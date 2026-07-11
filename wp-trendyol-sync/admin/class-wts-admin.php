<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Admin menü ve sayfaları (TAM SÜRÜM).
 *
 *  - Dashboard (kpi'lar + hızlı aksiyonlar)
 *  - Ayarlar
 *  - Fiyat Önizleme
 *  - Kategori Eşleştirme
 *  - Ürünler (eşleşme + push/pull)
 *  - Senkron & Cron
 *  - Satış Raporu
 *  - Loglar
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Admin {

	const CAPABILITY = 'manage_woocommerce';
	const SLUG       = 'wts';

	public static function init() {
		add_action( 'admin_menu',  array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_wts_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Digitalog marka / iletişim banner'ı.
	 * Her admin sayfasının en üstünde gösterilir.
	 *
	 * Geliştirici: Digitalog — https://digitalog.com.tr
	 */
	public static function digitalog_banner() {
		?>
		<div class="wts-digitalog-banner">
			<div class="wts-digitalog-inner">
				<div class="wts-digitalog-brand">
					<span class="dashicons dashicons-update"></span>
					<span class="wts-digitalog-name">Digitalog</span>
				</div>
				<div class="wts-digitalog-text">
					Bu eklenti <strong>Digitalog</strong> tarafından geliştirilmiştir.
					İletişim ve özel yazılım çözümleri için:
					<a href="https://digitalog.com.tr" target="_blank" rel="noopener">digitalog.com.tr</a>
				</div>
				<a class="wts-digitalog-cta" href="https://digitalog.com.tr" target="_blank" rel="noopener">digitalog.com.tr →</a>
			</div>
		</div>
		<?php
	}

	public static function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'wts' ) ) {
			return;
		}
		// Chart.js CDN
		wp_enqueue_script( 'wts-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
		// Dashicons icons
		wp_enqueue_style( 'dashicons' );
		// Our admin styles
		wp_enqueue_style(
			'wts-admin',
			plugins_url( 'admin/css/wts-admin.css', WTS_FILE ),
			array(),
			WTS_VERSION
		);
	}

	/* --------- Reusable UI helpers --------- */

	/**
	 * Sayfalama linkleri çıktısı.
	 *
	 * @param int    $total       Toplam item sayısı
	 * @param int    $per_page    Sayfa başı
	 * @param int    $current     Şu anki sayfa
	 * @param array  $base_args   Bu sayfaya GET ile geçilen ek argümanlar (page, filter, s vs.)
	 */
	public static function paginate( $total, $per_page, $current, $base_args = array() ) {
		$total      = max( 0, (int) $total );
		$per_page   = max( 1, (int) $per_page );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$current    = max( 1, min( $total_pages, (int) $current ) );
		if ( $total_pages <= 1 ) {
			return;
		}
		$build = function ( $p ) use ( $base_args ) {
			$base_args['paged'] = (int) $p;
			return esc_url( add_query_arg( $base_args, admin_url( 'admin.php' ) ) );
		};
		echo '<div class="wts-pagination">';
		echo '<span class="wts-pg-info">' . sprintf( esc_html__( '%1$d sonuç · sayfa %2$d / %3$d', 'wts' ), $total, $current, $total_pages ) . '</span>';
		if ( $current > 1 ) {
			echo '<a href="' . $build( 1 ) . '">&laquo;</a>';
			echo '<a href="' . $build( $current - 1 ) . '">&lsaquo;</a>';
		} else {
			echo '<span class="disabled">&laquo;</span><span class="disabled">&lsaquo;</span>';
		}
		// 5'lik aralık
		$start = max( 1, $current - 2 );
		$end   = min( $total_pages, $current + 2 );
		for ( $i = $start; $i <= $end; $i++ ) {
			if ( $i === $current ) {
				echo '<span class="current">' . (int) $i . '</span>';
			} else {
				echo '<a href="' . $build( $i ) . '">' . (int) $i . '</a>';
			}
		}
		if ( $current < $total_pages ) {
			echo '<a href="' . $build( $current + 1 ) . '">&rsaquo;</a>';
			echo '<a href="' . $build( $total_pages ) . '">&raquo;</a>';
		} else {
			echo '<span class="disabled">&rsaquo;</span><span class="disabled">&raquo;</span>';
		}
		echo '</div>';
	}

	/** Durum badge HTML üret. */
	public static function badge( $type, $label, $icon = '' ) {
		$icon_html = $icon ? '<span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span>' : '';
		return '<span class="wts-badge ' . esc_attr( $type ) . '">' . $icon_html . esc_html( $label ) . '</span>';
	}

	/** Path string'i kısalt — son segmenti tutar, üstünü "… > " ile gösterir. */
	public static function short_path( $path, $max_len = 38 ) {
		$path = (string) $path;
		if ( mb_strlen( $path ) <= $max_len ) {
			return $path;
		}
		// Son segmenti tam tut, başını "..." yap
		$parts = explode( ' > ', $path );
		if ( count( $parts ) >= 3 ) {
			$last = end( $parts );
			return '… > ' . $last;
		}
		return mb_substr( $path, 0, $max_len - 1 ) . '…';
	}

	public static function register_menu() {
		add_menu_page( 'Trendyol Senkron', 'Trendyol Senkron', self::CAPABILITY, self::SLUG, array( __CLASS__, 'page_dashboard' ), 'dashicons-update', 57 );

		add_submenu_page( self::SLUG, 'Panel',                'Panel',                self::CAPABILITY, self::SLUG,                array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, 'Ayarlar',              'Ayarlar',              self::CAPABILITY, self::SLUG . '-settings',  array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::SLUG, 'Trendyol Adresleri',   'Trendyol Adresleri',   self::CAPABILITY, self::SLUG . '-addresses', array( __CLASS__, 'page_addresses' ) );
		add_submenu_page( self::SLUG, 'Fiyat Önizleme',       'Fiyat Önizleme',       self::CAPABILITY, self::SLUG . '-price',     array( __CLASS__, 'page_price_preview' ) );
		add_submenu_page( self::SLUG, 'Kategori Eşleştirme',  'Kategori Eşleştirme',  self::CAPABILITY, self::SLUG . '-categories',array( __CLASS__, 'page_categories' ) );
		add_submenu_page( self::SLUG, 'Kategori Özellikleri', 'Kategori Özellikleri', self::CAPABILITY, self::SLUG . '-cat-attrs', array( __CLASS__, 'page_cat_attrs' ) );
		add_submenu_page( self::SLUG, 'Marka Eşleştirme',     'Marka Eşleştirme',     self::CAPABILITY, self::SLUG . '-brands',    array( __CLASS__, 'page_brands' ) );
		add_submenu_page( self::SLUG, 'Ürün Eşleştirme',      'Ürün Eşleştirme',      self::CAPABILITY, self::SLUG . '-products',  array( __CLASS__, 'page_products' ) );
		add_submenu_page( self::SLUG, 'Trendyol\'a Gönder',   'Trendyol\'a Gönder',   self::CAPABILITY, self::SLUG . '-push',      array( __CLASS__, 'page_push' ) );
		add_submenu_page( self::SLUG, 'Stok Yönetimi',        'Stok Yönetimi',        self::CAPABILITY, self::SLUG . '-stock',     array( __CLASS__, 'page_stock' ) );
		add_submenu_page( self::SLUG, 'Fiyat Yönetimi',       'Fiyat Yönetimi',       self::CAPABILITY, self::SLUG . '-pricing',   array( __CLASS__, 'page_pricing' ) );
		add_submenu_page( self::SLUG, 'Senkron & Cron',       'Senkron & Cron',       self::CAPABILITY, self::SLUG . '-sync',      array( __CLASS__, 'page_sync' ) );
		add_submenu_page( self::SLUG, 'Satış Raporu',         'Satış Raporu',         self::CAPABILITY, self::SLUG . '-reports',   array( __CLASS__, 'page_reports' ) );
		add_submenu_page( self::SLUG, 'Loglar',               'Loglar',               self::CAPABILITY, self::SLUG . '-logs',      array( __CLASS__, 'page_logs' ) );
	}

	/* ================================================================ ACTIONS */

	public static function handle_action() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Yetkisiz.' );
		}
		$action = isset( $_REQUEST['wts_action'] ) ? sanitize_key( $_REQUEST['wts_action'] ) : '';
		check_admin_referer( 'wts_action_' . $action );

		$redirect = isset( $_REQUEST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_REQUEST['_wp_http_referer'] ) ) : admin_url( 'admin.php?page=wts' );

		switch ( $action ) {

			case 'test_api':
				$api = new WTS_API_Client();
				$resp = $api->ping();
				$msg  = $resp['success'] ? '✅ API bağlantısı başarılı.' : '❌ API hatası: ' . $resp['error'];
				set_transient( 'wts_admin_notice', $msg, 60 );
				break;

			case 'sync_categories':
				$r = WTS_Categories::sync_tree();
				set_transient( 'wts_admin_notice',
					$r['success'] ? "✅ {$r['count']} kategori senkronlandı." : "❌ " . $r['error'],
					60 );
				break;

			case 'sync_brands':
				$r = WTS_Brands::sync_all();
				set_transient( 'wts_admin_notice',
					$r['success'] ? "✅ {$r['count']} marka senkronlandı." : "❌ " . $r['error'],
					60 );
				break;

			case 'sync_addresses':
				$r = WTS_Addresses::sync();
				set_transient( 'wts_admin_notice',
					$r['success'] ? "✅ {$r['count']} satıcı adresi cache'lendi." : "❌ " . $r['error'],
					60 );
				break;

			case 'set_cat_attr_defaults':
				$cat_id = isset( $_POST['ty_category_id'] ) ? (int) $_POST['ty_category_id'] : 0;
				$attrs  = isset( $_POST['attr'] ) && is_array( $_POST['attr'] ) ? $_POST['attr'] : array();
				if ( ! $cat_id ) {
					set_transient( 'wts_admin_notice', '❌ Kategori ID eksik.', 60 );
					break;
				}
				// $attrs yapısı: [ attributeId => ['value_id'=>X, 'custom'=>Y, 'attribute_name'=>Z, 'value_name'=>W] ]
				$items = array();
				foreach ( $attrs as $aid => $row ) {
					$aid = (int) $aid;
					if ( $aid <= 0 ) continue;
					$items[] = array(
						'attributeId'    => $aid,
						'valueId'        => isset( $row['value_id'] ) && $row['value_id'] !== '' ? (int) $row['value_id'] : null,
						'customValue'    => isset( $row['custom'] ) ? sanitize_text_field( wp_unslash( $row['custom'] ) ) : '',
						'attribute_name' => isset( $row['attribute_name'] ) ? sanitize_text_field( wp_unslash( $row['attribute_name'] ) ) : '',
						'value_name'     => isset( $row['value_name'] ) ? sanitize_text_field( wp_unslash( $row['value_name'] ) ) : '',
					);
				}
				$saved = WTS_Category_Attrs::save_defaults( $cat_id, $items );
				$missing = WTS_Category_Attrs::missing_required_count( $cat_id );
				$msg = "✅ Kategori {$cat_id} için {$saved} default kaydedildi.";
				if ( $missing > 0 ) {
					$msg .= " ⚠️ {$missing} zorunlu attribute hâlâ boş!";
				} else {
					$msg .= " 🎉 Tüm zorunlu özellikler dolu — bu kategorideki ürünler artık gönderilebilir.";
				}
				set_transient( 'wts_admin_notice', $msg, 180 );
				// Redirect aynı kategorinin edit ekranına geri dönsün
				$redirect = add_query_arg( array( 'page' => 'wts-cat-attrs', 'edit' => $cat_id ), admin_url( 'admin.php' ) );
				break;

			case 'rebuild_category_map':
				$s = WTS_Categories::build_mapping_table();
				set_transient( 'wts_admin_notice',
					"Kategori eşleştirme: {$s['matched']} otomatik, {$s['suggested']} öneri, {$s['unmatched']} eşleşmedi.",
					60 );
				break;

			case 'set_category_map':
				$wp_term = isset( $_POST['wp_term_id'] ) ? (int) $_POST['wp_term_id'] : 0;
				$ty_cat  = isset( $_POST['ty_category_id'] ) ? (int) $_POST['ty_category_id'] : 0;
				if ( $wp_term && $ty_cat ) {
					WTS_Categories::set_manual_mapping( $wp_term, $ty_cat );
					set_transient( 'wts_admin_notice', '✅ Kategori eşleştirmesi kaydedildi.', 60 );
				}
				break;

			case 'clear_category_map':
				$wp_term = isset( $_POST['wp_term_id'] ) ? (int) $_POST['wp_term_id'] : 0;
				if ( $wp_term ) {
					WTS_Categories::clear_mapping( $wp_term );
					set_transient( 'wts_admin_notice', 'Eşleştirme kaldırıldı.', 60 );
				}
				break;

			case 'rebuild_brand_map':
				$s = WTS_Brands::rebuild_map();
				set_transient( 'wts_admin_notice',
					"Marka eşleştirme: {$s['matched']} eşleşti, {$s['unmatched']} eşleşmedi (Toplam: {$s['total']}).",
					60 );
				break;

			case 'set_brand_map':
				$wp_term  = isset( $_POST['wp_term_id'] ) ? (int) $_POST['wp_term_id'] : 0;
				$ty_brand = isset( $_POST['ty_brand_id'] ) ? (int) $_POST['ty_brand_id'] : 0;
				if ( $wp_term && $ty_brand ) {
					WTS_Brands::set_map( $wp_term, $ty_brand );
					set_transient( 'wts_admin_notice', '✅ Marka eşleştirmesi kaydedildi.', 60 );
				} else {
					set_transient( 'wts_admin_notice', '❌ Eksik bilgi.', 60 );
				}
				break;

			case 'clear_brand_map':
				$wp_term = isset( $_POST['wp_term_id'] ) ? (int) $_POST['wp_term_id'] : 0;
				if ( $wp_term ) {
					WTS_Brands::clear_map( $wp_term );
					set_transient( 'wts_admin_notice', 'Marka eşleştirmesi kaldırıldı.', 60 );
				}
				break;

			case 'push_single':
				$pid = isset( $_REQUEST['wp_id'] ) ? (int) $_REQUEST['wp_id'] : 0;
				if ( ! $pid ) {
					set_transient( 'wts_admin_notice', '❌ Ürün ID yok.', 60 );
					break;
				}
				$r = WTS_Products::push_products( array( $pid ) );
				if ( $r['success'] ) {
					$msg = "✅ #{$pid} Trendyol'a gönderildi";
					if ( ! empty( $r['pushed'] ) && $r['pushed'] > 1 ) {
						$msg .= " ({$r['pushed']} varyant)";
					}
					$msg .= ". Trendyol onayı genellikle 1-30 saniye içinde tamamlanır.";
					set_transient( 'wts_admin_notice', $msg, 180 );
				} else {
					// Sıra: skipped[$pid] (en spesifik) → ilk skipped → $r['error']
					$err = '';
					if ( ! empty( $r['skipped'][ $pid ] ) ) {
						$err = $r['skipped'][ $pid ];
					} elseif ( ! empty( $r['skipped'] ) ) {
						$err = reset( $r['skipped'] );
					} elseif ( ! empty( $r['error'] ) ) {
						$err = $r['error'];
					} else {
						$err = 'Hata detayı için Loglar sayfasını kontrol et (product_push action).';
					}
					set_transient( 'wts_admin_notice', "❌ #{$pid} gönderilemedi: {$err}", 300 );
				}
				break;

			case 'rebuild_product_map':
				$s = WTS_Products::build_mapping_table( false );
				set_transient( 'wts_admin_notice',
					"Ürün eşleştirme: SKU {$s['matched_sku']}, ad {$s['matched_name']}, eşleşmemiş {$s['unmatched']} (Toplam WP: {$s['total_wp']}, Trendyol: {$s['total_ty']}).",
					120 );
				break;

			case 'sync_ty_cache':
				$r = WTS_Products::sync_ty_cache();
				set_transient( 'wts_admin_notice',
					$r['success']
						? "✅ {$r['count']} Trendyol ürünü cache'lendi."
						: '❌ ' . $r['error'],
					120 );
				break;

			case 'set_product_match':
				$pid  = isset( $_POST['wp_post_id'] ) ? (int) $_POST['wp_post_id'] : 0;
				$bcd  = isset( $_POST['ty_barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['ty_barcode'] ) ) : '';
				$r    = WTS_Products::set_manual_match( $pid, $bcd );
				set_transient( 'wts_admin_notice',
					$r['success']
						? "✅ #{$pid} → {$bcd} bağlandı."
						: '❌ ' . $r['error'],
					60 );
				break;

			case 'clear_product_match':
				$pid = isset( $_POST['wp_post_id'] ) ? (int) $_POST['wp_post_id'] : 0;
				if ( $pid && WTS_Products::clear_match( $pid ) ) {
					set_transient( 'wts_admin_notice', "Bağlantı kaldırıldı (#{$pid}).", 60 );
				}
				break;

			case 'update_wp_stock':
				$pid   = isset( $_POST['wp_post_id'] ) ? (int) $_POST['wp_post_id'] : 0;
				$stock = isset( $_POST['new_stock'] ) ? max( 0, (int) $_POST['new_stock'] ) : 0;
				if ( ! $pid ) {
					set_transient( 'wts_admin_notice', '❌ Ürün ID yok.', 60 );
					break;
				}
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					set_transient( 'wts_admin_notice', '❌ Ürün bulunamadı.', 60 );
					break;
				}
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $stock );
				$product->save();
				$push = WTS_Stock_Sync::push_single_to_ty( $pid, false );
				if ( $push['success'] && $push['batch_id'] ) {
					$poll = WTS_Stock_Sync::poll_batch_quick( $push['batch_id'], 6 );
					if ( $poll['ready'] && $poll['fail_count'] > 0 ) {
						$err = ! empty( $poll['errors'] ) ? implode( ' | ', array_unique( $poll['errors'] ) ) : '(detay yok)';
						set_transient( 'wts_admin_notice',
							"⚠️ #{$pid} WP'de güncellendi ama Trendyol kabul etmedi: {$err}",
							180 );
					} elseif ( $poll['ready'] ) {
						set_transient( 'wts_admin_notice', "✅ #{$pid} stoğu {$stock} olarak Trendyol'a uygulandı (batch ✓).", 90 );
					} else {
						set_transient( 'wts_admin_notice', "✅ #{$pid} stoğu gönderildi (batch: {$push['batch_id']}) — sonuç bekleniyor. Senkron & Cron sayfasından kontrol edebilirsin.", 120 );
					}
				} else {
					set_transient( 'wts_admin_notice', "⚠️ #{$pid} WP'de güncellendi ama Trendyol push'u başarısız: " . ( $push['error'] ?: 'eşleştirme kontrol et' ), 180 );
				}
				break;

			case 'update_wp_price':
				$pid     = isset( $_POST['wp_post_id'] ) ? (int) $_POST['wp_post_id'] : 0;
				$reg     = ( isset( $_POST['regular_price'] ) && '' !== $_POST['regular_price'] ) ? floatval( str_replace( ',', '.', $_POST['regular_price'] ) ) : null;
				$sale    = ( isset( $_POST['sale_price'] )    && '' !== $_POST['sale_price'] )    ? floatval( str_replace( ',', '.', $_POST['sale_price'] ) )    : null;
				if ( ! $pid ) {
					set_transient( 'wts_admin_notice', '❌ Ürün ID yok.', 60 );
					break;
				}
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					set_transient( 'wts_admin_notice', '❌ Ürün bulunamadı.', 60 );
					break;
				}
				if ( null !== $reg ) {
					$product->set_regular_price( (string) $reg );
				}
				if ( null !== $sale && $sale > 0 ) {
					$product->set_sale_price( (string) $sale );
					$product->set_price( (string) $sale );
				} else {
					$product->set_sale_price( '' );
					if ( null !== $reg ) {
						$product->set_price( (string) $reg );
					}
				}
				$product->save();

				$push = WTS_Stock_Sync::push_single_to_ty( $pid, true );
				$calc = WTS_Price::calculate( $product );
				$try  = isset( $calc['sale_price'] ) ? number_format( (float) $calc['sale_price'], 2 ) . ' ₺' : '?';

				if ( $push['success'] && $push['batch_id'] ) {
					$poll = WTS_Stock_Sync::poll_batch_quick( $push['batch_id'], 6 );
					if ( $poll['ready'] && $poll['fail_count'] > 0 ) {
						$err = ! empty( $poll['errors'] ) ? implode( ' | ', array_unique( $poll['errors'] ) ) : '(Trendyol sebebi belirtmedi)';
						set_transient( 'wts_admin_notice',
							"⚠️ #{$pid} WP'de güncellendi ama Trendyol fiyat değişikliğini KABUL ETMEDİ. Sebep: {$err}",
							240 );
					} elseif ( $poll['ready'] ) {
						set_transient( 'wts_admin_notice',
							"✅ #{$pid} → Trendyol fiyat {$try} olarak uygulandı (batch ✓).",
							120 );
					} else {
						set_transient( 'wts_admin_notice',
							"✅ #{$pid} fiyatı {$try} olarak gönderildi (batch: {$push['batch_id']}). Trendyol onayı genelde 1-30 sn sürer; sonucu Loglardan veya 'Bekleyen Batch'leri Kontrol Et' butonundan görebilirsin.",
							180 );
					}
				} else {
					set_transient( 'wts_admin_notice',
						"⚠️ #{$pid} WP'de güncellendi ama Trendyol push hatası: " . ( $push['error'] ?: 'eşleştirme kontrol et' ),
						240 );
				}
				break;

			case 'push_all_prices':
				global $wpdb;
				$map_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wts_product_map WHERE ty_barcode NOT LIKE 'WP\\_%' AND match_type != 'unmatched' AND status = 'synced' LIMIT 1000" );
				$items   = array();
				$skipped = 0;
				foreach ( $map_rows as $mr ) {
					$product = wc_get_product( $mr->wp_post_id );
					if ( ! $product ) { $skipped++; continue; }
					$calc = WTS_Price::calculate( $product );
					if ( ! empty( $calc['error'] ) || empty( $calc['sale_price'] ) ) {
						$skipped++;
						continue;
					}
					$item = array(
						'barcode'   => $mr->ty_barcode,
						'quantity'  => (int) $product->get_stock_quantity(),
						'salePrice' => floatval( $calc['sale_price'] ),
					);
					if ( ! empty( $calc['list_price'] ) ) {
						$item['listPrice'] = floatval( $calc['list_price'] );
					}
					$items[] = $item;
				}
				if ( empty( $items ) ) {
					set_transient( 'wts_admin_notice', "❌ Push'lanacak ürün yok. Atlanan: {$skipped}", 90 );
					break;
				}
				$api = new WTS_API_Client();
				$pushed = 0; $errors = 0; $batch_ids = array();
				foreach ( array_chunk( $items, 100 ) as $chunk ) {
					$resp = $api->update_price_and_inventory( $chunk );
					if ( $resp['success'] ) {
						$pushed += count( $chunk );
						$bid = isset( $resp['data']['batchRequestId'] ) ? $resp['data']['batchRequestId'] : '';
						if ( $bid ) {
							$batch_ids[] = $bid;
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
						// Cache + mapping snapshot'larını anında güncelle. Batch reddedilirse
						// kullanıcı loglarda görür; bir sonraki "Cache'i Tazele" üzerine yazar.
						foreach ( $chunk as $it ) {
							$bar = isset( $it['barcode'] ) ? $it['barcode'] : '';
							if ( ! $bar ) continue;
							$fields = array();
							if ( isset( $it['quantity'] ) )  $fields['quantity']   = (int) $it['quantity'];
							if ( isset( $it['salePrice'] ) ) $fields['sale_price'] = floatval( $it['salePrice'] );
							if ( isset( $it['listPrice'] ) ) $fields['list_price'] = floatval( $it['listPrice'] );
							if ( ! empty( $fields ) ) {
								WTS_Products::update_ty_cache_row( $bar, $fields );
							}
							$map_fields = array();
							if ( isset( $it['salePrice'] ) ) $map_fields['last_ty_price'] = floatval( $it['salePrice'] );
							if ( isset( $it['quantity'] ) )  $map_fields['last_ty_stock'] = (int) $it['quantity'];
							if ( ! empty( $map_fields ) ) {
								$map_fields['last_synced_at'] = current_time( 'mysql' );
								$wpdb->update( $wpdb->prefix . 'wts_product_map', $map_fields, array( 'ty_barcode' => $bar ) );
							}
						}
					} else {
						$errors += count( $chunk );
						WTS_Logger::error( 'push_all_prices', $resp['error'] );
					}
				}
				// İlk batch'i kısaca poll et — kullanıcıya canlı feedback
				$poll_summary = '';
				if ( ! empty( $batch_ids ) ) {
					$poll = WTS_Stock_Sync::poll_batch_quick( $batch_ids[0], 6 );
					if ( $poll['ready'] ) {
						if ( $poll['fail_count'] > 0 ) {
							$first_err = ! empty( $poll['errors'] ) ? reset( $poll['errors'] ) : '(detay yok)';
							$poll_summary = " — İlk batch'in {$poll['fail_count']} ürünü reddedildi. Örnek sebep: {$first_err}";
						} else {
							$poll_summary = " — İlk batch ✓ tamamen kabul edildi ({$poll['success_count']} ürün).";
						}
					} else {
						$poll_summary = " — İlk batch henüz işleniyor (Senkron & Cron > Bekleyen Batch'leri Kontrol Et).";
					}
				}
				set_transient( 'wts_admin_notice',
					"Toplu fiyat push: {$pushed} gönderildi, {$errors} HTTP hatası, {$skipped} atlandı (eşleştirme/fiyat eksik). Batch sayısı: " . count( $batch_ids ) . $poll_summary,
					300 );
				break;

			case 'push_selected':
				// Toplu gönderim çok ağır — PHP timeout/memory'yi yükselt.
				@set_time_limit( 300 );
				if ( function_exists( 'wp_raise_memory_limit' ) ) {
					wp_raise_memory_limit( 'admin' );
				}
				@ini_set( 'memory_limit', '512M' );

				$bulk_mode = isset( $_POST['bulk_mode'] ) ? sanitize_key( $_POST['bulk_mode'] ) : 'selected';
				$ids = array();
				$total_matching = 0;
				$per_click_cap  = 50; // Trendyol'un kendi batch boyutu — fazlası zaten ayrı API çağrısı olur, timeout riski artar

				if ( 'filtered' === $bulk_mode ) {
					// Toplam filtreye uyan ürün sayısı (WP_Query'den hızlıca al, "X kaldı" mesajı için)
					$count_args = self::build_push_query_args( $_POST, 1, 1 );
					$count_args['fields'] = 'ids';
					$count_args['no_found_rows'] = false;
					$count_q = new WP_Query( $count_args );
					$total_matching = (int) $count_q->found_posts;

					// Aynı filtreyle, en fazla 50 pushable ürün al (early-exit ile hızlı)
					$ids = self::query_push_ids_by_filter( $_POST, $per_click_cap, 1000 );
					if ( empty( $ids ) ) {
						set_transient( 'wts_admin_notice', 'Filtreye uyan gönderilebilir ürün bulunamadı (zaten gönderilmiş veya kategori/marka eşleşmesi eksik).', 60 );
						break;
					}
					WTS_Logger::info( 'product_push', "Toplu gönderim başladı: filtreye {$total_matching} ürün uyuyor, bu turda " . count( $ids ) . " ürün işlenecek." );
				} else {
					$ids = isset( $_POST['wp_ids'] ) ? array_map( 'intval', (array) $_POST['wp_ids'] ) : array();
					if ( empty( $ids ) ) {
						set_transient( 'wts_admin_notice', 'Seçili ürün yok.', 60 );
						break;
					}
					// Seçili modda da güvenlik için cap'le, kalanlar sonraki tıklamada
					if ( count( $ids ) > $per_click_cap ) {
						$total_matching = count( $ids );
						$ids = array_slice( $ids, 0, $per_click_cap );
					}
				}

				$r = WTS_Products::push_products( $ids );
				$remaining = max( 0, $total_matching - count( $ids ) );

				if ( $r['success'] ) {
					$prod  = isset( $r['products'] ) ? (int) $r['products'] : count( $ids );
					$skip  = isset( $r['skipped'] ) ? count( $r['skipped'] ) : 0;
					$msg   = "✅ {$prod} ürün Trendyol'a gönderildi";
					if ( $skip > 0 ) {
						$msg .= " ({$skip} atlandı)";
					}
					$msg .= ". Trendyol onayı genellikle 1-30 saniye içinde tamamlanır.";

					if ( $remaining > 0 ) {
						$msg .= " 📦 Filtreye uyan {$remaining} ürün daha var — \"Filtredekilerin Tümünü Gönder\" butonuna tekrar basarak devam edebilirsin.";
					}
					if ( $skip > 0 ) {
						$first_err = reset( $r['skipped'] );
						$msg .= " İlk atlanma sebebi: {$first_err}";
					}
					set_transient( 'wts_admin_notice', $msg, 240 );
				} else {
					$first_err = ! empty( $r['skipped'] ) ? reset( $r['skipped'] ) : '';
					WTS_Logger::error( 'product_push_bulk',
						"Toplu gönderim başarısız: " . ( $r['error'] ?: 'bilinmiyor' ),
						array( 'first_skipped' => $first_err, 'ids_count' => count( $ids ) )
					);
					set_transient( 'wts_admin_notice',
						'❌ ' . ( $r['error'] ?: 'Gönderim başarısız.' ) . ( $first_err ? ' İlk hata: ' . $first_err : '' ) . ' Detay için Loglar sayfasını kontrol et.',
						240
					);
				}
				break;

			case 'preview_payload':
				$pid = isset( $_POST['wp_id'] ) ? (int) $_POST['wp_id'] : 0;
				if ( $pid ) {
					$build = WTS_Products::build_create_payload( $pid );
					set_transient( 'wts_payload_preview_' . $pid, $build, 300 );
					$redirect = add_query_arg( array( 'page' => 'wts-push', 'preview' => $pid ), admin_url( 'admin.php' ) );
				}
				break;

			case 'pull_products':
				$n = WTS_Products::pull_new_to_wp();
				set_transient( 'wts_admin_notice', "{$n} yeni ürün Trendyol'dan WP'ye çekildi (taslak).", 60 );
				break;

			case 'backfill_images':
				$r = WTS_Products::backfill_pulled_images();
				set_transient( 'wts_admin_notice',
					"Görsel doldurma tamamlandı: {$r['updated']} ürün, {$r['images']} görsel eklendi.",
					120 );
				break;

			case 'check_batches':
				$n = WTS_Products::check_pending_batches();
				set_transient( 'wts_admin_notice', "{$n} bekleyen batch sorgulandı.", 60 );
				break;

			case 'sync_stock':
				$dir = isset( $_POST['direction'] ) ? sanitize_key( $_POST['direction'] ) : '';
				$r   = WTS_Stock_Sync::sync_all( $dir );
				if ( $r['success'] ) {
					$s = $r['stats'];
					set_transient( 'wts_admin_notice',
						"Stok senkronu: WP={$s['wp_updated']}, TY={$s['ty_updated']}, atlanan={$s['skipped']}, hata={$s['errors']}.",
						120 );
				} else {
					set_transient( 'wts_admin_notice', '❌ ' . $r['error'], 60 );
				}
				break;

			case 'pull_orders':
				$days = isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : 7;
				$r1 = WTS_Orders::pull_trendyol_orders( $days );
				$r2 = WTS_Orders::pull_wc_orders( $days );
				set_transient( 'wts_admin_notice',
					"Sipariş çekme: Trendyol {$r1['count']}, WP {$r2['count']} (son {$days} gün).",
					60 );
				break;

			case 'run_cron_fast':
				WTS_Cron::run_fast();
				set_transient( 'wts_admin_notice', 'Hızlı cron manuel olarak çalıştırıldı.', 60 );
				break;

			case 'run_cron_slow':
				WTS_Cron::run_slow();
				set_transient( 'wts_admin_notice', 'Yavaş cron manuel olarak çalıştırıldı.', 60 );
				break;

			case 'regenerate_webhook_secret':
				WTS_Webhook::regenerate_secret();
				set_transient( 'wts_admin_notice', 'Webhook secret yenilendi.', 60 );
				break;

			case 'clear_logs':
				$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 0;
				$n = WTS_Logger::purge_older_than( $days );
				set_transient( 'wts_admin_notice', "{$n} log kaydı silindi.", 60 );
				break;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	protected static function action_button( $label, $action, $extra = array(), $class = 'button' ) {
		// Buton etiketinde sadece <span class="dashicons ..."></span> gibi güvenli HTML'e izin ver.
		$allowed_html = array(
			'span' => array(
				'class' => true,
				'aria-hidden' => true,
				'style' => true,
			),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<?php wp_nonce_field( 'wts_action_' . $action ); ?>
			<input type="hidden" name="action" value="wts_action">
			<input type="hidden" name="wts_action" value="<?php echo esc_attr( $action ); ?>">
			<?php foreach ( $extra as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<button class="<?php echo esc_attr( $class ); ?>"><?php echo wp_kses( $label, $allowed_html ); ?></button>
		</form>
		<?php
	}

	protected static function flash_notice() {
		$msg = get_transient( 'wts_admin_notice' );
		if ( $msg ) {
			delete_transient( 'wts_admin_notice' );
			echo '<div class="notice notice-info is-dismissible" style="margin-top:18px;"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/* ================================================================ DASHBOARD */

	public static function page_dashboard() {
		global $wpdb;
		$p = $wpdb->prefix;

		$mapped_cats   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wts_category_map WHERE match_type != 'unmatched' AND ty_category_id IS NOT NULL" );
		$unmapped_cats = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wts_category_map WHERE match_type = 'unmatched' OR ty_category_id IS NULL" );
		$prod_stats    = WTS_Products::get_mapping_stats();

		$has_api = WTS_Settings::get( 'api_key' ) && WTS_Settings::get( 'api_secret' ) && WTS_Settings::get( 'api_seller_id' );
		$next    = WTS_Cron::next_runs();

		?>
		<div class="wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Trendyol Senkron — Panel</h1>
			<?php self::flash_notice(); ?>

			<?php if ( ! $has_api ) : ?>
				<div class="notice notice-warning"><p><strong>API bilgileri eksik.</strong> Önce <a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-settings' ) ); ?>">Ayarlar</a> sayfasından satıcı ID, API key ve secret'ı girin.</p></div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:18px;">
				<?php
				self::stat_card( 'Eşleşmiş Kategoriler',     $mapped_cats,                  'admin.php?page=wts-categories' );
				self::stat_card( 'Eşleşmemiş Kategoriler',   $unmapped_cats,                'admin.php?page=wts-categories' );
				self::stat_card( 'Senkron Ürün',             $prod_stats['synced'] . ' / ' . $prod_stats['total'], 'admin.php?page=wts-products' );
				self::stat_card( 'Hatalı Ürün',              $prod_stats['error'],          'admin.php?page=wts-products' );
				self::stat_card( 'Bekleyen / Senkronize Olunuyor', $prod_stats['pending'] . ' / ' . $prod_stats['syncing'], 'admin.php?page=wts-products' );
				self::stat_card( 'Eşleşmemiş Ürün',          $prod_stats['unmatched'],      'admin.php?page=wts-products' );
				?>
			</div>

			<h2 style="margin-top:30px;">Hızlı Aksiyonlar</h2>
			<p>
				<?php self::action_button( 'API Bağlantısını Test Et', 'test_api', array(), 'button button-primary' ); ?>
				<?php self::action_button( 'Trendyol Ürünlerini Çek (Cache)', 'sync_ty_cache' ); ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-push' ) ); ?>"><span class="dashicons dashicons-upload" style="margin-top:4px;"></span> Trendyol'a Ürün Gönder</a>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-products' ) ); ?>">Ürün Eşleştirme</a>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-stock' ) ); ?>">Stok Yönetimi</a>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-pricing' ) ); ?>">Fiyat Yönetimi</a>
			</p>
			<p>
				<?php self::action_button( 'Kategorileri Çek', 'sync_categories' ); ?>
				<?php self::action_button( 'Markaları Çek', 'sync_brands' ); ?>
				<?php self::action_button( 'Adresleri Çek', 'sync_addresses' ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-addresses' ) ); ?>">Adresler</a>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-cat-attrs' ) ); ?>"><span class="dashicons dashicons-list-view" style="margin-top:4px;"></span> Kategori Özellikleri</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-price' ) ); ?>">Fiyat Hesaplamayı Test Et</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-sync' ) ); ?>">Senkron &amp; Cron</a>
			</p>

			<h2 style="margin-top:30px;">Durum</h2>
			<table class="widefat striped" style="max-width:760px;">
				<tbody>
					<tr><th>Eklenti Sürümü</th><td><?php echo esc_html( WTS_VERSION ); ?></td></tr>
					<tr><th>FOX (WOOCS) Aktif</th><td><?php echo wts_woocs() ? '✅ Evet' : '❌ Hayır'; ?></td></tr>
					<tr><th>WCMP Aktif</th><td><?php echo class_exists( 'WCMP_Pricing' ) ? '✅ Evet' : '❌ Hayır'; ?></td></tr>
					<tr><th>API Modu</th><td><?php echo esc_html( WTS_Settings::get( 'api_mode' ) ); ?></td></tr>
					<tr><th>Marka Cache</th><td><?php echo WTS_Brands::count(); ?> kayıt — son: <?php echo esc_html( WTS_Brands::last_sync() ?: '—' ); ?></td></tr>
					<tr><th>Kategori Cache</th><td><?php echo WTS_Categories::count_all(); ?> kayıt (<?php echo WTS_Categories::count_leaves(); ?> leaf) — son: <?php echo esc_html( WTS_Categories::last_sync() ?: '—' ); ?></td></tr>
					<tr><th>Adres Cache</th><td><?php echo WTS_Addresses::count(); ?> kayıt — son: <?php echo esc_html( WTS_Addresses::last_sync() ?: '—' ); ?>
						· Default sevkiyat: <code><?php echo (int) WTS_Addresses::default_shipment_id() ?: '—'; ?></code>
						· Default iade: <code><?php echo (int) WTS_Addresses::default_returning_id() ?: '—'; ?></code></td></tr>
					<tr><th>Sonraki Hızlı Cron</th><td><?php echo $next['fast'] ? esc_html( date_i18n( 'Y-m-d H:i:s', $next['fast'] ) ) : '—'; ?></td></tr>
					<tr><th>Sonraki Yavaş Cron</th><td><?php echo $next['slow'] ? esc_html( date_i18n( 'Y-m-d H:i:s', $next['slow'] ) ) : '—'; ?></td></tr>
					<tr><th>Webhook URL</th><td><code style="word-break:break-all;"><?php echo esc_html( WTS_Webhook::get_webhook_url() ); ?></code></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	protected static function stat_card( $label, $value, $href = '' ) {
		?>
		<div style="background:#fff;border:1px solid #e2e4e7;border-radius:6px;padding:18px;">
			<div style="font-size:13px;color:#646970;"><?php echo esc_html( $label ); ?></div>
			<div style="font-size:28px;font-weight:600;margin-top:6px;"><?php echo esc_html( $value ); ?></div>
			<?php if ( $href ) : ?>
				<div style="margin-top:8px;"><a href="<?php echo esc_url( admin_url( $href ) ); ?>">Detay →</a></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ================================================================ SETTINGS */

	public static function page_settings() {
		?>
		<div class="wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Trendyol Senkron — Ayarlar</h1>
			<?php self::flash_notice(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'wts_settings_group' ); ?>
				<?php $s = wts_settings(); ?>

				<h2>API Kimlik Bilgileri</h2>
				<table class="form-table">
					<tr>
						<th><label>Satıcı ID</label></th>
						<td><input type="text" name="wts_settings[api_seller_id]" value="<?php echo esc_attr( $s['api_seller_id'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label>API Key</label></th>
						<td><input type="text" name="wts_settings[api_key]" value="<?php echo esc_attr( $s['api_key'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label>API Secret</label></th>
						<td><input type="password" name="wts_settings[api_secret]" value="<?php echo esc_attr( $s['api_secret'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label>Integration Adı (ops.)</label></th>
						<td>
							<input type="text" name="wts_settings[api_integration]" value="<?php echo esc_attr( $s['api_integration'] ); ?>" class="regular-text">
							<p class="description">Boş bırakılırsa "{SatıcıID} - SelfIntegration" kullanılır.</p>
						</td>
					</tr>
					<tr>
						<th><label>Mod</label></th>
						<td>
							<select name="wts_settings[api_mode]">
								<option value="production" <?php selected( $s['api_mode'], 'production' ); ?>>Production</option>
								<option value="sandbox"    <?php selected( $s['api_mode'], 'sandbox' ); ?>>Sandbox</option>
							</select>
						</td>
					</tr>
				</table>

				<h2>Fiyatlandırma</h2>
				<table class="form-table">
					<tr>
						<th>Fiyat Kaynağı</th>
						<td>
							<p><strong>WooCommerce ürün fiyatı</strong> (olduğu gibi)</p>
							<p class="description">
								Ürünlere Trendyol'a gönderilirken WooCommerce'deki fiyatları
								doğrudan kullanılır. FOX (WOOCS) / WCMP kur çevirimi, KDV ekleme,
								yuvarlama ve liste fiyatı markup'ı devre dışıdır.<br>
								<strong>listPrice</strong> = normal fiyat, <strong>salePrice</strong> =
								indirim varsa indirimli fiyat (yoksa normal fiyat).
							</p>
						</td>
					</tr>
				</table>

				<h2>Senkronizasyon</h2>
				<table class="form-table">
					<tr>
						<th>Yön</th>
						<td>
							<select name="wts_settings[sync_direction]">
								<option value="wp_to_ty"      <?php selected( $s['sync_direction'], 'wp_to_ty' ); ?>>WP → Trendyol</option>
								<option value="ty_to_wp"      <?php selected( $s['sync_direction'], 'ty_to_wp' ); ?>>Trendyol → WP</option>
								<option value="bidirectional" <?php selected( $s['sync_direction'], 'bidirectional' ); ?>>Çift yön (delta)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Stok Çakışması</th>
						<td>
							<select name="wts_settings[stock_source]">
								<option value="wp"       <?php selected( $s['stock_source'], 'wp' ); ?>>WP kazanır</option>
								<option value="trendyol" <?php selected( $s['stock_source'], 'trendyol' ); ?>>Trendyol kazanır</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Ürün Eşleştirme</th>
						<td>
							<select name="wts_settings[auto_match_products]">
								<option value="sku"  <?php selected( $s['auto_match_products'], 'sku' ); ?>>SKU/Barkod</option>
								<option value="name" <?php selected( $s['auto_match_products'], 'name' ); ?>>İsim</option>
								<option value="both" <?php selected( $s['auto_match_products'], 'both' ); ?>>Önce SKU, sonra isim</option>
								<option value="off"  <?php selected( $s['auto_match_products'], 'off' ); ?>>Kapalı</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Kategori Eşleştirme</th>
						<td>
							<select name="wts_settings[auto_match_categories]">
								<option value="name" <?php selected( $s['auto_match_categories'], 'name' ); ?>>İsim benzerliği</option>
								<option value="path" <?php selected( $s['auto_match_categories'], 'path' ); ?>>Yol benzerliği</option>
								<option value="off"  <?php selected( $s['auto_match_categories'], 'off' ); ?>>Kapalı</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Varsayılan Marka ID</th>
						<td>
							<input type="number" min="0" name="wts_settings[default_brand_id]" value="<?php echo esc_attr( $s['default_brand_id'] ?? 0 ); ?>" class="small-text">
							<p class="description">
								Ürünün kendi markası bulunamaz veya eşleştirilmemişse Trendyol'a bu marka ID'siyle gönderilir.
								Boş bırakırsan eşleştirmesiz ürünler gönderilemez. Marka ID'lerini <a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-brands' ) ); ?>">Marka Eşleştirme</a> sayfasından öğrenebilirsin.
							</p>
						</td>
					</tr>
					<tr>
						<th>Hızlı Cron (dk)</th>
						<td><input type="number" min="5" name="wts_settings[cron_fast_minutes]" value="<?php echo esc_attr( $s['cron_fast_minutes'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th>Yavaş Cron (sa)</th>
						<td><input type="number" min="1" name="wts_settings[cron_slow_hours]" value="<?php echo esc_attr( $s['cron_slow_hours'] ); ?>" class="small-text"></td>
					</tr>
				</table>

				<h2>Trendyol Ürün Gönderim Varsayılanları (createProducts V2)</h2>
				<p class="description">
					Bu alanlar yeni ürün gönderirken kullanılır. Adresleri "<a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-addresses' ) ); ?>">Trendyol Adresleri</a>" sayfasından çek, sonra burada seç.
				</p>
				<table class="form-table">
					<tr>
						<th>Storefront Code</th>
						<td>
							<input type="text" maxlength="4" name="wts_settings[storefront_code]" value="<?php echo esc_attr( $s['storefront_code'] ?? 'TR' ); ?>" class="small-text">
							<p class="description">V2 API'nin zorunlu header'ı. Türkiye için <code>TR</code> (varsayılan). Diğer ülkeler: AZ, DE, INT vs.</p>
						</td>
					</tr>
					<tr>
						<th>Sevkiyat Adres ID</th>
						<td>
							<?php
							$addr_rows = WTS_Addresses::all();
							if ( empty( $addr_rows ) ) :
							?>
								<em>Önce <a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-addresses' ) ); ?>">Trendyol Adresleri</a> sayfasından adresleri çek.</em>
							<?php else : ?>
								<select name="wts_settings[default_shipment_address_id]">
									<option value="0">— Otomatik (Trendyol default) —</option>
									<?php foreach ( $addr_rows as $a ) :
										$lbl = trim( ( $a->present_name ?: '#' . $a->ty_address_id ) . ' · ' . $a->city );
									?>
										<option value="<?php echo (int) $a->ty_address_id; ?>" <?php selected( (int) ( $s['default_shipment_address_id'] ?? 0 ), (int) $a->ty_address_id ); ?>>
											<?php echo esc_html( "#{$a->ty_address_id} – {$lbl}" ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>İade Adres ID</th>
						<td>
							<?php if ( empty( $addr_rows ) ) : ?>
								<em>—</em>
							<?php else : ?>
								<select name="wts_settings[default_returning_address_id]">
									<option value="0">— Otomatik (Trendyol default) —</option>
									<?php foreach ( $addr_rows as $a ) :
										$lbl = trim( ( $a->present_name ?: '#' . $a->ty_address_id ) . ' · ' . $a->city );
									?>
										<option value="<?php echo (int) $a->ty_address_id; ?>" <?php selected( (int) ( $s['default_returning_address_id'] ?? 0 ), (int) $a->ty_address_id ); ?>>
											<?php echo esc_html( "#{$a->ty_address_id} – {$lbl}" ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Teslimat Süresi (gün)</th>
						<td>
							<input type="number" min="0" max="60" name="wts_settings[default_delivery_duration]" value="<?php echo esc_attr( $s['default_delivery_duration'] ?? 0 ); ?>" class="small-text">
							<p class="description">
								<strong>0 = gönderme (önerilen).</strong> Trendyol mağaza profilindeki default kullanılır — her kategori için kendi aralığına uygun süre otomatik uygulanır.<br>
								Eğer manuel bir değer girersen (örn. 3) ve kategorinin min-max'ı dışında kalırsa
								<em>"Sevkiyat süresi belirlenen kategori min-max aralığında değildir"</em> hatası alırsın.
								Tüm kategoriler için aynı sabit süre kullanmak istiyorsan ve emin olduğun bir değerse gir.
							</p>
						</td>
					</tr>
					<tr>
						<th>Varsayılan Desi (kg)</th>
						<td>
							<input type="number" min="0" step="0.1" name="wts_settings[default_dimensional_weight]" value="<?php echo esc_attr( $s['default_dimensional_weight'] ?? 1 ); ?>" class="small-text">
							<p class="description">Ürünün kendi boyutları/ağırlığı yoksa kullanılacak fallback desi. Boyutlar (uzunluk × genişlik × yükseklik / 3000) varsa hesaplanır, yoksa ağırlık (kg), o da yoksa bu değer.</p>
						</td>
					</tr>
					<tr>
						<th>Kargo Şirket ID (ops.)</th>
						<td>
							<input type="number" min="0" name="wts_settings[default_cargo_company_id]" value="<?php echo esc_attr( $s['default_cargo_company_id'] ?? 0 ); ?>" class="small-text">
							<p class="description">V2'de bu alan resmi şemada yok; çoğu mağaza için boş bırakılır ve Trendyol panelden tanımlı kargo kullanılır. Sadece V1 davranışı gerekiyorsa doldur. (10 = Trendyol Express, 17 = Aras, 4 = Yurtiçi vs.)</p>
						</td>
					</tr>
					<tr>
						<th>Origin (ülke kodu)</th>
						<td>
							<input type="text" maxlength="2" name="wts_settings[origin_code]" value="<?php echo esc_attr( $s['origin_code'] ?? '' ); ?>" class="small-text">
							<p class="description">2 haneli ISO kodu (TR, CN, DE...). Boş = gönderilmez.</p>
						</td>
					</tr>
					<tr>
						<th>productMainId Stratejisi</th>
						<td>
							<select name="wts_settings[product_main_id_strategy]">
								<option value="parent_sku" <?php selected( $s['product_main_id_strategy'] ?? 'parent_sku', 'parent_sku' ); ?>>Parent SKU (önerilen)</option>
								<option value="parent_id"  <?php selected( $s['product_main_id_strategy'] ?? '', 'parent_id' ); ?>>WP Parent ID (WP-123)</option>
								<option value="sku"        <?php selected( $s['product_main_id_strategy'] ?? '', 'sku' ); ?>>Kendi SKU'su</option>
							</select>
							<p class="description">Varyantlı ürünlerin Trendyol'da gruplanma anahtarı. Parent SKU önerilen yöntemdir — tüm varyantlar aynı model kodu altında toplanır.</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ================================================================ PRICE PREVIEW */

	public static function page_price_preview() {
		$ids     = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		$id_list = array();
		if ( $ids ) {
			foreach ( preg_split( '/[\s,]+/', $ids ) as $x ) {
				$x = intval( $x );
				if ( $x > 0 ) {
					$id_list[] = $x;
				}
			}
		}

		?>
		<div class="wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Fiyat Hesaplama Önizleme</h1>
			<?php self::flash_notice(); ?>
			<form method="get" action="">
				<input type="hidden" name="page" value="wts-price">
				<input type="text" name="ids" value="<?php echo esc_attr( $ids ); ?>" placeholder="Örn: 123, 456, 789" style="width:400px;">
				<button class="button button-primary">Hesapla</button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-price&ids=' . self::sample_product_ids() ) ); ?>">Örnek 5 Ürün</a>
			</form>

			<?php if ( $id_list ) : $rows = WTS_Price::preview( $id_list ); ?>
				<h2 style="margin-top:20px;">Sonuçlar</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>ID</th><th>Ürün</th><th>SKU</th>
							<th>Normal Fiyat</th><th>İndirim Fiyatı</th>
							<th style="color:#0a7;">List</th><th style="color:#0a7;">Sale</th>
							<th>Hata</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) : $d = $r['debug']; ?>
							<tr>
								<td><?php echo (int) $r['id']; ?></td>
								<td>
									<?php echo esc_html( $r['name'] ); ?>
									<?php if ( ! empty( $d['parent_id'] ) ) : ?><br><small>(varyasyon, parent: <?php echo (int) $d['parent_id']; ?>)</small><?php endif; ?>
								</td>
								<td><?php echo esc_html( $r['sku'] ); ?></td>
								<td><?php echo self::fmt( $d['regular_price'] ?? null ); ?></td>
								<td><?php echo self::fmt( $d['sale_price_raw'] ?? null ); ?></td>
								<td><strong><?php echo self::fmt( $r['list_price'] ); ?></strong></td>
								<td><strong><?php echo self::fmt( $r['sale_price'] ); ?></strong></td>
								<td><?php echo $r['error'] ? '<span style="color:#b00">' . esc_html( $r['error'] ) . '</span>' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ================================================================ CATEGORIES */

	public static function page_categories() {
		global $wpdb;

		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per    = 50;

		// WP terimleri çek + filtrele + sayfala
		$all_terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $all_terms ) ) {
			$all_terms = array();
		}
		$filtered = array();
		foreach ( $all_terms as $term ) {
			if ( $search && false === stripos( $term->name, $search ) ) continue;
			$map = WTS_Categories::get_mapping( $term->term_id );
			$status = $map ? $map->match_type : 'unmatched';
			if ( 'matched'   === $filter && ! in_array( $status, array( 'auto', 'manual', 'confirmed' ), true ) ) continue;
			if ( 'suggested' === $filter && 'suggested' !== $status ) continue;
			if ( 'unmatched' === $filter && 'unmatched' !== $status && $status ) continue;
			$filtered[] = $term;
		}
		$total = count( $filtered );
		$page_terms = array_slice( $filtered, ( $paged - 1 ) * $per, $per );

		// Tüm leaf kategoriler — datalist için, KISA label ile (overflow'a karşı)
		$leaf_rows = $wpdb->get_results(
			"SELECT ty_category_id, name, path FROM {$wpdb->prefix}wts_category_cache WHERE is_leaf = 1 ORDER BY name ASC"
		);
		$leaf_count = count( $leaf_rows );

		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Kategori Eşleştirme</h1>
			<?php self::flash_notice(); ?>

			<div class="wts-stats">
				<div class="wts-stat info">
					<div class="wts-stat-label">Trendyol Kategori Cache</div>
					<div class="wts-stat-value"><?php echo (int) WTS_Categories::count_all(); ?></div>
					<div class="wts-stat-sub"><?php echo (int) $leaf_count; ?> leaf · son: <?php echo esc_html( WTS_Categories::last_sync() ?: '—' ); ?></div>
				</div>
				<div class="wts-stat ok">
					<div class="wts-stat-label">WP Kategori (toplam)</div>
					<div class="wts-stat-value"><?php echo count( $all_terms ); ?></div>
					<div class="wts-stat-sub">Filtrelenmiş gösterim: <?php echo (int) $total; ?></div>
				</div>
			</div>

			<div class="wts-step">
				<h2><span class="wts-step-num">1</span> Trendyol kategorilerini çek</h2>
				<p class="description">Tüm kategori ağacını yerel cache'e yazar. Mevcut eşleştirmeleri bozmaz.</p>
				<div class="wts-actions">
					<?php self::action_button( '<span class="dashicons dashicons-download"></span> Kategorileri Çek', 'sync_categories', array(), 'button button-primary' ); ?>
				</div>
			</div>

			<div class="wts-step">
				<h2><span class="wts-step-num">2</span> Otomatik eşleştirmeyi çalıştır</h2>
				<p class="description">İsim/path benzerliğine göre WP kategorilerini Trendyol kategorilerine otomatik bağlar. Skor &lt; 60 ise eşleşmedi sayılır.</p>
				<div class="wts-actions">
					<?php self::action_button( '<span class="dashicons dashicons-update"></span> Otomatik Eşleştir', 'rebuild_category_map', array(), 'button button-primary' ); ?>
				</div>
			</div>

			<div class="wts-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="wts-categories">
					<select name="filter">
						<option value="all"        <?php selected( $filter, 'all' ); ?>>Tümü</option>
						<option value="matched"    <?php selected( $filter, 'matched' ); ?>>Eşleşmiş</option>
						<option value="suggested"  <?php selected( $filter, 'suggested' ); ?>>Öneri Bekleyen</option>
						<option value="unmatched"  <?php selected( $filter, 'unmatched' ); ?>>Eşleşmemiş</option>
					</select>
					<input type="search" name="s" placeholder="WP kategori ara…" value="<?php echo esc_attr( $search ); ?>">
					<button class="button">Filtrele</button>
				</form>
			</div>

			<?php // Datalist — kategoriler sadece "Leaf Adı (#ID)" formatında, kısa ve aranabilir ?>
			<datalist id="wts-leaf-cats">
				<?php foreach ( $leaf_rows as $lc ) :
					// Kullanıcı yazınca "ahşap masa" gibi aratabilsin diye option label'a name veriyoruz
					$opt = $lc->name . ' (#' . (int) $lc->ty_category_id . ')';
				?>
					<option value="<?php echo esc_attr( $opt ); ?>"></option>
				<?php endforeach; ?>
			</datalist>

			<div class="wts-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>WP Kategori</th>
							<th>Trendyol Eşleşmesi</th>
							<th>Durum</th>
							<th style="min-width:480px;">İşlem</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $page_terms ) ) : ?>
							<tr><td colspan="4"><em>Sonuç yok.</em></td></tr>
						<?php else : foreach ( $page_terms as $term ) :
							$map = WTS_Categories::get_mapping( $term->term_id );
							$status = $map ? $map->match_type : 'unmatched';
							$path = WTS_Categories::wp_term_path( $term );
							$suggestions = ( $map && $map->suggestion ) ? json_decode( $map->suggestion, true ) : array();
						?>
							<tr>
								<td class="wts-prod-cell">
									<strong><?php echo esc_html( $term->name ); ?></strong>
									<?php if ( $path && $path !== $term->name ) : ?>
										<br><span class="wts-path" title="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( self::short_path( $path ) ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $map && $map->ty_category_id ) : ?>
										<span class="wts-path" title="<?php echo esc_attr( $map->ty_category_path ); ?>">
											<?php echo esc_html( self::short_path( $map->ty_category_path ) ); ?>
										</span>
										<br><small class="wts-cell-meta">ID: <?php echo (int) $map->ty_category_id; ?> · güven %<?php echo (int) $map->confidence; ?></small>
									<?php else : ?>
										<em style="color:#999;">—</em>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if     ( 'auto' === $status )      echo self::badge( 'ok',   'Otomatik', 'yes-alt' );
									elseif ( 'manual' === $status )    echo self::badge( 'ok',   'Manuel', 'admin-users' );
									elseif ( 'confirmed' === $status ) echo self::badge( 'ok',   'Onaylı', 'yes' );
									elseif ( 'suggested' === $status ) echo self::badge( 'warn', 'Öneri', 'editor-help' );
									else                               echo self::badge( 'err',  'Eşleşmedi', 'no-alt' );
									?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										  class="wts-row-form wts-cat-form">
										<?php wp_nonce_field( 'wts_action_set_category_map' ); ?>
										<input type="hidden" name="action" value="wts_action">
										<input type="hidden" name="wts_action" value="set_category_map">
										<input type="hidden" name="wp_term_id" value="<?php echo (int) $term->term_id; ?>">
										<input type="hidden" name="ty_category_id" value="">

										<?php if ( ! empty( $suggestions ) ) : ?>
											<select class="wts-cat-pick" style="max-width:220px;">
												<option value="">Öneri seç…</option>
												<?php foreach ( $suggestions as $sug ) : ?>
													<option value="<?php echo (int) $sug['ty_category_id']; ?>" <?php if ( $map && (int) $map->ty_category_id === (int) $sug['ty_category_id'] ) echo 'selected'; ?>>
														<?php echo esc_html( self::short_path( $sug['path'], 36 ) . ' (' . $sug['score'] . '%)' ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										<?php endif; ?>

										<input type="text" class="wts-cat-search wts-input-search" list="wts-leaf-cats"
											   placeholder="veya tüm kategorilerden ara…">

										<input type="number" class="wts-cat-manual wts-input-id" placeholder="veya ID" min="1">

										<button class="button button-small button-primary">Kaydet</button>

										<?php if ( $map && $map->ty_category_id ) : ?>
											</form>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'wts_action_clear_category_map' ); ?>
												<input type="hidden" name="action" value="wts_action">
												<input type="hidden" name="wts_action" value="clear_category_map">
												<input type="hidden" name="wp_term_id" value="<?php echo (int) $term->term_id; ?>">
												<button class="button-link" style="color:#b00;" title="Eşleştirmeyi kaldır">
													<span class="dashicons dashicons-trash"></span>
												</button>
											</form>
										<?php else : ?>
											</form>
										<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<?php self::paginate( $total, $per, $paged, array( 'page' => 'wts-categories', 'filter' => $filter, 's' => $search ) ); ?>

			<script>
			document.querySelectorAll( '.wts-cat-form' ).forEach( function ( f ) {
				var pick   = f.querySelector( '.wts-cat-pick' );
				var search = f.querySelector( '.wts-cat-search' );
				var manual = f.querySelector( '.wts-cat-manual' );
				var hidden = f.querySelector( 'input[name="ty_category_id"]' );

				function idFromSearch ( val ) {
					if ( ! val ) return 0;
					var m = String( val ).match( /#(\d+)\)\s*$/ );
					return m ? parseInt( m[1], 10 ) : 0;
				}
				f.addEventListener( 'submit', function ( e ) {
					var id = 0;
					if ( manual && manual.value ) id = parseInt( manual.value, 10 ) || 0;
					if ( ! id && search && search.value ) {
						id = idFromSearch( search.value );
						if ( ! id ) {
							e.preventDefault();
							alert( 'Listeden bir kategori seç (sonu "(#1234)" şeklinde olmalı), veya manuel ID gir.' );
							return;
						}
					}
					if ( ! id && pick && pick.value ) id = parseInt( pick.value, 10 ) || 0;
					if ( ! id ) {
						e.preventDefault();
						alert( 'Bir kategori seçmeden kaydedemezsin.' );
						return;
					}
					hidden.value = id;
				} );
			} );
			</script>

			<p class="description" style="margin-top:14px;">
				<strong>İpucu:</strong> Çok uzun kategori adlarının üzerine fareyle gelirsen tam yolu görürsün.
				Öneri yoksa "veya tüm kategorilerden ara" kutusuna leaf adının bir kısmını yaz.
			</p>
		</div>
		<?php
	}

	/* ================================================================ BRANDS */

	public static function page_brands() {
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per    = 50;

		$tax = WTS_Brands::detect_brand_taxonomy();
		$stats = WTS_Brands::get_map_stats();
		$brand_cache_count = WTS_Brands::count();

		// WP marka terimlerini topla (varsa) + filtrele
		$all_terms = array();
		if ( $tax ) {
			$all_terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( is_wp_error( $all_terms ) ) {
				$all_terms = array();
			}
		}
		global $wpdb;
		$map_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wts_brand_map" );
		$map_by_term = array();
		foreach ( $map_rows as $mr ) {
			$map_by_term[ (int) $mr->wp_term_id ] = $mr;
		}

		$filtered = array();
		foreach ( $all_terms as $term ) {
			if ( $search && false === stripos( $term->name, $search ) ) continue;
			$map = isset( $map_by_term[ $term->term_id ] ) ? $map_by_term[ $term->term_id ] : null;
			$is_matched = ( $map && $map->ty_brand_id );
			if ( 'matched'   === $filter && ! $is_matched ) continue;
			if ( 'unmatched' === $filter && $is_matched ) continue;
			$filtered[] = $term;
		}
		$total = count( $filtered );
		$page_terms = array_slice( $filtered, ( $paged - 1 ) * $per, $per );

		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Marka Eşleştirme</h1>
			<?php self::flash_notice(); ?>

			<?php if ( ! $tax ) : ?>
				<div class="wts-banner warn">
					Aktif bir marka taxonomy'si bulunamadı (örn. WooCommerce <code>product_brand</code>, Perfect WooCommerce Brands <code>pwb-brand</code>, YITH <code>yith_product_brand</code>).
					Önce WP'de bir marka taksonomisi etkin olmalı. Markasız ürünler için <strong>Ayarlar &gt; Varsayılan Marka ID</strong> kullanabilirsin.
				</div>
			<?php endif; ?>

			<div class="wts-stats">
				<div class="wts-stat info">
					<div class="wts-stat-label">Trendyol Marka Cache</div>
					<div class="wts-stat-value"><?php echo (int) $brand_cache_count; ?></div>
					<div class="wts-stat-sub">Son: <?php echo esc_html( WTS_Brands::last_sync() ?: '—' ); ?></div>
				</div>
				<div class="wts-stat ok">
					<div class="wts-stat-label">Eşleşmiş WP Markaları</div>
					<div class="wts-stat-value"><?php echo (int) $stats['matched']; ?></div>
					<div class="wts-stat-sub"><?php echo (int) $stats['total']; ?> toplam</div>
				</div>
				<div class="wts-stat err">
					<div class="wts-stat-label">Eşleşmemiş</div>
					<div class="wts-stat-value"><?php echo (int) $stats['unmatched']; ?></div>
					<div class="wts-stat-sub">Manuel ataman gerekiyor</div>
				</div>
			</div>

			<div class="wts-step">
				<h2><span class="wts-step-num">1</span> Trendyol markalarını çek</h2>
				<p class="description">~14.000 markayı yerel cache'e indirir. İlk seferde ~30 sn sürebilir.</p>
				<div class="wts-actions">
					<?php self::action_button( '<span class="dashicons dashicons-download"></span> Markaları Çek', 'sync_brands', array(), 'button button-primary' ); ?>
				</div>
			</div>

			<div class="wts-step">
				<h2><span class="wts-step-num">2</span> Otomatik eşleştir</h2>
				<p class="description">İsim benzerliğine göre eşleştirir. Manuel atadıklarına dokunmaz.</p>
				<div class="wts-actions">
					<?php self::action_button( '<span class="dashicons dashicons-update"></span> Otomatik Eşleştir', 'rebuild_brand_map', array(), 'button button-primary' ); ?>
				</div>
			</div>

			<div class="wts-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="wts-brands">
					<select name="filter">
						<option value="all"       <?php selected( $filter, 'all' ); ?>>Tümü</option>
						<option value="matched"   <?php selected( $filter, 'matched' ); ?>>Eşleşmiş</option>
						<option value="unmatched" <?php selected( $filter, 'unmatched' ); ?>>Eşleşmemiş</option>
					</select>
					<input type="search" name="s" placeholder="WP marka ara…" value="<?php echo esc_attr( $search ); ?>">
					<button class="button">Filtrele</button>
				</form>
			</div>

			<div class="wts-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>WP Marka</th>
							<th>Ürün</th>
							<th>Trendyol Eşleşmesi</th>
							<th>Durum</th>
							<th style="min-width:380px;">Manuel Atama</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $page_terms ) ) : ?>
							<tr><td colspan="5"><em>Sonuç yok.</em></td></tr>
						<?php else : foreach ( $page_terms as $term ) :
							$map = isset( $map_by_term[ $term->term_id ] ) ? $map_by_term[ $term->term_id ] : null;
							$status = $map ? $map->match_type : 'unmatched';
							$is_matched = ( $map && $map->ty_brand_id );
							$suggestions = WTS_Brands::suggest_for_name( $term->name, 12 );
						?>
							<tr>
								<td class="wts-prod-cell">
									<strong><?php echo esc_html( $term->name ); ?></strong>
									<br><small>#<?php echo (int) $term->term_id; ?></small>
								</td>
								<td><?php echo (int) $term->count; ?></td>
								<td>
									<?php if ( $is_matched ) : ?>
										<strong><?php echo esc_html( $map->ty_brand_name ?: '(adsız)' ); ?></strong>
										<br><small>ID: <?php echo (int) $map->ty_brand_id; ?></small>
									<?php else : ?>
										<em style="color:#999;">—</em>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if     ( 'auto' === $status )   echo self::badge( 'ok', 'Otomatik' . ( $map && (int) $map->confidence ? ' ' . (int) $map->confidence . '%' : '' ), 'yes-alt' );
									elseif ( 'manual' === $status ) echo self::badge( 'ok', 'Manuel', 'admin-users' );
									else                            echo self::badge( 'err', 'Eşleşmedi', 'no-alt' );
									?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wts-row-form">
										<?php wp_nonce_field( 'wts_action_set_brand_map' ); ?>
										<input type="hidden" name="action" value="wts_action">
										<input type="hidden" name="wts_action" value="set_brand_map">
										<input type="hidden" name="wp_term_id" value="<?php echo (int) $term->term_id; ?>">

										<?php if ( ! empty( $suggestions ) ) : ?>
											<select name="ty_brand_id" class="wts-input-search"
													onchange="this.form.querySelector('input[name=ty_brand_id_manual]').value=''">
												<option value="">Öneri seç…</option>
												<?php foreach ( $suggestions as $s ) : ?>
													<option value="<?php echo (int) $s['ty_brand_id']; ?>" <?php if ( $is_matched && (int) $map->ty_brand_id === (int) $s['ty_brand_id'] ) echo 'selected'; ?>>
														<?php echo esc_html( $s['name'] . ' (#' . $s['ty_brand_id'] . ')' ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<input type="hidden" name="ty_brand_id" value="">
											<small style="color:#888;">Öneri yok</small>
										<?php endif; ?>

										<input type="number" name="ty_brand_id_manual" placeholder="veya ID" class="wts-input-id"
											   onchange="if(this.value){var s=this.form.querySelector('select[name=ty_brand_id]'); if(s){s.value='';} this.form.querySelector('input[name=ty_brand_id]')?.remove(); var h=document.createElement('input'); h.type='hidden'; h.name='ty_brand_id'; h.value=this.value; this.form.appendChild(h);}">

										<button class="button button-small button-primary">Kaydet</button>

										<?php if ( $is_matched ) : ?>
											</form>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'wts_action_clear_brand_map' ); ?>
												<input type="hidden" name="action" value="wts_action">
												<input type="hidden" name="wts_action" value="clear_brand_map">
												<input type="hidden" name="wp_term_id" value="<?php echo (int) $term->term_id; ?>">
												<button class="button-link" style="color:#b00;" title="Kaldır">
													<span class="dashicons dashicons-trash"></span>
												</button>
											</form>
										<?php else : ?>
											</form>
										<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<?php self::paginate( $total, $per, $paged, array( 'page' => 'wts-brands', 'filter' => $filter, 's' => $search ) ); ?>

			<p class="description" style="margin-top:14px;">
				Önerilerde aradığın marka yoksa, Trendyol Satıcı Paneli &gt; Ürün Yönetimi &gt; Marka Listesi'nden ID'yi öğrenip "veya ID" kutusuna yaz.
				Markasız ürünler için Ayarlar sayfasından "Varsayılan Marka ID" tanımlayabilirsin.
			</p>
		</div>
		<?php
	}

	/* ================================================================ PRODUCTS */

	public static function page_products() {
		global $wpdb;
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per    = 50;

		$tbl = $wpdb->prefix . 'wts_product_map';
		$where_parts = array( '1=1' );
		$args = array();
		switch ( $filter ) {
			case 'unmatched':
				$where_parts[] = "( match_type = 'unmatched' OR ty_barcode LIKE 'WP\\_%' )";
				break;
			case 'synced':
				$where_parts[] = "( match_type IN ('sku','name','manual','auto') AND ty_barcode NOT LIKE 'WP\\_%' )";
				break;
		}
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_parts[] = $wpdb->prepare( '( ty_barcode LIKE %s )', $like );
		}
		$where_sql = implode( ' AND ', $where_parts );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE {$where_sql}" );
		$offset = ( $paged - 1 ) * $per;
		$rows = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE {$where_sql} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}" );

		// İlk açılış: hiç map yoksa WP ürünlerinden seed göster
		$ty_count = WTS_Products::get_ty_cache_count();
		$ty_last  = WTS_Products::get_ty_cache_last_sync();
		$stats    = WTS_Products::get_mapping_stats();

		if ( empty( $rows ) && empty( $stats['total'] ) ) {
			$wp_ids = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $per,
				'paged'          => $paged,
				'fields'         => 'ids',
				's'              => $search,
			) );
			$rows = array();
			foreach ( $wp_ids as $wid ) {
				$rows[] = (object) array(
					'wp_post_id' => $wid,
					'ty_barcode' => '',
					'match_type' => 'unmatched',
					'status'     => 'pending',
				);
			}
			// Toplam: tüm publish ürün sayısı
			$total = (int) wp_count_posts( 'product' )->publish;
		}

		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Ürün Eşleştirme</h1>
			<?php self::flash_notice(); ?>

			<div class="wts-stats">
				<div class="wts-stat info">
					<div class="wts-stat-label">Trendyol Ürün Cache</div>
					<div class="wts-stat-value"><?php echo (int) $ty_count; ?></div>
					<div class="wts-stat-sub">Son: <?php echo $ty_last ? esc_html( $ty_last ) : '—'; ?></div>
				</div>
				<div class="wts-stat ok">
					<div class="wts-stat-label">Eşleşmiş Ürünler</div>
					<div class="wts-stat-value"><?php echo (int) $stats['synced']; ?></div>
					<div class="wts-stat-sub"><?php echo (int) $stats['total']; ?> toplam</div>
				</div>
				<div class="wts-stat err">
					<div class="wts-stat-label">Eşleşmemiş</div>
					<div class="wts-stat-value"><?php echo (int) $stats['unmatched']; ?></div>
					<div class="wts-stat-sub">Aşağıdan manuel ata</div>
				</div>
				<div class="wts-stat">
					<div class="wts-stat-label">Sonraki Adım</div>
					<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
						<a class="button button-small button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-stock' ) ); ?>"><span class="dashicons dashicons-archive"></span> Stok</a>
						<a class="button button-small button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-pricing' ) ); ?>"><span class="dashicons dashicons-money-alt"></span> Fiyat</a>
					</div>
				</div>
			</div>

			<div class="wts-step">
				<h2><span class="wts-step-num">1</span> Trendyol ürünlerini cache'le</h2>
				<p class="description">Onaylı tüm Trendyol ürünlerini yerel cache tablosuna yazar. WP'ye ürün eklemez.</p>
				<div class="wts-actions">
					<?php self::action_button( '<span class="dashicons dashicons-download"></span> Trendyol Ürünlerini Çek', 'sync_ty_cache', array(), 'button button-primary' ); ?>
				</div>
			</div>

			<div class="wts-step">
				<h2><span class="wts-step-num">2</span> SKU = Barkod otomatik eşleştir</h2>
				<p class="description">WP SKU'su Trendyol barkoduyla aynı olan ürünleri otomatik bağlar. Manuel eşleştirmelere dokunmaz.</p>
				<div class="wts-actions">
					<?php self::action_button( '<span class="dashicons dashicons-update"></span> Otomatik Eşleştir', 'rebuild_product_map', array(), 'button button-primary' ); ?>
				</div>
			</div>

			<div class="wts-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="wts-products">
					<select name="filter">
						<option value="all"       <?php selected( $filter, 'all' ); ?>>Tümü</option>
						<option value="synced"    <?php selected( $filter, 'synced' ); ?>>Eşleşmiş</option>
						<option value="unmatched" <?php selected( $filter, 'unmatched' ); ?>>Eşleşmemiş</option>
					</select>
					<input type="search" name="s" placeholder="Ürün/SKU/barkod ara…" value="<?php echo esc_attr( $search ); ?>">
					<button class="button">Filtrele</button>
				</form>
			</div>

			<?php
			$ty_dl = $wpdb->get_results(
				"SELECT ty_barcode, title, product_main_id FROM {$wpdb->prefix}wts_ty_product_cache ORDER BY updated_at DESC LIMIT 2000"
			);
			?>
			<datalist id="wts-ty-products">
				<?php foreach ( $ty_dl as $td ) :
					$lbl = trim( ( $td->title ?: '' ) . ( $td->product_main_id ? ' [' . $td->product_main_id . ']' : '' ) );
				?>
					<option value="<?php echo esc_attr( $td->ty_barcode ); ?>"><?php echo esc_attr( mb_substr( $lbl, 0, 70 ) ); ?></option>
				<?php endforeach; ?>
			</datalist>

			<div class="wts-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>WP Ürün</th>
							<th>SKU</th>
							<th>Durum</th>
							<th>TY Eşleşmesi</th>
							<th style="min-width:380px;">Manuel Eşleştirme</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="5"><em>Sonuç yok. Önce TY cache'i çek + otomatik eşleştir.</em></td></tr>
						<?php else : foreach ( $rows as $r ) :
							$product = wc_get_product( $r->wp_post_id );
							if ( ! $product ) continue;
							$is_matched = ( ! empty( $r->ty_barcode ) && 'unmatched' !== $r->match_type && 0 !== strpos( $r->ty_barcode, 'WP_' ) );
							$ty_row = $is_matched ? WTS_Products::get_ty_cache_row( $r->ty_barcode ) : null;
						?>
							<tr>
								<td class="wts-prod-cell">
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $r->wp_post_id . '&action=edit' ) ); ?>" target="_blank">
										<?php echo esc_html( $product->get_name() ); ?>
									</a>
									<br><small>#<?php echo (int) $r->wp_post_id; ?></small>
								</td>
								<td><?php echo esc_html( $product->get_sku() ?: '—' ); ?></td>
								<td>
									<?php
									if ( $is_matched ) {
										$type_label = 'sku' === $r->match_type ? 'SKU' : ( 'manual' === $r->match_type ? 'Manuel' : ucfirst( (string) $r->match_type ) );
										echo self::badge( 'ok', $type_label, 'yes-alt' );
									} else {
										echo self::badge( 'err', 'Eşleşmedi', 'no-alt' );
									}
									?>
								</td>
								<td>
									<?php if ( $is_matched ) : ?>
										<small><strong><?php echo esc_html( $r->ty_barcode ); ?></strong></small>
										<?php if ( $ty_row && ! empty( $ty_row['title'] ) ) : ?>
											<br><span class="wts-path" title="<?php echo esc_attr( $ty_row['title'] ); ?>"><?php echo esc_html( mb_substr( $ty_row['title'], 0, 50 ) ); ?></span>
										<?php endif; ?>
									<?php else : ?>
										<em style="color:#999;">—</em>
									<?php endif; ?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wts-row-form">
										<?php wp_nonce_field( 'wts_action_set_product_match' ); ?>
										<input type="hidden" name="action" value="wts_action">
										<input type="hidden" name="wts_action" value="set_product_match">
										<input type="hidden" name="wp_post_id" value="<?php echo (int) $r->wp_post_id; ?>">
										<input type="text" name="ty_barcode_search" list="wts-ty-products"
											   placeholder="TY ürün ara…" class="wts-input-search">
										<input type="text" name="ty_barcode" placeholder="veya barkod" class="wts-input-narrow">
										<button class="button button-small button-primary">Bağla</button>
										<?php if ( $is_matched ) : ?>
											</form>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'wts_action_clear_product_match' ); ?>
												<input type="hidden" name="action" value="wts_action">
												<input type="hidden" name="wts_action" value="clear_product_match">
												<input type="hidden" name="wp_post_id" value="<?php echo (int) $r->wp_post_id; ?>">
												<button class="button-link" style="color:#b00;" title="Bağlantıyı kaldır">
													<span class="dashicons dashicons-trash"></span>
												</button>
											</form>
										<?php else : ?>
											</form>
										<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<?php self::paginate( $total, $per, $paged, array( 'page' => 'wts-products', 'filter' => $filter, 's' => $search ) ); ?>

			<script>
			document.querySelectorAll( 'form input[name="wts_action"][value="set_product_match"]' ).forEach( function ( inp ) {
				var form = inp.closest( 'form' );
				form.addEventListener( 'submit', function ( e ) {
					var manual = form.querySelector( 'input[name="ty_barcode"]' );
					var search = form.querySelector( 'input[name="ty_barcode_search"]' );
					var val = (manual && manual.value.trim()) || (search && search.value.trim()) || '';
					if ( ! val ) {
						e.preventDefault();
						alert( 'Bir Trendyol barkodu seç veya yaz.' );
						return;
					}
					if ( manual ) manual.value = val;
				} );
			} );
			</script>
		</div>
		<?php
	}

	/* ================================================================ STOCK */

	public static function page_stock() {
		global $wpdb;
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'matched';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per    = 50;
		$direction = WTS_Settings::get( 'sync_direction', 'bidirectional' );

		$where_parts = array( "ty_barcode NOT LIKE 'WP\\_%'", "match_type != 'unmatched'" );
		if ( 'mismatch' === $filter ) {
			$where_parts[] = "last_wp_stock IS NOT NULL AND last_ty_stock IS NOT NULL AND last_wp_stock != last_ty_stock";
		}
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_parts[] = $wpdb->prepare( '( ty_barcode LIKE %s )', $like );
		}
		$where_sql = implode( ' AND ', $where_parts );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wts_product_map WHERE {$where_sql}" );
		$offset = ( $paged - 1 ) * $per;
		$map_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wts_product_map WHERE {$where_sql} ORDER BY last_synced_at DESC LIMIT {$per} OFFSET {$offset}" );

		$dir_labels = array( 'wp_to_ty' => '→ WP→TY', 'ty_to_wp' => '← TY→WP', 'bidirectional' => '↔ Çift yön' );
		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Stok Yönetimi</h1>
			<?php self::flash_notice(); ?>

			<div class="wts-banner">
				<strong><span class="dashicons dashicons-info"></span> Otomatik:</strong>
				WP'de bir ürünün stoğu değişince hook 5 saniye içinde Trendyol'a otomatik push'lar.
				Manuel toplu senkron için aşağıdaki butonlar. Mevcut senkron yönü: <strong><?php echo esc_html( $dir_labels[ $direction ] ?? $direction ); ?></strong>
				· <a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-settings' ) ); ?>">Ayarlardan değiştir</a>
			</div>

			<div class="wts-actions" style="margin:14px 0;">
				<?php self::action_button( '<span class="dashicons dashicons-update"></span> Çift Yön Senkronla', 'sync_stock', array( 'direction' => 'bidirectional' ), 'button button-primary' ); ?>
				<?php self::action_button( '<span class="dashicons dashicons-arrow-right-alt"></span> WP → Trendyol', 'sync_stock', array( 'direction' => 'wp_to_ty' ) ); ?>
				<?php self::action_button( '<span class="dashicons dashicons-arrow-left-alt"></span> Trendyol → WP', 'sync_stock', array( 'direction' => 'ty_to_wp' ) ); ?>
				<?php self::action_button( '<span class="dashicons dashicons-download"></span> Trendyol Cache\'ini Tazele', 'sync_ty_cache' ); ?>
			</div>

			<div class="wts-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="wts-stock">
					<select name="filter">
						<option value="matched"  <?php selected( $filter, 'matched' ); ?>>Tüm Eşleşmişler</option>
						<option value="mismatch" <?php selected( $filter, 'mismatch' ); ?>>WP ≠ TY (uyuşmazlık)</option>
					</select>
					<input type="search" name="s" placeholder="Ürün/SKU/barkod ara…" value="<?php echo esc_attr( $search ); ?>">
					<button class="button">Filtrele</button>
				</form>
			</div>

			<div class="wts-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>WP Ürün</th>
							<th>SKU / Barkod</th>
							<th>WP Stok</th>
							<th>TY Stok</th>
							<th>Son Senkron</th>
							<th>Yeni WP Stok</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $map_rows ) ) : ?>
							<tr><td colspan="6"><em>Henüz eşleşmiş ürün yok. Önce <a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-products' ) ); ?>">Ürün Eşleştirme</a> sayfasından bağla.</em></td></tr>
						<?php else : foreach ( $map_rows as $r ) :
							$product = wc_get_product( $r->wp_post_id );
							if ( ! $product ) continue;
							if ( $search ) {
								$haystack = strtolower( $product->get_name() . ' ' . $product->get_sku() . ' ' . $r->ty_barcode );
								if ( false === strpos( $haystack, strtolower( $search ) ) ) continue;
							}
							$wp_stock = (int) $product->get_stock_quantity();
							$ty_row   = WTS_Products::get_ty_cache_row( $r->ty_barcode );
							$ty_stock = null;
							if ( $ty_row && isset( $ty_row['quantity'] ) && null !== $ty_row['quantity'] ) {
								$ty_stock = (int) $ty_row['quantity'];
							} elseif ( null !== $r->last_ty_stock ) {
								// Cache henüz çekilmemiş ama mapping tablosunda son senkron snapshot'ı var.
								$ty_stock = (int) $r->last_ty_stock;
							}
							$diff     = ( null !== $ty_stock ) ? $wp_stock - $ty_stock : null;
						?>
							<tr>
								<td class="wts-prod-cell">
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $r->wp_post_id . '&action=edit' ) ); ?>" target="_blank">
										<?php echo esc_html( $product->get_name() ); ?>
									</a>
								</td>
								<td>
									<small><?php echo esc_html( $product->get_sku() ?: '—' ); ?></small>
									<br><small style="color:#888;"><?php echo esc_html( $r->ty_barcode ); ?></small>
								</td>
								<td style="font-weight:600;font-size:14px;"><?php echo (int) $wp_stock; ?></td>
								<td>
									<?php if ( null !== $ty_stock ) : ?>
										<span style="font-weight:600;font-size:14px;"><?php echo (int) $ty_stock; ?></span>
										<?php if ( $diff !== 0 ) : ?>
											<br><?php echo self::badge( 'err', ( $diff > 0 ? '+' : '' ) . $diff . ' fark', 'warning' ); ?>
										<?php else : ?>
											<br><?php echo self::badge( 'ok', 'eşit', 'yes' ); ?>
										<?php endif; ?>
									<?php else : ?>
										<em style="color:#999;">—</em>
									<?php endif; ?>
								</td>
								<td><small><?php echo esc_html( $r->last_synced_at ?: '—' ); ?></small></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wts-row-form">
										<?php wp_nonce_field( 'wts_action_update_wp_stock' ); ?>
										<input type="hidden" name="action" value="wts_action">
										<input type="hidden" name="wts_action" value="update_wp_stock">
										<input type="hidden" name="wp_post_id" value="<?php echo (int) $r->wp_post_id; ?>">
										<input type="number" name="new_stock" value="<?php echo (int) $wp_stock; ?>" min="0" class="wts-input-stock">
										<button class="button button-small button-primary">
											<span class="dashicons dashicons-upload"></span>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<?php self::paginate( $total, $per, $paged, array( 'page' => 'wts-stock', 'filter' => $filter, 's' => $search ) ); ?>
		</div>
		<?php
	}

	/* ================================================================ PRICING */

	public static function page_pricing() {
		global $wpdb;
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'matched';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per    = 50;

		$where_parts = array( "ty_barcode NOT LIKE 'WP\\_%'", "match_type != 'unmatched'" );
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_parts[] = $wpdb->prepare( '( ty_barcode LIKE %s )', $like );
		}
		$where_sql = implode( ' AND ', $where_parts );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wts_product_map WHERE {$where_sql}" );
		$offset = ( $paged - 1 ) * $per;
		$map_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wts_product_map WHERE {$where_sql} ORDER BY last_synced_at DESC LIMIT {$per} OFFSET {$offset}" );

		$computed = array();
		foreach ( $map_rows as $r ) {
			$product = wc_get_product( $r->wp_post_id );
			if ( ! $product ) continue;
			if ( $search ) {
				$haystack = strtolower( $product->get_name() . ' ' . $product->get_sku() . ' ' . $r->ty_barcode );
				if ( false === strpos( $haystack, strtolower( $search ) ) ) continue;
			}
			$p = WTS_Price::calculate( $product );
			$computed[] = array( 'row' => $r, 'product' => $product, 'price' => $p );
		}
		if ( 'mismatch' === $filter ) {
			$computed = array_filter( $computed, function ( $c ) {
				$row = $c['row'];
				$ty_price = ( null !== $row->last_ty_price ) ? floatval( $row->last_ty_price ) : null;
				$wp_price = $c['price']['sale_price'] ?? null;
				return ( null !== $ty_price && null !== $wp_price && abs( $ty_price - $wp_price ) >= 0.5 );
			} );
		}
		$default_currency = wts_default_currency();
		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Fiyat Yönetimi</h1>
			<?php self::flash_notice(); ?>

			<div class="wts-banner">
				<strong><span class="dashicons dashicons-info"></span> Akış:</strong>
				WooCommerce ürün fiyatları <em>olduğu gibi</em> Trendyol'a gönderilir.
				Kur çevirimi, KDV ekleme, yuvarlama ve markup uygulanmaz.
				· <a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-settings' ) ); ?>">Ayarlar</a>
			</div>

			<div class="wts-actions" style="margin:14px 0;">
				<?php self::action_button( '<span class="dashicons dashicons-upload"></span> Tüm Fiyatları Trendyol\'a Push\'la', 'push_all_prices', array(), 'button button-primary' ); ?>
				<?php self::action_button( '<span class="dashicons dashicons-download"></span> Trendyol Cache\'ini Tazele', 'sync_ty_cache' ); ?>
			</div>

			<div class="wts-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="wts-pricing">
					<select name="filter">
						<option value="matched"  <?php selected( $filter, 'matched' ); ?>>Tüm Eşleşmişler</option>
						<option value="mismatch" <?php selected( $filter, 'mismatch' ); ?>>WP TL ≠ TY (fark var)</option>
					</select>
					<input type="search" name="s" placeholder="Ürün/SKU/barkod ara…" value="<?php echo esc_attr( $search ); ?>">
					<button class="button">Filtrele</button>
				</form>
			</div>

			<div class="wts-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>WP Ürün</th>
							<th>WooCommerce Fiyatı</th>
							<th>Trendyol'a Gidecek<br><small style="font-weight:normal;color:#888;">(aynı fiyat)</small></th>
							<th>TY Mevcut<br><small style="font-weight:normal;color:#888;">(cache)</small></th>
							<th>Fark</th>
							<th>Yeni Fiyat</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $computed ) ) : ?>
							<tr><td colspan="6"><em>Henüz eşleşmiş ürün yok ya da filtre boş döndü.</em></td></tr>
						<?php else : foreach ( $computed as $c ) :
							$r       = $c['row'];
							$product = $c['product'];
							$pdata   = $c['price'];
							$dbg     = $pdata['debug'] ?? array();

							$raw_regular = $dbg['regular_price']  ?? null;
							$raw_sale    = $dbg['sale_price_raw'] ?? null;
							$try_round   = $pdata['sale_price']   ?? null;
							$list_try    = $pdata['list_price']   ?? null;

							$ty_price = ( null !== $r->last_ty_price ) ? floatval( $r->last_ty_price ) : null;
							if ( $ty_price === null ) {
								$tyrow = WTS_Products::get_ty_cache_row( $r->ty_barcode );
								if ( $tyrow && isset( $tyrow['sale_price'] ) ) {
									$ty_price = floatval( $tyrow['sale_price'] );
								}
							}
							$diff = ( null !== $ty_price && null !== $try_round ) ? ( $try_round - $ty_price ) : null;
						?>
							<tr>
								<td class="wts-prod-cell">
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $r->wp_post_id . '&action=edit' ) ); ?>" target="_blank">
										<?php echo esc_html( $product->get_name() ); ?>
									</a>
									<br><small><?php echo esc_html( $product->get_sku() ?: '#' . $r->wp_post_id ); ?> · <?php echo esc_html( $r->ty_barcode ); ?></small>
								</td>
								<td>
									<?php if ( null !== $raw_sale && $raw_sale > 0 ) : ?>
										<s style="color:#888;"><?php echo esc_html( number_format( (float) $raw_regular, 2 ) ); ?></s>
										<br><strong><?php echo esc_html( number_format( (float) $raw_sale, 2 ) ); ?></strong>
									<?php else : ?>
										<strong><?php echo esc_html( number_format( (float) $raw_regular, 2 ) ); ?></strong>
									<?php endif; ?>
									<small style="color:#888;"> ₺</small>
								</td>
								<td>
									<?php if ( null !== $try_round ) : ?>
										<strong style="font-size:14px;"><?php echo esc_html( number_format( (float) $try_round, 2 ) ); ?> ₺</strong>
										<?php if ( null !== $list_try && $list_try > $try_round ) : ?>
											<br><small style="color:#888;">liste: <?php echo esc_html( number_format( (float) $list_try, 2 ) ); ?> ₺</small>
										<?php endif; ?>
									<?php elseif ( ! empty( $pdata['error'] ) ) : ?>
										<?php echo self::badge( 'err', $pdata['error'], 'warning' ); ?>
									<?php else : ?>
										<em style="color:#999;">—</em>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( null !== $ty_price ) : ?>
										<strong style="font-size:14px;"><?php echo esc_html( number_format( (float) $ty_price, 2 ) ); ?> ₺</strong>
									<?php else : ?>
										<em style="color:#999;">—</em>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( null !== $diff && abs( $diff ) >= 0.5 ) : ?>
										<?php echo self::badge( 'err', ( $diff > 0 ? '+' : '' ) . number_format( $diff, 2 ) . ' ₺', 'warning' ); ?>
									<?php elseif ( null !== $diff ) : ?>
										<?php echo self::badge( 'ok', 'eşit', 'yes' ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wts-row-form" style="flex-direction:column;align-items:flex-start;">
										<?php wp_nonce_field( 'wts_action_update_wp_price' ); ?>
										<input type="hidden" name="action" value="wts_action">
										<input type="hidden" name="wts_action" value="update_wp_price">
										<input type="hidden" name="wp_post_id" value="<?php echo (int) $r->wp_post_id; ?>">
										<div style="display:flex;gap:4px;align-items:center;">
											<input type="number" step="0.01" min="0" name="regular_price"
												   placeholder="Normal"
												   value="<?php echo esc_attr( null !== $raw_regular ? number_format( (float) $raw_regular, 2, '.', '' ) : '' ); ?>"
												   class="wts-input-price" title="Normal fiyat">
											<input type="number" step="0.01" min="0" name="sale_price"
												   placeholder="İndirim"
												   value="<?php echo esc_attr( null !== $raw_sale ? number_format( (float) $raw_sale, 2, '.', '' ) : '' ); ?>"
												   class="wts-input-price" title="İndirimli fiyat (opsiyonel)">
										</div>
										<button class="button button-small button-primary"><span class="dashicons dashicons-upload"></span> Kaydet & Push</button>
									</form>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<?php self::paginate( $total, $per, $paged, array( 'page' => 'wts-pricing', 'filter' => $filter, 's' => $search ) ); ?>
		</div>
		<?php
	}

	/* ================================================================ SYNC & CRON */

	public static function page_sync() {
		$next  = WTS_Cron::next_runs();
		$last_slow = get_option( 'wts_cron_slow_last_run', '—' );
		$webhook_url = WTS_Webhook::get_webhook_url();

		?>
		<div class="wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Senkron & Cron</h1>
			<?php self::flash_notice(); ?>

			<h2>Cron Durumu</h2>
			<table class="widefat" style="max-width:680px;">
				<tr><th>Hızlı Cron</th><td>Sonraki: <?php echo $next['fast'] ? esc_html( date_i18n( 'Y-m-d H:i:s', $next['fast'] ) ) : '—'; ?></td></tr>
				<tr><th>Yavaş Cron</th><td>Sonraki: <?php echo $next['slow'] ? esc_html( date_i18n( 'Y-m-d H:i:s', $next['slow'] ) ) : '—'; ?>, son: <?php echo esc_html( $last_slow ); ?></td></tr>
			</table>

			<h2>Manuel Tetikleyiciler</h2>
			<p>
				<?php self::action_button( 'Hızlı Cron\'u Şimdi Çalıştır', 'run_cron_fast' ); ?>
				<?php self::action_button( 'Yavaş Cron\'u Şimdi Çalıştır', 'run_cron_slow', array(), 'button button-primary' ); ?>
				<?php self::action_button( 'Bekleyen Batch\'leri Kontrol Et', 'check_batches' ); ?>
			</p>

			<h2>Stok Senkronu (Manuel)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wts_action_sync_stock' ); ?>
				<input type="hidden" name="action" value="wts_action">
				<input type="hidden" name="wts_action" value="sync_stock">
				<select name="direction">
					<option value="">Ayardan al (<?php echo esc_html( WTS_Settings::get( 'sync_direction' ) ); ?>)</option>
					<option value="wp_to_ty">WP → Trendyol</option>
					<option value="ty_to_wp">Trendyol → WP</option>
					<option value="bidirectional">Çift yön (delta)</option>
				</select>
				<button class="button button-primary">Stoğu Senkronla</button>
			</form>

			<h2>Sipariş Çekme (Manuel)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wts_action_pull_orders' ); ?>
				<input type="hidden" name="action" value="wts_action">
				<input type="hidden" name="wts_action" value="pull_orders">
				Son
				<input type="number" name="days" value="7" min="1" max="90" class="small-text"> gün
				<button class="button button-primary">Siparişleri Çek</button>
			</form>

			<h2>Webhook</h2>
			<table class="widefat" style="max-width:920px;">
				<tr>
					<th style="width:160px;">URL</th>
					<td><code style="word-break:break-all;"><?php echo esc_html( $webhook_url ); ?></code></td>
				</tr>
			</table>
			<p>
				<?php self::action_button( 'Webhook Secret\'ı Yenile', 'regenerate_webhook_secret', array(), 'button-secondary' ); ?>
				<span class="description">Bu URL'i Trendyol Satıcı Paneli'nde webhook olarak tanımlayın (veya `createWebhook` endpoint'i ile programatik).</span>
			</p>
		</div>
		<?php
	}

	/* ================================================================ REPORTS */

	public static function page_reports() {
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : date( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( $_GET['to'] ) )   : date( 'Y-m-d' );

		$totals = WTS_Orders::totals_by_source( $from, $to );
		$top    = WTS_Orders::top_products( $from, $to, 30 );
		$daily  = WTS_Orders::daily_series( $from, $to );

		// Daily series'i Chart.js için hazırla
		$days   = array();
		$by_src = array( 'wp' => array(), 'trendyol' => array() );
		foreach ( $daily as $r ) {
			$days[ $r->day ] = $r->day;
		}
		ksort( $days );
		$labels = array_values( $days );
		foreach ( $labels as $d ) {
			$by_src['wp'][ $d ]       = 0;
			$by_src['trendyol'][ $d ] = 0;
		}
		foreach ( $daily as $r ) {
			$by_src[ $r->source ][ $r->day ] = floatval( $r->revenue );
		}

		?>
		<div class="wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Satış Raporu</h1>
			<?php self::flash_notice(); ?>

			<form method="get" style="margin-bottom:18px;">
				<input type="hidden" name="page" value="wts-reports">
				Tarih:
				<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>">
				<input type="date" name="to"   value="<?php echo esc_attr( $to ); ?>">
				<button class="button">Filtrele</button>
				<?php self::action_button( 'Siparişleri Şimdi Çek', 'pull_orders', array( 'days' => 30 ), 'button-secondary' ); ?>
			</form>

			<h2>Platform Kırılımı</h2>
			<table class="widefat" style="max-width:680px;">
				<thead><tr><th>Platform</th><th>Sipariş</th><th>Adet</th><th>Ciro</th></tr></thead>
				<tbody>
				<?php if ( empty( $totals ) ) : ?>
					<tr><td colspan="4"><em>Veri yok. Önce siparişleri çekin.</em></td></tr>
				<?php else : foreach ( $totals as $t ) : ?>
					<tr>
						<td><?php echo esc_html( strtoupper( $t->source ) ); ?></td>
						<td><?php echo (int) $t->orders; ?></td>
						<td><?php echo (int) $t->qty; ?></td>
						<td><?php echo self::fmt( $t->total ); ?> TL</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px;">Günlük Ciro</h2>
			<canvas id="wts-daily-chart" height="100" style="max-width:980px;background:#fff;border:1px solid #e2e4e7;border-radius:6px;padding:14px;"></canvas>

			<h2 style="margin-top:24px;">En Çok Satan Ürünler (Top 30)</h2>
			<table class="widefat striped">
				<thead><tr><th>Ürün</th><th>SKU</th><th>WP Adet</th><th>Trendyol Adet</th><th>Toplam</th><th>Ciro</th></tr></thead>
				<tbody>
				<?php if ( empty( $top ) ) : ?>
					<tr><td colspan="6"><em>Veri yok.</em></td></tr>
				<?php else : foreach ( $top as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->product_name ); ?></td>
						<td><?php echo esc_html( $r->ty_barcode ); ?></td>
						<td><?php echo (int) $r->qty_wp; ?></td>
						<td><?php echo (int) $r->qty_ty; ?></td>
						<td><strong><?php echo (int) $r->qty_total; ?></strong></td>
						<td><?php echo self::fmt( $r->revenue ); ?> TL</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<script>
			document.addEventListener('DOMContentLoaded', function(){
				if ( typeof Chart === 'undefined' ) return;
				const ctx = document.getElementById('wts-daily-chart');
				if ( ! ctx ) return;
				new Chart(ctx, {
					type: 'bar',
					data: {
						labels: <?php echo wp_json_encode( $labels ); ?>,
						datasets: [
							{ label: 'WP',       data: <?php echo wp_json_encode( array_values( $by_src['wp'] ) ); ?>,       backgroundColor: '#2271b1' },
							{ label: 'Trendyol', data: <?php echo wp_json_encode( array_values( $by_src['trendyol'] ) ); ?>, backgroundColor: '#f27a1a' }
						]
					},
					options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
				});
			});
			</script>
		</div>
		<?php
	}

	/* ================================================================ LOGS */

	/* ================================================================ ADDRESSES */

	public static function page_addresses() {
		$rows = WTS_Addresses::all();
		$last = WTS_Addresses::last_sync();
		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Trendyol Adresleri</h1>
			<?php self::flash_notice(); ?>

			<div class="wts-banner">
				<strong><span class="dashicons dashicons-info"></span> Bilgi:</strong>
				<code>getSuppliersAddresses</code> endpoint'inden çekilen mağaza sevkiyat/iade adresleri.
				createProducts payload'unda <code>shipmentAddressId</code> ve <code>returningAddressId</code>
				için bu listeden bir tane seçilir. Ayarlar &gt; "Trendyol Ürün Gönderim Varsayılanları" bölümünden default'u belirle.
				<br><small>Son senkron: <?php echo $last ? esc_html( $last ) : '—'; ?></small>
			</div>

			<div class="wts-actions" style="margin:14px 0;">
				<?php self::action_button( '<span class="dashicons dashicons-update"></span> Adresleri Trendyol\'dan Çek', 'sync_addresses', array(), 'button button-primary' ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-settings' ) ); ?>">Ayarlara Git → Default Seç</a>
			</div>

			<div class="wts-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>ID</th>
							<th>İsim</th>
							<th>Tip</th>
							<th>Default Sevkiyat</th>
							<th>Default İade</th>
							<th>Şehir / İlçe</th>
							<th>Adres</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7"><em>Henüz adres çekilmedi. "Adresleri Trendyol'dan Çek" butonuna bas.</em></td></tr>
						<?php else : foreach ( $rows as $r ) : ?>
							<tr>
								<td><strong><?php echo (int) $r->ty_address_id; ?></strong></td>
								<td><?php echo esc_html( $r->present_name ?: '—' ); ?></td>
								<td><?php echo esc_html( $r->address_type ?: '—' ); ?></td>
								<td><?php echo $r->is_default_shipment ? '✅' : '—'; ?></td>
								<td><?php echo $r->is_default_returning ? '✅' : '—'; ?></td>
								<td><?php echo esc_html( trim( ( $r->city ?: '' ) . ' / ' . ( $r->district ?: '' ), ' /' ) ?: '—' ); ?></td>
								<td style="max-width:380px;"><small><?php echo esc_html( $r->full_address ?: '—' ); ?></small></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/* ================================================================ PUSH (Trendyol'a Gönder) */

	/**
	 * Push sayfasının filtre parametrelerinden WP_Query args üretir.
	 * Hem sayfalı tablo, hem "filtreye uyan TÜMÜNÜ gönder" akışı bunu kullanır.
	 *
	 * @param array $src   $_GET veya $_POST (sanitize edilecek).
	 * @param int   $per   posts_per_page (-1 = sınırsız).
	 * @param int   $paged 1+ (her zaman geçerli sayı, -1 ile birlikte yok sayılır).
	 * @return array WP_Query args.
	 */
	private static function build_push_query_args( $src, $per = 40, $paged = 1 ) {
		global $wpdb;

		$search   = isset( $src['s'] ) ? sanitize_text_field( wp_unslash( $src['s'] ) ) : '';
		$filter   = isset( $src['filter'] ) ? sanitize_key( $src['filter'] ) : 'unsent';
		$cat_id   = isset( $src['cat'] ) ? (int) $src['cat'] : 0;
		$brand_id = isset( $src['brand'] ) ? (int) $src['brand'] : 0;
		$price_f  = isset( $src['price'] ) ? sanitize_key( $src['price'] ) : 'any';

		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $per,
			'paged'          => max( 1, (int) $paged ),
			's'              => $search,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Gönderim durumuna göre filtre
		if ( 'sent' === $filter || 'error' === $filter ) {
			$status = 'sent' === $filter ? "status = 'synced'" : "status = 'error'";
			$sent_ids = $wpdb->get_col( "SELECT wp_post_id FROM {$wpdb->prefix}wts_product_map WHERE {$status} AND ty_barcode NOT LIKE 'WP\\_%'" );
			if ( ! empty( $sent_ids ) ) {
				$args['post__in'] = array_map( 'intval', $sent_ids );
			} else {
				$args['post__in'] = array( 0 );
			}
		} elseif ( 'unsent' === $filter ) {
			$sent_ids = $wpdb->get_col( "SELECT wp_post_id FROM {$wpdb->prefix}wts_product_map WHERE status IN ('synced','syncing') AND ty_barcode NOT LIKE 'WP\\_%'" );
			if ( ! empty( $sent_ids ) ) {
				$args['post__not_in'] = array_map( 'intval', $sent_ids );
			}
		}

		// Kategori & marka tax_query
		$tax_query = array();
		if ( $cat_id > 0 ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array( $cat_id ),
			);
		}
		if ( $brand_id > 0 ) {
			$brand_tax = WTS_Brands::detect_brand_taxonomy();
			if ( $brand_tax ) {
				$tax_query[] = array(
					'taxonomy' => $brand_tax,
					'field'    => 'term_id',
					'terms'    => array( $brand_id ),
				);
			}
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		// Fiyat filtresi (_price meta'sı üzerinden — variable ürünlerin min varyant fiyatı da burada olur)
		if ( 'has_price' === $price_f ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_price',
					'value'   => '',
					'compare' => '!=',
				),
				array(
					'key'     => '_price',
					'value'   => '0',
					'compare' => '!=',
				),
			);
		} elseif ( 'no_price' === $price_f ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_price',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_price',
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => '_price',
					'value'   => '0',
					'compare' => '=',
				),
			);
		}

		return $args;
	}

	/**
	 * Filtreye uyan TÜM gönderilebilir ürün ID'lerini döner (sayfalama yok, güvenli üst sınır var).
	 *
	 * Eşleştirme/marka eksik olanlar zaten push_products içinde "skipped" olur,
	 * ama burada tablo UI'ı ile tutarlı olsun diye is_pushable filtresini uygularız.
	 *
	 * @param array $src           $_POST/$_GET.
	 * @param int   $max           Geri döndürülecek pushable ID'nin üst sınırı (early-exit için).
	 * @param int   $scan_max      DB'den tarayacağımız maks. ürün sayısı (1462 ürün için 1462'den fazla istemeyiz).
	 * @return array<int> WP post ID'leri.
	 */
	private static function query_push_ids_by_filter( $src, $max = 50, $scan_max = 1000 ) {
		$args = self::build_push_query_args( $src, $scan_max, 1 );
		$args['fields'] = 'ids';
		$q = new WP_Query( $args );
		$ids = array_map( 'intval', (array) $q->posts );
		if ( empty( $ids ) ) {
			return array();
		}
		// Gönderilemez olanları (zaten gönderilmiş veya kategori/marka eksik) ele —
		// $max'a ulaştığımız anda dur, böylece 1000+ ürün için bile hızlı.
		$pushable = array();
		global $wpdb;
		foreach ( $ids as $pid ) {
			if ( count( $pushable ) >= $max ) {
				break;
			}
			$map_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT status, ty_barcode FROM {$wpdb->prefix}wts_product_map WHERE wp_post_id = %d",
				$pid
			) );
			$is_sent = ( $map_row && in_array( $map_row->status, array( 'synced', 'syncing' ), true ) && 0 !== strpos( $map_row->ty_barcode, 'WP_' ) );
			if ( $is_sent ) continue;

			$ty_cat = WTS_Categories::resolve_ty_category_for_product( $pid );
			if ( ! $ty_cat ) continue;

			$product = wc_get_product( $pid );
			if ( ! $product ) continue;

			$brand_id = WTS_Brands::resolve_ty_brand_for_product( $product );
			if ( ! $brand_id ) continue;

			$pushable[] = $pid;
		}
		return $pushable;
	}

	public static function page_push() {
		global $wpdb;
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter    = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'unsent';
		$cat_id    = isset( $_GET['cat'] ) ? (int) $_GET['cat'] : 0;
		$brand_id  = isset( $_GET['brand'] ) ? (int) $_GET['brand'] : 0;
		$price_f   = isset( $_GET['price'] ) ? sanitize_key( $_GET['price'] ) : 'any';
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per       = 40;
		$preview   = isset( $_GET['preview'] ) ? (int) $_GET['preview'] : 0;

		// Önizleme istendiyse transient'tan al
		$preview_data = null;
		if ( $preview ) {
			$preview_data = get_transient( 'wts_payload_preview_' . $preview );
		}

		// WP_Query args helper üzerinden
		$args     = self::build_push_query_args( $_GET, $per, $paged );
		$query    = new WP_Query( $args );
		$total    = (int) $query->found_posts;
		$products = $query->posts;

		// Kategori & marka dropdown verisi (sadece push sayfasında ürün taşıyan terimler)
		$cat_terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'name',
		) );
		$brand_tax   = WTS_Brands::detect_brand_taxonomy();
		$brand_terms = array();
		if ( $brand_tax ) {
			$brand_terms = get_terms( array(
				'taxonomy'   => $brand_tax,
				'hide_empty' => true,
				'orderby'    => 'name',
			) );
			if ( is_wp_error( $brand_terms ) ) {
				$brand_terms = array();
			}
		}

		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Trendyol'a Gönder</h1>
			<?php self::flash_notice(); ?>

			<div class="wts-banner">
				<strong><span class="dashicons dashicons-megaphone"></span> Nasıl çalışır:</strong>
				Aşağıdan ürünleri tek tek seçip "Seçilenleri Trendyol'a Gönder" diyebilir, ya da bir filtre uygulayıp "Filtredekilerin Tümünü Gönder" ile toplu gönderebilirsin.
				Varyantlı ürünlerin tüm varyantları otomatik birlikte gönderilir. Trendyol onayı genellikle 1-30 saniye içinde tamamlanır.
				<br>Gönderim öncesi: ✅ Marka eşleştirme, ✅ Kategori eşleştirme, ✅ Trendyol Adresleri çekilmeli, ✅ Görsel HTTPS olmalı.
			</div>

			<?php if ( $preview_data ) : ?>
				<div class="notice notice-info" style="padding:14px;margin:14px 0;">
					<h3 style="margin-top:0;">Payload Önizleme — Ürün #<?php echo (int) $preview; ?></h3>
					<?php if ( ! empty( $preview_data['success'] ) ) : ?>
						<p>
							<strong>✅ Gönderime hazır.</strong>
							<?php echo count( $preview_data['items'] ); ?> item üretildi
							(<?php echo count( $preview_data['items'] ) > 1 ? 'varyantlı ürün' : 'tekli ürün'; ?>),
							parentMainId: <code><?php echo esc_html( $preview_data['parent_sku'] ); ?></code>.
						</p>
						<?php if ( ! empty( $preview_data['warning'] ) ) : ?>
							<p style="color:#d80;"><strong>Uyarı:</strong> <?php echo esc_html( $preview_data['warning'] ); ?></p>
						<?php endif; ?>
						<details>
							<summary style="cursor:pointer;color:#06c;font-weight:600;">JSON payload göster</summary>
							<pre style="background:#1d2327;color:#c8e1ff;padding:14px;border-radius:6px;overflow:auto;max-height:420px;font-size:11px;"><?php echo esc_html( wp_json_encode( array( 'items' => $preview_data['items'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</details>
					<?php else : ?>
						<p style="color:#b00;"><strong>❌ Gönderilemez:</strong> <?php echo esc_html( $preview_data['error'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="wts-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="wts-push">
					<select name="filter" title="Gönderim durumu">
						<option value="all"     <?php selected( $filter, 'all' ); ?>>Tüm Ürünler</option>
						<option value="unsent"  <?php selected( $filter, 'unsent' ); ?>>Henüz Gönderilmemiş</option>
						<option value="sent"    <?php selected( $filter, 'sent' ); ?>>Gönderilmiş</option>
						<option value="error"   <?php selected( $filter, 'error' ); ?>>Hatalı</option>
					</select>

					<select name="cat" title="Kategori">
						<option value="0">— Tüm kategoriler —</option>
						<?php foreach ( $cat_terms as $t ) :
							if ( is_wp_error( $t ) ) continue; ?>
							<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $cat_id, (int) $t->term_id ); ?>>
								<?php echo esc_html( $t->name ); ?> (<?php echo (int) $t->count; ?>)
							</option>
						<?php endforeach; ?>
					</select>

					<?php if ( ! empty( $brand_terms ) ) : ?>
						<select name="brand" title="Marka">
							<option value="0">— Tüm markalar —</option>
							<?php foreach ( $brand_terms as $t ) : ?>
								<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $brand_id, (int) $t->term_id ); ?>>
									<?php echo esc_html( $t->name ); ?> (<?php echo (int) $t->count; ?>)
								</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input type="hidden" name="brand" value="0">
					<?php endif; ?>

					<select name="price" title="Fiyat durumu">
						<option value="any"       <?php selected( $price_f, 'any' ); ?>>— Fiyat farketmez —</option>
						<option value="has_price" <?php selected( $price_f, 'has_price' ); ?>>Fiyatı olan ürünler</option>
						<option value="no_price"  <?php selected( $price_f, 'no_price' ); ?>>Fiyatı olmayan ürünler</option>
					</select>

					<input type="search" name="s" placeholder="Ürün adı / SKU ara..." value="<?php echo esc_attr( $search ); ?>">
					<button class="button">Filtrele</button>
					<?php if ( $cat_id || $brand_id || 'any' !== $price_f || $search || 'unsent' !== $filter ) : ?>
						<a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wts-push' ) ); ?>">Sıfırla</a>
					<?php endif; ?>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wts-push-form">
				<?php wp_nonce_field( 'wts_action_push_selected' ); ?>
				<input type="hidden" name="action" value="wts_action">
				<input type="hidden" name="wts_action" value="push_selected">
				<input type="hidden" name="bulk_mode" id="wts-bulk-mode" value="selected">
				<!-- Filtre değerlerini POST'a taşı, "filtredekilerin tümünü gönder" işlemi için -->
				<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
				<input type="hidden" name="cat"    value="<?php echo (int) $cat_id; ?>">
				<input type="hidden" name="brand"  value="<?php echo (int) $brand_id; ?>">
				<input type="hidden" name="price"  value="<?php echo esc_attr( $price_f ); ?>">
				<input type="hidden" name="s"      value="<?php echo esc_attr( $search ); ?>">

				<div style="margin:14px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
					<button class="button button-primary button-large" id="wts-push-btn" type="submit" disabled>
						<span class="dashicons dashicons-upload" style="margin-top:4px;"></span>
						Seçilenleri Trendyol'a Gönder
					</button>
					<button class="button button-secondary button-large" id="wts-push-filtered-btn" type="submit">
						<span class="dashicons dashicons-filter" style="margin-top:4px;"></span>
						Filtredekilerin Tümünü Gönder (<?php echo (int) $total; ?>)
					</button>
					<span id="wts-sel-count" style="color:#666;">0 ürün seçili</span>
					<span style="color:#888;font-size:12px;margin-left:auto;">
						<em>Her tıklamada en fazla 50 ürün gönderilir (PHP timeout güvenliği). Kalanlar için butona tekrar bas.</em>
					</span>
				</div>
			</form>
			<!--
			ÖNEMLİ: Yukarıdaki bulk form burada KAPATILIYOR çünkü aşağıdaki tablonun her satırında
			"preview" ve "single push" için ayrı <form> tag'leri var. HTML iç içe form'a izin
			vermez — tarayıcı iç formu görünce dış formu kapatır. Bu yüzden checkbox'lar form
			dışında kalıyordu ve submit edilmiyordu (sadece tek tek gönder çalışıyordu).
			Şimdi checkbox'lar form="wts-push-form" attribute'ü ile dış forma bağlı, dış form
			erken kapatıldığı için inner formlar sibling oldu — valid HTML, hepsi çalışıyor.
			-->

			<div class="wts-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:30px;"><input type="checkbox" id="wts-check-all"></th>
								<th>Ürün</th>
								<th>Tip</th>
								<th>SKU</th>
								<th>Stok</th>
								<th>Kategori</th>
								<th>Marka</th>
								<th>Durum</th>
								<th>İşlem</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $products ) ) : ?>
								<tr><td colspan="9"><em>Bu kritere uygun ürün yok.</em></td></tr>
							<?php else : foreach ( $products as $post ) :
								$product = wc_get_product( $post->ID );
								if ( ! $product ) continue;

								// Map kontrolü
								$map_row = $wpdb->get_row( $wpdb->prepare(
									"SELECT status, ty_barcode, last_error FROM {$wpdb->prefix}wts_product_map WHERE wp_post_id = %d",
									$post->ID
								) );
								$is_sent  = ( $map_row && in_array( $map_row->status, array( 'synced', 'syncing' ), true ) && 0 !== strpos( $map_row->ty_barcode, 'WP_' ) );
								$has_err  = ( $map_row && 'error' === $map_row->status );

								// Kategori/marka tespiti
								$ty_cat   = WTS_Categories::resolve_ty_category_for_product( $post->ID );
								$brand_id = WTS_Brands::resolve_ty_brand_for_product( $product );

								// Variation sayısı
								$variant_n = 0;
								if ( $product->is_type( 'variable' ) ) {
									$children  = $product->get_children();
									$variant_n = count( $children );
								}
							?>
								<tr>
									<td>
										<input type="checkbox" name="wp_ids[]" value="<?php echo (int) $post->ID; ?>" class="wts-row-chk" form="wts-push-form" <?php disabled( $is_sent || ! $ty_cat || ! $brand_id ); ?>>
									</td>
									<td>
										<strong>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>" target="_blank">
												<?php echo esc_html( wp_strip_all_tags( $product->get_name() ) ); ?>
											</a>
										</strong>
										<br><small>
											#<?php echo (int) $post->ID; ?>
											· <?php
												$price_text = wp_strip_all_tags( html_entity_decode( $product->get_price_html() ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
												$price_text = preg_replace( '/\s+/u', ' ', $price_text );
												echo esc_html( trim( $price_text ) ?: '—' );
											?>
										</small>
									</td>
									<td>
										<?php
										if ( $product->is_type( 'variable' ) ) {
											echo self::badge( 'info', "Varyantlı ({$variant_n})", 'screenoptions' );
										} else {
											echo self::badge( 'ok', 'Tekli', 'tag' );
										}
										?>
									</td>
									<td><code><?php echo esc_html( $product->get_sku() ?: '—' ); ?></code></td>
									<td><?php echo $product->get_stock_quantity() !== null ? (int) $product->get_stock_quantity() : '—'; ?></td>
									<td>
										<?php
										if ( $ty_cat ) {
											$missing = WTS_Category_Attrs::missing_required_count( $ty_cat );
											if ( $missing > 0 ) {
												echo '<span style="color:#d80;" title="' . esc_attr( $missing . ' zorunlu özellik default\'u eksik' ) . '">⚠️ ' . esc_html( $ty_cat ) . '</span>';
												echo '<br><a href="' . esc_url( add_query_arg( array( 'page' => 'wts-cat-attrs', 'edit' => $ty_cat ), admin_url( 'admin.php' ) ) ) . '" target="_blank" style="font-size:11px;">' . (int) $missing . ' eksik özellik →</a>';
											} else {
												echo '<span style="color:#0a7;" title="TY ID ' . esc_attr( $ty_cat ) . '">✅ ' . esc_html( $ty_cat ) . '</span>';
											}
										} else {
											echo '<span style="color:#b00;">❌ Eşleştirilmemiş</span>';
										}
										?>
									</td>
									<td>
										<?php
										if ( $brand_id ) {
											echo '<span style="color:#0a7;" title="TY ID ' . esc_attr( $brand_id ) . '">✅ ' . esc_html( $brand_id ) . '</span>';
										} else {
											echo '<span style="color:#b00;">❌ Yok</span>';
										}
										?>
									</td>
									<td>
										<?php
										if ( $is_sent ) {
											echo self::badge( 'ok', $map_row->status === 'syncing' ? 'Onayda' : 'Gönderildi', 'yes-alt' );
											if ( $map_row->ty_barcode && 0 !== strpos( $map_row->ty_barcode, 'WP_' ) ) {
												echo '<br><small>Barkod: <code>' . esc_html( $map_row->ty_barcode ) . '</code></small>';
											}
										} elseif ( $has_err ) {
											echo self::badge( 'err', 'Hata', 'warning' );
											if ( ! empty( $map_row->last_error ) ) {
												echo '<br><small style="color:#b00;" title="' . esc_attr( $map_row->last_error ) . '">' . esc_html( mb_substr( $map_row->last_error, 0, 80 ) ) . '...</small>';
											}
										} else {
											echo self::badge( 'info', 'Hazır', 'arrow-up-alt' );
										}
										?>
									</td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0;">
											<?php wp_nonce_field( 'wts_action_preview_payload' ); ?>
											<input type="hidden" name="action" value="wts_action">
											<input type="hidden" name="wts_action" value="preview_payload">
											<input type="hidden" name="wp_id" value="<?php echo (int) $post->ID; ?>">
											<button class="button button-small" title="Payload önizle"><span class="dashicons dashicons-visibility"></span></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0;">
											<?php wp_nonce_field( 'wts_action_push_single' ); ?>
											<input type="hidden" name="action" value="wts_action">
											<input type="hidden" name="wts_action" value="push_single">
											<input type="hidden" name="wp_id" value="<?php echo (int) $post->ID; ?>">
											<button class="button button-small button-primary" <?php disabled( $is_sent || ! $ty_cat || ! $brand_id ); ?>>Gönder</button>
										</form>
									</td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>

				<?php self::paginate( $total, $per, $paged, array(
					'page'   => 'wts-push',
					'filter' => $filter,
					's'      => $search,
					'cat'    => $cat_id,
					'brand'  => $brand_id,
					'price'  => $price_f,
				) ); ?>

			<script>
			(function(){
				var all      = document.getElementById('wts-check-all');
				var btnSel   = document.getElementById('wts-push-btn');
				var btnFlt   = document.getElementById('wts-push-filtered-btn');
				var modeIn   = document.getElementById('wts-bulk-mode');
				var cnt      = document.getElementById('wts-sel-count');
				var chks     = document.querySelectorAll('.wts-row-chk');
				var form     = btnSel ? btnSel.closest('form') : null;
				var totalEl  = btnFlt;
				var filteredTotal = <?php echo (int) $total; ?>;

				function update() {
					var n = 0;
					chks.forEach(function(c){ if (c.checked && !c.disabled) n++; });
					cnt.textContent = n + ' ürün seçili';
					btnSel.disabled = (n === 0);
				}

				if (all) {
					all.addEventListener('change', function(){
						chks.forEach(function(c){ if (!c.disabled) c.checked = all.checked; });
						update();
					});
				}
				chks.forEach(function(c){ c.addEventListener('change', update); });

				// "Seçilenleri Gönder" tıklayınca mode = selected
				if (btnSel) {
					btnSel.addEventListener('click', function(){
						if (modeIn) modeIn.value = 'selected';
					});
				}

				// "Filtredekilerin Tümünü Gönder" tıklayınca mode = filtered
				// → tüm checkbox'ları temizle (server filtreyi yeniden çalıştıracak)
				if (btnFlt) {
					btnFlt.addEventListener('click', function(e){
						if (filteredTotal === 0) {
							e.preventDefault();
							alert('Filtreye uyan ürün yok.');
							return;
						}
						if (modeIn) modeIn.value = 'filtered';
						// wp_ids[]'leri gönderme — mode=filtered ise server kendi sorgulayacak
						chks.forEach(function(c){ c.checked = false; });
					});
				}

				if (form) {
					form.addEventListener('submit', function(e){
						var mode = modeIn ? modeIn.value : 'selected';
						if (mode === 'filtered') {
							var cap = Math.min(filteredTotal, 50);
							var note = filteredTotal > 50
								? '\n\nBu tıklamada ilk ' + cap + ' ürün gönderilir. Geriye kalan ' + (filteredTotal - cap) + ' ürün için butona tekrar basman gerekir (PHP timeout güvenliği).'
								: '';
							if (!confirm('Filtreye uyan ' + cap + ' ürün Trendyol\'a gönderilecek.' + note + '\n\nDevam edilsin mi?')) {
								e.preventDefault();
								return;
							}
							// Buton'u disable et + spinner
							btnFlt.disabled = true;
							btnFlt.innerHTML = '<span class="dashicons dashicons-update" style="animation:wts-spin 1s linear infinite;margin-top:4px;"></span> Gönderiliyor...';
							return;
						}
						// selected mode
						var n = 0;
						chks.forEach(function(c){ if (c.checked && !c.disabled) n++; });
						if (n === 0) { e.preventDefault(); return; }
						if (n > 50) {
							if (!confirm(n + ' ürün seçili. Bu tıklamada ilk 50 gönderilecek, kalanlar için tekrar bas. Devam?')) {
								e.preventDefault();
								return;
							}
						} else if (!confirm(n + ' ürün Trendyol\'a gönderilecek. Devam edilsin mi?')) {
							e.preventDefault();
							return;
						}
						btnSel.disabled = true;
						btnSel.innerHTML = '<span class="dashicons dashicons-update" style="animation:wts-spin 1s linear infinite;margin-top:4px;"></span> Gönderiliyor...';
					});
				}
				update();
			})();
			</script>
			<style>@keyframes wts-spin { 100% { transform: rotate(360deg); } }</style>
		</div>
		<?php
	}

	/* ================================================================ CATEGORY ATTRIBUTES (toplu default) */

	public static function page_cat_attrs() {
		$edit_cat = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		if ( $edit_cat ) {
			self::render_cat_attrs_edit( $edit_cat );
			return;
		}
		self::render_cat_attrs_list();
	}

	protected static function render_cat_attrs_list() {
		$rows = WTS_Category_Attrs::list_mapped_categories();
		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Kategori Özellikleri (Toplu Default)</h1>
			<?php self::flash_notice(); ?>

			<div class="wts-banner">
				<strong><span class="dashicons dashicons-lightbulb"></span> Niye bu sayfa?</strong>
				Trendyol her kategori için kendi zorunlu özellik setini ister (örn. Materyal, Garanti Süresi, Menşei).
				WP ürünlerinde bunlar genelde yok. Burada kategori başına <strong>bir kez</strong> default değerleri girersin,
				o kategorideki <strong>tüm ürünler</strong> otomatik bu değerlerle gönderilir.
				Ürün başına manuel girişe gerek kalmaz.
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<p style="margin-top:20px;">
					<em>Henüz eşleştirilmiş Trendyol kategorisi yok. Önce
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-categories' ) ); ?>">Kategori Eşleştirme</a> sayfasından
					WP kategorilerini Trendyol leaf kategorilerine bağla.</em>
				</p>
			<?php else : ?>
				<div class="wts-table-wrap" style="margin-top:20px;">
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:90px;">TY Cat ID</th>
								<th>Kategori Yolu</th>
								<th style="width:180px;">Zorunlu Özellikler</th>
								<th style="width:150px;">İşlem</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $r ) :
								$pct = $r['total_required'] > 0
									? round( ( $r['filled_required'] / $r['total_required'] ) * 100 )
									: 100;
								$bar_color = $pct === 100 ? '#0a7' : ( $pct >= 50 ? '#d80' : '#b00' );
							?>
								<tr>
									<td><code><?php echo (int) $r['ty_category_id']; ?></code></td>
									<td><?php echo esc_html( $r['ty_category_path'] ?: '—' ); ?></td>
									<td>
										<?php if ( $r['total_required'] === 0 ) : ?>
											<small style="color:#0a7;">Zorunlu özellik yok</small>
										<?php else : ?>
											<div style="background:#eee;border-radius:3px;height:18px;width:120px;overflow:hidden;display:inline-block;vertical-align:middle;">
												<div style="background:<?php echo $bar_color; ?>;width:<?php echo $pct; ?>%;height:100%;"></div>
											</div>
											<small style="margin-left:6px;color:<?php echo $bar_color; ?>;">
												<?php echo (int) $r['filled_required']; ?> / <?php echo (int) $r['total_required']; ?>
												<?php if ( $pct === 100 ) echo '✓'; ?>
											</small>
										<?php endif; ?>
									</td>
									<td>
										<a class="button button-small <?php echo $pct === 100 ? '' : 'button-primary'; ?>"
										   href="<?php echo esc_url( add_query_arg( array( 'page' => 'wts-cat-attrs', 'edit' => $r['ty_category_id'] ), admin_url( 'admin.php' ) ) ); ?>">
											<?php echo $pct === 100 ? 'Düzenle' : 'Default Gir'; ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	protected static function render_cat_attrs_edit( $cat_id ) {
		// Kategori bilgisi
		global $wpdb;
		$cat = $wpdb->get_row( $wpdb->prepare(
			"SELECT name, path FROM {$wpdb->prefix}wts_category_cache WHERE ty_category_id = %d",
			$cat_id
		) );
		if ( ! $cat ) {
			echo '<div class="wrap"><h1>Kategori bulunamadı</h1><p>TY Cat ID ' . (int) $cat_id . ' cache\'te yok. Önce <a href="' . esc_url( admin_url( 'admin.php?page=wts-categories' ) ) . '">Kategorileri Çek</a>.</p></div>';
			return;
		}

		$data = WTS_Category_Attrs::get_category_form_data( $cat_id );
		?>
		<div class="wrap wts-wrap">
		<?php self::digitalog_banner(); ?>
			<h1>
				Kategori Default'ları:
				<small style="font-weight:normal;color:#666;"><?php echo esc_html( $cat->path ); ?> (#<?php echo (int) $cat_id; ?>)</small>
			</h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-cat-attrs' ) ); ?>" class="button button-small">← Tüm Kategoriler</a>
			</p>

			<?php self::flash_notice(); ?>

			<?php if ( ! $data['success'] ) : ?>
				<div class="notice notice-error"><p>❌ <?php echo esc_html( $data['error'] ); ?></p></div>
			<?php elseif ( empty( $data['attributes'] ) ) : ?>
				<div class="notice notice-info"><p>Bu kategori için Trendyol attribute tanımı yok — ürünler herhangi bir özellik vermeden gönderilebilir.</p></div>
			<?php else :
				$required_count = 0;
				$req_filled     = 0;
				foreach ( $data['attributes'] as $a ) {
					if ( $a['required'] ) {
						$required_count++;
						if ( $a['saved_value_id'] || $a['saved_custom'] !== '' ) $req_filled++;
					}
				}
			?>
				<div class="wts-banner">
					<strong>Özet:</strong>
					<?php echo (int) $req_filled; ?> / <?php echo (int) $required_count; ?> zorunlu özellik dolu.
					<?php if ( $required_count > 0 && $req_filled < $required_count ) : ?>
						<span style="color:#b00;">⚠️ Tüm zorunlu özellikler doldurulmadan bu kategorideki ürünler reddedilir.</span>
					<?php elseif ( $required_count === $req_filled && $required_count > 0 ) : ?>
						<span style="color:#0a7;">✅ Bu kategorideki ürünler gönderilmeye hazır.</span>
					<?php endif; ?>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:18px;">
					<?php wp_nonce_field( 'wts_action_set_cat_attr_defaults' ); ?>
					<input type="hidden" name="action" value="wts_action">
					<input type="hidden" name="wts_action" value="set_cat_attr_defaults">
					<input type="hidden" name="ty_category_id" value="<?php echo (int) $cat_id; ?>">

					<table class="widefat striped" style="margin-top:14px;">
						<thead>
							<tr>
								<th style="width:200px;">Özellik</th>
								<th>Varsayılan Değer</th>
								<th style="width:160px;">İpuçları</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['attributes'] as $a ) :
								$aid          = (int) $a['attributeId'];
								$has_values   = ! empty( $a['values'] );
								$saved_vid    = (int) $a['saved_value_id'];
								$saved_custom = (string) $a['saved_custom'];
							?>
								<tr>
									<td>
										<strong><?php echo esc_html( $a['name'] ); ?></strong>
										<?php if ( $a['required'] ) : ?>
											<span style="color:#b00;font-weight:bold;" title="Zorunlu">*</span>
										<?php endif; ?>
										<br><small style="color:#888;">ID: <?php echo $aid; ?></small>
										<input type="hidden" name="attr[<?php echo $aid; ?>][attribute_name]" value="<?php echo esc_attr( $a['name'] ); ?>">
									</td>
									<td>
										<?php if ( $has_values ) : ?>
											<select name="attr[<?php echo $aid; ?>][value_id]"
											        onchange="this.form.querySelector('input[name=\'attr[<?php echo $aid; ?>][value_name]\']').value = this.options[this.selectedIndex].text">
												<option value="">— Seçilmedi —</option>
												<?php foreach ( $a['values'] as $v ) : ?>
													<option value="<?php echo (int) $v['id']; ?>" <?php selected( $saved_vid, (int) $v['id'] ); ?>>
														<?php echo esc_html( $v['name'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<input type="hidden" name="attr[<?php echo $aid; ?>][value_name]" value="<?php
												$nm = '';
												if ( $saved_vid ) {
													foreach ( $a['values'] as $v ) {
														if ( (int) $v['id'] === $saved_vid ) { $nm = $v['name']; break; }
													}
												}
												echo esc_attr( $nm );
											?>">
											<?php if ( $a['allowCustom'] ) : ?>
												<br>
												<small style="color:#888;">veya serbest metin:</small>
												<input type="text" name="attr[<?php echo $aid; ?>][custom]"
												       value="<?php echo esc_attr( $saved_custom ); ?>"
												       placeholder="Listede yoksa serbest yaz..."
												       maxlength="50" style="width:240px;margin-top:4px;">
											<?php endif; ?>
										<?php elseif ( $a['allowCustom'] ) : ?>
											<input type="text" name="attr[<?php echo $aid; ?>][custom]"
											       value="<?php echo esc_attr( $saved_custom ); ?>"
											       placeholder="Serbest metin gir..."
											       maxlength="50" style="width:300px;">
										<?php else : ?>
											<em style="color:#b00;">Bu attribute Trendyol value listesi gerektiriyor ama liste boş geldi. "Kategorileri Çek" butonuna tekrar bas veya support'tan kontrol iste.</em>
											<input type="hidden" name="attr[<?php echo $aid; ?>][value_id]" value="">
										<?php endif; ?>
									</td>
									<td>
										<?php
										$tips = array();
										if ( $a['varianter'] ) $tips[] = '<span style="color:#06c;">Varianter</span>';
										if ( $a['allowMultiple'] ) $tips[] = 'Çoklu seçim';
										if ( $a['allowCustom'] ) $tips[] = 'Serbest metin OK';
										if ( $has_values ) $tips[] = count( $a['values'] ) . ' seçenek';
										echo $tips ? '<small>' . implode( ' · ', $tips ) . '</small>' : '<small style="color:#888;">—</small>';
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top:18px;">
						<button class="button button-primary button-large">
							<span class="dashicons dashicons-saved" style="margin-top:4px;"></span>
							Default'ları Kaydet
						</button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wts-cat-attrs' ) ); ?>" class="button">Vazgeç</a>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ================================================================ LOGS */

	public static function page_logs() {
		$status    = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$action    = isset( $_GET['log_action'] ) ? sanitize_text_field( wp_unslash( $_GET['log_action'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$per_page  = 50;
		$page      = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$offset    = ( $page - 1 ) * $per_page;

		$rows = WTS_Logger::fetch( array(
			'status' => $status,
			'action' => $action,
			'search' => $search,
			'limit'  => $per_page,
			'offset' => $offset,
		) );
		$total = WTS_Logger::count( array( 'status' => $status, 'action' => $action ) );

		?>
		<div class="wrap">
		<?php self::digitalog_banner(); ?>
			<h1>Loglar (<?php echo (int) $total; ?>)</h1>
			<?php self::flash_notice(); ?>

			<form method="get" style="margin-bottom:14px;">
				<input type="hidden" name="page" value="wts-logs">
				<select name="status">
					<option value="">Tüm durumlar</option>
					<?php foreach ( array( 'info', 'success', 'warning', 'error' ) as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( $st ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="log_action" placeholder="action filtresi" value="<?php echo esc_attr( $action ); ?>">
				<input type="text" name="s" placeholder="mesajda ara" value="<?php echo esc_attr( $search ); ?>">
				<button class="button">Filtrele</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:14px;">
				<?php wp_nonce_field( 'wts_action_clear_logs' ); ?>
				<input type="hidden" name="action" value="wts_action">
				<input type="hidden" name="wts_action" value="clear_logs">
				<input type="number" name="days" value="30" class="small-text"> günden eski logları sil
				<button class="button-secondary">Sil</button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Zaman</th>
						<th>Aksiyon</th>
						<th>Durum</th>
						<th>Mesaj</th>
						<th>WP#</th>
						<th>Barkod</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r->created_at ); ?></td>
							<td><?php echo esc_html( $r->action ); ?></td>
							<td>
								<?php
								$colors = array( 'success' => '#0a7', 'warning' => '#d80', 'error' => '#b00', 'info' => '#666' );
								$c = isset( $colors[ $r->status ] ) ? $colors[ $r->status ] : '#666';
								echo '<span style="color:' . esc_attr( $c ) . ';">' . esc_html( $r->status ) . '</span>';
								?>
							</td>
							<td style="max-width:520px;">
								<?php echo esc_html( $r->message ); ?>
								<?php if ( $r->payload ) : ?>
									<details><summary style="cursor:pointer;color:#06c;">Detay</summary><pre style="white-space:pre-wrap;font-size:11px;background:#f6f7f7;padding:8px;border-radius:4px;"><?php echo esc_html( mb_substr( $r->payload, 0, 4000 ) ); ?></pre></details>
								<?php endif; ?>
							</td>
							<td><?php echo $r->wp_post_id ? esc_html( $r->wp_post_id ) : '—'; ?></td>
							<td><?php echo $r->ty_barcode ? esc_html( $r->ty_barcode ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = ceil( $total / $per_page );
			if ( $pages > 1 ) :
			?>
				<p style="margin-top:14px;">
					<?php for ( $i = 1; $i <= min( 10, $pages ); $i++ ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>" class="button <?php if ( $i === $page ) echo 'button-primary'; ?>"><?php echo $i; ?></a>
					<?php endfor; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ================================================================ HELPERS */

	protected static function fmt( $v ) {
		if ( null === $v || '' === $v ) {
			return '—';
		}
		return number_format( floatval( $v ), 2, ',', '.' );
	}

	protected static function sample_product_ids() {
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'orderby'        => 'rand',
		) );
		return implode( ',', array_map( 'intval', $ids ) );
	}
}
