<?php
/**
 * EBANX - Variable Subscription Admin
 * @package WordPress
 * @subpackage Ebanx Payment Gateway
 * @author Shazzad Hossain Khan
 * @url https://w4dev.com/about
**/


class WP_Ebanx_Subscription_Misc {

	function __construct() {
		// virtual subscription product does not need processing
		add_filter( 'woocommerce_order_item_needs_processing'				, __CLASS__ .'::order_item_needs_processing' 	, 10, 3 );
		// display card last4 number on myaccount subscriptions page
		add_filter( 'woocommerce_get_order_item_totals'						, __CLASS__ .'::get_order_item_totals' 			, 10, 2 );
	}

	public static function order_item_needs_processing( $needs_processing, $product, $order ) {
		if( $product->is_type( array( 'subscription', 'subscription_variation' ) ) && $product->is_virtual() ) {
			$needs_processing = false;
		}
		return $needs_processing;
	}
	public static function get_order_item_totals( $total_rows, $subscription ) {
		if( 'shop_subscription' == $subscription->get_type() && is_account_page() && $subscription->get_parent_id() ) {
			$order = $subscription->get_parent();
			if( false !== strpos( $subscription->get_payment_method(), 'ebanx-credit-card' ) 
				&& $subscription->get_meta( '_ebanx_masked_card_number', 1 ) 
				&& $subscription->get_meta( '_ebanx_card_brand', 1 )
				&& isset($total_rows['payment_method']) ) {
					$total_rows['payment_method']['value'] .= '<br>'. sprintf( '%s ending %s', $subscription->get_meta( '_ebanx_card_brand', 1 ), substr( $subscription->get_meta( '_ebanx_masked_card_number', 1 ), -4, 4 ) );
			}
			elseif( false !== strpos( $order->get_payment_method(), 'ebanx-credit-card' ) 
				&& $order->get_meta( '_ebanx_masked_card_number', 1 ) 
				&& $order->get_meta( '_ebanx_card_brand', 1 )
				&& isset($total_rows['payment_method']) ) {
					$total_rows['payment_method']['value'] .= '<br>'. sprintf( '%s ending %s', $order->get_meta( '_ebanx_card_brand', 1 ), substr( $order->get_meta( '_ebanx_masked_card_number', 1 ), -4, 4 ) );
			}
		}
		return $total_rows;
	}
}

	# Intialize Admin Page
	new WP_Ebanx_Subscription_Misc;
?>
