# Die schmale Fläche

Das Panel wird vom Telefon aus bedient — nicht als Hauptweg, aber an dem
Abend, an dem etwas nicht läuft und man nicht am Schreibtisch sitzt. Bis
August 2026 stand dort die Seitenleiste mit 186px neben einer Übersicht mit
204px: knapp die Hälfte des Bildschirms für ein Menü, daneben Kacheln,
Verlaufskurven und Tabellen mit sechs Spalten.

Dieses Dokument ist die Vorlage für jede neue Seite. Verbindlich, weil
geprüft: `tests/Feature/MobileLayoutTest.php`.

## 1. Zwei Haltepunkte, und kein dritter

| Wert | Bedeutung |
|---|---|
| **720px** | Seitenleiste wird Schublade, Kacheln einspaltig, Verzeichnisse stapeln |
| **480px** | auch zwei Felder nebeneinander gehen nicht mehr |

Beide stehen in `resources/css/app.css`. Ein dritter Wert, irgendwo in einer
Komponente erfunden, führt zu Seiten, die bei 640px umbrechen und bei 700px
nicht — und niemand weiß danach, welcher der richtige war. Der Test lässt nur
diese beiden durch.

## 2. Dichte und Tippziele

Unter 720px wechselt die Dichte unabhängig vom Kontotyp: `--row-height` auf
44px, `--tap` auf 44px. Das ist keine dritte Dichtestufe im Sinne von §7.2,
sondern dieselbe Achse an ihrem oberen Ende — auf einem Telefon bedient man
mit dem Finger, und 34px hohe Zeilen sind dort keine Dichte, sondern
Danebentippen.

**Jedes Bedienelement bekommt `min-height: var(--tap)`.** Ein Link in einer
Tabelle nicht — der ist Text und wird als Text gelesen.

## 3. `--text-input`: 16px, und zwar aus einem technischen Grund

Safari auf iOS zoomt die Seite hinein, sobald ein Eingabefeld mit weniger als
16px den Fokus bekommt — und zoomt danach nicht wieder heraus. Wer sein
Passwort eintippt, steht anschließend in einer vergrößerten Seite und schiebt
sie von Hand zurück.

`--text-input` ist deshalb die einzige Größe der Skala, die sich mit der
Fläche ändert: 13px am Schreibtisch, 16px auf dem Telefon. **Jedes `input`,
`select` und `textarea` benutzt sie** — ein Feld mit `var(--text-body)` ist
ein Feld, das zoomt.

## 4. Navigation: eine Schublade, keine zweite Navigation

Unter 720px liegt die Seitenleiste nicht mehr daneben, sondern als Schublade
darüber, geöffnet über die Kopfzeile. Es bleibt **dieselbe Komponente mit
denselben Einträgen**; eine eigene Navigation fürs Telefon wäre eine zweite
Stelle, an der jemand einen neuen Menüpunkt vergisst.

Drei Dinge, ohne die sich eine Schublade falsch anfühlt:

1. Sie schließt beim Seitenwechsel — sonst steht sie über der Seite, die man
   gerade geöffnet hat.
2. Sie schließt mit Escape und mit einem Tipp auf den Schleier.
3. Solange sie offen ist, rollt die Seite darunter nicht mit
   (`html.menu-open { overflow: hidden }`).

Die Seitenüberschrift steht in der Kopfzeile und darunter nicht noch einmal.
Die Beizeile bleibt — sie sagt etwas anderes.

### Das Gerüst ist unter 720px eine Spalte und kein Raster

Auf der breiten Fläche ist `.frame` ein Raster: Seitenleiste links, Inhalt
rechts, und darüber, über die volle Breite, das Band aus §6.3. Auf der schmalen
Fläche blieb es zunächst ein Raster mit einer Spalte und zwei Zeilen — `auto`
für die Kopfzeile, `1fr` für den Inhalt.

