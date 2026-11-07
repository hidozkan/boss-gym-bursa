<?php
if (!defined('ABSPATH')) { exit; }
final class BossGym_Core {
    private static $instance;
    private $memberships;
    private $pt;
    private $courts;
    private $contact_messages;
    public static function boot() {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-facility-gallery.php';
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-memberships.php';
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-membership-admin.php';
        $this->memberships = new BossGym_Memberships();
        new BossGym_Membership_Admin();
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-pt.php';
        $this->pt = new BossGym_PT();
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-courts.php';
        $this->courts = new BossGym_Courts();
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-notifications.php';
        new BossGym_Notifications();
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-account-recovery.php';
        new BossGym_Account_Recovery();
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-turnstile.php';
        new BossGym_Turnstile();
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-contact-messages.php';
        $this->contact_messages = new BossGym_Contact_Messages();
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    public static function activate() {
        require_once BOSSGYM_CORE_DIR . 'includes/modules/class-bossgym-pt.php';
        BossGym_PT::install();
        if (!get_page_by_path('uye-paneli')) {
            wp_insert_post([
                'post_title' => 'Üye Paneli',
                'post_name' => 'uye-paneli',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => "<!-- wp:shortcode -->\n[bossgym_dashboard]\n<!-- /wp:shortcode -->",
            ]);
        }
        flush_rewrite_rules();
    }
    public function enqueue_assets() {
        if (is_page(9) || is_page(16) || is_page(17) || is_page(18) || is_page(22) || is_page('uye-paneli')) {
            $membership_css = BOSSGYM_CORE_DIR . 'assets/css/membership.css';
            wp_enqueue_style('bossgym-membership', BOSSGYM_CORE_URL . 'assets/css/membership.css', [], file_exists($membership_css) ? (string) filemtime($membership_css) : BOSSGYM_CORE_VERSION);
        }
        if (is_page(22)) {
            $contact_css = BOSSGYM_CORE_DIR . 'assets/css/contact.css';
            wp_enqueue_style('bossgym-contact', BOSSGYM_CORE_URL . 'assets/css/contact.css', ['bossgym-membership'], file_exists($contact_css) ? (string) filemtime($contact_css) : BOSSGYM_CORE_VERSION);
        }
        if (is_page('uye-paneli')) {
            $dashboard_css = BOSSGYM_CORE_DIR . 'assets/css/dashboard.css';
            wp_enqueue_style('bossgym-dashboard', BOSSGYM_CORE_URL . 'assets/css/dashboard.css', ['bossgym-membership'], file_exists($dashboard_css) ? (string) filemtime($dashboard_css) : BOSSGYM_CORE_VERSION);
        }
        if (is_page(9) || is_page(17) || is_page(18) || is_page('uye-paneli')) {
            wp_enqueue_style('bossgym-pt', BOSSGYM_CORE_URL . 'assets/css/pt.css', ['bossgym-membership'], (string) filemtime(BOSSGYM_CORE_DIR . 'assets/css/pt.css'));
        }
        if (is_page(17)) {
            wp_enqueue_script('bossgym-pt', BOSSGYM_CORE_URL . 'assets/js/pt.js', ['jquery'], (string) filemtime(BOSSGYM_CORE_DIR . 'assets/js/pt.js'), true);
            wp_localize_script('bossgym-pt', 'BossGymPT', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('bgpt_slots'), 'loading' => 'Uygun saatler yükleniyor…', 'empty' => 'Bu tarih için uygun saat yok.']);
        }
        if (is_page(16)) {
            $pricing_js = BOSSGYM_CORE_DIR . 'assets/js/membership-pricing.js';
            wp_enqueue_script('bossgym-membership-pricing', BOSSGYM_CORE_URL . 'assets/js/membership-pricing.js', [], file_exists($pricing_js) ? (string) filemtime($pricing_js) : BOSSGYM_CORE_VERSION, true);
        }
        if (is_page(9) || is_page(21)) {
            $gallery_css = BOSSGYM_CORE_DIR . 'assets/css/facility-gallery.css';
            $gallery_js = BOSSGYM_CORE_DIR . 'assets/js/facility-gallery.js';
            wp_enqueue_style('bossgym-facility-gallery', BOSSGYM_CORE_URL . 'assets/css/facility-gallery.css', [], file_exists($gallery_css) ? (string) filemtime($gallery_css) : BOSSGYM_CORE_VERSION);
            wp_enqueue_script('bossgym-facility-gallery', BOSSGYM_CORE_URL . 'assets/js/facility-gallery.js', [], file_exists($gallery_js) ? (string) filemtime($gallery_js) : BOSSGYM_CORE_VERSION, true);
        }
    }

    public function enqueue_admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $bossgym_page = isset($_GET['page']) && in_array(sanitize_key(wp_unslash($_GET['page'])), ['bossgym-overview', 'bossgym-memberships', 'bossgym-pt', 'bossgym-pt-settings', 'bossgym-court-bookings', 'bossgym-courts', 'bossgym-referees', 'bossgym-notifications', 'bossgym-turnstile', 'bossgym-occupancy', 'bossgym-turnstile-events', 'bossgym-contact-messages'], true);
        $bossgym_post = $screen && in_array($screen->post_type, ['bg_pt_trainer', 'bg_pt_package', 'bg_court'], true);
        if (!$bossgym_page && !$bossgym_post) { return; }

        $path = BOSSGYM_CORE_DIR . 'assets/css/admin.css';
        wp_enqueue_style('bossgym-admin', BOSSGYM_CORE_URL . 'assets/css/admin.css', [], file_exists($path) ? (string) filemtime($path) : BOSSGYM_CORE_VERSION);
        if (isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'bossgym-contact-messages') {
            $contact_css = BOSSGYM_CORE_DIR . 'assets/css/contact.css';
            wp_enqueue_style('bossgym-contact-admin', BOSSGYM_CORE_URL . 'assets/css/contact.css', ['bossgym-admin'], file_exists($contact_css) ? (string) filemtime($contact_css) : BOSSGYM_CORE_VERSION);
        }
    }
}
