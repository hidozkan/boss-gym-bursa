<?php
/** Boss Gym Bursa child theme setup. */

add_action( 'wp_enqueue_scripts', 'bossgym_child_enqueue_assets', 20 );
function bossgym_child_enqueue_assets() {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();
    $style_file = $theme_dir . '/style.css';
    $brand_file = $theme_dir . '/assets/css/brand.css';
    $script_file = $theme_dir . '/assets/js/brand.js';

    wp_enqueue_style(
        'bossgym-child-style',
        $theme_uri . '/style.css',
        array(),
        file_exists( $style_file ) ? (string) filemtime( $style_file ) : wp_get_theme()->get( 'Version' )
    );

    wp_enqueue_style(
        'bossgym-brand',
        $theme_uri . '/assets/css/brand.css',
        array( 'bossgym-child-style' ),
        file_exists( $brand_file ) ? (string) filemtime( $brand_file ) : wp_get_theme()->get( 'Version' )
    );

    $accessibility_css = $theme_dir . '/assets/css/accessibility.css';
    $accessibility_js = $theme_dir . '/assets/js/accessibility.js';
    wp_enqueue_style(
        'bossgym-accessibility',
        $theme_uri . '/assets/css/accessibility.css',
        array( 'bossgym-brand' ),
        file_exists( $accessibility_css ) ? (string) filemtime( $accessibility_css ) : wp_get_theme()->get( 'Version' )
    );
    wp_enqueue_script(
        'bossgym-accessibility',
        $theme_uri . '/assets/js/accessibility.js',
        array(),
        file_exists( $accessibility_js ) ? (string) filemtime( $accessibility_js ) : wp_get_theme()->get( 'Version' ),
        true
    );

    if ( ! is_front_page() ) {
        return;
    }

    wp_enqueue_script(
        'bossgym-brand',
        $theme_uri . '/assets/js/brand.js',
        array(),
        file_exists( $script_file ) ? (string) filemtime( $script_file ) : wp_get_theme()->get( 'Version' ),
        true
    );
}

