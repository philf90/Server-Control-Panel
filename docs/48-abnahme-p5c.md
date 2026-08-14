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

### 3.3 „geschätzt 1 Zeilen" — **offen**

Einzahl mit Mehrzahlwort, hartcodiert in `openFacts`.

### 3.4 Die Kopfzeile behauptet eine Sortierung, die die Zeilen nicht haben — **offen**

`sortBy()` setzt `order` **vor** dem Laden, `loadPage()` überschreibt `page`
**nur bei Erfolg**. Nach einer Zeitüberschreitung steht die Kopfzeile also auf
`wert ↑`, während die Tabelle die alte, nach `id` sortierte Seite zeigt.

Auf beiden Systemen gesehen, jeweils zusammen mit der Meldung aus Punkt 4.

> **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen, als wäre
> sie durchgelaufen.**

**Kein Test dieses Projekts kann das sehen** — es entsteht erst aus dem
Zusammenspiel von Zeitüberschreitung und Anzeige.

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

## 4. Was noch offen ist

- **Punkt 5** — der befristete Zugang (Kriterium 1)
- **Punkt 6** — ohne Schlüssel (Kriterium 5)
- **Punkt 7** — genau eine Zeile (Kriterium 6)
- **Punkt 8 / 8b** — das Protokoll (Kriterium 7) und die unberührte Spalte
- **Punkt 9** — der Rückbau lässt nichts liegen
- **Punkt 2b** — der Baum mit der Tastatur

Und die drei offenen Befunde §3.2, §3.3 und §3.4 werden nach `docs/46 §15`
**gesammelt und am Ende behoben** — Weg 3, entschieden vom Betreiber: Ein Update
mitten im Lauf machte die späteren Punkte gegen eine andere Fassung messbar als
die frühen, und genau das hat schon in `docs/47` Verwirrung gekostet.
