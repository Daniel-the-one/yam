# Publier un lien Postman public — YAM

Procédure pour rendre la collection Postman partageable par lien, et état
réel de la publication à la date du 07/10/2026.

## Ce qui existe dans `api/postman/`

| Fichier | Rôle | Requêtes |
|---|---|---|
| `KondjiPro_API.postman_collection.json` | Suite de test complète (toute l'API, dossiers 00→99) | 67 |
| `KondjiPro_Local.postman_environment.json` | Environnement local (`baseUrl = http://127.0.0.1:8081`) | — |
| `KondjiPro_Production.postman_environment.json` | Environnement prod (`https://yam.mdkrlabs.dev`) | — |
| `Yam-Demo-API.postman_collection.json` | Collection **publique** de démonstration (fonds fictifs) | 9 |

La collection publique est aussi servie par le site à
`/Yam-Demo-API.postman_collection.json` (copie dans `api/public/`).
`base_url` y pointe vers la prod : c'est voulu, un destinataire du lien doit
pouvoir tester sans serveur local.

## État réel (07/10/2026, soir) — lien d'import opérationnel

- `https://yam.mdkrlabs.dev/api/demo/v1` → **200** ✅ (backend déployé).
- `https://yam.mdkrlabs.dev/demo.html` → **200** ✅.
- `https://yam.mdkrlabs.dev/Yam-Demo-API.postman_collection.json` → **200** ✅.

**Lien d'import auto-hébergé (prêt à diffuser) :**

```
https://yam.mdkrlabs.dev/Yam-Demo-API.postman_collection.json
```

Dans Postman : **Import → From link** → coller l'URL. `base_url` pointe déjà
vers la prod, donc l'import fonctionne sans serveur local.

Validation : `newman run api/postman/Yam-Demo-API.postman_collection.json`
→ 9 requêtes / 8 assertions / **0 échec** contre la prod.

> Historique : au 07/10/2026 matin, ces trois URL renvoyaient 404 (backend prod
> en retard). Le déploiement du backend + de `public/` les a rétablies.

## Procédure

### 1. Déployer l'API démo sur la prod (approbation requise)

```bash
cd deploy
./deploy-backend.sh --check        # liste ce qui sera poussé
./deploy-backend.sh --upload       # pousse app/ routes/ config/ migrations/
./deploy.sh                        # pousse public/ (dont la collection démo)
```

Vérifications :

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://yam.mdkrlabs.dev/api/demo/v1
curl -s -o /dev/null -w '%{http_code}\n' https://yam.mdkrlabs.dev/Yam-Demo-API.postman_collection.json
```

### 2. Créer le lien partageable (application Postman)

1. Postman → **Import** → fichier `api/postman/Yam-Demo-API.postman_collection.json`.
2. Clic droit sur la collection → **Share** → **Create public link**
   (ou *Share to workspace* puis activer le lien public).
3. Copier le lien `https://www.postman.com/…/collection/…` et le diffuser.
4. Tester le lien en navigation privée : l'import doit fonctionner, et
   `base_url` doit pointer vers `https://yam.mdkrlabs.dev`.

### 3. Alternative auto-hébergée (sans compte Postman)

`https://yam.mdkrlabs.dev/Yam-Demo-API.postman_collection.json`
→ dans Postman : **Import → From link**. (404 tant que `deploy.sh` n'a pas
poussé la copie.)

## Validation locale avant publication

```bash
# Suite complète (doit être 100 % verte)
npx --yes newman run api/postman/KondjiPro_API.postman_collection.json \
  -e api/postman/KondjiPro_Local.postman_environment.json --color off

# Collection publique (on force le local pour ne jamais toucher la prod)
npx --yes newman run api/postman/Yam-Demo-API.postman_collection.json \
  --env-var base_url=http://127.0.0.1:8081 --color off
```

Référence actuelle : 67 requêtes / 148 assertions / 0 échec (suite complète),
9 requêtes / 8 assertions / 0 échec (démo).

## Règles

- La collection publique ne contient **que des fonds fictifs** (démo). Ne
  jamais y ajouter de jeton réel, ni d'environnement contenant des secrets.
- `KondjiPro_API` pointe par défaut vers le **local** (`127.0.0.1:8081`) :
  un « Run collection » sans environnement ne doit jamais atteindre la prod.
- Domaine exact : **`yam.mdkrlabs.dev`**. Attention au typo `yam.mdkarlabs.dev`
  qui est NXDOMAIN.
- `api/Yam-API.postman_collection.json` (racine) est un **ancien export**
  (30/09) de la suite complète, devenu obsolète : le supprimer ou le
  régénérer pour éviter deux sources de vérité.