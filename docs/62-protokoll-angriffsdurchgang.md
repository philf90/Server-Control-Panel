# 62 — Das Protokoll des Angriffsdurchgangs (P6, Schritt 11)

Der Lauf steht in `docs/61`. Dieses Protokoll entsteht **während** er gefahren
wird, und es ist am 18. August 2026 angelegt worden, als die ersten Punkte
gemessen waren — nicht vorher, denn ein Verweis auf ein Dokument ohne Inhalt ist
ein toter Verweis.

**Es ist unvollständig, und das steht in §3.** Von den zwölf Angriffen und drei
Belegen aus `docs/51 §4` sind **alle fünfzehn gemessen**, Punkt 11 seit dem
19. August in allen 22 Zeilen; offen sind nur noch die Reste in §3. Ein
Protokoll, das seine Lücken nicht nennt, liest sich wie eine Abnahme.

| | |
|---|---|
| Maschine | `cloudsrv24` |
| Panel | `v0.6.0-rc.15` |
| Prüfstand | `main` ab `9cbf55f`, für die Punkte 5, 7 und 8 ab `4fe2e10` (Klon unter `/root/srvpanel-abnahme`) |
| PHP | 8.4.24, alle vierzehn Funktionen der Sandbox vorhanden |
| Kernel | Linux 6.8.0-138-generic |

---

## 1. Was gemessen ist

### Punkt 1 und 2 — `..` und der absolute Pfad

**Erfüllt, und zwar anders als der Plan es schrieb.** `docs/51 §4` verlangte
„abgewiesen"; gemessen wird ein harmloser Erfolg. `Files\Workspace::path()`
normalisiert, statt zurückzuweisen — die Begründung steht in `docs/61 §0b`.

Der Weg der Operation (Abschnitt 4b des Prüfstands), in drei Bauten:

| Bau | Normalisierung / eigener Prozess | Ergebnis |
|---|---|---|
| scharf | ja / ja | dreimal `haelt` |
| stumpf-A (ohne Normalisierung) | nein / ja | dreimal `haelt` |
| stumpf-B (ohne Chroot) | ja / nein | **AUSBRUCH, 3 von 3** |

**Die mittlere Zeile ist die Aussage dieses Punktes.** Dass der Angriff auch
ohne die Normalisierung nichts erreicht, ist der Beleg dafür, dass nicht sie ihn
hält, sondern das Chroot. Wer nur scharf misst, kann die beiden Wände nicht
auseinanderhalten.

> **Eine Gegenprobe, die zwei Wände zugleich wegnimmt, sagt über keine von
> beiden etwas.**

### Punkt 3 und 4 — Symlink lesen, Symlink auflisten

**Erfüllt.** Abschnitt 4 des Prüfstands, jeder Fall mit seiner Gegenprobe: Der
scharfe Zugriff liefert nichts, der stumpfe (derselbe Zugriff ohne Sandbox, als
root) liefert den Inhalt. Fünf Zeilen, alle `haelt`.

Mitgemessen und nicht im Kriterium: `conf/` (`root:root 0640`) bleibt für den
Kunden unlesbar — dort hält nicht das Chroot, sondern das Dateisystem.

### Punkt 5 — durch einen Verweis hinaus schreiben

**Erfüllt**, seit Abschnitt 4c des Prüfstands. Er stand bis zum 18. August offen
mit der Begründung „der Prüfstand liest nur, er schreibt nicht" — Lesen und
Schreiben sind zwei Fragen, und gemessen war nur die erste.

| | |
|---|---|
| `/etc/shadow` davor / danach | `225a48073778…` / `225a48073778…` — unverändert |
| Wegwerfziel ausserhalb, scharf | `unberührt` |
| dasselbe Ziel, ohne Sandbox | `stumpf durchgekommen` |

