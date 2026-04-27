<?php
/**
 * Monthly Statement Module
 *
 * Admin-facing Nation Club → Monthly Statements page.
 *
 * What it does:
 * - Calculates a per-vendor, per-month statement from the myCRED log.
 * - Persists the snapshot in wp_nc_statements so numbers don't drift when logs change.
 * - Tracks a simplified status: draft → sent (displayed as "Finalized & Sent").
 *   One-click "Finalize & Send Email" combines locking the numbers with sending
 *   the PDF, per client Proposal 3 (2026-04-25).
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
 *
 * @param int    $vendor_id
 * @param string $month_str    'YYYY-MM'
 * @param float  $shared_costs Admin-entered shared cost / subscription amount
 *                             for the month. Subtracted from closing balance.
 */
function nc_statement_compute( $vendor_id, $month_str, $shared_costs = 0 ) {
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

    $shared_costs = round( max( 0, (float) $shared_costs ), 2 );
    $closing      = round( $opening + $accepted - $earn_liab - $redeem_liab + $topup - $withdrawal - $shared_costs, 2 );

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
        'shared_costs'            => $shared_costs,
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
 *
 * Shared costs handling:
 *   - If $shared_costs is null, the existing row's shared_costs is preserved
 *     (so admin's entry survives a Regenerate click).
 *   - If $shared_costs is a number, that value is used and persisted.
 *
 * Returns [ 'ok' => bool, 'message' => string, 'id' => int ].
 */
function nc_statement_generate( $vendor_id, $month_str, $admin_id, $shared_costs = null ) {
    global $wpdb;
    $table = nc_statements_table();

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, status, shared_costs FROM {$table} WHERE vendor_id = %d AND statement_month = %s",
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

    if ( $shared_costs === null ) {
        $shared_costs = $existing ? (float) $existing->shared_costs : 0;
    }

    $calc = nc_statement_compute( $vendor_id, $month_str, $shared_costs );

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
           AND status <> 'draft'",
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
 * 3b. Calendar-based monthly cycle
 *
 *   - Cron auto-generates Drafts for the previous month on the 1st.
 *   - Withdrawal submission window: 2nd–5th of each month (calendar days
 *     in WP timezone). Outside the window, withdrawals are blocked.
 *   - Top-ups are NOT date-restricted (vendor balance can dip mid-month
 *     and they should be able to recover it without waiting).
 * ----------------------------------------------------------------------- */

// Default window — used as fallback if admin settings are missing
define( 'NC_WITHDRAWAL_WINDOW_START_DAY', 2 );
define( 'NC_WITHDRAWAL_WINDOW_END_DAY', 5 );

/**
 * Returns the configured withdrawal window days.
 *
 * Admin can change these in Nation Club → Settings. Falls back to the
 * default 2nd–5th if no setting is saved or the values are invalid.
 *
 * @return array{start:int,end:int}
 */
function nc_get_withdrawal_window_days() {
    $opts  = (array) get_option( 'nc_withdrawal_window', array() );
    $start = isset( $opts['start'] ) ? (int) $opts['start'] : NC_WITHDRAWAL_WINDOW_START_DAY;
    $end   = isset( $opts['end'] )   ? (int) $opts['end']   : NC_WITHDRAWAL_WINDOW_END_DAY;
    $start = max( 1, min( 31, $start ) );
    $end   = max( 1, min( 31, $end ) );
    if ( $end < $start ) { $end = $start; }
    return array( 'start' => $start, 'end' => $end );
}

/**
 * Schedule the daily cron that runs auto-statement-generation. Idempotent.
 */
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'nc_statement_daily_cron' ) ) {
        // Schedule for next 00:30 in WP timezone
        $tz   = wp_timezone();
        $when = new DateTime( 'tomorrow 00:30', $tz );
        wp_schedule_event( $when->getTimestamp(), 'daily', 'nc_statement_daily_cron' );
    }
} );

add_action( 'nc_statement_daily_cron', 'nc_statement_daily_cron_handler' );

/**
 * Daily cron handler. Runs every day but only does work on the 1st —
 * generates Draft statements for the previous month for all vendors.
 *
 * Safe to run multiple times: nc_statement_generate skips non-draft rows
 * and overwrites existing drafts with the latest computed numbers.
 *
 * Detailed log: wp-content/uploads/nc-statement-cron.log
 */
function nc_statement_daily_cron_handler( $force = false ) {
    $today      = (int) wp_date( 'j' );
    $today_full = wp_date( 'Y-m-d (D)' );

    nc_statement_cron_log( '=== Daily check fired — ' . $today_full . ( $force ? ' [MANUAL TEST]' : '' ) . ' ===' );

    if ( ! $force && $today !== 1 ) {
        nc_statement_cron_log( "Day {$today} — not the 1st of the month. Skipping auto-generation." );
        return;
    }

    $prev_month = wp_date( 'Y-m', strtotime( '-1 day' ) );
    if ( $force ) {
        // For manual test runs, target last month based on today's month
        $prev_month = wp_date( 'Y-m', strtotime( 'first day of last month' ) );
        nc_statement_cron_log( "MANUAL TEST mode — targeting previous calendar month: {$prev_month}" );
    } else {
        nc_statement_cron_log( "Day 1 — running auto-generation for previous month: {$prev_month}" );
    }

    global $wpdb;
    $providers = $wpdb->get_results(
        "SELECT u.ID, u.user_login, u.user_email, u.display_name
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
         WHERE m.meta_key = '{$wpdb->prefix}capabilities'
           AND m.meta_value LIKE '%wpamelia-provider%'
         ORDER BY u.ID ASC"
    );

    $total = count( $providers );
    nc_statement_cron_log( "Found {$total} vendor(s) with wpamelia-provider role." );

    if ( $total === 0 ) {
        nc_statement_cron_log( 'No vendors found. Nothing to generate.' );
        return;
    }

    $generated = 0; $skipped = 0; $errored = 0;

    foreach ( $providers as $p ) {
        $tag = sprintf( 'Vendor #%d (%s <%s>)', $p->ID, $p->display_name ?: $p->user_login, $p->user_email );
        try {
            $result = nc_statement_generate( (int) $p->ID, $prev_month, 0 );
            if ( $result['ok'] ) {
                $generated++;
                nc_statement_cron_log( "  OK   — {$tag} → {$result['message']} (statement #{$result['id']})" );
            } else {
                $skipped++;
                nc_statement_cron_log( "  SKIP — {$tag} → {$result['message']}" );
            }
        } catch ( \Throwable $e ) {
            $errored++;
            nc_statement_cron_log( "  ERR  — {$tag} → " . $e->getMessage() );
        }
    }

    nc_statement_cron_log( sprintf(
        'Done. Total: %d, Generated/Updated: %d, Skipped (non-draft): %d, Errors: %d',
        $total, $generated, $skipped, $errored
    ) );
    nc_statement_cron_log( '=== End run ===' . PHP_EOL );
}

/**
 * Top-up reminder cron. Runs every day; only does work on day 6 and day 7.
 *
 * For each Amelia provider:
 *   - if their current pool balance is below the minimum (NC_VENDOR_POOL_MIN_BALANCE),
 *   - AND no top-up request that would cover the shortfall is already pending review,
 *   - send the `topup_reminder` email.
 *
 * The shortfall check uses pending top-ups so we don't pester vendors who have
 * already submitted proof and are just waiting for admin approval.
 *
 * Logs to wp-content/uploads/nc-statement-cron.log alongside the statement-gen
 * output so admin can audit both flows in one place.
 */
add_action( 'nc_statement_daily_cron', 'nc_statement_topup_reminder_handler' );

