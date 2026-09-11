# Ein Abzeichen am Menüpunkt „Updates"

**Geschrieben am 11. September 2026, nach der Messrunde.** Gemeldet hat es der
Betreiber an einem Bildschirmfoto: Auf `/updates` stehen 32 Aktualisierungen,
und die Navigation daneben sagt darüber nichts.

**Die Nummer kommt aus dem 900er-Block** (`docs/900`): Dieses Merkmal gehört
keiner Stufe — P7b ist durch, P8 hat noch keinen Plan —, und eine Nummer in der
laufenden Zählung stünde zwischen zwei Abnahmeläufen. Dieselbe Überlegung wie
bei `docs/904`.

---

## §1 Was die Messrunde entschieden hat

Fünf Messungen, und **vier davon haben den Entwurf verändert**, bevor eine Zeile
Code entstand.

### 1.1 Die Zahl live zu holen ist ausgeschlossen, und zwar um Grössenordnungen

| | gemessen |
|---|---|
| `system.packages.list` — die Zahl holen | **2954–3644 ms** (`cloudsrv24`, `docs/906 §2`) |
| `Setting::find()` — die Zahl abgelegt lesen | **0,104 ms**, eine Abfrage |

Die Navigation steht auf **jeder** Seite. Damit fallen zwei der drei denkbaren
Wege sofort, und keiner davon ist Geschmackssache:

- **Live fragen** macht jede Seite des Panels drei Sekunden langsam — das
  Gegenteil dessen, was `docs/904` gerade erreicht hat.
- **In `share()` nachreichen** setzt bei *jedem* Seitenaufruf einen apt-Lauf ab.
  Zwei davon enden in der dpkg-Sperre, die `AptLock` seit A1 Schritt 2 abfragt.

Bleibt der dritte: **abgelegt, von einem Einsammler gefüllt.**

> **Ein Wert, dessen Beschaffung dreitausendmal teurer ist als sein Lesen,
> gehört nicht in eine Leiste, die auf jeder Seite steht.**

### 1.2 Der Streifen trägt keine Zustandsfarbe — und das steht in `app.css`

Gemessen: `--nav-bg` ist **in beiden Themen** `#1a0b2e`. Der Streifen ist kein
Stück Seite, sondern eine Fläche mit **acht eigenen Marken** (`.rail, .topbar`),
und `--warn` gehört nicht dazu. Ein `.badge.warn` dort liest die Farbe der
*Seite* und setzt sie auf einen fremden Grund:

| Fassung | hell | dunkel |
|---|---|---|
| `.badge.warn` im Streifen | **2,67:1** — fällt durch | 7,04:1 |
| `--accent` auf `--accent-surface` des Streifens | **8,42:1** | **8,42:1** |

Die 8,42 sind **zweimal unabhängig da**: einmal gemessen, einmal im Kommentar
von `app.css`, der sie für die Fläche des aktiven Menüpunkts schon nennt.

Derselbe Kommentar sagt die Regel, sechzig Zeilen über der Stelle:

> **Eine Markenfläche endet dort, wo eine Zustandsfarbe anfängt — sonst ist sie
> keine Fläche, sondern ein zweites Theme.**

**Damit braucht das Abzeichen keine neue Farbe und keine neunte Marke** — es
nimmt die, die der Streifen ohnehin führt. Und das passt zur Entscheidung des
Betreibers: Gezeigt werden **alle** Aktualisierungen, nicht nur die
sicherheitsrelevanten. Peach sagt „hier ist etwas", nicht „Warnung" — und das
ist genau die Aussage, die zu der Zahl gehört.

### 1.3 Die breite Ansicht ist die engere

| Breite | Schiene | je Eintrag frei |
|---|---|---|
| 1440 px | **236 px** (`grid-template-columns: 236px 1fr`) | **203 px** |
| 390 px | **272 px** (`min(272px, 82vw)`) | **239 px** |

> **Ein Fehler, den nur die breite Ansicht hat, entgeht einer Prüfung, die auf
> die schmale zielt.** Zum dritten Mal nach dem A9-Lauf und A14.

Gemessen an drei Zuständen des Eintrags „Updates":

