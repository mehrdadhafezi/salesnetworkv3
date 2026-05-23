<?php
if (! defined('ABSPATH')) {
	exit;
}

class SN_Plugin
{

	private function asset_version(string $relative_path): string
	{
		$relative_path = ltrim($relative_path, '/');
		$file = SN_PLUGIN_DIR . $relative_path;
		$mtime = file_exists($file) ? (string) filemtime($file) : '0';
		return SN_VERSION . '-' . $mtime;
	}


	private function sn_stats_definitions(): array
	{
		return [
			// لید کل: تمام رکوردهای جدول لید.
			'lead_total' => "1=1",
			// لید تخصیص‌یافته: لیدی که فروشنده دارد.
			'lead_assigned' => "seller_id IS NOT NULL",
			// لید فعال: لیدی که هنوز تبدیل/بسته نشده است.
			'lead_active' => "status IN ('assigned','supervisor_pool','unassigned')",
			// لید تکمیل‌شده: لید تبدیل‌شده به فروش.
			'lead_completed' => "status='invoiced'",
			// فاکتور صادرشده: هر فاکتور ثبت‌شده.
			'invoice_issued' => "1=1",
			// پرداخت در انتظار بررسی مالی.
			'payment_pending_review' => "(status IN ('pending_financial_approval','receipt_uploaded') OR payment_status IN ('pending_financial_approval','receipt_uploaded') OR invoice_status IN ('pending_financial_approval','receipt_uploaded'))",
			// پرداخت تأییدشده مالی.
			'payment_financial_approved' => "(status IN ('approved','finance_approved','completed') OR payment_status='approved' OR invoice_status='approved' OR financial_return_state='approved')",
			// پرداخت ردشده مالی.
			'payment_financial_rejected' => "(status IN ('rejected','finance_rejected') OR payment_status='rejected' OR invoice_status='rejected' OR financial_return_state='rejected')",
			// فاکتور برگشتی به فروشنده.
			'invoice_returned_to_seller' => "financial_return_state='returned_to_seller'",
			// ووچر خریداری‌شده: فاکتور پرداخت‌شده/تاییدشده.
			'voucher_purchased' => "(status IN ('paid','approved','finance_approved','completed') OR payment_status='approved' OR invoice_status='approved')",
			// ووچر استفاده‌شده: فعلاً completed را استفاده‌شده درنظر می‌گیریم.
			'voucher_used' => "status='completed'",
		];
	}

	private function sn_count_metric(string $entity, string $metric, array $extra_where = [], array $params = []): int
	{
		global $wpdb;
		$defs = $this->sn_stats_definitions();
		$base = $defs[$metric] ?? '1=0';
		$table = $entity === 'lead' ? "{$wpdb->prefix}sn_leads" : "{$wpdb->prefix}sn_invoices";
		$where = array_merge([$base], $extra_where);
		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', array_map(fn($w)=>"($w)", $where));
		return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
	}

	private function sn_sum_metric_amount(string $metric, array $extra_where = [], array $params = []): float
	{
		global $wpdb;
		$defs = $this->sn_stats_definitions();
		$base = $defs[$metric] ?? '1=0';
		$where = array_merge([$base], $extra_where);
		$sql = "SELECT COALESCE(SUM(COALESCE(final_total, product_price, 0)),0) FROM {$wpdb->prefix}sn_invoices WHERE " . implode(' AND ', array_map(fn($w)=>"($w)", $where));
		return (float) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
	}

	private function sn_commission_metrics(): array
	{
		global $wpdb;
		$payable = (float) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}sn_wallet_transactions WHERE direction='credit' AND type IN ('seller_commission','supervisor_commission')");
		$paid = (float) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}sn_wallet_transactions WHERE direction='debit' AND type='settlement'");
		return ['commission_payable' => $payable, 'commission_paid' => $paid];
	}

	private function sn_report_status_debug_counts(): array
	{
		global $wpdb;
		return [
			'status' => $wpdb->get_results("SELECT status AS label, COUNT(*) AS cnt FROM {$wpdb->prefix}sn_invoices GROUP BY status ORDER BY cnt DESC", ARRAY_A),
			'invoice_status' => $wpdb->get_results("SELECT COALESCE(invoice_status,'(null)') AS label, COUNT(*) AS cnt FROM {$wpdb->prefix}sn_invoices GROUP BY invoice_status ORDER BY cnt DESC", ARRAY_A),
			'payment_status' => $wpdb->get_results("SELECT COALESCE(payment_status,'(null)') AS label, COUNT(*) AS cnt FROM {$wpdb->prefix}sn_invoices GROUP BY payment_status ORDER BY cnt DESC", ARRAY_A),
			'financial_return_state' => $wpdb->get_results("SELECT COALESCE(financial_return_state,'(null)') AS label, COUNT(*) AS cnt FROM {$wpdb->prefix}sn_invoices GROUP BY financial_return_state ORDER BY cnt DESC", ARRAY_A),
		];
	}

	public function run(): void
	{
		// غیرفعال کردن jwt در AJAX های پلاگین ما — باید priority 1 باشه
		add_action('init', [$this, 'disable_jwt_on_ajax'], 1);

		add_action('init',                [$this, 'register_shortcodes']);
		add_action('admin_menu',          [$this, 'register_admin_menu']);
		add_action('wp_enqueue_scripts',  [$this, 'enqueue_public_assets']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_action('admin_init',            ['SN_Migration_Manager', 'maybe_run']);
		add_action('admin_init',            [$this, 'handle_admin_export']);
		add_action('admin_init',            [$this, 'block_front_roles_admin_access'], 1);
		add_action('after_setup_theme',     [$this, 'hide_front_roles_admin_bar'], 1);
		add_action('init',                  [$this, 'harden_front_roles_caps'], 3);
		add_action('init',                  [$this, 'ensure_finance_role'], 4);
		add_action('init',                  [$this, 'ensure_after_sales_role'], 5);
		add_action('init',                  [$this, 'ensure_sales_manager_role'], 5);
		add_action('init',                  [$this, 'ensure_wallet_tables'], 6);
		add_action('init',                  [$this, 'ensure_hr_role'], 7);
		add_action('wp_enqueue_scripts',    [$this, 'dequeue_external_fonts'], 99);
		add_filter('http_request_host_is_external', [$this, 'allow_sales_network_payment_sms_hosts'], 10, 3);

		// غیرفعال کردن JWT plugin در AJAX requests ما (تا headers خراب نشه)
		add_filter('jwt_auth_whitelist',        [$this, 'jwt_whitelist_ajax']);
		add_filter('jwt_auth_default_whitelist', [$this, 'jwt_whitelist_ajax']);
		// جلوگیری از output JWT قبل از AJAX response
		if (wp_doing_ajax()) {
			remove_action('rest_api_init', ['Jwt_Auth_Public', 'add_api_routes']);
			add_action('init', function () {
				remove_action('rest_api_init', ['Jwt_Auth_Public', 'add_api_routes']);
				// حذف فیلتر JWT که header میفرسته
				remove_filter('rest_pre_serve_request', ['Jwt_Auth_Public', 'rest_pre_serve_request']);
			}, 1);
		}

		// Product metabox (WooCommerce)
		add_action('add_meta_boxes_product', [$this, 'register_product_metabox']);
		add_action('save_post_product',      [$this, 'save_product_meta'], 10, 2);

		// Admin AJAX
		add_action('wp_ajax_sn_import_leads',      [$this, 'ajax_import_leads']);
		add_action('wp_ajax_sn_assign_leads',      [$this, 'ajax_assign_leads']);
		add_action('wp_ajax_sn_assign_supervisor_leads', [$this, 'ajax_assign_supervisor_leads']);
		add_action('wp_ajax_sn_sales_manager_leads', [$this, 'ajax_sales_manager_leads']);
		add_action('wp_ajax_sn_save_seller_supervisor', [$this, 'ajax_save_seller_supervisor']);
		add_action('wp_ajax_sn_bulk_seller_action', [$this, 'ajax_bulk_seller_action']);
		add_action('wp_ajax_sn_save_settings',     [$this, 'ajax_save_settings']);
		add_action('wp_ajax_sn_repair_pages',      [$this, 'ajax_repair_pages']);
		add_action('wp_ajax_sn_wallet_manual_adjust', [$this, 'ajax_wallet_manual_adjust']);
		add_action('wp_ajax_sn_wallet_recalculate', [$this, 'ajax_wallet_recalculate']);
		add_action('wp_ajax_sn_hr_list_employees', [$this, 'ajax_hr_list_employees']);
		add_action('wp_ajax_sn_hr_update_employee', [$this, 'ajax_hr_update_employee']);
		add_action('wp_ajax_sn_hr_levels', [$this, 'ajax_hr_levels']);
		add_action('wp_ajax_sn_hr_save_level', [$this, 'ajax_hr_save_level']);
		add_action('wp_ajax_sn_hr_commission_models', [$this, 'ajax_hr_commission_models']);
		add_action('wp_ajax_sn_hr_save_commission_model', [$this, 'ajax_hr_save_commission_model']);
		add_action('wp_ajax_sn_hr_transfer_create', [$this, 'ajax_hr_transfer_create']);
		add_action('wp_ajax_sn_hr_transfer_list', [$this, 'ajax_hr_transfer_list']);
		add_action('wp_ajax_sn_hr_transfer_action', [$this, 'ajax_hr_transfer_action']);
		add_action('wp_ajax_sn_hr_employee_export', [$this, 'ajax_hr_employee_export']);
		add_action('wp_ajax_sn_hr_payroll_preview', [$this, 'ajax_hr_payroll_preview']);
		add_action('wp_ajax_sn_hr_payroll_generate', [$this, 'ajax_hr_payroll_generate']);
		add_action('wp_ajax_sn_hr_payroll_approve', [$this, 'ajax_hr_payroll_approve']);
		add_action('wp_ajax_sn_hr_payroll_mark_paid', [$this, 'ajax_hr_payroll_mark_paid']);
		add_action('wp_ajax_sn_hr_backfill_employees', [$this, 'ajax_hr_backfill_employees']);
		add_action('wp_ajax_sn_hr_seed_levels', [$this, 'ajax_hr_seed_levels']);
		add_action('wp_ajax_sn_hr_diagnostics', [$this, 'ajax_hr_diagnostics']);

		// Seller AJAX
		add_action('wp_ajax_sn_save_customer_info',        [$this, 'ajax_save_customer_info']);
		add_action('wp_ajax_sn_toggle_seller_active',      [$this, 'ajax_toggle_seller_active']);
		add_action('wp_ajax_sn_create_invoice',        [$this, 'ajax_create_invoice']);
		// fallback: form POST برای محیط‌هایی که AJAX خراب میشه
		add_action('admin_post_sn_create_invoice',        [$this, 'handle_create_invoice_post']);
		add_action('admin_post_nopriv_sn_create_invoice', [$this, 'handle_create_invoice_post']);
		add_action('wp_ajax_sn_seller_leads',        [$this, 'ajax_seller_leads']);
		add_action('wp_ajax_sn_seller_invoices',        [$this, 'ajax_seller_invoices']);

		// Supervisor AJAX
		add_action('wp_ajax_sn_supervisor_data',   [$this, 'ajax_supervisor_data']);
		add_action('wp_ajax_sn_get_unassigned',    [$this, 'ajax_get_unassigned']);
		add_action('wp_ajax_sn_supervisor_unassign_leads', [$this, 'ajax_supervisor_unassign_leads']);
		add_action('wp_ajax_sn_lead_profile', [$this, 'ajax_lead_profile']);
		add_action('wp_ajax_sn_customer_profile_search', [$this, 'ajax_customer_profile_search']);
		add_action('wp_ajax_sn_seller_profile', [$this, 'ajax_seller_profile']);
		add_action('wp_ajax_sn_supervisor_upload_receipt', [$this, 'ajax_supervisor_upload_receipt']);
		add_action('wp_ajax_sn_supervisor_invoices', [$this, 'ajax_supervisor_invoices']);

		// Admin confirm/reject card payment
		add_action('wp_ajax_sn_confirm_card_payment', [$this, 'ajax_confirm_card_payment']);
		add_action('wp_ajax_sn_reject_receipt',       [$this, 'ajax_reject_receipt']);
		add_action('wp_ajax_sn_admin_change_status',  [$this, 'ajax_admin_change_invoice_status']);
		add_action('wp_ajax_sn_financial_approve_payment', [$this, 'ajax_financial_approve_payment']);
		add_action('wp_ajax_sn_financial_reject_payment', [$this, 'ajax_financial_reject_payment']);
		add_action('wp_ajax_sn_financial_invoices', [$this, 'ajax_financial_invoices']);
		add_action('wp_ajax_sn_seller_resend_financial', [$this, 'ajax_seller_resend_financial']);
		add_action('wp_ajax_sn_invoice_logs', [$this, 'ajax_invoice_logs']);

		// Public (no login) - صفحه فاکتور مشتری
		add_action('wp_ajax_nopriv_sn_invoice_info',    [$this, 'ajax_invoice_info']);
		add_action('wp_ajax_sn_invoice_info',           [$this, 'ajax_invoice_info']);
		add_action('wp_ajax_nopriv_sn_pay_online',      [$this, 'ajax_pay_online']);
		add_action('wp_ajax_sn_pay_online',             [$this, 'ajax_pay_online']);
		add_action('wp_ajax_nopriv_sn_upload_receipt',  [$this, 'ajax_upload_receipt']);
		add_action('wp_ajax_sn_upload_receipt',         [$this, 'ajax_upload_receipt']);
		add_action('wp_ajax_nopriv_sn_submit_manual_payment', [$this, 'ajax_submit_manual_payment']);
		add_action('wp_ajax_sn_submit_manual_payment', [$this, 'ajax_submit_manual_payment']);
		add_action('wp_ajax_nopriv_sn_invoice_recontact', [$this, 'ajax_invoice_recontact']);
		add_action('wp_ajax_sn_invoice_recontact', [$this, 'ajax_invoice_recontact']);
		add_action('wp_ajax_nopriv_sn_spin_invoice_wheel', [$this, 'ajax_spin_invoice_wheel']);
		add_action('wp_ajax_sn_spin_invoice_wheel', [$this, 'ajax_spin_invoice_wheel']);
		add_action('wp_ajax_nopriv_sn_apply_invoice_wheel_reward', [$this, 'ajax_apply_invoice_wheel_reward']);
		add_action('wp_ajax_sn_apply_invoice_wheel_reward', [$this, 'ajax_apply_invoice_wheel_reward']);
		add_action('wp_ajax_nopriv_sn_apply_invoice_coupon', [$this, 'ajax_apply_invoice_coupon']);
		add_action('wp_ajax_sn_apply_invoice_coupon', [$this, 'ajax_apply_invoice_coupon']);
		add_action('wp_ajax_nopriv_sn_remove_invoice_coupon', [$this, 'ajax_remove_invoice_coupon']);
		add_action('wp_ajax_sn_remove_invoice_coupon', [$this, 'ajax_remove_invoice_coupon']);
		add_action('wp_ajax_nopriv_sn_invoice_customer_action', [$this, 'ajax_invoice_customer_action']);
		add_action('wp_ajax_sn_invoice_customer_action', [$this, 'ajax_invoice_customer_action']);
		add_action('wp_ajax_nopriv_sn_invoice_customer_actions_batch', [$this, 'ajax_invoice_customer_actions_batch']);
		add_action('wp_ajax_sn_invoice_customer_actions_batch', [$this, 'ajax_invoice_customer_actions_batch']);
		add_action('wp_ajax_sn_seller_customer_actions', [$this, 'ajax_seller_customer_actions']);

		// ZarinPal callback
		add_action('template_redirect', [$this, 'handle_zarinpal_callback']);

		// Auth actions (public form)
		add_action('admin_post_nopriv_sn_seller_login',    [$this, 'handle_seller_login']);
		add_action('admin_post_sn_seller_login',           [$this, 'handle_seller_login']);
		add_action('admin_post_nopriv_sn_seller_register', [$this, 'handle_seller_register']);
		add_action('admin_post_sn_seller_register',        [$this, 'handle_seller_register']);
		add_action('admin_post_sn_seller_logout',          [$this, 'handle_seller_logout']);
		add_action('admin_post_nopriv_sn_financial_login', [$this, 'handle_financial_login']);
		add_action('admin_post_sn_financial_login',        [$this, 'handle_financial_login']);
		add_action('admin_post_sn_financial_logout',       [$this, 'handle_seller_logout']);
		add_action('admin_post_nopriv_sn_supervisor_login', [$this, 'handle_supervisor_login']);
		add_action('admin_post_sn_supervisor_login',        [$this, 'handle_supervisor_login']);
		add_action('admin_post_nopriv_sn_sales_manager_login', [$this, 'handle_sales_manager_login']);
		add_action('admin_post_sn_sales_manager_login',        [$this, 'handle_sales_manager_login']);
		add_action('admin_post_sn_sales_manager_export',       [$this, 'handle_sales_manager_export']);

		// جلوگیری از redirect به wp-admin برای فروشندگان
		add_filter('login_redirect',   [$this, 'seller_login_redirect'], 10, 3);
		add_action('wp_login',         [$this, 'block_seller_admin_access'], 10, 2);

		// WooCommerce My Account tab
		add_filter('woocommerce_account_menu_items',          [$this, 'add_myaccount_menu_item']);
		add_action('woocommerce_account_sn-invoices_endpoint', [$this, 'render_myaccount_invoices']);
		add_action('init',                                    [$this, 'register_myaccount_endpoint'], 5);

		// After invoice paid — grant subscription content
		add_action('sn_invoice_paid', [$this, 'on_invoice_paid'], 10, 2);

		// تست پیامک از ادمین
		add_action('wp_ajax_sn_test_sms', [$this, 'ajax_test_sms']);

		// وضعیت lead
		add_action('wp_ajax_sn_update_lead_status',       [$this, 'ajax_update_lead_status']);
		add_action('wp_ajax_sn_get_lead_statuses',          [$this, 'ajax_get_lead_statuses']);
		add_action('wp_ajax_sn_save_statuses',      [$this, 'ajax_save_statuses']);
	}


	public function ensure_required_pages(): void
	{
		// intentionally no-op: pages must be created by explicit admin action.
	}

	// =========================================================
	// SHORTCODES
	// =========================================================

	public function register_shortcodes(): void
	{
		add_shortcode('sn_seller_panel',     [$this, 'render_seller_panel']);
		add_shortcode('sn_supervisor_panel', [$this, 'render_supervisor_panel']);
		add_shortcode('sn_sales_manager_auth', [$this, 'render_sales_manager_auth']);
		add_shortcode('sn_sales_manager_panel', [$this, 'render_sales_manager_panel']);
		add_shortcode('sn_after_sales_panel', [$this, 'render_after_sales_panel']);
		add_shortcode('sn_financial_auth', [$this, 'render_financial_auth']);
		add_shortcode('sn_financial_panel', [$this, 'render_financial_panel']);
		add_shortcode('sn_invoice_page',     [$this, 'render_invoice_page']);
		add_shortcode('sn_auth',             [$this, 'render_auth']);
		add_shortcode('sn_supervisor_auth',  [$this, 'render_supervisor_auth']);
		add_shortcode('sn_hr_panel', [$this, 'render_hr_panel']);
	}

	// =========================================================
	// ASSETS
	// =========================================================

	public function enqueue_public_assets(): void
	{
		global $post;

		$shortcode_assets = [
			'sn_seller_panel'        => 'seller',
			'sn_supervisor_panel'    => 'supervisor',
			'sn_sales_manager_panel' => 'manager',
			'sn_after_sales_panel'   => 'manager',
			'sn_financial_panel'     => 'manager',
			'sn_invoice_page'        => 'invoice',
			'sn_auth'                => 'auth',
			'sn_supervisor_auth'     => 'auth',
			'sn_sales_manager_auth'  => 'auth',
			'sn_financial_auth'      => 'auth',
			'sn_hr_panel'            => 'hr',
		];

		$asset_key = '';
		if ($post && is_singular()) {
			foreach ($shortcode_assets as $sc => $key) {
				if (has_shortcode((string) $post->post_content, $sc)) {
					$asset_key = $key;
					break;
				}
			}
		}

		// در صفحه My Account فقط CSS لازم است؛ JS پنل‌ها نباید بی‌دلیل لود شود.
		if (! $asset_key && function_exists('is_account_page') && is_account_page()) {
			wp_enqueue_style('sn-public', SN_PLUGIN_URL . 'assets/css/public.css', [], $this->asset_version('assets/css/public.css'));
			wp_enqueue_style('sn-public-performance', SN_PLUGIN_URL . 'assets/css/public-performance.css', ['sn-public'], $this->asset_version('assets/css/public-performance.css'));
			return;
		}

		if (! $asset_key) {
			return;
		}

		wp_enqueue_style('sn-public', SN_PLUGIN_URL . 'assets/css/public.css', [], $this->asset_version('assets/css/public.css'));
		wp_enqueue_style('sn-public-performance', SN_PLUGIN_URL . 'assets/css/public-performance.css', ['sn-public'], $this->asset_version('assets/css/public-performance.css'));

		$script_map = [
			'seller'     => 'public-seller.js',
			'supervisor' => 'public-supervisor.js',
			'manager'    => 'public-manager.js',
			'invoice'    => 'public-invoice.js',
			'auth'       => 'public-auth.js',
			'hr'         => 'public-hr.js',
		];
		$script_file = $script_map[$asset_key] ?? 'public-auth.js';
		$handle = 'sn-public-' . $asset_key;

		wp_enqueue_script($handle, SN_PLUGIN_URL . 'assets/js/' . $script_file, ['jquery'], $this->asset_version('assets/js/' . $script_file), true);
		$sn_public_data = [
			'ajaxurl'      => admin_url('admin-ajax.php'),
			'nonce'        => wp_create_nonce('sn_public'),
			'admin_nonce'  => (current_user_can('manage_options') || current_user_can('sn_manage_supervisor_leads')) ? wp_create_nonce('sn_admin') : '',
			'current_user' => get_current_user_id(),
			'asset_key'    => $asset_key,
		];
		wp_localize_script($handle, 'snAjax', $sn_public_data);
		wp_localize_script($handle, 'snData', $sn_public_data);
	}
	public function dequeue_external_fonts(): void
	{
		global $wp_styles;
		if (empty($wp_styles) || empty($wp_styles->registered)) {
			return;
		}
		foreach ($wp_styles->registered as $handle => $style) {
			$src = isset($style->src) ? (string) $style->src : '';
			if (false !== strpos($src, 'fonts.googleapis.com') || false !== strpos($src, 'fonts.gstatic.com')) {
				wp_dequeue_style($handle);
				wp_deregister_style($handle);
			}
		}
	}

	// ساخت جداول در صورت نبود (بدون نیاز به deactivate/activate)
	// غیرفعال کردن jwt-authentication در AJAX — این پلاگین header میفرسته و AJAX ما رو خراب میکنه
	public function disable_jwt_on_ajax(): void
	{
		if (! defined('DOING_AJAX') || ! DOING_AJAX) {
			return;
		}
		$action = $_REQUEST['action'] ?? '';
		// فقط برای action های پلاگین ما
		if (strpos($action, 'sn_') !== 0) {
			return;
		}
		// حذف filter هایی که jwt اضافه کرده
		remove_all_filters('rest_api_init');
		// جلوگیری از اجرای jwt در این request
		if (class_exists('Jwt_Auth_Public')) {
			remove_action('init', ['Jwt_Auth_Public', 'add_api_routes']);
		}
		// پاک کردن output buffer از همان ابتدا
		if (! ob_get_level()) {
			ob_start();
		}
	}

	public function maybe_create_tables(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		// ---- 1. جدول sn_lead_statuses ----
		$st_table = $wpdb->prefix . 'sn_lead_statuses';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$st_table}'") !== $st_table) {
			dbDelta("CREATE TABLE {$st_table} (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				label VARCHAR(100) NOT NULL,
				color VARCHAR(20) NOT NULL DEFAULT '#6b7280',
				sort_order INT NOT NULL DEFAULT 0,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				destination_panel VARCHAR(50) DEFAULT NULL,
				move_to_destination TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
			) {$charset};");
			$defaults = [
				['label' => 'جواب نداده', 'color' => '#f59e0b', 'sort_order' => 1],
				['label' => 'تماس مجدد', 'color' => '#3b82f6', 'sort_order' => 2],
				['label' => 'علاقه‌مند',  'color' => '#10b981', 'sort_order' => 3],
				['label' => 'در بررسی',   'color' => '#8b5cf6', 'sort_order' => 4],
				['label' => 'کنسل',       'color' => '#ef4444', 'sort_order' => 5],
				['label' => 'خرید کرده',  'color' => '#22c55e', 'sort_order' => 6],
			];
			foreach ($defaults as $s) {
				$wpdb->insert($st_table, $s);
			}
		}
		if ($wpdb->get_var("SHOW TABLES LIKE '{$st_table}'") === $st_table) {
			$st_cols = $wpdb->get_col("SHOW COLUMNS FROM {$st_table}", 0);
			if (! in_array('destination_panel', $st_cols, true)) { $wpdb->query("ALTER TABLE {$st_table} ADD COLUMN destination_panel VARCHAR(50) DEFAULT NULL AFTER is_active"); }
			if (! in_array('move_to_destination', $st_cols, true)) { $wpdb->query("ALTER TABLE {$st_table} ADD COLUMN move_to_destination TINYINT(1) NOT NULL DEFAULT 0 AFTER destination_panel"); }
		}


		// ---- جداول لاگ و تاریخچه وضعیت ----
		dbDelta("CREATE TABLE {$wpdb->prefix}sn_activity_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(120) NOT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			description TEXT DEFAULT NULL,
			context LONGTEXT DEFAULT NULL,
			ip_address VARCHAR(64) DEFAULT NULL,
			user_agent TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY invoice_id (invoice_id),
			KEY user_id (user_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset};");

		dbDelta("CREATE TABLE {$wpdb->prefix}sn_lead_status_history (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			old_status VARCHAR(100) DEFAULT NULL,
			new_status VARCHAR(100) DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset};");


		// ---- 2. ستون‌های جدید در sn_leads (migration ایمن) ----
		$leads_table = $wpdb->prefix . 'sn_leads';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$leads_table}'") === $leads_table) {
			$cols = $wpdb->get_col("SHOW COLUMNS FROM {$leads_table}", 0);
			if (! in_array('lead_status', $cols, true)) {
				$wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN lead_status VARCHAR(60) DEFAULT NULL AFTER status");
				error_log('SN: Added lead_status column to sn_leads');
			}
			if (! in_array('note', $cols, true)) {
				$wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN note TEXT DEFAULT NULL AFTER lead_status");
				error_log('SN: Added note column to sn_leads');
			}
			if (! in_array('updated_at', $cols, true)) {
				$wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER assigned_at");
				error_log('SN: Added updated_at column to sn_leads');
			}
			if (! in_array('supervisor_id', $cols, true)) {
				$wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN supervisor_id BIGINT UNSIGNED DEFAULT NULL AFTER seller_id");
				error_log('SN: Added supervisor_id column to sn_leads');
			}
			if (! in_array('import_code', $cols, true)) {
				$wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN import_code VARCHAR(80) DEFAULT NULL AFTER phone");
				error_log('SN: Added import_code column to sn_leads');
			}
			if (! in_array('destination_panel', $cols, true)) {
				$wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN destination_panel VARCHAR(50) DEFAULT NULL AFTER lead_status");
				error_log('SN: Added destination_panel column to sn_leads');
			}
			if (! in_array('destination_routed_at', $cols, true)) {
				$wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN destination_routed_at DATETIME DEFAULT NULL AFTER destination_panel");
				error_log('SN: Added destination_routed_at column to sn_leads');
			}
		}


		// ---- 3. ستون‌های تایید مالی و اطلاعات واریز دستی در فاکتورها ----
		$invoice_table = $wpdb->prefix . 'sn_invoices';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$invoice_table}'") === $invoice_table) {
			$invoice_cols = $wpdb->get_col("SHOW COLUMNS FROM {$invoice_table}", 0);
			// Fix existing installations: old schema had status VARCHAR(20), but financial statuses can be longer.
			$wpdb->query("ALTER TABLE {$invoice_table} MODIFY COLUMN status VARCHAR(60) NOT NULL DEFAULT 'pending'");
			$invoice_migrations = [
				'invoice_status' => "ALTER TABLE {$invoice_table} ADD COLUMN invoice_status VARCHAR(60) DEFAULT NULL AFTER status",
				'payment_status' => "ALTER TABLE {$invoice_table} ADD COLUMN payment_status VARCHAR(60) DEFAULT NULL AFTER invoice_status",
				'receipt_file'    => "ALTER TABLE {$invoice_table} ADD COLUMN receipt_file VARCHAR(500) DEFAULT NULL AFTER receipt_url",
				'receipt_source'  => "ALTER TABLE {$invoice_table} ADD COLUMN receipt_source VARCHAR(50) DEFAULT NULL AFTER receipt_file",
				'payment_source'   => "ALTER TABLE {$invoice_table} ADD COLUMN payment_source VARCHAR(50) DEFAULT NULL AFTER pay_method",
				'manual_card_from' => "ALTER TABLE {$invoice_table} ADD COLUMN manual_card_from VARCHAR(4) DEFAULT NULL AFTER receipt_url",
				'manual_card_to'   => "ALTER TABLE {$invoice_table} ADD COLUMN manual_card_to VARCHAR(4) DEFAULT NULL AFTER manual_card_from",
				'manual_amount'    => "ALTER TABLE {$invoice_table} ADD COLUMN manual_amount DECIMAL(18,2) DEFAULT NULL AFTER manual_card_to",
				'manual_paid_at'   => "ALTER TABLE {$invoice_table} ADD COLUMN manual_paid_at DATETIME DEFAULT NULL AFTER manual_amount",
				'manual_paid_at_jalali' => "ALTER TABLE {$invoice_table} ADD COLUMN manual_paid_at_jalali VARCHAR(30) DEFAULT NULL AFTER manual_paid_at",
				'approved_by'      => "ALTER TABLE {$invoice_table} ADD COLUMN approved_by BIGINT UNSIGNED DEFAULT NULL AFTER paid_at",
				'approved_at'      => "ALTER TABLE {$invoice_table} ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by",
				'rejected_by'      => "ALTER TABLE {$invoice_table} ADD COLUMN rejected_by BIGINT UNSIGNED DEFAULT NULL AFTER approved_at",
				'rejected_at'      => "ALTER TABLE {$invoice_table} ADD COLUMN rejected_at DATETIME DEFAULT NULL AFTER rejected_by",
				'rejected_reason'  => "ALTER TABLE {$invoice_table} ADD COLUMN rejected_reason TEXT DEFAULT NULL AFTER rejected_at",
				'deposit_card_from_last4' => "ALTER TABLE {$invoice_table} ADD COLUMN deposit_card_from_last4 VARCHAR(4) DEFAULT NULL AFTER manual_paid_at_jalali",
				'deposit_card_to_last4'   => "ALTER TABLE {$invoice_table} ADD COLUMN deposit_card_to_last4 VARCHAR(4) DEFAULT NULL AFTER deposit_card_from_last4",
				'deposit_amount'          => "ALTER TABLE {$invoice_table} ADD COLUMN deposit_amount DECIMAL(18,2) DEFAULT NULL AFTER deposit_card_to_last4",
				'deposit_jalali_datetime' => "ALTER TABLE {$invoice_table} ADD COLUMN deposit_jalali_datetime VARCHAR(30) DEFAULT NULL AFTER deposit_amount",
				'financial_reviewed_by'   => "ALTER TABLE {$invoice_table} ADD COLUMN financial_reviewed_by BIGINT UNSIGNED DEFAULT NULL AFTER rejected_reason",
				'financial_reviewed_at'   => "ALTER TABLE {$invoice_table} ADD COLUMN financial_reviewed_at DATETIME DEFAULT NULL AFTER financial_reviewed_by",
				'financial_reject_reason' => "ALTER TABLE {$invoice_table} ADD COLUMN financial_reject_reason TEXT DEFAULT NULL AFTER financial_reviewed_at",
				'financial_rejected_at' => "ALTER TABLE {$invoice_table} ADD COLUMN financial_rejected_at DATETIME DEFAULT NULL AFTER financial_reject_reason",
				'financial_rejected_by' => "ALTER TABLE {$invoice_table} ADD COLUMN financial_rejected_by BIGINT UNSIGNED DEFAULT NULL AFTER financial_rejected_at",
				'resend_to_financial_at' => "ALTER TABLE {$invoice_table} ADD COLUMN resend_to_financial_at DATETIME DEFAULT NULL AFTER financial_rejected_by",
				'recontact_requested_at' => "ALTER TABLE {$invoice_table} ADD COLUMN recontact_requested_at DATETIME DEFAULT NULL AFTER resend_to_financial_at",
				'recontact_note' => "ALTER TABLE {$invoice_table} ADD COLUMN recontact_note TEXT DEFAULT NULL AFTER recontact_requested_at",
				'discount_amount' => "ALTER TABLE {$invoice_table} ADD COLUMN discount_amount DECIMAL(18,2) DEFAULT NULL AFTER product_price",
				'wheel_reward_summary' => "ALTER TABLE {$invoice_table} ADD COLUMN wheel_reward_summary TEXT DEFAULT NULL AFTER discount_amount",
				'coupon_code' => "ALTER TABLE {$invoice_table} ADD COLUMN coupon_code VARCHAR(100) DEFAULT NULL AFTER wheel_reward_summary",
				'coupon_discount_amount' => "ALTER TABLE {$invoice_table} ADD COLUMN coupon_discount_amount DECIMAL(18,2) DEFAULT NULL AFTER coupon_code",
				'original_total' => "ALTER TABLE {$invoice_table} ADD COLUMN original_total DECIMAL(18,2) DEFAULT NULL AFTER coupon_discount_amount",
				'discount_total' => "ALTER TABLE {$invoice_table} ADD COLUMN discount_total DECIMAL(18,2) DEFAULT NULL AFTER original_total",
				'final_total' => "ALTER TABLE {$invoice_table} ADD COLUMN final_total DECIMAL(18,2) DEFAULT NULL AFTER discount_total",
				'wc_order_id' => "ALTER TABLE {$invoice_table} ADD COLUMN wc_order_id BIGINT UNSIGNED DEFAULT NULL AFTER final_total",
				'financial_return_state' => "ALTER TABLE {$invoice_table} ADD COLUMN financial_return_state VARCHAR(40) DEFAULT NULL AFTER wc_order_id",
				'returned_to_seller_at' => "ALTER TABLE {$invoice_table} ADD COLUMN returned_to_seller_at DATETIME DEFAULT NULL AFTER financial_return_state",
				'resent_after_return_at' => "ALTER TABLE {$invoice_table} ADD COLUMN resent_after_return_at DATETIME DEFAULT NULL AFTER returned_to_seller_at",
			];
			foreach ($invoice_migrations as $col => $sql) {
				if (! in_array($col, $invoice_cols, true)) {
					$wpdb->query($sql);
				}
			}
		}

		dbDelta("CREATE TABLE {$wpdb->prefix}sn_invoice_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_name VARCHAR(255) NOT NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			is_free TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY product_id (product_id)
		) {$charset};");

		dbDelta("CREATE TABLE {$wpdb->prefix}sn_invoice_wheel (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED DEFAULT NULL,
			reward_type VARCHAR(40) DEFAULT NULL,
			reward_value VARCHAR(120) DEFAULT NULL,
			reward_payload LONGTEXT DEFAULT NULL,
			used_discount TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY customer_id (customer_id)
		) {$charset};");

		$payments_table = $wpdb->prefix . 'sn_payments';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$payments_table}'") === $payments_table) {
			$payment_cols = $wpdb->get_col("SHOW COLUMNS FROM {$payments_table}", 0);
			if (! in_array('uploaded_by_type', $payment_cols, true)) { $wpdb->query("ALTER TABLE {$payments_table} ADD COLUMN uploaded_by_type VARCHAR(20) DEFAULT NULL AFTER status"); }
			if (! in_array('uploaded_by_user_id', $payment_cols, true)) { $wpdb->query("ALTER TABLE {$payments_table} ADD COLUMN uploaded_by_user_id BIGINT UNSIGNED DEFAULT NULL AFTER uploaded_by_type"); }
		}
		$log_table = $wpdb->prefix . 'sn_activity_logs';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$log_table}'") === $log_table) {
			$log_cols = $wpdb->get_col("SHOW COLUMNS FROM {$log_table}", 0);
			$log_migrations = [
				'old_value'  => "ALTER TABLE {$log_table} ADD COLUMN old_value LONGTEXT DEFAULT NULL AFTER action",
				'new_value'  => "ALTER TABLE {$log_table} ADD COLUMN new_value LONGTEXT DEFAULT NULL AFTER old_value",
				'user_agent' => "ALTER TABLE {$log_table} ADD COLUMN user_agent TEXT DEFAULT NULL AFTER ip_address",
			];
			foreach ($log_migrations as $col => $sql) {
				if (! in_array($col, $log_cols, true)) {
					$wpdb->query($sql);
				}
			}
			$log_indexes = (array) $wpdb->get_results("SHOW INDEX FROM {$log_table}", ARRAY_A);
			$log_index_names = array_unique(array_map(static function($r){ return $r['Key_name'] ?? ''; }, $log_indexes));
			if (! in_array('sn_customer_action_lookup', $log_index_names, true)) {
				$wpdb->query("ALTER TABLE {$log_table} ADD INDEX sn_customer_action_lookup (action, created_at)");
			}
			if (! in_array('sn_invoice_action_lookup', $log_index_names, true)) {
				$wpdb->query("ALTER TABLE {$log_table} ADD INDEX sn_invoice_action_lookup (invoice_id, action, created_at)");
			}
		}
		$inv_table = $wpdb->prefix . 'sn_invoices';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$inv_table}'") === $inv_table) {
			$inv_indexes = (array) $wpdb->get_results("SHOW INDEX FROM {$inv_table}", ARRAY_A);
			$inv_index_names = array_unique(array_map(static function($r){ return $r['Key_name'] ?? ''; }, $inv_indexes));
			if (! in_array('sn_seller_status_lookup', $inv_index_names, true)) {
				$wpdb->query("ALTER TABLE {$inv_table} ADD INDEX sn_seller_status_lookup (seller_id, status, created_at)");
			}
			if (! in_array('sn_invoice_report_status', $inv_index_names, true)) {
				$wpdb->query("ALTER TABLE {$inv_table} ADD INDEX sn_invoice_report_status (status, payment_status, invoice_status)");
			}
			if (! in_array('sn_invoice_report_dates', $inv_index_names, true)) {
				$wpdb->query("ALTER TABLE {$inv_table} ADD INDEX sn_invoice_report_dates (created_at, paid_at, seller_id, supervisor_id)");
			}
		}
		$leads_idx_table = $wpdb->prefix . 'sn_leads';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$leads_idx_table}'") === $leads_idx_table) {
			$lead_indexes = (array) $wpdb->get_results("SHOW INDEX FROM {$leads_idx_table}", ARRAY_A);
			$lead_index_names = array_unique(array_map(static function($r){ return $r['Key_name'] ?? ''; }, $lead_indexes));
			if (! in_array('sn_lead_report_status', $lead_index_names, true)) {
				$wpdb->query("ALTER TABLE {$leads_idx_table} ADD INDEX sn_lead_report_status (status, lead_status, seller_id, supervisor_id)");
			}
		}


		// ---- 3. debug: log آخرین خطای DB ----
		if ($wpdb->last_error) {
			error_log('SN DB Error in maybe_create_tables: ' . $wpdb->last_error);
		}
	}

	public function enqueue_admin_assets(string $hook): void
	{
		if (false === strpos($hook, 'sn-') && false === strpos($hook, 'toplevel_page_sn')) {
			return;
		}
		wp_enqueue_style('sn-admin', SN_PLUGIN_URL . 'assets/css/admin.css', [], $this->asset_version('assets/css/admin.css'));
		wp_enqueue_script('sn-admin', SN_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], $this->asset_version('assets/js/admin.js'), true);
		wp_localize_script('sn-admin', 'snAdmin', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('sn_admin'),
		]);
	}

	// =========================================================
	// ADMIN MENU
	// =========================================================

	public function register_admin_menu(): void
	{
		add_menu_page(
			'شبکه فروش',
			'شبکه فروش',
			'manage_options',
			'sn-dashboard',
			[$this, 'render_admin_page'],
			'dashicons-networking',
			55
		);
		add_submenu_page('sn-dashboard', 'داشبورد', 'داشبورد', 'manage_options', 'sn-dashboard', [$this, 'render_admin_page']);
		add_submenu_page('sn-dashboard', 'شماره‌ها', 'شماره‌ها', 'manage_options', 'sn-leads', [$this, 'render_admin_leads']);
		add_submenu_page('sn-dashboard', 'فروشندگان', 'فروشندگان', 'manage_options', 'sn-sellers', [$this, 'render_admin_sellers']);
		add_submenu_page('sn-dashboard', 'سرپرست‌ها', 'سرپرست‌ها', 'manage_options', 'sn-supervisors', [$this, 'render_admin_supervisors']);
		add_submenu_page('sn-dashboard', 'فاکتورها', 'فاکتورها', 'manage_options', 'sn-invoices', [$this, 'render_admin_invoices']);
		add_submenu_page('sn-dashboard', 'کیف پول و پورسانت', 'کیف پول و پورسانت', 'manage_options', 'sn-wallets', [$this, 'render_admin_wallets']);
		add_submenu_page('sn-dashboard', 'پروفایل مشتری‌ها', 'پروفایل مشتری‌ها', 'manage_options', 'sn-customer-profiles', [$this, 'render_admin_customer_profiles']);
		add_submenu_page('sn-dashboard', 'وضعیت‌ها', 'وضعیت‌ها', 'manage_options', 'sn-statuses', [$this, 'render_admin_statuses']);
		add_submenu_page('sn-dashboard', 'گزارش‌گیری جامع', 'گزارش‌گیری جامع', 'manage_options', 'sn-reports', [$this, 'render_admin_reports']);
		add_submenu_page('sn-dashboard', 'تنظیمات', 'تنظیمات', 'manage_options', 'sn-settings', [$this, 'render_admin_settings']);
		add_menu_page('تایید مالی', 'تایید مالی', 'sn_view_payments', 'sn-financial-approval', [$this, 'render_financial_approval_page'], 'dashicons-yes-alt', 56);
	}

	// =========================================================
	// PRODUCT METABOX
	// =========================================================

	public function register_product_metabox(): void
	{
		add_meta_box('sn-product-cap', 'شبکه فروش', [$this, 'render_product_metabox'], 'product', 'side');
	}

	public function render_product_metabox(\WP_Post $post): void
	{
		$enabled  = get_post_meta($post->ID, '_sn_enabled', true);
		$sub_html = get_post_meta($post->ID, '_sn_subscription_content', true);
		$short_desc = get_post_meta($post->ID, '_sn_short_description', true);
		$lottery_chance_count = get_post_meta($post->ID, '_sn_lottery_chance_count', true);
		$has_lucky_wheel = get_post_meta($post->ID, '_sn_has_lucky_wheel', true);
		$has_discount_coupon = get_post_meta($post->ID, '_sn_has_discount_coupon', true);
		$selected_wheel_id = get_post_meta($post->ID, '_sn_wheel_id', true);
		$sn_lucky_wheels = get_option('sn_lucky_wheels', []);
		if (! is_array($sn_lucky_wheels)) { $sn_lucky_wheels = []; }
		wp_nonce_field('sn_product_meta', 'sn_product_nonce');
?>
		<div style="direction:rtl;font-family:Tahoma,sans-serif">
			<label style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
				<input type="checkbox" name="sn_enabled" id="sn_enabled_cb" value="1" <?php checked($enabled, '1'); ?>>
				<strong>نمایش در شبکه فروش</strong>
			</label>
			<div id="sn-sub-content-wrap" style="<?php echo $enabled === '1' ? '' : 'display:none'; ?>border-top:1px solid #ddd;padding-top:10px;margin-top:4px">
				<label style="display:block;font-weight:600;margin-bottom:6px;font-size:12px">توضیح کوتاه محصول در پیش‌فاکتور:</label>
				<textarea name="sn_short_description" rows="3" style="width:100%;font-size:12px;direction:rtl"><?php echo esc_textarea($short_desc); ?></textarea>
				<label style="display:block;font-weight:600;margin:10px 0 6px;font-size:12px">تعداد شانس قرعه‌کشی:</label>
				<input type="number" min="0" name="sn_lottery_chance_count" value="<?php echo esc_attr($lottery_chance_count !== '' ? $lottery_chance_count : '0'); ?>" style="width:100%">
				<label style="display:flex;align-items:center;gap:6px;margin-top:10px"><input type="checkbox" name="sn_has_lucky_wheel" value="1" <?php checked($has_lucky_wheel, '1'); ?>> گردونه شانس دارد</label>
				<label style="display:block;font-weight:600;margin:10px 0 6px;font-size:12px">گردونه متصل:</label>
				<select name="sn_wheel_id" style="width:100%"><option value="">پیش‌فرض / بدون انتخاب</option><?php foreach ($sn_lucky_wheels as $wid => $wheel): ?><option value="<?php echo esc_attr($wid); ?>" <?php selected($selected_wheel_id, $wid); ?>><?php echo esc_html($wheel['title'] ?? $wid); ?></option><?php endforeach; ?></select>
				<label style="display:flex;align-items:center;gap:6px;margin-top:8px"><input type="checkbox" name="sn_has_discount_coupon" value="1" <?php checked($has_discount_coupon, '1'); ?>> شامل کد تخفیف شبکه فروش می‌شود</label>
				<label style="display:block;font-weight:600;margin:12px 0 6px;font-size:12px">محتویات اشتراک / دسترسی پس از پرداخت:</label>
				<p style="font-size:11px;color:#777;margin-bottom:6px">HTML یا متن ساده — پس از پرداخت فاکتور در حساب مشتری نمایش داده می‌شود.</p>
				<textarea name="sn_subscription_content" rows="5" style="width:100%;font-size:12px;direction:rtl"><?php echo esc_textarea($sub_html); ?></textarea>
			</div>
		</div>
		<script>
			(function() {
				var cb = document.getElementById('sn_enabled_cb');
				var wrap = document.getElementById('sn-sub-content-wrap');
				if (cb && wrap) {
					cb.addEventListener('change', function() {
						wrap.style.display = this.checked ? '' : 'none';
					});
				}
			})();
		</script>
	<?php
	}

	public function save_product_meta(int $post_id, \WP_Post $post): void
	{
		if (! isset($_POST['sn_product_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sn_product_nonce'])), 'sn_product_meta')) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		$enabled = isset($_POST['sn_enabled']) ? '1' : '0';
		update_post_meta($post_id, '_sn_enabled', $enabled);
		$sub_content = wp_kses_post(wp_unslash($_POST['sn_subscription_content'] ?? ''));
		update_post_meta($post_id, '_sn_subscription_content', $sub_content);
		update_post_meta($post_id, '_sn_short_description', wp_kses_post(wp_unslash($_POST['sn_short_description'] ?? '')));
		update_post_meta($post_id, '_sn_lottery_chance_count', max(0, absint($_POST['sn_lottery_chance_count'] ?? 0)));
		update_post_meta($post_id, '_sn_has_lucky_wheel', isset($_POST['sn_has_lucky_wheel']) ? '1' : '0');
		update_post_meta($post_id, '_sn_has_discount_coupon', isset($_POST['sn_has_discount_coupon']) ? '1' : '0');
		update_post_meta($post_id, '_sn_wheel_id', sanitize_key($_POST['sn_wheel_id'] ?? ''));
	}

	// =========================================================
	// AUTH HANDLERS
	// =========================================================

	public function handle_seller_login(): void
	{
		$phone    = SN_Helpers::normalize_mobile(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
		$password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));

		// آدرس برگشت به صفحه auth با خطا
		$auth_page_id  = (int) get_option('sn_auth_page_id', 0);
		$auth_url      = $auth_page_id ? get_permalink($auth_page_id) : home_url();
		$panel_page_id = (int) get_option('sn_seller_panel_page_id', 0);
		$panel_url     = $panel_page_id ? get_permalink($panel_page_id) : home_url();

		if (empty($phone) || empty($password)) {
			wp_redirect(add_query_arg('sn_err', 'empty', $auth_url));
			exit;
		}

		$user = get_user_by('login', $phone);
		if (! $user || ! in_array('sn_seller', (array) $user->roles, true)) {
			wp_redirect(add_query_arg('sn_err', 'notfound', $auth_url));
			exit;
		}

		if (! wp_check_password($password, $user->user_pass, $user->ID)) {
			wp_redirect(add_query_arg('sn_err', 'wrongpass', $auth_url));
			exit;
		}

		// لاگین — بدون wp_login_user چون ممکنه redirect به admin بکنه
		wp_set_auth_cookie($user->ID, true);
		wp_set_current_user($user->ID);

		// redirect صریح به پنل فروشنده
		wp_redirect($panel_url);
		exit;
	}

	public function handle_sales_manager_login(): void
	{
		$login = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
		$normalized = SN_Helpers::normalize_mobile($login);
		$password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));
		$auth_id = (int) get_option('sn_sales_manager_auth_page_id', 0);
		$panel_id = (int) get_option('sn_sales_manager_panel_page_id', 0);
		$auth_url = $auth_id ? get_permalink($auth_id) : home_url();
		$panel_url = $panel_id ? get_permalink($panel_id) : home_url();
		if ($login === '' || $password === '') {
			wp_redirect(add_query_arg('sn_err', 'empty', $auth_url));
			exit;
		}
		$user = get_user_by('login', $normalized) ?: get_user_by('login', $login);
		if (! $user && is_email($login)) {
			$user = get_user_by('email', $login);
		}
		if (! $user || (! in_array('sn_sales_manager', (array) $user->roles, true) && ! user_can($user, 'manage_options'))) {
			wp_redirect(add_query_arg('sn_err', 'notfound', $auth_url));
			exit;
		}
		if (! wp_check_password($password, $user->user_pass, $user->ID)) {
			wp_redirect(add_query_arg('sn_err', 'wrongpass', $auth_url));
			exit;
		}
		wp_set_auth_cookie($user->ID, true);
		wp_set_current_user($user->ID);
		wp_redirect($panel_url);
		exit;
	}

	public function handle_supervisor_login(): void
	{
		$phone_or_login = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
		$normalized     = SN_Helpers::normalize_mobile($phone_or_login);
		$password       = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));

		$auth_page_id  = (int) get_option('sn_supervisor_auth_page_id', 0);
		$auth_url      = $auth_page_id ? get_permalink($auth_page_id) : home_url();
		$panel_page_id = (int) get_option('sn_supervisor_panel_page_id', 0);
		$panel_url     = $panel_page_id ? get_permalink($panel_page_id) : home_url();

		if (empty($phone_or_login) || empty($password)) {
			wp_redirect(add_query_arg('sn_err', 'empty', $auth_url));
			exit;
		}

		$user = get_user_by('login', $normalized);
		if (! $user) {
			$user = get_user_by('login', $phone_or_login);
		}
		if (! $user && is_email($phone_or_login)) {
			$user = get_user_by('email', $phone_or_login);
		}

		if (! $user || (! in_array('sn_supervisor', (array) $user->roles, true) && ! user_can($user, 'manage_options'))) {
			wp_redirect(add_query_arg('sn_err', 'notfound', $auth_url));
			exit;
		}

		if (! wp_check_password($password, $user->user_pass, $user->ID)) {
			wp_redirect(add_query_arg('sn_err', 'wrongpass', $auth_url));
			exit;
		}

		wp_set_auth_cookie($user->ID, true);
		wp_set_current_user($user->ID);
		wp_redirect($panel_url);
		exit;
	}
	public function handle_seller_register(): void
	{
		$phone    = SN_Helpers::normalize_mobile(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
		$name     = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
		$password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));

		$auth_page_id  = (int) get_option('sn_auth_page_id', 0);
		$auth_url      = $auth_page_id ? get_permalink($auth_page_id) : home_url();
		$panel_page_id = (int) get_option('sn_seller_panel_page_id', 0);
		$panel_url     = $panel_page_id ? get_permalink($panel_page_id) : home_url();

		if (! SN_Helpers::is_valid_mobile($phone) || empty($name) || strlen($password) < 6) {
			wp_redirect(add_query_arg('sn_err', 'invalid', $auth_url));
			exit;
		}

		if (username_exists($phone)) {
			wp_redirect(add_query_arg('sn_err', 'exists', $auth_url));
			exit;
		}

		$user_id = wp_create_user($phone, $password, $phone . '@sn.local');
		if (is_wp_error($user_id)) {
			wp_redirect(add_query_arg('sn_err', 'create', $auth_url));
			exit;
		}

		$new_user = new WP_User($user_id);
		$new_user->set_role('sn_seller');
		wp_update_user(['ID' => $user_id, 'display_name' => $name, 'first_name' => $name]);

		wp_set_auth_cookie($user_id, true);
		wp_set_current_user($user_id);

		// redirect صریح به پنل فروشنده
		wp_redirect($panel_url);
		exit;
	}


	/**
	 * سرپرست و فروشنده فقط باید از پنل اختصاصی خودشان استفاده کنند.
	 * این متد دسترسی مستقیم به wp-admin را می‌بندد، ولی admin-ajax/admin-post را برای فرم‌ها و AJAXها آزاد می‌گذارد.
	 */
	public function block_front_roles_admin_access(): void
	{
		if (! is_user_logged_in()) {
			return;
		}
		if (wp_doing_ajax() || (defined('DOING_AJAX') && DOING_AJAX)) {
			return;
		}
		if (defined('DOING_CRON') && DOING_CRON) {
			return;
		}

		$script = basename($_SERVER['PHP_SELF'] ?? '');
		if (in_array($script, ['admin-ajax.php', 'admin-post.php'], true)) {
			return;
		}
		if (isset($_GET['sn_export']) && current_user_can('sn_export_sales_reports')) {
			return;
		}

		$user  = wp_get_current_user();
		$roles = (array) $user->roles;

		// ادمین واقعی نباید قفل شود؛ اما نقش‌های اختصاصی بدون capability مدیریتی وارد پیشخوان نمی‌شوند.
		if (current_user_can('manage_options')) {
			return;
		}

		$target_page_id = 0;
		if (in_array('sn_supervisor', $roles, true)) {
			$target_page_id = (int) get_option('sn_supervisor_panel_page_id', 0);
		} elseif (in_array('sn_sales_manager', $roles, true)) {
			$target_page_id = (int) get_option('sn_sales_manager_panel_page_id', 0);
		} elseif (in_array('sn_seller', $roles, true)) {
			$target_page_id = (int) get_option('sn_seller_panel_page_id', 0);
		} elseif (in_array('sn_after_sales', $roles, true)) {
			$target_page_id = (int) get_option('sn_after_sales_panel_page_id', 0);
		}
		if (! $target_page_id) {
			return;
		}
		$target_url = get_permalink($target_page_id);
		if (! $target_url) {
			$target_url = home_url('/');
		}

		wp_safe_redirect($target_url);
		exit;
	}

	/** حذف admin bar برای سرپرست‌ها و فروشنده‌ها در فرانت */
	public function hide_front_roles_admin_bar(): void
	{
		if (! is_user_logged_in()) {
			return;
		}
		$user  = wp_get_current_user();
		$roles = (array) $user->roles;
		if (current_user_can('manage_options')) {
			return;
		}
		if (in_array('sn_supervisor', $roles, true) || in_array('sn_seller', $roles, true) || in_array('sn_after_sales', $roles, true) || in_array('sn_sales_manager', $roles, true)) {
			show_admin_bar(false);
		}
	}

	/**
	 * نقش‌های فرانت را سخت‌گیرانه نگه می‌دارد؛ اگر در نسخه‌های قبلی capability اضافه شده باشد پاک می‌شود.
	 */
	public function harden_front_roles_caps(): void
	{
		foreach (['sn_supervisor', 'sn_seller', 'sn_after_sales', 'sn_sales_manager'] as $role_key) {
			$role = get_role($role_key);
			if (! $role) {
				continue;
			}
			$role->add_cap('read');
			foreach (['edit_posts', 'delete_posts', 'publish_posts', 'upload_files', 'edit_pages', 'delete_pages', 'manage_options', 'list_users', 'create_users', 'edit_users', 'delete_users'] as $cap) {
				$role->remove_cap($cap);
			}
		}
	}

	// جلوگیری از redirect به wp-admin برای فروشندگان
	public function ajax_toggle_seller_active(): void
	{
		if (! is_user_logged_in() || ! check_ajax_referer('sn_public', 'nonce', false)) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		$user      = wp_get_current_user();
		$seller_id = absint($_POST['seller_id'] ?? 0);
		if (! $seller_id) {
			SN_Helpers::send_json(false, 'شناسه نامعتبر');
			return;
		}

		$can_manage = current_user_can('manage_options');
		if (! $can_manage && in_array('sn_supervisor', (array) $user->roles, true)) {
			$can_manage = ((int) get_user_meta($seller_id, 'sn_supervisor_id', true) === (int) $user->ID);
		}
		if (! $can_manage) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}

		$current = get_user_meta($seller_id, 'sn_is_active', true);
		$new_val = ($current === '0') ? '1' : '0';
		update_user_meta($seller_id, 'sn_is_active', $new_val);
		SN_Helpers::send_json(true, $new_val === '1' ? 'فروشنده فعال شد' : 'فروشنده غیرفعال شد', ['is_active' => $new_val === '1']);
	}

	public function seller_login_redirect(string $redirect_to, string $requested, $user): string
	{
		if (is_wp_error($user) || ! $user) {
			return $redirect_to;
		}
		if (in_array('sn_seller', (array) $user->roles, true)) {
			$panel_id = (int) get_option('sn_seller_panel_page_id', 0);
			return $panel_id ? get_permalink($panel_id) : home_url();
		}
		if (in_array('sn_supervisor', (array) $user->roles, true)) {
			$panel_id = (int) get_option('sn_supervisor_panel_page_id', 0);
			return $panel_id ? get_permalink($panel_id) : home_url();
		}
		if (in_array('sn_sales_manager', (array) $user->roles, true)) {
			$panel_id = (int) get_option('sn_sales_manager_panel_page_id', 0);
			return $panel_id ? get_permalink($panel_id) : home_url();
		}
		return $redirect_to;
	}

	// ریدایرکت بعد از لاگین wp-login برای نقش‌های فرانت
	public function block_seller_admin_access(string $user_login, WP_User $user): void
	{
		if (wp_doing_ajax() || current_user_can('manage_options')) {
			return;
		}
		$roles = (array) $user->roles;
		$panel_id = 0;
		if (in_array('sn_supervisor', $roles, true)) {
			$panel_id = (int) get_option('sn_supervisor_panel_page_id', 0);
		} elseif (in_array('sn_sales_manager', $roles, true)) {
			$panel_id = (int) get_option('sn_sales_manager_panel_page_id', 0);
		} elseif (in_array('sn_seller', $roles, true)) {
			$panel_id = (int) get_option('sn_seller_panel_page_id', 0);
		}
		if (! $panel_id) {
			return;
		}
		$url = get_permalink($panel_id) ?: home_url('/');
		wp_safe_redirect($url);
		exit;
	}

	public function handle_seller_logout(): void
	{
		$redirect = sanitize_url(wp_unslash($_POST['redirect'] ?? home_url()));
		wp_logout();
		wp_redirect($redirect);
		exit;
	}

	// =========================================================
	// AJAX - ADMIN
	// =========================================================

	public function ajax_import_leads(): void
	{
		if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}

		if (empty($_FILES['file'])) {
			SN_Helpers::send_json(false, 'فایل یافت نشد');
			return;
		}

		$file = $_FILES['file']; // phpcs:ignore
		$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if (! in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
			SN_Helpers::send_json(false, 'فرمت فایل پشتیبانی نمی‌شود');
			return;
		}

		$tmp = $file['tmp_name'];
		$batch_code = sanitize_text_field(wp_unslash($_POST['import_code'] ?? ''));
		$leads = [];

		if ($ext === 'csv') {
			if (($fh = fopen($tmp, 'r')) !== false) {
				$header = null;
				while (($row = fgetcsv($fh)) !== false) {
					$row = array_map(static fn($v) => trim((string) $v), $row);
					if ($header === null) {
						$maybe_header = array_map(static fn($v) => strtolower(trim((string) $v)), $row);
						$has_named_cols = count(array_intersect($maybe_header, ['phone', 'mobile', 'tel', 'number', 'code', 'import_code', 'batch_code'])) > 0;
						if ($has_named_cols) {
							$header = $maybe_header;
							continue;
						}
						$header = [];
					}
					$row_code = $batch_code;
					if ($header) {
						$phone_idx = null;
						foreach (['phone', 'mobile', 'tel', 'number'] as $key) {
							$idx = array_search($key, $header, true);
							if ($idx !== false) { $phone_idx = $idx; break; }
						}
						foreach (['code', 'import_code', 'batch_code'] as $key) {
							$idx = array_search($key, $header, true);
							if ($idx !== false && ! empty($row[$idx])) { $row_code = sanitize_text_field($row[$idx]); break; }
						}
						$candidates = $phone_idx !== null ? [$row[$phone_idx] ?? ''] : $row;
					} else {
						$candidates = $row;
					}
					foreach ($candidates as $cell) {
						$phone = SN_Helpers::normalize_mobile((string) $cell);
						if (SN_Helpers::is_valid_mobile($phone)) {
							$leads[$phone] = ['phone' => $phone, 'import_code' => $row_code];
							break;
						}
					}
				}
				fclose($fh);
			}
		} else {
			// برای xlsx از فایل CSV ساده پشتیبانی می‌کنیم
			// برای xlsx نیاز به کتابخانه خارجی است - فعلاً فقط CSV
			SN_Helpers::send_json(false, 'لطفاً فایل CSV آپلود کنید');
			return;
		}

		if (empty($leads)) {
			SN_Helpers::send_json(false, 'شماره معتبری یافت نشد');
			return;
		}

		global $wpdb;
		$imported = 0;
		$tagged_existing = 0;
		$table    = $wpdb->prefix . 'sn_leads';

		foreach ($leads as $lead) {
			$phone = $lead['phone'];
			$existing = $wpdb->get_row($wpdb->prepare("SELECT id, import_code FROM {$table} WHERE phone=%s LIMIT 1", $phone), ARRAY_A);
			if (! $existing) {
				$wpdb->insert($table, ['phone' => $phone, 'import_code' => $lead['import_code'], 'status' => 'unassigned']);
				$imported++;
			} elseif (! empty($lead['import_code']) && empty($existing['import_code'])) {
				$wpdb->update($table, ['import_code' => $lead['import_code']], ['id' => (int) $existing['id']]);
				$tagged_existing++;
			}
		}

		$this->sn_log_activity(null, null, 'leads_imported', 'ایمپورت شماره‌ها', ['count' => $imported, 'tagged_existing' => $tagged_existing, 'filename' => sanitize_file_name($file['name'] ?? ''), 'import_code' => $batch_code]);
		$message = "{$imported} شماره جدید وارد شد";
		if ($tagged_existing > 0) {
			$message .= " و کد برای {$tagged_existing} شماره موجود ثبت شد";
		}
		SN_Helpers::send_json(true, $message, ['count' => $imported, 'tagged_existing' => $tagged_existing]);
	}

	public function ajax_assign_leads(): void
	{
		if (! current_user_can('manage_options') && ! $this->is_supervisor()) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
		if (! $valid) {
			SN_Helpers::send_json(false, 'نانس نامعتبر');
			return;
		}

		$mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? 'count'));
		$seller_ids = array_map('absint', (array) ($_POST['seller_ids'] ?? []));
		if (empty($seller_ids)) {
			SN_Helpers::send_json(false, 'فروشنده‌ای انتخاب نشده');
			return;
		}

		$current_user = wp_get_current_user();
		$supervisor_id = current_user_can('manage_options') ? absint($_POST['supervisor_id'] ?? 0) : (int) $current_user->ID;
		if (! $supervisor_id && $this->is_supervisor()) {
			$supervisor_id = (int) $current_user->ID;
		}

		if (! current_user_can('manage_options')) {
			foreach ($seller_ids as $sid) {
				if ((int) get_user_meta($sid, 'sn_supervisor_id', true) !== (int) $supervisor_id) {
					SN_Helpers::send_json(false, 'یکی از فروشنده‌ها زیرمجموعه شما نیست');
					return;
				}
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sn_leads';
		$assigned = 0;

		if ($mode === 'count') {
			$count_each = absint($_POST['count_per_seller'] ?? 0);
			if ($count_each < 1) {
				SN_Helpers::send_json(false, 'تعداد نامعتبر');
				return;
			}
			foreach ($seller_ids as $seller_id) {
				if ($supervisor_id) {
					$leads = $wpdb->get_results($wpdb->prepare(
						"SELECT id FROM {$table} WHERE supervisor_id=%d AND seller_id IS NULL AND status='supervisor_pool' ORDER BY id ASC LIMIT %d",
						$supervisor_id,
						$count_each
					), ARRAY_A);
				} else {
					$leads = $wpdb->get_results($wpdb->prepare(
						"SELECT id FROM {$table} WHERE status='unassigned' AND seller_id IS NULL LIMIT %d",
						$count_each
					), ARRAY_A);
				}
				foreach ($leads as $lead) {
					$wpdb->update($table, [
						'status' => 'assigned',
						'seller_id' => $seller_id,
						'supervisor_id' => $supervisor_id ?: null,
						'assigned_at' => current_time('mysql'),
					], ['id' => $lead['id']]);
					$assigned++;
				}
			}
		} elseif ($mode === 'manual') {
			$lead_ids = array_map('absint', (array) ($_POST['lead_ids'] ?? []));
			$seller_id = $seller_ids[0] ?? 0;
			if (empty($lead_ids) || ! $seller_id) {
				SN_Helpers::send_json(false, 'داده ناقص');
				return;
			}
			foreach ($lead_ids as $lid) {
				$lead = $wpdb->get_row($wpdb->prepare("SELECT id, status, supervisor_id, seller_id FROM {$table} WHERE id=%d", $lid));
				if (! $lead || $lead->seller_id) {
					continue;
				}
				if ($supervisor_id && (int) $lead->supervisor_id !== (int) $supervisor_id) {
					continue;
				}
				if ($lead->status === 'supervisor_pool' || (current_user_can('manage_options') && $lead->status === 'unassigned')) {
					$wpdb->update($table, [
						'status' => 'assigned',
						'seller_id' => $seller_id,
						'supervisor_id' => $supervisor_id ?: ($lead->supervisor_id ?: null),
						'assigned_at' => current_time('mysql'),
					], ['id' => $lid]);
					$assigned++;
				}
			}
		}
		SN_Helpers::send_json(true, "{$assigned} شماره تخصیص یافت", ['assigned' => $assigned]);
	}

	public function ajax_assign_supervisor_leads(): void
	{
		$valid = check_ajax_referer('sn_admin', 'nonce', false) || check_ajax_referer('sn_public', 'nonce', false);
		if (! $this->sn_can_manage_supervisor_leads() || ! $valid) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		$supervisor_id = absint($_POST['supervisor_id'] ?? 0);
		$count = absint($_POST['count'] ?? 0);
		$import_code = sanitize_text_field(wp_unslash($_POST['import_code'] ?? ''));
		$filters = $this->get_sales_manager_filters($_POST);
		if (! $supervisor_id || $count < 1) {
			SN_Helpers::send_json(false, 'سرپرست یا تعداد نامعتبر است');
			return;
		}
		$user = get_user_by('id', $supervisor_id);
		if (! $user || ! in_array('sn_supervisor', (array) $user->roles, true)) {
			SN_Helpers::send_json(false, 'کاربر انتخاب‌شده سرپرست نیست');
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'sn_leads';
		$where = ["status='unassigned'", 'seller_id IS NULL', 'supervisor_id IS NULL'];
		$args = [];
		if ($import_code !== '') {
			$where[] = 'import_code=%s';
			$args[] = $import_code;
		}
		if ($filters['search'] !== '') {
			$where[] = '(phone LIKE %s OR province LIKE %s OR city LIKE %s OR note LIKE %s OR import_code LIKE %s)';
			$like = '%' . $wpdb->esc_like($filters['search']) . '%';
			array_push($args, $like, $like, $like, $like, $like);
		}
		if ($filters['lead_status'] !== '') {
			$where[] = 'lead_status=%s';
			$args[] = $filters['lead_status'];
		}
		$date_from = SN_Helpers::jalali_to_gregorian_date((string) $filters['date_from']);
		$date_to = SN_Helpers::jalali_to_gregorian_date((string) $filters['date_to']);
		if ($date_from) {
			$where[] = 'DATE(imported_at) >= %s';
			$args[] = $date_from;
		}
		if ($date_to) {
			$where[] = 'DATE(imported_at) <= %s';
			$args[] = $date_to;
		}
		if (preg_match('/^\d{2}:\d{2}$/', $filters['time_from'])) {
			$where[] = 'TIME(imported_at) >= %s';
			$args[] = $filters['time_from'] . ':00';
		}
		if (preg_match('/^\d{2}:\d{2}$/', $filters['time_to'])) {
			$where[] = 'TIME(imported_at) <= %s';
			$args[] = $filters['time_to'] . ':59';
		}
		$args[] = $count;
		$ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id ASC LIMIT %d", ...$args));
		$done = 0;
		foreach ($ids as $id) {
			$r = $wpdb->update($table, ['status' => 'supervisor_pool', 'supervisor_id' => $supervisor_id, 'assigned_at' => current_time('mysql')], ['id' => (int) $id]);
			if ($r !== false) {
				$done++;
			}
		}
		$this->sn_log_activity(null, null, 'supervisor_leads_assigned', 'تخصیص شماره به سرپرست', ['supervisor_id' => $supervisor_id, 'import_code' => $import_code, 'lead_ids' => array_map('intval', $ids), 'count' => $done, 'by' => get_current_user_id()]);
		SN_Helpers::send_json(true, "{$done} شماره به پنل سرپرست منتقل شد", ['assigned' => $done]);
	}

	public function ajax_sales_manager_leads(): void
	{
		$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
		if ((! current_user_can('manage_options') && ! current_user_can('sn_view_sales_reports')) || ! $valid) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sn_leads';
		$filters = $this->get_sales_manager_filters($_POST);
		[$where, $args] = $this->build_sales_manager_leads_where($filters);
		$limit = min(200, max(20, absint($_POST['limit'] ?? 80)));
		$offset = max(0, absint($_POST['offset'] ?? 0));

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : $wpdb->get_var($count_sql));

		$query_args = array_merge($args, [$limit, $offset]);
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, phone, province, city, status, lead_status, import_code, supervisor_id, seller_id, imported_at, assigned_at, updated_at FROM {$table} WHERE {$where} ORDER BY imported_at DESC, id DESC LIMIT %d OFFSET %d",
			...$query_args
		), ARRAY_A) ?: [];

		$items = array_map(function ($row) {
			$supervisor = ! empty($row['supervisor_id']) ? get_user_by('id', (int) $row['supervisor_id']) : null;
			$seller = ! empty($row['seller_id']) ? get_user_by('id', (int) $row['seller_id']) : null;
			return [
				'id' => (int) $row['id'],
				'phone' => (string) $row['phone'],
				'province' => (string) ($row['province'] ?? ''),
				'city' => (string) ($row['city'] ?? ''),
				'status' => (string) ($row['status'] ?? ''),
				'status_label' => SN_Helpers::status_label((string) ($row['status'] ?? '')),
				'lead_status' => (string) ($row['lead_status'] ?? ''),
				'import_code' => (string) ($row['import_code'] ?? ''),
				'supervisor_name' => $supervisor ? $supervisor->display_name : '—',
				'seller_name' => $seller ? $seller->display_name : '—',
				'imported_at' => SN_Helpers::gregorian_to_jalali_date($row['imported_at'] ?? ''),
				'assigned_at' => SN_Helpers::gregorian_to_jalali_date($row['assigned_at'] ?? ''),
			];
		}, $rows);

		SN_Helpers::send_json(true, 'گزارش مدیر فروش آماده شد', [
			'total' => $total,
			'items' => $items,
			'limit' => $limit,
			'offset' => $offset,
		]);
	}

	public function handle_sales_manager_export(): void
	{
		if ((! current_user_can('manage_options') && ! current_user_can('sn_export_sales_reports')) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'] ?? '')), 'sn_public')) {
			wp_die('دسترسی غیرمجاز');
		}
		$this->export_sales_manager_leads_csv($this->get_sales_manager_filters($_GET));
	}

	public function ajax_save_seller_supervisor(): void
	{
		if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		$seller_id = absint($_POST['seller_id'] ?? 0);
		$supervisor_id = absint($_POST['supervisor_id'] ?? 0);
		$seller = get_user_by('id', $seller_id);
		if (! $seller || ! in_array('sn_seller', (array) $seller->roles, true)) {
			SN_Helpers::send_json(false, 'فروشنده معتبر نیست');
			return;
		}
		if ($supervisor_id) {
			$supervisor = get_user_by('id', $supervisor_id);
			if (! $supervisor || ! in_array('sn_supervisor', (array) $supervisor->roles, true)) {
				SN_Helpers::send_json(false, 'سرپرست معتبر نیست');
				return;
			}
			update_user_meta($seller_id, 'sn_supervisor_id', $supervisor_id);
		} else {
			delete_user_meta($seller_id, 'sn_supervisor_id');
		}
		SN_Helpers::send_json(true, 'سرپرست فروشنده ذخیره شد');
	}


	public function ajax_bulk_seller_action(): void
	{
		if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		$seller_ids = array_map('absint', (array) ($_POST['seller_ids'] ?? []));
		$bulk_action = sanitize_text_field(wp_unslash($_POST['bulk_action'] ?? ''));
		$supervisor_id = absint($_POST['supervisor_id'] ?? 0);
		if (empty($seller_ids)) {
			SN_Helpers::send_json(false, 'هیچ فروشنده‌ای انتخاب نشده است');
			return;
		}
		if ($bulk_action === 'assign_supervisor') {
			$supervisor = get_user_by('id', $supervisor_id);
			if (! $supervisor || ! in_array('sn_supervisor', (array) $supervisor->roles, true)) {
				SN_Helpers::send_json(false, 'سرپرست معتبر نیست');
				return;
			}
		} elseif ($bulk_action !== 'remove_supervisor') {
			SN_Helpers::send_json(false, 'عملیات معتبر نیست');
			return;
		}
		$done = 0;
		foreach ($seller_ids as $seller_id) {
			$seller = get_user_by('id', $seller_id);
			if (! $seller || ! in_array('sn_seller', (array) $seller->roles, true)) {
				continue;
			}
			if ($bulk_action === 'assign_supervisor') {
				update_user_meta($seller_id, 'sn_supervisor_id', $supervisor_id);
			} else {
				delete_user_meta($seller_id, 'sn_supervisor_id');
			}
			$done++;
		}
		SN_Helpers::send_json(true, $done . ' فروشنده بروزرسانی شد', ['updated' => $done]);
	}

	private function sn_csv_header(string $filename): void
	{
		if (ob_get_level()) {
			ob_end_clean();
		}
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);
		header('Pragma: no-cache');
		header('Expires: 0');
		echo "\xEF\xBB\xBF";
	}

	public function handle_admin_export(): void
	{
		if (! is_admin() || empty($_GET['sn_export'])) {
			return;
		}
		if (! current_user_can('manage_options') && ! current_user_can('sn_export_sales_reports')) {
			wp_die('دسترسی غیرمجاز');
		}
		$type = sanitize_key(wp_unslash($_GET['sn_export']));
		check_admin_referer('sn_export_' . $type);
		if ($type === 'leads') {
			$this->export_leads_csv();
		}
		if ($type === 'invoices') {
			$this->export_invoices_csv();
		}
		if ($type === 'sellers') {
			$this->export_sellers_csv();
		}
		if ($type === 'supervisors') {
			$this->export_supervisors_csv();
		}
		if ($type === 'custom_report') {
			$this->export_custom_report_csv();
		}
	}

	private function export_leads_csv(): void
	{
		global $wpdb;
		$filters = $this->get_lead_filters();
		[$where, $args] = $this->build_leads_where($filters);
		$sql = "SELECT * FROM {$wpdb->prefix}sn_leads WHERE {$where} ORDER BY id DESC";
		$rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
		$this->sn_csv_header('sales-network-leads-' . date('Y-m-d') . '.csv');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['ID', 'شماره', 'کد واردات', 'استان', 'شهر', 'وضعیت سیستمی', 'وضعیت تماس', 'یادداشت', 'سرپرست', 'فروشنده', 'تاریخ ورود', 'تاریخ تخصیص']);
		foreach ($rows as $r) {
			$seller = ! empty($r['seller_id']) ? get_user_by('id', $r['seller_id']) : null;
			$supervisor = ! empty($r['supervisor_id']) ? get_user_by('id', $r['supervisor_id']) : null;
			fputcsv($out, [$r['id'], $r['phone'], $r['import_code'] ?? '', $r['province'], $r['city'], $r['status'], $r['lead_status'], $r['note'], $supervisor ? $supervisor->display_name : '', $seller ? $seller->display_name : '', SN_Helpers::gregorian_to_jalali_date($r['imported_at']), SN_Helpers::gregorian_to_jalali_date($r['assigned_at'])]);
		}
		exit;
	}

	private function export_invoices_csv(): void
	{
		global $wpdb;
		$filters = $this->get_invoice_filters();
		[$where, $args] = $this->build_invoices_where($filters);
		$sql = "SELECT * FROM {$wpdb->prefix}sn_invoices WHERE {$where} ORDER BY id DESC";
		$rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
		$this->sn_csv_header('sales-network-invoices-' . date('Y-m-d') . '.csv');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['ID', 'کد فاکتور', 'مشتری', 'موبایل', 'استان', 'شهر', 'محصول', 'مبلغ', 'فروشنده', 'وضعیت', 'روش پرداخت', 'تاریخ پرداخت', 'تاریخ ایجاد']);
		foreach ($rows as $r) {
			$seller = ! empty($r['seller_id']) ? get_user_by('id', $r['seller_id']) : null;
			fputcsv($out, [$r['id'], $r['invoice_code'], $r['customer_name'], $r['customer_phone'], $r['province'], $r['city'], get_the_title((int) $r['product_id']), $r['product_price'], $seller ? $seller->display_name : '', $r['status'], $r['pay_method'], $r['paid_at'], $r['created_at']]);
		}
		exit;
	}

	private function export_sellers_csv(): void
	{
		global $wpdb;
		$sellers = get_users(['role' => 'sn_seller', 'number' => 2000]);
		$this->sn_csv_header('sales-network-sellers-' . date('Y-m-d') . '.csv');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['ID', 'نام', 'شماره/نام کاربری', 'سرپرست', 'تعداد شماره', 'تعداد فاکتور', 'فاکتور پرداخت‌شده', 'مبلغ فروش تاییدشده']);
		foreach ($sellers as $s) {
			$sup_id = (int) get_user_meta($s->ID, 'sn_supervisor_id', true);
			$sup = $sup_id ? get_user_by('id', $sup_id) : null;
			$lc = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE seller_id=%d", $s->ID));
			$ic = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d", $s->ID));
			$pc = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d AND status='paid'", $s->ID));
			$rev = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(COALESCE(final_total, product_price, 0)),0) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d AND status='paid'", $s->ID));
			fputcsv($out, [$s->ID, $s->display_name, $s->user_login, $sup ? $sup->display_name : '', $lc, $ic, $pc, $rev]);
		}
		exit;
	}

	private function export_supervisors_csv(): void
	{
		global $wpdb;
		$supervisors = get_users(['role' => 'sn_supervisor', 'number' => 1000]);
		$this->sn_csv_header('sales-network-supervisors-' . date('Y-m-d') . '.csv');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['ID', 'نام سرپرست', 'شماره/نام کاربری', 'تعداد فروشنده', 'شماره‌های پنل سرپرست', 'شماره‌های تخصیص‌داده‌شده', 'فاکتورهای زیرمجموعه', 'فروش تاییدشده زیرمجموعه']);
		foreach ($supervisors as $sup) {
			$seller_ids = get_users(['role' => 'sn_seller', 'meta_key' => 'sn_supervisor_id', 'meta_value' => $sup->ID, 'fields' => 'ids', 'number' => 2000]);
			$seller_count = count($seller_ids);
			$pool_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NULL AND status='supervisor_pool'", $sup->ID));
			$assigned_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NOT NULL", $sup->ID));
			$invoice_count = 0;
			$revenue = 0;
			if (! empty($seller_ids)) {
				$ph = implode(',', array_fill(0, count($seller_ids), '%d'));
				$ids = array_map('intval', $seller_ids);
				$invoice_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id IN ($ph)", ...$ids));
				$revenue = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(COALESCE(final_total, product_price, 0)),0) FROM {$wpdb->prefix}sn_invoices WHERE status='paid' AND seller_id IN ($ph)", ...$ids));
			}
			fputcsv($out, [$sup->ID, $sup->display_name, $sup->user_login, $seller_count, $pool_count, $assigned_count, $invoice_count, $revenue]);
		}
		exit;
	}
	private function get_lead_filters(): array
	{
		return ['search' => sanitize_text_field(wp_unslash($_GET['sn_search'] ?? '')), 'status' => sanitize_text_field(wp_unslash($_GET['sn_status'] ?? 'all')), 'lead_status' => sanitize_text_field(wp_unslash($_GET['sn_lead_status'] ?? '')), 'seller_id' => absint($_GET['sn_seller_id'] ?? 0), 'supervisor_id' => absint($_GET['sn_supervisor_id'] ?? 0), 'import_code' => sanitize_text_field(wp_unslash($_GET['sn_import_code'] ?? '')), 'date_from' => sanitize_text_field(wp_unslash($_GET['sn_date_from'] ?? '')), 'date_to' => sanitize_text_field(wp_unslash($_GET['sn_date_to'] ?? '')), 'time_from' => sanitize_text_field(wp_unslash($_GET['sn_time_from'] ?? '')), 'time_to' => sanitize_text_field(wp_unslash($_GET['sn_time_to'] ?? ''))];
	}

	private function get_sales_manager_filters(array $source): array
	{
		$value = static function (array $source, string $key, string $fallback = '') {
			$sn_key = 'sn_' . $key;
			if (isset($source[$key])) {
				return sanitize_text_field(wp_unslash($source[$key]));
			}
			if (isset($source[$sn_key])) {
				return sanitize_text_field(wp_unslash($source[$sn_key]));
			}
			return $fallback;
		};
		return [
			'search' => $value($source, 'search'),
			'status' => $value($source, 'status', 'all'),
			'assignment' => $value($source, 'assignment', 'all'),
			'lead_status' => $value($source, 'lead_status'),
			'seller_id' => absint($source['seller_id'] ?? $source['sn_seller_id'] ?? 0),
			'supervisor_id' => absint($source['supervisor_id'] ?? $source['sn_supervisor_id'] ?? 0),
			'import_code' => $value($source, 'import_code'),
			'date_from' => $value($source, 'date_from'),
			'date_to' => $value($source, 'date_to'),
			'time_from' => $value($source, 'time_from'),
			'time_to' => $value($source, 'time_to'),
		];
	}

	private function build_sales_manager_leads_where(array $f): array
	{
		global $wpdb;
		$where = ['1=1'];
		$args = [];
		$statuses = ['unassigned', 'supervisor_pool', 'assigned', 'invoiced'];
		$assignments = ['all', 'unassigned', 'supervisor_pool', 'assigned'];

		if ($f['search'] !== '') {
			$where[] = '(phone LIKE %s OR province LIKE %s OR city LIKE %s OR note LIKE %s OR import_code LIKE %s)';
			$like = '%' . $wpdb->esc_like($f['search']) . '%';
			array_push($args, $like, $like, $like, $like, $like);
		}
		if ($f['import_code'] !== '') {
			$where[] = 'import_code = %s';
			$args[] = $f['import_code'];
		}
		if (in_array($f['status'], $statuses, true)) {
			$where[] = 'status = %s';
			$args[] = $f['status'];
		}
		if ($f['lead_status'] !== '') {
			$where[] = 'lead_status = %s';
			$args[] = $f['lead_status'];
		}
		if ($f['seller_id']) {
			$where[] = 'seller_id = %d';
			$args[] = $f['seller_id'];
		}
		if ($f['supervisor_id']) {
			$where[] = 'supervisor_id = %d';
			$args[] = $f['supervisor_id'];
		}
		if (in_array($f['assignment'], $assignments, true)) {
			if ($f['assignment'] === 'unassigned') {
				$where[] = "status = 'unassigned' AND seller_id IS NULL AND supervisor_id IS NULL";
			} elseif ($f['assignment'] === 'supervisor_pool') {
				$where[] = "status = 'supervisor_pool' AND seller_id IS NULL AND supervisor_id IS NOT NULL";
			} elseif ($f['assignment'] === 'assigned') {
				$where[] = 'seller_id IS NOT NULL';
			}
		}
		$date_from = SN_Helpers::jalali_to_gregorian_date((string) $f['date_from']);
		$date_to = SN_Helpers::jalali_to_gregorian_date((string) $f['date_to']);
		if ($date_from) {
			$where[] = 'DATE(imported_at) >= %s';
			$args[] = $date_from;
		}
		if ($date_to) {
			$where[] = 'DATE(imported_at) <= %s';
			$args[] = $date_to;
		}
		if (preg_match('/^\d{2}:\d{2}$/', $f['time_from'])) {
			$where[] = 'TIME(imported_at) >= %s';
			$args[] = $f['time_from'] . ':00';
		}
		if (preg_match('/^\d{2}:\d{2}$/', $f['time_to'])) {
			$where[] = 'TIME(imported_at) <= %s';
			$args[] = $f['time_to'] . ':59';
		}
		return [implode(' AND ', $where), $args];
	}

	private function export_sales_manager_leads_csv(array $filters): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'sn_leads';
		[$where, $args] = $this->build_sales_manager_leads_where($filters);
		$sql = "SELECT id, phone, import_code, province, city, status, lead_status, supervisor_id, seller_id, imported_at, assigned_at FROM {$table} WHERE {$where} ORDER BY imported_at DESC, id DESC LIMIT 5000";
		$rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=sales-manager-leads-' . date('Ymd-His') . '.csv');
		$out = fopen('php://output', 'w');
		fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
		fputcsv($out, ['ID', 'Phone', 'Import Code', 'Province', 'City', 'Status', 'Lead Status', 'Supervisor', 'Seller', 'Imported At', 'Assigned At']);
		foreach ($rows as $row) {
			$supervisor = ! empty($row['supervisor_id']) ? get_user_by('id', (int) $row['supervisor_id']) : null;
			$seller = ! empty($row['seller_id']) ? get_user_by('id', (int) $row['seller_id']) : null;
			fputcsv($out, [
				$row['id'],
				$row['phone'],
				$row['import_code'] ?? '',
				$row['province'],
				$row['city'],
				SN_Helpers::status_label((string) $row['status']),
				$row['lead_status'] ?: '—',
				$supervisor ? $supervisor->display_name : '—',
				$seller ? $seller->display_name : '—',
				SN_Helpers::gregorian_to_jalali_date($row['imported_at']),
				SN_Helpers::gregorian_to_jalali_date($row['assigned_at']),
			]);
		}
		exit;
	}

	private function build_leads_where(array $f): array
	{
		$where = ['1=1'];
		$args = [];
		if ($f['search'] !== '') {
			$where[] = '(phone LIKE %s OR province LIKE %s OR city LIKE %s OR note LIKE %s OR import_code LIKE %s)';
			$like = '%' . $GLOBALS['wpdb']->esc_like($f['search']) . '%';
			array_push($args, $like, $like, $like, $like, $like);
		}
		if (! empty($f['import_code'])) {
			$where[] = 'import_code = %s';
			$args[] = $f['import_code'];
		}
		if ($f['status'] !== '' && $f['status'] !== 'all') {
			$where[] = 'status = %s';
			$args[] = $f['status'];
		}
		if ($f['lead_status'] !== '') {
			$where[] = 'lead_status = %s';
			$args[] = $f['lead_status'];
		}
		if ($f['seller_id']) {
			$where[] = 'seller_id = %d';
			$args[] = $f['seller_id'];
		}
		if ($f['supervisor_id']) {
			$where[] = 'supervisor_id = %d';
			$args[] = $f['supervisor_id'];
		}
		$date_from = SN_Helpers::jalali_to_gregorian_date((string) $f['date_from']);
		$date_to = SN_Helpers::jalali_to_gregorian_date((string) $f['date_to']);
		if ($date_from) {
			$where[] = 'DATE(assigned_at) >= %s';
			$args[] = $date_from;
		}
		if ($date_to) {
			$where[] = 'DATE(assigned_at) <= %s';
			$args[] = $date_to;
		}
		if (preg_match('/^\d{2}:\d{2}$/', $f['time_from'])) {
			$where[] = 'TIME(assigned_at) >= %s';
			$args[] = $f['time_from'] . ':00';
		}
		if (preg_match('/^\d{2}:\d{2}$/', $f['time_to'])) {
			$where[] = 'TIME(assigned_at) <= %s';
			$args[] = $f['time_to'] . ':59';
		}
		return [implode(' AND ', $where), $args];
	}

	private function get_invoice_filters(): array
	{
		return ['status' => sanitize_text_field(wp_unslash($_GET['sn_status'] ?? 'all')), 'search' => sanitize_text_field(wp_unslash($_GET['sn_search'] ?? '')), 'seller_id' => absint($_GET['sn_seller_id'] ?? 0), 'supervisor_id' => absint($_GET['sn_supervisor_id'] ?? 0), 'date_from' => sanitize_text_field(wp_unslash($_GET['sn_date_from'] ?? '')), 'date_to' => sanitize_text_field(wp_unslash($_GET['sn_date_to'] ?? '')), 'time_from' => sanitize_text_field(wp_unslash($_GET['sn_time_from'] ?? '')), 'time_to' => sanitize_text_field(wp_unslash($_GET['sn_time_to'] ?? ''))];
	}

	private function build_invoices_where(array $f): array
	{
		global $wpdb;
		$where = ['1=1'];
		$args = [];
		if ($f['status'] === 'needs_review') {
			$where[] = "(status IN ('receipt_uploaded','pending_financial_approval') OR payment_status IN ('receipt_uploaded','pending_financial_approval') OR invoice_status IN ('receipt_uploaded','pending_financial_approval'))";
		} elseif ($f['status'] !== '' && $f['status'] !== 'all') {
			$where[] = 'status = %s';
			$args[] = $f['status'];
		}
		if ($f['search'] !== '') {
			$where[] = '(invoice_code LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s OR city LIKE %s)';
			$like = '%' . $wpdb->esc_like($f['search']) . '%';
			array_push($args, $like, $like, $like, $like);
		}
		if ($f['seller_id']) {
			$where[] = 'seller_id = %d';
			$args[] = $f['seller_id'];
		}
		if ($f['supervisor_id']) {
			$seller_ids = get_users(['role' => 'sn_seller', 'meta_key' => 'sn_supervisor_id', 'meta_value' => $f['supervisor_id'], 'fields' => 'ids', 'number' => 2000]);
			if (empty($seller_ids)) {
				$where[] = '0=1';
			} else {
				$where[] = 'seller_id IN (' . implode(',', array_fill(0, count($seller_ids), '%d')) . ')';
				$args = array_merge($args, array_map('intval', $seller_ids));
			}
		}
		$date_from = SN_Helpers::jalali_to_gregorian_date((string) $f['date_from']);
		$date_to = SN_Helpers::jalali_to_gregorian_date((string) $f['date_to']);
		if ($date_from) {
			$where[] = 'DATE(created_at) >= %s';
			$args[] = $date_from;
		}
		if ($date_to) {
			$where[] = 'DATE(created_at) <= %s';
			$args[] = $date_to;
		}
		if (preg_match('/^\d{2}:\d{2}$/', $f['time_from'])) {
			$where[] = 'TIME(created_at) >= %s';
			$args[] = $f['time_from'] . ':00';
		}
		if (preg_match('/^\d{2}:\d{2}$/', $f['time_to'])) {
			$where[] = 'TIME(created_at) <= %s';
			$args[] = $f['time_to'] . ':59';
		}
		return [implode(' AND ', $where), $args];
	}
	public function ajax_save_settings(): void
	{
		if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}

		// فیلدهای ثابت
		$fields = [
			'sn_zarinpal_merchant',
			'sn_zarinpal_sandbox',
			'sn_sms_provider',
			'sn_faraz_pattern_invoice',
			'sn_faraz_pattern_online_payment',
			'sn_faraz_pattern_card_payment',
			'sn_wheel_company_name',
			'sn_wheel_free_product_id',
			'sn_coupon_allow_on_sale',
			'sn_lottery_text_template',
			'sn_recontact_popup_text',
			'sn_invoice_info_show_short_desc',
			'sn_invoice_info_show_price',
			'sn_invoice_info_show_lottery',
			'sn_invoice_info_show_coupon',
			'sn_invoice_info_show_image',
			'sn_invoice_info_show_gallery',
			'sn_invoice_btn_show_product_info',
			'sn_invoice_btn_show_lottery',
			'sn_invoice_btn_show_wheel',
			'sn_invoice_btn_show_coupon',
			'sn_invoice_btn_show_recontact',
			'sn_invoice_btn_show_online_payment',
			'sn_invoice_btn_show_card_payment',
			'sn_meli_username',
			'sn_meli_password',
			'sn_meli_body_id_invoice',
			'sn_sms_invoice_template',
			'sn_sms_invoice_bodyid',
			'sn_card_number',
			'sn_card_owner',
			'sn_seller_commission_type',
			'sn_seller_commission_value',
			'sn_supervisor_commission_type',
			'sn_supervisor_commission_value',
			'sn_wallet_auto_credit',
			'sn_invoice_page_id',
			'sn_seller_panel_page_id',
			'sn_supervisor_panel_page_id',
			'sn_auth_page_id',
			'sn_supervisor_auth_page_id',
			'sn_after_sales_panel_page_id',
			'sn_financial_auth_page_id',
			'sn_financial_panel_page_id',
			'sn_sales_manager_auth_page_id',
			'sn_sales_manager_panel_page_id',
		];
		foreach ($fields as $field) {
			if (isset($_POST[$field])) {
				if ($field === 'sn_sms_invoice_template') {
					update_option($field, sanitize_textarea_field(wp_unslash($_POST[$field])));
				} else {
					update_option($field, sanitize_text_field(wp_unslash($_POST[$field])));
				}
			}
		}

		// Checkbox fields: absence means disabled. A posted value of "0" must stay disabled.
		foreach (['sn_zarinpal_sandbox','sn_wallet_auto_credit','sn_coupon_allow_on_sale','sn_invoice_info_show_short_desc','sn_invoice_info_show_price','sn_invoice_info_show_lottery','sn_invoice_info_show_coupon','sn_invoice_info_show_image','sn_invoice_info_show_gallery','sn_invoice_btn_show_product_info','sn_invoice_btn_show_lottery','sn_invoice_btn_show_wheel','sn_invoice_btn_show_coupon','sn_invoice_btn_show_recontact','sn_invoice_btn_show_online_payment','sn_invoice_btn_show_card_payment'] as $checkbox_field) {
			$value = isset($_POST[$checkbox_field]) ? sanitize_text_field(wp_unslash($_POST[$checkbox_field])) : '0';
			update_option($checkbox_field, in_array($value, ['1', 'on', 'yes', 'true'], true) ? '1' : '0');
		}

		// تنظیمات یکجای گردونه/قرعه‌کشی محصولات از صفحه تنظیمات؛ additive و هماهنگ با product meta ووکامرس.
		if (isset($_POST['sn_product_wheel']) && is_array($_POST['sn_product_wheel'])) {
			$wheel_products = wp_unslash($_POST['sn_product_wheel']);
			foreach ($wheel_products as $pid => $row) {
				$pid = absint($pid);
				if (! $pid || get_post_type($pid) !== 'product') { continue; }
				$row = is_array($row) ? $row : [];
				update_post_meta($pid, '_sn_lottery_chance_count', max(0, absint($row['lottery_chance_count'] ?? 0)));
				update_post_meta($pid, '_sn_has_lucky_wheel', ! empty($row['has_lucky_wheel']) ? '1' : '0');
				update_post_meta($pid, '_sn_has_discount_coupon', ! empty($row['has_discount_coupon']) ? '1' : '0');
				update_post_meta($pid, '_sn_wheel_id', sanitize_key($row['wheel_id'] ?? ''));
				update_post_meta($pid, '_sn_short_description', wp_kses_post($row['short_description'] ?? ''));
			}
		}


		// تنظیمات چند گردونه: هر گردونه چند گزینه با درصد شانس دارد و هر محصول به یکی از گردونه‌ها وصل می‌شود.
		if (isset($_POST['sn_lucky_wheels']) && is_array($_POST['sn_lucky_wheels'])) {
			$raw_wheels = wp_unslash($_POST['sn_lucky_wheels']);
			$wheels = [];
			foreach ($raw_wheels as $wheel_key => $wheel_row) {
				$wheel_row = is_array($wheel_row) ? $wheel_row : [];
				$title = sanitize_text_field($wheel_row['title'] ?? '');
				if ($title === '') { continue; }
				$id = sanitize_key($wheel_row['id'] ?? $wheel_key);
				if ($id === '' || $id === 'new') { $id = 'wheel_' . substr(md5($title . microtime(true) . wp_rand()), 0, 8); }
				$segments = [];
				if (! empty($wheel_row['segments']) && is_array($wheel_row['segments'])) {
					foreach ($wheel_row['segments'] as $seg) {
						$seg = is_array($seg) ? $seg : [];
						$label = sanitize_text_field($seg['label'] ?? '');
						$type = sanitize_key($seg['type'] ?? 'discount_coupon');
						$chance = max(0, min(100, (float) str_replace(',', '.', (string)($seg['chance'] ?? 0))));
						if ($label === '' || $chance <= 0) { continue; }
						$segments[] = [
							'label' => $label,
							'type' => in_array($type, ['discount_coupon','free_product','empty_reward','text'], true) ? $type : 'discount_coupon',
							'chance' => $chance,
							'value' => sanitize_text_field($seg['value'] ?? ''),
							'product_id' => absint($seg['product_id'] ?? 0),
						];
					}
				}
				if (!$segments) { continue; }
				$wheels[$id] = [
					'id' => $id,
					'title' => $title,
					'description' => sanitize_textarea_field($wheel_row['description'] ?? ''),
					'segments' => $segments,
				];
			}
			update_option('sn_lucky_wheels', $wheels, false);
		}

		// ذخیره api_key و sender بر اساس provider انتخاب‌شده
		// فرم، فیلدهای جداگانه‌ای برای هر سرویس دارد (sn_sms_api_key_faraz, _kavenegar, _meli)
		$provider = sanitize_text_field(wp_unslash($_POST['sn_sms_provider'] ?? ''));

		$provider_api_key_map = [
			'kavenegar'   => 'sn_sms_api_key_kavenegar',
			'faraz'       => 'sn_sms_api_key_faraz',
			'melipayamak' => 'sn_sms_api_key',  // ملی‌پیامک فیلد جداگانه ندارد
		];
		$provider_sender_map = [
			'kavenegar'   => 'sn_sms_sender_kavenegar',
			'faraz'       => 'sn_sms_sender_faraz',
			'melipayamak' => 'sn_sms_sender',
		];

		// همه فیلدهای provider-specific رو ذخیره کن (تا سوئیچ سرویس‌دهنده داده‌ها رو از دست نده)
		$all_api_fields    = ['sn_sms_api_key_kavenegar', 'sn_sms_api_key_faraz'];
		$all_sender_fields = ['sn_sms_sender_kavenegar', 'sn_sms_sender_faraz'];
		foreach (array_merge($all_api_fields, $all_sender_fields) as $pf) {
			if (isset($_POST[$pf])) {
				update_option($pf, sanitize_text_field(wp_unslash($_POST[$pf])));
			}
		}

		// sync: option عمومی (که SN_SMS ازش میخونه) رو با provider فعال هماهنگ کن
		if ($provider && isset($provider_api_key_map[$provider])) {
			$active_key_field    = $provider_api_key_map[$provider];
			$active_sender_field = $provider_sender_map[$provider];
			// اگه در POST بود از POST بخون، وگرنه از option قبلی
			$active_api_key = sanitize_text_field(wp_unslash($_POST[$active_key_field] ?? ''));
			$active_sender  = sanitize_text_field(wp_unslash($_POST[$active_sender_field] ?? ''));
			if (! $active_api_key) {
				$active_api_key = get_option($active_key_field, '');
			}
			if (! $active_sender) {
				$active_sender = get_option($active_sender_field, '');
			}
			if ($active_api_key) {
				update_option('sn_sms_api_key', $active_api_key);
			}
			if ($active_sender) {
				update_option('sn_sms_sender',  $active_sender);
			}
		}

		SN_Helpers::send_json(true, 'تنظیمات ذخیره شد');
	}

	public function ajax_repair_pages(): void
	{
		if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		if (! class_exists('SN_Activator') || ! method_exists('SN_Activator', 'create_required_pages')) {
			SN_Helpers::send_json(false, 'ماژول ساخت صفحات در دسترس نیست');
			return;
		}
		$result = SN_Activator::create_required_pages();
		update_option('sn_pages_repair_version', SN_VERSION);
		$duplicate_count = 0;
		foreach ($result as $item) {
			$duplicate_count += isset($item['duplicates']) && is_array($item['duplicates']) ? count($item['duplicates']) : 0;
		}
		$message = $duplicate_count > 0
			? 'صفحات سیستم بررسی و اصلاح شدند. ' . $duplicate_count . ' صفحه مشابه/تکراری گزارش شد و حذف نشد.'
			: 'صفحات سیستم بررسی و اصلاح شدند و duplicate فعالی پیدا نشد.';
		SN_Helpers::send_json(true, $message, ['pages' => $result, 'duplicates' => get_option('sn_page_duplicate_report', [])]);
	}

	// =========================================================
	// AJAX - SELLER
	// =========================================================

	public function ajax_create_invoice(): void
	{
		// پاک کردن هر output قبلی که ممکنه از jwt یا پلاگین دیگه‌ای باشه
		if (ob_get_level()) {
			ob_clean();
		}

		if (! is_user_logged_in()) {
			error_log('SN create_invoice: not logged in, user_id=' . get_current_user_id());
			SN_Helpers::send_json(false, 'لطفاً ابتدا وارد شوید');
			return;
		}
		if (! check_ajax_referer('sn_public', 'nonce', false)) {
			error_log('SN create_invoice: nonce failed nonce=' . ($_POST['nonce'] ?? 'EMPTY'));
			SN_Helpers::send_json(false, 'خطای امنیتی — صفحه را رفرش کنید');
			return;
		}

		$user = wp_get_current_user();
		if (! in_array('sn_seller', (array) $user->roles, true)) {
			error_log('SN create_invoice: user ' . $user->ID . ' has roles: ' . implode(',', $user->roles));
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز — نقش فروشنده ندارید');
			return;
		}

		$name    = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
		$phone   = SN_Helpers::normalize_mobile(sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? '')));
		$prov    = sanitize_text_field(wp_unslash($_POST['province'] ?? ''));
		$city    = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
		$prod_id = absint($_POST['product_id'] ?? 0);
		$product_ids_raw = $_POST['product_ids'] ?? [];
		$qtys_raw = $_POST['product_qtys'] ?? [];
		if (! is_array($product_ids_raw)) { $product_ids_raw = $product_ids_raw ? explode(',', (string) $product_ids_raw) : []; }
		if (! is_array($qtys_raw)) { $qtys_raw = []; }
		$product_ids = array_values(array_filter(array_map('absint', $product_ids_raw)));
		if (! $product_ids && $prod_id) { $product_ids = [$prod_id]; }
		$lead_id = absint($_POST['lead_id'] ?? 0);

		$this->maybe_create_tables();
		global $wpdb;
		if ($lead_id) {
			$lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_leads WHERE id=%d", $lead_id));
			if ($lead) {
				if ((int) $lead->seller_id !== (int) $user->ID) {
					SN_Helpers::send_json(false, 'این شماره به شما تخصیص داده نشده است');
					return;
				}
				if (empty($phone)) {
					$phone = SN_Helpers::normalize_mobile((string) $lead->phone);
				}
				if (empty($prov) && ! empty($lead->province)) {
					$prov = (string) $lead->province;
				}
				if (empty($city) && ! empty($lead->city)) {
					$city = (string) $lead->city;
				}
			}
		}

		if (empty($name) || ! SN_Helpers::is_valid_mobile($phone) || ! $product_ids) {
			SN_Helpers::send_json(false, 'اطلاعات ناقص است');
			return;
		}

		// بررسی محصول
		if (! class_exists('WooCommerce')) {
			SN_Helpers::send_json(false, 'ووکامرس فعال نیست');
			return;
		}
		$items = [];
		$total = 0.0;
		foreach ($product_ids as $idx => $pid) {
			$product = wc_get_product($pid);
			if (! $product) {
				error_log("SN create_invoice: product {$pid} not found");
				SN_Helpers::send_json(false, 'محصول یافت نشد (ID=' . $pid . ')');
				return;
			}
			$qty = isset($qtys_raw[$idx]) ? absint($qtys_raw[$idx]) : 1;
			if ($qty < 1) { $qty = 1; }
			$unit_price = (float) $product->get_price(); // قیمت فروش ویژه WooCommerce همینجا لحاظ می‌شود.
			$line_total = $unit_price * $qty;
			$total += $line_total;
			$items[] = ['product_id' => $pid, 'product_name' => $product->get_name(), 'qty' => $qty, 'unit_price' => $unit_price, 'total_price' => $line_total, 'is_free' => 0];
		}
		$prod_id = (int) $items[0]['product_id'];

		try {
			$code = SN_Helpers::generate_invoice_code();
		} catch (Throwable $e) {
			error_log('SN create_invoice: invoice code generation failed: ' . $e->getMessage());
			SN_Helpers::send_json(false, 'خطا در تولید کد فاکتور؛ لطفاً دوباره تلاش کنید');
			return;
		}
		// Debug logs are intentionally limited to failures in production stability builds.
		$wpdb->insert($wpdb->prefix . 'sn_invoices', [
			'invoice_code'   => $code,
			'seller_id'      => $user->ID,
			'lead_id'        => $lead_id ?: null,
			'customer_name'  => $name,
			'customer_phone' => $phone,
			'province'       => $prov,
			'city'           => $city,
			'product_id'     => $prod_id,
			'product_price'  => $total,
			'original_total' => $total,
			'discount_amount' => 0,
			'coupon_discount_amount' => 0,
			'discount_total' => 0,
			'final_total' => $total,
			'status'         => 'pre_invoice',
		]);
		$invoice_id = $wpdb->insert_id;
		foreach ($items as $it) {
			$wpdb->insert($wpdb->prefix . 'sn_invoice_items', $it + ['invoice_id' => $invoice_id, 'created_at' => current_time('mysql')]);
		}
		$this->sn_log_activity($invoice_id, $lead_id ?: null, 'invoice_created', 'صدور پیش‌فاکتور توسط فروشنده', ['seller_id' => $user->ID, 'product_ids' => $product_ids, 'amount' => $total]);

		// اگر lead بود وضعیتش رو به invoiced تغییر بده
		if ($lead_id) {
			$wpdb->update($wpdb->prefix . 'sn_leads', ['status' => 'invoiced'], ['id' => $lead_id]);
		}

		// ساخت یا پیدا کردن حساب کاربری مشتری
		$customer_wp_id = $this->get_or_create_customer_account($phone, $name);
		if ($customer_wp_id) {
			$wpdb->update($wpdb->prefix . 'sn_invoices', ['customer_wp_id' => $customer_wp_id], ['id' => $invoice_id]);
			$this->sn_sync_customer_profile_from_invoice($customer_wp_id, (object) [
				'id' => $invoice_id, 'invoice_code' => $code, 'customer_name' => $name, 'customer_phone' => $phone, 'province' => $prov, 'city' => $city
			]);
		}

		// ارسال SMS به مشتری
		$page_id = (int) get_option('sn_invoice_page_id');
		$account_url = wc_get_page_permalink('myaccount');
		$invoice_url = $page_id
			? add_query_arg('invoice', $code, get_permalink($page_id))
			: home_url('?invoice=' . $code);

		// دریافت مبلغ و شماره کارت
		$amount = number_format($total, 0, '', ',');
		$card_number = get_option('sn_card_number', '');

		$sms = new SN_SMS();
		$sms_sent = $sms->send_invoice_link($phone, $code, $invoice_url, $name, $amount, $card_number);
		$message = $sms_sent ? 'پیش‌فاکتور صادر شد و پیامک ارسال گردید' : 'پیش‌فاکتور صادر شد، اما ارسال پیامک ناموفق بود. لینک فاکتور را دستی ارسال کنید: ' . $invoice_url;

		SN_Helpers::send_json(true, $message, [
			'sms_sent'        => $sms_sent,
			'invoice_id'      => $invoice_id,
			'invoice_code'    => $code,
			'invoice_url'     => $invoice_url,
			'customer_wp_id'  => $customer_wp_id,
		]);
	}

	/**
	 * Fallback: صدور فاکتور از طریق form POST (برای وقتی AJAX مشکل داره)
	 */
	public function handle_create_invoice_post(): void
	{
		if (! is_user_logged_in()) {
			$auth = (int) get_option('sn_auth_page_id');
			wp_redirect($auth ? get_permalink($auth) : home_url());
			exit;
		}
		// همان منطق ajax_create_invoice
		$_POST['nonce'] = wp_create_nonce('sn_public'); // bypass nonce
		ob_start();
		$this->ajax_create_invoice_core();
		$result = ob_get_clean();
		$data = json_decode($result, true);
		$panel = (int) get_option('sn_seller_panel_page_id');
		$back  = $panel ? get_permalink($panel) : home_url();
		if ($data && $data['success']) {
			wp_redirect(add_query_arg('sn_ok', $data['invoice_code'], $back));
		} else {
			$msg = $data['message'] ?? 'خطا';
			wp_redirect(add_query_arg('sn_err_inv', urlencode($msg), $back));
		}
		exit;
	}

	/**
	 * پیدا کردن یا ساخت حساب کاربری مشتری بر اساس شماره موبایل.
	 * با پلاگین Digits سازگار است — username = شماره موبایل.
	 */
	private function get_or_create_customer_account(string $phone, string $name): int
	{
		// اول چک کن آیا کاربری با این شماره وجود داره
		$existing = get_user_by('login', $phone);
		if ($existing) {
			// اگه نقش customer نداره، اضافه کن
			if (! in_array('customer', (array) $existing->roles, true)) {
				$existing->add_role('customer');
			}
			return $existing->ID;
		}

		// ساخت کاربر جدید — رمز تصادفی (Digits بعدا میتونه reset کنه)
		$password = wp_generate_password(12, false);
		$email    = $phone . '@sn-customer.local'; // placeholder — Digits با phone_number کار میکنه

		$user_id = wp_insert_user([
			'user_login'   => $phone,
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => 'customer',
		]);

		if (is_wp_error($user_id)) {
			return 0;
		}

		// ست کردن متای phone_number برای سازگاری با Digits
		update_user_meta($user_id, 'digits_phone',        $phone);
		update_user_meta($user_id, 'digits_phone_no',     $phone);
		update_user_meta($user_id, 'billing_phone',       $phone);
		update_user_meta($user_id, 'billing_first_name',  $name);

		return $user_id;
	}


	private function sn_sync_customer_profile_from_invoice(int $customer_id, $invoice): void
	{
		if (! $customer_id || ! $invoice) { return; }
		$name = sanitize_text_field((string) ($invoice->customer_name ?? ''));
		$phone = SN_Helpers::normalize_mobile((string) ($invoice->customer_phone ?? ''));
		$province = sanitize_text_field((string) ($invoice->province ?? ''));
		$city = sanitize_text_field((string) ($invoice->city ?? ''));
		if ($name !== '') {
			wp_update_user(['ID' => $customer_id, 'display_name' => $name, 'first_name' => $name]);
			update_user_meta($customer_id, 'billing_first_name', $name);
		}
		if ($phone !== '') {
			update_user_meta($customer_id, 'digits_phone', $phone);
			update_user_meta($customer_id, 'digits_phone_no', $phone);
			update_user_meta($customer_id, 'billing_phone', $phone);
			update_user_meta($customer_id, 'sn_customer_phone', $phone);
		}
		if ($province !== '') {
			update_user_meta($customer_id, 'billing_state', $province);
			update_user_meta($customer_id, 'sn_customer_province', $province);
		}
		if ($city !== '') {
			update_user_meta($customer_id, 'billing_city', $city);
			update_user_meta($customer_id, 'sn_customer_city', $city);
		}
		update_user_meta($customer_id, 'sn_last_invoice_code', sanitize_text_field((string) ($invoice->invoice_code ?? '')));
		update_user_meta($customer_id, 'sn_last_invoice_id', (int) ($invoice->id ?? 0));
	}

	private function sn_create_wc_order_for_invoice(int $invoice_id, $invoice): int
	{
		if (! function_exists('wc_create_order') || ! $invoice_id || ! $invoice) { return 0; }
		global $wpdb;
		$fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $invoice_id));
		if ($fresh) { $invoice = $fresh; }
		if (! empty($invoice->wc_order_id) && get_post((int) $invoice->wc_order_id)) { return (int) $invoice->wc_order_id; }
		$customer_id = (int) ($invoice->customer_wp_id ?? 0);
		if (! $customer_id) {
			$customer_id = $this->get_or_create_customer_account((string) ($invoice->customer_phone ?? ''), (string) ($invoice->customer_name ?? ''));
			if ($customer_id) { $wpdb->update($wpdb->prefix . 'sn_invoices', ['customer_wp_id' => $customer_id], ['id' => $invoice_id]); }
		}
		$order = wc_create_order(['customer_id' => $customer_id ?: 0, 'created_via' => 'sales_network']);
		if (is_wp_error($order) || ! $order) { return 0; }
		$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d ORDER BY id ASC", $invoice_id));
		foreach ($items as $item) {
			$product = function_exists('wc_get_product') ? wc_get_product((int) $item->product_id) : null;
			$qty = max(1, (int) ($item->qty ?? 1));
			if ($product) {
				$order->add_product($product, $qty, [
					'subtotal' => (float) ($item->is_free ? 0 : $item->total_price),
					'total' => (float) ($item->is_free ? 0 : $item->total_price),
				]);
			} else {
				$wc_item = new WC_Order_Item_Product();
				$wc_item->set_name((string) ($item->product_name ?: 'محصول شبکه فروش'));
				$wc_item->set_quantity($qty);
				$wc_item->set_subtotal((float) ($item->is_free ? 0 : $item->total_price));
				$wc_item->set_total((float) ($item->is_free ? 0 : $item->total_price));
				$order->add_item($wc_item);
			}
		}
		$discount_total = (float) ($invoice->discount_total ?? 0);
		if ($discount_total > 0) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name('تخفیف شبکه فروش');
			$fee->set_amount(-1 * $discount_total);
			$fee->set_total(-1 * $discount_total);
			$order->add_item($fee);
		}
		$order->set_billing_first_name((string) ($invoice->customer_name ?? ''));
		$order->set_billing_phone((string) ($invoice->customer_phone ?? ''));
		$order->set_billing_city((string) ($invoice->city ?? ''));
		$order->update_meta_data('_sn_invoice_id', $invoice_id);
		$order->update_meta_data('_sn_invoice_code', (string) ($invoice->invoice_code ?? ''));
		$order->update_meta_data('_sn_payment_source', (string) ($invoice->payment_source ?? $invoice->pay_method ?? ''));
		$order->update_meta_data('_sn_receipt_file', (string) ($invoice->receipt_file ?? $invoice->receipt_url ?? ''));
		$order->update_meta_data('_sn_manual_paid_at_jalali', (string) ($invoice->manual_paid_at_jalali ?? $invoice->deposit_jalali_datetime ?? ''));
		$order->calculate_totals(false);
		$final = (float) ($invoice->final_total ?? $invoice->product_price ?? 0);
		if ($final >= 0) { $order->set_total($final); }
		$order->payment_complete();
		$order->update_status('completed', 'ثبت خودکار از شبکه فروش پس از تایید پرداخت');
		$order->save();
		$order_id = (int) $order->get_id();
		$wpdb->update($wpdb->prefix . 'sn_invoices', $this->sn_filter_existing_columns($wpdb->prefix . 'sn_invoices', ['wc_order_id' => $order_id]), ['id' => $invoice_id]);
		$this->sn_log_activity($invoice_id, (int) ($invoice->lead_id ?? 0), 'wc_order_created', 'سفارش ووکامرس از فاکتور شبکه فروش ساخته شد', ['order_id' => $order_id]);
		return $order_id;
	}

	public function ajax_seller_leads(): void
	{
		if (! is_user_logged_in() || ! check_ajax_referer('sn_public', 'nonce', false)) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		$user = wp_get_current_user();
		global $wpdb;
		$leads = $wpdb->get_results($wpdb->prepare(
			"SELECT l.*,
				MAX(CASE WHEN i.status='recontact_requested' THEN 1 ELSE 0 END) AS has_recontact,
				MAX(CASE WHEN i.status='recontact_requested' THEN i.recontact_requested_at ELSE NULL END) AS recontact_requested_at,
				MAX(CASE WHEN i.status='recontact_requested' THEN i.recontact_note ELSE NULL END) AS recontact_note,
				MAX(CASE WHEN i.status='recontact_requested' THEN i.invoice_code ELSE NULL END) AS recontact_invoice_code
			FROM {$wpdb->prefix}sn_leads l
			LEFT JOIN {$wpdb->prefix}sn_invoices i ON i.lead_id=l.id AND i.seller_id=l.seller_id
			WHERE l.seller_id=%d
			GROUP BY l.id
			ORDER BY COALESCE(MAX(CASE WHEN i.status='recontact_requested' THEN i.recontact_requested_at ELSE NULL END), l.assigned_at) DESC
			LIMIT 200",
			$user->ID
		), ARRAY_A);
		foreach ($leads as &$lead) {
			$lead['has_recontact'] = ! empty($lead['has_recontact']);
		}
		unset($lead);
		$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['leads' => $leads]);
	}

	// =========================================================
	// AJAX - SUPERVISOR
	// =========================================================

	public function ajax_supervisor_data(): void
	{
		if (! is_user_logged_in()) {
			SN_Helpers::send_json(false, 'وارد نشده‌اید');
			return;
		}
		$user = wp_get_current_user();
		if (! in_array('sn_supervisor', (array) $user->roles, true) && ! current_user_can('manage_options')) {
			SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
			return;
		}
		$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
		if (! $valid) {
			SN_Helpers::send_json(false, 'نانس نامعتبر');
			return;
		}

		global $wpdb;
		$supervisor_id = current_user_can('manage_options') ? absint($_POST['supervisor_id'] ?? 0) : (int) $user->ID;
		if (! $supervisor_id) {
			$supervisor_id = (int) $user->ID;
		}

		// دریافت فروشنده‌ها به صورت مقاوم: user_meta + fallback از لیدها.
		// اگر فروشنده‌ها در دیتابیس هستند ولی meta سرپرست آنها sync نشده باشد،
		// از lead.supervisor_id هم پیدا می‌شوند و لیست پنل سرپرست خالی نمی‌ماند.
		$seller_ids = [];
		if (current_user_can('manage_options') && empty($_POST['supervisor_id'])) {
			$seller_ids = get_users([
				'role__in' => ['sn_seller'],
				'fields'   => 'ids',
				'number'   => 1000,
			]);
		} else {
			$meta_seller_ids = get_users([
				'role__in'     => ['sn_seller'],
				'fields'       => 'ids',
				'number'       => 1000,
				'meta_key'     => 'sn_supervisor_id',
				'meta_value'   => (string) $supervisor_id,
				'meta_compare' => '=',
			]);
			$lead_seller_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT seller_id FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NOT NULL AND seller_id > 0", $supervisor_id));
			$seller_ids = array_unique(array_filter(array_map('absint', array_merge((array) $meta_seller_ids, (array) $lead_seller_ids))));
		}
		$sellers = [];
		foreach ($seller_ids as $sid) {
			$seller = get_user_by('id', $sid);
			if ($seller && in_array('sn_seller', (array) $seller->roles, true)) {
				$sellers[] = $seller;
			}
		}
		usort($sellers, static function ($a, $b) {
			return strnatcasecmp($a->display_name ?: $a->user_login, $b->display_name ?: $b->user_login);
		});

		$sellers_data = array_map(function ($s) use ($wpdb) {
			$lead_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE seller_id=%d", $s->ID));
			$invoice_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d", $s->ID));
			$paid_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d AND status='paid'", $s->ID));
			$active_meta = get_user_meta($s->ID, 'sn_is_active', true);
			return [
				'id' => $s->ID,
				'name' => $s->display_name,
				'phone' => $s->user_login,
				'lead_count' => (int) $lead_count,
				'invoice_count' => (int) $invoice_count,
				'paid_count' => (int) $paid_count,
				'is_active' => ($active_meta !== '0'),
			];
		}, $sellers);

		$pool = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NULL AND status='supervisor_pool'",
			$supervisor_id
		));

		$date_from = sanitize_text_field(wp_unslash($_POST['date_from'] ?? ''));
		$date_to   = sanitize_text_field(wp_unslash($_POST['date_to'] ?? ''));
		$time_from = sanitize_text_field(wp_unslash($_POST['time_from'] ?? ''));
		$time_to   = sanitize_text_field(wp_unslash($_POST['time_to'] ?? ''));
		$filter_seller_id = absint($_POST['seller_id'] ?? 0);
		$filter_lead_status = sanitize_text_field(wp_unslash($_POST['lead_status'] ?? ''));
		$filter_import_code = sanitize_text_field(wp_unslash($_POST['import_code'] ?? ''));
		$filter_assignment = sanitize_text_field(wp_unslash($_POST['assignment'] ?? ''));
		$where = ['supervisor_id=%d'];
		$args = [$supervisor_id];
		$date_from_g = SN_Helpers::jalali_to_gregorian_date($date_from);
		$date_to_g = SN_Helpers::jalali_to_gregorian_date($date_to);
		if ($date_from_g) {
			$where[] = 'DATE(assigned_at) >= %s';
			$args[] = $date_from_g;
		}
		if ($date_to_g) {
			$where[] = 'DATE(assigned_at) <= %s';
			$args[] = $date_to_g;
		}
		if (preg_match('/^\d{2}:\d{2}$/', $time_from)) {
			$where[] = 'TIME(assigned_at) >= %s';
			$args[] = $time_from . ':00';
		}
		if (preg_match('/^\d{2}:\d{2}$/', $time_to)) {
			$where[] = 'TIME(assigned_at) <= %s';
			$args[] = $time_to . ':59';
		}
		if ($filter_seller_id) {
			$where[] = 'seller_id = %d';
			$args[] = $filter_seller_id;
		}
		if ($filter_lead_status !== '') {
			$where[] = 'lead_status = %s';
			$args[] = $filter_lead_status;
		}
		if ($filter_import_code !== '') {
			$where[] = 'import_code = %s';
			$args[] = $filter_import_code;
		}
		if ($filter_assignment === 'assigned') {
			$where[] = 'seller_id IS NOT NULL';
		} elseif ($filter_assignment === 'unassigned') {
			$where[] = 'seller_id IS NULL';
		}
		$range_sql = " WHERE " . implode(' AND ', $where);
		$range_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads {$range_sql}", ...$args));
		$total_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d", $supervisor_id));
		$assigned_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NOT NULL", $supervisor_id));
		$invoiced_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND (" . ($this->sn_stats_definitions()['lead_completed']) . ")", $supervisor_id));
		$paid_count = $this->sn_count_metric('invoice','payment_financial_approved',["supervisor_id=%d"],[$supervisor_id]);
		$lead_statuses = $wpdb->get_col("SELECT label FROM {$wpdb->prefix}sn_lead_statuses WHERE is_active=1 ORDER BY sort_order ASC, id ASC");

		$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', [
			'sellers' => $sellers_data,
			'unassigned' => $pool,
			'supervisor_id' => $supervisor_id,
			'summary' => [
				'total' => $total_count,
				'total_leads' => $total_count,
				'assigned' => $assigned_count,
				'unassigned' => $pool,
				'range_assigned' => $range_count,
				'invoiced' => $invoiced_count,
				'invoices' => $invoiced_count,
				'paid' => $paid_count,
			],
			'lead_statuses' => $lead_statuses ?: [],
		]);
	}


	private function sn_public_error(string $message, string $code = 'sn_public_error', int $http = 400): void
	{
		wp_send_json(['success' => false, 'code' => $code, 'message' => $message], $http);
	}

	private function guard_public_invoice_request(string $method = 'POST')
	{
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
			$this->sn_public_error('متد درخواست نامعتبر است', 'invalid_method', 405);
			return null;
		}
		if (! check_ajax_referer('sn_public', 'nonce', false)) {
			$this->sn_public_error('نشست منقضی شده است. صفحه را رفرش کنید.', 'invalid_nonce', 403);
			return null;
		}
		$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
		$invoice = SN_Helpers::get_invoice_by_code($code);
		$token = sanitize_text_field(wp_unslash($_POST['public_token'] ?? ''));
		if (! $invoice || ! SN_Helpers::validate_public_invoice_access($invoice, $token)) {
			$this->sn_public_error('دسترسی به فاکتور مجاز نیست.', 'forbidden_invoice', 403);
			return null;
		}
		$ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'na');
		if (! SN_Helpers::enforce_rate_limit('pub|' . $code . '|' . $ip, 20, 60)) {
			$this->sn_public_error('تعداد درخواست بیش از حد مجاز است. لطفا کمی بعد تلاش کنید.', 'rate_limited', 429);
			return null;
		}
		return $invoice;
	}

	// =========================================================
	// AJAX - INVOICE PAGE (PUBLIC)
	// =========================================================

	public function ajax_invoice_info(): void
	{
		$invoice = $this->guard_public_invoice_request('POST');
		if (! $invoice) {
			return;
		}
		if (! $invoice) {
			SN_Helpers::send_json(false, 'فاکتور یافت نشد');
			return;
		}
		$card_number = get_option('sn_card_number', '');
		$card_owner  = get_option('sn_card_owner', '');
		global $wpdb;
		$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d ORDER BY id ASC", (int) $invoice->id), ARRAY_A);
		if (! $items) {
			$items = [[
				'product_id' => (int) $invoice->product_id,
				'product_name' => get_the_title($invoice->product_id),
				'qty' => 1,
				'unit_price' => (float) $invoice->product_price,
				'total_price' => (float) $invoice->product_price,
				'is_free' => 0,
			]];
		}
		$items_out = [];
		$sn_wheels_for_invoice = get_option('sn_lucky_wheels', []);
		if (! is_array($sn_wheels_for_invoice)) { $sn_wheels_for_invoice = []; }
		foreach ($items as $it) {
			$pid = (int) ($it['product_id'] ?? 0);
			$product = $pid && function_exists('wc_get_product') ? wc_get_product($pid) : null;
			$regular = $product ? (float) $product->get_regular_price() : (float) ($it['unit_price'] ?? 0);
			$sale = $product ? (float) $product->get_price() : (float) ($it['unit_price'] ?? 0);
			$short_description = (string) get_post_meta($pid, '_sn_short_description', true);
			if ($short_description === '' && $product && method_exists($product, 'get_short_description')) {
				$short_description = (string) $product->get_short_description();
			}
			if ($short_description === '' && $pid) {
				$short_description = (string) get_post_field('post_excerpt', $pid);
			}
			$image_url = '';
			$gallery_urls = [];
			if ($product) {
				$image_id = method_exists($product, 'get_image_id') ? (int) $product->get_image_id() : 0;
				if ($image_id) { $image_url = (string) wp_get_attachment_image_url($image_id, 'medium'); }
				$gallery_ids = method_exists($product, 'get_gallery_image_ids') ? (array) $product->get_gallery_image_ids() : [];
				foreach ($gallery_ids as $gid) {
					$gurl = wp_get_attachment_image_url((int) $gid, 'thumbnail');
					if ($gurl) { $gallery_urls[] = (string) $gurl; }
				}
			}
			$items_out[] = [
				'product_id' => $pid,
				'product_name' => (string) ($it['product_name'] ?? get_the_title($pid)),
				'qty' => (int) ($it['qty'] ?? 1),
				'unit_price' => (float) ($it['unit_price'] ?? $sale),
				'total_price' => (float) ($it['total_price'] ?? 0),
				'is_free' => ! empty($it['is_free']),
				'short_description' => wp_kses_post($short_description),
				'image_url' => esc_url_raw($image_url),
				'gallery_urls' => array_map('esc_url_raw', $gallery_urls),
				'regular_price' => $regular,
				'sale_price' => $sale,
				'has_sale' => $regular > $sale && $sale > 0,
				'lottery_chance_count' => (int) get_post_meta($pid, '_sn_lottery_chance_count', true),
				'has_lucky_wheel' => get_post_meta($pid, '_sn_has_lucky_wheel', true) === '1',
				'has_discount_coupon' => get_post_meta($pid, '_sn_has_discount_coupon', true) === '1',
				'wheel_id' => (string) get_post_meta($pid, '_sn_wheel_id', true),
				'wheel_title' => (function($wid, $wheels){ if ($wid && isset($wheels[$wid])) { return (string)($wheels[$wid]['title'] ?? ''); } $first = is_array($wheels) ? reset($wheels) : null; return is_array($first) ? (string)($first['title'] ?? '') : ''; })((string) get_post_meta($pid, '_sn_wheel_id', true), $sn_wheels_for_invoice),
				'wheel_description' => (function($wid, $wheels){ if ($wid && isset($wheels[$wid])) { return (string)($wheels[$wid]['description'] ?? ''); } $first = is_array($wheels) ? reset($wheels) : null; return is_array($first) ? (string)($first['description'] ?? '') : ''; })((string) get_post_meta($pid, '_sn_wheel_id', true), $sn_wheels_for_invoice),
				'wheel_segments' => (function($wid, $wheels){ if ($wid && isset($wheels[$wid]) && !empty($wheels[$wid]['segments'])) { return array_values((array)$wheels[$wid]['segments']); } $first = is_array($wheels) ? reset($wheels) : null; return (is_array($first) && !empty($first['segments'])) ? array_values((array)$first['segments']) : []; })((string) get_post_meta($pid, '_sn_wheel_id', true), $sn_wheels_for_invoice),
			];
		}
		$original_total = 0.0;
		foreach ($items_out as $io) {
			$original_unit = (! empty($io['has_sale']) && (float) $io['regular_price'] > 0) ? (float) $io['regular_price'] : (float) $io['unit_price'];
			$original_total += ! empty($io['is_free']) ? 0 : ($original_unit * max(1, (int) $io['qty']));
		}
		$final_total = (float) (($invoice->final_total ?? 0) ?: ($invoice->product_price ?? 0));
		$discount_total = (float) (($invoice->discount_total ?? 0) ?: max(0, $original_total - $final_total));
		if (isset($invoice->discount_amount)) { $discount_total = max($discount_total, (float) $invoice->discount_amount); }
		if (isset($invoice->coupon_discount_amount)) { $discount_total = max($discount_total, (float) $invoice->coupon_discount_amount + (float)($invoice->discount_amount ?? 0)); }
		$wheel_used = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoice_wheel WHERE invoice_id=%d", (int) $invoice->id));

		$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', [
			'invoice' => [
				'id'             => $invoice->id,
				'code'           => $invoice->invoice_code,
				'customer_name'  => $invoice->customer_name,
				'customer_phone' => $invoice->customer_phone,
				'province'       => $invoice->province,
				'city'           => $invoice->city,
				'product_id'     => $invoice->product_id,
				'product_name'   => get_the_title($invoice->product_id),
				'product_price'  => (float) (($invoice->final_total ?? 0) ?: ($invoice->product_price ?? 0)),
				'price_fmt'      => SN_Helpers::format_price((float) (($invoice->final_total ?? 0) ?: ($invoice->product_price ?? 0))),
				'final_price_fmt'=> SN_Helpers::format_price((float) (($invoice->final_total ?? 0) ?: ($invoice->product_price ?? 0))),
				'original_total'  => $original_total,
				'discount_total'  => $discount_total,
				'final_total'     => $final_total,
				'coupon_code'    => isset($invoice->coupon_code) ? (string) $invoice->coupon_code : '',
				'coupon_discount_amount' => isset($invoice->coupon_discount_amount) ? (float) $invoice->coupon_discount_amount : 0,
				'status'         => $invoice->status,
				'status_label'   => SN_Helpers::status_label((string) $invoice->status),
				'receipt_url'     => isset($invoice->receipt_url) ? $invoice->receipt_url : '',
				'manual_paid_at_jalali' => isset($invoice->manual_paid_at_jalali) ? $invoice->manual_paid_at_jalali : '',
				'items'          => $items_out,
				'discount_amount'=> isset($invoice->discount_amount) ? (float) $invoice->discount_amount : 0,
				'wheel_reward_summary' => isset($invoice->wheel_reward_summary) ? (string) $invoice->wheel_reward_summary : '',
				'wheel_used'     => $wheel_used > 0,
				'is_paid'        => in_array((string) $invoice->status, ['paid','approved'], true),
			],
			'card'    => ['number' => $card_number, 'owner' => $card_owner],
			'logs' => $timeline,
			'settings' => [
				'wheel_company_name' => get_option('sn_wheel_company_name', ''),
				'lottery_text_template' => get_option('sn_lottery_text_template', 'با پرداخت این فاکتور {count} شانس برای شرکت در قرعه‌کشی {company} دریافت می‌کنید.'),
				'recontact_popup_text' => get_option('sn_recontact_popup_text', 'اگر پیش از پرداخت فاکتور از کارشناس خود سوالی دارید، دکمه ارتباط مجدد با کارشناس را بزنید.'),
				'info_show_short_desc' => get_option('sn_invoice_info_show_short_desc', '1'),
				'info_show_price' => get_option('sn_invoice_info_show_price', '1'),
				'info_show_lottery' => get_option('sn_invoice_info_show_lottery', '1'),
				'info_show_coupon' => get_option('sn_invoice_info_show_coupon', '1'),
				'info_show_image' => get_option('sn_invoice_info_show_image', '1'),
				'info_show_gallery' => get_option('sn_invoice_info_show_gallery', '1'),
				'btn_show_product_info' => get_option('sn_invoice_btn_show_product_info', '1'),
				'btn_show_lottery' => get_option('sn_invoice_btn_show_lottery', '1'),
				'btn_show_wheel' => get_option('sn_invoice_btn_show_wheel', '1'),
				'btn_show_coupon' => get_option('sn_invoice_btn_show_coupon', '1'),
				'btn_show_recontact' => get_option('sn_invoice_btn_show_recontact', '1'),
				'btn_show_online_payment' => get_option('sn_invoice_btn_show_online_payment', '1'),
				'btn_show_card_payment' => get_option('sn_invoice_btn_show_card_payment', '1'),
			],
		]);
	}

	public function ajax_pay_online(): void
	{
		$invoice = $this->guard_public_invoice_request('POST');
		if (! $invoice) {
			return;
		}
		if (! $invoice || ! in_array($invoice->status, ['pending', 'pre_invoice'], true)) {
			SN_Helpers::send_json(false, 'فاکتور نامعتبر یا قبلاً پرداخت شده');
			return;
		}

		$gw = new SN_Invoice();
		$page_id      = (int) get_option('sn_invoice_page_id');
		$callback_url = add_query_arg([
			'sn_callback' => '1',
			'invoice_id'  => $invoice->id,
		], $page_id ? get_permalink($page_id) : home_url());

		$result = $gw->zarinpal_request(
			$invoice->id,
			(float) ($invoice->final_total ?: $invoice->product_price),
			'پرداخت فاکتور ' . $invoice->invoice_code,
			$invoice->customer_phone,
			$callback_url
		);

		if (isset($result['error'])) {
			SN_Helpers::send_json(false, $result['error']);
			return;
		}

		$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['redirect' => $result['url']]);
	}

	public function ajax_upload_receipt(): void
	{
		$invoice = $this->guard_public_invoice_request('POST');
		if (! $invoice) {
			return;
		}
		$allowed = ['pending', 'pre_invoice', 'rejected', 'pending_payment', 'receipt_uploaded', 'pending_financial_approval'];
		if (! $invoice || ! in_array((string) $invoice->status, $allowed, true)) {
			SN_Helpers::send_json(false, 'این فاکتور در وضعیت قابل ثبت فیش نیست');
			return;
		}

		if (empty($_FILES['receipt'])) {
			SN_Helpers::send_json(false, 'فایل آپلود نشد');
			return;
		}

		$url = SN_Helpers::upload_receipt($_FILES['receipt']); // phpcs:ignore
		if (! $url) {
			SN_Helpers::send_json(false, 'خطا در آپلود فایل');
			return;
		}

		if (! $this->sn_save_invoice_manual_payment((int) $invoice->id, 'customer_upload', ['receipt_url' => $url])) {
			SN_Helpers::send_json(false, 'ذخیره فیش در دیتابیس انجام نشد: ' . ($GLOBALS['wpdb']->last_error ?: 'خطای نامشخص'));
			return;
		}
		SN_Helpers::send_json(true, 'فیش با موفقیت ثبت شد و در وضعیت نیاز به بررسی فیش قرار گرفت', [
			'invoice_id' => (int) $invoice->id,
			'status' => 'pending_financial_approval',
			'status_label' => SN_Helpers::status_label('pending_financial_approval'),
			'receipt_url' => $url,
		]);
	}

	// =========================================================
	// ZARINPAL CALLBACK
	// =========================================================

	public function handle_zarinpal_callback(): void
	{
		if (empty($_GET['sn_callback']) || empty($_GET['invoice_id'])) {
			return;
		}
		if (empty($_GET['Authority']) || empty($_GET['Status'])) {
			return;
		}

		$invoice_id = absint($_GET['invoice_id']);
		$authority  = sanitize_text_field(wp_unslash($_GET['Authority']));
		$status     = sanitize_text_field(wp_unslash($_GET['Status']));

		global $wpdb;
		$invoice = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",
			$invoice_id
		));

		if (! $invoice) {
			wp_die('فاکتور یافت نشد');
		}

		if ($status !== 'OK') {
			wp_redirect(add_query_arg(
				['invoice' => $invoice->invoice_code, 'pay_result' => 'failed'],
				get_permalink((int) get_option('sn_invoice_page_id')) ?: home_url()
			));
			exit;
		}

		$gw     = new SN_Invoice();
		$result = $gw->zarinpal_verify($authority, (float) ($invoice->final_total ?: $invoice->product_price));

		if ($result['success']) {
			$ref_id = $result['ref_id'];
			$wpdb->update($wpdb->prefix . 'sn_invoices', [
				'status'     => 'paid',
				'pay_method' => 'online',
				'paid_at'    => current_time('mysql'),
			], ['id' => $invoice_id]);
			$wpdb->update($wpdb->prefix . 'sn_payments', [
				'status' => 'paid',
				'ref_id' => $ref_id,
			], ['authority' => $authority]);

			// اعطای محتوا و بروزرسانی حساب مشتری
			$invoice->pay_method = 'online';
			$invoice->status = 'paid';
			do_action('sn_invoice_paid', $invoice_id, $invoice);

			// اگه مشتری لاگین نیست ولی wp_id داره، auto-login
			if (! is_user_logged_in()) {
				$fresh_invoice = $wpdb->get_row($wpdb->prepare(
					"SELECT customer_wp_id FROM {$wpdb->prefix}sn_invoices WHERE id=%d",
					$invoice_id
				));
				if ($fresh_invoice && $fresh_invoice->customer_wp_id) {
					wp_set_auth_cookie((int) $fresh_invoice->customer_wp_id, false);
				}
			}

			wp_redirect(add_query_arg(
				['invoice' => $invoice->invoice_code, 'pay_result' => 'success', 'ref_id' => $ref_id],
				get_permalink((int) get_option('sn_invoice_page_id')) ?: home_url()
			));
		} else {
			wp_redirect(add_query_arg(
				['invoice' => $invoice->invoice_code, 'pay_result' => 'failed'],
				get_permalink((int) get_option('sn_invoice_page_id')) ?: home_url()
			));
		}
		exit;
	}

	// =========================================================
	// SHORTCODE RENDERERS
	// =========================================================

	public function render_auth(): string
	{
		$user     = wp_get_current_user();
		// redirect فقط اگه صریحاً در URL پاس شده باشه — وگرنه خالی بذار تا handler به panel بفرسته
		$redirect = sanitize_url(wp_unslash($_GET['redirect_to'] ?? ''));
		$err      = sanitize_text_field(wp_unslash($_GET['sn_err'] ?? ''));

		if (is_user_logged_in() && in_array('sn_seller', (array) $user->roles, true)) {
			$panel_id  = (int) get_option('sn_seller_panel_page_id', 0);
			$panel_url = $panel_id ? get_permalink($panel_id) : home_url();
			return '<script>window.location.href=' . json_encode($panel_url) . ';</script>'
				. '<p class="sn-notice sn-success">وارد شده‌اید — در حال انتقال...</p>';
		}

		$errors = [
			'empty'     => 'شماره موبایل یا رمز عبور خالی است.',
			'notfound'  => 'فروشنده‌ای با این شماره یافت نشد.',
			'wrongpass' => 'رمز عبور اشتباه است.',
			'invalid'   => 'اطلاعات وارد شده نامعتبر است.',
			'exists'    => 'این شماره قبلاً ثبت شده است.',
			'create'    => 'خطا در ایجاد حساب.',
		];
		$err_msg = $err ? ($errors[$err] ?? 'خطا رخ داد.') : '';

		ob_start();
	?>
		<div class="sn-auth-wrap" dir="rtl">

			<div class="sn-auth-logo">
				<h2>🛍️ پنل فروشنده</h2>
				<p>برای ادامه وارد شوید یا ثبت‌نام کنید</p>
			</div>

			<div class="sn-auth-card">

				<!-- تب‌ها با inline JS — وابستگی صفر به تم یا jQuery -->
				<div class="sn-auth-tabs">
					<button class="sn-auth-tab active" id="sn-atab-login"
						onclick="snAuthTab('login')" type="button">ورود</button>
					<button class="sn-auth-tab" id="sn-atab-register"
						onclick="snAuthTab('register')" type="button">ثبت‌نام</button>
				</div>

				<!-- فرم ورود -->
				<div id="sn-auth-login" class="sn-auth-body" style="display:block">
					<?php if ($err_msg) : ?>
						<div class="sn-auth-error"><?php echo esc_html($err_msg); ?></div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="sn_seller_login">
						<input type="hidden" name="redirect" value="<?php echo esc_attr($redirect); ?>">
						<div class="sn-field">
							<label>📱 شماره موبایل</label>
							<input type="tel" name="phone" required placeholder="09xxxxxxxxx" autocomplete="username">
						</div>
						<div class="sn-field">
							<label>🔒 رمز عبور</label>
							<input type="password" name="password" required autocomplete="current-password">
						</div>
						<button type="submit" class="sn-auth-submit">ورود به پنل</button>
					</form>
				</div>

				<!-- فرم ثبت‌نام -->
				<div id="sn-auth-register" class="sn-auth-body" style="display:none">
					<?php if ($err_msg) : ?>
						<div class="sn-auth-error"><?php echo esc_html($err_msg); ?></div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="sn_seller_register">
						<input type="hidden" name="redirect" value="<?php echo esc_attr($redirect); ?>">
						<div class="sn-field">
							<label>👤 نام و نام خانوادگی</label>
							<input type="text" name="name" required placeholder="نام کامل شما" autocomplete="name">
						</div>
						<div class="sn-field">
							<label>📱 شماره موبایل</label>
							<input type="tel" name="phone" required placeholder="09xxxxxxxxx" autocomplete="username">
						</div>
						<div class="sn-field">
							<label>🔒 رمز عبور <small style="font-weight:400;color:#94a3b8">(حداقل ۶ کاراکتر)</small></label>
							<input type="password" name="password" required minlength="6" autocomplete="new-password">
						</div>
						<button type="submit" class="sn-auth-submit">ثبت‌نام در پنل</button>
					</form>
				</div>

				<div class="sn-auth-footer">
					سامانه فروش — <?php echo esc_html(get_bloginfo('name')); ?>
				</div>

			</div><!-- .sn-auth-card -->

			<script>
				function snAuthTab(tab) {
					document.getElementById('sn-auth-login').style.display = (tab === 'login') ? 'block' : 'none';
					document.getElementById('sn-auth-register').style.display = (tab === 'register') ? 'block' : 'none';
					document.getElementById('sn-atab-login').className = 'sn-auth-tab' + (tab === 'login' ? ' active' : '');
					document.getElementById('sn-atab-register').className = 'sn-auth-tab' + (tab === 'register' ? ' active' : '');
				}
			</script>
		</div><!-- .sn-auth-wrap -->
	<?php
		return ob_get_clean();
	}

	public function render_supervisor_auth(): string
	{
		$user = wp_get_current_user();
		$err  = sanitize_text_field(wp_unslash($_GET['sn_err'] ?? ''));

		if (is_user_logged_in() && (in_array('sn_supervisor', (array) $user->roles, true) || current_user_can('manage_options'))) {
			$panel_id  = (int) get_option('sn_supervisor_panel_page_id', 0);
			$panel_url = $panel_id ? get_permalink($panel_id) : home_url();
			return '<script>window.location.href=' . json_encode($panel_url) . ';</script>'
				. '<p class="sn-notice sn-success">وارد شده‌اید — در حال انتقال...</p>';
		}

		$errors = [
			'empty'     => 'شماره موبایل/نام کاربری یا رمز عبور خالی است.',
			'notfound'  => 'سرپرستی با این مشخصات یافت نشد.',
			'wrongpass' => 'رمز عبور اشتباه است.',
		];
		$err_msg = $err ? ($errors[$err] ?? 'خطا رخ داد.') : '';

		ob_start();
	?>
		<div class="sn-auth-wrap" dir="rtl">
			<div class="sn-auth-logo">
				<h2>👥 ورود سرپرست</h2>
				<p>برای ورود به پنل سرپرست، شماره موبایل/نام کاربری و رمز عبور را وارد کنید.</p>
			</div>
			<div class="sn-auth-card">
				<?php if ($err_msg) : ?>
					<div class="sn-auth-error"><?php echo esc_html($err_msg); ?></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="sn_supervisor_login">
					<div class="sn-field">
						<label>📱 شماره موبایل / نام کاربری</label>
						<input type="text" name="phone" required placeholder="09xxxxxxxxx یا نام کاربری" autocomplete="username">
					</div>
					<div class="sn-field">
						<label>🔒 رمز عبور</label>
						<input type="password" name="password" required autocomplete="current-password">
					</div>
					<button type="submit" class="sn-auth-submit">ورود به پنل سرپرست</button>
				</form>
				<div class="sn-auth-footer">سامانه فروش — <?php echo esc_html(get_bloginfo('name')); ?></div>
			</div>
		</div>
	<?php
		return ob_get_clean();
	}

	public function render_sales_manager_auth(): string
	{
		$user = wp_get_current_user();
		$err  = sanitize_text_field(wp_unslash($_GET['sn_err'] ?? ''));
		if (is_user_logged_in() && (in_array('sn_sales_manager', (array) $user->roles, true) || current_user_can('manage_options'))) {
			$panel_id = (int) get_option('sn_sales_manager_panel_page_id', 0);
			$panel_url = $panel_id ? get_permalink($panel_id) : home_url();
			return '<script>window.location.href=' . json_encode($panel_url) . ';</script><p class="sn-notice sn-success">وارد شده‌اید — در حال انتقال...</p>';
		}
		$errors = ['empty' => 'نام کاربری/موبایل یا رمز عبور خالی است.', 'notfound' => 'مدیر فروشی با این مشخصات یافت نشد.', 'wrongpass' => 'رمز عبور اشتباه است.'];
		$err_msg = $err ? ($errors[$err] ?? 'خطا رخ داد.') : '';
		ob_start();
	?>
		<div class="sn-auth-wrap" dir="rtl">
			<div class="sn-auth-logo"><h2>ورود مدیر فروش</h2><p>برای مدیریت تخصیص و گزارش‌ها وارد شوید.</p></div>
			<div class="sn-auth-card">
				<?php if ($err_msg) : ?><div class="sn-auth-error"><?php echo esc_html($err_msg); ?></div><?php endif; ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="sn_sales_manager_login">
					<div class="sn-field"><label>شماره موبایل / نام کاربری</label><input type="text" name="phone" required autocomplete="username"></div>
					<div class="sn-field"><label>رمز عبور</label><input type="password" name="password" required autocomplete="current-password"></div>
					<button type="submit" class="sn-auth-submit">ورود به پنل مدیر فروش</button>
				</form>
				<div class="sn-auth-footer">سامانه فروش — <?php echo esc_html(get_bloginfo('name')); ?></div>
			</div>
		</div>
	<?php
		return ob_get_clean();
	}

	public function render_sales_manager_panel(): string
	{
		if (! is_user_logged_in()) {
			$auth_id = (int) get_option('sn_sales_manager_auth_page_id', 0);
			$url = $auth_id ? get_permalink($auth_id) : wp_login_url();
			return '<script>window.location.href=' . json_encode($url) . ';</script><div class="sn-notice">در حال انتقال به صفحه ورود...</div>';
		}
		if (! current_user_can('manage_options') && ! current_user_can('sn_view_sales_reports')) {
			return '<div class="sn-notice sn-error">دسترسی غیرمجاز.</div>';
		}
		global $wpdb;
		$supervisors = get_users(['role' => 'sn_supervisor', 'number' => 500]);
		$sellers = get_users(['role' => 'sn_seller', 'number' => 1000]);
		$lead_statuses = $wpdb->get_results("SELECT label FROM {$wpdb->prefix}sn_lead_statuses WHERE is_active=1 ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads");
		$raw = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE status='unassigned' AND seller_id IS NULL AND supervisor_id IS NULL");
		$pool = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE status='supervisor_pool'");
		$assigned = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE seller_id IS NOT NULL");
		ob_start();
	?>
		<div class="sn-panel sn-no-sidebar" id="sn-sales-manager-panel" dir="rtl">
			<div class="sn-panel-header"><h2>پنل مدیر فروش</h2></div>
			<div class="sn-kpi-grid">
				<div class="sn-kpi-card"><small>کل شماره‌ها</small><strong><?php echo esc_html(number_format_i18n($total)); ?></strong></div>
				<div class="sn-kpi-card"><small>خام آماده تخصیص</small><strong><?php echo esc_html(number_format_i18n($raw)); ?></strong></div>
				<div class="sn-kpi-card"><small>داخل پنل سرپرست</small><strong><?php echo esc_html(number_format_i18n($pool)); ?></strong></div>
				<div class="sn-kpi-card"><small>تخصیص به فروشنده</small><strong><?php echo esc_html(number_format_i18n($assigned)); ?></strong></div>
			</div>
			<div class="sn-card">
				<h3>تخصیص شماره خام به سرپرست</h3>
				<div id="sn-manager-assign-notice"></div>
				<div class="sn-form-grid">
					<div class="sn-field"><label>سرپرست</label><select id="sn-manager-supervisor"><option value="">انتخاب سرپرست</option><?php foreach ($supervisors as $sup) : ?><option value="<?php echo esc_attr($sup->ID); ?>"><?php echo esc_html($sup->display_name . ' — ' . $sup->user_login); ?></option><?php endforeach; ?></select></div>
					<div class="sn-field"><label>تعداد</label><input type="number" id="sn-manager-count" min="1"></div>
					<div class="sn-field"><label>کد واردات</label><input type="text" id="sn-manager-import-code" placeholder="اختیاری"></div>
				</div>
				<button type="button" id="sn-manager-assign" class="sn-btn sn-btn-primary">انتقال به سرپرست</button>
			</div>
			<div class="sn-card sn-manager-report-card">
				<div class="sn-card-head">
					<h3>لیدهای آپلودشده و گزارش مدیر فروش</h3>
					<a href="#" id="sn-manager-export" class="sn-btn sn-btn-secondary" data-export-base="<?php echo esc_url(admin_url('admin-post.php?action=sn_sales_manager_export')); ?>">خروجی CSV/Excel</a>
				</div>
				<div id="sn-manager-report-notice"></div>
				<div class="sn-manager-filters">
					<div class="sn-field"><label>جستجو</label><input type="search" id="sn-manager-search" placeholder="شماره، شهر، استان، یادداشت"></div>
					<div class="sn-field"><label>کد واردات</label><input type="text" id="sn-manager-import-code-filter" placeholder="batch/import code"></div>
					<div class="sn-field"><label>از تاریخ ورود</label><input type="text" class="sn-jalali-date" id="sn-manager-date-from" placeholder="1403/02/18"></div>
					<div class="sn-field"><label>تا تاریخ ورود</label><input type="text" class="sn-jalali-date" id="sn-manager-date-to" placeholder="1403/02/18"></div>
					<div class="sn-field"><label>از ساعت</label><input type="time" id="sn-manager-time-from"></div>
					<div class="sn-field"><label>تا ساعت</label><input type="time" id="sn-manager-time-to"></div>
					<div class="sn-field"><label>وضعیت سیستمی</label><select id="sn-manager-status"><option value="all">همه</option><option value="unassigned">بدون تخصیص</option><option value="supervisor_pool">در پنل سرپرست</option><option value="assigned">تخصیص به فروشنده</option><option value="invoiced">دارای پیش‌فاکتور</option></select></div>
					<div class="sn-field"><label>وضعیت تخصیص</label><select id="sn-manager-assignment"><option value="all">همه</option><option value="unassigned">آماده تخصیص به سرپرست</option><option value="supervisor_pool">در اختیار سرپرست</option><option value="assigned">دارای فروشنده</option></select></div>
					<div class="sn-field"><label>سرپرست</label><select id="sn-manager-supervisor-filter"><option value="">همه سرپرست‌ها</option><?php foreach ($supervisors as $sup) : ?><option value="<?php echo esc_attr($sup->ID); ?>"><?php echo esc_html($sup->display_name); ?></option><?php endforeach; ?></select></div>
					<div class="sn-field"><label>فروشنده</label><select id="sn-manager-seller-filter"><option value="">همه فروشنده‌ها</option><?php foreach ($sellers as $seller) : ?><option value="<?php echo esc_attr($seller->ID); ?>"><?php echo esc_html($seller->display_name); ?></option><?php endforeach; ?></select></div>
					<div class="sn-field"><label>وضعیت تماس</label><select id="sn-manager-lead-status"><option value="">همه</option><?php foreach ($lead_statuses as $st) : ?><option value="<?php echo esc_attr($st['label']); ?>"><?php echo esc_html($st['label']); ?></option><?php endforeach; ?></select></div>
					<div class="sn-field sn-manager-filter-actions"><label>&nbsp;</label><button type="button" id="sn-manager-filter" class="sn-btn sn-btn-primary">اعمال فیلتر</button></div>
				</div>
				<div class="sn-manager-report-meta">نتیجه فیلتر: <strong id="sn-manager-total">0</strong> لید</div>
				<div id="sn-manager-leads-list" class="sn-table-wrap">در حال بارگذاری...</div>
			</div>
		</div>
	<?php
		return ob_get_clean();
	}
	// --- پنل فروشنده ---

			private function sn_invoice_items_label(int $invoice_id, int $fallback_product_id = 0): string
			{
				global $wpdb;
				$items = $wpdb->get_results($wpdb->prepare("SELECT product_name, qty FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d ORDER BY id ASC", $invoice_id), ARRAY_A);
				if (!$items) { return $fallback_product_id ? (string) get_the_title($fallback_product_id) : '—'; }
				$parts = [];
				foreach ($items as $it) { $parts[] = trim((string)$it['product_name']) . ((int)$it['qty'] > 1 ? ' × ' . (int)$it['qty'] : ''); }
				return implode('، ', $parts);
			}

	public function render_seller_panel(): string
	{
		if (! is_user_logged_in()) {
			// در shortcode نمیشه header ارسال کرد — با JS redirect کن
			$auth_page_id  = (int) get_option('sn_auth_page_id', 0);
			$panel_page_id = (int) get_option('sn_seller_panel_page_id', 0);
			$current_url   = get_permalink($panel_page_id) ?: home_url($_SERVER['REQUEST_URI'] ?? '/');
			if ($auth_page_id) {
				$login_url = add_query_arg('redirect_to', urlencode($current_url), get_permalink($auth_page_id));
				return '<script>window.location.href=' . json_encode($login_url) . ';</script>'
					. '<div class="sn-notice">در حال انتقال به صفحه ورود...</div>';
			}
			return '<div class="sn-notice sn-error">صفحه ورود فروشنده تنظیم نشده — از پنل ادمین → تنظیمات، ID صفحه ورود را وارد کنید.</div>';
		}
		$user = wp_get_current_user();
		if (! in_array('sn_seller', (array) $user->roles, true)) {
			return '<p class="sn-notice sn-error">دسترسی غیرمجاز — این صفحه فقط برای فروشندگان است.</p>';
		}

		$products = SN_Helpers::get_sn_products();
		$provinces = SN_Helpers::get_provinces();

		ob_start();
	?>
		<div class="sn-panel" dir="rtl" id="sn-seller-panel">
			<div class="sn-panel-header">
				<h2>پنل فروشنده — <?php echo esc_html($user->display_name); ?></h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
					<input type="hidden" name="action" value="sn_seller_logout">
					<input type="hidden" name="redirect" value="<?php echo esc_url(get_permalink()); ?>">
					<button type="submit" class="sn-btn sn-btn-sm">خروج</button>
				</form>
			</div>

			<!-- تب‌ها -->
			<div class="sn-tabs">
				<button class="sn-tab active" data-tab="leads">شماره‌های من</button>
				<button class="sn-tab" data-tab="new-invoice">صدور پیش‌فاکتور</button>
				<button class="sn-tab" data-tab="invoices">فاکتورها</button>
				<button class="sn-tab" data-tab="customer-actions">رفتار مشتریان</button>
				<button class="sn-tab" data-tab="wallet">کیف پول من</button>
			</div>

			<!-- لیست شماره‌ها -->
			<div id="sn-tab-leads" class="sn-tab-content active">
				<div id="sn-leads-loading" class="sn-loading">در حال بارگذاری...</div>
				<div id="sn-leads-filter-bar"></div>
				<div id="sn-leads-list"></div>
			</div>

			<!-- فرم ثبت فاکتور -->
			<div id="sn-tab-new-invoice" class="sn-tab-content">
				<div class="sn-card">
					<h3>ثبت فاکتور جدید</h3>
					<div id="sn-invoice-notice"></div>
					<div class="sn-form-grid">
						<div class="sn-field">
							<label>نام مشتری *</label>
							<input type="text" id="sn-cust-name" placeholder="نام و نام خانوادگی">
						</div>
						<div class="sn-field">
							<label>شماره موبایل مشتری *</label>
							<input type="tel" id="sn-cust-phone" placeholder="09xxxxxxxxx">
						</div>
						<div class="sn-field">
							<label>استان</label>
							<select id="sn-cust-prov">
								<option value="">انتخاب استان</option>
								<?php foreach ($provinces as $p) : ?>
									<option value="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="sn-field">
							<label>شهر</label>
							<input type="text" id="sn-cust-city" placeholder="نام شهر">
						</div>
						<div class="sn-field sn-full">
							<label>محصول‌ها *</label>
							<div id="sn-products-multi" class="sn-products-multi">
								<div class="sn-product-row">
									<select class="sn-product-select" id="sn-product">
										<option value="">انتخاب محصول</option>
										<?php foreach ($products as $p) : ?>
											<option value="<?php echo esc_attr($p['id']); ?>" data-price="<?php echo esc_attr($p['price']); ?>"><?php echo esc_html($p['name']); ?> — <?php echo esc_html(SN_Helpers::format_price($p['price'])); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="number" class="sn-product-qty" min="1" value="1" aria-label="تعداد">
									<button type="button" class="sn-btn sn-btn-ghost sn-remove-product" style="display:none">حذف</button>
								</div>
							</div>
							<button type="button" id="sn-add-product-row" class="sn-btn sn-btn-secondary sn-btn-sm">+ افزودن محصول دیگر</button>
							<div id="sn-products-total" class="sn-products-total">جمع: ۰ تومان</div>
						</div>
						<div class="sn-field sn-full">
							<label>شماره مشتری از لیست (اختیاری)</label>
							<select id="sn-lead-select">
								<option value="">— بدون تخصیص —</option>
							</select>
						</div>
					</div>
					<button type="button" id="sn-create-invoice" class="sn-btn sn-btn-primary">صدور پیش‌فاکتور و ارسال پیامک</button>
				</div>
			</div>

			<!-- لیست فاکتورها -->
			<div id="sn-tab-invoices" class="sn-tab-content">
				<div class="sn-subtabs sn-invoice-status-tabs">
					<button type="button" class="sn-subtab active" data-status="all">همه</button>
					<button type="button" class="sn-subtab" data-status="pre_invoice">پیش‌فاکتور صادر شده</button>
					<button type="button" class="sn-subtab" data-status="paid">پرداخت شده</button>
					<button type="button" class="sn-subtab" data-status="rejected">رد شده</button>
					<button type="button" class="sn-subtab" data-status="recontact">ارتباط مجدد با کارشناس</button>
				</div>
				<div id="sn-invoices-loading" class="sn-loading">در حال بارگذاری...</div>
				<div id="sn-invoices-list"></div>
			</div>
			<div id="sn-tab-customer-actions" class="sn-tab-content">
				<div class="sn-card">
					<h3>رفتار مشتریان در صفحه فاکتور</h3>
					<p class="sn-muted">باز شدن لینک، مشاهده اطلاعات محصول، کلیک روی گردونه، کد تخفیف، پرداخت و سایر اکشن‌های مشتری اینجا نمایش داده می‌شود.</p>
					<div id="sn-customer-actions-loading" class="sn-loading" style="display:none">در حال بارگذاری...</div>
					<div id="sn-customer-actions-list"></div>
				</div>
			</div>
			<div id="sn-tab-wallet" class="sn-tab-content">
				<div class="sn-subtabs sn-wallet-tabs"><button type="button" class="sn-subtab active" data-wallet-filter="all">همه</button><button type="button" class="sn-subtab" data-wallet-filter="online">کیف پول پرداختی‌های آنلاین</button><button type="button" class="sn-subtab" data-wallet-filter="card_to_card">کیف پول پرداخت‌های کارت‌به‌کارتی</button></div>
				<?php echo $this->render_wallet_box_for_user(get_current_user_id(), 'seller'); ?>
			</div>
		</div>
	<?php
		return ob_get_clean();
	}

	// --- پنل سرپرست ---
	public function render_supervisor_panel(): string
	{
		if (! is_user_logged_in()) {
			return '<p class="sn-notice">لطفاً وارد شوید.</p>';
		}
		$user = wp_get_current_user();
		$is_supervisor = in_array('sn_supervisor', (array) $user->roles, true) || current_user_can('manage_options');
		if (! $is_supervisor) {
			return '<p class="sn-notice sn-error">دسترسی غیرمجاز.</p>';
		}

		ob_start();
	?>
		<div class="sn-panel" dir="rtl" id="sn-supervisor-panel">
			<div class="sn-panel-header">
				<h2>پنل سرپرست</h2>
				<span id="sn-unassigned-count" class="sn-badge">...</span> شماره تخصیص‌نیافته
			</div>

			<div class="sn-tabs">
				<button class="sn-tab active" data-tab="sellers">فروشندگان</button>
				<button class="sn-tab" data-tab="assign">تخصیص شماره</button>
				<button class="sn-tab" data-tab="unassign">جدا کردن لید</button>
				<button class="sn-tab" data-tab="invoices">فاکتورها</button>
				<button class="sn-tab" data-tab="wallet">کیف پول سرپرست</button>
			</div>

			<!-- لیست فروشندگان -->
			<div id="sn-tab-sellers" class="sn-tab-content active">

			<div class="sn-card sn-supervisor-summary-card">
				<div class="sn-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;align-items:end">
					<div><strong>کل شماره‌ها</strong>
						<div id="sn-sum-total" class="sn-badge">...</div>
					</div>
					<div><strong>تخصیص داده‌شده</strong>
						<div id="sn-sum-assigned" class="sn-badge">...</div>
					</div>
					<div><strong>تخصیص داده‌نشده</strong>
						<div id="sn-sum-unassigned" class="sn-badge">...</div>
					</div>
					<div><strong>تعداد در بازه</strong>
						<div id="sn-sum-range" class="sn-badge">...</div>
					</div>
					<div><strong>دارای فاکتور</strong>
						<div id="sn-sum-invoiced" class="sn-badge">...</div>
					</div>
					<div><strong>فروش موفق</strong>
						<div id="sn-sum-paid" class="sn-badge">...</div>
					</div>
					<div class="sn-field"><label>از تاریخ</label><input type="text" class="sn-jalali-date" id="sn-date-from" placeholder="1403/02/18"></div>
					<div class="sn-field"><label>تا تاریخ</label><input type="text" class="sn-jalali-date" id="sn-date-to" placeholder="1403/02/18"></div>
					<div class="sn-field"><label>از ساعت</label><input type="time" id="sn-time-from"></div>
					<div class="sn-field"><label>تا ساعت</label><input type="time" id="sn-time-to"></div>
					<div class="sn-field"><label>فروشنده</label><select id="sn-summary-seller"><option value="">همه</option></select></div>
					<div class="sn-field"><label>وضعیت تماس</label><select id="sn-summary-lead-status"><option value="">همه</option></select></div>
					<div class="sn-field"><label>کد واردات/شماره</label><input type="text" id="sn-summary-import-code"></div>
					<div class="sn-field"><label>تخصیص</label><select id="sn-summary-assignment"><option value="">همه</option><option value="assigned">تخصیص‌شده</option><option value="unassigned">تخصیص‌نشده</option></select></div>
					<div><button type="button" id="sn-summary-filter" class="sn-btn sn-btn-secondary">اعمال فیلتر</button></div>
					<div><button type="button" id="sn-summary-export" class="sn-btn sn-btn-ghost">خروجی CSV</button></div>
				</div>
			</div>
				<div id="sn-sellers-loading" class="sn-loading">در حال بارگذاری...</div>
				<div id="sn-sellers-table"></div>
			</div>

			<!-- فرم تخصیص -->
			<div id="sn-tab-assign" class="sn-tab-content">
				<div class="sn-card">
					<h3>تخصیص شماره به فروشندگان</h3>
					<div id="sn-assign-notice"></div>

					<div class="sn-field">
						<label>روش تخصیص</label>
						<div class="sn-radio-group">
							<label><input type="radio" name="assign_mode" value="count" checked> تعداد مشخص به هر فروشنده</label>
							<label><input type="radio" name="assign_mode" value="manual"> انتخاب دستی شماره‌ها</label>
						</div>
					</div>

					<!-- حالت تعداد -->
					<div id="sn-assign-count-mode">
						<div class="sn-field">
							<label>تعداد شماره به هر فروشنده</label>
							<input type="number" id="sn-count-per-seller" min="1" value="" placeholder="تعداد را وارد کنید"><small class="description">تا زمانی که تعداد وارد نشود دکمه تخصیص غیرفعال است.</small>
						</div>
						<div class="sn-field">
							<label>فروشنده‌ها (چندانتخابی)</label>
							<div id="sn-sellers-checkboxes" class="sn-checkbox-list">در حال بارگذاری...</div>
						</div>
					</div>

					<!-- حالت دستی -->
					<div id="sn-assign-manual-mode" style="display:none">
						<div class="sn-field">
							<label>فروشنده</label>
							<select id="sn-manual-seller"></select>
						</div>
						<div class="sn-field">
							<label>شماره‌های تخصیص‌نیافته</label>
							<div id="sn-unassigned-list" class="sn-phone-list">در حال بارگذاری...</div>
						</div>
					</div>

					<button type="button" id="sn-do-assign" class="sn-btn sn-btn-primary" disabled>اعمال تخصیص</button>
				</div>
			</div>

			<div id="sn-tab-unassign" class="sn-tab-content" style="display:none">
				<div class="sn-card">
					<h3>جدا کردن لید از فروشنده</h3>
					<div id="sn-unassign-notice"></div>
					<div class="sn-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
						<div class="sn-field"><label>فروشنده</label><select id="sn-unassign-seller">
								<option value="">همه فروشنده‌ها</option>
							</select></div>
						<div class="sn-field"><label>آخرین N شماره</label><input type="number" id="sn-unassign-count" min="1" placeholder="مثلاً 20"></div>
						<div class="sn-field"><label>از تاریخ تخصیص</label><input type="text" class="sn-jalali-date" id="sn-unassign-date-from" placeholder="1403/02/18"></div>
						<div class="sn-field"><label>تا تاریخ تخصیص</label><input type="text" class="sn-jalali-date" id="sn-unassign-date-to" placeholder="1403/02/18"></div>
						<div class="sn-field"><label>از ساعت</label><input type="time" id="sn-unassign-time-from"></div>
						<div class="sn-field"><label>تا ساعت</label><input type="time" id="sn-unassign-time-to"></div>
						<div class="sn-field"><label>وضعیت تماس</label><select id="sn-unassign-lead-status"><option value="">همه</option></select></div>
						<div class="sn-field"><label>کد واردات/شماره</label><input type="text" id="sn-unassign-import-code"></div>
					</div>
					<button type="button" id="sn-do-unassign" class="sn-btn sn-btn-secondary">جدا کردن و برگشت به لیست قابل تخصیص</button>
				</div>
			</div>

			<div id="sn-tab-invoices" class="sn-tab-content" style="display:none">
				<div class="sn-subtabs sn-supervisor-invoice-tabs"><button type="button" class="sn-subtab active" data-status="pre_invoice">پیش‌فاکتور</button><button type="button" class="sn-subtab" data-status="online_paid">پرداخت آنلاین</button><button type="button" class="sn-subtab" data-status="receipt_uploaded">فیش آپلود شده</button><button type="button" class="sn-subtab" data-status="rejected">رد شده</button><button type="button" class="sn-subtab" data-status="needs_review">نیاز به بررسی مالی</button></div>
				<div id="sn-supervisor-invoices-list" class="sn-card"><div class="sn-loading">در حال بارگذاری...</div></div>
			</div>
			<div id="sn-tab-wallet" class="sn-tab-content" style="display:none">
				<?php echo $this->render_wallet_box_for_user(get_current_user_id(), 'supervisor'); ?>
			</div>
		</div>
	<?php
		return ob_get_clean();
	}

	// --- صفحه فاکتور مشتری ---
	public function render_invoice_page(): string
	{
		$code       = sanitize_text_field(wp_unslash($_GET['invoice'] ?? ''));
		$pay_result = sanitize_text_field(wp_unslash($_GET['pay_result'] ?? ''));
		$ref_id     = sanitize_text_field(wp_unslash($_GET['ref_id'] ?? ''));

		ob_start();
	?>
		<div class="sn-invoice-page" dir="rtl" id="sn-invoice-page"
			data-code="<?php echo esc_attr($code); ?>"
			data-result="<?php echo esc_attr($pay_result); ?>"
			data-ref="<?php echo esc_attr($ref_id); ?>">
			<?php if ($pay_result === 'success') : ?>
				<div class="sn-notice sn-success">
					✅ پرداخت با موفقیت انجام شد.<br>
					کد پیگیری: <strong><?php echo esc_html($ref_id); ?></strong><br><br>
					<?php
					$myaccount_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';
					if ($myaccount_url) :
					?>
						<a href="<?php echo esc_url($myaccount_url . 'sn-invoices/'); ?>" class="sn-btn sn-btn-primary" style="display:inline-block;margin-top:8px">
							📋 مشاهده فاکتورهای من در حساب کاربری
						</a>
					<?php endif; ?>
				</div>
			<?php elseif ($pay_result === 'failed') : ?>
				<div class="sn-notice sn-error">❌ پرداخت ناموفق بود. لطفاً مجدداً تلاش کنید.</div>
			<?php endif; ?>

			<div id="sn-invoice-lookup">
				<div class="sn-card">
					<h2>مشاهده فاکتور</h2>
					<div class="sn-field">
						<label>کد فاکتور</label>
						<input type="text" id="sn-inv-code" value="<?php echo esc_attr($code); ?>" placeholder="INV-XXXXXXXX">
					</div>
					<button id="sn-load-invoice" class="sn-btn sn-btn-primary">مشاهده فاکتور</button>
				</div>
			</div>

			<div id="sn-invoice-detail" style="display:none">
				<div class="sn-card sn-invoice-card">
					<div class="sn-invoice-header">
						<h2>فاکتور فروش</h2>
						<span class="sn-invoice-code" id="sn-inv-display-code"></span>
					</div>
					<div class="sn-invoice-body">
						<div class="sn-invoice-row"><span>نام مشتری:</span> <strong id="sn-inv-name"></strong></div>
						<div class="sn-invoice-row"><span>شماره موبایل:</span> <strong id="sn-inv-phone"></strong></div>
						<div class="sn-invoice-row"><span>استان/شهر:</span> <strong id="sn-inv-location"></strong></div>
						<div class="sn-invoice-row"><span>محصول:</span> <strong id="sn-inv-product"></strong></div>
						<div class="sn-invoice-row"><span>وضعیت:</span> <strong id="sn-inv-status"></strong></div>
						<div class="sn-invoice-row sn-invoice-total"><span>مبلغ:</span> <strong id="sn-inv-price"></strong></div>
					</div>

					<div id="sn-payment-section" class="sn-payment-section">
						<h3>انتخاب روش پرداخت</h3>
						<div class="sn-pay-methods">
							<button id="sn-pay-online" class="sn-btn sn-btn-primary">💳 پرداخت آنلاین (درگاه)</button>
							<button id="sn-pay-card" class="sn-btn sn-btn-secondary">📱 کارت به کارت</button>
						</div>

						<div id="sn-card-info" style="display:none" class="sn-card-info">
							<p>لطفاً مبلغ را به شماره کارت زیر واریز کنید:</p>
							<div class="sn-card-number" id="sn-card-number"></div>
							<div class="sn-card-owner">به نام: <span id="sn-card-owner"></span></div>
							<hr>
							<p>پس از واریز، فیش پرداخت را بارگذاری کنید:</p>
							<input type="file" id="sn-receipt-file" accept="image/*,.pdf">
							<button id="sn-upload-receipt" class="sn-btn sn-btn-primary">ارسال فیش</button>
							<hr>
							<button type="button" id="sn-card-manual-toggle" class="sn-btn sn-btn-secondary">در صورت تمایل میتوانید به جای آپلود فیش اطلاعات واریزی خود را ثبت کنید</button>
							<div id="sn-card-manual-fields" style="display:none;margin-top:12px">
								<div class="sn-field"><label>۴ رقم آخر کارت مبدا</label><input type="text" id="sn-card-from4" maxlength="4" inputmode="numeric" placeholder="1234"></div>
								<div class="sn-field"><label>۴ رقم آخر کارت مقصد</label><input type="text" id="sn-card-to4" maxlength="4" inputmode="numeric" placeholder="5678"></div>
								<div class="sn-field"><label>مبلغ</label><input type="number" id="sn-card-amount" min="1" placeholder="مبلغ واریزی"></div>
								<div class="sn-field"><label>تاریخ و ساعت واریز شمسی</label><input type="hidden" id="sn-card-paid-at">
									<div id="sn-card-paid-at-picker" class="sn-inline-paid-datetime" aria-label="انتخاب تاریخ و ساعت شمسی"></div>
								</div>
								<button type="button" id="sn-submit-manual-payment" class="sn-btn sn-btn-primary">ثبت اطلاعات واریز</button>
							</div>
						</div>
					</div>

					<div id="sn-inv-paid-msg" style="display:none" class="sn-notice sn-success">
						این فاکتور قبلاً پرداخت شده است.
					</div>
				</div>
			</div>
		</div>
	<?php
		return ob_get_clean();
	}

	private function sn_can_hr(): bool
	{
		return current_user_can('manage_options') || current_user_can('sn_hr_manage') || current_user_can('sn_hr');
	}

	public function ensure_hr_role(): void
	{
		if (! get_role('sn_hr')) {
			add_role('sn_hr', 'منابع انسانی', ['read' => true, 'sn_hr_manage' => true, 'sn_hr' => true]);
		}
	}

	public function render_hr_panel(): string
	{
		if (! is_user_logged_in() || ! $this->sn_can_hr()) { return '<div class="sn-card" dir="rtl">دسترسی غیرمجاز</div>'; }
		ob_start(); ?>
		<div class="sn-hr-panel" dir="rtl">
			<div class="sn-tabs"><button class="sn-tab active" data-tab="emp">کارمندان</button><button class="sn-tab" data-tab="levels">سمت‌ها و سطح‌ها</button><button class="sn-tab" data-tab="org">ساختار سازمانی</button><button class="sn-tab" data-tab="salary">حقوق ثابت</button><button class="sn-tab" data-tab="cm">مدل‌های پورسانت</button><button class="sn-tab" data-tab="tr">درخواست‌های جابجایی</button><button class="sn-tab" data-tab="ex">خروجی کامل کارمند</button><button class="sn-tab" data-tab="rep">گزارش حقوق و پورسانت</button></div>
			<div class="sn-card" id="sn-hr-diagnostics" style="margin-bottom:10px;display:none"></div><div id="sn-hr-content" class="sn-card">در حال بارگذاری...</div>
		</div><?php
		return ob_get_clean();
	}

	private function sn_hr_guard(): bool
	{
		if (! is_user_logged_in() || ! $this->sn_can_hr() || ! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return false; }
		return true;
	}

	public function ajax_hr_list_employees(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb;
		$q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
		$role = sanitize_key($_POST['role_key'] ?? '');
		$employment = sanitize_key($_POST['employment_status'] ?? '');
		$where='1=1'; $args=[];
		if($q!==''){ $where .= " AND (p.full_name LIKE %s OR p.phone LIKE %s OR p.role_key LIKE %s)"; $like='%'.$wpdb->esc_like($q).'%'; array_push($args,$like,$like,$like); }
		if($role!==''){ $where .= " AND p.role_key=%s"; $args[]=$role; }
		if(in_array($employment,['training','contract'],true)){ $where .= " AND p.employment_status=%s"; $args[]=$employment; }
		$sql="SELECT p.*, u.user_login, u.display_name, lp.title AS level_title, pu.display_name AS parent_name FROM {$wpdb->prefix}sn_hr_employee_profiles p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id LEFT JOIN {$wpdb->prefix}sn_hr_levels lp ON lp.id=p.level_id LEFT JOIN {$wpdb->users} pu ON pu.ID=p.parent_user_id WHERE {$where} ORDER BY p.id DESC LIMIT 300";
		$rows=$args?$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A):$wpdb->get_results($sql,ARRAY_A);
		SN_Helpers::send_json(true,'',['items'=>$rows?:[]]);
	}
	public function ajax_hr_update_employee(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb;
		$id=absint($_POST['id']??0); if(!$id){SN_Helpers::send_json(false,'شناسه نامعتبر');return;}
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE id=%d",$id)); if(!$row){SN_Helpers::send_json(false,'کارمند یافت نشد');return;}
		$target=get_user_by('id',(int)$row->user_id); if($target && in_array('administrator',(array)$target->roles,true) && ! current_user_can('manage_options')){SN_Helpers::send_json(false,'ویرایش ادمین مجاز نیست');return;}
		$data=['role_key'=>sanitize_key($_POST['role_key']??$row->role_key),'level_id'=>absint($_POST['level_id']??0)?:null,'parent_user_id'=>absint($_POST['parent_user_id']??0)?:null,'base_salary'=>(float)($_POST['base_salary']??$row->base_salary),'employment_status'=>in_array($_POST['employment_status']??'', ['training','contract'],true)?sanitize_key($_POST['employment_status']):$row->employment_status,'is_active'=>!empty($_POST['is_active'])?1:0,'updated_at'=>current_time('mysql')]
		;
		$wpdb->update($wpdb->prefix.'sn_hr_employee_profiles',$data,['id'=>$id]);
		SN_Helpers::send_json(true,'ذخیره شد');
	}
	public function ajax_hr_levels(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb; $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_hr_levels ORDER BY sort_order ASC, id ASC",ARRAY_A); SN_Helpers::send_json(true,'',['items'=>$rows?:[]]);
	}
	public function ajax_hr_save_level(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb; $id=absint($_POST['id']??0); $title=sanitize_text_field(wp_unslash($_POST['title']??'')); if($title===''){SN_Helpers::send_json(false,'عنوان الزامی است');return;} $active=!empty($_POST['is_active'])?1:0;
		if($id){ if(!$active){$use=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE level_id=%d",$id)); if($use>0){SN_Helpers::send_json(false,'این سطح در حال استفاده است');return;}} $wpdb->update($wpdb->prefix.'sn_hr_levels',['title'=>$title,'sort_order'=>(int)($_POST['sort_order']??0),'is_active'=>$active,'updated_at'=>current_time('mysql')],['id'=>$id]); }
		else{$wpdb->insert($wpdb->prefix.'sn_hr_levels',['level_key'=>sanitize_title($title).'-'.time(),'title'=>$title,'sort_order'=>(int)($_POST['sort_order']??0),'is_system'=>0,'is_active'=>$active,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);}
		SN_Helpers::send_json(true,'ذخیره شد');
	}
	public function ajax_hr_commission_models(): void
	{ if (! $this->sn_hr_guard()) return; global $wpdb; $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_hr_commission_models ORDER BY id DESC LIMIT 500", ARRAY_A); SN_Helpers::send_json(true,'',['items'=>$rows?:[]]); }
	public function ajax_hr_save_commission_model(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb; $id=absint($_POST['id']??0);
		$data=['title'=>sanitize_text_field(wp_unslash($_POST['title']??'')),'role_key'=>sanitize_key($_POST['role_key']??'sn_seller'),'level_id'=>absint($_POST['level_id']??0)?:null,'employment_status'=>in_array($_POST['employment_status']??'all',['training','contract','all'],true)?sanitize_key($_POST['employment_status']):'all','payment_method'=>in_array($_POST['payment_method']??'all',['online','card_to_card','all'],true)?sanitize_key($_POST['payment_method']):'all','commission_type'=>in_array($_POST['commission_type']??'percent',['percent','fixed'],true)?sanitize_key($_POST['commission_type']):'percent','commission_value'=>(float)($_POST['commission_value']??0),'applies_to'=>in_array($_POST['applies_to']??'seller',['seller','supervisor','manager','custom'],true)?sanitize_key($_POST['applies_to']):'seller','is_active'=>!empty($_POST['is_active'])?1:0,'updated_at'=>current_time('mysql')];
		if($data['title']===''){SN_Helpers::send_json(false,'عنوان الزامی است');return;}
		if($id){$wpdb->update($wpdb->prefix.'sn_hr_commission_models',$data,['id'=>$id]);}
		else{$data['created_at']=current_time('mysql');$wpdb->insert($wpdb->prefix.'sn_hr_commission_models',$data);}
		SN_Helpers::send_json(true,'ذخیره شد');
	}

	private function sn_hr_my_profile_row(): ?array
	{
		if (! is_user_logged_in()) return null; global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d", get_current_user_id()), ARRAY_A);
	}
	
	private function sn_hr_is_descendant(int $user_id, int $possible_parent): bool
	{
		global $wpdb; $guard=0; $cur=$possible_parent;
		while($cur && $guard<30){ if($cur===$user_id) return true; $cur=(int)$wpdb->get_var($wpdb->prepare("SELECT parent_user_id FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d", $cur)); $guard++; }
		return false;
	}
	private function sn_hr_can_access_employee(int $employee_user_id): bool
	{
		if ($this->sn_can_hr() || current_user_can('manage_options')) return true;
		global $wpdb; $me=$this->sn_hr_my_profile_row(); if(!$me) return false;
		return ! $this->sn_hr_is_descendant((int)$me['user_id'], $employee_user_id) && ((int)$wpdb->get_var($wpdb->prepare("SELECT parent_user_id FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d",$employee_user_id))===(int)$me['user_id']);
	}
private function sn_hr_is_upstream_approver(array $req): bool
	{
		if (current_user_can('manage_options') || $this->sn_can_hr()) return true;
		$me = $this->sn_hr_my_profile_row();
		if (! $me) return false;
		return (int)($req['from_parent_user_id'] ?? 0) === (int)($me['user_id'] ?? 0);
	}
	private function sn_hr_next_status(array $req): string
	{
		if (empty($req['from_parent_user_id'])) return 'sent_to_hr';
		global $wpdb;
		$parent = $wpdb->get_row($wpdb->prepare("SELECT parent_user_id FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d", (int)$req['from_parent_user_id']), ARRAY_A);
		return !empty($parent['parent_user_id']) ? 'pending_parent_approval' : 'sent_to_hr';
	}
	private function sn_hr_transfer_log(int $request_id, string $action, ?string $from, ?string $to, string $note=''): void
	{ SN_HR_Transfers::log($request_id, $action, $from, $to, $note); }

	public function ajax_hr_transfer_create(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb;
		$employee_user_id = absint($_POST['employee_user_id'] ?? 0);
		$type = sanitize_key($_POST['request_type'] ?? 'transfer');
		$to_parent_user_id = absint($_POST['to_parent_user_id'] ?? 0) ?: null;
		$reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
		$effective = sanitize_text_field(wp_unslash($_POST['effective_from'] ?? ''));
		if (! in_array($type,['transfer','no_need','termination'],true) || ! $employee_user_id || $reason===''){ SN_Helpers::send_json(false,'اطلاعات درخواست نامعتبر است'); return; }
		if ($type==='transfer' && ! $to_parent_user_id) { SN_Helpers::send_json(false,'برای جابجایی، سرپرست مقصد الزامی است'); return; }
		if ($to_parent_user_id && $to_parent_user_id===$employee_user_id){ SN_Helpers::send_json(false,'کارمند نمی‌تواند سرپرست خودش باشد'); return; }
		$me = $this->sn_hr_my_profile_row();
		$emp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d", $employee_user_id), ARRAY_A);
		if (! $emp) { SN_Helpers::send_json(false,'کارمند یافت نشد'); return; }
		if (! current_user_can('manage_options') && ! $this->sn_can_hr() && (int)$emp['parent_user_id'] !== (int)($me['user_id']??0)) { SN_Helpers::send_json(false,'این کارمند زیرمجموعه شما نیست'); return; }
		if ($to_parent_user_id) { $target=$wpdb->get_row($wpdb->prepare("SELECT user_id,parent_user_id,is_active FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d",$to_parent_user_id),ARRAY_A); if(!$target|| (int)$target['is_active']!==1){SN_Helpers::send_json(false,'سرپرست مقصد نامعتبر یا غیرفعال است');return;} if($this->sn_hr_is_descendant($employee_user_id,$to_parent_user_id)){SN_Helpers::send_json(false,'انتخاب مقصد باعث حلقه سازمانی می‌شود');return;} }
		$status = 'pending_parent_approval';
		$wpdb->insert($wpdb->prefix.'sn_hr_transfer_requests', ['employee_user_id'=>$employee_user_id,'requested_by_user_id'=>get_current_user_id(),'from_parent_user_id'=>(int)($emp['parent_user_id']??0),'to_parent_user_id'=>$to_parent_user_id,'request_type'=>$type,'reason'=>$reason,'status'=>$status,'final_hr_user_id'=>null,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
		$id=(int)$wpdb->insert_id;
		if($effective!==''){ $wpdb->update($wpdb->prefix.'sn_hr_transfer_requests',['updated_at'=>current_time('mysql')],['id'=>$id]); }
		$this->sn_hr_transfer_log($id,'create',null,$status,$reason);
		SN_Helpers::send_json(true,'درخواست ثبت شد و در مسیر تایید قرار گرفت',['id'=>$id]);
	}

	public function ajax_hr_transfer_list(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb;
		$tab=sanitize_key($_POST['tab']??'mine'); $me=$this->sn_hr_my_profile_row();
		$where='1=1'; $args=[];
		if ($this->sn_can_hr()) { if($tab==='hr_queue'){$where="status='sent_to_hr'";} }
		else { if($tab==='approvals'){ $where="from_parent_user_id=%d AND status IN ('pending_parent_approval','approved_by_parent')"; $args[]=(int)($me['user_id']??0);} else { $where='requested_by_user_id=%d'; $args[]=get_current_user_id(); } }
		$sql="SELECT r.*, e.full_name, e.role_key FROM {$wpdb->prefix}sn_hr_transfer_requests r LEFT JOIN {$wpdb->prefix}sn_hr_employee_profiles e ON e.user_id=r.employee_user_id WHERE {$where} ORDER BY r.id DESC LIMIT 300";
		$rows=$args?$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A):$wpdb->get_results($sql,ARRAY_A);
		foreach($rows as &$r){ $r['logs']=$wpdb->get_results($wpdb->prepare("SELECT l.*, u.display_name FROM {$wpdb->prefix}sn_hr_transfer_logs l LEFT JOIN {$wpdb->users} u ON u.ID=l.actor_user_id WHERE transfer_request_id=%d ORDER BY id DESC",(int)$r['id']),ARRAY_A); }
		SN_Helpers::send_json(true,'',['items'=>$rows?:[]]);
	}

	public function ajax_hr_transfer_action(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb;
		$id=absint($_POST['id']??0); $action=sanitize_key($_POST['do']??''); $note=sanitize_textarea_field(wp_unslash($_POST['note']??''));
		$req=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_transfer_requests WHERE id=%d",$id),ARRAY_A); if(!$req){SN_Helpers::send_json(false,'درخواست یافت نشد');return;}
		$from=(string)$req['status']; $to=$from;
		if (in_array($action,['approve','reject','request_changes'],true) && ! $this->sn_hr_is_upstream_approver($req)){ SN_Helpers::send_json(false,'دسترسی تایید ندارید'); return; }
		if (in_array($from,['completed','rejected'],true)) { SN_Helpers::send_json(false,'این درخواست نهایی شده و قابل تغییر نیست'); return; }
		if ($action==='finalize' && ! $this->sn_can_hr()) { SN_Helpers::send_json(false,'فقط منابع انسانی می‌تواند نهایی کند'); return; }
		if ($action==='approve') { $to = $this->sn_can_hr() ? 'completed' : $this->sn_hr_next_status($req); }
		elseif ($action==='reject') { $to='rejected'; }
		elseif ($action==='request_changes') { $to='approved_by_parent'; }
		elseif ($action==='finalize' && $this->sn_can_hr()) { $to='completed'; } else if(!in_array($action,['approve','reject','request_changes','finalize'],true)){ SN_Helpers::send_json(false,'اقدام نامعتبر'); return; }
		$wpdb->update($wpdb->prefix.'sn_hr_transfer_requests',['status'=>$to,'final_hr_user_id'=>$this->sn_can_hr()?get_current_user_id():null,'updated_at'=>current_time('mysql')],['id'=>$id]);
		$this->sn_hr_transfer_log($id,$action,$from,$to,$note);
		if ($to==='completed') {
			$emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d",(int)$req['employee_user_id']),ARRAY_A);
			if($emp){
				$new_parent = $req['request_type']==='transfer' ? (int)$req['to_parent_user_id'] : null;
				if($req['request_type']==='termination'){ $wpdb->update($wpdb->prefix.'sn_hr_employee_profiles',['is_active'=>0,'left_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['user_id'=>(int)$req['employee_user_id']]); }
				elseif($req['request_type']==='no_need'){ $wpdb->update($wpdb->prefix.'sn_hr_employee_profiles',['parent_user_id'=>null,'updated_at'=>current_time('mysql')],['user_id'=>(int)$req['employee_user_id']]); }
				else { $wpdb->update($wpdb->prefix.'sn_hr_employee_profiles',['parent_user_id'=>$new_parent,'updated_at'=>current_time('mysql')],['user_id'=>(int)$req['employee_user_id']]); }
				$wpdb->insert($wpdb->prefix.'sn_hr_employee_assignment_history',['user_id'=>(int)$req['employee_user_id'],'previous_parent_user_id'=>$emp['parent_user_id']?:null,'new_parent_user_id'=>$new_parent,'previous_role_key'=>$emp['role_key'],'new_role_key'=>$emp['role_key'],'previous_level_id'=>$emp['level_id']?:null,'new_level_id'=>$emp['level_id']?:null,'changed_by_user_id'=>get_current_user_id(),'reason'=>$note?:$req['reason'],'effective_from'=>current_time('mysql'),'created_at'=>current_time('mysql')]);
			}
		}
		SN_Helpers::send_json(true,'درخواست بروزرسانی شد',['status'=>$to]);
	}

	public function ajax_hr_employee_export(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb; $user_id=absint($_POST['user_id']??0); $summary=!empty($_POST['summary']); $limit=min(500,max(50,absint($_POST['limit']??200))); if(!$user_id){SN_Helpers::send_json(false,'شناسه نامعتبر');return;} if(! $this->sn_can_hr()){ $me=$this->sn_hr_my_profile_row(); $owned=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d AND parent_user_id=%d",$user_id,(int)($me['user_id']??0))); if(!$owned){SN_Helpers::send_json(false,'اجازه مشاهده این کارمند را ندارید');return;} }
		$profile=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE user_id=%d",$user_id),ARRAY_A);
		if(!$profile){SN_Helpers::send_json(false,'کارمند یافت نشد');return;}
		$invoice_ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d OR supervisor_id=%d ORDER BY id DESC LIMIT %d",$user_id,$user_id,$limit)); $ph=$invoice_ids?implode(',',array_fill(0,count($invoice_ids),'%d')):'';
		$data=['profile'=>$profile,'summary'=>['invoice_count'=>(int)count($invoice_ids),'lead_count'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE seller_id=%d OR supervisor_id=%d",$user_id,$user_id))]];
		if(!$summary){
			$data['assignment_history']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_employee_assignment_history WHERE user_id=%d ORDER BY id DESC LIMIT %d",$user_id,$limit),ARRAY_A);
			$data['leads']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_leads WHERE seller_id=%d OR supervisor_id=%d ORDER BY id DESC LIMIT %d",$user_id,$user_id,$limit),ARRAY_A);
			$data['invoices']=$invoice_ids?$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id IN ($ph) ORDER BY id DESC",...$invoice_ids),ARRAY_A):[];
			$data['wallet']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_wallet_transactions WHERE user_id=%d ORDER BY id DESC LIMIT %d",$user_id,$limit),ARRAY_A);
			$data['salary']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_salary_ledger WHERE user_id=%d ORDER BY id DESC LIMIT %d",$user_id,$limit),ARRAY_A);
			$data['transfer_requests']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_transfer_requests WHERE employee_user_id=%d ORDER BY id DESC LIMIT %d",$user_id,$limit),ARRAY_A);
			$data['transfer_logs']=$wpdb->get_results($wpdb->prepare("SELECT l.* FROM {$wpdb->prefix}sn_hr_transfer_logs l INNER JOIN {$wpdb->prefix}sn_hr_transfer_requests r ON r.id=l.transfer_request_id WHERE r.employee_user_id=%d ORDER BY l.id DESC LIMIT %d",$user_id,$limit),ARRAY_A);
			if($invoice_ids){ $params=array_merge($invoice_ids,[$limit]); $data['activity']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_activity_logs WHERE invoice_id IN ($ph) ORDER BY id DESC LIMIT %d",...$params),ARRAY_A); $data['invoice_workflow_logs']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_logs WHERE invoice_id IN ($ph) ORDER BY id DESC LIMIT %d",...$params),ARRAY_A);} else {$data['activity']=[];$data['invoice_workflow_logs']=[];}
		}
		SN_Helpers::send_json(true,'',['data'=>$data]);
	}

	private function sn_hr_commission_context_parent_at(string $effective_from, int $employee_user_id): array
	{
		global $wpdb;
		$before = $wpdb->get_row($wpdb->prepare("SELECT previous_parent_user_id, new_parent_user_id, effective_from FROM {$wpdb->prefix}sn_hr_employee_assignment_history WHERE user_id=%d AND effective_from<=%s ORDER BY effective_from DESC, id DESC LIMIT 1", $employee_user_id, $effective_from), ARRAY_A);
		return ['employee_user_id'=>$employee_user_id,'effective_from'=>$effective_from,'eligible_parent_before'=>$before['previous_parent_user_id'] ?? null,'eligible_parent_after'=>$before['new_parent_user_id'] ?? null];
	}

	private function sn_valid_period(string $period): bool
	{
		return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
	}
	private function sn_hr_pick_model(array $profile, string $payment_method, string $applies_to): ?array
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_commission_models WHERE is_active=1 AND role_key=%s AND applies_to=%s ORDER BY id DESC", (string)$profile['role_key'], $applies_to), ARRAY_A);
		foreach ($rows as $m) {
			if ($m['employment_status'] !== 'all' && $m['employment_status'] !== $profile['employment_status']) continue;
			if ($m['payment_method'] !== 'all' && $m['payment_method'] !== $payment_method) continue;
			if (! empty($m['level_id']) && (int)$m['level_id'] !== (int)($profile['level_id'] ?? 0)) continue;
			return $m;
		}
		return null;
	}
	private function sn_hr_calculate_period(string $period, bool $dry_run = true): array
	{
		global $wpdb;
		$start = $period . '-01 00:00:00';
		$end = date('Y-m-t 23:59:59', strtotime($start));
		$employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_hr_employee_profiles WHERE is_active=1", ARRAY_A);
		$out = [];
		foreach ($employees as $e) {
			$invoices = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE (seller_id=%d OR supervisor_id=%d) AND paid_at BETWEEN %s AND %s AND (status IN ('paid','approved','completed') OR payment_status='approved' OR invoice_status='approved')", (int)$e['user_id'], (int)$e['user_id'], $start, $end), ARRAY_A);
			$commission = 0.0; $count = 0; $warnings=[];
			foreach ($invoices as $inv) {
				$method = in_array((string)$inv['pay_method'], ['online','gateway'], true) ? 'online' : 'card_to_card';
				$target = ((int)$inv['seller_id'] === (int)$e['user_id']) ? 'seller' : (((int)$inv['supervisor_id'] === (int)$e['user_id']) ? 'supervisor' : 'custom');
				$model = $this->sn_hr_pick_model($e, $method, $target);
				if (! $model) { $warnings[] = 'مدل پورسانت برای فاکتور #' . (int)$inv['id'] . ' یافت نشد'; continue; }
				$basis = (float) (($inv['final_total'] ?: $inv['product_price']) ?: 0);
				$amount = $model['commission_type'] === 'fixed' ? (float)$model['commission_value'] : ($basis * ((float)$model['commission_value'] / 100));
				$ctx = $this->sn_hr_commission_context_parent_at((string)($inv['paid_at'] ?: $inv['created_at']), (int)$e['user_id']);
				$commission += max(0, $amount);
				$count++;
				if (! $dry_run) {
					$snapshot = wp_json_encode(['model'=>$model,'profile'=>$e,'payment_method'=>$method,'invoice_id'=>(int)$inv['id'],'basis'=>$basis,'commission'=>$amount,'context'=>$ctx,'calculated_at'=>current_time('mysql')], JSON_UNESCAPED_UNICODE);
					$this->sn_add_wallet_transaction((int)$e['user_id'], $target === 'supervisor' ? 'supervisor' : 'seller', (float)$amount, 'credit', 'hr_commission', 'پورسانت HR برای فاکتور ' . (string)($inv['invoice_code'] ?? $inv['id']), (int)$inv['id'], (int)($inv['lead_id'] ?? 0), ['source_type'=>'commission','source_id'=>(int)$model['id'],'period_key'=>$period,'calculation_snapshot'=>$snapshot]);
				}
			}
			$base = (float)($e['base_salary'] ?? 0);
			$total = $base + $commission;
			$out[] = ['employee'=>$e,'base_salary'=>$base,'commission_count'=>$count,'commission_amount'=>$commission,'total_payable'=>$total,'warnings'=>$warnings];
			if (! $dry_run) {
				$exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_salary_ledger WHERE user_id=%d AND period_key=%s", (int)$e['user_id'], $period), ARRAY_A);
				if (! $exists) {
					$wpdb->insert($wpdb->prefix.'sn_hr_salary_ledger',['user_id'=>(int)$e['user_id'],'period_key'=>$period,'base_salary'=>$base,'commission_amount'=>$commission,'adjustments'=>0,'payable_amount'=>$total,'status'=>'draft','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
				}
				$ledger_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_salary_ledger WHERE user_id=%d AND period_key=%s", (int)$e['user_id'], $period));
				$this->sn_add_wallet_transaction((int)$e['user_id'], ((string)$e['role_key']==='sn_supervisor'?'supervisor':'seller'), $base, 'credit', 'hr_salary', 'حقوق پایه دوره '.$period, null, null, ['source_type'=>'salary','source_id'=>$ledger_id,'period_key'=>$period,'calculation_snapshot'=>wp_json_encode(['base_salary'=>$base,'period'=>$period,'employee'=>$e], JSON_UNESCAPED_UNICODE)]);
			}
		}
		return $out;
	}
	public function ajax_hr_payroll_preview(): void
	{
		if (! $this->sn_hr_guard()) return; $period=sanitize_text_field($_POST['period_key']??''); if(! $this->sn_valid_period($period)){SN_Helpers::send_json(false,'فرمت دوره نامعتبر است (YYYY-MM)');return;} $rows=$this->sn_hr_calculate_period($period,true); SN_Helpers::send_json(true,'پیش‌نمایش محاسبه شد',['items'=>$rows]);
	}
	public function ajax_hr_payroll_generate(): void
	{
		if (! $this->sn_hr_guard()) return; $period=sanitize_text_field($_POST['period_key']??''); $dry=!empty($_POST['dry_run']); if(! $this->sn_valid_period($period)){SN_Helpers::send_json(false,'فرمت دوره نامعتبر است');return;} $rows=$this->sn_hr_calculate_period($period,$dry); SN_Helpers::send_json(true,$dry?'پیش‌نمایش آماده شد':'درفت حقوق تولید شد',['items'=>$rows]);
	}
	public function ajax_hr_payroll_approve(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb; $period=sanitize_text_field($_POST['period_key']??''); if(! $this->sn_valid_period($period)){SN_Helpers::send_json(false,'فرمت دوره نامعتبر است');return;} $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_hr_salary_ledger SET status='approved', updated_at=%s WHERE period_key=%s AND status='draft'", current_time('mysql'), $period)); SN_Helpers::send_json(true,'حقوق دوره تایید شد');
	}
	public function ajax_hr_payroll_mark_paid(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb; $period=sanitize_text_field($_POST['period_key']??''); if(! $this->sn_valid_period($period)){SN_Helpers::send_json(false,'فرمت دوره نامعتبر است');return;} $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_hr_salary_ledger SET status='paid', updated_at=%s WHERE period_key=%s AND status='approved'", current_time('mysql'), $period)); SN_Helpers::send_json(true,'حقوق دوره پرداخت‌شده ثبت شد');
	}

	public function ajax_hr_backfill_employees(): void
	{
		if (! $this->sn_hr_guard()) return;
		if (class_exists('SN_HR')) { SN_HR::backfill_employee_profiles(); }
		SN_Helpers::send_json(true, 'پروفایل کارمندان با موفقیت بازسازی شد');
	}
	public function ajax_hr_seed_levels(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb;
		$defaults=['کارآموز','کارشناس','ارشد','سرپرست','مدیر']; $added=0;
		foreach($defaults as $i=>$t){
			$exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_levels WHERE title=%s LIMIT 1",$t));
			if($exists) continue;
			$wpdb->insert($wpdb->prefix.'sn_hr_levels',['level_key'=>sanitize_title($t), 'title'=>$t,'sort_order'=>$i,'is_system'=>1,'is_active'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
			$added++;
		}
		SN_Helpers::send_json(true, $added ? 'سطوح پیش‌فرض ایجاد شد' : 'سطوح پیش‌فرض از قبل موجود هستند', ['added'=>$added]);
	}
	public function ajax_hr_diagnostics(): void
	{
		if (! $this->sn_hr_guard()) return; global $wpdb; $u=wp_get_current_user();
		SN_Helpers::send_json(true,'',['data'=>['user_id'=>$u->ID,'roles'=>array_values((array)$u->roles),'can_hr'=>$this->sn_can_hr(),'employee_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_employee_profiles"),'level_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_levels"),'model_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_commission_models"),'nonce_present'=>!empty($_POST['nonce'])]]);
	}

	// =========================================================
	// ADMIN PAGES
	// =========================================================

	public function render_admin_page(): void
	{
		global $wpdb;
		$total_leads = $this->sn_count_metric('lead','lead_total');
		$unassigned = $this->sn_count_metric('lead','lead_active',["status='unassigned'"]);
		$total_invoices = $this->sn_count_metric('invoice','invoice_issued');
		$paid_invoices = $this->sn_count_metric('invoice','payment_financial_approved');
		$total_revenue = $this->sn_sum_metric_amount('payment_financial_approved');
		$total_sellers   = (int) (new \WP_User_Query(['role' => 'sn_seller', 'count_total' => true]))->get_total();
	?>
		<div class="wrap sn-admin" dir="rtl">
			<h1>داشبورد شبکه فروش</h1>
			<div class="sn-stats-grid">
				<div class="sn-stat"><span><?php echo number_format($total_leads); ?></span><label>کل شماره‌ها</label></div>
				<div class="sn-stat sn-stat-warn"><span><?php echo number_format($unassigned); ?></span><label>تخصیص‌نیافته</label></div>
				<div class="sn-stat"><span><?php echo number_format($total_sellers); ?></span><label>فروشندگان</label></div>
				<div class="sn-stat"><span><?php echo number_format($total_invoices); ?></span><label>فاکتورها</label></div>
				<div class="sn-stat sn-stat-success"><span><?php echo number_format($paid_invoices); ?></span><label>پرداخت شده</label></div>
				<div class="sn-stat sn-stat-primary"><span><?php echo SN_Helpers::format_price($total_revenue); ?></span><label>درآمد کل</label></div>
			</div>
			<?php if (current_user_can('manage_options')): $dbg = $this->sn_report_status_debug_counts(); ?>
			<div class="sn-card" style="margin-top:12px"><h3>بررسی وضعیت خام گزارش‌ها (فقط ادمین)</h3><pre><?php echo esc_html(wp_json_encode($dbg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></pre></div>
			<?php endif; ?>
		</div>
	<?php
	}

	public function render_admin_leads(): void
	{
		global $wpdb;
		$filters = $this->get_lead_filters();
		[$where, $args] = $this->build_leads_where($filters);
		$page    = max(1, absint($_GET['paged'] ?? 1));
		$per     = 50;
		$offset  = ($page - 1) * $per;
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE {$where}";
		$total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : $wpdb->get_var($count_sql));
		$pages = max(1, (int) ceil($total / $per));
		$list_sql = "SELECT * FROM {$wpdb->prefix}sn_leads WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_args = array_merge($args, [$per, $offset]);
		$leads = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_args), ARRAY_A);
		$all_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads");
		$raw = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE status='unassigned' AND seller_id IS NULL AND supervisor_id IS NULL");
		$pool = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE status='supervisor_pool'");
		$assigned = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE seller_id IS NOT NULL");
		$invoiced = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE status='invoiced'");
		$conv = $all_total ? round(($invoiced / $all_total) * 100, 1) : 0;
		$sellers = get_users(['role' => 'sn_seller', 'number' => 1000]);
		$supervisors = get_users(['role' => 'sn_supervisor', 'number' => 500]);
		$lead_statuses = $wpdb->get_results("SELECT label FROM {$wpdb->prefix}sn_lead_statuses WHERE is_active=1 ORDER BY sort_order ASC, id ASC", ARRAY_A);
		$export_url = wp_nonce_url(add_query_arg(array_merge($_GET, ['sn_export' => 'leads']), admin_url('admin.php')), 'sn_export_leads');
		$status_labels = ['unassigned' => 'لیست خام / بدون تخصیص', 'supervisor_pool' => 'داخل پنل سرپرست', 'assigned' => 'تخصیص‌یافته به فروشنده', 'invoiced' => 'پیش‌فاکتور صادرشده'];
	?>
		<div class="wrap sn-admin" dir="rtl">
			<h1>مدیریت شماره‌ها</h1>
			<div style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;margin:14px 0">
				<div class="sn-card"><strong><?php echo number_format_i18n($all_total); ?></strong><br><span>کل شماره‌ها</span></div>
				<div class="sn-card"><strong><?php echo number_format_i18n($raw); ?></strong><br><span>لیست خام</span></div>
				<div class="sn-card"><strong><?php echo number_format_i18n($pool); ?></strong><br><span>داخل پنل سرپرست</span></div>
				<div class="sn-card"><strong><?php echo number_format_i18n($assigned); ?></strong><br><span>تخصیص به فروشنده</span></div>
				<div class="sn-card"><strong><?php echo esc_html($conv); ?>٪</strong><br><span>نرخ تبدیل به فاکتور</span></div>
			</div>
			<div class="sn-card" style="margin-bottom:20px">
				<h3>آپلود فایل CSV</h3>
				<p>فایل CSV با ستون شماره موبایل آپلود کنید. اگر ستون <code>code</code> یا <code>import_code</code> داشته باشد، کد هر ردیف ذخیره می‌شود.</p>
				<input type="text" id="sn-import-code" class="regular-text" placeholder="کد کلی فایل، مثلاً ORD-1403-02" style="margin-left:8px">
				<input type="file" id="sn-import-file" accept=".csv">
				<button type="button" id="sn-do-import" class="button button-primary" style="margin-right:8px">آپلود و وارد کردن</button>
				<span id="sn-import-result"></span>
			</div>
			<form method="get" class="sn-card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:14px">
				<input type="hidden" name="page" value="sn-leads">
				<label>جستجو<br><input type="search" name="sn_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="شماره، شهر، یادداشت، کد"></label>
				<label>کد واردات<br><input type="text" name="sn_import_code" value="<?php echo esc_attr($filters['import_code']); ?>" placeholder="کد فایل/شماره"></label>
				<label>وضعیت سیستمی<br><select name="sn_status">
						<option value="all">همه</option><?php foreach ($status_labels as $k => $v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($filters['status'], $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
					</select></label>
				<label>وضعیت تماس<br><select name="sn_lead_status">
						<option value="">همه</option><?php foreach ($lead_statuses as $st): ?><option value="<?php echo esc_attr($st['label']); ?>" <?php selected($filters['lead_status'], $st['label']); ?>><?php echo esc_html($st['label']); ?></option><?php endforeach; ?>
					</select></label>
				<label>سرپرست<br><select name="sn_supervisor_id">
						<option value="0">همه</option><?php foreach ($supervisors as $sup): ?><option value="<?php echo esc_attr($sup->ID); ?>" <?php selected($filters['supervisor_id'], $sup->ID); ?>><?php echo esc_html($sup->display_name); ?></option><?php endforeach; ?>
					</select></label>
				<label>فروشنده<br><select name="sn_seller_id">
						<option value="0">همه</option><?php foreach ($sellers as $seller): ?><option value="<?php echo esc_attr($seller->ID); ?>" <?php selected($filters['seller_id'], $seller->ID); ?>><?php echo esc_html($seller->display_name); ?></option><?php endforeach; ?>
					</select></label>
				<label>از تاریخ تخصیص<br><input type="text" class="sn-jalali-date" name="sn_date_from" value="<?php echo esc_attr($filters['date_from']); ?>" placeholder="1403/02/18"></label>
				<label>تا تاریخ تخصیص<br><input type="text" class="sn-jalali-date" name="sn_date_to" value="<?php echo esc_attr($filters['date_to']); ?>" placeholder="1403/02/18"></label>
				<label>از ساعت<br><input type="time" name="sn_time_from" value="<?php echo esc_attr($filters['time_from']); ?>"></label>
				<label>تا ساعت<br><input type="time" name="sn_time_to" value="<?php echo esc_attr($filters['time_to']); ?>"></label>
				<button class="button button-primary">فیلتر</button>
				<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sn-leads')); ?>">پاک کردن</a>
				<a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">خروجی اکسل</a>
			</form>
			<p><strong><?php echo number_format_i18n($total); ?></strong> شماره مطابق فیلتر فعلی پیدا شد.</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>#</th>
						<th>شماره</th>
						<th>کد</th>
						<th>استان/شهر</th>
						<th>وضعیت سیستمی</th>
						<th>وضعیت تماس</th>
						<th>سرپرست</th>
						<th>فروشنده</th>
						<th>تاریخ ورود</th>
						<th>تاریخ تخصیص</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($leads as $l) : $seller = $l['seller_id'] ? get_user_by('id', $l['seller_id']) : null;
						$supervisor = ! empty($l['supervisor_id']) ? get_user_by('id', $l['supervisor_id']) : null; ?>
						<tr>
							<td><?php echo (int) $l['id']; ?></td>
							<td><?php echo esc_html($l['phone']); ?></td>
							<td><?php echo esc_html($l['import_code'] ?: '—'); ?></td>
							<td><?php echo esc_html(trim(($l['province'] ?: '') . ' / ' . ($l['city'] ?: ''), ' /') ?: '—'); ?></td>
							<td><span class="sn-status sn-status-<?php echo esc_attr($l['status']); ?>"><?php echo esc_html($status_labels[$l['status']] ?? $l['status']); ?></span></td>
							<td><?php echo esc_html($l['lead_status'] ?: '—'); ?></td>
							<td><?php echo $supervisor ? esc_html($supervisor->display_name) : '—'; ?></td>
							<td><?php echo $seller ? esc_html($seller->display_name) : '—'; ?></td>
							<td><?php echo esc_html(SN_Helpers::gregorian_to_jalali_date($l['imported_at'])); ?></td>
							<td><?php echo esc_html(SN_Helpers::gregorian_to_jalali_date($l['assigned_at'] ?: '')); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ($pages > 1) : ?><div class="tablenav bottom">
					<div class="tablenav-pages"><?php echo paginate_links(['total' => $pages, 'current' => $page, 'add_args' => array_filter($_GET)]); ?></div>
				</div><?php endif; ?>
		</div>
	<?php
	}

	public function render_admin_sellers(): void
	{
		$sellers = get_users(['role' => 'sn_seller', 'number' => 1000]);
		$supervisors = get_users(['role' => 'sn_supervisor', 'number' => 500]);
		global $wpdb;
		$export_url = wp_nonce_url(add_query_arg(['page' => 'sn-sellers', 'sn_export' => 'sellers'], admin_url('admin.php')), 'sn_export_sellers');
		$total_sellers = count($sellers);
		$with_sup = 0;
		foreach ($sellers as $sx) {
			if ((int) get_user_meta($sx->ID, 'sn_supervisor_id', true)) {
				$with_sup++;
			}
		}
	?>
		<div class="wrap sn-admin" dir="rtl">
			<h1>فروشندگان</h1>
			<div style="display:grid;grid-template-columns:repeat(3,minmax(150px,1fr));gap:10px;margin:14px 0">
				<div class="sn-card"><strong><?php echo number_format_i18n($total_sellers); ?></strong><br><span>کل فروشنده‌ها</span></div>
				<div class="sn-card"><strong><?php echo number_format_i18n($with_sup); ?></strong><br><span>دارای سرپرست</span></div>
				<div class="sn-card"><strong><?php echo number_format_i18n(max(0, $total_sellers - $with_sup)); ?></strong><br><span>بدون سرپرست</span></div>
			</div>
			<p>در این بخش مشخص می‌کنید هر فروشنده زیرمجموعه کدام سرپرست باشد. برای تغییر گروهی، چند فروشنده را انتخاب کنید.</p>
			<div class="sn-card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
				<select id="sn-bulk-seller-action">
					<option value="">عملیات گروهی</option>
					<option value="assign_supervisor">تخصیص به سرپرست</option>
					<option value="remove_supervisor">حذف از سرپرست</option>
				</select>
				<select id="sn-bulk-seller-supervisor">
					<option value="0">انتخاب سرپرست</option><?php foreach ($supervisors as $sup): ?><option value="<?php echo esc_attr($sup->ID); ?>"><?php echo esc_html($sup->display_name . ' — ' . $sup->user_login); ?></option><?php endforeach; ?>
				</select>
				<button type="button" class="button button-primary" id="sn-run-bulk-seller">اجرای عملیات</button>
				<a class="button" href="<?php echo esc_url($export_url); ?>">خروجی اکسل</a>
				<span id="sn-bulk-seller-result"></span>
			</div>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:35px"><input type="checkbox" id="sn-select-all-sellers"></th>
						<th>نام</th>
						<th>شماره</th>
						<th>سرپرست</th>
						<th>شماره‌ها</th>
						<th>فاکتورها</th>
						<th>پرداخت‌شده</th>
						<th>فروش تاییدشده</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($sellers as $s) :
						$lc = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE seller_id=%d", $s->ID));
						$ic = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d", $s->ID));
						$pc = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d AND status='paid'", $s->ID));
						$rev = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(COALESCE(final_total, product_price, 0)),0) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d AND status='paid'", $s->ID));
						$current_sup = (int) get_user_meta($s->ID, 'sn_supervisor_id', true);
					?>
						<tr>
							<td><input type="checkbox" class="sn-seller-checkbox" value="<?php echo esc_attr($s->ID); ?>"></td>
							<td><?php echo esc_html($s->display_name); ?></td>
							<td><?php echo esc_html($s->user_login); ?></td>
							<td><select class="sn-seller-supervisor-select" data-seller-id="<?php echo esc_attr($s->ID); ?>">
									<option value="">— بدون سرپرست —</option><?php foreach ($supervisors as $sup) : ?><option value="<?php echo esc_attr($sup->ID); ?>" <?php selected($current_sup, $sup->ID); ?>><?php echo esc_html($sup->display_name . ' — ' . $sup->user_login); ?></option><?php endforeach; ?>
								</select></td>
							<td><?php echo number_format_i18n($lc); ?></td>
							<td><?php echo number_format_i18n($ic); ?></td>
							<td><?php echo number_format_i18n($pc); ?></td>
							<td><?php echo SN_Helpers::format_price($rev); ?></td>
							<td><a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $s->ID)); ?>">ویرایش</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php
	}

	public function render_admin_supervisors(): void
	{
		global $wpdb;
		$supervisors = get_users(['role' => 'sn_supervisor', 'number' => 200]);
		$raw_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE status='unassigned' AND seller_id IS NULL AND supervisor_id IS NULL");
	?>
		<div class="wrap sn-admin" dir="rtl">
			<h1>سرپرست‌ها</h1>
			<p><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'sn-supervisors', 'sn_export' => 'supervisors'], admin_url('admin.php')), 'sn_export_supervisors')); ?>">خروجی اکسل</a></p>
			<div class="sn-card" style="margin-bottom:16px">
				<h3>انتقال شماره خام به پنل سرپرست</h3>
				<p>شماره‌های خام فعلی: <strong><?php echo number_format_i18n($raw_count); ?></strong></p>
				<select id="sn-supervisor-select">
					<option value="">انتخاب سرپرست</option>
					<?php foreach ($supervisors as $sup) : ?>
						<option value="<?php echo esc_attr($sup->ID); ?>"><?php echo esc_html($sup->display_name . ' — ' . $sup->user_login); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" id="sn-supervisor-lead-count" min="1" placeholder="تعداد شماره">
				<input type="text" id="sn-supervisor-import-code" placeholder="کد واردات، اختیاری">
				<button class="button button-primary" id="sn-assign-supervisor-leads">انتقال به پنل سرپرست</button>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>نام سرپرست</th>
						<th>شماره/نام کاربری</th>
						<th>فروشنده‌ها</th>
						<th>شماره‌های داخل پنل سرپرست</th>
						<th>شماره‌های تخصیص‌داده‌شده</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($supervisors as $sup) :
						$seller_count = count(get_users(['role' => 'sn_seller', 'meta_key' => 'sn_supervisor_id', 'meta_value' => $sup->ID, 'fields' => 'ids']));
						$pool_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NULL AND status='supervisor_pool'", $sup->ID));
						$assigned_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NOT NULL", $sup->ID));
					?>
						<tr>
							<td><?php echo esc_html($sup->display_name); ?></td>
							<td><?php echo esc_html($sup->user_login); ?></td>
							<td><?php echo number_format_i18n($seller_count); ?></td>
							<td><?php echo number_format_i18n($pool_count); ?></td>
							<td><?php echo number_format_i18n($assigned_count); ?></td>
							<td><a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $sup->ID)); ?>">ویرایش کاربر</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:14px">برای ساخت سرپرست جدید، از بخش کاربران وردپرس یک کاربر بسازید و نقش او را «سرپرست فروش» قرار دهید.</p>
		</div>
	<?php
	}

	public function render_admin_invoices(): void
	{
		global $wpdb;
		$page = max(1, absint($_GET['paged'] ?? 1));
		$filters = $this->get_invoice_filters();
		[$where, $args] = $this->build_invoices_where($filters);
		$per = 50;
		$offset = ($page - 1) * $per;
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE {$where}";
		$total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : $wpdb->get_var($count_sql));
		$pages = max(1, (int) ceil($total / $per));
		$list_sql = "SELECT * FROM {$wpdb->prefix}sn_invoices WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$invs = $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($args, [$per, $offset])), ARRAY_A);
		$total_amount_sql = "SELECT COALESCE(SUM(COALESCE(final_total, product_price, 0)),0) FROM {$wpdb->prefix}sn_invoices WHERE {$where}";
		$filtered_amount = (float) ($args ? $wpdb->get_var($wpdb->prepare($total_amount_sql, ...$args)) : $wpdb->get_var($total_amount_sql));
		$paid_amount = (float) $wpdb->get_var("SELECT COALESCE(SUM(COALESCE(final_total, product_price, 0)),0) FROM {$wpdb->prefix}sn_invoices WHERE status='paid'");
		$pending_review = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE status IN ('receipt_uploaded','pending_financial_approval') OR payment_status IN ('receipt_uploaded','pending_financial_approval') OR invoice_status IN ('receipt_uploaded','pending_financial_approval')");
		$sellers = get_users(['role' => 'sn_seller', 'number' => 1000]);
		$supervisors = get_users(['role' => 'sn_supervisor', 'number' => 500]);
		$export_url = wp_nonce_url(add_query_arg(array_merge($_GET, ['sn_export' => 'invoices']), admin_url('admin.php')), 'sn_export_invoices');
		$status_map = ['pre_invoice' => ['📋 پیش‌فاکتور', '#f59e0b'], 'pending' => ['📋 پیش‌فاکتور', '#f59e0b'], 'receipt_uploaded' => ['📎 نیاز به بررسی فیش', '#3b82f6'], 'pending_financial_approval' => ['📎 نیاز به بررسی فیش', '#3b82f6'], 'paid' => ['✅ فاکتور تایید‌شده', '#16a34a'], 'cancelled' => ['❌ لغوشده', '#ef4444']];
		$filter_labels = ['all' => 'همه', 'pre_invoice' => 'پیش‌فاکتور', 'needs_review' => '⚠️ نیاز به بررسی فیش', 'paid' => 'فاکتور تایید‌شده', 'cancelled' => 'لغوشده'];
	?>
		<div class="wrap sn-admin" dir="rtl">
			<h1>پیش‌فاکتورها و فاکتورها</h1>
			<div style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px;margin:14px 0">
				<div class="sn-card"><strong><?php echo number_format_i18n($total); ?></strong><br><span>تعداد مطابق فیلتر</span></div>
				<div class="sn-card"><strong><?php echo SN_Helpers::format_price($filtered_amount); ?></strong><br><span>مبلغ مطابق فیلتر</span></div>
				<div class="sn-card"><strong><?php echo SN_Helpers::format_price($paid_amount); ?></strong><br><span>کل فروش تاییدشده</span></div>
				<div class="sn-card"><strong><?php echo number_format_i18n($pending_review); ?></strong><br><span>فیش نیازمند بررسی</span></div>
			</div>
			<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center"><?php foreach ($filter_labels as $key => $lbl) : $url = add_query_arg(array_merge($_GET, ['page' => 'sn-invoices', 'sn_status' => $key, 'paged' => false]));
																										$active = $filters['status'] === $key;
																										$badge = ($key === 'needs_review' && $pending_review > 0) ? " <span style='background:#ef4444;color:#fff;padding:1px 7px;border-radius:10px;font-size:11px'>$pending_review</span>" : ''; ?><a href="<?php echo esc_url($url); ?>" style="padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;<?php echo $active ? 'background:#1d4ed8;color:#fff' : 'background:#f1f5f9;color:#1e293b'; ?>"><?php echo esc_html($lbl); ?><?php echo $badge; ?></a><?php endforeach; ?></div>
			<form method="get" class="sn-card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:14px"><input type="hidden" name="page" value="sn-invoices"><input type="hidden" name="sn_status" value="<?php echo esc_attr($filters['status']); ?>"><label>جستجو<br><input type="search" name="sn_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="کد، مشتری، موبایل"></label><label>سرپرست<br><select name="sn_supervisor_id">
						<option value="0">همه</option><?php foreach ($supervisors as $sup): ?><option value="<?php echo esc_attr($sup->ID); ?>" <?php selected($filters['supervisor_id'], $sup->ID); ?>><?php echo esc_html($sup->display_name); ?></option><?php endforeach; ?>
					</select></label><label>فروشنده<br><select name="sn_seller_id">
						<option value="0">همه</option><?php foreach ($sellers as $seller): ?><option value="<?php echo esc_attr($seller->ID); ?>" <?php selected($filters['seller_id'], $seller->ID); ?>><?php echo esc_html($seller->display_name); ?></option><?php endforeach; ?>
					</select></label><label>از تاریخ<br><input type="text" class="sn-jalali-date" name="sn_date_from" value="<?php echo esc_attr($filters['date_from']); ?>" placeholder="1403/02/18"></label><label>تا تاریخ<br><input type="text" class="sn-jalali-date" name="sn_date_to" value="<?php echo esc_attr($filters['date_to']); ?>" placeholder="1403/02/18"></label><label>از ساعت<br><input type="time" name="sn_time_from" value="<?php echo esc_attr($filters['time_from']); ?>"></label><label>تا ساعت<br><input type="time" name="sn_time_to" value="<?php echo esc_attr($filters['time_to']); ?>"></label><button class="button button-primary">فیلتر</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sn-invoices')); ?>">پاک کردن</a><a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">خروجی اکسل</a></form>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:120px">کد</th>
						<th>مشتری</th>
						<th>موبایل</th>
						<th>محصول</th>
						<th>مبلغ</th>
						<th>فروشنده</th>
						<th>وضعیت</th>
						<th>روش پرداخت</th>
						<th>تاریخ</th>
						<th style="width:200px">عملیات</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($invs as $inv) : $seller = get_user_by('id', $inv['seller_id']);
						[$slabel, $scolor] = $status_map[$inv['status']] ?? [$inv['status'], '#999'];
						$is_receipt = in_array($inv['status'], ['receipt_uploaded', 'pending_financial_approval'], true); ?>
						<tr style="<?php echo esc_attr($is_receipt ? 'background:#eff6ff' : ''); ?>">
							<td><code style="font-size:11px"><?php echo esc_html($inv['invoice_code']); ?></code></td>
							<td><?php echo esc_html($inv['customer_name']); ?></td>
							<td><?php echo esc_html($inv['customer_phone']); ?></td>
							<td><?php echo esc_html(get_the_title($inv['product_id'])); ?></td>
							<td><strong><?php echo SN_Helpers::format_price((float) $inv['product_price']); ?></strong></td>
							<td><?php echo $seller ? esc_html($seller->display_name) : '—'; ?></td>
							<td><span style="background:<?php echo esc_attr($scolor); ?>;color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;white-space:nowrap"><?php echo esc_html($slabel); ?></span></td>
							<td><?php echo $inv['pay_method'] === 'online' ? '💳 آنلاین' : ($inv['pay_method'] === 'card' ? '🏧 کارت به کارت' : '—'); ?></td>
							<td style="font-size:12px"><?php echo esc_html(substr($inv['created_at'], 0, 16)); ?></td>
							<td><?php if ($inv['receipt_url']) : ?><a href="<?php echo esc_url($inv['receipt_url']); ?>" target="_blank" class="button button-small">📎 مشاهده فیش</a><?php endif; ?><?php if ($is_receipt) : ?><button class="button button-primary sn-confirm-payment" data-id="<?php echo esc_attr($inv['id']); ?>" style="display:block;width:100%;margin:4px 0">✅ تایید مالی</button><button class="button sn-cancel-payment" data-id="<?php echo esc_attr($inv['id']); ?>" style="display:block;width:100%;margin-bottom:6px">❌ رد فیش</button><?php endif; ?><select class="sn-admin-status-sel" data-id="<?php echo esc_attr($inv['id']); ?>" style="width:100%;margin-top:4px;font-size:11px">
									<option value="">✏ تغییر وضعیت...</option>
									<option value="pre_invoice">📋 پیش‌فاکتور</option>
									<option value="paid">✅ تبدیل به فاکتور</option>
									<option value="cancelled">❌ لغو کردن</option>
								</select></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ($pages > 1) : ?><div class="tablenav bottom" style="margin-top:12px">
					<div class="tablenav-pages"><?php echo paginate_links(['total' => $pages, 'current' => $page, 'add_args' => array_filter($_GET)]); ?></div>
				</div><?php endif; ?>
		</div><?php
			}

			public function render_admin_settings(): void
			{
				$api_key_kavenegar = get_option('sn_sms_api_key_kavenegar', '');
				$sender_kavenegar  = get_option('sn_sms_sender_kavenegar', '');
				$api_key_faraz     = get_option('sn_sms_api_key_faraz', '');
				$sender_faraz      = get_option('sn_sms_sender_faraz', '');
				$current_provider = get_option('sn_sms_provider', 'faraz');
				$legacy_api_key   = get_option('sn_sms_api_key', '');
				$legacy_sender    = get_option('sn_sms_sender', '');
				if ($current_provider === 'faraz' && empty($api_key_faraz)) { $api_key_faraz = $legacy_api_key; $sender_faraz = $legacy_sender; }
				if ($current_provider === 'kavenegar' && empty($api_key_kavenegar)) { $api_key_kavenegar = $legacy_api_key; $sender_kavenegar = $legacy_sender; }
				$s = [
					'sn_zarinpal_merchant' => get_option('sn_zarinpal_merchant', ''),
					'sn_zarinpal_sandbox' => get_option('sn_zarinpal_sandbox', '0'),
					'sn_sms_provider' => $current_provider,
					'sn_sms_api_key' => $legacy_api_key,
					'sn_sms_sender' => $legacy_sender,
					'sn_sms_api_key_kavenegar' => $api_key_kavenegar,
					'sn_sms_sender_kavenegar' => $sender_kavenegar,
					'sn_sms_api_key_faraz' => $api_key_faraz,
					'sn_sms_sender_faraz' => $sender_faraz,
					'sn_faraz_pattern_invoice' => get_option('sn_faraz_pattern_invoice', ''),
					'sn_faraz_pattern_online_payment' => get_option('sn_faraz_pattern_online_payment', ''),
					'sn_faraz_pattern_card_payment' => get_option('sn_faraz_pattern_card_payment', ''),
					'sn_meli_username' => get_option('sn_meli_username', ''),
					'sn_meli_password' => get_option('sn_meli_password', ''),
					'sn_meli_body_id_invoice' => get_option('sn_meli_body_id_invoice', ''),
					'sn_sms_invoice_template' => get_option('sn_sms_invoice_template', ''),
					'sn_sms_invoice_bodyid' => get_option('sn_sms_invoice_bodyid', ''),
					'sn_card_number' => get_option('sn_card_number', ''),
					'sn_card_owner' => get_option('sn_card_owner', ''),
					'sn_invoice_page_id' => get_option('sn_invoice_page_id', ''),
					'sn_seller_commission_type' => get_option('sn_seller_commission_type', 'percent'),
					'sn_seller_commission_value' => get_option('sn_seller_commission_value', '0'),
					'sn_supervisor_commission_type' => get_option('sn_supervisor_commission_type', 'percent'),
					'sn_supervisor_commission_value' => get_option('sn_supervisor_commission_value', '0'),
					'sn_wallet_auto_credit' => get_option('sn_wallet_auto_credit', '1'),
					'sn_wheel_company_name' => get_option('sn_wheel_company_name', ''),
					'sn_wheel_free_product_id' => get_option('sn_wheel_free_product_id', ''),
					'sn_coupon_allow_on_sale' => get_option('sn_coupon_allow_on_sale', '0'),
					'sn_lucky_wheels' => get_option('sn_lucky_wheels', []),
					'sn_lottery_text_template' => get_option('sn_lottery_text_template', 'با پرداخت این فاکتور {count} شانس برای شرکت در قرعه‌کشی {company} دریافت می‌کنید.'),
					'sn_recontact_popup_text' => get_option('sn_recontact_popup_text', 'اگر پیش از پرداخت فاکتور از کارشناس خود سوالی دارید، دکمه ارتباط مجدد با کارشناس را بزنید.'),
					'sn_invoice_info_show_short_desc' => get_option('sn_invoice_info_show_short_desc', '1'),
					'sn_invoice_info_show_price' => get_option('sn_invoice_info_show_price', '1'),
					'sn_invoice_info_show_lottery' => get_option('sn_invoice_info_show_lottery', '1'),
					'sn_invoice_info_show_coupon' => get_option('sn_invoice_info_show_coupon', '1'),
					'sn_invoice_info_show_image' => get_option('sn_invoice_info_show_image', '1'),
					'sn_invoice_info_show_gallery' => get_option('sn_invoice_info_show_gallery', '1'),
					'sn_invoice_btn_show_product_info' => get_option('sn_invoice_btn_show_product_info', '1'),
					'sn_invoice_btn_show_lottery' => get_option('sn_invoice_btn_show_lottery', '1'),
					'sn_invoice_btn_show_wheel' => get_option('sn_invoice_btn_show_wheel', '1'),
					'sn_invoice_btn_show_coupon' => get_option('sn_invoice_btn_show_coupon', '1'),
					'sn_invoice_btn_show_recontact' => get_option('sn_invoice_btn_show_recontact', '1'),
					'sn_invoice_btn_show_online_payment' => get_option('sn_invoice_btn_show_online_payment', '1'),
					'sn_invoice_btn_show_card_payment' => get_option('sn_invoice_btn_show_card_payment', '1'),
					'sn_invoice_btn_show_product_info' => get_option('sn_invoice_btn_show_product_info', '1'),
					'sn_invoice_btn_show_lottery' => get_option('sn_invoice_btn_show_lottery', '1'),
					'sn_invoice_btn_show_wheel' => get_option('sn_invoice_btn_show_wheel', '1'),
					'sn_invoice_btn_show_coupon' => get_option('sn_invoice_btn_show_coupon', '1'),
					'sn_invoice_btn_show_recontact' => get_option('sn_invoice_btn_show_recontact', '1'),
					'sn_invoice_btn_show_online_payment' => get_option('sn_invoice_btn_show_online_payment', '1'),
					'sn_invoice_btn_show_card_payment' => get_option('sn_invoice_btn_show_card_payment', '1'),
				];
				$page_defs = class_exists('SN_Activator') && method_exists('SN_Activator', 'required_pages') ? SN_Activator::required_pages() : [];
				$duplicate_report = get_option('sn_page_duplicate_report', []);
				$products = [];
				if (function_exists('wc_get_product')) {
					foreach (SN_Helpers::get_sn_products() as $sn_product_row) {
						$product_obj = wc_get_product((int) ($sn_product_row['id'] ?? 0));
						if ($product_obj) { $products[] = $product_obj; }
					}
				}
				?>
		<div class="wrap sn-admin sn-settings-tabs-wrap" dir="rtl">
			<h1>تنظیمات شبکه فروش</h1>
			<div id="sn-settings-notice"></div>
			<form id="sn-settings-form">
				<?php wp_nonce_field('sn_admin', 'sn_settings_nonce'); ?>
				<div class="sn-admin-tabs">
					<button type="button" class="button sn-admin-tab active" data-tab="general">عمومی</button>
					<button type="button" class="button sn-admin-tab" data-tab="sms">پیامک</button>
					<button type="button" class="button sn-admin-tab" data-tab="financial">مالی / درگاه</button>
					<button type="button" class="button sn-admin-tab" data-tab="wheel">گردونه شانس</button>
					<button type="button" class="button sn-admin-tab" data-tab="wallet">کیف پول</button>
					<button type="button" class="button sn-admin-tab" data-tab="invoice">فاکتور / کارت‌به‌کارت</button>
					<button type="button" class="button sn-admin-tab" data-tab="pages">صفحات</button>
				</div>

				<div class="sn-admin-tab-panel active" id="sn-settings-general">
					<table class="form-table"><tr><th>توضیح</th><td>تنظیمات کلی شبکه فروش. تنظیمات هر بخش از تب‌های بالا قابل مدیریت است.</td></tr></table>
				</div>

				<div class="sn-admin-tab-panel" id="sn-settings-financial">
					<table class="form-table">
						<tr><th>Merchant ID زرین‌پال</th><td><input type="text" name="sn_zarinpal_merchant" class="regular-text" value="<?php echo esc_attr($s['sn_zarinpal_merchant']); ?>"></td></tr>
						<tr><th>حالت تست زرین‌پال</th><td><label><input type="checkbox" name="sn_zarinpal_sandbox" value="1" <?php checked((string) $s['sn_zarinpal_sandbox'], '1'); ?>> فعال</label></td></tr>
					</table>
				</div>

				<div class="sn-admin-tab-panel" id="sn-settings-sms">
					<table class="form-table">
						<tr><th>سرویس‌دهنده</th><td><select name="sn_sms_provider" id="sn_sms_provider"><option value="melipayamak" <?php selected($s['sn_sms_provider'], 'melipayamak'); ?>>ملی پیامک</option><option value="kavenegar" <?php selected($s['sn_sms_provider'], 'kavenegar'); ?>>کاوه‌نگار</option><option value="faraz" <?php selected($s['sn_sms_provider'], 'faraz'); ?>>فراز اس‌ام‌اس</option></select></td></tr>
						<tr class="sn-provider-row sn-provider-kavenegar"><th>API Key کاوه‌نگار</th><td><input type="text" name="sn_sms_api_key_kavenegar" class="regular-text" value="<?php echo esc_attr($s['sn_sms_api_key_kavenegar']); ?>" style="direction:ltr;text-align:left"></td></tr>
						<tr class="sn-provider-row sn-provider-kavenegar"><th>شماره فرستنده کاوه‌نگار</th><td><input type="text" name="sn_sms_sender_kavenegar" class="regular-text" value="<?php echo esc_attr($s['sn_sms_sender_kavenegar']); ?>"></td></tr>
						<tr class="sn-provider-row sn-provider-faraz"><th>API Key فراز</th><td><input type="text" name="sn_sms_api_key_faraz" class="regular-text" value="<?php echo esc_attr($s['sn_sms_api_key_faraz']); ?>" style="direction:ltr;text-align:left"></td></tr>
						<tr class="sn-provider-row sn-provider-faraz"><th>شماره فرستنده فراز</th><td><input type="text" name="sn_sms_sender_faraz" class="regular-text" value="<?php echo esc_attr($s['sn_sms_sender_faraz']); ?>"></td></tr>
						<tr class="sn-provider-row sn-provider-faraz"><th>کد پترن صدور فاکتور</th><td><input type="text" name="sn_faraz_pattern_invoice" class="regular-text" value="<?php echo esc_attr($s['sn_faraz_pattern_invoice']); ?>"><p class="description">متغیرها: customer_name, invoice_code, invoice_url, amount, card_number</p></td></tr>
						<tr class="sn-provider-row sn-provider-faraz"><th>کد پترن پرداخت آنلاین</th><td><input type="text" name="sn_faraz_pattern_online_payment" class="regular-text" value="<?php echo esc_attr($s['sn_faraz_pattern_online_payment']); ?>"><p class="description">متغیر جدید: payment_type = online</p></td></tr>
						<tr class="sn-provider-row sn-provider-faraz"><th>کد پترن کارت‌به‌کارت</th><td><input type="text" name="sn_faraz_pattern_card_payment" class="regular-text" value="<?php echo esc_attr($s['sn_faraz_pattern_card_payment']); ?>"><p class="description">متغیر جدید: payment_type = card_to_card</p></td></tr>
						<tr class="sn-provider-row sn-provider-melipayamak"><th>نام کاربری ملی پیامک</th><td><input type="text" name="sn_meli_username" class="regular-text" value="<?php echo esc_attr($s['sn_meli_username']); ?>"></td></tr>
						<tr class="sn-provider-row sn-provider-melipayamak"><th>API Key / رمز ملی پیامک</th><td><input type="text" name="sn_meli_password" class="regular-text" value="<?php echo esc_attr($s['sn_meli_password']); ?>" style="direction:ltr;text-align:left"></td></tr>
						<tr class="sn-provider-row sn-provider-melipayamak"><th>شماره فرستنده</th><td><input type="text" name="sn_sms_sender" class="regular-text" value="<?php echo esc_attr($s['sn_sms_sender']); ?>"></td></tr>
						<tr><th>متن پیامک فاکتور</th><td><textarea name="sn_sms_invoice_template" rows="5" class="large-text"><?php echo esc_textarea($s['sn_sms_invoice_template']); ?></textarea><p class="description">متغیرها: {customer_name}, {invoice_code}, {invoice_url}, {amount}, {card_number}, {payment_type}</p></td></tr>
						<tr><th>کد الگوی ملی پیامک</th><td><input type="number" name="sn_sms_invoice_bodyid" class="small-text" value="<?php echo esc_attr($s['sn_sms_invoice_bodyid']); ?>"></td></tr>
					</table>
					<h3>تست ارسال پیامک</h3><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><input type="tel" id="sn-test-sms-phone" placeholder="09xxxxxxxxx" class="regular-text" style="direction:ltr"><button id="sn-test-sms-btn" type="button" class="button button-secondary">ارسال پیامک تست</button><span id="sn-test-sms-result" style="font-weight:600"></span></div>
				</div>

				<div class="sn-admin-tab-panel" id="sn-settings-wheel">
					<table class="form-table">
						<tr><th>نام شرکت/کمپین قرعه‌کشی</th><td><input type="text" name="sn_wheel_company_name" class="regular-text" value="<?php echo esc_attr($s['sn_wheel_company_name']); ?>" placeholder="مثلاً ماکسیمم"></td></tr>
						<tr><th>محصول رایگان پیش‌فرض</th><td><select name="sn_wheel_free_product_id"><option value="">انتخاب نشده</option><?php foreach ($products as $prod): ?><option value="<?php echo esc_attr($prod->get_id()); ?>" <?php selected($s['sn_wheel_free_product_id'], (string)$prod->get_id()); ?>><?php echo esc_html($prod->get_name()); ?></option><?php endforeach; ?></select><p class="description">اگر گردونه محصول رایگان بدهد، این محصول با مبلغ صفر به آیتم‌های فاکتور اضافه می‌شود.</p></td></tr>
						<tr><th>اعمال کوپن روی محصول تخفیف‌دار</th><td><label><input type="checkbox" name="sn_coupon_allow_on_sale" value="1" <?php checked($s['sn_coupon_allow_on_sale'], '1'); ?>> اجازه بده کوپن روی محصولاتی که قیمت فروش ویژه دارند هم اعمال شود</label></td></tr>
                    <tr><th>متن توضیح شانس قرعه‌کشی</th><td><textarea name="sn_lottery_text_template" rows="3" class="large-text"><?php echo esc_textarea($s['sn_lottery_text_template']); ?></textarea><p class="description">متغیرها: {count} تعداد شانس، {company} نام کمپین</p></td></tr>
                        <tr><th>متن پاپ‌آپ ارتباط مجدد</th><td><textarea name="sn_recontact_popup_text" rows="3" class="large-text"><?php echo esc_textarea($s['sn_recontact_popup_text']); ?></textarea></td></tr>
					</table>
					<h3>تعریف چند گردونه و گزینه‌های شانس</h3>
					<p class="description">هر گردونه می‌تواند چند گزینه داشته باشد. درصد شانس‌ها وزن نسبی هستند؛ لازم نیست دقیقاً ۱۰۰ شوند، اما بهتر است مجموع هر گردونه حدود ۱۰۰ باشد.</p>
					<table class="widefat striped sn-lucky-wheels-table">
						<thead><tr><th>گردونه</th><th>گزینه‌ها و درصد شانس</th></tr></thead><tbody>
						<?php
						$wheels_for_ui = is_array($s['sn_lucky_wheels']) ? $s['sn_lucky_wheels'] : [];
						for ($wi=0; $wi<3; $wi++):
							$wheel_keys = array_keys($wheels_for_ui);
							$wid = $wheel_keys[$wi] ?? ('new_' . $wi);
							$wheel = $wheels_for_ui[$wid] ?? ['id'=>$wid,'title'=>'','description'=>'','segments'=>[]];
						?>
						<tr>
							<td style="min-width:230px">
								<input type="hidden" name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][id]" value="<?php echo esc_attr($wid); ?>">
								<label>نام گردونه<br><input type="text" name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][title]" value="<?php echo esc_attr($wheel['title'] ?? ''); ?>" class="regular-text" placeholder="مثلاً گردونه VIP"></label><br><br>
								<label>توضیح<br><textarea name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][description]" rows="3" style="width:100%"><?php echo esc_textarea($wheel['description'] ?? ''); ?></textarea></label>
							</td>
							<td>
								<table class="widefat"><thead><tr><th>عنوان گزینه</th><th>نوع</th><th>درصد شانس</th><th>مقدار/درصد تخفیف</th><th>محصول رایگان</th></tr></thead><tbody>
								<?php for ($si=0; $si<5; $si++): $seg = $wheel['segments'][$si] ?? []; ?>
								<tr>
									<td><input type="text" name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][segments][<?php echo $si; ?>][label]" value="<?php echo esc_attr($seg['label'] ?? ''); ?>" placeholder="مثلاً کد تخفیف ۱۰٪"></td>
									<td><select name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][segments][<?php echo $si; ?>][type]"><option value="discount_coupon" <?php selected($seg['type'] ?? '', 'discount_coupon'); ?>>کد تخفیف</option><option value="free_product" <?php selected($seg['type'] ?? '', 'free_product'); ?>>محصول رایگان</option><option value="empty_reward" <?php selected($seg['type'] ?? '', 'empty_reward'); ?>>پوچ / بدون جایزه</option><option value="text" <?php selected($seg['type'] ?? '', 'text'); ?>>متن/امتیاز</option></select></td>
									<td><input type="number" min="0" max="100" step="0.01" class="small-text" name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][segments][<?php echo $si; ?>][chance]" value="<?php echo esc_attr($seg['chance'] ?? ''); ?>"></td>
									<td><input type="text" class="small-text" name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][segments][<?php echo $si; ?>][value]" value="<?php echo esc_attr($seg['value'] ?? ''); ?>" placeholder="مثلاً 10"></td>
									<td><select name="sn_lucky_wheels[<?php echo esc_attr($wid); ?>][segments][<?php echo $si; ?>][product_id]"><option value="">—</option><?php foreach ($products as $prod): ?><option value="<?php echo esc_attr($prod->get_id()); ?>" <?php selected((string)($seg['product_id'] ?? ''), (string)$prod->get_id()); ?>><?php echo esc_html($prod->get_name()); ?></option><?php endforeach; ?></select></td>
								</tr>
								<?php endfor; ?>
								</tbody></table>
							</td>
						</tr>
						<?php endfor; ?>
						</tbody>
					</table>

					<h3>تنظیم گردونه به تفکیک محصول</h3>
					<p class="description">این جدول همان تنظیمات باکس «شبکه فروش» داخل ویرایش محصول را به صورت یکجا مدیریت می‌کند؛ حذف یا تغییر مخرب روی ووکامرس انجام نمی‌شود.</p>
					<table class="widefat striped sn-wheel-products-table">
						<thead><tr><th>محصول</th><th>تعداد شانس قرعه‌کشی</th><th>گردونه دارد؟</th><th>گردونه متصل</th><th>شامل کد تخفیف؟</th><th>توضیح کوتاه در پیش‌فاکتور</th></tr></thead>
						<tbody>
						<?php if (empty($products)) : ?><tr><td colspan="5">محصولی یافت نشد.</td></tr><?php endif; ?>
						<?php foreach ($products as $prod): $pid=$prod->get_id(); ?>
							<tr>
								<td><strong><?php echo esc_html($prod->get_name()); ?></strong><input type="hidden" name="sn_product_wheel[<?php echo esc_attr($pid); ?>][id]" value="<?php echo esc_attr($pid); ?>"></td>
								<td><input type="number" min="0" name="sn_product_wheel[<?php echo esc_attr($pid); ?>][lottery_chance_count]" value="<?php echo esc_attr((string) get_post_meta($pid, '_sn_lottery_chance_count', true)); ?>" class="small-text"></td>
								<td><label><input type="checkbox" name="sn_product_wheel[<?php echo esc_attr($pid); ?>][has_lucky_wheel]" value="1" <?php checked(get_post_meta($pid, '_sn_has_lucky_wheel', true), '1'); ?>> فعال</label></td>
								<td><select name="sn_product_wheel[<?php echo esc_attr($pid); ?>][wheel_id]"><option value="">پیش‌فرض</option><?php foreach ((is_array($s['sn_lucky_wheels'])?$s['sn_lucky_wheels']:[]) as $wid => $wheel): ?><option value="<?php echo esc_attr($wid); ?>" <?php selected(get_post_meta($pid, '_sn_wheel_id', true), $wid); ?>><?php echo esc_html($wheel['title'] ?? $wid); ?></option><?php endforeach; ?></select></td>
								<td><label><input type="checkbox" name="sn_product_wheel[<?php echo esc_attr($pid); ?>][has_discount_coupon]" value="1" <?php checked(get_post_meta($pid, '_sn_has_discount_coupon', true), '1'); ?>> فعال</label></td>
								<td><textarea name="sn_product_wheel[<?php echo esc_attr($pid); ?>][short_description]" rows="2" style="width:100%"><?php echo esc_textarea((string) get_post_meta($pid, '_sn_short_description', true)); ?></textarea></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="sn-admin-tab-panel" id="sn-settings-wallet"><table class="form-table"><tr><th>محاسبه خودکار پورسانت</th><td><label><input type="checkbox" name="sn_wallet_auto_credit" value="1" <?php checked($s['sn_wallet_auto_credit'], '1'); ?>> بعد از پرداخت/تایید مالی فاکتور، پورسانت خودکار به کیف پول اضافه شود</label></td></tr><tr><th>پورسانت فروشنده</th><td><select name="sn_seller_commission_type"><option value="percent" <?php selected($s['sn_seller_commission_type'], 'percent'); ?>>درصدی</option><option value="fixed" <?php selected($s['sn_seller_commission_type'], 'fixed'); ?>>مبلغ ثابت</option></select> <input type="number" step="0.01" name="sn_seller_commission_value" value="<?php echo esc_attr($s['sn_seller_commission_value']); ?>" class="small-text"></td></tr><tr><th>پورسانت سرپرست</th><td><select name="sn_supervisor_commission_type"><option value="percent" <?php selected($s['sn_supervisor_commission_type'], 'percent'); ?>>درصدی</option><option value="fixed" <?php selected($s['sn_supervisor_commission_type'], 'fixed'); ?>>مبلغ ثابت</option></select> <input type="number" step="0.01" name="sn_supervisor_commission_value" value="<?php echo esc_attr($s['sn_supervisor_commission_value']); ?>" class="small-text"></td></tr></table></div>
				<div class="sn-admin-tab-panel" id="sn-settings-invoice"><table class="form-table"><tr><th>شماره کارت</th><td><input type="text" name="sn_card_number" class="regular-text" value="<?php echo esc_attr($s['sn_card_number']); ?>" placeholder="xxxx-xxxx-xxxx-xxxx"></td></tr><tr><th>نام صاحب کارت</th><td><input type="text" name="sn_card_owner" value="<?php echo esc_attr($s['sn_card_owner']); ?>"></td></tr><tr><th>موارد قابل نمایش در اطلاعات محصول</th><td><label><input type="checkbox" name="sn_invoice_info_show_short_desc" value="1" <?php checked($s['sn_invoice_info_show_short_desc'], '1'); ?>> توضیحات کوتاه</label><br><label><input type="checkbox" name="sn_invoice_info_show_price" value="1" <?php checked($s['sn_invoice_info_show_price'], '1'); ?>> قیمت عادی/تخفیفی</label><br><label><input type="checkbox" name="sn_invoice_info_show_lottery" value="1" <?php checked($s['sn_invoice_info_show_lottery'], '1'); ?>> تعداد شانس قرعه‌کشی</label><br><label><input type="checkbox" name="sn_invoice_info_show_coupon" value="1" <?php checked($s['sn_invoice_info_show_coupon'], '1'); ?>> وضعیت کد تخفیف</label><br><label><input type="checkbox" name="sn_invoice_info_show_image" value="1" <?php checked($s['sn_invoice_info_show_image'] ?? '1', '1'); ?>> عکس محصول</label><br><label><input type="checkbox" name="sn_invoice_info_show_gallery" value="1" <?php checked($s['sn_invoice_info_show_gallery'] ?? '1', '1'); ?>> گالری محصول</label></td></tr><tr><th>دکمه‌های قابل نمایش در پیش‌فاکتور مشتری</th><td><label><input type="checkbox" name="sn_invoice_btn_show_product_info" value="1" <?php checked($s['sn_invoice_btn_show_product_info'] ?? '1', '1'); ?>> اطلاعات محصول</label><br><label><input type="checkbox" name="sn_invoice_btn_show_lottery" value="1" <?php checked($s['sn_invoice_btn_show_lottery'] ?? '1', '1'); ?>> شانس قرعه‌کشی</label><br><label><input type="checkbox" name="sn_invoice_btn_show_wheel" value="1" <?php checked($s['sn_invoice_btn_show_wheel'] ?? '1', '1'); ?>> گردونه شانس</label><br><label><input type="checkbox" name="sn_invoice_btn_show_coupon" value="1" <?php checked($s['sn_invoice_btn_show_coupon'] ?? '1', '1'); ?>> کد تخفیف</label><br><label><input type="checkbox" name="sn_invoice_btn_show_recontact" value="1" <?php checked($s['sn_invoice_btn_show_recontact'] ?? '1', '1'); ?>> ارتباط مجدد با کارشناس</label><hr><label><input type="checkbox" name="sn_invoice_btn_show_online_payment" value="1" <?php checked($s['sn_invoice_btn_show_online_payment'] ?? '1', '1'); ?>> دکمه پرداخت آنلاین</label><br><label><input type="checkbox" name="sn_invoice_btn_show_card_payment" value="1" <?php checked($s['sn_invoice_btn_show_card_payment'] ?? '1', '1'); ?>> دکمه کارت‌به‌کارت</label><p class="description">اگر دکمه‌ای غیرفعال شود، فقط در صفحه پیش‌فاکتور مشتری پنهان می‌شود و فلوهای داخلی/مالی/پیامک تغییر نمی‌کند.</p></td></tr></table></div>
				<div class="sn-admin-tab-panel" id="sn-settings-pages"><table class="form-table"><?php foreach ($page_defs as $option_key => $page_def) : $page_id = (int) get_option($option_key, ''); $view_url = $page_id ? get_permalink($page_id) : ''; ?><tr><th><?php echo esc_html($page_def['title']); ?></th><td><input type="number" name="<?php echo esc_attr($option_key); ?>" value="<?php echo esc_attr($page_id); ?>" class="small-text"> <?php if ($view_url) : ?><a class="button button-small" href="<?php echo esc_url($view_url); ?>" target="_blank">مشاهده صفحه</a><?php endif; ?><p class="description">slug: <code><?php echo esc_html($page_def['slug']); ?></code> | shortcode: <code><?php echo esc_html($page_def['shortcode']); ?></code></p></td></tr><?php endforeach; ?><tr><th>بررسی صفحات سیستم</th><td><button type="button" class="button button-secondary" id="sn-repair-pages">بررسی و اصلاح صفحات سیستم</button><span id="sn-repair-pages-result" style="font-weight:600;margin-right:8px"></span><?php if (!empty($duplicate_report)) : ?><div class="notice notice-warning inline"><p>صفحات مشابه گزارش شده‌اند؛ هیچ صفحه‌ای خودکار حذف نشده است.</p></div><?php endif; ?></td></tr></table></div>
				<p><button type="submit" class="button button-primary">ذخیره تنظیمات</button></p>
			</form>
			<script>
			jQuery(function($){
				function showProvider(){ var p=$('#sn_sms_provider').val(); $('.sn-provider-row').hide(); $('.sn-provider-'+p).show(); }
				showProvider(); $('#sn_sms_provider').on('change', showProvider);
				$('.sn-admin-tab').on('click', function(){ var t=$(this).data('tab'); $('.sn-admin-tab').removeClass('active'); $(this).addClass('active'); $('.sn-admin-tab-panel').removeClass('active').hide(); $('#sn-settings-'+t).addClass('active').show(); });
				$('.sn-admin-tab-panel').hide(); $('.sn-admin-tab-panel.active').show();
				$('#sn-test-sms-btn').on('click', function(){ var phone=$('#sn-test-sms-phone').val().trim(); if(!phone){alert('شماره موبایل را وارد کنید');return;} var $b=$(this),$r=$('#sn-test-sms-result'); $b.prop('disabled',true).text('در حال ارسال...'); $.post(ajaxurl,{action:'sn_test_sms',nonce:snAdmin.nonce,phone:phone},function(res){ $b.prop('disabled',false).text('ارسال پیامک تست'); $r.css('color',res.success?'green':'red').text((res.success?'✅ ':'❌ ')+(res.message||'')); }); });
			});
			</script>
		</div>
		<?php
			}


			public function ajax_seller_invoices(): void
			{
				if (! is_user_logged_in() || ! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return; }
				$user = wp_get_current_user(); global $wpdb;
				$tab = sanitize_key($_POST['tab'] ?? 'all');
				$page = max(1, absint($_POST['page'] ?? 1)); $limit = min(50, max(10, absint($_POST['limit'] ?? 30))); $offset = ($page-1)*$limit;
				$where = ['seller_id=%d']; $args = [(int)$user->ID];
				if ($tab === 'pre_invoice') { $where[] = "status IN ('pre_invoice','pending')"; }
				elseif ($tab === 'paid') { $where[] = "status IN ('paid','approved')"; }
				elseif ($tab === 'rejected') { $where[] = "status='rejected' OR payment_status='rejected' OR invoice_status='rejected'"; }
				elseif ($tab === 'recontact') { $where[] = "status='recontact_requested' OR invoice_status='recontact_requested'"; }
				$sql_where = implode(' AND ', array_map(function($w){ return '(' . $w . ')'; }, $where));
				$total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE {$sql_where}", ...$args));
				$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge($args, [$limit, $offset])), ARRAY_A);
				foreach ($rows as &$row) { $row['product_name'] = $this->sn_invoice_items_label((int)$row['id'], (int)$row['product_id']); $row['amount_fmt'] = SN_Helpers::format_price((float)($row['final_total'] ?: $row['product_price'])); $row['status_label'] = SN_Helpers::status_label((string)$row['status']); }
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['invoices'=>$rows, 'items'=>$rows, 'page'=>$page, 'limit'=>$limit, 'total'=>$total]);
			}


			public function ajax_get_unassigned(): void
			{
				if (! is_user_logged_in()) {
					SN_Helpers::send_json(false, 'وارد نشده‌اید');
					return;
				}
				$user = wp_get_current_user();
				if (! in_array('sn_supervisor', (array) $user->roles, true) && ! current_user_can('manage_options')) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				global $wpdb;
				$supervisor_id = current_user_can('manage_options') ? absint($_POST['supervisor_id'] ?? 0) : (int) $user->ID;
				if (! $supervisor_id) {
					$supervisor_id = (int) $user->ID;
				}
				$leads = $wpdb->get_results($wpdb->prepare(
					"SELECT id, phone, imported_at FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NULL AND status='supervisor_pool' ORDER BY id ASC LIMIT 300",
					$supervisor_id
				), ARRAY_A);
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['leads' => $leads ?: []]);
			}

			public function ajax_confirm_card_payment(): void
			{
				if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$id = absint($_POST['invoice_id'] ?? 0);
				if (! $id) {
					SN_Helpers::send_json(false, 'ID نامعتبر');
					return;
				}
				global $wpdb;
				$inv_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id));
				$wpdb->update($wpdb->prefix . 'sn_invoices', [
					'status'  => 'paid',
					'paid_at' => current_time('mysql'),
				], ['id' => $id]);
				if ($inv_row) {
					do_action('sn_invoice_paid', $id, $inv_row);
				}
				SN_Helpers::send_json(true, '✅ فاکتور تایید و صادر شد');
			}

			// تغییر وضعیت دستی پیش‌فاکتور از ادمین
			public function ajax_admin_change_invoice_status(): void
			{
				if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$id     = absint($_POST['invoice_id'] ?? 0);
				$status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
				$allowed = ['pre_invoice', 'pending', 'paid', 'cancelled'];
				if (! $id || ! in_array($status, $allowed, true)) {
					SN_Helpers::send_json(false, 'داده نامعتبر');
					return;
				}
				global $wpdb;
				$data = ['status' => $status];
				if ($status === 'paid') {
					$data['paid_at'] = current_time('mysql');
				}
				if ($status === 'pre_invoice' || $status === 'pending') {
					// ریست کردن فیش
					$data['receipt_url'] = null;
					$data['pay_method']  = null;
					$data['paid_at']     = null;
				}
				$inv_row = $wpdb->get_row($wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",
					$id
				));
				SN_Invoice_State_Machine::transition($id, $status, 'admin_change_status');
			$wpdb->update($wpdb->prefix . 'sn_invoices', $data, ['id' => $id]);

				// اگه تبدیل به فاکتور شد، action بزن
				if ($status === 'paid' && $inv_row) {
					do_action('sn_invoice_paid', $id, $inv_row);
				}
				// اگه lead رو برگردوندیم، وضعیتش رو هم reset کن
				if (in_array($status, ['pre_invoice', 'cancelled'], true) && $inv_row && $inv_row->lead_id) {
					$new_lead_status = $status === 'cancelled' ? 'assigned' : 'assigned';
					$wpdb->update($wpdb->prefix . 'sn_leads', ['status' => $new_lead_status], ['id' => $inv_row->lead_id]);
				}

				$labels = [
					'pre_invoice' => 'پیش‌فاکتور',
					'paid'        => 'فاکتور تایید‌شده',
					'cancelled'   => 'لغوشده',
				];
				SN_Helpers::send_json(true, 'وضعیت به «' . ($labels[$status] ?? $status) . '» تغییر کرد');
			}

			public function ajax_reject_receipt(): void
			{
				if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$id = absint($_POST['invoice_id'] ?? 0);
				if (! $id) {
					SN_Helpers::send_json(false, 'ID نامعتبر');
					return;
				}
				global $wpdb;
				$wpdb->update($wpdb->prefix . 'sn_invoices', [
					'status'      => 'pre_invoice',
					'receipt_url' => null,
					'pay_method'  => null,
				], ['id' => $id]);
				SN_Helpers::send_json(true, '❌ فیش رد شد — پیش‌فاکتور به حالت اولیه برگشت');
			}

			// =========================================================
			// TEST SMS — برای debug از ادمین
			// =========================================================

			public function ajax_test_sms(): void
			{
				if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$phone = SN_Helpers::normalize_mobile(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
				if (! SN_Helpers::is_valid_mobile($phone)) {
					SN_Helpers::send_json(false, 'شماره موبایل نامعتبر');
					return;
				}

				// خواندن تنظیمات بر اساس provider فعال
				$provider = get_option('sn_sms_provider', 'faraz');
				$pattern  = get_option('sn_faraz_pattern_invoice', '');

				// API key رو از option اختصاصی provider بخون (نه sn_sms_api_key عمومی)
				$api_key_map = [
					'faraz'       => get_option('sn_sms_api_key_faraz', '') ?: get_option('sn_sms_api_key', ''),
					'kavenegar'   => get_option('sn_sms_api_key_kavenegar', '') ?: get_option('sn_sms_api_key', ''),
					'melipayamak' => get_option('sn_sms_api_key', ''),
				];
				$sender_map = [
					'faraz'       => get_option('sn_sms_sender_faraz', '') ?: get_option('sn_sms_sender', ''),
					'kavenegar'   => get_option('sn_sms_sender_kavenegar', '') ?: get_option('sn_sms_sender', ''),
					'melipayamak' => get_option('sn_sms_sender', ''),
				];
				$api_key = $api_key_map[$provider] ?? get_option('sn_sms_api_key', '');
				$sender  = $sender_map[$provider]  ?? get_option('sn_sms_sender', '');

				// sync کردن sn_sms_api_key عمومی با provider فعال قبل از ارسال
				if ($api_key) {
					update_option('sn_sms_api_key', $api_key);
				}
				if ($sender) {
					update_option('sn_sms_sender',  $sender);
				}

				$sms = new SN_SMS(); // حالا با مقادیر sync‌شده

				if (empty($api_key)) {
					SN_Helpers::send_json(false, 'API Key برای سرویس ' . $provider . ' تنظیم نشده');
					return;
				}

				$result = $sms->send_invoice_link(
					$phone,
					'TEST-0000',
					home_url('/test-invoice/'),
					'مشتری تست',
					'100000',
					get_option('sn_card_number', '')
				);

				if ($result) {
					SN_Helpers::send_json(true, 'پیامک تست با موفقیت ارسال شد — لاگ را چک کنید', [
						'provider' => $provider,
						'sender'   => $sender,
						'pattern'  => $pattern,
					]);
				} else {
					SN_Helpers::send_json(false, 'ارسال ناموفق — لاگ وردپرس را چک کنید (wp-content/debug.log)', [
						'provider' => $provider,
						'api_key'  => substr($api_key, 0, 8) . '...',
						'sender'   => $sender,
						'pattern'  => $pattern,
					]);
				}
			}

			// =========================================================
			// LEAD STATUS AJAX
			// =========================================================

			public function ajax_save_customer_info(): void
			{
				if (! is_user_logged_in() || ! check_ajax_referer('sn_public', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$this->maybe_create_tables();
				$user    = wp_get_current_user();
				$lead_id = absint($_POST['lead_id'] ?? 0);
				if (! $lead_id) {
					SN_Helpers::send_json(false, 'شناسه نامعتبر');
					return;
				}

				global $wpdb;
				$owner = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT seller_id FROM {$wpdb->prefix}sn_leads WHERE id=%d",
					$lead_id
				));
				if ($owner !== (int) $user->ID && ! current_user_can('manage_options')) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}

				$data = [
					'customer_name'    => sanitize_text_field(wp_unslash($_POST['customer_name']    ?? '')),
					'province'         => sanitize_text_field(wp_unslash($_POST['province']         ?? '')),
					'city'             => sanitize_text_field(wp_unslash($_POST['city']             ?? '')),
					'sales_prediction' => sanitize_text_field(wp_unslash($_POST['sales_prediction'] ?? '')),
					'note'             => sanitize_textarea_field(wp_unslash($_POST['note']        ?? '')),
					'lead_status'      => sanitize_text_field(wp_unslash($_POST['lead_status']     ?? '')),
				];

				$wpdb->update($wpdb->prefix . 'sn_leads', $data, ['id' => $lead_id]);
				SN_Helpers::send_json(true, 'اطلاعات ذخیره شد', $data);
			}

			public function ajax_update_lead_status(): void
			{
				if (! is_user_logged_in() || ! check_ajax_referer('sn_public', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				// اطمینان از وجود ستون lead_status
				$this->maybe_create_tables();
				$user   = wp_get_current_user();
				$lead_id = absint($_POST['lead_id'] ?? 0);
				$has_status = array_key_exists('lead_status', $_POST);
				$status  = $has_status ? sanitize_text_field(wp_unslash($_POST['lead_status'] ?? '')) : null;
				$note    = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));

				if (! $lead_id) {
					SN_Helpers::send_json(false, 'شناسه نامعتبر');
					return;
				}

				global $wpdb;
				// فروشنده فقط lead های خودش رو میتونه آپدیت کنه
				$owner = $wpdb->get_var($wpdb->prepare(
					"SELECT seller_id FROM {$wpdb->prefix}sn_leads WHERE id=%d",
					$lead_id
				));
				if ((int) $owner !== (int) $user->ID && ! current_user_can('manage_options')) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}

				// وضعیت را فقط وقتی عمداً ارسال شده ذخیره کن؛ این جلوی پاک‌شدن اتفاقی وضعیت‌ها را می‌گیرد.
				$data = ['note' => $note];
				if ($has_status) {
					// مقدار خالی فقط وقتی allow_clear=1 باشد وضعیت را پاک می‌کند.
					$allow_clear = ! empty($_POST['allow_clear']);
					if ($status !== '' || $allow_clear) {
						$data['lead_status'] = $status;
						// route اختیاری وضعیت: فقط اگر ادمین برای این وضعیت مقصد انتخاب کرده باشد.
						$route = $status !== '' ? $wpdb->get_row($wpdb->prepare("SELECT destination_panel, move_to_destination FROM {$wpdb->prefix}sn_lead_statuses WHERE label=%s AND is_active=1 LIMIT 1", $status), ARRAY_A) : null;
						if ($route && ! empty($route['move_to_destination']) && ! empty($route['destination_panel'])) {
							$data['destination_panel'] = sanitize_key((string) $route['destination_panel']);
							$data['destination_routed_at'] = current_time('mysql');
						} elseif ($allow_clear && $status === '') {
							$data['destination_panel'] = null;
							$data['destination_routed_at'] = null;
						}
					}
				}

				$result = $wpdb->update($wpdb->prefix . 'sn_leads', $data, ['id' => $lead_id]);
				if ($result === false) {
					error_log('SN update_lead_status DB error: ' . $wpdb->last_error . ' | data: ' . wp_json_encode($data));
					SN_Helpers::send_json(false, 'خطای DB: ' . $wpdb->last_error);
					return;
				}
				SN_Helpers::send_json(true, 'وضعیت بروزرسانی شد');
			}

			public function ajax_get_lead_statuses(): void
			{
				// اطمینان از وجود جدول
				$this->maybe_create_tables();
				global $wpdb;
				$table = $wpdb->prefix . 'sn_lead_statuses';
				$statuses = $wpdb->get_results(
					"SELECT id, label, color, sort_order, destination_panel, move_to_destination FROM {$table} WHERE is_active=1 ORDER BY sort_order ASC",
					ARRAY_A
				);
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['statuses' => $statuses ?: []]);
			}

			public function ajax_save_statuses(): void
			{
				if (! current_user_can('manage_options')) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز — ادمین نیستید');
					return;
				}
				if (! check_ajax_referer('sn_admin', 'nonce', false)) {
					SN_Helpers::send_json(false, 'خطای امنیتی — صفحه را رفرش کنید');
					return;
				}
				global $wpdb;
				$table = $wpdb->prefix . 'sn_lead_statuses';

				// ساخت جدول اگه وجود نداره
				if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
					$wpdb->query("CREATE TABLE {$table} (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				label VARCHAR(100) NOT NULL,
				color VARCHAR(20) NOT NULL DEFAULT '#6b7280',
				sort_order INT NOT NULL DEFAULT 0,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				destination_panel VARCHAR(50) DEFAULT NULL,
				move_to_destination TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
			) " . $wpdb->get_charset_collate());
				}

				$statuses = $_POST['statuses'] ?? [];
				if (! is_array($statuses)) {
					SN_Helpers::send_json(false, 'داده نامعتبر است');
					return;
				}

				$wpdb->query("UPDATE {$table} SET is_active=0");
				$inserted = 0;
				foreach ($statuses as $i => $s) {
					$id = absint($s['id'] ?? 0);
					$label = sanitize_text_field($s['label'] ?? '');
					$color = sanitize_hex_color($s['color'] ?? '#6b7280') ?: '#6b7280';
					if ($label) {
						$data = [
							'label'      => $label,
							'color'      => $color,
							'sort_order' => (int) $i,
							'is_active'  => 1,
							'destination_panel' => sanitize_key($s['destination_panel'] ?? ''),
							'move_to_destination' => ! empty($s['move_to_destination']) ? 1 : 0,
						];
						if ($id) {
							$wpdb->update($table, $data, ['id' => $id]);
						} else {
							$wpdb->insert($table, $data);
						}
						$inserted++;
					}
				}
				SN_Helpers::send_json(true, "✅ {$inserted} وضعیت ذخیره شد");
			}

			public function render_admin_statuses(): void
			{
				$this->maybe_create_tables(); global $wpdb;
				$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_lead_statuses ORDER BY sort_order ASC", ARRAY_A) ?: [];
				$ajax_url = admin_url('admin-ajax.php'); $nonce = wp_create_nonce('sn_admin');
				$destinations = ['' => 'بدون مقصد', 'after_sales' => 'خدمات پس از فروش', 'financial' => 'تایید مالی', 'supervisor' => 'پنل سرپرست'];
				?>
		<div class="wrap" dir="rtl" style="font-family:Tahoma,Arial,sans-serif">
			<h1>🏷️ وضعیت‌های تماس و مسیر کارتابل</h1>
			<p style="color:#666;margin-bottom:16px">برای هر وضعیت می‌توانید به‌صورت اختیاری مقصد انتخاب کنید. اگر «ارسال به مقصد» فعال باشد، پروفایل هم در تب فروشنده می‌ماند و هم در کارتابل مقصد نمایش داده می‌شود.</p>
			<div id="sn-st-notice"></div>
			<table class="widefat striped" style="max-width:920px"><thead><tr><th>عنوان</th><th style="width:100px">رنگ</th><th style="width:190px">مقصد اختیاری</th><th style="width:130px">ارسال به مقصد</th><th style="width:60px">حذف</th></tr></thead><tbody id="sn-st-body">
			<?php foreach ($rows as $st) : ?><tr>
				<td><input type="hidden" class="sn-st-id" value="<?php echo esc_attr($st['id']); ?>"><input type="text" class="regular-text sn-st-lbl" value="<?php echo esc_attr($st['label']); ?>" style="width:98%"></td>
				<td><input type="color" class="sn-st-clr" value="<?php echo esc_attr($st['color']); ?>"></td>
				<td><select class="sn-st-dest"><?php foreach ($destinations as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected((string)($st['destination_panel'] ?? ''), $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></td>
				<td><label><input type="checkbox" class="sn-st-move" value="1" <?php checked(!empty($st['move_to_destination'])); ?>> فعال</label></td>
				<td><button type="button" class="button" onclick="this.parentNode.parentNode.remove()">❌</button></td>
			</tr><?php endforeach; ?></tbody></table>
			<p style="margin-top:14px;display:flex;gap:10px"><button type="button" class="button" id="sn-st-add">➕ افزودن وضعیت</button><button type="button" class="button button-primary" id="sn-st-save">💾 ذخیره</button></p>
		</div>
		<script>(function(){var AJ="<?php echo esc_js($ajax_url); ?>",NK="<?php echo esc_js($nonce); ?>";function destOptions(){return '<option value="">بدون مقصد</option><option value="after_sales">خدمات پس از فروش</option><option value="financial">تایید مالی</option><option value="supervisor">پنل سرپرست</option>';}
		document.getElementById('sn-st-add').addEventListener('click',function(){var tr=document.createElement('tr');tr.innerHTML='<td><input type="text" class="regular-text sn-st-lbl" placeholder="عنوان وضعیت" style="width:98%"></td><td><input type="color" class="sn-st-clr" value="#6b7280"></td><td><select class="sn-st-dest">'+destOptions()+'</select></td><td><label><input type="checkbox" class="sn-st-move" value="1"> فعال</label></td><td><button type="button" class="button" onclick="this.parentNode.parentNode.remove()">❌</button></td>';document.getElementById('sn-st-body').appendChild(tr);});
		document.getElementById('sn-st-save').addEventListener('click',function(){var btn=this,notice=document.getElementById('sn-st-notice'),fd=new FormData();btn.disabled=true;notice.innerHTML='<p>⏳ در حال ذخیره...</p>';fd.append('action','sn_save_statuses');fd.append('nonce',NK);document.querySelectorAll('#sn-st-body tr').forEach(function(tr,i){var l=tr.querySelector('.sn-st-lbl'),c=tr.querySelector('.sn-st-clr'),id=tr.querySelector('.sn-st-id'),d=tr.querySelector('.sn-st-dest'),m=tr.querySelector('.sn-st-move');if(l&&l.value.trim()){fd.append('statuses['+i+'][label]',l.value.trim());fd.append('statuses['+i+'][color]',c?c.value:'#6b7280');fd.append('statuses['+i+'][id]',id?id.value:'');fd.append('statuses['+i+'][destination_panel]',d?d.value:'');fd.append('statuses['+i+'][move_to_destination]',m&&m.checked?'1':'0');}});fetch(AJ,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){btn.disabled=false;notice.innerHTML='<div class="notice notice-'+(d.success?'success':'error')+' inline"><p>'+d.message+'</p></div>';}).catch(function(e){btn.disabled=false;notice.innerHTML='<div class="notice notice-error inline"><p>خطا: '+e+'</p></div>';});});})();</script>
		<?php
			}



			public function register_myaccount_endpoint(): void
			{
				add_rewrite_endpoint('sn-invoices', EP_ROOT | EP_PAGES);
				// Flush only once after activation (flag set in activator)
				if (get_option('sn_flush_rewrite_needed') === '1') {
					flush_rewrite_rules(false);
					delete_option('sn_flush_rewrite_needed');
				}
			}

			public function add_myaccount_menu_item(array $items): array
			{
				// درج قبل از خروج
				$logout = $items['customer-logout'] ?? null;
				unset($items['customer-logout']);
				$items['sn-invoices'] = '🧾 فاکتورهای من';
				if ($logout !== null) {
					$items['customer-logout'] = $logout;
				}
				return $items;
			}

			public function render_myaccount_invoices(): void
			{
				if (! is_user_logged_in()) {
					return;
				}
				$user_id = get_current_user_id();
				global $wpdb;

				$invoices = $wpdb->get_results($wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}sn_invoices WHERE customer_wp_id = %d ORDER BY id DESC",
					$user_id
				), ARRAY_A);

				$status_map = [
					'pre_invoice'      => ['پیش‌فاکتور — در انتظار پرداخت', '#f59e0b'],
					'pending'          => ['پیش‌فاکتور — در انتظار پرداخت', '#f59e0b'],
					'receipt_uploaded' => ['نیاز به بررسی فیش', '#2196F3'],
					'pending_financial_approval' => ['نیاز به بررسی فیش', '#2196F3'],
					'paid'             => ['پرداخت‌شده ✅', '#4CAF50'],
					'cancelled'        => ['لغوشده', '#f44336'],
				];

				$page_id = (int) get_option('sn_invoice_page_id');

				echo '<div class="sn-myaccount-invoices" dir="rtl" style="font-family:Tahoma,sans-serif">';
				echo '<h3 style="margin-bottom:20px">فاکتورهای من</h3>';

				if (empty($invoices)) {
					echo '<p style="color:#718096">هیچ فاکتوری یافت نشد.</p>';
				} else {
					echo '<div style="overflow-x:auto"><table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive">';
					echo '<thead><tr>
				<th>کد فاکتور</th>
				<th>محصول</th>
				<th>مبلغ</th>
				<th>وضعیت</th>
				<th>تاریخ</th>
				<th>عملیات</th>
			</tr></thead><tbody>';

					foreach ($invoices as $inv) {
						[$slabel, $scolor] = $status_map[$inv['status']] ?? [$inv['status'], '#999'];
						$pay_url = $page_id
							? add_query_arg('invoice', $inv['invoice_code'], get_permalink($page_id))
							: home_url('?invoice=' . $inv['invoice_code']);

						echo '<tr>';
						echo '<td><code>' . esc_html($inv['invoice_code']) . '</code></td>';
						echo '<td>' . esc_html(get_the_title((int) $inv['product_id'])) . '</td>';
						echo '<td>' . esc_html(SN_Helpers::format_price((float) $inv['product_price'])) . '</td>';
						echo '<td><span style="color:' . esc_attr($scolor) . ';font-weight:600">' . esc_html($slabel) . '</span></td>';
						echo '<td>' . esc_html($inv['created_at']) . '</td>';
						echo '<td>';
						if (in_array($inv['status'], ['pending', 'pre_invoice'], true)) {
							echo '<a href="' . esc_url($pay_url) . '" class="button alt" style="font-size:13px">پرداخت</a>';
						} elseif ($inv['status'] === 'paid') {
							echo '<a href="' . esc_url($pay_url) . '" class="button" style="font-size:13px">مشاهده</a>';
						}
						echo '</td>';
						echo '</tr>';

						// اگه پرداخت شده، محتوای اشتراک رو نشون بده
						if ($inv['status'] === 'paid') {
							$sub_content = get_post_meta((int) $inv['product_id'], '_sn_subscription_content', true);
							if ($sub_content) {
								echo '<tr><td colspan="6" style="background:#f7fff7;padding:12px 16px;border-right:4px solid #4CAF50">';
								echo '<strong style="display:block;margin-bottom:6px;color:#276749">🎁 دسترسی شما:</strong>';
								echo wp_kses_post($sub_content);
								echo '</td></tr>';
							}
						}
					}
					echo '</tbody></table></div>';
				}
				echo '</div>';
			}

	// =========================================================
	// INVOICE PAID ACTION
	// =========================================================

			/**
			 * اجرا بعد از پرداخت موفق فاکتور (آنلاین یا کارت)
			 * $invoice می‌تواند object یا array باشد
			 */
			public function on_invoice_paid(int $invoice_id, $invoice): void
			{
				global $wpdb;

				$inv = is_object($invoice) ? $invoice : (object) $invoice;

				// اگه customer_wp_id نداشت، سعی کن بسازیم
				$customer_wp_id = (int) ($inv->customer_wp_id ?? 0);
				if (! $customer_wp_id) {
					$customer_wp_id = $this->get_or_create_customer_account(
						$inv->customer_phone,
						$inv->customer_name
					);
					if ($customer_wp_id) {
						$wpdb->update(
							$wpdb->prefix . 'sn_invoices',
							['customer_wp_id' => $customer_wp_id],
							['id' => $invoice_id]
						);
					}
				}

				// تکمیل پروفایل مشتری و ثبت سفارش ووکامرس پس از پرداخت تایید شده
				if ($customer_wp_id) { $this->sn_sync_customer_profile_from_invoice($customer_wp_id, $inv); }
				$this->sn_create_wc_order_for_invoice($invoice_id, $inv);

				// ذخیره محصولات خریداری‌شده در user meta برای دسترسی سریع
				$this->sn_credit_wallet_for_invoice($invoice_id, $inv);
				$pm = (string)($inv->pay_method ?? '');
				$this->sn_maybe_send_payment_sms($inv, $pm === 'online' ? 'online' : 'card_to_card');

				if ($customer_wp_id && $inv->product_id) {
					$owned = get_user_meta($customer_wp_id, 'sn_owned_products', true);
					$owned = is_array($owned) ? $owned : [];
					if (! in_array((int) $inv->product_id, $owned, true)) {
						$owned[] = (int) $inv->product_id;
						update_user_meta($customer_wp_id, 'sn_owned_products', $owned);
					}
				}
			}

			// =========================================================
			// ACTIVITY LOG / LEAD PROFILE / FINANCIAL APPROVAL
			// =========================================================

			private function sn_can_finance(): bool
			{
				return current_user_can('manage_options') || current_user_can('sn_view_payments') || current_user_can('sn_approve_payments') || current_user_can('sn_reject_payments');
			}

			private function sn_log_activity(?int $invoice_id, ?int $lead_id, string $action, string $description = '', array $context = []): void
			{
				global $wpdb;
				$old_value = $context['old_value'] ?? null;
				$new_value = $context['new_value'] ?? null;
				$table = $wpdb->prefix . 'sn_activity_logs';
				$data = [
					'invoice_id'  => $invoice_id ?: null,
					'lead_id'     => $lead_id ?: null,
					'user_id'     => get_current_user_id() ?: null,
					'action'      => sanitize_key($action),
					'old_value'   => is_null($old_value) ? null : wp_json_encode($old_value, JSON_UNESCAPED_UNICODE),
					'new_value'   => is_null($new_value) ? null : wp_json_encode($new_value, JSON_UNESCAPED_UNICODE),
					'description' => sanitize_textarea_field($description),
					'context'     => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
					'ip_address'  => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null,
					'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : null,
					'created_at'  => current_time('mysql'),
				];
				$wpdb->insert($table, $this->sn_filter_existing_columns($table, $data));
			}

			private function sn_log_status_history(int $lead_id, $old_status, $new_status, string $note = ''): void
			{
				global $wpdb;
				$wpdb->insert($wpdb->prefix . 'sn_lead_status_history', [
					'lead_id'    => $lead_id,
					'user_id'    => get_current_user_id() ?: null,
					'old_status' => is_null($old_status) ? null : sanitize_text_field((string) $old_status),
					'new_status' => is_null($new_status) ? null : sanitize_text_field((string) $new_status),
					'note'       => sanitize_textarea_field($note),
					'created_at' => current_time('mysql'),
				]);
				$this->sn_log_activity(null, $lead_id, 'lead_status_history', 'ثبت تاریخچه تغییر وضعیت', ['old_status' => $old_status, 'new_status' => $new_status, 'note' => $note]);
			}

			private function sn_digits_only(string $value): string
			{
				$map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
				$value = strtr($value, $map);
				return preg_replace('/\D+/', '', $value) ?: '';
			}

			private function sn_find_wp_user_by_phone(string $phone): ?array
			{
				$digits = $this->sn_digits_only($phone);
				if ($digits === '') { return null; }
				$variants = array_values(array_unique([$phone, $digits, ltrim($digits, '0'), preg_replace('/^98/', '0', $digits)]));
				$meta_keys = ['billing_phone', 'phone', 'mobile', 'user_phone', 'sn_phone'];
				foreach ($variants as $v) {
					if ($v === '') { continue; }
					$users = get_users(['number' => 1, 'search' => $v, 'search_columns' => ['user_login', 'user_email']]);
					if (! empty($users)) { return $this->sn_wp_user_public_profile($users[0]); }
					foreach ($meta_keys as $key) {
						$users = get_users(['number' => 1, 'meta_key' => $key, 'meta_value' => $v]);
						if (! empty($users)) { return $this->sn_wp_user_public_profile($users[0]); }
					}
				}
				return null;
			}

			private function sn_wp_user_public_profile(WP_User $u): array
			{
				return [
					'ID' => (int)$u->ID,
					'user_login' => $u->user_login,
					'display_name' => $u->display_name,
					'user_email' => $u->user_email,
					'user_registered' => $u->user_registered,
					'billing_phone' => (string)get_user_meta($u->ID, 'billing_phone', true),
				];
			}

			public function ajax_lead_profile(): void
			{
				if (! is_user_logged_in()) {
					SN_Helpers::send_json(false, 'وارد نشده‌اید');
					return;
				}
				$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$lead_id = absint($_POST['lead_id'] ?? 0);
				if (! $lead_id) {
					SN_Helpers::send_json(false, 'شناسه نامعتبر');
					return;
				}
				global $wpdb;
				$lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_leads WHERE id=%d", $lead_id), ARRAY_A);
				if (! $lead) {
					SN_Helpers::send_json(false, 'شماره یافت نشد');
					return;
				}
				$user = wp_get_current_user();
				if (! current_user_can('manage_options') && ! current_user_can('sn_view_customer_profiles')) {
					if (in_array('sn_supervisor', (array) $user->roles, true) && (int) $lead['supervisor_id'] !== (int) $user->ID) {
						SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
						return;
					}
					if (in_array('sn_seller', (array) $user->roles, true) && (int) $lead['seller_id'] !== (int) $user->ID) {
						SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
						return;
					}
				}
				$invoices = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE lead_id=%d ORDER BY id DESC", $lead_id), ARRAY_A);
				$status_history = $wpdb->get_results($wpdb->prepare("SELECT h.*, u.display_name FROM {$wpdb->prefix}sn_lead_status_history h LEFT JOIN {$wpdb->users} u ON u.ID=h.user_id WHERE h.lead_id=%d ORDER BY h.id DESC", $lead_id), ARRAY_A);
				$activity = $wpdb->get_results($wpdb->prepare("SELECT l.*, u.display_name FROM {$wpdb->prefix}sn_activity_logs l LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id WHERE l.lead_id=%d ORDER BY l.id DESC LIMIT 300", $lead_id), ARRAY_A);
				$seller = ! empty($lead['seller_id']) ? get_user_by('id', (int) $lead['seller_id']) : null;
				$supervisor = ! empty($lead['supervisor_id']) ? get_user_by('id', (int) $lead['supervisor_id']) : null;
				$wp_customer = $this->sn_find_wp_user_by_phone((string)($lead['phone'] ?? ''));
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['lead' => $lead, 'seller' => $seller ? $seller->display_name : '', 'supervisor' => $supervisor ? $supervisor->display_name : '', 'wp_user' => $wp_customer, 'invoices' => $invoices, 'status_history' => $status_history, 'activity' => $activity]);
			}

			public function ajax_supervisor_unassign_leads(): void
			{
				if (! is_user_logged_in()) {
					SN_Helpers::send_json(false, 'وارد نشده‌اید');
					return;
				}
				$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$user = wp_get_current_user();
				if (! in_array('sn_supervisor', (array) $user->roles, true) && ! current_user_can('manage_options')) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$seller_id = absint($_POST['seller_id'] ?? 0);
				$count = absint($_POST['count'] ?? 0);
				$date_from = sanitize_text_field(wp_unslash($_POST['date_from'] ?? ''));
				$date_to = sanitize_text_field(wp_unslash($_POST['date_to'] ?? ''));
				$time_from = sanitize_text_field(wp_unslash($_POST['time_from'] ?? ''));
				$time_to = sanitize_text_field(wp_unslash($_POST['time_to'] ?? ''));
				$lead_status = sanitize_text_field(wp_unslash($_POST['lead_status'] ?? ''));
				$import_code = sanitize_text_field(wp_unslash($_POST['import_code'] ?? ''));
				$supervisor_id = current_user_can('manage_options') ? absint($_POST['supervisor_id'] ?? 0) : (int) $user->ID;
				if (! $supervisor_id) {
					$supervisor_id = (int) $user->ID;
				}
				global $wpdb;
				$where = ['supervisor_id=%d', 'seller_id IS NOT NULL'];
				$args = [$supervisor_id];
				if ($seller_id) {
					$where[] = 'seller_id=%d';
					$args[] = $seller_id;
				}
				$date_from_g = SN_Helpers::jalali_to_gregorian_date($date_from);
				$date_to_g = SN_Helpers::jalali_to_gregorian_date($date_to);
				if ($date_from_g) {
					$where[] = 'DATE(assigned_at) >= %s';
					$args[] = $date_from_g;
				}
				if ($date_to_g) {
					$where[] = 'DATE(assigned_at) <= %s';
					$args[] = $date_to_g;
				}
				if ($time_from) {
					$where[] = 'TIME(assigned_at) >= %s';
					$args[] = $time_from . ':00';
				}
				if ($time_to) {
					$where[] = 'TIME(assigned_at) <= %s';
					$args[] = $time_to . ':59';
				}
				if ($lead_status !== '') {
					$where[] = 'lead_status=%s';
					$args[] = $lead_status;
				}
				if ($import_code !== '') {
					$where[] = 'import_code=%s';
					$args[] = $import_code;
				}
				$limit = $count > 0 ? ' LIMIT ' . $count : '';
				$sql = "SELECT id FROM {$wpdb->prefix}sn_leads WHERE " . implode(' AND ', $where) . " ORDER BY assigned_at DESC, id DESC" . $limit;
				$ids = array_map('intval', $wpdb->get_col($wpdb->prepare($sql, ...$args)));
				if (! $ids) {
					SN_Helpers::send_json(false, 'شماره‌ای مطابق فیلترها پیدا نشد');
					return;
				}
				$ph = implode(',', array_fill(0, count($ids), '%d'));
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_leads SET seller_id=NULL, status='supervisor_pool', assigned_at=NULL WHERE id IN ($ph)", ...$ids));
				$this->sn_log_activity(null, null, 'supervisor_unassign_bulk', 'جدا کردن گروهی لید از فروشنده توسط سرپرست', ['supervisor_id' => $supervisor_id, 'seller_id' => $seller_id, 'lead_status' => $lead_status, 'import_code' => $import_code, 'lead_ids' => $ids, 'count' => count($ids)]);
				foreach ($ids as $id) {
					$this->sn_log_activity(null, $id, 'lead_unassigned', 'لید از فروشنده جدا شد', ['supervisor_id' => $supervisor_id, 'seller_id' => $seller_id]);
				}
				SN_Helpers::send_json(true, count($ids) . ' شماره از فروشنده جدا شد', ['lead_ids' => $ids]);
			}

		
	public static function write_invoice_workflow_log_static(int $invoice_id, array $data = []): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'sn_invoice_logs';
		$payload = [
			'invoice_id' => $invoice_id,
			'lead_id' => $data['lead_id'] ?? null,
			'actor_user_id' => $data['actor_user_id'] ?? (get_current_user_id() ?: null),
			'actor_role' => sanitize_text_field((string) ($data['actor_role'] ?? ((wp_get_current_user()->roles[0] ?? 'system')))),
			'from_status' => isset($data['from_status']) ? sanitize_text_field((string) $data['from_status']) : null,
			'to_status' => isset($data['to_status']) ? sanitize_text_field((string) $data['to_status']) : null,
			'action_type' => sanitize_key((string) ($data['action_type'] ?? 'workflow')),
			'note' => sanitize_textarea_field((string) ($data['note'] ?? '')),
			'assigned_from_user_id' => $data['assigned_from_user_id'] ?? null,
			'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
			'created_at' => current_time('mysql'),
		];
		$wpdb->insert($table, $payload);
	}

	private function sn_user_can_view_invoice(int $invoice_id): bool
	{
		if (current_user_can('manage_options') || $this->sn_can_finance()) { return true; }
		if (! is_user_logged_in()) { return false; }
		global $wpdb; $u = wp_get_current_user();
		$inv = $wpdb->get_row($wpdb->prepare("SELECT i.seller_id, l.supervisor_id FROM {$wpdb->prefix}sn_invoices i LEFT JOIN {$wpdb->prefix}sn_leads l ON l.id=i.lead_id WHERE i.id=%d", $invoice_id));
		if (! $inv) return false;
		if (in_array('sn_seller', (array)$u->roles, true)) return (int)$inv->seller_id === (int)$u->ID;
		if (in_array('sn_supervisor', (array)$u->roles, true)) return (int)$inv->supervisor_id === (int)$u->ID;
		return false;
	}

	private function sn_get_invoice_logs(int $invoice_id): array
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT l.*, u.display_name FROM {$wpdb->prefix}sn_invoice_logs l LEFT JOIN {$wpdb->users} u ON u.ID=l.actor_user_id WHERE l.invoice_id=%d ORDER BY l.id DESC LIMIT 200", $invoice_id), ARRAY_A);
		return $rows ?: [];
	}

	public function ajax_invoice_logs(): void
	{
		if (! is_user_logged_in() || (! check_ajax_referer('sn_public', 'nonce', false) && ! check_ajax_referer('sn_admin', 'nonce', false))) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return; }
		$invoice_id = absint($_POST['invoice_id'] ?? 0);
		if (! $invoice_id || ! $this->sn_user_can_view_invoice($invoice_id)) { SN_Helpers::send_json(false, 'اجازه مشاهده تاریخچه این فاکتور را ندارید'); return; }
		$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['logs' => $this->sn_get_invoice_logs($invoice_id)]);
	}
	private function sn_table_columns(string $table): array
			{
				global $wpdb;
				static $cache = [];
				if (! isset($cache[$table])) {
					$cache[$table] = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
				}
				return (array) $cache[$table];
			}

			private function sn_filter_existing_columns(string $table, array $data): array
			{
				$cols = $this->sn_table_columns($table);
				return array_intersect_key($data, array_flip($cols));
			}

			private function sn_ensure_payment_migrations(): void
			{
				// اجرای migration ایمن داخل AJAX هم لازم است؛ سایت‌های فعال‌شده قدیمی admin_init را همیشه اجرا نمی‌کنند.
				$this->maybe_create_tables();
			}

			private function sn_supervisor_can_access_invoice(int $invoice_id, int $supervisor_id): bool
			{
				if (current_user_can('manage_options')) {
					return true;
				}
				global $wpdb;
				$invoice = $wpdb->get_row($wpdb->prepare(
					"SELECT i.seller_id, i.lead_id, l.supervisor_id
					FROM {$wpdb->prefix}sn_invoices i
					LEFT JOIN {$wpdb->prefix}sn_leads l ON l.id=i.lead_id
					WHERE i.id=%d",
					$invoice_id
				));
				if (! $invoice) {
					return false;
				}
				if ((int) $invoice->supervisor_id === $supervisor_id) {
					return true;
				}
				return (int) get_user_meta((int) $invoice->seller_id, 'sn_supervisor_id', true) === $supervisor_id;
			}

			private function sn_save_invoice_manual_payment(int $invoice_id, string $source, array $extra = []): bool
			{
				$this->sn_ensure_payment_migrations();
				global $wpdb;
				$invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $invoice_id));
				if (! $invoice) {
					return false;
				}

				// هر نوع ثبت فیش/اطلاعات واریزی باید وارد صف تایید مالی شود.
				// این وضعیت در پنل ادمین با عنوان «نیاز به بررسی فیش» نمایش داده می‌شود.
				$data = [
					'status'                 => 'receipt_uploaded',
					'invoice_status'         => 'pending_financial_approval',
					'payment_status'         => 'pending_financial_approval',
					'pay_method'             => 'card',
					'payment_source'         => $source,
					'receipt_source'         => $source,
					'updated_at'             => current_time('mysql'),
				];
				foreach (['receipt_url', 'manual_card_from', 'manual_card_to', 'manual_amount', 'manual_paid_at', 'manual_paid_at_jalali'] as $k) {
					if (array_key_exists($k, $extra)) {
						$data[$k] = $extra[$k];
					}
				}
				if (array_key_exists('receipt_url', $data)) {
					$data['receipt_file'] = $data['receipt_url'];
				}
				if (array_key_exists('manual_card_from', $data)) {
					$data['deposit_card_from_last4'] = $data['manual_card_from'];
				}
				if (array_key_exists('manual_card_to', $data)) {
					$data['deposit_card_to_last4'] = $data['manual_card_to'];
				}
				if (array_key_exists('manual_amount', $data)) {
					$data['deposit_amount'] = $data['manual_amount'];
				}
				if (array_key_exists('manual_paid_at_jalali', $data)) {
					$data['deposit_jalali_datetime'] = $data['manual_paid_at_jalali'];
				}
				$data = $this->sn_filter_existing_columns($wpdb->prefix . 'sn_invoices', $data);
				$ok = false !== $wpdb->update($wpdb->prefix . 'sn_invoices', $data, ['id' => $invoice_id]);
				if ($ok) {
					$this->sn_log_activity($invoice_id, (int) $invoice->lead_id, $source === 'supervisor_upload' ? 'supervisor_payment_submitted' : 'customer_payment_submitted', 'ثبت فیش/اطلاعات واریزی و ارسال به تایید مالی', array_merge(['source' => $source, 'old_value' => (string) $invoice->status, 'new_value' => 'pending_financial_approval'], $extra));
				self::write_invoice_workflow_log_static($invoice_id, ['lead_id'=>(int)$invoice->lead_id,'from_status'=>(string)$invoice->status,'to_status'=>'pending_financial_approval','action_type'=>$source === 'supervisor_upload' ? 'supervisor_receipt_upload' : 'card_to_card_submission','note'=>'ارسال برای بررسی مالی']);
					$uploaded_type = $source === 'supervisor_upload' ? 'supervisor' : ($source === 'seller_upload' ? 'seller' : 'customer');
					$wpdb->insert($wpdb->prefix . 'sn_payments', $this->sn_filter_existing_columns($wpdb->prefix . 'sn_payments', [
						'invoice_id' => $invoice_id,
						'amount' => isset($extra['manual_amount']) ? (float) $extra['manual_amount'] : (float) (($invoice->final_total ?? 0) ?: ($invoice->product_price ?? 0)),
						'status' => 'pending',
						'uploaded_by_type' => $uploaded_type,
						'uploaded_by_user_id' => get_current_user_id() ?: null,
						'created_at' => current_time('mysql'),
					]));
				}
				return $ok;
			}

			public function ajax_submit_manual_payment(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$invoice = SN_Helpers::get_invoice_by_code($code);
				if (! $invoice || ! in_array((string) $invoice->status, ['pending', 'pre_invoice', 'rejected', 'pending_payment', 'receipt_uploaded', 'pending_financial_approval'], true)) {
					SN_Helpers::send_json(false, 'این فاکتور در وضعیت قابل ثبت اطلاعات واریزی نیست');
					return;
				}
				$from = sanitize_text_field(wp_unslash($_POST['card_from'] ?? ''));
				$to = sanitize_text_field(wp_unslash($_POST['card_to'] ?? ''));
				$amount = str_replace(',', '', sanitize_text_field(wp_unslash($_POST['amount'] ?? '')));
				$paid_at = sanitize_text_field(wp_unslash($_POST['paid_at'] ?? ''));
				$jy = sanitize_text_field(wp_unslash($_POST['paid_jy'] ?? ''));
				$jm = sanitize_text_field(wp_unslash($_POST['paid_jm'] ?? ''));
				$jd = sanitize_text_field(wp_unslash($_POST['paid_jd'] ?? ''));
				$hh = sanitize_text_field(wp_unslash($_POST['paid_hh'] ?? ''));
				$mi = sanitize_text_field(wp_unslash($_POST['paid_mi'] ?? ''));
				if ($jy !== '' && $jm !== '' && $jd !== '' && $hh !== '' && $mi !== '') {
					$paid_at = sprintf('%04d/%02d/%02d %02d:%02d', (int) $jy, (int) $jm, (int) $jd, (int) $hh, (int) $mi);
				}
				if ($paid_at === '') {
					SN_Helpers::send_json(false, 'تاریخ و ساعت واریز را انتخاب کنید');
					return;
				}
				if (! preg_match('/^\d{4}$/', $from) || ! preg_match('/^\d{4}$/', $to)) {
					SN_Helpers::send_json(false, '۴ رقم کارت باید عددی باشد');
					return;
				}
				if (! is_numeric($amount)) {
					SN_Helpers::send_json(false, 'مبلغ نامعتبر است');
					return;
				}
				$dt = current_time('mysql');
				if (! $this->sn_save_invoice_manual_payment((int) $invoice->id, 'customer_upload', ['manual_card_from' => $from, 'manual_card_to' => $to, 'manual_amount' => (float) $amount, 'manual_paid_at' => $dt, 'manual_paid_at_jalali' => $paid_at])) {
					SN_Helpers::send_json(false, 'ذخیره اطلاعات واریزی در دیتابیس انجام نشد');
					return;
				}
				SN_Helpers::send_json(true, 'اطلاعات واریز ثبت شد و در وضعیت نیاز به بررسی فیش قرار گرفت', ['status' => 'pending_financial_approval', 'status_label' => SN_Helpers::status_label('pending_financial_approval')]);
			}

			public function ajax_supervisor_upload_receipt(): void
			{
				if (! is_user_logged_in()) {
					SN_Helpers::send_json(false, 'وارد نشده‌اید');
					return;
				}
				$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$user = wp_get_current_user();
				if (! in_array('sn_supervisor', (array) $user->roles, true) && ! current_user_can('manage_options')) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$invoice_id = absint($_POST['invoice_id'] ?? 0);
				if (! $invoice_id || ! $this->sn_supervisor_can_access_invoice($invoice_id, (int) $user->ID)) {
					SN_Helpers::send_json(false, 'این پیش‌فاکتور متعلق به فروشنده‌های شما نیست');
					return;
				}
				if (empty($_FILES['receipt'])) {
					SN_Helpers::send_json(false, 'فایل فیش انتخاب نشده');
					return;
				}
				$url = SN_Helpers::upload_receipt($_FILES['receipt']); // phpcs:ignore
				if (! $url) {
					SN_Helpers::send_json(false, 'خطا در آپلود فیش');
					return;
				}
				if (! $this->sn_save_invoice_manual_payment($invoice_id, 'supervisor_upload', ['receipt_url' => $url])) {
					SN_Helpers::send_json(false, 'ذخیره فیش در دیتابیس انجام نشد: ' . ($GLOBALS['wpdb']->last_error ?: 'خطای نامشخص'));
					return;
				}
				SN_Helpers::send_json(true, 'فیش توسط سرپرست ثبت شد و در وضعیت نیاز به بررسی فیش قرار گرفت', ['status' => 'pending_financial_approval', 'status_label' => SN_Helpers::status_label('pending_financial_approval')]);
			}

			public function ajax_financial_approve_payment(): void
			{
				if (! $this->sn_can_finance()) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$valid = check_ajax_referer('sn_admin', 'nonce', false) || check_ajax_referer('sn_public', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$id = absint($_POST['invoice_id'] ?? 0);
				global $wpdb;
				$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id));
				if (! $inv) {
					SN_Helpers::send_json(false, 'فاکتور یافت نشد');
					return;
				}
				$this->sn_ensure_payment_migrations();
				$invoice_table = $wpdb->prefix . 'sn_invoices';
				$data = $this->sn_filter_existing_columns($invoice_table, [
					'status'                     => 'approved',
					'invoice_status'             => 'approved',
					'payment_status'             => 'approved',
					'approved_by'                => get_current_user_id(),
					'approved_at'                => current_time('mysql'),
					'financial_reviewed_by'      => get_current_user_id(),
					'financial_reviewed_at'      => current_time('mysql'),
					'paid_at'                    => current_time('mysql'),
				]);
				$wpdb->update($invoice_table, $data, ['id' => $id]);
				$this->sn_log_activity($id, (int) $inv->lead_id, 'payment_approved', 'پرداخت توسط واحد مالی تایید شد', ['approved_by' => get_current_user_id(), 'old_value' => (string) $inv->status, 'new_value' => 'approved']);
				self::write_invoice_workflow_log_static($id, ['lead_id'=>(int)$inv->lead_id,'from_status'=>(string)$inv->status,'to_status'=>'approved','action_type'=>'finance_approval','note'=>'تایید پرداخت توسط مالی']);
				do_action('sn_invoice_paid', $id, $inv);
				SN_Helpers::send_json(true, 'پرداخت تایید شد', ['status' => 'approved', 'status_label' => SN_Helpers::status_label('approved'), 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql')]);
			}

			public function ajax_financial_reject_payment(): void
			{
				if (! $this->sn_can_finance()) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$valid = check_ajax_referer('sn_admin', 'nonce', false) || check_ajax_referer('sn_public', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$id = absint($_POST['invoice_id'] ?? 0);
				$reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
				if (! $id || $reason === '') {
					SN_Helpers::send_json(false, 'شناسه یا دلیل رد نامعتبر است');
					return;
				}
				global $wpdb;
				$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id));
				if (! $inv) {
					SN_Helpers::send_json(false, 'فاکتور یافت نشد');
					return;
				}
				$this->sn_ensure_payment_migrations();
				$invoice_table = $wpdb->prefix . 'sn_invoices';
				$data = $this->sn_filter_existing_columns($invoice_table, [
					'status'                    => 'rejected',
					'invoice_status'            => 'rejected',
					'payment_status'            => 'rejected',
					'rejected_by'               => get_current_user_id(),
					'rejected_at'               => current_time('mysql'),
					'rejected_reason'           => $reason,
					'financial_reviewed_by'     => get_current_user_id(),
					'financial_reviewed_at'     => current_time('mysql'),
					'financial_reject_reason'   => $reason,
					'financial_rejected_at'     => current_time('mysql'),
					'financial_rejected_by'     => get_current_user_id(),
					'financial_return_state'  => 'returned_to_seller',
					'returned_to_seller_at'   => current_time('mysql'),
				]);
				$wpdb->update($invoice_table, $data, ['id' => $id]);
				$this->sn_log_activity($id, (int) $inv->lead_id, 'payment_rejected', 'پرداخت توسط واحد مالی رد شد', ['reason' => $reason, 'rejected_by' => get_current_user_id(), 'old_value' => (string) $inv->status, 'new_value' => 'rejected']);
				self::write_invoice_workflow_log_static($id, ['lead_id'=>(int)$inv->lead_id,'from_status'=>(string)$inv->status,'to_status'=>'rejected','action_type'=>'finance_rejection','note'=>$reason]);
				SN_Helpers::send_json(true, 'پرداخت رد شد', ['status' => 'rejected', 'status_label' => SN_Helpers::status_label('rejected'), 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql')]);
			}

			public function handle_financial_login(): void
			{
				$login = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
				$pass  = (string) ($_POST['password'] ?? '');
				$auth_id = (int) get_option('sn_financial_auth_page_id', 0);
				$auth_url = $auth_id ? get_permalink($auth_id) : home_url();
				if ($login === '' || $pass === '') {
					wp_safe_redirect(add_query_arg('sn_err', 'empty', $auth_url));
					exit;
				}
				$user = get_user_by('login', $login);
				if (! $user && is_email($login)) {
					$user = get_user_by('email', $login);
				}
				if (! $user || (! in_array('sn_financial_approval', (array) $user->roles, true) && ! in_array('sn_financial', (array) $user->roles, true) && ! user_can($user, 'manage_options'))) {
					wp_safe_redirect(add_query_arg('sn_err', 'notfound', $auth_url));
					exit;
				}
				$signon = wp_signon(['user_login' => $user->user_login, 'user_password' => $pass, 'remember' => true], is_ssl());
				if (is_wp_error($signon)) {
					wp_safe_redirect(add_query_arg('sn_err', 'wrongpass', $auth_url));
					exit;
				}
				$panel_id = (int) get_option('sn_financial_panel_page_id', 0);
				wp_safe_redirect($panel_id ? get_permalink($panel_id) : admin_url('admin.php?page=sn-financial-approval'));
				exit;
			}

			public function render_financial_auth(): string
			{
				if (is_user_logged_in() && $this->sn_can_finance()) {
					$panel_id = (int) get_option('sn_financial_panel_page_id', 0);
					$url = $panel_id ? get_permalink($panel_id) : admin_url('admin.php?page=sn-financial-approval');
					return '<script>window.location.href=' . wp_json_encode($url) . ';</script><p class="sn-notice sn-success">وارد شده‌اید — در حال انتقال...</p>';
				}
				$err = sanitize_text_field(wp_unslash($_GET['sn_err'] ?? ''));
				$errors = ['empty' => 'نام کاربری یا رمز عبور خالی است.', 'notfound' => 'کاربر تایید مالی یافت نشد یا دسترسی ندارد.', 'wrongpass' => 'رمز عبور اشتباه است.'];
				ob_start(); ?>
		<div class="sn-auth-wrap" dir="rtl">
			<div class="sn-auth-logo">
				<h2>ورود تایید مالی</h2>
				<p>برای بررسی و تایید پرداخت‌ها وارد شوید.</p>
			</div>
			<div class="sn-auth-card"><?php if ($err) : ?><div class="sn-auth-error"><?php echo esc_html($errors[$err] ?? 'خطا رخ داد.'); ?></div><?php endif; ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="sn_financial_login">
					<div class="sn-field"><label>نام کاربری / ایمیل</label><input type="text" name="phone" required autocomplete="username"></div>
					<div class="sn-field"><label>رمز عبور</label><input type="password" name="password" required autocomplete="current-password"></div><button type="submit" class="sn-auth-submit">ورود به پنل تایید مالی</button>
				</form>
			</div>
		</div>
	<?php return ob_get_clean();
			}

			public function render_financial_panel(): string
			{
				if (! is_user_logged_in()) { $auth_id=(int)get_option('sn_financial_auth_page_id',0); $url=$auth_id?get_permalink($auth_id):wp_login_url(); return '<script>window.location.href='.wp_json_encode($url).';</script><p class="sn-notice">در حال انتقال به صفحه ورود تایید مالی...</p>'; }
				if (! $this->sn_can_finance()) { return '<p class="sn-notice sn-error">دسترسی غیرمجاز.</p>'; }
				ob_start(); ?><div class="sn-panel" dir="rtl" id="sn-financial-panel"><div class="sn-panel-header"><h2>پنل تایید مالی</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="sn_financial_logout"><button type="submit" class="sn-btn sn-btn-sm">خروج</button></form></div><div id="sn-financial-kpis" class="sn-kpi-grid"></div><div class="sn-subtabs sn-financial-tabs"><button class="sn-subtab active" data-tab="needs_review">نیاز به بررسی</button><button class="sn-subtab" data-tab="online_paid">پرداخت شده آنلاین/درگاهی</button><button class="sn-subtab" data-tab="approved">تایید شده</button><button class="sn-subtab" data-tab="rejected">رد شده</button></div><div id="sn-financial-list" class="sn-card"><div class="sn-loading">در حال بارگذاری...</div></div></div><?php return ob_get_clean();
			}



			private function render_financial_approval_table(bool $wrap = true): void
			{
				global $wpdb;
				$this->sn_ensure_payment_migrations();
				$review_statuses_sql = "'pending_financial_approval','receipt_uploaded'";
				$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE status IN ({$review_statuses_sql}) OR payment_status IN ({$review_statuses_sql}) OR invoice_status IN ({$review_statuses_sql}) ORDER BY updated_at DESC, id DESC LIMIT 300");
				if ($wrap) {
					echo '<div class="wrap" dir="rtl"><h1>تایید مالی پرداخت‌ها</h1>';
				}
				echo '<table class="widefat striped sn-table"><thead><tr><th>کد</th><th>مشتری</th><th>مبلغ</th><th>نوع پرداخت / منبع</th><th>فیش / اطلاعات واریز</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
				if (! $rows) {
					echo '<tr><td colspan="7">پرداختی برای بررسی وجود ندارد.</td></tr>';
				}
				foreach ($rows as $r) {
					$jalali = isset($r->manual_paid_at_jalali) ? (string) $r->manual_paid_at_jalali : '';
					$receipt_url = ! empty($r->receipt_url) ? (string) $r->receipt_url : (string) ($r->receipt_file ?? '');
					$manual_from = (string) ($r->manual_card_from ?: ($r->deposit_card_from_last4 ?? ''));
					$manual_to = (string) ($r->manual_card_to ?: ($r->deposit_card_to_last4 ?? ''));
					$manual_amount = (string) ($r->manual_amount ?: ($r->deposit_amount ?? ''));
					$manual_jalali = $jalali ?: (string) ($r->deposit_jalali_datetime ?? '');
					$info = $receipt_url ? '<a target="_blank" href="' . esc_url($receipt_url) . '">مشاهده فیش</a>' : esc_html(trim($manual_from . ' → ' . $manual_to . ' / ' . $manual_amount . ' / ' . ($manual_jalali ?: (string) $r->manual_paid_at)));
					$source = SN_Helpers::payment_source_label((string) ($r->payment_source ?: ($r->receipt_source ?? '')));
					$effective_status = in_array((string) ($r->payment_status ?? ''), ['pending_financial_approval', 'receipt_uploaded'], true) ? (string) $r->payment_status : (in_array((string) ($r->invoice_status ?? ''), ['pending_financial_approval', 'receipt_uploaded'], true) ? (string) $r->invoice_status : (string) $r->status);
					echo '<tr><td>' . esc_html($r->invoice_code) . '</td><td>' . esc_html($r->customer_name . ' - ' . $r->customer_phone) . '</td><td>' . esc_html(SN_Helpers::format_price((float) (($r->final_total ?? 0) ?: ($r->product_price ?? 0)))) . '</td><td>' . esc_html(SN_Helpers::pay_method_label((string) $r->pay_method) . ' / ' . $source) . '</td><td>' . $info . '</td><td>' . esc_html(SN_Helpers::status_label($effective_status)) . '</td><td>';
					if (in_array($effective_status, ['pending_financial_approval', 'receipt_uploaded'], true)) {
						echo '<button class="button button-primary sn-fin-approve" data-id="' . esc_attr($r->id) . '">تایید</button> <button class="button sn-fin-reject" data-id="' . esc_attr($r->id) . '">رد</button>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
				if ($wrap) {
					echo '</div>';
				}
			}

			public function ajax_supervisor_invoices(): void
			{
				if (! is_user_logged_in()) {
					SN_Helpers::send_json(false, 'وارد نشده‌اید');
					return;
				}
				$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$user = wp_get_current_user();
				if (! in_array('sn_supervisor', (array) $user->roles, true) && ! current_user_can('manage_options')) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
				$invoice_tab = sanitize_key($_POST['tab'] ?? 'all');
				global $wpdb;
				$seller_ids = [];
				if (current_user_can('manage_options')) {
					$seller_ids = array_map('intval', get_users(['role' => 'sn_seller', 'fields' => 'ID']));
				} else {
					$seller_ids = array_map('intval', get_users(['role' => 'sn_seller', 'fields' => 'ID', 'meta_key' => 'sn_supervisor_id', 'meta_value' => get_current_user_id()]));
					$lead_seller_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT seller_id FROM {$wpdb->prefix}sn_leads WHERE supervisor_id=%d AND seller_id IS NOT NULL", get_current_user_id()));
					$seller_ids = array_values(array_unique(array_merge($seller_ids, array_map('intval', $lead_seller_ids))));
				}
				if (! $seller_ids) {
					$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['items' => []]);
					return;
				}
				$ph = implode(',', array_fill(0, count($seller_ids), '%d'));
				$where = ["i.seller_id IN ($ph)"];
				$args = $seller_ids;
				if ($q !== '') {
					$like = '%' . $wpdb->esc_like($q) . '%';
					$where[] = '(i.invoice_code LIKE %s OR i.customer_name LIKE %s OR i.customer_phone LIKE %s)';
					array_push($args, $like, $like, $like);
				}
				if ($invoice_tab === 'pre_invoice') { $where[] = "i.status IN ('pre_invoice','pending')"; }
				elseif ($invoice_tab === 'online_paid') { $where[] = "i.pay_method IN ('online','gateway') AND i.status IN ('paid','approved')"; }
				elseif ($invoice_tab === 'receipt_uploaded') { $where[] = "(i.status IN ('receipt_uploaded','pending_financial_approval') OR i.payment_status IN ('receipt_uploaded','pending_financial_approval') OR i.invoice_status IN ('receipt_uploaded','pending_financial_approval'))"; }
				elseif ($invoice_tab === 'needs_review') { $where[] = "(i.status='pending_financial_approval' OR i.payment_status='pending_financial_approval' OR i.invoice_status='pending_financial_approval')"; }
				elseif ($invoice_tab === 'rejected') { $where[] = "i.status='rejected'"; }
				$sql = "SELECT i.*, u.display_name seller_name FROM {$wpdb->prefix}sn_invoices i LEFT JOIN {$wpdb->users} u ON u.ID=i.seller_id WHERE " . implode(' AND ', $where) . " ORDER BY i.id DESC LIMIT 50";
				$rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
				foreach ($rows as &$r) {
					$r['status_label'] = SN_Helpers::status_label((string) $r['status']);
					$r['pay_method_label'] = SN_Helpers::pay_method_label((string) $r['pay_method']);
					$r['payment_source_label'] = SN_Helpers::payment_source_label((string) $r['payment_source']);
				}
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['items' => $rows]);
			}
			public function render_financial_approval_page(): void
			{
				if (! $this->sn_can_finance()) {
					wp_die('دسترسی غیرمجاز');
				}
				$this->render_financial_approval_table(true);
			}

			public function ensure_finance_role(): void
			{
				if (! get_role('sn_financial_approval')) {
					add_role('sn_financial_approval', 'تایید مالی', [
						'read' => true,
						'sn_view_payments' => true,
						'sn_approve_payments' => true,
						'sn_reject_payments' => true,
					]);
				}
				if (! get_role('sn_financial')) {
					add_role('sn_financial', 'تایید مالی', [
						'read' => true,
						'sn_view_payments' => true,
						'sn_approve_payments' => true,
						'sn_reject_payments' => true,
					]);
				}
				foreach (['sn_financial_approval', 'sn_financial'] as $role_key) {
					$role = get_role($role_key);
					if (! $role) {
						continue;
					}
					foreach (['read', 'sn_view_payments', 'sn_approve_payments', 'sn_reject_payments'] as $cap) {
						$role->add_cap($cap);
					}
				}
			}


			// =========================================================
			// HELPER
			// =========================================================

			private function is_supervisor(): bool
			{
				$user = wp_get_current_user();
				return is_user_logged_in() && in_array('sn_supervisor', (array) $user->roles, true);
			}

			public function ensure_after_sales_role(): void
			{
				if (! get_role('sn_after_sales')) {
					add_role('sn_after_sales', 'خدمات پس از فروش', ['read' => true, 'sn_view_customer_profiles' => true]);
				}
				$role = get_role('sn_after_sales');
				if ($role) {
					$role->add_cap('read');
					$role->add_cap('sn_view_customer_profiles');
					foreach (['edit_posts', 'delete_posts', 'publish_posts', 'upload_files', 'edit_pages', 'delete_pages', 'manage_options', 'list_users', 'create_users', 'edit_users', 'delete_users'] as $cap) {
						$role->remove_cap($cap);
					}
				}
			}

			public function ensure_sales_manager_role(): void
			{
				if (! get_role('sn_sales_manager')) {
					add_role('sn_sales_manager', 'مدیر فروش', [
						'read' => true,
						'sn_view_sales_reports' => true,
						'sn_manage_supervisor_leads' => true,
						'sn_export_sales_reports' => true,
					]);
				}
				$role = get_role('sn_sales_manager');
				if ($role) {
					foreach (['read', 'sn_view_sales_reports', 'sn_manage_supervisor_leads', 'sn_export_sales_reports'] as $cap) {
						$role->add_cap($cap);
					}
					foreach (['edit_posts', 'delete_posts', 'publish_posts', 'upload_files', 'edit_pages', 'delete_pages', 'manage_options', 'list_users', 'create_users', 'edit_users', 'delete_users'] as $cap) {
						$role->remove_cap($cap);
					}
				}
			}

			private function sn_is_sales_manager(): bool
			{
				$user = wp_get_current_user();
				return is_user_logged_in() && in_array('sn_sales_manager', (array) $user->roles, true);
			}

			private function sn_can_manage_supervisor_leads(): bool
			{
				return current_user_can('manage_options') || current_user_can('sn_manage_supervisor_leads');
			}

			private function sn_can_view_customer_profiles(): bool
			{
				return current_user_can('manage_options') || current_user_can('sn_view_customer_profiles');
			}

			public function render_after_sales_panel(): string
			{
				if (! is_user_logged_in()) {
					return '<p class="sn-notice">لطفاً وارد شوید.</p>';
				}
				if (! $this->sn_can_view_customer_profiles()) {
					return '<p class="sn-notice sn-error">دسترسی غیرمجاز.</p>';
				}
				ob_start(); ?>
		<div class="sn-panel" dir="rtl" id="sn-after-sales-panel">
			<div class="sn-panel-header">
				<h2>پنل خدمات پس از فروش</h2>
				<p>مشاهده پروفایل کامل مشتری، Timeline، پیش‌فاکتورها، پرداخت‌ها، فیش‌ها و Activity Log</p>
			</div>
			<div class="sn-card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
				<label>جستجو<br><input type="search" id="sn-after-search" placeholder="شماره، نام، شهر یا یادداشت"></label>
				<button type="button" class="sn-btn sn-btn-primary" id="sn-after-search-btn">جستجو</button>
				<button type="button" class="sn-btn sn-btn-secondary" id="sn-after-show-all">نمایش همه</button>
			</div>
			<div id="sn-after-results" class="sn-card" style="margin-top:12px"></div>
			<div id="sn-after-profile-modal" class="sn-after-profile-modal" style="display:none" aria-hidden="true">
				<div class="sn-after-profile-backdrop" data-close="1"></div>
				<div class="sn-after-profile-dialog" role="dialog" aria-modal="true">
					<div class="sn-after-profile-head"><strong>پروفایل مشتری</strong><button type="button" class="sn-btn sn-btn-sm sn-after-close">بستن</button></div>
					<div id="sn-after-profile" class="sn-after-profile-body"></div>
				</div>
			</div>
		</div>
		<script>
			window.snAjax = window.snAjax || window.snData || {
				ajaxurl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
				nonce: <?php echo wp_json_encode(wp_create_nonce('sn_public')); ?>
			};
			window.snData = window.snData || window.snAjax;
			(function($) {
				function esc(v) {
					return $('<div>').text(v || '—').html();
				}

				function faStatus(v) {
					var m = {
						pre_invoice: 'پیش‌فاکتور',
						pending: 'در انتظار پرداخت',
						pending_payment: 'در انتظار پرداخت',
						receipt_uploaded: 'نیاز به بررسی فیش',
						pending_financial_approval: 'نیاز به بررسی فیش',
						approved: 'تایید شده',
						paid: 'پرداخت‌شده',
						rejected: 'رد شده',
						cancelled: 'لغوشده',
						assigned: 'تخصیص داده‌شده',
						unassigned: 'بدون تخصیص',
						supervisor_pool: 'در پنل سرپرست',
						invoiced: 'پیش‌فاکتور صادر شده',
						follow_up: 'پیگیری مجدد',
						no_answer: 'عدم پاسخگویی'
					};
					return m[v] || v || '—';
				}

				function faPay(v) {
					var m = {
						online: 'پرداخت آنلاین',
						card: 'کارت به کارت',
						customer_upload: 'ثبت توسط مشتری',
						supervisor_upload: 'ثبت توسط سرپرست'
					};
					return m[v] || v || '—';
				}

				function renderProfile(p) {
					var lead = p.lead || {}, wpUser = p.wp_user || null,
						html = '<h3>پروفایل مشتری / شماره ' + esc(lead.phone) + '</h3>';
					html += '<div class="sn-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">';
					html += '<div><b>شماره:</b> ' + esc(lead.phone) + '</div><div><b>استان/شهر:</b> ' + esc((lead.province || '') + ' / ' + (lead.city || '')) + '</div><div><b>وضعیت فعلی:</b> ' + esc(faStatus(lead.lead_status || lead.status)) + '</div><div><b>فروشنده:</b> ' + esc(p.seller) + '</div><div><b>سرپرست:</b> ' + esc(p.supervisor) + '</div><div><b>تاریخ ورود:</b> ' + esc(lead.imported_at) + '</div><div><b>تاریخ تخصیص:</b> ' + esc(lead.assigned_at) + '</div></div>';
					if (wpUser) {
						html += '<h4>اطلاعات کاربر سایت (فقط خواندنی)</h4><div class="sn-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">';
						html += '<div><b>نام کاربری:</b> '+esc(wpUser.user_login)+'</div><div><b>نام نمایشی:</b> '+esc(wpUser.display_name)+'</div><div><b>ایمیل:</b> '+esc(wpUser.user_email)+'</div><div><b>تاریخ عضویت:</b> '+esc(wpUser.user_registered)+'</div>';
						html += '</div>';
					}
					html += '<h4>پیش‌فاکتورها و پرداخت‌ها</h4><table class="sn-table"><thead><tr><th>کد</th><th>مشتری</th><th>مبلغ</th><th>پرداخت</th><th>وضعیت</th><th>فیش/اطلاعات</th><th>تاریخ</th></tr></thead><tbody>';
					(p.invoices || []).forEach(function(i) {
						var pay = (i.receipt_url ? '<a target="_blank" href="' + esc(i.receipt_url) + '">فیش</a>' : '') + ' ' + esc((i.manual_card_from || '') + ' ' + (i.manual_card_to || ''));
						html += '<tr><td>' + esc(i.invoice_code) + '</td><td>' + esc(i.customer_name) + '</td><td>' + esc(i.product_price) + '</td><td>' + esc(faPay(i.pay_method)) + ' / ' + esc(faPay(i.payment_source)) + '</td><td>' + esc(faStatus(i.status)) + '</td><td>' + pay + '</td><td>' + esc(i.created_at) + '</td></tr>';
					});
					html += '</tbody></table><h4>تاریخچه وضعیت</h4><ul>';
					(p.status_history || []).forEach(function(h) {
						html += '<li>' + esc(h.created_at) + ' — ' + esc(h.display_name) + ' : ' + esc(faStatus(h.old_status)) + ' ⟶ ' + esc(faStatus(h.new_status)) + '</li>';
					});
					html += '</ul><h4>Activity Log</h4><ul>';
					(p.activity || []).forEach(function(a) {
						html += '<li>' + esc(a.created_at) + ' — ' + esc(a.display_name) + ' — ' + esc(a.action) + ' — ' + esc(a.description) + '</li>';
					});
					html += '</ul>';
					$('#sn-after-profile').html(html);
					$('#sn-after-profile-modal').fadeIn(120).attr('aria-hidden','false');
				}
				$(document).on('click', '.sn-after-close, .sn-after-profile-backdrop', function(){ $('#sn-after-profile-modal').fadeOut(120).attr('aria-hidden','true'); });

				function loadAfterSales(q) {
					$('#sn-after-results').html('در حال جستجو...');
					$.post(snAjax.ajaxurl, {
						action: 'sn_customer_profile_search',
						nonce: snAjax.nonce,
						q: q || ''
					}, function(res) {
						if (!res || !res.success) {
							$('#sn-after-results').html('❌ ' + esc((res && res.message) || 'خطا در جستجو'));
							return;
						}
						var html = '<table class="sn-table"><thead><tr><th>شماره</th><th>نام مشتری</th><th>استان/شهر</th><th>وضعیت</th><th>فروشنده</th><th>عملیات</th></tr></thead><tbody>';
						if (!(res.items || []).length) {
							html += '<tr><td colspan="6">موردی یافت نشد.</td></tr>';
						}
						(res.items || []).forEach(function(l) {
							html += '<tr><td>' + esc(l.phone) + '</td><td>' + esc(l.customer_name) + '</td><td>' + esc((l.province || '') + ' / ' + (l.city || '')) + '</td><td>' + esc(faStatus(l.lead_status || l.status)) + '</td><td>' + esc(l.seller_name) + '</td><td><button type="button" class="sn-btn sn-view-lead-profile" data-id="' + l.id + '">مشاهده پروفایل</button></td></tr>';
						});
						html += '</tbody></table>';
						$('#sn-after-results').html(html);
					}).fail(function(xhr) {
						$('#sn-after-results').html('❌ خطای سرور: ' + xhr.status);
					});
				}
				$('#sn-after-search-btn').on('click', function() {
					loadAfterSales($('#sn-after-search').val());
				});
				$('#sn-after-show-all').on('click', function() {
					$('#sn-after-search').val('');
					loadAfterSales('');
				});
				$('#sn-after-search').on('keydown', function(e) {
					if (e.key === 'Enter') {
						e.preventDefault();
						loadAfterSales($(this).val());
					}
				});
				$(document).on('click', '.sn-view-lead-profile', function() {
					$.post(snAjax.ajaxurl, {
						action: 'sn_lead_profile',
						nonce: snAjax.nonce,
						lead_id: $(this).data('id')
					}, function(res) {
						if (res.success) renderProfile(res);
						else alert((res && res.message) || 'خطا');
					}).fail(function(xhr) {
						alert('خطای سرور: ' + xhr.status);
					});
				});
				loadAfterSales('');
			})(jQuery);
		</script>
	<?php return ob_get_clean();
			}

			public function render_admin_customer_profiles(): void
			{
				global $wpdb;
				$q = sanitize_text_field(wp_unslash($_GET['sn_q'] ?? ''));
				$where = '1=1';
				$args = [];
				// وقتی جستجو خالی است، کارتابل خدمات پس از فروش فقط موارد route شده به این پنل را نشان می‌دهد؛ با جستجو، امکان یافتن همه پرونده‌ها حفظ می‌شود.
				if (false && $q === '') {
					$where .= " AND (l.destination_panel='after_sales' OR EXISTS (SELECT 1 FROM {$wpdb->prefix}sn_lead_statuses st WHERE st.label=l.lead_status AND st.destination_panel='after_sales' AND st.move_to_destination=1 AND st.is_active=1))";
				}
				if ($q !== '') {
					$like = '%' . $wpdb->esc_like($q) . '%';
					$where .= ' AND (l.phone LIKE %s OR l.city LIKE %s OR l.province LIKE %s OR l.note LIKE %s OR i.customer_name LIKE %s)';
					$args = [$like, $like, $like, $like, $like];
				}
				$sql = "SELECT l.*, MAX(i.customer_name) customer_name, MAX(u.display_name) seller_name FROM {$wpdb->prefix}sn_leads l LEFT JOIN {$wpdb->prefix}sn_invoices i ON i.lead_id=l.id LEFT JOIN {$wpdb->users} u ON u.ID=l.seller_id WHERE {$where} GROUP BY l.id ORDER BY l.id DESC LIMIT 300";
				$rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
				echo '<div class="wrap sn-admin" dir="rtl"><h1>پروفایل مشتری‌ها</h1><p>برای مشاهده Timeline کامل توسط خدمات پس از فروش، از صفحه «پنل خدمات پس از فروش» استفاده شود.</p><form method="get" style="margin:12px 0"><input type="hidden" name="page" value="sn-customer-profiles"><input type="search" name="sn_q" value="' . esc_attr($q) . '" placeholder="شماره، نام، شهر، یادداشت"><button class="button button-primary">جستجو</button></form><table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>شماره</th><th>نام مشتری</th><th>استان/شهر</th><th>وضعیت</th><th>فروشنده</th><th>آخرین بروزرسانی</th></tr></thead><tbody>';
				foreach ($rows as $r) {
					echo '<tr><td>' . (int)$r['id'] . '</td><td>' . esc_html($r['phone']) . '</td><td>' . esc_html($r['customer_name'] ?: '—') . '</td><td>' . esc_html(trim(($r['province'] ?: '') . ' / ' . ($r['city'] ?: ''), ' /') ?: '—') . '</td><td>' . esc_html(SN_Helpers::status_label((string)($r['lead_status'] ?: $r['status']))) . '</td><td>' . esc_html($r['seller_name'] ?: '—') . '</td><td>' . esc_html($r['updated_at']) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}

			public function ajax_customer_profile_search(): void
			{
				if (! is_user_logged_in() || ! $this->sn_can_view_customer_profiles()) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				global $wpdb;
				$q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
				$where = '1=1';
				$args = [];
				// وقتی جستجو خالی است، کارتابل خدمات پس از فروش فقط موارد route شده به این پنل را نشان می‌دهد؛ با جستجو، امکان یافتن همه پرونده‌ها حفظ می‌شود.
				if (false && $q === '') {
					$where .= " AND (l.destination_panel='after_sales' OR EXISTS (SELECT 1 FROM {$wpdb->prefix}sn_lead_statuses st WHERE st.label=l.lead_status AND st.destination_panel='after_sales' AND st.move_to_destination=1 AND st.is_active=1))";
				}
				if ($q !== '') {
					$like = '%' . $wpdb->esc_like($q) . '%';
					$where .= ' AND (l.phone LIKE %s OR l.city LIKE %s OR l.province LIKE %s OR l.note LIKE %s OR i.customer_name LIKE %s)';
					$args = [$like, $like, $like, $like, $like];
				}
				$sql = "SELECT l.id,l.phone,l.province,l.city,l.status,l.lead_status,l.updated_at,MAX(i.customer_name) customer_name,MAX(u.display_name) seller_name FROM {$wpdb->prefix}sn_leads l LEFT JOIN {$wpdb->prefix}sn_invoices i ON i.lead_id=l.id LEFT JOIN {$wpdb->users} u ON u.ID=l.seller_id WHERE {$where} GROUP BY l.id ORDER BY l.updated_at DESC,l.id DESC LIMIT 80";
				$items = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['items' => $items]);
			}

			public function ajax_seller_profile(): void
			{
				if (! is_user_logged_in()) {
					SN_Helpers::send_json(false, 'وارد نشده‌اید');
					return;
				}
				$valid = check_ajax_referer('sn_public', 'nonce', false) || check_ajax_referer('sn_admin', 'nonce', false);
				if (! $valid) {
					SN_Helpers::send_json(false, 'نانس نامعتبر');
					return;
				}
				$user = wp_get_current_user();
				$seller_id = absint($_POST['seller_id'] ?? 0);
				if (! $seller_id) {
					SN_Helpers::send_json(false, 'فروشنده نامعتبر است');
					return;
				}
				if (! current_user_can('manage_options') && in_array('sn_supervisor', (array)$user->roles, true) && (int)get_user_meta($seller_id, 'sn_supervisor_id', true) !== (int)$user->ID) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$s = get_user_by('id', $seller_id);
				if (!$s) {
					SN_Helpers::send_json(false, 'فروشنده یافت نشد');
					return;
				}
				global $wpdb;
				$stats = [
					'leads' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE seller_id=%d", $seller_id)),
					'invoices' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d", $seller_id)),
					'paid' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d AND (status IN ('paid','approved') OR payment_status='approved' OR invoice_status='approved')", $seller_id)),
					'revenue' => (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(COALESCE(final_total,product_price,0)),0) FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d AND (status IN ('paid','approved') OR payment_status='approved' OR invoice_status='approved')", $seller_id)),
				];
				$recent_leads = $wpdb->get_results($wpdb->prepare("SELECT id,phone,province,city,lead_status,status,assigned_at,updated_at FROM {$wpdb->prefix}sn_leads WHERE seller_id=%d ORDER BY updated_at DESC LIMIT 50", $seller_id), ARRAY_A);
				$recent_invoices = $wpdb->get_results($wpdb->prepare("SELECT id,invoice_code,customer_name,customer_phone,product_price,final_total,status,created_at FROM {$wpdb->prefix}sn_invoices WHERE seller_id=%d ORDER BY id DESC LIMIT 50", $seller_id), ARRAY_A);
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['seller' => ['id' => $s->ID, 'name' => $s->display_name, 'phone' => $s->user_login, 'registered' => $s->user_registered], 'stats' => $stats, 'recent_leads' => $recent_leads, 'recent_invoices' => $recent_invoices]);
			}
			// =========================================================
			// WALLET / COMMISSION MODULE
			// =========================================================
			public function ensure_wallet_tables(): void
			{
				global $wpdb;
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				$charset = $wpdb->get_charset_collate();
				dbDelta("CREATE TABLE {$wpdb->prefix}sn_wallets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			balance DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_debit DECIMAL(18,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_wallet (user_id, wallet_type),
			KEY user_id (user_id),
			KEY wallet_type (wallet_type)
		) {$charset};");
				dbDelta("CREATE TABLE {$wpdb->prefix}sn_wallet_transactions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wallet_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			direction VARCHAR(10) NOT NULL DEFAULT 'credit',
			type VARCHAR(60) NOT NULL DEFAULT 'commission',
			status VARCHAR(30) NOT NULL DEFAULT 'approved',
			description TEXT DEFAULT NULL,
			meta LONGTEXT DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY wallet_id (wallet_id),
			KEY user_id (user_id),
			KEY invoice_id (invoice_id),
			KEY type (type),
			KEY created_at (created_at)
		) {$charset};");
			}

			private function sn_wallet_id(int $user_id, string $wallet_type = 'seller'): int
			{
				global $wpdb;
				$this->ensure_wallet_tables();
				$table = $wpdb->prefix . 'sn_wallets';
				$id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d AND wallet_type=%s", $user_id, $wallet_type));
				if ($id) {
					return $id;
				}
				$wpdb->insert($table, ['user_id' => $user_id, 'wallet_type' => $wallet_type, 'balance' => 0, 'total_credit' => 0, 'total_debit' => 0]);
				return (int) $wpdb->insert_id;
			}

			private function sn_add_wallet_transaction(int $user_id, string $wallet_type, float $amount, string $direction, string $type, string $description = '', ?int $invoice_id = null, ?int $lead_id = null, array $meta = []): bool
			{
				if ($user_id <= 0 || $amount <= 0) {
					return false;
				}
				global $wpdb;
				$wallet_id = $this->sn_wallet_id($user_id, $wallet_type);
				if (($invoice_id && in_array($type, ['seller_commission', 'supervisor_commission','hr_commission'], true)) || ($type==='hr_salary' && !empty($meta['period_key']))) {
					if($type==='hr_salary'){ $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_wallet_transactions WHERE user_id=%d AND wallet_type=%s AND type=%s AND period_key=%s LIMIT 1", $user_id, $wallet_type, $type, (string)($meta['period_key']??''))); } else { $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d AND user_id=%d AND wallet_type=%s AND type=%s LIMIT 1", $invoice_id, $user_id, $wallet_type, $type)); }
					if ($exists) {
						return false;
					}
				}
				$wpdb->insert($wpdb->prefix . 'sn_wallet_transactions', [
					'wallet_id' => $wallet_id,
					'user_id' => $user_id,
					'wallet_type' => $wallet_type,
					'invoice_id' => $invoice_id,
					'lead_id' => $lead_id,
					'amount' => $amount,
					'direction' => $direction === 'debit' ? 'debit' : 'credit',
					'type' => sanitize_key($type),
					'status' => 'approved',
					'description' => $description,
					'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
				'source_type' => $meta['source_type'] ?? null,
				'source_id' => $meta['source_id'] ?? null,
				'period_key' => $meta['period_key'] ?? null,
				'calculation_snapshot' => $meta['calculation_snapshot'] ?? null,
					'created_by' => get_current_user_id() ?: null,
				]);
				if ($wpdb->insert_id) {
					$sign = $direction === 'debit' ? -1 : 1;
					$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_wallets SET balance = balance + %f, total_credit = total_credit + %f, total_debit = total_debit + %f WHERE id=%d", $sign * $amount, $direction === 'debit' ? 0 : $amount, $direction === 'debit' ? $amount : 0, $wallet_id));
					$this->sn_log_activity($invoice_id, $lead_id, 'wallet_transaction', 'ثبت تراکنش کیف پول: ' . $description, ['user_id' => $user_id, 'wallet_type' => $wallet_type, 'amount' => $amount, 'direction' => $direction, 'type' => $type]);
					return true;
				}
				return false;
			}

			private function sn_commission_amount(float $base, string $role): float
			{
				$type = get_option("sn_{$role}_commission_type", 'percent');
				$value = (float) get_option("sn_{$role}_commission_value", 0);
				if ($value <= 0 || $base <= 0) {
					return 0;
				}
				return $type === 'fixed' ? $value : round(($base * $value) / 100, 2);
			}

			private function sn_credit_wallet_for_invoice(int $invoice_id, $invoice): void
			{
				if (get_option('sn_wallet_auto_credit', '1') !== '1') {
					return;
				}
				global $wpdb;
				$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $invoice_id));
				if (! $inv) {
					$inv = is_object($invoice) ? $invoice : (object) $invoice;
				}
				$seller_id = (int) ($inv->seller_id ?? 0);
				$lead_id = (int) ($inv->lead_id ?? 0);
				$amount = (float) (($inv->final_total ?? 0) ?: ($inv->product_price ?? 0));
				if (! $seller_id || $amount <= 0) {
					return;
				}
				$seller_comm = $this->sn_commission_amount($amount, 'seller');
				if ($seller_comm > 0) {
					$this->sn_add_wallet_transaction($seller_id, 'seller', $seller_comm, 'credit', 'seller_commission', 'پورسانت فروشنده بابت فاکتور ' . ($inv->invoice_code ?? $invoice_id), $invoice_id, $lead_id, ['base_amount' => $amount, 'pay_method' => (string)($inv->pay_method ?? ''), 'payment_source' => (string)($inv->payment_source ?? '')]);
				}
				$supervisor_id = 0;
				if ($lead_id) {
					$supervisor_id = (int) $wpdb->get_var($wpdb->prepare("SELECT supervisor_id FROM {$wpdb->prefix}sn_leads WHERE id=%d", $lead_id));
				}
				if (! $supervisor_id) {
					$supervisor_id = (int) get_user_meta($seller_id, 'sn_supervisor_id', true);
				}
				$supervisor_comm = $this->sn_commission_amount($amount, 'supervisor');
				if ($supervisor_id && $supervisor_comm > 0) {
					$this->sn_add_wallet_transaction($supervisor_id, 'supervisor', $supervisor_comm, 'credit', 'supervisor_commission', 'پورسانت سرپرست بابت فاکتور ' . ($inv->invoice_code ?? $invoice_id), $invoice_id, $lead_id, ['seller_id' => $seller_id, 'base_amount' => $amount, 'pay_method' => (string)($inv->pay_method ?? ''), 'payment_source' => (string)($inv->payment_source ?? '')]);
				}
			}

			private function sn_wallet_summary(int $user_id, string $wallet_type): array
			{
				global $wpdb;
				$this->ensure_wallet_tables();
				$wallet_id = $this->sn_wallet_id($user_id, $wallet_type);
				$w = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_wallets WHERE id=%d", $wallet_id), ARRAY_A);
				$tx = $wpdb->get_results($wpdb->prepare("SELECT wt.*, i.pay_method AS invoice_pay_method, i.payment_source AS invoice_payment_source FROM {$wpdb->prefix}sn_wallet_transactions wt LEFT JOIN {$wpdb->prefix}sn_invoices i ON i.id=wt.invoice_id WHERE wt.wallet_id=%d ORDER BY wt.id DESC LIMIT 50", $wallet_id), ARRAY_A);
				return ['wallet' => $w ?: [], 'transactions' => $tx ?: []];
			}

			public function render_wallet_box_for_user(int $user_id, string $wallet_type = 'seller'): string
			{
				if (! $user_id) {
					return '<div class="sn-card">کاربر نامعتبر است.</div>';
				}
				$data = $this->sn_wallet_summary($user_id, $wallet_type);
				$w = $data['wallet'];
				ob_start();
	?>
		<div class="sn-card sn-wallet-box" dir="rtl">
			<h3><?php echo esc_html($wallet_type === 'supervisor' ? 'کیف پول سرپرست' : 'کیف پول فروشنده'); ?></h3>
			<div class="sn-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
				<div class="sn-stat"><strong>موجودی قابل تسویه</strong><br><span><?php echo esc_html(SN_Helpers::format_price((float) ($w['balance'] ?? 0))); ?></span></div>
				<div class="sn-stat"><strong>کل بستانکاری</strong><br><span><?php echo esc_html(SN_Helpers::format_price((float) ($w['total_credit'] ?? 0))); ?></span></div>
				<div class="sn-stat"><strong>کل برداشت/تسویه</strong><br><span><?php echo esc_html(SN_Helpers::format_price((float) ($w['total_debit'] ?? 0))); ?></span></div>
			</div>
			<h4>آخرین تراکنش‌ها</h4>
			<table class="sn-table">
				<thead>
					<tr>
						<th>تاریخ</th>
						<th>نوع</th>
						<th>مبلغ</th>
						<th>شرح</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($data['transactions'])) : ?><tr>
							<td colspan="4">تراکنشی ثبت نشده است.</td>
						</tr><?php endif; ?>
					<?php foreach ($data['transactions'] as $t) : $tx_meta=json_decode((string)($t['meta'] ?? '{}'), true); $pay_method=(string)($t['invoice_pay_method'] ?: ($tx_meta['pay_method'] ?? '')); ?>
						<tr data-payment-method="<?php echo esc_attr($pay_method); ?>">
							<td><?php echo esc_html(SN_Helpers::gregorian_to_jalali_date($t['created_at'])); ?></td>
							<td><?php echo esc_html($t['direction'] === 'debit' ? 'بدهکار/تسویه' : 'بستانکار'); ?><?php if ($pay_method) : ?><br><small><?php echo esc_html(SN_Helpers::pay_method_label($pay_method)); ?></small><?php endif; ?></td>
							<td><?php echo esc_html(SN_Helpers::format_price((float) $t['amount'])); ?></td>
							<td><?php echo esc_html($t['description']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php
				return ob_get_clean();
			}

			public function render_admin_wallets(): void
			{
				$this->ensure_wallet_tables();
				global $wpdb;
				$role = sanitize_text_field($_GET['sn_wallet_role'] ?? '');
				$user_q = sanitize_text_field($_GET['sn_wallet_user'] ?? '');
				$where = '1=1';
				$args = [];
				if ($role === 'seller' || $role === 'supervisor') {
					$where .= ' AND w.wallet_type=%s';
					$args[] = $role;
				}
				if ($user_q !== '') {
					$where .= ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
					$like = '%' . $wpdb->esc_like($user_q) . '%';
					$args = array_merge($args, [$like, $like, $like]);
				}
				$sql = "SELECT w.*, u.display_name, u.user_login FROM {$wpdb->prefix}sn_wallets w LEFT JOIN {$wpdb->users} u ON u.ID=w.user_id WHERE {$where} ORDER BY w.updated_at DESC LIMIT 300";
				$wallets = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
				$nonce = wp_create_nonce('sn_admin');
	?>
		<div class="wrap sn-admin" dir="rtl">
			<h1>کیف پول و پورسانت</h1>
			<p>پورسانت‌ها بر اساس فاکتورهای پرداخت‌شده/تاییدشده محاسبه می‌شوند. تنظیم درصد و مبلغ ثابت در صفحه تنظیمات انجام می‌شود.</p>
			<form method="get" style="display:flex;gap:8px;align-items:end;margin:12px 0;flex-wrap:wrap">
				<input type="hidden" name="page" value="sn-wallets">
				<label>نوع کیف پول<br><select name="sn_wallet_role">
						<option value="">همه</option>
						<option value="seller" <?php selected($role, 'seller'); ?>>فروشنده</option>
						<option value="supervisor" <?php selected($role, 'supervisor'); ?>>سرپرست</option>
					</select></label>
				<label>جستجوی کاربر<br><input type="search" name="sn_wallet_user" value="<?php echo esc_attr($user_q); ?>" placeholder="نام، موبایل، ایمیل"></label>
				<button class="button button-primary">فیلتر</button>
			</form>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>کاربر</th>
						<th>نوع</th>
						<th>موجودی</th>
						<th>کل بستانکاری</th>
						<th>کل تسویه/برداشت</th>
						<th>آخرین بروزرسانی</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($wallets as $w) : ?>
						<tr>
							<td><?php echo esc_html(($w['display_name'] ?: $w['user_login']) . ' #' . $w['user_id']); ?></td>
							<td><?php echo esc_html($w['wallet_type'] === 'supervisor' ? 'سرپرست' : 'فروشنده'); ?></td>
							<td><strong><?php echo esc_html(SN_Helpers::format_price((float) $w['balance'])); ?></strong></td>
							<td><?php echo esc_html(SN_Helpers::format_price((float) $w['total_credit'])); ?></td>
							<td><?php echo esc_html(SN_Helpers::format_price((float) $w['total_debit'])); ?></td>
							<td><?php echo esc_html($w['updated_at']); ?></td>
							<td><button type="button" class="button sn-wallet-adjust" data-user="<?php echo (int)$w['user_id']; ?>" data-type="<?php echo esc_attr($w['wallet_type']); ?>">اصلاح دستی/تسویه</button></td>
						</tr>
					<?php endforeach; ?>
					<?php if (empty($wallets)) : ?><tr>
							<td colspan="7">کیف پولی یافت نشد. با پرداخت فاکتور یا محاسبه مجدد، کیف پول‌ها ایجاد می‌شوند.</td>
						</tr><?php endif; ?>
				</tbody>
			</table>
			<p style="margin-top:14px"><button type="button" class="button button-secondary" id="sn-wallet-recalculate">محاسبه مجدد پورسانت فاکتورهای پرداخت‌شده</button></p>
		</div>
		<script>
			jQuery(function($) {
				$('.sn-wallet-adjust').on('click', function() {
					var amount = prompt('مبلغ تراکنش را وارد کنید');
					if (!amount) return;
					var direction = prompt('نوع تراکنش: credit برای افزایش / debit برای کاهش یا تسویه', 'debit');
					var desc = prompt('شرح تراکنش', direction === 'credit' ? 'اصلاح دستی موجودی' : 'تسویه کیف پول');
					$.post(ajaxurl, {
						action: 'sn_wallet_manual_adjust',
						nonce: '<?php echo esc_js($nonce); ?>',
						user_id: $(this).data('user'),
						wallet_type: $(this).data('type'),
						amount: amount,
						direction: direction,
						description: desc
					}, function(r) {
						alert(r.message || (r.success ? 'انجام شد' : 'خطا'));
						if (r.success) location.reload();
					});
				});
				$('#sn-wallet-recalculate').on('click', function() {
					if (!confirm('پورسانت فاکتورهای پرداخت‌شده که قبلاً تراکنش ندارند محاسبه شود؟')) return;
					$(this).prop('disabled', true).text('در حال محاسبه...');
					$.post(ajaxurl, {
						action: 'sn_wallet_recalculate',
						nonce: '<?php echo esc_js($nonce); ?>'
					}, function(r) {
						alert(r.message || 'انجام شد');
						location.reload();
					});
				});
			});
		</script>
<?php
			}

			public function ajax_wallet_manual_adjust(): void
			{
				if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				$user_id = absint($_POST['user_id'] ?? 0);
				$wallet_type = sanitize_key($_POST['wallet_type'] ?? 'seller');
				$amount = abs((float) ($_POST['amount'] ?? 0));
				$direction = sanitize_key($_POST['direction'] ?? 'debit');
				$desc = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
				if (! $user_id || $amount <= 0) {
					SN_Helpers::send_json(false, 'اطلاعات ناقص است');
					return;
				}
				$this->sn_add_wallet_transaction($user_id, $wallet_type, $amount, $direction === 'credit' ? 'credit' : 'debit', $direction === 'credit' ? 'manual_credit' : 'settlement', $desc ?: 'تراکنش دستی کیف پول', null, null, ['manual' => true]);
				SN_Helpers::send_json(true, 'تراکنش کیف پول ثبت شد');
			}

			public function ajax_wallet_recalculate(): void
			{
				if (! current_user_can('manage_options') || ! check_ajax_referer('sn_admin', 'nonce', false)) {
					SN_Helpers::send_json(false, 'دسترسی غیرمجاز');
					return;
				}
				global $wpdb;
				$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE status IN ('paid','approved') ORDER BY id ASC LIMIT 2000");
				$count = 0;
				foreach ($rows as $inv) {
					$before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d", (int)$inv->id));
					$this->sn_credit_wallet_for_invoice((int) $inv->id, $inv);
					$after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d", (int)$inv->id));
					if ($after > $before) {
						$count += ($after - $before);
					}
				}
				SN_Helpers::send_json(true, 'محاسبه مجدد انجام شد. تعداد تراکنش جدید: ' . $count);
			}

			/** Lightweight workflow additions for v1.0.7. */
			private function sn_customer_action_labels(): array
			{
				return [
					'invoice_viewed' => 'مشاهده لینک فاکتور',
					'product_info_viewed' => 'مطالعه اطلاعات محصول',
					'lottery_info_viewed' => 'مشاهده شانس قرعه‌کشی',
					'wheel_opened' => 'باز کردن گردونه',
					'wheel_spun' => 'چرخاندن گردونه',
					'reward_applied' => 'استفاده از جایزه',
					'reward_declined' => 'عدم استفاده از جایزه',
					'coupon_opened' => 'باز کردن کد تخفیف',
					'coupon_applied' => 'اعمال کد تخفیف',
					'coupon_removed' => 'لغو کد تخفیف',
					'recontact_opened' => 'باز کردن ارتباط مجدد',
					'recontact_requested' => 'درخواست ارتباط مجدد',
					'pay_online_clicked' => 'انتخاب پرداخت آنلاین',
					'card_payment_selected' => 'انتخاب کارت‌به‌کارت',
					'receipt_upload_clicked' => 'انتخاب آپلود فیش',
					'receipt_uploaded' => 'آپلود فیش',
					'manual_payment_form_opened' => 'باز کردن فرم اطلاعات واریزی',
					'manual_payment_submitted' => 'ثبت اطلاعات واریزی',
				];
			}

			private function sn_store_customer_action(string $code, string $action, string $label = '', string $extra_raw = ''): bool
			{
				$invoice = SN_Helpers::get_invoice_by_code($code);
				if (! $invoice) { return false; }
				$allowed = $this->sn_customer_action_labels();
				if (! isset($allowed[$action])) { $action = 'customer_action'; }
				$description = $allowed[$action] ?? ($label ?: 'اکشن مشتری در صفحه فاکتور');
				$extra = [];
				if (is_string($extra_raw) && $extra_raw !== '') {
					$decoded = json_decode($extra_raw, true);
					if (is_array($decoded)) { $extra = array_slice($decoded, 0, 12, true); }
				}
				$this->sn_log_activity((int)$invoice->id, (int)$invoice->lead_id, 'customer_' . sanitize_key($action), $description, array_merge($extra, [
					'invoice_code' => $code,
					'customer_phone' => (string)($invoice->customer_phone ?? ''),
					'label' => mb_substr($label, 0, 80),
				]));
				return true;
			}

			public function ajax_invoice_customer_action(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$action = sanitize_key(wp_unslash($_POST['event'] ?? 'customer_action'));
				$label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
				$extra_raw = wp_unslash($_POST['extra'] ?? '');
				if ($code === '') { SN_Helpers::send_json(false, 'کد فاکتور نامعتبر است'); return; }
				$this->sn_store_customer_action($code, $action, $label, is_string($extra_raw) ? $extra_raw : '');
				SN_Helpers::send_json(true, 'ثبت شد');
			}

			public function ajax_invoice_customer_actions_batch(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$raw = wp_unslash($_POST['events'] ?? '[]');
				$events = json_decode(is_string($raw) ? $raw : '[]', true);
				if ($code === '' || ! is_array($events)) { SN_Helpers::send_json(false, 'داده نامعتبر است'); return; }
				$count = 0;
				foreach (array_slice($events, 0, 10) as $e) {
					if (! is_array($e)) { continue; }
					$event = sanitize_key((string)($e['event'] ?? 'customer_action'));
					$label = sanitize_text_field((string)($e['label'] ?? ''));
					$extra = isset($e['extra']) && is_array($e['extra']) ? wp_json_encode(array_slice($e['extra'], 0, 12, true), JSON_UNESCAPED_UNICODE) : '';
					if ($this->sn_store_customer_action($code, $event, $label, $extra ?: '')) { $count++; }
				}
				SN_Helpers::send_json(true, 'ثبت شد', ['count' => $count]);
			}

			public function ajax_seller_customer_actions(): void
			{
				if (! is_user_logged_in() || ! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return; }
				$user = wp_get_current_user();
				if (! in_array('sn_seller', (array)$user->roles, true) && ! current_user_can('manage_options')) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return; }
				global $wpdb;
				$page = max(1, absint($_POST['page'] ?? 1));
				$limit = min(30, max(10, absint($_POST['limit'] ?? 20)));
				$offset = ($page - 1) * $limit;
				$detail_invoice_id = absint($_POST['invoice_id'] ?? 0);

				if ($detail_invoice_id > 0) {
					$owned = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->prefix}sn_invoices WHERE id=%d AND seller_id=%d", $detail_invoice_id, $user->ID));
					if (! $owned && ! current_user_can('manage_options')) { SN_Helpers::send_json(false, 'فاکتور متعلق به شما نیست'); return; }
					$rows = $wpdb->get_results($wpdb->prepare(
						"SELECT a.id,a.invoice_id,a.lead_id,a.action,a.description,a.context,a.created_at,i.invoice_code,i.customer_name,i.customer_phone,l.phone
						 FROM {$wpdb->prefix}sn_activity_logs a
						 INNER JOIN {$wpdb->prefix}sn_invoices i ON i.id=a.invoice_id
						 LEFT JOIN {$wpdb->prefix}sn_leads l ON l.id=a.lead_id
						 WHERE a.invoice_id=%d AND a.action LIKE 'customer_%%'
						 ORDER BY a.id DESC LIMIT 200",
						$detail_invoice_id
					), ARRAY_A);
					foreach ($rows as &$r) {
						$r['created_at_jalali'] = SN_Helpers::gregorian_to_jalali_date($r['created_at'] ?? '');
					}
					$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['items' => $rows ?: [], 'invoice_id' => $detail_invoice_id]);
					return;
				}

				$where = current_user_can('manage_options') ? '1=1' : 'i.seller_id=%d';
				$params = current_user_can('manage_options') ? [] : [$user->ID];
				$total = (int) $wpdb->get_var($params ? $wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->prefix}sn_invoices i WHERE {$where}", ...$params) : "SELECT COUNT(1) FROM {$wpdb->prefix}sn_invoices i WHERE {$where}");
				$params2 = array_merge($params, [$limit, $offset]);
				// 1.0.24 performance: avoid multiple correlated subqueries per invoice row.
				$sql = "SELECT i.id invoice_id,i.invoice_code,i.customer_name,i.customer_phone,
						la.description latest_action,
						la.action latest_action_key,
						la.created_at latest_at,
						COALESCE(ac.action_count,0) action_count
					FROM {$wpdb->prefix}sn_invoices i
					LEFT JOIN (
						SELECT invoice_id, MAX(id) latest_id, COUNT(1) action_count
						FROM {$wpdb->prefix}sn_activity_logs
						WHERE action LIKE 'customer_%%'
						GROUP BY invoice_id
					) ac ON ac.invoice_id=i.id
					LEFT JOIN {$wpdb->prefix}sn_activity_logs la ON la.id=ac.latest_id
					WHERE {$where}
					ORDER BY COALESCE(ac.latest_id,0) DESC, i.id DESC
					LIMIT %d OFFSET %d";
				$rows = $wpdb->get_results($wpdb->prepare($sql, ...$params2), ARRAY_A);
				foreach ($rows as &$r) {
					$r['latest_at_jalali'] = ! empty($r['latest_at']) ? SN_Helpers::gregorian_to_jalali_date($r['latest_at']) : '';
					if (empty($r['latest_action'])) { $r['latest_action'] = 'هیچ فعالیتی ثبت نشده'; }
					$r['customer_phone'] = $r['customer_phone'] ?: '';
					$r['customer_name'] = $r['customer_name'] ?: '—';
				}
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['items' => $rows ?: [], 'total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => ($offset + $limit) < $total]);
			}

			public function ajax_invoice_recontact(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
				$invoice = SN_Helpers::get_invoice_by_code($code);
				if (! $invoice || in_array((string) $invoice->status, ['paid','approved'], true)) { SN_Helpers::send_json(false, 'این فاکتور قابل ارجاع مجدد نیست'); return; }
				$this->maybe_create_tables();
				global $wpdb;
				$wpdb->update($wpdb->prefix . 'sn_invoices', $this->sn_filter_existing_columns($wpdb->prefix . 'sn_invoices', [
					'status' => 'recontact_requested',
					'invoice_status' => 'recontact_requested',
					'recontact_requested_at' => current_time('mysql'),
					'recontact_note' => $note,
					'updated_at' => current_time('mysql'),
				]), ['id' => (int) $invoice->id]);
				$this->sn_log_activity((int) $invoice->id, (int) $invoice->lead_id, 'invoice_recontact_requested', 'درخواست ارتباط مجدد با کارشناس توسط مشتری', ['note' => $note]);
				self::write_invoice_workflow_log_static((int)$invoice->id, ['lead_id'=>(int)$invoice->lead_id,'from_status'=>(string)$invoice->status,'to_status'=>'recontact_requested','action_type'=>'seller_to_supervisor','note'=>$note]);
				SN_Helpers::send_json(true, 'فاکتور شما به کارشناس مربوطه ارجاع داده شد. منتظر تماس کارشناس باشید.', ['status' => 'recontact_requested']);
			}

			public function ajax_seller_resend_financial(): void
			{
				if (! is_user_logged_in() || ! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return; }
				$user = wp_get_current_user();
				if (! in_array('sn_seller', (array) $user->roles, true) && ! current_user_can('manage_options')) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return; }
				$id = absint($_POST['invoice_id'] ?? 0);
				$note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
				global $wpdb;
				$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id));
				if (! $inv || ((int) $inv->seller_id !== (int) $user->ID && ! current_user_can('manage_options'))) { SN_Helpers::send_json(false, 'فاکتور متعلق به شما نیست'); return; }
				if ((string) $inv->status !== 'rejected') { SN_Helpers::send_json(false, 'فقط فاکتور رد شده قابل ارجاع مجدد است'); return; }
				$wpdb->update($wpdb->prefix . 'sn_invoices', $this->sn_filter_existing_columns($wpdb->prefix . 'sn_invoices', [
					'status' => 'pending_financial_approval',
					'invoice_status' => 'pending_financial_approval',
					'payment_status' => 'pending_financial_approval',
					'resend_to_financial_at' => current_time('mysql'),
					'financial_return_state' => 'resent_after_return',
					'resent_after_return_at' => current_time('mysql'),
					'updated_at' => current_time('mysql'),
				]), ['id' => $id]);
				$this->sn_log_activity($id, (int) $inv->lead_id, 'seller_resend_to_financial', 'ارجاع مجدد فاکتور رد شده به تایید مالی', ['note' => $note]);
				self::write_invoice_workflow_log_static($id, ['lead_id'=>(int)$inv->lead_id,'from_status'=>(string)$inv->status,'to_status'=>'pending_financial_approval','action_type'=>'seller_resubmit_finance','note'=>$note]);
				SN_Helpers::send_json(true, 'فاکتور دوباره به تایید مالی ارسال شد');
			}

			public function ajax_financial_invoices(): void
			{
				if (! $this->sn_can_finance()) { SN_Helpers::send_json(false, 'دسترسی غیرمجاز'); return; }
				$valid = check_ajax_referer('sn_admin', 'nonce', false) || check_ajax_referer('sn_public', 'nonce', false);
				if (! $valid) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$this->maybe_create_tables();
				$tab = sanitize_key($_POST['tab'] ?? 'needs_review');
				$page = max(1, absint($_POST['page'] ?? 1));
				$limit = min(50, max(10, absint($_POST['limit'] ?? 30)));
				$offset = ($page - 1) * $limit;
				global $wpdb;
				$defs = $this->sn_stats_definitions();
				$needs = str_replace(['status','payment_status','invoice_status'], ['i.status','i.payment_status','i.invoice_status'], $defs['payment_pending_review']);
				$approved = str_replace(['status','payment_status','invoice_status','financial_return_state'], ['i.status','i.payment_status','i.invoice_status','i.financial_return_state'], $defs['payment_financial_approved']);
				$rejected = str_replace(['status','payment_status','invoice_status','financial_return_state'], ['i.status','i.payment_status','i.invoice_status','i.financial_return_state'], $defs['payment_financial_rejected']);
				$onlinePaid = "(i.pay_method IN ('online','gateway') AND i.status IN ('paid','approved'))";
				if ($tab === 'online_paid') { $where = $onlinePaid; }
				elseif ($tab === 'approved') { $where = $approved; }
				elseif ($tab === 'rejected') { $where = $rejected; }
				else { $where = $needs; }
				$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices i WHERE {$where}");
				$sum = (float) $wpdb->get_var("SELECT COALESCE(SUM(COALESCE(i.final_total,i.product_price,0)),0) FROM {$wpdb->prefix}sn_invoices i WHERE {$where}");
				$rows = $wpdb->get_results($wpdb->prepare("SELECT i.*, p.uploaded_by_type latest_uploaded_by_type, p.uploaded_by_user_id latest_uploaded_by_user_id FROM {$wpdb->prefix}sn_invoices i LEFT JOIN {$wpdb->prefix}sn_payments p ON p.id=(SELECT p2.id FROM {$wpdb->prefix}sn_payments p2 WHERE p2.invoice_id=i.id ORDER BY p2.id DESC LIMIT 1) WHERE {$where} ORDER BY i.updated_at DESC, i.id DESC LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
				foreach ($rows as &$r) {
					$source = (string) ($r['payment_source'] ?: ($r['receipt_source'] ?? '') ?: ($r['latest_uploaded_by_type'] ?? ''));
					$r['status_label'] = SN_Helpers::status_label((string) $r['status']);
					$r['pay_method_label'] = SN_Helpers::pay_method_label((string) $r['pay_method']);
					$r['payment_source_label'] = SN_Helpers::payment_source_label($source);
					$receipt_url = (string)($r['receipt_url'] ?? ($r['receipt_file'] ?? ''));
					$manual_from = (string)($r['manual_card_from'] ?? ($r['deposit_card_from_last4'] ?? ''));
					$manual_to = (string)($r['manual_card_to'] ?? ($r['deposit_card_to_last4'] ?? ''));
					$manual_amount = (string)($r['manual_amount'] ?? ($r['deposit_amount'] ?? ''));
					$manual_jalali = (string)($r['manual_paid_at_jalali'] ?? ($r['deposit_jalali_datetime'] ?? ''));
					$r['receipt_url'] = $receipt_url;
					$r['payment_info_html'] = $receipt_url ? ('<a target="_blank" href="' . esc_url($receipt_url) . '">مشاهده فیش</a>') : esc_html(trim($manual_from . ' → ' . $manual_to . ' / ' . $manual_amount . ' / ' . $manual_jalali));
					$r['payment_info_text'] = $receipt_url ? 'فیش بارگذاری شده' : trim($manual_from . ' → ' . $manual_to . ' / ' . $manual_amount . ' / ' . $manual_jalali);
					$effective_status = (string)($r['payment_status'] ?: ($r['invoice_status'] ?: $r['status']));
					$r['can_review'] = (bool) in_array($effective_status, ['pending_financial_approval','receipt_uploaded'], true) || (bool) preg_match('/pending_financial_approval|receipt_uploaded/', (string)$r['status'] . ' ' . (string)($r['payment_status'] ?? '') . ' ' . (string)($r['invoice_status'] ?? ''));
					$return_state = (string)($r['financial_return_state'] ?? '');
					$r['financial_return_label'] = $return_state === 'resent_after_return' ? 'برگشتی و ارسال مجدد فروشنده' : ($return_state === 'returned_to_seller' ? 'برگشت به فروشنده' : '');
					$r['wc_order_id'] = (int)($r['wc_order_id'] ?? 0);
					$r['wc_order_label'] = !empty($r['wc_order_id']) ? ('سفارش ووکامرس #' . (int)$r['wc_order_id']) : '';
					$r['amount_fmt'] = SN_Helpers::format_price((float) ($r['final_total'] ?: $r['product_price']));
					$r['product_name'] = $this->sn_invoice_items_label((int)$r['id'], (int)$r['product_id']);
				}
				$kpi = [
					'count' => $total,
					'amount' => $sum,
					'amount_fmt' => SN_Helpers::format_price($sum),
					'needs_review' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices i WHERE {$needs}"),
					'online_paid' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices i WHERE {$onlinePaid}"),
					'approved' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices i WHERE {$approved}"),
					'rejected' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices i WHERE {$rejected}"),
				];
				$timeline = $this->sn_get_invoice_logs((int) $invoice->id);
		SN_Helpers::send_json(true, '', ['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total, 'kpi' => $kpi]);
			}


			private function sn_maybe_send_payment_sms($invoice, string $payment_type): void
			{
				// پیامک پرداخت اختیاری است؛ اگر پترن تنظیم نشده باشد هیچ رفتاری از سیستم فعلی تغییر نمی‌کند.
				$payment_type = $payment_type === 'card_to_card' ? 'card_to_card' : 'online';
				$pattern = $payment_type === 'online' ? get_option('sn_faraz_pattern_online_payment', '') : get_option('sn_faraz_pattern_card_payment', '');
				if (! $pattern || ! class_exists('SN_SMS')) { return; }
				$phone = (string)($invoice->customer_phone ?? '');
				if (! $phone) { return; }
				$label = $payment_type === 'online' ? 'online' : 'card_to_card';
				try {
					$sms = new SN_SMS();
					if (method_exists($sms, 'send_faraz_pattern')) {
						$sms->send_faraz_pattern($phone, $pattern, [
							'customer_name' => (string)($invoice->customer_name ?? ''),
							'invoice_code' => (string)($invoice->invoice_code ?? ''),
							'amount' => (string) preg_replace('/[^0-9]/', '', SN_Helpers::to_english_nums(SN_Helpers::format_price((float)(($invoice->final_total ?? 0) ?: ($invoice->product_price ?? 0))))),
							'payment_type' => $label,
						]);
					}
				} catch (\Throwable $e) {
					$this->sn_log_activity((int)($invoice->id ?? 0), (int)($invoice->lead_id ?? 0), 'payment_sms_failed', 'خطا در ارسال پیامک پرداخت: ' . $e->getMessage());
				}
			}


			private function sn_get_lucky_wheels(): array
			{
				$wheels = get_option('sn_lucky_wheels', []);
				return is_array($wheels) ? $wheels : [];
			}

			private function sn_pick_weighted_wheel_segment(array $segments): array
			{
				$total = 0.0;
				foreach ($segments as $s) { $total += max(0, (float)($s['chance'] ?? 0)); }
				if ($total <= 0) { return $segments[0] ?? []; }
				$rand = (wp_rand(1, 1000000) / 1000000) * $total;
				$cursor = 0.0;
				foreach ($segments as $s) {
					$cursor += max(0, (float)($s['chance'] ?? 0));
					if ($rand <= $cursor) { return $s; }
				}
				return end($segments) ?: [];
			}

			public function ajax_spin_invoice_wheel(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$apply = sanitize_key($_POST['apply'] ?? 'spin_only');
				$invoice = SN_Helpers::get_invoice_by_code($code);
				if (! $invoice || in_array((string) $invoice->status, ['paid','approved'], true)) { SN_Helpers::send_json(false, 'گردونه برای این فاکتور فعال نیست'); return; }
				$this->maybe_create_tables(); global $wpdb;
				$existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_wheel WHERE invoice_id=%d ORDER BY id DESC LIMIT 1", (int) $invoice->id));
				if ($existing) {
					$payload = json_decode((string) $existing->reward_payload, true);
					SN_Helpers::send_json(true, 'شما قبلاً از گردونه استفاده کرده‌اید.', [
						'reward_type'=>$existing->reward_type,
						'reward_value'=>$existing->reward_value,
						'summary'=>(string) $invoice->wheel_reward_summary,
						'used_discount'=>(int)$existing->used_discount,
						'payload'=>is_array($payload)?$payload:[],
					]);
					return;
				}
				$items = $wpdb->get_results($wpdb->prepare("SELECT product_id FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d", (int)$invoice->id));
				if (! $items) { $items = [(object)['product_id'=>(int)$invoice->product_id]]; }
				$eligible_wheel=false; $eligible_discount=false; $wheel_id='';
				foreach ($items as $it) {
					$pid=(int)$it->product_id;
					if (get_post_meta($pid, '_sn_has_lucky_wheel', true)==='1') { $eligible_wheel=true; if (!$wheel_id) { $wheel_id=(string)get_post_meta($pid, '_sn_wheel_id', true); } }
					if (get_post_meta($pid, '_sn_has_discount_coupon', true)==='1') { $eligible_discount=true; }
				}
				if (! $eligible_wheel) { SN_Helpers::send_json(false, 'برای محصولات این فاکتور گردونه تعریف نشده است'); return; }
				$wheels = $this->sn_get_lucky_wheels();
				$wheel = ($wheel_id && isset($wheels[$wheel_id])) ? $wheels[$wheel_id] : (is_array($wheels) ? reset($wheels) : null);
				$segment = $wheel && !empty($wheel['segments']) ? $this->sn_pick_weighted_wheel_segment((array)$wheel['segments']) : [];
				if ($segment) {
					$reward_type = sanitize_key($segment['type'] ?? 'discount_coupon');
					$reward_value = (string)($segment['value'] ?? '');
					$summary = sanitize_text_field($segment['label'] ?? 'جایزه گردونه');
					$free_product_id = absint($segment['product_id'] ?? 0);
					$discount_percent = $reward_type === 'discount_coupon' ? max(0, min(100, (float)$reward_value)) : 0;
				} else {
					$discount_percent = 0;
					$free_product_id = 0;
					$reward_type = 'empty_reward';
					$reward_value = '';
					$summary = 'بدون جایزه';
				}
				if (! in_array($reward_type, ['discount_coupon','free_product','empty_reward','text'], true)) { $reward_type = 'text'; }
				$payload = ['wheel_id'=>$wheel_id,'wheel_title'=>$wheel['title'] ?? '','segment'=>$segment,'discount_percent'=>$discount_percent,'free_product_id'=>$free_product_id];
				$wpdb->insert($wpdb->prefix . 'sn_invoice_wheel', ['invoice_id'=>(int)$invoice->id,'customer_id'=>(int)($invoice->customer_wp_id ?? 0),'reward_type'=>$reward_type,'reward_value'=>$reward_value,'reward_payload'=>wp_json_encode($payload, JSON_UNESCAPED_UNICODE),'used_discount'=>0,'created_at'=>current_time('mysql')]);
				$wpdb->update($wpdb->prefix.'sn_invoices', $this->sn_filter_existing_columns($wpdb->prefix.'sn_invoices', ['wheel_reward_summary'=>$summary,'updated_at'=>current_time('mysql')]), ['id'=>(int)$invoice->id]);
				if ($apply === 'yes' && in_array($reward_type, ['discount_coupon','free_product'], true)) {
					$_POST['invoice_code'] = $code;
					$this->ajax_apply_invoice_wheel_reward();
					return;
				}
				SN_Helpers::send_json(true, 'نتیجه گردونه: ' . $summary, ['reward_type'=>$reward_type,'reward_value'=>$reward_value,'summary'=>$summary,'discount_percent'=>$discount_percent,'free_product_id'=>$free_product_id]);
			}


			public function ajax_apply_invoice_wheel_reward(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$invoice = SN_Helpers::get_invoice_by_code($code);
				if (! $invoice || in_array((string)$invoice->status, ['paid','approved'], true)) { SN_Helpers::send_json(false, 'فاکتور قابل تغییر نیست'); return; }
				global $wpdb;
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_wheel WHERE invoice_id=%d ORDER BY id DESC LIMIT 1", (int)$invoice->id));
				if (! $row) { SN_Helpers::send_json(false, 'ابتدا گردونه را بچرخانید'); return; }
				if ((int)$row->used_discount === 1) { SN_Helpers::send_json(false, 'این جایزه قبلاً اعمال شده است'); return; }
				$payload = json_decode((string)$row->reward_payload, true); if (!is_array($payload)) { $payload=[]; }
				$summary = (string)($invoice->wheel_reward_summary ?: 'جایزه گردونه');
				$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d ORDER BY id ASC", (int)$invoice->id), ARRAY_A);
				if (! $items) { $items = [['product_id'=>(int)$invoice->product_id,'qty'=>1,'unit_price'=>(float)$invoice->product_price,'total_price'=>(float)$invoice->product_price,'is_free'=>0]]; }
				$base_total = 0.0;
				foreach ($items as $it) { if (empty($it['is_free'])) { $base_total += (float)($it['total_price'] ?? 0); } }
				$current_discount = (float)($invoice->discount_amount ?? 0) + (float)($invoice->coupon_discount_amount ?? 0);
				$extra_discount = 0.0;
				if ($row->reward_type === 'discount_coupon') {
					$percent = max(0, min(100, (float)($payload['discount_percent'] ?? $row->reward_value)));
					$extra_discount = round(max(0, $base_total - $current_discount) * $percent / 100, 2);
					$summary = $summary . ' - اعمال شد';
				} elseif ($row->reward_type === 'free_product') {
					$free_product_id = absint($payload['free_product_id'] ?? $row->reward_value);
					if (!$free_product_id) { $free_product_id = absint(get_option('sn_wheel_free_product_id', 0)); }
					if ($free_product_id && function_exists('wc_get_product')) {
						$fp = wc_get_product($free_product_id);
						if ($fp) {
							$already = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d AND product_id=%d AND is_free=1", (int)$invoice->id, $free_product_id));
							if (! $already) {
								$wpdb->insert($wpdb->prefix . 'sn_invoice_items', ['invoice_id'=>(int)$invoice->id,'product_id'=>$free_product_id,'product_name'=>$fp->get_name(),'qty'=>1,'unit_price'=>0,'total_price'=>0,'is_free'=>1,'created_at'=>current_time('mysql')]);
							}
							$summary = 'محصول رایگان: ' . $fp->get_name();
						}
					}
				} else {
					$summary = 'بدون جایزه';
				}
				$new_discount_amount = (float)($invoice->discount_amount ?? 0) + $extra_discount;
				$discount_total = $new_discount_amount + (float)($invoice->coupon_discount_amount ?? 0);
				$final_total = max(0, $base_total - $discount_total);
				$update = [
					'discount_amount' => $new_discount_amount,
					'original_total' => $base_total,
					'discount_total' => $discount_total,
					'final_total' => $final_total,
					'wheel_reward_summary' => $summary,
					'updated_at'=>current_time('mysql'),
				];
				$wpdb->update($wpdb->prefix.'sn_invoices', $this->sn_filter_existing_columns($wpdb->prefix.'sn_invoices', $update), ['id'=>(int)$invoice->id]);
				$wpdb->update($wpdb->prefix.'sn_invoice_wheel', ['used_discount'=>1], ['id'=>(int)$row->id]);
				SN_Helpers::send_json(true, 'جایزه روی فاکتور اعمال شد', ['summary'=>$summary,'final_total'=>$final_total,'discount_total'=>$discount_total]);
			}


			public function ajax_apply_invoice_coupon(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$coupon_code = sanitize_text_field(wp_unslash($_POST['coupon_code'] ?? ''));
				$invoice = SN_Helpers::get_invoice_by_code($code);
				if (! $invoice || in_array((string)$invoice->status, ['paid','approved'], true)) { SN_Helpers::send_json(false, 'فاکتور قابل تغییر نیست'); return; }
				if ($coupon_code === '') { SN_Helpers::send_json(false, 'کد تخفیف را وارد کنید'); return; }
				if (! function_exists('wc_get_coupon_id_by_code') || ! class_exists('WC_Coupon')) { SN_Helpers::send_json(false, 'ووکامرس فعال نیست'); return; }
				$coupon_id = wc_get_coupon_id_by_code($coupon_code);
				if (! $coupon_id) { SN_Helpers::send_json(false, 'کد تخفیف معتبر نیست'); return; }
				$coupon = new WC_Coupon($coupon_id);
				if (method_exists($coupon, 'get_date_expires') && $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < time()) { SN_Helpers::send_json(false, 'تاریخ اعتبار کد تخفیف تمام شده است'); return; }
				if (method_exists($coupon, 'get_usage_limit') && $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit()) { SN_Helpers::send_json(false, 'ظرفیت استفاده از این کد تخفیف تمام شده است'); return; }
				global $wpdb;
				$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d", (int)$invoice->id), ARRAY_A);
				if (!$items) { $items = [['product_id'=>(int)$invoice->product_id,'total_price'=>(float)$invoice->product_price,'qty'=>1,'is_free'=>0]]; }
				$allow_on_sale = get_option('sn_coupon_allow_on_sale', '0') === '1';
				$base = 0.0; $has_sale = false; $eligible = false;
				$product_ids = array_map('absint', (array) $coupon->get_product_ids());
				$excluded_ids = array_map('absint', (array) $coupon->get_excluded_product_ids());
				foreach ($items as $it) {
					if (! empty($it['is_free'])) { continue; }
					$pid = (int)($it['product_id'] ?? 0);
					if ($pid && in_array($pid, $excluded_ids, true)) { continue; }
					if (! empty($product_ids) && ! in_array($pid, $product_ids, true)) { continue; }
					$p = $pid && function_exists('wc_get_product') ? wc_get_product($pid) : null;
					if ($p && $p->is_on_sale()) { $has_sale = true; }
					$base += (float)($it['total_price'] ?? 0);
					$eligible = true;
				}
				if (! $eligible || $base <= 0) { SN_Helpers::send_json(false, 'این کد تخفیف برای محصولات این فاکتور قابل استفاده نیست'); return; }
				if ($has_sale && ! $allow_on_sale) { SN_Helpers::send_json(false, 'این کد تخفیف برای محصولات دارای تخفیف ویژه قابل استفاده نیست'); return; }
				if ($coupon->get_minimum_amount() && $base < (float)$coupon->get_minimum_amount()) { SN_Helpers::send_json(false, 'مبلغ فاکتور برای استفاده از این کد کافی نیست'); return; }
				if ($coupon->get_maximum_amount() && $base > (float)$coupon->get_maximum_amount()) { SN_Helpers::send_json(false, 'این کد برای مبلغ این فاکتور قابل استفاده نیست'); return; }
				$type = $coupon->get_discount_type(); $amount = (float)$coupon->get_amount();
				$discount = in_array($type, ['percent','recurring_percent'], true) ? round($base * $amount / 100, 2) : min($base, $amount);
				if ($discount <= 0) { SN_Helpers::send_json(false, 'مبلغ تخفیف قابل اعمال نیست'); return; }
				$other_discount = (float)($invoice->discount_amount ?? 0);
				$discount_total = $other_discount + $discount;
				$final_total = max(0, $base - $discount_total);
				$wpdb->update($wpdb->prefix.'sn_invoices', $this->sn_filter_existing_columns($wpdb->prefix.'sn_invoices', [
					'coupon_code'=>$coupon_code,
					'coupon_discount_amount'=>$discount,
					'original_total'=>$base,
					'discount_total'=>$discount_total,
					'final_total'=>$final_total,
					'updated_at'=>current_time('mysql'),
				]), ['id'=>(int)$invoice->id]);
				SN_Helpers::send_json(true, 'کد تخفیف اعمال شد', ['discount_amount'=>$discount,'final_total'=>$final_total,'discount_total'=>$discount_total]);
			}

			public function ajax_remove_invoice_coupon(): void
			{
				if (! check_ajax_referer('sn_public', 'nonce', false)) { SN_Helpers::send_json(false, 'نانس نامعتبر'); return; }
				$code = sanitize_text_field(wp_unslash($_POST['invoice_code'] ?? ''));
				$invoice = SN_Helpers::get_invoice_by_code($code);
				if (! $invoice || in_array((string)$invoice->status, ['paid','approved'], true)) { SN_Helpers::send_json(false, 'فاکتور قابل تغییر نیست'); return; }
				global $wpdb;
				$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d", (int)$invoice->id), ARRAY_A);
				if (!$items) { $items = [['product_id'=>(int)$invoice->product_id,'total_price'=>(float)$invoice->product_price,'qty'=>1,'is_free'=>0]]; }
				$base = 0.0; $original_total = 0.0;
				foreach ($items as $it) {
					if (! empty($it['is_free'])) { continue; }
					$pid = (int)($it['product_id'] ?? 0);
					$qty = max(1, (int)($it['qty'] ?? 1));
					$product = $pid && function_exists('wc_get_product') ? wc_get_product($pid) : null;
					$regular = $product ? (float)$product->get_regular_price() : (float)($it['unit_price'] ?? 0);
					$base += (float)($it['total_price'] ?? 0);
					$original_total += $regular > 0 ? ($regular * $qty) : (float)($it['total_price'] ?? 0);
				}
				$other_discount = (float)($invoice->discount_amount ?? 0);
				$final_total = max(0, $base - $other_discount);
				$wpdb->update($wpdb->prefix.'sn_invoices', $this->sn_filter_existing_columns($wpdb->prefix.'sn_invoices', [
					'coupon_code'=>null,
					'coupon_discount_amount'=>0,
					'original_total'=>$original_total,
					'discount_total'=>$other_discount,
					'final_total'=>$final_total,
					'updated_at'=>current_time('mysql'),
				]), ['id'=>(int)$invoice->id]);
				SN_Helpers::send_json(true, 'کد تخفیف لغو شد', ['final_total'=>$final_total,'discount_total'=>$other_discount]);
			}



			// =========================================================
			// REPORTS / KPI STABILITY MODULE
			// =========================================================
			private function sn_table_exists(string $table): bool
			{
				global $wpdb;
				return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
			}

			private function sn_report_types(): array
			{
				return [
					'leads' => 'شماره‌ها / لیدها',
					'invoices' => 'پیش‌فاکتورها و فاکتورها',
					'financial' => 'مالی و پرداخت‌ها',
					'wallet' => 'کیف پول و پورسانت',
					'customer_actions' => 'رفتار مشتریان',
					'after_sales' => 'خدمات پس از فروش / پرونده‌های مقصد',
					'sellers' => 'فروشندگان',
					'supervisors' => 'سرپرست‌ها',
				];
			}

			private function sn_report_params(array $src = []): array
			{
				return [
					'type' => sanitize_key($src['sn_report_type'] ?? 'invoices'),
					'q' => sanitize_text_field(wp_unslash($src['sn_report_q'] ?? '')),
					'status' => sanitize_text_field(wp_unslash($src['sn_report_status'] ?? '')),
					'from' => sanitize_text_field(wp_unslash($src['sn_report_from'] ?? '')),
					'to' => sanitize_text_field(wp_unslash($src['sn_report_to'] ?? '')),
					'user_id' => absint($src['sn_report_user_id'] ?? 0),
					'limit' => min(200, max(20, absint($src['sn_report_limit'] ?? 50))),
				];
			}

			private function sn_report_date_where(string $field, array $params, array &$args): string
			{
				$where = '';
				if (! empty($params['from'])) {
					$where .= " AND {$field} >= %s";
					$args[] = $params['from'] . ' 00:00:00';
				}
				if (! empty($params['to'])) {
					$where .= " AND {$field} <= %s";
					$args[] = $params['to'] . ' 23:59:59';
				}
				return $where;
			}

			private function sn_report_dataset(array $params): array
			{
				global $wpdb;
				$type = array_key_exists($params['type'], $this->sn_report_types()) ? $params['type'] : 'invoices';
				$limit = (int) $params['limit'];
				$rows = [];
				$summary = [];
				$headers = [];
				$args = [];
				$q = $params['q'];
				$status = $params['status'];
				$user_id = (int) $params['user_id'];

				if ($type === 'leads') {
					$table = $wpdb->prefix . 'sn_leads';
					if (! $this->sn_table_exists($table)) { return ['headers'=>[], 'rows'=>[], 'summary'=>['خطا'=>'جدول شماره‌ها وجود ندارد']]; }
					$where = '1=1';
					if ($q !== '') { $where .= ' AND (phone LIKE %s OR city LIKE %s OR province LIKE %s OR import_code LIKE %s)'; $like = '%' . $wpdb->esc_like($q) . '%'; array_push($args, $like, $like, $like, $like); }
					if ($status !== '') { $where .= ' AND (status=%s OR lead_status=%s)'; array_push($args, $status, $status); }
					if ($user_id) { $where .= ' AND (seller_id=%d OR supervisor_id=%d)'; array_push($args, $user_id, $user_id); }
					$where .= $this->sn_report_date_where('COALESCE(updated_at, imported_at)', $params, $args);
					$sql = "SELECT id, phone, province, city, status, lead_status, seller_id, supervisor_id, imported_at, assigned_at, updated_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d";
					$args2 = array_merge($args, [$limit]);
					$raw = $wpdb->get_results($wpdb->prepare($sql, ...$args2), ARRAY_A) ?: [];
					foreach ($raw as $r) { $seller = !empty($r['seller_id']) ? get_user_by('id', (int)$r['seller_id']) : null; $supervisor = !empty($r['supervisor_id']) ? get_user_by('id', (int)$r['supervisor_id']) : null; $rows[] = ['ID'=>$r['id'], 'شماره'=>$r['phone'], 'استان'=>$r['province'], 'شهر'=>$r['city'], 'وضعیت سیستمی'=>SN_Helpers::status_label((string)$r['status']), 'وضعیت تماس'=>$r['lead_status'], 'فروشنده'=>$seller?$seller->display_name:'', 'سرپرست'=>$supervisor?$supervisor->display_name:'', 'بروزرسانی'=>SN_Helpers::gregorian_to_jalali_date($r['updated_at'] ?: $r['imported_at'])]; }
					$total = (int) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$args)) : $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}"));
					$summary = ['تعداد کل مطابق فیلتر'=>number_format_i18n($total)];
				}

				if ($type === 'invoices' || $type === 'financial') {
					$table = $wpdb->prefix . 'sn_invoices';
					if (! $this->sn_table_exists($table)) { return ['headers'=>[], 'rows'=>[], 'summary'=>['خطا'=>'جدول فاکتورها وجود ندارد']]; }
					$where = '1=1';
					if ($q !== '') { $where .= ' AND (invoice_code LIKE %s OR customer_phone LIKE %s OR customer_name LIKE %s)'; $like = '%' . $wpdb->esc_like($q) . '%'; array_push($args, $like, $like, $like); }
					if ($status !== '') { $where .= ' AND (status=%s OR payment_status=%s OR invoice_status=%s)'; array_push($args, $status, $status, $status); }
					if ($user_id) { $where .= ' AND (seller_id=%d OR supervisor_id=%d)'; array_push($args, $user_id, $user_id); }
					if ($type === 'financial') { $where .= " AND (pay_method IN ('online','gateway','card_to_card','card') OR payment_status IS NOT NULL OR receipt_url IS NOT NULL OR receipt_file IS NOT NULL)"; }
					$where .= $this->sn_report_date_where('created_at', $params, $args);
					$sql = "SELECT id, invoice_code, customer_name, customer_phone, product_id, product_price, original_total, discount_total, final_total, pay_method, status, payment_status, invoice_status, seller_id, supervisor_id, created_at, paid_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d";
					$raw = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($args, [$limit])), ARRAY_A) ?: [];
					foreach ($raw as $r) { $seller = !empty($r['seller_id']) ? get_user_by('id', (int)$r['seller_id']) : null; $amount = (float)($r['final_total'] ?: $r['product_price']); $rows[] = ['ID'=>$r['id'], 'شماره فاکتور'=>$r['invoice_code'], 'مشتری'=>$r['customer_name'], 'موبایل'=>$r['customer_phone'], 'محصول'=>$this->sn_invoice_items_label((int)$r['id'], (int)$r['product_id']), 'مبلغ نهایی'=>SN_Helpers::format_price($amount), 'تخفیف'=>SN_Helpers::format_price((float)($r['discount_total'] ?: 0)), 'روش پرداخت'=>SN_Helpers::pay_method_label((string)$r['pay_method']), 'وضعیت'=>SN_Helpers::status_label((string)($r['payment_status'] ?: $r['invoice_status'] ?: $r['status'])), 'فروشنده'=>$seller?$seller->display_name:'', 'تاریخ'=>SN_Helpers::gregorian_to_jalali_date($r['created_at'])]; }
					$count = (int) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$args)) : $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}"));
					$sum = (float) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(COALESCE(final_total, product_price,0)),0) FROM {$table} WHERE {$where}", ...$args)) : $wpdb->get_var("SELECT COALESCE(SUM(COALESCE(final_total, product_price,0)),0) FROM {$table} WHERE {$where}"));
					$summary = ['تعداد'=>number_format_i18n($count), 'جمع مبلغ نهایی'=>SN_Helpers::format_price($sum)];
				}

				if ($type === 'wallet') {
					$table = $wpdb->prefix . 'sn_wallet_transactions';
					if (! $this->sn_table_exists($table)) { return ['headers'=>[], 'rows'=>[], 'summary'=>['خطا'=>'جدول کیف پول وجود ندارد']]; }
					$where = '1=1';
					if ($user_id) { $where .= ' AND user_id=%d'; $args[] = $user_id; }
					if ($status !== '') { $where .= ' AND source=%s'; $args[] = $status; }
					$where .= $this->sn_report_date_where('created_at', $params, $args);
					$raw = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d", ...array_merge($args, [$limit])), ARRAY_A) ?: [];
					foreach ($raw as $r) { $u = !empty($r['user_id']) ? get_user_by('id', (int)$r['user_id']) : null; $rows[] = ['ID'=>$r['id'], 'کاربر'=>$u?$u->display_name:$r['user_id'], 'فاکتور'=>$r['invoice_id'] ?? '', 'مبلغ'=>SN_Helpers::format_price((float)$r['amount']), 'نوع'=>$r['type'] ?? '', 'منبع'=>$r['source'] ?? '', 'توضیح'=>$r['description'] ?? '', 'تاریخ'=>SN_Helpers::gregorian_to_jalali_date($r['created_at'] ?? '')]; }
					$sum = (float) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE {$where}", ...$args)) : $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE {$where}"));
					$summary = ['جمع تراکنش‌ها'=>SN_Helpers::format_price($sum)];
				}

				if ($type === 'customer_actions') {
					$table = $wpdb->prefix . 'sn_activity_logs';
					if (! $this->sn_table_exists($table)) { return ['headers'=>[], 'rows'=>[], 'summary'=>['خطا'=>'جدول لاگ وجود ندارد']]; }
					$labels = $this->sn_customer_action_labels();
					$where = "action LIKE 'customer_%'";
					if ($q !== '') { $where .= ' AND (description LIKE %s OR context LIKE %s)'; $like = '%' . $wpdb->esc_like($q) . '%'; array_push($args, $like, $like); }
					$where .= $this->sn_report_date_where('created_at', $params, $args);
					$raw = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d", ...array_merge($args, [$limit])), ARRAY_A) ?: [];
					foreach ($raw as $r) { $rows[] = ['ID'=>$r['id'], 'فاکتور'=>$r['invoice_id'], 'مشتری/لید'=>$r['lead_id'], 'فعالیت'=>$labels[$r['action']] ?? $r['description'] ?? $r['action'], 'تاریخ'=>SN_Helpers::gregorian_to_jalali_date($r['created_at'])]; }
					$summary = ['تعداد فعالیت‌ها'=>number_format_i18n(count($rows))];
				}

				if ($type === 'after_sales') {
					$table = $wpdb->prefix . 'sn_leads';
					if (! $this->sn_table_exists($table)) { return ['headers'=>[], 'rows'=>[], 'summary'=>['خطا'=>'جدول شماره‌ها وجود ندارد']]; }
					$where = "(destination_panel='after_sales' OR destination_panel IS NOT NULL)";
					if ($status !== '') { $where .= ' AND lead_status=%s'; $args[] = $status; }
					if ($q !== '') { $where .= ' AND (phone LIKE %s OR city LIKE %s OR province LIKE %s)'; $like = '%' . $wpdb->esc_like($q) . '%'; array_push($args, $like, $like, $like); }
					$raw = $wpdb->get_results($wpdb->prepare("SELECT id, phone, province, city, lead_status, destination_panel, destination_routed_at, seller_id FROM {$table} WHERE {$where} ORDER BY COALESCE(destination_routed_at, updated_at, imported_at) DESC LIMIT %d", ...array_merge($args, [$limit])), ARRAY_A) ?: [];
					foreach ($raw as $r) { $seller = !empty($r['seller_id']) ? get_user_by('id', (int)$r['seller_id']) : null; $rows[] = ['ID'=>$r['id'], 'شماره'=>$r['phone'], 'استان'=>$r['province'], 'شهر'=>$r['city'], 'وضعیت'=>$r['lead_status'], 'مقصد'=>$r['destination_panel'], 'فروشنده'=>$seller?$seller->display_name:'', 'تاریخ ارجاع'=>SN_Helpers::gregorian_to_jalali_date($r['destination_routed_at'])]; }
					$summary = ['تعداد پرونده‌ها'=>number_format_i18n(count($rows))];
				}

				if ($type === 'sellers' || $type === 'supervisors') {
					$role = $type === 'sellers' ? 'sn_seller' : 'sn_supervisor';
					$users = get_users(['role'=>$role, 'number'=>$limit, 'search'=> $q ? '*' . $q . '*' : '', 'search_columns'=>['user_login','display_name','user_email']]);
					foreach ($users as $u) { $lead_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_leads WHERE " . ($type === 'sellers' ? 'seller_id' : 'supervisor_id') . "=%d", $u->ID)); $inv_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE " . ($type === 'sellers' ? 'seller_id' : 'supervisor_id') . "=%d", $u->ID)); $rows[] = ['ID'=>$u->ID, 'نام'=>$u->display_name, 'موبایل/نام کاربری'=>$u->user_login, 'ایمیل'=>$u->user_email, 'تعداد لید'=>$lead_count, 'تعداد فاکتور'=>$inv_count, 'ثبت‌نام'=>SN_Helpers::gregorian_to_jalali_date($u->user_registered)]; }
					$summary = ['تعداد نمایش داده شده'=>number_format_i18n(count($rows))];
				}

				if (! empty($rows)) { $headers = array_keys($rows[0]); }
				return ['headers'=>$headers, 'rows'=>$rows, 'summary'=>$summary, 'type'=>$type];
			}

			public function render_admin_reports(): void
			{
				if (! current_user_can('manage_options') && ! current_user_can('sn_view_sales_reports')) { wp_die('دسترسی غیرمجاز'); }
				$params = $this->sn_report_params($_GET);
				$types = $this->sn_report_types();
				$data = $this->sn_report_dataset($params);
				$export_url = wp_nonce_url(add_query_arg(array_merge($_GET, ['sn_export'=>'custom_report']), admin_url('admin.php?page=sn-reports')), 'sn_export_custom_report');
				?>
				<div class="wrap sn-admin" dir="rtl">
					<h1>گزارش‌گیری جامع شبکه فروش</h1>
					<form method="get" class="sn-card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;margin:12px 0">
						<input type="hidden" name="page" value="sn-reports">
						<label>بخش گزارش<br><select name="sn_report_type"><?php foreach ($types as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($params['type'],$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
						<label>جستجو<br><input type="text" name="sn_report_q" value="<?php echo esc_attr($params['q']); ?>" placeholder="شماره، نام، کد فاکتور..."></label>
						<label>وضعیت/منبع<br><input type="text" name="sn_report_status" value="<?php echo esc_attr($params['status']); ?>" placeholder="paid / rejected / ..."></label>
						<label>کاربر ID<br><input type="number" name="sn_report_user_id" value="<?php echo esc_attr($params['user_id']); ?>"></label>
						<label>از تاریخ میلادی<br><input type="date" name="sn_report_from" value="<?php echo esc_attr($params['from']); ?>"></label>
						<label>تا تاریخ میلادی<br><input type="date" name="sn_report_to" value="<?php echo esc_attr($params['to']); ?>"></label>
						<label>تعداد نمایش<br><input type="number" min="20" max="200" name="sn_report_limit" value="<?php echo esc_attr($params['limit']); ?>"></label>
						<div><button class="button button-primary">نمایش گزارش</button> <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">خروجی CSV</a></div>
					</form>
					<div class="sn-stats-grid" style="margin:14px 0"><?php foreach (($data['summary'] ?? []) as $k=>$v): ?><div class="sn-stat"><span><?php echo esc_html((string)$v); ?></span><label><?php echo esc_html((string)$k); ?></label></div><?php endforeach; ?></div>
					<div class="sn-card" style="overflow:auto"><table class="widefat striped"><thead><tr><?php foreach (($data['headers'] ?? []) as $h): ?><th><?php echo esc_html($h); ?></th><?php endforeach; ?></tr></thead><tbody><?php if (empty($data['rows'])): ?><tr><td colspan="20">داده‌ای برای این فیلتر پیدا نشد.</td></tr><?php else: foreach ($data['rows'] as $row): ?><tr><?php foreach (($data['headers'] ?? []) as $h): ?><td><?php echo esc_html((string)($row[$h] ?? '')); ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?></tbody></table></div>
					<p class="description">همه queryها محدود و server-side هستند تا روی سیستم‌های ضعیف فشار ایجاد نشود.</p>
				</div>
				<?php
			}

			private function export_custom_report_csv(): void
			{
				$params = $this->sn_report_params($_GET);
				$params['limit'] = 5000;
				$data = $this->sn_report_dataset($params);
				$this->sn_csv_header('sales-network-report-' . sanitize_file_name($params['type']) . '-' . date('Y-m-d') . '.csv');
				$out = fopen('php://output', 'w');
				$headers = $data['headers'] ?? [];
				if ($headers) { fputcsv($out, $headers); }
				foreach (($data['rows'] ?? []) as $row) { $line = []; foreach ($headers as $h) { $line[] = $row[$h] ?? ''; } fputcsv($out, $line); }
				exit;
			}

		}
