<?php
/**
 * Enabx - Subscription Admin
 * @package WordPress
 * @subpackage Ebanx Payment Gateway
 * @author Shazzad Hossain Khan
 * @url https://w4dev.com/about
**/

class WP_Ebanx_Subscription_Order_Switch_Admin {

	function __construct() {
		add_action( 'add_meta_boxes'									, __CLASS__ .'::add_meta_boxes'				, 24 );
		add_action( 'save_post'			 								, __CLASS__ .'::save_post'					, 90, 3 );
	}
	public static function get_data( $post_id ) {
		$keys = array( 
			'_ebanx_subscription_switch_created',
			'_ebanx_subscription_switch_completed',
			'_ebanx_subscription_switch_cancelled',
			'_ebanx_subscription_switch_product_id',
			'_ebanx_subscription_switch_condition'
		);
		$data = array();
		foreach( $keys as $key ) {
			$data[ $key ] = get_post_meta( $post_id, $key, 1 );
		}
		return $data;
	}
	public static function save( $post_id, $raw_data = array() ) {
		$keys = array(
			'_ebanx_subscription_switch_created',
			'_ebanx_subscription_switch_completed',
			'_ebanx_subscription_switch_cancelled',
			'_ebanx_subscription_switch_product_id',
			'_ebanx_subscription_switch_condition'
		);
		foreach( $keys as $key ) {
			if( ! empty( $raw_data[ $key ] ) ) {
				update_post_meta( $post_id, $key, $raw_data[ $key ] );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}
	public static function add_meta_boxes() {

		$post_id = get_the_ID();
		add_meta_box( 
			'woocommerce-subscription-switch', 
			_x( 'Subscription Switch', 'meta box title', 'woocommerce-subscriptions' ), 
			__CLASS__ .'::metabox_output', 
			'shop_subscription', 
			'side', 
			'default'
		);
	}
	public static function metabox_output() {
		$post_id = get_the_ID();

		$subscription = wcs_get_subscription( $post_id );
		$subscription_product = WC_EBANX_Subscription_Common::get_subscription_product_parent( $subscription );
		$settings = self::get_data( $post_id );

		wc_get_template(
			'subscription/metabox-subscription-order-switch.php',
			array(
				'subscription' 			=> $subscription,
				'subscription_product' 	=> $subscription_product,
				'settings' 				=> $settings
			),
			'woocommerce/ebanx/',
			WC_EBANX::get_templates_path()
		);
	}

	public static function save_post( $post_id, $post, $update ) {
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
			return;
		}
		if( 'shop_subscription' != $post->post_type || ! isset($_POST['ebanx_subscription_edit']) ){
			return;
		}
		$post_data = stripslashes_deep( $_POST );
		$old_data = self::get_data( $post_id );

		$do_update = false;
		if( empty($post_data['_ebanx_subscription_switch_condition']) ) {
			$do_update = true;
			$post_data['_ebanx_subscription_switch_condition'] = '';
			$post_data['_ebanx_subscription_switch_created'] = '';
			$post_data['_ebanx_subscription_switch_cancelled'] = current_time('mysql', 1);
		}
		elseif( ! empty($old_data['_ebanx_subscription_switch_product_id']) && $old_data['_ebanx_subscription_switch_product_id'] != $post_data['_ebanx_subscription_switch_product_id'] ){
			$do_update = true;
			$post_data['_ebanx_subscription_switch_created'] = current_time('mysql', 1);
			$post_data['_ebanx_subscription_switch_cancelled'] = '';
		}
		elseif( $old_data['_ebanx_subscription_switch_condition'] != $post_data['_ebanx_subscription_switch_condition'] ){
			$do_update = true;
			$post_data['_ebanx_subscription_switch_created'] = current_time('mysql', 1);
			$post_data['_ebanx_subscription_switch_cancelled'] = '';
		}

		if( $do_update ) {
			self::save( $post_id, $post_data );
		}
	}
	public static function p($a) {
		echo '<pre>'; print_r($a); echo '</pre>';
	}
}

	# Intialize Admin Page
	new WP_Ebanx_Subscription_Order_Switch_Admin;
?>
