<?php
/*
 * Plugin Name: Intend Payment Plugin
 * Plugin URI:  https://github.com/akbarali1/intend-pay-wordpress-woocommerce
 * Description: Intend Checkout Plugin for WooCommerce
 * Version: 0.6
 * Author: Akbarali
 * Author URI: https://github.com/akbarali1
 * Text Domain: intend
 * Telegram: @akbar_aka
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
require('core/intend.php');

add_action('plugins_loaded', 'woocommerce_intend', 0);

function callbackIntend()
{
    $all_request = json_decode(file_get_contents('php://input'), true);
    $api         = new WC_INTEND();
    $order_id    = $all_request['order_id'];
    $api_key     = $all_request['api_key'];
    $status      = $all_request['status'];
    $message     = $all_request['message'];
    $order       = wc_get_order($order_id);

    if ($api_key !== $api->api_key) {
        return wp_send_json_error('Invalid API Key');
    }
    if (!isset($order)) {
        return wp_send_json_error('Invalid Order');
    }
    $status_all = [
        0 => 'wc-pending',
        1 => 'wc-on-hold',
        2 => 'wc-processing',
        3 => 'wc-failed',
    ];
    if (isset($status_all[$status]) && $status_all[$status] === 'wc-processing') {
        $order->payment_complete();
        $order->add_order_note($message ?? 'Intend payment successful');
        $order->update_status($status_all[$status]);
        $order->save();

        return wp_send_json_success('Payment successful');
    } else {
        $order->add_order_note($message ?? 'Intend payment failed');
        $order->update_status('failed');
        $order->save();

        return wp_send_json_error('Payment failed');
    }
}

function webhookRedirect()
{
    $order_id = $_GET['order_id'];
    $order    = wc_get_order($order_id);
    if (!isset($order)) {
        return wp_send_json_error('Invalid Order');
    }
    if ($order->get_status() === 'processing') {
        wp_redirect($order->get_checkout_order_received_url());
        die();
    } else {
        wp_redirect($order->get_cancel_order_url());
        die();
    }

}

add_action('rest_api_init', function () {
    register_rest_route('intend-pay/v1', 'check-order', [
        'methods'  => 'GET',
        'callback' => 'webhookRedirect',
    ]);
});

add_action('rest_api_init', function () {
    register_rest_route('intend-pay/v1', 'callback', [
        'methods'  => 'POST',
        'callback' => 'callbackIntend',
    ]);
});

function woocommerce_intend()
{
    load_plugin_textdomain('intend', false, dirname(plugin_basename(__FILE__)).'/lang/');

    // Do nothing, if WooCommerce is not available
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // Do not re-declare class
    if (class_exists('WC_INTEND')) {
        return;
    }

    class WC_INTEND extends WC_Payment_Gateway
    {
        public $api_key;
        //        protected $checkout_url;
        //        protected $return_url;

        public function __construct()
        {
            $plugin_dir        = plugin_dir_url(__FILE__);
            $this->api_key = $this->get_option('api_key');
            $this->id          = 'intend';
            $this->icon        = apply_filters('woocommerce_intend_icon', ''.$plugin_dir.'intend.png');
            $this->has_fields  = false;
            
            $this->init_form_fields();
            $this->init_settings();
            
            $this->title       = $this->fetch_itend_price();
            // Populate options from the saved settings
            $this->api_key = $this->get_option('api_key');
            //            $this->checkout_url = $this->get_option('checkout_url');
            //            $this->return_url = $this->get_option('return_url');

            add_action('woocommerce_receipt_'.$this->id, [$this, 'receipt_page']);
            add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_wc_'.$this->id, [$this, 'callback']);
            add_action('wp_enqueue_scripts', array($this, 'enqueue_intend_scripts'));
        }

        function showMessage($content)
        {
            return '<h1>'.$this->msg['title'].'</h1><div class="box '.$this->msg['class'].'-box">'.$this->msg['message'].'</div>';
        }

        function showTitle($title)
        {
            return false;
        }

        public function admin_options()
        {
            ?>
            <h3><?php
                _e('Intend', 'intend'); ?></h3>

            <p><?php
                _e('Configure checkout settings', 'intend'); ?></p>

            <!--            <p>-->
            <!--                <strong>--><?php
            //_e('Your Web Cash Endpoint URL to handle requests is:', 'intend');
            ?><!--</strong>-->
            <!--                <em>--><?//= site_url('/?wc-api=wc_intend');
            ?><!--</em>-->
            <!--            </p>-->

            <table class="form-table">
                <?php
                $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __('Enable/Disable', 'intend'),
                    'type'    => 'checkbox',
                    'label'   => __('Enabled', 'intend'),
                    'default' => 'yes',
                ],
                'api_key' => [
                    'title'       => __('API KEY', 'intend'),
                    'type'        => 'text',
                    'description' => __('Intend.uz tomonidan berilgan API KEYni kiriting.', 'intend'),
                    'default'     => '',
                ],
            ];
        }

        public function generate_form($order_id)
        {
            // get order by id
            $order = wc_get_order($order_id);
            // Get and Loop Over Order Items

            // convert an amount to the coins (Intend accepts only coins)
            $sum = $order->get_total() * 100;

            // format the amount
            $sum = number_format($sum, 0, '.', '');

            $lang_codes = ['ru_RU' => 'ru', 'en_US' => 'en', 'uz_UZ' => 'uz'];
            $lang       = isset($lang_codes[get_locale()]) ? $lang_codes[get_locale()] : 'en';

            $label_pay    = __('Pay', 'intend');
            $label_cancel = __('Cancel payment and return back', 'intend');

            $callbackUrl = site_url().'/wp-json/intend-pay/v1/check-order/?order_id='.$order_id;

            $html_form = '';
            $i         = 0;
            foreach ($order->get_items() as $item_id => $item) {
                $i++;
                $html_form .= '<input type="hidden" name="products['.$i.'][id]" value="'.$item->get_product_id().'">';
                $html_form .= '<input type="hidden" name="products['.$i.'][name]" value="'.$item->get_name().'">';
                $html_form .= '<input type="hidden" name="products['.$i.'][price]" value="'.($item->get_total() / $item->get_quantity()).'">';
                $html_form .= '<input type="hidden" name="products['.$i.'][quantity]" value="'.$item->get_quantity().'">';
                $html_form .= '<input type="hidden" name="products['.$i.'][sku]" value="sku_'.$item->get_product_id().'">';
                $html_form .= '<input type="hidden" name="products['.$i.'][weight]" value="0">';
            }

            $form = <<<FORM
<form action="https://pay.intend.uz" method="POST" id="intend_form">
<input type="hidden" name="duration" value="12">
<input type="hidden" name="plugin_version" value="0.6">
<input type="hidden" name="order_id" value="$order_id">
<input type="hidden" name="api_key" value="$this->api_key ">
<input type="hidden" name="redirect_url" value="{$callbackUrl}">
{$html_form}
<hr />
<input type="submit" class="button alt" id="submit_intend_form" value="$label_pay">
<a class="button cancel" href="{$order->get_cancel_order_url()}">$label_cancel</a>
</form>
FORM;

            return $form;
        }

        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            return [
                'result'   => 'success',
                'redirect' => add_query_arg(
                    'order_pay',
                    $order->get_id(),
                    add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true))
                ),
            ];

        }

        public function receipt_page($order_id)
        {
            echo '<p>'.__('Thank you for your order, press "Pay" button to continue.', 'intend').'</p>';
            echo $this->generate_form($order_id);
        }

        /**
         * Endpoint method. This method handles requests from Paycom.
         */
        public function callback()
        {
            // Parse payload
            $payload = json_decode(file_get_contents('php://input'), true);

            if (json_last_error() !== JSON_ERROR_NONE) { // handle Parse error
                $this->respond($this->error_invalid_json());
            }

            // Authorize client
            $headers = getallheaders();

            $v                   = html_entity_decode($this->api_key);
            $encoded_credentials = base64_encode("Paycom:".$v);
            //$encoded_credentials = base64_encode("Paycom:{$this->merchant_key}");
            if (!$headers || // there is no headers
                !isset($headers['Authorization']) || // there is no Authorization
                !preg_match('/^\s*Basic\s+(\S+)\s*$/i', $headers['Authorization'], $matches) || // invalid Authorization value
                $matches[1] != $encoded_credentials // invalid credentials
            ) {
                $this->respond($this->error_authorization($payload));
            }

            // Execute appropriate method
            $response = method_exists($this, $payload['method'])
                ? $this->{$payload['method']}($payload)
                : $this->error_unknown_method($payload);

            // Respond with result
            $this->respond($response);
        }

        /**
         * Responds and terminates request processing.
         * @param array $response specified response
         */
        private function respond($response)
        {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }

            echo json_encode($response);
            die();
        }

        /**
         * Gets order instance by id.
         * @param array $payload request payload
         * @return WC_Order found order by id
         */
        private function get_order(array $payload)
        {
            try {
                return new WC_Order($payload['params']['account']['id']);
            } catch (Exception $ex) {
                $this->respond($this->error_order_id($payload));
            }
        }

        /**
         * Gets order instance by transaction id.
         * @param array $payload request payload
         * @return WC_Order found order by id
         */
        private function get_order_by_transaction($payload)
        {
            global $wpdb;

            try {
                $prepared_sql = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_value = '%s' AND meta_key = '_intend_transaction_id'", $payload['params']['id']);
                $order_id     = $wpdb->get_var($prepared_sql);

                return new WC_Order($order_id);
            } catch (Exception $ex) {
                $this->respond($this->error_transaction($payload));
            }
        }

        /**
         * Converts amount to coins.
         * @param float $amount amount value.
         * @return int Amount representation in coins.
         */
        private function amount_to_coin($amount)
        {
            return 100 * number_format($amount, 2, '.', '');
        }

        /**
         * Gets current timestamp in milliseconds.
         * @return float current timestamp in ms.
         */
        private function current_timestamp()
        {
            return round(microtime(true) * 1000);
        }

        /**
         * Get order's create time.
         * @param WC_Order $order order
         * @return float create time as timestamp
         */
        private function get_create_time(WC_Order $order)
        {
            return (double)get_post_meta($order->get_id(), '_intend_create_time', true);
        }

        /**
         * Get order's perform time.
         * @param WC_Order $order order
         * @return float perform time as timestamp
         */
        private function get_perform_time(WC_Order $order)
        {
            return (double)get_post_meta($order->get_id(), '_intend_perform_time', true);
        }

        /**
         * Get order's cancel time.
         * @param WC_Order $order order
         * @return float cancel time as timestamp
         */
        private function get_cancel_time(WC_Order $order)
        {
            return (double)get_post_meta($order->get_id(), '_intend_cancel_time', true);
        }

        /**
         * Get order's transaction id
         * @param WC_Order $order order
         * @return string saved transaction id
         */
        private function get_transaction_id(WC_Order $order)
        {
            return (string)get_post_meta($order->get_id(), '_intend_transaction_id', true);
        }

        private function get_cencel_reason(WC_Order $order)
        {
            $b_v = (int)get_post_meta($order->get_id(), '_cancel_reason', true);

            if ($b_v) {
                return $b_v;
            } else {
                return null;
            }
        }

        private function fetch_itend_price() {     
            if(isset(WC()->cart)) {
                $total_price_cart = WC()->cart->total;
                $api_key = $this->get_option('api_key'); 
    
                $url = "https://pay.intend.uz/api/v1/front/calculate?api_key=" . $api_key . "&price=" . $total_price_cart;

                $options = [
                    'http' => [
                        'method' => 'GET',
                        'header' => [
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ],
                    ],
                ];

                $context = stream_context_create($options);
                $response = file_get_contents($url, false, $context);

                if ($response !== false) {
                    $data = json_decode($response, true);

                    if ($data['success']) {
                        $per_month = number_format($data['data']['items'][0]['per_month'], 0, '.', ' ');
                        $description = '<span><span class="intend_price_checkout">' . $per_month . "</span> UZS per month</span>";
                    } else {
                        $description = 'Error while getting the price';
                    }
                } else {
                    $description = 'Something went wrong';
                }

                return __($description, 'intend');
            } else {
                return __('Intend', 'intend');
            }
        }

        public function enqueue_intend_scripts() {
            wp_enqueue_script('intend-script', plugins_url('/js/intend.js', __FILE__), array('jquery'), '1.0.0', true);
        }
    }

    function add_intend_gateway($methods)
    {
        $methods[] = 'WC_INTEND';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_intend_gateway');
}

