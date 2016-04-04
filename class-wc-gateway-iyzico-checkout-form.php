<?php
/*
 *  Plugin Name: WooCommerce iyzico checkout form Payment Gateway
 *  Plugin URI: https://www.iyzico.com
 *  Description: iyzico Payment gateway for woocommerce
 *  Text Domain: iyzico-woocommerce-checkout-form
 *  Domain Path: /i18n/languages/
 *  Version: 1.0.6
 *  Author: iyzico
 *  Author URI: https://www.iyzico.com
 * */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
define('API_URL_FORM', 'https://api.iyzipay.com/');

// init plugin
add_action('plugins_loaded', 'woocommerce_iyzico_checkout_from_init', 0);

function woocommerce_iyzico_checkout_from_init() {
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_Gateway_Iyzicocheckoutform extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'iyzicocheckoutform';
            $this->method_title = __('iyzico Checkout form', 'iyzico-woocommerce-checkout-form');
            $this->method_description = __( 'You can get your API ID and Secret key values from https://merchant.iyzipay.com/settings.', 'iyzico-woocommerce-checkout-form' );
            $this->icon = plugins_url('/iyzico-woocommerce-checkout-form/assets/img/cards.png', dirname(__FILE__));
            $this->has_fields = false;
            $this->order_button_text = __('Proceed to iyzico checkout', 'iyzico-woocommerce-checkout-form');
            $this->supports = array('products');

            // Load the form fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Get setting values
            $this->title = $this->settings['title'];
            $this->enabled = $this->settings['enabled'];
            $this->form_class = $this->settings['form_class'];
            
            //live keys
            $this->api_id = $this->settings['live_form_api_id'];
            $this->secret_key = $this->settings['live_form_secret_key'];

            add_action('init', array(&$this, 'check_iyzicocheckoutform_response'));
            add_action('woocommerce_api_wc_gateway_iyzicocheckoutform', array($this, 'check_iyzicocheckoutform_response'));

            add_action('admin_notices', array($this, 'checksFields'));
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
            }

            add_action('woocommerce_receipt_iyzicocheckoutform', array($this, 'receipt_page'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = 'no';
            }
        }

        /**
         * This function is used to check required fields,
         * */
        function checksFields() {
            global $woocommerce;

            if ($this->enabled == 'no')
                return;
        }

        /**
         * This function is used to initialize the fields for Admin side settings.
         */
        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'iyzico-woocommerce-checkout-form'),
                    'label' => __('Enable iyzico checkout', 'iyzico-woocommerce-checkout-form'),
                    'type' => 'checkbox',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'iyzico-woocommerce-checkout-form'),
                    'type' => 'text',
                    'description' => __('This message will show to the user during checkout.', 'iyzico-woocommerce-checkout-form'),
                    'default' => '�deme Yap'
                ),
                'live_form_api_id' => array(
                    'title' => __('Live Merchant API ID', 'iyzico-woocommerce-checkout-form'),
                    'type' => 'text'
                ),
                'live_form_secret_key' => array(
                    'title' => __('Live Merchant Secret Key', 'iyzico-woocommerce-checkout-form'),
                    'type' => 'text'
                ),
                 'form_class' => array(
                    'title' => __('Form Class', 'iyzico-woocommerce-checkout-form'),
                    'type' => 'select',
                    'default' => 'responsive',
                    'options' => array('popup' => __('Popup', 'iyzico-woocommerce-checkout-form'), 'responsive' => __('Responsive', 'iyzico-woocommerce-checkout-form'))
                ),
            );
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         *
         * @return bool
         */
        function is_valid_for_use() {
            if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_paypal_supported_currencies', array('TRY')))) {
                return false;
            }

            return true;
        }

        /**
         * Admin Panel Options
         * 
         * @since 1.0.0
         */
        public function admin_options() {
            if ($this->is_valid_for_use()) {
                parent::admin_options();
            } else {
                ?>
                <div class="inline error"><p><strong><?php _e('Gateway Disabled', 'iyzico-woocommerce-checkout-form'); ?></strong>: <?php _e('iyzico Checkout does not support your store currency.', 'iyzico-woocommerce-checkout-form'); ?></p></div>
                <?php
            }
        }

        /**
         * Receipt Page.
         * 
         * */
        function receipt_page($order) {
            global $woocommerce;

            $message = '<p>' . __('Thank you for your order, please click the button below to pay with iyzico Checkout.', 'iyzico-woocommerce-checkout-form') . '</p>';
            
            $response = $this->generate_iyzicocheckoutform_form($order);
            
            if (is_object($response) && 'success' == $response->getStatus()) {
                echo $message;
                $response = $response->getCheckoutFormContent();
                echo ' <div id="iyzipay-checkout-form" class="' . $this->form_class . '">' . $response . '</div>';
            } else if (is_object($response) && $response->getStatus() == 'failure') {
                echo $message;
                $response = $response->getErrorMessage();
                wc_add_notice(__($response, 'iyzico-woocommerce-checkout-form'), 'error');
            } else {
               wc_add_notice(__($response, 'iyzico-woocommerce-checkout-form'), 'error');
            }
        }

        /**
         * Generated iyzico payment form
         */
        function generate_iyzicocheckoutform_form($order_id) {
            global $woocommerce;

            $iyzico_gateway = new iyzicocheckoutformGateway($this->settings, $order_id);

            $api_response = $iyzico_gateway->generatePaymentToken();
            
            return $api_response;
        }

        /**
         * Process the payment and return the result
         * */
        function process_payment($order_id) {
            $order = new WC_Order($order_id);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) {
                /* 2.1.0 */
                $checkout_payment_url = $order->get_checkout_payment_url(true);
            } else {
                /* 2.0.0 */
                $checkout_payment_url = get_permalink(get_option('woocommerce_pay_page_id'));
            }

            return array(
                'result' => 'success',
                'redirect' => add_query_arg(
                        'order', $order->id, add_query_arg(
                                'key', $order->order_key, $checkout_payment_url
                        )
                )
            );
        }

        /**
         * Handle iyzico response and update order
         * 
         */
        function check_iyzicocheckoutform_response() {
            global $woocommerce;
            $order_id = '';
            $response = array();
            $siteLanguage = get_locale();

            try {
                require_once 'IyzipayBootstrap.php';
                 
                $token = $_POST['token'];
                if(empty($token)) {
                    throw new \Exception("Token not found");
                }

                IyzipayBootstrap::init();
                
                # create client configuration class
                $configuration = new \Iyzipay\Client\Configuration\ClientConfiguration();
                $configuration->setApiKey($this->api_id);
                $configuration->setSecretKey($this->secret_key);
                $configuration->setBaseUrl(API_URL_FORM);

                # create client class
                $client = \Iyzipay\Client\Service\EcomCheckoutFormServiceClient::fromConfiguration($configuration);

                # create request class
                $request = new \Iyzipay\Client\Ecom\Payment\Request\EcomRetrievePaymentCheckoutFormAuthRequest();
                $request->setLocale(\Iyzipay\Client\RequestLocaleType::TR);
                
                $request->setToken($token);

                # make request
                $response = $client->getAuthResponse($request);
                
//                # print response
//                if (empty($_REQUEST['wc-api']) || (!empty($_REQUEST['wc-api']) && 'WC_Gateway_Iyzicocheckoutform' !== $_REQUEST['wc-api'])) {
//                    throw new \Exception('Invalid request');
//                }
                
                //generate token response
                $api_response = $response->getStatus();
                if (empty($api_response) || 'success' != $api_response) {
                    throw new \Exception($response->getErrorMessage());
                }
                
                $payment_status  = $response->getPaymentStatus();
                if (empty($api_response) || 'SUCCESS' != $payment_status) {
                    throw new \Exception($response->getErrorMessage());
                }
                
                //check transaction token and cart is empty then checkout redirect 
                $token = $response->getToken();
                if (empty($token)) {
                    throw new \Exception("Invalid Token");
                }
                
                $transaction_object = $response->getBasketId();
                $order = new WC_Order($response->getBasketId());
               
                update_post_meta($transaction_object, 'get_auth', json_encode(array(
                    'api_request' => $request->toJsonString(),
                    'api_response' => $response->getRawResult(),
                    'processing_timestamp' => date('Y-m-d H:i:s', $response->getSystemTime() / 1000),
                    'transaction_status' => $response->getStatus(),
                    'created' => date('Y-m-d H:i:s'),
                    'note' => ($response->getStatus() != 'success') ? $response->getErrorMessage() : ''
                )));
               
                if ($order->post_status != 'wc-pending' || $order->post_status == 'wc-processing') {
                    throw new \Exception('Invalid request');
                }
                $checkout_orderurl = $order->get_checkout_order_received_url();
                $transauthorised = false;

                if ($order->status !== 'completed') { 
                    if ('success' == $response->getStatus()) {
                        
                        $items_array = $order->get_items();
                        foreach ( $items_array as $item ) { 
                            if($productid == $item["product_id"]){
                                update_post_meta($productid,"product_".$item["product_id"]."_status","completed");
                            }
                        }
                        
//                        update_user_meta( $userid, "_basket", $newlikes );
                        
                        $transauthorised = true;
                        $this->msg['message'] = __("Thank you for shopping with us. Your account has been charged and your transaction is successful.", 'iyzico-woocommerce-checkout-form');
                        $this->msg['class'] = 'woocommerce-message';

                        /**
                         * If installment is enable from admin pannel and process to installment.
                         */
                        $installment = $response->getInstallment();
                        if (!empty($installment) && $installment > 1) {
                            $installment_fee = $response->getPaidPrice() - $response->getPrice();
                            $order_fee = new stdClass();
                            $order_fee->id = 'Installment Fee';
                            $order_fee->name = __('Installment Fee', 'iyzico-woocommerce-checkout-form');
                            $order_fee->amount = floatval($installment_fee);
                            $order_fee->taxable = false;
                            $fee_id = $order->add_fee($order_fee);
                            $order->calculate_totals(true);

                            update_post_meta($order_id, 'iyzi_no_of_installment', $response->getInstallment());
                            update_post_meta($order_id, 'iyzi_installment_fee', $installment_fee);
                        }
                        
                        $order->payment_complete();
                        $order->add_order_note(__('Payment successful.', 'iyzico-woocommerce-checkout-form') . '<br/>' . __('Payment ID', 'iyzico-woocommerce-checkout-form') . ': ' . $response->getPaymentId());
                        $woocommerce->cart->empty_cart();
                    } else {
                        $this->msg['class'] = 'woocommerce-error';
                        $this->msg['message'] = __("Thank you for shopping with us. However, the transaction has been declined.", 'iyzico-woocommerce-checkout-form');
                        $order->add_order_note(__('Transaction ERROR', 'iyzico-woocommerce-checkout-form') . ': ' . $this->getValidErrorMessage($response, $siteLanguage));
                    }
                } else {
                    $this->msg['class'] = 'error';
                    $this->msg['message'] = __('Security error. Illegal access detected.', 'iyzico-woocommerce-checkout-form');
                }
                if ($transauthorised == false) {
                    $order->update_status('failed');
                }
                $redirect_url = add_query_arg(array('msg' => addslashes($this->msg['message']), 'type' => $this->msg['class']), $checkout_orderurl);
                wp_redirect($redirect_url);
                exit;
            } catch (\Exception $ex) {
                $respMsg = $ex->getMessage();
                $respMsg = !empty($respMsg) ? $respMsg : "Invalid Request";
                wc_add_notice(__($respMsg, 'iyzico-woocommerce-checkout-form'), 'error');
                $redirect_url = $woocommerce->cart->get_checkout_url();
                wp_redirect($redirect_url);
                exit;
            }
        }

    }
}

