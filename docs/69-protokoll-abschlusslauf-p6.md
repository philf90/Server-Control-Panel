# Protokoll des Abschlusslaufs für P6

*Angelegt am 21. August 2026, **vor** dem ersten Punkt. Der Lauf ist `docs/68`.*

> **Hier ist noch nichts gemessen.** Diese Datei ist das Gefäss, in das der Lauf
> schreibt — Punkt für Punkt, **während** er fährt und nicht danach. Wer sie
> heute liest, findet Erwartungen und keine Ergebnisse.

---

## 0. Rahmen

| | | |
|---|---|---|
| Lauf | **`docs/68`** | fünf Punkte plus die Rahmenprüfung |
| Fassung | **`0.6.0-rc.24`** | gemessen, Punkt 0 |
| Server | `cloudsrv24` | |
| Abonnement | 140, `p6-abnahme.invalid`, Systembenutzer `p1139` = **uid 1002** | |
| Messmittel | `tests/bilder-messen.js`, Stand 2026-08-21 | für Punkt 3 |

**Wofür es diesen Lauf gibt** steht in `docs/68 §0`: P6 ist gebaut und sein
Abnahmekriterium gemessen — offen sind vier benannte Reste und die
Abnahmeerklärung. Diese Datei wird die Erklärung, **wenn** die Punkte sie
tragen.

---

## 1. Die Punkte

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 0 | Die Fassung | `0.6.0-rc.24` | `0.6.0-rc.24` / `0.6.0~rc.24` | **erfüllt** |
| 1 | Die Archive durch die echte Route | nichts ausserhalb, `beweis` drinnen | — | **offen** |
| 2 | Durch einen Verweis hinaus schreiben | Ziel unverändert, Gegenprobe schreibt | — | **offen** |
| 3 | Die Umbruchregel auf zwei weiteren Seiten | `dokument: 0`, `schiebt` nur `.stacks thead` | — | **offen** |
| 4 | `id` am Vorgang | `ran_as.uid` = `id -u p1139` | — | **offen** |
| 5 | Die Rechte an `/var/lib/srvpanel` | `750 srvpanel:srvpanel`, auch nach dem Neustart | — | **offen** |

---

## 1a. Punkt 0 — welche Fassung läuft

**Gemessen am 21. August 2026 auf `cloudsrv24`:**

    srvpanel --version          0.6.0-rc.24
    dpkg -l srvpanel | tail -1  ii  srvpanel  0.6.0~rc.24  amd64

**Die beiden Schreibweisen sind dieselbe Fassung.** `dpkg` verlangt eine
Ordnung, in der `rc` **vor** der Freigabe steht, und `-` trennt dort schon die
Paketrevision ab; die Tilde ist das Zeichen, das kleiner sortiert als das
Nichts. `0.6.0~rc.24` liegt damit vor `0.6.0`, `0.6.0-rc.24` läge dahinter.

Und die Namen des Abonnements:

    /var/www/vhosts/p6-abnahme.invalid — uid=1002(p1139) gid=1002(p1139) groups=1002(p1139)

### Und eine Zahl, die schon hier etwas über Punkt 4 sagt

**`p1139` trägt `uid 1002` — und in `docs/62` trug `p1138` dieselbe 1002.**

Dort war sie der Beleg dafür, dass zwei Abonnements zwei Kennungen ergeben:
`p1136` → 1001, `p1138` → 1002. Heute gehört die 1002 einem anderen Namen. Ob
`p1138` weg ist und die Kennung nachrückte oder ob es sie doppelt gibt, ist
**nicht gemessen** und steht in Punkt 4.

Für den Lauf ändert das nichts und für seine Lesart einiges:

> **Eine Kennung, die einen Namen belegt hat, belegt ihn nicht auf Dauer** —
> ein `uid`, den man aus einem alten Protokoll mitnimmt, gehört einem
> Abonnement von damals.

Genau deshalb vergleicht Punkt 4 `id` und `ran_as` **im selben Augenblick** und
nicht gegen eine Zahl aus `docs/62`.

---

## 2. Die Befunde

*(Je Befund: was gesehen wurde, welche Zahl dazugehört, und ob er am Panel, am
Prüfmittel oder am Kriterium liegt. Noch keiner.)*

### Befund 0 — der Wächter über die Dokumente hat diesen Lauf eröffnet

**Er gehört hierher, weil er entstanden ist, bevor der Lauf begann**, und weil
er dieselbe Form hat wie die Befunde, die dieser Lauf sucht.

`docs/68` nannte `docs/69`, und das gab es nicht. `DocLinkTest` hat die CI rot
gemacht — der Wächter für genau den Fehler, der in diesem Projekt am häufigsten
wiederkehrt: *eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein
Test oder ein Werkzeug den Bezug prüft.*

**Und die Behauptung daneben war falsch.** Der Text des Beitrags sagte „Kein
Wächter liest `docs/*.md` — nachgesehen mit `grep -rln "docs/" tests/`". Der
Befehl lief mit `head -20`; er fand **177** Dateien, und `DocLinkTest` steht
nicht unter den ersten zwanzig.

> **Eine abgeschnittene Liste, die man als vollständige liest, ist keine
> Messung, sondern ihre Verkleidung.**

Das ist die vertraute Falle aus einer neuen Richtung: Bisher hiess sie „eine
Null ist nur dann eine Messung, wenn daneben etwas anderes als Null steht". Hier
stand kein Null da, sondern **zwanzig Zeilen, die alle stimmten** — und die
einundzwanzigste war die, auf die es ankam.

> **Ein Ausschnitt, der nicht sagt, dass er einer ist, beantwortet die Frage,
> als wäre er alles.**

**Und die teuerste Hälfte: Der Wächter war hier fahrbar.** `DocLinkTest` erbt
von `PHPUnit\Framework\TestCase` und läuft damit im Gestell dieses Containers,
ohne `vendor/`. Gefahren worden ist er nicht — weil ich an Dokumente gedacht
habe und nicht an die Wächter über Dokumente.

> **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
> gefahren — man denkt an das Gebaute und nicht an das Berührte.**

Derselbe Satz steht seit dem 20. August in `CLAUDE.md`, dort über `app.css`.
Hier hat er ein zweites Feld: **Wer `docs/` anfasst, fährt `DocLinkTest`.**

**Behoben:** Diese Datei gibt es jetzt. Der echte Wächter ist im Gestell
gefahren — grün mit ihr, und mit derselben Meldung wie die CI rot, sobald sie
wieder verschwindet:

    ohne docs/69   ROT  docs/68-abschlusslauf-p6.md nennt docs/69, dieses
                        Dokument gibt es nicht.
    mit  docs/69   2 gruen, 0 rot

Der Wächter hat getan, wofür er da ist — an einem Beitrag, der ausdrücklich
behauptet hat, es gebe ihn nicht.

---

## 3. Was offen bleibt

*(Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme. Hier steht am
Ende, was der Lauf nicht geschlossen hat — heute ist das alles.)*

Benannt und **nicht** Gegenstand dieses Laufs (`docs/68 §9`): Wand 2 aus Punkt
11 und Befund 23 aus `docs/59`, die neunzehn Griffe in `RevealTest::UNEXAMINED`,
die vollständige Umkehrung der Abstandsregel.

---

## 4. Wann P6 abgenommen ist

Die Bedingung steht in `docs/68 §10` und wird hier nicht zweimal geschrieben —
zwei Fassungen derselben Regel laufen auseinander, und die zweite ist die, die
veraltet.

**Heute ist sie nicht erfüllt.**
