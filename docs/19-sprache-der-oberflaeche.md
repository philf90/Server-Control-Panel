# 19 — Sprache der Oberfläche

> **Kurzfassung.** Die Texte des Panels sind **technisch**. Wo im deutschen
> Fachgebrauch das englische Wort gilt — Container, Volume, Stack, Image,
> Rollback, Build-Cache, Upstream, Login-Shell —, benutzt das Panel das
> englische Wort. Eine gesuchte deutsche Entsprechung ist keine Verbesserung,
> sondern eine zusätzliche Übersetzungsleistung für den Lesenden.

## 1. Warum das eine eigene Vorgabe braucht

Bis 0.6.0 stand auf einem Knopf **„nginx einspielen"**, darüber der Satz „Das
Panel kann es aus den Paketquellen der Distribution einspielen." Beides ist
korrektes Deutsch, beides ist im Zusammenhang eines Server-Control-Panels
falsch: *Einspielen* kommt von Tonbändern und Schallplatten. Ein Panel
**installiert**.

Der Fund war kein Einzelfall, sondern die Spitze einer Gewohnheit. In derselben
Durchsicht standen: *Anmeldeschale* für die Login-Shell, *Baucache* für den
Build-Cache, *Krumen* für die Pfadleiste, *Wirtspfad* für den Host-Pfad,
*Gegenstelle* für die Upstream-Adresse, *Fassung* für Version, *Rückweg* für
Rollback, *Spitzenreiter* über der Prozessliste, „das Journal schrieb schneller
als die **Leitung**".

Das gehört zusammen, und der Grund ist derselbe: Diese Wörter kommen nicht als
Entscheidung ins Projekt. Sie entstehen beim Formulieren. Wer einen Satz
schreibt, in dem „installieren" schon zweimal vorkommt, greift beim dritten Mal
zu „einspielen" — nicht aus Überzeugung, sondern aus Abwechslungsbedürfnis. Im
Diff fällt so etwas nie auf. Erst in der Summe entsteht eine Oberfläche, die
klingt wie ein Handbuch von 1994, und dann liest sie sich, als traue das Panel
seinen Benutzern nicht zu, dass sie wissen, was ein Volume ist.

**Die Zielgruppe kennt die Fachwörter.** Wer einen Server administriert, hat
`docker volume rm` getippt, bevor er dieses Panel geöffnet hat. Ihm „Datenträger
des Wirts" statt „Host-Volume" anzubieten, hilft ihm nicht — es zwingt ihn,
zurückzuübersetzen, bevor er handeln kann. Der literarische Ton kostet also
genau das, was Grundsatz V verspricht: dass die Oberfläche sich dort erklärt, wo
etwas geschieht.

## 2. Die Vorgabe

1. **Technisch vor literarisch.** Jeder sichtbare Text — Beschriftung,
   Überschrift, Erklärsatz, Rückfrage, Fehlermeldung, Vorgangszeile — ist so
   geschrieben, wie eine Fachperson die Sache benennen würde. Nicht wie ein
   Lektorat sie eindeutschen würde.

2. **Das etablierte englische Fachwort ist erlaubt und meist vorzuziehen.**
   Container, Image, Volume, Netzwerk/Netz, Stack, Compose, Registry, Socket,
   Port, Proxy, Upstream, Rollback, Backup, Build-Cache, Login-Shell, Stream,
   Logs, Host, Job, Token, Passkey, Commit, Tag. Diese Wörter *sind* der
   deutsche Fachgebrauch; sie zu übersetzen erfindet einen Dialekt, den nur
   dieses Panel spricht.

3. **Aber kein Denglisch um seiner selbst willen.** Wo ein deutsches Wort
   dasselbe leistet und gebräuchlich ist, gewinnt es: *Datei* statt File,
   *Verzeichnis* statt Directory, *Berechtigungen* statt Permissions,
   *Neustart* statt Reboot, *Zeitplan* statt Schedule, *Vorgang* statt Job in
   der laufenden Oberfläche. Der Maßstab ist der Sprachgebrauch der Zielgruppe,
   nicht die Herkunft des Wortes.

4. **Ein Begriff, ein Wort — im ganzen Panel.** Dieselbe Sache heißt auf jeder
   Seite gleich. Die Prüfung dazu ist ein Suchlauf: Kommt für dieselbe Sache
   irgendwo ein zweites Wort vor, ist eines davon falsch. So sind
   *Handgriff*/*Aktion* und *Fläche*/*Bereich* nebeneinander entstanden.

