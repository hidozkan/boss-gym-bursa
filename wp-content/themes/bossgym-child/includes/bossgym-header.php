<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'theme_mod_header_desktop_items', 'bossgym_child_header_desktop_items', 20 );
function bossgym_child_header_desktop_items( $items ) {
	if ( ! is_array( $items ) ) { $items = array(); }
	if ( empty( $items['main'] ) || ! is_array( $items['main'] ) ) { $items['main'] = array(); }
	$items['main'] = array_merge( $items['main'], array(
		'main_left' => array( 'logo' ),
		'main_left_center' => array(),
		'main_center' => array( 'navigation' ),
		'main_right_center' => array(),
		'main_right' => array( 'html' ),
	) );
	return $items;
}
function bossgym_child_account_links() {
	if ( is_user_logged_in() ) {
		return array(
			array( 'label' => "Üye Panelim", 'url' => home_url( '/uye-paneli/' ), 'class' => 'bossgym-account--member' ),
			array( 'label' => "Çıkış Yap", 'url' => wp_logout_url( home_url( '/uye-paneli/' ) ), 'class' => 'bossgym-account--quiet' ),
		);
	}
	$membership_url = get_permalink( 16 ) ?: home_url( '/uyelikler/' );
	return array(
		array( 'label' => "Üye Girişi", 'url' => $membership_url . '#bgm-login', 'class' => 'bossgym-account--login' ),
		array( 'label' => "Kayıt Ol", 'url' => $membership_url . '#bgm-register', 'class' => 'bossgym-account--register' ),
	);
}

/** The reservation page has a dedicated header CTA, not a duplicate menu item. */
add_filter( 'wp_nav_menu_objects', 'bossgym_child_remove_reservation_menu_item', 20, 2 );
function bossgym_child_remove_reservation_menu_item( $items, $args ) {
	$reservation_url = get_permalink( 20 );
	foreach ( $items as $key => $item ) {
		$is_reservation_page = isset( $item->object, $item->object_id ) && 'page' === $item->object && 20 === absint( $item->object_id );
		$is_reservation_url = $reservation_url && isset( $item->url ) && untrailingslashit( $item->url ) === untrailingslashit( $reservation_url );
		if ( $is_reservation_page || $is_reservation_url ) { unset( $items[ $key ] ); }
	}
	return array_values( $items );
}

add_action( 'kadence_header_html', 'bossgym_child_header_account_actions', 20 );
add_filter( 'theme_mod_header_html_content', 'bossgym_child_header_html_default_text' );
function bossgym_child_header_html_default_text( $content ) {
	if ( is_string( $content ) && in_array( trim( wp_strip_all_tags( $content ) ), array( 'HTML kodunu buraya ekleyin', 'Insert HTML here' ), true ) ) { return ''; }
	return $content;
}
function bossgym_child_header_account_actions() {
	$reservation_url = get_permalink( 20 ) ?: home_url( '/rezervasyon/' );
	echo '<nav class="bossgym-header-actions" aria-label="Rezervasyon ve hesap bağlantıları">';
	echo '<a class="bossgym-header-action bossgym-header-action--reservation" href="' . esc_url( $reservation_url . '#online-saha-talebi' ) . '">Rezervasyon Yap</a>';
	foreach ( bossgym_child_account_links() as $link ) {
		echo '<a class="bossgym-header-action ' . esc_attr( $link['class'] ) . '" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
	}

	echo '</nav>';
}

add_filter( 'wp_nav_menu_items', 'bossgym_child_mobile_menu_items', 20, 2 );
function bossgym_child_mobile_menu_items( $items, $args ) {
	$is_mobile_menu = ! empty( $args->theme_location ) && 'mobile' === $args->theme_location;
	$is_mobile_menu = $is_mobile_menu || ( ! empty( $args->menu_id ) && 'mobile-menu' === $args->menu_id );
	if ( ! $is_mobile_menu ) { return $items; }
	$reservation_url = get_permalink( 20 ) ?: home_url( '/rezervasyon/' );
	$items .= '<li class="menu-item menu-item-type-custom bossgym-mobile-reservation-item"><a href="' . esc_url( $reservation_url . '#online-saha-talebi' ) . '">Rezervasyon Yap</a></li>';
	foreach ( bossgym_child_account_links() as $link ) {
		$items .= '<li class="menu-item menu-item-type-custom bossgym-mobile-account-item ' . esc_attr( $link['class'] ) . '"><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></li>';
	}

	return $items;
}