function nc_statement_topup_reminder_handler( $force = false ) {
    $today      = (int) wp_date( 'j' );
    $today_full = wp_date( 'Y-m-d (D)' );

    if ( ! $force && ! in_array( $today, array( 6, 7 ), true ) ) {
        // Daily check already logged by nc_statement_daily_cron_handler — stay quiet on
        // non-trigger days so the log isn't doubled.
        return;
    }

    nc_statement_cron_log( '--- Top-up reminder run — ' . $today_full . ( $force ? ' [MANUAL TEST]' : '' ) . ' ---' );

    if ( ! function_exists( 'mycred_get_users_balance' ) ) {
        nc_statement_cron_log( 'mycred_get_users_balance() not available — myCRED inactive? Aborting reminder run.' );
        return;
    }

    global $wpdb;
    $providers = $wpdb->get_results(
        "SELECT u.ID, u.user_login, u.user_email, u.display_name
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
         WHERE m.meta_key = '{$wpdb->prefix}capabilities'
           AND m.meta_value LIKE '%wpamelia-provider%'
         ORDER BY u.ID ASC"
    );

    $total = count( $providers );
    nc_statement_cron_log( "Found {$total} vendor(s) to check." );
    if ( $total === 0 ) {
        nc_statement_cron_log( '--- End reminder run ---' . PHP_EOL );
        return;
    }

    $minimum    = (float) NC_VENDOR_POOL_MIN_BALANCE;
    $topup_tbl  = $wpdb->prefix . 'nc_topup_requests';
    $reminded   = 0; $skipped_ok = 0; $skipped_pending = 0; $skipped_no_email = 0; $errored = 0;

    foreach ( $providers as $p ) {
        $tag = sprintf( 'Vendor #%d (%s)', $p->ID, $p->display_name ?: $p->user_login );

        try {
            $balance   = (float) mycred_get_users_balance( (int) $p->ID );
            $shortfall = $minimum - $balance;

            if ( $shortfall <= 0 ) {
                $skipped_ok++;
                nc_statement_cron_log( "  OK   — {$tag} → balance " . number_format( $balance, 2 ) . " ≥ minimum, no reminder needed" );
                continue;
            }

            if ( empty( $p->user_email ) ) {
                $skipped_no_email++;
                nc_statement_cron_log( "  SKIP — {$tag} → balance " . number_format( $balance, 2 ) . " (short " . number_format( $shortfall, 2 ) . ") but vendor has no email on file" );
                continue;
            }

            $pending_total = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$topup_tbl}
                 WHERE vendor_id = %d AND status = 'pending'",
                (int) $p->ID
            ) );

            if ( $pending_total >= $shortfall ) {
                $skipped_pending++;
                nc_statement_cron_log( "  WAIT — {$tag} → balance " . number_format( $balance, 2 ) . ", but " . number_format( $pending_total, 2 ) . " in pending top-ups already covers the shortfall" );
                continue;
            }

            $tokens = array(
                '{vendor_name}'     => $p->display_name ?: $p->user_login,
                '{current_balance}' => number_format( $balance, 2 ),
                '{minimum_balance}' => number_format( $minimum, 2 ),
                '{shortfall}'       => number_format( $shortfall, 2 ),
                '{site_name}'       => get_bloginfo( 'name' ),
            );

            $sent = nc_email_send( 'topup_reminder', $p->user_email, $tokens );
            if ( $sent ) {
                $reminded++;
                nc_statement_cron_log( "  SENT — {$tag} → reminder emailed to {$p->user_email} (balance " . number_format( $balance, 2 ) . ", short " . number_format( $shortfall, 2 ) . ")" );
            } else {
                $errored++;
                nc_statement_cron_log( "  ERR  — {$tag} → wp_mail() returned false (target {$p->user_email})" );
            }
        } catch ( \Throwable $e ) {
            $errored++;
            nc_statement_cron_log( "  ERR  — {$tag} → " . $e->getMessage() );
        }
    }

    nc_statement_cron_log( sprintf(
        'Reminder summary — Total: %d, Reminded: %d, OK (above min): %d, Waiting on pending: %d, No email: %d, Errors: %d',
        $total, $reminded, $skipped_ok, $skipped_pending, $skipped_no_email, $errored
    ) );
    nc_statement_cron_log( '--- End reminder run ---' . PHP_EOL );
}

/**
 * Withdrawal window guard. Blocks withdrawal submissions outside
 * the 2nd–5th calendar window.
 */
add_filter( 'nc_vendor_can_withdraw', 'nc_statement_enforce_window_period', 9, 3 );

function nc_statement_enforce_window_period( $result, $vendor_id, $amount ) {
    if ( ! isset( $result['ok'] ) || ! $result['ok'] ) {
        return $result;
    }
    $today  = (int) wp_date( 'j' );
    $window = nc_get_withdrawal_window_days();
    if ( $today < $window['start'] || $today > $window['end'] ) {
        $next_window = nc_statement_next_withdrawal_window();
        $result['ok']     = false;
        $result['reason'] = sprintf(
            'Withdrawals can only be submitted between day %d and day %d of each month. The next window opens on %s.',
            $window['start'],
            $window['end'],
            wp_date( 'M j, Y', $next_window['start_ts'] )
        );
    }
    return $result;
}

/**
 * Returns info about the current/next withdrawal window for the vendor portal.
 *
 * @return array{is_open:bool, start_ts:int, end_ts:int, label_short:string, label_long:string}
 */
