<?php
/**
 * Coupon lifecycle: session binding, "applied" and "redeemed" tracking.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Coupons;

use Dev3\AiSmartCoupons\Data\Offers_Repository;
use Dev3\AiSmartCoupons\Engine\Offer_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce hooks.
 */
class Coupon_Hooks {

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'bind_to_session' ), 20, 2 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'applied' ) );
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'redeemed' ) );
		}
	}

	/**
	 * A generated coupon only works for the shopping session it was issued to (or its email).
	 *
	 * @param bool       $valid  Valid so far.
	 * @param \WC_Coupon $coupon Coupon.
	 * @return bool
	 * @throws \Exception When the coupon belongs to someone else (WooCommerce shows the message).
	 */
	public function bind_to_session( $valid, $coupon ) {
		if ( ! $valid || ! Coupon_Factory::is_generated( $coupon ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $valid;
		}
		/**
		 * Whether generated coupons are bound to the session they were issued to.
		 *
		 * @param bool       $bind   Bind.
		 * @param \WC_Coupon $coupon Coupon.
		 */
		if ( ! apply_filters( 'dev3sc_bind_coupon_to_session', true, $coupon ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return $valid;
		}
		$codes = array_map( 'strtoupper', (array) WC()->session->get( Offer_Engine::SESSION_CODES, array() ) );
		if ( in_array( strtoupper( $coupon->get_code() ), $codes, true ) ) {
			return $valid;
		}
		$emails = array_map( 'strtolower', (array) $coupon->get_email_restrictions() );
		$user   = wp_get_current_user();
		if ( $emails && $user && $user->exists() && in_array( strtolower( $user->user_email ), $emails, true ) ) {
			return $valid;
		}
		throw new \Exception( esc_html__( 'This personal offer belongs to another shopping session.', 'dev3-ai-smart-coupons-for-woocommerce' ), 100 );
	}

	/**
	 * Marks an offer applied.
	 *
	 * @param string $code Coupon code.
	 */
	public function applied( $code ) {
		$row = Offers_Repository::by_code( $code );
		if ( $row && empty( $row['applied_at'] ) ) {
			Offers_Repository::update(
				(int) $row['id'],
				array(
					'applied_at' => gmdate( 'Y-m-d H:i:s' ),
					'status'     => 'active' === $row['status'] ? 'applied' : $row['status'],
				)
			);
		}
	}

	/**
	 * Marks offers redeemed by a paid / on-hold order (idempotent).
	 *
	 * @param int $order_id Order id.
	 */
	public function redeemed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$row = Offers_Repository::by_code( $item->get_code() );
			if ( ! $row || ! empty( $row['redeemed_at'] ) ) {
				continue;
			}
			$discount = (float) $item->get_discount();
			if ( 'free_shipping' === $row['type'] ) {
				$discount = (float) $row['discount_value'];
			}
			Offers_Repository::update(
				(int) $row['id'],
				array(
					'status'         => 'redeemed',
					'redeemed_at'    => gmdate( 'Y-m-d H:i:s' ),
					'applied_at'     => $row['applied_at'] ? $row['applied_at'] : gmdate( 'Y-m-d H:i:s' ),
					'order_id'       => (int) $order->get_id(),
					'order_total'    => (float) $order->get_total(),
					'order_discount' => round( $discount, 2 ),
				)
			);
			$order->update_meta_data( '_dev3sc_offer_id', (int) $row['id'] );
			$order->save_meta_data();
		}
	}
}
