# 48 — Die Abnahme von P5c

**Der Lauf nach `docs/46 §15` auf `cloudsrv24`, gegen `v0.5.3-rc.13`.**
Begonnen am 13. August 2026. Dieses Dokument entsteht **während** des Laufs und
nicht danach — was hier steht, ist gemessen; was fehlt, ist nicht gefahren.

> **Ein Protokoll, das erst am Ende entsteht, verliert unterwegs die Zahlen.**

## 1. Der Aufbau

| | Kunde | Abonnement | MariaDB | PostgreSQL |
|---|---|---|---|---|
| **A** | 5 | 1130 | `p1130_p5c` (id 29) | `x1b311d2b6eedc3aa_p5c` (id 30) |
| | 5 | 1131 | `p1131_p5c` (id 31) | `xb5692b0b484effac_p5c` (id 32) |
| **B** | 6 | 1132 | `p1132_test1` (id 33) | `x32cd52aad3053e57_test2` (id 34) |

**B gehört einem anderen Kunden**, und das ist keine Feinheit — siehe §3.1.

### Die sieben Kriterien aus `docs/46 §4`

Abgeschlossen am 14. August 2026. **Alle sieben erfüllt**, jedes an einer
Messung und keines an einer Zusicherung:

| | Kriterium | Punkt | |
|---|---|---|---|
| 1 | der befristete Zugang | 5 | **erfüllt** |
| 2 | die vier Werte kommen an | 1 | **erfüllt** |
| 3 | keine fremde Tabelle | 3 (a)(b)(c) | **erfüllt** |
| 4 | das Zeitlimit | 4 | **erfüllt** |
| 5 | ohne Schlüssel wird nicht geändert | 6 | **erfüllt** |
| 6 | genau eine Zeile, und nur ihre Spalten | 7, 8b | **erfüllt** |
| 7 | das Protokoll | 8 | **erfüllt** |

Dazu gefahren, ohne eigenes Kriterium: Punkt 0 (derselbe Bestand auf beiden
Seiten), Punkt 2 und 2b (Blättern, Sortieren, Filtern, Tastatur) und Punkt 9
(der Rückbau lässt nichts liegen).

**Zwölf Befunde, und keinen davon ein Test** — **sieben über den Abnahmelauf
selbst**, vier über das Panel, einer über den Aufbau. Dasselbe Verhältnis wie
beim Fernzugriff (`docs/45`) und bei der Zwischenabnahme (`docs/47`): Die
Mehrheit der Fehler steckt nicht im Prüfling, sondern im Prüfmittel. **Drei der
vier über das Panel sind behoben** (§3.3, §3.4, §3.10), der vierte wartet auf
eine Entscheidung des Betreibers (§3.2).

## 2. Was gefahren ist

### Punkt 0 — derselbe Bestand auf beiden Seiten

Die Fixture nach `docs/46 §15` Punkt 0, beide Systeme. Der Vergleich der
Objektlisten und der vierzehn Zählwerte ist **zeichengleich**; `diff` sagt
nichts.

```
objekte bestellpositionen_archiv_2026_langer_name_zum_messen:r,blaettern:r,
        gross:r,lang:r,nur_unique:r,ohne_schluessel:r,probe:r,umsaetze_je_ort:v
archivtabelle 3 · blaettern 120 (1…120) · gross 3000000 · lang 1
nur_unique 2 · ohne_schluessel 2 · probe 1 · sicht 1
probe.leer laenge 0 · probe.nichts 1
probe.tab zeichen 9 · probe.umbruch zeichen 10
lang.langtext laenge 5000 · lang.leer 1
```

Die beiden letzten Zeilen der `probe` sind die wichtigsten: Zeichencode 9 und
10 belegen **im Bestand**, dass dort wirklich ein Tabulator und ein
Zeilenumbruch stehen. Alles Weitere in Punkt 1 ist damit eine Frage der
Anzeige und nicht des Transports.

`gross` ist im Lauf zweimal gewachsen — auf 20 und dann auf 60 Millionen
Zeilen (§2.5).

### Punkt 1 — die vier Werte · Kriterium 2 · **erfüllt**

Beide Systeme, Tabelle `probe`, eine Zeile:

| Spalte | MariaDB | PostgreSQL |
|---|---|---|
| `leer` | leere Zelle | leere Zelle |
| `nichts` | `NULL` | `NULL` |
| `tab` | `a b`, **eine** Zelle | ebenso |
| `umbruch` | `z1 z2`, **eine** Zelle | ebenso |

Der gefährliche Fehlschlag ist ausgeschlossen: Der Tabulator hat keine Spalte
erzeugt, der Umbruch keine Zeile — die Fehler, an denen `mysql --batch` (N1)
und `psql -A -t` (M7) gescheitert wären.

**Zwei Befunde aus diesem Punkt** stehen in §3.2 und §3.3.

### Punkt 2 — Blättern, Sortieren, Filtern · **erfüllt**

Tabelle `blaettern`, 120 Zeilen, beide Systeme **Zeile für Zeile gleich**:

| | beide Systeme |
|---|---|
| Seite 1 | `Zeilen 1–50 von mehr als 50` |
| Seite 3 | `Zeilen 101–120 von 120` |
| Sortierung ↓ | `120, 119, 118…` |
| Filter `enthält w01` | `Zeilen 1–21 von 21`, `120`…`100` |
| Operatoren | drei: `ist gleich`, `enthält`, `ist leer` |

