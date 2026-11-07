<?php
if (!defined('ABSPATH')) { exit; }
final class BossGym_Memberships {
    const PACKAGES = ['aylik' => 'Aylık Başlangıç', 'uc-aylik' => 'Üç Aylık Rutin', 'alti-aylik' => 'Altı Aylık Gelişim', 'yillik' => 'Yıllık Performans'];
    const PRICES = ['aylik' => '₺1.490 / ay', 'uc-aylik' => '₺4.200 / dönem', 'alti-aylik' => '₺7.800 / dönem', 'yillik' => '₺14.400 / yıl'];
    const BASE_PRICES = ['aylik' => 1490, 'uc-aylik' => 4200, 'alti-aylik' => 7800, 'yillik' => 14400];
    const GENDER_META = 'bossgym_gender';
    const GENDERS = ['male' => 'Erkek', 'female' => 'Kadın', 'undisclosed' => 'Belirtmek istemiyorum'];
    const CONSENT_VERSION = '2026-01';
    public function __construct() {
        add_filter('rest_endpoints', [$this, 'secure_user_rest_routes']);
        add_filter('the_content', [$this, 'link_home_membership_cards'], 20);
        add_shortcode('bossgym_register', [$this, 'registration_form']);
        add_shortcode('bossgym_login', [$this, 'login_form']);
        add_shortcode('bossgym_membership_apply', [$this, 'application_form']);
        add_shortcode('bossgym_dashboard', [$this, 'dashboard']);
        add_action('admin_post_nopriv_bossgym_register', [$this, 'handle_registration']);
        add_action('admin_post_bossgym_register', [$this, 'handle_registration']);
        add_action('admin_post_bossgym_gender_update', [$this, 'handle_gender_update']);
        add_action('admin_post_nopriv_bossgym_login', [$this, 'handle_login']);
        add_action('admin_post_bossgym_login', [$this, 'handle_login']);
        add_action('admin_post_bossgym_apply', [$this, 'handle_application']);
        add_action('admin_post_nopriv_bossgym_apply', [$this, 'handle_anonymous_application']);
    }
    public function secure_user_rest_routes($endpoints) {
        foreach ($endpoints as $route => &$handlers) {
            if (strpos($route, '/wp/v2/users') !== 0) { continue; }
            $permission = function ($request) use ($route) {
                if (current_user_can('list_users')) { return true; }
                $current_id = get_current_user_id();
                $requested_id = isset($request['id']) ? (string) $request['id'] : '';
                if ($current_id && (strpos($route, '/wp/v2/users/me') === 0 || (ctype_digit($requested_id) && (int) $requested_id === $current_id))) { return true; }
                return new WP_Error('rest_forbidden', __('Kullanıcı profilleri herkese açık değildir.', 'bossgym-core'), ['status' => is_user_logged_in() ? 403 : 401]);
            };
            if (is_array($handlers) && isset($handlers['permission_callback'])) {
                $handlers['permission_callback'] = $permission;
                continue;
            }
            if (is_array($handlers)) {
                foreach ($handlers as &$handler) {
                    if (is_array($handler) && array_key_exists('permission_callback', $handler)) { $handler['permission_callback'] = $permission; }
                }
                unset($handler);
            }
        }
        unset($handlers);
        return $endpoints;
    }
    public static function packages() { return self::PACKAGES; }
    private function requested_package() {
        $raw = isset($_GET['bgm_package']) ? wp_unslash($_GET['bgm_package']) : '';
        $package = is_string($raw) ? sanitize_key($raw) : '';
        return isset(self::PACKAGES[$package]) ? $package : '';
    }
    private function requested_trainer() {
        $raw = isset($_GET['bgm_trainer']) ? wp_unslash($_GET['bgm_trainer']) : '';
        return is_scalar($raw) ? absint($raw) : 0;
    }
    private function trainer_addon_fee($package) {
        $fees = get_option('bossgym_membership_pt_fees', []);
        return isset(self::PACKAGES[$package], $fees[$package]) ? max(0, min(100000, absint($fees[$package]))) : 0;
    }
    private function valid_membership_trainer($trainer_id, $package) {
        if (!$trainer_id) { return true; }
        $trainer = get_post((int) $trainer_id);
        return $trainer && $trainer->post_type === 'bg_pt_trainer' && $trainer->post_status === 'publish' && $this->trainer_addon_fee($package) > 0;
    }
    private function membership_trainer_field($package, $selected = 0) {
        $fee = $this->trainer_addon_fee($package);
        $trainers = get_posts(['post_type' => 'bg_pt_trainer', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $html = '<label for="bgm-trainer">Antrenör seçimi <span class="bgm-muted">(isteğe bağlı)</span></label><select id="bgm-trainer" class="bgm-trainer-choice" name="bgm_trainer"><option value="0">Antrenör istemiyorum</option>';
        foreach ($trainers as $trainer) {
            $name = get_the_title($trainer);
            $expertise = get_post_meta($trainer->ID, '_bgpt_expertise', true);
            $html .= '<option value="' . (int) $trainer->ID . '" data-name="' . esc_attr($name) . '" ' . selected((int) $selected, (int) $trainer->ID, false) . ($fee <= 0 ? ' disabled' : '') . '>' . esc_html($name . ($expertise ? ' · ' . $expertise : '')) . '</option>';
        }
        $html .= '</select><p class="bgm-help bgm-trainer-fee-note" role="status">' . ($fee > 0 ? 'Antrenör/PT ek ücreti, seçiminize göre temsili toplam fiyata eklenir.' : 'Antrenör/PT ek ücreti bu paket için yönetici tarafından tanımlanmamış; yalnızca “Antrenör istemiyorum” seçeneği kullanılabilir. İndirim uygulanmaz.') . '</p>';
        return $html;
    }
    private function membership_price_summary($package = '') {
        $base = isset(self::BASE_PRICES[$package]) ? self::BASE_PRICES[$package] : 0;
        $extra = $this->trainer_addon_fee($package);
        return '<div class="bgm-price-breakdown" data-base-price="' . $base . '" data-addon-price="' . $extra . '"><div><span>Paket taban fiyatı</span><strong data-price-base>₺' . number_format_i18n($base) . '</strong></div><div><span>Antrenör / PT ek ücreti</span><strong data-price-addon>₺0</strong></div><div class="bgm-price-breakdown__total"><span>Temsili toplam</span><strong data-price-total>₺' . number_format_i18n($base) . '</strong></div><p class="bgm-help">Tüm fiyatlar temsili örnektir; bu form ödeme almaz.</p></div>';
    }
    private function save_application_pricing($user_id, $package, $trainer_id) {
        $base = (int) self::BASE_PRICES[$package];
        $fee = $trainer_id ? $this->trainer_addon_fee($package) : 0;
        update_user_meta($user_id, 'bossgym_application_trainer', $trainer_id ? (int) $trainer_id : 0);
        update_user_meta($user_id, 'bossgym_application_base_fee', $base);
        update_user_meta($user_id, 'bossgym_application_pt_fee', $fee);
        update_user_meta($user_id, 'bossgym_application_total_fee', $base + $fee);
    }

    public function link_home_membership_cards($content) {
        if (is_admin() || !is_string($content)) { return $content; }
        if (is_page(16)) {
            if (strpos($content, 'bgm-membership-card-link') !== false) {
                return str_replace('class="bgm-membership-card-link"', 'class="bgm-membership-card-link scroll-ignore"', $content);
            }
            return $this->link_application_package_cards($content);
        }
        if (!is_page(9) && !is_front_page()) { return $content; }
        $cards = ['Temel' => 'aylik', 'Aktif' => 'uc-aylik', 'Performans' => 'yillik'];
        foreach ($cards as $label => $package) {
            $pattern = '~(<div class="wp-block-group bg-card bg-price-card(?: [^"]*)?">(?:(?!<div class="wp-block-group bg-card bg-price-card).)*?<h3[^>]*>' . preg_quote($label, '~') . '</h3>.*?<p class="bg-card__note[^>]*>.*?</p>)(</div>\s*</div>)~s';
            $url = esc_url(add_query_arg('bgm_package', $package, get_permalink(16)) . '#uyelik-basvurusu');
            $link = '<a class="bgm-membership-card-link" href="' . $url . '" aria-label="' . esc_attr($label . ' üyelik paketini seç ve başvuru formuna git') . '"></a>';
            $updated = preg_replace($pattern, '$1' . $link . '$2', $content, 1, $count);
            if ($count === 1) { $content = $updated; }
        }
        return $content;
    }
    private function link_application_package_cards($content) {
        // These are the canonical package identifiers accepted by the membership form.
        $cards = [
            'Aylık Başlangıç' => 'aylik',
            'Üç Aylık Rutin' => 'uc-aylik',
            'Altı Aylık Gelişim' => 'alti-aylik',
            'Yıllık Performans' => 'yillik',
        ];
        foreach ($cards as $label => $package_id) {
            if (!isset(self::PACKAGES[$package_id])) { continue; }
            $pattern = '~(<div class="wp-block-group bg-card(?: [^"]*)?">(?:(?!</div><!-- /wp:group -->).)*?<h3[^>]*>\s*' . preg_quote($label, '~') . '\s*</h3>(?:(?!</div><!-- /wp:group -->).)*?)(</div><!-- /wp:group -->)~s';
            $url = esc_url(add_query_arg('bgm_package', $package_id, get_permalink(16)) . '#uyelik-basvurusu');
            // Kadence's smooth-scroll handler otherwise reduces same-page query+hash links to the hash,
            // dropping the selected package query argument before the form can read it.
            $anchor = '<a class="bgm-membership-card-link scroll-ignore" href="' . $url . '" aria-label="' . esc_attr($label . ' paketini seç ve başvuru formuna git') . '"></a>';
            $link = "<!-- wp:html -->\n" . $anchor . "\n<!-- /wp:html -->\n";
            $content = preg_replace_callback($pattern, static function ($matches) use ($link) { return $matches[1] . $link . $matches[2]; }, $content, 1);
        }
        return $content;
    }
    public static function normalize_gender($value) {
        if (!is_string($value) && !is_numeric($value)) { return ''; }
        $value = sanitize_key((string) $value);
        return isset(self::GENDERS[$value]) ? $value : '';
    }
    public static function gender_label($user_id) {
        $gender = self::normalize_gender(get_user_meta((int) $user_id, self::GENDER_META, true));
        return $gender ? self::GENDERS[$gender] : 'Belirtilmedi';
    }
    /**
     * Maps a member into a future aggregate category only; it does not count occupancy.
     * A future occupancy total must come from successful entry events without a later exit,
     * never from registered membership totals.
     */
    public static function gender_category_for_user($user_id) {
        $gender = self::normalize_gender(get_user_meta((int) $user_id, self::GENDER_META, true));
        return in_array($gender, ['male', 'female'], true) ? $gender : 'unspecified';
    }
    private function gender_select($selected = '', $id = 'bgm-gender') {
        $selected = self::normalize_gender($selected);
        $html = '<label for="' . esc_attr($id) . '">Cinsiyet <span class="bgm-muted">(isteğe bağlı)</span></label><select id="' . esc_attr($id) . '" name="gender" aria-describedby="' . esc_attr($id . '-purpose') . '"><option value="">Seçim yapmayın (isteğe bağlı)</option>';
        foreach (self::GENDERS as $key => $label) { $html .= '<option value="' . esc_attr($key) . '" ' . selected($selected, $key, false) . '>' . esc_html($label) . '</option>'; }
        return $html . '</select>';
    }
    private function action_url() { return esc_url(admin_url('admin-post.php')); }
    private function notice($context = '') {
        $key = isset($_GET['bgm_notice']) ? sanitize_key(wp_unslash($_GET['bgm_notice'])) : '';
        $messages = [
            'registered' => ['Kayıt tamamlandı. Giriş yaparak paket başvurunuzu takip edebilirsiniz.', 'success'],
            'login_error' => ['Kullanıcı adı veya şifre hatalı. Bilgilerinizi kontrol edip tekrar deneyin.', 'error'],
            'login_limited' => ['Çok fazla giriş denemesi yapıldı. Lütfen bir süre sonra tekrar deneyin.', 'error'],
            'login_required' => ['Kullanıcı adı/e-posta ve şifre alanlarını doldurun.', 'error'],
            'login_session_error' => ['Oturum doğrulanamadı. Sayfayı yenileyip tekrar deneyin.', 'error'],
            'register_error' => ['Kayıt tamamlanamadı. Alanları kontrol edin veya daha sonra tekrar deneyin.', 'error'],
            'duplicate' => ['Bu e-posta adresiyle bir hesap zaten bulunuyor. Giriş yapmayı deneyin.', 'error'],
            'apply' => ['Paket başvurunuz yönetici incelemesi için beklemeye alındı. Ödeme alınmadı.', 'success'],
            'apply_error' => ['Paket başvurusu alınamadı. Lütfen giriş yapıp tekrar deneyin.', 'error'],
            'gender_updated' => ['Cinsiyet tercihiniz güncellendi.', 'success'],
            'gender_error' => ['Cinsiyet bilgisi güncellenemedi. Lütfen yeniden deneyin.', 'error'],
        ];
        if (!$key || !isset($messages[$key])) { return ''; }
        $login_keys = ['login_error', 'login_limited', 'login_required', 'login_session_error'];
        if (in_array($key, $login_keys, true) && 'login' !== $context) { return ''; }
        $role = 'error' === $messages[$key][1] ? 'alert' : 'status';
        $id = 'login' === $context && 'error' === $messages[$key][1] ? ' id="bgm-login-error"' : '';
        return '<div class="bgm-notice bgm-notice--' . esc_attr($messages[$key][1]) . '"' . $id . ' role="' . esc_attr($role) . '">' . esc_html($messages[$key][0]) . '</div>';
    }
    private function login_state_key($token) {
        return 'bgm_login_state_' . hash_hmac('sha256', (string) $token, wp_salt('auth'));
    }
    private function failed_login_value() {
        $token = isset($_GET['bgm_login_state']) ? sanitize_text_field(wp_unslash($_GET['bgm_login_state'])) : '';
        if (!$token || !preg_match('/^[A-Za-z0-9]{32}$/', $token)) { return ''; }
        $key = $this->login_state_key($token);
        $state = get_transient($key);
        delete_transient($key);
        return is_array($state) && isset($state['login']) ? sanitize_text_field($state['login']) : '';
    }
    private function redirect_login_error($notice = 'login_error', $login = '') {
        $args = ['bgm_notice' => sanitize_key($notice)];
        $login = substr(sanitize_text_field((string) $login), 0, 190);
        if ('' !== $login) {
            $token = wp_generate_password(32, false, false);
            set_transient($this->login_state_key($token), ['login' => $login], 5 * MINUTE_IN_SECONDS);
            $args['bgm_login_state'] = $token;
        }
        $url = add_query_arg($args, get_permalink(16) ?: home_url('/uyelikler/')) . '#bgm-login';
        wp_safe_redirect($url);
        exit;
    }
    private function nonce($action) { return wp_nonce_field($action, '_bgm_nonce', true, false); }
    private function privacy_note() {
        $privacy_id = (int) get_option('wp_page_for_privacy_policy');
        $privacy = $privacy_id ? get_post($privacy_id) : null;
        if ($privacy && $privacy->post_status === 'publish') {
            return '<p class="bgm-privacy-note">Kayıt öncesinde <a href="' . esc_url(get_permalink($privacy_id)) . '">Gizlilik Politikası</a> metnini inceleyebilirsiniz.</p>';
        }
        return '<p class="bgm-privacy-note">Gizlilik politikası sayfası henüz yayımlanmadı. Bu form yerel/demo kullanım içindir; gerçek üyelik kayıtlarından önce gizlilik ve kullanım koşullarını yayımlayın.</p>';
    }
    private function package_select($name = 'package', $id = 'bgm-package', $selected_package = '') {
        if (!$selected_package) { $selected_package = $this->requested_package(); }
        $html = '<label for="' . esc_attr($id) . '">Üyelik paketi</label><select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="bgm-package-choice" required><option value="">Paket seçin</option>';
        foreach (self::PACKAGES as $key => $label) {
            $base = (int) self::BASE_PRICES[$key];
            $html .= '<option value="' . esc_attr($key) . '" data-base="' . $base . '" data-addon="' . $this->trainer_addon_fee($key) . '" ' . selected($selected_package, $key, false) . '>' . esc_html($label . ' — ' . self::PRICES[$key] . ' (temsili)') . '</option>';
        }
        return $html . '</select><p class="bgm-help">Fiyatlar temsili örnektir. Başvuru ödeme oluşturmaz; üyelik yönetici onayıyla etkinleşir.</p>';
    }
    public function registration_form() {
        if (is_user_logged_in()) { return ''; }
        $package = $this->requested_package(); $trainer = $this->requested_trainer();
        $html = '<section id="bgm-register" class="bgm-wrap bgm-card"><div class="bgm-eyebrow">BOSS GYM BURSA</div><h2>Üyelik başvurusu</h2><p>Bilgilerinizi oluşturarak başlayın. Paket başvuruları yönetici onayına kadar beklemede kalır.</p>' . $this->notice();
        $html .= '<form class="bgm-form" method="post" action="' . $this->action_url() . '"><input type="hidden" name="action" value="bossgym_register">' . $this->nonce('bossgym_register');
        $html .= '<div class="bgm-grid"><div><label for="bgm-first">Ad</label><input id="bgm-first" name="first_name" autocomplete="given-name" required maxlength="60"></div><div><label for="bgm-last">Soyad</label><input id="bgm-last" name="last_name" autocomplete="family-name" required maxlength="60"></div></div>';
        $html .= '<label for="bgm-email">E-posta adresi</label><input id="bgm-email" type="email" name="email" autocomplete="email" required maxlength="190">';
        $html .= $this->gender_select('', 'bgm-register-gender') . '<p id="bgm-register-gender-purpose" class="bgm-help bgm-gender-purpose">Bu bilgi isteğe bağlıdır; tesis hizmetlerini planlama ve ileride giriş-çıkış hareketlerinden toplu doluluk dağılımı oluşturma amacıyla kullanılır. Paylaşmamanız başvuruyu etkilemez.</p>';
        $html .= '<div class="bgm-grid"><div><label for="bgm-pass">Şifre</label><input id="bgm-pass" type="password" name="password" autocomplete="new-password" minlength="12" required><small>En az 12 karakter kullanın.</small></div><div><label for="bgm-pass2">Şifre tekrarı</label><input id="bgm-pass2" type="password" name="password_confirm" autocomplete="new-password" required></div></div>';
        $html .= $this->package_select('package', 'bgm-register-package', $package) . $this->membership_trainer_field($package, $trainer) . $this->membership_price_summary($package);
        $html .= '<label class="bgm-check"><input type="checkbox" name="consent" value="yes" required> Kullanım ve gizlilik koşullarını okudum ve kabul ediyorum.</label>' . $this->privacy_note();
        $html .= '<div class="bgm-honeypot" aria-hidden="true"><label>Boş bırakın<input name="website" tabindex="-1" autocomplete="off"></label></div><button class="bgm-button" type="submit">Hesap oluştur ve başvur</button></form>';
        $html .= '<p class="bgm-muted">Hesabınız var mı? <a href="#bgm-login">Giriş yapın</a>.</p></section>';
        return $html;
    }

    public function login_form() {
        if (is_user_logged_in()) { return ''; }
        $package = $this->requested_package(); $trainer = $this->requested_trainer();
        $preserve = ($package ? '<input type="hidden" name="bgm_package" value="' . esc_attr($package) . '">' : '') . ($trainer ? '<input type="hidden" name="bgm_trainer" value="' . $trainer . '">' : '');
        $login = $this->failed_login_value();
        return '<section id="bgm-login" class="bgm-wrap bgm-card"><div class="bgm-eyebrow">ÜYE GİRİŞİ</div><h2>Hesabınıza giriş yapın</h2>' . $this->notice('login') . '<form class="bgm-form" method="post" action="' . $this->action_url() . '"><input type="hidden" name="action" value="bossgym_login">' . $this->nonce('bossgym_login') . $preserve . '<label for="bgm-user">E-posta veya kullanıcı adı</label><input id="bgm-user" name="log" autocomplete="username" value="' . esc_attr($login) . '" required><label for="bgm-login-pass">Şifre</label><input id="bgm-login-pass" type="password" name="pwd" autocomplete="current-password" required><button class="bgm-button" type="submit">Giriş yap</button></form><p class="bgm-muted"><a href="' . esc_url(add_query_arg('bgm-reset', '1', get_permalink(16) ?: home_url('/uyelikler/')) . '#bgm-recovery') . '">Şifremi unuttum</a></p></section>';
    }

    public function application_form() {
        $package = $this->requested_package();
        $trainer = $this->requested_trainer();
        if (is_user_logged_in() && !$trainer) { $trainer = absint(get_user_meta(get_current_user_id(), 'bossgym_application_trainer', true)); }
        if (!is_user_logged_in()) {
            $url = add_query_arg(array_filter(['bgm_package' => $package, 'bgm_trainer' => $trainer ?: '']), get_permalink(16) ?: home_url('/uyelikler/'));
            return '<section id="uyelik-basvurusu" class="bgm-wrap bgm-card"><h2>Üyelik paketi seçin</h2><p>Başvuru için önce hesap oluşturun veya giriş yapın. Seçtiğiniz paket ve antrenör tercihi korunur.</p><a class="bgm-button" href="' . esc_url($url . '#bgm-register') . '">Kayıt ol</a> <a class="bgm-button bgm-button--outline" href="' . esc_url($url . '#bgm-login') . '">Üye girişi</a></section>';
        }
        return '<section id="uyelik-basvurusu" class="bgm-wrap bgm-card"><div class="bgm-eyebrow">PAKET SEÇİMİ</div><h2>Üyelik paketi başvurusu</h2>' . $this->notice() . '<form class="bgm-form" method="post" action="' . $this->action_url() . '"><input type="hidden" name="action" value="bossgym_apply">' . $this->nonce('bossgym_apply') . $this->package_select('package', 'bgm-apply-package', $package) . $this->membership_trainer_field($package, $trainer) . $this->membership_price_summary($package) . '<button class="bgm-button" type="submit">Başvuruyu gönder</button></form></section>';
    }

    private function redirect_notice($key, $fallback = '') {
        $url = wp_get_referer();
        if (!$url) { $url = $fallback ?: home_url('/uyelikler/'); }
        wp_safe_redirect(add_query_arg('bgm_notice', $key, $url));
        exit;
    }
    private function valid_package($package) { return isset(self::PACKAGES[$package]); }
    private function valid_request_origin() {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        if (!$origin && !empty($_SERVER['HTTP_REFERER'])) {
            $origin = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }
        if (!$origin) { return false; }
        $actual = wp_parse_url($origin);
        $expected = wp_parse_url(home_url('/'));
        if (empty($actual['host']) || empty($expected['host']) || strtolower($actual['host']) !== strtolower($expected['host'])) { return false; }
        $actual_port = isset($actual['port']) ? (int) $actual['port'] : (isset($actual['scheme']) && strtolower($actual['scheme']) === 'https' ? 443 : 80);
        $expected_port = isset($expected['port']) ? (int) $expected['port'] : (isset($expected['scheme']) && strtolower($expected['scheme']) === 'https' ? 443 : 80);
        return $actual_port === $expected_port;
    }
    private function rate_key($type) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return 'bgm_' . $type . '_' . hash_hmac('sha256', $ip, wp_salt('auth'));
    }
    public function handle_registration() {
        if (!$this->valid_request_origin() || !isset($_POST['_bgm_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bgm_nonce'])), 'bossgym_register')) { $this->redirect_notice('register_error'); }
        if (!empty($_POST['website'])) { $this->redirect_notice('register_error'); }
        $key = $this->rate_key('register'); $attempts = (int) get_transient($key);
        if ($attempts >= 5) { $this->redirect_notice('register_error'); }
        set_transient($key, $attempts + 1, HOUR_IN_SECONDS);
        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $gender_raw = wp_unslash($_POST['gender'] ?? ''); $gender_input = is_string($gender_raw) ? sanitize_key($gender_raw) : '__invalid__'; $gender = self::normalize_gender($gender_input);
        $password = (string) wp_unslash($_POST['password'] ?? ''); $confirm = (string) wp_unslash($_POST['password_confirm'] ?? '');
        $package = sanitize_key(wp_unslash($_POST['package'] ?? '')); $trainer = absint(wp_unslash($_POST['bgm_trainer'] ?? 0));
        $consent = sanitize_text_field(wp_unslash($_POST['consent'] ?? ''));
        if (!$first || !$last || !is_email($email) || email_exists($email) || strlen($password) < 12 || $password !== $confirm || !$this->valid_package($package) || !$this->valid_membership_trainer($trainer, $package) || $consent !== 'yes' || ($gender_input !== '' && !$gender)) {
            $this->redirect_notice(email_exists($email) ? 'duplicate' : 'register_error');
        }
        $base = sanitize_user(current(explode('@', $email)), true); if (!$base) { $base = 'bgm-member'; }
        $username = $base; $suffix = 1; while (username_exists($username)) { $username = $base . $suffix++; }
        $user_id = wp_insert_user(['user_login' => $username, 'user_pass' => $password, 'user_email' => $email, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim($first . ' ' . $last), 'role' => 'subscriber']);
        if (is_wp_error($user_id)) { $this->redirect_notice('register_error'); }
        if ($gender !== '') { update_user_meta($user_id, self::GENDER_META, $gender); }
        update_user_meta($user_id, 'bossgym_application_package', $package);
        $this->save_application_pricing($user_id, $package, $trainer);
        update_user_meta($user_id, 'bossgym_application_status', 'pending'); update_user_meta($user_id, 'bossgym_application_at', current_time('mysql', true));
        update_user_meta($user_id, 'bossgym_consent_at', current_time('mysql', true)); update_user_meta($user_id, 'bossgym_consent_version', self::CONSENT_VERSION);
        update_user_meta($user_id, 'bossgym_membership_status', 'pending');
        $args = ['bgm_notice' => 'registered', 'bgm_package' => $package]; if ($trainer) { $args['bgm_trainer'] = $trainer; }
        wp_safe_redirect(add_query_arg($args, get_permalink(16) ?: home_url('/uyelikler/')) . '#bgm-login'); exit;
    }

    public function handle_login() {
        $form_login = sanitize_text_field(wp_unslash($_POST['log'] ?? ''));
        if (!$this->valid_request_origin() || !isset($_POST['_bgm_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bgm_nonce'])), 'bossgym_login')) { $this->redirect_login_error('login_session_error', $form_login); }
        $key = $this->rate_key('login'); $attempts = (int) get_transient($key);
        if ($attempts >= 5) { $this->redirect_login_error('login_limited', $form_login); }
        $password = (string) wp_unslash($_POST['pwd'] ?? '');
        if ('' === trim($form_login) || '' === $password) { $this->redirect_login_error('login_required', $form_login); }
        $login = $form_login;
        if (is_email($login)) { $email_user = get_user_by('email', sanitize_email($login)); if ($email_user) { $login = $email_user->user_login; } }
        $user = wp_signon(['user_login' => $login, 'user_password' => $password, 'remember' => true], is_ssl());
        if (is_wp_error($user)) { set_transient($key, $attempts + 1, 15 * MINUTE_IN_SECONDS); $this->redirect_login_error('login_error', $form_login); }
        delete_transient($key);
        $raw = wp_unslash($_POST['bgm_package'] ?? ''); $package = is_string($raw) ? sanitize_key($raw) : '';
        $trainer = absint(wp_unslash($_POST['bgm_trainer'] ?? 0));
        if (isset(self::PACKAGES[$package]) && $this->valid_membership_trainer($trainer, $package)) {
            $args = ['bgm_package' => $package]; if ($trainer) { $args['bgm_trainer'] = $trainer; }
            wp_safe_redirect(add_query_arg($args, get_permalink(16) ?: home_url('/uyelikler/')) . '#uyelik-basvurusu'); exit;
        }
        wp_safe_redirect(home_url('/uye-paneli/')); exit;
    }

    public function handle_anonymous_application() { $this->redirect_notice('apply_error'); }
    public function handle_gender_update() {
        $dashboard = get_permalink(35) ?: home_url('/uye-paneli/');
        if (!is_user_logged_in()) { $this->redirect_notice('gender_error', $dashboard); }
        $user_id = get_current_user_id();
        $nonce = sanitize_text_field(wp_unslash($_POST['_bgm_nonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'bossgym_gender_update_' . $user_id)) { $this->redirect_notice('gender_error', $dashboard); }
        $raw = wp_unslash($_POST['gender'] ?? '');
        $input = is_string($raw) ? sanitize_key($raw) : '__invalid__';
        $gender = self::normalize_gender($input);
        if ($input !== '' && !$gender) { $this->redirect_notice('gender_error', $dashboard); }
        if ($gender === '') { delete_user_meta($user_id, self::GENDER_META); }
        else { update_user_meta($user_id, self::GENDER_META, $gender); }
        $this->redirect_notice('gender_updated', $dashboard);
    }
    public function handle_application() {
        if (!is_user_logged_in()) { $this->redirect_notice('apply_error'); }
        if (!$this->valid_request_origin() || !isset($_POST['_bgm_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bgm_nonce'])), 'bossgym_apply')) { $this->redirect_notice('apply_error'); }
        $package = sanitize_key(wp_unslash($_POST['package'] ?? ''));
        if (!$this->valid_package($package)) { $this->redirect_notice('apply_error'); }
        $trainer = absint(wp_unslash($_POST['bgm_trainer'] ?? 0));
        if (!$this->valid_membership_trainer($trainer, $package)) { $this->redirect_notice('apply_error'); }
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'bossgym_application_package', $package);
        $this->save_application_pricing($user_id, $package, $trainer);
        update_user_meta($user_id, 'bossgym_application_status', 'pending');
        update_user_meta($user_id, 'bossgym_application_at', current_time('mysql', true));
        $this->redirect_notice('apply', get_permalink(get_page_by_path('uye-paneli')));
    }

    private function status_label($status) {
        $labels = ['active' => 'Aktif', 'expired' => 'Süresi dolmuş', 'pending' => 'Beklemede'];
        return $labels[$status] ?? 'Beklemede';
    }
    public function dashboard() {
        if (!is_user_logged_in()) { return '<section class="bgm-wrap bgm-card"><h2>Üye paneli</h2><p>Panel bilgilerinizi görmek için giriş yapın.</p>' . $this->login_form() . '</section>'; }
        $user = wp_get_current_user(); $id = $user->ID;
        $package = get_user_meta($id, 'bossgym_membership_package', true);
        $status = get_user_meta($id, 'bossgym_membership_status', true) ?: 'pending';
        $end = get_user_meta($id, 'bossgym_membership_end', true);
        if ($status === 'active' && $end && strtotime($end) < strtotime(current_time('Y-m-d'))) { $status = 'expired'; }
        $app_package = get_user_meta($id, 'bossgym_application_package', true);
        $app_status = get_user_meta($id, 'bossgym_application_status', true);
        $label = $package && isset(self::PACKAGES[$package]) ? self::PACKAGES[$package] : 'Henüz atanmadı';
        $html = '<div class="bgm-wrap bgm-dashboard" aria-labelledby="bgm-dashboard-title"><div class="bgm-dashboard-head"><div><div class="bgm-eyebrow">BOSS GYM BURSA</div><h1 id="bgm-dashboard-title">Üye paneli</h1><p>Merhaba ' . esc_html($user->first_name ?: $user->display_name) . ', üyelik bilgilerin burada.</p></div><a class="bgm-button bgm-button--outline" href="' . esc_url(wp_logout_url(get_permalink())) . '">Çıkış yap</a></div>' . $this->notice();
        $html .= '<section class="bgm-card"><h2>Üyelik özeti</h2><div class="bgm-info-grid"><div><span>Ad Soyad</span><strong>' . esc_html(trim($user->first_name . ' ' . $user->last_name)) . '</strong></div><div><span>E-posta</span><strong>' . esc_html($user->user_email) . '</strong></div><div><span>Paket</span><strong>' . esc_html($label) . '</strong></div><div><span>Durum</span><strong class="bgm-status bgm-status--' . esc_attr($status) . '">' . esc_html($this->status_label($status)) . '</strong></div><div><span>Başlangıç</span><strong>' . esc_html(get_user_meta($id, 'bossgym_membership_start', true) ?: '—') . '</strong></div><div><span>Bitiş</span><strong>' . esc_html($end ?: '—') . '</strong></div></div>';
        if ($app_status === 'pending' && isset(self::PACKAGES[$app_package])) { $html .= '<p class="bgm-pending">Başvuru: <strong>' . esc_html(self::PACKAGES[$app_package]) . '</strong> · Yönetici onayı bekleniyor. Ödeme alınmadı.</p>'; }
        $html .= '<div class="bgm-gender-current"><span>Cinsiyet</span><strong>' . esc_html(self::gender_label($id)) . '</strong></div><form class="bgm-form bgm-gender-form" method="post" action="' . $this->action_url() . '"><input type="hidden" name="action" value="bossgym_gender_update">' . $this->nonce('bossgym_gender_update_' . $id) . $this->gender_select(get_user_meta($id, self::GENDER_META, true), 'bgm-dashboard-gender') . '<p id="bgm-dashboard-gender-purpose" class="bgm-help bgm-gender-purpose">Bu bilgiyi paylaşmak isteğe bağlıdır. Tesis hizmetlerini planlama ve ileride giriş-çıkış kayıtlarından toplu doluluk dağılımı oluşturma amacıyla kullanılabilir; üyeler için açık profilde gösterilmez.</p><button class="bgm-button" type="submit">Cinsiyet bilgisini kaydet</button></form>';
        $html .= '</section><section class="bgm-grid bgm-dashboard-grid"><article class="bgm-card bgm-dashboard-card bgm-dashboard-card--placeholder"><h2>PT randevuları</h2><p>Randevu özelliği sonraki geliştirme aşamasında hazırlanacaktır.</p><span class="bgm-tag">Henüz geliştirilmedi</span></article><article class="bgm-card bgm-dashboard-card"><h2>Saha rezervasyonları</h2><p>Saha takvimi ve rezervasyon alanı henüz devrede değildir.</p><span class="bgm-tag">Henüz geliştirilmedi</span></article><article class="bgm-card bgm-dashboard-card"><h2>Demo QR alanı</h2><p>QR kod ve tesis giriş-çıkış özelliği sonraki aşamalar için ayrılmıştır.</p><span class="bgm-tag">Hazırlık alanı</span></article></section><section class="bgm-card"><h2>Paket başvurusu</h2>' . $this->application_form() . '</section></div>';
        return apply_filters('bossgym_dashboard_output', $html, $id);
    }
}
