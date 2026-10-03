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

**Gefahren am 3. Oktober 2026 gegen `0.9.0-rc.11`** (§7): Block 1 und **alle
neun Punkte erfüllt**, Punkt 7 im zweiten Anlauf. Zwei Befunde am Prüfling
kamen aus dem Lauf und ein dritter beim Bauen des zweiten; gebaut sind sie für
`0.9.0-rc.12` (§6b, §6c), dazu ein Wunsch des Betreibers (§6d). Drei Befunde
an der Vorschrift sind beim Fahren berichtigt, in §3 Punkt 3 und Punkt 7, und
Punkt 8 ist ergänzt. Es stehen aus: der Nachlauf gegen `rc.12` und die
Abnahme.

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

Vier Dateien, verschickt mit diesem Lauf und seit dem 3. Oktober auch im Repo
unter `tests/pruefkoerper/b6/`. Sie liegen auf dem Rechner, an dem der Browser
läuft. GitHub liefert die drei Bilder mit dem Typ aus ihrer Endung aus, das
getarnte SVG dagegen als `text/plain`, alle mit `nosniff` und einer Sandbox
(gemessen am 3. Oktober an den Adressen aus dem Repo). Ein Browser zeigt vom
getarnten SVG also seinen Quelltext, und ausgeführt wird nichts. Unter seinem
Namen lässt es sich aus dieser Ansicht nicht sicher speichern; deshalb liegt
daneben `b6-svg-als-png.zip`, das nur diese eine Datei enthält.

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
"${C[@]}" "$U/login" | grep -o -E '<title inertia>[^<]*</title>|<style>:root[^<]*</style>|"logo":(null|"[^"]*")'
"${C[@]}" -D - -o /tmp/b6-logo "$U/branding/logo" | grep -i -E '^HTTP|^content-type|^x-content-type|^cache-control' | tr -d '\r'
sha256sum /tmp/b6-logo; rm -f /tmp/b6-logo
```

**Berichtigt am 3. Oktober, vor Punkt 8** (§7). Das Muster für die Adresse
des Logos lautete `"logo":"[^"]*"` und trifft nur eine Zeichenkette. Ohne
Logo steht in den Daten der Seite `"logo":null`, und der Block druckte dazu
keine Zeile. Gerade das erwartet Punkt 8. Im Container gegengeprüft: Mit dem
alten Muster steht ohne Logo nur der Titel da, mit dem neuen zusätzlich
`"logo":null`.

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

**Berichtigt am 3. Oktober, beim Fahren** (§7). **So wie oben beschrieben,
misst der Punkt nichts mehr, wenn Punkt 4 länger als fünf Minuten zurückliegt.**
Logo A liegt im privaten Fenster dann abgelaufen im Zwischenspeicher, und ein
Neuladen holt es neu. Das täte es auch unter der festen Adresse von `rc.10`,
und der Punkt wäre in beiden Fassungen grün. Gefahren wird deshalb in dieser
Reihenfolge, alle Schritte innerhalb von fünf Minuten:

1. Im **privaten Fenster** die Anmeldeseite **ohne** Zwischenspeicher neu
   laden (Strg+F5, auf dem Mac ⇧⌘R) und das Snippet einfügen. Erwartet:
   Logo A, `rgb(110,231,183)`. Danach liegt Logo A frisch im Zwischenspeicher.
   „Cache deaktivieren" in den DevTools bleibt aus.
2. Im **normalen Fenster** `b6-logo-b.jpg` wählen und „Marke speichern".
3. Im privaten Fenster gewöhnlich neu laden (F5, auf dem Mac ⌘R) und das
   Snippet einfügen. Erwartet: Logo B, `rgb(192,32,32)`, unter einer neuen
   Fassung hinter `?v=`.
4. Im privaten Fenster die **Gegenprobe** einfügen. Sie fragt die alte
   Adresse von Logo A dreimal: im Zwischenspeicher, wie beim Neuladen und vom
   Server. Erwartet: `Urteil: unter der alten Adresse käme beim Neuladen noch
   Logo A, der Server hat Logo B — Logo B zeigt die Seite, weil die Adresse neu
   ist.` Das ist das Verhalten von `rc.10`, gemessen an derselben Seite.
5. Im normalen Fenster nach dem vollständigen Neuladen das Snippet einfügen.
   Erwartet: Logo B in der Leiste.

```js
// B6 · docs/140 Punkt 7, Gegenprobe: die alte Adresse von Logo A — im Zwischenspeicher, wie beim Neuladen, vom Server.
// Im privaten Fenster nach dem Neuladen und dem Snippet einfügen. Sie verändert den Zwischenspeicher nicht.
(async () => {
  const alt = location.origin + '/branding/logo?v=904eae8051dd7024'
  const A = 'rgb(110,231,183)'
  const B = 'rgb(192,32,32)'
  const lesen = async (wie, init) => {
    try {
      const r = await fetch(alt, init)
      const blob = await r.blob()
      const bild = await createImageBitmap(blob)
      const c = document.createElement('canvas'); c.width = bild.width; c.height = bild.height
      const g = c.getContext('2d'); g.drawImage(bild, 0, 0)
      const punkt = `rgb(${[...g.getImageData(40, 48, 1, 1).data].slice(0, 3).join(',')})`
      const datum = r.headers.get('date')
      const alter = datum ? Math.round((Date.now() - Date.parse(datum)) / 1000) : null
      return { wie, punkt, typ: blob.type, bytes: blob.size, alter, regel: r.headers.get('cache-control') }
    } catch (e) {
      return { wie, fehler: e.name }
    }
  }
  const zeile = (x) => x.fehler
    ? `  ${x.wie.padEnd(20)} ${x.fehler}`
    : `  ${x.wie.padEnd(20)} Punkt(40,48) ${x.punkt} · ${x.typ} ${x.bytes} B · Alter ${x.alter ?? '?'} s · ${x.regel ?? '—'}`

  // Erst lesen, was liegt — ohne Netz. Nur ein frischer Eintrag wird danach „wie beim Neuladen" gefragt:
  // Ein abgelaufener würde dabei neu geholt und unter der alten Adresse durch Logo B ersetzt.
  const gespeichert = await lesen('im Zwischenspeicher', { cache: 'only-if-cached', mode: 'same-origin' })
  const frisch = !gespeichert.fehler && gespeichert.alter !== null && gespeichert.alter < 300
  const neuladen = frisch
    ? await lesen('wie beim Neuladen', { cache: 'default' })
    : { wie: 'wie beim Neuladen', fehler: 'nicht gefragt — der Eintrag ist nicht frisch' }
  const server = await lesen('vom Server', { cache: 'no-store' })

  let urteil
  if (gespeichert.fehler) urteil = 'unter der alten Adresse liegt nichts im Zwischenspeicher — „Cache deaktivieren" an? So misst die Gegenprobe nichts.'
  else if (!frisch) urteil = `der Eintrag ist ${gespeichert.alter} s alt und damit abgelaufen — die Gegenprobe trennt nicht.`
  else if (gespeichert.punkt !== A) urteil = 'unter der alten Adresse liegt nicht Logo A — die Gegenprobe trennt nicht.'
  else if (neuladen.punkt === A && server.punkt === B) urteil = 'unter der alten Adresse käme beim Neuladen noch Logo A, der Server hat Logo B — Logo B zeigt die Seite, weil die Adresse neu ist.'
  else urteil = 'unerwartet — die Zeilen bitte so schicken.'
  console.log([`Gegenprobe Punkt 7 · ${new Date().toLocaleTimeString('de-DE')} · ${alt}`, zeile(gespeichert), zeile(neuladen), zeile(server), `Urteil: ${urteil}`].join('\n'))
})()
```

