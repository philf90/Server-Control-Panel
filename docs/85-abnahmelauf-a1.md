# Abnahmelauf A1 — Paketquellen und Systemupdates auf `cloudsrv24`

Geschrieben am 26. August 2026. Der Lauf ist **Schritt 10** aus `docs/81 §9` und
prüft in **fünfzehn Punkten** die **acht Punkte des Abnahmekriteriums** aus
`docs/81 §4` — dazu die drei Dinge, die `docs/81 §2.3h` als „nur auf einem echten
Server messbar" benennt, die Automatik aus Schritt 8 und einen Bildsatz.

**Dieses Dokument ist die Vorschrift, nicht das Protokoll.** Das Protokoll
entsteht **während** des Laufs als eigenes Dokument und nicht danach — solange
keine Messung darin steht, ist ein Protokoll eine Gliederung.

*Seine Nummer steht hier bewusst nicht.* `docs/81` hat einmal eine genannt, die
einem ganz anderen Dokument gehörte; `DocLinkTest` konnte das nicht sehen, weil
er prüft, ob es die Datei gibt, und nicht, ob sie das Gemeinte ist.

> **Eine Nummer, die man vergibt, bevor es die Datei gibt, ist eine Zusage an
> einen Namen und nicht an einen Inhalt.**

---

## 1. Was dieser Lauf beweist, und was schon feststeht

**Fünf der acht Punkte sind Lese- und Anzeigefragen**, und für sie gibt es
Wächter: `InstLineTest` über den Paketleser, `SourceListTest` über die Quellen,
`UnattendedStateTest` über die Automatik, `PackageNameTest` über die
Positivliste, `RebootConfirmTest` über den Neustart. Alle grün, alle mit
gegengeprüften Brüchen. Was ihnen fehlt, ist **echtes apt auf einem Server, den
seit Wochen niemand aktualisiert hat.**

**Drei Punkte misst kein Test, und deshalb gibt es diesen Lauf.**

**Punkt 5 ist der teuerste, und er ist der einzige, der A1 zum Scheitern
bringen kann.** Ein Upgrade, das `srvpanel` selbst enthält, startet mitten im
Lauf `srvpanel-worker` neu — also den Prozess, der die Operation abgesetzt hat.
Dass die transiente Unit das überlebt, **behauptet dieses Projekt seit P0** (so
läuft `panel.update`), und belegt hat es nur der eigene Gebrauch.

> **Ein Verfahren, das immer funktioniert hat, ist nicht dasselbe wie eines, das
> jemand gemessen hat.**

**Punkt 3 ist M5 von der anderen Seite.** Im Container ist der Durchstich gegen
echte apt-Ausgabe belegt, aber gegen eine Quelle, die es nie gab. Auf
`cloudsrv24` gibt es Sury, und eine unerreichbare Sury ist der Fall, aus dem der
ganze Befund stammt: Der Betreiber las *„Unable to locate package php8.4-fpm"*
— der Zustand richtig gemeldet, die Ursache falsch.

**Punkt 6 lässt sich hier gar nicht herstellen.** Eine Conffile entsteht, wenn
ein Paketbetreuer eine Konfigurationsdatei ändert, die der Betreiber auch
geändert hat. Das ist kein Zustand, den man sich wünscht — er muss **hergestellt**
werden, und wie, steht in Punkt 6 unten.

**Was schon steht und hier nicht wiederholt wird:** die CI auf allen vier
Zielplattformen gegen den Stand dieses Zweiges, der volle Lauf des Bruchskripts
(1524 Prüfungen, `FEHLT: 0`, alle beissend), der CI-Job `apt-messrunde` auf
Debian 12/13 und Ubuntu 22.04/24.04, und die Bildrunde zur Updates-Seite bei 390
und 1440 px in beiden Themes gegen die echte Seite mit laufendem Agenten.

---

## 2. Was man braucht

- **`cloudsrv24` mit dem Stand, gegen den abgenommen wird.** Punkt 0 belegt ihn.
- **Ein Terminal mit SSH auf `cloudsrv24`** und `sudo`, das `apt` darf.
- **Ein Browser**, angemeldet als **Betreiber**. Die Updates-Seite gehört ihm
  allein (`can:operate-server`) — das ist der offene Punkt aus `docs/81 §2.3h`,
  und dieser Lauf ändert daran nichts.
