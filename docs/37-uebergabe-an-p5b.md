# 37 — Übergabe an P5b (PostgreSQL)

**Stand: 9. August 2026, `v0.5.0-rc.10`.** P5 ist abgenommen. Dieses Dokument
ist für die Sitzung geschrieben, die P5b plant und baut — es sagt, was
dasteht, was davon trägt, was davon *nicht* trägt, und welche Frage vor der
ersten Zeile Code beantwortet sein muss.

Es ist das Gegenstück zu [32](32-uebergabe-p4.md), und es hat denselben
Zweck: **Was eine Stufe teuer gelernt hat, soll die nächste nicht noch einmal
bezahlen.**

---

## 1. Was zuerst zu lesen ist

- **`CLAUDE.md`** — die drei Grenzen, die Wächter-Gewohnheit, diese Umgebung.
- **[20 §9](20-hostingpanel-neuplan.md)** — P5b steht dort, kurz und präzise.
  Der Plan ist die Quelle; wo dieses Dokument und der Plan sich widersprechen,
  gilt der Plan.
- **[36](36-datenbanken.md)** — der Plan von P5 **und** sein
  Umsetzungsprotokoll. Besonders **§17** (die sieben Abnahmekriterien als
  Befehlsfolge), **§19** (die Entscheidungen des Betreibers) und
  **§22.3a–§22.3x** (was beim Bauen anders war als im Plan).
- **[19](19-sprache-der-oberflaeche.md)** Sprache · **[26](26-abonnements.md)**
  Abonnements.

**P5b bekommt einen eigenen Plan** — `docs/38`, im Zuschnitt von `docs/36`. Das
ist Entscheidung 1 des Betreibers (§19): eigene Stufe, eigener Umfang, **eigene
Abnahme**.

---

## 2. Was der Plan sagt

> **P5b — PostgreSQL · 2–3 Wochen · (0.6.x)**
>
> Aus P5 herausgelöst. PostgreSQL im Zuschnitt von P5, mit **eigener Abnahme** —
> denn „sieht keine fremde Datenbank" bedeutet dort etwas anderes:
> `pg_database` ist für jeden lesbar, und `REVOKE CONNECT ON DATABASE … FROM
> PUBLIC` nimmt die Verbindung und nicht die Sichtbarkeit des Namens.
>
> **Fertig, wenn** dasselbe gilt wie für P5 — und ein Datenbankbenutzer die
> **Namen** fremder Datenbanken nachweislich nicht aufzählen kann.

---

## 3. Die erste Aufgabe ist nicht Bauen, sondern Nachsehen

**Das Abnahmekriterium könnte in dieser Form nicht erfüllbar sein, und das
gehört geklärt, bevor irgendetwas geplant wird.**

`pg_database` ist in PostgreSQL für `PUBLIC` lesbar. Der Entzug ist technisch
möglich und bricht dabei `psql \l`, `pg_dump` und viele Klienten. Die üblichen
Auswege — ein Cluster je Kunde, ein Verbindungsvermittler davor, oder die
Sichtbarkeit der Namen hinnehmen — sind **drei verschiedene Stufen** mit ganz
verschiedenem Aufwand und ganz verschiedenem Betriebsbild.

Das ist Wissen aus zweiter Hand und gehört **auf einem echten PostgreSQL
gemessen**, bevor eine Zeile Plan entsteht. Der Massstab ist derselbe wie in P5:
gemessen, nicht geschätzt.

**Fällt das Kriterium, ist das kein Rückschlag, sondern der wertvollste Fund des
Tages** — und er gehört dem Betreiber vorgelegt, nicht stillschweigend umgangen.
Genau so ist P4 mit `docs/34 §10` verfahren und P5 mit dem siebten Kriterium,
das beim Planen dazukam.

---

## 4. Die grosse Frage: eine Geschmacksrichtung oder ein zweiter Stapel?

P5 kennt bereits `flavour` (`mariadb` / `mysql`) in `Server::describe()`. Die
Versuchung ist, PostgreSQL als dritte Richtung einzuhängen. **Vor dieser
Entscheidung stehen mindestens sechs Unterschiede, die tiefer sitzen als ein
`match`:**

