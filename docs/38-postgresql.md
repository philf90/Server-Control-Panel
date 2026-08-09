# 38 — P5b: PostgreSQL

> Der Plan für die Stufe P5b (`docs/20 §9 P5b`). Er ist zu lesen wie
> [36](36-datenbanken.md): erst die Messungen, dann die Entscheidungen mit ihren
> Gründen, dann die Schritte in zwingender Reihenfolge, dann die Wächter samt
> ihren Brüchen, dann das Abnahmekriterium als Befehlsfolge.
>
> **Der Unterschied zu `docs/36` steht gleich am Anfang: Dieser Plan beginnt
> nicht mit einer Absicht, sondern mit einer Messung.** [37 §3](37-uebergabe-an-p5b.md)
> hat das verlangt, und die Messung hat das Abnahmekriterium umgeworfen. Was
> danach kommt, ist auf Gemessenem gebaut und nicht auf Erinnertem.
>
> Stand: P0 bis P5 abgenommen. Ausgeliefert wird `v0.5.0-rc.10`.

---

## 1. Der Auftrag

Aus `docs/20 §9`:

> **P5b — PostgreSQL · 2–3 Wochen · (0.6.x)**
>
> Aus P5 herausgelöst. PostgreSQL im Zuschnitt von P5, mit **eigener Abnahme** —
> denn „sieht keine fremde Datenbank" bedeutet dort etwas anderes:
> `pg_database` ist für jeden lesbar, und `REVOKE CONNECT ON DATABASE … FROM
> PUBLIC` nimmt die Verbindung und nicht die Sichtbarkeit des Namens.
>
> **Fertig, wenn** dasselbe gilt wie für P5 — und ein Datenbankbenutzer die
> **Namen** fremder Datenbanken nachweislich nicht aufzählen kann.

Die zweite Hälfte dieses Satzes ist am 9. August 2026 gemessen worden. Sie ist
in dieser Form **nicht erfüllbar**, und §3 sagt, was an ihre Stelle tritt.

---

## 2. Die Messung kam vor dem Plan

### 2.1 Wo gemessen wurde

`CLAUDE.md` und `docs/36 §18` halten fest, dass dieser Container keine
Datenbank hat. Für MariaDB stimmt das. **Für PostgreSQL nicht:**
`postgresql-16` ist installiert, Serverbinärdateien und alles. Gemessen wurde
deshalb hier, am 9. August 2026, gegen **PostgreSQL 16.13** in einem
Wegwerf-Cluster (eigenes Datenverzeichnis, eigener Socket, Port 5433), in dem
die Lage aus `docs/36 §17` nachgebaut war: zwei Abonnements `p1001` und
`p1002`, je eine Datenbank, je eine Rolle.

Das ersetzt den Zielserver nicht — §2.3 nennt, was offen bleibt. Aber es
beantwortet jede Frage, an der die *Bauform* hängt, und das war der Auftrag.

### 2.2 Was dabei herauskam

| # | Frage | Befund |
|---|---|---|
| M1 | Sieht eine Kundenrolle fremde Datenbanknamen? | **Ja, alle fünf** — über `pg_database`, samt Eigentümerrolle |
| M5 | Ist `pg_database` der einzige Kanal? | **Nein.** Dreizehn Relationen in `pg_catalog` führen einen Datenbanknamen, **elf davon für PUBLIC lesbar** — darunter `pg_stat_database`, das *alle* Datenbanken nennt, auch die ohne jede Aktivität, und `pg_stat_activity`, das zusätzlich fremde Rollennamen preisgibt |
| M2 | Lässt sich `pg_database` entziehen? | Ja, und der Kunde arbeitet danach normal weiter |
| M6 | Was kostet der Entzug? | **`pg_dump` des Kunden stirbt** — nicht nur `--create`, sondern der schlichte Export der eigenen Datenbank: `pg_dump: error: permission denied for table pg_database` |
| M2b | Gilt der Entzug dauerhaft? | **Nein.** Er wirkt je Datenbank. Eine mit `TEMPLATE template0` angelegte — der Weg, den eine gesetzte Sortierung erzwingt — kommt mit unveränderten Rechten zurück und sieht wieder alles |
| M3 | Bleibt ein Kanal, wenn alles entzogen ist? | **Ja.** `permission denied for database "x"` gegen `database "x" does not exist` — die Fehlermeldung unterscheidet Existenz. Immerhin läuft die Passwortprüfung davor: ohne Zugangsdaten sind beide Meldungen gleich |
| M7 | Kann der Eigentümer die Absperrung aufheben? | **Ja.** `GRANT CONNECT … TO PUBLIC` gelingt ihm, und danach verbindet sich der fremde Kunde. `DROP DATABASE` auf die eigene ebenfalls |
| M13 | Und ohne Eigentum? | Beides abgewiesen, **normales Arbeiten unverändert möglich** — angelegt, gefüllt, gelesen. Preis: keine Erweiterungen, auch keine vertrauten |
| M8 | Was schafft ein bösartiger Dump unter einer Rolle ohne Superuser? | **Nichts.** `ALTER ROLE … SUPERUSER`, `GRANT pg_read_server_files`, `CREATE EXTENSION` (vertraut wie unvertraut), `COPY … FROM PROGRAM` — alle abgewiesen. Auch `\connect p1002_shop`, das ein Klartext-Dump regulär enthält |
| M8d | Meldet `psql` das? | **Nein.** `psql -f` gibt bei gescheitertem SQL **0** zurück und arbeitet weiter. Erst `ON_ERROR_STOP=1` macht daraus eine 3 und hält an |
| M9 | Wie meldet sich der Agent an? | Gar nicht: `psql -U postgres` als root → `Peer authentication failed`. Erst ein Kennungswechsel hilft |
| M11 | Taugt `NOLOGIN` als Sperre? | Ja — `role … is not permitted to log in`. Bestehende Sitzungen überleben, wie `ACCOUNT LOCK` in P5 auch |
| M12 | Grössen in einem Aufruf? | Ja: `SELECT datname, pg_database_size(oid) FROM pg_database` — dieselbe Form wie `db.usage` |
| M14 | Was kostet ein Cluster im Leerlauf? | **6 Prozesse, ~108 MiB RSS, 79 MB Platte** |
| — | Hat Debian einen Include-Punkt? | **`postgresql.conf`: ja** — `include_dir = 'conf.d'` steht aktiv in der Vorgabe, das Verzeichnis existiert. **`pg_hba.conf`: nein** |
| — | Was steht in `pg_hba.conf`? | `local all postgres peer` · `local all all peer` · `host all all 127.0.0.1/32 scram-sha-256` — ein Kunde kann sich über den Socket **gar nicht** anmelden, über `127.0.0.1` schon |

### 2.3 Was hier nicht zu messen war

Diese Fragen gehören auf `cloudsrv24` und auf die vier Zielplattformen, **bevor
Schritt 4 beginnt**:

1. **Die Fassungsspanne.** Debian 12/13 und Ubuntu 22.04/24.04 liefern
   verschiedene PostgreSQL-Fassungen, und die für uns wichtigste Regel hat sich
   mit **PG 15** geändert: Bis PG 14 darf `PUBLIC` im Schema `public` jeder
   Datenbank Objekte anlegen, ab PG 15 nicht mehr. Auf der älteren Fassung ist
   das ein Loch in genau der Wand, die §5 baut.
2. **Ob das Metapaket `postgresql` überall die richtige Fassung zieht.**
3. **Die Liste der elf Sichten ist fassungsabhängig** — PG 17 hat mehr
   `pg_stat_progress_*` als PG 14. Deshalb wird sie in §10 **erfragt und nicht
   verdrahtet**; gemessen gehört, dass die Abfrage auf jeder Fassung etwas
   findet.
4. **Ob MariaDB denselben Ratekanal hat** wie M3 — `ERROR 1044` gegen
   `ERROR 1049`. Falls ja, ist er kein Einwand gegen PostgreSQL, sondern ein
   Nachtrag zu beiden Stufen.
