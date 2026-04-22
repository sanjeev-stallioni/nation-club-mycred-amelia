<?php
/**
 * Vendor Pool — Top-up & Withdrawal Module
 *
 * Handles admin-verified top-ups (vendor submits → admin approves → ledger credit)
 * and vendor-initiated withdrawals (vendor requests → admin approves → Wise payout →
 * ledger debit).
 *
 * All pool credits/debits flow through myCRED's ledger using these refs:
 *   - vendor_topup       (+, created on admin approval)
 *   - vendor_withdrawal  (-, created when admin marks payout processed)
 *
 * Vendors cannot self-credit. All submissions are held in a custom table
 * (status = pending) until an admin reviews them.
 *
 * Shortcodes:
 *   [nc_vendor_topup_form]   — replaces the buyCred purchase form
 *   [nc_vendor_withdrawal]   — surplus view + withdrawal request UI
 *
 * Admin:
 *   Nation Club → Top-up Requests
 *   Nation Club → Withdrawal Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 1. Schema
 * ----------------------------------------------------------------------- */

define( 'NC_VENDOR_POOL_DB_VERSION', '1.0.0' );
define( 'NC_VENDOR_POOL_MIN_BALANCE', 1000 );

function nc_vendor_pool_tables() {
    global $wpdb;
    return array(
        'topup'      => $wpdb->prefix . 'nc_topup_requests',
        'withdrawal' => $wpdb->prefix . 'nc_withdrawal_requests',
    );
}

function nc_vendor_pool_install() {
    global $wpdb;
    $tables  = nc_vendor_pool_tables();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $topup = "CREATE TABLE {$tables['topup']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        vendor_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        transfer_date DATE DEFAULT NULL,
        wise_reference VARCHAR(191) DEFAULT NULL,
        attachment_id BIGINT UNSIGNED DEFAULT NULL,
        attachment_url TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        topup_id VARCHAR(40) DEFAULT NULL,
        mycred_log_id BIGINT UNSIGNED DEFAULT NULL,
        admin_note TEXT DEFAULT NULL,
        reviewed_by BIGINT UNSIGNED DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY vendor_id (vendor_id),
        KEY status (status)
    ) {$charset};";

    $withdrawal = "CREATE TABLE {$tables['withdrawal']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        vendor_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        statement_month VARCHAR(7) DEFAULT NULL,
        vendor_note TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        withdrawal_id VARCHAR(40) DEFAULT NULL,
        wise_reference VARCHAR(191) DEFAULT NULL,
        mycred_log_id BIGINT UNSIGNED DEFAULT NULL,
        admin_note TEXT DEFAULT NULL,
        approved_by BIGINT UNSIGNED DEFAULT NULL,
        processed_by BIGINT UNSIGNED DEFAULT NULL,
        request_date DATETIME NOT NULL,
        approval_date DATETIME DEFAULT NULL,
        processed_date DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY vendor_id (vendor_id),
        KEY status (status)
    ) {$charset};";

    dbDelta( $topup );
    dbDelta( $withdrawal );

    update_option( 'nc_vendor_pool_db_version', NC_VENDOR_POOL_DB_VERSION );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'nc_vendor_pool_db_version' ) !== NC_VENDOR_POOL_DB_VERSION ) {
        nc_vendor_pool_install();
    }
} );

/* -------------------------------------------------------------------------
 * 2. Helpers
 * ----------------------------------------------------------------------- */

function nc_vendor_pool_balance( $vendor_id ) {
    if ( ! function_exists( 'mycred_get_users_balance' ) ) {
        return 0;
    }
    return round( (float) mycred_get_users_balance( (int) $vendor_id ), 2 );
}

function nc_vendor_pool_surplus( $vendor_id ) {
    $bal = nc_vendor_pool_balance( $vendor_id );
    return max( 0, round( $bal - NC_VENDOR_POOL_MIN_BALANCE, 2 ) );
}

function nc_vendor_is_provider( $user_id ) {
    $user = get_userdata( (int) $user_id );
    if ( ! $user ) {
        return false;
    }
    return in_array( 'wpamelia-provider', (array) $user->roles, true );
}

function nc_vendor_generate_topup_id( $row_id ) {
    return 'TU-' . str_pad( (string) $row_id, 5, '0', STR_PAD_LEFT );
}

function nc_vendor_generate_withdrawal_id( $row_id ) {
    return 'WD-' . str_pad( (string) $row_id, 5, '0', STR_PAD_LEFT );
}

/**
 * Render a sortable <th> in the standard WP list-table style.
 * Clicking a header toggles the sort direction and resets paged to 1.
 */
function nc_admin_sortable_th( $label, $key, $current_orderby, $current_order ) {
    $is_current = ( $current_orderby === $key );
    $next_order = ( $is_current && $current_order === 'asc' ) ? 'desc' : 'asc';
    $classes    = $is_current
        ? 'manage-column column-' . $key . ' sorted ' . $current_order
        : 'manage-column column-' . $key . ' sortable desc';
    $url = add_query_arg( array(
        'orderby' => $key,
        'order'   => $next_order,
        'paged'   => 1,
    ) );
    printf(
        '<th scope="col" class="%s"><a href="%s"><span>%s</span><span class="sorting-indicator"></span></a></th>',
        esc_attr( $classes ),
        esc_url( $url ),
        esc_html( $label )
    );
}

/**
 * Register vendor-pool front-end assets (loaded only on pages that use the shortcodes).
 * Shared primitives live in common.css — module CSS files depend on it.
 */
