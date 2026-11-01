<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons;

use Dev3\AiSmartCoupons\Core3\Core\Assets;
use Dev3\AiSmartCoupons\Core3\Core\Schema;
use Dev3\AiSmartCoupons\Data\Offers_Repository;
use Dev3\AiSmartCoupons\Scheduler\Sweeper;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3sc_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3SC_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'dev3sc_license_check' ); // Licence check from builds before licensing was removed.
		Sweeper::unschedule();
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		add_filter( 'dev3sc_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3sc_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3sc_schema_sql', array( Offers_Repository::class, 'schema' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function woo_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		load_plugin_textdomain( 'dev3-ai-smart-coupons-for-woocommerce', false, dirname( plugin_basename( DEV3SC_FILE ) ) . '/languages' );
		self::register_schema();
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3SC_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', static fn() => Assets::register( array( 'admin' ) ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! self::woo_active() ) {
			add_action( 'admin_notices', array( $this, 'woo_notice' ) );
			return;
		}
		( new Coupons\Coupon_Hooks() )->register();
		( new Scheduler\Sweeper() )->register();
		( new Frontend\Storefront() )->register();
	}

	/**
	 * Notice when WooCommerce is missing.
	 */
	public function woo_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'DEV3 AI Smart Coupons & Dynamic Discounts', 'dev3-ai-smart-coupons-for-woocommerce' ),
			esc_html__( 'needs WooCommerce. Install and activate WooCommerce to start generating offers.', 'dev3-ai-smart-coupons-for-woocommerce' )
		);
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		if ( self::woo_active() ) {
			( new Rest\Tracker_Controller() )->register_routes();
			( new Rest\Admin_Controller() )->register_routes();
		}
	}
}
