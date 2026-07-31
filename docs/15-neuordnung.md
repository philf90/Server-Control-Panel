# 14 — Neuordnung der Oberfläche

> **Abgelöst (Stand 0.4.1), aber nicht ungültig.** Umgesetzt wurde Entwurf 1,
> die **Kommandobrücke**. Sie hat sich im Betrieb nicht bewährt und ist mit der
> Neukonzeption durch den **Leitstand** ersetzt; die Fläche, die sie trug, ist
> mit 0.4.1 abgebaut. Was bleibt, ist der wertvollere Teil dieses Dokuments:
> der **Befund** und die **fünf Grundsätze**. Sie gelten unverändert und sind
> die Messlatte der neuen Oberfläche —
> [16-neukonzeption.md](16-neukonzeption.md) §8.2 baut das Gestaltungssystem
> ausdrücklich auf ihnen auf. Einzelheiten unten unter
> [Was davon geblieben ist](#was-davon-geblieben-ist).

Drei Entwürfe dafür, wie das Panel angeordnet sein müsste, damit beim Öffnen das
Gefühl entsteht, an den Reglern zu sitzen statt einen Bericht zu lesen. Die
Mappe mit allen Mockups in Hell und Dunkel:

**[docs/entwuerfe/neuordnung.html](entwuerfe/neuordnung.html)** — im Browser öffnen.

Dazu eine zweite Mappe, die Entwurf 1 über den gesamten Bestand durchzieht —
alle 23 Seiten, je am Bildschirm und auf dem Telefon:

**[docs/entwuerfe/entwurf1-alle-seiten.html](entwuerfe/entwurf1-alle-seiten.html)**

Beide Dateien sind statisches HTML ohne Schriftdateien und ohne externe Aufrufe,
genau wie das Panel selbst. Jeder Rahmen hat einen eigenen Hell/Dunkel-Schalter,
oben stellt einer alle zugleich.

---

## Stand der Umsetzung

**Entwurf 1 ist umgesetzt.** Die Schale steht auf jeder Seite: Statusleiste mit
Wirt, Laufzeit und fünf Kennzahlen (jede ein Link), Symbolschiene mit elf Zielen
und Warnpunkt je Bereich, Konsole am unteren Rand mit dem zuletzt ausgeführten
Befehl. Schmal wird aus der Schiene eine Leiste am unteren Rand mit vier Zielen
und einem Knopf für den Rest.

| Teil | Wo |
|---|---|
| Statusleiste, Schiene, Konsole | `internal/ui/templates/partials.html` |
| Marken, Grundriss, Schmalmodus | `internal/ui/static/app.css` |
| Kennzahlen je Seite, Warnpunkte | `internal/httpd/pages.go`, `server.go` |
| Konsolen-Echo | `internal/privops/journal.go` |

Zwei Entscheidungen, die beim Bauen fielen:

- **Ein Seitenaufbau löst keinen Systemaufruf aus.** Der erste Versuch erhob den
  Handlungsbedarf für die Warnpunkte beim Rendern — damit hing jede Seite an
  einem `systemctl`. Erhoben wird jetzt im Messtakt und beim Aufruf der
  Übersicht; ist nichts Frisches da, bleiben die Punkte weg.
- **Das Journal hängt am Runner**, nicht an den einzelnen Operationen. So kann
  keine Stelle vergessen werden. Es liegt nur im Speicher, in einem Ring fester
  Größe; Stdin wird nie aufgezeichnet, und Argumente nach einer Option, die nach
  einem Geheimnis klingt, werden verdeckt.

**Noch nicht umgesetzt:** die Befehlspalette (⌘K). Sie braucht einen eigenen
Suchindex über Dienste, Dateien und Regeln und bleibt der optionale Teil des
Entwurfs — die Schale trägt ohne sie.

*Nachtrag: Sie kam mit der neuen Oberfläche
(`web/src/komponenten/Befehlspalette.svelte`) und liest ihre Ziele aus derselben
Liste wie die Seitenleiste (`web/src/lib/ziele.ts`) — zwei Fassungen desselben
Menüs laufen sonst auseinander, und ein Modul, das die Suche nicht kennt, gilt
als nicht vorhanden.*

## Was davon geblieben ist

Der Text ab hier ist der Stand vor der Neukonzeption und beschreibt eine
Oberfläche, die es nicht mehr gibt. Er bleibt stehen, weil die Herleitung
weitergilt — hier die Abrechnung, Punkt für Punkt:

| Teil dieses Dokuments | Stand |
|---|---|
| **Befund** (vier Entscheidungen, die zusammen zum Lesen statt Bedienen führten) | gilt unverändert; er ist der Anlass beider Oberflächen |
| **Fünf Grundsätze** | gelten unverändert, wiedergegeben in [16](16-neukonzeption.md) §8.3, dort um einen sechsten ergänzt (Rückweg mit Frist) |
| **Entwurf 1 · Kommandobrücke** | gebaut, **abgelöst** — die Schale (Statusband, Konsolen-Echo) ist in den Leitstand übernommen |
| **Entwurf 2 · Werkbank** | teilweise eingelöst: Liste plus Inspektor ist das Muster jeder Arbeitsseite ([16](16-neukonzeption.md) §8.4); die Änderungsablage kam nicht |
| **Entwurf 3 · Leitstand** | **gewinnt** und gibt dem neuen Gestaltungssystem den Namen samt Grundsatz „Farbe trägt ausschließlich Zustand" |
| **Die drei Mappen** | historisch; die gültige Entwurfsmappe ist [entwuerfe/neukonzept.html](entwuerfe/neukonzept.html) |
| **Dateiverweise** (`internal/ui/templates/*.html`, `app.css`) | hinfällig — diese Vorlagen sind mit 0.4.1 entfernt; die Oberfläche liegt unter `web/src/` |

Warum die Kommandobrücke nicht getragen hat, steht in
[16-neukonzeption.md](16-neukonzeption.md) §1: Was sich bewährt hat, waren die
Telemetrie-Kacheln der Übersicht — dunkle Karte, große Zahl, bernsteinfarbener
Verlauf. Sie sind der Keim des neuen Systems; die Symbolschiene mit elf
gleichrangigen Zielen ist es nicht geworden.

Auch der Abschnitt [Vorher zu klären](#vorher-zu-klären) ist beantwortet: Die
**Zahl der Module** ist über zwölf hinausgewachsen (achtzehn Ziele in vier
Bereichen), womit die Schiene bzw. die gruppierte Seitenleiste gesetzt ist, und
die **Konsole bleibt reines Echo** — ein Terminal mit Eingabe ist ein eigenes
Modul und steht hinter 1.0 ([16](16-neukonzeption.md) §6).

## Befund

Vier Entscheidungen, die einzeln richtig waren und zusammen dazu führen, dass
man liest statt bedient.

1. **Der Zustand verschwindet, sobald man handelt.** Die Kennzahlen stehen nur
   auf der Übersicht. Wer auf „Dienste“ wechselt, um den Ausfall zu beheben,
   sieht CPU, Speicher und Platte nicht mehr.
2. **Zahlen sind Text, keine Griffe.** Nichts auf der Übersicht ist anklickbar.
   Der Weg von einer auffälligen Zahl zu der Stelle, an der man etwas tut, führt
   über das Menü.
3. **Handlungen hinterlassen keine Spur.** Ein Neustart lädt die Seite neu. Was
   der Befehl war, ob er durchlief, wie lange er brauchte — steht nirgends im
   Blickfeld.
4. **Alle Bereiche wiegen gleich viel.** Das Menü verrät nicht, ob irgendwo
   etwas offen ist. Man muss jede Seite besuchen, um zu wissen, dass nichts zu
   tun ist.

## Fünf Grundsätze, die alle drei Entwürfe befolgen

| | |
|---|---|
| I | Der Zustand geht nie weg — Kennzahlen bleiben auf jeder Seite sichtbar |
| II | Jede Zahl ist ein Griff — ein Klick führt dorthin, wo man sie ändert |
| III | Handlungen sind quittiert — was, wie lange, wie ausgegangen |
| IV | Das Panel verschweigt nichts — ausgeführte Befehle im Klartext |
| V | Erst das Urteil, dann die Zahlen |

## Die drei Entwürfe

| | 1 · Kommandobrücke | 2 · Werkbank | 3 · Leitstand |
|---|---|---|---|
| Wodurch Kontrolle entsteht | alles im Blick, nichts verborgen | unmittelbare Handhabung, umkehrbare Eingriffe | eindeutiger Zustand, Handlung daneben |
| Grundriss | Statusleiste, Symbolschiene, Kachelmatrix, Konsole | Schiene, Liste, Inspektor, Änderungsablage | Kopfmenü, Urteil, Mimikbild, große Kacheln |
| Kennzeichen | Konsolen-Echo jedes ausgeführten Befehls, Befehlspalette (⌘K) | Änderungen sammeln und als ein Vorgang anwenden, mit Rückfallfrist | Farbe trägt ausschließlich Zustand, Signalweg als Bild |
| Für wen | Admins, die sonst per SSH arbeiten | Teams, gemischte Kenntnisstände, viele Module | Selfhoster, gelegentliche Nutzung, Wandmonitor |
| Umbau am Bestand | mittel | groß | klein |
| Ohne JavaScript | ja, ohne Palette | ja, Auswahl in der URL | ja, vollständig |

## Empfehlung: drei Schichten statt einer Wahl

Die Entwürfe arbeiten auf verschiedenen Ebenen und schließen sich weniger aus,
als es aussieht. Nach jeder Stufe ist etwas Fertiges da.

**Stufe 1 — Entwurf 3 für die Übersicht.** Urteilsband, Mimikbild, vier
Zustandskacheln mit Knöpfen darin, darunter Tableau und Spur. Kleinster
Eingriff, größte sofortige Wirkung: genau die Stelle, an der der erste Eindruck
entsteht.
`internal/ui/templates/dashboard.html` · `internal/ui/static/app.css` ·
`internal/ui/ui.go` (Ports je Dienst für das Mimikbild)

**Stufe 2 — Entwurf 1 als Schicht über allem.** Statusleiste und Konsolen-Echo
als zwei neue Teilvorlagen, die jede Seite erbt. Damit gilt Grundsatz I sofort
überall, ohne dass eine einzige Seite umgebaut wird. Die Befehlspalette kommt
zuletzt und bleibt optional.
`internal/ui/templates/partials.html` · `internal/ui/static/` (neu) ·
`internal/privops` (Befehl, Exit, Dauer protokollieren)

**Stufe 3 — Entwurf 2 für die Arbeitsseiten.** Dienste zuerst, dann Firewall,
dann Dateien und Systembenutzer. Die Auswahl steht in der URL
(`/services?unit=nginx.service`), damit es ohne JavaScript trägt und Links
teilbar bleiben. Die Änderungsablage lohnt zuerst bei der Firewall.
`internal/ui/templates/services.html`, `firewall.html`, `files.html`,
`sysusers.html` · neue Teilvorlage „inspektor“

## Entwurf 1 über den ganzen Bestand

Die zweite Mappe zieht die Kommandobrücke durch alle vorhandenen Seiten. Die
Schale ist überall dieselbe und besteht aus vier Teilen: Statusleiste,
Symbolschiene, Inhaltsfeld, Konsole. Nur der Inhaltsbereich wechselt.

Auf 360 Pixeln wandern drei Dinge, der Rest bleibt:

| | Bildschirm | Telefon |
|---|---|---|
| Messwerte | fünf in der Statusleiste | schiebbares Band unter der Kopfzeile |
| Navigation | Symbolschiene links, 11 Ziele | Reiterleiste unten, 5 Ziele + „Mehr“ |
| Konsole | Schublade unten, aufziehbar | eine Zeile, tippbar auf Vollbild |
| Tabellen | Spalten | eine Karte je Zeile |

Zwei Seiten weichen bewusst ab: Der **Datei-Editor** legt Messwerte und Konsole
ab, um Höhe zu gewinnen, und bekommt eine Zeichenleiste über der Tastatur. Bei
**Zwei-Faktor einrichten** entfällt der QR-Code — er zeigt auf dasselbe Gerät,
und ein Telefon kann seinen eigenen Bildschirm nicht scannen; stattdessen ein
`otpauth://`-Verweis.

Offene Punkte, die die Mappe benennt: die Belegung der Reiterleiste ist geraten,
der Warnpunkt kennt nur zwei Stufen, die Statusleiste braucht Kennzahlen in
jedem Seitenmodell, und für das Konsolen-Echo fehlt noch die Liste dessen, was
nicht in eine Zeile darf — Tokens, Passwörter, Schlüssel.

## Vorher zu klären

- **Zielgruppe.** Ist das Panel auch für Leute ohne SSH-Erfahrung gedacht,
  verliert Entwurf 1 an Gewicht und Entwurf 3 gewinnt.
- **Zahl der Module.** Bei acht bis zehn trägt das waagerechte Menü aus
  Entwurf 3; ab zwölf braucht es die Schiene aus 1 oder 2.
- **Konsole mit Eingabe?** Als reines Echo ist sie harmlos. Als Terminal ist sie
  ein eigenes Modul mit eigener Sicherheitsbetrachtung.
