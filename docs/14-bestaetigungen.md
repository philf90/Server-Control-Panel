# 14 — Rückfragen vor zerstörenden Aktionen

Wie das Panel fragt, bevor etwas weg ist — und warum die Prüfung im Handler
steht und nicht im Browser.

## Der Befund: dreizehn Rückfragen, keine einzige hat gefragt

Bis 0.3.0-rc.5 stand die Rückfrage in einem Attribut:

```html
<form method="post" action="/users/2/delete"
      onsubmit="return confirm('Konto kollege wirklich löschen?')">
```

Das hat nie funktioniert. Die Content-Security-Policy des Panels ist
`script-src 'self'` ohne `'unsafe-inline'`, und ein Inline-Handler ist für den
Browser genau das: ein Inline-Skript. Im Browser nachgemessen, Knopf „löschen"
auf *Panel-Zugänge*:

```
onsubmitAttribut: "return confirm('Konto zweiter wirklich löschen?')"
handlerGesetzt:   false          ← der Browser hat es nie kompiliert
dialoge:          0              ← kein Dialog
verstoesse:       "Refused to execute inline event handler because it violates
                   the following Content Security Policy directive:
                   script-src 'self'"
url:              /users/2/delete   ← das Konto war weg, ein Klick
```

Dreizehn Formulare waren so gebaut: Panel-Zugang löschen, Systemkonto löschen,
SSH-Schlüssel entfernen, Passkey entfernen, Datei oder Ordner löschen, ufw ein-
und ausschalten, Server neu starten, alle Updates einspielen, Dienst stoppen,
Panel-Update und Rollback, alle anderen Sitzungen beenden. Jedes sah im Code
abgesichert aus, keines war es.

Dieselbe Falle wie beim `style`-Attribut der Auslastungsbalken (siehe
[docs/13-dateimanager.md](13-dateimanager.md) und den CHANGELOG zu rc.5): Der
Browser verwirft still, die Seite sieht danach nicht kaputt aus, sondern nur
falsch.

## Die Regel: der Server fragt, nicht die Seite

Ein zerstörender Handler führt nichts aus, solange das Formular nicht
`bestaetigt=1` mitbringt. Stattdessen antwortet er mit einer Seite, die sagt,
was passieren wird — und die dasselbe POST erneut schickt, diesmal mit dem Feld.

```go
if !s.bestaetigt(w, r, bestaetigung{
    Titel:   "Panel-Zugang löschen",
    Frage:   "Konto " + target.Username + " endgültig löschen?",
    Punkte:  []string{"Offene Sitzungen dieses Kontos werden beendet.", …},
    Knopf:   "endgültig löschen",
    Tippen:  target.Username,
    Abbruch: "/users",
}) {
    return
}
```

Der Aufruf steht **nach** allen Prüfungen und **vor** der ersten Veränderung.
Lesen darf davor geschehen — die Frage soll die richtigen Zahlen tragen.

Drei Eigenschaften fallen dadurch von selbst an:

- **Ohne JavaScript funktioniert es.** Die Zwischenseite ist gewöhnliches HTML.
- **Ein selbstgebauter POST kommt nicht daran vorbei.** `curl` ohne das Feld tut
  nichts.
- **Der Dialog kann sich irren, ohne dass es gefährlich wird.** Er ist eine
  Bedienhilfe. Dieselbe Arbeitsteilung wie bei der Pfadwache und der
  Zielauswahl des Dateimanagers.

## Drei Stufen

Ein Dialog vor jeder Kleinigkeit erzieht zum Wegklicken und entwertet die
Rückfrage dort, wo sie zählt. Deshalb gestuft:

| Stufe | Wann | Was passiert |
|---|---|---|
| **1 — keine Rückfrage** | reversibel: sperren, entsperren, starten, neu starten, eine einzelne Sitzung beenden, ein einzelnes Paket einspielen | direkt |
| **2 — zweiter Klick** | zerstörend, aber nachvollziehbar: SSH-Schlüssel, Passkey, Datei, Dienst stoppen, ufw einschalten, Panel-Update, Rollback, alle anderen Sitzungen, neue Wiederherstellungscodes | Dialog mit Zahlen |
| **3 — getipptes Wort** | unumkehrbar oder aussperrend: Panel-Zugang löschen, Systemkonto löschen, Ordner mit Inhalt löschen, ufw ausschalten, Server neu starten | Dialog **und** Eingabe des Namens |