**`von mehr als 50` ist der eigentliche Beleg.** Dort hätte eine Trefferzahl
gestanden, wenn jemand den `count(*)` eingebaut hätte, den `docs/46 §9`
ausgeschlossen hat. Auf der letzten Seite steht die genaue Zahl, weil sie ohne
Zusatzabfrage feststeht — und `Zeilen 1–21 von 21` beim Filter aus demselben
Grund.

Dass **beide Systeme dieselbe Reihenfolge** liefern, ist die Aussage dieses
Punktes: PostgreSQL sortiert über Ausgabespalten mit Alias, MariaDB über ein
`JSON_OBJECT` ganz ohne Alias je Spalte.

### Punkt 2b — der Baum mit der Tastatur · **erfüllt**

Fünf Anschläge, PostgreSQL, und nach jedem der fokussierte Knoten **und**
`aria-expanded`:

| Taste | Fokus danach | `aria-expanded` | was der Schritt prüft |
|---|---|---|---|
| `End` | `umsaetze_je_ort` | `false` | letzter **sichtbarer** Knoten |
| `ArrowRight` | `umsaetze_je_ort` | **`true`** | klappt auf, Fokus **bleibt** |
| `ArrowRight` | `umsaetze_je_ort/columns` | `—` | jetzt erst geht er hinein |
| `ArrowLeft` | `umsaetze_je_ort` | `true` | vom Blatt zum Zweig, bleibt offen |
| `ArrowLeft` | `umsaetze_je_ort` | **`false`** | klappt zu, Fokus **bleibt** |

Die beiden `ArrowRight`-Zeilen sind der Punkt: **dieselbe Taste bedeutet auf
einem zugeklappten Zweig etwas anderes als auf einem offenen**, und diese
Fallunterscheidung hatte bis hierher niemand gefahren. `TreeSemanticsTest` prüft
nur, dass ein `@keydown` am Baum *hängt*; was es tut, prüft kein Test dieses
Projekts. Das Bild bestätigt die letzte Zeile am Dreieck: `▸`.

**Gemessen wurde mit einem Mitschreiber am Baum**, weil weder ein Bild noch ein
Blick in die Konsole reicht: Zwei der fünf Schritte lassen den Fokus
ausdrücklich stehen, `aria-expanded` steht in keinem Pixel, und das Nachsehen in
den Entwicklerwerkzeugen nimmt den Fokus selbst weg. Der Mitschreiber hängt
sich an `keydown` und liest `document.activeElement` erst im nächsten
Makrotask — nach Vues Aktualisierung des DOM.

**Der `End`-Schritt ist dabei stärker ausgefallen als geplant.** Der Klick, der
den Fokus in den Baum setzt, klappt den angeklickten Zweig auch auf; beim `End`
waren also drei Blätter sichtbar, und „letzter **sichtbarer** Knoten" war eine
echte Frage statt „letzte Tabelle der Liste".

**Und der Mitschrieb stand doppelt da** — jede Zeile zweimal, paarweise
identisch. Das ist kein Befund über das Panel, sondern über das Messgerät:
`window.__spur.length` wuchs bei einem einzelnen Tastendruck der Gegenprobe um
**2**, der Schnipsel hing also zweimal am selben Baum. Zwei Tastendrücke wären
es nicht gewesen — ein zweites `ArrowRight` hätte den Fokus weiterbewegt und
eine *andere* Zeile erzeugt.

> **Ein Messgerät, das man zweimal einhängt, misst zweimal — und die doppelte
> Zahl sieht aus wie ein Befund.**

### Punkt 3 — keine fremde Tabelle · Kriterium 3 · **erfüllt**

Drei Wände, jede einzeln:

| Wand | Beleg |
|---|---|
| (a) Mandantenklammer | **404** auf `/databases/34/console`; positiver Fall im Agentenprotokoll (`db.console.tables`, `ok:true`); **0** Konsolenoperationen, die eine fremde Datenbank nennen |
| (b) `Names::belongsTo()` | `SrvPanel\Agent\AgentException  Diese Datenbank gehört nicht zu diesem Abonnement.` |
| (c) Rechte der Rolle | PostgreSQL: `FATAL: permission denied for database "xb5692b0b484effac_p5c" / DETAIL: User does not have CONNECT privilege.` — MariaDB: `ERROR 1044 (42000): Access denied for user 'probe_p5c'@'localhost' to database 'p1131_p5c'` |

**(c) ist die Wand, die uns nicht gehört**, und ohne sie wäre Kriterium 3 nicht
gefahren: Mit (a) und (b) allein wäre belegt, dass *unsere* Prüfung greift, und
das ist nicht die Frage.

Die Erwartung **404 statt 403** ist im Plan korrigiert worden — siehe §3.6.

### Punkt 4 — das Zeitlimit · Kriterium 4 · **erfüllt**

Tabelle `gross`, Sortierung nach `wert` (ohne Index), beide Systeme:

| | Grösse | Meldung |
|---|---|---|
| MariaDB | 20 Mio. | `Die Datenbank hat abgewiesen: ERROR 1969 (70100) at line 2: Query execution was interrupted (max_statement_time exceeded)` |
| PostgreSQL | 60 Mio. · 5,1 GB | `Die Datenbank hat abgewiesen: ERROR: canceling statement due to statement timeout` |

Beide Sätze kommen **wörtlich vom Server**, mit unserem Vorspann davor — der
Wortlaut, den `docs/36 §17` Kriterium 6 verlangt, und nicht „der Vorgang ist
fehlgeschlagen", was eine Aussage über uns wäre.

**Und die zweite Hälfte:** Nach beiden Abbrüchen rechnet nichts weiter.

