<?php
/**
 * Real-time Reconciliation Dashboard + Month-end locked snapshots
 *
 * Admin page (Nation Club → Reconciliation) that surfaces a live "System
 * Health Check" so admin can spot issues — wrong vendor deductions,
 * duplicate redemptions, customer balance bugs — before month-end.
 *
 * Numbers shown:
 *   - Total Vendor Pool       = sum of all Amelia provider balances
 *   - Total Customer Points   = sum of all non-provider balances
 *   - System Total            = vendor pool + customer points
 *   - Total Top-ups           = sum of vendor_topup ledger entries
 *   - Total Withdrawals       = sum of vendor_withdrawal entries (abs)
 *   - Total Expired           = sum of points_expiry entries (abs)
 *   - Expected Total          = topups − withdrawals − expired
 *   - Status                  = Balanced / Mismatch (delta)
 *
 * Auto-refreshes every 30 seconds via AJAX so the page stays current
 * during testing without forcing a full reload.
 *
 * Month-end locked snapshots (separate from per-vendor monthly statements):
 *   - Captured automatically on the 1st of each month for the previous month
 *   - Stored immutably in wp_nc_reconciliation_snapshots
 *   - Browseable in the dashboard's "Snapshot History" section
 *   - Idempotent: re-capturing the same month is a no-op (unique key on month)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NC_RECONCILIATION_DB_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 * 0. Schema — month-end snapshots
 * ----------------------------------------------------------------------- */

function nc_reconciliation_snapshots_table() {
    global $wpdb;
    return $wpdb->prefix . 'nc_reconciliation_snapshots';
}

function nc_reconciliation_snapshots_install() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = nc_reconciliation_snapshots_table();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        snapshot_month VARCHAR(7) NOT NULL,
        vendor_pool DECIMAL(14,2) NOT NULL DEFAULT 0,
        customer_points DECIMAL(14,2) NOT NULL DEFAULT 0,
        system_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_topups DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_withdrawals DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_expired DECIMAL(14,2) NOT NULL DEFAULT 0,
        expected_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        delta DECIMAL(14,2) NOT NULL DEFAULT 0,
        balanced TINYINT(1) NOT NULL DEFAULT 0,
        captured_at DATETIME DEFAULT NULL,
        captured_by BIGINT UNSIGNED DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY snapshot_month (snapshot_month)
    ) {$charset};";

    dbDelta( $sql );
    update_option( 'nc_reconciliation_db_version', NC_RECONCILIATION_DB_VERSION );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'nc_reconciliation_db_version' ) !== NC_RECONCILIATION_DB_VERSION ) {
        nc_reconciliation_snapshots_install();
    }
} );

/* -------------------------------------------------------------------------
 * 1. Core calculation
 * ----------------------------------------------------------------------- */

/**
 * Compute the live reconciliation snapshot.
 *
 * @return array{
 *   vendor_pool: float,
 *   customer_points: float,
 *   system_total: float,
 *   total_topups: float,
 *   total_withdrawals: float,
 *   total_expired: float,
 *   expected_total: float,
 *   delta: float,
 *   balanced: bool,
 *   as_of: string,
 * }
 */
function nc_reconciliation_calculate() {
    global $wpdb;

    $caps_meta = $wpdb->prefix . 'capabilities';
    $log_tbl   = $wpdb->prefix . 'myCRED_log';

    // Total Vendor Pool — sum balances of all wpamelia-provider users.
    $vendor_pool = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(CAST(um.meta_value AS DECIMAL(20,4))), 0)
         FROM {$wpdb->usermeta} um
         INNER JOIN {$wpdb->usermeta} caps ON caps.user_id = um.user_id
         WHERE um.meta_key = 'mycred_default'
           AND caps.meta_key = %s
           AND caps.meta_value LIKE %s",
        $caps_meta,
        '%wpamelia-provider%'
    ) );

    // Total Customer Points — sum balances of all NON-providers.
    // Includes any non-provider WP user with a mycred_default meta row.
    $customer_points = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(CAST(um.meta_value AS DECIMAL(20,4))), 0)
         FROM {$wpdb->usermeta} um
         WHERE um.meta_key = 'mycred_default'
           AND um.user_id NOT IN (
               SELECT caps.user_id
               FROM {$wpdb->usermeta} caps
               WHERE caps.meta_key = %s
                 AND caps.meta_value LIKE %s
           )",
        $caps_meta,
        '%wpamelia-provider%'
    ) );

    $system_total = $vendor_pool + $customer_points;

    // Money in/out via Wise (top-ups +, withdrawals -)
    $total_topups = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl} WHERE ref = 'vendor_topup'"
    );
    $total_withdrawals = abs( (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl} WHERE ref = 'vendor_withdrawal'"
    ) );

    // Customer points expired (informational, NOT credited back to vendor)
    $total_expired = abs( (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl} WHERE ref = 'points_expiry'"
    ) );

    // Expected = real money in, less money out, less points lost to expiry
    $expected_total = $total_topups - $total_withdrawals - $total_expired;

    $delta    = round( $system_total - $expected_total, 2 );
    $balanced = abs( $delta ) < 0.01;

    return array(
        'vendor_pool'       => round( $vendor_pool, 2 ),
        'customer_points'   => round( $customer_points, 2 ),
        'system_total'      => round( $system_total, 2 ),
        'total_topups'      => round( $total_topups, 2 ),
        'total_withdrawals' => round( $total_withdrawals, 2 ),
        'total_expired'     => round( $total_expired, 2 ),
        'expected_total'    => round( $expected_total, 2 ),
        'delta'             => $delta,
        'balanced'          => $balanced,
        'as_of'             => current_time( 'mysql' ),
    );
}

/**
 * Compute the reconciliation state AS OF a specific cutoff timestamp.
 *
 * Back-calculates by reverse-applying log entries that happened on or after
 * the cutoff. Used by snapshot capture so a "March 2026" snapshot reflects
 * the actual end-of-March state, not whatever the live numbers happen to
 * be at the moment of capture.
 *
 * Cutoff is exclusive: rows where `time < $cutoff_ts` are included.
 */
