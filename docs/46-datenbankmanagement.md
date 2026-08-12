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

### 2.3 Was hier nicht zu messen war

Diese fünf gehören **auf `cloudsrv24` gemessen, bevor Schritt 2 anfängt** —
nicht danach, und nicht geschätzt. Sie betreffen alle MariaDB, für die es in
diesem Container keinen Server gibt.

1. **`JSON_OBJECT()` über eine `BLOB`-Spalte mit ungültigem UTF-8.** Die
   PostgreSQL-Hälfte ist gemessen (M8, `bytea` wird zu Hex). MariaDB kann hier
   einen Fehler werfen statt einer Zeichenkette — und dann bräche die Konsole an
   genau der Tabelle, an der sie am nötigsten ist.
2. **Ob `mysql --batch` eine JSON-Zeile unverändert durchlässt.** `--batch`
   maskiert Tabulator, Zeilenumbruch **und den Rückstrich** in der Ausgabe. Eine
   JSON-Zeichenkette besteht aus maskierten Rückstrichen; eine zweite Maskierung
   darüber ergäbe `\\n`, wo `\n` stehen soll. **Das ist die wahrscheinlichste
   Stelle, an der dieser Plan für MariaDB nicht trägt**, und `--raw` ist die
   Gegenprobe dazu.
3. **`max_statement_time`** — ob er greift und ob er, wie `statement_timeout`
   in M11, vom Rolleninhaber zurückgenommen werden kann. Für P5c ist nur die
   erste Hälfte nötig (§9), die zweite gehört trotzdem gemessen: Sie
   entscheidet, ob der Satz aus §9 für beide Systeme gilt oder nur für eines.
4. **`information_schema.TABLES.TABLE_ROWS` bei InnoDB** — eine Schätzung, und
   die Frage ist, ob sie wie `reltuples` einen Wert für „unbekannt" hat (M19)
   oder stillschweigend `0` meldet.
5. **Ob ein Kundenbenutzer `information_schema` gefiltert sieht.** Die
   PostgreSQL-Hälfte ist gemessen (M18). Für MariaDB ist es die Grundlage der
   ganzen Tabellenliste.

> **Ein Plan, der eine Bauform nennt, hat sie noch nicht gemessen.**
> ([38 §6](38-postgresql.md), und hier zum zweiten Mal.)

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

---

## 3a. Fünf Empfehlungen zum Zuschnitt — offen

Die vier Entscheidungen stehen. Was hier steht, sind Vorschläge **innerhalb**
ihres Rahmens, entstanden beim Ausschreiben des Plans; keiner ist entschieden,
und keiner ändert eine der vier.

1. **Der Filter fängt mit drei Operatoren an, nicht mit acht.** `=`, `enthält`,
   `ist NULL`. Die Filterzeile ist das dichteste Bedienelement der ganzen Fläche
   und steht bei 390 px über einer Tabelle, die schon waagerecht rollt; und acht
   Operatoren sind acht Wege, auf denen die Maskierung falsch sein kann. Die
   übrigen kommen dazu, wenn jemand die drei benutzt hat.
2. **Kein `count(*)` über einen Filter.** Es wird `limit + 1` geholt, und die
   Oberfläche sagt „mehr als 50" statt einer Zahl. Ein `count(*)` über eine
   gefilterte Spalte ohne Index ist genau die Abfrage, die ins Zeitlimit läuft —
   und sie liefe **jedes Mal**, auch für den, der nur die erste Seite ansieht.
3. **Eine Zelle einzeln ansehen.** Nach §9 ist eine Zelle bei 512 Zeichen
   gekürzt und nach §10.1 dann gesperrt — ohne einen Weg zum ganzen Wert ist das
   eine Sackgasse. Eine Ansicht für **eine** Zelle, mit eigener, höherer Grenze.
4. **Ein Protokolleintrag beim Öffnen der Konsole, entprellt.** Entscheidung 4
   hält die ändernden Handlungen fest, und das ist richtig. Was dann offenbleibt,
   ist „wer hatte überhaupt Zugriff" — und mit Entscheidung 3 und der
   Impersonation aus §3 ist genau das die Frage, die im Zweifel jemand stellt.
   Ein Eintrag je Datenbank und Stunde beantwortet sie, ohne dass das Protokoll
   mit dem Blättern wächst.
5. **Die optimistische Sperre wird benannt und nicht gebaut.** Zwei Personen, die
   dieselbe Zeile gleichzeitig öffnen, überschreiben einander; §10 fängt das
   nicht, weil die Zeile ja getroffen wird. Die Regel aus §10.1 — nur geänderte
   Spalten — macht den Schaden klein genug, und ein Kunde, der sich selbst ins
   Gehege kommt, ist in dieser Umgebung selten. Es steht in §18.

