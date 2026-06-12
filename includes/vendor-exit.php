<?php
/**
 * Vendor Exit Flow — managed offboarding for vendors leaving the platform.
 *
 * Lifecycle (Proposal 5):
 *   1. Vendor informs admin offline (no system action)
 *   2. Admin clicks "Start Exit Notice"             → status: notice_active
 *      System records start date; +2 months = scheduled end date.
 *      During the notice period nothing changes — vendor takes bookings,
 *      customers earn, vendor can still withdraw surplus.
 *   3. After 2 months admin clicks "Hide Listing"    → status: listing_hidden
 *      Pre-condition: vendor pool balance >= SGD 1,000 (client requirement —
 *      ensures buffer to absorb outstanding-points settlement).
 *      Action: updates Amelia provider status='hidden' (also saves user_meta
 *      `nc_vendor_hidden=1`) so they no longer appear on the booking page.
 *      Vendor's WP account remains active so they can still log in and view
 *      statements.
 *   4. Wait — outstanding points clear via redemption or expiry. Counter on
 *      this page shows live outstanding total per exit row.
 *   5. Once outstanding = 0, "Final Settlement" becomes available. Admin
 *      enters Wise reference and clicks. System debits the remaining pool
 *      balance via mycred_add('vendor_exit_settlement', -balance) and stamps
 *      the row → status: settled.
 *
 * Withdrawal interaction:
 *   - During notice_active: withdrawals allowed (existing rules apply).
 *   - After listing_hidden: withdrawals blocked via nc_vendor_can_withdraw
 *     filter — only the admin-driven Final Settlement can move money out.
 *
 * One row per vendor at a time (UNIQUE on vendor_id WHERE status != settled).
 * Once settled, a new exit row could in theory be opened (re-onboarded vendor
 * exiting again), so we don't enforce uniqueness on settled rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NC_VENDOR_EXIT_DB_VERSION', '1.2.0' );
define( 'NC_VENDOR_EXIT_NOTICE_MONTHS', 2 );

/* -------------------------------------------------------------------------
 * 0. Schema
 * ----------------------------------------------------------------------- */

function nc_vendor_exit_table() {
    global $wpdb;
    return $wpdb->prefix . 'nc_vendor_exits';
}

function nc_vendor_exit_install() {
    global $wpdb;
    $table   = nc_vendor_exit_table();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        vendor_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'notice_active',
        notice_start_date DATE NOT NULL,
        notice_end_date DATE NOT NULL,
        notice_started_by BIGINT UNSIGNED DEFAULT NULL,
        listing_hidden_at DATETIME DEFAULT NULL,
        listing_hidden_by BIGINT UNSIGNED DEFAULT NULL,
        amelia_prev_status VARCHAR(32) DEFAULT NULL,
        final_settlement_at DATETIME DEFAULT NULL,
        final_settlement_by BIGINT UNSIGNED DEFAULT NULL,
        final_settlement_amount DECIMAL(14,2) DEFAULT NULL,
        final_settlement_wise_ref VARCHAR(191) DEFAULT NULL,
        rejoined_at DATETIME DEFAULT NULL,
        rejoined_by BIGINT UNSIGNED DEFAULT NULL,
        last_day_email_sent_at DATETIME DEFAULT NULL,
        admin_note TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY vendor_id (vendor_id),
        KEY status (status),
        KEY notice_end_date (notice_end_date)
    ) {$charset};";

    dbDelta( $sql );
    update_option( 'nc_vendor_exit_db_version', NC_VENDOR_EXIT_DB_VERSION );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'nc_vendor_exit_db_version' ) !== NC_VENDOR_EXIT_DB_VERSION ) {
        nc_vendor_exit_install();
    }
} );

/* -------------------------------------------------------------------------
 * 1. Helpers
 * ----------------------------------------------------------------------- */

/**
 * Get the active (not settled) exit row for a vendor, or null.
 */
function nc_vendor_exit_get_active( $vendor_id ) {
    global $wpdb;
    $table = nc_vendor_exit_table();
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE vendor_id = %d AND status != 'settled'
         ORDER BY id DESC LIMIT 1",
        (int) $vendor_id
    ) );
}

/**
 * Outstanding points = sum of remaining_amount on active customer batches
 * where this vendor is the liability. These are points the vendor has paid
 * for (debited at earn time) but customers haven't yet redeemed or expired.
 */
function nc_vendor_exit_outstanding_points( $vendor_id ) {
    global $wpdb;
    $batches = $wpdb->prefix . 'nc_customer_point_batches';
    return (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(remaining_amount), 0)
         FROM {$batches}
         WHERE liability_vendor_id = %d AND status = 'active' AND remaining_amount > 0",
        (int) $vendor_id
    ) );
}

