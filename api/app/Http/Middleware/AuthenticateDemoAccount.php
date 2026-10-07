<?php

namespace App\Http\Middleware;

use App\Models\DemoAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDemoAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('Authorization', '');

        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return response()->json([
                'demo' => true,
                'message' => 'Jeton de démonstration requis.',
            ], 401);
        }

        $session = DemoAccessToken::with('account')
            ->where('token_hash', hash('sha256', trim($matches[1])))
            ->where('expires_at', '>', now())
            ->first();

        if (! $session || ! $session->account) {
            return response()->json([
                'demo' => true,
                'message' => 'Session de démonstration invalide ou expirée.',
            ], 401);
        }

        $session->forceFill(['updated_at' => now()])->save();
        $request->attributes->set('demo_account', $session->account);
        $request->attributes->set('demo_access_token', $session);

        return $next($request);
    }
}
