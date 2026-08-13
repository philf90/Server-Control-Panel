# 46 — P5c: Datenbankmanagement

**Stand: 12. August 2026.** P0 bis P5b sind abgenommen, ausgeliefert wird
`v0.5.2-rc.7`. Dieses Dokument plant P5c: ein Datenbankmanagement als
Bestandteil des Panels, für MariaDB und PostgreSQL, **reduziert auf das
Notwendige**.

Es steht im Zuschnitt von [36](36-datenbanken.md) und
[38](38-postgresql.md) und hat denselben Aufbau: erst die Messungen, dann die
Entscheidungen, dann das Abnahmekriterium, dann die Schritte.

Der Plan bleibt [20](20-hostingpanel-neuplan.md). Wo dieses Dokument ihm
widerspricht, gilt er.

---

## 1. Der Auftrag

Ein Kunde ohne SSH-Zugang kann heute eine Datenbank anlegen, einen Zugang
vergeben, sichern und zurückspielen — **und nicht hineinsehen.** „Datenbanken"
ist im Panel bisher eine Verwaltung von Namen. P5c macht daraus einen Ort, an
dem er seine Tabellen sieht, eine Zeile liest und eine Zeile berichtigt.

**Zur Vorgeschichte gehört ein Nein.** [36 §13](36-datenbanken.md) hat
Adminer als eingebettetes Werkzeug abgelehnt, und die drei Gründe dort gelten
unverändert: fremder PHP-Code auf dem Panel-Host, eine Sicherheitslücke fremder
Herkunft in unserer apt-Quelle, und eine Anmeldung, die es ohne abgelegtes
Passwort erst zu bauen gilt. Der Abschnitt schliesst mit *„er wird nach P5b
entschieden"*.

**Entschieden ist er jetzt, und zwar in der Sache anders und im Grund gleich:**
Es wird gebaut, nicht eingebunden. phpMyAdmin und Adminer sind der Massstab für
den Umfang, nicht die Quelle. Der dritte Grund — die Anmeldung — ist inzwischen
kein offener Punkt mehr, sondern gebauter und abgenommener Code: Der befristete
Zugang aus [36 §10.2](36-datenbanken.md) und [38 §13.4](38-postgresql.md) läuft
seit P5 unter jedem Zurückspielen.

---

## 2. Die Messung kam vor dem Plan

Wie in P5b. Was hier steht, ist am 12. August 2026 gemessen worden, **bevor eine
Zeile Plan entstand** — und drei der Messungen haben den Entwurf verändert, mit
dem diese Sitzung angefangen hat.

### 2.1 Wo gemessen wurde

Ein Wegwerf-Cluster im Container: `initdb` nach `/tmp/pgc`, Socket `/tmp/pgs`
(kurz, wegen der Grenze von 107 Byte), Port 5599, **PostgreSQL 16.13**. Darin
nachgebaut, was das Panel auf einem echten Server herstellt: zwei Abonnements
mit undurchsichtigem Präfix (`x7f3a91c2`, `xb0c1d2e3`), je eine Datenbank, je
eine Eigentümer- und eine Kundenrolle, die Absperrung aus
[38 §10](38-postgresql.md) wörtlich gefahren (dreizehn Kanäle gefunden, elf
gesperrt, `pg_database` und `pg_hba_file_rules` ausgenommen), und eine
`pg_hba.conf` in Debians Reihenfolge mit der `srvpanel_restore`-Zeile obenauf.

Für **MariaDB gibt es hier keinen Server.** Was dort gilt, steht in §2.3 als
offene Messung und nicht als Annahme.

### 2.2 Was dabei herauskam

| | Messung | Ergebnis |
|---|---|---|
| **M1** | Debians `pg_hba.conf` und `listen_addresses` im Auslieferungszustand | `local all all peer`, `host all all 127.0.0.1/32 scram-sha-256`, `listen_addresses` unkommentiert `'localhost'` |
| **M2** | Kundenrolle über den Unix-Socket | `FATAL: Peer authentication failed` — sie kommt dort **nicht** herein |
| **M3** | Kundenrolle über `127.0.0.1` mit Passwort | geht |
| **M4** | Befristete Rolle, Mitglied in `srvpanel_restore`, über den Socket | geht — und erbt als Mitglied der Kundenrolle genau deren Rechte |
| **M5** | `SET ROLE` als Sandbox | **`RESET ROLE` kommt zurück** — und die zurückgekehrte Rolle steht in jeder fremden Datenbank |
| **M6** | Kosten einer befristeten Rolle je Anfrage | **11,2 ms** mit Anlegen und Entfernen, 6,6 ms ohne |
| **M7** | `psql -A -t -F'\t'` mit `NULL`, `''`, Tabulator, Zeilenumbruch | **`NULL` und `''` beide leer**, ein Tabulator im Wert ergibt eine Spalte mehr, ein Umbruch eine Zeile mehr |
| **M8** | dieselbe Zeile als `row_to_json` | alle vier unterscheidbar, `bytea` als Hex, eine Zeile Ausgabe je Datenzeile |
| **M9** | ein Ergebnis von 200 000 Zeilen, **eine** davon gelesen | **RSS +51 MB** — libpq puffert das ganze Ergebnis |
| **M10** | dasselbe mit `DECLARE`/`FETCH 51` | +0,0 MB |
| **M11** | `statement_timeout`, von aussen gesetzt, vom Rolleninhaber | **`SET statement_timeout = 0` geht** — auch gegen `ALTER ROLE … SET` |
| **M12** | eine Abfrage, deren Klient wegfällt | rechnet weiter (nach 4 s noch `active`) |
| **M13** | Abbruch durch eine zweite befristete Rolle | `ERROR: permission denied for view pg_stat_activity` — die Absperrung schliesst auch den Abbruchweg |
| **M14** | `DECLARE CURSOR FOR <x>` als Unterscheider | nimmt `SELECT`/`VALUES`, weist `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP`, `SHOW` mit Syntaxfehler ab |
| **M15** | `SELECT 1; DROP TABLE x` über PDO, `EMULATE_PREPARES=false` | `cannot insert multiple commands into a prepared statement` — die Tabelle steht noch |
| **M16** | dasselbe mit `EMULATE_PREPARES=true` | **durchgelassen, die Tabelle ist fort** (Vorgabe von `pdo_pgsql` ist `false`) |
| **M17** | `BEGIN READ ONLY` als Schranke | **keine** — `SET TRANSACTION READ WRITE` von innen geht durch, danach `INSERT` und `CREATE TABLE` |
| **M18** | Katalogsichten für die Kundenrolle nach der Absperrung | `information_schema.tables`, `.columns`, `.schemata`, `pg_class` und `pg_database_size(current_database())` bleiben lesbar |
| **M19** | Zeilenschätzung aus `pg_class.reltuples` | für nie analysierte Tabellen **`-1`**, nicht `0` |
| **M20** | eine Seite von 50 Zeilen à 200 Zeichen als JSON | 11 191 Byte |
| **M21** | eine **einzelne** Zelle mit 3 MB als JSON | 3 000 012 Byte — die Anfragegrenze des Agenten liegt bei 1 MiB |
| **M22** | dieselbe Zelle mit `left(…, 512)` | 524 Byte |
| **M23** | `''`-Verdoppelung gegen `\'); DROP TABLE …` | hielt bei `standard_conforming_strings` **on und off**; bei `off` meldet der Server eine Warnung |

**Vier davon haben den Entwurf umgeworfen, mit dem diese Sitzung anfing.**

**M7 hat die naheliegende Bauform erledigt.** `Session::query()` — die einzige
Stelle, an der der Agent heute ein Ergebnis zurückgibt — liefert Zeilen als
Text mit Tabulatoren dazwischen. Für Katalogfragen ist das richtig: Ein
Datenbankname enthält keinen Tabulator. Für **Daten** trägt es nicht, und zwar
nicht ungenau, sondern gar nicht: Ein Wert mit einem Tabulator erzeugt eine
Spalte, die es nicht gibt, und einer mit einem Zeilenumbruch eine Zeile, die es
nicht gibt.

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**

**M5 hat die billigste Anmeldung erledigt.** Eine dauerhafte Konsolenrolle, die
Mitglied jeder Kundenrolle ist und sich je Sitzung per `SET ROLE` in die
richtige stellt, wäre der Weg ohne jede Rollenerzeugung. Sie ist **keine
Schranke:** `RESET ROLE` kommt zurück, und die zurückgekehrte Rolle ist Mitglied
aller anderen. Derselbe Satz gilt für `BEGIN READ ONLY` (M17).

> **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke, sondern eine
> Voreinstellung.** Das ist die Lehre aus [38 §5](38-postgresql.md) — dort war
> es `GRANT CONNECT … TO PUBLIC` — an einer neuen Stelle.

**M6 hat die Frage nach der Ablage erledigt.** Ein befristeter Zugang je
Anfrage kostet gemessen 4,6 ms mehr als eine blosse Verbindung. Damit muss
zwischen zwei Anfragen **kein Geheimnis irgendwo liegen** — die Frage „wo liegt
das Passwort der Konsolensitzung" stellt sich nicht.

**M9 hat die Seitengrösse zu einer Zahl mit Grund gemacht.** Ein Ergebnis wird
vollständig geholt, ganz gleich wie viel davon jemand liest.

### 2.3 Die fünf für MariaDB — hier stand „nicht zu messen", und das war falsch

**Hier stand, diese fünf gehörten auf `cloudsrv24` gemessen, weil es in diesem
Container keinen MariaDB-Server gibt.** Das ist am 12. August 2026 nachgeprüft
worden, und der Satz stimmte nur zur Hälfte: MariaDB ist hier **nicht
installiert** und war die ganze Zeit **installierbar** —
`mariadb-server 1:10.11.14-0ubuntu0.24.04.1` liegt im Ubuntu-Archiv, dieselbe
Fassung, die auf `cloudsrv24` läuft. Was der Proxy sperrt, ist `composer install`
und zwei PPAs, nicht das Archiv der Distribution.

Ein Wegwerf-Server ist damit derselbe Handgriff wie für PostgreSQL:
`mariadb-install-db` in den Scratchpad, `mariadbd --skip-networking` auf einem
eigenen Socket, kein systemd nötig. **Schritt 0 ist damit hier gefahren und
nicht auf dem Zielserver**, gegen 10.11.14 statt gegen eine Annahme.

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite braucht
> einen Versuch.** `CLAUDE.md` hat den ersten geführt und den zweiten gemeint;
> gekostet hat das nichts, weil jemand nachgesehen hat, bevor er es als Blockade
> gemeldet hat.

| | Messung | Ergebnis |
|---|---|---|
| **N1** | `mysql --batch` über eine JSON-Zeile — die heutigen Argumente von `Db\Session` | **Die Maskierung wird ein zweites Mal maskiert:** `"a\tb"` kommt als `"a\\tb"`, `"c\\d"` als `"c\\\\d"` |
| **N2** | dasselbe mit `--raw --batch` | unverändert: `"a\tb"`, `"z1\nz2"`, `"c\\d"` |
| **N3** | `JSON_OBJECT()` über ein `BLOB` mit ungültigem UTF-8 | rohe Bytes `0xFF 0x80` **mitten in der JSON-Zeichenkette** |
| **N4** | `JSON_VALID()` auf ebendiese Ausgabe | **`1`** — MariaDB hält sie für gültig |
| **N5** | `json_decode()` in PHP auf dieselbe Ausgabe | **`null`**, „Malformed UTF-8 characters" — und damit **die ganze Zeile**, nicht die eine Zelle |
| **N6** | dasselbe über `HEX(binaer)` | geht, und `OCTET_LENGTH` liefert die Länge daneben |
| **N7** | `max_statement_time` | greift: `ERROR 1969 … max_statement_time exceeded`, Rückgabewert 1 |
| **N8** | ob der Benutzer ihn zurücknehmen kann | ja, `SET max_statement_time=0` — wie M11, und aus demselben Grund ohne Belang (§9) |
| **N9** | `TABLE_ROWS` bei InnoDB | Schätzung: **197 076** bei 200 000 echten Zeilen (−1,5 %); **kein** Wert für „unbekannt" wie `reltuples = -1` |
| **N10** | `TABLE_ROWS` bei einer Sicht | **`NULL`** — hier gibt es das „unbekannt" doch, nur woanders |
| **N11** | `information_schema` für den Kundenbenutzer | nach Rechten gefiltert: eigene Tabellen 4, fremde **0**, Primärschlüssel lesbar |
| **N12** | Zugriff auf eine fremde Tabelle | `ERROR 1142 … SELECT command denied` |

**N1 hat den Verdacht bestätigt, und der Fehler wäre nicht aufgefallen.** Die
zweite Maskierung erzeugt kein kaputtes JSON — sie erzeugt **gültiges JSON mit
falschen Werten**. `{"tabulator": "a\\tb"}` liest sich fehlerfrei und ergibt die
vier Zeichen `a`, `\`, `t`, `b` statt `a`, Tabulator, `b`. Ein Parserfehler wäre
harmlos gewesen, weil er auffällt.

> **Eine Maskierung über einer Maskierung ist schlimmer als ein Parserfehler.**

**N3 bis N5 sind der eigentliche Fund dieses Schritts**, und er stand in keiner
der fünf Fragen so scharf: Ein einziges `BLOB` in einer Tabelle macht nicht seine
Zelle unlesbar, sondern **die ganze Seite** — `json_decode` gibt `null` für die
komplette Zeile zurück, und die anderen neunzehn Spalten sind mit fort. Dass
MariaDBs eigenes `JSON_VALID` dabei `1` sagt, ist die gefährliche Hälfte:

> **Eine Gültigkeitsprüfung des einen Systems sagt nichts über den Leser im
> anderen.**

Was daraus folgt, steht in §8 und ist schärfer als vorher: Eine binäre Spalte
darf `JSON_OBJECT()` **gar nicht erst erreichen** — nicht „wird später nicht
angezeigt", sondern gar nicht erst hinein.

**N9 und N10 zusammen sind die MariaDB-Fassung der `-1`-Falle aus M19**, nur an
einer anderen Stelle: Eine Basistabelle liefert immer eine Zahl, auch eine nie
analysierte; eine **Sicht** liefert `NULL`. Wer die Spalte für Basistabellen
prüft und sich dann sicher fühlt, schreibt unter jede Sicht „0 Zeilen".

---

## 3. Die Entscheidungen des Betreibers

Vorgelegt und beantwortet am 12. August 2026, vor dem Plan.

### Entscheidung 1 — Die Abfrage läuft über den Agenten

Zur Wahl standen drei Wege: PDO im Panel mit einem befristeten Zugang je
Anfrage, dasselbe mit einem Zugang je Sitzung, oder alles über den Agenten.

**Gewählt: alles über den Agenten.** Was daraus folgt, steht in §5 — und es ist
weniger, als es klingt, weil Entscheidung 2 die Frage „wie passt beliebiges SQL
zu einer typisierten Operation" gar nicht erst entstehen lässt.

Der Preis der beiden anderen Wege ist damit nicht bezahlt: `php8.4-pgsql` kommt
**nicht** in die Abhängigkeiten des Pakets, und es entsteht keine zweite Stelle,
die sich an einem Datenbankserver anmeldet.

### Entscheidung 2 — Kein freies SQL

Im Umfang sind: **Schemata und Tabellen durchsehen**, **die Struktur einer
Tabelle**, **Zeilen ansehen, filtern, sortieren und blättern**, **eine Zeile
anlegen, ändern und löschen**.

**Nicht im Umfang: ein Eingabefeld für beliebige Anweisungen.**

Das ist die Entscheidung mit den weitesten Folgen, und sie fällt zugunsten der
Architektur: Der Agent bekommt **typisierte Fragen und keine Anweisung**. Damit
gilt die erste Grenze aus `CLAUDE.md` wörtlich und nicht dem Sinne nach — die
Anwendung schickt „Seite 3 der Tabelle `bestellungen`, sortiert nach `id`" und
nicht einen Text, der zu einer Anweisung wird.

Vier Fragen erledigen sich mit: der SQL-Parser (es gibt nichts zu parsen), die
gestapelte Anweisung aus M15/M16, das Zurücknehmen des Zeitlimits aus M11 (der
Kunde kann kein `SET` schicken), und die Unterscheidung „lesend oder
schreibend" aus M14.

**Entscheidung 1 und 2 hängen aneinander, und das gehört aufgeschrieben, bevor
es jemand einzeln aufmacht.** Freies SQL über die Leitung des Agenten liesse
sich nicht auf eine Anweisung begrenzen: `psql` liest von der Standardeingabe,
und M14 hat gemessen, dass ein `SELECT 1; SELECT 2` beide Hälften ausführt — der
`DECLARE`-Rahmen umschliesst nur die erste. Was das verhindert, ist die
erweiterte Anfrage von PDO, und die gibt es nur, wenn die Anwendung selbst
verbindet (M15).

> **Wer später freies SQL will, öffnet damit Entscheidung 1 mit** — es ist keine
> Ergänzung des Umfangs, sondern eine andere Architektur.

### Entscheidung 3 — Nur der Kunde

Die Konsole gehört dem Kunden für seine eigenen Datenbanken. **Der Betreiber
bekommt sie nicht** — auch nicht lesend.

Damit stellt sich die Frage „wer hat in Kundendaten gesehen" nicht, und der
Knopf erscheint für ein Betreiberkonto gar nicht erst. Das ist keine Anzeige-
regel, sondern eine Policy und eine `can`-Ablage; `AbilityReachTest` prüft beide
Richtungen.

Der Betreiber hat weiterhin `srvpanel db` und den Weg über die Kommandozeile
seines Servers. Er verliert nichts, was er hatte — er bekommt nur nicht dazu,
was der Kunde bekommt.

**Und es gibt eine Tür, die deshalb keine Lücke ist.** [20 §6.3](20-hostingpanel-neuplan.md)
kennt „Anmelden als Kunde", gebaut seit P1 (`ImpersonationController`,
`App\Support\Audit\Impersonation`): sichtbares Band in der Oberfläche, kein
stiller Wechsel, **jede Handlung doppelt im Protokoll** — handelnde Person und
Kontext. Wer im Störfall in die Daten eines Kunden sehen muss, geht dort hindurch
und hinterlässt dabei mehr Spur als eine eigene Betreiberkonsole je hätte.

Das ist keine Umgehung von Entscheidung 3, sondern ihr Gegenstück: **Der
Unterschied zwischen einem Weg, den es nicht gibt, und einem, der einen Namen
und ein Protokoll hat, ist der ganze Punkt.** Dass die Konsole unter
Impersonation erreichbar ist und das Protokoll dabei beide Seiten führt, gehört
in Schritt 3 geprüft — nicht angenommen.

### Entscheidung 4 — Protokolliert wird, was ändert

Ins Protokoll gehen **die ändernden Handlungen** — anlegen, ändern, löschen —
mit Abonnement, Datenbank, Schema, Tabelle und Schlüssel. Lesende Abfragen
entstehen beim Blättern zu Dutzenden und stünden hundertfach im Protokoll, ohne
etwas zu beantworten.

**Und ohne die Werte.** Ein Protokoll, das den Inhalt einer geänderten Zeile
führt, ist eine zweite Kopie der Kundendaten an einer Stelle, an der sie niemand
vermutet — und sie überlebt das Löschen der Zeile. Was im Protokoll steht, ist
*welche* Zeile geändert wurde, nicht *worauf*.

> **Ein Protokoll, das den Inhalt mitschreibt, ist eine Datenhaltung mit einem
> anderen Namen.**

**Dazu ein Eintrag beim Öffnen** (Entscheidung 5, Punkt 4), und zwar
**entprellt: einer je Datenbank und Stunde.** Ohne ihn beantwortet das Protokoll
„was wurde geändert" und nicht „wer hatte Zugriff" — und die zweite Frage ist
die, die im Zweifel gestellt wird. Ohne die Entprellung schriebe er bei jedem
Blättern eine Zeile und wäre nach einer Woche das, wogegen der erste Absatz
argumentiert.

Der Eintrag entsteht in der **Anwendung** und nicht im Agenten: Er hält fest, wer
gesehen hat, und das weiss nur die Seite, die ein angemeldetes Konto kennt.

---

### Entscheidung 5 — Fünf Ergänzungen zum Zuschnitt

Vorgelegt und angenommen am 12. August 2026, nachdem der Plan einmal
ausgeschrieben war. Keine davon ändert eine der vier oberen; sie stehen hier
zusammen, weil sie zusammen vorgelegt wurden, und jede sagt, wo sie im Plan
sitzt.

1. **Der Filter fängt mit drei Operatoren an, nicht mit acht** — `ist gleich`,
   `enthält`, `ist leer`. Die Filterzeile ist das dichteste Bedienelement der
   ganzen Fläche und steht bei 390 px über einer Tabelle, die schon waagerecht
   rollt; und acht Operatoren sind acht Wege, auf denen die Maskierung falsch
   sein kann. Die übrigen kommen dazu, wenn jemand die drei benutzt hat — nicht
   vorher. (§11)
2. **Kein `count(*)` über einen Filter.** Geholt wird `limit + 1`, und die
   Oberfläche sagt „mehr als 50" statt einer Zahl. Ein `count(*)` über eine
   gefilterte Spalte ohne Index ist genau die Abfrage, die ins Zeitlimit läuft —
   und sie liefe **jedes Mal**, auch für den, der nur die erste Seite ansieht.
   (§9, §11)
3. **Eine Zelle lässt sich einzeln ansehen.** Nach §9 ist eine Zelle bei 512
   Zeichen gekürzt und nach §10.1 dann gesperrt; ohne einen Weg zum ganzen Wert
   wäre das eine Sackgasse. Ein fünftes Operationenpaar, mit eigener, höherer
   Grenze. (§9, §12)
4. **Ein Protokolleintrag beim Öffnen, entprellt.** Entscheidung 4 hält fest,
   *was geändert wurde*; offen bliebe „wer hatte überhaupt Zugriff" — und mit
   Entscheidung 3 und der Impersonation ist genau das die Frage, die im Zweifel
   jemand stellt. Ein Eintrag je Datenbank und Stunde beantwortet sie, ohne dass
   das Protokoll mit dem Blättern wächst. (§3 Entscheidung 4, §13 Schritt 7)
5. **Die optimistische Sperre wird benannt und nicht gebaut.** Zwei Personen, die
   dieselbe Zeile gleichzeitig öffnen, überschreiben einander; §10 fängt das
   nicht, weil die Zeile ja getroffen wird. Die Regel aus §10.1 — nur geänderte
   Spalten — hält den Schaden auf die Spalten begrenzt, an denen beide gearbeitet
   haben. (§16, §18)

**Schritt 6 bleibt im Umfang.** Er stand zur Debatte als der Schnitt, wenn die
Stufe kleiner werden soll — Lesen trägt den grössten Teil des Nutzens und keines
der Risiken aus §10.1. Der Betreiber hat ihn drin gelassen; damit ist §10.1
keine Vorsichtsmassnahme für später, sondern Bauvorschrift für diese Stufe.

---

## 4. Das Abnahmekriterium

> ### Das Abnahmekriterium von P5c
>
> **Fertig, wenn** ein Kunde in seiner Datenbank die Tabellen und die Struktur
> einer Tabelle sieht, eine Seite Zeilen liest, blättert, sortiert und filtert,
> und eine Zeile anlegt, ändert und löscht — und
>
> 1. jede dieser Handlungen unter einem **befristeten Zugang** lief, der danach
>    fort ist — belegt an seinem Namen, nicht an einer Anzahl;
> 2. ein Wert mit **Tabulator**, einer mit **Zeilenumbruch**, ein **`NULL`** und
>    eine **leere Zeichenkette** in der Oberfläche unterscheidbar ankommen — alle
>    vier benannt, nicht gezählt;
> 3. die Tabelle einer **fremden** Datenbank über die Konsole nicht erreichbar
>    ist, und zwar an der **Meldung des Servers** und nicht an einer des Panels;
> 4. eine Abfrage, die das Zeitlimit überschreitet, **abgebrochen** wird und der
>    Kunde den Grund wörtlich liest;
> 5. eine Tabelle **ohne Schlüssel** als nicht änderbar erkennbar ist, mit dem
>    Grund daneben;
> 6. ein Schreibvorgang, der **nicht genau eine Zeile** trifft, zurückgenommen
>    wird — und einer, der eine Zeile trifft, **nur die geänderten Spalten**
>    schreibt: Eine gekürzte Zelle und ein `NULL` überstehen das Speichern einer
>    Zeile, an der sie niemand angefasst hat;
> 7. das Protokoll die drei ändernden Handlungen führt und **keinen Zellenwert**
>    — dazu **einen** Eintrag für zwanzig Seitenaufrufe und nicht zwanzig.
>
> **Für beide Datenbanksysteme**, jeder Punkt zweimal gefahren.

Punkt 3 ist Punkt 3 aus [38 §3](38-postgresql.md) an einer neuen Stelle, und der
Zusatz ist der Kern: Wenn das Panel den Zugriff abweist, beweist der Lauf die
Prüfung des Panels und nicht die Trennung der Datenbank. Der Beleg ist die
Meldung des Servers.

Punkt 2 ist die Umsetzung von M7 in ein Kriterium — mit derselben Vorsicht, die
`docs/36 §17` gelernt hat: **die vier Werte gehören ins Protokoll, nicht ihre
Anzahl.** „Sonderzeichen kommen an" wäre erfüllt, solange irgendetwas ankommt.

**Die zweite Hälfte von Punkt 6 ist mit Entscheidung 5 dazugekommen**, und sie
misst dieselbe Sache wie Punkt 2 auf dem Rückweg (§10.1). Sie ist der einzige
Punkt dieses Kriteriums, dessen Fehlschlag man an der geänderten Zeile **nicht
sieht**: Die Zeile ist danach da, sie sieht richtig aus, und der Rest einer
gekürzten Zelle ist fort. Deshalb wird er an einer Spalte gefahren, die
niemand angefasst hat, und nicht an der geänderten.

---

## 5. Wo die Abfrage läuft — und warum das hier keine Ausnahme ist

**Im Agenten, unter einem befristeten Zugang, und die Anwendung sieht nur das
Ergebnis.**

Die erste Grenze aus `CLAUDE.md` lautet: *typisierte Operationen, niemals Text,
der zu einer Kommandozeile oder Konfigurationsdatei wird.* Ein Werkzeug in der
Art von Adminer scheint dem zu widersprechen, weil sein Kern ein Eingabefeld für
SQL ist. **Mit Entscheidung 2 gibt es dieses Feld nicht**, und damit fällt der
Widerspruch weg, statt umgangen zu werden:

```
db.console.rows  { database, schema, table, order, direction, offset, limit, filter? }
```

ist eine Operation wie `db.user.grant` — jedes Feld hat einen Typ, jeder
Bezeichner wird im Katalog **nachgeschlagen** (§7), und der Agent baut die
Anweisung daraus. Was über die Prozessgrenze geht, ist eine Frage, keine
Anweisung.

**Der Agent führt trotzdem nicht als root aus.** Das ist der Punkt, an dem M5
zählt: Läuft die Abfrage unter der Kennung, unter der der Agent arbeitet, dann
ruht die ganze Mandantentrennung auf `Names::belongsTo()` — auf **unserer**
Prüfung. Eine zweite Fassung derselben Regel ist die, die veraltet.

Sie läuft deshalb unter einem befristeten Zugang, der Mitglied der
Eigentümerrolle des Abonnements ist. Dann weist **die Datenbank** ab, was nicht
zum Abonnement gehört, und `Names::belongsTo()` bleibt die erste Wand statt der
einzigen.

**Und der grösste Teil davon ist gebauter, abgenommener Code.** P5c legt keinen
neuen Weg mit Rechten an:

| gebraucht | steht seit | in |
|---|---|---|
| befristeter Zugang, `finally`-Abbau | P5 / P5b | `Db\Ephemeral`, `Pg\Ephemeral` |
| Anmeldung über den Socket mit Passwort | P5b | `Pg\Hba::RULE` (`local all +srvpanel_restore`) |
| Namensprüfung gegen das Präfix | P5 | `Db\Names::belongsTo()` |
| Maskierung von Bezeichnern und Zeichenketten | P5 / P5b | `Db\Sql`, `Pg\Sql` |
| Anmeldung des Agenten | P5b | `Pg\Session::ROLE` |

Genau der Mechanismus, unter dem seit P5 **mitgebrachte Dumps** laufen — also
beliebiges fremdes SQL —, trägt jetzt auch das Durchsehen. Das ist der
konservativste verfügbare Weg: kein neuer Zugangspfad, keine neue Ablage, keine
neue Abhängigkeit.

**Der Preis, ehrlich benannt.** Das Ergebnis geht vollständig durch den Socket;
es gibt kein Blättern im Server, wie ein Cursor es erlaubte (M10). Die
Anfragegrenze des Agenten liegt bei 1 MiB (`Connection::REQUEST_MAX`), und
**eine einzige Zelle kann sie sprengen** (M21). Deshalb ist die Kürzung aus §9
keine Bequemlichkeit, sondern eine Bedingung.

---

## 6. Der befristete Zugang

Wörtlich `Db\Ephemeral::with()` und `Pg\Ephemeral::with()`, mit einem
Unterschied und einer Zahl.

**Der Unterschied: er ist Mitglied der Eigentümerrolle, nicht eines
Kundenzugangs.** Ein Abonnement kann mehrere Datenbankzugänge haben, und jeder
hat eigene Rechte. Die Konsole zeigt dem Kunden **seine Datenbank** und nicht
„was einer seiner Zugänge sehen dürfte" — die Grenze, die zählt, ist das
Abonnement. Für PostgreSQL ist das genau die Rolle, unter der schon das
Zurückspielen arbeitet (`Names::owner()`); für MariaDB entstehen wie in P5
Rechte auf genau die eine Datenbank.

**Die Zahl: 11,2 ms** gegen 6,6 ms (M6). Ein Zugang je Anfrage, angelegt und im
`finally` wieder fort. Was er kostet, spart er an anderer Stelle vollständig
ein: Zwischen zwei Anfragen liegt kein Geheimnis — nicht in der Sitzung, nicht
in der Panel-Datenbank (`SESSION_DRIVER=database`), nirgends. Die Antwort auf
„wo liegt das Passwort der Konsole" bleibt dieselbe wie die auf „wo liegen die
Datenbankpasswörter der Kunden" ([36 §4](36-datenbanken.md)): **nirgends.**

**Der Name trägt die Herkunft.** `Names::ephemeral()` erzeugt heute
`<präfix>_r<zufall>`; das `r` steht für das Zurückspielen. Die Konsole bekommt
ein eigenes Zeichen — `<präfix>_c<zufall>` —, damit ein Rest auf dem Server
sagt, **wobei** er entstanden ist. `srvpanel db` findet beide über
`Names::isEphemeral()`; die Erweiterung dort ist eine Zeile und gehört in
Schritt 1, nicht in den Abnahmelauf.

> **Ein Rest, der nicht sagt, woher er kommt, kostet beim Aufräumen genau die
> Zeit, die man beim Benennen gespart hat.**

---

## 7. Ein Bezeichner wird nachgeschlagen, nicht nur maskiert

Was aus dem Browser kommt — ein Tabellenname, ein Spaltenname zum Sortieren, ein
Spaltenname im Filter — ist Text aus einer fremden Quelle, und er landet an einer
Stelle, an der `Sql::identifier()` nur die eine Frage beantwortet, ob er sich
gefahrlos in Anführungszeichen setzen lässt.

**Das genügt hier nicht, und der Grund ist nicht Sicherheit, sondern Wahrheit.**
Ein maskierter Name, den es nicht gibt, erzeugt eine Fehlermeldung des Servers
über eine Relation — eine Auskunft, die ein Kunde weder braucht noch deuten
kann, und im ungünstigen Fall eine über eine Tabelle, die es **anderswo** gibt.

Die Regel: **Jeder Bezeichner aus dem Browser wird gegen den Katalog derselben
Datenbank geprüft, bevor er in eine Anweisung kommt.** Steht er nicht darin,
antwortet der Agent mit `badRequest` und nicht mit dem Fehler des Servers. Die
Maskierung bleibt zusätzlich — sie fällt nicht weg, weil es die Nachschlage-
prüfung gibt.

Für **Werte** — die rechte Seite eines Filters, der Inhalt einer geänderten
Zelle — gilt `Sql::text()`. M23 hat die Verdoppelung gegen einen Ausbruchsversuch
gefahren; sie hielt, bei `standard_conforming_strings` on **und** off. Das ist
trotzdem die erste Stelle des Projekts, an der **Kundentext** in eine Anweisung
geht — bisher waren es erzeugte Passwörter und geprüfte Netze —, und deshalb
stellt der Agent die Einstellung ausdrücklich her, statt sich auf die Vorgabe zu
verlassen.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**
> ([44](44-mariadb-ipv6-ausfall.md), und hier vorbeugend angewandt.)

---

## 8. Wie ein Ergebnis zurückkommt

**Als JSON, eine Zeile je Datenzeile** — `row_to_json()` in PostgreSQL,
`JSON_OBJECT()` in MariaDB. Gemessen (M8) trägt es alle vier Fälle, an denen die
Textausgabe scheitert:

| | `psql -A -t -F'\t'` | `row_to_json` |
|---|---|---|
| `NULL` | leeres Feld | `null` |
| `''` | leeres Feld | `""` |
| Wert mit Tabulator | **eine Spalte mehr** | `"a\tb"` |
| Wert mit Zeilenumbruch | **eine Zeile mehr** | `"z1\nz2"` |
| `bytea` | `\x0001ff` | `"\\x0001ff"` |

Die Umstellung betrifft **nur die Konsole.** `Session::query()` bleibt, wie es
ist: Für Katalogfragen ist die Textform richtig, und sie hat zwei Stufen
getragen. Was dazukommt, ist eine zweite Methode neben ihr — nicht ein Umbau der
ersten.

### 8.1 Für MariaDB gilt `--raw`, und zwar nur hier

`Db\Session` ruft heute mit `--batch --skip-column-names`. **Für eine JSON-Zeile
ist das falsch** (N1): `--batch` maskiert in der Ausgabe Tabulator,
Zeilenumbruch und Rückstrich — und eine JSON-Zeichenkette besteht aus maskierten
Rückstrichen. Aus `"a\tb"` wird `"a\\tb"`, und das ist **gültiges JSON mit einem
falschen Wert**: vier Zeichen `a \ t b` statt drei.

Die neue Methode ruft deshalb mit `--raw --batch`. Dass `--raw` sonst
gefährlich wäre — ein roher Zeilenumbruch im Wert bräche die Zeilentrennung —
gilt hier nicht: `JSON_OBJECT()` maskiert Steuerzeichen selbst, gemessen (N2).
**Die Sicherheit kommt vom Format, nicht vom Klienten**, und deshalb darf der
Klient sie loslassen.

**`--raw` bleibt aus der bestehenden `query()` heraus.** Dort ist die Maskierung
des Klienten genau die Sicherung, die die Zeilentrennung trägt; wer sie dort
entfernte, machte aus einer richtigen Methode eine kaputte. Der Wächter dazu
steht in §14.8.

### 8.2 Eine binäre Spalte erreicht `JSON_OBJECT()` gar nicht erst

**Binäre Spalten zeigt die Konsole als Länge und nicht als Wert.** `bytea` und
`BLOB` erscheinen als `<binär, 48 kB>` und lassen sich nicht ändern. Ein
Hexblock in einer Tabellenzelle hilft niemandem, ein Bild kann diese Oberfläche
nicht anzeigen, und das Ändern eines binären Werts über ein Textfeld wäre ein
Weg, Daten zu beschädigen, ohne es zu merken.

**Bis Schritt 0 war das eine Frage der Anzeige. Jetzt ist es eine Bedingung.**
N3 bis N5: Ein `BLOB` mit ungültigem UTF-8 landet als rohe Bytes in der
JSON-Zeichenkette; MariaDBs `JSON_VALID()` sagt dazu `1`, und PHPs
`json_decode()` gibt `null` zurück — **für die ganze Zeile.** Ein einziges
Bildchen in Spalte drei nimmt die anderen neunzehn Spalten mit, und die Meldung
lautet „Malformed UTF-8", also nach einem Fehler des Panels.

Deshalb: **Die Spaltenliste der Abfrage entsteht aus dem Katalog, und eine
binäre Spalte kommt dort als `OCTET_LENGTH(spalte)` hinein und nie als Wert**
(N6). Das ist keine Filterung des Ergebnisses, sondern eine der Frage — der
Unterschied ist, dass eine vergessene Filterung des Ergebnisses die Seite
zerstört, eine vergessene der Frage nur eine Spalte zu viel zeigt.

> **Was ein Format nicht tragen kann, gehört nicht hinein — nicht hinein und
> hinterher entfernt.**

### 8.3 Und der Klient muss seinen Zeichensatz nennen — gefunden erst auf dem Server

**Nachgetragen am 12. August 2026, aus dem Lauf von `docs/47`.** §8.1 und §8.2
haben zwei Wege gefunden, auf denen eine ganze Zeile unlesbar wird. Es gibt
einen dritten, und er trifft nicht eine Sonderspalte, sondern **jede deutsche
Kundendatenbank**.

`Db\Session` rief `mysql` ohne `--default-character-set`. Der Klient leitet den
Zeichensatz sonst aus der Locale ab — und `Runner::ENVIRONMENT` erzwingt seit P0
`LC_ALL=C`, damit Zahlen- und Datumsformate stabil bleiben. Ohne Locale fällt
`mysql` auf seinen eingebauten Zeichensatz zurück: **latin1**. Der Server steht
auf `utf8mb4` und konvertiert am Ausgang. Gemessen auf `cloudsrv24`, MariaDB
10.11.14:

```
env -i LC_ALL=C LANG=C mysql --batch --raw --skip-column-names \
  -e "SELECT JSON_OBJECT('n', notiz) FROM lang"