function nc_statement_next_withdrawal_window() {
    $tz       = wp_timezone();
    $now      = new DateTime( 'now', $tz );
    $today    = (int) $now->format( 'j' );
    $window   = nc_get_withdrawal_window_days();
    $is_open  = ( $today >= $window['start'] && $today <= $window['end'] );

    if ( $is_open ) {
        $start = clone $now;
        $start->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), $window['start'] )->setTime( 0, 0, 0 );
        $end   = clone $now;
        $end->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), $window['end'] )->setTime( 23, 59, 59 );
    } else {
        // Next window = next month if past end day, this month if before start day
        $start = clone $now;
        if ( $today > $window['end'] ) {
            $start->modify( 'first day of next month' );
        }
        $start->setDate( (int) $start->format( 'Y' ), (int) $start->format( 'n' ), $window['start'] )->setTime( 0, 0, 0 );
        $end = clone $start;
        $end->setDate( (int) $start->format( 'Y' ), (int) $start->format( 'n' ), $window['end'] )->setTime( 23, 59, 59 );
    }

    return array(
        'is_open'     => $is_open,
        'start_ts'    => $start->getTimestamp(),
        'end_ts'      => $end->getTimestamp(),
        'label_short' => $start->format( 'M j' ) . ' – ' . $end->format( 'M j, Y' ),
        'label_long'  => $is_open
            ? sprintf( 'Open until %s', $end->format( 'M j, Y' ) )
            : sprintf( 'Next window: %s – %s', $start->format( 'M j' ), $end->format( 'M j, Y' ) ),
    );
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
    if ( $status_sel === 'draft' ) {
        $where[]  = 's.status = %s';
        $params[] = 'draft';
    } elseif ( $status_sel === 'sent' ) {
        $where[]  = "s.status <> 'draft'";
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

        <div style="background:#fff;padding:14px;border:1px solid #ccd0d4;margin-top:8px;border-radius:4px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <strong>Test crons:</strong>
            <form method="post" style="display:inline">
                <?php wp_nonce_field( 'nc_stmt_run_cron' ); ?>
                <input type="hidden" name="nc_stmt_action" value="run_cron_now">
                <button type="submit" class="button" onclick="return confirm('Force-run the daily statement-generation cron right now? Targets last calendar month.');">Run Statement Cron (Test)</button>
            </form>
            <form method="post" style="display:inline">
                <?php wp_nonce_field( 'nc_stmt_run_reminder' ); ?>
                <input type="hidden" name="nc_stmt_action" value="run_reminder_now">
                <button type="submit" class="button" onclick="return confirm('Force-run the top-up reminder cron right now? Vendors below SGD 1,000 will be emailed.');">Run Top-up Reminder (Test)</button>
            </form>
            <?php
            $log_url = '';
            if ( function_exists( 'wp_upload_dir' ) ) {
                $up = wp_upload_dir();
                if ( ! empty( $up['baseurl'] ) ) {
                    $log_url = trailingslashit( $up['baseurl'] ) . 'nc-statement-cron.log';
                }
            }
            ?>
            <span style="color:#666;margin-left:8px">
                Writes to <code>wp-content/uploads/nc-statement-cron.log</code>.
                <?php if ( $log_url ) : ?>
                    <a href="<?php echo esc_url( $log_url ); ?>" target="_blank" rel="noopener">View latest log</a>
                <?php endif; ?>
            </span>
        </div>

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
                    <option value="draft" <?php selected( $status_sel, 'draft' ); ?>>Draft</option>
                    <option value="sent" <?php selected( $status_sel, 'sent' ); ?>>Finalized &amp; Sent</option>
                </select>
            </label>
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search vendor…">
            <button class="button">Filter</button>
            <a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=nc-statements' ) ); ?>">Reset</a>
        </form>

        <!-- Bulk actions form (table checkboxes reference this via form="..." attribute) -->
        <form method="post" id="nc-stmt-bulk-form" style="margin-top:16px;background:#fff;padding:10px 14px;border:1px solid #ccd0d4;border-radius:4px">
            <?php wp_nonce_field( 'nc_stmt_bulk' ); ?>
            <input type="hidden" name="nc_stmt_action" value="bulk">
            <select name="bulk_action">
                <option value="">— Bulk action —</option>
                <option value="finalize_and_send">Finalize &amp; Send Email (drafts only)</option>
                <option value="resend_email">Resend Email (already finalized)</option>
                <option value="set_shared_costs">Set Shared Costs (drafts only)</option>
                <option value="delete">Delete permanently</option>
            </select>
            <input type="number" step="0.01" min="0" name="bulk_amount" placeholder="Amount (for shared costs)" style="width:170px">
            <button type="submit" class="button" onclick="return ncStmtBulkConfirm(this.form);">Apply to selected</button>
            <span style="color:#666;margin-left:8px"><span id="nc-stmt-selected-count">0</span> selected</span>
        </form>

        <table class="wp-list-table widefat fixed striped" style="margin-top:10px">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column"><input type="checkbox" id="nc-stmt-cb-all" form="nc-stmt-bulk-form"></td>
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
                <tr><td colspan="9">No statements. Use the form above to generate.</td></tr>
            <?php else : foreach ( $rows as $row ) :
                $view_url = esc_url( add_query_arg( array( 'page' => 'nc-statements', 'view' => $row->id ), admin_url( 'admin.php' ) ) );
                $pdf_url  = esc_url( add_query_arg( array( 'page' => 'nc-statements', 'view' => $row->id, 'export' => 'pdf', '_wpnonce' => wp_create_nonce( 'nc_stmt_pdf_' . $row->id ) ), admin_url( 'admin.php' ) ) );
                $is_draft = ( $row->status === 'draft' );
                ?>
                <tr>
                    <th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $row->id ); ?>" form="nc-stmt-bulk-form" class="nc-stmt-cb"></th>
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
                        <?php if ( $is_draft ) : ?>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'nc_stmt_finalize_send_' . $row->id ); ?>
                                <input type="hidden" name="nc_stmt_action" value="finalize_and_send">
                                <input type="hidden" name="statement_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <button type="submit" class="button button-primary button-small" onclick="return confirm('Finalize this statement and send it to the vendor by email?');">Finalize &amp; Send</button>
                            </form>
                        <?php else : ?>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'nc_stmt_email_' . $row->id ); ?>
                                <input type="hidden" name="nc_stmt_action" value="send_email">
                                <input type="hidden" name="statement_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('Resend statement email to vendor?');">Resend Email</button>
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
    <script>
    (function () {
        var all = document.getElementById('nc-stmt-cb-all');
        var rows = document.querySelectorAll('input.nc-stmt-cb');
        var counter = document.getElementById('nc-stmt-selected-count');
        function recount() { counter.textContent = document.querySelectorAll('input.nc-stmt-cb:checked').length; }
        if (all) {
            all.addEventListener('change', function () {
                rows.forEach(function (cb) { cb.checked = all.checked; });
                recount();
            });
        }
        rows.forEach(function (cb) { cb.addEventListener('change', recount); });
    })();
    function ncStmtBulkConfirm(form) {
        var action = form.bulk_action.value;
        var ids = document.querySelectorAll('input.nc-stmt-cb:checked');
        if (!action) { alert('Choose a bulk action.'); return false; }
        if (ids.length === 0) { alert('Select at least one row.'); return false; }
        if (action === 'set_shared_costs') {
            var amt = parseFloat(form.bulk_amount.value);
            if (isNaN(amt) || amt < 0) { alert('Enter a valid shared cost amount (0 or more).'); return false; }
        }
        var labels = { finalize_and_send: 'finalize & send email for', resend_email: 'resend email for', set_shared_costs: 'set shared cost on', delete: 'permanently delete' };
        return confirm('Are you sure you want to ' + (labels[action] || action) + ' ' + ids.length + ' statement(s)?');
    }
    </script>
    <?php
}