/**
 * Vendor's current myCRED pool balance (SGD = points 1:1).
 */
function nc_vendor_exit_pool_balance( $vendor_id ) {
    if ( ! function_exists( 'mycred_get_users_balance' ) ) {
        return 0.0;
    }
    return (float) mycred_get_users_balance( (int) $vendor_id );
}

/**
 * Eligible vendors for "Start Exit Notice" — providers without an active
 * exit row.
 */
function nc_vendor_exit_eligible_vendors() {
    global $wpdb;
    $table = nc_vendor_exit_table();

    $active_ids = $wpdb->get_col(
        "SELECT DISTINCT vendor_id FROM {$table} WHERE status != 'settled'"
    );

    $args = array(
        'role__in' => array( 'wpamelia-provider', 'amelia_provider', 'editor' ),
        'fields'   => array( 'ID', 'display_name', 'user_email' ),
        'orderby'  => 'display_name',
        'order'    => 'ASC',
    );
    $users = get_users( $args );

    // Fallback: if no role match (sites use varied role slugs), fetch all
    // users that have at least one Amelia provider row.
    if ( empty( $users ) ) {
        $rows = $wpdb->get_results(
            "SELECT u.ID, u.display_name, u.user_email
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->prefix}amelia_users au ON au.externalId = u.ID
             WHERE au.type = 'provider'
             GROUP BY u.ID
             ORDER BY u.display_name ASC"
        );
        $users = $rows;
    }

    if ( ! empty( $active_ids ) ) {
        $active_ids = array_map( 'intval', $active_ids );
        $users = array_filter( $users, function ( $u ) use ( $active_ids ) {
            return ! in_array( (int) $u->ID, $active_ids, true );
        } );
    }

    return array_values( $users );
}

/* -------------------------------------------------------------------------
 * 2. State transitions
 * ----------------------------------------------------------------------- */

/**
 * Step 2 — Start exit notice.
 *
 * @return array{ok:bool,message:string,id?:int}
 */
function nc_vendor_exit_start_notice( $vendor_id, $start_date_ymd, $admin_id, $note = '' ) {
    global $wpdb;
    $table = nc_vendor_exit_table();

    $vendor_id = (int) $vendor_id;
    if ( $vendor_id <= 0 || ! get_user_by( 'id', $vendor_id ) ) {
        return array( 'ok' => false, 'message' => 'Invalid vendor.' );
    }
    if ( nc_vendor_exit_get_active( $vendor_id ) ) {
        return array( 'ok' => false, 'message' => 'This vendor already has an active exit notice.' );
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $start_date_ymd ) ) {
        return array( 'ok' => false, 'message' => 'Start date is required (YYYY-MM-DD).' );
    }

    $start = DateTime::createFromFormat( 'Y-m-d', $start_date_ymd, wp_timezone() );
    if ( ! $start ) {
        return array( 'ok' => false, 'message' => 'Could not parse start date.' );
    }
    $end = clone $start;
    $end->modify( '+' . (int) NC_VENDOR_EXIT_NOTICE_MONTHS . ' months' );

    $wpdb->insert( $table, array(
        'vendor_id'         => $vendor_id,
        'status'            => 'notice_active',
        'notice_start_date' => $start->format( 'Y-m-d' ),
        'notice_end_date'   => $end->format( 'Y-m-d' ),
        'notice_started_by' => (int) $admin_id,
        'admin_note'        => (string) $note,
    ) );

    return array(
        'ok'      => true,
        'id'      => (int) $wpdb->insert_id,
        'message' => sprintf(
            'Exit notice started. Notice period ends %s.',
            $end->format( 'M j, Y' )
        ),
    );
}

/**
 * Step 4 — Hide listing.
 *
 * Pre-conditions:
 *   - Exit row in 'notice_active' status
 *   - Vendor pool balance >= NC_VENDOR_POOL_MIN_BALANCE
 *
 * Action:
 *   - Snapshot Amelia's current provider status, set to 'hidden'
 *   - Set user_meta nc_vendor_hidden=1 (extension flag)
 *   - Move exit row to 'listing_hidden'
 */