**Die Gegenprobe geht nie auf `/etc/shadow`**, und das ist eine Entscheidung und
kein Versehen: Sie müsste treffen, und das hiesse, die Kennwortdatei der
Maschine zu überschreiben, auf der der Lauf fährt. Sie nimmt deshalb einen
zweiten Verweis auf eine Wegwerfdatei ausserhalb der Wurzel — dieselbe Form des
Angriffs, ein anderes Ziel. `/etc/shadow` selbst wird nur gemessen.

### Punkt 7 und 8 — die bösartigen Archive

**Erfüllt**, seit Abschnitt 4d. Je Archiv drei Zahlen:

| Archiv | ausserhalb | `beweis` drinnen | entpackt | `unnamed` | ohne Sandbox (`tar -xPf`) |
|---|---|---|---|---|---|
| `../` davor | nichts | ja | 1 | 1 | Datei liegt ausserhalb |
| absoluter Pfad | nichts | ja | 1 | 1 | Datei liegt ausserhalb |

Die dritte Spalte ist die Gegenprobe nach innen: Ein Archiv, das gar nicht
entpackt wird, erzeugt dieselbe Abwesenheit wie eine gehaltene Grenze. Die
letzte ist die nach aussen.

**Die Vorschrift aus `docs/61 §6` war dabei nicht fahrbar** und ist berichtigt.
Sie schrieb `../../../../` — vier Schritte hinauf, was nur von einem flachen
Zielverzeichnis aus reicht. Vom Zielverzeichnis des Prüfstands
(`/var/www/vhosts/<ABO>/httpdocs/boes/ziel`) landen vier Schritte bei
`/var/www/vhosts/<ABO>/tmp/…`, also **innerhalb** der Wurzel des Abonnements.

> **Ein Prüfkörper, dessen Ziel von der Tiefe des Ordners abhängt, misst den
> Ordner und nicht die Grenze.**

Gemeldet hat es der Prüfstand selbst, mit „OHNE MESSUNG".

### Punkt 6 — der Tausch während des Vorgangs

**Erfüllt.** Der Angreifer aus `docs/50 §3`, vier Prozesse, `renameat2` mit
`RENAME_EXCHANGE` — die Zeile „Tauschverfahren: renameat2(RENAME_EXCHANGE),
atomar" steht in allen drei Läufen, also ist FFI da und der Angreifer der
scharfe.

| | scharf | stumpf |
|---|---|---|
| Treffer ausserhalb, 30 000 Runden | **0** | 6407 / 4409 / 5646 |

Drei Läufe, drei verschiedene stumpfe Zahlen — und die Null bleibt Null.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Und der Rückbau dazu** (Abschnitt 6): 0 von 60 Durchgängen treffen etwas
ausserhalb; die stumpfe Fassung trifft nach 12, 1 und 25 Durchgängen.

### Punkt 9 und 10 — die Einschleusung in die Cron-Zeile

**Erfüllt, gemessen im Cron-Lauf** (`docs/60 §13`, 18. August, 32 zu 0):

| | |
|---|---|
| rohe Zeile mit eingeschleustem `root` | **läuft** — der Angriff ist gut gezielt |
| dieselbe Einschleusung in der Bauart des Panels (`.cmd`) | **läuft nicht** |
| `%` im Befehl | bleibt stehen, der Befehl wird nicht abgeschnitten |

Die erste Zeile ist die Gegenprobe: Ohne sie wäre die zweite kein Beleg.

**Offen bleibt die scharfe Hälfte durch das echte Panel** — siehe §3.

### Punkt 13 und 14 — unter wem der Vorgang lief

**Erfüllt, seit `v0.6.0-rc.15`.** Abgelesen im Protokoll des Agenten:

| Operation | `ran_as` |
|---|---|
| `files.tree`, `files.list` (Benutzer `p1136`) | `{"uid":1001,"groups":[1001]}` |
| `subscription.remove` (Benutzer `p1138`) | `{"uid":1002,"groups":[1002]}` |
| `system.info` (Gegenprobe) | **kein Feld** |

Zwei Abonnements ergeben zwei Kennungen — eine Konstante sähe gleich aus.

**Halb offen:** Dass `1001` die Kennung von `p1136` ist, sagt `id` und nicht das
Protokoll. „Nicht null" ist die eine Hälfte von Punkt 13.