function nc_reconciliation_calculate_as_of( $cutoff_ts ) {
    global $wpdb;

    $caps_meta = $wpdb->prefix . 'capabilities';
    $log_tbl   = $wpdb->prefix . 'myCRED_log';

    // Current vendor pool (sum of all provider balances)
    $current_vendor = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(CAST(um.meta_value AS DECIMAL(20,4))), 0)
         FROM {$wpdb->usermeta} um
         INNER JOIN {$wpdb->usermeta} caps ON caps.user_id = um.user_id
         WHERE um.meta_key = 'mycred_default'
           AND caps.meta_key = %s
           AND caps.meta_value LIKE %s",
        $caps_meta, '%wpamelia-provider%'
    ) );
    // Reverse-apply post-cutoff vendor log activity
    $post_cutoff_vendor = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(l.creds), 0)
         FROM {$log_tbl} l
         INNER JOIN {$wpdb->usermeta} caps ON caps.user_id = l.user_id
         WHERE l.time >= %d
           AND caps.meta_key = %s
           AND caps.meta_value LIKE %s",
        $cutoff_ts, $caps_meta, '%wpamelia-provider%'
    ) );
    $vendor_pool = $current_vendor - $post_cutoff_vendor;

    // Current customer points (non-providers)
    $current_customer = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(CAST(um.meta_value AS DECIMAL(20,4))), 0)
         FROM {$wpdb->usermeta} um
         WHERE um.meta_key = 'mycred_default'
           AND um.user_id NOT IN (
               SELECT caps.user_id FROM {$wpdb->usermeta} caps
               WHERE caps.meta_key = %s AND caps.meta_value LIKE %s
           )",
        $caps_meta, '%wpamelia-provider%'
    ) );
    $post_cutoff_customer = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(l.creds), 0)
         FROM {$log_tbl} l
         WHERE l.time >= %d
           AND l.user_id NOT IN (
               SELECT caps.user_id FROM {$wpdb->usermeta} caps
               WHERE caps.meta_key = %s AND caps.meta_value LIKE %s
           )",
        $cutoff_ts, $caps_meta, '%wpamelia-provider%'
    ) );
    $customer_points = $current_customer - $post_cutoff_customer;

    $system_total = $vendor_pool + $customer_points;

    // Lifetime money-flow totals AS OF the cutoff
    $total_topups = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl} WHERE ref = 'vendor_topup' AND time < %d",
        $cutoff_ts
    ) );
    $total_withdrawals = abs( (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl} WHERE ref = 'vendor_withdrawal' AND time < %d",
        $cutoff_ts
    ) ) );
    $total_expired = abs( (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl} WHERE ref = 'points_expiry' AND time < %d",
        $cutoff_ts
    ) ) );

    $expected_total = $total_topups - $total_withdrawals - $total_expired;
    $delta          = round( $system_total - $expected_total, 2 );
    $balanced       = abs( $delta ) < 0.01;

    return array(
        'vendor_pool'       => round( $vendor_pool, 2 ),
        'customer_points'   => round( $customer_points, 2 ),
        'system_total'      => round( $system_total, 2 ),
        'total_topups'      => round( $total_topups, 2 ),
        'total_withdrawals' => round( $total_withdrawals, 2 ),
        'total_expired'     => round( $total_expired, 2 ),
        'expected_total'    => round( $expected_total, 2 ),
        'delta'             => $delta,
        'balanced'          => $balanced,
        'as_of'             => current_time( 'mysql' ),
    );
}

/**
 * Compute the "This Month" rolling reconciliation.
 *
 * Initial = previous month's snapshot System Total. If no snapshot exists, we
 * fall back to 0 (genesis assumption). The dashboard surfaces the source so
 * admin knows whether the check is fully verified or genesis-based.
 *
 * Expected = Initial + Topups (this month) − Withdrawals (this month) − Expired (this month)
 *
 * Useful for spotting bugs early: if April was Balanced at month-end but May
 * shows a Mismatch mid-month, you immediately know the bug appeared in May.
 */
function nc_reconciliation_calculate_this_month() {
    global $wpdb;

    $tz             = wp_timezone();
    $now            = new DateTimeImmutable( 'now', $tz );
    $month_str      = $now->format( 'Y-m' );
    $month_label    = $now->format( 'F Y' );
    $month_start_dt = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'm' ), 1 )->setTime( 0, 0, 0 );
    $next_month_dt  = $month_start_dt->modify( '+1 month' );
    $prev_month_str = $month_start_dt->modify( '-1 month' )->format( 'Y-m' );

    $start_ts = $month_start_dt->getTimestamp();
    $end_ts   = $next_month_dt->getTimestamp();

    // Look up previous month's snapshot
    $snap_table   = nc_reconciliation_snapshots_table();
    $prev_system  = $wpdb->get_var( $wpdb->prepare(
        "SELECT system_total FROM {$snap_table} WHERE snapshot_month = %s",
        $prev_month_str
    ) );

    if ( $prev_system !== null ) {
        $initial        = (float) $prev_system;
        $initial_source = 'snapshot';
    } else {
        $initial        = 0.0;
        $initial_source = 'genesis';
    }

    // This month's ledger movements
    $log_tbl = $wpdb->prefix . 'myCRED_log';

    $topups = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl}
         WHERE ref = 'vendor_topup' AND time >= %d AND time < %d",
        $start_ts, $end_ts
    ) );

    $withdrawals = abs( (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl}
         WHERE ref = 'vendor_withdrawal' AND time >= %d AND time < %d",
        $start_ts, $end_ts
    ) ) );

    $expired = abs( (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(creds), 0) FROM {$log_tbl}
         WHERE ref = 'points_expiry' AND time >= %d AND time < %d",
        $start_ts, $end_ts
    ) ) );

    $expected_now = $initial + $topups - $withdrawals - $expired;

    // Reuse the lifetime calc for the "actual now" number
    $live       = nc_reconciliation_calculate();
    $actual_now = $live['system_total'];

    $delta    = round( $actual_now - $expected_now, 2 );
    $balanced = abs( $delta ) < 0.01;

    return array(
        'month'           => $month_str,
        'month_label'     => $month_label,
        'month_start'     => $month_start_dt->format( 'Y-m-d H:i:s' ),
        'prev_month'      => $prev_month_str,
        'initial'         => round( $initial, 2 ),
        'initial_source'  => $initial_source, // 'snapshot' | 'genesis'
        'topups'          => round( $topups, 2 ),
        'withdrawals'     => round( $withdrawals, 2 ),
        'expired'         => round( $expired, 2 ),
        'expected_now'    => round( $expected_now, 2 ),
        'actual_now'      => round( $actual_now, 2 ),
        'delta'           => $delta,
        'balanced'        => $balanced,
    );
}

