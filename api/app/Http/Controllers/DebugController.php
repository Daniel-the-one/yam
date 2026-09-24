<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Contrôleur de diagnostic TEMPORAIRE.
 * Expose les logs Reverb et l'état des processus pour déboguer
 * le HTTP 500 du handshake WebSocket. À SUPPRIMER avant la mise en prod.
 */
class DebugController extends Controller
{
    public function logs(): JsonResponse
    {
        $reverbLog = file_exists('/tmp/reverb.log')
            ? file_get_contents('/tmp/reverb.log')
            : '(fichier /tmp/reverb.log introuvable)';

        // Log Laravel (peut contenir l'erreur de handshake Reverb)
        $laravelLog = '';
        $laravelLogPath = storage_path('logs/laravel.log');
        if (file_exists($laravelLogPath)) {
            $laravelLog = file_get_contents($laravelLogPath);
            // Garder les 200 dernières lignes
            $lines = explode("\n", $laravelLog);
            $laravelLog = implode("\n", array_slice($lines, -200));
        } else {
            $laravelLog = '(fichier laravel.log introuvable)';
        }

        // Log nginx (erreurs de proxy)
        $nginxLog = '';
        if (file_exists('/tmp/nginx_error.log')) {
            $nginxLog = file_get_contents('/tmp/nginx_error.log');
        } else {
            $nginxLog = '(fichier nginx_error.log introuvable)';
        }

        // Vérifier si Reverb écoute sur 6001
        $reverbListening = @fsockopen('127.0.0.1', 6001, $errno, $errstr, 2);
        $reverbStatus = $reverbListening
            ? 'OK (port 6001 ouvert)'
            : "KO (port 6001 fermé : $errstr)";
        if ($reverbListening) {
            fclose($reverbListening);
        }

        // Vérifier si l'API écoute sur 8000
        $apiListening = @fsockopen('127.0.0.1', 8000, $errno2, $errstr2, 2);
        $apiStatus = $apiListening
            ? 'OK (port 8000 ouvert)'
            : "KO (port 8000 fermé : $errstr2)";
        if ($apiListening) {
            fclose($apiListening);
        }

        // Extensions PHP chargées (pertinent pour Reverb)
        $extensions = [
            'pcntl' => extension_loaded('pcntl'),
            'sockets' => extension_loaded('sockets'),
            'mbstring' => extension_loaded('mbstring'),
            'bcmath' => extension_loaded('bcmath'),
            'openssl' => extension_loaded('openssl'),
        ];

        // Variables d'environnement Reverb (masquées)
        $env = [
            'REVERB_APP_KEY' => env('REVERB_APP_KEY', '(non défini)'),
            'REVERB_APP_SECRET' => env('REVERB_APP_SECRET', '(non défini)'),
            'REVERB_APP_ID' => env('REVERB_APP_ID', '(non défini)'),
            'REVERB_SERVER_HOST' => env('REVERB_SERVER_HOST', '(non défini)'),
            'REVERB_SERVER_PORT' => env('REVERB_SERVER_PORT', '(non défini)'),
            'REVERB_HOST' => env('REVERB_HOST', '(non défini)'),
            'REVERB_PORT' => env('REVERB_PORT', '(non défini)'),
            'REVERB_SCHEME' => env('REVERB_SCHEME', '(non défini)'),
            'APP_ENV' => env('APP_ENV', '(non défini)'),
            'APP_KEY' => env('APP_KEY', '(non défini)'),
        ];

        return response()->json([
            'reverb_log' => $reverbLog,
            'laravel_log' => $laravelLog,
            'nginx_error_log' => $nginxLog,
            'reverb_status' => $reverbStatus,
            'api_status' => $apiStatus,
            'extensions' => $extensions,
            'env' => $env,
            'php_version' => PHP_VERSION,
        ]);
    }
}