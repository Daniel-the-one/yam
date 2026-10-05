<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class DemoApiController extends Controller
{
    /**
     * Jeton factice renvoyé par la connexion démo.
     *
     * Chaîne fixe, absente de `personal_access_tokens`, donc invalide
     * partout ailleurs. Les routes démo n'utilisant pas `auth:sanctum`,
     * elle n'est jamais vérifiée — mais un test peut le prouver.
     */
    public const JETON_DEMO = 'demo-jeton-non-valide';

    public function wallet(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '000',
            'message' => 'Réponse de démonstration statique — aucun solde réel.',
            'wallet' => [
                'wallet_id' => 'DEMO-WALLET-001',
                'solde' => '50 000 Fcfa',
                'solde_raw' => 50000,
                'devise' => 'XOF',
                'devise_symbol' => 'Fcfa',
                'is_default' => true,
            ],
            'membre' => [
                'nom' => 'Utilisateur Démo',
                'username' => 'demo-user',
                'email' => '',
                'kyc_valide' => false,
                'avatar' => '',
            ],
            'stats_mois' => [
                'periode' => 'Période de démonstration',
                'depenses' => '1 000 Fcfa',
                'depenses_raw' => 1000,
                'recus' => '2 000 Fcfa',
                'recus_raw' => 2000,
                'nb_transactions' => 3,
            ],
            'dernieres_transactions' => [
                [
                    'id' => 1,
                    'reference' => 'DEMO-TXN-001',
                    'type' => 'TRANSFER',
                    'type_name' => 'Transfert démo',
                    'amount' => '-1 000 Fcfa',
                    'amount_raw' => 1000,
                    'fees' => '100 Fcfa',
                    'devise' => 'XOF',
                    'status' => 2,
                    'status_show' => 'Démo',
                    'description' => 'Transaction fictive — aucun argent déplacé',
                    'date_transaction' => '30/09/2026',
                ],
            ],
            'epargnes' => [],
            'nb_epargnes' => 0,
            'assurances' => [],
            'nb_assurances' => 0,
        ]);
    }

    public function transactions(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'pagination' => [
                'current_page' => 1,
                'per_page' => 5,
                'total' => 2,
                'last_page' => 1,
            ],
            'transactions' => [
                '30/09/2026' => [
                    [
                        'id' => 1,
                        'reference' => 'DEMO-TXN-001',
                        'type' => 'TRANSFER',
                        'amount' => '-1 000 Fcfa',
                        'amount_raw' => 1000,
                        'status_show' => 'Démo',
                        'description' => 'Transaction fictive — aucun argent déplacé',
                    ],
                    [
                        'id' => 2,
                        'reference' => 'DEMO-TXN-002',
                        'type' => 'RECHARGE',
                        'amount' => '+2 000 Fcfa',
                        'amount_raw' => 2000,
                        'status_show' => 'Démo',
                        'description' => 'Recharge fictive — aucun paiement effectué',
                    ],
                ],
            ],
        ]);
    }

    public function recharge(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '000',
            'message' => 'Réponse de démonstration statique — aucun paiement effectué.',
            'information' => [
                'reference' => 'DEMO-RECHARGE-001',
                'type_transaction_name' => 'Recharge démo',
                'status' => 1,
                'status_show' => 'Démo',
                'amount' => '+2 000 Fcfa',
                'fees' => '200 Fcfa',
                'total_amount' => '2 200 Fcfa',
                'devise' => 'XOF',
                'new_balance' => '-',
                'description' => 'Recharge fictive — aucun paiement effectué',
                'payment' => [
                    'mode' => 'demo',
                    'payment_token' => '',
                    'payment_url' => '',
                    'must_be_redirected' => false,
                ],
            ],
        ], 201);
    }

    public function transfert(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '000',
            'message' => 'Réponse de démonstration statique — aucun transfert effectué.',
            'information' => [
                'reference' => 'DEMO-TRANSFER-001',
                'status' => 2,
                'status_show' => 'Démo',
                'amount' => '-1 000 Fcfa',
                'fees' => '100 Fcfa',
                'new_balance' => '-',
                'devise' => 'XOF',
                'is_internal' => true,
                'description' => 'Transfert fictif — aucun argent déplacé',
                'destinataire' => [
                    'nom' => 'Destinataire Démo',
                    'wallet_id' => 'DEMO-WALLET-002',
                    'montant_recu' => '1 000 Fcfa',
                ],
            ],
        ], 201);
    }

    public function webhook(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '999',
            'credit' => false,
            'raison' => 'demo_only',
            'message' => 'Webhook de démonstration — aucune transaction vérifiée ni créditée.',
        ]);
    }

    public function initierAppel(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Appel de démonstration — aucun appel ni facturation réelle.',
            'data' => [
                'appel_id' => 9001,
                'status' => 'initie',
                'tarif_par_minute' => 100,
            ],
        ], 201);
    }

    public function actionAppel(string $appel, string $action): JsonResponse
    {
        $statuts = [
            'lancer' => 'sonne',
            'decrocher' => 'decroche',
            'heartbeat' => 'decroche',
            'terminer' => 'termine',
        ];

        return response()->json([
            'demo' => true,
            'message' => 'Appel de démonstration — aucun appel ni facturation réelle.',
            'data' => [
                'appel_id' => ctype_digit($appel) ? (int) $appel : 9001,
                'status' => $statuts[$action],
                'solde_consomme' => 0,
                'duree_secondes' => 60,
                'tarif_par_minute' => 100,
                'raison_fin' => $action === 'terminer' ? 'demo' : null,
            ],
        ]);
    }

    public function afficherAppel(string $appel): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Détail d’appel fictif — aucune facturation réelle.',
            'data' => [
                'appel_id' => ctype_digit($appel) ? (int) $appel : 9001,
                'status' => 'termine',
                'duree_secondes' => 60,
                'tarif_par_minute' => 100,
                'solde_consomme' => 0,
                'raison_fin' => 'demo',
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // Amorçage de session
    //
    // Le client web (SPA) exige une session avant d'afficher le moindre
    // écran : sans ces endpoints, un testeur reste bloqué sur le
    // formulaire de connexion et la démo n'est pas « testable ».
    //
    // AUCUN de ces appels ne lit la base et AUCUN n'émet de jeton
    // réellement valide : le jeton ci-dessous est une chaîne fixe qui
    // n'existe dans aucune table `personal_access_tokens`. Les routes
    // démo ne passant pas par `auth:sanctum`, il n'est jamais vérifié.
    // ---------------------------------------------------------------

    /**
     * POST /api/demo/v1/auth/login — connexion factice, toujours acceptée.
     *
     * L'enveloppe reprend exactement celle de `AuthController::login()`
     * pour que le client n'ait aucun branchement spécial à écrire.
     */
    public function authLogin(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Connexion de démonstration — aucun compte réel.',
            'data' => [
                'token' => self::JETON_DEMO,
                'user' => $this->demoUser(),
                'device' => [
                    'id' => 9001,
                    'device_id' => 'demo-device',
                    'label' => 'Navigateur démo',
                    'platform' => 'web',
                    'last_seen_at' => '2026-10-05T00:00:00.000000Z',
                ],
            ],
        ]);
    }

    /**
     * POST /api/demo/v1/auth/logout — no-op.
     *
     * Permet au client de « se déconnecter » sans erreur et sans
     * révoquer quoi que ce soit.
     */
    public function authLogout(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'data' => ['ok' => true],
        ]);
    }

    /**
     * GET /api/demo/v1/users/me — profil factice.
     *
     * Appelé par le client pour valider une session au démarrage.
     * `devices_count` est renvoyé pour ne pas casser l'écran Paramètres.
     */
    public function me(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'data' => [
                ...$this->demoUser(),
                'devices_count' => 1,
            ],
        ]);
    }

    /**
     * GET /api/demo/v1/config — configuration réseau SANS secret.
     *
     * Volontairement réduite à des serveurs STUN publics :
     * le vrai `/v1/config` renvoie les identifiants TURN et la clé
     * Pusher. Exposer ces valeurs via un tunnel public les aurait
     * divulguées, donc la démo s'en passe. Conséquence assumée : la découverte
     * ICE fonctionne, mais un appel ne peut pas franchir un NAT
     * symétrique — sans effet ici, la démo ne transporte pas de média.
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'app' => ['name' => 'Yam (démo)'],
            'pusher' => ['app_key' => '', 'cluster' => ''],
            'turn' => ['url' => '', 'username' => '', 'credential' => ''],
            'ice_servers' => [
                ['urls' => [
                    'stun:stun.l.google.com:19302',
                    'stun:stun1.l.google.com:19302',
                    'stun:stun2.l.google.com:19302',
                ]],
                ['urls' => ['stun:stun.cloudflare.com:3478']],
            ],
        ]);
    }

    /**
     * GET /api/demo/v1/users/search — annuaire factice.
     *
     * Alimente l'écran Contacts/Répertoire. Deux profils fixes
     * suffisent à faire fonctionner la recherche et l'ajout.
     */
    public function searchUsers(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'data' => [
                ['id' => 9002, 'name' => 'Dr Demo', 'username' => 'dr-demo', 'role' => 'medecin'],
                ['id' => 9003, 'name' => 'Patient Démo', 'username' => 'patient-demo', 'role' => 'patient'],
            ],
        ]);
    }

    /**
     * POST /api/demo/v1/auth/register — création de compte factice.
     *
     * Même enveloppe que `authLogin()` : un testeur qui clique « Créer un
     * compte » sur l'écran de connexion atterrit donc dans la démo au lieu
     * d'y rester bloqué.
     */
    public function authRegister(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Création de compte de démonstration — aucun compte réel créé.',
            'data' => [
                'token' => self::JETON_DEMO,
                'user' => $this->demoUser(),
                'device' => [
                    'id' => 9001,
                    'device_id' => 'demo-device',
                    'label' => 'Navigateur démo',
                    'platform' => 'web',
                    'last_seen_at' => '2026-10-05T00:00:00.000000Z',
                ],
            ],
        ], 201);
    }

    // ---------------------------------------------------------------
    // Contacts, appareils et signalisation d'appels
    //
    // Ces endpoints ont été ajoutés après coup : la SPA les appelle, mais la
    // démo ne les exposait pas, ce qui laissait un 404 sur l'onglet Contacts
    // et rendait l'appel impossible. Constaté au navigateur, pas à la lecture.
    //
    // Ils restent STATIQUES : aucune écriture, donc la liste renvoyée ne
    // change pas après un POST ou un DELETE. C'est cohérent avec le reste de
    // la démo (le portefeuille ne bouge pas non plus après un transfert) et
    // cela évite d'introduire le moindre stockage en mémoire de session.
    // ---------------------------------------------------------------

    /**
     * GET /api/demo/v1/contacts — liste de contacts factice.
     *
     * Forme alignée sur `ContactController::index()` : le client lit
     * `data.contacts[].user_id / name / phone_number`.
     */
    public function contacts(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'contacts' => [
                [
                    'user_id' => 9002,
                    'name' => 'Dr Demo',
                    'username' => 'dr-demo',
                    'phone_number' => '+228 90 00 00 02',
                    'device_id' => 'demo-device-2',
                    'is_online' => true,
                ],
                [
                    'user_id' => 9003,
                    'name' => 'Patient Démo',
                    'username' => 'patient-demo',
                    'phone_number' => '+228 90 00 00 03',
                    'device_id' => 'demo-device-3',
                    'is_online' => false,
                ],
            ],
        ]);
    }

    /** POST /api/demo/v1/contacts — no-op. */
    public function storeContact(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Contact de démonstration — aucun contact enregistré.',
        ], 201);
    }

    /** DELETE /api/demo/v1/contacts/{contactUserId} — no-op. */
    public function destroyContact(int $contactUserId): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Retrait de démonstration — aucun contact enregistré.',
        ]);
    }

    /**
     * GET /api/demo/v1/devices — appareils factices.
     *
     * Forme alignée sur `DeviceController::index()` : le client lit
     * `data.devices[].label / platform / device_id`.
     */
    public function devices(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'devices' => [
                [
                    'id' => 9001,
                    'device_id' => 'demo-device',
                    'label' => 'Navigateur démo',
                    'platform' => 'web',
                    'last_seen_at' => '2026-10-05T00:00:00.000000Z',
                ],
                [
                    'id' => 9002,
                    'device_id' => 'demo-device-2',
                    'label' => 'Android démo',
                    'platform' => 'android',
                    'last_seen_at' => '2026-10-04T18:30:00.000000Z',
                ],
            ],
        ]);
    }

    /** POST /api/demo/v1/devices/register — no-op. */
    public function registerDevice(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Appareil de démonstration — aucun appareil enregistré.',
        ], 201);
    }

    /** DELETE /api/demo/v1/devices/{deviceId} — no-op. */
    public function destroyDevice(string $deviceId): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Retrait de démonstration — aucun appareil enregistré.',
        ]);
    }

    /**
     * Signalisation d'appels — no-op.
     *
     * ATTENTION au chemin : la SPA appelle `/call/ring`, `/call/signal` et
     * `/call/cancel` (voir `spa-calls.js`), alors que les routes
     * `/appels/init` et `/appels/{id}/{action}` declarées plus haut ne sont
     * appelées par AUCUN code. Elles avaient été écrites en suivant le nom
     * français de l'entité, sans vérifier le nom réel des endpoints.
     * Un 404 ici rendait l'appel impossible ; ces trois routes le corrigent.
     *
     * Trois méthodes distinctes plutôt qu'un paramètre par défaut de route :
     * Laravel ne passe que les *paramètres* de route en argument de méthode
     * (pas les *defaults*), donc une signature unique receiving `'action'`
     *levait une ArgumentCountError.
     */
    public function callRing(): JsonResponse
    {
        return $this->signaler('ring');
    }

    public function callSignal(): JsonResponse
    {
        return $this->signaler('signal');
    }

    public function callCancel(): JsonResponse
    {
        return $this->signaler('cancel');
    }

    private function signaler(string $action): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Signalisation de démonstration — aucun appel ni facturation réels.',
            'action' => $action,
            'data' => [
                'appel_id' => 9001,
                'solde_consomme' => 0,
                'duree_secondes' => 0,
                'tarif_par_minute' => 100,
            ],
        ]);
    }

    /**
     * Profil utilisateur factice, aligné sur `AuthController::userPayload()`.
     *
     * Le `phone_number` est volontairement vide : la démo n'imite aucun
     * numéro réel, et fabriquer un numéro au format E.164 ici nuirait à
     * la lisibilité sans aucun gain.
     */
    private function demoUser(): array
    {
        return [
            'id' => 9001,
            'name' => 'Utilisateur Démo',
            'username' => 'demo-user',
            'phone_number' => '',
            'phone_verified' => false,
            'role' => 'patient',
        ];
    }
}
