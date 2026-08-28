# Protokoll des Nachlaufs zu `0.7.2-rc.5`

Gefahren auf `cloudsrv24` am **28. August 2026** gegen `0.7.2-rc.5`. Der Lauf
ist `docs/87`. Er ist **keine** zweite Abnahme von A1 (`docs/86 §6`), sondern
ein Nachsehen über sechs Dinge, die zwischen der Abnahme und dieser Fassung
gebaut wurden und auf keinem Server standen.

---

## 0. Was vorher dastand

    srvpanel version                        0.7.2-rc.5
    systemctl is-active srvpanel-worker     active
    systemctl is-active srvpanel-agentd     active
    ls -l /var/log/srvpanel/upgrade.log     0 Bytes, 28. Aug 00:00

Der Versatz für Punkt 3 ist damit **0** — die Datei ist um Mitternacht rotiert
worden.

**Der Stand des Servers:** aktualisierbar 0, davon Sicherheit 0, davon neu 0,
zurückgehalten 2 (`libproc2-0`, `procps` — Ubuntus stufenweise Ausspielung),
würde entfernt 0. Dazu zwei Konfigurationsdateien, die auf eine Entscheidung
warten (`/etc/default/grub.ucf-dist`, `/etc/ssh/sshd_config.ucf-dist`) — die
Spur der `--force-confold`-Läufe vom 27. und 28. August.

---

## 1. Punkt 1a — Der Administrator sieht die Updates-Seite

**Fünf Erwartungen gemessen, vier davon schlüssig, zwei nicht herstellbar.**

Gemessen als **Zweite Verwaltung** (Rolle Administrator, zweiter Faktor
eingerichtet), Gegenprobe als **Administrator** (das Konto mit der Rolle
Betreiber).

| | Administrator | Betreiber | Urteil |
|---|---|---|---|
| `/updates` öffnet | ja, kein 403 | ja | **erfüllt** |
| Der Rollensatz oben | steht | — | **erfüllt** |
| Quellentabelle | **4** Spalten | **7** Spalten | **erfüllt** |
| „Server neu starten" | fehlt | steht | **erfüllt** |
| Automatikschalter | fehlt | steht | **erfüllt** |
| Paketliste, Spalten | — | — | **nicht herstellbar** |
| „Alle installieren", „n ausgewählte" | — | — | **nicht herstellbar** |

Der Satz steht wörtlich so, wie der Lauf ihn verlangt: *„Sie sehen den Stand
dieses Servers. Ändern — installieren, Quellen schalten, die Automatik
umstellen, neu starten — ist dem Betreiber vorbehalten."*

Die vier Spalten des Administrators sind Datei, Zustand, Adresse, Suiten; die
drei zusätzlichen des Betreibers sind Schlüssel, Fingerabdruck, Schalten.

**Die beiden Knöpfe sind schlüssig gemessen, und das ist nicht selbstverständlich.**
Beide sitzen in einem Abschnitt, der in **beiden** Ansichten steht — über dem
Neustartknopf steht in beiden *„Kein Neustart nötig"*, über dem Schalter in
beiden *„Es wird täglich unbeaufsichtigt installiert."* samt der Tabelle
darunter. Ihr Fehlen ist deshalb ein Unterschied und kein leerer Abschnitt.

---

## 2. Befund 1 — `systemctl is-active srvpanel` fragt nach einer Unit, die es nicht gibt

`docs/87 §1` liess vor dem ersten Punkt dies notieren:

    systemctl is-active srvpanel srvpanel-worker srvpanel-agentd

Die Antwort war `inactive`, `active`, `active` — und das erste `inactive` sieht
aus wie ein abgeschalteter Dienst. **Es gibt keine `srvpanel.service`.**
Ausgezählt über `packaging/systemd/` und `nfpm.yaml`: `srvpanel-agentd`,
`srvpanel-web`, `srvpanel-worker`, `srvpanel-metrics`, `srvpanel-usage`,
`srvpanel-tls`, `srvpanel-cron`, `srvpanel-dns` — und keine ohne Zusatz. Die
Weboberfläche läuft als **`srvpanel-web`**; über `grep` im ganzen Repo kommt
`srvpanel.service` null mal vor.