5. **Kein Wortspiel und keine Metapher, wo es um eine Folge geht.** „teils
   geglückt", „der Vorgang ist durch", „die Leitung", „zusehen" — bei einer
   Meldung, nach der jemand handelt, kostet der Ton Genauigkeit. Sätze über
   Löschen, Rechte und Erreichbarkeit sind nüchtern.

6. **Kommentare sind davon ausgenommen.** Die Begründungen im Quelltext dürfen
   erzählen, und sie dürfen die alten Wörter nennen — sie tragen die Geschichte
   der Entscheidung. Die Vorgabe gilt für Text, den ein Browser anzeigt.

7. **Die Kommandozeile ist nicht die Oberfläche.** Entschieden vom Betreiber am
   1. September 2026: `srvpanel …` **muss** die Sprache dieses Dokuments nicht
   führen. Deutsch und Englisch sind dort beide zulässig, und die Liste der
   verbrauchten Wörter in §3 bindet sie nicht.

   **Der Anlass war eine Frage, die seit dem Wunsch nach einem Fortschrittsbalken
   offen stand** und die dieses Dokument bis dahin zweimal verschieden
   beantwortete: Punkt 6 sagt „Text, den ein Browser anzeigt", §4a sagt „jeder
   Text der Oberfläche" — und `WordChoiceTest` las ganz `app/`, also auch
   `app/Console/Commands/`.

   > **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander,
   > und keine von beiden ist der Ort, an dem man nachsieht.**

   **Wer die Adressaten unterscheidet, sieht warum.** Die Oberfläche liest ein
   Kunde, der von Hosting so viel weiss, wie er zum Bestellen brauchte. Die
   Kommandozeile liest ein Betreiber mit root-Rechten, und dort steht der Text
   zwischen den Ausgaben von `apt`, `systemctl` und `dpkg` — die englisch sind
   und es bleiben.

   **Gemessen am 1. September 2026, und der Befund gab den Ausschlag:** In den
   sechzehn Dateien unter `app/Console/Commands/` stehen 1201
   Zeichenkettenliterale, und **keines** davon trifft die Liste aus §3. Die
   Kommandozeile spricht trotzdem schon ein anderes Deutsch als die Oberfläche —
   `apt-run` schreibt `Fassung 0.7.3~rc.7 wurde zu …`, und `srvpanel update`
   druckt das wörtlich, während jede Seite „Version" sagt. Durchgelassen hat das
   kein Urteil, sondern der Umstand, dass der Satz in einem Shellskript steht,
   das der Wächter nicht liest.

   > **Eine Regel, die eine Datei nicht liest, gilt für den Satz nicht — auch
   > wenn er am Ende auf demselben Bildschirm steht.**

   Was das kostet, ist benannt und angenommen: Dieselbe Sache kann auf der Seite
   und auf der Konsole verschieden heissen. Was es bringt, ist, dass eine
   Meldung neben `apt`s eigener Ausgabe stehen darf, ohne übersetzt zu werden.

   **`WordChoiceTest` nimmt `app/Console/Commands/` seit diesem Tag aus** — sonst
   sagte das Dokument das eine und der Wächter das andere, und das ist die
   Fehlerklasse, die dieses Projekt am häufigsten bezahlt hat.

## 3. Die Liste der verbrauchten Wörter

Verbindlich, weil mechanisch geprüft: `tests/Feature/WordChoiceTest.php` liest
den `<template>`-Block jeder Vue-Datei unter `resources/js` ohne
HTML-Kommentare und — über `token_get_all`, damit Kommentare außen vor bleiben —
die Zeichenkettenliterale aus `app/`.

Bis August 2026 stand hier `internal/ui/wortwahl_test.go`. Dieser Test ist beim
Repo-Übergang mit dem Go-Code verschwunden, die Vorgabe blieb stehen. Neun
Monate später stand im Aufgabenkatalog der Vorgangsseite „Fragt den Agenten nach
seiner Fassung" — gefunden hat es kein Lauf, sondern der erste Mensch, der
hingesehen hat. Ein Dokument ohne Prüfung ist eine Absichtserklärung.

