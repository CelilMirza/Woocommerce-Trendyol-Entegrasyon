<?php
/**
 * ============================================================
 *  Geliştirici: Digitalog  |  https://digitalog.com.tr
 *  İletişim ve özel yazılım çözümleri için: https://digitalog.com.tr
 * ============================================================
 *
 * Cron iskeleti.
 *
 *  - wts_cron_fast (15dk): batch sonuçları + son 1 gün sipariş + TÜM STOK senkronu
 *                          (Trendyol'dan tüm ürünler çekilir, ayarlı yöne göre WP↔TY eşitlenir).
 *  - wts_cron_slow (12sa): aynı stok senkronu + kategori/marka cache yenile +
 *                          son 30 gün sipariş doğrulama + log temizliği.
 *
 *  Eş zamanlı çalışma engeli: wts_cron_lock transient'i. Fast/slow/manuel butonlar
 *  aynı anda sync_all başlatamaz; ikinci çalışma atlanır ve loga warning düşer.
 *
 * @package WP_Trendyol_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTS_Cron {

	const HOOK_FAST = 'wts_cron_fast';
	const HOOK_SLOW = 'wts_cron_slow';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_intervals' ) );

		add_action( self::HOOK_FAST, array( __CLASS__, 'run_fast' ) );
		add_action( self::HOOK_SLOW, array( __CLASS__, 'run_slow' ) );

		self::ensure_scheduled();
	}

	public static function register_intervals( $schedules ) {
		$fast_min = max( 5, (int) WTS_Settings::get( 'cron_fast_minutes', 15 ) );
		$slow_hr  = max( 1, (int) WTS_Settings::get( 'cron_slow_hours', 12 ) );

		$schedules['wts_fast'] = array(
			'interval' => $fast_min * MINUTE_IN_SECONDS,
			'display'  => "Trendyol Senkron - Hızlı ({$fast_min} dk)",
		);
		$schedules['wts_slow'] = array(
			'interval' => $slow_hr * HOUR_IN_SECONDS,
			'display'  => "Trendyol Senkron - Yavaş ({$slow_hr} sa)",
		);
		return $schedules;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK_FAST ) ) {
			wp_schedule_event( time() + 60, 'wts_fast', self::HOOK_FAST );
		}
		if ( ! wp_next_scheduled( self::HOOK_SLOW ) ) {
			wp_schedule_event( time() + 300, 'wts_slow', self::HOOK_SLOW );
		}
	}

	public static function unschedule_all() {
		foreach ( array( self::HOOK_FAST, self::HOOK_SLOW ) as $h ) {
			$ts = wp_next_scheduled( $h );
			if ( $ts ) {
				wp_unschedule_event( $ts, $h );
			}
			wp_clear_scheduled_hook( $h );
		}
	}

	/* ---------- İşler ---------- */

	public static function run_fast() {
		if ( ! ( new WTS_API_Client() )->is_configured() ) {
			return;
		}

		// Eş zamanlı çalışma engeli — uzun süren bir senkron varsa ikinciyi atla.
		// (15dk fast cron, 12sa slow cron veya manuel butonla aynı anda çakışmasın.)
		if ( get_transient( 'wts_cron_lock' ) ) {
			WTS_Logger::warning( 'cron_fast', 'Önceki çalışma henüz bitmedi, atlanıyor.' );
			return;
		}
		set_transient( 'wts_cron_lock', time(), 10 * MINUTE_IN_SECONDS );

		if ( ! defined( 'WTS_SYNC_IN_PROGRESS' ) ) {
			define( 'WTS_SYNC_IN_PROGRESS', true );
		}

		try {
			// 1) Bekleyen batch sonuçlarını kontrol et
			WTS_Products::check_pending_batches();

			// 2) Son 1 gün siparişlerini güncelle
			WTS_Orders::pull_trendyol_orders( 1 );
			WTS_Orders::pull_wc_orders( 1 );

			// 3) Tüm stokları Trendyol'dan çek + ayarlı yöne göre senkronla.
			//    pull_all_ty_products() içinde sayfalı API çağrısı + cache update yapılır.
			//    sync_direction ayarına göre WP↔TY tarafları eşitlenir.
			WTS_Stock_Sync::sync_all();

			update_option( 'wts_cron_fast_last_run', current_time( 'mysql' ) );
		} catch ( \Throwable $e ) {
			WTS_Logger::error( 'cron_fast', 'Hata: ' . $e->getMessage() );
		}

		delete_transient( 'wts_cron_lock' );
	}

	public static function run_slow() {
		if ( ! ( new WTS_API_Client() )->is_configured() ) {
			return;
		}

		// Slow cron da aynı kilidi kullanır — fast ile çakışmasın.
		if ( get_transient( 'wts_cron_lock' ) ) {
			WTS_Logger::warning( 'cron_slow', 'Önceki çalışma henüz bitmedi, atlanıyor.' );
			return;
		}
		set_transient( 'wts_cron_lock', time(), 20 * MINUTE_IN_SECONDS );

		if ( ! defined( 'WTS_SYNC_IN_PROGRESS' ) ) {
			define( 'WTS_SYNC_IN_PROGRESS', true );
		}

		try {
			// 1) Kategori ve marka cache'i yenile
			WTS_Categories::sync_tree();
			WTS_Brands::sync_all();

			// 2) Stok senkronu (fast zaten yapıyor ama burada da çalışsın — slow daha geniş
			//    log ve son 30 gün sipariş doğrulamasıyla birlikte tam tarama yapar.)
			WTS_Stock_Sync::sync_all();

			// 3) Son 30 gün siparişlerini geriye dönük doğrula
			WTS_Orders::pull_trendyol_orders( 30 );
			WTS_Orders::pull_wc_orders( 30 );

			// 4) Log temizliği (90+ gün)
			WTS_Logger::purge_older_than( 90 );

			update_option( 'wts_cron_slow_last_run', current_time( 'mysql' ) );
		} catch ( \Throwable $e ) {
			WTS_Logger::error( 'cron_slow', 'Hata: ' . $e->getMessage() );
		}

		delete_transient( 'wts_cron_lock' );
	}

	public static function next_runs() {
		return array(
			'fast' => wp_next_scheduled( self::HOOK_FAST ),
			'slow' => wp_next_scheduled( self::HOOK_SLOW ),
		);
	}
}
