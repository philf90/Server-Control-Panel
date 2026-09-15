---
name: steward
description: Die Betreuung eines offenen Pull Requests in diesem Repo — was hier von den allgemeinen PR-Regeln trägt, welcher Lauf der Taktgeber ist, was vor dem Push lokal gemessen wird und was nur die CI sagen kann. Wird bei jedem CI-Ereignis auf einem PR dieser Sitzung gelesen; von Hand aufrufbar, wenn ein PR zu beobachten ist.
---

# Die Betreuung eines Pull Requests in diesem Repo

Gilt, sobald ein PR offen ist — für jedes CI-Ereignis, jede Rückmeldung und
jeden Check-in. Er ersetzt die allgemeinen Regeln nicht; er sagt, was von ihnen
hier trägt, was ins Leere läuft, und woran dieses Repo seine Läufe schon
verloren hat.

**Die Zahlen tragen ihr Datum, weil sie altern und nichts sie prüft.** Wer sie
wieder misst, schreibt das neue Datum daneben — eine Zahl ohne Datum ist eine
Vermutung mit Anspruch.

---

## 1 · Was ein PR hier ist — gemessen am 15. September 2026

| Gemessen | Wert |
|---|---|
| PRs insgesamt | 49, alle von einem `claude/…`-Zweig auf `main` |
| Lebensdauer | 17 bis 80 Minuten, Median rund 30 (letzte zehn) |
| Angeforderte Rezensenten | **keine**, in keinem der letzten zwölf |
| Review-Kommentare, Labels, Review-Bots | **keine** |
| Wer mergt | der Betreiber, selbst |

**Daraus folgt, was hier nicht gilt.** Die allgemeinen Regeln über
Review-Threads, das erneute Anfordern eines Rezensenten nach einer Änderung,
über „Claude Code Review", „Claude Approvals" und über die Unterscheidung
zwischen kleinen und grossen Bitten eines Rezensenten beschreiben einen Vorgang,
den dieses Repo nicht führt. Ein PR ist hier **kein Review-Vorgang, sondern der
Durchlauf zweier Läufe.**

> **Eine Regel, die einen Vorgang beschreibt, den es nicht gibt, wird nicht
> falsch angewandt — sie wird auf etwas anderes angewandt.**

Kommt doch einmal eine Rückmeldung — der Betreiber schreibt sie dann selbst —,
gilt der allgemeine Text unverändert. Was er sagt, ist eine Meldung des
Betreibers und keine eines Bots: Sie wird nicht auf Dringlichkeit sortiert,
sondern beantwortet.

---

## 2 · Der Taktgeber ist `waechter.yml` und nicht `ci.yml`

Gemessen am 15. September 2026 über die letzten Läufe am Ereignis
`pull_request`:

| Lauf | Dauer (Median) | längster | rot |
|---|---|---|---|
| `ci.yml` — 15 Jobs | **3,0 min** | 16 min | 8 von 60 |
| `waechter.yml` — ein Job, 1342 Eingriffe | **23,2 min** | 28,0 min | 2 von 38 |

**Jeder der acht roten CI-Läufe war in unter 3,5 Minuten rot** — die schnellen
Jobs fallen zuerst, und das sind genau die, die auch lokal fahrbar sind.

**Ein PR ist damit nicht nach drei Minuten beurteilt, sondern nach
fünfundzwanzig.** Wer nach der grünen CI „grün" meldet, hat die Hälfte der
Prüfung nicht gesehen — und zwar die Hälfte, die prüft, ob die Wächter dieses
Repos überhaupt beissen.

> **Ein Lauf, der noch läuft, ist kein grüner Lauf — und der schnellere von
> zweien sagt über den langsameren nichts.**

**Also: immer abwarten, bis beide durch sind, und dazwischen still bleiben.**
Der Betreiber wartet gemessen ohnehin: Von den letzten zehn PRs wurde **keiner**
gemergt, bevor der Wächterlauf fertig war, und bei zweien lagen zwischen seinem
Ende und dem Merge unter zwei Minuten.

