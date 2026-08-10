# 39 — Die Zwischenabnahme von P5b

**Was hier steht:** ein Testlauf gegen `v0.5.1-rc.2` auf einem echten Server,
neun Punkte, etwa eine Stunde. **Was hier nicht steht:** die Abnahme. Die ist
`38-postgresql.md §19`, sie kommt in Schritt 9, und sie prüft andere Dinge.

---

## 1. Warum es dieses Dokument gibt

Zwei Gründe, und der zweite ist der wichtigere.

**Erstens die Sache.** Zwischen `v0.5.1-rc.1` und `rc.2` liegen zweiundzwanzig
Beiträge — die Schritte 4 bis 7 aus `38 §17`: die Anwendung, die Sperre, die
Messung, Sichern und Zurückspielen, die Oberfläche. Gemessen ist davon vieles,
aber gegen einen **Wegwerf-Cluster im Entwicklungscontainer**. Auf einem Server
mit systemd, mit dem Agenten dazwischen und mit einem Panel, das die Vorgänge
selbst absetzt, ist keiner dieser vier Schritte je im Ganzen gelaufen. Die Frage
dieses Laufs ist deshalb nicht „ist das Kriterium erfüllt", sondern: **trägt es
überhaupt?**

**Zweitens die Form.** Dieser Ablauf stand zuerst nur in einem
Sitzungsverlauf — als Nachricht, nicht als Datei. Er ist damit genau das, wovor
`CLAUDE.md` seit sechs Vorfällen warnt, nur an der letzten Stelle, an der man es
sucht: **eine Anleitung, auf die sich jemand verlässt, ohne dass irgendetwas
ihren Bestand prüft.** Ein Verlauf ist kein Ort. Er lässt sich nicht
durchsuchen, nicht berichtigen, und `DocLinkTest` sieht ihn nicht.

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile Code
> ist.**

Und der Beleg dafür kam am selben Tag: Punkt 0 dieses Laufs enthielt **zwei
falsche Befehle**. Der Betreiber ist an ihnen hängengeblieben, und die
Berichtigung stand danach wieder nur im Verlauf. Sie steht jetzt in §3.

---

## 2. Was man braucht

- `cloudsrv24` mit `v0.5.1-rc.2`.
- **Ein Abonnement zum Wegwerfen.** Der Lauf legt keines an: Eine Kundennummer
  ist auf Dauer verbraucht und ein Systembenutzer erst recht (`docs/35`).
- Etwa eine Stunde.
- Die Bereitschaft, **jede Ausgabe zu schicken — auch die, die richtig
  aussieht.** Der teuerste Fehler von P4 hat `1 fällig, 1 bestellt` gemeldet,
  also genau die Zahl, die das Kriterium verlangte, und das Falsche getan.

Im Folgenden steht `<db>` für die angelegte Datenbank, `<rolle>` für den
Zugang, `<abo>` für das Abonnement und `16` für die Hauptfassung des Clusters —
alle vier bitte durch die echten Werte ersetzen.

---

## 3. Punkt 0 — Fassung und Ausgangslage

```bash
sudo apt update && sudo apt install --only-upgrade srvpanel
dpkg-query -W -f='${Version}\n' srvpanel
readlink /opt/srvpanel/current
sudo -u postgres psql -tAc "SELECT version()"
pg_lsclusters
```

**Erwartet:** `0.5.1-rc.2` aus beiden ersten Befehlen, PostgreSQL 16.14, ein
Cluster `online`.

**Wenn die Fassung eine andere ist, hier abbrechen** — alles Weitere prüfte dann
etwas anderes als das, was gemeint war.

> **Hier standen zwei falsche Befehle, und beide auf dieselbe Art falsch.**
>
> `srvpanel --version` antwortete `Laravel Framework 13.23.0`. Das ist richtig
> und beantwortet die Frage nicht: Der Wrapper reichte den Schalter an `artisan`
> durch, und `artisan --version` nennt die Fassung des **Frameworks**. Ein
> Stellvertreter, gemessen statt der Sache.
>
> `psql -c "SELECT version()" | head -2` schnitt die Ausgabe ab, bevor sie kam —
> `psql` schreibt Kopfzeile und Trenner zuerst. `-tAc` fragt ohne beides.
>
> **Ab `v0.5.1-rc.3` beantwortet `srvpanel version` die Frage selbst**, und
> `srvpanel --version` ebenfalls. Bis dahin gelten die beiden Befehle oben.

