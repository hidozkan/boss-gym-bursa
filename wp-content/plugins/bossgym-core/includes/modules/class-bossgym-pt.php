<?php
if (!defined('ABSPATH')) { exit; }

/** PT, trainer and appointment module. Pending requests reserve their time/capacity. */
final class BossGym_PT {
    const DB_VERSION = '1.0.0';
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bg_pt_appointments';
        add_action('init', [$this, 'register_content_types']);
        add_action('init', [$this, 'maybe_install'], 20);
        add_action('init', [$this, 'seed_and_integrate'], 30);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_bg_pt_trainer', [$this, 'save_trainer'], 10, 2);
        add_action('save_post_bg_pt_package', [$this, 'save_package'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_bossgym_pt_book', [$this, 'book']);
        add_action('admin_post_bossgym_pt_status', [$this, 'update_status']);
        add_action('wp_ajax_bossgym_pt_slots', [$this, 'ajax_slots']);
        add_shortcode('bossgym_pt_booking', [$this, 'booking_shortcode']);
        add_shortcode('bossgym_pt_trainers', [$this, 'trainers_shortcode']);
        add_shortcode('bossgym_pt_weekly_schedule', [$this, 'weekly_schedule_shortcode']);
        add_shortcode('bossgym_pt_member_appointments', [$this, 'member_shortcode']);
        add_filter('bossgym_dashboard_output', [$this, 'dashboard_output'], 10, 2);
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'bg_pt_appointments';
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            trainer_id bigint(20) unsigned NOT NULL,
            package_id bigint(20) unsigned NOT NULL,
            session_start datetime NOT NULL,
            session_end datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            mode varchar(20) NOT NULL,
            note text NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY trainer_time (trainer_id,session_start,session_end,status),
            KEY member_time (user_id,session_start,status),
            KEY group_time (trainer_id,package_id,session_start,status)
        ) $collate;";
        dbDelta($sql);
        update_option('bossgym_pt_db_version', self::DB_VERSION, false);
    }

    public function maybe_install() {
        if (get_option('bossgym_pt_db_version') !== self::DB_VERSION) { self::install(); }
    }

