<?php

// add_action('amelia_after_appointment_status_updated', 'mycred_appointment_approved_points_handle', 10, 2);

// Hook 1: When status changes to approved
add_action('amelia_after_appointment_status_updated', 'mycred_appointment_status_changed', 10, 2);

// Hook 2: When appointment is saved (catches invoice updates after approval)
add_action('amelia_after_appointment_updated', 'mycred_appointment_updated', 10, 5);

function get_mycred_customer_expiry_timestamp()
{

    // WordPress timezone-aware month
    $month = (int) wp_date('n');
    $year  = (int) wp_date('Y');

    if ($month >= 1 && $month <= 6) {
        // 31 December current year
        $expiry_date = $year . '-12-31 23:59:59';
    } else {
        // 30 June next year
        $expiry_date = ($year + 1) . '-06-30 23:59:59';
    }

    return (new DateTime($expiry_date, wp_timezone()))->getTimestamp();
}

/**
 * Handler for status change hook
 */
function mycred_appointment_status_changed($appointment, $requestedStatus)
{
    if (strtolower($requestedStatus) === 'approved') {
        mycred_process_appointment($appointment, 'status_change');
    }
}

/**
 * Handler for appointment updated hook
 * This fires when appointment is saved from the edit screen
 */
function mycred_appointment_updated($appointment, $oldAppointment, $removedBookings, $service, $paymentData)
{
    // Only process if status is currently approved
    if (isset($appointment['status']) && strtolower($appointment['status']) === 'approved') {
        nc_debug('appointment status is not empty');
        mycred_process_appointment($appointment, 'appointment_updated');
    } else {
        nc_debug('appointment status is empty');
    }
}

/**
 * Main processing function
 */
