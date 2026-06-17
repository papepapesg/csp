<?php

namespace App\Foundation\Providers;

use App\Foundation\Auth\KeycloakGuard;
use App\Foundation\Console\DispatchOutboxCommand;
use App\Foundation\Console\GeneratePostmanCommand;
use App\Foundation\Events\Drivers\KafkaEventBus;
use App\Foundation\Events\Drivers\OutboxEventBus;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\NativeRuleEngine;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Workflow\OperationManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the SOPHIX foundation: event bus, rules engine, workflow manager and
 * console commands. Driver bindings honour config/sophix.php so the Java-stack
 * components (Kafka/Camunda/Drools) can be swapped in without touching modules.
 */
class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventBus::class, function () {
            return config('sophix.event_bus') === 'kafka'
                ? new KafkaEventBus
                : new OutboxEventBus;
        });

        // FOUNDATION_CACHE: cache-aside helper over the configured Laravel store
        // (array/file in dev, Redis cluster in production — swappable via cache config,
        // same philosophy as the Kafka/Camunda/Drools driver bindings).
        $this->app->singleton(\App\Foundation\Cache\SophixCache::class, function ($app) {
            return new \App\Foundation\Cache\SophixCache(
                $app['cache']->store(config('sophix.cache_store') ?: null),
            );
        });

        // Default rule engine; the Rules module rebinds this to the data-driven
        // (decision-table) engine. singletonIf so module order doesn't clobber it.
        $this->app->singletonIf(RuleEngine::class, NativeRuleEngine::class);
        $this->app->singleton(OperationManager::class);
    }

    public function boot(): void
    {
        // FOUNDATION_AUTH seam: register the 'keycloak' guard driver. config/auth.php
        // points the `sanctum` guard at this driver when SOPHIX_AUTH_DRIVER=keycloak,
        // so the swap to Keycloak OIDC is env-only (no route/code change). When the
        // driver stays 'sanctum', this registration is simply never used.
        Auth::viaRequest('keycloak', fn ($request) => app(KeycloakGuard::class)->resolve($request));

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchOutboxCommand::class,
                GeneratePostmanCommand::class,
            ]);
        }
    }
}
