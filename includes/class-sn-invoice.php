<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * مدیریت فاکتور و درگاه زرین‌پال
 */
class SN_Invoice {

	private bool   $sandbox;
	private string $merchant_id;

	public function __construct() {
		$this->sandbox     = (string) get_option( 'sn_zarinpal_sandbox', '0' ) === '1';
		$this->merchant_id = get_option( 'sn_zarinpal_merchant', '' );
	}

	/** دریافت فاکتور با ID */
	public function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id = %d", $id
		) );
	}

	/** بروزرسانی وضعیت فاکتور */
	public function update_status( int $id, string $status, array $extra = [] ): void {
		global $wpdb;
		$data = array_merge( [ 'status' => $status ], $extra );
		$wpdb->update( $wpdb->prefix . 'sn_invoices', $data, [ 'id' => $id ] );
	}

	/** ایجاد درخواست پرداخت زرین‌پال - برگرداندن URL */
	public function zarinpal_request( int $invoice_id, float $amount, string $description, string $mobile, string $callback_url ): array {
		$api_url = $this->sandbox
			? 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
			: 'https://api.zarinpal.com/pg/v4/payment/request.json';

		$res = wp_remote_post( $api_url, [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'    => wp_json_encode( [
				'merchant_id'  => $this->merchant_id,
				'amount'       => (int) ( $amount * 10 ), // تومان به ریال
				'description'  => $description,
				'callback_url' => $callback_url,
				'metadata'     => [ 'mobile' => $mobile ],
			] ),
		] );

		if ( is_wp_error( $res ) ) {
			return [ 'error' => $res->get_error_message() ];
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['data']['authority'] ) ) {
			return [ 'error' => 'خطا در ارتباط با درگاه' ];
		}

		$authority = $body['data']['authority'];

		// ذخیره در جدول پرداخت‌ها
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'sn_payments', [
			'invoice_id' => $invoice_id,
			'authority'  => $authority,
			'amount'     => $amount,
			'status'     => 'pending',
		] );

		$gateway_url = $this->sandbox
			? "https://sandbox.zarinpal.com/pg/StartPay/{$authority}"
			: "https://www.zarinpal.com/pg/StartPay/{$authority}";

		return [ 'url' => $gateway_url, 'authority' => $authority ];
	}

	/** تایید پرداخت زرین‌پال */
	public function zarinpal_verify( string $authority, float $amount ): array {
		$api_url = $this->sandbox
			? 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json'
			: 'https://api.zarinpal.com/pg/v4/payment/verify.json';

		$res = wp_remote_post( $api_url, [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'    => wp_json_encode( [
				'merchant_id' => $this->merchant_id,
				'amount'      => (int) ( $amount * 10 ),
				'authority'   => $authority,
			] ),
		] );

		if ( is_wp_error( $res ) ) {
			return [ 'success' => false, 'error' => $res->get_error_message() ];
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$code = $body['data']['code'] ?? -1;

		if ( in_array( $code, [ 100, 101 ], true ) ) {
			$ref_id = $body['data']['ref_id'] ?? '';
			return [ 'success' => true, 'ref_id' => $ref_id, 'already_paid' => $code === 101 ];
		}

		return [ 'success' => false, 'code' => $code ];
	}
}