→ 75 6e 62 65 72  fc  68 72 74        "unber" · FC · "hrt"

… mit --default-character-set=utf8mb4:
→ 75 6e 62 65 72  c3 bc  68 72 74     "unber" · C3 BC · "hrt"
```

`FC` ist `ü` in latin1 und für sich genommen kein gültiges UTF-8;
`json_decode()` gibt `null` zurück, und damit ist wieder die **ganze Zeile**
fort. Die drei Wege enden also am selben Ort, und nur einer davon war geplant.

**Warum PostgreSQL denselben Fehler nicht hat:** `psql` leitet
`client_encoding` ebenfalls aus der Locale ab, und `LC_ALL=C` ergibt dort
`SQL_ASCII` — also **keine Konvertierung**. Die Bytes gehen unangetastet durch.

> **Zwei Systeme unter derselben Umgebung treffen entgegengesetzte Vorgaben —
> und die eine ist verlustfrei, die andere nicht.**

**`utf8mb4` und nicht „was die Locale sagt".** Auf demselben Server handelt ein
`mariadb` aus einer UTF-8-Shell `utf8mb3` aus; ein Zeichen ausserhalb der BMP —
ein Emoji in einer Kundentabelle — käme auch dort nicht heil an. Der Zeichensatz
gehört zur Abfrage und nicht zur Umgebung dessen, der sie stellt.

**Und die Argumentliste stand zweimal da**, in `run()` und in `linesAs()`.
Genau daran ist die fehlende Angabe nicht aufgefallen: Es gab keinen Ort, an dem
man nachsieht. Sie steht jetzt als `Session::CLIENT` einmal, und §14.8 prüft
beides — die Angabe und dass beide Wege die Konstante benutzen.

> **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von beiden
> ist der Ort, an dem man nachsieht.**

**Dazu ein zweiter Fund, der danebenlag:** Die Meldung aus `Console::decode()`
lautete „*Steht eine binäre Spalte in der Abfrage?*" — der Hinweis aus §8.2, und
für diesen Fall die falsche Fährte. Sie nennt jetzt beide Ursachen.

> **Ein Hinweis, der genau eine Ursache nennt, ist eine Diagnose — und eine
> falsche Diagnose ist teurer als keine.**

**Warum kein Test das gefunden hat:** Die Testdoppel dieses Projekts sind ASCII,
und der Abnahmelauf wäre es beinahe auch gewesen. Das einzige nicht-ASCII-Zeichen
im ganzen Testbestand von `docs/47` ist das `ü` in `'unberührt'` — hingeschrieben
als deutsches Wort, nicht als Prüfung.

> **Ein Testdatensatz aus ASCII prüft keine Kodierung.**

---

## 9. Die Grenzen, und woher jede ihre Zahl hat

| Grenze | Wert | Woher |
|---|---|---|
| Zeilen je Seite | **50** | M20: 11 KB JSON gegen 1 MiB Anfragegrenze — Raum um den Faktor 90 für breite Tabellen |
| Zeichen je Zelle | **512**, gekürzt markiert | M21/M22: eine Zelle mit 3 MB sprengt die Grenze allein, gekürzt sind es 524 Byte |
| Zeitlimit je Abfrage | **5 s** | eine Konsolenabfrage ist eine Bedienung und kein Vorgang |
| Zeilenzahl, ungefiltert | **Schätzung** aus dem Katalog | ein `count(*)` über eine grosse Tabelle ist selbst die teure Abfrage |
| Zeilenzahl, gefiltert | **gar keine** — `limit + 1` | Entscheidung 5, Punkt 2 |
| Zeichen einer **einzeln** geöffneten Zelle | **64 KiB**, gekürzt markiert | Entscheidung 5, Punkt 3: weit unter der Anfragegrenze, weit über allem, was eine Tabellenzeile trägt |

**Das Zeitlimit ist durchsetzbar, weil es kein freies SQL gibt.** M11 hat
gemessen, dass ein Rolleninhaber `statement_timeout` selbst zurücknehmen kann —
auch gegen `ALTER ROLE … SET`. Er kann es nur, wenn er ein `SET` schicken darf,
und genau das nimmt ihm Entscheidung 2. Der Agent setzt den Wert in derselben
Sitzung vor der Abfrage.

**Und der Abbruch nach dem Zeitlimit ist kein Nebeneffekt, sondern der Grund für
das Limit.** M12 zeigt, dass eine Abfrage weiterrechnet, wenn ihr Klient
wegfällt; M13, dass eine zweite befristete Rolle sie nicht abbrechen kann, weil
die Absperrung aus P5b `pg_stat_activity` verschlossen hat. Es gibt also **keinen
Weg, der ohne den Agenten auskäme** — was ein weiteres Argument für Entscheidung
1 ist, und es ist erst beim Messen entstanden.

**Die Schätzung hat einen dritten Zustand, und er sitzt in jedem System
woanders.** M19: `pg_class.reltuples` ist `-1` für eine Tabelle, die noch nie
analysiert wurde — nicht `0`. In MariaDB gibt es das für Basistabellen **nicht**
(N9: 197 076 bei 200 000 echten Zeilen, und eine nie analysierte Tabelle liefert
ihre drei Zeilen genau), dafür ist `TABLE_ROWS` bei einer **Sicht** `NULL` (N10).
Wer die eine Hälfte prüft und sich dann sicher fühlt, schreibt im anderen System
unter jede Sicht „0 Zeilen". Wer die Zahl unbesehen anzeigt, schreibt „−1 Zeilen" unter eine
Tabelle mit Inhalt; wer sie auf `max(0, …)` klemmt, schreibt „0 Zeilen" und das
ist schlimmer, weil es aussieht wie eine Antwort. Die Oberfläche zeigt
**„unbekannt"**, und `docs/41` hat denselben Satz schon einmal gebraucht:

> **Eine Zahl, die „nicht gemessen" bedeutet, darf nicht wie eine Messung
> aussehen.**

**Über einem Filter gibt es überhaupt keine Zahl.** Die Schätzung des Katalogs
gilt für die ganze Tabelle und wäre unter einem Filter schlicht falsch; ein
`count(*)` mit `WHERE` über eine Spalte ohne Index ist genau die Abfrage, die
das Zeitlimit auslöst — und sie liefe bei **jedem** Seitenaufruf, auch für den,
der nur die erste Seite ansieht und gleich wieder geht. Geholt wird `limit + 1`,
und danach steht dort „mehr als 50" oder die genaue Zahl, wenn die Seite die
letzte ist.

Das kostet die Blätterleiste ihre Seitenzahlen — sie kann nur „weiter" und
„zurück". Das ist der ehrliche Preis, und er ist kleiner als eine Zahl, die
gelegentlich fünf Sekunden braucht.

> **Eine Zahl, für die jemand bei jedem Aufruf bezahlt, muss auch bei jedem
> Aufruf gebraucht werden.**

Was die Grenzen **nicht** leisten: Blättern mit grossem `OFFSET` bleibt langsam,
weil der Server die übersprungenen Zeilen erzeugt. Das steht in §18 als benanntes
Risiko und wird nicht durch einen Cursor gelöst — ein Cursor lebte länger als
eine Anfrage, und der befristete Zugang tut das nicht.

---

## 10. Eine Zeile ändern braucht einen Schlüssel

**Ohne Schlüssel gibt es keinen sicheren Bezug auf eine Zeile.** Zwei Zeilen mit
gleichem Inhalt sind in einer Tabelle ohne Schlüssel nicht unterscheidbar, und
ein `UPDATE … WHERE <alle Spalten>` trifft dann beide.

Die Regel:

1. Ein **Primärschlüssel** wird benutzt (M14 zeigt die Katalogabfrage dafür).
2. Sonst ein **eindeutiger Index über Spalten ohne `NULL`**.
3. Sonst ist die Tabelle **nur lesbar**, und die Oberfläche sagt warum — nicht
   „Ändern nicht möglich", sondern „Diese Tabelle hat keinen Primärschlüssel;
   ohne ihn lässt sich eine einzelne Zeile nicht eindeutig ansprechen."

### 10.1 Der Schreibweg hat dieselbe Hälfte wie M7, und sie ist die gefährlichere

M7 hat gemessen, was auf dem **Leseweg** verlorengeht. Zwei Fälle davon haben
einen Zwilling auf dem **Schreibweg**, und dort ist der Verlust nicht eine
Anzeige, sondern ein Datenverlust. Beide sind in der ersten Fassung dieses
Abschnitts gefehlt.

**Ein Textfeld kann `NULL` nicht ausdrücken.** Ein Formular, das eine leere
Eingabe als `''` schreibt, macht aus jedem `NULL` einer nullbaren Spalte eine
leere Zeichenkette — lautlos, und beim ersten Speichern einer Zeile, an der
niemand diese Spalte anfassen wollte. Ein `WHERE spalte IS NULL` der
Kundenanwendung findet die Zeile danach nicht mehr.

Deshalb: **`NULL` ist ein eigener Zustand des Feldes und keine leere Eingabe.**
Ein Kästchen daneben, und solange es gesetzt ist, ist das Textfeld gesperrt.
Bei einer Spalte mit `NOT NULL` gibt es das Kästchen nicht.

**Eine gekürzte Zelle darf nicht zurückgeschrieben werden.** §9 kürzt bei 512
Zeichen, weil eine einzelne Zelle sonst die Anfragegrenze sprengt (M21). Käme
der gekürzte Wert beim Speichern zurück in die Zeile, wäre der Rest fort — und
zwar für den, der die Zeile aus einem ganz anderen Grund geöffnet hat. Zwei
Regeln, und die zweite trägt die erste:

1. **Ein gekürztes Feld ist gesperrt**, mit dem Grund daneben.
2. **Die Anweisung enthält nur die Spalten, die der Kunde geändert hat.** Ein
   `UPDATE` über alle Spalten schreibt auch die zurück, die nur angezeigt
   wurden — und damit jede Kürzung, jedes `''` aus einem `NULL` und jede
   Rundung, die zwischen Anzeige und Formular entstanden ist.

Regel 2 ist die wichtigere von beiden: Sie schützt auch vor den Fällen, die hier
niemand aufgezählt hat.

> **Ein Formular, das zurückschreibt, was es nur angezeigt hat, überträgt jeden
> Anzeigefehler in die Daten.**

**Und der Schreibvorgang prüft sich selbst.** Er läuft in einer Transaktion, und
was er ändert, muss **genau eine Zeile** sein; sonst wird zurückgenommen und der
Vorgang meldet, was er vorgefunden hat. Das fängt den Fall, den Regel 2 nicht
fängt: einen eindeutigen Index, der zwischen Anzeige und Änderung seine
Eindeutigkeit verloren hat.

> **Ein Schreibvorgang, der nicht nachzählt, was er getroffen hat, meldet
> Erfolg für einen Treffer, den niemand geprüft hat.** Das ist die Lehre aus
> `docs/36 §22.3m` — dort fehlte der Vorgang zwischen Vorbereitung und
> Nachprüfung, und beides sah grün aus.

---

## 11. Was in der Oberfläche entsteht

Drei Ansichten, alle unter der vorhandenen Datenbankseite:

- **Tabellen** — Liste mit Name, geschätzter Zeilenzahl, Grösse und dem Hinweis,
  ob sie einen Schlüssel hat.
- **Struktur** — Spalten mit Typ, `NULL`-Zulässigkeit, Vorgabe, Schlüssel; dazu
  die Indexe.
- **Zeilen** — eine Seite, sortierbar über eine Spalte, filterbar über eine
  Spalte mit einem Operator aus einer festen Liste, mit „weiter" und „zurück".
- **Eine Zelle** — der ganze Wert einer einzelnen Zelle, bis 64 KiB
  (Entscheidung 5, Punkt 3). Sie ist der Ausweg aus der Kürzung: In der Tabelle
  steht ein Wert bei 512 Zeichen abgeschnitten und ist nach §10.1 gesperrt, und
  ohne diesen Weg käme niemand mehr an den Rest.

**Die feste Liste hat drei Einträge und nicht acht** (Entscheidung 5, Punkt 1):
`ist gleich`, `enthält`, `ist leer`. Zwei Gründe, und der zweite ist der
schwerere: Die Filterzeile steht bei 390 px über einer Tabelle, die schon
waagerecht rollt — und acht Operatoren sind acht Wege, auf denen die Maskierung
falsch sein kann, an der einzigen Stelle dieses Projekts, an der Kundentext in
eine Anweisung geht (§7).

**`ist leer` deckt `NULL` und die leere Zeichenkette zusammen ab**, und das ist
Absicht: Sie auseinanderzuhalten ist die Aufgabe der **Anzeige** (Kriterium 2)
und des **Schreibwegs** (§10.1). Wer nach der einen sucht, sucht in aller Regel
nach beiden — und `ist NULL` neben `ist gleich ''` wären zwei Einträge, die
neunundneunzig von hundert Bedienenden nicht unterscheiden wollen. Die genaue
Unterscheidung kommt zurück, sobald jemand sie braucht; sie steht dann in der
Liste und nicht in einer Fussnote.

**Bei 390 px ist das der schwierigste Baustein, den dieses Panel bisher hatte** —
eine Tabelle mit unbekannter Spaltenzahl. `docs/24 §5` kennt drei Muster, und
zwei davon scheiden aus: `.stacks` macht aus jeder Zeile ein Kärtchen und ist für
zwanzig Spalten unlesbar, `.pairs` ist für ein Paar gedacht. Es bleibt
**`.scrolls`** — die Tabelle bleibt eine Tabelle und rollt waagerecht.

Das ist die einzige Stelle dieses Panels, an der waagerechtes Rollen **richtig**
ist, und sie braucht ihre eigene Messung:

> **Eine Zahl, die am Dokument misst, sagt nichts über eine Zelle, die selbst
> scrollen darf.** (`CLAUDE.md`, aus dem Fernzugriff.)

`scrollWidth - clientWidth` wird also **am Dokument und an der rollenden Zelle
getrennt** gemessen, und am Dokument muss sie 0 sein. Die erste Spalte bleibt
stehen, damit man beim Rollen weiss, in welcher Zeile man ist.

**Der Ort einer Rückmeldung** folgt `docs/19 §6` ohne Ausnahme: Der Fehlersatz
steht oben in der Zusammenfassung, das Feld trägt `aria-invalid`, und Erfolg
wird nie am Feld gemeldet.

### 11.1 Die Baumansicht — nachgetragen am 12. August 2026

**Der Betreiber hat gefragt, ob sich die drei Ansichten als aufklappbarer Baum
zeigen lassen.** Die Antwort ist ja, aber der Nutzen liegt woanders, als die
Frage vermuten lässt — und das steht hier, weil es gemessen wurde und nicht
überlegt.

Erwartet war, dass ein Baum bei 390 px an der Einrückung scheitert: Jede Ebene
kostet Breite, und die Breite fehlt den Daten. **Gemessen ist das Gegenteil.**
Der waagerechte Überlauf ist in jedem Entwurf `0px`; entschieden wird die Frage
**senkrecht**:

| 20 Tabellen, zugeklappt | 390 px | 1440 px |
|---|---|---|
| als gestapelte Tabelle (heute) | **4992 px** — 5,9 Bildschirme | 881 px |
| als Baum | **964 px** — 1,1 Bildschirme | 803 px |

Dieselben fünf Angaben je Tabelle, dieselben Daten, nur die Form ist anders.
**Am Arbeitsplatz nehmen die beiden sich nichts** — über 720 px ist die Tabelle
wieder eine Tabelle, und sie ist gut.

> **Der Baum löst kein Navigationsproblem. Er löst ein Telefonproblem.**

Der Grund liegt in `docs/24 §5`: `.stacks` macht aus jeder Zeile ein Kärtchen mit
einer beschrifteten Zeile je Spalte. Das ist richtig für ein **Verzeichnis**, das
man Zeile für Zeile liest — Kunden, Pläne, Vorgänge. Es ist falsch für eine
Liste, die man nach **einem Namen absucht**, und genau das ist eine Tabellenliste.

#### Was der Baum trägt, und was nicht

**Tabellen und Ziele, keine Daten.** Ein Zweig ist eine Tabelle, seine Blätter
sind drei Ziele — Spalten, Indexe, Zeilen —, und was man wählt, erscheint als
Tabelle: bei 390 px darunter, ab 900 px daneben.

**Keine Spalten als Blätter.** Ein Baum, der sie führt, müsste Typ,
`NULL`-Zulässigkeit, Vorgabe und Schlüssel weglassen — vier von fünf Angaben —
und wäre damit eine zweite, schlechtere Fassung der Strukturansicht.

**Keine Zeilen im Knoten.** Eine Seite Zeilen ist fünfzig Datensätze mit
unbekannter Spaltenzahl, die waagerecht rollen dürfen. In einen Baumknoten passt
das nicht; der Baum **ruft sie auf**.

**Und im Navigationsbaum steht nur der Name.** Der erste Entwurf hatte Art,
Zeilenzahl und Grösse rechts daneben; in einer 300 px schmalen Spalte quetschte
das den Tabellennamen auf ein Zeichen je Zeile. Dieselbe Falle wie an drei
anderen Stellen dieses Panels — ein Flexkind behält seine Inhaltsbreite, und der
Nachbar verliert. Die Zahlen stehen im Inhalt, wo Platz für sie ist.

#### Drei Entscheidungen

**1. Eine Form für beide Breiten.** Der Baum unter 720 px und die Tabelle
darüber wären zwei Fassungen derselben Ansicht — und die zweite ist die, die
veraltet. Der Baum gilt überall; ab 900 px bekommt er den Inhalt daneben statt
darunter.

**2. Nach Schritt 5.** Der Baum ruft die Zeilenansicht auf. Vorher gebaut, hätte
er nichts zum Aufrufen, und die Blätter wären Zusagen ins Leere.

**3. Als eigener Schritt und nicht als Zusatz zu 4.** Ein zweispaltiger
Grundriss ist eine Änderung an diesem Plan und nicht an seiner Umsetzung: Jede
Seite dieses Panels ist heute **eine** Spalte aus Bereichen.

#### Was er kostet

**Jedes Aufklappen ist ein befristeter Zugang.** Eine Katalogfrage legt eine
Rolle an und räumt sie ab — in PostgreSQL gemessene 11 ms, in MariaDB kommt ein
Neuladen der Rechtetabellen dazu und ist **ungemessen** (Risiko 4 in §18). Daraus
folgt zweierlei, und beides ist eine Regel und keine Empfehlung:

- **Der Baum lädt erst beim Aufklappen**, nie auf Vorrat.
- **„Alles aufklappen" gibt es nicht.** Ein Knopf, der zwanzig Zweige öffnet,
  legt zwanzig Datenbankrollen an.

Dazu ein Bedienmuster, das dieses Panel noch nicht hat: `role="tree"`,
`role="treeitem"`, `aria-expanded` und Pfeiltastenbedienung. Der Wächter dazu
steht in §14.9.

---

## 12. Die Operationen

Fünf Paare. `EngineReachTest` verlangt zu jeder `db.*` eine `pg.*`, und keine
davon braucht einen Ausnahmeeintrag.

| Operation | nimmt | gibt |
|---|---|---|
| `db.console.tables` · `pg.console.tables` | `database` | Liste `{schema, name, rows|null, bytes, key: bool}` |
| `db.console.columns` · `pg.console.columns` | `database, schema, table` | Liste `{name, type, nullable, default, key, binary}` + Indexe |
| `db.console.rows` · `pg.console.rows` | `database, schema, table, order, direction, offset, limit, filter?` | `{columns, rows, truncated: list<string>, more: bool}` |
| `db.console.cell` · `pg.console.cell` | `database, schema, table, key, column` | `{value, truncated: bool, bytes}` |
| `db.console.row.write` · `pg.console.row.write` | `database, schema, table, mode, key, values?` | `{affected}` |

**`more` statt einer Anzahl.** `console.rows` holt `limit + 1` Zeilen, gibt
`limit` zurück und sagt mit `more`, ob es weitergeht (Entscheidung 5, Punkt 2).
Ein Feld `total` gibt es nicht — es zu füllen wäre der `count(*)`, den §9
ausgeschlossen hat, und ein Feld, das manchmal `null` ist, lädt dazu ein, es
irgendwann doch zu füllen.

**`console.cell` braucht den Schlüssel und nicht die Zeilennummer.** Eine
Zeilennummer wäre eine Aussage über eine Seite, und zwischen ihrem Abruf und dem
Öffnen der Zelle kann jemand eine Zeile einfügen — dann zeigte die Ansicht den
Wert einer anderen Zeile, und niemand sähe es. Damit gilt für sie dieselbe
Voraussetzung wie fürs Ändern (§10): **Ohne Schlüssel gibt es die Zelleinzelsicht
nicht**, und die Kürzung in der Tabelle ist dort endgültig. Das ist eine benannte
Lücke; sie trifft dieselben Tabellen, die ohnehin nur lesbar sind.

**Keine davon geht durch die Warteschlange.** Ein eingereihter Vorgang legt seine
Argumente in `operations.payload` ab — und dort stünde bei `console.row.write`
der Inhalt einer Kundenzeile und bei `console.rows` ein Filterwert. Das ist
dieselbe Regel wie für Passwörter ([36 §4](36-datenbanken.md)) mit einem neuen
Anlass, und sie hat seit P5 einen Wächter (`SecretsStayOutOfTheQueueTest`), der
dafür erweitert wird.

> **Was nicht in der Warteschlange stehen darf, ist nicht nur ein Geheimnis —
> es ist alles, was dem Kunden gehört.**

Alle fünf laufen als unmittelbarer Aufruf (`Client::call`), wie
`db.user.create`. Ein Vorgang mit Fortschrittsanzeige wäre für eine Anzeige, die
in 50 ms fertig ist, die falsche Bauform.

---

## 13. Die Schritte, in dieser Reihenfolge

Kein Schritt beginnt, bevor der vorige grün ist. Jeder ist für sich lieferbar.

### Schritt 0 — Die fünf offenen Messungen (§2.3) ✓

**Erledigt am 12. August 2026**, und nicht auf `cloudsrv24`, sondern hier: Der
Container hatte keinen MariaDB-Server und konnte einen bekommen — 10.11.14 aus
dem Ubuntu-Archiv, dieselbe Fassung wie der Zielserver (§2.3). Zwölf Messungen,
N1 bis N12.

**Zwei davon haben etwas gefunden**, und beide ändern §8: `--batch` maskiert die
Maskierung einer JSON-Zeile und erzeugt gültiges JSON mit falschen Werten (§8.1),
und ein `BLOB` mit ungültigem UTF-8 macht über `json_decode()` die **ganze Zeile**
unlesbar, während MariaDBs `JSON_VALID()` sie für gültig hält (§8.2).

Beides wäre in Schritt 2 als Fehler aufgetaucht — das erste vermutlich gar nicht,
weil es keine Ausnahme wirft, sondern falsche Zeichen liefert.

### Schritt 1 — Der Agent für PostgreSQL ✓

**Erledigt am 12. August 2026.** `Pg\Console` (die Katalogfragen und der Bau der
Anweisungen), die fünf `pg.console.*`-Operationen, `Session::queryAs()`,
`jsonAs()` und `executeAs()`, `Names::ephemeral()` um das `c`-Zeichen erweitert,
`isEphemeral()` mit.

Gemessen gegen den **echten Debian-Cluster** im Container und nicht gegen einen
im Scratchpad: `Pg\Server::require()` fragt `pg_lsclusters`, und ein Cluster,
den Debians Werkzeug nicht kennt, gibt es für den Agenten nicht. Einundfünfzig
Behauptungen, alle grün — darunter die vier Werte aus Kriterium 2, die Kürzung,
der Filter mit einem Prozentzeichen im Wert, ein Ausbruchsversuch, die fremde
Datenbank und die drei Regeln des Schreibwegs.

**Drei Dinge waren beim Bauen anders als im Plan**, sie stehen in §20.

### Schritt 2 — Derselbe Agent für MariaDB ✓

**Erledigt am 12. August 2026**, und **nicht** auf `cloudsrv24`: Der Container
bekam MariaDB 10.11.14 aus dem Ubuntu-Archiv (§2.3). `Db\Console`, die fünf
`db.console.*`, `Session::queryAs()` und `jsonAs()` mit `--raw`,
`Sql::qualified()`.

Siebenundvierzig Behauptungen, alle grün — **darunter die beiden Funde aus
Schritt 0 im Betrieb**: Der Tabulator steht in der Zelle und nicht als zweite
Spalte daneben (`--raw`), und das `BLOB` kommt als Länge, ohne die Zeile
mitzunehmen.

Vier Unterschiede zur PostgreSQL-Hälfte, jeder mit Grund, stehen in §20.5.
`EngineReachTest` geht ohne Ausnahmeeintrag auf: fünf Paare, fünf Gegenstücke.

### Schritt 3 — Die Anwendung ✓

**Erledigt am 12. August 2026.** `App\Support\Databases\Console`, fünf
Controller-Methoden, fünf Routen mit `can:console,database`,
`DatabasePolicy::console()`, die `can`-Ablage auf der Datenbankseite — und die
zehn Agent-Operationen sind jetzt registriert, weil sie einen Aufrufer haben.

**Alle fünf Griffe sind `POST` und geben JSON zurück**, auch der, der nur die
Tabellenliste holt. Zwei Gründe, und der zweite ist der, der nicht auf der Hand
liegt (§20.7).

Was beim Bauen anders war, steht in §20.7.

### Schritt 4 — Tabellen und Struktur ✓

**Erledigt am 12. August 2026.** `Databases/Console.vue`, die `GET`-Route
`databases.console` als Einstieg, `can.console` in der Nutzlast der
Datenbankseite und der Knopf, der sie liest.

**Und zwei Operationen mehr als geplant** — `pg.console.indexes` und
`db.console.indexes`. §11 verlangt für die Struktur „dazu die Indexe", und die
kannte der Agent nicht; `columns()` führt sie nicht mit, weil diese Abfrage bei
**jedem** Blättern, Filtern und Schreiben läuft und die Indexe nur eine Ansicht
braucht.

> **Was eine Ansicht braucht, gehört nicht in die Abfrage, die alle brauchen.**

**Gemessen gegen beide Systeme**, bevor eine Zeile Oberfläche entstand — und die
PostgreSQL-Abfrage war beim ersten Wurf falsch (§20.10).

**Die Screenshots sind nachgeholt worden** — am 12. August 2026 auf
`cloudsrv24`, acht Bilder in beiden Themes, und sie haben **zwei Fehler
gefunden, die vorher grün waren** (§20.11). Der teuerste schob die Seite bei
390 px um 99 px aus dem Bild; der zweite war überhaupt nicht messbar und nur zu
sehen.

**Beides ist behoben und auf demselben Weg gegengeprüft** — `0.5.3-rc.4` auf
`cloudsrv24`, Strukturansicht bei 390 px:

```
dokument: 0px        (vorher 99px)
scrolls[0]: 0px      Tabellenliste
scrolls[1]: 0px      Spalten
scrolls[2]: 0px      Indexe
```

Drei Rollbehälter statt zwei sind dabei der Beleg für die zweite Hälfte: Spalten
und Indexe stehen wirklich in getrennten Bereichen. **Damit ist Schritt 4
abgeschlossen.**

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

### Schritt 5 — Zeilen, blättern, filtern, sortieren, eine Zelle öffnen ✓

Die dritte Ansicht — der Baustein aus §11 — mit den **drei** Filteroperatoren,
`limit + 1` statt einer Anzahl, und der Zelleinzelsicht dazu (Entscheidung 5,
Punkte 1 bis 3). Screenshots, und die Überlaufmessung **an beiden Stellen**.

Die Zelleinzelsicht gehört hierher und nicht zu Schritt 6: Sie ist der Ausweg aus
der Kürzung und wird gebraucht, sobald die Tabelle Werte zeigt — nicht erst,
sobald jemand sie ändern darf.

**Gebaut am 12. August 2026.** Der Agent konnte alles schon (Schritte 1 bis 3);
neu sind die Oberfläche und drei Regeln in `app.css` — `.rows`, `.filter` und
`.cell-value`. Vier Dinge, die der Plan nicht vorgesehen hatte, stehen in §20.12
bis §20.15; zwei davon hat erst die Messung gefunden, und eine davon hätte keine
Zahl gemeldet.

**Die Messung an beiden Stellen, bei 390 px und 1440 px, in beiden Themes:**

```
zeilen @390  light/dark:   dokument: 0px  |  rollende Zelle: 1517px
zeilen @1440 light/dark:   dokument: 0px  |  rollende Zelle:  431px
gegenprobe @390:           dokument: 510px
```

Die dritte Zeile ist die Gegenprobe: dieselbe Seite mit einem absichtlichen
900-px-Block. Ohne sie wären die beiden Nullen darüber keine Messung.

> **Eine Zahl, die 0 sein soll, braucht daneben eine, die es nicht ist.**

Die zweite Spalte ist die einzige Stelle dieses Panels, an der eine Zahl grösser
als 0 die **richtige** Antwort ist — und genau deshalb hat sie einen Fehler
verdeckt, den erst ein Bild gezeigt hat (§20.13).

**Die Zelleinzelsicht ist ein Bereich und kein Dialog.** Dieses Panel hat keinen
modalen Dialog, und diese Ansicht ist kein Anlass, den ersten einzuführen: Sie
ist eine dritte Auskunft zur offenen Tabelle, genau wie Spalten und Indexe, und
steht deshalb da, wo die auch stehen.

**Der Bildschirmfoto-Durchgang ist gefahren** — am 13. August 2026 auf
`cloudsrv24` gegen `0.5.3-rc.5`, in beiden Systemen, bei rund 375 px. Er hat
**drei Fehler gefunden, und keinen davon ein Test** (§20.16, §20.18, §20.19); ein
vierter betraf die CI und nicht das Panel. Was vorher hier gemessen wurde, war
das gebaute Stylesheet mit dem Markup dieser Ansicht im Container — das
beantwortet die Frage nach dem Überlauf und hat von den drei Funden **keinen**
gesehen.

> **Eine Messung im Container beantwortet die Frage, die sie stellt. Ein Bild
> vom echten Server stellt die, an die niemand gedacht hat.**

Die Messung auf dem Server, an beiden Stellen und mit ihrer Gegenprobe:

```
MESSUNG     dokument:   0px | scrolls[0]: 0px | scrolls[1]: 1299px
GEGENPROBE  dokument: 525px | scrolls[0]: 0px | scrolls[1]: 1299px
DANACH      dokument:   0px | scrolls[0]: 0px | scrolls[1]: 1299px
```

Die dritte Spalte ist die, die am meisten sagt: **Zwei Rollbehälter auf einer
Seite, und nur der richtige rollt.** Die Tabellenliste ist bei dieser Breite ein
Kärtchenstapel und rollt nicht; die Zeilentabelle rollt, wie §11 es verlangt.
Ohne diese Unterscheidung hiesse „scrolls rollt" nur, dass irgendein Behälter
überläuft.

**Belegt sind ausserdem** — je Bild eines: die drei Zustände `NULL`, leere
Zeichenkette und gekürzter Wert nebeneinander (Kriterium 2), der `…`-Knopf **nur**
an der gekürzten Zelle und in beiden Systemen, die Zelleinzelsicht mit Grösse und
ohne „gekürzt" unterhalb der Grenze, der Filter mit `enthält`, die leere
Trefferliste als Satz statt als Leere, und eine Tabelle ohne Schlüssel **ohne**
`…`-Knopf — die benannte Lücke aus §12, jetzt sichtbar.

**Was der Durchgang bewusst nicht beantwortet:** ob eine Tabelle ohne Schlüssel
dem Kunden *sagt*, warum es keinen Weg zum Rest gibt. Der Satz dafür ist
Kriterium 5 und gehört zu Schritt 6; ihn hier halb zu bauen hiesse, ihn zweimal
zu haben.

#### Die Gegenprobe — 13. August 2026, `0.5.3-rc.6` auf `cloudsrv24`

| | vorher | nachher |
|---|---|---|
| Sortierung nach `id` (PostgreSQL) | `1, 10, 100, 101, 102` | **`1, 2, 3, 4, 5`** |
| `anhang` in jeder Zeile (MariaDB) | `binär · 0 B` | **`NULL`** |

Und der Plan derselben Abfrage, an der Tabelle aus dem Durchgang:

```
Subquery Scan on t
  ->  Limit
        ->  Index Only Scan using bestellpositionen_…_pkey on bestellpositionen_… src