| Verbraucht | Stattdessen | Wo es stand |
|---|---|---|
| einspielen, eingespielt | **installieren** | nginx, Docker, ufw, Paket-Updates |
| Fassung | **Version** | Update-Modul, Image-Updates, Editor |
| Rückweg | **Rollback**, zurücksetzen | Panel-Update, Site-Probe |
| Fläche | **Bereich** | Token-Umfang, Modulnavigation |
| Handgriff | **Aktion** | Spaltenüberschrift der Tokenliste |
| Anmeldeschale | **Login-Shell** | Systemkonten |
| Krumen | **Pfadleiste** | Dateimanager, Zielwahl |
| Baucache | **Build-Cache** | Docker-Bestand |
| Wirtspfad, Wirtsdateisystem | **Host-Pfad**, Dateisystem des Hosts | Container-Inspektor, Compose-Prüfer |
| Gegenstelle | **Upstream-Adresse** | Site-Formular, Site-Prüfer |
| Spitzenreiter | eine Angabe, wonach sortiert wird | Prozessliste |
| wegräumen | **entfernen** | Docker-Bestand |
| geglückt | **erfolgreich** | Vorgangszustand |
| Platte | **Datenträger** | Übersicht, Dateimanager, Compose-Prüfer |

Nicht auf der Liste, aber in derselben Durchsicht ersetzt — sie sind als Muster
zu allgemein, um sie mechanisch zu verbieten:

- **Bezug / beziehen** für ein Zertifikat → *Ausstellung* für die Betriebsart,
  *anfordern* / *Anforderung* für den Vorgang.
- **Ablage** für die lokalen Images → „lokal vorhanden".
- **Leitung** → *Verbindung*. „Es sehen zu viele Verbindungen dem Journal zu"
  → „Es sind bereits zu viele Verbindungen zum Journal offen".
- **Strom** → *Stream*, wo es der SSE-Kanal ist.
- **Protokoll** für Container-Logs → *Logs*. Für das Audit bleibt es
  *Protokoll*: Dort ist es kein Log, sondern ein Nachweis.

## 3a. Keine Emoji

Im Passwortfeld standen 👁 und 🙈 als Knopf zum Ein- und Ausblenden. Das geht
aus drei Gründen nicht, und alle drei gelten für jedes Emoji in dieser
Oberfläche:

1. **Es sieht überall anders aus.** Gezeichnet wird es von der Schriftart des
   Betriebssystems — auf Windows bunt, auf macOS dreidimensional, auf einem
   Server mit dünner Schriftausstattung als leeres Rechteck.
2. **Es nimmt keine Textfarbe an.** In einer Gestaltung, in der Farbe etwas
   bedeutet (§7.2), steht ein Zeichen mit eigener Farbe daneben und behauptet
   eine Bedeutung, die es nicht hat.
3. **Der Ton stimmt nicht.** Der Affe, der sich die Augen zuhält, ist ein Witz
   an einer Stelle, an der jemand ein Passwort für ein Kundenkonto setzt.

Stattdessen: ein SVG mit `currentColor` und `stroke`, das Farbe und Größe vom
Umfeld erbt. Vorlage ist `resources/js/Components/EyeIcon.vue` — eigene
Geometrie, keine Icon-Bibliothek, deren Lizenz zur AGPL passen müsste.

**Was erlaubt bleibt:** ✓, ✗, —, · und Verwandte. Das sind Schriftzeichen und
keine Emoji; sie tragen die Prüfliste der Passwortfelder und die Trenner in
Kopfzeilen.

Geprüft von `test_no_vue_template_uses_an_emoji`. Die Regel lautet dort „Emoji
und nicht ASCII": `\p{Emoji}` allein trifft auch Ziffern, `#` und `*`, weil sie
die Grundlage der Tastenkappen-Emoji sind.

## 4. Was ausdrücklich bleibt

Diese Wörter sind kein Versehen, sondern das Vokabular des Projekts. Sie sind
gebräuchlich, eindeutig und im Panel durchgehalten:

**Vorgang** (für einen Job mit Live-Ausgabe), **Rückfrage** (für die
Bestätigungsstufen aus [20 §7](20-hostingpanel-neuplan.md)), **Prüfer**,
**Handlungsbedarf**, **Einhängepunkt**, **Sperrliste**, **verwaltet / fremd**,
**Zeitplan**, **Zugang**, **Anmeldung**, **Geheimnis** (für das TOTP-Secret,
weil die Authenticator-Apps es so nennen).

