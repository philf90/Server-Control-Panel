# 43 — Die Zwischenabnahme des Fernzugriffs

**Was hier steht:** ein Testlauf auf `cloudsrv24` für den Fernzugriff auf
PostgreSQL — Schritt 10 aus `docs/38 §17` und alles, was seitdem dazukam. Etwa
eine Stunde. **Was hier nicht steht:** die Abnahme von P5b. Die ist
`38-postgresql.md §19`, sie ist am 11. August 2026 gefahren, und ihr Protokoll
steht in `docs/42`.

---

## 1. Warum dieser Lauf eigenständig ist

**Ohne den Fernzugriff ist P5b abnehmbar, mit ihm horcht ein Dienst auf einer
erreichbaren Adresse.** Das ist der Grund, warum Schritt 10 am Ende steht und
nicht im selben Beitrag wie das Anlegen der ersten Datenbank (`docs/36 §19`,
Entscheidung 5) — und derselbe Grund macht ihn zu einem eigenen Lauf.

**Der teuerste Fehler dieser Fläche legt zwischen Ursache und Wirkung eine
Wartungsfrist.** Eine falsche Zeile in `pg_hba.conf` ist bei einem Reload
folgenlos und beim nächsten Neustart tödlich: `FATAL: could not load
pg_hba.conf`, der Cluster kommt nicht hoch. Gemessen ist das an einem
Wegwerf-Cluster im Entwicklungscontainer (`docs/38 §14.5`, M52). Was dieser Lauf
beantwortet, ist deshalb nicht „ist das Kriterium erfüllt", sondern: **greift
der Rückweg auf einem Server, auf dem systemd, der Agent und das Panel
dazwischenstehen?**

Punkt 6 ist der Kern des Laufs. Wer ihn auslässt, hat den Fernzugriff nicht
geprüft, sondern nur benutzt.

---

## 2. Was man braucht

- **`cloudsrv24` mit einer Fassung, die alle drei Teile hat** — siehe §3. Das
  ist die eine Voraussetzung, die man vorher nachsehen muss.
- **Ein Abonnement zum Wegwerfen**, und zwar eines mit einer
  PostgreSQL-Datenbank und einem Zugang. Der Lauf legt keines an: Eine
  Kundennummer ist auf Dauer verbraucht und ein Systembenutzer erst recht
  (`docs/35`).
- **Ein zweiter Rechner mit fester Adresse** für Punkt 5. Ohne ihn lässt sich
  „die Regel wirkt" nicht messen, sondern nur „die Datei sieht richtig aus".
- Etwa eine Stunde.
- Die Bereitschaft, **jede Ausgabe zu schicken — auch die, die richtig
  aussieht.** Der teuerste Fehler von P4 hat `1 fällig, 1 bestellt` gemeldet,
  also genau die Zahl, die das Kriterium verlangte, und das Falsche getan.

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt
> wurde.**

**Zwei Werte stehen unten nicht ausgeschrieben, weil sie es nicht können.** Das
Präfix eines Abonnements wird gewürfelt (`docs/38 §4`), und das Passwort einer
Rolle steht genau einmal auf dem Bildschirm. Beide sind dem Betreiber bekannt;
Punkt 0 holt das Präfix zurück, falls nicht. **Alles andere ist wörtlich
einzugeben** — auch die Fassung `16` im Pfad, falls der Cluster eine andere
trägt.

---

## 3. Welche Fassung — und warum das hier steht

Der Fernzugriff ist in drei Beiträgen entstanden, und **jeder davon macht einen
Punkt dieses Laufs erst möglich:**

| Fassung | was dazukam | welche Punkte |
|---|---|---|
| `v0.5.2-rc.1` | Schritt 10: `pg.remote.access`, die Netze, `srvpanel db --remote` für beide Systeme | 0–3, 5–9 |
| `v0.5.2-rc.2` | die Nachziehung: eine Rechteänderung schreibt den Block mit (`docs/38 §14.6`) | **4b, 4c** |
| `v0.5.2-rc.3` | die Betreiberseite: PostgreSQLs Horchadresse in den Einstellungen (`docs/38 §14.7`) | **2b, 4d, 8b, 9b** |
| `v0.5.2-rc.4` | `--bind=*`, die eigene Gegenprobe des Panels, der Rückweg ohne Bestand (`docs/44`) | **3** — und ohne sie alle folgenden |

