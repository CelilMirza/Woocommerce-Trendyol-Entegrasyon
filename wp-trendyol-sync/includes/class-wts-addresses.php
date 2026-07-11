<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Trendyol satıcı adres yönetimi (getSuppliersAddresses).
 *
 *  - Satıcı adreslerini çekip cache'ler.
 *  - createProducts payload'unda shipmentAddressId / returningAddressId
 *    seçimini bu cache üzerinden yapar (ayarlardaki varsayılan ID'ye düşer).
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Addresses {

	/**
	 * Trendyol'dan satıcı adreslerini çekip lokal tabloya yaz.
	 *
	 * Trendyol response yapısı:
	 * {
	 *   "supplierAddresses": [
	 *     { "id": 1, "addressType": "Shipment", "isDefault": true, "presentName": "Depo", ... },
	 *     ...
	 *   ]
	 * }
	 * veya bazı eski response'larda { "id":..., "shipmentAddressList":[], "returningAddressList":[] }
	 *
	 * @return array ['success'=>bool, 'count'=>int, 'error'=>string]
	 */
	public static function sync() {
		global $wpdb;
		$api = new WTS_API_Client();
		if ( ! $api->is_configured() ) {
			return array( 'success' => false, 'count' => 0, 'error' => 'API yapılandırılmamış.' );
		}

		$resp = $api->get_suppliers_addresses();
		if ( ! $resp['success'] ) {
			return array( 'success' => false, 'count' => 0, 'error' => $resp['error'] );
		}

		$tbl = $wpdb->prefix . 'wts_supplier_addresses';
		$wpdb->query( "TRUNCATE TABLE {$tbl}" );

		$data  = is_array( $resp['data'] ) ? $resp['data'] : array();
		$count = 0;
		$default_shipment  = 0;
		$default_returning = 0;

		// Modern yapı: supplierAddresses[]
		if ( ! empty( $data['supplierAddresses'] ) && is_array( $data['supplierAddresses'] ) ) {
			foreach ( $data['supplierAddresses'] as $a ) {
				$id  = isset( $a['id'] ) ? (int) $a['id'] : 0;
				$type= isset( $a['addressType'] ) ? (string) $a['addressType'] : '';
				if ( $id <= 0 ) {
					continue;
				}
				$is_def_ship = ! empty( $a['isDefault'] ) && ( strcasecmp( $type, 'Shipment' ) === 0 || strcasecmp( $type, 'ShipmentAddress' ) === 0 || strcasecmp( $type, 'Both' ) === 0 );
				$is_def_ret  = ! empty( $a['isDefault'] ) && ( strcasecmp( $type, 'Returning' ) === 0 || strcasecmp( $type, 'ReturningAddress' ) === 0 || strcasecmp( $type, 'Both' ) === 0 );
				if ( $is_def_ship && ! $default_shipment )  $default_shipment  = $id;
				if ( $is_def_ret  && ! $default_returning ) $default_returning = $id;
				$wpdb->replace( $tbl, array(
					'ty_address_id'      => $id,
					'address_type'       => $type,
					'present_name'       => isset( $a['presentName'] ) ? (string) $a['presentName'] : ( isset( $a['fullName'] ) ? (string) $a['fullName'] : '' ),
					'is_default_shipment'=> $is_def_ship ? 1 : 0,
					'is_default_returning'=> $is_def_ret ? 1 : 0,
					'city'               => isset( $a['city'] ) ? (string) $a['city'] : '',
					'district'           => isset( $a['district'] ) ? (string) $a['district'] : '',
					'postcode'           => isset( $a['postCode'] ) ? (string) $a['postCode'] : ( isset( $a['postcode'] ) ? (string) $a['postcode'] : '' ),
					'full_address'       => isset( $a['address'] ) ? (string) $a['address'] : ( isset( $a['fullAddress'] ) ? (string) $a['fullAddress'] : '' ),
				) );
				$count++;
			}
		}

		// Eski yapı: shipmentAddressList / returningAddressList
		if ( ! empty( $data['shipmentAddressList'] ) && is_array( $data['shipmentAddressList'] ) ) {
			foreach ( $data['shipmentAddressList'] as $a ) {
				$id = isset( $a['id'] ) ? (int) $a['id'] : 0;
				if ( $id <= 0 ) continue;
				$is_def = ! empty( $a['isDefault'] );
				if ( $is_def && ! $default_shipment ) $default_shipment = $id;
				$wpdb->replace( $tbl, array(
					'ty_address_id'      => $id,
					'address_type'       => 'Shipment',
					'present_name'       => isset( $a['presentName'] ) ? (string) $a['presentName'] : '',
					'is_default_shipment'=> $is_def ? 1 : 0,
					'is_default_returning'=> 0,
					'city'               => isset( $a['city'] ) ? (string) $a['city'] : '',
					'district'           => isset( $a['district'] ) ? (string) $a['district'] : '',
					'postcode'           => isset( $a['postCode'] ) ? (string) $a['postCode'] : '',
					'full_address'       => isset( $a['address'] ) ? (string) $a['address'] : '',
				) );
				$count++;
			}
		}
		if ( ! empty( $data['returningAddressList'] ) && is_array( $data['returningAddressList'] ) ) {
			foreach ( $data['returningAddressList'] as $a ) {
				$id = isset( $a['id'] ) ? (int) $a['id'] : 0;
				if ( $id <= 0 ) continue;
				$is_def = ! empty( $a['isDefault'] );
				if ( $is_def && ! $default_returning ) $default_returning = $id;
				// Aynı id zaten shipment olarak yazıldıysa returning bayrağını da set et
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT ty_address_id FROM {$tbl} WHERE ty_address_id = %d", $id ) );
				if ( $existing ) {
					$wpdb->update( $tbl, array( 'is_default_returning' => $is_def ? 1 : 0 ), array( 'ty_address_id' => $id ) );
				} else {
					$wpdb->replace( $tbl, array(
						'ty_address_id'      => $id,
						'address_type'       => 'Returning',
						'present_name'       => isset( $a['presentName'] ) ? (string) $a['presentName'] : '',
						'is_default_shipment'=> 0,
						'is_default_returning'=> $is_def ? 1 : 0,
						'city'               => isset( $a['city'] ) ? (string) $a['city'] : '',
						'district'           => isset( $a['district'] ) ? (string) $a['district'] : '',
						'postcode'           => isset( $a['postCode'] ) ? (string) $a['postCode'] : '',
						'full_address'       => isset( $a['address'] ) ? (string) $a['address'] : '',
					) );
					$count++;
				}
			}
		}

		// Ayarlarda hiçbir default seçili değilse Trendyol'un default'unu uygula
		$s = wts_settings();
		$dirty = false;
		if ( empty( $s['default_shipment_address_id'] ) && $default_shipment ) {
			$s['default_shipment_address_id'] = $default_shipment;
			$dirty = true;
		}
		if ( empty( $s['default_returning_address_id'] ) && $default_returning ) {
			$s['default_returning_address_id'] = $default_returning;
			$dirty = true;
		}
		if ( $dirty ) {
			update_option( WTS_Settings::OPTION_KEY, $s );
		}

		update_option( 'wts_addresses_last_sync', current_time( 'mysql' ) );
		WTS_Logger::success( 'addresses_sync', "{$count} satıcı adresi cache'lendi." );
		return array( 'success' => true, 'count' => $count, 'error' => '' );
	}

	public static function all() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wts_supplier_addresses ORDER BY is_default_shipment DESC, ty_address_id ASC" );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wts_supplier_addresses" );
	}

	public static function last_sync() {
		return get_option( 'wts_addresses_last_sync', '' );
	}

	/** Ayarlardan seçili shipment adresi ID'sini döndür; yoksa cache'ten ilk default'u. */
	public static function default_shipment_id() {
		$id = (int) WTS_Settings::get( 'default_shipment_address_id', 0 );
		if ( $id ) return $id;
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT ty_address_id FROM {$wpdb->prefix}wts_supplier_addresses WHERE is_default_shipment = 1 LIMIT 1" );
	}

	public static function default_returning_id() {
		$id = (int) WTS_Settings::get( 'default_returning_address_id', 0 );
		if ( $id ) return $id;
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT ty_address_id FROM {$wpdb->prefix}wts_supplier_addresses WHERE is_default_returning = 1 LIMIT 1" );
	}
}