/////////////// success page

add_filter('query_vars', 'intend_success_query_vars');
function intend_success_query_vars($query_vars)
{
    $query_vars[] = 'intend_success';
    $query_vars[] = 'id';

    return $query_vars;
}


add_action('parse_request', 'intend_success_parse_request');
function intend_success_parse_request(&$wp)
{
    if (array_key_exists('intend_success', $wp->query_vars)) {

        $order = new WC_Order($wp->query_vars['id']);

        $a = new WC_INTEND();
        add_action('the_title', [$a, 'showTitle']);
        add_action('the_content', [$a, 'showMessage']);

        if ($wp->query_vars['intend_success'] == 1) {

            if ($order->get_status() == "pending") {
                wp_redirect($order->get_cancel_order_url());
            } else {

                $a->msg['title']   = __('Intend successfully paid', 'intend');
                $a->msg['message'] = __('Thank you for your purchase!', 'intend');
                $a->msg['class']   = 'woocommerce_message woocommerce_message_info';
                WC()->cart->empty_cart();
            }

        } else {

            $a->msg['title']   = __('Intend not paid', 'intend');
            $a->msg['message'] = __('An error occurred during payment. Try again or contact your administrator.', 'intend');
            $a->msg['class']   = 'woocommerce_message woocommerce_message_info';
        }
    }

    return;
}

?>
