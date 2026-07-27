# 11 — Passkeys (WebAuthn)

Passkeys sind ein **zusätzlicher zweiter Faktor** neben der Authenticator-App:
Fingerabdruck, Gesicht oder ein Sicherheitsschlüssel statt eines abgetippten
Codes. Sie ersetzen in dieser Stufe weder Passwort noch TOTP — sie treten daneben.

## Warum überhaupt

Ein TOTP-Code lässt sich abtippen, mitlesen und auf einer nachgebauten Seite
abfangen. Ein Passkey ist ein privater Schlüssel, der das Gerät nie verlässt,
und die Signatur ist an den Ursprung (die Domain) gebunden — eine Phishing-Seite
unter anderem Namen bekommt keine gültige Antwort. Für ein Panel, das im offenen
Netz steht, ist das der stärkste Zugewinn nach der Zwei-Faktor-Pflicht selbst.

## Bewusst additiv

In 0.3.0 hat jedes Konto weiterhin TOTP, und die Wiederherstellungscodes bleiben
der Notnagel. Das ist kein halber Schritt, sondern die sichere Reihenfolge:

- **Kein Aussperren.** Geht das Gerät mit dem Passkey verloren, führen TOTP und
  Wiederherstellungscode weiter hinein. Einen Passkey zu entfernen kann niemanden
  aussperren.
- **Der Rückweg ohne JavaScript bleibt.** Die Passkey-Anmeldung braucht ein
  Skript und einen Browser mit WebAuthn. Wer beides nicht hat, meldet sich
  unverändert mit Passwort und Code an — dasselbe Formular, derselbe Weg wie
  vorher.

Der vollständige Ersatz von Passwort und TOTP durch einen Passkey ist ein
späterer Schritt (siehe [Roadmap](06-roadmap.md)), mit ausdrücklicher Zustimmung
und erst, wenn sich der additive Betrieb bewährt hat.

## Voraussetzung: ein auflösbarer Name

WebAuthn bindet jeden Passkey an eine **RP-ID** — eine registrierbare Domain, kein
Schema, kein Port. Daraus folgt:

- Das Panel muss über einen **Hostnamen** erreichbar sein (`panel.example.org`),
  nicht über eine IP-Adresse. Über eine IP funktioniert WebAuthn nicht; die
  Ausnahme ist `localhost` für die Entwicklung.
- Ein Passkey gilt genau für eine RP-ID. Wer das Panel unter mehreren Namen
  erreicht, kann sich mit dem Passkey nur unter dem Namen anmelden, unter dem er
  registriert wurde.

Das verzahnt sich mit dem TLS-Zertifikat: derselbe Name, der im Zertifikat steht
([10-tls-acme.md](10-tls-acme.md)), ist die natürliche RP-ID.

## Konfiguration

```yaml
auth:
  webauthn:
    enabled: true                         # Vorgabe: false
    rp_id: panel.example.org              # leer = aus Zertifikatsnamen/FQDN ableiten
    display_name: Project Asylum          # steht im Anmeldedialog des Browsers
    origins:                              # leer = https://<rp_id>:<panel-port>
      - https://panel.example.org:8443
```

Bleibt `enabled` aus oder findet sich kein auflösbarer Name (nur eine IP), zeigt
das Panel den Passkey-Abschnitt gar nicht erst an — die übrige Anmeldung ist
davon unberührt. `rp_id` und `origins` leer zu lassen ist der Normalfall: Das
Panel leitet sie aus den Zertifikatsnamen bzw. dem vollqualifizierten
Rechnernamen und dem eigenen Port ab. Wer hinter einem Reverse-Proxy unter einem
anderen Ursprung erreichbar ist, trägt ihn unter `origins` ein.

## Was gespeichert wird

Je registriertem Authenticator eine Zeile in `webauthn_credentials`: die
eindeutige Credential-Kennung, ein Name zur Anzeige, der Zeitpunkt, und das
Credential der Bibliothek als JSON — darin der **öffentliche** Schlüssel, der
Sign-Count und die Flags. Ein Datenbankabzug erlaubt damit keine Anmeldung: Der
private Schlüssel liegt ausschließlich im Authenticator.

## Entwurfsentscheidungen

- **Bibliothek statt Eigenbau.** Die Zeremonien laufen über
  `github.com/go-webauthn/webauthn`. WebAuthn ist zu umfangreich (CBOR, COSE,
  mehrere Attestierungsformate, Signaturprüfung über verschiedene Verfahren), um
  es für einen sicherheitskritischen Pfad selbst zu schreiben. Das Panel liefert
  nur den Adapter — Benutzertyp, Persistenz, den kurzlebigen Challenge-Speicher.
- **Attestierung „none".** Das Panel prüft nicht die Herkunft des Authenticators
  (Hersteller, Modell). Ein selbst gehosteter Server hat keinen Grund, bestimmte
  Fabrikate vorzuschreiben; die Attestierung zu prüfen brächte nur eine
  Datenschutzfrage und keinen Sicherheitsgewinn.
- **Klon-Erkennung, aber kein Aussperren.** Sinkt der Sign-Count einer Anmeldung
  unter den gespeicherten Wert, kann ein geklonter Authenticator im Umlauf sein.
  Das Panel vermerkt den Hinweis im Log und im Audit, lehnt die Anmeldung aber
  nicht ab: Ein zu Recht wiederhergestellter Schlüssel soll niemanden aussperren.
- **Challenge im Arbeitsspeicher.** Die Challenge zwischen dem ersten und zweiten
  Schritt liegt im Speicher des Prozesses, ein Token je Zeremonie, gültig genau
  einmal und für zwei Minuten. Ein Neustart verwirft nur laufende Versuche — der
  Nutzer beginnt sie neu.

## Der Anmeldefluss

Die Anmeldung mit Passkey ist zweistufig, weil die Assertion einen Austausch mit
dem Server und ein Skript braucht:

1. Benutzername und Passwort gehen an den Server (`/login/passkey/begin`), geprüft
   wie beim gewöhnlichen Login samt Ratenbegrenzung. Der Server liefert die
   Assertion-Optionen und legt die Challenge unter einem kurzlebigen,
   HttpOnly-Vorab-Cookie ab. Dieses Cookie gewährt **keinen** Zugriff — es
   markiert nur „Passwort geprüft, Passkey steht aus".
2. Der Browser lässt den Authenticator signieren; die Antwort geht an
   `/login/passkey/finish`. Erst wenn die Signatur gegen die serverseitige
   Challenge stimmt, entsteht eine Sitzung.

Beide Schritte laufen ohne CSRF-Token — es gibt ja noch keine Sitzung. Der Schutz
gegen fremde Seiten ist die Bindung der Assertion an den Ursprung, die WebAuthn
selbst leistet.

## Rettungsweg über SSH

Fällt ein Gerät aus oder muss ein Konto aufgeräumt werden, gibt es die
Kommandozeile auf dem Server:

```bash
asylum passkey list philipp            # hinterlegte Passkeys mit Kennung anzeigen
asylum passkey remove philipp --id 3   # einen entfernen
asylum passkey remove philipp --all    # alle entfernen
```

Anlegen geht bewusst nur im Panel — dafür braucht es den Browser und den
Authenticator. Da TOTP als zweiter Faktor bestehen bleibt, sperrt das Entfernen
eines Passkeys niemanden aus.

## Browser-Unterstützung

Passkeys funktionieren in aktuellen Fassungen von Chrome/Edge, Firefox und Safari
sowie auf iOS und Android. Ältere Browser ohne WebAuthn sehen den Passkey-Knopf
nicht; für sie bleibt die Anmeldung mit Passwort und Code.
