# A11 — der Abnahmelauf für die Zeit des Servers

Ausgeschrieben am **7. September 2026**, vor dem Fahren, gegen `0.7.3-rc.24` auf
`cloudsrv24`. Der Plan ist `docs/106`, die Messrunde davor `docs/81 §2.3r`.

**Dieses Dokument wird vor dem Lauf gelesen und nicht währenddessen.** Was beim
Ausschreiben am Quelltext aufgefallen ist, steht in §0 — ein Kriterium ist dabei
umgefallen.

---

## 0 · Was beim Ausschreiben schon umgefallen ist

**Punkt 6 stand da als „ein Administrator bekommt 403, der Betreiber nicht".
Das kann der Prüfling nicht erfüllen, und zwar zu Recht.**

`/settings/general` trägt `can:manage-settings`, und diese Fähigkeit gehört seit
A9 dem **Administrator**; die Route steht mit ausgeschriebener Begründung in
`AdminAbility::administratorRoutes()`. Die Überschrift von `docs/106 §2`
Entscheidung 1 lautete „Nur der Betreiber" und stammte aus der Zeit vor A9, als
beide Adminfähigkeiten auf `isAdmin()` auflösten. Der Satz darunter — „bleibt
ganz an `manage-settings`" — war die Entscheidung und ist richtig geblieben.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

> **Zwei Zeilen desselben Absatzes über dieselbe Frage laufen auseinander, und
> keine von beiden ist der Ort, an dem man nachsieht.**

Punkt 6 misst deshalb, was die Tür wirklich zusagt: Der Administrator **sieht**
den Bereich, ein Konto **ohne** Adminrolle bekommt 403.

**Und die Begründung in der Ausnahmeliste war zu schmal.** Sie nannte nur „Die
Anzeigezeitzone des Panels" — die Seite zeigt seit A11 auch Zone, Zeitabgleich,
Hardware-Uhr und Rechnername des Servers. Wer später fragt, ob die Seite noch
dem Administrator gehören darf, liest genau diesen Satz.

> **Eine Begründung, die weniger nennt als die Seite zeigt, ist beim nächsten
> Feld die Begründung für etwas anderes.**

---

## 0b · Der Vorflug — der Zustand, der hinterher wieder dastehen muss

**Zuerst und ohne Ausnahme.** Punkt 3 schaltet den Zeitabgleich ab, Punkt 4
maskiert einen Dienst. Beides muss zurück, und „zurück" ist nur belegbar, wenn
vorher jemand hingesehen hat.

    timedatectl show
    readlink /etc/localtime
    systemctl is-enabled systemd-timesyncd 2>&1
    systemctl is-active systemd-timesyncd 2>&1
    systemctl status systemd-timedated --no-pager 2>&1 | head -3

**Aufschreiben, was dasteht.** Am Ende steht dieselbe Ausgabe von
`timedatectl show` — Zeile für Zeile, ausser `TimeUSec`.

---

## 1 · Der Bereich steht da

**Ansehen:** `/settings/general`, Bereich **„Zeit des Servers"** hinter
„Anzeigezeit".

**Erwartet:** sechs Zeilen. Die Zone mit **Name und Beschriftung** —
`Europe/Berlin — CEST (UTC+02:00)` oder `Etc/UTC — UTC`, je nachdem, was
`readlink /etc/localtime` im Vorflug gesagt hat.

**Gegenprobe am Server**, und die ist der Kern dieses Punktes:

    srvpanel tinker --execute='echo App\Support\Cron\ServerZone::known(), "\n";'

Der Wert muss **derselbe** sein wie in der Zeile der Seite. Und dieselbe Zone
schreibt die Cronseite an einen Zeitplan — das ist der Befund dieser Stufe von
der Seite des Betreibers aus: Es gibt **eine** Antwort auf „in welcher Zone
steht dieser Server" und nicht zwei.

---

## 2 · Die Brücke

**Ansehen:** die beiden letzten Zeilen — „Jetzt auf dem Server" und „Dasselbe in
der Anzeigezeit".

**Erwartet:** Sie unterscheiden sich um genau den Versatz zwischen Serverzone
und Anzeigezone, und **beide sind auf die Minute genau gesetzt** — keine
Sekunden auf der einen und keine auf der anderen.

**Wenn beide Zonen gleich sind, zeigen sie dieselbe Zahl.** Dann ist der Punkt
nicht gemessen: Er sähe bei einer fehlenden Umrechnung genauso aus.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

In diesem Fall die Anzeigezone kurz auf eine andere stellen (im Feld darüber),
messen, zurückstellen — und das Zurückstellen belegen.

---

## 3 · NTP in seinen Zuständen · **Ausschluss**

    timedatectl set-ntp false && timedatectl show -p NTP -p CanNTP
    # Seite neu laden, Zeile „Zeitabgleich" ablesen
    timedatectl set-ntp true && timedatectl show -p NTP -p CanNTP
    # Seite neu laden, Zeile „Zeitabgleich" ablesen

**Erwartet:** `ausgeschaltet` und `eingeschaltet` — zwei **verschiedene** Sätze,
und keiner von beiden ist „nicht feststellbar".

**Der dritte Zustand — „kein Zeitdienst installiert" — darf ausfallen.** Er
hiesse, `systemd-timesyncd` von einem laufenden Server zu entfernen; das ist ein
Eingriff und keine Messung. Fällt er aus, wird er **benannt** und nicht
übergangen.

**Danebengeschaut:** die Zeile „Uhr abgeglichen". Sie hängt **nicht** am Dienst
— `NTPSynchronized` ist ein eigenes Feld. Läuft der Dienst und steht dort
trotzdem `nein`, ist das eine Auskunft und kein Fehler der Seite.

