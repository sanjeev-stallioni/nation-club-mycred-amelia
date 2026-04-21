<?php
/**
 * Vendor grouped transaction history.
 * Renders one row per Transaction ID with a NET points impact for the logged-in vendor.
 *
 * Shortcode: [nc_vendor_history per_page="10"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Query grouped vendor transactions.
 *
 * @return array { items: object[], total: int }
 */
function nc_query_vendor_transactions( $user_id, $per_page = 10, $offset = 0 ) {
    global $wpdb;

    $user_id  = (int) $user_id;
    $per_page = max( 1, (int) $per_page );
    $offset   = max( 0, (int) $offset );

    if ( $user_id <= 0 ) {
        return array( 'items' => array(), 'total' => 0 );
    }

    $log_tbl = $wpdb->prefix . 'myCRED_log';

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.transaction_id')))
         FROM {$log_tbl}
         WHERE user_id = %d
           AND ref IN ('redeem_accept','redeem_liability','earn_liability')",
        $user_id
    ) );

    if ( $total === 0 ) {
        return array( 'items' => array(), 'total' => 0 );
    }

    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.transaction_id')) AS txn_id,
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.service_id'))     AS service_id,
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.customer_id'))    AS customer_id,
            SUM(creds)                                           AS net,
            MIN(time)                                            AS occurred_at,
            MAX(CASE WHEN ref = 'earn_liability' THEN entry END) AS entry_main,
            MAX(entry)                                           AS entry_any
         FROM {$log_tbl}
         WHERE user_id = %d
           AND ref IN ('redeem_accept','redeem_liability','earn_liability')
         GROUP BY txn_id
         ORDER BY occurred_at DESC
         LIMIT %d OFFSET %d",
        $user_id,
        $per_page,
        $offset
    ) );

    return array(
        'items' => $items ?: array(),
        'total' => $total,
    );
}

function nc_get_service_name( $service_id ) {
    static $cache = array();

    $service_id = (int) $service_id;
    if ( $service_id <= 0 ) {
        return '';
    }
    if ( isset( $cache[ $service_id ] ) ) {
        return $cache[ $service_id ];
    }

    global $wpdb;
    $name = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}amelia_services WHERE id = %d",
        $service_id
    ) );

    return $cache[ $service_id ] = $name;
}

function nc_get_customer_display_name( $amelia_customer_id ) {
    static $cache = array();

    $amelia_customer_id = (int) $amelia_customer_id;
    if ( $amelia_customer_id <= 0 ) {
        return '';
    }
    if ( isset( $cache[ $amelia_customer_id ] ) ) {
        return $cache[ $amelia_customer_id ];
    }

    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT externalId, firstName, lastName
         FROM {$wpdb->prefix}amelia_users
         WHERE id = %d",
        $amelia_customer_id
    ) );

    if ( ! $row ) {
        return $cache[ $amelia_customer_id ] = '';
    }

    if ( ! empty( $row->externalId ) ) {
        $user = get_user_by( 'ID', (int) $row->externalId );
        if ( $user ) {
            return $cache[ $amelia_customer_id ] = $user->user_login;
        }
    }

    return $cache[ $amelia_customer_id ] = trim( $row->firstName . ' ' . $row->lastName );
}

function nc_format_net_points( $value ) {
    $value = (float) $value;

    if ( $value == 0 ) {
        return '0';
    }

    // whole number -> no decimals; otherwise 2 decimals
    $is_whole  = ( floor( $value ) == $value );
    $formatted = $is_whole ? number_format( $value, 0 ) : number_format( $value, 2 );

    return $value > 0 ? '+' . $formatted : $formatted;
}

