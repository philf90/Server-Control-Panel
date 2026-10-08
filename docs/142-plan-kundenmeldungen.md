# B9 — Zertifikat und Sicherung an den Kunden: der Plan

Geschrieben am 7. Oktober 2026, **nach** dem Nachlesen am Quelltext und drei
Rechnungen im Container, **vor** jeder Zeile Code. Die Rechnungen stehen als
`tests/kundenmeldungen-rechnen.php` im Repo; jede Zahl in §3 stammt aus ihrem
Lauf. Entschieden ist der Auftrag. Die vier Fragen, an denen der Bau hing,
stehen in §6, und **der Betreiber hat sie am selben Tag entschieden: 1b, 2a,
3a, 4a.** Gebaut ist B9 ebenfalls am 7. Oktober; was dabei anders lief als in
§4, steht in §10. Der Entwurf darunter bleibt, wie er vor den Antworten
geschrieben war, und trägt an den betroffenen Stellen einen Vermerk.

## §1 · Warum es diesen Plan gibt

`docs/20 §9` führt unter P9 *„Benachrichtigungen an Kunden: Kontingent
erreicht, Zertifikat läuft ab, Sicherung fehlgeschlagen"*. B5 hat davon die
Kontingente gebaut, und sein Kriterium in `docs/129 §9` fragt nach nichts
anderem; abgenommen ist er am 7. Oktober 2026 (`docs/141 §7`). Die beiden
übrigen Meldungen hat niemand gebaut.

**Entschieden hat der Betreiber am selben Tag**, nach der Regel vom
27. September: *„Macht es Sinn und gibt es einen spürbaren Mehrwert für einen
Nutzer im Panel? Wenn das nicht erfüllt ist, wird es nicht gebaut."*

| | |
|---|---|
| Zertifikat läuft ab, Sicherung fehlgeschlagen | **werden gebaut** — als eigenes Merkmal B9 mit eigenem Abnahmelauf, weil B5 abgenommen ist |
| Dokumentation: Betreiberhandbuch, Kundenhilfe in der Oberfläche | **vertagt** und als offener Punkt für später stehengelassen |
| danach | **B7 und B8** |

**B9 ist ein Name und keine Reihenfolge.** Es kommt vor B7 und B8, weil der
Betreiber es so entschieden hat, und nicht, weil die Nummer es sagt.

## §2 · Was beim Nachlesen umgefallen ist

### Befund 1 · Eine gescheiterte Sicherung erfährt niemand, auch der Betreiber nicht

`docs/129 §4` führt „Sicherung fehlgeschlagen" unter den Auslösern von B1, mit
`Checks\Backups` als Quelle. **Diese Prüfung sieht gescheiterte Sicherungen mit
Absicht nicht an.** Sie fragt nur Zeilen mit `status = ready`
(`Backups::ready()`), und ihr Kopf begründet es: *„Eine gescheiterte ist
bekanntermassen keine, und die Seite sagt es bereits."* `backup.file` urteilt
über die Bytes eines fertigen Archivs und nicht über einen Lauf.

Ausgezählt über `app/`: `BackupStatus::Failed` wird an **einer** Stelle
geschrieben, in `BackupLifecycle::afterFailure()`, und an **keiner** gelesen.
Die Unit bleibt dabei grün. `srvpanel:backups` reiht die Sicherungen nur ein
(`RunAgentOperation::dispatch()`), und sein Rückgabewert spricht mit Absicht
über den Lauf und nicht über den Bestand (Kopf von `RunBackups`). Was es gibt,
ist die Zeile auf der Seite der Sicherungen mit „fehlgeschlagen" und dem Grund,
und der Vorgang unter „Vorgänge". Wer nicht hinsieht, erfährt nichts.

> **Ein Auslöser, dessen Quelle niemand nachgelesen hat, steht in einer
> Tabelle und nirgends sonst.** Es ist die zweite Zeile derselben Tabelle nach
> „Platte voll" (`docs/136 §1`).

### Befund 2 · Etwa jede zwölfte gelungene Erneuerung meldet ein ablaufendes Zertifikat

`Certificates::EXPIRING_DAYS` und `CertificateRenewal::LEAD_DAYS` stehen beide
auf 30. Die Diagnose nennt ein Zertifikat von Let's Encrypt also genau in dem
Augenblick „läuft demnächst ab", in dem seine Erneuerung fällig wird. Beide
Läufe starten jede Nacht mit einer Stunde Streuung, und die wird jede Nacht neu
gewürfelt (`docs/909`). Fällt der Zeitpunkt in einer Nacht zwischen die
Erneuerung und die Diagnose, und läuft in der Nacht danach die Diagnose vor der
Erneuerung, steht der Befund in zwei Läufen hintereinander. Genau unter dieser
Bedingung meldet B1.