```
SELECT count(*) FROM pg_stat_activity WHERE state='active' AND pid <> pg_backend_pid()  → 0
SHOW PROCESSLIST | grep -c "ORDER BY wert"                                              → 0
```

Die zwei verschiedenen Grössen sind selbst ein Befund — §3.7.

### Punkt 5 — der befristete Zugang · Kriterium 1 · **erfüllt**

Nach den Punkten 1 bis 4, ohne offene Konsole:

| | Ergebnis |
|---|---|
| `SELECT rolname FROM pg_roles WHERE rolname ~ '^x[0-9a-f]{16}_[rc][0-9a-f]{8}$'` | **leer** |
| `SELECT user FROM mysql.user WHERE user REGEXP '^p[0-9]+_[rc][0-9a-f]{8}$'` | **leer** |

**Und daneben der Nachweis, dass welche entstanden sind** — ohne ihn wäre die
Leere nur die Leere:

```
xb5692b0b484effac_c855b3a6a      ← das `c` ist Names::KIND_CONSOLE
x90d271df69287335_r04df71ac      ← `r` aus Zurückspielungen, vier weitere
mysql-fa4509bd2a3cc2d6.cnf       ← fünf verschiedene Zugangsdateien
```

Der Beleg sieht je System anders aus, und das folgt aus dem Aufrufweg:
PostgreSQL ruft `psql -U <rolle>`, der Name steht also in der Kommandozeile;
MariaDB ruft `mysql --defaults-extra-file=…`, dort steht die **Datei**.

**Zugabe über den Plan hinaus:** `/run/srvpanel/mysql-*.cnf` — keine Datei.
Eine liegengebliebene Zugangsdatei enthält ein Passwort und wäre ein Rest, den
Kriterium 1 dem Sinne nach genauso ausschliesst wie eine Rolle. Sie steht nicht
im Plan, weil beim Schreiben niemand an sie gedacht hat.

### Punkt 6 — ohne Schlüssel · Kriterium 5 · **erfüllt**

Drei Tabellen, beide Systeme, alle sechs Fälle richtig:

| Tabelle | Beizeile | Ändern | Grund |
|---|---|---|---|
| `ohne_schluessel` | `Tabelle … · ohne Schlüssel` | **fehlt** | „…keinen **Primärschlüssel** und keinen eindeutigen Index über Spalten ohne NULL…" |
| `nur_unique` | `Tabelle … · **mit Schlüssel**` | **vorhanden** | — |
| `umsaetze_je_ort` | `**Sicht** … · ohne Schlüssel`, **keine Grösse** | **fehlt** | „Eine **Sicht** speichert nichts. Geändert wird in den Tabellen, aus denen sie liest." |

Drei Dinge daran, die einzeln durchgerutscht wären:

**Die beiden Gründe sind verschieden.** Bei der Tabelle steht der Schlüssel, bei
der Sicht steht, dass sie nichts speichert — „leg einen Schlüssel an" wäre dort
der falsche Rat. `RowKeyTest::test_the_interface_says_why_a_table_is_read_only`
prüft genau diese zwei Sätze; hier stehen sie am lebenden Objekt.

**Die Sicht bekommt keine Grösse** und heisst „Sicht" statt „Tabelle" — §20.28
im Betrieb. Der Katalog meldet für eine Sicht `0`, und „0 B" hätte sich wie
„leer" gelesen statt wie „gibt es nicht".

**Und `nur_unique` ist auf beiden Seiten änderbar**, obwohl der Schlüssel dort
auf ganz verschiedenen Wegen entsteht: MariaDB befördert den eindeutigen Index
selbst zum impliziten Primärschlüssel (`COLUMN_KEY = 'PRI'`, der Index heisst
dabei weiterhin nicht `PRIMARY`), PostgreSQL braucht das Prädikat aus
`KEY_INDEX`. Zwei Mechanismen, ein Ergebnis — §10 Regel 2 im Betrieb, und die
Stelle, an der §20.46 einmal einen Widerspruch gefunden hat.

Auch **„Zeile anlegen" fehlt** in beiden nur lesbaren Fällen, nicht nur
„Ändern" — folgerichtig: Eine Zeile, die man danach nicht eindeutig ansprechen
kann, sollte gar nicht erst entstehen.

### Punkt 7 — genau eine Zeile · Kriterium 6 · **erfüllt**

**(A) Eine Zeile ändern, und nur die.** Im Panel `blaettern`, `id = 51`,
`wert` auf `geaendert`. Gemessen wird an einer Zahl, die alles sagt:

```
SELECT count(*) FROM blaettern WHERE wert <> 'w' || lpad(id::text, 4, '0')
  vorher: 0 · 0      nachher: 1 · 1        (PostgreSQL · MariaDB)
SELECT id, wert FROM blaettern WHERE wert = 'geaendert'   →  51|geaendert
```

Genau eine Zeile weicht vom Muster ab, und es ist die richtige. Hätte der
Vorgang zwei getroffen, stünde `2` da.

**Der Plan sagt hier „in `probe` eine Zeile ändern … die anderen nicht" — und
`probe` hat nur eine Zeile.** Das ist eine Lücke zwischen Punkt 0 und Punkt 7,
entstanden beim Festlegen der Fixture. Gefahren wurde `blaettern`.

**(B) Der Gegenfall.** Beide Systeme, mit offenem Formular die Zeile von aussen
gelöscht, dann gespeichert:

> Der Vorgang hat 0 Zeilen getroffen und nicht genau eine; nichts wurde geändert.

**Der Gegenfall aus dem Plan ist nicht fahrbar**, und das ist ein Befund über
den Lauf — §3.9.

