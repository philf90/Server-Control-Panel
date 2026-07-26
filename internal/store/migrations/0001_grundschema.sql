-- Grundschema: Nutzer, Sessions, Audit-Log, Einstellungen.

CREATE TABLE users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    username       TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    password_hash  TEXT    NOT NULL,
    role           TEXT    NOT NULL CHECK (role IN ('owner', 'admin', 'readonly')),
    totp_secret    TEXT    NOT NULL DEFAULT '',
    totp_confirmed INTEGER NOT NULL DEFAULT 0,
    disabled       INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL,
    last_login_at  TEXT
);

-- Wiederherstellungscodes werden nur als Hash gespeichert und beim Einlösen
-- entfernt; ein Code gilt genau einmal.
CREATE TABLE recovery_codes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash  TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    used_at    TEXT
);

CREATE INDEX idx_recovery_codes_user ON recovery_codes(user_id);

-- Sessions liegen serverseitig. Im Cookie steht nur ein Zufallswert, in der
-- Datenbank dessen SHA-256 — ein Datenbankabzug erlaubt damit keine Übernahme
-- laufender Sitzungen.
CREATE TABLE sessions (
    id           TEXT    PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    csrf_token   TEXT    NOT NULL,
    created_at   TEXT    NOT NULL,
    last_seen_at TEXT    NOT NULL,
    expires_at   TEXT    NOT NULL,
    ip           TEXT    NOT NULL DEFAULT '',
    user_agent   TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX idx_sessions_user    ON sessions(user_id);
CREATE INDEX idx_sessions_expires ON sessions(expires_at);

CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    at         TEXT    NOT NULL,
    actor      TEXT    NOT NULL,
    action     TEXT    NOT NULL,
    target     TEXT    NOT NULL DEFAULT '',
    result     TEXT    NOT NULL CHECK (result IN ('ok', 'denied', 'error')),
    ip         TEXT    NOT NULL DEFAULT '',
    detail     TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX idx_audit_at ON audit_log(at DESC);

CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