5. **Fassungsversatz beim mitgebrachten Dump** — ein Export aus PG 17 in einen
   PG 15.

---

## 3. Das Abnahmekriterium fällt — und wie es neu lautet

**Der alte Wortlaut verlangt Unmögliches, und zwar aus zwei Gründen:**

Der erste ist der Ratekanal aus M3. Er lässt sich durch keine Rechtevergabe
schliessen: PostgreSQL sagt beim Verbindungsaufbau, ob eine Datenbank existiert,
und das ist Teil des Protokolls. Praktisch benutzbar wird er dadurch, dass
Systembenutzer fortlaufend vergeben werden — wer `p1001` ist, probiert
`p1002_shop`.

Der zweite ist der Preis von M6. Man *kann* das Aufzählen schliessen, aber der
Kunde bezahlt es mit `pg_dump`. Ein Panel, das die Abschottung durchsetzt, indem
es dem Kunden das Sicherungswerkzeug nimmt, hat einen Sicherheitsgewinn gegen
einen Datenverlust getauscht.

**Was daraus folgt, ist nicht Aufgeben, sondern eine schärfere Frage.** Der
Zweck des Kriteriums war nie die Liste, sondern was sie verrät. `p1002_shop`
verrät „Abonnement 1002 hat einen Shop". Ein Name, der das nicht verrät, macht
die Liste wertlos, ohne irgendetwas zu brechen.

> ### Das Abnahmekriterium von P5b
>
> **Fertig, wenn** ein Kunde eine Datenbank anlegt, benutzt, sichert und
> zurückspielt — und
>
> 1. ein Datenbankbenutzer **keine Aufzählung** fremder Datenbanken erhält, die
>    ihm etwas über deren Zugehörigkeit sagt;
> 2. die elf Statistiksichten, die Namen und Rollen fremder Sitzungen führen,
>    ihm **verschlossen** sind — belegt an ihren Namen, nicht an ihrer Anzahl;
> 3. er auf keine fremde Datenbank **zugreifen** kann, weder verbindend noch
>    lesend;
> 4. er die Absperrung seiner eigenen Datenbank **nicht aufheben** kann;
> 5. ein hochgeladener Dump, der Rechte vergeben oder die Datenbank wechseln
>    will, **scheitert** — und der Vorgang das auch **meldet**;
> 6. `pg_dump` für ihn weiter funktioniert;
> 7. der Rückbau seines Abonnements nichts liegenlässt.
>
> **Und ausdrücklich nicht erfüllt:** Der Ratekanal aus M3 bleibt. Er steht in
> §22 als benanntes Risiko und wird im Abnahmelauf **gefahren und
> protokolliert**, nicht verschwiegen.

Punkt 6 ist kein Zusatz, sondern die Gegenprobe zu Punkt 1: Er verhindert, dass
jemand die Abschottung später doch über `REVOKE SELECT ON pg_database` löst und
dabei etwas kaputtmacht, das niemand prüft.

---

## 4. Namen: nichtssagend statt sprechend

`docs/36 §3` wählt als Präfix den Systembenutzer — `p1001_shop`. Vier Gründe
stehen dort, und **drei davon tragen unverändert**: ein Systembenutzer wird nie
zweimal vergeben, der Name ist kurz, und er ist ein gültiger unquotierter
Bezeichner. Der vierte fällt: Er sagt in PostgreSQL jedem, wem die Datenbank
gehört.

### Was an die Stelle tritt

**Das Präfix bleibt ein Präfix — es wird nur bedeutungslos.** Je Abonnement
entsteht **einmal** ein undurchsichtiger Bezeichner, und der steht vor jedem
Namen:

```
Datenbank:  x7f3a91c2_shop
Rolle:      x7f3a91c2_web
```

`x` plus sechzehn Hexziffern, aus `random_bytes`. Siebzehn Zeichen, damit bleibt
Raum unter der Grenze von 63 Byte, die PostgreSQL für Bezeichner setzt.

**Warum nicht ein vollständig zufälliger Name je Datenbank.** Weil P5 das Präfix
nicht nur zur Anzeige benutzt: `Db\Names::belongsTo()` prüft im **Agenten**, ob
ein Name zu dem Abonnement gehört, in dessen Auftrag die Operation läuft — und
`DbIsolationProbe` hängt daran. Ein Name ohne Präfix nähme dem Agenten diese
Prüfung ersatzlos, und der Agent führt keinen Zustand, aus dem er sie
rekonstruieren könnte. **Ein Präfix, das nichts verrät, behält den Wächter und
verliert nur die Auskunft.**

**Was ein Fremder danach noch sieht:** dass es N Datenbanken gibt, wie viele
davon dasselbe Präfix teilen, und die Zusätze — `shop`, `blog`. Er sieht nicht,
zu welchem Abonnement, zu welchem Kunden und zu welcher Domain sie gehören. Das
ist der Rest, und er ist benannt statt weggeredet.

### Wo das Präfix liegt

In `system_users`. Dort steht seit `docs/35` die Reservierung, die nie
zurückgegeben wird, und **diese Eigenschaft ist hier genau die gebrauchte**: Ein
Präfix, das ein zurückgebautes Abonnement freigäbe, könnte auf ein
Datenverzeichnis treffen, das ein gescheitertes `DROP DATABASE` hinterlassen
hat. Eine Spalte `db_prefix`, eindeutig, `null` für Abonnements aus der Zeit
davor.

**Vergeben wird es von `Lifecycle::claim()` und nicht von einer zweiten Stelle.**
`docs/35` hat die Unterscheidung teuer gelernt: `claim()` verbraucht,
`nextSystemUser()` zeigt nur an — wer sie verwechselt, vergibt zweimal
dasselbe.

### Der Zusatz

Unverändert `^[a-z][a-z0-9_]{0,15}$` aus `docs/36 §3`, und unverändert gilt:
**Der Zusatz kommt aus dem Formular, das Präfix nie.** Der Browser schickt
`shop`; das Präfix liest die Anwendung aus der Zeile des Abonnements, das durch
die Mandantenklammer gekommen ist.

### Und was der Betreiber davon hat

Nichts, und das ist der Preis. `x7f3a91c2_shop` sagt ihm auf der Kommandozeile
nicht mehr, zu wem es gehört. Dafür gibt es `srvpanel db list`, und dafür nennt
`srvpanel db` bei jedem Fund den Abonnementnamen dazu — sonst wäre der
Aufräumlauf aus `docs/36 §22.3r` ein Werkzeug, dessen Ausgabe niemand deuten
kann.

---

## 5. Wem die Datenbank gehört

**Dem Panel.** Der Kunde bekommt `CONNECT` auf die Datenbank und Rechte im
Schema, nicht das Eigentum.

Der Grund ist M7, und er ist kein Geschmack: Ein Eigentümer darf
`GRANT CONNECT ON DATABASE … TO PUBLIC`, und danach verbindet sich jeder andere
Kunde des Servers — gemessen. **Ein Abnahmekriterium, das der Geprüfte mit einer
Zeile SQL abschalten kann, ist keins.** Dass er zusätzlich `DROP DATABASE` auf
seine eigene darf, kommt dazu: Das Panel hätte danach eine Zeile und keine
Datenbank, und gemerkt hätte es das erst beim nächsten `srvpanel db`.

**Der Preis sind die Erweiterungen**, und er ist ehrlich zu nennen: Ohne
Eigentum bekommt der Kunde kein `CREATE EXTENSION`, auch kein `pgcrypto`, das
PostgreSQL selbst als vertraut einstuft. Anwendungen, die `uuid-ossp`,
`pg_trgm` oder `unaccent` brauchen, laufen ohne Zutun des Betreibers nicht.

