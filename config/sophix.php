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
    | auth      : sanctum | keycloak (FOUNDATION_AUTH)
    */
    'event_bus' => env('SOPHIX_EVENT_BUS', 'outbox'),
    'workflow_driver' => env('SOPHIX_WORKFLOW_DRIVER', 'native'),
    'rules_driver' => env('SOPHIX_RULES_DRIVER', 'native'),
    'provisioning_driver' => env('SOPHIX_PROVISIONING_DRIVER', 'stub'),
    'tax_driver' => env('SOPHIX_TAX_DRIVER', 'stub'),
    'sms_driver' => env('SOPHIX_SMS_DRIVER', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | Auth driver (FOUNDATION_AUTH). The api guard's driver is env-selected
    | (config/auth.php reads SOPHIX_AUTH_DRIVER), so switching a deployment from
    | local Sanctum tokens to Keycloak OIDC is ENV-ONLY — no code, no route change.
    | Modules verify Keycloak JWTs locally (RS256), never calling Keycloak per
    | request; users.uid = the token `sub`, the only Keycloak-aware column.
    |--------------------------------------------------------------------------
    */
    'auth' => [
        'driver' => env('SOPHIX_AUTH_DRIVER', 'sanctum'),
        'keycloak' => [
            'issuer' => env('KEYCLOAK_ISSUER'),                       // e.g. https://id.yas.sn/realms/sophix
            'audience' => env('KEYCLOAK_AUDIENCE'),                   // optional aud claim to require
            'realm_public_key' => env('KEYCLOAK_REALM_PUBLIC_KEY'),   // realm RS256 public key (PEM or base64 DER)
            'roles_claim' => env('KEYCLOAK_ROLES_CLAIM', 'realm_access.roles'),
            'leeway' => (int) env('KEYCLOAK_LEEWAY', 30),             // clock-skew seconds
        ],
    ],


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

    'workflow' => [
        // Output-declaration lint (FOUNDATION_CAMUNDA IO contract). When a step
        // returns variables it did not declare in outputs(), warn by default; set
        // true to make it a hard failure (recommended in CI to keep the typed,
        // wireable dataflow surface honest). Engine control flags are always exempt.
        'strict_outputs' => (bool) env('SOPHIX_WORKFLOW_STRICT_OUTPUTS', false),
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

    /*
    |--------------------------------------------------------------------------
    | NOT-01 Notification Service
    |--------------------------------------------------------------------------
    | Operator-tunable delivery behaviour. Per-operator overrides belong in the
    | operator's config; these are the platform defaults.
    */
    'notification' => [
        /*
        | adapter_implementation -> adapter class. The string is what operators put in
        | channel_operator_config; the class is the ChannelAdapter that handles it.
        | Adding a channel/gateway = write the adapter class + add one entry here
        | (or register() it from a service provider) — no registry/dispatcher edits.
        */
        'adapter_implementations' => [
            'smtp.default' => \Modules\Notification\Dispatch\Adapters\EmailAdapter::class,
            'smtp.transac' => \Modules\Notification\Dispatch\Adapters\EmailAdapter::class,
            'sms.default' => \Modules\Notification\Dispatch\Adapters\SmsAdapter::class,
            'sms.africastalking' => \Modules\Notification\Dispatch\Adapters\SmsAdapter::class,
            'sms.beemafrica' => \Modules\Notification\Dispatch\Adapters\SmsAdapter::class,
            'sms.orange-sn' => \Modules\Notification\Dispatch\Adapters\SmsAdapter::class,
            'sms.twilio' => \Modules\Notification\Dispatch\Adapters\SmsAdapter::class,
        ],
        // Fallback adapter_implementation per channel when an operator has no
        // channel_operator_config row yet (used only by the legacy imperative send()).
        'default_adapter' => [
            'EMAIL' => 'smtp.default',
            'SMS' => 'sms.default',
            'PUSH' => 'sms.default',
            'WHATSAPP' => 'sms.default',
        ],
        'default_locale' => env('SOPHIX_NOTIFICATION_LOCALE', 'en'),
        'timezone' => env('SOPHIX_NOTIFICATION_TZ', config('app.timezone', 'UTC')),
        'send_timeout_seconds' => (int) env('SOPHIX_NOTIFICATION_SEND_TIMEOUT', 15),
        // R-NOT-01-F-2: transient retry backoff (7 retries -> escalate).
        'retry_backoff_seconds' => [60, 300, 900, 1800, 3600, 7200, 14400],
        // R-NOT-01-D-7: render retry backoff (8 retries -> give up).
        'render_backoff_seconds' => [300, 900, 1800, 3600, 7200, 14400, 28800, 86400],
        // R-NOT-01-R-4: regulatory delivery windows (local time). Non-urgent messages
        // outside the window are deferred to the next window start.
        'window' => [
            'default' => ['start' => '00:00', 'end' => '23:59'],
            'SMS' => ['start' => '07:00', 'end' => '21:00'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ICN-01 Internal Communications (staff)
    |--------------------------------------------------------------------------
    | adapter_impl -> StaffChannelAdapter class. The string is what operators put
    | in staff_notification_adapter_binding.adapter_impl. Adding a provider/channel
    | = write the adapter class + add an entry here + insert a binding row.
    */
    /*
    |--------------------------------------------------------------------------
    | BIL-02-TAX-01 tax-authority signers
    |--------------------------------------------------------------------------
    | signing_service_implementation_ref -> TaxInvoiceSigner class. Onboarding a
    | new authority = a signer class + an entry here + the operator's
    | tax_operator_config row. No framework edit.
    */
    'tax' => [
        'signer_implementations' => [
            'stub' => \Modules\Billing\Tax\Signers\StubTaxSigner::class,
        ],
    ],

    'icn' => [
        'adapter_implementations' => [
            'smtp-classic' => \Modules\Notification\Icn\Adapters\SmtpClassicAdapter::class,
            'microsoft-graph-mail' => \Modules\Notification\Icn\Adapters\GraphMailAdapter::class,
            'slack-bot-api' => \Modules\Notification\Icn\Adapters\SlackBotAdapter::class,
            'msteams-incoming-webhook' => \Modules\Notification\Icn\Adapters\TeamsWebhookAdapter::class,
            'inapp-websocket-fanout' => \Modules\Notification\Icn\Adapters\InAppPushAdapter::class,
        ],
    ],
];
