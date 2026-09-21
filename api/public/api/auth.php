<?php
/**
 * auth.php — Authentification KondjiPro (helper central).
 *
 * Fournit :
 *   - session_start_secure()  : démarre une session PHP sécurisée (cookie HttpOnly, SameSite=Lax).
 *   - auth_attempt()          : vérifie username/email/phone + mot de passe (bcrypt) → crée la session.
 *   - auth_user()             : renvoie l'utilisateur courant (array) ou null.
 *   - auth_check()            : booléen « un utilisateur est connecté ».
 *   - auth_require()          : garde API — renvoie 401 JSON si non connecté.
 *   - auth_logout()           : détruit la session.
 *
 * La table cible est `users` de la base MySQL/SQLite résolue par db_config().
 * Les mots de passe sont vérifiés via password_verify() (hash bcrypt).
 */

require_once __DIR__ . '/../config/db.php';

if (!function_exists('normalize_phone_e164')) {
    /**
     * Normalise un numéro de téléphone au format E.164.
     *
     * - "+22890112233"  → "+22890112233"
     * - "0022890112233" → "+22890112233"
     * - "90112233"      → "+22890112233" (indicatif Togo par défaut)
     *
     * Retourne null si le numéro est vide ou invalide.
     */
    function normalize_phone_e164(string $phone): ?string {
        $clean = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if ($clean === '') return null;
        if (str_starts_with($clean, '+')) {
            return $clean;
        }
        if (str_starts_with($clean, '00')) {
            return '+' . substr($clean, 2);
        }
        return '+228' . $clean; // indicatif Togo par défaut
    }
}

if (!function_exists('ensure_users_table')) {
    /**
     * Garantit l'existence de la table `users` (création idempotente).
     *
     * La table peut manquer (base SQLite serveur KondjiPro créée avant la
     * fonctionnalité d'auth). Le schéma est aligné sur les migrations Laravel
     * (users + auth_fields + phone_and_role + solde) et portable SQLite/MySQL.
     */
    function ensure_users_table(): void {
        $pdo = db_connect();
        if (!$pdo) return;

        // La table existe déjà ?
        try {
            $pdo->query('SELECT COUNT(*) FROM users');
            return;
        } catch (PDOException $e) {
            // table absente → on la crée
        }

        $driver = db_config()['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                username TEXT UNIQUE,
                device_id TEXT,
                platform TEXT DEFAULT 'web',
                is_online INTEGER DEFAULT 0,
                api_token TEXT UNIQUE,
                email TEXT UNIQUE,
                email_verified_at TEXT,
                password TEXT NOT NULL,
                remember_token TEXT,
                phone_number TEXT UNIQUE,
                phone_verified INTEGER DEFAULT 0,
                role TEXT DEFAULT 'patient' CHECK (role IN ('patient','medecin')),
                solde REAL DEFAULT 0,
                created_at TEXT,
                updated_at TEXT
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                username VARCHAR(255) UNIQUE,
                device_id VARCHAR(255) NULL,
                platform VARCHAR(20) DEFAULT 'web',
                is_online TINYINT(1) DEFAULT 0,
                api_token VARCHAR(64) UNIQUE,
                email VARCHAR(255) UNIQUE,
                email_verified_at TIMESTAMP NULL,
                password VARCHAR(255) NOT NULL,
                remember_token VARCHAR(100) NULL,
                phone_number VARCHAR(30) UNIQUE,
                phone_verified TINYINT(1) DEFAULT 0,
                role ENUM('patient','medecin') DEFAULT 'patient',
                solde DECIMAL(10,2) DEFAULT 0,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )");
        }
    }
}

if (!function_exists('session_start_secure')) {
    function session_start_secure(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_name('kondjipro_session');
        session_start();
    }
}

