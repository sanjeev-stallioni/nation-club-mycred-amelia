<?php
/**
 * Per-customer point batches — tracks each earn as a separate batch with its
 * own expiry date and source vendor.
 *
 * Why: a customer's balance is an aggregate of points earned from many
 * vendors at many different times. Without per-batch tracking we can't:
 *   1) Refund the originating vendor when the customer's points expire
 *   2) Differentiate expiry dates per source (Vendor A's points clear on
 *      Vendor A's schedule, not Vendor B's)
 *
 * Lifecycle:
 *   - Customer earns → row created (status=active, remaining=earned)
 *   - Customer redeems → walk active batches FIFO (oldest earned first),
 *     reduce remaining until redeem amount is satisfied. Batches that hit 0
 *     are marked fully_redeemed.
 *   - Daily cron expires batches whose expiry_ts has passed: posts
 *     `points_expiry` on customer (-remaining) and `expired_refund` on
 *     origin vendor (+remaining). Batch marked expired.
 *
 * The cron is hooked onto nc_statement_daily_cron so it shares the same
 * trigger as the statements + snapshots cron — single daily fire, all three
 * jobs run together.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NC_BATCHES_DB_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 * 0. Schema
 * ----------------------------------------------------------------------- */

function nc_batches_table() {
    global $wpdb;
    return $wpdb->prefix . 'nc_customer_point_batches';
}

function nc_batches_install() {
    global $wpdb;
    $table   = nc_batches_table();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        log_id BIGINT UNSIGNED DEFAULT NULL,
        customer_user_id BIGINT UNSIGNED NOT NULL,
        liability_vendor_id BIGINT UNSIGNED NOT NULL,
        earned_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        remaining_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        earned_ts BIGINT UNSIGNED NOT NULL,
        expiry_ts BIGINT UNSIGNED DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY customer_user_id (customer_user_id),
        KEY liability_vendor_id (liability_vendor_id),
        KEY status_expiry (status, expiry_ts),
        KEY earned_ts (earned_ts)
    ) {$charset};";

    dbDelta( $sql );
    update_option( 'nc_batches_db_version', NC_BATCHES_DB_VERSION );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'nc_batches_db_version' ) !== NC_BATCHES_DB_VERSION ) {
        nc_batches_install();
    }
} );

/* -------------------------------------------------------------------------
 * 1. Batch lifecycle helpers
 * ----------------------------------------------------------------------- */

/**
 * Create a new batch when a customer earns points.
 *
 * @param int       $customer_user_id    WP user id of the customer
 * @param int       $liability_vendor_id WP user id of the origin vendor
 * @param float     $amount              Points earned
 * @param int|null  $log_id              myCRED_log id of the booking_reward entry (optional, for traceability)
 * @return int                            New batch row id, or 0 on failure
 */
function nc_batch_create( $customer_user_id, $liability_vendor_id, $amount, $log_id = null ) {
    global $wpdb;

    $amount = round( (float) $amount, 2 );
    if ( $amount <= 0 ) {
        return 0;
    }

    $expiry_ts = function_exists( 'get_mycred_customer_expiry_timestamp' )
        ? get_mycred_customer_expiry_timestamp()
        : false;

    $now = current_time( 'timestamp' );

    $ok = $wpdb->insert( nc_batches_table(), array(
        'log_id'              => $log_id ? (int) $log_id : null,
        'customer_user_id'    => (int) $customer_user_id,
        'liability_vendor_id' => (int) $liability_vendor_id,
        'earned_amount'       => $amount,
        'remaining_amount'    => $amount,
        'earned_ts'           => $now,
        'expiry_ts'           => $expiry_ts && is_numeric( $expiry_ts ) ? (int) $expiry_ts : null,
        'status'              => 'active',
    ) );

    return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Consume from a customer's active batches FIFO (oldest earned_ts first).
 * Reduces each batch's remaining_amount until the redeem amount is satisfied.
 *
 * @param int   $customer_user_id
 * @param float $amount  Total to consume
 * @return array         List of [batch_id, amount_consumed]
 */
function nc_batch_consume_fifo( $customer_user_id, $amount ) {
    global $wpdb;
    $table = nc_batches_table();

    $remaining_to_consume = round( (float) $amount, 2 );
    $consumed             = array();

    if ( $remaining_to_consume <= 0 ) {
        return $consumed;
    }

    $now = current_time( 'timestamp' );

    // Exclude batches whose expiry has already passed — even if the daily
    // cron hasn't yet run to mark them expired. Closes the window between
    // the expiry timestamp and the cron tick where a customer could
    // otherwise redeem points that are officially expired.
    $batches = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, remaining_amount FROM {$table}
         WHERE customer_user_id = %d
           AND status = 'active'
           AND remaining_amount > 0
           AND ( expiry_ts IS NULL OR expiry_ts > %d )
         ORDER BY earned_ts ASC, id ASC",
        (int) $customer_user_id,
        (int) $now
    ) );

    foreach ( $batches as $batch ) {
        if ( $remaining_to_consume <= 0 ) {
            break;
        }

        $batch_remaining = (float) $batch->remaining_amount;
        $consume         = min( $batch_remaining, $remaining_to_consume );
        $new_remaining   = round( $batch_remaining - $consume, 2 );

        $wpdb->update( $table, array(
            'remaining_amount' => $new_remaining,
            'status'           => $new_remaining <= 0 ? 'fully_redeemed' : 'active',
        ), array( 'id' => (int) $batch->id ) );

        $consumed[]           = array( 'batch_id' => (int) $batch->id, 'amount' => $consume );
        $remaining_to_consume = round( $remaining_to_consume - $consume, 2 );
    }

    return $consumed;
}

