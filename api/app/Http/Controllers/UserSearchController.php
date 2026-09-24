<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    /**
     * GET /api/v1/users/search?q=...
     *
     * Recherche des utilisateurs par numéro de téléphone, nom ou username.
     * L'utilisateur courant est exclu des résultats.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'     => 'required|string|min:2|max:60',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $q = trim($validated['q']);
        $limit = $validated['limit'] ?? 20;

        $users = User::where('id', '!=', $request->user()->id)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->where(function ($query) use ($q) {
                $query->where('phone_number', 'like', '%' . $q . '%')
                    ->orWhere('name', 'like', '%' . $q . '%')
                    ->orWhere('username', 'like', '%' . $q . '%');
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'phone_number', 'name', 'username', 'phone_verified', 'role'])
            ->unique('id')
            ->values();

        return response()->json([
            'data' => $users,
        ]);
    }
}
