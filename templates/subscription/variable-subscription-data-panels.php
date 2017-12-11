<?php

$choices = array();

$variation_ids = $variable->is_type('variable-subscription') ? $variable->get_visible_children() : array();
if( empty($variation_ids) ) {
	$choices = array( '' => '-- Please Create Variations First --' );
} else {
	foreach( $variation_ids as $variation_id ){
		$variation = wc_get_product( $variation_id );
		$choices[ $variation->get_id() ] = $variation->get_name();
	}
}
?><div id='variable-subscription-switch' class='panel woocommerce_options_panel'>
	<div class="options_group"><?php
woocommerce_wp_hidden_input( array(
	'id' 			=> 'ebanx_subscription_edit',
	'name' 			=> 'ebanx_subscription_edit',
	'value' 		=> 1
));
woocommerce_wp_radio( array(
	'label' 		=> 'Switch Condition',
	'id' 			=> '_ebanx_subscription_switch_condition',
	'name' 			=> '_ebanx_subscription_switch_condition',
	'value' 		=> $settings['_ebanx_subscription_switch_condition'],
	'options'		=> array(
		'' 						=> 'Never',
		'after_expire' 			=> 'After current subscription expire',
		'at_second_renewal' 	=> 'At second renewal',
	),
	'desc_tip'		=> true,
	'description'	=> 'subscription will migrated after current active subscription ends defined length.'
));
woocommerce_wp_radio( array(
	'label' 		=> 'Switch Subscription',
	'id' 			=> '_ebanx_subscription_switch_product_id',
	'name' 			=> '_ebanx_subscription_switch_product_id',
	'value' 		=> $settings['_ebanx_subscription_switch_product_id'],
	'options'		=> $choices,
	'desc_tip'		=> true,
	'description' 	=> 'an active subscription is automatically switched to chosen subscription after given length. only affects future sold subscriptions.',
));
woocommerce_wp_checkbox( array(
	'label' 		=> 'Hidd Switch Product',
	'id' 			=> '_ebanx_subscription_switch_product_hidden',
	'name' 			=> '_ebanx_subscription_switch_product_hidden',
	'value' 		=> $settings['_ebanx_subscription_switch_product_hidden'],
	'cbvalue'		=> 'yes',
	'desc_tip'		=> true,
	'description'	=> 'hide switch product from purchasing.'
));

?></div></div>