/**
 * Capture a frozen month-end snapshot of the live reconciliation numbers.
 *
 * Idempotent: if a snapshot for the given month already exists, this is a
 * no-op (returns ok=false, message='already exists'). To re-capture a month,
 * delete the existing row first.
 *
 * @param string $month        YYYY-MM
 * @param int    $captured_by  WP user id of the actor (0 for cron)
 * @param string $notes        Optional free-text note
 * @return array{ok:bool, message:string, id:int}
 */
function nc_reconciliation_capture_snapshot( $month, $captured_by = 0, $notes = '' ) {
    global $wpdb;

    if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
        return array( 'ok' => false, 'message' => 'Invalid month format. Expected YYYY-MM.', 'id' => 0 );
    }

    $table = nc_reconciliation_snapshots_table();

    $existing = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE snapshot_month = %s",
        $month
    ) );
    if ( $existing > 0 ) {
        return array( 'ok' => false, 'message' => sprintf( 'Snapshot for %s already exists (#%d). Delete it first to re-capture.', $month, $existing ), 'id' => $existing );
    }

    // Cutoff = first second of (month + 1) — anything with time < cutoff belongs to this month or earlier.
    $tz        = wp_timezone();
    $cutoff_dt = ( new DateTimeImmutable( $month . '-01', $tz ) )->modify( '+1 month' )->setTime( 0, 0, 0 );
    $cutoff_ts = $cutoff_dt->getTimestamp();

    // Back-calculate the system state as of the end of the requested month
    // (so a "March 2026" snapshot captured in April still reflects March-end numbers).
    $r = nc_reconciliation_calculate_as_of( $cutoff_ts );

    $insert = array(
        'snapshot_month'    => $month,
        'vendor_pool'       => $r['vendor_pool'],
        'customer_points'   => $r['customer_points'],
        'system_total'      => $r['system_total'],
        'total_topups'      => $r['total_topups'],
        'total_withdrawals' => $r['total_withdrawals'],
        'total_expired'     => $r['total_expired'],
        'expected_total'    => $r['expected_total'],
        'delta'             => $r['delta'],
        'balanced'          => $r['balanced'] ? 1 : 0,
        'captured_at'       => current_time( 'mysql' ),
        'captured_by'       => (int) $captured_by,
        'notes'             => $notes ?: null,
    );

    $ok = $wpdb->insert( $table, $insert );
    if ( ! $ok ) {
        return array( 'ok' => false, 'message' => 'DB insert failed: ' . $wpdb->last_error, 'id' => 0 );
    }

    return array(
        'ok'      => true,
        'message' => sprintf(
            'Snapshot captured for %s — System: %s, Expected: %s, %s.',
            $month,
            number_format( $r['system_total'], 2 ),
            number_format( $r['expected_total'], 2 ),
            $r['balanced'] ? 'Balanced' : 'Mismatch (' . number_format( $r['delta'], 2 ) . ')'
        ),
        'id'      => (int) $wpdb->insert_id,
    );
}

/**
 * Cron handler — runs daily but only does work on the 1st of each month.
 * Captures the snapshot for the previous calendar month.
 */
add_action( 'nc_statement_daily_cron', 'nc_reconciliation_snapshot_cron_handler' );

function nc_reconciliation_snapshot_cron_handler( $force = false ) {
    $today = (int) wp_date( 'j' );

    if ( ! $force && $today !== 1 ) {
        return; // daily-check log is already written by the statement cron
    }

    $prev_month = $force
        ? wp_date( 'Y-m', strtotime( 'first day of last month' ) )
        : wp_date( 'Y-m', strtotime( '-1 day' ) );

    $tag = $force ? '[MANUAL TEST]' : '';
    if ( function_exists( 'nc_statement_cron_log' ) ) {
        nc_statement_cron_log( "--- Reconciliation snapshot run {$tag} — target month {$prev_month} ---" );
    }

    $r = nc_reconciliation_capture_snapshot( $prev_month, 0, $force ? 'Manual test run' : 'Auto-captured by daily cron' );

    if ( function_exists( 'nc_statement_cron_log' ) ) {
        nc_statement_cron_log( $r['ok'] ? '  OK   — ' . $r['message'] : '  SKIP — ' . $r['message'] );
        nc_statement_cron_log( '--- End reconciliation snapshot run ---' . PHP_EOL );
    }
}

/**
 * Fetch snapshots, newest first, paginated.
 *
 * @param int $paged    1-indexed page number
 * @param int $per_page Rows per page
 * @return array{rows: array, total: int}
 */
function nc_reconciliation_snapshot_history( $paged = 1, $per_page = 20 ) {
    global $wpdb;
    $table = nc_reconciliation_snapshots_table();

    $paged    = max( 1, (int) $paged );
    $per_page = max( 1, (int) $per_page );
    $offset   = ( $paged - 1 ) * $per_page;

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.*, u.display_name AS captured_by_name
         FROM {$table} s
         LEFT JOIN {$wpdb->users} u ON u.ID = s.captured_by
         ORDER BY s.snapshot_month DESC, s.id DESC
         LIMIT %d OFFSET %d",
        $per_page, $offset
    ) );

    return array( 'rows' => $rows, 'total' => $total );
}