add_action( 'init', 'bossgym_child_register_patterns' );
function bossgym_child_register_patterns() {
    if ( ! function_exists( 'register_block_pattern' ) ) {
        return;
    }

    register_block_pattern_category( 'bossgym', array( 'label' => __( 'Boss Gym Bursa', 'bossgym-child' ) ) );
    $badge = esc_url( get_stylesheet_directory_uri() . '/assets/images/bossgym-bursa-badge.png' );
    $content = <<<HTML
<!-- wp:group {"tagName":"div","className":"bossgym-home","layout":{"type":"constrained"}} -->
<div class="wp-block-group bossgym-home">
<!-- wp:group {"tagName":"section","className":"bg-hero alignfull","layout":{"type":"constrained"}} -->
<section class="wp-block-group bg-hero alignfull">
<!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">BURSA’DA HAREKETİN YENİ ADRESİ</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"bg-hero__title"} --><h1 class="wp-block-heading bg-hero__title"><span>GÜCÜNÜ KEŞFET,</span><br><span>SINIRLARINI AŞ!</span></h1><!-- /wp:heading -->
<!-- wp:paragraph {"className":"bg-hero__lead"} --><p class="bg-hero__lead">Antrenman, takım ruhu ve iyi yaşam aynı çatı altında.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"className":"bg-actions"} --><div class="wp-block-buttons bg-actions"><!-- wp:button {"className":"is-style-fill bg-button-gold"} --><div class="wp-block-button is-style-fill bg-button-gold"><a class="wp-block-button__link wp-element-button" href="#iletisim">Üyelik hakkında bilgi al</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline bg-button-outline"} --><div class="wp-block-button is-style-outline bg-button-outline"><a class="wp-block-button__link wp-element-button" href="#tesisler">Tesisleri keşfet</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","className":"bg-quick","layout":{"type":"constrained"}} --><section class="wp-block-group bg-quick"><!-- wp:columns {"className":"bg-card-grid"} --><div class="wp-block-columns bg-card-grid"><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-card bg-card--quick","layout":{"type":"constrained"}} --><div class="wp-block-group bg-card bg-card--quick"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Üye Ol</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Üyelik seçenekleri ve tesis kullanımı hakkında bilgi alın.</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"bg-card__link"} --><p class="bg-card__link"><a href="#iletisim">Üyelik için bize ulaşın →</a></p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-card bg-card--quick","layout":{"type":"constrained"}} --><div class="wp-block-group bg-card bg-card--quick"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">PT Randevusu</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Bireysel veya grup antrenmanı seçeneklerini ekibimizle görüşün.</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"bg-card__link"} --><p class="bg-card__link"><a href="#pt">PT eğitimlerini inceleyin →</a></p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-card bg-card--quick","layout":{"type":"constrained"}} --><div class="wp-block-group bg-card bg-card--quick"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Saha Kiralama</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Basketbol ve voleybol sahaları için uygunluk bilgisi alın.</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"bg-card__link"} --><p class="bg-card__link"><a href="#sahalar">Sahaları keşfedin →</a></p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --></div><!-- /wp:columns --></section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","anchor":"hakkimizda","className":"bg-section bg-about","layout":{"type":"constrained"}} --><section id="hakkimizda" class="wp-block-group bg-section bg-about"><!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"width":"34%"} --><div class="wp-block-column" style="flex-basis:34%"><!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"bg-badge"} --><figure class="wp-block-image size-large bg-badge"><img src="{$badge}" alt="Boss Gym Bursa amblemi"/></figure><!-- /wp:image --></div><!-- /wp:column --><!-- wp:column {"width":"66%"} --><div class="wp-block-column" style="flex-basis:66%"><!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">BOSS GYM BURSA</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Hareket için tasarlandı. Her seviyeye açık.</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Boss Gym Bursa; fitness antrenmanlarını, kişisel gelişimi ve saha sporlarını bir araya getiren bir spor kompleksidir. Hedefinize uygun bir başlangıç için tesisimizi keşfedin ve ekibimizle tanışın.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --></section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","anchor":"pt","className":"bg-section bg-section--dark","layout":{"type":"constrained"}} --><section id="pt" class="wp-block-group bg-section bg-section--dark"><!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">HEDEFİNE UYGUN ANTRENMAN</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Bireysel ve grup PT eğitimleri</h2><!-- /wp:heading --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-card bg-card--dark","layout":{"type":"constrained"}} --><div class="wp-block-group bg-card bg-card--dark"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Bireysel PT</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Hedeflerinize ve antrenman seviyenize göre kişisel çalışma planı için ekibimizle görüşün.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-card bg-card--dark","layout":{"type":"constrained"}} --><div class="wp-block-group bg-card bg-card--dark"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Grup PT</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Motivasyonu birlikte yükseltin. Grup derslerinin içeriği ve takvimi için bilgi alın.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:paragraph --><p>Size uygun antrenman biçimi ve ders takvimi için tesis ekibimizden güncel bilgi alın.</p><!-- /wp:paragraph --></section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","className":"bg-section bg-coach","layout":{"type":"constrained"}} --><section class="wp-block-group bg-section bg-coach"><!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">BİRLİKTE DAHA GÜÇLÜ</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Antrenör ekibimiz</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Antrenör kadrosu ve uzmanlık bilgileri netleştiğinde bu bölüm güncel profillerle tamamlanacaktır.</p><!-- /wp:paragraph --></section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","anchor":"sahalar","className":"bg-section bg-section--soft","layout":{"type":"constrained"}} --><section id="sahalar" class="wp-block-group bg-section bg-section--soft"><!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">TAKIMINI TOPLA</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Sahada buluşalım</h2><!-- /wp:heading --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-card bg-card--sport","layout":{"type":"constrained"}} --><div class="wp-block-group bg-card bg-card--sport"><!-- wp:paragraph {"className":"bg-card__number"} --><p class="bg-card__number">01 / 02</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Basketbol sahaları</h3><!-- /wp:heading --><!-- wp:paragraph --><p>İki basketbol sahamızda takımınızla antrenman veya maç planlayın. Uygunluk bilgisini tesisimizden alın.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-card bg-card--sport","layout":{"type":"constrained"}} --><div class="wp-block-group bg-card bg-card--sport"><!-- wp:paragraph {"className":"bg-card__number"} --><p class="bg-card__number">03</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Voleybol sahası</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Voleybol sahamızda oyun ve antrenman için saha kiralama bilgisi alın.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --></div><!-- /wp:columns --></section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","anchor":"tesisler","className":"bg-section bg-section--dark bg-facilities","layout":{"type":"constrained"}} --><section id="tesisler" class="wp-block-group bg-section bg-section--dark bg-facilities"><!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">ANTRENMANIN ÖTESİNDE</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">İyi hissetmeniz için gerekenler</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Soyunma odaları · Duş · Otopark · Kafe</p><!-- /wp:paragraph --></section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","className":"bg-section bg-smart","layout":{"type":"constrained"}} --><section class="wp-block-group bg-section bg-smart"><!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">YAKINDA / TANITIM</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Akıllı tesis deneyimi</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Demo QR turnike ve canlı doluluk paneli konseptimizi keşfedin. Bu tanıtım alanı aktif bir QR geçişi veya canlı doluluk verisi sağlamaz.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"bg-demo-panel","layout":{"type":"constrained"}} --><div class="wp-block-group bg-demo-panel"><!-- wp:paragraph {"className":"bg-demo-panel__label"} --><p class="bg-demo-panel__label">TESİS PANELİ · DEMO</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Canlı veri bağlantısı bulunmuyor</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Turnike ve doluluk entegrasyonu tamamlandığında panel buradan güncellenebilir.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column --></div><!-- /wp:columns --></section><!-- /wp:group -->
<!-- wp:group {"tagName":"section","anchor":"iletisim","className":"bg-section bg-contact","layout":{"type":"constrained"}} --><section id="iletisim" class="wp-block-group bg-section bg-contact"><!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"bg-contact__badge"} --><figure class="wp-block-image size-large bg-contact__badge"><img src="{$badge}" alt="Boss Gym Bursa"/></figure><!-- /wp:image --><!-- wp:paragraph {"className":"bg-eyebrow"} --><p class="bg-eyebrow">İLK ADIMI AT</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Hedefin ne olursa olsun, başlayacak yer burada.</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Üyelik, PT ve saha kullanımı hakkında bilgi almak için tesis ekibimizle iletişime geçin.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Güncel telefon, adres ve çalışma saatleri burada paylaşılacaktır.</p><!-- /wp:paragraph --></section><!-- /wp:group -->
</main><!-- /wp:group -->
HTML;

    register_block_pattern( 'bossgym-child/homepage', array(
        'title'       => __( 'Boss Gym Bursa Ana Sayfa', 'bossgym-child' ),
        'description' => __( 'Düzenlenebilir tanıtım, eğitim, saha ve iletişim bölümleri.', 'bossgym-child' ),
        'categories'  => array( 'bossgym', 'featured' ),
        'content'     => $content,
    ) );
}