function nc_statement_status_badge( $status ) {
    if ( $status === 'draft' ) {
        $style = '#e0e0e0;color:#444';
        $label = 'Draft';
    } else {
        $style = '#d4edda;color:#155724';
        $label = 'Finalized & Sent';
    }
    return sprintf(
        '<span style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s">%s</span>',
        $style,
        esc_html( $label )
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

    // PDF export handler (inside view page so we can reuse the loaded row)
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
                <tr>
                    <td>Shared costs / subscription <em>(−)</em></td>
                    <td style="text-align:right">
                        <?php if ( $row->status === 'draft' ) : ?>
                            <form method="post" style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap">
                                <?php wp_nonce_field( 'nc_stmt_shared_' . $id ); ?>
                                <input type="hidden" name="nc_stmt_action" value="update_shared_costs">
                                <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                                <input type="number" name="shared_costs" step="0.01" min="0" value="<?php echo esc_attr( number_format( (float) $row->shared_costs, 2, '.', '' ) ); ?>" style="width:90px;text-align:right">
                                <button type="submit" class="button button-small" title="Save shared cost and regenerate the statement from the latest ledger">Update &amp; Regenerate</button>
                            </form>
                        <?php else : ?>
                            <?php echo esc_html( number_format( (float) $row->shared_costs, 2 ) ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
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

            <!-- Actions -->
            <hr style="margin-top:28px">
            <h2>Actions</h2>
            <?php
            $pdf_url = esc_url( add_query_arg(
                array( 'page' => 'nc-statements', 'view' => $id, 'export' => 'pdf', '_wpnonce' => wp_create_nonce( 'nc_stmt_pdf_' . $id ) ),
                admin_url( 'admin.php' )
            ) );
            ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <a class="button" href="<?php echo $pdf_url; ?>">Download PDF</a>

                <?php if ( $row->status === 'draft' ) : ?>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_finalize_send_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="finalize_and_send">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <button class="button button-primary" onclick="return confirm('Finalize this statement and send it to the vendor by email? Once finalized the numbers are locked.');">Finalize &amp; Send Email</button>
                    </form>
                <?php else : ?>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_email_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="send_email">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <button class="button button-primary" onclick="return confirm('Resend statement email to vendor?');">Resend Email</button>
                    </form>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'nc_stmt_status_' . $id ); ?>
                        <input type="hidden" name="nc_stmt_action" value="set_status">
                        <input type="hidden" name="statement_id" value="<?php echo esc_attr( $id ); ?>">
                        <input type="hidden" name="new_status" value="draft">
                        <button class="button" onclick="return confirm('Revert this statement to Draft? Withdrawal lock for this vendor will re-engage until it is finalized again.');">Revert to Draft</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( $row->email_sent_at ) : ?>
                <p style="color:#666;font-size:12px;margin-top:10px">
                    Last emailed: <?php echo esc_html( mysql2date( 'M j, Y H:i', $row->email_sent_at ) ); ?>
                    to <code><?php echo esc_html( $row->email_sent_to ); ?></code>
                    (sent <?php echo (int) $row->email_sent_count; ?> time<?php echo $row->email_sent_count == 1 ? '' : 's'; ?>).
                </p>
            <?php endif; ?>

            <p style="color:#888;font-size:12px;margin-top:12px">
                Flow: Draft → Finalized &amp; Sent.
                On Drafts, edit Shared Costs above and click <em>Update &amp; Regenerate</em> to refresh from the latest ledger.
                Once finalized the numbers are locked and withdrawals unlock for this vendor.
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

    if ( 'bulk' === $action ) {
        nc_admin_statements_handle_bulk();
        return;
    }

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
        if ( $row->status === 'draft' ) {
            nc_stmt_redirect_back( 'err', 'Use "Finalize & Send Email" for draft statements.', $id );
        }
        $r = nc_statement_send_email( $row );
        nc_stmt_redirect_back( $r['ok'] ? 'msg' : 'err', $r['message'], $id );
    }

    if ( 'finalize_and_send' === $action ) {
        $id = isset( $_POST['statement_id'] ) ? (int) $_POST['statement_id'] : 0;
        check_admin_referer( 'nc_stmt_finalize_send_' . $id );
        $r = nc_statement_finalize_and_send( $id, get_current_user_id() );
        nc_stmt_redirect_back( $r['ok'] ? 'msg' : 'err', $r['message'], $id );
    }

    if ( 'run_cron_now' === $action ) {
        check_admin_referer( 'nc_stmt_run_cron' );
        nc_statement_daily_cron_handler( true );
        nc_stmt_redirect_back( 'msg', 'Statement-gen cron run forced. Check nc-statement-cron.log for the per-vendor result.' );
    }

    if ( 'run_reminder_now' === $action ) {
        check_admin_referer( 'nc_stmt_run_reminder' );
        nc_statement_topup_reminder_handler( true );
        nc_stmt_redirect_back( 'msg', 'Top-up reminder cron run forced. Check nc-statement-cron.log for the per-vendor result.' );
    }

    if ( 'update_shared_costs' === $action ) {
        $id = isset( $_POST['statement_id'] ) ? (int) $_POST['statement_id'] : 0;
        check_admin_referer( 'nc_stmt_shared_' . $id );
        $shared = isset( $_POST['shared_costs'] ) ? max( 0, (float) $_POST['shared_costs'] ) : 0;

        global $wpdb;
        $table = nc_statements_table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT vendor_id, statement_month, status FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) {
            nc_stmt_redirect_back( 'err', 'Statement not found.' );
        }
        if ( $row->status !== 'draft' ) {
            nc_stmt_redirect_back( 'err', 'Shared costs can only be edited on a Draft statement.', $id );
        }

        $r = nc_statement_generate( (int) $row->vendor_id, $row->statement_month, get_current_user_id(), $shared );
        nc_stmt_redirect_back(
            $r['ok'] ? 'msg' : 'err',
            $r['ok'] ? sprintf( 'Shared cost updated to %s. Closing balance recomputed.', number_format( $shared, 2 ) ) : $r['message'],
            $id
        );
    }
}

/**
 * Bulk action handler for Monthly Statements.
 * Supports: finalize_and_send, resend_email, set_shared_costs, delete.
 */
function nc_admin_statements_handle_bulk() {
    check_admin_referer( 'nc_stmt_bulk' );

    $bulk   = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
    $ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
    $amount = isset( $_POST['bulk_amount'] ) ? max( 0, (float) $_POST['bulk_amount'] ) : 0;
    $ids    = array_values( array_filter( array_unique( $ids ) ) );

    $allowed = array( 'finalize_and_send', 'resend_email', 'set_shared_costs', 'delete' );
    if ( ! in_array( $bulk, $allowed, true ) || empty( $ids ) ) {
        nc_stmt_redirect_back( 'err', 'No action or no rows selected.' );
    }

    global $wpdb;
    $table    = nc_statements_table();
    $admin_id = get_current_user_id();
    $done     = 0;
    $skipped  = 0;
    $errored  = 0;

    foreach ( $ids as $id ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
             FROM {$table} s LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
             WHERE s.id = %d", $id
        ) );
        if ( ! $row ) { $skipped++; continue; }

        if ( $bulk === 'delete' ) {
            $wpdb->delete( $table, array( 'id' => $row->id ) );
            $done++;
            continue;
        }

        if ( $bulk === 'set_shared_costs' ) {
            if ( $row->status !== 'draft' ) { $skipped++; continue; }
            $r = nc_statement_generate( (int) $row->vendor_id, $row->statement_month, $admin_id, $amount );
            if ( $r['ok'] ) { $done++; } else { $errored++; }
            continue;
        }

        if ( $bulk === 'finalize_and_send' ) {
            if ( $row->status !== 'draft' ) { $skipped++; continue; }
            $r = nc_statement_finalize_and_send( (int) $row->id, $admin_id );
            if ( $r['ok'] ) { $done++; } else { $errored++; }
            continue;
        }

        if ( $bulk === 'resend_email' ) {
            if ( $row->status === 'draft' ) { $skipped++; continue; }
            $r = nc_statement_send_email( $row );
            if ( $r['ok'] ) { $done++; } else { $errored++; }
            continue;
        }
    }

    $msg = sprintf( 'Bulk %s: %d done, %d skipped (status mismatch), %d error(s).', $bulk, $done, $skipped, $errored );
    nc_stmt_redirect_back( 'msg', $msg );
}

function nc_statement_set_status( $id, $new_status, $admin_id ) {
    global $wpdb;
    $table = nc_statements_table();
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    if ( ! $row ) {
        nc_stmt_redirect_back( 'err', 'Statement not found.' );
    }

    // Simplified flow per Proposal 3 — only revert (sent → draft) is exposed; the
    // forward transition (draft → sent) goes through nc_statement_finalize_and_send.
    $can_revert = ( $row->status !== 'draft' && $new_status === 'draft' );
    if ( ! $can_revert ) {
        nc_stmt_redirect_back( 'err', sprintf( 'Cannot move from %s to %s.', $row->status, $new_status ), $id );
    }

    $update = array(
        'status'       => 'draft',
        'finalized_at' => null,
        'finalized_by' => null,
        'sent_at'      => null,
        'sent_by'      => null,
    );

    $wpdb->update( $table, $update, array( 'id' => $id ) );
    nc_stmt_redirect_back( 'msg', 'Statement reverted to Draft.', $id );
}

function nc_stmt_redirect_back( $type, $message, $view_id = 0 ) {
    $args = array( 'page' => 'nc-statements' );
    if ( $view_id > 0 ) { $args['view'] = $view_id; }
    $args[ 'nc_admin_' . ( $type === 'err' ? 'err' : 'msg' ) ] = rawurlencode( $message );
    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}

