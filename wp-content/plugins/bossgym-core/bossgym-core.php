<?php
/**
 * Plugin Name: Boss Gym Core
 * Description: Boss Gym Bursa için modüler site özellikleri. İlk modül: üyelik başvuruları ve üye paneli.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Boss Gym Bursa
 * Text Domain: bossgym-core
 */
if (!defined('ABSPATH')) { exit; }
define('BOSSGYM_CORE_VERSION', '1.0.0');
define('BOSSGYM_CORE_FILE', __FILE__);
define('BOSSGYM_CORE_DIR', plugin_dir_path(__FILE__));
define('BOSSGYM_CORE_URL', plugin_dir_url(__FILE__));
require_once BOSSGYM_CORE_DIR . 'includes/class-bossgym-core.php';
register_activation_hook(__FILE__, ['BossGym_Core', 'activate']);
add_action('plugins_loaded', ['BossGym_Core', 'boot']);