### Punkt 8 — das Protokoll · Kriterium 7 · **erfüllt**

`audit_events` liegt in der Datenbank des Panels und nicht in der des Kunden;
dieser Punkt läuft **einmal** und nicht je System.

**(a) Was drinsteht — und was nicht.**

| Aktion | Anzahl |
|---|---|
| `database.console.opened` | 7 |
| `database.console.row.changed` | 4 |
| eine lesende Handlung | **es gibt keine** |

Vier ändernde Handlungen für vier Änderungen (Punkt 7 (A) auf beiden Systemen,
dazu die beiden Vorläufe). Blättern, Sortieren, Filtern und das Ansehen einer
Zelle — die ganze Punkt-2-Fläche — hinterlassen **keine Zeile**. Das ist die
Entscheidung 5 aus `docs/46 §3` im Betrieb: protokolliert wird, wer *geändert*
hat, und einmal je Stunde, wer *gesehen* hat.

**(b) Und mit welcher Genauigkeit.** Der Kontext einer Änderung:

```
context    {"table":"blaettern","key":{"id":"51"}}
target_id  29 (MariaDB) · 30 (PostgreSQL)
```

Datenbank, Tabelle und Schlüssel — die drei Angaben, die der Punkt verlangt.

**(c) Die Gegenprobe, und sie ist der eigentliche Punkt.** Gesucht wird nach dem
**Wert**, den der Kunde eingetragen hat:

```
SELECT count(*) FROM audit_events WHERE context LIKE '%geaendert%'   →  0
```

Der neue Wert steht nirgends im Protokoll. Ein Protokoll, das Werte mitschreibt,
wäre eine zweite Kopie der Kundendaten an einer Stelle, die niemand als solche
liest — und sie überlebt das Löschen der Zeile.

**(d) Die Entprellung.** Sie ist die Hälfte, die still bricht: Ohne sie stünde
bei jedem Betreten der Konsole eine Zeile im Protokoll, und die Konsole wird
beim Arbeiten dutzendfach betreten.

```
letzter Eintrag zu Ziel 29   2026-08-14 06:42:08   (UTC)
Beginn                       2026-08-14 07:26:53   (UTC_TIMESTAMP())
        → 20× die Konsole von p1130_p5c öffnen und verlassen
agent.log, "op":"db.console.tables"    153 → 193   (Differenz 40)
audit_events, created_at > Beginn                    0
```

Erwartet war **höchstens 1**; gemessen ist **0**, weil der vorige Eintrag noch
44 Minuten 45 Sekunden alt war und damit innerhalb des Fensters von einer Stunde
lag (`DatabaseController::CONSOLE_AUDIT_SECONDS = 3600`). Zwanzig Öffnungen,
kein einziger neuer Eintrag.

**Die 40 daneben sind der Grund, dass die 0 überhaupt etwas heisst.** Der Agent
schreibt je Anfrage **zwei** Zeilen mit demselben Operationsnamen — `request`
beim Annehmen, `result` beim Beantworten (`agent/src/Connection.php:53` und
`:68`). 20 Öffnungen → 20 Anfragen → 40 Zeilen, und
`resources/js/Pages/Databases/Console.vue:1000` hat mit `onMounted(loadTables)`
genau einen Aufrufer. Die Rechnung geht ohne Rest auf: Die zwanzig Aufrufe sind
belegt, und *trotzdem* steht auf der Protokollseite eine Null.

Ohne diese zweite Zahl wäre die 0 wertlos gewesen — eine Entprellung, die nie
gefragt wurde, liefert dieselbe Null wie eine, die zwanzigmal abgewiesen hat.
Wie es dreimal danebenging, steht in §3.11.

### Punkt 8b — die unberührte Spalte · Kriterium 6, zweite Hälfte · **erfüllt**

Der Punkt ist der einzige des Laufs, dessen Fehlschlag **an der Zeile nicht zu
sehen** wäre: Sie stünde da, sie sähe richtig aus, und 4488 Zeichen wären fort.

Im Panel `lang`, `id = 1`, **nur `notiz`** von `vorher` auf `nachher` — die lange
Textspalte und die NULL-Spalte nicht angefasst:

| | MariaDB | PostgreSQL |
|---|---|---|
| `length(langtext)` | **5000** | **5000** |
| `leer IS NULL` | `1` | `t` |
| `notiz` | `nachher` | `nachher` |

**Die Messung trägt ihren eigenen Zeugen.** `nachher` kann nur aus dem Formular
stammen — von Hand gesetzt wurde `vorher`. Die 5000 stehen also neben einem
Beleg, dass gespeichert wurde, und nicht neben der Möglichkeit, dass gar nichts
passiert ist. Nach §3.11 zweimal in Folge die Form, die hier gefehlt hat.

Ausgeschlossen sind damit die beiden Fehlschläge, die an der **Anzeige** hängen:
der bei 512 Zeichen gekürzte Wert, den das Formular zurückschickt (dann stünde
`512` da), und der leere String für eine leere Anzeige (dann stünde `leer IS
NULL` auf falsch — aus „nichts" wäre „nichts drin" geworden, in SQL nicht
dasselbe).

**Die Fixture musste dafür wachsen**, und das ist ein Befund über den Lauf —
§3.12.

**Die Gegenprobe im Panel**, beide Systeme: In der Zeilentabelle steht der bei
512 Zeichen abgeschnittene Wert mit dem Weg zum Rest, in der Zelleinzelsicht der
ganze:

```
Spalte langtext · 5 KB          (beide Systeme, und ohne „gekürzt")
```

