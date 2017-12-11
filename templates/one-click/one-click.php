<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<form class="ebanx-one-click-form" id="ebanx-one-click-form" method="post" action="<?php echo $permalink ?>">
    <input type="hidden" name="ebanx-action" value="<?php echo $action ?>">
    <input type="hidden" name="ebanx-nonce" value="<?php echo $nonce ?>">
    <input type="hidden" name="ebanx-product-id" value="<?php echo $product_id ?>">
    <div class="ebanx-one-click-button-container">
        <button class="single_add_to_cart_button ebanx-one-click-pay button" data-processing-label="<?php _e('Processing...', 'woocommerce-gateway-ebanx') ?>" type="submit" title="<?php echo esc_attr( $title ); ?>"><?php echo $button_text; ?></button>
    </div>
</form>
