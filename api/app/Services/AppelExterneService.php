<?php

namespace App\Services;

use App\Models\Appel;
use Illuminate\Support\Facades\Log;

/**
 * STUB du futur API externe de facturation des appels.
 *
 * En attendant que le vrai API externe soit fourni, cette classe journalise
 * localement les appels terminés (id, début, fin, durée, coût). Quand l'API
 * externe arrivera, il suffira de remplacer le corps de envoyerAppel() par
 * un appel HTTP (ex. Http::post($url, $payload)) sans toucher aux controllers.
 */
class AppelExterneService
{
    /**
     * Transmet un appel terminé à l'API externe de facturation.
     */
    public function envoyerAppel(Appel $appel): void
    {
        Log::info('[AppelExterneService] Appel à transmettre à l\'API externe', [
            'appel_id'      => $appel->id,
            'patient_id'    => $appel->patient_id,
            'medecin_id'    => $appel->medecin_id,
            'initie_par'    => $appel->initie_par,
            'status'        => $appel->status,
            'raison_fin'    => $appel->raison_fin,
            'date_debut'    => $appel->date_sonnerie?->toIso8601String(),
            'date_decroche' => $appel->date_decroche?->toIso8601String(),
            'date_fin'      => $appel->date_fin?->toIso8601String(),
            'duree_secondes' => $appel->date_decroche && $appel->date_fin
                ? $appel->date_decroche->diffInSeconds($appel->date_fin, true)
                : null,
            'tarif_par_minute' => (float) $appel->tarif_par_minute,
            'solde_consomme'   => (float) $appel->solde_consomme,
        ]);
    }
}