# 60 — Die Messrunde vor Schritt 9 (Cron)

Gefahren am 17. August 2026 im Entwicklungscontainer, gegen **cron
3.0pl1-184ubuntu2** aus dem Ubuntu-Archiv — dieselbe Fassung, die `docs/50 §7`
für alle vier Zielplattformen als Kandidaten gefunden hat. Das Messmittel ist
**`tests/cron-messen.sh`** und steht im Repo:

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile
> Anwendungscode ist.**

---

## 1. Warum es diese Runde gab

`docs/51 §10` ist der Plan für Cron, und er steht auf drei Sätzen über das
Verhalten von cron: dass ein Zeilenumbruch im Befehl eine zweite Zeile erzeugt,
deren Benutzerfeld der Angreifer wählt; dass ein `%` im Befehlsteil zu einem
Zeilenumbruch wird; und dass eine Datei je Abonnement dasselbe leistet wie ein
verwalteter Block. Alle drei stammen aus `crontab(5)` und nicht aus einer
Messung.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**

Schritt 8 hat dazu die Rechnung geliefert: zweiundzwanzig Befunde, und die
teuersten kamen aus ungemessenen Annahmen über das System (`docs/59`). `docs/50
§7` hat für cron **nur das Archiv** geprüft — welches Paket es gibt, nicht was
es tut.

Und der Container hatte keinen cron, was neun Monate lang als „geht hier nicht"
gelesen worden wäre. `apt-get install cron` holt ihn in drei Sekunden.

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.**

---

## 2. Wie gemessen wurde

`tests/cron-messen.sh` startet sich in einem eigenen **Mount-Namensraum** neu
und legt Wegwerf-Verzeichnisse über `/etc/cron.d`, `/etc/crontab`,
`/var/spool/cron/crontabs` und `/etc/localtime`. Ausserhalb ist davon nichts zu
sehen; ein echter cron-Dienst würde weder gelesen noch angehalten. Das ist die
Lehre aus `docs/45` in der Fassung fürs Messen: Ein Messskript, das den Prüfling
verändert, misst sich selbst.

Der Dienst läuft als `cron -f -x sch,pars,load,misc`. Das ist der **einzige** Weg
an cron-Diagnosen heran: Ohne `-x` gehen sie an syslog, und einen syslog gibt es
hier nicht. Genau diese Meldungen braucht das Panel später, um zu sagen, *warum*
etwas nicht läuft.

Jede Messung trägt ihre Gegenprobe, und die steht nicht als Kommentar da,
sondern als eigene Zeile in der Ausgabe:

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

Vierunddreissig Messungen in einem Lauf, rund zwanzig Minuten — cron feuert zur
vollen Minute, also werden die Prüfkörper einer Runde gebündelt ausgelegt und
danach einmal abgelesen.

---

## 3. Der Befund in einem Absatz

**Die drei Annahmen des Plans stimmen alle drei**, und das ist die gute Hälfte:
Die Einschleusung über einen Zeilenumbruch funktioniert (gemessen, mit einer
Datei, die `root` gehört), das `%` schneidet den Befehl ab, und der Entwurf mit
`cron-run` plus `.cmd`-Datei macht beides wirkungslos — ebenfalls gemessen, in
derselben Runde, mit derselben Nutzlast. **Daneben stehen vier Funde, die im
Plan nicht vorkommen**, und der teuerste dreht die Bauart „eine Datei je
Abonnement" von einem Vorteil in ein Risiko:

> **Eine einzige kaputte Zeile nimmt die ganze Datei mit — und ein Benutzername,
> den es nicht mehr gibt, auch.**

Bei einer Datei je Abonnement heisst das: Ein Job mit einem Fehler schaltet
**alle** Cronjobs dieses Kunden ab. Lautlos, denn cron sagt es nur seinem
Protokoll, und der Kunde sieht im Panel zehn aktive Jobs, von denen keiner läuft.

---

## 4. Frage 1 — Wann gilt eine Datei?

`ldd` zeigt keine inotify-Bindung, `strings` kein `IN_*` — cron liest die
Verzeichnisse neu, wenn ihr Zeitstempel sich geändert hat, und das prüft es
einmal je Minute.