function nc_vendor_exit_hide_listing( $exit_id, $admin_id ) {
    global $wpdb;
    $table = nc_vendor_exit_table();

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $exit_id ) );
    if ( ! $row ) {
        return array( 'ok' => false, 'message' => 'Exit row not found.' );
    }
    if ( $row->status !== 'notice_active' ) {
        return array( 'ok' => false, 'message' => 'Listing can only be hidden while notice is active.' );
    }

    $min     = defined( 'NC_VENDOR_POOL_MIN_BALANCE' ) ? NC_VENDOR_POOL_MIN_BALANCE : 1000;
    $balance = nc_vendor_exit_pool_balance( $row->vendor_id );
    if ( $balance < $min ) {
        return array(
            'ok'      => false,
            'message' => sprintf(
                'Vendor pool balance (SGD %s) is below the required minimum SGD %s. Ask the vendor to top up before hiding the listing.',
                number_format( $balance, 2 ),
                number_format( $min, 2 )
            ),
        );
    }

    // Snapshot + hide in Amelia.
    $amelia_users = $wpdb->prefix . 'amelia_users';
    $prev_status  = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM {$amelia_users} WHERE externalId = %d AND type = 'provider' ORDER BY id ASC LIMIT 1",
        (int) $row->vendor_id
    ) );
    if ( $prev_status ) {
        $wpdb->update(
            $amelia_users,
            array( 'status' => 'hidden' ),
            array( 'externalId' => (int) $row->vendor_id, 'type' => 'provider' )
        );
    }
    update_user_meta( (int) $row->vendor_id, 'nc_vendor_hidden', 1 );

    $wpdb->update( $table, array(
        'status'             => 'listing_hidden',
        'listing_hidden_at'  => current_time( 'mysql' ),
        'listing_hidden_by'  => (int) $admin_id,
        'amelia_prev_status' => $prev_status ?: null,
    ), array( 'id' => (int) $row->id ) );

    return array( 'ok' => true, 'message' => 'Listing hidden. Vendor will no longer appear on the booking page.' );
}

/**
 * Step 6 — Final settlement.
 *
 * Pre-conditions:
 *   - Exit row in 'listing_hidden' status
 *   - Outstanding points = 0
 *   - Wise reference provided
 *
 * Action:
 *   - Debit vendor's pool by current balance via mycred_add('vendor_exit_settlement')
 *   - Stamp row → 'settled'
 */
function nc_vendor_exit_finalize( $exit_id, $wise_reference, $admin_id ) {
    global $wpdb;
    $table = nc_vendor_exit_table();

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $exit_id ) );
    if ( ! $row ) {
        return array( 'ok' => false, 'message' => 'Exit row not found.' );
    }
    if ( $row->status !== 'listing_hidden' ) {
        return array( 'ok' => false, 'message' => 'Final settlement is only available after the listing has been hidden.' );
    }

    $outstanding = nc_vendor_exit_outstanding_points( $row->vendor_id );
    if ( $outstanding > 0 ) {
        return array(
            'ok'      => false,
            'message' => sprintf(
                'Outstanding points (%s) must reach zero before final settlement.',
                number_format( $outstanding, 2 )
            ),
        );
    }

    $wise = trim( (string) $wise_reference );
    if ( $wise === '' ) {
        return array( 'ok' => false, 'message' => 'Wise reference is required for final settlement.' );
    }

    $balance = nc_vendor_exit_pool_balance( $row->vendor_id );

    // Only attempt a debit if there's something to debit. If balance == 0
    // we still mark the exit as settled (nothing to refund — valid case).
    if ( $balance > 0 ) {
        if ( ! function_exists( 'mycred_add' ) ) {
            return array(
                'ok'      => false,
                'message' => 'myCRED is not available — cannot debit vendor pool. Settlement not completed; please retry once myCRED is active.',
            );
        }

        $log_data = wp_json_encode( array(
            'transaction_id'  => 'EXIT-' . str_pad( (string) $row->id, 5, '0', STR_PAD_LEFT ),
            'exit_id'         => (int) $row->id,
            'vendor_id'       => (int) $row->vendor_id,
            'wise_reference'  => $wise,
            'admin_id'        => (int) $admin_id,
        ) );

        $debit_ok = mycred_add(
            'vendor_exit_settlement',
            (int) $row->vendor_id,
            -$balance,
            sprintf( 'Final settlement on vendor exit — refunded SGD %s via Wise (%s)', number_format( $balance, 2 ), $wise ),
            (int) $row->id,
            $log_data
        );

        if ( ! $debit_ok ) {
            return array(
                'ok'      => false,
                'message' => sprintf(
                    'Failed to debit vendor pool by SGD %s. Settlement not completed — exit remains in Listing Hidden status so you can retry.',
                    number_format( $balance, 2 )
                ),
            );
        }
    }

    $wpdb->update( $table, array(
        'status'                    => 'settled',
        'final_settlement_at'       => current_time( 'mysql' ),
        'final_settlement_by'       => (int) $admin_id,
        'final_settlement_amount'   => round( $balance, 2 ),
        'final_settlement_wise_ref' => $wise,
    ), array( 'id' => (int) $row->id ) );

    return array(
        'ok'      => true,
        'message' => sprintf( 'Vendor exit settled. SGD %s refunded via Wise.', number_format( $balance, 2 ) ),
    );
}