**„Wie beim Neuladen" fragt sie nur einen frischen Eintrag.** Der kommt dann
aus dem Zwischenspeicher, und an ihm ändert sich nichts. Ein abgelaufener
Eintrag würde dabei neu geholt, und unter der alten Adresse läge danach Logo
B. Die erste Fassung der Gegenprobe tat genau das (§7). Im Container
gegengeprüft, je Fall allein am Server:

| Lage | im Zwischenspeicher | wie beim Neuladen | vom Server | Urteil |
|---|---|---|---|---|
| frisch | A, 1 s | A | B | „weil die Adresse neu ist" |
| „Cache deaktivieren" an | nichts (`TypeError`) | nicht gefragt | B | „so misst die Gegenprobe nichts" |
| abgelaufen | A, 312 s | nicht gefragt | B | „abgelaufen — trennt nicht" |
| alte Adresse mit Logo B neu geholt | B | B | B | „nicht Logo A — trennt nicht" |

Im abgelaufenen Fall bleibt der Zwischenspeicher unberührt: Ein zweiter
Aufruf nach dem Neuladen zeigt unverändert A, 312 s.

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

**Ergänzt am 3. Oktober, vor Punkt 8** (§7). Drei Dinge, gemessen im
Container am Knopf der Seite oder gelesen am Controller:

- **„Logo entfernen" schickt das ganze Formular**, mit `remove_logo` und ohne
  Rückfrage. Die Meldung danach ist dieselbe wie nach dem Speichern, sie
  allein belegt also nichts. Den Beleg gibt Block 2.
- **Unter „Bilddatei" bleibt nichts gewählt.** Der Controller entfernt erst
  das Logo und legt danach eine gewählte Datei ab; mit einer gewählten Datei
  stünde danach wieder ein Logo da.
- **Auf den Bildern des Laufs lag der Knopf unterhalb des Ausschnitts.**
  Gelesen wird er deshalb mit einer Zeile in der Konsole, auf
  `/settings/general` nach dem Neuladen. Erwartet:
  `Knopf „Logo entfernen": fort · Feld „Bilddatei": da · has_logo: false`.

```js
(() => { const p = document.getElementById('app').__vue_app__.config.globalProperties.$page; const knopf = [...document.querySelectorAll('button')].some((b) => b.textContent.trim() === 'Logo entfernen'); console.log(`${p.url} · Knopf „Logo entfernen": ${knopf ? 'da' : 'fort'} · Feld „Bilddatei": ${document.querySelector('input[type=file]') ? 'da' : 'fehlt'} · has_logo: ${p.props.brandSettings?.has_logo}`) })()
```

Im Container in beiden Zuständen gemessen: mit Logo `da · has_logo: true`,
nach einem Klick auf den echten Knopf `fort · has_logo: false`.

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

---

## §6b · Ein Befund aus dem Lauf: zwei Formulare ohne Abstand

Gefunden am 3. Oktober 2026 bei Punkt 1, auf dem Telefon, gemeldet vom
Betreiber: Auf `/settings/general` steht der Knopf „Speichern" des ersten
Formulars unmittelbar an der Überschrift „Name und Fusszeile" des zweiten.
Das Kriterium fragt nicht danach, die Seite gehört trotzdem zu B6: Das zweite
Formular steht dort seit B6.

**Gemessen im Chromium**, gegen den gebauten Stand im Container und mit der
Wegwerf-Datenbank aus §6a, je Lage frisch geladen:

| | 390 px | 1440 px |
|---|---|---|
| Fuge zwischen den beiden Formularen, `0.9.0-rc.11` | 0 px | 0 px |
| dieselbe Fuge mit der Behebung | 26 px | 30 px |
| zum Vergleich: `gap` von `.form` zwischen zwei Bereichen | 26 px | 30 px |

Der Abstand fehlt also auch bei 1440 px. Auf dem Telefon fällt er auf, weil
der Knopf dort die ganze Breite nimmt.

**Die Ursache steht in `app.css`, über `.sections > .form`.** Zwei Formulare
als Geschwister unter `main` stehen auf 0 px: Den Abstand zwischen Bereichen
gibt der jeweilige Behälter an seine Kinder, und zwei Formulare sind
niemandes Kinder. Die Antwort steht in derselben Regel, eine Hülle `.sections`
um beide. Die Seiten der Konten und des Zugangs tragen sie, die Seite
„Allgemein" bekam ihr zweites Formular ohne sie.

**Und der Wächter dafür hat geschwiegen.** `BlockSpacingTest` findet genau
diese Fuge, `form + form`. In seiner Liste offener Fugen stand sie aber
schon, für die Datenbankseite, und ein Eintrag galt jeder Vorlage, in der das
Paar vorkommt. Gegen die alte Seite gefahren ist der alte Wächter grün und
der neue rot.

> **Eine Ausnahme, die für ein Paar gilt, gilt an jeder Stelle, an der das
> Paar vorkommt — auch an der nächsten, die niemand angesehen hat.**

**Gebaut für `0.9.0-rc.12`:**

- Die Seite fasst beide Formulare in `.sections` ein. Eingerückt ist dabei der
  ganze Inhalt der Hülle; ohne Leerzeichen gelesen sind es 14 neue Zeilen.
- `BlockSpacingTest` bindet jede offene Fuge an die Vorlagen, in denen sie
  steht, 22 Paare an 52 Stellen. Eine neue Stelle ist rot, auch wenn das Paar
  anderswo schon eingetragen ist, und eine genannte Stelle ohne die Fuge
  ebenfalls.
- Drei neue Eingriffe im Bruchskript: die Hülle ohne Klasse, eine Vorlage zu
  viel in einem Eintrag, ein Eintrag ohne Vorlage. Gefahren mit allen
  Eingriffen, die eine der beiden Dateien anfassen oder `BlockSpacingTest`
  zum Ziel haben: 15 von 15 beissen. Der Eingriff von `NtpVerdictTest` an
  dieser Seite brauchte zwei Leerzeichen mehr in seinem Anker.

Die Datenbankseite trägt `form + form` weiter, und dort bleibt es eine
gezählte offene Fuge. Ob sie zu eng steht, entscheidet ein Blick und keine
Regel.

**Nachgemessen** in vier Lagen, 390 und 1440 px in beiden Themen:
`dokument=0`, Gegenprobe 200, die Fuge wie in der Tabelle. Eine Zeile meldet
`schiebt=1`. Das ist das Feld der Fusszeile, dessen Text in der
Wegwerf-Datenbank länger ist als das Feld; vor der Behebung steht dieselbe
Zeile mit denselben 279 und 59 px da.

**Für den Lauf heisst das:** Er geht gegen `0.9.0-rc.11` weiter, denn kein
Punkt hängt an dieser Fuge. Gesehen wird die Behebung danach gegen
`0.9.0-rc.12`, mit einem Bild von `/settings/general` bei 390 px an der
Stelle zwischen den beiden Formularen.

