# 09 — Sicherheitsbetrachtung: Anmeldung und Updates

> **Was dieses Dokument nicht ist.** Kein externer Review. Es ist eine
> Selbstbetrachtung derselben Leute, die den Code geschrieben haben — mit allen
> blinden Flecken, die das mit sich bringt. Es steht hier, damit fremde Prüfer
> nicht bei null anfangen müssen und damit Betreiber die getroffenen
> Abwägungen kennen, bevor sie sich darauf verlassen.

Betrachtet werden die beiden Pfade, an denen ein Fehler die Kontrolle über den
Server kostet: die Anmeldung und das Selbstupdate.

## Angreifermodell

| Angreifer | Was er kann | Wogegen er antritt |
|---|---|---|
| **Unangemeldet aus dem Netz** | HTTPS-Anfragen an das Panel | Rate-Begrenzung, Argon2id, zweiter Faktor |
| **Auf dem Weg dazwischen** | Verkehr lesen und verändern | TLS; beim Update zusätzlich die Signaturkette |
| **Mit übernommener Sitzung** | Cookie eines angemeldeten Kontos | Rollen, CSRF, Passwortabfrage vor dem Faktorwechsel |
| **Angemeldet mit geringer Rolle** | ReadOnly oder Admin | serverseitige Rollenprüfung je Route |
| **Wer den Updateserver kontrolliert** | Metadaten und Artefakte austauschen | eingebauter Signaturschlüssel |

Ausdrücklich **nicht** im Modell: wer bereits root auf dem Server hat. Wer das
hat, braucht keine Lücke im Panel.

## Anmeldung

### Ablauf

```
POST /login
  ├─ Middleware: Rate-Limit je Quell-IP          (routes.go: rateLimited)
  ├─ Handler:    Rate-Limit je Konto
  ├─ Konto laden — unbekannt? trotzdem Argon2 gegen einen Dummy rechnen
  ├─ Passwort:   Argon2id, zeitkonstanter Vergleich
  ├─ Faktor:     TOTP mit Wiederholungsschutz, sonst Wiederherstellungscode
  ├─ Alles zusammen prüfen — nach außen immer dieselbe Meldung
  ├─ Erst jetzt: TOTP-Zähler festschreiben
  └─ Sitzung anlegen, Cookie setzen, Audit-Eintrag
```

### Was bewusst so ist

**Zwei Rate-Begrenzungen, nicht eine.** Je Quell-IP in der Middleware, je Konto
im Handler. Nur je IP zu begrenzen ließe ein verteiltes Ausprobieren eines
einzelnen Kontos zu; nur je Konto zu begrenzen erlaubte einem Angreifer, ein
fremdes Konto durch Fehlversuche gezielt auszusperren — die IP-Grenze bremst ihn
vorher aus.

**`X-Forwarded-For` wird nicht ausgewertet.** Ein blind übernommener Header
würde die Rate-Begrenzung mit einer Kopfzeile aushebelbar machen. Wer das Panel
hinter einen Reverse Proxy stellt, muss das bewusst nachrüsten.

**Bei unbekanntem Konto wird trotzdem Argon2 gerechnet.** Ohne diesen
Zeitausgleich verriete die Antwortzeit, welche Konten es gibt.

**Nach außen immer dieselbe Meldung.** Welcher der beiden Faktoren gestimmt hat,
geht niemanden etwas an, der ihn nicht kennt. Im Audit-Log steht der Grund
vollständig — dort darf er stehen, dort liest ihn nur, wer schon angemeldet ist.

**Alle Vergleiche geheimer Werte sind zeitkonstant**: Passwort-Hash
(`password.go`), TOTP-Code (`totp.go`), CSRF-Token (`session.go`).

**In der Datenbank steht nie ein Sitzungs-Cookie**, nur dessen SHA-256. Ein
Datenbankabzug erlaubt damit keine Übernahme laufender Sitzungen.

**Der zweite Faktor lässt sich nicht abschalten.** Es gibt keine Einstellung
dafür — ein Panel mit root-Rechten und nur einem Passwort ist die Sorte
Bequemlichkeit, die man später bereut.

