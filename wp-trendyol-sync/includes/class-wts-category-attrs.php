<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Kategori bazında attribute default değerleri.
 *
 * Trendyol'da her kategori kendi zorunlu attribute setini ister
 * (örn. Elektronik kategorisi → Materyal, Garanti Süresi, Menşei...).
 * WP ürünlerinde bu özellikler genelde yoktur. Bu sınıf, kategori başına
 * "varsayılan değer" tanımlamanı sağlar — o kategorideki TÜM ürünler
 * aynı default'la gönderilir, ürün başına manuel girişe gerek kalmaz.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Category_Attrs {

	/**
	 * Bir kategorinin tüm default'larını döndür.
	 *
	 * @param int $ty_category_id
	 * @return array  [ ty_attribute_id => ['attributeId'=>..., 'attributeValueId'=>..., 'customAttributeValue'=>..., 'attribute_name'=>..., 'value_name'=>...] ]
	 */
	public static function get_defaults( $ty_category_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ty_attribute_id, ty_value_id, custom_value, attribute_name, value_name
			   FROM {$wpdb->prefix}wts_category_attr_defaults
			  WHERE ty_category_id = %d",
			(int) $ty_category_id
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$aid = (int) $r['ty_attribute_id'];
			$entry = array(
				'attributeId'    => $aid,
				'attribute_name' => $r['attribute_name'],
				'value_name'     => $r['value_name'],
			);
			if ( ! empty( $r['ty_value_id'] ) ) {
				$entry['attributeValueId'] = (int) $r['ty_value_id'];
			} elseif ( ! empty( $r['custom_value'] ) ) {
				$entry['customAttributeValue'] = (string) $r['custom_value'];
			} else {
				continue; // boş satır
			}
			$out[ $aid ] = $entry;
		}
		return $out;
	}

	/**
	 * Bir kategorinin attribute default'larını TOPLUCA kaydet.
	 * Eksik attribute'lar tablodan silinir.
	 *
	 * @param int   $ty_category_id
	 * @param array $items   [ ['attributeId'=>int, 'valueId'=>int|null, 'customValue'=>string|null, 'attribute_name'=>str, 'value_name'=>str], ... ]
	 * @return int  Kaydedilen satır sayısı
	 */
	public static function save_defaults( $ty_category_id, $items ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_category_attr_defaults';
		$ty_category_id = (int) $ty_category_id;
		if ( $ty_category_id <= 0 ) {
			return 0;
		}

		// Önce mevcut default'ları temizle (silinen attribute'lar için)
		$wpdb->delete( $tbl, array( 'ty_category_id' => $ty_category_id ), array( '%d' ) );

		$saved = 0;
		foreach ( (array) $items as $it ) {
			$aid    = isset( $it['attributeId'] ) ? (int) $it['attributeId'] : 0;
			$vid    = isset( $it['valueId'] ) && $it['valueId'] !== '' ? (int) $it['valueId'] : null;
			$custom = isset( $it['customValue'] ) ? trim( (string) $it['customValue'] ) : '';

			if ( $aid <= 0 ) continue;
			// Hiçbir değer yoksa kaydetme
			if ( ! $vid && '' === $custom ) continue;

			$wpdb->insert( $tbl, array(
				'ty_category_id'  => $ty_category_id,
				'ty_attribute_id' => $aid,
				'ty_value_id'     => $vid,
				'custom_value'    => $custom !== '' ? mb_substr( $custom, 0, 255 ) : null,
				'attribute_name'  => isset( $it['attribute_name'] ) ? mb_substr( (string) $it['attribute_name'], 0, 255 ) : null,
				'value_name'      => isset( $it['value_name'] ) ? mb_substr( (string) $it['value_name'], 0, 255 ) : null,
			) );
			$saved++;
		}

		WTS_Logger::success( 'cat_attr_defaults',
			"Kategori {$ty_category_id} için {$saved} attribute default kaydedildi." );
		return $saved;
	}

	/**
	 * Bir kategorinin TÜM attribute metadata'sını + (varsa) value listelerini + (varsa) kayıtlı default'ları
	 * UI'da render edebilecek formatta döndür.
	 *
	 * @param int $ty_category_id
	 * @return array {
	 *   success: bool,
	 *   error: string,
	 *   attributes: array  [ {attributeId, name, required, allowCustom, allowMultiple, varianter, values:[ {id,name}, ... ], saved_value_id, saved_custom_value} ]
	 * }
	 */
	public static function get_category_form_data( $ty_category_id ) {
		$resp = WTS_Categories::fetch_attributes( $ty_category_id );
		if ( ! $resp['success'] ) {
			return array( 'success' => false, 'error' => $resp['error'], 'attributes' => array() );
		}
		$cat_attrs = isset( $resp['data']['categoryAttributes'] ) ? $resp['data']['categoryAttributes'] : array();
		$saved     = self::get_defaults( $ty_category_id );

		$out = array();
		foreach ( $cat_attrs as $ca ) {
			if ( empty( $ca['attribute']['id'] ) ) continue;
			$aid    = (int) $ca['attribute']['id'];
			$name   = isset( $ca['attribute']['name'] ) ? (string) $ca['attribute']['name'] : '';

			// Önce categoryAttributes içinde gelmiş eski-stil values
			$values = array();
			if ( isset( $ca['attributeValues'] ) && is_array( $ca['attributeValues'] ) ) {
				foreach ( $ca['attributeValues'] as $v ) {
					if ( isset( $v['id'] ) && isset( $v['name'] ) ) {
						$values[] = array( 'id' => (int) $v['id'], 'name' => (string) $v['name'] );
					}
				}
			}
			// V2'de boş gelmiş olabilir → ayrı endpoint'ten çek (cache'li)
			if ( empty( $values ) ) {
				$values = WTS_Categories::fetch_attribute_values( $ty_category_id, $aid );
			}

			$entry = array(
				'attributeId'    => $aid,
				'name'           => $name,
				'required'       => ! empty( $ca['required'] ),
				'allowCustom'    => ! empty( $ca['allowCustom'] ),
				'allowMultiple'  => ! empty( $ca['allowMultipleAttributeValues'] ),
				'varianter'      => ! empty( $ca['varianter'] ),
				'values'         => $values,
				'saved_value_id' => isset( $saved[ $aid ]['attributeValueId'] ) ? (int) $saved[ $aid ]['attributeValueId'] : 0,
				'saved_custom'   => isset( $saved[ $aid ]['customAttributeValue'] ) ? (string) $saved[ $aid ]['customAttributeValue'] : '',
			);
			$out[] = $entry;
		}

		// Zorunlu olanlar üste
		usort( $out, function ( $a, $b ) {
			if ( $a['required'] !== $b['required'] ) {
				return $a['required'] ? -1 : 1;
			}
			return strcmp( $a['name'], $b['name'] );
		} );

		return array( 'success' => true, 'error' => '', 'attributes' => $out );
	}

	/**
	 * UI için tüm eşleştirilmiş kategorileri + default doluluk oranını listele.
	 *
	 * @return array  [ {ty_category_id, ty_category_path, total_required, filled_required} ]
	 */
	public static function list_mapped_categories() {
		global $wpdb;
		// product_map'te kullanılan veya category_map'te ty_category_id'si olan tüm kategoriler
		$rows = $wpdb->get_results(
			"SELECT DISTINCT cm.ty_category_id, cm.ty_category_path
			   FROM {$wpdb->prefix}wts_category_map cm
			  WHERE cm.ty_category_id IS NOT NULL AND cm.ty_category_id > 0
			  ORDER BY cm.ty_category_path ASC"
		);
		if ( empty( $rows ) ) return array();

		$out = array();
		foreach ( $rows as $r ) {
			$cat_id = (int) $r->ty_category_id;
			$total_req     = 0;
			$filled_req    = 0;

			// fetch_attributes cache'ten okur, hiç çekilmediyse API'ye düşer
			$resp = WTS_Categories::fetch_attributes( $cat_id );
			if ( $resp['success'] && ! empty( $resp['data']['categoryAttributes'] ) ) {
				$saved = self::get_defaults( $cat_id );
				foreach ( $resp['data']['categoryAttributes'] as $ca ) {
					if ( empty( $ca['required'] ) ) continue;
					$aid = isset( $ca['attribute']['id'] ) ? (int) $ca['attribute']['id'] : 0;
					$total_req++;
					if ( $aid && isset( $saved[ $aid ] ) ) $filled_req++;
				}
			}

			$out[] = array(
				'ty_category_id'   => $cat_id,
				'ty_category_path' => $r->ty_category_path,
				'total_required'   => $total_req,
				'filled_required'  => $filled_req,
			);
		}
		return $out;
	}

	/** Tek bir kategori için kaç zorunlu attribute eksik kaldığını döndür. */
	public static function missing_required_count( $ty_category_id ) {
		$resp = WTS_Categories::fetch_attributes( $ty_category_id );
		if ( ! $resp['success'] ) return 0;
		$cat_attrs = isset( $resp['data']['categoryAttributes'] ) ? $resp['data']['categoryAttributes'] : array();
		$saved     = self::get_defaults( $ty_category_id );
		$missing   = 0;
		foreach ( $cat_attrs as $ca ) {
			if ( empty( $ca['required'] ) ) continue;
			$aid = isset( $ca['attribute']['id'] ) ? (int) $ca['attribute']['id'] : 0;
			if ( $aid && ! isset( $saved[ $aid ] ) ) $missing++;
		}
		return $missing;
	}
}