---

## 4. Punkt 1 — Die Ausgangsmessung für Punkt 7

Sie muss **vorher** stattfinden, sonst ist Punkt 7c eine Abwesenheit ohne
Vorgeschichte — dieselbe Falle wie `docs/36 §22.3m`.

```bash
sudo grep -c srvpanel /etc/postgresql/16/main/pg_hba.conf
sudo -u postgres psql -tAc "SELECT count(*) FROM pg_roles WHERE rolname='srvpanel_restore'"
```

**Erwartet:** beides `0`. Das Panel legt Zeile und Gruppenrolle erst an, wenn
zum ersten Mal zurückgespielt wird.

Und, wenn die Datenbank aus der Zeit **vor** `v0.5.1-rc.5` stammt, dazu die
Gegenprobe zur Nachrüstung. Sie besteht aus drei Fragen, und **die letzten
beiden sind die belastbaren**:

```bash
sudo -u postgres psql -tAc "SELECT count(*) FROM pg_roles WHERE rolname = '<präfix>_owner'"

sudo -u postgres psql -tAc \
  "SELECT count(*) FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid
    WHERE g.rolname = '<präfix>_owner'"
sudo -u postgres psql -tAc \
  "SELECT count(*) FROM pg_db_role_setting s JOIN pg_roles r ON r.oid = s.setrole
    WHERE r.rolname LIKE '<präfix>\_%'"
```

**Erwartet: dreimal `0`.** Nach Punkt 7 steht dort die Eigentümerrolle, mindestens
ein Mitglied und mindestens ein `role=<präfix>_owner` — **das ist der Beleg dafür,
dass die Nachrüstung gelaufen ist**, und ohne diese Messung davor wäre er nur
eine Beobachtung. Dieselbe Falle wie bei `pg_hba.conf` eine Zeile höher.

> **Hier stand `rolname LIKE '%\_owner'`, und die Zeile hat auf `cloudsrv24` am
> 10. August 2026 eine `1` gemeldet, wo eine `0` stehen sollte.** Getroffen hat
> sie `pg_database_owner` — eine **eingebaute** Rolle (gemessen: `oid < 16384`),
> die es seit PostgreSQL 14 in jedem Cluster gibt. Gefragt wird deshalb nach dem
> Namen, den dieses Panel vergibt, und nicht nach einer Endung.
>
> **Und darunter stand die Erwartung `root` für den Eigentümer des Schemas. Auch
> die war falsch:** Seit PostgreSQL 15 gehört `public` der Rolle
> `pg_database_owner` — gemessen an einer frisch angelegten Datenbank auf 16.13.
> Ein Ablauf, der `root` verlangt, meldet auf jeder Zielplattform ausser
> Ubuntu 22.04 einen Fehlschlag, den es nicht gibt.
>
> *Ein Wächter, der nach einer Endung fragt, findet, was so endet.* Beide Zeilen
> haben eine Abweichung erzeugt, die keine war — und zwar in dem Dokument, das
> Abweichungen erkennen soll.

Wer den Eigentümer des Schemas trotzdem sehen will, prüft die **Eigenschaft** und
nicht den Namen:

```bash
sudo -u postgres psql -d <db> -tAc \
  "SELECT nspowner::regrole = '<präfix>_owner'::regrole FROM pg_namespace WHERE nspname='public'"
```

**Erwartet:** `f` vorher, `t` nach Punkt 7. Was davor dasteht — `pg_database_owner`
ab PG 15, `postgres` oder `root` darunter —, ist für diese Frage gleichgültig.

Und die Prüfsumme, weil `38 §19` Punkt 0 sie später vergleicht:

```bash
sudo md5sum /etc/postgresql/16/main/pg_hba.conf
```

---

## 5. Punkt 2 — PostgreSQL anbieten

Auf der Seite **Einstellungen → Datenbankserver** PostgreSQL einschalten.

