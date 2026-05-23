<?php
if (! defined('ABSPATH')) {
    exit;
}

class SN_Migration_Manager
{
    public const OPTION_DB_VERSION = 'sn_db_version';
    public const CURRENT_VERSION = '1.0.37';

    public static function maybe_run(): void
    {
        $installed = (string) get_option(self::OPTION_DB_VERSION, '0');
        if (version_compare($installed, self::CURRENT_VERSION, '>=')) {
            return;
        }

        try {
            self::migrate_1_0_33();
            self::migrate_1_0_34();
            self::migrate_1_0_35();
            self::migrate_1_0_36();
            self::migrate_1_0_37();
            update_option(self::OPTION_DB_VERSION, self::CURRENT_VERSION, false);
        } catch (Throwable $e) {
            error_log('Sales Network migration error: ' . $e->getMessage());
        }
    }

    private static function migrate_1_0_33(): void
    {
        if (class_exists('SN_Activator')) {
            SN_Activator::create_tables();
        }
    }

    private static function migrate_1_0_34(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sn_invoice_logs';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            lead_id BIGINT UNSIGNED NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            actor_role VARCHAR(80) NULL,
            from_status VARCHAR(60) NULL,
            to_status VARCHAR(60) NULL,
            action_type VARCHAR(80) NOT NULL,
            note TEXT NULL,
            assigned_from_user_id BIGINT UNSIGNED NULL,
            assigned_to_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY invoice_id (invoice_id),
            KEY actor_user_id (actor_user_id),
            KEY action_type (action_type)
        ) {$charset};");
    }

    private static function migrate_1_0_35(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}sn_hr_levels (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level_key VARCHAR(100) NOT NULL,
            title VARCHAR(191) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY level_key (level_key)
        ) {$charset};");

        dbDelta("CREATE TABLE {$wpdb->prefix}sn_hr_employee_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            employee_code VARCHAR(80) NOT NULL,
            national_code VARCHAR(20) NULL,
            phone VARCHAR(40) NOT NULL,
            full_name VARCHAR(191) NOT NULL,
            role_key VARCHAR(100) NOT NULL,
            level_id BIGINT UNSIGNED NULL,
            parent_user_id BIGINT UNSIGNED NULL,
            employment_status VARCHAR(20) NOT NULL DEFAULT 'contract',
            base_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            hired_at DATETIME NULL,
            left_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY role_key (role_key),
            KEY parent_user_id (parent_user_id)
        ) {$charset};
        ");

        dbDelta("CREATE TABLE {$wpdb->prefix}sn_hr_commission_models (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(191) NOT NULL,
            role_key VARCHAR(100) NOT NULL,
            level_id BIGINT UNSIGNED NULL,
            employment_status VARCHAR(20) NOT NULL DEFAULT 'all',
            payment_method VARCHAR(30) NOT NULL DEFAULT 'all',
            commission_type VARCHAR(20) NOT NULL,
            commission_value DECIMAL(18,4) NOT NULL DEFAULT 0,
            applies_to VARCHAR(30) NOT NULL DEFAULT 'seller',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$wpdb->prefix}sn_hr_salary_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            period_key VARCHAR(20) NOT NULL,
            base_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
            commission_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            adjustments DECIMAL(18,2) NOT NULL DEFAULT 0,
            payable_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_period (user_id, period_key)
        ) {$charset};");

        dbDelta("CREATE TABLE {$wpdb->prefix}sn_hr_transfer_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_user_id BIGINT UNSIGNED NOT NULL,
            requested_by_user_id BIGINT UNSIGNED NOT NULL,
            from_parent_user_id BIGINT UNSIGNED NOT NULL,
            to_parent_user_id BIGINT UNSIGNED NULL,
            request_type VARCHAR(20) NOT NULL,
            reason TEXT NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pending_parent_approval',
            final_hr_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$wpdb->prefix}sn_hr_transfer_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transfer_request_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            action_type VARCHAR(80) NOT NULL,
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY transfer_request_id (transfer_request_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$wpdb->prefix}sn_hr_employee_assignment_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            previous_parent_user_id BIGINT UNSIGNED NULL,
            new_parent_user_id BIGINT UNSIGNED NULL,
            previous_role_key VARCHAR(100) NULL,
            new_role_key VARCHAR(100) NULL,
            previous_level_id BIGINT UNSIGNED NULL,
            new_level_id BIGINT UNSIGNED NULL,
            changed_by_user_id BIGINT UNSIGNED NULL,
            reason TEXT NULL,
            effective_from DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) {$charset};");

        if (class_exists('SN_HR')) {
            SN_HR::backfill_employee_profiles();
        }
    }
    private static function migrate_1_0_36(): void
    {
        global $wpdb;
        $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_hr_transfer_requests ADD INDEX idx_employee_user_id (employee_user_id)");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_hr_transfer_requests ADD INDEX idx_parent_user_id (from_parent_user_id)");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_hr_transfer_requests ADD INDEX idx_status_created (status, created_at)");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_invoice_logs ADD INDEX idx_invoice_created (invoice_id, created_at)");
    }

    private static function migrate_1_0_37(): void
    {
        global $wpdb;
        $indexes = [
            "ALTER TABLE {$wpdb->prefix}sn_wallet_transactions ADD COLUMN source_type VARCHAR(20) NULL",
            "ALTER TABLE {$wpdb->prefix}sn_wallet_transactions ADD COLUMN source_id BIGINT UNSIGNED NULL",
            "ALTER TABLE {$wpdb->prefix}sn_wallet_transactions ADD COLUMN period_key VARCHAR(20) NULL",
            "ALTER TABLE {$wpdb->prefix}sn_wallet_transactions ADD COLUMN calculation_snapshot LONGTEXT NULL",
            "ALTER TABLE {$wpdb->prefix}sn_wallet_transactions ADD INDEX idx_source_period (source_type, source_id, period_key)",
            "ALTER TABLE {$wpdb->prefix}sn_hr_salary_ledger ADD UNIQUE KEY uniq_user_period (user_id, period_key)",
        ];
        foreach ($indexes as $sql) { try { $wpdb->query($sql); } catch (Throwable $e) {} }
    }

}
