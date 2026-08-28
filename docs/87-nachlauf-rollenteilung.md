# Der Nachlauf zu `0.7.2-rc.5` — sechs Dinge, die kein Server gesehen hat

**Angelegt am 28. August 2026, vor dem Lauf.** Das ist keine Formalie: Dieses
Projekt hat in `docs/45`, `docs/48`, `docs/59` und `docs/84` mehr Fehler im
**Prüfmittel** gefunden als im Prüfling, und der Grund war jedes Mal ein Lauf,
der beim Fahren entstand.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

---

## 0. Was dieser Lauf ist und was er nicht ist

**A1 ist abgenommen** (`docs/86 §6`). Das hier ist **keine** zweite Abnahme,
sondern ein Nachlauf über sechs Dinge, die zwischen der Abnahme und
`0.7.2-rc.5` gebaut wurden und **auf keinem Server standen**.

**Warum er trotzdem wiegt.** Zwei der Änderungen liegen auf dem Weg von allem:

| Datei | liegt auf dem Weg von |
|---|---|
| `agent/src/Runner.php` | **jeder** Operation dieses Panels |
| `app/Jobs/RunAgentOperation.php` | **jedem** Vorgang |

Der Ausgabeweg jedes Programms ist umgebaut und die Erfolgsmeldung jedes
Vorgangs verzweigt. Was daran falsch ist, ist nicht an A1 falsch.

**Stand:** `0.7.2-rc.5` ist am 28. August über `srvpanel update` eingespielt;
`apt-run` meldete *„Fassung 0.7.2~rc.4 wurde zu 0.7.2~rc.5."*, und
`srvpanel vhost --sites` ist gefahren (vier Kundendomains eingereiht, zwei ohne
Zertifikat).

---

## 1. Was vorher dasteht

Ein zweites Adminkonto mit der Rolle **Administrator** wird gebraucht. Falls es
das auf `cloudsrv24` noch nicht gibt, entsteht es über die Kontenverwaltung im
Panel — nicht über `srvpanel admin`, denn das legt einen Betreiber an.

**Notiert wird vor dem ersten Punkt:**

    srvpanel version
    systemctl is-active srvpanel-web srvpanel-worker srvpanel-agentd
    ls -l /var/log/srvpanel/upgrade.log        # Groesse — sie ist der Versatz

**Die Weboberfläche heisst `srvpanel-web`.** Hier stand bis zum 28. August
`srvpanel`, und das ist keine Unit dieses Systems — `systemctl is-active`
antwortet darauf mit `inactive` statt mit einem Fehler, und das liest sich wie
ein abgeschalteter Dienst. Der Lauf hat genau das gemeldet, während das Panel
im Browser daneben stand.

> **Ein Werkzeug, das nach einem Ding gefragt wird, das es nicht gibt,
> antwortet trotzdem — und seine Antwort sieht aus wie ein Befund.**

---

## 2. Punkt 1 — Der Administrator sieht die Updates-Seite

**Gemessen wird als Administrator**, angemeldet im Browser.

1. `/updates` öffnet sich (kein 403).
2. Oben steht der Satz: *„Sie sehen den Stand dieses Servers. Ändern … ist dem
   Betreiber vorbehalten."*
3. Die **Quellentabelle** hat vier Spalten: Datei, Zustand, Adresse, Suiten.
   Kein „Schlüssel", kein „Fingerabdruck", kein „Schalten".
4. Die **Paketliste** hat vier Spalten — **kein Kästchen** in der Kopfzeile und
   keines je Zeile.
5. Die Knöpfe: „Jetzt nachsehen" ist da. „Alle installieren", „n ausgewählte
   installieren", „Server neu starten" und der Automatikschalter sind **nicht**
   da.

**Die Gegenprobe gehört dazu und ist nicht optional.** Dieselbe Seite als
Betreiber: **sieben** Spalten in der Quellentabelle (die vier von oben plus
Schlüssel, Fingerabdruck, Schalten), **fünf** in der Paketliste (die vier plus
das Kästchen), und die vier Knöpfe samt Schalter.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.** „Drei Spalten weniger" sagt nichts, solange niemand gemessen hat,
> wie viele es vorher waren.

