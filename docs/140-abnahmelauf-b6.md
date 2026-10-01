# B6 — der Abnahmelauf für Marke, Farbe und Logo des Betreibers

Ausgeschrieben am 1. Oktober 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Logo, Farbe, Fusszeile und Absenderadresse des Betreibers stehen auf der
> Anmeldeseite und in einer verschickten Mail. Jede Farbe kommt aus
> `resources/css/app.css`.

Gebaut ist B6 seit dem 21. September (`de2b010b`) und ausgeliefert seit
`v0.9.0-rc.1`; die Begründungen stehen im CHANGELOG unter „B6 — Marke, Farbe
und Logo des Betreibers". Name, Fusszeile, zwei Akzentfarben und ein Logo
stehen auf `/settings/general`, die Absenderadresse steht seit P2 auf
`/settings/mail`.

**Beim Ausschreiben sind sechs Befunde am Prüfling herausgefallen** (§0
Punkte 1 bis 6) und zwei Fragen an das Kriterium (§0 Punkte 7 und 8). Die 26
Fälle aus `BrandReachTest`, `BrandStyleTest` und `BrandContrastTest` waren
grün. Gezeigt haben sich die Befunde erst im echten Chromium und durch die Tür,
gegen den gebauten Stand von `main` (`09999b07`). §6 fragt, ob sie vor dem
Lauf behoben werden und wie das Kriterium gelesen wird. Gefahren wird gegen
die Freigabe, die diese Entscheidungen trägt. **Entschieden hat der Betreiber
am selben Tag, alle drei Fragen wie vorgeschlagen**, und behoben sind die
sechs Befunde mit `0.9.0-rc.11` (§6a).

**Neu ist ein Snippet für die Konsole** (§3). Es liest auf der Anmeldeseite
und auf jeder Seite des Panels, was von der Marke ankommt: den Titel im
Reiter, Logo oder Zeichen, die Fusszeile und die Farben, wie sie in beiden
Themen wirken. Seine Erwartung nimmt es aus der Seite selbst, aus den Daten,
die der Server mitschickt, und aus dem Markenblock im Kopf. Zur Gegenprobe
schaltet es den Markenblock ab. Gegen den heutigen Stand meldet es die Befunde
1 und 5 von selbst, ohne Marke sagt es „kommt an" (§2a).

---

## §0 · Was beim Ausschreiben umgefallen ist

Gemessen im Container gegen `09999b07`: eine eigene, frisch migrierte
Datenbank im Scratchpad, darin ein Prüfkonto mit zweitem Faktor, `artisan
serve` auf dem gebauten Stand, Chromium 141. Gesetzt war die Marke aus §2,
„Muster Hosting", hell `#0b6e4f`, dunkel `#6ee7b7`, Logo `b6-logo-a.png`. Die
Anmeldung und das Formular liefen über die echten Seiten, nicht über den
Prüfstand der Tests.

**1 · Die helle Farbe des Betreibers greift nie.** `app.css` setzt die helle
Fassung mit `:root, :root[data-theme='light']`, und `data-theme` steht immer
am `<html>`. Der Markenblock schreibt für hell nur `:root`. Das ist weniger
spezifisch, 0,1,0 gegen 0,2,0, und die Reihenfolge entscheidet erst bei
Gleichstand. Gemessen auf der Anmeldeseite, mit dem Markenblock im Kopf:

| `data-theme` | `--accent` an der Wurzel |
|---|---|
| `light` | `#3730a3` — die Vorgabe |
| `dark` | `#6ee7b7` — die Farbe des Betreibers |
| ohne | `#0b6e4f` — die Farbe des Betreibers |
| `light`, Markenblock abgeschaltet | `#3730a3` |

Im dunklen Thema greift die Farbe, weil beide Seiten `:root[data-theme='dark']`
schreiben und der Markenblock später steht. Auf `/settings/general` trägt der
Hauptknopf im hellen Thema `#3730a3`, auch nach vollem Laden. Die
Anmeldeseite merkt davon nichts: Sie nimmt in beiden Themen die dunkle Farbe.

`BrandStyleTest` liest, was `Style::css()` ausgibt, und dort steht `:root`.
Ob es gegen `app.css` ankommt, fragt kein Fall. Der Kopf von `Style.php` nennt
„drei Selektoren, weil es drei Flächen gibt"; `app.css` schreibt die helle
Fläche mit zwei.

> **Ein Wert, der eine Marke überschreiben soll, muss mindestens so spezifisch
> sein wie die Regel, die sie setzt — die Reihenfolge entscheidet erst bei
> Gleichstand.**

**2 · Nach dem Speichern bleibt die Farbe bis zum Neuladen die alte.** Der
Markenblock steht im Kopf des Dokuments, geschrieben von `app.blade.php`. Eine
Inertia-Antwort tauscht die Seite und lässt den Kopf, wie er war. Gemessen
unmittelbar nach dem Speichern auf `/settings/general`: Die Hinweise nennen
schon „Gemessen 6:1 auf #fafafb" und „Gemessen 11,77:1 auf #14171d", und das
Logo steht in der Leiste. Der Hauptknopf trägt im dunklen Thema aber noch
`#ff7fec`, die Vorgabe. Erst ein volles Laden bringt `#6ee7b7`. Wer eine Farbe
speichert, sieht also keine Änderung, bis er F5 drückt.

> **Was im Kopf des Dokuments steht, erneuert nur ein volles Laden.**

**3 · „Logo entfernen" entfernt nichts.** Der Knopf schickt `remove_logo: true`
über `router.post(…, { forceFormData: true })`. In Formulardaten reist ein
Wahrheitswert als Zeichenkette: Inertia 3.6.1 schreibt `"1"` (`append()` in
`@inertiajs/core`). Der Controller vergleicht
`($data['remove_logo'] ?? false) === true`. Gemessen durch die Tür, einmal mit
dem Wert, den der Browser schickt, einmal mit dem, den nur ein Prüfstand
schicken kann:

| `remove_logo` | Antwort | Logo danach | `/branding/logo` |
|---|---|---|---|
| `"1"`, wie der Browser | Weiterleitung, „Die Marke ist gespeichert." | liegt | 200 |
| `true`, wie ein Prüfstand | Weiterleitung | fort | 404 |

Im echten Chromium dasselbe: `remove_logo` steht im Rumpf als `1`, die
Erfolgsmeldung kommt, die Logo-Route liefert dieselbe Prüfsumme, und der Knopf
steht weiter da. Kein Test schickt `remove_logo` überhaupt.

> **Dieselbe Regel über einem Wert, der einmal als JSON und einmal als
> Zeichenkette reist, gilt nur einmal.** Der Satz steht seit P6 in
> `CLAUDE.md`, dort über die Suche im Dateimanager.

**4 · Ein neues Logo erscheint erst nach bis zu fünf Minuten.** Das Logo liegt
unter einer festen Adresse, `/branding/logo`, mit
`Cache-Control: public, max-age=300`. Gemessen in Chromium: Logo A geladen,
dann Logo B abgelegt, PNG gegen JPEG. Derselbe Browser zeigt danach beim
Neuladen und beim neuen Aufruf weiter A; die Antworten kommen aus dem
Zwischenspeicher, mit `image/png`. Ein frischer Browser zeigt B, und der
Server liefert `image/jpeg`. Wer sein Logo austauscht, sieht das alte und hält
das Hochladen für gescheitert. Wer die Anmeldeseite in den fünf Minuten davor
geladen hat, sieht ebenfalls das alte.