/* -------------------------------------------------------------------------
 * 8. Small lookup helpers
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
 * 9. PDF generation (dompdf)
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
                <td colspan="2"><strong>Status:</strong> <?php echo esc_html( $row->status === 'draft' ? 'Draft' : 'Finalized & Sent' ); ?></td>
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
            <tr><td>Shared costs / subscription (−)</td><td class="num <?php echo $row->shared_costs > 0 ? 'neg' : ''; ?>"><?php echo $row->shared_costs > 0 ? '−' : ''; ?><?php echo esc_html( number_format( (float) $row->shared_costs, 2 ) ); ?></td></tr>
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
 * 10. Email templates + generic sender
 *
 * Registry holds metadata for all customizable emails:
 *   - statement                — monthly statement (vendor)
 *   - topup_submitted          — admin alert
 *   - topup_approved           — vendor confirmation
 *   - topup_rejected           — vendor rejection
 *   - withdrawal_submitted     — admin alert
 *   - withdrawal_decided       — vendor approve/reject (combined, uses {status})
 *   - withdrawal_paid          — vendor payment confirmation
 *   - topup_reminder           — vendor reminder when balance < min on day 6/7
 *   - vendor_low_balance       — event-based alert when vendor balance crosses below configured threshold
 *
 * Templates are stored in a single option `nc_email_templates`.
 * The legacy `nc_statement_email_template` option is auto-migrated.
 * ----------------------------------------------------------------------- */

define( 'NC_STATEMENT_EMAIL_OPTION', 'nc_statement_email_template' );    // legacy
define( 'NC_EMAIL_TEMPLATES_OPTION', 'nc_email_templates' );             // new

/**
 * Registry of all email templates with labels, audience, default subject/body,
 * and token list. Single source of truth for the admin UI and the senders.
 */
function nc_email_template_registry() {
    return array(
        'statement' => array(
            'label'    => 'Statement Email',
            'audience' => 'vendor',
            'tokens'   => array(
                '{vendor_name}'       => 'Vendor display name',
                '{vendor_id}'         => 'Vendor user ID',
                '{vendor_email}'      => 'Vendor email',
                '{month}'             => 'YYYY-MM',
                '{month_label}'       => 'e.g. April 2026',
                '{opening_balance}'   => 'SGD x,xxx.xx',
                '{closing_balance}'   => 'SGD x,xxx.xx',
                '{topup_required}'    => 'SGD x,xxx.xx',
                '{surplus}'           => 'SGD x,xxx.xx',
                '{site_name}'         => 'WordPress site name',
            ),
            'defaults' => array(
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
            ),
        ),
        'topup_submitted' => array(
            'label'    => 'Top-up — Submitted (admin)',
            'audience' => 'admin',
            'tokens'   => array(
                '{vendor_name}'    => 'Vendor display name',
                '{vendor_email}'   => 'Vendor email',
                '{amount}'         => 'SGD x,xxx.xx',
                '{transfer_date}'  => 'YYYY-MM-DD',
                '{wise_reference}' => 'Wise reference (if vendor provided)',
                '{request_id}'     => 'Internal request ID',
                '{site_name}'      => 'WordPress site name',
                '{admin_url}'      => 'Admin link to top-up requests page',
            ),
            'defaults' => array(
                'subject' => '[Nation Club] New top-up request — {vendor_name}, SGD {amount}',
                'body'    => "A new top-up request has been submitted.\n\n" .
                             "Vendor: {vendor_name} <{vendor_email}>\n" .
                             "Amount: SGD {amount}\n" .
                             "Transfer Date: {transfer_date}\n" .
                             "Wise Reference: {wise_reference}\n\n" .
                             "Review and approve here: {admin_url}",
            ),
        ),
        'topup_approved' => array(
            'label'    => 'Top-up — Approved (vendor)',
            'audience' => 'vendor',
            'tokens'   => array(
                '{vendor_name}'      => 'Vendor display name',
                '{amount}'           => 'SGD x,xxx.xx',
                '{topup_id}'         => 'TU-xxxxx reference',
                '{current_balance}'  => 'Vendor balance after credit',
                '{admin_note}'       => 'Admin note (may be empty)',
                '{site_name}'        => 'WordPress site name',
            ),
            'defaults' => array(
                'subject' => 'Your top-up has been approved — {topup_id}',
                'body'    => "Dear {vendor_name},\n\n" .
                             "Your top-up of SGD {amount} has been approved and credited to your Nation Club pool.\n\n" .
                             "Reference: {topup_id}\n" .
                             "Current balance: SGD {current_balance}\n\n" .
                             "{admin_note}\n\n" .
                             "Regards,\nNation Club",
            ),
        ),
        'topup_rejected' => array(
            'label'    => 'Top-up — Rejected (vendor)',
            'audience' => 'vendor',
            'tokens'   => array(
                '{vendor_name}'  => 'Vendor display name',
                '{amount}'       => 'SGD x,xxx.xx',
                '{admin_note}'   => 'Reason given by admin',
                '{site_name}'    => 'WordPress site name',
            ),
            'defaults' => array(
                'subject' => 'Your top-up request was not approved',
                'body'    => "Dear {vendor_name},\n\n" .
                             "Your top-up request for SGD {amount} could not be approved.\n\n" .
                             "Reason: {admin_note}\n\n" .
                             "Please contact Nation Club admin to resolve.\n\n" .
                             "Regards,\nNation Club",
            ),
        ),
        'withdrawal_submitted' => array(
            'label'    => 'Withdrawal — Submitted (admin)',
            'audience' => 'admin',
            'tokens'   => array(
                '{vendor_name}'  => 'Vendor display name',
                '{vendor_email}' => 'Vendor email',
                '{amount}'       => 'SGD x,xxx.xx',
                '{vendor_note}'  => 'Vendor note (may be empty)',
                '{request_id}'   => 'Internal request ID',
                '{site_name}'    => 'WordPress site name',
                '{admin_url}'    => 'Admin link to withdrawal requests page',
            ),
            'defaults' => array(
                'subject' => '[Nation Club] New withdrawal request — {vendor_name}, SGD {amount}',
                'body'    => "A new withdrawal request has been submitted.\n\n" .
                             "Vendor: {vendor_name} <{vendor_email}>\n" .
                             "Amount: SGD {amount}\n" .
                             "Vendor's note: {vendor_note}\n\n" .
                             "Review here: {admin_url}",
            ),
        ),
        'withdrawal_decided' => array(
            'label'    => 'Withdrawal — Approved/Rejected (vendor)',
            'audience' => 'vendor',
            'tokens'   => array(
                '{vendor_name}' => 'Vendor display name',
                '{amount}'      => 'SGD x,xxx.xx',
                '{status}'      => 'approved or rejected',
                '{admin_note}'  => 'Admin note (reason or follow-up info)',
                '{site_name}'   => 'WordPress site name',
            ),
            'defaults' => array(
                'subject' => 'Your withdrawal request has been {status}',
                'body'    => "Dear {vendor_name},\n\n" .
                             "Your withdrawal request for SGD {amount} has been {status}.\n\n" .
                             "{admin_note}\n\n" .
                             "If approved, your payout will be processed via Wise shortly. You'll receive a confirmation email once paid.\n\n" .
                             "Regards,\nNation Club",
            ),
        ),
        'withdrawal_paid' => array(
            'label'    => 'Withdrawal — Paid (vendor)',
            'audience' => 'vendor',
            'tokens'   => array(
                '{vendor_name}'      => 'Vendor display name',
                '{amount}'           => 'SGD x,xxx.xx',
                '{withdrawal_id}'    => 'WD-xxxxx reference',
                '{wise_reference}'   => 'Wise transaction reference',
                '{current_balance}'  => 'Vendor balance after debit',
                '{site_name}'        => 'WordPress site name',
            ),
            'defaults' => array(
                'subject' => 'Your withdrawal has been processed — {withdrawal_id}',
                'body'    => "Dear {vendor_name},\n\n" .
                             "Your withdrawal of SGD {amount} has been paid via Wise.\n\n" .
                             "Withdrawal Reference: {withdrawal_id}\n" .
                             "Wise Reference: {wise_reference}\n" .
                             "Current balance: SGD {current_balance}\n\n" .
                             "Regards,\nNation Club",
            ),
        ),
        'topup_reminder' => array(
            'label'    => 'Top-up Reminder (vendor)',
            'audience' => 'vendor',
            'tokens'   => array(
                '{vendor_name}'      => 'Vendor display name',
                '{current_balance}'  => 'Current vendor pool balance',
                '{minimum_balance}'  => 'Required minimum (SGD 1,000)',
                '{shortfall}'        => 'Amount needed to restore the minimum',
                '{site_name}'        => 'WordPress site name',
            ),
            'defaults' => array(
                'subject' => 'Reminder: please top up your Nation Club pool',
                'body'    => "Dear {vendor_name},\n\n" .
                             "This is a friendly reminder that your Nation Club Vendor Pool balance is currently SGD {current_balance}, " .
                             "which is below the required minimum of SGD {minimum_balance}.\n\n" .
                             "You need to top up at least SGD {shortfall} to restore your pool to the required minimum.\n\n" .
                             "Please log in to the vendor portal, transfer the amount via Wise, and submit a top-up request with the proof of payment.\n\n" .
                             "If you have already submitted a top-up request that is awaiting admin approval, please ignore this message.\n\n" .
                             "Regards,\nNation Club",
            ),
        ),
        'vendor_low_balance' => array(
            'label'    => 'Vendor Low Balance Alert (vendor)',
            'audience' => 'vendor',
            'tokens'   => array(
                '{vendor_name}'      => 'Vendor display name',
                '{current_balance}'  => 'Current vendor pool balance',
                '{threshold}'        => 'Configured low-balance threshold',
                '{minimum_balance}'  => 'Required minimum (SGD 1,000)',
                '{shortfall}'        => 'Amount needed to restore the minimum',
                '{site_name}'        => 'WordPress site name',
            ),
            'defaults' => array(
                'subject' => 'Low balance alert: your Nation Club pool is below SGD {threshold}',
                'body'    => "Dear {vendor_name},\n\n" .
                             "Your Nation Club Vendor Pool balance has just dropped to SGD {current_balance}, " .
                             "below the configured low-balance threshold of SGD {threshold}.\n\n" .
                             "Required minimum is SGD {minimum_balance}. You need to top up at least SGD {shortfall} " .
                             "to restore your pool to the required minimum.\n\n" .
                             "Please log in to the vendor portal, transfer the amount via Wise, and submit a top-up request with the proof of payment.\n\n" .
                             "You will not receive another low-balance alert until your balance recovers above the threshold and dips again.\n\n" .
                             "Regards,\nNation Club",
            ),
        ),
    );
}