**Der Ausweg hat in diesem Projekt schon eine Form** — eine Positivliste im
Agenten und eine Operation, die daraus installiert, wörtlich wie
`PhpVersions::EXTENSIONS`. Gebaut wird sie **nicht in P5b**, und das ist eine
Entscheidung und kein Vergessen: Welche Erweiterungen auf die Liste gehören, ist
eine Frage an den Betrieb und nicht an diesen Plan, und sie lässt sich erst
beantworten, wenn jemand PostgreSQL im Panel benutzt hat. Sie steht als Punkt in
`docs/20 §15`, wie Adminer auch. **Bis dahin sagt die Oberfläche es hin** —
nicht ausgeblendet, mit dem Grund daneben.

---

## 6. Wie der Agent sich anmeldet

Bei MariaDB kostete das keine Zeile: Der Agent läuft als root, MariaDB erkennt
root über den Socket. PostgreSQL bildet Unix-Kennungen auf Rollen ab, und eine
Rolle `root` gibt es nicht (M9).

**`Runner` lernt „läuft als".** Nicht `runuser` auf die Positivliste.

Der Unterschied ist der zwischen einer Eigenschaft und einer Vollmacht:
`runuser` ist ein Programm, das als root *beliebige* andere Programme unter
*beliebiger* Kennung startet. Auf einer Liste, von der `certbot` in P4 mit der
Begründung wieder verschwunden ist, dass ein Programm mit Erlaubnisschein
Angriffsfläche ist, wäre das die weiteste Zeile überhaupt. Setzt der Runner die
Kennung selbst, stehen auf der Liste weiter nur die drei Programme, die
tatsächlich laufen, und „als `postgres`" ist ein Feld des Aufrufs.

**Und dieses Feld bekommt eine eigene Positivliste mit genau einem Eintrag.**
Ein `as`-Feld, das eine Zeichenkette entgegennimmt, wäre wieder eine Vollmacht,
nur eine Ebene tiefer. `RunnerUserTest` (§18) besteht darauf.

**Was nicht gebaut wird und offenbleibt:** eine eigene Superuser-Rolle
`srvpanel` mit Passwort unter `/etc/srvpanel/db/`, die sich über `127.0.0.1`
anmeldet. Sie würde den Kennungswechsel aus dem Alltag nehmen — aber angelegt
werden muss sie einmal, und dafür braucht es ihn doch. Sie verkleinert die
Frage, sie beantwortet sie nicht, und eine Ablage für ein Geheimnis anzulegen,
die man nicht braucht, ist die Sorte Vorleistung, die `docs/36 §14` ablehnt.

---

## 7. Wie PostgreSQL auf den Server kommt

Heute steht in `packaging/nfpm.yaml`:

```yaml
suggests:
  - postgresql
```

Ohne Kommentar, ohne dass irgendetwas dahinterhängt — das einzige Vorkommen des
Wortes im ganzen Quelltext. Und `Suggests` installiert nichts. **In einer Datei,
deren übrige Zeilen jede einzeln begründet sind, ist das genau das Muster aus
`CLAUDE.md`:** eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein
Test oder ein Werkzeug den Bezug prüft. Sie liest sich wie eine Abhängigkeit und
ist eine Absichtserklärung.

### Warum nicht `Depends`

Jede Installation von SrvPanel bekäme einen zweiten Datenbankdienst — auch auf
jedem Server, der nie eine PostgreSQL-Datenbank anlegt, und gemessen sind das
6 Prozesse, ~108 MiB und 79 MB Platte (M14). Ein `Depends` ist ausserdem nicht
abwählbar: Wer PostgreSQL nicht will, könnte `srvpanel` nicht installieren. Das
ist Leitbild 1 von der falschen Seite.

### Erkennen immer, installieren auf Verlangen

Die Vorlage ist P3. PHP-Versionen sind keine Paketabhängigkeit; sie werden über
`php.version.install` geholt, und diese Operation macht fünf Dinge, die hier
alle gebraucht werden: kein Freitext an apt, wiederholbar, Erfolg **gelesen**
statt geglaubt, die Vorgabe der Distribution entschärft, Bestand erkannt statt
überfahren.

1. **`pg.server.info` kommt zuerst und läuft immer.** Ist PostgreSQL nicht
   installiert, ist das eine **Auskunft und kein Fehlschlag** — dieselbe
   Entscheidung wie in `Db\Server::describe()`, wo „MariaDB läuft nicht" bewusst
   keinen roten Vorgang erzeugt. Sonst stünde alle fünfzehn Minuten eine rote
   Zeile neben allem, was tatsächlich kaputt ist.
2. **`pg.server.install` ist eingereiht**, hinter dem Betreiberschalter, mit
   `->stream()` (siehe unten), Paketname aus einer Positivliste, Erfolg über
   `pg_lsclusters` **nachgelesen**.
3. **Der Betreiberschalter.** Ein zweiter Datenbankdienst ist eine serverweite
   Änderung; `docs/36 §19` Entscheidung 5 hat für diese Sorte die Form schon
   festgelegt. Hier: `srvpanel db --postgres=on`. Nie ein Kundenhäkchen.

### Ein vorhandener Cluster ist Bestand

Findet `pg.server.info` einen Cluster, den nicht wir angelegt haben, wird er
**benutzt und nicht umgebaut**. Insbesondere bleibt `pg_hba.conf` unangetastet —
sie ist eine Distributionsdatei, und die Vorgabe enthält bereits die Zeile, die
gebraucht wird: `host all all 127.0.0.1/32 scram-sha-256`.

**Kunden verbinden sich deshalb über `127.0.0.1` und nicht über den Socket.**
Über den Socket gilt `local all all peer`, und ein Kunde, dessen Unix-Kennung
`p1001` heisst und dessen Rolle `x7f3a91c2_web`, kommt dort nicht durch. Das
gehört in die Kundenhilfe und in die Oberfläche, weil `localhost` in libpq TCP
bedeutet und in `psql` ohne `-h` den Socket — derselbe Name, zwei Wege, und
einer davon geht nicht.

### Zwei Nebenwirkungen, die dazugehören

- **`postgresql.service` und `postgresql@*-main.service` fehlen in
  `ServiceAction::ALLOWED_UNITS`** und in der Unitliste von
  `OverviewController`. Ohne den Eintrag kann der Agent den Dienst, den er
  gerade installiert hat, nicht starten.
- **`pg.server.install` wäre die erste Datenbankoperation mit `->stream()`.**
  `docs/37 §8` hält fest, dass die Ausgabe-Hälfte des Frame-Fundes auf dem
  Server nie belegt wurde, weil keine `db.*`-Operation streamt. `apt-get` läuft
  Minuten und schreibt fortlaufend — sie schliesst diesen Punkt nebenbei, ohne
  dass dafür etwas gebaut werden müsste.

---

## 8. Ein Modell, zwei Sätze Operationen

`docs/20 §15` Punkt 5 hat P5b ausdrücklich zur Probe aufs Exempel erklärt:
*„muss P5b `agent/src/Db/` aufreissen, war die Trennung falsch."* Die Antwort
fällt in zwei Hälften, und sie fällt verschieden aus.

**Im Agenten: zwei Sätze.** Was gemessen wurde, ist kein Strauss von
Sonderfällen:

| | MariaDB | PostgreSQL |
|---|---|---|
| Anmeldung des Agenten | geschenkt | Kennungswechsel (§6) |
| Benutzer | `'name'@'host'`, zwei Wirte sind zwei Benutzer | eine Rolle, clusterweit |
| Wirtsbeschränkung | am Benutzer | in `pg_hba.conf` |
| Abschottung | ein `GRANT` je Datenbank | `REVOKE CONNECT`, Eigentum, elf `REVOKE` je Datenbank |
| Sperre | `ACCOUNT LOCK` | `NOLOGIN` |
| Zurückspielen | `mysql` bricht ab | **`psql` meldet Erfolg** (M8d) |
| Sicherung | `mysqldump`, DEFINER streichen | `pg_dump`, Fassungsversatz |
| Sortierung | änderbar | beim Anlegen festgelegt, erzwingt `template0` |