```

**Kein `Sort`-Knoten** — und der Planer nimmt sogar bei 120 Zeilen den Index.
Vorher stand dort zwingend `Sort (Sort Key: left((id)::text, 513))` über einem
`Seq Scan`. Damit ist die Zusage der Oberfläche wieder gedeckt, dass die
Sortierung über den Schlüssel läuft, *weil* dort ein Index liegt.

**Zwei Prüfungen sind bewusst unterblieben, und beide aus demselben Grund.**

Ein Blätter-Test von Hand — vor, zurück, Reihenfolge vergleichen — zeigt im
Erfolgsfall dasselbe wie im Fehlerfall: Der Plan wechselt innerhalb weniger
Sekunden nicht. Der Beleg für §20.19 ist die erzwungene Planänderung im
Container (5 doppelt und 25 ausgefallen gegen 0 doppelt), und die steht dort.

Eine neue Überlaufmessung misst die Änderung nicht, sondern folgt aus ihr: Beide
Korrekturen machen die Anzeige ausschliesslich schmaler — `NULL` ist kürzer als
„binär · 0 B", und die richtige Sortierung setzt auf Seite 1 die IDs `1…51`
statt dreistelliger.

> **Eine Prüfung, die im Erfolgsfall dasselbe zeigt wie im Fehlerfall, belegt
> nichts. Eine, deren Ergebnis aus der Änderung folgt, auch nicht.**

**Damit ist Schritt 5 abgeschlossen.**

### Schritt 5b — Die Baumansicht ✓

Der Baum aus §11.1 als Navigation: Tabellen als Zweige, Spalten/Indexe/Zeilen als
Ziele, der Inhalt ab 720 px daneben und darunter, solange es enger ist.

**Gebaut am 13. August 2026.** Die Tabellenliste ist ein `role="tree"` mit
Pfeiltastenbedienung, jeder Zweig eine Tabelle, jedes Blatt eines von drei
Zielen. Der Grundriss ist der **einzige zweispaltige dieses Panels**.

**Die Zahlen, die den Baum begründet haben — jetzt gegen das ausgelieferte
Stylesheet, zwanzig Tabellen, zugeklappt:**

| | 390 px | 1440 px |
|---|---|---|
| als Kärtchenstapel (vorher) | **6072 px** | 881 px |
| als Baum | **929 px** | 803 px |

Der waagerechte Überlauf ist bei jeder gemessenen Breite `0px` — 390, 720, 800,
900 und 1440. Am Arbeitsplatz nehmen sich die beiden nichts; der Unterschied ist
das Telefon, und dort ist er das Sechsfache.

> **Der Baum löst kein Navigationsproblem. Er löst ein Telefonproblem.**

**Drei Dinge sind beim Bauen anders gekommen als geplant** — der Haltepunkt
(§20.21), eine unsichtbare Rückmeldung (§20.22) und die Frage, wohin die vier
Angaben gehen, die der Baum nicht mehr trägt (§20.23).

**Er kommt nach Schritt 5 und vor Schritt 6.** Nach 5, weil er die Zeilenansicht
aufruft und sie vorher nicht existiert; vor 6, weil Schritt 6 sonst auf einem
Grundriss baut, den 5b danach umstellt.

**Belegt wird er mit denselben Zahlen, die ihn begründet haben** — die
senkrechte Länge bei 390 px für zwanzig Tabellen, zugeklappt, gegen die heutige
Form. Und mit Screenshots in beiden Themes, weil der zweite Fund aus Schritt 4
zeigt, dass eine Zahl nicht alles sieht.

### Schritt 6 — Ändern

Anlegen, ändern, löschen; die Schlüsselregel aus §10; die Prüfung auf genau eine
Zeile; **und die drei Regeln des Schreibwegs aus §10.1** — `NULL` als eigener
Zustand, eine gekürzte Zelle gesperrt, nur geänderte Spalten in der Anweisung.

**Er stand zur Debatte als der Schnitt** für den Fall, dass die Stufe kleiner
werden soll (Entscheidung 5); der Betreiber hat ihn drin gelassen. Nach Schritt 5
steht trotzdem etwas Fertiges — die Reihenfolge bleibt so.

### Schritt 7 — Das Protokoll ✓

Die drei ändernden Handlungen ins Audit-Protokoll, ohne Werte — und der
entprellte Eintrag beim Öffnen, einer je Datenbank und Stunde (Entscheidung 5,
Punkt 4).

**Die Entprellung ist der Teil, den ein Test braucht, und nicht der Eintrag.**
Ein Eintrag entsteht sichtbar; eine fehlende Entprellung sieht man erst, wenn
das Protokoll nach einer Woche nur noch aus Konsolenzeilen besteht.

### Schritt 8 — Die Wächter brechen

`tests/waechter-brechen.sh` um die Eingriffe aus §14 erweitert, jeder einmal rot
gesehen.

**Gebaut am 13. August 2026: 51 Eingriffe** zu den Schritten 1 bis 7, jeder mit
seinem Anlass beschriftet. Was beim Fahren herauskam, steht in §20.48.

### Schritt 9 — Der Abnahmelauf (§15)

Auf `cloudsrv24`, beide Systeme, jeder Punkt mit seinem Beleg.

---

## 14. Wächter und ihre Brüche

Zu jeder neuen Regel einer, und jeder wird absichtlich gebrochen.

### 14.1 `ConsoleQueueTest` — der wichtigste

**Regel:** Keine `console.*`-Operation geht durch die Warteschlange; keine trägt
einen Zellenwert oder einen Filterwert in `operations.payload`.

**Bruch:** Eine Konsolenoperation über `RunAgentOperation` einreihen statt über
`Client::call`. Erwartet: rot, und zwar mit dem Namen der Operation.

**Und die Gegenrichtung**, die P5 teuer gelernt hat: Der Wächter sucht **nach dem
Wert** in `operations.payload` und zählt nicht die Vorgänge
([36 §17](36-datenbanken.md), Kriterium 1).

### 14.2 `ConsoleIdentityTest`

**Regel:** Jede Konsolenoperation läuft unter einem befristeten Zugang. Keine
ruft `Session::query()`/`execute()` ohne `Ephemeral::with()` darum herum.

**Bruch:** In einer Konsolenoperation `$this->session->query(...)` direkt
aufrufen. Erwartet: rot.

Das ist die Regel, an der die ganze Mandantentrennung dieser Stufe hängt (§5).
Ohne sie liefe die Abfrage als `root`, und niemandem fiele es auf — das Ergebnis
sähe genau gleich aus.

> **Eine Prüfung, die im Fehlerfall dasselbe sagt wie im Erfolgsfall, belegt
> nichts.** ([37 §6](37-uebergabe-an-p5b.md), Punkt 3.)

### 14.3 `IdentifierLookupTest`

**Regel:** Ein Bezeichner aus einer Anfrage wird gegen den Katalog geprüft, bevor
er maskiert wird (§7).

**Bruch:** In `Console::rows()` die Nachschlageprüfung entfernen und nur
maskieren. Erwartet: rot mit einem Tabellennamen, den es nicht gibt.

### 14.4 `CellLimitTest`

**Regel:** Jede Zellenabfrage trägt die Kürzung, und eine gekürzte Zelle ist als
gekürzt gekennzeichnet.

**Bruch:** Die Kürzung aus einer der beiden Konsolen entfernen. Erwartet: rot —
und der Test misst **an der erzeugten Anweisung** und nicht an einem Ergebnis,
weil es hier keinen MariaDB-Server gibt (dieselbe Bauform wie `PhpIsolationTest`).

### 14.5 `AuditContentTest`

**Regel:** Kein Protokolleintrag der Konsole trägt einen Zellenwert.

**Bruch:** Den geänderten Wert in den Eintrag schreiben. Erwartet: rot.

**Und die zweite Hälfte:** Zwanzig Seitenaufrufe hintereinander erzeugen **einen**
Eintrag und nicht zwanzig (Entscheidung 5, Punkt 4). Bruch: die Entprellung
entfernen. Erwartet: rot — und der Test nennt die Anzahl, weil hier ausnahmsweise
genau sie die Regel ist.

### 14.6 `RowKeyTest`

**Regel:** Ein Schreibvorgang ohne Schlüssel entsteht gar nicht erst, und einer,
der nicht genau eine Zeile trifft, wird zurückgenommen (§10).

**Bruch:** Die Zählung nach dem `UPDATE` entfernen. Erwartet: rot.

### 14.7 `WriteBackTest` — der Zwilling von 14.4

**Regel:** Eine Anweisung enthält nur Spalten, die der Kunde geändert hat; eine
gekürzte Zelle ist nie darunter; `NULL` und `''` sind auf dem Schreibweg zwei
verschiedene Werte (§10.1).

**Bruch:** In `Console::write()` alle Spalten in das `UPDATE` nehmen statt der
geänderten. Erwartet: rot — **und der Wächter misst an der erzeugten Anweisung**,
nicht an einem Ergebnis, denn der Schaden dieser Regel ist gerade der, den man
am Ergebnis nicht sieht: Die Zeile ist danach da, sie sieht richtig aus, und der
Rest einer gekürzten Zelle ist fort.

**Der zweite Bruch:** Ein Feld mit gesetztem `NULL`-Kästchen als leere
Zeichenkette schreiben. Erwartet: rot, und der Test benennt beide Werte — nicht
„der Wert stimmt nicht", sondern `NULL` gegen `''`. Das ist Kriterium 2 des
Abnahmelaufs auf dem Schreibweg, und es hat denselben Grund: **Wer die beiden
gleich behandelt, merkt es an keiner Zählung.**

### 14.8 `ResultEncodingTest` — der Wächter zu den beiden Funden aus Schritt 0

**Regel eins:** Die JSON-Methode für MariaDB ruft mit `--raw`, die bestehende
`query()` **ohne**. Beide Richtungen, denn beide Fehler sind still: `--batch` auf
der JSON-Methode liefert gültiges JSON mit falschen Werten (N1), `--raw` auf
`query()` nimmt der Zeilentrennung ihre Sicherung.

**Bruch:** `--raw` aus der einen entfernen, in die andere setzen. Erwartet: zwei
rote Tests, jeder mit der Argumentliste im Text.

**Regel zwei:** Keine binäre Spalte steht als Wert in der Spaltenliste einer
Konsolenabfrage — sie steht als `OCTET_LENGTH(…)` (§8.2).

**Bruch:** Die Umsetzung auf `OCTET_LENGTH` entfernen. Erwartet: rot **an der
erzeugten Anweisung**, nicht an einem Ergebnis. Der Grund steht in §8.2: Am
Ergebnis sähe man es auch — aber erst, wenn jemand eine Tabelle mit einem `BLOB`
öffnet, und dann als „Malformed UTF-8" ohne jeden Hinweis auf die Ursache.

Beide Regeln sind **Textprüfungen** und brauchen keinen Server; das ist dieselbe
Bauform wie `SiteTemplateTest` und `PhpIsolationTest`, und sie ist hier aus
demselben Grund richtig: Der Standardschutz ist eine Eigenschaft der erzeugten
Zeichenkette.

### 14.9 `TreeSemanticsTest` und `ConsoleFanoutTest` — zu Schritt 5b

**Zwei Regeln, und die zweite ist die teurere.**

`TreeSemanticsTest`: Wo eine Vorlage einen Baum zeichnet, trägt der Behälter
`role="tree"`, jeder Knoten `role="treeitem"` und `aria-expanded`. Ein Baum ohne
diese drei ist für einen Screenreader eine Liste von Knöpfen ohne Zusammenhang —
und das fällt niemandem auf, der ihn sieht.

`ConsoleFanoutTest`: **Kein Weg in der Oberfläche holt die Struktur für mehr als
eine Tabelle in einem Zug.** Jede Katalogfrage legt eine Datenbankrolle an und
räumt sie ab; eine Schleife über die Tabellenliste wären zwanzig davon. Geprüft
wird als Textregel — kein `map`/`for` über die Tabellen, der `columns` oder
`indexes` ruft, und kein Bedienelement mit „alles".

**Gebaut am 13. August 2026, und die Regeln sind feiner geworden als geplant.**

`TreeSemanticsTest` prüft vier Dinge: `role="tree"` am Behälter, mindestens zwei
`treeitem`, **zu jeder `role="group"` genau ein `aria-expanded`** — gezählt und
nicht gesucht, denn dass das Wort irgendwo vorkommt, sagt nichts über den zweiten
Zweig — jedes `<li>` mit `role="none"`, und ein `@keydown` am Baum. Die
Pfeiltastenbedienung ist Teil des Musters und nicht sein Zubehör.

`ConsoleFanoutTest` prüft drei: **Aufklappen stellt keine Anfrage** (die schärfste
Fassung — nicht „sparsam", sondern gar nicht), keine Anfrage in einer Schleife
über die Tabellenliste, und kein Bedienelement, das alles öffnet.

**Sieben Brüche gefahren, alle rot.** Der siebte hat den Wächter dabei selbst
verbessert: „Alles aufklappen" stand als Erklärung in einem Quelltextkommentar,
und der erste Anlauf las ihn mit.

> **Ein Wächter, der Kommentare liest, bestraft das Dokumentieren genau des
> Fehlers, vor dem er schützt.** (Derselbe Fund wie in `PackagingTest`, nur in
> einer anderen Sprache.)

> **Ein Bedienelement, das zwanzig Datenbankrollen anlegt, sieht aus wie ein
> Komfort.**

### 14.10 Zwei Regeln an `MobileLayoutTest` — nachgetragen zu Schritt 5

Beide kommen aus der Messung von Schritt 5 und nicht aus diesem Plan; die
Begründungen stehen in §20.13 und §20.14.

`test_a_value_cell_of_the_rows_view_may_break`: Eine Zelle der Zeilenansicht darf
brechen, keine Regel nimmt ihr das wieder, **und die Vorlage gibt ihr kein
`.ident`**. Drei Behauptungen, weil jede einzeln umgangen werden kann.

`test_the_pager_state_may_break`: Die Angabe zwischen „Zurück" und „Weiter" trägt
kein `white-space: nowrap`. Sie führt in der Konsole eine Zeilennummer, und die
wächst mit dem Bestand.

**Die vier Brüche sind gefahren** — `nowrap` an `.rows .cell`; `overflow-wrap`
dort entfernt; `class="ident"` zurück an die Wertzelle; `nowrap` zurück an
`.pager-state`. Jeder war rot, und jeder mit seiner eigenen Meldung.

### 14.11 `NullDisplayTest` — nachgetragen zu Schritt 5

**Regel:** In der Zeilenansicht wird `NULL` als `NULL` angezeigt, **bevor** ein
Zweig auf den Typ der Spalte sieht. Und die Länge einer binären Spalte fällt
nicht auf einen Ersatzwert zurück.

Der Anlass steht in §20.16: Ein `NULL` in einer `BLOB`-Spalte erschien als
„binär · 0 B". Das ist Kriterium 2, und keine Messung hätte es gemeldet.

**Die Brüche:** die beiden Zweige vertauschen; `?? 0` an die Länge zurückgeben.
Beide sind gefahren, beide waren rot — der erste erst, nachdem die Vertauschung
wirklich in der Datei stand.

> **Ein Bruch, der nicht bricht, prüft nichts.** Der erste Anlauf hat die Datei
> gar nicht verändert, und der Wächter blieb grün — was wie eine Lücke aussah
> und keine war. Wer bricht, sieht danach in die Datei.

### Wächter, die von selbst mitlaufen

`EngineReachTest` (vier neue Paare), `AgentOperationReachTest` (jede neue
Operation braucht einen Aufrufer), `AbilityReachTest` (der Konsolenknopf
erscheint nur, wo die Policy ihn erlaubt — Entscheidung 3),
`RouteAuthorizationTest`, `WordChoiceTest`, `ClassNameTest`, `FieldErrorTest`,
`MobileLayoutTest`,
`TableStyleTest`, `PaginationTest` (wer paginiert, lässt auch blättern).

**Die Untergrenze zählt bei jedem neuen Wächter mit**, dort wo die Regel stehen
*darf* — sonst beisst er beim nächsten Aufräumen zu und wird abgeschaltet.

---

## 15. Das Abnahmekriterium als Befehlsfolge

```
# Voraussetzung: ein Abonnement A mit einer MariaDB- und einer
# PostgreSQL-Datenbank, ein zweites Abonnement B mit je einer —
# UND B GEHOERT EINEM ANDEREN KUNDEN ALS A.
# Der Lauf legt sie NICHT selbst an (docs/35).
#
# HIER STAND NUR „ein zweites Abonnement B", UND DAS REICHT FUER PUNKT 3 (a)
# NICHT. Die Mandantenklammer haengt am Abonnement, die ANMELDUNG aber am
# Konto — und `Tenancy::forAccount()` gibt einem Kunden „alle des eigenen
# Kundenkontos" (`accessibleSubscriptionIds()`). Zwei Abonnements desselben
# Kunden sind fuer das Panel EIN Mandant: Der Aufruf auf B gelaenge, und zwar
# zu Recht. Der Lauf saehe aus wie ein gerissenes Kriterium 3.
#
# > Zwei Abonnements sind nicht zwei Mandanten. Der Mandant ist der Kunde.
#
# Fuer die Punkte 3 (b) und (c) genuegt das zweite ABONNEMENT: Dort haengt die
# Trennung am Praefix und an den Rechten der Datenbank, und beide reisen mit
# dem Abonnement und nicht mit dem Kunden. Nur (a) braucht den zweiten Kunden.
# Gefunden am 13. August 2026 vom Betreiber beim Aufbau des Laufs.
# Jeder Punkt wird ZWEIMAL gefahren — einmal je System.