> **Eine Adresse, die bleibt, wenn sich ihr Inhalt ändert, liefert den alten
> Inhalt, solange der Zwischenspeicher ihn für frisch hält.**

**5 · Im Reiter steht „SrvPanel", auf jeder Seite.** Der Server schreibt den
Namen des Betreibers in den Kopf: `<title inertia>Muster Hosting</title>`.
Sobald Inertia startet, ersetzt es den Titel, und `resources/js/app.ts` baut
ihn mit einem festen Wort:

```ts
title: (titel) => (titel ? `${titel} · SrvPanel` : 'SrvPanel'),
```

Gemessen steht im Reiter „Anmeldung · SrvPanel" auf der Anmeldeseite und
„Allgemein · SrvPanel" auf `/settings/general`, in beiden Themen. Ohne Marke
steht dort derselbe Titel; der Reiter folgt der Marke also gar nicht. Der
Hinweis unter dem Namen auf `/settings/general` sagt dagegen: „Steht im
Reiter des Browsers". `BrandReachTest::test_the_document_title_carries_the_name`
liest die Antwort des Servers, also den Titel, bevor Inertia ihn ersetzt.

Zur selben Familie gehören zwei Stellen. Das eingebaute Zeichen trägt
`aria-label="SrvPanel"` (`MarkIcon.vue`). Neben dem Namen liest ein Vorleser
deshalb „SrvPanel Muster Hosting", und ohne Marke „SrvPanel SrvPanel". Die
Fehlerseiten tragen `@yield('title') · SrvPanel`.

> **Ein Wächter über die Antwort des Servers sieht den Titel nicht, den der
> Browser danach ersetzt.**

**6 · Betreff und Testmail sagen „SrvPanel".** Die drei Mails des Panels,
verschickt über den Speicher-Mailer mit der Marke aus §2 und dem Absender
`"Hoster GmbH" <panel@hoster.example>` aus den Mail-Einstellungen:

| Mail | Absender | Betreff | Unterschrift |
|---|---|---|---|
| Testmail | `"Hoster GmbH" <panel@hoster.example>` | `SrvPanel — Testmail` | `--`, Name, Fusszeile |
| Kontingent, an den Kunden | derselbe | `SrvPanel — Kontingent überschritten: kunde-web` | dieselbe |
| Bestandsdiagnose, an den Betreiber | derselbe | `SrvPanel — ein neuer Befund auf vm` | dieselbe |

Alle drei sind `text/plain`. Der Rumpf der Testmail beginnt mit „Diese
Nachricht bestätigt, dass SrvPanel über das eingetragene Relay verschicken
kann." Die zweite Mail geht an die Kunden des Betreibers.
`BrandReachTest::test_a_sent_mail_carries_name_and_footer` liest den Rumpf und
nicht den Betreff. Der Name im Absender kommt nicht aus der Marke, sondern aus
`/settings/mail`. Dort ist er ein Pflichtfeld, vorbelegt mit „SrvPanel"
(gemessen an einer frischen Datenbank: `Absender: SrvPanel <>`).

> **Ein Name, den der Betreiber einstellen kann, steht überall dort falsch, wo
> ihn jemand als Wort hingeschrieben hat.**

**7 · Logo und Farbe stehen nicht in der Mail, und das ist eine Entscheidung
von P2.** Die Mails dieses Panels sind reiner Text; HTML kann auf dem Weg
verändert werden, Text nicht. Das CHANGELOG von B6 nennt diesen Teil des
Kriteriums deshalb „benannt nicht erfüllt". Die Absenderadresse steht dagegen
nur in der Mail und nicht auf der Anmeldeseite. Wörtlich gelesen ist das
Kriterium damit nicht erfüllbar. §6 Frage 2 fragt, wie es gelesen wird.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den
> Verfasser.**

**8 · Die Farbe des Betreibers erreicht die Leiste nicht.** `Style::css()`
schreibt `:root`, `:root[data-theme='dark']` und `.signin`. Leiste und
Kopfleiste setzen in `app.css` ein eigenes `--accent`, `#ffb7a5`, und das schon
in `rc.9`. Gemessen tragen der aktive Menüpunkt, das Überfahren und der Punkt
am Menüknopf in beiden Themen `#ffb7a5`, während der Hauptknopf daneben die
Farbe des Betreibers trägt. Das Kriterium nennt die Leiste nicht. §6 Frage 3.

### Was gemessen ist und trägt

- **Eine Farbe unter 4,5:1 wird abgewiesen**, mit Zahl und Fläche: „Diese Farbe
  erreicht auf #fafafb nur 3,87:1. Der Akzent trägt auch Schrift; verlangt
  sind 4,5:1." Die Zusammenfassung steht oben und holt sich ins Bild.
- **Ein SVG wird abgewiesen, auch unter dem Namen `.png`**: „Nur PNG, JPEG und
  WebP — diese Datei ist image/svg+xml. SVG ist ausgeschlossen: …". Der Typ
  kommt aus dem Inhalt und nicht aus dem Namen.
- **Ein Bild von 470 KB wird abgewiesen**: „Das Bild ist 470 KB gross; erlaubt
  sind 256 KB." Der Pool des Panels nimmt bis 512 MB an
  (`packaging/etc/fpm.conf`). Die Abweisung kommt also vom Panel und nicht von
  PHP.
- **Gespeichert wird über `POST` mit `_method=put` und Formulardaten**, und
  das Logo kommt an: Die Route liefert dieselbe Prüfsumme wie die hochgeladene
  Datei, ohne Anmeldung, mit `image/png` und `nosniff`. Ohne Logo gibt sie 404.
- **Der Markenblock setzt ausschliesslich Marken**: drei Regeln, zehn
  Zuweisungen, keine Eigenschaft. Ohne eigene Farben steht gar keiner da.
- **Auf der Anmeldeseite** steht das Logo, 360×96 Bildpunkte, als Kasten
  128×34, mit dem Namen als Alternativtext; Zeichen und Name stehen nicht
  daneben. Die Fusszeile steht über der Versionsnummer. Der Hauptknopf trägt
  `#6ee7b7` mit Schrift `#0f1116`, 12,39:1; ohne Markenblock `#ffb7a5`. In der
  Leiste steht das Logo als Kasten 90×24.
- **Die Unterschrift jeder Mail** trägt Name und Fusszeile, und der Absender
  kommt aus `/settings/mail`.
- **Die Ablage überlebt ein Update.** `storage` jeder Fassung ist ein Verweis
  nach `/var/lib/srvpanel/storage` (`packaging/nfpm.yaml`), und dorthin
  schreibt der Pool als `srvpanel`.
- **Kein langlebiger Prozess verschickt Mails.** Der Meldelauf startet je Lauf
  neu, die Testmail kommt aus der Anfrage. Eine geänderte Marke steht also in
  der nächsten Mail.

---

## §1 · Die Vorbedingung

Block 1 liest, was vor dem Lauf auf dem Server steht. Er läuft ein zweites Mal
nach den Punkten 1 und 2: Dort darf sich nichts geändert haben.

Die Adresse des Panels kommt aus `/etc/srvpanel/panel.env` und `hostname -f`;
`--resolve` schickt die Anfrage an den eigenen Rechner, `-k` lässt das
Zertifikat aus, weil es hier nicht der Gegenstand ist. Der Block druckt die
Adresse mit, die er benutzt.

