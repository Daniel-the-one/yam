-- =========================================================
-- Schéma de base de données — Logiciel d'appels web & mobile
-- PostgreSQL
-- Modèle d'identification : user_id / device_id / current_ip
-- Pensé pour évoluer vers vidéo + appels de groupe
-- =========================================================

-- Extension pour générer des UUID (identifiants non devinables,
-- plus adaptés qu'un simple entier auto-incrémenté pour ce type d'app)
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ---------------------------------------------------------
-- 1. USERS — identité stable de la personne
-- ---------------------------------------------------------
CREATE TABLE users (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash TEXT         NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------
-- 2. DEVICES — un appareil/installation appartenant à un user
--    (un user peut avoir plusieurs devices : mobile + web, etc.)
-- ---------------------------------------------------------
CREATE TYPE platform_type AS ENUM ('mobile', 'web');

CREATE TABLE devices (
    id           UUID PRIMARY KEY DEFAULT gen_random_uuid(), -- = device_id généré côté client à l'installation
    user_id      UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    platform     platform_type NOT NULL,
    current_ip   INET,               -- capturée côté serveur à la connexion, jamais déclarée par le client
    is_online    BOOLEAN NOT NULL DEFAULT false,
    last_seen_at TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Recherche rapide de tous les appareils connectés d'un utilisateur
CREATE INDEX idx_devices_user_id ON devices(user_id);
CREATE INDEX idx_devices_online ON devices(is_online) WHERE is_online = true;

-- ---------------------------------------------------------
-- 3. CALLS — un appel (audio ou vidéo, 1-to-1 ou groupe demain)
-- ---------------------------------------------------------
CREATE TYPE call_type   AS ENUM ('audio', 'video');
CREATE TYPE call_status AS ENUM ('ringing', 'accepted', 'rejected', 'missed', 'ended', 'failed');

CREATE TABLE calls (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    initiator_id  UUID NOT NULL REFERENCES users(id),   -- celui qui a lancé l'appel
    type          call_type   NOT NULL DEFAULT 'audio',
    status        call_status NOT NULL DEFAULT 'ringing',
    started_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    answered_at   TIMESTAMPTZ,
    ended_at      TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_calls_initiator ON calls(initiator_id);
CREATE INDEX idx_calls_status ON calls(status);

-- ---------------------------------------------------------
-- 4. CALL_PARTICIPANTS — table de liaison many-to-many
--    Dès le départ en liste de participants (pas 2 colonnes A/B figées)
--    → un appel 1-to-1 = 2 lignes ; un appel de groupe demain = N lignes,
--      sans changer le schéma.
-- ---------------------------------------------------------
CREATE TYPE participant_status AS ENUM ('invited', 'ringing', 'accepted', 'rejected', 'missed', 'left');
CREATE TYPE participant_role   AS ENUM ('caller', 'callee');

CREATE TABLE call_participants (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    call_id    UUID NOT NULL REFERENCES calls(id) ON DELETE CASCADE,
    user_id    UUID NOT NULL REFERENCES users(id),
    device_id  UUID REFERENCES devices(id),   -- appareil effectivement utilisé pour répondre (nullable si non décroché)
    role       participant_role   NOT NULL,
    status     participant_status NOT NULL DEFAULT 'invited',
    joined_at  TIMESTAMPTZ,
    left_at    TIMESTAMPTZ,

    UNIQUE (call_id, user_id)  -- un utilisateur ne participe qu'une fois au même appel
);

CREATE INDEX idx_call_participants_call ON call_participants(call_id);
CREATE INDEX idx_call_participants_user ON call_participants(user_id);

-- ---------------------------------------------------------
-- Requêtes types que ce schéma doit permettre facilement
-- ---------------------------------------------------------

-- Historique des appels d'un utilisateur :
--   SELECT c.* FROM calls c
--   JOIN call_participants cp ON cp.call_id = c.id
--   WHERE cp.user_id = :user_id
--   ORDER BY c.started_at DESC;

-- Appels manqués d'un utilisateur :
--   SELECT c.* FROM calls c
--   JOIN call_participants cp ON cp.call_id = c.id
--   WHERE cp.user_id = :user_id AND cp.status = 'missed'
--   ORDER BY c.started_at DESC;

-- Tous les appareils actuellement en ligne pour un utilisateur (pour router un appel entrant) :
--   SELECT * FROM devices WHERE user_id = :user_id AND is_online = true;