# 0  DERSELBE BESTAND AUF BEIDEN SEITEN  ← vor allem anderen
#    Der Grund steht in §20.45: Auf cloudsrv24 war `id = 9001` in MariaDB
#    belegt und in PostgreSQL nicht, MariaDB hatte eine Tabelle
#    `ohne_schluessel_lang` mehr, und 16384 Zeilen gegen 120. Der Lauf fährt
#    jeden Punkt zweimal und bezieht genau daraus seine Aussage — über zwei
#    verschiedene Bestände gefahren, sagt er nichts über die Systeme, sondern
#    über die Bestände.
#
#    > Zwei Läufe über zwei verschiedene Bestände sind zwei Messungen und
#    > keine Gegenprobe.
#
#    ERST WEGWERFEN, DANN ANLEGEN. Das `DROP` ist nicht Hygiene, sondern der
#    Punkt: Was hier schiefging, war ein Rest aus einem früheren Lauf.
#
#    UND DAS `DROP` NENNT MEHR, ALS DER BLOCK ANLEGT — gefunden am 13. August
#    2026 beim ersten Lauf dieses Punktes auf cloudsrv24. Der erste Wurf warf
#    die sechs Tabellen weg, die er selbst anlegt; die Reste der Messrunden zu
#    Bild 4, 5 und 6 blieben stehen, und einer davon (`umsaetze_je_ort`) gab es
#    nur in MariaDB.
#
#    > Ein `DROP`, das die Tabellen nennt, die man anlegt, räumt nicht den
#    > Bestand auf — es räumt die eigene Spur auf.
#
#    DER TEURERE TEIL DESSELBEN FUNDES: Drei der Reste standen auf BEIDEN
#    Seiten, mit denselben Namen — über ihren INHALT sagte die Prüfung nichts,
#    denn sie zählt nur die Tabellen der Fixture. Ein „identisch" wäre eine
#    Aussage über Namen gewesen und nicht über den Bestand; die Zeile mit der
#    Tabellenliste fand die eine unsymmetrische, eine abweichende Zeilenzahl in
#    einem geteilten Rest wäre durchgegangen.
#
#    > Eine Prüfung, die eine Teilmenge zählt, sagt über den Rest nichts — und
#    > sieht dabei aus, als sagte sie etwas über alles.
#
#    DESHALB IST DER BESTAND GESCHLOSSEN: Jedes Objekt, das dasteht, legt
#    dieser Block an, und jedes Objekt der Liste steht auch in der Zählung. Die
#    lange Kennung aus §20.11 bleibt — sie trägt die 390px-Aufnahme —, aber mit
#    festgelegtem Inhalt statt als Rest.
#
#    UND EINER DER RESTE WAR EINE SICHT — `umsaetze_je_ort`, gefunden im selben
#    Lauf. Das hat gleich dreimal zugeschlagen:
#      - `DROP TABLE` entfernt in PostgreSQL keine Sicht, sondern bricht ab
#        (`ERROR: … is not a table`). Mit `ON_ERROR_STOP=1` — richtig gesetzt —
#        lief der GANZE Block nicht mehr, und weil MariaDB an derselben Stelle
#        nur warnt, sah das Ergebnis nach einem Unterschied im Bestand aus statt
#        nach einer Hälfte, die nie gelaufen ist.
#      - Die Sicht gehört ÜBERHAUPT in die Fixture und nicht in die Reste: §15
#        Punkt 6 verlangt, dass die Oberfläche eine Sicht anders begründet als
#        eine Tabelle ohne Schlüssel („eine Sicht speichert nichts"), und §20.28
#        hängt daran, dass eine Sicht keine Grösse bekommt. Ein Zustand, den der
#        Lauf prüft, darf kein Rest sein.
#      - Und sie hat die Listenabfrage auffliegen lassen (siehe unten).
#
#    > Ein Rest, den der Lauf braucht, ist kein Rest — er ist eine Zusage ohne
#    > Herkunft.
#
#    ── PostgreSQL, als Kunde in der eigenen Datenbank ──
     DROP VIEW IF EXISTS umsaetze_je_ort;
     DROP TABLE IF EXISTS probe, blaettern, gross, ohne_schluessel, nur_unique, lang,
                          ohne_schluessel_lang, protokoll_ohne_schluessel,
                          bestellpositionen_archiv_2026_langer_name_zum_messen;
     CREATE TABLE probe (id int primary key, leer text, nichts text, tab text, umbruch text);
     INSERT INTO probe VALUES (1, '', NULL, e'a\tb', e'z1\nz2');
     CREATE TABLE blaettern (id int primary key, wert text);
     INSERT INTO blaettern SELECT g, 'w' || lpad(g::text, 4, '0') FROM generate_series(1, 120) g;
     CREATE TABLE gross (id int primary key, wert text);
     INSERT INTO gross SELECT g, md5(g::text) FROM generate_series(1, 3000000) g;
     CREATE TABLE ohne_schluessel (a int, b text);
     INSERT INTO ohne_schluessel VALUES (1, 'x'), (1, 'x');
     CREATE TABLE nur_unique (a int NOT NULL, b text NOT NULL);
     CREATE UNIQUE INDEX nur_unique_ab ON nur_unique (a, b);
     INSERT INTO nur_unique VALUES (1, 'x'), (2, 'y');
     CREATE TABLE lang (id int primary key, langtext text, leer text);
     INSERT INTO lang VALUES (1, repeat('a', 5000), NULL);
     CREATE TABLE bestellpositionen_archiv_2026_langer_name_zum_messen (id int primary key, wert text);
     INSERT INTO bestellpositionen_archiv_2026_langer_name_zum_messen VALUES (1, 'a'), (2, 'b'), (3, 'c');
     CREATE VIEW umsaetze_je_ort AS SELECT b AS ort, count(*) AS anzahl FROM ohne_schluessel GROUP BY b;
#
#    ── MariaDB, als Kunde in der eigenen Datenbank ──
     DROP VIEW IF EXISTS umsaetze_je_ort;
     DROP TABLE IF EXISTS probe, blaettern, gross, ohne_schluessel, nur_unique, lang,
                          ohne_schluessel_lang, protokoll_ohne_schluessel,
                          bestellpositionen_archiv_2026_langer_name_zum_messen;
     CREATE TABLE probe (id int primary key, leer text, nichts text, tab text, umbruch text);
     INSERT INTO probe VALUES (1, '', NULL, 'a\tb', 'z1\nz2');
     CREATE TABLE blaettern (id int primary key, wert text);
     INSERT INTO blaettern SELECT seq, CONCAT('w', LPAD(seq, 4, '0')) FROM seq_1_to_120;
     CREATE TABLE gross (id int primary key, wert text);
     INSERT INTO gross SELECT seq, MD5(seq) FROM seq_1_to_3000000;
     CREATE TABLE ohne_schluessel (a int, b text);
     INSERT INTO ohne_schluessel VALUES (1, 'x'), (1, 'x');
     CREATE TABLE nur_unique (a int NOT NULL, b varchar(64) NOT NULL);
     CREATE UNIQUE INDEX nur_unique_ab ON nur_unique (a, b);
     INSERT INTO nur_unique VALUES (1, 'x'), (2, 'y');
     CREATE TABLE lang (id int primary key, langtext text, leer text);
     INSERT INTO lang VALUES (1, REPEAT('a', 5000), NULL);
     CREATE TABLE bestellpositionen_archiv_2026_langer_name_zum_messen (id int primary key, wert text);
     INSERT INTO bestellpositionen_archiv_2026_langer_name_zum_messen VALUES (1, 'a'), (2, 'b'), (3, 'c');
     CREATE VIEW umsaetze_je_ort AS SELECT b AS ort, count(*) AS anzahl FROM ohne_schluessel GROUP BY b;
#
#    DREI UNTERSCHIEDE, UND JEDER IST EINER ZU VIEL, WENN MAN IHN NICHT KENNT:
#      - `e'a\tb'` gegen `'a\tb'` — PostgreSQL braucht das `e`, MariaDB deutet
#        den Gegenschrägstrich von sich aus. Ohne das `e` steht in PostgreSQL
#        ein Backslash und ein t, und Punkt 1 prüft dann eine Zeichenkette
#        statt eines Tabulators.
#      - `generate_series` gegen `seq_1_to_N` (die Sequence-Engine von MariaDB).
#      - `b text NOT NULL` gegen `b varchar(64) NOT NULL` — MariaDB indiziert
#        `TEXT` nicht ohne Längenangabe. Die Spalte trägt zwei Zeichen; die
#        Länge ist nur da, damit der eindeutige Index entsteht, an dem §10
#        Regel 2 hängt.
#
#    UND DANN DER BELEG, DASS SIE JETZT GLEICH SIND. Beide Seiten laufen
#    lassen, die Ausgaben nebeneinanderlegen — sie müssen ZEICHENGLEICH sein:
#
#      psql -A -t -c "SELECT 'objekte ' || string_agg(c.relname || ':' ||
#                              CASE c.relkind WHEN 'v' THEN 'v' ELSE 'r' END,
#                              ',' ORDER BY c.relname COLLATE \"C\")
#                         FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
#                        WHERE n.nspname = 'public' AND c.relkind IN ('r','v')"
#      mysql --batch --skip-column-names
#            -e "SELECT CONCAT('objekte ', GROUP_CONCAT(CONCAT(TABLE_NAME, ':',
#                              IF(TABLE_TYPE='VIEW','v','r')) ORDER BY TABLE_NAME))
#                  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
#
#    BEIDE ZEILEN FRAGEN DASSELBE, UND DER ERSTE WURF TAT DAS NICHT: `pg_tables`
#    listet nur Tabellen, `information_schema.TABLES` listet Tabellen UND
#    Sichten. Die Sicht war auf der PostgreSQL-Seite unsichtbar und auf der
#    MariaDB-Seite sichtbar — die Zeile meldete einen Unterschied, den es nicht
#    gab, und hätte einen echten genauso erzeugt.
#
#    > Eine Gegenprobe über einen anderen Weg als den benutzten prüft den
#    > falschen Weg. (docs/44)
#
#    dann in BEIDEN dieselbe Abfrage (sie ist absichtlich in beiden Systemen
#    dieselbe Zeichenkette):
     SELECT 'archivtabelle' AS t, count(*) AS n FROM bestellpositionen_archiv_2026_langer_name_zum_messen
     UNION ALL SELECT 'blaettern', count(*) FROM blaettern
     UNION ALL SELECT 'gross', count(*) FROM gross
     UNION ALL SELECT 'lang', count(*) FROM lang
     UNION ALL SELECT 'nur_unique', count(*) FROM nur_unique
     UNION ALL SELECT 'ohne_schluessel', count(*) FROM ohne_schluessel
     UNION ALL SELECT 'probe', count(*) FROM probe
     UNION ALL SELECT 'sicht', count(*) FROM umsaetze_je_ort
     UNION ALL SELECT 'probe.leer laenge', length(leer) FROM probe
     UNION ALL SELECT 'probe.nichts', CASE WHEN nichts IS NULL THEN 1 ELSE 0 END FROM probe
     UNION ALL SELECT 'probe.tab zeichen', ascii(substr(tab, 2, 1)) FROM probe
     UNION ALL SELECT 'probe.umbruch zeichen', ascii(substr(umbruch, 3, 1)) FROM probe
     UNION ALL SELECT 'lang.langtext laenge', length(langtext) FROM lang
     UNION ALL SELECT 'lang.leer', CASE WHEN leer IS NULL THEN 1 ELSE 0 END FROM lang
     UNION ALL SELECT 'blaettern erste', min(id) FROM blaettern
     UNION ALL SELECT 'blaettern letzte', max(id) FROM blaettern
     ORDER BY 1;
#
#    erwartet, auf beiden Seiten Zeile für Zeile gleich:
#      objekte bestellpositionen_archiv_2026_langer_name_zum_messen:r,blaettern:r,
#              gross:r,lang:r,nur_unique:r,ohne_schluessel:r,probe:r,umsaetze_je_ort:v
#      archivtabelle 3 / sicht 1
#      blaettern 120 / blaettern erste 1 / blaettern letzte 120
#      gross 3000000 / lang 1 / nur_unique 2 / ohne_schluessel 2 / probe 1
#      lang.langtext laenge 5000 / lang.leer 1
#      probe.leer laenge 0 / probe.nichts 1
#      probe.tab zeichen 9 / probe.umbruch zeichen 10
#
#    WARUM DIE BESCHRIFTUNG `archivtabelle` HEISST UND NICHT `langer name`:
#    Die Zeilen werden mit `ORDER BY 1` sortiert, und ob PostgreSQL und MariaDB
#    eine Zeichenkette mit Punkt und Leerzeichen gleich einsortieren, hängt an
#    der Kollation. Die vierzehn vorhandenen sind gemessen gleich sortiert; eine
#    neue bekommt keinen Namen, dessen Sortierung eine offene Frage ist — sonst
#    sähe eine Kollationsdifferenz aus wie ein Befund über den Bestand.
#
#    WARUM DIE ABFRAGE KEINE NULL UND KEINE LEERE ZELLE AUSGIBT: `psql -A -t`
#    gibt beide als leeres Feld aus (§2). Eine Prüfung, die das Ergebnis über
#    dieses Format vergleicht, könnte NULL und '' nicht unterscheiden — also
#    fragt sie nach `length()` und nach einem `CASE`, und der Tabulator wird
#    als Zeichencode 9 belegt und nicht als Zeichen.
#
#    UND DIE GEGENPROBE ZUR GEGENPROBE, denn ein Vergleich, der nie etwas
#    findet, ist keiner: In einem System eine Tabelle anlegen und eine Zeile
#    einfügen, dann noch einmal vergleichen — beide Abweichungen müssen
#    dastehen. Genau das sind die zwei aus §20.45.
#
#    GEMESSEN am 13. August 2026 gegen PostgreSQL 16.13 und MariaDB 10.11.14
#    (Wegwerf-Server im Container, dieselbe MariaDB-Fassung wie cloudsrv24):
#    beide Blöcke laufen fehlerfrei durch — 19 s und 12,6 s, fast alles davon
#    `gross` —, der Vergleich ist zeichengleich, und die Gegenprobe meldet die
#    hergestellte Abweichung mit Tabellenliste und Zeilenzahl.
#    `nur_unique` gilt dabei in BEIDEN Systemen als schlüsselfähig — geprüft
#    mit dem Prädikat aus Pg\Console::KEY_INDEX und mit COLUMN_KEY = 'PRI'.
#
#    NACHGEMESSEN am 13. August 2026, nach dem ersten Lauf auf cloudsrv24: Die
#    Objektliste liefert auf beiden Systemen dieselbe Zeichenkette, samt `:v`
#    für die Sicht; die Sicht hat auf beiden 1 Zeile; und der Rückbau
#    (`DROP VIEW` vor `DROP TABLE`) läuft zweimal hintereinander ohne Fehler.

# 1  DIE VIER WERTE  ← Kriterium 2
#    Als Kunde von aussen in die eigene Datenbank:
#      CREATE TABLE probe (id int primary key, leer text, nichts text,
#                          tab text, umbruch text);
#      INSERT INTO probe VALUES (1, '', NULL, e'a\tb', e'z1\nz2');
#    Dann im Panel die Tabelle öffnen.
#    erwartet: ALLE VIER unterscheidbar — leer als leere Zelle, NULL als
#              erkennbares NULL, der Tabulator INNERHALB einer Zelle, der
#              Umbruch INNERHALB einer Zelle.
#    Die vier Zellen gehören einzeln ins Protokoll, nicht als „stimmt".
#    Ein Bildschirmfoto ist hier der Beleg und keine Zierde: Der Fehler aus
#    M7 sieht in einer Zählung wie ein Erfolg aus (fünf Spalten, fünf Werte).

# 2  BLÄTTERN UND SORTIEREN
#    Eine Tabelle mit mehr als 50 Zeilen anlegen (generate_series bzw. eine
#    Schleife), im Panel öffnen, auf Seite 2 blättern, absteigend sortieren,
#    filtern.
#    erwartet: Seite 2 beginnt bei Zeile 51; die Sortierung dreht die Reihen-
#              folge; der Filter lässt weniger Zeilen stehen.
#    BELEG: die erste und die letzte Kennung jeder Seite. „Es blättert" ist
#           keine Erfüllung — zwei gleiche Seiten blättern auch.
#    UND: es steht KEINE Trefferzahl da, sondern „mehr als 50" bzw. auf der
#    letzten Seite die genaue Zahl (Entscheidung 5, Punkt 2). Eine Zahl an
#    dieser Stelle ist kein hübscher Zusatz, sondern der count(*), den §9
#    ausgeschlossen hat — sie gehört gesucht und nicht übersehen.
#    Und die drei Operatoren sind drei: ist gleich, enthält, ist leer. Ein
#    vierter im Auswahlfeld ist ein Befund und kein Bonus.

# 2b DER BAUM MIT DER TASTATUR
#    Nachgetragen aus Schritt 5b: Die Pfeiltastenbedienung ist Teil des
#    Musters und nicht sein Zubehör (§11.1). `TreeSemanticsTest` prüft, dass
#    ein `@keydown` am Baum HÄNGT — was es TUT, prüft kein Test dieses
#    Projekts, und im Container gibt es dafür keine laufende Seite.
#
#    In den Baum tabben, dann der Reihe nach:
#      End         → der Fokus steht auf dem letzten SICHTBAREN Knoten
#      ArrowRight  auf einem ZUGEKLAPPTEN Zweig → klappt auf, Fokus BLEIBT
#      ArrowRight  auf dem nun offenen Zweig    → Fokus geht auf „Spalten"
#      ArrowLeft   auf einem Blatt              → Fokus geht auf seinen Zweig
#      ArrowLeft   auf dem offenen Zweig        → klappt zu, Fokus BLEIBT
#    erwartet: genau das, und `aria-expanded` sagt nach jedem Schritt dasselbe
#              wie das Dreieck.
#    BELEG: der Wert von `aria-expanded` und die Adresse des fokussierten
#           Knotens nach JEDEM der fünf Schritte. „Die Pfeiltasten gehen" ist
#           keine Erfüllung — vier der fünf Schritte tun verschiedene Dinge,
#           und zwei davon lassen den Fokus ausdrücklich stehen.
#    Die beiden mittleren sind die, die niemand von selbst probiert:
#    ArrowRight bedeutet auf einem zugeklappten Zweig etwas anderes als auf
#    einem offenen, und genau diese Fallunterscheidung ist bisher ungefahren.

# 3  KEINE FREMDE TABELLE  ← Kriterium 3
#    DREI Wände stehen hier hintereinander, und nur die dritte gehört uns nicht:
#      (a) die Mandantenklammer des Panels (403, bevor der Agent gefragt wird),
#      (b) Names::belongsTo() im Agenten (Pg\Console::within()),
#      (c) die Rechte der befristeten Rolle — die Meldung kommt vom SERVER.
#    Jede wird EINZELN gefahren; der Beleg ist die Herkunft der Meldung.
#
#    (a)  Als Kunde von Abo A die Adresse einer Datenbank von Abo B aufrufen —
#         und B GEHOERT EINEM ANDEREN KUNDEN (siehe Voraussetzung oben).
#         erwartet: 404, ohne dass der Agent gefragt wurde.
#
#         HIER STAND 403, UND DAS IST DIE FALSCHE ERWARTUNG. `Database` traegt
#         `BelongsToSubscription`, und der globale Filter greift VOR der Policy:
#         Die Adressaufloesung (`SubstituteBindings`) findet den Datensatz gar
#         nicht erst, also antwortet Laravel mit 404 und `can:console,database`
#         kommt nie dran. Die 403 stand hier als Vermutung, seit der Punkt
#         geschrieben wurde — gegen den Quelltext gelesen am 13. August 2026.
#
#         DIE 404 IST DABEI DIE SCHAERFERE ANTWORT und kein Mangel: Eine 403
#         sagt „das gibt es, du darfst nicht", eine 404 sagt gar nichts. Wer
#         hier auf 403 besteht, verlangt, dass das Panel die Existenz fremder
#         Datenbanken bestaetigt.
#
#         > Eine Erwartung, die niemand gegen den Code gelesen hat, ist eine
#         > Vermutung mit Aktenzeichen.
#
#         UND DER ZWEITE TEIL DES BELEGS BRAUCHT EINE GEGENPROBE. „Der Agent
#         wurde nicht gefragt" wird an SEINEM PROTOKOLL gemessen, und das ist
#         `/var/log/srvpanel/agent.log` — NDJSON, eine Zeile `request` je
#         Aufruf mit dem Namen der Operation (`Connection::handle()`).
#
#         NICHT `journalctl -u srvpanel-agentd`. Am 13. August 2026 auf
#         cloudsrv24 gemessen: Der Aufruf liefert `-- No entries --`, und zwar
#         AUCH DANN, wenn der Agent gerade gearbeitet hat. Wer damit belegt,
#         dass der Agent nicht gefragt wurde, belegt gar nichts.
#
#         > Ein Beleg, der immer dasselbe sagt, sagt nichts — auch dann, wenn
#         > das, was er sagt, gerade stimmt.
#
#         Gemessen wird am NAMEN und nicht an der Zeilenzahl:
#           → im Browser die EIGENE Konsole von A oeffnen
#           grep -c '"op":"db.console.tables"' /var/log/srvpanel/agent.log
#             MUSS groesser als 0 sein — der positive Fall
#           → im Browser die Adresse einer Datenbank von B aufrufen
#           grep 'console' /var/log/srvpanel/agent.log | grep -c '<B-Datenbank>'
#             MUSS 0 sein — keine KONSOLENoperation hat den Namen je genannt
#
#         UND DAS `grep 'console'` DAVOR GEHOERT DAZU. Der Name allein steht
#         auch dann im Protokoll, wenn die Datenbank ueber das Panel ANGELEGT
#         wurde — das geht durch den Agenten und ist voellig in Ordnung. Der
#         erste Anlauf zaehlte sieben Treffer und keiner davon war ein Leck.
#
#         > Ein Name allein sagt nicht, wer ihn genannt hat. Erst die Frage
#         > nach der Operation trennt „angelegt" von „ausgelesen".
#
#         DIE ZEILENZAHL TAUGT DAFUER NICHT, und der erste Anlauf hat es
#         vorgefuehrt: Zwischen den beiden Messungen kamen acht Zeilen dazu,
#         und keine davon gehoerte zum Versuch — das Protokoll traegt
#         `system.info` und `db.server.info` aus dem Hintergrund. Wer die
#         Differenz liest, liest den Verkehr anderer.
#
#         > Eine Zaehlung ueber einen Kanal, auf dem auch andere sprechen,
#         > misst das Gespraech nicht.
#
#         Der Name haengt dagegen an keinem Zeitfenster: Kommt die fremde
#         Datenbank im ganzen Protokoll nicht vor, hat der Agent sie nie
#         gesehen — egal, wer sonst noch geredet hat. Ohne den positiven Fall
#         davor belegt die 0 allerdings weiterhin nichts.
#    (b)  Am Agenten vorbei an der Anwendung, mit erfundener Nutzlast:
#           Client::call("pg.console.tables", ["prefix" => <A>, "database" =>
#                        <B-Datenbank>, "schema" => "public"])
#         erwartet: „Diese Datenbank gehört nicht zu diesem Abonnement."
#
#         GEFAHREN WIRD DAS MIT `HOME=/tmp srvpanel tinker --execute="…"`, UND
#         DAS `HOME` GEHOERT DAZU. Der Wrapper setzt per `setpriv` auf den
#         Benutzer `srvpanel` um, `HOME` bleibt dabei auf dem des Aufrufers
#         (`/root`), und psysh will dort `.config/psysh` anlegen. Es scheitert
#         mit „Writing to directory .config/psysh is not allowed" — und zwar
#         als blosse `User Notice`: Der Aufruf gibt danach NICHTS aus, weder
#         Ergebnis noch Fehler. Gemessen am 13. August 2026 auf cloudsrv24.
#
#         > Ein Werkzeug, das mit einer Warnung aufhoert, sieht aus wie eins,
#         > das nichts zu sagen hatte.
#
#         Dieselbe Sorte Fall wie in `docs/47`, wo eine Hilfsdatei unter /root
#         nach dem `setpriv` unlesbar war — nur andersherum: dort das Lesen,
#         hier das Schreiben.
#    (c)  An einer Rolle, die den befristeten Zugang nachbaut (CREATE ROLE …
#         IN ROLE srvpanel_restore, <A>_owner; GRANT CONNECT ON <A-Datenbank>),
#         über den SOCKET — den Weg, den Pg\Session::linesAs() geht:
#           psql -h /var/run/postgresql -U <probe> -d <B-Datenbank>
#         erwartet: FATAL: permission denied for database "<B>". Für MariaDB
#         ein Benutzer mit GRANT ALL auf genau eine Datenbank → ERROR 1044.
#
#    HIER STAND „(a) für einen Lauf abschalten, dann muss die Meldung vom Server
#    kommen", UND DAS KANN NICHT FUNKTIONIEREN. Das Präfix reist mit der
#    DATENBANK und nicht mit dem Aufrufer: App\Support\Databases\Console holt es
#    aus $database->subscription. Wer die Klammer abschaltet und die Adresse
#    einer Datenbank von B aufruft, richtet damit nicht die Konsole von A auf B,
#    sondern die von B auf B — der Aufruf GELINGT, und zwar zu Recht. Gefunden
#    beim Ausschreiben von `docs/47`, gegen den Quelltext gelesen.
#
#    > Eine Wand, die man nur erreicht, indem man die davor abschaltet, wird
#    > durch das Abschalten nicht erreicht — sie wird umgangen.
#
#    OHNE (c) IST KRITERIUM 3 NICHT GEFAHREN: Mit (a) und (b) allein wäre
#    belegt, dass unsere Prüfung greift, und genau das ist nicht die Frage.

# 4  DAS ZEITLIMIT  ← Kriterium 4
#    Eine Tabelle mit einigen Millionen Zeilen, dann im Panel nach einer
#    Spalte ohne Index sortieren.
#    erwartet: Abbruch nach 5 s, und der Kunde liest den Grund.
#    BELEG: die Meldung, wörtlich. „Fehlgeschlagen" ist eine Aussage über uns
#           und nicht über den Server (docs/36 §17, Kriterium 6).
#    Und danach:
#      SELECT count(*) FROM pg_stat_activity WHERE state='active';
#    erwartet: die abgebrochene Abfrage rechnet NICHT weiter (M12).

# 5  DER BEFRISTETE ZUGANG  ← Kriterium 1
#    Nach den Punkten 1 bis 4, ohne offene Konsole:
#      SELECT rolname FROM pg_roles
#       WHERE rolname ~ '^x[0-9a-f]{16}_[rc][0-9a-f]{8}$';           → leer
#      SELECT user FROM mysql.user
#       WHERE user REGEXP '^p[0-9]+_[rc][0-9a-f]{8}$';               → leer
#    HIER STAND `LIKE '%\_c%'`, UND DAS KANN NIE LEER SEIN. Gemessen am
#    12. August 2026 auf einem nackten PostgreSQL 16: Das Muster trifft
#    pg_checkpoint, pg_create_subscription und pg_use_reserved_connections.
#    Dieselbe Zeile mit `_r` — so steht sie in docs/38 §19 Punkt 7 — trifft
#    sechs Rollen, darunter srvpanel_restore, das das Panel selbst anlegt.
#    Der Ausdruck oben trifft die Form aus Names::isEphemeral() und sonst
#    nichts.
#    BELEG: DIE ABFRAGE ALLEIN GENÜGT NICHT — sie ist auch dann leer, wenn nie
#           eine Konsole lief. Dazu gehört der Nachweis, dass einer ENTSTANDEN
#           ist: das Journal des Agenten für einen der Läufe aus Punkt 1.
#           Dieselbe Falle wie docs/36 §17 Kriterium 5.

# 6  OHNE SCHLÜSSEL  ← Kriterium 5
#      CREATE TABLE ohne_schluessel (a int, b text);
#      INSERT INTO ohne_schluessel VALUES (1,'x'), (1,'x');
#    Im Panel öffnen.
#    erwartet: die Zeilen sind zu sehen, es gibt keinen Ändern-Knopf, und der
#              Grund steht daneben — mit dem Wort „Primärschlüssel".
#    Der Knopf FEHLT, er ist nicht abgeblendet: AbilityReachTest.

# 7  GENAU EINE ZEILE  ← Kriterium 6
#    In probe eine Zeile ändern.
#    erwartet: die Zeile ist geändert, die anderen nicht — beide Seiten
#              gezählt, vorher und nachher.
#    Dann der Gegenfall, von Hand herbeigeführt: Während die Zeile im Formular
#    offen ist, von aussen den Schlüsselwert auf einen zweiten Datensatz
#    duplizieren (in einer Tabelle mit eindeutigem Index über eine Spalte, die
#    danach ihre Eindeutigkeit verliert), dann speichern.
#    erwartet: der Vorgang wird zurückgenommen und meldet, was er vorfand.
#    Die Zeilen sind danach UNVERÄNDERT — beide.

# 8  DAS PROTOKOLL  ← Kriterium 7
#    Nach Punkt 7:  Protokoll öffnen.
#    erwartet: die ändernden Handlungen stehen da, mit Datenbank, Tabelle und
#              Schlüssel. Die lesenden aus Punkt 2 NICHT.
#    Gegenprobe, und sie ist der eigentliche Punkt:
#      SELECT id FROM audit_events WHERE payload LIKE '%<der neue Wert>%';
#      → 0 Zeilen.
#    Nach dem WERT suchen, nicht die Einträge zählen.
#    UND DIE ENTPRELLUNG, denn sie ist die Hälfte, die still bricht:
#    Zwanzig Seitenaufrufe hintereinander, dann
#      SELECT count(*) FROM audit_events WHERE action LIKE 'db.console%'
#        AND created_at > <Beginn>;
#    erwartet: EINS, nicht zwanzig. Hier ist die Anzahl ausnahmsweise die
#    Regel selbst — sonst gilt in diesem Lauf durchweg das Gegenteil.

# 8b DIE UNBERÜHRTE SPALTE  ← Kriterium 6, zweite Hälfte
#    Eine Tabelle mit einer langen Textspalte (> 512 Zeichen) und einer
#    nullbaren Spalte, die auf NULL steht:
#      CREATE TABLE lang (id int primary key, text text, leer text);
#      INSERT INTO lang VALUES (1, repeat('a', 5000), NULL);
#    Im Panel die Zeile öffnen, NUR id unverändert lassen und NUR eine dritte
#    Spalte ändern — die beiden anderen nicht anfassen. Speichern. Dann:
#      SELECT length(text), leer IS NULL FROM lang WHERE id = 1;
#    erwartet: 5000 und t.
#    OHNE DIESEN SCHRITT IST KRITERIUM 6 HALB GEFAHREN. Er ist der einzige des
#    Laufs, dessen Fehlschlag an der Zeile nicht zu sehen ist: Sie steht da, sie
#    sieht richtig aus, und 4488 Zeichen sind fort.
#    Und die Zelleinzelsicht ist die Gegenprobe dazu, denn ohne sie ist der
#    ganze Wert gar nicht nachzusehen:
#      Zelle `text` öffnen  → 5000 Zeichen, ungekürzt.

