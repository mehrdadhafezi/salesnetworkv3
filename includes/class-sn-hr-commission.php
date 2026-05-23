<?php
if (! defined('ABSPATH')) { exit; }

class SN_HR_Commission
{
    public static function get_active_models(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_hr_commission_models WHERE is_active=1 ORDER BY id DESC", ARRAY_A) ?: [];
    }
}