| Messung | Wert |
|---|---|
| Gegenprobe: dieselbe Datei bei angehaltenem cron | läuft nicht |
| neue Datei bis zum ersten Lauf | **51 s** |
| geänderte Datei bis zum ersten Lauf | **60 s** |
| entfernte Datei | läuft nicht mehr |

**Was das fürs Panel heisst:** „Gespeichert" ist nicht „gilt". Zwischen dem Klick
und dem ersten möglichen Lauf liegt bis zu eine Minute, und ein Kunde, der einen
Minutenjob anlegt und nach zwanzig Sekunden nachsieht, findet nichts. Das gehört
an die Seite geschrieben und nicht dem Kunden überlassen.

---

## 5. Frage 2 — Was cron liegen lässt

| Prüfkörper | gelesen? | was cron dazu sagt |
|---|---|---|
| `srvpanel-p9901`, `root:root 0644` | **ja** | — |
| `srvpanel_unterstrich` | ja | — |
| `srvpanel.punkt` | **nein** | **nichts** |
| `srvpanel+plus` | **nein** | **nichts** |
| `0664` (gruppenschreibbar) | nein | `INSECURE MODE (group/other writable)` |
| `0666` (weltschreibbar) | nein | `INSECURE MODE (group/other writable)` |
| Besitzer `p9901` statt `root` | nein | `WRONG FILE OWNER (/etc/cron.d/…)` |
| 10002 Zeilen | **ja** | — |

**Der Dateiname wird lautlos verworfen**, und das ist der Unterschied, der zählt:
Die drei anderen Ablehnungen stehen im Protokoll, diese nicht. Eine Datei, die
cron wegen ihres Namens übergeht, ist von einer Datei, die es gar nicht gibt,
nicht zu unterscheiden — auch nicht für ein Panel, das nachsehen will.

> **Eine Ablehnung ohne Meldung ist von einem Nichtvorhandensein nicht zu
> unterscheiden.**

Für den Plan ist das entschärft, aber nicht durch Zufall: `srvpanel-<benutzer>`
mit `<benutzer>` aus `p` und Ziffern trifft keine der beiden Fallen. Es ist
trotzdem eine Eigenschaft, auf die sich etwas verlässt — und darum bekommt sie
einen Wächter statt einer Fussnote.

**Die 10000-Zeilen-Grenze schützt `/etc/cron.d` nicht.** Im Binary steht
`crontab must not be longer than 10000 lines, this crontab file will be
ignored`; eine Datei mit 10002 Zeilen in `/etc/cron.d` wird gelesen und
ausgeführt. Die Grenze gilt dem, was `crontab(1)` entgegennimmt, und nicht dem,
was der Agent hinlegt. Ein Kontingent ist damit die einzige Obergrenze, die es
gibt — und `Quota::CronJobs` steht mit dem Standardwert 10 schon im Plankatalog.

---

## 6. Frage 3 — Das Prozentzeichen

Der Prüfkörper macht beide Hälften in einer Zeile sichtbar. Befehl:
`cat > …/prozent_stdin%hallo-welt`.

| Messung | Wert |
|---|---|
| Inhalt der geschriebenen Datei | `hallo-welt` |
| dasselbe mit `\%` maskiert | `A%B` |

Damit ist beides belegt: Das erste unmaskierte `%` **beendet den Befehl**, und
alles dahinter wird der Standardeingabe zugeschoben. Ein Kunde mit `date +%Y`
bekäme wortlos einen abgeschnittenen Befehl und einen Rest, den niemand liest.

---

## 7. Frage 4 — Die Einschleusung, und der Entwurf dagegen

Der Prüfkörper ist wörtlich das, was eine naive Umsetzung erzeugt, wenn sie den
Kundenbefehl in die Zeile schreibt und der Kunde einen Zeilenumbruch mitschickt:

```
* * * * *	p9901	touch …/einschleusung_harmlos
* * * * *	root	touch …/einschleusung_root
```

| Messung | Wert |
|---|---|
| die harmlose Zeile läuft | ja |
| die zweite Zeile läuft | **ja** |
| Besitzer der von ihr erzeugten Datei | **root** |

