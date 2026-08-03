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
   (`html.menu-offen { overflow: hidden }`).

Die Seitenüberschrift steht in der Kopfzeile und darunter nicht noch einmal.
Die Beizeile bleibt — sie sagt etwas anderes.

## 5. Tabellen: zwei Muster, und nur diese zwei

Eine Tabelle mit sechs Spalten ist auf 390px keine Tabelle mehr. Welches
Muster richtig ist, hängt davon ab, was darin steht.

### `.rollt` — sie bleibt eine Tabelle und rollt waagerecht

Für **Messwerte**: Dateisysteme, Prozesse, Dienste. Dort steht in jeder Spalte
eine Zahl derselben Art, und der Vergleich *zwischen* den Zeilen ist der Zweck
der Ansicht. Aufgebrochen in Kärtchen wäre genau dieser Vergleich weg.

```html
<div class="rollt">
  <table> … </table>
</div>
```

### `.stapelt` — jede Zeile wird zu einem Kärtchen

Für **Verzeichnisse**: Kunden, Pläne, Vorgänge, Protokoll. Dort ist die Zeile
der Gegenstand, und man liest sie einzeln. Der Spaltenkopf verschwindet für
das Auge (nicht für den Screenreader); seinen Text trägt jede Zelle als
`data-spalte` bei sich.

```html
<table class="stapelt">
  <thead><tr><th>Nummer</th><th>Name</th></tr></thead>
  <tbody>
    <tr>
      <td data-spalte="Nummer">K10001</td>
      <td data-spalte="Name">Musterfirma</td>
      <td><button>Anmelden als</button></td>  <!-- ohne: eine Aktion -->
    </tr>
  </tbody>
</table>
```

Eine Zelle **ohne** `data-spalte` gilt als Aktion und steht linksbündig über
die volle Breite. Steht in einer Zelle mehr als ein Wert — Name, Marke und
Beschreibung zusammen —, bekommt sie zusätzlich `class="mehrzeilig"`:
Beschriftung und Inhalt stehen dann untereinander statt nebeneinander, sonst
rutscht der Rest an den rechten Rand und bricht dort um. Der Test prüft, dass in einer `.stapelt`-Tabelle jede Zelle
entweder ein `data-spalte` trägt oder ein Bedienelement enthält — eine Zelle,
die beides nicht hat, steht auf dem Telefon ohne Beschriftung da.

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
- Tabelle: `.rollt` oder `.stapelt` — eine dritte Antwort gibt es nicht.
- Knopfreihen: unter 480px untereinander statt nebeneinander.
- Nach dem Bauen einmal bei 390px ansehen. Das ist ein iPhone im Hochformat
  und die schmalste Fläche, die dieses Panel bedienen können muss.