Bei objektbezogenen Aktionen ist das getippte Wort der Name des Objekts
(Kontoname, Ordnername). Bei systemweiten Aktionen ist es der **Hostname**. Das
schützt gegen einen Fehler, den kein Klick abfängt: die richtige Aktion auf dem
falschen Server. Er steht in der Seitenleiste und in der Fußzeile — abzulesen,
nicht zu erraten; Geheimhaltung ist nicht der Zweck.

**Eine Abweichung von dieser Tabelle, und zwar begründet:** Ein Cron-Eintrag **als
root** ist Stufe 3 mit dem Hostnamen, obwohl er löschbar — also nach dieser
Tabelle umkehrbar — ist. Der Grund steht in
[docs/16-neukonzeption.md](16-neukonzeption.md) unter 7.2: Der *Eintrag* ist
umkehrbar, seine *Folgen* sind es nicht, und er läuft unbeaufsichtigt. Ein Eintrag
als anderer Benutzer bleibt Stufe 2, ihn abzuschalten Stufe 1. Wenn eine weitere
Fläche diese Tabelle verlassen will, gehört die Begründung genauso hierhin — eine
stillschweigende Abweichung wäre der Anfang davon, dass die Stufen nichts mehr
bedeuten.

**Zweite Abweichung, ebenfalls begründet: einen laufenden Container entfernen.**
Nach der Tabelle wäre das Entfernen eines Containers Stufe 2 — der Container ist
weg, das Image bleibt, ein neuer ist ein Handgriff. Läuft er aber, tut derselbe
Klick zwei Dinge auf einmal: Er beendet einen Dienst *und* löscht ihn. Deshalb
Stufe 3 mit dem **Containernamen** (objektbezogen, nicht systemweit — es trifft
einen Container und nicht den Server). Ein gestoppter Container bleibt Stufe 2,
ihn zu starten Stufe 1, ihn zu stoppen Stufe 2. Die vollständige Tabelle des
Moduls steht in [17-docker.md](17-docker.md).

Verglichen wird ohne Rücksicht auf Groß- und Kleinschreibung (`EqualFold`): Auf
einem Telefon macht die Tastatur aus `vm` gern `Vm`. Wer den Namen abgeschrieben
hat, hat die Rückfrage gelesen — mehr soll die Stufe nicht leisten.

## Der Dialog

**Der Text kommt vom Server.** Ein Schreibzugriff, dem die Bestätigung fehlt,
wird nicht ausgeführt; der Handler antwortet mit **409** und legt bei, was zu
fragen ist:

```json
{ "bestaetigung": {
    "titel":  "Löschen",
    "frage":  "baum enthält 12 Dateien und 3 Ordner (4,1 MiB). Alles endgültig löschen?",
    "punkte": ["Der Ordner ist nicht leer.", "Es gibt keinen Papierkorb."],
    "knopf":  "endgültig löschen",
    "tippen": "baum" } }
```

Die Oberfläche zeigt das in `komponenten/Rueckfrage.svelte` und schickt dieselbe
Anfrage erneut, diesmal mit `"bestaetigt": true`. Sie **formuliert nichts und
entscheidet nichts** — auch nicht die Stufe: Ob ein getipptes Wort verlangt wird,
steht in `tippen`, und ob es stimmt, prüft der Handler noch einmal. Irrt sich
dieser Dialog, ist das kein Sicherheitsproblem; dieselbe Arbeitsteilung wie bei
der Pfadwache.

Fünf Einzelheiten, die nicht offensichtlich sind:

- **409 und nicht 412.** Der Statuscode für „hier fehlt die Zustimmung" ist im
  ganzen Panel derselbe. 412 ist reserviert und bedeutet genau eine andere Sache:
  Im Editor hat sich die Datei seit dem Öffnen geändert (Hash-Konflikt). Zwei
  Bedeutungen auf einem Code wären zwei Dialoge, die gleich aussehen und
  Verschiedenes meinen.
