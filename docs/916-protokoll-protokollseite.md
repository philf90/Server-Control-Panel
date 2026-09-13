# 916 — Protokoll zum Abnahmelauf der Protokollseite

> Der Plan ist `docs/914`, der Lauf **`docs/915`**. Gefahren am 13. September
> 2026 auf `cloudsrv24` gegen **`0.7.4-rc.8`**. Dieses Protokoll wächst mit dem
> Lauf; was offen ist, steht offen da.

## §1 Vorbedingung — halb gemessen, und das ist ein Befund an der Vorschrift

**Befund 1 — `| head -20` schneidet genau vor den Feldern ab, nach denen der
Punkt fragt.** Die Antwort des Agenten ist hübsch gedruckt, und zwanzig Zeilen
sind hier exakt: die Hülle, fünf Protokollzeilen, `offsets` mit fünf Zahlen.
`read`, `complete` und `capped` stehen dahinter.

> **Ein Griff, der genau vor dem Feld abschneidet, nach dem er fragt, misst die
> Hälfte — und die andere sieht aus, als wäre sie geprüft.**

**Was der Lauf trotzdem belegt hat:**

- `offsets` steht da: `[495, 496, 497, 498, 499]`. Bei `lines: 5` sind das die
  letzten fünf eines **500 Zeilen** grossen Fensters — die Lage wird also
  gesendet und nicht bei null neu gezählt.
- **`origin` ist fort.** Es stünde unmittelbar hinter `label`, und dort steht
  `exists`. Das Feld ohne Leser aus `docs/914 §12` ist damit auf dem Server
  belegt.

**Ungemessen blieben** `read`, `complete`, `capped` und die Abwesenheit von
`window`. `docs/915 §1` fragt seitdem mit einem Ausdruck nach genau diesen
Feldern statt mit einer Zeilenzahl.

## §2 Bestandsaufnahme — und sie hat einen Punkt umgestellt

| Datei | Zeilen | B/Zeile | Marke |
|---|---|---|---|
| `/var/lib/srvpanel/storage/logs/laravel.log` | 0 | 0 | kurz (leer) |
| `/var/log/srvpanel/update.log` | 36 | 43 | **kurz** |
| `/var/log/srvpanel/upgrade.log` | 0 | 0 | kurz (leer) |
| `/var/log/srvpanel/agent.log` | 500 | 189 | **lang** |
| `/var/log/srvpanel/panel-error.log` | 81 | 244 | kurz |
| `/var/log/srvpanel/panel-access.log` | 500 | 153 | lang |
| `/var/log/apt/history.log` | 251 | 52 | kurz |
| `/var/log/auth.log` | 500 | 130 | lang |

**Keine einzige Quelle trägt `DECKEL`.** Die dickste ist `panel-error.log` mit
**244 B/Zeile**, und die Schwelle liegt bei 1048. Punkt 3 hat auf dieser
Maschine keinen Gegenstand und wird über den Prüfkörper aus `docs/915 §5`
gefahren — umkehrbar, mit `cp -a` davor.

> **Ein Prüfkörper, der einen Zustand braucht, den es auf dieser Maschine
> vielleicht nicht gibt, wird gesucht und nicht vorausgesetzt.** Gesucht, nicht
> gefunden — und der Plan hatte den Fall vorgesehen.

**Die Journale, gezählt mit `wc -l`:**

| Unit | Zeilen | trägt |
|---|---|---|
| `srvpanel-web` | 503 | Punkt 5, Form „vom Ende" |
| `srvpanel-worker` | 501 | |
| `srvpanel-agentd` | 502 | |
| `srvpanel-cron` | 501 | |
| `srvpanel-metrics` | 502 | |
| **`srvpanel-tls`** | **359** | Punkt 5, Form „echte Zeilen" |
| `srvpanel-dns` | 501 | |
| `srvpanel-usage` | 501 | |

**`srvpanel-tls` ist die einzige Unit unter 500** und damit die einzige, an der
sich die zweite Form von Punkt 5 überhaupt zeigen lässt. Ohne die
Bestandsaufnahme wäre der Punkt an einer der sieben anderen gefahren worden und
hätte die Hälfte gemessen.

