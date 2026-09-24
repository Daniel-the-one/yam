-- ============================================================
-- Base KondjiPro — DONNÉES D'EXEMPLE (seed)
-- À n'exécuter que si la table `patients` est vide.
-- Le script migrate.php applique automatiquement cette condition.
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
