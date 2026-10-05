<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    /**
     * GET /api/v1/contacts
     *
     * Retourne les contacts enregistrés par l'utilisateur connecté.
     */
    public function index(Request $request): JsonResponse
    {
        $contacts = DB::table('user_contacts')
            ->join('users', 'users.id', '=', 'user_contacts.contact_user_id')
            ->where('user_contacts.user_id', $request->user()->id)
            ->whereNotNull('users.phone_number')
            ->select('users.id as user_id', 'users.name', 'users.username', 'users.phone_number', 'users.device_id', 'users.is_online')
            ->orderBy('users.name')
            ->get()
            ->map(function ($user) {
                return [
                    'user_id'      => $user->user_id,
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_user_id' => 'required|integer|exists:users,id|not_in:' . $request->user()->id,
        ]);

        DB::table('user_contacts')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'contact_user_id' => $validated['contact_user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request, int $contactUserId): JsonResponse
    {
        DB::table('user_contacts')
            ->where('user_id', $request->user()->id)
            ->where('contact_user_id', $contactUserId)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
