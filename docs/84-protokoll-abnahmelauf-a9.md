# Protokoll: Der Abnahmelauf von A9

Gefahren am **25. August 2026** auf `cloudsrv24` gegen **`v0.7.1-rc.2`**. Die
Vorschrift ist `docs/83`; sie prüft die sieben Punkte des Abnahmekriteriums aus
`docs/82 §6` und dazu die Punkte 9 und 10, die das Kriterium nicht kennt, weil
es vor Schritt 7 geschrieben wurde.

**Der Lauf ist gefahren, und A9 ist abgenommen** — die Punkte 1 bis 10 sind
gemessen und erfüllt. Die Punkte 11 bis 13 gehören zu A1 und A5 und entscheiden
über A9 nicht; ihre Befunde stehen hier, weil sie irgendwo stehen müssen.

**Sechzehn Befunde, sieben Beobachtungen, ein Wunsch.** Kein einziger davon ist
von einem Test gefunden worden — dasselbe Verhältnis wie in `docs/45`, `docs/48`
und `docs/59`. **Und wie dort steckt die Mehrheit nicht im Prüfling:** sieben der
sechzehn betreffen die Vorschrift, das Prüfmittel oder das Kriterium, nicht das
Panel.

Was offen bleibt, steht in §6 benannt.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

---

## 1. Das Abnahmekriterium, Punkt für Punkt

| Punkt | Kriterium | Ergebnis |
|---|---|---|
| 0 | Welche Fassung läuft | **erfüllt** — `0.7.1-rc.2`, Kanal `beta` |
| 1 | Ein zweites Adminkonto entsteht ohne SSH | **erfüllt** — vier von vier Erwartungen |
| 2 | Der Administrator arbeitet | **erfüllt** — fünf Seiten, jede mit Zeilen |
| 3 | Acht Seiten geben 403 | **erfüllt** — acht von acht |
| 4 | Nichts Verbotenes in der Antwort | **erfüllt** — beide Hälften, Null neben Eins |
| 5 | Die Navigation kommt aus der Policy | **erfüllt** — 3 gegen 11 Einträge |
| 6 | Der letzte Betreiber lässt sich nicht wegnehmen | **erfüllt** |
| 7 | Gesperrt heisst gesperrt, das Protokoll behält den Namen | **erfüllt** — mit Befund 6 und 7 |
| 8 | `srvpanel admin` als Rückweg | **erfüllt** — Erwartung 4 in Punkt 14 nachgeholt |
| 9 | Die Netzbeschränkung | **erfüllt** — 9a bis 9d |
| 10 | Die Sitzungsübersicht | **erfüllt** — fünf von fünf |
| 11 | `apt-get update` mit einer toten Quelle (A1) | **erfüllt** — M5 auf einem echten Server geschlossen |
| 12 | Die Logs gegen ein echtes Journal (A5) | **erfüllt** |
| 13 | Bilder bei 390 und 1440 px | **erfüllt** — vier Lagen, `dokument 0` in jeder |
| 14 | Aufräumen | **erfüllt** — Ausgangsstand plus drei gesperrte Konten |

---

## 2. Der Lauf

### Punkt 0 — Die Fassung

`0.7.1-rc.2`, Kanal `beta`. **`Commit: (unbekannt)` ist der entworfene Zustand
und kein Befund** — `SRVPANEL_COMMIT` wird bewusst nie gesetzt, die Zusage aus
AGPL §13 hängt am Link `/tree/v<version>` im Fussbereich, und der zeigte auf die
richtige Marke.

**Befund 1 entstand hier, bevor der Prüfling drankam:** Die Vorschrift mass
`artisan --version` statt `srvpanel version` — die Fassung des Frameworks statt
die des Panels. Im Dokument berichtigt.

### Punkt 1 — Ein zweites Adminkonto entsteht ohne SSH · Kriterium 1 **erfüllt**

| Erwartet | Belegt |
|---|---|
| Rolle **vorgewählt** auf Administrator | ja, ohne Zutun |
| Passwort erfüllt die Richtlinie | fünf Haken, „sehr stark · 118 Bit" |
| Liste: Administrator · aktiv · **noch nicht** · **noch nie** | alle vier |
| Protokoll trägt Name, Adresse **und Rolle** | `name: Zweite Verwaltung · email: … · role: administrator` |

