<?php
/**
 * Customer-facing points display shortcode: [nc_my_points]
 *
 * Shows the logged-in customer's:
 *   - Current total balance (myCRED)
 *   - Active batches breakdown — each row is one earn with source vendor,
 *     amount earned, amount remaining (after FIFO redemption), and expiry date
 *
 * Usage: drop [nc_my_points] anywhere on a customer-facing page (Account,
 * Dashboard, etc.). Renders nothing for logged-out visitors.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'nc_my_points', 'nc_my_points_shortcode' );

function nc_my_points_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p class="nc-mp-empty">Please log in to view your points.</p>';
    }

    $user_id = get_current_user_id();
    $balance = function_exists( 'mycred_get_users_balance' ) ? (float) mycred_get_users_balance( $user_id ) : 0.0;

    global $wpdb;
    $table = $wpdb->prefix . 'nc_customer_point_batches';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, liability_vendor_id, earned_amount, remaining_amount, earned_ts, expiry_ts
         FROM {$table}
         WHERE customer_user_id = %d AND status = 'active' AND remaining_amount > 0
         ORDER BY expiry_ts ASC, earned_ts ASC",
        $user_id
    ) );

    ob_start();
    ?>
    <div class="nc-mp-wrap">
        <div class="nc-mp-balance">
            <div class="nc-mp-balance__label">Your points balance</div>
            <div class="nc-mp-balance__value"><?php echo esc_html( number_format( $balance, 2 ) ); ?> <span>pts</span></div>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <p class="nc-mp-empty">You don't have any active points right now. Book a service to start earning.</p>
        <?php else : ?>
            <h3 class="nc-mp-title">Breakdown by earn batch</h3>
            <p class="nc-mp-hint">When you redeem, your oldest points are spent first. Each batch expires on its own date.</p>
            <div class="nc-mp-table-wrap">
                <table class="nc-mp-table">
                    <thead>
                        <tr>
                            <th>Earned</th>
                            <th>From vendor</th>
                            <th class="num">Earned</th>
                            <th class="num">Remaining</th>
                            <th>Expires on</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $r ) :
                            $vendor      = get_user_by( 'id', (int) $r->liability_vendor_id );
                            $vendor_name = $vendor ? $vendor->display_name : ( 'Vendor #' . (int) $r->liability_vendor_id );
                            $earned_str  = (int) $r->earned_ts ? wp_date( 'M j, Y', (int) $r->earned_ts ) : '—';
                            $expiry_str  = (int) $r->expiry_ts ? wp_date( 'M j, Y', (int) $r->expiry_ts ) : '—';

                            $expires_soon = false;
                            if ( (int) $r->expiry_ts > 0 ) {
                                $days_left    = (int) floor( ( (int) $r->expiry_ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
                                $expires_soon = ( $days_left >= 0 && $days_left <= 30 );
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $earned_str ); ?></td>
                                <td><?php echo esc_html( $vendor_name ); ?></td>
                                <td class="num"><?php echo esc_html( number_format( (float) $r->earned_amount, 2 ) ); ?></td>
                                <td class="num"><strong><?php echo esc_html( number_format( (float) $r->remaining_amount, 2 ) ); ?></strong></td>
                                <td>
                                    <?php echo esc_html( $expiry_str ); ?>
                                    <?php if ( $expires_soon ) : ?>
                                        <span class="nc-mp-soon">expires soon</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .nc-mp-wrap {
            /* max-width: 760px; */
            margin: 18px 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #2c2c2c;
        }
        .nc-mp-balance {
            background: linear-gradient(135deg, #8b1c3b 0%, #6b1530 100%);
            color: #fff;
            padding: 22px 24px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(139, 28, 59, 0.18);
            margin-bottom: 18px;
        }
        .nc-mp-balance__label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            opacity: 0.85;
        }
        .nc-mp-balance__value {
            font-size: 32px;
            font-weight: 700;
            margin-top: 4px;
        }
        .nc-mp-balance__value span {
            font-size: 14px;
            font-weight: 500;
            margin-left: 6px;
            opacity: 0.85;
        }
        .nc-mp-title {
            font-size: 15px;
            font-weight: 600;
            margin: 18px 0 4px;
            color: #444;
        }
        .nc-mp-hint {
            font-size: 13px;
            color: #777;
            margin: 0 0 12px;
        }
        .nc-mp-table-wrap {
            overflow-x: auto;
            border: 1px solid #e6e6e6;
            border-radius: 8px;
        }
        .nc-mp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .nc-mp-table th, .nc-mp-table td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #efefef;
        }
        .nc-mp-table th {
            background: #fafafa;
            font-weight: 600;
            color: #555;
        }
        .nc-mp-table tr:last-child td { border-bottom: none; }
        .nc-mp-table .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .nc-mp-soon {
            display: inline-block;
            background: #fff3cd;
            color: #6c4f00;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 999px;
            margin-left: 6px;
        }
        .nc-mp-empty {
            background: #f7f7f7;
            border: 1px dashed #ccc;
            padding: 18px;
            border-radius: 8px;
            color: #666;
            text-align: center;
        }
    </style>
    <?php
    return ob_get_clean();
}