**Gegen `rc.3` und älter darf Punkt 3 nicht mit `--bind=::` gefahren werden**,
und das ist keine Empfehlung: Auf `cloudsrv24` hat genau dieser Wert am
11. August 2026 das Panel abgeschaltet, weil MariaDB `::` ausschliesslich IPv6
bindet. `--bind=0.0.0.0` geht auf jeder Fassung; `--bind=*` erst ab `rc.4`.
Die Einzelheiten stehen in `docs/44`.

**Gegen `rc.1` schlägt Punkt 4b fehl**, und zwar auf eine Art, die wie ein
kaputter Fernzugriff aussieht und keiner ist: Die Zeile für die zweite Datenbank
fehlt, im Panel steht „erreichbar von …", und die Anwendung kommt nicht herein.
Wer den Lauf gegen `rc.1` fährt, misst diesen Fehler und nicht den Fernzugriff.

**Gefahren wird gegen `v0.5.2-rc.4`** — die erste Fassung, die alle Teile trägt
*und* den Lauf nicht in einen Ausfall führt. Gegen eine ältere sind die Punkte der fehlenden Zeile zu überspringen,
und zwar **mit einer Zeile im Protokoll und nicht stillschweigend**: Ein Lauf,
der Punkte auslässt, ohne es zu sagen, sieht hinterher aus wie ein
vollständiger.

```bash
dpkg-query -W -f='${Version}
' srvpanel
readlink /opt/srvpanel/current
```

---

## 4. Der Lauf