| | 1440 px | 390 px |
|---|---|---|
| ohne Abzeichen | Höhe **39** | Höhe **44** |
| `.badge` wie sie ist (50 px breit) | Höhe **44** (+5) | Höhe 44 (**±0**) |
| dieselbe mit zwölf Stellen (141 px) | **28 px Überlauf** | kein Überlauf |
| **ohne Punkt, `padding: 0 8px`** (34 px breit) | Höhe **39** (±0) | Höhe 44 (±0) |

Zwei Dinge stehen damit fest. Erstens: Bei 390 px ist das Abzeichen **gratis** —
der Eintrag liegt dort ohnehin auf dem Mindestmass von `--tap`. Zweitens: Bei
1440 px kostet `.badge` in ihrer heutigen Form **5 px Höhe an genau einem
Eintrag**, und der steht dann höher als seine siebzehn Nachbarn.

**Die 5 px kommen nicht vom Umbruch, sondern vom Punkt.** `.badge::before` ist
ein 6-px-Kreis mit `gap: 6px`, und `padding: 3px 10px` macht die Marke 26 px
hoch gegen eine Zeilenhöhe von 21. Ohne den Punkt und mit `padding: 0 8px` misst
sie **34 × 20 px**, und der Eintrag bleibt bei 39.

Der Platz reicht bis zu einem Abzeichen von rund **113 px**, also etwa acht
Stellen. Ein Server mit 32 offenen Paketen ist weit davon entfernt; ein Server,
der es nicht wäre, hätte ein anderes Problem.

**Der Seitenüberlauf ist in allen vier Lagen `dokument = 0`**, Gegenprobe 200 —
gemessen mit `tests/bilder-messen.js` aus dem Repo.

### 1.4 Und zwei eigene Prüfkörper haben je die Hälfte gesehen

**Die erste Messung war die falsche Dimension.** Sie fragte
`scrollWidth - clientWidth` je Eintrag und meldete für jeden `0`. Der Grund:
`.nav-item` ist eine Flexzeile, und der Text ist ein anonymes Flexkind, das
**umbricht** statt überzulaufen. Der Schaden hätte keine Breite gehabt, sondern
eine Höhe.

> **Ein Prüfkörper, der die Breite misst, sieht einen Umbruch nicht — der macht
> den Kasten höher und nicht breiter.**

**Die zweite Messung hatte eine Gegenprobe, die nicht ausschlug.** Ein Abzeichen
mit zwölf Stellen ergab dieselben 44 px wie eines mit zwei — weil es nicht
umbricht, sondern überläuft. Erst **beide** Messungen nebeneinander zeigen
sowohl den Überlauf (28 px) als auch die Höhe (39 → 44).

> **Zwei Messungen, von denen die eine den Schaden manchmal sieht, ersetzen
> einander nicht.** Derselbe Satz wie in `docs/108`, dort an einer
> Kennungszelle.

**Und der Aufsatz brauchte das `data-v`-Attribut.** `.nav-item` steht in einem
`<style scoped>` von `PanelLayout.vue`; Vite übersetzt das zu
`.nav-item[data-v-219b02fc]`. Ohne das Attribut am Markup hätte der Aufsatz
**keine einzige** Navigationsregel getroffen und trotzdem Zahlen geliefert.

### 1.5 Ein Verschluss und kein fertiger Wert

| `/services` | Abfragen | Dauer |
|---|---|---|
| voll | **3** | 25,1 ms |
| partiell nachgeladen | **2** | 2,7 ms |

Dieselbe Form wie `docs/103`: Ein **fertiger** Wert in `share()` läuft auch bei
einem partiellen Nachladen, das ihn gar nicht mitschickt; ein **Verschluss**
nicht. Bei 2,7 ms für ein partielles Nachladen wäre eine zusätzliche Abfrage
rund vier Prozent — vermeidbar, also vermieden.

**Die Grenze dieser Zahlen:** Sie sind gegen **SQLite** im Container gemessen,
der Server läuft MariaDB. Es sind Untergrenzen und keine Serverwerte. Was sie
tragen, ist das Verhältnis — und das ist vierstellig.

> **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
> Grenzen der falschen.**

---

## §2 Die drei Entscheidungen des Betreibers

