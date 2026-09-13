# 915 — Abnahmelauf für die Protokollseite

> **Ausgeschrieben am 13. September 2026, vor dem Fahren.** Gegenstand ist
> `docs/914`: die Fusszeile, die sagt, wie viel sie zeigt, und die
> Zeilennummern. Gefahren wird auf `cloudsrv24` gegen die erste Fassung, die
> beides enthält.

## §0 Was beim Ausschreiben umgefallen ist

**Drei Kriterien aus `docs/914 §9` tragen so nicht.**

**Punkt 1 fasste zwei Zustände in einem Satz zusammen.** Er verlangte für eine
Datei kürzer als das Fenster, „der Knopf steht nicht da". Gemessen in der
Bilderrunde steht er sehr wohl — bei `lines=100` und 118 Zeilen gibt es mehr zu
zeigen, und das ist richtig. Er verschwindet erst, wenn `lines` die Zahl der
Treffer erreicht.

> **Ein Kriterium, das zwei Zustände in einem Satz zusammenfasst, misst keinen
> von beiden.** „Das Fenster ist vollständig" und „alles ist gezeigt" sind zwei
> Dinge; Punkt 1 misst jetzt beide getrennt.

**Punkt 3 braucht einen Gegenstand, den der Server vielleicht nicht hat.** Der
Bytedeckel greift erst, wenn die letzten 500 Zeilen im Schnitt über **1048 B**
lang sind. Ob eine der acht Quellen auf `cloudsrv24` das erfüllt, weiss niemand
— **§2 misst es, bevor der Punkt gefahren wird**, und §5 sagt, wie der Zustand
sonst umkehrbar hergestellt wird.

> **Ein Prüfkörper, der einen Zustand braucht, den es auf dieser Maschine
> vielleicht nicht gibt, wird gesucht und nicht vorausgesetzt.**

**Punkt 5 setzte voraus, dass ein Journal nie vollständig ist.** Das stimmt
nicht: `journalctl --lines=500` liefert für eine ruhige Unit weniger als 500
Einträge, und dann ist `complete` wahr und die Nummern sind `1..n`. Welche der
acht Units über 500 Einträge hat, misst §2 mit.

## §1 Vorbedingung — trägt die installierte Fassung das Merkmal?

**Gemessen wird die Wirkung und nicht eine Fassungsnummer.** Der Griff muss im
heilen Fall eine positive Antwort geben; sonst ist er keine Prüfung, sondern
eine Behauptung, die auch im heilen Fall rot ist (`docs/913 §1`).

```bash
srvpanel version
/opt/srvpanel/current/agent/bin/srvpanel-agentd call system.logs.tail \
  '{"source":"agent","lines":5}' | head -20
```

**Erwartet:** Die Antwort trägt `read`, `complete`, `capped` und `offsets` —
und **kein** `window` und **kein** `origin`. Fehlt eines davon, ist die falsche
Fassung installiert, und der Lauf hört hier auf.

Wenn der Agent nicht antwortet, ersatzweise am Quelltext der installierten
Fassung — gemessen am Arbeitsbaum sind es **vier** beziehungsweise **drei**
Zeilen, alle Code und keine davon ein Kommentar:

```bash
grep -c "'capped' =>" /opt/srvpanel/current/agent/src/Ops/SystemLogsTail.php   # 4
grep -c "'capped' =>" /opt/srvpanel/current/agent/src/Ops/WebLogsTail.php      # 3
grep -rl 'attr(data-nummer)' /opt/srvpanel/current/public/build/assets/        # eine .css
```

**Die dritte Zeile hat beim Ausschreiben ihren Gegenstand gewechselt.** Der
erste Wurf las `/opt/srvpanel/current/resources/js/Pages/Logs/Index.vue` —
`packaging/build.sh` paketiert aber `resources/views` und **nicht**
`resources/js`. Der Griff hätte auf **jedem heilen Server** nichts gefunden.

> **Eine Vorbedingung, die man nicht gegen den heilen Fall gemessen hat, ist
> keine Prüfung — sie ist eine Behauptung, die auch im heilen Fall rot ist.**
> Zum zweiten Mal nach `docs/913 §1`, und diesmal vor dem Fahren bemerkt.

Gemessen im Arbeitsbaum: `attr(data-nummer)` steht im gebauten Stylesheet,
`log-body` in Stylesheet und Bündel, und ein erfundener Name findet nichts.

