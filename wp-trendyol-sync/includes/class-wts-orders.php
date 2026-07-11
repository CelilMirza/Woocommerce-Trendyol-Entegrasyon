<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Sipariş çekme + birleşik satış raporu.
 *
 *  - Trendyol siparişlerini periyodik çek, sales_report tablosuna yaz.
 *  - WC siparişleri içinde "Trendyol değil" olanları aynı tabloya yansıt.
 *  - Rapor sorguları: platform / ürün / tarih kırılımları.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Orders {

	/**
	 * Son N gündür Trendyol siparişlerini çek.
	 */
	public static function pull_trendyol_orders( $days = 7 ) {
		global $wpdb;
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array( 'success' => false, 'count' => 0, 'error' => 'API yapılandırılmamış.' );
		}

		$start_date = ( time() - ( $days * 86400 ) ) * 1000; // Trendyol ms timestamp
		$end_date   = time() * 1000;

		$page    = 0;
		$size    = 200;
		$total   = 0;
		$guard   = 0;
		$tbl     = $wpdb->prefix . 'wts_sales_report';

		while ( $guard++ < 200 ) {
			$resp = $api->get_shipment_packages( array(
				'startDate' => $start_date,
				'endDate'   => $end_date,
				'page'      => $page,
				'size'      => $size,
				'orderByField'     => 'PackageLastModifiedDate',
				'orderByDirection' => 'DESC',
			) );
			if ( ! $resp['success'] ) {
				return array( 'success' => false, 'count' => $total, 'error' => $resp['error'] );
			}

			$content = isset( $resp['data']['content'] ) ? $resp['data']['content'] : array();
			if ( empty( $content ) ) {
				break;
			}

			foreach ( $content as $pkg ) {
				$order_no   = isset( $pkg['orderNumber'] ) ? (string) $pkg['orderNumber'] : '';
				$status     = isset( $pkg['status'] ) ? (string) $pkg['status'] : '';
				$created_ms = isset( $pkg['orderDate'] ) ? (int) $pkg['orderDate'] : 0;
				$ordered_at = $created_ms ? gmdate( 'Y-m-d H:i:s', (int) ( $created_ms / 1000 ) ) : current_time( 'mysql' );

				$lines = isset( $pkg['lines'] ) ? $pkg['lines'] : array();
				foreach ( $lines as $line ) {
					$barcode    = isset( $line['barcode'] ) ? (string) $line['barcode'] : '';
					$name       = isset( $line['productName'] ) ? (string) $line['productName'] : '';
					$qty        = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
					$unit_price = isset( $line['price'] ) ? floatval( $line['price'] ) : null;
					$total_price = isset( $line['amount'] ) ? floatval( $line['amount'] ) : ( $unit_price * $qty );

					$wp_id = $barcode ? wc_get_product_id_by_sku( $barcode ) : 0;

					// upsert via unique key (source, external_order_id, ty_barcode)
					$existing = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$tbl} WHERE source = %s AND external_order_id = %s AND ty_barcode = %s",
						'trendyol', $order_no, $barcode
					) );

					$row = array(
						'source'            => 'trendyol',
						'external_order_id' => $order_no,
						'wp_post_id'        => $wp_id ?: null,
						'ty_barcode'        => $barcode,
						'product_name'      => mb_substr( $name, 0, 250 ),
						'qty'               => $qty,
						'unit_price'        => $unit_price,
						'total_price'       => $total_price,
						'currency'          => 'TRY',
						'order_status'      => $status,
						'ordered_at'        => $ordered_at,
					);

					if ( $existing ) {
						$wpdb->update( $tbl, $row, array( 'id' => $existing ) );
					} else {
						$wpdb->insert( $tbl, $row );
					}
					$total++;
				}
			}

			if ( count( $content ) < $size ) {
				break;
			}
			$page++;
		}

		WTS_Logger::success( 'orders_pull', "Trendyol siparişleri: {$total} kalem güncellendi (son {$days} gün)." );
		update_option( 'wts_orders_last_pull', current_time( 'mysql' ) );
		return array( 'success' => true, 'count' => $total, 'error' => '' );
	}

	/**
	 * WC siparişlerini (Trendyol olmayan satış kanalı) sales_report'a yansıt.
	 *
	 * Son N gündür "processing", "completed" siparişlerini al.
	 */
	public static function pull_wc_orders( $days = 7 ) {
		global $wpdb;
		$tbl   = $wpdb->prefix . 'wts_sales_report';
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		$orders = wc_get_orders( array(
			'limit'        => -1,
			'status'       => array( 'processing', 'completed', 'on-hold' ),
			'date_created' => '>=' . $since,
		) );

		$total = 0;
		foreach ( $orders as $order ) {
			$order_no  = (string) $order->get_id();
			$status    = $order->get_status();
			$ordered_at = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

			foreach ( $order->get_items() as $item ) {
				$product   = $item->get_product();
				$wp_id     = $product ? $product->get_id() : 0;
				$sku       = $product ? $product->get_sku() : '';
				$name      = $item->get_name();
				$qty       = (int) $item->get_quantity();
				$total_p   = floatval( $item->get_total() ) + floatval( $item->get_total_tax() );
				$unit_p    = $qty > 0 ? round( $total_p / $qty, 2 ) : 0;

				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$tbl} WHERE source = %s AND external_order_id = %s AND ty_barcode = %s",
					'wp', $order_no, $sku
				) );

				$row = array(
					'source'            => 'wp',
					'external_order_id' => $order_no,
					'wp_post_id'        => $wp_id ?: null,
					'ty_barcode'        => $sku,
					'product_name'      => mb_substr( $name, 0, 250 ),
					'qty'               => $qty,
					'unit_price'        => $unit_p,
					'total_price'       => $total_p,
					'currency'          => $order->get_currency(),
					'order_status'      => $status,
					'ordered_at'        => $ordered_at,
				);

				if ( $existing ) {
					$wpdb->update( $tbl, $row, array( 'id' => $existing ) );
				} else {
					$wpdb->insert( $tbl, $row );
				}
				$total++;
			}
		}

		WTS_Logger::success( 'orders_pull_wc', "WC siparişleri: {$total} kalem güncellendi." );
		return array( 'success' => true, 'count' => $total );
	}

	/* ---------- Rapor sorguları ---------- */

	/**
	 * Toplam satışların platform kırılımı.
	 */
	public static function totals_by_source( $from = null, $to = null ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_sales_report';
		list( $where, $params ) = self::date_filter( $from, $to );
		$sql = "SELECT source, COUNT(DISTINCT external_order_id) AS orders, SUM(qty) AS qty, SUM(total_price) AS total
			FROM {$tbl} WHERE {$where} GROUP BY source";
		if ( $params ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		}
		return $wpdb->get_results( $sql );
	}

	/**
	 * Ürün bazlı satışlar.
	 */
	public static function top_products( $from = null, $to = null, $limit = 20 ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_sales_report';
		list( $where, $params ) = self::date_filter( $from, $to );
		$sql = "SELECT product_name, ty_barcode, wp_post_id,
			SUM(CASE WHEN source='wp'       THEN qty ELSE 0 END) AS qty_wp,
			SUM(CASE WHEN source='trendyol' THEN qty ELSE 0 END) AS qty_ty,
			SUM(qty) AS qty_total,
			SUM(total_price) AS revenue
			FROM {$tbl} WHERE {$where} GROUP BY ty_barcode, product_name ORDER BY revenue DESC LIMIT %d";
		$params[] = (int) $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Günlük zaman serisi (her gün her platform için ciro).
	 */
	public static function daily_series( $from = null, $to = null ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_sales_report';
		list( $where, $params ) = self::date_filter( $from, $to );
		$sql = "SELECT DATE(ordered_at) AS day, source,
			SUM(total_price) AS revenue, SUM(qty) AS qty
			FROM {$tbl} WHERE {$where} GROUP BY day, source ORDER BY day ASC";
		if ( $params ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		}
		return $wpdb->get_results( $sql );
	}

	protected static function date_filter( $from, $to ) {
		$where  = '1=1';
		$params = array();
		if ( $from ) {
			$where   .= ' AND ordered_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( $to ) {
			$where   .= ' AND ordered_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		return array( $where, $params );
	}
}