**Das ging genau so lange, wie es zwei Kinder im Fluss gab.** Wer in die Sicht
eines Kunden wechselt, bekommt das Band dazu, und damit sind es drei: Band in
Zeile eins, **Kopfzeile in die `1fr`-Zeile** — und die nimmt sich allen übrigen
Platz. Auf einem Telefon mit 844px Höhe war die Kopfzeile 591px hoch, zwischen
Band und Seitentitel stand eine leere schwarze Fläche, und der Inhalt landete in
einer Zeile, die es im Raster gar nicht gab. Am Schreibtisch sieht man das nie:
Dort gilt die Regel nicht, und ohne „Anmelden als" gibt es das dritte Kind
nicht.

Eine dritte Zeile wäre die falsche Antwort — dann zählt man Kinder, und beim
nächsten Band zählt jemand falsch. Unter 720px gibt es eine Spalte, und die
Schublade steht ohnehin `fixed`: Gebraucht wird ein Flexcontainer von oben nach
unten. Der hat keine Zeilen, die man verzählen könnte.

`MobileLayoutTest` hält es fest: `.frame` ist unter 720px `display: flex` und
setzt keine `grid-template-rows`.

## 5. Tabellen: drei Muster, und nur diese drei

Eine Tabelle mit sechs Spalten ist auf 390px keine Tabelle mehr. Welches
Muster richtig ist, hängt davon ab, was darin steht.

> **Bis August 2026 standen hier zwei.** Das dritte kam mit dem Rework der
> Optik dazu und ist keine Aufweichung, sondern eine Lücke, die vorher jede
> Seite selbst gefüllt hat: die Tabelle aus **Bezeichnung und Wert** —
> Kontingente, Freigaben, Dienste. Sie passt auf 390px, sie muss also weder
> rollen noch zu Kärtchen zerfallen; ihr fehlte nur eine Regel, was mit dem
> Wert geschieht, wenn eine Zustandsmarke in der dritten Spalte ihn
> zusammendrückt. Ohne diese Regel stand „3 von 10" auf drei Zeilen.

### `.scrolls` — sie bleibt eine Tabelle und rollt waagerecht

Für **Messwerte**: Dateisysteme, Prozesse, Dienste. Dort steht in jeder Spalte
eine Zahl derselben Art, und der Vergleich *zwischen* den Zeilen ist der Zweck
der Ansicht. Aufgebrochen in Kärtchen wäre genau dieser Vergleich weg.

```html
<div class="scrolls">
  <table> … </table>
</div>
```

### `.stacks` — jede Zeile wird zu einem Kärtchen

Für **Verzeichnisse**: Kunden, Pläne, Vorgänge, Protokoll. Dort ist die Zeile
der Gegenstand, und man liest sie einzeln. Der Spaltenkopf verschwindet für
das Auge (nicht für den Screenreader); seinen Text trägt jede Zelle als
`data-column` bei sich.

```html
<table class="stacks">
  <thead><tr><th>Nummer</th><th>Name</th></tr></thead>
  <tbody>
    <tr>
      <td data-column="Nummer">K10001</td>
      <td data-column="Name">Musterfirma</td>
      <td><button>Anmelden als</button></td>  <!-- ohne: eine Aktion -->
    </tr>
  </tbody>
</table>
```

Eine Zelle **ohne** `data-column` gilt als Aktion und steht linksbündig über
die volle Breite. Steht in einer Zelle mehr als ein Wert — Name, Marke und
Beschreibung zusammen —, bekommt sie zusätzlich `class="multiline"`:
Beschriftung und Inhalt stehen dann untereinander statt nebeneinander, sonst
rutscht der Rest an den rechten Rand und bricht dort um. Der Test prüft, dass in einer `.stacks`-Tabelle jede Zelle
entweder ein `data-column` trägt oder ein Bedienelement enthält — eine Zelle,
die beides nicht hat, steht auf dem Telefon ohne Beschriftung da.

### `.pairs` — Bezeichnung links, Wert rechts

Für **Bezeichnung und Wert**: Kontingente, Freigaben, Dienste, Bestand. Zwei
oder drei Spalten, und die erste ist immer die Beschriftung der zweiten.

