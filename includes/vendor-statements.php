<?php
/**
 * Monthly Statement Module
 *
 * Admin-facing Nation Club → Monthly Statements page.
 *
 * What it does:
 * - Calculates a per-vendor, per-month statement from the myCRED log.
 * - Persists the snapshot in wp_nc_statements so numbers don't drift when logs change.
 * - Tracks a status workflow: draft → finalized → sent → viewed → completed.
 * - Plugs into nc_vendor_can_withdraw so withdrawals unlock only after the previous
 *   month's statement is Finalized (closes the NON-NEGOTIABLE Lock Period rule).
 *
 * Calculation sources (grouped strictly by vendor_id):
 *   + redeem_accept       — customer redeemed old points at this vendor
 *   - earn_liability      — this vendor issued new points to a customer
 *   - redeem_liability    — this vendor (as origin) bore cost of customer redemption elsewhere
 *   + vendor_topup        — admin-credited top-up
 *   - vendor_withdrawal   — processed payout
 *   (informational) points_expiry where data.liability_vendor_id = this vendor
 *
 * Closing = Opening + (accepted) - (earn_liability) - (redeem_liability)
 *           + (topup) - (withdrawal)
 * (Expired points are shown but NOT added back — per product decision.)
 *
 * Deferred (Phase 2): PDF generation, email sending, vendor portal view.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NC_STATEMENT_DB_VERSION', '1.1.0' );

/* -------------------------------------------------------------------------
 * 1. Schema
 * ----------------------------------------------------------------------- */

function nc_statements_table() {
    global $wpdb;
    return $wpdb->prefix . 'nc_statements';
}

function nc_statements_install() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = nc_statements_table();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        vendor_id BIGINT UNSIGNED NOT NULL,
        statement_month VARCHAR(7) NOT NULL,
        opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        closing_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        points_accepted DECIMAL(12,2) NOT NULL DEFAULT 0,
        points_earn_liability DECIMAL(12,2) NOT NULL DEFAULT 0,
        points_redeem_liability DECIMAL(12,2) NOT NULL DEFAULT 0,
        points_topup DECIMAL(12,2) NOT NULL DEFAULT 0,
        points_withdrawal DECIMAL(12,2) NOT NULL DEFAULT 0,
        points_expired DECIMAL(12,2) NOT NULL DEFAULT 0,
        shared_costs DECIMAL(12,2) NOT NULL DEFAULT 0,
        topup_required DECIMAL(12,2) NOT NULL DEFAULT 0,
        surplus DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        detail_data LONGTEXT DEFAULT NULL,
        generated_at DATETIME DEFAULT NULL,
        generated_by BIGINT UNSIGNED DEFAULT NULL,
        finalized_at DATETIME DEFAULT NULL,
        finalized_by BIGINT UNSIGNED DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        sent_by BIGINT UNSIGNED DEFAULT NULL,
        viewed_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        completed_by BIGINT UNSIGNED DEFAULT NULL,
        email_sent_at DATETIME DEFAULT NULL,
        email_sent_to VARCHAR(191) DEFAULT NULL,
        email_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY vendor_month (vendor_id, statement_month),
        KEY status (status)
    ) {$charset};";

    dbDelta( $sql );
    update_option( 'nc_statements_db_version', NC_STATEMENT_DB_VERSION );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'nc_statements_db_version' ) !== NC_STATEMENT_DB_VERSION ) {
        nc_statements_install();
    }
} );

/* -------------------------------------------------------------------------
 * 2. Computation helpers
 * ----------------------------------------------------------------------- */

/**
 * Balance at a given unix timestamp.
 *
 * Derived from the CURRENT myCRED balance (source of truth) minus the net of
 * all log entries posted after $ts. This captures off-log adjustments — e.g.
 * the initial 1,000 seed an admin sets directly on the vendor's myCRED balance
 * when onboarding — which a plain log-sum would miss.
 */
function nc_statement_balance_at( $vendor_id, $ts ) {
    global $wpdb;
    $log = $wpdb->prefix . 'myCRED_log';

    $current = function_exists( 'mycred_get_users_balance' )
        ? (float) mycred_get_users_balance( (int) $vendor_id )
        : 0;

    $after = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(creds),0) FROM {$log} WHERE user_id = %d AND time > %d",
        (int) $vendor_id,
        (int) $ts
    ) );

    return round( $current - $after, 2 );
}

/**
 * First and last unix timestamps of a month string 'YYYY-MM' in WP timezone.
 */
function nc_statement_month_bounds( $month_str ) {
    $tz = wp_timezone();
    $start = new DateTime( $month_str . '-01 00:00:00', $tz );
    $end   = clone $start;
    $end->modify( 'last day of this month' )->setTime( 23, 59, 59 );
    return array(
        'start_ts'  => $start->getTimestamp(),
        'end_ts'    => $end->getTimestamp(),
        'start_str' => $start->format( 'Y-m-d H:i:s' ),
        'end_str'   => $end->format( 'Y-m-d H:i:s' ),
    );
}

/**
 * All myCRED_log entries for a vendor in a date range.
 */
function nc_statement_fetch_entries( $vendor_id, $start_ts, $end_ts ) {
    global $wpdb;
    $log = $wpdb->prefix . 'myCRED_log';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id, ref, ref_id, creds, entry, data, time
         FROM {$log}
         WHERE user_id = %d
           AND time BETWEEN %d AND %d
         ORDER BY time ASC, id ASC",
        (int) $vendor_id,
        (int) $start_ts,
        (int) $end_ts
    ) );
}

/**
 * Expired-points lines where this vendor was the liability vendor (informational only).
 * These are logged against the CUSTOMER's user_id, so we filter via JSON data column.
 */
function nc_statement_fetch_expired_liability( $vendor_id, $start_ts, $end_ts ) {
    global $wpdb;
    $log = $wpdb->prefix . 'myCRED_log';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id, user_id, ref_id, creds, entry, data, time
         FROM {$log}
         WHERE ref = 'points_expiry'
           AND time BETWEEN %d AND %d
           AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.liability_vendor_id')) = %s
         ORDER BY time ASC, id ASC",
        (int) $start_ts,
        (int) $end_ts,
        (string) $vendor_id
    ) );
}

/**
 * Compute all statement numbers for a vendor+month from the ledger.
 * Does NOT persist — returns an associative array.
 */
function nc_statement_compute( $vendor_id, $month_str ) {
    $bounds  = nc_statement_month_bounds( $month_str );
    $opening = nc_statement_balance_at( $vendor_id, $bounds['start_ts'] - 1 );
    $entries = nc_statement_fetch_entries( $vendor_id, $bounds['start_ts'], $bounds['end_ts'] );

    $accepted = 0; $earn_liab = 0; $redeem_liab = 0; $topup = 0; $withdrawal = 0;

    foreach ( $entries as $row ) {
        $creds = (float) $row->creds;
        switch ( $row->ref ) {
            case 'redeem_accept':
                $accepted += $creds;
                break;
            case 'earn_liability':
                $earn_liab += abs( $creds );
                break;
            case 'redeem_liability':
                $redeem_liab += abs( $creds );
                break;
            case 'vendor_topup':
                $topup += $creds;
                break;
            case 'vendor_withdrawal':
                $withdrawal += abs( $creds );
                break;
        }
    }

    $expired_rows = nc_statement_fetch_expired_liability( $vendor_id, $bounds['start_ts'], $bounds['end_ts'] );
    $expired = 0;
    foreach ( $expired_rows as $row ) {
        $expired += abs( (float) $row->creds );
    }

    $closing = round( $opening + $accepted - $earn_liab - $redeem_liab + $topup - $withdrawal, 2 );

    $min = defined( 'NC_VENDOR_POOL_MIN_BALANCE' ) ? NC_VENDOR_POOL_MIN_BALANCE : 1000;
    $topup_required = max( 0, round( $min - $closing, 2 ) );
    $surplus        = max( 0, round( $closing - $min, 2 ) );

    return array(
        'opening_balance'         => $opening,
        'points_accepted'         => round( $accepted, 2 ),
        'points_earn_liability'   => round( $earn_liab, 2 ),
        'points_redeem_liability' => round( $redeem_liab, 2 ),
        'points_topup'            => round( $topup, 2 ),
        'points_withdrawal'       => round( $withdrawal, 2 ),
        'points_expired'          => round( $expired, 2 ),
        'shared_costs'            => 0,
        'closing_balance'         => $closing,
        'topup_required'          => $topup_required,
        'surplus'                 => $surplus,
        'detail_entries'          => $entries,
        'expired_entries'         => $expired_rows,
        'bounds'                  => $bounds,
    );
}

