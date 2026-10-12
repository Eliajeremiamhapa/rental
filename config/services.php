<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'africastalking' => [
        // API key from https://account.africastalking.com (sandbox or live).
        'username' => env('AT_USERNAME', 'sandbox'),
        'key' => env('AT_API_KEY'),
        'sender' => env('AT_SENDER', ''),
        'base_url' => env('AT_BASE_URL', 'https://api.africastalking.com/version1/messaging'),
    ],

    'receipt' => [
        // Platform-level fallback branding. Each landlord can still override the
        // name/contact from their own settings; this is the default shown on the
        // public site and on documents when no owner branding is available.
        'company_name' => env('RECEIPT_COMPANY_NAME', 'Nipangishe'),
        'company_slogan' => env('RECEIPT_COMPANY_SLOGAN', 'Makazi yako, hatua moja karibu'),
        'company_phone' => env('RECEIPT_COMPANY_PHONE', ''),
        'company_address' => env('RECEIPT_COMPANY_ADDRESS', ''),
        'logo_path' => env('RECEIPT_LOGO_PATH', 'branding/logo.png'),
        'signature_path' => env('RECEIPT_SIGNATURE_PATH', 'branding/signature.png'),
    ],

];
