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
    systemctl is-active srvpanel srvpanel-worker srvpanel-agentd
    ls -l /var/log/srvpanel/upgrade.log        # Groesse — sie ist der Versatz

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

**„Nur Sicherheit installieren" steht bewusst nicht in dieser Liste.** Er hängt
zusätzlich an `props.packages.security > 0` — ist gerade nichts an Sicherheit
offen, fehlt er **beiden** Rollen, und sein Fehlen beim Administrator belegt
dann nichts. Steht die Kachel „Sicherheit" über 0, gehört er dazu und wird
mitgezählt: dann sind es fünf Knöpfe.

> **Ein Bedienelement, das an zwei Bedingungen hängt, belegt die eine nur,
> solange die andere erfüllt ist.**

**Und die Tür, nicht nur die Anzeige.** Als Administrator, über die
Kommandozeile mit seiner Sitzung — oder ersatzweise durch Aufruf der Adresse:

    POST /updates/install     → 403
    PUT  /updates/sources     → 403
    PUT  /updates/unattended  → 403
    POST /server/reboot       → 403
    POST /updates/refresh     → **nicht** 403

Der letzte ist der wichtige: Er ist die einzige Handlung, die der Administrator
auf dieser Seite hat, und ein Aufräumen, das ihn versehentlich mitnimmt, fällt
sonst niemandem auf.

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
