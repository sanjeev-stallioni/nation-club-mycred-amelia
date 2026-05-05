<?php
/**
 * Customer points expiry rules — admin page + helper.
 *
 * Admin page at Nation Club → Expiry Rules. Two date-range rules: each says
 * "if today falls between From and To, points earned today expire on this
 * Expire date." A master "Disable expiry" toggle turns the whole thing off.
 *
 * The helper `get_mycred_customer_expiry_timestamp()` is consumed by:
 *  - Earn flow in mycred-hooks.php (to stamp expiry on a customer's balance)
 *  - Per-batch expiry system (to stamp expiry on each new earn batch)
 *
 * Returns false when expiry is disabled OR no rule window matches today —
 * callers must handle that gracefully (no expiry stamp on the earn).
 *
 * Defaults are seeded on first load:
 *  - Disabled flag = off
 *  - Rule 1: Jan 1 – Jun 30 → expire Dec 31 of same year
 *  - Rule 2: Jul 1 – Dec 31 → expire Jun 30 of next year
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*--------------------------------------------------------------
# 1. Admin Menu — Nation Club → Expiry Rules
--------------------------------------------------------------*/
add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Expiry Rules',
        'Expiry Rules',
        'manage_options',
        'nc-expiry-rules',
        'mycred_expiry_page',
        6
    );
}, 15 );

/*--------------------------------------------------------------
# 2. Register Settings
--------------------------------------------------------------*/
add_action( 'admin_init', function () {
    register_setting( 'mycred_expiry_group', 'mycred_expiry_rules' );
    register_setting( 'mycred_expiry_group', 'mycred_expiry_disabled' );
} );

/*--------------------------------------------------------------
# 2a. Seed defaults on first load
--------------------------------------------------------------*/
add_action( 'admin_init', function () {
    $existing = get_option( 'mycred_expiry_rules', null );
    if ( ! is_array( $existing ) || empty( $existing['rule1_expire'] ) ) {
        $year = (int) wp_date( 'Y' );
        update_option( 'mycred_expiry_rules', array(
            'rule1_from'   => $year . '-01-01 00:00',
            'rule1_to'     => $year . '-06-30 23:59',
            'rule1_expire' => $year . '-12-31 23:59',
            'rule2_from'   => $year . '-07-01 00:00',
            'rule2_to'     => $year . '-12-31 23:59',
            'rule2_expire' => ( $year + 1 ) . '-06-30 23:59',
        ) );
        update_option( 'mycred_expiry_last_rolled_year', $year );
    }
} );

/*--------------------------------------------------------------
# 2b. Auto-roll rules forward each year
#
# Tracks last_rolled_year. When current year is ahead, advance every date
# field in the rules by the year delta. Preserves any admin customisation —
# e.g. admin set Rule 1's expire to "Nov 30" → next Jan it becomes "Nov 30"
# of the new year, not reset to Dec 31.
#
# Called on admin_init AND inside the helper, so:
#   - Admin viewing the page after Jan 1 sees fresh dates
#   - Customer earning on Jan 1 still gets a valid expiry stamp
--------------------------------------------------------------*/
function nc_expiry_maybe_roll_rules() {
    $current_year = (int) wp_date( 'Y' );
    $last_rolled  = (int) get_option( 'mycred_expiry_last_rolled_year', 0 );

    if ( $last_rolled === 0 ) {
        // No tracker yet — set it so next year's check works. Skip the
        // advance because the seed block already used current year.
        update_option( 'mycred_expiry_last_rolled_year', $current_year );
        return;
    }

    if ( $current_year <= $last_rolled ) {
        return; // Already current
    }

    $delta = $current_year - $last_rolled;
    $rules = get_option( 'mycred_expiry_rules', array() );

    if ( ! is_array( $rules ) ) {
        return;
    }

    $fields = array( 'rule1_from', 'rule1_to', 'rule1_expire', 'rule2_from', 'rule2_to', 'rule2_expire' );

    foreach ( $fields as $field ) {
        if ( empty( $rules[ $field ] ) ) {
            continue;
        }
        try {
            $dt = new DateTime( $rules[ $field ], wp_timezone() );
            $dt->modify( '+' . $delta . ' year' );
            $rules[ $field ] = $dt->format( 'Y-m-d H:i' );
        } catch ( Exception $e ) {
            // Malformed date — leave as-is, admin can fix manually
        }
    }

    update_option( 'mycred_expiry_rules', $rules );
    update_option( 'mycred_expiry_last_rolled_year', $current_year );
}
add_action( 'admin_init', 'nc_expiry_maybe_roll_rules' );

