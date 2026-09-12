# Abnahmelauf für das Wartungsband — `0.7.4-rc.5` auf `cloudsrv24`

Ausgeschrieben am **12. September 2026, vor dem Fahren**. Der Plan des Merkmals
ist `docs/911`, die Messrunde steht in dessen §2, der Bau in §6.

**Wofür es diesen Lauf gibt.** `docs/911 §7` nennt es beim Namen: *„Nichts davon
hat einen Server gesehen."* Alle Zahlen dieses Merkmals stammen aus dem
Container — SQLite statt MariaDB, Anzeigezone UTC statt Europe/Berlin, und ein
Prüfstand, in dem die Flagdatei nie eine Kundenwebsite abgeschaltet hat. Drei
Dinge lassen sich dort grundsätzlich nicht messen:

1. Dass die Behauptung des Bandes stimmt — dass **wirklich** jede Kundenwebsite
   mit 503 antwortet, während es dasteht.
2. Dass die Zone im Satz die des Servers ist und nicht fest `UTC`.
3. Dass der Abgleich Ablage ↔ Datei einen Befund erzeugt, den das Abzeichen
   trägt — im Container gibt es weder einen Nachtlauf noch eine echte Flagdatei.

**Die Freigabe trägt zwei Änderungen und nicht eine.** `v0.7.4-rc.5` bringt das
Band **und** das Diagnose-Abzeichen aus `docs/910`, das in PR #236 gemergt und
nie getaggt wurde.

> **Ein Nachlauf gegen eine Fassung, die vieles mitbringt, misst nicht die eine
> Behebung.** (`docs/114 §14`)

Deshalb steht in §9 ausdrücklich, was Punkt 8 über das Abzeichen sagt und was
nicht: Er misst, dass der **neue** Befund dort ankommt — nicht das Abzeichen.

---

## §0 Was beim Ausschreiben umgefallen ist

Vier Kriterien standen schon da und waren falsch. Gefunden hat sie das
Nachlesen am Quelltext und nicht das Nachdenken.

**1. „Das Band verschwindet, wenn die Flagdatei von Hand entfernt wird."** Das
tut es nicht, und zwar mit Absicht: Das Band liest `Settings::maintenance()`,
also eine **Ablage**. Entscheidung 4 aus `docs/911 §3` sagt warum — ein
Sockelaufruf an den Agenten je Seitenaufbau wäre genau der Fehler, den
`docs/904` für `/updates` gerade behoben hat. Wer diesen Punkt fährt, meldet den
Prüfling für etwas, das er zu Recht tut.

> **Ein Kriterium, das man am falschen Paket misst, meldet den Prüfling für
> etwas, das er zu Recht tut.**

Der Zustand ist trotzdem ein Befund — nur nicht im Band, sondern in der
Bestandsdiagnose. Er steht als Punkt 7.

**2. „Drei Bänder stehen gleichzeitig: Impersonation, Wartung, Ankündigung."**
Nicht herstellbar, und der Grund ist Bauart und kein Mangel: `ImpersonationController`
ruft `Auth::login($target)`, der Betrachter **ist** danach der Kunde, und das
Wartungsband hängt an `operate-server`. Die beiden schliessen einander aus.
Gemessen wird deshalb der Stapel aus Wartungsband und Ankündigung (Punkt 5) —
und die Ausschliesslichkeit selbst wird zu Punkt 6.

**3. „Das Abzeichen am Menüpunkt „Diagnose" zeigt 1."** `cloudsrv24` trägt
bekannte Befunde — der Rest aus P7 (`orphan.row` für `tls.cloudlab24.de`) steht
seit `docs/113 §13` benannt offen. Eine absolute Zahl misst damit die Geschichte
dieses Servers und nicht die Wirkung des neuen Befundes. Gemessen wird **vorher
und nachher**, und der Ausgangswert wird gemessen und nicht aufgeschrieben.

> **Ein Marker, den man aufschreibt statt ihn zu messen, altert zwischen dem
> Aufschreiben und dem Messen.** (`docs/909`)

**4. „Der Administrator sieht das Band nicht."** Als Punkt dieses Laufs nicht
fahrbar, ohne den Anmeldeweg aus A9 mitzumessen: Ein zweites Adminkonto anlegen,
ein Passwort setzen, einen zweiten Faktor einrichten, sich anmelden — das misst
`docs/83` und nicht dieses Band. Dieselbe Tür beisst billig über die Kundensicht
(Punkt 6); dass es die **richtige** Tür ist — `AdminAbility::OPERATE_SERVER` und
keine andere —, hält `MaintenanceBandTest` in der CI und ist mit einem Eingriff
belegt.