/**
 * Per-vendor breakdown for the dashboard's drill-down section, paginated.
 *
 * @param int $paged    1-indexed page number
 * @param int $per_page Rows per page
 * @return array{rows: array, total: int}
 */
function nc_reconciliation_vendor_breakdown( $paged = 1, $per_page = 20 ) {
    global $wpdb;
    $caps_meta = $wpdb->prefix . 'capabilities';

    $paged    = max( 1, (int) $paged );
    $per_page = max( 1, (int) $per_page );
    $offset   = ( $paged - 1 ) * $per_page;

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} caps ON caps.user_id = u.ID
         WHERE caps.meta_key = %s
           AND caps.meta_value LIKE %s",
        $caps_meta, '%wpamelia-provider%'
    ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT u.ID AS user_id, u.display_name, u.user_email,
                COALESCE(CAST(bal.meta_value AS DECIMAL(20,4)), 0) AS balance
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} caps ON caps.user_id = u.ID
         LEFT  JOIN {$wpdb->usermeta} bal  ON bal.user_id  = u.ID AND bal.meta_key = 'mycred_default'
         WHERE caps.meta_key = %s
           AND caps.meta_value LIKE %s
         ORDER BY balance ASC, u.display_name ASC
         LIMIT %d OFFSET %d",
        $caps_meta, '%wpamelia-provider%', $per_page, $offset
    ) );

    return array( 'rows' => $rows, 'total' => $total );
}

/**
 * Render WP-native list-table pagination (« ‹ "1 of N" › » buttons).
 * Matches the look of WP_List_Table::pagination() so it visually aligns with
 * other admin tables in WordPress core.
 */
function nc_recon_render_pagination( $current, $total_pages, $total_items, $param, $label, $max_width = 0 ) {
    if ( $total_pages <= 1 ) {
        return;
    }

    $current     = max( 1, (int) $current );
    $total_pages = max( 1, (int) $total_pages );
    $base_url    = remove_query_arg( $param );

    $can_prev = $current > 1;
    $can_next = $current < $total_pages;

    $first_url = $can_prev ? add_query_arg( $param, 1, $base_url ) : false;
    $prev_url  = $can_prev ? add_query_arg( $param, $current - 1, $base_url ) : false;
    $next_url  = $can_next ? add_query_arg( $param, $current + 1, $base_url ) : false;
    $last_url  = $can_next ? add_query_arg( $param, $total_pages, $base_url ) : false;

    $style = $max_width ? sprintf( 'max-width:%dpx', (int) $max_width ) : '';
    ?>
    <div class="tablenav bottom" style="<?php echo esc_attr( $style ); ?>">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html( number_format_i18n( $total_items ) . ' ' . $label ); ?></span>
            <span class="pagination-links">
                <?php if ( $first_url ) : ?>
                    <a class="first-page button" href="<?php echo esc_url( $first_url ); ?>"><span class="screen-reader-text">First page</span><span aria-hidden="true">«</span></a>
                <?php else : ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
                <?php endif; ?>

                <?php if ( $prev_url ) : ?>
                    <a class="prev-page button" href="<?php echo esc_url( $prev_url ); ?>"><span class="screen-reader-text">Previous page</span><span aria-hidden="true">‹</span></a>
                <?php else : ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
                <?php endif; ?>

                <span class="paging-input">
                    <span class="tablenav-paging-text">
                        <?php echo (int) $current; ?> of <span class="total-pages"><?php echo (int) $total_pages; ?></span>
                    </span>
                </span>

                <?php if ( $next_url ) : ?>
                    <a class="next-page button" href="<?php echo esc_url( $next_url ); ?>"><span class="screen-reader-text">Next page</span><span aria-hidden="true">›</span></a>
                <?php else : ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
                <?php endif; ?>

                <?php if ( $last_url ) : ?>
                    <a class="last-page button" href="<?php echo esc_url( $last_url ); ?>"><span class="screen-reader-text">Last page</span><span aria-hidden="true">»</span></a>
                <?php else : ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * 2. Admin menu
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'nc-reconciliation',
        'nc_admin_reconciliation_page',
        5
    );
}, 14 );

/* -------------------------------------------------------------------------
 * 3. Admin page
 * ----------------------------------------------------------------------- */