| | MariaDB (P5) | PostgreSQL |
|---|---|---|
| Benutzer | `'name'@'host'` — der Wirt ist Teil der Identität | **Rolle, clusterweit**, ohne Wirt |
| Wirtsbeschränkung | im Benutzer | in `pg_hba.conf` |
| Sperre | `ALTER USER … ACCOUNT LOCK` | `ALTER ROLE … NOLOGIN` |
| Fernzugriff | `bind-address`, eine Datei | `listen_addresses` **und** `pg_hba.conf` |
| Sicherung | `mysqldump`, `DEFINER` streichen | `pg_dump`/`pg_restore`, Fassungsversatz zählt |
| Der gefährliche Dump | `GRANT ALL ON *.*` | `ALTER … OWNER TO`, `CREATE EXTENSION` |
| Grösse | `information_schema` | `pg_database_size()` |
| Sortierung | `COLLATE` änderbar | `LC_COLLATE`/ICU, **beim Anlegen festgelegt** |

**Die zweite Zeile ist die teuerste.** `DbUser.host` trägt in P5 die halbe
Fernzugriffs-Geschichte: den eindeutigen Index über `(name, host)`, das Feld
„Erreichbar von", die Zählung auf `Einstellungen → Datenbankserver` und
`RemoteAccessTest`. In PostgreSQL hat es kein Gegenstück — die Wirtsfrage steht
in `pg_hba.conf`, also in einer Datei und nicht an der Rolle.

**Und Kriterium 6 aus P5 braucht ein eigenes Gegenstück.** Dort prallt ein Dump,
der `GRANT ALL PRIVILEGES ON *.*` enthält, am befristeten Benutzer ab
(`Names::ephemeral()`). Die PostgreSQL-Entsprechung ist nicht dieselbe
Anweisung, sondern `ALTER … OWNER TO` und `CREATE EXTENSION` — beides
Dinge, die ein eingespielter Dump versuchen kann und die ein Rollenkonto ohne
Superuser-Recht nicht können darf.

---

## 5. Was P5 hinterlässt

**Agent** (`agent/src/`) — `Db/`: `Session`, `Server`, `Dump`, `Names`,
`Ephemeral`, `Sql`. Operationen: `DbDatabaseCreate/Remove`,
`DbUserCreate/Grant/Lock/Password/Remove`, `DbDumpCreate/Import/Remove`,
`DbRestore`, `DbServerInfo`, `DbRemoteAccess`, `DbUsage`, `DbIsolationProbe`.

**Panel** — `app/Support/Databases/`: `Databases`, `Dumps`, `DbLifecycle`,
`DatabasePrune`, `DumpIntegrity`, `Staging`, `ImportLimit`, `Secret`, `Usage`.
Modelle `Database`, `DbUser`, `DatabaseDump`; Aufzählungen `DatabaseStatus`,
`DbUserStatus`, `DumpStatus`, `DumpKind`.

**Oberfläche** — `Pages/Databases/{Index,Create,Show}.vue`,
`Pages/Settings/Database.vue`.

**Kommandozeile** — `srvpanel db [--prune] [--dry-run] [--remote=on|off]
[--bind=]`, `srvpanel acceptance-db`.

**Zwei Dinge daraus sind allgemein und gelten für P5b von Anfang an:**
`SrvPanel\Agent\Frame` (Fortschritt und Ausgabe eines Vorgangs, §22.3w) und
`AfterOperation::afterFailure()` (der Weg zurück nach einem gescheiterten
Vorgang). Beide sind in P5 entstanden, weil sie gefehlt haben — sie sind keine
Datenbanksache.

---

## 6. Die Lehren, die P5 teuer bezahlt hat

Diese sechs sind der eigentliche Wert dieser Übergabe. Jede steht für einen
Fehler, den kein Test gefunden hat.

1. **Eine geschriebene Zeile ist eine Absicht; erst der gelesene Zustand ist ein
   Zustand.** Der Fernzugriff meldete „geschrieben" und horchte lokal — gefangen
   nur, weil das Kommando `@@bind_address` nach dem Neustart zurückliest
   (§22.3w, A5).
2. **Der Beleg ist nie eine Abwesenheit allein.** Ein leeres Verzeichnis beweist
   nichts; sein Zeitstempel schon (§22.3q, §22.3x).
3. **Eine Prüfung, die im Fehlerfall dasselbe sagt wie im Erfolgsfall, belegt
   nichts.** `systemctl is-active` blieb grün, während das Panel keine Datenbank
   hatte (§22.3x).
4. **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt
   wurde.** Aus P4, und in P5 zweimal bestätigt.
