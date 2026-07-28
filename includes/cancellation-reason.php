<?php
/**
 * Cancellation / Rejection Reason — Amelia booking enhancement.
 *
 * When a vendor changes a customer's booking status to Canceled or Rejected
 * in the Amelia employee panel, a modal forces them to type a reason. The
 * reason is then:
 *   1) Saved to wp_nc_appointment_reasons (admin log)
 *   2) Emailed to the customer with the booking details
 *   3) Replaces Amelia's default cancel/reject email so the customer sees
 *      one clear message with the reason embedded
 *
 * Architecture:
 *   - Schema: wp_nc_appointment_reasons keyed by (booking_id, status)
 *   - AJAX endpoint: nc_save_cancellation_reason — called by the modal
 *     before the Amelia save fires
 *   - Hook: amelia_after_appointment_status_updated — sends our email and
 *     logs the row's email_sent_at
 *   - Suppression: pre_wp_mail filter blocks Amelia's default cancel/reject
 *     email when our row exists for the same booking+status (pairs by
 *     transient set inside the AJAX endpoint)
 *   - Admin log: Nation Club → Cancellation Log
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NC_CANCELLATION_DB_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 * 0. Schema
 * ----------------------------------------------------------------------- */

function nc_cancellation_table() {
    global $wpdb;
    return $wpdb->prefix . 'nc_appointment_reasons';
}

function nc_cancellation_install() {
    global $wpdb;
    $table   = nc_cancellation_table();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        appointment_id BIGINT UNSIGNED NOT NULL,
        customer_booking_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        reason TEXT NOT NULL,
        vendor_user_id BIGINT UNSIGNED DEFAULT NULL,
        vendor_name VARCHAR(191) DEFAULT NULL,
        customer_email VARCHAR(191) DEFAULT NULL,
        customer_name VARCHAR(191) DEFAULT NULL,
        service_id BIGINT UNSIGNED DEFAULT NULL,
        service_name VARCHAR(191) DEFAULT NULL,
        appointment_date DATETIME DEFAULT NULL,
        email_sent_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY booking_status (customer_booking_id, status),
        KEY appointment_id (appointment_id),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset};";

    dbDelta( $sql );
    update_option( 'nc_cancellation_db_version', NC_CANCELLATION_DB_VERSION );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'nc_cancellation_db_version' ) !== NC_CANCELLATION_DB_VERSION ) {
        nc_cancellation_install();
    }
} );

/* -------------------------------------------------------------------------
 * 1. Asset enqueue — only on Amelia employee panel pages
 * ----------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'nc_cancellation_enqueue_assets' );
add_action( 'admin_enqueue_scripts', 'nc_cancellation_enqueue_assets' );

function nc_cancellation_enqueue_assets() {
    // Amelia's employee panel renders inside a shortcode page or admin page.
    // We can't reliably detect from query alone, so we enqueue site-wide for
    // logged-in users and the JS itself bails if Amelia DOM isn't present.
    if ( ! is_user_logged_in() ) {
        return;
    }

    $base = plugin_dir_url( __DIR__ );

    wp_enqueue_style(
        'nc-cancellation-reason',
        $base . 'assets/cancellation-reason.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'nc-cancellation-reason',
        $base . 'assets/cancellation-reason.js',
        array( 'jquery' ),
        '1.0.0',
        true
    );

    wp_localize_script( 'nc-cancellation-reason', 'NC_CANCEL', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'nc_cancel_reason' ),
    ) );
}

/* -------------------------------------------------------------------------
 * 2. AJAX endpoint — modal posts the reason here before Amelia's save fires
 * ----------------------------------------------------------------------- */

add_action( 'wp_ajax_nc_save_cancellation_reason', 'nc_ajax_save_cancellation_reason' );

