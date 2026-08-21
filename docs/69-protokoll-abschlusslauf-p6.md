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
| 1 | Die Archive durch die echte Route | nichts ausserhalb, `beweis` drinnen | nichts ausserhalb, `beweis` drinnen, Gegenprobe trifft | **erfüllt** |
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

## 1b. Punkt 1 — die beiden Archive durch die echte Route

**Gemessen am 21. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.24`**, angemeldet
als Kunde des Abonnements 140. Der Weg war der echte: Panel → `can:editFiles` →
`FileController::extract()` → `Workspace::path()` → Sandbox. In `docs/62` ging
derselbe Angriff über den Weg der Operation; hier steht die **erste** der beiden
Wände aus `docs/61 §1` mit im Bild.

### Der Prüfkörper war scharf

Die Auflistung der Archive zeigte den Ausbruchspfad in den Mitgliedsnamen:

    raus-relativ.tar   ../../…/../tmp/getroffen-relativ   +  beweis
    raus-absolut.tar   /tmp/getroffen-absolut             +  beweis

`tar` warnte beim Bauen selbst mit `Removing leading '../' / '/' from member
names` — das ist der Beleg, dass die Namen aus dem Verzeichnis herausführen, und
nicht das Gegenteil.

### Die Messung

**Je Archiv im Panel „Aktionen → Entpacken", angemeldet als Kunde.** Zweimal
dieselbe Meldung, wörtlich:

> Das Archiv ist entpackt — 1 Einträge, 1 übergangen, weil sie aus dem
> Zielverzeichnis herausführen.

Danach auf dem Server:

| | erwartet | gemessen |
|---|---|---|
| `/tmp/getroffen-relativ` | nicht da | **No such file or directory** |
| `/tmp/getroffen-absolut` | nicht da | **No such file or directory** |
| `find $WURZEL -name 'getroffen*'` | keine Zeile | **keine Zeile** |
| in `ziel` | `beweis` liegt da | **`beweis`** + die zwei `.tar` |

### Die zwei Gegenproben

**Nach innen:** `beweis` ist in `ziel` gelandet — das Archiv hat also etwas
abgeliefert, und die Abwesenheit der `getroffen`-Dateien ist eine Wand und kein
leeres Archiv.

**Nach aussen:** Dieselben Tarballs von `tar -xPf` entpackt legten
`/tmp/getroffen-relativ` und `/tmp/getroffen-absolut` an (je 10 Byte,
`root:root`). Was das Panel abgewiesen hat, schreibt `tar` ohne die Sandbox —
der Angriff trifft also, sobald die Wand fehlt.

> **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**
> Dieser trifft — nur nicht durch das Panel.

Beide Treffer sind sofort wieder entfernt worden (`rm`, `ls` meldet danach
`No such file or directory`), damit sie in Punkt 2 nicht als Bestand mitmessen.

> **Ein Prüfkörper, den man stehen lässt, ist beim nächsten Lauf Bestand.**

### Ein Nebenbeleg, den kein Kriterium bestellt hat

`beweis` trägt `p1139 www-data`, die zwei `.tar` tragen `p1139 p1139`. Der
Unterschied ist keine Abweichung: Die Archive hat `install -g p1139` von Hand
hingelegt, **`beweis` hat die Sandbox geschrieben** — und die erbt die Gruppe
`www-data` vom setgid-Bit auf `httpdocs` (Schritt 6c, `docs/51 §8.4`).

> **Das Entpacken legt eine Datei an wie jeder andere Weg des Panels** — mit der
> Gruppe des Verzeichnisses, nicht der des Erzeugers. Genau das war der Sinn von
> Befund 3 der Zwischenabnahme.

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
