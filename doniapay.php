<?php
/*
 * Plugin Name: Doniapay 
 * Description: This plugin allows your customers to pay with Bkash, Nagad, Rocket, and all BD gateways via doniapay.
 * Author: DabdCoder
 * Author URI: https://github.com/Doniapay
 * Version: 1.0.0
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * License: GPL v2 or later
 * Text Domain: doniapay
 */

add_action('plugins_loaded', 'doniapay_init_gateway_class');

function doniapay_init_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_doniapay_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'doniapay';
            $this->icon = 'https://doniapay.com/public/uploads/user/6d93f2a0e5f0fe2cc3a6e9e3ade964b43b07f897/1748836843_e6eb753f29e09bb26089.png';
            $this->has_fields = false;
            $this->method_title = __('doniapay', 'doniapay');
            $this->method_description = __('Pay With doniapay', 'doniapay');

            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_doniapay_gateway', array($this, 'handle_webhook'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable doniapay',
                    'type'        => 'checkbox',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'default'     => 'doniapay Gateway',
                ),
                'apikeys' => array(
                    'title'       => 'Enter API Key',
                    'type'        => 'text',
                    'default'     => '',
                ),
                'currency_rate' => array(
                    'title'       => 'Enter USD Rate',
                    'type'        => 'number',
                    'default'     => '110',
                ),
                'is_digital' => array(
                    'title'       => 'Enable/Disable Digital product',
                    'label'       => 'Enable Digital product',
                    'type'        => 'checkbox',
                    'default'     => 'no'
                ),
                'payment_site' => array(
                    'title'             => 'Payment Site URL',
                    'type'              => 'text',
                    'default'           => 'https://api.doniapay.com/v2/order/synchronize',
                    'custom_attributes' => array(
                        'readonly' => 'readonly'
                    ),
                ),
            );
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = wc_get_order($order_id);

            $total = $order->get_total();

            if ($order->get_currency() == 'USD') {
                $total = $total * $this->get_option('currency_rate');
            }

            if ($order->get_status() != 'completed') {
                $order->update_status('pending', __('Customer is being redirected to doniapay', 'doniapay'));
            }

            $raw_data = array(
                "dn_su"      => add_query_arg(array('wc-api' => 'WC_doniapay_Gateway', 'order_id' => $order_id, 'type' => 'success'), home_url('/')),
                "dn_cu"      => $order->get_cancel_order_url(),
                "dn_wu"      => add_query_arg(array('wc-api' => 'WC_doniapay_Gateway', 'order_id' => $order_id, 'type' => 'webhook'), home_url('/')),
                "dn_am"      => (string)round($total, 2),
                "dn_cn"      => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
                "dn_ce"      => $order->get_billing_email(),
                "dn_mt"      => json_encode(array("order_id" => $order_id)),
                "dn_rt"      => "GET"
            );

            $payload = base64_encode(json_encode($raw_data));
            $api_key = $this->get_option('apikeys');
            $signature = hash_hmac('sha256', $payload, $api_key);

            $header = array(
                "api"       => $api_key,
                "signature" => $signature,
                "url"       => $this->get_option('payment_site') . "/prepare"
            );

            $response = $this->create_payment(array('dp_payload' => $payload), $header);
            $data = json_decode($response, true);

            if (isset($data['status']) && in_array($data['status'], ['success', 1])) {
                return array(
                    'result'   => 'success',
                    'redirect' => $data['payment_url']
                );
            } else {
                wc_add_notice(__('Initialization Error: ', 'doniapay') . ($data['message'] ?? 'Unknown error'), 'error');
                return;
            }
        }

        public function create_payment($data = "", $header = '')
        {
            $headers = array(
                'Content-Type: application/json',
                'X-Signature-Key: ' . $header['api']
            );

            if (isset($header['signature'])) {
                $headers[] = 'donia-signature: ' . $header['signature'];
            }

            $url = $header['url'];
            $curl = curl_init();
            $post_data = json_encode($data);

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_HTTPHEADER => $headers,
            ));
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($curl);
            curl_close($curl);
            return $response;
        }

        public function update_order_status($order)
        {
            $transactionId = isset($_REQUEST['ids']) ? sanitize_text_field($_REQUEST['ids']) : (isset($_REQUEST['transactionId']) ? sanitize_text_field($_REQUEST['transactionId']) : '');
            
            $data = array(
                "transaction_id" => $transactionId,
            );

            $header = array(
                "api" => $this->get_option('apikeys'),
                "url" => $this->get_option('payment_site') . "/confirm"
            );

            $response = $this->create_payment($data, $header);
            $data = json_decode($response, true);

            if ($order->get_status() != 'completed') {
                if (isset($data['status']) && in_array($data['status'], ['Paid', 'COMPLETED', 1, 'success'])) {
                    $transaction_id = $transactionId;
                    $amount = $data['amount'] ?? '';
                    
                    if ($this->get_option('is_digital') === 'yes') {
                        $order->update_status('completed', sprintf(__('Doniapay payment successful. Amount: %s, Trx ID: %s', 'doniapay'), $amount, $transaction_id));
                    } else {
                        $order->update_status('processing', sprintf(__('Doniapay payment successful. Amount: %s, Trx ID: %s', 'doniapay'), $amount, $transaction_id));
                    }
                    $order->payment_complete($transaction_id);
                    $order->reduce_order_stock();
                    return true;
                } else {
                    $order->update_status('on-hold', __('Awaiting Doniapay payment verification.', 'doniapay'));
                    return false;
                }
            }
        }

        public function handle_webhook()
        {
            $order_id = isset($_GET['order_id']) ? sanitize_text_field($_GET['order_id']) : '';
            $order = wc_get_order($order_id);

            if ($order) {
                $this->update_order_status($order);
            }

            if (isset($_GET['type']) && $_GET['type'] == 'success') {
                wp_redirect($this->get_return_url($order));
                exit;
            }

            status_header(200);
            echo json_encode(['message' => 'Processed']);
            exit();
        }
    }

    add_filter('woocommerce_payment_gateways', 'doniapay_add_gateway_class');
    function doniapay_add_gateway_class($gateways)
    {
        $gateways[] = 'WC_doniapay_Gateway';
        return $gateways;
    }
}