### Punkt 9 und 10 — die Einschleusung durch das echte Formular

**Erfüllt**, gemessen am 18. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.17`.
Zwei Cronjobs im Minutentakt, angelegt über das Formular des Kunden; abgelesen
wurde die Platte und nicht die Oberfläche.

**Auf Abonnement 137** — der Systembenutzer `p1136` gehört dorthin, nicht zu 140.
Für diesen Punkt ist das gleichgültig, gemessen werden das Benutzerfeld und die
Trennung von Zeitplan und Befehl. Es steht hier trotzdem, weil die Angabe einen
Tag später gefehlt hat: Punkt 11 lief mit `eigenJob: 4` gegen Abonnement 140,
und diese Kennung gab es dort nie.

**Die Cron-Datei** (`/etc/cron.d/srvpanel-p1136`, `cat -A`):

```
# Von srvpanel verwaltet. Änderungen werden beim nächsten Speichern überschrieben.$
MAILTO=""$
PATH=/usr/local/bin:/usr/bin:/bin$
* * * * *^Ip1136^I/usr/lib/srvpanel/cron-run 4$
* * * * *^Ip1136^I/usr/lib/srvpanel/cron-run 5$
```

| | |
|---|---|
| Zeilen mit Benutzerfeld | **zwei** — eine je Job, keine dritte |
| Benutzerfeld | `p1136`, `root` kommt in der Datei **nicht vor** |
| Kundentext in der Cron-Datei | **kein Zeichen** |
| `/tmp/uebernommen` | gibt es nicht |
| `/tmp/prozent.txt` | `2026` — vierstellig, das `%` wurde nicht abgeschnitten |

**Und die Gegenprobe ist die eigentliche Aussage.** Der eingeschleuste Text
steht **wörtlich** in der Befehlsdatei, der Zeilenumbruch als `$` sichtbar:

```
--- /etc/srvpanel/cron/srvpanel-p1136-4.cmd
echo eins$
* * * * * root touch /tmp/uebernommen$
```

Damit ist ausgeschlossen, dass er einfach nie ankam.

> **Ein Text, der nirgends ankommt, sieht aus wie ein Text, der unschädlich
> gemacht wurde.**

**Warum die Wand hier trägt**, und das ist eine Bauart und keine Prüfung: Die
Zeile in `/etc/cron.d` enthält ausschliesslich den Zeitplan, den Systembenutzer
und `/usr/lib/srvpanel/cron-run <id>`. Der Befehl des Kunden steht in einer
eigenen Datei und wird als **Argument** übergeben — er kann nicht in ein Feld
geraten, in dem crontab einen Benutzer erwartet. Das Formular prüft ihn deshalb
nur als `required|string|max:8192`, ohne Verbot von Zeilenumbrüchen; das ist
folgerichtig und nicht nachlässig.

Dasselbe erklärt Punkt 10: Das `%`-Verhalten von crontab gilt für das
**Befehlsfeld einer crontab-Zeile**, und dort steht kein Kundentext.

### Punkt 11 — der Mandantenübergriff

**Erfüllt in allen 22 Zeilen**, gemessen am 18. und 19. August 2026 auf
`cloudsrv24` gegen `v0.6.0-rc.17` mit `tests/mandant-messen.js`, angemeldet als
der Kunde von Abonnement 140, aufgerufen wurde 137.

| | |
|---|---|
| fremde Kennung, alle 22 Routen | **404**, kein einziger 2xx |
| Grund | die Mandantenklammer — nicht auffindbar |
| Gegenprobe: eigene Kennung | **22 von 22** kamen durch |
| `uebergriffe` / `haengen` | 0 / 0 |

**Es hat fünf Anläufe gebraucht, und die vier davor sind nicht weggeworfen** —
sie stehen unten mit ihren Fehlern. Der fünfte ist der, dessen Zahlen hier
stehen, und er unterscheidet sich in einem Punkt von allen davor: Jede der vier
Zweitkennungen (`eigenJob 6`, `fremdJob 7`, `eigenKey 18`, `fremdKey 16`) war
vorher an der Abo-Kennung *auf ihrer eigenen Seite* abgelesen und nicht aus
einer früheren Messung übernommen.

**Das Kriterium ist berichtigt worden, bevor der Lauf fuhr.** `docs/51 §4`
verlangt „403 …, und zwar aus der Policy und nicht aus einem 404". Der Code
liefert das nicht: `ApplyTenancy` klammert die Abfragen auf die Abonnements des
Kontos, **bevor** die Policy gefragt wird — ein fremdes Abonnement ist schon für
die Auflösung von `{subscription}` unauffindbar. Das ist die stärkere Antwort:
Ein 403 bestätigt die Existenz, ein 404 nicht.

> **Ein Kriterium, das eine Zahl vorschreibt, prüft die Zahl und nicht die
> Wand.**

**Die beiden zuletzt offenen Zeilen sind zu.** `DELETE /sftp/keys/{key}` gab für
die eigene Kennung 405 nach der Weiterleitung auf `/subscriptions/140/sftp` —
dieselbe Form wie die Zeilen 3 und 5: Die Löschung hat stattgefunden, und die
gefolgte Weiterleitung landete danach mit `DELETE` auf einer Nur-GET-Adresse.
`GET /cron/{job}/runs` gab 200.

**Fünf Anläufe, fünf Fehler, keiner im Panel** — vier im Skript, einer in dem,
was ihm übergeben wurde. Dasselbe Verhältnis wie in `docs/45`, `docs/47`,
`docs/48` und `docs/59`.

| # | Fehler | was er geschönt hätte |
|---|---|---|
| 1 | `X-Inertia: true` mitgeschickt | `409` auf jede GET-Route; `HandleInertiaRequests` liegt vor dem `can:`, der 409 belegt die Auflösung und nicht die Policy |
| 2 | `redirect: 'manual'` | `0` auf jede verändernde Route — eine undurchsichtige Weiterleitung, die ein Netzwerkfehler nicht von einem Erfolg unterscheidbar macht |
| 3 | `eindeutig` sah nur auf die fremde Zweitkennung | eine Zeile meldete `ja`, während ihre Gegenprobe „BLIEB HÄNGEN" sagte — ein Widerspruch in derselben Zeile |

> **Ein Statuscode nach einer gefolgten Weiterleitung gehört einer anderen
> Anfrage.**

> **Eine Spalte, die etwas behauptet, das sie nicht geprüft hat, ist schlimmer
> als keine.**

**Und der vierte Fehler hat Daten gekostet.** Der Kopf des Skripts behauptete,
der Lauf verändere nichts — er lasse den Rumpf weg, also scheitere jede
verändernde Route an ihrer eigenen Prüfung. Für zwanzig Routen stimmt das. Für
`DELETE /cron/{job}` und `DELETE /sftp/keys/{key}` nicht: Sie prüfen keinen
Rumpf, sie löschen.

> **Ein Vorgang, der nichts entgegennimmt, hat nichts, woran er scheitern
> kann.**

Der Lauf hat auf `cloudsrv24` **zweimal einen Cronjob gelöscht**, und beim ersten
Mal sah es aus, als sei er „nicht gespeichert worden" — der `GET …/runs` danach
fand ihn nicht mehr, und dessen 404 las sich wie eine gehaltene Grenze. Erst der
Blick in `CronController::destroy` hat es geklärt.

Behoben in drei Teilen: Die beiden löschenden Routen stehen am **Ende** der
Liste (damit die lesenden ihren Gegenstand noch vorfinden), jede Zeile trägt
eine Spalte `Nebenwirkung`, und der Lauf warnt namentlich, bevor er beginnt. Was
sich **nicht** beheben lässt: Für eine löschende Route heisst „durchgelassen"
wörtlich „hat gelöscht". Anders ist ihre Erreichbarkeit nicht zu belegen.

**Und der fünfte Fehler dieses Laufs steckte nicht im Skript, sondern in dem,
was ich ihm übergeben habe.** Der zweite Durchgang lief mit `eigenJob: 4` — der
Kennung aus der Messung der Punkte 9 und 10 (`docs/62`, oben). Drei Zeilen
meldeten daraufhin „BLIEB HÄNGEN": 18, 19 und 21, also genau die drei mit
`{job}`. Die Ursache stand in derselben Datei: Die Cron-Datei jener Messung
heisst `/etc/cron.d/srvpanel-p1136`, und **`p1136` ist der Systembenutzer von
Abonnement 137**, nicht von 140 (140 läuft als `p1139`; beides am 19. August an
`subscription.system_user` neben `subscription.id` abgelesen). Job 4 lag also
auf dem *fremden* Abonnement, und `eigenJob` zeigte ins Leere.

> **Eine Kennung, die man von einer Messung in die nächste mitnimmt, trägt ihr
> Abonnement nicht mit.**

Dass daraus kein falsches Grün wurde, ist allein das Verdienst der Gegenprobe:
Ohne sie hätte die Zeile „fremd 404" gemeldet und wie eine gehaltene Wand
ausgesehen. Für die Wand selbst war der Griff folgenlos — die Route trägt kein
`scopeBindings()`, also wird `{subscription}` vor `{key}` und `{job}` aufgelöst,
und der 404 fliegt aus der Mandantenklammer, bevor die Zweitkennung überhaupt
angefasst wird.

### Punkt 12 — die volle Quota

**Erfüllt, in beiden Hälften**, gemessen am 18. August 2026 auf `cloudsrv24`
gegen `2389c82` (Weg der Operation) und `v0.6.0-rc.17` (durch das Panel).

**Die erste Hälfte** — `tests/quota-messen.php`, Gerät `/dev/vda3`:

| | |
|---|---|
| 1 MB Grenze, 2 MiB geschrieben | der Vorgang **schlägt fehl** (`exec_failed`) |
| kein halb geschriebener Rest | ja |
| die Zieldatei entsteht gar nicht erst | ja |
| Gegenprobe unter 64 MB | gelingt, 2097152 von 2097152 Byte |

**Der Befund daraus:** Die Meldung lautete „Die Datei liess sich nicht
schreiben." und nannte das Kontingent **nicht**. Im Quelltext stand seit P6,
`file_put_contents` melde bei voller Quota die Zahl der geschriebenen Bytes;
gemessen wird **`false`** — PHP wandelt den kurzen Schreibvorgang selbst in einen
Fehlschlag um und wirft die Zahl weg. Die Meldung hing an einer Verzweigung nach
genau diesem Wert, also war die richtige **unerreichbar**.

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

> **Zwei Meldungen für denselben Fall laufen auseinander — und die falsche ist
> die, die man bekommt.**

Der Vergleich selbst hat gehalten: `false !== 2097152` ist ebenso wahr wie eine
kurze Zahl. **Der Vorgang hat nie Erfolg gemeldet** — es ging allein um die
Begründung. Behoben mit einer Meldung statt zwei; `ShortWriteTest` ist der
Wächter, und er war beim Fund selbst grün, weil er den Satz im Quelltext suchte
statt seiner Erreichbarkeit.

**Die zweite Hälfte** — durch das echte Panel, Abonnement `p6-b.invalid`,
Benutzer `p1136`, Grenze 64 MB mit 128 KB Luft, Prüfkörper 700 KB:

| | gemessen |
|---|---|
| Erfolgsmeldung | keine |
| die Begründung | „Die Datei wurde nicht vollständig geschrieben — vermutlich ist das Kontingent erschöpft." |
| wo sie steht | oben in der Zusammenfassung, das Feld bleibt unrot |
| bei 390 px, `scrollWidth − clientWidth` | **0** |
| Gegenprobe (Fenster + 200 px) | **200** |
| Gegenprobe nach oben: Grenze zurück auf den Plan | „Die Datei ist gespeichert." |

Die Meldung bricht innerhalb ihres Kastens auf drei Zeilen um. Das ist die
Stelle, an der `docs/48` einmal 110 px verloren hat, als die Begründung endlich
lesbar wurde.

**Der Lauf hat dabei zweimal seinen Prüfkörper verfehlt**, und beide Male sah es
nach einem Ergebnis aus:

1. Der erste Prüfkörper war 1,5 MB gross und starb an der **1-MiB-Grenze des
   Agentenprotokolls**, bevor er das Kontingent erreichte. Die Seite meldete
   „Anfrage überschreitet 1 MiB." — ein Satz über ein Socket, das der Kunde nicht
   kennt.
2. Die Messung bei 390 px lief auf der Seite **ohne** die Meldung. Null Überlauf,
   und der Gegenstand fehlte.

> **Ein Prüfkörper, der die Seite ohne den Gegenstand misst, misst die Seite und
> nicht den Gegenstand.**

**Und die Untergrenze des Panels hat den Aufbau umgedreht.** `Quota::minimum()`
lässt für Speicherplatz nichts unter **64 MB** zu — mit gutem Grund, ein
Abonnement mit weniger ist kein enges, sondern ein kaputtes. Der Prüfkörper
senkt deshalb nicht die Grenze auf den Bestand, sondern führt den Bestand an die
Grenze heran. Was `tests/quota-messen.php` mit 1 MB misst, ist über die
Oberfläche erst ab 64 MB erreichbar: Der Prüfstand ruft `DiskQuota::apply()`
unmittelbar und kommt an der Prüfung des Formulars vorbei.

### Punkt 12b — `FilesWrite::MAX_BYTES` ist unerreichbar

**Kein Angriff, gefunden beim Bau von Punkt 12.** Drei Grenzen widersprechen
sich:

| | Wert | |
|---|---|---|
| `FilesRead::MAX_BYTES` | 2 MiB | so gross darf eine Datei sein, um sich zu **öffnen** |
| `FilesWrite::MAX_BYTES` | 2 MiB | so gross darf ein Inhalt sein, um sich zu **speichern** |
| `Connection::REQUEST_MAX` | **1 MiB** | so gross darf eine Anfrage an den Agenten sein |

Die dritte steht vor den beiden anderen, also ist die zweite eine Behauptung.

> **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**

**Was der Kunde davon merkt:** Eine Datei zwischen 1 und 2 MiB öffnet sich im
Editor, lässt sich bearbeiten und **nie** speichern. Er bekommt „Anfrage
überschreitet 1 MiB." — eine Auskunft über das Protokoll statt über seine Datei
— und verliert seine Arbeit.

**Behoben am 19. August 2026.** `Connection::CONTENT_MAX` ist die eine Zahl, an
der beide Grenzen hängen (`REQUEST_MAX` minus 64 KiB für die Hülle = 960 KiB);
`FilesWrite` und `FilesRead` führen sie statt einer eigenen, und `Client::call()`
misst die **fertig kodierte Zeile**, bevor sie über den Socket geht. Was sich
öffnen lässt, lässt sich damit auch zurückschreiben, und eine Datei, deren
Maskierung sie dennoch über die Grenze hebt, wird abgewiesen, bevor sie
unterwegs ist. `TransportLimitTest` rechnet alle vier Zusagen nach.

#### Die Begründung dieses Befundes war beim ersten Ausschreiben falsch

Hier stand, deutscher Text wachse als JSON um den Faktor **1,71**, weil aus
einem `ü` die sechs Zeichen `\u00fc` würden — schon eine 620-KB-Datei reisse
also die Grenze. Das gilt für `json_encode` mit seinen Voreinstellungen.
**`Client::call()` setzt seit dem 11. August `JSON_UNESCAPED_UNICODE`**, und
damit bleibt ein `ü` ein `ü`. Nachgemessen am 19. August 2026 mit den Fahnen,
die der Klient wirklich führt:

| Inhalt | roh | als JSON | Faktor |
|---|---|---|---|
| deutsche Prosa | 840 000 | 860 000 | **1,02** |
| nur Umlaute | 1 400 000 | 1 400 000 | **1,00** |
| PHP mit Zeichenketten | 720 000 | 810 000 | 1,12 |
| ASCII ohne Umbruch | 700 000 | 700 000 | 1,00 |
| lauter Steuerzeichen | 400 000 | 2 400 000 | 6,00 |

Der Schluss bleibt derselbe — 2 MiB passen auch als reines ASCII nicht durch
1 MiB —, aber die Zahl war eine andere und die Dringlichkeit auch: Nicht jede
deutsche Datei ab 620 KB war betroffen, sondern jede Datei ab 1 MiB.

> **Ein Faktor, der an anderen Fahnen gemessen wurde, gehört zu einer anderen
> Leitung.**

Deshalb steht in `TransportLimitTest` kein Faktor. Er baut die volle Zeile und
misst sie; die Fahnen stehen als `Client::JSON` an genau einer Stelle, und der
Wächter besteht darauf, dass es dabei bleibt.

### Punkt 15 — ein gültiger Vorgang gelingt

**Erfüllt**, in jedem Lauf und vor allen Nullen: Abschnitt 3 liest eine gültige
Datei („innen") unter `uid=1002`, Gruppe 1002.

### Der Lauf vom 18. August, 14:34 — und was er nebenbei bestätigt hat

Die Punkte 5, 7 und 8 sind auf `cloudsrv24` gegen `4fe2e10` gemessen worden,
und derselbe Aufruf fährt **alle** Abschnitte. Damit sind die früher gemessenen
Punkte ein zweites Mal belegt, mit anderen Zahlen an denselben Stellen:

| | erster Lauf | 18. August, 14:34 |
|---|---|---|
| Rennen, Treffer ausserhalb (30 000 Runden) | scharf 0, stumpf 6407 / 4409 / 5646 | scharf **0**, stumpf **5114** |
| Rückbau | scharf 0 von 60, stumpf nach 12 / 1 / 25 | scharf **0 von 60**, stumpf **1 nach 5** |
| Bau | scharf | scharf (`ja / ja`) |

**Die stumpfen Zahlen sind jedes Mal andere, und die Null bleibt Null** — genau
das macht sie zu einer Messung und nicht zu einer Abwesenheit.

Vor dem Lauf hat `stumpf.sh --zurueck` gemeldet `stumpf-a scharf`,
`stumpf-b scharf`: Es lag nichts von einem früheren Durchgang herum. Rückgabewert
des Prüfstands: **0**, keine Zeile „OHNE MESSUNG".

**Und der erste Anlauf hat nichts gemessen** — er brach mit
`pathspec 'main' did not match any file(s)` ab, weil der Klon vom August keinen
lokalen Zweig `main` hatte und die Befehlsfolge einen erwartete. Die Prüfung, ob
der Prüfkörper überhaupt vorbeikommt, stand eine Zeile zu spät: **nach** dem
Umschalten statt davor.

> **Eine Vorprüfung, die hinter dem Schritt steht, den sie absichern soll, ist
> keine.**

---

## 2. Was der Lauf über sich selbst gelernt hat

**Die ersten drei Läufe haben nichts gemessen, und sie sahen aus wie eine
Messung.** `stumpf.sh a` und `b` patchen `Files\Workspace`; Abschnitt 4 des
Prüfstands ruft `Sandbox::run()` unmittelbar auf und kommt dort nie vorbei. Die
drei Logs waren Zeile für Zeile identisch.

`stumpf.sh --pruefen` meldete dabei zu Recht „ist stumpf" — es hatte die Wand
geöffnet und nachgewiesen, dass sie offen ist. Nur ging niemand hindurch.

> **Ein Nachweis, dass der Eingriff wirkt, sagt nichts darüber, dass der
> Prüfkörper dort vorbeikommt.**

Daraus sind vier Änderungen entstanden, alle in `9cbf55f`:

1. Abschnitt **4b** nimmt den Weg einer echten Operation.
2. Der Prüfstand **nennt den Bau**, aus dem er kommt — erkannt am Verhalten.
   Drei Läufe, die das nicht tun, sind ein Log dreimal.
3. **`stumpf-c` ist weggefallen**: Durch die Cron-Wand geht kein Prüfkörper.
4. Ein Wächter hält den Bezug fest, damit das nicht wiederkommt.

**Und der Bau der Punkte 7 und 8 hat einen Fehler gefunden, der mit dem Angriff
nichts zu tun hat.** `Archive::names()` zählte ein Tar mit `foreach (new
PharData($archive) as $file)` auf, und diese Schleife läuft über die **oberste
Ebene**. Ein gewöhnliches Archiv mit `oben.txt`, `dir/mitte.txt` und
`dir/sub/tief.txt` ergab `entries: 2` statt 5: `oben.txt` wurde geschrieben,
`dir` landete unter „verlegt", und die beiden Dateien darunter verschwanden
spurlos. Kein Ausbruch — ein Merkmal, das für jedes Tar mit einem
Unterverzeichnis das Falsche tat, seit es das Merkmal gibt.

> **Eine Aufzählung, die Ebenen hat, zählt nicht dasselbe wie eine, die keine
> hat.**

Zip war nie betroffen (`ZipArchive` zählt über den Index auf). Der Wächter dazu
ist `ArchiveDepthTest`; er baut seine Archive Byte für Byte selbst, weil
`PharData` keinen Eintrag mit `..` schreiben kann und weil ein Archiv aus dem
Prüfling den Prüfling gegen sich prüfte.

**Und kein Wächter dieses Repos hätte ihn finden können**, weil keiner je ein
Archiv gebaut hat. Gefunden hat ihn ein Prüfstand für eine andere Frage — zum
dritten Mal in diesem Lauf ist der Befund dort entstanden, wo niemand gesucht
hat.

**Zum vierten Mal dieselbe Falle mit dem Pfad im Chroot.** Der erste Wurf von 4d
reichte den Pfad der Maschine in die Sandbox hinein, wo dasselbe Verzeichnis
anders heisst. Diesmal war es ein Absturz und kein falsches Grün — die drei Male
davor (Abschnitte 4, 4b, 4c) sahen aus wie „hält".

---

## 3. Was offen ist

**Kein Punkt ist mehr ungemessen.** Sie stehen hier einzeln, weil
ein Protokoll ohne seine Lücken sich wie eine Abnahme liest.

**Alle fünfzehn Punkte sind gemessen, und Punkt 11 in allen 22 Zeilen.** Die
beiden zuletzt offenen — `DELETE /sftp/keys/{key}` und `GET /cron/{job}/runs` —
sind am 19. August geschlossen worden, nachdem für beide ein echter Gegenstand
auf dem eigenen Abonnement lag. Offen sind nur noch die Reste weiter unten.

**Die Punkte 5, 7, 8 und 12 sind am 18. August dazugekommen** und stehen oben in
§1. Punkt 12 ist als einziger **durch die echte Route** gemessen, in beiden
Hälften. Was an 5, 7 und 8 offen bleibt, ist dasselbe wie bei 9 und 10: Der
Prüfstand geht den Weg der Operation, nicht den durch die Route. Für 7 und 8
hiesse das `POST /subscriptions/<ABO>/files/extract` mit einem hochgeladenen
Archiv.

**Punkt 12b ist behoben** (`FilesWrite::MAX_BYTES` war unerreichbar), und dabei
ist die Begründung des Befundes berichtigt worden — der Faktor 1,71 war an
anderen JSON-Fahnen gemessen als denen des Klienten. Beides steht oben.

Dazu die halbe Hälfte von Punkt 13 (`id p1136`), und aus `docs/61 §0`:
`/var/lib/srvpanel` gehört auf dieser Maschine `root:root 0755` statt
`srvpanel:srvpanel 0750`, wie `nfpm.yaml` es deklariert.

**Damit ist P6 nicht abgenommen**, und `v0.6.0` kommt danach und nicht davor.