function nc_admin_reconciliation_page() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

    // POST actions for snapshot management
    if ( ! empty( $_POST['nc_recon_action'] ) ) {
        nc_admin_reconciliation_handle_post();
    }

    $r        = nc_reconciliation_calculate();
    $tm       = nc_reconciliation_calculate_this_month();
    $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
    $nonce    = wp_create_nonce( 'nc_reconciliation_refresh' );

    // Pagination — independent params so paging one section doesn't reset the other.
    $vend_paged   = isset( $_GET['vend_paged'] ) ? max( 1, (int) $_GET['vend_paged'] ) : 1;
    $snap_paged   = isset( $_GET['snap_paged'] ) ? max( 1, (int) $_GET['snap_paged'] ) : 1;
    $per_page     = 20;

    $vendor_data       = nc_reconciliation_vendor_breakdown( $vend_paged, $per_page );
    $vendors           = $vendor_data['rows'];
    $vendors_total     = $vendor_data['total'];
    $vendors_pages     = (int) ceil( max( 1, $vendors_total ) / $per_page );

    $snapshot_data     = nc_reconciliation_snapshot_history( $snap_paged, $per_page );
    $snapshots         = $snapshot_data['rows'];
    $snapshots_total   = $snapshot_data['total'];
    $snapshots_pages   = (int) ceil( max( 1, $snapshots_total ) / $per_page );
    ?>
    <div class="wrap">
        <h1>Dashboard
            <span class="nc-recon-status nc-recon-status--<?php echo $r['balanced'] ? 'ok' : 'err'; ?>" data-status>
                <?php echo $r['balanced'] ? '✓ Balanced' : '✗ Mismatch'; ?>
            </span>
        </h1>

        <?php if ( ! empty( $_GET['nc_recon_msg'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_recon_msg'] ) ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( ! empty( $_GET['nc_recon_err'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_recon_err'] ) ) ); ?></p></div>
        <?php endif; ?>
        <p class="nc-recon-subtitle">
            Live system health check — refreshes every 30 seconds.
            Last updated: <span data-as-of><?php echo esc_html( mysql2date( 'M j, Y H:i:s', $r['as_of'] ) ); ?></span>
            &nbsp;·&nbsp;
            <button type="button" class="button button-small" id="nc-recon-refresh-btn">Refresh Now</button>
        </p>

        <!-- 4 big number cards -->
        <div class="nc-recon-cards">
            <div class="nc-recon-card">
                <div class="lbl">Total Vendor Pool</div>
                <div class="val" data-key="vendor_pool"><?php echo esc_html( number_format( $r['vendor_pool'], 2 ) ); ?></div>
                <div class="sub">Sum of all vendor balances</div>
            </div>
            <div class="nc-recon-card">
                <div class="lbl">Total Customer Points</div>
                <div class="val" data-key="customer_points"><?php echo esc_html( number_format( $r['customer_points'], 2 ) ); ?></div>
                <div class="sub">Sum of all customer balances</div>
            </div>
            <div class="nc-recon-card nc-recon-card--system">
                <div class="lbl">System Total</div>
                <div class="val" data-key="system_total"><?php echo esc_html( number_format( $r['system_total'], 2 ) ); ?></div>
                <div class="sub">vendors + customers</div>
            </div>
            <div class="nc-recon-card nc-recon-card--expected">
                <div class="lbl">Expected Total <span style="font-weight:400;text-transform:none;letter-spacing:0">(lifetime)</span></div>
                <div class="val" data-key="expected_total"><?php echo esc_html( number_format( $r['expected_total'], 2 ) ); ?></div>
                <div class="sub">topups − withdrawals − expired</div>
            </div>
        </div>

        <!-- This Month rolling reconciliation -->
        <div class="nc-recon-thismonth nc-recon-thismonth--<?php echo $tm['balanced'] ? 'ok' : 'err'; ?>" data-tm-banner>
            <div class="nc-recon-thismonth__header">
                <h2 style="margin:0">This Month — <span data-tm-month-label><?php echo esc_html( $tm['month_label'] ); ?></span></h2>
                <span class="nc-recon-status nc-recon-status--<?php echo $tm['balanced'] ? 'ok' : 'err'; ?>" data-tm-status>
                    <?php echo $tm['balanced'] ? '✓ Balanced' : '✗ Mismatch'; ?>
                </span>
            </div>
            <p class="nc-recon-thismonth__hint">
                Localised check — compares this month's activity against where the system started on the 1st.
                Helps pinpoint <em>which month</em> a bug appeared in.
            </p>
            <table class="nc-recon-thismonth__table">
                <tbody>
                    <tr>
                        <td>Where we started <small data-tm-initial-source>(<?php
                            if ( $tm['initial_source'] === 'snapshot' ) {
                                echo esc_html( 'from ' . $tm['prev_month'] . ' snapshot' );
                            } else {
                                echo esc_html( 'no prior snapshot — assuming 0' );
                            }
                        ?>)</small></td>
                        <td class="num"><span data-tm-key="initial"><?php echo esc_html( number_format( $tm['initial'], 2 ) ); ?></span></td>
                    </tr>
                    <tr>
                        <td>+ Top-ups added this month</td>
                        <td class="num pos">+<span data-tm-key="topups"><?php echo esc_html( number_format( $tm['topups'], 2 ) ); ?></span></td>
                    </tr>
                    <tr>
                        <td>− Withdrawals paid out this month</td>
                        <td class="num neg">−<span data-tm-key="withdrawals"><?php echo esc_html( number_format( $tm['withdrawals'], 2 ) ); ?></span></td>
                    </tr>
                    <tr>
                        <td>− Customer points expired this month</td>
                        <td class="num neg">−<span data-tm-key="expired"><?php echo esc_html( number_format( $tm['expired'], 2 ) ); ?></span></td>
                    </tr>
                    <tr class="total">
                        <td><strong>= Expected System Total now</strong></td>
                        <td class="num"><strong><span data-tm-key="expected_now"><?php echo esc_html( number_format( $tm['expected_now'], 2 ) ); ?></span></strong></td>
                    </tr>
                    <tr class="actual">
                        <td><strong>Actual System Total now</strong></td>
                        <td class="num"><strong><span data-tm-key="actual_now"><?php echo esc_html( number_format( $tm['actual_now'], 2 ) ); ?></span></strong></td>
                    </tr>
                    <?php if ( ! $tm['balanced'] ) : ?>
                    <tr class="delta">
                        <td>Delta this month</td>
                        <td class="num neg"><span data-tm-key="delta"><?php echo esc_html( ( $tm['delta'] >= 0 ? '+' : '' ) . number_format( $tm['delta'], 2 ) ); ?></span></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ( $tm['initial_source'] === 'genesis' ) : ?>
                <p class="nc-recon-thismonth__warn">
                    ⚠ No snapshot exists for <?php echo esc_html( $tm['prev_month'] ); ?>.
                    Initial value is set to 0 (genesis assumption).
                    For a fully verified rolling check, capture <?php echo esc_html( $tm['prev_month'] ); ?>'s snapshot in the Snapshot History section below.
                </p>
            <?php endif; ?>
        </div>

        <!-- Status banner -->
        <div class="nc-recon-banner nc-recon-banner--<?php echo $r['balanced'] ? 'ok' : 'err'; ?>" data-banner>
            <?php if ( $r['balanced'] ) : ?>
                <strong>✓ System is Balanced.</strong>
                System Total matches Expected Total exactly.
            <?php else : ?>
                <strong>✗ Mismatch detected.</strong>
                System Total is <span data-delta-pretty><?php echo esc_html( ( $r['delta'] >= 0 ? '+' : '' ) . number_format( $r['delta'], 2 ) ); ?></span> compared to Expected Total.
                Investigate recent vendor deductions, duplicate redemptions, or expired points adjustments.
            <?php endif; ?>
        </div>

        <!-- Breakdown -->
        <h2 style="margin-top:28px">Money Flow Breakdown</h2>
        <table class="widefat striped" style="max-width:640px">
            <tbody>
                <tr>
                    <td>Total Top-ups (vendor_topup)</td>
                    <td style="text-align:right;color:#1a8d2e;font-weight:600">+<span data-key="total_topups"><?php echo esc_html( number_format( $r['total_topups'], 2 ) ); ?></span></td>
                </tr>
                <tr>
                    <td>Total Withdrawals (vendor_withdrawal)</td>
                    <td style="text-align:right;color:#c62828;font-weight:600">−<span data-key="total_withdrawals"><?php echo esc_html( number_format( $r['total_withdrawals'], 2 ) ); ?></span></td>
                </tr>
                <tr>
                    <td>Total Expired Customer Points (points_expiry)</td>
                    <td style="text-align:right;color:#c62828;font-weight:600">−<span data-key="total_expired"><?php echo esc_html( number_format( $r['total_expired'], 2 ) ); ?></span></td>
                </tr>
                <tr style="background:#f0f0f0">
                    <td><strong>Expected Total</strong></td>
                    <td style="text-align:right;font-weight:700"><span data-key="expected_total_2"><?php echo esc_html( number_format( $r['expected_total'], 2 ) ); ?></span></td>
                </tr>
            </tbody>
        </table>

        <!-- Per-vendor drill-down -->
        <h2 style="margin-top:28px">Per-Vendor Balances</h2>
        <p style="color:#666">Vendors below SGD 1,000 are highlighted — they need to top up.</p>
        <table class="widefat striped" style="max-width:760px">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Email</th>
                    <th style="text-align:right">Balance</th>
                    <th style="width:100px">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $vendors ) ) : ?>
                    <tr><td colspan="4"><em>No vendors found.</em></td></tr>
                <?php else : foreach ( $vendors as $v ) :
                    $bal       = (float) $v->balance;
                    $is_below  = $bal < (float) NC_VENDOR_POOL_MIN_BALANCE;
                    $is_zero   = $bal == 0;
                    $row_style = $is_zero ? 'background:#fee2e2' : ( $is_below ? 'background:#fff3cd' : '' );
                    ?>
                    <tr style="<?php echo $row_style; ?>">
                        <td><?php echo esc_html( $v->display_name ?: ( 'User #' . $v->user_id ) ); ?></td>
                        <td><small><?php echo esc_html( $v->user_email ); ?></small></td>
                        <td style="text-align:right;font-weight:600"><?php echo esc_html( number_format( $bal, 2 ) ); ?></td>
                        <td>
                            <?php if ( $is_zero ) : ?>
                                <span style="color:#991b1b;font-weight:600">Zero</span>
                            <?php elseif ( $is_below ) : ?>
                                <span style="color:#856404;font-weight:600">Below Min</span>
                            <?php else : ?>
                                <span style="color:#1a8d2e;font-weight:600">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php nc_recon_render_pagination( $vend_paged, $vendors_pages, $vendors_total, 'vend_paged', 'vendors', 760 ); ?>

        <!-- Month-end locked snapshots -->
        <h2 style="margin-top:32px">Snapshot History</h2>
        <p style="color:#666">
            Frozen month-end captures for official record-keeping. Auto-captured on the 1st of each month.
            Once stored, snapshots are immutable — deleting + re-capturing is the only way to update one.
        </p>

        <form method="post" style="background:#fff;padding:12px 14px;border:1px solid #ccd0d4;border-radius:4px;margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <?php wp_nonce_field( 'nc_recon_snapshot_capture' ); ?>
            <input type="hidden" name="nc_recon_action" value="capture_snapshot">
            <strong>Capture snapshot for month:</strong>
            <input type="month" name="snapshot_month" value="<?php echo esc_attr( wp_date( 'Y-m', strtotime( '-1 month' ) ) ); ?>" required>
            <button type="submit" class="button button-primary" onclick="return confirm('Capture a frozen snapshot for the selected month using the current live numbers?');">Capture Snapshot Now</button>
            <span style="color:#666;margin-left:6px">(Auto-runs on the 1st via cron — this is for testing or filling gaps.)</span>
        </form>

        <table class="widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align:right">Vendor Pool</th>
                    <th style="text-align:right">Customer Points</th>
                    <th style="text-align:right">System Total</th>
                    <th style="text-align:right">Expected Total</th>
                    <th>Status</th>
                    <th>Captured</th>
                    <th style="width:90px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $snapshots ) ) : ?>
                    <tr><td colspan="8"><em>No snapshots yet. Either wait for the 1st of next month, or click "Capture Snapshot Now" above.</em></td></tr>
                <?php else : foreach ( $snapshots as $s ) :
                    $month_label = wp_date( 'F Y', strtotime( $s->snapshot_month . '-01' ) );
                    $captured_at = $s->captured_at ? mysql2date( 'M j, Y H:i', $s->captured_at ) : '—';
                    $captured_by = $s->captured_by_name ?: ( $s->captured_by > 0 ? ( 'User #' . $s->captured_by ) : 'Cron' );
                    $is_balanced = (int) $s->balanced === 1;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $month_label ); ?></strong><br><small><?php echo esc_html( $s->snapshot_month ); ?></small></td>
                        <td style="text-align:right"><?php echo esc_html( number_format( (float) $s->vendor_pool, 2 ) ); ?></td>
                        <td style="text-align:right"><?php echo esc_html( number_format( (float) $s->customer_points, 2 ) ); ?></td>
                        <td style="text-align:right;font-weight:600"><?php echo esc_html( number_format( (float) $s->system_total, 2 ) ); ?></td>
                        <td style="text-align:right;font-weight:600"><?php echo esc_html( number_format( (float) $s->expected_total, 2 ) ); ?></td>
                        <td>
                            <?php if ( $is_balanced ) : ?>
                                <span style="background:#d4edda;color:#155724;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">✓ Balanced</span>
                            <?php else : ?>
                                <span style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">✗ <?php echo esc_html( ( $s->delta >= 0 ? '+' : '' ) . number_format( (float) $s->delta, 2 ) ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo esc_html( $captured_at ); ?><br><span style="color:#999"><?php echo esc_html( $captured_by ); ?></span></small></td>
                        <td>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'nc_recon_snapshot_delete_' . (int) $s->id ); ?>
                                <input type="hidden" name="nc_recon_action" value="delete_snapshot">
                                <input type="hidden" name="snapshot_id" value="<?php echo esc_attr( $s->id ); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('Delete the snapshot for <?php echo esc_js( $month_label ); ?>? This cannot be undone, but you can re-capture afterwards.');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php nc_recon_render_pagination( $snap_paged, $snapshots_pages, $snapshots_total, 'snap_paged', 'snapshots', 1100 ); ?>

        <p style="color:#888;font-size:12px;margin-top:18px">
            <strong>How to read this dashboard:</strong>
            "Expected Total (lifetime)" = all real money ever put into the system via Wise top-ups, minus withdrawals paid out,
            minus customer points that have expired. "System Total" = sum of every balance the system currently holds.
            They should match exactly. The "This Month" section above is a rolling check — it pinpoints which calendar month
            a discrepancy first appeared, by comparing this month's activity against where the system started on the 1st
            (taken from the previous month's snapshot). Snapshots freeze these numbers monthly for the official accounting record.
        </p>
    </div>

    <style>
        .nc-recon-status {
            display: inline-block;
            margin-left: 12px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            vertical-align: middle;
        }
        .nc-recon-status--ok  { background: #d4edda; color: #155724; }
        .nc-recon-status--err { background: #f8d7da; color: #721c24; }

        .nc-recon-subtitle {
            color: #666;
            margin: 6px 0 18px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .nc-recon-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        @media (max-width: 900px) {
            .nc-recon-cards { grid-template-columns: repeat(2, 1fr); }
        }
        .nc-recon-card {
            background: #fff;
            padding: 18px 20px;
            border: 1px solid #ccd0d4;
            border-radius: 8px;
        }
        .nc-recon-card .lbl {
            font-size: 12px;
            color: #777;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .nc-recon-card .val {
            font-size: 26px;
            font-weight: 700;
            color: #2c2c2c;
            margin: 10px 0;
            letter-spacing: -0.02em;
        }
        .nc-recon-card .sub {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }
        .nc-recon-card--system {
            background: linear-gradient(135deg, #faf5f7 0%, #f4ebef 100%);
            border-color: #ecd9e0;
        }
        .nc-recon-card--system .lbl { color: #8b1c3b; }
        .nc-recon-card--expected {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-color: #bae6fd;
        }
        .nc-recon-card--expected .lbl { color: #075985; }

        .nc-recon-banner {
            padding: 14px 18px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 18px;
            border: 2px solid;
        }
        .nc-recon-banner--ok  { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .nc-recon-banner--err { background: #f8d7da; color: #721c24; border-color: #f5c2c7; }

        .nc-recon-thismonth {
            background: #fff;
            border: 2px solid #ccd0d4;
            border-radius: 8px;
            padding: 16px 20px 12px;
            margin-bottom: 18px;
        }
        .nc-recon-thismonth--ok  { border-color: #c3e6cb; background: #f6fbf7; }
        .nc-recon-thismonth--err { border-color: #f5c2c7; background: #fdf6f7; }
        .nc-recon-thismonth__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 4px;
        }
        .nc-recon-thismonth__hint {
            color: #666;
            font-size: 13px;
            margin: 0 0 12px;
        }
        .nc-recon-thismonth__table {
            width: 100%;
            max-width: 720px;
            border-collapse: collapse;
        }
        .nc-recon-thismonth__table td {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        .nc-recon-thismonth__table td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            white-space: nowrap;
        }
        .nc-recon-thismonth__table td.num.pos { color: #1a8d2e; }
        .nc-recon-thismonth__table td.num.neg { color: #c62828; }
        .nc-recon-thismonth__table tr.total td { border-top: 2px solid #999; padding-top: 10px; }
        .nc-recon-thismonth__table tr.actual td { background: #fafafa; }
        .nc-recon-thismonth__table tr.delta td { color: #c62828; }
        .nc-recon-thismonth__table small { color: #888; font-weight: 400; }
        .nc-recon-thismonth__warn {
            margin: 12px 0 0;
            padding: 10px 12px;
            background: #fff8e1;
            border: 1px solid #f0d28a;
            border-radius: 4px;
            color: #6c4f00;
            font-size: 13px;
        }

        .nc-recon-flash {
            animation: nc-recon-flash 0.6s ease;
        }
        @keyframes nc-recon-flash {
            0%   { background: #fff3cd; }
            100% { background: transparent; }
        }
    </style>

    <script>
    (function () {
        var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
        var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
        var POLL_MS = 30000;
        var timer   = null;

        function fmt(n) {
            return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function flash(el) {
            if (!el) return;
            el.classList.remove('nc-recon-flash');
            void el.offsetWidth;
            el.classList.add('nc-recon-flash');
        }

        function applyData(data) {
            // Update card values
            ['vendor_pool', 'customer_points', 'system_total', 'expected_total', 'total_topups', 'total_withdrawals', 'total_expired'].forEach(function (key) {
                document.querySelectorAll('[data-key="' + key + '"]').forEach(function (el) {
                    var oldText = el.textContent;
                    var newText = fmt(data[key]);
                    if (oldText !== newText) {
                        el.textContent = newText;
                        flash(el.closest('.nc-recon-card') || el);
                    }
                });
            });
            // Also update the duplicated expected_total row
            document.querySelectorAll('[data-key="expected_total_2"]').forEach(function (el) {
                el.textContent = fmt(data.expected_total);
            });

            // Status pill
            var statusEl = document.querySelector('[data-status]');
            if (statusEl) {
                statusEl.classList.remove('nc-recon-status--ok', 'nc-recon-status--err');
                statusEl.classList.add(data.balanced ? 'nc-recon-status--ok' : 'nc-recon-status--err');
                statusEl.textContent = data.balanced ? '✓ Balanced' : '✗ Mismatch';
            }

            // Banner
            var banner = document.querySelector('[data-banner]');
            if (banner) {
                banner.classList.remove('nc-recon-banner--ok', 'nc-recon-banner--err');
                banner.classList.add(data.balanced ? 'nc-recon-banner--ok' : 'nc-recon-banner--err');
                if (data.balanced) {
                    banner.innerHTML = '<strong>✓ System is Balanced.</strong> System Total matches Expected Total exactly.';
                } else {
                    var deltaPretty = (data.delta >= 0 ? '+' : '') + fmt(data.delta);
                    banner.innerHTML = '<strong>✗ Mismatch detected.</strong> System Total is <span data-delta-pretty>' + deltaPretty + '</span> compared to Expected Total. Investigate recent vendor deductions, duplicate redemptions, or expired points adjustments.';
                }
            }

            // As-of timestamp
            var asOfEl = document.querySelector('[data-as-of]');
            if (asOfEl) { asOfEl.textContent = data.as_of_pretty || data.as_of; }

            // This-month rolling section
            var tm = data.this_month || null;
            if (tm) {
                ['initial', 'topups', 'withdrawals', 'expired', 'expected_now', 'actual_now'].forEach(function (key) {
                    document.querySelectorAll('[data-tm-key="' + key + '"]').forEach(function (el) {
                        var oldText = el.textContent;
                        var newText = fmt(tm[key]);
                        if (oldText !== newText) {
                            el.textContent = newText;
                            flash(el.parentElement);
                        }
                    });
                });

                var tmMonthEl = document.querySelector('[data-tm-month-label]');
                if (tmMonthEl) { tmMonthEl.textContent = tm.month_label; }

                var tmStatusEl = document.querySelector('[data-tm-status]');
                if (tmStatusEl) {
                    tmStatusEl.classList.remove('nc-recon-status--ok', 'nc-recon-status--err');
                    tmStatusEl.classList.add(tm.balanced ? 'nc-recon-status--ok' : 'nc-recon-status--err');
                    tmStatusEl.textContent = tm.balanced ? '✓ Balanced' : '✗ Mismatch';
                }

                var tmBanner = document.querySelector('[data-tm-banner]');
                if (tmBanner) {
                    tmBanner.classList.remove('nc-recon-thismonth--ok', 'nc-recon-thismonth--err');
                    tmBanner.classList.add(tm.balanced ? 'nc-recon-thismonth--ok' : 'nc-recon-thismonth--err');
                }

                var tmSrcEl = document.querySelector('[data-tm-initial-source]');
                if (tmSrcEl) {
                    tmSrcEl.textContent = '(' + (tm.initial_source === 'snapshot'
                        ? 'from ' + tm.prev_month + ' snapshot'
                        : 'no prior snapshot — assuming 0') + ')';
                }
            }
        }

        function fetchSnapshot() {
            var fd = new FormData();
            fd.append('action', 'nc_reconciliation_refresh');
            fd.append('_ajax_nonce', nonce);
            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json && json.success && json.data) {
                        applyData(json.data);
                    }
                })
                .catch(function () { /* swallow — next poll will retry */ });
        }

        document.getElementById('nc-recon-refresh-btn').addEventListener('click', function () {
            fetchSnapshot();
        });

        timer = setInterval(fetchSnapshot, POLL_MS);
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * 4. AJAX endpoint for live refresh
 * ----------------------------------------------------------------------- */

add_action( 'wp_ajax_nc_reconciliation_refresh', 'nc_ajax_reconciliation_refresh' );

function nc_ajax_reconciliation_refresh() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }
    check_ajax_referer( 'nc_reconciliation_refresh' );

    $r                 = nc_reconciliation_calculate();
    $r['as_of_pretty'] = mysql2date( 'M j, Y H:i:s', $r['as_of'] );
    $r['this_month']   = nc_reconciliation_calculate_this_month();

    wp_send_json_success( $r );
}

/* -------------------------------------------------------------------------
 * 5. POST handlers — snapshot capture / delete
 * ----------------------------------------------------------------------- */

function nc_admin_reconciliation_handle_post() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

    $action = sanitize_key( wp_unslash( $_POST['nc_recon_action'] ) );

    if ( $action === 'capture_snapshot' ) {
        check_admin_referer( 'nc_recon_snapshot_capture' );
        $month = isset( $_POST['snapshot_month'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_month'] ) ) : '';
        $r     = nc_reconciliation_capture_snapshot( $month, get_current_user_id(), 'Manual capture from admin' );
        nc_recon_redirect_back( $r['ok'] ? 'msg' : 'err', $r['message'] );
    }

    if ( $action === 'delete_snapshot' ) {
        $id = isset( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0;
        check_admin_referer( 'nc_recon_snapshot_delete_' . $id );
        global $wpdb;
        $deleted = (int) $wpdb->delete( nc_reconciliation_snapshots_table(), array( 'id' => $id ) );
        nc_recon_redirect_back(
            $deleted ? 'msg' : 'err',
            $deleted ? sprintf( 'Snapshot #%d deleted.', $id ) : sprintf( 'Snapshot #%d not found.', $id )
        );
    }
}

function nc_recon_redirect_back( $type, $message ) {
    $args = array( 'page' => 'nc-reconciliation' );
    $args[ 'nc_recon_' . ( $type === 'err' ? 'err' : 'msg' ) ] = rawurlencode( $message );
    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}