function doniapay_handle_rest_webhook($request)
{
    $params = $request->get_params();
    $transactionId = isset($params['transactionId']) ? sanitize_text_field($params['transactionId']) : (isset($params['ids']) ? sanitize_text_field($params['ids']) : '');
    $order_id = isset($_GET['success1']) ? sanitize_text_field($_GET['success1']) : (isset($_GET['order_id']) ? sanitize_text_field($_GET['order_id']) : '');

    if (!$transactionId || !$order_id) {
        return new WP_REST_Response(['message' => 'Missing Data'], 400);
    }

    $api_key = get_option('woocommerce_doniapay_settings')['apikeys'];
    $payment_site = get_option('woocommerce_doniapay_settings')['payment_site'];

    $headers = array(
        'Content-Type: application/json',
        'X-Signature-Key: ' . $api_key
    );

    $post_data = json_encode(array("transaction_id" => $transactionId));
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $payment_site . "/confirm",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $post_data,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    $data = json_decode($response, true);

    if (isset($data['status']) && in_array($data['status'], ['Paid', 'COMPLETED', 1, 'success'])) {
        $order = wc_get_order($order_id);
        if ($order && $order->get_status() != 'completed') {
            $order->payment_complete($transactionId);
            $order->reduce_order_stock();
            $order->update_status('completed', __('Confirmed via REST Webhook', 'doniapay'));
        }
    }

    return new WP_REST_Response(['message' => 'Webhook Processed'], 200);
}

add_action('rest_api_init', function () {
    register_rest_route('doniapay/v1', '/webhook', array(
        'methods' => 'POST',
        'callback' => 'doniapay_handle_rest_webhook',
        'permission_callback' => '__return_true'
    ));
});
