<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Marka cache senkronu.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Brands {

	/**
	 * Trendyol'dan tüm markaları çekip lokal tabloya yaz.
	 *
	 * @return array ['success'=>bool, 'count'=>int, 'error'=>string]
	 */
	public static function sync_all() {
		global $wpdb;
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array( 'success' => false, 'count' => 0, 'error' => 'API yapılandırılmamış.' );
		}

		$tbl   = $wpdb->prefix . 'wts_brand_cache';
		$page  = 0;
		$size  = 1000;
		$total = 0;

		while ( true ) {
			$resp = $api->get_brands( $page, $size );
			if ( ! $resp['success'] ) {
				return array( 'success' => false, 'count' => $total, 'error' => $resp['error'] );
			}

			$brands = isset( $resp['data']['brands'] ) ? $resp['data']['brands'] : array();
			if ( empty( $brands ) ) {
				break;
			}

			foreach ( $brands as $b ) {
				$id   = isset( $b['id'] )   ? (int) $b['id']   : 0;
				$name = isset( $b['name'] ) ? (string) $b['name'] : '';
				if ( $id <= 0 || '' === $name ) {
					continue;
				}
				$wpdb->replace(
					$tbl,
					array(
						'ty_brand_id' => $id,
						'name'        => $name,
					),
					array( '%d', '%s' )
				);
				$total++;
			}

			if ( count( $brands ) < $size ) {
				break;
			}
			$page++;
			if ( $page > 200 ) {
				break; // güvenlik
			}
		}

		WTS_Logger::success( 'brands_sync', "{$total} marka senkronlandı." );
		update_option( 'wts_brands_last_sync', current_time( 'mysql' ) );
		return array( 'success' => true, 'count' => $total, 'error' => '' );
	}

	/**
	 * Markayı isme göre cache'te bul. Tam eşleşme yoksa benzerlik (ilk eşleşeni).
	 */
	public static function find_id_by_name( $name ) {
		global $wpdb;
		$tbl  = $wpdb->prefix . 'wts_brand_cache';
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		// Tam eşleşme
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ty_brand_id FROM {$tbl} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
			$name
		) );
		if ( $id ) {
			return (int) $id;
		}
		// LIKE
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ty_brand_id FROM {$tbl} WHERE LOWER(name) LIKE LOWER(%s) LIMIT 1",
			'%' . $wpdb->esc_like( $name ) . '%'
		) );
		return $id ? (int) $id : 0;
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wts_brand_cache" );
	}

	public static function last_sync() {
		return get_option( 'wts_brands_last_sync', '' );
	}

	/* =========================================================
	 * Marka eşleştirme: WP marka terimleri <-> Trendyol markaları
	 * Kategori eşleştirme ile aynı paralel mantık.
	 * ========================================================= */

	/**
	 * Aktif marka taxonomy'sini tespit eder (WooCommerce yerleşik veya yaygın eklentiler).
	 */
	public static function detect_brand_taxonomy() {
		if ( function_exists( 'wcmp_brand_taxonomy' ) ) {
			$tax = wcmp_brand_taxonomy();
			if ( $tax && taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}
		$candidates = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand' );
		foreach ( $candidates as $c ) {
			if ( taxonomy_exists( $c ) ) {
				return $c;
			}
		}
		return '';
	}

	/**
	 * WP'deki tüm marka terimlerini map tablosuna ekler ve otomatik eşleştirme yapar.
	 * Var olan manuel eşleştirmeler korunur.
	 *
	 * @return array ['total'=>int, 'matched'=>int, 'unmatched'=>int]
	 */
	public static function rebuild_map() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_brand_map';
		$tax = self::detect_brand_taxonomy();

		if ( ! $tax ) {
			WTS_Logger::warning( 'brand_map', 'Aktif marka taxonomy bulunamadı (product_brand vs. yok).' );
			return array( 'total' => 0, 'matched' => 0, 'unmatched' => 0 );
		}

		$terms = get_terms( array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array( 'total' => 0, 'matched' => 0, 'unmatched' => 0 );
		}

		$matched   = 0;
		$unmatched = 0;

		foreach ( $terms as $term ) {
			// Mevcut manuel eşleştirmeye dokunma
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, match_type, ty_brand_id FROM {$tbl} WHERE wp_term_id = %d",
				$term->term_id
			) );
			if ( $existing && 'manual' === $existing->match_type && $existing->ty_brand_id ) {
				$matched++;
				continue;
			}

			$ty_id   = self::find_id_by_name( $term->name );
			$ty_name = '';
			if ( $ty_id ) {
				$ty_name = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT name FROM {$wpdb->prefix}wts_brand_cache WHERE ty_brand_id = %d",
					$ty_id
				) );
			}

			$row = array(
				'wp_term_id'   => (int) $term->term_id,
				'wp_taxonomy'  => $tax,
				'ty_brand_id'  => $ty_id ? (int) $ty_id : null,
				'ty_brand_name'=> $ty_name ?: null,
				'match_type'   => $ty_id ? 'auto' : 'unmatched',
				'confidence'   => $ty_id ? self::similarity_score( $term->name, $ty_name ) : 0,
			);
			$format = array( '%d', '%s', '%d', '%s', '%s', '%d' );

			if ( $existing ) {
				$wpdb->update( $tbl, $row, array( 'id' => $existing->id ), $format, array( '%d' ) );
			} else {
				$wpdb->insert( $tbl, $row, $format );
			}

			if ( $ty_id ) {
				$matched++;
			} else {
				$unmatched++;
			}
		}

		WTS_Logger::success( 'brand_map', "Marka eşleştirme tamamlandı: {$matched} eşleşti, {$unmatched} eşleşmedi." );
		return array( 'total' => count( $terms ), 'matched' => $matched, 'unmatched' => $unmatched );
	}

	/**
	 * Manuel marka eşleştirme.
	 */
	public static function set_map( $wp_term_id, $ty_brand_id ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_brand_map';
		$wp_term_id  = (int) $wp_term_id;
		$ty_brand_id = (int) $ty_brand_id;
		if ( ! $wp_term_id || ! $ty_brand_id ) {
			return false;
		}
		$ty_name = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT name FROM {$wpdb->prefix}wts_brand_cache WHERE ty_brand_id = %d",
			$ty_brand_id
		) );
		$term = get_term( $wp_term_id );
		$tax  = ( $term && ! is_wp_error( $term ) ) ? $term->taxonomy : self::detect_brand_taxonomy();

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE wp_term_id = %d", $wp_term_id ) );
		$data = array(
			'wp_term_id'    => $wp_term_id,
			'wp_taxonomy'   => (string) $tax,
			'ty_brand_id'   => $ty_brand_id,
			'ty_brand_name' => $ty_name ?: null,
			'match_type'    => 'manual',
			'confidence'    => 100,
		);
		$format = array( '%d', '%s', '%d', '%s', '%s', '%d' );
		if ( $existing ) {
			return false !== $wpdb->update( $tbl, $data, array( 'id' => (int) $existing ), $format, array( '%d' ) );
		}
		return false !== $wpdb->insert( $tbl, $data, $format );
	}

	public static function clear_map( $wp_term_id ) {
		global $wpdb;
		return false !== $wpdb->update(
			$wpdb->prefix . 'wts_brand_map',
			array( 'ty_brand_id' => null, 'ty_brand_name' => null, 'match_type' => 'unmatched', 'confidence' => 0 ),
			array( 'wp_term_id' => (int) $wp_term_id ),
			array( '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Verilen WP ürünü için Trendyol marka ID'sini çözer.
	 * Sıra: 1) brand_map (manuel/auto) → 2) cache'te isimden ara → 3) ayarlardaki "default_brand_id"
	 */
	public static function resolve_ty_brand_for_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product ) {
			return 0;
		}

		$tax = self::detect_brand_taxonomy();
		if ( $tax ) {
			$pid   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$terms = wp_get_post_terms( $pid, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				global $wpdb;
				$tbl = $wpdb->prefix . 'wts_brand_map';
				foreach ( $terms as $tid ) {
					$row = $wpdb->get_row( $wpdb->prepare(
						"SELECT ty_brand_id FROM {$tbl} WHERE wp_term_id = %d AND ty_brand_id IS NOT NULL",
						(int) $tid
					) );
					if ( $row && $row->ty_brand_id ) {
						return (int) $row->ty_brand_id;
					}
				}
				// Map'te yoksa isimden bul
				$names = wp_get_post_terms( $pid, $tax, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $names ) && ! empty( $names ) ) {
					$id = self::find_id_by_name( $names[0] );
					if ( $id ) {
						return (int) $id;
					}
				}
			}
		}

		// Son çare: ayarlardan varsayılan marka
		$default = (int) WTS_Settings::get( 'default_brand_id', 0 );
		return $default ?: 0;
	}

	public static function get_map_stats() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_brand_map';
		return array(
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ),
			'matched'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE match_type IN ('auto','manual') AND ty_brand_id IS NOT NULL" ),
			'unmatched' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE match_type = 'unmatched' OR ty_brand_id IS NULL" ),
		);
	}

	public static function get_map_rows( $filter = 'all', $limit = 200 ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_brand_map';
		$where = '';
		if ( 'matched' === $filter ) {
			$where = "WHERE match_type IN ('auto','manual') AND ty_brand_id IS NOT NULL";
		} elseif ( 'unmatched' === $filter ) {
			$where = "WHERE match_type = 'unmatched' OR ty_brand_id IS NULL";
		}
		$limit = max( 10, (int) $limit );
		return $wpdb->get_results( "SELECT * FROM {$tbl} {$where} ORDER BY match_type DESC, id ASC LIMIT {$limit}" );
	}

	/**
	 * Bir marka adı için en yakın N TY markası önerisi (LIKE bazlı).
	 */
	public static function suggest_for_name( $name, $limit = 8 ) {
		global $wpdb;
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $name ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ty_brand_id, name FROM {$wpdb->prefix}wts_brand_cache WHERE name LIKE %s ORDER BY CHAR_LENGTH(name) ASC LIMIT %d",
			$like, (int) $limit
		), ARRAY_A );
		return $rows ?: array();
	}

	protected static function similarity_score( $a, $b ) {
		$a = mb_strtolower( trim( (string) $a ) );
		$b = mb_strtolower( trim( (string) $b ) );
		if ( '' === $a || '' === $b ) {
			return 0;
		}
		if ( $a === $b ) {
			return 100;
		}
		$lev = levenshtein( $a, $b );
		$max = max( mb_strlen( $a ), mb_strlen( $b ) );
		$pct = (int) round( ( 1 - ( $lev / max( 1, $max ) ) ) * 100 );
		return max( 0, min( 100, $pct ) );
	}
}
