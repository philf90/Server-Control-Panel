# 75 — Die Bilderrunde (P7, Schritt 9)

Der vorletzte Schritt von P7 (`docs/72 §8`). Er beantwortet **eine** Frage je
Ansicht und Zustand: Schiebt die Seite bei 390 px aus dem Bild, und sieht sie in
beiden Themes so aus, wie sie gemeint ist?

Dieses Dokument ist die Vorschrift. Das Protokoll entsteht **während** des Laufs
und nicht danach — und es bekommt seine Nummer erst, wenn die erste Ansicht
gemessen ist. Hier steht sie deshalb noch nicht: Ein Verweis auf ein Dokument,
das es nicht gibt, ist ein toter Verweis, und `DocLinkTest` besteht zu Recht
darauf. Er hat diesen Absatz beim Schreiben schon einmal rot gemacht.

**Er ist zugleich Punkt 8 des Abnahmekriteriums** (`docs/72 §3`): „Bei 390 px
läuft nichts über, in beiden Themes." Anders als die übrigen sieben ist dieser
Punkt hier fällig und nicht erst im Abnahmelauf.

> **Schritt 9 wird nicht abgehakt, wenn er gerade nicht geht.** `docs/49 §6`
> hält fest, was das einmal gekostet hat: `v0.4.0-rc.4` ist mit einem
> Umbruchfehler ausgeliefert worden, weil die Bilderrunde einen Tag zu spät kam,
> und die nachgeholte Runde fand auf einer einzigen Seite drei Fehler — jeden
> davon vollständig grün getestet.

---

## 1. Das Messmittel

**`tests/bilder-messen.js`, unverändert aus dem Zweig.** Es liegt seit dem
19. August als geprüfte Vorschrift im Repo, statt in jedem Lauf neu geschrieben
zu werden; `OverflowProbeTest` liest es. Was es zurückgibt und warum seine
Gegenprobe an `scrollWidth + 200` hängt, steht in `docs/63 §1` und wird hier
nicht wiederholt — zwei Fassungen derselben Beschreibung laufen auseinander.

Die eine Regel, die dieser Lauf von dort übernimmt und die schon einmal einen
halben Tag gekostet hat:

> **Ein Werkzeug, das nach jedem Neuladen aus der Zwischenablage kommt, ist so
> alt wie die Zwischenablage und sagt es nicht.**

Das Skript wird nach jedem Neuladen **frisch aus dem Zweig** geholt, und `stand`
gehört in jede Zeile des Protokolls.

---

## 2. Vorbereitung

Ohne diese Daten zeigen die Ansichten ihren leeren Zustand, und der ist nicht
der, um den es geht. **Der DNS-Teil gehört zuerst gemacht**, weil er als
einziger nicht sofort wirkt: Eine Änderung an der Zone braucht ihre TTL.

### 2.0 — Welche Fassung läuft?

`srvpanel --version` muss **`0.7.0-rc.2` oder neuer** melden. Auf `rc.1` fehlen
alle vier Behebungen aus `docs/74`, und drei davon sind genau die Zustände, von
denen hier Bilder entstehen sollen: die Unterscheidung „nicht gefragt" gegen
„ohne Antwort", der Zeitpunkt über `Clock`, und das Feld für die Übersteuerung.

> **Ein Bild von einem Zustand, der eine Fassung später anders aussieht, ist
> kein Beleg, sondern ein Andenken.**

### 2.1 — Die Messstrecke steht schon

Aus der Zwischenabnahme (`docs/74 §1`), und sie trägt vier der fünf Zustände
ohne weiteres Zutun:

| Name | Satz | Zustand |
|---|---|---|
| `cloudlab24.de` | `A`, `AAAA` | Zeigt hierher |
| `cloudlab24.ipv64.de` | `A` | Zeigt woandershin (`198.51.100.1`) |
| `cloudlab24.ipv64.de` | `AAAA` | Fehlt |
| `p6-abnahme.invalid` | — | Nicht gefragt (RFC 2606) |

Beide als Zusatzdomain unter dem Abonnement `p6-b.invalid`.

### 2.2 — Ein fremdes CAA (**Vorlaufzeit, deshalb zuerst**)

Beim DNS-Anbieter für `cloudlab24.de` setzen:

```
cloudlab24.de.   CAA   0 issue "digicert.com"
```

Das erzeugt den Zustand `refused` mit dem Satz „CAA erlaubt nur digicert.com.
Solange … nicht dabeisteht, scheitert jede Bestellung." — also **den einen
CAA-Fall, den das Panel überhaupt anzeigt** (`docs/72 §2.4`), und einen langen
Satz in einem `.notice warn`, was für die Überlauffrage der interessante Fall
ist.

**Der Eintrag wird nach dem Lauf wieder entfernt.** Solange er steht, scheitert
jede echte Bestellung für diese Domain — und jeder Fehlversuch zählt bei Let's
Encrypt gegen die fünf je Konto und Stunde, die für **alle** Kunden dieses
Servers zusammen gelten (`docs/34 §11`). Deshalb in dieser Reihenfolge: setzen,
Bilder machen, entfernen.

### 2.3 — Die Übersteuerung, die abweicht

Unter **Einstellungen → Allgemein → „Adressen dieses Servers"** eine Adresse
eintragen, die der Server nicht führt — `203.0.113.10` (RFC 5737, TEST-NET-3).

Das erzeugt zwei Dinge auf einmal: den Hinweis „Die eingetragenen Adressen …
sind andere als die, die dieser Server führt" im DNS-Bereich der Domain, **und**
die beiden Zeilen „Abgeleitet" / „Verglichen wird gegen" auf der
Einstellungsseite mit verschiedenen Werten.

**Danach wieder leeren.** Solange der Eintrag steht, meldet der Abgleich jede
Domain als „Zeigt woandershin" — was für ein Bild gebraucht wird und für den
Betrieb falsch ist.

### 2.4 — Was sich **nicht** herstellen lässt, und das wird benannt

| Zustand | warum nicht |
|---|---|
| `inconsistent` — „Nameserver uneinig" | Braucht zwei autoritative Server derselben Zone mit verschiedenen Antworten. Bei einem Anbieter mit synchronisiertem Bestand ist das nicht auszulösen. |
| „kein Sollzustand bekannt" | Tritt ein, wenn der Server **keine** öffentliche Adresse führt. Das hiesse, `cloudsrv24` seine Adressen zu nehmen. |

Beide bleiben ungemessen und stehen so im Protokoll. **Ein Zustand, den der
Lauf nicht herstellen konnte, gehört benannt und nicht weggelassen** — sonst
liest sich das Protokoll, als sei alles gesehen worden.

Für „Nameserver uneinig" gilt zusätzlich: Er steht schon in `docs/73 §5` als
nicht geprüft und wandert damit unverändert weiter in den Abnahmelauf.

---

## 3. Wovon Bilder gemacht werden

**Zwei Ansichten.** Jede in **beiden Themes** und bei **beiden Breiten** —
390 px und 1440 px. Das sind 8 Aufnahmen, und jede trägt ihre Messung daneben.

| # | Ansicht | Adresse |
|---|---|---|
| 1 | Domain mit DNS-Abgleich | `/domains/<id>` |
| 2 | Einstellungen → Allgemein | `/settings/general` |

Das ist wenig gegen die neun Ansichten aus `docs/63`, und es ist die richtige
Zahl: P7 hat genau zwei Stellen an der Oberfläche angefasst. Der Lauf lebt
deshalb nicht von den Ansichten, sondern von den **Zuständen** darunter.

### Und dazu die Zustände, die das Layout ändern

Diese **einmal bei 390 px** in einem Theme, mit Messung. Das Thema wechselt die
Farbe und nicht die Geometrie; die Überlauffrage ist eine Frage der Geometrie.
Wo ein Zustand einen eigenen *Kontrast* mitbringt — eine Marke in Rot, Gelb
oder Grau, eine Meldung —, kommt er zusätzlich im zweiten Theme dazu.