Gerechnet mit den echten Funktionen (§3, M1) trifft das **8,4 bis 9,6 % aller
gelungenen Erneuerungen**, je nachdem, wie lange die Ausstellung dauert. Heute
geht diese Meldung per Mail an den Betreiber und über den Webhook. In der Nacht
darauf ist der Befund fort, und nur der Webhook entwarnt. **Mit einer
Kundenmail ginge sie an den Kunden**, und zwar über ein Zertifikat, das das
Panel in derselben Nacht erneuert hat.

> **Eine Warnung, die am selben Tag anschlägt wie ihre Abhilfe, meldet jedes
> Mal, wenn ihr Zeitgeber zuerst läuft.**

Liegt die Schwelle zwei Tage hinter der Erneuerung, gemeldet ab 28 Tagen
Restlaufzeit, meldet keine gelungene Erneuerung mehr etwas. Eine Erneuerung,
die nie gelingt, wird weiterhin in jedem Versuch gemeldet (die Gegenprobe in
M1).

### Befund 3 · Die Gründe eines Zertifikats schliessen einander aus

`Certificates::file()` gibt **einen** Grund zurück, in der Reihenfolge fehlt,
abgelaufen, falscher Name, läuft ab (§3, M2). Zwei Folgen davon treffen B9:

- **Beim Ablauf löst `expired` den Befund `expiring` ab.**
  `FindingLog::forgetMissing()` löscht ihn und bucht eine Entwarnung für jeden
  Kanal, dem er gemeldet war. Der Webhook bekommt für „läuft demnächst ab"
  also `resolved`, und zwar in dem Augenblick, in dem das Zertifikat abläuft.
  Das ist Befund 7 aus B5 (`docs/141 §0`), damals behoben für den Platz und
  nicht als Regel.

  > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
  > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.** Hier steckte
  > er nicht im nächsten Merkmal, sondern in einer Prüfung, die es seit A10
  > gibt.

- **Ein Zertifikat, das abläuft und einen Namen nicht deckt, heisst nur
  `name_mismatch`.** Eine Kundenmail käme dafür erst, wenn es abgelaufen ist,
  und dann als „abgelaufen" und nie als „läuft ab".

### Beobachtung · Eine gescheiterte Bestellung steht nur am Vorgang

`CertificateStatus` kennt `Pending` und `Failed`, und der Kopf der Aufzählung
begründet `Failed` ausführlich: *„der Zustand, den man beim Bauen vergisst"*.
**Geschrieben wird keiner von beiden**, und `certificates.last_error` nur auf
`null`. Eine Bestellung legt keine Zeile an; die entsteht erst bei der
Ausstellung (`CertificateRecord::store()`), und
`CertificateLifecycle::afterFailure()` ist leer. Warum eine Erneuerung
scheiterte, steht in der Meldung des Vorgangs `acme.certificate.issue` an der
Domain, und den sieht der Kunde schon heute unter „Vorgänge". B9 nimmt den
Grund von dort. Den Zustand an der Zeile baut es nicht; der Kopf der
Aufzählung wird beim Bauen berichtigt.

## §3 · Die Rechnungen

`php tests/kundenmeldungen-rechnen.php`, Laufzeit 48 s im Container.

**Gerechnet und nicht auf dem Server gemessen.** Die Zeitgeber sind ein Modell
ihrer Units unter `packaging/systemd`: `OnCalendar=daily`, eine Stunde Streuung
für Erneuerung und Diagnose, zwei Stunden für die Sicherungen, je Nacht neu
gewürfelt. Die Regeln kommen aus dem Bestand: `Certificates::file()`,
`CertificateRenewal::due()` und `Notices::HOLD_HOURS`. **Gemessen ist die
Laufzeit eines Zertifikats**, an einem echten von Let's Encrypt (`docs/78`):
`notAfter` ist `notBefore` plus 90 Tage minus eine Sekunde.

### M1 · Eine Erneuerung gegen die Diagnose, 200 000 Versuche

| Erneuerung | Ausstellung dauert | gemeldet ab | zweimal hintereinander | nur einmal |
|---|---|---|---|---|
| gelingt | 5 s | 30 Tagen | 8,40 % | 41,69 % |
| gelingt | 60 s | 30 Tagen | 8,63 % | 42,72 % |
| gelingt | 300 s | 30 Tagen | 9,55 % | 46,94 % |
| gelingt | 60 s | 29 Tagen | 0,00 % | 4,44 % |
| gelingt | 60 s | **28 Tagen** | **0,00 %** | **0,00 %** |
| scheitert | 60 s | 30 Tagen | 100,00 % | 0,00 % |
| scheitert | 60 s | **28 Tagen** | **100,00 %** | 0,00 % |

„Zweimal hintereinander" ist eine Meldung, „nur einmal" eine Zeile auf
„Diagnose" für eine Nacht. Die beiden letzten Zeilen sind die Gegenprobe: Eine
Erneuerung, die nie gelingt, wird mit beiden Schwellen in jedem Versuch
gemeldet. Die drei Dauern stehen da, weil die Ausstellung über die
Warteschlange läuft, in der nachts auch die Sicherungen stehen.

### M2 · Welchen Grund `Certificates::file()` wann ausspricht