- **Ein Server, der aktualisierbare Pakete hat.** Steht die Zahl auf 0, misst
  Punkt 1 nichts. Vor dem Lauf einmal nachsehen, und wenn nötig ein paar Tage
  warten oder Punkt 0b fahren.

  > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
  > steht.**
- **Etwa 2 Stunden**, davon der grösste Teil Wartezeit: Punkt 5 fährt einen
  vollen `dist-upgrade`, und wie lange der dauert, ist genau das, was `docs/81
  §2.3h` Punkt 1 offen lässt.
- Die Bereitschaft, **jede Ausgabe zu schicken — auch die, die richtig
  aussieht.**

  > **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt
  > wurde.**

### 2.1 Der Rückweg, bevor er gebraucht wird

Dieser Lauf fasst als einziger der Reihe **die Paketverwaltung des laufenden
Servers** an. Drei Wege zurück, und sie gehören **vor** dem Lauf einmal gelesen:

```bash
# 1 — die tote Quelle aus Punkt 3 wieder herstellen
ssh cloudsrv24 'sudo mv /etc/apt/sources.list.d/sury-php.sources.abnahme \
                       /etc/apt/sources.list.d/sury-php.sources && sudo apt-get update'

# 2 — eine hängende dpkg-Sperre (nur, wenn ein Lauf wirklich abgebrochen ist)
ssh cloudsrv24 'sudo dpkg --configure -a'

# 3 — das Panel kommt nach Punkt 5 nicht wieder
ssh cloudsrv24 'sudo systemctl status srvpanel-web srvpanel-worker srvpanel-agentd'
ssh cloudsrv24 'sudo tail -50 /var/log/srvpanel/upgrade.log'
```

**Der dritte ist der wichtige, und er ist zugleich der Beleg für Punkt 5.**
Bleibt das Panel nach dem Upgrade weg, steht die Ursache in `upgrade.log` — und
dass sie dort steht, ist die halbe Zusage dieses Punktes.

> **Ein Rückweg, den man erst sucht, wenn man ihn braucht, ist keiner.**

### 2.2 Was dieser Lauf am Server verändert

| Was | Punkt | Zurückgenommen in |
|---|---|---|
| Eine unerreichbare apt-Quelle (Sury umbenannt) | 3 | Punkt 3c |
| Der ganze Paketstand — ein voller `dist-upgrade` | 5 | **nicht** — das ist der Zweck |
| Eine hergestellte Conffile | 6 | Punkt 6c |
| Eine Quelle aus- und wieder eingeschaltet | 2b | Punkt 2b, im selben Punkt |
| `unattended-upgrades` an- und ausgeschaltet | 9 | Punkt 9c |

**Punkt 5 ist nicht zurücknehmbar, und das ist kein Versehen.** apt kann ein
Upgrade nicht ehrlich zurückrollen (`docs/81 §10`), und ein Lauf, der so tut,
wäre schlimmer als keiner. Wer diesen Punkt fährt, aktualisiert `cloudsrv24`
wirklich — deshalb steht er hinten und nicht vorne.

**Kein Kundendatensatz wird angefasst**, keine Domain angelegt, kein Zertifikat
bestellt.

---

## 3. Der Lauf

### Punkt 0 — Welche Fassung läuft

```bash
ssh cloudsrv24 'srvpanel version'
ssh cloudsrv24 'dpkg-query -W -f="${Version}\n" srvpanel'
```

**Zurück:** beide Zeilen, wörtlich. Sie stehen im Protokoll als erste Zeile —
jeder Befund darunter gilt für genau diese Fassung.

**Und die Gegenprobe zum Agenten**, weil ohne ihn jede Zahl dieses Laufs aus
einer Fehlermeldung stammt:

```bash
ssh cloudsrv24 'sudo systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-web'
```

### Punkt 0b — Gibt es überhaupt etwas zu messen

```bash
ssh cloudsrv24 'sudo apt-get update && apt-get -s dist-upgrade | grep -c "^Inst "'
```

**Zurück:** die Zahl. **Ist sie 0, wird der Lauf abgebrochen** und auf einen Tag
verschoben, an dem sie es nicht ist. Ein Lauf über einen aktuellen Server misst
die Punkte 1, 2, 5 und 8 nicht — er zeigt bei allen vieren dasselbe wie ein
kaputtes Panel.

### Punkt 1 — Die drei Zahlen stimmen (Kriterium 1)

