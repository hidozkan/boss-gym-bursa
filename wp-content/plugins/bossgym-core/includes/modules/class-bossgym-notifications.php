<?php
if (!defined('ABSPATH')) { exit; }

/** Appointment email events and deduplicated WP-Cron reminders. */
final class BossGym_Notifications {
    const DB_VERSION = '1.0.0';
    private $log_table;

    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'bg_notification_log';
        add_action('init', [$this, 'install_if_needed'], 5);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action('init', [$this, 'schedule'], 20);
        add_action('bossgym_booking_created', [$this, 'booking_created'], 10, 2);
        add_action('bossgym_booking_status_changed', [$this, 'booking_status_changed'], 10, 3);
        add_action('bossgym_referee_status_changed', [$this, 'referee_status_changed'], 10, 2);
        add_action('bossgym_notifications_cron', [$this, 'send_reminders']);
        add_action('admin_menu', [$this, 'admin_menu'], 50);
        add_action('admin_post_bossgym_notifications_save', [$this, 'save_settings']);
        add_action('admin_post_bossgym_notifications_test', [$this, 'send_test']);
    }

    public function install_if_needed() {
        if (get_option('bossgym_notifications_db_version') === self::DB_VERSION) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_type varchar(16) NOT NULL,
            booking_id bigint(20) unsigned NOT NULL,
            notice_type varchar(24) NOT NULL,
            sent_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY booking_notice (booking_type,booking_id,notice_type),
            KEY sent_utc (sent_utc)
        ) $collate;");
        update_option('bossgym_notifications_db_version', self::DB_VERSION, false);
    }

    public function schedule() {
        $event = wp_get_scheduled_event('bossgym_notifications_cron');
        if ($event && $event->schedule === 'bossgym_five_minutes') { return; }
        wp_clear_scheduled_hook('bossgym_notifications_cron');
        wp_schedule_event(time() + 60, 'bossgym_five_minutes', 'bossgym_notifications_cron');
    }

    public function add_cron_schedules($schedules) {
        if (!isset($schedules['bossgym_five_minutes'])) {
            $schedules['bossgym_five_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Boss Gym her 5 dakika'];
        }
        return $schedules;
    }

    public function admin_menu() {
        add_submenu_page('bossgym-overview', 'E-posta Bildirimleri', 'E-posta Bildirimleri', 'manage_options', 'bossgym-notifications', [$this, 'settings_page']);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu sayfaya erişim izniniz yok.')); }
        $enabled = get_option('bossgym_notifications_enabled', '1') === '1';
        $reminders = get_option('bossgym_notifications_reminders', '1') === '1';
        $last = get_option('bossgym_notifications_last_result', []);
        if (!empty($last['error_message']) && empty($mail_error_notice_shown)) {
            $mail_error_notice_shown = true;
            echo '<div class="notice notice-error"><p><strong>Son e-posta hatası (' . esc_html($last['error_code'] ?? 'wp_mail_failed') . '):</strong> ' . esc_html($last['error_message']) . '</p></div>';
        }
        if (!empty($last['error_message']) && empty($mail_error_notice_shown)) {
            echo '<div class="notice notice-error"><p><strong>Son e-posta hatası (' . esc_html($last['error_code'] ?? 'wp_mail_failed') . '):</strong> ' . esc_html($last['error_message']) . '</p></div>';
        }
        echo '<div class="wrap bossgym-admin"><header class="bossgym-admin__header"><h1>E-posta bildirimleri</h1><p>WordPress wp_mail() kullanılır. SMTP kurulumu yapılmadan localhost gönderimleri teslim edilmeyebilir.</p></header>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Ayarlar kaydedildi.</p></div>'; }
        if (isset($_GET['test'])) { echo '<div class="notice ' . (sanitize_key(wp_unslash($_GET['test'])) === 'sent' ? 'notice-success' : 'notice-error') . '"><p>' . (sanitize_key(wp_unslash($_GET['test'])) === 'sent' ? 'wp_mail() gönderimi başarılı olarak döndü; alıcıya teslimat doğrulanmadı.' : 'wp_mail() gönderim isteği başarısız oldu.') . '</p></div>'; }
        echo '<form class="bossgym-admin__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_notifications_save">';
        wp_nonce_field('bossgym_notifications_save');
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked($enabled, true, false) . '> PT ve saha başvurusu, onay ve iptal bildirimlerini etkinleştir</label></p><p><label><input type="checkbox" name="reminders" value="1" ' . checked($reminders, true, false) . '> Onaylı rezervasyonlar için 24 ve 2 saatlik hatırlatmaları etkinleştir</label></p><button class="button button-primary">Ayarları kaydet</button></form>';
        echo '<hr><h2>Test e-postası</h2><p>Bu düğme yalnızca siz gönderdiğinizde e-posta gönderir.</p><form class="bossgym-admin__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_notifications_test">';
        wp_nonce_field('bossgym_notifications_test');
        echo '<label>Alıcı e-posta <input type="email" name="email" required value="' . esc_attr(wp_get_current_user()->user_email) . '"></label> <button class="button">Test e-postası gönder</button></form>';
        echo '<p>Son wp_mail() sonucu: ' . esc_html(isset($last['time'], $last['result']) ? $last['time'] . ' — ' . ($last['result'] ? 'istek kabul edildi (teslimat doğrulanmadı)' : 'başarısız') : 'Henüz gönderim yok.') . '</p><p>Yerel XAMPP ortamında zamanında hatırlatma için Windows Görev Zamanlayıcı ile dakikada bir <code>wp-cron.php</code> çağrısı planlayın. PHP, WordPress kök dizinini çalışma dizini olarak kullanmalıdır.</p></div>';
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Yetki yok.'); }
        check_admin_referer('bossgym_notifications_save');
        update_option('bossgym_notifications_enabled', isset($_POST['enabled']) ? '1' : '0', false);
        update_option('bossgym_notifications_reminders', isset($_POST['reminders']) ? '1' : '0', false);
        wp_safe_redirect(admin_url('admin.php?page=bossgym-notifications&saved=1')); exit;
    }

    public function send_test() {
        if (!current_user_can('manage_options')) { wp_die('Yetki yok.'); }
        check_admin_referer('bossgym_notifications_test');
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$email || !is_email($email)) { wp_safe_redirect(admin_url('admin.php?page=bossgym-notifications&test=failed')); exit; }
        $result = $this->mail($email, 'Boss Gym Bursa — Test e-postası', 'E-posta ayar testi', '<p>Bu ileti Boss Gym Bursa e-posta ayar testi olarak yönetici isteğiyle oluşturuldu.</p>');
        update_option('bossgym_notifications_last_result', ['time' => current_time('mysql'), 'result' => (bool) $result], false);
        wp_safe_redirect(admin_url('admin.php?page=bossgym-notifications&test=' . ($result ? 'sent' : 'failed'))); exit;
    }

    public function booking_created($type, $id) { $this->send_booking_notice($type, (int) $id, 'created'); }

    public function booking_status_changed($type, $id, $status) {
        $kind = $status === 'approved' ? 'approved' : ($status === 'cancelled' ? 'cancelled' : ($type === 'court' && $status === 'rejected' ? 'rejected' : ''));
        if ($kind) { $this->send_booking_notice($type, (int) $id, $kind); }
    }

    public function referee_status_changed($id, $status) {
        if (get_option('bossgym_notifications_enabled', '1') !== '1') { return; }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.user_id,r.reservation_id,b.court_id,b.start_utc,b.end_utc,b.status AS booking_status FROM {$wpdb->prefix}bg_referee_requests r INNER JOIN {$wpdb->prefix}bg_court_reservations b ON b.id=r.reservation_id WHERE r.id=%d",
            (int) $id
        ));
        if (!$row) { return; }
        $user = get_user_by('id', $row->user_id);
        if (!$user || !is_email($user->user_email)) { return; }
        $labels = ['requested' => 'Talep Alındı', 'reviewing' => 'Değerlendiriliyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal Edildi'];
        if (!isset($labels[$status])) { return; }
        $start = new DateTimeImmutable($row->start_utc, new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($row->end_utc, new DateTimeZone('UTC'));
        $court = get_the_title((int) $row->court_id);
        $state = ['pending' => 'Onay Bekliyor', 'approved' => 'Onaylandı', 'cancelled' => 'İptal Edildi', 'rejected' => 'Reddedildi', 'completed' => 'Tamamlandı'][$row->booking_status] ?? 'Bilinmiyor';
        $title = 'Hakem Talebiniz Güncellendi';
        $html = '<p>Hakem hizmeti talebinizin durumu güncellendi.</p><table role="presentation" style="width:100%;border-collapse:collapse;color:#f7f5ef;background:#171717"><tr><td style="padding:10px">Saha</td><td style="padding:10px">' . esc_html($court) . '</td></tr><tr><td style="padding:10px">Tarih ve saat</td><td style="padding:10px">' . esc_html(wp_date('d.m.Y H:i', $start->getTimestamp()) . ' - ' . wp_date('H:i', $end->getTimestamp())) . '</td></tr><tr><td style="padding:10px">Hakem talebi</td><td style="padding:10px">' . esc_html($labels[$status]) . '</td></tr><tr><td style="padding:10px">Rezervasyon durumu</td><td style="padding:10px">' . esc_html($state) . '</td></tr></table>';
        $this->mail($user->user_email, 'Boss Gym Bursa — ' . $title, $title, $html, ['type' => 'referee', 'booking_id' => (int) $row->reservation_id, 'event' => $status]);
    }

    private function send_booking_notice($type, $id, $event) {
        if (get_option('bossgym_notifications_enabled', '1') !== '1') { return; }
        $data = $this->booking_data($type, $id);
        if (!$data || !$data['email']) { return; }
        if ($type === 'court') {
            global $wpdb;
            $reservation = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}bg_court_reservations WHERE id=%d", $id));
            if (!$reservation) { return; }
            $referee = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}bg_referee_requests WHERE reservation_id=%d", $id));
            $titles = [
                'created' => 'Saha Rezervasyon Ba&#351;vurunuz Al&#305;nd&#305;',
                'approved' => 'Saha Rezervasyonunuz Onayland&#305;',
                'cancelled' => 'Saha Rezervasyonunuz &#304;ptal Edildi',
                'rejected' => 'Saha Rezervasyon Talebiniz Reddedildi',
            ];
            if (!isset($titles[$event])) { return; }
            $title = html_entity_decode($titles[$event], ENT_QUOTES, 'UTF-8');
            $states = ['pending' => 'Onay Bekliyor', 'approved' => 'Onaylandı', 'cancelled' => 'İptal Edildi', 'rejected' => 'Reddedildi', 'completed' => 'Tamamlandı'];
            $referee_states = ['requested' => 'Talep Alındı', 'reviewing' => 'Değerlendiriliyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal Edildi'];
            $html = '<p>' . esc_html($title) . '.</p><table role="presentation" style="width:100%;border-collapse:collapse;color:#f7f5ef;background:#171717"><tr><td style="padding:10px">Saha</td><td style="padding:10px">' . esc_html($data['place']) . '</td></tr><tr><td style="padding:10px">Tarih</td><td style="padding:10px">' . esc_html($data['start']) . '</td></tr><tr><td style="padding:10px">Saat aralığı</td><td style="padding:10px">' . esc_html(substr($data['start'], -5) . ' - ' . $data['end']) . '</td></tr><tr><td style="padding:10px">Hakem talebi</td><td style="padding:10px">' . esc_html($referee ? ($referee_states[$referee] ?? 'Durum bilinmiyor') : 'Talep edilmedi') . '</td></tr><tr><td style="padding:10px">Rezervasyon durumu</td><td style="padding:10px">' . esc_html($states[$reservation->status] ?? 'Durum bilinmiyor') . '</td></tr></table><p>Bu bildirim otomatik gönderilmiştir. Ödeme alınmamıştır.</p>';
            $this->mail($data['email'], 'Boss Gym Bursa — ' . $title, $title, $html, ['type' => 'court', 'booking_id' => $id, 'event' => $event]);
            return;
        }
        $titles = ['created' => 'Başvurunuz alındı', 'approved' => 'Rezervasyonunuz onaylandı', 'cancelled' => 'Rezervasyonunuz iptal edildi'];
        $subject = 'Boss Gym Bursa — ' . $titles[$event];
        $intro = $event === 'created' ? 'Talebiniz alındı ve yönetici onayı bekliyor.' : ($event === 'approved' ? 'Rezervasyon talebiniz yönetici tarafından onaylandı.' : 'Rezervasyonunuz iptal edildi.');
        $html = '<p>' . esc_html($intro) . '</p>' . $this->details_html($data) . '<p>Bu e-posta otomatik bilgilendirmedir. Ödeme alınmamıştır.</p>';
        $title = $titles[$event];
        if ($type === 'pt' && $event === 'created') {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT session_end,status,trainer_id,package_id FROM {$wpdb->prefix}bg_pt_appointments WHERE id=%d", $id));
            if ($row) {
                $end = new DateTimeImmutable($row->session_end, new DateTimeZone('UTC'));
                $data['end'] = wp_date('H:i', $end->getTimestamp());
                $data['status'] = $row->status === 'pending' ? 'Onay Bekliyor' : $row->status;
            }
            $title = 'PT Randevu Başvurunuz Alındı';
            $subject = 'Boss Gym Bursa — ' . $title;
            $html = '<p>PT randevu başvurunuz alındı.</p>' . $this->details_html($data) . '<p><strong>Durum:</strong> ' . esc_html($data['status'] ?? 'Onay Bekliyor') . '</p><p>Randevunuz yönetici onayından sonra kesinleşir. Ödeme alınmamıştır.</p>';
        }
        if ($type === 'pt' && $event === 'created' && !empty($row)) {
            $html = '<p>PT randevu ba&#351;vurunuz al&#305;nd&#305;.</p><table role="presentation" style="width:100%;border-collapse:collapse;color:#f7f5ef;background:#171717"><tr><td style="padding:10px">E&#287;itmen</td><td style="padding:10px">' . esc_html(get_the_title($row->trainer_id)) . '</td></tr><tr><td style="padding:10px">Paket</td><td style="padding:10px">' . esc_html(get_the_title($row->package_id)) . '</td></tr><tr><td style="padding:10px">Tarih</td><td style="padding:10px">' . esc_html($data['start']) . '</td></tr><tr><td style="padding:10px">Saat</td><td style="padding:10px">' . esc_html($data['start'] ? substr($data['start'], -5) . ' - ' . $data['end'] : '') . '</td></tr><tr><td style="padding:10px">Durum</td><td style="padding:10px">' . esc_html($data['status'] ?? 'Onay Bekliyor') . '</td></tr></table><p>Randevunuz yönetici onayından sonra kesinle&#351;ir. &#214;deme al&#305;nmam&#305;&#351;t&#305;r.</p>';
        }
        $this->mail($data['email'], $subject, $title, $html, ['type' => $type, 'booking_id' => $id, 'event' => $event]);
    }

    private function booking_data($type, $id) {
        global $wpdb;
        if ($type === 'pt') {
            $table = $wpdb->prefix . 'bg_pt_appointments';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
            if (!$row) { return null; }
            $user = get_user_by('id', $row->user_id);
            $start = new DateTimeImmutable($row->session_start, new DateTimeZone('UTC'));
            return ['email' => $user ? $user->user_email : '', 'name' => $user ? $user->display_name : '', 'type' => 'PT randevusu', 'place' => get_the_title($row->trainer_id) . ' — ' . get_the_title($row->package_id), 'start' => wp_date('d.m.Y H:i', $start->getTimestamp()), 'end' => ''];
        }
        if ($type === 'court') {
            $table = $wpdb->prefix . 'bg_court_reservations';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
            if (!$row) { return null; }
            $user = get_user_by('id', $row->user_id);
            $start = new DateTimeImmutable($row->start_utc, new DateTimeZone('UTC'));
            $end = new DateTimeImmutable($row->end_utc, new DateTimeZone('UTC'));
            return ['email' => $user ? $user->user_email : '', 'name' => $user ? $user->display_name : '', 'type' => 'Saha rezervasyonu', 'place' => get_the_title($row->court_id), 'start' => wp_date('d.m.Y H:i', $start->getTimestamp()), 'end' => wp_date('H:i', $end->getTimestamp())];
        }
        return null;
    }

    private function details_html($data) {
        return '<table role="presentation" style="width:100%;border-collapse:collapse;color:#f7f5ef;background:#171717"><tr><td style="padding:10px;border-bottom:1px solid #3d321d">Hizmet</td><td style="padding:10px;border-bottom:1px solid #3d321d">' . esc_html($data['type']) . '</td></tr><tr><td style="padding:10px;border-bottom:1px solid #3d321d">Antrenör / saha</td><td style="padding:10px;border-bottom:1px solid #3d321d">' . esc_html($data['place']) . '</td></tr><tr><td style="padding:10px">Tarih ve saat</td><td style="padding:10px">' . esc_html($data['start'] . ($data['end'] ? ' – ' . $data['end'] : '')) . '</td></tr></table>';
    }

    private function mail($to, $subject, $heading, $content, $context = []) {
        $mail_error = null;
        $capture_error = static function($error) use (&$mail_error) {
            if (!is_wp_error($error)) { return; }
            $message = sanitize_text_field($error->get_error_message());
            $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-posta gizlendi]', $message);
            $message = preg_replace('/(?i)(password|passwd|pass|username|user|secret|token|authorization)(\s*[=:]\s*)([^\s,;]+)/', '$1$2[gizlendi]', $message);
            $mail_error = ['code' => sanitize_key($error->get_error_code()), 'message' => substr($message, 0, 500)];
        };
        add_action('wp_mail_failed', $capture_error, 9999, 1);
        $html = '<div style="margin:0;background:#101010;padding:24px;font-family:Arial,sans-serif;color:#f5f5f5"><div style="max-width:640px;margin:auto;border:1px solid #74530b;border-radius:12px;overflow:hidden"><div style="padding:20px 24px;background:#181818;border-bottom:2px solid #e5a000"><strong style="color:#e5a000;letter-spacing:2px">BOSS GYM BURSA</strong></div><div style="padding:24px"><h1 style="margin-top:0;color:#fff;font-size:22px">' . esc_html($heading) . '</h1><div style="line-height:1.6;color:#ddd">' . $content . '</div></div></div></div>';
        $result = wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        update_option('bossgym_notifications_last_result', ['time' => current_time('mysql'), 'result' => (bool) $result], false);
        remove_action('wp_mail_failed', $capture_error, 9999);
        update_option('bossgym_notifications_last_result', [
            'time' => current_time('mysql'),
            'result' => (bool) $result,
            'context' => array_intersect_key($context, array_flip(['type', 'booking_id', 'event'])),
            'error_code' => $mail_error['code'] ?? '',
            'error_message' => $mail_error['message'] ?? '',
        ], false);
        return $result;
    }

    public function send_reminders() {
        if (get_option('bossgym_notifications_enabled', '1') !== '1' || get_option('bossgym_notifications_reminders', '1') !== '1') { return; }
        foreach (['pt' => $GLOBALS['wpdb']->prefix . 'bg_pt_appointments', 'court' => $GLOBALS['wpdb']->prefix . 'bg_court_reservations'] as $type => $table) {
            global $wpdb;
            $rows = $wpdb->get_results("SELECT id,user_id," . ($type === 'pt' ? 'session_start AS start_utc' : 'start_utc') . " FROM {$table} WHERE status='approved' AND " . ($type === 'pt' ? 'session_start' : 'start_utc') . " >= UTC_TIMESTAMP() AND " . ($type === 'pt' ? 'session_start' : 'start_utc') . " < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 26 HOUR) LIMIT 500");
            foreach ((array) $rows as $row) {
                $start = new DateTimeImmutable($row->start_utc, new DateTimeZone('UTC'));
                $hours = ($start->getTimestamp() - time()) / HOUR_IN_SECONDS;
                foreach ([24 => 5 / 60, 2 => 5 / 60] as $target => $window) {
                    if (abs($hours - $target) > $window) { continue; }
                    $this->send_reminder_once($type, (int) $row->id, $target);
                }
            }
        }
    }

    private function send_reminder_once($type, $id, $target) {
        global $wpdb;
        $insert = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->log_table} (booking_type,booking_id,notice_type,sent_utc) VALUES (%s,%d,%s,UTC_TIMESTAMP())", $type, $id, 'reminder_' . $target));
        if ($insert !== 1) { return; }
        $data = $this->booking_data($type, $id);
        if (!$data || !$data['email']) { $wpdb->delete($this->log_table, ['booking_type' => $type, 'booking_id' => $id, 'notice_type' => 'reminder_' . $target]); return; }
        $success = $this->mail($data['email'], 'Boss Gym Bursa — ' . $target . ' saat sonra randevunuz var', 'Randevu hatırlatması', '<p>' . esc_html($target . ' saat sonra rezervasyonunuz bulunuyor.') . '</p>' . $this->details_html($data));
        if (!$success) { $wpdb->delete($this->log_table, ['booking_type' => $type, 'booking_id' => $id, 'notice_type' => 'reminder_' . $target]); }
    }
}