/**
 * Generate (or regenerate) a statement row. Only regenerates if existing is draft.
 * Returns [ 'ok' => bool, 'message' => string, 'id' => int ].
 */
function nc_statement_generate( $vendor_id, $month_str, $admin_id ) {
    global $wpdb;
    $table = nc_statements_table();

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, status FROM {$table} WHERE vendor_id = %d AND statement_month = %s",
        (int) $vendor_id,
        $month_str
    ) );

    if ( $existing && $existing->status !== 'draft' ) {
        return array(
            'ok'      => false,
            'message' => 'Statement is ' . $existing->status . ' — cannot regenerate. Revert to draft first if needed.',
            'id'      => (int) $existing->id,
        );
    }

    $calc = nc_statement_compute( $vendor_id, $month_str );

    // Snapshot entries for later viewing (so the statement is frozen even if logs change)
    $detail_data = wp_json_encode( array(
        'entries'         => $calc['detail_entries'],
        'expired_entries' => $calc['expired_entries'],
    ) );

    $fields = array(
        'vendor_id'               => (int) $vendor_id,
        'statement_month'         => $month_str,
        'opening_balance'         => $calc['opening_balance'],
        'closing_balance'         => $calc['closing_balance'],
        'points_accepted'         => $calc['points_accepted'],
        'points_earn_liability'   => $calc['points_earn_liability'],
        'points_redeem_liability' => $calc['points_redeem_liability'],
        'points_topup'            => $calc['points_topup'],
        'points_withdrawal'       => $calc['points_withdrawal'],
        'points_expired'          => $calc['points_expired'],
        'shared_costs'            => $calc['shared_costs'],
        'topup_required'          => $calc['topup_required'],
        'surplus'                 => $calc['surplus'],
        'status'                  => 'draft',
        'detail_data'             => $detail_data,
        'generated_at'            => current_time( 'mysql' ),
        'generated_by'            => (int) $admin_id,
    );

    if ( $existing ) {
        $wpdb->update( $table, $fields, array( 'id' => $existing->id ) );
        return array( 'ok' => true, 'message' => 'Statement regenerated.', 'id' => (int) $existing->id );
    }

    $wpdb->insert( $table, $fields );
    return array( 'ok' => true, 'message' => 'Statement generated.', 'id' => (int) $wpdb->insert_id );
}

/**
 * Generate statements for all vendors who have any ledger activity in the month,
 * or who already have a pool balance. Skips vendors whose statement is non-draft.
 */
function nc_statement_generate_for_all( $month_str, $admin_id ) {
    global $wpdb;
    $bounds = nc_statement_month_bounds( $month_str );
    $log    = $wpdb->prefix . 'myCRED_log';
    $users  = $wpdb->prefix . 'users';
    $um     = $wpdb->prefix . 'usermeta';

    // Vendors = users with wpamelia-provider role AND at least one ledger entry ever
    $provider_ids = $wpdb->get_col(
        "SELECT DISTINCT u.ID
         FROM {$users} u
         INNER JOIN {$um} m ON m.user_id = u.ID
         WHERE m.meta_key = '{$wpdb->prefix}capabilities'
           AND m.meta_value LIKE '%wpamelia-provider%'"
    );

    $generated = 0; $skipped = 0;
    foreach ( $provider_ids as $vid ) {
        $result = nc_statement_generate( (int) $vid, $month_str, $admin_id );
        if ( $result['ok'] ) { $generated++; } else { $skipped++; }
    }
    return array( 'generated' => $generated, 'skipped' => $skipped, 'total' => count( $provider_ids ) );
}

/* -------------------------------------------------------------------------
 * 3. Withdrawal lock filter — "only after previous month's statement is finalized"
 * ----------------------------------------------------------------------- */

add_filter( 'nc_vendor_can_withdraw', 'nc_statement_enforce_lock_period', 10, 3 );

function nc_statement_enforce_lock_period( $result, $vendor_id, $amount ) {
    if ( ! isset( $result['ok'] ) || ! $result['ok'] ) {
        return $result; // already blocked by another rule
    }
    global $wpdb;
    $table = nc_statements_table();

    $prev_month = wp_date( 'Y-m', strtotime( '-1 month', current_time( 'timestamp' ) ) );

    $finalized = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table}
         WHERE vendor_id = %d
           AND statement_month = %s
           AND status IN ('finalized','sent','viewed','completed')",
        (int) $vendor_id,
        $prev_month
    ) );

    if ( ! $finalized ) {
        $result['ok']     = false;
        $result['reason'] = sprintf(
            'Withdrawal is locked until the statement for %s is finalized by admin.',
            $prev_month
        );
    }
    return $result;
}

/* -------------------------------------------------------------------------
 * 4. Admin menu
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Monthly Statements',
        'Monthly Statements',
        'manage_options',
        'nc-statements',
        'nc_admin_statements_router',
        2
    );
}, 11 );

/**
 * Router — single statement view vs. list.
 */
function nc_admin_statements_router() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    if ( isset( $_POST['nc_stmt_action'] ) ) {
        nc_admin_statements_handle_post();
    }
    $view_id = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0;
    if ( $view_id > 0 ) {
        nc_admin_statement_view_page( $view_id );
    } else {
        nc_admin_statements_list_page();
    }
}

/* -------------------------------------------------------------------------
 * 5. Admin: list page
 * ----------------------------------------------------------------------- */