## 4a. Bezeichner sind englisch

**Kommentare, Dokumentation und jeder Text der Oberfläche: deutsch.
Bezeichner: englisch.** Ein Bezeichner ist kein Text, den jemand liest — er
ist der Name, unter dem zwei Stellen im Quelltext sich treffen.

Das galt hier von Anfang an und stand trotzdem nur in `CLAUDE.md`. An dieser
Stelle stand sogar das Gegenteil: „Die Bezeichner im Quelltext sind von alledem
unberührt", mit `api.einspielen` und `ufwEinspielen` als Beispielen. Der Satz
stammt aus dem Vorgängerprojekt und meinte etwas Enges und Richtiges — eine
**Schnittstelle**, die man nicht für einen Wortgeschmack umbenennt, weil auf
der anderen Seite jemand mitliest.

Auf die Klassennamen der eigenen Gestaltung angewandt hat er neun Monate lang
eine zweite Sprache gerechtfertigt: `.knopf`, `.marke`, `.bereich`, `.kennung`,
`.stapelt`, `data-spalte` — rund 110 Namen, und dazu Komponenten mit
`titel`- und `erklaerung`-Eigenschaften. Eine CSS-Klasse ist keine
Schnittstelle nach aussen; sie steht ausschliesslich zwischen `app.css` und
einem Template dieses Repositorys.

Was daraus folgt:

- **CSS-Klassen, Datenattribute, Komponentennamen und ihre Eigenschaften sind
  englisch.** Geprüft von `tests/Feature/ClassNameTest.php` gegen eine
  Wortliste: Wer eine Klasse hinzufügt, trägt ihr Wort dort ein, und die Zeile
  steht im Diff — genau dort, wo ein deutsches Wort auffällt.
- **Eine echte Schnittstelle bleibt, wie sie ist.** Ein JSON-Feld, ein
  Operationsname des Agenten, ein Spaltenname in der Datenbank: Die umzubenennen
  kostet eine Migration oder einen Bruch, und dafür ist ein Wortgeschmack kein
  Grund.
- **Was im Rumpf einer Funktion steht, ist niemandes Schnittstelle.** Lokale
  Variablen und seitenlokale Hilfsfunktionen sind hier nicht geprüft. Wer sie
  anfasst, macht sie englisch mit; ein eigener Durchgang nur dafür lohnt nicht.

## 5. Wenn ein Wort dazukommt

Ein neuer Eintrag in der Liste gehört an drei Stellen gleichzeitig:

1. die Tabelle in Abschnitt 3,
2. `words()` in `tests/Feature/WordChoiceTest.php`,
3. der Ersatz an allen Fundstellen — der Test nennt sie.

Die ersten beiden prüfen sich gegenseitig: `test_the_list_matches_the_document`
schlägt an, wenn ein Wort nur noch an einer der beiden Stellen steht.

Ein Wort landet dort nicht, weil es jemandem missfällt, sondern weil es im Panel
schon einmal falsch stand. Die Liste ist ein Protokoll und keine Stilrichtlinie
für die deutsche Sprache.

## 6. Wo eine Rückmeldung steht

Diese Vorgabe ist am 12. August 2026 dazugekommen, nachdem der Abnahmelauf des
Fernzugriffs gemeldet hatte, dass jede Fehlermeldung **zweimal** auf der Seite
steht (`docs/45 §5`, Befund 3). Sie regelt nicht die Wörter, sondern den Ort —
und gehört trotzdem hierher, aus demselben Grund wie §3a: Es ist eine Eigenschaft
der Oberfläche, die man ohne Regel bei jedem neuen Formular neu entscheidet.

### 6.1 Ein Fehler aus einer Antwort

**Der Satz steht oben, die Markierung am Feld.**

| | |
|---|---|
| Der Satz | einmal, in der Zusammenfassung (`FormErrors.vue`) |
| Das Feld | `:aria-invalid="Boolean(form.errors.x)"` — sonst nichts |
| Am Feld | **kein** Satz, auch nicht gekürzt |

Der Grund für die Zusammenfassung steht in `FormErrors.vue`: Inertia setzt die
Scrollposition nach einer Antwort zurück, der Betreiber landet oben, und eine
rote Zeile weit unten im Formular sieht er nicht — das hat einmal einen halben
Tag gekostet. Der Grund gegen den zweiten Satz ist einfacher: Zwei gleiche Sätze
übereinander liest niemand als „Übersicht und Ort", sondern als Versehen.