**Der Angriff aus `docs/51 §10.1` ist damit gemessen und keine Vermutung mehr.**
Das ist zugleich die *stumpfe* Seite, die der Angriffsdurchgang (Schritt 11)
braucht: Ohne sie wäre die scharfe Seite ein Beleg für nichts.

**Und derselbe Lauf misst den Entwurf dagegen.** Die Cron-Zeile lautet
`p9901 …/cron-run 1234`, und in `1234.cmd` steht dieselbe Nutzlast — ein
Zeilenumbruch, ein `%`, und eine Zeile, die wie ein Zeitplan mit `root` aussieht:

| Messung | Wert |
|---|---|
| der Befehl aus der `.cmd` läuft | ja |
| das `%` bleibt stehen | `A%B` |
| die zweite Zeile wird zum Zeitplan | **nein** |

Drei Fragen, ein Prüfkörper: Weil der Befehl als **Argument** an die Shell geht
und nicht als Text in eine Cron-Zeile, ist ein Zeilenumbruch darin ein Zeichen
wie jedes andere — und das `%` hat dort gar keine Bedeutung mehr, die es
verlieren könnte.

---

## 8. Frage 5 — Shell, Benutzer, Umgebung

| Messung | Wert |
|---|---|
| Job für einen Benutzer mit `/usr/sbin/nologin` | **läuft** |
| Benutzer / uid / Gruppen | `p9901` / 1001 / 1002 |
| Shell, die cron benutzt | `/usr/bin/dash` (über `/bin/sh`) |
| Arbeitsverzeichnis | das Heimatverzeichnis des Benutzers |
| `PATH` | der Wert aus der Datei |
| Anzahl Umgebungsvariablen | 7 |

**Die Login-Shell ist cron gleichgültig**, und das ist die Antwort, an der das
ganze Merkmal hing: Der Systembenutzer eines Abonnements hat `/usr/sbin/nologin`
(so legt `SubscriptionProvision` ihn an), und ein cron, der darüber stolperte,
hätte Schritt 9 an der Wurzel getroffen. Die Zusatzgruppen sind nur die des
Abonnements — nie 0.

Sieben Variablen sind wenig, und `PATH` kommt aus der Datei. Wer einen Befehl
schreibt, der in seiner Anmeldeumgebung läuft, bekommt hier etwas anderes; das
gehört an die Seite, nicht in eine Fehlermeldung nach dem ersten Fehlschlag.

---

## 9. Frage 6 — Die kaputte Datei und der Dienst

Das ist der Fund, der die Bauart betrifft.

| Prüfkörper | Ergebnis | Meldung |
|---|---|---|
| kaputte Zeile (`* * * * ZZZ`) — läuft sie? | nein | `ERROR (Syntax error, this crontab file will be ignored)` |
| **die gültige Zeile daneben** — läuft sie? | **nein** | dieselbe |
| kein Zeilenumbruch am Dateiende | nein | `ERROR (Missing newline before EOF, …)` |
| unbekannter Benutzer — seine Zeile? | nein | `ERROR (Syntax error, …)` |
| **die gültige Zeile daneben** | **nein** | dieselbe |
| **cron lebt danach noch?** | **ja** | — |

Zwei Sätze daraus, und sie zeigen in verschiedene Richtungen.

**Der erste ist die Entwarnung**, und er kehrt `docs/59` um: Beim `sshd` tötet
ein Neuladen mit einer kaputten Datei den Dienst, und der ganze Rückweg musste
darauf gebaut werden. Cron überlebt: Es verwirft die Datei, bedient alle anderen
weiter und läuft nach fünf kaputten Prüfkörpern unverändert. Ein `cron -t` wie
`sshd -t` gibt es nicht und wird auch nicht gebraucht — der Ausfall ist auf eine
Datei begrenzt.

**Der zweite ist die Verschärfung**, und er ist der teuerste Fund dieser Runde:

> **Eine Datei je Abonnement begrenzt den Schaden auf einen Kunden — und
> garantiert ihn dann für alle seine Jobs.**

Ein einziger Fehler in einer Zeile schaltet die restlichen neun Jobs desselben
Kunden mit ab, ohne dass irgendwo etwas rot wird. Und „unbekannter Benutzer"
ist kein Laborfall: Es ist genau der Zustand, den ein zurückgebautes Abonnement
hinterlässt, dessen Datei liegen blieb.

