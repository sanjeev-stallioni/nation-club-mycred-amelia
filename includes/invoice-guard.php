<?php
/**
 * Invoice Amount guard
 * -------------------------------------------------------------------------
 * The reward a customer earns is a percentage of the "Invoice Amount" custom
 * field on their booking, and the points come straight out of the service
 * vendor's pool (`earn_liability` in mycred-hooks.php). That makes this one
 * field the only customer-visible input that moves real money.
 *
 * The field is meant to be filled in by the VENDOR after the service, and on
 * the site it is hidden from the booking form with an inline `display: none`.
 * CSS is not an access control: the input is still in the DOM, still bound,
 * and still submitted, so a customer can reveal it with browser dev tools and
 * choose their own reward. The same value can be posted directly to Amelia's
 * booking endpoint (VAPT finding NC-10).
 *
 * This file enforces the intended rule on the server, where the customer
 * cannot reach it: if the person CREATING a booking is not a vendor or an
 * admin, the Invoice Amount is blanked before the booking is stored. A vendor
 * entering the amount later is untouched, because the award only fires once
 * the field holds a value — see the `$invoice_amount <= 0` early return in
 * mycred_process_appointment().
 *
 * Deliberately creation-only. `amelia_before_appointment_booking_saved_filter`
 * also fires on UPDATES, and a payment webhook re-saving a booking with no
 * logged-in user would wipe an amount the vendor had legitimately entered.
 *
 * Not covered here: a compromised or misused VENDOR account. Vendors are
 * trusted to set invoice amounts by design, so that risk is addressed by 2FA
 * plus disabling Application Passwords for non-admin roles (VAPT NC-27,
 * NC-29, NC-14), not by this guard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Amelia custom field ID for "Invoice Amount".
 *
 * Amelia keys the customFields JSON by field ID, so this is an exact match.
 * Matching on the ID rather than the label matters because the label search
 * takes the FIRST field containing "invoice" and those fields are drag-
 * reorderable in the Amelia admin — adding a second invoice-ish field could
 * otherwise silently change which number we pay points on.
 */
if ( ! defined( 'NC_INVOICE_CUSTOM_FIELD_ID' ) ) {
    define( 'NC_INVOICE_CUSTOM_FIELD_ID', 1 );
}

/**
 * Locate the invoice field within a decoded customFields array.
 *
 * Prefers the configured field ID. Falls back to the historical label match so
 * that a site whose field ID differs still behaves as it did before.
 *
 * @param  array $fields Decoded customFields.
 * @return string|int|null Array key of the invoice field, or null if absent.
 */
function nc_invoice_field_key( $fields ) {
    if ( ! is_array( $fields ) || empty( $fields ) ) {
        return null;
    }

    foreach ( array_keys( $fields ) as $key ) {
        if ( (int) $key === (int) NC_INVOICE_CUSTOM_FIELD_ID && is_array( $fields[ $key ] ) ) {
            return $key;
        }
    }

    foreach ( $fields as $key => $field ) {
        if ( is_array( $field ) && isset( $field['label'] ) && stripos( $field['label'], 'invoice' ) !== false ) {
            return $key;
        }
    }

    return null;
}

/**
 * May this user set an Invoice Amount directly?
 *
 * Vendors (Amelia providers) and administrators may — that is the normal
 * back-office flow. Everyone else, including logged-out visitors, may not.
 *
 * @param  int|null $user_id Defaults to the current user.
 * @return bool
 */
function nc_user_may_set_invoice( $user_id = null ) {
    $user_id = ( $user_id === null ) ? get_current_user_id() : (int) $user_id;

    if ( $user_id <= 0 ) {
        return false;
    }
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return false;
    }

    $trusted_roles = apply_filters(
        'nc_invoice_trusted_roles',
        array( 'administrator', 'wpamelia-admin', 'wpamelia-manager', 'wpamelia-provider' )
    );

    return (bool) array_intersect( $trusted_roles, (array) $user->roles );
}

/**
 * Strip a customer-supplied Invoice Amount before the booking is stored.
 *
 * Hooked on Amelia's `amelia_before_booking_added_filter`, which runs for the
 * front-end booking form, the customer panel, and the wpamelia_api endpoint
 * those forms post to — the same endpoint the pentest used directly.
 *
 * @param  array $appointment Appointment payload, including its bookings.
 * @return array Payload with any illegitimate invoice value cleared.
 */