## §6c · Schrift und Zeichen in der Farbe des Betreibers — und eine Prüfung, die die Tönungen rechnet

Gefunden am 3. Oktober 2026 bei Punkt 4a, am Bild der Anmeldeseite mit
Marke: **„Angemeldet bleiben" steht in Pfirsich neben dem mintgrünen Knopf.**
Im Container nachgemessen sind es mehr Stellen. Mit Logo ist es nur dieses
Wort, ohne Logo zusätzlich der Name „Muster Hosting" in Pfirsich und das
Zeichen, dessen Balken Pfirsich und dessen oberer Balken Pink tragen. In der
Leiste trägt der obere Balken des Zeichens ohne Logo weiter Pink. Der
Markenblock setzte auf diesen Flächen nur die vier Akzentmarken, und
`--text-strong` und `--mark-accent` blieben in den Farben der Auslieferung.

**Entscheidung 1, vom Betreiber am selben Tag:** Schrift und Zeichen gehen
auf den Markenflächen mit. Die Anmeldeseite bekommt `--text-strong` und
`--mark-accent` im dunklen Akzent, Leiste und Kopfleiste `--mark-accent`. An
der Wurzel bleibt `--mark-accent`, wie es ist. Dort färbt es nur die Auswahl
und die Suchtreffer im Datei-Editor, und über dieser Fläche steht Text.

### Was die Messung beim Bauen gezeigt hat

Gemessen im Chromium gegen den gebauten Stand, mit der Wegwerf-Datenbank aus
§6a. Als Akzent diente der dunkelste, den die Prüfung damals annahm,
`#02925b`. Gefunden ist er über die Prüfung selbst, und seine Leuchtdichte
trifft die Schwelle der Formel auf sechs Stellen (0,213260). Gemessen wurden
die Anmeldeseite, die Seite des zweiten Faktors, eine Ankündigung, Leiste und
Kopfleiste, je Element die Farbe gegen ihren wirksamen Grund, in Ruhe, beim
Überfahren und mit Fokusrahmen.

| | Block bisher | mit Schrift und Zeichen |
|---|---|---|
| Stellen in einer Farbe der Auslieferung | 82 | 0 |
| Überschrift der Fehlermeldung, auf `#331a2e` | Pfirsich, 9,46:1 | **3,96:1** |
| aktiver Menüpunkt der Leiste, auf `#171e34` | **4,14:1** | **4,14:1** |
| Ziffern im Feld des zweiten Faktors, 34 px, auf `#2a1745` | Pfirsich, 9,62:1 | 4,03:1 |

Die Überschrift einer Fehlermeldung liest `--text-strong` und steht auf der
Tönung der Meldung. Der aktive Menüpunkt trägt den Akzent auf seiner eigenen
Tönung, und das schon seit `rc.11`. Die Ziffern sind grosse Schrift, für die
`docs/20 §7.2` 3:1 verlangt; sie tragen.

**In meinem Vorschlag stand, auf der Anmeldeseite brauche nur das Auge
„Passwort zeigen" beim Überfahren 3:1.** Das war unvollständig. Die Prüfung
beim Speichern rechnete den Akzent nur gegen die drei Flächen, und als Schrift
steht er auch auf getönten: auf seiner eigenen Tönung (aktiver Menüpunkt,
aktive Knöpfe, Zählmarken), als Verweis im Band eines Vorgangs und seit
Entscheidung 1 in der Überschrift einer Fehlermeldung. Gerechnet fiel der
Akzent auf seiner eigenen Tönung im ganzen Panel auf 3,81:1 (dunkel) und
3,86:1 (hell).

> **Eine Farbe, die auf jeder Fläche lesbar ist, ist es auf der Tönung
> darüber noch lange nicht — und die Tönung ist die Stelle, an der etwas
> hervorgehoben werden soll.**

**Entscheidung 2, vom Betreiber am selben Tag: Die Prüfung rechnet die
getönten Flächen mit, und zwar alle.** Zur Wahl standen zwei Fassungen,
gerechnet über alle Töne, die die alte Prüfung annahm, in Schritten von 3:

| | abgewiesen, hell | abgewiesen, dunkel |
|---|---|---|
| jede Tönung des Stylesheets über jedem Grund ihrer Fläche | 19,9 % | 22,9 % |
| nur die Orte, an denen heute Schrift im Akzent steht | 16,3 % | 15,9 % |

Gewählt ist die erste. Ein Verweis, der morgen in eine Erfolgsmeldung kommt,
ist damit schon gerechnet und braucht keinen Wächter, der ihn findet. Die
Zahlen, die ich dem Betreiber zuerst genannt hatte (rund 17 % dunkel, 16 %
hell), rechneten die eigene Tönung und die Meldungen der Anmeldeseite, aber
nicht die Tönungen der Meldungen und Bänder im Panel. Berichtigt sind sie vor
dem Bauen und nicht danach.

> **Eine Zahl, auf der eine Entscheidung beruht, wird berichtigt, bevor
> gebaut wird — sonst hat jemand etwas entschieden, das es nicht gibt.**

### Gebaut für `0.9.0-rc.12`

- `Contrast::over()` mischt eine Tönung so, wie der Browser es tut: Kanal für
  Kanal im sRGB-Raum. Gemessen: `rgb(2 146 91 / 0.14)` über `#1a0b2e` zeichnet
  Chromium als `#171e34`, und die Methode rechnet dasselbe.
- `BrandSettings::verdictLight()` und `verdictDark()` rechnen jeden Grund, auf
  dem der Akzent Schrift tragen kann. Für hell sind das 12: die beiden
  Flächen, die eigene Tönung über jeder und die vier Zustandstönungen über
  jeder. Für dunkel sind es 19: die drei Flächen, die eigene Tönung über
  ihnen und über dem Grund der Anmeldeseite, die vier Zustandstönungen über
  den beiden Gründen des Themas und die zwei Zustände der Anmeldeseite über
  ihren beiden Gründen. Farben und Deckungen stehen in `BrandSettings`, und
  `BrandContrastTest` hält sie in beide Richtungen gegen `app.css`.
- Die Meldung beim Speichern und der Hinweis neben dem Feld nennen bei einer
  Tönung den Ort, denn ihr Hexwert steht in keinem Stylesheet.
- Die Wächter: `BrandStyleTest` sucht Reste nach dem Wert und nicht nach dem
  Namen, mit der Ausnahme an der Wurzel samt Grund und in beide Richtungen.
  `BrandContrastTest` hält die Tönungen gegen `app.css`, weist eine Farbe ab,
  die nur auf einer Tönung durchfällt, und rechnet die Gründe der
  Anmeldeseite gegen den dunkelsten Akzent, den die Prüfung annimmt. Dass
  diese Rechnung die Prüfung selbst ist, hält eine Gegenprobe an jedem der
  256 Grautöne. `BrandReachTest` hält Meldung und Hinweis durch die Tür.
- Im Bruchskript 14 neue Eingriffe und 4 nachgezogene Anker. Gefahren mit
  allen Eingriffen der berührten Dateien: 40 von 40 beissen, dazu einer mit
  zwei Blöcken von Hand.

