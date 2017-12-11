<?php

if ( ! defined('ABSPATH') ) {
	exit;
}

class WC_EBANX_Subscription_Order_Switch {

	function __construct() {
		add_filter( 'woocommerce_product_single_add_to_cart_text'	, __CLASS__ . '::single_add_to_cart_text'				, 20, 2 );
		add_filter( 'woocommerce_hide_invisible_variations'			, __CLASS__ . '::hide_invisible_variations'				, 40, 3 );
		add_action( 'woocommerce_variation_is_visible'				, __CLASS__ . '::variation_is_visible'					, 40, 4 );
		add_action( 'woocommerce_checkout_subscription_created'		, __CLASS__ . '::add_subscription_switch_meta'			, 20, 2 );
		add_action( 'woocommerce_scheduled_subscription_payment'	, __CLASS__ . '::maybe_switch_subscription'				, 0, 1 );
		add_action( 'admin_init'									, __CLASS__ . '::debug' );
	}
	public static function single_add_to_cart_text( $text, $product ) {
		if( $product->is_type('variable-subscription') && $subscriptions = wcs_get_users_subscriptions() ) {
			$active_product_subscription = false;
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->has_product( $product->get_id() ) ) {
					$active_product_subscription = $subscription;
					break;
				}
			}
			if( $active_product_subscription ){
				$text = __('Change Subscription');
			}
		}
		return $text;
	}
	// on subscription payment hook, we perform autoswitch
	public static function hide_invisible_variations( $hide, $product_id, $variation ) {
		$switch_product_id = get_post_meta( $product_id, '_ebanx_subscription_switch_product_id', 1 );
		if( $switch_product_id && $switch_product_id == $variation->get_id() && 'yes' == get_post_meta( $product_id, '_ebanx_subscription_switch_product_hidden', 1 ) ){
			$hide = true;
		}
		return $hide;
	}
	public static function variation_is_visible( $is_visible, $variation_id, $product_id, $variation ) {
		if( $subscriptions = wcs_get_users_subscriptions() ) {
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->has_product( $variation_id ) ) {
					$is_visible = false;
				}
			}
		}
		else if( 'yes' == get_post_meta( $product_id, '_ebanx_subscription_switch_product_hidden', 1 ) ) {
			$switch_product_id = get_post_meta( $product_id, '_ebanx_subscription_switch_product_id', 1 );
			if( $switch_product_id && $switch_product_id == $variation_id ){
				$is_visible = false;
			}
		}
		return $is_visible;
	}
	// after subscription is created, through order checkout, we set auto_switch data
	public static function add_subscription_switch_meta( $subscription, $order ) {
		WC_EBANX::log( __METHOD__ . ' - starts' );

		$subscription_product = WC_EBANX_Subscription_Common::get_subscription_product_parent( $subscription );
		if( ! $subscription_product ){
			WC_EBANX::log(  __METHOD__ . ' - subscription product not exists.' );
			return false;
		}

		$switch_product_id = get_post_meta( $subscription_product->get_id(), '_ebanx_subscription_switch_product_id', 1 );
		$switch_condition = get_post_meta( $subscription_product->get_id(), '_ebanx_subscription_switch_condition', 1 );

		if( ! $switch_product_id || ! $switch_condition ) {
			WC_EBANX::log( __METHOD__ . ' - no needed - ' . $subscription_product->get_id() );
			return false;
		}

		if( get_post_meta( $subscription->get_id(), '_ebanx_subscription_switch_created' ) ) {
			WC_EBANX::log( __METHOD__ . ' - switch data already created');
			return false;
		}

		update_post_meta( $subscription->get_id(), '_ebanx_subscription_switch_product_id', $switch_product_id );
		update_post_meta( $subscription->get_id(), '_ebanx_subscription_switch_condition', $switch_condition );
		update_post_meta( $subscription->get_id(), '_ebanx_subscription_switch_created', current_time('mysql', 1) );
		WC_EBANX::log( __METHOD__ . ' - switch data created');
	}
	public static function maybe_switch_subscription( $subscription_id ) {
		WC_EBANX::log('WC_EBANX_Subscription_Order_Switch::maybe_switch_subscription - start');

		$subscription = wcs_get_subscription( $subscription_id );

		if( get_post_meta( $subscription_id, '_ebanx_subscription_switch_completed', 1 ) ) {
			WC_EBANX::log('WC_EBANX_Subscription_Order_Switch::maybe_switch_subscription - alreay completed');
			return false;
		}
		elseif( get_post_meta( $subscription_id, '_ebanx_subscription_switch_cancelled', 1 ) ) {
			WC_EBANX::log('WC_EBANX_Subscription_Order_Switch::maybe_switch_subscription - switch was cancelled');
			return false;
		}
		elseif( ! get_post_meta( $subscription_id, '_ebanx_subscription_switch_created', 1 ) ) {
			WC_EBANX::log('WC_EBANX_Subscription_Order_Switch::maybe_switch_subscription - switch not scheduled/needed/configured');
			return false;
		}

		$switch_product_id = get_post_meta( $subscription_id, '_ebanx_subscription_switch_product_id', 1);
		$switch_condition = get_post_meta( $subscription_id, '_ebanx_subscription_switch_condition', 1);

		if ( $switch_product_id && ! wc_get_product( $switch_product_id ) ) {
			update_post_meta( $subscription->get_id(), '_ebanx_subscription_switch_cancelled', current_time('mysql', 1) );
			WC_EBANX::log('WC_EBANX_Subscription_Order_Switch::maybe_switch_subscription - cancelling switch as switch product not exists');
			return false;
		}

		if ( ! $switch_condition ) {
			WC_EBANX::log('WC_EBANX_Subscription_Order_Switch::maybe_switch_subscription - switch not configured.');
			return false;
		}

		$total_renewed = 0;
		$orders = $subscription->get_related_orders('all', 'renewal');
		foreach( $orders as $order ){
			if( $order->has_status( array( 'processing', 'complete' ) ) ) {
				$total_renewed ++;
			}
		}

		WC_EBANX::log('Renewed - '. $total_renewed . ', switch '. $switch_condition );

		switch( $switch_condition ) {
			case 'after_expire';
				if( $subscription->get_time('end') && $subscription->get_time('end') < ( time() + DAY_IN_SECONDS ) ) {
					self::process_subscription_switch( $subscription );
					remove_action( 'woocommerce_scheduled_subscription_payment', 'WC_Subscriptions_Manager::prepare_renewal', 1, 1 );
				}
			break;
			case 'at_second_renewal';
				if( $total_renewed >= 1 ) {
					self::process_subscription_switch( $subscription );
					remove_action( 'woocommerce_scheduled_subscription_payment', 'WC_Subscriptions_Manager::prepare_renewal', 1, 1 );
				}
			break;
			default:
			break;
		}
		WC_EBANX::log('Switch condition not matched' );
	}

	public static function process_subscription_switch( $subscription ) {
		WC_EBANX::log('WC_EBANX_Subscription_Order_Switch::process_subscription_switch');

		$old_product = WC_EBANX_Subscription_Common::get_subscription_product( $subscription );
		$new_product_id = get_post_meta( $subscription->get_id(), '_ebanx_subscription_switch_product_id', 1 );

		if( ! empty($new_product_id) && $new_product_id != $old_product->get_id() ){
			$new_product = wc_get_product( $new_product_id );
			self::process_subscription_switch_item( $subscription, $old_product, $new_product );
			update_post_meta( $subscription->get_id(), '_ebanx_subscription_switch_completed', current_time('mysql', 1) );
		}
	}
	public static function process_subscription_switch_item( $subscription, $old_product, $new_product ) {

		WC_EBANX::log( "switch from ". $old_product->get_id() ." to ". $new_product->get_id() );

		$subscription_id = $subscription->get_id();
		$last_order = wc_get_order( $subscription->get_last_order() );
		$billing = $last_order->get_address('billing');
		$shipping = $last_order->get_address('shipping');

		$subscription_switch_item = new WC_Order_Item_Pending_Switch;
		$subscription_switch_item->set_props( array(
			'name'         => $new_product->get_name(),
			'tax_class'    => $new_product->get_tax_class(),
			'product_id'   => $new_product->is_type( 'variation' ) ? $new_product->get_parent_id() : $new_product->get_id(),
			'variation_id' => $new_product->is_type( 'variation' ) ? $new_product->get_id() : 0,
			'variation'    => $new_product->is_type( 'variation' ) ? $new_product->get_attributes() : array(),
			'subtotal'     => wc_get_price_excluding_tax( $new_product, array( 'qty' => 1 ) ),
			'total'        => wc_get_price_excluding_tax( $new_product, array( 'qty' => 1 ) ),
			'quantity'     => 1,
			'subtotal_tax' => 0,
			'total_tax'    => 0,
			'taxes'        => array(),
		) );

		$subscription->add_item( $subscription_switch_item );
		$subscription->save();

		$old_item_id = 0;
		foreach ( $subscription->get_items() as $item ) {
			if( $item->is_type( 'line_item' ) && $old_product->get_id() == $item->get_product_id() ) {
				$old_item_id = $item->get_id();
				break;
			}
		}
		$new_item_id = $subscription_switch_item->get_id();

		$new_order = wc_create_order();
		$order_item_id = $new_order->add_product( $new_product, 1 );
		$new_order->set_address( $billing, 'billing' );
		$new_order->set_address( $shipping, 'shipping' );
		$new_order->set_customer_id( $last_order->get_customer_id() );

		$new_order->save();

		update_post_meta( $new_order->get_id(), '_subscription_switch', $subscription->get_id() );
		update_post_meta( $new_order->get_id(), '_subscription_switch_data', array(
			$subscription_id => array(
				'switches' => array(
					$order_item_id => array(
						'add_line_item' => $new_item_id,
						'remove_line_item' => $old_item_id
					)
				),
				'billing_schedule' => array(
					'_billing_period' => $new_product->get_meta('_subscription_period'),
					'_billing_interval' => $new_product->get_meta('_subscription_period_interval')
				)
			)
		));

		WC_EBANX::log( print_r( array(
			'_billing_period' => $new_product->get_meta('_subscription_period'),
			'_billing_interval' => $new_product->get_meta('_subscription_period_interval')
		), true ));

		if( $new_order->needs_payment() ) {
			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
			ob_start();
			$result = $available_gateways[ $subscription->get_payment_method() ]->process_payment( $new_order->get_id() );
			ob_get_clean();
		} else {
			$new_order->payment_complete();
		}
	}

	public static function debug() {
		if( isset($_REQUEST['debug']) ){
			#$order = wc_get_order(13947);
			#$subscription = wcs_get_subscription(13948);
			#self::add_subscription_switch_meta( $subscription, $order );
			self::maybe_switch_subscription(14161);
			die();
		}
	}
	public static function p($a) {
		echo '<pre>'; print_r($a); echo '</pre>';
	}
}

	new WC_EBANX_Subscription_Order_Switch();