function nc_vendor_pool_register_assets() {
    $files = array(
        'nc-common-css'            => array( 'assets/common.css',            'style',  array() ),
        'nc-vendor-topup-css'      => array( 'assets/vendor-topup.css',      'style',  array( 'nc-common-css' ) ),
        'nc-vendor-topup-js'       => array( 'assets/vendor-topup.js',       'script', array() ),
        'nc-vendor-withdrawal-css' => array( 'assets/vendor-withdrawal.css', 'style',  array( 'nc-common-css' ) ),
        'nc-vendor-withdrawal-js'  => array( 'assets/vendor-withdrawal.js',  'script', array() ),
    );
    foreach ( $files as $handle => $info ) {
        list( $rel, $kind, $deps ) = $info;
        $abs = NC_MYCRE_AMELIA_PATH . $rel;
        $ver = file_exists( $abs ) ? filemtime( $abs ) : '1.0';
        if ( $kind === 'style' ) {
            wp_register_style( $handle, NC_MYCRE_AMELIA_URL . $rel, $deps, $ver );
        } else {
            wp_register_script( $handle, NC_MYCRE_AMELIA_URL . $rel, $deps, $ver, true );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'nc_vendor_pool_register_assets' );

/**
 * Check if a withdrawal is allowed for the given vendor/amount.
 *
 * Enforces the balance floor (SGD 1,000). Other rules from the spec
 * (statement not finalized, disputes, manual adjustments, shared costs)
 * plug in via the `nc_vendor_can_withdraw` filter once those modules ship.
 *
 * @return array{ok:bool,reason:string}
 */
function nc_vendor_can_request_withdrawal( $vendor_id, $amount ) {
    $vendor_id = (int) $vendor_id;
    $amount    = round( (float) $amount, 2 );

    if ( $amount <= 0 ) {
        return array( 'ok' => false, 'reason' => 'Amount must be greater than zero.' );
    }

    $balance = nc_vendor_pool_balance( $vendor_id );
    $after   = round( $balance - $amount, 2 );

    if ( $after < NC_VENDOR_POOL_MIN_BALANCE ) {
        return array(
            'ok'     => false,
            'reason' => sprintf(
                'Withdrawal would drop your pool to %s. Minimum balance of %s must be maintained.',
                number_format( $after, 2 ),
                number_format( NC_VENDOR_POOL_MIN_BALANCE, 2 )
            ),
        );
    }

    $result = array( 'ok' => true, 'reason' => '' );

    // Extension point: statement module, disputes module, adjustments module etc.
    // register filters that can flip `ok` to false and set `reason`.
    $result = apply_filters( 'nc_vendor_can_withdraw', $result, $vendor_id, $amount );

    return $result;
}

/* -------------------------------------------------------------------------
 * 3. Vendor shortcode — Top-up form
 * ----------------------------------------------------------------------- */

function nc_vendor_topup_form_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in to submit a top-up request.</p>';
    }

    $user_id = get_current_user_id();
    if ( ! nc_vendor_is_provider( $user_id ) ) {
        return '<p>Only vendors can submit top-up requests.</p>';
    }

    wp_enqueue_style( 'nc-vendor-topup-css' );
    wp_enqueue_script( 'nc-vendor-topup-js' );

    ob_start();
    nc_vendor_topup_form_render( $user_id );
    return ob_get_clean();
}
add_shortcode( 'nc_vendor_topup_form', 'nc_vendor_topup_form_shortcode' );

function nc_vendor_topup_form_render( $vendor_id ) {
    global $wpdb;
    $tables = nc_vendor_pool_tables();

    $notice = '';
    if ( isset( $_GET['nc_topup'] ) ) {
        $flag = sanitize_key( wp_unslash( $_GET['nc_topup'] ) );
        if ( 'success' === $flag ) {
            $notice = '<div class="nc-notice nc-notice--ok">Top-up request submitted. Admin will verify the Wise payment and credit your pool within 48 hours.</div>';
        } elseif ( 'error' === $flag && isset( $_GET['nc_msg'] ) ) {
            $notice = '<div class="nc-notice nc-notice--err">' . esc_html( sanitize_text_field( wp_unslash( $_GET['nc_msg'] ) ) ) . '</div>';
        }
    }

    $balance = nc_vendor_pool_balance( $vendor_id );

    // Paginate Recent Submissions
    $per_page = 10;
    $paged    = isset( $_GET['nc_topup_page'] ) ? max( 1, (int) $_GET['nc_topup_page'] ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$tables['topup']} WHERE vendor_id = %d",
        $vendor_id
    ) );

    $recent = $total > 0 ? $wpdb->get_results( $wpdb->prepare(
        "SELECT id, amount, transfer_date, status, topup_id, admin_note, created_at
         FROM {$tables['topup']}
         WHERE vendor_id = %d
         ORDER BY id DESC
         LIMIT %d OFFSET %d",
        $vendor_id,
        $per_page,
        $offset
    ) ) : array();

    $total_pages = (int) ceil( $total / $per_page );
    ?>
    <div class="nc-box">
        <div class="nc-box__header">
            <h2>Top-Up Your Points</h2>
            <p class="nc-box__subtitle">1 point = SGD 1. Pay via Wise, then submit this form for admin verification.</p>
        </div>

        <div class="nc-box__body">
            <?php echo $notice; ?>

            <div class="nc-topup-balance">
                <div>
                    <div class="nc-topup-balance__label">Current Pool Balance</div>
                    <div class="nc-topup-balance__sub">SGD <?php echo esc_html( number_format( $balance, 2 ) ); ?></div>
                </div>
                <div class="nc-topup-balance__value"><?php echo esc_html( number_format( $balance, 2 ) ); ?></div>
            </div>

            <div class="nc-hint-banner">
                <span class="nc-hint-banner__icon">ⓘ</span>
                <span>Transfer funds to Nation Club's Wise account first. Admin will verify the payment within 48 hours and credit your pool.</span>
            </div>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="nc_vendor_submit_topup">
                <?php wp_nonce_field( 'nc_vendor_submit_topup' ); ?>

                <div class="nc-field">
                    <label for="nc_topup_amount">Amount (SGD)<span class="nc-required">*</span></label>
                    <input type="number" step="0.01" min="1" id="nc_topup_amount" name="amount" placeholder="e.g. 1000.00" required>
                </div>

                <div class="nc-field">
                    <label for="nc_topup_date">Transfer Date<span class="nc-required">*</span></label>
                    <input type="date" id="nc_topup_date" name="transfer_date" required>
                </div>

                <div class="nc-field">
                    <label for="nc_topup_ref">Wise Reference <span style="font-weight:400;color:#999">(optional)</span></label>
                    <input type="text" id="nc_topup_ref" name="wise_reference" maxlength="190" placeholder="e.g. TRX-12345">
                </div>

                <div class="nc-field">
                    <label>Payment Proof<span class="nc-required">*</span></label>
                    <div class="nc-topup-file">
                        <input type="file" id="nc_topup_proof" name="payment_proof" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                        <div class="nc-topup-file__inner">
                            <div class="nc-topup-file__icon">📎</div>
                            <div class="nc-topup-file__text">
                                <div class="nc-topup-file__primary">Click to choose a file</div>
                                <div class="nc-topup-file__secondary">PDF, DOC, DOCX, JPG, PNG — max 8 MB</div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="nc-btn nc-btn--primary nc-btn--block">Submit Top-up Request</button>
            </form>

            <?php if ( $recent ) : ?>
                <h3 class="nc-section-title">Recent Submissions</h3>
                <div class="nc-table-wrap">
                    <table class="nc-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Top-up ID</th>
                                <th>Status</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $recent as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( mysql2date( 'M j, Y', $row->created_at ) ); ?></td>
                                <td class="nc-amount"><?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></td>
                                <td><?php echo esc_html( $row->topup_id ?: '—' ); ?></td>
                                <td><span class="nc-status nc-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span></td>
                                <td><?php echo esc_html( $row->admin_note ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $total_pages > 1 ) :
                    $links = paginate_links( array(
                        'base'      => add_query_arg( 'nc_topup_page', '%#%' ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                        'end_size'  => 1,
                        'mid_size'  => 1,
                    ) );
                ?>
                    <nav class="nc-pagination"><?php echo $links; ?></nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * 4. Top-up submission handler (vendor → pending row)
 * ----------------------------------------------------------------------- */

function nc_vendor_handle_topup_submission() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Not logged in.' );
    }

    check_admin_referer( 'nc_vendor_submit_topup' );

    $vendor_id = get_current_user_id();
    if ( ! nc_vendor_is_provider( $vendor_id ) ) {
        wp_die( 'Only vendors can submit top-up requests.' );
    }

    $redirect = wp_get_referer() ?: home_url();

    $amount        = isset( $_POST['amount'] ) ? round( (float) $_POST['amount'], 2 ) : 0;
    $transfer_date = isset( $_POST['transfer_date'] ) ? sanitize_text_field( wp_unslash( $_POST['transfer_date'] ) ) : '';
    $wise_ref      = isset( $_POST['wise_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['wise_reference'] ) ) : '';

    if ( $amount <= 0 ) {
        wp_safe_redirect( add_query_arg( array( 'nc_topup' => 'error', 'nc_msg' => rawurlencode( 'Amount must be greater than zero.' ) ), $redirect ) );
        exit;
    }
    if ( empty( $transfer_date ) ) {
        wp_safe_redirect( add_query_arg( array( 'nc_topup' => 'error', 'nc_msg' => rawurlencode( 'Transfer date is required.' ) ), $redirect ) );
        exit;
    }
    if ( empty( $_FILES['payment_proof']['name'] ) ) {
        wp_safe_redirect( add_query_arg( array( 'nc_topup' => 'error', 'nc_msg' => rawurlencode( 'Payment proof is required.' ) ), $redirect ) );
        exit;
    }

    // File upload
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! function_exists( 'wp_insert_attachment' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $allowed_mimes = array(
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg|jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    );

    $file = $_FILES['payment_proof'];
    if ( (int) $file['size'] > 8 * 1024 * 1024 ) {
        wp_safe_redirect( add_query_arg( array( 'nc_topup' => 'error', 'nc_msg' => rawurlencode( 'File exceeds 8 MB.' ) ), $redirect ) );
        exit;
    }

    $upload = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $allowed_mimes ) );
    if ( isset( $upload['error'] ) ) {
        wp_safe_redirect( add_query_arg( array( 'nc_topup' => 'error', 'nc_msg' => rawurlencode( $upload['error'] ) ), $redirect ) );
        exit;
    }

    // Rename to unguessable filename so the raw URL isn't useful to anyone
    // who doesn't come through the admin-gated view handler.
    $ext           = pathinfo( $upload['file'], PATHINFO_EXTENSION );
    $hashed_name   = 'nc-proof-' . bin2hex( random_bytes( 12 ) ) . ( $ext ? '.' . strtolower( $ext ) : '' );
    $hashed_path   = dirname( $upload['file'] ) . DIRECTORY_SEPARATOR . $hashed_name;
    if ( @rename( $upload['file'], $hashed_path ) ) {
        $upload['file'] = $hashed_path;
        $upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . $hashed_name;
    }

    $attachment_id = wp_insert_attachment( array(
        'post_mime_type' => $upload['type'],
        'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'private',
    ), $upload['file'] );

    if ( ! is_wp_error( $attachment_id ) ) {
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
    } else {
        $attachment_id = 0;
    }

    global $wpdb;
    $tables = nc_vendor_pool_tables();

    // attachment_url column retained for backward compatibility but no longer
    // exposed in the admin UI — admins view via the guarded stream handler.
    $wpdb->insert( $tables['topup'], array(
        'vendor_id'      => $vendor_id,
        'amount'         => $amount,
        'transfer_date'  => $transfer_date,
        'wise_reference' => $wise_ref,
        'attachment_id'  => $attachment_id ?: null,
        'attachment_url' => $upload['url'],
        'status'         => 'pending',
        'created_at'     => current_time( 'mysql' ),
    ), array( '%d', '%f', '%s', '%s', '%d', '%s', '%s', '%s' ) );

    wp_safe_redirect( add_query_arg( array( 'nc_topup' => 'success' ), $redirect ) );
    exit;
}
add_action( 'admin_post_nc_vendor_submit_topup', 'nc_vendor_handle_topup_submission' );

