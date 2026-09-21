<?php
/**
 * patient/_init.php — Amorçage commun des pages de l'espace patient.
 *
 * - Authentification requise (redirection vers /pages/login sinon).
 * - Rôle patient requis (un médecin est redirigé vers son dashboard).
 * - Fiche patient liée au compte connecté (user_id, fallback téléphone).
 *
 * Variables définies pour les pages :
 *   $user      array  — utilisateur connecté (auth_user())
 *   $patient   array  — fiche patient (patients)
 *   $patientId int    — id de la fiche patient (0 si absente)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/auth.php';
require_once __DIR__ . '/../api/patient.php';

// Garde d'authentification : redirection vers la connexion si non connecté.
auth_redirect_guest('/pages/login');

$user = auth_user();

// Garde de rôle : un médecin n'a pas d'espace patient.
if (($user['role'] ?? '') !== 'patient') {
    header('Location: /dashboard');
    exit;
}

// Fiche patient liée au compte (user_id, fallback téléphone).
$patient = patient_for_user((int)$user['id'], $user['phone_number'] ?? null);
$patientId = $patient ? (int)$patient['id'] : 0;