/*--------------------------------------------------------------
# 3. Helpers – date display + time normalization
--------------------------------------------------------------*/

/**
 * Pull just the Y-m-d portion out of a stored value for the <input type="date"> field.
 *
 * Uses regex (not strtotime+wp_date) because the stored value carries an
 * explicit time-of-day like "2026-12-31 23:59". Round-tripping that through
 * strtotime/wp_date can cross a day boundary if PHP's default timezone
 * differs from WP's site timezone — which would silently shift the date by
 * +1 day on every render. The regex avoids any timezone arithmetic entirely.
 */
function mycred_date_only_value( $value ) {

    if ( empty( $value ) ) {
        return '';
    }

    if ( is_numeric( $value ) ) {
        return wp_date( 'Y-m-d', (int) $value );
    }

    if ( preg_match( '/(\d{4})-(\d{1,2})-(\d{1,2})/', (string) $value, $m ) ) {
        return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
    }

    return '';
}

/**
 * Time-of-day each field should always carry. Admin only picks the date —
 * we attach the time deterministically so behavior is predictable:
 *   - "From" starts at 00:00 (the very beginning of that day)
 *   - "To" ends at 23:59   (covers the entire day)
 *   - "Expire on" at 23:59 (points stay valid for the entire day, then expire)
 */
function nc_expiry_field_time_map() {
    return array(
        'rule1_from'   => '00:00',
        'rule1_to'     => '23:59',
        'rule1_expire' => '23:59',
        'rule2_from'   => '00:00',
        'rule2_to'     => '23:59',
        'rule2_expire' => '23:59',
    );
}

/**
 * Force every rule field into "Y-m-d HH:MM" using the canonical time-of-day
 * for that field. Idempotent — calling it twice returns the same result.
 *
 * Uses a regex to extract the date portion. Earlier this used strtotime +
 * wp_date but that introduced a +1 day drift each pass when PHP's default
 * timezone differed from WP's site timezone (since 23:59 in one tz = 00:00+
 * in another). Each save → admin_init re-normalize compounded the shift.
 */
function nc_expiry_normalize_rules( $rules ) {

    if ( ! is_array( $rules ) ) {
        return $rules;
    }

    $times = nc_expiry_field_time_map();

    foreach ( $times as $field => $expected_time ) {
        if ( empty( $rules[ $field ] ) ) {
            continue;
        }
        if ( preg_match( '/(\d{4})-(\d{1,2})-(\d{1,2})/', (string) $rules[ $field ], $m ) ) {
            $rules[ $field ] = sprintf(
                '%04d-%02d-%02d %s',
                (int) $m[1], (int) $m[2], (int) $m[3], $expected_time
            );
        }
    }

    return $rules;
}

/**
 * Filter on save — admin can only submit Y-m-d (type="date" input). Append
 * the canonical time-of-day before the option hits the DB.
 */
add_filter( 'pre_update_option_mycred_expiry_rules', 'nc_expiry_normalize_rules' );

/**
 * One-time fix-up on admin load — normalises any existing rows that were
 * stored with the wrong time-of-day (legacy datetime-local input lets admin
 * type any time, and earlier saves may have ended up at 00:00 on Expire).
 */
add_action( 'admin_init', function () {
    $rules = get_option( 'mycred_expiry_rules', array() );
    if ( ! is_array( $rules ) || empty( $rules ) ) {
        return;
    }
    $normalized = nc_expiry_normalize_rules( $rules );
    if ( $normalized !== $rules ) {
        update_option( 'mycred_expiry_rules', $normalized );
    }
}, 20 );