## §2 Bestandsaufnahme — welche Quelle trägt welchen Punkt

**Vor jedem Punkt, und sie entscheidet drei davon.**

```bash
for f in /var/lib/srvpanel/storage/logs/laravel.log \
         /var/log/srvpanel/update.log \
         /var/log/srvpanel/upgrade.log \
         /var/log/srvpanel/agent.log \
         /var/log/srvpanel/panel-error.log \
         /var/log/srvpanel/panel-access.log \
         /var/log/apt/history.log \
         /var/log/auth.log; do
  if [ ! -f "$f" ]; then printf '%-48s fehlt\n' "$f"; continue; fi
  tail -500 "$f" | awk -v f="$f" '{b += length($0) + 1; n++}
    END {printf "%-48s %4d Zeilen  %6.0f B/Zeile  %s\n", f, n, (n ? b/n : 0),
         (n && b/n > 1048 ? "DECKEL" : (n < 500 ? "kurz" : "lang"))}'
done

for u in web worker agentd cron metrics tls dns usage; do
  printf '%-22s %s Einträge\n' "srvpanel-$u" \
    "$(journalctl -u "srvpanel-$u" --lines=500 --no-pager -o short-iso 2>/dev/null | wc -l)"
done
```

**Daraus wird gewählt:**

- **`kurz`** → Punkt 1 (weniger Zeilen als das Fenster)
- **`lang`** → Punkt 2 (mehr Zeilen als das Fenster)
- **`DECKEL`** → Punkt 3; steht dort keine Zeile, siehe §5
- eine Unit mit **500** Einträgen → Punkt 5, Form „vom Ende"
- eine Unit mit **weniger** → Punkt 5, Form „echte Zeilen"

## §3 Punkt 1 — eine kurze Quelle, zwei Zustände

Mit `<kurz>` aus §2, einmal mit einem `lines`, das **unter** der Zeilenzahl
liegt, und einmal darüber:

```
/logs?source=<kurz>&lines=10
/logs?source=<kurz>&lines=500
```

**Erwartet bei `lines=10`:** Die Fusszeile nennt „gelesen wurden die letzten
**N** Zeilen" mit dem **wahren** N und nicht 500; der Knopf „Mehr Zeilen" steht
da.

**Erwartet bei `lines=500`:** dieselbe Zahl, **kein** Knopf, und die Notiz
„Das ist die ganze Quelle; die Nummern sind ihre Zeilen." Die erste Nummer ist
**1**.

## §4 Punkt 2 — eine lange Quelle

```
/logs?source=<lang>&lines=100
```

**Erwartet:** „gelesen wurden die letzten 500 Zeilen", die Notiz „Die Nummern
zählen vom Ende: −1 ist die letzte Zeile.", die erste Nummer **−100**, die
letzte **−1**. Der Knopf steht da; nach einem Druck stehen 200 Zeilen da und
die erste Nummer ist **−200**.

## §5 Punkt 3 — der Bytedeckel *(darf nicht ausfallen)*

**Steht in §2 eine Quelle mit `DECKEL`,** wird sie genommen:

```
/logs?source=<deckel>&lines=500
```

**Steht dort keine,** wird der Zustand umkehrbar hergestellt — gesichert wird
**vorher** und mit `cp -a`, und der Rückweg steht im selben Block:

```bash
cp -a /var/lib/srvpanel/storage/logs/laravel.log /root/laravel.log.vorher
python3 - <<'PY'
with open('/var/lib/srvpanel/storage/logs/laravel.log', 'a') as f:
    for i in range(1, 601):
        f.write(f"[2026-09-13 00:00:00] production.ERROR: Prüfkörper {i} " + "x" * 4000 + "\n")
PY
# … messen …
mv /root/laravel.log.vorher /var/lib/srvpanel/storage/logs/laravel.log
```

**Erwartet:** Die Seite zeigt **weniger als 500** Zeilen, die Fusszeile nennt
genau diese Zahl, und die Notiz trägt zusätzlich „Weiter zurück wurde nicht
gelesen — das Fenster ist auch in Bytes begrenzt."

**Und die Gegenprobe gehört dazu:** nach dem Rückbau ist der Satz fort. Ohne
sie bliebe offen, ob er immer dasteht.

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.**

## §6 Punkt 4 — mit Filter sind die Nummern nicht fortlaufend

