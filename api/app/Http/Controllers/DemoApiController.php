<?php

namespace App\Http\Controllers;

use App\Models\DemoAccessToken;
use App\Models\DemoAccount;
use App\Services\DemoWalletService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoApiController extends Controller
{
    public function index(): JsonResponse
    {
        $baseUrl = url('/api/demo/v1');
        $endpoints = [
            ['GET', '/', false, 'Liste des endpoints et paramètres de connexion'],
            ['GET', '/config', false, 'Configuration publique sans secrets'],
            ['POST', '/auth/login', false, 'Connexion ou création de session invitée'],
            ['POST', '/auth/register', false, 'Créer un compte démo avec téléphone et mot de passe'],
            ['POST', '/auth/logout', true, 'Révoquer le jeton courant'],
            ['GET', '/users/me', true, 'Profil et wallet du compte connecté'],
            ['GET', '/users/search?q={terme}&limit=20', true, 'Rechercher les comptes démo'],
            ['GET', '/contacts', true, 'Lister les contacts démo'],
            ['POST', '/contacts', true, 'Ajouter un contact démo'],
            ['DELETE', '/contacts/{contactUserId}', true, 'Retirer un contact démo'],
            ['GET', '/devices', true, 'Lister les appareils démo'],
            ['POST', '/devices/register', true, 'Enregistrer un appareil démo'],
            ['DELETE', '/devices/{deviceId}', true, 'Retirer un appareil démo'],
            ['POST', '/call/ring', true, 'Signaler un appel entrant'],
            ['POST', '/call/signal', true, 'Échanger la signalisation d’appel'],
            ['POST', '/call/cancel', true, 'Annuler un appel'],
            ['GET', '/wallet', true, 'Consulter le solde et les dernières transactions'],
            ['GET', '/wallet/transactions?page=1&limit=20', true, 'Historique paginé du wallet'],
            ['POST', '/wallet/recharge', true, 'Créer une recharge virtuelle en attente'],
            ['POST', '/wallet/recharge/notify', true, 'Confirmer ou refuser une recharge virtuelle'],
            ['POST', '/wallet/transfert', true, 'Transférer entre deux wallets démo'],
            ['POST', '/wallet/withdraw', true, 'Retirer du wallet vers un Mobile Money'],
            ['GET', '/kondjipro/solde', true, 'Consulter le solde KondjiPro démo'],
            ['POST', '/kondjipro/encaissement', true, 'Créer un encaissement démo'],
            ['POST', '/kondjipro/encaissement/{reference}/confirm', true, 'Confirmer un encaissement démo'],
            ['POST', '/appels/init', true, 'Démarrer un appel démo'],
            ['POST', '/appels/{appel}/lancer', true, 'Lancer un appel démo'],
            ['POST', '/appels/{appel}/decrocher', true, 'Décrocher un appel démo'],
            ['POST', '/appels/{appel}/heartbeat', true, 'Maintenir un appel démo'],
            ['POST', '/appels/{appel}/terminer', true, 'Terminer un appel démo'],
            ['GET', '/appels/{appel}', true, 'Consulter un appel démo'],
        ];
        $endpoints = array_map(
            fn (array $endpoint) => [
                'method' => $endpoint[0],
                'path' => '/api/demo/v1'.($endpoint[1] === '/' ? '' : $endpoint[1]),
                'url' => $baseUrl.($endpoint[1] === '/' ? '' : $endpoint[1]),
                'requires_auth' => $endpoint[2],
                'description' => $endpoint[3],
            ],
            $endpoints,
        );

        return response()->json([
            'demo' => true,
            'name' => 'Yam Demo API',
            'message' => 'API de test publique : comptes et fonds fictifs, isolés des comptes réels.',
            'api_path' => '/api/demo/v1',
            'web_demo_path' => '/demo.html',
            'postman_collection_path' => '/Yam-Demo-API.postman_collection.json',
            'authentication' => [
                'register' => 'POST /auth/register',
                'login' => 'POST /auth/login (payload vide pour créer un compte invité)',
                'header' => 'Authorization: Bearer <token>',
            ],
            'wallet' => [
                'initial_balance_xof' => 50000,
                'recharge' => 'POST /wallet/recharge puis POST /wallet/recharge/notify',
                'transfer' => 'POST /wallet/transfert (entre deux wallets de démonstration)',
                'withdraw' => 'POST /wallet/withdraw (sortie vers un numéro Mobile Money)',
                'transactions' => 'GET /wallet/transactions?page=1&limit=20',
            ],
            'public_routes' => [
                'GET /',
                'GET /config',
                'POST /auth/login',
                'POST /auth/register',
            ],
            'authenticated_routes' => [
                'POST /auth/logout',
                'GET /users/me',
                'GET /users/search',
                'GET|POST|DELETE /contacts',
                'GET|POST|DELETE /devices',
                'POST /call/ring',
                'POST /call/signal',
                'POST /call/cancel',
                'GET /wallet',
                'GET /wallet/transactions',
                'POST /wallet/recharge',
                'POST /wallet/recharge/notify',
                'POST /wallet/transfert',
                'POST /wallet/withdraw',
                'GET /kondjipro/solde',
                'POST /kondjipro/encaissement',
                'POST /kondjipro/encaissement/{reference}/confirm',
                'POST /appels/init',
                'POST /appels/{appel}/{action}',
                'GET /appels/{appel}',
            ],
            'endpoints' => $endpoints,
        ]);
    }

    public function wallet(Request $request, DemoWalletService $wallet): JsonResponse
    {
        return response()->json($wallet->walletData($this->demoAccount($request)));
    }

    public function transactions(Request $request, DemoWalletService $wallet): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($wallet->listTransactions(
            $this->demoAccount($request),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['limit'] ?? 20),
        ));
    }

    public function recharge(Request $request, DemoWalletService $wallet): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|integer|min:100|max:1000000',
            'phone_number' => 'required|string|max:20',
        ]);

        return response()->json($wallet->recharge(
            $this->demoAccount($request),
            (int) $validated['montant'],
            $validated['phone_number'],
        ), 201);
    }

    public function transfert(Request $request, DemoWalletService $wallet): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|integer|min:1|max:1000000',
            'destinataire_wallet_id' => 'nullable|string|max:32',
            'phone_number' => 'nullable|string|max:20',
        ]);

        if (empty($validated['destinataire_wallet_id']) && empty($validated['phone_number'])) {
            throw ValidationException::withMessages([
                'destinataire_wallet_id' => 'Un destinataire de démonstration est requis.',
            ]);
        }

        return response()->json($wallet->transfer(
            $this->demoAccount($request),
            (int) $validated['montant'],
            $validated['destinataire_wallet_id'] ?? null,
            $validated['phone_number'] ?? null,
        ), 201);
    }

    /**
     * POST /api/demo/v1/wallet/withdraw
     *
     * Retrait virtuel : débite la balance du compte de démonstration.
     * Solde insuffisant → 402, comme l'API réelle /api/v1/wallet/withdraw.
     */
    public function withdraw(Request $request, DemoWalletService $wallet): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|integer|min:1|max:1000000',
            'phone_number' => 'nullable|string|max:20',
        ]);

        return response()->json($wallet->withdraw(
            $this->demoAccount($request),
            (int) $validated['montant'],
            $validated['phone_number'] ?? null,
        ), 201);
    }

    public function webhook(Request $request, DemoWalletService $wallet): JsonResponse
    {
        $validated = $request->validate([
            'transaction_id' => 'required|string|max:64',
            'code' => 'nullable|integer',
            'status' => 'nullable|string|max:20',
        ]);
        $succeeded = isset($validated['code'])
            ? (int) $validated['code'] === 200
            : in_array(strtoupper((string) ($validated['status'] ?? '')), ['SUCCESS', 'SUCCEEDED', 'COMPLETED'], true);

        return response()->json($wallet->notifyRecharge(
            $this->demoAccount($request),
            $validated['transaction_id'],
            $succeeded,
        ));
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
    // Les identités et jetons démo sont persistés dans des tables distinctes
    // des comptes réels. Les sessions sont vérifiées par le middleware
    // `demo.auth` et ne sont jamais acceptées par `auth:sanctum`.
    // ---------------------------------------------------------------

    /**
     * POST /api/demo/v1/auth/login — connexion démo ou création d'un invité.
     *
     * L'enveloppe reprend exactement celle de `AuthController::login()`
     * pour que le client n'ait aucun branchement spécial à écrire.
     */
    public function authLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => 'nullable|string|max:30',
            'password' => 'nullable|string|max:72',
            'device_id' => 'nullable|string|max:100',
            'platform' => 'nullable|string|in:web,ios,android',
        ]);
        $phone = isset($validated['phone_number'])
            ? $this->normalizeDemoPhone($validated['phone_number'])
            : null;
        $password = $validated['password'] ?? null;

        if (($phone === null) !== ($password === null)) {
            throw ValidationException::withMessages([
                'phone_number' => 'Le numéro et le mot de passe doivent être fournis ensemble.',
            ]);
        }

        if ($phone !== null) {
            $account = DemoAccount::where('phone_number', $phone)->first();
            if (! $account || ! $account->password || ! Hash::check($password, $account->password)) {
                return response()->json([
                    'demo' => true,
                    'message' => 'Identifiants de démonstration incorrects.',
                ], 401);
            }
        } else {
            $account = DemoAccount::create([
                'name' => 'Visiteur démo',
                'wallet_id' => $this->newDemoWalletId(),
            ]);
        }

        return $this->sessionResponse($account, (string) ($validated['device_id'] ?? 'demo-device'));
    }

    /**
     * POST /api/demo/v1/auth/logout — révoque le jeton courant.
     */
    public function authLogout(Request $request): JsonResponse
    {
        $request->attributes->get('demo_access_token')?->delete();

        return response()->json([
            'demo' => true,
            'data' => ['ok' => true],
        ]);
    }

    /**
     * GET /api/demo/v1/users/me — profil du compte démo courant.
     *
     * Appelé par le client pour valider une session au démarrage.
     * `devices_count` est renvoyé pour ne pas casser l'écran Paramètres.
     */
    public function me(Request $request): JsonResponse
    {
        $account = $this->demoAccount($request);

        return response()->json([
            'demo' => true,
            'data' => [
                ...$this->demoUser($account),
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
     * GET /api/demo/v1/users/search — recherche d'autres comptes démo.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $account = $this->demoAccount($request);
        $query = trim((string) $request->query('q', ''));
        $accounts = DemoAccount::where('id', '!=', $account->id)
            ->when($query !== '', fn ($builder) => $builder->where(function ($builder) use ($query) {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('username', 'like', '%'.$query.'%');
            }))
            ->orderBy('id')
            ->limit(min(50, max(1, $request->integer('limit', 20))))
            ->get();

        return response()->json([
            'demo' => true,
            'data' => $accounts->map(fn (DemoAccount $candidate) => [
                'id' => 9000 + $candidate->id,
                'name' => $candidate->name,
                'username' => $candidate->username ?? '',
                'role' => 'patient',
                'wallet_id' => $candidate->wallet_id,
            ])->all(),
        ]);
    }

    /**
     * POST /api/demo/v1/auth/register — crée un compte démo isolé.
     *
     * Même enveloppe que `authLogin()` : un testeur qui clique « Créer un
     * compte » sur l'écran de connexion atterrit donc dans la démo au lieu
     * d'y rester bloqué.
     */
    public function authRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:1|max:50',
            'phone_number' => 'required|string|max:30|regex:/[0-9]/',
            'password' => 'required|string|min:8|max:72',
            'username' => 'nullable|string|min:2|max:30',
            'device_id' => 'nullable|string|max:100',
        ]);
        $phone = $this->normalizeDemoPhone($validated['phone_number']);

        if (DemoAccount::where('phone_number', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => 'Ce numéro de démonstration est déjà utilisé.',
            ]);
        }

        $username = $validated['username'] ?? null;
        if ($username !== null && DemoAccount::where('username', $username)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'Ce nom d’utilisateur de démonstration est déjà utilisé.',
            ]);
        }

        $account = DemoAccount::create([
            'name' => $validated['name'],
            'username' => $username ?: 'demo-'.Str::lower(Str::random(8)),
            'phone_number' => $phone,
            'password' => Hash::make($validated['password']),
            'wallet_id' => $this->newDemoWalletId(),
        ]);

        return $this->sessionResponse($account, (string) ($validated['device_id'] ?? 'demo-device'), 201);
    }

    // ---------------------------------------------------------------
    // Contacts, appareils et signalisation d'appels
    //
    // Ces endpoints ont été ajoutés après coup : la SPA les appelle, mais la
    // démo ne les exposait pas, ce qui laissait un 404 sur l'onglet Contacts
    // et rendait l'appel impossible. Constaté au navigateur, pas à la lecture.
    //
    // Les contacts et appareils restent des exemples statiques ; les données
    // financières, elles, sont persistées par DemoWalletService.
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
     * Solde KondjiPay du compte démo connecté.
     *
     * Même forme que `GET /api/medecin.php` (champ `solde` seul) pour que
     * le mobile puisse basculer sur l'URL réelle sans changer le parsing.
     */
    public function kondjiproSolde(Request $request): JsonResponse
    {
        $account = $this->demoAccount($request);

        return response()->json([
            'demo' => true,
            'ok' => true,
            'solde' => (int) $account->balance,
            'devise' => 'XOF',
            'message' => 'Solde virtuel de démonstration.',
        ]);
    }

    /**
     * Demande d'encaissement KondjiPay virtuel en attente.
     *
     * Reprend la forme de `api/encaissement_create.php` (transaction en attente
     * + qr_data) ; seule la route de confirmation crédite le wallet virtuel.
     */
    public function kondjiproEncaissement(Request $request, DemoWalletService $wallet): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => 'required|integer|min:1',
            'montant' => 'required|integer|min:100|max:1000000',
            'motif' => 'nullable|string|max:255',
        ]);

        return response()->json($wallet->createEncaissement(
            $this->demoAccount($request),
            (int) $validated['patient_id'],
            (int) $validated['montant'],
            trim((string) ($validated['motif'] ?? '')),
        ), 201);
    }

    public function confirmerKondjiproEncaissement(
        Request $request,
        string $reference,
        DemoWalletService $wallet
    ): JsonResponse {
        return response()->json($wallet->confirmEncaissement(
            $this->demoAccount($request),
            $reference,
        ));
    }

    private function sessionResponse(DemoAccount $account, string $deviceId, int $status = 200): JsonResponse
    {
        $token = 'demo_'.Str::random(64);
        DemoAccessToken::create([
            'demo_account_id' => $account->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'demo' => true,
            'message' => 'Session de démonstration créée. Les montants sont virtuels.',
            'data' => [
                'token' => $token,
                'user' => $this->demoUser($account),
                'device' => [
                    'id' => 9000 + $account->id,
                    'device_id' => $deviceId !== '' ? $deviceId : 'demo-device',
                    'label' => 'Appareil démo',
                    'platform' => 'web',
                    'last_seen_at' => now()->toISOString(),
                ],
            ],
        ], $status);
    }

    private function demoAccount(Request $request): DemoAccount
    {
        return $request->attributes->get('demo_account');
    }

    private function newDemoWalletId(): string
    {
        do {
            $walletId = 'TGW'.Str::upper(Str::random(13));
        } while (DemoAccount::where('wallet_id', $walletId)->exists());

        return $walletId;
    }

    private function normalizeDemoPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 8) {
            $digits = '228'.$digits;
        }

        return '+'.$digits;
    }

    private function demoUser(DemoAccount $account): array
    {
        return [
            'id' => 9000 + $account->id,
            'name' => $account->name,
            'username' => $account->username ?? '',
            'phone_number' => $account->phone_number ?? '',
            'wallet_id' => $account->wallet_id,
            'phone_verified' => false,
            'role' => 'patient',
        ];
    }
}
