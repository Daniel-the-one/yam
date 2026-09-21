<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../auth.php';
$user = auth_user();
if (!$user) { http_response_code(401); echo json_encode(['error'=>'unauthorized','message'=>'Non connecté.']); exit; }
echo json_encode(['user'=>$user]);