Auf der Kommandozeile:

```bash
ssh cloudsrv24 'apt-get -s dist-upgrade | grep -c "^Inst "'          # aktualisierbar
ssh cloudsrv24 'apt-get -s dist-upgrade | grep "^Inst " | grep -c "\-security"'
ssh cloudsrv24 'apt-mark showhold | wc -l'                           # zurückgehalten
```

Dann im Browser `/updates` öffnen und die drei Zahlen oben ablesen.

**Erwartet:** dreimal dieselbe Zahl wie auf der Kommandozeile.

**Und die Falle, die diesen Punkt am leichtesten falsch grün macht:** Der
Sicherheitszähler des Panels liest nicht `-security` im Text, sondern das Ziel
der Herkunft. Weichen die beiden Zahlen ab, ist **die Kommandozeile hier die
schlechtere Messung** — dann gehört die Liste selbst verglichen:

```bash
ssh cloudsrv24 'apt-get -s dist-upgrade | grep "^Inst "' > /tmp/inst.txt
```

**Zurück:** die drei Zahlen von beiden Seiten und, bei Abweichung, `/tmp/inst.txt`.

### Punkt 2 — Eine Neuinstallation ist als solche zu sehen (Kriterium 2)

Ein `dist-upgrade` bringt gelegentlich ein Paket mit, das es noch nicht gibt —
ein neuer Kernel-Metapaketstand etwa. Solche Zeilen tragen in der Simulation
**keine alte Fassung in Klammern**:

```bash
ssh cloudsrv24 'apt-get -s dist-upgrade | grep "^Inst " | grep -v "\["'
```

**Erwartet:** Jede Zeile, die hier steht, steht auf der Seite als **neu** und
nicht mit einer erfundenen alten Fassung.

**Kommt hier nichts zurück**, wird der Zustand hergestellt — sonst misst dieser
Punkt nichts:

```bash
ssh cloudsrv24 'sudo apt-get install -s cowsay | grep "^Inst "'
```

`cowsay` ist klein, hat keine Abhängigkeiten von Belang und ist auf keinem
Server dieses Projekts installiert. Die Zeile darf **nicht** installiert werden;
gemessen wird die Anzeige, und die Liste der Seite entsteht aus derselben
Simulation.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

### Punkt 2b — Eine Quelle aus- und wieder einschalten

Auf der Seite, im Bereich „Paketquellen", eine **eigene** Quelle abschalten (das
Panel lässt fremde nicht anfassen — `SourceOwnershipTest`), dann:

```bash
ssh cloudsrv24 'apt-get indextargets | grep -c "^Created-By"'
```

**Erwartet:** Die Zahl der Ziele fällt, die Quelle steht weiter in der Datei und
ist als **AUS** markiert. Danach wieder einschalten und die Zahl vergleichen.

**Zurück:** die Zahl vorher, aus, wieder ein — und der Inhalt der Datei in allen
drei Zuständen (`sudo cat` auf den Pfad, den die Seite nennt).

### Punkt 3 — Eine tote Quelle wird benannt (Kriterium 3, und das ist M5)

**Das ist der Punkt, aus dem der ganze Befund stammt.**

**Der Zustand ist nicht „die Quelle fehlt", sondern „die Quelle steht da und
antwortet nicht".** Eine umbenannte Datei erzeugt gar keine Meldung — apt kennt
sie dann einfach nicht, und der Punkt misst das Falsche. Umgebogen wird deshalb
die Adresse, und die Datei bleibt liegen.

> **Ein Prüfkörper, der einen anderen Zustand herstellt als den gemeinten,
> erreicht die Prüfung nicht.**

**Zuerst nachsehen, wie die Datei wirklich heisst** — auf `cloudsrv24` kann sie
`sury-php.list` heissen statt `.sources`, und die beiden Formate sehen innen
verschieden aus:

```bash
ssh cloudsrv24 'ls -l /etc/apt/sources.list.d/'
ssh cloudsrv24 'sudo cat /etc/apt/sources.list.d/<die Sury-Datei>'
```

**Der Name aus dieser Ausgabe** — nicht der hier geratene — geht in die beiden
nächsten Zeilen. Bei einer `.sources` (deb822) ist `URIs:` die Zeile, bei einer
`.list` steht die Adresse als drittes Wort:

```bash
# Sicherung zuerst, und in beiden Fällen dieselbe. Eine Datei mit der Endung
# `.abnahme` liest apt nicht — es nimmt nur `.list` und `.sources`.
ssh cloudsrv24 'sudo cp /etc/apt/sources.list.d/<datei> /etc/apt/sources.list.d/<datei>.abnahme'

# deb822 (.sources) — die Adresse steht hinter `URIs:`
ssh cloudsrv24 'sudo sed -i "s|^URIs:.*|URIs: https://gibtesnicht.invalid/php/|" \
                        /etc/apt/sources.list.d/<datei>'

# einzeilig (.list) — die Adresse ist das erste Wort, das mit https:// anfängt
ssh cloudsrv24 'sudo sed -i "s| https://[^ ]*| https://gibtesnicht.invalid/php/|" \
                        /etc/apt/sources.list.d/<datei>'
```

**Danach nachsehen, dass der Eingriff wirklich sitzt** — ein `sed`, dessen
Muster nicht passt, ändert nichts und meldet Erfolg:

```bash
ssh cloudsrv24 'sudo cat /etc/apt/sources.list.d/<datei>'
```

> **Ein Nachweis, dass der Eingriff wirkt, sagt nichts darüber, dass der
> Prüfkörper dort vorbeikommt** — und ohne ihn misst Punkt 3 einen Server, an
> dem nichts geschehen ist.

> **Eine Anweisung, die zuerst „nachsehen" sagt und danach den geratenen Wert
> einsetzt, hat das Nachsehen zur Verzierung gemacht.** Deshalb steht hier
> `<datei>` und kein Name.

**3a — die Kommandozeile**, damit der Ausgangswert bekannt ist:

```bash
ssh cloudsrv24 'sudo apt-get update >/tmp/out.txt 2>/tmp/err.txt; echo "rc=$?"; \
                echo "stdout: $(wc -c </tmp/out.txt) Bytes"; \
                echo "stderr: $(wc -c </tmp/err.txt) Bytes"; grep "^W:" /tmp/err.txt'
```

**Erwartet:** `rc=0` — das ist M5 — und mindestens eine `W:`-Zeile auf **stderr**,
die `gibtesnicht.invalid` nennt.

> **Eine Messung, die zwei Dinge zusammenwirft, belegt keines von beiden.** Die
> Kanäle sind hier getrennt, und zwar deshalb: Die erste Fassung dieser Messung
> schrieb `>datei 2>&1` und konnte nicht sagen, auf welchem Kanal die Zeilen
> standen.

**3b — die Seite.** `/updates` öffnen, „Jetzt nachsehen" drücken.

**Erwartet:** Die Quelle steht **mit ihrem Namen** als unerreichbar da, und der
Vorgang meldet nicht Erfolg. Ein Bild davon.

**3c — der Beleg, dass die Ursache und nicht der Zustand gemeldet wird.** Das
ist der eigentliche Punkt, und er geht über die Seite: unter „PHP" eine Fassung
installieren lassen, die es noch nicht gibt — der Weg, an dem der Befund
entdeckt wurde.

**Erwartet:** eine Meldung, die **die Quelle** nennt. **Nicht erwartet:**
*„Unable to locate package php8.4-fpm"* — das ist der Zustand richtig gemeldet
und die Ursache falsch, und genau dafür gibt es `Apt` seit Schritt 1.

> **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts gesagt —
> und der Leser sucht dort, wohin die Meldung zeigt.**

**3d — zurück:**

```bash
ssh cloudsrv24 'sudo mv /etc/apt/sources.list.d/<datei>.abnahme \
                        /etc/apt/sources.list.d/<datei> && sudo apt-get update; echo "rc=$?"'
```

**Erwartet:** `rc=0`, keine `W:`-Zeile mehr. **Diese Gegenprobe gehört zum
Punkt** — ohne sie ist nicht belegt, dass die Meldung am Zustand hing und nicht
am Server.

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

### Punkt 4 — Ein ablaufender Schlüssel wird gemeldet (Kriterium 4)

```bash
ssh cloudsrv24 'for k in /etc/apt/keyrings/* /usr/share/keyrings/*; do \
                  echo "== $k"; gpg --show-keys --with-colons "$k" 2>/dev/null \
                  | awk -F: "/^pub/ {print \$7}"; done'
```

**Erwartet:** Für jeden Schlüssel mit Ablaufdatum zeigt die Seite dasselbe
Datum, und einer, der in weniger als dreissig Tagen abläuft, ist hervorgehoben.