```bash
# 1 · Fassung, Marke, Absender und Ablage vor dem Lauf
srvpanel version
srvpanel tinker --execute='$s = app(App\Support\Settings\Settings::class); echo json_encode($s->brand()->toArray(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), PHP_EOL; $m = $s->mail(); echo "Absender: ", $m->from_name, " <", $m->from_address, ">", PHP_EOL;'
ls -la /var/lib/srvpanel/storage/app/branding/ 2>&1
P=$(awk -F= '/^PANEL_PORT=/{print $2; exit}' /etc/srvpanel/panel.env); H=$(hostname -f); U="https://$H:$P"; echo "Panel: $U"
curl -sk --noproxy '*' --resolve "$H:$P:127.0.0.1" "$U/login" | grep -o -E '<title inertia>[^<]*</title>|<style>:root[^<]*</style>'
curl -sk --noproxy '*' --resolve "$H:$P:127.0.0.1" -o /dev/null -w 'Logo-Route: %{http_code}\n' "$U/branding/logo"
```

**Erwartet**, wenn noch nie eine Marke gesetzt war:

```
{"name":"SrvPanel","accent_light":"#3730a3","accent_dark":"#ff7fec","footer":"","logo":null}
Absender: <Name> <Adresse aus /settings/mail>
<ls meldet, dass es /var/lib/srvpanel/storage/app/branding/ nicht gibt>
Panel: https://<Rechner>:<Port>
<title inertia>SrvPanel</title>
Logo-Route: 404
```

Ohne eigene Farben steht **keine** `<style>`-Zeile da; das ist die
Gegenprobe zu Punkt 3. Die Absenderadresse ist die, über die B1 seine Mails
verschickt hat; leer darf sie nicht sein, sonst fällt Punkt 6 aus.

**Steht schon eine eigene Marke da**, wird die Zeile abgeschrieben: Punkt 9
stellt sie wieder her.

---

## §2 · Die Prüfkörper und die Werte des Laufs

Vier Dateien, verschickt mit diesem Lauf. Sie liegen auf dem Rechner, an dem
der Browser läuft.

| Datei | Grösse | Inhalt | sha256 |
|---|---|---|---|
| `b6-logo-a.png` | 681 B | PNG 360×96, links ein mintgrünes Feld | `9dd9c55e1ea8dc76bbb2872a0430fdcb4fc3dc6f00277dafaa6d8570390975d7` |
| `b6-logo-b.jpg` | 5.194 B | JPEG 360×96, links ein roter Kreis | `b48f82fb558501ec8704c5ea2740b6008ffc812c2fadb7a2d16d0e8d3edd77cf` |
| `b6-svg-als-png.png` | 71 B | ein SVG mit `<script>`, unter dem Namen `.png` | `3f67c8be987aa1db0ef99ce330286efe1929e729f5f9d1695b5fd0b987246244` |
| `b6-zu-gross.png` | 481.330 B | PNG 400×400 aus Rauschen, 470 KB | `c7acac55565bf113f36891f50a53f525fa753ddede7d975c50bdafae35b6fefc` |

**Die beiden Logos unterscheidet ein Bildpunkt.** An der Stelle (40, 48) ist A
`rgb(110,231,183)` und B `rgb(192,32,32)`; das Snippet liest genau diesen Punkt.
Damit zeigt eine Zeile, welches Logo der Browser wirklich hat, und nicht nur,
dass er eines hat. Gemessen in Chromium an beiden Dateien.

Die Werte, die in das Formular gehen:

| Feld | Wert | gemessen |
|---|---|---|
| Name des Panels | `Muster Hosting` | |
| Fusszeile der Anmeldeseite | `Betrieben von der Muster Hosting GmbH · Musterweg 1 · 12345 Musterstadt` | |
| Akzent im hellen Thema | `#0b6e4f` | 6:1 auf `#fafafb` |
| Akzent im dunklen Thema | `#6ee7b7` | 11,77:1 auf `#14171d` |
| abgewiesen, hell | `#2f8f5b` | 3,87:1 auf `#fafafb` |

Grün, weil es mit keiner Vorgabe zu verwechseln ist: hell Indigo `#3730a3`,
dunkel Pink `#ff7fec`, Anmeldeseite und Leiste Pfirsich `#ffb7a5`.

### §2a · Das Snippet gegen den heutigen Stand

Gefahren im Container gegen `09999b07`, je Seite frisch geladen, mit der Marke
aus §2. Auf der Anmeldeseite:

```
b6Messen · 1. Oktober 2026 · /login · Breite 1440 px
Marke laut Seite: Name „Muster Hosting" · Fusszeile „Betrieben von der Muster Hosting GmbH · Musterweg 1 · 12345 Musterstadt" · Logo http://127.0.0.1:8123/branding/logo
Markenblock: 3 Regeln, 10 Zuweisungen, davon keine Marke: 0
  :root → #0b6e4f · :root[data-theme="dark"] → #6ee7b7 · .signin → #6ee7b7
Titel im Reiter: Anmeldung · SrvPanel
Anmeldeseite: Logo http://127.0.0.1:8123/branding/logo · alt „Muster Hosting" · Bild 360×96 · Kasten 128×34 · Punkt(40,48) rgb(110,231,183)
Fusszeilen: „Betrieben von der Muster Hosting GmbH · Musterweg 1 · 12345 Musterstadt" · „Quellbaum"
Ladebeleg: Grund der Maske #1a0b2e (erwartet #1a0b2e)
Farben, wie sie wirken:
  light  Wurzel #3730a3  Hauptknopf #6ee7b7  Anmeldeseite #6ee7b7  Leiste —  aktiver Menüpunkt —
  dark   Wurzel #6ee7b7  Hauptknopf #6ee7b7  Anmeldeseite #6ee7b7  Leiste —  aktiver Menüpunkt —
Gegenprobe, Markenblock abgeschaltet:
  light  Wurzel #3730a3  Hauptknopf #ffb7a5  Anmeldeseite #ffb7a5  Leiste —  aktiver Menüpunkt —
  dark   Wurzel #ff7fec  Hauptknopf #ffb7a5  Anmeldeseite #ffb7a5  Leiste —  aktiver Menüpunkt —
Urteil: der Reiter nennt „Muster Hosting" nicht · die helle Farbe greift nicht: Wurzel #3730a3 statt #0b6e4f
Thema der Seite zurück auf: light
```

Auf `/settings/general`, angemeldet, frisch geladen (ab dem Titel; die
Zeilen davor sind dieselben):

```
Titel im Reiter: Allgemein · SrvPanel
Leiste: Logo http://127.0.0.1:8123/branding/logo · alt „Muster Hosting" · Bild 360×96 · Kasten 90×24 · Punkt(40,48) rgb(110,231,183)
Ladebeleg: Grund der Leiste #1a0b2e (erwartet #1a0b2e)
Farben, wie sie wirken:
  light  Wurzel #3730a3  Hauptknopf #3730a3  Anmeldeseite —  Leiste #ffb7a5  aktiver Menüpunkt #ffb7a5
  dark   Wurzel #6ee7b7  Hauptknopf #6ee7b7  Anmeldeseite —  Leiste #ffb7a5  aktiver Menüpunkt #ffb7a5
Gegenprobe, Markenblock abgeschaltet:
  light  Wurzel #3730a3  Hauptknopf #3730a3  Anmeldeseite —  Leiste #ffb7a5  aktiver Menüpunkt #ffb7a5
  dark   Wurzel #ff7fec  Hauptknopf #ff7fec  Anmeldeseite —  Leiste #ffb7a5  aktiver Menüpunkt #ffb7a5
Urteil: der Reiter nennt „Muster Hosting" nicht · die helle Farbe greift nicht: Wurzel #3730a3 statt #0b6e4f
```