`systemctl is-active` beantwortet eine Frage nach einer unbekannten Unit nicht
mit einem Fehler, sondern mit `inactive`.

> **Ein Werkzeug, das nach einem Ding gefragt wird, das es nicht gibt,
> antwortet trotzdem — und seine Antwort sieht aus wie ein Befund.**

Das ist dieselbe Familie wie M5 an drei Stellen (`docs/81 §2.1a`, `docs/86`):
Eine Auskunft, die „nicht nachgesehen" bedeutet, ist von „nichts zu tun" nicht
zu unterscheiden. Hier ist es die Umkehrung — „gibt es nicht" liest sich wie
„läuft nicht".

**Der Befund steckt im Prüfmittel und nicht im Prüfling.** Das Panel lief die
ganze Zeit; der Browser daneben zeigte es.

---

## 3. Befund 2 — Die Bestandsabhängigkeit galt einem Knopf und gilt dem ganzen Abschnitt

`docs/87 §2` nimmt „Nur Sicherheit installieren" ausdrücklich aus der Zählung,
weil er zusätzlich an `packages.security > 0` hängt und dann **beiden** Rollen
fehlt. Der Satz stimmt und war zu eng gefasst. Am Quelltext nachgesehen:

    <p v-if="props.packages.upgradable.length === 0" class="empty">
      Es steht keine Aktualisierung an.
    </p>
    <template v-else>
      <div v-if="darfSchalten" class="button-row"> … </div>
      … die Paketliste …
    </template>

**Die ganze Paketsektion hängt am Bestand** — die Tabelle mitsamt ihrer
Kästchenspalte und alle drei Installierknöpfe. Bei `aktualisierbar 0` steht in
beiden Ansichten dieselbe eine Zeile, und der Unterschied, den Punkt 1 messen
soll, ist an dieser Stelle gar nicht vorhanden.

> **Eine Ausnahme, die man für einen Fall aufschreibt, ist falsch gefasst, wenn
> dieselbe Bedingung über dem ganzen Abschnitt steht.**

Ich hatte die Bedingung am einzelnen Knopf gelesen und nicht nachgesehen, was
über ihm steht.

---

## 4. Beobachtung 1 — Die zwei Schlüsselmeldungen verschwinden ohne eigenen Wächter

Der Betreiber liest über der Quellentabelle *„5 Signaturschlüssel liessen sich
nicht lesen — das ist etwas anderes, als hätte die Quelle keinen: …"*. Beim
Administrator steht sie nicht.

**Sie ist nicht durch ein `v-if` verborgen.** `unlesbar` filtert auf
`e.key !== null && !e.key.readable`, `faellig` läuft über `eintrag.key?.keys ?? []`
— mit dem `key = null`, das `withoutKeys()` setzt, laufen beide leer.

**Das ist die richtige Bauart und keine Nachlässigkeit.** Ein
`v-if="darfSchalten"` an diesen beiden Meldungen wäre eine zweite Fassung
derselben Regel, und die zweite ist die, die beim Umbau stehenbleibt. Der
Filter ist die eine Stelle, und `SourceKeyFilterTest` hält ihn — samt der
Richtung, dass kein neues Feld zum Agenten kommt, ohne dass jemand entscheidet,
ob der Administrator es sehen darf.

Notiert wird es trotzdem, weil die Form der aus A9 bekannten Falle gleicht:
*Eine Sicherheit, die aus einer Eigenschaft der Daten folgt und nicht aus einer
Prüfung, hält genau so lange, bis jemand die Daten ändert.* Der Unterschied ist,
dass die Eigenschaft hier von einem geprüften Filter hergestellt wird und nicht
zufällig besteht.

---

