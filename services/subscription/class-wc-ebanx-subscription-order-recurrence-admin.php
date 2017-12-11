<?php
/**
 * EBANX - Subscription Admin
 *
 * @package    WordPress
 * @subpackage Ebanx Payment Gateway
 * @author     Shazzad Hossain Khan
 * @url https://w4dev.com/about
 **/

class WC_EBANX_Subscription_Order_Recurrence_Admin
{

	function __construct()
	{
		add_action('add_meta_boxes', __CLASS__ . '::add_meta_boxes', 24);
		add_action('save_post', __CLASS__ . '::save_post', 90, 3);
	}

	public static function add_meta_boxes()
	{
		add_meta_box(
			'woocommerce-subscription-recurrence',
			_x('Subscription Recurrence', 'meta box title', 'woocommerce-subscriptions'),
			__CLASS__ . '::metabox_output',
			'shop_subscription',
			'side',
			'default'
		);
	}

	public static function metabox_output()
	{
		$post_id = get_the_ID();

		$subscription = wcs_get_subscription($post_id);
		$settings     = self::get_data($post_id);

		wc_get_template(
			'subscription/metabox-subscription-order-recurrence.php',
			[
				'subscription' => $subscription,
				'settings'     => $settings,
			],
			'woocommerce/ebanx/',
			WC_EBANX::get_templates_path()
		);
	}

	public static function get_data($post_id)
	{
		$keys = [
			'_ebanx_subscription_recurrence_created',
			'_ebanx_subscription_recurrence_cancelled',
			'_ebanx_subscription_recurrence_status',
		];
		$data = [];
		foreach ($keys as $key) {
			$data[$key] = get_post_meta($post_id, $key, 1);
		}

		return $data;
	}

	/**
	 * @param int $post_id
	 * @param int|WP_Post $post
	 */
	public static function save_post($post_id, $post)
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if ('shop_subscription' != $post->post_type || !isset($_POST['_ebanx_subscription_order_recurrence_edit'])) {
			return;
		}

		self::save_data($post_id, stripslashes_deep($_POST));
	}

	public static function save_data($post_id, $post_data = [])
	{

		$old_data = self::get_data($post_id);

		$do_activate = $do_deactivate = false;

		if (!empty($post_data['_ebanx_subscription_recurrence_status'])
			&& $old_data['_ebanx_subscription_recurrence_status'] != $post_data['_ebanx_subscription_recurrence_status']) {
			if ('active' == $post_data['_ebanx_subscription_recurrence_status']) {
				$do_activate                                         = true;
				$post_data['_ebanx_subscription_recurrence_created'] = current_time('mysql', 1);
				$post_data['_ebanx_subscription_switch_cancelled']   = '';
			} else if ('inactive' == $post_data['_ebanx_subscription_recurrence_status']) {
				$do_deactivate                                         = true;
				$post_data['_ebanx_subscription_recurrence_created']   = '';
				$post_data['_ebanx_subscription_recurrence_cancelled'] = current_time('mysql', 1);
			}
		}

		if (!$do_activate && !$do_deactivate) {
			return false;
		}

		#self::p( $do_deactivate ); die();

		$keys = [
			'_ebanx_subscription_recurrence_created',
			'_ebanx_subscription_recurrence_cancelled',
			'_ebanx_subscription_recurrence_status',
		];
		foreach ($keys as $key) {
			if (empty($post_data[$key])) {
				delete_post_meta($post_id, $key);
				continue;
			}

			update_post_meta($post_id, $key, $post_data[$key]);
		}

		$subscription = wcs_get_subscription($post_id);

		// if deactivating, reset the expiration date
		if ($do_deactivate) {
#			$from_date = $subscription->get_date('date_created');
#			$expiration_date = gmdate( 'Y-m-d H:i:s', wcs_add_time( WC_Subscriptions_Product::get_interval( $product_id ), WC_Subscriptions_Product::get_period( $product_id ), wcs_date_to_time( $from_date ) ) );
			$expiration_date = gmdate('Y-m-d H:i:s', (strtotime($subscription->get_date('next_payment'), time()) + 5));
			#echo $expiration_date;
			#die();

			#$expiration_date = WC_Subscriptions_Product::get_expiration_date( $product, $subscription->get_date('date_created') );
			$subscription->update_dates(['end' => $expiration_date]);
			$subscription->add_order_note('Recurrence Deactived');

			// if activating, remove expiration date
		} else if ($do_activate) {
			$subscription->delete_date('end');
			$subscription->add_order_note('Recurrence Activated');
		}
	}
}

# Intialize Admin Page
new WC_EBANX_Subscription_Order_Recurrence_Admin;
