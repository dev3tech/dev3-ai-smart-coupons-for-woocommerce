<?php
/**
 * Deterministic risk model.
 *
 * @package Dev3\AiSmartCoupons
 */

use Dev3\AiSmartCoupons\Engine\Risk_Score;

class RiskScoreTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		\Dev3\AiSmartCoupons\Core3\Core\Settings_Store::flush();
	}

	private function keys( $result ) {
		return wp_list_pluck( $result['factors'], 'points', 'key' );
	}

	public function test_high_risk_guest_with_exit_intent() {
		$r = Risk_Score::score(
			array(
				'trigger'      => 'exit_intent',
				'idle_seconds' => 120,
				'cart_value'   => 200,
				'aov'          => 60,
				'cart_visits'  => 3,
				'page_type'    => 'checkout',
			)
		);
		$k = $this->keys( $r );
		$this->assertSame( 30, $k['exit_intent'] );
		$this->assertSame( 25, $k['idle'] );
		$this->assertSame( 20, $k['cart_value'] );
		$this->assertSame( 10, $k['visitor'] );
		$this->assertSame( 10, $k['history'] );
		$this->assertSame( 10, $k['cart_visits'] );
		$this->assertSame( 5, $k['checkout'] );
		$this->assertSame( 100, $r['score'], 'sum 110 is clamped to 100' );
	}

	public function test_loyal_recent_customer_scores_low() {
		$r = Risk_Score::score(
			array(
				'trigger'               => 'idle',
				'idle_seconds'          => 10,
				'cart_value'            => 40,
				'aov'                   => 60,
				'logged_in'             => true,
				'order_count'           => 5,
				'days_since_last_order' => 3,
			)
		);
		$k = $this->keys( $r );
		$this->assertArrayNotHasKey( 'idle', $k, 'idle at the floor adds nothing' );
		$this->assertSame( -15, $k['history'] );
		$this->assertSame( -10, $k['recency'] );
		$this->assertSame( 0, $r['score'], 'negative totals clamp to 0' );
	}

	public function test_idle_is_linear_between_floor_and_cap() {
		$r = Risk_Score::score( array( 'trigger' => 'idle', 'idle_seconds' => 65, 'is_returning' => true, 'order_count' => 1 ) );
		$k = $this->keys( $r );
		$this->assertSame( 13, $k['idle'], '(65-10)/110*25 = 12.5 → 13' );
		$this->assertSame( 4, $k['visitor'] );
		$this->assertSame( -5, $k['history'] );
	}

	public function test_lapsed_customer_and_mobile_triggers() {
		$r = Risk_Score::score( array( 'trigger' => 'scroll_up', 'order_count' => 2, 'days_since_last_order' => 400, 'cart_value' => 50, 'aov' => 60 ) );
		$k = $this->keys( $r );
		$this->assertSame( 24, $k['scroll_up'] );
		$this->assertSame( 6, $k['recency'] );
		$this->assertSame( 6, $k['cart_value'] );
		$this->assertSame( 18, $this->keys( Risk_Score::score( array( 'trigger' => 'tab_hidden' ) ) )['tab_hidden'] );
	}

	public function test_garbage_input_is_normalised_and_deterministic() {
		$a = Risk_Score::score( array( 'idle_seconds' => -50, 'cart_value' => 'abc', 'cart_visits' => 9999, 'order_count' => -3 ) );
		$b = Risk_Score::score( array( 'idle_seconds' => -50, 'cart_value' => 'abc', 'cart_visits' => 9999, 'order_count' => -3 ) );
		$this->assertSame( $a, $b );
		$this->assertSame( 0, $a['signals']['idle_seconds'] );
		$this->assertSame( 100, $a['signals']['cart_visits'] );
		$this->assertSame( 0, $a['signals']['order_count'] );
		$this->assertGreaterThanOrEqual( 0, $a['score'] );
		$this->assertLessThanOrEqual( 100, $a['score'] );
	}

	public function test_weights_are_filterable() {
		add_filter(
			'dev3sc_risk_weights',
			static function ( $w ) {
				$w['exit_intent'] = 50;
				return $w;
			}
		);
		$this->assertSame( 50, $this->keys( Risk_Score::score( array( 'trigger' => 'exit_intent' ) ) )['exit_intent'] );
		remove_all_filters( 'dev3sc_risk_weights' );
	}
}
