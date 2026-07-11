<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Ayarlar deposu ve admin sayfası.
 *
 * Ayarlar tek bir option olarak saklanır: wts_settings (associative array).
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Settings {

	const OPTION_KEY = 'wts_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Varsayılan ayarlar.
	 */
	public static function defaults() {
		return array(
			// API kimlik bilgileri
			'api_seller_id'      => '',
			'api_key'            => '',
			'api_secret'         => '',
			'api_integration'    => '', // Trendyol User-Agent için: "{sellerId} - SelfIntegration"
			'api_mode'           => 'production', // production | sandbox

			// Fiyatlandırma
			'price_source'       => 'sale_or_regular', // sale_or_regular | regular_only | both
			'price_currency'     => 'TRY',
			'rounding_mode'      => 'none',            // none = WP'deki fiyat aynen TL'ye çevrilir (kullanıcı zaten yuvarlamış)
			'rounding_step'      => 5,                 // sadece up_step / nearest_step modunda kullanılır
			'list_price_markup'  => 0,                 // sale price ile birlikte liste fiyatına ekstra % markup (Trendyol "list price" alanı için)
			'min_price'          => 0,                 // güvenlik: bu değerin altına düşemez

			// Vergi (mağaza zaten KDV dahil giriyorsa add_vat = false)
			'prices_include_tax' => 'auto',            // auto | yes | no
			'vat_rate'           => 20,                // KDV % (sadece add etmek gerekirse)

			// Senkron davranışı
			'sync_direction'     => 'bidirectional',    // wp_to_ty | ty_to_wp | bidirectional
			'stock_source'       => 'wp',              // wp | trendyol — çakışma durumunda kazan
			'auto_match_products'=> 'sku',             // sku | name | both | off
			'auto_match_categories' => 'name',         // name | path | off
			'default_brand_id'   => 0,                 // ürün markasız ise/eşleştirme yoksa Trendyol marka ID

			// Trendyol ürün gönderim varsayılanları (createProducts V2)
			'storefront_code'           => 'TR',       // V2 zorunlu header. TR | AZ | DE | INT vs.
			'default_shipment_address_id'   => 0,      // getSuppliersAddresses'ten alınır
			'default_returning_address_id'  => 0,
			'default_delivery_duration'     => 0,      // 0 = gönderme (en güvenli, kategori min-max çakışmasın). Trendyol profil default'u uygulanır.
			'default_dimensional_weight'    => 1,      // ürünün desi/ağırlığı yoksa fallback
			'default_cargo_company_id'      => 0,      // V2'de opsiyonel; bazı entegrasyonlar için ayar burada
			'origin_code'                   => '',     // 2 haneli ülke kodu (TR, CN vs.) — opsiyonel
			'product_main_id_strategy'      => 'parent_sku', // parent_sku | parent_id | sku

			// Cron
			'cron_fast_minutes'  => 15,                // sipariş çekme, batch sonuç
			'cron_slow_hours'    => 12,                // tam stok karşılaştırma
		);
	}

	public static function register_settings() {
		register_setting(
			'wts_settings_group',
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$current = get_option( self::OPTION_KEY, $out );
		if ( ! is_array( $current ) ) {
			$current = $out;
		}
		$out = array_merge( $out, $current );

		// API
		if ( isset( $input['api_seller_id'] ) ) {
			$out['api_seller_id'] = preg_replace( '/[^0-9]/', '', $input['api_seller_id'] );
		}
		if ( isset( $input['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( $input['api_key'] );
		}
		if ( isset( $input['api_secret'] ) ) {
			$out['api_secret'] = sanitize_text_field( $input['api_secret'] );
		}
		if ( isset( $input['api_integration'] ) ) {
			$out['api_integration'] = sanitize_text_field( $input['api_integration'] );
		}
		if ( isset( $input['api_mode'] ) && in_array( $input['api_mode'], array( 'production', 'sandbox' ), true ) ) {
			$out['api_mode'] = $input['api_mode'];
		}

		// Fiyatlandırma
		if ( isset( $input['price_source'] ) && in_array( $input['price_source'], array( 'sale_or_regular', 'regular_only', 'both' ), true ) ) {
			$out['price_source'] = $input['price_source'];
		}
		if ( isset( $input['rounding_mode'] ) && in_array( $input['rounding_mode'], array( 'up_step', 'nearest_step', 'charm9', 'none' ), true ) ) {
			$out['rounding_mode'] = $input['rounding_mode'];
		}
		if ( isset( $input['rounding_step'] ) ) {
			$step = floatval( $input['rounding_step'] );
			$out['rounding_step'] = ( $step > 0 ) ? $step : 5;
		}
		if ( isset( $input['list_price_markup'] ) ) {
			$out['list_price_markup'] = max( 0, floatval( $input['list_price_markup'] ) );
		}
		if ( isset( $input['min_price'] ) ) {
			$out['min_price'] = max( 0, floatval( $input['min_price'] ) );
		}
		if ( isset( $input['prices_include_tax'] ) && in_array( $input['prices_include_tax'], array( 'auto', 'yes', 'no' ), true ) ) {
			$out['prices_include_tax'] = $input['prices_include_tax'];
		}
		if ( isset( $input['vat_rate'] ) ) {
			$out['vat_rate'] = max( 0, floatval( $input['vat_rate'] ) );
		}

		// Senkron
		if ( isset( $input['sync_direction'] ) && in_array( $input['sync_direction'], array( 'wp_to_ty', 'ty_to_wp', 'bidirectional' ), true ) ) {
			$out['sync_direction'] = $input['sync_direction'];
		}
		if ( isset( $input['stock_source'] ) && in_array( $input['stock_source'], array( 'wp', 'trendyol' ), true ) ) {
			$out['stock_source'] = $input['stock_source'];
		}
		if ( isset( $input['auto_match_products'] ) && in_array( $input['auto_match_products'], array( 'sku', 'name', 'both', 'off' ), true ) ) {
			$out['auto_match_products'] = $input['auto_match_products'];
		}
		if ( isset( $input['auto_match_categories'] ) && in_array( $input['auto_match_categories'], array( 'name', 'path', 'off' ), true ) ) {
			$out['auto_match_categories'] = $input['auto_match_categories'];
		}
		if ( isset( $input['default_brand_id'] ) ) {
			$out['default_brand_id'] = max( 0, intval( $input['default_brand_id'] ) );
		}

		// Trendyol ürün gönderim varsayılanları
		if ( isset( $input['storefront_code'] ) ) {
			$sf = strtoupper( preg_replace( '/[^A-Za-z]/', '', $input['storefront_code'] ) );
			$out['storefront_code'] = $sf ? $sf : 'TR';
		}
		if ( isset( $input['default_shipment_address_id'] ) ) {
			$out['default_shipment_address_id'] = max( 0, intval( $input['default_shipment_address_id'] ) );
		}
		if ( isset( $input['default_returning_address_id'] ) ) {
			$out['default_returning_address_id'] = max( 0, intval( $input['default_returning_address_id'] ) );
		}
		if ( isset( $input['default_delivery_duration'] ) ) {
			$out['default_delivery_duration'] = max( 0, min( 60, intval( $input['default_delivery_duration'] ) ) );
		}
		if ( isset( $input['default_dimensional_weight'] ) ) {
			$out['default_dimensional_weight'] = max( 0, floatval( $input['default_dimensional_weight'] ) );
		}
		if ( isset( $input['default_cargo_company_id'] ) ) {
			$out['default_cargo_company_id'] = max( 0, intval( $input['default_cargo_company_id'] ) );
		}
		if ( isset( $input['origin_code'] ) ) {
			$oc = strtoupper( preg_replace( '/[^A-Za-z]/', '', $input['origin_code'] ) );
			$out['origin_code'] = mb_substr( $oc, 0, 2 );
		}
		if ( isset( $input['product_main_id_strategy'] ) && in_array( $input['product_main_id_strategy'], array( 'parent_sku', 'parent_id', 'sku' ), true ) ) {
			$out['product_main_id_strategy'] = $input['product_main_id_strategy'];
		}

		// Cron
		if ( isset( $input['cron_fast_minutes'] ) ) {
			$out['cron_fast_minutes'] = max( 5, intval( $input['cron_fast_minutes'] ) );
		}
		if ( isset( $input['cron_slow_hours'] ) ) {
			$out['cron_slow_hours'] = max( 1, intval( $input['cron_slow_hours'] ) );
		}

		return $out;
	}

	public static function get( $key, $default = null ) {
		$s = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $s ) ) {
			return $default;
		}
		$s = array_merge( self::defaults(), $s );
		return isset( $s[ $key ] ) ? $s[ $key ] : $default;
	}
}