**Erwartet:** Die Marke wechselt auf „wird angeboten". Auf der Übersicht steht
der Dienst als aktiv — und zwar über die **Instanzunit**
`postgresql@16-main.service` und nicht über die Sammelunit `postgresql.service`.

**Die Gegenprobe gehört dazu und ist der eigentliche Punkt.** Die Sammelunit
bleibt aktiv, wenn der Cluster steht; wer sie fragt, bekommt „läuft" für einen
Server, der nicht läuft. Genau das ist am 9. August 2026 gefunden worden.

```bash
sudo pg_ctlcluster 16 main stop
```

Übersicht neu laden → der Dienst muss **inaktiv** sein. Danach wieder an:

```bash
sudo pg_ctlcluster 16 main start
```

---

## 6. Punkt 3 — Eine Datenbank anlegen, mit Systemwahl

Abonnement → Datenbanken → Anlegen.

Worauf zu achten ist:

- Das Feld **System** ist da (weil beide Systeme verfügbar sind) und steht
  **über** dem Namen.
- Beim Umschalten auf PostgreSQL ändert sich der Name im Hinweis darunter: von
  `p1xxx_shop` auf einen Namen mit siebzehn Zeichen nichtssagendem Präfix
  (`38 §4`). **Das ist die Stelle, die in diesem Container nicht an der echten
  Seite prüfbar war.**
- Bei PostgreSQL verschwindet das Feld **Sortierung**, und der Hinweis sagt
  statt dessen, dass Zeichensatz und Sortierung von der Vorlage kommen.

Bitte eine mit einem **langen** Namen anlegen — `kundendatenbank`, sechzehn
Zeichen. Der volle Name hat dann vierunddreissig, und das ist das Längste, was
dieses Panel vergibt.

```bash
sudo -u postgres psql -c "\l"
```

**Erwartet:** die Datenbank, Eigentümer `root`, und in `datacl` **kein**
`PUBLIC` — das ist die Absperrung aus `38 §10`.

---

## 7. Punkt 4 — Die Liste und die Detailseite

Datenbanken-Übersicht:

- Die Spalte **System** ist da (weil es jetzt eine PostgreSQL-Datenbank gibt).
- Der lange Name schiebt die Seite nicht.

**Hier bitte Screenshots**, am Telefon oder bei 390 px Fensterbreite, hell und
dunkel. Das ist der Teil, der beim Bauen gefehlt hat: Gemessen ist bisher nur
der einzelne Baustein gegen das gebaute Stylesheet, nicht die Seite im
Zusammenspiel. **P4 Schritt 6 ist genau so ausgeliefert worden, und die
nachgeholte Runde fand drei Fehler auf einer vollständig grün getesteten
Seite.**

Detailseite der Datenbank:

- Zeile **System** mit Marke.
- **Keine** Zeile „Sortierung" — für PostgreSQL gibt es dort nichts zu sagen,
  und eine fehlende Angabe ist ehrlicher als eine falsche.
- Der Hinweis über `127.0.0.1` und die Erweiterungen.

---

## 8. Punkt 5 — Zugang und Kontingent

Zugang anlegen. **Das Passwort erscheint genau einmal.**

```bash
sudo -u postgres psql -tAc \
  "SELECT rolname, rolcanlogin, rolsuper, rolcreatedb FROM pg_roles WHERE rolname LIKE '<präfix>%'"
```

**Erwartet:** eine Zeile, `rolcanlogin = t`, und **`rolsuper` und `rolcreatedb`
beide `f`** — die Rolle kann nichts, was sie nicht soll.

Und die Probe, dass der Zugang trägt und die Sortierung dort ankommt, wo der
Kunde sie sieht:

```bash
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> \
  -c "SELECT current_user, datcollate FROM pg_database WHERE datname = current_database()"
```

> **`current_setting('lc_collate')` steht hier nicht, und zwar nicht aus
> Geschmack.** PostgreSQL 15 hat `lc_collate` und `lc_ctype` als
> Laufzeitparameter **entfernt**; sie sind seitdem nur noch Eigenschaften einer
> Datenbank. Der Aufruf antwortet mit
> `ERROR: unrecognized configuration parameter` — am 10. August 2026 genau so
> gemessen, weil dieser Ablauf ihn zuerst nannte. Gefragt wird der Katalog.
>
> Dass die Zeile überhaupt kommt, ist dabei schon die halbe Antwort: Ein
> Serverfehler heisst, dass die Anmeldung **stand**.