Ein `match` in jeder der fünfzehn `db.*`-Operationen hiesse fünfzehn Dateien mit
je zwei Umsetzungen — und `CLAUDE.md` sagt über zweite Fassungen derselben
Regel, dass die zweite die ist, die veraltet. Also `pg.*` **neben** `db.*`, mit
`agent/src/Pg/` neben `agent/src/Db/`.

**Im Panel: ein Modell und eine Fläche.** Der Kunde soll eine Liste
„Datenbanken" sehen und nicht zwei Menüpunkte; die Unterschiede aus der Tabelle
oben sind samt und sonders Unterschiede *unterhalb* des Agenten-Protokolls. Also
eine `engine`-Spalte, eine Policy, ein Controller, eine Seite.

**Damit ist die Behauptung aus `docs/36 §14` überprüfbar geworden**, und die
Prüfung hat ein Datum: Wenn beim Bauen von `pg.*` keine Datei unter
`agent/src/Db/` geändert werden muss, war die Trennung richtig. Muss doch eine —
`Runner` zählt nicht, der gehört keinem der beiden —, gehört das in §24 und
nicht in einen stillen Commit.

---

## 9. Das Datenmodell

Eine Migration, die die drei Tabellen aus `docs/36 §7` erweitert, und eine
Spalte in `system_users`.

```php
/**
 * PostgreSQL kommt dazu (P5b, docs/38).
 *
 * **`engine` und kein zweiter Satz Tabellen.** Die Unterschiede zwischen den
 * beiden Systemen sitzen unterhalb des Agenten-Protokolls (docs/38 §8); oben
 * sind es Datenbanken mit Namen, Grösse und Zugängen. Zwei Tabellen wären zwei
 * Policies, zwei Controller und zwei Seiten für einen Unterschied, den der
 * Kunde nicht sieht.
 *
 * **`default('mariadb')` und nicht `nullable()`.** Jede Zeile, die es beim
 * Migrieren schon gibt, IST eine MariaDB-Datenbank — das ist kein Vorgabewert
 * auf Verdacht, sondern eine Tatsache über den Bestand. Ein `null` hiesse
 * „unbekannt", und unbekannt ist hier nichts.
 *
 * **`db_prefix` gehört zu `system_users` und nicht zu `subscriptions`.** Es
 * darf nie zweimal vergeben werden, und die Tabelle, die genau das leistet,
 * gibt es seit docs/35. Ein zurückgebautes Abonnement wird hart gelöscht; sein
 * Präfix bliebe damit frei und könnte auf ein Datenverzeichnis treffen, das
 * ein gescheitertes DROP DATABASE hinterlassen hat.
 */
```

- `databases.engine` — `string(16)`, `default('mariadb')`, indiziert zusammen
  mit `subscription_id`
- `db_users.engine` — dito
- `database_dumps.engine` — dito. **Ein Dump trägt die Herkunft**, sonst spielt
  ihn irgendwann jemand in das falsche System zurück, und die Fehlermeldung
  redet dann über SQL-Syntax
- `system_users.db_prefix` — `string(24)`, `nullable()`, `unique()`

**`db_users.host` bleibt und bleibt leer.** Für PostgreSQL gibt es kein
Gegenstück (§14); der eindeutige Index `(name, host)` trägt weiter, weil
Rollennamen ohnehin clusterweit eindeutig sind. Eine Spalte zu entfernen, die
für die Hälfte der Zeilen die Wahrheit sagt, wäre der teurere Weg.

**`databases.charset` und `collation` bekommen bei PostgreSQL andere Werte,
nicht andere Spalten** — `UTF8` und `C.UTF-8` statt `utf8mb4` und
`utf8mb4_unicode_ci`. Was sich unterscheidet, ist die Änderbarkeit: In
PostgreSQL steht die Sortierung beim Anlegen fest, und die Oberfläche sagt das,
statt ein Feld anzubieten, das später nicht mehr wirkt.

---

## 10. Die Operationen des Agenten

Unter `agent/src/Ops/`, eingetragen in `agent/src/Registry.php` unter
`// P5b — PostgreSQL.`

| Operation | Was sie tut | Warteschlange? |
|---|---|---|
| `pg.server.info` | Installiert? Cluster, Fassung, Port, Horchadresse, wem er gehört | nein — liest nur |
| `pg.server.install` | Paket, Cluster, Dienst — §7 | **ja**, mit `->stream()` |
| `pg.database.create` | `CREATE DATABASE`, danach die Absperrung (unten) | **ja** — `template0` und elf `REVOKE` |
| `pg.database.remove` | `DROP DATABASE`, danach die Rollen, die nur daran hingen | **ja** |
| `pg.role.create` | `CREATE ROLE … LOGIN`, `GRANT CONNECT`, Rechte im Schema | nein — **Passwort** |
| `pg.role.password` | `ALTER ROLE … PASSWORD` | nein — **Passwort** |
| `pg.role.grant` | Rechte für ein Paar | nein |
| `pg.role.remove` | `REASSIGN`/`DROP OWNED`, dann `DROP ROLE` | nein |
| `pg.role.lock` | `NOLOGIN` / `LOGIN` (§11) | **ja** |
| `pg.usage` | Grössen aller Datenbanken in einem Aufruf | nein |
| `pg.dump.create` | `pg_dump` in die Ablage | **ja** |
| `pg.dump.import` | Eine mitgebrachte Sicherung ablegen | **ja** |
| `pg.dump.remove` | Die Ablage wieder weg | **ja** |
| `pg.restore` | Einspielen unter befristeter Rolle, `ON_ERROR_STOP=1` (§13) | **ja** |
| `pg.isolation.probe` | Die Gegenprobe zum Abnahmekriterium (§19) | nein |

Bausteine unter `agent/src/Pg/`, im Schnitt von `agent/src/Db/`:

```
agent/src/Pg/Names.php      Präfix, Zusatz, Zusammensetzung
agent/src/Pg/Sql.php        Bezeichner und Zeichenketten maskiert
agent/src/Pg/Server.php     Fassung, Cluster, Horchadresse — einmal gelesen
agent/src/Pg/Session.php    Ein Lauf gegen `psql`, als `postgres`
agent/src/Pg/Dump.php       pg_dump/pg_restore und die Ablage
agent/src/Pg/Ephemeral.php  Die befristete Rolle
agent/src/Pg/Shielding.php  Die Absperrung einer neuen Datenbank
```

**Neu auf `Runner::PROGRAMS`: `psql`, `pg_dump`, `pg_restore`** — alle drei als
fassungsunabhängige Wrapper unter `/usr/bin`, gemessen. Das ist der sichtbare
Preis dieser Stufe, den `docs/36 §14` angekündigt hat, und er ist um einen Pfad
kleiner als dort geschätzt, weil `apt-get` schon steht.

### `Pg\Shielding` — die Absperrung, und warum sie eine eigene Klasse ist

Nach jedem `CREATE DATABASE` läuft in der **neuen** Datenbank:

```sql
REVOKE CONNECT ON DATABASE <name> FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM PUBLIC;    -- trägt erst ab PG 15 von selbst
REVOKE SELECT ON pg_catalog.<jede Statistiksicht> FROM PUBLIC;
```

Drei Dinge daran sind Befunde und keine Formsache:

1. **Es läuft je Datenbank und nicht einmal je Cluster** (M2b). Eine Absperrung,
   die man einmal setzt, ist beim nächsten `CREATE DATABASE … TEMPLATE
   template0` wieder fort — und `template0` ist Pflicht, sobald eine Sortierung
   gesetzt wird. Deshalb gehört sie in dieselbe Operation wie das Anlegen und
   nicht in ein Einrichtungsskript.
2. **Die Liste der Sichten wird erfragt, nicht verdrahtet.** Sie ist
   fassungsabhängig (§2.3). Gefragt wird der Katalog nach Relationen mit einer
   Spalte `datname` oder `database` — dieselbe Abfrage, die die elf gefunden
   hat. Eine feste Liste wäre auf der nächsten Fassung unvollständig, und
   unvollständig hiesse hier: ein offener Kanal, den niemand bemerkt.
3. **`pg_database` selbst bleibt lesbar.** Absicht, siehe §3 Punkt 6.

