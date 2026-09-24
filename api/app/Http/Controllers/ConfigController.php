<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    /**
     * GET /api/v1/config
     *
     * Expose les valeurs runtime sans les coder en dur dans le frontend.
     * Cela permet aux appareils de fonctionner sur le même LAN ou à travers
     * Internet (4G/5G, différents réseaux Wi-Fi, tunnels HTTPS, NATs).
     */
    public function index(Request $request): JsonResponse
    {
        // Serveurs TURN pour franchir les NATs symétriques (4G / 5G / multi-réseaux)
        $turnUrl = config('yam.turn.url');
        $turnUsername = config('yam.turn.username');
        $turnCredential = config('yam.turn.credential');

        $iceServers = [
            ['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302', 'stun:stun2.l.google.com:19302']],
            ['urls' => ['stun:stun.cloudflare.com:3478']],
        ];

        if (!empty($turnUrl)) {
            $turnUrls = array_map('trim', explode(',', $turnUrl));
            foreach ($turnUrls as $tUrl) {
                if (!empty($tUrl)) {
                    $iceServers[] = [
                        'urls' => $tUrl,
                        'username' => $turnUsername,
                        'credential' => $turnCredential,
                    ];
                }
            }
        }

        return response()->json([
            'app' => [
                'name' => config('yam.app.name', 'Yam'),
            ],
            'pusher' => [
                'app_key' => config('broadcasting.connections.pusher.key'),
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
            ],
            'turn' => [
                'url' => $turnUrl,
                'username' => $turnUsername,
                'credential' => $turnCredential,
            ],
            'ice_servers' => $iceServers,
        ]);
    }
}