Dann das Kontingent ansehen — **auf der Seite „Datenbank anlegen" und nicht auf
der Abonnement-Seite.** Dort oben steht *„Datenbanken: 2 von unbegrenzt.
Datenbankbenutzer zählen nicht getrennt."*: **Die Zahl zählt beide Systeme
zusammen**, und der Satz nennt keines der beiden namentlich.

> **Die Abonnement-Seite zeigt keinen Verbrauch, und das ist Absicht.** Ihr
> Abschnitt „Kontingente" listet, was der Plan erlaubt — er ist eine Auskunft
> über den *Vertrag*, nicht über den *Bestand*. Der Verbrauch steht dort, wo er
> eine Entscheidung beeinflusst: vor dem Anlegen.
>
> Diese Anleitung schickte am 10. August 2026 auf die falsche Seite, und der
> Betreiber hat gemeldet, dass dort nur „unbegrenzt" steht. Er hatte recht.

---

## 9. Punkt 6 — Die Sperre erreicht die Rolle

**Der Knopf „Sperren" oben rechts auf der Abonnement-Seite** — und nicht
„Zugriff entziehen" auf der Detailseite einer Datenbank.

> **Die beiden sehen von aussen ähnlich aus und tun Verschiedenes.** „Zugriff
> entziehen" nimmt einer Rolle das CONNECT auf *eine* Datenbank
> (`pg.role.grant`), unmittelbar und ohne Vorgang. „Sperren" nimmt allen Rollen
> des Abonnements die Anmeldung (`pg.role.lock` → `ALTER ROLE … NOLOGIN`) und
> läuft über die Warteschlange.
>
> Am 10. August 2026 hat diese Anleitung nur „Abonnement sperren" gesagt, der
> Betreiber hat den anderen Knopf gedrückt, und das Ergebnis sah eine halbe
> Stunde lang nach einem schweren Fehler aus: CONNECT weg, `rolcanlogin`
> unverändert, kein Vorgang in der Liste. Es war alles richtig — nur nicht das,
> was hier gemeint war.
>
> **Aufgeklärt hat es das Protokoll und nicht die Vorgangsliste.** Vorgänge
> zeigen, was in der Warteschlange lief; das Protokoll zeigt, was *jemand getan
> hat*. Wenn eine Messung nicht zum Code passt, ist die zweite Frage die
> richtige.

```bash
sudo -u postgres psql -tAc \
  "SELECT rolname, rolcanlogin FROM pg_roles WHERE rolname LIKE '<präfix>%'"
```

**Erwartet:** `rolcanlogin` ist `f` für die Kundenrolle, und im Panel steht der
Zugang als gesperrt.

**Und dasselbe für MariaDB**, denn die Sperre läuft über zwei Lebensläufe — einen
je System —, die beide auf dasselbe Ereignis hören. Dass keiner die Zugänge des
anderen anfasst, sichert `EngineScopeTest`; auf einem Server mit beidem ist es
hier zum ersten Mal wirklich gefahren.

```bash
sudo mariadb -e "SELECT User, Host, JSON_VALUE(Priv, '\$.account_locked') AS gesperrt \
                 FROM mysql.global_priv WHERE User LIKE '<systembenutzer>%'"
```

> **`mysql.user` hat keine Spalte `account_locked`.** Seit MariaDB 10.4 ist
> `mysql.user` nur noch eine Sicht auf `mysql.global_priv`, und die Sperre steht
> dort im JSON-Feld `Priv`. Ein `SELECT account_locked FROM mysql.user` endet mit
> `ERROR 1054 Unknown column` — am 10. August 2026 genau so gemessen, weil diese
> Anleitung es zuerst falsch nannte.

**Und die Probe, die zählt** — mit dem Passwort aus Punkt 5:

```bash
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> -c "SELECT 1"
```

