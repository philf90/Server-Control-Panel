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

Verglichen wird ohne Rücksicht auf Groß- und Kleinschreibung (`EqualFold`): Auf
einem Telefon macht die Tastatur aus `vm` gern `Vm`. Wer den Namen abgeschrieben
hat, hat die Rückfrage gelesen — mehr soll die Stufe nicht leisten.

## Der Dialog

`bestaetigen.js` hängt an jedem Formular mit `data-bestaetigen` und hält das
Absenden auf, bis bestätigt wurde. Es steht in der Fußzeile jeder Seite und
nicht in den acht, die einen solchen Knopf tragen: Ein Skript, das man beim
Hinzufügen eines Löschknopfes vergessen kann, ist keines.

```html
<form method="post" action="/files/delete"
      data-bestaetigen="baum enthält 12 Dateien und 3 Ordner (4,1 MiB). Alles endgültig löschen?"
      data-bestaetigen-titel="Löschen"
      data-bestaetigen-knopf="endgültig löschen"
      data-bestaetigen-tippen="baum">
```

Vier Einzelheiten, die nicht offensichtlich sind:

- **Ein `<dialog>`, kein `window.confirm`.** Nur das erste kann ein
  Eingabefeld tragen, sich gestalten und den Rest der Seite abdecken.
- **Die Angabe darf am Knopf stehen.** Auf *Panel-Zugänge* teilen drei Knöpfe
  ein Formular, und `formaction` entscheidet, welche Zurücksetzung gemeint ist.
  Das Skript liest `event.submitter` und bevorzugt dessen Angaben.
- **Abgeschickt wird mit `requestSubmit(knopf)`, nicht mit `submit()`.**
  `submit()` verwirft das `formaction` des Knopfes — statt der Passkeys wäre
  dann das Passwort zurückgesetzt. Ein Browsertest hält genau das fest.
- **Escape ist ein Abbruch**, und der gefährliche Knopf bekommt nicht den
  Fokus. Bei der dritten Stufe liegt er im Eingabefeld und bleibt gesperrt, bis
  das Wort stimmt.

## Was ohne Rückfrage bleibt — mit Begründung

- **`/users/reset-password`, `/users/reset-2fa`, `/users/reset-passkeys`:** Das
  Formular verlangt das eigene Passwort des Owners. Die Bremse steht schon
  darin, und eine Zwischenseite müsste das Passwort in einem versteckten Feld
  weitergeben — das wäre schlechter als keine. Der Knopf für die Passkeys zeigt
  trotzdem einen Dialog.
- **`/firewall` (Regelsatz speichern):** Eine geleerte Portnummer entfernt eine
  Regel. Der gespeicherte Regelsatz gilt zunächst auf Probe und nimmt sich ohne
  Bestätigung binnen 60 Sekunden von selbst zurück — eine wirksamere Sicherung
  als ein Dialog, weil sie auch den Fall abfängt, in dem man sich selbst
  aussperrt und nicht mehr klicken kann.
- **Eine einzelne Sitzung beenden:** Man meldet sich wieder an.

## Was die Tests festhalten

- `internal/ui`: kein `onsubmit`/`onclick` in irgendeiner Vorlage (die CSP
  verwirft sie), und jedes Formular auf einer zerstörenden Route trägt
  `data-bestaetigen` — die Liste der Routen steht im Test ausgeschrieben, damit
  eine neue auffällt.
- `internal/httpd`: Für jede zerstörende Route führt ein POST **ohne** das Feld
  nichts aus. Geprüft wird die Wirkung, nicht der Statuscode: Die Zwischenseite
  antwortet mit 200, und ein Test, der nur darauf schaut, besteht auch dann,
  wenn nichts geschah. Ein falsches getipptes Wort ergibt 400 und keine Aktion.
- Browsertest (`ASYLUM_BESTAETIGEN_E2E=1`): Der Dialog erscheint überhaupt —
  das war der Befund —, ist modal, `abbrechen` und Escape schicken kein POST,
  der Knopf bleibt bis zum richtigen Wort gesperrt, `window.confirm` kommt nicht
  vor, und der bestätigte Klick landet beim `formaction` des Knopfes.

## Was bewusst fehlt

**Kein Papierkorb und kein Undo.** Das Panel arbeitet auf dem echten System; ein
halb gelöschter Zustand in einem Panel-Ordner wäre gefährlicher als eine klare
Rückfrage. Wo es einen umkehrbaren Weg gibt, nennt die Rückfrage ihn: Ein Konto,
das nur vorübergehend keinen Zugang haben soll, wird **gesperrt** statt gelöscht.
