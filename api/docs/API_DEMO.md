# API de démonstration Yam

Le mode démo permet de tester l'authentification, les écrans web et les flux
financiers sans toucher aux comptes ni à l'argent réels. Il utilise ses propres
tables (`demo_accounts`, `demo_access_tokens`, `demo_wallet_transactions`) dans
la base configurée par Laravel. Les routes réelles `/api/v1/*` continuent
d'utiliser leurs utilisateurs, wallets et transactions habituels.

Chaque nouvelle session invitée commence avec **50 000 XOF virtuels**. Les
soldes, comptes et transactions démo sont persistants et séparés entre testeurs.
Ils ne sont jamais transférés vers `users` ou `transactions`.

## Partager et consommer l'API

En local ou sur le même réseau :

```sh
cd api
./scripts/share.sh lan
```

Pour un accès depuis Internet, lancer un tunnel :

```sh
cd api
./scripts/share.sh pinggy
```

Le tunnel affiche un domaine HTTPS temporaire. Remplacer `<domaine>` dans les
liens suivants par ce domaine avant de les partager :

| Ressource | URL |
|---|---|
| Client web de démonstration | `https://<domaine>/demo.html` |
| Index de l'API, utilisable pour découvrir les routes | `https://<domaine>/api/demo/v1` |
| Collection Postman | `https://<domaine>/Yam-Demo-API.postman_collection.json` |

L'index JSON `/api/demo/v1` expose un tableau `endpoints` avec, pour chaque
route, sa méthode HTTP, son URL complète sur le domaine courant, le chemin,
l'exigence d'authentification et une description. Les paramètres variables sont
indiqués entre accolades (`{reference}`, `{appel}`) et doivent être remplacés
par les identifiants retournés par l'API. Chaque URL peut ainsi être copiée et
partagée directement.

La collection Postman peut aussi être importée depuis
`public/Yam-Demo-API.postman_collection.json`. Sa variable `base_url` doit
contenir uniquement l'origine (par exemple `https://<domaine>`), sans chemin
`/api/demo/v1`.

Le tunnel ne reste disponible que tant que le processus de partage tourne.
Ne partagez pas le jeton `Bearer` d'un compte démo : il donne accès au solde et
à l'historique de ce compte.

## Authentification

Les routes `GET /api/demo/v1` et `GET /api/demo/v1/config` sont publiques. Les
autres routes exigent un jeton démo :

```http
Authorization: Bearer <token>
Accept: application/json
```

Créer un compte invité sans identifiants (pratique pour Postman ou le client
web) :

```http
POST /api/demo/v1/auth/login
Content-Type: application/json

{}
```

Chaque appel invité crée un compte indépendant et retourne un jeton aléatoire.
Pour tester une inscription et une reconnexion avec identifiants :

```http
POST /api/demo/v1/auth/register
Content-Type: application/json

{
  "name": "Testeur",
  "phone_number": "+22890000001",
  "password": "DemoPass123",
  "device_id": "postman-test",
  "platform": "web"
}
```

Puis appeler `POST /api/demo/v1/auth/login` avec `phone_number` et
`password`. Les mots de passe sont hachés et les jetons ne sont conservés qu'en
empreinte. Les jetons démo ne sont pas valides sur `/api/v1/*`. `POST
/api/demo/v1/auth/logout` révoque le jeton courant ; les jetons expirent après
30 jours.

## Wallet et transactions virtuelles

| Méthode | Route | Comportement |
|---|---|---|
| `GET` | `/api/demo/v1/wallet` | Solde, statistiques du mois et dernières transactions du compte connecté |
| `GET` | `/api/demo/v1/wallet/transactions?page=1&limit=20` | Historique paginé, filtré sur le compte connecté |
| `POST` | `/api/demo/v1/wallet/recharge` | Crée une recharge virtuelle en attente, sans redirection ni appel à CinetPay |
| `POST` | `/api/demo/v1/wallet/recharge/notify` | Simule la confirmation/échec de la recharge, une seule fois |
| `POST` | `/api/demo/v1/wallet/transfert` | Transfert atomique entre deux wallets démo |
| `POST` | `/api/demo/v1/wallet/withdraw` | Retrait virtuel : débite immédiatement le solde (402 si insuffisant) |
| `GET` | `/api/demo/v1/kondjipro/solde` | Solde virtuel du compte connecté |
| `POST` | `/api/demo/v1/kondjipro/encaissement` | Crée un encaissement virtuel en attente |
| `POST` | `/api/demo/v1/kondjipro/encaissement/{reference}/confirm` | Confirme l'encaissement et crédite une seule fois le wallet virtuel |

Une recharge suit deux étapes. Le premier appel retourne une référence et le
statut `1` (en attente) :

```json
{
  "montant": 2000,
  "phone_number": "+22890000000"
}
```

La confirmation doit reprendre la référence retournée, jamais un montant fourni
par le client :

```json
{
  "transaction_id": "DEMO-<reference>",
  "code": 200
}
```

Un transfert interne prend un wallet `TGW...` obtenu à l'inscription d'un
second compte démo :

```json
{
  "montant": 1000,
  "destinataire_wallet_id": "TGW..."
}
```

Le débit, les frais et le crédit du destinataire sont enregistrés atomiquement.
Les transferts externes restent en attente et ne débitent pas le solde sans
confirmation. Un rejeu de confirmation de recharge ou d'encaissement ne crédite
jamais le compte une seconde fois.

## Vérifications

Depuis `api/` :

```sh
php artisan migrate
php artisan test --filter=DemoApiTest
```

Les tests couvrent l'inscription/connexion, la révocation et l'isolation des
jetons, l'indépendance des soldes, les transferts, le cycle de recharge, la
pagination, les routes KondjiPro et l'absence de secrets dans la configuration
publique.
