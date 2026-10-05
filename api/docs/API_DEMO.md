# Les API `demo/v1` — réponses fixes de démonstration

> **Statut** : document de référence technique.
> **Source** : `app/Http/Controllers/DemoApiController.php`, `routes/api.php` (lignes 42-56),
> `tests/Feature/DemoApiTest.php`.
> **Vérifié le** : 2026-10-05 — suite `DemoApiTest` verte (3 tests, 24 assertions).

---

## 1. À quoi ça sert

Le préfixe `demo/v1` est un **jumeau statique de l'API réelle `/v1`**. Il permet de
développer et de tester un client (mobile Flutter, front web, collection Postman)
sans base de données fonctionnelle, sans compte, sans CinetPay, sans argent réel.

C'est un mode de travail, pas une approximation : **aucune de ces routes ne lit ni
n'écrit en base, ne vérifie de jeton, ne déplace de solde et n'émet d'événement temps
réel.** La réponse est écrite en dur dans le contrôleur.

### Trois garanties à tenir

1. **Aucune dépendance** — ni auth, ni base, ni service externe.
2. **Aucune entrée** — le contenu de la requête est ignoré ; la réponse est identique
   quel que soit le payload.
3. **Aucun effet de bord** — impossible de créditer, débiter ou facturer.

Ces trois points ne sont pas seulement documentés : ils sont **testés**
(voir § 7). C'est le seul moyen d'éviter qu'une API de démo devienne un jour une
brèche dans un environment de staging.

---

## 2. Comment elles sont déclarées

Tout le module tient dans un seul bloc de `routes/api.php` :

```php
Route::prefix('demo/v1')->name('demo.')->group(function () {
    Route::get('/wallet', [DemoApiController::class, 'wallet']);
    Route::get('/wallet/transactions', [DemoApiController::class, 'transactions']);
    Route::post('/wallet/recharge', [DemoApiController::class, 'recharge']);
    Route::post('/wallet/transfert', [DemoApiController::class, 'transfert']);
    Route::post('/wallet/recharge/notify', [DemoApiController::class, 'webhook']);

    Route::post('/appels/init', [DemoApiController::class, 'initierAppel']);
    Route::post('/appels/{appel}/{action}', [DemoApiController::class, 'actionAppel'])
        ->whereIn('action', ['lancer', 'decrocher', 'heartbeat', 'terminer']);
    Route::get('/appels/{appel}', [DemoApiController::class, 'afficherAppel']);
});
```

Trois décisions structurantes :

| Décision | Effet |
|---|---|
| Bloc déclaré **avant** le groupe `auth:sanctum` | Aucune authentification requise, par construction |
| `prefix('demo/v1')` + `name('demo.')` | Séparation totale du namespace d'URL ; impossible de confondre avec `/v1` |
| Aucune validation, aucun `FormRequest` | Un client en erreur n'obtient jamais de 422 : il obtient toujours la même réponse |

Le préfixe `/api` est ajouté par Laravel (config `bootstrap/app.php`, `api: routes/api.php`).
Les URLs finales sont donc `/api/demo/v1/...`.

### Vérification

```
$ php artisan route:list --path=demo
 POST api/demo/v1/appels/init .......... demo. › DemoApiController@initierAppel
 GET|HEAD api/demo/v1/appels/{appel} .... demo. › DemoApiController@afficherAppel
 POST api/demo/v1/appels/{appel}/{action} demo. › DemoApiController@ac…
 GET|HEAD api/demo/v1/wallet ............ demo. › DemoApiController@wallet
 POST api/demo/v1/wallet/recharge ...... demo. › DemoApiController@recharge
 POST api/demo/v1/wallet/recharge/notify demo. › DemoApiController@web…
 GET|HEAD api/demo/v1/wallet/transactions demo. › DemoApiController@transa…
 POST api/demo/v1/wallet/transfert ..... demo. › DemoApiController@transfert
```

**8 routes.** La colonne « middleware » est vide sur les 8 : ni `auth:sanctum`,
ni `throttle`, ni `IdempotencyService`.

---

## 3. Qui appelle ces API aujourd'hui

Réponse courte : **Personne, à part les tests et Postman.**

| Consommateur | Statut |
|---|---|
| `tests/Feature/DemoApiTest.php` | ✅ Utilise 7 des 8 routes |
| `Yam-API.postman_collection.json` / `KondjiPro_API.postman_collection.json` | ✅ Collection générée, ~46 appels |
| Application Flutter (`settings_test_app/`, `web/`) | ❌ **Aucune référence à `demo/v1`** |
| Portail web `public/contact.php` | ❌ Aucune référence |

Autrement dit : le mode démo est **prêt pour le client mais pas encore branché**.
Un client peut basculer son `baseUrl` vers `/api/demo/v1` et fonctionner sans
aucune modification de code — c'est l'intérêt du principe — mais le switch de
mode n'est pas encore dans le code mobile.