**Das fehlende Wort ist der Beleg und nicht die Länge.** „gekürzt" steht in
dieser Kopfzeile genau dann, wenn auch die Einzelsicht abschneiden musste
(`Console::CELL_FULL_LIMIT` im Agenten); die Grösse misst der Agent in der
Datenbank und nicht an dem, was er ausliefert. Das Panel sagt hier also selbst,
dass der Wert vollständig ist — niemand muss Zeichen zählen. Ohne diese Ansicht
wäre eine gekürzte Zelle eine Sackgasse.

Zwei Nebenbeobachtungen aus denselben zwei Bildern:

**Dieselbe Tabelle heisst auf beiden Seiten verschieden gross** — `32 KB`
(PostgreSQL) gegen `16 KB` (MariaDB) —, und die Zeilenzahl steht nur auf einer
Seite: PostgreSQL sagt `Zeilenzahl unbekannt`, MariaDB `geschätzt 1 Zeilen`.
Beides ist richtig. `reltuples` steht bei einer nie analysierten Tabelle auf
`-1`, und das Panel schreibt dafür **„unbekannt" und nicht „0"** — die Regel aus
`Console.vue:657` im Betrieb, an genau der Stelle, an der eine `0` sich wie
„leer" gelesen hätte.

Und `geschätzt 1 Zeilen` ist §3.3 zum zweiten Mal, jetzt an einer anderen
Tabelle.

### Punkt 9 — der Rückbau lässt nichts liegen · **erfüllt**

**(a) Vor dem Rückbau**, nach allen Konsolensitzungen des Laufs — sieben
geöffnete Konsolen über zwölf Stunden, dazu die zwanzig aus Punkt 8 (d):

```
mariadb 10.11.14 — nutzbar · Horcht auf 127.0.0.1 — Fernzugriff aus.
postgresql 16.14 — nutzbar · horcht auf localhost — Fernzugriff aus.
Im Bestand des Panels:
  MariaDB      3 Datenbank(en), 3 Zugang/Zugänge, 0 Sicherung(en)
  PostgreSQL   3 Datenbank(en), 3 Zugang/Zugänge, 0 Sicherung(en)
Keine Zeile ohne Abonnement.
```

**Keine Zeile über befristete Zugänge, auf keinem der beiden Systeme.** Die
Konsole hat nichts angelegt, was sie überlebt.

**Der Blick gehört vor den Rückbau**, und das war die Korrektur am Plan: Der
Rückbau entfernt alles mit dem Präfix des Abonnements, also auch einen Zugang aus
einer abgebrochenen Konsolensitzung. Wer erst danach nachsieht, misst den
Rückbau.

**(b) Die Gegenprobe**, denn die Entwarnung besteht hier aus **Schweigen**:
`reportStale()` gibt gar nichts aus, wenn nichts da ist — das ist noch weniger
als eine Null. Ein Rest von Hand gelegt, auf beiden Systemen:

```
1 befristete(r) Zugang/Zugänge blieben in MariaDB stehen:
  p1130_c00000000@localhost
1 befristete(r) Zugang/Zugänge blieben in PostgreSQL stehen:
  x1b311d2b6eedc3aa_c00000000
Sie gehen mit `srvpanel db --prune`.
```

Beide beim Namen, mit dem Weg zum Aufräumen. Nach `DROP USER` und `DROP ROLE`
kehrt das Schweigen zurück — zweimal nachgesehen.

**Die beiden Reste entstehen auf verschiedenen Wegen**, und das gehört zum
Beleg: Der MariaDB-Zugang brauchte einen zurückgesetzten
`password_last_changed`, sonst gilt er als frisch und wird zu Recht
verschwiegen; die PostgreSQL-Rolle nicht, weil die Messung dort ohne offene
Verbindung sofort auf die Karenz zurückfällt. Zwei Mechanismen, ein Ergebnis.

> **Eine Entwarnung, die aus Schweigen besteht, ist von einem kaputten Messgerät
> nicht zu unterscheiden.**

**(c) Der Rückbau selbst.** Zurückgebaut wurde **Abo 1131** und nicht 1130 — für
die Frage dieses Punktes ist das gleichgültig, und der Bestand aus Punkt 0 bleibt
damit für die Nacharbeit an den offenen Befunden stehen. `gross` allein trägt
60 Millionen Zeilen.

Gemessen wird **auf dem Server** und erst danach am Panel:

| | vorher | nachher |
|---|---|---|
| MariaDB Datenbanken | `p1131_p5c` | — |
| MariaDB Zugänge | `p1131_p5c@localhost` | — |
| PostgreSQL Datenbanken | `xb5692b0b484effac_p5c` | — |
| PostgreSQL Rollen | `xb5692b0b484effac_owner`, `xb5692b0b484effac_p5c` | — |

Danach das Panel: **`2 Datenbank(en), 2 Zugang/Zugänge`** je System (von drei),
`Keine Zeile ohne Abonnement.`, keine befristeten Zugänge.

**Die Reihenfolge ist der Punkt, nicht die Gründlichkeit.** `srvpanel db` sagt
„Im Bestand **des Panels**" — das sind seine eigenen Tabellen. Ein Rückbau, der
die Zeilen des Panels löscht und die Datenbank auf dem Server stehen lässt,
erzeugt **dieselbe Ausgabe** wie ein sauberer, und die Prüfung der befristeten
Zugänge fängt das nicht: Sie sieht nur auf `[rc]`-Kennungen, und `p1131_p5c` ist
keine.

