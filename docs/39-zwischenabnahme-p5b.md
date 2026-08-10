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

Dann auf der Abonnement-Seite das Kontingent ansehen: **Die Zahl zählt beide
Systeme zusammen**, und der Hinweistext darunter nennt keines der beiden
namentlich.

---

## 9. Punkt 6 — Die Sperre erreicht die Rolle

Abonnement sperren.

```bash
sudo -u postgres psql -tAc \
  "SELECT rolname, rolcanlogin FROM pg_roles WHERE rolname LIKE '<präfix>%'"
```

**Erwartet:** `rolcanlogin` ist `f` für die Kundenrolle, und im Panel steht der
Zugang als gesperrt.

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

Erst Inhalt anlegen:

```bash
sudo -u postgres psql -d <db> -c "CREATE TABLE kunden (id int primary key, name text)"
sudo -u postgres psql -d <db> -c "INSERT INTO kunden VALUES (1,'a'),(2,'b')"
```

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

Vorher etwas kaputtmachen, damit der Erfolg sichtbar ist:

```bash
sudo -u postgres psql -d <db> -c "DELETE FROM kunden WHERE id=2"
```

Dann im Panel zurückspielen, danach:

```bash
sudo -u postgres psql -d <db> -tAc "SELECT count(*) FROM kunden"
sudo -u postgres psql -d <db> -tAc "SELECT tableowner FROM pg_tables WHERE tablename='kunden'"
```

**Erwartet:** `2` — und der Eigentümer ist **`root`**, nicht die befristete
Rolle.

> **Die zweite Zeile ist die wichtigere.** Was eine Rolle anlegt, gehört ihr;
> das `DROP OWNED BY` beim Aufräumen nahm deshalb im ersten Anlauf genau die
> Daten mit, die gerade eingespielt worden waren. Der Vorgang meldete Erfolg,
> und die Tabelle war fort. Jetzt überträgt `REASSIGN OWNED BY … TO` zuerst an
> den Eigentümer der Datenbank — gefragt statt angenommen. Wer nur die Zeilen
> zählt, sieht davon nichts.

### 7e — Keine Reste

```bash
sudo -u postgres psql -tAc "SELECT count(*) FROM pg_roles WHERE rolname LIKE '%\_r%'"
sudo ls -la /run/srvpanel/ 2>/dev/null
```

**Erwartet:** `0`, und keine `pgpass-*`-Datei.

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
