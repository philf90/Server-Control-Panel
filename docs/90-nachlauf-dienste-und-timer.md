# Der Nachlauf zu `0.7.3-rc.1` — A2 auf einem echten Server

**Angelegt am 31. August 2026, vor dem Lauf.** Das ist keine Formalie: Dieses
Projekt hat in `docs/45`, `docs/48`, `docs/59`, `docs/84` und `docs/88` mehr
Fehler im **Prüfmittel** gefunden als im Prüfling, und der Grund war jedes Mal
ein Lauf, der beim Fahren entstand.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

---

## 0. Was dieser Lauf ist und was er nicht ist

**Er ist die Abnahme von A2.** Das Kriterium steht in `docs/81 §A2`:

> Fertig, wenn ein Timer ohne nächsten Termin auf der Seite als kaputt
> erkennbar ist, und zwar ohne dass man die Zahl deuten muss.

Das ist **Punkt 4**, und ohne ihn ist der Lauf nicht abgenommen. Die übrigen
Punkte prüfen, was A2 daneben gebaut hat, und zwei Reste aus früheren Läufen.

**Warum er nötig ist, obwohl alles gemessen wurde.** A2 ist vollständig gegen
**systemd 255 in einer Container-Namespace** entwickelt worden (`docs/89 §1`) —
mit Attrappen-Units, die ich selbst geschrieben habe. Auf `cloudsrv24` stehen
sechzehn echte Units, davon zwölf eigene, und drei Annahmen sind dort zum ersten
Mal der Wirklichkeit ausgesetzt:

| Annahme | gemessen gegen | auf dem Server |
|---|---|---|
| `systemctl show a b c …` gibt je Unit einen Block, getrennt durch Leerzeilen | **3** Units | **16** |
| `list-timers --output=json` gibt `next` als rohe Mikrosekunden | systemd **255** | ungemessene Fassung |
| Die Kandidaten je Rolle fallen auf den zusammen, den es gibt | selbstgebaute Attrappen | echtes `ssh`/`sshd`, echtes MariaDB |

**Die erste ist die gefährlichste.** `Units::readMany()` wirft eine Ausnahme,
wenn die Zahl der Blöcke nicht zur Zahl der Fragen passt — und zwar mit Absicht,
weil eine verschobene Zuordnung stiller Unsinn wäre. Passt sie auf dem Server
nicht, ist die **ganze Seite** ein Fehler und nicht eine falsche Zeile.

> **Eine Zusicherung, die im Container hält, ist auf dem Server eine Vermutung —
> bis jemand sie dort misst.**

**Was er nicht ist.** Keine Abnahme von A5, A1 oder A9; die stehen. Und keine
Prüfung der Knöpfe je Unit — die gibt es nicht, A2 baut eine Ansicht.

---

## 1. Was vorher dasteht

**Vor dem ersten Punkt aufschreiben.** Ohne diese Zeilen ist später nicht zu
unterscheiden, ob ein Wert falsch ist oder nur anders, als ich erwartet hatte.

```bash
# a) Die Fassung des Panels
srvpanel version

# b) Die Fassung von systemd — sie entscheidet Punkt 3
systemctl --version | head -1

# c) Welche Units es auf diesem Server wirklich gibt
for u in srvpanel-agentd srvpanel-web srvpanel-worker srvpanel-metrics \
         srvpanel-usage srvpanel-tls srvpanel-cron srvpanel-dns; do
  printf '%-26s %s\n' "$u.service" "$(systemctl is-active "$u.service" 2>&1)"
done
for u in srvpanel-usage srvpanel-tls srvpanel-cron srvpanel-dns; do
  printf '%-26s %s\n' "$u.timer" "$(systemctl is-active "$u.timer" 2>&1)"
done
for u in nginx.service mariadb.service mysql.service ssh.service sshd.service \
         cron.service crond.service; do
  printf '%-26s %s\n' "$u" "$(systemctl show "$u" --property=LoadState --value)"
done
```

**Erwartet:** `0.7.3-rc.1`; systemd irgendwo zwischen 249 und 257; die acht
eigenen Dienste und vier Timer `active`; von den fremden **je Rolle genau einer**
`loaded` — `nginx.service`, eine der beiden Datenbankunits, eine der beiden
SSH-Units, eine der beiden Cron-Units.

