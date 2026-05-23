<?php
if (! defined('ABSPATH')) {
	exit;
}

class SN_Helpers
{

	/**
	 * تولید کد فاکتور یکتا برای Romina Club.
	 *
	 * قوانین:
	 * - طول کد دقیقاً ۶ کاراکتر است.
	 * - فقط از حروف/اعداد کم‌خطای دیداری استفاده می‌شود.
	 * - حروف I / L / O و عدد 0 استفاده نمی‌شوند.
	 * - تمام کدها قبل از ذخیره Uppercase می‌شوند.
	 * - مقایسه یکتایی به‌صورت Case-Insensitive انجام می‌شود.
	 *
	 * نکته: کدهای فاکتور قدیمی تغییر داده نمی‌شوند؛ این منطق فقط برای فاکتورهای جدید است.
	 */
	public static function generate_invoice_code(): string
	{
		$charset = 'ABCDEFGHJKMNPQRSTUVWXYZ123456789';
		$length  = 6;
		$max     = strlen($charset) - 1;

		for ($attempt = 0; $attempt < 50; $attempt++) {
			$code = '';
			for ($i = 0; $i < $length; $i++) {
				// random_int از CSPRNG سیستم استفاده می‌کند و جایگزین امن math/rand است.
				$code .= $charset[random_int(0, $max)];
			}

			$code = self::normalize_invoice_code($code);
			if (! self::invoice_code_exists($code)) {
				return $code;
			}
		}

		// احتمال رسیدن به این نقطه بسیار ناچیز است، اما برای جلوگیری از loop بی‌نهایت خطای واضح می‌دهیم.
		throw new RuntimeException('Unable to generate unique invoice code after 50 attempts.');
	}

	public static function normalize_invoice_code(string $code): string
	{
		return strtoupper(trim($code));
	}

