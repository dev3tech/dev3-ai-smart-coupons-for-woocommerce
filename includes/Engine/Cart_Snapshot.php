<?php
/**
 * Reads the live WooCommerce cart into a plain array the rules engine can reason about.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Engine;

use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot builder.
 */
class Cart_Snapshot {

	/**
	 * Snapshot of a WC_Cart.
	 *
	 * @param \WC_Cart|null $cart Cart (defaults to WC()->cart).
	 * @return array { subtotal, shipping, currency, currency_symbol, item_count, lines[], categories[] }
	 */
	public static function from_cart( $cart = null ) {
		$cart  = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$rules = Settings::group( 'rules' );
		$key   = (string) $rules['cost_meta_key'];
		$lines = array();
		$cats  = array();
		$count = 0;
		if ( $cart ) {
			foreach ( $cart->get_cart() as $item ) {
				$product = isset( $item['data'] ) ? $item['data'] : null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$parent_id = $product->get_parent_id();
				$cat_ids   = $parent_id ? wc_get_product_cat_ids( $parent_id ) : $product->get_category_ids();
				foreach ( $cat_ids as $cat_id ) {
					$term = get_term( $cat_id, 'product_cat' );
					if ( $term && ! is_wp_error( $term ) ) {
						$cats[ $cat_id ] = $term->name;
					}
				}
				$qty     = (int) $item['quantity'];
				$count  += $qty;
				$lines[] = array(
					'product_id'   => (int) $product->get_id(),
					'parent_id'    => (int) $parent_id,
					'qty'          => $qty,
					'line_total'   => (float) ( isset( $item['line_subtotal'] ) ? $item['line_subtotal'] : 0 ),
					'unit_cost'    => self::unit_cost( $product, $key ),
					'category_ids' => array_map( 'intval', $cat_ids ),
					'on_sale'      => $product->is_on_sale(),
					'name'         => $product->get_name(),
				);
			}
		}
		$shipping = $cart ? (float) $cart->get_shipping_total() : 0.0;
		return array(
			'subtotal'        => $cart ? (float) $cart->get_displayed_subtotal() : 0.0,
			'shipping'        => $shipping,
			'currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$',
			'item_count'      => $count,
			'lines'           => $lines,
			'categories'      => array_values( $cats ),
		);
	}

	/**
	 * Unit cost from the configured meta key (variation first, then its parent).
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Meta key.
	 * @return float|null
	 */
	public static function unit_cost( $product, $key ) {
		if ( '' === $key ) {
			return null;
		}
		$ids = array( $product->get_id() );
		if ( $product->get_parent_id() ) {
			$ids[] = $product->get_parent_id();
		}
		foreach ( $ids as $id ) {
			$raw = get_post_meta( $id, $key, true );
			if ( '' !== $raw && null !== $raw && is_numeric( str_replace( ',', '.', (string) $raw ) ) ) {
				return max( 0.0, (float) str_replace( ',', '.', (string) $raw ) );
			}
		}
		/**
		 * Unit cost for a product when the meta key is empty (e.g. read from another cost plugin).
		 *
		 * @param float|null  $cost    Cost or null when unknown.
		 * @param \WC_Product $product Product.
		 */
		$cost = apply_filters( 'dev3sc_product_cost', null, $product );
		return null === $cost ? null : max( 0.0, (float) $cost );
	}
}