Ein Zertifikat, gültig bis zum 22. November 2026, 11:00:17 UTC:

| Zeitpunkt | Grund |
|---|---|
| 35 Tage davor | keiner |
| 29 Tage davor | `expiring` |
| eine Stunde davor | `expiring` |
| eine Stunde danach | `expired` |
| drei Tage danach | `expired` |
| 10 Tage davor, `www.example.de` nicht gedeckt | `name_mismatch` |

Ein Grund und nie zwei: Mit dem Ablauf ist `expiring` fort.

### M3 · Eine gescheiterte Sicherung gegen die Diagnose, 100 000 Versuche

Angenommen ist ein Befund, der steht, solange die jüngste fertige Sicherung
eines Abonnements gescheitert ist, und der im Nachtlauf der Diagnose entsteht.
Der Verzug zählt vom Scheitern bis zur Meldung.

| gescheitert | Haltezeit | gemeldet | Verzug, Median und höchstens |
|---|---|---|---|
| keine Nacht | 0 h oder 20 h | 0,00 % | — |
| eine Nacht | **0 h** | **81,43 %** | 22,9 h, 25,0 h |
| eine Nacht | 20 h | 18,32 % | 24,1 h, 25,0 h |
| zwei Nächte | **0 h** | **100,00 %** | 23,0 h, 25,0 h |
| zwei Nächte | 20 h | 81,67 % | 46,9 h, 48,9 h |
| jede Nacht | 0 h | 100,00 % | 23,0 h, 25,0 h |
| jede Nacht | 20 h | 100,00 % | 47,0 h, 48,9 h |

**Mit der Haltezeit aus B1 würfelt die Meldung.** Eine einzelne gescheiterte
Sicherung wird in 18 % der Fälle gemeldet und zwei hintereinander in 82 %, weil
die Diagnose den Befund ein- oder zweimal sieht, je nachdem, ob sie vor oder
nach der Sicherung läuft. Ohne Haltezeit bleiben 19 % einer einzelnen
ungemeldet, und das zu Recht: Dort war die nächste Sicherung gelungen, bevor
die Diagnose lief.

> **Eine Haltezeit über einem Zustand, den ein anderer Zeitgeber herstellt,
> zählt nicht die Fehlschläge, sondern wie oft der Ablesende sie antrifft.**

## §4 · Der Entwurf

### Wer was bekommt

| Befund | Kunde | Betreiber per Mail | Webhook |
|---|---|---|---|
| `quota.exceeded` (B5) | ja | nein | ja |
| `tls.file` · `expiring`, `expired` | **ja** | ja, wie seit B1 | ja |
| `tls.file` · `missing`, `name_mismatch`; `tls.wire` | nein | ja, wie seit B1 | ja |
| `backup.latest` · `failed` (neu) | **ja** | **ja** | ja |
| alle übrigen | nein | ja, wie seit B1 | ja |

**Entschieden ist 1b** (§6): In der Spalte „Betreiber per Mail" steht für beide
neuen Zeilen „nein", und der Kanal „Kundenmail" entfällt samt der Migration
darunter. Die Laufzeit ist dabei ein eigener Schlüssel geworden, `tls.expiry`;
die Tabelle, wie gebaut, steht in §10.

**Dass der Betreiber bei Zertifikat und Sicherung auch eine Mail bekommt, ist
Frage 1 und der teuerste Teil des Entwurfs.** Heute trägt der Mailkanal einen
Befund zu genau **einem** Empfänger: `MailChannel::batchKey()` entscheidet nach
der Prüfung, und gebucht wird in `finding_notifications` je Befund und Kanal.
Für zwei Empfänger braucht es einen zweiten Kanal, „Kundenmail", neben der Mail
an den Betreiber und dem Webhook. Daraus folgt dreierlei:

- **Die Kontingente ziehen in den neuen Kanal um, und ihre Buchungen ziehen
  mit.** Eine Migration schreibt sie vom einen Kanal auf den anderen um. Ohne
  sie ginge nach dem Update jede Kontingentmail, die schon gemeldet war, ein
  zweites Mal hinaus.

  > **Ein Update, das eine Buchung umzieht, ohne sie mitzunehmen, meldet alles
  > noch einmal, was schon gemeldet war.**
- **Ein Kanal sagt wieder, was er trägt.** `carries()` stand bis zum
  24. September in `Channel` und ist fort, weil zwei von zwei Kanälen dasselbe
  antworteten. Mit dem dritten antworten sie verschieden.
- **„Zuletzt erfolgreich zugestellt" steht für beide Mailkanäle auf der
  Einstellungsseite** (`ChannelReachTest`), und „Diagnose" zeigt die Zustellung
  je Kanal wie bisher.

Mit Frage 1b, nur der Kunde, bleibt es bei einem Mailkanal, der nach Prüfung
**und Grund** auswählt. Dem Betreiber fehlten dann die Zertifikatsmails, die
seit B1 für die Domains der Kunden kommen, und eine gescheiterte Sicherung
stünde für den Betreiber nur im Webhook und auf der Seite.

