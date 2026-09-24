#!/usr/bin/env python3
"""Génère une collection Postman v2.1.0 valide à partir des 4 fichiers JSON
de réponse de l'API externe Yam Wallet.

Usage : python3 build_postman_collection.py
"""
import json
import os

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(BASE_DIR, "Yam-Wallet-API.postman_collection.json")

BASE_URL = "http://127.0.0.1:8000"

def load(name):
    with open(os.path.join(BASE_DIR, name), "r", encoding="utf-8") as f:
        return json.load(f)

def make_url(path, query=None):
    raw = "{{base_url}}" + path
    url = {
        "raw": raw,
        "host": ["{{base_url}}"],
        "path": path.strip("/").split("/"),
    }
    if query:
        url["query"] = query
    return url

def make_response(name, body, code=200, status="OK"):
    return [{
        "name": name,
        "originalRequest": {},
        "status": status,
        "code": code,
        "header": [{"key": "Content-Type", "value": "application/json"}],
        "body": json.dumps(body, ensure_ascii=False, indent=4),
    }]

def make_request(method, url, body=None, description=""):
    req = {
        "method": method,
        "header": [{"key": "Content-Type", "value": "application/json"}],
        "url": url,
        "description": description,
    }
    if body is not None:
        req["body"] = {
            "mode": "raw",
            "raw": json.dumps(body, ensure_ascii=False, indent=2),
            "options": {"raw": {"language": "json"}},
        }
    return req

# ---------------------------------------------------------------- endpoints
wallet = load("recup_wallet.json")
recharge = load("recharge.json")
transfert = load("transfert.json")
transactions = load("listes des transactions.json")

items = [
    {
        "name": "1. Récupérer le wallet",
        "request": make_request(
            "GET",
            make_url("/api/v1/wallet"),
            description=(
                "Récupère le wallet de l'utilisateur connecté : solde, infos "
                "membre, stats du mois, dernières transactions, épargnes et "
                "assurances.\n\nAuthentification : Bearer token (Sanctum)."
            ),
        ),
        "response": make_response("Exemple de réponse — wallet", wallet),
    },
    {
        "name": "2. Recharger le wallet",
        "request": make_request(
            "POST",
            make_url("/api/v1/wallet/recharge"),
            body={
                "montant": 6040,
                "phone_number": "22891121670",
            },
            description=(
                "Initie une recharge via Mobile Money (CinetPay). Retourne un "
                "payment_token et une payment_url vers laquelle rediriger "
                "l'utilisateur (must_be_redirected = true).\n\n"
                "Authentification : Bearer token (Sanctum)."
            ),
        ),
        "response": make_response("Exemple de réponse — recharge", recharge, code=201, status="Created"),
    },
    {
        "name": "3. Transférer des fonds",
        "request": make_request(
            "POST",
            make_url("/api/v1/wallet/transfert"),
            body={
                "montant": 5000,
                "destinataire_wallet_id": "TGW26062200270B",
            },
            description=(
                "Transfère des fonds vers un wallet interne (destinataire_wallet_id) "
                "ou un numéro Mobile Money. Retourne la référence de la transaction.\n\n"
                "Authentification : Bearer token (Sanctum)."
            ),
        ),
        "response": make_response("Exemple de réponse — transfert", transfert, code=201, status="Created"),
    },
    {
        "name": "4. Liste des transactions",
        "request": make_request(
            "GET",
            make_url("/api/v1/wallet/transactions", query=[
                {"key": "page", "value": "1", "description": "Numéro de page (défaut 1)"},
                {"key": "limit", "value": "20", "description": "Nombre d'éléments par page (défaut 20)"},
            ]),
            description=(
                "Liste paginée des transactions du wallet, groupées par date.\n\n"
                "Authentification : Bearer token (Sanctum)."
            ),
        ),
        "response": make_response("Exemple de réponse — transactions", transactions),
    },
]

collection = {
    "info": {
        "name": "Yam Wallet API — API externe",
        "description": (
            "Collection Postman de l'API externe Yam Wallet (à implémenter).\n\n"
            "Base URL : {{base_url}}\n"
            "Authentification : Bearer token (Sanctum) sur chaque requête.\n\n"
            "Endpoints :\n"
            "1. GET  /api/v1/wallet — récupère le wallet\n"
            "2. POST /api/v1/wallet/recharge — initie une recharge Mobile Money\n"
            "3. POST /api/v1/wallet/transfert — transfère des fonds\n"
            "4. GET  /api/v1/wallet/transactions — liste paginée des transactions"
        ),
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "variable": [
        {"key": "base_url", "value": BASE_URL, "type": "string"},
        {"key": "wallet_id", "value": "TGW260622001252", "type": "string"},
        {"key": "token", "value": "", "type": "string"},
    ],
    "item": items,
}

with open(OUT, "w", encoding="utf-8") as f:
    json.dump(collection, f, ensure_ascii=False, indent=2)

print(f"Collection générée : {OUT}")
print(f"Taille : {os.path.getsize(OUT)} octets")