5. **Wer etwas anlegt, das auf der Platte bleibt, baut den Weg zurück mit.**
   Zweimal getroffen: Zertifikate (`docs/35`), hochgeladene Sicherungen
   (§22.3w).
6. **Eine Zeichenkette über eine Prozessgrenze braucht einen Typ.** `pct` gegen
   `percent`, `log` gegen `output` — zehn Monate ins Leere, ohne dass irgendwo
   etwas rot wurde (§22.3w).

**Dazu zwei über Wächter selbst:**

- Sie zählen dort, wo die Regel stehen **darf**, nicht wo sie stehen *soll* —
  sonst beissen sie beim Aufräumen, und dann werden sie abgeschaltet.
- Sie dürfen **`tests/` nicht auslassen.** Dort steht die zweite Fassung einer
  Regel am häufigsten; `DumpKindTest` hat es im ersten Wurf getan und genau dort
  ist es durchgerutscht.

---

## 7. Diese Umgebung — was Runden spart

- **Kein `vendor/`.** `composer install` scheitert am Proxy
  (`codeload.github.com` → 403, packagist → 200). `php artisan test` und PHPStan
  mit larastan laufen **nur in der CI**.
- **`pint.phar` und `phpstan.phar` gibt es von den GitHub-Releases** und sie
  sind das Echte. PHPStan taugt für `agent/`, `tests/Support/` und Einzeldateien,
  gefiltert nach `notFound`.
- **PHPStan widerspricht sich zwischen den Umgebungen** bei der Vollständigkeit
  von `match`: `match.unhandled` hier, `match.alwaysTrue` in der CI. Eine
  Zuordnung statt `match` macht beide zufrieden.
- **`expectsOutputToContain()` sagt nicht, was stattdessen dastand.**
  `Artisan::call()` + `Artisan::output()` benutzen (§22.5b).
- **Headless Chromium klemmt das Fenster bei 500 CSS-px.** Echte 390px nur im
  `<iframe>` (§22.5a).
- **Namen, die der Basisklasse gehören** — `for()`, `matches()`, `count()`,
  `configure()` — brechen beim *Laden*. Ein Testfall ist auch eine abgeleitete
  Klasse.
- **Ein einzeiliger Dokumentationsblock trägt keine Marke**; `$` in PCRE braucht
  `/D`.

---

## 8. Was aus P5 offen bleibt

Nichts davon hält P5b auf, aber es gehört gewusst:

- **Die Ausgabe-Hälfte des Frame-Fundes** ist auf dem Server nicht belegt — sie
  braucht eine Operation mit `->stream()` (`subscription.provision`,
  `php.version.install`). Die Datenbankoperationen haben keine.
- **`acme.account.ensure`** steht seit P4 in `AgentOperationReachTest::UNREACHED`
  — Entscheidung des Betreibers: anschliessen oder entfernen.
- **`disk_used_mb` ist seit P2 „nicht gemessen"** — `cloudsrv24` hat keine
  Dateisystemquota.
- **Der Rotbeweis aller 174 Eingriffe** in `tests/waechter-brechen.sh` braucht
  ein lokales `vendor/` und ist nie vollständig gefahren worden.
- **Auf `cloudsrv24` liegt Abnahmerest:** eine `pending`-Zeile von vor der
  Korrektur, zwei Bomben-Zeilen, `/root/bombe.sql.gz`. Die Sicherung mit der
  Grössenabweichung (`p1118-dummy-20260808-162945-ddbf8d2a`) ist der lebende
  Prüfstein für `srvpanel db` und darf bleiben, solange er gebraucht wird.

---

## 9. Ablauf

Eigener `claude/…`-Zweig, **nie `main`**; ist der zugehörige PR gemergt, den
Zweig unter demselben Namen frisch von `main` starten. **Einen Pull Request nur
öffnen, wenn ausdrücklich danach gefragt wurde.** `git commit -s` (DCO). Der
`CHANGELOG.md` ist kein Protokoll der Commits, sondern der Ort, an dem steht,
*warum* etwas so ist und was vorher falsch war.

Jede neue Regel bekommt einen Wächter, und der wird absichtlich gebrochen —
`tests/waechter-brechen.sh`, geprüft von `BreakScriptTest`.

**Eine Ausbaustufe gilt erst als fertig, wenn ihr Abnahmekriterium nachweisbar
erfüllt ist — gemessen auf einem echten Server, nicht geschätzt.**