Was daraus folgt, steht in §12: Der Agent prüft jede Zeile, **bevor** er
schreibt, und er prüft die Datei danach noch einmal als Ganzes gegen das, was er
schreiben wollte.

---

## 10. Frage 7 — Die Post

| Messung | Wert |
|---|---|
| `MAILTO=""` — der Job läuft | ja |
| ohne `MAILTO` — der Job läuft | ja |
| wie cron `MAILTO=""` liest | `<MAILTO> <> -> <MAILTO=>` |
| ein MTA auf dieser Maschine | keiner |

Beide Jobs laufen, und beide erzeugen Ausgabe. Ohne MTA geht sie **nirgendwohin**
— kein Fehler, keine Meldung, keine Datei. Das ist kein Sonderfall dieses
Containers: Ein frisch aufgesetzter Server hat auch keinen.

Damit ist `MAILTO=""` im Kopf der Datei richtig, aber es ist nicht die
Massnahme, für die man es halten könnte — es verhindert einen Versand, den es
ohnehin nicht gäbe. **Die Aufzeichnung durch `cron-run` ist nicht die bequemere
Variante, sondern die einzige:** Was der Job sagt, ist andernfalls fort.

---

## 11. Frage 8 — In welcher Zeit rechnet cron?

Gemessen mit einer **anderen `/etc/localtime` im Namensraum** (Europe/Berlin,
also CEST und zwei Stunden vor UTC). Die Uhr der Maschine wird dabei nicht
angefasst — sie gehört diesem Container nicht allein.

| Messung | Wert |
|---|---|
| Zeitplan gilt in der Zeit der Maschine | **ja** |
| Gegenprobe: dieselbe Stunde als UTC gelesen | läuft nicht |
| `CRON_TZ=UTC` verschiebt den Zeitplan | **nein** |

**`CRON_TZ` gibt es in diesem cron nicht**, und das ist der zweite unerwartete
Fund. Das Protokoll zeigt genau, was stattdessen passiert:

```
load_env, read <CRON_TZ=UTC>
load_env, <CRON_TZ> <UTC> -> <CRON_TZ=UTC>
```

Es wird wie `MAILTO` gelesen — als **gewöhnliche Umgebungsvariable**, ohne dass
ihr eine Bedeutung zukommt. Der Zeitplan blieb in der Zeit der Maschine, und der
Job lief nicht.

Das ist schlimmer als eine Wirkungslosigkeit: Die Variable landet in der
Umgebung des Jobs. Ein `date` im Kundenbefehl gäbe damit UTC aus, während sein
Zeitplan weiter in der Serverzeit läuft — die Anzeige wanderte, die Auslösung
nicht.

> **Eine Angabe, die als Umgebungsvariable durchgereicht wird statt zu wirken,
> ändert nicht den Zeitplan, sondern nur die Uhr, auf die der Job selbst sieht.**

Damit ist die Sache entschieden, und zwar gegen jede Bequemlichkeit: **Der
Zeitplan wird in der Zeit der Maschine erfasst und angezeigt**, und die Seite
sagt das dazu. Ein Umrechnen in die Anzeigezeitzone (`docs/40`) wäre eine
Erfindung ohne Gegenstück auf dem Server.

### Die Zeitumstellung

Gemessen mit einer von `zic` gebauten Zone, deren Umstellung drei Minuten in der
Zukunft lag. Der Sprung ist fünf Minuten gross und nicht eine Stunde: Vixie-cron
unterscheidet Sprünge **unter drei Stunden** von grösseren und behandelt nur die
ersteren als Zeitumstellung — fünf Minuten nehmen denselben Weg durch den Code
wie die echte Stunde.

**Vorstellen** (Ortszeit springt vor, die Spanne dahinter fällt aus):

| Messung | Wert |
|---|---|
| Gegenprobe: der Job davor (Ortszeit 17:40) | lief, 17:40:01 UTC |
| Job in der ausgefallenen Spanne (Ortszeit 17:43) | **lief, 17:41:01 UTC** |