    public function register_content_types() {
        register_post_type('bg_pt_trainer', [
            'labels' => ['name' => 'Antrenör Yönetimi', 'singular_name' => 'Antrenör', 'add_new_item' => 'Antrenör ekle', 'edit_item' => 'Antrenörü düzenle'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
            'supports' => ['title'], 'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
        register_post_type('bg_pt_package', [
            'labels' => ['name' => 'PT Paketleri', 'singular_name' => 'PT Paketi', 'add_new_item' => 'PT paketi ekle', 'edit_item' => 'PT paketini düzenle'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
            'supports' => ['title'], 'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
    }

    public function add_meta_boxes() {
        add_meta_box('bgpt_trainer_data', 'Antrenör bilgileri', [$this, 'trainer_box'], 'bg_pt_trainer', 'normal', 'high');
        add_meta_box('bgpt_package_data', 'Paket bilgileri', [$this, 'package_box'], 'bg_pt_package', 'normal', 'high');
    }

    private function field($label, $name, $value, $type = 'text', $help = '') {
        echo '<p class="bgpt-admin-field bossgym-admin__field"><label><strong>' . esc_html($label) . '</strong>';
        if ($type === 'textarea') { echo '<textarea class="large-text" rows="4" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>'; }
        else { echo '<input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
        if ($help) { echo '<span class="description">' . esc_html($help) . '</span>'; }
        echo '</label></p>';
    }

    private function demo_profile_defaults($post) {
        $slug = $post instanceof WP_Post ? ($post->post_name ?: sanitize_title(remove_accents($post->post_title))) : '';
        $profiles = [
            'ece-yalcin' => [
                'photo_file' => 'coach-ece-yalcin.webp',
                'expertise' => 'Fonksiyonel kuvvet ve hareket tekniği',
                'bio' => 'Ece Yalçın profili, temel hareket kalıpları ve fonksiyonel kuvvet çalışmalarına odaklanır. Squat, itiş-çekiş, kalça menteşesi ve gövde kontrolü gibi temel örüntülerin kişiye uygun biçimde ele alınmasını esas alır.\n\nBaşlangıç seviyesindeki veya düzenli egzersize yeniden dönmek isteyen üyeler için anlaşılır yönlendirme, kontrollü tempo ve aşamalı yüklenme yaklaşımını öne çıkarır.',
                'target_members' => 'Egzersize yeni başlayanlar, temel hareket tekniğini geliştirmek isteyenler ve düzenli antrenmana dönüş hedefi olan üyeler.',
                'approach' => 'Önce hareket kalitesini ve kişisel başlangıç seviyesini gözeten; teknik oturdukça tekrar, tempo veya yükü adım adım artıran bir yaklaşım.',
                'competencies' => 'Temel kuvvet çalışmaları\nFonksiyonel hareket örüntüleri\nGövde kontrolü ve egzersiz tekniği',
                'certificates' => '',
            ],
            'mert-kaya' => [
                'photo_file' => 'coach-mert-kaya.webp',
                'expertise' => 'Kuvvet gelişimi ve kondisyon planlama',
                'bio' => 'Mert Kaya profili, kuvvet gelişimi ile temel kondisyonu birlikte ele alır. Düzenli çalışma alışkanlığı, hareketlerin kontrollü uygulanması ve ilerlemenin küçük adımlarla takip edilmesi bu yaklaşımın temelini oluşturur.\n\nYapılandırılmış bir planla bireysel hedeflerine ilerlemek veya grup derslerinde temel kondisyon çalışmak isteyen katılımcılara yönelik bir çalışma çerçevesi sunar. Program içeriği kişinin seviyesine göre uyarlanmalıdır.',
                'target_members' => 'Düzenli antrenman rutini kurmak, temel kuvvetini geliştirmek veya kondisyon çalışmalarını planlı biçimde sürdürmek isteyen üyeler.',
                'approach' => 'Teknik, çalışma temposu ve dinlenmeyi birlikte gözeten; ilerlemeyi düzenli gözlemleyip yükü kontrollü biçimde artıran programlama.',
                'competencies' => 'Temel kuvvet ve kondisyon\nAşamalı yüklenme prensipleri\nAntrenman temposu ve dinlenme planlaması',
                'certificates' => '',
            ],
            'deniz-arslan' => [
                'photo_file' => 'coach-deniz-arslan.webp',
                'expertise' => 'Hareket kalitesi ve mobilite',
                'bio' => 'Deniz Arslan profili; hareket açıklığı, mobilite ve temel egzersiz tekniğine odaklanır. Çalışma çerçevesi kontrollü hareket aralığı, denge ve günlük egzersizlerde kullanılabilir hareket kalıplarını geliştirme üzerine kuruludur.\n\nHareketlerini daha bilinçli yapmak veya antrenmana kontrollü bir temel oluşturmak isteyen üyeler için bireysel çalışma yaklaşımını tanıtır.',
                'target_members' => 'Hareket kalitesine, mobilite çalışmalarına ve egzersiz tekniğine bireysel olarak odaklanmak isteyen üyeler.',
                'approach' => 'Rahat ve kontrollü hareket aralığı içinde çalışmayı, kişinin sınırlarını gözetmeyi ve teknik geri bildirimi önceliklendiren bir yaklaşım.',
                'competencies' => 'Mobilite ve hareket açıklığı çalışmaları\nDenge ve gövde kontrolü\nTemel egzersiz tekniği',
                'certificates' => '',
            ],
            'burak-demir' => [
                'photo_file' => 'coach-burak-demir.webp',
                'expertise' => 'Grup egzersizi ve temel kondisyon',
                'bio' => 'Burak Demir profili, grup ortamında güvenli ve anlaşılır egzersiz akışını tanıtır. Isınma, ana çalışma ve soğuma bölümlerini dengeli bir ders akışı içinde ele alır.\n\nBirlikte çalışmaktan motivasyon alan, temel kondisyon ve kuvvet egzersizlerini grup düzeninde denemek isteyen katılımcılara yönelik bir yaklaşım sunar. Hareket seçenekleri farklı katılımcı seviyelerine göre uyarlanmalıdır.',
                'target_members' => 'Grup ortamında egzersiz yapmayı tercih eden ve temel kondisyon çalışmalarını ortak bir ders akışı içinde sürdürmek isteyen katılımcılar.',
                'approach' => 'Ders öncesi hareket hazırlığı, anlaşılır egzersiz yönergeleri ve katılımcıların kendi seviyelerinde çalışmasına alan açan grup yönetimi.',
                'competencies' => 'Grup antrenmanı akışı\nTemel kondisyon ve kuvvet egzersizleri\nEgzersiz seçeneklerini seviyeye göre uyarlama',
                'certificates' => '',
            ],
        ];
        foreach ($profiles as &$profile) {
            foreach (['bio', 'competencies'] as $field) {
                $profile[$field] = str_replace('\\n', "\n", $profile[$field]);
            }
        }
        unset($profile);
        return $profiles[$slug] ?? [];
    }

    private function trainer_profile_content($trainer) {
        $defaults = $this->demo_profile_defaults($trainer);
        $values = [];
        foreach (['expertise', 'bio', 'target_members', 'approach', 'competencies', 'certificates'] as $key) {
            $value = trim((string) get_post_meta($trainer->ID, '_bgpt_' . $key, true));
            $legacy_bio = $key === 'bio' && strpos($value, 'kurgusal demo antrenör profili') !== false;
            $legacy_certificates = $key === 'certificates' && strpos($value, 'sertifikalar doğrulanmadı.') !== false;
            if (!$value || $legacy_bio || $legacy_certificates || ($key === 'bio' && strlen($value) < 180) || ($key === 'expertise' && strlen($value) < 28)) {
                $value = $defaults[$key] ?? $value;
            }
            $values[$key] = $value;
        }
        $values['demo_note'] = get_post_meta($trainer->ID, '_bgpt_demo_note', true) ?: 'Kurgusal demo profil · İsim ve profil içeriği temsili amaçlıdır.';
        $values['experience'] = absint(get_post_meta($trainer->ID, '_bgpt_experience', true));
        $values['modes'] = array_filter(array_map('trim', explode(',', (string) get_post_meta($trainer->ID, '_bgpt_modes', true))));
        $values['hours'] = $this->trainer_hours_label(get_post_meta($trainer->ID, '_bgpt_hours', true));
        return $values;
    }

    private function trainer_hours_label($raw) {
        $hours = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($hours) || !$hours) { return 'Çalışma saatleri henüz tanımlanmamış.'; }
        $days = [1 => 'Pzt', 2 => 'Sal', 3 => 'Çar', 4 => 'Per', 5 => 'Cum', 6 => 'Cmt', 7 => 'Paz'];
        $lines = [];
        foreach ($hours as $day => $value) {
            $day = absint($day);
            if (!isset($days[$day]) || !is_array($value)) { continue; }
            $start = $value[0] ?? ($value['open'] ?? '');
            $end = $value[1] ?? ($value['close'] ?? '');
            if (preg_match('/^\d{2}:\d{2}$/', (string) $start) && preg_match('/^\d{2}:\d{2}$/', (string) $end)) { $lines[] = $days[$day] . ': ' . $start . '–' . $end; }
        }
        return $lines ? implode(' · ', $lines) : 'Çalışma saatleri henüz tanımlanmamış.';
    }

    public function trainer_box($post) {
        echo '<div class="bossgym-admin bossgym-admin--meta bossgym-admin--trainer">';
        wp_nonce_field('bgpt_save_trainer', 'bgpt_nonce');
        $id = $post->ID;
        $this->field('Profil fotoğrafı (Medya Kütüphanesi ID)', 'bgpt_photo', get_post_meta($id, '_bgpt_photo', true), 'number');
        echo '<p><button type="button" class="button bgpt-media-pick">Medya Kütüphanesinden seç</button></p>';
        $defaults = $this->demo_profile_defaults($post);
        $meta_value = static function ($key) use ($id, $defaults) {
            $value = trim((string) get_post_meta($id, '_bgpt_' . $key, true));
            $legacy_bio = $key === 'bio' && (strpos($value, 'kurgusal demo antrenör profili') !== false || strlen($value) < 180);
            $legacy_certificates = $key === 'certificates' && strpos($value, 'sertifikalar doğrulanmadı.') !== false;
            if ($legacy_bio || $legacy_certificates || ($key === 'expertise' && strlen($value) < 28)) { $value = ''; }
            return $value !== '' ? $value : ($defaults[$key] ?? '');
        };
        $this->field('Uzmanlık alanı', 'bgpt_expertise', $meta_value('expertise'));
        $this->field('Özgeçmiş', 'bgpt_bio', $meta_value('bio'), 'textarea');
        $this->field('Uygun üyeler / hedefler', 'bgpt_target_members', $meta_value('target_members'), 'textarea');
        $this->field('Çalışma yaklaşımı', 'bgpt_approach', $meta_value('approach'), 'textarea');
        $this->field('Yetkinlik başlıkları', 'bgpt_competencies', $meta_value('competencies'), 'textarea');
        $this->field('Deneyim (yıl)', 'bgpt_experience', get_post_meta($id, '_bgpt_experience', true), 'number');
        $this->field('Eğitim / sertifika bilgisi', 'bgpt_certificates', $meta_value('certificates'), 'textarea', 'Bu profiller kurgusal demodur. Doğrulanmamış resmî sertifika veya belge iddiası eklemeyin.');
        $this->field('Yetkinlikler (bireysel,grup)', 'bgpt_modes', get_post_meta($id, '_bgpt_modes', true), 'text', 'Virgülle ayrılmış: bireysel, grup veya ikisi.');
        $this->field('Çalışma saatleri (haftanın günü=başlangıç-bitiş)', 'bgpt_hours', get_post_meta($id, '_bgpt_hours', true), 'textarea', "Gün numarası: 1=Pazartesi … 7=Pazar. Örnek satır: 1=08:00-20:00");
        $this->field('Profil notu', 'bgpt_demo_note', get_post_meta($id, '_bgpt_demo_note', true), 'text', 'Kurgusal profiller için “Kurgusal demo profil” yazın.');
        echo '</div>';
    }

    public function package_box($post) {
        echo '<div class="bossgym-admin bossgym-admin--meta bossgym-admin--package">';
        wp_nonce_field('bgpt_save_package', 'bgpt_nonce');
        $id = $post->ID;
        echo '<p class="bgpt-admin-field bossgym-admin__field"><label><strong>Antrenman türü</strong><select name="bgpt_mode"><option value="individual" ' . selected(get_post_meta($id, '_bgpt_mode', true), 'individual', false) . '>Bireysel</option><option value="group" ' . selected(get_post_meta($id, '_bgpt_mode', true), 'group', false) . '>Grup</option></select></label></p>';
        $this->field('Açıklama', 'bgpt_description', get_post_meta($id, '_bgpt_description', true), 'textarea');
        $this->field('Seans adedi', 'bgpt_sessions', get_post_meta($id, '_bgpt_sessions', true), 'number');
        $this->field('Seans süresi (dakika; 30 dakikanın katı)', 'bgpt_duration', get_post_meta($id, '_bgpt_duration', true), 'number');
        $this->field('Temsili ücret (₺)', 'bgpt_fee', get_post_meta($id, '_bgpt_fee', true), 'number', 'Fiyat demo/temsili olarak gösterilir; ödeme alınmaz.');
        $this->field('Eğitim kapsamı', 'bgpt_scope', get_post_meta($id, '_bgpt_scope', true), 'textarea');
        $this->field('Antrenör ID’leri (virgülle)', 'bgpt_trainers', get_post_meta($id, '_bgpt_trainers', true), 'text', 'Boşsa türü uygun bütün aktif antrenörler listelenir.');
        $this->field('Grup kontenjanı', 'bgpt_capacity', get_post_meta($id, '_bgpt_capacity', true), 'number');
        $level = get_post_meta($id, '_bgpt_level', true);
        echo '<p class="bgpt-admin-field bossgym-admin__field"><label><strong>Örnek program seviyesi</strong><select name="bgpt_level"><option value="">Belirtilmedi</option><option value="beginner" ' . selected($level, 'beginner', false) . '>Başlangıç</option><option value="intermediate" ' . selected($level, 'intermediate', false) . '>Orta</option><option value="all" ' . selected($level, 'all', false) . '>Tüm seviyeler</option></select></label><span class="description">Programda gösterilir; müsaitlik randevu talebi sırasında canlı takvimden doğrulanır.</span></p>';
        echo '</div>';
    }

    public function save_trainer($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id) || empty($_POST['bgpt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bgpt_nonce'])), 'bgpt_save_trainer')) { return; }
        $fields = ['photo' => 'absint', 'expertise' => 'sanitize_text_field', 'bio' => 'sanitize_textarea_field', 'target_members' => 'sanitize_textarea_field', 'approach' => 'sanitize_textarea_field', 'competencies' => 'sanitize_textarea_field', 'experience' => 'absint', 'certificates' => 'sanitize_textarea_field', 'demo_note' => 'sanitize_text_field'];
        foreach ($fields as $key => $sanitize) { update_post_meta($post_id, '_bgpt_' . $key, call_user_func($sanitize, wp_unslash($_POST['bgpt_' . $key] ?? ''))); }
        $modes = array_intersect(array_map('sanitize_key', array_map('trim', explode(',', wp_unslash($_POST['bgpt_modes'] ?? '')))), ['individual', 'group']);
        update_post_meta($post_id, '_bgpt_modes', implode(',', $modes));
        update_post_meta($post_id, '_bgpt_hours', $this->clean_hours(wp_unslash($_POST['bgpt_hours'] ?? '')));
    }

    private function clean_hours($raw) {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            if (!preg_match('/^([1-7])\s*=\s*(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', trim($line), $m)) { continue; }
            if (!$this->valid_clock($m[2]) || !$this->valid_clock($m[3]) || $m[2] >= $m[3]) { continue; }
            $result[(int) $m[1]] = [$m[2], $m[3]];
        }
        return wp_json_encode($result);
    }

    private function valid_clock($value) { return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value); }