---

## 4 · Nicht feststellbar · **Ausschluss**

**Der Weg ist ungemessen und wird zuerst geprüft, nicht ausgeführt.** Im
Container scheiterte `systemctl` an seinem Bus, während `timedatectl` trug —
dort war der Weg also gar nicht prüfbar.

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

Erst nachsehen, ob der Weg überhaupt wirkt:

    systemctl mask systemd-timedated
    systemctl stop systemd-timedated 2>&1
    timedatectl show; echo "rc=$?"

**Bedingung, damit der Punkt gefahren werden darf:** `rc != 0`. Antwortet
`timedatectl` weiterhin mit `rc=0`, ist der Weg keiner — dann sofort
`systemctl unmask systemd-timedated` und der Punkt fällt als **nicht
herstellbar** aus, mit dem gemessenen `rc` daneben.

Wirkt er, die Seite neu laden.

**Erwartet:** Zeitabgleich, Uhr abgeglichen, Hardware-Uhr und Zeitzone stehen
auf **`nicht feststellbar`** — und **nicht** auf `ausgeschaltet`, `nein` oder
`UTC`. Das ist der ganze Punkt: Ein geratener Wert sieht aus wie ein gemessener.

**Ausnahme, die erwartet wird:** „Jetzt auf dem Server" und „Dasselbe in der
Anzeigezeit" bleiben stehen. Die Zone kommt aus `ServerZone` und nicht aus
`timedatectl`; sie ist von dessen Ausfall nicht betroffen. Stünde dort
ebenfalls „nicht feststellbar", wäre das ein Befund.

**Danach unbedingt:**

    systemctl unmask systemd-timedated
    timedatectl show; echo "rc=$?"

---

## 5 · Der Rechnername

**Ansehen:** Bereich „Adressen dieses Servers", Zeile **Rechnername**.

    srvpanel tinker --execute='echo var_export(SrvPanel\Agent\Names::fqdn(), true), " | ", SrvPanel\Agent\Names::host(), "\n";'

**Erwartet:** Steht ein vollständiger Name da, zeigt die Seite ihn; gibt
`fqdn()` `NULL`, zeigt sie den kurzen. Im Container war `NULL` der gemessene
Fall — auf einem eingerichteten Server ist er es vermutlich nicht, und dann ist
dieser Punkt der triviale von beiden.

**Und der Satz darunter** — dass der Name sich hier nicht ändern lässt, weil er
im Zertifikat, in den vhosts und im DNS-Abgleich steckt — steht sichtbar da und
nicht nur im Kommentar.

---

## 6 · Die Tür

**Berichtigt, siehe §0.**

- Ein Konto mit der Rolle **Administrator** ruft `/settings/general` auf:
  **200**, der Bereich ist da.
- Ein Konto **ohne** Adminrolle (ein Kunde): **403**.

Gibt es kein Administratorkonto, wird eines angelegt oder der Punkt fällt als
nicht herstellbar aus — dann aber **benannt**.

---

## 7 · 390 px

Die Bilderrunde im Container steht in `docs/106 §8b` und ist dort mit
`dokument = 0`, Gegenprobe 200/200 gemessen. Hier wird sie am **echten** Server
mit **echten** Werten wiederholt, weil der Container die Zone stellen musste,
um die längste Form zu bekommen.

Im Browser bei 390 px, `tests/bilder-messen.js` in die Konsole, dann
`bilderMessen()` — **einmal je frisch geladener Seite**, das Werkzeug weist den
zweiten Aufruf ab.

**Erwartet:** `dokument = 0`, `gegenprobe = 200 (soll 200)`.

`rollt` darf 0 sein: Ein Roller taucht nur auf, wenn er auch überläuft — das war
die Erwartung, die der A14-Lauf berichtigt hat.

---

## 8 · Kosten

    for i in 1 2 3 4 5; do
      /usr/bin/time -f %e timedatectl show >/dev/null
    done

**Erwartet:** in der Grössenordnung der Messrunde (dort 10–12 ms). Und die Seite
lädt nicht spürbar langsamer als vorher — der Griff ist ein Verschluss und läuft
nur, wenn die Seite ihn schickt.

> **Eine Messung, die man nur einmal fährt, misst den Zwischenspeicher mit.**

---

## 9 · Was dieser Lauf ausdrücklich **nicht** prüft

- **`NTPSynchronized=yes`** als hergestellten Zustand. Ob die Uhr wirklich
  stimmt, hängt an einem erreichbaren Zeitserver und nicht am Panel.
- **Den Zustand „kein Zeitdienst installiert"** — siehe Punkt 3.
- **`set-timezone`.** Gibt es nicht, soll es nicht geben (`docs/106 §9`).
- **Die Bestandsdiagnose.** A10 ist abgenommen; ein neuer `check` dort wäre eine
  Änderung an einer abgenommenen Stufe und braucht ihren eigenen Nachlauf.

---

## 10 · Wann er durch ist

**Alle acht Punkte erfüllt**, und **3 und 4 dürfen nicht ausfallen**. Fällt
einer der übrigen als „nicht herstellbar" aus, wird er benannt.

Ins Protokoll gehören:

- die **Fassung**, gegen die gemessen wurde,
- je Punkt der **gemessene** Wert und nicht „erfüllt",
- ob der Weg aus §4 getragen hat und mit welchem `rc`,
- der Beleg, dass der Prüfstand abgeräumt ist: `timedatectl show` zeigt
  dieselben Zeilen wie im Vorflug,
- und was danach **offen bleibt**.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