function nc_admin_statements_list_page() {
    global $wpdb;
    $table = nc_statements_table();

    $month      = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m', strtotime( '-1 month' ) );
    $vendor_sel = isset( $_GET['vendor_id'] ) ? (int) $_GET['vendor_id'] : 0;
    $status_sel = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
    $search     = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';

    $where  = array( '1=1' );
    $params = array();

    if ( $month !== '' && preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
        $where[]  = 's.statement_month = %s';
        $params[] = $month;
    }
    if ( $vendor_sel > 0 ) {
        $where[]  = 's.vendor_id = %d';
        $params[] = $vendor_sel;
    }
    if ( in_array( $status_sel, array( 'draft', 'finalized', 'sent', 'viewed', 'completed' ), true ) ) {
        $where[]  = 's.status = %s';
        $params[] = $status_sel;
    }
    if ( $search !== '' ) {
        $like     = '%' . $wpdb->esc_like( $search ) . '%';
        $where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
        $params[] = $like; $params[] = $like;
    }

    $where_sql = implode( ' AND ', $where );

    $per_page = 20;
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    $total_sql = "SELECT COUNT(*) FROM {$table} s LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id WHERE {$where_sql}";
    $total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

    $rows_sql = "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
                 FROM {$table} s
                 LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
                 WHERE {$where_sql}
                 ORDER BY s.statement_month DESC, s.id DESC
                 LIMIT %d OFFSET %d";
    $rows_params = array_merge( $params, array( $per_page, $offset ) );
    $rows        = $total > 0 ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) ) : array();

    $total_pages = (int) ceil( $total / $per_page );

    // Vendor dropdown options (providers only)
    $providers = $wpdb->get_results(
        "SELECT u.ID, u.display_name
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
         WHERE m.meta_key = '{$wpdb->prefix}capabilities'
           AND m.meta_value LIKE '%wpamelia-provider%'
         ORDER BY u.display_name ASC"
    );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Monthly Statements</h1>

        <?php if ( isset( $_GET['nc_admin_msg'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_msg'] ) ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['nc_admin_err'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_err'] ) ) ); ?></p></div>
        <?php endif; ?>

        <hr class="wp-header-end">

        <form method="post" style="background:#fff;padding:14px;border:1px solid #ccd0d4;margin-top:16px;border-radius:4px">
            <?php wp_nonce_field( 'nc_stmt_generate' ); ?>
            <input type="hidden" name="nc_stmt_action" value="generate_batch">
            <strong>Generate statements for month:</strong>
            <input type="month" name="gen_month" value="<?php echo esc_attr( $month ); ?>" required>
            <select name="gen_vendor_id">
                <option value="0">All vendors</option>
                <?php foreach ( $providers as $p ) : ?>
                    <option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->display_name ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary">Generate / Regenerate</button>
            <span style="color:#666;margin-left:8px">(Only drafts are regenerated — finalized statements are preserved.)</span>
        </form>

        <form method="get" style="margin-top:16px">
            <input type="hidden" name="page" value="nc-statements">
            <label>Month: <input type="month" name="month" value="<?php echo esc_attr( $month ); ?>"></label>
            <label>Vendor:
                <select name="vendor_id">
                    <option value="0">All</option>
                    <?php foreach ( $providers as $p ) : ?>
                        <option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $vendor_sel, $p->ID ); ?>><?php echo esc_html( $p->display_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status:
                <select name="status">
                    <option value="">Any</option>
                    <?php foreach ( array( 'draft', 'finalized', 'sent', 'viewed', 'completed' ) as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_sel, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search vendor…">
            <button class="button">Filter</button>
            <a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=nc-statements' ) ); ?>">Reset</a>
        </form>

        <table class="wp-list-table widefat fixed striped" style="margin-top:16px">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Vendor</th>
                    <th>Opening</th>
                    <th>Closing</th>
                    <th>Top-up req.</th>
                    <th>Surplus</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="8">No statements. Use the form above to generate.</td></tr>
            <?php else : foreach ( $rows as $row ) :
                $view_url = esc_url( add_query_arg( array( 'page' => 'nc-statements', 'view' => $row->id ), admin_url( 'admin.php' ) ) );
                $csv_url  = esc_url( add_query_arg( array( 'page' => 'nc-statements', 'view' => $row->id, 'export' => 'csv', '_wpnonce' => wp_create_nonce( 'nc_stmt_csv_' . $row->id ) ), admin_url( 'admin.php' ) ) );
                $pdf_url  = esc_url( add_query_arg( array( 'page' => 'nc-statements', 'view' => $row->id, 'export' => 'pdf', '_wpnonce' => wp_create_nonce( 'nc_stmt_pdf_' . $row->id ) ), admin_url( 'admin.php' ) ) );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $row->statement_month ); ?></strong></td>
                    <td>
                        <?php echo esc_html( $row->vendor_name ?: ( 'User #' . $row->vendor_id ) ); ?><br>
                        <small><?php echo esc_html( $row->vendor_email ); ?></small>
                    </td>
                    <td><?php echo esc_html( number_format( (float) $row->opening_balance, 2 ) ); ?></td>
                    <td><strong><?php echo esc_html( number_format( (float) $row->closing_balance, 2 ) ); ?></strong></td>
                    <td><?php echo $row->topup_required > 0 ? '<span style="color:#b32d2e">' . esc_html( number_format( (float) $row->topup_required, 2 ) ) . '</span>' : '—'; ?></td>
                    <td><?php echo $row->surplus > 0 ? '<span style="color:#1a8d2e">' . esc_html( number_format( (float) $row->surplus, 2 ) ) . '</span>' : '—'; ?></td>
                    <td><?php echo nc_statement_status_badge( $row->status ); ?></td>
                    <td>
                        <a class="button button-small" href="<?php echo $view_url; ?>">View</a>
                        <a class="button button-small" href="<?php echo $pdf_url; ?>">PDF</a>
                        <a class="button button-small" href="<?php echo $csv_url; ?>">CSV</a>
                        <?php if ( in_array( $row->status, array( 'finalized', 'sent', 'viewed', 'completed' ), true ) ) :
                            $label = $row->email_sent_count > 0 ? 'Resend' : 'Email';
                            $confirm = $row->email_sent_count > 0 ? 'Resend statement email to vendor?' : 'Send statement email to vendor?';
                            ?>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'nc_stmt_email_' . $row->id ); ?>
                                <input type="hidden" name="nc_stmt_action" value="send_email">
                                <input type="hidden" name="statement_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( $confirm ); ?>');"><?php echo esc_html( $label ); ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo esc_html( number_format_i18n( $total ) ); ?> items</span>
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $total_pages,
                        'current'   => $paged,
                    ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function nc_statement_status_badge( $status ) {
    $colors = array(
        'draft'     => '#e0e0e0;color:#444',
        'finalized' => '#cce5ff;color:#004085',
        'sent'      => '#fff3cd;color:#856404',
        'viewed'    => '#d1ecf1;color:#0c5460',
        'completed' => '#d4edda;color:#155724',
    );
    $style = isset( $colors[ $status ] ) ? $colors[ $status ] : '#eee';
    return sprintf(
        '<span style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;text-transform:capitalize;background:%s">%s</span>',
        $style,
        esc_html( $status )
    );
}

/* -------------------------------------------------------------------------
 * 6. Admin: single statement view
 * ----------------------------------------------------------------------- */