    public function save_package($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id) || empty($_POST['bgpt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bgpt_nonce'])), 'bgpt_save_package')) { return; }
        $mode = sanitize_key(wp_unslash($_POST['bgpt_mode'] ?? 'individual'));
        if (!in_array($mode, ['individual', 'group'], true)) { $mode = 'individual'; }
        update_post_meta($post_id, '_bgpt_mode', $mode);
        foreach (['description' => 'sanitize_textarea_field', 'sessions' => 'absint', 'duration' => 'absint', 'fee' => 'absint', 'scope' => 'sanitize_textarea_field', 'trainers' => 'sanitize_text_field', 'capacity' => 'absint'] as $key => $sanitize) {
            update_post_meta($post_id, '_bgpt_' . $key, call_user_func($sanitize, wp_unslash($_POST['bgpt_' . $key] ?? '')));
        }
        $level = sanitize_key(wp_unslash($_POST['bgpt_level'] ?? ''));
        update_post_meta($post_id, '_bgpt_level', in_array($level, ['beginner', 'intermediate', 'all'], true) ? $level : '');
    }
    public function admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->post_type, ['bg_pt_trainer', 'bg_pt_package'], true)) { return; }
        wp_enqueue_media();
        wp_add_inline_script('jquery', <<<'JS'
jQuery(function($){$('.bgpt-media-pick').on('click',function(e){e.preventDefault();var f=wp.media({title:'Antrenör fotoğrafı seç',button:{text:'Fotoğrafı kullan'},multiple:false});f.on('select',function(){var a=f.state().get('selection').first().toJSON();$('input[name=bgpt_photo]').val(a.id);});f.open();});});
JS
        );
    }

    public function admin_menu() {
        add_submenu_page('bossgym-overview', 'Antrenör Yönetimi', 'Antrenör Yönetimi', 'manage_options', 'edit.php?post_type=bg_pt_trainer');
        add_submenu_page('bossgym-overview', 'PT Paketleri', 'PT Paketleri', 'manage_options', 'edit.php?post_type=bg_pt_package');
        add_submenu_page('bossgym-overview', 'PT Randevuları', 'PT Randevuları', 'manage_options', 'bossgym-pt', [$this, 'appointments_page']);
        add_submenu_page('bossgym-overview', 'Genel Ayarlar', 'Genel Ayarlar', 'manage_options', 'bossgym-pt-settings', [$this, 'settings_page']);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) { return; }
        if (!empty($_POST['bgpt_save_settings'])) {
            check_admin_referer('bgpt_settings');
            update_option('bossgym_pt_active_membership_required', !empty($_POST['active_required']) ? '1' : '0');
            update_option('bossgym_court_active_membership_required', !empty($_POST['court_active_required']) ? '1' : '0');
            echo '<div class="notice notice-success"><p>Ayar kaydedildi.</p></div>';
        }
        echo '<div class="wrap bossgym-admin bossgym-admin--settings"><header class="bossgym-admin__header"><h1>Genel Ayarlar</h1><p>Üyelik doğrulama seçenekleri, mevcut PT ve saha rezervasyon modüllerine uygulanır.</p></header><section class="bossgym-admin__card"><form class="bossgym-admin__settings-form" method="post"><fieldset><legend>PT randevuları</legend>';
        wp_nonce_field('bgpt_settings');
        echo '<label><input type="checkbox" name="active_required" value="1" ' . checked(get_option('bossgym_pt_active_membership_required', '0'), '1', false) . '> PT randevu başvurusu için aktif üyelik zorunlu olsun</label><p>PT randevu talebi yönetici onayına gider.</p></fieldset><fieldset><legend>Saha rezervasyonları</legend><label><input type="checkbox" name="court_active_required" value="1" ' . checked(get_option('bossgym_court_active_membership_required', '0'), '1', false) . '> Saha rezervasyon talebi için aktif üyelik zorunlu olsun</label><p>Kapalıyken kayıtlı her kullanıcı rezervasyon talebi gönderebilir. Açıkken üyelik durumu ve bitiş tarihi doğrulanır. Fiyatlar temsilîdir; ödeme alınmaz.</p></fieldset><button class="button button-primary" name="bgpt_save_settings" value="1">Kaydet</button></form></section></div>';
    }

    public function appointments_page() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY session_start DESC LIMIT 300");
        echo '<div class="wrap bossgym-admin bossgym-admin--appointments"><header class="bossgym-admin__header"><h1>PT randevuları</h1><p>Bekleyen başvurular zaman aralığını ve grup kontenjanını bloke eder. İptal, kapasiteyi serbest bırakır.</p></header><div class="bossgym-admin__table-wrap"><table class="widefat striped bossgym-admin__table bossgym-admin__table--appointments"><thead><tr><th>Üye</th><th>Antrenör / paket</th><th>Tarih</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $user = get_user_by('id', $row->user_id); $trainer = get_the_title($row->trainer_id); $package = get_the_title($row->package_id);
            echo '<tr><td>' . esc_html($user ? $user->display_name : 'Silinmiş hesap') . '</td><td>' . esc_html($trainer . ' / ' . $package) . '<br>' . esc_html($row->mode === 'group' ? 'Grup' : 'Bireysel') . '</td><td>' . esc_html($this->local_datetime($row->session_start)->format('d.m.Y H:i')) . '</td><td>' . esc_html($this->status_label($row->status)) . '</td><td>';
            if (in_array($row->status, ['pending', 'approved'], true)) {
                echo '<form class="bgpt-admin-action bossgym-admin__form bossgym-admin__form--appointment" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_pt_status"><input type="hidden" name="id" value="' . (int) $row->id . '">';
                wp_nonce_field('bgpt_status_' . (int) $row->id);
                echo '<select name="status"><option value="approved">Onaylandı</option><option value="cancelled">İptal edildi</option><option value="completed">Tamamlandı</option></select> <button class="button">Uygula</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5">Henüz randevu başvurusu yok.</td></tr>'; }
        echo '</tbody></table></div></div>';
    }

    public function update_status() {
        if (!current_user_can('manage_options')) { wp_die('Bu işlem için yetkiniz yok.'); }
        $id = absint($_POST['id'] ?? 0); check_admin_referer('bgpt_status_' . $id);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        if (!in_array($status, ['approved', 'cancelled', 'completed'], true)) { wp_die('Durum geçersiz.'); }
        global $wpdb;
        $before = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->table} WHERE id=%d", $id));
        $wpdb->update($this->table, ['status' => $status], ['id' => $id]);
        if ($before && $before !== $status) { do_action('bossgym_booking_status_changed', 'pt', $id, $status); }
        wp_safe_redirect(admin_url('admin.php?page=bossgym-pt&updated=1')); exit;
    }

    public function seed_and_integrate() {
        if (get_option('bossgym_pt_seed_version') === '1') { return; }
        $existing = get_posts(['post_type' => 'bg_pt_trainer', 'post_status' => ['publish', 'draft'], 'numberposts' => 1, 'fields' => 'ids']);
        if (!$existing) { $this->seed_trainers(); }
        $packages = get_posts(['post_type' => 'bg_pt_package', 'post_status' => ['publish', 'draft'], 'numberposts' => 1, 'fields' => 'ids']);
        if (!$packages) { $this->seed_packages(); }
        $this->integrate_pages();
        update_option('bossgym_pt_seed_version', '1', false);
    }

    private function seed_trainers() {
        $people = [
            ['Ece Yalçın','Fonksiyonel kuvvet','Temel hareket kalıpları ve kuvvet gelişimine odaklanan kurgusal demo antrenör profili.',6,'coach-ece-yalcin.webp','individual,group'],
            ['Mert Kaya','Kuvvet ve kondisyon','Aşamalı yüklenme ve kondisyon planlamasını anlatan kurgusal demo antrenör profili.',8,'coach-mert-kaya.webp','individual,group'],
            ['Deniz Arslan','Hareket ve mobilite','Hareket kalitesi ve esneklik çalışmalarını öne çıkaran kurgusal demo antrenör profili.',5,'coach-deniz-arslan.webp','individual'],
            ['Burak Demir','Grup antrenmanı','Takım enerjisi ve güvenli egzersiz tekniğini vurgulayan kurgusal demo antrenör profili.',7,'coach-burak-demir.webp','group'],
        ];
        foreach ($people as $p) {
            $id = wp_insert_post(['post_type' => 'bg_pt_trainer', 'post_status' => 'publish', 'post_title' => $p[0], 'post_content' => '']);
            if (is_wp_error($id) || !$id) { continue; }
            update_post_meta($id, '_bgpt_expertise', $p[1]); update_post_meta($id, '_bgpt_bio', $p[2]);
            update_post_meta($id, '_bgpt_experience', $p[3]); update_post_meta($id, '_bgpt_modes', $p[5]);
            update_post_meta($id, '_bgpt_demo_note', 'Kurgusal demo profil · Bu kişi ve özgeçmiş temsilîdir.');
            update_post_meta($id, '_bgpt_certificates', 'Temsili profil; sertifikalar doğrulanmadı.');
            update_post_meta($id, '_bgpt_hours', wp_json_encode([1=>['08:00','20:00'],2=>['08:00','20:00'],3=>['08:00','20:00'],4=>['08:00','20:00'],5=>['08:00','20:00'],6=>['09:00','17:00']]));
            $image = get_stylesheet_directory() . '/assets/images/home/' . $p[4];
            if (file_exists($image)) { update_post_meta($id, '_bgpt_photo_url', get_stylesheet_directory_uri() . '/assets/images/home/' . $p[4]); }
        }
    }

    private function seed_packages() {
        $items = [
            ['Bireysel Başlangıç · 4 seans','individual',4,60,2400,'Teknik değerlendirme ve temel kuvvet çalışması.',1],
            ['Bireysel Gelişim · 8 seans','individual',8,60,4400,'Düzenli kuvvet, kondisyon ve ilerleme takibi.',1],
            ['Grup Enerji · 4 ders','group',4,60,1200,'Küçük grup kuvvet ve kondisyon dersi.',6],
            ['Grup Ritim · 8 ders','group',8,60,2200,'Haftalık grup çalışması ve temel hareket çeşitliliği.',8],
        ];
        foreach ($items as $p) {
            $id = wp_insert_post(['post_type' => 'bg_pt_package', 'post_status' => 'publish', 'post_title' => $p[0]]);
            if (is_wp_error($id) || !$id) { continue; }
            update_post_meta($id, '_bgpt_mode', $p[1]); update_post_meta($id, '_bgpt_sessions', $p[2]); update_post_meta($id, '_bgpt_duration', $p[3]);
            update_post_meta($id, '_bgpt_fee', $p[4]); update_post_meta($id, '_bgpt_description', $p[5]); update_post_meta($id, '_bgpt_scope', $p[5]); update_post_meta($id, '_bgpt_capacity', $p[6]);
        }
    }

    private function integrate_pages() {
        $pt = get_post(17);
        if ($pt && strpos($pt->post_content, '[bossgym_pt_booking]') === false) { wp_update_post(['ID' => 17, 'post_content' => $pt->post_content . "\n<!-- wp:shortcode -->\n[bossgym_pt_booking]\n<!-- /wp:shortcode -->"]); }
        $coaches = get_post(18);
        if ($coaches && strpos($coaches->post_content, '[bossgym_pt_trainers]') === false) {
            $replacement = '<!-- wp:shortcode -->[bossgym_pt_trainers]<!-- /wp:shortcode -->';
            $updated = preg_replace('/<!-- wp:columns \{"className":"bg-coach-grid"\} -->.*?<!-- \/wp:columns -->/s', $replacement, $coaches->post_content, 1, $count);
            if (!empty($count)) { wp_update_post(['ID' => 18, 'post_content' => $updated]); }
        }
    }

    public function trainers_shortcode() {
        $trainers = get_posts(['post_type'=>'bg_pt_trainer','post_status'=>'publish','numberposts'=>-1,'orderby'=>'menu_order title','order'=>'ASC']);
        if (!$trainers) { return '<p>Antrenör profilleri henüz eklenmedi.</p>'; }
        $is_home = is_page(9);
        $html = '<p class="bgpt-profile-note" role="note">Antrenör profilleri tanıtım amacıyla kurgulanmıştır. Sertifika veya belge bilgileri doğrulanmadan yayımlanmaz.</p><div class="bgpt-trainer-grid' . ($is_home ? ' bgpt-trainer-grid--home' : ' bgpt-trainer-grid--directory') . '">';
        foreach ($trainers as $t) {
            $photo = get_post_meta($t->ID, '_bgpt_photo', true); $photo_url = $photo ? wp_get_attachment_image_url($photo, 'medium_large') : get_post_meta($t->ID, '_bgpt_photo_url', true);
            $demo_defaults = $this->demo_profile_defaults($t);
            $photo_path = $photo_url ? basename((string) wp_parse_url($photo_url, PHP_URL_PATH)) : '';
            $demo_photo_prefixes = ['coach-ece-yalcin', 'coach-mert-kaya', 'coach-deniz-arslan', 'coach-burak-demir'];
            $is_demo_photo = !$photo_url;
            foreach ($demo_photo_prefixes as $prefix) {
                if (strpos($photo_path, $prefix) === 0) { $is_demo_photo = true; break; }
            }
            if ($is_demo_photo && !empty($demo_defaults['photo_file'])) {
                $photo_url = get_stylesheet_directory_uri() . '/assets/images/home/' . rawurlencode($demo_defaults['photo_file']);
            }
            $profile = $this->trainer_profile_content($t);
            $bio = trim((string) $profile['bio']);
            $summary_words = $is_home ? 18 : 25;
            $summary = wp_trim_words($bio, $summary_words, '…');
            $mode_labels = array_map(static function ($mode) { return $mode === 'group' ? 'Grup antrenmanı' : 'Bireysel PT'; }, $profile['modes']);
            $html .= '<article id="bgpt-trainer-' . (int) $t->ID . '" class="bgpt-card ' . ($is_home ? 'bgpt-card--compact' : 'bgpt-card--directory') . '">';
            if ($photo_url) { $html .= '<img class="bgpt-trainer-photo" loading="lazy" src="' . esc_url($photo_url) . '" alt="' . esc_attr(get_the_title($t) . ' adlı kurgusal antrenör için temsilî portre') . '">'; }
            $html .= '<h3 class="bgpt-trainer-name">' . esc_html(get_the_title($t)) . '</h3><p class="bgpt-trainer-meta"><strong>' . esc_html($profile['expertise']) . '</strong> · ' . (int) $profile['experience'] . ' yıl deneyim</p><p class="bgpt-trainer-bio">' . esc_html($summary) . '</p>';
            if (!$is_home) {
                $html .= '<details class="bgpt-trainer-details"><summary>Profil ayrıntıları</summary><div class="bgpt-trainer-details__content"><div><strong>Özgeçmiş</strong>' . wpautop(esc_html($bio)) . '</div><p><strong>Uygun üyeler:</strong> ' . esc_html($profile['target_members']) . '</p><p><strong>Çalışma yaklaşımı:</strong> ' . esc_html($profile['approach']) . '</p><div><strong>Yetkinlikler</strong>' . wpautop(esc_html($profile['competencies'])) . '</div><p><strong>Antrenman türleri:</strong> ' . esc_html(implode(', ', $mode_labels) ?: 'Henüz tanımlanmamış') . '</p><p><strong>Çalışma saatleri:</strong> ' . esc_html($profile['hours']) . '</p>';
                if ($profile['certificates'] !== '') { $html .= '<p><strong>Eğitim / belge bilgisi:</strong> ' . esc_html($profile['certificates']) . '</p>'; }
                $html .= '</div></details>';
            }
            $html .= '<a class="bgpt-link" href="' . esc_url(add_query_arg('trainer', $t->ID, get_permalink(17) . '#pt-randevu')) . '">' . ($is_home ? 'Randevu iste' : 'Bu antrenörle randevu iste') . '</a></article>';
        }
        return $html . '</div>';
    }

    private function published_items($type) { return get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>-1,'orderby'=>'menu_order title','order'=>'ASC']); }
    private function package_trainers($package) {
        $mode = get_post_meta($package->ID, '_bgpt_mode', true);
        $ids = array_filter(array_map('absint', explode(',', (string)get_post_meta($package->ID, '_bgpt_trainers', true))));
        $trainers = $this->published_items('bg_pt_trainer');
        return array_values(array_filter($trainers, function($t) use($ids,$mode) { $modes = explode(',', (string)get_post_meta($t->ID, '_bgpt_modes', true)); return (!$ids || in_array($t->ID,$ids,true)) && in_array($mode,$modes,true); }));
    }

    public function weekly_schedule_shortcode() {
        $days = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
        $levels = ['beginner' => 'Başlangıç', 'intermediate' => 'Orta', 'all' => 'Tüm seviyeler'];
        $trainers = $this->published_items('bg_pt_trainer');
        $packages = $this->published_items('bg_pt_package');
        $rows = [];

        foreach ($packages as $package) {
            $mode = get_post_meta($package->ID, '_bgpt_mode', true);
            $duration = (int) get_post_meta($package->ID, '_bgpt_duration', true);
            if (!in_array($mode, ['individual', 'group'], true) || $duration < 30 || $duration % 30 !== 0) { continue; }

            $level = sanitize_key(get_post_meta($package->ID, '_bgpt_level', true));
            $capacity = $mode === 'group' ? max(1, (int) get_post_meta($package->ID, '_bgpt_capacity', true)) . ' kişi' : '1 kişi';
            $allowed_trainers = array_map(static function ($item) { return (int) $item->ID; }, $this->package_trainers($package));

            foreach ($trainers as $trainer) {
                if (!in_array((int) $trainer->ID, $allowed_trainers, true)) { continue; }
                $hours = json_decode((string) get_post_meta($trainer->ID, '_bgpt_hours', true), true);
                if (!is_array($hours)) { continue; }

                $schedule_days = [];
                foreach ($days as $day => $day_name) {
                    $window = $hours[$day] ?? null;
                    if (!is_array($window) || !isset($window[0], $window[1])) { continue; }
                    $schedule_days[] = '<span class="bgpt-weekly-day"><strong>' . esc_html($day_name) . '</strong><span>' . esc_html($window[0] . '–' . $window[1]) . '</span></span>';
                }
                if (!$schedule_days) { continue; }

                $program = '<div class="bgpt-weekly-program" data-package-id="' . (int) $package->ID . '" data-mode="' . esc_attr($mode) . '"><strong>' . esc_html(get_the_title($package)) . '</strong><span>' . esc_html($mode === 'group' ? 'Grup' : 'Bireysel') . ' · ' . esc_html($levels[$level] ?? 'Yönetici tanımı bekleniyor') . '</span><span>' . $duration . ' dk · Kontenjan: ' . esc_html($capacity) . '</span></div>';
                $rows[] = '<tr data-trainer-id="' . (int) $trainer->ID . '" data-package-ids="' . (int) $package->ID . '"><td data-label="PT paketi">' . $program . '</td><td data-label="Antrenör">' . esc_html(get_the_title($trainer)) . '</td><td data-label="Çalışma günleri ve saatleri"><div class="bgpt-weekly-days">' . implode('', $schedule_days) . '</div></td></tr>';
            }
        }

        if (!$rows) {
            return '<p class="bgpt-schedule-note">Aktif antrenör çalışma saatleri ve eşleşen PT paketleri tanımlandığında haftalık program burada gösterilir.</p>';
        }

        return '<section class="bgpt-weekly-wrap" aria-label="Antrenörlerin çalışma ve paket programı"><p class="bgpt-schedule-note">Bu özet, her paket ve antrenör için haftalık çalışma günlerini gösterir. Seçilen tarih için gerçek müsaitlik canlı takvimden hesaplanır; grup kontenjanı ve çakışma rezervasyon sırasında yeniden doğrulanır.</p><div class="bgpt-weekly-scroll"><table class="bgpt-table bgpt-weekly"><thead><tr><th>PT paketi</th><th>Antrenör</th><th>Çalışma günleri ve saatleri</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table><p class="bgpt-weekly-empty" hidden>Bu seçimle eşleşen haftalık program bulunamadı.</p></div></section>';
    }
    public function booking_shortcode() {
        if (!is_user_logged_in()) { return '<section id="pt-randevu" class="bgpt-panel"><h2>PT randevu başvurusu</h2><p>Randevu isteği göndermek için üye girişi yapın. <a href="' . esc_url(get_permalink(16)) . '#bgm-login">Üye girişi</a>.</p></section>'; }
        $packages = $this->published_items('bg_pt_package');
        $mode = isset($_GET['pt_mode']) ? sanitize_key(wp_unslash($_GET['pt_mode'])) : '';
        if (!in_array($mode, ['individual', 'group'], true)) { $mode = ''; }
        $package_id = isset($_GET['pt_package']) ? absint($_GET['pt_package']) : 0;
        if ($package_id) {
            $requested = get_post($package_id);
            if (!$requested || $requested->post_type !== 'bg_pt_package' || $requested->post_status !== 'publish') { $package_id = 0; }
            elseif (!$mode) { $mode = get_post_meta($package_id, '_bgpt_mode', true); }
        }
        $trainer_id = isset($_GET['trainer']) ? absint($_GET['trainer']) : 0;
        $html = '<section id="pt-randevu" class="bgpt-panel"><div class="bgpt-kicker">BOSS GYM BURSA · PT</div><h2>PT paketleri ve randevu başvurusu</h2><p>Paket ücretleri temsili örnektir. Ödeme alınmaz; başvurunuz yönetici onayına kadar beklemede kalır. Bekleyen başvurular saat ve kontenjanı bloke eder.</p><div class="bgpt-package-grid">';
        foreach ($packages as $item) {
            $item_mode = get_post_meta($item->ID, '_bgpt_mode', true); $kind = $item_mode === 'group' ? 'Grup' : 'Bireysel';
            $html .= '<article class="bgpt-package-card" data-mode="' . esc_attr($item_mode) . '"' . ($mode && $mode !== $item_mode ? ' hidden' : '') . '><span>' . esc_html($kind) . ' · ' . (int)get_post_meta($item->ID, '_bgpt_sessions', true) . ' seans</span><h3>' . esc_html(get_the_title($item)) . '</h3><p>' . esc_html(get_post_meta($item->ID, '_bgpt_description', true)) . '</p><p>' . (int)get_post_meta($item->ID, '_bgpt_duration', true) . ' dakika · <strong>₺' . esc_html(number_format_i18n((int)get_post_meta($item->ID, '_bgpt_fee', true))) . ' temsili</strong></p><p>' . esc_html(get_post_meta($item->ID, '_bgpt_scope', true)) . '</p></article>';
        }
        $html .= '</div>';
        if (isset($_GET['bgpt_notice'])) { $html .= '<p class="bgpt-notice" role="status">' . esc_html($this->notice_text(sanitize_key(wp_unslash($_GET['bgpt_notice'])))) . '</p>'; }
        $html .= '<form class="bgpt-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_pt_book">' . wp_nonce_field('bgpt_book','_bgpt_nonce',true,false);
        $html .= '<label>Antrenman türü<select name="mode" class="bgpt-mode" required><option value="">Tür seçin</option><option value="individual"' . selected($mode, 'individual', false) . '>Bireysel PT</option><option value="group"' . selected($mode, 'group', false) . '>Grup antrenmanı</option></select></label>';
        $html .= '<label>PT paketi<select name="package_id" class="bgpt-package" required><option value="">Paket seçin</option>';
        foreach ($packages as $p) { $item_mode=get_post_meta($p->ID,'_bgpt_mode',true); $label=$item_mode==='group'?'Grup':'Bireysel'; $html.='<option value="'.(int)$p->ID.'" data-mode="'.esc_attr($item_mode).'"' . selected($package_id, $p->ID, false) . '>'.esc_html(get_the_title($p).' · '.$label.' · '.(int)get_post_meta($p->ID,'_bgpt_duration',true).' dk · ₺'.number_format_i18n((int)get_post_meta($p->ID,'_bgpt_fee',true)).' temsili').'</option>'; }
        $html .= '</select></label><label>Antrenör<select name="trainer_id" class="bgpt-trainer" data-requested-trainer="' . $trainer_id . '" required><option value="">Antrenör seçin</option>';
        foreach ($packages as $p) { foreach ($this->package_trainers($p) as $t) { $html.='<option data-pack="'.(int)$p->ID.'" value="'.(int)$t->ID.'">'.esc_html(get_the_title($t).' · '.get_post_meta($t->ID,'_bgpt_expertise',true)).'</option>'; } }
        $html .= '</select><small class="bgpt-field-help">Önce türü ve paketi seçin; uygun antrenörler listelenir. Antrenör veya tarih değiştiğinde müsait saatler yenilenir.</small></label><label>Tarih<input type="date" name="date" class="bgpt-date" min="' . esc_attr(current_datetime()->format('Y-m-d')) . '" required></label><label>Uygun saat<select name="time" class="bgpt-time" required disabled><option value="">Önce paket, antrenör ve tarih seçin</option></select></label><div class="bgpt-slot-grid" role="group" aria-label="PT uygunluk saatleri"></div><p class="bgpt-slot-status" role="status" aria-live="polite" aria-atomic="true"></p><label>Not (isteğe bağlı)<textarea name="note" rows="3" maxlength="500"></textarea></label><button class="bgpt-button" type="submit">Randevu başvurusu gönder</button></form><p class="bgpt-fine">Aktif üyelik zorunluluğu: ' . (get_option('bossgym_pt_active_membership_required','0')==='1'?'Evet':'Hayır') . '. Randevular Türkiye saatine göre listelenir.</p></section>';
        wp_enqueue_script('bossgym-pt', BOSSGYM_CORE_URL.'assets/js/pt.js', ['jquery'], (string) filemtime(BOSSGYM_CORE_DIR.'assets/js/pt.js'), true);
        wp_localize_script('bossgym-pt','BossGymPT',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('bgpt_slots'),'loading'=>'Uygun saatler yükleniyor…','empty'=>'Bu tarih için uygun saat yok.','requestedTrainer'=>$trainer_id]);
        return $html;
    }
    public function ajax_slots() {
        if (!is_user_logged_in() || !check_ajax_referer('bgpt_slots','nonce',false)) { wp_send_json_error(['message'=>'Oturum doğrulanamadı.'],403); }
        $trainer = absint($_POST['trainer_id'] ?? 0); $package = absint($_POST['package_id'] ?? 0); $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $result = $this->available_slots($trainer,$package,$date);
        if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()],400); }
        wp_send_json_success(['slots'=>$result, 'availability'=>$this->availability_grid($trainer, $package, $date, $result)]);
    }

    private function availability_grid($trainer_id, $package_id, $date, $available) {
        $hours = json_decode((string) get_post_meta($trainer_id, '_bgpt_hours', true), true);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        if (!$day) { return []; }
        $schedule = is_array($hours) ? ($hours[(int) $day->format('N')] ?? null) : null;
        $from = $schedule ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $schedule[0], wp_timezone()) : null;
        $until = $schedule ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $schedule[1], wp_timezone()) : null;
        $duration = max(30, (int) get_post_meta($package_id, '_bgpt_duration', true));
        $now = current_datetime(); $cursor = $day->setTime(0, 0); $limit = $day->modify('+1 day'); $result = [];
        for (; $cursor < $limit; $cursor = $cursor->modify('+30 minutes')) {
            $end = $cursor->modify('+' . $duration . ' minutes');
            $inside = $from && $until && $cursor >= $from && $end <= $until;
            $state = $cursor <= $now ? 'past' : (!$inside ? 'closed' : (in_array($cursor->format('H:i'), $available, true) ? 'available' : 'full'));
            $result[] = ['time' => $cursor->format('H:i'), 'state' => $state];
        }
        return $result;
    }

    private function available_slots($trainer_id,$package_id,$date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { return new WP_Error('date','Tarih geçersiz.'); }
        $package=get_post($package_id); $trainer=get_post($trainer_id);
        if (!$package || $package->post_type!=='bg_pt_package' || $package->post_status!=='publish' || !$trainer || $trainer->post_type!=='bg_pt_trainer' || $trainer->post_status!=='publish' || !in_array($trainer_id,array_map(function($t){return $t->ID;},$this->package_trainers($package)),true)) { return new WP_Error('selection','Paket veya antrenör seçimi geçersiz.'); }
        $tz=wp_timezone(); $start_day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);
        if (!$start_day || $start_day->format('Y-m-d')!==$date || $start_day->format('Y-m-d')<current_datetime()->format('Y-m-d')) { return new WP_Error('past','Geçmiş tarihe randevu oluşturulamaz.'); }
        $hours=json_decode((string)get_post_meta($trainer_id,'_bgpt_hours',true),true); $weekday=(int)$start_day->format('N');
        if (empty($hours[$weekday]) || !is_array($hours[$weekday])) { return []; }
        $duration=max(30,(int)get_post_meta($package_id,'_bgpt_duration',true)); if ($duration%30!==0) { return new WP_Error('duration','Paket süresi 30 dakikanın katı olmalıdır.'); }
        $from=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$hours[$weekday][0],$tz); $until=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$hours[$weekday][1],$tz);
        if (!$from || !$until) { return []; }
        $now=current_datetime(); $slots=[];
        for($cursor=$from;$cursor<$until;$cursor=$cursor->modify('+30 minutes')) {
            $end=$cursor->modify('+'.$duration.' minutes'); if($end>$until || $cursor<=$now) { continue; }
            if($this->slot_available($trainer_id,$package_id,$cursor,$end)) { $slots[]=$cursor->format('H:i'); }
        }
        return $slots;
    }

    private function slot_available($trainer_id,$package_id,DateTimeImmutable $start,DateTimeImmutable $end) {
        global $wpdb; $s=$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $e=$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $mode=get_post_meta($package_id,'_bgpt_mode',true);
        if ($mode === 'group' && is_user_logged_in()) {
            $mine = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE user_id=%d AND trainer_id=%d AND package_id=%d AND session_start=%s AND status IN ('pending','approved')", get_current_user_id(), $trainer_id, $package_id, $s));
            if ($mine) { return false; }
        }
        $overlap=$wpdb->get_results($wpdb->prepare("SELECT package_id,session_start,mode FROM {$this->table} WHERE trainer_id=%d AND status IN ('pending','approved') AND session_start < %s AND session_end > %s",$trainer_id,$e,$s));
        if(!$overlap) { return true; }
        $capacity=(int)get_post_meta($package_id,'_bgpt_capacity',true);
        foreach($overlap as $row) { if($mode!=='group' || $row->mode!=='group' || (int)$row->package_id!==$package_id || $row->session_start!==$s) { return false; } }
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE trainer_id=%d AND package_id=%d AND session_start=%s AND status IN ('pending','approved')",$trainer_id,$package_id,$s));
        return $count < max(1,$capacity);
    }

    public function book() {
        if (!is_user_logged_in()) { $this->redirect_notice('login'); }
        if (empty($_POST['_bgpt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bgpt_nonce'])),'bgpt_book')) { $this->redirect_notice('invalid'); }
        $user=get_current_user_id();
        if(get_option('bossgym_pt_active_membership_required','0')==='1' && !$this->has_active_membership($user)) { $this->redirect_notice('membership'); }
        $trainer=absint($_POST['trainer_id']??0); $package=absint($_POST['package_id']??0); $date=sanitize_text_field(wp_unslash($_POST['date']??'')); $time=sanitize_text_field(wp_unslash($_POST['time']??'')); $note=sanitize_textarea_field(wp_unslash($_POST['note']??''));
        $posted_mode = sanitize_key(wp_unslash($_POST['mode'] ?? ''));
        $package_mode = get_post_meta($package, '_bgpt_mode', true);
        if (!in_array($package_mode, ['individual', 'group'], true) || $posted_mode !== $package_mode) { $this->redirect_notice('invalid'); }
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) { $this->redirect_notice('invalid'); }
        $available=$this->available_slots($trainer,$package,$date);
        if(is_wp_error($available)||!in_array($time,$available,true)) { $this->redirect_notice('unavailable'); }
        $tz=wp_timezone(); $local=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,$tz); $minutes=max(30,(int)get_post_meta($package,'_bgpt_duration',true));
        $start=$local->setTimezone(new DateTimeZone('UTC')); $end=$local->modify('+'.$minutes.' minutes')->setTimezone(new DateTimeZone('UTC'));
        global $wpdb; $lock='bgpt:'.$trainer.':'.$date; $got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 8)',$lock));
        if($got!==1) { $this->redirect_notice('busy'); }
        $wpdb->query('START TRANSACTION');
        $s=$start->format('Y-m-d H:i:s'); $e=$end->format('Y-m-d H:i:s'); $mode=get_post_meta($package,'_bgpt_mode',true);
        $overlap=$wpdb->get_results($wpdb->prepare("SELECT package_id,session_start,mode FROM {$this->table} WHERE trainer_id=%d AND status IN ('pending','approved') AND session_start < %s AND session_end > %s FOR UPDATE",$trainer,$e,$s));
        $okay=true;
        if($overlap) {
            foreach($overlap as $row) { if($mode!=='group'||$row->mode!=='group'||(int)$row->package_id!==$package||$row->session_start!==$s) {$okay=false;break;} }
            if($okay) { $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE trainer_id=%d AND package_id=%d AND session_start=%s AND status IN ('pending','approved')",$trainer,$package,$s)); if($count >= max(1,(int)get_post_meta($package,'_bgpt_capacity',true))) {$okay=false;} }
            if($okay) { $mine=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE user_id=%d AND trainer_id=%d AND package_id=%d AND session_start=%s AND status IN ('pending','approved')",$user,$trainer,$package,$s)); if($mine) {$okay=false;} }
        }
        if(!$okay) { $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); $this->redirect_notice('unavailable'); }
        $inserted=$wpdb->insert($this->table,['user_id'=>$user,'trainer_id'=>$trainer,'package_id'=>$package,'session_start'=>$s,'session_end'=>$e,'status'=>'pending','mode'=>$mode,'note'=>$note,'created_utc'=>gmdate('Y-m-d H:i:s')],['%d','%d','%d','%s','%s','%s','%s','%s','%s']);
        if(!$inserted) { $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); $this->redirect_notice('error'); }
        $booking_id = (int) $wpdb->insert_id;
        $wpdb->query('COMMIT'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        do_action('bossgym_booking_created', 'pt', $booking_id);
        $this->redirect_notice('created');
    }

    public function member_shortcode() {
        if(!is_user_logged_in()) { return '<div class="bgpt-panel"><h2>PT randevularım</h2><p>Randevularınızı görüntülemek için giriş yapın.</p></div>'; }
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE user_id=%d ORDER BY session_start DESC",get_current_user_id()));
        $html='<section class="bgpt-panel bgm-dashboard-pt"><div class="bgpt-kicker">ÜYE PANELİ</div><h2>PT randevularım</h2><div class="bgpt-table-wrap"><table class="bgpt-table"><thead><tr><th>Tarih</th><th>Antrenör</th><th>Paket</th><th>Tür</th><th>Durum</th></tr></thead><tbody>';
        foreach($rows as $r) { $html.='<tr><td>'.esc_html($this->local_datetime($r->session_start)->format('d.m.Y H:i')).'</td><td>'.esc_html(get_the_title($r->trainer_id)).'</td><td>'.esc_html(get_the_title($r->package_id)).'</td><td>'.esc_html($r->mode==='group'?'Grup':'Bireysel').'</td><td><span class="bgpt-status bgpt-status--'.esc_attr($r->status).'">'.esc_html($this->status_label($r->status)).'</span></td></tr>'; }
        if(!$rows) { $html.='<tr><td colspan="5">Henüz PT randevunuz yok. <a href="'.esc_url(get_permalink(17).'#pt-randevu').'">Randevu başvurusu oluşturun.</a></td></tr>'; }
        return $html.'</tbody></table></div></section>';
    }

    public function dashboard_output($html,$user_id) {
        $placeholder='<article class="bgm-card bgm-dashboard-card bgm-dashboard-card--placeholder"><h2>PT randevuları</h2><p>Randevu özelliği sonraki geliştirme aşamasında hazırlanacaktır.</p><span class="bgm-tag">Henüz geliştirilmedi</span></article>';
        return str_replace($placeholder, do_shortcode('[bossgym_pt_member_appointments]'), $html);
    }

    private function status_label($status) { return ['pending'=>'Beklemede','approved'=>'Onaylandı','cancelled'=>'İptal edildi','completed'=>'Tamamlandı'][$status]??'Bilinmiyor'; }
    private function has_active_membership($user_id) {
        if (get_user_meta($user_id, 'bossgym_membership_status', true) !== 'active') { return false; }
        $today = current_datetime()->format('Y-m-d');
        $start = get_user_meta($user_id, 'bossgym_membership_start', true);
        $end = get_user_meta($user_id, 'bossgym_membership_end', true);
        return (!$start || $start <= $today) && (!$end || $end >= $today);
    }
    private function local_datetime($utc) { return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(wp_timezone()); }
    private function notice_text($key) { return ['created'=>'Başvurunuz alındı. Yönetici onayı bekleniyor; ödeme alınmadı.','unavailable'=>'Seçilen saat artık uygun değil. Lütfen başka bir saat seçin.','busy'=>'Takvim güncelleniyor. Lütfen tekrar deneyin.','membership'=>'Randevu için aktif üyelik gereklidir.','login'=>'Randevu başvurusu için giriş yapın.','invalid'=>'Form doğrulanamadı. Bilgileri kontrol edip tekrar deneyin.','error'=>'Başvuru kaydedilemedi. Lütfen tekrar deneyin.'][$key]??''; }
    private function redirect_notice($key) { $url=wp_get_referer()?:get_permalink(17); wp_safe_redirect(add_query_arg('bgpt_notice',$key,$url).'#pt-randevu'); exit; }
}