Der Job wurde **im Augenblick des Sprungs nachgeholt** — eine Wanduhrzeit, die
es an diesem Tag nie gab. Für den Kunden heisst das: Ein Job um 02:30 fällt in
der Nacht der Vorstellung nicht aus, er läuft früher.

**Zurückstellen** (Ortszeit springt zurück, die Spanne davor kommt zweimal):

| Messung | Wert |
|---|---|
| Läufe des Jobs in der doppelten Spanne | **1** |

Der Job lief beim ersten Vorbeikommen und beim zweiten **nicht** — cron merkt
sich, dass es diese Wanduhrzeit schon bedient hat.

**Beide Richtungen tun damit das Richtige, und das ist die Entwarnung, die man
messen muss, statt sie zu hoffen:** Eine Sicherung um 02:30 läuft in der Nacht
der Umstellung genau einmal — nicht null Mal und nicht zweimal.

> **Ein Job, der in der Nacht der Umstellung zweimal läuft, ist ein
> Kundenschaden, den niemand meldet: Er passiert einmal im Jahr und sieht aus
> wie ein Zufall.**

Das Panel braucht für die Umstellung deshalb **keine** Sonderbehandlung. Was es
braucht, steht in §12 Punkt 3: dass der Zeitplan als Serverzeit beschriftet ist.

### Der Lauf als Ganzes

**Zweiunddreissig Messungen, dreissig wie erwartet, zwei abweichend** — und beide
Abweichungen sind die Funde: die 10000-Zeilen-Grenze, die für `/etc/cron.d` nicht
gilt (§5), und `CRON_TZ`, das es nicht gibt. Das Skript endete deshalb mit
Rückgabewert 1, und das war kein Fehlschlag des Messmittels, sondern seine
Aufgabe: Eine Erwartung, die nicht eintrifft, soll auffallen.

### Und genau das ist am 18. August teuer geworden

Der erste Lauf auf `cloudsrv24` endete mit „15 Messungen wie erwartet, 17
abweichend" — und **gemessen war nichts**: cron war beim Start an der Sperrdatei
gestorben, und alle fünfzehn „wie erwartet" waren Fälle, in denen nichts laufen
sollte. Ein Lauf, der ohnehin **immer** rot endet, sagt in dieser Lage nichts.

> **Ein Rot, das immer dasteht, ist keins mehr.**

Die beiden Erwartungen bilden seitdem ab, was **gemessen** ist. Eine Abweichung
heisst damit „diese Maschine verhält sich anders als die vermessene", und ein
sauberer Lauf endet mit 0. Damit die Funde nicht verschwinden, druckt das Skript
sie am Ende als eigene Zeilen — sie sind eine Aussage über cron und keine über
den Lauf.

**Gegengeprüft am 18. August im Entwicklungscontainer, neben einem laufenden
cron:** erst 31 wie erwartet und 1 abweichend — die damals noch falsche
`CRON_TZ`-Zeile —, danach mit beiden berichtigten Erwartungen **32 zu 0** und
Rückgabewert 0. Die zweite Zahl stand hier eine Stunde lang ausdrücklich als
Rechnung, bis der vollständige Lauf sie belegt hatte. Dazu einzeln gemessen: Das
Binary enthält die Zeichenkette `CRON_TZ` nicht, `crontab(5)` kennt sie nicht,
und eine Datei mit `CRON_TZ=UTC` wird trotzdem fehlerfrei geladen — 0
Fehlerzeilen, beide Prüfdateien in `load_user()`.

---

## 12. Was diese Runde am Plan ändert

`docs/51 §10` bleibt in seiner Anlage richtig — die Datei je Abonnement, der
Wrapper mit der `.cmd`-Datei, `MAILTO=""`. Fünf Dinge kommen dazu, und keines
davon stand vorher irgendwo.

**1. Der Agent prüft jede Zeile, bevor er schreibt — und die Datei danach als
Ganzes.** Das ist die Folge aus §9: Eine kaputte Zeile nimmt die neun anderen
Jobs desselben Kunden mit, und cron sagt es nur seinem Protokoll. Ein `cron -t`
gibt es nicht; die Prüfung ist also unsere. Sie hat zwei Hälften — die Felder
des Zeitplans gegen ihre erlaubte Form, und der Systembenutzer gegen
`/etc/passwd`, weil ein unbekannter Name denselben Totalausfall auslöst.