**Eine Zahl daran ist eine Frage und keine Messung.** `journalctl --lines=500`
liefert höchstens 500 Einträge, gezählt wurden 501 bis 503. Die Differenz sind
Zeilen, die `journalctl` selbst schreibt — Bootmarken oder eine Kopfzeile. Ob
der Leser des Agenten sie als Protokollzeilen durchreicht, sagt erst Punkt 5:
`readJournal()` wirft leere Zeilen und `-- No entries --` weg und sonst nichts.

> **Eine Zahl, die um zwei über ihrer Obergrenze liegt, zählt etwas mit, das
> niemand bestellt hat.**

## Zuordnung für die weiteren Punkte

| Punkt | Quelle |
|---|---|
| 1 — kurze Quelle, zwei Zustände | `update.log` (36 Zeilen) |
| 2 — lange Quelle | `agent.log` (500 Zeilen) |
| 3 — Bytedeckel *(Ausschluss)* | Prüfkörper nach `docs/915 §5` |
| 4 — Filter | `agent.log` |
| 5 — Journal *(Ausschluss)* | `journal-web` und `journal-tls` |
| 6 · 7 · 8 — Bilder, Kleben, Kopieren | `agent.log` |

---

## §1b Vorbedingung nachgeholt — erfüllt

```
"read": 500   "complete": false   "capped": false   "matched": 500   "truncated": true
```

Fünf Felder da, **kein `window`**, **kein `origin`**. Dass die fünf dastehen,
ist der Beleg, dass der Ausdruck greift — die Abwesenheit der beiden anderen
bedeutet damit etwas.

## §3 Punkt 3 — der Bytedeckel *(Ausschlusskriterium, erfüllt)*

Prüfkörper nach `docs/915 §5`: 600 Zeilen à rund 4 KiB an `laravel.log`
angehängt, die Datei war vorher leer.

| gemessen an | Ergebnis |
|---|---|
| Agent, mit Prüfkörper | `read: 130` · `complete: false` · **`capped: true`** · `matched: 130` · `truncated: false` |
| Seite | 130 nummerierte Zeilen, „130 Zeilen · gelesen wurden die letzten 130 Zeilen" |
| Notiz | „Die Nummern zählen vom Ende: −1 ist die letzte Zeile. **Weiter zurück wurde nicht gelesen — das Fenster ist auch in Bytes begrenzt.**" |
| Gegenprobe nach dem Rückbau | `read: 0` · **`capped: false`** |

**Die Containermessung war auf die Zeile genau.** Derselbe Prüfkörper ergab
dort ebenfalls **130** (`docs/914 §13`).

> **Ein Aufsatz, der das echte Markup und das gebaute Stylesheet benutzt, misst
> die echte Seite** — und hier auch die echte Zahl.

### Befund 2 — beim Bytedeckel ist die erste Zeile ein Bruchstück

Auf dem Bild trägt Zeile **−130** kein `[2026-09-13 …]`, sondern nur `xxxx…`.
Der Leser liest rückwärts in Blöcken; endet er am Bytedeckel, fängt sein Text
**mitten in einer Zeile** an, und `explode("\n", …)` macht daraus einen ersten
Eintrag, der keine Zeile ist.

**Der Leser kennt das Problem und schützt nur den einen Ausstieg.** Sein
Kommentar sagt die Absicht: *„Der erste Block endet in aller Regel mitten in
einer Zeile, und die gehört nicht angeschnitten zurückgegeben."* Der Schutz ist
`substr_count($text, "\n") > $count` — ein Umbruch mehr als gewünscht, damit
`array_slice($all, -$count)` das Bruchstück wegschneidet. Beim Bytedeckel
greift diese Bedingung nie, und dann bleibt es stehen.

> **Ein Schutz, der an einer von drei Abbruchbedingungen hängt, schützt die
> beiden anderen nicht — und welche greift, entscheidet der Inhalt der Datei.**

Kein Kriterium fragt danach; der Befund fiel aus dem **Bild** heraus, nicht aus
einer Zahl. Er ist **nicht während des Laufs behoben** — eine Behebung ist eine
Änderung am Prüfling.

### Eine Beobachtung, kein Befund

Die Fusszeile sagt „130 Zeilen · gelesen wurden die letzten 130 Zeilen" —
dieselbe Zahl zweimal, weil ohne Filter `matched` und `read` gleich sind. Sie
steht schon als Beobachtung in `docs/914 §13`; der Blick auf dem Server
bestätigt sie und entscheidet sie nicht.
