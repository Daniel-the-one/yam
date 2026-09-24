<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CallOfferController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DebugController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\SignalController;
use App\Http\Controllers\UserSearchController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AppelController;
use App\Http\Controllers\WalletController;

// ---------------------------------------------------------
// Routes publiques (pas d'auth requise)
// ---------------------------------------------------------
Route::get('/v1/config', [ConfigController::class, 'index']);
Route::get('/v1/call/{call_id}/offer', [CallOfferController::class, 'show'])
    ->middleware('throttle:30,1');

// Diagnostic TEMPORAIRE — exposé UNIQUEMENT si APP_DEBUG=true (jamais en prod)
if (config('app.debug')) {
    Route::get('/v1/debug/logs', [DebugController::class, 'logs']);
}

// Auth (avec limitation de débit anti-force brute)
Route::post('/v1/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ---------------------------------------------------------
// Routes protégées par Sanctum (accès sécurisé)
// ---------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/v1/auth/logout', [AuthController::class, 'logout']);
    Route::get('/v1/users/me', [AuthController::class, 'me']);
    Route::get('/v1/users/search', [UserSearchController::class, 'search']);
    Route::get('/v1/contacts', [ContactController::class, 'index']);

    // Registre d'appareils de l'utilisateur connecté
    Route::get('/v1/devices', [DeviceController::class, 'index']);
    Route::post('/v1/devices/register', [DeviceController::class, 'register']);
    Route::delete('/v1/devices/{deviceId}', [DeviceController::class, 'destroy']);

    // Flux d'appels & signalisation
    Route::post('/v1/call/ring', [CallController::class, 'ring']);
    Route::post('/v1/call/cancel', [CallController::class, 'cancel']);
    Route::post('/v1/call/signal', [SignalController::class, 'signal']);

    //Module appels facturés (patients/medecin/solde)
    Route::post('/v1/appels/init', [AppelController::class, 'init'])->middleware('throttle:20,1');
    Route::post('/v1/appels/{appel}/lancer', [AppelController::class, 'lancer'])->middleware('throttle:20,1');
    Route::post('/v1/appels/{appel}/decrocher', [AppelController::class, 'decrocher'])->middleware('throttle:20,1');
    Route::post('/v1/appels/{appel}/terminer', [AppelController::class, 'terminer'])->middleware('throttle:20,1');
    Route::post('/v1/appels/{appel}/heartbeat', [AppelController::class, 'heartbeat'])->middleware('throttle:60,1');
    Route::get('/v1/appels/{appel}', [AppelController::class, 'show'])->middleware('throttle:60,1');

    // Wallet (API externe) — formats alignés sur api/api externe/*.json
    Route::get('/v1/wallet', [WalletController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/v1/wallet/recharge', [WalletController::class, 'recharge'])->middleware('throttle:10,1');
    Route::post('/v1/wallet/transfert', [WalletController::class, 'transfert'])->middleware('throttle:20,1');
    Route::get('/v1/wallet/transactions', [WalletController::class, 'transactions'])->middleware('throttle:60,1');
});