| Ansicht | Zustand | wie er entsteht |
|---|---|---|
| Domain | noch nie geprüft | eine frisch angelegte Zusatzdomain, **vor** dem ersten Lauf |
| Domain | vier Zeilen, vier Zustände | `cloudlab24.de` und `cloudlab24.ipv64.de` nach „Jetzt prüfen" |
| Domain | „Nicht gefragt" | `p6-abnahme.invalid` — die Meldung mit dem `.ident` darin |
| Domain | CAA abgewiesen | nach 2.2 — der lange Satz im `.notice warn` |
| Domain | Adressen weichen ab | nach 2.3 |
| Domain | Knopf während der Messung | „Jetzt prüfen" drücken und **währenddessen** aufnehmen („Wird geprüft …") |
| Domain | ohne den Knopf | als Kunde ohne die Freigabe — oder benannt auslassen |
| Allgemein | Übersteuerung leer | der Ausgangszustand, „Abgeleitet" und „Verglichen wird gegen" gleich |
| Allgemein | Übersteuerung gesetzt | nach 2.3 — die beiden Zeilen verschieden |
| Allgemein | Feld abgewiesen | `keine-adresse` eintragen und speichern — Meldung oben, `aria-invalid` am Feld |

**Der lange Name ist der Prüfkörper, der hier am ehesten etwas findet.** Ein
Zonenname darf 253 Zeichen tragen, ein einzelnes Label 63 — und die
Umbruchregel dafür (`.ident.name`) ist die Behebung von `docs/67` Befund 6.
Wenn eine Zusatzdomain mit einem Label nahe 63 Zeichen zur Hand ist, gehört sie
in die Tabelle; sonst wird das Fehlen benannt.

---

## 4. Der Ablauf je Aufnahme

Unverändert aus `docs/63 §4`, und die drei Regeln daraus, die dieser Lauf nicht
neu lernen muss:

1. Breite in der Geräteansicht auf **390 × 844** oder **1440 × 900**.
2. Adresse aufrufen und **neu laden** — nach jedem Wechsel der Breite, nicht nur
   einmal je Ansicht.

   > **Eine Messung nach einem Wechsel der Breite misst auch, was von vorher
   > übrig ist.**

3. In der Konsole, **flach ausgegeben und nicht als Objekt**:

   ```
   JSON.stringify(bilderMessen())
   ```

   > **Eine Zahl, die man aus einem eingeklappten Objekt abschreibt, hat man
   > nicht gemessen.**

4. Bild aufnehmen — **die ganze Seite**, nicht nur den sichtbaren Ausschnitt.
5. Thema umschalten (`window.srvpanelTheme('dark')` / `('light')`) und ab 3
   wiederholen.

---

## 5. Was ein Fund ist

- `dokument` grösser als 0 — die Seite schiebt. **Das ist das Kriterium.**
- `gegenprobe.ausschlag` ungleich `erwartet` — **dann ist die ganze Zeile
  ungültig** und wird nicht als Messung notiert.
- **und alles, was auf dem Bild falsch aussieht, ohne eine Zahl zu erzeugen.**

`schiebt` ist ein Hinweis und kein Urteil: Bei 390 px steht dort regelmässig
`thead` mit rund 350 px, und das ist `.stacks thead` — absichtlich aus dem Bild
genommen, damit der Screenreader die Spaltenüberschriften behält. Jeder Eintrag
wird einzeln beurteilt und im Protokoll benannt: gewollt oder Fund.

Die drei Sätze, die diesem Projekt Fehler gebracht haben, die vollständig grün
waren:

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.** Jedes Bild wird einmal *ohne* Frage
> angesehen, bevor die nächste Aufnahme kommt.

---

## 6. Was dieser Lauf ausdrücklich nicht prüft

- Die sieben übrigen Abnahmekriterien aus `docs/72 §3`. Sie gehören in
  Schritt 10, und **Kriterium 4** — dass eine Änderung sichtbar wird, bevor ein
  Auflöserzwischenspeicher sie hätte — ist der Punkt, der etwas beweist.
- Die beiden Zustände aus §2.4.
- Alles ausserhalb der zwei Ansichten. P7 hat sonst nichts an der Oberfläche
  angefasst; wer hier weitermisst, misst P6.

---

## 7. Wann der Schritt fertig ist

Wenn zu **jeder** Zeile aus §3 eine Messung im Protokoll steht — mit `stand`,
Lage, `dokument` und Gegenprobe —, jeder Eintrag in `schiebt` als gewollt oder
Fund benannt ist, und jeder Fund entweder behoben und **nachgemessen** oder mit
Begründung offen benannt.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**
