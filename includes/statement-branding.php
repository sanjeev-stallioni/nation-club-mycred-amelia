<?php
/**
 * Statement Branding — scope item 8.
 *
 * Backend settings (Nation Club → Statement Branding) that customise the
 * vendor statement PDF only:
 *   - Header text
 *   - Logo (top-left of the statement)
 *   - Background colour + optional background image
 *
 * The saved values are read by nc_statement_build_pdf_html() in
 * vendor-statements.php and injected into the PDF / vendor-portal preview.
 * The admin statement view is intentionally left unbranded.
 *
 * Images are embedded into the PDF as base64 data URIs because the PDF engine
 * (dompdf) cannot fetch remote URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Current branding settings, with sensible defaults.
 *
 * @return array{header:string,logo_id:int,bg_color:string,bg_image_id:int}
 */
function nc_statement_branding() {
    $header = trim( (string) get_option( 'nc_stmt_brand_header', '' ) );
    if ( $header === '' ) {
        $header = get_bloginfo( 'name' ) . ' — Monthly Statement';
    }

    $note = trim( (string) get_option( 'nc_stmt_brand_note', '' ) );
    if ( $note === '' ) {
        $note = nc_statement_branding_default_note();
    }

    $footer = trim( (string) get_option( 'nc_stmt_brand_footer', '' ) );
    if ( $footer === '' ) {
        $footer = 'For disputes / clarification, please contact Nation Club admin.';
    }

    return array(
        'header'      => $header,
        'logo_id'     => (int) get_option( 'nc_stmt_brand_logo_id', 0 ),
        'bg_color'    => (string) get_option( 'nc_stmt_brand_bg_color', '' ),
        'bg_image_id' => (int) get_option( 'nc_stmt_brand_bg_image_id', 0 ),
        'note'        => $note,
        'footer'      => $footer,
    );
}

/**
 * Default statement note (client-provided). Used when the admin hasn't set one.
 */
function nc_statement_branding_default_note() {
    return 'Note: Shared costs, subscription fees and other cash charges are separate from the Nation Club Points Pool. If one Wise payment is made for both shared costs and Points Pool top-up, shared costs will be allocated first, and only the remaining balance will be credited to the Vendor’s Points Pool.';
}

/**
 * Base64 data URI for an uploaded image so dompdf can embed it directly.
 * Returns '' when the attachment is missing or unreadable.
 */
function nc_statement_branding_data_uri( $attachment_id ) {
    $attachment_id = (int) $attachment_id;
    if ( $attachment_id <= 0 ) {
        return '';
    }

    $path = get_attached_file( $attachment_id );
    if ( ! $path || ! file_exists( $path ) ) {
        return '';
    }

    $data = @file_get_contents( $path );
    if ( $data === false ) {
        return '';
    }

    $type = get_post_mime_type( $attachment_id ) ?: 'image/png';

    return 'data:' . $type . ';base64,' . base64_encode( $data );
}