**Warum das kein `.stacks` ist.** `.stacks` macht aus jeder *Zeile* ein
Kärtchen, weil die Zeile der Gegenstand ist — ein Kunde, ein Vorgang. Bei
einer Paartabelle ist die Zeile schon nur ein Paar; sie zu einem Kärtchen mit
zwei beschrifteten Feldern aufzublasen, verdoppelte die Beschriftung
(„KONTINGENT: Domains / STAND: 3 von 10") und dreifachte die Höhe.

Auf der schmalen Fläche wird jede Zeile zu einer Flexzeile: Bezeichnung links,
Wert rechts und ohne Umbruch, und eine Zustandsmarke rutscht in die nächste
Zeile, wenn sie nicht mehr danebenpasst. Ohne das drückte die Marke den Wert
so weit zusammen, dass „3 von 10" auf drei Zeilen stand.

Breit bekommt sie eine Grenze von 620px: Steht der Bereich allein in seiner
Reihe, lägen Bezeichnung und Wert sonst an den gegenüberliegenden Rändern —
gemessen 1300px auseinander.

**Und eine Kennung in der Wertspalte braucht `flex: 1 1 auto; min-width: 0`.**
`.ident` trägt `white-space: nowrap`, weil ein umgebrochener Pfad keiner
mehr ist; in einer Paarzeile hiess das auf `/domains/1` bei 390px **81px
waagerechter Überlauf**. Der naheliegende Versuch — `white-space: normal;
overflow-wrap: anywhere` — liess ihn auf exakt denselben 81px stehen: In einer
Flexzeile hält `flex: none` die Inhaltsbreite, ganz gleich wie umgebrochen
werden darf. Erst das Recht, schmaler zu werden als der eigene Inhalt, löst
das. Zwei Messungen, ein Fund — der erste Fix sah aus wie einer und war
keiner.

```html
<table class="pairs">
  <tbody>
    <tr>
      <td>Domains</td>
      <td class="rechts">3 von 10</td>
      <td class="rechts"><Marke art="warn">abweichend vom Plan</Marke></td>
    </tr>
  </tbody>
</table>
```

**Was keine Tabelle ist, wird auch keine.** Der Aufgabenkatalog der
Vorgangsseite sah aus wie eine Paartabelle — Name, Beschreibung, ein Knopf —
und ist eine Liste von Dingen, die man tun kann. Er steht als `<ul>` da. Die
Frage vor der Wahl des Musters ist, ob die Daten überhaupt tabellarisch sind.

### Blättern

Unter jedem Verzeichnis steht `.pager` — drei Spalten: „Zurück", „Seite N von
M", „Weiter". Die äusseren halten ihre Breite auch dann, wenn kein Knopf darin
steht; sonst rutschte die Angabe in der Mitte um eine Knopfbreite, sobald man
von Seite 1 weiterblättert.

**Er stapelt nicht.** `.button-row` legt seine Knöpfe unter 480 px
untereinander und über die volle Breite — der Pager tut das ausdrücklich
nicht: Drei kurze Elemente passen bei 390 px nebeneinander (gemessen: rund
300 px), und ein „Weiter" über die ganze Breite sähe aus wie die Hauptsache
der Seite.

## 6. `dvh` statt `vh`

`vh` zählt auf einem Telefon die Adressleiste mit, die beim Rollen
verschwindet. Eine Seite mit `100vh` steht deshalb im Ausgangszustand zu hoch
— man rollt, obwohl nichts zu rollen wäre. Überall `dvh`.

## 7. Sichere Bereiche

Kopfzeile, Schublade und der untere Rand des Inhalts rechnen
`env(safe-area-inset-*)` auf ihren Abstand. Ohne das liegt die unterste Zeile
unter der Leiste des Browsers oder unter dem Bedienbalken des Geräts.

## 8. Was beim Bauen einer neuen Seite zu tun ist

- Formularfelder: `--text-input`, volle Breite unter 720px, `min-height: var(--tap)`.
- Tabelle: `.scrolls` oder `.stacks` — eine dritte Antwort gibt es nicht.
- Knopfreihen: unter 480px untereinander statt nebeneinander.
- Nach dem Bauen einmal bei 390px ansehen. Das ist ein iPhone im Hochformat
  und die schmalste Fläche, die dieses Panel bedienen können muss.
