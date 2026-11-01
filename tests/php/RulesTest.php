<?php
/**
 * Safety-rule clamping: caps, margin floor, exclusions, budget, malicious model output, fallback.
 *
 * @package Dev3\AiSmartCoupons
 */

use Dev3\AiSmartCoupons\Engine\Copy;
use Dev3\AiSmartCoupons\Engine\Rules;
use Dev3\AiSmartCoupons\Settings;

class RulesTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		\Dev3\AiSmartCoupons\Core3\Core\Settings_Store::flush();
	}

	private function rules( $over = array() ) {
		return array_merge(
			Settings::rule_defaults(),
			array(
				'allowed_types'      => array( 'percent', 'fixed_cart', 'free_shipping' ),
				'min_percent'        => 5,
				'max_percent'        => 20,
				'min_fixed'          => 2,
				'max_fixed'          => 25,
				'min_spend'          => 30,
				'min_margin_percent' => 10,
				'urgency_min'        => 10,
				'urgency_max'        => 30,
				'exclude_sale_items' => true,
			),
			$over
		);
	}

	private function snapshot( $lines = null ) {
		$lines = $lines ? $lines : array(
			array( 'product_id' => 1, 'qty' => 1, 'line_total' => 100, 'unit_cost' => 40, 'category_ids' => array( 7 ), 'on_sale' => false ),
			array( 'product_id' => 2, 'qty' => 2, 'line_total' => 50, 'unit_cost' => 10, 'category_ids' => array( 8 ), 'on_sale' => false ),
		);
		return array(
			'subtotal'        => array_sum( wp_list_pluck( $lines, 'line_total' ) ),
			'shipping'        => 0,
			'currency_symbol' => '$',
			'item_count'      => 3,
			'lines'           => $lines,
		);
	}

	public function test_percent_is_capped_at_max() {
		$o = Rules::clamp( array( 'type' => 'percent', 'amount' => 75, 'urgency_minutes' => 20 ), $this->snapshot(), $this->rules() );
		$this->assertSame( 20.0, $o['amount'] );
		$this->assertSame( 30.0, $o['discount_value'] );
		$this->assertSame( array( 1, 2 ), $o['product_ids'] );
	}

	public function test_margin_floor_limits_percent_per_line() {
		// Line 1: 100, cost 80 → floor 88 → room 12 (12%). Line 2: room 50-22=28 (56%). Min ratio = 12%.
		$snap = $this->snapshot(
			array(
				array( 'product_id' => 1, 'qty' => 1, 'line_total' => 100, 'unit_cost' => 80, 'on_sale' => false ),
				array( 'product_id' => 2, 'qty' => 2, 'line_total' => 50, 'unit_cost' => 10, 'on_sale' => false ),
			)
		);
		$o = Rules::clamp( array( 'type' => 'percent', 'amount' => 20 ), $snap, $this->rules() );
		$this->assertSame( 12.0, $o['amount'] );
		$this->assertContains( 'amount_capped', $o['adjustments'] );
		// Never below cost + min margin on any line.
		$this->assertGreaterThanOrEqual( 80 * 1.1 - 1e-6, 100 * ( 1 - $o['amount'] / 100 ) );
	}

	public function test_lines_without_room_are_excluded_not_fatal() {
		$snap = $this->snapshot(
			array(
				array( 'product_id' => 1, 'qty' => 1, 'line_total' => 100, 'unit_cost' => 95, 'on_sale' => false ),
				array( 'product_id' => 2, 'qty' => 1, 'line_total' => 60, 'unit_cost' => 20, 'on_sale' => false ),
			)
		);
		$o = Rules::clamp( array( 'type' => 'percent', 'amount' => 10 ), $snap, $this->rules() );
		$this->assertSame( array( 2 ), $o['product_ids'] );
		$this->assertSame( array( 1 ), $o['excluded_ids'] );
		$this->assertSame( 6.0, $o['discount_value'], '10% of the eligible 60 only' );
	}

	public function test_fixed_is_capped_by_margin_max_fixed_and_max_percent() {
		$o = Rules::clamp( array( 'type' => 'fixed_cart', 'amount' => 500 ), $this->snapshot(), $this->rules() );
		$this->assertSame( 25.0, $o['amount'], 'max fixed 25 (20% of 150 = 30, margin room ratio 0.56 → 84)' );
		$o = Rules::clamp( array( 'type' => 'fixed_cart', 'amount' => 25 ), $this->snapshot(), $this->rules( array( 'max_percent' => 10 ) ) );
		$this->assertSame( 15.0, $o['amount'], 'fixed never exceeds max % of the eligible cart' );
	}

	public function test_exclusions_sale_category_and_product() {
		$snap = $this->snapshot(
			array(
				array( 'product_id' => 1, 'qty' => 1, 'line_total' => 40, 'unit_cost' => 10, 'on_sale' => true ),
				array( 'product_id' => 2, 'qty' => 1, 'line_total' => 40, 'unit_cost' => 10, 'category_ids' => array( 9 ), 'on_sale' => false ),
				array( 'product_id' => 3, 'parent_id' => 30, 'qty' => 1, 'line_total' => 40, 'unit_cost' => 10, 'on_sale' => false ),
				array( 'product_id' => 4, 'qty' => 1, 'line_total' => 40, 'unit_cost' => null, 'on_sale' => false ),
			)
		);
		$e = Rules::eligibility( $snap, $this->rules( array( 'excluded_category_ids' => array( 9 ), 'excluded_product_ids' => array( 30 ), 'missing_cost' => 'skip' ) ) );
		$this->assertSame( array(), $e['product_ids'] );
		$this->assertSame( array( 'on_sale', 'excluded_category', 'excluded_product', 'no_cost' ), wp_list_pluck( $e['excluded'], 'reason' ) );
		$this->assertWPError( Rules::clamp( array( 'type' => 'percent', 'amount' => 10 ), $snap, $this->rules( array( 'excluded_category_ids' => array( 9 ), 'excluded_product_ids' => array( 30 ), 'missing_cost' => 'skip' ) ) ) );
	}

	public function test_budget_caps_and_blocks() {
		$o = Rules::clamp( array( 'type' => 'percent', 'amount' => 20 ), $this->snapshot(), $this->rules(), array( 'budget_remaining' => 12 ) );
		$this->assertSame( 8.0, $o['amount'], '12 / 150 = 8%' );
		$this->assertLessThanOrEqual( 12, $o['discount_value'] );
		$this->assertWPError( Rules::clamp( array( 'type' => 'percent', 'amount' => 20 ), $this->snapshot(), $this->rules(), array( 'budget_remaining' => 3 ) ), 'below min percent' );
		$o = Rules::clamp( array( 'type' => 'fixed_cart', 'amount' => 20 ), $this->snapshot(), $this->rules(), array( 'budget_remaining' => 7.5 ) );
		$this->assertSame( 7.5, $o['amount'] );
	}

	public function test_min_spend_and_allowed_types() {
		$snap             = $this->snapshot();
		$snap['subtotal'] = 20;
		$this->assertSame( 'dev3sc_min_spend', Rules::clamp( array( 'type' => 'percent', 'amount' => 10 ), $snap, $this->rules() )->get_error_code() );
		$o = Rules::clamp( array( 'type' => 'fixed_cart', 'amount' => 10 ), $this->snapshot(), $this->rules( array( 'allowed_types' => array( 'percent' ) ) ) );
		$this->assertSame( 'percent', $o['type'], 'disallowed type is coerced' );
		$this->assertContains( 'type', $o['adjustments'] );
	}

	public function test_free_shipping_requires_method_margin_and_budget() {
		$rules = $this->rules( array( 'free_shipping_estimate' => 8 ) );
		$this->assertWPError( Rules::clamp( array( 'type' => 'free_shipping' ), $this->snapshot(), $rules ) );
		$o = Rules::clamp( array( 'type' => 'free_shipping', 'amount' => 50 ), $this->snapshot(), $rules, array( 'free_shipping_available' => true ) );
		$this->assertSame( 0.0, $o['amount'] );
		$this->assertSame( 8.0, $o['discount_value'] );
		$this->assertSame( array(), $o['product_ids'] );
		$this->assertWPError( Rules::clamp( array( 'type' => 'free_shipping' ), $this->snapshot(), $rules, array( 'free_shipping_available' => true, 'budget_remaining' => 5 ) ) );
	}

	public function test_malicious_model_output_is_clamped_and_copy_replaced() {
		$evil = array(
			'type'            => 'percent',
			'amount'          => '1e9',
			'urgency_minutes' => -500,
			'headline'        => '<script>alert(1)</script>90% OFF EVERYTHING',
			'message'         => 'Visit http://evil.example for more',
			'cta'             => 'Claim',
		);
		$o    = Rules::clamp( $evil, $this->snapshot(), $this->rules() );
		$this->assertSame( 20.0, $o['amount'] );
		$this->assertSame( 10, $o['urgency_minutes'] );
		$this->assertStringNotContainsString( '90%', $o['headline'] );
		$this->assertStringNotContainsString( '<', $o['headline'] );
		$this->assertStringNotContainsString( 'evil', $o['message'] );
		$this->assertStringContainsString( '20% off', $o['headline'], 'fallback template carries the real deal' );
		$this->assertContains( 'copy', $o['adjustments'] );

		$nan = Rules::clamp( array( 'type' => 'fixed_cart', 'amount' => 'NaN', 'urgency_minutes' => 99999 ), $this->snapshot(), $this->rules() );
		$this->assertSame( 2.0, $nan['amount'], 'non-numeric amount → minimum' );
		$this->assertSame( 30, $nan['urgency_minutes'] );
		$neg = Rules::clamp( array( 'type' => 'fixed_cart', 'amount' => -40 ), $this->snapshot(), $this->rules() );
		$this->assertSame( 2.0, $neg['amount'], 'negative amount never flips positive beyond the minimum' );
	}

	public function test_safe_model_copy_keeps_placeholders() {
		$o = Rules::clamp(
			array( 'type' => 'percent', 'amount' => 10, 'urgency_minutes' => 15, 'headline' => 'Still deciding? Take {discount}', 'message' => 'Yours for the next {minutes} minutes.', 'cta' => 'Use my discount' ),
			$this->snapshot(),
			$this->rules()
		);
		$this->assertSame( 'Still deciding? Take 10% off', $o['headline'] );
		$this->assertSame( 'Yours for the next 15 minutes.', $o['message'] );
		$this->assertNotContains( 'copy', $o['adjustments'] );
		$this->assertSame( 'Enjoy 10% off today', Copy::fill( 'Enjoy {discount} off today', '10% off', 5 ) );
		$this->assertFalse( Copy::is_safe( 'Save {code} now' ) );
		$this->assertFalse( Copy::is_safe( 'Save $5' ) );
	}

	public function test_fallback_scales_with_risk_and_respects_none() {
		$rules = $this->rules( array( 'risk_threshold' => 50, 'fallback_type' => 'percent' ) );
		$this->assertSame( 5.0, Rules::fallback( 50, $this->snapshot(), $rules )['amount'] );
		$this->assertSame( 13.0, Rules::fallback( 75, $this->snapshot(), $rules )['amount'] );
		$this->assertSame( 20.0, Rules::fallback( 100, $this->snapshot(), $rules )['amount'] );
		$this->assertWPError( Rules::fallback( 90, $this->snapshot(), $this->rules( array( 'fallback' => 'none' ) ) ) );
		// Preferred type impossible (no free shipping method) → next allowed type.
		$o = Rules::fallback( 90, $this->snapshot(), $this->rules( array( 'fallback_type' => 'free_shipping' ) ) );
		$this->assertSame( 'percent', $o['type'] );
	}

	public function test_settings_sanitize_clamps_rules() {
		$r = Settings::sanitize_rules( array( 'max_percent' => 500, 'min_percent' => -5, 'daily_budget' => -10, 'allowed_types' => array( 'percent', 'bogus' ), 'code_prefix' => 'ab c<>!', 'excluded_product_ids' => '3, -4, x, 5' ), Settings::rule_defaults() );
		$this->assertSame( 90, $r['max_percent'] );
		$this->assertSame( 1, $r['min_percent'] );
		$this->assertSame( 0.0, $r['daily_budget'] );
		$this->assertSame( array( 'percent' ), $r['allowed_types'] );
		$this->assertSame( 'ABC', $r['code_prefix'] );
		$this->assertSame( array( 3, 5 ), $r['excluded_product_ids'] );
	}
}
