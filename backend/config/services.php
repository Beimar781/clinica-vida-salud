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

    // Correo en producción (Railway bloquea el SMTP): ver app/Support/BrevoTransport.php
    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

    // Pago con tarjeta (CU-09 y CU-11): Stripe Checkout alojado por API REST, sin SDK. Sin STRIPE_SECRET el
    // método tarjeta queda deshabilitado y el efectivo sigue funcionando. Moneda y tipo de cambio: config/vidasalud.php
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
    ],

    // Resumen con IA de los reportes (CU-18, RNF09): Google Gemini; solo recibe totales agregados.
    'ia' => [
        'gemini_key' => env('GEMINI_API_KEY'),
        'modelo' => env('IA_MODELO', 'gemini-3.5-flash-lite'),
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

];