if (!function_exists('auth_attempt')) {
    /**
     * @param string $identifier username, email ou phone_number
     * @param string $password   mot de passe en clair
     * @return array|null        utilisateur (array) si succès, null sinon
     */
    function auth_attempt(string $identifier, string $password): ?array {
        $pdo = db_connect();
        if (!$pdo) return null;

        // Garantit la table users (création idempotente si absente).
        ensure_users_table();

        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') return null;

        // Si l'identifiant ressemble à un numéro de téléphone, on le normalise
        // en E.164 pour matcher la valeur stockée (ex. "+228 90 11 22 33" → "+22890112233").
        $identifierPhone = normalize_phone_e164($identifier);

        $stmt = $pdo->prepare(
            'SELECT * FROM users
              WHERE username = :id_u OR email = :id_e OR phone_number = :id_p
              LIMIT 1'
        );
        $stmt->execute([
            ':id_u' => $identifier,
            ':id_e' => $identifier,
            ':id_p' => $identifierPhone ?? $identifier,
        ]);
        $user = $stmt->fetch();

        if (!$user || !isset($user['password'])) return null;
        if (!password_verify($password, $user['password'])) return null;

        // Régénération de l'ID de session (anti fixation).
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['username']      = $user['username'] ?? '';
        $_SESSION['name']          = $user['name'] ?? '';
        $_SESSION['role']          = $user['role'] ?? 'patient';
        $_SESSION['phone_number']  = $user['phone_number'] ?? '';
        $_SESSION['login_at']      = time();

        // Lien compte → fiche patient (rôle patient) : par user_id, avec
        // fallback sur le téléphone (fiche préexistante créée avant l'auth).
        if (($user['role'] ?? 'patient') === 'patient') {
            require_once __DIR__ . '/patient.php';
            $patient = patient_for_user((int)$user['id'], $user['phone_number'] ?? null);
            $_SESSION['patient_id'] = $patient ? (int)$patient['id'] : null;
        } else {
            $_SESSION['patient_id'] = null;
        }

        // Ne jamais exposer le hash dans la session.
        unset($user['password'], $user['api_token'], $user['remember_token']);
        return $user;
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array {
        session_start_secure();
        if (empty($_SESSION['user_id'])) return null;
        return [
            'id'           => (int)$_SESSION['user_id'],
            'username'     => $_SESSION['username'] ?? '',
            'name'         => $_SESSION['name'] ?? '',
            'role'         => $_SESSION['role'] ?? 'patient',
            'phone_number' => $_SESSION['phone_number'] ?? '',
            'patient_id'   => isset($_SESSION['patient_id']) ? (int)$_SESSION['patient_id'] : null,
            'login_at'     => $_SESSION['login_at'] ?? null,
        ];
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool {
        return auth_user() !== null;
    }
}

if (!function_exists('auth_require')) {
    /**
     * Garde API : renvoie 401 JSON et stoppe le script si non connecté.
     */
    function auth_require(): array {
        $user = auth_user();
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'   => 'unauthorized',
                'message' => 'Authentification requise. Veuillez vous connecter.',
            ]);
            exit;
        }
        return $user;
    }
}

if (!function_exists('auth_logout')) {
    function auth_logout(): void {
        session_start_secure();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}

if (!function_exists('auth_redirect_guest')) {
    /**
     * Garde page : redirige vers la page de connexion si non connecté.
     */
    function auth_redirect_guest(string $loginUrl = '/pages/login'): void {
        if (!auth_check()) {
            header('Location: ' . $loginUrl);
            exit;
        }
    }
}

/* ── CSRF Token ────────────────────────────────────────────── */

if (!function_exists('csrf_generate')) {
    /**
     * Génère ou retourne le token CSRF de la session courante.
     * Un token est attaché à la session et régénéré si absent.
     */
    function csrf_generate(): string {
        session_start_secure();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_validate')) {
    /**
     * Valide un token CSRF envoyé via header X-CSRF-Token ou champ _token.
     * Renvoie true si valide, false sinon.
     */
    function csrf_validate(?string $token): bool {
        session_start_secure();
        if (!$token || empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('csrf_require')) {
    /**
     * Garde CSRF : vérifie le token et renvoie 403 si invalide.
     * À appeler avant tout traitement POST côté API.
     */
    function csrf_require(): void {
        // GET, HEAD, OPTIONS n'ont pas besoin de CSRF
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? ($_POST['_token'] ?? null)
              ?? ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? null);

        if (!csrf_validate($token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'   => 'csrf_invalid',
                'message' => 'Token CSRF invalide ou manquant.',
            ]);
            exit;
        }
    }
}

/* ── Rate Limiting (file-based) ────────────────────────────── */

if (!function_exists('rate_limit_check')) {
    /**
     * Vérifie et enregistre une tentative pour une clé donnée (ex. "login:IP").
     * Utilise des fichiers temporaires dans /tmp/ pour éviter la dépendance BDD.
     *
     * @param string $key          Clé unique (ex. "login:1.2.3.4")
     * @param int    $maxAttempts  Nombre max de tentatives autorisées
     * @param int    $window       Fenêtre en secondes (défaut : 15 min)
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    function rate_limit_check(string $key, int $maxAttempts = 10, int $window = 900): array {
        $dir = sys_get_temp_dir() . '/yam_ratelimit';
        if (!is_dir($dir)) mkdir($dir, 0700, true);

        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $file = $dir . '/' . $safeKey . '.json';

        $now = time();
        $attempts = [];

        // Lecture des tentatives existantes
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $attempts = $raw ? json_decode($raw, true) : [];
            if (!is_array($attempts)) $attempts = [];
        }

        // Nettoyage des tentatives expirées
        $attempts = array_filter($attempts, fn($ts) => ($now - $ts) < $window);
        $attempts = array_values($attempts);

        $count = count($attempts);
        $allowed = $count < $maxAttempts;

        if ($allowed) {
            $attempts[] = $now;
            file_put_contents($file, json_encode($attempts), LOCK_EX);
            $count++;
        }

        $remaining = max(0, $maxAttempts - $count);
        $retryAfter = $count >= $maxAttempts
            ? $window - ($now - ($attempts[0] ?? $now))
            : 0;

        return [
            'allowed'     => $allowed,
            'remaining'   => $remaining,
            'retry_after' => max(0, $retryAfter),
        ];
    }
}