```
# 0  DIE NAMEN, MIT DENEN GEARBEITET WIRD
sudo -u postgres psql -Atc "SELECT datname FROM pg_database WHERE datname LIKE 'x%' ORDER BY 1"
sudo -u postgres psql -Atc "SELECT rolname FROM pg_roles WHERE rolname LIKE 'x%' AND rolcanlogin ORDER BY 1"
#    erwartet: die Datenbank und die Rolle aus §19 Punkt 1.
#    BELEG: beide Namen ins Protokoll — sie stehen unten als <DB> und <ROLLE>,
#           und sie sind die EINZIGEN zwei Stellen, an denen etwas einzusetzen ist.

# 1  DER AUSGANGSZUSTAND, GEMESSEN UND NICHT ANGENOMMEN
md5sum /etc/postgresql/16/main/postgresql.conf /etc/postgresql/16/main/pg_hba.conf
ls -l /etc/postgresql/16/main/conf.d/
sudo -u postgres psql -Atc "SHOW listen_addresses"
#    erwartet: listen_addresses = localhost, conf.d ist leer.
#    BELEG: beide md5-Summen ins Protokoll — Punkt 6 vergleicht gegen sie.

# 2  DAS FELD IST NICHT DA, UND ES STEHT DA, WARUM
#    Im Panel: Datenbanken → die PostgreSQL-Datenbank → Zugänge.
#    erwartet: KEIN Knopf „Netz eintragen", und darunter der Satz, dass der
#              Server nur lokal erreichbar ist und wer das einschalten kann.
#    Ein Feld, das ohne Erklärung fehlt, sieht aus wie ein Fehler
#    (AbilityReachTest).
#    BELEG: Screenshot.

# 2b DIE BETREIBERSEITE, VOR DEM EINSCHALTEN
#    Im Panel: Einstellungen → Datenbankserver, Abschnitt „PostgreSQL".
#    erwartet: „Horcht auf" nennt localhost (oder was in postgresql.conf steht),
#              „Fernzugriff" trägt die Marke „aus", „Erlaubte Netze" ist 0.
#    BELEG: Screenshot.
#    Bis zum 11. August 2026 stand hier über PostgreSQL keine Horchadresse —
#    die Seite fragte nur db.server.info und zeigte damit die von MariaDB
#    (docs/38 §14.7). Beide Antworten haben dieselbe Form; auffallen wäre es
#    nur auf einem Server, der genau eines von beiden erreichbar hat.

# 3  FREISCHALTEN
srvpanel db --remote=on --bind='*'
#    Die Anführungszeichen gehören dazu. Unquotiert ist * ein Suchmuster der
#    Shell: In bash bleibt es stehen, weil nichts passt, in zsh bricht der
#    Befehl mit „no matches found" ab, bevor er startet.
#    Die Rückfrage mit „yes" beantworten — beide Datenbankserver werden neu
#    gestartet, MariaDB zuerst.
#    erwartet in der Ausgabe, WÖRTLICH:
#      Horcht auf * — Fernzugriff möglich.
#      PostgreSQL horcht auf * — Fernzugriff möglich, 0 Zugangsregel(n) in
#      /etc/postgresql/16/main/pg_hba.conf.
#    „0" ist hier richtig: Es hat noch niemand ein Netz eingetragen.
#
#    HIER STAND --bind=:: UND DAS HAT AM 11. AUGUST 2026 DAS PANEL ABGESCHALTET.
#    MariaDB bindet :: ausschliesslich IPv6 — `ss -tlnp` zeigt nur [::]:3306 —,
#    und das Panel verbindet sich über 127.0.0.1: Connection refused, 500er auf
#    jeder Seite. Der Doppelstapel liegt auf *. Das ganze Protokoll samt der drei
#    weiteren Fehler, die daran beteiligt waren, steht in `docs/44`.
#
#    Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
#    nicht — er führt sie aus.

ss -tlnp | grep :3306
#    erwartet ZWEI Zeilen: 0.0.0.0:3306 und [::]:3306, beide mariadbd, mit
#    VERSCHIEDENEN Dateikennungen (fd=23 und fd=25 auf cloudsrv24).
#    DAS IST DIE SCHNELLSTE DIAGNOSE FÜR DEN AUSFALL AUS `docs/44`: Bei
#    bind-address = :: steht dort GENAU EINE Zeile, [::]:3306, und das Panel
#    kommt über 127.0.0.1 nicht mehr herein. Bei * legt MariaDB zwei getrennte
#    Sockets an, einen je Familie.
#
#    > Ein Eintrag heisst IPv6-only. Zwei Einträge heissen beides.
#
#    In `docs/44` stand zuerst, ss könne die beiden Fälle nicht unterscheiden —
#    der Unterschied liege unsichtbar in IPV6_V6ONLY. Gemessen am 11. August
#    2026 auf cloudsrv24 stimmt das nicht; er steht direkt da.

timeout 3 bash -c 'cat < /dev/null > /dev/tcp/127.0.0.1/3306' && echo "IPv4 offen" || echo "IPv4 ZU"
#    erwartet: IPv4 offen.

curl -sS -o /dev/null -w '%{http_code}\n' https://<PANEL>/login
#    erwartet: 200.
#    <PANEL> ist die Adresse, unter der das Panel bedient wird, MIT PORT, falls
#    es nicht 443 ist — auf cloudsrv24 ist es cloudsrv24.de:8443. NICHT
#    $(hostname -f): Der volle Rechnername muss nicht der Name im Zertifikat
#    sein, und dann scheitert dieser Aufruf an der Prüfung statt an der Sache.
#    Genau so passiert, am 11. August 2026.
#    BELEG: DIESE ZAHL. Der Datenbankserver zu fragen, ob er horcht, genügt
#    nicht — genau das hat der Agent am 11. August getan und „Fernzugriff
#    möglich" gemeldet, während das Panel schon unten war. Seine Gegenprobe
#    läuft über den Unix-Socket, also über eine Strecke, die nicht kaputtgeht,
#    wenn TCP kaputtgeht.
#
#    Das Kommando prüft das seit `docs/44` selbst und nimmt bei einem Fehlschlag
#    zurück. Diese Zeile ist die Gegenprobe dazu: Ein Rückweg, den niemand von
#    aussen nachmisst, ist wieder nur eine Behauptung.

cat /etc/postgresql/16/main/conf.d/60-srvpanel.conf
md5sum /etc/postgresql/16/main/postgresql.conf
#    erwartet: die Datei existiert und enthält listen_addresses = '*';
#              die md5-Summe von postgresql.conf ist DIE VON PUNKT 1.
#    Keine Distributionsdatei wird angefasst — Leitbild 1.

sudo -u postgres psql -Atc "SHOW listen_addresses"
#    erwartet: *
#    BELEG: DIESE ZEILE und nicht die Datei darüber. Ob der Include-Punkt
#           greift, lässt sich nicht erfragen (M51) — nur hier ablesen. Eine
#           geschriebene Zeile ist eine Absicht (docs/37 §6, Lehre 1).

# 4  EIN NETZ EINTRAGEN   ← und hier wird gezählt, WAS gezählt wurde
#    Im Panel, in der Zeile des Zugangs: „Netz eintragen" → 203.0.113.5/32.
#    erwartet: die Zeile steht danach unter „Herkunft".

sudo -u postgres psql -Atc \
  "SELECT line_number, database, user_name, address, netmask, auth_method
     FROM pg_hba_file_rules WHERE '<ROLLE>' = ANY(user_name)"
#    erwartet GENAU EINE Zeile, und in ihrer Spalte `database` steht <DB> —
#    NICHT `all`. Das ist die zweite Wand hinter dem REVOKE CONNECT aus §10.
#
#    > Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt
#    > wurde.
#
#    BELEG: die AUSGEGEBENE ZEILE ins Protokoll, nicht ihre Anzahl.

sudo -u postgres psql -Atc "SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL"
#    erwartet: 0

# 4b DIE ZWEITE DATENBANK   ← der Weg, den ein echter Kunde als Erstes geht
#    OHNE DIESEN PUNKT IST DER HÄUFIGSTE FALL NICHT GEFAHREN. Punkt 4 legt eine
#    Datenbank an; die Zeile nennt die Datenbank und nicht `all` (M23), also
#    braucht JEDE weitere Datenbank derselben Rolle eine eigene Zeile.
#
#    In Abo A eine zweite PostgreSQL-Datenbank anlegen. Dann auf ihrer Seite:
#    „Vorhandenen Zugang verbinden" → <ROLLE>.
sudo -u postgres psql -Atc \
  "SELECT database, address FROM pg_hba_file_rules WHERE '<ROLLE>' = ANY(user_name) ORDER BY 1"
#    erwartet: ZWEI Zeilen — je eine Datenbank, dasselbe Netz.
#    BELEG: beide ausgegebenen Zeilen ins Protokoll, nicht ihre Anzahl.
#
#    Und die Gegenrichtung, im selben Punkt:
#    Auf derselben Seite „Zugriff entziehen".
sudo -u postgres psql -Atc \
  "SELECT count(*) FROM pg_hba_file_rules WHERE '<ROLLE>' = ANY(user_name)"
#    erwartet: 1 — die Zeile der zweiten Datenbank ist mitgegangen.
#
#    DER ANLASS, damit niemand ihn für Zierde hält: Bis zum 11. August 2026
#    schrieb den Block nur, wer ein NETZ anfasste. Eine Rechteänderung liess ihn
#    stehen, wie er war — im Panel stand „erreichbar von 203.0.113.5", und die
#    Anwendung kam nicht herein. Das ist das Gegenteil eines Sicherheitslochs
#    und trotzdem der teuerste Fehler dieser Fläche, weil er wie ein kaputter
#    Fernzugriff aussieht. Gefunden hat ihn nicht der Betrieb, sondern die
#    Frage, ob sich Schritt 10 überhaupt abnehmen lässt — der Lauf kam an
#    diesem Weg vorbei, weil er nur EINE Datenbank kannte.
#
#    > Ein Abnahmelauf, der den häufigsten Weg nicht geht, misst die Fläche und
#    > nicht den Betrieb.
#
#    Der Wächter dazu ist `PgHbaFollowTest`; er liest im Quelltext und kann
#    nicht sehen, ob es zur Laufzeit wirkt. Dieser Punkt kann es.

# 4c UND DER RÜCKBAU EINER VON ZWEI DATENBANKEN
#    Die zweite Datenbank wieder verbinden (wie in 4b), dann die ZWEITE
#    Datenbank entfernen — die Rolle überlebt, weil sie noch an der ersten hängt.
sudo -u postgres psql -Atc \
  "SELECT database FROM pg_hba_file_rules WHERE '<ROLLE>' = ANY(user_name)"
#    erwartet: nur noch die erste Datenbank.
#    Eine Zeile für eine Datenbank, die es nicht mehr gibt, ist für PostgreSQL
#    kein Fehler (M22) — sie fiele nur auf, wenn jemand danach fragt.
srvpanel db
#    erwartet: „…alle im Bestand." und KEINE verwaiste Zeile.

# 4d UND DIE BETREIBERSEITE ZEIGT ES
#    Einstellungen → Datenbankserver, Abschnitt „PostgreSQL", neu laden.
#    erwartet: „Horcht auf" nennt jetzt *, „Fernzugriff" trägt „an",
#              „Erlaubte Netze" ist 1, und die Tabelle darunter nennt
#              203.0.113.5/32 mit einem Zugang.
#    BELEG: Screenshot.
#    Die Zahl zählt EINTRÄGE und nicht Zugänge — eine Rolle kann mehrere Netze
#    haben (docs/38 §14.3). Der Satz dazu steht unter der Tabelle; wenn er
#    fehlt, behauptet sie das Gegenteil.

# 5  UND SIE WIRKT — von einem anderen Rechner aus
#    Von einem Rechner mit der Adresse 203.0.113.5:
#      psql "postgresql://<ROLLE>:<PASSWORT>@cloudsrv24:5432/<DB>" -c "SELECT 1"
#    erwartet: geht.
#      psql "postgresql://<ROLLE>:<PASSWORT>@cloudsrv24:5432/postgres" -c "SELECT 1"
#    erwartet: FATAL: no pg_hba.conf entry for host "203.0.113.5", user
#              "<ROLLE>", database "postgres"
#    Die Meldung WÖRTLICH ins Protokoll — „scheitert" wäre auch ein Tippfehler
#    im Datenbanknamen (docs/36 §22.3m).

# 6  DIE PROBE, DIE DAS EIGENTLICHE RISIKO MISST
#    0.0.0.0/0 im Panel eintragen.
#    erwartet: abgewiesen, mit einer Meldung, die sagt warum. Der Block bleibt
#              unverändert.
#    Ebenso 198.51.100.5/24 — erwartet: abgewiesen mit dem Hinweis auf die
#    gesetzten Wirtsbits und beiden gemeinten Auflösungen (M50: PostgreSQL
#    selbst nimmt das klaglos an und lässt 254 Rechner herein).

#    UND JETZT DER RÜCKWEG, VON HAND SCHARF GEMACHT:
md5sum /etc/postgresql/16/main/pg_hba.conf
sudo sed -i 's#^host    <DB>#host    <DB>   KAPUTT#' /etc/postgresql/16/main/pg_hba.conf
#    Im Panel ein zweites Netz eintragen: 198.51.100.0/24
#    erwartet: der Vorgang SCHEITERT, und die Meldung nennt die ZEILENNUMMER.
md5sum /etc/postgresql/16/main/pg_hba.conf
#    erwartet: DIE SUMME VON VOR DEM sed — der Rückweg hat den Stand
#    zurückgelegt, den er vorgefunden hat, samt der von Hand eingebauten
#    kaputten Zeile. Das ist richtig: Er stellt her, was da war, und repariert
#    nicht, was jemand anders getan hat.

sudo sed -i 's#^host    <DB>   KAPUTT#host    <DB>#' /etc/postgresql/16/main/pg_hba.conf
sudo -u postgres psql -Atc "SELECT pg_reload_conf()"
sudo -u postgres psql -Atc "SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL"
#    erwartet: 0

#    DANN, UND DAS IST DER EIGENTLICHE BELEG:
pg_ctlcluster 16 main restart && pg_lsclusters
#    erwartet: online.
#    OHNE DIESEN NEUSTART IST PUNKT 6 NICHT GEFAHREN. Eine kaputte
#    pg_hba.conf ist im laufenden Betrieb unsichtbar (M16) und verhindert erst
#    den nächsten Start (M17) — gemessen: „pg_ctl: could not start server".
#    Wer nur prüft, dass der Server noch antwortet, prüft genau das, was auch
#    im Fehlerfall gilt.

# 7  DIE ZEILE FÜRS ZURÜCKSPIELEN HAT ALLES ÜBERLEBT   ← der zweite Bereich
head -3 /etc/postgresql/16/main/pg_hba.conf
#    erwartet: die Marke „# srvpanel: Zurückspielen einer Sicherung" und
#              darunter „local   all   +srvpanel_restore   scram-sha-256" —
#              GANZ OBEN, über „local all all peer".
#    Und die Gegenprobe, dass sie nicht nur dasteht, sondern trägt:
#      Im Panel eine Sicherung zurückspielen.
#    erwartet: geht.
#    OHNE PUNKT 7 IST DER TEUERSTE FEHLER DIESES SCHRITTS NICHT GEFAHREN
#    (§14.5, M47): Der Rückweg aus Punkt 6 ist genau der Griff, der diese Zeile
#    wegwerfen kann — und niemand merkt es, bis Wochen später ein
#    Zurückspielen an „Peer authentication failed" scheitert.

# 8  DER WEG ZURÜCK
#    Im Panel das Netz zurücknehmen.
grep -c "<ROLLE>" /etc/postgresql/16/main/pg_hba.conf
#    erwartet: 0

#    Und dasselbe über den Rückbau: den ZUGANG im Panel entfernen (mit einem
#    zweiten, vorher eingetragenen Netz).
grep -c "<ROLLE>" /etc/postgresql/16/main/pg_hba.conf
#    erwartet: 0 — pg.role.remove nimmt die Zeilen im selben Vorgang mit.
#    Eine Zeile für eine gelöschte Rolle ist für PostgreSQL kein Fehler (M22);
#    sie fällt nur auf, wenn jemand danach fragt.

srvpanel db
#    erwartet: „0 Zugangsregel(n)…" bzw. „…alle im Bestand." und
#              „Nichts liegengeblieben."

#    UND DIE GEGENPROBE ZUR MELDUNG — sonst ist sie nie rot gewesen:
sudo sed -i 's#^# END srvpanel#host    stillgelegt   x0000000000000000_web   198.51.100.0/24   scram-sha-256\n# END srvpanel#' /etc/postgresql/16/main/pg_hba.conf
srvpanel db
#    erwartet: „1 von 1 Zugangsregel(n) in pg_hba.conf zeigen auf nichts im
#              Bestand:" und die Zeile darunter. GEMELDET UND NICHT GELÖSCHT.
sudo sed -i '/x0000000000000000_web/d' /etc/postgresql/16/main/pg_hba.conf

# 8b DER GESTRANDETE ZUSTAND   ← der einzige, den dieser Lauf sonst auslässt
#    Netze an einem Server, der nur lokal horcht, sind Zeilen, die im Panel
#    richtig aussehen und niemanden hereinlassen. Sie entstehen NUR so:
#    ausschalten, während noch Netze eingetragen sind.
#
#    Auf einem verbliebenen Zugang noch einmal 203.0.113.5/32 eintragen, dann:
srvpanel db --remote=off
#    Einstellungen → Datenbankserver, Abschnitt „PostgreSQL".
#    erwartet: eine WARNUNG, die die Zahl der Netze nennt und sagt, dass die
#              Zeilen in pg_hba.conf stehen und niemanden hereinlassen,
#              solange der Fernzugriff aus ist.
#    BELEG: Screenshot.
#
#    Danach wieder aufräumen — das Netz zurücknehmen —, sonst stimmen die
#    md5-Summen in Punkt 9 nicht:
srvpanel db --remote=on --bind=*
#    (Netz im Panel zurücknehmen, dann weiter mit Punkt 9.)

# 9  ABSCHALTEN
srvpanel db --remote=off
#    erwartet: die Warnung nennt die Zahl der Zugänge UND Netze, die danach
#              niemand mehr erreicht.
ls /etc/postgresql/16/main/conf.d/
sudo -u postgres psql -Atc "SHOW listen_addresses"
md5sum /etc/postgresql/16/main/postgresql.conf /etc/postgresql/16/main/pg_hba.conf
#    erwartet: 60-srvpanel.conf ist fort, listen_addresses ist wieder
#              localhost, und BEIDE md5-Summen sind die von Punkt 1.

# 9b UND DIE SEITE SAGT DASSELBE
#    Einstellungen → Datenbankserver, Abschnitt „PostgreSQL", neu laden.
#    erwartet: „Horcht auf" nennt wieder localhost, „Fernzugriff" trägt „aus",
#              „Erlaubte Netze" ist 0, und KEINE Warnung steht da.
#    BELEG: Screenshot.
#    Das ist die Gegenprobe zu 2b: Wäre die Seite eine Behauptung statt einer
#    Messung, stünde hier noch das, was vor dem Abschalten galt.
```

