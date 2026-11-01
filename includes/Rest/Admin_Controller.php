<?php
/**
 * Admin endpoints: analytics, flash sales (+ AI copy), simulator, product/category lookup.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Rest;

use Dev3\AiSmartCoupons\Core3\Core\Settings_Store;
use Dev3\AiSmartCoupons\Core3\Rest\Base_Controller;
use Dev3\AiSmartCoupons\Data\Offers_Repository;
use Dev3\AiSmartCoupons\Engine\Budget;
use Dev3\AiSmartCoupons\Engine\Flash_Sales;
use Dev3\AiSmartCoupons\Engine\Offer_Engine;
use Dev3\AiSmartCoupons\Engine\Risk_Score;
use Dev3\AiSmartCoupons\Engine\Rules;
use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller (nonce + manage capability on every route).
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		register_rest_route(
			self::NS,
			'/analytics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'analytics' ),
				'permission_callback' => $manage,
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 365,
						'default' => 30,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/flash-sales',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => fn() => $this->respond( Flash_Sales::all() ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_flash' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/flash-sales/copy',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'flash_copy' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/simulate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'simulate' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => $manage,
				'args'                => array(
					'type'    => array(
						'type'    => 'string',
						'enum'    => array( 'products', 'categories' ),
						'default' => 'products',
					),
					'search'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'include' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Analytics.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analytics( \WP_REST_Request $request ) {
		$stats  = Offers_Repository::stats( (int) $request['days'] );
		$recent = array_map(
			static function ( $row ) {
				$row['order_url'] = $row['order_id'] ? self::order_url( (int) $row['order_id'] ) : '';
				return $row;
			},
			Offers_Repository::recent( 25 )
		);
		$budget = Budget::state();
		return $this->respond(
			$stats + array(
				'recent'   => $recent,
				'budget'   => array(
					'limit'     => (float) Settings::group( 'rules' )['daily_budget'],
					'spent'     => $budget['spent'],
					'remaining' => Budget::remaining(),
				),
				'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			)
		);
	}

	/**
	 * Admin order URL (HPOS-aware).
	 *
	 * @param int $order_id Order.
	 * @return string
	 */
	private static function order_url( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? $order->get_edit_order_url() : '';
	}

	/**
	 * Saves flash sales.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_flash( \WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		return $this->respond( Flash_Sales::save( is_array( $items ) ? $items : array() ) );
	}

	/**
	 * AI copy for a flash sale.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function flash_copy( \WP_REST_Request $request ) {
		$minutes = (int) $request->get_param( 'minutes' );
		return $this->respond( self::brand( Flash_Sales::generate_copy( (string) $request->get_param( 'brief' ), (string) $request->get_param( 'tone' ), max( 0, $minutes ) ) ) );
	}

	/**
	 * Product UI says "AI", not the engine name: rewrites shared-core error texts.
	 *
	 * @param mixed $result Result or WP_Error.
	 * @return mixed
	 */
	public static function brand( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$code = $result->get_error_code();
		$data = $result->get_error_data();
		if ( is_array( $data ) && isset( $data['hint'] ) ) {
			$data['hint'] = str_replace( 'Gemini', 'The AI service', (string) $data['hint'] );
		}
		return new \WP_Error( $code, str_replace( array( 'Gemini’s', 'Gemini' ), array( 'The AI service’s', 'The AI service' ), $result->get_error_message() ), $data );
	}

	/**
	 * Risk + offer simulator (never creates a coupon).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function simulate( \WP_REST_Request $request ) {
		$p        = (array) $request->get_json_params() + (array) $request->get_params();
		$value    = Settings::clamp( isset( $p['cart_value'] ) ? $p['cart_value'] : 80, 0, 1000000 );
		$cost_pct = Settings::clamp( isset( $p['cost_percent'] ) ? $p['cost_percent'] : 50, 0, 200 );
		$trigger  = isset( $p['trigger'] ) && in_array( $p['trigger'], Tracker_Controller::TRIGGERS, true ) ? $p['trigger'] : 'idle';
		$page     = isset( $p['page_type'] ) && in_array( $p['page_type'], Tracker_Controller::PAGES, true ) ? $p['page_type'] : 'cart';
		$signals  = array(
			'trigger'               => $trigger,
			'page_type'             => $page,
			'idle_seconds'          => Settings::clamp( isset( $p['idle_seconds'] ) ? $p['idle_seconds'] : 45, 0, 3600, true ),
			'cart_visits'           => Settings::clamp( isset( $p['cart_visits'] ) ? $p['cart_visits'] : 1, 0, 100, true ),
			'is_returning'          => ! empty( $p['returning'] ),
			'returning'             => ! empty( $p['returning'] ),
			'logged_in'             => ! empty( $p['order_count'] ),
			'order_count'           => Settings::clamp( isset( $p['order_count'] ) ? $p['order_count'] : 0, 0, 1000, true ),
			'days_since_last_order' => isset( $p['days_since_last_order'] ) && '' !== $p['days_since_last_order'] ? Settings::clamp( $p['days_since_last_order'], 0, 10000, true ) : null,
			'cart_value'            => $value,
			'aov'                   => Offer_Engine::aov(),
		);
		$risk     = Risk_Score::score( $signals );
		$rules    = Settings::group( 'rules' );
		$symbol   = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$snapshot = array(
			'subtotal'        => $value,
			'shipping'        => 0,
			'currency'        => get_woocommerce_currency(),
			'currency_symbol' => $symbol,
			'item_count'      => 2,
			'categories'      => array( __( 'Sample category', 'dev3-ai-smart-coupons-for-woocommerce' ) ),
			'lines'           => array(
				array(
					'product_id' => 0,
					'qty'        => 1,
					'line_total' => $value,
					'unit_cost'  => $value * $cost_pct / 100,
					'on_sale'    => false,
				),
			),
		);
		$ctx      = array(
			'budget_remaining'        => Budget::remaining(),
			'free_shipping_available' => Offer_Engine::free_shipping_available(),
			'banner'                  => Settings::group( 'banner' ),
		);
		$out      = array(
			'risk'      => $risk,
			'threshold' => (int) $rules['risk_threshold'],
			'eligible'  => $risk['score'] >= (int) $rules['risk_threshold'],
			'proposal'  => null,
			'source'    => 'fallback',
		);
		$offer    = null;
		if ( ! empty( $p['use_ai'] ) ) {
			if ( '' === Settings_Store::api_key() ) {
				$out['ai_error'] = __( 'Add an API key in API Config first.', 'dev3-ai-smart-coupons-for-woocommerce' );
			} else {
				$proposal = Offer_Engine::ask_ai( $snapshot, $risk, $rules, $signals, $ctx );
				if ( is_wp_error( $proposal ) ) {
					$out['ai_error'] = self::brand( $proposal )->get_error_message();
				} else {
					$out['proposal'] = $proposal;
					$offer           = Rules::clamp( $proposal, $snapshot, $rules, $ctx );
					$out['source']   = is_wp_error( $offer ) ? 'fallback' : 'ai';
					if ( is_wp_error( $offer ) ) {
						$out['ai_error'] = $offer->get_error_message();
						$offer           = null;
					}
				}
			}
		}
		if ( ! $offer ) {
			$offer = Rules::fallback( $risk['score'], $snapshot, $rules, $ctx );
		}
		$out['offer'] = is_wp_error( $offer ) ? null : $offer;
		if ( is_wp_error( $offer ) ) {
			$out['offer_error'] = $offer->get_error_message();
		}
		return $this->respond( $out );
	}

	/**
	 * Product / category lookup for the exclusion pickers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function lookup( \WP_REST_Request $request ) {
		$include = array_filter( array_map( 'intval', explode( ',', (string) $request['include'] ) ) );
		$search  = sanitize_text_field( (string) $request['search'] );
		if ( 'categories' === $request['type'] ) {
			$args = array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 30,
			);
			if ( $include ) {
				$args['include'] = $include;
			} elseif ( '' !== $search ) {
				$args['search'] = $search;
			}
			$terms = get_terms( $args );
			$terms = is_wp_error( $terms ) ? array() : $terms;
			return $this->respond(
				array_map(
					static fn( $t ) => array(
						'id'   => (int) $t->term_id,
						'name' => $t->name,
					),
					$terms
				)
			);
		}
		$args = array(
			'limit'  => 20,
			'status' => array( 'publish', 'private', 'draft' ),
			'type'   => array( 'simple', 'variable', 'variation', 'grouped', 'external' ),
		);
		if ( $include ) {
			$args['include'] = $include;
		} elseif ( '' !== $search ) {
			$args['s'] = $search;
		}
		return $this->respond(
			array_map(
				static fn( $p ) => array(
					'id'   => $p->get_id(),
					'name' => wp_strip_all_tags( $p->get_formatted_name() ),
				),
				wc_get_products( $args )
			)
		);
	}
}