**Die Gegenprobe für das Snippet selbst** ist dieselbe Seite ohne Marke. Dort
steht „Markenblock: keiner", Zeichen und Name „SrvPanel", die Farben der
Vorgabe, und das Urteil lautet „was die Seite über die Marke sagt, kommt an".
Das Snippet trennt also die beiden Fälle, und ein Urteil ohne Befund heisst
etwas.

---

## §3 · Die Punkte

Die Seiten werden als Betreiber geöffnet, die Anmeldeseite in einem **privaten
Fenster**. In die Konsole kommt das Snippet, je Seite **frisch geladen**:

```js
// B6 · docs/140 §3: was von der Marke auf dieser Seite ankommt.
// Einmal auf der frisch geladenen Seite in die Konsole einfügen.
(() => {
  const STAND = 'b6Messen · 1. Oktober 2026'
  if (window.__b6Gemessen) {
    console.log('Schon gemessen — Seite neu laden (Strg+F5) und dann erneut einfügen.')
    return
  }
  window.__b6Gemessen = true

  const html = document.documentElement
  const vorher = html.getAttribute('data-theme')
  const zeilen = [`${STAND} · ${location.pathname} · Breite ${innerWidth} px`]
  const fehler = []
  const hex = (s) => {
    const t = String(s).trim()
    if (t.startsWith('#')) return t.toLowerCase()
    const m = (t.match(/[\d.]+/g) || []).map(Number)
    return m.length < 3 ? (t || '—') : '#' + m.slice(0, 3).map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')
  }
  const marke = (el) => (el ? hex(getComputedStyle(el).getPropertyValue('--accent')) : '—')
  const grund = (el) => (el ? hex(getComputedStyle(el).backgroundColor) : '—')

  // Was der Server über die Marke sagt: die geteilte Ablage der Seite.
  const brand = document.getElementById('app')?.__vue_app__?.config.globalProperties.$page?.props?.brand
  zeilen.push(`Marke laut Seite: Name „${brand?.name ?? '?'}" · Fusszeile „${brand?.footer ?? '?'}" · Logo ${brand?.logo ?? '—'}`)

  // Der Markenblock, wie der Browser ihn gelesen hat (CSSOM, kein Textvergleich).
  const block = [...document.head.querySelectorAll('style')].find((s) =>
    [...(s.sheet?.cssRules ?? [])].some((r) => r.style && r.style.getPropertyValue('--accent') !== ''))
  const soll = {}
  if (block) {
    const regeln = [...block.sheet.cssRules]
    const fremd = []
    let n = 0
    for (const r of regeln) {
      for (const p of r.style) { n++; if (!p.startsWith('--')) fremd.push(`${r.selectorText} ${p}`) }
      soll[r.selectorText] = hex(r.style.getPropertyValue('--accent'))
    }
    zeilen.push(`Markenblock: ${regeln.length} Regeln, ${n} Zuweisungen, davon keine Marke: ${fremd.length}${fremd.length ? ' — ' + fremd.join(' | ') : ''}`)
    zeilen.push(`  ${regeln.map((r) => `${r.selectorText} → ${soll[r.selectorText]}`).join(' · ')}`)
    if (fremd.length) fehler.push('der Markenblock setzt Eigenschaften')
  } else {
    zeilen.push('Markenblock: keiner — es gelten die Vorgabefarben aus app.css')
  }

  zeilen.push(`Titel im Reiter: ${document.title}`)
  if (brand?.name && !document.title.includes(brand.name)) fehler.push(`der Reiter nennt „${brand.name}" nicht`)

  // Logo oder Zeichen und Name — auf der Anmeldeseite und in der Leiste.
  for (const [wo, sel] of [['Anmeldeseite', 'main.signin'], ['Leiste', 'aside.rail']]) {
    const f = document.querySelector(sel)
    if (!f) continue
    const img = f.querySelector('img.brand-logo')
    if (img) {
      let punkt = 'nicht lesbar'
      try {
        const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight
        const g = c.getContext('2d'); g.drawImage(img, 0, 0)
        punkt = `rgb(${[...g.getImageData(40, 48, 1, 1).data].slice(0, 3).join(',')})`
      } catch (e) { punkt = `nicht lesbar (${e.name})` }
      const k = img.getBoundingClientRect()
      zeilen.push(`${wo}: Logo ${img.getAttribute('src')} · alt „${img.alt}" · Bild ${img.naturalWidth}×${img.naturalHeight} · Kasten ${Math.round(k.width)}×${Math.round(k.height)} · Punkt(40,48) ${punkt}`)
      if (!img.complete || img.naturalWidth === 0) fehler.push(`${wo}: das Logo ist nicht geladen`)
    } else {
      zeilen.push(`${wo}: kein Logo · Zeichen ${f.querySelector('svg.mark') ? 'ja' : 'nein'} · Name „${f.querySelector('.brand-name')?.textContent ?? '—'}"`)
      if (brand?.logo) fehler.push(`${wo}: die Seite nennt ein Logo, gezeigt wird keins`)
    }
  }
  const signin = document.querySelector('main.signin')
  if (signin) {
    const fuss = [...signin.querySelectorAll('p.release')].map((p) => p.textContent.trim())
    zeilen.push(`Fusszeilen: ${fuss.map((t) => `„${t}"`).join(' · ') || '—'}`)
    if (brand?.footer && fuss[0] !== brand.footer) fehler.push('die Fusszeile steht nicht über der Versionsnummer')
    zeilen.push(`Ladebeleg: Grund der Maske ${grund(signin.querySelector('.sheet'))} (erwartet #1a0b2e)`)
  } else {
    zeilen.push(`Ladebeleg: Grund der Leiste ${grund(document.querySelector('aside.rail'))} (erwartet #1a0b2e)`)
  }

  // Die Farben, wie sie wirken — je Thema, dann ohne Markenblock.
  const rail = document.querySelector('aside.rail')
  const lesen = () => {
    const knopf = signin ? signin.querySelector('.button.primary') : document.querySelector('main .button.primary')
    const aktiv = rail?.querySelector('.nav-item.active')
    return {
      wurzel: marke(html),
      knopf: grund(knopf),
      anmeldung: marke(signin),
      leiste: marke(rail),
      menuepunkt: aktiv ? hex(getComputedStyle(aktiv).color) : '—',
    }
  }
  const zeile = (t, f) => `  ${t.padEnd(5)}  Wurzel ${f.wurzel}  Hauptknopf ${f.knopf}  Anmeldeseite ${f.anmeldung}  Leiste ${f.leiste}  aktiver Menüpunkt ${f.menuepunkt}`
  try {
    zeilen.push('Farben, wie sie wirken:')
    const gemessen = {}
    for (const t of ['light', 'dark']) {
      html.setAttribute('data-theme', t)
      gemessen[t] = lesen()
      zeilen.push(zeile(t, gemessen[t]))
    }
    if (block) {
      block.disabled = true
      zeilen.push('Gegenprobe, Markenblock abgeschaltet:')
      for (const t of ['light', 'dark']) { html.setAttribute('data-theme', t); zeilen.push(zeile(t, lesen())) }
      block.disabled = false

      const regel = (pruefe) => Object.entries(soll).find(([s]) => s.split(',').some((x) => pruefe(x.trim())))?.[1]
      const hell = regel((x) => x === ':root' || x.includes('"light"'))
      const dunkel = regel((x) => x.includes('"dark"'))
      const leiste = regel((x) => x === '.rail')
      if (hell && gemessen.light.wurzel !== hell) fehler.push(`die helle Farbe greift nicht: Wurzel ${gemessen.light.wurzel} statt ${hell}`)
      if (dunkel && gemessen.dark.wurzel !== dunkel) fehler.push(`die dunkle Farbe greift nicht: Wurzel ${gemessen.dark.wurzel} statt ${dunkel}`)
      if (signin && dunkel && gemessen.light.anmeldung !== dunkel) fehler.push(`die Anmeldeseite trägt ${gemessen.light.anmeldung} statt ${dunkel}`)
      if (rail && leiste && gemessen.light.leiste !== leiste) fehler.push(`die Leiste trägt ${gemessen.light.leiste} statt ${leiste}`)
    }
  } finally {
    if (vorher === null) html.removeAttribute('data-theme'); else html.setAttribute('data-theme', vorher)
  }
  zeilen.push(fehler.length === 0 ? 'Urteil: was die Seite über die Marke sagt, kommt an' : `Urteil: ${fehler.join(' · ')}`)
  zeilen.push(`Thema der Seite zurück auf: ${vorher ?? '(keins)'}`)
  console.log(zeilen.join('\n'))
})()
```

**Ein zweiter Aufruf ohne Neuladen misst nicht**, er druckt nur den Hinweis.
Das Snippet stellt `data-theme` um und stellt es danach zurück; es schaltet
den Markenblock ab und wieder an. Auf einer Seite, die danach weitergemessen
wird, wird deshalb neu geladen.

### Punkt 1 — Eine Farbe, die nicht trägt, wird abgewiesen

Auf `/settings/general` im Bereich „Farbe" den Akzent im hellen Thema auf
`#2f8f5b` setzen, sonst nichts ändern, „Marke speichern".