function nc_admin_statement_view_page( $id ) {
    global $wpdb;
    $table = nc_statements_table();

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
         FROM {$table} s LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
         WHERE s.id = %d", $id
    ) );

    if ( ! $row ) {
        echo '<div class="wrap"><h1>Statement not found</h1></div>';
        return;
    }

    // CSV / PDF export handlers (inside view page so we can reuse the loaded row)
    if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
        check_admin_referer( 'nc_stmt_csv_' . $id );
        nc_statement_export_csv( $row );
        exit;
    }
    if ( isset( $_GET['export'] ) && $_GET['export'] === 'pdf' ) {
        check_admin_referer( 'nc_stmt_pdf_' . $id );
        nc_statement_download_pdf( $row );
        exit;
    }

    $detail = $row->detail_data ? json_decode( $row->detail_data, true ) : array( 'entries' => array(), 'expired_entries' => array() );
    $entries = isset( $detail['entries'] ) ? $detail['entries'] : array();
    $expired = isset( $detail['expired_entries'] ) ? $detail['expired_entries'] : array();

    $back = esc_url( admin_url( 'admin.php?page=nc-statements' ) );
    ?>
    <div class="wrap">
        <h1>Statement — <?php echo esc_html( $row->vendor_name ?: 'Vendor #' . $row->vendor_id ); ?> — <?php echo esc_html( $row->statement_month ); ?></h1>
        <p><a href="<?php echo $back; ?>">&larr; Back to list</a></p>

        <?php if ( isset( $_GET['nc_admin_msg'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_msg'] ) ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['nc_admin_err'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_err'] ) ) ); ?></p></div>
        <?php endif; ?>

        <div style="background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:4px;max-width:960px">
            <!-- Header -->
            <table style="width:100%;margin-bottom:20px">
                <tr>
                    <td><strong>Vendor:</strong> <?php echo esc_html( $row->vendor_name ?: 'Vendor #' . $row->vendor_id ); ?></td>
                    <td><strong>Vendor ID:</strong> <?php echo esc_html( $row->vendor_id ); ?></td>
                </tr>
                <tr>
                    <td><strong>Statement Month:</strong> <?php echo esc_html( $row->statement_month ); ?></td>
                    <td><strong>Statement Date:</strong> <?php echo esc_html( mysql2date( 'M j, Y', $row->generated_at ) ); ?></td>
                </tr>
                <tr>
                    <td colspan="2"><strong>Status:</strong> <?php echo nc_statement_status_badge( $row->status ); ?></td>
                </tr>
            </table>

            <!-- Summary -->
            <h2 style="margin-top:24px">Summary</h2>
            <table class="wp-list-table widefat striped" style="max-width:560px">
                <tr><td>Opening Points Pool balance</td><td style="text-align:right"><?php echo esc_html( number_format( (float) $row->opening_balance, 2 ) ); ?></td></tr>
                <tr><td>Points accepted from customers (+)</td><td style="text-align:right;color:#1a8d2e">+<?php echo esc_html( number_format( (float) $row->points_accepted, 2 ) ); ?></td></tr>
                <tr><td>Points issued to customers — earn liability (−)</td><td style="text-align:right;color:#c62828">−<?php echo esc_html( number_format( (float) $row->points_earn_liability, 2 ) ); ?></td></tr>
                <tr><td>Points redeemed from vendor liability (−)</td><td style="text-align:right;color:#c62828">−<?php echo esc_html( number_format( (float) $row->points_redeem_liability, 2 ) ); ?></td></tr>
                <tr><td>Vendor top-ups (+)</td><td style="text-align:right;color:#1a8d2e">+<?php echo esc_html( number_format( (float) $row->points_topup, 2 ) ); ?></td></tr>
                <tr><td>Vendor withdrawals (−)</td><td style="text-align:right;color:#c62828">−<?php echo esc_html( number_format( (float) $row->points_withdrawal, 2 ) ); ?></td></tr>
                <tr><td>Expired Points Adjustment <em>(informational)</em></td><td style="text-align:right;color:#888"><?php echo esc_html( number_format( (float) $row->points_expired, 2 ) ); ?></td></tr>
                <tr><td>Shared costs / subscription</td><td style="text-align:right"><?php echo esc_html( number_format( (float) $row->shared_costs, 2 ) ); ?></td></tr>
                <tr style="background:#f0f0f0"><td><strong>Closing balance</strong></td><td style="text-align:right"><strong><?php echo esc_html( number_format( (float) $row->closing_balance, 2 ) ); ?></strong></td></tr>
                <?php if ( $row->topup_required > 0 ) : ?>
                    <tr><td>Required reload to restore SGD 1,000</td><td style="text-align:right;color:#b32d2e"><strong><?php echo esc_html( number_format( (float) $row->topup_required, 2 ) ); ?></strong></td></tr>
                <?php endif; ?>
                <?php if ( $row->surplus > 0 ) : ?>
                    <tr><td>Surplus above SGD 1,000</td><td style="text-align:right;color:#1a8d2e"><strong><?php echo esc_html( number_format( (float) $row->surplus, 2 ) ); ?></strong></td></tr>
                <?php endif; ?>
            </table>

            <!-- Detail -->
            <h2 style="margin-top:28px">Detail</h2>
            <?php if ( empty( $entries ) && empty( $expired ) ) : ?>
                <p><em>No ledger activity in this month.</em></p>
            <?php else : ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Transaction ID</th>
                            <th>Customer</th>
                            <th>Service</th>
                            <th style="text-align:right">Points</th>
                            <th>Entry</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $entries as $e ) :
                        $data  = is_string( $e['data'] ?? '' ) ? json_decode( $e['data'], true ) : ( $e['data'] ?? array() );
                        $creds = (float) ( $e['creds'] ?? 0 );
                        $color = $creds > 0 ? '#1a8d2e' : ( $creds < 0 ? '#c62828' : '' );
                        $txn   = $data['transaction_id'] ?? ( $data['topup_id'] ?? ( $data['withdrawal_id'] ?? '—' ) );
                        $cust  = isset( $data['customer_id'] ) ? nc_statement_customer_name( (int) $data['customer_id'] ) : '—';
                        $svc   = isset( $data['service_id'] ) ? nc_statement_service_name( (int) $data['service_id'] ) : '—';
                        ?>
                        <tr>
                            <td><?php echo esc_html( wp_date( 'M j, Y', (int) $e['time'] ) ); ?></td>
                            <td><code><?php echo esc_html( $e['ref'] ); ?></code></td>
                            <td><?php echo esc_html( $txn ); ?></td>
                            <td><?php echo esc_html( $cust ); ?></td>
                            <td><?php echo esc_html( $svc ); ?></td>
                            <td style="text-align:right;color:<?php echo esc_attr( $color ); ?>;font-weight:600">
                                <?php echo ( $creds > 0 ? '+' : '' ) . esc_html( number_format( $creds, 2 ) ); ?>
                            </td>
                            <td><?php echo esc_html( wp_strip_all_tags( html_entity_decode( $e['entry'] ?? '', ENT_QUOTES, 'UTF-8' ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ( $expired as $e ) :
                        $data = is_string( $e['data'] ?? '' ) ? json_decode( $e['data'], true ) : ( $e['data'] ?? array() );
                        $cust = isset( $e['user_id'] ) ? nc_statement_customer_name_by_user( (int) $e['user_id'] ) : '—';
                        ?>
                        <tr style="color:#888">
                            <td><?php echo esc_html( wp_date( 'M j, Y', (int) $e['time'] ) ); ?></td>
                            <td><code>points_expiry</code></td>
                            <td>—</td>
                            <td><?php echo esc_html( $cust ); ?></td>
                            <td>—</td>
                            <td style="text-align:right;font-weight:600"><em><?php echo esc_html( number_format( abs( (float) $e['creds'] ), 2 ) ); ?></em></td>
                            <td><em>Expired (informational only)</em></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Footer -->
            <hr style="margin-top:28px">
            <p style="color:#666;font-size:13px">
                Generated by Nation Club system on <?php echo esc_html( mysql2date( 'M j, Y H:i', $row->generated_at ) ); ?>.<br>
                For disputes / clarification, contact Nation Club admin.
            </p>

            <!-- Export / Email actions -->
            <hr style="margin-top:28px">
            <h2>Download &amp; Email</h2>
            <?php
            $pdf_url = esc_url( add_query_arg(
                array( 'page' => 'nc-statements', 'view' => $id, 'export' => 'pdf', '_wpnonce' => wp_create_nonce( 'nc_stmt_pdf_' . $id ) ),
                admin_url( 'admin.php' )
            ) );
            $csv_url = esc_url( add_query_arg(
                array( 'page' => 'nc-statements', 'view' => $id, 'export' => 'csv', '_wpnonce' => wp_create_nonce( 'nc_stmt_csv_' . $id ) ),
                admin_url( 'admin.php' )
            ) );
            $can_email = in_array( $row->status, array( 'finalized', 'sent', 'viewed', 'completed' ), true );
            ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <a class="button" href="<?php echo $pdf_url; ?>">Download PDF</a>
                <a class="button" href="<?php echo $csv_url; ?>">Download CSV</a>

                <?php if ( $can_email ) : ?>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_email_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="send_email">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <button class="button button-primary" onclick="return confirm('<?php echo $row->email_sent_count > 0 ? 'Resend statement email to vendor?' : 'Send statement email to vendor?'; ?>');">
                            <?php echo $row->email_sent_count > 0 ? 'Resend Email' : 'Send Email'; ?>
                        </button>
                    </form>
                <?php else : ?>
                    <span style="color:#888">Finalize this statement before sending email.</span>
                <?php endif; ?>
            </div>

            <?php if ( $row->email_sent_at ) : ?>
                <p style="color:#666;font-size:12px;margin-top:10px">
                    Last emailed: <?php echo esc_html( mysql2date( 'M j, Y H:i', $row->email_sent_at ) ); ?>
                    to <code><?php echo esc_html( $row->email_sent_to ); ?></code>
                    (sent <?php echo (int) $row->email_sent_count; ?> time<?php echo $row->email_sent_count == 1 ? '' : 's'; ?>).
                </p>
            <?php endif; ?>

            <!-- Status controls -->
            <hr style="margin-top:28px">
            <h2>Status Controls</h2>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php
                $nonce_url = function ( $action ) use ( $id ) {
                    return wp_nonce_url(
                        admin_url( 'admin.php?page=nc-statements&view=' . $id ),
                        'nc_stmt_status_' . $id
                    );
                };
                ?>
                <?php if ( $row->status === 'draft' ) : ?>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_status_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="set_status">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <input type="hidden" name="new_status" value="finalized">
                        <button class="button button-primary" onclick="return confirm('Finalize this statement? This locks the numbers and unlocks withdrawals for this vendor.');">Finalize</button>
                    </form>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_regen_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="regenerate">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <button class="button" onclick="return confirm('Regenerate from current ledger?');">Regenerate</button>
                    </form>
                <?php elseif ( $row->status === 'finalized' ) : ?>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_status_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="set_status">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <input type="hidden" name="new_status" value="sent">
                        <button class="button button-primary">Mark as Sent</button>
                    </form>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_status_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="set_status">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <input type="hidden" name="new_status" value="draft">
                        <button class="button" onclick="return confirm('Revert to Draft? Any finalization-dependent unlocks (withdrawals) will re-lock.');">Revert to Draft</button>
                    </form>
                <?php elseif ( in_array( $row->status, array( 'sent', 'viewed' ), true ) ) : ?>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_status_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="set_status">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <input type="hidden" name="new_status" value="completed">
                        <button class="button button-primary">Mark as Completed</button>
                    </form>
                <?php endif; ?>
            </div>
            <p style="color:#888;font-size:12px;margin-top:12px">
                Flow: Draft → Finalized → Sent → Viewed by vendor → Completed.
                Only Draft statements can be regenerated.
                Finalizing this statement unlocks withdrawal requests for this vendor (for the relevant period).
            </p>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * 7. Admin: POST handlers (generate / regenerate / status)
 * ----------------------------------------------------------------------- */

function nc_admin_statements_handle_post() {
    $action = sanitize_key( wp_unslash( $_POST['nc_stmt_action'] ) );

    if ( 'generate_batch' === $action ) {
        check_admin_referer( 'nc_stmt_generate' );
        $month  = isset( $_POST['gen_month'] ) ? sanitize_text_field( wp_unslash( $_POST['gen_month'] ) ) : '';
        $vendor = isset( $_POST['gen_vendor_id'] ) ? (int) $_POST['gen_vendor_id'] : 0;
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            nc_stmt_redirect_back( 'err', 'Invalid month.' );
        }
        if ( $vendor > 0 ) {
            $r = nc_statement_generate( $vendor, $month, get_current_user_id() );
            nc_stmt_redirect_back( $r['ok'] ? 'msg' : 'err', $r['message'] );
        } else {
            $r = nc_statement_generate_for_all( $month, get_current_user_id() );
            nc_stmt_redirect_back( 'msg', sprintf(
                'Batch complete: %d generated/updated, %d skipped (non-draft or no activity) out of %d vendors.',
                $r['generated'], $r['skipped'], $r['total']
            ) );
        }
    }

    if ( 'set_status' === $action ) {
        $id = isset( $_POST['statement_id'] ) ? (int) $_POST['statement_id'] : 0;
        check_admin_referer( 'nc_stmt_status_' . $id );
        $new = sanitize_key( wp_unslash( $_POST['new_status'] ) );
        nc_statement_set_status( $id, $new, get_current_user_id() );
    }

    if ( 'regenerate' === $action ) {
        $id = isset( $_POST['statement_id'] ) ? (int) $_POST['statement_id'] : 0;
        check_admin_referer( 'nc_stmt_regen_' . $id );
        global $wpdb;
        $table = nc_statements_table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT vendor_id, statement_month FROM {$table} WHERE id = %d", $id ) );
        if ( $row ) {
            $r = nc_statement_generate( (int) $row->vendor_id, $row->statement_month, get_current_user_id() );
            nc_stmt_redirect_back( $r['ok'] ? 'msg' : 'err', $r['message'], $id );
        } else {
            nc_stmt_redirect_back( 'err', 'Statement not found.' );
        }
    }

    if ( 'send_email' === $action ) {
        $id = isset( $_POST['statement_id'] ) ? (int) $_POST['statement_id'] : 0;
        check_admin_referer( 'nc_stmt_email_' . $id );
        global $wpdb;
        $table = nc_statements_table();
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
             FROM {$table} s LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
             WHERE s.id = %d", $id
        ) );
        if ( ! $row ) {
            nc_stmt_redirect_back( 'err', 'Statement not found.' );
        }
        if ( ! in_array( $row->status, array( 'finalized', 'sent', 'viewed', 'completed' ), true ) ) {
            nc_stmt_redirect_back( 'err', 'Finalize the statement before sending.', $id );
        }
        $r = nc_statement_send_email( $row );
        nc_stmt_redirect_back( $r['ok'] ? 'msg' : 'err', $r['message'], $id );
    }
}

