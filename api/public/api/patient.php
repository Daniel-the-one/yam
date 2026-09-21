<?php
/**
 * patient.php — Helper central de l'espace patient KondjiPro.
 *
 * Fournit :
 *   - ensure_patient_schema()   : migration idempotente — ajoute `user_id`
 *                                 à `patients` et crée la table `rendez_vous`.
 *   - patient_for_user()        : fiche patient liée au compte connecté
 *                                 (par user_id, fallback téléphone).
 *   - ensure_patient_for_user() : crée la fiche patient si elle manque
 *                                 (register patient / premier login).
 *
 * Portable SQLite / MySQL (même logique que ensure_users_table()).
 */

require_once __DIR__ . '/../config/db.php';

if (!function_exists('ensure_patient_schema')) {
    /**
     * Garantit le schéma de l'espace patient (idempotent) :
     *   1. colonne `user_id` dans `patients` (lien compte → fiche patient) ;
     *   2. table `rendez_vous` (prise de rendez-vous patient).
     */
    function ensure_patient_schema(): void {
        $pdo = db_connect();
        if (!$pdo) return;

        $driver = db_config()['driver'] ?? 'mysql';

        // ---------- 1. Colonne user_id dans patients ----------
        $hasUserId = false;
        try {
            if ($driver === 'sqlite') {
                $cols = $pdo->query('PRAGMA table_info(patients)')->fetchAll();
                foreach ($cols as $c) {
                    if (($c['name'] ?? '') === 'user_id') { $hasUserId = true; break; }
                }
            } else {
                $stmt = $pdo->query("SHOW COLUMNS FROM patients LIKE 'user_id'");
                $hasUserId = (bool)$stmt->fetch();
            }
        } catch (PDOException $e) {
            // table patients absente → on la crée ci-dessous
        }

        if (!$hasUserId) {
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec('ALTER TABLE patients ADD COLUMN user_id INTEGER');
                } else {
                    $pdo->exec('ALTER TABLE patients ADD COLUMN user_id BIGINT UNSIGNED NULL');
                }
                // Index (portable : nom d'index court pour MySQL).
                $pdo->exec('CREATE INDEX idx_patients_user ON patients(user_id)');
            } catch (PDOException $e) {
                error_log('[kondjipro] ensure_patient_schema user_id: ' . $e->getMessage());
            }
        }

        // ---------- 2. Colonnes profil patient (groupe sanguin, allergies, etc.) ----------
        $profileCols = [
            'groupe_sanguin'  => $driver === 'sqlite' ? 'TEXT'                : 'VARCHAR(10) NULL',
            'allergies'       => $driver === 'sqlite' ? 'TEXT'                : 'TEXT NULL',
            'assurance'       => $driver === 'sqlite' ? 'TEXT'                : 'VARCHAR(120) NULL',
            'contact_urgence' => $driver === 'sqlite' ? 'TEXT'                : 'VARCHAR(255) NULL',
            'photo_profil'    => $driver === 'sqlite' ? 'TEXT'                : 'VARCHAR(255) NULL',
            'bio'             => $driver === 'sqlite' ? 'TEXT'                : 'TEXT NULL',
        ];
        // Récupérer les colonnes existantes
        $existingCols = [];
        try {
            if ($driver === 'sqlite') {
                foreach ($pdo->query('PRAGMA table_info(patients)')->fetchAll() as $c) {
                    $existingCols[] = $c['name'] ?? '';
                }
            } else {
                foreach ($pdo->query('SHOW COLUMNS FROM patients')->fetchAll() as $c) {
                    $existingCols[] = $c['Field'] ?? '';
                }
            }
        } catch (PDOException $e) {}
        foreach ($profileCols as $col => $type) {
            if (!in_array($col, $existingCols, true)) {
                try {
                    $pdo->exec("ALTER TABLE patients ADD COLUMN $col $type");
                } catch (PDOException $e) {
                    error_log("[kondjipro] ensure_patient_schema add $col: " . $e->getMessage());
                }
            }
        }

        // ---------- 3. Enrichir la table medecins (bio, etc.) ----------
        if ($pdo) {
            $medCols = [];
            try {
                if ($driver === 'sqlite') {
                    foreach ($pdo->query('PRAGMA table_info(medecins)')->fetchAll() as $c) {
                        $medCols[] = $c['name'] ?? '';
                    }
                } else {
                    foreach ($pdo->query('SHOW COLUMNS FROM medecins')->fetchAll() as $c) {
                        $medCols[] = $c['Field'] ?? '';
                    }
                }
                $medNewCols = [
                    'bio'             => $driver === 'sqlite' ? 'TEXT' : 'TEXT NULL',
                    'horaires'        => $driver === 'sqlite' ? 'TEXT' : 'TEXT NULL',
                    'accepte_rdv'     => $driver === 'sqlite' ? 'INTEGER DEFAULT 1' : 'TINYINT(1) DEFAULT 1',
                ];
                foreach ($medNewCols as $col => $type) {
                    if (!in_array($col, $medCols, true)) {
                        try {
                            $pdo->exec("ALTER TABLE medecins ADD COLUMN $col $type");
                        } catch (PDOException $e) {
                            error_log("[kondjipro] ensure_patient_schema add medecins.$col: " . $e->getMessage());
                        }
                    }
                }
            } catch (PDOException $e) {}
        }

        // ---------- 4. Table rendez_vous ----------
        try {
            $pdo->query('SELECT COUNT(*) FROM rendez_vous');
            return; // existe déjà
        } catch (PDOException $e) {
            // absente → on la crée
        }

        try {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS rendez_vous (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    patient_id INTEGER NOT NULL,
                    medecin_id INTEGER NOT NULL,
                    date_rdv TEXT NOT NULL,
                    motif TEXT,
                    statut TEXT DEFAULT 'en_attente'
                        CHECK (statut IN ('en_attente','confirme','annule','termine')),
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
                    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE
                )");
                $pdo->exec('CREATE INDEX idx_rdv_patient ON rendez_vous(patient_id)');
                $pdo->exec('CREATE INDEX idx_rdv_medecin ON rendez_vous(medecin_id)');
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS rendez_vous (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    patient_id BIGINT UNSIGNED NOT NULL,
                    medecin_id BIGINT UNSIGNED NOT NULL,
                    date_rdv DATETIME NOT NULL,
                    motif VARCHAR(255) NULL,
                    statut VARCHAR(20) NOT NULL DEFAULT 'en_attente',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_rdv_patient (patient_id),
                    INDEX idx_rdv_medecin (medecin_id),
                    CONSTRAINT fk_rdv_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
                    CONSTRAINT fk_rdv_medecin FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE
                )");
            }
        } catch (PDOException $e) {
            error_log('[kondjipro] ensure_patient_schema rendez_vous: ' . $e->getMessage());
        }
    }
}

