-- ============================================================
-- Base de données KondjiPro — variante SQLite
-- Utilisée quand MySQL n'est pas disponible (hébergement sans
-- accès cPanel) : le fichier .sqlite est stocké HORS du docroot.
-- Ce fichier contient le schéma ET les données d'exemple afin de
-- pouvoir générer directement la base :
--   sqlite3 kondjipro.sqlite < schema.sqlite.sql
-- ============================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS patients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT UNIQUE,
    nom TEXT NOT NULL,
    telephone TEXT NOT NULL,
    date_naissance TEXT,
    adresse TEXT,
    sexe TEXT CHECK (sexe IN ('M','F')),
    photo TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Profil du médecin (1 seule ligne active)
CREATE TABLE IF NOT EXISTS medecins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    prenom TEXT NOT NULL DEFAULT '',
    nom TEXT NOT NULL DEFAULT '',
    telephone TEXT NOT NULL DEFAULT '',
    specialite TEXT NOT NULL DEFAULT 'Médecin généraliste',
    photo TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS consultations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    date_consultation TEXT DEFAULT CURRENT_TIMESTAMP,
    motif TEXT,
    diagnostic TEXT,
    notes TEXT,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ordonnances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    date_ordonnance TEXT DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ordonnance_medicaments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ordonnance_id INTEGER NOT NULL,
    nom TEXT NOT NULL,
    quantite INTEGER NOT NULL DEFAULT 1,
    unite TEXT,
    frequence TEXT,
    FOREIGN KEY (ordonnance_id) REFERENCES ordonnances(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS factures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    date_facture TEXT DEFAULT CURRENT_TIMESTAMP,
    total INTEGER NOT NULL DEFAULT 0,
    statut TEXT DEFAULT 'en_attente' CHECK (statut IN ('en_attente','payee','annulee')),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS facture_actes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    facture_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    libelle TEXT NOT NULL,
    prix INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS transactions_encaissement (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    montant INTEGER NOT NULL,
    motif TEXT,
    reference TEXT,
    date_transaction TEXT DEFAULT CURRENT_TIMESTAMP,
    statut TEXT DEFAULT 'en_attente' CHECK (statut IN ('en_attente','reussi','echec')),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_consult_patient ON consultations(patient_id);
CREATE INDEX IF NOT EXISTS idx_fact_patient    ON factures(patient_id);
CREATE INDEX IF NOT EXISTS idx_enc_patient     ON transactions_encaissement(patient_id);

-- ============================================================
-- Données d'exemple
-- ============================================================
INSERT INTO patients (uuid, nom, telephone, date_naissance, sexe, adresse) VALUES
  (lower(hex(randomblob(4))||'-'||hex(randomblob(2))||'-4'||substr(hex(randomblob(2)),2)||'-a'||substr(hex(randomblob(2)),2)||'-'||hex(randomblob(6))), 'Jean KOFFI',   '+229 90 12 34 56', '1985-04-12', 'M', 'Cotonou, Akpakpa'),
  (lower(hex(randomblob(4))||'-'||hex(randomblob(2))||'-4'||substr(hex(randomblob(2)),2)||'-a'||substr(hex(randomblob(2)),2)||'-'||hex(randomblob(6))), 'Ama ASSOGBA',  '+229 96 78 11 22', '1992-09-23', 'F', 'Porto-Novo, Centre'),
  (lower(hex(randomblob(4))||'-'||hex(randomblob(2))||'-4'||substr(hex(randomblob(2)),2)||'-a'||substr(hex(randomblob(2)),2)||'-'||hex(randomblob(6))), 'Kossi AGBEKO', '+229 95 44 33 22', '1978-01-30', 'M', 'Calavi, Godomey');

INSERT INTO consultations (patient_id, date_consultation, motif, diagnostic) VALUES
  (1, datetime('now','-2 day'), 'Fièvre persistante',  'Paludisme simple'),
  (2, datetime('now','-5 day'), 'Contrôle tension',    'HTA stade 1'),
  (3, datetime('now','-7 day'), 'Douleurs articulaires','Arthrose genou');

INSERT INTO ordonnances (patient_id, date_ordonnance, notes) VALUES
  (1, datetime('now','-2 day'), 'Repos 3 jours, hydratation'),
  (2, datetime('now','-5 day'), 'Surveillance tensionnelle');

INSERT INTO ordonnance_medicaments (ordonnance_id, nom, quantite, unite, frequence) VALUES
  (1, 'Paracétamol 500mg', 21, 'comprimé', '3x/jour pendant 7j'),
  (1, 'Coartem',          24, 'comprimé', '2x/jour pendant 3j'),
  (2, 'Amlodipine 5mg',   30, 'comprimé', '1x/jour le matin');

INSERT INTO factures (patient_id, date_facture, total, statut) VALUES
  (1, datetime('now','-2 day'),  5000, 'payee'),
  (2, datetime('now','-1 day'),  3000, 'en_attente'),
  (3, datetime('now','-3 hour'), 2500, 'en_attente');

INSERT INTO facture_actes (facture_id, code, libelle, prix) VALUES
  (1, 'C001', 'Consultation', 5000),
  (2, 'C002', 'Pansement',    3000),
  (3, 'C003', 'Injection',    2500);

INSERT INTO transactions_encaissement (patient_id, montant, motif, statut) VALUES
  (1, 5000, 'Consultation', 'reussi'),
  (2, 3000, 'Pansement',    'reussi');

INSERT INTO medecins (prenom, nom, telephone, specialite) VALUES
  ('Mensah', '', '+229 97 00 00 00', 'Médecin généraliste');
