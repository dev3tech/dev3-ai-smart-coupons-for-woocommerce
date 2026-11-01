<?php
/**
 * Offer engine with Gemini mocked through pre_http_request: AI path, clamping of out-of-range
 * output, fallback on failure, dedupe, budget, coupon properties, sweeps, redemption.
 *
 * @package Dev3\AiSmartCoupons
 */

use Dev3\AiSmartCoupons\Core3\Core\Settings_Store;
use Dev3\AiSmartCoupons\Coupons\Coupon_Factory;
use Dev3\AiSmartCoupons\Coupons\Coupon_Hooks;
use Dev3\AiSmartCoupons\Data\Offers_Repository;
use Dev3\AiSmartCoupons\Engine\Budget;
use Dev3\AiSmartCoupons\Engine\Offer_Engine;
use Dev3\AiSmartCoupons\Scheduler\Sweeper;

class OfferEngineTest extends WP_UnitTestCase {

	private $calls = 0;

	public function set_up() {
		parent::set_up();
		$this->calls = 0;
		Settings_Store::update(
			array(
				'api_key'    => 'AIzaTESTKEY000000000000000000',
				'enabled'    => true,
				'ai_enabled' => true,
				'rules'      => array(
					'allowed_types'      => array( 'percent', 'fixed_cart' ),
					'min_percent'        => 5,
					'max_percent'        => 15,
					'min_spend'          => 30,
					'min_margin_percent' => 10,
					'risk_threshold'     => 50,
					'daily_budget'       => 100,
					'urgency_min'        => 10,
					'urgency_max'        => 30,
					'code_prefix'        => 'TEST',
					'retention_days'     => 3,
				),
			)
		);
		Budget::reset();
		set_transient( Offer_Engine::AOV_TRANSIENT, 60, HOUR_IN_SECONDS );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function gemini( $payload, $status = 200 ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $payload, $status ) {
				if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
					return $pre;
				}
				++$this->calls;
				$body = 200 === $status
					? array(
						'candidates'    => array( array( 'content' => array( 'parts' => array( array( 'text' => is_string( $payload ) ? $payload : wp_json_encode( $payload ) ) ) ), 'finishReason' => 'STOP' ) ),
						'usageMetadata' => array( 'promptTokenCount' => 100, 'candidatesTokenCount' => 40, 'totalTokenCount' => 140 ),
					)
					: array( 'error' => array( 'code' => $status, 'message' => 'Internal', 'status' => 'INTERNAL' ) );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array( 'code' => $status, 'message' => '' ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	private function snapshot() {
		return array(
			'subtotal'        => 150,
			'shipping'        => 0,
			'currency'        => 'USD',
			'currency_symbol' => '$',
			'item_count'      => 2,
			'categories'      => array( 'Apparel' ),
			'lines'           => array(
				array( 'product_id' => 101, 'qty' => 1, 'line_total' => 100, 'unit_cost' => 40, 'on_sale' => false ),
				array( 'product_id' => 102, 'qty' => 1, 'line_total' => 50, 'unit_cost' => 20, 'on_sale' => false ),
			),
		);
	}

	private function id( $n ) {
		return array(
			'session_hash' => Offer_Engine::hash( 'sess-' . $n ),
			'email'        => '',
			'email_hash'   => '',
			'ip_hash'      => Offer_Engine::hash( '10.0.0.' . $n ),
			'user_id'      => 0,
		);
	}

	private function hot() {
		return array( 'trigger' => 'exit_intent', 'idle_seconds' => 90, 'page_type' => 'cart', 'cart_visits' => 2 );
	}

	public function test_ai_offer_out_of_range_is_clamped_and_coupon_is_single_use() {
		$this->gemini(
			array(
				'type'            => 'percent',
				'amount'          => 80,
				'urgency_minutes' => 600,
				'headline'        => 'Huge 80% OFF today only!!!',
				'message'         => 'Come back',
				'cta'             => 'Grab it',
			)
		);
		$res = Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 1 ) );
		$this->assertSame( 'created', $res['reason'] );
		$this->assertSame( 1, $this->calls );
		$offer = $res['offer'];
		$this->assertSame( 'percent', $offer['type'] );
		$this->assertStringNotContainsString( '80', $offer['headline'] );
		$this->assertStringContainsString( '15% off', $offer['headline'] );

		$row = Offers_Repository::get( $offer['id'] );
		$this->assertSame( 'ai', $row['source'] );
		$this->assertSame( '15.00', $row['amount'] );
		$expires = strtotime( $row['expires_at'] . ' UTC' ) - strtotime( $row['created_at'] . ' UTC' );
		$this->assertSame( 30 * 60, $expires, 'urgency clamped to max 30 minutes' );

		$coupon = new WC_Coupon( $offer['code'] );
		$this->assertTrue( Coupon_Factory::is_generated( $coupon ) );
		$this->assertStringStartsWith( 'test-', $coupon->get_code() );
		$this->assertSame( 'percent', $coupon->get_discount_type() );
		$this->assertEquals( 15, $coupon->get_amount() );
		$this->assertSame( 1, $coupon->get_usage_limit() );
		$this->assertSame( 1, $coupon->get_usage_limit_per_user() );
		$this->assertTrue( $coupon->get_individual_use() );
		$this->assertTrue( $coupon->get_exclude_sale_items() );
		$this->assertEquals( 30, $coupon->get_minimum_amount() );
		$this->assertSame( array( 101, 102 ), $coupon->get_product_ids() );
		$this->assertSame( $this->id( 1 )['session_hash'], $coupon->get_meta( Coupon_Factory::META_SESSION ) );
		$this->assertEquals( $offer['id'], $coupon->get_meta( Coupon_Factory::META_OFFER ) );
		$this->assertEqualsWithDelta( time() + 1800, $coupon->get_date_expires()->getTimestamp(), 5 );
		$this->assertEqualsWithDelta( 22.5, Budget::state()['spent'], 0.001, 'estimated value reserved' );
	}