> **Eine Buchhaltung, die sich selbst befragt, bestätigt sich selbst.**

Genau dieser Fehler steht in `docs/35 §11`: Zertifikate liessen sich in diesem
System anlegen, aber nirgends löschen, und jedes zurückgebaute Abonnement liess
seinen privaten Schlüssel liegen. Gemerkt hat es Jahre niemand, weil nichts
danach *fragte*.

**PostgreSQL hatte zwei Rollen und MariaDB einen Benutzer** — die
Eigentümerrolle aus P5b kommt dazu. Beide sind fort; ein Rückbau, der nur die
Zugangsrolle kennt, hätte hier eine Zeile stehengelassen.

## 3. Die Befunde

### 3.1 Zwei Abonnements sind nicht zwei Mandanten — **Aufbau**

Die Voraussetzung von `docs/46 §15` verlangte „ein zweites Abonnement B". Für
Punkt 3 (a) reicht das nicht: Die Klammer hängt am Abonnement, die Anmeldung am
Konto, und `Tenancy::forAccount()` gibt einem Kunden **alle** Abonnements seines
Kundenkontos. Der Aufruf auf B wäre gelungen, und zwar zu Recht.

**Gefunden hat es der Betreiber beim Aufbau**, bevor der Punkt lief. Der Plan
verlangt jetzt einen zweiten **Kunden**.

### 3.2 Tabulator, Umbruch und Leerzeichen sehen gleich aus — **offen**

`a\tb` erscheint als `a b`, `z1\nz2` als `z1 z2`, und ein Wert mit einem
gewöhnlichen Leerzeichen erschiene genauso. HTML fasst Weissraum zusammen, und
`.rows .cell` trifft dazu keine Entscheidung.

Nach dem Wortlaut von Kriterium 2 ist das erfüllt — der Umbruch bleibt
*innerhalb* der Zelle. Wer die Zelle **liest**, kann drei verschiedene
gespeicherte Werte trotzdem nicht auseinanderhalten.

Vorschlag: `white-space: pre-wrap` an `.rows .cell`. Es kollidiert nicht mit
`docs/46 §20.13` — `pre-wrap` bricht weiterhin am Rand —, macht aber Zeilen mit
Umbrüchen höher. Entscheidung des Betreibers nach dem Lauf.

### 3.3 „geschätzt 1 Zeilen" — **behoben**

Einzahl mit Mehrzahlwort, fest angehängt in `openFacts`. Die Einzahl ist im
Betrieb der Normalfall und in der Entwicklung der Sonderfall: Beim Schreiben war
eine Tabelle mit 16384 Zeilen offen.

> **Ein Plural, der immer stimmt, stimmt nur, solange niemand eine Zeile
> anlegt.**

**Der Wächter dazu hat beim ersten Lauf drei Fundstellen gemeldet und nicht
eine** — ausser der Konsole noch das Protokoll („1 Einträge",
`Audit/Index.vue`) und die Planvorlage („1 Abonnements gebunden",
`Plans/Form.vue`). Beide stehen seit P2 im Repo, beide hat niemand bemerkt.

> **Ein Fehler, der an drei Stellen unabhängig gemacht wurde, ist keine
> Unachtsamkeit, sondern eine fehlende Stelle.**

Die Entscheidung steht deshalb jetzt in `resources/js/Composables/useCounted.ts`
und nicht in der Seite, die sie gerade braucht. **Beide Wörter werden übergeben
und keines abgeleitet** — im Deutschen gibt es dafür keine Regel: `Zeile` wird zu
`Zeilen`, `Zugang` zu `Zugänge`, `Treffer` bleibt.

Wächter: `CountedNounTest` — keine eingesetzte Zahl klebt an einem Mehrzahlwort,
das Muster findet nachweislich eines, und mindestens drei Vorlagen holen die
Entscheidung von der einen Stelle.

### 3.4 Die Kopfzeile behauptet eine Sortierung, die die Zeilen nicht haben — **offen**

`sortBy()` setzt `order` **vor** dem Laden, `loadPage()` überschreibt `page`
**nur bei Erfolg**. Nach einer Zeitüberschreitung steht die Kopfzeile also auf
`wert ↑`, während die Tabelle die alte, nach `id` sortierte Seite zeigt.

Auf beiden Systemen gesehen, jeweils zusammen mit der Meldung aus Punkt 4.

> **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen, als wäre
> sie durchgelaufen.**

**Kein Test dieses Projekts kann das sehen** — es entsteht erst aus dem
Zusammenspiel von Zeitüberschreitung und Anzeige.

**Behoben.** Es gibt jetzt eine Stelle, die den Zustand der Sicht sichert, den
Griff ausführt und bei Fehlschlag zurücknimmt (`change()` in `Console.vue`);
`loadPage()` meldet dafür, ob sie durchkam. Das betraf **fünf** Griffe und nicht
nur die Sortierung: Filtern, Filter entfernen, Vor und Zurück hingen an
derselben Ursache. `back()` war dabei der stillste — er nahm den Versatz vom
Stapel, und bei einem Fehlschlag war er fort.

Wächter: `ViewStateTest` — die Ladung meldet ihr Ergebnis, was gesichert wird
wird zurückgenommen, und kein Griff fasst etwas an, das nicht gesichert ist. Die
dritte Prüfung ist die wichtigere: Der Fehler, der wiederkommt, ist nicht „jemand
baut den Rückweg aus", sondern „jemand fügt eine sechste Angabe hinzu".

### 3.5 Der Beleg von Punkt 3 (a) hat dreimal das Messgerät gewechselt — **Lauf**

