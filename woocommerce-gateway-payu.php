<?php

declare(strict_types=1);

/*
 * Plugin Name: WooCommerce PayU Gateway
 * Plugin URI: https://wordpress.org/plugins/woocommerce-gateway-payu/
 * Description: Accept payments using PayU
 * Author: PayU MEA
 * Author URI: https://southafrica.payu.com/
 * Version: 2.0.1
 * Requires at least: 6.5
 * Tested up to: 6.7
 * Requires PHP: 8.0
 * Requires PHP Architecture: 64 bits
 * Requires Plugins: woocommerce
 * WC requires at least: 7.4
 * WC tested up to: 9.7
 * Text Domain: woocommerce-gateway-payu
 * Domain Path: /plugins
 */

if (! defined('ABSPATH')) {
    exit;
}

class WC_PayU
{
    private static $instance;

    protected $payu_gateway = null;

    protected static $version = '2.0.1';

    public function __construct()
    {
        add_action('before_woocommerce_init', [$this, 'woocommerce_payu_declare_hpos_compatibility']);

        add_action('woocommerce_loaded', [$this, 'woocommerce_loaded'], 40);

        add_action('woocommerce_payment_gateways', [$this, 'add_gateways']);

        add_action('woocommerce_blocks_loaded', [$this, 'woocommerce_payu_blocks_support']);

        add_filter('pre_update_option_woocommerce_payu_settings', [$this, 'gateway_settings_update'], 10, 2);

        // Load translation files
        add_action('init', __CLASS__ . '::load_plugin_textdomain', 3);

        add_action('add_meta_boxes', [$this, 'add_meta_boxes'], 10, 2);

        add_action('woocommerce_before_thankyou', [$this, 'output_thankyou_notices']);

        add_filter(
            'plugin_action_links_' . plugin_basename(__FILE__),
            [
                $this,
                'woocommerce_payu_plugin_links'
            ]
        );

        // Add Admin Backend Actions
        add_action(
            'wp_ajax_payu_gateway_capture',
            [
                $this,
                'ajax_payu_gateway_capture'
            ]
        );

        add_action(
            'wp_ajax_payu_gateway_cancel',
            array(
                $this,
                'ajax_payu_gateway_cancel'
            )
        );

        add_action(
            'wp_ajax_payu_gateway_refund',
            array(
                $this,
                'ajax_payu_gateway_refund'
            )
        );

        add_action(
            'wp_ajax_payu_gateway_capture_partly',
            array(
                $this,
                'ajax_payu_gateway_capture_partly'
            )
        );

        add_action(
            'wp_ajax_payu_gateway_refund_partly',
            array(
                $this,
                'ajax_payu_gateway_refund'
            )
        );

        if (is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
            add_action('admin_notices', [$this, 'currency_setting_notice']);
        }
    }

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return WC_PayU The *Singleton* instance.
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Called on plugins_loaded to load any translation files.
     *
     * @since 2.0.1
     */
    public static function load_plugin_textdomain()
    {
        $plugin_rel_path = apply_filters('woocommerce_payu_translation_file_rel_path', dirname(plugin_basename(__FILE__)) . '/languages');
        load_plugin_textdomain('woocommerce-gateway-payu', false, $plugin_rel_path);
    }

    private function load_classes()
    {
        require_once dirname(__FILE__) . '/includes/exceptions/empty-log-string-exception.php';
        require_once dirname(__FILE__) . '/includes/exceptions/invalid-payment-method-exception.php';
        require_once dirname(__FILE__) . '/includes/exceptions/currency-mismatch-exception.php';
        require_once dirname(__FILE__) . '/includes/exceptions/payu-transaction-exception.php';

        require_once dirname(__FILE__) . '/includes/constants/class-wc-payu-payment-methods.php';

        require_once dirname(__FILE__) . '/includes/class-wc-payu-mode.php';
        require_once dirname(__FILE__) . '/includes/class-wc-payu-helper.php';
        require_once dirname(__FILE__) . '/includes/class-wc-payu-currency.php';
        require_once dirname(__FILE__) . '/includes/class-wc-payu-utils.php';
        require_once dirname(__FILE__) . '/includes/class-wc-payu-xml-parser.php';
        require_once dirname(__FILE__) . '/includes/class-wc-gateway-payu.php';

        require_once dirname(__FILE__) . '/includes/classes/class-payu-payment-base.php';
        require_once dirname(__FILE__) . '/includes/classes/class-payu-payment-transaction.php';

        require_once dirname(__FILE__) . '/includes/payment-methods/class-wc-payu-payment-method.php';
        require_once dirname(__FILE__) . '/includes/payment-methods/class-wc-payu-payment-method-card.php';
        require_once dirname(__FILE__) . '/includes/payment-methods/class-wc-payu-payment-method-dm.php';
    }

