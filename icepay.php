<?php

########################################################################
#                                                             	      #
#           The property of ICEPAY www.icepay.com                      #
#                                                             	      #
#       The merchant is entitled to change de ICEPAY plug-in           #
#       code, any changes will be at merchant's own risk.	       #
#	Requesting ICEPAY support for a modified plug-in will be       #
#	charged in accordance with the standard ICEPAY tariffs.	       #
#                                                             	      #
########################################################################

/**
 * ICEPAY Woocommerce payment module
 * 
 * @author Wouter van Tilburg <wouter@icepay.eu>
 * @license http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @copyright Copyright (c) 2013 ICEPAY B.V.
 *
 * Plugin Name: ICEPAY Plugin for Woocommerce
 * Plugin URI: http://www.icepay.com/webshop-modules/online-payments-for-wordpress-woocommerce
 * Description: Enables ICEPAY Plugin within Woocommerce
 * Author: ICEPAY
 * Author URI: http://www.icepay.com
 * Version: 2.2.6
 */
// Launch ICEPAY when active plugins and pluggable functions are loaded
add_action('plugins_loaded', 'ICEPAY_Init');

require(realpath(dirname(__FILE__)) . '/api/icepay_api_webservice.php');
require(realpath(dirname(__FILE__)) . '/classes/helper.php');

