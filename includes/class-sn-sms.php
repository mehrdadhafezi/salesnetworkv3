<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * سرویس ارسال پیامک
 * پشتیبانی از: کاوه‌نگار | فراز اس‌ام‌اس | ملی پیامک
 */
class SN_SMS {

	private string $provider;
	private string $api_key;
	private string $sender;
	private string $invoice_pattern;

	// --- ملی پیامک ---
	private string $meli_username;
	private string $meli_password;
	private string $meli_body_id_invoice;

	public function __construct() {
		$this->provider        = trim( (string) get_option( 'sn_sms_provider', 'kavenegar' ) );
		$this->api_key         = trim( (string) get_option( 'sn_sms_api_key', '' ) );
		$this->sender          = trim( (string) get_option( 'sn_sms_sender', '' ) );
		$this->invoice_pattern = trim( (string) get_option( 'sn_faraz_pattern_invoice', '' ) );

		// تنظیمات ملی پیامک (الگو)
		$this->meli_username         = trim( (string) get_option( 'sn_meli_username', '' ) );
		$this->meli_password         = trim( (string) get_option( 'sn_meli_password', '' ) );
		$this->meli_body_id_invoice  = trim( (string) get_option( 'sn_meli_body_id_invoice', '' ) );

		// مجاز کردن دامنه‌های فراز اس‌ام‌اس در صورت block بودن external requests
		add_filter( 'http_request_host_is_external', [ $this, 'allow_sms_hosts' ], 10, 2 );
	}

	public function allow_sms_hosts( bool $allow, string $host ): bool {
		$allowed_hosts = [
			'api.iranpayamak.com',
			'rest.ippanel.com',
			'ippanel.com',
			'app.farazsms.com',
		];
		if ( in_array( strtolower( $host ), $allowed_hosts, true ) ) {
			return true;
		}
		return $allow;
	}

	/** ارسال پیامک ساده */
	public function send( string $to, string $message ): bool {
		$to = SN_Helpers::normalize_mobile( $to );

		if ( empty( $this->api_key ) || empty( $to ) ) {
			return false;
		}

		switch ( $this->provider ) {
			case 'kavenegar':
				return $this->send_kavenegar( $to, $message );

			case 'faraz':
				return $this->send_faraz_simple( $to, $message );

			case 'melipayamak':
				return $this->send_melipayamak( $to, $message );
		}

		return false;
	}

	/** ارسال لینک فاکتور به مشتری */
	public function send_invoice_link( string $phone, string $invoice_code, string $invoice_url, string $customer_name, $amount = '', $card_number = '' ): bool {
		$phone         = SN_Helpers::normalize_mobile( $phone );
		$invoice_code  = trim( $invoice_code );
		$invoice_url   = trim( $invoice_url );
		$customer_name = trim( $customer_name );
		$amount        = trim( (string) $amount );
		$card_number   = trim( (string) $card_number );

		if ( empty( $phone ) || empty( $invoice_url ) ) {
			return false;
		}

		if ( empty( $customer_name ) ) {
			$customer_name = 'مشتری گرامی';
		}
		
		// اگر مبلغ خالی باشد، از تنظیمات بگیر
		if ( empty( $card_number ) ) {
			$card_number = get_option( 'sn_card_number', '' );
		}

		// اگر سرویس‌دهنده فراز باشد و کد پترن تعریف شده باشد، با پترن بفرست
		if ( $this->provider === 'faraz' && ! empty( $this->invoice_pattern ) ) {
			return $this->send_faraz_pattern(
				$phone,
				$this->invoice_pattern,
				[
					'customer_name' => $customer_name,
					'invoice_code'  => $invoice_code,
					'invoice_url'   => $invoice_url,
					'amount'        => (string) preg_replace( '/[^0-9]/', '', SN_Helpers::to_english_nums( (string) $amount ) ),
					'card_number'   => $card_number,
				]
			);
		}

		// اگر سرویس‌دهنده ملی پیامک باشد و bodyId فاکتور تعریف شده باشد، با الگو بفرست
		if ( $this->provider === 'melipayamak' && ! empty( $this->meli_body_id_invoice ) ) {
			return $this->send_melipayamak_pattern(
				$phone,
				(int) $this->meli_body_id_invoice,
				[ $customer_name, $invoice_code, $invoice_url, $amount, $card_number ]
			);
		}

		// استفاده از template از تنظیمات
		$template = get_option( 'sn_sms_invoice_template', '' );
		
		// اگر template داریم از اون استفاده کن، وگرنه متن پیش‌فرض
		if ( ! empty( $template ) ) {
			$message = str_replace(
				[ '{customer_name}', '{invoice_code}', '{invoice_url}', '{amount}', '{card_number}' ],
				[ $customer_name, $invoice_code, $invoice_url, $amount, $card_number ],
				$template
			);
		} else {
			// متن پیش‌فرض
			$message  = "مشتری گرامی {$customer_name}\n";
			$message .= "فاکتور شما با کد {$invoice_code} آماده است.\n";
			
			if ( ! empty( $amount ) ) {
				$message .= "مبلغ: {$amount} تومان\n";
			}
			
			$message .= "برای مشاهده و پرداخت:\n{$invoice_url}";
			
			if ( ! empty( $card_number ) ) {
				$message .= "\n\nیا کارت به کارت به شماره:\n{$card_number}";
			}
		}

		return $this->send( $phone, $message );
	}