---

## 11. Die Sperre eines Abonnements erreicht die Datenbank

Wörtlich `docs/36 §6`, mit `NOLOGIN` statt `ACCOUNT LOCK` (M11). `PgLifecycle`
beantwortet `subscription.suspend` und `subscription.resume`.

**Und dieselbe Grenze wie in P5, jetzt benannt:** `NOLOGIN` nimmt die Anmeldung
und beendet keine bestehende Sitzung. `ACCOUNT LOCK` tut das auch nicht — P5 hat
es nur nirgends aufgeschrieben. Eine Anwendung mit offenem Verbindungspool
arbeitet also nach der Sperre weiter, bis sie neu verbindet. Wer das schliessen
will, braucht `pg_terminate_backend`, und das ist eine Entscheidung mit
Folgen — ein Kunde, dessen Abonnement gesperrt wird, sieht dann mitten in einer
Transaktion einen Abbruch. **P5b sperrt und beendet nicht**, und der Satz steht
in §22 statt in einer Fussnote.

---

## 12. Kontingente und Messung

`Quota::Databases` und `Quota::DatabaseMb` gelten für **beide Systeme
zusammen** — ein Abonnement mit drei MariaDB- und zwei PostgreSQL-Datenbanken
hat fünf. Alles andere wäre ein zweites Kontingent für dieselbe Sache, und der
Plan des Kunden kennt eine Zahl.

**Damit werden zwei Beschriftungen falsch.** `Quota::hint()` sagt heute
„MariaDB-Schemata" und „`/var/lib/mysql` liegt ausserhalb der
Dateisystem-Quota". Beides gilt weiter und beides ist unvollständig. Insgesamt
nennen **sechs Stellen sichtbaren Textes** MariaDB beim Namen — `Quota::hint()`
zweimal, `Subscriptions/Show.vue` dreimal, `Databases/Show.vue` zweimal —, und
sie gehören in Schritt 6 umformuliert. Die *Bezeichner* sind sauber geblieben:
Kein Modell, keine Tabelle und keine Spalte trägt `mysql` im Namen. Die
Unterlassung aus `docs/36 §19` Entscheidung 1 hat gehalten.

`pg.usage` misst wie `db.usage` **alles in einem Aufruf** und gibt nur heraus,
was der Präfixform entspricht:

```sql
SELECT datname, pg_database_size(oid) FROM pg_database WHERE datname ~ '^x[0-9a-f]{16}_'
```

`srvpanel usage` misst ab P5b drei Dinge und startet weiter aus **einem** Timer.

---

## 13. Sichern und Zurückspielen

Ablage, Rechte und Verzeichnisnamen unverändert aus `docs/36 §10`:
`/var/lib/srvpanel/dumps/<abonnement>/<storage_name>.sql.gz`, Verzeichnisse
`root:srvpanel 0710`, Dateien `root:srvpanel 0640`. Ein Dump ist ein Dump.

**Drei Unterschiede, jeder gemessen:**

### 13.1 `ON_ERROR_STOP=1` ist nicht optional

`psql -f` gibt bei gescheitertem SQL **0** zurück und arbeitet weiter (M8d).
Gemessen an einem Skript mit vier Anweisungen, von denen die dritte abgewiesen
wurde: Rückgabewert 0, und die vierte lief trotzdem.

Was das bedeutet, ist grösser als eine Option:

- **Kriterium 5 wäre nicht belegbar.** Ein Zurückspielen, das vollständig
  scheitert, meldete „erledigt".
- **Kriterium 6 belegte das Falsche.** In P5 ist der Beleg die *Fehlermeldung
  des Vorgangs*, wörtlich, mit Benutzer und Zeilennummer (`docs/36 §17`). Ohne
  `ON_ERROR_STOP` gäbe es keine.

Das ist Lehre 3 aus `docs/37 §6`, wörtlich: *Eine Prüfung, die im Fehlerfall
dasselbe sagt wie im Erfolgsfall, belegt nichts.* Und es ist die Stelle, an der
P5b sich am leichtesten hätte täuschen lassen, weil `mysql` es von selbst
richtig macht.

`PgRestoreTest` prüft die Zeichenkette (§18). **Der Aufruf, nicht die
Absicht** — ein Wächter, der eine Konstante `ON_ERROR_STOP` findet, hat eine
Konstante gefunden.

### 13.2 Es gibt keine DEFINER-Falle, dafür eine Eigentümer-Falle

`pg_dump` schreibt keine `DEFINER`-Angaben; der Filter aus `docs/36 §10.1`
entfällt ersatzlos. Dafür schreibt es `ALTER … OWNER TO` und `GRANT`-Zeilen auf
Rollennamen, die es auf dem Zielserver nicht gibt — beim Umzug von einem fremden
Server der Normalfall. `pg_dump --no-owner --no-privileges` beim Erzeugen
eigener Sicherungen; bei mitgebrachten prallen sie an der befristeten Rolle ab,
und das ist richtig so (M8).

### 13.3 Der Fassungsversatz

Ein Dump aus einer neueren Fassung lässt sich nicht einspielen. Das trifft
mitgebrachte Sicherungen, nicht eigene, und es gehört **vor** dem Lauf gesagt
und nicht als Fehlermeldung danach: `pg.dump.import` liest die Fassung aus dem
Kopf des Dumps und weist ab, wenn sie über der des Servers liegt.

### 13.4 Die befristete Rolle

Wie `docs/36 §10.2`, mit dem Präfix aus §4: `x7f3a91c2_r<8 Zeichen>`, Rechte auf
genau eine Datenbank, `DROP` im `finally`. **Und sie trägt die halbe
Kriterium-6-Last**, weil `\connect` in einem Klartext-Dump an ihrem fehlenden
`CONNECT` scheitert (M8) — der `REVOKE CONNECT` aus §10 arbeitet hier ein
zweites Mal, für einen anderen Zweck.

---

## 14. Fernzugriff — hier kollidieren zwei Entscheidungen

**P5b baut keinen.** Das ist eine Verkleinerung gegenüber P5, und sie ist keine
Bequemlichkeit, sondern die Folge zweier Festlegungen, die zusammen nichts
übriglassen:

- Die Wirtsbeschränkung eines Kunden steht in PostgreSQL in `pg_hba.conf` — es
  gibt kein Gegenstück am Benutzer (`docs/37 §4`).
- `pg_hba.conf` wird nicht angefasst (Entscheidung des Betreibers, §21).

Bliebe ein Include-Punkt. Den kennt `pg_hba.conf` erst **ab PG 16**, und Debian
liefert ihn auch dort nicht eingeschaltet — gemessen. Auf Ubuntu 22.04 und
Debian 12 gibt es ihn nicht. Ein Fernzugriff, der auf der Hälfte der
Zielplattformen anders gebaut wäre, ist zwei Umsetzungen für eine Zusage.

**Was bleibt und was geht:**

- `listen_addresses` **hätte** einen sauberen Include-Punkt:
  `include_dir = 'conf.d'` steht in Debians `postgresql.conf` aktiv, das
  Verzeichnis existiert. Genutzt wird er in P5b nicht — ohne die
  IP-Beschränkung wäre ein offener Port ein Zugang von überall, und `%` als
  Wirt hat `docs/36 §12` mit Grund abgewiesen.
- Die Oberfläche zeigt das Häkchen **nicht** und sagt daneben, warum — dieselbe
  Form wie bei abgeschaltetem `bind-address` in P5 (`AbilityReachTest`).

Das gehört in `docs/20 §15` als offener Punkt, mit der Fassungsfrage als
Bedingung.

---

## 15. Der Rückbau

Wörtlich `docs/36 §5`: Vor `subscription.remove` reiht
`SubscriptionController::destroy` je Datenbank einen Vorgang ein, und die
Abschrift `subscription_name` mit `nullOnDelete` fängt den Fehlschlag auf.

**Zwei Stellen sind neu:**