/**
 * Admin-only handler to stream a top-up payment proof.
 *
 * The raw upload URL is no longer exposed in the admin UI. Admins must go
 * through this handler, which:
 *   1. Verifies manage_options capability
 *   2. Verifies the nonce bound to the specific topup row
 *   3. Loads the attachment via attachment_id (works even for legacy rows)
 *   4. Streams the file with correct headers
 *
 * Vendors and any non-admin user cannot call this endpoint.
 */
function nc_admin_stream_topup_proof() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized', 'Forbidden', array( 'response' => 403 ) );
    }
    $topup_id = isset( $_GET['topup_id'] ) ? (int) $_GET['topup_id'] : 0;
    if ( ! $topup_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'nc_view_topup_proof_' . $topup_id ) ) {
        wp_die( 'Invalid request.' );
    }

    global $wpdb;
    $tables = nc_vendor_pool_tables();
    $row    = $wpdb->get_row( $wpdb->prepare(
        "SELECT attachment_id, attachment_url FROM {$tables['topup']} WHERE id = %d",
        $topup_id
    ) );
    if ( ! $row ) {
        wp_die( 'Top-up request not found.' );
    }

    $file = $row->attachment_id ? get_attached_file( (int) $row->attachment_id ) : '';
    if ( ! $file || ! file_exists( $file ) ) {
        wp_die( 'Attached file missing on server.' );
    }

    $mime = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $file ) : array();
    $mime = ! empty( $mime['type'] ) ? $mime['type'] : 'application/octet-stream';

    while ( ob_get_level() ) { ob_end_clean(); }
    nocache_headers();
    header( 'Content-Type: ' . $mime );
    header( 'Content-Disposition: inline; filename="' . basename( $file ) . '"' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Content-Length: ' . filesize( $file ) );
    readfile( $file );
    exit;
}
add_action( 'admin_post_nc_view_topup_proof', 'nc_admin_stream_topup_proof' );

/* -------------------------------------------------------------------------
 * 5. Vendor shortcode — Withdrawal UI
 * ----------------------------------------------------------------------- */

function nc_vendor_withdrawal_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in to manage withdrawals.</p>';
    }

    $user_id = get_current_user_id();
    if ( ! nc_vendor_is_provider( $user_id ) ) {
        return '<p>Only vendors can access this page.</p>';
    }

    wp_enqueue_style( 'nc-vendor-withdrawal-css' );
    wp_enqueue_script( 'nc-vendor-withdrawal-js' );

    ob_start();
    nc_vendor_withdrawal_render( $user_id );
    return ob_get_clean();
}
add_shortcode( 'nc_vendor_withdrawal', 'nc_vendor_withdrawal_shortcode' );