**`waechter.yml` läuft ausschliesslich an `pull_request`, `workflow_dispatch`
und montags um 04:00 UTC.** Auf einem Zweig ohne PR fährt er nie. `ci.yml` hängt
auf `push` nur an `main` — auf einem Zweig ohne PR fährt also **gar nichts**,
und das sieht aus wie eingerichtet.

---

## 3 · Vor dem Push: was hier lokal fahrbar ist

`vendor/` lässt sich in diesem Container herstellen (`CLAUDE.md` →
„Diese Umgebung"). Damit ist **jeder Job ausser den Plattform-Jobs vor dem Push
messbar.**

**Wie viele der acht roten Läufe das gewesen wären, ist nicht gezählt** — und
die Vermutung „alle" ist widerlegt: Am 2. September fiel `Installation auf …`
an einer Migration, die gegen SQLite durchgeht. Belegt sind die vier Fälle in
der Tabelle unten, drei davon lokal fahrbar und einer nicht.

> **Eine Runde CI, die eine lokale Messung ersetzt, kostet zwanzig Minuten und
> beantwortet dieselbe Frage.**

### Die Befehle werden aus `ci.yml` gezogen, nicht nacherzählt

**Nicht aus dem Gedächtnis und nicht aus `CLAUDE.md`.** Belegt am
15. September 2026: `CLAUDE.md` nennt `shellcheck -e SC1091 packaging/bin/*` als
„wörtlich in `ci.yml` und einmal Kopieren" — dort stehen **fünf** Aufrufe, und
der genannte deckt `packaging/install.sh`, `packaging/php-source.sh`,
`packaging/scripts/*.sh`, `packaging/version-channel.sh`,
`packaging/release-notes.sh`, `packaging/build.sh`, `packaging/testbed.sh`
und `packaging/rotate-signing-keys.sh` nicht ab.

> **Ein Befehl, den ein zweites Dokument nacherzählt, ist eine zweite Fassung —
> und die zweite ist die, die veraltet.**

Der Griff ist `sed -n '<von>,<bis>p' .github/workflows/ci.yml` auf den Job, der
rot war, oder auf alle, die der Zweig berührt.

### Die Naht zur CI — jeder Job ist hier eingeordnet

**Diese Tabelle ist die Naht, und `StewardSkillTest` hält sie.** Jeder Job aus
`ci.yml` und `waechter.yml` steht entweder hier oder in §4 — ein neuer Job
zwingt damit zu der Frage, ob er vor dem Push messbar ist. Job- und
Schrittnamen stehen wörtlich so, wie der Workflow sie führt.

| Job | Schritt | vor dem Push |
|---|---|---|
| `Statische Prüfung` | `composer.json prüfen` | `composer validate --strict` |
| `Statische Prüfung` | `Pint` | `pint.phar --test` — und **vor** dem Commit, nicht danach |
| `Statische Prüfung` | `PHPStan` | siehe die Fallen unten; ohne sie misst der Lauf nicht |
| `Statische Prüfung` | `Der Agent hängt an nichts` | der Zweizeiler aus `ci.yml`, wenn der Zweig `agent/` berührt |
| `Tests` | *(der Schritt trägt keinen Namen)* | `php artisan test` |
| `Oberfläche` | `Typen` | `npm run types` |
| `Oberfläche` | `Bundle` | `npm run build` |
| `Oberfläche` | `Farbwerte stehen nur in app.css` | der `grep` aus `ci.yml` — er trifft **auch** `rgb()`, `hsl()`, `oklch()` und `color-mix()`, nicht nur Hexwerte |
| `Shell-Skripte` | `shellcheck` | **alle fünf** Aufrufe, siehe unten |
| `Schwachstellen und Lizenzen` | `composer audit` | `composer audit --no-interaction` |
| `Schwachstellen und Lizenzen` | `npm audit` | `npm audit --audit-level=high` |
| `Schwachstellen und Lizenzen` | `Lizenzen sind mit der AGPL vereinbar` | der Block aus `ci.yml`, wenn eine Abhängigkeit dazukommt |
| `Jede Regel absichtlich brechen` | `tests/waechter-brechen.sh` | die Eingriffe, die der Zweig berührt — welche das sind, steht unten |

### Was schon getroffen hat

| Job | Wann | Was dieses Repo daran verloren hat |
|---|---|---|
| `Statische Prüfung` | 2. Sept. | 21 Typmeldungen auf einmal — der lokale Lauf ging über die geänderten `app/`-Pfade, geändert waren `tests/` |
| `Oberfläche` | 11. Sept. | `Farbwerte stehen nur in app.css`; der Ausdruck trifft mehr als Hexwerte |
| `Shell-Skripte` | 1. Sept. | `SC2317` in `packaging/bin/apt-run`, nachdem vorher nur `bash -n` gefahren worden war |
| `Jede Regel absichtlich brechen` | 5. und 10. Sept. | zwei rote Läufe, beide „Eingriff ohne Biss" |
| `Installation auf …` | 2. Sept. | zwei `TIMESTAMP NOT NULL` in einer Tabelle — **der einzige der vier, den kein lokaler Lauf gefunden hätte** |

### Die Fallen, an denen der lokale Lauf *scheinbar* grün ist

Alle in `CLAUDE.md` belegt, alle schon einmal bezahlt:

- **Die Dateiliste kommt aus `git diff --name-only origin/main...HEAD`, und zwar
  unmittelbar vor dem Lauf.** PR #223 kam mit einer PHPStan-Meldung zurück, die
  es beim Lauf noch nicht gab: Zwei Dateien waren danach dazugekommen.
- **Gefahren wird mit `-c phpstan.neon`**, der Projektdatei — eine Wegwerfdatei
  prüft eine andere Konfiguration als die CI.
- **Ein Prüfkörper gehört in denselben Aufruf.** Ein PHPStan-Lauf über *eine*
  einzelne, schon bestehende Datei meldet wortlos nichts. Ohne ein absichtliches
  `strlen(42)`, das eine Zeile erzeugen **muss**, ist „leer" keine Messung.
- **`env -u AI_AGENT -u CLAUDECODE`** vor jedem Lauf von PHPStan und PHPUnit.
  Sonst verpackt das Gestell die Ausgabe, und ein Leser, der darin nichts
  findet, meldet „sauber".
- **Ein Trait allein ergibt null Zeilen.** Seine Nutzer gehören in denselben
  Lauf, sonst ist die Stille keine Messung — und eine Schnittstelle, die im Lauf
  fehlt, macht aus „ich kenne sie nicht" ein „die Klasse erfüllt sie nicht".
- **Gefiltert wird nach dem Zerlegen.** Ein `grep -v` über eine einzeilige
  JSON-Ausgabe löscht nicht eine Zeile, sondern den Bericht. Und ein Filter auf
  `notFound` löscht `property.notFound` mit — acht von einundzwanzig Meldungen
  am 2. September.
- **Pint läuft nach dem Wächter, und ins Repo geht die Fassung danach.**
- **Vom Bruchskript gehören die Eingriffe dazu, deren Wächter eine geänderte
  Datei *liest*** — nicht nur die, deren `vorher_datei` sie nennt:
  `grep -rl "<pfad>" tests/`. Siebzehn Wächter lesen allein `routes/web.php`.
- **Während das Bruchskript läuft, wird nicht am Repo gearbeitet.** Es stellt
  den Arbeitsbaum mit `git checkout --` her und nimmt einem zweiten Schreiber
  seine Arbeit weg. Am 26. August genau so passiert, und der Commit trug beides.

---

## 4 · Was nur die CI sagen kann

Diese Jobs nimmt kein lokaler Lauf vorweg. Ein rotes Ergebnis von hier ist
deshalb **nie** ein Grund, den Lauf zu wiederholen, bevor er gelesen wurde.

| Job | warum nicht hier |
|---|---|
| `Installation auf …` | Paketbau, systemd als PID 1, Ersteinrichtung, Update und purge — je Debian 12/13 und Ubuntu 22.04/24.04 |
| `Nur das Paket auf …` | derselbe Grund, ohne die Voraussetzungen ringsum |
| `apt-Messrunde auf …` | die vier Fälle, die im Container nicht vorkommen |

**Und alles, was gegen MariaDB statt SQLite läuft.** Am 2. September fiel
`Installation auf …` an zwei `TIMESTAMP NOT NULL` in einer Tabelle; gegen
SQLite fällt davon nichts auf, und die stille Hälfte — eine Spalte, die bei
jedem `UPDATE` auf die Wanduhr springt — hätte ein Abnahmekriterium unerfüllbar
gemacht.

> **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
> Grenzen der falschen.**

---

## 5 · Bei rot

**Ursache suchen, Behebung lokal gegen genau den Job messen, der rot war,
pushen — und erst danach melden.** Entschieden vom Betreiber am
15. September 2026. Kein Zwischenstand, keine Ankündigung der Behebung; der
Push ist die Lieferung, eine Beschreibung ist es nicht.

1. **Den Job lesen, bevor irgendetwas gefahren wird.** Welcher Job, welcher
   Schritt, welcher Wortlaut — und dann die Stelle in `ci.yml` dazu.
2. **Den Fehlschlag zuerst nachstellen**, dann denselben Lauf grün zeigen.
   Beides gehört in die Commit-Meldung, samt der Gegenprobe.
3. **Minimal bleiben.** Was der Fehlschlag verlangt, nicht mehr. Der PR wird
   nicht nebenbei erweitert.
4. **Eine Wiederholung ist kein Befund.** Ein zweiter Lauf kommt nur in Frage,
   wenn der Job vor dem ersten Testrumpf gestorben ist — Checkout, Installation,
   Runner weg. „Flake" ist keine Ursache, und ein Test wird nie übersprungen,
   abgeschaltet oder eingesperrt.
5. **Nach dem Push wieder abwarten, bis beide Läufe durch sind** — auch beim
   zweiten und dritten Mal. Es gibt keine Rundenzahl, nach der etwas anderes
   gilt.

**Beim Bruchskript ist „passed (erwartet: failed)" nicht immer ein Fehler.** Am
10. September war die Ausnahme, die ein Eingriff für veraltet erklärte, nicht
veraltet — derselbe Zweig hatte die Route gebaut, auf die sie sich bezog. Der
Wächter blieb zu Recht grün.

> **Ein Prüfkörper, der einen Zustand behauptet, statt ihn zu prüfen, hört auf
> zu messen, sobald jemand den Zustand herstellt — und sagt es nicht.**

Ein Eingriff, der eine **Abwesenheit** braucht, sichert sie deshalb zu und
bricht laut ab, wenn sie nicht mehr gilt.

---

## 6 · Was hier nicht getan wird

- **Kein Merge und keine Freigabe.** Beides gehört dem Betreiber. Ein Tag lässt
  sich aus diesem Container ohnehin nicht setzen (zweimal `HTTP 403`, gemessen
  am 8. September).
- **Kein leerer Commit und kein Schliessen-und-Öffnen**, um einen Lauf
  anzustossen.
- **Kein Umschreiben der Historie**: kein Rebase, kein Amend, kein
  force-push auf einen Zweig mit offenem PR.
- **Kein PR ohne ausdrückliche Bitte.** Und ist der PR gemergt, wird der Zweig
  unter demselben Namen frisch von `main` gestartet, statt auf gemergter
  Historie zu stapeln.
- **Keine Meldung, die „grün" sagt, solange ein Lauf noch läuft.**

---

## 7 · Was kein Wächter halten kann

Hier stehen Fragen und keine Zusagen — sie hängen an etwas, das keine
Eigenschaft des Quelltextes ist:

- **Ist der Befund, den ein Job meldet, der Befund, den er meinen sollte?** Ein
  roter Job sagt, dass eine Prüfung gefallen ist, nicht dass sie das Richtige
  geprüft hat. Am 2. September lagen zwei der drei behobenen Ausfälle nicht im
  Prüfling: Der lokale PHPStan-Lauf war über die falschen Pfade gegangen, und
  ein Filter hatte acht von einundzwanzig Meldungen weggeworfen.
- **Deckt die Behebung die Ursache oder das Symptom?** Ein zweites Rot an
  derselben Stelle heisst: die Ursache suchen, nicht die nächste Zeile ändern.
- **Ist eine Zahl in diesem Dokument noch die gemessene?** Sie altert, und
  `StewardSkillTest` hält nur, dass die genannten Jobs, Dateien und Befehle
  existieren — nicht, dass die Werte daneben stimmen.