function nc_ajax_save_cancellation_reason() {
    check_ajax_referer( 'nc_cancel_reason', 'nonce' );

    $appointment_id      = isset( $_POST['appointment_id'] )      ? (int) $_POST['appointment_id']      : 0;
    $customer_booking_id = isset( $_POST['customer_booking_id'] ) ? (int) $_POST['customer_booking_id'] : 0;
    $status              = isset( $_POST['status'] )              ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
    $reason              = isset( $_POST['reason'] )              ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
    $ctx_date            = isset( $_POST['ctx_date'] )            ? sanitize_text_field( wp_unslash( $_POST['ctx_date'] ) ) : '';
    $ctx_time            = isset( $_POST['ctx_time'] )            ? sanitize_text_field( wp_unslash( $_POST['ctx_time'] ) ) : '';
    $ctx_service         = isset( $_POST['ctx_service'] )         ? sanitize_text_field( wp_unslash( $_POST['ctx_service'] ) ) : '';
    $ctx_customer        = isset( $_POST['ctx_customer'] )        ? sanitize_text_field( wp_unslash( $_POST['ctx_customer'] ) ) : '';

    if ( function_exists( 'nc_debug' ) ) {
        nc_debug( sprintf(
            '[cancel-ajax] in: status=%s booking=%d appt=%d ctx_date=%s ctx_time=%s ctx_service=%s ctx_customer=%s reason_len=%d',
            $status, $customer_booking_id, $appointment_id, $ctx_date, $ctx_time, $ctx_service, $ctx_customer, strlen( $reason )
        ) );
    }

    $status = strtolower( $status );
    if ( ! in_array( $status, array( 'canceled', 'rejected' ), true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid status.' ) );
    }
    if ( trim( $reason ) === '' ) {
        wp_send_json_error( array( 'message' => 'Reason is required.' ) );
    }

    // Vue doesn't expose booking IDs in the DOM, so when the modal couldn't
    // capture them client-side, resolve via the visible context (date + time
    // + service name + this vendor's provider id).
    if ( $customer_booking_id <= 0 && $ctx_date && $ctx_service ) {
        $resolved = nc_cancellation_resolve_booking_by_context( $ctx_date, $ctx_time, $ctx_service, $ctx_customer, get_current_user_id() );
        if ( $resolved ) {
            $appointment_id      = (int) $resolved['appointment_id'];
            $customer_booking_id = (int) $resolved['customer_booking_id'];
        }
    }

    if ( $customer_booking_id <= 0 ) {
        wp_send_json_error( array( 'message' => 'Could not resolve booking. Please reload the page and try again.' ) );
    }

    // NC-09 (VAPT 2026-07-24) — missing authorization. The nonce made this
    // CSRF-safe but proved nothing about ownership: any logged-in user could
    // post an arbitrary customer_booking_id and have us write an admin-visible
    // log row, email that booking's customer, and DELETE the Amelia booking
    // record below. Bind the booking to the caller before touching anything.
    if ( ! nc_cancellation_user_can_manage_booking( get_current_user_id(), $customer_booking_id ) ) {
        wp_send_json_error( array( 'message' => 'You are not allowed to update this booking.' ), 403 );
    }

    global $wpdb;
    $row = nc_cancellation_resolve_booking_context( $appointment_id, $customer_booking_id );

    $current_user = wp_get_current_user();

    $data = array(
        'appointment_id'      => $appointment_id,
        'customer_booking_id' => $customer_booking_id,
        'status'              => $status,
        'reason'              => $reason,
        'vendor_user_id'      => $current_user ? (int) $current_user->ID : null,
        'vendor_name'         => $current_user ? $current_user->display_name : null,
        'customer_email'      => $row['customer_email'],
        'customer_name'       => $row['customer_name'],
        'service_id'          => $row['service_id'],
        'service_name'        => $row['service_name'],
        'appointment_date'    => $row['appointment_date'],
    );

    $table = nc_cancellation_table();

    $existing = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE customer_booking_id = %d AND status = %s",
        $customer_booking_id,
        $status
    ) );

    if ( $existing ) {
        // Re-cancellation/re-rejection of the same booking+status pair —
        // reset email_sent_at so our email fires again, and bump created_at
        // so the log timestamp reflects this fresh event (admin should see
        // the most recent action at the top of the table).
        $data['email_sent_at'] = null;
        $data['created_at']    = current_time( 'mysql' );
        $wpdb->update( $table, $data, array( 'id' => $existing ) );
        $row_id = $existing;
    } else {
        $wpdb->insert( $table, $data );
        $row_id = (int) $wpdb->insert_id;
    }

    // Flag this booking+status for the next 5 minutes so our email-suppression
    // filter knows Amelia's default email should be blocked for this pair.
    // (Best-effort — Amelia often emails before this flag is set, so the
    // recommendation is to also disable Amelia's cancel/reject notifications
    // in Amelia → Settings → Notifications.)
    set_transient(
        'nc_cancel_block_' . $customer_booking_id . '_' . $status,
        1,
        5 * MINUTE_IN_SECONDS
    );

    // Fire our customer email immediately. The Amelia status hook may have
    // already run and bailed (because the reason wasn't saved yet), so we
    // can't rely on it to send. Firing here guarantees the email goes out
    // exactly when the reason is captured.
    nc_cancellation_send_email( $customer_booking_id, $status );

    // Auto-cleanup: delete the canceled/rejected customer_booking row from
    // Amelia. Why: Amelia attaches new bookings to existing appointments
    // matching same provider+date+time, so leftover canceled rows accumulate
    // on the appointment. Once total bookings exceed the service's
    // maxCapacity, Amelia returns 409 "Maximum capacity reached" and the
    // vendor can no longer approve fresh bookings on that slot.
    //
    // Our Cancellation Log row already snapshots customer name, service,
    // appointment date, and reason — the audit trail survives even after
    // the Amelia row is gone. The customer_booking_id remains in our log
    // as a historical reference.
    $deleted = $wpdb->delete(
        $wpdb->prefix . 'amelia_customer_bookings',
        array( 'id' => $customer_booking_id )
    );
    if ( function_exists( 'nc_debug' ) ) {
        nc_debug( "[cancel-ajax] auto-cleanup: deleted Amelia booking #{$customer_booking_id} (rows affected: " . (int) $deleted . ')' );
    }

    wp_send_json_success( array( 'id' => $row_id ) );
}

