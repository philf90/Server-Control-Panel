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

### 2.2a Der zweite Durchgang: der Fernzugriff

Nachgemessen am selben Tag, nachdem §14 in seiner ersten Fassung abgeraten
hatte. **Die Messung hat die Empfehlung umgeworfen**, und zwar an ihrer
Voraussetzung — siehe §14.

| # | Frage | Befund |
|---|---|---|
| M15 | Braucht ein verwalteter Bereich in `pg_hba.conf` einen Include-Punkt? | **Nein.** Ein Block zwischen `# BEGIN srvpanel` und `# END srvpanel` ist auf jeder Fassung derselbe Bau. Die Include-Frage stellt sich gar nicht |
| M18 | Lässt sich der Zustand zurücklesen? | **Ja, sauber.** `pg_hba_file_rules` gibt Regelnummer, Zeilennummer, Rolle, Adresse, Netzmaske, Methode — **und eine Spalte `error`**. Nur für Superuser lesbar, ein Kunde bekommt `permission denied` |
| M16 | Kaputte `pg_hba.conf` + **Reload**? | Harmlos: Der Server bedient weiter, **behält die alten Regeln**, und `pg_hba_file_rules` nennt den Fehler mit Zeilennummer |
| M17 | Kaputte `pg_hba.conf` + **Neustart**? | **`FATAL: could not load pg_hba.conf` — der Cluster kommt nicht hoch.** Eine falsche Zeile ist beim Schreiben unsichtbar und zündet beim nächsten Neustart |
| M19 | Eine Rolle, mehrere Netze? | Geht — zwei Zeilen, und `pg_hba_file_rules` liest beide samt Reihenfolge zurück |
| M20 | `listen_addresses` — Reload oder Neustart? | Kontext `postmaster`, also **Neustart**. Wie `bind-address` in P5 |
| M22 | Eine Zeile für eine Rolle, die es nicht gibt? | **Kein Fehler.** Sie bleibt liegen, und niemand meldet es |
| M23 | Zeile je Datenbank statt `all`? | Geht: `host <db> <rolle> <netz>` lässt die Rolle in ihre Datenbank und in `postgres` nicht — `no pg_hba.conf entry for … database "postgres"` |

### 2.2b Der dritte Durchgang: die Anmeldung des Agenten

Nachgemessen, nachdem §6 in seiner ersten Fassung einen Kennungswechsel im
`Runner` vorgesehen hatte. **Auch diese Empfehlung ist gefallen.**

| # | Frage | Befund |
|---|---|---|
| M24 | Kommt root als `postgres` durch? | Nein — `Peer authentication failed` |
| M25 | Und wenn es eine Rolle `root` gibt? | **Ja, als Superuser** — `local all all peer` steht in Debians Vorgabe, und peer bildet die Kennung auf die gleichnamige Rolle ab. Keine Datei wird angefasst |
| M26 | Trägt `pcntl_fork` + `posix_setuid` + `pcntl_exec`? | **Nein.** Es läuft, aber die Umleitung der Dateinummern ist nicht verlässlich: Die Ausgabe von `psql` landete in der Datei für stderr, Rückgabewert 0. Dieselbe Reihenfolge stimmte in einem isolierten Fall — sie hängt davon ab, was der Prozess sonst offen hat |
| M26b | Erbt das Kind fremde Dateinummern? | **Ja**, den Socket des Agenten |
| M27 | Kann root einen Cluster mit eigenem Bootstrap-Benutzer anlegen? | Ja: `pg_createcluster 16 probe -- --username=root`, und root verbindet sich danach sofort. **Nebenbefund:** In diesem Cluster gibt es *keine* Rolle `postgres`, und Debians Wartungswerkzeuge rechnen mit ihr |

### 2.2c Der Ausgangszustand des Zielservers

Gemessen auf `cloudsrv24` am 9. August 2026, **vor** dem ersten Schritt von P5b.

| Frage | Befund |
|---|---|
| Ist PostgreSQL da? | **Nein.** Kein `psql`, kein `pg_lsclusters`, kein `/etc/postgresql`, und `sudo -u postgres` meldet `unknown user postgres` |
| Was würde `apt` installieren? | Das Metapaket `postgresql` in **`16+257build1.1`** |

**Und was daraus wurde, ist am 9. August 2026 nachgemessen worden: `16.14
(Ubuntu 16.14-0ubuntu0.24.04.1)`.** Hier stand vorher, `cloudsrv24` bekomme
16.13 — „byteweise dieselbe Fassung wie im Entwicklungscontainer". Das war
**falsch geschlossen**: `16+257build1.1` ist die Nummer des *Metapakets* und
sagt nichts über die Serverfassung dahinter; 16.13 war die Zahl aus dem
Container, nicht vom Server. Wörtlich die Falle aus `CLAUDE.md` — *Wissen aus
zweiter Hand sieht aus wie Wissen* —, diesmal in diesem Plan selbst.

**Folgenlos ist es trotzdem, und zwar nachprüfbar:** Was §2.2, §2.2a und §2.2b
gemessen haben, hängt an der **Hauptfassung** und nicht am Wartungsstand — die
dreizehn Kanäle, die `public`-Regel ab PG 15, `WITH (FORCE)`, `DROP OWNED BY` je
Datenbank, der Rückgabewert von `psql -f` ohne `ON_ERROR_STOP`. Beide sind 16.
Der Satz „dieselbe Konfiguration" gilt also weiter; nur seine Begründung war
eine andere, als hier stand.

**Und der Zustand „nichts installiert" bleibt stehen.** Er ist der Fall, für den
`pg.server.info` und `pg.server.install` gebaut werden (§7) — wer ihn von Hand
auflöst, nimmt der Operation ihren einzigen Prüfstein. Die erste Installation
auf diesem Server macht das Panel.

> **Der Messlauf hat dabei zwei eigene Fehler gezeigt, beide im Werkzeug und
> nicht im Gegenstand.** Der erste: Die Befehlsfolge setzte voraus, dass
> PostgreSQL installiert ist, und war für einen Server geschrieben, den es so
> nicht gab. Der zweite: `mysql -p` liest sein Passwort von der Standardeingabe
> — werden mehrere Zeilen zusammen eingefügt, nimmt es die **nächste Zeile** als
> Passwort. Die Probe auf den Ratekanal von MariaDB (§2.3 Punkt 4) hat deshalb
> zweimal `ERROR 1045` geliefert und die Frage nicht berührt; sie bleibt offen.

### 2.2d Der Zustandsraum eines vorhandenen Clusters

Gemessen am 9. August 2026, nachdem der Betreiber gefragt hatte, was
`pg.server.install` tun soll, wenn schon ein Cluster da ist (§7).

| # | Frage | Befund |
|---|---|---|
| M42 | Paket installiert, Cluster **gestoppt** — wie sieht das aus? | **`/var/run/postgresql` fehlt** — genau wie bei „gar nicht installiert" |
| M43 | Zwei Cluster nebeneinander? | Gehen; der zweite bekommt automatisch 5433. `pg_lsclusters` nennt beide mit Fassung, Name, Port, Status und Verzeichnis |
| M44 | Welchen Cluster spricht eine Verbindung an? | `current_setting('data_directory')` und `port` sagen es — **von innen, zurückgelesen** |