/**
 * Get the active subject/body for a template key.
 * Falls back to defaults; merges in legacy `nc_statement_email_template` for the
 * `statement` key so existing customizations aren't lost.
 */
function nc_email_get_template( $key ) {
    $reg = nc_email_template_registry();
    if ( ! isset( $reg[ $key ] ) ) { return null; }

    $all     = (array) get_option( NC_EMAIL_TEMPLATES_OPTION, array() );
    $stored  = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

    // One-time legacy migration for statement
    if ( $key === 'statement' && empty( $stored['subject'] ) && empty( $stored['body'] ) ) {
        $legacy = (array) get_option( NC_STATEMENT_EMAIL_OPTION, array() );
        if ( ! empty( $legacy['subject'] ) || ! empty( $legacy['body'] ) ) {
            $stored = $legacy;
        }
    }

    $defaults = $reg[ $key ]['defaults'];
    return array(
        'subject' => ! empty( $stored['subject'] ) ? (string) $stored['subject'] : $defaults['subject'],
        'body'    => ! empty( $stored['body'] )    ? (string) $stored['body']    : $defaults['body'],
    );
}

/**
 * Save subject/body for a template key. Validates against registry.
 */
function nc_email_save_template( $key, $subject, $body ) {
    $reg = nc_email_template_registry();
    if ( ! isset( $reg[ $key ] ) ) { return false; }
    $all = (array) get_option( NC_EMAIL_TEMPLATES_OPTION, array() );
    $all[ $key ] = array(
        'subject' => (string) $subject,
        'body'    => (string) $body,
    );
    return update_option( NC_EMAIL_TEMPLATES_OPTION, $all );
}

/**
 * Reset a template key to its registry defaults.
 */
function nc_email_reset_template( $key ) {
    $all = (array) get_option( NC_EMAIL_TEMPLATES_OPTION, array() );
    if ( isset( $all[ $key ] ) ) {
        unset( $all[ $key ] );
        update_option( NC_EMAIL_TEMPLATES_OPTION, $all );
    }
    // Also clear legacy if statement
    if ( $key === 'statement' ) {
        delete_option( NC_STATEMENT_EMAIL_OPTION );
    }
}

/**
 * Substitute {tokens} in a string using a key=>value map.
 */
function nc_email_apply_tokens( $text, $tokens ) {
    return strtr( (string) $text, (array) $tokens );
}

/**
 * Generic email sender. Loads the template, applies tokens, sends as HTML
 * via wp_mail with the wp_mail_content_type filter (works through WP Mail SMTP).
 *
 * @param string       $key         Template key from the registry
 * @param array|string $to          Recipient email(s)
 * @param array        $tokens      Token map for substitution
 * @param array        $cc          Optional CC recipients
 * @param array        $attachments Optional file paths
 * @return bool
 */