### Gefunden und behoben: TOTP-Codes galten mehrfach

Bei dieser Durchsicht fiel auf, dass `VerifyTOTP` zustandslos war. Ein Code galt
sein ganzes Zeitfenster über — bei einer Toleranz von einem Fenster bis zu
**anderthalb Minuten** — und beliebig oft. RFC 6238 §5.2 verlangt das Gegenteil:

> *The verifier MUST NOT accept the second attempt of the OTP after the
> successful validation has been issued for the first OTP.*

**Angriffsweg.** Wer einen gültigen Code mitliest und das Passwort kennt, konnte
ihn innerhalb der Frist erneut einlösen. Mitlesen ist keine exotische Annahme:
ein Phishing-Formular, das Passwort und Code entgegennimmt und weiterreicht, ein
protokollierender Proxy davor, ein Blick über die Schulter.

**Behoben.** Das Konto merkt sich das zuletzt angenommene Zeitfenster
(`users.totp_last_counter`, Migration 0002). Codes aus diesem oder einem
früheren Fenster werden abgewiesen.

Drei Feinheiten daran:

- **Verbraucht wird erst nach einer geglückten Anmeldung**, nicht bei jedem
  Versuch. Sonst müsste jeder, der sich beim Passwort vertippt, eine halbe
  Minute auf den nächsten Code warten.
- **Das UPDATE trägt seine Bedingung mit** (`WHERE totp_last_counter < ?`).
  Melden sich zwei Anfragen gleichzeitig mit demselben Code an, kommt genau
  eine durch — die zweite ändert keine Zeile und wird abgewiesen.
- **Eine Wiederverwendung wird im Audit-Log als solche vermerkt**, unterscheidbar
  von einem falschen Code. Das eine deutet auf Mitlesen hin, das andere auf ein
  Vertippen.

### Ebenfalls geändert: Wiederherstellungscodes

Ein Wiederherstellungscode wird beim Prüfen unwiderruflich eingelöst. Bis dahin
geschah das unabhängig davon, ob das Passwort stimmte — wer die Codeliste hatte,
aber nicht das Passwort, konnte die Vorräte eines Kontos aufbrauchen. Jetzt wird
ein Wiederherstellungscode nur noch bei richtigem Passwort überhaupt geprüft.

### Offene Punkte

- **Keine Sperre eines Kontos nach dauerhaftem Beschuss.** Die Verzögerung
  wächst exponentiell bis 15 Minuten, aber ein Konto wird nie endgültig
  gesperrt. Das ist Absicht — eine automatische Sperre ist ein Werkzeug, mit dem
  sich der rechtmäßige Inhaber aussperren lässt. Wer eine will, muss sie
  bewusst wollen.
- **Die Rate-Zähler liegen im Speicher.** Ein Neustart des Panels setzt sie
  zurück. Da ein Neustart root voraussetzt, ist das für einen Angreifer von
  außen kein Weg.
- **TOTP allein ist gegen Phishing nicht sicher.** Ein Formular, das Passwort
  und Code entgegennimmt und sofort weiterreicht, kommt durch — der
  Wiederholungsschutz verkürzt nur das Zeitfenster, er schließt die Lücke nicht.
  Die Antwort darauf sind Passkeys (siehe unten); sie stehen seit 0.3.0 als
  zusätzlicher Faktor bereit, sind aber noch nicht Pflicht.

### Passkeys (WebAuthn)

Seit 0.3.0 lässt sich ein Passkey als zweiter Faktor hinterlegen. Er ist die
Antwort auf die Phishing-Schwäche von TOTP: Der private Schlüssel verlässt das
Gerät nie, und die Signatur ist an den Ursprung gebunden — eine nachgebaute
Seite unter anderem Namen bekommt keine gültige Antwort. Einzelheiten und die
Entwurfsentscheidungen stehen in [11-passkeys.md](11-passkeys.md).

Was bewusst so ist:

- **Additiv, nicht ersetzend.** TOTP bleibt Pflicht, die Wiederherstellungscodes
  bleiben der Notnagel. So sperrt weder ein verlorenes Gerät noch das Entfernen
  eines Passkeys jemanden aus, und der Anmeldeweg ohne JavaScript bleibt.