**Der Protokolleintrag ist mehr als ein Haken.** `docs/66` hat gemeldet, dass
`context` geschrieben und von keiner Oberfläche gelesen wurde — 18 Aufrufe mit
`target:` und ohne `context:`, alle aus P6. Hier steht der Gegenstand in der
Spalte *Einzelheiten*, auf einem echten Server. Das ist die Behebung jenes
Befundes, **nachgesehen statt behauptet**.

### Punkt 2 — Der Administrator arbeitet · Kriterium 2 **erfüllt**

Kunden (2), Pläne (2), Abonnements (3), Domains (4), Datenbanken (1) — jede
Seite lädt, jede Liste trägt Zeilen. Der zweite Faktor kam zuerst;
`RequireTwoFactor` hat vorher nichts durchgelassen.

### Punkt 3 — Acht Seiten geben 403 · Kriterium 3, erste Hälfte **erfüllt**

Acht von acht. **Befund 3** steht auf allen acht Bildern.

### Punkt 4 — Nichts Verbotenes in der Antwort · Kriterium 3, zweite Hälfte **erfüllt**

Beide Browser, und **die Null steht neben einer Eins**: Beim Administrator kein
Treffer auf `abilities`-Geheimnisse, beim Betreiber die erwarteten. Ohne den
zweiten Wert wäre die Null keine Messung.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

Der Weg dorthin hat vier Anläufe gebraucht und **Befund 4** erzeugt.

### Punkt 5 — Die Navigation kommt aus der Policy · Kriterium 4 **erfüllt**

| Rolle | Gruppe SERVER |
|---|---|
| Administrator | Vorgänge · Protokoll · Allgemein — **drei** |
| Betreiber | zusätzlich Logs · Konten · Zugang · PHP-Versionen · Datenbankserver · Mailversand · Zertifikat · DNS-Zugang — **elf** |

Die acht Geheimnisseiten fehlen beim Administrator vollständig; die Überschrift
„Server" steht trotzdem da, weil drei Einträge übrig sind. VERWALTUNG und KONTO
sind bei beiden gleich.

### Punkt 6 — Der letzte Betreiber · Kriterium 5 **erfüllt**

Die Marke **„letzter"** steht neben „Betreiber", der erklärende Satz darunter,
und beide Wege — herabstufen und sperren — sind abgewiesen. `LastOperator`
rechnet mit, statt einen Zustand festzuhalten; die Marke erscheint in dem
Moment, in dem sie wahr wird, und verschwindet wieder.

### Punkt 7 — Gesperrt heisst gesperrt · Kriterium 7 **erfüllt**, mit zwei Befunden

Das Kriterium ist erfüllt: Die nächste **Anmeldung** scheitert, und das Protokoll
behält den Namen des gesperrten Kontos.

**Der Betreiber hat trotzdem hingesehen und berichtet, was der Lauf zugelassen
hätte:** *„Ich bin beim nächsten Klick im zweiten Browser nicht rausgeflogen. Die
Session hatte Bestand."* Daraus wurden **Befund 6** und **Befund 7**.

### Punkt 8 — `srvpanel admin` als Rückweg · Kriterium 6 **erfüllt**

Beide Hälften: das bestehende Konto zurückgeholt (`Passwort von … gesetzt, Konto
aktiv.`), das neue angelegt (`Adminkonto … angelegt.`), beide Male mit Passwort
und dem Hinweis, dass es nicht wiederkommt. Die Anmeldung mit dem ausgegebenen
Passwort gelingt — womit Beobachtung 2 empirisch mitbelegt ist.

**Erwartung 4 war nicht messbar (Befund 8) und ist in Punkt 14 nachgeholt.**

### Punkt 9 — Die Netzbeschränkung · **erfüllt**

**9a** — `94.31.74.201/32` gespeichert, in kanonischer Schreibweise.

**9b** — die Ablehnung greift, mit dem Satz, der sagt, was zu tun ist. Dabei
fielen **Befund 9, 10, 11 und 12**.

**9c** — der Teil, den kein Test ersetzen kann, mit Protokollbelegen statt
Bildschirmfotos:

| | Zeit | Adresse | Eintrag |
|---|---|---|---|
| Betreiber im Mobilfunk | 13:10:21 | `80.187.84.166` | `auth.login.blocked` · `reason: Netz nicht zugelassen` |
| **Kunde** im Mobilfunk | 13:11:45 | `80.187.84.166` | `auth.login` **erfolgreich** |
| Betreiber zurück im WLAN | 13:13:17 | `94.31.74.201` | `auth.login` · `method: totp` |

