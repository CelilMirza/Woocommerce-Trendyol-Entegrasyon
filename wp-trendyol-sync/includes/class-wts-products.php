<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Ürün eşleştirme + push/pull işlemleri.
 *
 *  - WP ↔ Trendyol ürün eşleştirme tablosunu kurar (SKU/barkod/isim).
 *  - WP ürünlerini Trendyol payload'una çevirir.
 *  - createProducts (v2) ile push.
 *  - filterProducts ile pull (Trendyol → WP eşleştirme).
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Products {

	/* ---------- Eşleştirme ---------- */

	/**
	 * Tüm WP ürünleri için map satırlarını kurar; mevcut Trendyol ürünleriyle eşleştirir.
	 *
	 * Akış:
	 *  1. Trendyol'dan onaylı tüm ürünleri çek (filterProducts, sayfalı).
	 *  2. WP ürünleri için SKU = barcode eşleşmesi dene.
	 *  3. Eşleşmeyenler için isim benzerliği dene (opsiyonel).
	 *
	 * @param bool $only_new  true ise sadece map'te olmayan WP ürünlerini işle.
	 * @return array stats
	 */
	public static function build_mapping_table( $only_new = false ) {
		global $wpdb;
		$method = WTS_Settings::get( 'auto_match_products', 'sku' );
		$tbl    = $wpdb->prefix . 'wts_product_map';

		// 1) Trendyol ürün cache'ini barkod indeksi olarak yükle.
		//    Cache boşsa API'ye düş (geriye uyum).
		$ty_index = self::load_ty_index_from_cache();
		if ( empty( $ty_index ) ) {
			$ty_index = self::pull_all_ty_products();
		}

		// 2) WP ürünlerini iterate et
		$wp_ids = self::get_all_wp_product_ids();

		$stats = array(
			'matched_sku' => 0,
			'matched_name'=> 0,
			'unmatched'   => 0,
			'total_wp'    => count( $wp_ids ),
			'total_ty'    => count( $ty_index ),
		);

		foreach ( $wp_ids as $pid ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$tbl} WHERE wp_post_id = %d",
				$pid
			) );

			if ( $only_new && $existing ) {
				continue;
			}

			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}

			$sku     = trim( (string) $product->get_sku() );
			$name    = $product->get_name();
			$barcode = $sku ? $sku : ''; // WP SKU = Trendyol barcode

			$ty_match  = null;
			$matched_via = '';

			if ( in_array( $method, array( 'sku', 'both' ), true ) && $barcode && isset( $ty_index[ $barcode ] ) ) {
				$ty_match    = $ty_index[ $barcode ];
				$matched_via = 'sku';
			}
			if ( ! $ty_match && in_array( $method, array( 'name', 'both' ), true ) && $name ) {
				$norm_name = WTS_Categories::normalize( $name );
				foreach ( $ty_index as $ty_b => $ty ) {
					if ( WTS_Categories::normalize( $ty['title'] ?? '' ) === $norm_name ) {
						$ty_match    = $ty;
						$matched_via = 'name';
						break;
					}
				}
			}

			if ( $ty_match ) {
				$data = array(
					'wp_post_id'    => $pid,
					'ty_barcode'    => $ty_match['barcode'] ?: $barcode,
					'ty_product_id' => isset( $ty_match['productId'] )    ? (string) $ty_match['productId']    : null,
					'ty_content_id' => isset( $ty_match['productContentId'] ) ? (int) $ty_match['productContentId'] : null,
					'match_type'    => $matched_via,
					'status'        => 'synced',
					'last_synced_at'=> current_time( 'mysql' ),
					'last_ty_stock' => isset( $ty_match['quantity'] ) ? (int) $ty_match['quantity'] : null,
					'last_ty_price' => isset( $ty_match['salePrice'] ) ? floatval( $ty_match['salePrice'] ) : null,
				);
				if ( $existing ) {
					$wpdb->update( $tbl, $data, array( 'id' => $existing->id ) );
				} else {
					$wpdb->insert( $tbl, $data );
				}
				if ( 'sku' === $matched_via ) {
					$stats['matched_sku']++;
				} else {
					$stats['matched_name']++;
				}
			} else {
				// Eşleşmedi
				if ( ! $existing ) {
					$wpdb->insert( $tbl, array(
						'wp_post_id' => $pid,
						'ty_barcode' => $barcode ? $barcode : 'WP_' . $pid,
						'match_type' => 'unmatched',
						'status'     => 'pending',
					) );
				}
				$stats['unmatched']++;
			}
		}

		WTS_Logger::success(
			'product_mapping',
			"Ürün eşleştirme: SKU {$stats['matched_sku']}, ad {$stats['matched_name']}, eşleşmemiş {$stats['unmatched']}"
		);

		return $stats;
	}

	protected static function get_all_wp_product_ids() {
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		return $ids ? $ids : array();
	}

	/**
	 * TY ürün cache tablosunu barkod indexli array'e çevir
	 * (build_mapping_table'ın beklediği yapıya benzer).
	 */
	protected static function load_ty_index_from_cache() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT ty_barcode, ty_product_id, ty_content_id, title, quantity, sale_price
			 FROM {$wpdb->prefix}wts_ty_product_cache"
		);
		$idx = array();
		foreach ( $rows as $r ) {
			$idx[ $r->ty_barcode ] = array(
				'barcode'          => $r->ty_barcode,
				'productId'        => $r->ty_product_id,
				'productContentId' => (int) $r->ty_content_id,
				'title'            => (string) $r->title,
				'quantity'         => (int) $r->quantity,
				'salePrice'        => floatval( $r->sale_price ),
			);
		}
		return $idx;
	}

	/**
	 * Trendyol'daki tüm onaylı ürünleri (barkod => ürün) olarak indexle.
	 */
	public static function pull_all_ty_products() {
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array();
		}

		$index = array();
		$page  = 0;
		$size  = 200;
		$guard = 0;

		while ( $guard++ < 500 ) {
			$resp = $api->filter_products( array(
				'page'     => $page,
				'size'     => $size,
				'approved' => 'true',
			) );
			if ( ! $resp['success'] ) {
				break;
			}
			$content = isset( $resp['data']['content'] ) ? $resp['data']['content'] : array();
			if ( empty( $content ) ) {
				break;
			}
			foreach ( $content as $item ) {
				$barcode = isset( $item['barcode'] ) ? (string) $item['barcode'] : '';
				if ( $barcode ) {
					$index[ $barcode ] = $item;
				}
			}
			if ( count( $content ) < $size ) {
				break;
			}
			$page++;
		}
		return $index;
	}

	/* ---------- WP → Trendyol Payload (V2) ---------- */

	/**
	 * Bir WP ürünü için createProducts V2 payload item dizisi üret.
	 *
	 * Variable ürünler için her varyant ayrı bir item olur (aynı productMainId ile gruplanır).
	 * Simple ürünler tek item döner.
	 *
	 * @param int $product_id
	 * @return array {
	 *   success: bool,
	 *   items: array  Trendyol item array'leri (1+ adet)
	 *   parent_sku: string  productMainId değeri
	 *   error: string
	 * }
	 */
	public static function build_create_payload( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return self::payload_error( 'Ürün bulunamadı.' );
		}

		// Variable ürünse children'a recurse et
		if ( $product->is_type( 'variable' ) ) {
			return self::build_variable_payload( $product );
		}

		// Simple veya variation
		$build = self::build_single_item( $product, null );
		if ( ! $build['success'] ) {
			return self::payload_error( $build['error'] );
		}
		return array(
			'success'    => true,
			'items'      => array( $build['item'] ),
			'parent_sku' => $build['item']['productMainId'],
			'error'      => '',
		);
	}

	/**
	 * Variable ürün → tüm varyantlar için tek bir items[] dizisi üret.
	 */
	protected static function build_variable_payload( WC_Product_Variable $parent ) {
		$child_ids = $parent->get_children();
		if ( empty( $child_ids ) ) {
			return self::payload_error( 'Varyantlı ürünün varyantı yok. Önce varyantları oluştur.' );
		}

		$parent_sku = self::resolve_product_main_id( $parent );
		if ( '' === $parent_sku ) {
			return self::payload_error( 'Üst ürünün SKU ya da ID üzerinden productMainId üretilemedi.' );
		}

		$items   = array();
		$errors  = array();
		foreach ( $child_ids as $cid ) {
			$variation = wc_get_product( $cid );
			if ( ! $variation || ! $variation->variation_is_active() ) {
				continue;
			}
			$build = self::build_single_item( $variation, $parent );
			if ( $build['success'] ) {
				// Tüm varyantlar aynı productMainId ile gruplansın
				$build['item']['productMainId'] = mb_substr( $parent_sku, 0, 40 );
				$items[] = $build['item'];
			} else {
				$errors[] = "Varyant #{$cid}: " . $build['error'];
			}
		}

		if ( empty( $items ) ) {
			return self::payload_error( 'Hiçbir varyant gönderilemedi: ' . implode( ' | ', $errors ) );
		}

		$resp = array(
			'success'    => true,
			'items'      => $items,
			'parent_sku' => $parent_sku,
			'error'      => '',
		);
		if ( ! empty( $errors ) ) {
			$resp['warning'] = implode( ' | ', $errors );
		}
		return $resp;
	}

	/**
	 * Tek bir WC_Product (simple veya variation) için Trendyol V2 item'ı üret.
	 *
	 * @param WC_Product       $product
	 * @param WC_Product|null  $parent  Variation ise üst ürün; aksi halde null
	 * @return array ['success'=>bool, 'item'=>array|null, 'error'=>string]
	 */
	protected static function build_single_item( $product, $parent = null ) {
		$ref_for_meta = $parent ? $parent : $product;

		// 1) Barkod (zorunlu, max 40 char). WP SKU → Trendyol barcode + stockCode.
		$sku = trim( (string) $product->get_sku() );
		if ( '' === $sku && $product instanceof WC_Product_Variation ) {
			// Varyant SKU'su boşsa parent SKU'su + variation_id kullan
			$parent_sku = $parent ? trim( (string) $parent->get_sku() ) : '';
			$sku = $parent_sku ? ( $parent_sku . '-' . $product->get_id() ) : ( 'WP-' . $product->get_id() );
		}
		if ( '' === $sku ) {
			return array( 'success' => false, 'item' => null, 'error' => 'Ürünün SKU/barkodu yok.' );
		}
		$sku = self::sanitize_barcode( $sku );

		// 2) Kategori — ana ürün kategorisinden çöz
		$ty_cat_id = WTS_Categories::resolve_ty_category_for_product( $ref_for_meta->get_id() );
		if ( ! $ty_cat_id ) {
			return array( 'success' => false, 'item' => null, 'error' => 'Bu ürünün kategorisi Trendyol\'a eşleştirilmemiş. Kategori Eşleştirme sayfasından eşleyin.' );
		}

		// 3) Marka
		$brand_id = WTS_Brands::resolve_ty_brand_for_product( $ref_for_meta );
		if ( ! $brand_id ) {
			$brand_name = self::get_product_brand_name( $ref_for_meta );
			if ( $brand_name ) {
				return array( 'success' => false, 'item' => null, 'error' => "Marka '{$brand_name}' Trendyol'a eşleştirilmemiş. Marka Eşleştirme sayfasından eşleyin." );
			}
			return array( 'success' => false, 'item' => null, 'error' => 'Ürünün markası yok ve varsayılan marka tanımlı değil. Ayarlar > Varsayılan Marka ID veya Marka Eşleştirme sayfasını kullan.' );
		}

		// 4) Fiyat
		$price = WTS_Price::calculate( $product );
		if ( $price['error'] || ! $price['sale_price'] ) {
			return array( 'success' => false, 'item' => null, 'error' => $price['error'] ?: 'Fiyat hesaplanamadı.' );
		}
		// listPrice >= salePrice kuralı
		$list_price = ! empty( $price['list_price'] ) ? floatval( $price['list_price'] ) : floatval( $price['sale_price'] );
		$sale_price = floatval( $price['sale_price'] );
		if ( $list_price < $sale_price ) {
			$list_price = $sale_price;
		}

		// 5) Görseller — variation'da kendi görseli yoksa parent'tan al
		$images = self::collect_product_images( $product );
		if ( empty( $images ) && $parent ) {
			$images = self::collect_product_images( $parent );
		}
		if ( empty( $images ) ) {
			$pid_for_msg = $product instanceof WC_Product_Variation
				? $product->get_parent_id()
				: $product->get_id();
			return array(
				'success' => false,
				'item'    => null,
				'error'   => "Ürün için geçerli görsel bulunamadı. Featured image (öne çıkan görsel) ekleyin: post.php?post={$pid_for_msg}. Trendyol HTTPS URL ve .jpg/.png/.webp uzantısı şart.",
			);
		}

		// 6) Stok
		$stock_qty = (int) $product->get_stock_quantity();
		if ( $stock_qty < 0 ) {
			$stock_qty = 0;
		}

		// 7) productMainId (variant gruplama anahtarı)
		$product_main_id = $parent
			? self::resolve_product_main_id( $parent )
			: self::resolve_product_main_id( $product );
		if ( '' === $product_main_id ) {
			$product_main_id = $sku;
		}

		// 8) Desi (dimensionalWeight)
		$dim_weight = self::calculate_dimensional_weight( $product, $parent );

		// 9) Açıklama — HTML/shortcode/FOX kalıntıları temizlenir; izin verilen basic HTML korunur
		$raw_desc = $ref_for_meta->get_description();
		$desc     = self::clean_description_html( $raw_desc );

		if ( '' === $desc ) {
			$short = $ref_for_meta->get_short_description();
			$desc  = self::clean_description_html( $short );
		}

		// Trendyol description ZORUNLU. Boş veya çok kısa kalırsa anlamlı bir
		// minimum açıklama üretiriz (title + SKU + marka + kategori adı).
		$used_fallback = false;
		if ( '' === $desc || mb_strlen( wp_strip_all_tags( $desc ) ) < 20 ) {
			$used_fallback = true;
			$desc = self::build_fallback_description( $ref_for_meta, $sku, $ty_cat_id );
		}

		// Yine de boş kaldıysa (imkansız ama emniyet için) ürün adı + ID
		if ( '' === trim( $desc ) ) {
			$desc = '<p>' . esc_html( self::clean_title( $ref_for_meta->get_name() ) ?: ( 'Ürün #' . $ref_for_meta->get_id() ) ) . '</p>';
		}

		if ( $used_fallback ) {
			WTS_Logger::warning(
				'description_fallback',
				"Ürün #{$ref_for_meta->get_id()} ({$sku}) için WP'de açıklama boş veya çok kısa. Otomatik fallback açıklama üretildi — Trendyol'da göründüğünde ürünün description'ını güncellemen önerilir.",
				array( 'wp_post_id' => $ref_for_meta->get_id(), 'ty_barcode' => $sku )
			);
		}

		// 10) Attribute'lar — V2 formatı (attributeValueId VEYA customAttributeValue)
		$attr_resp  = WTS_Categories::fetch_attributes( $ty_cat_id );
		$attributes = array();
		$missing_required = array();
		if ( $attr_resp['success'] && ! empty( $attr_resp['data']['categoryAttributes'] ) ) {
			$attributes = self::auto_map_attributes( $product, $parent, $attr_resp['data']['categoryAttributes'], $ty_cat_id );

			// Zorunlu olup hâlâ map'lenmemiş attribute'ları topla → uyarı
			$mapped_ids = array_column( $attributes, 'attributeId' );
			foreach ( $attr_resp['data']['categoryAttributes'] as $ca ) {
				if ( empty( $ca['required'] ) || empty( $ca['attribute']['id'] ) ) continue;
				$aid = (int) $ca['attribute']['id'];
				if ( ! in_array( $aid, $mapped_ids, true ) ) {
					$missing_required[] = isset( $ca['attribute']['name'] ) ? $ca['attribute']['name'] : ('ID:' . $aid);
				}
			}
		}
		if ( ! empty( $missing_required ) ) {
			WTS_Logger::warning(
				'attribute_missing',
				"Ürün #{$ref_for_meta->get_id()} ({$sku}) için Trendyol kategorisindeki ZORUNLU attribute'lar eksik: " . implode( ', ', $missing_required ) . ". Trendyol ürünü reddedebilir.",
				array( 'wp_post_id' => $ref_for_meta->get_id(), 'ty_barcode' => $sku )
			);
		}

		// 11) Ayarlardan gelen varsayılanlar
		$s = wts_settings();
		$ship_addr  = (int) WTS_Addresses::default_shipment_id();
		$ret_addr   = (int) WTS_Addresses::default_returning_id();
		$delivery_d = isset( $s['default_delivery_duration'] ) ? (int) $s['default_delivery_duration'] : 0;

		// Title da HTML/shortcode'dan temizlensin
		$clean_title = self::clean_title( $ref_for_meta->get_name() );
		if ( '' === $clean_title ) {
			$clean_title = 'Ürün #' . $ref_for_meta->get_id();
		}

		// Item gövdesi (V2)
		$item = array(
			'barcode'           => mb_substr( $sku, 0, 40 ),
			'title'             => mb_substr( $clean_title, 0, 100 ),
			'productMainId'     => mb_substr( $product_main_id, 0, 40 ),
			'brandId'           => (int) $brand_id,
			'categoryId'        => (int) $ty_cat_id,
			'quantity'          => $stock_qty,
			'stockCode'         => mb_substr( $sku, 0, 100 ),
			'dimensionalWeight' => $dim_weight,
			'description'       => mb_substr( $desc, 0, 30000 ),
			'currencyType'      => 'TRY',
			'listPrice'         => round( $list_price, 2 ),
			'salePrice'         => round( $sale_price, 2 ),
			'vatRate'           => self::resolve_vat_rate( $product ),
			'images'            => array_map( function ( $url ) { return array( 'url' => $url ); }, $images ),
			'attributes'        => $attributes,
		);

		// Opsiyoneller — varsa ekle (Trendyol 0 değerini reddedebilir)
		if ( $ship_addr > 0 ) {
			$item['shipmentAddressId'] = $ship_addr;
		}
		if ( $ret_addr > 0 ) {
			$item['returningAddressId'] = $ret_addr;
		}
		if ( $delivery_d > 0 ) {
			$item['deliveryOption'] = array( 'deliveryDuration' => $delivery_d );
		}
		if ( ! empty( $s['default_cargo_company_id'] ) ) {
			// V2'de bu alan resmi şemada yok ama Trendyol V1 ile uyumlu olarak çoğu mağaza için
			// hâlâ geçerli; ayar açıksa ekliyoruz. Boş bırakılırsa hiç gönderilmez.
			$item['cargoCompanyId'] = (int) $s['default_cargo_company_id'];
		}
		if ( ! empty( $s['origin_code'] ) ) {
			$item['origin'] = strtoupper( mb_substr( $s['origin_code'], 0, 2 ) );
		}

		return array( 'success' => true, 'item' => $item, 'error' => '' );
	}

	/**
	 * Barkod sanitize: Trendyol sadece nokta, tire, alt çizgi özel karakterlerine izin verir.
	 * Boşlukları kaldır, geçersiz karakterleri tire ile değiştir, baş/son ayraçları temizle.
	 *
	 * Örnek: "S220-24P4X(400W)" → "S220-24P4X-400W" (sondaki paranteze gelen tire kaldırılır)
	 */
	protected static function sanitize_barcode( $sku ) {
		$sku = trim( (string) $sku );
		// Boşlukları kaldır
		$sku = preg_replace( '/\s+/', '', $sku );
		// İzin verilen: harf, rakam, ., -, _
		$sku = preg_replace( '/[^\p{L}\p{N}\.\-_]/u', '-', $sku );
		// Ardarda gelen ayraçları teke indir (-- → -, .. → ., __ → _)
		$sku = preg_replace( '/([\-\._])\1+/u', '$1', $sku );
		// Baş/sondaki ayraçları kırp
		$sku = trim( $sku, '-._' );
		return mb_substr( $sku, 0, 40 );
	}

	/**
	 * productMainId stratejisine göre üret. Variantsız ürün için kendi SKU'su,
	 * variable ürün için parent SKU/ID. Boş döndürebilir.
	 */
	protected static function resolve_product_main_id( $product ) {
		$strategy = WTS_Settings::get( 'product_main_id_strategy', 'parent_sku' );
		switch ( $strategy ) {
			case 'parent_id':
				$id = $product instanceof WC_Product_Variation
					? $product->get_parent_id()
					: $product->get_id();
				return $id ? 'WP-' . $id : '';
			case 'sku':
				return self::sanitize_barcode( (string) $product->get_sku() );
			case 'parent_sku':
			default:
				$sku = trim( (string) $product->get_sku() );
				if ( '' === $sku && $product instanceof WC_Product_Variation ) {
					$parent = wc_get_product( $product->get_parent_id() );
					if ( $parent ) {
						$sku = (string) $parent->get_sku();
					}
				}
				if ( '' === $sku ) {
					$id = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
					$sku = 'WP-' . $id;
				}
				return self::sanitize_barcode( $sku );
		}
	}

	/**
	 * Desi/dimensionalWeight hesabı. Önce ürünün kendi boyutlarından
	 * ((B × E × Y) / 3000) cm³ ile dene; sonra ağırlık kg → desi varsay;
	 * son çare ayarlardaki default.
	 */
	protected static function calculate_dimensional_weight( $product, $parent = null ) {
		$pick = function ( $p, $method ) {
			if ( ! $p ) return 0.0;
			$v = $p->$method();
			return floatval( $v );
		};

		// 1) Boyutlar (cm) varsa hacim/3000 kuralı (Trendyol/MNG/Yurtiçi standardı)
		$L = $pick( $product, 'get_length' );
		$W = $pick( $product, 'get_width' );
		$H = $pick( $product, 'get_height' );
		if ( ! ( $L && $W && $H ) && $parent ) {
			$L = $L ?: $pick( $parent, 'get_length' );
			$W = $W ?: $pick( $parent, 'get_width' );
			$H = $H ?: $pick( $parent, 'get_height' );
		}
		if ( $L > 0 && $W > 0 && $H > 0 ) {
			$dim = ( $L * $W * $H ) / 3000.0;
			if ( $dim > 0 ) {
				return round( max( 0.1, $dim ), 2 );
			}
		}

		// 2) Ağırlık (kg) varsa, gerçek ağırlık desi'sini al
		$weight = $pick( $product, 'get_weight' );
		if ( ! $weight && $parent ) {
			$weight = $pick( $parent, 'get_weight' );
		}
		if ( $weight > 0 ) {
			// WC ağırlık birimi farklı olabilir; gram/kilogram ise yine kg'a çevir
			$unit = get_option( 'woocommerce_weight_unit', 'kg' );
			if ( 'g' === $unit )  $weight = $weight / 1000.0;
			if ( 'lbs' === $unit ) $weight = $weight * 0.4535924;
			if ( 'oz' === $unit )  $weight = $weight * 0.0283495;
			return round( max( 0.1, $weight ), 2 );
		}

		// 3) Ayar fallback
		$d = floatval( WTS_Settings::get( 'default_dimensional_weight', 1 ) );
		return $d > 0 ? round( $d, 2 ) : 1.0;
	}

	/**
	 * Trendyol vatRate: 0, 1, 10, 20 olmalı.
	 */
	protected static function resolve_vat_rate( $product ) {
		$v = (int) WTS_Settings::get( 'vat_rate', 20 );
		// Ürün düzeyinde tax_class özelse oradan da çekilebilir; şimdilik ayar.
		$allowed = array( 0, 1, 8, 10, 18, 20 );
		if ( ! in_array( $v, $allowed, true ) ) {
			// En yakın izinli orana yuvarla
			$v = 20;
		}
		return $v;
	}

	/**
	 * Description için izin verilen HTML — Trendyol'un kabul ettiği basic tag'ler.
	 *
	 * Akış (önemli):
	 *   1. WP shortcode'larını render et (woocommerce, woocs, vc_*, [vc_row] vs.).
	 *      Render edemediklerini sil ki Trendyol'da düz [shortcode] olarak kalmasın.
	 *   2. Bilinmiş çöp blokları (woocs price wrapper, vc_, style/script tag'leri) tamamen kaldır.
	 *   3. Sadece izin verilen tag'leri bırak (wp_kses).
	 *   4. HTML entity'lerini decode et (&amp; → &, &nbsp; → boşluk).
	 *   5. Çoklu boşluk/newline tek boşluğa indirgensin.
	 *   6. Boş kaldıysa boş döndür — caller fallback yapar.
	 */
	protected static function clean_description_html( $desc ) {
		$desc = (string) $desc;
		if ( '' === trim( $desc ) ) {
			return '';
		}

		// 1) Shortcode'ları çalıştır (render edebileceği gibi render etsin)
		if ( function_exists( 'do_shortcode' ) ) {
			$desc = do_shortcode( $desc );
		}
		// Render edilemeyen / hâlâ kalmış shortcode'ları sil
		if ( function_exists( 'strip_shortcodes' ) ) {
			$desc = strip_shortcodes( $desc );
		}
		// Süslü [tag] kalıntıları (strip_shortcodes registered olmayanı atlar)
		$desc = preg_replace( '/\[[^\]]{1,80}\]/u', '', $desc );

		// 2) Tehlikeli/çöp blokları kaldır
		$desc = preg_replace( '#<script[^>]*>.*?</script>#is', '', $desc );
		$desc = preg_replace( '#<style[^>]*>.*?</style>#is', '', $desc );
		// FOX/WOOCS fiyat wrapper'ı: <span class="woocs_price_code" data-...>...</span> — içeriğini tut, tag'i sil
		$desc = preg_replace( '#<span[^>]*class="[^"]*woocs[^"]*"[^>]*>(.*?)</span>#is', '$1', $desc );
		// WooCommerce price wrapper'ları
		$desc = preg_replace( '#<span[^>]*class="[^"]*woocommerce-Price-amount[^"]*"[^>]*>(.*?)</span>#is', '$1', $desc );
		$desc = preg_replace( '#<bdi[^>]*>(.*?)</bdi>#is', '$1', $desc );
		// Visual Composer / Elementor şablon kalıntıları
		$desc = preg_replace( '#<div[^>]*data-vc-[^>]*>(.*?)</div>#is', '$1', $desc );

		// 3) Sadece izin verilen tag'lere indir
		$allowed = array(
			'p'      => array(),
			'br'     => array(),
			'b'      => array(), 'strong' => array(),
			'i'      => array(), 'em'     => array(),
			'u'      => array(),
			'ul'     => array(), 'ol' => array(), 'li' => array(),
			'h2'     => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(),
			'table'  => array(), 'tr' => array(), 'td' => array(), 'th' => array(), 'thead' => array(), 'tbody' => array(),
		);
		$desc = wp_kses( $desc, $allowed );

		// 4) Entity decode
		$desc = html_entity_decode( $desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 5) Whitespace normalize — tag'lerin İÇİNE girmeden (kabaca yeterli)
		$desc = preg_replace( '/[\x{00A0}\s]+/u', ' ', $desc ); // \xA0 = &nbsp;
		$desc = preg_replace( '/\s+(<\/?(?:p|br|li|ul|ol|h2|h3|h4|h5|tr|td|th|table|thead|tbody)\b)/i', '$1', $desc );
		$desc = preg_replace( '/(<\/?(?:p|br|li|ul|ol|h2|h3|h4|h5|tr|td|th|table|thead|tbody)\b[^>]*>)\s+/i', '$1', $desc );
		// Ardarda boş <p></p>'leri kaldır
		$desc = preg_replace( '#<p>\s*</p>#i', '', $desc );

		return trim( $desc );
	}

	/**
	 * Title temizleme: HTML, shortcode, entity, fazla boşluk → düz metin.
	 */
	protected static function clean_title( $title ) {
		$title = (string) $title;
		if ( function_exists( 'do_shortcode' ) ) {
			$title = do_shortcode( $title );
		}
		if ( function_exists( 'strip_shortcodes' ) ) {
			$title = strip_shortcodes( $title );
		}
		$title = preg_replace( '/\[[^\]]{1,80}\]/u', '', $title );
		$title = wp_strip_all_tags( $title );
		$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = preg_replace( '/[\x{00A0}\s]+/u', ' ', $title );
		return trim( $title );
	}

	/**
	 * Açıklaması olmayan ürünler için anlamlı bir fallback açıklama üretir.
	 *
	 * Stratejisi: ürün adı + ürün kodu + (varsa) marka adı + kategori adı.
	 * Trendyol description boş kabul etmediği için min ~60 char garanti edilir.
	 *
	 * @param WC_Product $product
	 * @param string     $sku
	 * @param int        $ty_cat_id
	 * @return string  Basic HTML açıklama
	 */
	protected static function build_fallback_description( $product, $sku, $ty_cat_id ) {
		$title = self::clean_title( $product->get_name() ) ?: ( 'Ürün #' . $product->get_id() );

		// Marka adı (WP taxonomy'den)
		$brand_name = self::get_product_brand_name( $product );

		// Kategori adı (Trendyol cache'inden)
		$cat_name = '';
		if ( $ty_cat_id ) {
			global $wpdb;
			$cat_name = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}wts_category_cache WHERE ty_category_id = %d",
				(int) $ty_cat_id
			) );
		}

		$lines = array();
		$lines[] = '<p><strong>' . esc_html( $title ) . '</strong></p>';

		$details = array();
		if ( $sku ) {
			$details[] = 'Ürün Kodu: ' . esc_html( $sku );
		}
		if ( $brand_name ) {
			$details[] = 'Marka: ' . esc_html( $brand_name );
		}
		if ( $cat_name ) {
			$details[] = 'Kategori: ' . esc_html( $cat_name );
		}
		if ( $details ) {
			$lines[] = '<ul><li>' . implode( '</li><li>', $details ) . '</li></ul>';
		}

		// Ağırlık/boyut varsa onları da yaz
		$weight = floatval( $product->get_weight() );
		if ( $weight > 0 ) {
			$wunit = get_option( 'woocommerce_weight_unit', 'kg' );
			$lines[] = '<p>Ağırlık: ' . esc_html( $weight . ' ' . $wunit ) . '</p>';
		}

		$desc = implode( '', $lines );

		// Hâlâ kısa kaldıysa generic bir kapanış cümlesi ekle ki Trendyol min limit'i geçsin
		if ( mb_strlen( wp_strip_all_tags( $desc ) ) < 60 ) {
			$desc .= '<p>Orijinal ve garantili ürün. Hızlı kargo ile gönderilir.</p>';
		}
		return $desc;
	}

	protected static function payload_error( $msg ) {
		return array( 'success' => false, 'items' => array(), 'parent_sku' => '', 'error' => $msg );
	}

	protected static function get_product_brand_name( $product ) {
		// WCMP'nin tespit fonksiyonu varsa onu kullan
		if ( function_exists( 'wcmp_brand_taxonomy' ) ) {
			$tax = wcmp_brand_taxonomy();
		} else {
			$candidates = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand' );
			$tax = '';
			foreach ( $candidates as $c ) {
				if ( taxonomy_exists( $c ) ) { $tax = $c; break; }
			}
		}
		if ( ! $tax ) {
			return '';
		}
		$pid = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$terms = wp_get_post_terms( $pid, $tax, array( 'fields' => 'names' ) );
		return ! empty( $terms ) ? $terms[0] : '';
	}

	/**
	 * Ürünün featured image'ını ve gallery'sini toplar.
	 *
	 *  - Variation kendi görseli varsa onu en başa koyar (cover olarak).
	 *  - Sonra parent/simple ürünün featured image'ı.
	 *  - Sonra gallery (galeri sıralı).
	 *  - HTTP → HTTPS dönüşüm.
	 *  - Geçersiz uzantı/protokol olanları atar.
	 *  - Max 8 ile sınırlar.
	 *
	 * @param WC_Product $product
	 * @return string[]
	 */
	protected static function collect_product_images( $product ) {
		$urls = array();

		// 1) Variation kendi görseliyse onu öne al (Trendyol'da kapak resmi)
		if ( $product instanceof WC_Product_Variation ) {
			$var_img_id = $product->get_image_id();
			if ( $var_img_id ) {
				$u = wp_get_attachment_url( $var_img_id );
				if ( $u ) $urls[] = $u;
			}
		}

		// 2) Featured image — variation'sa parent'tan, simple'sa kendinden
		$pid_for_image = $product instanceof WC_Product_Variation
			? $product->get_parent_id()
			: $product->get_id();

		$thumb_id = get_post_thumbnail_id( $pid_for_image );
		if ( $thumb_id ) {
			$u = wp_get_attachment_url( $thumb_id );
			if ( $u ) $urls[] = $u;
		}

		// 3) Gallery
		$gallery_meta = get_post_meta( $pid_for_image, '_product_image_gallery', true );
		if ( $gallery_meta ) {
			$gallery_ids = array_filter( array_map( 'intval', explode( ',', $gallery_meta ) ) );
			foreach ( $gallery_ids as $gid ) {
				$u = wp_get_attachment_url( $gid );
				if ( $u ) $urls[] = $u;
			}
		}

		// 4) Normalize + filtrele
		$cleaned = array();
		foreach ( $urls as $u ) {
			if ( ! $u || ! is_string( $u ) ) continue;
			// HTTP → HTTPS
			$u = preg_replace( '#^http://#i', 'https://', $u );
			// Sadece https:// kabul et
			if ( ! preg_match( '#^https://#i', $u ) ) continue;
			// Geçerli görsel uzantısı (URL'de query string olabilir)
			$path = parse_url( $u, PHP_URL_PATH );
			if ( ! $path || ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $path ) ) {
				continue;
			}
			if ( ! in_array( $u, $cleaned, true ) ) {
				$cleaned[] = $u;
			}
		}

		return array_slice( $cleaned, 0, 8 );
	}

	/**
	 * Zorunlu attribute'ları WP attribute'larıyla otomatik eşle (V2 uyumlu).
	 *
	 * V2 farkı: attributeValues artık categoryAttributes içinde gelmiyor;
	 * her attribute için ayrı endpoint'ten (cache'lenmiş) değer listesini çekiyoruz.
	 *
	 * Variation ise WP'nin variation_attributes()'ını da dener.
	 *
	 * @param WC_Product      $product
	 * @param WC_Product|null $parent          Variation ise üst ürün
	 * @param array           $required_attrs  categoryAttributes dizisi
	 * @param int             $ty_category_id  Bu attributes hangi kategoriye ait
	 * @return array  Trendyol attribute item'ları
	 */
	protected static function auto_map_attributes( $product, $parent, $required_attrs, $ty_category_id ) {
		$out = array();

		// WP attribute'larını topla — variation ise hem variation hem parent
		$wp_attr_map = self::collect_wp_attribute_values( $product, $parent );

		// Kategori bazında tanımlanmış default'ları çek — WP'de yoksa buradan al
		$cat_defaults = WTS_Category_Attrs::get_defaults( $ty_category_id );

		foreach ( $required_attrs as $req ) {
			if ( empty( $req['attribute']['id'] ) ) {
				continue;
			}
			$attr_id      = (int) $req['attribute']['id'];
			$attr_name    = isset( $req['attribute']['name'] ) ? (string) $req['attribute']['name'] : '';
			$required     = ! empty( $req['required'] );
			$varianter    = ! empty( $req['varianter'] );
			$allow_custom = ! empty( $req['allowCustom'] );
			$allow_multi  = ! empty( $req['allowMultipleAttributeValues'] );

			// İlk olarak categoryAttributes içinde gelmiş eski-stil değer listesi
			$values_list = isset( $req['attributeValues'] ) && is_array( $req['attributeValues'] )
				? array_map( function ( $v ) {
					return array(
						'id'   => isset( $v['id'] ) ? (int) $v['id'] : 0,
						'name' => isset( $v['name'] ) ? (string) $v['name'] : '',
					);
				}, $req['attributeValues'] )
				: array();

			// V2'de boş gelmiş olabilir — yardımcı endpoint'ten çek (cache'li)
			if ( empty( $values_list ) ) {
				$values_list = WTS_Categories::fetch_attribute_values( $ty_category_id, $attr_id );
			}

			// 1) WP tarafındaki değeri bul (attribute_name -> value)
			$wp_val = self::find_wp_attr_value( $attr_name, $wp_attr_map );

			// 2) WP'de bulunmadıysa kategori default'una bak
			if ( '' === $wp_val && isset( $cat_defaults[ $attr_id ] ) ) {
				$def = $cat_defaults[ $attr_id ];
				$row = array( 'attributeId' => $attr_id );
				if ( ! empty( $def['attributeValueId'] ) ) {
					if ( $allow_multi ) {
						$row['attributeValueIds'] = array( (int) $def['attributeValueId'] );
					} else {
						$row['attributeValueId'] = (int) $def['attributeValueId'];
					}
					$out[] = $row;
					continue;
				}
				if ( ! empty( $def['customAttributeValue'] ) && $allow_custom ) {
					$row['customAttributeValue'] = mb_substr( (string) $def['customAttributeValue'], 0, 50 );
					$out[] = $row;
					continue;
				}
			}

			if ( '' === $wp_val ) {
				if ( $required && $allow_custom ) {
					$out[] = array(
						'attributeId'          => $attr_id,
						'customAttributeValue' => '-',
					);
				}
				continue;
			}

			// 3) WP değeri var → Trendyol value listesinde eşleşme dene
			$value_id = 0;
			foreach ( $values_list as $v ) {
				if ( isset( $v['name'] ) && WTS_Categories::normalize( $v['name'] ) === WTS_Categories::normalize( $wp_val ) ) {
					$value_id = (int) $v['id'];
					break;
				}
			}

			$row = array( 'attributeId' => $attr_id );
			if ( $value_id ) {
				if ( $allow_multi ) {
					$row['attributeValueIds'] = array( $value_id );
				} else {
					$row['attributeValueId'] = $value_id;
				}
			} elseif ( $allow_custom ) {
				$row['customAttributeValue'] = mb_substr( (string) $wp_val, 0, 50 );
			} else {
				// Eşleşmedi ve custom da kabul etmiyor → kategori default'una son şans
				if ( isset( $cat_defaults[ $attr_id ] ) ) {
					$def = $cat_defaults[ $attr_id ];
					if ( ! empty( $def['attributeValueId'] ) ) {
						if ( $allow_multi ) {
							$row['attributeValueIds'] = array( (int) $def['attributeValueId'] );
						} else {
							$row['attributeValueId'] = (int) $def['attributeValueId'];
						}
					} else {
						continue;
					}
				} else {
					continue;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * WC product (ve varsa parent) için attribute_name -> value haritası üret.
	 * Variation ise variation_attributes() (örn. attribute_pa_renk => 'kirmizi') öncelikli.
	 */
	protected static function collect_wp_attribute_values( $product, $parent = null ) {
		$map = array();

		// 1) Variation öncelikli
		if ( $product instanceof WC_Product_Variation ) {
			$va = $product->get_variation_attributes();
			if ( is_array( $va ) ) {
				foreach ( $va as $key => $val ) {
					if ( '' === $val ) continue;
					// attribute_pa_renk → renk; attribute_size → size
					$clean = preg_replace( '/^attribute_(pa_)?/', '', $key );
					$human = self::attribute_slug_to_label( $clean );
					// Slug değeri ise term name'e çevir
					$val_human = self::resolve_attribute_value_label( $clean, $val );
					$map[ $clean ]   = $val_human;
					$map[ $human ]   = $val_human;
				}
			}
		}

		// 2) Üst ürünün attribute'ları
		$source = $parent ? $parent : $product;
		$wp_attrs = $source->get_attributes();
		foreach ( $wp_attrs as $key => $attr ) {
			if ( ! is_object( $attr ) ) continue;
			$label_key = is_callable( array( $attr, 'get_name' ) ) ? $attr->get_name() : '';
			$slug_key  = preg_replace( '/^pa_/', '', $label_key );
			if ( $attr->is_taxonomy() ) {
				$opts = wc_get_product_terms( $source->get_id(), $label_key, array( 'fields' => 'names' ) );
				$val  = ! empty( $opts ) ? implode( ', ', $opts ) : '';
			} else {
				$opts = $attr->get_options();
				$val  = is_array( $opts ) ? implode( ', ', $opts ) : (string) $opts;
			}
			if ( '' !== $val ) {
				$human = self::attribute_slug_to_label( $slug_key );
				if ( ! isset( $map[ $slug_key ] ) ) $map[ $slug_key ] = $val;
				if ( ! isset( $map[ $human ] ) )    $map[ $human ]    = $val;
				if ( ! isset( $map[ $label_key ] ) ) $map[ $label_key ] = $val;
			}
		}

		return $map;
	}

	/**
	 * "renk" -> "Renk" gibi okunabilir label üret (taxonomy meta'sından).
	 */
	protected static function attribute_slug_to_label( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) return '';
		// Önce pa_ prefix'li global attribute'tan label çek
		$tax = 'pa_' . $slug;
		if ( taxonomy_exists( $tax ) ) {
			$tax_obj = wc_get_attribute( wc_attribute_taxonomy_id_by_name( $tax ) );
			if ( $tax_obj && ! empty( $tax_obj->name ) ) {
				return $tax_obj->name;
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Variation attribute değeri slug ise term'in görünen ismine çevir.
	 */
	protected static function resolve_attribute_value_label( $attr_slug, $val ) {
		$tax = 'pa_' . $attr_slug;
		if ( taxonomy_exists( $tax ) ) {
			$term = get_term_by( 'slug', $val, $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $val;
	}

	/**
	 * Trendyol attribute adı için WP haritasında karşılık ara.
	 * Normalize edilmiş eşleşme + bilinen takma adlar.
	 */
	protected static function find_wp_attr_value( $ty_attr_name, $wp_attr_map ) {
		if ( '' === $ty_attr_name || empty( $wp_attr_map ) ) {
			return '';
		}
		$target = WTS_Categories::normalize( $ty_attr_name );

		// Doğrudan
		foreach ( $wp_attr_map as $k => $v ) {
			if ( WTS_Categories::normalize( $k ) === $target ) {
				return $v;
			}
		}
		// Yaygın takma adlar
		$aliases = array(
			'renk'    => array( 'color', 'colour' ),
			'beden'   => array( 'size', 'boy' ),
			'boyut'   => array( 'size' ),
			'materyal'=> array( 'material', 'kumas', 'kumas turu' ),
			'cinsiyet'=> array( 'gender' ),
			'desen'   => array( 'pattern' ),
		);
		foreach ( $aliases as $main => $alt ) {
			if ( $target === $main ) {
				foreach ( $alt as $a ) {
					foreach ( $wp_attr_map as $k => $v ) {
						if ( WTS_Categories::normalize( $k ) === WTS_Categories::normalize( $a ) ) {
							return $v;
						}
					}
				}
			}
			if ( in_array( $target, array_map( array( 'WTS_Categories', 'normalize' ), $alt ), true ) ) {
				foreach ( $wp_attr_map as $k => $v ) {
					if ( WTS_Categories::normalize( $k ) === WTS_Categories::normalize( $main ) ) {
						return $v;
					}
				}
			}
		}
		return '';
	}

	/* ---------- Push (V2 + varyant destekli) ---------- */

	/**
	 * Birden fazla WP ürününü Trendyol'a gönder. Variable ürünler için tüm
	 * varyantlar aynı batch içine girer.
	 *
	 * @param int[] $wp_ids
	 * @return array
	 */
	public static function push_products( $wp_ids ) {
		$wp_ids = array_filter( array_map( 'intval', (array) $wp_ids ) );
		if ( empty( $wp_ids ) ) {
			return array( 'success' => false, 'error' => 'Gönderilecek ürün yok.' );
		}
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array( 'success' => false, 'error' => 'API yapılandırılmamış.' );
		}

		// Tüm item'ları topla. her wp_id → 1+ item olabilir (variable ürünler için).
		$all_items     = array();       // flat list, items dizisinde sırayla
		$item_to_pid   = array();       // index → wp_id (aynı index, $all_items'te)
		$skipped       = array();
		$warnings      = array();

		foreach ( $wp_ids as $pid ) {
			$build = self::build_create_payload( $pid );
			if ( ! $build['success'] ) {
				$skipped[ $pid ] = $build['error'];
				WTS_Logger::warning( 'product_push_skip', "Ürün #{$pid}: " . $build['error'], array(
					'wp_post_id' => $pid,
				) );
				continue;
			}
			if ( ! empty( $build['warning'] ) ) {
				$warnings[ $pid ] = $build['warning'];
			}
			foreach ( $build['items'] as $it ) {
				$all_items[]   = $it;
				$item_to_pid[] = $pid;
			}
		}

		if ( empty( $all_items ) ) {
			return array(
				'success' => false,
				'error'   => 'Tüm ürünler hatadan dolayı atlandı. İlk hata: ' . ( $skipped ? reset( $skipped ) : 'bilinmiyor' ),
				'skipped' => $skipped,
			);
		}

		// Trendyol tek seferde max 1000 item, biz 50'lik batch'ler yapıyoruz
		$chunks    = array_chunk( $all_items, 50 );
		$idx_off   = 0;
		$batch_ids = array();
		$api_errors = array(); // chunk indexli hata mesajları — push_single buradan tek hata mesajı çekecek
		global $wpdb;
		$pid_for_batch = array(); // batch_id → wp_id[] mapping

		foreach ( $chunks as $chunk_no => $chunk ) {
			$resp = $api->create_products( $chunk );

			if ( ! $resp['success'] ) {
				$err_msg = $resp['error'] ?: 'API yanıtı boş.';
				WTS_Logger::error( 'product_push', "Batch gönderim hatası: " . $err_msg, array(
					'payload' => array(
						'first_barcode' => isset( $chunk[0]['barcode'] ) ? $chunk[0]['barcode'] : '?',
						'http_code'     => isset( $resp['code'] ) ? $resp['code'] : 0,
						'response'      => $resp['data'],
					),
				) );
				// Bu chunk'taki tüm wp_id'lere bu hatayı ata, böylece UI'da gerçek mesaj görünür
				for ( $i = 0; $i < count( $chunk ); $i++ ) {
					$pid = isset( $item_to_pid[ $idx_off + $i ] ) ? $item_to_pid[ $idx_off + $i ] : 0;
					if ( $pid && ! isset( $skipped[ $pid ] ) ) {
						$skipped[ $pid ] = 'Trendyol reddetti: ' . mb_substr( $err_msg, 0, 300 );
					}
				}
				$api_errors[] = $err_msg;
				$idx_off += count( $chunk );
				continue;
			}
			$batch_id = isset( $resp['data']['batchRequestId'] ) ? $resp['data']['batchRequestId'] : '';
			if ( ! $batch_id ) {
				$api_errors[] = 'Trendyol batchRequestId döndürmedi: ' . wp_json_encode( $resp['data'] );
				$idx_off += count( $chunk );
				continue;
			}
			$batch_ids[] = $batch_id;

			// Bu chunk'taki item'ların WP ID'lerini topla
			$wp_ids_in_chunk = array();
			for ( $i = 0; $i < count( $chunk ); $i++ ) {
				$pid = isset( $item_to_pid[ $idx_off + $i ] ) ? $item_to_pid[ $idx_off + $i ] : 0;
				if ( $pid && ! in_array( $pid, $wp_ids_in_chunk, true ) ) {
					$wp_ids_in_chunk[] = $pid;
				}
			}
			$pid_for_batch[ $batch_id ] = $wp_ids_in_chunk;

			$wpdb->insert(
				$wpdb->prefix . 'wts_batch_queue',
				array(
					'batch_id'   => $batch_id,
					'batch_type' => 'create_products',
					'status'     => 'pending',
					'item_count' => count( $chunk ),
					'result'     => wp_json_encode( array( 'wp_ids' => $wp_ids_in_chunk ), JSON_UNESCAPED_UNICODE ),
				)
			);

			// Map satırlarını güncelle: her item için barkod -> wp_id eşleştirmesi
			for ( $i = 0; $i < count( $chunk ); $i++ ) {
				$pid     = isset( $item_to_pid[ $idx_off + $i ] ) ? $item_to_pid[ $idx_off + $i ] : 0;
				$barcode = isset( $chunk[ $i ]['barcode'] ) ? $chunk[ $i ]['barcode'] : '';
				if ( ! $pid || ! $barcode ) continue;

				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}wts_product_map WHERE wp_post_id = %d",
					$pid
				) );
				$data = array(
					'wp_post_id'    => $pid,
					'ty_barcode'    => $barcode,
					'match_type'    => 'manual',
					'status'        => 'syncing',
					'last_synced_at'=> current_time( 'mysql' ),
				);
				if ( $existing ) {
					$wpdb->update( $wpdb->prefix . 'wts_product_map', $data, array( 'id' => (int) $existing ) );
				} else {
					$wpdb->insert( $wpdb->prefix . 'wts_product_map', $data );
				}
			}

			$idx_off += count( $chunk );
		}

		return array(
			'success'   => ! empty( $batch_ids ),
			'batch_ids' => $batch_ids,
			'pushed'    => count( $all_items ),
			'products'  => count( $wp_ids ) - count( $skipped ),
			'skipped'   => $skipped,
			'warnings'  => $warnings,
			'error'     => empty( $batch_ids )
				? ( $api_errors ? implode( ' | ', array_unique( $api_errors ) ) : 'Hiçbir batch oluşturulamadı.' )
				: '',
		);
	}

	/* ---------- Batch sonuç kontrolü ---------- */

	/**
	 * Bekleyen tüm batch'leri kontrol et.
	 */
	public static function check_pending_batches() {
		global $wpdb;
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return 0;
		}

		$tbl = $wpdb->prefix . 'wts_batch_queue';
		$rows = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE status = 'pending' ORDER BY id ASC LIMIT 20" );
		$processed = 0;

		foreach ( $rows as $row ) {
			$resp = $api->get_batch_request_result( $row->batch_id );
			if ( ! $resp['success'] ) {
				continue;
			}
			$data   = $resp['data'];
			$status = isset( $data['status'] ) ? $data['status'] : '';
			$items  = isset( $data['items'] ) ? $data['items'] : array();
			$success = 0;
			$fail    = 0;

			foreach ( $items as $i ) {
				$st = isset( $i['status'] ) ? $i['status'] : '';
				if ( 'SUCCESS' === $st ) {
					$success++;
					// Map satırını güncelle
					if ( isset( $i['requestItem']['barcode'] ) ) {
						$wpdb->update(
							$wpdb->prefix . 'wts_product_map',
							array( 'status' => 'synced' ),
							array( 'ty_barcode' => $i['requestItem']['barcode'] )
						);
					}
				} else {
					$fail++;
					if ( isset( $i['requestItem']['barcode'] ) ) {
						$err = isset( $i['failureReasons'] ) ? wp_json_encode( $i['failureReasons'], JSON_UNESCAPED_UNICODE ) : 'Bilinmeyen hata';
						$wpdb->update(
							$wpdb->prefix . 'wts_product_map',
							array( 'status' => 'error', 'last_error' => $err ),
							array( 'ty_barcode' => $i['requestItem']['barcode'] )
						);
					}
				}
			}

			$wpdb->update( $tbl, array(
				'status'        => 'completed',
				'checked_at'    => current_time( 'mysql' ),
				'success_count' => $success,
				'fail_count'    => $fail,
				'result'        => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
			), array( 'id' => $row->id ) );

			WTS_Logger::info( 'batch_result',
				"Batch {$row->batch_id} sonuçlandı: {$success} başarılı, {$fail} hatalı.",
				array( 'batch_id' => $row->batch_id )
			);
			$processed++;
		}

		return $processed;
	}

	/* ---------- Pull (Trendyol → WP) ---------- */

	/**
	 * Trendyol'da olup WP'de olmayan ürünler için draft WP ürünü oluştur.
	 */
	public static function pull_new_to_wp() {
		$ty_index = self::pull_all_ty_products();
		$created  = 0;
		$img_total = 0;

		// Yeni ürün yaratırken stok hook'u tetiklenip Trendyol'a geri push olmasın
		if ( ! defined( 'WTS_SYNC_IN_PROGRESS' ) ) {
			define( 'WTS_SYNC_IN_PROGRESS', true );
		}

		foreach ( $ty_index as $barcode => $item ) {
			$wp_id = wc_get_product_id_by_sku( $barcode );
			if ( $wp_id ) {
				continue;
			}
			// Yeni draft ürün
			$product = new WC_Product_Simple();
			$product->set_name( $item['title'] ?? 'Trendyol Ürün' );
			$product->set_sku( $barcode );
			$product->set_status( 'draft' );
			$product->set_regular_price( isset( $item['salePrice'] ) ? (string) $item['salePrice'] : '' );
			$product->set_stock_quantity( isset( $item['quantity'] ) ? (int) $item['quantity'] : 0 );
			$product->set_manage_stock( true );
			$product->set_description( $item['description'] ?? '' );

			$wp_id = $product->save();

			if ( $wp_id ) {
				// Görselleri indir + featured/gallery olarak set et
				$images = isset( $item['images'] ) && is_array( $item['images'] ) ? $item['images'] : array();
				$img_total += self::import_product_images( $wp_id, $images );

				global $wpdb;
				$wpdb->replace(
					$wpdb->prefix . 'wts_product_map',
					array(
						'wp_post_id'    => $wp_id,
						'ty_barcode'    => $barcode,
						'ty_product_id' => isset( $item['productId'] ) ? (string) $item['productId'] : null,
						'ty_content_id' => isset( $item['productContentId'] ) ? (int) $item['productContentId'] : null,
						'match_type'    => 'pulled',
						'status'        => 'synced',
						'last_synced_at'=> current_time( 'mysql' ),
						'last_ty_stock' => isset( $item['quantity'] ) ? (int) $item['quantity'] : null,
						'last_ty_price' => isset( $item['salePrice'] ) ? floatval( $item['salePrice'] ) : null,
					)
				);
				$created++;
			}
		}

		WTS_Logger::success( 'product_pull', "{$created} yeni ürün çekildi, {$img_total} görsel eklendi." );
		return $created;
	}

	/**
	 * Daha önce çekilmiş ama görseli olmayan ürünler için Trendyol'dan
	 * görsel indirip featured + gallery olarak ekler.
	 *
	 * @return array ['updated'=>int, 'images'=>int]
	 */
	public static function backfill_pulled_images() {
		global $wpdb;
		$tbl  = $wpdb->prefix . 'wts_product_map';
		$rows = $wpdb->get_results( "SELECT wp_post_id, ty_barcode FROM {$tbl} WHERE ty_barcode IS NOT NULL AND ty_barcode != ''" );
		if ( empty( $rows ) ) {
			return array( 'updated' => 0, 'images' => 0 );
		}

		$ty_index = self::pull_all_ty_products();
		if ( empty( $ty_index ) ) {
			WTS_Logger::warning( 'image_backfill', 'Trendyol ürün indeksi boş döndü — görsel ekleme atlandı.' );
			return array( 'updated' => 0, 'images' => 0 );
		}

		if ( ! defined( 'WTS_SYNC_IN_PROGRESS' ) ) {
			define( 'WTS_SYNC_IN_PROGRESS', true );
		}

		$updated    = 0;
		$img_total  = 0;
		foreach ( $rows as $row ) {
			$wp_id = (int) $row->wp_post_id;
			$bc    = (string) $row->ty_barcode;
			if ( ! $wp_id || ! $bc || ! isset( $ty_index[ $bc ] ) ) {
				continue;
			}
			$product = wc_get_product( $wp_id );
			if ( ! $product ) {
				continue;
			}
			// Zaten featured görseli varsa atla
			if ( $product->get_image_id() ) {
				continue;
			}
			$images = isset( $ty_index[ $bc ]['images'] ) && is_array( $ty_index[ $bc ]['images'] ) ? $ty_index[ $bc ]['images'] : array();
			$n      = self::import_product_images( $wp_id, $images );
			if ( $n > 0 ) {
				$updated++;
				$img_total += $n;
			}
		}

		WTS_Logger::success( 'image_backfill', "{$updated} ürün için toplam {$img_total} görsel eklendi." );
		return array( 'updated' => $updated, 'images' => $img_total );
	}

	/**
	 * Verilen ürüne Trendyol images dizisindeki görselleri media library'ye
	 * sideload eder; ilkini featured image, kalanını gallery olarak set eder.
	 *
	 * @param int   $wp_id
	 * @param array $images  Trendyol formatı: [ ['url'=>'https://...'], ... ]
	 * @return int  Eklenen attachment sayısı.
	 */
	protected static function import_product_images( $wp_id, $images ) {
		if ( empty( $images ) || ! is_array( $images ) ) {
			return 0;
		}

		// WP media fonksiyonlarını yükle (front/admin fark etmeksizin gerekli)
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$product = wc_get_product( $wp_id );
		if ( ! $product ) {
			return 0;
		}

		$attach_ids = array();
		$count      = 0;

		foreach ( $images as $img ) {
			$url = '';
			if ( is_array( $img ) && ! empty( $img['url'] ) ) {
				$url = (string) $img['url'];
			} elseif ( is_string( $img ) ) {
				$url = $img;
			}
			if ( '' === $url ) {
				continue;
			}

			// Trendyol bazen sadece /Original/foo.jpg path'i döndürür → CDN host'u öneklendir
			if ( 0 === strpos( $url, '/' ) ) {
				$url = 'https://cdn.dsmcdn.com' . $url;
			}
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}

			// Aynı URL daha önce eklenmişse yeniden indirme
			$existing = get_posts( array(
				'post_type'   => 'attachment',
				'meta_key'    => '_wts_source_url',
				'meta_value'  => $url,
				'fields'      => 'ids',
				'numberposts' => 1,
			) );
			if ( ! empty( $existing ) ) {
				$attach_ids[] = (int) $existing[0];
				continue;
			}

			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				WTS_Logger::warning( 'image_import', "Görsel indirilemedi: {$url} - " . $tmp->get_error_message() );
				continue;
			}

			$basename = basename( parse_url( $url, PHP_URL_PATH ) );
			if ( ! $basename ) {
				$basename = 'trendyol-' . md5( $url );
			}
			if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $basename ) ) {
				$basename .= '.jpg';
			}
			$file_array = array(
				'name'     => $basename,
				'tmp_name' => $tmp,
			);

			$attach_id = media_handle_sideload( $file_array, $wp_id );
			if ( is_wp_error( $attach_id ) ) {
				@unlink( $tmp );
				WTS_Logger::warning( 'image_import', "Sideload başarısız: {$url} - " . $attach_id->get_error_message() );
				continue;
			}
			update_post_meta( $attach_id, '_wts_source_url', $url );
			$attach_ids[] = (int) $attach_id;
			$count++;
		}

		if ( ! empty( $attach_ids ) ) {
			$featured = array_shift( $attach_ids );
			$product->set_image_id( $featured );
			if ( ! empty( $attach_ids ) ) {
				$product->set_gallery_image_ids( $attach_ids );
			}
			$product->save();
		}

		return $count;
	}

	/* ---------- Helpers ---------- */

	public static function get_mapping_stats() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_product_map';
		return array(
			'total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ),
			'synced'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='synced'" ),
			'pending'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='pending'" ),
			'syncing'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='syncing'" ),
			'error'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status='error'" ),
			'unmatched'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE match_type='unmatched'" ),
		);
	}

	public static function get_map_row( $wp_post_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wts_product_map WHERE wp_post_id = %d",
			(int) $wp_post_id
		) );
	}

	/* =========================================================
	 * Trendyol ürün cache + manuel eşleştirme yardımcıları.
	 * Bu blok kullanıcının asıl ihtiyacı için: WP ürünlerini
	 * Trendyol'daki ürünlere bağlayıp stok senkronu yapmak.
	 * ========================================================= */

	/**
	 * Trendyol'daki onaylı ürünleri yerel cache tablosuna yazar.
	 * WP'ye HİÇBİR şey eklemez; sadece eşleştirme ve stok senkronu için referans.
	 *
	 * @return array ['success'=>bool, 'count'=>int, 'error'=>string]
	 */
	public static function sync_ty_cache() {
		global $wpdb;
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array( 'success' => false, 'count' => 0, 'error' => 'API yapılandırılmamış.' );
		}

		$tbl   = $wpdb->prefix . 'wts_ty_product_cache';
		$page  = 0;
		$size  = 200;
		$guard = 0;
		$count = 0;

		// Yeni sync başlarken eski cache'i temizle (silinmiş ürünler kalmasın)
		$wpdb->query( "TRUNCATE TABLE {$tbl}" );

		while ( $guard++ < 500 ) {
			$resp = $api->filter_products( array(
				'page'     => $page,
				'size'     => $size,
				'approved' => 'true',
			) );
			if ( ! $resp['success'] ) {
				return array( 'success' => false, 'count' => $count, 'error' => $resp['error'] );
			}
			$content = isset( $resp['data']['content'] ) ? $resp['data']['content'] : array();
			if ( empty( $content ) ) {
				break;
			}
			foreach ( $content as $item ) {
				$barcode = isset( $item['barcode'] ) ? (string) $item['barcode'] : '';
				if ( '' === $barcode ) {
					continue;
				}
				$wpdb->replace(
					$tbl,
					array(
						'ty_barcode'      => $barcode,
						'ty_product_id'   => isset( $item['productId'] )        ? (string) $item['productId']        : null,
						'ty_content_id'   => isset( $item['productContentId'] ) ? (int)    $item['productContentId'] : null,
						'title'           => isset( $item['title'] )            ? mb_substr( (string) $item['title'], 0, 500 ) : null,
						'product_main_id' => isset( $item['productMainId'] )    ? (string) $item['productMainId']    : null,
						'brand'           => isset( $item['brand'] )            ? (string) $item['brand']            : null,
						'category_name'   => isset( $item['categoryName'] )     ? (string) $item['categoryName']     : null,
						'quantity'        => isset( $item['quantity'] )         ? (int)    $item['quantity']         : null,
						'sale_price'      => isset( $item['salePrice'] )        ? floatval( $item['salePrice'] )     : null,
						'list_price'      => isset( $item['listPrice'] )        ? floatval( $item['listPrice'] )     : null,
						'approved'        => ! empty( $item['approved'] ) ? 1 : 1,
					)
				);
				$count++;
			}
			if ( count( $content ) < $size ) {
				break;
			}
			$page++;
		}

		update_option( 'wts_ty_cache_last_sync', current_time( 'mysql' ) );
		WTS_Logger::success( 'ty_cache_sync', "{$count} Trendyol ürünü cache'e yazıldı." );
		return array( 'success' => true, 'count' => $count, 'error' => '' );
	}

	public static function get_ty_cache_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wts_ty_product_cache" );
	}

	public static function get_ty_cache_last_sync() {
		return get_option( 'wts_ty_cache_last_sync', '' );
	}

	/**
	 * Cache'ten barkod/başlık ile arama (manuel eşleştirme UI için).
	 *
	 * @param string $q
	 * @param int    $limit
	 * @return array
	 */
	public static function search_ty_cache( $q, $limit = 20 ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_ty_product_cache';
		$q   = trim( (string) $q );
		if ( '' === $q ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT ty_barcode, title, product_main_id, brand, quantity, sale_price
			 FROM {$tbl}
			 WHERE ty_barcode LIKE %s OR title LIKE %s OR product_main_id LIKE %s
			 ORDER BY CHAR_LENGTH(title) ASC
			 LIMIT %d",
			$like, $like, $like, (int) $limit
		), ARRAY_A );
	}

	/**
	 * Cache'ten tek bir barkodun verisini getir.
	 */
	public static function get_ty_cache_row( $barcode ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wts_ty_product_cache WHERE ty_barcode = %s",
			(string) $barcode
		), ARRAY_A );
	}

	/**
	 * Tek bir cache satırının alanlarını günceller. Senkron sonrası "TY Mevcut"
	 * sütununun gerçekten son durumu göstermesi için kullanılır. Sadece verilen
	 * alanlar yazılır, diğerleri korunur.
	 *
	 * @param string $barcode
	 * @param array  $fields  ['quantity'=>int, 'sale_price'=>float, 'list_price'=>float, ...]
	 * @return bool
	 */
	public static function update_ty_cache_row( $barcode, $fields ) {
		global $wpdb;
		$barcode = trim( (string) $barcode );
		if ( '' === $barcode || empty( $fields ) || ! is_array( $fields ) ) {
			return false;
		}
		$tbl = $wpdb->prefix . 'wts_ty_product_cache';

		// İzin verilen alanlar
		$allowed = array( 'quantity', 'sale_price', 'list_price', 'title', 'brand', 'category_name', 'product_main_id', 'ty_product_id', 'ty_content_id', 'approved' );
		$data    = array();
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) {
				$data[ $k ] = $v;
			}
		}
		if ( empty( $data ) ) {
			return false;
		}

		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE ty_barcode = %s",
			$barcode
		) );

		if ( $exists ) {
			return false !== $wpdb->update( $tbl, $data, array( 'ty_barcode' => $barcode ) );
		}
		// Cache'te yoksa yeni satır oluştur (sync_all sırasında barkod biliniyor ama
		// cache henüz çekilmemişse).
		$data['ty_barcode'] = $barcode;
		return false !== $wpdb->insert( $tbl, $data );
	}

	/**
	 * Bir WP ürününü manuel olarak bir Trendyol barkoduna bağla.
	 * Stok senkronu artık bu ikilinin üzerinden çalışır.
	 *
	 * @param int    $wp_post_id
	 * @param string $ty_barcode
	 * @return array ['success'=>bool, 'error'=>string]
	 */
	public static function set_manual_match( $wp_post_id, $ty_barcode ) {
		global $wpdb;
		$wp_post_id = (int) $wp_post_id;
		$ty_barcode = trim( (string) $ty_barcode );
		if ( ! $wp_post_id || '' === $ty_barcode ) {
			return array( 'success' => false, 'error' => 'Eksik bilgi.' );
		}
		$ty = self::get_ty_cache_row( $ty_barcode );
		if ( ! $ty ) {
			return array( 'success' => false, 'error' => "Trendyol cache'inde bu barkod yok: {$ty_barcode}. Önce 'Trendyol Ürünlerini Çek' butonuna bas." );
		}
		// Aynı barkod başka bir WP ürününe bağlıysa o eşleştirmeyi temizle (uniq_barcode constraint)
		$wpdb->update(
			$wpdb->prefix . 'wts_product_map',
			array( 'ty_barcode' => 'WP_' . $wp_post_id . '_old_' . time(), 'match_type' => 'unmatched', 'status' => 'pending' ),
			array( 'ty_barcode' => $ty_barcode )
		);
		// Map satırı yaz / güncelle
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}wts_product_map WHERE wp_post_id = %d",
			$wp_post_id
		) );
		$data = array(
			'wp_post_id'    => $wp_post_id,
			'ty_barcode'    => $ty_barcode,
			'ty_product_id' => isset( $ty['ty_product_id'] ) ? $ty['ty_product_id'] : null,
			'ty_content_id' => isset( $ty['ty_content_id'] ) ? (int) $ty['ty_content_id'] : null,
			'match_type'    => 'manual',
			'status'        => 'synced',
			'last_synced_at'=> current_time( 'mysql' ),
			'last_ty_stock' => isset( $ty['quantity'] ) ? (int) $ty['quantity'] : null,
			'last_ty_price' => isset( $ty['sale_price'] ) ? floatval( $ty['sale_price'] ) : null,
		);
		if ( $existing ) {
			$wpdb->update( $wpdb->prefix . 'wts_product_map', $data, array( 'id' => (int) $existing ) );
		} else {
			$wpdb->insert( $wpdb->prefix . 'wts_product_map', $data );
		}

		WTS_Logger::success( 'manual_match', "WP #{$wp_post_id} → TY barkod {$ty_barcode} bağlandı." );
		return array( 'success' => true, 'error' => '' );
	}

	/**
	 * Bir WP ürünündeki eşleştirmeyi tamamen kaldır.
	 */
	public static function clear_match( $wp_post_id ) {
		global $wpdb;
		$wp_post_id = (int) $wp_post_id;
		return false !== $wpdb->update(
			$wpdb->prefix . 'wts_product_map',
			array(
				'ty_barcode'    => 'WP_' . $wp_post_id,
				'ty_product_id' => null,
				'ty_content_id' => null,
				'match_type'    => 'unmatched',
				'status'        => 'pending',
				'last_ty_stock' => null,
				'last_ty_price' => null,
			),
			array( 'wp_post_id' => $wp_post_id )
		);
	}
}
