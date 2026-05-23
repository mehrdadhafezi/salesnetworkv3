<?php
if (! defined('ABSPATH')) { exit; }

class SN_HR
{
    public static function backfill_employee_profiles(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sn_hr_employee_profiles';
        $roles = ['sn_seller', 'sn_supervisor', 'sn_sales_manager'];
        $users = get_users(['role__in' => $roles, 'number' => 5000]);
        $inserted_count = 0;
        $existing_count = 0;
        $total_count = count($users);
        foreach ($users as $u) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d", $u->ID));
            if ($exists) { $existing_count++; continue; }
            $role_key = (string) (($u->roles[0] ?? ''));
            $parent = 0;
            if ($role_key === 'sn_seller') {
                $parent = (int) get_user_meta($u->ID, 'sn_supervisor_id', true);
            }
            $ok = $wpdb->insert($table, [
                'user_id' => (int) $u->ID,
                'employee_code' => 'EMP-' . (int) $u->ID,
                'national_code' => null,
                'phone' => (string) ($u->user_login ?: get_user_meta($u->ID, 'billing_phone', true)),
                'full_name' => (string) ($u->display_name ?: $u->user_login),
                'role_key' => $role_key,
                'level_id' => null,
                'parent_user_id' => $parent ?: null,
                'employment_status' => 'contract',
                'base_salary' => 0,
                'is_active' => 1,
                'hired_at' => null,
                'left_at' => null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            if ($ok) { $inserted_count++; }
        }
        return [
            'inserted_count' => $inserted_count,
            'existing_count' => $existing_count,
            'total_count' => $total_count,
        ];
    }
}
