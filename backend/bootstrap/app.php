<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            RateLimiter::for('login', fn (Request $request) => [
                Limit::perMinute(5)->by(mb_strtolower((string) $request->input('login')).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ]);
            RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [SetLocale::class]);
        // Token auth only (Bearer) — no cookies, so no CSRF surface for the API.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([BusinessException::class]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Never leak technical details: every API error gets a friendly, localized message.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof BusinessException => response()->json([
                    'success' => false,
                    'code' => $e->errorCode,
                    'message' => $e->localizedMessage(),
                    'meta' => $e->params,
                ], $e->status),
                $e instanceof ValidationException => response()->json([
                    'success' => false,
                    'code' => 'VALIDATION',
                    'message' => __('messages.validation'),
                    'errors' => $e->errors(),
                ], 422),
                $e instanceof AuthenticationException => response()->json([
                    'success' => false, 'code' => 'UNAUTHENTICATED', 'message' => __('messages.unauthenticated'),
                ], 401),
                $e instanceof ThrottleRequestsException => response()->json([
                    'success' => false, 'code' => 'TOO_MANY', 'message' => __('messages.too_many'),
                ], 429),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => response()->json([
                    'success' => false, 'code' => 'NOT_FOUND', 'message' => __('messages.not_found'),
                ], 404),
                $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => response()->json([
                    'success' => false, 'code' => 'HTTP_'.$e->getStatusCode(), 'message' => __('messages.generic'),
                ], $e->getStatusCode()),
                default => (function () use ($e, $request) {
                    $correlationId = $request->header('X-Correlation-ID')
                        ?: $request->header('X-Request-ID')
                        ?: (string) \Illuminate\Support\Str::uuid();

                    $category = match (true) {
                        $e instanceof \PDOException => 'database_connection',
                        $e instanceof \Illuminate\Database\QueryException => 'database_query',
                        str_contains(get_class($e), 'Token') => 'token_creation',
                        default => 'server_error',
                    };

                    // Strip any database credentials or password fragments from logged message
                    $safeMessage = preg_replace('/:[^:@\s]+@/', ':***@', $e->getMessage());

                    \Illuminate\Support\Facades\Log::error('API Server Error', [
                        'correlation_id' => $correlationId,
                        'method' => $request->method(),
                        'path' => $request->path(),
                        'category' => $category,
                        'exception' => get_class($e),
                        'error_code' => $e->getCode(),
                        'message' => \Illuminate\Support\Str::limit($safeMessage, 300),
                        'timestamp' => now()->toIso8601String(),
                    ]);

                    return response()->json(array_filter([
                        'success' => false,
                        'code' => 'SERVER_ERROR',
                        'message' => __('messages.generic'),
                        'correlation_id' => $correlationId,
                        'debug' => config('app.debug') ? $safeMessage : null,
                    ]), 500)->header('X-Correlation-ID', $correlationId);
                })(),
            };
        });
    })->create();
