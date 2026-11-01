<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Admin;

use Dev3\AiSmartCoupons\Core3\Core\Assets;
use Dev3\AiSmartCoupons\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3SC_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3SC_NAME, __( 'DEV3 AI Smart Coupons', 'dev3-ai-smart-coupons-for-woocommerce' ), Capabilities::manage(), DEV3SC_SLUG, array( $this, 'render' ), 'dashicons-tickets-alt', 56 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		echo '<div class="wrap"><div id="dev3sc-admin-root" class="dev3sc-root"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
			// Real storefront banner styles for the live preview.
			if ( is_readable( DEV3SC_PATH . 'build/storefront.css' ) ) {
				wp_enqueue_style( 'dev3sc-storefront-preview', DEV3SC_URL . 'build/storefront.css', array(), DEV3SC_VERSION );
			}
			wp_add_inline_script(
				Assets::handle( 'admin' ),
				'window.dev3scAdmin = ' . wp_json_encode(
					array(
						'woo'          => class_exists( 'WooCommerce' ),
						'currency'     => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$',
						'timezone'     => wp_timezone_string(),
						'gmtOffset'    => (float) get_option( 'gmt_offset', 0 ),
						'freeShipping' => class_exists( 'WooCommerce' ) ? \Dev3\AiSmartCoupons\Engine\Offer_Engine::free_shipping_available() : false,
						'shippingUrl'  => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SC_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-smart-coupons-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