add_filter('woocommerce_payment_gateways', 'woocommerce_add_iyzico_checkout_form_gateway');

/**
 * Add the Gateway to WooCommerce
 * */
function woocommerce_add_iyzico_checkout_form_gateway($methods) {
    $methods[] = 'WC_Gateway_Iyzicocheckoutform';
    return $methods;
}

/**
 * Load Localisation files.
 */
function iyzico_checkout_form_load_plugin_textdomain() {
    load_plugin_textdomain('iyzico-woocommerce-checkout-form', FALSE, plugin_basename(dirname(__FILE__)) . '/i18n/languages/');
}

add_action('plugins_loaded', 'iyzico_checkout_form_load_plugin_textdomain');

class iyzicocheckoutformGateway {

    /**
     * plugin settings
     */
    private $_pluginSettings = array();

    /**
     * order object
     */
    private $_wcOrder = array();

    function __construct($settings, $order_id) {
        $this->_pluginSettings = $settings;
        $this->_wcOrder = new WC_Order($order_id);
    }

    /**
     * Generate the token.
     * @return json string
     */
    function generatePaymentToken() {
       
        if($this->_wcOrder->get_order_currency() != 'TRY') {
                       
            $response = 'Şu anda sadece TL desteklenmektedir.';   
            return $response;
        }
       
        require_once 'IyzipayBootstrap.php';

        IyzipayBootstrap::init();
        
        //live keys 
        $api_id = $this->_pluginSettings['live_form_api_id'];
        $secret_key = $this->_pluginSettings['live_form_secret_key'];
        
        $cart_total = 0;
        
        # create client configuration class
        $configuration = new \Iyzipay\Client\Configuration\ClientConfiguration();
        $configuration->setApiKey($api_id);
        $configuration->setSecretKey($secret_key);
        $configuration->setBaseUrl(API_URL_FORM);
       
        $order_amount = $this->_wcOrder->order_total;
        $checkout_orderurl = $this->_wcOrder->get_checkout_order_received_url();
        $return_url = add_query_arg('wc-api', 'WC_Gateway_Iyzicocheckoutform', $checkout_orderurl);
              
        # create client class
        $client = \Iyzipay\Client\Service\EcomCheckoutFormServiceClient::fromConfiguration($configuration);

        # create request class
        $request = new \Iyzipay\Client\Ecom\Payment\Request\EcomPaymentCheckoutFormInitializeRequest();
        $request->setLocale(\Iyzipay\Client\RequestLocaleType::TR);
        $request->setConversationId(uniqid().'_'.$this->_wcOrder->id);
        $request->setPaidPrice(round($order_amount,2));
        $request->setBasketId($this->_wcOrder->id);
        $request->setPaymentGroup(\Iyzipay\Client\Ecom\Payment\Enumtype\PaymentGroupRequestType::PRODUCT);
        $request->setPaymentSource(\Iyzipay\Client\Ecom\Payment\Enumtype\PaymentSourceRequestType::WOOCOMMERCE.'-'.WOOCOMMERCE_VERSION);
        $request->setCallbackUrl($return_url);
        
        // billing
        $first_name = !empty($this->_wcOrder->billing_first_name) ? $this->_wcOrder->billing_first_name : 'NOT PROVIDED';
        $last_name = !empty($this->_wcOrder->billing_last_name) ? $this->_wcOrder->billing_last_name : 'NOT PROVIDED';
        $phone = !empty($this->_wcOrder->billing_phone) ? $this->_wcOrder->billing_phone : 'NOT PROVIDED';
        $email = !empty($this->_wcOrder->billing_email) ? $this->_wcOrder->billing_email : 'NOT PROVIDED';
        $order_date = !empty($this->_wcOrder->order_date) ? $this->_wcOrder->order_date : 'NOT PROVIDED';
        $modified_date = !empty($this->_wcOrder->modified_date) ? $this->_wcOrder->modified_date : 'NOT PROVIDED';
        $city = !empty($this->_wcOrder->billing_city) ? $this->_wcOrder->billing_city : 'NOT PROVIDED';
        $country = !empty(WC()->countries->countries[$this->_wcOrder->billing_country]) ? WC()->countries->countries[$this->_wcOrder->billing_country] : 'NOT PROVIDED';
        $postcode = !empty($this->_wcOrder->billing_postcode) ? $this->_wcOrder->billing_postcode : 'NOT PROVIDED';
        
        //shipping
        $shipping_city = !empty($this->_wcOrder->shipping_city) ? $this->_wcOrder->shipping_city : 'NOT PROVIDED';
        $shipping_country = !empty(WC()->countries->countries[$this->_wcOrder->shipping_country]) ? WC()->countries->countries[$this->_wcOrder->shipping_country] : 'NOT PROVIDED';
        $shipping_postcode = !empty($this->_wcOrder->shipping_postcode) ? $this->_wcOrder->shipping_postcode : 'NOT PROVIDED';
        
        # create payment buyer dto
        $buyer = new \Iyzipay\Client\Ecom\Payment\Dto\EcomPaymentBuyerDto();
        $buyer->setId($this->_wcOrder->id); 
        $buyer->setName($first_name);
        $buyer->setSurname($last_name); 
        $buyer->setGsmNumber($phone);
        $buyer->setEmail($email); 
        $buyer->setIdentityNumber(uniqid());
        $buyer->setLastLoginDate($order_date);
        $buyer->setRegistrationDate($modified_date);
        $buyer->setRegistrationAddress($this->_wcOrder->billing_address_1.','.$this->_wcOrder->billing_address_2);
        $buyer->setIp($_SERVER['REMOTE_ADDR']);
        $buyer->setCity($city);
        $buyer->setCountry($country);
        $buyer->setZipCode($postcode);
        $request->setBuyer($buyer);

        # create billing address dto
        $billingAddress = new \Iyzipay\Client\Ecom\Payment\Dto\EcomPaymentBillingAddressDto();
        $billingAddress->setContactName($this->_wcOrder->get_formatted_billing_full_name());
        $billingAddress->setCity($city);
        $billingAddress->setCountry($country);
        $billingAddress->setAddress($this->_wcOrder->billing_address_1.','.$this->_wcOrder->billing_address_2);
        $billingAddress->setZipCode($postcode);
        $request->setBillingAddress($billingAddress);

        # create shipping address dto
        $shippingAddress = new \Iyzipay\Client\Ecom\Payment\Dto\EcomPaymentShippingAddressDto();
        $shippingAddress->setContactName($this->_wcOrder->get_formatted_shipping_full_name());
        $shippingAddress->setCity($shipping_city);
        $shippingAddress->setCountry($shipping_country);
        $shippingAddress->setAddress($this->_wcOrder->shipping_address_1.','.$this->_wcOrder->shipping_address_2);
        $shippingAddress->setZipCode($shipping_postcode);
        $request->setShippingAddress($shippingAddress);
        
        # create payment basket items
        $items = array();
        $sub_total = 0;
        $product_final_price = 0;
        global $woocommerce;
        $items_array = $woocommerce->cart->get_cart();
        $shipping_total = $this->_wcOrder->get_total_shipping() + $this->_wcOrder->get_shipping_tax();
       
        foreach ( $items_array as $item ) { 
            $sub_total = WC()->cart->subtotal_ex_tax;
            $product_cats = wp_get_post_terms( $item['product_id'], 'product_cat' );
           
            //product type
            $product = new WC_Product( $item['product_id'] );
            
            if( $product->is_downloadable() || $product->is_virtual() ){
                $request_type = \Iyzipay\Client\Ecom\Payment\Enumtype\BasketItemRequestType::VIRTUAL;
            } else {
                $request_type = \Iyzipay\Client\Ecom\Payment\Enumtype\BasketItemRequestType::PHYSICAL;
            } 
            
            //category
            if ( $product_cats && ! is_wp_error ( $product_cats ) ){ 
                 $single_cat = array_shift( $product_cats ); 
            }
            $product_final_price = $item['line_total'] + $item['line_tax'];
            
            //shipping calculation
            if($shipping_total > 0) {
                $product_with_shipping = (($item['data']->price * $item['quantity'])/ $sub_total) * $shipping_total;
                $product_final_price += $product_with_shipping;
            }
           
            $category = !empty($single_cat->name) ? $single_cat->name : 'NOT PROVIDED';
        
            $product_detail = new \Iyzipay\Client\Ecom\Payment\Dto\EcomPaymentBasketItemDto();
            $product_detail->setId($item['product_id']);
            $product_detail->setName($item['data']->post->post_title);
            $product_detail->setCategory1($category);
            $product_detail->setItemType($request_type);
            $product_detail->setPrice(round($product_final_price, 2));
            $cart_total += round($product_final_price, 2);
            $themerchantid = get_post_field( 'post_author', $item['product_id'] );
            $themerchantkey = get_user_meta($themerchantid,"_merchantkey",true);
            if($themerchantkey){
                $product_detail->setSubMerchantKey($themerchantkey);
            }else{
                $userid = $themerchantid;
                $address = $this->UsersGetAddress($userid);
                $userdata = get_userdata($userid);
                $bankinfo = get_user_meta($userid,"_bankinfo",true);
                if($userdata->first_name == ""){
                    $userdata->first_name = get_user_meta( $userdata->ID, 'shipping_first_name', true );
                }
                if($userdata->last_name == ""){
                    $userdata->last_name = get_user_meta( $userdata->ID, 'shipping_last_name', true );
                }
                $identitynumber = "TCKN".md5($userdata->ID);

                # create request class
                $request = new \Iyzipay\Request\CreateSubMerchantRequest();
                # tracking
                $request->setLocale(\Iyzipay\Model\Locale::TR);
                $request->setConversationId("merchant_".$userdata->ID);
                # new data
                $request->setSubMerchantExternalId("U".$userdata->ID);
                $request->setSubMerchantType(\Iyzipay\Model\SubMerchantType::PERSONAL);
                $request->setAddress($address);
                $request->setContactName($userdata->first_name);
                $request->setContactSurname($userdata->last_name);
                $request->setEmail($userdata->user_email);
                $request->setGsmNumber("+905350000000");
                $request->setName($userdata->first_name." ".$userdata->last_name." Dükkanı");
                $request->setIban($bankinfo[0]["iban"]);
                $request->setIdentityNumber($identitynumber);

                # make request
                $themerchant =  \Iyzipay\Model\SubMerchant::create($request, $configuration);
                $product_detail->setSubMerchantKey($themerchant->getSubMerchantKey());

            }
            $product_detail->setSubMerchantPrice(round($cart_total * 0.9, 2));
            
            if($product_final_price > 0) {
                $items[] = $product_detail;
            }
        }
        
        if($order_amount != $cart_total) {
            $amount_difference = $order_amount - $cart_total;
            $item_array_keys = array_keys($items);
            $last_item = end($item_array_keys);
            $last_item_amount = $items[$last_item]->getPrice() + $amount_difference;
            $cart_total += $amount_difference;
            $items[$last_item]->setPrice(round($last_item_amount, 2));
        }
        
        $request->setPrice($cart_total);
        $request->setBasketItems($items);
     
        # make request
        if(empty($items)){
             wp_redirect( $this->_wcOrder->get_checkout_order_received_url());
        } else {
            $response = $client->initializeCheckoutForm($request);
            
            update_post_meta($this->_wcOrder->id, 'payment_form_initialization', json_encode(array(
                'api_request' => $request->toJsonString(),
                'api_response' => $response->getRawResult(),
                'processing_timestamp' => date('Y-m-d H:i:s', $response->getSystemTime() / 1000),
                'transaction_status' => $response->getStatus(),
                'created' => date('Y-m-d H:i:s'),
                'note' => ($response->getStatus() != 'success') ? $response->getErrorMessage() : ''
            )));

            return $response;
            }
        }
    }

    