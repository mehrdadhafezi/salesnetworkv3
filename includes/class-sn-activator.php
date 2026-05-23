<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SN_Activator {

	public static function activate() {
		self::register_roles();
		self::create_tables();
		self::insert_defaults();
		self::create_required_pages();
	}

	public static function deactivate() {}

	private static function register_roles() {
		add_role( 'sn_seller', 'فروشنده', [ 'read' => true ] );
		add_role( 'sn_supervisor', 'سرپرست فروش', [ 'read' => true ] );
		add_role( 'sn_financial_approval', 'تایید مالی', [
			'read' => true,
			'sn_view_payments' => true,
			'sn_approve_payments' => true,
			'sn_reject_payments' => true,
		] );
		add_role( 'sn_financial', 'تایید مالی', [
			'read' => true,
			'sn_view_payments' => true,
			'sn_approve_payments' => true,
			'sn_reject_payments' => true,
		] );
		add_role( 'sn_after_sales', 'خدمات پس از فروش', [ 'read' => true, 'sn_view_customer_profiles' => true ] );
		add_role( 'sn_sales_manager', 'مدیر فروش', [
			'read' => true,
			'sn_view_sales_reports' => true,
			'sn_manage_supervisor_leads' => true,
			'sn_export_sales_reports' => true,
		] );

		$finance = get_role( 'sn_financial_approval' );
		if ( $finance ) {
			foreach ( [ 'read', 'sn_view_payments', 'sn_approve_payments', 'sn_reject_payments' ] as $cap ) {
				$finance->add_cap( $cap );
			}
		}
		$finance_alias = get_role( 'sn_financial' );
		if ( $finance_alias ) {
			foreach ( [ 'read', 'sn_view_payments', 'sn_approve_payments', 'sn_reject_payments' ] as $cap ) {
				$finance_alias->add_cap( $cap );
			}
		}

		// اطمینان از اینکه نقش‌های فرانت هیچ دسترسی به پیشخوان وردپرس ندارند.
		foreach ( [ 'sn_seller', 'sn_supervisor', 'sn_after_sales', 'sn_sales_manager' ] as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) { continue; }
			$role->add_cap( 'read' );
			foreach ( [ 'edit_posts', 'delete_posts', 'publish_posts', 'upload_files', 'edit_pages', 'delete_pages', 'manage_options', 'list_users', 'create_users', 'edit_users', 'delete_users' ] as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_leads (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone        VARCHAR(20)     NOT NULL,
			import_code  VARCHAR(80)     DEFAULT NULL,
			province     VARCHAR(60)     DEFAULT NULL,
			city         VARCHAR(60)     DEFAULT NULL,
			status       VARCHAR(20)     NOT NULL DEFAULT 'unassigned',
			lead_status  VARCHAR(60)     DEFAULT NULL,
			destination_panel VARCHAR(50) DEFAULT NULL,
			destination_routed_at DATETIME DEFAULT NULL,
			note         TEXT            DEFAULT NULL,
			seller_id    BIGINT UNSIGNED DEFAULT NULL,
			supervisor_id BIGINT UNSIGNED DEFAULT NULL,
			imported_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			assigned_at  DATETIME        DEFAULT NULL,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY seller_id (seller_id),
			KEY supervisor_id (supervisor_id),
			KEY import_code (import_code),
			KEY status (status),
			KEY lead_status (lead_status)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_lead_statuses (
			id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			label      VARCHAR(100) NOT NULL,
			color      VARCHAR(20)  NOT NULL DEFAULT '#6b7280',
			sort_order INT          NOT NULL DEFAULT 0,
			is_active  TINYINT(1)   NOT NULL DEFAULT 1,
			destination_panel VARCHAR(50) DEFAULT NULL,
			move_to_destination TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_invoices (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_code    VARCHAR(20)     NOT NULL,
			seller_id       BIGINT UNSIGNED NOT NULL,
			lead_id         BIGINT UNSIGNED DEFAULT NULL,
			customer_wp_id  BIGINT UNSIGNED DEFAULT NULL,
			customer_name   VARCHAR(120)    NOT NULL,
			customer_phone  VARCHAR(20)     NOT NULL,
			province        VARCHAR(60)     DEFAULT NULL,
			city            VARCHAR(60)     DEFAULT NULL,
			product_id      BIGINT UNSIGNED NOT NULL,
			product_price   DECIMAL(18,2)   NOT NULL DEFAULT 0,
			pay_method      VARCHAR(20)     DEFAULT NULL,
			status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
			invoice_status  VARCHAR(60)     DEFAULT NULL,
			payment_status  VARCHAR(60)     DEFAULT NULL,
			receipt_url     VARCHAR(500)    DEFAULT NULL,
			receipt_file    VARCHAR(500)    DEFAULT NULL,
			receipt_source  VARCHAR(50)     DEFAULT NULL,
			payment_source  VARCHAR(50)     DEFAULT NULL,
			manual_card_from VARCHAR(4)     DEFAULT NULL,
			manual_card_to  VARCHAR(4)      DEFAULT NULL,
			manual_amount   DECIMAL(18,2)   DEFAULT NULL,
			manual_paid_at  DATETIME        DEFAULT NULL,
			manual_paid_at_jalali VARCHAR(30) DEFAULT NULL,
			deposit_card_from_last4 VARCHAR(4) DEFAULT NULL,
			deposit_card_to_last4 VARCHAR(4) DEFAULT NULL,
			deposit_amount  DECIMAL(18,2)   DEFAULT NULL,
			deposit_jalali_datetime VARCHAR(30) DEFAULT NULL,
			paid_at         DATETIME        DEFAULT NULL,
			approved_by     BIGINT UNSIGNED DEFAULT NULL,
			approved_at     DATETIME        DEFAULT NULL,
			rejected_by     BIGINT UNSIGNED DEFAULT NULL,
			rejected_at     DATETIME        DEFAULT NULL,
			rejected_reason TEXT            DEFAULT NULL,
			financial_reviewed_by BIGINT UNSIGNED DEFAULT NULL,
			financial_reviewed_at DATETIME  DEFAULT NULL,
			financial_reject_reason TEXT    DEFAULT NULL,
			financial_rejected_at DATETIME DEFAULT NULL,
			financial_rejected_by BIGINT UNSIGNED DEFAULT NULL,
			resend_to_financial_at DATETIME DEFAULT NULL,
			recontact_requested_at DATETIME DEFAULT NULL,
			recontact_note TEXT DEFAULT NULL,
			discount_amount DECIMAL(18,2) DEFAULT NULL,
			wheel_reward_summary TEXT DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_code (invoice_code),
			KEY seller_id (seller_id),
			KEY customer_wp_id (customer_wp_id),
			KEY customer_phone (customer_phone),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_payments (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id   BIGINT UNSIGNED NOT NULL,
			authority    VARCHAR(100)    DEFAULT NULL,
			ref_id       VARCHAR(100)    DEFAULT NULL,
			amount       DECIMAL(18,2)   NOT NULL DEFAULT 0,
			status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
			uploaded_by_type VARCHAR(20) DEFAULT NULL,
			uploaded_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_invoice_items (
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
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_invoice_wheel (
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
		) $charset;" );


		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_activity_logs (
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
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}sn_lead_status_history (
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
		) $charset;" );

		// Migration: ستون‌های جدید
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_leads", 0 );
		if ( ! in_array( 'lead_status', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_leads ADD COLUMN lead_status VARCHAR(60) DEFAULT NULL AFTER status" );
		}
		if ( ! in_array( 'note', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_leads ADD COLUMN note TEXT DEFAULT NULL AFTER lead_status" );
		}
		if ( ! in_array( 'updated_at', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_leads ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER assigned_at" );
		}
		if ( ! in_array( 'supervisor_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_leads ADD COLUMN supervisor_id BIGINT UNSIGNED DEFAULT NULL AFTER seller_id" );
		}
		if ( ! in_array( 'import_code', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_leads ADD COLUMN import_code VARCHAR(80) DEFAULT NULL AFTER phone" );
		}
		$lead_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_leads", 0 );
		if ( ! in_array( 'destination_panel', $lead_cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_leads ADD COLUMN destination_panel VARCHAR(50) DEFAULT NULL AFTER lead_status" );
		}
		if ( ! in_array( 'destination_routed_at', $lead_cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_leads ADD COLUMN destination_routed_at DATETIME DEFAULT NULL AFTER destination_panel" );
		}

		if ( ! in_array( 'customer_wp_id', $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_invoices", 0 ), true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN customer_wp_id BIGINT UNSIGNED DEFAULT NULL AFTER lead_id" );
		}
		$invoice_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_invoices", 0 );
		// Fix existing installations: old schema had status VARCHAR(20), but financial statuses can be longer.
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_invoices MODIFY COLUMN status VARCHAR(60) NOT NULL DEFAULT 'pending'" );
		$invoice_migrations = [
			'invoice_status' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN invoice_status VARCHAR(60) DEFAULT NULL AFTER status",
			'payment_status' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN payment_status VARCHAR(60) DEFAULT NULL AFTER invoice_status",
			'receipt_file'    => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN receipt_file VARCHAR(500) DEFAULT NULL AFTER receipt_url",
			'receipt_source'  => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN receipt_source VARCHAR(50) DEFAULT NULL AFTER receipt_file",
			'payment_source'   => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN payment_source VARCHAR(50) DEFAULT NULL AFTER pay_method",
			'manual_card_from' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN manual_card_from VARCHAR(4) DEFAULT NULL AFTER receipt_url",
			'manual_card_to'   => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN manual_card_to VARCHAR(4) DEFAULT NULL AFTER manual_card_from",
			'manual_amount'    => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN manual_amount DECIMAL(18,2) DEFAULT NULL AFTER manual_card_to",
			'manual_paid_at'   => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN manual_paid_at DATETIME DEFAULT NULL AFTER manual_amount",
			'manual_paid_at_jalali' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN manual_paid_at_jalali VARCHAR(30) DEFAULT NULL AFTER manual_paid_at",
			'approved_by'      => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN approved_by BIGINT UNSIGNED DEFAULT NULL AFTER paid_at",
			'approved_at'      => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by",
			'rejected_by'      => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN rejected_by BIGINT UNSIGNED DEFAULT NULL AFTER approved_at",
			'rejected_at'      => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN rejected_at DATETIME DEFAULT NULL AFTER rejected_by",
			'rejected_reason'  => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN rejected_reason TEXT DEFAULT NULL AFTER rejected_at",
			'deposit_card_from_last4' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN deposit_card_from_last4 VARCHAR(4) DEFAULT NULL AFTER manual_paid_at_jalali",
			'deposit_card_to_last4'   => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN deposit_card_to_last4 VARCHAR(4) DEFAULT NULL AFTER deposit_card_from_last4",
			'deposit_amount'          => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN deposit_amount DECIMAL(18,2) DEFAULT NULL AFTER deposit_card_to_last4",
			'deposit_jalali_datetime' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN deposit_jalali_datetime VARCHAR(30) DEFAULT NULL AFTER deposit_amount",
			'financial_reviewed_by'   => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN financial_reviewed_by BIGINT UNSIGNED DEFAULT NULL AFTER rejected_reason",
			'financial_reviewed_at'   => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN financial_reviewed_at DATETIME DEFAULT NULL AFTER financial_reviewed_by",
			'financial_reject_reason' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN financial_reject_reason TEXT DEFAULT NULL AFTER financial_reviewed_at",
		];
		foreach ( $invoice_migrations as $col => $sql ) {
			if ( ! in_array( $col, $invoice_cols, true ) ) {
				$wpdb->query( $sql );
			}
		}

		$status_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_lead_statuses", 0 );
		if ( ! in_array( 'destination_panel', $status_cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_lead_statuses ADD COLUMN destination_panel VARCHAR(50) DEFAULT NULL AFTER is_active" );
		}
		if ( ! in_array( 'move_to_destination', $status_cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_lead_statuses ADD COLUMN move_to_destination TINYINT(1) NOT NULL DEFAULT 0 AFTER destination_panel" );
		}

		$invoice_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_invoices", 0 );
		$workflow_invoice_migrations = [
			'financial_rejected_at' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN financial_rejected_at DATETIME DEFAULT NULL AFTER financial_reject_reason",
			'financial_rejected_by' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN financial_rejected_by BIGINT UNSIGNED DEFAULT NULL AFTER financial_rejected_at",
			'resend_to_financial_at' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN resend_to_financial_at DATETIME DEFAULT NULL AFTER financial_rejected_by",
			'recontact_requested_at' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN recontact_requested_at DATETIME DEFAULT NULL AFTER resend_to_financial_at",
			'recontact_note' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN recontact_note TEXT DEFAULT NULL AFTER recontact_requested_at",
			'discount_amount' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN discount_amount DECIMAL(18,2) DEFAULT NULL AFTER product_price",
			'wheel_reward_summary' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN wheel_reward_summary TEXT DEFAULT NULL AFTER discount_amount",
			'wc_order_id' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN wc_order_id BIGINT UNSIGNED DEFAULT NULL AFTER wheel_reward_summary",
			'financial_return_state' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN financial_return_state VARCHAR(40) DEFAULT NULL AFTER wc_order_id",
			'returned_to_seller_at' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN returned_to_seller_at DATETIME DEFAULT NULL AFTER financial_return_state",
			'resent_after_return_at' => "ALTER TABLE {$wpdb->prefix}sn_invoices ADD COLUMN resent_after_return_at DATETIME DEFAULT NULL AFTER returned_to_seller_at",
		];
		foreach ( $workflow_invoice_migrations as $col => $sql ) {
			if ( ! in_array( $col, $invoice_cols, true ) ) { $wpdb->query( $sql ); }
		}

		$payment_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_payments", 0 );
		if ( ! in_array( 'uploaded_by_type', $payment_cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_payments ADD COLUMN uploaded_by_type VARCHAR(20) DEFAULT NULL AFTER status" );
		}
		if ( ! in_array( 'uploaded_by_user_id', $payment_cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_payments ADD COLUMN uploaded_by_user_id BIGINT UNSIGNED DEFAULT NULL AFTER uploaded_by_type" );
		}
		$log_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}sn_activity_logs", 0 );
		$log_migrations = [
			'old_value' => "ALTER TABLE {$wpdb->prefix}sn_activity_logs ADD COLUMN old_value LONGTEXT DEFAULT NULL AFTER action",
			'new_value' => "ALTER TABLE {$wpdb->prefix}sn_activity_logs ADD COLUMN new_value LONGTEXT DEFAULT NULL AFTER old_value",
			'user_agent' => "ALTER TABLE {$wpdb->prefix}sn_activity_logs ADD COLUMN user_agent TEXT DEFAULT NULL AFTER ip_address",
		];
		foreach ( $log_migrations as $col => $sql ) {
			if ( ! in_array( $col, $log_cols, true ) ) {
				$wpdb->query( $sql );
			}
		}

		// 1.0.24: indexes for lightweight customer action timeline queries.
		$sn_log_indexes = [
			'sn_idx_log_invoice_action_id' => 'invoice_id, action, id',
			'sn_idx_log_invoice_id'        => 'invoice_id, id',
			'sn_idx_log_action_created'    => 'action, created_at',
		];
		foreach ( $sn_log_indexes as $sn_index => $sn_cols_sql ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $wpdb->prefix . 'sn_activity_logs WHERE Key_name = %s', $sn_index ) );
			if ( ! $exists ) {
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}sn_activity_logs ADD INDEX {$sn_index} ({$sn_cols_sql})" );
			}
		}

		// 1.0.16 stability-lite: safe indexes for financial tabs and invoice lookup on upgraded installs.
		$sn_index_specs = [
			"{$wpdb->prefix}sn_invoices" => [
				'sn_idx_inv_status_payment' => 'status, payment_status',
				'sn_idx_inv_financial_tabs' => 'payment_status, receipt_source, payment_source',
				'sn_idx_inv_seller_status' => 'seller_id, status',
			],
			"{$wpdb->prefix}sn_payments" => [
				'sn_idx_pay_status_uploaded' => 'status, uploaded_by_type',
				'sn_idx_pay_invoice_status' => 'invoice_id, status',
			],
		];
		foreach ( $sn_index_specs as $sn_table => $sn_indexes ) {
			foreach ( $sn_indexes as $sn_index => $sn_cols_sql ) {
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $sn_table . ' WHERE Key_name = %s', $sn_index ) );
				if ( ! $exists ) {
					$wpdb->query( "ALTER TABLE {$sn_table} ADD INDEX {$sn_index} ({$sn_cols_sql})" );
				}
			}
		}

	}

	private static function insert_defaults() {
		global $wpdb;
		update_option( 'sn_flush_rewrite_needed', '1' );

		// وضعیت‌های پیش‌فرض
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sn_lead_statuses" );
		if ( $count === 0 ) {
			$defaults = [
				[ 'label' => 'جواب نداده',  'color' => '#f59e0b', 'sort_order' => 1 ],
				[ 'label' => 'تماس مجدد',  'color' => '#3b82f6', 'sort_order' => 2 ],
				[ 'label' => 'علاقه‌مند',   'color' => '#10b981', 'sort_order' => 3 ],
				[ 'label' => 'در بررسی',    'color' => '#8b5cf6', 'sort_order' => 4 ],
				[ 'label' => 'کنسل',        'color' => '#ef4444', 'sort_order' => 5 ],
				[ 'label' => 'خرید کرده',   'color' => '#22c55e', 'sort_order' => 6 ],
			];
			foreach ( $defaults as $s ) {
				$wpdb->insert( $wpdb->prefix . 'sn_lead_statuses', $s );
			}
		}

		$options = [
			'sn_zarinpal_merchant'  => '',
			'sn_zarinpal_sandbox'   => '0',
			'sn_sms_provider'       => 'faraz',
			'sn_sms_api_key'        => '',
			'sn_sms_sender'         => '',
			'sn_card_number'        => '',
			'sn_card_owner'         => '',
			'sn_invoice_page_id'       => '',
			'sn_seller_panel_page_id'  => '',
			'sn_supervisor_panel_page_id' => '',
			'sn_supervisor_auth_page_id' => '',
			'sn_after_sales_panel_page_id' => '',
			'sn_sales_manager_auth_page_id' => '',
			'sn_sales_manager_panel_page_id' => '',
			'sn_financial_auth_page_id' => '',
			'sn_financial_panel_page_id' => '',
			'sn_auth_page_id'          => '',
			'sn_coupon_allow_on_sale' => '0',
			'sn_invoice_info_show_short_desc' => '1',
			'sn_invoice_info_show_price' => '1',
			'sn_invoice_info_show_lottery' => '1',
			'sn_invoice_info_show_coupon' => '1',
			'sn_lottery_text_template' => 'با پرداخت این فاکتور {count} شانس برای شرکت در قرعه‌کشی {company} دریافت می‌کنید.',
			'sn_recontact_popup_text' => 'اگر پیش از پرداخت فاکتور از کارشناس خود سوالی دارید، دکمه ارتباط مجدد با کارشناس را بزنید.',
			'sn_wheel_company_name' => '',
		];
		foreach ( $options as $key => $val ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $val );
			}
		}
	}

	public static function create_required_pages(): array {
		$pages = self::required_pages();
		$result = [];
		foreach ( $pages as $option_key => $page ) {
			$duplicates = [];
			$page_id = self::resolve_required_page_id( $option_key, $page, $duplicates );
			$post = $page_id ? get_post( $page_id ) : null;
			if ( ! $post ) {
				$new_id = wp_insert_post( [
					'post_title'     => $page['title'],
					'post_name'      => $page['slug'],
					'post_content'   => $page['shortcode'],
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				], true );
				if ( is_wp_error( $new_id ) ) { $result[ $option_key ] = [ 'created' => false, 'error' => $new_id->get_error_message() ]; continue; }
				$page_id = (int) $new_id;
				$post = get_post( $page_id );
				update_post_meta( $page_id, '_sn_system_page', '1' );
			}
			if ( $post && 'publish' !== $post->post_status ) {
				wp_update_post( [ 'ID' => $page_id, 'post_status' => 'publish' ] );
				$post = get_post( $page_id );
			}
			if ( $post ) {
				self::ensure_page_shortcode_content( $post, $page['shortcode'] );
				$post = get_post( $page_id );
			}
			update_option( $option_key, $page_id );
			if ( $post && trim( (string) $post->post_content ) === $page['shortcode'] ) {
				update_post_meta( $page_id, '_sn_system_page', '1' );
			}
			$result[ $option_key ] = [
				'created'    => true,
				'page_id'    => $page_id,
				'title'      => $page['title'],
				'slug'       => $page['slug'],
				'shortcode'  => $page['shortcode'],
				'duplicates' => $duplicates,
			];
		}
		update_option( 'sn_pages_initialized', current_time( 'mysql' ) );
		update_option( 'sn_flush_rewrite_needed', '1' );
		self::store_duplicate_report( $result );
		return $result;
	}

	public static function required_pages(): array {
		return [
			'sn_auth_page_id' => [ 'title' => 'ورود فروشنده', 'slug' => 'seller-login', 'shortcode' => '[sn_auth]' ],
			'sn_supervisor_auth_page_id' => [ 'title' => 'ورود سرپرست', 'slug' => 'supervisor-login', 'shortcode' => '[sn_supervisor_auth]' ],
			'sn_seller_panel_page_id' => [ 'title' => 'پنل فروشنده', 'slug' => 'seller-panel', 'shortcode' => '[sn_seller_panel]' ],
			'sn_supervisor_panel_page_id' => [ 'title' => 'پنل سرپرست', 'slug' => 'supervisor-panel', 'shortcode' => '[sn_supervisor_panel]' ],
			'sn_after_sales_panel_page_id' => [ 'title' => 'پنل خدمات پس از فروش', 'slug' => 'after-sales-panel', 'shortcode' => '[sn_after_sales_panel]' ],
			'sn_sales_manager_auth_page_id' => [ 'title' => 'ورود مدیر فروش', 'slug' => 'sales-manager-login', 'shortcode' => '[sn_sales_manager_auth]' ],
			'sn_sales_manager_panel_page_id' => [ 'title' => 'پنل مدیر فروش', 'slug' => 'sales-manager-panel', 'shortcode' => '[sn_sales_manager_panel]' ],
			'sn_financial_auth_page_id' => [ 'title' => 'ورود تایید مالی', 'slug' => 'financial-login', 'shortcode' => '[sn_financial_auth]' ],
			'sn_financial_panel_page_id' => [ 'title' => 'پنل تایید مالی', 'slug' => 'financial-approval', 'shortcode' => '[sn_financial_panel]' ],
			'sn_invoice_page_id' => [ 'title' => 'فاکتور', 'slug' => 'invoice', 'shortcode' => '[sn_invoice_page]' ],
			'sn_hr_panel_page_id' => [ 'title' => 'پنل منابع انسانی', 'slug' => 'hr-panel', 'shortcode' => '[sn_hr_panel]' ],
		];
	}

	private static function resolve_required_page_id( string $option_key, array $page, array &$duplicates ): int {
		$valid_statuses = [ 'publish', 'private', 'draft' ];
		$option_id = absint( get_option( $option_key, 0 ) );
		$post = $option_id ? get_post( $option_id ) : null;
		if ( $post && 'page' === $post->post_type && in_array( $post->post_status, $valid_statuses, true ) && self::content_has_shortcode( (string) $post->post_content, $page['shortcode'] ) ) {
			$duplicates = self::find_duplicate_pages( $page, $option_id );
			return $option_id;
		}

		$by_slug = get_page_by_path( $page['slug'], OBJECT, 'page' );
		if ( $by_slug && 'trash' !== $by_slug->post_status ) {
			$duplicates = self::find_duplicate_pages( $page, (int) $by_slug->ID );
			return (int) $by_slug->ID;
		}

		$by_shortcode = self::find_pages_by_shortcode( $page['shortcode'] );
		if ( ! empty( $by_shortcode ) ) {
			$chosen = (int) $by_shortcode[0]->ID;
			$duplicates = self::find_duplicate_pages( $page, $chosen );
			return $chosen;
		}

		$duplicates = self::find_duplicate_pages( $page, 0 );
		return 0;
	}

	private static function ensure_page_shortcode_content( WP_Post $post, string $shortcode ): void {
		$content = trim( (string) $post->post_content );
		if ( $content === $shortcode ) {
			return;
		}
		$has_shortcode = self::content_has_shortcode( $content, $shortcode );
		$wrapped_in_code = (bool) preg_match( '/<(code|pre)[^>]*>.*' . preg_quote( $shortcode, '/' ) . '.*<\/\1>/is', $content );
		$is_system_page = (string) get_post_meta( $post->ID, '_sn_system_page', true ) === '1';
		$is_elementor = (string) get_post_meta( $post->ID, '_elementor_edit_mode', true ) !== '';

		if ( ! $is_elementor && ( $content === '' || $wrapped_in_code || $is_system_page ) ) {
			wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $shortcode,
			] );
			return;
		}

		if ( ! $has_shortcode ) {
			wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $content . "\n\n" . $shortcode,
			] );
		}
	}

	private static function content_has_shortcode( string $content, string $shortcode ): bool {
		$tag = trim( $shortcode, '[]' );
		return has_shortcode( $content, $tag ) || false !== strpos( $content, $shortcode );
	}

	private static function find_pages_by_shortcode( string $shortcode ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $shortcode ) . '%';
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status IN ('publish','private','draft') AND post_content LIKE %s ORDER BY post_date ASC LIMIT 20",
			$like
		) );
		return array_values( array_filter( array_map( 'get_post', array_map( 'absint', $ids ?: [] ) ) ) );
	}

	private static function find_duplicate_pages( array $page, int $chosen_id ): array {
		$ids = [];
		$slug_page = get_page_by_path( $page['slug'], OBJECT, 'page' );
		if ( $slug_page ) {
			$ids[] = (int) $slug_page->ID;
		}
		foreach ( self::find_pages_by_shortcode( $page['shortcode'] ) as $post ) {
			$ids[] = (int) $post->ID;
		}
		$ids = array_values( array_unique( array_filter( $ids, static fn( $id ) => $id && $id !== $chosen_id ) ) );
		$duplicates = [];
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || 'page' !== $p->post_type || 'trash' === $p->post_status ) {
				continue;
			}
			$duplicates[] = [
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'slug'   => $p->post_name,
				'status' => $p->post_status,
			];
		}
		return $duplicates;
	}

	private static function store_duplicate_report( array $result ): void {
		$report = [];
		foreach ( $result as $option_key => $item ) {
			if ( empty( $item['duplicates'] ) ) {
				continue;
			}
			$report[ $option_key ] = [
				'selected_page_id' => (int) ( $item['page_id'] ?? 0 ),
				'title'            => (string) ( $item['title'] ?? '' ),
				'duplicates'       => $item['duplicates'],
			];
		}
		update_option( 'sn_page_duplicate_report', $report, false );
		if ( ! empty( $report ) ) {
			error_log( 'SN duplicate page report: ' . wp_json_encode( $report, JSON_UNESCAPED_UNICODE ) );
		}
	}

}