**M42 ist ein Fehler in Code, der schon eingecheckt war.** `Pg\Server::describe()`
unterschied „nicht installiert" von „läuft nicht" an `is_dir()` auf das
Socketverzeichnis — und das fehlt in beiden Fällen. Zwei verschiedene Handgriffe
des Betreibers, eine Meldung: wörtlich Lehre 3 aus `docs/37 §6`. Der Abschnitt,
der in §6 als Fortschritt gegenüber P5 steht („ein Zustand, den P5 nicht kennt"),
hat die Unterscheidung nicht getroffen, die er behauptet.

**Die Entscheidung des Betreibers dazu (§21, Punkt 9):** `pg_lsclusters` fragen,
bevor verbunden wird, und bei mehreren *laufenden* Clustern nicht wählen. Daraus
sieben Zustände, jeder mit genau einem Handgriff — `absent`, `no_cluster`,
`stopped`, `ambiguous`, `not_handed_over`, `unusable`, `ready`. Alle sieben sind
gegen einen echten Server gefahren.

### 2.3 Was hier nicht zu messen war

Diese Fragen gehören auf `cloudsrv24` und auf die vier Zielplattformen, **bevor
Schritt 4 beginnt**:

1. **Die Fassungsspanne — für drei der vier Plattformen.** Ubuntu 24.04
   liefert 16.14, gemessen auf `cloudsrv24` nach der ersten Installation
   (§2.2c) — im Entwicklungscontainer sind es 16.13, und der Unterschied ist
   ein Wartungsstand derselben Hauptfassung. Für Debian 12, Debian 13 und Ubuntu
   22.04 steht die Zahl weiter aus, und die für uns wichtigste Regel hat sich
   mit **PG 15** geändert: Bis PG 14 darf `PUBLIC` im Schema `public` jeder
   Datenbank Objekte anlegen, ab PG 15 nicht mehr. Auf der älteren Fassung ist
   das ein Loch in genau der Wand, die §5 baut. **Für die Abnahme von P5b ist
   das folgenlos** — sie läuft auf `cloudsrv24` —, für die Freigabe nicht.
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

## 6. Wie der Agent sich anmeldet — es gibt eine Rolle `root`

> **Hier stand: „`Runner` lernt „läuft als"."** Der Abschnitt setzte einen
> Mechanismus voraus, ohne ihn zu prüfen — und die Prüfung hat ihn nicht
> getragen (§2.2b). Das ist im selben Dokument zum dritten Mal derselbe Fehler:
> §3 für `pg_database`, §14 für den Include-Punkt, hier für den
> Kennungswechsel. **Ein Plan, der eine Bauform nennt, hat sie noch nicht
> gemessen.**

Bei MariaDB kostete die Anmeldung keine Zeile: Der Agent läuft als root, MariaDB
erkennt root über den Socket. PostgreSQL bildet Unix-Kennungen auf **Rollen** ab
— und wenn es eine Rolle mit dem Namen der Kennung gibt, ist genau das die
Antwort:

```
psql -U postgres   → FATAL: Peer authentication failed for user "postgres"
psql -U root       → root|t
```

**Debians Vorgabe enthält `local all all peer`.** Existiert die Rolle `root`,
kommt der Agent als Superuser durch — ohne Änderung an `pg_hba.conf`, ohne
zusätzliches Programm auf der Positivliste, ohne Eingriff in `Runner` und ohne
ein Geheimnis, das irgendwo liegt. `Pg\Session` ruft `psql -U root` auf, sonst
nichts.

### 6.1 Wer die Rolle anlegt: der Betreiber, einmal

Sie zu erzeugen braucht genau eine Verbindung als Superuser, und die hat der
Agent vor ihr nicht. **Diesen einen Handgriff macht der Betreiber**, und das
Panel zeigt ihm den Befehl:

```
sudo -u postgres psql -c "CREATE ROLE root SUPERUSER LOGIN"
```

Das ist dieselbe Form wie `srvpanel db --remote=on` (`docs/36 §19`,
Entscheidung 5): Eine Übergabe, die den Server verändert, ist eine Handlung des
Betreibers und kein Häkchen. Und es ist **ein** Weg für beide Fälle — den
vorhandenen Cluster und den, den `pg.server.install` bringt.

`pg.server.info` beantwortet die Frage „ist übergeben worden?" und meldet sie
als **Auskunft und nicht als Fehlschlag**; solange sie offen ist, bietet das
Panel PostgreSQL nicht an und zeigt den Befehl daneben.

### 6.2 Was gemessen wurde und die erste Fassung umgeworfen hat

- **`proc_open` kennt keine Option für eine fremde Kennung.** Sie ist die
  einzige Stelle, an der der Agent ein Programm startet.
- **`pcntl_fork` + `posix_setuid` + `pcntl_exec` läuft — und die Umleitung der
  Dateinummern ist nicht verlässlich.** In einem Lauf landete die Ausgabe von
  `psql` in der Datei für **stderr**, während dieselbe Reihenfolge in einem
  isolierten Fall stimmte. Der Rückgabewert war beide Male 0. *Was Erfolg meldet
  und die Daten woanders ablegt* ist genau die Sorte Fehler, gegen die dieses
  Projekt seine Wächter baut — und hier stünde sie in der
  sicherheitsempfindlichsten Klasse, durch die jede vorhandene Operation läuft.
- **Der geforkte Prozess erbt den Socket des Agenten.** Ohne `CLOEXEC` hiesse
  das, einem Prozess unter fremder Kennung den Panel-Socket mitzugeben.

### 6.3 Was daraus für die Positivliste folgt

Sie wächst um **drei Programme und keine Vollmacht**: `psql`, `pg_dump`,
`pg_restore`. `runuser`, `su`, `setpriv` und `sudo` stehen nicht darauf und
gehören nicht darauf — ein Programm, das als root beliebige andere unter
beliebiger Kennung startet, wäre die weiteste Zeile der ganzen Liste, auf der
`certbot` in P4 mit einer schwächeren Begründung keinen Platz mehr hatte.
`AgentIdentityTest` (§18) hält beides fest: kein Programm, das die Kennung
wechselt, und `psql` wird nur aus `Pg\Session` gerufen.

**Was offenbleibt, und zwar bewusst:** eine eigene Superuser-Rolle `srvpanel`
mit Passwort unter `/etc/srvpanel/db/`. Sie brächte gegenüber der Rolle `root`
nichts, das sie nicht auch kostet — eine Ablage für ein Geheimnis, das es sonst
nicht gäbe.

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
   festgelegt. Hier: `srvpanel db --postgresql=on`. Nie ein Kundenhäkchen.

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

- **~~`postgresql.service` und `postgresql@*-main.service` fehlen in
  `ServiceAction::ALLOWED_UNITS`~~ — die Begründung ist beim Bauen
  weggefallen.** Sie lautete: „Ohne den Eintrag kann der Agent den Dienst, den
  er gerade installiert hat, nicht starten." Er startet ihn mit
  `pg_ctlcluster` (Entscheidung 9) und lädt in §14 mit
  `SELECT pg_reload_conf()` — `systemctl` kommt in beiden Wegen nicht vor.

  **Ein Eintrag ohne Aufrufer ist deshalb keine Vorsorge, sondern das, was
  `AgentOperationReachTest` verbietet:** *Code, der als root läuft und zu dem
  kein Weg führt, ist Angriffsfläche ohne Nutzen.* `service.action` wird im
  ganzen Panel von genau zwei Stellen gerufen — `webserver.reload` und
  `srvpanel setup` —, und keine davon meint PostgreSQL. Der Eintrag entfällt;
  wenn ein Knopf entsteht, der PostgreSQL über systemd steuert, kommt er in
  demselben Beitrag dazu.

  **In der Unitliste von `OverviewController` steht `postgresql.service`
  dagegen**, denn dort geht es ums Sehen und nicht ums Schalten:
  `service.status` führt keine Positivliste. Die Zeile erscheint nur, wenn es
  die Unit gibt — ein dauerhaftes „nicht vorhanden" auf jedem Server ohne
  PostgreSQL wäre Rauschen an der Stelle, an der man Störungen sucht.
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

### `db_users.host` trägt für PostgreSQL nicht

**Die einzige Stelle, an der ein Datenmodell aus P5 bricht** — und sie bricht
aus einem Grund, der in `docs/37 §4` schon als „die teuerste Zeile" der
Übergabetabelle stand.

In MariaDB ist der Wirt Teil der Identität: `'p1001_web'@'localhost'` und
`'p1001_web'@'203.0.113.5'` sind **zwei Benutzer mit zwei Passwörtern**, und
deshalb ist `(name, host)` eindeutig und richtig. In PostgreSQL ist es eine
Rolle mit einem Passwort, und die erlaubten Netze stehen in `pg_hba.conf` —
mehrere je Rolle (M19). Zwei Zeilen mit demselben Namen wären hier nicht zwei
Zugänge, sondern zwei Regeln für einen; `pg.role.create` liefe zweimal, und der
zweite Lauf setzte ein zweites Passwort auf dieselbe Rolle.

Deshalb eine eigene Tabelle:

```php
Schema::create('db_user_networks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('db_user_id')->constrained('db_users')->cascadeOnDelete();

    // Als Text und nicht als zwei Spalten: Was hier steht, geht Zeichen für
    // Zeichen in eine Zeile von pg_hba.conf, und die Schreibweise ist die von
    // PostgreSQL. Zerlegt und wieder zusammengesetzt wäre es eine zweite
    // Fassung derselben Regel.
    $table->string('cidr', 43);

    $table->timestamps();
    $table->unique(['db_user_id', 'cidr']);
});
```

**`db_users.host` bleibt trotzdem stehen.** Für MariaDB sagt die Spalte die
Wahrheit und trägt den eindeutigen Index; sie zu entfernen wäre eine Migration
über Kundendaten, damit zwei Systeme dieselbe leere Spalte teilen. Für
PostgreSQL steht dort `localhost`, und die Netze stehen daneben. Was die
Oberfläche zeigt, entscheidet `engine` — nicht zwei Felder nebeneinander, von
denen eines immer leer ist.

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
| `pg.remote.access` | `listen_addresses` über `conf.d`, der verwaltete Block in `pg_hba.conf`, mit Rückweg (§14) | **ja** — Reload, und bei Bedarf Neustart |
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
agent/src/Pg/Hba.php        Der verwaltete Block, das Zurücklesen, der Rückweg
```

**`Pg\Hba` ist eine eigene Klasse und keine Methode in der Operation.** Sie
schreibt in eine Datei, deren Fehler erst beim nächsten Neustart wirkt (§14.2) —
das ist die Sorte Code, die man einzeln prüfen können muss, und
`PgHbaRollbackTest` fährt sie einzeln.

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

> Gemessen und bestätigt am 9. August 2026: ohne den Schalter Rückgabewert 0 und
> die vierte Anweisung lief, mit ihm Rückgabewert 3 und Abbruch. Der Aufruf
> selbst steht in `Pg\Session::restore()` und nicht in der Operation —
> `AgentIdentityTest` besteht darauf, dass `psql` an genau einer Stelle gerufen
> wird, und hat den ersten Anlauf zurückgewiesen.

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
genau eine Datenbank, `DROP` im `finally`.

> **Drei Sätze dieses Abschnitts hat das Bauen umgeworfen** (9. August 2026).
>
> **Sie kommt über den Socket gar nicht herein.** Debians `pg_hba.conf` beginnt
> mit `local all all peer`, und `peer` verlangt einen gleichnamigen
> Unix-Benutzer. „Wie `docs/36 §10.2`" trägt hier nicht — MariaDB lässt die
> Anmeldung mit Passwort über den Socket zu, PostgreSQL nicht. Die Antwort ist
> Entscheidung 9 in §21: eine Gruppenrolle `srvpanel_restore` und eine Zeile
> ganz oben in `pg_hba.conf`.
>
> **Sie braucht `GRANT ALL ON SCHEMA public`.** Seit PostgreSQL 15 darf `PUBLIC`
> dort nicht mehr schreiben; ohne die Zeile bricht das Zurückspielen an der
> ersten `CREATE TABLE` ab, also nach dem halben Vorspann. In MariaDB gibt es
> dazu kein Gegenstück, weil ein Schema dort die Datenbank *ist*.
>
> **`DROP` im `finally` allein wirft die Daten weg.** Was eine Rolle anlegt,
> gehört ihr, und beim Zurückspielen legt sie die ganze Datenbank an; ein
> `DROP OWNED BY` nimmt sie wieder mit. Der Lauf meldete Erfolg, und die Tabelle
> war fort. Davor steht deshalb ein `REASSIGN OWNED BY … TO` auf den Eigentümer
> der Datenbank — gefragt, nicht angenommen.
>
> Und eine Beruhigung: Ein `pg_dump` **einer** Datenbank enthält kein
> `\connect` (gemessen: null Vorkommen, `pg_dumpall` drei). Die Falle unten
> trifft nur Mitgebrachtes. **Und sie trägt die halbe
Kriterium-6-Last**, weil `\connect` in einem Klartext-Dump an ihrem fehlenden
`CONNECT` scheitert (M8) — der `REVOKE CONNECT` aus §10 arbeitet hier ein
zweites Mal, für einen anderen Zweck.

---

## 14. Fernzugriff — und ein Abschnitt, den seine eigene Messung umgeworfen hat

> **Hier stand: „P5b baut keinen."** Die Begründung war, `pg_hba.conf` kenne
> einen Include-Punkt erst ab PG 16, ein Fernzugriff wäre also auf der Hälfte
> der Zielplattformen anders gebaut. Der Satz stimmt und war trotzdem die
> falsche Frage: **Ein verwalteter Block zwischen Markern braucht keinen
> Include** (M15) und ist auf PG 14 bis 17 derselbe Bau. Die Prämisse war
> richtig, die Folgerung nicht.
>
> Das ist im selben Dokument zum zweiten Mal derselbe Fehler — §3 hält für
> `pg_database` fest, dass ein zutreffender Satz die falsche Frage beantworten
> kann. Er ist hier nicht durch Nachdenken aufgefallen, sondern dadurch, dass
> jemand nachgemessen hat, was der Abschnitt behauptet.

**P5b baut den Fernzugriff**, im Zuschnitt von `docs/36 §12`. Der Betreiber
schaltet ihn frei, `0.0.0.0/0` wird abgewiesen, und die Oberfläche sagt, dass
die Beschränkung im Datenbankserver gilt und nicht im Paketfilter.

### 14.1 Die zwei Hälften, und nur eine ist neu

**`listen_addresses` hat einen sauberen Include-Punkt.**
`include_dir = 'conf.d'` steht in Debians `postgresql.conf` **aktiv**, das
Verzeichnis existiert — P5b schreibt `/etc/postgresql/<fassung>/main/conf.d/60-srvpanel.conf`
und fasst keine Distributionsdatei an. Das ist dieselbe Form wie
`60-srvpanel.cnf` in P5, nur muss diese Stufe den Include-Punkt nicht selbst
schaffen. **Neustart, nicht Reload** — der Wert hat Kontext `postmaster` (M20),
genau wie `bind-address`.

**`pg_hba.conf` ist die neue Hälfte**, denn dort und nur dort steht in
PostgreSQL, von wo eine Rolle kommen darf. Ein verwalteter Block:

```
# BEGIN srvpanel — verwaltet, nicht von Hand ändern
host    p1001_shop   p1001_web   203.0.113.5/32     scram-sha-256
host    p1001_shop   p1001_web   198.51.100.0/24    scram-sha-256
# END srvpanel
```

**Die Zeile nennt die Datenbank und nicht `all`** (M23). Das ist eine zweite
Wand hinter dem `REVOKE CONNECT` aus §10, und sie kostet eine Zeile je
Datenbank × Rolle × Netz — bei hundert Abonnements mit je zwei Datenbanken und
einem Netz rund zweihundert. Die Reihenfolge ist gemessen stabil.

### 14.2 Die Landmine, und warum der Ablauf nicht verhandelbar ist

| | |
|---|---|
| kaputte Datei + **Reload** | Server bedient weiter, alte Regeln bleiben, `pg_hba_file_rules` nennt den Fehler mit Zeilennummer (M16) |
| kaputte Datei + **Neustart** | **`FATAL: could not load pg_hba.conf` — der Cluster kommt nicht hoch** (M17) |

**Eine falsche Zeile ist beim Schreiben unsichtbar und wochenlang folgenlos.**
Sie zündet beim nächsten Neustart, bei einem Paketupdate oder einem Reboot —
alle Kunden ohne Datenbank, und die Ursache ist eine Datei, die vor einem Monat
geschrieben wurde. Das ist die teuerste Bauart von Fehler, die dieses Projekt
kennt: einer, der zwischen Ursache und Wirkung eine Wartungsfrist legt.

Deshalb läuft `pg.remote.access` in fünf Schritten, und der vierte ist keine
Meldung, sondern ein Rückweg:

1. Die vorhandene Datei beiseitelegen.
2. Den Block schreiben — **additiv**, alles ausserhalb der Marken bleibt Byte
   für Byte stehen.
3. `SELECT pg_reload_conf()`.
4. **`SELECT … FROM pg_hba_file_rules WHERE error IS NOT NULL` lesen.** Steht
   dort etwas: die beiseitegelegte Datei zurückschreiben, noch einmal reloaden,
   und den Vorgang mit der Fehlermeldung samt Zeilennummer scheitern lassen.
5. Erst danach, und nur wenn `listen_addresses` sich ändert, der Neustart.

**Zurückrollen und nicht melden.** Eine Operation, die eine kaputte Datei
liegenlässt und darüber berichtet, hat den Server scharf gemacht und ein
Protokoll geschrieben. `PgHbaRollbackTest` besteht darauf (§18).

Dass Schritt 4 überhaupt möglich ist, ist Glück und gehört benannt: Der Reload
ist gnädig, wo der Neustart es nicht ist. Es ist die Umkehrung von Lehre 1 aus
`docs/37 §6` — hier ist der gelesene Zustand nicht nur ehrlicher als die
geschriebene Zeile, er ist die einzige Gelegenheit, den Fehler zu sehen, bevor
er wirkt.

### 14.3 Wo P5b nicht gleich ist, obwohl die Fläche gleich aussieht

**Ein Fernzugang hat kein eigenes Passwort.** In MariaDB sind
`'p1001_web'@'localhost'` und `'p1001_web'@'203.0.113.5'` zwei Benutzer mit zwei
Passwörtern; wer das eine verliert, verliert nicht das andere. In PostgreSQL ist
es **eine Rolle, ein Passwort, mehrere erlaubte Netze**. Das ist kein Baufehler,
sondern die Bauart des Systems — und es gehört auf die Seite, weil ein Kunde,
der P5 kennt, das Gegenteil annimmt.

**Damit bricht `db_users.host`** (§9): Zwei Zeilen mit demselben Namen wären
nicht zwei Zugänge, sondern zwei Regeln für einen — und `pg.role.create` liefe
zweimal. Die Netze eines Zugangs stehen deshalb in einer eigenen Tabelle.

### 14.4 Der Weg zurück

Eine Zeile für eine Rolle, die es nicht mehr gibt, ist für PostgreSQL **kein
Fehler** (M22) — sie bleibt liegen, und nichts meldet es. `pg_hba.conf` ist
damit das Vierte, was diese Stufe auf der Platte hinterlässt, neben Datenbanken,
Rollen und Sicherungen.

- `pg.role.remove` nimmt die Zeilen der Rolle mit, im selben Vorgang.
- `srvpanel db` gleicht den Block gegen den Bestand ab und meldet, was
  übrigbleibt — **melden und nicht löschen**, wie `docs/36 §5` es festlegt.
- `PgHbaReachTest` hält beide Richtungen (§18).

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
agent/src/Runner.php            (drei Programme auf die Positivliste, §6.3)
agent/src/Ops/PgServerInfo.php
agent/src/Ops/PgDatabaseRemove.php   ← vor …
agent/src/Ops/PgDatabaseCreate.php   ← … dieser Zeile geschrieben
agent/src/Ops/PgRoleRemove.php
agent/src/Ops/PgRoleCreate.php
agent/src/Ops/PgRoleGrant.php
agent/src/Ops/PgRoleLock.php
tests/Unit/PgNameTest.php  PgShieldingTest.php  AgentIdentityTest.php
```

> **`agent/src/Registry.php` stand hier und gehört nicht hierher.** Der erste
> Anlauf hat `pg.server.info` in diesem Schritt eingetragen, und die CI war rot:
> `AgentOperationReachTest::test_every_operation_without_a_lifecycle_is_called_somewhere`
> verlangt zu jeder Operation ohne Lebenslauf einen **Aufrufer** und nicht nur
> eine Begründung — *„Code, der als root läuft und zu dem kein Weg führt, ist
> Angriffsfläche ohne Nutzen."*
>
> Die Regel ist älter als dieser Plan und wiegt schwerer als seine Dateiliste.
> **Eine Operation wird in demselben Beitrag eingetragen, der ihr einen Aufrufer
> gibt** — also in Schritt 3 für `pg.server.install` und in Schritt 4 für den
> Rest. Bis dahin liegen die Klassen da und sind aus dem Agenten nicht
> erreichbar, und das ist der richtige Zustand: Was niemand rufen kann, ist auch
> keine Fläche.

**Hier prüfbar, und diesmal richtig:** `agent/src/autoload.php` lädt ohne
Framework, und in diesem Container läuft ein PostgreSQL 16. Ein Wegwerfskript im
Scratchpad kann `Pg\Session` gegen einen echten Server fahren — das ist mehr,
als P5 an dieser Stelle hatte, und es gehört genutzt, bevor eine CI-Runde dafür
draufgeht.

### Schritt 2 — Die Migration und die Modelle

§9 als Code, `engine` in die drei Modelle, `db_prefix` in `SystemUser`, und
`Lifecycle::claim()` vergibt es mit.

### Schritt 3 — PostgreSQL kommt auf den Server (§7)

`PgServerInstall`, der Betreiberschalter, ~~die zwei Unitlisten~~ **die eine
Unitliste** (§24.1), und `nfpm.yaml` — die Zeile `suggests: postgresql` bekommt
ihre Begründung oder verschwindet.

> **Und der Knopf, der die Operation auslöst — in demselben Beitrag.** Das ist
> die Regel aus Lauf 446, hier zum ersten Mal angewandt: `pg.server.info` und
> `pg.server.install` werden mit dem Aufrufer eingetragen und nicht davor.
> Konkret heisst das für diesen Schritt vier Stellen: die Registratur,
> `Task::PostgresInstall` (der Knopf schickt eine Aufgabe, keinen Namen),
> `DatabaseSettingsController::postgres()` (der unmittelbare Aufruf) und
> `Settings/Database.vue`.
>
> **Welche Zustände der Knopf zeigt, entscheidet der Agent** —
> `PgServerInstall::ACTIONABLE`. Ein `no_cluster` dort wäre ein Knopf, dessen
> einzige Wirkung eine Fehlermeldung ist; ein `ready` dort wäre einer, der
> „installieren" heisst und nichts tut.

### Schritt 4 — Die Anwendung

`app/Support/Databases/` erweitert statt verdoppelt: `Databases::create()`
verzweigt an genau einer Stelle auf `engine` und schickt `db.*` oder `pg.*`.
`PgLifecycle` neben `DbLifecycle`.

### Schritt 5 — Sperre und Messung

§11 und §12, dazu `srvpanel usage`.

### Schritt 6 — Sichern und Zurückspielen (§13)

`ON_ERROR_STOP=1` **zuerst**, dann alles andere.

### Schritt 6b — Kunden-PHP kann PostgreSQL ansprechen (§24.2)

Nicht im ursprünglichen Plan; eingeschoben, weil der Fund aus Schritt 3 sonst
die Abnahme überlebt hätte. `pgsql` in `PhpVersions::EXTENSIONS`,
`php.version.install` läuft auf den Paketsatz zu statt auf den Handler,
`dpkg-query` auf der Positivliste, „Ergänzen" in „Einstellungen →
PHP-Versionen", `EngineExtensionTest` und `PhpExtensionTest`.

**Vor Schritt 7 und nicht danach:** Der Abnahmelauf prüft mit `psql`, also
würde er die Lücke nicht bemerken.

### Schritt 7 — Die Oberfläche und die Screenshots (§16)

Und die sechs Textstellen aus §12.

> **Und dazwischen ein Lauf auf einem echten Server: `39-zwischenabnahme-p5b.md`.**
> Nicht die Abnahme — die ist §19 und kommt in Schritt 9. Neun Punkte gegen
> `v0.5.1-rc.2` mit der Frage, ob die Schritte 4 bis 7 überhaupt tragen: Sie
> sind gegen einen Wegwerf-Cluster im Container gemessen und auf einem Server
> mit systemd, Agent und Panel nie im Ganzen gelaufen.
>
> Er steht als eigenes Dokument, weil er zuerst nur in einem Sitzungsverlauf
> stand — und Punkt 0 enthielt zwei falsche Befehle, an denen der Betreiber
> hängengeblieben ist. Ein Verlauf lässt sich nicht berichtigen und `DocLinkTest`
> sieht ihn nicht.

### Schritt 8 — Die Wächter brechen

`tests/waechter-brechen.sh`, geprüft von `BreakScriptTest`.

### Schritt 9 — Der Abnahmelauf (§19), ohne Fernzugriff

Auf `cloudsrv24`, mit zwei Abonnements. **Vorher §2.3 messen.**

### Schritt 10 — Fernzugriff (§14)

**Ans Ende, und zwar aus demselben Grund wie in `docs/36 §19` Entscheidung 5:**
Ohne ihn ist die Stufe abnehmbar, mit ihm horcht ein Dienst auf einer
erreichbaren Adresse. Das gehört nicht in denselben Beitrag wie das Anlegen der
ersten Datenbank.

```
agent/src/Pg/Hba.php
agent/src/Ops/PgRemoteAccess.php
database/migrations/…_create_db_user_networks_table.php
app/Console/Commands/Databases.php   (--remote=on für PostgreSQL)
resources/js/Pages/Databases/Show.vue
tests/Unit/PgHbaRollbackTest.php  tests/Feature/PgHbaReachTest.php
```

**`Pg\Hba` ist hier fahrbar**, und zwar vollständig: Ein Wegwerf-Cluster im
Scratchpad nimmt eine kaputte Zeile entgegen, und ob der Rückweg greift, lässt
sich sehen statt behaupten. Das ist der Schritt, bei dem sich die PostgreSQL im
Container am meisten auszahlt.

---

## 18. Wächter und ihre Brüche

| Wächter | Die Regel | Der Bruch |
|---|---|---|
| `PgNameTest` | Ein Präfix ist nichtssagend und wird nie zweimal vergeben | Präfix aus der Abonnementnummer bilden |
| `PgShieldingTest` | Jede neu angelegte Datenbank wird abgesperrt, **und die Sichtenliste wird erfragt** | Die Liste als Konstante verdrahten |
| `PgRestoreTest` | Der Aufruf von `psql` trägt `ON_ERROR_STOP=1` — geprüft am erzeugten Aufruf, nicht an einer Konstanten | Den Schalter entfernen |
| `AgentIdentityTest` | Der Agent wechselt seine Kennung nicht: kein `runuser`, `su`, `setpriv` oder `sudo` auf der Positivliste — und `psql` wird nur aus `Pg\Session` gerufen | `runuser` auf die Positivliste setzen |
| `UnitReachTest` | Jeder Unitname, den eine Operation nennt, kommt durch `ServiceAction::allowed()` | `postgresql` aus `ALLOWED_UNITS` nehmen |
| `DocLinkTest` | Jeder Verweis in `docs/` zeigt auf eine Datei, die es gibt | Eine Zieldatei umbenennen |
| `PackagingTest` (erweitert) | Jede Zeile unter `depends`/`recommends`/`suggests` hat eine Begründung oder eine Stelle im Code, die sie einlöst | Eine Zeile ohne beides einfügen — der heutige Zustand |
| `EngineReachTest` | Zu jeder `db.*`-Operation mit einem Gegenstück gibt es `pg.*`, oder ein begründeter Eintrag sagt warum nicht | Eine `pg.*`-Operation entfernen |
| `PgHbaRollbackTest` | Eine ungültige Zeile führt zur **alten Datei** zurück, nicht zu einer Meldung — geprüft gegen einen echten Cluster, nicht am Quelltext | Den Rückweg durch ein `log()` ersetzen |
| `PgHbaReachTest` | Jede Zeile im verwalteten Block zeigt auf eine Rolle, die es gibt — **und jede Rolle mit Netzen hat ihre Zeilen** | Eine Rolle entfernen und ihre Zeilen stehenlassen |

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
#    srvpanel db --postgresql=on, dann Einstellungen → Datenbankserver.
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

# 10 FERNZUGRIFF   ← Schritt 10, nach der Abnahme der übrigen neun
#    (a) Solange nicht freigeschaltet, zeigt das Panel das Feld NICHT — mit dem
#        Grund daneben, nicht ausgeblendet (AbilityReachTest).
#    (b) srvpanel db --postgres --remote=on
#        erwartet: /etc/postgresql/<fassung>/main/conf.d/60-srvpanel.conf
#                  existiert, die Distributionsdateien sind UNVERÄNDERT:
#          md5sum vorher/nachher von postgresql.conf   → gleich
#        BELEG: SHOW listen_addresses; NACH dem Neustart — zurückgelesen und
#               nicht geschrieben. „Datei geschrieben" ist eine Absicht
#               (docs/37 §6, Lehre 1; in P5 genau hier gefunden, §22.3w A5).
#    (c) Für <A>_web ein Netz eintragen. Dann:
#          SELECT rule_number, database, user_name, address, error
#            FROM pg_hba_file_rules WHERE error IS NOT NULL;
#        erwartet: 0 Zeilen. UND die eigene Zeile taucht mit ihrer Datenbank
#        auf — nicht mit `all`.
#    (d) DIE PROBE, DIE DAS EIGENTLICHE RISIKO MISST — von Hand eine ungültige
#        Zeile in den verwalteten Block schreiben und pg.remote.access erneut
#        laufen lassen, ODER die Operation mit einem ungültigen Netz rufen.
#        erwartet: der Vorgang SCHEITERT, die Fehlermeldung nennt die
#        Zeilennummer, UND die Datei steht wieder wie vorher:
#          md5sum pg_hba.conf   → wie vor dem Lauf
#        DANN, UND DAS IST DER EIGENTLICHE BELEG:
#          systemctl restart postgresql && systemctl is-active postgresql
#        erwartet: aktiv. OHNE DIESEN NEUSTART IST (d) NICHT GEFAHREN — eine
#        kaputte pg_hba.conf ist im laufenden Betrieb unsichtbar (M16) und
#        verhindert erst den nächsten Start (M17). Wer nur prüft, dass der
#        Server noch antwortet, prüft genau das, was auch im Fehlerfall gilt.
#    (e) %/0.0.0.0/0 als Netz → abgewiesen, mit Meldung.
#    (f) Und der Weg zurück: den Zugang löschen, dann
#          grep -c "<A>_web" /etc/postgresql/*/main/pg_hba.conf   → 0
#          srvpanel db                              → „Nichts liegengeblieben."
#        Eine Zeile für eine gelöschte Rolle ist für PostgreSQL kein Fehler
#        (M22) — sie fällt nur auf, wenn jemand danach fragt.
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
3. **Der Agent meldet sich als Rolle `root` an** (§6) — nicht über einen
   Kennungswechsel im `Runner`, wie dieser Plan zuerst vorsah. Die Rolle legt
   der Betreiber einmal an; das Panel zeigt den Befehl und bietet PostgreSQL
   bis dahin nicht an. Nachgetragen, nachdem die Messung aus §2.2b die erste
   Fassung umgeworfen hat: `proc_open` kennt keinen Kennungswechsel, und der
   Weg über `pcntl_fork` ist gemessen unzuverlässig.
4. **Ein Datenmodell, eine Fläche, zwei Sätze Agent-Operationen** (§8).
5. **PostgreSQL wird erkannt *und* auf Verlangen installiert** (§7). Ein
   vorhandener Cluster ist Bestand und wird benutzt; Kunden verbinden sich über
   `127.0.0.1`.

   > **Berichtigt am 9. August 2026.** Hier stand „`pg_hba.conf` bleibt
   > unangetastet". Das hält Entscheidung 9 nicht mehr, und es hätte auch §14
   > nie gehalten — der Fernzugriff schreibt ohnehin in diese Datei. Der Satz
   > war als Zusage über den *Bestand* gemeint (nichts Vorhandenes wird
   > geändert) und las sich als Zusage über die *Datei*. Beides steht jetzt
   > getrennt da: Vorhandene Zeilen werden nicht angefasst, ergänzt wird oben
   > und mit einer Marke.

6. **Der Fernzugriff wird gebaut** (§14) — nachgetragen am selben Tag, nachdem
   die Messung aus §2.2a die Empfehlung dieses Plans umgeworfen hatte.

   Der Ablauf gehört zur Entscheidung, weil er zeigt, wozu die Regel „gemessen,
   nicht geschätzt" da ist: Vorgelegt worden war *„P5b baut keinen Fernzugriff"*,
   mit der Begründung, `pg_hba.conf` kenne einen Include-Punkt erst ab PG 16.
   Auf die Frage des Betreibers, was sich änderte, wenn er doch gebaut würde,
   ist nachgemessen worden — und dabei fiel auf, dass ein verwalteter Block
   **keinen** Include-Punkt braucht. Die Begründung war eine wahre Aussage über
   etwas, das die Aufgabe nicht verlangt.

   **Damit wird `pg_hba.conf` doch angefasst**, und Entscheidung 5 ist insoweit
   enger gefasst: Das Panel schreibt in einen verwalteten Block zwischen
   Marken, additiv, und lässt alles ausserhalb Byte für Byte stehen. Was es
   nicht tut, ist die Datei erzeugen oder Zeilen des Betreibers ändern.

9. **Ein vorhandener Cluster wird benutzt, nicht umgebaut — und bei mehreren
   laufenden wählt das Panel nicht.** Gefragt wird `pg_lsclusters`, bevor
   irgendetwas verbunden wird; ohne dieses Werkzeug lässt sich „installiert,
   aber gestoppt" von „gar nicht installiert" nicht unterscheiden (§2.2d).
   Ein gestoppter Cluster wird gestartet — derselbe Handgriff, den `apt` beim
   Installieren macht und den das Panel für `mariadb.service` seit P5 tut. Zwei
   *laufende* sind dagegen der eine Fall, in dem Raten Kundendaten kostet:
   Sie heissen fast immer, dass der Betreiber einen davon selbst betreibt.
   **Gezählt werden die laufenden und nicht alle** — dieselbe feinere Fassung,
   die `docs/20 §15` Punkt 4 für nginx festhält.

   Die Positivliste wächst dafür um `pg_lsclusters` und `pg_ctlcluster`. Beide
   sind Debian-Werkzeuge, die genau eine Sache tun; das erste ist zugleich der
   Fühler dafür, ob PostgreSQL überhaupt installiert ist.

10. **Installiert wird über einen Knopf im Panel**, nicht über
    `srvpanel db --postgresql=on`. Der Schalter schaltet die Fläche frei, die
    Installation ist eine eigene Handlung mit eigenem Vorgang.

**Nicht vorgelegt, weil entscheidbar — und deshalb hier zum Widerspruch:**

7. **Die Kontingente gelten für beide Systeme zusammen** (§12).
8. **Die `pg_hba`-Zeile nennt die Datenbank und nicht `all`** (§14.1). Das ist
   enger als P5 es kann und kostet Zeilen; wer die Datei lieber kurz hätte,
   muss es sagen.

**Nachgetragen am 9. August 2026, beide beim Bauen von Schritt 6 vorgelegt:**

9. **Die befristete Rolle meldet sich über den Socket an, mit einer
   Gruppenrolle und einer Zeile in `pg_hba.conf`** (§13.4). Der Anlass ist eine
   Messung, die §13.4 umgeworfen hat: Debians `pg_hba.conf` beginnt mit
   `local all all peer`, und `peer` verlangt einen gleichnamigen
   Unix-Benutzer — den hat `x7f3a…_r1a2b3c4d` nicht. In P5 trägt MariaDB das,
   und §13.4 sagte „wie `docs/36 §10.2`".

   Der andere Weg war gemessen und vorgelegt: TCP über `127.0.0.1` läuft ohne
   jede Änderung an der Konfiguration, hängt dafür an `listen_addresses` — ein
   Betreiber, der PostgreSQL auf den Socket beschränkt, verlöre das
   Zurückspielen lautlos und erst dann, wenn er es braucht.

   Die Zeile steht **ganz oben**, weil die erste passende entscheidet, auch
   wenn sie abweist. Vorhandene Zeilen werden nicht verändert.

10. **Was mit einer Sicherung geschieht, bekommt einen eigenen Lebenslauf**
    (`DumpLifecycle`, Schritt 6, zweite Hälfte). Die vier Dump-Aufgaben ziehen
    aus `DbLifecycle` dorthin um und gelten für beide Systeme; `PgLifecycle`
    und `DbLifecycle` behalten Rückbau und Sperre.

    Der Grund ist derselbe wie bei `Dump::requireGzip()` und den drei anderen
    Helfern, die in Schritt 6 aus `DbDumpImport` nach `Dump` gezogen sind: Eine
    Sicherung ist eine Datei und eine Zeile, und was mit ihr geschieht — Bytes
    aus der Antwort, `Ready` oder `Failed`, beim Entfernen löschen, beim
    Zurückspielen nichts — hängt nicht am Datenbanksystem. Nur die vier
    Aufgabennamen tun das.

    Die beiden Alternativen sind vorgelegt und verworfen worden: dieselbe Logik
    ein zweites Mal in `PgLifecycle` wäre die zweite Fassung, die veraltet; sie
    in `DbLifecycle` für beide Systeme laufen zu lassen hiesse, dass der Name
    lügt und die `engine`-Einschränkungen darin eine unsichtbare Ausnahme
    bekommen. **Eine Klasse je Gegenstand, nicht je System.**

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
5a. **Eine kaputte `pg_hba.conf` verhindert den nächsten Start, nicht den
   laufenden Betrieb** (M17). Der Rückweg aus §14.2 nimmt das Risiko, aber er
   nimmt es nicht weg: Wer die Datei von Hand ändert — und sie ist eine Datei,
   die Betreiber von Hand ändern —, bekommt dieselbe Bombe ohne unseren
   Rückweg. `srvpanel db` liest deshalb `pg_hba_file_rules` bei **jedem** Lauf
   mit und meldet Fehler, auch solche, die nicht von uns stammen.
5b. **Ein Fernzugang hat kein eigenes Passwort** (§14.3). Wer die Zugangsdaten
   eines PostgreSQL-Zugangs verliert, verliert ihn für jedes erlaubte Netz.
   Bei MariaDB ist das anders, und die Oberfläche muss es sagen.
6. **Der Abnahmelauf verbraucht zwei Systembenutzer**, endgültig (`docs/35`).
7. **`waechter-brechen.sh` bleibt zur Hälfte ungeprüft.** `docs/36 §20` Punkt 1
   gilt unverändert; P5b legt acht weitere Brüche darauf. Der Rotbeweis braucht
   ein lokales `vendor/`.

---

## 23. Umfang

| Bereich | neue Dateien | geänderte |
|---|---|---|
| `agent/` | 8 Bausteine, 16 Operationen | `Registry.php`, `Runner.php`, `ServiceAction.php` |
| `app/` | 1 Dienst, 1 Lebenslauf, 1 Modell | `Databases`, `Dumps`, `Usage`, `Quota`, `SubscriptionController`, `SystemUser`, `Lifecycle` |
| `database/` | 2 Migrationen | — |
| `resources/` | — | 4 Seiten |
| `tests/` | 11 Wächter | `waechter-brechen.sh`, `RemovalPathTest`, `AgentOperationReachTest`, `PackagingTest` |
| `packaging/` | — | `nfpm.yaml`, `bin/srvpanel` |
| `docs/` | dieses | `20 §9 P5b`, `20 §15`, `23`, `CHANGELOG`, `CLAUDE.md` |

Geschätzt zwei bis drei Wochen, dieselbe Grössenordnung wie `docs/20 §9` sie
nennt. **Der Fernzugriff ist enthalten** (§14) und steht als Schritt 10 am Ende;
er kostet gegenüber der ersten Fassung dieses Plans rund eine halbe Woche, weil
`pg.remote.access` sich weitgehend an `db.remote.access` entlangschreiben lässt
— was neu ist, ist `Pg\Hba` und sein Rückweg.

---

## 24. Umsetzung — was beim Bauen anders war als im Plan

*Die Antwort auf die Frage aus §8 — musste `agent/src/Db/` doch aufgerissen
werden? — steht am Ende dieses Abschnitts, wenn es sie gibt. Bis Schritt 3 ist
keine Datei darunter angefasst worden.*

### 24.1 Der Eintrag in `ServiceAction::ALLOWED_UNITS` entfällt (Schritt 3)

Steht in §7 durchgestrichen samt Grund. Kurz: Die Begründung des Plans war,
der Agent könne den Dienst sonst nicht starten — er startet ihn mit
`pg_ctlcluster` und lädt mit `SELECT pg_reload_conf()`. Ein Allowlist-Eintrag,
den niemand ruft, ist genau das, was `AgentOperationReachTest` verbietet.

### 24.2 Kunden-PHP kann PostgreSQL nicht ansprechen (gefunden in Schritt 3, behoben davor)

> **Erledigt am 9. August 2026**, auf Entscheidung des Betreibers innerhalb von
> P5b und vor Schritt 7. Was gebaut wurde, steht unter dem ursprünglichen Befund.

**`PhpVersions::EXTENSIONS` kennt `mysql` und nicht `pgsql`.** Ein Kunde, der
in diesem Panel eine PostgreSQL-Datenbank anlegt, bekommt sie — und seine
Website kann sich nicht damit verbinden, weil `phpX.Y-pgsql` auf dem Server
nicht installiert ist. Das steht in diesem Plan nirgends; gefunden beim
Durchsehen der Paketbeziehungen für §7.

Es ist **kein** Einzeiler, und deshalb steht es hier statt im Code:

1. `EXTENSIONS` zu erweitern ändert, was `php.version.install` künftig holt.
2. **Bestehende Installationen bekommen es nicht nach.**
   `PhpVersionInstall::execute()` kehrt bei einer installierten Version früh
   zurück (`already => true`) — die neue Erweiterung käme auf keinem Server an,
   auf dem PHP schon liegt. Das ist dieselbe Sorte Lücke wie die aus `docs/35`:
   Etwas ist vorgesehen, und der Weg dorthin fehlt.
3. Damit hängt die Frage dran, ob `php.version.install` eine fehlende
   Erweiterung nachinstallieren soll — eine Änderung an P3 und nicht an P5b.

Gehört vor Schritt 7 entschieden, spätestens vor der Abnahme: Ohne sie ist
„der Kunde legt eine PostgreSQL-Datenbank an" ein Angebot, das an der ersten
Verbindung scheitert.

#### Was daraus geworden ist

**Der Kern war nicht die fehlende Zeile, sondern eine Prüfung, die einen
Stellvertreter gefragt hat.** `php.version.install` hielt eine Version für
vollständig, sobald `/usr/sbin/php-fpm8.2` dalag — geprüft wurde der Handler,
gemeint war der Paketsatz. Solange `EXTENSIONS` sich nie ändert, sind die beiden
dasselbe. Die Operation läuft jetzt auf den gewünschten Satz **zu**: Sie fragt,
was fehlt, installiert nur das, und liest denselben Satz danach zurück.

**Gefragt wird `dpkg-query` und nicht `php-fpm -m`.** Gemessen in diesem
Container: `php8.2-mysql` bringt die Module `mysqli`, `mysqlnd` und `pdo_mysql`
mit und **kein** Modul namens `mysql`. Eine Zuordnung Paket → Merkmalsmodul wäre
eine zweite Liste, die nichts prüft; die Frage ist eine Paketfrage, also
antwortet das Paketsystem. Dasselbe gilt für `mods-available/*.ini`, wo dieselbe
Lücke steht.

**Der Rückgabewert von `dpkg-query` bleibt ungelesen, mit Begründung im Code:**
Er ist 1, sobald eines der genannten Pakete unbekannt ist — also genau dann,
wenn die Frage etwas zu melden hat. Die bekannten Namen stehen vollständig auf
`stdout`, die unbekannten auf `stderr`. Wer den Code als Fehlschlag liest,
bekommt eine Operation, die immer dann abbricht, wenn sie etwas zu tun hätte.

**Und ein Punkt, den der Plan nicht hatte: der Neustart.** Ein laufender FPM
lädt eine frisch installierte Erweiterung nicht von selbst. Das `postinst` der
Distribution ruft `phpenmod` und stösst über einen dpkg-Trigger einen Neustart
an — *meistens*, und „meistens" ist hier kein Zustand: Bleibt er aus, steht
`pgsql` auf der Platte, im Panel steht „vollständig", und die Website antwortet
weiter *„could not find driver"*. `php.version.install` startet die Unit deshalb
ausdrücklich neu, **wenn sie läuft**, und meldet es als `restarted`. Das kostet
die Anfragen, die in diesem Moment unterwegs sind; die Alternative kostet jede
Anfrage danach. Die Rückfrage im Panel sagt es vorher.

**Der Wächter, um den es eigentlich geht, sitzt an der Aufzählung.**
`DatabaseEngine::phpExtension()` nennt je System den Paketsuffix, und
`EngineExtensionTest` hält ihn gegen `PhpVersions::EXTENSIONS`. Dieser Test wäre
an dem Tag rot geworden, an dem die Aufzählung entstand — also drei Beiträge vor
dem Fund — und er beisst wieder, sobald ein drittes System dazukommt. Dazu
`PhpExtensionTest` über die Auswertung der dpkg-Ausgabe, mit echten Zeilen und
den Zuständen `config-files` und `half-installed`, die *nicht* als installiert
zählen dürfen.

**Was der Betreiber sieht:** `php.versions` meldet je installierter Version, was
fehlt; „Einstellungen → PHP-Versionen" zeigt es neben dem Zustand und bietet
**Ergänzen** an — dieselbe Aufgabe wie „Installieren", weil es dieselbe Regel
ist. Nicht in `srvpanel update`: Ein Update, das von selbst `apt-get install`
fährt, kann an einer fremden Paketquelle scheitern und nimmt das Panel mit.

#### Was die Messung auf `cloudsrv24` dazu ergeben hat

**Sechs von dreizehn Paketen fehlen, nicht eines.** Am 9. August 2026 gegen die
einzige dort installierte Version, PHP 8.4:

| vorhanden | fehlt |
|---|---|
| `fpm`, `mysql`, `xml`, `mbstring`, `curl`, `opcache`, `readline` | `pgsql`, `gd`, `zip`, `intl`, `bcmath`, `soap` |

Die sieben vorhandenen sind genau die, die `packaging/nfpm.yaml` als
Abhängigkeit des **Panels** nennt, plus was daran hängt. **PHP 8.4 ist also nie
durch `php.version.install` gegangen** — es kam als Abhängigkeit mit, und die
Operation hätte es auch nie ergänzt: `is_executable('/usr/sbin/php-fpm8.4')` war
wahr, also meldete sie `already => true`.

**Damit ist der Befund grösser als PostgreSQL.** Das Panel führt 8.4 als
installiert, bietet sie Kunden an, und eine Website darauf hat kein `gd`, kein
`zip`, kein `intl`, kein `bcmath` und kein `soap` — die fünf, die eine übliche
Anwendung als Erstes verlangt. Das steht seit P3 so auf dem Server, und niemand
hat es gesehen, weil die einzige Stelle, die danach hätte fragen können, einen
Stellvertreter fragte.

**Was daraus *nicht* folgt:** dass eine unvollständige Version aus dem
Auswahlfeld der Domains verschwindet. `PhpSelection::installed()` bleibt bei
„der Handler ist da". Einem Kunden eine funktionierende PHP-Version wegen eines
fehlenden `soap` zu entziehen, wäre die härtere Änderung mit dem grösseren
Schaden; die richtige Ebene ist, dass der Betreiber es **sieht** und ergänzt.
Das ist eine Entscheidung und keine Auslassung.
