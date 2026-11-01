<?php
/**
 * PHPUnit bootstrap: WordPress test suite from wp-env (WP_TESTS_DIR) + this plugin and its dependencies.
 *
 * Run: npx wp-env run tests-cli --env-cwd=wp-content/plugins/dev3-ai-smart-coupons-for-woocommerce vendor/bin/phpunit
 *
 * @package Dev3\AiSmartCoupons
 */

$dev3sc_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';
if ( ! file_exists( $dev3sc_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test suite not found in {$dev3sc_tests_dir}. Run the tests inside wp-env (tests-cli).\n"; // phpcs:ignore
	exit( 1 );
}
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $dev3sc_tests_dir . '/includes/functions.php';

tests_add_filter(
	'dev3sc_test_dependencies',
	static function ( $deps ) {
		$deps[] = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php';
		return $deps;
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		// Install WooCommerce tables/options once for the test database.
		if ( class_exists( 'WC_Install' ) ) {
			define( 'WC_REMOVE_ALL_DATA', true );
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore
			wp_roles();
			\Dev3\AiSmartCoupons\Plugin::activate();
		}
	}
);

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Load required third-party plugins first when they are installed in wp-env (e.g. WooCommerce).
		foreach ( (array) apply_filters( 'dev3sc_test_dependencies', array() ) as $dev3sc_dep ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $dev3sc_dep ) ) {
				require WP_PLUGIN_DIR . '/' . $dev3sc_dep;
			}
		}
		require dirname( __DIR__, 2 ) . '/dev3-ai-smart-coupons-for-woocommerce.php';
	}
);

require $dev3sc_tests_dir . '/includes/bootstrap.php';