**Aufschreiben, welche.** Punkt 1 vergleicht die Seite damit, und ohne diese
Liste prüft er sich selbst.

---

## 2. Punkt 1 — Die Seite steht, und sie zeigt sechzehn Zeilen

**Als Betreiber anmelden**, dann `/services` öffnen — über den Menüpunkt
**„Dienste"** in der Gruppe **„Betrieb"** und nicht über die Adresszeile: Der Ort
des Menüpunkts ist Teil dessen, was hier geprüft wird.

**Erwartet:**

- Zwei Bereiche, **„Dienste"** und **„Timer"**.
- Im ersten **zwölf** Zeilen: acht eigene Dienste, dann `nginx.service`, die
  Datenbankunit, die SSH-Unit, die Cron-Unit.
- Im zweiten **vier** Zeilen: die vier eigenen Timer.
- **Keine Unit steht doppelt.** Insbesondere nicht `ssh.service` *und*
  `sshd.service`, nicht `mariadb.service` *und* `mysql.service`.
- Die Zeilen der eigenen Units zeigen einen **Zustand**, eine **PID** und eine
  **Beschreibung**, die systemd geliefert hat — keine leeren Striche.

**Was ein Fehlschlag bedeutet.** Stehen achtzehn oder neunzehn Zeilen da, fällt
`Catalog::pick()` nicht zusammen — dann ist die Rolle nicht erkannt, oder
`present` steht für beide Kandidaten auf `true`. Fehlen Zeilen, hat `Catalog`
eine Unit, die das Paket nicht ablegt (was `UnitCatalogTest` halten sollte).

**Und der Fall, der im Container nicht vorkam:** Steht bei einer eigenen Unit
`nicht installiert`, obwohl `systemctl is-active` sie oben als `active` gemeldet
hat, dann stimmt die Zuordnung der Blöcke nicht — und das ist Punkt 2.

---

## 3. Punkt 2 — Ein Aufruf für sechzehn Units, und die Blockzahl stimmt

**Der Punkt, an dem A2 am ehesten bricht.** Gemessen wurde die Blocktrennung
gegen **drei** Units; hier sind es sechzehn.

```bash
# Genau der Aufruf, den der Agent macht — die Reihenfolge ist die des Katalogs.
systemctl show \
  srvpanel-agentd.service srvpanel-web.service srvpanel-worker.service \
  srvpanel-metrics.service srvpanel-usage.service srvpanel-usage.timer \
  srvpanel-tls.service srvpanel-tls.timer srvpanel-cron.service \
  srvpanel-cron.timer srvpanel-dns.service srvpanel-dns.timer \
  nginx.service mariadb.service mysql.service ssh.service sshd.service \
  cron.service crond.service \
  --property=Id,Description,LoadState,ActiveState,SubState,UnitFileState,MainPID,ExecMainStartTimestamp,NRestarts,NextElapseUSecRealtime,NextElapseUSecMonotonic,Unit \
  --no-pager > /tmp/a2-show.txt

# Wie viele Blöcke sind es? Erwartet: 19 — so viele, wie gefragt wurde.
awk 'BEGIN{RS=""} END{print NR}' /tmp/a2-show.txt

# Und die Gegenprobe: dieselbe Zahl Ids, in der gefragten Reihenfolge.
grep -c '^Id=' /tmp/a2-show.txt
grep '^Id=' /tmp/a2-show.txt
```

**Erwartet:** beide Zahlen **19** — die neunzehn Katalogeinträge, nicht sechzehn:
Der Agent fragt **alle** Kandidaten und lässt erst danach die zusammenfallen, die
dieselbe Rolle haben.

**Die Falle, auf die zu achten ist.** Bei `ssh.service` und `sshd.service`
antwortet systemd zweimal mit **demselben `Id`** — das ist gemessen und richtig.
Die `Id`-Liste hat deshalb weniger *verschiedene* Werte als Zeilen; entscheidend
ist die **Zahl der Blöcke**, nicht die Zahl der verschiedenen Namen.

