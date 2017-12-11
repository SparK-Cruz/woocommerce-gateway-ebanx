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
<div id="ebanx-payment-data">
	<input name="ebanx-credit-card-use" type="radio" value="new" checked="checked" style="display:none !important;" />
    <input name="wc-ebanx-payment-token" type="radio" value="new" checked="checked" style="display:none !important;" />
	<input id="billing_country" type="hidden" value="<?php echo $customer->get_billing_country(); ?>"  />
	<input id="billing_first_name" type="hidden" value="<?php echo $customer->get_billing_first_name(); ?>" />
	<input id="billing_last_name" type="hidden" value="<?php echo $customer->get_billing_last_name(); ?>" />

    <fieldset id="wc-ebanx-cc-form" class="wc-credit-card-form wc-payment-form">
        <p class="form-row form-row-wide">
            <label for="ebanx-card-number"><?php _e( 'Card number', 'woocommerce-gateway-ebanx' ); ?> <span class="required">*</span></label>
            <input id="ebanx-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" type="tel">
        </p>
        <p class="form-row form-row-first">
            <label for="ebanx-card-expiry"><?php _e( 'Expiration Date (MM / YY)', 'woocommerce-gateway-ebanx' ); ?> <span class="required">*</span></label>            <input id="ebanx-card-expiry" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" autocomplete="cc-exp" autocorrect="no" autocapitalize="no" spellcheck="no" placeholder="<?php _e( 'MM / YY', 'woocommerce-gateway-ebanx' ); ?>" type="tel">
        </p>
        <p class="form-row form-row-last">
        <label for="ebanx-card-cvv"><?php _e( 'Security Code', 'woocommerce-gateway-ebanx' ); ?> <span class="required">*</span></label>
        <input id="ebanx-card-cvv" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" maxlength="4" placeholder="<?php _e( 'CVC', 'woocommerce-gateway-ebanx' ); ?>" style="width:100px" type="tel">
        </p>
        <div class="clear"></div>
    </fieldset>
</div>