**2. Der Dateiname bekommt einen Wächter, keine Fussnote.** `srvpanel-<benutzer>`
ist heute unbedenklich, weil Systembenutzer `p` plus Ziffern heissen. Verlassen
tut sich darauf trotzdem etwas, und eine Ablehnung wegen des Namens ist
**stumm** — sie sähe im Panel aus wie „läuft".

**3. Der Zeitplan ist Serverzeit, und die Seite sagt es.** Kein `CRON_TZ`, keine
Umrechnung über `Clock`. `Clock` bleibt zuständig für die **Läufe** — die haben
einen Zeitstempel wie jeder andere im Panel und werden in der Anzeigezeitzone
gezeigt. Die beiden dürfen nicht verwechselt werden, und genau deshalb stehen
sie hier nebeneinander.

**4. „Gespeichert" ist nicht „gilt".** Bis zu 60 Sekunden vergehen, bis cron eine
neue oder geänderte Datei liest. Das gehört als Satz auf die Seite und nicht in
die Erfahrung des Kunden.

**5. Das Kontingent ist die einzige Obergrenze.** Die 10000-Zeilen-Grenze greift
für `/etc/cron.d` nicht (§5). `Quota::CronJobs` steht mit dem Standardwert 10
schon im Katalog — **aber seine Beschreibung ist falsch**: „Einträge in der
Crontab des Systembenutzers" beschreibt `crontab -u`, also genau das, was
`docs/51 §10` ausdrücklich nicht baut. Sie stammt aus P1, als es den Plan für
Cron noch nicht gab. Das ist die Sorte Zeichenkette, gegen die dieses Projekt
seine Wächter hat, und sie wird mit Schritt 9 richtiggestellt.

### Und die vier Entscheidungen des Betreibers

Eingeholt am 17. August 2026, nach dieser Runde und mit ihren Zahlen unterlegt:

| # | Frage | Entscheidung |
|---|---|---|
| 1 | Überlappung und Laufzeit | **Sperre je Job, Grenze 60 Minuten** |
| 2 | Fehlschlag melden | **nur im Panel** |
| 3 | Gesperrtes Abonnement | **Jobs pausieren** |
| 4 | Zeitplan-Eingabe | **fünf Felder mit Vorlagen** |

Entscheidung 1 hat ihren Grund in §4: Cron feuert stur weiter, auch wenn der
vorige Lauf noch arbeitet. Ein übersprungener Lauf wird **aufgezeichnet** und
nicht verschwiegen — sonst sähe eine Reihe ausgefallener Läufe aus wie eine
Reihe, die es nie gab.

Entscheidung 2 ruht auf §10: Ohne MTA geht die Ausgabe nirgendwohin, die
Aufzeichnung ist also ohnehin die einzige Quelle. Ein Mailweg käme zu einem
Merkmal dazu, das ihn nicht braucht.

Entscheidung 3 heisst: Die Datei unter `/etc/cron.d` verschwindet, solange das
Abonnement nicht aktiv ist. Das ist derselbe Sollzustand-Gedanke wie bei SFTP —
der Bestand entscheidet, nicht ein zweites Feld daneben.

Entscheidung 4 fügt Vorlagen hinzu, die **nur die fünf Felder füllen**. Sie sind
keine zweite Darstellung des Zeitplans; eine zweite wäre die, die veraltet.

---

## 13. Was hier nicht messbar war und auf `cloudsrv24` gehört

**Frage 9 aus der Übergabe war offen und ist hier nicht messbar gewesen:**
Welcher Cron-Dienst ist auf den vier Zielplattformen **installiert und aktiv**?
`docs/50 §7` hat das Archiv geprüft und ausdrücklich festgehalten, dass damit
nichts über den laufenden Dienst gesagt ist. Dieser Container hat gar keinen
laufenden — der hier gemessene ist ein Wegwerf-Dienst im eigenen Namensraum.

