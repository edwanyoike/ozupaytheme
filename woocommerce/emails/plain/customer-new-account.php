<?php
/**
 * Customer new account email (plain text) — OzuPay.
 * Overrides woocommerce/templates/emails/plain/customer-new-account.php.
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

$order        = function_exists( 'ozp_new_account_order' ) ? ozp_new_account_order() : null;
$product_list = $order ? ozp_new_account_product_names( $order ) : array();

/* translators: %s: username */
printf( esc_html__( 'Hi %s, your OzuPay account is set up and ready to go.', 'ozupay' ), esc_html( $user_login ) );
echo "\n\n";

/* translators: %s: username */
printf( esc_html__( 'Your username: %s', 'ozupay' ), esc_html( $user_login ) );
echo "\n\n";

if ( $product_list ) {
	echo esc_html__( "Here's what you ordered:", 'ozupay' ) . "\n";
	echo esc_html( implode( ', ', $product_list ) ) . "\n\n";
}

if ( $password_generated && $set_password_url ) {
	echo esc_html__( 'Set your password:', 'ozupay' ) . "\n";
	echo esc_html( $set_password_url ) . "\n\n";
	echo esc_html__( 'This link expires in 24 hours — if it\'s expired, use "Lost your password?" on the login page to get a new one.', 'ozupay' ) . "\n\n";
} else {
	echo esc_html__( 'Log in to My Account:', 'ozupay' ) . "\n";
	echo esc_html( wc_get_page_permalink( 'myaccount' ) ) . "\n\n";
}

echo "----------------------------------------\n\n";
echo esc_html__( 'From My Account you can download the plugin, view your license key, check your subscription, and reach support.', 'ozupay' ) . "\n\n";
echo esc_html__( 'Docs: ', 'ozupay' ) . esc_html( home_url( '/docs/' ) ) . "\n";
echo esc_html__( 'Support: support@ozulabs.com', 'ozupay' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