> **Was ein Wächter halten kann, misst ein Server nicht noch einmal. Was er
> nicht halten kann, misst nur ein Server.**

---

## §1 Vorbereitung — und sie wird belegt, nicht vorausgesetzt

Alles als `root` auf `cloudsrv24`, ausser wo eine Browserzeile dasteht.

```bash
srvpanel version                                    # erwartet: 0.7.4-rc.5
systemctl is-active srvpanel-agentd srvpanel-worker  # erwartet: active active
ls -l /var/spool/srvpanel/wartung                   # erwartet: No such file
```

**Der Arbeiter gehört dazu und ist kein Beiwerk.** Punkt 3 ändert die Endzeit,
und das schreibt über die Warteschlange jede lebende Domain neu
(`MaintenanceMode::rewrite()`). Steht `srvpanel-worker` still, bleiben die
Vorgänge auf „wartet" — und das läse sich wie ein hängender Agent.

**Die Zone wird gemessen und nicht angenommen.** Das Band setzt sie über
`Clock::labelAt()` und nicht `label()`, weil Berlin im Januar anders heisst als
im Juli. Beide werden abgelesen, damit im Protokoll steht, wogegen der Satz
später gehalten wird:

```bash
srvpanel tinker --execute='
  echo "label():   ", App\Support\Time\Clock::label(), PHP_EOL;
  echo "labelAt(): ", App\Support\Time\Clock::labelAt(now("UTC")->toDateTimeString()), PHP_EOL;'
```

Erwartet ist zweimal `CEST (UTC+02:00)`. **Steht dort `UTC`, ist der Lauf nicht
kaputt** — dann ist die Anzeigezone dieses Servers UTC, und Punkt 2 misst
lediglich, dass der Satz die Zone der Anzeige trägt statt einer festen.

**Der Ausgangswert des Abzeichens**, gemessen und nicht erinnert:

```bash
srvpanel tinker --execute='
  echo (new App\Support\Diagnose\PendingFindings)->count(), PHP_EOL;
  foreach (App\Models\Finding::query()->get() as $f) {
      echo "  ", $f->check->value, " / ", $f->reason, " — ", $f->subject, PHP_EOL;
  }'
```

Die Zahl heisst im Weiteren **N**. `Finding` trägt keine Mandantenklammer
(nachgesehen: kein `BelongsToSubscription`), die Frage auf der Kommandozeile ist
also eine echte und keine, die im Grundzustand alles verweigert.