function nc_vendor_withdrawal_render( $vendor_id ) {
    global $wpdb;
    $tables = nc_vendor_pool_tables();

    $notice = '';
    if ( isset( $_GET['nc_wd'] ) ) {
        $flag = sanitize_key( wp_unslash( $_GET['nc_wd'] ) );
        if ( 'success' === $flag ) {
            $notice = '<div class="nc-notice nc-notice--ok">Withdrawal request submitted. Admin will review it shortly.</div>';
        } elseif ( 'error' === $flag && isset( $_GET['nc_msg'] ) ) {
            $notice = '<div class="nc-notice nc-notice--err">' . esc_html( sanitize_text_field( wp_unslash( $_GET['nc_msg'] ) ) ) . '</div>';
        }
    }

    $balance = nc_vendor_pool_balance( $vendor_id );
    $surplus = nc_vendor_pool_surplus( $vendor_id );
    $deficit = $balance < NC_VENDOR_POOL_MIN_BALANCE ? ( NC_VENDOR_POOL_MIN_BALANCE - $balance ) : 0;

    // Paginate Recent Requests
    $per_page = 10;
    $paged    = isset( $_GET['nc_wd_page'] ) ? max( 1, (int) $_GET['nc_wd_page'] ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$tables['withdrawal']} WHERE vendor_id = %d",
        $vendor_id
    ) );

    $recent = $total > 0 ? $wpdb->get_results( $wpdb->prepare(
        "SELECT id, amount, status, withdrawal_id, admin_note, wise_reference, request_date, approval_date, processed_date
         FROM {$tables['withdrawal']}
         WHERE vendor_id = %d
         ORDER BY id DESC
         LIMIT %d OFFSET %d",
        $vendor_id,
        $per_page,
        $offset
    ) ) : array();

    $total_pages = (int) ceil( $total / $per_page );

    $has_pending = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$tables['withdrawal']}
         WHERE vendor_id = %d AND status IN ('pending','approved')",
        $vendor_id
    ) );
    ?>
    <div class="nc-box">
        <div class="nc-box__header">
            <h2>Pool Balance &amp; Withdrawal</h2>
            <p class="nc-box__subtitle">Maintain a minimum of <?php echo esc_html( number_format( NC_VENDOR_POOL_MIN_BALANCE, 2 ) ); ?> points. Surplus can be kept in pool or withdrawn via Wise.</p>
        </div>

        <div class="nc-box__body">
            <?php echo $notice; ?>

            <div class="nc-wd-summary">
                <div class="nc-wd-card">
                    <div class="lbl">Current Balance</div>
                    <div class="val"><?php echo esc_html( number_format( $balance, 2 ) ); ?></div>
                    <div class="sub">SGD <?php echo esc_html( number_format( $balance, 2 ) ); ?></div>
                </div>
                <div class="nc-wd-card">
                    <div class="lbl">Required Minimum</div>
                    <div class="val"><?php echo esc_html( number_format( NC_VENDOR_POOL_MIN_BALANCE, 2 ) ); ?></div>
                    <div class="sub">Maintained at all times</div>
                </div>
                <?php if ( $deficit > 0 ) : ?>
                    <div class="nc-wd-card nc-wd-card--deficit">
                        <div class="lbl">Top-up Required</div>
                        <div class="val"><?php echo esc_html( number_format( $deficit, 2 ) ); ?></div>
                        <div class="sub">to restore minimum</div>
                    </div>
                <?php else : ?>
                    <div class="nc-wd-card <?php echo $surplus > 0 ? 'nc-wd-card--surplus' : ''; ?>">
                        <div class="lbl">Surplus</div>
                        <div class="val"><?php echo esc_html( number_format( $surplus, 2 ) ); ?></div>
                        <div class="sub"><?php echo $surplus > 0 ? 'available to withdraw' : 'no surplus yet'; ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( $has_pending ) : ?>
                <div class="nc-notice nc-notice--info">
                    You have a withdrawal request in progress. Please wait for it to be processed before submitting a new one.
                </div>
            <?php elseif ( $surplus <= 0 ) : ?>
                <div class="nc-empty">
                    No surplus to withdraw. Withdrawals are available once your balance exceeds
                    <strong><?php echo esc_html( number_format( NC_VENDOR_POOL_MIN_BALANCE, 2 ) ); ?></strong>.
                </div>
            <?php else : ?>
                <h3 class="nc-section-title">Request Withdrawal</h3>
                <p class="nc-hint">You can keep the surplus in your pool for future services, or request a withdrawal to Wise. Admin will review and process the payout.</p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="nc_vendor_submit_withdrawal">
                    <?php wp_nonce_field( 'nc_vendor_submit_withdrawal' ); ?>

                    <div class="nc-field">
                        <label for="nc_wd_amount">Amount to Withdraw <span style="font-weight:400;color:#999">(max <?php echo esc_html( number_format( $surplus, 2 ) ); ?>)</span></label>
                        <input type="number" step="0.01" min="1" max="<?php echo esc_attr( $surplus ); ?>" id="nc_wd_amount" name="amount" value="<?php echo esc_attr( $surplus ); ?>" required>
                    </div>

                    <div class="nc-field">
                        <label for="nc_wd_note">Note <span style="font-weight:400;color:#999">(optional)</span></label>
                        <textarea id="nc_wd_note" name="vendor_note" rows="2" maxlength="500" placeholder="Anything the admin should know about this withdrawal…"></textarea>
                    </div>

                    <div class="nc-form-actions">
                        <button type="submit" class="nc-btn nc-btn--primary">Request Withdrawal</button>
                        <span class="nc-hint" style="margin:0">or simply leave it — surplus carries forward to next month.</span>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ( $recent ) : ?>
                <h3 class="nc-section-title">Recent Requests</h3>
                <div class="nc-table-wrap">
                    <table class="nc-table">
                        <thead>
                            <tr>
                                <th>Requested</th>
                                <th>Amount</th>
                                <th>Withdrawal ID</th>
                                <th>Status</th>
                                <th>Wise Ref</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $recent as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( mysql2date( 'M j, Y', $row->request_date ) ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></td>
                                <td><?php echo esc_html( $row->withdrawal_id ?: '—' ); ?></td>
                                <td><span class="nc-status nc-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span></td>
                                <td><?php echo esc_html( $row->wise_reference ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row->admin_note ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $total_pages > 1 ) :
                    $links = paginate_links( array(
                        'base'      => add_query_arg( 'nc_wd_page', '%#%' ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                        'end_size'  => 1,
                        'mid_size'  => 1,
                    ) );
                ?>
                    <nav class="nc-pagination"><?php echo $links; ?></nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * 6. Withdrawal submission handler (vendor → pending row)
 * ----------------------------------------------------------------------- */

function nc_vendor_handle_withdrawal_submission() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Not logged in.' );
    }

    check_admin_referer( 'nc_vendor_submit_withdrawal' );

    $vendor_id = get_current_user_id();
    if ( ! nc_vendor_is_provider( $vendor_id ) ) {
        wp_die( 'Only vendors can submit withdrawal requests.' );
    }

    $redirect = wp_get_referer() ?: home_url();

    $amount      = isset( $_POST['amount'] ) ? round( (float) $_POST['amount'], 2 ) : 0;
    $vendor_note = isset( $_POST['vendor_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vendor_note'] ) ) : '';

    global $wpdb;
    $tables = nc_vendor_pool_tables();

    // Don't allow overlapping requests
    $existing = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$tables['withdrawal']}
         WHERE vendor_id = %d AND status IN ('pending','approved')",
        $vendor_id
    ) );
    if ( $existing > 0 ) {
        wp_safe_redirect( add_query_arg( array( 'nc_wd' => 'error', 'nc_msg' => rawurlencode( 'You already have a withdrawal in progress.' ) ), $redirect ) );
        exit;
    }

    $check = nc_vendor_can_request_withdrawal( $vendor_id, $amount );
    if ( ! $check['ok'] ) {
        wp_safe_redirect( add_query_arg( array( 'nc_wd' => 'error', 'nc_msg' => rawurlencode( $check['reason'] ) ), $redirect ) );
        exit;
    }

    $wpdb->insert( $tables['withdrawal'], array(
        'vendor_id'       => $vendor_id,
        'amount'          => $amount,
        'statement_month' => wp_date( 'Y-m' ),
        'vendor_note'     => $vendor_note,
        'status'          => 'pending',
        'request_date'    => current_time( 'mysql' ),
    ), array( '%d', '%f', '%s', '%s', '%s', '%s' ) );

    wp_safe_redirect( add_query_arg( array( 'nc_wd' => 'success' ), $redirect ) );
    exit;
}
add_action( 'admin_post_nc_vendor_submit_withdrawal', 'nc_vendor_handle_withdrawal_submission' );

