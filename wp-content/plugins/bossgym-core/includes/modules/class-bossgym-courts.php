<?php
if (!defined('ABSPATH')) { exit; }

/** Court booking and referee request module. Reservation times are stored in UTC. */
final class BossGym_Courts {
    const DB_VERSION = '1.0.0';
    private $reservations;
    private $referees;

    public function __construct() {
        global $wpdb;
        $this->reservations = $wpdb->prefix . 'bg_court_reservations';
        $this->referees = $wpdb->prefix . 'bg_referee_requests';
        add_action('init', [$this, 'register_court_type'], 5);
        add_action('init', [$this, 'maybe_install'], 25);
        add_action('add_meta_boxes_bg_court', [$this, 'add_court_metabox']);
        add_action('save_post_bg_court', [$this, 'save_court'], 10, 2);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'front_assets']);
        add_action('template_redirect', [$this, 'booking_page_no_cache'], 0);
        add_action('admin_post_bossgym_court_book', [$this, 'book']);
        add_action('admin_post_nopriv_bossgym_court_book', [$this, 'book']);
        add_action('admin_post_bossgym_court_status', [$this, 'update_reservation_status']);
        add_action('admin_post_bossgym_referee_status', [$this, 'update_referee_status']);
        add_action('wp_ajax_bossgym_court_slots', [$this, 'ajax_slots']);
        add_action('wp_ajax_nopriv_bossgym_court_slots', [$this, 'ajax_slots']);
        add_action('wp_ajax_bossgym_court_refresh_nonces', [$this, 'ajax_refresh_nonces']);
        add_shortcode('bossgym_court_list', [$this, 'court_list_shortcode']);
        add_shortcode('bossgym_court_booking', [$this, 'booking_shortcode']);
        add_filter('bossgym_dashboard_output', [$this, 'dashboard_output'], 20, 2);
        add_filter('manage_bg_court_posts_columns', [$this, 'court_columns']);
        add_action('manage_bg_court_posts_custom_column', [$this, 'court_column'], 10, 2);
    }

    public function register_court_type() {
        register_post_type('bg_court', [
            'labels' => [
                'name' => 'Saha Yönetimi', 'singular_name' => 'Saha',
                'add_new_item' => 'Saha ekle', 'edit_item' => 'Sahayı düzenle',
                'new_item' => 'Yeni saha', 'view_item' => 'Sahayı görüntüle',
                'search_items' => 'Sahalarda ara', 'not_found' => 'Saha bulunamadı',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => false,
            'show_in_rest' => true, 'supports' => ['title', 'editor'],
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
    }

    public function maybe_install() {
        if (get_option('bossgym_courts_db_version') === self::DB_VERSION) { return; }
        if (!self::install_tables()) { return; }
        $this->seed_courts();
        $this->append_page_shortcode(19, 'bossgym_court_list');
        $this->append_page_shortcode(20, 'bossgym_court_booking');
        update_option('bossgym_courts_db_version', self::DB_VERSION, false);
    }

    private static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $reservations = $wpdb->prefix . 'bg_court_reservations';
        $referees = $wpdb->prefix . 'bg_referee_requests';
        dbDelta("CREATE TABLE {$reservations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            court_id bigint(20) unsigned NOT NULL,
            start_utc datetime NOT NULL,
            end_utc datetime NOT NULL,
            duration_minutes smallint(5) unsigned NOT NULL,
            hourly_rate decimal(10,2) NOT NULL DEFAULT 0.00,
            quoted_total decimal(10,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'pending',
            note text NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY court_time (court_id,start_utc,end_utc),
            KEY user_time (user_id,start_utc),
            KEY status_time (status,start_utc)
        ) ENGINE=InnoDB {$collate};");
        dbDelta("CREATE TABLE {$referees} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reservation_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'requested',
            admin_note text NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_id (reservation_id),
            KEY user_id (user_id),
            KEY status (status)
        ) ENGINE=InnoDB {$collate};");
        $reservations_exist = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($reservations))) === $reservations;
        $referees_exist = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($referees))) === $referees;
        return $reservations_exist && $referees_exist;
    }

    private function seed_courts() {
        $days = [];
        for ($day = 1; $day <= 7; $day++) { $days[$day] = ['open' => '07:00', 'close' => '23:00']; }
        $defaults = [
            ['basketbol-sahasi-1', 'Basketbol Sahası 1', 'basketball', 800, 'basketbol-sahasi.webp', 'Demo saha kaydıdır. Fiyat ve çalışma saatleri temsilîdir; rezervasyon yönetici onayıyla kesinleşir.'],
            ['basketbol-sahasi-2', 'Basketbol Sahası 2', 'basketball', 800, 'basketbol-sahasi.webp', 'Demo saha kaydıdır. Fiyat ve çalışma saatleri temsilîdir; rezervasyon yönetici onayıyla kesinleşir.'],
            ['voleybol-sahasi-1', 'Voleybol Sahası 1', 'volleyball', 650, 'voleybol-sahasi.webp', 'Demo saha kaydıdır. Fiyat ve çalışma saatleri temsilîdir; rezervasyon yönetici onayıyla kesinleşir.'],
        ];
        foreach ($defaults as $index => $item) {
            if (get_page_by_path($item[0], OBJECT, 'bg_court')) { continue; }
            $id = wp_insert_post([
                'post_type' => 'bg_court', 'post_status' => 'publish', 'post_name' => $item[0],
                'post_title' => $item[1], 'post_content' => $item[5], 'menu_order' => $index + 1,
            ], true);
            if (is_wp_error($id) || !$id) { continue; }
            update_post_meta($id, '_bgc_sport', $item[2]);
            update_post_meta($id, '_bgc_hourly_rate', (string) $item[3]);
            update_post_meta($id, '_bgc_min_minutes', '60');
            update_post_meta($id, '_bgc_max_minutes', '180');
            update_post_meta($id, '_bgc_hours', $days);
            update_post_meta($id, '_bgc_active', '1');
            update_post_meta($id, '_bgc_default_image', $item[4]);
        }
    }

    private function append_page_shortcode($page_id, $shortcode) {
        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page' || has_shortcode($page->post_content, $shortcode)) { return; }
        $content = rtrim($page->post_content) . "\n\n<!-- wp:shortcode -->\n[{$shortcode}]\n<!-- /wp:shortcode -->\n";
        wp_update_post(['ID' => (int) $page_id, 'post_content' => $content]);
    }

    public function add_court_metabox() {
        add_meta_box('bossgym-court-details', 'Saha bilgileri ve çalışma düzeni', [$this, 'render_court_metabox'], 'bg_court', 'normal', 'high');
    }

    public function render_court_metabox($post) {
        $sport = get_post_meta($post->ID, '_bgc_sport', true) ?: 'basketball';
        $rate = get_post_meta($post->ID, '_bgc_hourly_rate', true) ?: '800';
        $min = (int) (get_post_meta($post->ID, '_bgc_min_minutes', true) ?: 60);
        $max = (int) (get_post_meta($post->ID, '_bgc_max_minutes', true) ?: 180);
        $hours = get_post_meta($post->ID, '_bgc_hours', true);
        $maintenance = get_post_meta($post->ID, '_bgc_maintenance', true);
        if (!is_array($hours)) { $hours = []; }
        if (!is_array($maintenance)) { $maintenance = []; }
        wp_nonce_field('bgc_save_court_' . $post->ID, '_bgc_nonce');
        echo '<div class="bossgym-admin bossgym-admin--meta bgc-court-meta">';
        echo '<p class="description">Saatlik bedel ve başlangıç saatleri demo amaçlıdır. Gerçek ücretleri, açıklamayı ve fotoğrafı burada düzenleyin.</p>';
        echo '<div class="bossgym-admin__field"><label><strong>Spor türü</strong><select name="bgc_sport"><option value="basketball" ' . selected($sport, 'basketball', false) . '>Basketbol</option><option value="volleyball" ' . selected($sport, 'volleyball', false) . '>Voleybol</option></select></label></div>';
        echo '<div class="bossgym-admin__field"><label><strong>Saatlik kiralama bedeli (temsili TL)</strong><input type="number" min="0" step="0.01" name="bgc_rate" value="' . esc_attr($rate) . '"></label></div>';
        echo '<div class="bossgym-admin__field"><label><strong>En kısa rezervasyon süresi (dakika)</strong><select name="bgc_min">';
        foreach ([30, 60, 90, 120] as $value) { echo '<option value="' . $value . '" ' . selected($min, $value, false) . '>' . $value . ' dakika</option>'; }
        echo '</select></label></div><div class="bossgym-admin__field"><label><strong>En uzun rezervasyon süresi (dakika)</strong><select name="bgc_max">';
        foreach ([60, 90, 120, 150, 180, 240, 300, 360] as $value) { echo '<option value="' . $value . '" ' . selected($max, $value, false) . '>' . $value . ' dakika</option>'; }
        echo '</select></label></div>';
        $image_id = absint(get_post_meta($post->ID, '_bgc_image_id', true));
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        echo '<div class="bossgym-admin__field bgc-image-field"><strong>Saha fotoğrafı</strong><input type="hidden" name="bgc_image_id" value="' . $image_id . '"><div class="bgc-image-preview">' . ($image_url ? '<img src="' . esc_url($image_url) . '" alt="">' : '<span>Varsayılan temsilî görsel kullanılacak.</span>') . '</div><button type="button" class="button bgc-select-image">Medya Kütüphanesinden seç</button> <button type="button" class="button bgc-remove-image">Görseli kaldır</button></div>';
        echo '<div class="bossgym-admin__field bgc-active-field"><label><input type="checkbox" name="bgc_active" value="1" ' . checked(get_post_meta($post->ID, '_bgc_active', true), '1', false) . '> Rezervasyona açık (aktif)</label></div>';
        echo '<div class="bossgym-admin__field bgc-hours-field"><strong>Açılış/kapanış saatleri — WordPress saat dilimi</strong><table class="widefat striped bgc-hours-table"><thead><tr><th>Gün</th><th>Açılış</th><th>Kapanış</th><th>Durum</th></tr></thead><tbody>';
        $labels = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
        foreach ($labels as $day => $label) {
            $row = $hours[$day] ?? ['open' => '07:00', 'close' => '23:00'];
            echo '<tr><th>' . esc_html($label) . '</th><td><input type="time" name="bgc_hours[' . $day . '][open]" value="' . esc_attr($row['open'] ?? '') . '"></td><td><input type="time" name="bgc_hours[' . $day . '][close]" value="' . esc_attr($row['close'] ?? '') . '"></td><td><label><input type="checkbox" name="bgc_days[]" value="' . $day . '" ' . checked(array_key_exists($day, $hours), true, false) . '> Açık</label></td></tr>';
        }
        echo '</tbody></table></div>';
        $lines = [];
        foreach ($maintenance as $block) { $lines[] = implode('|', [$block['date'] ?? '', $block['start'] ?? '', $block['end'] ?? '', $block['reason'] ?? '']); }
        echo '<div class="bossgym-admin__field bgc-maintenance-field"><label><strong>Bakım/kapalı zaman aralıkları</strong><textarea name="bgc_maintenance" rows="6" placeholder="2026-12-01|09:00|12:00|Bakım">' . esc_textarea(implode("\n", $lines)) . '</textarea></label><span class="description">Her satıra tarih|başlangıç|bitiş|açıklama yazın. Örnek: 2026-12-01|09:00|12:00|Bakım. Saatler yerel WordPress saat dilimindedir.</span></div>';
        echo '</div>';
    }

    public function save_court($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (wp_is_post_revision($post_id) || !$post || $post->post_type !== 'bg_court') { return; }
        if (!current_user_can('manage_options') || empty($_POST['_bgc_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bgc_nonce'])), 'bgc_save_court_' . $post_id)) { return; }
        $sport = sanitize_key(wp_unslash($_POST['bgc_sport'] ?? ''));
        if (!in_array($sport, ['basketball', 'volleyball'], true)) { return; }
        $rate_raw = sanitize_text_field(wp_unslash($_POST['bgc_rate'] ?? ''));
        if (!is_numeric($rate_raw) || (float) $rate_raw < 0 || (float) $rate_raw > 1000000) { return; }
        $min = absint($_POST['bgc_min'] ?? 0); $max = absint($_POST['bgc_max'] ?? 0);
        if (!in_array($min, [30, 60, 90, 120], true) || !in_array($max, [60, 90, 120, 150, 180, 240, 300, 360], true) || $min > $max) { return; }
        $hours_input = wp_unslash($_POST['bgc_hours'] ?? []);
        $open_days = array_map('absint', (array) wp_unslash($_POST['bgc_days'] ?? []));
        $hours = [];
        for ($day = 1; $day <= 7; $day++) {
            $row = is_array($hours_input[$day] ?? null) ? $hours_input[$day] : [];
            $open = sanitize_text_field($row['open'] ?? ''); $close = sanitize_text_field($row['close'] ?? '');
            if (in_array($day, $open_days, true) && $this->valid_time($open) && $this->valid_time($close) && $open < $close) { $hours[$day] = ['open' => $open, 'close' => $close]; }
        }
        $maintenance_text = sanitize_textarea_field(wp_unslash($_POST['bgc_maintenance'] ?? ''));
        $maintenance = [];
        foreach (array_slice(preg_split('/\r\n|\r|\n/', $maintenance_text), 0, 100) as $line) {
            $parts = array_map('trim', explode('|', $line, 4));
            if (count($parts) < 3 || !$this->valid_date($parts[0]) || !$this->valid_time($parts[1]) || !$this->valid_time($parts[2]) || $parts[1] >= $parts[2]) { continue; }
            $maintenance[] = ['date' => $parts[0], 'start' => $parts[1], 'end' => $parts[2], 'reason' => sanitize_text_field($parts[3] ?? '')];
        }
        update_post_meta($post_id, '_bgc_sport', $sport);
        update_post_meta($post_id, '_bgc_hourly_rate', number_format((float) $rate_raw, 2, '.', ''));
        update_post_meta($post_id, '_bgc_min_minutes', (string) $min);
        update_post_meta($post_id, '_bgc_max_minutes', (string) $max);
        update_post_meta($post_id, '_bgc_hours', $hours);
        update_post_meta($post_id, '_bgc_maintenance', $maintenance);
        update_post_meta($post_id, '_bgc_active', !empty($_POST['bgc_active']) ? '1' : '0');
        $image_id = absint($_POST['bgc_image_id'] ?? 0);
        if ($image_id && wp_attachment_is_image($image_id)) { update_post_meta($post_id, '_bgc_image_id', $image_id); }
        else { delete_post_meta($post_id, '_bgc_image_id'); }
    }

    public function court_columns($columns) {
        $columns['bgc_sport'] = 'Spor türü'; $columns['bgc_rate'] = 'Saatlik demo bedeli'; $columns['bgc_active'] = 'Rezervasyon';
        return $columns;
    }

    public function court_column($column, $post_id) {
        if ($column === 'bgc_sport') { echo esc_html(get_post_meta($post_id, '_bgc_sport', true) === 'volleyball' ? 'Voleybol' : 'Basketbol'); }
        if ($column === 'bgc_rate') { echo esc_html(number_format_i18n((float) get_post_meta($post_id, '_bgc_hourly_rate', true), 2)) . ' TL / saat (temsili)'; }
        if ($column === 'bgc_active') { echo get_post_meta($post_id, '_bgc_active', true) === '1' ? 'Aktif' : 'Pasif'; }
    }

    public function admin_menu() {
        add_submenu_page('bossgym-overview', 'Saha Rezervasyonları', 'Saha Rezervasyonları', 'manage_options', 'bossgym-court-bookings', [$this, 'admin_reservations']);
        add_submenu_page('bossgym-overview', 'Saha Yönetimi', 'Saha Yönetimi', 'manage_options', 'edit.php?post_type=bg_court');
        add_submenu_page('bossgym-overview', 'Hakem Talepleri', 'Hakem Talepleri', 'manage_options', 'bossgym-referees', [$this, 'admin_referees']);
    }

    public function admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type === 'bg_court' && in_array($hook, ['post.php', 'post-new.php'], true)) {
            wp_enqueue_media();
            wp_enqueue_script('bossgym-courts-admin', BOSSGYM_CORE_URL . 'assets/js/courts-admin.js', ['jquery'], (string) filemtime(BOSSGYM_CORE_DIR . 'assets/js/courts-admin.js'), true);
        }
    }

    public function front_assets() {
        if (!is_page(9) && !is_front_page() && !is_page(19) && !is_page(20) && !is_page(35)) { return; }
        $css = BOSSGYM_CORE_DIR . 'assets/css/courts.css';
        $js = BOSSGYM_CORE_DIR . 'assets/js/courts.js';
        wp_enqueue_style('bossgym-courts', BOSSGYM_CORE_URL . 'assets/css/courts.css', [], file_exists($css) ? (string) filemtime($css) : BOSSGYM_CORE_VERSION);
        if (is_page(19)) {
            $carousel = BOSSGYM_CORE_DIR . 'assets/js/courts-carousel.js';
            wp_enqueue_script('bossgym-courts-carousel', BOSSGYM_CORE_URL . 'assets/js/courts-carousel.js', [], file_exists($carousel) ? (string) filemtime($carousel) : BOSSGYM_CORE_VERSION, true);
        }
        if (is_page(20)) {
            wp_enqueue_script('bossgym-courts', BOSSGYM_CORE_URL . 'assets/js/courts.js', [], file_exists($js) ? (string) filemtime($js) : BOSSGYM_CORE_VERSION, true);
            wp_localize_script('bossgym-courts', 'BossGymCourts', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('bgc_slots'), 'refreshAction' => 'bossgym_court_refresh_nonces', 'loggedIn' => is_user_logged_in(), 'loading' => 'Müsait saatler yükleniyor…', 'empty' => 'Bu tarih ve süre için uygun saat bulunamadı.']);
        }
    }

    public function court_list_shortcode() {
        $courts = get_posts(['post_type' => 'bg_court', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_bgc_active', 'meta_value' => '1', 'orderby' => 'menu_order', 'order' => 'ASC']);
        if (!$courts) { return '<section class="bgc-courts"><p>Şu anda rezervasyona açık saha bulunmuyor.</p></section>'; }
        $gallery_base = get_stylesheet_directory_uri() . '/assets/images/courts/';
        $photos = [
            'basketball' => [
                ['basketball-general.webp', 'Basketbol sahasının genel görünümü'],
                ['basketball-sideline.webp', 'Basketbol sahasına kenar çizgisinden bakış'],
                ['basketball-hoop-detail.webp', 'Basketbol potası ve saha çizgisi detayı'],
                ['basketball-elevated-view.webp', 'Basketbol sahasının yüksek açıdan görünümü'],
            ],
            'volleyball' => [
                ['volleyball-overview.webp', 'Voleybol sahasının genel görünümü'],
                ['volleyball-net-detail.webp', 'Voleybol filesi ve saha çizgileri detayı'],
                ['volleyball-side-angle.webp', 'Voleybol sahasına yan açıdan bakış'],
                ['volleyball-corner-view.webp', 'Voleybol sahasının yüksek açıdan görünümü'],
            ],
        ];
        $basketball_one_photos = [
            ['basketbol-1-01.webp', 'Basketbol Sahası 1 genel açı'],
            ['basketbol-1-02.webp', 'Basketbol Sahası 1 ikinci görünüm'],
            ['basketbol-1-03.webp', 'Basketbol Sahası 1 saha detayı'],
            ['basketbol-1-04.webp', 'Basketbol Sahası 1 farklı açı'],
        ];
        $html = '<section class="bgc-courts bgc-court-gallery" aria-label="Spor sahaları"><p class="bgc-courts__notice">Saha fotoğrafları konsept amaçlıdır ve mevcut tesisi birebir temsil etmeyebilir. Fiyatlar örnek niteliğindedir; rezervasyon yönetici onayıyla kesinleşir ve ödeme alınmaz.</p><div class="bgc-court-grid">';
        foreach ($courts as $court) {
            $sport = get_post_meta($court->ID, '_bgc_sport', true);
            $rate = (float) get_post_meta($court->ID, '_bgc_hourly_rate', true);
            $booking = add_query_arg('court', (int) $court->ID, get_permalink(20)) . '#online-saha-talebi';
            $sport_key = $sport === 'volleyball' ? 'volleyball' : 'basketball';
            $slides = [];
            $court_photos = sanitize_title($court->post_title) === 'basketbol-sahasi-1' ? $basketball_one_photos : $photos[$sport_key];
            foreach ($court_photos as $photo) {
                $slides[] = ['src' => trailingslashit($gallery_base) . rawurlencode($photo[0]), 'alt' => get_the_title($court) . ': ' . $photo[1]];
            }
            $first = $slides[0];
            $court_title = get_the_title($court);
            $description = trim((string) $court->post_content);
            if ($description === '' || strpos($description, 'Demo saha kaydıdır.') !== false) {
                $description = 'Düzenli saha kullanımı ve uygunluk bilgisi için rezervasyon talebi gönderin. Başvurular yönetici onayıyla kesinleşir.';
            }
            $html .= '<article class="bgc-court-card"><div class="bgc-court-slider" data-court-slider data-court-slides="' . esc_attr(wp_json_encode($slides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) . '" aria-label="' . esc_attr($court_title . ' fotoğraf galerisi') . '">';
            $html .= '<a class="bgc-court-slider__photo-link" href="' . esc_url($booking) . '" aria-label="' . esc_attr('Rezervasyon yap: ' . $court_title) . '"><img class="bgc-court-card__image" data-court-slide-image src="' . esc_url($first['src']) . '" alt="' . esc_attr($first['alt']) . '" loading="lazy" decoding="async"></a>';
            $html .= '<button class="bgc-court-slider__arrow bgc-court-slider__arrow--prev" type="button" data-court-prev aria-label="' . esc_attr('Önceki fotoğraf: ' . $court_title) . '"><span aria-hidden="true">‹</span></button><button class="bgc-court-slider__arrow bgc-court-slider__arrow--next" type="button" data-court-next aria-label="' . esc_attr('Sonraki fotoğraf: ' . $court_title) . '"><span aria-hidden="true">›</span></button><span class="bgc-court-slider__count" data-court-count role="status" aria-live="polite" aria-atomic="true">1 / 4</span><span class="bgc-court-slider__error" data-court-image-error role="status" hidden>Fotoğraf şu anda yüklenemiyor.</span></div>';
            $html .= '<a class="bgc-court-card__link" href="' . esc_url($booking) . '"><div class="bgc-court-card__body"><span class="bgc-eyebrow">' . esc_html($sport === 'volleyball' ? 'Voleybol' : 'Basketbol') . '</span><h2>' . esc_html($court_title) . '</h2><div class="bgc-court-card__description">' . wp_kses_post(wpautop($description)) . '</div><p class="bgc-court-card__price">' . esc_html(number_format_i18n($rate, 0)) . ' TL / saat</p><span class="bgc-button">Rezervasyon Yap</span></div></a></article>';
        }
        $html .= '</div></section>';
        return $html;
    }

    public function booking_shortcode() {
        if (!is_user_logged_in()) {
            return '<section id="online-saha-talebi" class="bgc-booking bgc-card"><h2>Online saha talebi</h2><p>Rezervasyon talebi oluşturmak için önce üye girişi yapın. Onay öncesinde saat kesinleşmez; ödeme alınmaz.</p><a class="bgc-button" href="' . esc_url(get_permalink(16) . '#bgm-login') . '">Üye girişi</a></section>';
        }
        $courts = get_posts(['post_type' => 'bg_court', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_bgc_active', 'meta_value' => '1', 'orderby' => 'menu_order', 'order' => 'ASC']);
        if (!$courts) { return '<section class="bgc-booking bgc-card"><h2>Saha rezervasyonu</h2><p>Şu anda rezervasyona açık saha bulunmuyor.</p></section>'; }
        $restore = $this->consume_booking_form_state();
        $selected = absint($restore['court_id'] ?? ($_GET['court'] ?? 0));
        $today = current_datetime()->format('Y-m-d');
        $html = '<section id="online-saha-talebi" class="bgc-booking bgc-card"><span id="bgc-booking" class="bgc-booking-anchor-alias" aria-hidden="true"></span><div class="bgc-eyebrow">ONLINE SAHA TALEBİ</div><h2>Rezervasyon talebi oluştur</h2><p class="bgc-note">Fiyatlar ve çalışma saatleri temsili demodur. Talep yönetici onayına kadar beklemededir; ödeme alınmaz.</p>';
        if (!empty($_GET['bgc'])) { $html .= $this->front_notice(sanitize_key(wp_unslash($_GET['bgc']))); }
        $html .= '<form class="bgc-booking-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-bgc-booking><input type="hidden" name="action" value="bossgym_court_book">';
        $html .= wp_nonce_field('bgc_book', '_bgc_nonce', false, false);
        $html .= '<div class="bgc-form-grid"><label><span>Saha</span><select name="court_id" required data-bgc-court><option value="">Saha seçin</option>';
        foreach ($courts as $court) {
            $sport = get_post_meta($court->ID, '_bgc_sport', true);
            $rate = (float) get_post_meta($court->ID, '_bgc_hourly_rate', true);
            $min = (int) (get_post_meta($court->ID, '_bgc_min_minutes', true) ?: 60);
            $max = (int) (get_post_meta($court->ID, '_bgc_max_minutes', true) ?: 180);
            $html .= '<option value="' . (int) $court->ID . '" data-rate="' . esc_attr($rate) . '" data-min="' . $min . '" data-max="' . $max . '" ' . selected($selected, $court->ID, false) . '>' . esc_html(get_the_title($court) . ' — ' . ($sport === 'volleyball' ? 'Voleybol' : 'Basketbol')) . '</option>';
        }
        $html .= '</select></label><label><span>Tarih</span><input type="date" name="date" min="' . esc_attr($today) . '" value="' . esc_attr($restore['date'] ?? '') . '" required data-bgc-date></label><label><span>Kiralama süresi</span><select name="duration" required data-bgc-duration data-bgc-initial-duration="' . esc_attr($restore['duration'] ?? '') . '"><option value="">Önce saha seçin</option></select></label><label><span>Müsait başlangıç saati</span><select name="time" required data-bgc-time data-bgc-initial-time="' . esc_attr($restore['time'] ?? '') . '" disabled><option value="">Önce saha ve tarih seçin</option></select></label><div class="bgc-slot-grid" role="group" aria-label="Saha uygunluk saatleri"></div><p class="bgc-slot-status" role="status" aria-live="polite" aria-atomic="true"></p></div>';
        $html .= '<fieldset class="bgc-referee-choice"><legend>İsteğe bağlı hizmet</legend><label><input type="checkbox" name="referee" value="1" ' . checked(!empty($restore['referee']), true, false) . '> Hakem hizmeti talep ediyorum</label><p>Talep, rezervasyonla birlikte yönetime iletilir. Hakem ataması ve ek ücret bu demo sürümünde kesinleşmez.</p></fieldset>';
        $html .= '<label class="bgc-note-field"><span>Not (isteğe bağlı)</span><textarea name="note" rows="3" maxlength="1000" placeholder="Rezervasyonla ilgili kısa not">' . esc_textarea($restore['note'] ?? '') . '</textarea></label><div class="bgc-booking-summary" data-bgc-summary aria-live="polite">Saha, tarih ve süre seçtiğinizde temsili tutar burada gösterilir.</div><button class="bgc-button" type="submit" data-bgc-submit disabled>Rezervasyon talebi gönder</button></form></section>';
        return $html;
    }

    private function court_image_url($court) {
        $image_id = absint(get_post_meta($court->ID, '_bgc_image_id', true));
        if ($image_id) { $url = wp_get_attachment_image_url($image_id, 'large'); if ($url) { return $url; } }
        $file = get_post_meta($court->ID, '_bgc_default_image', true);
        if (!$file || in_array(basename($file), ['voleybol-sahasi.webp', 'basketbol-sahasi.webp'], true)) {
            $file = get_post_meta($court->ID, '_bgc_sport', true) === 'volleyball' ? 'volleyball-overview.webp' : 'basketball-general.webp';
        }
        $basename = basename($file);
        $court_dir = get_stylesheet_directory() . '/assets/images/courts/';
        $home_dir = get_stylesheet_directory() . '/assets/images/home/';
        if (file_exists($court_dir . $basename)) {
            return get_stylesheet_directory_uri() . '/assets/images/courts/' . rawurlencode($basename);
        }
        if (file_exists($home_dir . $basename)) {
            return get_stylesheet_directory_uri() . '/assets/images/home/' . rawurlencode($basename);
        }
        $fallback = get_post_meta($court->ID, '_bgc_sport', true) === 'volleyball' ? 'volleyball-overview.webp' : 'basketball-general.webp';
        return get_stylesheet_directory_uri() . '/assets/images/courts/' . rawurlencode($fallback);
    }

    private function front_notice($key) {
        $messages = [
            'created' => ['success', 'Rezervasyon talebiniz alındı. Yönetici onayı bekleniyor; bu bir ödeme veya kesinleşmiş rezervasyon değildir.'],
            'conflict' => ['error', 'Saat dolu veya başka bir başvuru tarafından tutuldu. Lütfen güncel müsait saatlerden birini seçin.'],
            'invalid' => ['error', 'Form bilgileri geçersiz. Lütfen saha, tarih, süre ve başlangıç saatini kontrol edin.'],
            'invalid_nonce' => ['error', 'Formun güvenlik anahtarı yenilenmiş. Sayfayı tazeleyip rezervasyon talebini yeniden gönderin.'],
            'invalid_court' => ['error', 'Seçilen saha artık rezervasyona açık değil. Lütfen başka bir saha seçin.'],
            'invalid_date' => ['error', 'Seçilen tarih geçersiz. Lütfen takvimden geçerli bir gün seçin.'],
            'invalid_time' => ['error', 'Başlangıç saati geçersiz. Lütfen müsait saatlerden birini seçin.'],
            'invalid_duration' => ['error', 'Geçersiz süre. Lütfen bu saha için sunulan kiralama sürelerinden birini seçin.'],
            'past' => ['error', 'Geçmiş bir tarih veya saat için rezervasyon oluşturulamaz.'],
            'closed' => ['error', 'Seçilen tarih saha çalışma takviminde kapalı.'],
            'outside_hours' => ['error', 'Seçilen saat çalışma saatleri dışında veya rezervasyon kapanış saatini aşıyor.'],
            'maintenance' => ['error', 'Seçilen saat saha bakım/kapalı zaman aralığıyla çakışıyor.'],
            'membership' => ['error', 'Rezervasyon için aktif üyelik gereklidir.'],
            'error' => ['error', 'Talep kaydedilemedi. Lütfen daha sonra yeniden deneyin.'],
        ];
        if (!isset($messages[$key])) { return ''; }
        return '<div class="bgc-notice bgc-notice--' . esc_attr($messages[$key][0]) . '" role="status">' . esc_html($messages[$key][1]) . '</div>';
    }

    public function ajax_slots() {
        check_ajax_referer('bgc_slots', 'nonce');
        $court_id = absint($_POST['court_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $duration_raw = isset($_POST['duration']) && is_scalar($_POST['duration']) ? trim((string) wp_unslash($_POST['duration'])) : '';
        if (!preg_match('/^\d+$/', $duration_raw)) { $this->redirect_front('invalid_duration'); }
        $duration = (int) $duration_raw;
        $slots = $this->available_slots($court_id, $date, $duration);
        if (is_wp_error($slots)) { wp_send_json_error(['message' => $slots->get_error_message()], 400); }
        wp_send_json_success(['slots' => $slots, 'availability' => $this->availability_grid($court_id, $date, $duration, $slots)]);
    }

    /** Issue fresh nonces to the authenticated current session. This endpoint is read-only. */
    public function ajax_refresh_nonces() {
        if (!is_user_logged_in() || strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['message' => 'Rezervasyon oturumu bulunamadı. Lütfen yeniden giriş yapın.'], 401);
        }
        nocache_headers();
        wp_send_json_success(['booking' => wp_create_nonce('bgc_book'), 'slots' => wp_create_nonce('bgc_slots')]);
    }

    public function booking_page_no_cache() {
        if (is_page(20)) {
            if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
            nocache_headers();
        }
    }

    private function availability_grid($court_id, $date, $duration, $available) {
        $hours = get_post_meta($court_id, '_bgc_hours', true);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        if (!$day) { return []; }
        $schedule = is_array($hours) ? ($hours[(int) $day->format('N')] ?? null) : null;
        if (!$schedule || !$this->valid_time($schedule['open'] ?? '') || !$this->valid_time($schedule['close'] ?? '') || $schedule['open'] >= $schedule['close']) { return []; }
        $open = $day->setTime((int) substr($schedule['open'], 0, 2), (int) substr($schedule['open'], 3, 2));
        $close = $day->setTime((int) substr($schedule['close'], 0, 2), (int) substr($schedule['close'], 3, 2));
        $maintenance = get_post_meta($court_id, '_bgc_maintenance', true);
        $blocks = [];
        foreach ((array) $maintenance as $block) {
            if (($block['date'] ?? '') === $date && $this->valid_time($block['start'] ?? '') && $this->valid_time($block['end'] ?? '')) {
                $blocks[] = [$day->setTime((int) substr($block['start'], 0, 2), (int) substr($block['start'], 3, 2)), $day->setTime((int) substr($block['end'], 0, 2), (int) substr($block['end'], 3, 2))];
            }
        }
        $now = current_datetime(); $result = [];
        for ($cursor = $open; $cursor->modify('+' . $duration . ' minutes') <= $close; $cursor = $cursor->modify('+30 minutes')) {
            $end = $cursor->modify('+' . $duration . ' minutes');
            $maintenance_hit = false;
            foreach ($blocks as $block) { if ($cursor < $block[1] && $end > $block[0]) { $maintenance_hit = true; break; } }
            $state = $cursor <= $now ? 'past' : ($maintenance_hit ? 'closed' : (in_array($cursor->format('H:i'), $available, true) ? 'available' : 'full'));
            $result[] = ['time' => $cursor->format('H:i'), 'state' => $state];
        }
        return $result;
    }

    private function available_slots($court_id, $date, $duration) {
        $court = get_post($court_id);
        if (!$court || $court->post_type !== 'bg_court' || $court->post_status !== 'publish' || get_post_meta($court_id, '_bgc_active', true) !== '1') { return new WP_Error('court', 'Saha şu anda rezervasyona açık değil.'); }
        if (!$this->valid_date($date)) { return new WP_Error('date', 'Tarih geçersiz.'); }
        $min = (int) (get_post_meta($court_id, '_bgc_min_minutes', true) ?: 60);
        $max = (int) (get_post_meta($court_id, '_bgc_max_minutes', true) ?: 180);
        if ($duration < $min || $duration > $max || $duration % 30 !== 0) { return new WP_Error('duration', 'Rezervasyon süresi saha koşullarına uygun değil.'); }
        $tz = wp_timezone();
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$day || $day->format('Y-m-d') !== $date) { return new WP_Error('date', 'Tarih geçersiz.'); }
        $hours = get_post_meta($court_id, '_bgc_hours', true);
        $schedule = is_array($hours) ? ($hours[(int) $day->format('N')] ?? null) : null;
        if (!$schedule || !$this->valid_time($schedule['open'] ?? '') || !$this->valid_time($schedule['close'] ?? '') || $schedule['open'] >= $schedule['close']) { return []; }
        $open = $day->setTime((int) substr($schedule['open'], 0, 2), (int) substr($schedule['open'], 3, 2));
        $close = $day->setTime((int) substr($schedule['close'], 0, 2), (int) substr($schedule['close'], 3, 2));
        if ($day->format('Y-m-d') < current_datetime()->format('Y-m-d')) { return []; }
        $utc_start = $day->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $utc_end = $day->modify('+1 day')->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        global $wpdb;
        $busy = $wpdb->get_results($wpdb->prepare("SELECT start_utc,end_utc FROM {$this->reservations} WHERE court_id=%d AND status IN ('pending','approved') AND start_utc < %s AND end_utc > %s", $court_id, $utc_end, $utc_start));
        if ($wpdb->last_error) { return new WP_Error('database', 'Müsaitlik bilgisi alınamadı.'); }
        $maintenance = get_post_meta($court_id, '_bgc_maintenance', true);
        if (!is_array($maintenance)) { $maintenance = []; }
        $blocked = [];
        foreach ($maintenance as $block) {
            if (($block['date'] ?? '') !== $date || !$this->valid_time($block['start'] ?? '') || !$this->valid_time($block['end'] ?? '')) { continue; }
            $blocked[] = [$day->setTime((int) substr($block['start'], 0, 2), (int) substr($block['start'], 3, 2))->setTimezone(new DateTimeZone('UTC'))->getTimestamp(), $day->setTime((int) substr($block['end'], 0, 2), (int) substr($block['end'], 3, 2))->setTimezone(new DateTimeZone('UTC'))->getTimestamp()];
        }
        $busy_ranges = [];
        foreach ($busy as $range) { $busy_ranges[] = [strtotime($range->start_utc . ' UTC'), strtotime($range->end_utc . ' UTC')]; }
        $slots = [];
        $now = current_datetime()->getTimestamp();
        for ($cursor = $open; $cursor->modify('+' . $duration . ' minutes') <= $close; $cursor = $cursor->modify('+30 minutes')) {
            if ($cursor->getTimestamp() <= $now) { continue; }
            $start = $cursor->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
            $end = $cursor->modify('+' . $duration . ' minutes')->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
            $overlap = false;
            foreach (array_merge($busy_ranges, $blocked) as $range) { if ($start < $range[1] && $end > $range[0]) { $overlap = true; break; } }
            if (!$overlap) { $slots[] = $cursor->format('H:i'); }
        }
        return $slots;
    }

    public function book() {
        if (!is_user_logged_in()) { $this->redirect_front('login'); }
        if (empty($_POST['_bgc_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bgc_nonce'])), 'bgc_book')) { $this->redirect_front('invalid_nonce'); }
        $user_id = get_current_user_id();
        if (get_option('bossgym_court_active_membership_required', '0') === '1' && !$this->has_active_membership($user_id)) { $this->redirect_front('membership'); }
        $court_id = absint($_POST['court_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $time = $this->normalize_time(sanitize_text_field(wp_unslash($_POST['time'] ?? '')));
        $duration = absint($_POST['duration'] ?? 0);
        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        if (!$court_id) { $this->redirect_front('invalid_court'); }
        if (!$this->valid_date($date)) { $this->redirect_front('invalid_date'); }
        if (!$time) { $this->redirect_front('invalid_time'); }
        $min = (int) (get_post_meta($court_id, '_bgc_min_minutes', true) ?: 60);
        $max = (int) (get_post_meta($court_id, '_bgc_max_minutes', true) ?: 180);
        if ($duration < $min || $duration > $max || $duration % 30 !== 0) { $this->redirect_front('invalid_duration'); }
        $local_start = $this->local_datetime($date, $time);
        if (!$local_start) { $this->redirect_front('invalid_time'); }
        if ($local_start->getTimestamp() <= current_datetime()->getTimestamp()) { $this->redirect_front('past'); }
        global $wpdb;
        $lock = 'bgc:' . $court_id . ':' . str_replace('-', '', $date);
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ($locked !== 1) { $this->redirect_front('error'); }
        if ($wpdb->query('START TRANSACTION') === false) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); $this->redirect_front('error'); }
        $available = $this->available_slots($court_id, $date, $duration);
        if (is_wp_error($available) || !in_array($time, $available, true)) {
            $failure = is_wp_error($available) ? $this->availability_error_code($available) : $this->unavailable_slot_code($court_id, $date, $time, $duration);
            $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); $this->redirect_front($failure);
        }
        $court = get_post($court_id);
        $rate = (float) get_post_meta($court_id, '_bgc_hourly_rate', true);
        $local_end = $local_start->modify('+' . $duration . ' minutes');
        $inserted = $wpdb->insert($this->reservations, [
            'user_id' => $user_id, 'court_id' => $court_id,
            'start_utc' => $local_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_utc' => $local_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'duration_minutes' => $duration, 'hourly_rate' => number_format($rate, 2, '.', ''),
            'quoted_total' => number_format($rate * $duration / 60, 2, '.', ''), 'status' => 'pending',
            'note' => $note, 'created_utc' => gmdate('Y-m-d H:i:s'),
        ], ['%d', '%d', '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s']);
        if (!$inserted) { $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); $this->redirect_front('error'); }
        $booking_id = (int) $wpdb->insert_id;
        if (!empty($_POST['referee'])) {
            $ref_insert = $wpdb->insert($this->referees, ['reservation_id' => $booking_id, 'user_id' => $user_id, 'status' => 'requested', 'admin_note' => '', 'created_utc' => gmdate('Y-m-d H:i:s')], ['%d', '%d', '%s', '%s', '%s']);
            if (!$ref_insert) { $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); $this->redirect_front('error'); }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            $this->redirect_front('error');
        }
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        do_action('bossgym_booking_created', 'court', $booking_id);
        $this->redirect_front('created');
    }

    private function redirect_front($code) {
        $code = sanitize_key($code);
        if (is_user_logged_in() && $code !== 'created' && !empty($_POST)) {
            $state = [
                'court_id' => absint($_POST['court_id'] ?? 0),
                'date' => sanitize_text_field(wp_unslash($_POST['date'] ?? '')),
                'duration' => absint($_POST['duration'] ?? 0),
                'time' => sanitize_text_field(wp_unslash($_POST['time'] ?? '')),
                'referee' => !empty($_POST['referee']) ? 1 : 0,
                'note' => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
            ];
            set_transient('bgc_booking_form_' . get_current_user_id(), $state, 5 * MINUTE_IN_SECONDS);
        }
        wp_safe_redirect(add_query_arg('bgc', $code, (get_permalink(20) ?: home_url('/rezervasyon/')) . '#online-saha-talebi'));
        exit;
    }

    private function consume_booking_form_state() {
        if (empty($_GET['bgc']) || !is_user_logged_in()) { return []; }
        $key = 'bgc_booking_form_' . get_current_user_id();
        $state = get_transient($key);
        delete_transient($key);
        if (!is_array($state)) { return []; }
        if (!$this->valid_date($state['date'] ?? '')) { $state['date'] = ''; }
        if (!$this->valid_time($state['time'] ?? '')) { $state['time'] = ''; }
        $state['note'] = sanitize_textarea_field($state['note'] ?? '');
        return $state;
    }

    private function has_active_membership($user_id) {
        if (get_user_meta($user_id, 'bossgym_membership_status', true) !== 'active') { return false; }
        $end = get_user_meta($user_id, 'bossgym_membership_end', true);
        return !$end || $end >= current_datetime()->format('Y-m-d');
    }

    private function valid_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        return checkdate($month, $day, $year);
    }

    private function valid_time($time) { return is_string($time) && (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time); }

    private function normalize_time($time) {
        if (!is_string($time)) { return ''; }
        $time = trim($time);
        if (preg_match('/^((?:[01]\d|2[0-3]):[0-5]\d)(?::([0-5]\d))?$/', $time, $matches)) {
            if (!empty($matches[2]) && $matches[2] !== '00') { return ''; }
            return $matches[1];
        }
        return '';
    }

    private function availability_error_code($error) {
        $codes = ['court' => 'invalid', 'date' => 'invalid_date', 'duration' => 'invalid_duration', 'database' => 'error'];
        return $codes[$error->get_error_code()] ?? 'invalid';
    }

    private function unavailable_slot_code($court_id, $date, $time, $duration) {
        $court = get_post($court_id);
        if (!$court || $court->post_type !== 'bg_court' || $court->post_status !== 'publish' || get_post_meta($court_id, '_bgc_active', true) !== '1') { return 'invalid'; }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        if (!$day) { return 'invalid_date'; }
        $hours = get_post_meta($court_id, '_bgc_hours', true);
        $schedule = is_array($hours) ? ($hours[(int) $day->format('N')] ?? null) : null;
        if (!$schedule || !$this->valid_time($schedule['open'] ?? '') || !$this->valid_time($schedule['close'] ?? '') || $schedule['open'] >= $schedule['close']) { return 'closed'; }
        $start = $this->local_datetime($date, $time);
        if (!$start) { return 'invalid_time'; }
        $end = $start->modify('+' . $duration . ' minutes');
        $open = $day->setTime((int) substr($schedule['open'], 0, 2), (int) substr($schedule['open'], 3, 2));
        $close = $day->setTime((int) substr($schedule['close'], 0, 2), (int) substr($schedule['close'], 3, 2));
        if ($start < $open || $end > $close) { return 'outside_hours'; }
        if ($start->getTimestamp() <= current_datetime()->getTimestamp()) { return 'past'; }
        $maintenance = get_post_meta($court_id, '_bgc_maintenance', true);
        foreach ((array) $maintenance as $block) {
            if (($block['date'] ?? '') !== $date || !$this->valid_time($block['start'] ?? '') || !$this->valid_time($block['end'] ?? '')) { continue; }
            $block_start = $day->setTime((int) substr($block['start'], 0, 2), (int) substr($block['start'], 3, 2));
            $block_end = $day->setTime((int) substr($block['end'], 0, 2), (int) substr($block['end'], 3, 2));
            if ($start < $block_end && $end > $block_start) { return 'maintenance'; }
        }
        return 'conflict';
    }

    private function local_datetime($date, $time) {
        if (!$this->valid_date($date) || !$this->valid_time($time)) { return false; }
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, wp_timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if (!$value || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) { return false; }
        return $value->format('Y-m-d H:i') === $date . ' ' . $time ? $value : false;
    }

    private function redirect_admin($page, $message = '') {
        $url = admin_url('admin.php?page=' . $page);
        if ($message !== '') { $url = add_query_arg('bgc_notice', sanitize_key($message), $url); }
        wp_safe_redirect($url); exit;
    }

    public function admin_reservations() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu sayfaya erişim izniniz yok.')); }
        global $wpdb;
        $where = ['1=1']; $args = [];
        $court_id = absint($_GET['court'] ?? 0); $status = sanitize_key(wp_unslash($_GET['status'] ?? '')); $date = sanitize_text_field(wp_unslash($_GET['date'] ?? ''));
        if ($court_id) { $where[] = 'court_id=%d'; $args[] = $court_id; }
        if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true)) { $where[] = 'status=%s'; $args[] = $status; }
        if ($this->valid_date($date)) {
            $from = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
            $to = $from->modify('+1 day');
            $where[] = 'start_utc >= %s AND start_utc < %s'; $args[] = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $args[] = $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $sql = "SELECT * FROM {$this->reservations} WHERE " . implode(' AND ', $where) . ' ORDER BY start_utc DESC LIMIT 300';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        $courts = get_posts(['post_type' => 'bg_court', 'post_status' => ['publish', 'draft'], 'numberposts' => -1]);
        echo '<div class="wrap bossgym-admin bossgym-admin--courts"><header class="bossgym-admin__header"><h1>Saha Rezervasyonları</h1><p>Bekleyen talepler de zamanı bloke eder. Onay, iptal ve tamamlama işlemleri yönetici yetkisi gerektirir.</p></header>';
        $this->admin_notice();
        echo '<form class="bossgym-admin__filters" method="get"><input type="hidden" name="page" value="bossgym-court-bookings"><label>Saha <select name="court"><option value="0">Tüm sahalar</option>';
        foreach ($courts as $court) { echo '<option value="' . (int) $court->ID . '" ' . selected($court_id, $court->ID, false) . '>' . esc_html(get_the_title($court)) . '</option>'; }
        echo '</select></label><label>Tarih <input type="date" name="date" value="' . esc_attr($date) . '"></label><label>Durum <select name="status"><option value="">Tümü</option>';
        foreach (['pending' => 'Beklemede', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal edildi', 'completed' => 'Tamamlandı'] as $key => $label) { echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select></label><button class="button button-primary">Filtrele</button></form><div class="bossgym-admin__table-wrap"><table class="widefat striped bossgym-admin__table bossgym-admin__table--courts"><thead><tr><th>Üye / saha</th><th>Tarih ve saat</th><th>Süre / tutar</th><th>Hakem talebi</th><th>Durum / işlem</th></tr></thead><tbody>';
        foreach ((array) $rows as $row) {
            $user = get_user_by('id', $row->user_id); $court = get_post($row->court_id); $ref = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->referees} WHERE reservation_id=%d", $row->id));
            $start = $this->format_local($row->start_utc); $end = $this->format_local($row->end_utc);
            echo '<tr><td><strong>' . esc_html($court ? $court->post_title : 'Silinmiş saha') . '</strong><br>' . esc_html($user ? $user->display_name : 'Silinmiş hesap') . '</td><td>' . esc_html($start) . '<br>— ' . esc_html($end) . '</td><td>' . (int) $row->duration_minutes . ' dk<br>' . esc_html(number_format_i18n((float) $row->quoted_total, 2)) . ' TL (temsili)</td><td>' . ($ref ? 'Talep: ' . esc_html($this->referee_label($ref->status)) : 'Talep yok') . '</td><td><span class="bgc-admin-status bgc-admin-status--' . esc_attr($row->status) . '">' . esc_html($this->reservation_label($row->status)) . '</span>';
            if (in_array($row->status, ['pending', 'approved'], true)) {
                echo '<form class="bossgym-admin__form bgc-admin-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_court_status"><input type="hidden" name="reservation_id" value="' . (int) $row->id . '">';
                wp_nonce_field('bgc_reservation_status_' . (int) $row->id);
                echo '<select name="status" aria-label="Rezervasyon durumu"><option value="approved">Onaylandı</option><option value="rejected">Reddedildi</option><option value="cancelled">İptal edildi</option><option value="completed">Tamamlandı</option></select><button class="button">Uygula</button></form>';
            }
            if ($row->note !== '') { echo '<small class="bgc-admin-note">' . esc_html($row->note) . '</small>'; }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5">Filtreye uygun rezervasyon bulunamadı.</td></tr>'; }
        echo '</tbody></table></div><p class="description">En fazla son 300 rezervasyon gösterilir. Fiyatlar başvuru anındaki temsilî tutardır; ödeme alınmaz.</p></div>';
    }

    public function update_reservation_status() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu işlem için yetkiniz yok.')); }
        $id = absint($_POST['reservation_id'] ?? 0); check_admin_referer('bgc_reservation_status_' . $id);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        if (!in_array($status, ['approved', 'rejected', 'cancelled', 'completed'], true)) { $this->redirect_admin('bossgym-court-bookings', 'invalid'); }
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) { $this->redirect_admin('bossgym-court-bookings', 'error'); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->reservations} WHERE id=%d FOR UPDATE", $id));
        if (!$row || !in_array($row->status, ['pending', 'approved'], true)) { $wpdb->query('ROLLBACK'); $this->redirect_admin('bossgym-court-bookings', 'invalid'); }
        $updated = $wpdb->update($this->reservations, ['status' => $status], ['id' => $id], ['%s'], ['%d']);
        if ($updated === false) { $wpdb->query('ROLLBACK'); $this->redirect_admin('bossgym-court-bookings', 'error'); }
        $referee = null;
        $referee_next_status = in_array($status, ['cancelled', 'rejected'], true) ? $status : '';
        if ($referee_next_status !== '') {
            $referee = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$this->referees} WHERE reservation_id=%d", $id));
            if ($referee && $referee->status !== $referee_next_status) {
                $ref_updated = $wpdb->update($this->referees, ['status' => $referee_next_status], ['id' => $referee->id], ['%s'], ['%d']);
                if ($ref_updated === false) { $wpdb->query('ROLLBACK'); $this->redirect_admin('bossgym-court-bookings', 'error'); }
            }
        }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); $this->redirect_admin('bossgym-court-bookings', 'error'); }
        if ($row->status !== $status) { do_action('bossgym_booking_status_changed', 'court', $id, $status); }
        if ($referee_next_status !== '' && $referee && $referee->status !== $referee_next_status) { do_action('bossgym_referee_status_changed', (int) $referee->id, $referee_next_status); }
        $this->redirect_admin('bossgym-court-bookings', 'updated');
    }

    public function admin_referees() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu sayfaya erişim izniniz yok.')); }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT r.*, b.court_id, b.start_utc, b.end_utc, b.status AS reservation_status FROM {$this->referees} r LEFT JOIN {$this->reservations} b ON b.id=r.reservation_id ORDER BY r.created_utc DESC LIMIT 300");
        echo '<div class="wrap bossgym-admin bossgym-admin--courts"><header class="bossgym-admin__header"><h1>Hakem Talepleri</h1><p>Hakem talepleri rezervasyona bağlıdır. Buradaki onay gerçek görevlendirme veya ödeme oluşturmaz.</p></header>';
        $this->admin_notice();
        echo '<div class="bossgym-admin__table-wrap"><table class="widefat striped bossgym-admin__table bossgym-admin__table--referees"><thead><tr><th>Üye</th><th>Saha / zaman</th><th>Rezervasyon</th><th>Hakem durumu</th><th>İşlem</th></tr></thead><tbody>';
        foreach ((array) $rows as $row) {
            $user = get_user_by('id', $row->user_id); $court = get_post($row->court_id);
            echo '<tr><td>' . esc_html($user ? $user->display_name : 'Silinmiş hesap') . '</td><td>' . esc_html($court ? $court->post_title : 'Silinmiş saha') . '<br>' . esc_html($this->format_local($row->start_utc)) . ' – ' . esc_html($this->format_local($row->end_utc)) . '</td><td>' . esc_html($this->reservation_label($row->reservation_status ?: 'cancelled')) . '</td><td><span class="bgc-admin-status bgc-admin-status--' . esc_attr($row->status) . '">' . esc_html($this->referee_label($row->status)) . '</span></td><td>';
            if ($row->status !== 'cancelled') {
                echo '<form class="bossgym-admin__form bgc-admin-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_referee_status"><input type="hidden" name="referee_id" value="' . (int) $row->id . '">';
                wp_nonce_field('bgc_referee_status_' . (int) $row->id);
                echo '<select name="status" aria-label="Hakem talebi durumu">';
                foreach (['requested' => 'Talep Alındı', 'reviewing' => 'Değerlendiriliyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal Edildi'] as $key => $label) { echo '<option value="' . esc_attr($key) . '" ' . selected($row->status, $key, false) . '>' . esc_html($label) . '</option>'; }
                echo '</select><button class="button">Uygula</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5">Henüz hakem talebi yok.</td></tr>'; }
        echo '</tbody></table></div></div>';
    }

    public function update_referee_status() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu işlem için yetkiniz yok.')); }
        $id = absint($_POST['referee_id'] ?? 0); check_admin_referer('bgc_referee_status_' . $id);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        if (!in_array($status, ['requested', 'reviewing', 'approved', 'rejected', 'cancelled'], true)) { $this->redirect_admin('bossgym-referees', 'invalid'); }
        global $wpdb;
        $reservation = $wpdb->get_row($wpdb->prepare("SELECT r.status AS referee_status,b.status AS reservation_status FROM {$this->referees} r LEFT JOIN {$this->reservations} b ON b.id=r.reservation_id WHERE r.id=%d", $id));
        if (!$reservation || $reservation->reservation_status === 'cancelled') { $this->redirect_admin('bossgym-referees', 'invalid'); }
        $updated = $wpdb->update($this->referees, ['status' => $status], ['id' => $id], ['%s'], ['%d']);
        if ($updated === false) { $this->redirect_admin('bossgym-referees', 'error'); }
        if ($updated > 0 && $reservation->referee_status !== $status) { do_action('bossgym_referee_status_changed', $id, $status); }
        $this->redirect_admin('bossgym-referees', 'updated');
    }

    private function admin_notice() {
        $key = sanitize_key(wp_unslash($_GET['bgc_notice'] ?? ''));
        if (!in_array($key, ['updated', 'invalid', 'error'], true)) { return; }
        $message = $key === 'updated' ? 'Değişiklik kaydedildi.' : ($key === 'invalid' ? 'İşlem doğrulanamadı veya kayıt artık düzenlenebilir değil.' : 'İşlem kaydedilemedi.');
        echo '<div class="notice ' . ($key === 'updated' ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function format_local($utc) {
        try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('d.m.Y H:i'); }
        catch (Exception $e) { return 'Tarih bulunamadı'; }
    }

    private function reservation_label($status) { return ['pending' => 'Beklemede', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal edildi', 'completed' => 'Tamamlandı'][$status] ?? 'Bilinmiyor'; }
    private function referee_label($status) { return ['requested' => 'Talep Alındı', 'reviewing' => 'Değerlendiriliyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal Edildi'][$status] ?? 'Bilinmiyor'; }

    public function dashboard_output($html, $user_id) {
        if (!preg_match('/<article\b[^>]*class="[^"]*\bbgm-dashboard-card\b[^"]*"[^>]*>(?:(?!<\/article>).)*?<h2>Saha rezervasyonlar/s', $html, $match, PREG_OFFSET_CAPTURE)) { return $html; }
        $start = $match[0][1];
        $end = strpos($html, '</article>', $start);
        if ($end === false) { return $html; }
        $end += strlen('</article>');
        return substr($html, 0, $start) . $this->member_bookings((int) $user_id) . substr($html, $end);
    }

    private function member_bookings($user_id) {
        if (!is_user_logged_in() || get_current_user_id() !== $user_id) { return '<article class="bgm-card bgm-dashboard-card bgc-member-card"><h2>Saha rezervasyonlarım</h2><p>Rezervasyon bilgileri yalnızca hesabınıza giriş yaptığınızda görüntülenir.</p></article>'; }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->reservations} WHERE user_id=%d ORDER BY start_utc DESC LIMIT 100", $user_id));
        $now_utc = current_time('mysql', true); $upcoming = []; $past = [];
        foreach ((array) $rows as $row) { if ($row->start_utc >= $now_utc && !in_array($row->status, ['cancelled', 'rejected'], true)) { $upcoming[] = $row; } else { $past[] = $row; } }
        $html = '<article class="bgm-card bgm-dashboard-card bgc-member-card"><div class="bgc-eyebrow">SAHA PROGRAMI</div><h2>Saha rezervasyonlarım</h2><p>Başvurular yönetici onayına kadar beklemede kalır; ödeme alınmaz.</p>';
        $html .= $this->member_reservation_table('Yaklaşan rezervasyonlar', $upcoming, true);
        $html .= $this->member_reservation_table('Geçmiş ve iptal edilenler', $past, false);
        return $html . '<a class="bgc-button" href="' . esc_url(get_permalink(20)) . '">Yeni rezervasyon talebi</a></article>';
    }

    private function member_reservation_table($title, $rows, $empty_action) {
        global $wpdb;
        $html = '<h3>' . esc_html($title) . '</h3><div class="bgc-table-wrap"><table class="bgc-table"><thead><tr><th>Saha</th><th>Tarih ve saat</th><th>Durum</th><th>Hakem</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $court = get_post($row->court_id); $ref = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->referees} WHERE reservation_id=%d", $row->id));
            $ref_text = $ref ? $this->referee_label($ref) : 'Talep edilmedi';
            $html .= '<tr><td>' . esc_html($court ? $court->post_title : 'Saha') . '</td><td>' . esc_html($this->format_local($row->start_utc)) . ' – ' . esc_html($this->format_local($row->end_utc)) . '</td><td><span class="bgc-member-status bgc-member-status--' . esc_attr($row->status) . '">' . esc_html($this->reservation_label($row->status)) . '</span></td><td>' . esc_html($ref_text) . '</td></tr>';
        }
        if (!$rows) { $html .= '<tr><td colspan="4">' . ($empty_action ? 'Yaklaşan rezervasyon talebiniz bulunmuyor.' : 'Gösterilecek geçmiş kayıt yok.') . '</td></tr>'; }
        return $html . '</tbody></table></div>';
    }
}
