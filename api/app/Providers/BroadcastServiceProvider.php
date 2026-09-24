<?php

namespace App\Providers;

use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Enregistre la route d'authentification des canaux de broadcast
 * (POST /broadcasting/auth) et charge la définition des canaux.
 *
 * NB : on définit la route MANUELLEMENT avec le middleware auth:sanctum au
 * lieu d'utiliser Broadcast::routes() : le framework (bootstrap/app.php →
 * withRouting(channels:)) appelle Broadcast::routes() avec le middleware
 * 'web', ce qui écrase notre configuration. Or le guard web (session) ne
 * reconnaît pas les tokens Bearer Sanctum → 403 systématique.
 *
 * Pour éviter ce conflit, on a retiré `channels:` de withRouting() dans
 * bootstrap/app.php : le framework n'appelle donc plus Broadcast::routes()
 * du tout, et ce provider est l'UNIQUE endroit qui définit la route et
 * charge les canaux. Plus de hook booted() fragile ni de double require.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('auth:sanctum')
            ->match(['get', 'post'], '/broadcasting/auth', [BroadcastController::class, 'authenticate']);

        require base_path('routes/channels.php');
    }
}