**Eine Kundendomain wird ausgewählt und ihr heiler Zustand belegt.** Sie heisst
im Weiteren `<domain>`; sie muss `active` sein und darf kein Alias sein.

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/
```

Erwartet: `200` (oder was diese Domain sonst im Normalfall gibt — **gemessen**,
nicht angenommen; der Wert heisst im Weiteren **V**).

> **Ein Prüfkörper, der den Zustand herstellt, statt ihn zu suchen, ändert den
> Server für eine Zeile, die vielleicht schon dasteht.** (`docs/902`)

**Der Griff im Browser** — eingefügt in die Konsole, angemeldet als Betreiber:

```js
const app = document.getElementById('app').__vue_app__.config.globalProperties
const band = () => document.querySelector('a.band.warn[href="/maintenance"]')
const stand = () => ({
  url:      app.$page.url,
  prop:     app.$page.props.maintenanceBand,
  band:     band()?.innerText ?? null,
  hoehe:    band() ? Math.round(band().getBoundingClientRect().height) : null,
  abzeichen: app.$page.props.pendingFindings,
})
```

**`$page` und nicht das `script[data-page]`.** Inertia 3 legt die Ablage nicht
ans Wurzelelement, und das Script-Element trägt die Seite, mit der geladen
wurde — es überlebt jede Navigation (`docs/903`). `url` wird deshalb
mitgedruckt: Eine Messung der vorigen Seite ist von einer der gemeinten sonst
nicht zu unterscheiden.

**Der Wählerausdruck ist `a.band.warn[href="/maintenance"]` und nicht
`.band.warn`.** Das Impersonationsband trägt denselben Rang; ein Ausdruck über
die Klasse allein träfe beide und könnte das eine für das andere halten.

---

## §2 Punkt 1 — der Ausgangszustand ist der Prüfkörper

Angemeldet als Betreiber, auf einer beliebigen Seite:

```js
stand()
```

**Erwartet:** `prop: null`, `band: null`, `abzeichen: N`.

**Ohne diesen Punkt sagen die folgenden nichts.** Ein Band, das immer dasteht,
belegt nicht, dass es einen Zustand zeigt.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## §3 Punkt 2 — eingeschaltet, und die Behauptung wird gegengeprüft

Auf `/maintenance`: **einschalten, Endzeit leer lassen**, speichern.

```js
stand()
```

**Erwartet:**

| | |
|---|---|
| `prop.since` | ein Zeitpunkt der Form `2026-09-12 14:07` |
| `prop.since_zone` | die in §1 gemessene Zone, also `CEST (UTC+02:00)` |
| `prop.until` | `null` |
| `prop.overdue` | `false` |
| `band` | `Wartung Alle Kundenwebsites antworten mit 503 — seit … Uhr (CEST (UTC+02:00)).` |

Und **die Behauptung selbst**, auf dem Server:

```bash
ls -l /var/spool/srvpanel/wartung
curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/
```

**Erwartet:** Die Datei liegt, und die Antwort ist `503`.

**Das ist der Kern dieses Punktes und nicht sein Beiwerk.** Das Band sagt einen
Satz über den Zustand *draussen*; gemessen wird er an einer echten Domain über
die echte Leitung. Im Container gibt es keine, die antworten könnte.

> **Eine Gegenprobe über einen anderen Weg als den benutzten prüft den falschen
> Weg.** (`docs/44`, `docs/108`)

**Kein Rundlauf in diesem Schritt.** Die Endzeit war vorher `null` und ist es
geblieben; `MaintenanceMode::set()` schreibt die Vhost-Dateien nur neu, wenn sie
sich ändert. Erwartet sind **null** neue Vorgänge — nachzusehen auf
`/operations`.

---

## §4 Punkt 3 — die Endzeit kommt dazu, und die Dauer springt nicht

Der Wert von `prop.since` aus Punkt 2 wird notiert; er heisst **S**.

Auf `/maintenance`: **eingeschaltet lassen**, Datum und Uhrzeit auf einen
Zeitpunkt **zwei Stunden in der Zukunft** setzen, speichern.

```js
stand()
```

**Erwartet:**

| | |
|---|---|
| `prop.since` | **unverändert S** |
| `prop.until` | der eingetippte Zeitpunkt, in der Anzeigezone |
| `prop.overdue` | `false` |
| `band` | `… mit 503 — seit S Uhr (…), voraussichtlich bis … Uhr (…).` |

**`since` ist die Messung dieses Punktes und nicht `until`.** Wer nur die
Endzeit ändert, ruft dieselbe Route mit `enabled = true`; ein Zeitstempel je
Aufruf setzte die Dauer zurück, und das Band läse danach „seit einer Minute",
während die Wartung seit Stunden läuft.

> **Ein Wert, der bei jeder Änderung neu entsteht, misst die letzte Änderung und
> nicht den Zustand.**

**Und hier läuft der Rundlauf.** Die Endzeit steht im Server-Block jeder Domain,
also schreibt `rewrite()` sie neu — ein `web.site.apply` je lebender Domain über
die Warteschlange. Auf `/operations` stehen danach so viele neue Vorgänge, wie
der Server lebende Nicht-Alias-Domains hat. **Das ist kein Befund**, sondern
die Bauart aus `docs/101`; die Zahl wird abgelesen und im Protokoll genannt.

Gewartet wird, bis sie `succeeded` sind, bevor Punkt 4 gefahren wird — sonst
misst der nächste Schritt in eine laufende Warteschlange hinein.

---

## §5 Punkt 4 — die überschrittene Endzeit steht vorn *(darf nicht ausfallen)*

Auf `/maintenance`: **eingeschaltet lassen**, die Endzeit auf einen Zeitpunkt
**in der Vergangenheit** setzen (heutiges Datum, eine Stunde zurück), speichern.

Eine Zeit in der Vergangenheit ist zugelassen und keine Lücke — der
Prüfkommentar in `MaintenanceController::update()` sagt es ausdrücklich: Die
Angabe ist eine Auskunft und keine Steuerung.

```js
stand()
```

**Erwartet:**

| | |
|---|---|
| `prop.overdue` | **`true`** |
| `band` beginnt mit | `Wartung Die angekündigte Endzeit ist seit … Uhr (…) vorbei.` |
| danach | `Alle Kundenwebsites antworten mit 503, seit S Uhr (…).` |

**Zwei Dinge werden hier gemessen, und das zweite ist das teurere.** Dass der
Satz umschlägt — und dass die überschrittene Endzeit **vorn** steht.

> **Ein Band, das nach der angekündigten Endzeit weiter „voraussichtlich bis"
> sagt, wiederholt ein abgelaufenes Versprechen — es schwiege in genau dem
> Augenblick, für den es gebaut ist.**

**Dieser Punkt darf nicht ausfallen.** Er ist der Grund, aus dem das Band eine
dritte Fassung hat; fällt er, ist das Merkmal eine Verzierung auf einem
Zustand, den man ohnehin gerade eingeschaltet hat.

Daneben meldet die Bestandsdiagnose seit A12 eine überschrittene Endzeit als
eigenen Befund (`MaintenanceWindow`). Dass sie es tut, ist in `docs/102`
abgenommen und wird hier **nicht** noch einmal gemessen; wenn sie in Punkt 7
mit auftaucht, wird sie im Protokoll genannt und nicht dem neuen Befund
zugeschlagen.

---

## §6 Punkt 5 — die Bilderrunde, und zwei Bänder stapeln

Vorbereitung: auf `/announcements` **eine Ankündigung anlegen** (Rang `info`,
kurzer Text). Sie wird in Punkt 10 wieder entfernt.

Dann, im Zustand aus Punkt 4, `tests/bilder-messen.js` in die Konsole einfügen
und je Lage aufrufen:

```js
bilderMessen()
```

**Vier Lagen:** 390 px und 1440 px, je hell und dunkel. **Jede Lage in einer
frisch geladenen Seite** — `bilderMessen()` wirft beim zweiten Aufruf ohne
Neuladen, und ein zweites Einfügen scheitert an der Wiederdeklaration von
`STAND`. Dass die Vorschrift an ihrer eigenen Falle scheitert, ist der Beleg,
dass die Vorbedingung eingehalten wurde (`docs/108`).

**Erwartet je Lage:** `dokument: 0`, `gegenprobe: 200`, und `stand` zeigt die
gefahrene Fassung.

**Dazu, in derselben Lage, die Lage der Bänder:**

```js
const b = [...document.querySelectorAll('.bands > *')]
b.map(e => ({ was: e.className, oben: Math.round(e.getBoundingClientRect().top),
              hoch: Math.round(e.getBoundingClientRect().height) }))
