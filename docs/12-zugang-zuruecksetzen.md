# 12 — Zugang zurücksetzen

Was passiert, wenn jemand sein Passwort vergisst oder sein Telefon verliert.

Bis 0.3.0 gab es dafür genau einen Weg: `sudo asylum reset-password` auf der
Kommandozeile des Servers. Als Anker ist der richtig und bleibt. Als Alltag war
er zu wenig — bei einer Installation mit einem einzigen Konto bedeutete ein
vergessenes Passwort: Wer keinen SSH-Zugang zur Hand hat, kommt nicht ins Panel.

Seit 0.4.0 gibt es zwei Wege mehr. Beide sind bewusst eng gefasst.

## Warum kein Rettungsweg über E-Mail

Die naheliegende Antwort auf „Passwort vergessen" ist eine Mail mit einem Link.
Für die meisten Webanwendungen ist das richtig. Für ein Server-Admin-Panel
haben wir dagegen entschieden, und zwar aus vier Gründen:

- **Das Postfach würde zum Hauptschlüssel.** Wer die Mailbox kontrolliert,
  kontrolliert den Server. Heute braucht eine Übernahme Passwort **und** zweiten
  Faktor.
- **Ein Rettungsweg, der still versagt, ist schlechter als keiner.** Frische
  VPS-Adressen landen regelmäßig im Spam oder werden ganz blockiert. Ausgerechnet
  im Notfall nicht anzukommen ist die schlechteste Eigenschaft, die ein Notweg
  haben kann.
- **Neue Betriebsabhängigkeit und ein neues Geheimnis.** Es bräuchte einen
  SMTP-Relay samt Zugangsdaten auf dem Server. (Eine Code-Abhängigkeit wäre es
  nicht — `net/smtp` ist Standardbibliothek. Die Abhängigkeit ist betrieblich.)
- **Verpflichtend wäre ein Installationshindernis.** In internen oder
  abgeschotteten Netzen ohne Mailausgang würde die Ersteinrichtung daran
  scheitern.

