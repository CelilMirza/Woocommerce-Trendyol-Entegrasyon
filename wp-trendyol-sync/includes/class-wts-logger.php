<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Log yöneticisi: API çağrıları + senkron olayları.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Logger {

	const LEVEL_DEBUG   = 'debug';
	const LEVEL_INFO    = 'info';
	const LEVEL_SUCCESS = 'success';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Genel log yaz.
	 *
	 * @param array $args
	 *   direction   : 'wp_to_ty' | 'ty_to_wp' | 'internal'
	 *   action      : 'push_product' | 'pull_orders' | 'category_sync' | ...
	 *   wp_post_id  : int|null
	 *   ty_barcode  : string|null
	 *   batch_id    : string|null
	 *   status      : 'pending'|'success'|'error'|'warning'
	 *   message     : string
	 *   payload     : array|string|null  (JSON encode edilir)
	 */
	public static function log( $args ) {
		global $wpdb;
		$defaults = array(
			'direction'  => 'internal',
			'action'     => '',
			'wp_post_id' => null,
			'ty_barcode' => null,
			'batch_id'   => null,
			'status'     => 'info',
			'message'    => '',
			'payload'    => null,
		);
		$a = wp_parse_args( $args, $defaults );

		if ( is_array( $a['payload'] ) || is_object( $a['payload'] ) ) {
			$a['payload'] = wp_json_encode( $a['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$wpdb->insert(
			$wpdb->prefix . 'wts_sync_log',
			array(
				'direction'  => (string) $a['direction'],
				'action'     => (string) $a['action'],
				'wp_post_id' => $a['wp_post_id'] ? (int) $a['wp_post_id'] : null,
				'ty_barcode' => $a['ty_barcode'] ? (string) $a['ty_barcode'] : null,
				'batch_id'   => $a['batch_id'] ? (string) $a['batch_id'] : null,
				'status'     => (string) $a['status'],
				'message'    => (string) $a['message'],
				'payload'    => $a['payload'],
			)
		);
		return $wpdb->insert_id;
	}

	public static function info( $action, $message, $extra = array() ) {
		return self::log( array_merge( array(
			'action'  => $action,
			'status'  => self::LEVEL_INFO,
			'message' => $message,
		), $extra ) );
	}

	public static function success( $action, $message, $extra = array() ) {
		return self::log( array_merge( array(
			'action'  => $action,
			'status'  => self::LEVEL_SUCCESS,
			'message' => $message,
		), $extra ) );
	}

	public static function error( $action, $message, $extra = array() ) {
		return self::log( array_merge( array(
			'action'  => $action,
			'status'  => self::LEVEL_ERROR,
			'message' => $message,
		), $extra ) );
	}

	public static function warning( $action, $message, $extra = array() ) {
		return self::log( array_merge( array(
			'action'  => $action,
			'status'  => self::LEVEL_WARNING,
			'message' => $message,
		), $extra ) );
	}

	/**
	 * Filtreli log getir (admin sayfası için).
	 */
	public static function fetch( $args = array() ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_sync_log';

		$defaults = array(
			'limit'      => 100,
			'offset'     => 0,
			'status'     => '',
			'action'     => '',
			'direction'  => '',
			'wp_post_id' => 0,
			'ty_barcode' => '',
			'search'     => '',
		);
		$a = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $a['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $a['status'];
		}
		if ( $a['action'] ) {
			$where[]  = 'action = %s';
			$params[] = $a['action'];
		}
		if ( $a['direction'] ) {
			$where[]  = 'direction = %s';
			$params[] = $a['direction'];
		}
		if ( $a['wp_post_id'] ) {
			$where[]  = 'wp_post_id = %d';
			$params[] = (int) $a['wp_post_id'];
		}
		if ( $a['ty_barcode'] ) {
			$where[]  = 'ty_barcode = %s';
			$params[] = $a['ty_barcode'];
		}
		if ( $a['search'] ) {
			$where[]  = '(message LIKE %s OR payload LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $a['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = (int) $a['limit'];
		$params[] = (int) $a['offset'];

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count( $args = array() ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_sync_log';

		$defaults = array(
			'status'     => '',
			'action'     => '',
			'direction'  => '',
			'wp_post_id' => 0,
		);
		$a = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'status', 'action', 'direction' ) as $k ) {
			if ( $a[ $k ] ) {
				$where[]  = "{$k} = %s";
				$params[] = $a[ $k ];
			}
		}
		if ( $a['wp_post_id'] ) {
			$where[]  = 'wp_post_id = %d';
			$params[] = (int) $a['wp_post_id'];
		}
		$sql = "SELECT COUNT(*) FROM {$tbl} WHERE " . implode( ' AND ', $where );
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		return (int) $wpdb->get_var( $sql );
	}

	public static function purge_older_than( $days = 30 ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'wts_sync_log';
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$tbl} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			(int) $days
		) );
	}
}
