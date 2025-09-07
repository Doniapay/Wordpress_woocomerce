<?php

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
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
            $this->icon = 'https://doniapay.com/public/assets/img/logo.png';
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
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'handle_webhook'));
        }

        public function init_form_fields()
{
    $this->form_fields = array(
        'enabled' => array(
            'title'       => 'Enable/Disable',
            'label'       => 'Enable doniapay',
            'type'        => 'checkbox',
            'description' => '',
            'default'     => 'no'
        ),
        'title' => array(
            'title'       => 'Title',
            'type'        => 'text',
            'description' => 'This controls the title which the user sees during checkout.',
            'default'     => 'doniapay Gateway',
            'desc_tip'    => true,
        ),
        'apikeys' => array(
            'title'       => 'Enter API Key',
            'type'        => 'text',
            'description' => '',
            'default'     => '###################',
            'desc_tip'    => true,
        ),
        'currency_rate' => array(
            'title'       => 'Enter USD Rate',
            'type'        => 'number',
            'description' => '',
            'default'     => '110',
            'desc_tip'    => true,
        ),
        'is_digital' => array(
            'title'       => 'Enable/Disable Digital product',
            'label'       => 'Enable Digital product',
            'type'        => 'checkbox',
            'description' => '',
            'default'     => 'no'
        ),
        'payment_site' => array(
            'title'             => 'Payment Site URL',
            'type'              => 'text',
            'description'       => '',
            'default'           => 'https://payment.doniapay.com/',
            'desc_tip'          => true,
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
            $current_user = wp_get_current_user();

            $subtotal = WC()->cart->subtotal;
            $shipping_total = WC()->cart->get_shipping_total();
            $fees = WC()->cart->get_fee_total();
            $discount_excl_tax_total = WC()->cart->get_cart_discount_total();
            $discount_tax_total = WC()->cart->get_cart_discount_tax_total();
            $discount_total = $discount_excl_tax_total + $discount_tax_total;
            $total = $subtotal + $shipping_total + $fees - $discount_total;

            if ($order->get_currency() == 'USD') {
                $total = $total * $this->get_option('currency_rate');
            }

            if ($order->get_status() != 'completed') {
                $order->update_status('pending', __('Customer is being redirected to doniapay', 'doniapay'));
            }

            $data = array(
                "cus_name"    => $current_user->user_firstname,
                "cus_email"   => $current_user->user_email,
                "amount"      => $total,
                "webhook_url" => site_url('/?wc-api=wc_doniapay_gateway&order_id=' . $order->get_id()),
                "success_url" => $this->get_return_url($order),
                "cancel_url"  => wc_get_checkout_url()
            );

            $header = array(
                "api" => $this->get_option('apikeys'),
                "url" => $this->get_option('payment_site') . "api/payment/create"
            );

            $response = $this->create_payment($data, $header);

            $data = json_decode($response, true);

            return array(
                'result'   => 'success',
                'redirect' => $data['payment_url']
            );
        }

        public function create_payment($data = "", $header = '')
        {
            $headers = array(
                'Content-Type: application/json',
                'donia-apikey: ' . $header['api'],
            );
            $url = $header['url'];
            $curl = curl_init();
            $data = json_encode($data);

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_VERBOSE => true
            ));
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($curl);
            curl_close($curl);
            return $response;
        }

        public function update_order_status($order)
        {
            $transactionId = $_REQUEST['transactionId'];
            $data = array(
                "transaction_id" => $transactionId,
            );
            $header = array(
                "api" => $this->get_option('apikeys'),
                "url" => $this->get_option('payment_site') . "api/payment/verify"
            );

            $response = $this->create_payment($data, $header);
            $data = json_decode($response, true);

            if ($order->get_status() != 'completed') {
                if ($data['status'] == "COMPLETED") {
                    $transaction_id = $data['transaction_id'];
                    $amount = $data['amount'];
                    $sender_number = $data['cus_email'];
                    $payment_method = 'doniapay';

                    if ($this->get_option('is_digital') === 'yes') {
                        $order->update_status('completed', __("doniapay payment was successfully completed. Payment Method: {$payment_method}, Amount: {$amount}, Transaction ID: {$transaction_id}, Sender Number: {$sender_number}", 'doniapay'));
                        $order->reduce_order_stock();
                        $order->add_order_note(__('Payment completed via PGW URL checkout. trx id: ' . $transaction_id, 'doniapay'));
                        $order->payment_complete();
                    } else {
                        $order->update_status('processing', __("doniapay payment was successfully processed. Payment Method: {$payment_method}, Amount: {$amount}, Transaction ID: {$transaction_id}, Sender Number: {$sender_number}", 'doniapay'));
                        $order->reduce_order_stock();
                        $order->payment_complete();
                    }
                    return true;
                } else {
                    $order->update_status('on-hold', __('doniapay payment was successfully on-hold. Transaction id not found. Please check it manually.', 'doniapay'));
                    return true;
                }
            }
        }

        public function handle_webhook()
        {
            $order_id = $_GET['order_id'];
            $order = wc_get_order($order_id);

            if ($order) {
                $this->update_order_status($order);
            }

            status_header(200);
            echo json_encode(['message' => 'Webhook received and processed.']);
            exit();
        }
    }

    function doniapay_add_gateway_class($gateways)
    {
        $gateways[] = 'WC_doniapay_Gateway';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'doniapay_add_gateway_class');
}

function doniapay_handle_webhook()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $transactionId = $_REQUEST['transactionId'];
        $data = array(
            "transaction_id" => $transactionId,
        );
        $header = array(
            "api" => get_option('apikeys'),
            "url" => get_option('payment_site') . "api/payment/verify"
        );

        $response = create_payment($data, $header);
        $data = json_decode($response, true);

        if (isset($_GET['success1']) && $data['status'] == "COMPLETED") {
            $order_id = $_GET['success1'];
            $order = wc_get_order($order_id);

            if ($order) {
                $order->update_status('completed', __('Payment confirmed via webhook.', 'doniapay'));
                $order->reduce_order_stock();
                $order->payment_complete();
            }
        }
    }

    status_header(200);
    echo json_encode(['message' => 'Webhook received and processed.']);
    exit();
}

add_action('rest_api_init', function () {
    register_rest_route('doniapay/v1', '/webhook', array(
        'methods' => 'POST',
        'callback' => 'doniapay_handle_webhook',
    ));
});