**Hat kein Schlüssel dieses Servers ein Ablaufdatum** — das ist der Normalfall
und war es auch im Container —, ist dieser Punkt **nicht messbar** und wird als
solcher protokolliert. Er wird **nicht** dadurch grün, dass nichts hervorgehoben
ist.

> **Ein Zustand, den die Umgebung nicht zulässt, wird nicht dadurch
> hergestellt, dass man nichts tut.**

### Punkt 5 — Ein Upgrade, das das Panel enthält (Kriterium 5)

**Der Punkt, der A1 zum Scheitern bringen kann.** Vorher:

```bash
ssh cloudsrv24 'apt-get -s dist-upgrade | grep "^Inst srvpanel "'
ssh cloudsrv24 'date -Is; sudo wc -c /var/log/srvpanel/upgrade.log 2>/dev/null || echo "noch keine"'
```

**Steht `srvpanel` nicht in der Liste**, wird der Zustand hergestellt: eine
Fassung zurücksetzen und das Depot nachziehen. Ohne `srvpanel` in der Liste
prüft dieser Punkt einen gewöhnlichen Lauf und nicht den, um den es geht.

**5a — auslösen.** Auf `/updates` „Alle einspielen" drücken. **Die Uhrzeit
notieren.**

**5b — während des Laufs**, aus einem *zweiten* Terminal:

```bash
ssh cloudsrv24 'systemctl list-units "srvpanel-update-*" --all'
ssh cloudsrv24 'sudo tail -f /var/log/srvpanel/upgrade.log'
```

**Erwartet:** genau eine transiente Unit, und das Protokoll wächst.

**5c — nach dem Lauf.** Uhrzeit notieren; die Differenz ist die Antwort auf
`docs/81 §2.3h` Punkt 1.

```bash
ssh cloudsrv24 'date -Is'
ssh cloudsrv24 'systemctl is-active srvpanel-web srvpanel-worker srvpanel-agentd'
ssh cloudsrv24 'sudo tail -20 /var/log/srvpanel/upgrade.log'
```

**Drei Dinge sind erwartet, und alle drei zählen:**

1. Das Panel ist erreichbar (Browser neu laden).
2. Die **letzte** Zeile des Protokolls ist die Bilanzzeile von `apt-run`:
   *„N von M Aktualisierungen eingespielt, K bleiben offen."* Sie ist der Beleg,
   dass der Lauf **nach** dem Neustart von `srvpanel-worker` weitergelaufen ist —
   und damit die Antwort auf `docs/81 §2.3h` Punkt 2.
3. Das Protokoll ist **vollständig** lesbar, auch der Teil vor dem Neustart. Zu
   sehen über die Seite: `/logs`, Quelle „Aktualisierungen installieren".

> **Ein Beleg für den Weg ist keiner für das Ziel.** Dass eine Unit abgesetzt
> wurde, sagt nichts darüber, dass sie zu Ende gelaufen ist. Die Bilanzzeile
> sagt es.

**5d — der Fall, in dem `apt-run` sein eigenes Urteil fällt.** Denselben Knopf
gleich noch einmal drücken. **Erwartet:** Der Lauf endet mit `3` und der Zeile
*„Der Lauf hat nichts verändert — offene Aktualisierungen vorher wie nachher: 0."*
Das ist M5 an der vierten Stelle, und es ist der Grund, dass dieses Skript
existiert.

### Punkt 6 — Eine Conffile steht mit ihrem Pfad da (Kriterium 6)

**Der Zustand muss hergestellt werden.** Eine Conffile entsteht, wenn ein
Paketbetreuer eine Datei ändert, die auch der Betreiber geändert hat; mit
`--force-confold` (Frage 3 aus `docs/81 §3`) legt dpkg die neue als `.dpkg-dist`
daneben.

**6a — herstellen**, ohne auf ein passendes Update zu warten:

```bash
ssh cloudsrv24 'sudo touch /etc/srvpanel/abnahme-a1.conf.dpkg-dist'
```

**Das ist ein Prüfkörper für die Anzeige und nicht für dpkg.** Was gemessen
wird, ist Kriterium 6 wörtlich: *„steht diese Datei mit ihrem Pfad auf der
Seite"*. Dass dpkg solche Dateien anlegt, ist in `docs/81 §2.1c` (M12) gemessen
und wird hier nicht noch einmal geprüft.

