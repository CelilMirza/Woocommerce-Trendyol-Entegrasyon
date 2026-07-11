<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Trendyol REST API client (v2 uyumlu, 0.5.0).
 *
 *  - Basic Auth (apikey:apisecret base64)
 *  - User-Agent: "{sellerId} - SelfIntegration"
 *  - storeFrontCode header (TR varsayılan)
 *  - Otomatik retry (429 ve 5xx) + exponential backoff
 *  - Rate limit (transient bazlı sliding window)
 *  - Tüm istek/yanıtları logger'a yazar
 *
 * V2 endpoint'ler:
 *   GET  /product/categories
 *   GET  /product/categories/{categoryId}/attributes
 *   GET  /product/categories/{categoryId}/attributes/{attributeId}/values
 *   GET  /product/brands
 *   GET  /product/brands/by-name
 *   GET  /sellers/{sellerId}/addresses
 *   GET  /product/sellers/{sellerId}/products
 *   POST /product/sellers/{sellerId}/v2/products
 *   POST /inventory/sellers/{sellerId}/products/price-and-inventory
 *   GET  /product/sellers/{sellerId}/products/batch-requests/{batchId}
 *   GET  /order/sellers/{sellerId}/orders
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_API_Client {

	const BASE_URL_PRODUCTION = 'https://apigw.trendyol.com/integration';
	const BASE_URL_SANDBOX    = 'https://stageapigw.trendyol.com/integration';

	const RATE_LIMIT_TRANSIENT = 'wts_api_rate_window';
	const MAX_REQUESTS_PER_MIN = 100;

	protected $seller_id;
	protected $api_key;
	protected $api_secret;
	protected $integration;
	protected $base_url;
	protected $storefront_code;

	public function __construct() {
		$s = wts_settings();
		$this->seller_id   = (string) $s['api_seller_id'];
		$this->api_key     = (string) $s['api_key'];
		$this->api_secret  = (string) $s['api_secret'];
		$this->integration = $s['api_integration']
			? (string) $s['api_integration']
			: ( $this->seller_id . ' - SelfIntegration' );

		$this->storefront_code = ! empty( $s['storefront_code'] ) ? (string) $s['storefront_code'] : 'TR';

		$this->base_url = ( 'sandbox' === $s['api_mode'] )
			? self::BASE_URL_SANDBOX
			: self::BASE_URL_PRODUCTION;
	}

	public function is_configured() {
		return ( $this->seller_id && $this->api_key && $this->api_secret );
	}

	public function get_seller_id() {
		return $this->seller_id;
	}

	/* ---------- HTTP metodları ---------- */

	public function get( $path, $query = array(), $opts = array() ) {
		return $this->request( 'GET', $path, null, $query, $opts );
	}

	public function post( $path, $body = null, $query = array(), $opts = array() ) {
		return $this->request( 'POST', $path, $body, $query, $opts );
	}

	public function put( $path, $body = null, $query = array(), $opts = array() ) {
		return $this->request( 'PUT', $path, $body, $query, $opts );
	}

	public function delete( $path, $body = null, $query = array(), $opts = array() ) {
		return $this->request( 'DELETE', $path, $body, $query, $opts );
	}

	/* ---------- Ana request metodu ---------- */

	public function request( $method, $path, $body = null, $query = array(), $opts = array() ) {
		if ( ! $this->is_configured() ) {
			return $this->error_result( 0, 'API kimlik bilgileri tanımlı değil. Ayarlar sayfasından gir.' );
		}

		$path = str_replace( '{sellerId}', $this->seller_id, $path );

		$url = rtrim( $this->base_url, '/' ) . '/' . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
		}

		if ( ! $this->check_rate_limit() ) {
			$wait = $this->rate_limit_wait_seconds();
			WTS_Logger::warning( 'api_rate_limit', "Rate limit aşıldı, {$wait}s bekleniyor.", array(
				'payload' => array( 'url' => $url, 'method' => $method ),
			) );
			sleep( min( $wait, 30 ) );
		}

		$max_retry  = isset( $opts['max_retry'] ) ? (int) $opts['max_retry'] : 3;
		$timeout    = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 30;
		$log_action = isset( $opts['log_action'] ) ? $opts['log_action'] : 'api_request';
		$log_body   = ! isset( $opts['log_body'] ) || $opts['log_body'];

		// Headers — V2 için storeFrontCode zorunlu
		$headers = array(
			'Authorization'   => 'Basic ' . base64_encode( $this->api_key . ':' . $this->api_secret ),
			'User-Agent'      => $this->integration,
			'Content-Type'    => 'application/json',
			'Accept'          => 'application/json',
			'storeFrontCode'  => $this->storefront_code,
		);

		// Opsiyonel ek header'lar (örn. Accept-Language)
		if ( ! empty( $opts['headers'] ) && is_array( $opts['headers'] ) ) {
			foreach ( $opts['headers'] as $hk => $hv ) {
				$headers[ $hk ] = $hv;
			}
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		);
		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$attempt = 0;
		while ( $attempt < $max_retry ) {
			$attempt++;
			$this->record_request();

			$resp = wp_remote_request( $url, $args );

			if ( is_wp_error( $resp ) ) {
				$err = $resp->get_error_message();
				if ( $attempt < $max_retry ) {
					sleep( $this->backoff_seconds( $attempt ) );
					continue;
				}
				WTS_Logger::error( $log_action, "Bağlantı hatası: {$err}", array(
					'payload' => array( 'url' => $url, 'method' => $method, 'attempt' => $attempt ),
				) );
				return $this->error_result( 0, $err );
			}

			$code        = (int) wp_remote_retrieve_response_code( $resp );
			$body_raw    = wp_remote_retrieve_body( $resp );
			$headers_out = wp_remote_retrieve_headers( $resp );

			if ( ( 429 === $code || ( $code >= 500 && $code < 600 ) ) && $attempt < $max_retry ) {
				$wait = $this->backoff_seconds( $attempt, $headers_out );
				sleep( $wait );
				continue;
			}

			$data    = json_decode( $body_raw, true );
			$success = ( $code >= 200 && $code < 300 );

			$log_payload = array(
				'url'      => $url,
				'method'   => $method,
				'code'     => $code,
				'attempts' => $attempt,
			);
			if ( $log_body ) {
				$log_payload['request']  = $body;
				$log_payload['response'] = $data ?: $body_raw;
			}

			WTS_Logger::log( array(
				'action'  => $log_action,
				'status'  => $success ? 'success' : 'error',
				'message' => "{$method} {$path} → {$code}",
				'payload' => $log_payload,
			) );

			if ( $success ) {
				return array(
					'success' => true,
					'code'    => $code,
					'data'    => $data,
					'body'    => $body_raw,
					'error'   => '',
					'headers' => is_object( $headers_out ) ? $headers_out->getAll() : (array) $headers_out,
				);
			}

			$msg = $this->extract_error_message( $data, $body_raw );
			return array(
				'success' => false,
				'code'    => $code,
				'data'    => $data,
				'body'    => $body_raw,
				'error'   => $msg,
				'headers' => is_object( $headers_out ) ? $headers_out->getAll() : (array) $headers_out,
			);
		}

		return $this->error_result( 0, 'Maksimum deneme sayısı aşıldı.' );
	}

	/* ---------- Rate limit ---------- */

	protected function check_rate_limit() {
		$window = get_transient( self::RATE_LIMIT_TRANSIENT );
		if ( ! is_array( $window ) ) {
			return true;
		}
		$now    = time();
		$window = array_filter( $window, function ( $ts ) use ( $now ) {
			return $ts > ( $now - 60 );
		} );
		return ( count( $window ) < self::MAX_REQUESTS_PER_MIN );
	}

	protected function record_request() {
		$window = get_transient( self::RATE_LIMIT_TRANSIENT );
		if ( ! is_array( $window ) ) {
			$window = array();
		}
		$now    = time();
		$window = array_filter( $window, function ( $ts ) use ( $now ) {
			return $ts > ( $now - 60 );
		} );
		$window[] = $now;
		set_transient( self::RATE_LIMIT_TRANSIENT, $window, 120 );
	}

	protected function rate_limit_wait_seconds() {
		$window = get_transient( self::RATE_LIMIT_TRANSIENT );
		if ( ! is_array( $window ) || empty( $window ) ) {
			return 0;
		}
		$oldest = min( $window );
		return max( 1, 60 - ( time() - $oldest ) );
	}

	protected function backoff_seconds( $attempt, $headers = null ) {
		if ( $headers ) {
			$retry_after = is_object( $headers ) ? $headers->offsetGet( 'retry-after' ) : ( is_array( $headers ) ? ( $headers['retry-after'] ?? null ) : null );
			if ( $retry_after && is_numeric( $retry_after ) ) {
				return min( 60, max( 1, (int) $retry_after ) );
			}
		}
		return min( 30, (int) pow( 2, $attempt ) );
	}

	/* ---------- Hata mesajı çıkartıcı ---------- */

	protected function extract_error_message( $data, $body_raw ) {
		if ( is_array( $data ) ) {
			if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$msgs = array();
				foreach ( $data['errors'] as $e ) {
					if ( is_array( $e ) ) {
						$msg = isset( $e['message'] ) ? $e['message'] : '';
						$key = isset( $e['key'] ) ? $e['key'] : '';
						if ( $msg && $key ) {
							// "Açıklama alanı boş olamaz [productRequest.description.null]"
							$msgs[] = "{$msg} [{$key}]";
						} elseif ( $msg ) {
							$msgs[] = $msg;
						} elseif ( $key ) {
							$msgs[] = $key;
						} else {
							$msgs[] = wp_json_encode( $e );
						}
					} else {
						$msgs[] = (string) $e;
					}
				}
				return implode( ' | ', $msgs );
			}
			if ( isset( $data['message'] ) ) {
				return (string) $data['message'];
			}
			if ( isset( $data['error'] ) ) {
				return (string) $data['error'];
			}
			// Trendyol bazı endpoint'lerde {"exception":"...","errors":[{...}]} yerine doğrudan exception/timestamp döndürür
			if ( isset( $data['exception'] ) ) {
				return (string) $data['exception'] . ( isset( $data['traceId'] ) ? ' (trace: ' . $data['traceId'] . ')' : '' );
			}
		}
		if ( $body_raw && false !== stripos( $body_raw, '<!DOCTYPE html' ) ) {
			if ( false !== stripos( $body_raw, 'cloudflare' ) || false !== stripos( $body_raw, 'Attention Required' ) ) {
				return 'Cloudflare tarafından bloklandı (HTML yanıt geldi). Olası nedenler: yanlış endpoint URL, eksik/yanlış User-Agent ("sellerId - SelfIntegration" formatında olmalı), API kimlik bilgileri hatalı veya IP engellenmiş.';
			}
			return 'API JSON yerine HTML döndürdü (büyük olasılıkla yanlış endpoint ya da gateway hatası).';
		}
		return $body_raw ? mb_substr( $body_raw, 0, 500 ) : 'Bilinmeyen API hatası.';
	}

	protected function error_result( $code, $msg ) {
		return array(
			'success' => false,
			'code'    => $code,
			'data'    => null,
			'body'    => '',
			'error'   => $msg,
			'headers' => array(),
		);
	}

	/* ---------- Endpoint kısayolları (V2 uyumlu) ---------- */

	/**
	 * V2: Kategori ağacı. Eski path /product/product-categories da çalışıyor
	 * ama yeni V2 dokümantasyonu /product/categories diyor — yine de Trendyol
	 * ikisini de aynı response ile dönüyor, biz yine eskisini koruyoruz çünkü
	 * v1/v2 ortak path'i bu.
	 */
	public function get_category_tree() {
		return $this->get( '/product/product-categories', array(), array( 'log_action' => 'category_tree' ) );
	}

	/**
	 * V2: Kategori attribute listesi (sadece metadata, değerler ayrı endpoint'te).
	 * Trendyol hâlâ /product/product-categories/{id}/attributes path'ini de destekliyor.
	 */
	public function get_category_attributes( $category_id ) {
		return $this->get(
			"/product/product-categories/{$category_id}/attributes",
			array(),
			array( 'log_action' => 'category_attributes' )
		);
	}

	/**
	 * V2 yeni: Belirli bir attribute'un değerleri (sayfalı).
	 * Çok büyük listeler için size=1000 + sayfalama gerekebilir.
	 */
	public function get_category_attribute_values( $category_id, $attribute_id, $page = 0, $size = 1000 ) {
		return $this->get(
			"/product/categories/{$category_id}/attributes/{$attribute_id}/values",
			array( 'page' => (int) $page, 'size' => (int) $size ),
			array( 'log_action' => 'category_attr_values' )
		);
	}

	public function get_brands( $page = 0, $size = 1000 ) {
		return $this->get( '/product/brands', array(
			'page' => (int) $page,
			'size' => (int) $size,
		), array( 'log_action' => 'brands_list' ) );
	}

	public function search_brand( $name ) {
		return $this->get( '/product/brands/by-name', array(
			'name' => $name,
		), array( 'log_action' => 'brand_search' ) );
	}

	public function get_suppliers_addresses() {
		return $this->get(
			"/sellers/{$this->seller_id}/addresses",
			array(),
			array( 'log_action' => 'supplier_addresses' )
		);
	}

	public function filter_products( $params = array() ) {
		$defaults = array(
			'page'     => 0,
			'size'     => 50,
			'approved' => 'true',
		);
		$params = wp_parse_args( $params, $defaults );
		return $this->get(
			"/product/sellers/{$this->seller_id}/products",
			$params,
			array( 'log_action' => 'filter_products' )
		);
	}

	public function create_products( $items ) {
		return $this->post(
			"/product/sellers/{$this->seller_id}/v2/products",
			array( 'items' => $items ),
			array(),
			array( 'log_action' => 'create_products' )
		);
	}

	public function update_products( $items ) {
		return $this->put(
			"/product/sellers/{$this->seller_id}/v2/products",
			array( 'items' => $items ),
			array(),
			array( 'log_action' => 'update_products' )
		);
	}

	public function update_price_and_inventory( $items ) {
		return $this->post(
			"/inventory/sellers/{$this->seller_id}/products/price-and-inventory",
			array( 'items' => $items ),
			array(),
			array( 'log_action' => 'update_price_stock' )
		);
	}

	public function get_batch_request_result( $batch_id ) {
		return $this->get(
			"/product/sellers/{$this->seller_id}/products/batch-requests/{$batch_id}",
			array(),
			array( 'log_action' => 'batch_result' )
		);
	}

	public function get_shipment_packages( $params = array() ) {
		$defaults = array(
			'page'             => 0,
			'size'             => 50,
			'orderByField'     => 'PackageLastModifiedDate',
			'orderByDirection' => 'DESC',
		);
		$params = wp_parse_args( $params, $defaults );
		return $this->get(
			"/order/sellers/{$this->seller_id}/orders",
			$params,
			array( 'log_action' => 'fetch_orders' )
		);
	}

	public function ping() {
		return $this->get( '/product/brands', array( 'page' => 0, 'size' => 1 ), array(
			'log_action' => 'ping',
			'max_retry'  => 1,
		) );
	}
}
