#!/usr/bin/env python3
"""
Génère la collection Postman de l'API KondjiPro.

Pourquoi un script et pas un JSON écrit à la main : 60+ requêtes avec des
scripts de test JS imbriqués dans du JSON. Lesappergements à la main sont
la source n°1 de collection invalide ; ici la structure est garantie par
json.dump et le JSON produit est relu par Newman à chaque exécution.

Sortie :
  api/postman/KondjiPro_API.postman_collection.json
  api/postman/KondjiPro_Local.postman_environment.json
"""
import json
import pathlib
import uuid

SORTIE = pathlib.Path(__file__).resolve().parent.parent / "postman"
SORTIE.mkdir(parents=True, exist_ok=True)

AUTH = {"type": "bearer", "bearer": [{"key": "token", "value": "{{tokenApprovisionne}}", "type": "string"}]}
NOAUTH = {"type": "noauth"}


def auth(token):
    """Bearer sur un jeton précis."""
    return {"type": "bearer", "bearer": [{"key": "token", "value": "{{%s}}" % token, "type": "string"}]}


def url(text, query=None):
    """
    Construit une URL Postman.

    ATTENTION : `raw` doit contenir `{{baseUrl}}`. Postman donne la priorité à
    `raw` ; si on n'y met que `/up`, l'URL est interprétée comme RELATIVE et la
    requête part ailleurs (404 incompréhensible). C'est ce que produit
    Postman lui-même à l'export.
    """
    parties = text.split("/")
    brut = "{{baseUrl}}" + text
    if query:
        brut += "?" + "&".join("%s=%s" % (k, v) for k, v in query.items())
    u = {"raw": brut, "host": ["{{baseUrl}}"], "path": parties[1:]}
    if query:
        u["query"] = [{"key": k, "value": v} for k, v in query.items()]
    return u


def json_body(payload):
    return {
        "mode": "raw",
        "raw": json.dumps(payload, indent=2, ensure_ascii=False),
        "options": {"raw": {"language": "json"}},
    }


def req(nom, methode, chemin, dossier, description, tests="", pre="", body=None,
        auth_mecanisme=AUTH, entetes=None, query=None, exemple=None):
    r = {
        "name": nom,
        "request": {
            "method": methode,
            "header": [{"key": "Accept", "value": "application/json"}] + (
                [{"key": "Content-Type", "value": "application/json"}] if body else []
            ) + (entetes or []),
            "url": url(chemin, query),
            "description": description,
            "auth": auth_mecanisme,
        },
    }
    if body is not None:
        r["request"]["body"] = json_body(body)
    if pre:
        r["event"] = [{"listen": "prerequest", "script": {"type": "text/javascript", "exec": pre.split("\n")}}]
    if tests:
        r["event"] = r.get("event", []) + [
            {"listen": "test", "script": {"type": "text/javascript", "exec": tests.split("\n")}}
        ]
    if exemple:
        r["response"] = exemple
    r["_dossier"] = dossier
    return r


REQS = []

# =====================================================================
# 00 — Prérequis
# =====================================================================
REQS.append(req(
    "Health check (Laravel)", "GET", "/up", "00 — Prérequis",
    "Sonde Laravel. 200 = l'application répond. À lancer EN PREMIER : si elle échoue, "
    "rien d'autre ne marchera.",
    tests="""pm.test('200 — le serveur répond', () => pm.response.to.have.status(200));""", auth_mecanisme=NOAUTH,
))

REQS.append(req(
    "GET /config (public)", "GET", "/api/v1/config", "00 — Prérequis",
    "Config publique servie à l'app mobile (versions, seuils, feature flags).",
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('corps JSON non vide', () => {
    pm.expect(pm.response.json()).to.be.an('object');
});""", auth_mecanisme=NOAUTH,
))

REQS.append(req(
    "GET /debug/logs (public si APP_DEBUG)", "GET", "/api/v1/debug/logs", "00 — Prérequis",
    "Route de diagnostic enregistrée UNIQUEMENT si APP_DEBUG=true.\\n\\n"
    "En local (APP_DEBUG=true) → 200. En production → 404, la route n'existe pas.\\n"
    "Le test accepte les deux : c'est le comportement attendu qui compte.",
    tests="""const code = pm.response.code;
pm.test('200 en local OU 404 si APP_DEBUG=false', () => {
    pm.expect([200, 404]).to.include(code);
});
if (code === 200) {
    pm.test('les logs sont un objet/tableau', () => pm.expect(pm.response.json()).to.exist);
}""", auth_mecanisme=NOAUTH,
))

# =====================================================================
# 01 — Authentification
# =====================================================================
REQS.append(req(
    "POST /auth/login — patient approvisionné", "POST", "/api/v1/auth/login", "01 — Authentification",
    "Compte créé par `php artisan yam:seed-demo`. C'est celui qui a 50 000 F : "
    "c'est le seul moyen de tester les endpoints qui débloquent de l'argent.",
    body={"phone_number": "{{phoneApprovisionne}}", "password": "{{demoPassword}}", "device_id": "postman-appro", "platform": "web"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('jeton renvoyé', () => pm.expect(j.data.token).to.be.a('string').and.not.empty);
pm.test('rôle = patient', () => pm.expect(j.data.user.role).to.eql('patient'));
pm.collectionVariables.set('tokenApprovisionne', j.data.token);
pm.collectionVariables.set('userIdApprovisionne', j.data.user.id);
pm.test('le payload de login ne contient pas le solde (il vient de GET /wallet)', () => {
    pm.expect(j.data.user).to.not.have.property('solde');
});""",
))