### Nachgemessen

Mit dem dunkelsten Akzent, den die neue Prüfung annimmt: `#869c36`, gesucht
in Schritten von 2 über Rot und Blau, Leuchtdichte 0,2911. Dieselben Seiten
und Zustände wie oben.

- Stellen in einer Farbe der Auslieferung: **0**.
- Stellen in der Farbe des Betreibers unter ihrer Forderung: **0**. Am
  knappsten sind der aktive Menüpunkt mit 5,11:1, die Überschrift der
  Fehlermeldung mit 5,13:1 und die Ziffern des Codes mit 5,22:1.
- Der Hinweis neben dem hellen Feld:
  `Gemessen 5,13:1 auf #ede8e0 (der Tönung einer Warnung) — verlangt sind 4,5:1.`
- Abgewiesen wird `#02925b`, den die alte Prüfung annahm:
  `Diese Farbe erreicht auf #1d302f (der Tönung einer Erfolgsmeldung) nur 3,47:1. Der Akzent trägt auch Schrift; verlangt sind 4,5:1.`

### Für den Lauf heisst das

Er geht gegen `0.9.0-rc.11` weiter. Gegen `0.9.0-rc.12` ändern sich vier
Erwartungen, und sie gehören in den Nachlauf:

| Stelle | `rc.11` | `rc.12` |
|---|---|---|
| Punkt 1, Meldung für `#2f8f5b` | `auf #fafafb nur 3,87:1` | `auf #ede8e0 (der Tönung einer Warnung) nur 3,31:1` |
| Punkt 3, Hinweis hell | `Gemessen 6:1 auf #fafafb` | `Gemessen 5,13:1 auf #ede8e0 (der Tönung einer Warnung)` |
| Punkt 3, Hinweis dunkel | `Gemessen 11,77:1 auf #14171d` | `Gemessen 8,59:1 auf #213433 (ihrer eigenen Tönung, wie in einem aktiven Knopf)` |
| Snippet, Markenblock | 4 Regeln, 16 Zuweisungen | 4 Regeln, 19 Zuweisungen |

Dazu am Bild: „Angemeldet bleiben", Name und Zeichen auf der Anmeldeseite in
Mint, und in der Leiste ohne Logo der obere Balken des Zeichens ebenfalls.
Die Prüffarben des Laufs bleiben gültig, `#0b6e4f` mit 5,13:1 und `#6ee7b7`
mit 8,59:1.

## §6d · Ein Wunsch nach dem Lauf: Ohne Eintrag gilt die Vorgabe

Geäussert vom Betreiber am 3. Oktober 2026, nach Punkt 9:

> „Die ursprünglichen Werte, auch unter Punkt 9 Schritt 1 gelistet, sollten
> immer der Default sein. Wenn die geänderten Werte entfernt werden und die
> Input Felder leer bleiben, muss immer der Default geladen werden."

**Bis dahin ging das nicht.** Name und beide Akzente trugen `required`, im
Formular und in der Prüfung der Tür. Ein leeres Feld schickte der Browser gar
nicht erst ab; in Punkt 9 wurden die Vorgaben deshalb von Hand eingetippt.
Fusszeile und Logo gingen schon zurück: Die Fusszeile ist ohne Eintrag leer,
und für das Logo gibt es „Logo entfernen".

**Entschieden vom Betreiber am selben Tag, aus zwei Fassungen:**

| | gewählt: das Feld bleibt leer | verworfen: die Vorgabe wird eingetragen |
|---|---|---|
| im Feld nach dem Speichern | nichts, die Vorgabe grau als Platzhalter | die Vorgabe als Wert |
| in der Ablage | keine Angabe, `null` | eine Abschrift der Vorgabe |
| eine spätere Fassung ändert die Vorgabe | das Panel folgt ihr | das Panel bleibt bei der alten |

Eine eingetippte Vorgabe zählt ebenso als keine Angabe, auch in
Grossbuchstaben.

> **Ein Wert, der der Vorgabe gleicht, ist keine eigene Angabe — wer ihn als
> Wert ablegt, hält eine zweite Fassung der Vorgabe, und die veraltet.**

Dieselbe Regel steht seit B6 in `Style::css()` für den Markenblock: Bei den
Vorgabewerten gibt es keinen. Hier gilt sie eine Ebene tiefer, in der Ablage.

### Gebaut für `0.9.0-rc.12`

- `BrandSettings::fromForm()` macht aus dem Formular die Marke, die gilt: Ein
  leeres Feld wird die Vorgabe, eine Farbe wird kleingeschrieben. `own()` sagt,
  was davon eigene Angabe ist, und ein Wert, der der Vorgabe gleicht, ist es
  nicht. `toStored()` legt nur die eigenen Angaben ab und für die Vorgabe
  `null`; `Settings::saveBrand()` speichert genau das. `toArray()` bleibt, was
  gilt, und das druckt Block 1.
- Die Tür nimmt ein leeres Feld an, `nullable` statt `required`, und rechnet
  die Farbe, die gelten wird.
- Die Seite bekommt die eigenen Angaben, leer für die Vorgabe, und daneben die
  Vorgaben selbst. Stünde im Feld der Wert, der gilt, schickte das nächste
  Speichern ihn als eigene Angabe zurück, etwa wenn nur ein Logo dazukommt.
- Die drei Felder tragen kein `required` mehr und zeigen die Vorgabe als
  Platzhalter. Der Hinweis unter einer Farbe beginnt mit „Vorgabe, gemessen …",
  wenn keine eigene Angabe gespeichert ist, und endet sonst mit „Ohne Eintrag
  gilt die Vorgabe.". Unter dem Namen steht „Ohne Eintrag gilt „SrvPanel"."
- Die Wächter: `BrandReachTest` misst durch die Tür, dass ein leeres Feld die
  Vorgabe wird, bis in die Ablage und bis auf die Anmeldeseite, dass eine
  eingetippte Vorgabe in Grossbuchstaben keine eigene Angabe ist, und was das
  Formular bekommt. Gegen den Code von vorher sind alle drei Fälle rot.
  `BrandFormTest` hält die Vorlage: kein `required` an den drei Feldern, auch
  kein gebundenes, und einen Platzhalter aus den Vorgaben des Servers. Gegen
  die Vorlage von vorher sind zwei seiner drei Fälle rot; der dritte ist seine
  Untergrenze.
- Im Bruchskript 13 neue Eingriffe und ein nachgezogener Anker. Gefahren mit
  allen Eingriffen an den fünf berührten Dateien: 38 von 38 beissen, dazu
  einer mit zwei Blöcken von Hand.

### Nachgemessen

Im Chromium gegen den gebauten Stand, angemeldet mit dem Wegwerfkonto aus §6a.
Vorher stand eine eigene Marke da (`Muster Hosting`, `#0b6e4f`, `#6ee7b7`);
dann sind die drei Felder geleert und gespeichert worden.