function nc_statement_set_status( $id, $new_status, $admin_id ) {
    global $wpdb;
    $table = nc_statements_table();
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    if ( ! $row ) {
        nc_stmt_redirect_back( 'err', 'Statement not found.' );
    }

    $allowed_transitions = array(
        'draft'     => array( 'finalized' ),
        'finalized' => array( 'sent', 'draft' ),
        'sent'      => array( 'completed', 'viewed' ),
        'viewed'    => array( 'completed' ),
        'completed' => array(),
    );

    if ( ! isset( $allowed_transitions[ $row->status ] ) || ! in_array( $new_status, $allowed_transitions[ $row->status ], true ) ) {
        nc_stmt_redirect_back( 'err', sprintf( 'Cannot move from %s to %s.', $row->status, $new_status ), $id );
    }

    $update = array( 'status' => $new_status );
    if ( 'finalized' === $new_status ) { $update['finalized_at'] = current_time( 'mysql' ); $update['finalized_by'] = $admin_id; }
    if ( 'sent' === $new_status )      { $update['sent_at']      = current_time( 'mysql' ); $update['sent_by']      = $admin_id; }
    if ( 'completed' === $new_status ) { $update['completed_at'] = current_time( 'mysql' ); $update['completed_by'] = $admin_id; }
    if ( 'draft' === $new_status )     { $update['finalized_at'] = null; $update['finalized_by'] = null; $update['sent_at'] = null; $update['sent_by'] = null; }

    $wpdb->update( $table, $update, array( 'id' => $id ) );
    nc_stmt_redirect_back( 'msg', 'Status updated to ' . $new_status . '.', $id );
}

function nc_stmt_redirect_back( $type, $message, $view_id = 0 ) {
    $args = array( 'page' => 'nc-statements' );
    if ( $view_id > 0 ) { $args['view'] = $view_id; }
    $args[ 'nc_admin_' . ( $type === 'err' ? 'err' : 'msg' ) ] = rawurlencode( $message );
    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}

/* -------------------------------------------------------------------------
 * 8. CSV export
 * ----------------------------------------------------------------------- */

function nc_statement_export_csv( $row ) {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

    $detail  = $row->detail_data ? json_decode( $row->detail_data, true ) : array();
    $entries = isset( $detail['entries'] ) ? $detail['entries'] : array();
    $expired = isset( $detail['expired_entries'] ) ? $detail['expired_entries'] : array();

    while ( ob_get_level() ) { ob_end_clean(); }
    nocache_headers();
    $filename = sprintf( 'nc-statement-%d-%s.csv', (int) $row->vendor_id, $row->statement_month );
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $out = fopen( 'php://output', 'w' );
    fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    fputcsv( $out, array( 'Nation Club Monthly Statement' ) );
    fputcsv( $out, array( 'Vendor', $row->vendor_name ) );
    fputcsv( $out, array( 'Vendor ID', $row->vendor_id ) );
    fputcsv( $out, array( 'Statement Month', $row->statement_month ) );
    fputcsv( $out, array( 'Status', $row->status ) );
    fputcsv( $out, array( 'Generated', $row->generated_at ) );
    fputcsv( $out, array() );

    fputcsv( $out, array( 'Summary' ) );
    fputcsv( $out, array( 'Opening balance', $row->opening_balance ) );
    fputcsv( $out, array( 'Points accepted from customers', $row->points_accepted ) );
    fputcsv( $out, array( 'Points issued (earn liability)', '-' . $row->points_earn_liability ) );
    fputcsv( $out, array( 'Points redeemed (redeem liability)', '-' . $row->points_redeem_liability ) );
    fputcsv( $out, array( 'Vendor top-ups', $row->points_topup ) );
    fputcsv( $out, array( 'Vendor withdrawals', '-' . $row->points_withdrawal ) );
    fputcsv( $out, array( 'Expired points (informational)', $row->points_expired ) );
    fputcsv( $out, array( 'Shared costs', $row->shared_costs ) );
    fputcsv( $out, array( 'Closing balance', $row->closing_balance ) );
    fputcsv( $out, array( 'Top-up required', $row->topup_required ) );
    fputcsv( $out, array( 'Surplus', $row->surplus ) );
    fputcsv( $out, array() );

    fputcsv( $out, array( 'Detail' ) );
    fputcsv( $out, array( 'Date', 'Reference', 'Transaction ID', 'Customer ID', 'Service ID', 'Points', 'Entry' ) );
    foreach ( $entries as $e ) {
        $data = is_string( $e['data'] ?? '' ) ? json_decode( $e['data'], true ) : ( $e['data'] ?? array() );
        $txn  = $data['transaction_id'] ?? ( $data['topup_id'] ?? ( $data['withdrawal_id'] ?? '' ) );
        fputcsv( $out, array(
            wp_date( 'Y-m-d H:i:s', (int) ( $e['time'] ?? 0 ) ),
            $e['ref'] ?? '',
            $txn,
            $data['customer_id'] ?? '',
            $data['service_id'] ?? '',
            $e['creds'] ?? 0,
            wp_strip_all_tags( html_entity_decode( $e['entry'] ?? '', ENT_QUOTES, 'UTF-8' ) ),
        ) );
    }
    foreach ( $expired as $e ) {
        fputcsv( $out, array(
            wp_date( 'Y-m-d H:i:s', (int) ( $e['time'] ?? 0 ) ),
            'points_expiry',
            '',
            $e['user_id'] ?? '',
            '',
            $e['creds'] ?? 0,
            'Expired (informational)',
        ) );
    }

    fclose( $out );
    exit;
}