/**
 * Step 7 (optional) — Rejoin the platform after a settled exit.
 *
 * Pre-condition: row must be in 'settled' status AND not already rejoined.
 *
 * Action:
 *   - Restore Amelia provider visibility (uses snapshotted prev status if any)
 *   - Clear nc_vendor_hidden user_meta
 *   - Stamp rejoined_at / rejoined_by on the settled row (audit trail —
 *     row stays as 'settled' so the original exit history is preserved)
 *
 * The vendor still needs to top up to SGD 1,000 via the normal portal flow
 * before they can operate. This function only restores visibility.
 */
function nc_vendor_exit_rejoin( $exit_id, $admin_id ) {
    global $wpdb;
    $table = nc_vendor_exit_table();

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $exit_id ) );
    if ( ! $row ) {
        return array( 'ok' => false, 'message' => 'Exit row not found.' );
    }
    if ( $row->status !== 'settled' ) {
        return array( 'ok' => false, 'message' => 'Rejoin is only available after the exit has been settled.' );
    }
    if ( $row->rejoined_at ) {
        return array( 'ok' => false, 'message' => 'This vendor has already been rejoined.' );
    }

    $amelia_users = $wpdb->prefix . 'amelia_users';
    $restore      = $row->amelia_prev_status ?: 'visible';
    $wpdb->update(
        $amelia_users,
        array( 'status' => $restore ),
        array( 'externalId' => (int) $row->vendor_id, 'type' => 'provider' )
    );
    delete_user_meta( (int) $row->vendor_id, 'nc_vendor_hidden' );

    $wpdb->update( $table, array(
        'rejoined_at' => current_time( 'mysql' ),
        'rejoined_by' => (int) $admin_id,
    ), array( 'id' => (int) $row->id ) );

    return array(
        'ok'      => true,
        'message' => 'Vendor rejoined. Listing is visible again — remind them to top up to SGD 1,000 via the vendor portal before resuming bookings.',
    );
}

/**
 * Revert an exit (admin escape hatch). Restores Amelia status if applicable.
 * Only allowed for non-settled rows (settled = irreversible to keep ledger clean).
 */
function nc_vendor_exit_revert( $exit_id, $admin_id ) {
    global $wpdb;
    $table = nc_vendor_exit_table();

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $exit_id ) );
    if ( ! $row ) {
        return array( 'ok' => false, 'message' => 'Exit row not found.' );
    }
    if ( $row->status === 'settled' ) {
        return array( 'ok' => false, 'message' => 'A settled exit cannot be reverted.' );
    }

    if ( $row->status === 'listing_hidden' ) {
        $amelia_users = $wpdb->prefix . 'amelia_users';
        $restore      = $row->amelia_prev_status ?: 'visible';
        $wpdb->update(
            $amelia_users,
            array( 'status' => $restore ),
            array( 'externalId' => (int) $row->vendor_id, 'type' => 'provider' )
        );
        delete_user_meta( (int) $row->vendor_id, 'nc_vendor_hidden' );
    }

    $wpdb->delete( $table, array( 'id' => (int) $row->id ) );
    return array( 'ok' => true, 'message' => 'Exit notice reverted and removed.' );
}

/* -------------------------------------------------------------------------
 * 3. Withdrawal interaction
 *
 * After listing is hidden, vendor can no longer withdraw via the normal
 * surplus flow — only the admin-driven Final Settlement moves money. During
 * notice_active the existing rules apply unchanged.
 * ----------------------------------------------------------------------- */

add_filter( 'nc_vendor_can_withdraw', 'nc_vendor_exit_block_withdrawal_after_hide', 8, 3 );

function nc_vendor_exit_block_withdrawal_after_hide( $result, $vendor_id, $amount ) {
    $row = nc_vendor_exit_get_active( $vendor_id );
    if ( $row && $row->status === 'listing_hidden' ) {
        $result['ok']     = false;
        $result['reason'] = 'Vendor exit in progress — final settlement will be processed by admin once outstanding points clear.';
    }
    return $result;
}