**Erwartet:** oben die Zusammenfassung, im Bild:

> Das Formular wurde nicht gespeichert.
> Diese Farbe erreicht auf #fafafb nur 3,87:1. Der Akzent trägt auch Schrift;
> verlangt sind 4,5:1.

Danach Block 1 noch einmal: **dieselbe Ausgabe wie vor dem Lauf.** Eine
abgewiesene Farbe ändert nichts. Die Gegenprobe ist Punkt 3: Eine Farbe, die
trägt, wird gespeichert.

### Punkt 2 — Ein SVG und ein zu grosses Bild werden abgewiesen

Auf `/settings/general` frisch geladen, im Bereich „Logo" `b6-svg-als-png.png`
wählen, „Marke speichern". Dann neu laden, `b6-zu-gross.png` wählen, „Marke
speichern".

**Erwartet:**

> Nur PNG, JPEG und WebP — diese Datei ist image/svg+xml. SVG ist
> ausgeschlossen: Es darf Skript enthalten, und das Logo steht auf der
> Anmeldeseite.

> Das Bild ist 470 KB gross; erlaubt sind 256 KB.

Die erste Meldung ist der Punkt: Die Datei heisst `.png`, und der Typ kommt
trotzdem aus ihrem Inhalt. Danach Block 1 noch einmal: **dieselbe Ausgabe wie
vor dem Lauf**, `Logo-Route: 404`.

### Punkt 3 — Die Marke wird gespeichert

Auf `/settings/general` frisch geladen die Werte aus §2 eintragen, als Logo
`b6-logo-a.png` wählen, „Marke speichern".

**Erwartet auf der Seite:** „Die Marke ist gespeichert.", und die Seite lädt
**vollständig** neu (§6 Frage 1, Befund 2). Darunter stehen die Hinweise
„Gemessen 6:1 auf #fafafb" und „Gemessen 11,77:1 auf #14171d", und in der
Leiste steht das Logo. Unter `rc.10` bliebe die Seite stehen, und die Farben
wären bis F5 die alten.

Dann der Server:

```bash
# 2 · Was nach dem Speichern auf dem Server steht
srvpanel tinker --execute='echo json_encode(app(App\Support\Settings\Settings::class)->brand()->toArray(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), PHP_EOL;'
ls -la /var/lib/srvpanel/storage/app/branding/
sha256sum /var/lib/srvpanel/storage/app/branding/logo.*
P=$(awk -F= '/^PANEL_PORT=/{print $2; exit}' /etc/srvpanel/panel.env); H=$(hostname -f); U="https://$H:$P"; C=(curl -sk --noproxy '*' --resolve "$H:$P:127.0.0.1")
"${C[@]}" "$U/login" | grep -o -E '<title inertia>[^<]*</title>|<style>:root[^<]*</style>|"logo":"[^"]*"'
"${C[@]}" -D - -o /tmp/b6-logo "$U/branding/logo" | grep -i -E '^HTTP|^content-type|^x-content-type|^cache-control' | tr -d '\r'
sha256sum /tmp/b6-logo; rm -f /tmp/b6-logo
```

**Erwartet:**

- Die Marke mit den Werten aus §2 und `"logo":"logo.png"`.
- Eine Datei `logo.png` mit 681 Bytes, Eigentümer `srvpanel`, und **beide**
  Prüfsummen `9dd9c55e…975d7`: die der abgelegten Datei und die der
  ausgelieferten. Gleich der verschickten heisst, dass die Datei unverändert
  durch Formular, Ablage und Route gegangen ist.
- `<title inertia>Muster Hosting</title>`.
- Der Markenblock, eine Zeile `<style>…</style>`. Seine helle Regel trägt die
  Selektoren aus `app.css` (§6 Frage 1, Befund 1), seine Werte sind `#0b6e4f`
  und `#6ee7b7`, und `.rail,.topbar` steht darin (§6 Frage 3). Der Wortlaut
  steht in §6a.
- Die Adresse des Logos in den Daten der Seite, mit einer Fassung dahinter
  (`…/branding/logo?v=…`, §6 Frage 1, Befund 4). Die Schrägstriche stehen dort
  als `\/`, so schreibt JSON sie.
- `HTTP/2 200` oder `HTTP/1.1 200`, `content-type: image/png`,
  `x-content-type-options: nosniff`.

### Punkt 4 — Die Anmeldeseite trägt Logo, Farbe und Fusszeile

Im **privaten Fenster** die Anmeldeseite frisch laden, bei 1440 px. Snippet
einfügen.

**Erwartet:**

- `Titel im Reiter: Anmeldung · Muster Hosting` (Befund 5).
- `Anmeldeseite: Logo …/branding/logo?v=… · alt „Muster Hosting" · Bild 360×96 · Kasten 128×34 · Punkt(40,48) rgb(110,231,183)`.
  Kein Zeichen und kein Name daneben.
- `Fusszeilen: „Betrieben von der Muster Hosting GmbH · Musterweg 1 · 12345 Musterstadt" · „0.9.0-rc.…"`,
  die Fusszeile also über der Versionsnummer.
- `Ladebeleg: Grund der Maske #1a0b2e`.
- In beiden Themen `Anmeldeseite #6ee7b7` und `Hauptknopf #6ee7b7`, und im
  hellen Thema `Wurzel #0b6e4f`. In der Gegenprobe ohne Markenblock
  `Anmeldeseite #ffb7a5`, `Hauptknopf #ffb7a5`, `Wurzel #3730a3` und
  `#ff7fec`.
