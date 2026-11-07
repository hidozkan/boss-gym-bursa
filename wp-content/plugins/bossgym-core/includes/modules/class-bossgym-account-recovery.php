<?php
if (!defined('ABSPATH')) { exit; }

/** Branded password recovery using WordPress's one-time reset key API. */
final class BossGym_Account_Recovery {
    public function __construct() {
        add_filter('do_shortcode_tag', [$this, 'filter_login_shortcode'], 10, 4);
        add_action('admin_post_nopriv_bossgym_recovery_request', [$this, 'request_reset']);
        add_action('admin_post_bossgym_recovery_request', [$this, 'request_reset']);
        add_action('admin_post_nopriv_bossgym_recovery_save', [$this, 'save_password']);
        add_action('admin_post_bossgym_recovery_save', [$this, 'save_password']);
    }

    public function filter_login_shortcode($output, $tag, $attr, $m) {
        if ($tag !== 'bossgym_login') { return $output; }
        $output = preg_replace('/href="[^"]*wp-login\.php[^"]*action=lostpassword[^"]*"/', 'href="' . esc_url($this->recovery_url()) . '"', $output);
        if (isset($_GET['bgm-reset']) && (string) wp_unslash($_GET['bgm-reset']) === '1') { return $this->recovery_screen(); }
        if (isset($_GET['bgm-reset']) && (string) wp_unslash($_GET['bgm-reset']) === 'done') {
            $output = '<div class="bgm-notice bgm-notice--success" role="status">&#350;ifreniz g&#252;ncellendi. Yeni &#351;ifrenizle giri&#351; yapabilirsiniz.</div>' . $output;
        }
        return $output;
    }

    private function page_url() { return get_permalink(16) ?: home_url('/uyelikler/'); }
    private function recovery_url($args = []) { return add_query_arg(array_merge(['bgm-reset' => '1'], $args), $this->page_url()) . '#bgm-recovery'; }

    private function recovery_screen() {
        if (!empty($_GET['key']) && !empty($_GET['login'])) {
            $login = sanitize_user(wp_unslash($_GET['login']));
            $key = sanitize_text_field(wp_unslash($_GET['key']));
            $user = check_password_reset_key($key, $login);
            if (is_wp_error($user)) { return '<section id="bgm-recovery" class="bgm-wrap bgm-card"><h2>Şifre bağlantısı geçersiz</h2><p>Bağlantının süresi dolmuş veya daha önce kullanılmış olabilir. Yeni bir sıfırlama bağlantısı isteyin.</p><a class="bgm-button" href="' . esc_url($this->recovery_url()) . '">Yeni bağlantı iste</a></section>'; }
            $nonce_action = 'bossgym_recovery_save_' . hash('sha256', $login . '|' . $key);
            return '<section id="bgm-recovery" class="bgm-wrap bgm-card"><div class="bgm-eyebrow">BOSS GYM BURSA</div><h2>Yeni şifre belirleyin</h2><form class="bgm-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_recovery_save"><input type="hidden" name="key" value="' . esc_attr($key) . '"><input type="hidden" name="login" value="' . esc_attr($login) . '">' . wp_nonce_field($nonce_action, '_bgm_recovery_nonce', true, false) . '<label for="bgm-new-password">Yeni şifre</label><input id="bgm-new-password" type="password" name="password" autocomplete="new-password" minlength="12" required><label for="bgm-new-password-confirm">Yeni şifre tekrar</label><input id="bgm-new-password-confirm" type="password" name="password_confirm" autocomplete="new-password" minlength="12" required><button class="bgm-button" type="submit">Şifreyi kaydet</button></form></section>';
        }
        $html = '<section id="bgm-recovery" class="bgm-wrap bgm-card"><div class="bgm-eyebrow">BOSS GYM BURSA</div><h2>Şifremi unuttum</h2><p>Kayıtlı e-posta adresinizi girin. Hesap varsa sıfırlama bağlantısını gönderme isteği oluşturulur.</p>';
        if (isset($_GET['bgm-recovery-sent'])) { $html .= '<div class="bgm-notice bgm-notice--success" role="status">Talebiniz al&#305;nd&#305;. G&#252;venlik nedeniyle adresin kay&#305;tl&#305; olup olmad&#305;&#287;&#305; a&#231;&#305;klanmaz. E-posta ula&#351;mazsa adresinizi ve posta sunucusunun SMTP yap&#305;land&#305;rmas&#305;n&#305; kontrol edin.</div>'; }
        $html .= '<form class="bgm-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_recovery_request">' . wp_nonce_field('bossgym_recovery_request', '_bgm_recovery_nonce', true, false) . '<label for="bgm-recovery-email">E-posta adresi</label><input id="bgm-recovery-email" type="email" name="email" autocomplete="email" required maxlength="190"><button class="bgm-button" type="submit">S&#305;f&#305;rlama Ba&#287;lant&#305;s&#305; G&#246;nder</button></form><p><a href="' . esc_url($this->page_url() . '#bgm-login') . '">Üye girişine dön</a></p></section>';
        return $html;
    }