La collection Postman est produite par `scripts/generate_postman_collection.py`,
qui **réécrit** les URLs réelles vers les URLs démo :

```python
request["url"]["raw"] = raw_url.replace("/api/v1/", "/api/demo/v1/")
```

Une seule ligne. C'est ce qui garantit que la collection démo ne peut pas diverger
de la collection réelle : elle en est dérivée mécaniquement.

---

## 4. Logique de chaque endpoint

### 4.1 `GET /api/demo/v1/wallet` — 200

Retourne un portefeuille complet et figé : solde **50 000 Fcfa**, membre
« Utilisateur Démo », stats du mois, une transaction, deux listes vides
(`epargnes`, `assurances`).

Logique : il n'y en a pas. C'est un miroir figé de la forme produced par
`WalletService::getWalletData()`, utile pour coder un écran de dashboard complet
avant que la base ne soit prête.

### 4.2 `GET /api/demo/v1/wallet/transactions` — 200

Deux transactions fictives regroupées sous la date `30/09/2026` : un transfert
(`-1 000`) et une recharge (`+2 000`).

Logique : reproduit le **groupement par date** que fait `WalletService::listTransactions()`,
parce que c'est ce format que l'écran d'historique doit savoir consommer.

⚠️ **Les paramètres `?page=` et `?limit=` sont acceptés par l'URL mais totalement
ignorés.** La pagination renvoyée est toujours identique.

### 4.3 `POST /api/demo/v1/wallet/recharge` — 201

Simule une initiation de recharge CinetPay.

Le champ le plus important est `payment` :

```json
"payment": {
  "mode": "demo",
  "payment_token": "",
  "payment_url": "",
  "must_be_redirected": false
}
```

C'est le **contrat de non-redirection**. En mode réel, `must_be_redirected: true`
et `payment_url` contient l'URL CinetPay vers laquelle le client redirige l'utilisateur.
En démo, les deux sont neutralisés : un client qui respecte ce contrat ne peut pas
sortir de l'application, ce qui est exactement le comportement souhaité.

Le statut `201` (Created) est identique au réel : le client doit écrire le même code
dans les deux modes.

### 4.4 `POST /api/demo/v1/wallet/transfert` — 201

Simule un transfert interne (`is_internal: true`) vers « Destinataire Démo ».

Point d'attention : `new_balance` vaut `"-"` — un tiret, pas un nombre. Le client
doit savoir traiter cette valeur avant le solde réel.

### 4.5 `POST /api/demo/v1/wallet/recharge/notify` — 200

**La route la plus importante du module.**

Le webhook réel de CinetPay est un point d'entrée sensible : sans jeton, la
sécurité repose uniquement sur des vérifications serveur. En démo, on ne vérifie
**rien du tout** — et c'est le but. Le contrôleur répond systématiquement :

```json
{ "demo": true, "status": "999", "credit": false, "raison": "demo_only" }
```

`credit: false` en permanence, quelle que soit la payload. Un client qui
recevrait `credit: true` en démo aurait un bug d'affichage ; un attaquant ne peut
pas obtenir de crédit par ce chemin.

Le couple `status` / `credit` / `raison` reprend exactement la forme de
`WalletController::reponseWebhook()`, ce qui permet de coder le traitement du
côté client une fois pour toutes.

### 4.6 `POST /api/demo/v1/appels/init` — 201

Simule la création d'un appel facturé. Retourne `appel_id: 9001`, `status: initie`,
`tarif_par_minute: 100`.

L'identifiant `9001` est choisi hors de portée des vrais appels (base qui démarre à 1),
ce qui évite qu'un client recycle par erreur un id réel dans un appel démo.

### 4.7 `POST /api/demo/v1/appels/{appel}/{action}` — 200

Le cœur du cycle d'appel. Une seule route, quatre actions, un dictionnaire :

| `action` | `status` renvoyé | Sens réel |
|---|---|---|
| `lancer` | `sonne` | Le médecin vient d'être alerté |
| `decrocher` | `decroche` | Le patient a décroché, la facturation démarre |
| `heartbeat` | `decroche` | L'appel est en cours, on factume |
| `terminer` | `termine` | Fin d'appel, facturation close |

`{action}` est contrainte par `whereIn` : une action inconnue ne produit pas un
500 mais un **404** (la route ne « matche » pas). C'est le comportement correct.

Deux détails de logique :

- `solde_consomme` vaut **toujours 0**, y compris sur `terminer`. Aucune durée n'est
  convertie en argent.
- `raison_fin` vaut `'demo'` **uniquement** sur `terminer`, `null` sinon. Le client
  peut donc tester sa branche « fin d'appel » et sa branche « appel en cours ».