/*--------------------------------------------------------------
# 4. Admin Page UI
--------------------------------------------------------------*/
function mycred_expiry_page() {

    $rules    = get_option( 'mycred_expiry_rules', array() );
    $disabled = (bool) get_option( 'mycred_expiry_disabled', 0 );

    $defaults = array(
        'rule1_from'   => '',
        'rule1_to'     => '',
        'rule1_expire' => '',
        'rule2_from'   => '',
        'rule2_to'     => '',
        'rule2_expire' => '',
    );

    $rules = wp_parse_args( $rules, $defaults );
    ?>
    <div class="wrap">
        <h1>Expiry Rules</h1>

        <p style="color:#555;max-width:780px">
            Configure when customer points expire. Each rule defines a date window: points earned during that window expire on the rule's <em>Expire</em> date.
            You can disable the entire system below — when disabled, new earns are not stamped with an expiry and no points will ever expire (until re-enabled).
        </p>

        <form method="post" action="options.php">
            <?php settings_fields( 'mycred_expiry_group' ); ?>

            <h2 style="margin-top:24px">Master switch</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Disable expiry</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mycred_expiry_disabled" value="1" <?php checked( $disabled, true ); ?>>
                            Turn off all customer point expiry
                        </label>
                        <p class="description">When ticked, the rules below are ignored and no points will expire. Existing expired points stay expired.</p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:18px">Rule 1</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">From</th>
                    <td>
                        <input type="date" name="mycred_expiry_rules[rule1_from]"
                               value="<?php echo esc_attr( mycred_date_only_value( $rules['rule1_from'] ) ); ?>">
                        <p class="description">Earning window starts at the beginning of this day (00:00).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">To</th>
                    <td>
                        <input type="date" name="mycred_expiry_rules[rule1_to]"
                               value="<?php echo esc_attr( mycred_date_only_value( $rules['rule1_to'] ) ); ?>">
                        <p class="description">Earning window covers all of this day (until 23:59).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Expire on</th>
                    <td>
                        <input type="date" name="mycred_expiry_rules[rule1_expire]"
                               value="<?php echo esc_attr( mycred_date_only_value( $rules['rule1_expire'] ) ); ?>">
                        <p class="description">Points stay valid for all of this day, then expire at 23:59.</p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:18px">Rule 2</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">From</th>
                    <td>
                        <input type="date" name="mycred_expiry_rules[rule2_from]"
                               value="<?php echo esc_attr( mycred_date_only_value( $rules['rule2_from'] ) ); ?>">
                        <p class="description">Earning window starts at the beginning of this day (00:00).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">To</th>
                    <td>
                        <input type="date" name="mycred_expiry_rules[rule2_to]"
                               value="<?php echo esc_attr( mycred_date_only_value( $rules['rule2_to'] ) ); ?>">
                        <p class="description">Earning window covers all of this day (until 23:59).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Expire on</th>
                    <td>
                        <input type="date" name="mycred_expiry_rules[rule2_expire]"
                               value="<?php echo esc_attr( mycred_date_only_value( $rules['rule2_expire'] ) ); ?>">
                        <p class="description">Points stay valid for all of this day, then expire at 23:59.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/*--------------------------------------------------------------
# 5. Active expiry timestamp (consumed by earn flow + per-batch system)
--------------------------------------------------------------*/
function get_mycred_customer_expiry_timestamp() {

    if ( (bool) get_option( 'mycred_expiry_disabled', 0 ) ) {
        return false;
    }

    // Roll rules forward if a year (or more) has passed since last update.
    // Ensures earns on Jan 1 still get a valid expiry stamp without requiring
    // an admin to visit the dashboard first.
    nc_expiry_maybe_roll_rules();

    $rules = get_option( 'mycred_expiry_rules', array() );
    if ( empty( $rules ) ) {
        return false;
    }

    $now = current_datetime();

    foreach ( array( 'rule1', 'rule2' ) as $rule ) {

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