/* -------------------------------------------------------------------------
 * 7. Admin menu
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
    add_menu_page(
        'Nation Club',
        'Nation Club',
        'manage_options',
        'nation-club',
        'nc_admin_topup_page',
        'dashicons-bank',
        30
    );
    add_submenu_page(
        'nation-club',
        'Top-up Requests',
        'Top-up Requests',
        'manage_options',
        'nation-club',
        'nc_admin_topup_page'
    );
    add_submenu_page(
        'nation-club',
        'Withdrawal Requests',
        'Withdrawal Requests',
        'manage_options',
        'nc-withdrawals',
        'nc_admin_withdrawal_page',
        1
    );
} );

/* -------------------------------------------------------------------------
 * 8. Admin page — Top-up Requests
 * ----------------------------------------------------------------------- */

function nc_admin_topup_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    // Handle approve/reject
    if ( isset( $_POST['nc_topup_action'] ) ) {
        nc_admin_topup_handle_action();
    }

    global $wpdb;
    $tables = nc_vendor_pool_tables();

    $filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
    $allowed = array( 'pending', 'approved', 'rejected', 'all' );
    if ( ! in_array( $filter, $allowed, true ) ) {
        $filter = 'pending';
    }

    // Search
    $search       = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
    $search_where = '';
    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $search_where = $wpdb->prepare(
            " AND (u.display_name LIKE %s
                OR u.user_email LIKE %s
                OR t.wise_reference LIKE %s
                OR t.topup_id LIKE %s
                OR t.admin_note LIKE %s
                OR t.transfer_date LIKE %s
                OR CAST(t.amount AS CHAR) LIKE %s
                OR CAST(t.id AS CHAR) LIKE %s)",
            $like, $like, $like, $like, $like, $like, $like, $like
        );
    }

    // Sort
    $orderby_allowed = array( 'id', 'amount', 'transfer_date' );
    $orderby_raw     = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
    $orderby         = in_array( $orderby_raw, $orderby_allowed, true ) ? $orderby_raw : 'id';
    $order           = ( isset( $_GET['order'] ) && strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) === 'asc' ) ? 'asc' : 'desc';

    $base_where = ( $filter === 'all' ) ? '1=1' : $wpdb->prepare( 'status = %s', $filter );
    $where      = $base_where . $search_where;

    $per_page = 20;
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$tables['topup']} t
         LEFT JOIN {$wpdb->users} u ON u.ID = t.vendor_id
         WHERE {$where}"
    );

    $rows = $total > 0 ? $wpdb->get_results( $wpdb->prepare(
        "SELECT t.*, u.display_name AS vendor_name, u.user_email AS vendor_email
         FROM {$tables['topup']} t
         LEFT JOIN {$wpdb->users} u ON u.ID = t.vendor_id
         WHERE {$where}
         ORDER BY t.{$orderby} {$order}
         LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ) ) : array();

    $total_pages = (int) ceil( $total / $per_page );

    $counts = $wpdb->get_row(
        "SELECT
            SUM(status='pending')  AS pending,
            SUM(status='approved') AS approved,
            SUM(status='rejected') AS rejected,
            COUNT(*) AS total
         FROM {$tables['topup']}"
    );
    ?>
    <div class="wrap">
        <h1>Top-up Requests</h1>

        <?php if ( isset( $_GET['nc_admin_msg'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_msg'] ) ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['nc_admin_err'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_err'] ) ) ); ?></p></div>
        <?php endif; ?>

        <ul class="subsubsub">
            <?php foreach ( array( 'pending', 'approved', 'rejected', 'all' ) as $f ) :
                $tab_args = array( 'page' => 'nation-club', 'status' => $f );
                if ( $search !== '' ) { $tab_args['s'] = $search; }
                ?>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( $tab_args, admin_url( 'admin.php' ) ) ); ?>"
                       class="<?php echo $filter === $f ? 'current' : ''; ?>">
                        <?php echo esc_html( ucfirst( $f ) ); ?>
                        <span class="count">(<?php
                            echo esc_html( (int) ( $f === 'all' ? $counts->total : $counts->{$f} ) );
                        ?>)</span>
                    </a>
                    <?php if ( $f !== 'all' ) echo ' |'; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <form method="get" style="float:right;margin:8px 0">
            <input type="hidden" name="page" value="nation-club">
            <input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>">
            <p class="search-box" style="margin:0">
                <label class="screen-reader-text" for="nc-topup-search-input">Search top-ups</label>
                <input type="search" id="nc-topup-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Vendor, TU-ID, Wise ref, amount…">
                <input type="submit" class="button" value="Search">
                <?php if ( $search !== '' ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'nation-club', 'status' => $filter ), admin_url( 'admin.php' ) ) ); ?>" class="button-link" style="margin-left:6px">Clear</a>
                <?php endif; ?>
            </p>
        </form>
        <div style="clear:both"></div>

        <table class="wp-list-table widefat fixed striped" style="margin-top:10px">
            <thead>
                <tr>
                    <?php nc_admin_sortable_th( 'ID', 'id', $orderby, $order ); ?>
                    <th>Vendor</th>
                    <?php nc_admin_sortable_th( 'Amount', 'amount', $orderby, $order ); ?>
                    <?php nc_admin_sortable_th( 'Transfer Date', 'transfer_date', $orderby, $order ); ?>
                    <th>Wise Ref</th>
                    <th>Proof</th>
                    <th>Pool Balance</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="10">No top-up requests.</td></tr>
            <?php else : foreach ( $rows as $row ) :
                $balance = nc_vendor_pool_balance( $row->vendor_id );
                ?>
                <tr>
                    <td>#<?php echo esc_html( $row->id ); ?><?php if ( $row->topup_id ) echo '<br><small>' . esc_html( $row->topup_id ) . '</small>'; ?></td>
                    <td>
                        <strong><?php echo esc_html( $row->vendor_name ?: 'User #' . $row->vendor_id ); ?></strong><br>
                        <small><?php echo esc_html( $row->vendor_email ); ?></small>
                    </td>
                    <td><strong><?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                    <td><?php echo esc_html( $row->transfer_date ?: '—' ); ?></td>
                    <td><?php echo esc_html( $row->wise_reference ?: '—' ); ?></td>
                    <td>
                        <?php if ( $row->attachment_id ) :
                            $proof_url = esc_url( add_query_arg(
                                array(
                                    'action'   => 'nc_view_topup_proof',
                                    'topup_id' => $row->id,
                                    '_wpnonce' => wp_create_nonce( 'nc_view_topup_proof_' . $row->id ),
                                ),
                                admin_url( 'admin-post.php' )
                            ) );
                            ?>
                            <a href="<?php echo $proof_url; ?>" target="_blank" rel="noopener">View</a>
                        <?php else : ?>—<?php endif; ?>
                    </td>
                    <td><?php echo esc_html( number_format( $balance, 2 ) ); ?></td>
                    <td><?php echo esc_html( mysql2date( 'M j, Y H:i', $row->created_at ) ); ?></td>
                    <td>
                        <span class="nc-status nc-status--<?php echo esc_attr( $row->status ); ?>" style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;text-transform:capitalize;
                            <?php
                            echo $row->status === 'pending' ? 'background:#fff3cd;color:#856404;' :
                                ($row->status === 'approved' ? 'background:#d4edda;color:#155724;' :
                                'background:#f8d7da;color:#721c24;');
                            ?>"><?php echo esc_html( $row->status ); ?></span>
                        <?php if ( $row->reviewed_at ) : ?>
                            <br><small>by <?php $r = get_userdata( $row->reviewed_by ); echo esc_html( $r ? $r->display_name : '#' . $row->reviewed_by ); ?><br>
                            <?php echo esc_html( mysql2date( 'M j, Y', $row->reviewed_at ) ); ?></small>
                        <?php endif; ?>
                        <?php if ( $row->admin_note ) : ?>
                            <br><small><em>"<?php echo esc_html( $row->admin_note ); ?>"</em></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $row->status === 'pending' ) : ?>
                            <form method="post" style="display:inline;margin-bottom:6px">
                                <?php wp_nonce_field( 'nc_topup_action_' . $row->id ); ?>
                                <input type="hidden" name="nc_topup_action" value="approve">
                                <input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <input type="text" name="admin_note" placeholder="Note (optional)" style="width:140px"><br>
                                <button class="button button-primary" onclick="return confirm('Approve and credit <?php echo esc_attr( number_format( (float) $row->amount, 2 ) ); ?> points to vendor pool?');">Approve &amp; Credit</button>
                            </form>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'nc_topup_action_' . $row->id ); ?>
                                <input type="hidden" name="nc_topup_action" value="reject">
                                <input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <input type="text" name="admin_note" placeholder="Reason" style="width:140px" required><br>
                                <button class="button" onclick="return confirm('Reject this top-up request?');">Reject</button>
                            </form>
                        <?php else : ?>
                            <span style="color:#888">—</span>
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