Die Gegenprobe ist damit **doppelt** belegt — abgewiesener Betreiber und
durchgelassener Kunde unter **derselben** Mobilfunkadresse. Und Schritt 2: die
offene Sitzung hat den Netzwechsel **nicht** überlebt.

**9d** — zurückgenommen, „Die Anmeldung ist wieder von überall möglich."

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

### Punkt 10 — Die Sitzungsübersicht · **erfüllt**

Alle fünf Erwartungen. Auf demselben Bild fiel **Befund 5**.

### Punkt 11 — `apt-get update` mit einer toten Quelle (A1, M5) · **erfüllt**

| | |
|---|---|
| Der Vorgang bricht ab | ✓ bei 10 %, **vor** der Installation |
| Die Meldung **nennt die tote Quelle** | ✓ mit vollständiger Adresse |
| **Nicht** „Unable to locate package php8.2-fpm" | ✓ |
| Sie sagt, was das bedeutet | ✓ „käme veraltet oder gar nicht" |

**M5 ist damit auf einem echten Server geschlossen** — der Befund, mit dem P7b
angefangen hat. Bis dahin las der Betreiber *„Unable to locate package
php8.4-fpm"*: den Zustand richtig gemeldet, die Ursache falsch.

Drei Anläufe hat der Prüfkörper gebraucht (**Befund 13**), und der Weg dorthin
hat **Beobachtung 5** erzeugt — samt ihrem Beweis in einem einzigen
Bildschirmfoto:

```
Ihre Shell:     W: Fehlschlag beim Holen von https://ppa.launchpadcontent.net/…
Agent-Ausgabe:  W: Failed to fetch          https://ppa.launchpadcontent.net/…
```

Zwei Sprachen, ein Lauf. Der Leser sucht `Failed to fetch` und bekommt genau das,
weil `Runner::ENVIRONMENT` seit P0 `LC_ALL=C` erzwingt.

### Punkt 12 — Die Logs gegen ein echtes Journal (A5) · **erfüllt**

Dateien und Journal, jeweils mit echten Zeilen und einer Kopfzeile, die Pfad,
Grösse und Zeitpunkt nennt.

### Punkt 13 — Bilder bei 390 und 1440 px · **erfüllt**

Vier Ansichten in vier Lagen, gemessen mit `tests/bilder-messen.js` im echten
Browser:

| Ansicht | 1440/Hell | 1440/Dunkel | 390/Hell | 390/Dunkel |
|---|---|---|---|---|
| Kontenliste | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 |
| Kontenformular | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 |
| Zugangsseite | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 |
| Menüfläche | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 | 0 · 200/200 · 0 |

*(`dokument` · `gegenprobe` · `schiebt`)*

`versteckt 5` am Kontenformular und `versteckt 2` an der Zugangsseite sind genau
die Zahlen, die im Kopf der Messvorschrift stehen — der Filter aus `docs/66`
Befund 2 arbeitet, statt bloss nichts zu finden. `thema` las in jeder Lage den
gesetzten Wert; die Falle aus A5 (`emulateMedia` statt `data-theme`) ist nicht
zugeschlagen.

**Zwei Genauigkeiten, die hierher gehören:**

1. **Die vierte Ansicht bei 390/Dunkel steht auf „Mein Konto", nicht auf der
   Übersicht.** Gegenstand dieser Ansicht ist die Menüfläche des Administrators,
   und die ist identisch; „Übersicht bei 390/Dunkel" ist streng genommen
   ungemessen. Die gemessene Seite ist der schwerere Fall (sie trägt ein
   Passwortfeld).
2. **Das Kontenformular ist mit fehlenden Regeln gemessen worden.** Das ist
   Befund 16, und er wurde erst *durch* diese Runde gefunden. `dokument 0` bleibt
   gültig — es lief nichts über —, aber die gemessene Seite war nicht die
   gemeinte. Nach dem nächsten Ausliefern gehört diese eine Zeile nachgemessen.

**Und die flache Zeile, die ich für die Konsole gebaut habe, hatte selbst einen
Fehler:** Sie liess `stand` weg — genau das Feld, das die Vorschrift am
19. August bekommen hat, weil das Skript bei jedem Neuladen aus der
Zwischenablage zurückkommt und die nicht sichtbar altert.

> **Ein Werkzeug, das nach jedem Neuladen aus der Zwischenablage kommt, ist so
> alt wie die Zwischenablage und sagt es nicht.** Wer es kürzt, kürzt zuerst das
> Feld weg, das davor schützt.

