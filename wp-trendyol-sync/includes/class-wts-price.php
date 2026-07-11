<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Fiyat motoru (SADE SÜRÜM).
 *
 * WooCommerce ürününün fiyatını OLDUĞU GİBİ Trendyol'a gönderir.
 * FOX (WOOCS) / WCMP kur çevirimi, KDV ekleme, yuvarlama ve liste
 * fiyatı markup'ı DEVRE DIŞIDIR. WooCommerce'de fiyat neyse Trendyol'a
 * o gider (mağazanın kendi para biriminde).
 *
 *   - listPrice  = ürünün normal (regular) fiyatı
 *   - salePrice  = indirim varsa indirimli fiyat, yoksa normal fiyat
 *
 * Trendyol kuralı gereği listPrice >= salePrice olmak zorundadır; tek
 * güvenlik kontrolü budur.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Price {

	/**
	 * Ana giriş noktası: ürün için Trendyol'a gönderilecek fiyatları döndür.
	 *
	 * @param int|WC_Product $product
	 * @return array {
	 *     @type float|null sale_price   Trendyol "salePrice" alanı
	 *     @type float|null list_price   Trendyol "listPrice" alanı
	 *     @type array      debug        Hesaplama adımları (önizleme için)
	 *     @type string|null error
	 * }
	 */
	public static function calculate( $product ) {
		$product = self::resolve_product( $product );
		if ( ! $product ) {
			return self::empty_result( 'Ürün bulunamadı.' );
		}

		// WooCommerce'in kendi fiyatlarını doğrudan oku (kur çevrimi YOK).
		$regular = $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );

		$regular = ( '' === $regular || null === $regular ) ? null : floatval( $regular );
		$sale    = ( '' === $sale    || null === $sale )    ? null : floatval( $sale );

		if ( null === $regular && null === $sale ) {
			return self::empty_result( 'Ürün fiyatı tanımlı değil.' );
		}

		// listPrice = normal fiyat; salePrice = indirim varsa o, yoksa normal.
		$list_price = ( null !== $regular ) ? $regular : $sale;
		$sale_price = ( null !== $sale && $sale > 0 ) ? $sale : $list_price;

		// Trendyol kuralı: listPrice >= salePrice.
		if ( null !== $list_price && null !== $sale_price && $list_price < $sale_price ) {
			$list_price = $sale_price;
		}

		$list_price = ( null !== $list_price ) ? round( $list_price, 2 ) : null;
		$sale_price = ( null !== $sale_price ) ? round( $sale_price, 2 ) : null;

		$debug = array(
			'wp_post_id'       => $product->get_id(),
			'parent_id'        => $product->get_parent_id(),
			'regular_price'    => $regular,
			'sale_price_raw'   => $sale,
			'final_list_price' => $list_price,
			'final_sale_price' => $sale_price,
			'note'             => 'WooCommerce fiyatı olduğu gibi kullanıldı (kur/KDV/yuvarlama yok).',
		);

		return array(
			'sale_price' => $sale_price,
			'list_price' => $list_price,
			'debug'      => $debug,
			'error'      => null,
		);
	}

	/**
	 * Geriye dönük uyumluluk: eski kodda round() çağrılıyorsa kırılmasın.
	 * Artık yuvarlama yapmıyoruz, sadece 2 ondalığa sabitliyoruz.
	 */
	public static function round( $price ) {
		return round( floatval( $price ), 2 );
	}

	/**
	 * Önizleme: ürünler için hesaplanan fiyatları döndürür.
	 * Admin ekranındaki "test et" butonu için.
	 */
	public static function preview( $product_ids ) {
		$rows = array();
		foreach ( (array) $product_ids as $pid ) {
			$pid = intval( $pid );
			if ( ! $pid ) {
				continue;
			}
			$p = wc_get_product( $pid );
			if ( ! $p ) {
				continue;
			}
			$res    = self::calculate( $p );
			$rows[] = array(
				'id'         => $pid,
				'name'       => $p->get_name(),
				'sku'        => $p->get_sku(),
				'sale_price' => $res['sale_price'],
				'list_price' => $res['list_price'],
				'error'      => $res['error'],
				'debug'      => $res['debug'],
			);
		}
		return $rows;
	}

	/* ---------- Yardımcılar ---------- */

	protected static function resolve_product( $product ) {
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		if ( is_numeric( $product ) ) {
			$p = wc_get_product( intval( $product ) );
			return $p ? $p : null;
		}
		return null;
	}

	protected static function empty_result( $error = '' ) {
		return array(
			'sale_price' => null,
			'list_price' => null,
			'debug'      => array(),
			'error'      => $error,
		);
	}
}
