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

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        // identity-linked な API キーはワークスペース ID が必須（不要なキーなら空でよい）。
        'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

        // 概算コスト計算用の単価（USD / 100 万トークン）。モデルを変えたら更新する。
        // 既定は claude-sonnet-5 の第一者 API レート。
        'input_cost_per_mtok' => (float) env('ANTHROPIC_INPUT_COST_PER_MTOK', 2.0),
        'output_cost_per_mtok' => (float) env('ANTHROPIC_OUTPUT_COST_PER_MTOK', 10.0),
    ],

];
