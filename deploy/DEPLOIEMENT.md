# Déploiement o2switch — YAM

> **Railway abandonné** (2026-09-09). La production est hébergée sur **o2switch**
> (`https://yam.mdkrlabs.dev`). Tout le déploiement passe par FTP.

---

## Architecture de déploiement

```
yam/api/                        ← dépôt local (repo git "yam-api")
├── public/                     ← dossier WEB servi par nginx (SPA + index.php)
│   └── ...                     ← déployé par deploy.sh
├── app/ routes/ config/ ...    ← backend Laravel (vit au-dessus de public/)
│   └── ...                     ← déployé par deploy-backend.sh
```

Sur o2switch, le vhost pointe vers `public/`. Le backend Laravel vit dans le
dossier parent (structure Laravel standard).

---

## Scripts

| Script | Rôle | Cible |
|--------|------|-------|
| `deploy/deploy.sh` | Frontend SPA + assets | `api/public/` → `/public` |
| `deploy/deploy-backend.sh` | Backend PHP Laravel | `api/{app,routes,config,...}` → racine |

Config FTP : `~/.yam-deploy/yam.conf` (hôte `ftp.koyv0696.odns.fr`, user
`yam@mdkrlabs.dev`, dossier local `api/public`, dossier distant `/public`).
**Jamais commitée** (chmod 600).

---

## Procédure de déploiement

### 1. Backend PHP (après chaque modification PHP)

```bash
cd deploy
./deploy-backend.sh
```

Puis, sur le serveur (SSH o2switch ou manager) :

```bash
cd ~/www/yam.mdkrlabs.dev   # adapter le chemin
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear     # ou config:cache après vérification
php artisan route:list | grep call   # vérifier les routes
```

### 2. Frontend web (après chaque modification JS/CSS)

```bash
cd deploy
./deploy.sh yam              # tout public/
# ou fichier unique :
./deploy.sh yam js/spa-calls.js
```

### 3. Secret FCM (une seule fois)

Le fichier `storage/firebase/service-account.json` est **gitignoré** → absent du
déploiement. Deux options :

- **Option A (recommandée)** : déposer le fichier sur le serveur :
  ```bash
  ./deploy.sh yam storage/firebase/service-account.json   # adapter le chemin
  ```
  (ou FTP manuel vers `storage/firebase/service-account.json` à la racine)
- **Option B** : variable d'environnement dans le `.env` serveur :
  ```
  FIREBASE_SERVICE_ACCOUNT_JSON=<contenu du JSON ou base64>
  ```
  Générer le base64 : `base64 -w0 storage/firebase/service-account.json`

### 4. Vérification

```bash
# Route cancel présente (plus de 404)
curl -X POST https://yam.mdkrlabs.dev/api/v1/call/cancel \
  -H "Content-Type: application/json" -d '{"call_id":"test","from_device_id":"test"}'

# Route debug protégée (404 si APP_DEBUG=false)
curl https://yam.mdkrlabs.dev/api/v1/debug/logs

# Config push active
curl https://yam.mdkrlabs.dev/api/v1/config
```

---

## Notes

- **Pusher.com** (SaaS) assure la signalisation temps réel — **pas de Reverb**
  en production (fichiers Docker/nginx/Reverb supprimés avec Railway).
- Le `.env` serveur n'est **jamais** poussé par FTP (gitignoré + exclu des scripts).
- Après un déploiement backend, toujours vérifier `config:clear` si le `.env`
  a changé (sinon `config:cache` fige les anciennes valeurs).