<?php
/**
 * REST: public tracker permission (same-origin, rate limit, validation, session binding),
 * admin routes, apply token + session-bound coupon validity, flash sales.
 *
 * @package Dev3\AiSmartCoupons
 */

use Dev3\AiSmartCoupons\Coupons\Coupon_Factory;
use Dev3\AiSmartCoupons\Coupons\Coupon_Hooks;
use Dev3\AiSmartCoupons\Engine\Flash_Sales;
use Dev3\AiSmartCoupons\Engine\Offer_Engine;
use Dev3\AiSmartCoupons\Settings;

class RestTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		\Dev3\AiSmartCoupons\Core3\Core\Settings_Store::flush();
		do_action( 'rest_api_init' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	public function tear_down() {
		remove_all_filters( 'dev3sc_track_rate_limit' );
		parent::tear_down();
	}

	private function track( $body, $origin = null ) {
		$req = new WP_REST_Request( 'POST', '/dev3sc/v1/track' );
		$req->set_header( 'Content-Type', 'application/json' );
		if ( $origin ) {
			$req->set_header( 'Origin', $origin );
		}
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	public function test_cross_origin_is_rejected() {
		$res = $this->track( array( 'trigger' => 'idle' ), 'https://evil.example' );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'dev3sc_forbidden_origin', $res->get_data()['code'] );
	}

	public function test_same_origin_without_session_returns_no_offer() {
		$res = $this->track( array( 'trigger' => 'exit_intent', 'page_type' => 'cart' ), home_url() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNull( $res->get_data()['offer'] );
		$this->assertSame( 'no_session', $res->get_data()['reason'] );
		$this->assertSame( 'no-store, private', $res->get_headers()['Cache-Control'] );
	}

	public function test_input_is_validated() {
		$this->assertSame( 400, $this->track( array( 'trigger' => 'drop_table' ) )->get_status() );
		$this->assertSame( 400, $this->track( array( 'trigger' => 'idle', 'idle_seconds' => -5 ) )->get_status() );
		$this->assertSame( 400, $this->track( array( 'trigger' => 'idle', 'page_type' => '<script>' ) )->get_status() );
		$this->assertSame( 400, $this->track( array() )->get_status(), 'trigger is required' );
	}

	public function test_rate_limit_per_ip() {
		add_filter( 'dev3sc_track_rate_limit', fn() => 2 );
		$this->assertSame( 200, $this->track( array( 'trigger' => 'idle' ) )->get_status() );
		$this->assertSame( 200, $this->track( array( 'trigger' => 'idle' ) )->get_status() );
		$res = $this->track( array( 'trigger' => 'idle' ) );
		$this->assertSame( 429, $res->get_status() );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 200, $this->track( array( 'trigger' => 'idle' ) )->get_status(), 'other IPs are unaffected' );
	}

	public function test_admin_routes_require_manage_capability_and_nonce() {
		foreach ( array( array( 'GET', 'analytics' ), array( 'GET', 'flash-sales' ), array( 'POST', 'simulate' ), array( 'POST', 'flash-sales/copy' ), array( 'GET', 'lookup' ) ) as $route ) {
			wp_set_current_user( 0 );
			$req = new WP_REST_Request( $route[0], '/dev3sc/v1/' . $route[1] );
			$this->assertSame( 403, rest_do_request( $req )->get_status(), "anon {$route[1]}" );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$this->assertContains( rest_do_request( $req )->get_status(), array( 401, 403 ), "customer {$route[1]}" );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$req = new WP_REST_Request( 'GET', '/dev3sc/v1/analytics' );
		$this->assertSame( 403, rest_do_request( $req )->get_status(), 'admin without nonce' );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'totals', $res->get_data() );
		$this->assertArrayHasKey( 'by_trigger', $res->get_data() );
		$this->assertArrayNotHasKey( 'session_hash', $res->get_data()['recent'][0] ?? array() );
	}

	public function test_simulator_never_creates_coupons() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$before = wp_count_posts( 'shop_coupon' )->publish;
		$req    = new WP_REST_Request( 'POST', '/dev3sc/v1/simulate' );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$req->set_body_params( array( 'cart_value' => 120, 'cost_percent' => 40, 'trigger' => 'exit_intent', 'idle_seconds' => 60 ) );
		$data = rest_do_request( $req )->get_data();
		$this->assertGreaterThan( 0, $data['risk']['score'] );
		$this->assertNotEmpty( $data['offer'] );
		$this->assertSame( $before, wp_count_posts( 'shop_coupon' )->publish );
	}

	public function test_generated_coupon_is_bound_to_its_session() {
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		$coupon = new WC_Coupon();
		$coupon->set_code( 'BOUND-' . wp_generate_password( 6, false ) );
		$coupon->set_amount( 10 );
		$coupon->update_meta_data( Coupon_Factory::META_GENERATED, 1 );
		$coupon->save();
		$hooks = new Coupon_Hooks();
		try {
			$hooks->bind_to_session( true, $coupon );
			$this->fail( 'Another session must not use the code' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'another shopping session', $e->getMessage() );
		}
		Offer_Engine::remember_code( $coupon->get_code() );
		$this->assertTrue( $hooks->bind_to_session( true, $coupon ) );
		$plain = new WC_Coupon();
		$plain->set_code( 'MERCHANT10' );
		$plain->save();
		$this->assertTrue( $hooks->bind_to_session( true, $plain ), 'merchant coupons untouched' );
	}

	public function test_apply_token_depends_on_session_and_code() {
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->session->set_customer_session_cookie( true );
		$a = Offer_Engine::apply_token( 'CODE-A' );
		$this->assertSame( $a, Offer_Engine::apply_token( 'code-a' ) );
		$this->assertNotSame( $a, Offer_Engine::apply_token( 'CODE-B' ) );
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->session->set_customer_session_cookie( true );
		$this->assertNotSame( $a, Offer_Engine::apply_token( 'CODE-A' ), 'other session, other token' );
	}

	public function test_flash_sales_sanitize_and_window() {
		$now   = time();
		$saved = Flash_Sales::save(
			array(
				array( 'enabled' => true, 'headline' => '<b>Weekend</b> sale', 'starts' => $now - 60, 'ends' => $now + 3600, 'cta_url' => 'https://evil.example/x', 'coupon_code' => 'cozy 25!' ),
				array( 'enabled' => true, 'headline' => 'Backwards', 'starts' => $now + 100, 'ends' => $now ),
				array( 'enabled' => true, 'headline' => 'Next week', 'starts' => $now + 7 * DAY_IN_SECONDS, 'ends' => $now + 8 * DAY_IN_SECONDS ),
				array( 'enabled' => false, 'headline' => 'Off', 'starts' => $now - 60, 'ends' => $now + 60 ),
			)
		);
		$this->assertCount( 3, $saved, 'end before start is dropped' );
		$this->assertSame( 'Weekend sale', $saved[0]['headline'] );
		$this->assertSame( '', $saved[0]['cta_url'], 'off-site links removed' );
		$this->assertSame( 'COZY25', $saved[0]['coupon_code'] );
		$page = Flash_Sales::for_page( 'home' );
		$this->assertCount( 1, $page );
		$this->assertArrayNotHasKey( 'title', $page[0] );
	}

	public function test_banner_settings_sanitize() {
		$b = Settings::sanitize_banner( array( 'bg' => 'red;}', 'accent' => '#00ff00', 'position' => 'sideways', 'fallback_heading' => '<img src=x onerror=alert(1)>Hi' ), Settings::banner_defaults() );
		$this->assertSame( '#111827', $b['bg'] );
		$this->assertSame( '#00ff00', $b['accent'] );
		$this->assertSame( 'bottom', $b['position'] );
		$this->assertSame( 'Hi', $b['fallback_heading'] );
	}
}
