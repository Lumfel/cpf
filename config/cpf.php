<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prototype Environment Isolation
    |--------------------------------------------------------------------------
    |
    | Controls whether the isolated prototype workspace (routes/prototype.php,
    | App\Http\Controllers\Prototype\*, and resources/js/prototype/*) is active.
    | Set CPF_PROTOTYPE_ENABLED=false in production to disable all prototype
    | routes without touching any main application code.
    |
    */
    'prototype' => [
        'enabled' => (bool) env('CPF_PROTOTYPE_ENABLED', env('APP_ENV', 'local') !== 'production'),
        'route_prefix' => env('CPF_PROTOTYPE_PREFIX', 'prototype'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blockchain Credentials Environment (Achievements, Certificates, Awards)
    |--------------------------------------------------------------------------
    |
    | Configures cryptographic hash-chaining and optional EVM smart-contract
    | anchoring for all CPF member achievements, digital certificates, and
    | awards. Default driver "local_ledger" maintains a tamper-evident SHA-256
    | hash chain inside the main database and can be upgraded to an EVM network
    | (e.g., polygon_amoy, polygon_mainnet, ethereum_sepolia) via environment.
    |
    */
    'blockchain' => [
        'enabled' => (bool) env('BLOCKCHAIN_ENABLED', true),
        'driver' => env('BLOCKCHAIN_DRIVER', 'local_ledger'),
        'network' => env('BLOCKCHAIN_NETWORK', 'cpf-local-chain'),
        'chain_id' => (int) env('BLOCKCHAIN_CHAIN_ID', 80002),
        'rpc_url' => env('BLOCKCHAIN_RPC_URL'),
        'contract_address' => env('BLOCKCHAIN_CONTRACT_ADDRESS'),
        'issuer_address' => env('BLOCKCHAIN_ISSUER_ADDRESS'),
        'explorer_base_url' => env('BLOCKCHAIN_EXPLORER_BASE_URL', 'https://amoy.polygonscan.com/tx/'),
        'hash_algorithm' => env('BLOCKCHAIN_HASH_ALGORITHM', 'sha256'),
    ],

    'google_forms' => [
        'webhook_secret' => env('GOOGLE_FORMS_WEBHOOK_SECRET', 'cpf-local-forms-secret'),
    ],

    'archive_path' => env('CPF_ARCHIVE_HDD_PATH', storage_path('app/archives')),

];