1. **`DROP ROLE` scheitert, solange der Rolle etwas gehört.** PostgreSQL
   verweigert es mit einer Meldung, die aufzählt, was noch an ihr hängt.
   `pg.role.remove` läuft deshalb als `REASSIGN OWNED BY … TO <panel>` und
   `DROP OWNED BY …`, dann `DROP ROLE`. Wer nur `DROP ROLE` schreibt, bekommt
   eine Operation, die im Test funktioniert und im Betrieb an jeder Datenbank
   scheitert, in der der Kunde je eine Tabelle angelegt hat.
2. **`pg.server.install` hat kein Gegenstück, und `RemovalPathTest` wird das
   melden.** Er kennt `.install` als anlegendes Verb — deshalb hat
   `php.version.install` sein `php.version.remove`. Ein `pg.server.remove`
   wäre eine Operation, die die Datenbanken aller Kunden wegwirft. Sie bekommt
   deshalb einen Eintrag in `WITHOUT_REMOVAL`, mit der Begründung im Wert und
   nicht im Kommentar daneben, nach dem Muster von `panel.provision`:

   > *„Installiert den Datenbankserver. Der Weg zurück ist `apt remove
   > postgresql` durch den Betreiber und nicht eine Operation, die die Daten
   > aller Kunden wegwirft, weil jemand einen Knopf gedrückt hat."*

   **Und der Cluster, den sie anlegt, bekommt trotzdem einen Weg zurück:**
   `srvpanel db prune` findet Datenbanken ohne Zeile im Bestand — in beiden
   Systemen, aus derselben Schleife.

---

## 16. Was in der Oberfläche entsteht

Keine neue Seite. Die vorhandenen bekommen dazu:

| Datei | Was dazukommt |
|---|---|
| `Pages/Databases/Index.vue` | eine Spalte „System" — als Marke, nicht als Filter |
| `Pages/Databases/Create.vue` | die Wahl des Systems, **nur wenn beide verfügbar sind** |
| `Pages/Databases/Show.vue` | Verbindungsangaben je System; bei PostgreSQL der Satz über `127.0.0.1` und der über die Erweiterungen (§5) |
| `Pages/Settings/Database.vue` | der zweite Server: installiert? Cluster? Fassung? Und der Knopf aus §7 |

**Der Name ist die Stelle, an der es weh tut.** `x7f3a91c2_shop` ist siebzehn
Zeichen länger als `shop` und eine Kennung im Fliesstext — genau die Sorte, die
`v0.4.0-rc.4` gekostet hat, als sie die Seite um 83 px aus dem Bildschirm schob.
Screenshots in beiden Themes und bei 390 px, und nach jeder Aufnahme
`scrollWidth - clientWidth`. Bei 390 px wird hier nicht bei 390 px gemessen —
der Weg über den `<iframe>` steht in `docs/36 §22.5a`.

---

## 17. Die Schritte, in dieser Reihenfolge

Kein Schritt beginnt, bevor der vorige grün ist. Die CI läuft über
`workflow_dispatch` auf dem Zweig.

### Schritt 0 — Die Verweise, die niemand prüft

Zwei kleine Dinge, und beide sind dieselbe Sorte Fehler:

- `docs/20 §15` Punkt 5 verweist auf `37-postgresql.md`; die Datei heisst
  `37-uebergabe-an-p5b.md`. **`ChangelogTest::test_every_referenced_document_exists`
  gibt es, und er hätte das nicht gefunden** — er sieht in den `CHANGELOG` und
  prüft die *Nummer* über einen Glob, nicht den Dateinamen und nicht die
  Dokumente untereinander. Das ist Lehre zwei über Wächter aus `docs/37 §6`,
  eine Ebene weiter: *Sie dürfen `docs/` nicht auslassen.*
- `DocLinkTest`: Jeder Markdown-Verweis in `docs/` zeigt auf eine Datei, die es
  gibt. Der Bruch dazu benennt eine Zieldatei um.

### Schritt 1 — Der Agent, und zwar der Weg zurück zuerst

```
agent/src/Pg/Names.php          agent/src/Pg/Sql.php
agent/src/Pg/Server.php         agent/src/Pg/Session.php
agent/src/Pg/Shielding.php
agent/src/Runner.php            (das „läuft als"-Feld, §6)
agent/src/Ops/PgServerInfo.php
agent/src/Ops/PgDatabaseRemove.php   ← vor …
agent/src/Ops/PgDatabaseCreate.php   ← … dieser Zeile geschrieben
agent/src/Ops/PgRoleRemove.php
agent/src/Ops/PgRoleCreate.php
agent/src/Ops/PgRoleGrant.php
agent/src/Ops/PgRoleLock.php
agent/src/Registry.php
tests/Unit/PgNameTest.php  PgShieldingTest.php  RunnerUserTest.php
```

**Hier prüfbar, und diesmal richtig:** `agent/src/autoload.php` lädt ohne
Framework, und in diesem Container läuft ein PostgreSQL 16. Ein Wegwerfskript im
Scratchpad kann `Pg\Session` gegen einen echten Server fahren — das ist mehr,
als P5 an dieser Stelle hatte, und es gehört genutzt, bevor eine CI-Runde dafür
draufgeht.

### Schritt 2 — Die Migration und die Modelle

§9 als Code, `engine` in die drei Modelle, `db_prefix` in `SystemUser`, und
`Lifecycle::claim()` vergibt es mit.

### Schritt 3 — PostgreSQL kommt auf den Server (§7)

`PgServerInstall`, der Betreiberschalter, die zwei Unitlisten, und
`nfpm.yaml` — die Zeile `suggests: postgresql` bekommt ihre Begründung oder
verschwindet.

### Schritt 4 — Die Anwendung

`app/Support/Databases/` erweitert statt verdoppelt: `Databases::create()`
verzweigt an genau einer Stelle auf `engine` und schickt `db.*` oder `pg.*`.
`PgLifecycle` neben `DbLifecycle`.

### Schritt 5 — Sperre und Messung

§11 und §12, dazu `srvpanel usage`.

### Schritt 6 — Sichern und Zurückspielen (§13)

`ON_ERROR_STOP=1` **zuerst**, dann alles andere.

### Schritt 7 — Die Oberfläche und die Screenshots (§16)

Und die sechs Textstellen aus §12.

### Schritt 8 — Die Wächter brechen

`tests/waechter-brechen.sh`, geprüft von `BreakScriptTest`.

### Schritt 9 — Der Abnahmelauf (§19)

Auf `cloudsrv24`, mit zwei Abonnements. **Vorher §2.3 messen.**

---

## 18. Wächter und ihre Brüche

| Wächter | Die Regel | Der Bruch |
|---|---|---|
| `PgNameTest` | Ein Präfix ist nichtssagend und wird nie zweimal vergeben | Präfix aus der Abonnementnummer bilden |
| `PgShieldingTest` | Jede neu angelegte Datenbank wird abgesperrt, **und die Sichtenliste wird erfragt** | Die Liste als Konstante verdrahten |
| `PgRestoreTest` | Der Aufruf von `psql` trägt `ON_ERROR_STOP=1` — geprüft am erzeugten Aufruf, nicht an einer Konstanten | Den Schalter entfernen |
| `RunnerUserTest` | Das `as`-Feld nimmt nur Namen aus einer Positivliste; keine Operation reicht Freitext durch | Die Positivliste durch eine Prüfung auf „nicht leer" ersetzen |
| `UnitReachTest` | Jeder Unitname, den eine Operation nennt, kommt durch `ServiceAction::allowed()` | `postgresql` aus `ALLOWED_UNITS` nehmen |
| `DocLinkTest` | Jeder Verweis in `docs/` zeigt auf eine Datei, die es gibt | Eine Zieldatei umbenennen |
| `PackagingTest` (erweitert) | Jede Zeile unter `depends`/`recommends`/`suggests` hat eine Begründung oder eine Stelle im Code, die sie einlöst | Eine Zeile ohne beides einfügen — der heutige Zustand |
| `EngineReachTest` | Zu jeder `db.*`-Operation mit einem Gegenstück gibt es `pg.*`, oder ein begründeter Eintrag sagt warum nicht | Eine `pg.*`-Operation entfernen |