1. **Im Abzeichen steht die Zahl aller aktualisierbaren Pakete** — hier 32 —,
   nicht nur die der sicherheitsrelevanten.
2. **Eingesammelt wird nach jeder Änderung und zusätzlich stündlich.** Der
   stündliche Lauf ist der Rückfall für das, was ausserhalb des Panels
   geschieht — `unattended-upgrades` arbeitet auf eigenem Takt.
3. **Kein Flimmerschutz für den Platzhalter.** Die 300 ms aus `docs/904 §2`
   bleiben eine Planungsregel („was wird nachgereicht") und werden kein
   Mechanismus zur Laufzeit.

---

## §3 Der Bau

### 3.1 Wo die Zahl herkommt

Ein Einsammler schreibt `Setting` mit der Zahl **und dem Zeitpunkt**. Der
Zeitpunkt ist kein Beiwerk:

> **Ein Wert, der aus „jetzt" folgt und abgelegt wird, ist ab dem nächsten
> Augenblick falsch — die Frage ist nur, wie schnell es auffällt.**

Das ist die Narbe aus `docs/108`: `next_due` war eine Spalte, die einen falschen
Wert ein Jahr lang über eine Behebung hinweggetragen hat. Ein Abzeichen, das
„32" sagt, während null anstehen, ist dieselbe Form.

### 3.2 Wann eingesammelt wird

**„Nach jeder Änderung" ist nicht „nach jedem Klick".**
`system.packages.upgrade` ist **Form A** aus `docs/86 §5`: Es *setzt ab* und
kennt den Ausgang nicht. Der Einsammler hängt deshalb am **beendeten Lauf** und
nicht am Knopf — `AwaitDispatchedRun` gibt es seit A1 genau dafür. Am Knopf
gehängt schriebe er die alte Zahl in dem Augenblick fest, in dem sie sich
gerade ändert.

Dazu ein stündlicher Timer. **Er fragt vor dem Lauf `AptLock`** — er ruft apt,
und ein Lauf, der in ein laufendes Upgrade fährt, ist genau die Kollision, die
A1 Schritt 2 beseitigt hat.

### 3.3 Was die Navigation liest

Ein **Verschluss** in `HandleInertiaRequests::share()` (§1.5), und der Wert
trägt die Fähigkeit seiner Route: Der Menüpunkt „Updates" steht auf
`inspect-server`, das Abzeichen darf nicht weiter reichen.
`AdminPayloadTest` hält, dass jeder Menüpunkt die Fähigkeit seiner Route trägt.

### 3.4 Wie es aussieht

Eine Marke aus den Marken des Streifens — `--accent` auf `--accent-surface`,
**8,42:1** —, ohne den Punkt und mit `padding: 0 8px`, damit der Eintrag seine
39 px behält. Rechts ausgerichtet über `margin-left: auto`: `.nav-item` ist eine
Flexzeile mit `gap: 10px` und **ohne** `justify-content: space-between`, ein
drittes Kind klebte sonst am Wort.

---

## §4 Was benannt offen bleibt

- **Der Wert gegen MariaDB.** §1.5 ist gegen SQLite gemessen.
- **Was das Abzeichen tut, wenn der Wert alt ist.** Die Frage ist gestellt und
  nicht beantwortet; ein Vorschlag steht in §3.1 (Zeitpunkt daneben), die
  Entscheidung nicht.
- **Ob der Einsammler eine eigene Unit bekommt** oder an einer bestehenden
  hängt. `srvpanel-diagnose.timer` läuft `daily` und ist damit zu grob;
  `srvpanel-usage.timer` läuft `*:0/15` und wäre für apt zu dicht.

---

## §5 Was dieses Merkmal ausdrücklich **nicht** wird

- **Kein zweites Abzeichen an einem anderen Menüpunkt.** Die Regel aus §1.2
  gilt für jedes: Der Streifen trägt keine Zustandsfarbe, und wer dort eine
  zweite Zahl unterbringen will, misst sie zuerst.
- **Keine Zahl, die live geholt wird** — §1.1.
- **Keine Automatik, die Updates einspielt.** Das Abzeichen zeigt und handelt
  nicht.
