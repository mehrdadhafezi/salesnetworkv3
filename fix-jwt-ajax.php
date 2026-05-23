<?php
/**
 * این فایل رو در wp-content/mu-plugins/fix-jwt-ajax.php کپی کن
 * Must-use plugin: قبل از همه پلاگین‌ها لود میشه
 * جلوگیری از تداخل JWT با AJAX
 */
if ( defined('DOING_AJAX') && DOING_AJAX ) {
    // بلاک کردن JWT output در AJAX
    add_filter( 'jwt_auth_do_jwt_check', '__return_false', PHP_INT_MAX );
    
    // حذف action هایی که JWT به rest_api_init اضافه میکنه
    add_action( 'setup_theme', function() {
        if ( class_exists('Jwt_Auth_Public') ) {
            $jwt = new Jwt_Auth_Public('jwt-authentication-for-wp-rest-api', '1.0');
            remove_action( 'init', [ $jwt, 'add_api_routes' ] );
            remove_filter( 'rest_api_init', [ $jwt, 'add_api_routes' ] );
        }
    }, 1 );
    
    // شروع output buffering قبل از همه چیز
    ob_start();
}