## 5. Beobachtung 2 — Der Lauf davor hat den Bestand geleert, den dieser Lauf braucht

Am 27. August standen auf diesem Server **141** aktualisierbare Pakete; der
Abnahmelauf von A1 und das Update auf `0.7.2-rc.5` haben sie eingespielt. Heute
sind es 0.

Daran hängt mehr als Punkt 1: **Punkt 3** (ein abgesetzter Lauf meldet nicht
sofort „fertig") und **Punkt 4** (der Lauf, der nichts bewirkt) brauchen beide
den Knopf „Alle installieren", und den gibt es bei leerem Bestand nicht. Auch
die drei Laufzeitzahlen aus `docs/81 §2.3h` Punkt 1 sind so nicht zu messen.

> **Ein Lauf, der einen Bestand braucht, wird von dem Lauf davor geleert.**

Die Automatik arbeitet dagegen: *Zuletzt unbeaufsichtigt installiert
2026-08-28 06:10:10*, täglich. Warten stellt den Zustand also nicht verlässlich
her — es verbraucht ihn.

---

## 6. Punkt 1b — Die Tür

**Erfüllt.** Gemessen aus der Browserkonsole der angemeldeten Sitzung, durch das
echte nginx, mit leerem Rumpf.

| | Betreiber (Gegenprobe) | Administrator |
|---|---|---|
| `POST /updates/install` | `0 (opaqueredirect)` | **403** |
| `PUT /updates/sources` | `0 (opaqueredirect)` | **403** |
| `PUT /updates/unattended` | `0 (opaqueredirect)` | **403** |
| `POST /server/reboot` | `0 (opaqueredirect)` | **403** |
| `POST /updates/refresh` | `0 (opaqueredirect)` | `0 (opaqueredirect)` |

**Die Gegenprobe trägt.** Kein einziges 403 beim Betreiber — der Prüfkörper hat
die Mittelschicht also erreicht und die CSRF-Prüfung bestanden. Wäre sie
gescheitert, stünden dort fünfmal 419, und 419 ist nicht 403: Der Lauf daneben
läse sich als „vier Türen stehen offen".

**Der Server ist nicht neu gestartet**, und das ist der Beleg für die
Sicherheitsüberlegung aus `docs/87 §2`: `hostname` ist Pflichtfeld und wird
gegen den echten Namen geprüft, bevor irgendetwas abgesetzt wird.

---

## 7. Befund 3 — Der zweite Ausgang ist 302 und nicht 422

`docs/87 §2` führte eine Tabelle: 403 heisst „Tür hielt", **422** heisst „Tür
offen, Prüfung hat aufgefangen". Gemessen wurde kein einziges 422, sondern
durchweg `0 (opaqueredirect)` — also 3xx.

Der Grund steht in `bootstrap/app.php`:

    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*'),
    );

**JSON gibt es in dieser Anwendung nur unter `api/*`.** Ausserhalb davon nimmt
ein `ValidationException` den HTML-Weg und leitet zurück, gleich was der
Aufrufer im `Accept` erbittet. Der `403` bleibt einer, weil er ein Status ist
und kein Format — deshalb hat das Kriterium trotzdem funktioniert.

> **Eine Kopfzeile, die ein Format erbittet, entscheidet nichts, wenn die
> Anwendung das Format an den Pfad gebunden hat.**

**Die Grenze, die daraus folgt, wiegt mehr als der falsche Wert.** Ein
`0 (opaqueredirect)` unterscheidet nicht zwischen „die Prüfung hat
zurückgeleitet" und „es ist durchgelaufen und hat weitergeleitet". Für die vier
gesperrten Handlungen trennt das die Pflichtfeldprüfung. Für
`POST /updates/refresh` trennt es **nichts** — und genau dort behauptet der Lauf
etwas.

---

## 8. Beobachtung 3 — Die Gegenprobe hat den Bestand hergestellt, der fehlte