### Zertifikat

- **Ablauf und Namen werden getrennt beurteilt, und `expiring` bleibt neben
  `expired` stehen** — Befund 3. Die Mail nennt dann nur den schwereren, wie
  `QuotaWarning::shown()` beim Platz. `missing` bleibt allein, denn ohne Datei
  ist nichts weiter zu beurteilen. Die Leitung wird weiterhin nur gefragt, wenn
  die Datei keinen Befund hat.
- **Die Schwelle hängt an der Herkunft.** Ein hochgeladenes Zertifikat erneuert
  niemand; es wird wie heute ab 30 Tagen gemeldet. Eines von Let's Encrypt erst
  ab 28 Tagen, zwei Nächte nachdem seine Erneuerung fällig wurde (Frage 2). Ein
  Wächter rechnet den Abstand aus den beiden Units nach, wie `DiskCadenceTest`
  die Haltezeit von „Platte voll".
- **Das Datum steht in der Anzeigezone mit ihrer Zone** und nicht mehr in UTC,
  für beide Empfänger.
- **Die Mail sagt, was der Kunde tun kann**, und das hängt an der Herkunft:
  - **Let's Encrypt:** Das Panel erneuert selbst und versucht es jede Nacht. Die
    Mail sagt, seit wann es nicht gelingt und was der letzte Versuch gemeldet
    hat, also die Meldung des Vorgangs. Und dass sich die Bestellung auf der
    Seite der Domain sofort neu anstossen lässt: Die Route `POST
    /domains/{domain}/certificate` fragt dieselbe Fähigkeit wie das Ändern der
    Domain.
  - **Hochgeladen:** Das Panel erneuert es nicht; es braucht ein neues. Ob der
    Kunde das selbst hochladen darf, entscheidet der Plan des Abonnements
    (`certificate_upload`), und der Satz sagt es danach.

### Sicherung

- **Ein neuer Schlüssel `backup.latest` mit einem Grund `failed`:** Die jüngste
  fertige Sicherung des Abonnements ist gescheitert. „Fertig" heisst `ready`
  oder `failed`; eine laufende zählt nicht, und eine, die gerade entfernt wird,
  zählt als gelungen. Gegenstand ist das Abonnement. Die Zeile nennt Zeitpunkt
  und Grund aus `backups.last_error`, also denselben Satz, den der Kunde heute
  auf der Seite liest.
- **Geschrieben im Nachtlauf der Diagnose** und nicht im Prüflauf der
  Sicherungen. Die Prüfung liest allein die Datenbank und fragt keinen Agenten;
  sie kennt deshalb kein `unreachable`, denn ein Grund ohne Sprecher ist ein
  toter Eintrag.
- **Gezählt werden die Abonnements, die eine neue Sicherung bekommen können:**
  benutzbar, und ihr Plan gibt Sicherungen frei. Ohne die zweite Bedingung
  stünde nach einem Planwechsel ein Befund, den keine Sicherung mehr ablösen
  kann.
