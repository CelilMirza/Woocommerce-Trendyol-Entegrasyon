<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Yardımcı fonksiyonlar — FOX (WOOCS) ve WCMP ile uyumlu.
 *
 * NOT: WCMP eklentisi varsa onun helper'larına yaslanırız, yoksa kendi
 * uygulamamızı kullanırız. Bu sayede eklenti tek başına da çalışır.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---- FOX/WOOCS erişimi ---- */

if ( ! function_exists( 'wts_woocs' ) ) {
	function wts_woocs() {
		global $WOOCS;
		return is_object( $WOOCS ) ? $WOOCS : null;
	}
}

if ( ! function_exists( 'wts_currencies' ) ) {
	function wts_currencies() {
		// WCMP varsa onun fonksiyonunu kullan.
		if ( function_exists( 'wcmp_currencies' ) ) {
			return wcmp_currencies();
		}
		$w = wts_woocs();
		if ( $w ) {
			if ( method_exists( $w, 'get_currencies' ) ) {
				$c = $w->get_currencies();
				if ( is_array( $c ) && $c ) {
					return $c;
				}
			}
			if ( isset( $w->currencies ) && is_array( $w->currencies ) ) {
				return $w->currencies;
			}
		}
		return array();
	}
}

if ( ! function_exists( 'wts_default_currency' ) ) {
	function wts_default_currency() {
		if ( function_exists( 'wcmp_default_currency' ) ) {
			return wcmp_default_currency();
		}
		$w = wts_woocs();
		if ( $w && ! empty( $w->default_currency ) ) {
			return $w->default_currency;
		}
		return get_option( 'woocommerce_currency' );
	}
}

if ( ! function_exists( 'wts_rate' ) ) {
	/**
	 * Verilen para biriminin FOX kuru (default'a göre çarpan).
	 * Yoksa 1.
	 */
	function wts_rate( $code ) {
		if ( function_exists( 'wcmp_rate' ) ) {
			return wcmp_rate( $code );
		}
		$cur = wts_currencies();
		if ( isset( $cur[ $code ]['rate'] ) ) {
			$r = floatval( $cur[ $code ]['rate'] );
			if ( $r > 0 ) {
				return $r;
			}
		}
		return 1.0;
	}
}

/**
 * Bir tutarı (kaynak para biriminde) hedef para birimine (TRY) çevirir.
 *
 *  hedef = (kaynak / kur[kaynak]) * kur[hedef]
 *
 * Kaynak para birimi boşsa, mağaza varsayılanı olarak kabul edilir.
 */
if ( ! function_exists( 'wts_convert' ) ) {
	function wts_convert( $amount, $source_code, $target_code = 'TRY' ) {
		if ( '' === $amount || null === $amount ) {
			return null;
		}
		$amount = floatval( $amount );

		$src    = $source_code ? $source_code : wts_default_currency();
		$src_r  = wts_rate( $src );
		if ( $src_r <= 0 ) {
			$src_r = 1.0;
		}
		$tgt_r  = wts_rate( $target_code );
		if ( $tgt_r <= 0 ) {
			$tgt_r = 1.0;
		}

		// kaynak -> default -> hedef
		$in_default = $amount / $src_r;
		return $in_default * $tgt_r;
	}
}

/* ---- WCMP meta keys (varsa) ---- */

if ( ! function_exists( 'wts_meta_source_currency' ) ) {
	function wts_meta_source_currency() {
		if ( class_exists( 'WCMP_Product_Data' ) && defined( 'WCMP_Product_Data::META_CURRENCY' ) ) {
			return WCMP_Product_Data::META_CURRENCY;
		}
		return '_wcmp_source_currency';
	}
}

/* ---- Yuvarlama ---- */

/**
 * Bir fiyatı verilen adıma "yukarı" yuvarlar.
 * Örn: step=5, 247 -> 250, 250 -> 250, 251 -> 255.
 */
if ( ! function_exists( 'wts_round_up_to_step' ) ) {
	function wts_round_up_to_step( $price, $step = 5 ) {
		$price = floatval( $price );
		$step  = floatval( $step );
		if ( $price <= 0 ) {
			return 0.0;
		}
		if ( $step <= 0 ) {
			return round( $price, 2 );
		}
		// Küçük epsilon ile float karşılaştırma hatasını engelle.
		$eps = 1e-9;
		return round( ceil( $price / $step - $eps ) * $step, 2 );
	}
}

/**
 * Sonu 9 ile bitir: bir sonraki onluğa çıkıp 1 düş.
 * 247 -> 249, 250 -> 259, 251 -> 259, 100 -> 109.
 */
if ( ! function_exists( 'wts_round_charm9' ) ) {
	function wts_round_charm9( $price ) {
		$price = floatval( $price );
		if ( $price <= 0 ) {
			return 0.0;
		}
		// Her zaman bir sonraki onluğa çık (tam onluk dahil), 1 düş.
		return floor( $price / 10 ) * 10 + 9;
	}
}

/* ---- Trendyol yardımcıları ---- */

if ( ! function_exists( 'wts_settings' ) ) {
	function wts_settings() {
		$s = get_option( 'wts_settings', array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array_merge( WTS_Settings::defaults(), $s );
	}
}

if ( ! function_exists( 'wts_setting' ) ) {
	function wts_setting( $key, $default = null ) {
		$s = wts_settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : $default;
	}
}
