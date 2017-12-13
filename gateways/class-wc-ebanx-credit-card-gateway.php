<?php

if (!defined('ABSPATH')) {
	exit;
}

abstract class WC_EBANX_Credit_Card_Gateway extends WC_EBANX_Gateway
{
	/**
	 * The rates for each instalment
	 *
	 * @var array
	 */
	protected $instalment_rates = array();

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->api_name = '_creditcard';

		parent::__construct();

		$this->save_card_data = $this->get_setting_or_default('save_card_data', 'no') === 'yes';

		// let credit card support all features of subscriptions
		$this->supports[] = 'products';
		$this->supports[] = 'subscriptions';
		$this->supports[] = 'subscription_cancellation';
		$this->supports[] = 'subscription_suspension';
		$this->supports[] = 'subscription_reactivation';
		$this->supports[] = 'subscription_amount_changes';
		$this->supports[] = 'subscription_date_changes';
		$this->supports[] = 'subscription_payment_method_change';
		$this->supports[] = 'subscription_payment_method_change_customer';
		$this->supports[] = 'subscription_payment_method_change_admin';
		$this->supports[] = 'tokenization';
		$this->supports[] = 'add_payment_method';
		$this->supports[] = 'multiple_subscriptions';

		if ($this->get_setting_or_default('interest_rates_enabled', 'no') == 'yes') {
			$max_instalments = $this->configs->settings['credit_card_instalments'];
			for ($i=1; $i <= $max_instalments; $i++) {
				$field = 'interest_rates_' . sprintf("%02d", $i);
				$this->instalment_rates[$i] = 0;
				if (is_numeric($this->configs->settings[$field])) {
					$this->instalment_rates[$i] = $this->configs->settings[$field] / 100;
				}
			}
		}
		
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment_action' ) );
		add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment_action' ) );
		add_action( 'woocommerce_order_status_on-hold_to_cancelled', array( $this, 'cancel_payment_action' ) );
		add_action( 'woocommerce_order_status_on-hold_to_refunded', array( $this, 'cancel_payment_action' ) );

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_checkout_subscription_created', array( $this, 'checkout_subscription_created' ), 10, 2 );
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		}
	}

	public function checkout_subscription_created( $subscription, $order )
	{
		if( $this->id == $order->get_payment_method() ) {
			WC_EBANX::log( 'Ebanx - copying payment meta on subscription' );
			if( !empty(WC_EBANX_Request::read('ebanx_brand', null) ) ) {
				update_post_meta($subscription->get_id(), '_ebanx_card_brand', WC_EBANX_Request::read('ebanx_brand'));
			}
			if( !empty(WC_EBANX_Request::read('ebanx_masked_card_number', null))){
				update_post_meta($subscription->get_id(), '_ebanx_masked_card_number', WC_EBANX_Request::read('ebanx_masked_card_number'));
			}
		}
	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param $amount_to_charge float The amount to charge.
	 * @param $order WC_Order A WC_Order object for renewal payment.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $order )
	{
		WC_EBANX::log( 'WC_EBANX_Credit_Card_Gateway::scheduled_subscription_payment. Amount - '. $amount_to_charge );

		$result = $this->process_subscription_payment( $order, $amount_to_charge );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf( __( 'Ebanx Transaction Failed (%s)', 'woocommerce-gateway-ebanx' ), $result->get_error_message() ) );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order );
			return;
		}

		WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
	}

	/**
	 * @param mixed $order
	 * @throws Exception
	 */

	public function process_subscription_payment($order)
	{
		WC_EBANX::log( 'WC_EBANX_Credit_Card_Gateway::process_subscription_payment' );

		$payment_token = false;
		if( $order->get_meta( '_ebanx_masked_card_number' ) ) {
			$payment_tokens = WC_Payment_Tokens::get_tokens( array(
				'user_id' 		=> $order->get_customer_id(),
				'gateway_id' 	=> $this->id
			));
			if( ! empty($payment_tokens) ) {
				foreach( $payment_tokens as $payment_token ) {
					if( $payment_token->get_meta('masked_card_number', 1) == $order->get_meta('_ebanx_masked_card_number', 1) ) {
						break;
					}
				}
			}
		}

		// if order have a payment token, we will use that.
		if( $payment_token ) {
			WC_EBANX_Request::set( 'ebanx_pay_by_token', $payment_token->get_id() );
			WC_EBANX_Request::set( 'ebanx_brand', $payment_token->get_card_type() );
			WC_EBANX_Request::set( 'ebanx_token', $payment_token->get_token() );
			WC_EBANX_Request::set( 'ebanx_masked_card_number', $payment_token->get_meta('masked_card_number', 1) );
		} else {
			WC_EBANX::log( 'WC_EBANX_Credit_Card_Gateway::process_subscription_payment - card not saved, can not capture renewal payments.' );
			throw new Exception( 'Invalid card details' );
		}

		WC_EBANX_Request::set('billing_postcode', $order->get_billing_postcode() );
		WC_EBANX_Request::set('billing_address_1', $order->get_billing_address_1() );
		WC_EBANX_Request::set('billing_city', $order->get_billing_city() );
		WC_EBANX_Request::set('billing_state', $order->get_billing_state() );
		WC_EBANX_Request::set('billing_country', $order->get_billing_country() );

		if( WC_EBANX_Constants::COUNTRY_BRAZIL == strtolower( $order->get_billing_country() ) ) {
			if( ! ( $document = $order->get_meta( '_ebanx_billing_brazil_document', true ) ) ) {
				$document = get_user_meta( $order->get_customer_id(), '_ebanx_billing_brazil_document', true );
			}
			WC_EBANX_Request::set('ebanx_billing_brazil_document', $document );
		}
		elseif( WC_EBANX_Constants::COUNTRY_COLOMBIA == strtolower( $order->get_billing_country() ) ) {
			if( ! ( $document = $order->get_meta( '_ebanx_billing_colombia_document', true ) ) ) {
				$document = get_user_meta( $order->get_customer_id(), '_ebanx_billing_colombia_document', true );
			}
			WC_EBANX_Request::set('ebanx_billing_colombia_document', $document );
		}
		elseif( WC_EBANX_Constants::COUNTRY_CHILE == strtolower( $order->get_billing_country() ) ) {
			if( ! ( $document = $order->get_meta( '_ebanx_billing_chile_document', true ) ) ) {
				$document = get_user_meta( $order->get_customer_id(), '_ebanx_billing_chile_document', true );
			}
			WC_EBANX_Request::set('ebanx_billing_chile_document', $document );
		}

		$return = $this->process_payment( $order->get_id() );

		return $return;
	}

	/**
	 * Check the Auto Capture
	 *
	 * @param  array $actions
	 * @return array
	 */
	public function auto_capture($actions)
	{
		if (is_array($actions)) {
			$actions['custom_action'] = __('Capture by EBANX');
		}

		return $actions;
	}

	/**
	 * Action to capture the payment
	 *
	 * @return void
	 */
	public function capture_payment_action( $order_id )
	{
		$order = wc_get_order($order_id);
		$hash = get_post_meta( $order->get_id(), '_ebanx_payment_hash', true );

		if( $order->get_payment_method() !== $this->id || ! $hash ) {
			return false;
		}

		WC_EBANX::log( 'capture_payment_action # '. $order_id );

		\Ebanx\Config::set(array(
			'integrationKey' => $this->private_key,
			'testMode' => $this->is_sandbox_mode,
			'directMode' => true,
		));

		$response = \Ebanx\Ebanx::doCapture(array('hash' => get_post_meta($order->get_id(), '_ebanx_payment_hash', true)));
		$error = $this->check_capture_errors( $response );

		$is_recapture = false;
		if( $error ){
			$is_recapture = $error->code === 'BP-CAP-4';
			$response->payment->status = $error->status;

			WC_EBANX::log($error->message);
			WC_EBANX_Flash::add_message($error->message, 'warning', true);
		}

		if ( $response->payment->status == 'CO' ) {
			if ( ! $is_recapture ) {
				$order->add_order_note(__('EBANX: Transaction was captured.', 'woocommerce-gateway-ebanx'));
			}
		}
		else if ($response->payment->status == 'CA') {
			$order->add_order_note(__('EBANX: Transaction Failed', 'woocommerce-gateway-ebanx'));
		}
		else if ($response->payment->status == 'OP') {
			$order->add_order_note(__('EBANX: Transaction Pending', 'woocommerce-gateway-ebanx'));
		}
	}

	/**
	 * @param $order_id
	 * @return bool
	 */
	public function cancel_payment_action( $order_id )
	{
		$order = wc_get_order($order_id);
		$hash = get_post_meta( $order->get_id(), '_ebanx_payment_hash', true );

		if( $order->get_payment_method() !== $this->id || ! $hash ) {
			return false;
		}

		// TODO
		try {
			\Ebanx\Config::set(array(
				'integrationKey' => $this->private_key,
				'testMode' => $this->is_sandbox_mode,
				'directMode' => true,
			));

			$response = \Ebanx\Ebanx::doCancel(array('hash' => get_post_meta($order->get_id(), '_ebanx_payment_hash', true)));
			if ($response->status !== 'SUCCESS') {
				$order->add_order_note( __( 'EBANX: Unable to refund', 'woocommerce-gateway-ebanx' ));
				return false;
			}
				$order->add_order_note( __( 'EBANX: Charge refunded', 'woocommerce-gateway-ebanx' ));
		} catch( Exception $e){

		}

		return true;
	}

	/**
	 * Checks for errors during capture action
	 * Returns an object with error code, message and target status
	 *
	 * @param object $response The response from EBANX API
	 * @return stdClass
	 */
	public function check_capture_errors($response)
	{
		if ( $response->status !== 'ERROR' ) {
			return null;
		}

		$code = $response->code;
		$message = sprintf(__('EBANX - Unknown error, enter in contact with Ebanx and inform this error code: %s.', 'woocommerce-gateway-ebanx'), $response->payment->status_code);
		$status = $response->payment->status;

		switch($response->status_code) {
			case 'BC-CAP-3':
				$message = __('EBANX - Payment cannot be captured, changing it to Failed.', 'woocommerce-gateway-ebanx');
				$status = 'CA';
				break;
			case 'BP-CAP-4':
				$message = __('EBANX - Payment has already been captured, changing it to Processing.', 'woocommerce-gateway-ebanx');
				$status = 'CO';
				break;
			case 'BC-CAP-5':
				$message = __('EBANX - Payment cannot be captured, changing it to Pending.', 'woocommerce-gateway-ebanx');
				$status = 'OP';
				break;
		}

		return (object)array(
			'code' => $code,
			'message' => $message,
			'status' => $status
		);
	}

	/**
	 * Insert the necessary assets on checkout page
	 *
	 * @return void
	 */
	public function checkout_assets()
	{
		if ( is_checkout() || is_add_payment_method_page() ) {
			wp_enqueue_script('wc-credit-card-form');
			// Using // to avoid conflicts between http and https protocols
			wp_enqueue_script('ebanx', '//js.ebanx.com/ebanx-1.5.min.js', '', null, true);
			wp_enqueue_script('woocommerce_ebanx_jquery_mask', plugins_url('assets/js/jquery-mask.js', WC_EBANX::DIR), array('jquery'), WC_EBANX::get_plugin_version(), true);
			wp_enqueue_script('woocommerce_ebanx_credit_card', plugins_url('assets/js/credit-card.js', WC_EBANX::DIR), array('jquery-payment', 'ebanx'), WC_EBANX::get_plugin_version(), true);

			// If we're on the checkout page we need to pass ebanx.js the address of the order.
			if( is_checkout_pay_page() && isset($_GET['order']) && isset($_GET['order_id'])) {
				$order_key = urldecode($_GET['order']);
				$order_id = absint($_GET['order_id']);
				$order = wc_get_order($order_id);

				if ($order->get_id() === $order_id && $order->order_key === $order_key) {
					static::$ebanx_params['billing_first_name'] = $order->get_billing_first_name();
					static::$ebanx_params['billing_last_name'] = $order->get_billing_last_name();
					static::$ebanx_params['billing_address_1'] = $order->get_billing_address_1();
					static::$ebanx_params['billing_address_2'] = $order->get_billing_address_2();
					static::$ebanx_params['billing_state'] = $order->get_billing_state();
					static::$ebanx_params['billing_city'] = $order->get_billing_city();
					static::$ebanx_params['billing_postcode'] = $order->get_billing_postcode();
					static::$ebanx_params['billing_country'] = $order->get_billing_country();
				}
			}
		}

		parent::checkout_assets();
	}


	/**
	 * Validate checkout fields
	 *
	 * @return void
	 */
	public function validate_fields()
	{
		// set tokenizer
		if( ! empty(WC_EBANX_Request::read('wc-'. $this->id .'-payment-token',null)) 
			&& 'new' != WC_EBANX_Request::read('wc-'. $this->id .'-payment-token',null) 
		) {
			WC_EBANX_Request::set('ebanx_pay_by_token', WC_EBANX_Request::read('wc-'. $this->id .'-payment-token') );

			$wc_token = WC_Payment_Tokens::get( WC_EBANX_Request::read('ebanx_pay_by_token') );
			if( $wc_token && $wc_token->get_id() ) {
				WC_EBANX_Request::set( 'ebanx_brand', $wc_token->get_card_type() );
				WC_EBANX_Request::set( 'ebanx_token', $wc_token->get_token() );
				WC_EBANX_Request::set( 'ebanx_masked_card_number', $wc_token->get_meta('masked_card_number', 1) );
			}
		}

		$names = $this->names;
		if( empty(WC_EBANX_Request::read('ebanx_token', null) )
			|| empty(WC_EBANX_Request::read('ebanx_masked_card_number', null))
			|| empty(WC_EBANX_Request::read('ebanx_brand', null) )
		) {
			wc_add_notice( __( 'Missing card information.', 'woocommerce' ), 'error' );
			return;
		}
		else if( 
			empty(WC_EBANX_Request::read('ebanx_is_one_click', null)) 
			&& empty(WC_EBANX_Request::read('ebanx_device_fingerprint', null)) 
			&& empty(WC_EBANX_Request::read('ebanx_pay_by_token', null) ) 
		) {
			wc_add_notice( __( 'Missing device fingerprint, please reload.', 'woocommerce-gateway-ebanx' ), 'error' );
			return;
		}
		else if( 
			( 
				WC_EBANX_Constants::COUNTRY_BRAZIL == WC_EBANX_Request::read('billing_country', null) 
				&& ! WC_EBANX_Request::has($names['ebanx_billing_brazil_document']) 
			) || ( 
				WC_EBANX_Constants::COUNTRY_COLOMBIA == WC_EBANX_Request::read('billing_country', null)
				&& ! WC_EBANX_Request::has($names['ebanx_billing_colombia_document'])
			) || ( 
				WC_EBANX_Constants::COUNTRY_CHILE == WC_EBANX_Request::read('billing_country', null)
				&& ! WC_EBANX_Request::has($names['ebanx_billing_chile_document'])
			)
		){
			wc_add_notice( __( 'Missing document.', 'woocommerce' ), 'error' );
			return;
		}
	}

	/**
	 * The main method to process the payment came from WooCommerce checkout
	 * This method check the informations sent by WooCommerce and if them are fine, it sends the request to EBANX API
	 * The catch captures the errors and check the code sent by EBANX API and then show to the users the right error message
	 *
	 * @param  integer $order_id	The ID of the order created
	 * @return void
	 */
	public function process_payment( $order_id )
	{
		$this->handle_order_instalment( $order_id );
		return parent::process_payment( $order_id );
	}

	/**
	 * If Order has installments, then add additional interests on order total
	 *
	 * @param  integer $order_id	The ID of the order created
	 * @return void
	 */

	public function handle_order_instalment( $order_id )
	{
		$has_instalments = (WC_EBANX_Request::has('ebanx_billing_instalments') || WC_EBANX_Request::has('ebanx-credit-card-installments'));
		if ( $has_instalments && WC_EBANX_Request::read('ebanx-credit-card-installments', null) > 1 ) {

			$order = wc_get_order( $order_id );

			$total_price = get_post_meta( $order_id, '_order_total', true );
			$tax_rate = 0;
			$instalments = WC_EBANX_Request::has('ebanx_billing_instalments') ? WC_EBANX_Request::read('ebanx_billing_instalments') : WC_EBANX_Request::read('ebanx-credit-card-installments');

			if ( array_key_exists( $instalments, $this->instalment_rates ) ) {
				$tax_rate = $this->instalment_rates[$instalments];
			}

			$total_price += $total_price * $tax_rate;
			update_post_meta( $order_id, '_order_total', $total_price );
		}
	}

	/**
	 * Mount the data to send to EBANX API
	 *
	 * @param WC_Order $order
	 * @return array
	 * @throws Exception
	 */
	protected function request_data( $order )
	{
		$data = parent::request_data( $order );

		if (in_array($order->get_billing_country(), WC_EBANX_Constants::$CREDIT_CARD_COUNTRIES)) {
			$data['payment']['instalments'] = '1';

			if ($this->configs->settings['credit_card_instalments'] > 1 && WC_EBANX_Request::has('ebanx_billing_instalments')) {
				$data['payment']['instalments'] = WC_EBANX_Request::read('ebanx_billing_instalments');
			}
		}

		if ( ! empty(WC_EBANX_Request::read('ebanx_device_fingerprint', null))) {
			$data['device_id'] = WC_EBANX_Request::read('ebanx_device_fingerprint');
		}

		$data['payment']['payment_type_code'] = WC_EBANX_Request::read('ebanx_brand');
		$data['payment']['creditcard'] = array(
			'token' => WC_EBANX_Request::read('ebanx_token')
		);
		if( ! empty( WC_EBANX_Request::read('ebanx_billing_cvv', null) ) ) {
			$data['payment']['creditcard']['card_cvv'] = WC_EBANX_Request::read('ebanx_billing_cvv');
		}
		$data['payment']['creditcard']['auto_capture'] = ($this->configs->settings['capture_enabled'] === 'yes');

		return $data;
	}

	/**
	 * Process the response of request from EBANX API
	 *
	 * @param  Object $request The result of request
	 * @param  WC_Order $order   The order created
	 * @return void
	 */
	protected function process_response($request, $order)
	{
		if ($request->status == 'ERROR' || ! $request->payment->pre_approved) {
			WC_EBANX::log(sprintf(__('Processing response: %s', 'woocommerce-gateway-ebanx'), print_r($request, true)));
			return $this->process_response_error($request, $order);
		}

		parent::process_response($request, $order);
	}

	/**
	 * Save order's meta fields for future use
	 *
	 * @param  WC_Order $order The order created
	 * @param  Object $request The request from EBANX success response
	 * @return void
	 */
	protected function save_order_meta_fields( $order, $request )
	{
		parent::save_order_meta_fields( $order, $request );

		if( !empty(WC_EBANX_Request::read('ebanx_brand', null))){
			update_post_meta($order->get_id(), '_ebanx_card_brand', WC_EBANX_Request::read('ebanx_brand'));
		}
		if( isset($request->payment) && isset($request->payment->instalments) ) {
			update_post_meta($order->get_id(), '_ebanx_instalments_number', WC_EBANX_Request::read('ebanx_billing_instalments', null));
		}
		if( !empty(WC_EBANX_Request::read('ebanx_masked_card_number', null))){
			update_post_meta($order->get_id(), '_ebanx_masked_card_number', WC_EBANX_Request::read('ebanx_masked_card_number'));
		}
	}

	/**
	 * Save user's meta fields for future use
	 *
	 * @param  WC_Order $order The order created
	 * @return void
	 */
	protected function save_user_meta_fields( $order )
	{
		parent::save_user_meta_fields($order);

		if ( ! $this->userId ) {
			$this->userId = $order->get_user_id();
		}

		$payment_token = false;
		if ( $this->userId && $this->save_card_data ) {

			if( ! empty( WC_EBANX_Request::read('ebanx_pay_by_token', null) ) ) {
				$payment_token = WC_Payment_Tokens::get( WC_EBANX_Request::read( 'ebanx_pay_by_token', null ));
			}
			elseif ( WC_EBANX_Request::has( 'wc-'. $this->id .'-new-payment-method' ) ) {
				try {
					$payment_token = $this->save_user_card( stripslashes_deep( $_POST ) );
				} catch( Exception $e ){
					// nothing
				}
			}
		}

		// delete earlier payment token
		delete_post_meta( $order->get_id(), '_payment_tokens' );

		if( $payment_token && $payment_token->get_id() && 'shop_subscription' != $order->get_type() ) {
			// add new payment token
			$order->add_payment_token( $payment_token );
		}
	}

	/**
	 * Checks if the payment term is allowed based on price, country and minimal instalment value
	 *
	 * @param doubloe $price Product price used as base
	 * @param int $instalment_number Number of instalments
	 * @param string $country Costumer country
	 * @return integer
	 */
	public function is_valid_instalment_amount($price, $instalment_number, $country = null) {
		if ($instalment_number === 1) {
			return true;
		}

		$country = $country ?: WC()->customer->get_billing_country();
		$currency_code = strtolower($this->merchant_currency);

		switch (trim(strtolower($country))) {
			case 'br':
				$site_to_local_rate = $this->get_local_currency_rate_for_site(WC_EBANX_Constants::CURRENCY_CODE_BRL);
				$merchant_min_instalment_value = $this->get_setting_or_default("min_instalment_value_$currency_code", 0) * $site_to_local_rate;
				$min_instalment_value = max(
					WC_EBANX_Constants::ACQUIRER_MIN_INSTALMENT_VALUE_BRL,
					$merchant_min_instalment_value
				);
				break;
			case 'mx':
				$site_to_local_rate = $this->get_local_currency_rate_for_site(WC_EBANX_Constants::CURRENCY_CODE_MXN);
				$merchant_min_instalment_value = $this->get_setting_or_default("min_instalment_value_$currency_code", 0) * $site_to_local_rate;
				$min_instalment_value = max(
					WC_EBANX_Constants::ACQUIRER_MIN_INSTALMENT_VALUE_MXN,
					$merchant_min_instalment_value
				);
				break;
			case 'co':
				$site_to_local_rate = $this->get_local_currency_rate_for_site(WC_EBANX_Constants::CURRENCY_CODE_COP);
				$merchant_min_instalment_value = $this->get_setting_or_default("min_instalment_value_$currency_code", 0) * $site_to_local_rate;
				$min_instalment_value = max(
					WC_EBANX_Constants::ACQUIRER_MIN_INSTALMENT_VALUE_COP,
					$merchant_min_instalment_value
				);
				break;
		}

		if (isset($site_to_local_rate) && isset($min_instalment_value)) {
			$local_value = $price * $site_to_local_rate;
			$instalment_value = $local_value / $instalment_number;
			return $instalment_value >= $min_instalment_value;
		}

		return false;
	}

	/**
	 * The page of order received, we call them as "Thank you pages"
	 *
	 * @param  WC_Order $order The order created
	 * @return void
	 */
	public static function thankyou_page($order)
	{
		$order_amount = $order->get_total();
		$instalments_number = get_post_meta($order->get_id(), '_ebanx_instalments_number', true) ?: 1;
		$country = trim(strtolower(get_post_meta($order->get_id(), '_billing_country', true)));
		$currency = $order->get_currency();

		if ($country === WC_EBANX_Constants::COUNTRY_BRAZIL) {
			$order_amount += round(($order_amount * WC_EBANX_Constants::BRAZIL_TAX), 2);
		}

		$data = array(
			'data' => array(
				'card_brand_name' => get_post_meta($order->get_id(), '_ebanx_card_brand', true),
				'instalments_number' => $instalments_number,
				'instalments_amount' => wc_price(round($order_amount / $instalments_number, 2), array('currency' => $currency)),
				'masked_card' => substr(get_post_meta($order->get_id(), '_ebanx_masked_card_number', true), -4),
				'customer_email' => $order->get_billing_email(),
				'customer_name' => $order->get_billing_first_name(),
				'total' => wc_price($order_amount, array('currency' => $currency))
			),
			'order_status' => $order->get_status(),
			'method' => $order->get_payment_method()
		);

		parent::thankyou_page($data);
	}

	/**
	 * Calculates the interests and values of items based on interest rates settings
	 *
	 * @param int $amount	  The total of the user cart
	 * @param int $max_instalments The max number of instalments based on settings
	 * @param int $tax The tax applied
	 * @return filtered array	   An array of instalment with price, amount, if it has interests and the number
	 */
	public function get_payment_terms($amount, $max_instalments, $tax = 0) {
		$instalments = array();
		$instalment_taxes = $this->instalment_rates;

		for ($number = 1; $number <= $max_instalments; ++$number) {
			$has_interest = false;
			$cart_total = $amount;

			if (isset($instalment_taxes) && array_key_exists($number, $instalment_taxes)) {
				$cart_total += $cart_total * $instalment_taxes[$number];
				$cart_total += $cart_total * $tax;
				if ($instalment_taxes[$number] > 0) {
					$has_interest = true;
				}
			}

			if ( $this->is_valid_instalment_amount($cart_total, $number) ) {
				$instalment_price = $cart_total / $number;
				$instalment_price = round(floatval($instalment_price), 2);

				$instalments[] = array(
					'price' => $instalment_price,
					'has_interest' => $has_interest,
					'number' => $number
				);
			}
		}

		return apply_filters('ebanx_get_payment_terms', $instalments);
	}

	/**
	 * The HTML structure on checkout page
	 */
	public function payment_fields() {

		// handle add payment method page early
		if ( is_add_payment_method_page() ) {
			if ( $this->description ) {
				echo wpautop( wp_kses_post( $this->description ) );
			}
			wc_get_template(
				$this->id . '/payment-form-add-card.php',
				array(
					'id' 					=> $this->id,
					'customer'				=> WC()->customer,
					'currency'				=> strtoupper( get_woocommerce_currency() )
				),
				'woocommerce/ebanx/',
				WC_EBANX::get_templates_path()
			);
			return;
		}

		$display_tokenization 	= $this->supports( 'tokenization' ) && is_checkout() && $this->save_card_data;
		$cart_total 			= WC()->cart->total;

		// If paying from order, we need to get total from order not cart.
		if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) {
			$order = wc_get_order( wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) ) );
			$cart_total = $order->get_total();
		}

		$country = $this->getTransactionAddress('country');

		$max_instalments = min(
			$this->configs->settings['credit_card_instalments'],
			WC_EBANX_Constants::$MAX_INSTALMENTS[$country]
		);

		$tax = get_woocommerce_currency() === WC_EBANX_Constants::CURRENCY_CODE_BRL ? WC_EBANX_Constants::BRAZIL_TAX : 0;
		$instalments_terms = $this->get_payment_terms($cart_total, $max_instalments, $tax);

		$currency = WC_EBANX_Constants::$LOCAL_CURRENCIES[$country];


		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}

		if ( $display_tokenization ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}

		wc_get_template(
			$this->id . '/payment-form.php',
			array(
				'currency' => $currency,
				'country' => $country,
				'instalments_terms' => $instalments_terms,
				'currency' => $this->currency_code,
				'currency_rate' => round(floatval($this->get_local_currency_rate_for_site($this->currency_code)), 2),
				'cart_total' => $cart_total,
				'instalments' => __('Number of installments', 'woocommerce-gateway-ebanx'),
				'id' => $this->id,
				'add_tax' => $this->configs->get_setting_or_default('add_iof_to_local_amount_enabled', 'yes') === 'yes',
			),
			'woocommerce/ebanx/',
			WC_EBANX::get_templates_path()
		);

		if ( $display_tokenization ) {
			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * Add payment method via account screen.
	 * We don't store the token locally, but to the Stripe API.
	 * @since 3.0.0
	 */
	public function add_payment_method() {
		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'There was a problem adding the card.', 'woocommerce-gateway-ebanx' ), 'error' );
			return;
		}

		$this->save_user_card( stripslashes_deep( $_POST ) );

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}


	/**
	 * Add payment method via account screen.
	 * We don't store the token locally, but to the Stripe API.
	 * @since 3.0.0
	 */
	public function save_user_card( $post_data ) {
		if ( class_exists( 'WC_Payment_Token_CC' ) ) {
			try {
				$expiries = explode( '/', $post_data['ebanx_billing_expiry'] );

				$token = new WC_Payment_Token_CC();
				$token->set_token( $post_data['ebanx_token'] );
				$token->set_gateway_id( $post_data['payment_method'] );
				$token->set_card_type( strtolower( $post_data['ebanx_brand'] ) );
				$token->set_last4( substr( $post_data['ebanx_masked_card_number'], -4, 4 ) );
				$token->set_expiry_month( $expiries[0] );
				$token->set_expiry_year( $expiries[1] );
				$token->set_user_id( get_current_user_id() );
				$token->add_meta_data( 'masked_card_number', $post_data['ebanx_masked_card_number'], true );
				$token->save();
	
				return $token;
			} catch( Exception $e ){
				return false;
			}
		}
		return false;
	}
}