**Erwartet:** abgewiesen. Danach Abonnement freigeben und **denselben Befehl
noch einmal** — jetzt muss er durchkommen. Ohne die zweite Hälfte belegt der
Lauf nur, dass irgendetwas nicht ging.

---

## 10. Punkt 7 — Sichern und Zurückspielen

Der Kern dieses Laufs. Hier passiert am meisten zum ersten Mal auf einem echten
System.

> **Dieser Abschnitt ist am 10. August 2026 neu geschrieben worden, und der
> Grund ist ein Erfolg gewesen.** Der erste Anlauf hat drei Fehler gefunden —
> das Zurückspielen kam in eine Datenbank mit Tabellen gar nicht hinein, was
> hineinkam gehörte `root`, und ein zweiter Zugang sah die Tabellen des ersten
> nicht. Alle drei sind dieselbe Frage: *Wem gehört, was in dieser Datenbank
> steht?* Die Antwort ist seit `v0.5.1-rc.5` eine **Eigentümerrolle je
> Abonnement** (`<präfix>_owner`, `docs/38 §21` Entscheidung 11).
>
> Damit ändert sich, was hier erwartet wird: Wo „Eigentümer: `root`" stand,
> steht jetzt `<präfix>_owner`. **Ein Ablauf, dessen Erwartung nicht mit dem
> Entwurf mitzieht, prüft die vorige Fassung.**

Erst Inhalt anlegen — **als Kunde und nicht als `postgres`.** Wer die Tabelle
als Superuser anlegt, misst hinterher einen Fall, den es auf keinem echten
Server gibt: Dort legt der Kunde seine Tabellen selbst an.

```bash
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> \
  -c "CREATE TABLE kunden (id int primary key, name text)"
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> \
  -c "INSERT INTO kunden VALUES (1,'a'),(2,'b')"
sudo -u postgres psql -d <db> -tAc "SELECT tableowner FROM pg_tables WHERE tablename='kunden'"
```

**Erwartet:** Das Anlegen geht durch — und beim Eigentümer gibt es **zwei
richtige Antworten**, je nachdem, wann die Datenbank entstanden ist:

| Datenbank angelegt | Eigentümer von `kunden` |
|---|---|
| mit `v0.5.1-rc.5` oder später | `<präfix>_owner` |
| davor | die **Kundenrolle** selbst |

Notier, welche der beiden dasteht. Im zweiten Fall misst dieser Punkt zusätzlich
die **Nachrüstung**: Das Zurückspielen zieht die Eigentümerrolle nach, und nach
7d steht dort die erste Antwort.

### 7a — Sicherung erstellen (im Panel)

```bash
sudo ls -la /var/lib/srvpanel/dumps/<abo>/
```

**Erwartet:** Datei `root:srvpanel` mit `0640`, Verzeichnis `root:srvpanel` mit
`0710`.

### 7b — Herunterladen im Panel

Das ist die Stelle, an der P5 am 8. August 2026 einen 404 hatte, weil der
Verzeichnismodus falsch war. Die Datei bitte aufheben — Punkt 8 braucht sie.

### 7c — Die Voraussetzungen sind jetzt entstanden

```bash
sudo grep -B1 -A1 srvpanel /etc/postgresql/16/main/pg_hba.conf
sudo -u postgres psql -tAc "SELECT rolname FROM pg_roles WHERE rolname='srvpanel_restore'"
```

**Erwartet:** die Marke und die Regel **ganz oben** in der Datei — in
`pg_hba.conf` gewinnt die erste passende Zeile, und Debians Vorgabe beginnt mit
`local all all peer` —, dazu die Gruppenrolle. **Beide gab es vor Punkt 7
nicht** (Punkt 1), und genau das macht diese Messung zu einem Beleg statt zu
einer Beobachtung.

### 7d — Zurückspielen

Vorher zweierlei kaputtmachen, damit der Erfolg sichtbar wird — **eine Zeile
löschen und eine Tabelle dazulegen:**

```bash
sudo -u postgres psql -d <db> -c "DELETE FROM kunden WHERE id=2"
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> \
  -c "CREATE TABLE spaeter (id int)"
```