```

**Erwartet:** zwei Einträge, und die Oberkanten wachsen streng — sie **stapeln**
und liegen nicht übereinander. Das ist die Falle aus `docs/103`, die seit dem
5. September geschlossen ist; im Container ist sie gemessen (`docs/911 §2`, M4),
auf einem Server noch nie.

**Die Höhe des Wartungsbandes** wird notiert. Im Container waren es **104 px**
bei 390 px für die überschrittene Fassung und **41 px** bei 1440 px. Eine
Abweichung ist kein Befund — die Zone ist hier länger als `UTC`, und der Satz
wird dadurch breiter. Ein **Überlauf** wäre einer.

---

## §7 Punkt 6 — wer nicht schalten darf, sieht es nicht

Im Zustand aus Punkt 4: auf `/customers` einen Kunden öffnen und **„Anmelden
als"** benutzen.

**Der Griff aus §1 wird danach neu eingefügt.** Ob der Wechsel die Seite hart
neu lädt oder über Inertia geht, ist nicht gemessen — und eine Konsole, in der
`stand` plötzlich nicht definiert ist, kostet mitten im Lauf Zeit, die niemand
vorhergesehen hat. Einfügen ist billiger als nachsehen.

```js
stand()
document.querySelector('.bands .band.warn')?.innerText ?? null
```

**Erwartet:** `prop: null`, `band: null` — **und** die zweite Zeile zeigt das
Impersonationsband. Die Kundenwebsites geben in diesem Augenblick weiter 503;
der Kunde erfährt es nicht vom Panel, und das ist Entscheidung 1 aus
`docs/911 §3`.

Danach zurück zur Verwaltung, und **belegen, dass es wieder da ist**:

```js
stand()
```

**Erwartet:** `prop` wieder gesetzt.

**Der Rückweg ist die Gegenprobe und nicht das Aufräumen.** Ohne ihn bliebe
offen, ob das Band überhaupt noch erscheint — und dann meldete dieser Punkt ein
Verschwinden, das nichts mit der Fähigkeit zu tun hat.

**Was er misst und was nicht:** Er misst, dass die Tür beisst. Dass es die
richtige Tür ist — `operate-server` und nicht irgendeine —, hält
`MaintenanceBandTest`; siehe §0 Punkt 4.

---

## §8 Punkt 7 und 8 — die Datei verschwindet, und das Abzeichen steigt

Auf dem Server, im Zustand aus Punkt 4 (Ablage: **an**):

```bash
rm /var/spool/srvpanel/wartung
curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/   # erwartet: V
srvpanel diagnose
```

**Die Website ist sofort wieder erreichbar** — nginx liest die Datei bei jeder
Anfrage. Genau das ist der Zustand, den niemand bemerkt: Das Panel führt eine
Wartung, die keine mehr ist.

**Punkt 7 — erwartet in der Ausgabe von `srvpanel diagnose`** und auf
`/diagnose`:

| | |
|---|---|
| Prüfung | `maintenance.flag` |
| Grund | `missing` |
| Zustand | **Warnung** |
| Gegenstand | `/var/spool/srvpanel/wartung` |
| Text | `Das Panel führt den Modus als eingeschaltet; die Datei liegt nicht, und die Kundenwebsites sind erreichbar.` |

**Der Gegenstand kommt aus der Antwort des Agenten und nicht aus der Konstante.**
Steht dort ein anderer Pfad, ist das ein Befund und keine Kleinigkeit — dann
sieht der Agent woanders nach als die Wache im Server-Block.

**Und das Band behauptet weiter, es sei Wartung** — das ist gemessen und kein
Mangel (§0 Punkt 1):

```js
stand()
```

**Erwartet:** `prop` unverändert gesetzt. Die Grenze aus `docs/911 §2` M8 steht
damit auf einem Server gemessen da statt hergeleitet.

**Punkt 8 — das Abzeichen.** Auf einer beliebigen Seite neu laden:

```js
app.$page.props.pendingFindings
document.querySelector('a[href="/diagnose"] .badge.count')?.textContent ?? null
```

**Erwartet:** **N + 1**, und dieselbe Zahl sichtbar am Menüpunkt.

Ist in Punkt 5 zusätzlich die überschrittene Endzeit als eigener Befund
dazugekommen, ist der erwartete Wert entsprechend höher; gezählt wird die
**Differenz** und die wird begründet, nicht die absolute Zahl.

**Was dieser Punkt über das Abzeichen sagt:** dass ein **neu entstandener**
Befund dort ankommt. Dass das Abzeichen richtig zählt, ist `docs/910` und hängt
nicht an diesem Lauf — ausgezählt sind `missing → warn` und
`unexpected → fail`, beide auffällig, und die Schnittmenge aus lauten und
leisen Grundnamen ist leer (gemessen, `PendingFindings::loudReasons()`).

---

## §9 Punkt 9 — die Datei liegt, das Panel schweigt *(darf nicht ausfallen)*

**Erst die Gegenprobe zu Punkt 7.** Auf `/maintenance` **ausschalten**, dann:

```bash
srvpanel diagnose
```

**Erwartet:** `maintenance.flag` ist **fort**, und das Abzeichen steht wieder
auf **N**. Ohne diesen Schritt bliebe offen, ob der Befund von der Abweichung
kommt oder einfach immer dasteht.

**Dann die andere Richtung — und sie schaltet echte Kundenwebsites ab:**

```bash
touch /var/spool/srvpanel/wartung
curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/   # erwartet: 503
srvpanel diagnose
rm /var/spool/srvpanel/wartung                                 # sofort danach
curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/   # erwartet: V
```

**Die vier Zeilen gehören zusammen und werden zusammen abgesetzt.** Zwischen
`touch` und `rm` antwortet jede Kundenwebsite dieses Servers mit 503, ohne dass
irgendetwas im Panel es sagt. Das Fenster ist so lang wie ein Diagnoselauf —
gemessen 391 ms für den ganzen Nachtlauf (`docs/100 §1.3`).

**Erwartet:**

| | |
|---|---|
| Prüfung | `maintenance.flag` |
| Grund | `unexpected` |
| Zustand | **Fehler** |
| Text | `Das Panel führt den Modus als ausgeschaltet; die Datei liegt, und die Kundenwebsites antworten mit 503.` |

**Und im selben Augenblick, im Browser:** kein Band. Das ist der Zustand, für
den es diese Prüfung gibt — die Anzeige kann ihn nicht zeigen, weil sie eine
Ablage liest, und deshalb muss ihn eine Prüfung finden.

**Dieser Punkt darf nicht ausfallen.** Von den beiden Richtungen ist er die
teure: Bei `missing` glaubt der Betreiber an einen Schutz, den er nicht hat; bei
`unexpected` sind die Kunden offline und niemand weiss es.

**Zum Schluss, nach dem `rm`:**

```bash
srvpanel diagnose
```

**Erwartet:** der Befund ist fort, das Abzeichen steht auf **N**.

---

## §10 Punkt 10 — der Endzustand wird belegt und nicht angenommen

```bash
ls -l /var/spool/srvpanel/wartung                              # erwartet: No such file
curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/   # erwartet: V
srvpanel tinker --execute='
  var_dump(app(App\Support\Settings\Settings::class)->maintenance());
  echo (new App\Support\Diagnose\PendingFindings)->count(), PHP_EOL;'