function nc_email_send( $key, $to, $tokens, $cc = array(), $attachments = array() ) {
    $tpl = nc_email_get_template( $key );
    if ( ! $tpl ) { return false; }

    $subject = nc_email_apply_tokens( $tpl['subject'], $tokens );
    $body    = nc_email_apply_tokens( $tpl['body'], $tokens );
    $body    = wpautop( $body );

    // Always merge in the global CC list configured in Nation Club → Settings,
    // in addition to whatever the caller provided. The recipient (To) is
    // excluded from CC to avoid duplicate delivery.
    $global_cc = nc_parse_email_list( get_option( 'nc_admin_notify_cc', '' ) );
    $cc        = array_unique( array_filter( array_merge( (array) $cc, $global_cc ) ) );

    $to_list = is_array( $to ) ? $to : array( $to );
    $cc      = array_values( array_diff( $cc, $to_list ) );

    // Single comma-separated Cc header is the most reliably handled form
    // across PHPMailer, WP Mail SMTP, and downstream mail servers.
    $headers = array();
    if ( ! empty( $cc ) ) {
        $headers[] = 'Cc: ' . implode( ', ', $cc );
    }

    if ( function_exists( 'nc_debug' ) ) {
        nc_debug( sprintf(
            'nc_email_send key=%s | to=%s | cc=%s | global_cc_raw=%s',
            $key,
            is_array( $to ) ? implode( ',', $to ) : (string) $to,
            empty( $cc ) ? '(none)' : implode( ',', $cc ),
            (string) get_option( 'nc_admin_notify_cc', '' )
        ) );
    }

    $force_html = function () { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $force_html );
    $sent = wp_mail( $to, $subject, $body, $headers, $attachments );
    remove_filter( 'wp_mail_content_type', $force_html );

    return (bool) $sent;
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

/**
 * Send statement email to vendor with PDF attached. Uses the unified
 * registry/sender from section 10.
 *
 * Caller is expected to ensure the statement is finalized first; this helper
 * does NOT change status (it's used both for the initial send by
 * nc_statement_finalize_and_send and for subsequent resends).
 *
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

    $tokens = nc_statement_email_tokens( $row );
    $sent   = nc_email_send( 'statement', $row->vendor_email, $tokens, array(), array( $pdf_path ) );

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

    return array( 'ok' => true, 'message' => 'Email sent to ' . $row->vendor_email );
}

/**
 * Single-action: lock a Draft statement and email it to the vendor with the
 * PDF attached. Status moves draft → sent (displayed as "Finalized & Sent").
 *
 * If sending fails, the row is rolled back to draft so the admin can retry
 * without leaving the statement half-finalized.
 *
 * Returns [ 'ok' => bool, 'message' => string ].
 */
function nc_statement_finalize_and_send( $statement_id, $admin_id ) {
    global $wpdb;
    $table = nc_statements_table();

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
         FROM {$table} s LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
         WHERE s.id = %d",
        (int) $statement_id
    ) );
    if ( ! $row ) {
        return array( 'ok' => false, 'message' => 'Statement not found.' );
    }
    if ( $row->status !== 'draft' ) {
        return array( 'ok' => false, 'message' => 'Statement is already finalized.' );
    }
    if ( empty( $row->vendor_email ) ) {
        return array( 'ok' => false, 'message' => 'Vendor has no email on file. Cannot finalize without a delivery target.' );
    }

    $now = current_time( 'mysql' );
    $wpdb->update( $table, array(
        'status'       => 'sent',
        'finalized_at' => $now,
        'finalized_by' => (int) $admin_id,
        'sent_at'      => $now,
        'sent_by'      => (int) $admin_id,
    ), array( 'id' => (int) $row->id ) );

    // Re-fetch with updated status so the PDF reflects "Finalized & Sent"
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.*, u.display_name AS vendor_name, u.user_email AS vendor_email
         FROM {$table} s LEFT JOIN {$wpdb->users} u ON u.ID = s.vendor_id
         WHERE s.id = %d",
        (int) $statement_id
    ) );

    $r = nc_statement_send_email( $row );
    if ( ! $r['ok'] ) {
        $wpdb->update( $table, array(
            'status'       => 'draft',
            'finalized_at' => null,
            'finalized_by' => null,
            'sent_at'      => null,
            'sent_by'      => null,
        ), array( 'id' => (int) $row->id ) );
        return array( 'ok' => false, 'message' => 'Email send failed (' . $r['message'] . '). Statement was reverted to Draft.' );
    }

    return array( 'ok' => true, 'message' => 'Statement finalized and emailed to ' . $row->vendor_email . '.' );
}