function mycred_process_appointment($appointment, $trigger)
{
    global $wpdb;

    $log_file = WP_CONTENT_DIR . '/mycred-debug.log';

    $wlog = function ($msg) use ($log_file) {
        $line = '[' . wp_date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    };

    $wlog("=== [myCRED point handling hook] Start (trigger: {$trigger}) ===");

    $amelia_service_tbl  = $wpdb->prefix . 'amelia_services';
    $amelia_users_tbl    = $wpdb->prefix . 'amelia_users';
    $amelia_bookings_tbl = $wpdb->prefix . 'amelia_customer_bookings';

    $booking_data = is_array($appointment['bookings']) && !empty($appointment['bookings']) ? $appointment['bookings'][0] : array();

    // reward percentages per serviceId (default 5%)
    $service_rewards = [
        6 => 0.05,
        23 => 0.05,
        24 => 0.05,
        40 => 0.05,
        41 => 0.05,
        42 => 0.05,
        15 => 0.05,
        53 => 0.05,
        54 => 0.05,
        7 => 0.05,
        27 => 0.05,
        11 => 0.05,
        58 => 0.05,
        59 => 0.05,
        60 => 0.05,
        61 => 0.05,
        62 => 0.05,
        63 => 0.05,
        64 => 0.05,
        65 => 0.05,
        5 => 0.02,
        55 => 0.02,
        56 => 0.02,
        57 => 0.02,
        9 => 0.05,
        30 => 0.05,
        8 => 0.10,
        49 => 0.10,
        13 => 0.05,
        72 => 0.05,
        73 => 0.05,
        74 => 0.05,
        12 => 0.05,
        75 => 0.05,
        76 => 0.05,
        77 => 0.05,
        78 => 0.05,
        10 => 0.05,
        66 => 0.05,
        67 => 0.05,
        68 => 0.05,
        69 => 0.05,
        70 => 0.05,
        71 => 0.05,
        14 => 0.05,
        43 => 0.05,
        44 => 0.05,
        45 => 0.05,
        46 => 0.05,
        47 => 0.05,
        48 => 0.05,
        51 => 0.05
    ];

    if (!empty($booking_data)) {

        $bookingId      = $booking_data['id'];
        $transaction_id = 'NC' . str_pad($bookingId, 4, '0', STR_PAD_LEFT);
        $customerId     = $booking_data['customerId'];

        // Fix: appointmentId might be in booking_data OR in the main appointment array
        $appointmentId  = !empty($booking_data['appointmentId']) ? $booking_data['appointmentId'] : (!empty($appointment['id']) ? $appointment['id'] : null);

        if (!$appointmentId) {
            $wlog("⚠️ Could not determine appointmentId. Booking data: " . json_encode($booking_data));
            $wlog("⚠️ Appointment data: " . json_encode($appointment));
            return;
        }

        $bookingStatus  = $appointment['status'];
        $serviceId      = $appointment['serviceId'];
        $providerId     = $appointment['providerId'];

        // CRITICAL: Fetch fresh custom fields from database to ensure we have latest data
        $fresh_booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT customFields FROM {$amelia_bookings_tbl} WHERE id = %d",
                $bookingId
            )
        );

        $custom_fields  = $fresh_booking ? json_decode($fresh_booking->customFields, true) : json_decode($booking_data['customFields'], true);
        $invoice_amount = 0.00;

        $wp_user_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT externalId FROM {$amelia_users_tbl} WHERE id = %d",
                (int) $customerId
            )
        );

        if ($wp_user_id === null || $wp_user_id <= 0) {
            $wlog("⏸ Skipping appt {$appointmentId} — invalid externalId / wp_user_id.");
            return;
        }

        if (!function_exists('mycred_get_users_balance') || !function_exists('mycred_add')) {
            $wlog('⚠️ myCRED functions missing. Skipping processing.');
            return;
        }

        // Parse invoice from customFields
        if (is_array($custom_fields) && !empty($custom_fields)) {
            foreach ($custom_fields as $field) {
                if (is_array($field) && isset($field['label']) && stripos($field['label'], 'invoice') !== false) {
                    $invoice_amount = floatval($field['value'] ?? 0);
                    break;
                }
            }
        }

        if ($invoice_amount <= 0) {
            $wlog("⚠️ Invalid invoice for appt {$appointmentId}. Skipping.");
            return;
        }

        // Preserve full invoice for reward calculation. Rewards are always
        // earned on the full service value, independent of any redemption.
        $original_invoice_amount = $invoice_amount;

        // Get service name by service id
        $service_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$amelia_service_tbl} WHERE id = %d",
                (int) $serviceId
            )
        );

        $service_name = !empty($service_name) ? $service_name : '';

        $percent = $service_rewards[$serviceId] ?? 0.05;

        $already_awarded  = get_user_meta($wp_user_id, 'mycred_awarded_appt_' . $appointmentId, true);
        $already_redeemed = get_user_meta($wp_user_id, 'mycred_redeemed_appt_' . $appointmentId, true);

        $balance_before = round(mycred_get_users_balance($wp_user_id), 2);

        $user_total_bookings = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$amelia_bookings_tbl} WHERE customerId = %d",
            $customerId
        )));

        $wlog("Appt {$appointmentId}: invoice={$invoice_amount}, balance_before={$balance_before}, total_bookings={$user_total_bookings}");

        // ------------------------------
        // Step 1: Auto-Redemption (Before reward)
        // ------------------------------
        if ($already_redeemed) {
            $wlog("⏸ Redemption already done for appt {$appointmentId}.");
        } elseif ($user_total_bookings <= 1) {
            $wlog("⏸ First booking for user {$wp_user_id}, skip redemption.");
        } elseif ($balance_before <= 0) {
            $wlog("⏸ No balance to redeem for {$wp_user_id}.");
        } else {
            $redeem_amount = min($balance_before, $invoice_amount);
            $new_invoice_amount = round($invoice_amount - $redeem_amount, 2);
            $origin_vendor_id = intval(get_user_meta($wp_user_id, 'last_earned_vendor', true));

            $customer_redeem_log_data = json_encode([
                'service_id'          => intval($serviceId),
                'vendor_id'           => intval($providerId),
                'origin_vendor_id'    => $origin_vendor_id,
                'liability_vendor_id' => $origin_vendor_id,
                'booking_id'          => intval($bookingId),
                'transaction_id'      => $transaction_id,
                'customer_id'         => intval($customerId)
            ]);

            mycred_add(
                'booking_redeem',
                $wp_user_id,
                -$redeem_amount,
                "{$service_name} – Redeemed " . number_format($redeem_amount, 2) . " points",
                $appointmentId,
                $customer_redeem_log_data
            );

            if (is_array($custom_fields) && !empty($custom_fields)) {
                foreach ($custom_fields as $k => $fld) {
                    if (is_array($fld) && isset($fld['label']) && stripos($fld['label'], 'invoice') !== false) {
                        $custom_fields[$k]['value'] = number_format($new_invoice_amount, 2, '.', '');
                        break;
                    }
                }
                $wpdb->update($amelia_bookings_tbl, ['customFields' => wp_json_encode($custom_fields)], ['id' => $bookingId]);
            }

            // Service vendor: credited for honoring the redemption
            $vendor_wp_user_id = intval($wpdb->get_var($wpdb->prepare(
                "SELECT externalId FROM {$amelia_users_tbl} WHERE id = %d",
                $providerId
            )));

            if ($vendor_wp_user_id > 0) {
                mycred_add(
                    'redeem_accept',
                    $vendor_wp_user_id,
                    $redeem_amount,
                    "{$service_name} – Accepted customer redemption of " . number_format($redeem_amount, 2) . " points",
                    $appointmentId,
                    $customer_redeem_log_data
                );
                if (function_exists('nc_vendor_check_low_balance')) {
                    nc_vendor_check_low_balance((int) $vendor_wp_user_id);
                }
                $wlog("↩️ Credited {$redeem_amount} pts to service vendor {$vendor_wp_user_id} (redeem_accept).");
            }

            // Origin vendor: NOT debited again at redeem time.
            // The origin vendor's pool was already drained when the customer earned
            // these points (earn_liability). Debiting again here would double-count
            // the same liability.
            //
            // Net flow across vendors after this change:
            //   Earn:   origin vendor -X (earn_liability)        — funds the loyalty
            //   Redeem: receiving vendor +X (redeem_accept)      — collects the value
            //   Total vendor pool change: 0 ✓
            //
            // Historical redeem_liability entries (created before this change) remain
            // intact in the log and on past statements — they reflect what actually
            // happened to those vendor balances at the time.
            if ($origin_vendor_id > 0) {
                $wlog("ℹ️ Origin vendor amelia_id={$origin_vendor_id}: no debit applied — already paid via earn_liability when customer earned these pts.");
            } else {
                $wlog("ℹ️ No origin_vendor_id for appt {$appointmentId} — pts may be from manual grant; no settlement debit applied.");
            }

            update_user_meta($wp_user_id, 'mycred_redeemed_appt_' . $appointmentId, 1);
            $invoice_amount = $new_invoice_amount;
            $wlog("💰 Customer {$wp_user_id} redeemed {$redeem_amount} pts. New invoice: {$invoice_amount}.");
        }

        // ------------------------------
        // Step 2: Reward Customer + Deduct Vendor (5%)
        // Always calculated from the FULL invoice amount, not the
        // post-redemption net. Redemption does not reduce earnings.
        // ------------------------------
        if (!$already_awarded && $original_invoice_amount > 0) {
            $base_amount = round($original_invoice_amount, 2);
            $points = round($base_amount * $percent, 2);

            $wlog("Convert points from invoice: {$points} pts.");

            if ($points > 0) {

                // -------------------------
                // Calculate expiry timestamp
                // -------------------------
                $expiry_ts = get_mycred_customer_expiry_timestamp();

                $customer_log_data = json_encode([
                    'service_id'          => intval($serviceId),
                    'vendor_id'           => intval($providerId),
                    'origin_vendor_id'    => intval($providerId),
                    'liability_vendor_id' => intval($providerId),
                    'booking_id'          => intval($bookingId),
                    'transaction_id'      => $transaction_id,
                    'customer_id'         => intval($customerId)
                ]);

                mycred_add(
                    'booking_reward',
                    $wp_user_id,
                    $points,
                    "{$service_name} – " . ($percent * 100) . "% of invoice SGD " . number_format($base_amount, 2),
                    $appointmentId,
                    $customer_log_data
                );

                // Save expiry ONLY for customer
                update_user_meta($wp_user_id, 'mycred_points_expiry', $expiry_ts);

                update_user_meta($wp_user_id, 'mycred_awarded_appt_' . $appointmentId, 1);

                $wlog("✅ Awarded {$points} pts to customer {$wp_user_id} for {$service_name} (base {$base_amount}).");

                $vendor_wp_user_id = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT externalId FROM {$amelia_users_tbl} WHERE id = %d",
                    $providerId
                )));

                if ($vendor_wp_user_id > 0) {
                    mycred_add(
                        'earn_liability',
                        $vendor_wp_user_id,
                        -$points,
                        "{$service_name} – " . ($percent * 100) . "% of invoice SGD " . number_format($base_amount, 2),
                        $appointmentId,
                        $customer_log_data
                    );
                    if (function_exists('nc_vendor_check_low_balance')) {
                        nc_vendor_check_low_balance((int) $vendor_wp_user_id);
                    }
                    $wlog("⬇️ Deducted {$points} pts from service vendor {$vendor_wp_user_id} (earn_liability).");
                }
            } else {
                $wlog("⏸ Calculated reward points is 0 for appt {$appointmentId}, base {$base_amount}.");
            }

            update_user_meta($wp_user_id, 'last_earned_vendor', intval($providerId));
        } else {
            if ($already_awarded) {
                $wlog("⏸ Already awarded for appt {$appointmentId}, skipping reward.");
            } elseif ($original_invoice_amount <= 0) {
                $wlog("⏸ Invoice amount zero, skipping reward.");
            }
        }
    }

    $wlog("=== [myCRED point handling hook] End ===");
}