- **Die Krypto kommt aus einer Bibliothek** (go-webauthn), nicht aus Eigenbau —
  anders als bei TOTP, das dreißig Zeilen sind. WebAuthn ist zu umfangreich, um
  es für einen sicherheitskritischen Pfad selbst zu schreiben.
- **Anlegen verlangt das aktuelle Passwort**, damit eine übernommene Sitzung
  nicht unbemerkt einen dauerhaften Schlüssel hinterlegt.
- **Die halb fertige Anmeldung gewährt nichts.** Zwischen Passwort und Assertion
  liegt nur ein kurzlebiges, serverseitiges Token; ohne gültige Signatur entsteht
  keine Sitzung. Beide Schritte gehen durch dieselbe Ratenbegrenzung wie der
  gewöhnliche Login.
- **Klon-Hinweis ohne Sperre.** Ein rückläufiger Sign-Count wird vermerkt, sperrt
  aber nicht — ein zu Recht wiederhergestellter Authenticator soll niemanden
  aussperren.

Geprüft wurde nicht nur der Idealfall: Ein echter Browser mit virtuellem
Authenticator fährt den vollen Weg (registrieren, abmelden, anmelden), und
derselbe Weg mit einer unterwegs **verfälschten Signatur** wird abgelehnt, ohne
dass eine Sitzung entsteht. Dazu kommen Tests für die Verzweigungen des Servers:
falsches Passwort, kein Passkey, verbrauchtes Vorab-Token (kein Replay), kaputte
Antwort, und die Kontosperre beim Beschuss über den Passkey-Beginn.

### Zugang zurücksetzen

Seit 0.4.0 muss ein vergessenes Passwort nicht mehr über SSH gelöst werden. Die
Einzelheiten und die Abwägungen stehen in
[12-zugang-zuruecksetzen.md](12-zugang-zuruecksetzen.md); sicherheitsrelevant ist
davon:

- **Kein Rettungsweg über E-Mail.** Das Panel verschickt keine Post. Ein Reset
  per Mail würde das Postfach zum Hauptschlüssel des Servers machen; heute
  braucht eine Übernahme Passwort **und** zweiten Faktor. Dazu käme ein Notweg,
  der auf einer frischen Maschine still im Spam versagt — im Notfall die
  schlechteste Eigenschaft, die ein Notweg haben kann.
- **Der Owner-Reset verlangt das eigene Passwort des Owners.** Ein übernommenes
  Owner-Cookie soll nicht stillschweigend fremde Konten übernehmen. Das eigene
  Konto ist von diesem Weg ausgenommen.
- **Ein vergebenes Einmalpasswort trägt genau eine Anmeldung.** Danach kommt das
  Konto nur auf die Wechselseite — der Owner kennt das Passwort, es darf keine
  dauerhafte Zugangsberechtigung daraus werden.
- **Die Selbstbedienung verlangt zwei Teile, nicht einen.** Der Passkey-Nachweis
  läuft mit `userVerification: "required"`: Besitz des Authenticators **und** die
  Prüfung am Gerät. Ein entwendetes, entsperrtes Notebook genügt nicht.
- **Sie verrät keine Konten.** Die Zeremonie läuft über auffindbare Passkeys und
  nennt weder Anmeldenamen noch Credential-Kennungen. Damit entsteht kein neuer
  Weg, vorhandene Konten zu erraten — was eine gewöhnliche Assertion mit ihrer
  Liste erlaubter Credentials sehr wohl täte.
- **Das Ticket zwischen Nachweis und neuem Passwort liegt serverseitig**, gilt
  zehn Minuten und genau einmal. CSRF-Schutz ist `SameSite=Strict` am Cookie —
  eine Sitzung, aus der ein Token käme, gibt es an dieser Stelle noch nicht.
- **Ein gesperrtes Konto kommt nicht durch.** Es soll sich nicht selbst befreien.