### Punkt 14 — Aufräumen · **erfüllt**

**Bestand vorher:**

```
1 philipp@netzhost24.de operator      active
7 philipp@homesrv24.de  administrator active
8 wegwerf@cloudlab24.de operator      disabled
9 neu@cloudlab24.de     operator      active
```

**Befund 8 nachgeholt, und zwar am gesperrten Konto** — das ist mehr, als die
Vorschrift verlangt hat:

```
srvpanel admin philipp@homesrv24.de --generate
Passwort von philipp@homesrv24.de gesetzt, Konto aktiv.
rolle=administrator zustand=active
```

Zweierlei auf einmal. **Die Rolle bleibt `administrator`** — `CreateAdmin`
schreibt bei einem bestehenden Konto nur `password` und `status`, und jetzt ist
das gemessen statt erschlossen. **Und der Rückweg aus `status = disabled` ist
gegangen**, ein Fall, den vorher niemand hatte: Punkt 8 hatte ein Konto gerettet,
das ein unbekanntes Passwort ausgesperrt hatte — das ist eine andere Sperre.

**Bestand nachher:**

```
1 philipp@netzhost24.de operator      active
7 philipp@homesrv24.de  administrator disabled
8 wegwerf@cloudlab24.de operator      disabled
9 neu@cloudlab24.de     operator      disabled
```