// Expire points
add_action('wp_loaded', function () {

    $user_id = get_current_user_id();

    if ($user_id <= 0) {
        return;
    }

    mycred_maybe_expire_user_points($user_id);
});

function mycred_maybe_expire_user_points($user_id)
{

    global $wpdb;

    $user_id = (int) $user_id;

    nc_expiry_debug("=== [USER EXPIRY] Start for user {$user_id} ===");

    if ($user_id <= 0) {
        nc_expiry_debug('[USER EXPIRY] ❌ Invalid user ID.');
        return;
    }

    if (! function_exists('mycred_add')) {
        nc_expiry_debug('[USER EXPIRY] ❌ myCRED not loaded.');
        return;
    }

    // ---- get expiry timestamp ----
    $expiry_ts = get_user_meta($user_id, 'mycred_points_expiry', true);

    if (empty($expiry_ts) || ! is_numeric($expiry_ts)) {
        nc_expiry_debug("[USER EXPIRY] User {$user_id} has no valid expiry timestamp.");
        return;
    }

    $expiry_ts = (int) $expiry_ts;
    // $today_midnight_ts = ( new DateTime(
    //     wp_date( 'Y-m-d 00:00:00' ),
    //     wp_timezone()
    // ) )->getTimestamp();

    // if ( $expiry_ts > $today_midnight_ts ) {
    //     nc_expiry_debug('[USER EXPIRY] ⏸ Not expired yet.');
    //     return;
    // }
    $now_ts = current_time('timestamp');

    if ($expiry_ts > $now_ts) {
        nc_expiry_debug('[USER EXPIRY] ⏸ Not expired yet.');
        return;
    }

    // ---- get balance ----
    $balance = round(mycred_get_users_balance($user_id), 2);

    if ($balance <= 0) {
        delete_user_meta($user_id, 'mycred_points_expiry');
        nc_expiry_debug('[USER EXPIRY] Zero balance → expiry meta cleared.');
        return;
    }

    // ---- get last relevant myCRED log ----
    $log_table = $wpdb->prefix . 'myCRED_log';

    $last_log = $wpdb->get_row(
        $wpdb->prepare(
            "
            SELECT ref, ref_id, data
            FROM {$log_table}
            WHERE user_id = %d
            AND ref IN ('booking_reward','booking_redeem')
            ORDER BY id DESC
            LIMIT 1
            ",
            $user_id
        )
    );

    $data   = ($last_log && ! empty($last_log->data)) ? $last_log->data : '';
    $ref_id = ($last_log && ! empty($last_log->ref_id)) ? (int) $last_log->ref_id : 0;

    nc_expiry_debug(
        '[USER EXPIRY] data = ' . print_r($data, true)
    );

    // ---- expire points ----
    mycred_add(
        'points_expiry',
        $user_id,
        -$balance,
        sprintf(
            '%s points expired on %s',
            number_format($balance, 2),
            wp_date('M jS Y', $expiry_ts)
        ),
        $ref_id,
        $data
    );

    delete_user_meta($user_id, 'mycred_points_expiry');

    nc_expiry_debug("[USER EXPIRY] ❌ {$balance} points expired for user {$user_id}");
    nc_expiry_debug("=== [USER EXPIRY] End for user {$user_id} ===");
}