| | vorher | nach dem Speichern |
|---|---|---|
| Feld Name | `Muster Hosting`, Platzhalter `SrvPanel` | leer, Platzhalter `SrvPanel` |
| Feld hell | `#0b6e4f`, Platzhalter `#3730a3` | leer, Platzhalter `#3730a3` |
| Feld dunkel | `#6ee7b7`, Platzhalter `#ff7fec` | leer, Platzhalter `#ff7fec` |
| Hinweis hell | `Gemessen 5,13:1 auf #ede8e0 (der Tönung einer Warnung)` … `Ohne Eintrag gilt die Vorgabe.` | `Vorgabe, gemessen 8,15:1 auf #ede8e0 (der Tönung einer Warnung)` … |
| Hinweis dunkel | `Gemessen 8,59:1 auf #213433 (ihrer eigenen Tönung, wie in einem aktiven Knopf)` … | `Vorgabe, gemessen 6,27:1 auf #1d302f (der Tönung einer Erfolgsmeldung)` … |
| Reiter | `Allgemein · Muster Hosting` | `Allgemein · SrvPanel` |
| Markenblock im Kopf | ja | nein |
| Ablage | `"name":"Muster Hosting"` … | `"name":null,"accent_light":null,"accent_dark":null` |

Danach steht „Die Marke ist gespeichert." da, und die Fusszeile bleibt, wie sie
war. Bei 390 px ist `dokument=0`.

**Was offen bleibt.** Was vor `rc.12` gespeichert wurde, trägt die Vorgabe als
Wert, auf `cloudsrv24` seit Punkt 9. Das Formular zeigt solche Felder trotzdem
leer, denn gefragt wird, ob der Wert der Vorgabe gleicht. In der Ablage wird
daraus beim nächsten Speichern keine Angabe. Ändert eine spätere Fassung die
Vorgabe vorher, braucht sie eine Migration, die die alte Vorgabe kennt; bis
dahin lässt sich eine solche Zeile von einer eigenen Angabe nicht
unterscheiden.

### Für den Nachlauf

- Punkt 9 geht gegen `rc.12` ohne Abschreiben: Name, beide Farben und die
  Fusszeile leeren, speichern. Block 1 druckt danach dieselbe Marke wie vor
  dem Lauf, denn `toArray()` gibt aus, was gilt.
- Was abgelegt ist, zeigt eine Zeile mehr:

  ```bash
  srvpanel tinker --execute='echo json_encode(App\Models\Setting::query()->where("key", "brand")->first()?->value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), PHP_EOL;'
  ```

  Erwartet nach dem Leeren:
  `{"name":null,"accent_light":null,"accent_dark":null,"footer":"","logo":null}`.
  Vor dem ersten Speichern unter `rc.12` steht dort noch die Abschrift aus
  Punkt 9, und die Felder zeigen trotzdem leer.
- Die Hinweise mit der Vorgabe: hell
  `Vorgabe, gemessen 8,15:1 auf #ede8e0 (der Tönung einer Warnung)`, dunkel
  `Vorgabe, gemessen 6,27:1 auf #1d302f (der Tönung einer Erfolgsmeldung)`.

## §7 · Protokoll

Gefahren am 3. Oktober 2026 auf `cloudsrv24` gegen `0.9.0-rc.11`, vom Morgen
bis 20:12 Uhr. Punkt 1 lief auf dem Telefon, alle weiteren am Mac in Chrome:
die Anmeldeseite im privaten Fenster, das Panel im normalen. Die Prüfkörper
kamen aus dem Repo (`tests/pruefkoerper/b6/`, Stand `7910516d`). Was hier
steht, ist von den Bildern und Ausgaben des Betreibers abgelesen, und die
Uhrzeiten sind die der Anzeigezone, CEST.

**Block 1 und alle neun Punkte sind erfüllt**, die Punkte 3, 4 und 6
darunter, und keiner ist als „nicht herstellbar" ausgefallen. Punkt 7 ist es
im zweiten Anlauf, mit berichtigter Reihenfolge und berichtigter Gegenprobe
(§3 Punkt 7). Kein Befund aus §0 stand noch da: Die sechs Behebungen von
`rc.11` sind auf dem Server angekommen.

**Acht Befunde.** Drei stecken im Prüfling, zwei kamen aus dem Lauf und einer
beim Bauen des zweiten, und alle drei sind für `0.9.0-rc.12` gebaut (§6b,
§6c). Drei stecken in der Vorschrift und sind beim Fahren berichtigt, zwei in
meinem Prüfstand im Container. Nach Punkt 9 kam ein Wunsch des Betreibers
dazu, ebenfalls gebaut (§6d).

### Block 1 vor dem Lauf

```
0.9.0-rc.11
{"name":"SrvPanel","accent_light":"#3730a3","accent_dark":"#ff7fec","footer":"","logo":null}
Absender: SrvPanel <panel@cloudsrv24.de>
ls: cannot access '/var/lib/srvpanel/storage/app/branding/': No such file or directory
Panel: https://cloudsrv24.de:8443
<title inertia>SrvPanel</title>
Logo-Route: 404
```

Erfüllt, so wie §1 es für einen Server erwartet, auf dem nie eine Marke
gesetzt war: keine `<style>`-Zeile und kein Verzeichnis `branding/`. Die
Absenderadresse ist eingetragen, und damit steht die Vorbedingung für
Punkt 6.

### Punkt 1 — erfüllt, um 09:53 auf dem Telefon

Oben stand die Zusammenfassung, Wort für Wort wie erwartet:

> Das Formular wurde nicht gespeichert.
> Diese Farbe erreicht auf #fafafb nur 3,87:1. Der Akzent trägt auch Schrift;
> verlangt sind 4,5:1.

Der Hinweis unter dem Feld blieb bei „Gemessen 9,52:1 auf #fafafb", also bei
der Vorgabe. Block 1 lief erst nach Punkt 2 noch einmal und gilt für beide.

- Ein Bild zeigt im Feld `#3730a3` statt `#2f8f5b`. Das ist kein Befund: Nach
  einer Abweisung bleibt der eingetippte Wert im Feld stehen, im Container
  gemessen. Das Bild ist vor dem Speichern oder nach einem Neuladen
  entstanden.
- **Am Bild hat der Betreiber einen Befund gemeldet**: „Speichern" klebt an
  „Name und Fusszeile". Dieselbe Fuge stand danach auf jedem Bild der Seite,
  auch bei 1440 px am Mac. Gemessen sind es 0 px bei beiden Breiten (§6b).

### Punkt 2 — erfüllt, um 15:03, und Block 1 danach

Beide Meldungen standen Wort für Wort da:

> Nur PNG, JPEG und WebP — diese Datei ist image/svg+xml. SVG ist
> ausgeschlossen: Es darf Skript enthalten, und das Logo steht auf der
> Anmeldeseite.

> Das Bild ist 470 KB gross; erlaubt sind 256 KB.

Die erste Datei heisst `.png`, und der Typ kam trotzdem aus ihrem Inhalt.
**Block 1 danach war Zeile für Zeile derselbe wie vor dem Lauf**, und
`branding/` gab es weiterhin nicht. Abgewiesen heisst also auch: nichts
abgelegt, in Punkt 1 wie in Punkt 2.

### Punkt 3 — erfüllt, gespeichert um 15:32

**Auf der Seite** stand „Die Marke ist gespeichert.", und sie hatte ganz neu
geladen: Die Knöpfe waren ohne F5 mintgrün, das Dateifeld war leer, in der
Leiste stand das Logo, und unter dem hellen Akzent stand „Gemessen 6:1 auf
#fafafb".