Auch hier ist der Negativfall geprüft, und zwar im echten Browser: Ein
Authenticator, der nichts am Gerät prüft, führt zu keiner Zurücksetzung
(`TestPasskeyBrowserForgotWithoutUV`). Daran hängt die Begründung des ganzen
Wegs — Besitz allein ist ein Faktor, und ein Faktor genügt nicht, um ein
Passwort zu ersetzen.

## Dateimanager

Der Dateimanager (ab 0.3.0) vergrößert die Angriffsfläche des Panels stärker als
jedes andere Modul: Bis hierher stand in jeder Anfrage ein Wert aus einer
Allowlist — ein Unit-Name, ein Paketname, ein Port. Jetzt steht dort ein Pfad,
und der Prozess läuft als root.

### Was ein übernommener Zugang damit kann

Alles, was der Bedienende auch könnte: Konfigurationsdateien ändern, Daten
herunterladen, Verzeichnisse löschen. Das ist keine neue Klasse von Schaden —
wer ein Panel mit Schreibrecht übernimmt, kann ohnehin Dienste stoppen, Pakete
installieren und Benutzer anlegen. Neu ist die **Bequemlichkeit**: Ein Download
über eine Weboberfläche hinterlässt weniger Spuren als eine SSH-Sitzung und
braucht kein Werkzeug.

Drei Riegel dagegen:

1. **Die Sperrliste.** Passwort-Hashes (`/etc/shadow`), SSH-Host-Schlüssel, der
   private TLS-Schlüssel und die Datenbank des Panels sind für das Modul tabu —
   für **jede** Rolle, auch für Owner. Sonst wäre ein übernommener Zugang
   gleichbedeutend mit dem Verlust aller weiteren Schutzschichten: Mit der
   Datenbank hat man die Hashes aller Panel-Zugänge und die Passkey-Daten, mit
   dem TLS-Schlüssel jede künftige Verbindung. Die Liste ist eingebaut und über
   die Konfiguration nur erweiterbar.
2. **Jeder Download im Audit-Log.** Bei einem Dateimanager ist die interessantere
   Frage nicht, wer etwas geschrieben, sondern wer etwas mitgenommen hat.
3. **Rollen.** Lesen darf jede angemeldete Rolle, ändern nur `admin` und
   `owner`. Jeder Schreibendpunkt ist einzeln gegen fehlendes CSRF-Token und
   gegen eine nur lesende Rolle geprüft — eine vergessene Middleware-Kette an
   einer einzigen Route wäre ein Loch, das kein anderer Test findet.

### Beim Angriffsdurchgang gefunden

Zwei Dinge, die der Durchgang zutage gebracht hat und die vorher keinem Test
aufgefallen wären:

- **Ein Hardlink umging die Sperrliste.** Sie vergleicht Pfade, und ein Pfad ist
  nicht die Datei: `ln /etc/shadow /srv/harmlos.txt` trägt einen Namen, den kein
  Muster trifft. Die Wache prüft deshalb zusätzlich die Identität — Gerät und
  Inode der geöffneten Datei gegen die der gesperrten, ermittelt einmal je
  Prozess. Damit ist auch ein Bind-Mount auf ein Geheimnis abgedeckt. Der Test
  dazu scheitert zuverlässig, wenn man die Prüfung entfernt.

  Praktisch braucht dieser Angriff lokalen Schreibzugriff, und
  `fs.protected_hardlinks` (Vorgabe auf jeder gängigen Distribution) verbietet
  das Verlinken fremder Dateien. Die Prüfung ist trotzdem drin: Ein Riegel, der
  von einer Kernel-Einstellung abhängt, ist kein Riegel.
- **Ein Zeilenumbruch im Pfad landete unverändert im Audit-Log.** Das Log liegt
  heute in SQLite, wo eine Spalte einen Zeilenumbruch verträgt; die Roadmap sieht
  aber zusätzlich ein zeilenweises Protokoll unter `/var/log/asylum/audit.log`
  vor, und dort wären aus einem Eintrag zwei geworden — der zweite frei
  erfunden. `store.AppendAudit` macht Steuerzeichen und
  Schreibrichtungs-Umschalter jetzt als Escape-Folge sichtbar und begrenzt die
  Feldlänge. Sichtbar machen statt entfernen: Ein Pfad, aus dem stillschweigend
  Zeichen verschwinden, führt bei der Fehlersuche in die Irre.