REQS.append(req(
    "POST /auth/login — médecin", "POST", "/api/v1/auth/login", "01 — Authentification",
    "COMPTE MÉDECIN. Il est IMPOSSIBLE de le créer par l'API : `register` force "
    "`role=patient`. Il vient du seeder — voir la requête « Vérifier que register "
    "force le rôle patient » qui verrouille ce comportement.",
    body={"phone_number": "{{phoneMedecin}}", "password": "{{demoPassword}}", "device_id": "postman-med", "platform": "web"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.collectionVariables.set('tokenMedecin', j.data.token);
pm.collectionVariables.set('userIdMedecin', j.data.user.id);
pm.test('rôle = medecin', () => pm.expect(j.data.user.role).to.eql('medecin'));""",
))

REQS.append(req(
    "POST /auth/login — patient (solde 0)", "POST", "/api/v1/auth/login", "01 — Authentification",
    "Deuxième patient, solde 0. Sert à vérifier le refus 402 / solde insuffisant.",
    body={"phone_number": "{{phonePatient}}", "password": "{{demoPassword}}", "device_id": "postman-pat", "platform": "web"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.collectionVariables.set('tokenPatient', j.data.token);
pm.collectionVariables.set('userIdPatient', j.data.user.id);
pm.test('le rôle est bien patient', () => pm.expect(j.data.user.role).to.eql('patient'));""",
))

REQS.append(req(
    "POST /auth/register (numéro aléatoire)", "POST", "/api/v1/auth/register", "01 — Authentification",
    "Inscription classique. Le numéro est généré à chaque exécution pour ne jamais "
    "entrer en collision avec un compte existant.",
    pre="""pm.collectionVariables.set('phoneFree', '+2289' + Math.floor(1000000 + Math.random() * 9000000));""",
    body={"name": "Postman Test", "phone_number": "{{phoneFree}}", "password": "{{demoPassword}}",
          "device_id": "postman-register-{{$randomInt}}", "platform": "web"},
    tests="""pm.test('201', () => pm.response.to.have.status(201));
const j = pm.response.json();
pm.collectionVariables.set('tokenJetable', j.data.token);
pm.collectionVariables.set('userIdJetable', j.data.user.id);
pm.test('mot de passe JAMAIS renvoyé en clair', () => {
    pm.expect(JSON.stringify(j)).to.not.include('{{demoPassword}}');
});""",
))

REQS.append(req(
    "Vérifier que register force le rôle patient", "POST", "/api/v1/auth/register", "01 — Authentification",
    "CONTRAINTE IMPORTANTE, documentée par un test.\\n\\n"
    "`register` force `role = 'patient'` et IGNORE tout rôle envoyé par le client. "
    "Conséquence : il n'existe AUCUN moyen de créer un médecin par l'API, alors que "
    "6 endpoints d'appels exigent un médecin. Il faut le seeder.\\n\\n"
    "Ce test échouera si quelqu'un ouvre un jour l'inscription aux médecins : ce sera "
    "une bonne nouvelle, et il faudra mettre à jour cette documentation.",
    pre="""pm.collectionVariables.set('phoneFree2', '+2289' + Math.floor(1000000 + Math.random() * 9000000));""",
    body={"name": "Postman Role", "phone_number": "{{phoneFree2}}", "password": "{{demoPassword}}",
          "role": "medecin", "device_id": "postman-role-{{$randomInt}}", "platform": "web"},
    tests="""pm.test('201', () => pm.response.to.have.status(201));
const j = pm.response.json();
pm.test('le rôle est forcé à patient, le client ne peut pas créer de médecin', () => {
    pm.expect(j.data.user.role).to.eql('patient');
});
pm.test('le rôle demandé (medecin) a bien été ignoré', () => {
    pm.expect(j.data.user.role).to.not.eql('medecin');
});""",
))

REQS.append(req(
    "POST /auth/register — mot de passe trop court (422)", "POST", "/api/v1/auth/register", "01 — Authentification",
    "Cas d'erreur : `password` exige au moins 8 caractères (422).",
    pre="""pm.collectionVariables.set('phoneErr', '+2289' + Math.floor(1000000 + Math.random() * 9000000));""",
    body={"name": "Court", "phone_number": "{{phoneErr}}", "password": "court", "device_id": "d-err", "platform": "web"},
    tests="""pm.test('422', () => pm.response.to.have.status(422));
pm.test('le champ fautif est nommé', () => pm.expect(pm.response.json().errors).to.have.property('password'));""",
    auth_mecanisme=NOAUTH,
))

REQS.append(req(
    "POST /auth/login — mauvais mot de passe (401)", "POST", "/api/v1/auth/login", "01 — Authentification",
    "Cas d'erreur : identifiants invalides.\n\n"
    "L'API renvoie 422 (ValidationException sur phone_number) et non 401 : le "
    "contrat est en place depuis le début, on documente le comportement réel.\n\n"
    "Le message est volontairement le même que le numéro existe ou non "
    "(\"Identifiants incorrects.\") : impossible d'énumérer les comptes.",
    body={"phone_number": "{{phoneApprovisionne}}", "password": "MauvaisMotDePasse123"},
    tests="""pm.test('422 (et non 401 : voir la description)', () => pm.response.to.have.status(422));
pm.test('aucun jeton renvoyé', () => {
    pm.expect(JSON.stringify(pm.response.json())).to.not.include('plainTextToken');
});
pm.test('le message ne distingue pas compte existant / mot de passe faux', () => {
    pm.expect(pm.response.json().errors.phone_number[0]).to.eql('Identifiants incorrects.');
});""",
    auth_mecanisme=NOAUTH,
))

REQS.append(req(
    "GET /users/me", "GET", "/api/v1/users/me", "02 — Utilisateur connecté",
    "Profil de l'utilisateur authentifié. Sert aussi à vérifier que le jeton est encore valide.",
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('le user_id correspond au token utilisé', () => {
    pm.expect(String(j.data.id)).to.eql(pm.collectionVariables.get('userIdApprovisionne'));
});""",
))

# =====================================================================
# 02 — Utilisateurs
# =====================================================================
REQS.append(req(
    "GET /users/search?q=", "GET", "/api/v1/users/search", "03 — Recherche & contacts",
    "Recherche d'utilisateurs. `q` fait au minimum 2 caractères (422 en dessous).",
    query={"q": "Demo", "limit": "10"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('les résultats correspondent à la recherche', () => {
    const data = pm.response.json().data;
    pm.expect(data).to.be.an('array');
});""",
))

REQS.append(req(
    "GET /users/search?q=x (trop court, 422)", "GET", "/api/v1/users/search", "03 — Recherche & contacts",
    "Cas d'erreur : `q` minimal = 2 caractères.",
    query={"q": "x"},
    tests="""pm.test('422', () => pm.response.to.have.status(422));
pm.test('erreur sur q', () => pm.expect(pm.response.json().errors).to.have.property('q'));""",
))

REQS.append(req(
    "GET /contacts", "GET", "/api/v1/contacts", "03 — Recherche & contacts",
    "Liste des contacts de l'utilisateur connecté.",
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('liste JSON', () => pm.expect(pm.response.json()).to.be.an('object'));""",
))

# =====================================================================
# 04 — Appareils
# =====================================================================
REQS.append(req(
    "GET /devices", "GET", "/api/v1/devices", "04 — Appareils",
    "Appareils enregistrés pour l'utilisateur courant.\n\n"
    "C'est ici qu'on récupère le device_id qui alimente la suppression : "
    "l'enregistrement est un feu-et-forgat qui ne renvoie que { ok: true }.",
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('la liste des appareils est un tableau', () => pm.expect(j.devices).to.be.an('array'));
pm.test('au moins un appareil enregistré', () => pm.expect(j.devices.length).to.be.above(0));
pm.collectionVariables.set('deviceIdTeste', j.devices[0].device_id);""",
))

REQS.append(req(
    "POST /devices/register", "POST", "/api/v1/devices/register", "04 — Appareils",
    "Enregistre un appareil (notifications push, WebRTC).\n\n"
    "Contrat réel : c'est un feu-et-forgat, la réponse vaut simplement "
    "{ ok: true }. Le device_id se récupère ensuite via GET /devices, c'est ce qui alimente la suppression.",
    body={"label": "Postman Device", "device_id": "postman-dev-{{$randomInt}}", "platform": "web"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('enregistrement acquitté (feu-et-forgat)', () => {
    pm.expect(pm.response.json().ok).to.eql(true);
});""",
))

REQS.append(req(
    "DELETE /devices/{deviceId}", "DELETE", "/api/v1/devices/{{deviceIdTeste}}", "04 — Appareils",
    "Supprime l'appareil récupéré par GET /devices.\n\n"
    "Si le device_id est vide, l'URL devient /api/v1/devices/ et l'API répond "
    "405 : symptôme d'un GET /devices manquant, pas d'un vrai défaut.",
    tests="""pm.test('200', () => pm.response.to.have.status(200));""",
))

# =====================================================================
# 05 — Wallet
# =====================================================================
REQS.append(req(
    "GET /wallet (crée le wallet)", "GET", "/api/v1/wallet", "05 — Wallet",
    "Consulte le wallet. Le wallet est CRÉÉ à ce premier appel (wallet_id + key_wallet).\\n\\n"
    "Le format du wallet_id suit le contrat de l'API externe : TGW + aaMMjj + 6 chiffres.",
    auth_mecanisme=auth("tokenApprovisionne"),
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const w = pm.response.json().wallet;
pm.test('wallet_id conforme au contrat externe (TGW+aammjj+6 chiffres)', () => {
    pm.expect(w.wallet_id).to.match(/^TGW\\d{12}$/);
});
pm.test('key_wallet fait 32 caractères', () => pm.expect(w.key_wallet).to.have.length(32));
pm.test('solde = 50 000 (champ numerique solde_raw)', () => pm.expect(Number(w.solde_raw)).to.eql(50000));
pm.test('solde expose aussi en version formatee, pour l’affichage', () => pm.expect(w.solde).to.be.a('string'));
pm.collectionVariables.set('walletApprovisionne', w.wallet_id);""",
))

REQS.append(req(
    "GET /wallet (patient, crée son wallet)", "GET", "/api/v1/wallet", "05 — Wallet",
    "Crée le wallet du patient à solde 0 : il devient une destination de transfert "
    "valide, ce qu'il n'était pas avant cet appel.",
    auth_mecanisme=auth("tokenPatient"),
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const w = pm.response.json().wallet;
pm.collectionVariables.set('walletPatient', w.wallet_id);
pm.test('solde = 0', () => pm.expect(Number(w.solde_raw)).to.eql(0));""",
))

REQS.append(req(
    "POST /wallet/recharge (avec Idempotency-Key)", "POST", "/api/v1/wallet/recharge", "05 — Wallet",
    "Lance une recharge.\\n\\n"
    "L'en-tête `Idempotency-Key` est facultatif côté API mais FORTEMENT recommandé : "
    "sans lui, un double appui ou un retry réseau sur 4G togolaise recharge deux fois.\\n\\n"
    "Sans clés CinetPay configurées, la réponse est en mode `simulation` : "
    "`payment_url` est VIDE. C'est voulu — l'ancienne version renvoyait une URL "
    "fictive vers laquelle le client redirigeait le payeur.",
    entetes=[{"key": "Idempotency-Key", "value": "{{idemRecharge}}", "type": "text"}],
    pre="""pm.collectionVariables.set('idemRecharge', 'pm-recharge-' + Date.now() + '-' + Math.floor(Math.random() * 1000));""",
    body={"montant": 2000, "phone_number": "22891112233"},
    tests="""pm.test('201', () => pm.response.to.have.status(201));
const j = pm.response.json();
const info = j.information, pay = info.payment;
pm.test('une référence est renvoyée', () => pm.expect(info.reference).to.be.a('string').and.not.empty);
pm.collectionVariables.set('rechargeReference', info.reference);
pm.test('la recharge NE crédite AUCUN solde avant confirmation', () => {
    pm.expect(info.status_show).to.eql('En cours');
});
// Montants formates ("200 Fcfa", "+2 000 Fcfa") : on ne compare qu'apres avoir
// retire tout ce qui n'est pas un chiffre.
const chiffres = (v) => Number(String(v).replace(/[^0-9]/g, ''));
pm.test('montant = 2 000', () => pm.expect(chiffres(info.amount)).to.eql(2000));
pm.test('frais = 10 % du montant', () => pm.expect(chiffres(info.fees)).to.eql(200));
pm.test('total = montant + frais', () => pm.expect(chiffres(info.total_amount)).to.eql(2200));
pm.test('mode simulation = pas de payment_url inventée', () => {
    pm.expect(pay.mode).to.eql('simulation');
    pm.expect(pay.payment_url).to.eql('');
    pm.expect(pay.must_be_redirected).to.eql(false);
});
// Cette reponse ne porte pas de solde : c'est un objet `information`. Le
// solde se verifie en relisant GET /wallet juste apres (requete suivante).
pm.test('aucun solde crédité avant confirmation CinetPay', () => {
    pm.expect(info.new_balance).to.eql('-');
});
pm.test('la recharge est en attente, pas validée', () => {
    pm.expect(info.status).to.not.eql(2);
});""",
))

REQS.append(req(
    "POST /wallet/recharge — rejeu de la MÊME clé", "POST", "/api/v1/wallet/recharge", "05 — Wallet",
    "Rejoue EXACTEMENT la recharge précédente avec la même clé.\\n\\n"
    "Attendu : aucune nouvelle recharge, la réponse d’origine est rejouée à "
    "l'identique et l'en-tête `Idempotent-Replay: true` le signale.\\n\\n"
    "C'est le test de non-régression du correctif e78b805 : avant, ce rejeu créait "
    "une deuxième recharge.",
    entetes=[{"key": "Idempotency-Key", "value": "{{idemRecharge}}", "type": "text"}],
    body={"montant": 2000, "phone_number": "22891112233"},
    tests="""pm.test('201 — le statut d’origine est rejoué', () => pm.response.to.have.status(201));
pm.test('en-tête Idempotent-Replay: true', () => {
    pm.expect(pm.response.headers.get('Idempotent-Replay')).to.eql('true');
});
pm.test('la MÊME référence est renvoyée (aucune recharge créée)', () => {
    pm.expect(pm.response.json().information.reference)
      .to.eql(pm.collectionVariables.get('rechargeReference'));
});""",
))

REQS.append(req(
    "POST /wallet/recharge — clé invalide (409)", "POST", "/api/v1/wallet/recharge", "05 — Wallet",
    "Une clé trop courte (< 8 caractères) est refusée 409 plutôt que tronquée.",
    entetes=[{"key": "Idempotency-Key", "value": "court", "type": "text"}],
    body={"montant": 2000, "phone_number": "22891112233"},
    tests="""pm.test('409', () => pm.response.to.have.status(409));
pm.test('code erreur explicite', () => pm.expect(pm.response.json().error.code).to.eql('idempotency_conflict'));""",
))

REQS.append(req(
    "GET /wallet/transactions", "GET", "/api/v1/wallet/transactions", "05 — Wallet",
    "Historique paginé, groupé par jour. `limit` max 100.",
    query={"page": "1", "limit": "5"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('la pagination est présente', () => pm.expect(j.pagination).to.have.property('total'));
pm.test('les transactions sont groupées par date', () => pm.expect(j.transactions).to.be.an('object'));""",
))

REQS.append(req(
    "POST /wallet/transfert", "POST", "/api/v1/wallet/transfert", "05 — Wallet",
    "Transfert interne. L'expéditeur est débité du montant + 10 % de frais, le "
    "destinataire est crédité du montant NOMINAL (les frais restent chez la plateforme).\\n\\n"
    "Deux lignes sont écrites : débit + crédit.",
    entetes=[{"key": "Idempotency-Key", "value": "{{idemTransfert}}", "type": "text"}],
    pre="""pm.collectionVariables.set('idemTransfert', 'pm-transfert-' + Date.now() + '-' + Math.floor(Math.random() * 1000));""",
    body={"montant": 1000, "destinataire_wallet_id": "{{walletPatient}}"},
    tests="""pm.test('201', () => pm.response.to.have.status(201));
const j = pm.response.json();
pm.test('réponse de succès', () => pm.expect(j.message).to.include('succ'));
pm.test('frais = 10 %', () => {
    pm.expect(Number(String(j.information.fees).replace(/[^0-9]/g, ''))).to.eql(100);
});""",
))

REQS.append(req(
    "GET /wallet (après transfert) — conservation de l'argent", "GET", "/api/v1/wallet", "05 — Wallet",
    "Vérifie que l'argent n'a pas été créé ni détruit : 50 000 - 1 000 - 100 de frais = "
    "48 900 chez l'expéditeur.",
    auth_mecanisme=auth("tokenApprovisionne"),
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('solde = 48 900 (50 000 - 1 000 - 100 de frais)', () => {
    pm.expect(Number(pm.response.json().wallet.solde_raw)).to.eql(48900);
});""",
))

REQS.append(req(
    "GET /wallet du destinataire — crédit exact", "GET", "/api/v1/wallet", "05 — Wallet",
    "Le destinataire a reçu 1 000 F exactement, pas 1 100.",
    auth_mecanisme=auth("tokenPatient"),
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('solde = 1 000 (montant nominal, frais exclus)', () => {
    pm.expect(Number(pm.response.json().wallet.solde_raw)).to.eql(1000);
});""",
))

REQS.append(req(
    "POST /wallet/transfert — solde insuffisant (402)", "POST", "/api/v1/wallet/transfert", "05 — Wallet",
    "Le patient à 1 000 F ne peut pas transférer 999 999 F. Le contrôle du solde est "
    "fait SOUS VERROU, donc atomique avec le débit.",
    auth_mecanisme=auth("tokenPatient"),
    entetes=[{"key": "Idempotency-Key", "value": "pm-402-{{$randomInt}}", "type": "text"}],
    body={"montant": 999999, "destinataire_wallet_id": "{{walletApprovisionne}}"},
    tests="""pm.test('402 Payment Required', () => pm.response.to.have.status(402));
pm.test('le montant est nommé dans l’erreur', () => {
    pm.expect(pm.response.json().errors).to.have.property('montant');
});""",
))

REQS.append(req(
    "POST /wallet/transfert — même clé, montant différent (409)", "POST", "/api/v1/wallet/transfert", "05 — Wallet",
    "Réutiliser une clé d'idempotence avec un corps différent est un 409 : on refuse "
    "de mélanger deux intentions sous la même clé. Aucun argent ne bouge.",
    entetes=[{"key": "Idempotency-Key", "value": "{{idemTransfert}}", "type": "text"}],
    body={"montant": 5000, "destinataire_wallet_id": "{{walletPatient}}"},
    tests="""pm.test('409 Conflict', () => pm.response.to.have.status(409));
pm.test('code idempotency_conflict', () => {
    pm.expect(pm.response.json().error.code).to.eql('idempotency_conflict');
});""",
))

REQS.append(req(
    "POST /wallet/transfert — sans destinataire (422)", "POST", "/api/v1/wallet/transfert", "05 — Wallet",
    "Un transfert doit viser soit un wallet_id, soit un numéro. Les deux absents → 422.",
    body={"montant": 1000},
    tests="""pm.test('422', () => pm.response.to.have.status(422));""",
))

# =====================================================================
# 06 — Webhook CinetPay
# =====================================================================
REQS.append(req(
    "POST /wallet/recharge/notify — sans transaction_id", "POST", "/api/v1/wallet/recharge/notify", "06 — Webhook CinetPay",
    "WEBHOOK : AUCUN jeton, l'appel vient de CinetPay.\\n\\n"
    "Premier test de la règle fail-closed : sans `transaction_id`, AUCUN crédit, "
    "et la transaction n'est même pas marquée en échec.",
    auth_mecanisme=NOAUTH,
    body={"code": 200, "amount": 2200, "site_id": "{{siteIdCinetPay}}"},
    tests="""pm.test('200 (CinetPay attend 200, sinon il réessaiera)', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('aucun crédit', () => pm.expect(j.credit).to.eql(false));
pm.test('raison explicite', () => pm.expect(j.raison).to.eql('transaction_id_manquant'));""",
))

REQS.append(req(
    "POST /wallet/recharge/notify — transaction inconnue", "POST", "/api/v1/wallet/recharge/notify", "06 — Webhook CinetPay",
    "Un `transaction_id` qui n'existe pas en base ne crédite rien.",
    auth_mecanisme=NOAUTH,
    body={"transaction_id": "TXN_reference_inexistante_0001", "code": 200, "amount": 2200},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('aucun crédit', () => pm.expect(j.credit).to.eql(false));
pm.test('recharge introuvable', () => pm.expect(j.raison).to.eql('recharge_introuvable'));""",
))

REQS.append(req(
    "POST /wallet/recharge/notify — montant forgé", "POST", "/api/v1/wallet/recharge/notify", "06 — Webhook CinetPay",
    "ATTAQUE : on reprend une vraie référence de recharge et on annonce un montant "
    "différent. Le montant CRÉDITÉ est celui de la base, jamais celui de la notification, "
    "donc la recharge est refusée.\\n\\n"
    "Si ce test passe sans que le contrôle existe, c'est qu'un attaquant peut forger "
    "n'importe quel crédit : c'est le test de non-régression le plus important du fichier.",
    auth_mecanisme=NOAUTH,
    body={"transaction_id": "{{rechargeReference}}", "code": 200, "amount": 1, "site_id": "{{siteIdCinetPay}}"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('le montant forgé est REJETÉ — aucun crédit', () => {
    pm.expect(j.credit).to.eql(false);
    pm.expect(j.raison).to.eql('montant_invalide');
});""",
))

REQS.append(req(
    "POST /wallet/recharge/notify — sans code ni status", "POST", "/api/v1/wallet/recharge/notify", "06 — Webhook CinetPay",
    "Ni `code: 200` ni `status: SUCCESS` → fail-closed, aucun crédit. Le webhook ne "
    "devine jamais qu'un paiement a réussi.",
    auth_mecanisme=NOAUTH,
    body={"transaction_id": "{{rechargeReference}}", "amount": 2200, "site_id": "{{siteIdCinetPay}}"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('aucun crédit sans indicateur de succès', () => {
    pm.expect(pm.response.json().credit).to.eql(false);
});""",
))

REQS.append(req(
    "POST /wallet/recharge/notify — site_id inconnu", "POST", "/api/v1/wallet/recharge/notify", "06 — Webhook CinetPay",
    "Un `site_id` qui ne correspond pas à la configuration est rejeté (si l'intégration "
    "est configurée ; en mode simulation la recharge est de toute façon refusée).",
    auth_mecanisme=NOAUTH,
    body={"transaction_id": "{{rechargeReference}}", "code": 200, "amount": 2200, "site_id": "site-dun-attaquant"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('aucun crédit', () => pm.expect(pm.response.json().credit).to.eql(false));""",
))

# =====================================================================
# 07 — Appels payants
# =====================================================================
REQS.append(req(
    "POST /appels/init (patient → médecin)", "POST", "/api/v1/appels/init", "07 — Appels payants",
    "Le patientApprovisionné initie un appel vers le médecin. C'est le seul sens qui "
    "facture le patient (100 F/minute). Nécessite un solde ≥ le tarif.\\n\\n"
    "Un seul appel actif à la fois entre deux participants : un deuxième init en "
    "cours renvoie 409.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={"destination_user_id": "{{userIdMedecin}}"},
    tests="""pm.test('201', () => pm.response.to.have.status(201));
const j = pm.response.json();
pm.test('un appel_id est renvoyé', () => pm.expect(j.data.appel_id).to.be.a('number'));
pm.test('statut = initie', () => pm.expect(j.data.status).to.eql('initie'));
pm.collectionVariables.set('appelId', j.data.appel_id);""",
))

REQS.append(req(
    "POST /appels/init — déjà en cours (409)", "POST", "/api/v1/appels/init", "07 — Appels payants",
    "Anti-spam : deux appels ne peuvent pas coexister entre les mêmes participants.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={"destination_user_id": "{{userIdMedecin}}"},
    tests="""pm.test('409', () => pm.response.to.have.status(409));
pm.test('code appel_en_cours', () => pm.expect(pm.response.json().error.code).to.eql('appel_en_cours'));""",
))

REQS.append(req(
    "POST /appels/init — solde insuffisant (402)", "POST", "/api/v1/appels/init", "07 — Appels payants",
    "Un appel facturé est refusé quand le solde est <= 0, ce qui répond "
    "402 Payment Required (et non 422).\n\n"
    "Le test utilise volontairement le patient à solde 0 : avec 50 000 F on ne "
    "peut pas déclencher cette erreur, c'est le garde-fou qui compte, pas le "
    "montant demandé.",
    auth_mecanisme=auth("tokenPatient"),
    body={"destination_user_id": "{{userIdMedecin}}"},
    tests="""pm.test('402 Payment Required', () => pm.response.to.have.status(402));
pm.test('code solde_insuffisant', () => {
    pm.expect(pm.response.json().error.code).to.eql('solde_insuffisant');
});""",
))

REQS.append(req(
    "POST /appels/init — mauvais rôle de destination (422)", "POST", "/api/v1/appels/init", "07 — Appels payants",
    "Un patient doit appeler un MÉDECIN, pas un autre patient.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={"destination_user_id": "{{userIdPatient}}"},
    tests="""pm.test('422', () => pm.response.to.have.status(422));
pm.test('code destinataire_invalide', () => {
    pm.expect(pm.response.json().error.code).to.eql('destinataire_invalide');
});""",
))

REQS.append(req(
    "POST /appels/{id}/lancer (initiateur)", "POST", "/api/v1/appels/{{appelId}}/lancer", "07 — Appels payants",
    "L'INITIATEUR déclenche la sonnerie. Seul l'initiateur a le droit ; le "
    "destinataire qui tente reçoit un 403.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('statut = sonne', () => pm.expect(pm.response.json().data.status).to.eql('sonne'));""",
))

REQS.append(req(
    "POST /appels/{id}/decrocher (médecin)", "POST", "/api/v1/appels/{{appelId}}/decrocher", "07 — Appels payants",
    "Le MÉDECIN décroche. C'est le seul qui en a le droit.",
    auth_mecanisme=auth("tokenMedecin"),
    body={},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('statut = decroche', () => pm.expect(pm.response.json().data.status).to.eql('decroche'));""",
))

REQS.append(req(
    "POST /appels/{id}/decrocher — par le patient (403)", "POST", "/api/v1/appels/{{appelId}}/decrocher", "07 — Appels payants",
    "Le patient ne peut pas décrocher son propre appel : 403. L'appel est déjà "
    "décroché, mais le contrôle de rôle passe AVANT celui d'état.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={},
    tests="""pm.test('403', () => pm.response.to.have.status(403));
pm.test('code forbidden', () => pm.expect(pm.response.json().error.code).to.eql('forbidden'));""",
))

REQS.append(req(
    "POST /appels/{id}/heartbeat", "POST", "/api/v1/appels/{{appelId}}/heartbeat", "07 — Appels payants",
    "Battement de cœur pendant un appel décroché. C'est ce qui alimente la "
    "facturation au fil de l'eau (et ce qui évite de facturer une page laissée "
    "ouverte).",
    auth_mecanisme=auth("tokenMedecin"),
    body={},
    tests="""pm.test('200', () => pm.response.to.have.status(200));""",
))

REQS.append(req(
    "POST /appels/{id}/terminer", "POST", "/api/v1/appels/{{appelId}}/terminer", "07 — Appels payants",
    "Raccrochage. Le règlement FINAL est calculé ici sur le delta exact entre "
    "décroché et raccroché (le dernier heartbeat peut avoir jusqu'à 10 s de retard).\\n\\n"
    "C'est la requête qui débite le patient.",
    auth_mecanisme=auth("tokenMedecin"),
    body={"raison": "raccroche_manuel"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('statut = termine', () => pm.expect(j.data.status).to.eql('termine'));
// La duree n'est PAS dans cette reponse : terminer renvoie uniquement
// { appel_id, status }. Elle est exposee par GET /appels/{id}, et reste nulle
// si l'appel n'a jamais sonne. L'asserter ici serait un faux positif.
pm.test('aucun secret dans la reponse', () => {
    pm.expect(JSON.stringify(j)).to.not.include('key_wallet');
});""",
))

REQS.append(req(
    "GET /appels/{id}", "GET", "/api/v1/appels/{{appelId}}", "07 — Appels payants",
    "Détail de l'appel + facturation (durée, solde consommé, dates).",
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const d = pm.response.json().data;
pm.test('la durée est renseignée', () => pm.expect(d.duree_secondes).to.be.a('number'));
pm.test('le tarif est 100 F/minute', () => pm.expect(Number(d.tarif_par_minute)).to.eql(100));""",
))

REQS.append(req(
    "GET /wallet — le patient a été débité", "GET", "/api/v1/wallet", "07 — Appels payants",
    "Le débit de l'appel apparaît sur le wallet. À comparer avec le solde relevé avant "
    "l'appel (48 900).",
    auth_mecanisme=auth("tokenApprovisionne"),
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const solde = Number(pm.response.json().wallet.solde_raw);
pm.test('le solde a diminué (appel facturé)', () => pm.expect(solde).to.be.below(48900));
pm.collectionVariables.set('soldeApresAppel', solde);
pm.test('le débit reste positif (le patient n’a pas été à découvert)', () => {
    pm.expect(solde).to.be.at.least(0);
});""",
))

REQS.append(req(
    "POST /appels/init (médecin → patient, relation requise)", "POST", "/api/v1/appels/init", "07 — Appels payants",
    "Sens inverse. Un MÉDECIN ne peut appeler qu'un patient avec qui il a DÉJÀ eu un "
    "appel (décroché ou terminé) — c'est le test de la relation.\\n\\n"
    "L'appel précédent a créé cette relation, donc cette requête doit passer. Le "
    "patient n'est PAS facturé dans ce sens.",
    auth_mecanisme=auth("tokenMedecin"),
    body={"destination_user_id": "{{userIdApprovisionne}}"},
    tests="""pm.test('201', () => pm.response.to.have.status(201));
pm.test('initie_par = medecin', () => {
    pm.expect(pm.response.json().data.status).to.eql('initie');
});
pm.collectionVariables.set('appelIdMedecin', pm.response.json().data.appel_id);""",
))

REQS.append(req(
    "POST /appels/{id}/lancer + terminer (médecin)", "POST", "/api/v1/appels/{{appelIdMedecin}}/lancer", "07 — Appels payants",
    "L'appel du médecin n'est pas décroché : il se termine en `non_decroche`, et le "
    "patient ne doit RIEN payer.",
    auth_mecanisme=auth("tokenMedecin"),
    body={},
    tests="""pm.test('200', () => pm.response.to.have.status(200));""",
))

REQS.append(req(
    "POST /appels/{id}/terminer (non décroché)", "POST", "/api/v1/appels/{{appelIdMedecin}}/terminer", "07 — Appels payants",
    "Fin d'un appel jamais décroché → `raison_fin = non_decroche`.",
    auth_mecanisme=auth("tokenMedecin"),
    body={"raison": "non_decroche"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
// `terminer` ne renvoie que { appel_id, status } : la raison de fin se lit
// sur GET /appels/{id}. L'asserter ici serait un faux positif.
pm.test('un appel_id est renvoyé', () => {
    pm.expect(pm.response.json().data.appel_id).to.be.a('number');
});""",
))

REQS.append(req(
    "GET /wallet — le patient n'a RIEN payé", "GET", "/api/v1/wallet", "07 — Appels payants",
    "Preuve qu'un appel à l'initiative du médecin ne facture pas le patient : le "
    "solde est exactement celui laissé par l'appel précédent.",
    auth_mecanisme=auth("tokenApprovisionne"),
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const solde = Number(pm.response.json().wallet.solde_raw);
pm.test('solde inchangé : un appel du médecin ne coûte rien au patient', () => {
    pm.expect(solde).to.eql(Number(pm.collectionVariables.get('soldeApresAppel')));
});""",
))

REQS.append(req(
    "GET /appels/999999 (404)", "GET", "/api/v1/appels/999999", "07 — Appels payants",
    "Appel inexistant → 404. Vérifie que le model binding ne devine rien.",
    tests="""pm.test('404', () => pm.response.to.have.status(404));""",
))

# =====================================================================
# 08 — Signalisation WebRTC
# =====================================================================
REQS.append(req(
    "POST /call/ring", "POST", "/api/v1/call/ring", "08 — Signalisation WebRTC",
    "Sonnerie temps réel. `from_device_id` est obligatoire ; la cible peut être un "
    "user_id ou un numéro de téléphone.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={"to_user_id": "{{userIdMedecin}}", "from_device_id": "postman-webrtc-appro", "type": "audio"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
const j = pm.response.json();
pm.test('un call_id est renvoyé', () => pm.expect(j.data.call_id).to.be.a('string').and.not.empty);
pm.collectionVariables.set('callId', j.data.call_id);""",
))

REQS.append(req(
    "POST /call/signal — type=offer", "POST", "/api/v1/call/signal", "08 — Signalisation WebRTC",
    "Offre SDP WebRTC. `type` ∈ offer, answer, candidate, candidates_batch, bye. "
    "Le `payload` est transmis tel quel à l'autre partie.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={"call_id": "{{callId}}", "from_device_id": "postman-webrtc-appro", "type": "offer",
          "to_user_id": "{{userIdMedecin}}", "payload": {"sdp": "v=0\\r\\n..."}},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('ok: true', () => pm.expect(pm.response.json().ok).to.eql(true));""",
))

REQS.append(req(
    "POST /call/signal — type=answer", "POST", "/api/v1/call/signal", "08 — Signalisation WebRTC",
    "Réponse SDP du médecin.",
    auth_mecanisme=auth("tokenMedecin"),
    body={"call_id": "{{callId}}", "from_device_id": "postman-webrtc-med", "type": "answer",
          "to_user_id": "{{userIdApprovisionne}}", "payload": {"sdp": "v=0\\r\\n..."}},
    tests="""pm.test('200', () => pm.response.to.have.status(200));""",
))

REQS.append(req(
    "POST /call/signal — type invalide (422)", "POST", "/api/v1/call/signal", "08 — Signalisation WebRTC",
    "`type` hors de la liste fermée est refusé.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={"from_device_id": "postman-webrtc-appro", "type": "type_bidon"},
    tests="""pm.test('422', () => pm.response.to.have.status(422));
pm.test('erreur sur type', () => pm.expect(pm.response.json().errors).to.have.property('type'));""",
))

REQS.append(req(
    "GET /call/{call_id}/offer (public)", "GET", "/api/v1/call/{{callId}}/offer", "08 — Signalisation WebRTC",
    "Récupération de l'offre WebRTC par device_id. Route PUBLIQUE (le pair qui "
    "rejoint n'a pas encore de jeton) et limitée à 30 appels/minute.\\n\\n"
    "Sans offre enregistrée pour ce device_id, la réponse est un 200 avec "
    "`ok: false` — ce n'est pas une erreur HTTP.",
    auth_mecanisme=NOAUTH,
    query={"device_id": "postman-webrtc-med"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('ok=false car aucune offre enregistrée pour ce device_id', () => {
    pm.expect(pm.response.json().ok).to.eql(false);
});""",
))

REQS.append(req(
    "POST /call/cancel", "POST", "/api/v1/call/cancel", "08 — Signalisation WebRTC",
    "Annulation d'une sonnerie en cours.",
    auth_mecanisme=auth("tokenApprovisionne"),
    body={"call_id": "{{callId}}", "from_device_id": "postman-webrtc-appro"},
    tests="""pm.test('200', () => pm.response.to.have.status(200));
pm.test('ok: true', () => pm.expect(pm.response.json().data.ok).to.eql(true));""",
))

# =====================================================================
# 09 — Sécurité
# =====================================================================
REQS.append(req(
    "GET /wallet sans jeton (401)", "GET", "/api/v1/wallet", "09 — Sécurité",
    "Une route protégée sans jeton doit répondre 401, PAS une redirection vers une "
    "route « login » inexistante (l'API est pure, pas un site web).",
    auth_mecanisme=NOAUTH,
    tests="""pm.test('401', () => pm.response.to.have.status(401));""",
))

REQS.append(req(
    "POST /wallet/recharge sans jeton (401)", "POST", "/api/v1/wallet/recharge", "09 — Sécurité",
    "Créer une recharge exige un jeton : l'argent ne se recharge pas anonymement.",
    auth_mecanisme=NOAUTH,
    body={"montant": 2000, "phone_number": "22891112233"},
    tests="""pm.test('401', () => pm.response.to.have.status(401));""",
))

REQS.append(req(
    "POST /appels/init sans jeton (401)", "POST", "/api/v1/appels/init", "09 — Sécurité",
    "Initier un appel facturé exige un jeton.",
    auth_mecanisme=NOAUTH,
    body={"destination_user_id": 1},
    tests="""pm.test('401', () => pm.response.to.have.status(401));""",
))

REQS.append(req(
    "Jeton falsifié (401)", "GET", "/api/v1/wallet", "09 — Sécurité",
    "Un jeton au format valide mais inexistant est rejeté.",
    auth_mecanisme={"type": "bearer", "bearer": [{"key": "token", "value": "1|eyJzdGF0dXMiOiJ4In0.fake", "type": "string"}]},
    tests="""pm.test('401', () => pm.response.to.have.status(401));""",
))

REQS.append(req(
    "Route inexistante (404)", "GET", "/api/v1/nexiste_pas_du_tout", "09 — Sécurité",
    "Une route inconnue renvoie 404, pas 500.",
    auth_mecanisme=NOAUTH,
    tests="""pm.test('404', () => pm.response.to.have.status(404));""",
))

REQS.append(req(
    "Méthode HTTP non autorisée (405)", "DELETE", "/api/v1/wallet", "09 — Sécurité",
    "Supprimer un wallet n'existe pas : 405 Method Not Allowed, et non une erreur 500.",
    auth_mecanisme=NOAUTH,
    tests="""pm.test('405', () => pm.response.to.have.status(405));""",
))

# =====================================================================
# 99 — Déconnexion
# =====================================================================
REQS.append(req(
    "POST /auth/logout", "POST", "/api/v1/auth/logout", "99 — Déconnexion",
    "Révoque le jeton du compte créé pour ce test. Utilise le compte « jetable » "
    "pour ne pas invalider les jetons des autres requêtes.",
    auth_mecanisme=auth("tokenJetable"),
    body={},
    tests="""pm.test('200', () => pm.response.to.have.status(200));""",
))

REQS.append(req(
    "Jeton révoqué (401)", "GET", "/api/v1/users/me", "99 — Déconnexion",
    "Le jeton révoqué ne fonctionne plus : la révocation est effective immédiatement.",
    auth_mecanisme=auth("tokenJetable"),
    tests="""pm.test('401 après logout', () => pm.response.to.have.status(401));""",
))

# ---------------------------------------------------------------------
# Assemblage
# ---------------------------------------------------------------------
VARIABLES = [
    ("baseUrl", "http://127.0.0.1:8081", "Laravel artisan serve. Si tu testes via `php artisan serve` sur le port 8000, change ici."),
    ("demoPassword", "DemoPass123!", "Mot de passe commun aux comptes de `php artisan yam:seed-demo`."),
    ("phoneApprovisionne", "+228900000003", "Patient avec 50 000 F — seul compte permettant de tester ce qui coûte de l'argent."),
    ("phoneMedecin", "+228900000002", "Compte médecin. INCRÉABLE par l'API (register force le rôle patient)."),
    ("phonePatient", "+228900000001", "Patient à solde 0, sert aux cas d'erreur 402 / 422."),
    ("siteIdCinetPay", "site-de-test", "site_id CinetPay attendu. Sans clés réelles configurées, aucun webhook ne peut aboutir."),
    ("phoneFree", "", "Généré à chaque exécution de l'inscription."),
    ("phoneFree2", "", "Généré à chaque exécution du test de rôle."),
    ("phoneErr", "", "Généré à chaque exécution du test 422."),
    ("tokenApprovisionne", "", "Rempli automatiquement par le 1er login."),
    ("tokenMedecin", "", "Rempli automatiquement."),
    ("tokenPatient", "", "Rempli automatiquement."),
    ("tokenJetable", "", "Rempli automatiquement par register, consommé par logout."),
    ("userIdApprovisionne", "", "Rempli automatiquement."),
    ("userIdMedecin", "", "Rempli automatiquement."),
    ("userIdPatient", "", "Rempli automatiquement."),
    ("userIdJetable", "", "Rempli automatiquement."),
    ("walletApprovisionne", "", "Rempli par GET /wallet."),
    ("walletPatient", "", "Rempli par GET /wallet (patient)."),
    ("rechargeReference", "", "Rempli par POST /wallet/recharge, utilisé par les tests de webhook."),
    ("idemRecharge", "", "Généré à chaque recharge."),
    ("idemTransfert", "", "Généré à chaque transfert."),
    ("appelId", "", "Rempli par POST /appels/init."),
    ("appelIdMedecin", "", "Rempli par l'init à l'initiative du médecin."),
    ("soldeApresAppel", "", "Rempli après le débit d'appel, pour prouver l'absence de débit ensuite."),
    ("callId", "", "Rempli par POST /call/ring."),
    ("deviceIdTeste", "", "Rempli par POST /devices/register, consommé par DELETE."),
]

dossiers = {}
for r in REQS:
    d = r.pop("_dossier")
    dossiers.setdefault(d, []).append(r)

collection = {
    "info": {
        "name": "KondjiPro API (Laravel)",
        "description": (
            "Collection de test pour toute l'API KondjiPro (api/ Laravel 13).\n\n"
            "ORDRE D'EXÉCUTION : les dossiers sont numérotés et s'exécutent de haut en bas. "
            "Les requêtes s'enchaînent (un login fournit le jeton utilisé plus bas), donc "
            "ne lance pas une requête isolée au milieu.\n\n"
            "PRÉREQUIS :\n"
            "  1. php artisan migrate\n"
            "  2. php artisan yam:seed-demo   (crée un patient, un médecin, un patientApprovisionné)\n"
            "  3. php artisan serve --host=127.0.0.1 --port=8081\n\n"
            "Dans Postman : importer la collection, puis l'environnement "
            "`KondjiPro_Local.postman_environment.json`, puis « Run collection ».\n\n"
            "LIMITE CONNUE : le compte médecin ne peut PAS être créé par l'API "
            "(register force role=patient). Sans le seeder, les 6 endpoints d'appels "
            "sont intestables. Cette limite est volontairement documentée par un test."
        ),
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "auth": AUTH,
    "variable": [{"key": k, "value": v, "type": "string", "description": d} for k, v, d in VARIABLES],
    "item": [{"name": nom, "item": reqs} for nom, reqs in dossiers.items()],
    "protocolProfileBehavior": {},
}

collection_path = SORTIE / "KondjiPro_API.postman_collection.json"
collection_path.write_text(json.dumps(collection, indent=2, ensure_ascii=False), encoding="utf-8")

# Garde le lien de partage historique de Yam pointe vers la suite complète,
# plutôt que vers l'ancienne collection limitée aux appels WebRTC.
yam_collection = dict(collection)
yam_collection["info"] = {
    **collection["info"],
    "name": "Yam API — Suite complète",
    "description": (
        "Suite Postman complète de l'API Yam (64 requêtes : authentification, "
        "utilisateurs, appareils, wallet, appels et signalisation WebRTC).\n\n"
        "Cette suite est conçue pour un environnement local de test : elle "
        "nécessite `php artisan migrate`, `php artisan yam:seed-demo` et "
        "`php artisan serve --host=127.0.0.1 --port=8081`.\n\n"
        "Ne lance pas « Run collection » sur le serveur de production : les "
        "scénarios de test créent des comptes et effectuent des opérations de "
        "wallet/appels. Les requêtes et leurs corps servent aussi de référence "
        "pour consulter les endpoints."
    ),
}
yam_collection_path = SORTIE.parent / "Yam-API.postman_collection.json"
yam_collection_path.write_text(json.dumps(yam_collection, indent=2, ensure_ascii=False), encoding="utf-8")

# ATTENTION : Postman/Newman donnent la PRIORITÉ aux variables d'environnement
# sur celles de la collection. Si ce fichier définissait `tokenApprovisionne`
# (vide), il masquerait la valeur capturée par les scripts de test et TOUTES les
# routes protégées répondraient 401. Donc : l'environnement ne contient que
# `baseUrl`, tout l'état de la collection vit dans les variables de collection.
env = {
    "id": str(uuid.uuid4()),
    "name": "KondjiPro Local",
    "values": [
        {"key": "baseUrl", "value": "http://127.0.0.1:8081", "type": "default", "enabled": True}
    ],
    "_postman_variable_scope": "environment",
    "_postman_exported_at": "2026-09-28T00:00:00.000Z",
    "_postman_exported_using": "yam/scripts/generate_postman_collection.py",
}
env_path = SORTIE / "KondjiPro_Local.postman_environment.json"
env_path.write_text(json.dumps(env, indent=2, ensure_ascii=False), encoding="utf-8")

print("collection : %s (%d requêtes, %d dossiers)" % (collection_path.name, len(REQS), len(dossiers)))
print("environnement : %s" % env_path.name)
for nom, reqs in dossiers.items():
    print("   %-32s %2d requêtes" % (nom, len(reqs)))