**Block 2** trug beim Fahren eine Zeile mehr. Sie vergleicht die
`<style>`-Zeile Zeichen für Zeichen mit dem Wortlaut aus §6a und druckt
„Markenblock wie §6a: ja" oder „NEIN". Unter `rc.12` trägt der Block drei
Zuweisungen mehr (§6c); der Vergleich gilt also nur für `rc.11`.

- Die Marke:
  `{"name":"Muster Hosting","accent_light":"#0b6e4f","accent_dark":"#6ee7b7","footer":"Betrieben von der Muster Hosting GmbH · Musterweg 1 · 12345 Musterstadt","logo":"logo.png"}`.
- `logo.png` mit 681 Bytes, Eigentümer und Gruppe `srvpanel`, das
  Verzeichnis `drwxr-x---`, beide mit der Zeit 15:32.
- **Beide Prüfsummen `9dd9c55e…975d7`**, die der abgelegten und die der
  ausgelieferten Datei.
- `<title inertia>Muster Hosting</title>` und „Markenblock wie §6a: ja".
- `"logo":"https:\/\/cloudsrv24.de:8443\/branding\/logo?v=904eae8051dd7024"`.
  Die Fassung hinter `?v=` ist genau die, die vorher aus Logo A gerechnet war.
- `HTTP/2 200`, `content-type: image/png` und
  `cache-control: max-age=300, public`, dazu
  `x-content-type-options: nosniff` zweimal (Beobachtung 1).

### Punkt 4 — erfüllt, die Anmeldeseite gegen 15:40

**4a, das Snippet** im privaten Fenster bei 1440 px, jede Zeile wie erwartet:

- `Titel im Reiter: Anmeldung · Muster Hosting`.
- `Markenblock: 4 Regeln, 16 Zuweisungen, davon keine Marke: 0`, mit den
  Selektoren aus §6a.
- `Anmeldeseite: Logo https://cloudsrv24.de:8443/branding/logo?v=904eae8051dd7024 · alt „Muster Hosting" · Bild 360×96 · Kasten 128×34 · Punkt(40,48) rgb(110,231,183)`.
- Die Fusszeile über `0.9.0-rc.11` und `Ladebeleg: Grund der Maske #1a0b2e`.
- Hell `Wurzel #0b6e4f`, Hauptknopf und Anmeldeseite in beiden Themen
  `#6ee7b7`. Ohne Markenblock die Vorgaben, `#3730a3`, `#ff7fec` und
  `#ffb7a5`.
- `Urteil: was die Seite über die Marke sagt, kommt an` und
  `Thema der Seite zurück auf: light`. Hell ist die Vorgabe des Servers für
  Seiten ohne Konto.

**4b, die vier Bilder** bei 1440 und 390 px in beiden Themen: Logo A oben in
der Maske, der Knopf in Mint, die Fusszeile darunter, bei 390 px sauber in
zwei Zeilen. Die Anmeldeseite sieht in beiden Themen gleich aus.
`bilderMessen()` bei 390 px meldete hell wie dunkel dasselbe, Zeichen für
Zeichen wie vorab im Container:

```
dokument=0 gegenprobe=200 schiebt=0 rollt=0 versteckt=0
```

**Am Bild von 4a** stand „Angemeldet bleiben" in Pfirsich neben dem mintgrünen
Knopf. Daraus sind die Befunde 2 und 3 geworden (§6c).

### Punkt 5 — erfüllt, das Panel in beiden Themen

**Das Snippet** auf `/settings/general`, angemeldet, jede Zeile wie erwartet:
`Titel im Reiter: Allgemein · Muster Hosting`, in der Leiste Logo A unter
`?v=904eae8051dd7024` im Kasten 90×24 mit `rgb(110,231,183)`, der Ladebeleg
`#1a0b2e`. Hell stehen Wurzel und Hauptknopf auf `#0b6e4f`, dunkel beide auf
`#6ee7b7`, Leiste und aktiver Menüpunkt in beiden Themen auf `#6ee7b7`. Ohne
Markenblock stehen die Vorgaben da, `#3730a3`, `#ff7fec` und in der Leiste
`#ffb7a5`. Das Urteil: „kommt an".

**Die beiden Bilder** bei 1440 px, dunkel und hell, mit dem Bereich „Farbe".
Die Hinweise lauten „Gemessen 6:1 auf #fafafb — verlangt sind 4,5:1. Der
Akzent trägt auch Schrift." und „Gemessen 11,77:1 auf #14171d. Diese Farbe
gilt auch auf der Anmeldeseite — sie trägt in beiden Themes einen dunklen
Grund.". Die Knöpfe stehen hell in Dunkelgrün und dunkel in Mint. Die Leiste
bleibt in beiden Themen dunkel, und „Allgemein" ist darin in Mint markiert.

- Die Fusszeile ist im Feld abgeschnitten, weil ihr Text länger ist als das
  Feld. Kein Befund.
- Die DevTools meldeten „6 issues". Das ist die Ausfüllhilfe von Chrome
  (`docs/76`, `docs/126`), kein Befund.

### Punkt 6 — erfüllt, die Mails um 17:32 und 17:39

**Block 3:**

```
Muster Hosting — Testmail
Muster Hosting — Kontingent überschritten: kunde-web
Muster Hosting — ein neuer Befund auf cloudsrv24.de
```

Unter `rc.10` stand dort dreimal „SrvPanel —".

**Die Quelle der zweiten Mail**, ohne die Zeilen der Zustellung, ohne DKIM und
ohne Message-ID:

```
From: SrvPanel <panel@cloudsrv24.de>
Subject: Muster Hosting =?utf-8?Q?=E2=80=94?= Testmail
MIME-Version: 1.0
Date: Sat, 03 Oct 2026 15:39:54 +0000
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Diese Nachricht best=C3=A4tigt, dass dieses Panel =C3=BCber das eingetragen=
e Relay verschicken kann.

Ausgel=C3=B6st von Administrator am 2026-10-=
03 17:39:54.

Wenn Sie diese Mail erhalten haben, ohne sie erwartet zu =
haben, sehen Sie im
Protokoll des Panels nach: Dort steht, wer sie ausgel=
=C3=B6st hat.
--
Muster Hosting
Betrieben von der Muster Hosting GmbH=
 =C2=B7 Musterweg 1 =C2=B7 12345 Musterstadt =C2=B7 zweite Fassung
```

- `From:` trägt Name und Adresse aus Block 1. Der Name des Absenders bleibt
  der aus `/settings/mail` (§6 Frage 1, Punkt 6).
- Der Betreff ist „Muster Hosting — Testmail"; `=E2=80=94` ist der
  Gedankenstrich.
- Ein einziger Teil, `text/plain; charset=utf-8`, kein HTML.
- Die erste Zeile sagt, dass **dieses Panel** über das Relay verschicken kann.
- Am Ende stehen Name und Fusszeile; `=C2=B7` ist der Mittelpunkt.

**Die Gegenprobe in der Zeit:** Die erste Mail um 17:32 endet auf
„… 12345 Musterstadt". Danach ist die Fusszeile um „ · zweite Fassung"
verlängert worden, und die zweite Mail um 17:39 endet darauf. Die Unterschrift
liest also die gespeicherte Marke. Logo und Farbe stehen in keiner der beiden
Mails (§6 Frage 2). Die Fusszeile behielt den Zusatz bis Punkt 9.