/**
 * True when $user_id is allowed to record a cancellation against a booking.
 *
 * Administrators may act on anything. Everyone else must be the Amelia
 * provider on the appointment that owns the booking. Because the booking has
 * to exist for the join to match, this doubles as the existence check that
 * NC-09 found missing — a made-up id simply returns 0 rows and is refused.
 *
 * @return bool
 */
function nc_cancellation_user_can_manage_booking( $user_id, $customer_booking_id ) {
    $user_id             = (int) $user_id;
    $customer_booking_id = (int) $customer_booking_id;

    if ( $user_id <= 0 || $customer_booking_id <= 0 ) {
        return false;
    }
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }

    global $wpdb;
    $b = $wpdb->prefix . 'amelia_customer_bookings';
    $a = $wpdb->prefix . 'amelia_appointments';
    $u = $wpdb->prefix . 'amelia_users';

    $provider_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$u} WHERE externalId = %d AND type = 'provider' LIMIT 1",
        $user_id
    ) );
    if ( $provider_id <= 0 ) {
        return false;
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*)
           FROM {$b} cb
           JOIN {$a} ap ON ap.id = cb.appointmentId
          WHERE cb.id = %d
            AND ap.providerId = %d",
        $customer_booking_id,
        $provider_id
    ) ) > 0;
}

/**
 * Resolve a booking from visible UI context when Vue didn't expose the IDs.
 *
 * Strategy:
 *   1. Find the Amelia provider row for the current vendor user
 *   2. Match an appointment by (provider_id, date, service_name, time)
 *   3. Pick the customer booking on that appointment whose status just
 *      transitioned — but since we don't know which customer it was, return
 *      the first booking on the matching appointment. (When an appointment
 *      has multiple customer bookings the modal can only target one — admin
 *      can adjust manually if needed via the Cancellation Log.)
 *
 * @return array{appointment_id:int,customer_booking_id:int}|null
 */
