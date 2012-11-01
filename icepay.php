<?php
########################################################################
#                                                             	       #
#           The property of ICEPAY www.icepay.eu                       #
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
 * @version 1.0.2
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
 * Version: 1.0.2
 * Author URI: http://www.icepay.com
 */
// Define constants
define('ICEPAY_VERSION', '1.0.2');
define('ICEPAY_TRANSACTION_TABLE', 'woocommerce_icepay_transactions');
define('ICEPAY_ERROR_LOG_TABLE', 'woocommerce_icepay_errors');

// Load ICEPAY translations
load_plugin_textdomain('icepay', false, dirname(plugin_basename(__FILE__)) . '/languages/');

// Require ICEPAY API
require(realpath(dirname(__FILE__)) . '/api/icepay_api_basic.php');

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
    $sql = "CREATE TABLE $table_name (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL,
        `status` varchar(255) NOT NULL,
        `transaction_id` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`)
    );";

    dbDelta($sql);

    $table_name = $wpdb->prefix . ICEPAY_ERROR_LOG_TABLE;
    $sql = "CREATE TABLE $table_name (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL,
        `datetime` datetime NOT NULL,
        `action` varchar(255) NOT NULL,
        `message` varchar(255) NOT NULL,
        PRIMARY KEY (`id`)
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

            $this->settings = ( array ) get_option('woocommerce_icepay_settings');
            $this->paymentMethodCode = $_SESSION['icepay_paymentmethods'][0][0];

            $this->id = "ICEPAY_{$this->paymentMethodCode}";
            $this->title = $this->settings["{$this->paymentMethodCode}displayname"];
            $this->method_title = 'icepay_';

            $paymentMethod = Icepay_Api_Basic::getInstance()->prepareFiltering()->getClassByPaymentMethodCode($this->paymentMethodCode);

            $issuers = $paymentMethod->getSupportedIssuers();

            $output = sprintf("<input type='hidden' name='paymentMethod' value='%s' />", $this->paymentMethodCode);

            $image = sprintf("%s/images/%s.png", plugins_url('', __FILE__), $this->paymentMethodCode);
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

                $output .= "<select name='{$this->paymentMethodCode}_issuer' style='width:164px; padding: 2px; margin-left: 7px;'>";
                foreach ($issuers as $issuer) {
                    $output .= sprintf("<option value='%s'>%s</option>", $issuer, __($issuer, 'icepay'));
                }
                $output .= "</select>";
            }

            $this->description = $output;

            if ($this->settings[$this->paymentMethodCode] == 'yes')
                $this->enabled = true;

            if (!$this->ICEPAY_ValidatePaymentMethod($paymentMethod)) {
                $this->enabled = false;
                $this->title .= sprintf("<span style='font-weight: normal; color: Red; margin-left: 2px;'><i>%s</i></span>", __("has been disabled because your webshop's configured currency is not supported by this payment method", 'icepay'));
            }

            array_shift($_SESSION['icepay_paymentmethods']);
        }

        private function ICEPAY_ValidatePaymentMethod($paymentMethod, $ic_obj = null) {
            global $woocommerce;

            $proceed = true;

            // Currency check
            if (!Icepay_Api_Basic::getInstance()->exists(get_woocommerce_currency(), $paymentMethod->getSupportedCurrency()))
                $proceed = false;

            if ($ic_obj) {
                // Country check
                if (!Icepay_Api_Basic::getInstance()->exists($ic_obj->country, $paymentMethod->getSupportedCountries())) {
                    $message = sprintf(__('Your country is not supported by %s', 'icepay'), $this->title);
                    $woocommerce->add_error(__('Payment error:', 'woothemes') . $message);
                    $proceed = false;
                }

                // Amount check
                $amountRange = $paymentMethod->getSupportedAmountRange();

                if (!$ic_obj->amount > $amountRange['minimum'] && $ic_obj->amount < $amountRange['maximum']) {
                    $message = sprintf(__('The amount of this order is not supported by %s', 'icepay'), $this->title);
                    $woocommerce->add_error(__('Payment error:', 'woothemes') . $message);
                    $proceed = false;
                }
            }

            return $proceed;
        }

        public function process_payment($order_id) {
            global $wpdb, $woocommerce;

            $order = &new woocommerce_order($order_id);

            // Initiate icepay object
            $ic_obj = new StdClass();

            // Get the grand total of order
            $ic_obj->amount = intval($order->order_total * 100);
            $ic_obj->country = $order->billing_country;

            // Get the used currency for the shop
            $ic_obj->currency = get_woocommerce_currency();

            // Get the Wordpress language and adjust format so ICEPAY accepts it.
            $language_locale = get_bloginfo('language');
            $ic_obj->language = strtoupper(substr($language_locale, 0, 2));

            // Get the order and fetch the order id
            $orderID = $order->id;

            $paymentMethod = explode('_', $order->payment_method);
            $pmCode = strtoupper($paymentMethod[1]);

            // Get paymentclass
            $paymentMethodClass = Icepay_Api_Basic::getInstance()
                    ->readFolder()
                    ->getClassByPaymentmethodCode($pmCode);

            if (!$this->ICEPAY_ValidatePaymentMethod($paymentMethodClass, $ic_obj)) {
                return;
            }

            $supportedIssuers = $paymentMethodClass->getSupportedIssuers();


            $issuerName = sprintf('%s_issuer', $paymentMethod[1]);

            if (isset($_POST[$issuerName])) {
                $issuer = $_POST[$issuerName];
            } elseif (count($supportedIssuers > 0)) {
                $issuer = $supportedIssuers[0];
            } else {
                $issuer = 'DEFAULT';
            }

            $supportedLanguages = $paymentMethodClass->getSupportedLanguages();

            if (count($supportedLanguages) == 1 && ($supportedLanguages[0] != '00'))
                $ic_obj->language = $supportedLanguages[0];

            $description = !empty($this->settings['descriptiontransaction']) ? $this->settings['descriptiontransaction'] : null;

            $paymentObj = new Icepay_PaymentObject();
            $paymentObj->setOrderID($orderID)
                    ->setDescription($description)
                    ->setReference($orderID)
                    ->setAmount(intval($ic_obj->amount))
                    ->setCurrency($ic_obj->currency)
                    ->setCountry($ic_obj->country)
                    ->setLanguage($ic_obj->language)
                    ->setPaymentMethod($pmCode)
                    ->setIssuer($issuer);

            // Validate payment object
            try {
                $basicmode = Icepay_Basicmode::getInstance();
                $basicmode->setMerchantID($this->settings['merchantid'])
                        ->setSecretCode($this->settings['secretcode'])
                        ->validatePayment($paymentObj);

                $basicmode->setProtocol('http');

                // Get payment url and redirect to paymentscreen
                $url = $basicmode->getURL();
            } catch (Exception $e) {
                $woocommerce->add_error(__('Payment error:', 'woothemes') . ' Something went wrong during your checkout, please try again. The webmaster has been contacted');

                $table_name = $wpdb->prefix . ICEPAY_ERROR_LOG_TABLE;
                $wpdb->insert($table_name, array('order_id' => $orderID, 'datetime' => date("Y-m-d H:i:s"), 'action' => 'create-order', 'message' => $e->getMessage()));

                return;
            }

            // Add transaction to ICEPAY table
            $table_name = $wpdb->prefix . ICEPAY_TRANSACTION_TABLE;
            $wpdb->insert($table_name, array('order_id' => $order_id, 'status' => Icepay_StatusCode::OPEN, 'transaction_id' => NULL));

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

            $this->method_title = 'ICEPAY';
            $this->id = 'ICEPAY';
            $this->title = 'ICEPAY';

            $this->ICEPAY_Form();
            $this->init_settings();

            $this->enabled = false;
            $this->settings['postbackurl'] = sprintf('%s/index.php?page=icepayresult', get_option('siteurl'));
        }

        // ICEPAY Form
        public function ICEPAY_Form() {
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
                    'title' => __('(Optional) Custom IP Range for IP Check for Postbackk', 'icepay'),
                    'type' => 'text',
                    'description' => __('For example a proxy: 1.222.333.444-100.222.333.444 For multiple ranges use a , seperator: 2.2.2.2-5.5.5.5,8.8.8.8-9.9.9.9', 'icepay'),
                    'css' => 'width: 300px;'
                )
            );

            $paymentMethods = Icepay_Api_Basic::getInstance()->readFolder()->getObject();


            foreach ($paymentMethods as $key => $paymentMethod) {
                $methodTitle = sprintf("%stitle", $key);
                $this->form_fields[$methodTitle] = array(
                    'title' => $paymentMethod->getReadableName(),
                    'type' => 'title'
                );
                $this->form_fields[$key] = array(
                    'title' => __('Active', 'icepay'),
                    'type' => 'checkbox',
                    'label' => ' '
                );

                $displayName = sprintf("%sdisplayname", $key);
                $this->form_fields[$displayName] = array(
                    'title' => __('Display name', 'icepay'),
                    'type' => 'text',
                    'css' => 'width: 300px;',
                    'default' => $paymentMethod->getReadableName()
                );
            }
        }

        // Sadly had to use jQuery to make it possible for multiple payment methods :'(
        public function admin_options() {
            global $wpdb, $woocommerce;

            $table_icepay = $wpdb->prefix . ICEPAY_ERROR_LOG_TABLE;

            if (isset($_GET['icepayaction']) && $_GET['icepayaction'] == 'markread') {
                $wpdb->query("UPDATE $table_icepay SET `read` = 'yes'");
            }

            if (isset($_GET['icepayaction']) && $_GET['icepayaction'] == 'clearlog') {
                $wpdb->query("DELETE FROM $table_icepay");
            }

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
                    jQuery('.icepay-error-table').hide();
                });
            ");

            // Hide Set-up configuration page
            $woocommerce->add_inline_js("                     
                jQuery('#icepay-payment').click(function(e){
                    jQuery('#gateway-ICEPAY table').nextAll('h4').hide().nextAll('table').hide().nextAll('table, h4').show();
                    jQuery('.icepay-error-table').hide();
                });               
            ");

            // Hide all tabs and show error log
            $woocommerce->add_inline_js("                     
                jQuery('#icepay-errors').click(function(e){                    
                    jQuery('#gateway-ICEPAY table,h4').hide();
                    jQuery('.icepay-error-table').show();
                });
            ");

            // Additional styling, and standards
            $woocommerce->add_inline_js("       
                jQuery('#gateway-ICEPAY').css('background-color', '#F5F5F5').find('h4').css({'padding-left' : '10px', 'margin' : '10px 0 0 0'});
                jQuery('#gateway-ICEPAY table').css('margin-left', '10px').nextAll('table').nextAll('table, h4').hide();
                jQuery('.submit').show();
            ");
            
            // Sadly have to move the WP footer, it's terrible absolute positioning is causing it overlapse with input fields
            // Note: We're not the only gateway with this problem.
            $woocommerce->add_inline_js("       
                jQuery('#footer').css('position', 'relative');
            ");

            $image = sprintf("%s/images/icepay-header.png", plugins_url('', __FILE__));

            $countUnreadErrors = $wpdb->get_var("SELECT count(*) FROM $table_icepay WHERE `read` = 'no'");
            $errorList = $wpdb->get_results("SELECT * FROM $table_icepay ORDER BY `id` DESC");
            ?>

            <div style="background-image: url(<?php echo $image; ?>); width: 100%; height: 134px;">
                <div style="padding-top: 95px; padding-left: 10px; font-size: 12px; float: left;">
                    <a href="#" id="icepay-setup" style="text-decoration: none;" class="button-primary"><?php _e('Set-up configuration', 'icepay'); ?></a>
                    <a href="#" id="icepay-payment" style="text-decoration: none; margin-left: 5px;" class="button-primary"><?php _e('Payment Methods', 'icepay'); ?></a>
                    <a href="#icepay-errors" id="icepay-errors" class="button-primary" style="text-decoration: none; margin-left: 5px;">Error Reports</a>
                    <?php if ($countUnreadErrors > 0) { ?>
                        <span style="display: inline-block; position: relative; left: -15px; top: -10px; width: 20px; height: 20px; background-color: Red; 
                              text-align: center; line-height: 20px; color: #fff; -webkit-border-radius: 100px; -moz-border-radius: 100px;
                              -ms-border-radius: 100px; -o-border-radius: 100px; border-radius: 100px; font-weight: bold; font-family: 'Times New Roman'';">
                              <?php echo $countUnreadErrors; ?>
                        </span>
                    <?php } ?>
                </div>
                <div style="padding-top: 95px; padding-right: 10px; font-size: 12px; float: right;">
                    <a href="http://www.icepay.com/downloads/pdf/manuals/wordpress-woocommerce/manual-wordpress-woocommerce.pdf" target="_BLANK" style="text-decoration: none;"><?php _e('View the manual', 'icepay'); ?></a> | 
                    <a href="http://www.icepay.eu" style="text-decoration: none;" target="_BLANK"><?php _e('Visit the ICEPAY website', 'icepay'); ?></a> |
                    Module Version <?php echo ICEPAY_VERSION; ?>
                </div>
            </div>

            <table class="icepay-settings">
                <?php $this->generate_settings_html(); ?>

                <table class="icepay-error-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <h3 class="icepay-error-header">ICEPAY Error log</h3>
                        </td>
                    </tr>
                    <?php if (!empty($errorList)) { ?>
                        <tr style="text-align: left;">
                            <th style="width: 130px;">Date time</th>
                            <th style="width: 70px;">Order ID</th>
                            <th style="width: 130px;">Action</th>
                            <th style="min-width: 600px;">Errormessage</th>
                        </tr>
                        <?php foreach ($errorList as $error) { ?>
                            <tr <?php
                    if ($error->read == 'no') {
                        echo "style='background-color: #E8E8E8;'";
                    }
                            ?>>
                                <td style="border-top: 1px solid #000; padding: 3px 0;"><?php echo $error->datetime; ?></td>
                                <td style="border-top: 1px solid #000; padding: 3px 0;"><?php echo $error->order_id; ?></td>
                                <td style="border-top: 1px solid #000; padding: 3px 0;"><?php echo $error->action; ?></td>
                                <td style="border-top: 1px solid #000; padding: 3px 0;"><?php echo $error->message; ?></td>
                            </tr>
                            <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td>No errors, <b>Wootastic!</b></td>
                        </tr>                        
                    <?php } ?>    
                </table>
                <table class="icepay-error-table">
                    <tr>
                        <?php if (!empty($errorList)) { ?>
                            <td style="padding-top: 10px; padding-bottom: 10px;"><a href="<?php echo $_SERVER["REQUEST_URI"] . '&icepayaction=markread'; ?>" id="icepay-payment" class="button" style="text-decoration: none; padding: 4px 10px;">Mark all as Read</a></td>
                            <td style="padding-top: 10px; padding-bottom: 10px;"><a href="<?php echo $_SERVER["REQUEST_URI"] . '&icepayaction=clearlog'; ?>" id="icepay-payment" class="button" style="text-decoration: none; padding: 4px 10px;">Clear Log</a></td>
                        <?php } ?>
                        <td style="padding-top: 10px; padding-bottom: 10px;"><a href="https://www.icepay.com//Merchant/EN/Support" id="icepay-payment" class="button" style="text-decoration: none; padding: 4px 10px;" target="_BLANK" >Contact ICEPAY Support</a></td>
                    </tr>
                </table>

            </div>
            </table>

            <?php
            if (empty($this->settings['merchantid']) && empty($this->settings['merchantid'])) {
                printf("<div class='error'><p><b>ICEPAY:</b> %s</p></div>", __(' Merchant ID and Secretcode must be set!', 'icepay'));
            }
        }

        // ICEPAY Response
        public function ICEPAY_Response() {
            global $wpdb, $wp_version, $woocommerce;


            if (isset($_GET['page']) && $_GET['page'] == 'icepayresult') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {

                    $icepay = Icepay_Project_Helper::getInstance()->postback();
                    $icepay->setMerchantID($this->settings['merchantid'])
                            ->setSecretCode($this->settings['secretcode'])
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
                        $ic_order = $wpdb->get_row("SELECT * FROM $table_icepay WHERE `order_id` = $order_id");
                        $order = &new WC_Order($order_id);

                        if ($icepay->canUpdateStatus($ic_order->status)) {
                            switch ($data->status) {
                                case Icepay_StatusCode::ERROR:
                                    $order->update_status('cancelled');
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

                            $wpdb->update($table_icepay, array('status' => $data->status, 'transaction_id' => $data->transactionID), array('order_id' => $order_id));
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
                    $order_id = $_GET['OrderID'];

                    $order = new WC_Order($order_id);

                    $icepay = Icepay_Project_Helper::getInstance()->result();
                    
                    try {
                        $icepay->setMerchantID($this->settings['merchantid'])
                               ->setSecretCode($this->settings['secretcode']);
                    } catch (Exception $e) {
                        echo "Postback URL installed successfully.";
                    }

                    if ($icepay->validate()) {
                        switch ($icepay->getStatus()) {
                            case Icepay_StatusCode::ERROR:
                                $order->add_order_note('User cancelled order.');
                                $location = $order->get_cancel_order_url();
                                wp_safe_redirect($location);
                                exit();
                                break;
                            case Icepay_StatusCode::OPEN:
                            case Icepay_StatusCode::SUCCESS:
                                $woocommerce->cart->empty_cart();

                                $location = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))));
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
            // Get ICEPAY paymentmethods
            $paymentMethods = Icepay_Api_Basic::getInstance()->readFolder()->getObject();

            // Unset session just to be sure ;-)
            unset($_SESSION['icepay_paymentmethods']);

            $i = 0;
            foreach (array_keys(( array ) $paymentMethods) as $key) {
                $gateway = 'WC_ICEPAY_Paymentmethod';
                $icepaySettings = ( array ) get_option('woocommerce_icepay_settings');

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