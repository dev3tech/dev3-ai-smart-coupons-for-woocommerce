<?php
/**
 * Public storefront endpoint: POST dev3sc/v1/track (called only when a trigger fires).
 *
 * Security: same-origin + per-IP rate limit (core public_permission), strict argument schema,
 * WooCommerce session binding (the offer is computed from — and returned to — the caller's own
 * session only). No other shopper's data is ever read or returned.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Rest;

use Dev3\AiSmartCoupons\Core3\Rest\Base_Controller;
use Dev3\AiSmartCoupons\Engine\Offer_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Tracker controller.
 */
class Tracker_Controller extends Base_Controller {

	const TRIGGERS = array( 'exit_intent', 'scroll_up', 'tab_hidden', 'idle' );
	const PAGES    = array( 'cart', 'checkout', 'product', 'shop', 'home', 'other' );

	/**
	 * Routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/track',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'track' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'trigger'      => array(
						'type'     => 'string',
						'enum'     => self::TRIGGERS,
						'required' => true,
					),
					'page_type'    => array(
						'type'    => 'string',
						'enum'    => self::PAGES,
						'default' => 'other',
					),
					'idle_seconds' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 86400,
						'default' => 0,
					),
					'dwell'        => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 86400,
						'default' => 0,
					),
					'cart_visits'  => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
						'default' => 0,
					),
					'returning'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'device'       => array(
						'type'    => 'string',
						'enum'    => array( 'desktop', 'mobile' ),
						'default' => 'desktop',
					),
				),
			)
		);
	}

	/**
	 * Same-origin + rate limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'dev3sc_no_wc', __( 'WooCommerce is not active.', 'dev3-ai-smart-coupons-for-woocommerce' ), array( 'status' => 503 ) );
		}
		/**
		 * Tracker requests per minute per IP.
		 *
		 * @param int $limit Limit.
		 */
		return $this->public_permission( $request, 'track', (int) apply_filters( 'dev3sc_track_rate_limit', 12 ) );
	}

	/**
	 * Loads the caller's WooCommerce session + cart (REST requests do not load them by default).
	 *
	 * @return bool Whether a session exists.
	 */
	public static function load_session() {
		if ( ! WC()->session || ! WC()->cart ) {
			wc_load_cart();
		}
		if ( ! WC()->session || ! WC()->session->has_session() ) {
			return false;
		}
		if ( WC()->cart ) {
			WC()->cart->get_cart(); // Lazy-loads the cart from the session.
			WC()->cart->calculate_totals();
		}
		return true;
	}

	/**
	 * Handles a trigger.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function track( \WP_REST_Request $request ) {
		$response = $this->respond( $this->evaluate( $request ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Builds the tracker result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function evaluate( \WP_REST_Request $request ) {
		if ( ! self::load_session() ) {
			return array(
				'offer'  => null,
				'reason' => 'no_session',
			);
		}
		$signals = array(
			'trigger'      => (string) $request['trigger'],
			'page_type'    => (string) $request['page_type'],
			'idle_seconds' => (int) $request['idle_seconds'],
			'dwell'        => (int) $request['dwell'],
			'cart_visits'  => (int) $request['cart_visits'],
			'is_returning' => (bool) $request['returning'],
			'returning'    => (bool) $request['returning'],
			'device'       => (string) $request['device'],
		);
		$result  = Offer_Engine::handle_trigger( $signals );
		unset( $result['risk'] ); // Internal; not needed by the browser.
		return $result;
	}
}