> **Eine Messung, die um ihren Gegenstand herumführt, hängt an Bedingungen, die
> mit ihm nichts zu tun haben.** Der Gegenstand ist hier die Anzeige.

**6b — messen.** `/updates` neu laden.

**Erwartet:** `/etc/srvpanel/abnahme-a1.conf.dpkg-dist` steht mit **vollem Pfad**
auf der Seite. Ein Bild davon, bei 390 px — ein Pfad ist eine Kennung, und
`docs/64` Befund 1 ist genau an einer Kennung entstanden.

**6c — zurück:**

```bash
ssh cloudsrv24 'sudo rm /etc/srvpanel/abnahme-a1.conf.dpkg-dist'
```

Und die Seite noch einmal laden: Der Eintrag ist fort.

### Punkt 7 — Neustart nötig, und der Knopf (Kriterium 7)

**7a — die drei Zustände.** Der wichtige ist der dritte:

```bash
ssh cloudsrv24 'ls -l /run/reboot-required /run/reboot-required.pkgs 2>&1'
ssh cloudsrv24 'dpkg-query -W -f="${db:Status-Status}\n" update-notifier-common 2>&1'
```

**Erwartet auf der Seite:**

| Zustand | Was dasteht |
|---|---|
| `/run/reboot-required` liegt | „Ein Neustart ist nötig", mit den Paketen aus `.pkgs` |
| liegt nicht, `update-notifier-common` installiert | „Kein Neustart nötig" |
| liegt nicht, Paket **fehlt** | „nicht nachgesehen" — und **nicht** „nein" |

Der dritte ist der, um den es geht. Fehlt das Paket, weiss niemand etwas, und
eine Anzeige, die daraus „nein" macht, behauptet etwas.

> **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
> tun".**

**Herstellen**, falls nach Punkt 5 keine Datei liegt:

```bash
ssh cloudsrv24 'sudo touch /run/reboot-required'
ssh cloudsrv24 'echo srvpanel | sudo tee /run/reboot-required.pkgs'
```

**7b — der Knopf.** Auf der Seite den Neustart anstossen.

**Erwartet:** Ein Bestätigungsfeld, das den **Rechnernamen** verlangt. Zuerst
absichtlich etwas Falsches eingeben — der Knopf bleibt gesperrt. Dann den
richtigen Namen; er steht auf derselben Seite.

**7c — und dann nicht bestätigen.** Der Lauf endet hier: Ein Neustart von
`cloudsrv24` prüft `systemd-run --on-active=60` und sonst nichts, und dass die
Verzögerung greift, ist in `docs/81 §2.3f` belegt.

**Wer ihn doch fahren will** — er ist der ehrlichste Beleg für Kriterium 7 —,
fährt ihn als **letzten** Punkt des Laufs und misst dabei:

```bash
ssh cloudsrv24 'systemctl list-timers srvpanel-reboot --all'
ssh cloudsrv24 'sudo systemctl stop srvpanel-reboot.timer'   # der Rückweg innerhalb der 60 s
```

> **Ein Merkmal, das aussperren kann, braucht seinen Rückweg.**

**7d — aufräumen**, wenn 7a die Dateien selbst angelegt hat:

```bash
ssh cloudsrv24 'sudo rm -f /run/reboot-required /run/reboot-required.pkgs'
```

### Punkt 8 — Ein zweiter Lauf wird abgewiesen (Kriterium 8)

**8a — die Sperre von aussen.** Ein langer apt-Lauf im einen Terminal:

```bash
ssh cloudsrv24 'sudo apt-get -s -o Debug::NoLocking=0 dist-upgrade >/dev/null; \
                sudo flock /var/lib/dpkg/lock-frontend sleep 90'
```

Währenddessen im Browser „Alle einspielen" drücken.