**Und wenn die Stufe kleiner werden soll, ist Schritt 6 der Schnitt.** Lesen
trägt den grössten Teil des Nutzens und keines der Risiken aus §10.1; die
Reihenfolge in §13 ist bereits so gebaut, dass nach Schritt 5 etwas Fertiges
dasteht.

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
>    wird;
> 7. das Protokoll die drei ändernden Handlungen führt und **keinen Zellenwert**.
>
> **Für beide Datenbanksysteme**, jeder Punkt zweimal gefahren.

Punkt 3 ist Punkt 3 aus [38 §3](38-postgresql.md) an einer neuen Stelle, und der
Zusatz ist der Kern: Wenn das Panel den Zugriff abweist, beweist der Lauf die
Prüfung des Panels und nicht die Trennung der Datenbank. Der Beleg ist die
Meldung des Servers.

Punkt 2 ist die Umsetzung von M7 in ein Kriterium — mit derselben Vorsicht, die
`docs/36 §17` gelernt hat: **die vier Werte gehören ins Protokoll, nicht ihre
Anzahl.** „Sonderzeichen kommen an" wäre erfüllt, solange irgendetwas ankommt.

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

**Binäre Spalten zeigt die Konsole als Länge und nicht als Wert.** `bytea` und
`BLOB` werden als `<binär, 48 kB>` dargestellt und lassen sich nicht ändern. Das
ist eine benannte Lücke und keine Nachlässigkeit: Ein Hexblock in einer
Tabellenzelle hilft niemandem, ein Bild kann diese Oberfläche nicht anzeigen, und
das Ändern eines binären Werts über ein Textfeld wäre ein Weg, Daten zu
beschädigen, ohne es zu merken.

---

## 9. Die Grenzen, und woher jede ihre Zahl hat

| Grenze | Wert | Woher |
|---|---|---|
| Zeilen je Seite | **50** | M20: 11 KB JSON gegen 1 MiB Anfragegrenze — Raum um den Faktor 90 für breite Tabellen |
| Zeichen je Zelle | **512**, gekürzt markiert | M21/M22: eine Zelle mit 3 MB sprengt die Grenze allein, gekürzt sind es 524 Byte |
| Zeitlimit je Abfrage | **5 s** | eine Konsolenabfrage ist eine Bedienung und kein Vorgang |
| Zeilenzahl | **Schätzung** aus dem Katalog | ein `count(*)` über eine grosse Tabelle ist selbst die teure Abfrage |

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

**Die Schätzung hat einen dritten Zustand, und der ist der gefährliche.** M19:
`pg_class.reltuples` ist `-1` für eine Tabelle, die noch nie analysiert wurde —
nicht `0`. Wer die Zahl unbesehen anzeigt, schreibt „−1 Zeilen" unter eine
Tabelle mit Inhalt; wer sie auf `max(0, …)` klemmt, schreibt „0 Zeilen" und das
ist schlimmer, weil es aussieht wie eine Antwort. Die Oberfläche zeigt
**„unbekannt"**, und `docs/41` hat denselben Satz schon einmal gebraucht:

> **Eine Zahl, die „nicht gemessen" bedeutet, darf nicht wie eine Messung
> aussehen.**

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
  Spalte mit einem Operator aus einer festen Liste (`=`, `≠`, `enthält`,
  `beginnt mit`, `ist NULL`, `ist nicht NULL`, `<`, `>`), mit Blättern.

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

Vier Paare. `EngineReachTest` verlangt zu jeder `db.*` eine `pg.*`, und keine
davon braucht einen Ausnahmeeintrag.

| Operation | nimmt | gibt |
|---|---|---|
| `db.console.tables` · `pg.console.tables` | `database` | Liste `{schema, name, rows|null, bytes, key: bool}` |
| `db.console.columns` · `pg.console.columns` | `database, schema, table` | Liste `{name, type, nullable, default, key, binary}` + Indexe |
| `db.console.rows` · `pg.console.rows` | `database, schema, table, order, direction, offset, limit, filter?` | `{columns, rows, truncated: list<string>}` |
| `db.console.row.write` · `pg.console.row.write` | `database, schema, table, mode, key, values?` | `{affected}` |

**Keine davon geht durch die Warteschlange.** Ein eingereihter Vorgang legt seine
Argumente in `operations.payload` ab — und dort stünde bei `console.row.write`
der Inhalt einer Kundenzeile und bei `console.rows` ein Filterwert. Das ist
dieselbe Regel wie für Passwörter ([36 §4](36-datenbanken.md)) mit einem neuen
Anlass, und sie hat seit P5 einen Wächter (`SecretsStayOutOfTheQueueTest`), der
dafür erweitert wird.

> **Was nicht in der Warteschlange stehen darf, ist nicht nur ein Geheimnis —
> es ist alles, was dem Kunden gehört.**

