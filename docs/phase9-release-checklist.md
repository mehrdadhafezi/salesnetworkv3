# Phase 9 — Final Regression & Release Hardening Checklist

## Static flow/action audit
Core action names verified unchanged for:
- seller login (`admin_post_nopriv_sn_seller_login`)
- seller leads (`wp_ajax_sn_seller_leads`)
- seller save customer info (`wp_ajax_sn_save_customer_info`)
- create invoice (`wp_ajax_sn_create_invoice`)
- invoice info (`wp_ajax_nopriv_sn_invoice_info`)
- online payment (`wp_ajax_nopriv_sn_pay_online`)
- zarinpal callback (`template_redirect -> handle_zarinpal_callback`)
- receipt upload (`wp_ajax_nopriv_sn_upload_receipt`)
- finance approve/reject (`wp_ajax_sn_financial_approve_payment`, `wp_ajax_sn_financial_reject_payment`)
- seller resend (`wp_ajax_sn_seller_resend_financial`)
- supervisor dashboard/data (`wp_ajax_sn_supervisor_data`)
- manager dashboard (`wp_ajax_sn_sales_manager_leads`)
- SMS test (`wp_ajax_sn_test_sms`)
- wallet display (`render_wallet_box_for_user`)

## Migration audit
- Versions in sequence: 1.0.33 -> 1.0.34 -> 1.0.35 -> 1.0.36 -> 1.0.37.
- No destructive drop/truncate migrations.
- `dbDelta` used for table create/upgrade compatible paths.
- Added indexes are additive and safe for upgrade/fresh install.

## AJAX security audit summary
### nopriv endpoints still present (required for customer invoice page)
- `sn_invoice_info`
- `sn_pay_online`
- `sn_upload_receipt`
- `sn_submit_manual_payment`
- `sn_invoice_recontact`
- `sn_spin_invoice_wheel`
- `sn_apply_invoice_wheel_reward`
- `sn_apply_invoice_coupon`
- `sn_remove_invoice_coupon`
- `sn_invoice_customer_action`
- `sn_invoice_customer_actions_batch`

All above operate on public invoice flow and are guarded by nonce/public-token checks via invoice public guards in plugin code.

### HR endpoints (no nopriv)
All `sn_hr_*` actions are `wp_ajax_` only, guarded by login + nonce + HR/admin capability (`sn_hr_guard`) and hierarchy checks in transfer/export actions.

## Payroll/wallet safety
- Salary ledger uniqueness protected by `uniq_user_period` on `(user_id, period_key)`.
- HR salary and commission wallet metadata fields:
  - `source_type`
  - `source_id`
  - `period_key`
  - `calculation_snapshot`
- Dry-run preview endpoint does not write ledger/wallet rows.
- Approve/paid status actions are explicit.

## UI/asset audit
- `public-hr.js` only loaded when shortcode map resolves to `sn_hr_panel`.
- No external CDN/fonts added by HR features.
- Persian/RTL labels preserved in HR panel.

## Manual install checklist
1. Install plugin on clean WordPress.
2. Activate plugin.
3. Open admin once to trigger migrations.
4. Verify HR tables and invoice log table are created.
5. Create HR page with `[sn_hr_panel]`.

## Manual upgrade checklist
1. Backup DB/files.
2. Replace plugin files.
3. Open admin to run migrations.
4. Verify old seller/supervisor/finance flows.
5. Verify HR backfill profiles created for existing users.

## Rollback notes
- Restore DB backup first, then plugin files.
- If rollback without DB restore, leave added columns/tables untouched (backward-compatible additive schema).

## Known limitations
- Full runtime E2E must be validated in live WP env with users/invoices.
- Export endpoint is JSON view, not file/PDF generation.