Die Markierung ist dabei **genauer** als die Zeile, die sie ersetzt: Sie sitzt am
Bedienelement statt darunter, und sie sagt damit ohne ein Wort, wo gearbeitet
wird. Sie trägt zwei Kanäle, weil Farbe allein nach WCAG 1.4.1 zu wenig wäre —
Farbe **und** Randbreite; die Form dazu steht in `app.css` und nirgends sonst.

**Ein Fehler, der zu keinem Feld gehört, markiert nichts.** Der Sammelfehler
einer Domain, die Grenzen einer ganzen Einstellungsgruppe: Sie stehen allein in
der Zusammenfassung. Es gibt kein Feld, das sie anstreichen könnten, und alle
anzustreichen wäre eine Behauptung über jedes einzelne.

**Und die Gegenrichtung ist die gefährliche:** Eine Seite, die ein Feld markieren
kann, **muss** die Zusammenfassung tragen. Sonst wird ein Rand rot, und kein Wort
sagt warum. `FieldErrorTest` prüft beide Richtungen.

> **Wer die Auskunft an einen Ort verlegt, muss prüfen, dass es den Ort auf jeder
> Seite gibt.**

### 6.2 Eine Meldung, die nie über den Draht geht

Sie bleibt am Feld, und das ist keine Ausnahme von der Regel, sondern ihre
Kehrseite: Die Zusammenfassung liest die **Antwort der letzten Anfrage**. Was
beim Tippen entsteht, kann sie gar nicht kennen.

Es gibt genau eine davon: „Die beiden Eingaben sind nicht gleich." in
`PasswordFields.vue`. Kommt eine dazu, gehört sie an dieselbe Stelle — ans Feld,
mit Satz.

### 6.3 Erfolg wird nie am Feld gemeldet

**Eine grüne Meldung je Vorgang, oben, und keine am Feld.** Das Panel hat dafür
genau eine Stelle: `PanelLayout.vue` rendert `flash.success` als `.notice ok` mit
`role="status"`.

Der Grund ist nicht Symmetrie, sondern die Aufgabe der Markierung. Sie zeigt, wo
noch etwas zu tun ist — sie ist ein Wegweiser zur Arbeit. Erfolg hat keinen Ort,
an dem man weiterarbeitet: Ein grüner Rand an vierzehn Feldern eines
gespeicherten Formulars sagt vierzehnmal dasselbe und weist auf nichts hin. Er
wäre auch der einzige Zustand, den man nicht wegbekommt, ohne etwas kaputt zu
machen.

Dazu kommt, dass Grün am Feld die eine Sache entwertet, für die die Markierung da
ist: Sind Felder ständig eingefärbt, fällt das eine rote nicht mehr auf.

**Ein Vorgang, der auf dem Server weiterläuft, ist kein Erfolg.** Dafür gibt es
das Protokoll und den Zustand der Zeile, nicht die grüne Meldung — sie sagt „ist
geschehen" und nicht „ist beauftragt".

#### Zwei Quellen, ein Ort — ergänzt am 13. August 2026

**Der Ort bleibt `PanelLayout.vue`.** Was dazukommt, ist eine zweite Quelle
dafür: `Composables/useAnnounce.ts`.

Bis P5c war jede Änderung eine Inertia-Antwort, und `flash.success` reichte. Die
**Konsole** aus `docs/46` ist die erste Seite dieses Panels, die über XHR ändert
und dabei stehen bleibt — es gibt dort keine Antwort, die eine Seite aufbaut,
und damit keinen `flash`. Der erste Wurf hat deshalb eine eigene `notice ok` auf
die Seite gesetzt; `FieldErrorTest` hat sie abgewiesen, und zwar zu Recht.

> **Eine Regel, die einen Ort vorschreibt, braucht einen Weg dorthin — sonst baut
> die nächste Seite ihren eigenen.**

`announce('Die Zeile ist angelegt.')` setzt die Meldung, das Layout rendert sie,
und beim nächsten Seitenwechsel ist sie fällig — wie ein `flash`, der eine
Antwort lang lebt. `FieldErrorTest` bleibt unverändert: Es gibt weiter genau eine
Datei mit `notice ok`.