**Die ganze Paketsektion hängt am Bestand, und das entscheidet über vier der
sieben Erwartungen.** Berichtigt am 28. August, nachdem der Lauf sie umgeworfen
hat: Der erste Wurf nahm nur „Nur Sicherheit installieren" aus (er hängt
zusätzlich an `packages.security > 0`). Über ihm steht aber

    <p v-if="props.packages.upgradable.length === 0">Es steht keine Aktualisierung an.</p>
    <template v-else> … die drei Knöpfe und die Paketliste … </template>

— bei `aktualisierbar 0` sind **Tabelle und alle drei Installierknöpfe** in
beiden Ansichten fort, und der Unterschied ist an dieser Stelle nicht
vorhanden.

> **Eine Ausnahme, die man für einen Fall aufschreibt, ist falsch gefasst, wenn
> dieselbe Bedingung über dem ganzen Abschnitt steht.**

**Vor dem Lauf wird deshalb der Bestand gelesen** — die Kachel „Aktualisierbar".
Steht sie auf 0, sind die Erwartungen 4 und der Knopfteil von 5 **nicht
herstellbar** und werden als solche notiert, statt als erfüllt zu gelten. Die
schlüssigen bleiben: der Rollensatz, die Quellentabelle, der Neustartknopf und
der Automatikschalter — die drei letzten sitzen in Abschnitten, die in beiden
Ansichten stehen, ihr Fehlen ist also ein Unterschied und kein leerer
Abschnitt.

**Und die Tür, nicht nur die Anzeige.** Gemessen wird aus der Browserkonsole der
angemeldeten Sitzung — damit geht die Anfrage durch das echte nginx, die echte
Mittelschicht und die echte Sitzung, und nicht an ihnen vorbei.

    POST /updates/install     → 403
    PUT  /updates/sources     → 403
    PUT  /updates/unattended  → 403
    POST /server/reboot       → 403
    POST /updates/refresh     → **nicht** 403

Der letzte ist der wichtige: Er ist die einzige Handlung, die der Administrator
auf dieser Seite hat, und ein Aufräumen, das ihn versehentlich mitnimmt, fällt
sonst niemandem auf.

**Der Rumpf ist leer, und das ist keine Bequemlichkeit.** Am Quelltext
nachgesehen, bevor der Prüfkörper geschrieben wurde: Alle vier gesperrten
Handlungen rufen `validate()` als erstes und verlangen Pflichtfelder —
`mode`, `path`/`stanza`/`enabled`, `enabled`, und `hostname` sogar gegen den
echten Rechnernamen. Ein leerer Rumpf kann also nichts auslösen:

| | bedeutet |
|---|---|
| **403** | die Tür hat gehalten |
| **302** (`0 (opaqueredirect)`) | die Tür stand offen, und die Prüfung hat zurückgeleitet |

**Keiner der beiden fasst den Server an.** Das ist der Grund, warum
`POST /server/reboot` überhaupt gemessen werden darf: Wäre die Reihenfolge
umgekehrt, prüfte dieser Punkt einen Neustart der Produktivmaschine.

**Hier stand bis zum 28. August „422", und das war falsch.** Gemessen wurde
`302` — `bootstrap/app.php` setzt
`shouldRenderJsonWhen(fn ($request) => $request->is('api/*'))`, und damit gibt
es JSON ausserhalb von `api/*` nicht, gleich was im `Accept` steht. Ein
`ValidationException` leitet dort zurück. Der `403` bleibt einer, weil er ein
Status ist und kein Format.

> **Eine Kopfzeile, die ein Format erbittet, entscheidet nichts, wenn die
> Anwendung das Format an den Pfad gebunden hat.**

**Und daraus folgt eine Grenze dieses Prüfkörpers, die man kennen muss:** Ein
`0 (opaqueredirect)` unterscheidet nicht zwischen „die Prüfung hat
zurückgeleitet" und „es ist durchgelaufen und hat weitergeleitet". Für die vier
gesperrten Handlungen trennt das die Pflichtfeldprüfung; für
`POST /updates/refresh` trennt es nichts — dort ist der **Vorgang in
`/operations`** der Beleg und nicht der Statuscode.

> **Ein Prüfkörper, der im Fehlerfall Schaden anrichtet, ist keiner — man fährt
> ihn nicht, und dann ist der Punkt ungemessen.**

