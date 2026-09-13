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