**Was ein Fehlschlag bedeutet.** Steht dort eine andere Zahl als 19, wirft
`Units::readMany()` und `/services` gibt einen 500er. Dann ist der Befund nicht
„eine Zeile ist falsch", sondern „der Mehrfachleser trägt auf diesem systemd
nicht" — und die Behebung ist, je Unit einzeln zu fragen, nicht die Zusicherung
zu entfernen.

> **Eine Zusicherung, die man beim ersten Widerspruch herausnimmt, war keine.**

---

## 4. Punkt 3 — Die vier eigenen Timer tragen ihren nächsten Termin

**Zuerst die Frage, die den Punkt entscheidet:** Kann dieses systemd überhaupt
JSON?

```bash
# a) Trägt die Fassung --output=json?
systemctl list-timers --all --output=json --no-pager | head -c 200; echo

# b) Und die menschenlesbare Fassung daneben, als Vergleichswert
systemctl list-timers --all --no-pager | grep -E 'NEXT|srvpanel'
```

**Erwartet zu (a):** eine JSON-Liste, in der jedes Element `unit` und `next`
trägt und `next` eine grosse Zahl ist (Mikrosekunden seit 1970). Kommt statt
dessen eine Fehlermeldung über eine unbekannte Option, ist das **kein
Fehlschlag** dieses Laufs, sondern der gemessene Beleg dafür, dass die Option auf
dieser Fassung fehlt — dann muss (c) unten „unbekannt" zeigen und nicht ein
falsches Datum.

**Dann auf der Seite:**

**(c) Erwartet im Bereich „Timer":** Alle vier Zeilen zeigen einen Zustand
**bereit** und in der Spalte **Nächster Termin** ein Datum, das mit der Spalte
`NEXT` aus (b) übereinstimmt — auf die Minute.

**Wenn (a) fehlgeschlagen ist**, steht dort bei allen vieren **`unbekannt`** und
bei keinem `—`. Das ist der Unterschied, der eigens gebaut wurde:

> **„Kein Termin" ist ein Schaden, „Termin unbekannt" eine Lücke im Messmittel —
> und dieselbe Zelle für beides machte aus jeder Lücke einen Befund.**

Steht bei einem gesunden Timer ein `—`, ist das ein Befund: Dann hat `has_next`
`false` geliefert, obwohl `list-timers` einen Termin kennt, und die beiden
Quellen widersprechen sich.

---

## 5. Punkt 4 — Ein Timer ohne nächsten Termin ist als kaputt erkennbar

**Das ist das Abnahmekriterium von A2.** Ohne diesen Punkt ist der Lauf nicht
abgenommen.

Der Zustand muss **hergestellt** werden — auf einem gesunden Server gibt es ihn
nicht. Genommen wird `srvpanel-tls.timer`, und zwar mit Bedacht: Er läuft
**täglich** und trägt `Persistent=true`; ein Fenster von zwei Minuten lässt ihn
keinen Lauf verpassen, weil ein versäumter nachgeholt wird. Bei `cron` (alle fünf
Minuten) oder `dns` (alle fünfzehn) wäre das anders.

> **Ein Prüfkörper, der auf einer laufenden Maschine entsteht, wird nach seiner
> Wirkung ausgesucht und nicht nach seiner Bequemlichkeit.**

```bash
# a) Vorher festhalten, damit der Rückweg belegt ist
systemctl list-timers --all --no-pager | grep srvpanel-tls

# b) Den Zustand herstellen
systemctl stop srvpanel-tls.timer
systemctl show srvpanel-tls.timer \
  --property=ActiveState,SubState,NextElapseUSecRealtime,NextElapseUSecMonotonic
```

**Erwartet zu (b):** `ActiveState=inactive`, `SubState=dead`,
`NextElapseUSecRealtime=` leer, `NextElapseUSecMonotonic=infinity`.

**Jetzt `/services` neu laden.** Erwartet:

1. Die Zeile `srvpanel-tls.timer` zeigt in der Spalte **Zustand** den Satz
   **„kein nächster Termin"**, in Rot.

   Steht dort **„gestoppt"**, ist das ein Befund: Die Anzeige folgt dann dem
   Zustand von systemd statt dem fehlenden Termin, und genau das soll sie nicht.
   Steht dort **„nicht installiert"**, ist `present` falsch — dann liest der
   Leser `LoadState` einer gestoppten Unit als „gibt es nicht".
2. In der Spalte **Nächster Termin** steht **`—`** und nicht `unbekannt`.
3. **Oben auf der Seite** steht die Meldung in Bernstein:
   *„1 Timer hat keinen nächsten Termin und meldet trotzdem „active"."*

**Punkt 3 der Meldung ist der eigentliche Beleg.** Sie muss den Zustand
**zählen** und benennen, ohne dass man eine Zahl deutet — genau das verlangt das
Kriterium.

```bash
# c) Der Rückweg, und er wird belegt und nicht angenommen
systemctl start srvpanel-tls.timer
systemctl list-timers --all --no-pager | grep srvpanel-tls
```

**Erwartet zu (c):** derselbe `NEXT`-Wert wie in (a), oder ein späterer — und auf
der neu geladenen Seite wieder **bereit** mit Datum, und **keine** bernsteinfarbene
Meldung mehr.

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

Der Satz stammt aus `docs/78` und ist der Grund, warum (c) zum Punkt gehört und
nicht zum Aufräumen.

---

## 6. Punkt 5 — Die Zeit steht in der Anzeigezone und nicht in UTC

Der Termin wird **auf dem Server** zu Text, über `Clock::display` (`docs/40`).
Ein `toLocaleString` im Browser nähme die Zone des Betrachters, und `docs/19`
verlangt die eingestellte.

```bash
# a) Was der Server für eine Zeit hat
date -u '+%F %H:%M UTC'; date '+%F %H:%M %Z'
```

**(b) Auf der Seite** die Anzeigezone unter **Einstellungen → Allgemein**
nachsehen und mit dem Datum in der Spalte **Nächster Termin** vergleichen.

**Erwartet:** Das Datum steht in der eingestellten Anzeigezone. Steht dort UTC,
obwohl die Einstellung etwas anderes sagt, ist das ein Befund — und zwar einer,
der jede Zeitangabe dieses Panels beträfe und nicht nur diese Seite.

**Die Gegenprobe, ohne die (b) nichts sagt:** Wenn die Anzeigezone auf UTC steht,
prüft dieser Punkt nichts. Dann die Zone kurz umstellen, die Seite neu laden, den
Wert vergleichen und zurückstellen.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 7. Punkt 6 — Eine Unit, die es nicht gibt, erfindet keine Zahlen

Im Container hat der Blick aufs Bild zwei Befunde gebracht, die keine Zahl
gemeldet hat: Eine Unit ohne Datei beantwortet `Description` mit dem erfragten
Namen und `NRestarts` mit `0`, und die Seite zeigte daraus eine Beschreibung, die
den Unitnamen wiederholt, und „0 Neustarts" für etwas, das nicht installiert ist.

**Auf einem gesunden Server ist dieser Fall womöglich nicht herstellbar** — die
Kandidaten, die es nicht gibt, fallen in `Catalog::pick()` weg, bevor eine Zeile
entsteht. Dann gilt:

**(a) Wenn keine Zeile „nicht installiert" zeigt**, ist der Punkt **nicht
prüfbar** und wird als solcher aufgeschrieben. Das ist kein Ausfall — der Fall
ist in `UnitStateTest` mit gemessenen Prüfkörpern gehalten.

**(b) Wenn eine Zeile „nicht installiert" zeigt** (etwa weil ein Dienst auf
diesem Server fehlt), muss sie in **PID**, **Neustarts** und **Beschreibung**
jeweils `—` tragen. Steht dort `0` oder der Unitname, ist die Behebung nicht
angekommen.

> **Ein Wert, den systemd für eine Unit liefert, die es nicht gibt, ist keine
> Messung.**

---

## 8. Punkt 7 — Die Übersicht zeigt weiter dieselben drei Zeilen

**Eine Regressionsprüfung, und sie ist wichtiger, als sie aussieht.** In
`OverviewController` standen drei feste Unitnamen; sie kommen jetzt aus
`Catalog::essential()`. Die Titelseite ist die Seite, die ein Betreiber am
häufigsten sieht.