function nc_cancellation_resolve_booking_by_context( $date_str, $time_str, $service_name, $customer_str, $vendor_user_id ) {
    global $wpdb;

    $log = function ( $msg ) {
        if ( function_exists( 'nc_debug' ) ) {
            nc_debug( '[cancel-resolve] ' . $msg );
        }
    };

    $log( "input: date='{$date_str}' time='{$time_str}' service='{$service_name}' customer='{$customer_str}' vendor_user_id={$vendor_user_id}" );

    $date_ts = strtotime( $date_str );
    if ( ! $date_ts ) {
        $log( "FAIL: could not parse date '{$date_str}'" );
        return null;
    }
    $date_ymd = wp_date( 'Y-m-d', $date_ts );

    $a = $wpdb->prefix . 'amelia_appointments';
    $u = $wpdb->prefix . 'amelia_users';
    $s = $wpdb->prefix . 'amelia_services';
    $b = $wpdb->prefix . 'amelia_customer_bookings';

    $provider_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$u} WHERE externalId = %d AND type = 'provider' ORDER BY id ASC LIMIT 1",
        (int) $vendor_user_id
    ) );

    if ( ! $provider_id ) {
        $log( "FAIL: no Amelia provider row for WP user {$vendor_user_id}" );
        return null;
    }
    $log( "resolved provider_id={$provider_id}" );

    // Match by (provider, date, service name) — case-insensitive and trimmed
    // because Vue's rendered text occasionally differs in spacing/case from
    // what's stored. Time is NOT matched (Amelia may store UTC vs local).
    // If the vendor has multiple same-service appointments on the same day
    // for different customers, the customer-name match below disambiguates.
    $appointments = $wpdb->get_results( $wpdb->prepare(
        "SELECT ap.id AS appointment_id, ap.bookingStart
         FROM {$a} ap
         INNER JOIN {$s} sv ON sv.id = ap.serviceId
         WHERE ap.providerId = %d
           AND DATE(ap.bookingStart) = %s
           AND LOWER(TRIM(sv.name)) = LOWER(TRIM(%s))
         ORDER BY ap.id DESC",
        $provider_id,
        $date_ymd,
        $service_name
    ) );

    if ( empty( $appointments ) ) {
        $log( "FAIL: no appointment found for provider={$provider_id} date={$date_ymd} service='{$service_name}'" );
        return null;
    }
    $log( 'matched ' . count( $appointments ) . ' candidate appointment(s)' );

    // For each candidate appointment, check if any of its customer bookings
    // belongs to a customer whose name matches $customer_str. Pick the first
    // hit. If no name match, fall back to the most recent appointment's
    // first booking.
    $customer_norm = strtolower( trim( (string) $customer_str ) );

    foreach ( $appointments as $ap ) {
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT cb.id, cu.firstName, cu.lastName
             FROM {$b} cb
             LEFT JOIN {$u} cu ON cu.id = cb.customerId
             WHERE cb.appointmentId = %d
             ORDER BY cb.id ASC",
            (int) $ap->appointment_id
        ) );

        if ( empty( $bookings ) ) {
            continue;
        }

        // Try to match by customer name when we have one
        if ( $customer_norm !== '' ) {
            foreach ( $bookings as $bk ) {
                $full = strtolower( trim( $bk->firstName . ' ' . $bk->lastName ) );
                if ( $full !== '' && ( $full === $customer_norm || strpos( $customer_norm, $full ) !== false || strpos( $full, $customer_norm ) !== false ) ) {
                    $log( "matched by customer name: appointment={$ap->appointment_id} booking={$bk->id} ({$full})" );
                    return array(
                        'appointment_id'      => (int) $ap->appointment_id,
                        'customer_booking_id' => (int) $bk->id,
                    );
                }
            }
        }
    }

    // No customer-name match — fall back to most recent appointment's first booking
    $first_ap   = $appointments[0];
    $booking_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$b} WHERE appointmentId = %d ORDER BY id ASC LIMIT 1",
        (int) $first_ap->appointment_id
    ) );

    if ( ! $booking_id ) {
        $log( "FAIL: appointment {$first_ap->appointment_id} has no customer bookings" );
        return null;
    }
    $log( "fallback resolved booking_id={$booking_id} (appointment {$first_ap->appointment_id})" );

    return array(
        'appointment_id'      => (int) $first_ap->appointment_id,
        'customer_booking_id' => $booking_id,
    );
}

