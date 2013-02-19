<?php
########################################################################
#                                                             	       #
#           The property of ICEPAY www.icepay.com              #
#                                                             	       #
#       The merchant is entitled to change de ICEPAY plug-in           #
#       code, any changes will be at merchant's own risk.	       #
#	Requesting ICEPAY support for a modified plug-in will be       #
#	charged in accordance with the standard ICEPAY tariffs.	       #
#                                                             	       #
########################################################################

/**
 * ICEPAY Woocommerce Payment module - Main script
 * 
 * @version 2.0.0
 * @author Wouter van Tilburg <wouter@icepay.eu>
 * @license http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @copyright Copyright (c) 2012 ICEPAY B.V.
 * 
 * Below are settings required for Wordpress
 * 
 * Plugin Name: ICEPAY for Woocommerce
 * Plugin URI: http://www.icepay.com/webshop-modules/online-payments-for-wordpress-woocommerce
 * Description: Enables ICEPAY within Woocommerce
 * Author: ICEPAY
 * Version: 2.0.0
 * Author URI: http://www.icepay.com
 */
// Define constants
define('ICEPAY_VERSION', '2.0.0');
define('ICEPAY_TRANSACTION_TABLE', 'woocommerce_icepay_transactions');
define("ICEPAY_PM_INFO", 'woocommerce_icepay_pminfo');
define("ICEPAY_PM_RAWDATA", 'woocommerce_icepay_pmrawdata');

// Load ICEPAY translations
load_plugin_textdomain('icepay', false, dirname(plugin_basename(__FILE__)) . '/languages/');

// Require ICEPAY API
require(realpath(dirname(__FILE__)) . '/api/icepay_api_webservice.php');

// Add hook for activation
register_activation_hook(__FILE__, 'ICEPAY_register');

