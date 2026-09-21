-- ============================================================
-- Base de données : kondjipro
-- Schéma SQL : tables, contraintes et données d'exemple
-- Compatible MySQL 5.7+ / MariaDB 10+
-- ============================================================

CREATE DATABASE IF NOT EXISTS kondjipro
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE kondjipro;

-- Table patients
CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NULL UNIQUE,
    nom VARCHAR(120) NOT NULL,
    telephone VARCHAR(30) NOT NULL,
    date_naissance DATE NULL,
    adresse VARCHAR(255) NULL,
    sexe ENUM('M','F') NULL,
    photo VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Profil du médecin (1 seule ligne active)
CREATE TABLE IF NOT EXISTS medecins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prenom VARCHAR(80) NOT NULL DEFAULT '',
    nom VARCHAR(80) NOT NULL DEFAULT '',
    telephone VARCHAR(30) NOT NULL DEFAULT '',
    specialite VARCHAR(120) NOT NULL DEFAULT 'Médecin généraliste',
    photo VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table consultations
CREATE TABLE IF NOT EXISTS consultations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    date_consultation DATETIME DEFAULT CURRENT_TIMESTAMP,
    motif VARCHAR(255) NULL,
    diagnostic TEXT NULL,
    notes TEXT NULL,
    CONSTRAINT fk_consult_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table ordonnances
CREATE TABLE IF NOT EXISTS ordonnances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    date_ordonnance DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    CONSTRAINT fk_ord_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table medicaments d'une ordonnance
CREATE TABLE IF NOT EXISTS ordonnance_medicaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ordonnance_id INT NOT NULL,
    nom VARCHAR(160) NOT NULL,
    quantite INT NOT NULL DEFAULT 1,
    unite VARCHAR(40) NULL,
    frequence VARCHAR(80) NULL,
    CONSTRAINT fk_med_ord FOREIGN KEY (ordonnance_id) REFERENCES ordonnances(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table factures
CREATE TABLE IF NOT EXISTS factures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    date_facture DATETIME DEFAULT CURRENT_TIMESTAMP,
    total INT NOT NULL DEFAULT 0,
    statut ENUM('en_attente','payee','annulee') DEFAULT 'en_attente',
    CONSTRAINT fk_fact_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Actes d'une facture
CREATE TABLE IF NOT EXISTS facture_actes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facture_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    libelle VARCHAR(160) NOT NULL,
    prix INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_acte_fact FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table transactions d'encaissement (KondjiPay)
CREATE TABLE IF NOT EXISTS transactions_encaissement (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    montant INT NOT NULL,
    motif VARCHAR(255) NULL,
    reference VARCHAR(60) NULL,
    date_transaction DATETIME DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('en_attente','reussi','echec') DEFAULT 'en_attente',
    CONSTRAINT fk_enc_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Données d'exemple (visibles dans les maquettes)
-- ============================================================
INSERT INTO patients (uuid, nom, telephone, date_naissance, sexe, adresse) VALUES
  (UUID(), 'Jean KOFFI',      '+229 90 12 34 56', '1985-04-12', 'M', 'Cotonou, Akpakpa'),
  (UUID(), 'Ama ASSOGBA',     '+229 96 78 11 22', '1992-09-23', 'F', 'Porto-Novo, Centre'),
  (UUID(), 'Kossi AGBEKO',    '+229 95 44 33 22', '1978-01-30', 'M', 'Calavi, Godomey');

INSERT INTO consultations (patient_id, date_consultation, motif, diagnostic) VALUES
  (1, NOW() - INTERVAL 2 DAY, 'Fièvre persistante',  'Paludisme simple'),
  (2, NOW() - INTERVAL 5 DAY, 'Contrôle tension',    'HTA stade 1'),
  (3, NOW() - INTERVAL 1 WEEK,'Douleurs articulaires','Arthrose genou');

INSERT INTO ordonnances (patient_id, date_ordonnance, notes) VALUES
  (1, NOW() - INTERVAL 2 DAY, 'Repos 3 jours, hydratation'),
  (2, NOW() - INTERVAL 5 DAY, 'Surveillance tensionnelle');

INSERT INTO ordonnance_medicaments (ordonnance_id, nom, quantite, unite, frequence) VALUES
  (1, 'Paracétamol 500mg', 21, 'comprimé', '3x/jour pendant 7j'),
  (1, 'Coartem',          24, 'comprimé', '2x/jour pendant 3j'),
  (2, 'Amlodipine 5mg',   30, 'comprimé', '1x/jour le matin');

INSERT INTO factures (patient_id, date_facture, total, statut) VALUES
  (1, NOW() - INTERVAL 2 DAY,  5000, 'payee'),
  (2, NOW() - INTERVAL 1 DAY,  3000, 'en_attente'),
  (3, NOW() - INTERVAL 3 HOUR, 2500, 'en_attente');

INSERT INTO facture_actes (facture_id, code, libelle, prix) VALUES
  (1, 'C001', 'Consultation', 5000),
  (2, 'C002', 'Pansement',    3000),
  (3, 'C003', 'Injection',    2500);

INSERT INTO transactions_encaissement (patient_id, montant, motif, statut) VALUES
  (1, 5000, 'Consultation', 'reussi'),
  (2, 3000, 'Pansement',    'reussi');

INSERT INTO medecins (prenom, nom, telephone, specialite) VALUES
  ('Mensah', '', '+229 97 00 00 00', 'Médecin généraliste');

-- ============================================================
-- MIGRATION v2 (bases existantes) : identifiant unique UUID
-- Exécuter UNE SEULE FOIS sur une base déjà créée :
--   ALTER TABLE patients ADD COLUMN uuid VARCHAR(36) NULL UNIQUE AFTER id;
--   UPDATE patients SET uuid = UUID() WHERE uuid IS NULL;
-- ============================================================