/* -------------------------------------------------------------------------
 * 11. Email Templates admin page (tabbed, multi-template hub)
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Email Templates',
        'Email Templates',
        'manage_options',
        'nc-email-templates',
        'nc_admin_email_templates_page',
        3
    );
}, 12 );

function nc_admin_email_templates_page() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

    $reg     = nc_email_template_registry();
    $current = isset( $_GET['tpl'] ) ? sanitize_key( $_GET['tpl'] ) : 'statement';
    if ( ! isset( $reg[ $current ] ) ) { $current = 'statement'; }

    if ( isset( $_POST['nc_email_save'] ) && check_admin_referer( 'nc_email_save_' . $current ) ) {
        $subject = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
        $body    = isset( $_POST['email_body'] )    ? wp_kses_post( wp_unslash( $_POST['email_body'] ) )    : '';
        nc_email_save_template( $current, $subject, $body );
        echo '<div class="notice notice-success is-dismissible"><p>Template saved.</p></div>';
    }

    if ( isset( $_POST['nc_email_reset'] ) && check_admin_referer( 'nc_email_reset_' . $current ) ) {
        nc_email_reset_template( $current );
        echo '<div class="notice notice-success is-dismissible"><p>Template reset to defaults.</p></div>';
    }

    $meta = $reg[ $current ];
    $tpl  = nc_email_get_template( $current );
    ?>
    <div class="wrap">
        <h1>Email Templates</h1>
        <p>Customize the subject, body, and tokens for each automated email. The active template is used at the time the email is sent.</p>

        <h2 class="nav-tab-wrapper" style="margin-top:18px">
            <?php foreach ( $reg as $key => $info ) :
                $url = esc_url( add_query_arg( array( 'page' => 'nc-email-templates', 'tpl' => $key ), admin_url( 'admin.php' ) ) );
                $cls = ( $key === $current ) ? 'nav-tab nav-tab-active' : 'nav-tab';
                $audience_badge = $info['audience'] === 'admin'
                    ? '<span style="background:#fff3cd;color:#856404;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px">ADMIN</span>'
                    : '<span style="background:#d1ecf1;color:#0c5460;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px">VENDOR</span>';
                ?>
                <a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo $url; ?>"><?php echo esc_html( $info['label'] ); ?> <?php echo $audience_badge; ?></a>
            <?php endforeach; ?>
        </h2>

        <p style="color:#666;background:#f8f9fa;padding:10px 14px;border-left:4px solid #8b1c3b;margin-top:16px;max-width:860px">
            <?php if ( $meta['audience'] === 'admin' ) : ?>
                Sent to admin notification recipients (configured in <a href="<?php echo esc_url( admin_url( 'admin.php?page=nc-settings' ) ); ?>">Nation Club → Settings</a>).
            <?php else : ?>
                Sent to the vendor's WordPress account email.
            <?php endif; ?>
        </p>

        <form method="post" style="max-width:860px;margin-top:12px">
            <?php wp_nonce_field( 'nc_email_save_' . $current ); ?>
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
                <button type="submit" name="nc_email_save" class="button button-primary">Save Template</button>
            </p>
        </form>

        <form method="post" style="margin-top:8px;max-width:860px">
            <?php wp_nonce_field( 'nc_email_reset_' . $current ); ?>
            <button type="submit" name="nc_email_reset" class="button" onclick="return confirm('Reset this template to defaults?');">Reset to Defaults</button>
        </form>

        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:14px;margin-top:24px;max-width:860px">
            <h2 style="margin-top:0">Available Tokens for this template</h2>
            <p>Use these in the subject or body — they will be replaced when the email is sent.</p>
            <table class="widefat striped">
                <thead><tr><th>Token</th><th>Replaced with</th></tr></thead>
                <tbody>
                    <?php foreach ( $meta['tokens'] as $token => $desc ) : ?>
                        <tr><td><code><?php echo esc_html( $token ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * 12. Vendor portal shortcode — [nc_vendor_statements]
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
           AND s.status <> 'draft'
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
                                <td><span class="nc-status nc-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status === 'draft' ? 'Draft' : 'Finalized & Sent' ); ?></span></td>
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
 * Vendor-side PDF stream. Verifies ownership and streams the PDF. Records
 * the first viewed_at timestamp for audit, but no longer changes the
 * statement status (the simplified flow stops at "Finalized & Sent").
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
    if ( $row->status === 'draft' ) {
        wp_die( 'Statement is not available for download.' );
    }

    if ( ! $row->viewed_at ) {
        $wpdb->update( $table, array( 'viewed_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
    }

    nc_statement_download_pdf( $row );
}

/* -------------------------------------------------------------------------
 * 13. Nation Club → Settings (withdrawal window etc.)
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Nation Club Settings',
        'Settings',
        'manage_options',
        'nc-settings',
        'nc_admin_settings_page',
        4
    );
}, 13 );

function nc_admin_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

    if ( isset( $_POST['nc_settings_save'] ) && check_admin_referer( 'nc_settings_save' ) ) {
        $start = isset( $_POST['wd_start'] ) ? max( 1, min( 31, (int) $_POST['wd_start'] ) ) : 2;
        $end   = isset( $_POST['wd_end'] )   ? max( 1, min( 31, (int) $_POST['wd_end'] ) )   : 5;

        $admin_to_raw      = isset( $_POST['admin_notify_to'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notify_to'] ) ) : '';
        $admin_cc_raw      = isset( $_POST['admin_notify_cc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notify_cc'] ) ) : '';
        $low_bal_threshold = isset( $_POST['low_balance_threshold'] ) ? max( 0, min( 100000, (int) $_POST['low_balance_threshold'] ) ) : 300;

        if ( $end < $start ) {
            echo '<div class="notice notice-error is-dismissible"><p>End day cannot be earlier than Start day.</p></div>';
        } else {
            // Validate emails — surface anything we had to drop so admin can fix it
            $to_invalid = nc_find_invalid_emails( $admin_to_raw );
            $cc_invalid = nc_find_invalid_emails( $admin_cc_raw );

            update_option( 'nc_withdrawal_window', array( 'start' => $start, 'end' => $end ) );
            update_option( 'nc_admin_notify_to', $admin_to_raw );
            update_option( 'nc_admin_notify_cc', $admin_cc_raw );
            update_option( 'nc_low_balance_threshold', $low_bal_threshold );

            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';

            if ( ! empty( $to_invalid ) || ! empty( $cc_invalid ) ) {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Warning:</strong> ';
                if ( ! empty( $to_invalid ) ) {
                    echo 'Admin To has invalid email(s) that will be ignored: <code>' . esc_html( implode( ', ', $to_invalid ) ) . '</code>. ';
                }
                if ( ! empty( $cc_invalid ) ) {
                    echo 'Global CC has invalid email(s) that will be ignored: <code>' . esc_html( implode( ', ', $cc_invalid ) ) . '</code>. ';
                }
                echo 'Please correct them — emails won\'t be delivered to those addresses.</p></div>';
            }
        }
    }

    $window            = nc_get_withdrawal_window_days();
    $admin_to          = (string) get_option( 'nc_admin_notify_to', '' );
    $admin_cc          = (string) get_option( 'nc_admin_notify_cc', '' );
    $low_bal_threshold = function_exists( 'nc_get_low_balance_threshold' ) ? nc_get_low_balance_threshold() : 300;
    ?>
    <div class="wrap">
        <h1>Nation Club Settings</h1>

        <form method="post" style="max-width:720px;background:#fff;padding:18px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px">
            <?php wp_nonce_field( 'nc_settings_save' ); ?>

            <h2 style="margin-top:0">Withdrawal Submission Window</h2>
            <p style="color:#666">
                Vendors can submit withdrawal requests only between these days each month.
                Outside this window, the request form is hidden in the vendor portal and submissions are blocked server-side.
                Top-up submissions are always available.
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wd_start">Window Start Day</label></th>
                    <td>
                        <input type="number" id="wd_start" name="wd_start" min="1" max="31" value="<?php echo esc_attr( $window['start'] ); ?>" required style="width:90px">
                        <span style="color:#666">day of the month (1–31)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wd_end">Window End Day</label></th>
                    <td>
                        <input type="number" id="wd_end" name="wd_end" min="1" max="31" value="<?php echo esc_attr( $window['end'] ); ?>" required style="width:90px">
                        <span style="color:#666">day of the month (1–31), inclusive</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Current setting</th>
                    <td>
                        <code>Day <?php echo esc_html( $window['start'] ); ?> – Day <?php echo esc_html( $window['end'] ); ?></code> of each month
                        <?php
                        $next = nc_statement_next_withdrawal_window();
                        if ( $next['is_open'] ) {
                            echo ' &nbsp; <span style="color:#1a8d2e;font-weight:600">● OPEN today</span> (until ' . esc_html( wp_date( 'M j, Y', $next['end_ts'] ) ) . ')';
                        } else {
                            echo ' &nbsp; <span style="color:#888">● Closed today</span> · next window: ' . esc_html( $next['label_short'] );
                        }
                        ?>
                    </td>
                </tr>
            </table>

            <p style="color:#888;font-size:12px">
                Tip: To temporarily allow withdrawals on any day for testing, set Start = 1 and End = 31. Remember to revert before going live.
            </p>

            <hr style="margin:24px 0">

            <h2>Email Notifications</h2>
            <p style="color:#666">
                Configure where the system sends admin alerts and who else gets copied on every email.
                One email per line, or comma-separated. Vendor-facing emails always go to the vendor's WordPress account email (with the global CC also added).
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="admin_notify_to">Admin Notification To <span style="color:#c62828">*</span></label></th>
                    <td>
                        <textarea id="admin_notify_to" name="admin_notify_to" rows="3" cols="50" placeholder="admin@example.com&#10;ops@example.com" style="width:100%;max-width:500px"><?php echo esc_textarea( $admin_to ); ?></textarea>
                        <p class="description">Receives admin alerts (top-up submitted, withdrawal submitted). If empty, the WordPress site admin email (<code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code>) is used.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="admin_notify_cc">Global CC <span style="color:#888">(optional)</span></label></th>
                    <td>
                        <textarea id="admin_notify_cc" name="admin_notify_cc" rows="3" cols="50" placeholder="accounting@example.com" style="width:100%;max-width:500px"><?php echo esc_textarea( $admin_cc ); ?></textarea>
                        <p class="description">CC'd on <strong>every</strong> email — admin alerts and vendor-facing emails (statement, top-up approved/rejected, withdrawal approved/rejected/paid). Multiple emails allowed.</p>
                    </td>
                </tr>
            </table>

            <hr style="margin:24px 0">

            <h2>Vendor Low Balance Alert</h2>
            <p style="color:#666">
                When a vendor's pool balance drops below this threshold (typically because of a customer reward or redemption settlement),
                the system automatically emails the vendor a low-balance alert. Each vendor receives one alert per "cross-down" — once their
                balance recovers above the threshold, the next dip will trigger another alert. Set to <code>0</code> to disable.
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="low_balance_threshold">Low Balance Threshold</label></th>
                    <td>
                        <input type="number" id="low_balance_threshold" name="low_balance_threshold" min="0" max="100000" step="1" value="<?php echo esc_attr( $low_bal_threshold ); ?>" style="width:120px">
                        <span style="color:#666">SGD (default 300)</span>
                        <p class="description">Set to <code>0</code> to disable low-balance alerts entirely.</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="nc_settings_save" class="button button-primary">Save Settings</button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Parse a list of emails from a free-form string (newlines, commas, or both).
 * Returns an array of valid, sanitized emails. Drops invalid entries silently.
 */
function nc_parse_email_list( $raw ) {
    if ( empty( $raw ) ) { return array(); }
    $parts = preg_split( '/[\s,;]+/', (string) $raw );
    $out   = array();
    foreach ( $parts as $p ) {
        $p = trim( $p );
        if ( $p && is_email( $p ) ) { $out[] = sanitize_email( $p ); }
    }
    return array_values( array_unique( $out ) );
}

/**
 * Counterpart to nc_parse_email_list — returns the entries that were dropped
 * because they aren't valid emails. Used to surface a warning to admins.
 */
function nc_find_invalid_emails( $raw ) {
    if ( empty( $raw ) ) { return array(); }
    $parts = preg_split( '/[\s,;]+/', (string) $raw );
    $bad   = array();
    foreach ( $parts as $p ) {
        $p = trim( $p );
        if ( $p !== '' && ! is_email( $p ) ) {
            $bad[] = $p;
        }
    }
    return array_values( array_unique( $bad ) );
}

/**
 * @return array{to:array, cc:array}
 */
function nc_get_admin_notify_recipients() {
    $to = nc_parse_email_list( get_option( 'nc_admin_notify_to', '' ) );
    if ( empty( $to ) ) {
        $to = array( get_option( 'admin_email' ) );
    }
    $cc = nc_parse_email_list( get_option( 'nc_admin_notify_cc', '' ) );
    return array( 'to' => $to, 'cc' => $cc );
}
