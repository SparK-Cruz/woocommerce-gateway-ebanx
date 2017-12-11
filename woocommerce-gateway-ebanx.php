<?php
/**
 * Plugin Name: WooCommerce EBANX Gateway
 * Plugin URI: https://w4dev.com
 * Description: Ebanx Payment Gateway
 * Author: Shazzad Hossain Khan
 * Author URI: https://w4dev.com
 * Version: 1.6
 * License: MIT
 * Text Domain: woocommerce-gateway-ebanx
 * Domain Path: /languages
 *
 * @package WooCommerce_EBANX
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

// stop collision with other active version
if( defined('WC_EBANX_MIN_PHP_VER') ) {
	return '';
}

define('WC_EBANX_MIN_PHP_VER', '5.6.0');
define('WC_EBANX_MIN_WC_VER', '2.6.0');
define('WC_EBANX_MIN_WP_VER', '4.0.0');
define('WC_EBANX_DIR', __DIR__ . DIRECTORY_SEPARATOR);
define('WC_EBANX_PLUGIN_DIR_URL', plugin_dir_url(__FILE__) . DIRECTORY_SEPARATOR);
define('WC_EBANX_PLUGIN_NAME', WC_EBANX_PLUGIN_DIR_URL . basename(__FILE__));
define('WC_EBANX_GATEWAYS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'gateways' . DIRECTORY_SEPARATOR);
define('WC_EBANX_SERVICES_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR);
define('WC_EBANX_EXCEPTIONS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'exceptions' . DIRECTORY_SEPARATOR);
define('WC_EBANX_LANGUAGES_DIR', dirname( plugin_basename(__FILE__) ) . '/languages/');
define('WC_EBANX_TEMPLATES_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR);
define('WC_EBANX_VENDOR_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
define('WC_EBANX_ASSETS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR);
define('WC_EBANX_CONTROLLERS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR);

if ( ! class_exists('WC_EBANX') ) {
	/**
	 * Hooks
	 */
	register_activation_hook(__FILE__, array('WC_EBANX', 'activate_plugin'));
	register_deactivation_hook(__FILE__, array('WC_EBANX', 'deactivate_plugin'));

	/**
	 * WooCommerce WC_EBANX main class.
	 */
	class WC_EBANX
	{
		/**
		 * Plugin version.
		 *
		 * @var string
		 */
		const DIR = __FILE__;

		/**
		 * Instance of this class.
		 *
		 * @var object
		 */
		protected static $instance = null;

		private static $log;

		/**
		 * Initialize the plugin public actions.
		 */
		private function __construct()
		{
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-notice.php';

			$this->notices = new WC_EBANX_Notice();

			if (!class_exists('WC_Payment_Gateway')) {
				$this->notices
					->with_view('missing-woocommerce')
					->enqueue();
				return;
			}

			/**
			 * Includes
			 */
			$this->includes();

			$configs = new WC_EBANX_Global_Gateway();

			/**
			 * Actions
			 */
			add_action('plugins_loaded', array($this, 'plugins_loaded'));
			add_action('wp_loaded', array($this, 'enable_i18n'));

			add_action('init', array($this, 'ebanx_router'));
			add_action('init', array('WC_EBANX_Third_Party_Compability_Layer', 'check_and_solve'));
			add_action('admin_init', array($this, 'ebanx_sidebar_shortcut'));
			add_action('admin_init', array('WC_EBANX_Flash', 'enqueue_admin_messages'));

			if ( WC_EBANX_Request::is_post_empty() ) {
				add_action('admin_init', array($this, 'setup_configs'), 10);
				add_action('admin_init', array($this, 'checker'), 30);
			}

			add_action( 'admin_head', array( 'WC_EBANX_Capture_Payment', 'add_order_capture_button_css'));

			add_action('woocommerce_order_actions', array('WC_EBANX_Capture_Payment', 'add_auto_capture_dropdown'));
			add_action('woocommerce_order_action_ebanx_capture_order', array('WC_EBANX_Capture_Payment', 'capture_from_order_dropdown'));

			add_action('admin_footer', array('WC_EBANX_Assets', 'render'), 0);

			add_action('woocommerce_settings_saved', array($this, 'setup_configs'), 10);
			add_action('woocommerce_settings_saved', array($this, 'on_save_settings'), 10);
			add_action('woocommerce_settings_saved', array($this, 'update_lead'), 20);
			add_action('woocommerce_settings_saved', array($this, 'checker'), 20);

			add_action('woocommerce_admin_order_data_after_order_details', array($this, 'ebanx_admin_order_details'), 10, 1);

			/**
			 * Payment by Link
			 */
			add_action('woocommerce_order_actions_end', array($this, 'ebanx_metabox_save_post_render_button'));
			add_action('save_post', array($this, 'ebanx_metabox_payment_link_save'));

			/**
			 * My account card section has been removed as WooCommerce already provide card storage feature.
			 */

			/**
			 * Filters
			 */
			add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
			add_filter('woocommerce_my_account_my_orders_actions', array('WC_EBANX_Cancel_Order', 'add_my_account_cancel_order_action'), 10, 2);
			add_filter('woocommerce_admin_order_actions', array( 'WC_EBANX_Capture_Payment', 'add_order_capture_button'), 10, 2);

			/**
			 * Payment Tokens
			 */
		}

		/**
		* Sets up the configuration object
		*
		* @return void
		*/
		public function setup_configs() {
			/**
			 * Configs
			 */
			$this->configs = new WC_EBANX_Global_Gateway();
			$this->is_sandbox_mode = $this->configs->settings['sandbox_mode_enabled'] === 'yes';
			$this->private_key = $this->is_sandbox_mode ? $this->configs->settings['sandbox_private_key'] : $this->configs->settings['live_private_key'];
			$this->public_key = $this->is_sandbox_mode ? $this->configs->settings['sandbox_public_key'] : $this->configs->settings['live_public_key'];
		}

		/**
		 * Extract some informations from the plugin
		 * @param  string $info The information that you want to extract, possible values: version, name, description, author, network
		 * @return string       The value extracted
		 */
		public static function get_plugin_info($info = 'name') {
			$plugin = get_file_data(__FILE__, array($info => $info));

			return $plugin[$info];
		}

		/**
		 * Extract the plugin version described on plugin's header
		 *
		 * @return string The plugin version
		 */
		public static function get_plugin_version() {
			return self::get_plugin_info('version');
		}

		/**
		* Performs checks on some system status
		*
		* @return void
		*/
		public function checker() {
			WC_EBANX_Checker::check_sandbox_mode($this);
			WC_EBANX_Checker::check_merchant_api_keys($this);
			WC_EBANX_Checker::check_environment($this);
			WC_EBANX_Checker::check_currency($this);
			WC_EBANX_Checker::check_https_protocol($this);
		}

		/**
		 * Call when the plugins are loaded
		 *
		 * @return void
		 */
		public function plugins_loaded()
		{
			if ($this->get_environment_warning()) {
				return;
			}
		}

		/**
		 * Checks if we are receiving a third-party request and routes it
		 *
		 * @return void
		 */
		public function ebanx_router() {
			$ebanx_router = new WC_EBANX_Query_Router('ebanx');

			$this->setup_configs();
			$api_controller = new WC_EBANX_Api_Controller($this->configs);

			$ebanx_router->map('dashboard-check', array($api_controller, 'dashboard_check'));
			$ebanx_router->map('order-received', array($api_controller, 'order_received'));
			$ebanx_router->map('cancel-order', array($api_controller, 'cancel_order'));
			$ebanx_router->map('capture-payment', array($api_controller, 'capture_payment'));

			$ebanx_router->serve();
		}

		/**
		 * It enables the i18n of the plugin using the languages folders and the domain 'woocommerce-gateway-ebanx'
		 *
		 * @return void
		 */
		public function enable_i18n() {
			load_plugin_textdomain('woocommerce-gateway-ebanx', false, WC_EBANX_LANGUAGES_DIR);
		}

		/**
		 * Save some informations from merchant and send to EBANX servers
		 *
		 * @return void
		 */
		public static function save_merchant_infos() {
			// Prevent fatal error if WooCommerce isn't installed
			if ( !defined('WC_VERSION') ) {
				return;
			}

			// Save merchant informations
			$user = get_userdata(get_current_user_id());
			if (!$user || is_wp_error($user)) {
				return;
			}

			$url = 'https://dashboard.ebanx.com/api/lead';
			$args = array(
				'body' => array(
					'lead' => array(
						'user_email' => $user->user_email,
						'user_display_name' => $user->display_name,
						'user_last_name' => $user->last_name,
						'user_first_name' => $user->first_name,
						'site_email' => get_bloginfo('admin_email'),
						'site_url' => get_bloginfo('url'),
						'site_name' => get_bloginfo('name'),
						'site_language' => get_bloginfo('language'),
						'wordpress_version' => get_bloginfo('version'),
						'woocommerce_version' => WC()->version
					)
				)
			);

			// Call EBANX API to save a lead
			$request = wp_remote_post($url, $args);

			if (isset($request['body'])) {
				$data = json_decode($request['body']);

				// Update merchant
				update_option('_ebanx_lead_id', $data->id, false);
			}
		}

		/**
		 * A method that will be called every time settings are saved
		 *
		 * @return void
		 */
		public function on_save_settings() {
			// Delete flag that check if the api is ok
			delete_option('_ebanx_api_was_checked');

			do_action('ebanx_settings_saved', $_POST);
		}

		/**
		 * Update and inegrate the lead to the merchant using the merchant's integration key
		 *
		 * @return void
		 */
		public function update_lead() {
			$url = 'https://dashboard.ebanx.com/api/lead';
			$lead_id = get_option('_ebanx_lead_id');

			$args = array(
				'body' => array(
					'lead' => array(
						'id' => $lead_id,
						'integration_key' => $this->private_key
					)
				)
			);

			// Call EBANX API to save a lead
			wp_remote_post($url, $args);
		}

		/**
		 * Method that will be called when plugin is activated
		 *
		 * @return void
		 */
		public static function activate_plugin() {
			self::save_merchant_infos();

			flush_rewrite_rules();

			do_action('ebanx_activate_plugin');
		}

		/**
		 * Method that will be called when plugin is deactivated
		 *
		 * @return void
		 */
		public static function deactivate_plugin() {
			flush_rewrite_rules();

			do_action('ebanx_deactivate_plugin');
		}

		/**
		 * Return an instance of this class.
		 *
		 * @return object A single instance of this class.
		 */
		public static function get_instance()
		{
			// If the single instance hasn't been set, set it now.
			if (null === self::$instance) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Includes.
		 */
		private function includes()
		{
			// Utils
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-constants.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-helper.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-notice.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-hooks.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-checker.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-flash.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-request.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-errors.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-assets.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-query-router.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-third-party-compability-layer.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-cancel-order.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-capture-payment.php';

			// New
			include_once WC_EBANX_SERVICES_DIR . 'subscription/class-wc-ebanx-subscription-common.php';
			include_once WC_EBANX_SERVICES_DIR . 'subscription/class-wc-ebanx-subscription-product-switch-admin.php';
			include_once WC_EBANX_SERVICES_DIR . 'subscription/class-wc-ebanx-subscription-order-switch.php';
			include_once WC_EBANX_SERVICES_DIR . 'subscription/class-wc-ebanx-subscription-order-switch-admin.php';
			include_once WC_EBANX_SERVICES_DIR . 'subscription/class-wc-ebanx-subscription-order-recurrence-admin.php';
			include_once WC_EBANX_SERVICES_DIR . 'subscription/class-wc-ebanx-subscription-misc.php';

			// Gateways
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-redirect-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-flow-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-global-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-credit-card-gateway.php';

			// Chile Gateways
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-servipag-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-sencillito-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-webpay-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-multicaja-gateway.php';

			// Brazil Gateways
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-banking-ticket-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-credit-card-br-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-account-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-tef-gateway.php';

			// Mexico Gateways
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-credit-card-mx-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-debit-card-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-oxxo-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-spei-gateway.php';

			// Argentina Gateways
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-efectivo-gateway.php';

			// Colombia Gateways
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-baloto-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-eft-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-credit-card-co-gateway.php';

			// Peru Gateways
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-pagoefectivo-gateway.php';
			include_once WC_EBANX_GATEWAYS_DIR . 'class-wc-ebanx-safetypay-gateway.php';

			// Hooks/Actions
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-payment-by-link.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-payment-validator.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-my-account.php';
			include_once WC_EBANX_SERVICES_DIR . 'class-wc-ebanx-one-click.php';

			// Controllers
			include_once WC_EBANX_CONTROLLERS_DIR . 'class-wc-ebanx-api-controller.php';

			// Exceptions
			include_once WC_EBANX_EXCEPTIONS_DIR . 'class-wc-ebanx-payment-exception.php';
		}

		/**
		 * Get templates path.
		 *
		 * @return string
		 */
		public static function get_templates_path()
		{
			return WC_EBANX_TEMPLATES_DIR;
		}

		/**
		 * Add the gateways to WooCommerce.
		 *
		 * @param  array $methods WooCommerce payment methods.
		 *
		 * @return array
		 */
		public function add_gateway($methods)
		{
			// Global
			$methods[] = 'WC_EBANX_Global_Gateway';

			// Brazil
			$methods[] = 'WC_EBANX_Banking_Ticket_Gateway';
			$methods[] = 'WC_EBANX_Credit_Card_BR_Gateway';
			$methods[] = 'WC_EBANX_Tef_Gateway';
			$methods[] = 'WC_EBANX_Account_Gateway';

			// Mexico
			$methods[] = 'WC_EBANX_Credit_Card_MX_Gateway';
			$methods[] = 'WC_EBANX_Debit_Card_Gateway';
			$methods[] = 'WC_EBANX_Oxxo_Gateway';
			$methods[] = 'WC_EBANX_Spei_Gateway';

			// Chile
			$methods[] = 'WC_EBANX_Webpay_Gateway';
			$methods[] = 'WC_EBANX_Multicaja_Gateway';
			$methods[] = 'WC_EBANX_Sencillito_Gateway';
			$methods[] = 'WC_EBANX_Servipag_Gateway';

			// Colombia
			$methods[] = 'WC_EBANX_Credit_Card_CO_Gateway';
			$methods[] = 'WC_EBANX_Baloto_Gateway';
			$methods[] = 'WC_EBANX_Eft_Gateway';

			// Peru
			$methods[] = 'WC_EBANX_Pagoefectivo_Gateway';
			$methods[] = 'WC_EBANX_Safetypay_Gateway';

			// Argentina
			$methods[] = 'WC_EBANX_Efectivo_Gateway';

			return $methods;
		}

		/**
		 * Action links.
		 *
		 * @param  array $links Plugin links.
		 *
		 * @return array
		 */
		public function plugin_action_links($links)
		{
			$plugin_links = array();

			$ebanx_global = 'ebanx-global';

			$plugin_links[] = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $ebanx_global)) . '">' . __('Settings', 'woocommerce-gateway-ebanx') . '</a>';

			return array_merge($plugin_links, $links);
		}

		/**
		 * WooCommerce fallback notice.
		 */
		public function woocommerce_missing_notice()
		{
			// TODO: Others notice here
			include_once WC_EBANX_TEMPLATES_DIR . 'views/html-notice-missing-woocommerce.php';
		}

		/**
		 * Log messages
		 *
		 * @param  string $message The log message
		 * @return void
		 */
		public static function log($message)
		{
			$configs = new WC_EBANX_Global_Gateway();

			if ($configs->settings['debug_enabled'] !== 'yes') return;

			if (empty(self::$log)) self::$log = new WC_Logger();

			self::$log->add('woocommerce-gateway-ebanx', $message);

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log($message);
			}
		}

		/**
		 * It inserts a EBANX Settings shortcut on Wordpress sidebar
		 *
		 * @return void
		 */
		public function ebanx_sidebar_shortcut()
		{
			add_menu_page(
				'EBANX Settings',
				'EBANX Settings',
				'administrator',

				// TODO: Create a dynamic url
				WC_EBANX_Constants::SETTINGS_URL,
				'',
				WC_EBANX_Assets::get_logo(),
				21
			);
		}

		/**
		 * Checks if this post is an EBANX Order and call WC_EBANX_Payment_By_Link
		 *
		 * @param  int 	  $post_id The post id
		 * @return void
		 */
		public function ebanx_metabox_payment_link_save ($post_id) {
			$order = wc_get_order( $post_id );
			if( is_a( $order, 'WC_Order' ) ) {
				$checkout_url = get_post_meta($order->get_id(), '_ebanx_checkout_url', true);
			} else {
				$checkout_url = '';
			}

			// Check if is an EBANX request
			if ( WC_EBANX_Request::has('create_ebanx_payment_link')
				&& WC_EBANX_Request::read('create_ebanx_payment_link') === __('Create EBANX Payment Link', 'woocommerce-gateway-ebanx')
				&& ! $checkout_url ) {

				$this->setup_configs();
				$config = array(
					'integrationKey' => $this->private_key,
					'testMode'       => $this->is_sandbox_mode,
				);

				WC_EBANX_Payment_By_Link::create($post_id, $config);
			}
			return;
		}

		/**
		 * Checks if the button can be renderized and renders it
		 *
		 * @param  int   $post_id The post id
		 * @return void
		 */
		public function ebanx_metabox_save_post_render_button ($post_id) {
			$ebanx_currencies = array('BRL', 'USD', 'EUR', 'PEN', 'CLP', 'MXN', 'COP');
			$order = wc_get_order($post_id);
			$checkout_url = get_post_meta($order->get_id(), '_ebanx_checkout_url', true);

			if ( !$checkout_url
				&& in_array($order->get_status(), array('auto-draft', 'pending'))
				&& in_array(strtoupper(get_woocommerce_currency()), $ebanx_currencies) ) {
				wc_get_template(
					'payment-by-link-action.php',
					array(),
					'woocommerce/ebanx/',
					WC_EBANX::get_templates_path()
				);
			}
		}

		/**
		 * It inserts informations about the order on admin order details
		 *
		 * @param  WC_Object $order The WC order object
		 * @return void
		 */
		public function ebanx_admin_order_details ($order) {
			$payment_hash = get_post_meta($order->get_id(), '_ebanx_payment_hash', true);
			if ($payment_hash) {

				wc_get_template(
					'admin-order-details.php',
					array(
						'order' => $order,
						'payment_hash' => $payment_hash,
						'payment_checkout_url' => get_post_meta($order->get_id(), '_ebanx_checkout_url', true),
						'is_sandbox_mode' => $this->is_sandbox_mode,
						'dashboard_link' => "https://dashboard.ebanx.com/" . ($this->is_sandbox_mode ? 'test/' : '') . "payments/?hash=$payment_hash"
					),
					'woocommerce/ebanx/',
					WC_EBANX::get_templates_path()
				);
			}
		}
	}

	add_action('plugins_loaded', array('WC_EBANX', 'get_instance'));
}