# 9  DER RÜCKBAU LÄSST NICHTS LIEGEN
#    Abo A zurückbauen.
#      srvpanel db   → „Nichts liegengeblieben."
#    Die Konsole legt nichts an, was sie überlebt — dieser Punkt belegt es,
#    statt es anzunehmen.
```

---

## 16. Was P5c ausdrücklich nicht wird

Diese Liste ist so wichtig wie der Umfang, und jeder Eintrag hat einen Grund.

- **Kein freies SQL.** Entscheidung 2. Es ist der Unterschied zwischen einer
  typisierten Operation und einem Text, der zu einer Anweisung wird — und damit
  der Unterschied zwischen diesem Plan und einem Umbau der ersten Grenze.
- **Kein Adminer, kein phpMyAdmin, kein fremder Code.** Der Grund aus
  [36 §13](36-datenbanken.md) gilt unverändert; er ist der Anlass dieser Stufe
  und nicht ihr Gegner.
- **Keine Struktur ändern.** Kein `CREATE TABLE`, kein `ALTER TABLE`, kein
  `DROP`. Wer eine Tabelle anlegt, tut das aus seiner Anwendung heraus oder über
  einen Dump. Eine Oberfläche für Spaltentypen zweier Datenbanksysteme ist eine
  eigene Stufe, und sie hat den grössten Abstand zwischen den beiden Systemen.
- **Kein Export und kein Import über die Konsole.** Beides gibt es seit P5 als
  Sicherung, mit Grössenbegrenzung, Vorgang und befristetem Zugang. Ein zweiter
  Weg dorthin wäre eine zweite Fassung derselben Regel.
- **Kein Zugang für den Betreiber.** Entscheidung 3.
- **Keine Navigation über Fremdschlüssel**, keine Suche über mehrere Tabellen,
  keine gespeicherten Ansichten. Das ist die Grenze zwischen „hineinsehen" und
  „ein zweites Werkzeug". **Der Baum aus §11.1 ändert daran nichts:** Er führt
  Tabelle und Struktur, nicht Beziehungen zwischen Tabellen — und er hat keine
  Datenblätter, sondern Ziele.
- **Keine Transaktion über mehrere Schritte.** Der befristete Zugang lebt eine
  Anfrage lang; eine Transaktion, die länger lebt, hielte eine Sperre auf einer
  Kundentabelle über eine Bedienpause hinweg.
- **Kein Cursor.** Aus demselben Grund. Der Preis ist das langsame Blättern bei
  grossem `OFFSET` (§18).
- **Keine Erweiterungen.** `CREATE EXTENSION` bleibt offen, wie
  [38 §5](38-postgresql.md) es benannt hat.
- **Keine optimistische Sperre** (Entscheidung 5, Punkt 5). Zwei Personen an
  derselben Zeile überschreiben einander; die Regel „nur geänderte Spalten" aus
  §10.1 hält den Schaden auf die Spalten begrenzt, an denen beide gearbeitet
  haben. Steht als Risiko 7 in §18 — benannt, nicht gebaut.
- **Keine Anzahl der Treffer unter einem Filter** (Entscheidung 5, Punkt 2), und
  damit auch keine Seitenzahlen in der Blätterleiste. Der Grund steht in §9: Die
  Zahl kostet bei jedem Aufruf, gebraucht wird sie selten.
- **Fünf der acht Filteroperatoren** (Entscheidung 5, Punkt 1). `≠`,
  `beginnt mit`, `ist nicht NULL`, `<` und `>` kommen dazu, wenn jemand die drei
  benutzt hat — und dann mit einem Wächter über der Maskierung jedes einzelnen.

---

## 17. Was aus P5b offen bleibt

Unverändert die zwei Punkte aus [42 §5](42-abnahme-p5b.md), und P5c fasst sie
nicht an: der `template1`-Beleg, und ob ein Zugang ohne jede Datenbank überhaupt
entstehen kann. Beide sind nie gemessen worden. Wer sie anfasst, fängt dort an
und nicht bei null.

---

## 18. Risiken, ehrlich benannt

1. ~~**Die MariaDB-Hälfte von §8 ist nicht gemessen.**~~ **Gemessen am
   12. August 2026** (§2.3, N1 bis N12). Der Verdacht war richtig: `--batch`
   maskiert die Maskierung. Die Antwort ist `--raw --batch` und keine
   Base64-Kodierung — §8.1. Damit ist das grösste Einzelrisiko dieser Stufe fort,
   und das zweite, das dabei zum Vorschein kam, ebenfalls benannt (§8.2).
2. **Blättern mit grossem `OFFSET` ist langsam** und wird es bleiben. Bei einer
   Tabelle mit Millionen Zeilen ist Seite 20 000 eine Abfrage, die ins Zeitlimit
   läuft. Die Oberfläche sagt das dann — sie tut nicht so, als gäbe es die
   Grenze nicht.
3. **Die Zeilenschätzung kann weit danebenliegen.** Sie steht als Schätzung da
   und nicht als Zahl mit Nachkommastelle.
4. **Ein befristeter Zugang je Anfrage schreibt in den Rollenkatalog.** Bei
   PostgreSQL sind das gemessen 11,2 ms; bei MariaDB kostet `CREATE USER`
   zusätzlich ein Neuladen der Rechtetabellen, und **wie viel, ist nicht
   gemessen** (§2.3 nennt es nicht, weil es kein Kriterium ist — hier steht es
   als Risiko). Fällt es ins Gewicht, ist die Antwort ein Zugang je Sitzung mit
   Ablage — also eine Entscheidung, die dem Betreiber noch einmal vorzulegen
   wäre, und keine, die beim Bauen nebenbei fällt.
5. **Die Konsole zeigt alles, was dem Abonnement gehört** — auch Tabellen, die
   ein einzelner Datenbankzugang nicht sehen dürfte (§6). Das ist gewollt und
   benannt, damit es niemand später für einen Fehler hält.
6. **Ein Kunde kann seine eigenen Daten über die Konsole beschädigen.** Ein
   `DELETE` auf die falsche Zeile ist eine Handlung und kein Fehler; das Panel
   fragt zurück ([20 §7](20-hostingpanel-neuplan.md)) und hat für den Rest die
   Sicherungen aus P5.
8. **Die Form einer befristeten Kennung ist breiter geworden.** `[rc]` statt
   `r` reserviert mehr Namen, als es vorher tat (§20.2) — richtig für alles, was
   ab jetzt entsteht, und **rückwirkend nicht**: Ein Zugang, der vor dieser
   Fassung `c` plus acht Hexziffern hiess, gilt ab jetzt als Rest und würde vom
   Aufräumlauf eingesammelt. Vor der Auslieferung gehört einmal nachgesehen:
   `SELECT rolname FROM pg_roles WHERE rolname ~ '^x[0-9a-f]{16}_c[0-9a-f]{8}$'`
   und das Gegenstück in `mysql.user`. Erwartet wird leer; ist es das nicht, ist
   das ein Kundenzugang und kein Rest.
7. **Es gibt keine optimistische Sperre.** Zwei Personen, die dieselbe Zeile
   gleichzeitig öffnen, überschreiben einander, und §10 fängt das nicht — die
   Zeile wird ja getroffen. Die Regel „nur geänderte Spalten" aus §10.1 hält den
   Schaden auf die Spalten begrenzt, an denen beide gearbeitet haben. Gebaut wird
   sie nicht (§3a Punkt 5); benannt ist sie hier, damit die Entscheidung eine
   war.

---

## 19. Umfang

| | |
|---|---|
| Agent | `Db\Console`, `Pg\Console`, zehn Operationen |
| Anwendung | `Console`, Controller, Policy-Methode, Routen |
| Oberfläche | drei Ansichten, eine Zelleinzelsicht und der Navigationsbaum (§11.1) unter `Pages/Databases/` |
| Wächter | zehn neue, dreizehn Brüche |
| Migrationen | **keine** — die Konsole führt keinen Zustand |
| Positivliste | **keine Erweiterung** — `psql` und `mysql` stehen seit P5/P5b |
| Paketabhängigkeiten | **keine Erweiterung** (Entscheidung 1) |

Dass die letzten drei Zeilen leer sind, ist die eigentliche Aussage dieses
Plans: **P5c fügt dem System keinen neuen Weg mit Rechten hinzu.** Es benutzt
den, unter dem seit P5 fremde Dumps laufen.

Geschätzt 2–3 Wochen, im Zuschnitt von P5b — plus rund drei Tage für Schritt 5b,
der am 12. August dazugekommen ist und im ursprünglichen Zuschnitt nicht stand.

---

## 20. Umsetzung — was beim Bauen anders war als im Plan

### 20.1 Ein Prozentzeichen, das zwei Herren dient

`Console::writeStatement()` baut den `DO`-Block mit `sprintf()`, und der Block
enthält `RAISE EXCEPTION '… hat % Zeilen getroffen …', getroffen`. **Das `%` ist
in `RAISE` der Platzhalter für die Zahl und in `sprintf()` der Platzhalter für
das nächste Argument** — PHP zählte zwei und bekam eins.

Der Fehler heisst `ArgumentCountError` und nennt eine Zeilennummer in
`Console.php`, nicht das Prozentzeichen. `php -l` sieht ihn nicht, weil eine
Formatzeichenkette erst zur Laufzeit gezählt wird; gefunden hat ihn der erste
Lauf gegen einen echten Cluster.

> **Eine Zeichenkette, die zwei Sprachen gleichzeitig liest, ist an jeder Stelle
> zweideutig, an der beide dasselbe Zeichen benutzen.**

### 20.2 Die befristete Kennung braucht ein Zeichen mehr, und das kostet etwas

`Names::ephemeral()` erzeugte `<präfix>_r<8 hex>`, und `suffix()` sperrt genau
diese Form, damit kein Kunde einen Zugang bekommt, den der Aufräumlauf nach einer
Stunde für einen Rest hält. Die Konsole bekommt `c` — und damit wird aus dem
Muster `[rc]`.

**Das reserviert mehr Kundennamen als vorher**, und rückwirkend gilt es nicht:
Ein Zugang, der heute `c1234abcd` heisst, ist ab dieser Fassung ein Rest. Der
Preis ist klein und er ist keiner, den man nachträglich merkt — deshalb steht er
als Risiko 8 in §18, mit der Abfrage, die vor der Auslieferung einmal zu fahren
ist.

### 20.3 Jede Zelle ausser einer binären kommt als Text

Die Spaltenliste castet mit `::text`, damit `left()` auf jedem Typ arbeitet und
die Kürzung überall gilt. Die Folge steht nirgends im Plan und gehört gewusst:
**Eine `integer`-Spalte kommt als `"3"` an und nicht als `3`.** Nur eine binäre
Spalte trägt eine Zahl, und die ist ihre Länge.

Das ist richtig so — die Anzeige braucht Text, und der Schlüssel geht als Text
zurück, also gibt es genau eine Fassung eines Werts statt zweier. Es ist nur
nicht selbstverständlich, und ein Test, der `=== 3` erwartet, ist rot, ohne dass
etwas kaputt wäre. Genau das ist beim ersten Lauf passiert.

### 20.4 Der Abnahmelauf von P5b trug eine Erwartung, die nie eintreten konnte

Beim Nachbauen von Kriterium 1 fiel auf, dass die Abfrage nach Resten so nicht
stimmen kann. `docs/38 §19` Punkt 7 schreibt:

```
SELECT rolname FROM pg_roles WHERE rolname LIKE '%\_r%';  → 0 Zeilen.
```

Gemessen am 12. August 2026 auf einem nackten PostgreSQL 16 trifft das Muster
**sechs** Rollen: `pg_read_all_data`, `pg_read_all_settings`, `pg_read_all_stats`,
`pg_read_server_files`, `pg_use_reserved_connections` — und `srvpanel_restore`,
das das Panel für genau dieses Zurückspielen anlegt. Mit `_c` sind es drei.

Das Kriterium ist richtig, die Abfrage war es nicht. Beide Fassungen sind
berichtigt (`docs/38 §19`, `docs/46 §15`) und fragen jetzt nach der Form aus
`Names::isEphemeral()`.

> **Ein Abnahmeschritt, dessen Erwartung nie eintreten kann, wird beim Fahren
> stillschweigend umgedeutet** — und ab da prüft er nichts mehr.

### 20.5 Zehn Operationen ohne Aufrufer — der Fehler, vor dem der Code warnt

Schritt 1 und 2 haben die zehn `*.console.*`-Operationen in `Registry` **und**
in die Registratur eingetragen. Einen Aufrufer bekommen sie erst in Schritt 3.
`AgentOperationReachTest` verlangt zu jeder Operation einen — *Code, der als
root läuft und zu dem kein Weg führt, ist Angriffsfläche ohne Nutzen* —, und
damit wäre die CI rot gewesen.

**Der Kommentar direkt darüber beschreibt genau diesen Fehler**, mit demselben
Ausgang, aus P5b: „Der erste Anlauf hat `pg.server.info` schon in Schritt 1
registriert und die CI rot gemacht." Ich habe ihn beim Schreiben gelesen und
beim Registrieren nicht angewandt.

> **Eine Warnung, die neben der Zeile steht, hält niemanden auf, der sie beim
> Lesen versteht und beim Schreiben vergisst.** Was aufhält, ist der Test — und
> der lief hier nicht, weil `vendor/` fehlt.

Die Klassen bleiben liegen, die zehn `register()`-Zeilen kommen erst mit
Schritt 3 zurück. Auf die Messungen hat das keinen Einfluss: Die Wegwerf-Läufe
haben die Operationen unmittelbar erzeugt und nicht über die Registratur geholt
— was bequem war und die Lücke zugleich verdeckt hat.

### 20.6 Vier Unterschiede zwischen den beiden Konsolen

Der Plan sagt „dasselbe für MariaDB". Vier Stellen sind es nicht, und jede hat
ihren Grund im System und nicht im Geschmack.

**1. `--raw`.** Der Fund aus Schritt 0 (§8.1), hier gebaut: `jsonAs()` ruft mit
`--raw`, `query()` ohne. Beide Richtungen haben einen Wächter, weil beide Fehler
still sind.

**2. Kein anonymer Block, dafür `LIMIT 1`.** PostgreSQL bekommt einen `DO`-Block
mit `GET DIAGNOSTICS` und `RAISE EXCEPTION`; **MariaDB kennt keinen anonymen
Block ausserhalb einer gespeicherten Routine.** Eine Prozedur dafür anzulegen
hiesse, ein Ding zu bauen, das den Lauf überlebt und aufgeräumt werden muss —
genau die Sorte Rest, die dieses Projekt sonst einsammelt.

An seine Stelle treten zwei Dinge, die zusammen dasselbe leisten: **`LIMIT 1`**
macht „mehr als eine Zeile" unmöglich — MariaDB erlaubt es an `UPDATE` und
`DELETE`, PostgreSQL nicht —, und **`ROW_COUNT()`** in derselben Verbindung sagt,
ob es null waren. Die Meldung lautet wörtlich wie die aus dem Block.

> **Zwei Systeme dürfen dieselbe Zusage auf zwei Wegen halten. Sie dürfen sie
> nicht auf einem halten und auf dem anderen behaupten.**

**3. Es gibt kein Schema neben der Datenbank.** In MariaDB *ist* die Datenbank
das Schema. Die Operationen tragen das Feld trotzdem, damit die Anwendung
**eine** Frage für beide Systeme baut; `Db\Console::schema()` besteht darauf,
dass es die Datenbank selbst nennt. Ein anderer Wert wäre kein Fehler des
Kunden, sondern einer im Panel — und er soll auffallen, statt still ignoriert zu
werden.

**4. Das Präfix heisst `prefix` und nicht `user`.** Die älteren `db.*`-Operationen
aus P5 nennen es `user`, die `pg.*` aus P5b `prefix`. Die fünf Konsolengriffe
sind für beide Systeme zusammen entworfen und nehmen **beide** `prefix`, damit
die Anwendung eine Nutzlast baut statt zweier, die sich in einem Feldnamen
unterscheiden. Die alten bleiben, wie sie sind: Sie umzubenennen wäre eine
Änderung an einer Schnittstelle, über die Vorgänge in der Warteschlange liegen
können (`docs/19 §4a`).

**Und eine Kleinigkeit, die keine ist:** `TABLE_TYPE` heisst `BASE TABLE`,
`relkind` heisst `r`. Keines der beiden Wörter gehört in eine Vue-Datei, also
übersetzen beide Konsolen in `table` und `view` (`Pg\Console::KINDS`,
`Db\Console::KINDS`). Sonst stünde die Fallunterscheidung in der Oberfläche —
und damit die dritte Fassung einer Regel, die es zweimal gibt.

### 20.7 Ein zusammengesetzter Operationsname hat keinen Aufrufer

`EngineDriver::consoleOperation()` machte im ersten Wurf aus dem Griff `rows` den
Namen `'db.console.'.$handle`. Das ist kürzer, es funktioniert — und es hat die
CI rot gemacht, aus einem Grund, der über diese Stufe hinausgeht.

**`AgentOperationReachTest` sucht den Namen als Zeichenkette unter `app/`.** Er
fragt nicht „gibt es Code, der das aufruft", sondern *„führt ein Weg dorthin?"*,
und die Antwort auf eine zusammengesetzte Zeichenkette ist Nein: Der Name steht
im Quelltext nirgends. Der Test hat recht — ein Tippfehler in einem der fünf
Griffe fiele erst auf, wenn ein Kunde die Konsole öffnet.

**Die zweite Hälfte des Wächters ist dabei die wichtigere**, und sie gibt es,
weil dieses Projekt die Lücke schon einmal bezahlt hat: `db.user.grant` hatte
seit P5 einen Eintrag in der Ausnahmeliste, eine fertige Methode und **keinen
einzigen Aufrufer** — drei Monate lang, gefunden von einer Frage des Betreibers
und nicht vom Test (`docs/36 §22.3o`). Wer erklärt, dass ein Dienst unmittelbar
aufruft, muss zeigen, dass es diesen Dienst gibt. Eine zusammengesetzte
Zeichenkette macht die Erklärung zu einer Behauptung.

Die fünf Griffe stehen deshalb in jedem Treiber **ausgeschrieben**, als
`CONSOLE`-Zuordnung; ein unbekannter Griff wirft. Dieselbe Überlegung wie bei
`Runner::PROGRAMS`:

> **Aus einem Wert einen Namen zu bauen ist der Vorgang, den eine Positivliste
> verhindert.**

Dazu zehn Einträge in `WITHOUT_LIFECYCLE`, jeder mit demselben Grund: Ein
eingereihter Vorgang legte einen Filterwert oder den Inhalt einer Kundenzeile in
`operations.payload` ab.

### 20.8 Eine Fähigkeit, die niemand abfragt, ist auch ein Fehler

Schritt 3 hat `can.console` schon in die Nutzlast der Datenbankseite gelegt —
die Fähigkeit gibt es ja, und der Knopf käme mit Schritt 4. **`AbilityReachTest`
prüft aber beide Richtungen**, und die zweite hatte ich beim Lesen übersehen:

> Eine Fahne, die die Seite abfragt und niemand schickt, ist in Vue `undefined`
> — der Knopf verschwindet dann für **alle**, ohne dass etwas meldet. Eine, die
> geschickt wird und die niemand abfragt, ist eine **Zusage ins Leere.** Beides
> ist dasselbe Muster: eine Zeichenkette, die auf etwas verweist, das es nicht
> gibt.

Der Satz stimmt, und die Regel ist strenger als sie aussieht: **Die Fahne kommt
mit dem Knopf, der sie liest, und keinen Beitrag früher.** Das ist keine
Förmlichkeit — eine Nutzlast, die Fähigkeiten auf Vorrat trägt, sagt nach
einigen Beiträgen nichts mehr darüber, was die Seite wirklich anbietet.

**Und die Lehre über den Fall hinaus:** Ich hatte den Wächter gelesen, den
Namen der ersten Testmethode für seine ganze Aussage gehalten und daraus
geschlossen, ein zusätzlicher Schlüssel sei harmlos. Er war es nicht.

> **Ein Wächter sagt, was er prüft, in seinen Behauptungen — nicht im Namen
> seiner ersten Methode.**

### 20.9 Fünfmal `POST`, und der Einstieg ist noch keine Seite

**Vier der fünf Griffe müssen `POST` sein, und der Grund ist der Inhalt.** Ein
Filterwert und ein Zeilenschlüssel gehören nicht in eine Adresse: Dort stünden
sie im Zugriffsprotokoll des Webservers, in der Verlaufsliste des Browsers und
in jedem `Referer`, den die Seite danach schickt. Das ist dieselbe Überlegung,
aus der sie nicht in `operations.payload` dürfen (§12) — nur eine Schicht weiter
aussen, und dort ist sie noch leichter zu übersehen.

**Der fünfte ist es aus einem schwächeren Grund**, und der gehört benannt: Er
holt die Tabellenliste und trägt nichts, was nicht ohnehin in der Adresse steht.
Er ist `POST`, damit die fünf zusammenbleiben — eine Bauform, die für einen von
fünf abweicht, muss später jemand erklären, und beim Erklären wird sie
angeglichen, wahrscheinlich in die falsche Richtung.

**Und er ist noch keine Seite.** Der Plan sieht für Schritt 3 ausdrücklich keine
Oberfläche vor; ein `Inertia::render('Databases/Console')` bräuchte aber die
Vue-Datei, und `InertiaPagesTest` besteht zu Recht darauf, dass es sie gibt. Der
Einstieg bleibt deshalb bis Schritt 4 ein JSON-Griff und wird dort zur
`GET`-Route mit einer Ansicht.

> **Ein Schritt, der seine Grenze einhält, muss sie manchmal an einer Stelle
> ziehen, an der sie unbequem liegt.**

**Dazu zwei Kleinigkeiten, die sonst niemand aufschriebe.** Für PostgreSQL ist
`schema` immer `public` — das Panel legt keine weiteren Schemata an, und ein
Schema, das ein mitgebrachter Dump angelegt hat, ist über die Konsole nicht
erreichbar. Es zu übernehmen hiesse, einen Bezeichner aus der Anfrage in die
Nutzlast zu legen, den niemand nachgeschlagen hat. Und `values` geht **ohne**
`array_filter` durch: Es würfe genau die Spalten weg, die auf `NULL` gesetzt
werden sollen (§10.1).

### 20.10 Die Indexabfrage zählte in zwei Systemen verschieden — und beide waren richtig

`Pg\Console::indexesQuery()` holte die Spalten eines Index über

```sql
SELECT pg_get_indexdef(ix.indexrelid, k.n, true)
  FROM generate_subscripts(ix.indkey, 1) AS k(n)
```

Gemessen am 12. August 2026 gegen PostgreSQL 16 gab das für einen Index über
`(ort, name)`:

```
kunde_ort_name | f | f | CREATE INDEX kunde_ort_name ON kunde USING btree (ort, name), ort
```

**`indkey` ist ein `int2vector` und zählt ab 0**, `pg_get_indexdef(oid, colno, …)`
zählt **ab 1**, und `colno = 0` bedeutet dort „die ganze Definition". In der
Spaltenliste stand deshalb ein vollständiges `CREATE INDEX …`, und die **letzte
Spalte fehlte**.

> **Zwei Zählweisen im selben Ausdruck, und keine der beiden ist falsch — falsch
> ist, sie füreinander zu halten.**

Es steht jetzt `generate_series(1, ix.indnkeyatts)` da. `indnkeyatts` statt
`indnatts` lässt die `INCLUDE`-Spalten weg: Sie stehen im Index, aber die
Sortierung folgt ihnen nicht, und genau danach sieht hier jemand.

**Vier Fälle sind danach gegen einen echten Cluster gemessen worden** —
Primärschlüssel, eindeutiger Index, Index über einem Ausdruck (`lower(name)`),
mehrspaltiger Index, Index mit `INCLUDE`, und eine Tabelle ohne jeden Index. Für
MariaDB dasselbe gegen 10.11.14 im Container.

**Und ein Unterschied, den man einmal falsch herum liest:**
`information_schema.STATISTICS.NON_UNIQUE` ist **`0` für eindeutig**. Die Spalte
fragt nach dem Gegenteil dessen, was in der Antwort steht.


### 20.11 Der Bildschirmfoto-Durchgang hat zwei Fehler gefunden, die grün waren

**12. August 2026 auf `cloudsrv24`**, acht Bilder: Tabellenliste und Struktur, je
bei Arbeitsplatzbreite und 390 px, je hell und dunkel. Der Container konnte davon
nichts zeigen — ohne `vendor/` läuft keine Seite —, und beide Funde wären ohne
diese Runde ausgeliefert worden.

**Der erste schob die Seite.** Der Bereichstitel lautete
`Struktur — {{ openTable }}`, und ein Tabellenname darf 63 Zeichen lang sein:

```
dokument: 99px      ← Strukturansicht offen
dokument:  0px      ← nur die Tabellenliste
```

Zwei Ursachen lagen übereinander, und im Nachbau lässt sich jede einzeln
abschalten (390 px, gebautes Stylesheet, Chromium):

| Aufbau | CSS | Dokument |
|---|---|---|
| Name im **Titel** | ohne `overflow-wrap`/`min-width` | **152 px** |
| Name im Titel | mit | 0 px |
| Name als `.ident` | ohne | 0 px |
| Name als `.ident` | mit | 0 px |

Behoben wurden **beide**: Der Name steht jetzt als `.ident` unter einer kurzen
Überschrift — dort darf er brechen und erscheint in der Schrift, die für
Kennungen vorgesehen ist (`docs/19`) —, und `.section-head h2` bekommt
`overflow-wrap: anywhere` **mit** `min-width: 0`, weil ein Flexkind sonst nicht
unter seine Inhaltsbreite darf. Das ist die **dritte** Fassung derselben
Ausnahme nach `.ident` und `.stacks td .ident`; deshalb rechnet
`MobileLayoutTest::test_a_section_heading_can_break` sie seitdem nach.

Die CSS-Hälfte bleibt, obwohl die Markup-Hälfte allein genügt hätte:
`CustomerOverview.vue` setzt `:title="abo.name"` und trägt denselben latenten
Fehler.

**Der zweite war überhaupt nicht messbar.** Spalten- und Indextabelle standen
ohne Abstand und ohne Beschriftung untereinander — auf die Zeile `anhang` folgte
unmittelbar der Kopf `INDEX | SPALTEN | ART`, und beide lasen sich als **eine**
Tabelle. Nichts lief über, nichts war abgeschnitten; es sah nur falsch aus. Sie
stehen jetzt in zwei Bereichen, „Spalten" und „Indexe".

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

**Und eine Korrektur an mir selbst:** Ich hatte in den Quelltextkommentar
geschrieben, der Titel werde *geschnitten* und die Überlaufmessung sei dabei
grün. Gemessen hatte ich das nicht; die Zahl vom Server sagte 99 px. Der
Kommentar steht jetzt richtig da.

> **Ein Fix ohne Nachmessung ist eine Behauptung.** Gegengeprüft wurde auf
> demselben Weg, an derselben Tabelle, mit derselben Zeile in der Konsole:
> `dokument: 0px`.

### 20.12 Die Zelleinzelsicht ist ein Bereich, weil es keinen Dialog gibt

§11 nennt sie „eine Zelle — der ganze Wert einer einzelnen Zelle" und sagt nicht,
in welcher Bauform. Die naheliegende wäre ein modaler Dialog gewesen; **dieses
Panel hat keinen**, und Schritt 5 war kein Anlass, den ersten einzuführen.

Ein Dialog bringt Dinge mit, die keine einzige Seite hier bisher braucht: eine
Falle für die Tastatur, ein Ziel für die Rückgabe des Fokus, eine Abdeckung, ein
`Esc`, und die Frage, was beim Rollen darunter passiert. Fünf Regeln für eine
Ansicht, die dasselbe zeigt wie Spalten und Indexe — eine Auskunft zur offenen
Tabelle.

> **Ein neues Bedienmuster ist teurer als die Ansicht, für die es kommt.**

Dieselbe Überlegung steht schon in §11.1 unter „Drei Entscheidungen": Der
zweispaltige Grundriss ist deshalb ein eigener Schritt und kein Zusatz.

### 20.13 Der Fund, den keine Zahl gemeldet hat — und der Grund dafür

**Die Messung war grün, und die Ansicht war kaputt.** `dokument: 0px`, der
Rollbehälter rollte — genau wie §11 es verlangt. Erst ein Bildschirmfoto des
*gerollten* Zustands zeigte, was los war: Eine bei 512 Zeichen gekürzte Textzelle
machte den Inhalt der Tabelle **5710 px** breit statt 1907. Bei 390 px sind das
zehn Bildschirme Rollen durch eine einzige Zelle.

> **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine Zahl,
> die sich beschwert.**

Das ist die Umkehrung des Satzes aus §20.11. Dort hatte eine Zahl gemeldet, was
ein Bild nicht erklärte; hier erklärt ein Bild, was keine Zahl melden konnte —
weil die einzige Zahl, die hier misst, absichtlich grösser als 0 sein darf.

**Die Ursache stand seit dem Optik-Rework in `app.css`:**

```css
td .ident, th .ident, td.ident, th.ident { white-space: nowrap; }
```

Diese Regel hat eine ausführliche Begründung, und sie stimmt — für Kennungen: Ein
Pfad, der mitten im Verzeichnisnamen umbricht, ist schwerer zu lesen als einer,
für den man die Tabelle schiebt, und schieben kann man dort. Für einen
**Kundenwert** von 512 Zeichen stimmt sie nicht.

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**

Derselbe Satz wie bei `psql -A -t -F'\t'` in §2 — dort für die Ausgabe des
Servers, hier für die Anzeige im Browser. Beide Male hat eine Form, die zwei
Stufen lang richtig war, den Übergang von Katalog zu Inhalt nicht überstanden.

**Behoben ist es an drei Stellen zugleich**, und keine ersetzt die andere:

1. Die Wertzelle trägt `.cell` statt `.ident` — sie ist kein Bezeichner.
2. `.rows .cell` bekommt `max-width: 48ch` und `overflow-wrap: anywhere`. Die
   Grenze steht auf einem `div` und nicht auf der `td`: `max-width` gilt für eine
   Tabellenzelle nicht (CSS 2.1, „does not apply").
3. Der Wert **bricht** und wird nicht abgeschnitten. Was abgeschnitten dasteht,
   ist ohne Weg zum Rest — und den gibt es nur für die Zellen, die der Agent
   gekürzt hat.

`MobileLayoutTest::test_a_value_cell_of_the_rows_view_may_break` rechnet alle
drei nach. Die dritte Behauptung liest die Vorlage und nicht das Stylesheet, und
ohne sie wäre der Wächter grün, während der Fehler zurück ist: `.ident` bringt
sein `nowrap` aus einer Regel mit, die auf `.ident` endet — und der Selektor
dieses Tests sieht nur Regeln an, die auf der Zelle enden.

> **Ein Wächter, der die Regel prüft und nicht ihren Anlass, sieht die Rückkehr
> des Fehlers nicht.**

### 20.14 Ein `nowrap` über einer Zahl, die wächst

**Die Blätterleiste hat die Seite um 8 px geschoben**, und zwar an einer Stelle,
die dieses Panel seit `v0.4.0` hat. `.pager-state` trug `white-space: nowrap` —
richtig, solange dort „Seite 2 von 5" stand: kurz, und in der Länge unabhängig
davon, wie gross die Liste ist. Die Zeilenansicht schreibt
„1.001–1.050 von mehr als 1.050".

> **Ein `nowrap` über einer Zahl, die wächst, ist keine Zusage über die Zeile —
> es ist eine über den Bestand.**

Das `nowrap` ist ersatzlos gefallen. Der Umbruch nimmt nichts weg: Was kurz genug
ist, bricht nicht, und „Seite 2 von 5" bleibt einzeilig. Was zu lang ist, stand
vorher ausserhalb des Bildes. `MobileLayoutTest::test_the_pager_state_may_break`
hält es fest — und seine Untergrenze zählt **Regeln** und nicht Umbruchregeln,
denn die richtige Antwort ist hier gerade, dass keine Umbruchregel die Angabe
erreicht.

> **Ein Wächter über eine Abwesenheit braucht einen zweiten Beleg dafür, dass er
> noch hinsieht.**

### 20.15 Zwei Kleinigkeiten, die aus demselben Grund keine sind

**Der Knopf zum ganzen Wert steht an der Zelle und nicht an der Spalte.** Der
Agent meldet mit `truncated` die *Spalte*, in der gekürzt wurde — in einer
Textspalte mit einem langen Wert bekämen sonst alle fünfzig Zeilen den Knopf,
auch die mit drei Zeichen. Welche Zelle es trifft, rechnet die Anzeige aus der
Seite aus: Gekürzt wird auf eine feste Länge, und in einer Spalte, in der gekürzt
wurde, ist das die längste, die vorkommt. **Die 512 steht dabei nicht in der
Oberfläche** — sie gehört dem Agenten, und eine zweite Fassung wäre die, die
veraltet.

**Und die Zelleinzelsicht nennt keine Grenze in Zeichen.** Sie schreibt
„gekürzt" und daneben die Grösse des ganzen Wertes, die der Agent in der
Datenbank misst. Aus demselben Grund: `CELL_FULL_LIMIT` gehört dem Agenten. Die
Tabelle in §9 nennt sie „64 KiB"; gemessen wird sie in Zeichen (`mb_strlen`), und
die beiden sind nicht dasselbe, sobald ein `ü` im Wert steht.

### 20.16 Ein `NULL` in einer binären Spalte war eine Null

**Gefunden im Bildschirmfoto-Durchgang zu Schritt 5, auf `cloudsrv24`.** In der
Zeilenansicht stand in der Spalte `anhang` in **jeder** Zeile „binär · 0 B". Die
Spalte war leer — nicht null Byte lang, sondern `NULL` —, und die Anzeige machte
daraus eine Zahl.

Die Ursache stand in der Reihenfolge der Zweige:

```
<span v-if="isBinary(column)">binär · {{ formatBytes(Number(row[column] ?? 0)) }}</span>
<span v-else-if="row[column] === null">NULL</span>
```

`OCTET_LENGTH(NULL)` ist `NULL`, `Number(null ?? 0)` ist `0`, und der Zweig für
`NULL` kam nie dran. Eine leere Zelle war damit von einem tatsächlich leeren Blob
nicht mehr zu unterscheiden — **und genau das ist Kriterium 2** (§4).

> **Eine 0, die für „nichts da" steht, sieht aus wie eine Antwort.**

Es ist derselbe Fund wie bei der geschätzten Zeilenzahl in §9: Dort hiess die
falsche Antwort „0 Zeilen", hier „0 B". Beide Male war die richtige Anzeige ein
Wort und keine Zahl — und beide Male stand sie schon da und war unerreichbar.

**Keine Zahl hätte das gemeldet.** Die Ansicht lief über, ohne überzulaufen; die
Überlaufmessung war 0, die Spalte war gefüllt, jede Zeile sah gleich aus. Nur
kann man einer Zelle nicht ansehen, dass sie die Wahrheit über eine andere sagt.

**Der Wächter dazu ist `NullDisplayTest`** (§14.11), und er prüft die
**Reihenfolge** und nicht das Wort „NULL". Dass es in der Vorlage steht, sagt
nichts darüber, ob es je zu sehen ist — der Fehler war ja nicht, dass die
Anzeige fehlte.

### 20.17 Ein `IF NOT EXISTS` im Vorbereitungsblock, und was es verschwiegen hat

**Der Block, der die Daten für die Aufnahmen legt, ist zur Hälfte gescheitert**,
und die Meldung stand an einer Stelle, die nichts damit zu tun hatte:

```
ERROR:  column "zeitpunkt" is of type timestamp with time zone
        but expression is of type integer
