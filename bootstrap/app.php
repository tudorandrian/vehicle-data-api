<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Sentry\Laravel\Integration;
use VehicleData\Core\Http\Middleware\AuthenticateClient;
use VehicleData\Core\Http\Middleware\ClientCors;
use VehicleData\Core\Http\Middleware\ClientCorsPreflight;
use VehicleData\Core\Http\Middleware\DeprecationHeaders;
use VehicleData\Core\Http\Middleware\Etag;
use VehicleData\Core\Http\Middleware\LimitBodySize;
use VehicleData\Core\Http\Middleware\LogRequest;
use VehicleData\Core\Http\Middleware\RecordUsage;
use VehicleData\Core\Http\Middleware\RequestId;
use VehicleData\Core\Http\Middleware\RequireScope;
use VehicleData\Core\Http\Middleware\SecurityHeaders;
use VehicleData\Core\Http\Middleware\ThrottleClient;
use VehicleData\Core\Http\Middleware\TrustProxiesFromConfig;
use VehicleData\Core\Http\Problem\ProblemRenderer;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NOTE: never call config() in this closure — the HTTP kernel resolves
        // it before configuration is loaded under a real web server (only the
        // console kernel happens to boot config first, which is why a
        // config()-based trustProxies() call would pass tests but 500 on
        // every real request). TrustProxiesFromConfig reads
        // `core.trusted_proxies` at request time instead, inside handle().
        $middleware->replace(TrustProxies::class, TrustProxiesFromConfig::class);

        // Only the host of APP_URL is trusted (no subdomains): OpenApiController writes the
        // request's scheme and host into servers[0].url, so a forged Host header must be rejected
        // rather than reflected. Laravel applies this outside the `local` and `testing`
        // environments. The patterns are a closure, evaluated per request — config is not loaded
        // yet when this closure runs (see the note above).
        $middleware->trustHosts(at: static function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return is_string($host) && $host !== '' ? ['^'.preg_quote($host).'$'] : [];
        }, subdomains: false);

        // RequestId and SecurityHeaders are registered GLOBALLY (not only via
        // the 'core' route-group middleware) so they still apply when routing
        // itself fails to produce a matched route — an unmatched-route 404 or
        // a wrong-verb 405 never reaches a route's own middleware pipeline.
        // Both middleware internally no-op outside the v1/openapi.yaml/docs
        // paths. They are also listed in the 'core' group below for the
        // documented group interface; both are idempotent so running twice
        // on a matched /v1 route is harmless.
        //
        // ClientCorsPreflight is prepended for the same reason and answers
        // `OPTIONS /v1/*` before the router ever sees the request — a real
        // `Route::options('v1/{any}', …)` route was tried first but made
        // Laravel's router treat every unmatched GET/POST/etc. under /v1 as
        // 405 instead of 404, because a route existed for *some* method on
        // that URI (see that middleware's docblock).
        //
        // LogRequest is global for the same reason: it must also cover
        // /docs, which (routes/web.php) sits outside every route group. It
        // self-guards to the API surface (AppliesToCorePaths) and steps
        // aside for `data`-group routes, which RecordUsage already logs.
        $middleware->prepend([RequestId::class, SecurityHeaders::class, ClientCorsPreflight::class, LogRequest::class]);

        $middleware->group('core', [RequestId::class, LimitBodySize::class, SecurityHeaders::class, DeprecationHeaders::class, SubstituteBindings::class]);
        $middleware->group('data', ['core', RecordUsage::class, AuthenticateClient::class, ThrottleClient::class, ClientCors::class]);
        $middleware->alias([
            'request.id' => RequestId::class, 'security.headers' => SecurityHeaders::class, 'body.limit' => LimitBodySize::class,
            'auth.client' => AuthenticateClient::class, 'scope' => RequireScope::class, 'throttle.client' => ThrottleClient::class,
            'cors.client' => ClientCors::class, 'usage.record' => RecordUsage::class, 'etag' => Etag::class,
            'deprecation' => DeprecationHeaders::class,
        ]);

        // Middleware priority for the API surface.
        // SecurityHeaders sits directly after RequestId, before any auth/throttle
        // middleware, so 401/403/429 responses still carry the
        // hardening headers even though they also self-apply them via
        // Problem::response()/SecurityHeaders::headers().
        //
        // ThrottleClient runs before RequireScope so a
        // scope-403 still consumes the client's rate limit — otherwise a
        // caller with a valid key but the wrong scope could hammer a route
        // for free, since RequireScope would short-circuit before the
        // throttle ever counted the request.
        $middleware->priority([
            RequestId::class, SecurityHeaders::class, LimitBodySize::class, DeprecationHeaders::class, RecordUsage::class,
            AuthenticateClient::class, ThrottleClient::class, RequireScope::class, ClientCors::class, Etag::class, SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Optional: only reports to Sentry when SENTRY_LARAVEL_DSN is set (config/sentry.php);
        // otherwise this is a no-op and nothing is captured or sent over the network.
        Integration::handles($exceptions);
        $exceptions->render(fn (Throwable $e, Request $request) => ProblemRenderer::render($e, $request));
    })->create();
