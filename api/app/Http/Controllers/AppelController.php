<?php

namespace App\Http\Controllers;

use App\Events\AppelLance;
use App\Models\Appel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppelController extends Controller
{
    /**
     * POST /api/v1/appels/init
     *
     * Crée un appel facturé (statut "initie").
     *  - l'appelant est l'utilisateur authentifié ;
     *  - son rôle détermine initie_par et qui est patient/médecin ;
     *  - un patient doit avoir un solde > 0 (sinon 402).
     */
    public function init(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination_user_id' => 'required|integer|exists:users,id',
        ]);

        // P1-3 : nettoyer les appels orphelins AVANT le contrôle d'appel actif.
        // Si terminer a échoué (réseau, page fermée), un appel peut rester
        // "decroche"/"initie"/"sonne" pour toujours → le couple ne pourrait
        // plus JAMAIS se rappeler (409 permanent). On auto-termine les appels
        // périmés : decroche sans heartbeat depuis 60 s, initie/sonne depuis
        // plus de 3 min.
        $this->nettoyerAppelsOrphelins();

        $appelant = $request->user();
        $destinataire = User::findOrFail($validated['destination_user_id']);

        if (!in_array($appelant->role, [Appel::INITIE_PAR_PATIENT, Appel::INITIE_PAR_MEDECIN], true)) {
            return response()->json([
                'error' => [
                    'code' => 'role_invalide',
                    'message' => 'Rôle utilisateur invalide.',
                ],
            ], 403);
        }

        if ($appelant->role === Appel::INITIE_PAR_PATIENT) {
            if ($destinataire->role !== Appel::INITIE_PAR_MEDECIN) {
                return response()->json([
                    'error' => [
                        'code' => 'destinataire_invalide',
                        'message' => 'Le destinataire doit être un médecin.',
                    ],
                ], 422);
            }

            if ((float) $appelant->solde <= 0) {
                return response()->json([
                    'error' => [
                        'code' => 'solde_insuffisant',
                        'message' => 'Solde insuffisant pour initier un appel.',
                    ],
                ], 402);
            }

            $patientId = $appelant->id;
            $medecinId = $destinataire->id;
        } else {
            if ($destinataire->role !== Appel::INITIE_PAR_PATIENT) {
                return response()->json([
                    'error' => [
                        'code' => 'destinataire_invalide',
                        'message' => 'Le destinataire doit être un patient.',
                    ],
                ], 422);
            }

            $patientId = $destinataire->id;
            $medecinId = $appelant->id;

            // Un médecin ne peut appeler qu'un patient avec qui il a déjà eu
            // un appel (décroché ou terminé). Il n'est PAS contraint par le
            // solde du patient : il appelle quand il veut, tant que la
            // relation existe (décision métier validée).
            $relationExiste = Appel::where('patient_id', $patientId)
                ->where('medecin_id', $medecinId)
                ->whereIn('status', [Appel::STATUS_DECROCHE, Appel::STATUS_TERMINE])
                ->exists();

            if (!$relationExiste) {
                return response()->json([
                    'error' => [
                        'code' => 'relation_requise',
                        'message' => 'Vous ne pouvez appeler que des patients avec qui vous avez déjà eu un appel.',
                    ],
                ], 403);
            }
        }

        // Un seul appel actif à la fois entre ces deux participants
        // (anti-spam de sonneries / appels parallèles).
        $appelActif = Appel::where('patient_id', $patientId)
            ->where('medecin_id', $medecinId)
            ->whereIn('status', [Appel::STATUS_INITIE, Appel::STATUS_SONNE, Appel::STATUS_DECROCHE])
            ->exists();

        if ($appelActif) {
            return response()->json([
                'error' => [
                    'code' => 'appel_en_cours',
                    'message' => 'Un appel est déjà en cours entre ces deux participants.',
                ],
            ], 409);
        }

        $appel = Appel::create([
            'patient_id' => $patientId,
            'medecin_id' => $medecinId,
            'initie_par' => $appelant->role,
            'status' => Appel::STATUS_INITIE,
            'tarif_par_minute' => 100.00,
            'solde_consomme' => 0.00,
        ]);

        return response()->json([
            'data' => [
                'appel_id' => $appel->id,
                'status' => $appel->status,
            ],
        ], 201);
    }

    /**
     * POST /api/v1/appels/{appel}/lancer
     *
     * Déclenche la sonnerie : statut "sonne" + notification temps réel
     * (AppelLance) sur le canal PRIVÉ du destinataire.
     */
    public function lancer(Request $request, Appel $appel): JsonResponse
    {
        $appelant = $request->user();

        // Seul l'initiateur peut déclencher la sonnerie.
        if (!$this->estInitiateur($appelant, $appel)) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Action non autorisée.'],
            ], 403);
        }

        // L'appel doit être dans l'état "initie".
        if ($appel->status !== Appel::STATUS_INITIE) {
            return response()->json([
                'error' => ['code' => 'mauvais_etat', 'message' => 'L\'appel n\'est pas dans le bon état.'],
            ], 409);
        }

        $appel->update([
            'status'        => Appel::STATUS_SONNE,
            'date_sonnerie' => now(),
        ]);

        // Le destinataire est l'autre participant.
        $destinataireId = $appel->initie_par === Appel::INITIE_PAR_PATIENT
            ? $appel->medecin_id
            : $appel->patient_id;

        // Notification temps réel sur le canal PRIVÉ du destinataire.
        // app()->terminating() : la réponse HTTP part d'abord, la sonnerie
        // est diffusée juste après (un broadcast Pusher coûte ~2 s, on ne
        // veut pas bloquer l'appelant qui doit envoyer son offre WebRTC).
        // NB : broadcast() direct (pas de dispatch) — o2switch n'a pas de
        // worker de queue persistant, un job en file ne partirait jamais.
        // NB2 : JsonResponse n'a PAS de méthode afterResponse() (c'est une
        // méthode de Illuminate\Http\Response) → on passe par l'Application.
        $response = response()->json([
            'data' => [
                'appel_id' => $appel->id,
                'status'   => $appel->status,
            ],
        ]);

        app()->terminating(fn () => broadcast(new AppelLance(
            appelId:           $appel->id,
            destinationUserId: $destinataireId,
            initiePar:         $appel->initie_par,
            status:            $appel->status,
        )));

        return $response;
    }

    /**
     * POST /api/v1/appels/{appel}/decrocher
     *
     * Le destinataire décroche : statut "decroche" + date_decroche.
     * C'est à partir de ce moment que le front démarre le heartbeat.
     */
    public function decrocher(Request $request, Appel $appel): JsonResponse
    {
        $user = $request->user();

        // Seul le destinataire peut décrocher.
        if (!$this->estDestinataire($user, $appel)) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Action non autorisée.'],
            ], 403);
        }

        if ($appel->status !== Appel::STATUS_SONNE) {
            return response()->json([
                'error' => ['code' => 'mauvais_etat', 'message' => 'L\'appel n\'est pas dans le bon état.'],
            ], 409);
        }

        $appel->update([
            'status'        => Appel::STATUS_DECROCHE,
            'date_decroche' => now(),
        ]);

        return response()->json([
            'data' => [
                'appel_id' => $appel->id,
                'status'   => $appel->status,
            ],
        ]);
    }

    /**
     * POST /api/v1/appels/{appel}/heartbeat
     *
     * Battement de cœur envoyé par le front toutes les 10-15 s pendant
     * l'appel décroché. Débite le solde du patient de façon continue et
     * répond { continuer: false } UNIQUEMENT à épuisement total (solde = 0).
     */
    public function heartbeat(Request $request, Appel $appel): JsonResponse
    {
        $user = $request->user();

        // Seuls les participants (patient ou médecin) envoient des heartbeats.
        if (!$this->estParticipant($user, $appel)) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Action non autorisée.'],
            ], 403);
        }

        // Le heartbeat n'a de sens que pendant un appel décroché.
        // MAIS si l'appel est encore "sonne", on force le décrochage : le
        // heartbeat prouve que le WebRTC est établi des deux côtés. Cela
        // protège la facturation quand AppelLance a été perdu (Pusher down)
        // ou que decrocher n'a jamais été reçu par le serveur (P0 revue).
        if ($appel->status === Appel::STATUS_SONNE) {
            $appel->update([
                'status'        => Appel::STATUS_DECROCHE,
                'date_decroche' => now(),
            ]);
            $appel->refresh();
        }

        if ($appel->status !== Appel::STATUS_DECROCHE) {
            return response()->json([
                'error' => ['code' => 'mauvais_etat', 'message' => 'L\'appel n\'est pas en cours.'],
            ], 409);
        }

        $soldeRestant = null;

        // Facturation : uniquement quand le PATIENT a initié l'appel.
        if ($appel->initie_par === Appel::INITIE_PAR_PATIENT) {
            // Transaction + verrou de ligne : le calcul du débit est atomique.
            // Sans lock, deux heartbeats concurrents (patient ET médecin)
            // liraient le même solde et écriraient → perte d'écriture ou
            // double débit (P1 revue).
            DB::transaction(function () use ($appel, &$soldeRestant) {
                $patient = User::whereKey($appel->patient_id)->lockForUpdate()->first();
                $appelCourant = Appel::whereKey($appel->id)->lockForUpdate()->first();

                // P1-1 : re-vérifier le statut SOUS VERROU. Si terminer a
                // commit entre le chargement du modèle (route binding) et
                // cette transaction, l'appel est "termine" → on ne débite
                // PAS de secondes postérieures au raccrochage (sur-facturation).
                if (!$patient || !$appelCourant || $appelCourant->status !== Appel::STATUS_DECROCHE) {
                    $soldeRestant = null;
                    return;
                }

                // Temps total écoulé depuis le décrochage (secondes).
                // 2e paramètre true = valeur ABSOLUE : diffInSeconds est signé
                // en Carbon (négatif si date_decroche est dans le passé).
                $secondes = now()->diffInSeconds($appelCourant->date_decroche, true);

                // Coût réel total depuis le début : (secondes / 60) × tarif par minute.
                $coutTotal = round(($secondes / 60) * (float) $appelCourant->tarif_par_minute, 2);

                // Tranche à débiter = coût total − déjà consommé.
                // Recalcul depuis date_decroche : idempotent — si un heartbeat
                // est perdu (réseau), le suivant rattrape automatiquement.
                $delta = max(0.0, $coutTotal - (float) $appelCourant->solde_consomme);

                $appelCourant->solde_consomme = $coutTotal;
                $patient->solde = max(0.0, (float) $patient->solde - $delta);
                $patient->save();
                $appelCourant->save();

                $soldeRestant = (float) $patient->solde;

                // Coupure effective UNIQUEMENT à épuisement total (solde = 0).
                // Le seuil de 100 F n'est qu'un point de contrôle interne,
                // jamais un seuil de coupure.
                if ($soldeRestant <= 0) {
                    $appelCourant->update([
                        'status'     => Appel::STATUS_TERMINE,
                        'date_fin'   => now(),
                        'raison_fin' => 'solde_epuise',
                    ]);
                }
            });

            // P1-1 : si la transaction a détecté un appel terminé (null),
            // on répond 409 comme pour un appel déjà terminé.
            if ($soldeRestant === null) {
                return response()->json([
                    'error' => ['code' => 'mauvais_etat', 'message' => 'L\'appel n\'est plus en cours.'],
                ], 409);
            }

            if ($soldeRestant <= 0) {
                return response()->json([
                    'data' => [
                        'continuer'     => false,
                        'raison_fin'    => 'solde_epuise',
                        'solde_restant' => 0,
                    ],
                ]);
            }
        }

        // P1-4 : le solde restant est une donnée financière du PATIENT.
        // Le médecin ne doit JAMAIS le voir (règle métier) → on ne le renvoie
        // que si l'appelant est le patient lui-même.
        $soldePourReponse = ($user->id === $appel->patient_id) ? $soldeRestant : null;

        return response()->json([
            'data' => [
                'continuer'     => true,
                'solde_restant' => $soldePourReponse,
            ],
        ]);
    }

    /**
     * POST /api/v1/appels/{appel}/terminer
     *
     * Termine un appel : raccrochage manuel (raccroche_manuel) ou
     * personne n'a répondu dans les 30 s (non_decroche, timer côté front).
     */
    public function terminer(Request $request, Appel $appel): JsonResponse
    {
        $user = $request->user();

        if (!$this->estParticipant($user, $appel)) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Action non autorisée.'],
            ], 403);
        }

        $validated = $request->validate([
            'raison' => 'nullable|string|in:non_decroche,raccroche_manuel',
        ]);

        // P1-2 : update conditionnel ATOMIQUE. Deux terminer concurrents
        // (les deux participants raccrochent en même temps — cas réel) :
        // le premier gagne (1 ligne affectée), le second voit 0 ligne →
        // pas de double transmission à l'API de facturation.
        // NB : Query Builder (pas Eloquent) → le $dateFormat du modèle n'est
        // PAS appliqué → on formate now() avec les microsecondes explicitement
        // (P3-arrondi : date_fin doit garder les µs pour le règlement exact).
        $affected = Appel::whereKey($appel->id)
            ->where('status', '!=', Appel::STATUS_TERMINE)
            ->update([
                'status'     => Appel::STATUS_TERMINE,
                'date_fin'   => now()->format('Y-m-d H:i:s.u'),
                'raison_fin' => $validated['raison'] ?? 'raccroche_manuel',
            ]);

        if (!$affected) {
            return response()->json([
                'error' => ['code' => 'mauvais_etat', 'message' => 'L\'appel est déjà terminé.'],
            ], 409);
        }

        // Le route binding a chargé $appel AVANT l'update conditionnel
        // (Appel::whereKey()->update() ne rafraîchit pas le modèle) → on
        // recharge pour répondre avec le statut réel ("termine").
        $appel->refresh();

        // P1-1 : règlement FINAL de la facturation. Le dernier heartbeat peut
        // être vieux de 0 à 10 s → solde_consomme serait sous-évalué sinon.
        // On débite le delta EXACT entre date_decroche et date_fin.
        $this->reglerFacturation($appel->fresh());

        // STUB : transmet l'appel terminé à l'API externe de facturation
        // (id, début, fin, durée, coût). Log local en attendant le vrai API.
        app(\App\Services\AppelExterneService::class)->envoyerAppel($appel->fresh());

        return response()->json([
            'data' => [
                'appel_id' => $appel->id,
                'status'   => $appel->status,
            ],
        ]);
    }

    /**
     * GET /api/v1/appels/{appel}
     *
     * Récupère les informations d'un appel (id, heure de début, heure de
     * fin, durée, coût) pour les fournir à l'API externe de facturation.
     * Accessible aux deux participants (patient et médecin).
     */
    public function show(Request $request, Appel $appel): JsonResponse
    {
        $user = $request->user();

        if (!$this->estParticipant($user, $appel)) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Action non autorisée.'],
            ], 403);
        }

        return response()->json([
            'data' => [
                'appel_id'        => $appel->id,
                'patient_id'      => $appel->patient_id,
                'medecin_id'      => $appel->medecin_id,
                'initie_par'      => $appel->initie_par,
                'status'          => $appel->status,
                'raison_fin'      => $appel->raison_fin,
                'tarif_par_minute'=> (float) $appel->tarif_par_minute,
                'solde_consomme'  => (float) $appel->solde_consomme,
                'date_debut'      => $appel->date_sonnerie?->toIso8601String(),
                'date_decroche'   => $appel->date_decroche?->toIso8601String(),
                'date_fin'        => $appel->date_fin?->toIso8601String(),
                'duree_secondes'  => $appel->date_decroche && $appel->date_fin
                    ? $appel->date_decroche->diffInSeconds($appel->date_fin, true)
                    : null,
            ],
        ]);
    }

    /**
     * Règlement FINAL de la facturation d'un appel terminé.
     *
     * Débite le delta EXACT entre date_decroche et date_fin (le dernier
     * heartbeat peut être vieux de 0 à 10 s). Transaction + verrous pour
     * rester atomique face à un heartbeat concurrent. Ne fait rien si
     * l'appel n'a jamais été décroché ou s'il n'a pas été initié par le
     * patient (le médecin appelle → le patient ne paie pas).
     */
    protected function reglerFacturation(Appel $appel): void
    {
        if ($appel->initie_par !== Appel::INITIE_PAR_PATIENT || !$appel->date_decroche || !$appel->date_fin) {
            return;
        }

        DB::transaction(function () use ($appel) {
            $patient = User::whereKey($appel->patient_id)->lockForUpdate()->first();
            $appelCourant = Appel::whereKey($appel->id)->lockForUpdate()->first();

            if (!$patient || !$appelCourant || $appelCourant->status !== Appel::STATUS_TERMINE) {
                return;
            }

            $secondes = $appelCourant->date_decroche->diffInSeconds($appelCourant->date_fin, true);
            $coutExact = round(($secondes / 60) * (float) $appelCourant->tarif_par_minute, 2);
            $delta = max(0.0, $coutExact - (float) $appelCourant->solde_consomme);

            $appelCourant->solde_consomme = $coutExact;
            $patient->solde = max(0.0, (float) $patient->solde - $delta);

            $patient->save();
            $appelCourant->save();
        });
    }

    /**
     * Auto-terminaison des appels orphelins (P1-3).
     *
     * Si terminer a échoué (réseau, page fermée pendant le fetch), un appel
     * peut rester "decroche"/"initie"/"sonne" pour toujours → le couple ne
     * pourrait plus JAMAIS se rappeler (409 permanent). On auto-termine :
     *  - "decroche" sans heartbeat depuis > 60 s (le heartbeat met à jour
     *    updated_at à chaque battement) ;
     *  - "initie"/"sonne" de plus de 3 min (jamais décroché).
     * Le règlement final de facturation est appliqué pour les appels décrochés.
     */
    protected function nettoyerAppelsOrphelins(): void
    {
        $decrochesOrphelins = Appel::where('status', Appel::STATUS_DECROCHE)
            ->where('updated_at', '<', now()->subSeconds(60))
            ->get();

        foreach ($decrochesOrphelins as $a) {
            // M2 revue : update CONDITIONNEL. Si un terminer() concurrent a
            // déjà passé l'appel en "termine" entre la sélection et cet
            // update, on ne doit PAS écraser son date_fin/raison_fin — le
            // premier qui gagne écrit, le second ne fait rien.
            // NB : Query Builder (pas Eloquent) → format µs explicite (P3-arrondi).
            $affected = Appel::whereKey($a->id)
                ->where('status', '!=', Appel::STATUS_TERMINE)
                ->update([
                    'status'     => Appel::STATUS_TERMINE,
                    'date_fin'   => now()->format('Y-m-d H:i:s.u'),
                    'raison_fin' => 'timeout',
                ]);

            if (!$affected) {
                continue;
            }

            $this->reglerFacturation($a->fresh());
            app(\App\Services\AppelExterneService::class)->envoyerAppel($a->fresh());
        }

        Appel::whereIn('status', [Appel::STATUS_INITIE, Appel::STATUS_SONNE])
            ->where('updated_at', '<', now()->subMinutes(3))
            ->update([
                'status'     => Appel::STATUS_TERMINE,
                'date_fin'   => now()->format('Y-m-d H:i:s.u'),
                'raison_fin' => 'non_decroche',
            ]);
    }

    /**
     * L'utilisateur est-il celui qui a initié l'appel ?
     */
    protected function estInitiateur(User $user, Appel $appel): bool
    {
        return $appel->initie_par === Appel::INITIE_PAR_PATIENT
            ? $user->id === $appel->patient_id
            : $user->id === $appel->medecin_id;
    }

    /**
     * L'utilisateur est-il le destinataire de l'appel ?
     */
    protected function estDestinataire(User $user, Appel $appel): bool
    {
        return $appel->initie_par === Appel::INITIE_PAR_PATIENT
            ? $user->id === $appel->medecin_id
            : $user->id === $appel->patient_id;
    }

    /**
     * L'utilisateur est-il l'un des deux participants de l'appel ?
     */
    protected function estParticipant(User $user, Appel $appel): bool
    {
        return $user->id === $appel->patient_id || $user->id === $appel->medecin_id;
    }
}