function findExpAndPoints($user_id)
{
    global $wpdb;

    $users_meta_tbl = $wpdb->prefix . 'usermeta';

    $sql = $wpdb->prepare(
        "SELECT meta_key, meta_value
         FROM $users_meta_tbl
         WHERE user_id = %d
         AND meta_key IN ('mycred_default', 'mycred_points_expiry')",
        $user_id
    );

    $results = $wpdb->get_results($sql, OBJECT_K);

    return [
        'exp'    => $results['mycred_points_expiry']->meta_value ?? null,
        'points' => isset($results['mycred_default'])
            ? number_format((float) $results['mycred_default']->meta_value, 2)
            : null,
    ];
}

/**
 * Shortcode to display expiring points
 */
add_shortcode('mycred_expiring_points', function ($atts) {
    if (!is_user_logged_in()) {
        return '';
    }

    $user_id = get_current_user_id();

    $data = findExpAndPoints($user_id);

    $output = '<div class="mycred-expiring-notice" style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">';
    $output .= '<p style="margin: 0; font-size: 14px; color: #856404;">';
    if ($data['points'] > 0) {

        $output .= '<strong>⚠️ You have ' . $data['points'] . ' points available until ' . gmdate(get_option('date_format'), $data['exp']) . '.</strong>';
    } else {
        $output .= '<strong>You have no points earned yet. Make a service booking and earn the points</strong>';
    }
    $output .= '</p>';
    $output .= '</div>';
    return $output;
});

