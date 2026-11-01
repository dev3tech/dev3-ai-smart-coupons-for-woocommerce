<?php
/**
 * Offer log (one row per incentive shown). Stores hashes only — never emails or IPs.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for {prefix}dev3sc_offers.
 */
class Offers_Repository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3sc_offers';
	}

	/**
	 * CREATE TABLE for dbDelta (hooked into dev3sc_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$t     = self::table();
		$sql[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			day date NOT NULL,
			session_hash char(32) NOT NULL DEFAULT '',
			email_hash char(32) NOT NULL DEFAULT '',
			ip_hash char(32) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
			code varchar(40) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT '',
			amount decimal(12,2) NOT NULL DEFAULT 0,
			discount_value decimal(12,2) NOT NULL DEFAULT 0,
			cart_value decimal(14,2) NOT NULL DEFAULT 0,
			risk tinyint(3) unsigned NOT NULL DEFAULT 0,
			trigger_type varchar(20) NOT NULL DEFAULT '',
			page_type varchar(20) NOT NULL DEFAULT '',
			source varchar(12) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'active',
			headline varchar(160) NOT NULL DEFAULT '',
			message varchar(255) NOT NULL DEFAULT '',
			cta varchar(60) NOT NULL DEFAULT '',
			discount_label varchar(60) NOT NULL DEFAULT '',
			applied_at datetime NULL DEFAULT NULL,
			redeemed_at datetime NULL DEFAULT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_total decimal(14,2) NOT NULL DEFAULT 0,
			order_discount decimal(12,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY code (code),
			KEY session_day (session_hash,day),
			KEY email_day (email_hash,day),
			KEY ip_day (ip_hash,day),
			KEY created (created_at)
		) {$charset};";
		return $sql;
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $row Columns.
	 * @return int Insert id.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$now  = time();
		$row += array(
			'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			'day'        => wp_date( 'Y-m-d', $now ),
			'status'     => 'active',
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Row by coupon code.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public static function by_code( $code ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s ORDER BY id DESC LIMIT 1", strtoupper( (string) $code ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Active (unexpired, not redeemed) offer for a session.
	 *
	 * @param string $session_hash Session hash.
	 * @return array|null
	 */
	public static function active_for_session( $session_hash ) {
		if ( '' === $session_hash ) {
			return null;
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_hash = %s AND status IN ('active','applied') AND expires_at > %s ORDER BY id DESC LIMIT 1", $session_hash, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Whether any identity already received an offer in the last 24 hours.
	 *
	 * @param string $session_hash Session hash.
	 * @param string $email_hash   Email hash ('' unknown).
	 * @param string $ip_hash      IP hash ('' to ignore).
	 * @return bool
	 */
	public static function seen_recently( $session_hash, $email_hash, $ip_hash ) {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// Unknown identities use a sentinel that can never match a stored hash ('' would match rows without one).
		$pick = static function ( $value ) {
			return '' === (string) $value ? '#none' : (string) $value;
		};
		if ( '' === (string) $session_hash && '' === (string) $email_hash && '' === (string) $ip_hash ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE (session_hash = %s OR email_hash = %s OR ip_hash = %s) AND created_at >= %s", $pick( $session_hash ), $pick( $email_hash ), $pick( $ip_hash ), $since ) );
		return $count > 0;
	}

	/**
	 * Updates a row.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Marks expired offers.
	 *
	 * @return int Rows.
	 */
	public static function expire_past() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'expired' WHERE status IN ('active','applied') AND expires_at <= %s", gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Aggregates for the analytics screen.
	 *
	 * @param int $days Window in days.
	 * @return array
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS shown, SUM(applied_at IS NOT NULL) AS applied, SUM(redeemed_at IS NOT NULL) AS redeemed,
				SUM(CASE WHEN redeemed_at IS NOT NULL THEN order_total ELSE 0 END) AS revenue,
				SUM(CASE WHEN redeemed_at IS NOT NULL THEN order_discount ELSE 0 END) AS discount_cost,
				SUM(cart_value) AS offered_cart_value, AVG(risk) AS avg_risk, SUM(source = 'ai') AS ai_offers
				FROM {$t} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);
		$group      = static function ( $col ) use ( $wpdb, $t, $since ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$col} AS label, COUNT(*) AS shown, SUM(applied_at IS NOT NULL) AS applied, SUM(redeemed_at IS NOT NULL) AS redeemed,
					SUM(CASE WHEN redeemed_at IS NOT NULL THEN order_total ELSE 0 END) AS revenue,
					SUM(CASE WHEN redeemed_at IS NOT NULL THEN order_discount ELSE 0 END) AS discount_cost
					FROM {$t} WHERE created_at >= %s GROUP BY {$col} ORDER BY shown DESC",
					$since
				),
				ARRAY_A
			);
		};
		$daily      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, COUNT(*) AS shown, SUM(applied_at IS NOT NULL) AS applied, SUM(redeemed_at IS NOT NULL) AS redeemed
				FROM {$t} WHERE created_at >= %s GROUP BY day ORDER BY day ASC",
				$since
			),
			ARRAY_A
		);
		$by_type    = $group( 'type' );
		$by_trigger = $group( 'trigger_type' );
		// phpcs:enable

		$num    = static function ( $rows ) {
			return array_map(
				static function ( $r ) {
					foreach ( $r as $k => $v ) {
						if ( 'label' !== $k && 'day' !== $k ) {
							$r[ $k ] = is_numeric( $v ) ? $v + 0 : 0;
						}
					}
					return $r;
				},
				(array) $rows
			);
		};
		$totals = $num( array( (array) $totals ) )[0];
		$shown  = (int) $totals['shown'];

		$totals['conversion'] = $shown ? round( (int) $totals['redeemed'] / $shown * 100, 1 ) : 0;
		$totals['apply_rate'] = $shown ? round( (int) $totals['applied'] / $shown * 100, 1 ) : 0;
		return array(
			'days'       => (int) $days,
			'totals'     => $totals,
			'by_type'    => $num( $by_type ),
			'by_trigger' => $num( $by_trigger ),
			'daily'      => $num( $daily ),
		);
	}

	/**
	 * Recent offers for the admin table (no hashes).
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function recent( $limit = 25 ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, expires_at, user_id, coupon_id, code, type, amount, discount_value, cart_value, risk, trigger_type, page_type, source, status, headline, applied_at, redeemed_at, order_id, order_total, order_discount FROM {$t} ORDER BY id DESC LIMIT %d", max( 1, min( 200, (int) $limit ) ) ), ARRAY_A );
		return (array) $rows;
	}
}