- `Urteil: was die Seite über die Marke sagt, kommt an`.

Dann vier Bilder der Anmeldeseite: bei 1440 px und bei 390 px, jeweils hell
und dunkel. Umgeschaltet wird in der Konsole mit
`window.srvpanelTheme('dark')` und `window.srvpanelTheme('light')`. Die
Anmeldeseite sieht in beiden Themen gleich aus, weil sie eine eigene Fläche
ist; die Bilder belegen genau das. Bei 390 px, frisch geladen, dazu
`tests/bilder-messen.js` mit `bilderMessen()`: **Erwartet** `dokument = 0` und
die Gegenprobe `200`. Das Logo ist dort höchstens 220 px breit
(`.signin .brand-logo`).

**Das private Fenster bleibt offen.** Punkt 7 braucht es mit dem Logo A im
Zwischenspeicher.

### Punkt 5 — Die Farbe im Panel, in beiden Themen

Im normalen Fenster, angemeldet, `/settings/general` frisch laden. Snippet
einfügen.

**Erwartet:**

- `Titel im Reiter: Allgemein · Muster Hosting`.
- `Leiste: Logo …/branding/logo?v=… · alt „Muster Hosting" · Bild 360×96 · Kasten 90×24 · Punkt(40,48) rgb(110,231,183)`.
- Hell: `Wurzel #0b6e4f  Hauptknopf #0b6e4f`.
  Dunkel: `Wurzel #6ee7b7  Hauptknopf #6ee7b7`.
  Unter `rc.10` stünde hell `#3730a3`, und das Urteil meldete es (§2a).
- Die Leiste in beiden Themen `Leiste #6ee7b7  aktiver Menüpunkt #6ee7b7`
  (§6 Frage 3).
- In der Gegenprobe ohne Markenblock die Vorgaben: hell `#3730a3`, dunkel
  `#ff7fec`, Leiste `#ffb7a5`.
- `Urteil: was die Seite über die Marke sagt, kommt an`.

Dazu zwei Bilder von `/settings/general` bei 1440 px, hell und dunkel, mit dem
Bereich „Farbe" im Bild.

### Punkt 6 — Eine verschickte Mail trägt Absender, Name und Fusszeile

Zuerst rechnet der Server, welche Betreffzeilen er schreibt:

```bash
# 3 · Was die Mails des Panels als Betreff schreiben
srvpanel tinker --execute='foreach ([new App\Mail\TestMessage("x", "y"), new App\Mail\QuotaWarning("kunde-web", []), new App\Mail\DiagnoseReport([["label" => "l", "subject" => "s", "detail" => "d", "since" => "x"]])] as $m) echo $m->envelope()->subject, PHP_EOL;'
```

**Erwartet** (§6 Frage 1, Befund 6): `Muster Hosting — Testmail`,
`Muster Hosting — Kontingent überschritten: kunde-web` und
`Muster Hosting — ein neuer Befund auf <Rechner>`. Unter `rc.10` steht dort
dreimal `SrvPanel —`.