function nc_admin_topup_handle_action() {
    $action     = sanitize_key( wp_unslash( $_POST['nc_topup_action'] ) );
    $request_id = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;
    check_admin_referer( 'nc_topup_action_' . $request_id );

    if ( ! $request_id ) {
        return;
    }

    global $wpdb;
    $tables = nc_vendor_pool_tables();

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['topup']} WHERE id = %d", $request_id ) );
    if ( ! $row || $row->status !== 'pending' ) {
        wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Request not found or already processed.' ), wp_get_referer() ) );
        exit;
    }

    $admin_id   = get_current_user_id();
    $admin_note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';

    if ( 'approve' === $action ) {
        if ( ! function_exists( 'mycred_add' ) ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'myCRED not available.' ), wp_get_referer() ) );
            exit;
        }

        $topup_id = nc_vendor_generate_topup_id( $row->id );

        $log_data = wp_json_encode( array(
            'topup_request_id' => (int) $row->id,
            'topup_id'         => $topup_id,
            'wise_reference'   => $row->wise_reference,
            'transfer_date'    => $row->transfer_date,
            'attachment_id'    => (int) $row->attachment_id,
            'approved_by'      => $admin_id,
        ) );

        $entry = sprintf( 'Vendor top-up %s — SGD %s', $topup_id, number_format( (float) $row->amount, 2 ) );

        $ok = mycred_add(
            'vendor_topup',
            (int) $row->vendor_id,
            (float) $row->amount,
            $entry,
            $row->id,
            $log_data
        );

        if ( ! $ok ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'myCRED rejected the credit.' ), wp_get_referer() ) );
            exit;
        }

        // Find the log id we just created
        $log_tbl = $wpdb->prefix . 'myCRED_log';
        $log_id  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$log_tbl}
             WHERE user_id = %d AND ref = 'vendor_topup' AND ref_id = %d
             ORDER BY id DESC LIMIT 1",
            (int) $row->vendor_id,
            (int) $row->id
        ) );

        $wpdb->update( $tables['topup'], array(
            'status'        => 'approved',
            'topup_id'      => $topup_id,
            'mycred_log_id' => $log_id ?: null,
            'admin_note'    => $admin_note,
            'reviewed_by'   => $admin_id,
            'reviewed_at'   => current_time( 'mysql' ),
        ), array( 'id' => $row->id ) );

        wp_safe_redirect( add_query_arg( 'nc_admin_msg', rawurlencode( $topup_id . ' approved and credited.' ), wp_get_referer() ) );
        exit;
    }

    if ( 'reject' === $action ) {
        if ( $admin_note === '' ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Rejection reason is required.' ), wp_get_referer() ) );
            exit;
        }
        $wpdb->update( $tables['topup'], array(
            'status'      => 'rejected',
            'admin_note'  => $admin_note,
            'reviewed_by' => $admin_id,
            'reviewed_at' => current_time( 'mysql' ),
        ), array( 'id' => $row->id ) );

        wp_safe_redirect( add_query_arg( 'nc_admin_msg', rawurlencode( 'Request rejected.' ), wp_get_referer() ) );
        exit;
    }
}

/* -------------------------------------------------------------------------
 * 9. Admin page — Withdrawal Requests
 * ----------------------------------------------------------------------- */

