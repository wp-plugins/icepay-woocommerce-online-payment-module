<?php

class ICEPAY_Helper {

    public static function generateListItems($paymentMethods, &$variables) {
        if ($paymentMethods) {
            foreach ($paymentMethods as $paymentMethod) {
                $link = admin_url() . "admin.php?page=wc-settings&tab=checkout&section=icepay_paymentmethod_{$paymentMethod->id}";
                $variables['{list}'] .= "<li><a href='{$link}'>{$paymentMethod->pm_name}</a></li>";
            }
        } else {
            $variables['{error}'] = '<div class="error below-h2 ic_getpaymentmethods_error">'. __('No payment methods stored yet.', 'icepay') . '</div>';
        }
    }
    
    public static function generateAjaxListItems($paymentMethod, $i) {
        $link = admin_url() . "admin.php?page=wc-settings&tab=checkout&section=icepay_paymentmethod_{$i}";

        return "<li><a href='{$link}'>{$paymentMethod['Description']}</a></li>";
    }
    
    public static function generateAjaxError($message) {
        return "<div class='error ic_getpaymentmethods_error'>{$message}</div>";
    }

    public static function isIcepayPage($id) {
        if (isset($_GET['section']) && (stripos($_GET['section'], $id) !== false))
            return true;

        return false;
    }
    
    public static function getVersion() {
        return $version = '2.3.0';
    }
    
    public static function addUpgradeNotice($message) {
        update_option('ICEPAY_UpgradeNotice', $message);
    }
    
    public static function isUpgradeNoticeAvailable() {
        if (get_option('ICEPAY_UpgradeNotice', false))
            return true;        
        
        return false;
    }
    
    public static function getUpgradeNotice() {
        $flashMessage = get_option('ICEPAY_UpgradeNotice');
        
        update_option('ICEPAY_UpgradeNotice', false);
        
        return $flashMessage;
    }
    
    public static function generateUpgradeNotice(&$variables) {
        $upgradeNotice = self::getUpgradeNotice();
        $variables['{upgrade_notice}'] = "<div class='error below-h2 ic_getpaymentmethods_error'>Upgrade notice: {$upgradeNotice}</div>";
    }

}
