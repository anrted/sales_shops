<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withSchedule(function (): void {
        Schedule::command('discounts:parse --chain=magnit')->everySixHours()->withoutOverlapping();
        Schedule::command('discounts:parse --chain=metro')->everyTwoHours()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $requestId = (string) \Illuminate\Support\Str::uuid();
                $statusCode = 500;
                $errorCode = 'INTERNAL_SERVER_ERROR';
                $message = 'Произошла внутренняя ошибка сервера.';

                if ($e instanceof \App\Exceptions\ApiException) {
                    $statusCode = $e->getStatusCode();
                    $errorCode = $e->getErrorCode();
                    $message = $e->getMessage();
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                    $errorCode = 'VALIDATION_FAILED';
                    $message = $e->getMessage();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $statusCode = 401;
                    $errorCode = 'UNAUTHENTICATED';
                    $message = 'Необходима авторизация.';
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $statusCode = 404;
                    $errorCode = 'RESOURCE_NOT_FOUND';
                    $message = 'Запрошенный ресурс не найден.';
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $statusCode = $e->getStatusCode();
                    $errorCode = 'HTTP_ERROR_'.$statusCode;
                    $message = $e->getMessage() ?: 'Ошибка обращения к серверу.';
                }

                \Illuminate\Support\Facades\Log::error($e->getMessage(), [
                    'request_id' => $requestId,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
                ]);

                $response = [
                    'success' => false,
                    'message' => $message,
                    'error_code' => $errorCode,
                    'request_id' => $requestId,
                ];

                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $response['errors'] = $e->errors();
                }

                if (config('app.debug')) {
                    $response['debug'] = [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ];
                }

                return response()->json($response, $statusCode);
            }
        });
    })
    ->create();
