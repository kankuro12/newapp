<?php

use App\Http\Controllers\McpController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // AI-agent MCP endpoint: bearer-token only, no session or CSRF.
        then: fn () => Route::post('mcp', [McpController::class, 'handle'])->middleware('throttle:60,1'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant.context' => ResolveTenant::class]);
        // The SPA owns sign-in; a signed-out visitor (for example from a verification email) returns afterwards.
        $middleware->redirectGuestsTo(fn (Request $request) => rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/signin?'.http_build_query(['next' => $request->getRequestUri()]));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (InvalidArgumentException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage(), 'errors' => ['form' => [$exception->getMessage()]]], 422);
            }
        });
    })->create();
