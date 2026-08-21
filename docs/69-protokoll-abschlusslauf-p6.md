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
| Fassung | *(Punkt 0 trägt sie hier ein)* | |
| Server | `cloudsrv24` | |
| Abonnement | 140, `p6-abnahme.invalid`, Systembenutzer `p1139` | |
| Messmittel | `tests/bilder-messen.js`, Stand 2026-08-21 | für Punkt 3 |

**Wofür es diesen Lauf gibt** steht in `docs/68 §0`: P6 ist gebaut und sein
Abnahmekriterium gemessen — offen sind vier benannte Reste und die
Abnahmeerklärung. Diese Datei wird die Erklärung, **wenn** die Punkte sie
tragen.

---

## 1. Die Punkte

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 0 | Die Fassung | `0.6.0-rc.24` | — | **offen** |
| 1 | Die Archive durch die echte Route | nichts ausserhalb, `beweis` drinnen | — | **offen** |
| 2 | Durch einen Verweis hinaus schreiben | Ziel unverändert, Gegenprobe schreibt | — | **offen** |
| 3 | Die Umbruchregel auf zwei weiteren Seiten | `dokument: 0`, `schiebt` nur `.stacks thead` | — | **offen** |
| 4 | `id` am Vorgang | `ran_as.uid` = `id -u p1139` | — | **offen** |
| 5 | Die Rechte an `/var/lib/srvpanel` | `750 srvpanel:srvpanel`, auch nach dem Neustart | — | **offen** |

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
