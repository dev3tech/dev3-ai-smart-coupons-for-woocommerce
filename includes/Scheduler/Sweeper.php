<?php
/**
 * Background jobs on Action Scheduler (bundled with WooCommerce), WP-Cron as a fallback.
 *
 * - dev3sc_sweep (hourly): expire offers, delete expired + unused generated coupons after the retention period.
 * - dev3sc_budget_reset (daily, site midnight): reset the global discount budget.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Scheduler;

use Dev3\AiSmartCoupons\Coupons\Coupon_Factory;
use Dev3\AiSmartCoupons\Data\Offers_Repository;
use Dev3\AiSmartCoupons\Engine\Budget;
use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs.
 */
class Sweeper {

	const SWEEP = 'dev3sc_sweep';
	const RESET = 'dev3sc_budget_reset';
	const GROUP = 'ai-smart-coupons';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( self::SWEEP, array( __CLASS__, 'sweep' ) );
		add_action( self::RESET, array( Budget::class, 'reset' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	/**
	 * Ensures both recurring actions exist.
	 */
	public static function schedule() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::SWEEP, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, self::SWEEP, array(), self::GROUP );
			}
			if ( ! as_has_scheduled_action( self::RESET, array(), self::GROUP ) ) {
				as_schedule_recurring_action( self::next_midnight(), DAY_IN_SECONDS, self::RESET, array(), self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::SWEEP ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SWEEP );
		}
		if ( ! wp_next_scheduled( self::RESET ) ) {
			wp_schedule_event( self::next_midnight(), 'daily', self::RESET );
		}
	}

	/**
	 * Removes scheduled actions (deactivation).
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SWEEP, array(), self::GROUP );
			as_unschedule_all_actions( self::RESET, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::SWEEP );
		wp_clear_scheduled_hook( self::RESET );
	}

	/**
	 * Next local midnight as a Unix timestamp.
	 *
	 * @return int
	 */
	public static function next_midnight() {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		return $now->modify( 'tomorrow' )->getTimestamp();
	}

	/**
	 * Expires offers and deletes expired, unused generated coupons older than the retention period.
	 *
	 * @param int $batch Max coupons per run.
	 * @return int Coupons deleted.
	 */
	public static function sweep( $batch = 200 ) {
		Offers_Repository::expire_past();
		$days    = (int) Settings::group( 'rules' )['retention_days'];
		$cutoff  = time() - $days * DAY_IN_SECONDS;
		$ids     = get_posts(
			array(
				'post_type'        => 'shop_coupon',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => max( 1, (int) $batch ),
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => Coupon_Factory::META_GENERATED,
						'value' => '1',
					),
					array(
						'key'     => 'date_expires',
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$deleted = 0;
		foreach ( $ids as $id ) {
			$coupon = new \WC_Coupon( (int) $id );
			if ( ! Coupon_Factory::is_generated( $coupon ) || $coupon->get_usage_count() > 0 ) {
				continue;
			}
			$expires = $coupon->get_date_expires();
			if ( ! $expires || $expires->getTimestamp() >= $cutoff ) {
				continue;
			}
			$coupon->delete( true );
			++$deleted;
		}
		return $deleted;
	}
}