**`POST /updates/refresh` ist der Sonderfall und nimmt nichts entgegen.** Es
setzt sofort einen Vorgang ab. Für diese Route heisst „durchgelassen" deshalb
wörtlich „hat einen Vorgang angelegt" — anders ist ihre Erreichbarkeit nicht zu
belegen (`docs/62`, Punkt 11).

**Die Gegenprobe ist derselbe Lauf als Betreiber**, und sie ist nicht optional.
Ein Fehlschlag der CSRF-Prüfung gäbe **419** auf alle fünf — und 419 ist nicht
403, läse sich also als „vier Türen stehen offen". Nur wenn derselbe Lauf als
Betreiber **kein einziges** 403 liefert, hat der Prüfkörper die Mittelschicht
überhaupt erreicht.

> **Ein Prüfkörper, der aus dem falschen Grund scheitert, meldet den Prüfling
> für etwas, das er zu Recht tut.**

**Kein `X-Inertia` im Kopf.** Es erzeugt bei abweichendem Baustand einen **409**,
und zwar **vor** der Policy — der Punkt wäre dann ungemessen und sähe aus wie
ein Ergebnis (`docs/62`, Punkt 11).

---

## 3. Punkt 2 — Der Vorbehalt steht in der Liste

**Hergestellt** wird der Zustand, indem eine Quelle unerreichbar gemacht wird —
derselbe Griff wie in `docs/86` Punkt 3: die Sury-Zeile auf eine tote Adresse
zeigen lassen, `apt-get update` erzwingen, danach zurückstellen.

1. Als Betreiber „Jetzt nachsehen" drücken.
2. **Auf der Vorgangsliste `/operations`**, ohne den Vorgang zu öffnen: Neben
   der Aufgabe steht eine bernsteinfarbene Marke mit *„Nicht erreicht: …"* und
   dem Namen der Quelle.
3. Der Zustand daneben ist **`fertig`** und nicht `fehlgeschlagen` — das ist
   die Entscheidung des Betreibers vom 28. August und kein Versehen.

> **Ein Lauf, der getan hat, worum man ihn bat, ist gelungen — auch wenn er
> dabei etwas zu melden hat.**

**Gegenprobe:** Nach dem Zurückstellen dieselbe Handlung — die Marke ist fort.
Ohne sie belegt Punkt 2 nur, dass irgendwo eine Marke steht.

---

## 4. Punkt 3 — Ein abgesetzter Lauf meldet nicht sofort „fertig"

**Der Kern dieses Nachlaufs.** Bis `rc.4` stand der Vorgang auf `fertig`,
während `apt-get` noch lief.

1. Vor dem Drücken: `ls -l /var/log/srvpanel/upgrade.log` — die Grösse notieren.
2. Als Betreiber „Alle installieren" drücken (oder „Nur Sicherheit", falls der
   Bestand das hergibt).
3. **Sofort danach** auf der Vorgangsseite: Der Zustand ist **`läuft`**, nicht
   `fertig`. Kein „beendet"-Zeitpunkt.
4. `systemctl list-units 'srvpanel-update-*'` zeigt die Unit.
5. **Warten.** Alle fünfzehn Sekunden sieht die Nachlese nach.
6. Wenn die Unit fort ist: Der Vorgang steht auf `fertig` **oder**
   `fehlgeschlagen`, und im Ergebnis steht `verdict` mit genau der Zeile, die
   `apt-run` geschrieben hat.

**Und die drei Zahlen, die `docs/81 §2.3h` Punkt 1 seit dem 26. August offen
lässt:**

| | woher |
|---|---|
| Zahl der Pakete | die Kachel „Aktualisierbar" vor dem Lauf |
| Absetzen → erste Logzeile | `upgrade.log`, Änderungszeit gegen den Beginn |
| Absetzen → Urteilszeile | `finished_at` des Vorgangs minus `started_at` |

Sie sind der Grund, warum `AwaitDispatchedRun::DEADLINE` auf zwei Stunden steht.

> **Eine Frist, die man nicht gemessen hat, wird lang gewählt und nicht
> plausibel.**

**Falls der Bestand klein ist**, ist das kein Ausfall des Punktes — die Nachlese
wird trotzdem gemessen, nur die Dauer nicht. Dann bleibt Punkt 1 aus §2.3h
weiter offen, und das gehört so notiert und nicht überspielt.