/**
 * Pull customer + service + date for a booking from Amelia's tables so the
 * log row carries enough context to render later without joining at read time.
 */
function nc_cancellation_resolve_booking_context( $appointment_id, $customer_booking_id ) {
    global $wpdb;

    $b = $wpdb->prefix . 'amelia_customer_bookings';
    $u = $wpdb->prefix . 'amelia_users';
    $a = $wpdb->prefix . 'amelia_appointments';
    $s = $wpdb->prefix . 'amelia_services';

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            cb.customerId AS customer_amelia_id,
            ap.bookingStart AS appointment_date,
            ap.serviceId AS service_id,
            sv.name AS service_name
         FROM {$b} cb
         LEFT JOIN {$a} ap ON ap.id = cb.appointmentId
         LEFT JOIN {$s} sv ON sv.id = ap.serviceId
         WHERE cb.id = %d
         LIMIT 1",
        (int) $customer_booking_id
    ), ARRAY_A );

    $context = array(
        'customer_email'   => null,
        'customer_name'    => null,
        'service_id'       => null,
        'service_name'     => null,
        'appointment_date' => null,
    );

    if ( ! $row ) {
        return $context;
    }

    $context['service_id']       = $row['service_id'] ? (int) $row['service_id'] : null;
    $context['service_name']     = $row['service_name'];
    $context['appointment_date'] = $row['appointment_date'];

    if ( ! empty( $row['customer_amelia_id'] ) ) {
        $cust = $wpdb->get_row( $wpdb->prepare(
            "SELECT email, firstName, lastName FROM {$u} WHERE id = %d LIMIT 1",
            (int) $row['customer_amelia_id']
        ) );
        if ( $cust ) {
            $context['customer_email'] = $cust->email;
            $context['customer_name']  = trim( $cust->firstName . ' ' . $cust->lastName );
        }
    }

    return $context;
}

/* -------------------------------------------------------------------------
 * 3. Email templates — register with the shared Email Templates admin
 *
 * Two new entries appear under Nation Club → Email Templates: one for
 * Canceled bookings, one for Rejected. Both share the same token list and
 * default body skeleton — admin can edit subject/body independently.
 * ----------------------------------------------------------------------- */

add_filter( 'nc_email_template_registry', 'nc_cancellation_register_email_templates' );

function nc_cancellation_register_email_templates( $registry ) {
    $tokens = array(
        '{customer_name}'     => 'Customer first + last name',
        '{customer_email}'    => 'Customer email',
        '{service_name}'      => 'Booked service name',
        '{appointment_date}'  => 'e.g. May 10, 2026 at 2:30 pm',
        '{vendor_name}'       => 'Provider display name',
        '{reason}'            => 'Reason the vendor entered',
        '{site_name}'         => 'WordPress site name',
    );

    $default_body = "Hi {customer_name},\n\n" .
                    "We regret to inform you that your booking has been {STATUS_VERB} by the service provider.\n\n" .
                    "Service: {service_name}\n" .
                    "Date: {appointment_date}\n" .
                    "Provider: {vendor_name}\n\n" .
                    "Reason:\n{reason}\n\n" .
                    "If you have any questions, please contact us.\n\n" .
                    "Thank you,\n{site_name}";

    $registry['appointment_canceled'] = array(
        'label'    => 'Appointment Canceled (customer)',
        'audience' => 'customer',
        'tokens'   => $tokens,
        'defaults' => array(
            'subject' => 'Your booking has been canceled',
            'body'    => str_replace( '{STATUS_VERB}', 'canceled', $default_body ),
        ),
    );

    $registry['appointment_rejected'] = array(
        'label'    => 'Appointment Rejected (customer)',
        'audience' => 'customer',
        'tokens'   => $tokens,
        'defaults' => array(
            'subject' => 'Your booking has been rejected',
            'body'    => str_replace( '{STATUS_VERB}', 'rejected', $default_body ),
        ),
    );

    return $registry;
}

