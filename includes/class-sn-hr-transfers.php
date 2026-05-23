<?php
if (! defined('ABSPATH')) { exit; }

class SN_HR_Transfers
{
    public static function log(int $request_id, string $action_type, ?string $from_status, ?string $to_status, string $note = ''): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'sn_hr_transfer_logs', [
            'transfer_request_id' => $request_id,
            'actor_user_id' => get_current_user_id() ?: null,
            'action_type' => sanitize_key($action_type),
            'from_status' => $from_status,
            'to_status' => $to_status,
            'note' => sanitize_textarea_field($note),
            'created_at' => current_time('mysql'),
        ]);
    }
}
