# 73 — Die Admin-Ansicht im Vergleich mit Plesk und cPanel

Geschrieben am 22. August 2026 auf die Frage des Betreibers, welche Funktionen
für den **Serveradministrator** noch sinnvoll in die Admin-Ansicht gehören.
Genannt war als Beispiel die Verwaltung der Paketquellen und der Systemupdates
über apt — sie steht unten als Vorschlag **A1** und ist der am weitesten
ausgearbeitete, weil das Fundament dafür schon liegt.

Dieses Dokument ist **kein Plan**. Es ist die Bestandsaufnahme, der Vergleich
und eine Empfehlung zur Reihenfolge. Was daraus gebaut wird, entscheidet der
Betreiber; jeder Vorschlag, der drankommt, bekommt vorher seine eigene
Messrunde nach dem Muster von `docs/50`, `docs/57`, `docs/60` und `docs/71`.

> **Wissen aus zweiter Hand sieht aus wie Wissen.** Was hier über apt, nftables
> und fail2ban steht, ist gelesen und nicht gemessen. Die Trennlinie steht bei
> jedem Vorschlag unter „Was vorher zu messen ist".

---

## 1. Was die Admin-Ansicht heute kann

Ausgezählt am Quelltext, nicht aus dem Gedächtnis (`PanelLayout.vue` Zeile
201–235, `OverviewController`, `agent/src/Registry.php`).

**Das Menü des Admins** hat heute fünfzehn Einträge in vier Gruppen:

| Gruppe | Einträge |
|---|---|
| — | Übersicht |
| Verwaltung | Kunden · Pläne · Abonnements · Domains · Datenbanken · Vorgänge · Protokoll |
| Einstellungen | Allgemein · PHP-Versionen · Datenbankserver · Mailversand · Zertifikat · DNS-Zugang |
| Konto | Mein Konto |

**Die Übersicht** ist die einzige Seite, die den Server selbst zeigt, und sie
zeigt mehr als es aussieht: Rechnername, Kernel **mit der Frage, ob ein neuerer
installiert ist und nicht läuft** (`kernel_stale`), Distribution, Laufzeit, fünf
Kennzahlenkacheln mit Spikelines über sechzig Punkte (CPU, RAM, Load, Netz ein
und aus, Platten-IO), die Dateisysteme mit Füllstand, die grössten Prozesse und
den Zustand von drei Diensten plus den PostgreSQL-Clustern.

**Was der Agent an Systemnähe schon kann** (aus 94 Operationen die, die den
Server und nicht ein Abonnement betreffen):

| Operation | Was sie tut |
|---|---|
| `system.info` | alles oben; ein Aufruf, nicht drei |
| `service.status` | `systemctl show` je Unit — auch `NRestarts` und `UnitFileState` |
| `service.action` | start/stop/restart/reload/enable/disable, Positivliste: `srvpanel-*`, `nginx`, `mariadb`, `php*-fpm` |
| `config.validate` | die Prüfung vor dem Übernehmen |
| `panel.update` | `apt-get update && apt-get install --only-upgrade srvpanel` über `systemd-run`, ausserhalb der eigenen Kontrollgruppe |
| `php.version.install` / `.remove` / `.list` | apt und `dpkg-query`, Paketname aus zwei Positivlisten |
| `pg.server.install` | dasselbe für PostgreSQL |
| `web.logs.tail` / `web.logrotate` | Zugriffs- und Fehlerlog je Domain |
| `dns.check` | der Abgleich aus P7 |

**Damit ist die wichtigste Feststellung dieses Dokuments schon getroffen:** Das
Panel spricht heute an fünf Stellen mit apt und liest an drei davon den Erfolg
über `dpkg-query` nach, statt ihn zu glauben. Was für Vorschlag A1 fehlt, ist
nicht das Verfahren, sondern die Ansicht und drei bis vier Operationen.

---

## 2. Was der Plan noch vorsieht

Aus `docs/20 §9` und §11, unverändert zitiert, nur auf die Admin-Seite
verkürzt:

- **P8** (0.9) — Sicherungen je Abonnement, Zeitpläne, Ziele lokal/S3/SFTP,
  Wiederherstellung durch den Kunden, Prüflauf.
- **P9** (0.10) — Statistik mit Spikelines je Abo und Domain, Auswertung der
  Zugriffslogs, **Ressourcenüberwachung mit Schwellen und Benachrichtigungen**,
  Kundenbenachrichtigungen, Branding, **„Serververwaltung für den Admin:
  Dienste, Pakete und Systemupdates, Firewall (nftables), Fail2ban-Jails,
  Logs"**, API v1 mit OpenAPI, Dokumentation.
