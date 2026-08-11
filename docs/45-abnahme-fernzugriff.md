# 45 — Die Abnahme des Fernzugriffs, gefahren

**Gefahren am 11. August 2026 auf `cloudsrv24`** gegen `v0.5.2-rc.3` bis `rc.5`,
nach der Befehlsfolge aus `docs/43`. Alle sechzehn Punkte sind erfüllt. Der Lauf
hat dabei **zwölf Fehler gefunden, und keinen davon ein Test** — zwei davon
haben ihn unterbrochen, bis sie behoben und ausgeliefert waren, und fünf stecken
im Abnahmelauf selbst.

Das Verhältnis ist das bekannte: `docs/42` hielt für P5b sechs Fehler fest, von
denen vier ein echter Server fand. Hier sind es zwölf, und der teuerste hat das
Panel abgeschaltet.

---

## 1. Die Kurzfassung

| | |
|---|---|
| Server | `cloudsrv24`, Ubuntu 24.04, PostgreSQL 16.14, MariaDB 10.11.14 |
| Fassungen | `rc.3` (Punkte 0–2b), `rc.4` (3–5), `rc.5` (6–9b) |
| Zweiter Rechner | `91.99.177.177` |
| Abonnement | `x4019d319cc321a7a`, Datenbank `…_laden`, Rolle `…_lesen` |
| Dauer | etwa fünf Stunden, davon gut vier für die Fehler |

**Der Kern des Laufs steht:** Die Datei war kaputt, das Panel lief hinein, der
Rückweg legte den vorgefundenen Stand bytegenau zurück, die Meldung nannte Zeile
und Grund, und der Cluster startete danach.

**Und der Lauf hinterlässt nichts.** Nach Punkt 9 sind beide Prüfsummen
bytegleich die von Punkt 1:

```
bd603609e2aec42cfc7d7c7eaa30d346  postgresql.conf
2ebf18b2fe61e73a8b35b22c2a76551a  pg_hba.conf
```

---

## 2. Die Punkte mit ihren gemessenen Werten

| Punkt | gemessen |
|---|---|
| **0** | `x4019d319cc321a7a_laden` / `…_lesen` |
| **1** | `listen_addresses = localhost`, `conf.d` leer, beide `md5` festgehalten |
| **2** | kein Knopf „Netz eintragen", darunter der Grund und der Weg |
| **2b** | Horcht auf `localhost`, Fernzugriff **aus**, Erlaubte Netze **0** |
| **3** | `Horcht auf * — Fernzugriff möglich.` · `PostgreSQL horcht auf * …, 0 Zugangsregel(n)` · `SHOW listen_addresses` → `*` · `md5 postgresql.conf` unverändert · `IPv4 offen` · Panel `200` |
| **4** | `138 \| {…_laden} \| {…_lesen} \| 91.99.177.177 \| 255.255.255.255 \| scram-sha-256` — **die Datenbank steht drin, nicht `all`**; `error IS NOT NULL` → 0 |
| **4b** | zwei Zeilen, je eine Datenbank, dasselbe Netz; nach „Zugriff entziehen" wieder eine |
| **4c** | nur noch die erste Datenbank; `srvpanel db` → „alle im Bestand", „Nichts liegengeblieben." |
| **4d** | Horcht auf `*`, Fernzugriff **an**, Netze **1**, Tabelle nennt `91.99.177.177/32` mit einem Zugang, Satz über Einträge statt Zugänge steht darunter |
| **5** | erlaubt: `1 (1 row)` · verwehrt, wörtlich: `FATAL: no pg_hba.conf entry for host "91.99.177.177", user "x4019d319cc321a7a_lesen", database "postgres"` — **zweimal**, mit und ohne Verschlüsselung |
| **6** | beide Netze abgewiesen **mit Begründung**; Rückweg: `md5` = der Stand mit der kaputten Zeile, neues Netz nicht in der Datei, Meldung `Zeile 136: invalid authentication method`; nach dem Aufräumen `0` kaputte Zeilen und `pg_ctlcluster 16 main restart` → **online** |
| **7** | `# srvpanel: Zurückspielen einer Sicherung` und `local all +srvpanel_restore scram-sha-256` ganz oben, vor und nach dem Zurückspielen unverändert |
| **8** | Netz zurück → 0 Zeilen, **Block ganz fort**; Zugang mit zwei Netzen entfernt → 0 Zeilen; `md5` = der von Punkt 1 |
| **8e** | `1 von 2 Zugangsregel(n) … zeigen auf nichts im Bestand:` samt Zeile und dem Hinweis, dass nichts von selbst passiert |
| **8b** | Warnung auf der Kommandozeile **und** auf der Seite: „1 Netz(e) … aber der Server horcht nur lokal" |
| **9** | `conf.d` leer, `listen_addresses = localhost`, **beide `md5` die von Punkt 1** |
| **9b** | `localhost`, Fernzugriff **aus**, Netze **0**, keine Warnung |

