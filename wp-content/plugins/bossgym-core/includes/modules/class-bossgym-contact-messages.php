<?php
if (!defined('ABSPATH')) { exit; }

/** Public contact form storage and its administrator-only inbox. */
final class BossGym_Contact_Messages {
    const DB_VERSION = '1.1.0';
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bg_contact_messages';
        add_action('init', [$this, 'install_if_needed'], 5);
        add_shortcode('bossgym_contact_form', [$this, 'render_form']);
        add_action('admin_post_nopriv_bossgym_contact_submit', [$this, 'submit']);
        add_action('admin_post_bossgym_contact_submit', [$this, 'submit']);
        add_action('admin_menu', [$this, 'admin_menu'], 70);
        add_action('admin_post_bossgym_contact_message_action', [$this, 'handle_admin_action']);
        add_action('admin_post_bossgym_contact_settings', [$this, 'save_settings']);
    }

    public function install_if_needed() {
        if (get_option('bossgym_contact_db_version') === self::DB_VERSION) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            email varchar(190) NOT NULL,
            subject varchar(190) NOT NULL,
            message text NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'unread',
            mail_status varchar(16) NOT NULL DEFAULT 'unknown',
            mail_error_code varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_created (status,created_at),
            KEY created_at (created_at)
        ) $collate;");
        update_option('bossgym_contact_db_version', self::DB_VERSION, false);
    }

    public function render_form() {
        $notice = isset($_GET['bg_contact']) ? sanitize_key(wp_unslash($_GET['bg_contact'])) : '';
        $messages = [
            'sent' => ['success', 'Mesajınız başarıyla alındı. E-posta gönderim isteği kabul edildi; alıcıya teslimat ayrıca doğrulanmamıştır.'],
            'mail_failed' => ['warning', 'Mesajınız kaydedildi, ancak e-posta bildirimi gönderilemedi. Mesajınız yönetim panelindeki İletişim Mesajları bölümünde kayıtlıdır.'],
            'invalid' => ['error', 'Bilgileri kontrol edip formu yeniden gönderin.'],
            'rate' => ['error', 'Kısa süre içinde çok fazla mesaj gönderildi. Lütfen biraz sonra yeniden deneyin.'],
            'error' => ['error', 'Mesaj şu anda kaydedilemedi. Lütfen daha sonra yeniden deneyin veya telefonla bize ulaşın.'],
        ];

        ob_start(); ?>
        <section class="bg-contact-form-section" id="bg-contact-form" aria-labelledby="bg-contact-form-title">
            <div class="bg-contact-form-card">
                <p class="bg-contact-form-eyebrow">BİZE ULAŞIN</p>
                <h2 id="bg-contact-form-title">Mesajınızı iletin</h2>
                <p class="bg-contact-form-intro">Sorunuz veya bilgi talebiniz için formu doldurun. Ekibimiz size dönüş yapabilmek için verdiğiniz iletişim bilgilerini kullanır.</p>
                <?php if (isset($messages[$notice])) : ?><div class="bg-contact-feedback bg-contact-feedback--<?php echo esc_attr($messages[$notice][0]); ?>" role="<?php echo in_array($messages[$notice][0], ["error", "warning"], true) ? "alert" : "status"; ?>" aria-live="<?php echo in_array($messages[$notice][0], ["error", "warning"], true) ? "assertive" : "polite"; ?>"><?php echo esc_html($messages[$notice][1]); ?></div><?php endif; ?>
                <form class="bg-contact-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="bossgym_contact_submit">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(get_permalink(22)); ?>">
                    <?php wp_nonce_field('bossgym_contact_submit', '_bgc_contact_nonce'); ?>
                    <div class="bg-contact-form__trap" aria-hidden="true"><label for="bg-contact-check">Bu alan boş bırakılmalıdır</label><input id="bg-contact-check" type="text" name="bgc_contact_check" value="" tabindex="-1" autocomplete="new-password" autocapitalize="off" spellcheck="false"></div>
                    <div class="bg-contact-form__grid">
                        <label><span>Ad Soyad</span><input type="text" name="name" autocomplete="name" maxlength="190" required></label>
                        <label><span>E-posta</span><input type="email" name="email" autocomplete="email" maxlength="190" required></label>
                        <label class="bg-contact-form__full"><span>Konu</span><input type="text" name="subject" maxlength="190" required></label>
                        <label class="bg-contact-form__full"><span>Mesaj</span><textarea name="message" rows="6" maxlength="5000" required></textarea></label>
                    </div>
                    <button class="bg-contact-form__submit" type="submit">Mesajı Gönder</button>
                </form>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    public function submit() {
        $redirect = get_permalink(22);
        if (!$redirect) { $redirect = home_url('/iletisim/'); }
        if (!isset($_POST['_bgc_contact_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bgc_contact_nonce'])), 'bossgym_contact_submit')) {
            $this->redirect_form($redirect, 'invalid');
        }

        // Honeypot: silently accept without storing or notifying bots.
        if (!empty($_POST['bgc_contact_check'])) { $this->redirect_form($redirect, 'invalid'); }
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        if ($name === '' || strlen($name) > 190 || !$email || !is_email($email) || $subject === '' || strlen($subject) > 190 || $message === '' || strlen($message) > 5000) {
            $this->redirect_form($redirect, 'invalid');
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rate_key = 'bg_contact_rate_' . hash_hmac('sha256', $ip, wp_salt('auth'));
        if (get_transient($rate_key)) { $this->redirect_form($redirect, 'rate'); }

        global $wpdb;
        $saved = $wpdb->insert($this->table, [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'status' => 'unread',
            'mail_status' => 'pending',
            'mail_error_code' => '',
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        if (!$saved) { $this->redirect_form($redirect, 'error'); }
        $message_id = (int) $wpdb->insert_id;
        set_transient($rate_key, 1, 2 * MINUTE_IN_SECONDS);

        $to = sanitize_email(get_option('bossgym_contact_recipient', get_option('admin_email')));
        if (!$to || !is_email($to)) { $to = sanitize_email(get_option('admin_email')); }
        $headers = ['Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $email];
        $body = '<div style="font-family:Arial,sans-serif;background:#111;color:#f5f5f5;padding:24px"><div style="max-width:640px;margin:auto;border-top:4px solid #e5a000;padding:20px;background:#1d1d1d"><h1 style="color:#e5a000">Yeni iletişim mesajı</h1><p><strong>Ad Soyad:</strong> ' . esc_html($name) . '</p><p><strong>E-posta:</strong> ' . esc_html($email) . '</p><p><strong>Konu:</strong> ' . esc_html($subject) . '</p><hr style="border-color:#444"><p style="white-space:pre-wrap">' . nl2br(esc_html($message)) . '</p></div></div>';
        $mail_error_codes = [];
        $capture_mail_error = static function ($error) use (&$mail_error_codes) {
            if (is_wp_error($error)) {
                foreach ($error->get_error_codes() as $code) {
                    $code = sanitize_key($code);
                    if ($code !== '') { $mail_error_codes[] = $code; }
                }
            }
        };
        add_action('wp_mail_failed', $capture_mail_error, 10, 1);
        $sent = $to && is_email($to) ? wp_mail($to, 'Boss Gym Bursa — İletişim: ' . $subject, $body, $headers) : false;
        remove_action('wp_mail_failed', $capture_mail_error, 10);
        $mail_status = $sent ? 'accepted' : 'failed';
        $mail_error_code = $sent ? '' : substr($mail_error_codes[0] ?? (!$to || !is_email($to) ? 'invalid_recipient' : 'wp_mail_failed'), 0, 64);
        $wpdb->update($this->table, ['mail_status' => $mail_status, 'mail_error_code' => $mail_error_code], ['id' => $message_id], ['%s', '%s'], ['%d']);
        $this->redirect_form($redirect, $sent ? 'sent' : 'mail_failed');
    }

    private function redirect_form($url, $result) {
        wp_safe_redirect(add_query_arg('bg_contact', $result, $url) . '#bg-contact-form');
        exit;
    }

    public function admin_menu() {
        add_submenu_page('bossgym-overview', 'İletişim Mesajları', 'İletişim Mesajları', 'manage_options', 'bossgym-contact-messages', [$this, 'render_admin']);
    }

    public function render_admin() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu sayfaya erişim izniniz yok.')); }
        global $wpdb;
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 25;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page));
        $view_id = absint($_GET['message'] ?? 0);
        echo '<div class="wrap bossgym-admin bossgym-admin--contact"><header class="bossgym-admin__header"><h1>İletişim Mesajları</h1><p>Gelen mesajlar bu WordPress yönetim ekranında saklanır ve yönetilir.</p></header>';
        if (isset($_GET['updated'])) { echo '<div class="notice notice-success"><p>Mesaj durumu güncellendi.</p></div>'; }
        if (isset($_GET['deleted'])) { echo '<div class="notice notice-success"><p>Mesaj silindi.</p></div>'; }
        if (isset($_GET['settings'])) { echo '<div class="notice ' . (sanitize_key(wp_unslash($_GET['settings'])) === 'saved' ? 'notice-success' : 'notice-error') . '"><p>' . (sanitize_key(wp_unslash($_GET['settings'])) === 'saved' ? 'Bildirim alıcısı kaydedildi.' : 'Geçerli bir e-posta adresi girin.') . '</p></div>'; }
        if ($view_id) {
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $view_id));
            if (!$item) { echo '<div class="notice notice-error"><p>Mesaj bulunamadı.</p></div>'; }
            else {
            echo '<article class="bossgym-admin__card bossgym-contact-admin-detail"><p><strong>Gönderen:</strong> ' . esc_html($item->name) . ' &lt;' . esc_html($item->email) . '&gt;</p><p><strong>Konu:</strong> ' . esc_html($item->subject) . '</p><p><strong>Tarih:</strong> ' . esc_html($item->created_at) . '</p><p><strong>Okunma durumu:</strong> ' . esc_html($item->status === 'read' ? 'Okundu' : 'Okunmadı') . '</p><p><strong>E-posta bildirimi:</strong> ' . esc_html($this->mail_status_label($item->mail_status)) . ($item->mail_error_code ? ' (' . esc_html($item->mail_error_code) . ')' : '') . '</p><div class="bossgym-contact-admin-message">' . nl2br(esc_html($item->message)) . '</div>';
                $this->action_form($item->id, $item->status === 'read' ? 'unread' : 'read', $item->status === 'read' ? 'Okunmadı olarak işaretle' : 'Okundu olarak işaretle');
                $this->action_form($item->id, 'delete', 'Mesajı sil', true);
                echo '<p><a href="' . esc_url(admin_url('admin.php?page=bossgym-contact-messages')) . '">← Mesaj listesine dön</a></p></article>';
            }
        } else {
            echo '<div class="bossgym-admin__table-wrap"><table class="wp-list-table widefat fixed striped bossgym-admin__table bossgym-contact-admin-table"><thead><tr><th>Tarih</th><th>Okunma</th><th>Bildirim</th><th>Ad Soyad</th><th>E-posta</th><th>Konu</th><th>İşlem</th></tr></thead><tbody>';
            if (!$rows) { echo '<tr><td colspan="7">Henüz iletişim mesajı yok.</td></tr>'; }
            foreach ($rows as $item) {
                $view = add_query_arg(['page' => 'bossgym-contact-messages', 'message' => (int) $item->id], admin_url('admin.php'));
                echo '<tr><td>' . esc_html($item->created_at) . '</td><td>' . esc_html($item->status === 'read' ? 'Okundu' : 'Okunmadı') . '</td><td>' . esc_html($this->mail_status_label($item->mail_status)) . '</td><td>' . esc_html($item->name) . '</td><td><a href="mailto:' . esc_attr($item->email) . '">' . esc_html($item->email) . '</a></td><td>' . esc_html($item->subject) . '</td><td><a class="button" href="' . esc_url($view) . '">Mesajı oku</a></td></tr>';
            }
            echo '</tbody></table></div>';
            $pages = (int) ceil($total / $per_page);
            if ($pages > 1) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(['base' => add_query_arg(['page' => 'bossgym-contact-messages', 'paged' => '%#%'], admin_url('admin.php')), 'format' => '', 'current' => $page, 'total' => $pages, 'type' => 'plain'])) . '</div></div>'; }
        }
        $recipient = get_option('bossgym_contact_recipient', get_option('admin_email'));
        echo '<section class="bossgym-admin__card bossgym-contact-admin-settings"><h2>Bildirim alıcısı</h2><p>Yeni mesaj bildirimi mevcut WordPress wp_mail() ve WP Mail SMTP yapılandırmasını kullanır.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_contact_settings">';
        wp_nonce_field('bossgym_contact_settings');
        echo '<label>Bildirim e-posta adresi <input type="email" name="recipient" value="' . esc_attr($recipient) . '" required></label> <button class="button button-primary">Alıcıyı kaydet</button></form></section></div>';
    }

    private function action_form($id, $operation, $label, $confirm = false) {
        echo '<form class="bossgym-contact-admin-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($confirm ? ' onsubmit="return confirm(\'Bu mesaj silinsin mi?\');"' : '') . '><input type="hidden" name="action" value="bossgym_contact_message_action"><input type="hidden" name="message_id" value="' . absint($id) . '"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        wp_nonce_field('bossgym_contact_message_action_' . absint($id));
        echo '<button class="button' . ($confirm ? ' button-link-delete' : '') . '">' . esc_html($label) . '</button></form>';
    }

    private function mail_status_label($status) {
        $labels = [
            'unknown' => 'Eski kayıt / bilinmiyor',
            'pending' => 'İşleniyor',
            'accepted' => 'Gönderim isteği kabul edildi (teslimat doğrulanmadı)',
            'failed' => 'Gönderim başarısız',
        ];
        return $labels[$status] ?? 'Bilinmiyor';
    }

    public function handle_admin_action() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu işlem için yetkiniz yok.')); }
        $id = absint($_POST['message_id'] ?? 0);
        check_admin_referer('bossgym_contact_message_action_' . $id);
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        global $wpdb;
        if ($operation === 'delete') { $wpdb->delete($this->table, ['id' => $id], ['%d']); }
        elseif (in_array($operation, ['read', 'unread'], true)) { $wpdb->update($this->table, ['status' => $operation], ['id' => $id], ['%s'], ['%d']); }
        $url = admin_url('admin.php?page=bossgym-contact-messages');
        if ($operation !== 'delete') { $url = add_query_arg('message', $id, $url); }
        wp_safe_redirect(add_query_arg($operation === 'delete' ? 'deleted' : 'updated', '1', $url));
        exit;
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu işlem için yetkiniz yok.')); }
        check_admin_referer('bossgym_contact_settings');
        $email = sanitize_email(wp_unslash($_POST['recipient'] ?? ''));
        if (!$email || !is_email($email)) {
            wp_safe_redirect(add_query_arg('settings', 'invalid', admin_url('admin.php?page=bossgym-contact-messages')));
            exit;
        }
        update_option('bossgym_contact_recipient', $email, false);
        wp_safe_redirect(add_query_arg('settings', 'saved', admin_url('admin.php?page=bossgym-contact-messages')));
        exit;
    }
}