if (!function_exists('patient_for_user')) {
    /**
     * Fiche patient liée au compte connecté.
     * Priorité : user_id ; fallback : téléphone normalisé (E.164).
     */
    function patient_for_user(int $userId, ?string $phone = null): ?array {
        $pdo = db_connect();
        if (!$pdo) return null;

        ensure_patient_schema();

        if ($userId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM patients WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch();
            if ($row) return $row;
        }

        if ($phone !== null && $phone !== '') {
            $e164 = normalize_phone_e164($phone);
            if ($e164 !== null) {
                // Recherche tolérante aux espaces/tirets : les fiches historiques
                // stockent le téléphone au format "+229 90 12 34 56" (avec espaces),
                // les nouveaux comptes en E.164 "+22990123456". REPLACE est portable
                // SQLite/MySQL (pas d'index utilisé, acceptable sur petite table).
                $clean = str_replace([' ', '-', '.', '(', ')'], '', $e164);
                $stmt = $pdo->prepare(
                    'SELECT * FROM patients
                      WHERE user_id IS NULL
                        AND REPLACE(REPLACE(REPLACE(telephone, " ", ""), "-", ""), ".", "") = :p
                      LIMIT 1'
                );
                $stmt->execute([':p' => $clean]);
                $row = $stmt->fetch();
                if ($row) {
                    // Lier définitivement la fiche au compte.
                    try {
                        $upd = $pdo->prepare('UPDATE patients SET user_id = :uid WHERE id = :id');
                        $upd->execute([':uid' => $userId, ':id' => $row['id']]);
                    } catch (PDOException $e) {
                        error_log('[kondjipro] patient_for_user lien user_id: ' . $e->getMessage());
                    }
                    return $row;
                }
            }
        }
        return null;
    }
}

if (!function_exists('ensure_patient_for_user')) {
    /**
     * Crée la fiche patient liée au compte si elle n'existe pas.
     * Retourne la fiche (existante ou créée), ou null si base absente.
     */
    function ensure_patient_for_user(int $userId, string $name, string $phone): ?array {
        $pdo = db_connect();
        if (!$pdo) return null;

        ensure_patient_schema();

        $existing = patient_for_user($userId, $phone);
        if ($existing) return $existing;

        $e164 = normalize_phone_e164($phone) ?? $phone;
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO patients (user_id, nom, telephone, created_at)
                 VALUES (:uid, :nom, :tel, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([':uid' => $userId, ':nom' => $name, ':tel' => $e164]);
            $id = (int)$pdo->lastInsertId();
            return [
                'id'        => $id,
                'user_id'   => $userId,
                'nom'       => $name,
                'telephone' => $e164,
            ];
        } catch (PDOException $e) {
            error_log('[kondjipro] ensure_patient_for_user: ' . $e->getMessage());
            return null;
        }
    }
}