	// --- Kavenegar ---
	private function send_kavenegar( string $to, string $message ): bool {
		$url = "https://api.kavenegar.com/v1/{$this->api_key}/sms/send.json";

		$res = wp_remote_post( $url, [
			'timeout' => 15,
			'body'    => [
				'receptor' => $to,
				'message'  => $message,
				'sender'   => $this->sender,
			],
		] );

		if ( is_wp_error( $res ) ) {
			error_log( 'SN Kavenegar Error: ' . $res->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return isset( $body['return']['status'] ) && (int) $body['return']['status'] === 200;
	}

	// --- Faraz SMS : Simple (IPPanel v1) ---
	private function send_faraz_simple( string $to, string $message ): bool {
		// داکیومنت: https://docs.iranpayamak.com/send-simple-sms-13909967e0.md
		$url = 'https://api.iranpayamak.com/ws/v1/sms/simple';

		// iranpayamak فرمت 09xx میخواد
		$originator = $this->sender;

		$payload = [
			'text'          => $message,
			'line_number'   => $originator,
			'recipients'    => [ $to ],
			'number_format' => 'english',
			'schedule'      => null,
		];

		$res = wp_remote_post( $url, [
			'timeout'   => 20,
			'sslverify' => false,
			'headers'   => [
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Api-Key'      => $this->api_key,
			],
			'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		] );

		if ( is_wp_error( $res ) ) {
			error_log( 'SN Faraz Simple WP_Error: ' . $res->get_error_message() );
			return false;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$raw       = wp_remote_retrieve_body( $res );

		error_log( 'SN Faraz Simple | HTTP:' . $http_code . ' | ' . $raw );

		// IPPanel v1: status=OK
		$body = json_decode( $raw, true );
		if ( $http_code === 200 || $http_code === 201 ) {
			return isset( $body['status'] ) ? $body['status'] === 'OK' : true;
		}
		return false;
	}

	// --- Faraz SMS : Pattern ---
	// فراز SMS از IPPanel استفاده میکنه
	// اگر rest.ippanel.com بلاک بود (سرورهای ایران)، fallback به endpoint داخلی ippanel.com:8080
	public function send_faraz_pattern( string $to, string $pattern_code, array $attributes ): bool {
		if ( empty( $pattern_code ) || empty( $to ) ) {
			error_log( 'SN Faraz Pattern: pattern_code یا to خالی است' );
			return false;
		}

		// تبدیل شماره: 09xx → +989xx
		// iranpayamak فرمت 09xxxxxxxxx میخواد (بدون +98)
		$recipient  = $to;  // همان 09xx که از SN_Helpers آمده
		$originator = $this->sender;

		// --- روش اول: REST API (rest.ippanel.com) ---
		$result = $this->send_faraz_pattern_rest( $recipient, $originator, $pattern_code, $attributes );
		if ( $result !== null ) {
			return $result;
		}

		// --- روش دوم: endpoint داخلی ایران (ippanel.com:8080) ---
		error_log( 'SN Faraz: REST بلاک شد، تلاش با endpoint داخلی...' );
		return $this->send_faraz_pattern_domestic( $recipient, $pattern_code, $attributes );
	}

	private function send_faraz_pattern_rest( string $recipient, string $originator, string $pattern_code, array $attributes ): ?bool {
		// داکیومنت رسمی: https://docs.iranpayamak.com/send-pattern-based-sms-13925177e0.md
		// route: POST https://api.iranpayamak.com/ws/v1/sms/pattern
		$url = 'https://api.iranpayamak.com/ws/v1/sms/pattern';

		$payload = [
			'code'          => $pattern_code,     // نه patternCode
			'recipient'     => $recipient,         // فرمت 09xxxxxxxxx
			'line_number'   => $originator,        // نه originator
			'attributes'    => $attributes,        // نه values — آرایه associative
			'number_format' => 'english',
		];

		$res = wp_remote_post( $url, [
			'timeout'   => 20,
			'sslverify' => false,
			'headers'   => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'Api-Key'      => $this->api_key,
			],
			'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		] );

		if ( is_wp_error( $res ) ) {
			error_log( 'SN Faraz Pattern WP_Error: ' . $res->get_error_message() );
			return null;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$raw       = wp_remote_retrieve_body( $res );
		$body      = json_decode( $raw, true );

		error_log( 'SN Faraz Pattern | HTTP:' . $http_code . ' | to:' . $recipient . ' | code:' . $pattern_code . ' | Response:' . $raw );

		// 201 = موفق (Created)
		if ( $http_code === 201 || $http_code === 200 ) {
			if ( isset( $body['status'] ) ) {
				return $body['status'] === 'success';
			}
			return true;
		}
		if ( $http_code === 401 || $http_code === 403 ) {
			error_log( 'SN Faraz Pattern Auth FAILED — API Key نامعتبر' );
			return false;
		}
		return null;
	}

	private function send_faraz_pattern_domestic( string $recipient, string $pattern_code, array $attributes ): bool {
		// fallback: همان API جدید iranpayamak با timeout بیشتر
		$url     = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
		$payload = [
			'code'          => $pattern_code,
			'recipient'     => $recipient,
			'line_number'   => $this->sender,
			'attributes'    => $attributes,
			'number_format' => 'english',
		];
		$res = wp_remote_post( $url, [
			'timeout'   => 30,
			'sslverify' => false,
			'headers'   => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'Api-Key'      => $this->api_key,
			],
			'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		] );
		if ( is_wp_error( $res ) ) {
			error_log( 'SN Faraz Fallback WP_Error: ' . $res->get_error_message() );
			return false;
		}
		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$raw       = wp_remote_retrieve_body( $res );
		error_log( 'SN Faraz Fallback | HTTP:' . $http_code . ' | ' . $raw );
		$body = json_decode( $raw, true );
		if ( $http_code === 200 || $http_code === 201 ) {
			return ! isset( $body['status'] ) || $body['status'] === 'success' || $body['status'] === true;
		}
		return false;
	}

	// --- Meli Payamak : Simple ---
	private function send_melipayamak( string $to, string $message ): bool {
		$url      = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
		$username = $this->meli_username ?: explode( ':', $this->api_key . ':' )[0];
		$password = $this->meli_password ?: ( explode( ':', $this->api_key . ':' )[1] ?? '' );

		$res = wp_remote_post( $url, [
			'timeout' => 15,
			'body'    => [
				'username' => $username,
				'password' => $password,
				'to'       => $to,
				'from'     => $this->sender,
				'text'     => $message,
				'isFlash'  => 'false',
			],
		] );

		if ( is_wp_error( $res ) ) {
			error_log( 'SN MeliPayamak Error: ' . $res->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return isset( $body['RetStatus'] ) && (int) $body['RetStatus'] === 1;
	}

	// --- Meli Payamak : Pattern (الگوی اشتراکی) ---
	public function send_melipayamak_pattern( string $to, int $body_id, array $vars ): bool {
		if ( empty( $this->meli_username ) || empty( $this->meli_password ) || ! $body_id ) {
			error_log( 'SN MeliPayamak Pattern: تنظیمات ناقص (username/password/bodyId)' );
			return false;
		}

		// متغیرها با ; جدا می‌شوند
		$text = implode( ';', $vars );

		$payload = [
			'username' => $this->meli_username,
			'password' => $this->meli_password,
			'text'     => $text,
			'to'       => $to,
			'bodyId'   => $body_id,
		];

		$res = wp_remote_post(
			'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
			[
				'timeout' => 15,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			]
		);

		if ( is_wp_error( $res ) ) {
			error_log( 'SN MeliPayamak Pattern Error: ' . $res->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		// ارسال موفق: RetStatus=1 و Value بیشتر از ۱۵ رقم
		if (
			isset( $body['RetStatus'] ) &&
			(int) $body['RetStatus'] === 1 &&
			isset( $body['Value'] ) &&
			strlen( (string) $body['Value'] ) > 15
		) {
			return true;
		}

		$error_code = $body['Value'] ?? 'unknown';
		error_log( 'SN MeliPayamak Pattern Failed — کد خطا: ' . $error_code . ' | پاسخ: ' . wp_json_encode( $body, JSON_UNESCAPED_UNICODE ) );
		return false;
	}
}