/* -------------------------------------------------------------------------
 * 9. Small lookup helpers
 * ----------------------------------------------------------------------- */

function nc_statement_customer_name( $amelia_id ) {
    static $cache = array();
    if ( ! $amelia_id ) { return '—'; }
    if ( isset( $cache[ $amelia_id ] ) ) { return $cache[ $amelia_id ]; }
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT externalId, firstName, lastName FROM {$wpdb->prefix}amelia_users WHERE id = %d",
        (int) $amelia_id
    ) );
    if ( ! $row ) { return $cache[ $amelia_id ] = 'Customer #' . $amelia_id; }
    if ( $row->externalId ) {
        $u = get_user_by( 'ID', (int) $row->externalId );
        if ( $u ) { return $cache[ $amelia_id ] = $u->user_login; }
    }
    return $cache[ $amelia_id ] = trim( $row->firstName . ' ' . $row->lastName );
}

function nc_statement_customer_name_by_user( $wp_user_id ) {
    $u = get_user_by( 'ID', (int) $wp_user_id );
    return $u ? $u->user_login : ( 'User #' . $wp_user_id );
}

function nc_statement_service_name( $service_id ) {
    static $cache = array();
    if ( ! $service_id ) { return '—'; }
    if ( isset( $cache[ $service_id ] ) ) { return $cache[ $service_id ]; }
    global $wpdb;
    $name = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}amelia_services WHERE id = %d",
        (int) $service_id
    ) );
    return $cache[ $service_id ] = ( $name ?: ( 'Service #' . $service_id ) );
}

/* -------------------------------------------------------------------------
 * 10. PDF generation (dompdf)
 * ----------------------------------------------------------------------- */

/**
 * Build printable HTML for a statement row. Used by both the PDF renderer
 * and the vendor portal preview.
 */
