# 49 — Die Übergabe an P6

**Was P6 erbt, worauf es aufsetzt, und was vor der ersten Zeile gemessen
gehört.** Geschrieben am 14. August 2026, nachdem P5c abgeschlossen war.

Dieses Dokument ersetzt weder `CLAUDE.md` noch den Plan (`docs/20`). Es steht
zwischen beiden: Es sammelt, was in dieser Stufe wichtig wird und heute über
zehn Dateien verteilt liegt — so wie `docs/32` es für P4 und `docs/37` für P5b
getan haben.

---

## 1. Wo das Projekt steht

**P0 bis P5c sind abgenommen**, jede Stufe gegen ein Kriterium auf einem echten
Server und nicht gegen eine Schätzung. Ausgeliefert wird `v0.5.3-rc.15`.

| Stufe | Inhalt | Protokoll |
|---|---|---|
| P0–P2 | Agent, Panel, Pakete, Abonnements, Web und PHP | — |
| P3 | Websites, PHP-Fassungen, Isolation | — |
| P4 | TLS, ACME, DNS-01, Platzhalter, eigene Zertifikate | `docs/33`, `docs/34` |
| P5 | Datenbanken, Zugänge, Sicherungen, Zurückspielen (MariaDB) | `docs/36` |
| P5b | PostgreSQL, Fernzugriff | `docs/42`, `docs/45` |
| P5c | Datenbankmanagement — Tabellen, Zeilen, Ändern | `docs/46`, `docs/48` |

**P6 ist die nächste Stufe** (`docs/20 §9`, Fassung 0.7): Dateimanager, SFTP mit
Chroot, FTP-Konten, Cronjobs je Abo, optionaler SSH-Zugang.

> **Fertig, wenn der Angriffsdurchgang für Pfadausbruch, Symlink-Tricks und
> Cron-Befehlseinschleusung durchläuft.**

Das ist das schärfste Abnahmekriterium, das dieses Projekt bisher hatte, und es
ist das erste, das nicht fragt „funktioniert es", sondern „hält es".

---

## 2. Die eine Stelle, an der P6 mit dem bisherigen Muster bricht

**Bis heute nimmt keine Agent-Operation einen Pfad entgegen — sie baut ihn.**
Der Satz steht wörtlich in `agent/src/Ops/SubscriptionProvision.php`:

> Übergeben wird der *Name* des Abonnements, geprüft gegen eine Positivliste;
> der Pfad entsteht hier als `/var/www/vhosts/<name>`. Damit gibt es kein `..`,
> keinen Symlink und keinen absoluten Pfad, den ein Aufrufer unterschieben
> könnte. **Eine Operation, die einen Pfad annimmt und ihn danach prüft, ist
> eine Operation, deren Prüfung irgendwann eine Lücke hat.**

**Ein Dateimanager kann das nicht.** Er bekommt zwangsläufig einen Pfad vom
Kunden — das ist seine Aufgabe. Damit fällt in P6 der Schutz weg, der alle
bisherigen Stufen getragen hat, und muss durch etwas Gleichwertiges ersetzt
werden. Das ist der Kern dieser Stufe und gehört an den Anfang des Plans, nicht
in einen Unterpunkt.

**Und das Zeitfenster ist bereits benannt.** `agent/src/Filesystem.php` hält
fest, was der heutige Baumlauf nicht abdeckt:

> Zwischen der Prüfung und dem Abstieg liegt ein Zeitfenster, in dem ein
> laufender Prozess des Abonnements ein Verzeichnis durch einen Verweis ersetzen
> könnte. Sauber schliessen liesse sich das nur mit `openat(O_NOFOLLOW)`, und
> **das gibt PHP nicht heraus.**

Für einen Baumlauf beim Zurückbauen war das vertretbar. Für einen Dateimanager,
der auf Zuruf des Kunden in dessen eigenem Verzeichnis arbeitet — während dessen
eigene Prozesse laufen —, ist es die Lücke, gegen die der Angriffsdurchgang
antritt.

---

## 3. Was P6 an Fertigem vorfindet

**Das Verzeichnisschema steht seit P2 und ist für Chroot gebaut**
(`docs/20 §4.5`):

```
/var/www/vhosts/beispiel.de/     root:root 0755   ← Chroot-Wurzel für SFTP
    httpdocs/                    p1001:www-data 0750
    <weitere-domain>/            p1001:www-data 0750
    logs/<domain>/…              p1001:adm 0750
    tmp/                         p1001:p1001 0700
    conf/                        root:root 0755
    .ssh/                        p1001:p1001 0700
```

Die Zweiteilung — Wurzel root, Inhalt Kunde — ist **keine Vorsicht, sondern eine
Vorgabe von OpenSSH**: Ein Chroot, dessen Wurzel dem eingesperrten Benutzer
gehört, wird wortlos beim Verbindungsaufbau verweigert. `SubscriptionProvision`
legt sie deshalb schon heute so an, mit `--no-create-home`.

