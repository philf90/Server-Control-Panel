# Mailversand

Verbindlich, weil geprüft: `tests/Feature/MailSettingsTest.php`.

## 1. Ein Relay, kein eigener Versand

Das Panel stellt nicht selbst zu. Wer das tut, braucht einen MTA auf demselben
Server, einen sauberen PTR, SPF, DKIM und eine IP mit Ruf — und wenn eines
davon fehlt, landet die Mail im Spam, ohne Rückmeldung. Das ist der Fehler, den
man nicht bemerkt: Der Einmal-Link wurde verschickt, das Protokoll sagt
„erfolgreich", und der Kunde wartet.

Über ein Relay geht sie über einen Absender, der das alles schon hat. Der
Betreiber trägt die Zugangsdaten einmal ein, unter **Server → Mailversand**.

## 2. Wo die Einstellungen liegen

In der Tabelle `settings`, unter dem Schlüssel `mail`, **als Ganzes
verschlüsselt** (`encrypted:array`, Schlüssel ist der `APP_KEY` aus
`/etc/srvpanel/panel.env`).

Nicht einzelne Felder: Wer je Feld verschlüsselt, muss bei jedem neuen Feld
daran denken — und wer einmal nicht daran denkt, legt einen fremden Zugang im
Klartext ab, ohne dass es auffällt. Ein Test liest die Spalte roh und stellt
sicher, dass weder Passwort noch Servername darin zu finden sind.

**Warum nicht `/etc/srvpanel/panel.env`.** Diese Datei schreibt der Agent, und
dann liefe jede Änderung an einer Einstellung als privilegierte Operation über
den Socket. Für Werte, die ohnehin verschlüsselt in der Datenbank landen, ist
das eine Schicht zu viel.

## 3. Das Passwort verlässt den Server nicht wieder

Es steht in **keiner** Antwort. Das Formular zeigt statt dessen, *ob* eines
hinterlegt ist, und lässt das Feld leer.

Daraus folgt die Regel, die sonst Zugänge verliert: **Ein leeres Passwortfeld
heißt „unverändert" und nicht „gelöscht".** Ohne sie räumte jedes Speichern
des Ports die Anmeldung am Relay ab. Entfernen geht über ein eigenes Häkchen.

## 4. Die Konfiguration entsteht erst beim Versand

`SrvPanelServiceProvider` hängt sich mit `resolving(MailManager::class)` ein —
das läuft, sobald zum ersten Mal jemand eine Mail verschickt, und sonst nie.
Die Einstellungen bei jedem Seitenaufruf zu lesen wäre eine Datenbankabfrage
für etwas, das ein Panel ein paar Mal am Tag braucht.

Ist nichts hinterlegt, bleibt es bei dem, was in der Konfiguration steht — auf
einem frisch installierten Server ist das `log`, und eine Mail landet in der
Datei statt im Nichts.

Zwei Feinheiten, die im Betrieb wehtun:

- **`none` ist kein Verfahren.** Laravel erwartet `tls`, `ssl` — oder gar
  nichts. Stünde „none" in der Konfiguration, fiele der Transport mit einer
  Meldung aus, die nach einem Fehler des Relays aussieht.
- **Ein unlesbarer Wert darf das Panel nicht anhalten.** Wechselt der
  `APP_KEY`, sind die abgelegten Zugangsdaten nicht mehr zu entschlüsseln. Die
  Antwort darauf sind leere Einstellungen und kein Fehler: Ohne Mailversand
  läuft das Panel weiter, mit einer Ausnahme beim Hochfahren nicht mehr.

## 5. Die Testmail geht an die eigene Adresse

An die eigene und an keine andere. Ein Feld für den Empfänger machte aus dieser
Seite ein Formular, mit dem sich über das Relay des Betreibers an beliebige
Adressen schreiben ließe — mit seinem Absender und auf seinen Ruf.

**Der Fehler wird angezeigt, nicht nur „hat nicht geklappt".** Was hier
schiefgeht, ist fast immer eine Auskunft des Relays: falsches Passwort, Port
zu, Zertifikat abgelehnt. Genau die braucht der Betreiber, und sie steht sonst
nur im Protokoll der Anwendung, an das er auf einem Server ohne Shell nicht
herankommt.

## 6. Was im Protokoll steht

`settings.mail.updated` trägt Server, Port, Verschlüsselung und Absender —
nicht das Passwort und nicht seine Länge. `settings.mail.tested` trägt den
Empfänger, im Fehlerfall die Meldung des Relays.

## 7. Was darauf aufbaut

Der **Einmal-Link** zum Setzen eines Passworts (§15 des Plans) war bis hierher
blockiert: Ohne Versand führt er ins Leere. Dazu kommen später die Warnung bei
erreichtem Kontingent und die Meldung über einen fehlgeschlagenen
Sicherungslauf.