Dann auf `/settings/mail` die Testmail verschicken. Im Postfach die Mail
öffnen und die **Quelle** ansehen (in Thunderbird „Nachrichten-Quelltext",
Strg+U; in Gmail „Original anzeigen").

**Erwartet:**

- `From:` mit Name und Adresse aus Block 1.
- `Subject: Muster Hosting — Testmail`.
- `Content-Type: text/plain; charset=utf-8`, kein HTML-Teil.
- Der Rumpf sagt in der ersten Zeile, dass **dieses Panel** über das Relay
  verschicken kann, und nicht mehr „SrvPanel".
- Am Ende die Unterschrift:

  ```
  --
  Muster Hosting
  Betrieben von der Muster Hosting GmbH · Musterweg 1 · 12345 Musterstadt
  ```

**Die Gegenprobe steht in der Zeit.** Die Fusszeile auf `/settings/general`
um ` · zweite Fassung` verlängern, speichern, die Testmail noch einmal
verschicken. In der zweiten Mail steht die verlängerte Fusszeile. Damit ist
belegt, dass die Unterschrift die gespeicherte Marke liest und keine, die
irgendwo liegen geblieben ist.

Logo und Farbe stehen in keiner der beiden Mails; das ist §6 Frage 2.

### Punkt 7 — Ein neues Logo ersetzt das alte sofort

Im normalen Fenster auf `/settings/general` `b6-logo-b.jpg` wählen, „Marke
speichern". Nach dem vollständigen Neuladen das Snippet einfügen. Danach im
**privaten Fenster aus Punkt 4** die Anmeldeseite neu laden, mit F5 und ohne
Strg, und dort das Snippet einfügen.

**Erwartet in beiden:** `Punkt(40,48) rgb(192,32,32)`, also Logo B, und eine
andere Fassung hinter `?v=` als in Punkt 4. Unter `rc.10` stünde im privaten
Fenster bis zu fünf Minuten lang `rgb(110,231,183)` (Befund 4).

Dann der Server: Block 2 noch einmal. **Erwartet:** nur noch `logo.jpg`, 5.194
Bytes, beide Prüfsummen `b48f82fb…77cf`, `content-type: image/jpeg`, und
`"logo":"logo.jpg"` in der Marke. `logo.png` ist fort, weil `Logo::store()`
vor dem Ablegen jede ältere Fassung entfernt.

### Punkt 8 — Das Logo lässt sich entfernen

Auf `/settings/general` „Logo entfernen". Dann Block 2 noch einmal und im
privaten Fenster die Anmeldeseite neu laden und das Snippet einfügen.

**Erwartet:**

- Auf dem Server `"logo":null`, das Verzeichnis ohne `logo.*`, `sha256sum`
  meldet `No such file or directory`, und die Route gibt `HTTP… 404`. Die
  zweite Prüfsumme gilt dann der Fehlerseite und sagt nichts.
- Auf der Anmeldeseite
  `Anmeldeseite: kein Logo · Zeichen ja · Name „Muster Hosting"`, und das
  Urteil „kommt an".
- Der Knopf „Logo entfernen" ist fort.

Unter `rc.10` bliebe das Logo liegen, bei gleicher Prüfsumme und mit der
Meldung „Die Marke ist gespeichert." (Befund 3).

### Punkt 9 — Zurück

Entweder die Vorgaben wieder eintragen — Name `SrvPanel`, Fusszeile leer, hell
`#3730a3`, dunkel `#ff7fec` — oder die eigene Marke des Betreibers, falls Block
1 eine gezeigt hat. Dann Block 1.

**Erwartet** mit den Vorgaben: die Ausgabe von Block 1 vor dem Lauf, also
`<title inertia>SrvPanel</title>`, **keine** `<style>`-Zeile und
`Logo-Route: 404`. Ohne eigene Farben schreibt `Style::css()` keinen Block;
das ist die Gegenprobe zu Punkt 3 in der Zeit. Anders ist nur die Zeile von
`ls`: Das Verzeichnis `branding/` steht jetzt da, leer. Das ist richtig,
angelegt hat es der erste Upload, und `Logo::forget()` räumt Dateien ab und
kein Verzeichnis.

---

## §4 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Eine Mail mit Logo und Farbe.** Es gibt sie nicht; die Mails sind seit P2
  reiner Text (§6 Frage 2).
- **Die Mail an den Kunden aus B5.** Ihr Betreff ist hier nur gerechnet
  (Block 3); verschickt wird sie im Lauf für B5, an einem überschrittenen
  Kontingent.
- **Favicon, Symbol der Startseite, Webmanifest und Fehlerseiten.** Sie
  tragen das Zeichen und den Namen „SrvPanel" als feste Dateien, und die
  Fehlerseiten müssen auch dann rendern, wenn die Datenbank fort ist. Das
  Kriterium nennt sie nicht.
- **Ein Logo im WebP-Format.** Die Positivliste nimmt es an; gefahren werden
  PNG und JPEG.
- **Die Kontrastformel.** Sie hält `BrandContrastTest`, gegen die Flächen, die
  er aus `app.css` liest. Der Lauf misst nur, dass die Abweisung an der Tür
  ankommt.
- **Ein Kundenkonto.** Die Anmeldeseite ist für alle dieselbe, und innen
  gilt derselbe Markenblock.
- **Andere Mailprogramme.** Gelesen wird die Quelle der Mail, nicht ihre
  Darstellung.

---

## §5 · Wann er durch ist

**Alle neun Punkte erfüllt.** Die Punkte 3, 4 und 6 dürfen nicht ausfallen:
Sie sind das Kriterium, die gespeicherte Marke, die Anmeldeseite und die
verschickte Mail. Punkt 6 hängt am Relay; ist keine Absenderadresse
eingetragen, ist das ein Befund an der Umgebung und kein „nicht herstellbar".
Ein Punkt, der am Werkzeug scheitert, ist nicht „nicht herstellbar"
(`docs/108`); `tests/bilder-messen.js` und das Snippet brauchen eine
Browserkonsole.

**Gefahren wird gegen die Freigabe mit den Entscheidungen aus §6.** Steht
dann ein Befund aus §0 noch so da, ist sein Punkt nicht erfüllt: Die Behebung
ist nicht angekommen. Die Abnahme spricht der Betreiber aus.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten,
> ist keines mehr — es ist eine Zusammenfassung.**

---

## §6 · Drei Fragen an den Betreiber — vor dem Lauf

**Entschieden am 1. Oktober 2026, alle drei wie vorgeschlagen.** Was gebaut
ist, steht in §6a.

Alle drei betreffen, was ein Betreiber und seine Kunden sehen, und stehen
damit unter der Regel vom 27. September: *Macht es Sinn und gibt es einen
spürbaren Mehrwert für einen Nutzer im Panel?*

**Frage 1 · Die sechs Befunde vor dem Lauf beheben?** Der Vorschlag: **ja,
alle sechs**, als `0.9.0-rc.11` vor dem Lauf. Jeder ist ein Versprechen der
Seite, das nicht eingelöst wird, und jeder ist klein:

1. **Helle Farbe:** Der Markenblock schreibt für hell dieselben Selektoren wie
   `app.css`, `:root, :root[data-theme='light']`. Ein Wächter liest die
   Selektoren der Regeln, die in `app.css` `--accent` setzen, und verlangt im
   Markenblock dieselben. Er liest sie aus dem Stylesheet und nicht aus einer
   Liste im Test.
2. **Neuladen nach dem Speichern:** Nach einer gespeicherten Marke lädt die
   Seite vollständig neu, damit der Kopf den neuen Markenblock trägt. Die
   Meldung „Die Marke ist gespeichert." bleibt.
3. **Logo entfernen:** Der Controller liest `remove_logo` als Wahrheitswert
   aus der Anfrage, so wie ihn der Browser schickt. Ein Fall durch die Tür mit
   `"1"` und einer mit `"0"`.
4. **Logo-Adresse:** Die Adresse trägt eine Fassung, gebildet aus der
   abgelegten Datei. Ein neues Logo bekommt damit eine neue Adresse, und der
   Zwischenspeicher kann nichts Altes mehr liefern.
5. **Reiter:** Der Titel lautet `<Seite> · <Name der Marke>`. Das Zeichen
   neben dem Namen wird Schmuck für den Vorleser, weil der Name daneben steht.
   Die Fehlerseiten bleiben bei „SrvPanel" (§4).
6. **Mails:** Der Betreff beginnt mit dem Namen der Marke, und die Testmail
   sagt „dieses Panel". Der Name im Absender bleibt der aus `/settings/mail`;
   er steht dort als eigenes Feld, und der Betreiber sieht, was er einträgt.

**Frage 2 · Wie wird das Kriterium gelesen?** Der Vorschlag:

- **Auf der Anmeldeseite stehen Logo, Farbe und Fusszeile.** Die Farbe ist
  dort der dunkle Akzent, in beiden Themen.
- **In der verschickten Mail stehen Absenderadresse, Name und Fusszeile.**
  Logo und Farbe stehen dort nicht, weil die Mails seit P2 reiner Text sind.
  Dieser Teil des Kriteriums wird nicht gebaut, und das steht so in
  `docs/129 §9`.

Die andere Lesart verlangte Mails in HTML. Das hiesse, jede Meldung dieses
Panels in ein Format zu heben, dessen Ankunft man nicht mehr beurteilen kann.
Nach der Regel vom 27. September wird das nicht gebaut.

**Frage 3 · Nimmt die Leiste die Farbe des Betreibers mit?** Der Vorschlag:
**ja.** Die Leiste ist wie die Anmeldeseite eine dunkle Markenfläche, in
beiden Themen dieselbe, und die Anmeldeseite nimmt den dunklen Akzent schon
mit. Der Kontrast ist schon gerechnet: Der Grund der Leiste, `#1a0b2e`, steht
in den Flächen, gegen die jede dunkle Farbe geprüft wird. Ohne die Leiste
stehen nebeneinander zwei Akzente: Pfirsich im Menü, die Farbe des Betreibers
auf den Knöpfen. Gebaut wäre es als eine Regel mehr im Markenblock, für
`.rail` und `.topbar`.

---

## §6a · Entschieden und gebaut: `0.9.0-rc.11`

Gebaut am 1. Oktober, am Tag der Entscheidung. Das Kriterium wird gelesen wie
in Frage 2, und `docs/129 §9` sagt es so.

| Befund | Gebaut | Gehalten von |
|---|---|---|
| 1 · helle Farbe | `Style::css()` schreibt an dieselben Selektoren wie `app.css`; der Block steht nach dem Stylesheet | `BrandStyleTest`, vier neue Fälle |
| 2 · Neuladen | `Inertia::location()` mit der Weiterleitung samt Meldung: 409 und `X-Inertia-Location`, der Browser lädt ganz | `BrandReachTest::test_saving_reloads_the_whole_page` |
| 3 · Logo entfernen | `$request->boolean('remove_logo')` | zwei Fälle in `BrandReachTest`, mit `"1"` und mit `"0"` |
| 4 · Logo-Adresse | `/branding/logo?v=` mit 16 Hexziffern, `xxh3` über den Inhalt (`Logo::version()`) | `BrandReachTest::test_a_new_logo_gets_a_new_address` |
| 5 · Reiter | Der Titel nimmt den Namen aus den Daten der Seite; das Zeichen trägt `aria-hidden` | `BrandNameTest` |
| 6 · Mails | Der Betreff kommt aus `MailSubject::of()`, die Testmail sagt „dieses Panel" | `BrandReachTest`, `BrandNameTest` |
| Frage 3 · Leiste | `.rail,.topbar` mit dem dunklen Akzent | `BrandStyleTest` |

**Dazu kommt ohne eigene Frage der Fokusring.** `--focus` trägt in `app.css`
an jeder dieser Flächen denselben Wert wie `--accent`. Ohne ihn stünde neben
einem grünen Knopf ein indigoblauer Ring, und deshalb setzt der Block ihn
jetzt überall mit.

**Der Markenblock mit den Werten aus §2**, wörtlich, so wie Block 2 ihn nach
Punkt 3 zeigt:

```
<style>:root,:root[data-theme='light']{--accent:#0b6e4f;--accent-on:#ffffff;--accent-surface:rgb(11 110 79 / 0.09);--focus:#0b6e4f;}:root[data-theme='dark']{--accent:#6ee7b7;--accent-on:#0f1116;--accent-surface:rgb(110 231 183 / 0.14);--focus:#6ee7b7;}.rail,.topbar{--accent:#6ee7b7;--accent-on:#0f1116;--accent-surface:rgb(110 231 183 / 0.14);--focus:#6ee7b7;}.signin{--accent:#6ee7b7;--accent-on:#0f1116;--accent-surface:rgb(110 231 183 / 0.14);--focus:#6ee7b7;}</style>
```

Vier Regeln mit je vier Marken. Das Snippet druckt deshalb
`Markenblock: 4 Regeln, 16 Zuweisungen, davon keine Marke: 0`. Die Selektoren
zeigt es so, wie der Browser sie liest, mit Leerzeichen nach dem Komma und
doppelten Anführungszeichen.

**Die Brüche.** Sechzehn neue Eingriffe stehen im Bruchskript, drei
bestehende haben einen neuen Anker. Gefahren ist jeder Eingriff, der eine der
geänderten Dateien anfasst, einzeln und mit `cp` gesichert: **50 von 50
beissen.** Gegen den alten Stand sind die neuen Fälle rot, drei in
`BrandStyleTest` und drei in `BrandNameTest`.

**Der volle Lauf danach: 3222 Prüfungen mit Biss und eine ohne.** Der Eingriff
„Logo bleibt liegen" fand seinen Anker zweimal und brach ab, weil mein neuer
Helfer in `BrandReachTest` dieselben drei Zeilen trägt wie der Fall, an dem er
hängt. Dem Einzellauf hatte ich die Dateien unter `app/` und `resources/`
mitgegeben und die Testdateien nicht. Der Fall benutzt jetzt den Helfer, der
Anker steht wieder einmal da, und der Eingriff beisst. Er lässt mit Absicht
`logo.png` liegen, und das Bruchskript räumt es seitdem danach ab.

> **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
> gefahren — man denkt an das Gebaute und nicht an das Berührte.** Diesmal an
> den Testdateien, die zum Berührten gehören.

**Beim Nachsehen fiel ein Rest des Bruchskripts auf.** Unter dem Bruch „SVG
kommt durch" legt `test_an_svg_is_refused` seinen Prüfkörper ab, und der Fall
räumte ihn nicht ab. Seit dem 28. September lag er als
`storage/app/branding/logo.svg` im Container, 66 Bytes mit
`<script>x()</script>`. Beim Ausschreiben dieses Laufs hatte ich die Datei für
älteren Bestand gehalten und liegen lassen. Der Fall räumt jetzt ab.

> **Ein Fall, der im heilen Zustand nichts ablegt, braucht trotzdem ein
> Abräumen — unter dem Bruch legt er ab.**

### Nachgemessen im Chromium

Gegen den gebauten Stand im Container, mit der Marke aus §2 in einer eigenen
Datenbank, gespeichert über das echte Formular. Ein Gastfenster steht für das
private Fenster. Die Zeilen sind die des Snippets, gekürzt auf das, wonach die
Punkte fragen.

**Punkt 3.** Vor dem Speichern stand ein Merker im Fenster, danach war er fort:
Die Seite hat ganz neu geladen, und die Meldung „Die Marke ist gespeichert."
stand da. Den dunklen Akzent auf `#7dd3fc` gesetzt und ohne F5 gelesen:
Hauptknopf `rgb(125, 211, 252)`, Leiste `--accent #7dd3fc`. Danach zurück auf
`#6ee7b7`.

**Punkt 4**, Gastfenster, 1440 px:

```
Markenblock: 4 Regeln, 16 Zuweisungen, davon keine Marke: 0
  :root, :root[data-theme="light"] → #0b6e4f · :root[data-theme="dark"] → #6ee7b7 · .rail, .topbar → #6ee7b7 · .signin → #6ee7b7
Titel im Reiter: Anmeldung · Muster Hosting
Anmeldeseite: Logo http://127.0.0.1:8123/branding/logo?v=904eae8051dd7024 · alt „Muster Hosting" · Bild 360×96 · Kasten 128×34 · Punkt(40,48) rgb(110,231,183)
Farben, wie sie wirken:
  light  Wurzel #0b6e4f  Hauptknopf #6ee7b7  Anmeldeseite #6ee7b7  Leiste —  aktiver Menüpunkt —
  dark   Wurzel #6ee7b7  Hauptknopf #6ee7b7  Anmeldeseite #6ee7b7  Leiste —  aktiver Menüpunkt —
Urteil: was die Seite über die Marke sagt, kommt an
```

**Punkt 5**, `/settings/general`, angemeldet:

```
Titel im Reiter: Allgemein · Muster Hosting
Farben, wie sie wirken:
  light  Wurzel #0b6e4f  Hauptknopf #0b6e4f  Anmeldeseite —  Leiste #6ee7b7  aktiver Menüpunkt #6ee7b7
  dark   Wurzel #6ee7b7  Hauptknopf #6ee7b7  Anmeldeseite —  Leiste #6ee7b7  aktiver Menüpunkt #6ee7b7
Gegenprobe, Markenblock abgeschaltet:
  light  Wurzel #3730a3  Hauptknopf #3730a3  Anmeldeseite —  Leiste #ffb7a5  aktiver Menüpunkt #ffb7a5
  dark   Wurzel #ff7fec  Hauptknopf #ff7fec  Anmeldeseite —  Leiste #ffb7a5  aktiver Menüpunkt #ffb7a5
Urteil: was die Seite über die Marke sagt, kommt an
```

**Punkt 6**, Block 3 gegen dieselbe Datenbank: `Muster Hosting — Testmail`,
`Muster Hosting — Kontingent überschritten: kunde-web` und
`Muster Hosting — ein neuer Befund auf vm`. Die Testmail beginnt mit „Diese
Nachricht bestätigt, dass dieses Panel über das eingetragene Relay verschicken
kann." und endet mit `--`, „Muster Hosting" und der Fusszeile.

**Punkt 7.** Logo B gespeichert, dann im Gastfenster F5 ohne Strg: sofort
`branding/logo?v=96a55ff9c30b1a1e · Punkt(40,48) rgb(192,32,32)`, also Logo B
unter einer neuen Adresse.

**Punkt 8.** Nach „Logo entfernen" ist der Knopf fort, `/branding/logo` gibt
404, und im Gastfenster steht
`Anmeldeseite: kein Logo · Zeichen ja · Name „Muster Hosting"` mit dem Urteil
„kommt an".

**Und der Name für einen Vorleser**, gelesen aus dem Baum, den Chromium
daraus macht: mit Logo die Überschrift „Muster Hosting" mit dem Bild
„Muster Hosting" darin, ohne Logo die Überschrift „Muster Hosting". Die
Gegenprobe auf derselben Seite setzt das alte Markup des Zeichens
(`role="img"`, `aria-label="SrvPanel"`) im Browser; dann heisst dieselbe
Überschrift „SrvPanel Muster Hosting".