/** Keep Kadence's logo image using the transparent cropped header version. */
add_filter( 'wp_get_attachment_image_attributes', 'bossgym_child_header_logo_image_attributes', 20, 3 );
function bossgym_child_header_logo_image_attributes( $attr, $attachment, $size ) {
	$logo_id = absint( get_theme_mod( 'custom_logo' ) );
	if ( ! $logo_id || ! isset( $attachment->ID ) || $logo_id !== (int) $attachment->ID || false === strpos( (string) ( $attr['class'] ?? '' ), 'custom-logo' ) ) { return $attr; }
	$attr['src'] = get_stylesheet_directory_uri() . '/assets/images/bossgym-wordmark-header.png';
	$attr['width'] = 556;
	$attr['height'] = 82;
	unset( $attr['srcset'], $attr['sizes'] );
	return $attr;
}


function bossgym_accessibility_icon_markup() {
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="4.5" r="2" fill="currentColor"/><path d="M4 8.2c2.2-.9 4.7-1.3 8-1.3s5.8.4 8 1.3M12 7v6.2m0 0-4.7 7M12 13.2l4.6 7M7.7 10.2l4.3 3 4.4-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

add_action( 'wp_footer', 'bossgym_render_accessibility_panel', 15 );
function bossgym_render_accessibility_panel() {
    if ( is_admin() ) { return; }
    ?>
    <button class="bossgym-accessibility-trigger bossgym-accessibility-trigger--fixed" type="button" aria-label="Erişilebilirlik ayarlarını aç" aria-expanded="false" aria-controls="bossgym-accessibility-panel"><span aria-hidden="true"><?php echo bossgym_accessibility_icon_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></button>
    <section id="bossgym-accessibility-panel" class="bossgym-accessibility-panel" aria-labelledby="bossgym-accessibility-title" aria-describedby="bossgym-accessibility-help" hidden>
        <div class="bossgym-accessibility-panel__head">
            <h2 id="bossgym-accessibility-title" tabindex="-1">Erişilebilirlik ayarları</h2>
            <button type="button" class="bossgym-accessibility-close" data-accessibility-close aria-label="Erişilebilirlik ayarlarını kapat">×</button>
        </div>
        <p id="bossgym-accessibility-help" class="bossgym-accessibility-help">Görünüm ve okuma tercihleri yalnızca bu tarayıcıda saklanır.</p>
        <fieldset class="bossgym-accessibility-group">
            <legend>Renk teması</legend>
            <div class="bossgym-accessibility-controls" role="group" aria-label="Renk teması seçin">
                <button type="button" data-accessibility-theme="light" aria-pressed="false">Aydınlık</button>
                <button type="button" data-accessibility-theme="dark" aria-pressed="true">Karanlık</button>
            </div>
        </fieldset>
        <div class="bossgym-accessibility-group">
            <span class="bossgym-accessibility-label">Kontrast</span>
            <button type="button" data-accessibility-contrast aria-pressed="false">Yüksek kontrastı aç</button>
        </div>
        <div class="bossgym-accessibility-group">
            <span class="bossgym-accessibility-label">Yazı boyutu <span data-accessibility-scale aria-live="polite">100%</span></span>
            <div class="bossgym-accessibility-controls" role="group" aria-label="Yazı boyutu">
                <button type="button" data-accessibility-size="down" aria-label="Yazı boyutunu azalt">A−</button>
                <button type="button" data-accessibility-size="up" aria-label="Yazı boyutunu artır">A+</button>
                <button type="button" data-accessibility-size="reset">Sıfırla</button>
            </div>
        </div>
        <fieldset class="bossgym-accessibility-group">
            <legend>Türkçe sesli okuma</legend>
            <div class="bossgym-accessibility-controls" role="group" aria-label="Sesli okuma kontrolleri">
                <button type="button" data-speech-start>Başlat</button>
                <button type="button" data-speech-pause disabled>Duraklat</button>
                <button type="button" data-speech-resume disabled>Devam et</button>
                <button type="button" data-speech-stop disabled>Durdur</button>
            </div>
            <p class="bossgym-accessibility-speech-status" data-speech-status role="status" aria-live="polite" aria-atomic="true"></p>
        </fieldset>
        <button type="button" class="bossgym-accessibility-reset" data-accessibility-reset>Tüm erişilebilirlik ayarlarını sıfırla</button>
    </section>
    <?php
}