### Punkt 7 — erfüllt im zweiten Anlauf

**Vor dem Fahren war die Vorschrift berichtigt.** Logo A lag im privaten
Fenster seit Punkt 4 im Zwischenspeicher, also seit rund vier Stunden, und
mit `max-age=300` war der Eintrag längst abgelaufen. Ein Neuladen hätte Logo B
auch unter der festen Adresse von `rc.10` geholt, und der Punkt wäre in beiden
Fassungen grün gewesen. Gefahren wurde deshalb in der Reihenfolge, die jetzt
in §3 Punkt 7 steht, dazu eine Gegenprobe. „F5 ohne Strg" ist auf dem Mac ⌘R.

**Der erste Anlauf, 19:40 bis 19:47:**

- Schritt 1, privat: Logo A unter `?v=904eae8051dd7024`, `rgb(110,231,183)`,
  Kasten 128×34, die Fusszeile mit „· zweite Fassung" über `0.9.0-rc.11`,
  Urteil „kommt an".
- Schritt 2, normal: Logo B gespeichert, die Datei trägt 19:44.
- Schritt 3, privat mit ⌘R: Logo B unter `?v=96a55ff9c30b1a1e`,
  `rgb(192,32,32)`, Kasten 128×34, Urteil „kommt an".
- Schritt 5, normal: Logo B in der Leiste, Kasten 90×24, `rgb(192,32,32)`,
  die Farben wie in Punkt 5, Urteil „kommt an".
- Block 2: die Marke mit `"logo":"logo.jpg"`, im Verzeichnis nur noch
  `logo.jpg` mit 5.194 Bytes, beide Prüfsummen `b48f82fb…77cf`, „Markenblock
  wie §6a: ja", `"logo":"…\/branding\/logo?v=96a55ff9c30b1a1e"`, `HTTP/2 200`
  und `content-type: image/jpeg`. `logo.png` ist fort.
- **Schritt 4, die Gegenprobe, sagte „unerwartet".** Unter der alten Adresse
  lag schon Logo B, 5.194 Bytes und 20 s alt; abgeholt war es also um
  19:45:49, nach dem Speichern. Im Bild stand der Code der ersten Fassung, und
  die hatte ich zwei Stunden vorher berichtigt (Befund 5).

Die wahrscheinliche Erklärung: Ein erster Aufruf der ersten Fassung fand den
Eintrag aus Schritt 1 abgelaufen vor, denn Schritt 1 lag mehr als fünf Minuten
zurück. Er fragte ihn „wie beim Neuladen", holte damit das gegenwärtige Logo
und legte es unter die alte Adresse. Das geht, weil die Route unter jeder
Fassung das gegenwärtige Logo ausliefert; `?v=` ist allein für den
Zwischenspeicher da. Belegt ist die Erklärung nicht: Der Verlauf der Konsole
war nach den Neuladungen der Wiederholung leer.

**Die Wiederholung, 19:52 bis 19:55**, mit der berichtigten Gegenprobe. Auf
dem Server lag Logo B, und im privaten Fenster lag Logo B frisch unter der
alten Adresse. Deshalb wurde zuerst Logo A gespeichert und im privaten Fenster
ohne Zwischenspeicher geladen:

| Schritt | Fenster | gemessen |
|---|---|---|
| R1 | normal | Logo A gespeichert um 19:52:55, Logo A in der Leiste |
| R2 | privat, ⇧⌘R | Logo A unter `?v=904eae8051dd7024`, `rgb(110,231,183)`, Urteil „kommt an" |
| R3 | normal | Logo B gespeichert um 19:54:12, Logo B in der Leiste |
| R4 | privat, ⌘R | Logo B unter `?v=96a55ff9c30b1a1e`, `rgb(192,32,32)`, Urteil „kommt an" |
| R5 | privat, Gegenprobe | im Zwischenspeicher `rgb(110,231,183)`, `image/png`, 681 B, 69 s alt · wie beim Neuladen dasselbe · vom Server `rgb(192,32,32)`, `image/jpeg`, 5.194 B |

Das Urteil der Gegenprobe um 19:54:47: „unter der alten Adresse käme beim
Neuladen noch Logo A, der Server hat Logo B — Logo B zeigt die Seite, weil die
Adresse neu ist." Die 69 s führen auf 19:53:38 zurück, zwischen R1 und R3,
also auf R2. **Erfüllt**, und die Gegenprobe zeigt das Verhalten von `rc.10`
an derselben Seite. Block 2 lief nach der Wiederholung nicht noch einmal; R3
hat denselben Stand hergestellt wie Schritt 2.

### Punkt 8 — erfüllt, um 20:00

Im normalen Fenster „Logo entfernen", ohne gewählte Datei. Die Seite meldete
„Die Marke ist gespeichert." und lud ganz neu, und in der Leiste stehen
seitdem Zeichen und Name. Das Snippet druckte
`Leiste: kein Logo · Zeichen ja · Name „Muster Hosting"`, den Markenblock mit
4 Regeln und 16 Zuweisungen und das Urteil „kommt an". Die Zeile in der
Konsole: `Knopf „Logo entfernen": fort · Feld „Bilddatei": da · has_logo: false`.

**Block 2**, mit dem berichtigten Muster:

- `"logo":null` in der Marke und in den Daten der Seite.
- `branding/` leer, und `sha256sum` meldet `No such file or directory`.
- `<title inertia>Muster Hosting</title>`, der Markenblock unverändert.
- Die Route: `HTTP/2 404`, `content-type: text/html; charset=utf-8`,
  `cache-control: no-cache, private` und `nosniff` einmal, nur von nginx. Die
  Prüfsumme danach gilt der Fehlerseite.

**Im privaten Fenster** mit ⌘R:
`Anmeldeseite: kein Logo · Zeichen ja · Name „Muster Hosting"`, die Fusszeile
über `0.9.0-rc.11`, das Urteil „kommt an". Am Bild stehen Zeichen und Name in
Pfirsich, in der Leiste trägt der obere Balken des Zeichens Pink. Das ist
Befund 2.

### Punkt 9 — erfüllt, um 20:12

Die Vorgaben eingetippt, `SrvPanel`, Fusszeile leer, `#3730a3` und `#ff7fec`.
„Die Marke ist gespeichert." stand um 20:12:56 da. In der Leiste stehen
wieder Zeichen und „SrvPanel", die Knöpfe sind im dunklen Thema wieder Pink,
und der Hinweis lautet „Gemessen 9,52:1 auf #fafafb".

**Block 1 war Zeile für Zeile der vom Morgen**, mit der einen Ausnahme, die §3
Punkt 9 vorhersagt: `branding/` steht jetzt da, leer, zuletzt geändert um
20:00 beim Entfernen. `<title inertia>SrvPanel</title>`, keine
`<style>`-Zeile, `Logo-Route: 404`. Ohne eigene Farben schreibt `Style::css()`
keinen Block, und das ist die Gegenprobe zu Punkt 3 in der Zeit.

In der Ablage stehen die Vorgaben seitdem als Werte; unter `rc.11` geht es
nicht anders. Danach äusserte der Betreiber den Wunsch, aus dem §6d geworden
ist.

