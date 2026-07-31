-- API-Tokens: der zweite Anmeldeweg neben der Sitzung.
--
-- Gespeichert wird NUR der Hash, genau wie bei den Sitzungen: Ein Abzug dieser
-- Datenbank erlaubt damit keine Anmeldung. Der Token selbst wird genau einmal
-- angezeigt und danach nie wieder — es gibt keine Spalte, aus der er
-- zurückzuholen wäre, und das ist keine Bequemlichkeitslücke, sondern der Zweck.
--
-- Vier Entscheidungen stehen in dieser Tabelle und gehören begründet:
--
--  1. `prefix` ist der sichtbare Anfang des Tokens (die ersten Zeichen nach
--     `asy_`). Ohne ihn wäre eine Liste von Tokens eine Liste von Namen — und
--     wer drei Tokens in drei Skripten liegen hat und einen davon widerrufen
--     will, könnte nicht sagen, welcher welcher ist. Der Anfang allein erlaubt
--     keine Anmeldung: Er ist kurz, und der Rest ist das Geheimnis.
--
--  2. `expires_at` darf NULL sein — „ohne Ablauf". Das ist bewusst erlaubt und
--     bewusst sichtbar: Ein Token, der um drei Uhr nachts stillschweigend
--     abläuft, bricht eine Automatisierung genau dann, wenn niemand hinsieht.
--     Wer das nicht will, bekommt es; die Oberfläche markiert solche Tokens
--     dafür dauerhaft als offene Rechnung. Verschweigen wäre schlimmer als
--     erlauben.
--
--  3. `scopes` ist eine kommagetrennte Liste von Modulfamilien (der zweite Pfadteil
--     unter /api/v1/…). Leer heißt „alle erlaubten Familien". Welche Familien für
--     einen Token grundsätzlich NICHT erreichbar sind, steht nicht hier, sondern
--     im Code an einer Stelle (internal/httpd/tokenauth.go) — eine Liste in der
--     Datenbank wäre eine zweite Wahrheit, und die ältere gewinnt beim
--     Auseinanderlaufen.
--
--  4. Es gibt keine Spalte `revoked_at`. Widerrufen LÖSCHT die Zeile. Ein
--     widerrufener Token, der als Zeile stehen bleibt, ist eine Zeile, die man
--     versehentlich wieder scharf schaltet; und die Auskunft „es gab einmal einen
--     Token namens X" gehört ins Audit-Protokoll, wo sie unveränderlich ist.
--     Dieselbe Überlegung wie bei DeleteUser in users.go.
CREATE TABLE api_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash   TEXT    NOT NULL UNIQUE,
    prefix       TEXT    NOT NULL,
    name         TEXT    NOT NULL,
    -- Der Token gehört einem Konto und erbt dessen Rolle. Fällt das Konto, fällt
    -- der Token: Ein Token, der ein gelöschtes Konto überlebt, wäre ein Zugang
    -- ohne Inhaber und ohne Rolle.
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scopes       TEXT    NOT NULL DEFAULT '',
    -- read_only beschneidet den Token auf lesende Anfragen. Es ist die Ergänzung
    -- zur Rolle, nicht ihr Ersatz: Die Rolle bleibt die Obergrenze, read_only
    -- senkt sie für diesen einen Zugang.
    read_only    INTEGER NOT NULL DEFAULT 0 CHECK (read_only IN (0, 1)),
    created_at   TEXT    NOT NULL,
    expires_at   TEXT,
    -- last_used_at und last_used_ip sind die Antwort auf „benutzt das noch
    -- jemand?". Ohne sie ist Aufräumen Raten, und ein Token, den niemand mehr
    -- braucht, bleibt liegen, weil niemand sich traut.
    last_used_at TEXT,
    last_used_ip TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX idx_api_tokens_user    ON api_tokens(user_id);
CREATE INDEX idx_api_tokens_expires ON api_tokens(expires_at);
