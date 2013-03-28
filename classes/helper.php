<?php

class ICEPAY_Helper {

    private static $version = '2.2.1';
    
    public static function generateListItems($paymentMethods, &$variables) {
        if ($paymentMethods) {
            foreach ($paymentMethods as $paymentMethod) {
                if (self::isOldWooCommerce()) {
                    $link = admin_url() . "admin.php?page=woocommerce_settings&tab=payment_gateways&saved=true&subtab=gateway-ICEPAY_{$paymentMethod->pm_code}";
                } else {
                    $link = admin_url() . "admin.php?page=woocommerce_settings&tab=payment_gateways&section=ICEPAY_PaymentMethod_{$paymentMethod->id}";
                }

                $variables['{list}'] .= "<li><a href='{$link}'>{$paymentMethod->pm_name}</a></li>";
            }
        } else {
            $variables['{error}'] = "<div class='error below-h2 ic_getpaymentmethods_error'>No paymentmethods stored yet.</div>";
        }
    }

    public static function generateAjaxListItems($paymentMethod, $i) {
        if (self::isOldWooCommerce()) {
            $link = admin_url() . "admin.php?page=woocommerce_settings&tab=payment_gateways&saved=true&subtab=gateway-ICEPAY_{$paymentMethod['PaymentMethodCode']}";
        } else {
            $link = admin_url() . "admin.php?page=woocommerce_settings&tab=payment_gateways&section=ICEPAY_PaymentMethod_{$i}";
        }

        return "<li><a href='{$link}'>{$paymentMethod['Description']}</a></li>";
    }

    public static function generateAjaxError($message) {
        return "<div class='error ic_getpaymentmethods_error'>{$message}</div>";
    }

    public static function isOldWooCommerce() {
        if (version_compare(WOOCOMMERCE_VERSION, '2.0', '>='))
            return false;
        
        return true;
    }

    public static function isIcepayPage($id) {
        if (self::isOldWooCommerce()) {
            if ((isset($_GET['page']) && $_GET['page'] == 'woocommerce_settings') && (isset($_GET['tab']) && $_GET['tab'] == 'payment_gateways')) {
                return true;
            }
        } else {
            if (isset($_GET['section']) && (strpos($_GET['section'], $id) !== false))
                return true;
        }
        
        return false;
    }
    
    public static function getVersion() {
        return self::$version;
    }

}