// This function is called when ICEPAY is being activated
function ICEPAY_register() {
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Add custom status (To prevent user cancel - or re-pay on standard status pending)
    wp_insert_term(__('Awaiting Payment', 'icepay'), 'shop_order_status');

    // Create ICEPAY Transactions table
    $table_name = $wpdb->prefix . ICEPAY_TRANSACTION_TABLE;
    $sql = "CREATE TABLE {$table_name} (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL,
        `status` varchar(255) NOT NULL,
        `transaction_id` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`)
    );";

    dbDelta($sql);

    // Create Paymentmethods table for raw data
    $table_name = $wpdb->prefix . ICEPAY_PM_RAWDATA;
    $sql = "CREATE TABLE $table_name (
        `raw_pm_data` TEXT
    );";

    dbDelta($sql);
}

add_action('plugins_loaded', 'WC_ICEPAY_Load', 0);

function WC_ICEPAY_Load() {

    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_ICEPAY_Paymentmethod extends WC_Payment_Gateway {

        public function __construct() {
            if (!isset($_SESSION['icepay_paymentmethods'][0]))
                return;

            global $wpdb;

            $this->settings = (array) get_option('woocommerce_icepay_settings');
            $this->paymentMethodCode = $_SESSION['icepay_paymentmethods'][0][0];

            $this->id = "ICEPAY_{$this->paymentMethodCode}";
            $this->title = $this->settings["{$this->paymentMethodCode}displayname"];
            $this->method_title = 'icepay_';

            $icRawDataTable = $wpdb->prefix . ICEPAY_PM_RAWDATA;
            $paymentMethods = unserialize($wpdb->get_var("SELECT raw_pm_data FROM $icRawDataTable"));

            $method = Icepay_Api_Webservice::getInstance()->singleMethod()->loadFromArray($paymentMethods);
            $pMethod = $method->selectPaymentMethodByCode($this->paymentMethodCode);

            $issuers = $pMethod->getIssuers();

            $output = sprintf("<input type='hidden' name='paymentMethod' value='%s' />", $this->paymentMethodCode);

            $image = sprintf("%s/images/%s.png", plugins_url('', __FILE__), strtolower($this->paymentMethodCode));
            $output .= "<img src='{$image}' />";

            if (count($issuers) > 1) {
                __('AMEX', 'icepay');
                __('VISA', 'icepay');
                __('MASTER', 'icepay');
                __('ABNAMRO', 'icepay');
                __('ASNBANK', 'icepay');
                __('FRIESLAND', 'icepay');
                __('ING', 'icepay');
                __('RABOBANK', 'icepay');
                __('SNSBANK', 'icepay');
                __('SNSREGIOBANK', 'icepay');
                __('TRIODOSBANK', 'icepay');
                __('VANLANSCHOT', 'icepay');
                __('KNAB', 'icepay');

                $output .= "<select name='{$this->paymentMethodCode}_issuer' style='width:164px; padding: 2px; margin-left: 7px;'>";
                foreach ($issuers as $issuer) {
                    $output .= sprintf("<option value='%s'>%s</option>", $issuer['IssuerKeyword'], __($issuer['IssuerKeyword'], 'icepay'));
                }
                $output .= '</select>';
            }

            $this->description = $output;

            if ($this->settings[$this->paymentMethodCode] == 'yes')
                $this->enabled = true;

            array_shift($_SESSION['icepay_paymentmethods']);
        }

        public function process_payment($order_id) {
            global $wpdb, $woocommerce;

            $order = &new woocommerce_order($order_id);

            // Get the order and fetch the order id
            $orderID = $order->id;

            try {
                $webservice = Icepay_Api_Webservice::getInstance()->paymentService();
                $webservice->addToExtendedCheckoutList(array('AFTERPAY'))
                        ->setMerchantID($this->settings['merchantid'])
                        ->setSecretCode($this->settings['secretcode']);

                $paymentMethod = explode('_', $order->payment_method);
                $pmCode = strtoupper($paymentMethod[1]);

                if ($webservice->isExtendedCheckoutRequiredByPaymentMethod($pmCode)) {

                    $consumerID = ($order->user_id == null) ? 'Guest' : $order->user_id;

                    // Set Consumer Info
                    Icepay_Order::getInstance()
                            ->setConsumer(Icepay_Order_Consumer::create()
                                    ->setConsumerID($consumerID)
                                    ->setEmail($order->billing_email)
                                    ->setPhone($order->billing_phone)
                    );

                    // Add Products                
                    foreach ($order->get_items() as $product) {
                        $pricePerProduct = $product['line_total'] / $product['qty'];

                        $taxRateMultiplier = round(($product['line_tax'] / $product['line_total']) + 1, 2);
                        $taxRatePercentage = round($product['line_tax'] / $product['line_total'] * 100, 2);

                        $price = round($pricePerProduct * $taxRateMultiplier, 2) * 100;
                        
                        Icepay_Order::getInstance()
                                ->addProduct(Icepay_Order_Product::create()
                                        ->setProductID($product['id'])
                                        ->setProductName($product['name'])
                                        ->setDescription($product['name'])
                                        ->setQuantity($product['qty'])
                                        ->setUnitPrice($price)
                                        ->setVATCategory(Icepay_Order_VAT::getCategoryForPercentage((int)(string)$taxRatePercentage))
                        );

                        // WooCommerce calculates taxes per row instead of per unit price
                        // Sadly need to make an tax correction for Afterpay untill WooCommerce has tax calculation based on unit price.
                        $totalPriceTaxPerRow = ($product['line_tax'] + $product['line_total']) * 100;
                        $totalPriceTaxPerUnit = $price * $product['qty'];

                        $taxDifference = (int) (string) ($totalPriceTaxPerRow - $totalPriceTaxPerUnit);

                        if (abs($taxDifference) > 0 && abs($taxDifference) < 10) {
                            Icepay_Order::getInstance()
                                    ->addProduct(Icepay_Order_Product::create()
                                            ->setProductID($product['id'])
                                            ->setProductName($product['name'])
                                            ->setDescription('BTW Correctie')
                                            ->setQuantity('1')
                                            ->setUnitPrice($taxDifference)
                                            ->setVATCategory(Icepay_Order_VAT::getCategoryForPercentage(0))
                            );
                        }
                    };

                    $billingAddress = $order->billing_address_1 . " " . $order->billing_address_2;

                    // Billing Address
                    Icepay_Order::getInstance()
                            ->setBillingAddress(
                                    Icepay_Order_Address::create()
                                    ->setInitials($order->billing_first_name)
                                    ->setLastName($order->billing_last_name)
                                    ->setStreet(Icepay_Order_Helper::getStreetFromAddress($billingAddress))
                                    ->setHouseNumber(Icepay_Order_Helper::getHouseNumberFromAddress($billingAddress))
                                    ->setHouseNumberAddition(Icepay_Order_Helper::getHouseNumberAdditionFromAddress($billingAddress))
                                    ->setZipCode($order->billing_postcode)
                                    ->setCity($order->billing_city)
                                    ->setCountry($order->billing_country)
                    );

                    $shippingAddress = $order->shipping_address_1 . " " . $order->shipping_address_2;

                    // Shipping Address
                    Icepay_Order::getInstance()
                            ->setShippingAddress(
                                    Icepay_Order_Address::create()
                                    ->setInitials($order->shipping_first_name)
                                    ->setLastName($order->shipping_last_name)
                                    ->setStreet(Icepay_Order_Helper::getStreetFromAddress($shippingAddress))
                                    ->setHouseNumber(Icepay_Order_Helper::getHouseNumberFromAddress($shippingAddress))
                                    ->setHouseNumberAddition(Icepay_Order_Helper::getHouseNumberAdditionFromAddress($shippingAddress))
                                    ->setZipCode($order->shipping_postcode)
                                    ->setCity($order->shipping_city)
                                    ->setCountry($order->shipping_country)
                    );

                    // Shipping                
                    if ($order->order_shipping != '0.00') {
                        $taxRate = (int) (string) ($order->order_shipping_tax / $order->order_shipping * 100);
                        Icepay_Order::getInstance()->setShippingCosts($order->order_shipping * 100, $taxRate);
                    }

                    // Discount            
                    if ($order->order_discount != '0.00') {
                        $orderDiscount = (int) (string) ($order->order_discount * 100);
                        Icepay_Order::getInstance()->setOrderDiscountAmount($orderDiscount);
                    }
                }

                // Initiate icepay object
                $ic_obj = new StdClass();

                // Get the grand total of order
                $ic_obj->amount = (string) (int) ($order->order_total * 100);

                // Get the billing country
                $ic_obj->country = $order->billing_country;

                // Get the used currency for the shop
                $ic_obj->currency = get_woocommerce_currency();

                // Get the Wordpress language and adjust format so ICEPAY accepts it.
                $language_locale = get_bloginfo('language');
                $ic_obj->language = strtoupper(substr($language_locale, 0, 2));

                // Get paymentclass
                $icRawDataTable = $wpdb->prefix . ICEPAY_PM_RAWDATA;
                $paymentMethods = unserialize($wpdb->get_var("SELECT raw_pm_data FROM {$icRawDataTable}"));

                $method = Icepay_Api_Webservice::getInstance()->singleMethod()->loadFromArray($paymentMethods);
                $pMethod = $method->selectPaymentMethodByCode($this->paymentMethodCode);

                $supportedIssuers = $pMethod->getIssuers();

                $issuerName = sprintf('%s_issuer', $paymentMethod[1]);

                if (isset($_POST[$issuerName])) {
                    $issuer = $_POST[$issuerName];
                } elseif (count($supportedIssuers > 0)) {
                    $issuer = $supportedIssuers[0]['IssuerKeyword'];
                } else {
                    $issuer = 'DEFAULT';
                }

                $description = !empty($this->settings['descriptiontransaction']) ? $this->settings['descriptiontransaction'] : null;

                // Add transaction to ICEPAY table
                $table_name = $wpdb->prefix . ICEPAY_TRANSACTION_TABLE;

                $wpdb->insert($table_name, array('order_id' => $order_id, 'status' => Icepay_StatusCode::OPEN, 'transaction_id' => NULL));
                $lastid = $wpdb->insert_id;

                $paymentObj = new Icepay_PaymentObject();
                $paymentObj->setOrderID($lastid)
                        ->setDescription($description)
                        ->setReference($orderID)
                        ->setAmount($ic_obj->amount)
                        ->setCurrency($ic_obj->currency)
                        ->setCountry($ic_obj->country)
                        ->setLanguage($ic_obj->language)
                        ->setPaymentMethod($pmCode)
                        ->setIssuer($issuer);

                /* Start the transaction */
                if ($webservice->isExtendedCheckoutRequiredByPaymentMethod($pmCode)) {
                    $transactionObj = $webservice->extendedCheckOut($paymentObj);
                } else {
                    $transactionObj = $webservice->CheckOut($paymentObj);
                }

                // Get payment url and redirect to paymentscreen
                $url = $transactionObj->getPaymentScreenURL();
            } catch (Exception $e) {

                if ($e->getMessage() == 'IC_ERR: Currency is not supported in country')
                    $message = 'Currency is not supported by this paymentmethod.';
                else
                    $message = $e->getMessage();

                $woocommerce->add_error(__('Payment error:', 'woothemes') . ' ' . $message);

                $order = &new WC_Order($orderID);
                $order->add_order_note("Customer tried to make an attempt to complete the order but an error occured: {$message}");

                return false;
            }

            return array(
                'result' => 'success',
                'redirect' => $url
            );
        }

    }

    class WC_ICEPAY extends WC_Payment_Gateway {

        public function __construct() {
            add_filter('woocommerce_payment_gateways', array('WC_ICEPAY', 'ICEPAY_Add_Gateway'));
            add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
            add_action('init', array($this, 'ICEPAY_Response'), 8);

            if (is_admin()) {
                add_action('wp_ajax_ic_getpaymentmethods', array($this, 'ICEPAY_GetPaymentMethodsCallback'));
            }

            $this->method_title = 'ICEPAY';
            $this->id = 'ICEPAY';
            $this->title = 'ICEPAY';

            $this->ICEPAY_Form();
            $this->init_settings();

            $this->enabled = false;
            $this->settings['postbackurl'] = sprintf('%s/index.php?page=icepayresult', get_option('siteurl'));
            
            $this->merchantID = $this->settings['merchantid'];
            $this->secretCode = $this->settings['secretcode'];
        }

        public function ICEPAY_GetPaymentMethodsCallback() {
            global $wpdb;

            // SOAP Check          
            if (class_exists('SoapClient') === false) {
                echo "<div class='ic_getpaymentmethods_error' style='margin: 20px 0px 10px 10px; color: Red;'>SOAP extension for PHP must be enabled. Please contact your webhoster.</div>";
                die();
            }
            
            try {
                $ic_paymentService = Icepay_Api_Webservice::getInstance()->paymentMethodService();
                $ic_paymentService->setMerchantID($this->merchantID)
                        ->setSecretCOde($this->secretCode);

                $paymentMethods = $ic_paymentService->retrieveAllPaymentmethods()->asArray();

                $ic_RawDataTable = $wpdb->prefix . ICEPAY_PM_RAWDATA;
                // Clear old raw paymentdata in database
                $wpdb->query("DELETE FROM $ic_RawDataTable");

                // Store new raw paymentdata in database
                $wpdb->insert($ic_RawDataTable, array('raw_pm_data' => serialize($paymentMethods)));

                if ($paymentMethods > 0) {
                    // Make up the form and insert it into the admin page
                    foreach ($paymentMethods as $paymentMethod) {
                        $pmCode = $paymentMethod['PaymentMethodCode'];

                        if ($pmCode != null) {
                            $readableName = $paymentMethod['Description'];

                            $html .= '<h4 class="" style="padding-left: 10px; margin: 10px 0px 0px; display: block;">' . $readableName . '</h4>';
                            $html .= '<table id="table-ic-paymentmethods" class="form-table" style="margin-left: 10px; display: table;">
                                            <tbody><tr valign="top">
                                                <th scope="row" class="titledesc">Active</th>
                                                <td class="forminp">
                                                <fieldset><legend class="screen-reader-text"><span>Active</span></legend>
                                                <label for="woocommerce_ICEPAY_' . $pmCode . '"><input style="" name="woocommerce_ICEPAY_' . $pmCode . '" id="woocommerce_ICEPAY_' . $pmCode . '" type="checkbox" value="1" class="">  </label><br>
                                                </fieldset></td>
                                                </tr>
                                                <tr valign="top">
                                                <th scope="row" class="titledesc"><label for="woocommerce_ICEPAY_.' . $pmCode . 'displayname">Display name</label></th>
                                                <td class="forminp">
                                                <fieldset><legend class="screen-reader-text"><span>Display name</span></legend>
                                                <input class="input-text wide-input " type="text" name="woocommerce_ICEPAY_' . $pmCode . 'displayname" id="woocommerce_ICEPAY_' . $pmCode . 'displayname" style="width: 300px;" value="' . $readableName . '"></fieldset></td>
                                                </tr>
                                            </tbody>
                                          </table>';
                        } else {
                            $html = "<div class='ic_getpaymentmethods_error' style='margin: 20px 0px 10px 10px; color: Red;'>No active paymentmethods found for this merchant.</div>";
                        }
                    }
                }
            } catch (Exception $e) {
                $html = "<div class='ic_getpaymentmethods_error' style='margin: 20px 0px 10px 10px; color: Red;'>An error occured: <b>{$e->getMessage()}</b></div>";
            }
            
            echo $html;

            // Always die on Ajax calls
            die();
        }

        // ICEPAY Form
        public function ICEPAY_Form() {
            global $wpdb;

            $this->form_fields = array(
                'stepone' => array(
                    'title' => __('Set-up configuration', 'icepay'),
                    'type' => 'title'
                ),
                'postbackurl' => array(
                    'title' => __('Postback URL', 'icepay'),
                    'type' => 'text',
                    'description' => __('Copy-Paste this URL to the Success, Error and Postback section of your ICEPAY merchant account.', 'icepay'),
                    'css' => 'width: 300px;'
                ),
                'merchantid' => array(
                    'title' => __('Merchant ID', 'icepay'),
                    'type' => 'text',
                    'description' => __('Copy the Merchant ID from your ICEPAY account.', 'icepay'),
                    'css' => 'width: 300px;'
                ),
                'secretcode' => array(
                    'title' => __('Secretcode', 'icepay'),
                    'type' => 'text',
                    'description' => __('Copy the Secret Code from your ICEPAY account.', 'icepay'),
                    'css' => 'width: 300px;'
                ),
                'descriptiontransaction' => array(
                    'title' => __('(Optional) Description on transaction statement of customer', 'icepay'),
                    'type' => 'text',
                    'description' => __('Some payment methods allow customized descriptions on the transaction statement. If left empty the Order ID is used. (Max 100 char.)', 'icepay'),
                    'css' => 'width: 300px;'
                ),
                'ipcheck' => array(
                    'title' => __('(Optional) Custom IP Range for IP Check for Postback', 'icepay'),
                    'type' => 'text',
                    'description' => __('For example a proxy: 1.222.333.444-100.222.333.444 For multiple ranges use a , seperator: 2.2.2.2-5.5.5.5,8.8.8.8-9.9.9.9', 'icepay'),
                    'css' => 'width: 300px;'
                ),
                'steptwo' => array(
                    'title' => __('PaymentMethods', 'icepay'),
                    'type' => 'title',
                    'class' => 'icpaymentmethods'
                )
            );


            // Get paymentmethods from Raw Data stored in Database
            // If no paymentmethods are stored, show message
            $icRawDataTable = $wpdb->prefix . ICEPAY_PM_RAWDATA;
            $paymentMethods = unserialize($wpdb->get_var("SELECT raw_pm_data FROM $icRawDataTable"));

            foreach ($paymentMethods as $key => $paymentMethod) {
                $methodTitle = sprintf("%stitle", $paymentMethod['PaymentMethodCode']);
                $this->form_fields[$methodTitle] = array(
                    'title' => $paymentMethod['Description'],
                    'type' => 'title'
                );
                $this->form_fields[$paymentMethod['PaymentMethodCode']] = array(
                    'title' => __('Active', 'icepay'),
                    'type' => 'checkbox',
                    'label' => ' '
                );

                $displayName = sprintf("%sdisplayname", $paymentMethod['PaymentMethodCode']);
                $this->form_fields[$displayName] = array(
                    'title' => __('Display name', 'icepay'),
                    'type' => 'text',
                    'css' => 'width: 300px;',
                    'default' => $paymentMethod['Description']
                );
            }
        }

        // Sadly had to use jQuery to make it possible for multiple payment methods :'(
        public function admin_options() {
            global $woocommerce;

            // Remove ICEPAY from the payment gateway list
            $woocommerce->add_inline_js("
                jQuery('.wc_gateways tr').each(function(e){
                    if ($(this).find('strong').text() == 'ICEPAY') {
                        $(this).remove();
                    }                            
                }); 
            ");

            // Remove all ICEPAY payment methods from top gateway navigation
            $woocommerce->add_inline_js("
                jQuery('.subsubsub li a').each(function(e){
                    if ($(this).text() == 'Icepay_') {
                        $(this).parent().remove();
                    }                            
                });
            ");

            // Hide Paymentmethods page
            $woocommerce->add_inline_js("      
                jQuery('#icepay-setup').click(function(e){
                    jQuery('#gateway-ICEPAY table').nextAll('h4').show().nextAll('table').show().nextAll('table, h4').hide();
                    jQuery('#table-ic-paymentmethods').hide();
                    jQuery('#ic_refreshpaymentmethods').hide();
                    jQuery('.ic_getpaymentmethods_error').hide();
                });
            ");

            // Hide Set-up configuration page
            $woocommerce->add_inline_js("                     
                jQuery('#icepay-payment').click(function(e){
                    jQuery('#gateway-ICEPAY table').nextAll('h4').hide().nextAll('table').hide().nextAll('table, h4').show();
                    jQuery('#ic_refreshpaymentmethods').show();
                    jQuery('.ic_getpaymentmethods_error').show();
                });               
            ");

            $woocommerce->add_inline_js("
                var button = '<input id=\"ic_refreshpaymentmethods\" type=\"submit\" value=\"Refresh Payment Methods\" class=\"button-primary\" style=\"margin: 20px; display: none;\" />';
                
                jQuery('#gateway-ICEPAY').append(button);
            ");

            $woocommerce->add_inline_js("       
                jQuery('#gateway-ICEPAY').css('background-color', '#F5F5F5').find('h4').css({'padding-left' : '10px', 'margin' : '10px 0 0 0'});
                jQuery('#gateway-ICEPAY table').css('margin-left', '10px').nextAll('table').nextAll('table, h4').hide();                
                jQuery('.submit').show();
                
                jQuery('#woocommerce_ICEPAY_postbackurl').click(function(e){
                    $(this).select(); 
                });
                
                jQuery('#ic_refreshpaymentmethods').click(function(e){
                    e.preventDefault();
                    
                    var val = jQuery(this).val();                   
                    
                    jQuery.ajax({
                        type: 'post',url: 'admin-ajax.php',data: { action: 'ic_getpaymentmethods' },
                        beforeSend: function() {
                            jQuery('#ic_refreshpaymentmethods').val('Loading paymentmethod data...').css('cursor', 'waiting').attr('disabled', 'disabled');
                            jQuery('body').css('cursor', 'progress');
                            jQuery('.ic_getpaymentmethods_error').remove();
                            jQuery('.icpaymentmethods').nextAll('table, h4').remove();
                        }, 
                        success: function(html){                            
                            jQuery('.icpaymentmethods').after(html);
                            jQuery('#table-ic-paymentmethods').nextAll().show();
                            jQuery('#ic_refreshpaymentmethods').val(val).removeAttr('disabled');
                            jQuery('body').css('cursor', 'auto');
                        }
                    });   
                });
            ");

            $image = sprintf("%s/images/icepay-header.png", plugins_url('', __FILE__));
            ?>

            <div style="background-image: url(<?php echo $image;
            ?>); width: 100%; height: 134px;">
                <div style="padding-top: 95px; padding-left: 10px; font-size: 12px; float: left;">
                    <a href="#" id="icepay-setup" style="text-decoration: none;" class="button-primary"><?php _e('Set-up configuration', 'icepay'); ?></a>
                    <a href="#" id="icepay-payment" style="text-decoration: none; margin-left: 5px;" class="button-primary"><?php _e('Payment Methods', 'icepay'); ?></a>                   
                </div>
                <div style="padding-top: 95px; padding-right: 10px; font-size: 12px; float: right;">
                    <a href="http://www.icepay.com/downloads/pdf/manuals/wordpress-woocommerce/manual-wordpress-woocommerce.pdf" target="_BLANK" style="text-decoration: none;"><?php _e('View the manual', 'icepay'); ?></a> | 
                    <a href="http://www.icepay.com" style="text-decoration: none;" target="_BLANK"><?php _e('Visit the ICEPAY website', 'icepay'); ?></a> |
                    Module Version <?php echo ICEPAY_VERSION; ?>
                </div>
            </div>

            <table class="icepay-settings">
                <?php $this->generate_settings_html(); ?>
            </table>

            <?php
            if (empty($this->merchantID) && empty($this->secretCode)) {
                printf("<div class='error'><p><b>ICEPAY:</b> %s</p></div>", __(' Merchant ID and Secretcode must be set!', 'icepay'));
            } elseif (!Icepay_Parameter_Validation::merchantID($this->merchantID) || !Icepay_Parameter_Validation::secretCode($this->secretCode)) {
                printf("<div class='error'><p><b>ICEPAY:</b> %s</p></div>", __(' Your MerchantID or SecretCode is incorrect. Please verify you copy pasted it correctly from our website.', 'icepay'));
            }                
        }

        // ICEPAY Response
        public function ICEPAY_Response() {
            global $wpdb, $wp_version, $woocommerce;

            if (isset($_GET['page']) && $_GET['page'] == 'icepayresult') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {

                    $icepay = Icepay_Project_Helper::getInstance()->postback();
                    $icepay->setMerchantID($this->merchantID)
                            ->setSecretCode($this->secretCode)
                            ->doIPCheck(true);

                    if (!empty($this->settings['ipcheck'])) {
                        $ipRanges = explode(",", $this->settings['ipcheck']);

                        foreach ($ipRanges as $ipRange) {
                            $ip = explode("-", $ipRange);
                            $icepay->setIPRange($ip[0], $ip[1]);
                        }
                    }

                    if ($icepay->validate()) {
                        $data = $icepay->GetPostback();

                        $order_id = $data->orderID;

                        $table_icepay = $wpdb->prefix . ICEPAY_TRANSACTION_TABLE;

                        $query = "SELECT * FROM $table_icepay WHERE `id` = %d";
                        $ic_order = $wpdb->get_row($wpdb->prepare($query, $order_id));

                        $order = &new WC_Order($ic_order->order_id);

                        if ($icepay->canUpdateStatus($ic_order->status)) {
                            switch ($data->status) {
                                case Icepay_StatusCode::ERROR:
                                    $order->add_order_note($data->statusCode);
                                    break;
                                case Icepay_StatusCode::OPEN:
                                    $order->update_status('awaiting-payment');
                                    break;
                                case Icepay_StatusCode::SUCCESS:
                                    $order->status = 'pending';
                                    $order->payment_complete();
                                    break;
                                case Icepay_StatusCode::REFUND:
                                    $order->update_status('cancelled');
                                    $order->add_order_note($data->statusCode);
                                    break;
                                case Icepay_StatusCode::CHARGEBACK:
                                    $order->update_status('cancelled');
                                    $order->add_order_note($data->statusCode);
                                    break;
                            }

                            $wpdb->update($table_icepay, array('status' => $data->status, 'transaction_id' => $data->transactionID), array('id' => $order_id));
                        }
                    } else {
                        if ($icepay->isVersionCheck()) {
                            $dump = array(
                                "module" => sprintf("ICEPAY Woocommerce payment module version %s using PHP API version %s", ICEPAY_VERSION, Icepay_Project_Helper::getInstance()->getReleaseVersion()), //<--- Module version and PHP API version
                                "notice" => "Checksum validation passed!"
                            );

                            if ($icepay->validateVersion()) {
                                $dump["additional"] = array(
                                    "Wordpress" => $wp_version, // CMS name & version
                                    "WooCommerce" => $woocommerce->version // Webshop name & version
                                );
                            } else {
                                $dump["notice"] = "Checksum failed! Merchant ID and Secret code probably incorrect.";
                            }
                            var_dump($dump);
                            exit();
                        }
                    }
                } else {
                    $icepay = Icepay_Project_Helper::getInstance()->result();

                    try {
                        $icepay->setMerchantID($this->merchantID)
                                ->setSecretCode($this->secretCode);
                    } catch (Exception $e) {
                        echo "Postback URL installed successfully.";
                    }

                    if ($icepay->validate()) {
                        $order_id = $_GET['OrderID'];

                        $table_icepay = $wpdb->prefix . ICEPAY_TRANSACTION_TABLE;

                        $query = "SELECT * FROM $table_icepay WHERE `id` = %d";
                        $ic_order = $wpdb->get_row($wpdb->prepare($query, $order_id));

                        $order = &new WC_Order($ic_order->order_id);

                        switch ($icepay->getStatus()) {
                            case Icepay_StatusCode::ERROR:
                                $order->add_order_note('User cancelled order.');
                                $location = $order->get_cancel_order_url();
                                wp_safe_redirect($location);
                                exit();
                                break;
                            case Icepay_StatusCode::OPEN:
                            case Icepay_StatusCode::AUTHORIZED:
                            case Icepay_StatusCode::SUCCESS:
                                $woocommerce->cart->empty_cart();

                                $location = add_query_arg('key', $order->order_key, add_query_arg('order', $ic_order->order_id, get_permalink(woocommerce_get_page_id('thanks'))));
                                wp_safe_redirect($location);
                                exit();
                                break;
                        }
                    }

                    exit();
                }
            }
        }

        // ICEPAY Add Gateway
        public function ICEPAY_Add_Gateway($methods) {
            global $wpdb;


            // Unset session just to be sure ;-)
            unset($_SESSION['icepay_paymentmethods']);

            $icRawDataTable = $wpdb->prefix . ICEPAY_PM_RAWDATA;
            $paymentMethods = unserialize($wpdb->get_var("SELECT raw_pm_data FROM $icRawDataTable"));

            $i = 0;
            foreach ($paymentMethods as $paymentMethod) {
                $key = $paymentMethod['PaymentMethodCode'];

                $gateway = 'WC_ICEPAY_Paymentmethod';
                $icepaySettings = (array) get_option('woocommerce_icepay_settings');

                // If paymentmethod is enabled in the ICEPAY configuration, add this method as a gateway
                if ($icepaySettings[$key] == 'yes') {
                    $_SESSION['icepay_paymentmethods'][$i] = array($key);
                    $methods[] = $gateway;
                    $i++;
                }
            }

            // Add ICEPAY core
            $methods[] = 'WC_ICEPAY';

            return $methods;
        }

    }

    // Let's dance!
    new WC_ICEPAY();
}