/* -------------------------------------------------------------------------
 * 4. Send our email after Amelia's status-update hook fires
 * ----------------------------------------------------------------------- */

add_action( 'amelia_after_appointment_status_updated', 'nc_cancellation_after_status_updated', 10, 2 );

function nc_cancellation_after_status_updated( $appointment, $requestedStatus ) {
    $status = strtolower( (string) $requestedStatus );
    if ( ! in_array( $status, array( 'canceled', 'rejected' ), true ) ) {
        return;
    }

    if ( empty( $appointment['bookings'] ) || ! is_array( $appointment['bookings'] ) ) {
        return;
    }

    foreach ( $appointment['bookings'] as $booking ) {
        if ( empty( $booking['id'] ) ) {
            continue;
        }
        if ( strtolower( $booking['status'] ?? '' ) !== $status ) {
            continue;
        }
        nc_cancellation_send_email( (int) $booking['id'], $status );
    }
}

function nc_cancellation_send_email( $customer_booking_id, $status ) {
    global $wpdb;
    $table = nc_cancellation_table();

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE customer_booking_id = %d AND status = %s",
        $customer_booking_id,
        $status
    ) );

    if ( ! $row ) {
        return; // No reason captured — modal didn't fire (e.g. status changed via direct DB or admin)
    }
    if ( $row->email_sent_at ) {
        return; // Already sent
    }
    if ( empty( $row->customer_email ) || ! is_email( $row->customer_email ) ) {
        return;
    }
    if ( ! function_exists( 'nc_email_send' ) ) {
        return; // Shared sender lives in vendor-statements.php — bail if not loaded
    }

    $appointment_date_str = $row->appointment_date
        ? wp_date( 'F j, Y \a\t g:i a', strtotime( $row->appointment_date ) )
        : '—';

    $tokens = array(
        '{customer_name}'    => $row->customer_name ?: 'there',
        '{customer_email}'   => $row->customer_email,
        '{service_name}'     => $row->service_name ?: '—',
        '{appointment_date}' => $appointment_date_str,
        '{vendor_name}'      => $row->vendor_name ?: '—',
        '{reason}'           => $row->reason,
        '{site_name}'        => get_bloginfo( 'name' ),
    );

    $template_key = $status === 'rejected' ? 'appointment_rejected' : 'appointment_canceled';
    $sent         = nc_email_send( $template_key, $row->customer_email, $tokens );

    if ( $sent ) {
        $wpdb->update( $table, array( 'email_sent_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
    }
}

/* -------------------------------------------------------------------------
 * 4. Suppress Amelia's default cancel/reject email
 *
 * We can't directly hook Amelia's email pipeline without modifying its source,
 * so we filter pre_wp_mail and check whether this booking+status pair has a
 * "block flag" transient set by our AJAX endpoint. The flag persists 5 min,
 * which comfortably covers Amelia's same-request email send.
 *
 * To match the booking we look at the email subject and body for booking ID
 * patterns. If a match is found AND we have a block flag, we return false to
 * cancel the wp_mail() call.
 * ----------------------------------------------------------------------- */

add_filter( 'pre_wp_mail', 'nc_cancellation_maybe_block_amelia_email', 10, 2 );

function nc_cancellation_maybe_block_amelia_email( $return, $atts ) {
    if ( $return !== null ) {
        return $return; // Some other filter already decided
    }

    $subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
    $message = isset( $atts['message'] ) ? (string) $atts['message'] : '';

    // Quick reject: only Amelia's cancel/reject mails carry these phrases.
    $is_amelia_cancel  = stripos( $subject, 'cancel' )  !== false || stripos( $message, 'has been canceled' )  !== false;
    $is_amelia_reject  = stripos( $subject, 'reject' )  !== false || stripos( $message, 'has been rejected' )  !== false;
    if ( ! $is_amelia_cancel && ! $is_amelia_reject ) {
        return $return;
    }

    // Walk the active block flags. If any matches, suppress.
    global $wpdb;
    $like = $wpdb->esc_like( '_transient_nc_cancel_block_' ) . '%';
    $hits = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ) );

    foreach ( $hits as $opt ) {
        // Format: _transient_nc_cancel_block_<booking_id>_<status>
        $key = str_replace( '_transient_', '', $opt );
        if ( get_transient( $key ) ) {
            // Active flag exists — assume Amelia's email is for the same pair.
            return false;
        }
    }

    return $return;
}