// Display Customer points and last service in employee portal
function get_customer_mycred_points()
{

    global $wpdb;

    // Security
    check_ajax_referer('amelia-custom-nonce', 'nonce');

    if (empty($_POST['email'])) {
        wp_send_json_error('Email not provided');
    }

    $customer_email = sanitize_email($_POST['email']);

    if (empty($customer_email) || ! is_email($customer_email)) {
        wp_send_json_error('Invalid email format');
    }

    // Get WP user (customer)
    $customer_wp = get_user_by('email', $customer_email);
    if (! $customer_wp) {
        wp_send_json_error('User not found');
    }

    if (! function_exists('mycred_get_users_balance')) {
        wp_send_json_error('myCred not available');
    }

    $points = mycred_get_users_balance($customer_wp->ID);

    // Resolve the currently logged-in vendor's Amelia provider id.
    // "Last Service" must be scoped to this vendor only.
    $current_wp_user_id = get_current_user_id();

    $users_tbl       = $wpdb->prefix . 'amelia_users';
    $appointment_tbl = $wpdb->prefix . 'amelia_appointments';
    $bookings_tbl    = $wpdb->prefix . 'amelia_customer_bookings';
    $service_tbl     = $wpdb->prefix . 'amelia_services';

    $provider_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$users_tbl} WHERE externalId = %d AND type = 'provider' LIMIT 1",
            $current_wp_user_id
        )
    );

    $customer_amelia_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$users_tbl} WHERE externalId = %d AND type = 'customer' LIMIT 1",
            $customer_wp->ID
        )
    );

    $response = array(
        'points'          => $points,
        'last_service'    => 'First time customer',
        'service_date'    => null,
        'last_invoice'    => null,
        'total_completed' => 0,
    );

    // If we can't resolve the vendor (e.g. admin viewing) or the customer's
    // amelia record, fall back to the "first time" response.
    if ($provider_id <= 0 || $customer_amelia_id <= 0) {
        wp_send_json_success($response);
    }

    // Latest approved appointment this customer had WITH THIS VENDOR
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT a.id AS appointment_id,
                    a.bookingStart,
                    s.name AS service_name,
                    b.customFields
             FROM {$appointment_tbl} a
             JOIN {$bookings_tbl} b ON b.appointmentId = a.id
             LEFT JOIN {$service_tbl} s ON s.id = a.serviceId
             WHERE a.providerId = %d
               AND b.customerId = %d
               AND LOWER(a.status) = 'approved'
             ORDER BY a.bookingStart DESC
             LIMIT 1",
            $provider_id,
            $customer_amelia_id
        )
    );

    if (! $row) {
        wp_send_json_success($response);
    }

    // Parse invoice amount from the booking customFields
    $invoice_amount = null;
    if (! empty($row->customFields)) {
        $fields = json_decode($row->customFields, true);
        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (is_array($field) && isset($field['label']) && stripos($field['label'], 'invoice') !== false) {
                    $invoice_amount = floatval($field['value'] ?? 0);
                    break;
                }
            }
        }
    }

    // Total completed approved appointments with THIS vendor
    $total_completed = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$appointment_tbl} a
             JOIN {$bookings_tbl} b ON b.appointmentId = a.id
             WHERE a.providerId = %d
               AND b.customerId = %d
               AND LOWER(a.status) = 'approved'",
            $provider_id,
            $customer_amelia_id
        )
    );

    $response['last_service']    = $row->service_name ?: '—';
    $response['service_date']    = $row->bookingStart ? wp_date('M j, Y', strtotime($row->bookingStart)) : null;
    $response['last_invoice']    = $invoice_amount !== null ? number_format($invoice_amount, 2) : null;
    $response['total_completed'] = $total_completed;

    wp_send_json_success($response);
}

