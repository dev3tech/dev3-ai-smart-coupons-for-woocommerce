<?php
/**
 * Storefront: tracker + banner assets, inline cart/checkout banners, one-click apply handler.
 *
 * Cache safety: pages other than cart/checkout may be served from a full-page cache, so they
 * only receive public data (settings, flash sales). A shopper's own offer is embedded only on
 * cart/checkout (never cached by WooCommerce-aware caches) or returned by the tracker endpoint.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Frontend;

use Dev3\AiSmartCoupons\Core3\Core\Settings_Store;
use Dev3\AiSmartCoupons\Data\Offers_Repository;
use Dev3\AiSmartCoupons\Engine\Flash_Sales;
use Dev3\AiSmartCoupons\Engine\Offer_Engine;
use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront wiring.
 */
class Storefront {

	const HANDLE = 'dev3-ai-smart-coupons-for-woocommerce-storefront';

	/**
	 * Active offer for this request (cart/checkout only).
	 *
	 * @var array|null|false False = not looked up yet.
	 */
	private $offer = false;

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_apply' ), 30 );
		add_action( 'woocommerce_before_cart', array( $this, 'inline_cart' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'inline_checkout' ), 5 );
		add_filter( 'render_block_woocommerce/cart', array( $this, 'block_cart' ) );
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'block_checkout' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Reserves space for a live flash-sale bar in the first paint (no layout shift).
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		$now = time();
		foreach ( Flash_Sales::for_page( self::page_type() ) as $sale ) {
			if ( $sale['starts'] <= $now ) {
				$classes[] = 'dev3sc-flash-space-' . ( 'bottom' === $sale['placement'] ? 'bottom' : 'top' );
				break;
			}
		}
		return $classes;
	}

	/**
	 * Page type of the current request.
	 *
	 * @return string cart|checkout|product|shop|home|other
	 */
	public static function page_type() {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			return 'shop';
		}
		if ( is_front_page() || is_home() ) {
			return 'home';
		}
		return 'other';
	}

	/**
	 * Whether the tracker should run on a page type.
	 *
	 * @param string $page  Page type.
	 * @param string $scope Setting (cart_checkout|shop|all).
	 * @return bool
	 */
	public static function tracker_applies( $page, $scope ) {
		if ( 'cart_checkout' === $scope ) {
			return in_array( $page, array( 'cart', 'checkout' ), true );
		}
		if ( 'shop' === $scope ) {
			return in_array( $page, array( 'cart', 'checkout', 'product', 'shop' ), true );
		}
		return true;
	}

	/**
	 * The shopper's active offer, when it is safe to embed (cart/checkout).
	 *
	 * @return array|null
	 */
	private function active_offer() {
		if ( false === $this->offer ) {
			$this->offer = null;
			$page        = self::page_type();
			if ( in_array( $page, array( 'cart', 'checkout' ), true ) && WC()->session && WC()->session->has_session() ) {
				$row = Offers_Repository::active_for_session( Offer_Engine::identity()['session_hash'] );
				if ( $row ) {
					$this->offer = Offer_Engine::public_offer( $row );
				}
			}
		}
		return $this->offer;
	}

	/**
	 * Assets + boot config.
	 */
	public function enqueue() {
		if ( ! Settings_Store::get( 'enabled', true ) || is_admin() ) {
			return;
		}
		$page     = self::page_type();
		$triggers = Settings::group( 'triggers' );
		$track    = self::tracker_applies( $page, $triggers['pages'] ) && ! is_order_received_page();
		$flash    = Flash_Sales::for_page( $page );
		if ( ! $track && ! $flash ) {
			return;
		}
		$asset = DEV3SC_PATH . 'build/storefront.asset.php';
		if ( ! is_readable( $asset ) ) {
			return;
		}
		$meta = include $asset;
		wp_register_script( self::HANDLE, DEV3SC_URL . 'build/storefront.js', $meta['dependencies'], $meta['version'], array( 'in_footer' => true ) );
		wp_register_style( self::HANDLE, DEV3SC_URL . 'build/storefront.css', array(), $meta['version'] );
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_script( self::HANDLE, 'window.dev3scStore = ' . wp_json_encode( $this->config( $page, $track, $flash ) ) . ';', 'before' );
	}

	/**
	 * Public boot config.
	 *
	 * @param string $page  Page type.
	 * @param bool   $track Tracker enabled here.
	 * @param array  $flash Flash sales.
	 * @return array
	 */
	public function config( $page, $track, array $flash ) {
		$t      = Settings::group( 'triggers' );
		$b      = Settings::group( 'banner' );
		$offer  = $this->active_offer();
		$config = array(
			'endpoint' => esc_url_raw( rest_url( 'dev3sc/v1/track' ) ),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'page'     => $page,
			'track'    => (bool) $track,
			'cartUrl'  => wc_get_cart_url(),
			'triggers' => array(
				'exit'        => (bool) $t['exit_intent'],
				'mobile'      => (bool) $t['mobile_exit'],
				'idle'        => (bool) $t['idle'],
				'idleSeconds' => (int) $t['idle_seconds'],
				'minDwell'    => (int) $t['min_dwell'],
			),
			'consent'  => array(
				'mode'       => $t['consent'],
				'cookie'     => $t['consent_cookie'],
				'respectDnt' => (bool) $t['respect_dnt'],
			),
			'banner'   => self::banner_public( $b ),
			'flash'    => $flash,
			'offer'    => $offer,
			'i18n'     => self::strings(),
		);
		/**
		 * Storefront config (public; never add private data for cacheable pages).
		 *
		 * @param array  $config Config.
		 * @param string $page   Page type.
		 */
		return apply_filters( 'dev3sc_storefront_config', $config, $page );
	}

	/**
	 * Banner design for the browser.
	 *
	 * @param array $b Banner settings.
	 * @return array
	 */
	public static function banner_public( array $b ) {
		return array(
			'position'   => $b['position'],
			'bg'         => $b['bg'],
			'text'       => $b['text'],
			'accent'     => $b['accent'],
			'accentText' => $b['accent_text'],
			'radius'     => (int) $b['radius'],
			'countdown'  => (bool) $b['show_countdown'],
		);
	}

	/**
	 * UI strings.
	 *
	 * @return array
	 */
	public static function strings() {
		return array(
			'expires'  => __( 'Offer ends in', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'endsIn'   => __( 'Ends in', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'expired'  => __( 'This offer has expired.', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'applied'  => __( 'Discount applied', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'applying' => __( 'Applying…', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'dismiss'  => __( 'Dismiss offer', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'close'    => __( 'Close', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'code'     => __( 'Code', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'offer'    => __( 'Personal offer', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'flash'    => __( 'Flash sale', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'failed'   => __( 'Could not apply the code. Please enter it at checkout.', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'copy'     => __( 'Copy code', 'dev3-ai-smart-coupons-for-woocommerce' ),
			'copied'   => __( 'Copied', 'dev3-ai-smart-coupons-for-woocommerce' ),
		);
	}

	/**
	 * `?dev3sc_apply=CODE&dev3sc_token=…` one-click apply (classic cart/checkout and any page).
	 */
	public function maybe_apply() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified with a session-bound HMAC token below.
		if ( empty( $_GET['dev3sc_apply'] ) || is_admin() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$code  = strtoupper( wc_format_coupon_code( sanitize_text_field( wp_unslash( $_GET['dev3sc_apply'] ) ) ) );
		$token = isset( $_GET['dev3sc_token'] ) ? sanitize_text_field( wp_unslash( $_GET['dev3sc_token'] ) ) : '';
		// phpcs:enable
		$back = remove_query_arg( array( 'dev3sc_apply', 'dev3sc_token' ) );
		if ( '' !== $code && hash_equals( Offer_Engine::apply_token( $code ), $token ) ) {
			if ( ! WC()->cart->has_discount( $code ) ) {
				WC()->cart->apply_coupon( $code );
			}
		} else {
			wc_add_notice( __( 'That offer link has expired. Please use the code at checkout.', 'dev3-ai-smart-coupons-for-woocommerce' ), 'error' );
		}
		wp_safe_redirect( $back ? $back : wc_get_cart_url() );
		exit;
	}

	/**
	 * Classic cart inline banner.
	 */
	public function inline_cart() {
		if ( Settings::group( 'banner' )['inline_cart'] ) {
			echo $this->inline_markup( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in inline_markup().
		}
	}

	/**
	 * Classic checkout inline banner.
	 */
	public function inline_checkout() {
		if ( Settings::group( 'banner' )['inline_checkout'] ) {
			echo $this->inline_markup( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in inline_markup().
		}
	}

	/**
	 * Block cart: prepend the banner.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public function block_cart( $html ) {
		return Settings::group( 'banner' )['inline_cart'] ? $this->inline_markup( 'cart' ) . $html : $html;
	}

	/**
	 * Block checkout: prepend the banner.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public function block_checkout( $html ) {
		return Settings::group( 'banner' )['inline_checkout'] ? $this->inline_markup( 'checkout' ) . $html : $html;
	}

	/**
	 * Server-rendered inline banner (rendered in the initial HTML, so no layout shift).
	 * The storefront script hydrates it (countdown, apply, copy).
	 *
	 * @param string $where cart|checkout.
	 * @return string
	 */
	public function inline_markup( $where ) {
		$offer = $this->active_offer();
		if ( ! $offer ) {
			return '';
		}
		$b       = Settings::group( 'banner' );
		$s       = self::strings();
		$left    = max( 0, $offer['expires_at'] - time() );
		$style   = sprintf( '--dev3sc-bg:%s;--dev3sc-fg:%s;--dev3sc-accent:%s;--dev3sc-accent-fg:%s;--dev3sc-radius:%dpx', $b['bg'], $b['text'], $b['accent'], $b['accent_text'], (int) $b['radius'] );
		$applied = ! empty( $offer['applied'] );
		ob_start();
		?>
		<section class="dev3sc-banner dev3sc-inline<?php echo $applied ? ' is-applied' : ''; ?>" data-dev3sc-inline="<?php echo esc_attr( $where ); ?>" data-dev3sc-offer="<?php echo esc_attr( (string) $offer['id'] ); ?>" style="<?php echo esc_attr( $style ); ?>" aria-label="<?php echo esc_attr( $s['offer'] ); ?>">
			<div class="dev3sc-banner__body">
				<p class="dev3sc-banner__eyebrow"><?php echo esc_html( $s['offer'] ); ?></p>
				<p class="dev3sc-banner__headline"><?php echo esc_html( $offer['headline'] ); ?></p>
				<p class="dev3sc-banner__message"><?php echo esc_html( $offer['message'] ); ?></p>
			</div>
			<div class="dev3sc-banner__actions">
				<?php if ( $b['show_countdown'] ) : ?>
					<span class="dev3sc-countdown" role="timer" aria-live="off" data-expires="<?php echo esc_attr( (string) $offer['expires_at'] ); ?>" data-now="<?php echo esc_attr( (string) time() ); ?>">
						<span class="dev3sc-countdown__label"><?php echo esc_html( $s['expires'] ); ?></span>
						<span class="dev3sc-countdown__time"><?php echo esc_html( sprintf( '%02d:%02d', floor( $left / 60 ), $left % 60 ) ); ?></span>
					</span>
				<?php endif; ?>
				<span class="dev3sc-code"><span class="dev3sc-sr"><?php echo esc_html( $s['code'] ); ?>: </span><?php echo esc_html( $offer['code'] ); ?></span>
				<?php if ( $applied ) : ?>
					<span class="dev3sc-applied" role="status"><?php echo esc_html( $s['applied'] ); ?></span>
				<?php else : ?>
					<a class="dev3sc-btn" data-dev3sc-apply href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'dev3sc_apply' => rawurlencode( $offer['code'] ),
								'dev3sc_token' => Offer_Engine::apply_token( $offer['code'] ),
							)
						)
					);
					?>
																"><?php echo esc_html( $offer['cta'] ); ?></a>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