/* -------------------------------------------------------------------------
 * 4. Admin page — Nation Club → Vendor Exits
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', 'nc_vendor_exit_register_admin_page', 25 );

function nc_vendor_exit_register_admin_page() {
    add_submenu_page(
        'nation-club',
        'Vendor Exits',
        'Vendor Exits',
        'manage_options',
        'nc-vendor-exits',
        'nc_vendor_exit_render_admin_page'
    );
}

function nc_vendor_exit_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle POST actions
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['nc_exit_action'] ) ) {
        nc_vendor_exit_handle_post();
    }

    global $wpdb;
    $table = nc_vendor_exit_table();
    $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY FIELD(status,'notice_active','listing_hidden','settled') ASC, updated_at DESC" );

    $msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
    $err = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : '';
    ?>
    <div class="wrap">
        <h1>Vendor Exits</h1>
        <p>Two-month managed offboarding for vendors leaving the platform. <strong>Step 1</strong> happens offline; from <strong>Step 2</strong> onwards everything flows through this page.</p>

        <?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
        <?php if ( $err ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>

        <h2 class="title">Start a new exit notice</h2>
        <?php nc_vendor_exit_render_start_form(); ?>

        <p style="margin-top:14px">
            <strong>Test cron:</strong>
            <form method="post" style="display:inline">
                <?php wp_nonce_field( 'nc_exit_test_last_day_email' ); ?>
                <input type="hidden" name="nc_exit_action" value="run_last_day_email">
                <button type="submit" class="button" onclick="return confirm('Force-run the last-day email cron now? Any active exit whose notice_end_date matches today and has not yet been emailed will receive the reminder.');">Run Last-Day Email Cron (Test)</button>
            </form>
        </p>

        <h2 class="title" style="margin-top:30px">Active &amp; recent exits</h2>
        <?php if ( empty( $rows ) ) : ?>
            <p><em>No vendor exits recorded.</em></p>
        <?php else : ?>
            <?php nc_vendor_exit_render_table( $rows ); ?>
        <?php endif; ?>
    </div>
    <?php
}

function nc_vendor_exit_render_start_form() {
    $vendors = nc_vendor_exit_eligible_vendors();
    $today   = wp_date( 'Y-m-d' );
    ?>
    <form method="post" style="background:#fff;border:1px solid #ddd;padding:14px 18px;border-radius:6px;max-width:640px">
        <?php wp_nonce_field( 'nc_exit_start' ); ?>
        <input type="hidden" name="nc_exit_action" value="start_notice">
        <table class="form-table" style="margin:0">
            <tr>
                <th><label for="nc_exit_vendor_id">Vendor</label></th>
                <td>
                    <?php if ( empty( $vendors ) ) : ?>
                        <em>No eligible vendors (all providers either don't exist or already have an active exit).</em>
                    <?php else : ?>
                        <select name="vendor_id" id="nc_exit_vendor_id" required style="min-width:280px">
                            <option value="">— Select vendor —</option>
                            <?php foreach ( $vendors as $u ) : ?>
                                <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="nc_exit_start_date">Notice start date</label></th>
                <td>
                    <input type="date" id="nc_exit_start_date" name="start_date" value="<?php echo esc_attr( $today ); ?>" required>
                    <p class="description">Notice period is <?php echo (int) NC_VENDOR_EXIT_NOTICE_MONTHS; ?> months. End date is computed automatically.</p>
                </td>
            </tr>
            <tr>
                <th><label for="nc_exit_note">Admin note <em>(optional)</em></label></th>
                <td><textarea name="admin_note" id="nc_exit_note" rows="2" style="width:100%"></textarea></td>
            </tr>
        </table>
        <p>
            <button type="submit" class="button button-primary" <?php disabled( empty( $vendors ) ); ?>>Start Exit Notice</button>
        </p>
    </form>
    <?php
}

function nc_vendor_exit_render_table( $rows ) {
    $today = strtotime( wp_date( 'Y-m-d' ) );
    ?>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>Vendor</th>
                <th>Status</th>
                <th>Notice period</th>
                <th>Pool balance</th>
                <th>Outstanding pts</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rows as $r ) :
                $user      = get_user_by( 'id', (int) $r->vendor_id );
                $vname     = $user ? $user->display_name : ( 'User #' . (int) $r->vendor_id );
                $vemail    = $user ? $user->user_email : '—';
                $balance   = nc_vendor_exit_pool_balance( $r->vendor_id );
                $outstand  = nc_vendor_exit_outstanding_points( $r->vendor_id );
                $end_ts    = strtotime( $r->notice_end_date );
                $days_left = (int) round( ( $end_ts - $today ) / DAY_IN_SECONDS );

                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $vname ); ?></strong><br>
                        <small><?php echo esc_html( $vemail ); ?></small>
                    </td>
                    <td><?php echo nc_vendor_exit_status_badge( $r->status ); ?></td>
                    <td>
                        <?php echo esc_html( wp_date( 'M j, Y', strtotime( $r->notice_start_date ) ) ); ?>
                        → <?php echo esc_html( wp_date( 'M j, Y', $end_ts ) ); ?>
                        <?php if ( $r->status === 'notice_active' ) : ?>
                            <br>
                            <?php if ( $days_left > 0 ) : ?>
                                <small style="color:#856404"><?php echo (int) $days_left; ?> day(s) left</small>
                            <?php else : ?>
                                <small style="color:#1a8d2e"><strong>Notice period ended</strong></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>SGD <?php echo esc_html( number_format( $balance, 2 ) ); ?></td>
                    <td>
                        <?php echo esc_html( number_format( $outstand, 2 ) ); ?>
                        <?php if ( $r->status === 'listing_hidden' && $outstand <= 0 ) : ?>
                            <br><small style="color:#1a8d2e"><strong>Cleared</strong></small>
                        <?php endif; ?>
                    </td>
                    <td><?php nc_vendor_exit_render_row_actions( $r, $balance, $outstand, $days_left ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function nc_vendor_exit_status_badge( $status ) {
    $map = array(
        'notice_active'   => array( 'Notice Active', '#fef3c7', '#92400e' ),
        'listing_hidden'  => array( 'Listing Hidden', '#dbeafe', '#1e40af' ),
        'settled'         => array( 'Settled', '#dcfce7', '#166534' ),
    );
    $info = $map[ $status ] ?? array( ucfirst( $status ), '#eee', '#444' );
    return sprintf(
        '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:%s;color:%s;font-size:11px;font-weight:600;text-transform:uppercase">%s</span>',
        esc_attr( $info[1] ),
        esc_attr( $info[2] ),
        esc_html( $info[0] )
    );
}

function nc_vendor_exit_render_row_actions( $row, $balance, $outstanding, $days_left ) {
    $min = defined( 'NC_VENDOR_POOL_MIN_BALANCE' ) ? NC_VENDOR_POOL_MIN_BALANCE : 1000;

    if ( $row->status === 'notice_active' ) :
        $can_hide = ( $balance >= $min );
        ?>
        <form method="post" style="display:inline-block">
            <?php wp_nonce_field( 'nc_exit_hide_' . (int) $row->id ); ?>
            <input type="hidden" name="nc_exit_action" value="hide_listing">
            <input type="hidden" name="exit_id" value="<?php echo (int) $row->id; ?>">
            <button type="submit" class="button" <?php disabled( ! $can_hide ); ?> onclick="return confirm('Hide this vendor from the booking page now?');">Hide Listing</button>
        </form>
        <?php if ( ! $can_hide ) : ?>
            <p style="margin:6px 0 0;color:#b32d2e;font-size:12px">Pool balance below SGD <?php echo number_format( $min, 2 ); ?> — top up first.</p>
        <?php endif; ?>
        <form method="post" style="display:inline-block;margin-left:6px">
            <?php wp_nonce_field( 'nc_exit_revert_' . (int) $row->id ); ?>
            <input type="hidden" name="nc_exit_action" value="revert">
            <input type="hidden" name="exit_id" value="<?php echo (int) $row->id; ?>">
            <button type="submit" class="button-link-delete" style="background:none;border:0;color:#b32d2e;cursor:pointer" onclick="return confirm('Cancel this exit notice?');">Cancel notice</button>
        </form>
    <?php elseif ( $row->status === 'listing_hidden' ) :
        $can_settle = ( $outstanding <= 0 );
        ?>
        <form method="post" style="display:flex;flex-direction:column;gap:6px;align-items:flex-start">
            <?php wp_nonce_field( 'nc_exit_settle_' . (int) $row->id ); ?>
            <input type="hidden" name="nc_exit_action" value="finalize">
            <input type="hidden" name="exit_id" value="<?php echo (int) $row->id; ?>">
            <input type="text" name="wise_reference" placeholder="Wise reference" style="width:170px" required>
            <button type="submit" class="button button-primary" <?php disabled( ! $can_settle ); ?> onclick="return confirm('Process final settlement of SGD <?php echo number_format( $balance, 2 ); ?>?');">Final Settlement</button>
        </form>
        <?php if ( ! $can_settle ) : ?>
            <p style="margin:6px 0 0;color:#666;font-size:12px"><?php echo esc_html( number_format( $outstanding, 2 ) ); ?> pts still outstanding — wait for redemption or expiry.</p>
        <?php endif; ?>
        <form method="post" style="margin-top:6px">
            <?php wp_nonce_field( 'nc_exit_revert_' . (int) $row->id ); ?>
            <input type="hidden" name="nc_exit_action" value="revert">
            <input type="hidden" name="exit_id" value="<?php echo (int) $row->id; ?>">
            <button type="submit" class="button-link-delete" style="background:none;border:0;color:#b32d2e;cursor:pointer" onclick="return confirm('Restore the listing and cancel this exit?');">Restore listing</button>
        </form>
    <?php elseif ( $row->status === 'settled' ) :
        ?>
        <small>
            Settled <?php echo $row->final_settlement_at ? esc_html( wp_date( 'M j, Y', strtotime( $row->final_settlement_at ) ) ) : ''; ?><br>
            Refund: SGD <?php echo esc_html( number_format( (float) $row->final_settlement_amount, 2 ) ); ?><br>
            Wise ref: <?php echo esc_html( $row->final_settlement_wise_ref ?: '—' ); ?>
        </small>
        <?php if ( $row->rejoined_at ) : ?>
            <p style="margin:8px 0 0;color:#1a8d2e;font-size:12px">
                <strong>Rejoined</strong> on <?php echo esc_html( wp_date( 'M j, Y', strtotime( $row->rejoined_at ) ) ); ?>
            </p>
        <?php else : ?>
            <form method="post" style="margin-top:8px">
                <?php wp_nonce_field( 'nc_exit_rejoin_' . (int) $row->id ); ?>
                <input type="hidden" name="nc_exit_action" value="rejoin">
                <input type="hidden" name="exit_id" value="<?php echo (int) $row->id; ?>">
                <button type="submit" class="button" onclick="return confirm('Restore this vendor to the platform? They will be visible on the booking page again, but must top up to SGD 1,000 before resuming bookings.');">Rejoin</button>
            </form>
        <?php endif; ?>
    <?php endif;
}

function nc_vendor_exit_handle_post() {
    $action  = sanitize_text_field( wp_unslash( $_POST['nc_exit_action'] ) );
    $admin_id = get_current_user_id();

    if ( $action === 'start_notice' ) {
        check_admin_referer( 'nc_exit_start' );
        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );
        $start     = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
        $note      = sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ?? '' ) );
        $res       = nc_vendor_exit_start_notice( $vendor_id, $start, $admin_id, $note );
        nc_vendor_exit_redirect( $res );
    }

    if ( $action === 'hide_listing' ) {
        $exit_id = (int) ( $_POST['exit_id'] ?? 0 );
        check_admin_referer( 'nc_exit_hide_' . $exit_id );
        $res = nc_vendor_exit_hide_listing( $exit_id, $admin_id );
        nc_vendor_exit_redirect( $res );
    }

    if ( $action === 'finalize' ) {
        $exit_id = (int) ( $_POST['exit_id'] ?? 0 );
        check_admin_referer( 'nc_exit_settle_' . $exit_id );
        $wise = sanitize_text_field( wp_unslash( $_POST['wise_reference'] ?? '' ) );
        $res  = nc_vendor_exit_finalize( $exit_id, $wise, $admin_id );
        nc_vendor_exit_redirect( $res );
    }

    if ( $action === 'revert' ) {
        $exit_id = (int) ( $_POST['exit_id'] ?? 0 );
        check_admin_referer( 'nc_exit_revert_' . $exit_id );
        $res = nc_vendor_exit_revert( $exit_id, $admin_id );
        nc_vendor_exit_redirect( $res );
    }

    if ( $action === 'rejoin' ) {
        $exit_id = (int) ( $_POST['exit_id'] ?? 0 );
        check_admin_referer( 'nc_exit_rejoin_' . $exit_id );
        $res = nc_vendor_exit_rejoin( $exit_id, $admin_id );
        nc_vendor_exit_redirect( $res );
    }

    if ( $action === 'run_last_day_email' ) {
        check_admin_referer( 'nc_exit_test_last_day_email' );
        nc_vendor_exit_last_day_email_handler();
        nc_vendor_exit_redirect( array(
            'ok'      => true,
            'message' => 'Last-day email cron run forced. Check Nation Club → Log for results.',
        ) );
    }
}

function nc_vendor_exit_redirect( $res ) {
    $url = admin_url( 'admin.php?page=nc-vendor-exits' );
    if ( ! empty( $res['ok'] ) ) {
        $url = add_query_arg( 'msg', $res['message'], $url );
    } else {
        $url = add_query_arg( 'err', $res['message'] ?? 'Action failed.', $url );
    }
    wp_safe_redirect( $url );
    exit;
}

/* -------------------------------------------------------------------------
 * 5. Last-day email reminder
 *
 * Hooks onto the shared daily cron (nc_statement_daily_cron). For each exit
 * in notice_active status whose notice_end_date matches today, sends the
 * vendor a heads-up email — reminds them to top up to SGD 1,000 if their
 * pool is below, and notes the outstanding points that will be refunded
 * after account closure. Stamped with last_day_email_sent_at for idempotency.
 *
 * Template is editable from Nation Club → Email Templates.
 * ----------------------------------------------------------------------- */

