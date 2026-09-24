<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => ['message' => 'Token manquant.'],
            ], 401);
        }

        $user = User::where('api_token', $token)->first();

        if (!$user) {
            return response()->json([
                'error' => ['message' => 'Token invalide.'],
            ], 401);
        }

        // Stocker l'utilisateur dans les attributs de la requête
        $request->attributes->add(['auth_user' => $user]);

        return $next($request);
    }
}
