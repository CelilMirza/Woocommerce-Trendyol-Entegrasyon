<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Plugin Name: Trendyol Senkronizasyon
 * Plugin URI:  https://digitalog.com.tr
 * Description: WooCommerce <-> Trendyol Pazaryeri çift yönlü senkronizasyon. FOX (WOOCS) ve WCMP çoklu fiyatlandırma ile uyumlu. Kategori eşleştirme, ürün/stok/fiyat senkronu, satış raporlama.
 * Version:     0.5.7
 * Author:      Digitalog
 * Author URI:  https://digitalog.com.tr
 * Text Domain: wts
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.9
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WTS_VERSION', '0.5.7' );
define( 'WTS_FILE', __FILE__ );
define( 'WTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WTS_URL', plugin_dir_url( __FILE__ ) );
define( 'WTS_BASENAME', plugin_basename( __FILE__ ) );

require_once WTS_PATH . 'includes/helpers.php';
require_once WTS_PATH . 'includes/class-wts-logger.php';
require_once WTS_PATH . 'includes/class-wts-activator.php';
require_once WTS_PATH . 'includes/class-wts-settings.php';
require_once WTS_PATH . 'includes/class-wts-price.php';
require_once WTS_PATH . 'includes/class-wts-api-client.php';
require_once WTS_PATH . 'includes/class-wts-brands.php';
require_once WTS_PATH . 'includes/class-wts-categories.php';
require_once WTS_PATH . 'includes/class-wts-category-attrs.php';
require_once WTS_PATH . 'includes/class-wts-addresses.php';
require_once WTS_PATH . 'includes/class-wts-products.php';
require_once WTS_PATH . 'includes/class-wts-stock-sync.php';
require_once WTS_PATH . 'includes/class-wts-orders.php';
require_once WTS_PATH . 'includes/class-wts-cron.php';
require_once WTS_PATH . 'includes/class-wts-webhook.php';
require_once WTS_PATH . 'admin/class-wts-admin.php';

/* HPOS uyumluluğu */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WTS_FILE, true );
	}
} );

/* Aktivasyon / Deaktivasyon */
register_activation_hook( __FILE__, array( 'WTS_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, function () {
	WTS_Activator::deactivate();
	WTS_Cron::unschedule_all();
} );

/* Boot */
add_action( 'plugins_loaded', function () {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Trendyol Senkronizasyon:</strong> Bu eklenti için WooCommerce gereklidir.</p></div>';
		} );
		return;
	}

	// Şema versiyonu kontrol — gerekiyorsa migrate
	if ( get_option( 'wts_db_version' ) !== WTS_VERSION ) {
		WTS_Activator::activate();
	}

	WTS_Settings::init();
	WTS_Admin::init();
	WTS_Cron::init();
	WTS_Webhook::init();
	WTS_Stock_Sync::init_hooks();
} );