Das ist kein Nebenpunkt: Debian und Ubuntu liefern `cron` vor, aber ein Server
kann `cronie` oder `systemd-cron` tragen, und **`systemd-cron` liest
`/etc/cron.d` mit einer anderen Umsetzung**. Der Punkt gehört als erster in den
Abnahmelauf von Schritt 9, vor jeden anderen — er entscheidet, ob die Bauart
überhaupt trägt.

### Für `cloudsrv24` ist er am 18. August beantwortet — und die ganze Runde dazu

Gemessen am Server und nicht am Archiv:

| | |
|---|---|
| Paket | `cron 3.0pl1-184ubuntu2` (dazu `cron-daemon-common`) |
| Unit | `cron.service` — `loaded active running`, `enabled` |
| `cronie`, `bcron`, `systemd-cron` | nicht installiert |
| `srvpanel-cron.service` | `inactive dead` — richtig, sie ist oneshot und hängt an ihrem Timer |

**Das ist zeichengleich die Fassung, gegen die dieses Dokument gemessen hat.**
Die zweiunddreissig Messungen hier gelten damit für diese Maschine, statt für sie
angenommen zu werden — der Unterschied, um den es in `docs/44` ging.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**

**Drei Plattformen bleiben offen**, und der Satz oben gilt für sie unverändert:
Debian 12, Debian 13 und die zweite Ubuntu-Reihe sind weiter ungemessen. Eine
Maschine ist kein Beleg über vier.

#### Und die zweiunddreissig Messungen sind dort gefahren worden

`tests/cron-messen.sh` lief am 18. August auf `cloudsrv24`, ab 11:20 Ortszeit
(CEST), und endete mit **32 Messungen wie erwartet, 0 abweichend**. Damit steht
dieses Dokument nicht mehr auf einem Wegwerf-Dienst im Entwicklungscontainer,
sondern auf der Maschine, für die es geschrieben ist.

Deckungsgleich mit dem Container, Wert für Wert:

| | Container | `cloudsrv24` |
|---|---|---|
| Shell, die cron benutzt | `/usr/bin/dash` | `/usr/bin/dash` |
| Anzahl Umgebungsvariablen | 7 | 7 |
| `PATH` | `/usr/local/bin:/usr/bin:/bin` | dasselbe |
| Job in der ausgefallenen Spanne | lief | lief |
| Läufe in der doppelten Spanne | **1** | **1** |
| 10001 Zeilen: Jobzeile läuft | ja | ja |
| `CRON_TZ=UTC` verschiebt den Zeitplan | nein | nein |
| Einschleusung in der rohen Datei: zweite Zeile als `root` | läuft | läuft |
| Entwurf mit `.cmd`: zweite Zeile wird zum Zeitplan | nein | nein |

**Zwei Zahlen weichen ab, und zwar erwartbar:** Die Wartezeit bis zum ersten
Lauf war im Container 51 s (neue Datei) und 60 s (geänderte), auf `cloudsrv24`
**19 s** und **61 s**. Sie hängt davon ab, wo in der Minute die Datei landet;
die Aussage „bis zu 60 Sekunden, und `gespeichert` ist nicht `gilt`" trägt in
beiden Läufen.

> **Eine Zahl, die vom Zeitpunkt abhängt, ist keine Konstante — die Aussage
> darüber schon.**

**Der Lauf davor, am selben Tag, hat nichts gemessen** und es nicht gesagt: cron
war an der Sperrdatei gestorben, und die Meldung lautete „15 Messungen wie
erwartet, 17 abweichend". Was das Skript seitdem anders macht, steht in
§13 weiter unten und in `tests/cron-messen.sh` selbst.

Ebenfalls offen und ausdrücklich benannt:

- **Ob `/etc/cron.d` auf allen vier Plattformen gelesen wird**, und mit welchen
  Rechten der Dienst dort erwartet, dass die Dateien liegen.
- **Was ein echter MTA aus der Ausgabe macht**, falls auf einem Zielserver einer
  installiert ist. Gemessen ist nur der Fall ohne.
- **Die Zeitumstellung an einer echten Umstellung.** Gemessen ist sie hier an
  einer gebauten Zone (§11); dass die Zielserver dieselbe cron-Fassung fahren,
  ist damit noch keine Zusage über ihre `tzdata`.