### Die acht Befunde

| | wo | was | gefunden | Stand |
|---|---|---|---|---|
| 1 | Prüfling | Die beiden Formulare auf `/settings/general` stehen ohne Abstand, 0 px | vom Betreiber in Punkt 1, auf dem Telefon | gebaut für `rc.12` (§6b) |
| 2 | Prüfling | Schrift und Zeichen auf den Markenflächen bleiben in den Farben der Auslieferung | am Bild von Punkt 4a | gebaut für `rc.12` (§6c) |
| 3 | Prüfling | Die Prüfung beim Speichern rechnet die getönten Flächen nicht. Der aktive Menüpunkt fällt schon unter `rc.11` auf 4,14:1 | beim Bauen von 2, im Container | gebaut für `rc.12` (§6c) |
| 4 | Vorschrift | Punkt 7 misst nichts, wenn Punkt 4 länger als fünf Minuten zurückliegt | vor Punkt 7, an der Uhr, gemessen im Container | berichtigt, §3 Punkt 7 |
| 5 | Vorschrift | Die erste Gegenprobe zu Punkt 7 legt bei einem abgelaufenen Eintrag Logo B unter die alte Adresse | vor Punkt 7, im Container; gefahren wurde sie trotzdem | berichtigt, Punkt 7 wiederholt |
| 6 | Vorschrift | Block 2 druckt `"logo":null` nicht | vor Punkt 8, im Container | berichtigt, §3 Punkt 3 |
| 7 | Prüfstand | Ein Lauf mit 310 s Wartezeit neben anderen Läufen gegen denselben Server: Der Fall „frisch" druckte nichts, und der lange Lauf war wertlos | im Container | nacheinander wiederholt |
| 8 | Prüfstand | `Network.setCacheDisabled` wirkt ohne `Network.enable` nicht | im Container, an der Gegenprobe zur Gegenprobe | berichtigt |

Die Befunde 7 und 8 betrafen die Messungen, mit denen ich die Gegenprobe im
Container belegt habe, und keine Zahl auf dem Server.

### Beobachtungen, keine Befunde

1. **`x-content-type-options: nosniff` steht in der Antwort der Logo-Route
   zweimal.** nginx setzt die Zeile für jede Antwort des Panels
   (`add_header … always` in `PanelVhost`), der Controller für die Route noch
   einmal. Der Wert ist derselbe und wirkt wie einer. Bei der 404
   in Punkt 8 steht er einmal.
2. **Die Unterschrift ist für ein Mailprogramm keine.** Die Trennzeile lautet
   `--`. Die Konvention ist `-- ` mit Leerzeichen (RFC 3676 §4.3), und ohne
   das Leerzeichen setzen Mailprogramme die Unterschrift nicht ab und lassen
   sie beim Antworten im Zitat. Dazu fehlt die Leerzeile davor:
   `resources/views/mail/signature.blade.php` hat eine, aber Laravel kürzt
   jede gerenderte Ansicht vorn (`ltrim(ob_get_clean())` in `PhpEngine`),
   auch eine eingebundene. Die Quelle oben zeigt es: Auf „… ausgelöst hat."
   folgt unmittelbar `--`, und im Container gerendert beginnt die Vorlage der
   Unterschrift mit `--`. In allen drei Mailvorlagen steht sie direkt unter
   der letzten Zeile. Kein Kriterium dieses Laufs.
3. **Die Zeit im Rumpf steht ohne Zone.** `Date:` sagt `15:39:54 +0000`, der
   Rumpf „am 2026-10-03 17:39:54", dieselbe Sekunde in der Anzeigezone des
   Panels. Die Zone nennt er nicht. Kein Kriterium dieses Laufs.
4. **„Logo entfernen" fragt nicht zurück** und meldet dasselbe wie das
   Speichern; eine gewählte Datei legt der Controller danach wieder ab (§3
   Punkt 8). Das Logo lässt sich jederzeit neu hochladen, und den Beleg gab
   Block 2.

### Was der Lauf über sich selbst gelernt hat

**Punkt 7 hat seinen Prüfkörper vier Stunden vor dem Gebrauch hergestellt.**
Logo A kam in Punkt 4 in den Zwischenspeicher und war nach fünf Minuten
abgelaufen. Der Satz dazu steht seit P7 in diesem Repo, dort an einer TTL von
zehn Sekunden:

> **Ein Prüfkörper, der eine Haltbarkeit hat, wird nicht vor ihr
> hergestellt.**

**Die erste Gegenprobe hat den Zwischenspeicher verändert, den sie lesen
sollte.** Im Container zeigte ein Neuladen danach Logo B unter der Adresse von
Logo A, also Befund 4 aus §0 im Kleinen, und hergestellt hatte ihn die
Gegenprobe selbst.

> **Ein Prüfkörper, der seinen Gegenstand beim Messen verändert, meldet den
> Unterschied als Fehler des Gemessenen.**

**Und die berichtigte Fassung stand zwei Stunden vor Punkt 7 bereit.** Sie
kam als Nachtrag in einer späteren Nachricht, die erste Fassung stand in der
Anweisung zum Schritt, und gefahren wurde die erste.

> **Eine Berichtigung, die als Nachtrag neben der alten Fassung steht, ersetzt
> sie nicht — gefahren wird, was beim Schritt steht.**

Wer eine Anweisung berichtigt, schreibt den ganzen Schritt neu und sagt, dass
der alte nicht mehr gilt.

**Zwei Fehler meines Prüfstands, beide an der Gegenprobe.** Den Fall
„abgelaufen" belegt nur ein Lauf mit 310 s Wartezeit. Ich habe ihn neben
anderen Läufen gestartet, die das Logo desselben Servers umschalteten. Der Fall
„frisch" druckte danach nichts, und der lange Lauf war wertlos.

> **Ein Test, dessen Ergebnis davon abhängt, was gerade nebenher läuft, misst
> die Umgebung mit.**

Zwei Läufe gegen einen Server, den beide umschalten, gehören hintereinander.
Den Fall „Cache deaktivieren" stellt `Network.setCacheDisabled` her, und das
wirkt erst nach `Network.enable`. Der erste Anlauf zeigte Logo A aus dem
Zwischenspeicher, also gerade den Zustand, den er ausschliessen sollte. Der
Satz dazu steht in `docs/96`:

> **Eine Vorbereitung, die man nicht belegt, ist keine Bedingung der Messung,
> sondern eine Hoffnung daneben.**

**Und einmal hat das Hinsehen über die Frage hinaus getragen.** Am Bild von
Punkt 4a waren Logo, Fusszeile und Knopf gefragt. Gefunden wurde daneben die
Schrift in Pfirsich, und daraus kamen die Befunde 2 und 3.

### Was aussteht

- **Der Nachlauf gegen `0.9.0-rc.12`**, ausgeschrieben vor dem Fahren. Er
  sieht §6b an der Fuge bei 390 px nach, §6c an den vier Erwartungen aus „Für
  den Lauf heisst das" und an Schrift und Zeichen in Mint, und §6d an Punkt 9
  ohne Abschreiben, an der Ablage mit `null` und an den Hinweisen mit der
  Vorgabe.
- **Die Abnahme spricht der Betreiber aus** (§5).
