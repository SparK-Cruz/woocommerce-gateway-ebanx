<?php
/**
 * EFT - Checkout form.
 *
 * @author  EBANX.com
 * @package WooCommerce_EBANX/Templates
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div id="ebanx-multicaja-payment" class="ebanx-payment-container ebanx-language-es">
	<?php include WC_EBANX::get_templates_path() . 'compliance-fields-cl.php' ?>
</div>