**Und eine Gegenprobe, die nichts extra kostet: Befund 6 aus `docs/86`.** Die
Kachel „Aktualisierbar" und der Lauf müssen dieselbe Zahl meinen. Notiert wird
die Kachel **vor** dem Drücken und die Urteilszeile danach — sie nennt beide
Zahlen (*„N von M Aktualisierungen eingespielt, K bleiben offen"*).

| | heisst |
|---|---|
| M gleich der Kachel, K = 0 | zwei Messungen an **einem** Ort |
| M kleiner als die Kachel | die Seite verspricht mehr, als der Lauf einspielt — Befund 6 ist zurück |

> **Zwei Läufe desselben Befehls an zwei Orten sind zwei Messungen und nicht
> eine.**

---

## 5. Punkt 4 — Der Lauf, der nichts bewirkt, wird als solcher gemeldet

Direkt nach Punkt 3 noch einmal „Alle installieren". Jetzt ist nichts mehr offen.

Erwartet: `apt-run` endet mit 3 und schreibt *„Der Lauf hat nichts verändert
— …"*; die Nachlese liest das als **Fehlschlag**, und der Vorgang steht auf
`fehlgeschlagen` mit genau dieser Zeile als Begründung.

**Das ist der Punkt, an dem `rc.4` „fertig" gemeldet hat** (`docs/86`, Punkt 5d).

---

## 6. Punkt 5 — Das `W:` steht heil da

Auf der Vorgangsseite eines Laufs, dessen Ausgabe eine `W:`-Zeile enthält —
Punkt 2 stellt so eine her, wenn eine Quelle einen schwachen Schlüssel hat.

Erwartet: `W: https://…` steht **in einer Zeile**, nicht als `W` allein mit
`: …` darunter.

**Und die Zahl daneben**, weil ein Bild allein die Frage nicht beantwortet:

    grep -c '^W:' <ausgabe>     gegen     grep -c '^:' <ausgabe>

Der zweite muss 0 sein. Eine Ausgabe, in der er 0 ist, weil gar keine `W:`-Zeile
vorkommt, belegt nichts — dann ist der Prüfkörper falsch.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 7. Punkt 6 — Die Zählwörter

Zwei Meldungen, beide über die Oberfläche:

1. **Eine einzelne Datei** hochladen, deren Name schon vergeben ist, sodass sie
   scheitert → *„Von **1 Datei** ist 0 hochgeladen."* und nicht „1 Dateien".
2. Ein Archiv mit **einem** Eintrag entpacken → *„Das Archiv ist entpackt —
   **1 Eintrag**."*

---

## 8. Punkt 7 — Was das Update selbst schon belegt hat

Nichts zu tun; hier nur festgehalten, damit es nicht zweimal gemessen wird:

- `apt-run panel` hat **beide** Ausgänge jetzt auf einem echten Server gezeigt
  (`docs/86`, Punkt 10).
- Die transiente Unit hat den Neustart des Panels überlebt und ihr Urteil
  danach geschrieben — der Weg, auf den sich die Nachlese aus Punkt 3 stützt.

---

## 9. Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Bilder.** Die Ansicht des Administrators ist im Container bei 390 und
  1440 px gemessen (`docs/81 §2.3n`), und der Aufsatz trifft dort aufs Pixel
  (`docs/56` Punkt 5). Ein zweiter Bildersatz auf dem Server misst dasselbe
  zweimal.
- **Die Rollenteilung anderer Seiten.** Es gibt keine — `/updates` ist die
  einzige Seite mit zwei Lesern.
- **Form B ausserhalb von `refresh`.** Nur diese eine Operation meldet heute
  einen Vorbehalt.
- **Befund 14** (die Fusszeile von `/logs`) — der gehört zu A5.

---

## 10. Wann der Nachlauf durch ist

Wenn die Punkte 1 bis 6 gemessen sind und jeder Befund entweder behoben oder
**benannt** dasteht.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

Das Protokoll bekommt die nächste freie Nummer; hier steht sie bewusst nicht.
Der erste Wurf dieses Abschnitts hat sie genannt — in einem Satz, der erklärte,
warum man sie nicht nennt. `DocLinkTest` war sofort rot, und zwar zu Recht: Eine
Nummer, die noch keinem Dokument gehört, ist von einer, die einem falschen
gehört, nicht zu unterscheiden.

> **Ein Satz, der eine Regel erklärt, ist kein Beleg, dass man sie befolgt
> hat.**
