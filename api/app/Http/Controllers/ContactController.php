<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * GET /api/v1/contacts
     *
     * Retourne la liste des contacts enregistrés en excluant l'utilisateur connecté.
     */
    public function index(Request $request): JsonResponse
    {
        $currentUserId = $request->user()?->id;

        $contacts = User::query()
            ->when($currentUserId, fn ($q) => $q->where('id', '!=', $currentUserId))
            ->whereNotNull('phone_number')
            ->select('id', 'name', 'username', 'phone_number', 'device_id', 'is_online')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return [
                    'user_id'      => $user->id,
                    'name'         => $user->name,
                    'username'     => $user->username,
                    'phone_number' => $user->phone_number,
                    'device_id'    => $user->device_id,
                    'is_online'    => (bool) $user->is_online,
                ];
            });

        return response()->json([
            'contacts' => $contacts,
        ]);
    }
}