    public function request_reset() {
        check_admin_referer('bossgym_recovery_request', '_bgm_recovery_nonce');
        $url = $this->recovery_url(['bgm-recovery-sent' => '1']);
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $rate_key = 'bg_recovery_' . hash_hmac('sha256', $ip, wp_salt('auth'));
        $count = (int) get_transient($rate_key);
        if ($count >= 3) { wp_safe_redirect($url); exit; }
        set_transient($rate_key, $count + 1, 20 * MINUTE_IN_SECONDS);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $user = $email && is_email($email) ? get_user_by('email', $email) : false;
        if ($user) {
            $key = get_password_reset_key($user);
            if (!is_wp_error($key)) {
                $link = $this->recovery_url(['login' => $user->user_login, 'key' => $key]);
                $html = '<div style="background:#101010;padding:24px;color:#f5f5f5;font-family:Arial,sans-serif"><div style="max-width:620px;margin:auto;border:1px solid #74530b;border-radius:12px"><div style="padding:20px;border-bottom:2px solid #e5a000;color:#e5a000;font-weight:bold;letter-spacing:2px">BOSS GYM BURSA</div><div style="padding:24px"><h1 style="color:#fff;font-size:22px">Şifre yenileme</h1><p style="color:#ddd;line-height:1.6">Hesabınız için şifre yenileme isteği alındı. Bu bağlantı tek kullanımlıdır ve WordPress parola sıfırlama süresi içinde geçerlidir.</p><p><a style="display:inline-block;padding:13px 20px;background:#e5a000;color:#17130a;text-decoration:none;font-weight:bold;border-radius:8px" href="' . esc_url($link) . '">Yeni şifre belirle</a></p><p style="color:#aaa">Bu isteği siz yapmadıysanız e-postayı yok sayabilirsiniz; mevcut şifreniz değişmez.</p></div></div></div>';
                wp_mail($user->user_email, 'Boss Gym Bursa — Şifre yenileme bağlantısı', $html, ['Content-Type: text/html; charset=UTF-8']);
            }
        }
        wp_safe_redirect($url); exit;
    }

    public function save_password() {
        $login = sanitize_user(wp_unslash($_POST['login'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
        $nonce = sanitize_text_field(wp_unslash($_POST['_bgm_recovery_nonce'] ?? ''));
        $action = 'bossgym_recovery_save_' . hash('sha256', $login . '|' . $key);
        if (!$nonce || !wp_verify_nonce($nonce, $action)) { wp_die('İstek doğrulanamadı. Yenileme bağlantısını yeniden açın.'); }
        $user = check_password_reset_key($key, $login);
        $password = (string) wp_unslash($_POST['password'] ?? '');
        $confirm = (string) wp_unslash($_POST['password_confirm'] ?? '');
        if (is_wp_error($user) || strlen($password) < 12 || $password !== $confirm) { wp_die('Bağlantı geçersiz/süresi dolmuş veya şifreler uygun değil. Yeni bağlantı isteyin.'); }
        reset_password($user, $password);
        wp_safe_redirect(add_query_arg('bgm-reset', 'done', $this->page_url() . '#bgm-login')); exit;
    }
}
