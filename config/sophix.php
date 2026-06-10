<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform identity
    |--------------------------------------------------------------------------
    */
    'name' => 'SOPHIX Core BSS',
    'version' => env('SOPHIX_VERSION', 'v3'),

    /*
    |--------------------------------------------------------------------------
    | Default operator / region context (multi-operator, FE-APP-00 §4)
    |--------------------------------------------------------------------------
    */
    'default_operator' => env('SOPHIX_DEFAULT_OPERATOR', 'WIK'),
    'default_country' => env('SOPHIX_DEFAULT_COUNTRY', 'KE'),
    'default_currency' => env('SOPHIX_DEFAULT_CURRENCY', 'KES'),
    'default_timezone' => env('SOPHIX_DEFAULT_TIMEZONE', 'Africa/Nairobi'),

    /*
    |--------------------------------------------------------------------------
    | Foundation driver selection (Laravel-native, swappable)
    |--------------------------------------------------------------------------
    | event_bus : outbox | kafka   (FOUNDATION_KAFKA)
    | workflow  : native | camunda (FOUNDATION_CAMUNDA)
    | rules     : native | drools  (FOUNDATION_DROOLS)
    */
    'event_bus' => env('SOPHIX_EVENT_BUS', 'outbox'),
    'workflow_driver' => env('SOPHIX_WORKFLOW_DRIVER', 'native'),
    'rules_driver' => env('SOPHIX_RULES_DRIVER', 'native'),
    'provisioning_driver' => env('SOPHIX_PROVISIONING_DRIVER', 'stub'),
    'tax_driver' => env('SOPHIX_TAX_DRIVER', 'stub'),
    'sms_driver' => env('SOPHIX_SMS_DRIVER', 'stub'),

    'kafka' => [
        'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
        'topic_prefix' => env('KAFKA_TOPIC_PREFIX', 'sophix'),
    ],

    'camunda' => [
        'base_url' => env('CAMUNDA_BASE_URL', 'http://camunda:8080/engine-rest'),
    ],

    'drools' => [
        'base_url' => env('DROOLS_BASE_URL', 'http://drools:8080/kie-server'),
        'user' => env('DROOLS_USER', 'sophix'),
        'password' => env('DROOLS_PASSWORD', ''),
        'timeout_seconds' => (int) env('DROOLS_TIMEOUT_SECONDS', 3), // DROOLS-CALL-1
        /*
        | DROOLS-VER-5: callers PIN container ids — no floating to latest.
        | Keyed by rule-set prefix (longest match wins); {operator} is replaced
        | with the lowercased operator code (container naming §8:
        | {module}-rules-{operator}_{version}).
        */
        'containers' => [
            'rules.subscription' => [
                'container' => 'subscription-rules-{operator}_1.0.0',
                'lookup' => 'subscription-session',
                'fact_class' => 'com.sophix.subscription.facts.RequestFact',
            ],
            'rules.billing' => [
                'container' => 'billing-rules-{operator}_1.0.0',
                'lookup' => 'billing-session',
                'fact_class' => 'com.sophix.billing.facts.RequestFact',
            ],
        ],
    ],

    'rules' => [
        // In-process memo of resolved decision tables (NOT Redis — a module never
        // caches data it owns, FOUNDATION_CACHE §5). Bounds policy-edit staleness
        // in long-running workers. 0 disables.
        'memo_seconds' => (int) env('SOPHIX_RULES_MEMO_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | API conventions (DD_API-00)
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_size' => 50,
        'max_size' => 200,
    ],
];