Netzbeschränkung leer („Keine Beschränkung — Verwaltungskonten können sich von
überall anmelden."), `zzz-tot.list` fort, `rc=0`.

**Beide Zeilen zusammen, und das ist M5:** `ls` meldet `No such file or
directory` **und** `apt-get update` gibt 0 zurück. Der Rückgabewert allein sagt
nichts — er wäre auch bei toter Quelle 0.

---

## 3. Die sechzehn Befunde

**Sieben davon stecken nicht im Prüfling**, sondern in der Vorschrift, im
Prüfmittel oder im Kriterium: 1, 4, 7, 8, 13 — und die beiden Anteile, mit denen
die Vorschrift Befund 6 verfehlt hätte.

### An der Vorschrift und am Prüfmittel

**Befund 1 — `docs/83` Punkt 0 mass die falsche Fassung.** `artisan --version`
nennt Laravel, nicht das Panel. Berichtigt, bevor der Prüfling drankam.

**Befund 4 — der bequeme Weg in Punkt 4 mass den falschen Gegenstand.** Nach dem
Hydrieren räumt Inertias Vue-Adapter `data-page` aus dem DOM; Kriterium 3 fragt
aber nach der **Antwort**.

> **Eine Messung am hydrierten DOM misst, was der Browser behalten hat — nicht,
> was der Server geschickt hat.**

**Befund 7 — Punkt 7 liess zwei Ausgänge zu.** *„…fliegt beim nächsten Klick
heraus — oder spätestens die nächste Anmeldung scheitert"*. Damit war der Punkt
grün, egal was geschah, und konnte Befund 6 nie melden. Gefunden hat ihn, dass
der Betreiber hingesehen und den Unterschied berichtet hat, statt den Haken zu
setzen.

> **Ein Kriterium, das zwei Ausgänge zulässt, misst keinen von beiden.**

**Befund 8 — Erwartung 4 von Punkt 8 war nicht messbar.** Der Prüfkörper war ein
Konto, das **schon Betreiber war**; hätte das Kommando die Rolle überschrieben,
stünde dasselbe Ergebnis da.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

*In Punkt 14 nachgeholt und geschlossen.*

**Befund 13 — der Prüfkörper von Punkt 11 erreichte die Prüfung nicht.** Die
Vorschrift tötete eine **beliebige** apt-Quelle; abbrechen kann
`php.version.install` aber nur an seiner **eigenen**, und `hitting()` fragt
`PhpVersions::sourceUris()`.

> **Ein Prüfkörper, der eine andere Quelle tötet als die, an der die Prüfung
> hängt, erreicht die Prüfung nicht.**

Dieselbe Form wie Befund 8 und wie die weiche Formulierung in Punkt 7 — **die
dritte Vorschrift dieses Laufs, die am Gegenstand vorbeimisst.**

### Am Panel

**Befund 2 — `srvpanel:admin` steht in der Oberfläche.** `Accounts/Form.vue:313`
nennt den artisan-Namen; auf dem Server heisst der Wrapper `srvpanel`. Wer dem
Hinweis folgt, bekommt „command not found". Nachgezählt: die einzige Stelle mit
Doppelpunkt. `CommandReachTest` sieht sie nicht, weil sein Muster `srvpanel` plus
**Leerzeichen** verlangt.

**Befund 3 — die Fehlerseiten sind Laravels Vorgabe.** *„This action is
unauthorized."*, englisch und ausserhalb von „Kontor". `resources/views/errors/`
gibt es nicht, und `WordChoiceTest` liest ausschliesslich `.vue`-Dateien.

> **Ein Wächter, der die geschriebenen Seiten prüft, sagt nichts über die, die
> niemand geschrieben hat.**

A9 hat das erst wichtig gemacht: Vor der Rollentrennung bekam kaum jemand einen
403 zu sehen; jetzt ist er der **entworfene** Zustand für acht Seiten.

**Befund 5 — die Kürzung der Gerätekennung greift zu spät.**
`Sessions::agent()` schneidet bei 120 Zeichen; ein Desktop-Chrome liegt bei
~116, Firefox bei ~90. Der Knopf „Beenden" stand dadurch bei 1440 px ausserhalb
des Sichtbaren.

> **Eine Obergrenze, die über dem tatsächlichen Höchstwert liegt, ist keine.**

Die Grenze sitzt **zwischen** den beiden häufigen Werten: Bei der iPhone-Kennung
greift sie (auf dem Bild als `Mobile/1…` zu sehen), bei der Desktop-Kennung nicht.

**Befund 6 — Sperren beendet keine offene Sitzung.** Keine der sieben
Mittelschichten fragt den Kontozustand; `status` wird bei der **Anmeldung**
gefragt und bei einer laufenden Anfrage nie.

| | |
|---|---|
| Leerlauf bis zum Verfall | `SESSION_LIFETIME` = **120 Minuten** |
| Absolute Obergrenze | **30 Tage** |

Der Leerlauf setzt sich bei jedem Klick zurück. **Ein gesperrtes Adminkonto
behält seine Rechte damit bis zu 30 Tage lang, solange jemand die Sitzung
benutzt.** Das ist der Befund mit der grössten Wirkung, und das Vorbild für die
Behebung steht schon im Stapel: `EnforceAdminNetwork`.

**Befund 9 — Feld und Knopf auf der Zugangsseite sind verschieden hoch.**
`.button.small` ist ausdrücklich „für eine Aktion, die in einer Tabellenzeile
steht"; auf der Zugangsseite steht er neben einem Feld mit `--tap`. Und das
Interessante: Unter `max-width: 720px` bekommt er `min-height: var(--tap)`
zurück.

> **Ein Fehler, den nur die breite Ansicht hat, entgeht einer Prüfung, die auf
> die schmale zielt.**

**Befund 10 — nur der erste falsche Eintrag wird gemeldet.** Ein `throw` im
Schleifenrumpf beendet die Prüfung; alles darunter wird nie angesehen.
`srvpanel access` macht dasselbe, und dort ist es vertretbar.

> **Zwei Eingänge, die dieselbe Prüfung teilen, teilen darum noch nicht dieselbe
> Meldung — eine Liste hat mehr Fehler als eine Kommandozeile.**

**Befund 11 — `form.reset()` stellt den Stand vom Laden wieder her.** Eine
gelöschte Zeile kommt nach dem Speichern zurück, obwohl der Server sie korrekt
entfernt hat. **Die Folge ist kein Anzeigefehler:** Wer sie wiedersieht,
schliesst auf einen Fehlschlag und drückt noch einmal Speichern — und **legt die
Beschränkung wieder an, die er gerade entfernt hat.** Beide Vorgänge melden
Erfolg.

> **Eine Anzeige, die den Zustand vor der Änderung zeigt, verleitet zu der
> Handlung, die die Änderung zurücknimmt.**

**Befund 12 — `.field select` setzt kein `appearance: none`.** Im ganzen
Stylesheet steht das genau einmal, für das Kästchen. Die Auswahl behält damit
iOS Safaris eigene Zeichnung, deren innere Höhe sich zu unserem `padding`
addiert statt es zu ersetzen. In Chromium erreichen beide den Boden und sind
gleich hoch; **iOS rechnet anders.**

**Befund 14 — ein Konto namens „Administrator" mit der Rolle „Betreiber".**
`CreateAdmin.php:95` setzt als Vorgabe `'name' => 'Administrator'`, und
`AdminRole::Administrator` beschriftet sich mit demselben Wort. Der zweite Fall
ist schlimmer als der jetzige: `Administrator / • Administrator`.

> **Ein Name, der eine Klasse beschreibt, wird mehrdeutig, sobald jemand das
> Wort für eine Unterteilung derselben Klasse verwendet — und die Vorgabe steht
> in einer Datei, die man beim Unterteilen aufmacht.**

**Befund 15 — die Sitzungsliste hängt still an `SESSION_DRIVER=database`.**
`Sessions::of()` und `forget()` lesen `DB::table('sessions')`; nichts hält den
Treiber fest. Der Ausfall fällt zur **beruhigenden** Seite: „keine offenen
Sitzungen", während der Leser in einer sitzt.

> **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu tun".**

Das ist der M5-Satz zum vierten Mal in dieser Stufe, diesmal an einem
Konfigurationswert. **Punkt 9c ist davon nicht betroffen** —
`EnforceAdminNetwork` beendet über `Auth::logout()` und `session()->invalidate()`,
und beides gilt für jeden Treiber.

**Befund 16 — ein `<style scoped>` im Vorlagenblock.** *Behoben und
ausgeliefert.* Gemeldet als „die Marke klebt an der Adresse"; der Abstand stand
im Quelltext, aber der Block war zwischen `#breadcrumb` und den ersten Inhalt
gerutscht. Ein `<style>` dort ist Markup, und Vue wirft es weg — **beide** Regeln
der Datei waren fort.

> **Ein Block, der an der falschen Stelle steht, ist kein falsch stehender Block
> — er ist keiner.**

`ClassReachTest` war grün, weil er `<style>` in der **ganzen Datei** suchte.

> **Ein Wächter, der eine Zeichenkette sucht statt eines Blocks, ist grün,
> sobald die Zeichenkette irgendwo steht.**

Gebaut: der Block ans Dateiende, `ClassReachTest` schneidet den Vorlagenblock
heraus, und **`SfcBlockTest`** hält die Regel selbst. Vier Eingriffe in
`tests/waechter-brechen.sh`, jeder einzeln rot belegt — darunter einer, der die
**alte** Fassung von `ClassReachTest` gegen dieselbe kaputte Quelle grün zeigt.
Ausgezählt über alle 66 Komponenten: genau eine war betroffen.

---

## 4. Die sieben Beobachtungen und der Wunsch

**Beobachtung 1 — keine `Content-Security-Policy` am Panel.** Kein Befund dieses
Laufs, aber A9 macht die Frage schärfer: Ein Administrator ist jetzt ein
Betrachter mit weniger Rechten in derselben Anwendung.

**Beobachtung 2 — zwei Schreibweisen fürs Passwort, zwanzig Zeilen
auseinander.** `'password' => 'hashed'` als Cast am Modell, `Hash::make` im
anderen Zweig. Beide Wege sind heute richtig. Sobald jemand den Cast anfasst,
ist eine der beiden falsch.

**Beobachtung 3 — der Wegwerf-Aufsatz stimmt aufs Pixel, für das, was er
misst.** Er kennt iOS nicht, und Befund 12 lebt genau dort. Betrifft nicht nur
diese eine Seite.

**Beobachtung 4 — ein netzbedingt abgewiesener Anmeldeversuch zählt nicht in die
Ratenbegrenzung.** Drei `auth.login.blocked` hintereinander und **kein**
`auth.login.throttled`. Vertretbar — die Zugangsdaten waren richtig, der Block
kommt erst danach —, aber nirgends aufgeschrieben.

**Beobachtung 5 — der apt-Leser hängt an `LC_ALL=C`, und nichts prüft den
Bezug.** Fiele es weg, bliebe `unreachable` für immer leer: lautlos, ohne
Fehler, und M5 wäre wieder da. `LC_ALL=C` hat in P5c schon einmal zugebissen
(die latin1-Aushandlung von `mysql`).

**Beobachtung 6 — die Spalte „Gerät" zeigt das zuletzt gesehene Gerät, nicht
das anmeldende.** *Gemessen und geschlossen; kein Befund.*

Anlass war die markierte Zeile mit einer Android-Kennung, während in
Desktop-Chrome mit Gerätesimulation gemessen wurde. Zwei Erklärungen waren
denkbar, und die zweite — `current` steht auf der falschen Zeile — ist **durch
den Bau ausgeschlossen**: `Sessions::of()` vergleicht `$row->id` mit
`$request->session()->getId()`, also mit der Kennung der Sitzung, die diese
Anfrage bedient. Passt keine, ist **keine** Zeile markiert; eine falsche kann es
nicht werden.

Gemessen wurde deshalb die erste, an **einer** Zeile zu **drei** Zeitpunkten:

| | Kennung | `user_agent` |
|---|---|---|
| normales Fenster | `is0Ptj3Kf…` | `Windows NT 10.0; Win64; x64` |
| Simulation an, **ohne** Neuladen | `is0Ptj3Kf…` | `Windows NT 10.0; Win64; x64` |
| nach dem Neuladen | `is0Ptj3Kf…` | `Linux; Android 15; Pixel 9` |

Die zweite Sitzung desselben Kontos (ein iPhone) blieb über alle drei Läufe
unverändert — sie ist die Gegenprobe dafür, dass sich nicht einfach alles ändert.

> **Zwei Werte sagen, dass sich etwas geändert hat. Der dritte sagt, wodurch.**

Der Wert bewegt sich nicht beim Umschalten, sondern beim **Schreiben der
Sitzung**: Laravels `addRequestInformation()` setzt `ip_address` und
`user_agent` bei jedem Schreibvorgang neu. Beide Spalten tragen also den letzten
Stand und nicht den der Anmeldung.

**Aufgeschrieben gehört es trotzdem**, denn der Kopf von `Sessions::agent()`
sagt, wonach der Leser sucht: *„war das mein Rechner"*. Für ein gestohlenes
Cookie ist der neue Wert die **bessere** Auskunft; für die Frage „welche dieser
Zeilen ist mein altes Telefon" die schlechtere. Die Klasse schreibt sonst sehr
genau auf, was sie liest und was nicht — diese Eigenschaft fehlte.

**Beobachtung 7 — ein Knopf neben einem Feld ist am Schreibtisch 5 px
niedriger.** Beim Beheben von Befund 9 gemessen und nicht behoben: Bei 1440 px
wird ein `.field`-Bedienelement mit `--text-input` und 9 px Polsterung von
selbst **43 px** hoch, ein `.button` mit `--text-table` und 8 px nur **38**.
Beide halten `--tap` ein — die Marke ist ausdrücklich „die kleinste Fläche für
einen Zeiger" und keine Höhe.

> **Zwei Werte, die beide über der Untergrenze liegen, sind darum noch nicht
> gleich.**

Auf der Zugangsseite ist es behoben, weil dort die Zeile keine sichtbare
Beschriftung trägt und die Hülle des Feldes damit genau so hoch ist wie sein
Bedienelement. Auf der **Übersicht** steht dieselbe Paarung („Aktualisieren"
neben dem Selbstlauf), und dort trägt sie ein `.field.inline`, das unter 480 px
zweizeilig wird — ein gestreckter Knopf wäre dann zwei Zeilen hoch. Das ist eine
Frage an das Gestaltungssystem und keine Behebung: **Soll ein Knopf so hoch sein
wie ein Feld?** Bei 390 px stellt sie sich nicht, dort sind beide 44.

**Wunsch 1 — ein QR-Code beim zweiten Faktor.** Der Betreiber hat ihn während
Punkt 2 geäussert, und er trifft eine Lücke, die schon im Code steht:
`TwoFactorSetupController` **spricht an zwei Stellen von einem QR-Code**, den es
nicht gibt. Entschieden: npm-Bibliothek für die **Modulmatrix**, das SVG bauen
wir. Zwei Dinge werden dabei aufgeschrieben, weil sie Ausnahmen sind — der Code
bleibt **dunkel auf hell in beiden Themes** (invertiert scheitert er an vielen
Scannern), und der Wächter hält **eine Quelle**: QR und Textadresse kommen aus
demselben `uri`.

---

## 5. Was dieser Lauf über sich selbst gelernt hat

**Die Mehrheit der Fehler steckt nicht im Prüfling.** Sieben von sechzehn — und
in `docs/45`, `docs/48` und `docs/59` war es dasselbe. Wer einen Abnahmelauf
schreibt, schreibt Code, den niemand ausführt, bis es darauf ankommt.

**Drei Vorschriften dieses Laufs haben am Gegenstand vorbeigemessen** (Befunde 7,
8 und 13), und alle drei nach demselben Muster: Der Prüfkörper zeigt im
Fehlerfall dasselbe wie im Erfolgsfall, oder er erreicht die geprüfte Stelle gar
nicht.

**Der teuerste Befund kam nicht aus einer Messung, sondern daraus, dass der
Betreiber hingesehen hat.** Befund 6 wäre unter der weichen Formulierung von
Punkt 7 nie gemeldet worden. Vier weitere (9, 11, 12 und 16) sind ebenfalls so
entstanden — gemeldet, nicht gemessen.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

**Und der Lauf hat zweimal am Prüfmittel selbst gespart, wo es nicht ging:**
einmal an der flachen Konsolenzeile ohne `stand`, einmal an einer Anweisung, die
zuerst „erst nachsehen" sagte und dann den geratenen Wert einsetzte
(`packages.sury.org` statt `ppa.launchpadcontent.net`).

> **Eine Anweisung, die zuerst „nachsehen" sagt und danach den geratenen Wert
> einsetzt, hat das Nachsehen zur Verzierung gemacht.**

---

## 6. Was offen bleibt

- ~~**„Übersicht bei 390/Dunkel"** ist ungemessen~~ — **nachgeholt am 26. August
  2026.** `dokument: 0`, `gegenprobe: 200/200`, `schiebt: 0`, in allen vier
  Lagen. **Mit einer benannten Grenze:** gemessen gegen eine leere Instanz ohne
  Agenten, die Kacheln zeigen also ihre Leerzustände. Die Anordnung ist damit
  belegt, das Verhalten einer gefüllten Kachel nicht.
- ~~**Das Kontenformular gehört nach dem nächsten Ausliefern nachgemessen**~~ —
  **nachgeholt am 26. August 2026**, und nicht nur über den Überlauf: `dokument:
  0` und `schiebt: 0` in allen vier Lagen sagen über Befund 16 nichts, weil ein
  fehlender Abstand nichts überlaufen lässt. Gemessen wurde deshalb der Abstand
  selbst — `.title-row` steht auf `display: flex` mit `column-gap: 8px`, und die
  sichtbare Lücke zwischen Adresse und Marke beträgt bei 390 px wie bei 1440 px
  **8 px**. Vorher war sie 0.

  > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
  > Betrachter.**

  Beide Regeln des Blocks wirken: Die Gerätekennung steht in der kleineren
  Schrift aus `.agent` und gekürzt. Gemessen auf der **echten** Seite hinter
  `artisan serve` — der Container-Aufsatz aus handgeschriebenem Markup kann
  `<style scoped>` grundsätzlich nicht, und genau darum ging es hier.
- **Die apt-Messrunde auf Debian 12/13 und Ubuntu 22.04** steht seit A1 aus
  (`docs/83 §5`).
- **Teil 3 von M5** — `panel.update` liest nach dem Neustart seine eigene
  Fassung nach — hängt an A1 Schritt 6.
- **A3, A4 und A7** haben weiterhin keine Stufe (`docs/20 §9`).

---

## 7. Was zu bauen bleibt

Nach Dringlichkeit:

1. **Befund 6** — eine Mittelschicht, die den Kontozustand bei jeder Anfrage
   fragt, in der Form von `EnforceAdminNetwork` und direkt daneben im Stapel.
   Der Wächter dafür ist der Zwilling von `AdminNetworkTest`.
2. **Befund 11** — `form.reset()` ersetzen; die Zeile, die zum Wiederanlegen
   verleitet, ist die zweitgefährlichste dieses Laufs.
3. **Befund 10** — Fehler sammeln, dann einmal werfen.
4. **Befund 2** — `srvpanel admin` statt `srvpanel:admin`, und `CommandReachTest`
   um die Doppelpunktform erweitern.
5. **Befund 3** — eigene Fehlerseiten für 403, 404 und 500 in „Kontor", und ein
   Wächter, der auch die Seiten sieht, die es als `.vue` nicht gibt.
6. **Befund 5** — die Kürzung unter die häufigen Werte legen.
7. **Befund 9 und 12** — Feld und Knopf auf gleiche Höhe, `appearance: none`
   für `.field select`.
8. **Befund 14** — die Vorgabe in `CreateAdmin` umbenennen.
9. **Befund 15** — den Sitzungstreiber festhalten, statt ihn vorauszusetzen.
10. **Wunsch 1** — der QR-Code.
11. **Beobachtungen 2, 4 und 5** — ohne Dringlichkeit, aber aufgeschrieben.

**Befund 8 und Befund 16 sind geschlossen**, der eine gemessen, der andere
behoben und ausgeliefert.
