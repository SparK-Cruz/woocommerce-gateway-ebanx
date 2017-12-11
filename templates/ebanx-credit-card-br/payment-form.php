<?php
/**
 * Credit Card - Checkout form.
 *
 * @author  EBANX.com
 * @package WooCommerce_EBANX/Templates
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="ebanx-credit-cart-form" class="ebanx-payment-container ebanx-language-br">
	<?php include WC_EBANX::get_templates_path() . 'compliance-fields-br.php' ?>
    <fieldset id="wc-ebanx-credit-card-br-cc-form" class="wc-credit-card-form wc-payment-form">
        <?php include_once 'card-template.php';?>
    </fieldset>
</div>

<script>
	// Custom select fields
	if ('jQuery' in window && 'select2' in jQuery.fn) {
		jQuery('select.ebanx-select-field').select2();
	}
</script>