add_filter( 'nc_email_template_registry', 'nc_vendor_exit_register_email_template' );

function nc_vendor_exit_register_email_template( $registry ) {
    $registry['vendor_exit_last_day'] = array(
        'label'    => 'Vendor Exit — Last Day Reminder (vendor)',
        'audience' => 'vendor',
        'tokens'   => array(
            '{vendor_name}'        => 'Vendor display name',
            '{notice_start}'       => 'Notice period start date',
            '{notice_end}'         => 'Notice period end date (= today)',
            '{pool_balance}'       => 'Current vendor pool balance (SGD)',
            '{required_minimum}'   => 'Required minimum balance (SGD 1,000)',
            '{topup_required}'     => 'Amount needed to reach the minimum (SGD)',
            '{outstanding_points}' => 'Points still in customer wallets (refundable after they clear)',
            '{site_name}'          => 'WordPress site name',
        ),
        'defaults' => array(
            'subject' => 'Final day of your Nation Club notice period',
            'body'    => "Dear {vendor_name},\n\n" .
                         "Today ({notice_end}) is the last day of your 2-month notice period with Nation Club. Thank you for being part of our platform.\n\n" .
                         "**Account status**\n" .
                         "• Notice period: {notice_start} → {notice_end}\n" .
                         "• Current pool balance: SGD {pool_balance}\n" .
                         "• Required minimum: SGD {required_minimum}\n" .
                         "• Top-up required to reach minimum: SGD {topup_required}\n" .
                         "• Outstanding points still in customer wallets: {outstanding_points}\n\n" .
                         "If your pool balance is below SGD {required_minimum}, please top up the difference (SGD {topup_required}) via the vendor portal today so we can proceed with closing your account in good standing.\n\n" .
                         "Any outstanding points still held by customers will clear over the coming weeks through redemption or expiry. Once they reach zero, your remaining pool balance will be refunded to your Wise account.\n\n" .
                         "If you have any questions or want to reconsider, please contact us before end of day.\n\n" .
                         "Regards,\n{site_name}",
        ),
    );
    return $registry;
}