**Auf `/` (Übersicht)** die Kachel mit den Diensten ansehen.

**Erwartet:** dieselben Zeilen wie vor dem Update — der Agent, der Webserver, die
Datenbank, dazu die PostgreSQL-Cluster, falls es welche gibt. **Nicht** sechzehn
Zeilen, und **keine** zusätzliche Zeile `mysql.service`, die „nicht installiert"
meldet.

**Was ein Fehlschlag bedeutet.** Steht dort eine Zeile zuviel, fällt `pick()` auf
der Übersicht nicht zusammen — dieselbe Ursache wie in Punkt 1, aber an einer
Stelle, die jeder Betreiber sieht.

---

## 9. Punkt 8 — Die neue Gliederung der Navigation

**Als Betreiber.** Erwartet vier Gruppen in dieser Reihenfolge:

| Gruppe | Punkte |
|---|---|
| Verwaltung | Kunden · Pläne · Abonnements · Domains · Datenbanken |
| **Betrieb** | Vorgänge · Protokoll · Logs · **Dienste** · Updates · Konten |
| **Einstellungen** | Zugang · Allgemein · PHP-Versionen · Datenbankserver · Mailversand · Zertifikat · DNS-Zugang |
| Konto | Mein Konto |

**Und die Probe, die das Telefon betrifft:** dieselbe Seite bei 390 px — die
Schublade öffnen und **bis zum Ende rollen**. Erwartet: alle vier Überschriften
lesbar, kein waagerechtes Rollen, „Mein Konto" erreichbar.