> **Die zweite Zeile ist die neue, und sie prüft den Fehler, an dem der erste
> Anlauf hängengeblieben ist.** `pg_dump --format=plain` schreibt kein `DROP`,
> `mysqldump` bringt sein `DROP TABLE IF EXISTS` von selbst mit — P5b hat diese
> stillschweigende Vorgabe geerbt. Ein Zurückspielen in eine Datenbank, in der
> noch Tabellen stehen, endete deshalb mit
> `ERROR: relation "kunden" already exists`. Seit rc.5 leert das Panel das
> Schema **privilegiert**, bevor es einspielt.

Dann im Panel zurückspielen, danach:

```bash
sudo -u postgres psql -d <db> -tAc "SELECT count(*) FROM kunden"
sudo -u postgres psql -d <db> -tAc \
  "SELECT tablename||' -> '||tableowner FROM pg_tables WHERE schemaname='public' ORDER BY 1"
```

**Erwartet:** `2` — die Tabelle `spaeter` ist **fort** (sie stand nicht in der
Sicherung), und der Eigentümer von `kunden` ist **`<präfix>_owner`**. Weder
`root` noch die befristete Rolle.

**Und dann die drei Fragen, auf die es dem Kunden ankommt:**

```bash
# 1. Kommt er an seine Daten?
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> \
  -c "SELECT count(*) FROM kunden"

# 2. Darf er sie auch ändern?
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> \
  -c "INSERT INTO kunden VALUES (3,'c')"

# 3. Und als wer arbeitet er dabei?
PGPASSWORD='<passwort>' psql -h 127.0.0.1 -U <rolle> -d <db> \
  -tAc "SELECT current_user, session_user"
```

**Erwartet:** `2`, dann `INSERT 0 1`, dann `<präfix>_owner|<rolle>`.

> **Die dritte Zeile ist die Erklärung für die ersten beiden.** Jede Sitzung des
> Kunden läuft als die Eigentümerrolle (`ALTER ROLE … IN DATABASE … SET role`),
> und was er anlegt, gehört ihr. `session_user` bleibt er selbst — **wer
> verbunden war, steht weiter im Protokoll von PostgreSQL.** Stünde in beiden
> Feldern dasselbe, wäre die Sitzungsrolle nicht gesetzt und die Nachrüstung
> nicht gelaufen.
>
> **Wer nur die Zeilen zählt, sieht davon nichts:** `sudo -u postgres` ist
> Superuser und darf immer. Genau deshalb hat der erste Anlauf einen grünen
> Vorgang und einen ausgesperrten Kunden nebeneinander stehengehabt.

### 7d-2 — Und ein zweites Mal, ohne etwas kaputtzumachen

```bash
# im Panel dieselbe Sicherung noch einmal zurückspielen, danach:
sudo -u postgres psql -d <db> -tAc "SELECT count(*) FROM kunden"
```

**Erwartet:** `2` — die Zeile aus Frage 2 ist wieder fort, und der Vorgang ist
grün. **Das ist die eigentliche Wiederholbarkeit:** Der erste Lauf lief in eine
leere Datenbank, dieser in eine volle. Scheitert er, ist das Leeren nicht
gelaufen, und der erste Erfolg war einer aus Zufall.

### 7e — Der zweite Zugang sieht dasselbe

Nur, wenn es einen zweiten gibt (Punkt 5). Sonst überspringen.

```bash
PGPASSWORD='<passwort2>' psql -h 127.0.0.1 -U <rolle2> -d <db> \
  -c "SELECT count(*) FROM kunden"
```

**Erwartet:** dieselbe Zahl. **Das war der dritte Fehler des ersten Anlaufs** —
`permission denied for table`, weil in PostgreSQL eine Tabelle dem gehört, der
sie angelegt hat, und der zweite Zugang ein anderer ist. Über die gemeinsame
Eigentümerrolle ist er es nicht mehr.

### 7f — Keine Reste

```bash
sudo -u postgres psql -tAc "SELECT count(*) FROM pg_roles WHERE rolname LIKE '%\_r%'"
sudo ls -la /run/srvpanel/ 2>/dev/null
```

**Erwartet:** `0`, und keine `pgpass-*`-Datei.