### Was gegen Pfadausbruch getan ist

Die gesamte Prüfung liegt in `internal/privops/pfadwache.go`. Aufgelöst wird über
`os.Root`, nicht über Zeichenketten: Ein Symlink `/tmp/x → /etc/shadow` wäre
sonst ein Umweg um jede Prüfung, die nur die Zeichenkette ansieht. Geprüft
werden beide Fassungen des Pfads — die angefragte und die aufgelöste — und für
die Sperrliste zusätzlich jeder Vorfahre.

Ein eigener Angriffsdurchgang (`files_angriff_test.go`) fährt gegen das Modul:
Pfadausbruch in mehreren Kodierungen, Symlinks auf Gesperrtes, `/proc/self/root`
als Sprungbrett, Hardlinks, Namen mit NUL-Byte, Zeilenumbruch und
Schreibrichtungs-Umschaltern, Rollenanhebung, fehlende Tokens, Dateinamen mit
Pfadanteilen im Upload.

### Offene Punkte

- **Ein Angreifer, der schon lokal schreiben darf**, kann ein Verzeichnis mitten
  im Pfad im richtigen Augenblick durch einen Verweis ersetzen (TOCTOU). Gegen
  die letzte Komponente hilft `O_NOFOLLOW`, gegen die mittleren wäre ein Öffnen
  Komponente für Komponente nötig. Wer lokal schreiben kann, braucht das Panel
  dafür allerdings nicht — das Risiko ist bewusst getragen.
- **Die gelockerte Härtung.** `ProtectHome=false` und `ProtectSystem=true` statt
  `full` sind eine echte Abschwächung: Ein Codeausführungsfehler im Panel kann
  jetzt mehr anrichten. `/usr` und `/boot` bleiben schreibgeschützt, damit ein
  untergeschobenes Binary nicht der nächste Schritt ist. Wer den Dateimanager
  nicht braucht, verschärft beides und setzt `files.enabled: false`.
- **Der Editor-Nonce lockert `style-src`** für genau eine Seite auf ein
  nonce-gebundenes Element. Das ist deutlich enger als `'unsafe-inline'`, aber
  nicht so eng wie `'self'` allein.

## Selbstupdate

Der ausführliche Ablauf steht in [05-updates.md](05-updates.md); hier nur, was
sicherheitsrelevant ist.

### Der Vertrauensanker

Ein einziger Wert: der öffentliche minisign-Schlüssel als Konstante im Binary
(`internal/update/key.go`). Weder die Metadatendatei noch der Downloadserver
noch ein Programm im `PATH` kann ihn ersetzen. Die Signaturprüfung ist in Go
umgesetzt und ruft kein externes `minisign` auf — ein untergeschobenes Programm
im `PATH` könnte sonst jede Signatur für gültig erklären.

Ein Test wacht darüber, dass der eingebaute Schlüssel, `packaging/minisign.pub`
und der in `install.sh` eingebettete nicht auseinanderlaufen.

### Warum die Metadatendatei nicht signiert ist

`updates/<kanal>.json` ist ein Wegweiser, keine Vertrauensquelle. Wer sie
fälscht, kann höchstens auf eine andere — ebenfalls echt signierte — Fassung
zeigen oder das Update verhindern. Beides ist unangenehm, aber keine
Codeausführung.

Genau deshalb ist der Abgleich des beglaubigten Kommentars wesentlich: minisign
signiert neben der Prüfsummenliste einen Kommentar, der die Fassung nennt.
Stimmt er nicht mit den Metadaten überein, bricht das Update ab. **Ohne diese
Prüfung wäre ein Downgrade möglich** — eine gefälschte Metadatendatei könnte die
echte Signatur einer älteren Fassung mit bekannter Lücke vorlegen. Der Fall wird
getestet.

Geprüft wird außerdem die globale Signatur, die Signatur und Kommentar gemeinsam
abdeckt; ohne sie ließe sich der Kommentar austauschen.

### Weitere Festlegungen

