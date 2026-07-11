<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Kategori cache + eşleştirme motoru.
 *
 *  - Trendyol kategori ağacını çekip flat tabloya yazar (parent_id + path).
 *  - Her leaf kategori için attribute listesini lazy-load eder.
 *  - WP product_cat terimlerini Trendyol leaf kategorilerle eşleştirir
 *    (ad / yol benzerliği + güven skoru).
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Categories {

	/**
	 * Trendyol kategori ağacını çek ve cache'e yaz.
	 */
	public static function sync_tree() {
		global $wpdb;
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array( 'success' => false, 'count' => 0, 'error' => 'API yapılandırılmamış.' );
		}

		$resp = $api->get_category_tree();
		if ( ! $resp['success'] ) {
			return array( 'success' => false, 'count' => 0, 'error' => $resp['error'] );
		}

		$cats = isset( $resp['data']['categories'] ) ? $resp['data']['categories'] : array();
		if ( empty( $cats ) ) {
			return array( 'success' => false, 'count' => 0, 'error' => 'Kategori listesi boş döndü.' );
		}

		$tbl = $wpdb->prefix . 'wts_category_cache';
		// Önce tabloyu boşalt — tüm ağacı yeniden yazıyoruz.
		$wpdb->query( "TRUNCATE TABLE {$tbl}" );

		$count = 0;
		self::flatten_tree( $cats, 0, '', $wpdb, $tbl, $count );

		WTS_Logger::success( 'category_sync', "{$count} kategori cache'e yazıldı." );
		update_option( 'wts_categories_last_sync', current_time( 'mysql' ) );
		return array( 'success' => true, 'count' => $count, 'error' => '' );
	}

	/**
	 * Recursive tree → flat rows.
	 */
	protected static function flatten_tree( $nodes, $parent_id, $parent_path, $wpdb, $tbl, &$count ) {
		foreach ( $nodes as $n ) {
			$id       = isset( $n['id'] ) ? (int) $n['id'] : 0;
			$name     = isset( $n['name'] ) ? (string) $n['name'] : '';
			$children = isset( $n['subCategories'] ) ? $n['subCategories'] : array();
			if ( $id <= 0 || '' === $name ) {
				continue;
			}
			$path    = $parent_path ? ( $parent_path . ' > ' . $name ) : $name;
			$is_leaf = empty( $children ) ? 1 : 0;

			$wpdb->insert(
				$tbl,
				array(
					'ty_category_id' => $id,
					'parent_id'      => $parent_id ? $parent_id : null,
					'name'           => $name,
					'path'           => $path,
					'is_leaf'        => $is_leaf,
				),
				array( '%d', '%d', '%s', '%s', '%d' )
			);
			$count++;

			if ( ! empty( $children ) ) {
				self::flatten_tree( $children, $id, $path, $wpdb, $tbl, $count );
			}
		}
	}

	/**
	 * Belirli bir kategorinin attribute listesini çek (ve cache'e yaz).
	 *
	 * V2 not: response içinde artık attributeValues listesi gelmiyor —
	 * onun için ayrı fetch_attribute_values() metodumuz var. Bu fonksiyon
	 * eski v1 yanıt yapısını da işliyor (içinde attributeValues varsa
	 * onları da attr-value cache tablosuna yazar).
	 */
	public static function fetch_attributes( $category_id, $force = false ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_category_cache';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT attributes FROM {$tbl} WHERE ty_category_id = %d",
			(int) $category_id
		) );

		if ( ! $force && $row && $row->attributes ) {
			return array( 'success' => true, 'data' => json_decode( $row->attributes, true ), 'error' => '' );
		}

		$api  = new WTS_API_Client();
		$resp = $api->get_category_attributes( $category_id );
		if ( ! $resp['success'] ) {
			return array( 'success' => false, 'data' => null, 'error' => $resp['error'] );
		}

		$wpdb->update(
			$tbl,
			array( 'attributes' => wp_json_encode( $resp['data'], JSON_UNESCAPED_UNICODE ) ),
			array( 'ty_category_id' => (int) $category_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Eski yanıtlarda attributeValues categoryAttributes içinde gelmiş olabilir —
		// onları da attribute-value cache tablosuna sıkıştır.
		if ( ! empty( $resp['data']['categoryAttributes'] ) && is_array( $resp['data']['categoryAttributes'] ) ) {
			$vtbl = $wpdb->prefix . 'wts_category_attr_values';
			foreach ( $resp['data']['categoryAttributes'] as $ca ) {
				if ( empty( $ca['attribute']['id'] ) || empty( $ca['attributeValues'] ) || ! is_array( $ca['attributeValues'] ) ) {
					continue;
				}
				$aid = (int) $ca['attribute']['id'];
				foreach ( $ca['attributeValues'] as $v ) {
					$vid  = isset( $v['id'] ) ? (int) $v['id'] : 0;
					$name = isset( $v['name'] ) ? (string) $v['name'] : '';
					if ( $vid && $name !== '' ) {
						$wpdb->replace( $vtbl, array(
							'ty_category_id'  => (int) $category_id,
							'ty_attribute_id' => $aid,
							'ty_value_id'     => $vid,
							'value_name'      => mb_substr( $name, 0, 255 ),
						) );
					}
				}
			}
		}

		return array( 'success' => true, 'data' => $resp['data'], 'error' => '' );
	}

	/**
	 * V2 yeni endpoint: bir attribute'un olası değerlerini sayfalı çekip cache'le.
	 * Çağrı sırasında cache'e bakar, boşsa indirir.
	 *
	 * @param int  $category_id
	 * @param int  $attribute_id
	 * @param bool $force  true → cache'i bypass et
	 * @return array  [ ['id'=>int,'name'=>string], ... ]
	 */
	public static function fetch_attribute_values( $category_id, $attribute_id, $force = false ) {
		global $wpdb;
		$vtbl = $wpdb->prefix . 'wts_category_attr_values';

		if ( ! $force ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ty_value_id, value_name FROM {$vtbl} WHERE ty_category_id = %d AND ty_attribute_id = %d ORDER BY value_name ASC",
				(int) $category_id, (int) $attribute_id
			), ARRAY_A );
			if ( ! empty( $rows ) ) {
				return array_map( function ( $r ) {
					return array( 'id' => (int) $r['ty_value_id'], 'name' => (string) $r['value_name'] );
				}, $rows );
			}
		}

		$api    = new WTS_API_Client();
		$out    = array();
		$page   = 0;
		$size   = 1000;
		$guard  = 0;
		while ( $guard++ < 20 ) {
			$resp = $api->get_category_attribute_values( $category_id, $attribute_id, $page, $size );
			if ( ! $resp['success'] ) {
				break;
			}
			$content = isset( $resp['data']['content'] ) ? $resp['data']['content'] : array();
			if ( empty( $content ) ) {
				break;
			}
			foreach ( $content as $v ) {
				// V2 yanıtı: attributeValueId + attributeValue
				$vid  = isset( $v['attributeValueId'] ) ? (int) $v['attributeValueId'] : ( isset( $v['id'] ) ? (int) $v['id'] : 0 );
				$name = isset( $v['attributeValue'] ) ? (string) $v['attributeValue'] : ( isset( $v['name'] ) ? (string) $v['name'] : '' );
				if ( $vid <= 0 || '' === $name ) {
					continue;
				}
				$wpdb->replace( $vtbl, array(
					'ty_category_id'  => (int) $category_id,
					'ty_attribute_id' => (int) $attribute_id,
					'ty_value_id'     => $vid,
					'value_name'      => mb_substr( $name, 0, 255 ),
				) );
				$out[] = array( 'id' => $vid, 'name' => $name );
			}
			if ( count( $content ) < $size ) {
				break;
			}
			$page++;
		}
		return $out;
	}

	/* ---------- Eşleştirme motoru ---------- */

	/**
	 * Tüm WP product_cat terimleri için map satırlarını kur ve auto-match dene.
	 *
	 * @return array ['matched'=>int, 'suggested'=>int, 'unmatched'=>int]
	 */
	public static function build_mapping_table() {
		global $wpdb;
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );
		if ( is_wp_error( $terms ) ) {
			return array( 'matched' => 0, 'suggested' => 0, 'unmatched' => 0 );
		}

		$method  = WTS_Settings::get( 'auto_match_categories', 'name' );
		$tbl_map = $wpdb->prefix . 'wts_category_map';
		$stats   = array( 'matched' => 0, 'suggested' => 0, 'unmatched' => 0 );

		foreach ( $terms as $term ) {
			$wp_path = self::wp_term_path( $term );

			// Mevcut satırı oku
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$tbl_map} WHERE wp_term_id = %d",
				$term->term_id
			) );

			// Eğer zaten 'manual' veya 'confirmed' ise dokunma
			if ( $existing && in_array( $existing->match_type, array( 'manual', 'confirmed' ), true ) ) {
				$stats['matched']++;
				continue;
			}

			$result = self::auto_match( $term, $wp_path, $method );

			$data = array(
				'wp_term_id'       => (int) $term->term_id,
				'ty_category_id'   => $result['ty_category_id'],
				'ty_category_path' => $result['ty_category_path'],
				'match_type'       => $result['match_type'],
				'confidence'       => $result['confidence'],
				'suggestion'       => $result['suggestion'] ? wp_json_encode( $result['suggestion'], JSON_UNESCAPED_UNICODE ) : null,
			);
			$format = array( '%d', '%d', '%s', '%s', '%d', '%s' );

			if ( $existing ) {
				$wpdb->update( $tbl_map, $data, array( 'id' => $existing->id ), $format, array( '%d' ) );
			} else {
				$wpdb->insert( $tbl_map, $data, $format );
			}

			if ( 'auto' === $result['match_type'] ) {
				$stats['matched']++;
			} elseif ( 'suggested' === $result['match_type'] ) {
				$stats['suggested']++;
			} else {
				$stats['unmatched']++;
			}
		}

		return $stats;
	}

	/**
	 * WP terim için "Üst > Alt > En Alt" şeklinde path üret.
	 */
	public static function wp_term_path( $term ) {
		$parts = array();
		$t = $term;
		$guard = 0;
		while ( $t && $guard++ < 20 ) {
			array_unshift( $parts, $t->name );
			if ( ! $t->parent ) {
				break;
			}
			$t = get_term( $t->parent, 'product_cat' );
			if ( is_wp_error( $t ) ) {
				break;
			}
		}
		return implode( ' > ', $parts );
	}

	/**
	 * Tek bir WP terim için Trendyol leaf kategori önerisi üret.
	 *
	 * @return array {
	 *     match_type       : 'auto' | 'suggested' | 'unmatched'
	 *     confidence       : 0..100
	 *     ty_category_id   : int|null
	 *     ty_category_path : string|null
	 *     suggestion       : array|null  (en iyi 5 aday, manuel seçim için)
	 * }
	 */
	public static function auto_match( $term, $wp_path, $method = 'name' ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_category_cache';

		// Sadece leaf kategorileri al
		$leaves = $wpdb->get_results( "SELECT ty_category_id, name, path FROM {$tbl} WHERE is_leaf = 1" );
		if ( empty( $leaves ) ) {
			return array(
				'match_type'       => 'unmatched',
				'confidence'       => 0,
				'ty_category_id'   => null,
				'ty_category_path' => null,
				'suggestion'       => null,
			);
		}

		if ( 'off' === $method ) {
			return array(
				'match_type'       => 'unmatched',
				'confidence'       => 0,
				'ty_category_id'   => null,
				'ty_category_path' => null,
				'suggestion'       => null,
			);
		}

		$candidates = array();
		$wp_name    = $term->name;

		foreach ( $leaves as $leaf ) {
			$score = ( 'path' === $method )
				? self::similarity_score( $wp_path, $leaf->path )
				: self::similarity_score( $wp_name, $leaf->name );

			// İsim bazlı eşleştirmede tam eşleşmeye bonus
			if ( 'name' === $method && mb_strtolower( $wp_name ) === mb_strtolower( $leaf->name ) ) {
				$score = 100;
			}

			if ( $score > 30 ) {
				$candidates[] = array(
					'ty_category_id' => (int) $leaf->ty_category_id,
					'path'           => $leaf->path,
					'name'           => $leaf->name,
					'score'          => $score,
				);
			}
		}

		if ( empty( $candidates ) ) {
			return array(
				'match_type'       => 'unmatched',
				'confidence'       => 0,
				'ty_category_id'   => null,
				'ty_category_path' => null,
				'suggestion'       => null,
			);
		}

		usort( $candidates, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		$best = $candidates[0];
		$top5 = array_slice( $candidates, 0, 5 );

		// Skor 90+: otomatik kabul; 60-90: öneri (manuel onay); altı: unmatched
		if ( $best['score'] >= 90 ) {
			return array(
				'match_type'       => 'auto',
				'confidence'       => $best['score'],
				'ty_category_id'   => $best['ty_category_id'],
				'ty_category_path' => $best['path'],
				'suggestion'       => $top5,
			);
		}
		if ( $best['score'] >= 60 ) {
			return array(
				'match_type'       => 'suggested',
				'confidence'       => $best['score'],
				'ty_category_id'   => $best['ty_category_id'],
				'ty_category_path' => $best['path'],
				'suggestion'       => $top5,
			);
		}
		return array(
			'match_type'       => 'unmatched',
			'confidence'       => $best['score'],
			'ty_category_id'   => null,
			'ty_category_path' => null,
			'suggestion'       => $top5,
		);
	}

	/**
	 * İki string arasında 0-100 benzerlik skoru (Türkçe normalize edilmiş).
	 *
	 * Karışım: similar_text (yüzdesel) + Levenshtein bonus + ortak kelime bonusu.
	 */
	public static function similarity_score( $a, $b ) {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( '' === $a || '' === $b ) {
			return 0;
		}
		if ( $a === $b ) {
			return 100;
		}

		// similar_text yüzdesi
		$pct = 0.0;
		similar_text( $a, $b, $pct );

		// Ortak kelime bonusu: WP'nin son segmenti Trendyol path'inin herhangi bir kelimesinde geçiyorsa +
		$tokens_a = preg_split( '/[\s\>\-_\/]+/u', $a );
		$tokens_b = preg_split( '/[\s\>\-_\/]+/u', $b );
		$tokens_a = array_filter( array_map( 'trim', $tokens_a ) );
		$tokens_b = array_filter( array_map( 'trim', $tokens_b ) );
		$common   = array_intersect( $tokens_a, $tokens_b );

		$bonus = 0;
		if ( ! empty( $common ) ) {
			$bonus = min( 25, count( $common ) * 10 );
		}

		$score = min( 100, round( $pct + $bonus ) );
		return $score;
	}

	/**
	 * Türkçe karakterleri sadeleştir + lowercase.
	 */
	public static function normalize( $s ) {
		$s = mb_strtolower( (string) $s, 'UTF-8' );
		$tr = array( 'ı'=>'i','ç'=>'c','ğ'=>'g','ö'=>'o','ş'=>'s','ü'=>'u','İ'=>'i' );
		$s  = strtr( $s, $tr );
		$s  = preg_replace( '/[^\p{L}\p{N}\s\>]+/u', ' ', $s );
		$s  = preg_replace( '/\s+/', ' ', $s );
		return trim( $s );
	}

	/* ---------- Public getters ---------- */

	public static function count_leaves() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wts_category_cache WHERE is_leaf = 1" );
	}

	public static function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wts_category_cache" );
	}

	public static function last_sync() {
		return get_option( 'wts_categories_last_sync', '' );
	}

	/**
	 * Belirli bir WP term ID için mapping satırını döndür.
	 */
	public static function get_mapping( $wp_term_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wts_category_map WHERE wp_term_id = %d",
			(int) $wp_term_id
		) );
	}

	/**
	 * Bir ürün için kullanılacak Trendyol kategori ID'sini bul (eşleşmiş ilk WP kategorisinden).
	 */
	public static function resolve_ty_category_for_product( $product_id ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}
		global $wpdb;
		$placeholders = implode( ',', array_map( 'intval', $terms ) );
		$row = $wpdb->get_row(
			"SELECT ty_category_id FROM {$wpdb->prefix}wts_category_map
			 WHERE wp_term_id IN ({$placeholders})
			   AND match_type IN ('auto','manual','confirmed')
			   AND ty_category_id IS NOT NULL
			 ORDER BY confidence DESC LIMIT 1"
		);
		return $row ? (int) $row->ty_category_id : 0;
	}

	/**
	 * Manuel eşleştirme ata.
	 */
	public static function set_manual_mapping( $wp_term_id, $ty_category_id ) {
		global $wpdb;
		$cat = $wpdb->get_row( $wpdb->prepare(
			"SELECT path FROM {$wpdb->prefix}wts_category_cache WHERE ty_category_id = %d AND is_leaf = 1",
			(int) $ty_category_id
		) );
		if ( ! $cat ) {
			return false;
		}
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}wts_category_map WHERE wp_term_id = %d",
			(int) $wp_term_id
		) );
		$data = array(
			'wp_term_id'       => (int) $wp_term_id,
			'ty_category_id'   => (int) $ty_category_id,
			'ty_category_path' => $cat->path,
			'match_type'       => 'manual',
			'confidence'       => 100,
		);
		if ( $existing ) {
			$wpdb->update( $wpdb->prefix . 'wts_category_map', $data, array( 'id' => $existing ) );
		} else {
			$wpdb->insert( $wpdb->prefix . 'wts_category_map', $data );
		}
		return true;
	}

	public static function clear_mapping( $wp_term_id ) {
		global $wpdb;
		return $wpdb->delete( $wpdb->prefix . 'wts_category_map', array( 'wp_term_id' => (int) $wp_term_id ), array( '%d' ) );
	}

	/**
	 * Kategori cache'inden filter ile arama (autocomplete için).
	 */
	public static function search_leaves( $q, $limit = 30 ) {
		global $wpdb;
		$q = trim( (string) $q );
		if ( '' === $q ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT ty_category_id, name, path FROM {$wpdb->prefix}wts_category_cache
			 WHERE is_leaf = 1 AND (name LIKE %s OR path LIKE %s)
			 ORDER BY LENGTH(path) ASC LIMIT %d",
			$like, $like, (int) $limit
		) );
	}
}