- **Ein echtes `<dialog>` mit `showModal()`, kein `<div>` mit Schleier und kein
  `window.confirm`.** Der Browser bringt Fokusfang, oberste Ebene und Escape mit.
  Das nachzubauen ist die Stelle, an der Tastaturbedienung still verloren geht —
  und `confirm()` kann kein Eingabefeld tragen, das die dritte Stufe braucht.
- **Escape ist ein Abbruch**, und zwar über `oncancel`: Ohne das schließt der
  Browser den Dialog, während die Komponente eingehängt bleibt — beim nächsten
  Versuch stünde ein geschlossener Dialog da, und der Knopf wirkte kaputt.
- **Der gefährliche Knopf bekommt nicht den Fokus.** Bei der dritten Stufe liegt
  er im Eingabefeld und bleibt gesperrt, bis das Wort stimmt.
- **Die Stufe berechnet der Server je Objekt.** Ein leerer Ordner ist Stufe 2,
  ein Ordner mit Inhalt Stufe 3 — dieselbe Zahl, die den Dialog füllt, entscheidet
  auch die Prüfung. Eine Hürde ohne Anlass entwertet die Hürde dort, wo sie zählt.

Bis 0.4.0 lief das anders: `bestaetigen.js` hing an jedem Formular mit
`data-bestaetigen`, hielt das Absenden auf und schickte mit
`requestSubmit(knopf)` weiter — mit dem Knopf, weil `submit()` das `formaction`
verwirft und auf *Panel-Zugänge* statt der Passkeys das Passwort zurückgesetzt
worden wäre. Der Weg über das Formular ist mit der alten Oberfläche entfallen
(0.4.1); die Fragen selbst und die Stufen sind dieselben, weil sie immer im
Handler standen.

## Was ohne Rückfrage bleibt — mit Begründung

- **Das Zurücksetzen eines fremden Zugangs** (Passwort, zweiter Faktor,
  Passkeys): Die Anfrage verlangt das eigene Passwort des Owners. Die Bremse
  steht schon darin, und sie noch einmal abzufragen hieße, das Passwort zweimal
  entgegenzunehmen. Das Zurücksetzen der Passkeys zeigt trotzdem einen Dialog —
  es nimmt den letzten Nachweis eines Geräts, das niemand wiederbeschaffen kann.
- **Der Regelsatz der Firewall:** Eine geleerte Portnummer entfernt eine
  Regel. Der gespeicherte Regelsatz gilt zunächst auf Probe und nimmt sich ohne
  Bestätigung binnen 60 Sekunden von selbst zurück — eine wirksamere Sicherung
  als ein Dialog, weil sie auch den Fall abfängt, in dem man sich selbst
  aussperrt und nicht mehr klicken kann.
- **Eine einzelne Sitzung beenden:** Man meldet sich wieder an.

## Was die Tests festhalten

- `internal/ui` (Quellentest über `web/src`): kein `confirm(` in irgendeiner
  Svelte-Quelle — ein `window.confirm` wäre der bequeme Weg zurück und kann die
  dritte Stufe nicht tragen —, und die Rückfrage benutzt das `<dialog>`-Element
  und nicht ein selbstgebautes Overlay.
- `internal/httpd`: Für jede zerstörende Route führt ein Aufruf **ohne**
  `bestaetigt` nichts aus. Geprüft wird die Wirkung, nicht der Statuscode: Ein
  Test, der nur auf 409 schaut, besteht auch dann, wenn zusätzlich etwas geschah.
  Ein falsches getipptes Wort ergibt 400 und keine Aktion.
- Browsertest (`ASYLUM_LEITSTAND_E2E=1`): Der Dialog erscheint überhaupt — das
  war der Befund —, ist modal, `abbrechen` und Escape schicken keine zweite
  Anfrage (nachgesehen wird danach der Zustand, nicht nur der Dialog), und der
  Knopf bleibt bis zum richtigen Wort gesperrt.

## Was bewusst fehlt

**Kein Papierkorb und kein Undo.** Das Panel arbeitet auf dem echten System; ein
halb gelöschter Zustand in einem Panel-Ordner wäre gefährlicher als eine klare
Rückfrage. Wo es einen umkehrbaren Weg gibt, nennt die Rückfrage ihn: Ein Konto,
das nur vorübergehend keinen Zugang haben soll, wird **gesperrt** statt gelöscht.