    /**
     * Declare blocks support
     */
    public function woocommerce_payu_blocks_support()
    {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            require_once dirname(__FILE__) . '/includes/blocks/abstract-class-wc-gateway-payu-block-support.php';
            require_once dirname(__FILE__) . '/includes/blocks/class-wc-gateway-payu-card-block-support.php';
            require_once dirname(__FILE__) . '/includes/blocks/class-wc-gateway-payu-dm-block-support.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new WC_PayU_Card_Block_Support());
                    $payment_method_registry->register(new WC_PayU_Dm_Block_Support());
                }
            );
        }
    }

    /**
     * Returns the main PayU payment gateway class instance.
     *
     * @return WC_Gateway_PayU
     */
    public function get_main_gateway()
    {
        if (! is_null($this->payu_gateway)) {
            return $this->payu_gateway;
        }

        $this->payu_gateway = new WC_Gateway_PayU();

        return $this->payu_gateway;
    }

    /**
     * Declare HPOS support
     */
    public function woocommerce_payu_declare_hpos_compatibility()
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * WooCommerce Loaded: load classes
     * @return void
     */
    public function woocommerce_loaded()
    {
        $this->load_classes();
    }

    public function add_gateways($methods)
    {
        $main_gateway = $this->get_main_gateway();

        if (!is_checkout()) {
            $methods[] = $main_gateway;
        }

        // Card and Discovery Miles are configured inside "PayU Secure Payments",
        // so they must not be listed as separate gateways on the Payments settings screens.
        if ($this->is_payments_settings_screen()) {
            return $methods;
        }

        return array_merge($methods, $main_gateway->payment_methods);
    }

    /**
     * Whether the current request is the WooCommerce Payments settings tab
     * (the gateway list or any gateway's settings section).
     *
     * @return bool
     */
    private function is_payments_settings_screen()
    {
        return is_admin()
            && isset($_GET['page'], $_GET['tab'])
            && 'wc-settings' === sanitize_key(wp_unslash($_GET['page']))
            && 'checkout' === sanitize_key(wp_unslash($_GET['tab']));
    }

    /**
     * Prints notices queued by the PayU return callback on the order received page.
     *
     * WooCommerce does not print notices on the thank-you page, and Storefront skips
     * them on checkout pages, so they would otherwise only appear on the next page load.
     *
     * @param int $order_id The order being confirmed.
     * @return void
     */
    public function output_thankyou_notices($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order || 0 !== strpos($order->get_payment_method(), WC_Gateway_PayU::ID)) {
            return;
        }

        woocommerce_output_all_notices();
    }

    /**
     * Returns the URL of the PayU gateway settings screen.
     *
     * @return string The settings URL.
     */
    public function get_settings_url()
    {
        return add_query_arg(
            [
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => 'wc_gateway_payu',
            ],
            admin_url('admin.php')
        );
    }

    /**
     * Warns when the stored currency setting can no longer be used.
     *
     * The setting is validated when it is saved, but the store currency can be
     * changed afterwards, so the stored value is re-checked on every admin screen
     * instead of waiting for PayU to decline the next transaction.
     */
    public function currency_setting_notice()
    {
        if (! current_user_can('manage_woocommerce') || ! class_exists('WC_Gateway_PayU')) {
            return;
        }

        $settings = WC_PayU_Helper::get_payu_settings();

        if (empty($settings['enabled']) || 'yes' !== $settings['enabled']) {
            return;
        }

        try {
            $this->get_main_gateway()->get_currency_code();
        } catch (CurrencyMismatchException $e) {
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
                esc_html__('WooCommerce PayU Gateway:', 'woocommerce-gateway-payu'),
                esc_html($e->getMessage()),
                esc_url($this->get_settings_url()),
                esc_html__('Review the currency setting', 'woocommerce-gateway-payu')
            );
        }
    }

    /**
     * Add links to plugin description
     *
     * @param array $links
     * @return array
     */
    public function woocommerce_payu_plugin_links($links)
    {
        $settings_url = $this->get_settings_url();

        $plugin_links = [
            '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'woocommerce-gateway-payu') . '</a>',
            '<a href="https://wordpress.org/support/plugin/woocommerce-gateway-payu/">' . esc_html__('Support', 'woocommerce-gateway-payu') . '</a>',
            '<a href="https://wordpress.org/plugins/woocommerce-gateway-payu/#description">' . esc_html__('Docs', 'woocommerce-gateway-payu') . '</a>',
        ];

        return array_merge($plugin_links, $links);
    }

    /**
     * Provide default values for missing settings on initial gateway settings save.
     *
     * @since 2.0.1
     * @version 2.0.2
     *
     * @param array $settings New settings to save.
     * @param array|bool $old_settings Existing settings, if any.
     * @return array New value but with defaults initially filled in for missing settings.
     */
    public function gateway_settings_update($settings, $old_settings)
    {
        if (false === $old_settings) {
            $gateway = new WC_Gateway_PayU();
            $fields = $gateway->get_form_fields();
            $old_settings = array_merge(array_fill_keys(array_keys($fields), ''), wp_list_pluck($fields, 'default'));
            $settings = array_merge($old_settings, $settings);
        }

        return $settings;
    }

    /**
     * beGateway metabox
     *
     * @param Object $screen The current screen object.
     * @param WC_Order|WP_Post $order The current post/order object.
     * @return void
     */
    public function add_meta_boxes($screen, $order)
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order->ID);
        if (!($order instanceof \WC_Order)) {
            return;
        }

        $payment_method = $order->get_payment_method();
        $payment_gateways = WC()->payment_gateways->payment_gateways();

        if (!isset($payment_gateways[$payment_method])) {
            return;
        }

        $screen = WC_PayU_Utils::get_edit_order_screen_id();

        $post_types = apply_filters('woocommerce_payu_admin_meta_box_post_types', array($screen));

        foreach ($post_types as $post_type) {
            add_meta_box(
                'payu-gateway-payment-actions',
                __('PayU Transactions', 'woocommerce-gateway-payu'),
                [
                    &$this,
                    'meta_box_payment',
                ],
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * PayU metabox content.
     *
     * @param WC_Order|WP_Post $object The current post/order object.
     * @return void
     */
    public function meta_box_payment($object)
    {
        $order = $object instanceof WC_Order ? $object : wc_get_order($object->ID);

        if (!($order instanceof \WC_Order)) {
            return;
        }

        $payment_method = $order->get_payment_method();
        $payment_gateways = WC()->payment_gateways->payment_gateways();


        if (isset($payment_gateways[$payment_method])) {

            do_action('woocommerce_payu_meta_box_payment_before_content', $order);

            // Get Payment Gateway
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();

            /** @var WC_Gateway_PayU $gateway */
            $gateway = $gateways[$payment_method];

            try {
                wc_get_template(
                    'admin/metabox-order.php',
                    [
                        'gateway' => $gateway,
                        'order' => $order,
                        'order_id' => $order->get_id(),
                        'order_data' => $gateway->get_invoice_data($order)
                    ],
                    '',
                    WC_PayU::plugin_abspath() . 'templates/'
                );
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Returns the edit order's screen id.
     *
     * Takes into consideration if HPOS is enabled or not.
     *
     * @return string
     */
    public static function get_edit_order_screen_id()
    {
        if (!function_exists('wc_get_container') || !class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            return 'shop_order';
        }

        return wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';
    }

    /**
     * Enqueue Scripts in admin
     *
     * @param $hook
     *
     * @return void
     */
    public function admin_enqueue_scripts($hook)
    {
        // Scripts
        wp_register_script(
            'payu-admin-js',
            plugin_dir_url(__FILE__) . 'js/admin.js',
            [
                'jquery'
            ]
        );
        // Version by modification time so browsers pick up stylesheet changes immediately.
        wp_enqueue_style(
            'woocommerce-gateway-payu',
            plugins_url('/css/style.css', __FILE__),
            [],
            (string) filemtime(plugin_dir_path(__FILE__) . 'css/style.css'),
            'all'
        );

        // Localize the script
        $translation_array = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'text_wait' => __('Please wait...', 'woocommerce-begateway-payu'),
        ];
        wp_localize_script('payu-admin-js', 'PayU_Gateway_Admin', $translation_array);

        // Enqueued script with localized data
        wp_enqueue_script('payu-admin-js');
    }

    /**
     * Action for Capture
     */
    public function ajax_payu_gateway_capture()
    {
        if (!wp_verify_nonce($_REQUEST['nonce'], 'payu')) {
            exit('Invalid nonce');
        }

        $order_id = (int) sanitize_text_field($_REQUEST['order_id']);
        $order = wc_get_order($order_id);

        // Get Payment Gateway
        $payment_method = $order->get_payment_method();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        /** @var WC_PayU_Payment_Method $gateway */
        $gateway = $gateways[$payment_method];
        $result = $gateway->capture_payment($order_id, $order->get_total());

        if (!is_wp_error($result)) {
            wp_send_json_success(__('Capture success', 'woocommerce-gateway-payu'));
        } else {
            wp_send_json_error($result->get_error_message());
        }
    }

    /**
     * Action for Cancel
     */
    public function ajax_payu_gateway_cancel()
    {
        if (!wp_verify_nonce($_REQUEST['nonce'], 'payu')) {
            exit('Invalid nonce');
        }

        $order_id = (int) sanitize_text_field($_REQUEST['order_id']);
        $order = wc_get_order($order_id);

        //
        // Check if the order is already cancelled
        // ensure no more actions are made
        //
        if ($order->get_meta('_payu_transaction_voided', true) === "yes") {
            wp_send_json_success(__('Order already cancelled', 'woocommerce-gateway-payu'));
            return;
        }

        // Get Payment Gateway
        $payment_method = $order->get_payment_method();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        /** @var WC_PayU_Payment_Method $gateway */
        $gateway = $gateways[$payment_method];

        $result = $gateway->cancel_payment($order_id, $order->get_total());

        if (!is_wp_error($result)) {
            wp_send_json_success(__('Cancel success', 'woocommerce-gateway-payu'));
        } else {
            wp_send_json_error($result->get_error_message());
        }
    }

    /**
     * Action for Cancel
     */
    public function ajax_payu_gateway_refund()
    {
        if (!wp_verify_nonce($_REQUEST['nonce'], 'payu')) {
            exit('Invalid nonce');
        }

        $amount = sanitize_text_field($_REQUEST['amount']);
        $order_id = (int) sanitize_text_field($_REQUEST['order_id']);
        $order = wc_get_order($order_id);

        $amount = str_replace(",", ".", $amount);
        $amount = floatval($amount);

        $payment_method = $order->get_payment_method();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        /** @var WC_PayU_Payment_Method $gateway */
        $gateway = $gateways[$payment_method];
        $result = $gateway->refund_payment($order_id, $amount);

        if (!is_wp_error($result)) {
            wp_send_json_success(__('Refund success', 'woocommerce-gateway-payu'));
        } else {
            wp_send_json_error($result->get_error_message());
        }
    }

    /**
     * Action for partial capture
     */
    public function ajax_begateway_capture_partly()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'payu')) {
            exit('Invalid nonce');
        }

        $amount = sanitize_text_field($_REQUEST['amount']);
        $order_id = (int) sanitize_text_field($_REQUEST['order_id']);
        $order = wc_get_order($order_id);

        $amount = str_replace(",", ".", $amount);
        $amount = floatval($amount);

        // Get Payment Gateway
        $payment_method = $order->get_payment_method();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        /** @var WC_PayU_Payment_Method $gateway */
        $gateway = $gateways[$payment_method];
        $result = $gateway->capture_payment($order_id, $amount);

        if (!is_wp_error($result)) {
            wp_send_json_success(__('Capture partly success', 'woocommerce-gateway-payu'));
        } else {
            wp_send_json_error($result->get_error_message());
        }
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_url()
    {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_abspath()
    {
        return trailingslashit(plugin_dir_path(__FILE__));
    }

    /**
     * Plugin version.
     *
     * @return string
     */
    public static function plugin_version()
    {
        return self::$version;
    }
}

// Use the singleton so the constructor's hooks are registered once, not again on the first get_instance() call.
WC_PayU::get_instance();
