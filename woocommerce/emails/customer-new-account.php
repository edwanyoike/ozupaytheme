<?php
/**
 * Customer new account email — OzuPay (single-product framing).
 * Overrides woocommerce/templates/emails/customer-new-account.php.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$order        = function_exists( 'ozp_new_account_order' ) ? ozp_new_account_order() : null;
$product_list = $order ? ozp_new_account_product_names( $order ) : array();
?>

<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
	<tr>
		<td>
			<p style="font-size: 16px; margin: 0 0 16px;">
				<?php
				printf(
					/* translators: %s: username */
					wp_kses( __( 'Hi <strong>%s</strong>, your OzuPay account is set up and ready to go.', 'ozupay' ), array( 'strong' => array() ) ),
					esc_html( $user_login )
				);
				?>
			</p>

			<p style="margin: 0 0 24px; font-size: 14px; color: #475569;">
				<?php
				printf(
					/* translators: %s: username */
					wp_kses( __( 'Your username: <strong>%s</strong>', 'ozupay' ), array( 'strong' => array() ) ),
					esc_html( $user_login )
				);
				?>
			</p>

			<?php if ( $product_list ) : ?>
				<p style="margin: 0 0 4px; font-size: 14px; color: #475569;">
					<?php esc_html_e( "Here's what you ordered:", 'ozupay' ); ?>
				</p>
				<p style="margin: 0 0 24px; font-size: 15px; font-weight: 600; color: #0f172a;">
					<?php echo esc_html( implode( ', ', $product_list ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $password_generated && $set_password_url ) : ?>
				<table border="0" cellpadding="0" cellspacing="0" role="presentation">
					<tr>
						<td style="border-radius: 6px; background-color: #059669;">
							<a href="<?php echo esc_url( $set_password_url ); ?>"
								style="display: inline-block; padding: 12px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">
								<?php esc_html_e( 'Set your password', 'ozupay' ); ?>
							</a>
						</td>
					</tr>
				</table>
				<p style="margin: 16px 0 0; font-size: 13px; color: #64748b;">
					<?php esc_html_e( "You'll need this the first time you log in. The link expires in 24 hours — if it's expired, use \"Lost your password?\" on the login page to get a new one.", 'ozupay' ); ?>
				</p>
			<?php else : ?>
				<p style="margin: 0 0 16px;">
					<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" style="color: #059669; font-weight: 600;">
						<?php esc_html_e( 'Log in to My Account', 'ozupay' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<div style="height: 1px; background-color: #e2e8f0; margin: 28px 0;"></div>

			<p style="margin: 0 0 8px; font-size: 14px; color: #475569;">
				<?php esc_html_e( 'From My Account you can:', 'ozupay' ); ?>
			</p>
			<ul style="margin: 0 0 24px; padding-left: 20px; font-size: 14px; color: #334155;">
				<li><?php esc_html_e( 'Download the plugin and view your license key', 'ozupay' ); ?></li>
				<li><?php esc_html_e( 'Check your order and subscription status', 'ozupay' ); ?></li>
				<li><?php esc_html_e( 'Reach support directly if you get stuck', 'ozupay' ); ?></li>
			</ul>

			<p style="margin: 0; font-size: 14px;">
				<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" style="color: #059669;"><?php esc_html_e( 'My Account', 'ozupay' ); ?></a>
				&nbsp;&middot;&nbsp;
				<a href="<?php echo esc_url( home_url( '/docs/' ) ); ?>" style="color: #059669;"><?php esc_html_e( 'Documentation', 'ozupay' ); ?></a>
				&nbsp;&middot;&nbsp;
				<a href="mailto:support@ozulabs.com" style="color: #059669;"><?php esc_html_e( 'Support', 'ozupay' ); ?></a>
			</p>
		</td>
	</tr>
</table>

<?php
if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
