# Migration SQLite → MySQL — Production o2switch

Ce dossier contient le kit de migration vers MySQL, à la fois pour la base
**Laravel YAM** (API appels) et la base **KondjiPro** (cabinet médical).

## État actuel (constaté sur le serveur)

| Élément | Valeur |
|---|---|
| PHP prod | 8.4.25 |
| `pdo_mysql` | disponible ✅ |
| MySQL | écoute sur `localhost:3306` ✅ |
| Docroot | `/home/koyv0696/public_html/yam/public` |
| Base SQLite prod | `/home/koyv0696/public_html/yam/database/database.sqlite` |
| `.env` Laravel prod | `/home/koyv0696/public_html/yam/.env` (actuellement `DB_CONNECTION=sqlite`) |

**Seul prérequis manquant : créer les bases MySQL dans cPanel** (o2switch →
« Bases de données MySQL »). L'accès FTP ne permet pas de créer une base.

## 1. Créer les bases dans cPanel (action utilisateur)

Dans cPanel o2switch → **Bases de données MySQL / MySQL Databases** :

1. Créer une base `kondjipro` → devient `koyv0696_kondjipro`
2. Créer une base `yam` → devient `koyv0696_yam`
3. Créer un utilisateur `kpro` avec un mot de passe fort → devient `koyv0696_kpro`
4. Ajouter cet utilisateur aux **deux** bases avec **ALL PRIVILEGES**

> Note : o2switch préfixe systématiquement noms de bases et d'utilisateurs par
> le compte cPanel (`koyv0696_`). Utilisez les noms complets dans la config.

## 2. Préparer la configuration

Copier `migrate-config.example.json` en `migrate-config.json` et renseigner les
identifiants créés à l'étape 1 (+ un token secret).

⚠️ `migrate-config.json` contient des secrets : il est dans `.gitignore` et doit
être **supprimé du serveur** juste après la migration.

## 3. Tester en local (recommandé, sans risque)

Le script est idempotent et testable avec une instance MySQL locale :

```bash
php deploy/prod-migration/migrate.php
```

## 4. Exécuter en production

Chez o2switch il n'y a **pas de SSH** : on passe par HTTP, protégé par token.

```bash
# Upload du kit dans un dossier temporaire du docroot
curl -u "$FTP_USER:$FTP_PASS" --ftp-create-dirs \
  -T deploy/prod-migration/migrate.php "ftp://$HOST/public/_migrate/migrate.php"
# idem pour yam-schema.sql, kondjipro-schema.sql, kondjipro-seed.sql,
# migrate-config.json
```

Puis naviguer une fois vers :

```
https://yam.mdkrlabs.dev/_migrate/migrate.php?t=VOTRE_TOKEN
```

Vérifier la sortie : « Terminé sans erreur ».

## 5. Basculer Laravel sur MySQL

Mettre à jour le `.env` de prod (FTP) :

```ini
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=koyv0696_yam
DB_USERNAME=koyv0696_kpro
DB_PASSWORD=***
```

Puis écrire `public/config/db.local.php` sur le serveur (KondjiPro) :

```php
<?php
return [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'koyv0696_kondjipro',
    'user' => 'koyv0696_kpro',
    'pass' => '***',
];
```

## 6. Vérifier

- `https://yam.mdkrlabs.dev/api/v1/config` → 200
- `https://yam.mdkrlabs.dev/dashboard.php` → les compteurs reflètent la base
- `https://yam.mdkrlabs.dev/api/patients.php` → patients avec UUID

## 7. Nettoyage (obligatoire)

1. Supprimer le dossier `/public/_migrate/` (script + config + SQL)
2. Conserver `database/database.sqlite` comme sauvegarde (ne pas le supprimer),
   il n'est plus utilisé une fois `DB_CONNECTION=mysql`.

## Rollback

En cas de problème : remettre `DB_CONNECTION=sqlite` dans `.env` et supprimer
`public/config/db.local.php` → retour immédiat à l'ancien fonctionnement.

## Contenu du dossier

| Fichier | Rôle |
|---|---|
| `migrate.php` | Script de migration (schémas + copie des données) |
| `yam-schema.sql` | Schéma MySQL Laravel (14 tables, généré par `mysqldump`) |
| `kondjipro-schema.sql` | Schéma MySQL KondjiPro (DDL idempotent) |
| `kondjipro-seed.sql` | Données d'exemple KondjiPro (si base vide) |
| `migrate-config.example.json` | Modèle de configuration |