add_action('wp_ajax_get_mycred_points', 'get_customer_mycred_points');
add_action('wp_ajax_nopriv_get_mycred_points', 'get_customer_mycred_points');
// If needed for non-logged-in, but Employee Panel requires login.


// Add Table Column 'Vendor Name' in My Point History Table
add_action('init', 'nationclub_mycred_add_vendor_column_for_customers');

function nationclub_mycred_add_vendor_column_for_customers()
{

    // Safety check (now WordPress is loaded)
    if (! is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();
    $user    = get_userdata($user_id);

    $roles = (array) $user->roles;

    if (! $user || ! array_intersect($roles, ['wpamelia-customer'])) {
        return;
    }

    add_filter('mycred_log_column_headers', 'custom_mycred_add_vendor_column_header');
    add_filter('mycred_log_vendor-name', 'custom_mycred_add_vendor_column_content', 10, 2);

    // Customers don't need internal ledger references — hide them
    add_filter('mycred_log_column_headers', 'nc_hide_internal_columns_for_customers', 20);
}

function nc_hide_internal_columns_for_customers($columns)
{
    unset($columns['points-origin-vendor']);
    unset($columns['transaction-id']);
    return $columns;
}

function custom_mycred_add_vendor_column_header($columns)
{
    $columns['vendor-name'] = 'Vendor Name';
    return $columns;
}

function custom_mycred_add_vendor_column_content($content, $entry)
{
    $data = maybe_unserialize($entry->data);
    // Decode JSON if needed
    if (is_string($data)) {
        $data = json_decode($data, true);
    }
    if (is_array($data) && isset($data['vendor_id'])) {
        $vendor_id = intval($data['vendor_id']);
        global $wpdb;
        $vendor = $wpdb->get_row($wpdb->prepare(
            "SELECT firstName, lastName 
             FROM {$wpdb->prefix}amelia_users 
             WHERE id = %d AND type = 'provider'",
            $vendor_id
        ));
        if ($vendor) {
            return trim($vendor->firstName . ' ' . $vendor->lastName);
        } else {
            return 'Unknown Vendor';
        }
    }
    return 'N/A';
}

// Add Table Column 'Username' in My Point History Table
add_action('init', 'nationclub_mycred_add_username_column');
function nationclub_mycred_add_username_column()
{

    // Safety check (now WordPress is loaded)
    if (! is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();
    $user    = get_userdata($user_id);

    $roles = (array) $user->roles;

    if (! $user || ! array_intersect($roles, ['wpamelia-provider'])) {
        return;
    }

    add_filter('mycred_log_column_headers', 'nationclub_mycred_username_column_header');
    add_filter('mycred_log_user-name', 'nationclub_mycred_username_column_content', 10, 2);
}

function nationclub_mycred_username_column_header($columns)
{
    $columns['user-name'] = 'Username';
    return $columns;
}

function nationclub_mycred_username_column_content($content, $entry)
{
    $data = maybe_unserialize($entry->data);

    // Decode JSON if needed
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $data = $decoded;
        }
    }

    /**
     * 1️⃣ Amelia-based transactions (booking_reward, earn_liability, redeem_accept, redeem_liability, etc.)
     * Uses customer_id → amelia_users.externalId → wp_users.ID
     */
    if (is_array($data) && ! empty($data['customer_id'])) {

        global $wpdb;
        $customer_id = (int) $data['customer_id'];

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT externalId
                 FROM {$wpdb->prefix}amelia_users
                 WHERE id = %d",
                $customer_id
            )
        );

        if (! empty($row->externalId)) {
            $user = get_user_by('ID', (int) $row->externalId);
            if ($user) {
                return $user->user_login;
            }
        }
    }

    /**
     * 2️⃣ User-based transactions (buy_creds_with_stripe, manual, etc.)
     * Uses myCRED entry user_id directly
     */
    if (! empty($entry->user_id)) {
        $user = get_user_by('ID', (int) $entry->user_id);
        if ($user) {
            return $user->user_login;
        }
    }

    return 'N/A';
}

