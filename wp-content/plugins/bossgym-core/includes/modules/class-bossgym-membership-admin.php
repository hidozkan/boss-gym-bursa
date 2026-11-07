<?php
if (!defined('ABSPATH')) { exit; }
final class BossGym_Membership_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_menu', [$this, 'reorder_submenu'], 999);
        add_action('admin_post_bossgym_admin_update', [$this, 'update_member']);
        add_action('admin_post_bossgym_membership_pt_fees', [$this, 'save_pt_fees']);
        add_action('admin_init', [$this, 'restrict_member_admin_access'], 1);
        add_filter('show_admin_bar', [$this, 'filter_admin_bar'], 20);
    }
    public function menu() {
        add_menu_page('Boss Gym Bursa', 'Boss Gym Bursa', 'manage_options', 'bossgym-overview', [$this, 'overview'], 'dashicons-awards', 58);
        add_submenu_page('bossgym-overview', 'Genel Bakış', 'Genel Bakış', 'manage_options', 'bossgym-overview', [$this, 'overview']);
        add_submenu_page('bossgym-overview', 'Üyelik Yönetimi', 'Üyelik Yönetimi', 'manage_options', 'bossgym-memberships', [$this, 'render']);
    }

    public function reorder_submenu() {
        global $submenu;
        $parent = 'bossgym-overview';
        if (empty($submenu[$parent]) || !is_array($submenu[$parent])) { return; }
        $order = [
            'bossgym-overview',
            'bossgym-memberships',
            'bossgym-pt',
            'edit.php?post_type=bg_pt_trainer',
            'edit.php?post_type=bg_pt_package',
            'bossgym-court-bookings',
            'edit.php?post_type=bg_court',
            'bossgym-referees',
            'bossgym-pt-settings',
            'bossgym-contact-messages',
        ];
        $items = $submenu[$parent];
        usort($items, static function ($a, $b) use ($order) {
            $a_pos = array_search($a[2] ?? '', $order, true);
            $b_pos = array_search($b[2] ?? '', $order, true);
            $a_pos = ($a_pos === false) ? count($order) : $a_pos;
            $b_pos = ($b_pos === false) ? count($order) : $b_pos;
            return $a_pos <=> $b_pos;
        });
        $submenu[$parent] = $items;
    }

    public function restrict_member_admin_access() {
        if (!is_user_logged_in() || current_user_can('manage_options') || wp_doing_ajax()) { return; }
        $script = isset($_SERVER['PHP_SELF']) ? basename(wp_unslash($_SERVER['PHP_SELF'])) : '';
        // Front-end forms and PT slot lookups use these WordPress endpoints.
        if (in_array($script, ['admin-ajax.php', 'admin-post.php'], true)) { return; }
        $dashboard = get_permalink(35);
        wp_safe_redirect($dashboard ? $dashboard : home_url('/uye-paneli/'));
        exit;
    }

    public function filter_admin_bar($show) {
        // Keep the native toolbar inside wp-admin; do not expose admin navigation on the site frontend.
        return is_admin() ? $show : false;
    }

    public function overview() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu sayfaya erişim izniniz yok.')); }
        global $wpdb;
        $pending_query = new WP_User_Query(['meta_key' => 'bossgym_application_status', 'meta_value' => 'pending', 'fields' => 'ID', 'number' => 1, 'count_total' => true]);
        $pending_memberships = (int) $pending_query->get_total();
        $table = $wpdb->prefix . 'bg_pt_appointments';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        $pt_requests = $table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'") : 0;
        $court_table = $wpdb->prefix . 'bg_court_reservations';
        $court_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($court_table))) === $court_table;
        $court_requests = $court_table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$court_table} WHERE status = 'pending'") : 0;
        $referee_table = $wpdb->prefix . 'bg_referee_requests';
        $referee_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($referee_table))) === $referee_table;
        $referee_requests = $referee_table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$referee_table} WHERE status IN ('requested','reviewing')") : 0;
        $trainers = wp_count_posts('bg_pt_trainer');
        $packages = wp_count_posts('bg_pt_package');
        $courts = wp_count_posts('bg_court');
        $cards = [
            ['Üyelik Yönetimi', 'Üye başvuruları, paketler ve üyelik tarihleri.', admin_url('admin.php?page=bossgym-memberships'), $pending_memberships . ' bekleyen başvuru', 'active'],
            ['PT Randevuları', 'Randevu başvurularını inceleyin ve durumlarını yönetin.', admin_url('admin.php?page=bossgym-pt'), $pt_requests . ' bekleyen randevu', 'active'],
            ['Antrenör Yönetimi', 'Antrenör profillerini ve çalışma bilgilerini düzenleyin.', admin_url('edit.php?post_type=bg_pt_trainer'), (int) ($trainers->publish ?? 0) . ' yayımlanmış profil', 'active'],
            ['PT Paketleri', 'Bireysel ve grup PT paketlerini yapılandırın.', admin_url('edit.php?post_type=bg_pt_package'), (int) ($packages->publish ?? 0) . ' yayımlanmış paket', 'active'],
            ['Saha Rezervasyonları', 'Rezervasyonları, tarihleri ve durumlarını yönetin.', admin_url('admin.php?page=bossgym-court-bookings'), $court_requests . ' bekleyen talep', 'active'],
            ['Saha Yönetimi', 'Saha bilgileri, ücretleri, saatleri ve bakım aralıkları.', admin_url('edit.php?post_type=bg_court'), (int) ($courts->publish ?? 0) . ' kayıtlı saha', 'active'],
            ['Hakem Talepleri', 'İlişkili hakem hizmeti taleplerini değerlendirin.', admin_url('admin.php?page=bossgym-referees'), $referee_requests . ' işlem bekleyen talep', 'active'],
            ['Genel Ayarlar', 'PT ve saha rezervasyonlarında aktif üyelik koşulu.', admin_url('admin.php?page=bossgym-pt-settings'), 'Yönetilebilir', 'active'],
        ];
        echo '<div class="wrap bossgym-admin bossgym-admin--overview"><header class="bossgym-admin__header"><h1>Boss Gym Bursa — Genel Bakış</h1><p>Üyelik, PT, saha rezervasyonu ve hakem talebi yönetimlerine buradan erişin.</p></header><div class="bossgym-admin-overview-grid">';
        foreach ($cards as $card) {
            echo '<a class="bossgym-admin-overview-card bossgym-admin-overview-card--' . esc_attr($card[4]) . '" href="' . esc_url($card[2]) . '"><span class="bossgym-admin-overview-card__status">' . esc_html($card[3]) . '</span><h2>' . esc_html($card[0]) . '</h2><p>' . esc_html($card[1]) . '</p><span class="bossgym-admin-overview-card__link">Yönetim ekranını aç →</span></a>';
        }
        echo '</div></div>';
    }

    private function planned_module_page($title, $description) {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu sayfaya erişim izniniz yok.')); }
        echo '<div class="wrap bossgym-admin bossgym-admin--planned"><header class="bossgym-admin__header"><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></header><section class="bossgym-admin__card"><span class="bossgym-admin-planned-badge">Henüz geliştirilmedi</span><p>Bu menü, modül hazır olduğunda yönetim arayüzüne bağlanacaktır. Şu anda burada çalışan rezervasyon veya talep kaydı bulunmuyor.</p></section></div>';
    }
    public function save_pt_fees() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu işlem için yetkiniz yok.')); }
        check_admin_referer('bossgym_membership_pt_fees');
        $raw = isset($_POST['pt_fees']) && is_array($_POST['pt_fees']) ? wp_unslash($_POST['pt_fees']) : [];
        $fees = [];
        foreach (BossGym_Memberships::packages() as $key => $label) {
            $value = isset($raw[$key]) && is_scalar($raw[$key]) ? (string) $raw[$key] : '0';
            $fees[$key] = preg_match('/^\d{1,6}$/', $value) ? min(100000, (int) $value) : 0;
        }
        update_option('bossgym_membership_pt_fees', $fees, false);
        wp_safe_redirect(add_query_arg('fees_updated', '1', admin_url('admin.php?page=bossgym-memberships')));
        exit;
    }
    public function render() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu sayfaya erişim izniniz yok.')); }
        $users = get_users(['number' => 500, 'orderby' => 'registered', 'order' => 'DESC', 'meta_query' => ['relation' => 'OR', ['key' => 'bossgym_application_status', 'compare' => 'EXISTS'], ['key' => 'bossgym_membership_status', 'compare' => 'EXISTS']]]);
        echo '<div class="wrap bossgym-admin bossgym-admin--memberships"><header class="bossgym-admin__header"><h1>Boss Gym üyelikleri</h1><p>Başvurular ödeme oluşturmaz. Üyelik yalnızca yönetici onayıyla etkinleşir. Cinsiyet bilgisi isteğe bağlıdır ve yalnızca bu yetkili ekranda görüntülenir.</p></header>';
        if (!empty($_GET['updated'])) { echo '<div class="notice notice-success is-dismissible"><p>Üyelik bilgileri güncellendi.</p></div>'; }
        if (!empty($_GET['fees_updated'])) { echo '<div class="notice notice-success is-dismissible"><p>PT ek ücretleri güncellendi. Ücret tanımlanmayan paketlerde antrenör seçimi kapalıdır; antrenör seçilmemesi indirim oluşturmaz.</p></div>'; }
        $pt_fees = get_option('bossgym_membership_pt_fees', []);
        echo '<section class="bossgym-admin__card bossgym-membership-fees"><h2>Üyelik paketi PT / antrenör ek ücretleri</h2><p>Paket taban fiyatları mevcut üyelik sistemindeki temsili değerlerden gelir. Buradaki tutar, antrenör seçilirse paket dönemine eklenir. 0 veya boş değer, PT ek ücreti tanımlanmadığı anlamına gelir; bu durumda antrenör seçimi sunulmaz ve indirim yapılmaz.</p><form class="bossgym-admin__form bossgym-membership-fees__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_membership_pt_fees">';
        wp_nonce_field('bossgym_membership_pt_fees');
        echo '<div class="bossgym-membership-fees__grid">';
        foreach (BossGym_Memberships::packages() as $key => $label) {
            $base = (int) BossGym_Memberships::BASE_PRICES[$key]; $fee = isset($pt_fees[$key]) ? absint($pt_fees[$key]) : 0;
            echo '<label>' . esc_html($label) . '<span class="description">Paket tabanı: ₺' . esc_html(number_format_i18n($base)) . ' (temsili)</span><input type="number" min="0" max="100000" step="1" name="pt_fees[' . esc_attr($key) . ']" value="' . $fee . '" aria-label="' . esc_attr($label . ' PT/antrenör ek ücreti') . '"></label>';
        }
        echo '</div><button class="button button-primary" type="submit">Ek ücretleri kaydet</button></form></section>';
        echo '<div class="bossgym-admin__table-wrap"><table class="widefat striped bossgym-admin__table bossgym-admin__table--memberships"><thead><tr><th>Üye</th><th>Cinsiyet</th><th>Başvuru</th><th>Durum</th><th>Başlangıç / Bitiş</th><th>Yönetim</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $app = get_user_meta($u->ID, 'bossgym_application_package', true);
            $current = get_user_meta($u->ID, 'bossgym_membership_package', true);
            $status = get_user_meta($u->ID, 'bossgym_membership_status', true) ?: 'pending';
            $start = get_user_meta($u->ID, 'bossgym_membership_start', true);
            $end = get_user_meta($u->ID, 'bossgym_membership_end', true);
            echo '<tr><td>' . esc_html($u->display_name) . '<br><small>' . esc_html($u->user_email) . ' · ID ' . (int) $u->ID . '</small></td><td>' . esc_html(BossGym_Memberships::gender_label((int) $u->ID)) . '</td><td>' . esc_html(BossGym_Memberships::packages()[$app] ?? '—') . ' (' . esc_html(get_user_meta($u->ID, 'bossgym_application_status', true) ?: '—') . ')</td><td>' . esc_html($status) . '</td><td>' . esc_html(($start ?: '—') . ' / ' . ($end ?: '—')) . '</td><td>';
            echo '<form class="bossgym-admin__form bossgym-admin__form--membership" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bossgym_admin_update"><input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
            wp_nonce_field('bossgym_admin_update_' . $u->ID);
            echo '<label class="screen-reader-text" for="bgmp-' . (int) $u->ID . '">Paket</label><select id="bgmp-' . (int) $u->ID . '" name="package">';
            foreach (BossGym_Memberships::packages() as $key => $label) { echo '<option value="' . esc_attr($key) . '" ' . selected($app ?: $current, $key, false) . '>' . esc_html($label) . '</option>'; }
            echo '</select> <select name="status" aria-label="Üyelik durumu">';
            foreach (['pending' => 'Beklemede', 'active' => 'Aktif', 'expired' => 'Süresi dolmuş'] as $key => $label) { echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>'; }
            echo '</select><label>Başlangıç <input type="date" name="start" value="' . esc_attr($start) . '"></label><label>Bitiş <input type="date" name="end" value="' . esc_attr($end) . '"></label><button class="button button-primary" type="submit">Kaydet / onayla</button></form></td></tr>';
        }
        if (!$users) { echo '<tr><td colspan="6">Henüz üyelik kaydı yok.</td></tr>'; }
        echo '</tbody></table></div></div>';
    }
    private function valid_date($value) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) { return false; }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
    public function update_member() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Bu işlem için yetkiniz yok.')); }
        $id = absint($_POST['user_id'] ?? 0);
        check_admin_referer('bossgym_admin_update_' . $id);
        $user = get_user_by('id', $id);
        $package = sanitize_key(wp_unslash($_POST['package'] ?? ''));
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        if (!$user || !isset(BossGym_Memberships::packages()[$package]) || !in_array($status, ['pending', 'active', 'expired'], true)) { wp_die(esc_html__('Üyelik verileri geçersiz.')); }
        $start = sanitize_text_field(wp_unslash($_POST['start'] ?? ''));
        $end = sanitize_text_field(wp_unslash($_POST['end'] ?? ''));
        if (($start && !$this->valid_date($start)) || ($end && !$this->valid_date($end))) { wp_die(esc_html__('Tarih biçimi geçersiz.')); }
        if ($status === 'active') {
            update_user_meta($id, 'bossgym_membership_package', $package);
            if (!$start) { $start = current_time('Y-m-d'); }
            if (!$end) { $months = ['aylik' => 1, 'uc-aylik' => 3, 'alti-aylik' => 6, 'yillik' => 12][$package]; $end = (new DateTimeImmutable($start))->modify('+' . $months . ' months')->format('Y-m-d'); }
            update_user_meta($id, 'bossgym_membership_start', $start);
            update_user_meta($id, 'bossgym_membership_end', $end);
            update_user_meta($id, 'bossgym_application_status', 'approved');
        } elseif ($status === 'pending') {
            update_user_meta($id, 'bossgym_application_package', $package);
            update_user_meta($id, 'bossgym_application_status', 'pending');
            update_user_meta($id, 'bossgym_application_at', current_time('mysql', true));
        } else {
            update_user_meta($id, 'bossgym_membership_package', $package);
            if ($start) { update_user_meta($id, 'bossgym_membership_start', $start); }
            if ($end) { update_user_meta($id, 'bossgym_membership_end', $end); }
        }
        update_user_meta($id, 'bossgym_membership_status', $status);
        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=bossgym-memberships')));
        exit;
    }
}