- **P10** (1.0) — Angriffsdurchgang über alle Module, Lasttest, externer Review,
  Upgrade-Pfad, Freigabe.
- **Nach der 1.0** — Mail, Reseller, **Import aus Plesk-/cPanel-Sicherungen**,
  WordPress-Toolkit, Node/Python je Abo, RHEL, mehrere Server.

**Der eine Satz aus P9 trägt fünf Themen** — Dienste, Pakete, Systemupdates,
Firewall, Fail2ban, Logs — und steht in einer Stufe, die mit 3–4 Wochen
veranschlagt ist und daneben noch Statistik, Benachrichtigungen, Branding, API
und Dokumentation trägt. Das ist die Beobachtung, aus der §6 seine Empfehlung
zieht.

---

## 3. Der Vergleich — Admin-Seite

Plesk („Tools & Settings", Server Administration) und cPanel/WHM, gegen den
Stand hier. Die Spalte **Bewertung** ist meine, nicht die der Hersteller.

### 3.1 Server und Betriebssystem

| Funktion | Plesk | cPanel/WHM | SrvPanel heute | geplant | Bewertung |
|---|---|---|---|---|---|
| Systemzustand, Last, Speicher, Platte | Health Monitor, Advanced Monitoring (Grafana) | Server Status, Load | **ja**, Kacheln + Spikelines | P9 je Abo | gleichauf, mit weniger Fläche |
| Dienste starten/stoppen | Services Management | Service Manager, Restart Services | **teilweise** — Zustand für 3 Units, Aktion für 4 Muster | P9 | **Lücke A2** |
| systemd-Timer / Zeitpläne des Servers | — | — | nein | — | **Lücke A6**, und hier besser als beide |
| Systemupdates (apt/yum) | System Updates, automatische Updates | System Update, Update Preferences | nein (nur Panel-Update) | P9 | **Lücke A1** |
| Paketquellen | teilweise (Komponenten-Installer) | EasyApache 4 | nein — Sury liegt in einem eigenen `.deb` | — | **Lücke A1** |
| Neustart, Neustart-nötig-Anzeige | ja | ja | halb — `kernel_stale` wird erhoben und gezeigt, es gibt keinen Knopf | — | **Lücke A11** |
| Zeit, Zeitzone, NTP | ja | ja | nur die **Anzeige**zeitzone (`docs/40`) | — | **Lücke A11**, klein |
| Rechnername | ja | Basic Setup | nur Anzeige | — | richtig so |
| Terminal im Browser | Extension | Terminal | nein | nein | **bewusst nicht**, §5 |

### 3.2 Sicherheit

| Funktion | Plesk | cPanel/WHM | SrvPanel heute | geplant | Bewertung |
|---|---|---|---|---|---|
| Firewall | Plesk Firewall | Host Access Control / CSF | nein — die Oberfläche sagt es ausdrücklich (`docs/36:838`) | P9 | **Lücke A3** |
| Brute-Force-Schutz | Fail2Ban | cPHulk | nur für die Panel-Anmeldung (§6.4) | P9 | **Lücke A4** |
| SSH-Zugang des Admins, Schlüssel | Extension | SSH Access | nur SFTP je Abo (P6) | — | bewusst offen |
| 2FA | ja | ja | **ja**, TOTP mit Schrittsperre | — | gleichauf |
| Zugangsbeschränkung für das Panel (IP) | ja | ja | nein | — | **Lücke A9** |
| Weitere Admins, Rollen | Additional Administrators | Reseller/Root | nein — genau ein Admin | Reseller post-1.0 | **Lücke A9** |
| Sitzungen sehen und beenden | ja | ja | nein | — | **Lücke A9**, klein |
| Malware-Scan | ImunifyAV | ImunifyAV | nein | nein | **bewusst nicht**, §5 — mit einer billigen Hälfte, A13 |
| WAF / ModSecurity | ja, drei Regelwerke | ja, Anbieterwahl | nein | nein | **bewusst nicht**, §5 |
| Angriffsprotokoll, Aktionsprotokoll | Action Log | — | **ja**, seit P1, mit Filter und CSV | — | **besser hier** |

### 3.3 Betrieb

| Funktion | Plesk | cPanel/WHM | SrvPanel heute | geplant | Bewertung |
|---|---|---|---|---|---|
| Logs zentral ansehen | Log Browser | Log Programs | nur je Domain | P9 | **Lücke A5** |
| apt-/Änderungshistorie | — | — | nein | — | **Lücke A5**, und hier besser als beide |
| Benachrichtigungen, Schwellen | Health Monitor, Notifications | Contact Manager, Notifications | nein — Mailversand ist eingerichtet und **weckt niemanden** | P9 | **Lücke A7** |
| Sicherung des Servers/Panels | Backup Manager | Backup Configuration | nein | P8 (je Abo) | **Lücke A15** |
| Diagnose und Reparatur | `plesk repair` | — | `srvpanel acceptance` auf der Kommandozeile | — | **Lücke A10** |
| Wartungsmodus | ja | — | nein | — | **Lücke A12** |
| API mit Tokens | ja | WHM API | nein | P9 | geplant, richtig eingeordnet |
| Migration von Fremdpanels | Migrator | Transfer Tool | nein | post-1.0 | richtig eingeordnet |
| IP-Adressen verwalten | ja | IP Functions | nein | — | **Lücke A8**, klein |

### 3.4 Hosting-Funktionen (zur Einordnung, nicht Gegenstand der Frage)

| Funktion | Plesk | cPanel | SrvPanel |
|---|---|---|---|
| Kunden, Pläne, Kontingente | ja | ja | **ja** |
| Domains, vhosts, PHP je Abo | ja | ja | **ja**, mit eigenem FPM-Pool |
| PHP-Versionen | ja | MultiPHP | **ja** |
| Datenbanken MariaDB/PostgreSQL | ja | nur MySQL/MariaDB | **ja, beide** — hier besser als cPanel |
| Datenbank-Oberfläche | phpMyAdmin | phpMyAdmin | **ja, eigene** (P5c), ohne freies SQL |
| Dateimanager | ja | ja | **ja** (P6) |
| SFTP/FTP | ja | ja | **SFTP** (P6), kein FTP |
| Cron je Abo | ja | ja | **ja** (P6) |
| TLS, Let's Encrypt, Wildcard | ja | AutoSSL | **ja** (P4), acht DNS-Anbieter |
| DNS | eigener Nameserver | eigener Nameserver | **Abgleich statt Zone** (P7, `docs/72 §1`) — bewusst anders |
| Mail | ja | ja | nein, Nicht-Ziel der 1.0 |
| Sicherungen | ja | ja | P8 |

---

## 4. Die Vorschläge

Sechzehn, geordnet nach Bereich. Die Reihenfolge zum Bauen steht in §6.

Jeder Vorschlag nennt: **Was** · **Warum** · **Wie es in die Architektur passt**
· **Was vorher zu messen ist** · **Die Falle** · **Aufwand**.

---

### A1 — Paketquellen und Systemupdates über apt

**Der Vorschlag des Betreibers, und der grösste dieser Liste.**

**Was.** Vier Teile, die getrennt fertig werden können:

**(a) Anzeigen, was aktualisierbar ist.** Zwei Operationen, weil zwei
verschiedene Dinge: `system.packages.refresh` ist mutierend (`apt-get update`
schreibt nach `/var/lib/apt/lists`), `system.packages.list` ist es nicht. Die
Liste kommt aus `apt-get --simulate dist-upgrade` und wird über die
`Inst`-Zeilen gelesen; dort steht auch die **Herkunft**, und daran hängt die
Trennung zwischen Sicherheitsupdate und gewöhnlichem. Dazu zwei Zahlen, die
beide gehören: was aktualisiert wird, und was **zurückgehalten** wird.

**(b) Einspielen.** `system.packages.upgrade` mit drei Formen: alles · nur
Sicherheit · benannte Pakete. Die Namen kommen aus der zuvor gelesenen Liste
und werden dagegen geprüft — **kein Freitext erreicht apt**, dieselbe Regel wie
bei `php.version.install`. Der Lauf geht über `systemd-run` als eigene
transiente Unit, weil ein Upgrade `srvpanel`, `php8.4-fpm`, `nginx` oder
`mariadb` mitnehmen kann; `panel.update` kennt diesen Weg samt Protokolldatei
und Sperre gegen den zweiten Lauf.

**(c) Paketquellen.** `system.sources.list` liest **alle** Quellen und zeigt je
Quelle: Datei, URI, Suite, Komponenten, den Signaturschlüssel mit Fingerabdruck
und **Ablaufdatum**, ob sie erreichbar ist und wie viele installierte Pakete von
dort kommen. Geschrieben wird ausschliesslich, was das Panel selbst angelegt
hat (`/etc/apt/sources.list.d/srvpanel-*.sources`) — der Bestand ist Gesetz.
Fremde Quellen werden gelesen, angezeigt und über `Enabled: no` schaltbar
gemacht; das ist reversibel und ändert an ihrem Inhalt nichts.

Die Sury-Quelle gehört als Erste hierher: Sie ist heute im Panel unsichtbar,
liegt in einem eigenen `.deb` (`packaging/php-source.sh`) und ist die Quelle
für jede PHP-Version, die das Panel anbietet.

**(d) Unbeaufsichtigte Updates.** Nicht nachbauen, was die Distribution
betreibt: `unattended-upgrades` wird über eine eigene Datei
(`/etc/apt/apt.conf.d/60srvpanel-unattended`, verwalteter Block) konfiguriert,
und das Panel zeigt an, was zuletzt lief und was es getan hat.

**Warum.** Drei Gründe, in dieser Reihenfolge:

1. **Ein Panel, das apt nicht kennt, lügt über den Zustand des Servers.** Es
   zeigt Kernel und `kernel_stale`, sagt aber nicht, dass seit sechs Wochen
   vierzig Sicherheitsupdates offen sind.
2. **Die Paketquelle ist die Ursache der Klasse von Fehlern, die dieses Projekt
   am teuersten bezahlt** — eine Zeichenkette, die auf etwas verweist, ohne dass
   etwas den Bezug prüft. Ein abgelaufener Signaturschlüssel bricht
   `apt-get update`, und man merkt es beim nächsten Panel-Update.
3. Es ist der einzige Bereich, in dem **Plesk und cPanel beide** eine
   ausgebaute Fläche haben und hier nichts steht.

**Wie es passt.** Sehr gut, und das ist der eigentliche Befund: Fünf Operationen
sprechen schon mit apt, drei lesen den Erfolg über `dpkg-query` nach, und
`panel.update` löst bereits das schwerste Teilproblem (der Lauf, der den eigenen
Prozess beendet). Neu sind vier Operationen und eine Seite.

**Was vorher zu messen ist.** Die Ausgabe von `apt-get -s dist-upgrade` auf
**allen vier Zielplattformen** — vier apt-Fassungen, und `deb822` gegen
einzeilige `.list` ist noch einmal ein Unterschied. Dazu: ob
`/run/reboot-required` überhaupt existiert (es kommt von
`update-notifier-common` und ist auf einem Server nicht immer installiert),
wie ein abgelaufener Signaturschlüssel sich meldet, und was `apt-get upgrade`
gegenüber `dist-upgrade` zurückhält. **Der Container kann das** — apt ist da,
und die Lehre aus `docs/46 §2.3` gilt: „Es ist nicht da" und „es geht nicht"
sind zwei Sätze.

**Die Fallen**, benannt und nicht entdeckt:

1. **Zwei apt-Läufe gleichzeitig enden in der dpkg-Sperre.** `panel.update` hat
   die Prüfung; sie muss für **alle** apt-Operationen an **einer** Stelle
   stehen. Zwei Listen, die dasselbe meinen, laufen auseinander.
2. **Ein Upgrade kann das Panel mitnehmen.** Die Ansicht muss überleben, dass
   die Verbindung abreisst — `panel.update` weiss das, die neue Operation erbt
   es, und die Seite braucht denselben Hinweis.
3. **Conffiles.** Ohne `--force-confold` hängt der Lauf an einer Frage, die
   niemand sieht. Mit `confold` bleibt der Bestand — richtig — und es entstehen
   `.dpkg-dist`-Dateien, die **angezeigt gehören**. Sonst ist es eine
   Entscheidung, die das Panel für den Betreiber trifft und verschweigt.
4. **Erfolg wird gelesen, nicht geglaubt.** Nach dem Lauf wird die Liste neu
   erhoben. `apt` meldet auch dann Erfolg, wenn nichts passiert ist.
5. **Ein `reboot-required`, das man anzeigt und nicht anstossen kann,** ist die
   halbe Auskunft. Der Knopf gehört dazu — mit Bestätigung durch Eingabe des
   Rechnernamens, und die Seite danach muss die Wiederkehr abwarten können.
6. **Eine Quelle hinzuzufügen heisst, root über apt weiterzugeben.** Deshalb im
   ersten Wurf: anzeigen, schalten, und **nur** die Quellen anbieten, die das
   Panel ohnehin kennt (Sury, PGDG, das eigene Repo). Freies Hinzufügen ist eine
   eigene Entscheidung des Betreibers und kein Nebeneffekt dieses Merkmals.

**Aufwand.** 2–3 Wochen. Das ist eine eigene Stufe, keine Ergänzung.

---

### A2 — Dienste und Timer

**Was.** Eine Seite „Dienste" mit **allen** Units, die das Panel betreibt oder
braucht — heute sind drei fest verdrahtet (`OverviewController` Zeile 503) und
`service.action` kennt vier Muster. Es fehlen `postgresql`, `ssh`, `cron` und
die vier eigenen Timer. Je Unit: Zustand, seit wann, **Neustartzähler**
(`ServiceStatus` liest `NRestarts` längst und niemand zeigt es), die letzten
Journalzeilen, und die Aktionen aus der Positivliste.

Die Timer eigens und mit ihrem **nächsten Termin**.

**Warum.** Wegen des Befundes vom 19. August: `srvpanel-cron.timer` meldete
`active`, `NEXT` stand auf `-`, und der letzte Lauf lag 22 Stunden zurück. Zwei
von drei Timern waren so gebaut.

> **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
> abgeschaltet und sieht aus wie eingeschaltet.**

Dieser Satz steht heute in `CLAUDE.md` und in `TimerRearmTest`. Er gehört in
die Oberfläche: Der Wächter fängt den Bau, die Anzeige fängt den Betrieb.

**Wie es passt.** `service.status` liefert schon fast alles; neu sind eine
Journal-Operation (nicht mutierend, feste Zeilenzahl, Unit aus der Positivliste)
und die Erweiterung der Listen. Die Positivliste wächst **im Code des Agenten**
— so steht es dort als Kommentar, und so bleibt es.

**Die Falle.** Eine Unit-Liste an zwei Stellen (Übersicht und neue Seite) ist
dieselbe Falle wie die Argumentliste in `Db\Session`: zwei Listen, die dasselbe
meinen. Sie gehört einmal in den Agenten.

**Aufwand.** 1 Woche. **Bestes Verhältnis dieser Liste nach A5.**

---

### A3 — Firewall (nftables)

**Was.** In zwei Würfen, und der erste ist nur Anzeige: welche Ports lauschen
(`ss -ltnp`), welches Regelwerk läuft, und ob die Ports, die das Panel geöffnet
hat (3306, 5432, 22), von aussen erreichbar sind. Erst der zweite Wurf schreibt,
und dann in eine **eigene** nftables-Tabelle, die den Bestand nicht anfasst.

**Warum.** Das Panel öffnet Ports — der Fernzugriff auf MariaDB und PostgreSQL
tut genau das — und kann heute nicht sagen, ob sie erreichbar sind. Die
Oberfläche sagt an dieser Stelle ausdrücklich, die Firewall sei nicht Sache des
Panels (`docs/36:838`). Das ist ehrlich und trotzdem eine halbe Auskunft.

**Was vorher zu messen ist.** Welche vier Möglichkeiten es gibt (`nft`,
`iptables-nft`, `ufw`, `firewalld`) und wie man sie unterscheidet — und ob
überhaupt eine läuft. Auf einem gemieteten Server steht oft eine Cloud-Firewall
davor, die das Panel gar nicht sieht; eine Anzeige „Port offen", die das
verschweigt, ist falsch.

**Die Falle.** Eine Firewall über das Panel zu verwalten heisst, sich über das
Panel aussperren zu können. Jede Änderung braucht eine **Rücknahme nach Zeit**
— der neue Regelsatz gilt, und wenn niemand innerhalb von zwei Minuten
bestätigt, stellt eine transiente Unit den alten wieder her.

> **Ein Rückweg, der voraussetzt, dass man noch drankommt, ist keiner für den
> Fall, dass genau dieser Vorgang einen aussperrt.**

Derselbe Satz wie beim `sshd` in `CLAUDE.md`, an einer anderen Sache.

**Aufwand.** 2 Wochen für beide Würfe. Der erste allein: 4 Tage.

---

### A4 — Anmeldeschutz (fail2ban)

**Was.** Lesen, welche Jails es gibt, wie viele Sperren stehen und wer gesperrt
ist; entsperren; eine eigene Jail für das Panel-Log.

**Warum.** Ein SFTP-Zugang, den das Panel anlegt, ist ein Ziel, das das Panel
geschaffen hat. Der Schutz dafür ist nicht Sache des Kunden.

**Die Falle.** Ein Entsperren-Knopf ohne Prüfung ist „beliebige Zeichenkette an
fail2ban". Die Adresse gehört validiert, der Jailname kommt aus der gelesenen
Liste.

**Aufwand.** 1 Woche.

---

### A5 — Logs an einer Stelle

**Was.** Eine Seite „Logs" mit einer **Positivliste von Quellen**: das Panel
(`laravel.log`, `update.log`, der Agent), das Journal ausgewählter Units, die
**apt-Historie** (`/var/log/apt/history.log`), die Anmeldungen
(`/var/log/auth.log`) und die Logs von nginx, das nicht zu einer Domain gehört.
Je Quelle: Tail, Filter, Herunterladen.

**Warum.** Heute gibt es Logs nur je Domain. Und `/var/log/apt/history.log`
beantwortet die Frage „was hat sich auf diesem Server verändert" besser als
jedes Panel-Protokoll — es steht dort, wer wann welches Paket auf welche
Fassung gebracht hat, auch wenn es an der Kommandozeile geschah.

**Die Falle.** Kein Dateipfad aus dem Formular. Die Quellen sind eine Liste im
Agenten, und `web.logs.tail` zeigt die Form dafür bereits.

**Aufwand.** 3–5 Tage. **Bestes Verhältnis dieser Liste.**

---

### A6 — Die Zeitpläne des Servers

**Was.** Eine Leseansicht von `/etc/crontab`, `/etc/cron.d`, `/etc/cron.daily|weekly`
— neben den Timern aus A2.

**Warum.** Das Panel schreibt selbst nach `/etc/cron.d/srvpanel-*` und betreibt
vier Timer. Der Admin sieht davon nichts an einer Stelle.

**Lesen, nicht schreiben.** Was der Admin ändern will, ändert er als root; was
das Panel schreibt, schreibt es über seine eigenen Dateien. Ein Editor für
`/etc/crontab` wäre Freitext mit Systemrechten über einen Umweg.

**Aufwand.** 2–3 Tage, zusammen mit A2 fast umsonst.

---

### A7 — Schwellen und Benachrichtigungen

**Was.** Schwellen je Kennzahl mit zwei Kanälen (Mail, Webhook): Platte voll,
RAM, Load, Dienst tot, Timer ohne nächsten Termin, Zertifikat läuft ab,
Sicherung fehlgeschlagen, Sicherheitsupdates offen, Signaturschlüssel läuft ab.

**Warum.** Die Kennzahlen sind da, der Mailversand ist eingerichtet und
testbar — und niemand wird geweckt. Das ist heute die grösste Lücke zwischen
„das Panel weiss es" und „jemand erfährt es".

**Die Fallen.** Zwei, und die zweite ist die wichtige:

1. **Entprellung.** Eine Platte, die um die Schwelle pendelt, sind 400 Mails.
2. **Eine Meldung über einen Ausfall, die über den ausgefallenen Weg geht,
   kommt nicht an.** Der Mailversand läuft über diesen Server. Deshalb der
   Webhook als zweiter Kanal — und eine Anzeige „zuletzt erfolgreich
   zugestellt", weil ein Kanal, der schweigt, von einem, der nichts zu melden
   hat, sonst nicht zu unterscheiden ist.

**Aufwand.** 1,5 Wochen.

---

### A8 — IP-Adressen des Servers

**Was.** Anzeigen, welche Adressen der Server hat, welche das Panel als
Sollzustand für den DNS-Abgleich benutzt und welche die Standardadresse für neue
Domains ist.

**Warum.** P7 vergleicht DNS gegen diese Adressen (`docs/72 §2.1a`). Wenn der
Abgleich „zeigt woandershin" meldet, ist die erste Frage: gegen **was** hat er
verglichen?

**Aufwand.** 2–3 Tage, am besten zusammen mit P7.

---

### A9 — Mehrere Admins, Rollen, Zugang zum Panel

**Was.** Vier kleine Dinge: ein zweiter Admin (Vertretung), ein Admin mit
Nur-Lese-Recht (Support), IP-Beschränkung für die Panel-Anmeldung, erzwungene
2FA für Admins — dazu eine Sitzungsübersicht mit „hier abmelden".

**Warum.** Heute gibt es genau **einen** Admin. Fällt er aus oder verliert er
sein zweites Merkmal, ist niemand mehr da. Und ein Support-Zugang, der nur
lesen darf, ist die häufigste Anforderung eines Betriebs mit mehr als einer
Person.

**Wie es passt.** Das Rechtemodell trägt es schon: Autorisierung sitzt an der
Aktion, jede Route trägt `can:`, `AbilityReachTest` prüft beide Richtungen. Es
fehlt die Fläche, nicht das Fundament.

**Aufwand.** 1 Woche. **Hoher Nutzen, kleiner Aufwand.**

---

### A10 — Diagnose des Bestands

**Was.** Ein Knopf und ein Nachtlauf, die den Bestand prüfen: `nginx -t`,
`php-fpm -t`, `sshd -t`, Agent erreichbar, **jeder Timer mit nächstem Termin**,
Zertifikate gültig und für den richtigen Namen, Quota lesbar (nicht nur in den
Mount-Optionen — `docs/41`), verwaltete Blöcke unversehrt, Systembenutzer
vorhanden, verwaiste Zeilen, Sury-Schlüssel gültig.

**Warum.** Das ist Plesks `plesk repair`, und für dieses Projekt ist es mehr:
Die Mehrzahl der teuren Befunde aus `docs/45`, `docs/62` und `docs/66` waren
Zustände, die ein regelmässiger Bestandslauf gefunden hätte — ein `pg_hba.conf`,
in die ein zweiter schreibt; ein Timer ohne Termin; eine Quelle, deren Schlüssel
abläuft.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat.** Ein
> Bestandslauf ist das „jemand", das jede Nacht nachsieht.

`srvpanel acceptance` gibt es auf der Kommandozeile. Was fehlt, ist die Fläche
und der Zeitplan.

**Aufwand.** 1 Woche, und der Nutzen wächst mit jeder Stufe danach.

---

### A11 — Neustart, Zeit, Rechnername

**Was.** Drei kleine Dinge auf einer Seite: Neustart mit Bestätigung durch
Eingabe des Rechnernamens (Anlass: `kernel_stale` und `reboot-required` aus A1);
Zeitzone des Servers und NTP-Zustand **neben** der Anzeigezeitzone aus
`docs/40`, weil die beiden sonst verwechselt werden; Rechnername nur anzeigen.

**Ändern des Rechnernamens ist kein Knopf** — es nimmt Zertifikate, vhosts und
den DNS-Abgleich mit.

**Aufwand.** 2 Tage.

---

### A12 — Wartungsmodus

**Was.** Ein Schalter, der alle Kundenseiten auf eine 503-Seite legt und das
Panel erreichbar lässt.

**Warum.** Er ist die ehrliche Antwort auf „das Upgrade nimmt gleich php-fpm
mit" und gehört deshalb neben A1.

**Aufwand.** 3 Tage.

---

### A13 — Die billige Hälfte des Malware-Scans

**Was.** Kein Scanner. Ein Nachtlauf, der auf das Auffällige sieht: Dateien mit
Rechten 0777, frisch geänderte PHP-Dateien ausserhalb bekannter Pfade,
`eval(base64_decode` als reine Textsuche, Schreibrechte auf Verzeichnisse, die
keine brauchen.

**Warum.** Ein echter Scanner ist ein Produkt mit laufender Signaturpflege und
keine Funktion. Diese Hälfte kostet drei Tage und fängt den häufigsten Fall.

**Aufwand.** 3 Tage. Am besten als Teil von A10.

---

### A14 — API-Tokens

Geplant für P9. **Nicht vorziehen** — aber: Wer A1 baut, schneidet die
Operationen so, dass eine API sie später aufrufen kann. Das kostet beim Bauen
nichts und später viel.

---

### A15 — Die Sicherung, die P8 nicht plant

P8 plant Sicherungen **je Abonnement**. Was ein Serveradministrator zusätzlich
braucht, ist die Sicherung des Panels selbst: die Datenbank, `/etc/srvpanel`,
die Zertifikate, die verwalteten Blöcke.

**Ein Panel, dessen Datenbank weg ist, hat alle Abonnements verloren — auch
wenn deren Dateien noch liegen.** Die Zuordnung von Systembenutzer zu Kunde
steht in der Datenbank und nirgends sonst.

Das gehört **in** P8 hinein und nicht daneben. Kein eigener Vorschlag, sondern
eine Ergänzung des Plans.

---

### A16 — Import aus Plesk/cPanel

Post-1.0, richtig eingeordnet. Kein Vorziehen. Eine Anmerkung: Der Import steht
im Plan als „der Weg zu echten Kunden" — und er hängt an P8, weil ein Import
ohne ein beschriebenes Sicherungsformat zweimal gebaut wird.

---

## 5. Was ich ausdrücklich **nicht** vorschlage

- **Terminal im Browser.** Es widerspricht der ersten Grenze wörtlich: Jedes
  Zeichen wäre Freitext mit Systemrechten. Wer SSH hat, braucht es nicht; wer
  es nicht hat, soll es nicht hierüber bekommen.
- **Eigener Nameserver.** Entschieden am 21. August, `docs/72 §1`.
- **Mailserver.** Nicht-Ziel der 1.0, und die grösste Einzelstufe danach.
- **ModSecurity/WAF.** Laufende Regelpflege, hohe Fehlalarmquote, und ein
  falsches Regelwerk nimmt Kundenseiten vom Netz. Post-1.0, wenn überhaupt.
- **Erweiterungs- oder App-Katalog.** Nicht-Ziel.
- **Editor für fremde Konfigurationsdateien.** Ein „nginx-Direktiven"-Feld, wie
  Plesk es hat, ist der Weg, auf dem Freitext doch noch in eine
  Konfigurationsdatei kommt. `DirectiveAllowlistTest` gibt es aus gutem Grund.

---

## 6. Empfehlung

### 6.1 Die Reihenfolge nach Nutzen je Aufwand

| # | Vorschlag | Aufwand | Warum hier |
|---|---|---|---|
| 1 | **A5** Logs | 3–5 Tage | kleinster Aufwand, sofort jeden Tag nützlich |
| 2 | **A2** Dienste und Timer | 1 Woche | enthält den Timer-Befund als Anzeige |
| 3 | **A10** Diagnose | 1 Woche | zahlt sich bei jeder folgenden Abnahme aus |
| 4 | **A9** Admins und Zugang | 1 Woche | Fundament liegt, nur die Fläche fehlt |
| 5 | **A1** Pakete und Updates | 2–3 Wochen | die eigentliche Stufe, Fundament liegt |
| 6 | **A7** Schwellen und Meldungen | 1,5 Wochen | braucht A2 und A10 als Quellen |
| 7 | **A3** Firewall | 2 Wochen | braucht eine eigene Messrunde |
| 8 | **A4** Anmeldeschutz | 1 Woche | nach A3, gleiche Ecke |

Nebenher, je 2–3 Tage: **A11**, **A6**, **A8**, **A12**, **A13**.

**Summe: rund zehn Wochen.**

### 6.2 Und daraus die eine Beobachtung zum Plan

P9 ist heute mit **3–4 Wochen** veranschlagt und trägt: Statistik je Abo und
Domain, Logauswertung, Ressourcenüberwachung mit Benachrichtigungen,
Kundenbenachrichtigungen, Branding, **Serververwaltung mit Diensten, Paketen,
Systemupdates, Firewall, Fail2ban und Logs**, API v1 mit OpenAPI und die
Dokumentation.

Allein der hervorgehobene Teil ist nach dieser Aufstellung acht bis zehn Wochen.

**Vorschlag: P9 teilen.**

- **P9a — Serververwaltung** (A5, A2, A10, A1, A11, A6): rund 6 Wochen.
  Das ist die Stufe, nach der der Betreiber den Server nicht mehr über SSH
  ansehen muss, um zu wissen, wie es ihm geht.
- **P9b — Kundenfähigkeit** (Statistik, Kundenbenachrichtigungen, Branding,
  API, Dokumentation): der ursprüngliche Rest, rund 4 Wochen.
- **P9c — Absicherung des Servers** (A3, A4, A7, A9): rund 5 Wochen. Sie darf
  auch nach P10 kommen, wenn der Server hinter einer Cloud-Firewall steht —
  aber dann als Entscheidung und nicht als Versäumnis.

**Und eine kleinere Empfehlung: A5, A2 und A10 vor P8 vorziehen.** Zusammen
zweieinhalb Wochen, und sie machen jede Stufe danach billiger — ein
Diagnoselauf, der jede Nacht den Bestand prüft, hätte in `docs/45`, `docs/62`
und `docs/66` Befunde gefunden, bevor sie ein Abnahmelauf gefunden hat.

### 6.3 Wo das im Menü steht

Die Frage, die dieses Projekt dreimal bezahlt hat, gehört **vor** das Bauen und
nicht danach:

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?**

Heute liegen die sechs Einstellungsseiten flach untereinander, und nichts davon
heisst „Server". Vorschlag für die Admin-Navigation:

| Gruppe | Einträge |
|---|---|
| — | Übersicht |
| Verwaltung | Kunden · Pläne · Abonnements · Domains · Datenbanken |
| **Server** | **Dienste · Pakete und Updates · Logs · Diagnose · Netz** *(später: Firewall)* |
| Betrieb | Vorgänge · Protokoll |
| Einstellungen | Allgemein · PHP-Versionen · Datenbankserver · Mailversand · Zertifikat · DNS-Zugang · **Benachrichtigungen · Admins und Zugang** |
| Konto | Mein Konto |

Ein Neustart gehört dabei **nicht** in ein eigenes Menü, sondern dorthin, wo der
Anlass steht — an die Kernelzeile der Übersicht und an das Ende eines
Paketlaufs. Ein Knopf, den man sucht, wenn man ihn braucht, steht am falschen
Ort; einer, der neben seiner Begründung steht, am richtigen.