/** Mark the custom-content pages so Kadence keeps their sections full width. */
add_filter( 'body_class', 'bossgym_child_subpage_body_class' );
function bossgym_child_subpage_body_class( $classes ) {
    if ( is_page( array( 'hakkimizda', 'uyelikler', 'pt-egitimleri', 'antrenorler', 'spor-sahalari', 'rezervasyon', 'tesis-olanaklari', 'iletisim' ) ) ) {
        $classes[] = 'bossgym-subpage';
    }
    return $classes;
}

require_once get_stylesheet_directory() . '/includes/bossgym-header.php';

/** Boss Gym site-wide footer actions and copyright, rendered through Kadence footer hooks. */
add_action( 'kadence_top_footer', 'bossgym_child_footer_actions', 1 );
function bossgym_child_footer_actions() {
    ?>
    <nav class="bossgym-footer-cta" aria-label="Hızlı bağlantılar">
        <a class="bossgym-footer-cta__link" href="<?php echo esc_url( home_url( '/uyelikler/' ) ); ?>">Üyelik seçenekleri</a>
        <a class="bossgym-footer-cta__link bossgym-footer-cta__link--primary" href="<?php echo esc_url( home_url( '/pt-egitimleri/' ) ); ?>">PT eğitimlerini keşfet</a>
        <a class="bossgym-footer-cta__link" href="<?php echo esc_url( home_url( '/tesis-olanaklari/' ) ); ?>">Tesis olanakları</a>
    </nav>
    <?php
}