function ICEPAY_Init() {
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class ICEPAY extends WC_Payment_Gateway {

        public function __construct() {
            // Load ICEPAY translations
            load_plugin_textdomain('icepay', false, dirname(plugin_basename(__FILE__)) . '/languages/');

            // Set core gateway settings
            $this->method_title = 'ICEPAY';
            $this->id = 'ICEPAY';
            $this->title = 'ICEPAY';

            $this->version = ICEPAY_Helper::getVersion();

            // Create admin configuration form
            $this->initForm();

            // Initialise gateway settings
            $this->init_settings();

            // Core gateway is for configuration use only and should never be enabled
            $this->enabled = false;

            // Add postback URL to configuration form
            $this->settings['postbackurl'] = str_replace('https:', 'http:', add_query_arg('wc-api', 'icepay_result', home_url('/')));

            // Payment listener/API hook
            add_action('woocommerce_api_icepay_result', array($this, 'result'));

            // Since we use a class wrapper, our class is called  twice. To prevent double execution we do a check if the gateway is already registered.
            $loaded_gateways = apply_filters('woocommerce_payment_gateways', array());
            if (in_array($this->id, $loaded_gateways))
                return;

            // Add ICEPAY as WooCommerce gateway
            add_filter('woocommerce_payment_gateways', array($this, 'addGateway'));

            // Check if on admin page
            if (is_admin()) {
                // Run install if false - not using install hook to make sure people who upgrade get the correct tables installed (Upgrade function was added later)
                if (!get_option('ICEPAY_Installed', false))
                    $this->install();
                                
                // Run upgrade if needed
                $upgradeRequired = array('2.2.2');
                
                $currentVersion = get_option('ICEPAY_Version', '1.0.0');
                
                foreach ($upgradeRequired as $version) {
                    if (version_compare($currentVersion, $version, '<'))
                        $this->upgrade($version);
                }
                
                update_option('ICEPAY_VERSION', $this->version);              

                if (ICEPAY_Helper::isOldWooCommerce()) {
                    // WooCommerce 1.6.6 compatiblity
                    add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
                } else {
                    // WooCommerce 2.0.0+
                    add_action('woocommerce_update_options_payment_gateways_ICEPAY', array($this, 'process_admin_options'));
                }

                // Ajax callback for getPaymentMethods
                add_action('wp_ajax_ic_getpaymentmethods', array($this, 'getPaymentMethods'));

                // Add scripts
                add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
            }
        }

        public function enqueueScripts() {
            // Add files only on ICEPAY's configuration page
            if (ICEPAY_helper::isIcepayPage($this->id)) {
                wp_enqueue_script('icepay', '/wp-content/plugins/icepay-woocommerce-online-payment-module/assets/js/icepay.js', array('jquery'), '1.2');
                wp_enqueue_style('icepay', '/wp-content/plugins/icepay-woocommerce-online-payment-module/assets/css/icepay.css', array(), '1.0');
                
                wp_localize_script('icepay', 'objectL10n', array(
                    'loading' => __('Loading paymentmethod data...', 'icepay'),
                    'refresh' => __('Refresh Payment Methods', 'icepay')
                ));
            }
        }

        public function result() {
            global $wpdb, $wp_version, $woocommerce;

            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $icepay = Icepay_Project_Helper::getInstance()->postback();
                $icepay->setMerchantID($this->settings['merchantid'])
                        ->setSecretCode($this->settings['secretcode'])
                        ->doIPCheck(true);

                if (!empty($this->settings['ipcheck']))
                    $icepay->addToWhitelist($this->settings['ipcheck']);

                if ($icepay->validate()) {
                    $data = $icepay->GetPostback();

                    $order_id = $data->orderID;

                    $query = "SELECT * FROM `{$this->getTableWithPrefix('woocommerce_icepay_transactions')}` WHERE `id` = %d";
                    $ic_order = $wpdb->get_row($wpdb->prepare($query, $order_id));

                    $order = new WC_Order($ic_order->order_id);

                    if ($icepay->canUpdateStatus($ic_order->status)) {
                        switch ($data->status) {
                            case Icepay_StatusCode::ERROR:
                                $order->add_order_note($data->statusCode);
                                break;
                            case Icepay_StatusCode::OPEN:
                            case Icepay_StatusCode::AUTHORIZED:
                                $order->update_status('awaiting-payment');
                                break;
                            case Icepay_StatusCode::SUCCESS:
                                $order->status = 'pending';
                                $order->payment_complete();
                                break;
                            case Icepay_StatusCode::REFUND:
                                $order->update_status('refunded');
                                $order->add_order_note($data->statusCode);
                                break;
                            case Icepay_StatusCode::CHARGEBACK:
                                $order->update_status('cancelled');
                                $order->add_order_note($data->statusCode);
                                break;
                        }

                        $wpdb->update($this->getTableWithPrefix('woocommerce_icepay_transactions'), array('status' => $data->status, 'transaction_id' => $data->transactionID), array('id' => $order_id));
                    }
                } else {
                    if ($icepay->isVersionCheck()) {
                        $dump = array(
                            "module" => sprintf("ICEPAY Woocommerce payment module version %s using PHP API version %s", ICEPAY_Helper::getVersion(), Icepay_Project_Helper::getInstance()->getReleaseVersion()), //<--- Module version and PHP API version
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
                    $icepay->setMerchantID($this->settings['merchantid'])
                            ->setSecretCode($this->settings['secretcode']);
                } catch (Exception $e) {
                    echo "Postback URL installed successfully.";
                }

                if ($icepay->validate()) {
                    $order_id = $_GET['OrderID'];

                    $query = "SELECT * FROM `{$this->getTableWithPrefix('woocommerce_icepay_transactions')}` WHERE `id` = %d";
                    $ic_order = $wpdb->get_row($wpdb->prepare($query, $order_id));

                    $order = new WC_Order($ic_order->order_id);

                    switch ($icepay->getStatus()) {
                        case Icepay_StatusCode::ERROR:
                            $order->add_order_note('User cancelled order.');
                            $location = $order->get_cancel_order_url();
                            wp_safe_redirect($location);
                            exit();
                            break;
                        case Icepay_StatusCode::AUTHORIZED:
                        case Icepay_StatusCode::OPEN:
                        case Icepay_StatusCode::SUCCESS:
                            $woocommerce->cart->empty_cart();

                            $location = add_query_arg('key', $order->order_key, add_query_arg('order', $ic_order->order_id, get_permalink(woocommerce_get_page_id('thanks'))));
                            wp_safe_redirect($location);
                            exit();
                            break;
                    }
                }

                exit('Postback URL installed correctly.');
            }
        }

        public function getPaymentMethods() {
            global $wpdb;

            // SOAP Check          
            if (class_exists('SoapClient') === false) {
                echo ICEPAY_Helper::generateAjaxError(__('SOAP extension for PHP must be enabled. Please contact your webhoster.', 'icepay'));
                die();
            }

            try {
                $paymentService = Icepay_Api_Webservice::getInstance()->paymentMethodService();
                $paymentService->setMerchantID($this->settings['merchantid'])
                        ->setSecretCOde($this->settings['secretcode']);
                
                $paymentMethods = $paymentService->retrieveAllPaymentmethods()->asArray();

                $pmInfoTable = $this->getTableWithPrefix('woocommerce_icepay_pminfo');
                $pmRawDataTable = $this->getTableWithPrefix('woocommerce_icepay_pmrawdata');
                
                // Clear old raw paymentdata in database
                $wpdb->query("TRUNCATE `{$pmRawDataTable}`");
                
                // Clear old pminfo data in database
                $wpdb->query("TRUNCATE `{$pmInfoTable}`");
                
                // Store new raw paymentdata in database
                $wpdb->insert($pmRawDataTable, array('raw_pm_data' => serialize($paymentMethods)));
                
                if ($paymentMethods > 0) {
                    $i = 1;
                    $html = '';

                    foreach ($paymentMethods as $paymentMethod) {
                        $wpdb->insert($pmInfoTable, array('id' => $i, 'pm_code' => $paymentMethod['PaymentMethodCode'], 'pm_name' => $paymentMethod['Description']));

                        $html .= ICEPAY_Helper::generateAjaxListItems($paymentMethod, $i);

                        $i++;
                    }
                } else {
                    $html = ICEPAY_Helper::generateAjaxError(__('No active paymentmethods found for this merchant.', 'icepay'));
                }
            } catch (Exception $e) {
                $html = ICEPAY_Helper::generateAjaxError("An error occured: <b>{$e->getMessage()}</b>");
            }

            echo $html;

            // Always die on Ajax calls
            die();
        }

        public function admin_options() {
            global $wpdb;

            ob_start();
            $this->generate_settings_html();
            $settings = ob_get_contents();
            ob_end_clean();

            $paymentMethods = $wpdb->get_results("SELECT * FROM `{$this->getTableWithPrefix('woocommerce_icepay_pminfo')}`");

            $variables = array(
                '{image}' => plugins_url('', __FILE__) . '/assets/images/icepay-header.png',
                '{version}' => sprintf('%s %s', __('Module Version', 'icepay'), $this->version),
                '{manual}' => __('View the manual', 'icepay'),
                '{website}' => __('Visit the ICEPAY website', 'icepay'),
                '{settings}' => $settings,
                '{configure_text}' => __('To configure your paymentmethods, press the paymentmethod above or in the top navigation.', 'icepay'),
                '{refreshButtonValue}' => __('Refresh Payment Methods', 'icepay'),
                '{error}' => '',
                '{list}' => '',
                '{upgrade_notice}' => '',
            );

            ICEPAY_Helper::generateListItems($paymentMethods, $variables);

            if (ICEPAY_Helper::isUpgradeNoticeAvailable())
                ICEPAY_Helper::generateUpgradeNotice($variables);

            $template = file_get_contents(plugin_dir_path(__FILE__) . 'templates/admin.php');

            foreach ($variables as $key => $value) {
                $template = str_replace($key, $value, $template);
            }

            echo $template;
        }

        public function addGateway($methods) {
            global $wpdb;

            $methods[] = 'ICEPAY';

            $paymentMethodCount = $wpdb->get_var("SELECT count(id) FROM `{$this->getTableWithPrefix('woocommerce_icepay_pminfo')}`");

            if ($paymentMethodCount != null) {
                $i = 1;
                while ($i <= $paymentMethodCount) {
                    $methods[] = "ICEPAY_PaymentMethod_{$i}";
                    $i++;
                }
            }

            return $methods;
        }

        private function initForm() {
            $this->form_fields = array(
                'configuration' => array(
                    'title' => __('Set-up configuration', 'icepay'),
                    'type' => 'title'
                ),
                'postbackurl' => array(
                    'title' => __('Postback URL', 'icepay'),
                    'type' => 'text',
                    'class' => 'icepay-postback-url ic-input',
                    'description' => __('Copy-Paste this URL to the Success, Error and Postback section of your ICEPAY merchant account.', 'icepay'),
                    'desc_tip' => true
                ),
                'merchantid' => array(
                    'title' => __('Merchant ID', 'icepay'),
                    'type' => 'text',
                    'class' => 'ic-input',
                    'description' => __('Copy the Merchant ID from your ICEPAY account.', 'icepay'),
                    'desc_tip' => true
                ),
                'secretcode' => array(
                    'title' => __('Secretcode', 'icepay'),
                    'type' => 'text',
                    'class' => 'ic-input',
                    'description' => __('Copy the Secret Code from your ICEPAY account.', 'icepay'),
                    'desc_tip' => true
                ),
                'steptwo' => array(
                    'title' => __('Optional configuration', 'icepay'),
                    'type' => 'title'
                ),
                'descriptiontransaction' => array(
                    'title' => __('(Optional) Description on transaction statement of customer', 'icepay'),
                    'type' => 'text',
                    'class' => 'ic-input',
                    'description' => __('Some payment methods allow customized descriptions on the transaction statement. If left empty the Order ID is used. (Max 100 char.)', 'icepay'),
                    'desc_tip' => true
                ),
                'ipcheck' => array(
                    'title' => __('(Optional) Custom IP Range for IP Check for Postback', 'icepay'),
                    'type' => 'text',
                    'class' => 'ic-input',
                    'description' => __('For example a proxy: 1.222.333.444-100.222.333.444 For multiple ranges use a , seperator: 2.2.2.2-5.5.5.5,8.8.8.8-9.9.9.9', 'icepay'),
                    'desc_tip' => true
                ),
                'stepthree' => array(
                    'title' => __('PaymentMethods', 'icepay'),
                    'type' => 'title',
                    'class' => 'icpaymentmethods'
                )
            );
        }

        private function install() {
            global $wpdb;

            // Add custom status (To prevent user cancel - or re-pay on standard status pending)
            wp_insert_term(__('Awaiting Payment', 'icepay'), 'shop_order_status');

            // Install ICEPAY's transaction table
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$this->getTableWithPrefix('woocommerce_icepay_transactions')}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `order_id` int(11) NOT NULL,
                `status` varchar(255) NOT NULL,
                `transaction_id` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id`)
            )");

            // Install ICEPAY's pminfo table
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$this->getTableWithPrefix('woocommerce_icepay_pminfo')}` (
                `id` int(11) NOT NULL,            
                `pm_code` varchar(255),
                `pm_name` varchar(255)
            );");

            // Install ICEPAY's pmrawdata table
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$this->getTableWithPrefix('woocommerce_icepay_pmrawdata')}` (
                `raw_pm_data` LONGTEXT
            )");

            update_option('ICEPAY_Installed', true);
        }

        private function upgrade($version) {
            global $wpdb;

            switch ($version) {
                case '2.2.2':
                    // Changing raw_pm_data from TEXT to LONGTEXT, some databases couldn't handle it
                    $wpdb->query("ALTER TABLE `{$this->getTableWithPrefix('woocommerce_icepay_pmrawdata')}` CHANGE `raw_pm_data` `raw_pm_data` LONGTEXT NULL DEFAULT NULL");

                    // Using the wc-api listener (woocommerce_api) hook since 2.2.2 to be more effectice and maintain WooCommerce standards
                    ICEPAY_Helper::addUpgradeNotice(__('The postback URL has changed! Please copy the new postback URL and paste it into your ICEPAY account.', 'icepay'));
                    break;
            }            
        }

        private function getTableWithPrefix($tableName) {
            global $wpdb;

            return $wpdb->prefix . $tableName;
        }

    }

    class ICEPAY_Paymentmethod_Core extends WC_Payment_Gateway {

        public function __construct() {
            global $wpdb;

            $paymentMethod = $wpdb->get_row("SELECT * FROM `{$this->getTableWithPrefix('woocommerce_icepay_pminfo')}` WHERE `id` = '{$this->pmCode}'");

            $this->id = "ICEPAY_{$paymentMethod->pm_code}";
            $this->method_title = "ICEPAY {$paymentMethod->pm_name}";
            $this->paymentMethodCode = $paymentMethod->pm_code;

            if (ICEPAY_Helper::isOldWooCommerce()) {
                // WooCommerce 1.6.6 compatiblity
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            } else {
                // WooCommerce 2.0.0+
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
            }

            $this->form_fields = array(
                'stepone' => array(
                    'title' => $this->method_title,
                    'type' => 'title'
                ),
                'enabled' => array(
                    'title' => __('Active', 'icepay'),
                    'type' => 'checkbox',
                    'label' => ' ',
                    'description' => __('Enable or disable the payment method during the checkout process', 'icepay'),
                    'desc_tip' => true
                ),
                'displayname' => array(
                    'title' => __('Displayname', 'icepay'),
                    'type' => 'text',
                    'class' => 'ic-input',
                    'description' => __('Name that will be displayed during the checkout.', 'icepay'),
                    'desc_tip' => true
                )
            );

            $this->init_settings();
            
            if (empty($this->settings['displayname']))
                $this->settings['displayname'] = $paymentMethod->pm_name;

            $this->title = (ICEPAY_Helper::isOldWooCommerce()) ? $this->settings['displayname'] : $this->get_option('displayname');

            $this->iceCoreSettings = get_option($this->plugin_id . 'ICEPAY_settings', null);

            $paymentMethods = $wpdb->get_var("SELECT raw_pm_data FROM `{$this->getTableWithPrefix('woocommerce_icepay_pmrawdata')}`");

            if ($paymentMethods != null) {
                $paymentMethods = unserialize($wpdb->get_var("SELECT raw_pm_data FROM `{$this->getTableWithPrefix('woocommerce_icepay_pmrawdata')}`"));

                $method = Icepay_Api_Webservice::getInstance()->singleMethod()->loadFromArray($paymentMethods);
                $pMethod = $method->selectPaymentMethodByCode($paymentMethod->pm_code);

                $issuers = $pMethod->getIssuers();

                $output = sprintf("<input type='hidden' name='paymentMethod' value='%s' />", $paymentMethod->pm_code);
                
                $image = sprintf("%s/assets/images/%s.png", plugins_url('', __FILE__), strtolower($paymentMethod->pm_code));
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

                    $output .= "<select name='{$paymentMethod->pm_code}_issuer' style='width:164px; padding: 2px; margin-left: 7px;'>";
                    
                    //RoBe - Sumedia - 17-12-2013 - Added line to add a default option 'Choose your bank' instead of the first option to be the first issuer
                    $output .= "<option selected='selected' disabled='disabled'>Choose your bank</option>";
                    
                    foreach ($issuers as $issuer) {
                        $output .= sprintf("<option value='%s'>%s</option>", $issuer['IssuerKeyword'], __($issuer['IssuerKeyword'], 'icepay'));
                    }

                    $output .= '</select>';
                }
                
                $this->description = $output;
            }
        }

        public function admin_options() {
            ob_start();
            $this->generate_settings_html();
            $settings = ob_get_contents();
            ob_end_clean();

            $variables = array(
                '{image}' => plugins_url('', __FILE__) . '/assets/images/icepay-header.png',
                '{version}' => sprintf('%s %s', __('Module Version', 'icepay'), ICEPAY_Helper::getVersion()),
                '{manual}' => __('View the manual', 'icepay'),
                '{website}' => __('Visit the ICEPAY website', 'icepay'),
                '{settings}' => $settings
            );

            $template = file_get_contents(plugin_dir_path(__FILE__) . 'templates/admin_paymentmethod.php');

            foreach ($variables as $key => $value) {
                $template = str_replace($key, $value, $template);
            }

            echo $template;
        }

        public function process_payment($order_id) {
            global $wpdb, $woocommerce;

            $order = new WC_Order($order_id);

            // Get the order and fetch the order id
            $orderID = $order->id;

            try {
                $webservice = Icepay_Api_Webservice::getInstance()->paymentService();
                $webservice->addToExtendedCheckoutList(array('AFTERPAY'))
                        ->setMerchantID($this->iceCoreSettings['merchantid'])
                        ->setSecretCode($this->iceCoreSettings['secretcode']);

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
                    foreach ($order->get_items() as $item) {
                        $product = $order->get_product_from_item( $item );
                        
                        $pricePerProduct = $item['line_total'] / $item['qty'];

                        $taxRateMultiplier = round(($item['line_tax'] / $item['line_total']) + 1, 2);
                        $taxRatePercentage = round($item['line_tax'] / $item['line_total'] * 100, 2);

                        $price = round($pricePerProduct * $taxRateMultiplier, 2) * 100;

                        
                        Icepay_Order::getInstance()
                                ->addProduct(Icepay_Order_Product::create()
                                        ->setProductID($product->id)
                                        ->setProductName(htmlentities($product->post->post_title))
                                        ->setDescription(htmlentities($product->post->post_title))
                                        ->setQuantity($item['qty'])
                                        ->setUnitPrice($price)
                                        ->setVATCategory(Icepay_Order_VAT::getCategoryForPercentage($taxRatePercentage))
                        );

                        // WooCommerce calculates taxes per row instead of per unit price
                        // Sadly need to make an tax correction for Afterpay untill WooCommerce has tax calculation based on unit price.
                        $totalPriceTaxPerRow = ($item['line_tax'] + $item['line_total']) * 100;
                        $totalPriceTaxPerUnit = $price * $item['qty'];

                        $taxDifference = (int) (string) ($totalPriceTaxPerRow - $totalPriceTaxPerUnit);

                        if ($taxDifference < 10) {
                            Icepay_Order::getInstance()
                                    ->addProduct(Icepay_Order_Product::create()
                                            ->setProductID($product->id)
                                            ->setProductName(htmlentities($product->post->post_title))
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
                        $taxAmount = (int) (string) ($order->order_shipping_tax * 100);
                        $shippingCosts = ((int)(string) ($order->order_shipping * 100)) + $taxAmount;
                        Icepay_Order::getInstance()->setShippingCosts($shippingCosts, $taxRate);
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
                $ic_obj->amount = (int) (string) ($order->order_total * 100);

                // Get the billing country
                $ic_obj->country = $order->billing_country;

                // Get the used currency for the shop
                $ic_obj->currency = get_woocommerce_currency();

                // Get the Wordpress language and adjust format so ICEPAY accepts it.
                $language_locale = get_bloginfo('language');
                $ic_obj->language = strtoupper(substr($language_locale, 0, 2));

                // Get paymentclass
                $paymentMethods = unserialize($wpdb->get_var("SELECT raw_pm_data FROM `{$this->getTableWithPrefix('woocommerce_icepay_pmrawdata')}`"));

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

                $description = !empty($this->iceCoreSettings['descriptiontransaction']) ? $this->iceCoreSettings['descriptiontransaction'] : null;

                // Add transaction to ICEPAY table
                $wpdb->insert($this->getTableWithPrefix('woocommerce_icepay_transactions'), array('order_id' => $order_id, 'status' => Icepay_StatusCode::OPEN, 'transaction_id' => NULL));
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

                $order = new WC_Order($orderID);
                $order->add_order_note("Customer tried to make an attempt to complete the order but an error occured: {$message}");

                return false;
            }

            return array(
                'result' => 'success',
                'redirect' => $url
            );
        }

        private function getTableWithPrefix($tableName) {
            global $wpdb;

            return $wpdb->prefix . $tableName;
        }

    }

    for ($i = 1; $i < 15; $i++) {
        require(realpath(dirname(__FILE__)) . "/classes/placeholder/paymentmethod{$i}.php");
    }

    // Let's dance!
    new ICEPAY();
}