**Weiteres, das P6 benutzt statt neu zu bauen:**

| Sache | Wo | Was P6 davon hat |
|---|---|---|
| Systembenutzer je Abo | `docs/35`, `system_users` | Der Benutzer, unter dem Cronjobs und SFTP laufen; die Reservierung verbrauchter Namen |
| `Lifecycle::claim()` gegen `nextSystemUser()` | `app/Support/Subscriptions/Lifecycle.php` | Der eine verbraucht einen Namen, der andere zeigt ihn nur an — wer sie verwechselt, vergibt `p1000` zweimal |
| Dateisystem-Quota | `docs/41` | Bereits gesetzt; der Dateimanager muss ihr Zuschlagen als Fehler zeigen können |
| PHP-Isolation, `open_basedir` | `docs/28`, `PhpIsolationTest` | Die Grenze, die für PHP schon gilt — der Dateimanager braucht die gleiche |
| Warteschlange, `Operation.type` / `.task` | `RunAgentOperation`, `Lifecycle::afterSuccess()` | Für alles, was länger dauert als eine Antwort (Entpacken, Suche) |
| Befristeter Zugang | P5/P5c | Das Muster für „Rechte nur für die Dauer eines Vorgangs" |
| `Filesystem::remove()` | `agent/src/Filesystem.php` | Der symlink-sichere Baumlauf, den P6 erweitern statt abschreiben muss |

**Und was es noch nicht gibt:** keinen FTP-Server, keinen SFTP-Chroot-Eintrag in
`sshd_config`, keine Cron-Verwaltung, keine Datei-Operationen im Agenten ausser
Anlegen und Entfernen ganzer Bäume.

---

## 4. Was vor dem Plan gemessen gehört

**Bei P5b und P5c hat je eine Messrunde vor der ersten Zeile das
Abnahmekriterium umgeworfen.** Bei P5b war es die Frage, ob ein Datenbankbenutzer
fremde Namen aufzählen kann (Antwort: in PostgreSQL nicht verhinderbar, und der
Versuch nähme dem Kunden `pg_dump`). Bei P5c waren es drei Messungen, die den
Entwurf umgebaut haben, bevor er stand.

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

Für P6 sind das die Fragen, die niemand aus der Dokumentation beantworten
sollte:

1. **`openat2` mit `RESOLVE_BENEATH` — gibt es das auf allen vier
   Zielplattformen?** Debian 12/13, Ubuntu 22.04/24.04. Der Systemaufruf ist ab
   Linux 5.6 da, aber PHP gibt ihn nicht heraus: Es braucht ein kleines
   Hilfsprogramm im Agenten oder einen anderen Weg. **Gemessen wird der Kernel
   des Zielservers, nicht der des Containers.**
2. **Was leistet `realpath()` unter Nebenläufigkeit?** Der heutige Weg ist
   `realpath($x) === $x` plus `is_link`. Der Angriffsdurchgang wird genau dazwischen
   greifen. Ein Wegwerf-Versuch mit einem Prozess, der in einer Schleife
   umbenennt, beantwortet das in Minuten.
3. **OpenSSH-Chroot: Was verlangt die Fassung auf jeder Plattform wirklich?**
   Besitz und Rechte jedes Verzeichnisses **oberhalb** der Wurzel zählen mit.
   `/var/www/vhosts` gehört heute wem? Gemessen, nicht angenommen.
4. **`internal-sftp` im Chroot braucht keine Binärdateien — SSH schon.** Ein
   optionaler SSH-Zugang mit Shell verlangt ein bewohnbares Chroot. Was heisst
   das für `docs/20 §4.5`?
5. **Cron: `crontab` je Benutzer oder `/etc/cron.d`?** Und welcher Dienst ist auf
   jeder Plattform installiert — `cron`, `cronie`, oder systemd-Timer? Die
   Ausgabe soll aufgezeichnet werden, also braucht es einen Wrapper; wo läuft der
   und wem gehört er?
6. **FTP: welcher Server ist im Archiv jeder Zielplattform?** `vsftpd`,
   `proftpd`, keiner? Das entscheidet, ob dieser Punkt überhaupt so gebaut werden
   kann, wie der Plan ihn beschreibt.
7. **Der Editor mit Syntaxhervorhebung** wäre die erste Frontend-Abhängigkeit
   dieses Projekts, das bisher **ohne jede** auskommt (keine Diagramm-Bibliothek,
   `docs/20 §4.6`). Das ist eine Entscheidung des Betreibers, keine des Bauenden.