**Was hier auffallen könnte und im Container nicht auffiel:** Die Schublade ist
um zwei Überschriften länger geworden. Wenn der Weg zu „Mein Konto" jetzt spürbar
weiter ist, gehört das aufgeschrieben — nicht als Ausfall, sondern als Messwert
für die Frage, ob die Kundennavigation (neun Punkte unter „Konto") als Nächstes
geteilt wird.

---

## 10. Punkt 9 — Der Administrator sieht die Seite, der Kunde nicht

Die Seite gehört `inspect-server` — dieselbe Teilung wie bei den Updates.

**(a) Als Administrator** (das zweite Adminkonto aus dem A9-Lauf): Der Menüpunkt
**„Dienste"** steht da, die Seite lädt, die sechzehn Zeilen stehen.

**(b) Als Kunde:** Der Menüpunkt steht **nicht** da, und `/services` von Hand
aufgerufen gibt **403**.

**Die Gegenprobe zu (b), ohne die sie nichts sagt:** Eine Seite, die der Kunde
sehen darf — etwa `/domains` —, muss im selben Anlauf **200** geben. Sonst prüft
(b) womöglich eine abgelaufene Sitzung statt der Fähigkeit.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

---

## 11. Punkt 10 — Die vier Behebungen aus `docs/86 §5`, endlich auf einem Server

**Ein Rest aus `docs/88`.** Die vier Behebungen sind gegen `rc.7` ausgeliefert
und im Container geprüft; ein Server hat sie nie gesehen.

```bash
# a) Eine Wegwerfquelle, die nie erreichbar sein kann (RFC 2606)
cat > /etc/apt/sources.list.d/zz-nachlauf.list <<'EOF'
deb http://nicht.erreichbar.invalid/ubuntu noble main
EOF
```

**(b)** Im Panel unter **Betrieb → Updates** auf **„Nachsehen"** drücken und den
entstandenen Vorgang öffnen.

**Erwartet:** Der Vorgang steht auf **fertig**, und die Meldung auf der
Detailseite ist **bernsteinfarben** — nicht grün. Sie nennt die nicht erreichte
Quelle.

**Das ist der ganze Punkt.** Vor der Behebung stand dort eine grüne Meldung über
einen Lauf, der die Hälfte seiner Quellen nicht erreicht hatte:

> **Ein Feld im Payload ist noch keine Spalte.**

```bash
# c) Aufräumen — und die Gegenprobe, dass es weg ist
rm /etc/apt/sources.list.d/zz-nachlauf.list
apt-get update 2>&1 | tail -3
```

**Erwartet zu (c):** kein `W:` mehr über `nicht.erreichbar.invalid`. Und danach
noch einmal „Nachsehen" im Panel: Die Meldung ist wieder grün. **Auch hier ist
das Zurücknehmen Teil des Punktes.**

---

## 12. Punkt 11 — Punkt 4 aus `docs/87`, falls Paketbestand da ist

**Der letzte offene Punkt des vorigen Nachlaufs.** Er braucht Pakete, die
anstehen — ohne sie ist er nicht fahrbar und wird als solcher aufgeschrieben.

```bash
# a) Steht überhaupt etwas an?
apt-get -s upgrade | grep -c '^Inst '
```

**Wenn `0`:** Punkt entfällt, weiter zu §13.

**Wenn mehr als `0`:** Unter **Betrieb → Updates** auf **„Alle installieren"**
drücken, den Vorgang bis `fertig` verfolgen — und dann **ohne die Seite neu zu
laden** noch einmal auf denselben Knopf drücken.

**Erwartet:** Der zweite Lauf meldet, dass er nichts verändert hat, und **nicht**
einen Fehlschlag. Der Knopf muss dafür noch da sein: Der Paketbereich hängt an
`v-if="upgradable.length === 0"` und verschwindet genau in dem Zustand, den der
erste Lauf herstellt — deshalb **ohne neu zu laden**.

---

## 13. Was dieser Lauf ausdrücklich **nicht** prüft

- **Knöpfe je Unit.** Start, Stopp und Neustart aus der Oberfläche gibt es
  nicht; A2 baut eine Ansicht. Die Positivliste in `ServiceAction` trägt seit
  dem 30. August nur noch `srvpanel-*`, und das ist eine Entscheidung des
  Betreibers und keine Lücke.
- **Die versionierten Units.** `php8.3-fpm.service` und
  `postgresql@16-main.service` stehen bewusst nicht im Katalog; ihre Namen baut,
  wer sie kennt. Die Übersicht zeigt die Cluster weiterhin über `Clusters::unit()`.
- **Die Journalzeilen je Unit.** Sie stehen in der Skizze von A2 und sind nicht
  gebaut.
- **Ein Timer, dessen nächster Termin *monoton* ist.** Alle vier eigenen Timer
  tragen `OnCalendar`; der Fall, in dem `OnBootSec` vor der nächsten Kalenderzeit
  liegt, tritt nur in den ersten Minuten nach einem Neustart auf. Wer den Server
  ohnehin neu startet, sieht in `docs/89 §3` nach, was dann dastehen muss.
- **Der Fall „`systemctl` antwortet gar nicht".** Er ist in `LoginTest` und über
  `live` gehalten; ihn hier herzustellen hiesse, den Agenten anzuhalten.

---

## 14. Wann der Nachlauf durch ist

**Abgenommen ist A2, wenn Punkt 4 erfüllt ist** — der Timer ohne Termin ist auf
der Seite als kaputt erkennbar, und die Anzeige nimmt den Zustand zurück, wenn er
weg ist.

**Die übrigen Punkte tragen die Abnahme, hindern sie aber einzeln nicht**, mit
zwei Ausnahmen:

- **Punkt 2 ist ein Ausschlusskriterium.** Trägt der Mehrfachleser auf diesem
  systemd nicht, gibt die Seite einen 500er, und dann ist Punkt 4 gar nicht
  messbar.
- **Punkt 7 ebenso.** Eine Übersicht, die nach diesem Update anders aussieht als
  vorher, ist eine Regression an der meistgesehenen Seite des Panels.

**Punkt 6 und Punkt 11 dürfen als „nicht herstellbar" ausfallen** — der eine,
weil ein gesunder Server keine fehlende Unit hat, der andere, weil er
Paketbestand braucht. Beide werden dann benannt und nicht abgehakt.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

**Das Protokoll bekommt eine eigene Nummer.** Sie steht bewusst **nicht** hier:
`docs/81` hat einmal eine genannt, die einem anderen Dokument gehörte, und
`DocLinkTest` konnte das nicht sehen.