> **Und die befristete Rolle besitzt nichts mehr, bevor sie geht.** Im ersten
> Entwurf übertrug ein `REASSIGN OWNED BY … TO` ihr Eigentum an den Eigentümer
> der *Datenbank* — an `root`, und genau daran hing Fehler 2. Seit sie **als**
> die Eigentümerrolle arbeitet, gehört ihr am Ende ohnehin nichts: gemessen
> 0 Objekte, `DROP ROLE` geht ohne Übertragung. Ein Entwurf, der eine Fallgrube
> überflüssig macht, ist besser als einer, der sie umgeht.

---

## 11. Punkt 8 — Eine mitgebrachte Sicherung

Die heruntergeladene Datei aus 7b im Panel wieder hochladen („Sicherung
hochladen").

**Erwartet:** wird übernommen, und die Art steht als „mitgebracht".

**Und der Fassungsversatz**, wenn er gesehen werden soll: die Datei auspacken,
die Kopfzeile auf `-- Dumped from database version 17.0` ändern, wieder packen.

```bash
zcat <datei> > /tmp/probe.sql
sed -i 's/^-- Dumped from database version .*/-- Dumped from database version 17.0/' /tmp/probe.sql
gzip -c /tmp/probe.sql > /tmp/probe.sql.gz && rm /tmp/probe.sql
```

**Erwartet:** Das Hochladen wird mit einer Meldung über die Hauptfassung
abgewiesen — **vor** dem Lauf und nicht durch ihn.

---

## 12. Punkt 9 — Rückbau

Erst die Datenbank entfernen, dann das ganze Abonnement.

```bash
sudo -u postgres psql -c "\l"
sudo -u postgres psql -tAc "SELECT count(*) FROM pg_roles WHERE rolname LIKE '<präfix>%'"
sudo ls /var/lib/srvpanel/dumps/
srvpanel db
```

**Erwartet:** keine Datenbank, keine Rolle, kein Verzeichnis — und `srvpanel db`
meldet **keine** verwaisten Sicherungen.

> **Die Rollenzahl schliesst die Eigentümerrolle ein**, und das ist seit rc.5
> die Zeile, die am ehesten `1` statt `0` liefert. Sie entsteht beim Anlegen der
> ersten Datenbank und geht mit der **letzten** — der Weg zurück, den `docs/35`
> erzwingt: *Etwas, das sich anlegen, aber nirgends löschen lässt, bleibt Jahre
> stehen und fällt erst einer Datenmigration auf.* Bleibt sie stehen, ist der
> Rückbau unvollständig, auch wenn er grün gemeldet hat.

> **Die letzte Zeile prüft etwas, das keine Abfrage an PostgreSQL prüfen kann:**
> den Bestand des Panels gegen die Platte (`docs/36 §22.3r`). Und sie prüft
> genau die Stelle, an der in Schritt 6 eine `engine`-Einschränkung **entfernt**
> wurde: Solange `db.dump.remove` nur MariaDB bediente, musste sie dastehen;
> seit sie beide bedient, wäre dieselbe Zeile der Fehler. Ist die Entscheidung
> falsch gewesen, meldet dieser Befehl es hier.

---

## 13. Was zurückkommen soll

Die Ausgaben **aller** Punkte, die Screenshots aus Punkt 4 — und vor allem: wo
die Oberfläche anders aussieht, als sie soll.

Bei P4 und P5 kamen die teuersten Fehler nicht aus den Befehlen, sondern aus dem
Blick auf die Seite. Sechs Fehler des letzten Abnahmelaufs hat kein Test
gefunden, drei davon auf einer Seite, die vollständig grün war.

---

## 14. Was dieser Lauf ausdrücklich nicht prüft

- **Das Abnahmekriterium.** Das ist `38 §19`, mit zwei Abonnements, der
  `template0`-Falle und dem Dump, der nichts erzwingen darf. Es kommt nach
  Schritt 8.
- **Den Fernzugriff.** Der ist Schritt 10 und noch nicht gebaut. Solange er
  fehlt, horcht kein Dienst auf einer erreichbaren Adresse — und das ist der
  Grund, warum er ans Ende gehört (`38 §17`).
