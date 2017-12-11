<?php
/**
 * Display the billing schedule for a subscription
 *
 * @var object $the_subscription The WC_Subscription object to display the billing schedule for
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$recurrence_status_choices = array(
	'active' 			=> 'Active',
	'inactive' 			=> 'Inactive',
);

?>
<div class="wc-metaboxes-wrapper">
	<?php if ( ! empty( $settings['_ebanx_subscription_recurrence_created'] ) ) : ?>
		<p><?php printf( __( 'Recurrence Created: %s', 'woocommerce-gateway-ebanx' ), $settings['_ebanx_subscription_recurrence_created'] ); ?></p>
    <?php elseif ( ! empty( $settings['_ebanx_subscription_recurrence_cancelled'] ) ) : ?>
		<p><?php printf( __( 'Recurrence Cancelled: %s', 'woocommerce-gateway-ebanx' ), $settings['_ebanx_subscription_recurrence_cancelled'] ); ?></p>
    <?php else : ?>
		<p><?php _e( 'Not recurring', 'woocommerce-gateway-ebanx' ); ?></p>
    <?php endif; ?>
    <?php
		woocommerce_wp_hidden_input( array(
			'id' 			=> '_ebanx_subscription_order_recurrence_edit',
			'name' 			=> '_ebanx_subscription_order_recurrence_edit',
			'value' 		=> 1
		));
		woocommerce_wp_select( array(
			'label' 		=> 'Recurrence',
			'id' 			=> '_ebanx_subscription_recurrence_status',
			'name' 			=> '_ebanx_subscription_recurrence_status',
			'value' 		=> $settings['_ebanx_subscription_recurrence_status'],
			'options'		=> $recurrence_status_choices,
			'desc_tip'		=> true,
			'description' 	=> 'an active subscription is automatically switched to chosen subscription after given length. only affects future sold subscriptions.',
		));

	?>
</div>