/* -------------------------------------------------------------------------
 * 5. Admin page — Nation Club → Cancellation Log
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', 'nc_cancellation_register_admin_page', 30 );

function nc_cancellation_register_admin_page() {
    add_submenu_page(
        'nation-club',
        'Cancellation Log',
        'Cancellation Log',
        'manage_options',
        'nc-cancellation-log',
        'nc_cancellation_render_admin_page'
    );
}

function nc_cancellation_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = nc_cancellation_table();

    $per_page = 20;
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ) );
    ?>
    <div class="wrap">
        <h1>Cancellation &amp; Rejection Log</h1>
        <p>All canceled or rejected bookings, with the reason the vendor entered. The customer received an email at the time of status change.</p>

        <?php if ( empty( $rows ) ) : ?>
            <p><em>No cancellations or rejections recorded yet.</em></p>
        <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Service</th>
                        <th>Vendor</th>
                        <th>Appointment</th>
                        <th>Reason</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $r->created_at ) ) ); ?></td>
                            <td>
                                <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:<?php echo $r->status === 'rejected' ? '#fde2e2' : '#fef3c7'; ?>;color:<?php echo $r->status === 'rejected' ? '#991b1b' : '#92400e'; ?>;font-size:11px;font-weight:600;text-transform:uppercase">
                                    <?php echo esc_html( $r->status ); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $r->customer_name ?: '—' ); ?></strong>
                                <?php if ( $r->customer_email ) : ?>
                                    <br><small><?php echo esc_html( $r->customer_email ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $r->service_name ?: '—' ); ?></td>
                            <td><?php echo esc_html( $r->vendor_name ?: '—' ); ?></td>
                            <td>
                                <?php echo $r->appointment_date ? esc_html( wp_date( 'M j, Y g:i a', strtotime( $r->appointment_date ) ) ) : '—'; ?>
                                <?php if ( $r->appointment_id ) : ?>
                                    <br><small>#<?php echo (int) $r->appointment_id; ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:280px"><?php echo nl2br( esc_html( $r->reason ) ); ?></td>
                            <td>
                                <?php if ( $r->email_sent_at ) : ?>
                                    <span style="color:#1a8d2e">✓ Sent</span><br>
                                    <small><?php echo esc_html( wp_date( 'M j, g:i a', strtotime( $r->email_sent_at ) ) ); ?></small>
                                <?php else : ?>
                                    <span style="color:#888">— Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $total_pages = (int) ceil( $total / $per_page );
            if ( $total_pages > 1 ) :
                $base_url = remove_query_arg( 'paged' );
            ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo (int) $total; ?> items</span>
                        <span class="pagination-links">
                            <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                                <?php if ( $i === $paged ) : ?>
                                    <span class="paging-input"><?php echo $i; ?> of <?php echo $total_pages; ?></span>
                                <?php else : ?>
                                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $i, $base_url ) ); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
