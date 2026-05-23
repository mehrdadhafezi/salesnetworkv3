<?php
/**
 * Plugin Name: Sales Network (شبکه فروش)
 * Plugin URI:  https://example.com
 * Description: مدیریت فروشندگان، تخصیص شماره و صدور فاکتور با پرداخت آنلاین یا کارت به کارت
 * Version: 1.0.32-cache-stability
 * Author:      Developer
 * Text Domain: sn
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_VERSION', '1.0.32-cache-stability' );

// جلوگیری از تداخل JWT plugin با AJAX requests این پلاگین
add_action( 'plugins_loaded', function() {
	if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) { return; }
	$action = sanitize_text_field( $_POST['action'] ?? $_GET['action'] ?? '' );
	if ( strpos( $action, 'sn_' ) !== 0 ) { return; }

	// JWT plugin خط ۱۱۲ یه header میفرسته — باید متوقفش کنیم
	// روش ۱: فیلتر JWT
	add_filter( 'jwt_auth_do_jwt_check', '__return_false', 999 );

	// روش ۲: حذف action مستقیم JWT از rest_api_init
	remove_action( 'rest_api_init', [ 'Jwt_Auth_Public', 'add_api_routes' ] );

	// روش ۳: output buffering را شروع کن تا headers خراب نشن
	if ( ! ob_get_level() ) {
		ob_start();
	}
}, 0 );
define( 'SN_PLUGIN_FILE', __FILE__ );
define( 'SN_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SN_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once SN_PLUGIN_DIR . 'includes/class-sn-activator.php';
require_once SN_PLUGIN_DIR . 'includes/class-sn-helpers.php';
require_once SN_PLUGIN_DIR . 'includes/class-sn-sms.php';
require_once SN_PLUGIN_DIR . 'includes/class-sn-invoice.php';
require_once SN_PLUGIN_DIR . 'includes/class-sn-plugin.php';

register_activation_hook( __FILE__, [ 'SN_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SN_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	( new SN_Plugin() )->run();
} );
