<?php

use App\Foundation\Errors\DomainException as SophixDomainException;
use App\Foundation\Errors\ErrorCode;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Http\Middleware\CorrelationId;
use App\Foundation\Http\Middleware\EnforceIdempotency;
use App\Foundation\Http\Middleware\ResolveOperatorContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocaleFromOperator::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // SOPHIX API conventions (DD_API-00): correlation id + operator scope on
        // every API request; idempotency is opt-in per command route.
        $middleware->api(prepend: [
            CorrelationId::class,
            ResolveOperatorContext::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\SetLocaleFromOperator::class,
        ]);

        $middleware->alias([
            'idempotency' => EnforceIdempotency::class,
            'operator' => ResolveOperatorContext::class,
            'correlation' => CorrelationId::class,
            // spatie/laravel-permission (DD_EM-CFG-03 enforcement)
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render the SOPHIX standard error model for API/JSON requests (DD_API-00 §8).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // let web/Inertia handle it normally
            }

            return match (true) {
                $e instanceof SophixDomainException => ApiResponse::error(
                    $e->errorCode, $e->getMessage(), $e->status, $e->retryable, $e->fieldErrors, $e->nextAction,
                ),
                $e instanceof ValidationException => ApiResponse::error(
                    ErrorCode::VALIDATION_FAILED,
                    'The given data was invalid.',
                    422,
                    false,
                    collect($e->errors())->flatMap(fn ($messages, $field) => array_map(
                        fn ($m) => ['field' => $field, 'code' => 'INVALID', 'message' => $m],
                        $messages,
                    ))->values()->all(),
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    ErrorCode::UNAUTHENTICATED, 'Authentication required.', 401,
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    ErrorCode::FORBIDDEN, 'You do not have permission to perform this action.', 403,
                ),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(
                    ErrorCode::NOT_FOUND, 'Resource not found.', 404,
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    ErrorCode::INTERNAL_ERROR, $e->getMessage() ?: 'Request failed.', $e->getStatusCode(),
                ),
                default => null, // fall back to framework handling (debug-aware)
            };
        });
    })->create();
