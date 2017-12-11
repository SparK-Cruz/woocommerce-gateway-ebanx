<?php
/**
 * Display the billing schedule for a subscription
 *
 * @var object $the_subscription The WC_Subscription object to display the billing schedule for
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if( ! $subscription_product ) {
	echo 'No product assigned for this subscription';
	return;
}
#elseif( $subscription_product->is_type('subscription') ) {
#	echo 'Simple subscription product doesn\'t contain switch';
#	return;
#}
#elseif( ! $subscription_product->is_type('variable-subscription') ) {
#	echo 'Subscription product doesn\'t support switch';
#	return;
#}
#elseif( count( $subscription_product->get_visible_children() ) < 1 ) {
#	echo 'Subscription Product does not contain any variation';
#	return;
#}

if( $subscription_product->is_type('subscription') ) {
	$variation_ids = get_posts( array('post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => -1, 'post_status' => 'publish', 'product_type' => 'subscription', 'post__not_in' => array($subscription_product->get_id())));
} else {
	$variation_ids = $subscription_product->get_visible_children();
}

$switch_product_choices = array();
foreach( $variation_ids as $variation_id ){
	$variation = wc_get_product( $variation_id );
	$switch_product_choices[ $variation->get_id() ] = $variation->get_name();
}

$switch_condition_choices = array(
	'' 						=> 'Cancel',
	'after_expire' 			=> 'After current subscription expire',
	'at_second_renewal' 	=> 'At second renewal',
);

?>
<div class="wc-metaboxes-wrapper">
    <?php if ( ! empty( $settings['_ebanx_subscription_switch_completed'] ) ) : ?>
		<p><?php printf( __( 'Switch Completed: %s', 'woocommerce-gateway-ebanx' ), $settings['_ebanx_subscription_switch_completed'] ); ?></p>
	<?php elseif( ! empty( $settings['_ebanx_subscription_switch_created'] ) ) : ?>
		<p><?php printf( __( 'Switch Created: %s', 'woocommerce-gateway-ebanx' ), $settings['_ebanx_subscription_switch_created'] ); ?></p>
    <?php elseif ( ! empty( $settings['_ebanx_subscription_switch_cancelled'] ) ) : ?>
		<p><?php printf( __( 'Switch Cancelled: %s', 'woocommerce-gateway-ebanx' ), $settings['_ebanx_subscription_switch_cancelled'] ); ?></p>
    <?php else : ?>
		<p><?php _e( 'Switch Not Created', 'woocommerce-gateway-ebanx' ); ?></p>
    <?php endif; ?>
    <?php
		woocommerce_wp_hidden_input( array(
			'id' 			=> 'ebanx_subscription_edit',
			'name' 			=> 'ebanx_subscription_edit',
			'value' 		=> 1
		));
		woocommerce_wp_select( array(
			'label' 		=> 'Switch Condition',
			'id' 			=> '_ebanx_subscription_switch_condition',
			'name' 			=> '_ebanx_subscription_switch_condition',
			'value' 		=> $settings['_ebanx_subscription_switch_condition'],
			'options'		=> $switch_condition_choices,
			'desc_tip'		=> true,
			'description'	=> 'subscription will migrated after current active subscription ends defined length.'
		));
		woocommerce_wp_select( array(
			'label' 		=> 'Switch Subscription',
			'id' 			=> '_ebanx_subscription_switch_product_id',
			'name' 			=> '_ebanx_subscription_switch_product_id',
			'value' 		=> $settings['_ebanx_subscription_switch_product_id'],
			'options'		=> $switch_product_choices,
			'desc_tip'		=> true,
			'description' 	=> 'an active subscription is automatically switched to chosen subscription after given length. only affects future sold subscriptions.',
		));
	?>
</div>