**Der Container kann mehr, als man denkt** (`CLAUDE.md`, „Diese Umgebung"):
MariaDB und PostgreSQL lassen sich als Wegwerf-Server installieren, npm läuft,
Chromium ist da. Was hier nicht geht, ist `composer install` — und PHPStan und
PHPUnit fehlen deshalb.

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.**

---

## 5. Die Entscheidungen, die vor dem Plan beim Betreiber liegen

Bei P5c waren es vier, und die zweite hat die Architektur bestimmt (**kein freies
SQL** — damit bekam der Agent typisierte Fragen statt einer Anweisung). Für P6
sind das die Kandidaten:

1. **Editor mit Syntaxhervorhebung — ja, und mit welcher Abhängigkeit?** Oder ein
   `<textarea>` mit Zeilennummern und ohne Farben?
2. **FTP überhaupt?** Es ist das unsicherste Protokoll im Plan, und SFTP deckt
   denselben Bedarf. Der Plan nennt es; die Frage ist, ob es bleibt.
3. **SSH mit Shell oder nur SFTP?** Ein bewohnbares Chroot ist eine andere
   Baustelle als ein leeres.
4. **Wie lange wird die Ausgabe eines Cronjobs aufbewahrt**, und was passiert,
   wenn ein Job jede Minute läuft und jedes Mal ein Megabyte ausgibt?
5. **Darf der Dateimanager ausserhalb von `httpdocs` sehen** — also die
   Chroot-Wurzel mitsamt `logs/` und `conf/`, oder nur, was dem Kunden gehört?

---

## 6. Wie in diesem Projekt gearbeitet wird

`CLAUDE.md` ist bindend und steht der neuen Sitzung ohnehin zur Verfügung. Diese
fünf Punkte sind die, an denen P5c am meisten gehangen hat:

**1. Für jede Regel gibt es einen Wächter, und der Wächter wird gegengeprüft.**
Wer eine Regel aufstellt, baut den Test dazu — und **bricht die Regel danach
absichtlich, um zu sehen, dass der Test zubeisst.** Ein Wächter, der nie rot war,
ist kein Wächter. Die Brüche stehen in `tests/waechter-brechen.sh` (379
Eingriffe, 623 Testläufe, gemessen 4:40); der Lauf hängt seit dem 13. August an
jedem Pull Request.

**2. Im Browser nachsehen, nicht nur bauen.** Bei allem Sichtbaren gehört ein
Screenshot dazu, in beiden Themes und bei 390 px, mit der Messung
`scrollWidth - clientWidth` daneben. Drei Fehler waren grün getestet und trotzdem
falsch; `v0.4.0-rc.4` ist mit einem Umbruchfehler ausgeliefert worden, weil die
Bilderrunde einen Tag zu spät kam.

**3. Die Mehrheit der Fehler steckt im Prüfmittel, nicht im Prüfling.** In
`docs/45`, `docs/47` und `docs/48` war es jedes Mal dasselbe Verhältnis: sieben
von zwölf Befunden betrafen den Abnahmelauf selbst. Wer einen Abnahmelauf
schreibt, schreibt Code, den niemand ausführt, bis es darauf ankommt.

**4. Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
steht.** Dreimal in P5c: bei der Frage, ob der Agent gefragt wurde; bei der
Entprellung des Protokolls; beim Nachweis, dass eine Erfolgsmeldung
zurückgenommen wird. Jedes Mal sah die Null wie ein Beleg aus und war keiner.

**5. Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke, sondern eine
Voreinstellung.** In P5c gemessen an `RESET ROLE`, `SET TRANSACTION READ WRITE`
und `SET statement_timeout = 0`. **Für P6 ist dieser Satz zentral**: Eine Grenze,
die im Prozess des Kunden durchgesetzt wird, ist keine.

---

## 7. Ablauf und Werkzeug

- Entwickelt wird auf dem zugewiesenen `claude/…`-Zweig, **nie direkt auf
  `main`**. Ist der PR gemergt, wird der Zweig unter demselben Namen frisch von
  `main` gestartet.
- **Einen Pull Request nur öffnen, wenn ausdrücklich danach gefragt wurde.**
- `git commit -s` (DCO). Der `CHANGELOG.md` hält fest, *warum* etwas so ist und
  was vorher falsch war.
- Freigaben sind annotierte Tags `v<version>` auf `main`. **Privates
  Schlüsselmaterial wird in diesem Container nie erzeugt.**
- Eine Stufe gilt erst als fertig, wenn ihr Abnahmekriterium **nachweisbar**
  erfüllt ist — gemessen auf einem echten Server (`cloudsrv24`), nicht geschätzt.
- Das Protokoll entsteht **während** des Laufs, nicht danach.

---

## 8. Was offen ist und P6 nicht erbt

- **Zwei Punkte aus `docs/42 §5`**, nie gemessen: der `template1`-Beleg und die
  Frage, ob ein Datenbankzugang ohne jede Datenbank entstehen kann. Wer sie
  anfasst, fängt dort an und nicht bei null.
- **Der Bestand aus `docs/48` Punkt 0** steht noch auf `cloudsrv24` — Abo 1130
  mit `gross` (60 Millionen Zeilen, 3,3 GB / 5,1 GB). Zurückbauen, sobald die
  Systemmarke in der Konsole gegen `rc.15` im Browser gesehen wurde.
- **Zwei Ergänzungen an der P5c-Fixture** stehen im Bestand und nicht im Plan:
  `probe.luecke` und `lang.notiz`.