/* -------------------------------------------------------------------------
 * Admin page
 * ----------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'nation-club',
        'Statement Branding',
        'Statement Branding',
        'manage_options',
        'nc-statement-branding',
        'nc_statement_branding_page',
        3
    );
}, 12 );

add_action( 'admin_enqueue_scripts', function () {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'nc-statement-branding' ) {
        wp_enqueue_media();
    }
} );

function nc_statement_branding_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['nc_brand_save'] ) && check_admin_referer( 'nc_stmt_branding' ) ) {
        update_option( 'nc_stmt_brand_header', sanitize_text_field( wp_unslash( $_POST['brand_header'] ?? '' ) ) );
        update_option( 'nc_stmt_brand_logo_id', (int) ( $_POST['brand_logo_id'] ?? 0 ) );
        update_option( 'nc_stmt_brand_bg_image_id', (int) ( $_POST['brand_bg_image_id'] ?? 0 ) );

        $color = sanitize_hex_color( trim( (string) ( $_POST['brand_bg_color'] ?? '' ) ) );
        update_option( 'nc_stmt_brand_bg_color', $color ? $color : '' );

        update_option( 'nc_stmt_brand_note', sanitize_textarea_field( wp_unslash( $_POST['brand_note'] ?? '' ) ) );
        update_option( 'nc_stmt_brand_footer', sanitize_textarea_field( wp_unslash( $_POST['brand_footer'] ?? '' ) ) );

        echo '<div class="notice notice-success is-dismissible"><p>Branding saved.</p></div>';
    }

    $b        = nc_statement_branding();
    $logo_url = $b['logo_id'] ? wp_get_attachment_image_url( $b['logo_id'], 'medium' ) : '';
    $bg_url   = $b['bg_image_id'] ? wp_get_attachment_image_url( $b['bg_image_id'], 'medium' ) : '';
    ?>
    <div class="wrap">
        <h1>Statement Branding</h1>
        <p>These apply to the <strong>vendor statement PDF</strong> (and the vendor portal preview). The admin statement view is left unchanged.</p>

        <form method="post">
            <?php wp_nonce_field( 'nc_stmt_branding' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="brand_header">Header text</label></th>
                    <td>
                        <input type="text" id="brand_header" name="brand_header" class="regular-text" value="<?php echo esc_attr( $b['header'] ); ?>">
                        <p class="description">Shown at the top of the statement. Default: site name + " — Monthly Statement".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Logo</th>
                    <td>
                        <input type="hidden" id="brand_logo_id" name="brand_logo_id" value="<?php echo esc_attr( $b['logo_id'] ); ?>">
                        <div id="brand_logo_preview" style="margin-bottom:8px"><?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:70px;border:1px solid #ddd;padding:4px;background:#fff"><?php endif; ?></div>
                        <button type="button" class="button" id="brand_logo_select">Select logo</button>
                        <button type="button" class="button" id="brand_logo_remove"<?php echo $b['logo_id'] ? '' : ' style="display:none"'; ?>>Remove</button>
                        <p class="description">Appears top-left of the statement PDF.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="brand_bg_color">Background colour</label></th>
                    <td>
                        <input type="text" id="brand_bg_color" name="brand_bg_color" value="<?php echo esc_attr( $b['bg_color'] ); ?>" placeholder="#ffffff" style="max-width:140px">
                        <p class="description">Hex colour, e.g. <code>#f7f2f4</code>. Leave blank for white.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Background image <span style="font-weight:400;color:#888">(optional)</span></th>
                    <td>
                        <input type="hidden" id="brand_bg_image_id" name="brand_bg_image_id" value="<?php echo esc_attr( $b['bg_image_id'] ); ?>">
                        <div id="brand_bg_preview" style="margin-bottom:8px"><?php if ( $bg_url ) : ?><img src="<?php echo esc_url( $bg_url ); ?>" style="max-height:90px;border:1px solid #ddd;padding:4px;background:#fff"><?php endif; ?></div>
                        <button type="button" class="button" id="brand_bg_select">Select image</button>
                        <button type="button" class="button" id="brand_bg_remove"<?php echo $b['bg_image_id'] ? '' : ' style="display:none"'; ?>>Remove</button>
                        <p class="description">Optional. Use a light / print-friendly image so the statement text stays readable.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="brand_note">Note text</label></th>
                    <td>
                        <textarea id="brand_note" name="brand_note" rows="4" class="large-text"><?php echo esc_textarea( $b['note'] ); ?></textarea>
                        <p class="description">Shown near the bottom of the statement PDF. Leave blank to use the default note.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="brand_footer">Footer text</label></th>
                    <td>
                        <textarea id="brand_footer" name="brand_footer" rows="2" class="large-text"><?php echo esc_textarea( $b['footer'] ); ?></textarea>
                        <p class="description">Appears under the "Generated by … on …" line. Leave blank to use the default.</p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="nc_brand_save" value="1" class="button button-primary">Save Branding</button></p>
        </form>
    </div>

    <script>
    ( function ( $ ) {
        function picker( selectBtn, removeBtn, idInput, previewBox, title ) {
            var frame;
            $( selectBtn ).on( 'click', function ( e ) {
                e.preventDefault();
                if ( frame ) { frame.open(); return; }
                frame = wp.media( { title: title, button: { text: 'Use this image' }, multiple: false } );
                frame.on( 'select', function () {
                    var a   = frame.state().get( 'selection' ).first().toJSON();
                    var url = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
                    $( idInput ).val( a.id );
                    $( previewBox ).html( '<img src="' + url + '" style="max-height:80px;border:1px solid #ddd;padding:4px;background:#fff">' );
                    $( removeBtn ).show();
                } );
                frame.open();
            } );
            $( removeBtn ).on( 'click', function ( e ) {
                e.preventDefault();
                $( idInput ).val( '' );
                $( previewBox ).empty();
                $( this ).hide();
            } );
        }
        picker( '#brand_logo_select', '#brand_logo_remove', '#brand_logo_id', '#brand_logo_preview', 'Select logo' );
        picker( '#brand_bg_select', '#brand_bg_remove', '#brand_bg_image_id', '#brand_bg_preview', 'Select background image' );
    } )( jQuery );
    </script>
    <?php
}
