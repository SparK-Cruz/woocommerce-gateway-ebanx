<?php
/**
 * EBANX - Variable Subscription Admin
 *
 * @package    WordPress
 * @subpackage Ebanx Payment Gateway
 * @author     Shazzad Hossain Khan
 * @url https://w4dev.com/about
 **/


class WC_EBANX_Subscription_Product_Switch_Admin
{

	function __construct()
	{
		add_filter('woocommerce_product_data_tabs', __CLASS__ . '::product_data_tabs', 10);
		add_action('woocommerce_product_data_panels', __CLASS__ . '::product_data_panels', 10);
		add_action('save_post', __CLASS__ . '::save_post', 90, 3);
	}

	// ajax handlers
	public static function product_data_tabs($tabs)
	{
		$tabs['variable-subscription-switch'] = [
			'label'    => __('Switch', 'wpr'),
			'target'   => 'variable-subscription-switch',
			'class'    => ['show_if_variable-subscription'],
			'priority' => 65,
		];

		return $tabs;
	}

	public static function product_data_panels()
	{
		$post_id  = get_the_ID();
		$variable = wc_get_product($post_id);

		$keys     = [
			'_ebanx_subscription_switch_product_id',
			'_ebanx_subscription_switch_condition',
			'_ebanx_subscription_switch_product_hidden',
		];
		$settings = [];
		foreach ($keys as $key) {
			$settings[$key] = get_post_meta($post_id, $key, 1);
		}

		wc_get_template(
			'subscription/variable-subscription-data-panels.php',
			[
				'variable' => $variable,
				'settings' => $settings,
			],
			'woocommerce/ebanx/',
			WC_EBANX::get_templates_path()
		);
	}

	public static function save_post($post_id, $post, $update)
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if ('product' != $post->post_type || !isset($_POST['ebanx_subscription_edit']) || !isset($_POST['product-type']) || 'variable-subscription' != $_POST['product-type']) {
			return;
		}
		$post_data = stripslashes_deep($_POST);
		$keys      = [
			'_ebanx_subscription_switch_product_id',
			'_ebanx_subscription_switch_condition',
			'_ebanx_subscription_switch_product_hidden',
		];
		foreach ($keys as $key) {
			if (!empty($post_data[$key])) {
				update_post_meta($post_id, $key, $post_data[$key]);
			} else {
				delete_post_meta($post_id, $key);
			}
		}
	}

	public static function p($a)
	{
		echo '<pre>';
		print_r($a);
		echo '</pre>';
	}
}

# Intialize Admin Page
new WC_EBANX_Subscription_Product_Switch_Admin;
