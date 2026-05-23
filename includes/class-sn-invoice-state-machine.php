<?php
if (! defined('ABSPATH')) { exit; }

class SN_Invoice_State_Machine {
    public static function transition(int $invoice_id, string $to, string $reason = '', array $context = []): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sn_invoices';
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $invoice_id));
        if (! $invoice) { return false; }
        $from = (string) $invoice->status;
        if (! self::is_allowed($from, $to)) { return false; }
        $data = ['status' => $to];
        if ($to === 'paid_waiting_finance' || $to === 'paid' || $to === 'approved' || $to === 'finance_approved') {
            $data['payment_status'] = 'paid';
        }
        if (in_array($to, ['finance_approved','approved','completed'], true)) {
            $data['invoice_status'] = 'approved';
            $data['financial_return_state'] = 'approved';
        } elseif ($to === 'finance_rejected' || $to === 'rejected') {
            $data['invoice_status'] = 'rejected';
            $data['financial_return_state'] = 'rejected';
        }
        $ok = (bool) $wpdb->update($table, $data, ['id' => $invoice_id]);
        if ($ok) {
            if (class_exists('SN_Plugin') && method_exists('SN_Plugin', 'write_invoice_workflow_log_static')) {
                SN_Plugin::write_invoice_workflow_log_static($invoice_id, [
                    'lead_id' => (int) ($invoice->lead_id ?? 0),
                    'from_status' => $from,
                    'to_status' => $to,
                    'action_type' => 'state_transition',
                    'note' => $reason,
                    'actor_user_id' => get_current_user_id() ?: null,
                    'actor_role' => SN_Helpers::role_label((string) (wp_get_current_user()->roles[0] ?? 'system')),
                ]);
            }
            $wpdb->insert($wpdb->prefix . 'sn_logs', [
                'invoice_id' => $invoice_id,
                'action' => 'invoice_state_transition',
                'description' => sprintf('state: %s -> %s | %s', $from, $to, $reason),
                'meta' => wp_json_encode($context),
                'created_at' => current_time('mysql'),
            ]);
        }
        return $ok;
    }

    public static function is_allowed(string $from, string $to): bool {
        $map = [
            'draft' => ['issued','payment_pending','cancelled','pre_invoice','pending'],
            'pre_invoice' => ['pending','payment_pending','cancelled','paid_waiting_finance','rejected'],
            'issued' => ['payment_pending','cancelled'],
            'pending' => ['payment_pending','cancelled','paid_waiting_finance','rejected'],
            'payment_pending' => ['paid_waiting_finance','cancelled','rejected'],
            'paid_waiting_finance' => ['finance_approved','finance_rejected','completed'],
            'finance_rejected' => ['returned_to_seller','paid_waiting_finance'],
            'finance_approved' => ['completed'],
            'rejected' => ['pre_invoice','pending','paid_waiting_finance'],
        ];
        if ($from === $to) { return true; }
        return in_array($to, $map[$from] ?? [], true);
    }
}
