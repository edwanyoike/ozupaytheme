<?php
/**
 * Empty cart page
 *
 * Overrides WooCommerce default cart/cart-empty.php (WC 7.0.1).
 *
 * @package OzuPay
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_cart_is_empty' );
?>
<div class="ozp-cart-empty">
	<div class="ozp-cart-empty__icon" aria-hidden="true">
		<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round">
			<circle cx="8" cy="21" r="1"/>
			<circle cx="19" cy="21" r="1"/>
			<path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>
		</svg>
	</div>
	<h2 class="ozp-cart-empty__heading"><?php esc_html_e( 'Your cart is empty', 'ozupay' ); ?></h2>
	<p class="ozp-cart-empty__sub"><?php esc_html_e( 'Head back to the shop to find what you\'re looking for.', 'ozupay' ); ?></p>
	<?php if ( wc_get_page_id( 'shop' ) > 0 ) : ?>
	<a class="ozp-cart-empty__btn" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
		<?php esc_html_e( 'Browse products', 'ozupay' ); ?>
	</a>
	<?php endif; ?>
	<ul class="ozp-cart-empty__trust" aria-label="<?php esc_attr_e( 'Purchase assurance', 'ozupay' ); ?>">
		<li>
			<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
			<?php esc_html_e( 'Instant download', 'ozupay' ); ?>
		</li>
		<li>
			<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
			<?php esc_html_e( '30-day money-back', 'ozupay' ); ?>
		</li>
		<li>
			<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
			<?php esc_html_e( 'Secure checkout', 'ozupay' ); ?>
		</li>
	</ul>
</div>