---

## 5. Was zurückkommen soll

**Jede Ausgabe, nicht die Zusammenfassung.** Was dieser Lauf misst, steht in
Zeilen, die richtig aussehen können und es nicht sind — die ausgegebenen
`pg_hba_file_rules`-Zeilen, die `md5sum`-Werte vor und nach Punkt 6, die
Meldung, mit der ein abgewiesenes Netz zurückkommt.

Vier Dinge gehören ausdrücklich dazu, weil sie sonst untergehen:

1. **Die Zeile aus Punkt 4, wörtlich.** In ihrer Spalte `database` muss die
   Datenbank stehen und nicht `all`. „Eine Zeile gefunden" ist keine Antwort auf
   diese Frage.
2. **Beide `md5sum`-Werte aus Punkt 6.** Der zweite belegt den Rückweg; ohne den
   ersten belegt er nichts.
3. **Die Ausgabe von `pg_lsclusters` nach dem Neustart in Punkt 6.** Ohne diesen
   Neustart ist der Kern des Laufs nicht gefahren.
4. **Die drei Zeilen aus Punkt 7** (`head -3`). Sie belegen, dass der Rückweg
   den *anderen* verwalteten Bereich nicht mitgenommen hat — den Fund, der
   diesem Schritt seinen Satz gegeben hat.

Und wenn ein Punkt anders ausgeht als beschrieben: **die Ausgabe schicken und
nicht nacharbeiten.** Ein Lauf, der unterwegs repariert wird, misst den Zustand
nach der Reparatur.

---

## 6. Was dieser Lauf ausdrücklich nicht prüft

- **Den Paketfilter.** Die Beschränkung gilt im Datenbankserver und nicht in
  einer Firewall; das steht so auf der Seite und kommt mit P9.
- **Die Fassungsspanne.** Gefahren wird gegen PostgreSQL 16 auf Ubuntu 24.04.
  Was auf Debian 12, Debian 13 und Ubuntu 22.04 gilt, steht als offener Punkt in
  `docs/38 §2.3`.
- **Den Dauerbetrieb.** Ob eine `pg_hba.conf` nach fünfzig Änderungen noch
  aussieht wie gedacht, sagt kein Lauf von einer Stunde. Dafür gibt es den
  Abgleich in `srvpanel db` — Punkt 8 fährt ihn einmal.
- **Last.** Zweihundert Zeilen im Block sind gerechnet (`docs/38 §14.1`) und
  nicht gemessen.