function nc_statement_build_pdf_html( $row ) {
    $detail  = $row->detail_data ? json_decode( $row->detail_data, true ) : array();
    $entries = isset( $detail['entries'] ) ? $detail['entries'] : array();
    $expired = isset( $detail['expired_entries'] ) ? $detail['expired_entries'] : array();

    $vendor_name = $row->vendor_name ?: ( 'Vendor #' . $row->vendor_id );
    $gen_date    = $row->generated_at ? mysql2date( 'M j, Y', $row->generated_at ) : '';
    $site        = get_bloginfo( 'name' );

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Statement <?php echo esc_html( $row->statement_month ); ?> — <?php echo esc_html( $vendor_name ); ?></title>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; margin: 0; padding: 0; }
            .wrap { padding: 24px 28px; }
            h1 { font-size: 18px; color: #8b1c3b; margin: 0 0 4px; }
            h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
            .meta { margin: 8px 0 12px; }
            .meta td { padding: 3px 10px 3px 0; }
            table.summary, table.detail { width: 100%; border-collapse: collapse; }
            table.summary td, table.detail td, table.detail th {
                padding: 5px 8px; border: 1px solid #e1e1e1;
            }
            table.detail th { background: #f4f4f4; font-weight: bold; text-align: left; }
            .num { text-align: right; }
            .pos { color: #1a8d2e; }
            .neg { color: #c62828; }
            .muted { color: #888; font-style: italic; }
            .closing td { background: #f0f0f0; font-weight: bold; }
            .footer { margin-top: 24px; color: #666; font-size: 10px; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
    <div class="wrap">
        <h1><?php echo esc_html( $site ); ?> — Monthly Statement</h1>
        <table class="meta">
            <tr>
                <td><strong>Vendor:</strong> <?php echo esc_html( $vendor_name ); ?></td>
                <td><strong>Vendor ID:</strong> <?php echo esc_html( $row->vendor_id ); ?></td>
            </tr>
            <tr>
                <td><strong>Statement Month:</strong> <?php echo esc_html( $row->statement_month ); ?></td>
                <td><strong>Statement Date:</strong> <?php echo esc_html( $gen_date ); ?></td>
            </tr>
            <tr>
                <td colspan="2"><strong>Status:</strong> <?php echo esc_html( ucfirst( $row->status ) ); ?></td>
            </tr>
        </table>

        <h2>Summary</h2>
        <table class="summary">
            <tr><td>Opening Points Pool balance</td><td class="num"><?php echo esc_html( number_format( (float) $row->opening_balance, 2 ) ); ?></td></tr>
            <tr><td>Points accepted from customers (+)</td><td class="num pos">+<?php echo esc_html( number_format( (float) $row->points_accepted, 2 ) ); ?></td></tr>
            <tr><td>Points issued to customers — earn liability (−)</td><td class="num neg">−<?php echo esc_html( number_format( (float) $row->points_earn_liability, 2 ) ); ?></td></tr>
            <tr><td>Points redeemed from vendor liability (−)</td><td class="num neg">−<?php echo esc_html( number_format( (float) $row->points_redeem_liability, 2 ) ); ?></td></tr>
            <tr><td>Vendor top-ups (+)</td><td class="num pos">+<?php echo esc_html( number_format( (float) $row->points_topup, 2 ) ); ?></td></tr>
            <tr><td>Vendor withdrawals (−)</td><td class="num neg">−<?php echo esc_html( number_format( (float) $row->points_withdrawal, 2 ) ); ?></td></tr>
            <tr><td>Expired Points Adjustment (informational)</td><td class="num muted"><?php echo esc_html( number_format( (float) $row->points_expired, 2 ) ); ?></td></tr>
            <tr><td>Shared costs / subscription</td><td class="num"><?php echo esc_html( number_format( (float) $row->shared_costs, 2 ) ); ?></td></tr>
            <tr class="closing"><td>Closing balance</td><td class="num"><?php echo esc_html( number_format( (float) $row->closing_balance, 2 ) ); ?></td></tr>
            <?php if ( $row->topup_required > 0 ) : ?>
                <tr><td>Required reload to restore SGD 1,000</td><td class="num neg"><strong><?php echo esc_html( number_format( (float) $row->topup_required, 2 ) ); ?></strong></td></tr>
            <?php endif; ?>
            <?php if ( $row->surplus > 0 ) : ?>
                <tr><td>Surplus above SGD 1,000</td><td class="num pos"><strong><?php echo esc_html( number_format( (float) $row->surplus, 2 ) ); ?></strong></td></tr>
            <?php endif; ?>
        </table>

        <h2>Detail</h2>
        <?php if ( empty( $entries ) && empty( $expired ) ) : ?>
            <p class="muted">No ledger activity in this month.</p>
        <?php else : ?>
            <table class="detail">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Txn ID</th>
                        <th>Customer</th>
                        <th>Service</th>
                        <th class="num">Points</th>
                        <th>Entry</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $entries as $e ) :
                    $data  = is_string( $e['data'] ?? '' ) ? json_decode( $e['data'], true ) : ( $e['data'] ?? array() );
                    $creds = (float) ( $e['creds'] ?? 0 );
                    $class = $creds > 0 ? 'pos' : ( $creds < 0 ? 'neg' : '' );
                    $txn   = $data['transaction_id'] ?? ( $data['topup_id'] ?? ( $data['withdrawal_id'] ?? '—' ) );
                    $cust  = isset( $data['customer_id'] ) ? nc_statement_customer_name( (int) $data['customer_id'] ) : '—';
                    $svc   = isset( $data['service_id'] ) ? nc_statement_service_name( (int) $data['service_id'] ) : '—';
                    ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( 'M j, Y', (int) $e['time'] ) ); ?></td>
                        <td><?php echo esc_html( $e['ref'] ); ?></td>
                        <td><?php echo esc_html( $txn ); ?></td>
                        <td><?php echo esc_html( $cust ); ?></td>
                        <td><?php echo esc_html( $svc ); ?></td>
                        <td class="num <?php echo esc_attr( $class ); ?>"><?php echo ( $creds > 0 ? '+' : '' ) . esc_html( number_format( $creds, 2 ) ); ?></td>
                        <td><?php echo esc_html( wp_strip_all_tags( html_entity_decode( $e['entry'] ?? '', ENT_QUOTES, 'UTF-8' ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ( $expired as $e ) :
                    $cust = isset( $e['user_id'] ) ? nc_statement_customer_name_by_user( (int) $e['user_id'] ) : '—';
                    ?>
                    <tr class="muted">
                        <td><?php echo esc_html( wp_date( 'M j, Y', (int) $e['time'] ) ); ?></td>
                        <td>points_expiry</td>
                        <td>—</td>
                        <td><?php echo esc_html( $cust ); ?></td>
                        <td>—</td>
                        <td class="num"><?php echo esc_html( number_format( abs( (float) $e['creds'] ), 2 ) ); ?></td>
                        <td>Expired (informational only)</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="footer">
            Generated by <?php echo esc_html( $site ); ?> on <?php echo esc_html( mysql2date( 'M j, Y H:i', $row->generated_at ) ); ?>.<br>
            For disputes / clarification, please contact Nation Club admin.
        </div>
    </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Render a statement row to PDF binary via dompdf.
 * Returns binary string, or WP_Error on failure.
 */
function nc_statement_render_pdf( $row ) {
    if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
        return new WP_Error( 'no_dompdf', 'PDF library (dompdf) not available. Run composer install.' );
    }
    $html = nc_statement_build_pdf_html( $row );

    $options = new \Dompdf\Options();
    $options->set( 'isRemoteEnabled', false );
    $options->set( 'isHtml5ParserEnabled', true );
    $options->set( 'defaultFont', 'DejaVu Sans' );

    $dompdf = new \Dompdf\Dompdf( $options );
    $dompdf->loadHtml( $html, 'UTF-8' );
    $dompdf->setPaper( 'A4', 'portrait' );
    $dompdf->render();

    return $dompdf->output();
}

/**
 * Stream a PDF to the browser as a download.
 */
function nc_statement_download_pdf( $row ) {
    $pdf = nc_statement_render_pdf( $row );
    if ( is_wp_error( $pdf ) ) {
        wp_die( esc_html( $pdf->get_error_message() ) );
    }
    $filename = sprintf( 'nc-statement-%d-%s.pdf', (int) $row->vendor_id, $row->statement_month );

    while ( ob_get_level() ) { ob_end_clean(); }
    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename=' . $filename );
    header( 'Content-Length: ' . strlen( $pdf ) );
    echo $pdf;
    exit;
}

/**
 * Write PDF to a temp file and return path. Caller must unlink after use.
 */
function nc_statement_save_pdf_tmp( $row ) {
    $pdf = nc_statement_render_pdf( $row );
    if ( is_wp_error( $pdf ) ) { return $pdf; }
    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'nc-statements-tmp';
    if ( ! file_exists( $dir ) ) { wp_mkdir_p( $dir ); }
    $file = $dir . '/' . sprintf( 'nc-statement-%d-%s.pdf', (int) $row->vendor_id, $row->statement_month );
    file_put_contents( $file, $pdf );
    return $file;
}

/* -------------------------------------------------------------------------
 * 11. Email template + sender
 * ----------------------------------------------------------------------- */

define( 'NC_STATEMENT_EMAIL_OPTION', 'nc_statement_email_template' );

/**
 * Default email template fields. Used on first install and as fallback.
 */
function nc_statement_email_defaults() {
    return array(
        'subject' => 'Nation Club Monthly Statement — {month_label}',
        'body'    => "Dear {vendor_name},\n\nPlease find attached your Nation Club monthly statement for {month_label}.\n\n" .
                     "Summary:\n" .
                     "• Opening balance: {opening_balance}\n" .
                     "• Closing balance: {closing_balance}\n" .
                     "• Required reload: {topup_required}\n" .
                     "• Surplus: {surplus}\n\n" .
                     "If any reload is required, please transfer via Wise and submit a top-up request in the vendor portal.\n\n" .
                     "For any queries, contact Nation Club admin.\n\n" .
                     "Regards,\nNation Club",
    );
}

/**
 * Stored template, merged with defaults for any missing keys.
 */
function nc_statement_email_template() {
    $stored   = (array) get_option( NC_STATEMENT_EMAIL_OPTION, array() );
    $defaults = nc_statement_email_defaults();
    return array(
        'subject' => isset( $stored['subject'] ) && $stored['subject'] !== '' ? (string) $stored['subject'] : $defaults['subject'],
        'body'    => isset( $stored['body'] ) && $stored['body'] !== '' ? (string) $stored['body'] : $defaults['body'],
    );
}

/**
 * Token map for a statement row.
 */
function nc_statement_email_tokens( $row ) {
    $month_label = $row->statement_month
        ? wp_date( 'F Y', strtotime( $row->statement_month . '-01' ) )
        : $row->statement_month;
    return array(
        '{vendor_name}'       => $row->vendor_name ?: ( 'Vendor #' . $row->vendor_id ),
        '{vendor_id}'         => (string) $row->vendor_id,
        '{vendor_email}'      => $row->vendor_email ?: '',
        '{month}'             => $row->statement_month,
        '{month_label}'       => $month_label,
        '{opening_balance}'   => 'SGD ' . number_format( (float) $row->opening_balance, 2 ),
        '{closing_balance}'   => 'SGD ' . number_format( (float) $row->closing_balance, 2 ),
        '{topup_required}'    => 'SGD ' . number_format( (float) $row->topup_required, 2 ),
        '{surplus}'           => 'SGD ' . number_format( (float) $row->surplus, 2 ),
        '{site_name}'         => get_bloginfo( 'name' ),
    );
}

function nc_statement_apply_tokens( $text, $row ) {
    $tokens = nc_statement_email_tokens( $row );
    return strtr( (string) $text, $tokens );
}

/**
 * Send statement email to vendor with PDF attached.
 * Returns [ 'ok' => bool, 'message' => string ].
 */
function nc_statement_send_email( $row ) {
    if ( empty( $row->vendor_email ) ) {
        return array( 'ok' => false, 'message' => 'Vendor has no email on file.' );
    }

    $pdf_path = nc_statement_save_pdf_tmp( $row );
    if ( is_wp_error( $pdf_path ) ) {
        return array( 'ok' => false, 'message' => $pdf_path->get_error_message() );
    }

    $tpl     = nc_statement_email_template();
    $subject = nc_statement_apply_tokens( $tpl['subject'], $row );
    $body    = nc_statement_apply_tokens( $tpl['body'], $row );

    // Template comes from wp_editor (WYSIWYG) so treat as HTML. If the admin
    // pasted plain text, wpautop converts newlines to paragraphs.
    $body = wpautop( $body );

    // Force HTML content type reliably — the headers array is sometimes
    // overridden by WP Mail SMTP or phpmailer defaults.
    $force_html = function () { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $force_html );

    $sent = wp_mail( $row->vendor_email, $subject, $body, array(), array( $pdf_path ) );

    remove_filter( 'wp_mail_content_type', $force_html );

    // Best-effort cleanup
    @unlink( $pdf_path );

    if ( ! $sent ) {
        return array( 'ok' => false, 'message' => 'wp_mail() returned false. Check your mailer (WP Mail SMTP).' );
    }

    global $wpdb;
    $table = nc_statements_table();
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table}
         SET email_sent_at = %s, email_sent_to = %s, email_sent_count = email_sent_count + 1
         WHERE id = %d",
        current_time( 'mysql' ),
        $row->vendor_email,
        (int) $row->id
    ) );

    // Auto-advance status draft → finalized? No — admin action. But finalized → sent: yes, makes sense to auto-advance.
    if ( $row->status === 'finalized' ) {
        $wpdb->update(
            $table,
            array(
                'status'  => 'sent',
                'sent_at' => current_time( 'mysql' ),
                'sent_by' => get_current_user_id(),
            ),
            array( 'id' => (int) $row->id )
        );
    }

    return array( 'ok' => true, 'message' => 'Email sent to ' . $row->vendor_email );
}

/* -------------------------------------------------------------------------
 * 12. Email template settings admin page
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Email Template',
        'Email Template',
        'manage_options',
        'nc-statement-email',
        'nc_admin_statement_email_page',
        3
    );
}, 12 );

function nc_admin_statement_email_page() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

    if ( isset( $_POST['nc_stmt_email_save'] ) && check_admin_referer( 'nc_stmt_email_save' ) ) {
        $subject = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
        $body    = isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '';
        update_option( NC_STATEMENT_EMAIL_OPTION, array( 'subject' => $subject, 'body' => $body ) );
        echo '<div class="notice notice-success is-dismissible"><p>Template saved.</p></div>';
    }

    if ( isset( $_POST['nc_stmt_email_reset'] ) && check_admin_referer( 'nc_stmt_email_reset' ) ) {
        delete_option( NC_STATEMENT_EMAIL_OPTION );
        echo '<div class="notice notice-success is-dismissible"><p>Template reset to defaults.</p></div>';
    }

    $tpl = nc_statement_email_template();
    ?>
    <div class="wrap">
        <h1>Monthly Statement Email Template</h1>
        <p>This template is used when sending monthly statements to vendors. The PDF statement is attached automatically.</p>

        <form method="post" style="max-width:860px">
            <?php wp_nonce_field( 'nc_stmt_email_save' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="email_subject">Subject</label></th>
                    <td>
                        <input type="text" id="email_subject" name="email_subject" value="<?php echo esc_attr( $tpl['subject'] ); ?>" class="regular-text" style="width:100%">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="email_body">Body</label></th>
                    <td>
                        <?php
                        wp_editor(
                            $tpl['body'],
                            'email_body',
                            array(
                                'textarea_name' => 'email_body',
                                'textarea_rows' => 14,
                                'media_buttons' => false,
                                'teeny'         => true,
                            )
                        );
                        ?>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="nc_stmt_email_save" class="button button-primary">Save Template</button>
            </p>
        </form>

        <form method="post" style="margin-top:8px">
            <?php wp_nonce_field( 'nc_stmt_email_reset' ); ?>
            <button type="submit" name="nc_stmt_email_reset" class="button" onclick="return confirm('Reset template to defaults?');">Reset to Defaults</button>
        </form>

        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:14px;margin-top:24px;max-width:860px">
            <h2 style="margin-top:0">Available Tokens</h2>
            <p>Use these in the subject or body — they will be replaced per vendor when the email is sent:</p>
            <table class="widefat striped">
                <thead><tr><th>Token</th><th>Replaced with</th></tr></thead>
                <tbody>
                    <tr><td><code>{vendor_name}</code></td><td>Vendor display name</td></tr>
                    <tr><td><code>{vendor_id}</code></td><td>Vendor user ID</td></tr>
                    <tr><td><code>{vendor_email}</code></td><td>Vendor email</td></tr>
                    <tr><td><code>{month}</code></td><td>e.g. 2026-04</td></tr>
                    <tr><td><code>{month_label}</code></td><td>e.g. April 2026</td></tr>
                    <tr><td><code>{opening_balance}</code></td><td>e.g. SGD 1,000.00</td></tr>
                    <tr><td><code>{closing_balance}</code></td><td>e.g. SGD 1,610.00</td></tr>
                    <tr><td><code>{topup_required}</code></td><td>e.g. SGD 0.00</td></tr>
                    <tr><td><code>{surplus}</code></td><td>e.g. SGD 610.00</td></tr>
                    <tr><td><code>{site_name}</code></td><td>Your WordPress site name</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * 13. Vendor portal shortcode — [nc_vendor_statements]
 * ----------------------------------------------------------------------- */

add_shortcode( 'nc_vendor_statements', 'nc_vendor_statements_shortcode' );

function nc_vendor_statements_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in to view your statements.</p>';
    }

    $user = wp_get_current_user();
    if ( ! in_array( 'wpamelia-provider', (array) $user->roles, true ) ) {
        return '<p>This page is for vendors only.</p>';
    }

    // PDF download handler for vendor side
    if ( isset( $_GET['nc_stmt_pdf'] ) ) {
        $sid = (int) $_GET['nc_stmt_pdf'];
        nc_vendor_stream_statement_pdf( $sid, (int) $user->ID );
        // above function exits on success
    }

    global $wpdb;
    $table = nc_statements_table();
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
         FROM {$table} s
         LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
         WHERE s.vendor_id = %d
           AND s.status IN ('finalized','sent','viewed','completed')
         ORDER BY s.statement_month DESC",
        (int) $user->ID
    ) );

    ob_start();
    ?>
    <div class="nc-box">
        <div class="nc-box__header">
            <h2 class="nc-box__title">My Monthly Statements</h2>
            <p class="nc-box__subtitle">Finalized statements issued by Nation Club.</p>
        </div>
        <div class="nc-box__body">
            <?php if ( empty( $rows ) ) : ?>
                <p class="nc-empty">No statements available yet.</p>
            <?php else : ?>
                <div class="nc-table-wrap">
                    <table class="nc-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Opening</th>
                                <th>Closing</th>
                                <th>Top-up required</th>
                                <th>Surplus</th>
                                <th>Status</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $rows as $row ) :
                            $label = wp_date( 'F Y', strtotime( $row->statement_month . '-01' ) );
                            $url   = esc_url( add_query_arg( array(
                                'nc_stmt_pdf' => $row->id,
                                '_wpnonce'    => wp_create_nonce( 'nc_vendor_stmt_' . $row->id ),
                            ) ) );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $label ); ?></strong></td>
                                <td><?php echo esc_html( number_format( (float) $row->opening_balance, 2 ) ); ?></td>
                                <td><strong><?php echo esc_html( number_format( (float) $row->closing_balance, 2 ) ); ?></strong></td>
                                <td><?php echo $row->topup_required > 0 ? esc_html( number_format( (float) $row->topup_required, 2 ) ) : '—'; ?></td>
                                <td><?php echo $row->surplus > 0 ? esc_html( number_format( (float) $row->surplus, 2 ) ) : '—'; ?></td>
                                <td><span class="nc-status nc-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
                                <td><a class="nc-btn nc-btn--primary" href="<?php echo $url; ?>">Download PDF</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Vendor-side PDF stream. Verifies ownership, marks the statement as
 * "viewed" on first download, then streams the PDF.
 */
function nc_vendor_stream_statement_pdf( $statement_id, $vendor_id ) {
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'nc_vendor_stmt_' . $statement_id ) ) {
        wp_die( 'Invalid request.' );
    }
    global $wpdb;
    $table = nc_statements_table();
    $row   = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
         FROM {$table} s LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
         WHERE s.id = %d AND s.vendor_id = %d",
        (int) $statement_id,
        (int) $vendor_id
    ) );
    if ( ! $row ) { wp_die( 'Statement not found.' ); }
    if ( ! in_array( $row->status, array( 'finalized', 'sent', 'viewed', 'completed' ), true ) ) {
        wp_die( 'Statement is not available for download.' );
    }

    // First-time-viewed → advance status
    if ( in_array( $row->status, array( 'finalized', 'sent' ), true ) ) {
        $wpdb->update(
            $table,
            array( 'status' => 'viewed', 'viewed_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $row->id )
        );
    } elseif ( ! $row->viewed_at ) {
        $wpdb->update( $table, array( 'viewed_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
    }

    nc_statement_download_pdf( $row );
}
