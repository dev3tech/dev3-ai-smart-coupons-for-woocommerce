<?php
/**
 * Plugin Name:       DEV3 AI Smart Coupons & Dynamic Discounts for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-smart-coupons-for-woocommerce
 * Description:       AI cart-abandonment detection with personalised, margin-safe single-use coupons and countdown urgency banners for WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-smart-coupons-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.6
 * WC tested up to:   11.2
 *
 * @package Dev3\AiSmartCoupons
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3SC_VERSION', '1.0.0' );
define( 'DEV3SC_FILE', __FILE__ );
define( 'DEV3SC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3SC_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3SC_SLUG', 'dev3-ai-smart-coupons-for-woocommerce' );
define( 'DEV3SC_NAME', 'DEV3 AI Smart Coupons & Dynamic Discounts for WooCommerce' );
define( 'DEV3SC_MIN_PHP', '7.4' );
define( 'DEV3SC_MIN_WP', '6.0' );
define( 'DEV3SC_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3SC_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\\AiSmartCoupons\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3SC_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3SC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3SC_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiSmartCoupons\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiSmartCoupons\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiSmartCoupons\Plugin', 'instance' ) );
