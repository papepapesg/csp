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