Zwischen den beiden Läufen ist die Kachel **„Aktualisierbar" von 0 auf 15
gesprungen**, und in der Paketliste stehen fünfzehn `php8.4-*` aus
`PPA for PHP:24.04/noble`, installiert `8.4.24-1+ubuntu24.04.1+deb.sury.org+1`,
neu `8.4.25-…`.

Dazwischen lag genau eine Handlung, die apts Listen anfassen kann: das
`POST /updates/refresh` der Gegenprobe. Sury hat in der Zwischenzeit 8.4.25
veröffentlicht, und der Vorgang hat es geholt.

**Das ist zugleich der einzige verlässliche Beleg, dass `refresh` durchgelassen
wurde** — der Statuscode kann es nach Befund 3 nicht sein. Für eine Route, die
nichts entgegennimmt, heisst „durchgelassen" wörtlich „hat einen Vorgang
angelegt" (`docs/62`, Punkt 11); nachzusehen ist das in `/operations`, und dort
gehören **zwei** Vorgänge `system.packages.refresh` zu stehen — einer je Lauf,
der zweite auf das Konto *Zweite Verwaltung*. Erst der zweite belegt die Zeile
„**nicht** 403" für den Administrator.

**Damit sind die beiden nicht herstellbaren Erwartungen aus §1 wieder
herstellbar** — und die Hälfte des Administrators ist im selben Bild schon
gemessen: Die Paketliste zeigt ihm **vier** Spalten (Paket, Installiert, Neu,
Herkunft), **kein Kästchen** in der Kopfzeile, **keines je Zeile**, und über dem
Filter steht **keine Knopfreihe**. Was fehlt, ist die Gegenprobe des Betreibers
bei fünfzehn Paketen.

> **Eine Gelegenheit, die man nicht herstellen kann, stellt sich manchmal von
> selbst her — und dann misst man sie, statt auf die geplante zu warten.**

---

## 9. Punkt 1a ist vollständig — die Gegenprobe bei siebzehn Paketen

Nachgeholt, nachdem §8 den Bestand hergestellt hatte.

| | Administrator | Betreiber |
|---|---|---|
| Paketliste, Spalten | **4** (Paket, Installiert, Neu, Herkunft) | **5** (dazu das Kästchen) |
| Kästchen in der Kopfzeile | nein | ja |
| Kästchen je Zeile | nein | ja |
| Knopfreihe über dem Filter | keine | „Alle installieren", „0 ausgewählte installieren" |