add_action( 'nc_statement_daily_cron', 'nc_vendor_exit_last_day_email_handler' );

function nc_vendor_exit_last_day_email_handler() {
    if ( ! function_exists( 'nc_email_send' ) ) {
        return;
    }

    global $wpdb;
    $table = nc_vendor_exit_table();
    $today = wp_date( 'Y-m-d' );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE status = 'notice_active'
           AND notice_end_date = %s
           AND last_day_email_sent_at IS NULL",
        $today
    ) );

    if ( empty( $rows ) ) {
        if ( function_exists( 'nc_statement_cron_log' ) ) {
            nc_statement_cron_log( '--- Vendor exit last-day email: no exits ending today ---' );
        }
        return;
    }

    if ( function_exists( 'nc_statement_cron_log' ) ) {
        nc_statement_cron_log( '--- Vendor exit last-day email: ' . count( $rows ) . ' vendor(s) ---' );
    }

    $min = defined( 'NC_VENDOR_POOL_MIN_BALANCE' ) ? NC_VENDOR_POOL_MIN_BALANCE : 1000;

    foreach ( $rows as $r ) {
        $vendor = get_user_by( 'id', (int) $r->vendor_id );
        if ( ! $vendor || ! is_email( $vendor->user_email ) ) {
            continue;
        }

        $balance      = nc_vendor_exit_pool_balance( $r->vendor_id );
        $outstanding  = nc_vendor_exit_outstanding_points( $r->vendor_id );
        $topup_needed = max( 0, round( $min - $balance, 2 ) );

        $tokens = array(
            '{vendor_name}'        => $vendor->display_name,
            '{notice_start}'       => wp_date( 'F j, Y', strtotime( $r->notice_start_date ) ),
            '{notice_end}'         => wp_date( 'F j, Y', strtotime( $r->notice_end_date ) ),
            '{pool_balance}'       => number_format( $balance, 2 ),
            '{required_minimum}'   => number_format( $min, 2 ),
            '{topup_required}'     => number_format( $topup_needed, 2 ),
            '{outstanding_points}' => number_format( $outstanding, 2 ),
            '{site_name}'          => get_bloginfo( 'name' ),
        );

        $sent = nc_email_send( 'vendor_exit_last_day', $vendor->user_email, $tokens );

        if ( $sent ) {
            $wpdb->update( $table, array( 'last_day_email_sent_at' => current_time( 'mysql' ) ), array( 'id' => (int) $r->id ) );
            if ( function_exists( 'nc_statement_cron_log' ) ) {
                nc_statement_cron_log( "  OK   — sent to {$vendor->user_email} (vendor {$r->vendor_id}, exit {$r->id})" );
            }
        } else {
            if ( function_exists( 'nc_statement_cron_log' ) ) {
                nc_statement_cron_log( "  FAIL — could not send to {$vendor->user_email} (vendor {$r->vendor_id})" );
            }
        }
    }
}