function nc_register_vendor_transactions_assets() {
    $css_rel = 'assets/vendor-transactions.css';
    $js_rel  = 'assets/vendor-transactions.js';
    $css_abs = NC_MYCRE_AMELIA_PATH . $css_rel;
    $js_abs  = NC_MYCRE_AMELIA_PATH . $js_rel;

    wp_register_style(
        'nc-vendor-history',
        NC_MYCRE_AMELIA_URL . $css_rel,
        array(),
        file_exists( $css_abs ) ? filemtime( $css_abs ) : '1.0'
    );

    wp_register_script(
        'nc-vendor-history',
        NC_MYCRE_AMELIA_URL . $js_rel,
        array(),
        file_exists( $js_abs ) ? filemtime( $js_abs ) : '1.0',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'nc_register_vendor_transactions_assets' );

function nc_render_vendor_transactions_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'per_page' => 10,
        ),
        $atts,
        'nc_vendor_history'
    );

    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'You must be logged in to view your transaction history.', 'nation-club-mycred-amelia' ) . '</p>';
    }

    wp_enqueue_style( 'nc-vendor-history' );
    wp_enqueue_script( 'nc-vendor-history' );

    $user_id  = get_current_user_id();
    $per_page = max( 1, (int) $atts['per_page'] );
    $paged    = isset( $_GET['nc_txn_page'] ) ? max( 1, (int) $_GET['nc_txn_page'] ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    $result = nc_query_vendor_transactions( $user_id, $per_page, $offset );
    $items  = $result['items'];
    $total  = $result['total'];

    $nonce = wp_create_nonce( 'nc_vendor_history' );

    ob_start();
    ?>
    <div class="nc-vendor-history" data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <?php if ( empty( $items ) ) : ?>
            <p><?php esc_html_e( 'No transactions yet.', 'nation-club-mycred-amelia' ); ?></p>
        <?php else : ?>
            <?php
            $total_pages = (int) ceil( $total / $per_page );
            if ( $total_pages > 1 ) {
                $pagination = paginate_links( array(
                    'base'      => add_query_arg( 'nc_txn_page', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                    'end_size'  => 2,
                    'mid_size'  => 1,
                    'type'      => 'list',
                ) );
            }
            ?>
            <table class="nc-vendor-history__table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'nation-club-mycred-amelia' ); ?></th>
                        <th><?php esc_html_e( 'Service', 'nation-club-mycred-amelia' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'nation-club-mycred-amelia' ); ?></th>
                        <th><?php esc_html_e( 'Points Effect', 'nation-club-mycred-amelia' ); ?></th>
                        <th><?php esc_html_e( 'Transaction ID', 'nation-club-mycred-amelia' ); ?></th>
                        <th><?php esc_html_e( 'Details', 'nation-club-mycred-amelia' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $items as $row ) :
                    $net       = (float) $row->net;
                    $net_class = $net > 0 ? 'nc-net--pos' : ( $net < 0 ? 'nc-net--neg' : '' );
                    ?>
                    <tr data-txn="<?php echo esc_attr( $row->txn_id ); ?>">
                        <td><?php echo esc_html( date_i18n( 'M d, Y', (int) $row->occurred_at ) ); ?></td>
                        <td><?php echo esc_html( nc_get_service_name( $row->service_id ) ); ?></td>
                        <td><?php echo esc_html( nc_get_customer_display_name( $row->customer_id ) ); ?></td>
                        <td class="nc-net <?php echo esc_attr( $net_class ); ?>"><?php echo esc_html( nc_format_net_points( $net ) ); ?></td>
                        <td><?php echo esc_html( $row->txn_id ); ?></td>
                        <td><a href="#" class="nc-txn-view" data-txn="<?php echo esc_attr( $row->txn_id ); ?>"><?php esc_html_e( 'View', 'nation-club-mycred-amelia' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php 
            echo '<nav class="nc-vendor-history__pagination">' . $pagination . '</nav>';
        endif; ?>
    </div>
    <?php

    return ob_get_clean();
}

add_shortcode( 'nc_vendor_history', 'nc_render_vendor_transactions_shortcode' );

/**
 * AJAX: return the per-transaction breakdown HTML for the logged-in vendor.
 */
function nc_ajax_get_txn_breakdown() {
    check_ajax_referer( 'nc_vendor_history', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Not logged in' ) );
    }

    $txn_id = isset( $_POST['txn_id'] ) ? sanitize_text_field( wp_unslash( $_POST['txn_id'] ) ) : '';
    if ( $txn_id === '' ) {
        wp_send_json_error( array( 'message' => 'Missing transaction ID' ) );
    }

    global $wpdb;
    $log_tbl = $wpdb->prefix . 'myCRED_log';
    $user_id = get_current_user_id();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ref, creds, entry
         FROM {$log_tbl}
         WHERE user_id = %d
           AND ref IN ('redeem_accept','redeem_liability','earn_liability')
           AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.transaction_id')) = %s
         ORDER BY id ASC",
        $user_id,
        $txn_id
    ) );

    if ( empty( $rows ) ) {
        wp_send_json_error( array( 'message' => 'No entries for this transaction' ) );
    }

    $labels = array(
        'redeem_accept'    => __( 'Customer redeemed points', 'nation-club-mycred-amelia' ),
        'redeem_liability' => __( 'Old points liability cleared', 'nation-club-mycred-amelia' ),
        'earn_liability'   => __( 'New points issued', 'nation-club-mycred-amelia' ),
    );

    $net  = 0.0;
    $html = '<div class="nc-txn-breakdown">';
    foreach ( $rows as $row ) {
        $creds     = (float) $row->creds;
        $net      += $creds;
        $label     = isset( $labels[ $row->ref ] ) ? $labels[ $row->ref ] : $row->ref;
        $css_class = $creds > 0 ? 'nc-net--pos' : ( $creds < 0 ? 'nc-net--neg' : '' );

        $html .= sprintf(
            '<div class="nc-txn-line"><span>%s:</span><span class="%s">%s</span></div>',
            esc_html( $label ),
            esc_attr( $css_class ),
            esc_html( nc_format_net_points( $creds ) )
        );
    }
    $html .= '<hr class="nc-txn-divider">';

    $net_class = $net > 0 ? 'nc-net--pos' : ( $net < 0 ? 'nc-net--neg' : '' );
    $html .= sprintf(
        '<div class="nc-txn-line nc-txn-net"><span>%s:</span><span class="%s">%s</span></div>',
        esc_html__( 'Net impact to your pool', 'nation-club-mycred-amelia' ),
        esc_attr( $net_class ),
        esc_html( nc_format_net_points( $net ) )
    );
    $html .= '</div>';

    wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_nc_get_txn_breakdown', 'nc_ajax_get_txn_breakdown' );