**Erwartet:** eine Meldung über den **laufenden Vorgang** — nicht die von dpkg
(*„Could not get lock /var/lib/dpkg/lock-frontend"*). Ein Bild davon.

**8b — die Sperre von innen**, und das ist der Fall, den `AptLock` seit Schritt
2 abdeckt: Während der Lauf aus Punkt 5 noch läuft, denselben Knopf ein zweites
Mal drücken.

**Erwartet:** dieselbe Meldung, und **keine** zweite Unit:

```bash
ssh cloudsrv24 'systemctl list-units "srvpanel-update-*" --all'
```

> **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
> anderen Vorgänge derselben Reihe nicht.** Deshalb fragt `AptLock` die Sperre
> selbst über `/proc/locks` und nicht die eigene Vorgangstabelle.

### Punkt 9 — Die unbeaufsichtigten Updates (Schritt 8, kein Kriterium in §4)

**9a — der Zustand vor dem Schalten**, und zwar aus **apts** Sicht und nicht aus
unserer Datei:

```bash
ssh cloudsrv24 'apt-config dump | grep -i "APT::Periodic"'
ssh cloudsrv24 'ls -l /etc/apt/apt.conf.d/ | grep -i "auto-upgrade\|srvpanel\|periodic"'
ssh cloudsrv24 'dpkg-query -W -f="${db:Status-Status}\n" unattended-upgrades 2>&1'
ssh cloudsrv24 'systemctl list-timers "apt-daily*" --all'
```

**9b — schalten.** Auf der Seite die Automatik einschalten, dann `9a` wiederholen.

**Erwartet:** `APT::Periodic::Unattended-Upgrade` steht auf `1`, unsere Datei
`/etc/apt/apt.conf.d/zz-srvpanel-unattended` ist da, **und die Seite zeigt den
Zustand, den `apt-config dump` zeigt** — nicht den unserer Datei.

**Und der Fall, für den die Nachlesung gebaut ist:** Setzt eine andere Datei den
Hauptschalter auf `0`, muss die Operation **scheitern** und die Datei benennen,
die es tut. Herstellen:

```bash
ssh cloudsrv24 'echo "APT::Periodic::Enable \"0\";" | \
                sudo tee /etc/apt/apt.conf.d/zzz-abnahme-a1'
```

Danach auf der Seite einschalten. **Erwartet:** ein Fehlschlag mit dem Namen
`zzz-abnahme-a1` — und nicht ein Erfolg, dem nichts folgt.

> **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen Zustand.**

**9c — zurück:**

```bash
ssh cloudsrv24 'sudo rm -f /etc/apt/apt.conf.d/zzz-abnahme-a1'
```

Und die Automatik auf den Stand zurückstellen, den `9a` gezeigt hat.

### Punkt 10 — `apt-run panel` vergleicht die Fassung (`docs/81 §2.3h` Punkt 3)

Im Container ist nur der Fehlerweg gemessen (`rc=100`, *„Fassung: vorher
unbekannt, jetzt unbekannt"*), weil `srvpanel` dort nicht installiert ist.

```bash
ssh cloudsrv24 'sudo /usr/lib/srvpanel/apt-run panel; echo "rc=$?"'
```

**Erwartet, je nachdem, ob eine neue Fassung im Depot liegt:**

- eine gibt es: *„Fassung `<alt>` wurde zu `<neu>`"*, `rc=0`
- keine: *„Der Lauf hat nichts verändert — Fassung vorher wie nachher: `<x>`"*,
  `rc=3`

**Beide Ausgänge sind ein Ergebnis.** Was **kein** Ergebnis ist: `rc=0` ohne eine
Zeile über die Fassung — dann trägt der Rückgabewert wieder allein, und M5 wäre
still zurück.

**Kommt Punkt 5 vor diesem hier**, ist die Fassung schon aktuell, und gemessen
wird der zweite Ausgang. Das genügt: Gefragt ist, ob **verglichen** wird.

### Punkt 11 — Bilder bei 390 und 1440 px

Nach `tests/bilder-messen.js`, in **beiden** Themes, gegen die echte Seite mit
echten Daten:

- `/updates` — die Zahlen oben, die Paketliste, die Quellenliste, die Automatik
- `/updates` mit der Conffile aus Punkt 6
- `/updates` mit der toten Quelle aus Punkt 3
- der Bestätigungsdialog aus Punkt 7b
- `/logs` mit der Quelle „Aktualisierungen installieren" nach Punkt 5

**Gemessen wird `schiebt`, `rollt` **und** `stand`** — das letzte, weil es bei
jedem Neuladen aus der Zwischenablage zurückkommt und genau deshalb im
Messmittel steht.

> **Wer ein Messmittel kürzt, kürzt zuerst das Feld weg, das vor der alten
> Messung schützt.**

**Die Gegenprobe je Lage gehört dazu**: ein Prüfkörper von `scrollWidth + 200`,
der in allen vier Lagen mit `200/200` ausschlägt. Ohne sie bedeutet eine `0`
nichts.

**Der Blick auf das Bild ersetzt die Zahl nicht, und die Zahl nicht das Bild.**
Die Paketliste ist eine lange Tabelle mit Fassungsnummern, und Fassungsnummern
sind Kennungen.

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

### Punkt 12 — Aufräumen, und das gehört zum Lauf

```bash
ssh cloudsrv24 'ls -l /etc/apt/sources.list.d/'          # keine .abnahme mehr
ssh cloudsrv24 'ls -l /etc/apt/apt.conf.d/ | grep abnahme'   # nichts
ssh cloudsrv24 'ls -l /etc/srvpanel/*.dpkg-dist 2>&1'    # nichts
ssh cloudsrv24 'ls -l /run/reboot-required* 2>&1'        # nur, was Punkt 5 hinterlassen hat
ssh cloudsrv24 'sudo apt-get update; echo "rc=$?"'       # 0, keine W:-Zeile
```

**Zurück:** alle fünf Ausgaben. Ein Lauf, der seinen Prüfkörper stehen lässt,
vergiftet den nächsten — dieselbe Regel wie im Bruchskript.

---

## 4. Was zurückkommen soll

Für jeden Punkt:

- die **Kommandozeilen-Ausgabe wörtlich**, auch wenn sie richtig aussieht;
- bei Anzeigefragen ein **Bild**, in beiden Themes und bei 390 px;
- bei jeder Zahl die **Gegenzahl**, gegen die sie geprüft wurde;
- bei jedem Punkt, der nicht messbar war, **warum** — und nicht ein Haken.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 5. Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Rollenteilung aus `docs/81 §3` Frage 2.** Die Updates-Seite gehört
  ganz dem Betreiber; dass der Administrator Zahlen und Liste sehen darf, ist
  **nicht gebaut** und steht als benannter Widerspruch in `docs/81 §2.3h`. Ein Lauf kann das nicht abnehmen, weil es das Merkmal nicht gibt.
- **Ein Distributions-Upgrade** (`do-release-upgrade`) — `docs/81 §10`.
- **Das Zurückrollen eines Upgrades.** apt kann es nicht ehrlich.
- **Das Hinzufügen einer fremden Paketquelle** — Frage 1, entschieden.
- **Die vier apt-Fälle, die im Container nicht vorkamen** und die der CI-Job
  `apt-messrunde` inzwischen abdeckt. Wer sie hier noch einmal misst, misst die
  CI.
- **Ob `unattended-upgrades` wirklich läuft.** Gemessen wird der *Zustand*, den
  apt meldet, nicht ein nächtlicher Lauf — dafür bräuchte es eine Nacht.

---

## 6. Wann A1 abgenommen ist

**Wenn die acht Punkte aus `docs/81 §4` gemessen sind und stimmen** — nicht,
wenn sie plausibel sind.

Zwei Punkte dürfen dabei als **nicht messbar** protokolliert werden, ohne die
Abnahme zu verhindern, und zwar nur diese:

- **Punkt 4**, wenn kein Schlüssel dieses Servers ein Ablaufdatum trägt. Dann
  fehlt der Zustand, nicht die Anzeige.
- **Punkt 2**, wenn das `dist-upgrade` keine Neuinstallation enthält **und** der
  Ersatzprüfkörper aus 2b denselben Weg nimmt.

**Punkt 5 darf nicht ausfallen.** Er ist der einzige, der A1 zum Scheitern
bringen kann, und ohne ihn ist die zentrale Zusage dieser Stufe unbelegt.

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

---

## 7. Was nach diesem Lauf zu bauen bleibt

- **Die Rollenteilung** aus `docs/81 §3` Frage 2 — ein eigener Schritt mit
  eigenen Wächtern: die Seite dem Administrator öffnen, dabei das
  Schlüsselmaterial aus der Quellenliste nehmen und die drei Installierknöpfe
  hinter der Fähigkeit verstecken, die er nicht hat.
- **A3, A4 und A7** — Firewall, Fail2ban, Schwellen. Sie stehen in `docs/20 §9`
  unter P7b als „hat noch keine Stufe".
- **Die Befunde dieses Laufs**, in der Reihenfolge ihrer Dringlichkeit. Sie
  stehen im Protokoll und nicht hier.
