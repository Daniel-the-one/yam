<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../auth.php';
auth_logout();
echo json_encode(['message' => 'Déconnexion réussie.']);