- **Nur `https`**, auch nach Weiterleitungen und auch für Adressen, die aus der
  Metadatendatei stammen. Ohne diese Prüfung könnte eine manipulierte Datei den
  Download auf `http://` oder `file://` umlenken.
- **Größengrenzen** auf jeden Abruf, damit ein Server das Panel nicht mit einem
  endlosen Datenstrom beschäftigt.
- **Das Archiv wird im Speicher ausgepackt**, und nur `asylumd` daraus. Es
  entsteht kein Pfad, der über `../` ausbrechen oder als Symlink woandershin
  zeigen könnte.
- **ELF-Kennung geprüft**, bevor irgendetwas abgelegt wird. Eine
  HTML-Fehlerseite fällt damit auf.
- **`asylumd.neu` wird angesprochen, bevor getauscht wird.** Eine falsche
  Architektur oder ein beschädigter Download fällt vor dem Tausch auf.
- **Der Tausch ist ein `rename(2)`** im selben Verzeichnis, also atomar.
- **Der Vorgang läuft außerhalb der Kontrollgruppe des Dienstes.** systemd
  beendet beim Neustart die gesamte Kontrollgruppe; ein Update darin würde
  zwischen Tausch und Bereitschaftsprüfung abgeschnitten — mit einer ungeprüften
  neuen Fassung und niemandem, der sie zurücknimmt. `asylum update` weigert
  sich, in der Kontrollgruppe des Dienstes zu laufen.
- **Einspielen darf nur Owner.** Wer ein Update auslöst, bestimmt, welcher Code
  als root läuft. Das ist keine gewöhnliche Schreiboperation.

### Offene Punkte

- **Kein Herkunftsnachweis der Artefakte.** minisign sagt „von diesem
  Schlüssel", nicht „aus diesem Repository, aus diesem Workflow". cosign mit
  OIDC würde das leisten, setzt aber eine Rekor-Abfrage über das Netz voraus —
  und der Updateweg soll gerade nicht von einem weiteren erreichbaren Dienst
  abhängen. Als Ergänzung neben minisign bleibt es sinnvoll.
- **Der private Signaturschlüssel liegt als GitHub-Secret.** Wer die Kontrolle
  über das Repository erlangt, kann signieren. Ein Hardware-Token oder ein
  getrennter Signierdienst wäre besser, ist für ein Projekt dieser Größe aber
  unverhältnismäßig.
- **Der APT-Weg hat keine Bereitschaftsprüfung.** `apt upgrade` kennt keinen
  Healthcheck und keinen Rollback. Deshalb ist das eingebaute Update der
  empfohlene Weg, und der apt-Weg das Zusatzangebot.

## Was geprüft wurde und wie

| Gegenstand | Art der Prüfung |
|---|---|
| Signaturformat | gegen echtes minisign-Material im Repository, nicht gegen die eigene Vorstellung davon |
| Vollständige Updatekette | zwei echte Binaries, mit dem Projektschlüssel signiert, über HTTPS geladen, getauscht, neu gestartet, zurückgerollt |
| Manipulationen | ausgetauschtes Archiv, veränderte Prüfsummenliste, fremde Signatur, Downgrade über Metadaten — alle vier abgewiesen |
| Selbsttätiger Rückweg | echt signierte, aber nicht startfähige Fassung; nach 60 s ohne Antwort stellte der Server die vorherige wieder her |
| Rollentrennung | je verändernder Route ein Test, dass ReadOnly und Admin abgewiesen werden |
| TOTP | Testvektoren aus RFC 6238, Gegenprobe mit einer unabhängigen Implementierung, Wiederholungsschutz mit eigenen Tests |
| APT-Repository | gegen echtes `apt`: Einrichtung, Installation, manipulierte `InRelease` (BADSIG), manipulierte Paketliste (Hash-Mismatch) |

**Nicht geprüft werden konnte**, was ohne systemd als PID 1 nicht prüfbar ist:
der Aufruf über `systemd-run` und die journald-Pfade. Dort greifen Parsertests
gegen aufgezeichnete Ausgaben und ein einspeisbarer Runner.

## Wenn Sie eine Lücke finden

[SECURITY.md](../SECURITY.md) — bitte nicht als öffentliches Issue.