/**
 * Process all batches whose expiry_ts has passed. For each:
 *   - Debits the customer via points_expiry
 *   - Refunds the origin vendor via expired_refund (NEW ref)
 *   - Marks the batch status=expired
 *
 * @param bool $force  Manual run flag (purely for log tagging)
 * @return int         Number of batches expired in this run
 */
function nc_batch_run_expiry( $force = false ) {
    global $wpdb;
    $table = nc_batches_table();

    if ( ! function_exists( 'mycred_add' ) ) {
        return 0;
    }

    $now = current_time( 'timestamp' );

    $batches = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE status = 'active'
           AND remaining_amount > 0
           AND expiry_ts IS NOT NULL
           AND expiry_ts <= %d
         ORDER BY expiry_ts ASC, id ASC",
        $now
    ) );

    $expired_count = 0;

    foreach ( $batches as $batch ) {
        $remaining = (float) $batch->remaining_amount;
        if ( $remaining <= 0 ) {
            continue;
        }

        // Resolve the Amelia customer id from WP user id so the vendor's
        // transaction-history shortcode (which keys on Amelia ids) can render
        // the customer column correctly.
        $amelia_customer_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amelia_users WHERE externalId = %d ORDER BY id ASC LIMIT 1",
            (int) $batch->customer_user_id
        ) );

        $log_data = wp_json_encode( array(
            // Synthetic transaction id so the vendor history grouping (which
            // uses transaction_id as its key) treats this refund as its own row.
            'transaction_id'      => 'BATCH-' . (int) $batch->id,
            'batch_id'            => (int) $batch->id,
            'log_id'              => $batch->log_id ? (int) $batch->log_id : null,
            'customer_id'         => $amelia_customer_id ?: 0,
            'customer_user_id'    => (int) $batch->customer_user_id,
            'liability_vendor_id' => (int) $batch->liability_vendor_id,
            'service_id'          => 0,
            'earned_ts'           => (int) $batch->earned_ts,
            'expiry_ts'           => (int) $batch->expiry_ts,
        ) );

        // Customer side — debit
        mycred_add(
            'points_expiry',
            (int) $batch->customer_user_id,
            -$remaining,
            sprintf( '%s points expired (batch #%d)', number_format( $remaining, 2 ), (int) $batch->id ),
            (int) $batch->id,
            $log_data
        );

        // Vendor side — refund (NEW: previously expiry was a permanent loss to vendor)
        mycred_add(
            'expired_refund',
            (int) $batch->liability_vendor_id,
            $remaining,
            sprintf( '%s points refunded — customer batch #%d expired unredeemed', number_format( $remaining, 2 ), (int) $batch->id ),
            (int) $batch->id,
            $log_data
        );

        $wpdb->update( $table, array(
            'remaining_amount' => 0,
            'status'           => 'expired',
        ), array( 'id' => (int) $batch->id ) );

        $expired_count++;
    }

    return $expired_count;
}

/* -------------------------------------------------------------------------
 * 2. Daily cron — hooks onto nc_statement_daily_cron
 * ----------------------------------------------------------------------- */

add_action( 'nc_statement_daily_cron', 'nc_batch_expiry_cron_handler' );

function nc_batch_expiry_cron_handler( $force = false ) {
    if ( function_exists( 'nc_statement_cron_log' ) ) {
        $today = (int) wp_date( 'j' );
        nc_statement_cron_log( "--- Per-batch expiry run (day {$today})" . ( $force ? ' [MANUAL TEST]' : '' ) . ' ---' );
    }

    $count = nc_batch_run_expiry( $force );

    if ( function_exists( 'nc_statement_cron_log' ) ) {
        nc_statement_cron_log( "  OK   — {$count} batch(es) expired in this run" );
        nc_statement_cron_log( '--- End per-batch expiry run ---' . PHP_EOL );
    }
}