Alle vier laufen als unmittelbarer Aufruf (`Client::call`), wie
`db.user.create`. Ein Vorgang mit Fortschrittsanzeige wäre für eine Anzeige, die
in 50 ms fertig ist, die falsche Bauform.

---

## 13. Die Schritte, in dieser Reihenfolge

Kein Schritt beginnt, bevor der vorige grün ist. Jeder ist für sich lieferbar.

### Schritt 0 — Die fünf offenen Messungen (§2.3)

Auf `cloudsrv24`, gegen MariaDB 10.11.14, **vor Schritt 2**. Ergebnis ist ein
Abschnitt in diesem Dokument, kein Code. Fällt Messung 2 (`--batch` maskiert die
Maskierung), ändert das §8 für MariaDB — und dann ist es besser, es steht hier,
als dass es in Schritt 2 als Fehler auftaucht.

### Schritt 1 — Der Agent für PostgreSQL

`Pg\Console` (die Katalogfragen und der Bau der Anweisungen), die vier
`pg.console.*`-Operationen, `Names::ephemeral()` um das `c`-Zeichen erweitert,
`Names::isEphemeral()` mit. Gemessen gegen einen Wegwerf-Cluster im Container —
das geht hier vollständig, ohne Panel und ohne Warteschlange.

### Schritt 2 — Derselbe Agent für MariaDB

`Db\Console` und die vier `db.console.*`. Gegen `cloudsrv24`, weil es hier keinen
Server gibt. Am Ende steht `EngineReachTest` grün, ohne Ausnahmeeintrag.

### Schritt 3 — Die Anwendung

`App\Support\Databases\Console`, der Controller, die Routen mit `can:`, die
Policy-Methode, die `can`-Ablage im Inertia-Payload. Keine Oberfläche.

### Schritt 4 — Tabellen und Struktur

Die beiden lesenden Ansichten, mit Screenshots in beiden Themes und bei 390 px.

### Schritt 5 — Zeilen, blättern, filtern, sortieren

Die dritte Ansicht — der Baustein aus §11. Screenshots, und die
Überlaufmessung **an beiden Stellen**.

### Schritt 6 — Ändern

Anlegen, ändern, löschen; die Schlüsselregel aus §10; die Prüfung auf genau eine
Zeile; **und die drei Regeln des Schreibwegs aus §10.1** — `NULL` als eigener
Zustand, eine gekürzte Zelle gesperrt, nur geänderte Spalten in der Anweisung.

**Dies ist der Schritt, der herausfällt, wenn die Stufe kleiner werden soll**
(§3a). Nach Schritt 5 steht etwas Fertiges.

### Schritt 7 — Das Protokoll

Die drei ändernden Handlungen ins Audit-Protokoll, ohne Werte.

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
#              folge; der Filter reduziert die Trefferzahl.
#    BELEG: die erste und die letzte Kennung jeder Seite. „Es blättert" ist
#           keine Erfüllung — zwei gleiche Seiten blättern auch.

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
#      SELECT rolname FROM pg_roles WHERE rolname LIKE '%\_c%';     → leer
#      SELECT user FROM mysql.user WHERE user LIKE '%\_c%';         → leer
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

---

## 17. Was aus P5b offen bleibt

Unverändert die zwei Punkte aus [42 §5](42-abnahme-p5b.md), und P5c fasst sie
nicht an: der `template1`-Beleg, und ob ein Zugang ohne jede Datenbank überhaupt
entstehen kann. Beide sind nie gemessen worden. Wer sie anfasst, fängt dort an
und nicht bei null.

---

## 18. Risiken, ehrlich benannt

1. **Die MariaDB-Hälfte von §8 ist nicht gemessen.** Wenn `mysql --batch` die
   Maskierung einer JSON-Zeile ein zweites Mal maskiert, trägt der Plan dort
   nicht, und Schritt 2 braucht eine andere Form (`--raw`, oder das Ergebnis
   Base64-kodiert durch die Leitung). Das ist das grösste Einzelrisiko dieser
   Stufe, und es steht als Schritt 0 vor dem Bauen.
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
| Agent | `Db\Console`, `Pg\Console`, acht Operationen |
| Anwendung | `Console`, Controller, Policy-Methode, Routen |
| Oberfläche | drei Ansichten unter `Pages/Databases/` |
| Wächter | sieben neue, acht Brüche |
| Migrationen | **keine** — die Konsole führt keinen Zustand |
| Positivliste | **keine Erweiterung** — `psql` und `mysql` stehen seit P5/P5b |
| Paketabhängigkeiten | **keine Erweiterung** (Entscheidung 1) |

Dass die letzten drei Zeilen leer sind, ist die eigentliche Aussage dieses
Plans: **P5c fügt dem System keinen neuen Weg mit Rechten hinzu.** Es benutzt
den, unter dem seit P5 fremde Dumps laufen.

Geschätzt 2–3 Wochen, im Zuschnitt von P5b.