	public static function invoice_code_exists(string $code): bool
	{
		global $wpdb;
		$code = self::normalize_invoice_code($code);
		return (bool) $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sn_invoices WHERE UPPER(invoice_code) = %s LIMIT 1",
			$code
		));
	}

	/** فرمت قیمت ریال */
	public static function format_price(float $price): string
	{
		return number_format($price, 0, '.', ',') . ' تومان';
	}

	/** برچسب فارسی وضعیت‌های فاکتور و پرداخت */
	public static function status_label(string $status): string
	{
		$map = [
			'pre_invoice' => 'پیش‌فاکتور',
			'pending' => 'در انتظار پرداخت',
			'pending_payment' => 'در انتظار پرداخت',
			'receipt_uploaded' => 'نیاز به بررسی فیش',
			'pending_financial_approval' => 'نیاز به بررسی فیش',
			'paid' => 'پرداخت‌شده درگاهی',
			'approved' => 'تایید شده مالی',
			'rejected' => 'رد شده',
			'cancelled' => 'لغوشده',
			'assigned' => 'تخصیص داده‌شده',
			'unassigned' => 'بدون تخصیص',
			'supervisor_pool' => 'در پنل سرپرست',
			'invoiced' => 'پیش‌فاکتور صادر شده',
			'follow_up' => 'پیگیری مجدد',
			'no_answer' => 'عدم پاسخگویی',
			'interested' => 'علاقه‌مند',
			'not_interested' => 'عدم تمایل',
			'converted' => 'تبدیل شده',
			'card' => 'کارت به کارت',
			'card_to_card' => 'کارت به کارت',
			'online' => 'پرداخت آنلاین',
			'gateway' => 'درگاه پرداخت',
			'customer_upload' => 'ثبت توسط مشتری',
			'supervisor_upload' => 'ثبت توسط سرپرست',
			'recontact_requested' => 'ارتباط مجدد با کارشناس',
		];
		return $map[$status] ?? ($status !== '' ? $status : '—');
	}

	/** برچسب فارسی روش پرداخت */
	public static function pay_method_label(string $method): string
	{
		$map = [
			'card' => 'کارت به کارت',
			'card_to_card' => 'کارت به کارت',
			'online' => 'پرداخت آنلاین',
			'gateway' => 'درگاه پرداخت',
		];
		return $map[$method] ?? ($method !== '' ? $method : '—');
	}

	/** برچسب فارسی منبع ثبت فیش */
	public static function payment_source_label(string $source): string
	{
		$map = [
			'customer_upload' => 'ثبت توسط مشتری',
			'supervisor_upload' => 'ثبت توسط سرپرست',
			'recontact_requested' => 'ارتباط مجدد با کارشناس',
			'admin_upload' => 'ثبت توسط ادمین',
			'gateway' => 'درگاه پرداخت',
		];
		return $map[$source] ?? ($source !== '' ? $source : '—');
	}

	public static function payment_status_label(string $status): string
	{
		return self::status_label($status);
	}

	public static function invoice_status_label(string $status): string
	{
		return self::status_label($status);
	}

	public static function source_label(string $source): string
	{
		return self::payment_source_label($source);
	}

	public static function role_label(string $role): string
	{
		$map = [
			'administrator' => 'مدیر سایت',
			'sn_seller' => 'فروشنده',
			'sn_supervisor' => 'سرپرست فروش',
			'sn_financial_approval' => 'تایید مالی',
			'sn_financial' => 'تایید مالی',
			'sn_after_sales' => 'خدمات پس از فروش',
			'sn_sales_manager' => 'مدیر فروش',
			'customer' => 'مشتری',
			'subscriber' => 'مشترک',
		];
		return $map[$role] ?? ($role !== '' ? $role : '—');
	}

	/** تبدیل اعداد فارسی به انگلیسی */
	public static function to_english_nums(string $str): string
	{
		$persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
		$arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
		$english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
		$str = str_replace($persian, $english, $str);
		$str = str_replace($arabic,  $english, $str);
		return $str;
	}

	public static function jalali_to_gregorian_date(string $date): string
	{
		$date = trim(self::to_english_nums($date));
		$date = str_replace(['-', '.', ' '], '/', $date);
		if (! preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date, $m)) {
			return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
		}
		$jy = (int) $m[1];
		$jm = (int) $m[2];
		$jd = (int) $m[3];
		if ($jy > 1700) {
			return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
		}
		$jy += 1595;
		$days = -355668 + (365 * $jy) + ((int) floor($jy / 33) * 8) + (int) floor((($jy % 33) + 3) / 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
		$gy = 400 * (int) floor($days / 146097);
		$days %= 146097;
		if ($days > 36524) {
			$gy += 100 * (int) floor(--$days / 36524);
			$days %= 36524;
			if ($days >= 365) {
				$days++;
			}
		}
		$gy += 4 * (int) floor($days / 1461);
		$days %= 1461;
		if ($days > 365) {
			$gy += (int) floor(($days - 1) / 365);
			$days = ($days - 1) % 365;
		}
		$gd = $days + 1;
		$sal_a = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
		for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
			$gd -= $sal_a[$gm];
		}
		return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
	}

	public static function gregorian_to_jalali_date(?string $datetime): string
	{
		if (! $datetime) {
			return '—';
		}
		$ts = strtotime($datetime);
		if (! $ts) {
			return (string) $datetime;
		}
		$gy = (int) date('Y', $ts);
		$gm = (int) date('n', $ts);
		$gd = (int) date('j', $ts);
		$g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
		$gy2 = $gm > 2 ? $gy + 1 : $gy;
		$days = 355666 + (365 * $gy) + (int) floor(($gy2 + 3) / 4) - (int) floor(($gy2 + 99) / 100) + (int) floor(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
		$jy = -1595 + (33 * (int) floor($days / 12053));
		$days %= 12053;
		$jy += 4 * (int) floor($days / 1461);
		$days %= 1461;
		if ($days > 365) {
			$jy += (int) floor(($days - 1) / 365);
			$days = ($days - 1) % 365;
		}
		$jm = $days < 186 ? 1 + (int) floor($days / 31) : 7 + (int) floor(($days - 186) / 30);
		$jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
		return sprintf('%04d/%02d/%02d', $jy, $jm, $jd) . (strlen($datetime) > 10 ? ' ' . date('H:i', $ts) : '');
	}

	/** اعتبارسنجی شماره موبایل ایران */
	public static function is_valid_mobile(string $phone): bool
	{
		$phone = self::to_english_nums($phone);
		$phone = preg_replace('/\D/', '', $phone);
		if (substr($phone, 0, 2) === '98') {
			$phone = '0' . substr($phone, 2);
		}
		return (bool) preg_match('/^09[0-9]{9}$/', $phone);
	}

	/** نرمال‌سازی شماره موبایل */
	public static function normalize_mobile(string $phone): string
	{
		$phone = self::to_english_nums($phone);
		$phone = preg_replace('/\D/', '', $phone);
		if (substr($phone, 0, 2) === '98') {
			$phone = '0' . substr($phone, 2);
		}
		return $phone;
	}

	/** لیست استان‌های ایران */
	public static function get_provinces(): array
	{
		return [
			'آذربایجان شرقی',
			'آذربایجان غربی',
			'اردبیل',
			'اصفهان',
			'البرز',
			'ایلام',
			'بوشهر',
			'تهران',
			'چهارمحال و بختیاری',
			'خراسان جنوبی',
			'خراسان رضوی',
			'خراسان شمالی',
			'خوزستان',
			'زنجان',
			'سمنان',
			'سیستان و بلوچستان',
			'فارس',
			'قزوین',
			'قم',
			'کردستان',
			'کرمان',
			'کرمانشاه',
			'کهگیلویه و بویراحمد',
			'گلستان',
			'گیلان',
			'لرستان',
			'مازندران',
			'مرکزی',
			'هرمزگان',
			'همدان',
			'یزد',
		];
	}

	/** برگرداندن پیام JSON و توقف */
	public static function send_json(bool $success, string $message, array $data = []): void
	{
		$payload = wp_json_encode(
			array_merge(['success' => $success, 'message' => $message], $data)
		);
		// پاک کردن هر output قبلی
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		// ارسال response
		wp_send_json(json_decode($payload, true));
	}

	/** دریافت محصولات فعال‌شده برای شبکه فروش */
	public static function get_sn_products(): array
	{
		if (! class_exists('WooCommerce')) {
			return [];
		}
		$query = new WP_Query([
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [[
				'key'   => '_sn_enabled',
				'value' => '1',
			]],
		]);
		$products = [];
		foreach ($query->posts as $post) {
			$product = wc_get_product($post->ID);
			if ($product) {
				$products[] = [
					'id'    => $product->get_id(),
					'name'  => $product->get_name(),
					'price' => (float) $product->get_price(),
				];
			}
		}
		return $products;
	}

	/** پیدا کردن فاکتور با کد، به‌صورت Case-Insensitive */
	public static function get_invoice_by_code(string $code): ?object
	{
		global $wpdb;
		$code = self::normalize_invoice_code($code);
		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sn_invoices WHERE UPPER(invoice_code) = %s LIMIT 1",
			$code
		));
	}


	public static function public_request_token(?object $invoice): string
	{
		if (! $invoice || empty($invoice->id) || empty($invoice->invoice_code)) {
			return '';
		}
		return hash_hmac('sha256', (string) $invoice->id . '|' . (string) $invoice->invoice_code, wp_salt('sn_public_token'));
	}

	public static function validate_public_invoice_access(?object $invoice, string $token): bool
	{
		$expected = self::public_request_token($invoice);
		return $expected !== '' && $token !== '' && hash_equals($expected, $token);
	}

	public static function enforce_rate_limit(string $key, int $max, int $window): bool
	{
		$transient_key = 'sn_rl_' . md5($key);
		$hits = (int) get_transient($transient_key);
		if ($hits >= $max) {
			return false;
		}
		set_transient($transient_key, $hits + 1, $window);
		return true;
	}

	/** آپلود فایل فیش پرداخت */
	public static function upload_receipt(array $file): string|false
	{
		if (! function_exists('wp_handle_upload')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$allowed_ext = ['jpg','jpeg','png','gif','pdf'];
		$allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
		$size_limit = 5 * 1024 * 1024;
		$name = sanitize_file_name((string) ($file['name'] ?? ''));
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (! in_array($ext, $allowed_ext, true) || (int) ($file['size'] ?? 0) > $size_limit) {
			return false;
		}
		$finfo = wp_check_filetype_and_ext((string) ($file['tmp_name'] ?? ''), $name);
		$mime = (string) ($finfo['type'] ?? ($file['type'] ?? ''));
		if (! in_array($mime, $allowed_mimes, true)) {
			return false;
		}
		$file['name'] = $name;
		$uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => array_combine($allowed_ext, $allowed_mimes)]);
		return $uploaded['url'] ?? false;
	}
}