| Anlauf | gemessen an | warum es nicht ging |
|---|---|---|
| 1 | `journalctl -u srvpanel-agentd` | dort steht **nie** etwas — der Agent schreibt nach `/var/log/srvpanel/agent.log` |
| 2 | Zeilenzahl von `agent.log` | dort steht **immer** etwas — `system.info` läuft im Zehnsekundentakt (181.260 Einträge) |
| 3 | Name der fremden Datenbank | der steht auch vom **Anlegen** dort, und das ist in Ordnung |

Gemessen wird jetzt am Namen **innerhalb einer Konsolenoperation**.

> **Ein Name allein sagt nicht, wer ihn genannt hat.**

### 3.6 Die erwartete 403 war eine Vermutung — **Lauf**

`Database` trägt `BelongsToSubscription`; der globale Filter greift vor der
Policy, die Adressauflösung findet den Datensatz nicht, und Laravel antwortet
mit **404**. Die 404 ist die schärfere Antwort: Eine 403 bestätigt die Existenz
der fremden Datenbank.

### 3.7 „Einige Millionen Zeilen" ist keine portable Anweisung — **Lauf**

Dieselbe Tabelle mit 20 Millionen Zeilen reisst in MariaDB die Grenze und wird
in PostgreSQL durchsortiert; bei 3 Millionen waren es 0,134 s gegen 1,141 s.
PostgreSQL brauchte 60 Millionen. Die Grösse gehört je System gemessen.

### 3.8 Zwei Werkzeuge, die schweigend aufhören — **Lauf**

`srvpanel tinker --execute=…` gibt **nichts** aus — kein Ergebnis, kein Fehler:
Der Wrapper setzt per `setpriv` um, `HOME` bleibt auf `/root`, und psysh
scheitert beim Anlegen von `.config/psysh` mit einer blossen `User Notice`.
`HOME=/tmp` davor löst es.

> **Ein Werkzeug, das mit einer Warnung aufhört, sieht aus wie eins, das nichts
> zu sagen hatte.**

Dazu eine leere Shell-Variable (`$PGDB`), die `psql` in die Vorgabedatenbank
schickte: Die Antwort war eine ehrliche Fehlermeldung über eine Tabelle, die es
dort nicht gibt — und damit eine Messung an etwas anderem.

### 3.9 Der Gegenfall aus Punkt 7 ist nicht fahrbar — **Lauf**

Der Plan wollte den Schlüssel **mehrdeutig** machen: „von aussen den
Schlüsselwert auf einen zweiten Datensatz duplizieren (in einer Tabelle mit
eindeutigem Index über eine Spalte, die danach ihre Eindeutigkeit verliert)".

Das geht nur, indem man den eindeutigen Index **entfernt** — und dann hat die
Tabelle gar keinen Schlüssel mehr. `checkedKey()` weist ab, bevor eine Anweisung
entsteht, mit „Diese Spalte gehört nicht zum Primärschlüssel". Gemessen am
13. August 2026 auf PostgreSQL; MariaDB benutzt dieselbe geteilte Prüfung.

> **Ein Gegenfall, der eine Prüfung erreichen soll, muss an den davor
> vorbeikommen.**

Solange der eindeutige Index steht, kann der Schlüssel nie zwei Zeilen treffen;
nimmt man ihn weg, greift die frühere Wand. **Die Zählung nach dem `UPDATE` ist
über diesen Weg überhaupt nicht erreichbar** — dasselbe Muster wie in Punkt 3,
wo eine Wand nur durch Abschalten der davor erreichbar schien.

Gefahren wurde statt dessen der Fall, der im Betrieb wirklich vorkommt: **die
Zeile verschwindet, während jemand sie offen hat.** Dann trifft der `WHERE`-Teil
null Zeilen, und genau dafür stehen `GET DIAGNOSTICS` und `ROW_COUNT()` im Code.