### 4.8 `GET /api/demo/v1/appels/{appel}` — 200

Retourne le bilan d'un appel terminé : `status: termine`, `duree_secondes: 60`,
`solde_consomme: 0`.

Utilisé par le flux de facturation pour afficher le récapitulatif. Comme la 4.7,
le coût reste à zéro.

---

## 5. Ce qui est réellement commun avec l'API réelle

C'est le cœur du sujet : **un client doit pouvoir commuter d'un mode à l'autre sans
rien réécrire.** Voici ce qui a été.aligné, et ce qui ne l'est pas.

### 5.1 Aligné ✅

| Élément | Réel | Démo |
|---|---|---|
| Codes HTTP | 200 / 201 | 200 / 201 |
| Clés du portefeuille | `solde`, `solde_raw`, `devise`, `devise_symbol` | identiques |
| Bloc membre | `nom`, `username`, `email`, `kyc_valide`, `avatar` | identique |
| Statuts d'appel | `initie`, `sonne`, `decroche`, `termine` | identiques |
| Contrat de redirection | `payment.must_be_redirected` | présent (à `false`) |
| Enveloppe webhook | `status` / `credit` / `raison` | identique |
| Montants | `formatMontant` → `"1 000 Fcfa"` + `*_raw` entier | même format |
| Regroupement par date | `transactions` en map `date → []` | identique |

Le format des montants deserve un mot : le double champ `montant` / `montant_raw`
existe pour que l'affichage utilise la chaîne formatée (« 1 000 Fcfa ») et les calculs
utilisent l'entier. Les valeurs JSON suivent donc exactement ce que produit `formatMontant()`.

### 5.2 Divergences connues ⚠️

Trois écarts repérés entre le contrôleur démo et le service réel. **Aucun ne casse
l'API démo elle-même** (qui est auto-cohérente), mais ils peuvent faire échouer un
client écrit contre le réel puis testé en démo.

**① Pagination de `transactions` — le vrai écart**

| Clé | `WalletService::listTransactions()` (réel) | `DemoApiController` (démo) |
|---|---|---|
| page courante | `page` | `current_page` |
| taille | `limit` | `per_page` |
| nombre de pages | `total_pages` | `last_page` |
| page suivante | `has_next` (booléen) | *absent* |
| total | `total` | `total` |

La démo a utilisé la forme **par défaut de Laravel** (`LengthAwarePaginator`) au lieu
de la forme du service. Un client qui lit `pagination.has_next` pour afficher un bouton
« Suivant » ne trouvera rien en démo.

**② Transactions incomplètes dans la liste**

`WalletService::formatTransaction()` renvoie 22 clés. La démo n'en renvoie que 7.
Manquants côté démo : `type_name`, `type_icon`, `type_icon_color`, `type_icon_bg_color`,
`sens`, `fees`, `devise`, `devise_symbol`, `new_balance`, `status`, `status_color`,
`counterpart`, `date_transaction`, `heure_transaction`, `date_complete`.

Concrètement : **l'icône et la couleur d'une ligne d'historique n'existent pas en démo.**
C'est le manque le plus visible pour un front.

**③ Types non uniformes**

Trois booléens écrits en `true`/`false` dans la démo et en `1`/`0` dans le réel :

| Clé | Réel | Démo |
|---|---|---|
| `information.is_internal` (transfert) | `1` / `0` | `true` |
| `wallet.is_default` | `1` | `true` |
| `membre.kyc_valide` | `0` | `false` |

En JSON ce n'est pas le même type — un client TypeScript strict ou un test de contrat
le détectera.

**④ Clés manquantes dans `wallet`**

`WalletService::formatWallet()` renvoie 10 clés. La démo n'en renvoie que 6.
Manquantes : `key_wallet`, `devise_wallet`, `symbole_wallet`, `date_create`.

`date_create` est celle qui peut faire le plus mal : un écran affichant « membre
depuis le … » fonctionnera en démo et affichera un champ vide en réel.

### 5.3 Champs non alignés volontairement

Trois choix assumés :

- `status: '000'` sur les réponses de recharge/transfert. C'est le code succès du
  prestataire externe, réutilisé tel quel pour que le client teste une seule valeur.
- `stats_mois.periode: 'Période de démonstration'`. Le client ne doit pas afficher
  « Septembre 2026 » sur des chiffres inventés.
- `statutTransaction` du réel produit un objet `{show, color}`. La démo renvoie
  `status_show: 'Démo'` en texte simple, cohérent avec le point ②.

---

## 6. Schéma de communication

