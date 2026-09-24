<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalise un numéro de téléphone en format E.164 (+228 par défaut).
     * Retire espaces, tirets, parenthèses, points ; convertit 00 → +.
     * Lève une exception si le résultat est vide (numéro invalide).
     */
    private function normalizePhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if ($clean === '') {
            throw new \InvalidArgumentException(
                'Numéro de téléphone invalide : "' . $phone . '"'
            );
        }

        if (str_starts_with($clean, '+')) {
            return $clean;
        }

        if (str_starts_with($clean, '00')) {
            return '+' . substr($clean, 2);
        }

        return '+228' . $clean;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            // ── 1. Normaliser TOUS les numéros d'abord ──────────────────
            // Une seule passe : on normalise tout, puis on fusionne. Évite
            // les doublons résiduels entre deux passes séquentielles.
            $users = DB::table('users')
                ->whereNotNull('phone_number')
                ->where('phone_number', '!=', '')
                ->orderBy('id')
                ->get(['id', 'phone_number']);

            foreach ($users as $user) {
                try {
                    $normalized = $this->normalizePhone($user->phone_number);
                    if ($normalized !== $user->phone_number) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['phone_number' => $normalized]);
                    }
                } catch (\InvalidArgumentException $e) {
                    // Numéro invalide (ex: "abc") : on le met à null pour
                    // éviter un doublon non détecté et une corruption.
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['phone_number' => null]);
                }
            }

            // ── 2. Fusionner les doublons (même numéro normalisé) ───────
            $duplicates = DB::table('users')
                ->select('phone_number')
                ->whereNotNull('phone_number')
                ->where('phone_number', '!=', '')
                ->groupBy('phone_number')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $dup) {
                $group = DB::table('users')
                    ->where('phone_number', $dup->phone_number)
                    ->orderBy('id')
                    ->get(['id', 'name', 'username', 'device_id']);

                $keepId = $group->first()->id;

                foreach ($group->skip(1) as $duplicate) {
                    // ── Rattacher les devices, en gérant les doublons de
                    // device_id (si le compte conservé a déjà un device avec
                    // le même device_id technique, on supprime le doublon).
                    $dupDevices = DB::table('devices')
                        ->where('user_id', $duplicate->id)
                        ->get(['id', 'device_id']);

                    foreach ($dupDevices as $dev) {
                        $existsOnKeep = DB::table('devices')
                            ->where('user_id', $keepId)
                            ->where('device_id', $dev->device_id)
                            ->exists();

                        if ($existsOnKeep) {
                            // Le keep a déjà ce device : supprimer le doublon.
                            DB::table('devices')->where('id', $dev->id)->delete();
                        } else {
                            // Rattacher le device au compte conservé.
                            DB::table('devices')
                                ->where('id', $dev->id)
                                ->update(['user_id' => $keepId]);
                        }
                    }

                    // ── Transférer les tokens Sanctum ───────────────────
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', 'App\Models\User')
                        ->where('tokenable_id', $duplicate->id)
                        ->update(['tokenable_id' => $keepId]);

                    // ── Transférer les call_offers routées par user ─────
                    DB::table('call_offers')
                        ->where('to_user_id', $duplicate->id)
                        ->update(['to_user_id' => $keepId]);

                    // ── Transférer les sessions (évite les orphelins) ───
                    DB::table('sessions')
                        ->where('user_id', $duplicate->id)
                        ->update(['user_id' => $keepId]);

                    // ── Supprimer le compte en double ────────────────────
                    DB::table('users')->where('id', $duplicate->id)->delete();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irréversible : on ne restaure pas les numéros ni les comptes fusionnés.
        throw new \RuntimeException('Cette migration est irréversible.');
    }
};