---

## 3. Die zwei Fehler, die den Lauf unterbrochen haben

### 3.1 `--bind=::` hat das Panel abgeschaltet

Punkt 3 lautete `srvpanel db --remote=on --bind=::`. Danach gab jede Seite einen
500er. **MariaDB bindet `::` ausschliesslich IPv6**, das Panel verbindet über
`127.0.0.1`. Das Protokoll steht in **`docs/44`**, samt der drei weiteren Fehler,
die daran hingen. Behoben in `rc.4`.

Der Lauf konnte erst weitergehen, nachdem `--bind=*` gebaut, die Gegenprobe ins
Panel geholt und der Rückweg vom Bestand gelöst war.

### 3.2 Jeder Formularfehler landete auf `/login`

Punkt 6 verlangt, dass ein abgewiesenes Netz **begründet** wird. Abgewiesen
wurde es — die Begründung fehlte, und statt ihrer kam eine wortlose
Weiterleitung auf die Übersicht.

Entschieden hat das der Vergleich zweier Antworten, nicht das Lesen:

| Eingabe | Antwort |
|---|---|
| `198.51.100.7/32` (gültig) | `302 → /databases/22` — `to_route(…)` |
| `0.0.0.0/0` (abgewiesen) | `302 → /login` — `back()` |

Laravel merkt sich die vorige Seite nur bei GET-Anfragen, die nicht als XHR
gelten, und **jede Inertia-Navigation ist XHR**. `_previous.url` stand deshalb
nach dem Anmelden dauerhaft auf `/login`; dorthin leitete jede
`ValidationException`, die `guest`-Middleware schickte den angemeldeten Benutzer
weiter auf die Übersicht, und die Meldung sah niemand. **Das traf jedes Formular
des Panels, seit es das Panel gibt.** Behoben in `rc.5` durch
`RememberPageUrl`.

**Drei falsche Spuren gingen voraus** — abgelaufene Sitzung, absolute
Sitzungsdauer, fehlende Anzeige im Formular. Jede war plausibel, und jede kostete
Zeit. Beendet hat es ein Versuch mit einem *gültigen* Netz: Der ging durch, und
damit war der Fehler auf den Abweisungsweg eingegrenzt.

> **Ein Fehlschlag, den man an der falschen Stelle sucht, hat auch dort eine
> plausible Ursache.**

---

## 4. Was der Lauf über sich selbst gelernt hat

### 4.1 Der Kernpunkt hätte nie etwas gemessen

`docs/43` Punkt 6 baute die kaputte Zeile mit
`sed 's#^host <DB>#host <DB> KAPUTT#'` ein — also **in die verwaltete Zeile
selbst**. Die setzt `Hba::render()` bei jedem Schreiben neu: Es wirft alles
zwischen `BEGIN` und `END` weg und hängt seinen eigenen Stand ans Ende. Der
Eingriff war verschwunden, bevor ihn etwas bemerken konnte; die Datei war
fehlerfrei, `errors()` fand nichts, und es gab nichts zurückzurollen. **Der
Vorgang meldete Erfolg.**

> **Ein Eingriff, den der Prüfling selbst überschreibt, prüft nichts.**

Das ist derselbe Fehler wie ein toter Eingriff in `tests/waechter-brechen.sh` —
nur stand er im Abnahmedokument, über dem geschrieben steht: *„Wer ihn auslässt,
hat den Fernzugriff nicht geprüft, sondern nur benutzt."* Die kaputte Zeile
gehört **ausserhalb** des Blocks; dort steht sie jetzt.