function nc_invoice_guard_before_booking_added( $appointment ) {
    if ( ! is_array( $appointment ) || empty( $appointment['bookings'] ) || ! is_array( $appointment['bookings'] ) ) {
        return $appointment;
    }

    // Vendors and admins are allowed to set this. Nothing to do.
    if ( nc_user_may_set_invoice() ) {
        return $appointment;
    }

    $stripped = array();

    foreach ( $appointment['bookings'] as $i => $booking ) {
        if ( ! is_array( $booking ) || empty( $booking['customFields'] ) ) {
            continue;
        }

        // Amelia passes customFields as a JSON string in most paths, but as an
        // array in some. Preserve whichever shape we were handed.
        $raw       = $booking['customFields'];
        $was_json  = is_string( $raw );
        $fields    = $was_json ? json_decode( $raw, true ) : $raw;

        $key = nc_invoice_field_key( $fields );
        if ( $key === null ) {
            continue;
        }

        $submitted = isset( $fields[ $key ]['value'] ) ? trim( (string) $fields[ $key ]['value'] ) : '';
        if ( $submitted === '' || (float) $submitted == 0.0 ) {
            continue; // Nothing meaningful supplied — the normal case.
        }

        $fields[ $key ]['value'] = '';
        $appointment['bookings'][ $i ]['customFields'] = $was_json ? wp_json_encode( $fields ) : $fields;

        $stripped[] = $submitted;
    }

    if ( ! empty( $stripped ) ) {
        nc_invoice_guard_report( $stripped, $appointment );
    }

    return $appointment;
}
add_filter( 'amelia_before_booking_added_filter', 'nc_invoice_guard_before_booking_added', 5 );

/**
 * Log and (throttled) email an admin when a value was stripped.
 *
 * Logging every occurrence matters for a reason beyond the audit trail: if an
 * Amelia update ever changes the payload shape or the hook name, this guard
 * would stop matching and fail silently. A log that suddenly goes quiet is the
 * only signal that would surface it.
 *
 * @param array $values      The submitted value(s) that were cleared.
 * @param array $appointment Appointment payload, for context.
 */
function nc_invoice_guard_report( $values, $appointment ) {
    $user_id = get_current_user_id();
    $user    = $user_id ? get_userdata( $user_id ) : null;
    $who     = $user ? ( $user->user_login . ' (#' . $user_id . ')' ) : 'logged-out visitor';
    $service = isset( $appointment['serviceId'] ) ? (int) $appointment['serviceId'] : 0;
    $list    = implode( ', ', array_map( 'sanitize_text_field', $values ) );

    if ( function_exists( 'nc_debug' ) ) {
        nc_debug( sprintf(
            '[invoice-guard] Cleared customer-supplied Invoice Amount (%s) on booking creation by %s. serviceId=%d providerId=%d ip=%s',
            $list,
            $who,
            $service,
            isset( $appointment['providerId'] ) ? (int) $appointment['providerId'] : 0,
            isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '?'
        ) );
    }

    // Throttle the email so a scripted run can't flood the inbox. The log above
    // still records every single occurrence.
    if ( get_transient( 'nc_invoice_guard_alert_sent' ) ) {
        return;
    }
    set_transient( 'nc_invoice_guard_alert_sent', 1, HOUR_IN_SECONDS );

    if ( ! function_exists( 'nc_email_send' ) || ! function_exists( 'nc_get_admin_notify_recipients' ) ) {
        return;
    }

    $recip = nc_get_admin_notify_recipients();
    nc_email_send( 'invoice_guard_alert', $recip['to'], array(
        '{submitted_value}' => $list,
        '{user}'            => $who,
        '{service_id}'      => (string) $service,
        '{ip}'              => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '?',
        '{site_name}'       => get_bloginfo( 'name' ),
    ), $recip['cc'] );
}

/**
 * Register the alert email so it is editable under Nation Club → Email Templates.
 */
function nc_invoice_guard_register_email( $registry ) {
    $registry['invoice_guard_alert'] = array(
        'label'    => 'Invoice Amount Blocked (admin alert)',
        'audience' => 'admin',
        'tokens'   => array(
            '{submitted_value}' => 'The amount the customer tried to set',
            '{user}'            => 'Who submitted it',
            '{service_id}'      => 'Amelia service ID',
            '{ip}'              => 'Submitting IP address',
            '{site_name}'       => 'WordPress site name',
        ),
        'defaults' => array(
            'subject' => '[{site_name}] Blocked a customer-supplied Invoice Amount',
            'body'    => "A booking was created with an Invoice Amount already filled in.\n\n" .
                         "Customers are not supposed to set this field — it decides how many loyalty points are awarded, " .
                         "and those points are deducted from the vendor's pool. The value was cleared before the booking was saved, " .
                         "so no points were awarded and the vendor can enter the correct amount as usual.\n\n" .
                         "• Value submitted: {submitted_value}\n" .
                         "• Submitted by: {user}\n" .
                         "• Service ID: {service_id}\n" .
                         "• IP address: {ip}\n\n" .
                         "No action is required for this booking. Repeated alerts may indicate someone probing the booking form.\n\n" .
                         "Regards,\nNation Club",
        ),
    );

    return $registry;
}
add_filter( 'nc_email_template_registry', 'nc_invoice_guard_register_email' );