add_action( 'wp', 'bossgym_child_replace_footer_copyright', 20 );
function bossgym_child_replace_footer_copyright() {
    if ( is_admin() ) {
        return;
    }
    remove_action( 'kadence_footer_html', 'Kadence\\footer_html' );
    add_action( 'kadence_footer_html', 'bossgym_child_footer_copyright' );
}

function bossgym_child_footer_copyright() {
    echo '<p class="bossgym-footer-copyright">&copy; 2026 Boss Gym Bursa. Tüm hakları saklıdır.</p>';
}
if ( ! shortcode_exists( 'bossgym_contact_map' ) ) {
    add_shortcode( 'bossgym_contact_map', 'bossgym_child_contact_map_shortcode' );
}
function bossgym_child_contact_map_shortcode() {
    $map_url = 'https://www.google.com/maps?q=23%20Nisan%20Mahallesi%2C%20240.%20Sokak%20No%3A%202%2C%20Nil%C3%BCfer%2C%20Bursa&output=embed';
    return '<div class="bg-map-embed"><iframe title="Boss Gym Bursa adresi için Google Haritalar" src="' . esc_url( $map_url ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>';
}


// Kadence supplies the skip link; localize its visible accessible name without changing its target.
add_filter( 'gettext', 'bossgym_localize_skip_link', 20, 3 );
function bossgym_localize_skip_link( $translation, $text, $domain ) {
    if ( 'kadence' === $domain && 'Skip to content' === $text ) {
        return 'Ana içeriğe geç';
    }
    return $translation;
}

add_filter( 'render_block', 'bossgym_home_landmark_cleanup', 10, 2 );
function bossgym_home_landmark_cleanup( $content, $block ) {
    if ( 'core/group' !== ( $block['blockName'] ?? '' ) || empty( $block['attrs']['className'] ) || ! preg_match( '/(?:^|\s)bossgym-home(?:\s|$)/', $block['attrs']['className'] ) ) {
        return $content;
    }
    $content = preg_replace( '/^<main(\s[^>]*)?>/', '<div$1>', $content, 1 );
    return preg_replace( '/<\/main>\s*$/', '</div>', $content, 1 );
}

// Some legacy homepage content is stored as raw HTML instead of a core/group block.
// Keep Kadence's main landmark as the single main landmark on that rendered page.
add_filter( 'the_content', 'bossgym_home_raw_content_landmark_cleanup', 99 );
function bossgym_home_raw_content_landmark_cleanup( $content ) {
    if ( ! is_page( 9 ) || ! is_main_query() ) {
        return $content;
    }

    $content = preg_replace( '/<main\b([^>]*class="[^"]*\bbossgym-home\b[^"]*"[^>]*)>/', '<div$1>', $content, 1, $count );
    if ( ! $count ) {
        return $content;
    }

    $closing_main = strrpos( $content, '</main>' );
    if ( false === $closing_main ) {
        return $content;
    }

    return substr_replace( $content, '</div>', $closing_main, 7 );
}