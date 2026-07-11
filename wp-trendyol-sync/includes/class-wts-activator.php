<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Aktivasyon: tablolar, varsayılan ayarlar, cron.
 *
 * Tablolar:
 *  - wts_category_map     : WP terim <-> Trendyol kategori eşleştirme
 *  - wts_product_map      : WP ürün <-> Trendyol ürün (barkod bazlı)
 *  - wts_sync_log         : tüm senkron işlemleri (debug + audit)
 *  - wts_batch_queue      : asenkron batch sonuç takibi
 *  - wts_sales_report     : birleşik satış kayıtları (WP + Trendyol)
 *  - wts_category_cache   : Trendyol kategori ağacı + attribute cache
 *  - wts_brand_cache      : Trendyol marka listesi cache
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Activator {

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$tables = array();

		/* Kategori eşleştirme */
		$tables[] = "CREATE TABLE {$p}wts_category_map (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_term_id BIGINT(20) UNSIGNED NOT NULL,
			ty_category_id BIGINT(20) UNSIGNED NULL,
			ty_category_path VARCHAR(500) NULL,
			match_type VARCHAR(20) NOT NULL DEFAULT 'unmatched',
			confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
			suggestion JSON NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_term (wp_term_id),
			KEY idx_ty_cat (ty_category_id),
			KEY idx_status (match_type)
		) {$charset};";

		/* Ürün eşleştirme */
		$tables[] = "CREATE TABLE {$p}wts_product_map (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT(20) UNSIGNED NOT NULL,
			ty_barcode VARCHAR(64) NOT NULL,
			ty_product_id VARCHAR(64) NULL,
			ty_content_id BIGINT(20) UNSIGNED NULL,
			match_type VARCHAR(20) NOT NULL DEFAULT 'unmatched',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			last_synced_at DATETIME NULL,
			last_wp_stock INT NULL,
			last_ty_stock INT NULL,
			last_wp_price DECIMAL(12,2) NULL,
			last_ty_price DECIMAL(12,2) NULL,
			last_error TEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_wp_post (wp_post_id),
			UNIQUE KEY uniq_barcode (ty_barcode),
			KEY idx_status (status),
			KEY idx_match (match_type)
		) {$charset};";

		/* Senkron log */
		$tables[] = "CREATE TABLE {$p}wts_sync_log (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			direction VARCHAR(20) NOT NULL,
			action VARCHAR(40) NOT NULL,
			wp_post_id BIGINT(20) UNSIGNED NULL,
			ty_barcode VARCHAR(64) NULL,
			batch_id VARCHAR(64) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			message TEXT NULL,
			payload LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY idx_created (created_at),
			KEY idx_batch (batch_id),
			KEY idx_post (wp_post_id),
			KEY idx_status (status)
		) {$charset};";

		/* Batch kuyruğu */
		$tables[] = "CREATE TABLE {$p}wts_batch_queue (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(64) NOT NULL,
			batch_type VARCHAR(40) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			checked_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			item_count INT UNSIGNED NOT NULL DEFAULT 0,
			success_count INT UNSIGNED NOT NULL DEFAULT 0,
			fail_count INT UNSIGNED NOT NULL DEFAULT 0,
			result LONGTEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_batch (batch_id),
			KEY idx_status (status)
		) {$charset};";

		/* Satış raporu (birleşik) */
		$tables[] = "CREATE TABLE {$p}wts_sales_report (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(20) NOT NULL,
			external_order_id VARCHAR(64) NOT NULL,
			wp_post_id BIGINT(20) UNSIGNED NULL,
			ty_barcode VARCHAR(64) NULL,
			product_name VARCHAR(255) NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			unit_price DECIMAL(12,2) NULL,
			total_price DECIMAL(12,2) NULL,
			currency VARCHAR(8) NOT NULL DEFAULT 'TRY',
			order_status VARCHAR(40) NULL,
			ordered_at DATETIME NULL,
			synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_src_order_prod (source, external_order_id, ty_barcode),
			KEY idx_source (source),
			KEY idx_ordered (ordered_at),
			KEY idx_post (wp_post_id)
		) {$charset};";

		/* Kategori cache (Trendyol kategori ağacı + attribute'lar) */
		$tables[] = "CREATE TABLE {$p}wts_category_cache (
			ty_category_id BIGINT(20) UNSIGNED NOT NULL,
			parent_id BIGINT(20) UNSIGNED NULL,
			name VARCHAR(255) NOT NULL,
			path VARCHAR(500) NOT NULL,
			is_leaf TINYINT(1) NOT NULL DEFAULT 0,
			attributes LONGTEXT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (ty_category_id),
			KEY idx_parent (parent_id),
			KEY idx_leaf (is_leaf),
			KEY idx_name (name(64))
		) {$charset};";

		/* Marka cache */
		$tables[] = "CREATE TABLE {$p}wts_brand_cache (
			ty_brand_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (ty_brand_id),
			KEY idx_name (name(64))
		) {$charset};";

		/* Marka eşleştirme: WP marka terimleri <-> Trendyol marka */
		$tables[] = "CREATE TABLE {$p}wts_brand_map (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_term_id BIGINT(20) UNSIGNED NOT NULL,
			wp_taxonomy VARCHAR(64) NOT NULL DEFAULT '',
			ty_brand_id BIGINT(20) UNSIGNED NULL,
			ty_brand_name VARCHAR(255) NULL,
			match_type VARCHAR(20) NOT NULL DEFAULT 'unmatched',
			confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_term (wp_term_id),
			KEY idx_ty_brand (ty_brand_id),
			KEY idx_status (match_type)
		) {$charset};";

		/* Trendyol ürün cache: manuel eşleştirme + stok senkronu için */
		$tables[] = "CREATE TABLE {$p}wts_ty_product_cache (
			ty_barcode VARCHAR(64) NOT NULL,
			ty_product_id VARCHAR(64) NULL,
			ty_content_id BIGINT(20) UNSIGNED NULL,
			title VARCHAR(500) NULL,
			product_main_id VARCHAR(120) NULL,
			brand VARCHAR(255) NULL,
			category_name VARCHAR(255) NULL,
			quantity INT NULL,
			sale_price DECIMAL(12,2) NULL,
			list_price DECIMAL(12,2) NULL,
			approved TINYINT(1) NOT NULL DEFAULT 1,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (ty_barcode),
			KEY idx_title (title(64)),
			KEY idx_main (product_main_id),
			KEY idx_updated (updated_at)
		) {$charset};";

		/* Kategori attribute VALUE cache (V2: değerler ayrı endpoint'ten geliyor) */
		$tables[] = "CREATE TABLE {$p}wts_category_attr_values (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ty_category_id BIGINT(20) UNSIGNED NOT NULL,
			ty_attribute_id BIGINT(20) UNSIGNED NOT NULL,
			ty_value_id BIGINT(20) UNSIGNED NOT NULL,
			value_name VARCHAR(255) NOT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_val (ty_category_id, ty_attribute_id, ty_value_id),
			KEY idx_cat_attr (ty_category_id, ty_attribute_id),
			KEY idx_name (value_name(64))
		) {$charset};";

		/* Satıcı adres cache (getSuppliersAddresses) */
		$tables[] = "CREATE TABLE {$p}wts_supplier_addresses (
			ty_address_id BIGINT(20) UNSIGNED NOT NULL,
			address_type VARCHAR(40) NOT NULL DEFAULT '',
			present_name VARCHAR(255) NULL,
			is_default_shipment TINYINT(1) NOT NULL DEFAULT 0,
			is_default_returning TINYINT(1) NOT NULL DEFAULT 0,
			city VARCHAR(120) NULL,
			district VARCHAR(120) NULL,
			postcode VARCHAR(40) NULL,
			full_address TEXT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (ty_address_id)
		) {$charset};";

		/* Kategori bazında attribute default değerleri — TOPLU GÖNDERİM İÇİN KRİTİK
		   Her zorunlu attribute için bir kez burada "varsayılan değer" ata,
		   o kategorideki tüm ürünler aynı default'la gönderilir. */
		$tables[] = "CREATE TABLE {$p}wts_category_attr_defaults (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ty_category_id BIGINT(20) UNSIGNED NOT NULL,
			ty_attribute_id BIGINT(20) UNSIGNED NOT NULL,
			ty_value_id BIGINT(20) UNSIGNED NULL,
			custom_value VARCHAR(255) NULL,
			attribute_name VARCHAR(255) NULL,
			value_name VARCHAR(255) NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_cat_attr (ty_category_id, ty_attribute_id),
			KEY idx_cat (ty_category_id)
		) {$charset};";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		/* Varsayılan ayarlar */
		$defaults = WTS_Settings::defaults();
		$current  = get_option( 'wts_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$current = array_merge( $defaults, $current );
		update_option( 'wts_settings', $current );

		update_option( 'wts_db_version', WTS_VERSION );

		/* One-time migration (v0.3.2): Yuvarlamayı kapat —
		   kullanıcı WP fiyatlarını zaten yuvarlanmış olarak tuttuğunu belirtti. */
		if ( ! get_option( 'wts_v032_round_off' ) ) {
			$s = get_option( WTS_Settings::OPTION_KEY, array() );
			if ( is_array( $s ) ) {
				$s['rounding_mode']     = 'none';
				$s['list_price_markup'] = 0;
				update_option( WTS_Settings::OPTION_KEY, $s );
			}
			update_option( 'wts_v032_round_off', 1 );
		}

		/* One-time migration (v0.5.4): default_delivery_duration eskiden 3'tü.
		   Trendyol kategorilerinin min-max aralığı 3'ü kabul etmiyor olabiliyor —
		   default'u 0 (gönderme) yapıyoruz. Kullanıcı elle değiştirmediyse migrate et. */
		if ( ! get_option( 'wts_v054_delivery_off' ) ) {
			$s = get_option( WTS_Settings::OPTION_KEY, array() );
			if ( is_array( $s ) && isset( $s['default_delivery_duration'] ) && (int) $s['default_delivery_duration'] === 3 ) {
				$s['default_delivery_duration'] = 0;
				update_option( WTS_Settings::OPTION_KEY, $s );
			}
			update_option( 'wts_v054_delivery_off', 1 );
		}
	}

	public static function deactivate() {
		// Cron job'ları aktiflerse temizle (henüz kurulmadı).
		$hooks = array( 'wts_cron_fast', 'wts_cron_slow' );
		foreach ( $hooks as $h ) {
			$ts = wp_next_scheduled( $h );
			if ( $ts ) {
				wp_unschedule_event( $ts, $h );
			}
		}
	}
}
