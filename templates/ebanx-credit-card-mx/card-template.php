<div class="ebanx-credit-card-template">
    <section class="ebanx-form-row">
        <label for="ebanx-card-number"><?php _e( 'Card number', 'woocommerce-gateway-ebanx' ); ?><span class="required">*</span></label>
        <input id="ebanx-card-number" class="input-text wc-credit-card-form-card-number" type="tel" maxlength="20" autocomplete="off" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" />
    </section>
    <div class="clear"></div>
    <section class="ebanx-form-row ebanx-form-row-first">
        <label for="ebanx-card-expiry"><?php _e( 'Expiration Date (MM / YY)', 'woocommerce-gateway-ebanx' ); ?><span class="required">*</span></label>
        <input id="ebanx-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="tel" autocomplete="off" placeholder="<?php _e( 'MM / YY', 'woocommerce-gateway-ebanx' ); ?>" maxlength="7" />
    </section>
    <section class="ebanx-form-row ebanx-form-row-last">
        <label for="ebanx-card-cvv"><?php _e( 'Security Code', 'woocommerce-gateway-ebanx' ); ?><span class="required">*</span></label>
        <input id="ebanx-card-cvv" class="input-text wc-credit-card-form-card-cvc" type="tel" autocomplete="off" placeholder="<?php _e( 'CVC', 'woocommerce-gateway-ebanx' ); ?>" />
    </section>
    <?php include WC_EBANX::get_templates_path() . 'instalments.php'; ?>
</div>