**„Nur Sicherheit installieren" fehlt beiden**, und zwar zu Recht: `davon
Sicherheit` steht auf 0. Das ist genau der Fall, den `docs/87 §2` von der
Zählung ausnimmt — sein Fehlen beim Administrator belegt hier nichts, und
deshalb steht er nicht in der Tabelle.

**Damit ist Punkt 1 erfüllt**, alle sieben Erwartungen gemessen.

---

## 10. Punkt 1b ist vollständig — der Vorgang, den der Statuscode nicht liefern konnte

Im Verlauf von `/operations`:

| Nummer | Aufgabe | Zustand | Ausgelöst von | Begonnen | Beendet |
|---|---|---|---|---|---|
| **719** | `system.packages.refresh` | fertig | **Zweite Verwaltung** | 20:18:35 | 20:18:36 |
| **718** | `system.packages.refresh` | fertig | Administrator | 20:16:46 | 20:16:51 |

**719 ist der Beleg.** „Nicht 403" sagt nur, dass die Tür nicht zugeschlagen
hat; für eine Route ohne Rumpf heisst durchgelassen wörtlich „hat einen Vorgang
angelegt", und dieser trägt den Namen des Administratorkontos.

Die Laufzeiten sind nebenbei stimmig: 718 brauchte **fünf** Sekunden und hat die
neuen Indizes geholt, 719 brauchte **eine** und fand sie frisch vor.

---

## 11. Beobachtung 4 — Das „Vorher" der Familie steht im selben Verlauf

| Nummer | Aufgabe | Zustand | Ausgelöst von | Begonnen | Beendet |
|---|---|---|---|---|---|
| **707** | `system.packages.upgrade` | **fertig** | Administrator | 2026-08-27 20:59:39 | 20:59:40 |

**Ein abgesetzter Upgrade-Lauf, `fertig` nach einer Sekunde.** Das ist Form A
aus `docs/86 §5` im Betrieb, auf diesem Server, aus der Fassung vor der
Behebung — und es steht schon da, ohne dass jemand es herstellen musste.

Punkt 3 erzeugt das „Nachher" in derselben Liste. Die beiden Zeilen werden
wenige Nummern auseinanderliegen und dieselbe Aufgabe tragen; der Unterschied
ist dann keine Erzählung, sondern zwei Zeilen untereinander.

> **Ein Vorher, das man nicht herstellen muss, weil es schon dasteht, ist der
> beste Prüfkörper — es kann nicht auf den Fall zugeschnitten sein.**

---

## 12. Beobachtung 5 — Der Bestand hat sich noch einmal bewegt

Zwischen den beiden Aufrufen der Seite:

| | vorher | nachher |
|---|---|---|
| aktualisierbar | 15 | **17** |
| zurückgehalten | 2 | **0** |
| die Phasenmeldung | stand | **fort** |

`libproc2-0` und `procps` sind von „zurückgehalten" nach „aktualisierbar"
gewandert und stehen jetzt mit `Ubuntu:24.04/noble-updates` in der Liste.

**Zwei Erklärungen kommen in Frage, und eine davon wäre ein Rückfall.** Ubuntus
stufenweise Ausspielung kann diese Maschine erreicht haben — oder die Simulation
lief an einem Ort ohne Phasing, und das wäre **Befund 6 aus `docs/86`** zurück:
Die Seite verspräche dann mehr, als `apt-run all` einspielt.

**Am Quelltext nachgesehen spricht der Mechanismus dagegen.**
`Apt::simulate()` ruft

    systemd-run --quiet --pipe --wait --collect … apt-run simulate

— also dieselbe transiente Unit, in der auch `apt-run all` läuft. Der
Namensraum, der `ischroot` rc=0 melden liess, liegt in diesem Weg nicht.

**Punkt 3 entscheidet es umsonst.** Sagt die Seite 17 und spielt der Lauf 17
ein, waren es zwei Messungen an einem Ort. Bleiben zwei offen, ist die Seite
wieder der falsche Ort.

> **Zwei Läufe desselben Befehls an zwei Orten sind zwei Messungen und nicht
> eine** — und ob es diesmal einer war, sagt der Vergleich der beiden Zahlen
> und nicht der Blick in den Quelltext.

---

## 13. Beobachtung 6 — Zwei Zertifikatsbestellungen stehen auf `fehlgeschlagen`

| Nummer | Aufgabe | Zustand | Begonnen |
|---|---|---|---|
| 717 | `acme.certificate.issue` | **fehlgeschlagen** | 2026-08-28 09:33:34 |
| 716 | `acme.certificate.issue` | **fehlgeschlagen** | 2026-08-28 09:33:33 |

Sie folgen unmittelbar auf die acht Vorgänge (708–715) des
`srvpanel vhost --sites` von heute morgen — vier Paare aus `php.pool.apply` und
`web.site.apply`, also die vier Kundendomains. Zwei davon haben kein Zertifikat
bekommen.

**Das liegt ausserhalb dieses Laufs** (`docs/87 §9` nennt TLS nicht), und es
gehört trotzdem notiert: Zwei Kundendomains laufen seit heute morgen ohne
Zertifikat, und das überlebt diesen Nachlauf. Wer als nächstes TLS anfasst,
fängt hier an.

---

## 14. Punkt 3 — Die Nachlese trägt, und drei Dinge daneben nicht

**Die Nachlese ist belegt.** Vorgang **720**, `system.packages.upgrade`, Modus
`all`, ausgelöst vom Betreiber:

| | |
|---|---|
| Begonnen | 2026-08-28 **21:36:33** |
| Zustand um 21:36 | **`läuft`**, Beendet **`—`** |
| Beendet | 2026-08-28 **21:37:06** |
| Laufzeit | **33 Sekunden** |
| Pakete | **17** |

**Das ist das Nachher zu Vorgang 707** (§11), zwei Zeilen weiter in derselben
Liste: dort `fertig` nach einer Sekunde, hier `läuft` über dreiunddreissig und
`fertig` erst danach. Die transiente Unit hat ihr Urteil geschrieben, und
`AwaitDispatchedRun` hat es gelesen.

**Und die erste gemessene Laufzeit für `docs/81 §2.3h` Punkt 1:** 33 Sekunden
für 17 Pakete. Das ist nicht die Zahl für 142, aber es ist eine, wo vorher keine
stand — und sie macht `AwaitDispatchedRun::DEADLINE` von zwei Stunden zu einer
grosszügigen und nicht zu einer geratenen Frist.

---

## 15. Befund 4 — Der Vorgang endet mit der Meldung „läuft"

Auf der Seite von Vorgang 720 steht **in beiden Aufnahmen** derselbe grüne
Kasten mit dem Wort **„läuft"** — auch um 21:37, als Zustand und Marke schon
`fertig` sagen.

Die Kette, am Quelltext ausgezählt:

    SystemPackagesUpgrade.php:176   $context->progress(100, 'läuft');
    OperationRecorder::dispatched() fasst `message` nicht an
    OperationRecorder::succeed()    ruft finish(…, message: null)
    OperationRecorder::finish()     'message' => $message === null
                                        ? $this->operation->message : …

**Ein `null` bedeutet dort „lass die alte stehen".** Die alte ist das Wort, mit
dem der Agent den Lauf abgesetzt hat. Der Vorgang endet also mit einer grünen
Meldung, die das Gegenteil seines Zustands behauptet.

> **Eine Meldung, die den Zustand von vorhin trägt, widerspricht der Marke
> daneben — und der Leser glaubt der Meldung, weil sie aus Worten besteht.**

**Der Fehler ist älter als die Behebung und wird erst durch sie sichtbar.**
Vorgang 707 trägt dieselbe Meldung; dort fiel sie nicht auf, weil der Zustand
eine Sekunde später ohnehin falsch war.

---

## 16. Befund 5 — „Fortschritt 100 %" steht neben „läuft"

Um 21:36, während der Vorgang lief, stand der Balken auf **voll** und darunter
*„Fortschritt 100 %"*.

**Und das ist genau der Wert, den `dispatched()` bewusst nicht setzt.** In
seinem Dokumentblock steht:

> **Kein `finished_at` und kein `progress: 100`.** Beide behaupteten ein Ende;
> der Fortschritt bleibt, wo der Agent ihn gelassen hat.

Der Agent hat ihn bei **100** gelassen — `progress(100, 'läuft')`, dieselbe
Zeile wie in Befund 4. Die Entscheidung, ihn nicht zu setzen, läuft ins Leere.

> **Ein Wert, den man bewusst nicht setzt, ist trotzdem gesetzt, wenn ihn vorher
> jemand anders gesetzt hat.**

Der Kommentar nennt die Bedingung — *„wo der Agent ihn gelassen hat"* — und
niemand hat nachgesehen, wo das ist.

---

## 17. Befund 6 — Das Urteil steht im Ergebnis und auf keiner Seite

`AwaitDispatchedRun` legt das Urteil ab:

    $recorder->succeed([...$ergebnis, 'verdict' => $urteil]);

`Operations/Show.vue` rendert `message`, `progress`, die Argumente und die
Ausgabe — **`result` nicht**. Die Zeile, die `apt-run` geschrieben hat und
derentwegen die ganze Nachlese gebaut wurde, ist damit im Payload und auf keiner
Seite. Die Ausgabe daneben sagt *„Keine Ausgabe."*, und das stimmt: Ein
abgesetzter Lauf schreibt in `upgrade.log` und nicht in den Vorgang.

> **Ein Feld im Payload ist noch keine Spalte.**

**Zum dritten Mal, und zum zweiten Mal in diesem Merkmal.** Genau dieser Satz
steht in `docs/86 §5` über Form B — dort hat die Behebung der Vorgangs**liste**
eine Marke gegeben. Die Detailseite hat niemand angesehen.

> **Ein Fehler, den man an einer Stelle behoben hat, ist an der nächsten wieder
> da, wenn die Behebung nicht die Regel wurde.**

**Und die Asymmetrie ist der eigentliche Befund.** Im Fehlerfall ruft die
Nachlese `fail($urteil, …)`, und `fail()` reicht das Urteil als **Meldung**
durch — dort steht es also. Im Erfolgsfall ruft sie `succeed()` mit `null`, und
dann steht es nirgends.

> **Ein Urteil, das nur im Fehlerfall sichtbar wird, ist keine Auskunft über den
> Ausgang — es ist eine Fehlermeldung.**

**Das trifft die Gegenprobe aus §12.** Die Urteilszeile sollte Kachel und Lauf
vergleichen (*„N von M eingespielt, K bleiben offen"*); vom Panel aus ist sie
nicht lesbar. Bis Befund 6 behoben ist, geht das nur über
`cat /var/log/srvpanel/upgrade.log`.

---

## 18. Nicht entschieden — die transiente Unit war nie zu sehen

`systemctl list-units 'srvpanel-update-*'` meldete dreimal
`0 loaded units listed.`

**Das ist kein Befund und kein Beleg.** `--collect` räumt die Unit ab, sobald
sie fertig ist; nach 21:37:06 ist sie zu Recht fort. Ob die drei Aufrufe
innerhalb der dreiunddreissig Sekunden lagen, geht aus der Aufnahme nicht
hervor — sie trägt keine Uhrzeit.

> **Ein leerer Treffer ohne Zeitstempel beantwortet eine Frage nach einem
> Zeitraum nicht.**

Entscheiden würde es ein Aufruf **während** eines Laufs, mit `date` davor. Der
Weg steht ohnehin schon: Das Urteil in `upgrade.log` kann nur die Unit
geschrieben haben.

---

## 19. Wo der Lauf steht

| Punkt | Stand |
|---|---|
| 1a — der Administrator sieht die Seite | **erfüllt**, alle sieben Erwartungen (§1, §9) |
| 1b — die Tür | **erfüllt**, samt Vorgangsbeleg (§10) |
| 2 — der Vorbehalt in der Liste | offen |
| 3 — die Nachlese | **erfüllt** (§14) — mit drei Befunden daneben |
| 4 — der Lauf ohne Wirkung | folgt unmittelbar auf 3 |
| 5 — das heile `W:` | offen |
| 6 — die Zählwörter | offen |

**Sechs Befunde: drei im Prüfmittel, drei im Panel.** Die ersten drei sassen in
der Vorschrift, die letzten drei auf einer einzigen Seite — der des Vorgangs.

**Sie sind eine Familie und keine drei Einzelfälle**, und die Familie ist
dieselbe wie in `docs/86 §5`: **Was der Vorgang über seinen Ausgang sagt, sagt
er falsch oder gar nicht.** Der Zustand stimmt seit dem 28. August; die Meldung
daneben trägt das Wort von vorhin (Befund 4), der Balken die Zahl von vorhin
(Befund 5), und das Urteil, das beide ersetzen würde, wird nicht gerendert
(Befund 6).

> **Eine Behebung, die den Zustand richtig macht, hat über die Anzeige daneben
> nichts gesagt.**

**Und zwei Dinge, die dieser Lauf nicht bestellt hat, aber gefunden hat:** das
unhergestellte Vorher der Familie (§11) und zwei Kundendomains ohne Zertifikat
(§13).

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
