<?php
/**
 * Admin → Nation Club → Log → Points log
 *
 * Displays the contents of `wp-content/uploads/mycred-debug.log` (written by
 * nc_debug()). Lets admin tail the log, refresh it, download it, or clear it.
 *
 * Tail size is capped to the last 10,000 lines so the page stays responsive
 * on a noisy log file. Admin can download the full log if they need more.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 1. Admin menu — Nation Club → Log
 * ----------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Points log',
        'Log',
        'manage_options',
        'nc-log',
        'nc_log_admin_page',
        7
    );
}, 16 );

/* -------------------------------------------------------------------------
 * 2. Helpers
 * ----------------------------------------------------------------------- */

function nc_log_file_path() {
    if ( ! defined( 'WP_CONTENT_DIR' ) ) {
        return '';
    }
    return trailingslashit( WP_CONTENT_DIR ) . 'mycred-debug.log';
}

/**
 * Read the last N lines of a file efficiently (seeks from the end).
 */
function nc_log_tail( $path, $max_lines = 10000 ) {
    if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
        return array( 'lines' => array(), 'truncated' => false, 'size' => 0 );
    }

    $size = filesize( $path );
    if ( $size === 0 ) {
        return array( 'lines' => array(), 'truncated' => false, 'size' => 0 );
    }

    $f = @fopen( $path, 'rb' );
    if ( ! $f ) {
        return array( 'lines' => array(), 'truncated' => false, 'size' => $size );
    }

    $buffer    = '';
    $lines     = array();
    $chunk_sz  = 8192;
    $position  = $size;
    $remaining = $max_lines;

    while ( $position > 0 && $remaining > 0 ) {
        $read_sz  = (int) min( $chunk_sz, $position );
        $position -= $read_sz;
        fseek( $f, $position );
        $buffer = fread( $f, $read_sz ) . $buffer;

        // Pull complete lines off the end of the buffer
        while ( $remaining > 0 ) {
            $nl = strrpos( $buffer, "\n" );
            if ( $nl === false ) {
                if ( $position === 0 ) {
                    // Whole file fits — emit the buffer as one line and stop
                    $lines[]   = $buffer;
                    $buffer    = '';
                    $remaining--;
                }
                break;
            }
            $line = substr( $buffer, $nl + 1 );
            $buffer = substr( $buffer, 0, $nl );
            if ( $line !== '' ) {
                $lines[] = $line;
                $remaining--;
            }
        }
    }

    fclose( $f );

    $truncated = ( $remaining === 0 && $position > 0 );
    $lines     = array_reverse( $lines );

    return array( 'lines' => $lines, 'truncated' => $truncated, 'size' => $size );
}

/* -------------------------------------------------------------------------
 * 3. Action handlers (clear, download)
 * ----------------------------------------------------------------------- */

add_action( 'admin_post_nc_log_clear', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    check_admin_referer( 'nc_log_clear' );

    $path = nc_log_file_path();
    if ( $path && file_exists( $path ) ) {
        @file_put_contents( $path, '' );
    }

    wp_safe_redirect( add_query_arg( array( 'page' => 'nc-log', 'cleared' => 1 ), admin_url( 'admin.php' ) ) );
    exit;
} );

add_action( 'admin_post_nc_log_download', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    check_admin_referer( 'nc_log_download' );

    $path = nc_log_file_path();
    if ( ! $path || ! file_exists( $path ) ) {
        wp_die( 'Log file not found.' );
    }

    nocache_headers();
    header( 'Content-Type: text/plain; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="mycred-debug-' . wp_date( 'Y-m-d-His' ) . '.log"' );
    header( 'Content-Length: ' . filesize( $path ) );
    readfile( $path );
    exit;
} );

/* -------------------------------------------------------------------------
 * 4. Admin page
 * ----------------------------------------------------------------------- */
function nc_log_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    $path   = nc_log_file_path();
    $exists = $path && file_exists( $path );
    $tail   = $exists ? nc_log_tail( $path, 10000 ) : array( 'lines' => array(), 'truncated' => false, 'size' => 0 );

    $clear_url    = wp_nonce_url( admin_url( 'admin-post.php?action=nc_log_clear' ), 'nc_log_clear' );
    $download_url = wp_nonce_url( admin_url( 'admin-post.php?action=nc_log_download' ), 'nc_log_download' );
    ?>
    <div class="wrap">
        <h1>Points log</h1>

        <?php if ( ! empty( $_GET['cleared'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>
        <?php endif; ?>

        <p style="color:#555;max-width:780px">
            Live tail of <code><?php echo esc_html( $path ?: '(upload dir unavailable)' ); ?></code>.
            This is the debug log written by the plugin's earn / redeem / batch / expiry hooks.
            <?php if ( $exists ) : ?>
                <br><small style="color:#888">File size: <?php echo esc_html( size_format( $tail['size'] ) ); ?> · Showing latest <?php echo esc_html( number_format_i18n( count( $tail['lines'] ) ) ); ?> line(s)<?php if ( $tail['truncated'] ) echo ' (truncated — older entries hidden)'; ?></small>
            <?php endif; ?>
        </p>

        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=nc-log' ) ); ?>" class="button">Refresh</a>
            <?php if ( $exists ) : ?>
                <a href="<?php echo esc_url( $download_url ); ?>" class="button">Download full log</a>
                <a href="<?php echo esc_url( $clear_url ); ?>" class="button button-secondary"
                   onclick="return confirm('Clear the entire log file? This cannot be undone.');">Clear log</a>
            <?php endif; ?>
        </p>

        <?php if ( ! $exists ) : ?>
            <div class="notice notice-info inline"><p>Log file does not exist yet. It will be created the first time the plugin writes a debug entry.</p></div>
        <?php elseif ( empty( $tail['lines'] ) ) : ?>
            <div class="notice notice-info inline"><p>Log file is empty.</p></div>
        <?php else : ?>
            <pre class="nc-log-pane"><?php
                foreach ( $tail['lines'] as $line ) {
                    echo esc_html( $line ) . "\n";
                }
            ?></pre>
        <?php endif; ?>
    </div>

    <style>
        .nc-log-pane {
            background: #1e1e1e;
            color: #e6e6e6;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12.5px;
            line-height: 1.55;
            padding: 14px 16px;
            border-radius: 6px;
            max-height: 70vh;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            margin-top: 8px;
        }
    </style>
    <?php
}
