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

    // Stripe (Fase 9, assinaturas.md): SO por variavel de ambiente. Nada disso
    // vai para o banco, para o frontend ou para o log. Sem a chave secreta, a
    // assinatura online nao aparece (R-25); sem o segredo do webhook, todo
    // evento e recusado.
    // Resend (D-05): chave SO no ambiente; vazia = provedor nao configurado.
    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'api_base' => env('RESEND_API_BASE', 'https://api.resend.com'),
        'timeout' => (int) env('RESEND_TIMEOUT', 10),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Versao da API fixada (o endpoint do webhook no painel do Stripe deve
        // usar a mesma versao). Leitura dos objetos tolera os formatos novos.
        'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
        // Janela da assinatura do webhook (protecao contra reenvio antigo).
        'tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'currency' => 'brl',
        'timeout' => 15,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
