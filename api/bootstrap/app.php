<?php

use App\Services\IdempotencyConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Pour les routes API : retourner un 401 JSON au lieu de rediriger
        // vers la route "login" (qui n'existe pas dans une API pure).
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->is('broadcasting/*')) {
                abort(401, 'Unauthenticated.');
            }
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Conflit d'idempotence -> 409, pas 500 : c'est une situation que le
        // client peut comprendre et corriger (il réessaiera avec une nouvelle
        // clé), pas une panne serveur. Traité globalement pour que tout
        // endpoint protégé par `IdempotencyService` en bénéficie.
        $exceptions->render(function (IdempotencyConflictException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'error'   => [
                    'code'    => 'idempotency_conflict',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        });
    })->create();