```
┌──────────────────┐
│  Client          │   (Flutter, web, Postman)
│  baseUrl:        │
│  /api/demo/v1    │
└────────┬─────────┘
         │  HTTP — aucun en-tête Authorization
         │  Payload IGNORÉ
         ▼
┌────────────────────────────────────────────┐
│  routes/api.php — prefix('demo/v1')       │
│  Aucun middleware : ni auth, ni throttle   │
└────────┬───────────────────────────────────┘
         ▼
┌────────────────────────────────────────────┐
│  DemoApiController                         │
│                                            │
│  return response()->json([...littéral...]) │
│                                            │
│  ✗ pas de Model      ✗ pas de Service     │
│  ✗ pas de DB::       ✗ pas d'Event        │
│  ✗ pas de Log        ✗ pas de CinetPay     │
└────────┬───────────────────────────────────┘
         ▼
   JSON statique  ──────────────►  Client

   Base de données :     AUCUN ACCÈS
   CinetPay :            AUCUN APPEL
   Idempotency-Key :     IGNORÉE
```

**Point important pour le lecteur pressé :** ce schéma ne comporte **aucune
communication sortante**. Une API de démo qui appellerait un service externe
n'aurait plus de raison d'être.

---

## 7. Ce qui protège le module

Les tests ne vérifient pas « ça marche », ils vérifient les **trois garanties**.
C'est la partie à ne pas supprimer si quelqu'un reprend ce fichier.

`tests/Feature/DemoApiTest.php` :

| Test | Ce qu'il verrouille |
|---|---|
| `test_wallet_demo_returns_static_fixture_independent_of_input` | Deux recharges avec montants **et numéros différents** (1000/900000) rendent un JSON **strictement identique** (`assertSame` sur les deux tableaux). C'est la preuve qu'aucune entrée n'est lue. |
| `test_wallet_demo_never_credits_or_transfers_money` | `solde_raw: 50000` inchangé, `new_balance: '-'`, et surtout **`credit: false`** sur le webhook. |
| `test_paid_call_demo_returns_static_response_without_authentication` | Un appel sans aucun jeton obtient `201` ; `solde_consomme` vaut `0` sur `terminer` comme sur le `GET`. |

Le premier test est le plus important : il utilise `assertSame` sur les **tableaux
PHP**, donc il échouerait si le contrôleur commençait à lire ne serait-ce qu'un
champ de la requête. C'est le test qui empêche la démo de dériver vers du semi-réel.

---

## 8. Ce qu'il reste à faire

Par ordre de valeur pour le client :

1. **Aligner la pagination** (§ 5.2 ①) — copier les 5 clés du service réel dans
   `DemoApiController::transactions()`. Un bug de plus d'une ligne, qui peut faire
   échouer un bouton « page suivante ».
2. **Compléter les lignes de transaction** (§ 5.2 ②) — surtout `type_icon`,
   `type_icon_color`, `sens` et `counterpart`, sans lesquelles un écran d'historique
   est irréalisable en démo.
3. **Uniformiser les booléens** (§ 5.2 ③) — `is_internal`, `is_default`, `kyc_valide`
   passent de `true`/`false` à `1`/`0`. Trois lignes.
4. **Ajouter les 4 clés manquantes de `wallet`** (§ 5.2 ④) — en priorité `date_create`,
   qui conditionne l'affichage « membre depuis ».
5. **Ajouter le switch de mode côté client** (§ 3) — rien n'appelle `demo/v1`
   aujourd'hui ; c'est le travail restant le plus visible.
6. **Ajouter un contrôle de garde** : `if (app()->environment('production')) abort(404)`
   dans le groupe de routes. Les routes démo sont aujourd'hui présentes en
   production — elles sont inoffensives (aucune donnée réelle), mais un
   `solde: 50 000 Fcfa` visible par un utilisateur réel est une faute de goût et une
   source de tickets « je vois 50 000 sur mon compte ».

Le point 6 est le seul qui présente un risque de réputation. Les cinq autres sont
du confort de développement.

---

## 9. Piège à connaître

`DemoApiController::actionAppel()` retourne `heartbeat → 'decroche'`, et
`afficherAppel()` retourne toujours `termine`. Ce sont des choix de commodité, pas
une simulation fidèle.

Si un jour le comportement démo doit servir à valider une machine à états côté
client (par exemple pour tester qu'un bouton « raccrocher » disparaît après
`terminer`), **ces deux raccourcis produiront un faux vert** : le client passera ses
états alors que la logique réelle ne le ferait pas.

C'est acceptable tant que le mode démo sert à l'Intégration, pas à la Validation
des règles métier.

---

## Voir aussi

- `app/Http/Controllers/DemoApiController.php` — les 8 réponses en dur
- `tests/Feature/DemoApiTest.php` — les garanties verrouillées
- `app/Http/Controllers/WalletController.php` — le pendant réel
- `app/Http/Controllers/AppelController.php` — le pendant réel
- `scripts/generate_postman_collection.py` — génération de la collection Postman
- `.claude/NEXT_STEP.md` — état d'avancement global du projet