Gemerkt hat es niemandes Nachdenken, sondern die Tabelle im Panel: Das Netz war
eingetragen, obwohl es hätte scheitern müssen.

### 4.2 Und vier weitere Stellen, die beim Fahren gerissen sind

- **Der `sed` in 8e konnte nie laufen.** `s#^# END srvpanel#…#` — das `#` im
  Suchmuster beendet den Trenner von `sed`.
- **Punkt 9 verlangte eine Warnung, die 8b unmöglich macht.** 8b nimmt sein Netz
  vorher zurück; danach gibt es niemanden auszusperren. 8b kam mit `rc.3` dazu
  und hat Punkt 9 die Voraussetzung weggenommen.
- **8b braucht einen zweiten Zugang**, den der Lauf nicht anlegt — Punkt 8
  entfernt den einzigen.
- **`--bind=*` stand unquotiert**, und der HTTP-Aufruf nannte `$(hostname -f)`
  statt der Adresse, unter der das Panel bedient (`cloudsrv24.de:8443`).

Dazu die falsche Behauptung in `docs/44`, `ss` könne die beiden Bindungsfälle
nicht unterscheiden — es kann: **ein Eintrag heisst IPv6-only, zwei Einträge
heissen beides.**

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

---

## 5. Was offen bleibt

Fünf Befunde am Produkt, unterwegs **notiert und nicht repariert** (`docs/43 §5`
— ein Lauf, der unterwegs repariert wird, misst den Zustand nach der Reparatur):

1. **Ein gescheiterter Netz-Eintrag lässt seine Zeile im Bestand.**
   `RemoteAccess::add()` legt die Zeile an und ruft danach `sync()`; wirft der,
   bleibt sie stehen. Im Panel steht dann „erreichbar von …", und die Datei hat
   keine Zeile dafür — dieselbe Lage wie der Fehler, für den es `rc.2` gab, nur
   von der anderen Seite. **`srvpanel db` meldet diese Richtung nicht:**
   `orphans()` sieht nur Zeilen, die im Bestand fehlen, nicht Bestand, der in der
   Datei fehlt.
2. **Die Bestandszeile steht unter der falschen Überschrift.**
   `Databases::showServer()` zählt Datenbanken, Zugänge und Sicherungen ohne
   `engine`-Filter, druckt sie aber im MariaDB-Block. Auf `cloudsrv24` hielt
   MariaDB nichts und PostgreSQL alles — die Zeile las sich trotzdem wie eine
   Auskunft über MariaDB.
3. **Die Fehlermeldung steht doppelt** auf der Seite: als Banner oben und am
   Feld unten, wörtlich derselbe Satz.
4. **Die Zeilennummer im Rückweg beschreibt einen Dateizustand, den es nicht
   mehr gibt.** Gemessen: `140` vor dem Versuch, `136` in der Meldung — während
   des Versuchs war der alte Block heraus und der neue ans Ende gehängt.
5. **„Nichts liegengeblieben." in Grün** steht direkt unter einer orangen
   Meldung über verwaiste Zeilen. Beide Aussagen stimmen und meinen
   Verschiedenes; nebeneinander gelesen widersprechen sie sich.

Dazu unverändert aus `docs/44 §5`: **die übrigen Schalter, die einen Dienst neu
starten, den das Panel selbst braucht, sind nicht durchgesehen.**

---

## 6. Was dieser Lauf nicht geprüft hat

Unverändert gegenüber `docs/43 §6`: **den Paketfilter** (P9), **die
Fassungsspanne** (gefahren gegen PostgreSQL 16 auf Ubuntu 24.04; Debian 12,
Debian 13 und Ubuntu 22.04 stehen offen in `docs/38 §2.3`), **den Dauerbetrieb**
und **Last**.

Und eines, das dieser Lauf hinzufügt: **die Wiederherstellung nach einem
gescheiterten Neustart** ist nicht gefahren worden. Punkt 6 belegt, dass der
Cluster nach dem Rückweg hochkommt — nicht, was geschieht, wenn er es nicht tut.
Der Handgriff dafür steht in `docs/43`, benutzt hat ihn niemand.
