-- ============================================================
-- Base KondjiPro — SCHÉMA (DDL uniquement, version PRODUCTION)
-- Contrairement à kondjipro/schema.sql, ce fichier ne crée pas la
-- base ni ne fait de USE : la base est créée dans cPanel et le
-- script de migration se connecte directement dessus.
-- Idempotent : peut être rejoué sans erreur (CREATE TABLE IF NOT EXISTS).
-- Compatible MySQL 5.7+ / MariaDB 10+
-- ============================================================

CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NULL UNIQUE,
    nom VARCHAR(120) NOT NULL,
    telephone VARCHAR(30) NOT NULL,
    date_naissance DATE NULL,
    adresse VARCHAR(255) NULL,
    sexe ENUM('M','F') NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consultations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    date_consultation DATETIME DEFAULT CURRENT_TIMESTAMP,
    motif VARCHAR(255) NULL,
    diagnostic TEXT NULL,
    notes TEXT NULL,
    CONSTRAINT fk_consult_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ordonnances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    date_ordonnance DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    CONSTRAINT fk_ord_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ordonnance_medicaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ordonnance_id INT NOT NULL,
    nom VARCHAR(160) NOT NULL,
    quantite INT NOT NULL DEFAULT 1,
    unite VARCHAR(40) NULL,
    frequence VARCHAR(80) NULL,
    CONSTRAINT fk_med_ord FOREIGN KEY (ordonnance_id) REFERENCES ordonnances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS factures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    date_facture DATETIME DEFAULT CURRENT_TIMESTAMP,
    total INT NOT NULL DEFAULT 0,
    statut ENUM('en_attente','payee','annulee') DEFAULT 'en_attente',
    CONSTRAINT fk_fact_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS facture_actes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facture_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    libelle VARCHAR(160) NOT NULL,
    prix INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_acte_fact FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions_encaissement (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    montant INT NOT NULL,
    motif VARCHAR(255) NULL,
    reference VARCHAR(60) NULL,
    date_transaction DATETIME DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('en_attente','reussi','echec') DEFAULT 'en_attente',
    CONSTRAINT fk_enc_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