- **Zurück nimmt ihn die nächste gelungene Sicherung.** Die Aufbewahrung räumt
  gescheiterte Zeilen nicht ab (`Retention`: *„weil sie nichts aufbewahrt"*),
  und die Automatik versucht es in der nächsten Nacht wieder, weil
  `Retention::isDue()` eine gescheiterte nicht zählt.
- **`fail` und nicht `warn`.** Eine Sicherung hat genau eine Aufgabe, und in
  dieser Nacht hat sie sie nicht erfüllt — dieselbe Begründung, mit der
  `backup.file` durchgehend auf `fail` steht.
- **Die Mail nennt, was noch da ist:** die jüngste gelungene Sicherung mit
  ihrem Zeitpunkt, oder dass es keine gibt. Dass die nächste von selbst kommt,
  sagt sie nur, wenn die Automatik an ist.
- Wann gemeldet wird, ist Frage 3; welche Sicherungen zählen, Frage 4.

### Die Mail

**Eine je Abonnement, Empfänger und Nacht**, wie in B5. Ein Kunde, dessen
Platz knapp wird und dessen Sicherung scheitert, bekommt eine Mail mit zwei
Abschnitten und nicht zwei Mails. Reiner Text, die Zeilen unter 78 Zeichen, die
Absätze nur zu dem, was in der Mail steht. Der Betreff nennt die Überschriften
und das Abonnement:

    Zertifikat läuft ab und Sicherung fehlgeschlagen: kunde-a

**Keine Entwarnung per Mail**, wie in B5; der Webhook entwarnt wie bisher.

## §5 · Wann es abgenommen ist

Der Vorschlag für `docs/129 §9`:

> **B9** — Eine Domain, deren hochgeladenes Zertifikat in weniger als dreissig
> Tagen abläuft, und ein Abonnement, dessen jüngste Sicherung gescheitert ist,
> bringen dem Kunden je genau eine Mail. Der Betreiber sieht auf „Diagnose",
> dass sie zugestellt wurden, und eine gelungene Sicherung nimmt den Befund
> zurück.

Mit Frage 1a gehört dazu, dass auch der Betreiber eine Mail bekommt.
**Entschieden ist 1b**; eingetragen in `docs/129 §9` ist deshalb die
Gegenrichtung: Der Betreiber bekommt dazu keine Mail. Und weil eine Mail je
Abonnement und Nacht hinausgeht, stehen zwei Zustände desselben Abonnements in
einer Nacht als zwei Abschnitte in einer Mail.

**Zwei Teile kann ein Lauf von zwei Nächten nicht zeigen**, und sie stehen
deshalb bei den Wächtern und nicht im Kriterium: dass eine gelingende
Erneuerung von Let's Encrypt nichts meldet und eine scheiternde schon. Eine
Erneuerung steht alle sechzig Tage an. Beides tragen die Rechnung aus §3 und
der Wächter über die Schwelle.

**Hergestellt wird auf `cloudsrv24`**, und zwei Dinge daran stehen schon fest.
Das Prüfzertifikat samt Schlüssel erzeugt der Betreiber auf dem Server, denn
privates Schlüsselmaterial entsteht in diesem Container nie. Und wie eine
Sicherung scheitert, ohne einem Kunden etwas zu nehmen, misst die Vorbereitung
im Container, bevor der Lauf es anweist.

## §6 · Die Fragen an den Betreiber

**Entschieden am 7. Oktober 2026: 1b, 2a, 3a, 4a.** Bei Frage 1 ist es nicht
der Vorschlag; bei den drei übrigen ist er es.

1. **Wer bekommt die beiden Meldungen per Mail?**
   - **a. Kunde und Betreiber** — der eigene Kanal „Kundenmail" aus §4.
     *Vorschlag.* Eine gescheiterte Sicherung erfährt heute niemand
     (Befund 1), und die Zertifikatsmails bekommt der Betreiber seit B1.
   - b. Nur der Kunde, wie bei den Kontingenten. Kleiner; der Betreiber
     verlöre die Zertifikatsmails und erführe eine gescheiterte Sicherung nur
     über Webhook und Seite. **— entschieden**
2. **Ab wann gilt ein Zertifikat von Let's Encrypt als „läuft demnächst ab"?**
   - **a. Ab 28 Tagen**, zwei Nächte nachdem seine Erneuerung fällig wurde.
     *Vorschlag.* Das behebt Befund 2 auch für den Betreiber. **— entschieden**
   - b. Wie heute ab 30 Tagen. Dann meldet etwa jede zwölfte gelungene
     Erneuerung, an den Betreiber und an den Kunden.
3. **Wann wird eine gescheiterte Sicherung gemeldet?**
   - **a. Im ersten Nachtlauf, der sie sieht**, ohne Haltezeit: jede, die bis
     dahin keine gelungene abgelöst hat, rund einen Tag nach dem Scheitern.
     *Vorschlag.* **— entschieden**
   - b. Erst wenn zwei hintereinander gescheitert sind — ein eigener Grund,
     ebenfalls ohne Haltezeit. Eine einzelne meldet dann nie, zwei immer.
   - c. Wie alles aus der Nacht nach 20 Stunden. Dann würfelt die Meldung
     (M3: eine einzelne 18 %, zwei hintereinander 82 %). *Nicht empfohlen.*
4. **Welche Sicherungen zählen?**
   - **a. Die jüngste, gleich wer sie angestossen hat.** *Vorschlag.* Eine
     gelungene von Hand nimmt dann eine gescheiterte aus der Nacht zurück.
     Umgekehrt meldet sich eine gescheiterte von Hand am nächsten Tag, auch
     wenn der Kunde sie hat scheitern sehen. **— entschieden**
   - b. Nur die aus der Nacht. Wer von Hand sichert, sieht das Ergebnis auf der
     Seite. Erkennbar ist die nächtliche an ihrem Vorgang, der keinen
     Handelnden trägt (`BackupActorTest`).

## §7 · Was B9 nicht wird

- **Keine Entwarnung per Mail an den Kunden**, wie in B5.
- **Keine Kundenmail zu `missing`, `name_mismatch` und `tls.wire`.** Das sind
  Zustände des Servers, und die Mail dazu bekommt der Betreiber.
- **Keine Kundenmail zu einer beschädigten Sicherung** (`backup.file`). Der
  Befund entsteht im Prüflauf über fertige Archive, und was dann zu tun ist,
  entscheidet der Betreiber. Ob der Kunde davon erfährt, wäre eine eigene Frage
  nach derselben Regel.
- **Kein Abbestellen durch den Kunden**, wie in B5.
- **Kein Hinweis im Panel, wohin die Meldungen gehen.** Entschieden am
  7. Oktober 2026 (`docs/141 §7`, Beobachtung 1).
- **Kein Zustand an der Zeile einer gescheiterten Bestellung** (Beobachtung in
  §2).

## §8 · Die Wächter

- **Wer was bekommt:** die Tabelle aus §4 je Prüfung und Grund, in beide
  Richtungen und durch den echten Meldelauf. Der Ort ist `NoticeAudienceTest`.
- **Die umgezogene Buchung** (nur mit Frage 1a): Ein gemeldeter
  Kontingentbefund ist nach der Migration über die Kundenmail nicht wieder
  fällig. *Entfällt mit 1b; die Wächter, wie gebaut, stehen in §10.*
- **Die Schwelle für Let's Encrypt** liegt zwei Nächte hinter `LEAD_DAYS`,
  gerechnet aus den Units.
- **Ablauf und Namen getrennt**, `expiring` neben `expired`, und die Leitung
  nur bei heiler Datei. `CertificateVerdictTest` hält das heute für einen
  einzigen Grund.
- **`backup.latest`:** jüngste fertige gescheitert, Befund; eine jüngere
  gelungene, keiner; eine laufende übergangen; ein Plan ohne Sicherungen,
  keiner; die Haltezeit nach Frage 3.
- **Die Mail:** jeder Grund hat eine Überschrift, die Zeilen bleiben unter 78
  Zeichen, reiner Text mit Unterschrift (`QuotaWarningTest`,
  `PlainTextMailTest`, `MailSignatureTest`).
- **Jeder Wächter bekommt seinen Eingriff im Bruchskript**, und jeder Eingriff
  wird einzeln gegen seinen Fall gefahren.

## §9 · Aufwand und Reihenfolge

Zwei bis drei Tage Bau, davon etwa einer für den Kanal aus Frage 1a, dazu ein
Abnahmelauf über zwei Nächte. *Mit 1b entfiel der Kanal; gebaut ist B9 an
einem Tag (§10).* Gebaut wird in dieser Reihenfolge:

1. **Zertifikat** — die Befunde 2 und 3, an einer Prüfung, die es gibt.
2. **Sicherung** — der neue Schlüssel.
3. **Kanal und Mail.**
4. **Wächter und Bruchskript**, dann die Freigabe und der Lauf.

## §10 · Gebaut — 7. Oktober 2026

Gebaut am selben Tag wie entschieden, in einem Zug, und an vier Stellen anders
als in §4 entworfen. **Eine Freigabe trägt es noch nicht, und gefahren ist kein
Lauf**; das Kriterium steht in `docs/129 §9`.

### Wer was bekommt, wie gebaut

| Befund | Kunde | Betreiber per Mail | Webhook |
|---|---|---|---|
| `quota.exceeded` (B5) | ja | nein | ja |
| `tls.expiry` · `expiring`, `expired` (neu) | **ja** | **nein** | ja |
| `tls.file` · `missing`, `name_mismatch`; `tls.wire` | nein | ja | ja |
| `backup.latest` · `failed` (neu) | **ja** | nein | ja |
| alle übrigen | nein | ja | ja |

Der Betreiber sieht beide Meldungen auf „Diagnose", samt der Zustellung an den
Kunden, und über den Webhook. Eine Mail bekommt er dazu nicht, auch nicht mehr
für ein ablaufendes Zertifikat, das er seit B1 per Mail bekam.

Die übrigen drei Antworten: Ein Zertifikat von Let's Encrypt gilt ab 28 Tagen
Restlaufzeit als ablaufend, ein hochgeladenes wie bisher ab 30 (2a). Eine
gescheiterte Sicherung meldet der erste Nachtlauf, der sie sieht, ohne
Haltezeit (3a). Und es zählt die jüngste fertige Sicherung, gleich wer sie
angestossen hat (4a).

### Was anders lief als entworfen

1. **Die Laufzeit ist ein eigener Schlüssel und kein Grund unter `tls.file`.**
   §4 wollte den Mailkanal nach Prüfung **und Grund** auswählen lassen. Gebaut
   ist `tls.expiry` neben `tls.file` und `tls.wire`, und der Kanal wählt wie
   bisher nach der Prüfung allein (`MailChannel::CUSTOMER`). Befund 3 ist damit
   an der Wurzel behoben: `expiring` und `expired` stehen unter einem Schlüssel
   nebeneinander, und ein falscher Name verdeckt die Laufzeit nicht mehr, weil
   beides zwei Fragen sind.

   > **Zwei Fragen an denselben Gegenstand sind zwei Schlüssel, sobald sie
   > zwei Empfänger haben.**

2. **Der Satz am Befund bleibt in UTC.** §4 wollte das Datum für beide
   Empfänger in der Anzeigezone. Die Prüfung rechnet aber ohne Framework —
   `tests/kundenmeldungen-rechnen.php` fährt sie so —, und die Anzeigezone steht
   in der Datenbank. Der Satz trägt seine Zone (`gültig bis … UTC`); die Mail an
   den Kunden liest den Zeitpunkt aus ihm (`Certificates::validUntil()`) und
   nennt ihn in der Anzeigezone. **Nicht aus `not_after` im Bestand:** Der
   Befund beurteilt die Datei, und wo Datei und Zeile auseinanderlaufen, nennte
   die Mail sonst einen anderen Tag als den, über den sie berichtet.

3. **Die Kundenmail heisst `CustomerNotice` und nicht mehr `QuotaWarning`.**
   Eine je Abonnement, Empfänger und Nacht, wie §4 es wollte, mit einem
   Abschnitt je Art (`QuotaSection`, `CertificateSection`, `BackupSection`) in
   fester Reihenfolge. Was nicht im Befund steht, liest `CustomerFacts` beim
   Zustellen: das Abonnement einer Domain, die Herkunft des Zertifikats, den
   letzten gescheiterten Versuch am Vorgang `acme.certificate.issue`, die
   jüngste gelungene Sicherung und ob die nächste von selbst kommt.

4. **Ein Übergang, den §4 nicht kannte, und keine Migration dafür.** Nach dem
   Update stehen bis zum ersten Nachtlauf Zeilen `tls.file / expiring` und
   `expired` in der Tabelle, geschrieben von der Fassung davor.
   `FindingCheck::state()` und `sentence()` warfen für einen Grund, den die
   Prüfung nicht mehr ausspricht: Die Seite „Diagnose" gab einen 500er, und
   der Meldelauf brach an der Entwarnung ab. Gemessen mit `RetiredReasonTest`
   gegen die Fassung ohne die Behebung, an beiden Stellen mit derselben
   Meldung: *Die Prüfung tls.file kennt den Grund "expired" nicht.*

   Geplant war eine Migration, die die Zeilen nach `tls.expiry` umzieht. **Sie
   hätte den Webhook falsch zurückgelassen.** Sein Empfänger ordnet eine
   Entwarnung über Gegenstand, Prüfung und Grund zu
   (`WebhookChannel::deliverResolved()`). Der Vorfall unter `tls.file` wäre nie
   geschlossen worden, und die Entwarnung käme später unter einem Schlüssel,
   den er nie gesehen hat. Gebaut ist deshalb `FindingCheck::retired()`: Die
   beiden Gründe bleiben unter `tls.file` bekannt, mit Urteil und Satz von
   damals, und geschrieben werden sie nie, denn der Schreibweg fragt
   `assertSpoken()` und nicht mehr `state()`. Der erste Nachtlauf nach dem
   Update schliesst den alten Vorfall mit seinem eigenen Satz, und
   `tls.expiry` meldet sich nach seiner Haltezeit, also in der zweiten Nacht.

   > **Ein Grund, den es nicht mehr gibt, steht nach dem Update noch in der
   > Tabelle — und wer ihn fragt, wirft.**

   > **Wer eine Zeile umzieht, unter deren Schlüssel ein Empfänger einen
   > Vorfall führt, lässt den Vorfall offen.**

### Die Mail, wie sie hinausgeht

Gerendert im Container mit der echten Vorlage gegen eine frisch migrierte
Datenbank im Speicher, Anzeigezone `Europe/Berlin`; die Angaben sind
ausgedacht. Die längste Zeile hat 76 Zeichen. Die Trennzeile der Unterschrift
trägt in der Mail ein Leerzeichen am Ende (`docs/140 §6e`), hier nicht.

    Betreff: SrvPanel — Zertifikat läuft ab und Sicherung fehlgeschlagen: kunde-a

    Guten Tag,

    für Ihr Abonnement kunde-a hat das Panel Folgendes festgestellt:

    - Das Zertifikat läuft demnächst ab.
      Domain: kunde-a.example
      Gültig bis: 2026-11-03 12:00 CET (UTC+01:00)
      Erneuerung fällig seit: 2026-10-04 13:00 CEST (UTC+02:00)
      Letzter Versuch: 2026-10-08 02:12 CEST (UTC+02:00): Die Prüfdatei war
      nicht erreichbar.

    Zertifikate von Let’s Encrypt erneuert das Panel selbst, ab 30 Tagen vor dem
    Ablauf. Auf der Seite der Domain im Panel lässt sich die Bestellung sofort
    neu anstossen.

    - Die jüngste Sicherung dieses Abonnements ist fehlgeschlagen.
      Erstellt: 2026-10-07 03:31 CEST (UTC+02:00)
      Meldung: Zu wenig Platz für die Sicherung

    Die jüngste gelungene Sicherung dieses Abonnements ist vom 2026-10-06 03:28
    CEST (UTC+02:00).

    Die nächste Sicherung legt das Panel in der kommenden Nacht von selbst an.

    Sie bekommen diese Nachricht einmal je Zustand. Erst wenn er vorbei ist und
    wieder eintritt, meldet sich das Panel erneut.

    --
    SrvPanel

Der Abschnitt zum Zertifikat sieht für ein hochgeladenes anders aus: ohne
„Erneuerung fällig seit" und „Letzter Versuch", und der Absatz sagt, dass das
Panel es nicht erneuert und ob der Kunde ein neues selbst hochladen darf.

### M2, wie gebaut

`tests/kundenmeldungen-rechnen.php` rechnet M2 seit dem Bau mit
`Certificates::file()` und `expiry()` und mit beiden Schwellen. Dasselbe
Zertifikat wie in §3, gültig bis zum 22. November 2026, 11:00:17 UTC:

| Zeitpunkt | hochgeladen | Let's Encrypt |
|---|---|---|
| 35 Tage davor | keiner | keiner |
| 29 Tage davor | `expiring` | keiner |
| eine Stunde davor | `expiring` | `expiring` |
| eine Stunde danach | `expiring`, `expired` | `expiring`, `expired` |
| drei Tage danach | `expiring`, `expired` | `expiring`, `expired` |

Läuft es ab und deckt `www.example.de` nicht, stehen zwei Befunde da: die Datei
mit `name_mismatch` für den Betreiber, die Zeit mit `expiring` für den Kunden.
M1 und M3 rechnen unverändert; die Zahlen in §3 stehen.

### Die Wächter

- **Neu:** `CertificateCadenceTest` (die Schwelle liegt zwei Nächte hinter
  `LEAD_DAYS`, gerechnet aus den Units), `LatestBackupTest`
  (`backup.latest`), `CustomerNoticeTest` (die Mail, ihre Abschnitte und was
  `CustomerFacts` liest) und `RetiredReasonTest` (der Übergang, durch die Seite
  und durch den Meldelauf).
- **Erweitert:** `CertificateVerdictTest` (Ablauf und Namen getrennt,
  `expiring` neben `expired`) und `NoticeAudienceTest` (die Tabelle oben durch
  den echten Meldelauf, beide Hälften des Zertifikats), dazu
  `NotificationLedgerTest`, `QuotaRecipientTest`, `MailSignatureTest`,
  `PlainTextMailTest`, `BrandReachTest`, `QuotaOverrunTest`,
  `FindingIdentityTest`, `DiagnoseCatalogTest` und `DiagnoseSeamTest`.
  `QuotaWarningTest` heisst `QuotaNoticeTest`.
- **Im Bruchskript 49 neue Eingriffe**, dazu neue Anker und Namen für die
  bestehenden, deren Dateien B9 umgebaut hat. Gefahren sind alle Eingriffe in
  Dateien, die B9 berührt, zuerst 217 Abschnitte, danach die der berichtigten
  Fälle und des Übergangs. Am Ende beissen alle.

**Drei Eingriffe bissen im ersten Lauf nicht, und alle drei lagen an meinen
eigenen Fällen.** `NoticeAudienceTest` teilte die Prüfungen nach dem Text ihres
Schlüssels ein und hielt einen, der den Namen der Domain trug, für einen des
Betreibers; er vergleicht jetzt, mit welchem Schlüssel einer übereinstimmt.
Und `CustomerNoticeTest` prüfte, dass „Letzter Versuch" fehlt, an einer Domain
ohne Zertifikat. Die Zeile steht nur bei einem, das das Panel selbst erneuert,
und fehlte deshalb in jeder Fassung.

> **Ein Prüfkörper, dem der Zustand fehlt, unter dem eine Zeile überhaupt
> erscheint, misst ihr Fehlen in jeder Fassung.**

> **Ein undurchsichtiger Schlüssel wird verglichen und nicht gelesen.**

### Was offen bleibt

- **Der Abnahmelauf**, gegen die nächste Freigabe und über zwei Nächte. Das
  Prüfzertifikat samt Schlüssel erzeugt der Betreiber auf dem Server (§5).
- **Vor dem Update auf `cloudsrv24` gehören die Empfänger nachgesehen.** Was
  auf „Diagnose" als `tls.file / expiring` oder `expired` steht, befindet der
  erste Nachtlauf danach unter `tls.expiry` neu, ein Zertifikat von Let's
  Encrypt erst ab 28 Tagen. Steht es dann noch da, geht in der zweiten Nacht
  eine Mail an die Konten seines Abonnements. Seit dem 21. September steht
  dort `tls.file` an `p6-b.invalid`, dem abgelaufenen hochgeladenen
  Zertifikat aus A10 (`docs/141 §7`). Wer diese Mail bekommt, ist nicht
  nachgesehen, und hinter einer Adresse stand im Lauf für B5 eine Attrappe auf
  einer fremden Domain.
- **Ein Platzhalterzertifikat nennt keinen letzten Versuch an seinen
  Unterdomains.** Der Vorgang hängt an der Domain, für die bestellt wurde; eine
  Unterdomain, die der Platzhalter deckt, bekommt ihren Abschnitt ohne diese
  Zeile. Er fehlt, er ist nicht falsch.
- **Mehr als zwanzig fällige Erneuerungen in denselben zwei Nächten.**
  `CertificateRenewal::PER_RUN` bestellt höchstens zehn je Lauf, und M1 setzt
  eine Erneuerung je Nacht voraus. Werden mehr fällig, nach einer Übernahme
  etwa, erneuert das Panel einen Teil erst nach der Schwelle, und dieser Teil
  kann eine Mail bringen, obwohl die Erneuerung gelingt. Gerechnet ist das
  nicht.