**Und noch ein Nebenbefund daraus:** Die Meldung „Diese Spalte gehört nicht zum
Primärschlüssel" ist für diese Lage irreführend — es gibt in dem Moment gar
keinen Primärschlüssel mehr. Sie nennt das Symptom, nicht die Ursache („der
Schlüssel dieser Tabelle hat sich geändert, die Ansicht ist veraltet"). Sehr
selten, aber notiert.

### 3.10 Eine gescheiterte Handlung lässt die vorige Erfolgsmeldung stehen — **behoben**

Auf dem MariaDB-Bild zu Punkt 7 (B) steht über der roten Meldung noch **„Die
Zeile ist geändert."** in Grün — von der Handlung davor. Der Kunde drückt
Speichern und liest gleichzeitig „geändert" und „nichts wurde geändert".

`useAnnounce` räumt die Erfolgsmeldung bei einer **Navigation** ab, und die
Konsole navigiert nie — sie arbeitet ausschliesslich über XHR. `report()` setzt
nur `failure` und rührt die grüne Meldung nicht an.

> **Eine gescheiterte Handlung muss die Erfolgsmeldung der vorigen wegnehmen —
> sonst stehen zwei Sätze über derselben Taste, und einer ist falsch.**

Dieselbe Familie wie §3.4: Zustand einer vorigen, erfolgreichen Handlung
überlebt eine gescheiterte.

**Behoben.** `useAnnounce` hat einen Rückweg bekommen (`dismiss()`), und
`report()` geht ihn, bevor der Fehlersatz steht. Auf jeder anderen Seite dieses
Panels kann das nicht passieren — dort ist die Erfolgsmeldung ein `flash` und
lebt eine Antwort lang. Die Konsole ist die erste Fläche, die ändert und dabei
stehen bleibt.

Wächter: `AnnounceWithdrawalTest` — den Rückweg gibt es, er nimmt wirklich
zurück, und jede Stelle, die einen Fehlersatz setzt, geht ihn. **Der Wächter hat
zuerst sich selbst gefunden:** Sein Ausdruck `failure\.value\s*=\s*(?!null)`
meldete auch das Aufräumen `failure.value = null` — schlägt die Vorschau fehl,
gibt `\s*` ein Zeichen zurück und probiert es erneut.

> **Ein `\s*` vor einer Verneinung hebt sie auf.**

### 3.11 Punkt 8 (d) hat dreimal nicht gemessen — **Lauf**

Dieselbe Sorte Fehlschlag wie §3.5, und wieder an einer Null:

| Anlauf | was gefahren wurde | warum es nichts gemessen hat |
|---|---|---|
| 1 | `created_at > '<die Zeit von eben>'` | der Platzhalter blieb wörtlich stehen — **MariaDB hat ihn stillschweigend umgewandelt** und `7` geliefert |
| 2 | `BEGINN=$(… NOW() …)` | `NOW()` ist die Ortszeit des Servers (+2), `created_at` steht in UTC — die Grenze lag zwei Stunden in der Zukunft |
| 3 | `UTC_TIMESTAMP()`, aber ohne Beleg der zwanzig Aufrufe | `0` — und nicht zu unterscheiden von „es ist gar nichts passiert" |

Der erste ist der gefährlichste, weil er **keinen Fehler** wirft: Eine Grenze,
die keine ist, liefert alle sieben Zeilen, und sieben sieht nach einer Messung
aus.

> **Ein Platzhalter, den die Datenbank stillschweigend annimmt, liefert eine
> Zahl statt eines Fehlers.**

Der zweite ist die Kehrseite von `docs/40`: Die Anzeigezeitzone geht über
`Clock`, das Protokoll steht in UTC — und `mysql` auf demselben Server rechnet
in Ortszeit. Zwei Uhren, ein Vergleich.

> **Eine Zeitgrenze aus einer anderen Uhr als die Spalte ist kein Filter,
> sondern ein Zufall.**

Der dritte ist §3.5 zum zweiten Mal, an einer anderen Stelle desselben Laufs.
Aufgelöst hat ihn ein Zähler auf einem **anderen** Kanal — die
Operationszeilen des Agenten —, der sich nicht entprellen lässt.

### 3.12 Punkt 8b hatte keine Spalte zum Ändern — **Lauf**

Der Schritt verlangt, **nur eine dritte Spalte** zu ändern und die lange
Textspalte wie die NULL-Spalte unberührt zu lassen. `lang` hat drei Spalten:
`id` ist der Schlüssel, `langtext` und `leer` sind genau die beiden, die
unberührt bleiben müssen. Es blieb nichts übrig.

Dieselbe Lücke wie bei Punkt 7, wo `probe` nur eine Zeile hatte — und aus
demselben Grund: Die Fixture steht in Punkt 0, der Schritt in Punkt 8b, und
zwischen beiden hat niemand nachgezählt.

> **Eine Fixture und der Schritt, der sie benutzt, entstehen an zwei Stellen —
> und nur einer von beiden zählt die Spalten.**

Aufgestockt wurde auf **beiden** Systemen um `notiz`, wie schon `gross` in
Punkt 4 auf beiden Seiten gewachsen ist: Eine Seite allein zu ändern nimmt dem
Lauf seine Gegenprobe.

## 4. Was noch offen ist

**Der Lauf selbst ist durch, und drei der vier Befunde am Panel sind behoben** —
§3.3, §3.4 und §3.10, jeder mit einem Wächter, der ohne den Fix zubeisst. Die
acht Eingriffe dazu stehen in `tests/waechter-brechen.sh` und sind einzeln
gefahren worden.

Offen ist **§3.2**: Tabulator, Umbruch und ein gewöhnliches Leerzeichen sehen in
der Zelle gleich aus. Nach dem Wortlaut von Kriterium 2 ist das erfüllt; für den,
der die Zelle liest, sind drei verschiedene gespeicherte Werte trotzdem nicht zu
unterscheiden. Der Vorschlag ist `white-space: pre-wrap` an `.rows .cell` — er
kollidiert nicht mit §20.13, macht aber Zeilen mit Umbrüchen höher, **und das ist
eine Entscheidung des Betreibers und keine des Bauenden.**

Behoben wurde erst **nach** dem Lauf und nicht während — Weg 3, entschieden vom
Betreiber: Ein Update mitten im Lauf machte die späteren Punkte gegen eine andere
Fassung messbar als die frühen, und genau das hat schon in `docs/47` Verwirrung
gekostet.

**Der Bestand aus Punkt 0 steht dafür noch**, auf `p1130_p5c` und
`x1b311d2b6eedc3aa_p5c`: Zurückgebaut wurde für Punkt 9 das zweite Abonnement
desselben Kunden. Wer die Fixes nachprüft, braucht Tabellen mit genau diesen
Eigenschaften — `probe` für §3.2, `lang` und `blaettern` für den Rest — und muss
sie nicht erst wieder einfüllen. `gross` allein trägt 60 Millionen Zeilen.

**Und zwei Punkte aus `docs/42 §5` sind auch dieser Lauf nicht angegangen** —
der `template1`-Beleg und die Frage, ob ein Zugang ohne jede Datenbank entstehen
kann. Sie standen nie in `docs/46 §15`, und sie sind hier nicht heimlich
miterledigt worden.