LINE 1: INSERT INTO protokoll_ohne_schluessel VALUES (1, repeat('b',...
```

`protokoll_ohne_schluessel` gab es schon — aus dem Zwischenlauf, mit einer
anderen Spaltenfolge. Das `CREATE TABLE IF NOT EXISTS` hat das übersprungen, und
der `INSERT` **ohne Spaltennamen** hat danach auf die eigene Form gesetzt statt
auf die vorhandene.

> **Ein `IF NOT EXISTS` macht aus einer Annahme über die Form eine stille.** Es
> sagt „die Tabelle ist da" und nicht „sie sieht aus, wie du denkst" — und der
> Fehler kommt eine Anweisung später.

Das gehört hierher und nicht in eine Fussnote: Der Abnahmelauf von §15 legt
Tabellen auf demselben Weg an, und `docs/47` hat schon einmal gezeigt, dass die
teuersten Fehler eines Laufs im Lauf selbst stecken.

### 20.18 PostgreSQL sortierte den gekürzten Text — und benutzte den Index nie

**Der Fund kam von einem Bildschirmfoto, und er sah aus wie eine Kleinigkeit.**
Auf Bild 4 und 5 der Zeilenansicht standen die IDs in dieser Reihenfolge:

```
PostgreSQL:   1, 10, 100, 101, 102, 103, 104, 105
MariaDB:      1, 2, 3, 4, 6, 7, 8, 9, 13, 14, …
```

`id` ist `bigint`, sortiert wird aufsteigend über den Primärschlüssel — und
PostgreSQL sortierte **lexikographisch**.

Die Ursache steht in der Auswahlliste. `selectList()` gibt jeder Spalte ihren
eigenen Namen als Alias:

```sql
SELECT left("id"::text, 513) AS "id", … FROM "public"."t" ORDER BY "id" ASC
```

**PostgreSQL löst einen einfachen Namen im `ORDER BY` gegen die Ausgabespalte
auf**, nicht gegen die Eingangsspalte — so steht es in seiner Dokumentation zu
`SELECT`, und so ist es hier gemessen worden (16.13, Wegwerf-Cluster).

> **Ein Alias, der wie seine Spalte heisst, ist keine Umbenennung — er ist eine
> zweite Bedeutung desselben Namens.**

**Der zweite Schaden war auf keinem Bild zu sehen, und er ist der schwerere.**
Ein Sortierschlüssel `left(id::text, 513)` passt auf keinen Index. Gemessen an
derselben Tabelle mit 200.000 Zeilen:

```
vorher:   Limit -> Gather Merge -> Sort (Sort Key: left((t.id)::text, 513))
                                   -> Parallel Seq Scan on t
nachher:  Limit -> Index Only Scan using t_pkey on t src
```

Damit war die Begründung hinfällig, die in `Console.vue` steht: über den
Schlüssel zu sortieren, *weil* dort ein Index liegt und die erste Seite deshalb
nicht ins Zeitlimit läuft. Er wurde nie benutzt — auf `cloudsrv24` fiel das
nicht auf, weil 120 Zeilen auch ohne Index schnell sortiert sind.

> **Eine Zusage über ein Zeitlimit, die an einem kleinen Bestand geprüft wird,
> ist keine.**

**Behoben** ist es mit einem Aliasnamen an der Tabelle und einem qualifizierten
`ORDER BY`; ein qualifizierter Name kann keine Ausgabespalte treffen. Die
Auswahlliste und das `WHERE` bleiben unqualifiziert — beide sehen Ausgabenamen
ohnehin nicht. Gegengeprüft, dass der Aliasname nicht kollidiert: eine Tabelle
namens `src` mit einer Spalte namens `src` liefert dieselbe Reihenfolge.

**Und MariaDB war hier zufällig richtig.** Dort steht die Kürzung in einem
`JSON_OBJECT(...)`, das gar keinen Alias je Spalte erzeugt; `ORDER BY id` konnte
nur die Spalte meinen. Zwei Systeme, dieselbe Absicht, und nur eines hatte den
Fehler — die Art von Unterschied, für die es `EngineReachTest` gibt und die er
nicht sieht, weil beide Operationen ja da sind.

**Der Wächter dazu ist `ResultEncodingTest::test_the_sort_key_can_only_mean_the_column`.**
Er prüft je System das, was den Namen dort eindeutig macht: in PostgreSQL die
Qualifizierung, in MariaDB die Abwesenheit eines Alias je Spalte. Eine Regel,
zwei Belege — denn fiele das `JSON_OBJECT` weg, träte derselbe Fehler dort auf,
ohne dass jemand die PostgreSQL-Hälfte angefasst hätte.

### 20.19 Eine Sortierung ohne eindeutigen Schluss ist beim Blättern eine Stichprobe

**Gefunden auf Bild 7 des Durchgangs zu Schritt 5.** Sortiert nach `ort`, und
innerhalb von „Grünheide" standen die IDs so da:

```
116, 5, 92, 113, 47, 98, 77, 89, 104, 110, 119, 74, 23
```

Das ist keine Nachlässigkeit des Servers. Er sagt zu, nach `ort` zu sortieren,
und über Zeilen mit demselben `ort` sagt er **nichts** — die Reihenfolge darf
sich zwischen zwei Aufrufen ändern, und sie tut es, sobald der Plan sich ändert.

**Mit `OFFSET` ist das kein Schönheitsfehler.** Gemessen auf PostgreSQL 16.13,
120 Zeilen und drei Werten in `ort`, Seite 1 ohne und Seite 2 mit Index:

| | doppelt gesehen | nie gesehen |
|---|---|---|
| `ORDER BY ort` | **5 Zeilen** | **25 Zeilen** |
| `ORDER BY ort, id` | 0 | 20 — und das ist Seite 3 |

> **Eine Sortierung ohne eindeutigen Schluss ist beim Blättern keine Sortierung,
> sondern eine Stichprobe.**

Der Plan wechselt in echten Beständen von allein: wenn ein Index dazukommt, wenn
`ANALYZE` läuft, wenn die Tabelle wächst. Der Kunde sieht dann Zeilen doppelt,
während andere ausfallen — und nichts daran sieht nach einem Fehler aus.

**Behoben** ist es in `PgConsole::orderColumns()`, das **beide** Systeme
benutzen: die gewählte Spalte, dann die Spalten des Schlüssels, alle in derselben
Richtung. Wer nach dem Schlüssel selbst sortiert, bekommt ihn nicht zweimal.
Gegengeprüft mit den **erzeugten** Anweisungen über zwei verschiedene Pläne:
0 doppelt, 18 offen bei 2 × 51 von 120 Zeilen.

**Ohne Schlüssel bleibt es dabei**, und das ist eine benannte Lücke — es gibt
dann keine Spalte, die eine Zeile eindeutig macht. Sie trifft dieselben Tabellen,
die nach §10 ohnehin nur lesbar sind, und steht neben der Lücke aus §12, dass es
für sie auch die Zelleinzelsicht nicht gibt.

**Und dieser Fund hängt am vorigen.** §20.18 hat die Sortierung überhaupt erst
auf die richtige Spalte gestellt; solange sie den gekürzten Text sortierte, war
die Frage nach Gleichständen gar nicht zu sehen — `left(id::text, 513)` ist über
einem Primärschlüssel eindeutig, und die Reihenfolge sah deshalb stabil aus.

> **Ein Fehler, der einen zweiten verdeckt, wird beim Beheben zum Finder.**

### 20.20 Die hohe Zeile bleibt — eine Entscheidung des Betreibers

Auf Bild 8 des Durchgangs hat die Zeile mit der gekürzten `bemerkung` eine hohe
leere Fläche: Die Zelle bricht auf elf Zeilen, und ihre Spalte ist gerade aus dem
Bild gerollt. Der Grund für die Höhe steht ausserhalb des Bildschirms.

**Entschieden am 13. August 2026: so lassen.** Nichts ist versteckt, die Höhe ist
ehrlich, und wer nach rechts rollt, sieht sofort warum. Der Fall tritt nur bei
Zellen über etwa 500 Zeichen auf.

Die beiden Alternativen kosten mehr, als sie einbringen:

- **Die Zelle klemmen** verlangt den `…`-Knopf ausserhalb der Klemmung — und ein
  langer, vom Agenten **nicht** gekürzter Wert wäre dann abgeschnitten, ohne dass
  ein Weg zum Rest bliebe. Aus einer sichtbaren Unschönheit würde eine
  unsichtbare Lücke.
- **Eine zweite Kürzungsgrenze nur für die Anzeige** wären zwei Zahlen für
  dieselbe Sache, und die zweite ist die, die veraltet.

> **Eine hohe Zeile ist ehrlich. Eine geklemmte verschweigt, dass sie klemmt.**

Die Begründung steht ausserdem bei `.rows .cell` in `app.css` — dort, wo jemand
als Nächstes ein `max-height` hinschreiben würde.

### 20.21 Der Plan sagte 900 px, und dieses Panel kennt keinen dritten Haltepunkt

§11.1 schreibt „ab 900 px daneben". **`docs/24 §1` heisst „Zwei Haltepunkte, und
kein dritter"** — 720 px und 480 px, mit Begründung und mit einem Wächter, der
jeden anderen Wert abweist.

Die 900 stammen aus der Entwurfsphase dieses Plans und sind gegen `docs/24` nie
gehalten worden. Gemessen reicht 720:

| Breite | Inhaltsspalte |
|---|---|
| 720 px | 416 px |
| 800 px | 496 px |
| 900 px | 596 px |
| 1440 px | 798 px |

Bei 720 px teilen sich Baum (280 px) und Inhalt (416 px) die Fläche; die
Strukturtabelle rollt dort innerhalb ihrer Spalte, wofür `.scrolls` da ist. Ein
dritter Haltepunkt hätte dafür eine Vorgabe gebrochen, die neun Monate lang
gehalten hat.

> **Eine Zahl in einem Schrittplan ist keine Vorgabe. Sie ist ein Vorschlag, bis
> jemand sie gegen die Vorgabe hält.**

### 20.22 Ein Überlauf, der nur in der Mitte auftritt

**Der erste zweispaltige Entwurf schob die Seite — aber nur bei 800 und 900 px.**
Bei 720 und bei 1440 stand die Messung auf `0px`, also genau an den beiden
Stellen, an denen man zuerst nachsieht: am Haltepunkt und am Arbeitsplatz.

```
ohne min-width: 0     720px: 0px   800px: 242px   900px: 142px   1440px: 0px
mit  min-width: 0     720px: 0px   800px:   0px   900px:   0px   1440px: 0px
```

Die Ursache ist dieselbe wie an vier anderen Stellen dieses Panels: **Ein
Rasterkind hat als Mindestbreite seine Inhaltsbreite**, und im Inhalt steht eine
Tabelle, deren Kennungen `white-space: nowrap` tragen. Die `1fr`-Spalte konnte
deshalb nicht unter die Breite des längsten Bezeichners, und der Rest schob.

> **Ein Überlauf, der nur in der Mitte auftritt, entkommt beiden Messungen, die
> man von selbst macht.**

Deshalb misst das Skript zu diesem Schritt vier Breiten und nicht zwei.

**Und eine zweite Rückmeldung war unsichtbar.** Der erste Entwurf nahm
`--control-bg` als Hintergrund für Hover und ausgewähltes Ziel. Im hellen Theme
ist das `#ffffff` — derselbe Wert wie `--bg`. Eine Rückmeldung, die es nur im
dunklen Theme gibt, und dieselbe Fehlerklasse wie der Knopfrand mit 1,04:1 und
das Eingabefeld mit 1,09:1.

> **Eine Marke, die man für einen Hintergrund hält, ist noch keine Farbe, die
> sich vom Hintergrund abhebt.**

Behoben nach der Hausform: Hover ändert die Farbe (wie `.button:hover`), das
ausgewählte Ziel trägt `--accent-surface` (wie `.button.active`). Beides sind
vorhandene Marken, und „ausgewählt" sieht damit im ganzen Panel gleich aus.

### 20.23 Was aus der Navigation fällt, muss im Inhalt landen

Die alte Tabellenliste trug fünf Angaben je Tabelle: Name, Art, Zeilenzahl,
Grösse, Schlüssel. **Der Baum trägt nur den Namen** (§11.1) — und die anderen
vier waren damit erst einmal weg.

Weglassen war keine Möglichkeit. „Hat diese Tabelle einen Schlüssel" entscheidet,
ob es die Zelleinzelsicht gibt (§12), und ab Schritt 6, ob man sie ändern kann;
Zeilenzahl und Grösse sind das, woran man eine Tabelle einschätzt, bevor man sie
öffnet.

Sie stehen jetzt in der Beizeile des offenen Ziels:

    Tabelle bestellpositionen_… · Tabelle · 16.008 Zeilen · 3,3 MB · mit Schlüssel

> **Was aus der Navigation fällt, muss im Inhalt landen — sonst ist es
> weggefallen.**

**Und ein Ziel öffnet jetzt genau eine Katalogfrage.** Spalten und Indexe waren
eine Ansicht mit zwei Bereichen und zwei Anfragen nebeneinander; im Baum sind sie
zwei Blätter. Zwei Blätter, die dasselbe öffnen, wären eine Lüge über die
Navigation — und nebenbei kostet jedes Ziel damit einen befristeten Zugang statt
zweier.

### 20.24 Ein Klick, der auf dem Telefon nichts zu tun scheint

Unter 720 px liegt der Inhalt **unter** dem Baum, und zwanzig Zweige sind rund
930 px hoch. Wer „Zeilen" wählt, bekommt die Zeilen zwei Bildschirme weiter
unten — die Seite ändert sich sichtbar überhaupt nicht.

Die Ansicht holt den Inhalt deshalb ins Bild, und nur dort: Ab 720 px steht er
daneben und ist längst zu sehen, ein Sprung wäre eine Bewegung ohne Anlass. Die
Breite kommt aus `matchMedia` mit demselben Haltepunkt wie in `app.css` — zwei
Fassungen davon wären eine zu viel.

### 20.25 Ein Satz, der eine Seite verspricht

**Das erste Bild des Durchgangs zu Schritt 5b hat einen Fehler gefunden, den
kein Wächter sehen konnte.** Solange keine Tabelle gewählt ist, stand im Inhalt:

> Wählen Sie **links** eine Tabelle und darunter, was Sie sehen möchten.

Der Baum steht nur **ab 720 px** daneben. Darunter steht er *oben* — und genau
auf dem Telefon schickte der Satz in die falsche Richtung.

Der Satz war grammatisch, deutsch, freundlich und sachlich richtig; falsch war
er nur zusammen mit dem Grundriss, den er nicht kennt.

> **Ein Text, der eine Anordnung behauptet, ist nur so lange richtig wie die
> Anordnung — und die hängt hier an der Breite des Fensters.**

Er heisst jetzt „Wählen Sie eine Tabelle und dann, was Sie von ihr sehen
möchten." — ohne Richtung, in jeder Breite wahr.

**Der Wächter dazu ist `MobileLayoutTest::test_no_text_promises_a_side`**, und
er hat beim ersten Lauf einen Fehlalarm produziert, der die Regel geschärft hat:
`Settings/Mail.vue` schreibt „Einmal-**Links** und Warnungen entstehen" — das
sind Verweise und keine Richtung. Die deutsche Rechtschreibung trennt die beiden
zuverlässig (die Richtung ist ein Adverb und klein, das Substantiv gross), und
nur am Satzanfang fallen sie zusammen; genau dieser Fall steht als zweite
Möglichkeit im Ausdruck.

> **Ein Wächter, der ein Wort sucht statt einer Bedeutung, findet die Wörter,
> die zufällig gleich aussehen.**

„Oben" und „unten" stehen bewusst nicht in der Regel: Sie bleiben beim Umbruch
richtig — was untereinander steht, steht in jeder Breite untereinander, es
wandert nur, wie weit.

### 20.26 „Schliessen" klebte an der Tabelle — seit Schritt 4

**Der Betreiber hat es auf Bild 3 und 4 des Durchgangs zu Schritt 5b gesehen:**
Der Knopf „Schliessen" stand ohne einen Millimeter Abstand unter der
Spaltentabelle. Gemessen nach der Behebung: **16 px**, vorher 0.

`.button-row` bringt keinen Rand nach oben mit. In einem `.form` fällt das nicht
auf — die Reihe ist dort ein Flexkind und erbt den `gap`. Für die Fälle
ausserhalb gibt es seit dem Optik-Rework einen Nachbarschaftsausdruck in
`app.css`, und der kannte **nur Formularinhalt**: `.field`, `.hint`, `.error`.

Der Grund hat aber nichts mit Formularen zu tun: Diese Bausteine **enden
bündig**. Und das tun `.scrolls` (hört an der Tabellenkante auf), `.pager` (oben
eine Linie, unten nichts) und `.cell-value` (`margin: 0`) genauso.

> **Eine Regel, die eine Liste von Nachbarn führt, ist eine Liste, die wächst —
> der Grund steht nicht in ihr, sondern daneben.**

**Der Fehler war seit Schritt 4 da und hat zwei Bildschirmfoto-Durchgänge
überlebt.** Gefunden hat ihn kein Wächter und keine Messung: Nichts lief über,
nichts war abgeschnitten, es sah nur gedrängt aus.

**Und der Wächter dazu hat dreimal falsch herum gefragt.** `ButtonRowSpacingTest`
suchte zuerst *Knopfreihen* und fragte, ob ihr Vorgänger von der Regel erfasst
sei. Das meldete drei richtige Dinge als Fehler:

| Fundstelle | warum sie richtig ist |
|---|---|
| `.form` in `Login.vue` | Flexbehälter mit `gap` — der Abstand kommt vom Behälter |
| `.sheet` in `Login.vue` | eigene Regel `.sheet .button-row { margin-top: 22px }` |
| `.tasks li` in `Operations/Index.vue` | Flexzeile mit `gap`, und waagerecht |

> **Ein Wächter, der von der falschen Seite fragt, findet drei richtige Antworten
> und nennt sie Fehler.**

Er fragt jetzt von der anderen Seite: Welche Bausteine enden bündig, und steht
unter einem von ihnen eine Knopfreihe, ohne dass die Regel ihn kennt? Was das
**nicht** abdeckt, steht in seinem Kopf: Ein neuer bündiger Baustein, den seine
Liste nicht kennt, fällt durch. Die Liste ist die Regel, nicht ihr Ersatz.

### 20.27 Der Mangel, den die Frage nach dem Beleg gefunden hat

**Der Betreiber hat gefragt, wie sich die Tastaturbedienung noch belegen lässt —
ausser mit der Aussage, dass sie funktioniert.** Beim Ausschreiben der Antwort
gehörte diese Zeile in die Messung:

```js
document.querySelectorAll('[role="treeitem"][tabindex="0"]').length   // erwartet: 1
```

Sie trifft genau einen Knoten, wie sie soll. Aber **immer denselben**: Der erste
Wurf schrieb `:tabindex="index === 0 ? 0 : -1"`. Der Baum war damit **eine**
Tabulatorstation — richtig und der ganze Zweck des Musters —, aber die Station
wanderte nicht mit. Wer den Baum verliess und mit `Tab` zurückkam, stand wieder
oben statt dort, wo er war.

> **Wer aufschreibt, wie etwas zu belegen wäre, sieht dabei, was der Beleg
> zeigen würde.**

Das ist kein Fund aus einem Bild und keiner aus einer Messung — der Beleg musste
gar nicht erst gefahren werden. Es genügte, ihn so genau zu formulieren, dass
sein Ergebnis vorhersagbar wurde.

**Behoben** mit einer wandernden Station: `tabStop` merkt sich den zuletzt
besuchten Punkt, `@focusin` am Baum schreibt ihn fort, und solange niemand im
Baum war, trägt ihn der erste Zweig — irgendwo muss man hineinkommen.

`TreeSemanticsTest::test_a_tree_is_one_tab_stop_and_it_moves` prüft beide
Hälften: dass **jede** Station gebunden ist (eine feste `0` kann nicht wandern)
und dass der Baum mitbekommt, wohin der Fokus geht. Beide Brüche gefahren.

#### Wie sich diese Ansicht belegen lässt

Für den Abnahmelauf und für jeden, der das Muster später anfasst — vier Belege,
vom schwächsten zum stärksten:

1. **Ein Protokoll der Fokusbewegung.** Tasten aus der Konsole schicken und nach
   jeder festhalten, welches Element den Fokus hat und was sein `aria-expanded`
   sagt. Das ist die Messung statt der Behauptung.
2. **Der Zugänglichkeitsbaum** (DevTools → Elements → Accessibility) am
   fokussierten Knoten. Er zeigt, was eine Vorleseausgabe bekommt — und Markup
   und Zugänglichkeitsbaum sind zwei verschiedene Dinge: Ein `role` an der
   falschen Stelle verschwindet dort schweigend.
3. **Die Tabulatorprobe.** Einmal `Tab` hinein, einmal `Tab` heraus — nicht
   zwanzigmal. Das ist der ganze Zweck des Musters, und genau hier ist der
   Mangel oben aufgefallen.
4. **Der Bruch.** `@keydown` aus dem Baum nehmen und dieselbe Messung fahren.
   Bleibt der Fokus stehen, hat die Messung gemessen.

#### Die Belege, gefahren am 13. August 2026 gegen `0.5.3-rc.7`

**Punkt 1, das Protokoll der Fokusbewegung** — jede Zeile eine Taste, dahinter
das Element, das danach den Fokus hat:

```
start        → ▾bestellpositionen_…   aria-expanded=true
ArrowDown    → Spalten                aria-expanded=—
ArrowDown    → Indexe                 aria-expanded=—
ArrowUp      → Spalten                aria-expanded=—
ArrowRight   → Spalten                aria-expanded=—      ein Blatt klappt nichts auf
ArrowRight   → Spalten                aria-expanded=—
ArrowDown    → Indexe                 aria-expanded=—
ArrowLeft    → ▾bestellpositionen_…   aria-expanded=true   heraus aus der Gruppe
End          → ▸umsaetze_je_ort       aria-expanded=false  letzter *sichtbarer* Punkt
Home         → ▾bestellpositionen_…   aria-expanded=true
```

**Punkt 2, der Zugänglichkeitsbaum**: Chromium löst `tree "" multiselectable:
false` unter `main` auf — die Rolle steht also nicht nur im Quelltext.

**Punkt 3**: `document.querySelectorAll('[role="treeitem"][tabindex="0"]').length`
→ `1`. Eine Tabulatorstation, keine zwanzig.

**Und was das Protokoll nicht zeigt.** Der Lauf begann auf einem bereits offenen
Zweig und ging von dort in die Blätter; `aria-expanded` stand durchweg auf `true`
und hat nie gewechselt. Damit fehlen genau die beiden Übergänge, die den Zweig
betreffen — `ArrowRight` auf einem zugeklappten und `ArrowLeft` auf einem
offenen.

> **Ein Protokoll, in dem eine Spalte nie ihren Wert wechselt, hat den Übergang
> nicht gesehen — nur den Zustand.**

Nachzuholen mit einer Folge, die auf einem zugeklappten Zweig beginnt:
`['End', 'ArrowRight', 'ArrowRight', 'ArrowLeft', 'ArrowLeft']`. Dann muss
`aria-expanded` **false → true** und wieder **true → false** durchlaufen.

Punkt 3 zeigt in `rc.7` ausserdem noch die **feste** Station; dass sie mitwandert
(§20.27), ist erst gegen die nächste Fassung zu belegen — Baum verlassen,
zurücktabben, und dort landen, wo man war.

### 20.28 Drei Fehler in einer Zeile — und der dritte zum dritten Mal

**Der Nachtrag zu Schritt 5b hat die Beizeile getroffen**, die ich in eben
diesem Schritt eingeführt habe (§20.23). Bei einer **Sicht** stand da:

> Tabelle `umsaetze_je_ort` · Sicht · unbekannt Zeilen · 0 B · ohne Schlüssel

Drei Fehler, alle meine:

1. **„Tabelle … · Sicht"** — beides in einem Satz, zwei Wörter Abstand. Die
   Beschriftung vor dem Namen stand fest auf „Tabelle", während die Angaben
   dahinter die Art nannten.
2. **„unbekannt Zeilen"** ist kein Deutsch. `formatRows(null)` gibt das Wort
   „unbekannt" zurück, und ich hatte blind „ Zeilen" angehängt. Die Zahl trägt
   ihre Einheit, das Wort trägt seinen Satz.
3. **„0 B" für eine Sicht.** Eine Sicht speichert nichts; der Katalog meldet
   dafür `0`, und die Anzeige machte daraus eine Grösse.

> **Eine Beschriftung, die einen Wert wiederholt, der daneben steht, ist nicht
> doppelt — sie ist die zweite Fassung.**

**Der dritte ist der teure, weil er zum dritten Mal auftritt.** Vorher: die
geschätzte Zeilenzahl (§9 — PostgreSQL meldet `-1`, und `max(0, …)` machte
daraus „0 Zeilen") und die Länge einer binären Spalte mit `NULL` (§20.16 —
„binär · 0 B"). Dreimal dieselbe Ursache: **eine Zahl, die es gibt, für eine
Angabe, die es nicht gibt.**

> **Eine 0, die für „nichts da" steht, sieht aus wie eine Antwort.**

Beim dritten Mal bekommt sie einen Wächter:
`NullDisplayTest::test_a_view_is_shown_without_a_size`. Er prüft den
Zusammenhang und nicht den Aufruf — dass `formatBytes` vorkommt, ist richtig;
falsch wäre, es ohne Rücksicht auf die Art der Tabelle zu tun.

**Gefunden hat alle drei ein Bildschirmfoto**, und zwar eines, das für etwas
ganz anderes aufgenommen wurde: Der Betreiber hat die Tabulatorstation belegt,
und die Beizeile stand zufällig daneben.

> **Ein Bild vom echten Server zeigt auch das, wofür es nicht aufgenommen
> wurde.**

### 20.29 Wie sich eine Bedienung belegen lässt

**Die Frage des Betreibers zum Nachtrag war: „Die Anweisungen funktionieren.
Aber wie soll ich es nachweisen?"** Sie gilt über diesen Schritt hinaus — für
Schritt 6 stellt sie sich bei jedem Formular neu.

> **Eine Bedienung hinterlässt keine Spur. Ein Zustand schon — also verwandle
> die eine in den anderen.**

Drei Wege, und der zweite ist der beste:

**1 · Die Bewegung mitschreiben lassen.** Ein `focusin`-Horcher auf dem
Dokument, dann von Hand tabben und pfeilen. Jede Zeile im Protokoll ist ein
**echter** Tastendruck — das ist stärker als ein Lauf mit `dispatchEvent`, der
zwar den Handler prüft, aber nicht, ob der Browser die Taste überhaupt dorthin
leitet.

**2 · Den Zustand danach ablesen.** Nach der Bedienung eine Zeile:

```js
document.querySelector('[role="treeitem"][tabindex="0"]').textContent.trim()
```

Gemessen am 13. August 2026 gegen `0.5.3-rc.8` nach „Tab hinein, dreimal
Pfeil ab, Tab hinaus, Umschalt+Tab zurück": **`▾umsaetze_je_ort`** — die dritte
Tabelle. In `rc.7` hätte dieselbe Zeile die erste genannt. Ein Bildschirmfoto
dieser einen Zeile sagt mehr als ein Video der Bedienung.

**3 · Messen statt ansehen.** Der Abstand unter der Tabelle: gemessen `16`.
„Sieht jetzt besser aus" ist keine Zahl.

**Und ein Nebenbefund aus demselben Lauf:** Als kein `.scrolls` auf der Seite
war, ist die Messung mit einem `TypeError` **laut** gescheitert, statt still
eine 0 zu liefern.

> **Eine Messung, die ins Leere greift, soll abbrechen und nicht null
> zurückgeben.**

**Was keiner der drei belegt**, und das gehört benannt: dass eine
**Vorleseausgabe** den Baum richtig ansagt. Die Rollen im Zugänglichkeitsbaum
sind die Voraussetzung dafür, nicht der Beweis — den liefert nur NVDA oder
VoiceOver, mit dem Baum bedient und dem Gehörten daneben. Das ist eine benannte
Lücke und keine erledigte Sache.

### 20.30 Der fünfte Fall derselben Fuge — und der erste in der anderen Richtung

**Der Betreiber hat es auf demselben Bild gesehen wie die Beizeile**: Auf der
Datenbankseite steht „Tabellen durchsehen" am Kopf, darunter fangen die
Bereiche an, und zwischen beiden war nichts. Es ist derselbe Fehler wie in
§20.26 — mit einem Unterschied, der ihn vier Anläufe lang unsichtbar gemacht
hat: **Die Knopfreihe steht diesmal oben.**

`.button-row` bringt keinen Abstand mit. Die vier Fälle davor fragten deshalb
alle dasselbe: Was steht *über* der Reihe, und bringt es welchen mit? Der
Nachbarschaftsausdruck in `app.css` ist von dieser Frage geformt —
`:is(.field, .hint, …) + .button-row`. Er kann den umgekehrten Fall nicht
sehen, und der Wächter dazu konnte es auch nicht.

> **Eine Regel über den Nachbarn davor sagt nichts über den danach.**

`.sections` setzt seinerseits keinen Rand nach oben, und das ist richtig: Auf
jeder anderen Seite dieses Panels steht davor eine Meldung oder `FormErrors`,
und die bringen ihren `margin-bottom` selbst mit. Die Datenbankseite ist die
einzige mit einer Knopfreihe an dieser Stelle — und damit die einzige, auf der
zwei Bausteine aufeinandertreffen, die beide nichts mitbringen.

**Gemessen**, mit dem gebauten Stylesheet im vorinstallierten Chromium:

| | 1440px | 390px |
|---|---|---|
| Knopfreihe → Bereiche, vorher | **0px** | **0px** |
| Knopfreihe → Bereiche, nachher | 26px | 24px |
| Meldung → Bereiche (Gegenprobe) | 26px | 24px |

Die dritte Zeile ist der Grund, dass die erste etwas heisst. Ohne sie wäre die
0 keine Messung, sondern eine Behauptung — derselbe Satz wie beim `konsole = 0`
aus `docs/47`.

**Die Zahlen gelten für die Dichtestufe `admin`**, und das gehört dazu: Auf
`cloudsrv24` ist am 13. August dieselbe Stelle in der **Kundensicht** gemessen
worden, und dort steht **34px**. Das ist kein Widerspruch, sondern die Marke bei
der Arbeit — `--block-gap` ist 26px für `admin`, 34px für `customer` und 24px
unterhalb von 720px. Eine feste `26px` in der Regel hätte der Kundenfläche einen
Abstand gegeben, der zu keinem ihrer Nachbarn passt.

> **Eine Messung ohne die Stufe, in der sie entstand, ist eine Zahl ohne
> Einheit.**

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

Die Regel nimmt `--block-gap` und nicht die 16px von §20.26: Das hier ist die
Fuge zwischen zwei Blöcken einer Seite und nicht die zwischen zwei Zeilen eines
Formulars. Dass die Zahl danach auf den Millimeter der einer Meldung entspricht,
ist kein Zufall, sondern die Probe darauf.

#### Und der Bruch hat einen zweiten Fehler gefunden — im Wächter selbst

Der erste Bruch — die Regel aus `app.css` entfernen — biss sofort. Der zweite —
die Nachbarschaft in `Show.vue` auflösen, damit die Untergrenze anschlägt —
blieb **grün**, und das war kein Fehler des Bruchs.

Umbenannt hatte ich `class="sections"` nach `class="sections-x"`. Der Wächter
suchte mit `\bsections\b`, und `\b` sitzt zwischen `s` und `-` genauso wie
zwischen `s` und einem Leerzeichen. Der umbenannte Baustein galt weiter als der
alte.

> **Ein Bindestrich ist für einen regulären Ausdruck eine Wortgrenze und für
> eine Klassenliste keine.**

Das traf **auch die vier Jahre alte Hälfte** dieses Wächters: `\bpager\b` trifft
`pager-state`, `\bcell\b` trifft `cell-value` — beide Klassen gibt es in
`app.css`, und beide sind etwas anderes als der Baustein, nach dem gefragt wird.
Gefunden hat es kein Nachdenken über den Ausdruck, sondern der Versuch, ihn zu
brechen.

Beide Hälften fragen jetzt über `className()` nach einem ganzen Klassennamen:
`(?<![\w-])name(?![\w-])`. Verankern (`(?:^|\s)`) ginge nicht — der Ausdruck
steht mitten in einem `class="[^"]*…[^"]*"`, und `^` meinte dort den Anfang der
ganzen Datei.

**Drei Brüche, drei Bisse** (nach der Korrektur): Regel weg → rot; Nachbarschaft
weg → rot an der Untergrenze; und die alte Hälfte, `.scrolls` aus dem Ausdruck
entfernt → rot in beiden alten Fällen.

### 20.31 Der Einstieg in die Konsole ist die Hauptsache seiner Seite

**Der Betreiber hat gefragt, ob sich der Knopf farblich hervorheben lässt.** Die
Antwort brauchte keine neue Farbe: Die Rangfolge steht seit „Kontor" in
`app.css` und heisst `.button.primary` — „die eine Aktion, für die man die Seite
geöffnet hat". Alles andere auf der Datenbankseite — Zugänge, Netze,
Sicherungen — *verwaltet* die Datenbank; dieser Knopf führt hinein.

Ein zweites `primary` gibt es dort nicht, und
`ButtonStyleTest::test_at_most_one_primary_button_per_form` besteht darauf. Der
Kontrast ist ebenfalls schon gerechnet und nicht geschätzt
(`test_the_label_on_a_button_stays_readable`), in beiden Themes.

> **Eine Frage nach einer neuen Farbe ist meistens eine nach einem Rang, den es
> schon gibt.**

### 20.32 Eine Messung, die überall eine Zahl findet, sagt nicht, wo sie gemessen hat

**Der Beleg zu §20.30 ist beim ersten Versuch auf der falschen Seite gelaufen**
— und das Ergebnis sah aus wie eines:

```
{ luecke: -2231, rang: 'button', dokument: 0 }
```

Der Ausdruck war so geschrieben:

```js
const knopf = document.querySelector('.button-row')
const bereiche = document.querySelector('.sections')
if (!knopf || !bereiche) throw new Error('Knopfreihe oder Bereiche nicht gefunden')
```

Auf der Datenbankseite trifft das genau das gemeinte Paar. Auf der
**Konsolenseite** gibt es beide Klassen auch — nur in der anderen Reihenfolge:
`.sections` steht dort in Zeile 827, die erste `.button-row` in 893. Der
Ausdruck hat also zwei Elemente gefunden, die nichts miteinander zu tun haben,
ihren Abstand ausgerechnet und **−2231** gemeldet.

Der Wächter im Ausdruck — das `throw` aus §20.29 — hat nicht angeschlagen, weil
er die falsche Frage stellte: *Gibt es die beiden?* Ja, gab es. Die richtige
Frage ist: *Sind es die beiden, die ich meine?*

> **Eine Messung, die ins Leere greift, bricht ab. Eine, die daneben greift,
> rechnet weiter.** Die zweite ist die gefährlichere.

Das Vorzeichen war der einzige Hinweis: Ein negativer Abstand heisst, dass die
angenommene Reihenfolge nicht stimmt. Er wurde ausgegeben statt geprüft.

Richtig gestellt wird der Ausdruck über die **Nachbarschaft** — dieselbe
Beziehung, die auch die CSS-Regel ausdrückt:

```js
(() => {
  const knopf = [...document.querySelectorAll('.button-row')]
    .find((el) => el.nextElementSibling?.classList.contains('sections'))
  if (!knopf) throw new Error('Keine Knopfreihe mit Bereichen als nächstem Geschwister — falsche Seite?')
  const bereiche = knopf.nextElementSibling
  return {
    luecke: Math.round(bereiche.getBoundingClientRect().top - knopf.getBoundingClientRect().bottom),
    rang: knopf.querySelector('.button').className,
    dokument: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  }
})()
```

Auf der Konsolenseite bricht er jetzt ab, und der Abbruch nennt den Grund.

**Und derselbe Fehler steckt in jeder Messung dieses Projekts, die mit
`querySelector` anfängt.** Die Überlaufmessung bei 390px fragt nach dem
Dokument und ist damit unverdächtig; die aus §20.29 — der Abstand unter der
Tabelle — nimmt `.scrolls` und `.button-row` und hat dasselbe Problem, nur ist
sie auf der einzigen Seite gelaufen, auf der es das Paar gibt.

> **Ein Ausdruck, der zwei Dinge sucht, muss sagen, dass sie zusammengehören.**

### 20.33 Und das Bild für die Messung hat den offenen Beleg mitgeliefert

Auf demselben Bildschirmfoto — aufgenommen, um die Konsole des Browsers zu
zeigen — steht die Beizeile einer **echten Tabelle**:

```
Tabelle bestellpositionen_archiv_2026_langer_name_zum_messen · 120 Zeilen · 136 KB · mit Schlüssel
```

Damit ist die Gegenprobe zu §20.28 vollständig. Die Sicht daneben las sich
`Sicht umsaetze_je_ort · Zeilenzahl unbekannt · ohne Schlüssel`, und erst
zusammen belegen die beiden Zeilen etwas: Bei der Tabelle steht die Grösse, bei
der Sicht steht sie nicht — nicht als `0 B`, sondern gar nicht.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Zum zweiten Mal in dieser Stufe, und zum zweiten Mal hat das Bild
> die Gegenprobe zufällig mitgebracht.

> **Ein Bild vom echten Server zeigt auch das, wofür es nicht aufgenommen
> wurde.**

### 20.34 Der Beleg, gefahren — und was die dritte Zahl noch gezeigt hat

**Gemessen auf `cloudsrv24` gegen `0.5.3-rc.10`**, mit dem berichtigten Ausdruck
aus §20.32, in der Kundensicht (Dichtestufe `customer`):

| Fläche | `luecke` | `rang` | `dokument` |
|---|---|---|---|
| weit | **34** | `button primary` | 0 |
| schmal (390px) | **24** | `button primary` | 0 |
| Konsolenseite, alter Ausdruck | −2231 | `button` | 0 |

Die dritte Zeile ist der Fund aus §20.32 und steht hier, damit sie nicht als
Fehlmessung durchgeht: Sie ist der Beleg dafür, **dass** der alte Ausdruck eine
Zahl liefert, wo er keine liefern dürfte.

Zwei Dinge, die die Zahlen sagen und ein Bild nicht sagt:

**1 · `rang` nennt die Klasse und nicht die Farbe.** `button primary` ist
prüfbar; „sieht violett aus" ist es nicht — und ein Bild könnte auch eine Farbe
zeigen, die aus einem Hexwert in der Komponente käme. Die Klasse zeigt auf eine
Regel in `app.css`, und `ClassReachTest` besteht darauf, dass es sie gibt.

**2 · Die 34 war nicht vorhergesagt, und das ist der interessantere Teil.** Ich
hatte 26 erwartet — der Wert der Dichtestufe `admin`, gegen die ich hier
gemessen habe. Dass stattdessen 34 herauskam, ist die Probe darauf, dass die
Regel die **Marke** liest und keine Zahl: In der Kundensicht ist `--block-gap`
34px, und der Abstand folgt mit.

> **Eine Messung, die anders ausfällt als erwartet und trotzdem stimmt, prüft
> mehr als eine, die trifft.**

### 20.35 Schritt 6, erste Hälfte — und §10 Regel 2 war nie gebaut

**Der Rückstand stand nicht im Plan, sondern im Quelltext.** `docs/46 §10` nennt
drei Regeln für den Bezug auf eine Zeile: Primärschlüssel, sonst ein eindeutiger
Index über Spalten ohne `NULL`, sonst nur lesbar. Gebaut war Regel 1. Beide
Konsolen meldeten `key` ausschliesslich für den Primärschlüssel — eine Tabelle
mit einem tauglichen eindeutigen Index war damit nicht änderbar, obwohl sich
eine Zeile darüber genauso eindeutig ansprechen lässt.

**Aufgefallen ist es an MariaDB, die es längst richtig machte.** Sie befördert
den ersten eindeutigen Index über `NOT NULL`-Spalten zum impliziten
Primärschlüssel und meldet seine Spalten in `COLUMN_KEY` als `PRI`. Der
MariaDB-Zweig dieses Panels erfüllt Regel 2 also, seit es ihn gibt — und niemand
hat es aufgeschrieben. Der PostgreSQL-Zweig erfüllte sie nicht.

> **Ein Unterschied zwischen zwei Umsetzungen derselben Regel ist kein
> Unterschied der Systeme, solange ihn niemand gemessen hat.**

`EngineReachTest` kann das nicht sehen: Er vergleicht Namen von Operationen und
kein Verhalten. Es ist dieselbe Lücke wie bei der Kodierung in `docs/47` — zwei
Systeme, eine Regel, zwei Antworten, und kein Wächter dazwischen.

#### Neun Fälle, beide Systeme, gemessen

Wegwerf-Server im Container: PostgreSQL **16.13**, MariaDB **10.11.14** (dieselbe
Fassung wie `cloudsrv24`; `apt-get install mariadb-server` holt sie aus dem
Ubuntu-Archiv). Gefahren mit den **echten** `columnsQuery()` aus dem Quelltext
und nicht mit einer nachgebauten Abfrage.

| Fixtur | erwartet | PostgreSQL | MariaDB |
|---|---|---|---|
| Primärschlüssel | `id` | `id` | `id` |
| nur eindeutiger Index, `NOT NULL` | `kennung` | `kennung` | `kennung` |
| eindeutiger Index über nullbare Spalte | — | — | — |
| gar kein Index | — | — | — |
| zwei taugliche Indexe | der zuerst angelegte | `b, c` | `b, c` |
| Primärschlüssel **und** eindeutiger Index | der Primärschlüssel | `id` | `id` |
| Teilindex (`WHERE aktiv`) / Präfixindex | — | — | — |
| Ausdrucksindex (`lower(kennung)`) | — | — (kein Fall in MariaDB) |
| eine Sicht | — | — | — |

Vier Ausschlüsse in PostgreSQL, jeder einzeln belegt:

- **`indpred IS NULL`** — ein Teilindex ist nur für die Zeilen eindeutig, die
  seine Bedingung erfüllen, und sagt über die anderen nichts.
- **`0 <> ALL (indkey)`** — eine `0` in `indkey` steht für einen *Ausdruck*, und
  zu einem Ausdruck gibt es keine Spalte, in die sich ein `WHERE` schreiben
  liesse.
- **keine nullbare Spalte darunter** — `NULL = NULL` ist nicht wahr, ein `WHERE`
  darüber träfe die Zeile nicht.
- **`ORDER BY indisprimary DESC, indexrelid`** — der Primärschlüssel gewinnt,
  sonst der zuerst angelegte.

**Die letzte Hälfte war keine freie Wahl.** Der erste Entwurf sortierte nach
Spaltenzahl („der schmalste Index gewinnt"), und das ist für sich genommen
vernünftig — es hätte die beiden Systeme bei zwei tauglichen Indexen
auseinanderlaufen lassen: MariaDB nimmt den zuerst angelegten. Auch das
gemessen, nicht überlegt.

#### Und dieselbe Regel stand zweimal da

`Db\Console::keyCondition()` war Zeile für Zeile `Pg\Console::keyCondition()`
mit `` ` `` statt `"`. Als die dritte Prüfung dazukam — **ist jede Spalte des
Schlüssels genannt?** —, wäre die zweite Fassung die gewesen, die sie nicht
bekommt. Die Prüfung steht jetzt einmal in `PgConsole::checkedKey()`, die
Maskierung bleibt je System.

Der neue Fall: Bei einem zusammengesetzten Schlüssel `(b, c)` trifft
`WHERE b = '1'` jede Zeile mit diesem `b`. Gefährlich ist das nicht — die
Anweisung zählt nach und nimmt zurück, was nicht genau eine Zeile war. Aber sie
meldet dann „hat 3 Zeilen getroffen", und das liest sich wie ein
Nebenläufigkeitsproblem statt wie ein unvollständiger Aufruf.

> **Eine Sicherung, die den Schaden verhindert, erklärt ihn nicht.**

### 20.36 Befund 2 aus `docs/47` ist entschieden — der Satz gehört uns

**Die Frage stand seit dem Zwischenlauf offen und war Schritt 6 zugewiesen:** Was
liest ein Kunde, dessen Schreibvorgang nicht genau eine Zeile trifft? Gemessen
gegen 16.13, das war die Antwort vorher:

```
Die Datenbank hat abgewiesen: ERROR:  Der Vorgang hat 0 Zeilen getroffen …
CONTEXT:  PL/pgSQL function inline_code_block line 7 at RAISE
```

Ein Satz, den dieses Panel selbst geschrieben hat — mit einem Vorspann, der sagt,
es habe jemand anders gesprochen, und einer Zeilennummer auf eine Datei, die es
nicht gibt. **MariaDB machte es von Anfang an richtig**: Dort entsteht der Satz
in PHP, weil es keinen anonymen Block gibt. Niemand hat entschieden, dass die
beiden es verschieden machen.

> **Eine Verpackung, die für eine fremde Meldung richtig ist, ist für die eigene
> falsch.**

**Entschieden: der Block schickt eine Zahl, den Satz baut PHP.** `RAISE
EXCEPTION '<Marke>=%'` statt der Prosa; `Console::missed()` macht daraus den
Satz, und zwar für beide Systeme aus einer Quelle. Die Marke steht als
`Console::MISS_MARKER` an einer Stelle, weil sie beim Bauen und beim Lesen
gebraucht wird — zwei Zeichenketten, die aufeinander zeigen, sind der Fehler,
den dieses Projekt sechsmal bezahlt hat.

**Was ausdrücklich bleibt:** Jede andere Meldung behält ihre Verpackung. Beim
Zeitlimit *ist* es die Meldung des Servers, und `docs/36 §17` verlangt sie
wörtlich.

#### `VERBOSITY terse` wäre der naheliegende Fix gewesen und der falsche

Er hätte die `CONTEXT`-Zeile entfernt — und mit ihr die `DETAIL`-Zeile, die bei
drei von vier gemessenen Fehlern die nützliche Hälfte ist:

| Fehler | `DETAIL`, das `terse` wegnähme |
|---|---|
| Fremdschlüssel | `Key (id)=(1) is still referenced from table "kind".` |
| Eindeutigkeit | `Key (id)=(1) already exists.` |
| `NOT NULL` | `Failing row contains (2, null).` |

> **Ein Schalter, der Rauschen entfernt, entfernt es nicht nur dort, wo es
> Rauschen ist.**

#### Und der erste Wurf des Erkenners hat nichts erkannt

`/^ERROR:\s+<Marke>=(\d+)$/m` traf nichts — `Session` setzt
`Die Datenbank hat abgewiesen: ` davor, und die Meldung beginnt damit mitten in
der Zeile. Der Fall sah aus wie „keine eigene Meldung" und fiel still in die
alte Verpackung zurück.

> **Ein Ausdruck, der nichts findet, sieht aus wie einer, der nichts zu finden
> hatte.**

Nach hinten bleibt er streng verankert, und das ist die Hälfte, die zählt:
Gesucht wird eine `ERROR:`-Zeile, auf der nach der Marke nichts mehr folgt. Ein
Kundenwert, der die Marke in einer `DETAIL`-Zeile nachahmt, wird nicht erkannt —
gegengeprüft.

### 20.37 Schritt 6, zweite Hälfte — die Oberfläche und drei Funde beim Bauen

Das Zeilenformular setzt die drei Regeln aus §10.1 um: `NULL` als eigener
Zustand des Feldes, eine gekürzte oder binäre Zelle gesperrt mit dem Grund
daneben, und nur geänderte Spalten in der Anweisung. Dazu Kriterium 5 — eine
Tabelle ohne Schlüssel sagt, **warum** sie nur lesbar ist, und eine Sicht bekommt
einen anderen Satz als eine Tabelle ohne Schlüssel („leg einen Schlüssel an"
wäre dort der falsche Rat).

**`touched` und nicht nur der Vergleich mit dem Ausgangswert.** Beim Ändern
genügte ein Vergleich; beim **Anlegen** gibt es keinen Ausgangswert, und „das
Feld ist leer" hiesse dann entweder „schreib `''`" oder „lass die Vorgabe
gelten" — zwei Dinge, die ein leeres Textfeld nicht auseinanderhält.

#### Fund 1 — Eine Feldbeschriftung schob die Seite um 96px

Jede Beschriftung dieses Formulars ist ein **Spaltenname**, und der darf 63
Zeichen lang sein. Es ist die vierte Fassung derselben Ausnahme, nach `.ident`,
`.stacks td .ident` und dem Bereichstitel.

> **Eine Beschriftung ist so lang wie das, was sie beschriftet — und das
> entscheidet nicht, wer sie gestaltet.**

#### Fund 2 — `min-width: 0` ist nicht die zweite Hälfte, sondern der zweite Weg

Dieses Stylesheet behauptete an **drei** Stellen: „`min-width: 0` gehört dazu,
weil ein Flexkind sonst nicht unter seine Inhaltsbreite darf." Das klingt richtig
und ist seit dem Bereichstitel ungeprüft weitergereicht worden. Gemessen bei
390px mit dem gebauten Stylesheet, am Feld **und** am Bereichstitel, gleiches
Bild:

| `overflow-wrap` | `min-width` | Überlauf |
|---|---|---|
| `anywhere` | `0` | 0px |
| `anywhere` | `auto` | **0px** — allein genug |
| `break-word` | `0` | **0px** — allein genug |
| `break-word` | `auto` | 96px |
| `normal` | `auto` | 96px |

`overflow-wrap: anywhere` verkleinert die **Mindestbreite des Inhalts**,
`break-word` nicht — deshalb bindet `min-width: auto` im einen Fall und im
anderen nicht. Beide Regeln bleiben stehen, jetzt aber mit dem richtigen Grund:
Wer `anywhere` für ein Synonym von `break-word` hält und tauscht, bekommt die
Seite nicht zurückgeschoben.

> **Zwei Regeln, die zusammen wirken, können auch zwei Wege zum selben Ziel sein
> — und welcher davon trägt, sagt nur die Messung.**

Gefunden hat es der **Bruch**: Die eine Hälfte zurückzunehmen sollte nach meiner
eigenen Behauptung 94px ergeben und ergab 0.

#### Fund 3 — Der Wächter über die Rangfolge meinte die Gliederung

`ButtonStyleTest::test_at_most_one_primary_button_per_form` biss mit „3 Knöpfe
mit ‚wichtig' in einem Formular". Die Antwort war nicht, einen Rang wegzunehmen:
Die Filterzeile und das Zeilenformular sind wirklich zwei Formulare, sie waren
nur `<div>`. Nebenbei tut die Eingabetaste jetzt, was man von ihr erwartet.

> **Ein Wächter, der über die Rangfolge klagt, meint manchmal die Gliederung.**

### 20.38 Dreizehn Brüche, und zwei davon haben Lücken gefunden

`RowKeyTest` und `WriteBackTest` messen beide **an der erzeugten Anweisung** und
nicht an einem Ergebnis (§14.6, §14.7). Jede Regel wurde einzeln gebrochen; elf
bissen sofort, zwei nicht.

**Die erste Lücke: Der Wächter las den Kommentar.** `RowKeyTest` verlangt, dass
die MariaDB-Konsole den Satz nicht selbst baut, sondern `PgConsole::missed()`
ruft. Der Bruch entfernte den Aufruf — und der Test blieb grün, weil derselbe
Name zwei Zeilen darüber im **Kommentar** steht, der genau das erklärt.

> **Ein Wächter, der Kommentare liest, wird von der Dokumentation des Fehlers
> beruhigt, vor dem er schützt.**

Dasselbe Muster wie in `ConsoleFanoutTest` und `NullDisplayTest`, nur andersherum:
Dort machte ein Kommentar den Test rot, hier grün. **Die zweite Richtung ist die
gefährlichere, weil sie nach Ordnung aussieht.**

**Die zweite Lücke: ein Zweig von zweien.** `test_null_and_the_empty_string_stay_two_values`
prüfte `NULL` nur beim **Ändern**. Der Bruch (`strval()` über die Werte) traf den
Zweig fürs **Anlegen** und blieb grün — eine neue Zeile mit einem ausdrücklichen
`NULL` in einer nullbaren Spalte ist ein gewöhnlicher Fall und hatte keinen
Wächter. Geprüft werden jetzt vier Fälle: zwei Arten mal zwei Systeme.

> **Ein Wächter, der einen von zwei Zweigen prüft, deckt die Hälfte ab und meldet
> das nicht.**

#### Und `git checkout -- resources/` hat die halbe Stufe weggeworfen

Der Bruchlauf stellte jede Datei mit `git checkout` wieder her — auch die, deren
Arbeit **noch nicht eingecheckt** war. 450 Zeilen Zeilenformular waren fort, in
einem Befehl, der nach Aufräumen aussieht. Die Warnung dafür steht in `CLAUDE.md`
und ist der Grund, weshalb `tests/waechter-brechen.sh` sich bei schmutzigem
`resources/` weigert.

Gerettet hat es eine Dateikopie im Scratchpad, die zehn Minuten vorher aus einem
anderen Grund entstanden war. Seitdem gilt hier: **erst einchecken, dann
brechen** — der Bruch ist ein Werkzeug, das den Baum verändert, und kein Lesen.

> **Ein Wiederherstellen, das nicht zwischen fremder und eigener Änderung
> unterscheidet, ist ein Löschen mit gutem Namen.**

### 20.39 `assertTrue(false, …)` ist keine Behauptung

**Gefunden von PHPStan in der CI**, und hier findet es nichts: `vendor/bin/phpstan`
gibt es in diesem Container nicht. Beide neuen Wächter benutzten für „hier darf
der Lauf nicht ankommen" die Form

```php
$this->assertTrue(false, 'Ein UPDATE ohne Schlüssel entsteht ohne Widerspruch.');
```

`method.impossibleType` — eine Behauptung über eine Konstante ist keine
Behauptung. Sie tut zwar das Richtige, sagt es aber nicht: Wer sie liest, prüft
erst den Wert und dann die Absicht. PHPUnit hat dafür `fail()`, und das ist als
`never` typisiert.

> **Ein Test, der einen Fehlschlag als Behauptung tarnt, prüft dasselbe und
> erklärt weniger.**

Es ist die zweite Meldung dieser Art in dieser Stufe, nach der ungenutzten
Konstante in `ButtonRowSpacingTest` — beide Male hat PHPStan etwas gefunden, das
kein Wächter dieses Projekts sieht und das dieser Container nicht fahren kann.

### 20.40 Die CI hat zwei Fehler gefunden, die kein Wächter hier sieht

**Fund 1 — §14.6 und §14.7 waren längst gebaut.** `tests/Unit/ConsoleStatementTest.php`
gibt es seit Schritt 1 und prüft die PostgreSQL-Anweisung: die Zählung im
`DO`-Block, den Schlüsselzwang, die geänderten Spalten, `NULL` gegen `''`. Der
erste Wurf von `RowKeyTest` und `WriteBackTest` hat das alles noch einmal
aufgeschrieben — genau das Muster, vor dem dieses Projekt an zehn Stellen warnt,
und es ist einem Wächter passiert.

> **Wer eine Regel für ein zweites System aufschreibt, schreibt sie leicht ein
> zweites Mal auf.**

Aufgefallen ist es nicht beim Schreiben, sondern weil `ConsoleStatementTest` rot
wurde: Es verlangte den Satz „nicht genau eine" **in der Anweisung**, und §20.36
hat ihn dort herausgenommen. Ein bestehender Wächter hat also die Doppelung
gemeldet, indem er an seiner eigenen Regel scheiterte.

Die Aufteilung ist jetzt benannt: **`ConsoleStatementTest` gehört die
PostgreSQL-Anweisung als Text**; in `RowKeyTest` und `WriteBackTest` steht, was
dort nicht hinpasst — die MariaDB-Hälfte, die Regeln über beide Systeme zugleich,
und die Oberfläche.

**Fund 2 — die grüne Meldung stand am falschen Ort.** `docs/19 §6.3` nennt genau
eine Stelle dafür: `PanelLayout.vue`. Die Konsole hat eine eigene `notice ok`
bekommen, weil ein `flash` sie nicht erreicht — sie ist die **erste Seite dieses
Panels, die über XHR ändert und dabei stehen bleibt**. `FieldErrorTest` hat das
abgewiesen.

> **Eine Regel, die einen Ort vorschreibt, braucht einen Weg dorthin — sonst baut
> die nächste Seite ihren eigenen.**

Der Weg heisst jetzt `Composables/useAnnounce.ts` und ändert die Regel nicht:
Gerendert wird weiter ausschliesslich im Layout, es gibt weiter genau eine Datei
mit `notice ok`, und `FieldErrorTest` bleibt unverändert. `docs/19 §6.3` hat den
Zusatz bekommen.

**Beide Funde hat nur die CI sehen können.** Das PHPUnit-freie Gestell in diesem
Container fährt die framework-freien Wächter; `ConsoleStatementTest` und
`FieldErrorTest` erben Laravels Basisklasse und laufen hier nicht.

> **Ein Wächter, den die eigene Umgebung nicht fahren kann, findet trotzdem —
> nur später und teurer.**

### 20.41 Die fünfte Fuge — und der Wächter, der vier davon nicht sehen konnte

**Kriterium 5 ist belegt** (Bild vom 13. August gegen `0.5.3-rc.11`): Auf
`protokoll_ohne_schluessel` gibt es keine Spalte „Zeile", kein „Zeile anlegen",
und der Satz steht da. Auf demselben Bild fand der Betreiber den Fehler: Der Satz
klebte unter der Blätterleiste.

Gemessen mit dem gebauten Stylesheet:

| | 1200px | 390px |
|---|---|---|
| Blätterleiste → Hinweis, vorher | **0px** | **0px** |
| Blätterleiste → Hinweis, nachher | 26px | 24px |
| Hinweis → Knopfreihe (seine eigene Kante) | 26px | 24px |
| Meldung → Meldung (Gegenprobe) | 26px | 24px |

`--block-gap` und nicht die 16px der Knopfreihe: Eine Meldung lässt **unter**
sich genau so viel, und ein Abstand, der oben enger ist als unten, setzt sie
sichtbar schief zwischen ihre Nachbarn.

#### Der Wächter hat die falsche Frage gestellt — viermal richtig

`ButtonRowSpacingTest` fragte nach **Knopfreihen**. Das war die Frage der ersten
vier Fälle und trotzdem die falsche: Beim fünften stand dort eine Meldung.

> **Eine Liste von Nachbarn, die wächst, ist keine Regel — sie ist eine
> Aufzählung der Fälle, die schon jemand gesehen hat.**

Er heisst jetzt `BlockSpacingTest` und fragt: **Endet der eine bündig, und fängt
der andere bündig an?** Zwei Listen, alle Paare daraus, und für jedes Paar, das
in einer Vorlage wirklich vorkommt, muss `app.css` eine Nachbarschaftsregel
haben.

#### Und dabei kamen drei Fehler im Wächter selbst heraus

**Erstens: `.pager` stand seit Schritt 5 in der Liste und wurde nie gefunden.**
Der alte Ausdruck las den Vorgänger „bis zum nächsten Tag desselben Namens" —
`.scrolls` geht so, `.pager` nicht, denn darin stehen drei `<div>`. Die
Untergrenze zählte die anderen Bausteine mit und blieb grün.

> **Ein Eintrag in einer Liste, den der Ausdruck nie erreicht, sieht aus wie eine
> Abdeckung und ist eine Lücke.**

Gesucht wird jetzt über die **Verschachtelungstiefe**, und ein dritter Test
verlangt, dass jeder Name beider Listen in einer Vorlage wirklich vorkommt — der
Wächter über den Wächter.

**Zweitens: der Ausdruck las den `<script>`-Block mit.** `ref<HTMLElement | null>`
sieht aus wie ein Tag, das nie zugeht; die Tiefenzählung lief ins Dateiende und
fand fast nichts. Sie meldete das nicht — sie gab weniger Paare zurück.

**Drittens, und das ist der Kern: `<template v-else>` rendert nichts.** Die
Blätterleiste steht darin, die Meldung dahinter — im Quelltext sind sie **keine**
Geschwister, im Browser sind sie es, und dort klebten sie.

> **Ein Wächter, der Markup liest, muss lesen, was gerendert wird — nicht, was
> dasteht.**

Ohne diesen dritten Punkt hätte der neue Wächter genau den Fall nicht gefunden,
für den er gebaut wurde. Belegt hat es der Bruch: Macht man `<template>` wieder
undurchsichtig, sinkt die Zahl der gefundenen Fugen von drei auf zwei — und
`.pager + .notice` ist die, die fehlt.

**Fünf Brüche, fünf Bisse**, und der erste nennt die Fuge des Betreibers
wörtlich: „`Console.vue` setzt `.notice` unmittelbar unter `.pager`, und app.css
kennt diese Nachbarschaft nicht."

### 20.42 Eine Umbenennung, drei Wächter — und mein lokaler Durchgang war eine Liste

Die Umbenennung von `ButtonRowSpacingTest` nach `BlockSpacingTest` hat drei
bestehende Wächter rot gemacht, und **jeder hatte recht**:

| Wächter | Befund |
|---|---|
| `ChangelogTest` | Der Changelog nennt `ButtonRowSpacingTest`, die Datei gibt es nicht mehr |
| `GuardReachTest` | Der Kommentar in `BlockSpacingTest` nennt ihn ebenfalls |
| `NoticeShapeTest` | fand die falsche Regel — dazu unten |

Für die ersten beiden gibt es seit August den vorgesehenen Weg:
`ChangelogTest::REMOVED`, mit Datum und Grund. Ein Test, den es absichtlich nicht
mehr gibt, wird dort eingetragen — und nicht im Fliesstext ohne Rückstriche
umschrieben, was den Wächter umginge statt ihn zu erweitern.

> **Ein Name, den es nicht mehr gibt, ist kein kleineres Problem als ein Name,
> den es nie gab.**

#### `NoticeShapeTest` fand die falsche Regel

Er suchte mit `strpos($css, '.notice {')` — und ab dieser Fassung steht 650
Zeilen früher `:is(.field, …, .button-row) + .notice {`. Derselbe Text, andere
Regel. Der Wächter las seitdem einen Block, in dem nur `margin-top` steht, und
meldete, die Umbrucherlaubnis der Meldung sei fort. Sie stand unverändert da.

> **Ein Ausdruck, der einen Selektor am Namen sucht, findet jeden, der ihn
> enthält.**

Gesucht wird jetzt am Zeilenanfang. Es ist derselbe Fund wie beim `\b` über
Klassennamen in §20.30 — eine Grenze, die für den einfachen Fall reicht und für
den nächsten nicht.

#### Und der eigentliche Befund: mein lokaler Durchgang war eine Liste

Alle drei Wächter **laufen in diesem Container** — sie erben
`PHPUnit\Framework\TestCase` und brauchen kein Framework. Gefahren habe ich sie
nicht, weil das Gestell aus `docs/46 §20.38` mit einer **handverlesenen** Liste
von dreizehn Namen aufgerufen wurde. Framework-freie Wächter gibt es **136**.

> **Eine Prüfung, die nur nachsieht, woran man gerade denkt, prüft das
> Erinnerungsvermögen.**

Der Aufruf zählt seitdem selbst ab: `grep -l 'use PHPUnit\Framework\TestCase;'`
über `tests/`. Was dabei nicht laufen kann — ein Test, der einen Socket braucht
oder eine Laravel-Klasse —, wird als **übersprungen** ausgewiesen und nicht als
grün; eine Zahl, die Übersprungenes mitzählt, wäre schlimmer als keine. Dazu ruft
das Gestell jetzt `setUp()`, sonst scheitern Tests an uninitialisierten
Eigenschaften und sähen aus wie gebrochene Regeln.

**Gemessen am 13. August 2026 über den ganzen Bestand: 468 grün, 1 rot, 263
übersprungen** — und die 263 stehen nach Art daneben:

| kann dieses Gestell nicht | |
|---|---|
| `Error` (Klassen aus `tests/Support`, Laravel) | 154 |
| `setUp()` | 48 |
| `ArgumentCountError` (Datenlieferanten) | 35 |
| bindet `App\` ein — braucht Laravel | 25 |
| `ReflectionException` | 1 |

Der eine rote ist `PolicyReachTest`; er spiegelt über `App\Policies\…`, und in
der CI ist er gegen denselben Commit grün.

> **Ein Wächter ausserhalb seiner Umgebung ist rot, ohne dass etwas kaputt
> ist — und das ist genauso wertlos wie grün.**

#### Und die Einteilung selbst ist dreimal danebengegangen

**Erst war der Topf zu.** Jeder Fehler galt als „übersprungen", und ein
`ArgumentCountError` aus meinem eigenen `sprintf()` verschwand darin — ein echter
Bug in einer Abfrage, die ich gerade geschrieben hatte (§20.46).

> **Ein Topf für „geht hier nicht" nimmt jeden Fehler auf, der nicht
> widerspricht — und macht ihn unsichtbar.**

**Dann sollte der Wortlaut entscheiden.** `str_contains($e->getMessage(), 'not
found')` — und 104 Wächter kippten in die falsche Richtung, weil PHP für eine
fehlende Klasse je nach Weg „not found" **oder** „does not exist" schreibt.

> **Ein Kriterium, das den Wortlaut einer Meldung liest, ist so genau wie die
> Laune dessen, der sie geschrieben hat.**

Es ist derselbe Fehler wie das `\b` über Klassennamen (§20.30) und das `strpos`
über `.notice {` (§20.42) — **dreimal an einem Tag**, und das dritte Mal in
meinem eigenen Werkzeug.

**Dann strukturell**, und auch das griff nicht: Der Ausdruck `/^use App\\/m`
wurde beim Durchreichen zu `/^use App\/m` und suchte einen Schrägstrich. Er
legte dabei offen, dass das Gestell auch an Datenlieferanten, `expectException()`
und Hilfsklassen scheitert — die Einteilung „läuft/läuft nicht" hat also gar
keine einfache Bedingung.

**Die Antwort ist keine bessere Bedingung, sondern ein offener Topf.** Jede
Ursache wird nach Art gezählt und ausgewiesen; ein neuer Grund fällt damit auf,
ohne dass irgendwo eine Liste von Fehlertexten gepflegt werden muss.

> **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die kleiner
> werden kann.**

Wer die Zahl benutzt, vergleicht sie gegen die CI und nicht gegen null. Was das
Gestell trägt, sind die **Textwächter**: Sie lesen Dateien und brauchen nichts
weiter.

### 20.43 Befund 2 aus `docs/47` ist geschlossen — in beiden Systemen wörtlich gleich

**Gemessen am 13. August 2026 auf `cloudsrv24` gegen `0.5.3-rc.12`**, mit einem
Eingriff von aussen bei offenem Formular.

| | PostgreSQL (`x1b311d2b6eedc3aa_p5c`) | MariaDB (`p1130_p5c`) |
|---|---|---|
| Zeilen vorher | 120 | 16384 |
| Wegwerfzeile angelegt | `INSERT 0 1` | `1` |
| von aussen gelöscht, Formular offen | `DELETE 1` | ohne Ausgabe |
| **Meldung im Panel** | `Der Vorgang hat 0 Zeilen getroffen und nicht genau eine; nichts wurde geändert.` | **wörtlich dieselbe** |
| Zeilen danach | 120 | 16384 |

Kein `Die Datenbank hat abgewiesen: ERROR: …`, keine
`CONTEXT: PL/pgSQL function inline_code_block line 7 at RAISE`. Der Satz kommt
aus `Console::missed()` und damit in beiden Systemen aus einer Quelle.

**Die letzte Zeile der Tabelle ist die, die zählt.** Eine Fehlermeldung belegt,
dass etwas abgewiesen wurde; sie belegt nicht, dass nichts geschrieben wurde.
Erst die unveränderte Zeilenzahl macht aus der Meldung einen Beleg.

> **Ein Schreibvorgang, der nicht nachzählt, was er getroffen hat, meldet Erfolg
> für einen Treffer, den niemand geprüft hat.**

### 20.44 Und die Zeilenzahl behauptete fünf Stellen, die sie nicht hat

**Auf demselben Bild, und kein Test konnte es sehen.** Die Beizeile der
MariaDB-Tabelle las sich

```
Tabelle bestellpositionen_archiv_2026_langer_name_zum_messen · 16.008 Zeilen · 3,3 MB · mit Schlüssel
```

`SELECT COUNT(*)` sagte **16384**. Die Zahl ist nicht falsch gerechnet — sie ist
die Schätzung aus dem Katalog, und dass es eine ist, hat `docs/46 §9`
entschieden, weil die Zählung selbst die teure Abfrage wäre. **Falsch war, dass
nichts es sagt:** Das Wort „geschätzt" stand in diesem Panel ausschliesslich in
Quelltextkommentaren.

> **Eine Zahl ohne das Wort, das sie einschränkt, behauptet mehr als sie weiss.**

Es ist derselbe Fehler wie „0 B" für eine Sicht und „0 Zeilen" für eine
unbekannte Zahl, nur andersherum: Dort log eine Null über etwas, das es nicht
gibt, hier eine Genauigkeit über etwas, das es ungefähr gibt. Die Beizeile sagt
jetzt `geschätzt 16.008 Zeilen`.

**Warum kein Test das finden konnte**, und das gehört dazu: Die Zahl ist richtig
gerechnet und richtig formatiert. Es gibt keinen Zustand, in dem der Code sich
widerspricht — nur einen, in dem er etwas verschweigt. Gefunden hat es der
Betreiber, weil neben dem Bild ein `SELECT COUNT(*)` im Terminal stand, das für
etwas ganz anderes gefahren wurde.

> **Ein Bild vom echten Server zeigt auch das, wofür es nicht aufgenommen
> wurde.** Zum dritten Mal in dieser Stufe.

`NullDisplayTest::test_an_estimated_row_count_says_so` prüft seitdem die
**Nachbarschaft** und nicht das Vorkommen: Dass das Wort irgendwo in der Datei
steht, sagt nichts — es muss an der Zahl stehen.

### 20.45 Und ein Nebenbefund für Schritt 9: die beiden Fixturen laufen auseinander

Der MariaDB-Bestand hat `id = 9001` bereits belegt (der `INSERT` scheiterte mit
`Duplicate entry`), PostgreSQL nicht; MariaDB hat ausserdem eine Tabelle
`ohne_schluessel_lang`, die es dort nicht gibt, und 16384 Zeilen gegen 120.

Für diese Messung ist das gleichgültig. Für den Abnahmelauf aus §15 nicht:
**„jeder Punkt zweimal gefahren" prüft zweimal etwas anderes, wenn die Bestände
verschieden sind.** Das ist vor Schritt 9 zu klären und hier notiert, damit es
dort nicht neu auffällt.

> **Zwei Läufe über zwei verschiedene Bestände sind zwei Messungen und keine
> Gegenprobe.**

**Geklärt am 13. August 2026, und zwar als Punkt 0 von §15** — nicht als
Aufräumen nebenbei, sondern als erster Schritt des Laufs, mit `DROP` vor
`CREATE`: Was hier schiefging, war ein Rest aus einem früheren Lauf, und ein
Bestand, der nur ergänzt wird, trägt ihn weiter.

Dazu ein Vergleich, der belegt, dass die beiden Seiten danach gleich **sind** —
zeichengleiche Ausgabe aus einer Abfrage, die in beiden Systemen dieselbe
Zeichenkette ist. Gefahren gegen PostgreSQL 16.13 und MariaDB 10.11.14 im
Container; die Gegenprobe (eine Tabelle und eine Zeile mehr auf einer Seite,
also genau die beiden Abweichungen von oben) meldet beide.

> **Ein Vergleich, der nie etwas findet, ist keiner** — auch der zwischen zwei
> Beständen braucht seinen eigenen Bruch.

Drei Stellen unterscheiden sich dabei zwischen den Systemen und sind im Plan
benannt, weil jede einzeln den Lauf verfälschen würde: `e'a\tb'` gegen `'a\tb'`
(ohne das `e` prüft Punkt 1 in PostgreSQL eine Zeichenkette statt eines
Tabulators), `generate_series` gegen `seq_1_to_N`, und `text` gegen
`varchar(64)` für die Spalte des eindeutigen Index — MariaDB indiziert `TEXT`
nicht ohne Längenangabe, und ohne den Index gäbe es die Tabelle nicht, an der
§10 Regel 2 hängt.

### 20.46 Bild 6 ist belegt — und die Beizeile widersprach der Seite, auf der sie stand

**§10 Regel 2 im Betrieb, gemessen auf `cloudsrv24` gegen `0.5.3-rc.12`.** Eine
Tabelle ohne Primärschlüssel, aber mit einem eindeutigen Index über eine
`NOT NULL`-Spalte ist in **beiden** Systemen änderbar: Spalte „Zeile" mit
„Ändern", „Zeile anlegen", und das Formular mit `kennung` und `ort`. Daneben die
Gegenproben — `protokoll_ohne_schluessel` und `ohne_schluessel_lang` bleiben nur
lesbar, mit ihrem Satz.

**Auf demselben Bild stand der Fehler:**

```
Tabelle nur_unique · Zeilenzahl unbekannt · 32 KB · ohne Schlüssel
```

„ohne Schlüssel" — über einer Tabelle, deren Zeilen die gleiche Seite ändern
lässt. Die Beizeile kommt aus `tables()`, die Bearbeitbarkeit aus `columns()`,
und beim Bau von §20.35 habe ich **eine von zwei Stellen** angefasst.

> **Eine Regel an zwei Stellen ist keine Regel, sondern eine Absprache — und sie
> hält genau bis zur ersten Änderung.**

Kein Test konnte das sehen: Beide Abfragen waren für sich genommen richtig, und
keine widersprach sich selbst. Es ist der Fehler, vor dem dieses Projekt am
häufigsten warnt, in seiner reinsten Form — und er ist mir beim Beheben eines
anderen unterlaufen.

#### Wie es jetzt aussieht

**PostgreSQL** teilt die **Bedingung** in `Console::KEY_INDEX`; jede der beiden
Abfragen schreibt ihr eigenes `SELECT` darum. **MariaDB** liest in beiden
Abfragen `information_schema.COLUMNS.COLUMN_KEY` — dieselbe Spalte, aus der auch
der implizite Primärschlüssel kommt.

Der MariaDB-Teil hat dabei einen eigenen Grund: **Sie befördert den eindeutigen
Index zwar zum impliziten Primärschlüssel, benennt ihn aber nicht um.** Die alte
Abfrage suchte einen Index namens `PRIMARY`, und den gibt es dann nicht.

> **Zwei Abfragen an dieselbe Frage sind zwei Antworten, solange sie nicht
> dieselbe Spalte lesen.**

Gemessen gegen beide Wegwerf-Server, zwölf beziehungsweise acht Fixturen, und
Tabellenliste und Spaltenliste stimmen jetzt in jeder überein.

#### Und zwei Fehler auf dem Weg dorthin

**Der geteilte Baustein war der falsche.** Der erste Versuch zog das ganze
`SELECT` in die Konstante — die Tabellenliste braucht `SELECT 1`, die
Spaltenliste `SELECT i.indkey`, und PostgreSQL wies es mit `cannot cast type
integer to smallint[]` ab.

> **Zwei Stellen, die dieselbe Regel brauchen, brauchen nicht dieselbe
> Abfrage.**

**Und mein Gestell hat den Fehler als „übersprungen" abgelegt.** Der fehlende
`sprintf()`-Parameter warf einen `ArgumentCountError`, und der landete im selben
Topf wie eine Laravel-Klasse, die es hier nicht gibt: als etwas, das man nicht
wissen kann. Es war ein Fehler in einer Abfrage, die ich gerade geschrieben
hatte.

> **Ein Topf für „geht hier nicht" nimmt jeden Fehler auf, der nicht
> widerspricht — und macht ihn unsichtbar.**

Übersprungen wird seitdem nur noch, was nach fehlender Umgebung aussieht —
„class not found" und eine uninitialisierte Eigenschaft aus einem fehlenden
`setUp()`. Alles andere zählt rot.

**Vier Brüche, vier Bisse.** Der dritte hat den Wächter dabei geschärft: Er
prüfte `COLUMN_KEY` und blieb grün, als die Sicht von `COLUMNS` auf `STATISTICS`
wechselte.

> **Ein Feldname ohne seine Tabelle ist eine halbe Angabe.**

### 20.47 Schritt 7 — das Protokoll, und die Hälfte, die man nicht sieht

**Vier Einträge, und drei davon sind der einfache Teil.** `database.console.row.created`,
`.changed` und `.removed` entstehen im Griff, **nachdem** der Vorgang gelungen
ist — ein abgewiesener Schreibvorgang hat nichts geändert, und ein Eintrag über
einen Versuch beantwortet keine Frage, die jemand stellt.

Der Kontext trägt **Tabelle und Schlüssel** und keine Werte (Entscheidung 4). Der
Schlüssel gehört ausdrücklich hinein: Er sagt, *welche* Zeile es war. Die
geänderten Werte sagen *worauf*, und das ist die Frage, die das Protokoll nicht
beantworten soll.

> **Ein Protokoll, das den Inhalt mitschreibt, ist eine Datenhaltung mit einem
> anderen Namen.**

**Drei Namen und nicht einer mit der Art im Kontext.** Wer nach „hier wurde
gelöscht" sucht, filtert nach einer Aktion und nicht nach einem Feld in einem
JSON — so machen es `database.dump.created` und `database.user.removed` auch.

#### Der vierte ist der, der einen Wächter braucht

`database.console.opened` ist **entprellt: einer je Datenbank und Stunde.** Ohne
den Eintrag beantwortet das Protokoll „was wurde geändert" und nicht „wer hatte
Zugriff" — und die zweite Frage ist die, die im Zweifel gestellt wird. Ohne die
Entprellung stünde er bei jedem Betreten darin, und die Konsole wird beim
Arbeiten mehrfach betreten und verlassen.

**Warum gerade er den Test braucht:** Ein Eintrag entsteht sichtbar. Wer die
Konsole öffnet und ins Protokoll sieht, bemerkt sofort, ob eine Zeile dasteht.
Eine **fehlende** Entprellung sieht beim ersten Mal genauso aus und fällt erst
nach einer Woche auf — also genau dann, wenn das Protokoll gebraucht wird und
nichts mehr hergibt.

> **Ein Fehler, der beim ersten Mal richtig aussieht, hat keinen Finder.**

#### Die Entprellung fragt nach *wer* und nicht nur nach *was*

`action`, `target_type`, `target_id`, **`account_id`** und `created_at`. Ohne die
handelnde Person verschluckt sie den Fall, für den man das Protokoll liest: Sieht
ein Admin über „Anmelden als" in dieselbe Datenbank, in der der Kunde gerade war,
gehört das in einen eigenen Eintrag — sonst schweigt das Protokoll genau dort.

Die Spanne fragt an `created_at` und damit an UTC. Das ist Speicherung und keine
Anzeige; die Anzeigezeitzone aus `docs/40` bleibt aussen vor, denn eine Spanne,
die in der Zone des Betrachters rechnet, wäre je nach Sommerzeit eine andere.

#### Und der Test nennt hier ausnahmsweise die Zahl

Sonst gilt in diesem Projekt der Satz aus P4:

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.**

Hier **ist** die Anzahl die Regel — „einer je Datenbank und Stunde" ist der
Wortlaut von Entscheidung 5, Punkt 4 —, und deshalb steht die 3600 als benannte
Konstante da und nicht als Zahl mitten im Griff.

**Sechs Brüche, sechs Bisse:** Löschen aus der Aktionsliste; Schlüssel aus dem
Kontext; Werte in den Kontext; Entprellung durch eine gewöhnliche Aufzeichnung
ersetzt; Spanne auf eine Minute; und die Frage nach der handelnden Person
entfernt.

### 20.48 Schritt 8 — 51 Eingriffe, und zwei davon haben etwas über sich selbst gesagt

`tests/waechter-brechen.sh` hat die Brüche der Schritte 1 bis 7 bekommen: 51
Eingriffe für zwölf Wächter, jeder mit dem Anlass daneben, aus dem er entstanden
ist. Bis dahin standen sie in Sitzungsverläufen — gefahren worden waren sie
alle, wiederholen konnte sie niemand.

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile Code
> ist.** (Derselbe Satz wie bei `docs/39`.)

#### Der erste Fund: eine Überschrift, die den Eingriff darunter verschluckt

`echo "── RowKeyTest: „nur lesbar" ohne Begründung ──"` — deutsches
Anführungszeichen auf, ASCII-Anführungszeichen zu. Damit stehen drei davon in
der Zeile: Das mittlere beendet die Zeichenkette der Shell, das letzte öffnet
eine neue, und alles bis zum nächsten wäre **Text und kein Befehl** gewesen.

Das ist wortwörtlich der Fund vom 11. August, für den
`BreakScriptTest::test_no_heading_swallows_the_intervention_below_it` gebaut
wurde — und er hat beim ersten Lauf zugebissen. Hier ist er zum zweiten Mal
passiert, in derselben Datei, von derselben Hand.

> **Eine Regel, die man kennt, schützt nicht vor dem Fehler, den man nicht
> bemerkt.**

#### Der zweite: ein Bruch, der abstürzt, statt zurückzufallen

Der Eingriff zu `RowKeyTest::test_the_marker_is_one_constant_on_both_sides` soll
den Zustand von vor `docs/47` Befund 2 herstellen — den Satz für den Kunden
wieder in den `DO`-Block schreiben. Geschrieben stand er als

    RAISE EXCEPTION 'Der Vorgang hat % Zeilen getroffen', getroffen;

und das ist kein Rückfall, sondern ein Absturz: Der Block ist die
Formatzeichenkette eines `sprintf()`, und `%` mit einem Leerzeichen dahinter ist
dort keine Angabe. PHP wirft `ValueError: Unknown format specifier`, der Testfall
bricht ab, und die Klasse meldet „übersprungen" statt „rot".

**Ein Bruch, der abstürzt, prüft den falschen Fehlschlag.** Der Wächter war nie
rot; er ist gar nicht erst dazu gekommen. Gemerkt hat es nur, dass die Prüfung
danach nach `ROT` gesucht hat und etwas anderes fand.

> **Ein Bruch muss die Regel verletzen und nicht den Code zerstören. Der
> Unterschied sieht in der Ausgabe fast gleich aus.**

Richtig ist `%%` — dann steht in der Anweisung ein einzelnes Prozentzeichen, so
wie es vor der Korrektur wirklich dastand.

#### Gefahren, hier, ohne PHPUnit

47 der 51 Eingriffe sind in diesem Container einzeln gefahren worden: anwenden,
den Wächter über das Gestell aus `docs/46 §20.42` laufen lassen, auf `ROT` mit
**dem genannten Namen** prüfen, zurücksetzen. Alle 47 haben zugebissen.

**Vier konnten es hier nicht**, und das ist eine Eigenschaft des Gestells und
kein Befund: `ConsoleQueueTest` bindet `App\Support\Operations\Task` ein und
braucht damit Laravel (zwei Eingriffe), und
`ConsoleStatementTest::test_an_unknown_identifier_never_becomes_a_statement`
benutzt `expectException()`, das dieses Gestell nicht hat. Sie hängen am Lauf des
Skripts in der CI.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Deshalb prüft der Fahrplan auf den *Namen* des roten Tests und nicht
> darauf, dass irgendetwas rot war — sonst hätte der abstürzende Eingriff oben
> wie ein Biss ausgesehen.
