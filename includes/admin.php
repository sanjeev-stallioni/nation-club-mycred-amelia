<?php
/**
 * MyCred Expiry Rules – Full Code
 */

/*--------------------------------------------------------------
# 1. Admin Menu
--------------------------------------------------------------*/
add_action( 'admin_menu', 'mycred_expiry_menu' );
function mycred_expiry_menu() {
    add_menu_page(
        'Expiry Rules',
        'Expiry Rules',
        'manage_options',
        'mycred-expiry-rules',
        'mycred_expiry_page',
        'dashicons-clock',
        80
    );
}

/*--------------------------------------------------------------
# 2. Register Setting
--------------------------------------------------------------*/
add_action( 'admin_init', 'mycred_register_expiry_option' );
function mycred_register_expiry_option() {
    register_setting( 'mycred_expiry_group', 'mycred_expiry_rules' );
}

/*--------------------------------------------------------------
# 3. Helper – Convert value to datetime-local format
--------------------------------------------------------------*/
function mycred_datetime_local_value( $value ) {

    if ( empty( $value ) ) {
        return '';
    }

    // Timestamp
    if ( is_numeric( $value ) ) {
        return wp_date( 'Y-m-d\TH:i', (int) $value );
    }

    // MySQL / string datetime
    return wp_date(
        'Y-m-d\TH:i',
        strtotime( $value ),
        wp_timezone()
    );
}

/*--------------------------------------------------------------
# 4. Admin Page UI
--------------------------------------------------------------*/
function mycred_expiry_page() {

    $rules = get_option( 'mycred_expiry_rules', [] );

    $defaults = [
        'rule1_from'   => '',
        'rule1_to'     => '',
        'rule1_expire' => '',
        'rule2_from'   => '',
        'rule2_to'     => '',
        'rule2_expire' => '',
    ];

    $rules = wp_parse_args( $rules, $defaults );
    ?>
    <div class="wrap">
        <h1>Expiry Rules</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'mycred_expiry_group' ); ?>

            <table class="form-table">

                <tr><th colspan="2"><h2>Rule 1</h2></th></tr>
                <tr>
                    <th>From</th>
                    <td>
                        <input type="datetime-local"
                               name="mycred_expiry_rules[rule1_from]"
                               value="<?php echo esc_attr( mycred_datetime_local_value( $rules['rule1_from'] ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th>To</th>
                    <td>
                        <input type="datetime-local"
                               name="mycred_expiry_rules[rule1_to]"
                               value="<?php echo esc_attr( mycred_datetime_local_value( $rules['rule1_to'] ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th>Expire</th>
                    <td>
                        <input type="datetime-local"
                               name="mycred_expiry_rules[rule1_expire]"
                               value="<?php echo esc_attr( mycred_datetime_local_value( $rules['rule1_expire'] ) ); ?>">
                    </td>
                </tr>

                <tr><th colspan="2"><h2>Rule 2</h2></th></tr>
                <tr>
                    <th>From</th>
                    <td>
                        <input type="datetime-local"
                               name="mycred_expiry_rules[rule2_from]"
                               value="<?php echo esc_attr( mycred_datetime_local_value( $rules['rule2_from'] ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th>To</th>
                    <td>
                        <input type="datetime-local"
                               name="mycred_expiry_rules[rule2_to]"
                               value="<?php echo esc_attr( mycred_datetime_local_value( $rules['rule2_to'] ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th>Expire</th>
                    <td>
                        <input type="datetime-local"
                               name="mycred_expiry_rules[rule2_expire]"
                               value="<?php echo esc_attr( mycred_datetime_local_value( $rules['rule2_expire'] ) ); ?>">
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/*--------------------------------------------------------------
# 5. Get Active Expiry Timestamp
--------------------------------------------------------------*/
function get_mycred_customer_expiry_timestamp() {

    $rules = get_option( 'mycred_expiry_rules', [] );
    if ( empty( $rules ) ) {
        return false;
    }

    $now = current_datetime(); // WP timezone-safe

    foreach ( [ 'rule1', 'rule2' ] as $rule ) {

        if (
            empty( $rules["{$rule}_from"] ) ||
            empty( $rules["{$rule}_to"] ) ||
            empty( $rules["{$rule}_expire"] )
        ) {
            continue;
        }

        $from   = new DateTime( $rules["{$rule}_from"], wp_timezone() );
        $to     = new DateTime( $rules["{$rule}_to"], wp_timezone() );
        $expire = new DateTime( $rules["{$rule}_expire"], wp_timezone() );

        if ( $now >= $from && $now <= $to ) {
            return $expire->getTimestamp();
        }
    }

    return false;
}
