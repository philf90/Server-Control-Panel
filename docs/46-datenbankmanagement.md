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

### Schritt 4 — Tabellen und Struktur

Die beiden lesenden Ansichten, mit Screenshots in beiden Themes und bei 390 px.

### Schritt 5 — Zeilen, blättern, filtern, sortieren, eine Zelle öffnen

Die dritte Ansicht — der Baustein aus §11 — mit den **drei** Filteroperatoren,
`limit + 1` statt einer Anzahl, und der Zelleinzelsicht dazu (Entscheidung 5,
Punkte 1 bis 3). Screenshots, und die Überlaufmessung **an beiden Stellen**.

Die Zelleinzelsicht gehört hierher und nicht zu Schritt 6: Sie ist der Ausweg aus
der Kürzung und wird gebraucht, sobald die Tabelle Werte zeigt — nicht erst,
sobald jemand sie ändern darf.

### Schritt 6 — Ändern

Anlegen, ändern, löschen; die Schlüsselregel aus §10; die Prüfung auf genau eine
Zeile; **und die drei Regeln des Schreibwegs aus §10.1** — `NULL` als eigener
Zustand, eine gekürzte Zelle gesperrt, nur geänderte Spalten in der Anweisung.

**Er stand zur Debatte als der Schnitt** für den Fall, dass die Stufe kleiner
werden soll (Entscheidung 5); der Betreiber hat ihn drin gelassen. Nach Schritt 5
steht trotzdem etwas Fertiges — die Reihenfolge bleibt so.

### Schritt 7 — Das Protokoll

Die drei ändernden Handlungen ins Audit-Protokoll, ohne Werte — und der
entprellte Eintrag beim Öffnen, einer je Datenbank und Stunde (Entscheidung 5,
Punkt 4).

**Die Entprellung ist der Teil, den ein Test braucht, und nicht der Eintrag.**
Ein Eintrag entsteht sichtbar; eine fehlende Entprellung sieht man erst, wenn
das Protokoll nach einer Woche nur noch aus Konsolenzeilen besteht.

### Schritt 8 — Die Wächter brechen

`tests/waechter-brechen.sh` um die Eingriffe aus §14 erweitert, jeder einmal rot
gesehen.

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
# PostgreSQL-Datenbank, ein zweites Abonnement B mit je einer.
# Der Lauf legt sie NICHT selbst an (docs/35).
# Jeder Punkt wird ZWEIMAL gefahren — einmal je System.

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

# 3  KEINE FREMDE TABELLE  ← Kriterium 3
#    Die Konsole von Abo A auf eine Tabelle aus Abo B richten. Der Weg dafür
#    ist die Adresse, nicht die Oberfläche — die zeigt sie gar nicht erst an.
#    erwartet: Abweisung.
#    UND DER BELEG IST DIE HERKUNFT DER MELDUNG. Zwei Wände stehen hier
#    hintereinander:
#      (a) die Mandantenklammer des Panels (403, bevor der Agent gefragt wird),
#      (b) die Rechte der Datenbank.
#    Um (b) zu belegen, wird (a) für einen Lauf ABGESCHALTET — Tenancy::
#    withoutRestriction() in einer Wegwerf-Zeile — und dann muss die Meldung
#    von PostgreSQL bzw. MariaDB kommen, wörtlich.
#    OHNE DIESEN SCHRITT IST KRITERIUM 3 NICHT GEFAHREN: Mit (a) allein wäre
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
  „ein zweites Werkzeug".
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
| Oberfläche | drei Ansichten und eine Zelleinzelsicht unter `Pages/Databases/` |
| Wächter | acht neue, elf Brüche |
| Migrationen | **keine** — die Konsole führt keinen Zustand |
| Positivliste | **keine Erweiterung** — `psql` und `mysql` stehen seit P5/P5b |
| Paketabhängigkeiten | **keine Erweiterung** (Entscheidung 1) |

Dass die letzten drei Zeilen leer sind, ist die eigentliche Aussage dieses
Plans: **P5c fügt dem System keinen neuen Weg mit Rechten hinzu.** Es benutzt
den, unter dem seit P5 fremde Dumps laufen.

Geschätzt 2–3 Wochen, im Zuschnitt von P5b.

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

### 20.7 Fünfmal `POST`, und der Einstieg ist noch keine Seite

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
