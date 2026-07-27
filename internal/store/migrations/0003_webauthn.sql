-- Passkeys (WebAuthn) als zusätzlicher zweiter Faktor.
--
-- Jeder Datensatz ist ein registrierter Authenticator eines Kontos. Das
-- vollständige Credential der go-webauthn-Bibliothek liegt als JSON in `data`
-- (öffentlicher Schlüssel, Sign-Count, Flags, Transports) — der öffentliche
-- Schlüssel ist kein Geheimnis, ein Datenbankabzug erlaubt damit keine
-- Anmeldung. `credential_id` ist die base64url-Kennung des Authenticators und
-- eindeutig: Dasselbe Gerät wird kein zweites Mal registriert.
--
-- Der Passkey ist in dieser Stufe additiv; TOTP bleibt Pflicht. Ein Konto ohne
-- Zeile hier hat schlicht keinen Passkey und verliert nichts.
CREATE TABLE webauthn_credentials (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    credential_id TEXT    NOT NULL UNIQUE,
    label         TEXT    NOT NULL DEFAULT '',
    data          BLOB    NOT NULL,
    created_at    TEXT    NOT NULL,
    last_used_at  TEXT
);

CREATE INDEX idx_webauthn_user ON webauthn_credentials(user_id);