function nc_admin_withdrawal_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    if ( isset( $_POST['nc_wd_action'] ) ) {
        nc_admin_withdrawal_handle_action();
    }

    global $wpdb;
    $tables = nc_vendor_pool_tables();

    $filter  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
    $allowed = array( 'pending', 'approved', 'completed', 'rejected', 'all' );
    if ( ! in_array( $filter, $allowed, true ) ) {
        $filter = 'pending';
    }

    // Search
    $search       = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
    $search_where = '';
    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $search_where = $wpdb->prepare(
            " AND (u.display_name LIKE %s
                OR u.user_email LIKE %s
                OR w.withdrawal_id LIKE %s
                OR w.wise_reference LIKE %s
                OR w.admin_note LIKE %s
                OR w.vendor_note LIKE %s
                OR w.statement_month LIKE %s
                OR CAST(w.amount AS CHAR) LIKE %s
                OR CAST(w.id AS CHAR) LIKE %s)",
            $like, $like, $like, $like, $like, $like, $like, $like, $like
        );
    }

    // Sort
    $orderby_allowed = array( 'id', 'amount', 'request_date' );
    $orderby_raw     = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
    $orderby         = in_array( $orderby_raw, $orderby_allowed, true ) ? $orderby_raw : 'id';
    $order           = ( isset( $_GET['order'] ) && strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) === 'asc' ) ? 'asc' : 'desc';

    $base_where = ( $filter === 'all' ) ? '1=1' : $wpdb->prepare( 'status = %s', $filter );
    $where      = $base_where . $search_where;

    $per_page = 20;
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$tables['withdrawal']} w
         LEFT JOIN {$wpdb->users} u ON u.ID = w.vendor_id
         WHERE {$where}"
    );

    $rows = $total > 0 ? $wpdb->get_results( $wpdb->prepare(
        "SELECT w.*, u.display_name AS vendor_name, u.user_email AS vendor_email
         FROM {$tables['withdrawal']} w
         LEFT JOIN {$wpdb->users} u ON u.ID = w.vendor_id
         WHERE {$where}
         ORDER BY w.{$orderby} {$order}
         LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ) ) : array();

    $total_pages = (int) ceil( $total / $per_page );

    $counts = $wpdb->get_row(
        "SELECT
            SUM(status='pending')    AS pending,
            SUM(status='approved')   AS approved,
            SUM(status='completed')  AS completed,
            SUM(status='rejected')   AS rejected,
            COUNT(*)                 AS total
         FROM {$tables['withdrawal']}"
    );
    ?>
    <div class="wrap">
        <h1>Withdrawal Requests</h1>

        <?php if ( isset( $_GET['nc_admin_msg'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_msg'] ) ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['nc_admin_err'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc_admin_err'] ) ) ); ?></p></div>
        <?php endif; ?>

        <ul class="subsubsub">
            <?php foreach ( array( 'pending', 'approved', 'completed', 'rejected', 'all' ) as $f ) :
                $tab_args = array( 'page' => 'nc-withdrawals', 'status' => $f );
                if ( $search !== '' ) { $tab_args['s'] = $search; }
                ?>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( $tab_args, admin_url( 'admin.php' ) ) ); ?>"
                       class="<?php echo $filter === $f ? 'current' : ''; ?>">
                        <?php echo esc_html( ucfirst( $f ) ); ?>
                        <span class="count">(<?php echo esc_html( (int) ( $f === 'all' ? $counts->total : $counts->{$f} ) ); ?>)</span>
                    </a>
                    <?php if ( $f !== 'all' ) echo ' |'; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <form method="get" style="float:right;margin:8px 0">
            <input type="hidden" name="page" value="nc-withdrawals">
            <input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>">
            <p class="search-box" style="margin:0">
                <label class="screen-reader-text" for="nc-wd-search-input">Search withdrawals</label>
                <input type="search" id="nc-wd-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Vendor, WD-ID, Wise ref, amount…">
                <input type="submit" class="button" value="Search">
                <?php if ( $search !== '' ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'nc-withdrawals', 'status' => $filter ), admin_url( 'admin.php' ) ) ); ?>" class="button-link" style="margin-left:6px">Clear</a>
                <?php endif; ?>
            </p>
        </form>
        <div style="clear:both"></div>

        <table class="wp-list-table widefat fixed striped" style="margin-top:10px">
            <thead>
                <tr>
                    <?php nc_admin_sortable_th( 'ID', 'id', $orderby, $order ); ?>
                    <th>Vendor</th>
                    <?php nc_admin_sortable_th( 'Amount', 'amount', $orderby, $order ); ?>
                    <th>Statement</th>
                    <th>Pool Balance</th>
                    <?php nc_admin_sortable_th( 'Requested', 'request_date', $orderby, $order ); ?>
                    <th>Status / Dates</th>
                    <th>Wise Ref</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="9">No withdrawal requests.</td></tr>
            <?php else : foreach ( $rows as $row ) :
                $balance    = nc_vendor_pool_balance( $row->vendor_id );
                $after      = round( $balance - (float) $row->amount, 2 );
                $blocks_min = $after < NC_VENDOR_POOL_MIN_BALANCE;
                ?>
                <tr>
                    <td>#<?php echo esc_html( $row->id ); ?><?php if ( $row->withdrawal_id ) echo '<br><small>' . esc_html( $row->withdrawal_id ) . '</small>'; ?></td>
                    <td>
                        <strong><?php echo esc_html( $row->vendor_name ?: 'User #' . $row->vendor_id ); ?></strong><br>
                        <small><?php echo esc_html( $row->vendor_email ); ?></small>
                    </td>
                    <td>
                        <strong><?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong>
                        <?php if ( $row->vendor_note ) : ?>
                            <br><small><em>"<?php echo esc_html( $row->vendor_note ); ?>"</em></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $row->statement_month ?: '—' ); ?></td>
                    <td>
                        <?php echo esc_html( number_format( $balance, 2 ) ); ?>
                        <?php if ( $row->status === 'pending' ) : ?>
                            <br><small>After: <?php echo esc_html( number_format( $after, 2 ) ); ?></small>
                            <?php if ( $blocks_min ) : ?>
                                <br><small style="color:#b32d2e">⚠ Would drop below minimum</small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( mysql2date( 'M j, Y H:i', $row->request_date ) ); ?></td>
                    <td>
                        <span style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;text-transform:capitalize;
                            <?php
                            echo $row->status === 'pending'   ? 'background:#fff3cd;color:#856404;' :
                                ($row->status === 'approved'  ? 'background:#cce5ff;color:#004085;' :
                                ($row->status === 'completed' ? 'background:#d4edda;color:#155724;' :
                                'background:#f8d7da;color:#721c24;'));
                            ?>"><?php echo esc_html( $row->status ); ?></span>
                        <?php if ( $row->approval_date ) : ?>
                            <br><small>Approved: <?php echo esc_html( mysql2date( 'M j', $row->approval_date ) ); ?></small>
                        <?php endif; ?>
                        <?php if ( $row->processed_date ) : ?>
                            <br><small>Processed: <?php echo esc_html( mysql2date( 'M j', $row->processed_date ) ); ?></small>
                        <?php endif; ?>
                        <?php if ( $row->admin_note ) : ?>
                            <br><small><em>"<?php echo esc_html( $row->admin_note ); ?>"</em></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $row->wise_reference ?: '—' ); ?></td>
                    <td>
                        <?php if ( $row->status === 'pending' ) : ?>
                            <form method="post" style="display:inline;margin-bottom:6px">
                                <?php wp_nonce_field( 'nc_wd_action_' . $row->id ); ?>
                                <input type="hidden" name="nc_wd_action" value="approve">
                                <input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <input type="text" name="admin_note" placeholder="Note (optional)" style="width:140px"><br>
                                <button class="button button-primary" <?php if ( $blocks_min ) echo 'disabled'; ?>>Approve</button>
                            </form>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'nc_wd_action_' . $row->id ); ?>
                                <input type="hidden" name="nc_wd_action" value="reject">
                                <input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <input type="text" name="admin_note" placeholder="Reason" style="width:140px" required><br>
                                <button class="button">Reject</button>
                            </form>
                        <?php elseif ( $row->status === 'approved' ) : ?>
                            <form method="post">
                                <?php wp_nonce_field( 'nc_wd_action_' . $row->id ); ?>
                                <input type="hidden" name="nc_wd_action" value="process">
                                <input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <input type="text" name="wise_reference" placeholder="Wise Ref *" style="width:140px" required><br>
                                <input type="text" name="admin_note" placeholder="Note (optional)" style="width:140px;margin-top:4px"><br>
                                <button class="button button-primary" style="margin-top:4px" onclick="return confirm('Confirm Wise payout is processed and debit <?php echo esc_attr( number_format( (float) $row->amount, 2 ) ); ?> points from vendor pool?');">Mark Paid &amp; Debit</button>
                            </form>
                        <?php else : ?>
                            <span style="color:#888">—</span>
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

