<?php
if (!defined('ABSPATH')) { exit; }

/** Demo turnstile: opaque member tokens and private entry/exit events. */
final class BossGym_Turnstile {
    const DB_VERSION = '1.0.0';
    const TOKEN_META = 'bossgym_turnstile_token';
    private $events;

    public function __construct() {
        global $wpdb;
        $this->events = $wpdb->prefix . 'bg_turnstile_events';
        add_action('init', [$this, 'install_if_needed'], 4);
        add_action('admin_menu', [$this, 'admin_menu'], 60);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_bossgym_turnstile_scan', [$this, 'scan']);
        add_action('wp_ajax_bossgym_turnstile_stats', [$this, 'stats_ajax']);
        add_action('wp_ajax_bossgym_member_occupancy', [$this, 'member_occupancy_ajax']);
        add_action('wp_ajax_nopriv_bossgym_member_occupancy', [$this, 'member_occupancy_ajax']);
        add_filter('bossgym_dashboard_output', [$this, 'dashboard_output'], 30, 2);
        add_action('wp_enqueue_scripts', [$this, 'member_qr_assets'], 30);
    }

    public function member_qr_assets() {
        if (!is_user_logged_in() || (!is_page(35) && !is_page('uye-paneli'))) { return; }
        $base = BOSSGYM_CORE_DIR . 'assets/js/';
        wp_enqueue_script('bossgym-qrcode-generator', BOSSGYM_CORE_URL . 'assets/js/vendor/qrcode-generator.js', [], '2.0.4', true);
        wp_enqueue_script('bossgym-member-qr', BOSSGYM_CORE_URL . 'assets/js/member-qr.js', ['bossgym-qrcode-generator'], (string) filemtime($base . 'member-qr.js'), true);
        wp_enqueue_script('bossgym-member-occupancy', BOSSGYM_CORE_URL . 'assets/js/member-occupancy.js', [], filemtime(BOSSGYM_CORE_DIR . 'assets/js/member-occupancy.js'), true);
        wp_localize_script('bossgym-member-occupancy', 'BossGymMemberOccupancy', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bossgym_member_occupancy'),
            'loading' => 'Salon doluluk verileri yükleniyor.',
            'error' => 'Salon doluluk verisi şu anda alınamıyor. Lütfen daha sonra yeniden deneyin.',
        ]);
    }

    public function install_if_needed() {
        if (get_option('bossgym_turnstile_db_version') === self::DB_VERSION) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            direction varchar(8) NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_recent (user_id,id),
            KEY recent (id,direction)
        ) $collate;");
        if (get_option('bossgym_turnstile_capacity') === false) { add_option('bossgym_turnstile_capacity', 100, '', false); }
        update_option('bossgym_turnstile_db_version', self::DB_VERSION, false);
    }

    private function allowed() { return current_user_can('manage_options') || current_user_can('bossgym_manage_turnstile'); }
    public function admin_menu() {
        add_submenu_page('bossgym-overview', 'QR Turnike', 'QR Turnike', 'manage_options', 'bossgym-turnstile', [$this, 'scanner_page']);
        add_submenu_page('bossgym-overview', 'Canlı Doluluk', 'Canlı Doluluk', 'manage_options', 'bossgym-occupancy', [$this, 'occupancy_page']);
        add_submenu_page('bossgym-overview', 'Turnike Hareketleri', 'Turnike Hareketleri', 'manage_options', 'bossgym-turnstile-events', [$this, 'events_page']);
    }

    public function admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, ['bossgym-turnstile', 'bossgym-occupancy', 'bossgym-turnstile-events'], true)) { return; }
        if ($page === 'bossgym-turnstile') {
            wp_enqueue_script('bossgym-jsqr', BOSSGYM_CORE_URL . 'assets/js/vendor/jsQR.js', [], '1.4.0', true);
        }
        wp_enqueue_script('bossgym-turnstile-admin', BOSSGYM_CORE_URL . 'assets/js/turnstile-admin.js', $page === 'bossgym-turnstile' ? ['bossgym-jsqr'] : [], filemtime(BOSSGYM_CORE_DIR . 'assets/js/turnstile-admin.js'), true);
        wp_localize_script('bossgym-turnstile-admin', 'BossGymTurnstile', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('bossgym_turnstile'), 'unsupported' => 'Bu tarayıcı QR kamera okumayı desteklemiyor. Manuel kod alanını kullanın.']);
    }

    public function scanner_page() {
        if (!$this->allowed()) { wp_die('Bu alana erişim izniniz yok.'); }
        echo '<div class="wrap bossgym-admin"><header class="bossgym-admin__header"><h1>Demo QR turnike</h1><p>Yalnızca onaylı ve geçerli üyelikler geçiş yapabilir. Bu ekran gerçek turnike donanımına bağlı değildir.</p></header><div class="bossgym-admin__card"><p><label>İşlem <select id="bgts-direction"><option value="entry">Giriş</option><option value="exit">Çıkış</option></select></label></p><video id="bgts-video" playsinline muted style="width:min(100%,560px);background:#111"></video><p><button type="button" class="button" id="bgts-start">Kamerayı başlat</button> <button type="button" class="button" id="bgts-stop" disabled>Durdur</button></p><form id="bgts-manual"><label>Üye kodu <input type="text" name="code" minlength="32" maxlength="64" autocomplete="off" required></label> <button class="button button-primary">Kodu doğrula</button></form><div id="bgts-result" role="status" aria-live="polite"></div></div><section class="bossgym-admin__card"><h2>Canlı doluluk</h2><p>Başarılı giriş ve çıkış hareketlerinden hesaplanır.</p><div id="bgts-occupancy" aria-live="polite"><p>Doluluk verileri yükleniyor…</p></div></section></div>';
    }

    public function occupancy_page() {
        if (!$this->allowed()) { wp_die('Bu alana erişim izniniz yok.'); }
        if (isset($_POST['bgts_capacity'])) {
            check_admin_referer('bossgym_turnstile_capacity');
            update_option('bossgym_turnstile_capacity', max(1, absint($_POST['bgts_capacity'])), false);
        }
        echo '<div class="wrap bossgym-admin"><header class="bossgym-admin__header"><h1>Canlı salon doluluğu</h1><p>Sayım yalnızca başarıyla içeri alınmış, henüz çıkışı kaydedilmemiş üyelerden yapılır.</p></header><form method="post" class="bossgym-admin__form">';
        wp_nonce_field('bossgym_turnstile_capacity');
        echo '<label>Maksimum kapasite <input type="number" min="1" name="bgts_capacity" value="' . (int) get_option('bossgym_turnstile_capacity', 100) . '"></label> <button class="button">Kaydet</button></form><div id="bgts-occupancy" data-ajax="1"><p>Doluluk verileri yükleniyor…</p></div></div>';
    }

    public function events_page() {
        if (!$this->allowed()) { wp_die('Bu alana erişim izniniz yok.'); }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT e.id,e.user_id,e.direction,e.created_utc,u.display_name FROM {$this->events} e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id ORDER BY e.id DESC LIMIT 100");
        echo '<div class="wrap bossgym-admin"><header class="bossgym-admin__header"><h1>Turnike hareketleri</h1><p>Bu bilgiler yalnızca yetkili yöneticilere açıktır.</p></header><div class="bossgym-admin__table-wrap"><table class="widefat striped bossgym-admin__table"><thead><tr><th>Tarih</th><th>Üye</th><th>İşlem</th></tr></thead><tbody>';
        foreach ((array) $rows as $row) { echo '<tr><td>' . esc_html(get_date_from_gmt($row->created_utc, 'd.m.Y H:i')) . '</td><td>' . esc_html($row->display_name ?: 'Hesap bulunamadı') . '</td><td>' . esc_html($row->direction === 'entry' ? 'Giriş' : 'Çıkış') . '</td></tr>'; }
        if (!$rows) { echo '<tr><td colspan="3">Henüz hareket yok.</td></tr>'; }
        echo '</tbody></table></div></div>';
    }

    private function ensure_token($user_id) {
        $token = (string) get_user_meta($user_id, self::TOKEN_META, true);
        if (strlen($token) < 40) { $token = bin2hex(random_bytes(24)); update_user_meta($user_id, self::TOKEN_META, $token); }
        return $token;
    }

    private function membership_access_state($user_id) {
        $status = (string) get_user_meta($user_id, 'bossgym_membership_status', true);
        $application = (string) get_user_meta($user_id, 'bossgym_application_status', true);
        $start = trim((string) get_user_meta($user_id, 'bossgym_membership_start', true));
        $end = trim((string) get_user_meta($user_id, 'bossgym_membership_end', true));
        $today = current_datetime()->format('Y-m-d');
        $valid_date = static function ($date) {
            if ($date === '') { return true; }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) { return false; }
            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        };

        if ($status === 'active') {
            if (!$valid_date($start) || !$valid_date($end)) {
                $reason = 'invalid_dates';
            } elseif ($start !== '' && $start > $today) {
                $reason = 'not_started';
            } elseif ($end !== '' && $end < $today) {
                $reason = 'expired';
            } else {
                return ['valid' => true, 'reason' => 'active', 'start' => $start, 'end' => $end];
            }
        } elseif ($status === 'pending' || ($status === '' && $application === 'pending')) {
            $reason = 'pending';
        } elseif ($status === 'expired') {
            $reason = 'expired';
        } else {
            $reason = 'inactive';
        }

        return ['valid' => false, 'reason' => $reason, 'start' => $start, 'end' => $end];
    }

    private function member_card($user_id) {
        $access = $this->membership_access_state($user_id);
        $start = $access['start'];
        $end = $access['end'];
        if (!$access['valid']) {
            $reason = '';
            if ($access['reason'] === 'pending') {
                $reason = 'Onay bekliyor. QR kodunuz &#252;yelik ba&#351;vurusu onayland&#305;ktan sonra g&#246;sterilir.';
            } elseif ($access['reason'] === 'not_started') {
                $reason = '&#220;yelik hen&#252;z ba&#351;lamad&#305;. Ba&#351;lang&#305;&#231; tarihi: ' . esc_html(mysql2date(get_option('date_format') ?: 'd.m.Y', $start)) . '.';
            } elseif ($access['reason'] === 'expired') {
                $reason = '&#220;yelik s&#252;resi doldu veya y&#246;netici taraf&#305;ndan sona erdirildi.';
                if ($end !== '') { $reason .= ' Biti&#351; tarihi: ' . esc_html(mysql2date(get_option('date_format') ?: 'd.m.Y', $end)) . '.'; }
            } elseif ($access['reason'] === 'invalid_dates') {
                $reason = '&#220;yelik tarihleri ge&#231;ersiz. Y&#246;neticinin tarih bilgilerini kontrol etmesi gerekiyor.';
            } else {
                $reason = 'Aktif &#252;yelik yok. QR kod yaln&#305;zca onaylanm&#305;&#351; aktif &#252;yeler i&#231;in g&#246;sterilir.';
            }
            return '<article class="bgm-card bgm-dashboard-card"><h2>Demo QR turnike</h2><p class="bgts-membership-validity"><strong>&#220;yelik durumu:</strong> ' . $reason . '</p></article>';
        }

        $token = $this->ensure_token($user_id);
        global $wpdb;
        $last = $wpdb->get_row($wpdb->prepare("SELECT direction,created_utc FROM {$this->events} WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user_id));
        $last_text = $last ? (($last->direction === 'entry' ? 'Son giri&#351;: ' : 'Son &#231;&#305;k&#305;&#351;: ') . get_date_from_gmt($last->created_utc, 'd.m.Y H:i')) : 'Hen&#252;z turnike hareketi yok.';
        $valid_until = $end !== '' ? esc_html(mysql2date(get_option('date_format') ?: 'd.m.Y', $end)) : 'S&#252;re s&#305;n&#305;r&#305; tan&#305;mlanmam&#305;&#351;';
        $valid_from = $start !== '' ? esc_html(mysql2date(get_option('date_format') ?: 'd.m.Y', $start)) : 'Tan&#305;mlanmam&#305;&#351;';

        return '<article class="bgm-card bgm-dashboard-card bgts-member-card"><h2>Demo QR turnike</h2><p>&#220;yeli&#287;iniz ge&#231;erli. QR kodunuz mevcut yetkili okuyucuda do&#287;rulanabilir; bu demo sistem fiziksel turnikeye ba&#287;l&#305; de&#287;ildir.</p><div class="bgts-member-qr" data-code="' . esc_attr($token) . '" role="img" aria-label="Ki&#351;isel &#252;yelik QR kodu"><span class="bgts-qr-loading">QR haz&#305;rlan&#305;yor...</span></div><p class="bgts-membership-validity"><strong>&#220;yelik durumu:</strong> Aktif ve ge&#231;erli<br><strong>Ba&#351;lang&#305;&#231;:</strong> ' . $valid_from . '<br><strong>Son ge&#231;erlilik:</strong> ' . $valid_until . '</p><details class="bgts-member-code-details"><summary>Manuel yedek kod</summary><code class="bgts-member-code">' . esc_html($token) . '</code></details><p>' . $last_text . '</p></article>';
    }
    public function dashboard_output($html, $user_id) {
        if (!is_user_logged_in() || get_current_user_id() !== (int) $user_id) { return $html; }
        $pattern = '~<article class="bgm-card bgm-dashboard-card"><h2>Demo QR alan[^<]*</h2>.*?</article>~su';
        $html = preg_replace_callback($pattern, function () use ($user_id) { return $this->member_card((int) $user_id); }, $html, 1);
        $card = $this->member_occupancy_card();
        $position = strpos($html, '<section class="bgm-card"><h2>');
        if ($position !== false) { $html = substr_replace($html, $card, $position, 0); }
        return $html;
    }

    private function member_occupancy_card() {
        return '<section class="bgm-card bgm-occupancy" aria-labelledby="bgm-occupancy-title"><header class="bgm-occupancy__header"><div><div class="bgm-eyebrow">TESİS BİLGİSİ · DEMO</div><h2 id="bgm-occupancy-title">Salon Doluluk Durumu</h2></div><p class="bgm-occupancy__demo-note">QR giriş ve çıkış kayıtlarından hesaplanır. Fiziksel turnikeye bağlı değildir.</p></header><p class="bgm-occupancy__status" data-occupancy-status role="status" aria-live="polite">Salon doluluk verileri yükleniyor.</p><div class="bgm-occupancy__grid" data-occupancy-grid hidden><div class="bgm-occupancy__stat"><span>İçeride</span><strong data-occupancy-value="inside">—</strong><small>kişi</small></div><div class="bgm-occupancy__stat"><span>Erkek</span><strong data-occupancy-value="male">—</strong><small>kişi</small></div><div class="bgm-occupancy__stat"><span>Kadın</span><strong data-occupancy-value="female">—</strong><small>kişi</small></div><div class="bgm-occupancy__stat"><span>Belirtilmedi</span><strong data-occupancy-value="unspecified">—</strong><small>kişi</small></div><div class="bgm-occupancy__stat"><span>Kapasite</span><strong data-occupancy-value="capacity">—</strong><small>kişi</small></div><div class="bgm-occupancy__stat"><span>Doluluk</span><strong><span data-occupancy-value="percentage">—</span>%</strong><small>kapasite</small></div></div><div class="bgm-occupancy__progress-wrap" data-occupancy-progress-wrap hidden><progress class="bgm-occupancy__progress" data-occupancy-progress max="100" value="0" aria-label="Salon doluluk yüzdesi"></progress><span data-occupancy-progress-label>—%</span></div></section>';
    }

    private function is_inside($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT direction FROM {$this->events} WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user_id)) === 'entry';
    }

    private function valid_member($user_id) {
        $state = $this->membership_access_state($user_id);
        return $state['valid'];
    }
    public function scan() {
        if (!$this->allowed() || !check_ajax_referer('bossgym_turnstile', 'nonce', false)) { wp_send_json_error(['message' => 'Yetki veya istek doğrulanamadı.'], 403); }
        $token = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $direction = sanitize_key(wp_unslash($_POST['direction'] ?? ''));
        if (!preg_match('/^[a-f0-9]{48}$/', $token) || !in_array($direction, ['entry', 'exit'], true)) { wp_send_json_error(['message' => 'Kod veya işlem geçersiz.'], 400); }
        $user = get_users(['meta_key' => self::TOKEN_META, 'meta_value' => $token, 'number' => 1, 'fields' => 'ID']);
        $user_id = $user ? (int) $user[0] : 0;
        if (!$user_id) { wp_send_json_error(['message' => 'Kod gecersiz.'], 403); }
        global $wpdb;
        $lock = 'bgts:' . $user_id;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock)) !== 1) { wp_send_json_error(['message' => 'Islem mesgul; tekrar deneyin.'], 409); }
        $wpdb->query('START TRANSACTION');
        $inside = $this->is_inside($user_id);
        $eligible = $this->valid_member($user_id);
        if (($direction === 'entry' && !$eligible) || ($direction === 'entry' && $inside) || ($direction === 'exit' && !$inside)) {
            $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            $message = ($direction === 'entry' && !$eligible) ? 'Uyelik onaysiz veya suresi dolmus.' : ($inside ? 'Uye zaten iceride.' : 'Uye icin acik giris kaydi yok.');
            wp_send_json_error(['message' => $message], 409);
        }
        $inserted = $wpdb->insert($this->events, ['user_id' => $user_id, 'direction' => $direction, 'created_utc' => gmdate('Y-m-d H:i:s')], ['%d', '%s', '%s']);
        if (!$inserted) { $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); wp_send_json_error(['message' => 'Hareket kaydedilemedi.'], 500); }
        $wpdb->query('COMMIT');
        delete_transient('bossgym_turnstile_occupancy_counts');
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        wp_send_json_success(['message' => ($direction === 'entry' ? 'Giriş başarılı: ' : 'Çıkış başarılı: ') . get_the_author_meta('display_name', $user_id)]);
    }

    private function occupancy_counts() {
        $cached = get_transient('bossgym_turnstile_occupancy_counts');
        if (is_array($cached) && isset($cached['male'], $cached['female'], $cached['unspecified'])) { return $cached; }

        global $wpdb;
        $latest = "SELECT e.user_id,e.direction FROM {$this->events} e INNER JOIN (SELECT user_id,MAX(id) id FROM {$this->events} GROUP BY user_id) x ON x.id=e.id WHERE e.direction='entry'";
        $ids = $wpdb->get_col($latest);
        if (!is_array($ids) || $wpdb->last_error !== '') { return new WP_Error('occupancy_unavailable', 'Occupancy source unavailable.'); }

        $counts = ['male' => 0, 'female' => 0, 'unspecified' => 0];
        foreach ($ids as $id) {
            if (!$this->valid_member((int) $id)) { continue; }
            $gender = get_user_meta((int) $id, 'bossgym_gender', true);
            $key = $gender === 'male' ? 'male' : ($gender === 'female' ? 'female' : 'unspecified');
            $counts[$key]++;
        }
        set_transient('bossgym_turnstile_occupancy_counts', $counts, 15);
        return $counts;
    }

    private function occupancy_data() {
        $counts = $this->occupancy_counts();
        if (is_wp_error($counts)) { return $counts; }
        $inside = array_sum($counts);
        $capacity = max(1, (int) get_option('bossgym_turnstile_capacity', 100));
        return [
            'inside' => $inside,
            'male' => $counts['male'],
            'female' => $counts['female'],
            'unspecified' => $counts['unspecified'],
            'capacity' => $capacity,
            'percentage' => (int) round(100 * $inside / $capacity),
        ];
    }

    public function member_occupancy_ajax() {
        if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Bu bilgiye erişmek için oturum açın.'], 401); }
        if (!check_ajax_referer('bossgym_member_occupancy', 'nonce', false)) { wp_send_json_error(['message' => 'İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.'], 403); }
        $data = $this->occupancy_data();
        if (is_wp_error($data)) { wp_send_json_error(['message' => 'Salon doluluk verisi şu anda alınamıyor.'], 503); }
        wp_send_json_success($data);
    }

    public function stats_ajax() {
        if (!$this->allowed() || !check_ajax_referer('bossgym_turnstile', 'nonce', false)) { wp_send_json_error(['message' => 'Yetki yok.'], 403); }
        global $wpdb;
        $data = $this->occupancy_data();
        if (is_wp_error($data)) { wp_send_json_error(['message' => 'Doluluk verisi şu anda alınamıyor.'], 503); }
        $recent = $wpdb->get_results("SELECT e.direction,e.created_utc,u.display_name FROM {$this->events} e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id ORDER BY e.id DESC LIMIT 8");
        if ($wpdb->last_error !== '') { wp_send_json_error(['message' => 'Doluluk hareketleri şu anda alınamıyor.'], 503); }
        $html = '<div class="bossgym-occupancy-grid"><strong>İçeride: ' . (int) $data['inside'] . '</strong><span>Erkek: ' . (int) $data['male'] . '</span><span>Kadın: ' . (int) $data['female'] . '</span><span>Belirtilmedi: ' . (int) $data['unspecified'] . '</span><span>Kapasite: ' . (int) $data['capacity'] . '</span><span>Doluluk: ' . (int) $data['percentage'] . '%</span></div><h2>Son hareketler</h2><ul>';
        foreach ((array) $recent as $row) { $html .= '<li>' . esc_html(get_date_from_gmt($row->created_utc, 'H:i')) . ' — ' . esc_html($row->direction === 'entry' ? 'Giriş' : 'Çıkış') . ' — ' . esc_html($row->display_name ?: '') . '</li>'; }
        wp_send_json_success(['html' => $html . '</ul>']);
    }
}