**Vier laufen von selbst mit** und sind der eigentliche Grund, warum §8 so
entschieden ist: `RemovalPathTest` (§15), `AgentOperationReachTest`,
`AbilityReachTest` (§14) und `SecretsStayOutOfTheQueueTest` — das Passwort einer
Rolle gehört so wenig in `operations.payload` wie das eines MariaDB-Benutzers.

**Und die Falle, in die dieses Vorgehen dreimal gelaufen ist**, gilt hier für
`EngineReachTest`: Er zählt seine Treffer dort, wo die Regel stehen **darf**,
nicht wo sie stehen soll. Sonst meldet er Rot, sobald jemand aufräumt.

---

## 19. Das Abnahmekriterium — als Befehlsfolge

```
# Voraussetzung: zwei Abonnements. Der Lauf legt sie NICHT selbst an — eine
# Kundennummer ist auf Dauer verbraucht und ein Systembenutzer erst recht
# (docs/35). A und B stehen unten für ihre Präfixe aus §4.

# 0  DER SERVER
#    srvpanel db --postgres=on, dann Einstellungen → Datenbankserver.
#    erwartet: Fassung, Cluster und Port stehen da.
#    Gegenprobe, falls schon ein Cluster lief: Er ist unverändert —
#      md5sum /etc/postgresql/*/main/pg_hba.conf   vorher und nachher gleich.
#    BELEG: die Nummer des Vorgangs pg.server.install und seine Ausgabe.
#           „Ist schon installiert" ist auch ein Beleg, aber ein anderer.

# 1  ANLEGEN
#    In Abo A eine PostgreSQL-Datenbank und einen Zugang anlegen.
#    erwartet: Datenbank <A>_shop und Rolle <A>_web existieren, das Passwort
#              steht GENAU EINMAL auf dem Bildschirm.
#    Gegenprobe: SELECT id FROM operations WHERE payload LIKE '%<passwort>%';
#      → 0 Zeilen. Nach dem Passwort suchen, nicht Vorgänge zählen.

# 2  BENUTZEN
#    psql "postgresql://<A>_web:PW@127.0.0.1/<A>_shop"
#    Tabelle anlegen, füllen, lesen.
#    erwartet: geht. Und über den SOCKET geht es nicht — das ist kein Fehler,
#              sondern §7, und der Kunde muss es in der Oberfläche lesen können.

# 3  KEINE AUFZÄHLUNG   ← Kriterium 1 und 2
#    In Abo B ebenfalls eine anlegen. Dann als <A>_web:
#      SELECT datname FROM pg_stat_database;
#      SELECT datname FROM pg_stat_activity;
#    erwartet BEIDE: ERROR: permission denied for view <name>.
#    Nicht „scheitert" — die Meldung, wörtlich. Ein Tippfehler im Sichtnamen
#    scheitert genauso (docs/36 §22.3m, dieselbe Lehre eine Stufe später).
#      SELECT datname FROM pg_database ORDER BY 1;
#    erwartet: die Liste kommt — und KEIN Name darin nennt eine
#              Abonnementnummer, einen Kundennamen oder eine Domain.
#              Die AUSGEGEBENEN NAMEN gehören ins Protokoll, nicht ihre Anzahl.

# 3b DIE template0-FALLE   ← der Fund, den kein Test gefunden hätte
#    In Abo A eine ZWEITE Datenbank anlegen, diesmal mit gesetzter Sortierung
#    (die erzwingt TEMPLATE template0). Dann als <A>_web darin:
#      SELECT count(*) FROM pg_stat_database;
#    erwartet: permission denied — WIE in der ersten.
#    OHNE DIESEN SCHRITT IST KRITERIUM 2 NICHT GEFAHREN. Am 9. August 2026 im
#    Container gemessen: dieselbe Rolle sah in der einen Datenbank nichts und
#    in der nächsten sieben Namen, und beide sahen von aussen gleich aus.

# 4  KEIN ZUGRIFF   ← Kriterium 3
#      psql "postgresql://<A>_web:PW@127.0.0.1/<B>_shop"
#    erwartet: FATAL: permission denied for database "<B>_shop".
#    Und die Gegenprobe zum Ratekanal, ausdrücklich gefahren:
#      psql "postgresql://<A>_web:PW@127.0.0.1/gibtsnicht"
#    erwartet: FATAL: database "gibtsnicht" does not exist.
#    DIESE ZEILE IST KEIN FEHLSCHLAG DES LAUFS. Sie belegt das Risiko aus
#    §22 und gehört ins Protokoll — ein Kriterium, das seine eigene Grenze
#    nicht misst, behauptet sie nur.

# 5  DIE ABSPERRUNG HÄLT   ← Kriterium 4
#    Als <A>_web in der eigenen Datenbank:
#      GRANT CONNECT ON DATABASE <A>_shop TO PUBLIC;
#    erwartet: WARNING: no privileges were granted — und danach scheitert
#              <B>_web weiterhin an Schritt 4.
#      DROP DATABASE <A>_shop;
#    erwartet: ERROR: must be owner of database.
#    Beide Zeilen, nicht eine: Die erste meldet keinen Fehler, sie meldet eine
#    Warnung und „GRANT". Wer nur den Rückgabewert liest, liest Erfolg.

# 6  SICHERN   ← Kriterium 6 und die Hälfte des Auftrags
#    (a) Im Panel exportieren, warten, herunterladen.
#        erwartet: Datei unter /var/lib/srvpanel/dumps/<abo>/, root:srvpanel
#                  0640, Verzeichnis root:srvpanel 0710, mit den Zeilen aus 2:
#                    zcat <datei> | grep -c "^COPY\|^INSERT"   → > 0
#    (b) UND als Kunde von aussen:
#        pg_dump "postgresql://<A>_web:PW@127.0.0.1/<A>_shop" > /tmp/kunde.sql
#        erwartet: geht, und die Datei ist nicht leer.
#        DAS IST KRITERIUM 6 UND ES IST DER GRUND, WARUM pg_database LESBAR
#        BLEIBT. Fällt diese Zeile, ist §3 Punkt 1 auf dem falschen Weg gelöst
#        worden — gemessen am 9. August: mit entzogenem pg_database scheitert
#        genau dieser Aufruf.

# 7  ZURÜCKSPIELEN
#    Tabelle löschen, den Dump im Panel zurückspielen.
#    erwartet: die Zeilen sind zurück — dieselben Zahlen je Tabelle wie vorher,
#              auf beiden Seiten notiert.
#    Und: während des Laufs entsteht <A>_r<zufall> und ist danach fort:
#      SELECT rolname FROM pg_roles WHERE rolname LIKE '%\_r%';  → 0 Zeilen.
#    BELEG: die Nummer des Vorgangs und sein Zustand „erledigt". OHNE DIESE
#           ZEILE IST DAS KRITERIUM NICHT GEFAHREN — die Abfrage oben ist auch
#           dann leer, wenn nie ein Restore lief (docs/36 §22.3m).

# 8  DER DUMP DARF NICHTS ERZWINGEN   ← Kriterium 5
#    Einen ANDEREN Dump von Hand ergänzen — nicht den aus Schritt 7:
#      zcat <datei> > /tmp/probe.sql
#      cat >> /tmp/probe.sql <<'ENDE'
#      ALTER ROLE <A>_web SUPERUSER;
#      GRANT pg_read_server_files TO <A>_web;
#      COPY t FROM PROGRAM 'id > /tmp/ausbruch.txt';
#      \connect <B>_shop
#      CREATE TABLE fremd_erreicht (id int);
#      ENDE
#      gzip -c /tmp/probe.sql > <datei> && rm /tmp/probe.sql
#      chown root:srvpanel <datei> && chmod 0640 <datei>
#    und zurückspielen.
#    erwartet: der Vorgang SCHEITERT, und
#      SELECT rolsuper FROM pg_roles WHERE rolname = '<A>_web';  → f
#      ls /tmp/ausbruch.txt                                      → nicht da
#      psql -d <B>_shop -c '\dt'                                 → kein fremd_erreicht
#    BELEG: die Fehlermeldung des Vorgangs, WÖRTLICH. Sie muss die abgewiesene
#           Anweisung nennen. „Fehlgeschlagen" allein ist eine Aussage über uns
#           und nicht über den Server.
#    ACHTUNG, HIER LIEGT DIE FALLE DIESER STUFE: Ohne ON_ERROR_STOP=1 meldet
#    psql für genau diesen Dump ERFOLG (am 9. August gemessen: Rückgabewert 0
#    bei vier Anweisungen, von denen die dritte abgewiesen wurde). Der Vorgang
#    stünde auf „erledigt", die drei Abfragen oben wären trotzdem alle grün —
#    und das Kriterium sähe erfüllt aus, während der Beleg fehlt.

# 9  DER RÜCKBAU LÄSST NICHTS LIEGEN   ← Kriterium 7
#    Abo A zurückbauen. Erwartet, alle vier:
#      SELECT datname FROM pg_database WHERE datname LIKE '<A>%';  → leer
#      SELECT rolname FROM pg_roles    WHERE rolname LIKE '<A>%';  → leer
#      ls /var/lib/srvpanel/dumps/<abo>/                           → nicht da
#      srvpanel db                                    → „Nichts liegengeblieben."
#    Die letzte prüft den Bestand gegen die Platte, und das kann keine Abfrage
#    an PostgreSQL (docs/36 §22.3r).
#    Und die Gegenprobe, dass B unberührt ist.
#    ACHTUNG: Der Rückbau ist an einem Abo zu fahren, das MINDESTENS EINE
#    Sicherung hat — sonst ist die dritte Erwartung eine Abwesenheit ohne
#    Vorgeschichte.
```