// Add Custom Columns to myCRED Log Export
add_filter('mycred_log_column_headers', 'custom_mycred_add_export_columns');
function custom_mycred_add_export_columns($columns)
{
    $columns['category'] = 'Service';
    $columns['points-origin-vendor'] = 'Vendor';
    $columns['transaction-id'] = 'Transaction ID';
    return $columns;
}

add_filter('mycred_log_category', 'custom_mycred_category_column_content', 10, 2);
function custom_mycred_category_column_content($content, $entry)
{
    $data = maybe_unserialize($entry->data);
    if (is_string($data)) {
        $data = json_decode($data, true);
    }
    if (is_array($data) && isset($data['service_id'])) {
        $service_id = intval($data['service_id']);
        global $wpdb;
        $service = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}amelia_services WHERE id = %d",
            $service_id
        ));
        return $service ? $service : 'Unknown Service';
    }
    return 'N/A';
}

add_filter('mycred_log_points-origin-vendor', 'custom_mycred_points_origin_vendor_column_content', 10, 2);
function custom_mycred_points_origin_vendor_column_content($content, $entry)
{

    $data = maybe_unserialize($entry->data);
    if (is_string($data)) {
        $data = json_decode($data, true);
    }

    // Prefer origin vendor (true source of points)
    if (is_array($data) && isset($data['origin_vendor_id'])) {
        $vendor_id = intval($data['origin_vendor_id']);
    } elseif (is_array($data) && isset($data['vendor_id'])) {
        // fallback
        $vendor_id = intval($data['vendor_id']);
    } else {
        return 'N/A';
    }

    global $wpdb;
    $vendor = $wpdb->get_row($wpdb->prepare(
        "SELECT firstName, lastName 
         FROM {$wpdb->prefix}amelia_users 
         WHERE id = %d AND type = 'provider'",
        $vendor_id
    ));

    return $vendor
        ? trim($vendor->firstName . ' ' . $vendor->lastName)
        : 'Unknown Vendor';
}


add_filter('mycred_log_transaction-id', 'custom_mycred_transaction_id_column_content', 10, 2);

