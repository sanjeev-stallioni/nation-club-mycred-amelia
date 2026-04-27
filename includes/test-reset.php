<?php
/**
 * Test Reset Module — DESTRUCTIVE, FOR TESTING ONLY
 *
 * Provides an admin page (Nation Club → Test Reset) to wipe the plugin's
 * test data so QA/test runs can start from a clean slate without touching
 * the database manually.
 *
 * Capabilities:
 *   - Truncate plugin tables: nc_topup_requests, nc_withdrawal_requests,
 *     nc_statements, myCRED_log
 *   - Reset a vendor or customer balance to 0 and clear booking-idempotency
 *     and low-balance-alert flags
 *   - Quick-reset buttons for the 3 known test accounts
 *   - Free-form "reset by email" textarea for ad-hoc test users
 *   - Combo "Reset Everything" button
 *
 * REMOVE OR GATE THIS FILE BEFORE PRODUCTION.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hardcoded quick-reset accounts. Add more here as the test fleet grows.
 */
function nc_test_reset_quick_accounts() {
    return array(
        'test.01.sanjeev@gmail.com' => 'Test Vendor 1',
        'test.02.sanjeev@gmail.com' => 'Test Vendor 2',
        'jess.advancer@gmail.com'   => 'Test Customer',
        'subash@stallioni.com'      => 'Test Account (Subash)',
        'govind@stallioni.com'      => 'Test Account (Govind)',
    );
}

/* -------------------------------------------------------------------------
 * Admin menu
 * ----------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Test Reset',
        'Test Reset',
        'manage_options',
        'nc-test-reset',
        'nc_admin_test_reset_page',
        99
    );
}, 50 );

/* -------------------------------------------------------------------------
 * Core reset operations
 * ----------------------------------------------------------------------- */

/**
 * Truncate one of the plugin tables. Returns rows-affected count (estimated
 * from the count before the truncate, since TRUNCATE doesn't return rows).
 */
function nc_test_reset_truncate( $key ) {
    global $wpdb;
    $tbl = nc_test_reset_table_name( $key );
    if ( ! $tbl ) {
        return array( 'ok' => false, 'message' => 'Unknown table key.', 'rows' => 0 );
    }

    $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
    $wpdb->query( "TRUNCATE TABLE {$tbl}" );

    return array( 'ok' => true, 'message' => sprintf( 'Truncated %s (%d rows removed).', $tbl, $before ), 'rows' => $before );
}

function nc_test_reset_table_name( $key ) {
    global $wpdb;
    switch ( $key ) {
        case 'topup':       return $wpdb->prefix . 'nc_topup_requests';
        case 'withdrawal':  return $wpdb->prefix . 'nc_withdrawal_requests';
        case 'statements':  return $wpdb->prefix . 'nc_statements';
        case 'mycred_log':  return $wpdb->prefix . 'myCRED_log';
    }
    return '';
}

function nc_test_reset_table_count( $key ) {
    global $wpdb;
    $tbl = nc_test_reset_table_name( $key );
    if ( ! $tbl ) { return 0; }
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
}

/**
 * Reset a single user's balance to 0 and clear plugin-level meta flags.
 * Works for both vendors and customers.
 */
function nc_test_reset_user_by_id( $user_id ) {
    global $wpdb;
    $user_id = (int) $user_id;
    if ( $user_id <= 0 ) {
        return array( 'ok' => false, 'message' => 'Invalid user id.' );
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return array( 'ok' => false, 'message' => 'User not found: #' . $user_id );
    }

    // Reset balance directly via user_meta — myCRED reads balance from there.
    update_user_meta( $user_id, 'mycred_default', 0 );

    // Clear plugin meta flags so a re-test fires cleanly
    delete_user_meta( $user_id, 'nc_low_balance_alerted' );
    delete_user_meta( $user_id, 'mycred_points_expiry' );

    // Booking idempotency flags use dynamic keys mycred_awarded_appt_<id> / mycred_redeemed_appt_<id>
    $deleted_meta = (int) $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta}
         WHERE user_id = %d
           AND ( meta_key LIKE %s OR meta_key LIKE %s OR meta_key = %s )",
        $user_id,
        'mycred_awarded_appt_%',
        'mycred_redeemed_appt_%',
        'last_earned_vendor'
    ) );

    return array(
        'ok'      => true,
        'message' => sprintf( 'Reset %s (user #%d) — balance set to 0, %d booking flag(s) cleared.', $user->user_email, $user_id, $deleted_meta ),
    );
}

function nc_test_reset_user_by_email( $email ) {
    $email = sanitize_email( $email );
    if ( ! is_email( $email ) ) {
        return array( 'ok' => false, 'message' => 'Invalid email: ' . $email );
    }
    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        return array( 'ok' => false, 'message' => 'User not found for email: ' . $email );
    }
    return nc_test_reset_user_by_id( (int) $user->ID );
}

