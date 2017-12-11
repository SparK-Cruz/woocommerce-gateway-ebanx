<?php
/**
 * Credit Card - Payment processed.
 *
 * @author  EBANX.com
 * @package WooCommerce_EBANX/Templates
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ebanx-thank-you-page ebanx-thank-you-page--br ebanx-thank-you-page--cc-br">
	<?php if ($instalments_number > 1) : ?>
    	<p><?php printf( __('%s\'s payment of %s, in installments of %sx %s, was approved.', 'woocommerce-gateway-ebanx' ) , $customer_name, $total, $instalments_number, $instalments_amount ); ?></p>
	<?php else: ?>
    	<p><?php printf( __('%s\'s payment of %s.', 'woocommerce-gateway-ebanx' ) , $customer_name, $total ); ?></p>
	<?php endif ?>

	<p><?php printf( __( 'If you have any questions regarding your payment, access the EBANX Account with %s.', 'woocommerce-gateway-ebanx' ), $customer_email ); ?></p>

	<?php include WC_EBANX::get_templates_path() . 'apps_br.php' ?>
</div>