function custom_mycred_transaction_id_column_content($content, $entry)
{
    $data = maybe_unserialize($entry->data);

    if (is_string($data)) {
        $json = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $data = $json;
        }
    }

    if (is_array($data)) {

        // For most Amelia / vendor refs
        if (!empty($data['transaction_id'])) {
            return $data['transaction_id'];
        }

        // For vendor pool top-ups
        if (!empty($data['topup_id'])) {
            return $data['topup_id'];
        }

        // For vendor pool withdrawals
        if (!empty($data['withdrawal_id'])) {
            return $data['withdrawal_id'];
        }

        // For buy_creds_with_stripe
        // if (!empty($data['txn_id'])) {
        //     return $data['txn_id'];
        // }
    }

    return 'N/A';
}


//function custom_mycred_transaction_id_column_content($content, $entry)
//{
// $data = maybe_unserialize($entry->data);
//if (is_string($data)) {
// $data = json_decode($data, true);
// }
// if (is_array($data) && isset($data['booking_id'])) {
// $transaction_id = intval($data['booking_id']);
// return $transaction_id;
//  }
// return 'N/A';
//}


// Add Export Log Submenu
add_action('admin_menu', 'add_export_log_submenu');
function add_export_log_submenu()
{
    add_submenu_page(
        'mycred',          // Parent slug (myCRED main menu)
        'Export Log',      // Page title
        'Export Log',      // Menu title
        'manage_options',  // Capability
        'export-log',      // Menu slug
        'export_log_page'  // Callback function
    );
}

// Callback to Render the Export Page
function export_log_page()
{
    // Check permissions
    if (! current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    // Handle export submission
    if (isset($_POST['export_mycred_log']) && check_admin_referer('export_mycred_log_nonce')) {
        export_mycred_log_to_csv();
        exit; // Stop after export
    }

    // Display the page
?>
    <div class="wrap">
        <h1>Export myCRED Points Log</h1>
        <p>Click below to download the log as CSV.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="export_mycred_log">
            <?php wp_nonce_field('export_mycred_log_nonce'); ?>
            <button type="submit" class="button button-primary">
                Export as CSV
            </button>
        </form>
    </div>
<?php
}

// Function to Generate and Download CSV
function export_mycred_log_to_csv()
{
    if (! current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('export_mycred_log_nonce');
    global $wpdb;
    // Prevent WordPress from outputting anything
    while (ob_get_level()) {
        ob_end_clean();
    }
    nocache_headers();
    $log_entries = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}myCRED_log ORDER BY time DESC"
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mycred-log-export-' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, [
        'User Email',
        'Username',
        'Reference',
        'Date',
        'Points',
        'Entry',
        'Service',
        'Vendor',
        'Transaction ID'
    ]);
    foreach ($log_entries as $entry) {
        $user = get_userdata($entry->user_id);
        $user_email = $user ? $user->user_email : 'Unknown';
        $username = $user ? $user->user_login : 'Unknown';
        $date = date_i18n('F j, Y g:i a', $entry->time);
        // ENTRY (strip HTML)
        $entry_text = html_entity_decode($entry->entry, ENT_QUOTES, 'UTF-8');
        $entry_text = wp_strip_all_tags($entry_text);
        // CUSTOM FIELDS (force text)
        $category = wp_strip_all_tags(
            html_entity_decode(
                apply_filters('mycred_log_category', '', $entry),
                ENT_QUOTES,
                'UTF-8'
            )
        );
        $vendor = wp_strip_all_tags(
            html_entity_decode(
                apply_filters('mycred_log_points-origin-vendor', '', $entry),
                ENT_QUOTES,
                'UTF-8'
            )
        );
        $txn_id = wp_strip_all_tags(
            html_entity_decode(
                apply_filters('mycred_log_transaction-id', '', $entry),
                ENT_QUOTES,
                'UTF-8'
            )
        );
        fputcsv($output, [
            $user_email,
            $username,
            $entry->ref,
            $date,
            $entry->creds,
            trim($entry_text),
            trim($category),
            trim($vendor),
            trim($txn_id)
        ]);
    }
    fclose($output);
    exit;
}
add_action('admin_post_export_mycred_log', 'export_mycred_log_to_csv');
