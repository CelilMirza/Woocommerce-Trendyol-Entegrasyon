<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Webhook alıcısı: Trendyol'un gönderdiği sipariş bildirimlerini karşılar.
 *
 * Trendyol webhook'ları konfigüre etmek için ayrı `createWebhook` çağrısı yapılır,
 * URL olarak bu endpoint verilir:
 *   POST {site}/wp-json/wts/v1/webhook?secret={SECRET}
 *
 * SECRET ayar olarak saklanır (rastgele üretilir) ve karşılaştırılır.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'ensure_secret' ) );
	}

	public static function ensure_secret() {
		$secret = get_option( 'wts_webhook_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( 'wts_webhook_secret', $secret );
		}
	}

	public static function register_routes() {
		register_rest_route( 'wts/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
	}

	public static function authorize( $request ) {
		$secret    = get_option( 'wts_webhook_secret' );
		$provided  = $request->get_param( 'secret' );
		if ( ! $secret || ! $provided || ! hash_equals( $secret, $provided ) ) {
			return new WP_Error( 'wts_unauthorized', 'Geçersiz webhook secret.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function handle( $request ) {
		$body = $request->get_body();
		$data = json_decode( $body, true );

		WTS_Logger::info( 'webhook_incoming', 'Trendyol webhook bildirimi alındı.', array(
			'direction' => 'ty_to_wp',
			'payload'   => $data ?: $body,
		) );

		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'status' => 'invalid_payload' ), 400 );
		}

		// Trendyol webhook'ları farklı event tipleri gönderir.
		// Type'a göre işle: OrderCreated, PackageStatusChange, vs.
		$type = isset( $data['eventType'] ) ? $data['eventType'] : ( isset( $data['type'] ) ? $data['type'] : '' );

		switch ( $type ) {
			case 'OrderCreated':
			case 'PackageStatusChange':
				self::handle_order_event( $data );
				break;

			default:
				// Bilinmeyen event tipi — log ve geç.
				WTS_Logger::info( 'webhook_unknown_event', "Bilinmeyen event: {$type}" );
				break;
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	protected static function handle_order_event( $data ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_sales_report';

		$lines      = isset( $data['lines'] ) ? $data['lines'] : array();
		$order_no   = isset( $data['orderNumber'] ) ? (string) $data['orderNumber'] : '';
		$status     = isset( $data['status'] ) ? (string) $data['status'] : '';
		$created_ms = isset( $data['orderDate'] ) ? (int) $data['orderDate'] : 0;
		$ordered_at = $created_ms ? gmdate( 'Y-m-d H:i:s', (int) ( $created_ms / 1000 ) ) : current_time( 'mysql' );

		foreach ( $lines as $line ) {
			$barcode = isset( $line['barcode'] ) ? (string) $line['barcode'] : '';
			$qty     = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;

			// Satışı raporla
			$wp_id = $barcode ? wc_get_product_id_by_sku( $barcode ) : 0;
			$row = array(
				'source'            => 'trendyol',
				'external_order_id' => $order_no,
				'wp_post_id'        => $wp_id ?: null,
				'ty_barcode'        => $barcode,
				'product_name'      => isset( $line['productName'] ) ? mb_substr( $line['productName'], 0, 250 ) : '',
				'qty'               => $qty,
				'unit_price'        => isset( $line['price'] ) ? floatval( $line['price'] ) : null,
				'total_price'       => isset( $line['amount'] ) ? floatval( $line['amount'] ) : null,
				'currency'          => 'TRY',
				'order_status'      => $status,
				'ordered_at'        => $ordered_at,
			);
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE source = %s AND external_order_id = %s AND ty_barcode = %s",
				'trendyol', $order_no, $barcode
			) );
			if ( $exists ) {
				$wpdb->update( $tbl, $row, array( 'id' => $exists ) );
			} else {
				$wpdb->insert( $tbl, $row );
			}

			// WP stoğunu hemen düşür (eğer eşleşmişse)
			if ( $wp_id && 'Created' === $status ) {
				$product = wc_get_product( $wp_id );
				if ( $product ) {
					if ( ! defined( 'WTS_SYNC_IN_PROGRESS' ) ) {
						define( 'WTS_SYNC_IN_PROGRESS', true );
					}
					$current = (int) $product->get_stock_quantity();
					$product->set_stock_quantity( max( 0, $current - $qty ) );
					$product->save();
					WTS_Logger::info( 'webhook_stock_decrement', "WP ürün #{$wp_id} stoğu {$qty} düşürüldü ({$current} → " . max( 0, $current - $qty ) . ").",
						array( 'wp_post_id' => $wp_id, 'ty_barcode' => $barcode )
					);
				}
			}
		}
	}

	public static function get_webhook_url() {
		$secret = get_option( 'wts_webhook_secret' );
		return rest_url( 'wts/v1/webhook' ) . '?secret=' . $secret;
	}

	public static function regenerate_secret() {
		$new = wp_generate_password( 32, false, false );
		update_option( 'wts_webhook_secret', $new );
		return $new;
	}
}
