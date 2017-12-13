<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class WC_EBANX_One_Click {
	const CREATE_ORDER_ACTION = 'ebanx_one_click_order';

	private $userId;
	private $userCountry;
	private $gateway;
	private $payment_token;

	/**
	 * Constructor
	 */
	public function __construct( $user_id ) {


		$this->userId = $user_id;
		$this->userCountry = trim(strtolower( get_user_meta( $this->userId, 'billing_country', true ) ) );
		$this->gateway = $this->userCountry 
			? (
				$this->userCountry === WC_EBANX_Constants::COUNTRY_BRAZIL 
				? new WC_EBANX_Credit_Card_BR_Gateway() 
				: new WC_EBANX_Credit_Card_MX_Gateway()
			)
			: false;

		if ( ! $this->gateway
			|| $this->gateway->get_setting_or_default('one_click', 'no') !== 'yes'
			|| $this->gateway->get_setting_or_default('save_card_data', 'no') !== 'yes' ) {
			return;
		}

		if( ! ( $this->payment_token = WC_Payment_Tokens::get_customer_default_token( $this->userId ) ) ){
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 100 );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'print_button' ) );
		add_action( 'wp_loaded', array( $this, 'one_click_handler' ), 99 );
		add_shortcode( 'ebanx-one-click', array( $this, 'button_shortcode' ) );
	}


	/**
	 * Process the one click request
	 *
	 * @return void
	 */
	public function one_click_handler() {

		if ( is_admin()
			|| ! WC_EBANX_Request::has('ebanx-action')
			|| ! WC_EBANX_Request::has('ebanx-nonce')
			|| ! WC_EBANX_Request::has('ebanx-product-id')
			|| WC_EBANX_Request::read('ebanx-action') !== self::CREATE_ORDER_ACTION
			|| ! wp_verify_nonce( WC_EBANX_Request::read('ebanx-nonce'), self::CREATE_ORDER_ACTION )
		) {
			return;
		}

		WC_EBANX::log('intializing');

		try {
			$this->load_data();

			$product_id = WC_EBANX_Request::read( 'ebanx-product-id' );

			wc_empty_cart();

			$passed_validation 	= apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
			if ( ! $passed_validation || false === WC()->cart->add_to_cart( $product_id, 1 ) ) {
				return;
			}
			# die();

			wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );
			wc_set_time_limit( 0 );
			do_action( 'woocommerce_before_checkout_process' );
			do_action( 'woocommerce_checkout_process' );

			WC()->session->set( 'chosen_payment_method', $this->gateway->id );
			WC()->cart->calculate_totals();
			do_action( 'woocommerce_check_cart_items' );

			if ( WC()->cart->needs_payment() ) {
				$this->gateway->validate_fields();
			}

			$post_data = $_REQUEST;
			$errors = new WP_Error();

			do_action( 'woocommerce_after_checkout_validation', $post_data, $errors );

			#echo '<pre>'; print_r($post_data); echo '</pre>';die();

			$order_id = WC()->checkout->create_order( $post_data );
			if ( is_wp_error( $order_id ) ) {
				throw new Exception( $order_id->get_error_message() );
			}
			$order = wc_get_order( $order_id );
			do_action( 'woocommerce_checkout_order_processed', $order_id, $post_data, $order );

			if ( wc_notice_count( 'error' ) == 0 ) {
				if ( WC()->cart->needs_payment() ) {
					WC()->session->set( 'order_awaiting_payment', $order_id );
					$response = $this->gateway->process_payment( $order_id );
				} else {
					$order->payment_complete();
					wc_empty_cart();
					$response = array(
						'result' => 'success',
						'redirect' => apply_filters('woocommerce_checkout_no_payment_needed_redirect',$order->get_checkout_order_received_url(),$order),
					);
				}

				if ( $response['result'] !== 'success' ) {
					$message = __('Ebanx One Click Purchase: Unable to create the payment via one click.', 'woocommerce-gateway-ebanx');
					$order->add_order_note($message);
					throw new Exception($message);
				}

				wp_safe_redirect( $response['redirect'] );
				exit;
			}
		}
		catch (Exception $e) {
			wc_add_notice( sprintf( __( 'Ebanx One Click Purchase: %s.', 'woocommerce-gateway-ebanx' ), $e->getMessage() ), 'error' );
		}
	}

	protected function load_data() {
		// data for ebanx
		WC_EBANX_Request::set('ebanx_token', $this->payment_token->get_token());
		WC_EBANX_Request::set('ebanx_masked_card_number', $this->payment_token->get_meta('masked_card_number', 1));
		WC_EBANX_Request::set('ebanx_brand', $this->payment_token->get_card_type() );
		WC_EBANX_Request::set('ebanx_is_one_click', true);
		WC_EBANX_Request::set('ebanx-credit-card-installments', 1);
		WC_EBANX_Request::set('ebanx_billing_instalments', 1);
		$names = $this->gateway->names;
		WC_EBANX_Request::set($names['ebanx_billing_brazil_document'], get_user_meta( $this->userId, '_ebanx_billing_brazil_document', true ));
		WC_EBANX_Request::set($names['ebanx_billing_colombia_document'], get_user_meta( $this->userId, '_ebanx_billing_colombia_document', true ));

		// data for cart
		WC_EBANX_Request::set('checkout_via', 'ebanx-one-click');
		WC_EBANX_Request::set('payment_method', $this->gateway->id);
		$user_addresses = $this->get_user_addresses();
		foreach( $user_addresses as $type => $addresses ){
			foreach( $addresses as $name => $value ){
				WC_EBANX_Request::set("{$type}_{$name}", $value );
			}
		}
	}

	/**
	 * It creates the user's billing data to process the one click response
	 *
	 * @return array
	 */
	public function get_user_addresses() {

		$customer = new WC_Customer( $this->userId );

		return array( 
			'shipping' => apply_filters( 'ebanx_customer_shipping', array(
				'first_name' => $customer->get_shipping_first_name(),
				'last_name'  => $customer->get_shipping_last_name(),
				'company'    => $customer->get_shipping_company(),
				'address_1'  => $customer->get_shipping_address_1(),
				'address_2'  => $customer->get_shipping_address_2(),
				'city'       => $customer->get_shipping_city(),
				'state'      => $customer->get_shipping_state(),
				'postcode'   => $customer->get_shipping_postcode(),
				'country'    => $customer->get_shipping_country()
			)),
			'billing' => apply_filters( 'ebanx_customer_billing', array(
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'company'    => $customer->get_billing_company(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'postcode'   => $customer->get_billing_postcode(),
				'country'    => $customer->get_billing_country(),
				'email'      => $customer->get_billing_email(),
				'phone'      => $customer->get_billing_phone()
			))
		);
	}

	/**
	 * Set the assets necessary by one click works
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_script(
			'woocommerce_ebanx_one_click_script',
			plugins_url( 'assets/js/one-click.js', WC_EBANX::DIR ),
			array(),
			WC_EBANX::get_plugin_version(),
			true
		);

		wp_enqueue_style(
			'woocommerce_ebanx_one_click_style',
			plugins_url( 'assets/css/one-click.css', WC_EBANX::DIR )
		);
	}

	/**
	 * Render the button "One-Click Purchase" using a template
	 *
	 * @return void
	 */
	public function print_button() {
		global $product;
		return $this->button_shortcode( array('product_id' => $product->get_id() ) );
	}
	
	public function button_shortcode( $attrs ) {

		if( empty($attrs['product_id']) || 'product' != get_post_type($attrs['product_id']) ){
			return '';
		}

		$product = wc_get_product( $attrs['product_id'] );

		if( $product->is_type( array('simple', 'variable', 'subscription', 'variable_subscription') ) ) {

			$title = $product->get_name();

			$button_text = empty($attrs['button_text']) ? __( 'One click purchase', 'woocommerce-gateway-ebanx' ) : $attrs['button_text'];

			$args = apply_filters( 'ebanx_template_args', array(
				'product_id' 			=> $product->get_id(),
				'button_text'			=> $button_text,
				'title'					=> $title,
				'nonce' 				=> wp_create_nonce( self::CREATE_ORDER_ACTION ),
				'action' 				=> self::CREATE_ORDER_ACTION,
				'permalink' 			=> get_permalink( $product->get_id() )
			));
			wc_get_template( 'one-click.php', $args, '', WC_EBANX::get_templates_path() . 'one-click/' );
		}
	}
}

add_action( 'init', 'wc_ebanx_one_click_init' );

function wc_ebanx_one_click_init() {
	if( is_user_logged_in() && ! is_admin() ) {
		new WC_EBANX_One_Click( get_current_user_id() );
	}
}
