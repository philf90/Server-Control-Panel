# Ein Abzeichen am Menüpunkt „Diagnose"

Geschrieben am **11. September 2026, nach der Messrunde**. Der Anlass ist eine
Frage des Betreibers („welche weiteren Navigationspunkte lassen sich sinnvoll
mit einem Status-Badge ergänzen?"), die Entscheidung für `fail` **und** `warn`
ist seine.

Das Vorbild ist `docs/907` — das Abzeichen am Menüpunkt „Updates", abgenommen
am 11. September (`docs/909`). Dieser Plan übernimmt dessen Form und **nicht
seine Bauart**: Dort musste die Zahl abgelegt werden, weil ihre Quelle 3033 ms
kostet. Hier kostet sie 0,073 ms, und das ändert alles.

---

## §1 Warum überhaupt

Der Nachtlauf schreibt Befunde in eine Tabelle, und **niemand sieht sie, der
nicht hingeht**. Genau davor warnt `docs/81 §11` bei A13:

> **Ein Befund, der niemanden erreicht, ist eine Zeile in einer Tabelle.**

Am 11. September stand auf `cloudsrv24` `Auffällig: 2` — zwei Befunde, die seit
Tagen dort liegen und die weder der Betreiber noch ich angesehen hatten, bis
ein Abnahmelauf zufällig daran vorbeikam.

---

## §2 Die Messrunde

Gefahren am 11. September 2026 gegen die lokale Datenbank (SQLite) mit einem
gebooteten Laravel, jede Zeitmessung **warm** und über 20 bis 50 Runden.

### M1 — Es gibt keine Zustandsspalte *(wirft den naheliegenden Entwurf um)*

`findings` führt `check`, `subject`, `reason`, `detail`, zwei Zeitstempel — und
**keinen Zustand**. Der kommt aus `FindingCheck::state($reason)`, einer
Abbildung in PHP.

> **„Zähle alle mit `fail` oder `warn`" ist damit keine SQL-Frage** — jedenfalls
> nicht ohne einen Umweg über die Paare, die diese Abbildung kennt.

### M2 — 49 Paare: 30 `fail`, 8 `warn`, 11 `unknown`

**Und die erste Zählung war falsch.** Ein `grep -o 'FindingState::[A-Za-z]*'`
über die Datei ergab 28 / 8 / 2 statt 30 / 8 / 11.

Der Grund steht in `reasons()` selbst: `$unreachable` ist **einmal**
hingeschrieben und wird mit `...$unreachable` in elf Fälle gespreizt. Der
Ausdruck zählte das eine Literal, die Abbildung trägt elf Paare.

> **Ein Ausdruck, der einen Wert als Literal sucht, zählt die Stellen, an denen
> ihn jemand hingeschrieben hat — und nicht die, an denen er gilt.**

Das ist derselbe Satz wie bei `...array_fill_keys(Schedule::FIELDS, …)` am
20. August: *Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht
wenig gemessen — er hat an dieser Stelle gar nicht gemessen.* Gefragt wird
deshalb die Abbildung und nicht die Datei.

**Die elf `unknown` heissen alle `unreachable`** — „der Agent hat nicht
geantwortet". Das ist kein Mangel am Server, sondern die Aussage, dass nicht
nachgesehen werden konnte; der Betreiber hat sie deshalb aus dem Abzeichen
herausgehalten.

### M5 — Nur eine Stelle schreibt

`App\Support\Diagnose\FindingLog` ist der einzige Schreiber. Das macht eine
**abgelegte** Zahl überhaupt erst denkbar — sie wäre nie veraltet.

### M7 — Drei Wege, gemessen

| Zeilen | A: laden + in PHP filtern | B: SQL über 38 Paare | D: `whereNotIn('reason', …)` |
|---|---|---|---|
| 2 | 0,179 ms | 1,061 ms | **0,074 ms** |
| 50 | 0,833 ms | 1,088 ms | **0,084 ms** |
| 500 | 7,527 ms | 1,360 ms | **0,095 ms** |

Alle drei liefern dieselbe Zahl (gegengeprüft je Lauf).

**A scheidet nicht an seiner Zahl aus, sondern an seiner Steigung.** Es wird
teurer, je mehr der Server zu melden hat:

> **Eine Anzeige, die teurer wird, je mehr sie zu melden hat, wird genau dann
> langsam, wenn sie gebraucht wird.**

### M6 — Warum D erlaubt ist, und wie sich das halten lässt

D filtert über `reason` **allein** und nicht über das Paar. Das ist nur dann
gleichwertig, wenn kein Grundname zugleich auffällig und nicht auffällig ist.
Gemessen: 28 auffällige Namen, **ein** nicht auffälliger (`unreachable`),
**Überschneidung leer**.

Damit ist die Abkürzung nicht geraten, sondern **beweisbar** — und der Beweis
ist die Bedingung, unter der sie gilt. `DiagnoseBadgeTest` hält genau diese
Bedingung; überschneiden sich die Namen je, wird er rot und jemand baut die
Paarform.

> **Eine Abkürzung, deren Voraussetzung ein Wächter hält, ist keine Abkürzung
> mehr — sie ist ein Sonderfall mit Beleg.**

### M8 — Die Ablage wäre langsamer als ihre Quelle *(entscheidet gegen C)*

| | warm |
|---|---|
| `Settings::pendingUpdates()` — ein abgelegter Wert | **0,211 ms** |
| die Zählung D aus der Tabelle | **0,073 ms** |

> **Eine Ablage, die einen Wert schneller liefern sollte als seine Quelle, ist
> hier langsamer als sie** — und dazu eine zweite Fassung derselben Wahrheit.

Damit ist die Bauart von `docs/907` hier **falsch**, obwohl die Form dieselbe
ist. Das Abzeichen zählt live.

**Und die erste Fassung dieser Messung war keine:** Der erste Aufruf kostete
**18,6 ms** — Verbindungsaufbau und Auflösung. Einmal gefahren misst man den
kalten Zwischenspeicher.

> **Eine Messung, die man nur einmal fährt, misst den Zwischenspeicher mit — und
> ob sie ihn kalt oder warm erwischt, sagt sie nicht.**

### M9 — Die Breite, und warum keine Deckelung

Gemessen im Streifen (236 px, Eintrag 203 px), mit dem `data-v`-Attribut des
Übersetzers gesetzt:

| Ziffern | 1 | 2 | 3 | 4 |
|---|---|---|---|---|
| Abzeichen | 25 px | 34 px | 43 px | 52 px |

Bei „Diagnose" bleibt auch mit **vier** Ziffern Luft; nichts ragt heraus,
`dokument = 0`. Eine Deckelung auf „99+" wäre eine ungemessene Vorsicht, die
Auskunft kostet.

---

## §3 Zwei Fehler am eigenen Prüfmittel, beide in dieser Runde

**Der Ladebeleg konnte nicht unterscheiden.** Seit gestern druckt jede
Bildmessung mit, dass der Prüfling geladen ist. Hier stand als Beleg
`getComputedStyle(rail).display` — und `block` ist bei einem `<aside>` **auch
der Vorgabewert**. Der Beleg war erfüllt, während `.frame` kein Raster war.

> **Ein Ladebeleg, dessen Wert im geladenen und im ungeladenen Fall derselbe
> ist, belegt nichts.**

Ein brauchbarer nennt eine Eigenschaft, die es ohne das Stylesheet nicht gibt —
hier `border-radius: 999px` am Abzeichen und `display: grid` an `.frame`.

**Und die Ursache dahinter ist die bekannte:** `.frame` und `.rail` stehen im
**`scoped`**-Block von `PanelLayout.vue`. Ohne `data-v-bd07ad81` am Markup gibt
es sie nicht, und die Leiste war 1440 px breit statt 236.

> **Eine Regel, die an ein Attribut gebunden ist, das nur der Übersetzer setzt,
> fehlt in jedem Aufsatz, der das Markup selbst schreibt.**

---

## §4 Was gebaut wird

1. **`App\Support\Diagnose\PendingFindings`** — eine Stelle, die die Zahl
   liefert. Sie leitet die auszunehmenden Gründe **aus `FindingCheck` ab** und
   nicht aus einer Liste; die Voraussetzung aus M6 steht in ihrem Kopf.
2. **`HandleInertiaRequests::share()`** reicht sie als **Verschluss** nach —
   ein fertiger Wert liefe auch bei einem partiellen Nachladen, das ihn gar
   nicht mitschickt (`docs/103`).
3. **Der Menüpunkt „Diagnose"** bekommt `badge: pendingFindings`, dieselbe
   Form wie „Updates".
4. **Der Punkt am Menüknopf** zählt beide Quellen. Er war für *eine* Zahl
   gebaut; bliebe er bei ihr, wären die Befunde auf dem Telefon wieder
   unsichtbar — genau der Befund, für den es ihn gibt.
5. **Die Beschriftung des Knopfes** wird aus den **sichtbaren** Menüpunkten mit
   Abzeichen gebaut und nicht aus zwei festen Sätzen. Eine dritte Quelle später
   trägt sich damit von selbst ein, und ein Abzeichen, das der Betrachter nicht
   sehen darf, wird auch nicht vorgelesen.

**Was nicht gebaut wird:** keine Ablage, keine Deckelung, keine Zustandsfarbe
(`NavBadgeTest` hält, dass im Streifen keine steht — gemessen 2,67:1 im hellen
Thema), und **kein zweiter Badge für „Dienste"**: Was dort zu melden wäre,
beurteilt die Bestandsdiagnose ohnehin nachts, und eine zweite Anzeige derselben
Sache ist die, die veraltet.

---

## §5 Die Messung an der echten Seite

Gefahren am 11. September 2026 gegen `artisan serve` im Container, mit dem
gebauten Stylesheet und echten Daten — **sechs Lagen**: beide Themen, 1440 px,
390 px zugeklappt und 390 px mit offener Schublade.

| Lage | Abzeichen | Punkt | `dokument` | Gegenprobe |
|---|---|---|---|---|
| 1440 px | `Updates=32@x173` · `Diagnose=2@x182` | — | 0 | 200 |
| 390 px, zu | `@x-63` · `@x-54` (ausserhalb) | **6 px @ x42** | 0 | 200 |
| 390 px, offen | `Updates=32@x209` · `Diagnose=2@x218` | 6 px @ x42 | 0 | 200 |

Beide Themen Zeile für Zeile gleich; die Beschriftung des Menüknopfes lautet in
allen sechs Lagen **„Navigation, 32 Aktualisierungen und 2 Befunde"**.

**Der Prüfkörper war die Zahl selbst.** Angelegt waren **drei** Befunde — zwei
`invalid` (`fail`) und einer `unreachable` (`unknown`). Das Abzeichen zeigt
**2**. Stünde dort 3, wäre der Filter wirkungslos, und zwar lautlos: Eine Zahl
sieht aus wie eine Zahl.

> **Eine Zählung, die filtert, wird an einer Zeile gemessen, die
> herausfallen muss — sonst misst man, dass sie zählen kann.**

Bei 390 px liegen beide Abzeichen zugeklappt bei `x = −63` und `x = −54`, also
genau dort, wo `docs/907 §6` das Abzeichen der Updates gefunden hat. Der Punkt
am Menüknopf ist die Antwort darauf, und er steht in dieser Lage da — jetzt für
**beide** Quellen, weil er sie zählt und nicht eine von ihnen liest.

### Zwei Dinge, die die Messung über sich selbst gesagt hat

**Der erste Lauf war nicht auszuwerten.** Vier Zeilen, Zeichen für Zeichen
gleich — und keine sagte, welches Thema sie gemessen hatte. Gedruckt wird
seitdem das gesetzte Attribut **und die Farbe, die daraus folgt**
(`light grund=rgb(255, 255, 255)` gegen `dark grund=rgb(15, 17, 22)`).

> **Eine Messung, die ihren Zustand nicht mitdruckt, ist von einer, die ihn
> nicht hatte, nicht zu unterscheiden.**

**Und der Ladebeleg steht in derselben Zeile:** `frame=grid` bei 1440 px,
`frame=flex` bei 390 px, `badge=999px` überall. Keiner der drei Werte ist ein
Vorgabewert — ein `<div>` ist `block`, und einen runden Rand hat von allein
nichts.

---

## §6 Was offen bleibt und benannt ist

- **`unknown` erscheint nicht im Abzeichen.** Antwortet der Agent nicht, sagt
  das jede Seite ohnehin; die elf `unreachable` bleiben trotzdem eine Auskunft,
  die das Abzeichen verschweigt. Das ist die Entscheidung des Betreibers und
  kein Versehen.
- **Auf einem Server ist keine Zahl dieser Runde gemessen.** Die Zeiten stammen
  aus SQLite im Container; `cloudsrv24` fährt MariaDB. `docs/907 §1.5` hat für
  dieselbe Art Abfrage 0,104 ms (SQLite) gegen 0,279 ms (MariaDB) gemessen —
  der Faktor ist bekannt, diese Zahl ist es nicht. §5 misst die **Anzeige** an
  einer echten Seite, und die hängt am Stylesheet und nicht am
  Datenbanksystem; die **Dauer** der Zählung tut es.
- **Die Zahl ist an zwei Befunden gemessen und nicht an zweihundert.** M7 sagt,
  dass der kurze Weg bei 500 Zeilen 0,095 ms kostet — das ist die Abfrage und
  nicht die Anzeige. Wie ein dreistelliges Abzeichen im Streifen eines echten
  Servers aussieht, ist im Nachbau gemessen (M9) und nicht an der Seite.