function nc_test_reset_balance_for_email( $email ) {
    $email = sanitize_email( $email );
    if ( ! is_email( $email ) ) { return null; }
    $user = get_user_by( 'email', $email );
    if ( ! $user ) { return null; }
    if ( ! function_exists( 'mycred_get_users_balance' ) ) { return 'myCRED inactive'; }
    return number_format( (float) mycred_get_users_balance( (int) $user->ID ), 2 );
}

/* -------------------------------------------------------------------------
 * POST handlers
 * ----------------------------------------------------------------------- */

function nc_admin_test_reset_handle_post() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
    if ( ! isset( $_POST['nc_test_action'] ) ) { return; }

    $action = sanitize_key( wp_unslash( $_POST['nc_test_action'] ) );
    check_admin_referer( 'nc_test_reset' );

    $messages = array();
    $errors   = array();

    if ( $action === 'truncate_table' ) {
        $key = isset( $_POST['table_key'] ) ? sanitize_key( $_POST['table_key'] ) : '';
        $r   = nc_test_reset_truncate( $key );
        $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
    }

    if ( $action === 'truncate_all_tables' ) {
        foreach ( array( 'topup', 'withdrawal', 'statements', 'mycred_log' ) as $k ) {
            $r = nc_test_reset_truncate( $k );
            $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
        }
    }

    if ( $action === 'reset_user_email' ) {
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $r     = nc_test_reset_user_by_email( $email );
        $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
    }

    if ( $action === 'reset_quick_users' ) {
        foreach ( array_keys( nc_test_reset_quick_accounts() ) as $email ) {
            $r = nc_test_reset_user_by_email( $email );
            $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
        }
    }

    if ( $action === 'reset_users_bulk' ) {
        $raw = isset( $_POST['emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['emails'] ) ) : '';
        $list = preg_split( '/[\s,]+/', $raw );
        foreach ( $list as $email ) {
            if ( $email === '' ) { continue; }
            $r = nc_test_reset_user_by_email( $email );
            $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
        }
    }

    if ( $action === 'reset_everything' ) {
        // Tables first
        foreach ( array( 'topup', 'withdrawal', 'statements', 'mycred_log' ) as $k ) {
            $r = nc_test_reset_truncate( $k );
            $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
        }
        // Then quick accounts
        foreach ( array_keys( nc_test_reset_quick_accounts() ) as $email ) {
            $r = nc_test_reset_user_by_email( $email );
            $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
        }
    }

    $args = array( 'page' => 'nc-test-reset' );
    if ( $messages ) { $args['nc_test_msg'] = rawurlencode( implode( ' | ', $messages ) ); }
    if ( $errors )   { $args['nc_test_err'] = rawurlencode( implode( ' | ', $errors ) ); }
    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}

/* -------------------------------------------------------------------------
 * Page renderer
 * ----------------------------------------------------------------------- */

function nc_admin_test_reset_page() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

    if ( ! empty( $_POST['nc_test_action'] ) ) {
        nc_admin_test_reset_handle_post();
    }

    $counts = array(
        'topup'      => nc_test_reset_table_count( 'topup' ),
        'withdrawal' => nc_test_reset_table_count( 'withdrawal' ),
        'statements' => nc_test_reset_table_count( 'statements' ),
        'mycred_log' => nc_test_reset_table_count( 'mycred_log' ),
    );

    $quick = nc_test_reset_quick_accounts();
    ?>
    <div class="wrap">
        <h1>Test Reset</h1>

        <div style="background:#fff3cd;color:#856404;padding:14px 18px;border:2px solid #f5c2c7;border-radius:4px;margin:14px 0;font-size:14px">
            <strong>⚠️ DESTRUCTIVE — for testing only.</strong>
            These actions <strong>cannot be undone</strong>. They wipe plugin data and reset user balances.
            Do NOT use on a live production site.
        </div>

        <?php if ( ! empty( $_GET['nc_test_msg'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo wp_kses_post( str_replace( ' | ', '<br>', esc_html( sanitize_text_field( wp_unslash( $_GET['nc_test_msg'] ) ) ) ) ); ?></p>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $_GET['nc_test_err'] ) ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo wp_kses_post( str_replace( ' | ', '<br>', esc_html( sanitize_text_field( wp_unslash( $_GET['nc_test_err'] ) ) ) ) ); ?></p>
            </div>
        <?php endif; ?>

        <!-- Tables -->
        <div style="background:#fff;padding:18px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px;max-width:760px">
            <h2 style="margin-top:0">Reset Tables</h2>
            <p style="color:#666">Empties the table completely. Auto-increment is reset (TRUNCATE).</p>

            <table class="widefat striped" style="margin-top:8px">
                <thead><tr><th>Table</th><th style="width:120px;text-align:right">Rows</th><th style="width:200px">Action</th></tr></thead>
                <tbody>
                    <?php foreach ( array(
                        'topup'      => 'wp_nc_topup_requests (Top-up Requests)',
                        'withdrawal' => 'wp_nc_withdrawal_requests (Withdrawal Requests)',
                        'statements' => 'wp_nc_statements (Monthly Statements)',
                        'mycred_log' => 'wp_myCRED_log (myCRED Log)',
                    ) as $k => $label ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $label ); ?></code></td>
                            <td style="text-align:right"><strong><?php echo esc_html( number_format_i18n( $counts[ $k ] ) ); ?></strong></td>
                            <td>
                                <form method="post" style="margin:0">
                                    <?php wp_nonce_field( 'nc_test_reset' ); ?>
                                    <input type="hidden" name="nc_test_action" value="truncate_table">
                                    <input type="hidden" name="table_key" value="<?php echo esc_attr( $k ); ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('Truncate <?php echo esc_js( $label ); ?>? This deletes all <?php echo (int) $counts[ $k ]; ?> rows. Continue?');">Truncate</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" style="margin-top:14px">
                <?php wp_nonce_field( 'nc_test_reset' ); ?>
                <input type="hidden" name="nc_test_action" value="truncate_all_tables">
                <button type="submit" class="button button-secondary" onclick="return confirm('Truncate ALL 4 plugin tables? This deletes every top-up, withdrawal, statement, and myCRED log row. Continue?');">⚠️ Truncate ALL 4 Tables</button>
            </form>
        </div>

        <!-- Quick reset users -->
        <div style="background:#fff;padding:18px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px;max-width:760px">
            <h2 style="margin-top:0">Reset User Balances to 0</h2>
            <p style="color:#666">
                Sets balance to 0 in user_meta and clears booking idempotency flags
                (<code>mycred_awarded_appt_*</code>, <code>mycred_redeemed_appt_*</code>),
                <code>nc_low_balance_alerted</code>, and <code>mycred_points_expiry</code>
                so the user can be re-tested cleanly.
            </p>

            <table class="widefat striped" style="margin-top:8px">
                <thead><tr><th>Email</th><th>Label</th><th style="width:140px;text-align:right">Current Balance</th><th style="width:160px">Action</th></tr></thead>
                <tbody>
                    <?php foreach ( $quick as $email => $label ) :
                        $bal = nc_test_reset_balance_for_email( $email );
                        $exists = $bal !== null;
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( $email ); ?></code></td>
                            <td><?php echo esc_html( $label ); ?></td>
                            <td style="text-align:right"><strong><?php echo $exists ? esc_html( $bal ) : '<em>not found</em>'; ?></strong></td>
                            <td>
                                <?php if ( $exists ) : ?>
                                    <form method="post" style="margin:0">
                                        <?php wp_nonce_field( 'nc_test_reset' ); ?>
                                        <input type="hidden" name="nc_test_action" value="reset_user_email">
                                        <input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
                                        <button type="submit" class="button button-small" onclick="return confirm('Reset <?php echo esc_js( $email ); ?> balance to 0?');">Reset to 0</button>
                                    </form>
                                <?php else : ?>
                                    <em style="color:#888">no user</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" style="margin-top:14px">
                <?php wp_nonce_field( 'nc_test_reset' ); ?>
                <input type="hidden" name="nc_test_action" value="reset_quick_users">
                <button type="submit" class="button button-secondary" onclick="return confirm('Reset ALL <?php echo count( $quick ); ?> quick-reset users to 0?');">Reset All Quick Users</button>
            </form>
        </div>

        <!-- Free-form by email -->
        <div style="background:#fff;padding:18px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px;max-width:760px">
            <h2 style="margin-top:0">Reset Other Users by Email</h2>
            <p style="color:#666">One email per line, or comma-separated.</p>
            <form method="post">
                <?php wp_nonce_field( 'nc_test_reset' ); ?>
                <input type="hidden" name="nc_test_action" value="reset_users_bulk">
                <textarea name="emails" rows="3" cols="60" placeholder="user1@example.com&#10;user2@example.com" style="width:100%;max-width:500px"></textarea>
                <p>
                    <button type="submit" class="button" onclick="return confirm('Reset balance to 0 for the listed users?');">Reset Listed Users</button>
                </p>
            </form>
        </div>

        <!-- Full reset -->
        <div style="background:#fbeaea;padding:18px;border:2px solid #c62828;border-radius:4px;margin-top:24px;max-width:760px">
            <h2 style="margin-top:0;color:#c62828">⚠️ Full Reset</h2>
            <p>Truncates all 4 tables AND resets all <?php echo count( $quick ); ?> quick-reset users to 0 in one click.</p>
            <form method="post">
                <?php wp_nonce_field( 'nc_test_reset' ); ?>
                <input type="hidden" name="nc_test_action" value="reset_everything">
                <button type="submit" class="button button-primary" style="background:#c62828;border-color:#a31515" onclick="return confirm('🚨 RESET EVERYTHING — wipe all 4 tables AND zero out all quick-reset users? This cannot be undone. Continue?');">RESET EVERYTHING</button>
            </form>
        </div>
    </div>
    <?php
}