	public function test_ai_prompt_contains_no_pii_and_schema_has_max_lengths() {
		$sent = null;
		add_filter(
			'dev3sc_request_body',
			function ( $body ) use ( &$sent ) {
				$sent = $body;
				return $body;
			}
		);
		$this->gemini( array( 'type' => 'fixed_cart', 'amount' => 10, 'urgency_minutes' => 15, 'headline' => 'Still thinking it over?', 'message' => 'Take {discount} if you check out in {minutes} minutes.', 'cta' => 'Apply' ) );
		$id          = $this->id( 2 );
		$id['email'] = 'shopper@example.com';
		$res         = Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $id );
		$this->assertSame( 'created', $res['reason'] );
		$json = wp_json_encode( $sent );
		$this->assertStringNotContainsString( 'shopper@example.com', $json );
		$this->assertStringNotContainsString( '10.0.0', $json );
		$props = $sent['generationConfig']['responseSchema']['properties'];
		foreach ( array( 'headline', 'message', 'cta', 'reason' ) as $p ) {
			$this->assertArrayHasKey( 'maxLength', $props[ $p ] );
		}
		$this->assertSame( 'Take $10 off if you check out in 15 minutes.', $res['offer']['message'] );
		$coupon = new WC_Coupon( $res['offer']['code'] );
		$this->assertSame( array( 'shopper@example.com' ), $coupon->get_email_restrictions() );
		$this->assertEquals( 150, $coupon->get_minimum_amount(), 'fixed offers require the cart value they were checked against' );
		remove_all_filters( 'dev3sc_request_body' );
	}

	public function test_ai_failure_falls_back_to_rules() {
		$this->gemini( array(), 500 );
		$res = Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 3 ) );
		$this->assertSame( 'created', $res['reason'] );
		$this->assertSame( 'fallback', Offers_Repository::get( $res['offer']['id'] )['source'] );
	}

	public function test_malformed_json_falls_back() {
		$this->gemini( '{"type": "percent", "amount": ' );
		$res = Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 4 ) );
		$this->assertSame( 2, $this->calls, 'one retry on bad JSON' );
		$this->assertSame( 'fallback', Offers_Repository::get( $res['offer']['id'] )['source'] );
	}

	public function test_one_offer_per_session_email_ip_per_day() {
		$this->gemini( array( 'type' => 'percent', 'amount' => 10, 'urgency_minutes' => 15, 'headline' => 'Hi', 'message' => 'Hello', 'cta' => 'Go' ) );
		$this->assertSame( 'created', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 5 ) )['reason'] );
		$this->assertSame( 'already_offered', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 5 ) )['reason'] );
		$same_ip                 = $this->id( 6 );
		$same_ip['ip_hash']      = $this->id( 5 )['ip_hash'];
		$this->assertSame( 'already_offered', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $same_ip )['reason'] );
		Settings_Store::update( array( 'rules' => array( 'limit_per_ip' => false ) ) );
		$this->assertSame( 'created', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $same_ip )['reason'] );
	}

	public function test_active_offer_is_reused_not_duplicated() {
		$this->gemini( array( 'type' => 'percent', 'amount' => 10, 'urgency_minutes' => 15, 'headline' => 'Hi', 'message' => 'Hello', 'cta' => 'Go' ) );
		$first  = Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 7 ) );
		$active = Offers_Repository::active_for_session( $this->id( 7 )['session_hash'] );
		$this->assertSame( (string) $first['offer']['id'], (string) $active['id'] );
		$this->assertNull( Offers_Repository::active_for_session( $this->id( 8 )['session_hash'] ), 'other sessions never see it' );
	}

	public function test_gates_low_risk_min_spend_budget_disabled() {
		$this->gemini( array( 'type' => 'percent', 'amount' => 10, 'urgency_minutes' => 15, 'headline' => 'Hi', 'message' => 'Hello', 'cta' => 'Go' ) );
		$calm = array( 'trigger' => 'idle', 'idle_seconds' => 0, 'returning' => true, 'is_returning' => true );
		$snap = $this->snapshot();
		$snap['subtotal'] = 40;
		$this->assertSame( 'low_risk', Offer_Engine::evaluate( $calm, $snap, $this->id( 9 ) )['reason'] );
		$snap['subtotal'] = 10;
		$this->assertSame( 'min_spend', Offer_Engine::evaluate( $this->hot(), $snap, $this->id( 10 ) )['reason'] );
		Budget::reserve( 100 );
		$this->assertSame( 'budget', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 11 ) )['reason'] );
		Budget::reset();
		Settings_Store::update( array( 'enabled' => false ) );
		$this->assertSame( 'disabled', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 12 ) )['reason'] );
		$this->assertSame( 0, $this->calls, 'no AI call when a gate stops the offer' );
	}

	public function test_budget_remaining_caps_ai_offer_and_daily_reset() {
		$this->gemini( array( 'type' => 'percent', 'amount' => 15, 'urgency_minutes' => 15, 'headline' => 'Hi', 'message' => 'Hello', 'cta' => 'Go' ) );
		Budget::reserve( 91 ); // 9 left → 6% of 150.
		$res = Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 13 ) );
		$this->assertSame( '6.00', Offers_Repository::get( $res['offer']['id'] )['amount'] );
		$this->assertEqualsWithDelta( 0, Budget::remaining(), 0.001 );
		Budget::reset();
		$this->assertEquals( 100, Budget::remaining() );
		update_option( Budget::OPTION, array( 'day' => '2000-01-01', 'spent' => 99 ) );
		$this->assertEquals( 100, Budget::remaining(), 'a stale day self-heals' );
	}

	public function test_cooldown_limits_repeated_failed_evaluations() {
		Settings_Store::update( array( 'rules' => array( 'fallback' => 'none' ) ) );
		$this->gemini( array(), 500 );
		$this->assertSame( 'no_valid_offer', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 14 ) )['reason'] );
		$this->assertSame( 'cooldown', Offer_Engine::evaluate( $this->hot(), $this->snapshot(), $this->id( 14 ) )['reason'] );
		$this->assertSame( 1, $this->calls );
	}

	public function test_sweep_deletes_only_expired_unused_generated_coupons_after_retention() {
		$make = function ( $days_ago, $generated = true, $used = false ) {
			$c = new WC_Coupon();
			$c->set_code( 'sweep-' . wp_generate_password( 6, false ) );
			$c->set_date_expires( time() - $days_ago * DAY_IN_SECONDS );
			if ( $generated ) {
				$c->update_meta_data( Coupon_Factory::META_GENERATED, 1 );
			}
			if ( $used ) {
				$c->set_usage_count( 1 );
			}
			$c->save();
			return $c->get_id();
		};
		$old      = $make( 10 );
		$recent   = $make( 1 );
		$used     = $make( 10, true, true );
		$merchant = $make( 10, false );
		$this->assertSame( 1, Sweeper::sweep() );
		$this->assertNull( get_post( $old ) );
		$this->assertNotNull( get_post( $recent ), 'inside retention window' );
		$this->assertNotNull( get_post( $used ), 'used coupons are kept for records' );
		$this->assertNotNull( get_post( $merchant ), 'merchant coupons are never touched' );
	}

	public function test_sweep_marks_expired_offers() {
		$id = Offers_Repository::insert( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'session_hash' => 'x', 'code' => 'OLD' ) );
		Sweeper::sweep();
		$this->assertSame( 'expired', Offers_Repository::get( $id )['status'] );
	}

	public function test_schedules_action_scheduler_jobs() {
		Sweeper::schedule();
		$this->assertTrue( as_has_scheduled_action( Sweeper::SWEEP, array(), Sweeper::GROUP ) );
		$this->assertTrue( as_has_scheduled_action( Sweeper::RESET, array(), Sweeper::GROUP ) );
	}

	public function test_redeemed_order_is_recorded_and_coupon_not_reusable() {
		$this->gemini( array( 'type' => 'percent', 'amount' => 10, 'urgency_minutes' => 20, 'headline' => 'Hi', 'message' => 'Hello', 'cta' => 'Go' ) );
		$product = new WC_Product_Simple();
		$product->set_name( 'Test hoodie' );
		$product->set_regular_price( '100' );
		$product->save();
		$snap                           = $this->snapshot();
		$snap['lines'][0]['product_id'] = $product->get_id();
		$res                            = Offer_Engine::evaluate( $this->hot(), $snap, $this->id( 15 ) );
		$code                           = $res['offer']['code'];

		( new Coupon_Hooks() )->applied( $code );
		$this->assertNotEmpty( Offers_Repository::get( $res['offer']['id'] )['applied_at'] );

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_email( 'guest@example.com' );
		$order->calculate_totals();
		$this->assertTrue( $order->apply_coupon( $code ) );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );

		$row = Offers_Repository::get( $res['offer']['id'] );
		$this->assertSame( 'redeemed', $row['status'] );
		$this->assertSame( (string) $order->get_id(), (string) $row['order_id'] );
		$this->assertEquals( 90, (float) $row['order_total'] );
		$this->assertEquals( 10, (float) $row['order_discount'] );

		$coupon = new WC_Coupon( $code );
		$this->assertSame( 1, $coupon->get_usage_count() );
		$discounts = new WC_Discounts( $order );
		$valid     = $discounts->is_coupon_valid( $coupon );
		$this->assertWPError( $valid, 'usage limit reached' );

		$stats = Offers_Repository::stats( 30 );
		$this->assertSame( 1, $stats['totals']['redeemed'] );
		$this->assertEquals( 90, $stats['totals']['revenue'] );
	}
}