```

**Erwartet:** `enabled: false`, `until: null`, `since: null`, und die Zahl ist
wieder **N**.

Dazu im Browser: `stand()` → `prop: null`, `band: null`.

Und aufgeräumt wird, was der Lauf angelegt hat:

- die Ankündigung aus Punkt 5 auf `/announcements` löschen,
- die Vorgänge aus Punkt 3 bleiben stehen — sie sind das Protokoll des Laufs
  und kein Rest.

**Die Endzeit ist dabei im Server-Block jeder Domain geblieben**, mit dem Wert
aus Punkt 4. Sie wirkt nicht, solange der Modus aus ist; wer sie loswerden will,
schaltet einmal mit leerer Endzeit ein und wieder aus. Das gehört **nicht** in
diesen Lauf: Es wäre ein zweiter Rundlauf über alle Domains für eine Zeile, die
niemand liest.

---

## §11 Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Wartungsseite des Besuchers.** Ihren Wortlaut, ihren `Retry-After` und
  die Ausnahme für die ACME-Prüfadresse misst `docs/102`; A12 ist abgenommen.
  Hier wird nur der Statuscode gelesen — als Gegenprobe zur Behauptung des
  Bandes, nicht als Prüfung der Seite.
- **Dass der Nachtlauf von selbst feuert.** `srvpanel-diagnose.timer` ist in
  A10 abgenommen (`docs/100`). Gefahren wird `srvpanel diagnose` von Hand;
  alles andere hiesse, eine Nacht zu warten, um eine Prüfung zu messen, die in
  einer Sekunde antwortet.
- **Das Abzeichen selbst.** Siehe §8; gemessen wird, dass der neue Befund dort
  ankommt.
- **Dass das Band der Administrator nicht sieht.** Siehe §0 Punkt 4.
- **Die Zeiten.** `docs/911 §2` M2 hat 0,122 ms für `Settings::maintenance()`
  gemessen — gegen SQLite. Eine Messung gegen MariaDB wäre eine neue Zahl und
  keine Abnahme; das Band trägt zwei Abfragen je Anfrage, und das ist die
  Grösse, die zählt, nicht die Millisekunde.
- **Der Zustand „eingeschaltet, aber `since` fehlt".** Er entsteht nur auf einem
  Server, der beim Einspielen dieser Fassung **schon** in Wartung stand. Ob
  `cloudsrv24` das tut, entscheidet §1; tut er es nicht, ist der Zustand hier
  nicht herstellbar und bleibt benannt offen.

---

## §12 Wann er durch ist

Alle zehn Punkte erfüllt, **die Punkte 4 und 9 darunter**. Fällt einer der
beiden, ist das Merkmal nicht abgenommen — der eine ist der Grund, aus dem das
Band eine dritte Fassung hat, der andere der Grund, aus dem es die Prüfung gibt.

**Kein Punkt darf als „nicht herstellbar" ausfallen.** Alle zehn hängen an
Zuständen, die dieser Server herstellen kann; wo einer am Werkzeug scheitert und
nicht am Gegenstand, wird er nachgeholt und nicht umbenannt.

> **Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
> „nicht herstellbar" — und ihn so zu nennen wäre die bequemere von zwei
> falschen Auskünften.** (`docs/108`)

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten, ist
> keines mehr — es ist eine Zusammenfassung.**

Das Protokoll bekommt die nächste freie Nummer im 900er-Block und liegt neben
diesem Lauf. Es hält je Punkt den **gemessenen** Wert fest, die Befunde mit
ihren Lehren und am Ende, was benannt offen bleibt — `docs/911 §7` nennt vier
Dinge, die dieser Lauf nicht alle schliesst.