function nc_admin_withdrawal_handle_action() {
    $action     = sanitize_key( wp_unslash( $_POST['nc_wd_action'] ) );
    $request_id = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;
    check_admin_referer( 'nc_wd_action_' . $request_id );

    if ( ! $request_id ) {
        return;
    }

    global $wpdb;
    $tables = nc_vendor_pool_tables();

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['withdrawal']} WHERE id = %d", $request_id ) );
    if ( ! $row ) {
        wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Request not found.' ), wp_get_referer() ) );
        exit;
    }

    $admin_id   = get_current_user_id();
    $admin_note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';

    if ( 'approve' === $action ) {
        if ( $row->status !== 'pending' ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Only pending requests can be approved.' ), wp_get_referer() ) );
            exit;
        }

        // Re-verify rules at approval time — balance may have changed since submission
        $check = nc_vendor_can_request_withdrawal( $row->vendor_id, $row->amount );
        if ( ! $check['ok'] ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Cannot approve: ' . $check['reason'] ), wp_get_referer() ) );
            exit;
        }

        $wpdb->update( $tables['withdrawal'], array(
            'status'        => 'approved',
            'admin_note'    => $admin_note,
            'approved_by'   => $admin_id,
            'approval_date' => current_time( 'mysql' ),
        ), array( 'id' => $row->id ) );

        wp_safe_redirect( add_query_arg( 'nc_admin_msg', rawurlencode( 'Request approved. Process Wise payout, then mark as paid.' ), wp_get_referer() ) );
        exit;
    }

    if ( 'reject' === $action ) {
        if ( $row->status !== 'pending' ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Only pending requests can be rejected.' ), wp_get_referer() ) );
            exit;
        }
        if ( $admin_note === '' ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Rejection reason is required.' ), wp_get_referer() ) );
            exit;
        }
        $wpdb->update( $tables['withdrawal'], array(
            'status'        => 'rejected',
            'admin_note'    => $admin_note,
            'approved_by'   => $admin_id,
            'approval_date' => current_time( 'mysql' ),
        ), array( 'id' => $row->id ) );

        wp_safe_redirect( add_query_arg( 'nc_admin_msg', rawurlencode( 'Request rejected.' ), wp_get_referer() ) );
        exit;
    }

    if ( 'process' === $action ) {
        if ( $row->status !== 'approved' ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Only approved requests can be marked paid.' ), wp_get_referer() ) );
            exit;
        }

        $wise_ref = isset( $_POST['wise_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['wise_reference'] ) ) : '';
        if ( $wise_ref === '' ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Wise reference is required.' ), wp_get_referer() ) );
            exit;
        }

        if ( ! function_exists( 'mycred_add' ) ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'myCRED not available.' ), wp_get_referer() ) );
            exit;
        }

        // Re-check balance one last time before debiting
        $balance = nc_vendor_pool_balance( $row->vendor_id );
        if ( round( $balance - (float) $row->amount, 2 ) < NC_VENDOR_POOL_MIN_BALANCE ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'Cannot process: vendor pool would drop below minimum.' ), wp_get_referer() ) );
            exit;
        }

        $wd_id = nc_vendor_generate_withdrawal_id( $row->id );

        $log_data = wp_json_encode( array(
            'withdrawal_request_id' => (int) $row->id,
            'withdrawal_id'         => $wd_id,
            'wise_reference'        => $wise_ref,
            'processed_by'          => $admin_id,
            'approved_by'           => (int) $row->approved_by,
        ) );

        $entry = sprintf( 'Vendor withdrawal %s — SGD %s paid via Wise (%s)', $wd_id, number_format( (float) $row->amount, 2 ), $wise_ref );

        $ok = mycred_add(
            'vendor_withdrawal',
            (int) $row->vendor_id,
            -1 * (float) $row->amount,
            $entry,
            $row->id,
            $log_data
        );

        if ( ! $ok ) {
            wp_safe_redirect( add_query_arg( 'nc_admin_err', rawurlencode( 'myCRED rejected the debit.' ), wp_get_referer() ) );
            exit;
        }

        $log_tbl = $wpdb->prefix . 'myCRED_log';
        $log_id  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$log_tbl}
             WHERE user_id = %d AND ref = 'vendor_withdrawal' AND ref_id = %d
             ORDER BY id DESC LIMIT 1",
            (int) $row->vendor_id,
            (int) $row->id
        ) );

        $wpdb->update( $tables['withdrawal'], array(
            'status'         => 'completed',
            'withdrawal_id'  => $wd_id,
            'wise_reference' => $wise_ref,
            'mycred_log_id'  => $log_id ?: null,
            'admin_note'     => $admin_note ?: $row->admin_note,
            'processed_by'   => $admin_id,
            'processed_date' => current_time( 'mysql' ),
        ), array( 'id' => $row->id ) );

        wp_safe_redirect( add_query_arg( 'nc_admin_msg', rawurlencode( $wd_id . ' processed and debited.' ), wp_get_referer() ) );
        exit;
    }
}
