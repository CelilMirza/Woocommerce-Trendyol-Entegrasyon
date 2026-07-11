<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Stok senkronizasyonu.
 *
 * İki mod:
 *   1) Tek yön (wp_to_ty / ty_to_wp): kaynak ne diyorsa hedefe yaz.
 *   2) Çift yön (bidirectional): delta hesabıyla iki tarafın değişimini birleştir.
 *
 * Çift yön delta mantığı:
 *   - wp_delta = wp_current - last_wp_stock
 *   - ty_delta = ty_current - last_ty_stock
 *   - net = wp_delta + ty_delta  (her iki taraftaki net değişim)
 *   - new_stock = baseline + net
 *   - baseline = (last_wp_stock + last_ty_stock) / 2 değil — direkt min(last_wp, last_ty) alıyoruz,
 *     yoksa stok kazanma riski.
 *
 *   Aslında daha basit: WP'de N adet, TY'de M adet, başlangıçta X olduğunu biliyoruz.
 *     WP'de satılan = X - N
 *     TY'de satılan = X - M
 *     Toplam satılan = (X - N) + (X - M) = 2X - N - M
 *     Yeni stok = X - toplam_satılan = -X + N + M
 *
 *   Bu mantık doğru: WP=10, TY=10, son=10 → 1 satış WP'de (WP=9, TY=10) → yeni=−10+9+10=9 ✓
 *   1 satış WP, 2 satış TY → WP=9, TY=8 → yeni=−10+9+8=7 ✓
 *
 *   Negatif sonuç çıkarsa 0'a clamp.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Stock_Sync {

	/**
	 * Tüm eşleşmiş ürünler için stok senkronu yap.
	 *
	 * @param string $direction  override: wp_to_ty | ty_to_wp | bidirectional | '' (ayardan)
	 * @return array stats
	 */
	public static function sync_all( $direction = '' ) {
		global $wpdb;
		if ( '' === $direction ) {
			$direction = WTS_Settings::get( 'sync_direction', 'wp_to_ty' );
		}
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array( 'success' => false, 'error' => 'API yapılandırılmamış.' );
		}

		// Sonsuz döngü engeli: WP stoğunu burada set ederken on_wp_stock_change tetiklenmesin.
		if ( ! defined( 'WTS_SYNC_IN_PROGRESS' ) ) {
			define( 'WTS_SYNC_IN_PROGRESS', true );
		}

		// Trendyol stok index'i (CANLI API verisi — fiyat ve stok için tek doğruluk kaynağı)
		$ty_index = WTS_Products::pull_all_ty_products();

		$tbl  = $wpdb->prefix . 'wts_product_map';
		$maps = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE status IN ('synced','syncing') AND ty_barcode IS NOT NULL" );

		$updates_to_ty = array();
		// Cache'te güncellenecek barkod => alanlar haritası
		$cache_updates = array();
		$stats = array(
			'wp_updated' => 0,
			'ty_updated' => 0,
			'skipped'    => 0,
			'errors'     => 0,
		);

		foreach ( $maps as $m ) {
			$product = wc_get_product( $m->wp_post_id );
			if ( ! $product ) {
				$stats['skipped']++;
				continue;
			}
			$wp_stock = (int) $product->get_stock_quantity();
			$ty       = isset( $ty_index[ $m->ty_barcode ] ) ? $ty_index[ $m->ty_barcode ] : null;
			$ty_stock = ( $ty && isset( $ty['quantity'] ) ) ? (int) $ty['quantity'] : null;
			$ty_price = ( $ty && isset( $ty['salePrice'] ) ) ? floatval( $ty['salePrice'] ) : null;
			$ty_list  = ( $ty && isset( $ty['listPrice'] ) ) ? floatval( $ty['listPrice'] ) : null;

			if ( null === $ty_stock ) {
				$stats['skipped']++;
				continue;
			}

			// API'den çekilen TAZE değerleri her halükarda cache'e yaz — böylece "TY Stok" /
			// "TY Mevcut" sütunları senkrondan ÖNCE bile son durumu gösterir.
			$cache_updates[ $m->ty_barcode ] = array(
				'quantity'   => $ty_stock,
				'sale_price' => $ty_price,
				'list_price' => $ty_list,
			);
			// Mapping tablosunun "snapshot" alanları da TAZE TY verisiyle güncellensin
			// (kullanıcı bidirectional veya ty_to_wp seçtiğinde fark hesabı için kritik).
			$wpdb->update(
				$tbl,
				array(
					'last_ty_stock' => $ty_stock,
					'last_ty_price' => $ty_price,
				),
				array( 'id' => $m->id )
			);

			$new_stock = null;

			if ( 'wp_to_ty' === $direction ) {
				$new_stock = $wp_stock;
				if ( $new_stock !== $ty_stock ) {
					$updates_to_ty[ $m->ty_barcode ] = $new_stock;
				}
			} elseif ( 'ty_to_wp' === $direction ) {
				$new_stock = $ty_stock;
				if ( $new_stock !== $wp_stock ) {
					self::set_wp_stock( $product, $new_stock );
					$stats['wp_updated']++;
				}
			} else { // bidirectional
				$last_wp = ( null !== $m->last_wp_stock ) ? (int) $m->last_wp_stock : $wp_stock;
				$last_ty = ( null !== $m->last_ty_stock ) ? (int) $m->last_ty_stock : $ty_stock;

				// formül: new = -baseline + wp + ty   ; baseline = min(last_wp, last_ty) güvenli
				$baseline  = min( $last_wp, $last_ty );
				$new_stock = $wp_stock + $ty_stock - $baseline;
				$new_stock = max( 0, $new_stock );

				if ( $new_stock !== $wp_stock ) {
					self::set_wp_stock( $product, $new_stock );
					$stats['wp_updated']++;
				}
				if ( $new_stock !== $ty_stock ) {
					$updates_to_ty[ $m->ty_barcode ] = $new_stock;
				}
			}

			// Map satırını güncelle — senkron sonrası beklenen değer
			$wpdb->update(
				$tbl,
				array(
					'last_wp_stock'  => ( null !== $new_stock ) ? $new_stock : $wp_stock,
					'last_ty_stock'  => ( null !== $new_stock ) ? $new_stock : $ty_stock,
					'last_synced_at' => current_time( 'mysql' ),
				),
				array( 'id' => $m->id ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);

			// Cache'te de yeni stok değerini güncelle (Trendyol'a push edildiğini varsayıyoruz —
			// batch reddedilirse zaten kullanıcı log'larda görür; bir sonraki senkron taze veriyle
			// üzerine yazacak.)
			if ( null !== $new_stock ) {
				$cache_updates[ $m->ty_barcode ]['quantity'] = (int) $new_stock;
			}
		}

		// Trendyol'a toplu update
		if ( ! empty( $updates_to_ty ) ) {
			$items = array();
			foreach ( $updates_to_ty as $bar => $qty ) {
				$items[] = array(
					'barcode'  => $bar,
					'quantity' => (int) $qty,
				);
			}
			// 100'erli batch
			foreach ( array_chunk( $items, 100 ) as $chunk ) {
				$resp = $api->update_price_and_inventory( $chunk );
				if ( $resp['success'] ) {
					$stats['ty_updated'] += count( $chunk );
					$bid = isset( $resp['data']['batchRequestId'] ) ? $resp['data']['batchRequestId'] : '';
					if ( $bid ) {
						$wpdb->insert(
							$wpdb->prefix . 'wts_batch_queue',
							array(
								'batch_id'   => $bid,
								'batch_type' => 'update_price_stock',
								'status'     => 'pending',
								'item_count' => count( $chunk ),
							)
						);
					}
				} else {
					$stats['errors'] += count( $chunk );
				}
			}
		}

		// Cache'i toplu güncelle — UI bir sonraki yüklenişte taze veriyi göstersin
		foreach ( $cache_updates as $bar => $fields ) {
			WTS_Products::update_ty_cache_row( $bar, $fields );
		}

		WTS_Logger::success(
			'stock_sync',
			"Stok senkronu tamam: WP=+{$stats['wp_updated']}, TY=+{$stats['ty_updated']}, atlanan={$stats['skipped']}, hata={$stats['errors']}"
		);

		return array( 'success' => true, 'stats' => $stats );
	}

	/**
	 * Tek bir WP ürününün stok/fiyat değişikliğini Trendyol'a anında bildir.
	 *
	 * @return array ['success'=>bool, 'batch_id'=>string, 'error'=>string]
	 */
	public static function push_single_to_ty( $product_id, $also_price = false ) {
		global $wpdb;
		$result = array( 'success' => false, 'batch_id' => '', 'error' => '' );

		$map = WTS_Products::get_map_row( $product_id );
		if ( ! $map || 'synced' !== $map->status || ! $map->ty_barcode ) {
			$result['error'] = 'Ürün eşleştirilmemiş veya senkron değil.';
			return $result;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$result['error'] = 'WC ürünü bulunamadı.';
			return $result;
		}
		$qty = (int) $product->get_stock_quantity();

		$item = array(
			'barcode'  => $map->ty_barcode,
			'quantity' => $qty,
		);
		if ( $also_price ) {
			$price = WTS_Price::calculate( $product );
			if ( ! empty( $price['sale_price'] ) ) {
				$item['salePrice'] = floatval( $price['sale_price'] );
			}
			if ( ! empty( $price['list_price'] ) ) {
				$item['listPrice'] = floatval( $price['list_price'] );
			}
		}

		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			$result['error'] = 'API yapılandırılmamış.';
			return $result;
		}
		$resp = $api->update_price_and_inventory( array( $item ) );

		if ( ! $resp['success'] ) {
			$result['error'] = $resp['error'];
			return $result;
		}

		$bid = isset( $resp['data']['batchRequestId'] ) ? $resp['data']['batchRequestId'] : '';
		$result['success']  = true;
		$result['batch_id'] = $bid;

		// Batch'i kuyruğa yaz (sonra "Bekleyen Batch'leri Kontrol Et" ile sorgulanabilir)
		if ( $bid ) {
			$wpdb->insert(
				$wpdb->prefix . 'wts_batch_queue',
				array(
					'batch_id'   => $bid,
					'batch_type' => 'update_price_stock',
					'status'     => 'pending',
					'item_count' => 1,
				)
			);
		}

		$wpdb->update(
			$wpdb->prefix . 'wts_product_map',
			array(
				'last_wp_stock'  => $qty,
				'last_ty_stock'  => $qty,
				'last_synced_at' => current_time( 'mysql' ),
			),
			array( 'wp_post_id' => $product_id )
		);

		// Cache'i de güncelle — UI bir sonraki refreshte taze veriyi göstersin
		$cache_fields = array( 'quantity' => $qty );
		if ( isset( $item['salePrice'] ) ) {
			$cache_fields['sale_price'] = floatval( $item['salePrice'] );
		}
		if ( isset( $item['listPrice'] ) ) {
			$cache_fields['list_price'] = floatval( $item['listPrice'] );
		}
		WTS_Products::update_ty_cache_row( $map->ty_barcode, $cache_fields );

		// last_ty_price'ı da güncelle (cache değil mapping tablosu)
		if ( isset( $item['salePrice'] ) ) {
			$wpdb->update(
				$wpdb->prefix . 'wts_product_map',
				array( 'last_ty_price' => floatval( $item['salePrice'] ) ),
				array( 'wp_post_id' => $product_id )
			);
		}
		return $result;
	}

	/**
	 * Sadece batch'in anlık sonucunu kontrol et (poll). Trendyol fiyat batch'i
	 * genelde 5-30 saniyede hazır olur; bu yardımcı kullanıcıya hemen sonuç gösterir.
	 *
	 * @return array ['ready'=>bool, 'success_count'=>int, 'fail_count'=>int, 'errors'=>array<string>]
	 */
	public static function poll_batch_quick( $batch_id, $max_wait_seconds = 6 ) {
		$out = array( 'ready' => false, 'success_count' => 0, 'fail_count' => 0, 'errors' => array() );
		if ( ! $batch_id ) {
			return $out;
		}
		$api      = new WTS_API_Client();
		$start    = time();
		$attempts = 0;
		while ( ( time() - $start ) < (int) $max_wait_seconds && $attempts < 3 ) {
			$attempts++;
			$resp = $api->get_batch_request_result( $batch_id );
			if ( ! $resp['success'] ) {
				return $out;
			}
			$data   = $resp['data'] ?? array();
			$status = $data['status'] ?? '';
			$items  = $data['items'] ?? array();
			if ( 'COMPLETED' === strtoupper( (string) $status ) || ! empty( $items ) ) {
				$out['ready'] = true;
				foreach ( $items as $it ) {
					$st = strtoupper( (string) ( $it['status'] ?? '' ) );
					if ( 'SUCCESS' === $st ) {
						$out['success_count']++;
					} else {
						$out['fail_count']++;
						$msgs = isset( $it['failureReasons'] ) ? (array) $it['failureReasons'] : array();
						foreach ( $msgs as $m ) {
							$out['errors'][] = is_string( $m ) ? $m : wp_json_encode( $m );
						}
					}
				}
				return $out;
			}
			sleep( 2 );
		}
		return $out;
	}

	protected static function set_wp_stock( $product, $qty ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( max( 0, (int) $qty ) );
		$product->save();
	}

	/* ---------- WC hook'ları: WP'de stok/fiyat değişince Trendyol'a push ---------- */

	public static function init_hooks() {
		// Sipariş "processing" veya "completed" olduğunda zaten WC stok düşer; sonra bu hook tetiklenir
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_wp_stock_change' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_wp_stock_change' ), 20, 1 );

		// Fiyat değişimi — kaydedildiğinde tetikle. WC'nin "update_props" eventi
		// hem regular_price hem sale_price için tetiklenir.
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'on_wp_product_props_change' ), 20, 2 );
	}

	public static function on_wp_stock_change( $product ) {
		if ( ! is_object( $product ) ) {
			return;
		}
		// Sonsuz döngüyü engelle: senkron sırasında set ediyorsak skip
		if ( defined( 'WTS_SYNC_IN_PROGRESS' ) && WTS_SYNC_IN_PROGRESS ) {
			return;
		}
		$pid = $product->get_id();
		// Asenkron yap: action scheduler yoksa wp_cron'a tek atışlık. Stok değişimde
		// fiyatı da gönder (kullanıcı her şeyin tek hookta senkronlanmasını istiyor).
		if ( ! wp_next_scheduled( 'wts_push_single_full', array( $pid ) ) ) {
			wp_schedule_single_event( time() + 5, 'wts_push_single_full', array( $pid ) );
		}
	}

	/**
	 * WC ürün prop'ları değiştiğinde tetiklenir. Sadece fiyat alanları değiştiyse
	 * tam push tetikle (stok'unki zaten on_wp_stock_change ile gelir).
	 */
	public static function on_wp_product_props_change( $product, $updated_props ) {
		if ( ! is_object( $product ) || empty( $updated_props ) ) {
			return;
		}
		if ( defined( 'WTS_SYNC_IN_PROGRESS' ) && WTS_SYNC_IN_PROGRESS ) {
			return;
		}
		$price_props = array( 'price', 'regular_price', 'sale_price' );
		$changed     = array_intersect( $price_props, (array) $updated_props );
		if ( empty( $changed ) ) {
			return;
		}
		$pid = $product->get_id();
		if ( ! wp_next_scheduled( 'wts_push_single_full', array( $pid ) ) ) {
			wp_schedule_single_event( time() + 5, 'wts_push_single_full', array( $pid ) );
		}
	}
}

// Hook callback — eski adı geriye uyum için bırakıyoruz ama yeni 'full' hook hem stok hem fiyat push'lar.
add_action( 'wts_push_single_stock', function ( $product_id ) {
	WTS_Stock_Sync::push_single_to_ty( $product_id, false );
} );
add_action( 'wts_push_single_full', function ( $product_id ) {
	WTS_Stock_Sync::push_single_to_ty( $product_id, true );
} );