```
/logs?source=<lang>&lines=100&filter=<ein Wort, das nicht in jeder Zeile steht>
```

**Erwartet:** Die Nummern haben **Lücken**; die Fusszeile nennt die Treffer.
Notiert wird die Folge der ersten fünf Nummern — eine lückenlose Folge wäre der
Befund.

## §7 Punkt 5 — das Journal *(darf nicht ausfallen)*

Beide Formen, mit den Units aus §2:

```
/logs?source=journal-<viel>&lines=100      (500 Einträge)
/logs?source=journal-<wenig>&lines=100     (weniger als 500)
```

**Erwartet bei `<viel>`:** Nummern **−100 … −1**, Notiz „zählen vom Ende".
**Bei `<wenig>`:** Nummern **1 … n**, Notiz „Das ist die ganze Quelle".

**Das ist der Punkt, den der Container grundsätzlich nicht messen kann** — er
hat kein Journal. Deshalb darf er nicht ausfallen.

## §8 Punkt 6 — bei 390 px, und die Nummer nach dem Rollen

`tests/bilder-messen.js` in die Browserkonsole einfügen, je Lage **eine frisch
geladene Seite**, dann `bilderMessen()`.

**Erwartet:** `dokument=0`, `gegenprobe=200 (soll 200)`, `schiebt=0`.

Dazu die Klebeprobe, im selben Seitenaufbau:

```js
(() => {
  const rahmen = document.querySelector('.log')
  const nummer = document.querySelector('.log-number')
  const links = () => Math.round(nummer.getBoundingClientRect().left - rahmen.getBoundingClientRect().left)
  const vorher = links()
  rahmen.scrollLeft = Math.max(1, rahmen.scrollWidth - rahmen.clientWidth)
  const nachher = links()
  return { rollweg: rahmen.scrollWidth - rahmen.clientWidth, vorher, nachher }
})()
```

**Erwartet:** `vorher === nachher`, und ein **Rollweg über 0** — ohne ihn ist
die Gleichheit trivial wahr. Gemessen im Container: `17 -> 17` bei Rollwegen
bis 31 433 px.

## §9 Punkt 7 — das Kopieren nimmt die Nummer nicht mit

Von Hand, und **mit der Maus**: drei Zeilen im Protokoll überstreichen,
kopieren, in das Filterfeld einfügen und wieder löschen.

**Erwartet:** Der eingefügte Text trägt die Protokollzeilen und **keine
Nummer**.

**Ein programmatischer `Range` taugt dafür nicht** — er nimmt den Text eines
`user-select: none` mit, und ein Mensch tut etwas anderes. Genau das hat die
Bilderrunde einen Fehlversuch gekostet (`docs/914 §13`).

## §10 Punkt 8 — beide Themen, beide Breiten

Vier Lagen (hell/dunkel × 390/1440 px) an der Quelle aus Punkt 2, je eine
Aufnahme und je eine Messung. **Die Nummernspalte muss in beiden Themen gegen
ihre Fläche lesbar sein** — gerechnet und nicht geschätzt, 4,5:1.

## §11 Was dieser Lauf ausdrücklich nicht prüft

- **Die Domainseite** (`web.logs.tail`). Sie bekommt die Felder mitgesendet und
  zeigt sie nicht; das ist in `docs/914 §12` benannt und nicht gebaut.
- **Den Deckel des Runners** (4 MiB) am Journal. Er lässt sich nur mit einer
  Unit herstellen, die so viel schreibt; gemessen ist er an einem
  selbstgebauten `Result`.
- **Die Laufzeit.** Der Leser hat sich nicht geändert; er gibt nur mehr
  Auskunft.
- **Ob ein Betreiber die Nummern nützlich findet.** Das hält kein Test und
  keine Messung.

## §12 Wann er durch ist

Alle acht Punkte erfüllt. **Punkt 3 und Punkt 5 dürfen nicht ausfallen** — der
eine ist der Fall, den es ohne die Messrunde nicht gäbe, der andere der, den
dieser Container grundsätzlich nicht messen kann.

Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist **nicht**
„nicht herstellbar": `tests/bilder-messen.js` verlangt eine Browserkonsole, und
vom Telefon aus geht das nicht (`docs/108`). Dann wird er nachgeholt und nicht
weicher gelesen.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten,
> ist keines mehr — es ist eine Zusammenfassung.**