---

## 20. Diese Umgebung

Unverändert aus `docs/36 §18`, mit **einer Korrektur, die eine Zeile wert ist**:

| | hier |
|---|---|
| `vendor/` | nein — `composer install` scheitert am Proxy (`codeload.github.com` → 403, packagist → 200) |
| PHPUnit | nein, Folge davon |
| PHPStan | `phpstan.phar` von den Releases, Stufe 6 über `agent/src` **und** `tests/Support` |
| Pint | `pint.phar` von den Releases; `php-cs-fixer` ist **nicht** Pint |
| MariaDB | nein |
| **PostgreSQL** | **ja — 16.13, Server und Klienten.** `initdb` in den Scratchpad, `pg_ctl` mit `-k` auf einen **kurzen** Socketpfad: Der Scratchpad-Pfad überschreitet die Grenze von 107 Byte, und die Meldung nennt sie |
| `agent/` fahren | ja, über `agent/src/autoload.php` |
| npm | ja |
| die Testsuite | `workflow_dispatch` auf `ci.yml`, auf dem Zweig |

**Was das für P5b heisst:** Der grösste Teil von `agent/src/Pg/` ist hier
*fahrbar*, nicht nur übersetzbar. Das ist der Unterschied zwischen P5 und P5b,
und er ist bares Geld — jeder Fund, der hier fällt, spart eine CI-Runde.

---

## 21. Die Entscheidungen des Betreibers

Getroffen am 9. August 2026, nach der Messung aus §2 und in dieser Reihenfolge
vorgelegt:

1. **Das Abnahmekriterium wird neu gefasst** (§3). Nichtssagende Namen statt
   Entzug von `pg_database`, die elf Statistiksichten gesperrt, der Ratekanal
   benannt statt verschwiegen. Der Wortlaut aus `docs/20 §9` wird damit nicht
   erfüllt, sein Zweck schon — und `docs/20 §9 P5b` wird entsprechend
   berichtigt.
2. **Die Datenbank gehört dem Panel** (§5). Erweiterungen kommen später über
   eine Positivliste und stehen bis dahin in `docs/20 §15`.
3. **`Runner` lernt „läuft als"** (§6), statt `runuser` auf die Positivliste zu
   nehmen.
4. **Ein Datenmodell, eine Fläche, zwei Sätze Agent-Operationen** (§8).
5. **PostgreSQL wird erkannt *und* auf Verlangen installiert** (§7). Ein
   vorhandener Cluster ist Bestand und wird benutzt; `pg_hba.conf` bleibt
   unangetastet; Kunden verbinden sich über `127.0.0.1`.

**Nicht vorgelegt, weil entscheidbar — und deshalb hier zum Widerspruch:**

6. **Fernzugriff entfällt in P5b** (§14). Er folgt aus 5 und ist keine eigene
   Entscheidung, aber er verkleinert den Umfang gegenüber P5 sichtbar und
   gehört deshalb genannt.
7. **Die Kontingente gelten für beide Systeme zusammen** (§12).

---

## 22. Risiken, ehrlich benannt

1. **Der Ratekanal bleibt** (M3). Wer die Zusatznamen errät, kann die Existenz
   einer fremden Datenbank bestätigen. Zugreifen kann er nicht. Mit den
   nichtssagenden Präfixen aus §4 ist der Raum gross geworden, aber nicht
   unendlich — wer ein Präfix kennt, probiert `shop`, `db`, `wordpress`.
   Schliessen liesse er sich nur mit einem Cluster je Kunde.
2. **Die Sperre beendet keine bestehende Sitzung** (§11) — und das gilt für P5
   genauso, es stand dort nur nirgends.
3. **Auf PG 14 ist das Schema `public` offen.** Ubuntu 22.04 liefert es. Die
   Absperrung aus §10 nimmt das Recht ausdrücklich weg, statt sich auf die
   Vorgabe zu verlassen — aber die Zeile gehört auf jeder Zielplattform
   gemessen und nicht geglaubt.
4. **Die Erweiterungen fehlen** (§5). Eine Anwendung, die `uuid-ossp` braucht,
   läuft nicht. Das ist die spürbarste Einschränkung dieser Stufe für einen
   Kunden, und sie ist eine Folge von Entscheidung 2.
5. **Der Betreiber liest nichtssagende Namen** (§4). `srvpanel db list` löst
   das auf der Kommandozeile; wer `psql` von Hand benutzt, hat es schwerer als
   vorher.
6. **Der Abnahmelauf verbraucht zwei Systembenutzer**, endgültig (`docs/35`).
7. **`waechter-brechen.sh` bleibt zur Hälfte ungeprüft.** `docs/36 §20` Punkt 1
   gilt unverändert; P5b legt acht weitere Brüche darauf. Der Rotbeweis braucht
   ein lokales `vendor/`.

---

## 23. Umfang

| Bereich | neue Dateien | geänderte |
|---|---|---|
| `agent/` | 7 Bausteine, 15 Operationen | `Registry.php`, `Runner.php`, `ServiceAction.php` |
| `app/` | 1 Dienst, 1 Lebenslauf | `Databases`, `Dumps`, `Usage`, `Quota`, `SubscriptionController`, `SystemUser`, `Lifecycle` |
| `database/` | 1 Migration | — |
| `resources/` | — | 4 Seiten |
| `tests/` | 8 Wächter | `waechter-brechen.sh`, `RemovalPathTest`, `AgentOperationReachTest`, `PackagingTest` |
| `packaging/` | — | `nfpm.yaml`, `bin/srvpanel` |
| `docs/` | dieses | `20 §9 P5b`, `20 §15`, `23`, `CHANGELOG`, `CLAUDE.md` |

Geschätzt zwei bis drei Wochen, dieselbe Grössenordnung wie `docs/20 §9` sie
nennt. **Der Fernzugriff ist nicht enthalten** (§14).

---

## 24. Umsetzung — was beim Bauen anders war als im Plan

*Noch leer. Hier steht später, wo dieser Plan nicht getragen hat — und
insbesondere die Antwort auf die Frage aus §8: Musste `agent/src/Db/` doch
aufgerissen werden?*