Das Panel verschickt daher keine Post. Sollte später ein Mailkanal kommen, dann
zuerst für **Benachrichtigungen** („dein Zugang wurde zurückgesetzt") — die
bringen Sicherheitsgewinn, ohne einen neuen Übernahmeweg zu öffnen.

## Weg 1: Der Owner setzt einen fremden Zugang zurück

Auf der Seite **Panel-Zugänge** steht unter der Tabelle der Abschnitt „Zugang
zurücksetzen". Je Tabellenzeile führt ein Link dorthin und wählt das Konto vor.
Drei Aktionen, einzeln auslösbar:

| Aktion | Wirkung |
|---|---|
| Passwort zurücksetzen | Vergibt ein Einmalpasswort, das **genau einmal** angezeigt wird. Das Konto muss es bei der nächsten Anmeldung ersetzen. Offene Sitzungen werden beendet, eine Sperre aufgehoben. Der zweite Faktor bleibt. |
| Zweiten Faktor zurücksetzen | TOTP wird unbestätigt, die Wiederherstellungscodes werden geleert. Beim nächsten Anmelden führt der Weg durch die Einrichtung. Das Passwort bleibt. |
| Passkeys entfernen | Löscht alle hinterlegten Passkeys des Kontos — für verlorene Geräte. |

Was den Weg eng hält:

- **Nur die Owner-Rolle**, und der Owner muss sein **eigenes** Passwort mitgeben.
  Ein übernommenes Owner-Cookie soll nicht stillschweigend fremde Konten
  übernehmen können — dieselbe Rückfrage wie beim Wechsel des zweiten Faktors.
- **Das eigene Konto steht nicht zur Wahl.** Es fehlt schon in der Auswahlliste,
  und der Handler weist es zusätzlich ab. Ein Owner, der sich selbst ein
  Einmalpasswort vergibt, hätte nichts gewonnen.
- **Jede Aktion steht im Audit-Log** (`user.reset_password`, `user.reset_2fa`,
  `user.reset_passkeys`) mit Akteur, Ziel und Ergebnis. Das Einmalpasswort selbst
  steht dort nicht.

### Der Wechselzwang

Ein Einmalpasswort ist einem anderen Menschen bekannt. Es soll genau eine
Anmeldung tragen, nicht länger. Dafür trägt das Konto ein Kennzeichen
(`users.must_change_password`, Migration `0004`), solange das Passwort nicht
ersetzt ist. Wirkung:

- Nach der Anmeldung führt **jede** Seite auf `/account/password-change`.
  Vorher ist nichts anderes erreichbar — dieselbe Mechanik, mit der schon die
  Zwei-Faktor-Einrichtung erzwungen wird.
- Die Wechselseite verlangt das vergebene Passwort noch einmal (jede
  Passwortänderung im Panel tut das) und lehnt es als neues Passwort ab.
- Danach fällt das Kennzeichen weg, alle Sitzungen des Kontos werden beendet und
  die aktuelle neu aufgebaut.

Setzt der Inhaber sein Passwort selbst — auf der Kontoseite, nach einem
Passkey-Nachweis oder über `asylum reset-password` —, fällt das Kennzeichen
ebenfalls weg. Genau das war die Bedingung, die es stellt.

## Weg 2: Vergessenes Passwort, per Passkey

Weg 1 hilft nur, wenn es einen zweiten Owner gibt. Bei einer Installation mit
einem einzigen Konto — dem Normalfall — braucht es Selbstbedienung. Der Link
„Passwort vergessen?" unter dem Anmeldeformular führt nach `/login/forgot`.

Warum der Passkey und nicht der Wiederherstellungscode: Ein Code hilft hier
nicht. Er wird nur eingelöst, wenn das Passwort stimmt — und das aus gutem
Grund, sonst könnte jeder mit der Codeliste, aber ohne Passwort, die Vorräte
eines Kontos aufbrauchen. Ein Passkey dagegen ist ein an den Ursprung
gebundener, nicht abphishbarer Nachweis.

Der Ablauf:

1. **Kein Konto in der Anfrage.** Die Zeremonie läuft über *auffindbare*
   (discoverable) Passkeys: Der Browser bietet an, was er für diese Domain hat,
   der Server nennt niemanden. Damit lässt sich über diesen Weg auch nicht
   erraten, welche Anmeldenamen es gibt — eine Liste von Credential-Kennungen,
   wie sie eine gewöhnliche Assertion enthält, wäre genau das.
2. **Die Prüfung am Gerät ist Pflicht** (`userVerification: "required"`). PIN,
   Fingerabdruck oder Gesicht. Erst damit besteht der Nachweis aus zwei Teilen —
   dem Besitz des Authenticators und dem, was ihn entsperrt. Ein entwendetes,
   entsperrtes Notebook genügt nicht. Der Server prüft das Flag nach der
   Signaturprüfung noch einmal ausdrücklich.
3. **Danach ein Ticket, serverseitig und einmalig einlösbar.** Es liegt zehn
   Minuten, das Cookie dazu ist `HttpOnly`, `Secure` und `SameSite=Strict` —
   letzteres ist der CSRF-Schutz, denn eine Sitzung und damit ein CSRF-Token
   gibt es noch nicht. Ein Neustart des Dienstes verwirft offene Vorgänge.
4. **Neues Passwort setzen.** Erst hier wird das Ticket verbraucht: Ein
   Tippfehler bei der Wiederholung soll nicht bedeuten, dass der Passkey erneut
   vorzuzeigen ist. Danach sind alle Sitzungen des Kontos beendet, und die
   Anmeldung verlangt den zweiten Faktor wie gewohnt.

Ein gesperrtes Konto kommt hier nicht durch — es soll sich nicht selbst
befreien. Alle Schritte gehen durch dieselbe Ratenbegrenzung wie der gewöhnliche
Login. Kein Schritt setzt einen Wechselzwang: Das Passwort hat der Inhaber selbst
gewählt.

### Auffindbare Passkeys

Damit Schritt 1 überhaupt etwas zu bieten hat, muss der Passkey seine
Kontozuordnung im Authenticator tragen. Die Registrierung verlangt das seit
0.4.0 mit `residentKey: "preferred"` — nicht `"required"`: Ein
Sicherheitsschlüssel mit belegtem Speicher würde die Registrierung sonst
abweisen, und ein Passkey ohne diese Eigenschaft ist als zweiter Faktor
unverändert brauchbar.

Plattform-Authenticators (Touch ID, Windows Hello, Cloud-Passkeys von Apple und
Google) legen praktisch immer auffindbare Schlüssel an. Bei einem älteren, vor
0.4.0 registrierten Sicherheitsschlüssel kann es sein, dass der Browser nichts
anbietet. Dann bleibt Weg 1 oder die Kommandozeile — die Seite nennt beides.

## Weg 3 bleibt: die Kommandozeile

```bash
sudo asylum reset-password philipp             # Passwort und zweiter Faktor
sudo asylum reset-password philipp --keep-2fa  # nur das Passwort
sudo asylum passkey remove philipp --all       # alle Passkeys entfernen
```

Der Fall „weder Passkey noch zweiter Owner" muss irgendwo endlich sein, und root
auf dem Server ist der richtige Ort dafür. Ein hier gesetztes Passwort trägt
keinen Wechselzwang: Wer über SSH an seiner eigenen Installation arbeitet, hat
es selbst gewählt.

## Was geprüft ist

Auf der Ebene der Handler: Zurücksetzen mit und ohne richtiges Owner-Passwort,
die Ablehnung des eigenen Kontos, die Ablehnung für die Admin-Rolle, das
Aufheben der Sperre, das Beenden der Sitzungen des Zielkontos, der Wechselzwang
auf allen geschützten Seiten samt der Ausnahme für die Wechselseite selbst, das
Ablehnen des vergebenen Passworts als neues, Lebensdauer und Einmaligkeit der
Tickets, sowie der Vergessen-Weg ohne Ticket.

Dazu im echten Browser mit virtuellem Authenticator
(`TestPasskeyBrowserForgot`): der vollständige Weg vom „Passwort vergessen"
über die Zeremonie ohne genanntes Konto bis zum gesetzten Passwort. Und der
Gegenprobe-Durchlauf `TestPasskeyBrowserForgotWithoutUV` mit einem
Authenticator, der nichts am Gerät prüft — dort **muss** die Zurücksetzung
scheitern. Daran hängt die ganze Begründung: Besitz allein ist ein Faktor, und
ein Faktor genügt nicht, um ein Passwort zu ersetzen.
