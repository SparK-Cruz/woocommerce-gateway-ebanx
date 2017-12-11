<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_EBANX_Subscription_Common
{

	public static function get_subscription_product_parent($subscription)
	{
		$product = self::get_subscription_product($subscription);
		if (!$product || !$product->get_parent_id()) {
			return $product;
		}

		return wc_get_product($product->get_parent_id());
	}

	public static function get_subscription_product($subscription)
	{
		foreach ($subscription->get_items() as $item) {
			if ($item->is_type('line_item') && ($product = $item->get_product()) && $product->is_type([
					'subscription',
					'subscription_variation',
				])) {
				return $product;
			}
		}

		return false;
	}

	public static function get_subscription_product_item($subscription)
	{
		foreach ($subscription->get_items() as $item) {
			if ($item->is_type('line_item') && ($product = $item->get_product()) && $product->is_type([
					'subscription',
					'subscription_variation',
				])) {
				return $item;
			}
		}

		return false;
	}
}
