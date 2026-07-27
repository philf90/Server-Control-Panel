# 10 — TLS: selbstsigniert und Let's Encrypt (ACME)

Beim ersten Start erzeugt das Panel ein **selbstsigniertes** Zertifikat. Es
verschlüsselt die Verbindung, aber der Browser warnt — nur der Abgleich des
Fingerprints belegt, dass die Verbindung echt ist. Wer einen auflösenden Namen
hat, holt sich stattdessen ein von Browsern anerkanntes Zertifikat über
**ACME (Let's Encrypt)**.

| Modus | Bedeutung |
|---|---|
| `selfsigned` | Vorgabe. Selbstsigniertes Zertifikat, im Panel unter **Zertifikat** und über `asylum cert status` einsehbar. |
| `acme` | Zertifikat von Let's Encrypt. Solange keins bezogen ist — oder wenn der Bezug scheitert — bleibt das selbstsignierte im Einsatz. |

## Eingestellt wird im Panel

**Alles auf dieser Seite lässt sich unter „Zertifikat" in der Oberfläche
einstellen.** Eine Konfigurationsdatei muss dafür niemand anfassen — für ein
Control Panel wäre alles andere eine merkwürdige Zumutung. Die folgenden
Abschnitte erklären, was die Felder bedeuten und was hinter den Kulissen
passiert; wer lieber Dateien bearbeitet, findet weiter unten die YAML-Fassung.

Was im Panel gespeichert wird, landet in einer eigenen Datei:

```
/etc/asylum/config.yaml        gehört dem Betreiber, wird nie umgeschrieben
/etc/asylum/conf.d/10-tls.yaml vom Panel verwaltet, bei jedem Speichern neu
/etc/asylum/conf.d/*.yaml      eigene Ergänzungen; höherer Name gewinnt
```

Die Dateien in `conf.d` werden nach der Hauptdatei in Namensreihenfolge
gelesen. Wer eine Einstellung von Hand festhalten will, die das Panel nicht
überschreiben soll, legt sie in eine Datei mit höherer Nummer, etwa
`90-eigen.yaml`.

Eine Änderung greift **sofort**: Der Bezug wird mit den neuen Werten neu
angestoßen, ohne den Dienst neu zu starten. Ein Panel, das sich für eine
Einstellung selbst abschießt, wäre genau dann weg, wenn man sehen will, ob die
Einstellung stimmt.

Der Knopf **Jetzt beziehen** fordert sofort ein Zertifikat an, auch wenn das
vorhandene noch gültig ist — gedacht, um eine geänderte Einstellung zu prüfen.
Er zählt auf die Rate-Limits der CA; zum Ausprobieren gibt es daneben das
Testverzeichnis. Was dabei geschieht, steht darunter im Verlauf (siehe
[Der Verlauf eines Bezugs](#der-verlauf-eines-bezugs)).

Das Cloudflare-Token wird im Panel eingegeben, aber **nicht** in der
Konfiguration abgelegt: Es landet in `<paths.data>/acme/cloudflare.token` mit
Rechten 0600 und wird nie wieder angezeigt. Ein leeres Feld beim Speichern
lässt ein bereits hinterlegtes Token unangetastet.

## Grundsatz: nie ausfallen

Der Bezug läuft im Hintergrund und tauscht das Zertifikat ohne Neustart aus. Er
darf den Start nie verhindern: Schlägt er fehl, bleibt das selbstsignierte
Zertifikat. Ein Panel, das wegen einer gescheiterten ACME-Anfrage nicht mehr
startet, wäre schlimmer als eines mit Browser-Warnung.

Erneuert wird rund 30 Tage vor Ablauf. Nach einem Fehlversuch wartet der Daemon
eine Stunde, statt in einer Schleife anzufragen — die Rate-Limits von Let's
Encrypt sind streng.

## Das Prüfverfahren wählen

Let's Encrypt muss prüfen, dass der Server wirklich zur Domain gehört. Dafür
gibt es zwei Wege:

- **HTTP-01** — Let's Encrypt ruft `http://<domain>/.well-known/acme-challenge/…`
  auf **Port 80** ab. Der Panel-Listener dafür läuft nur während der
  Ausstellung. **Läuft auf Port 80 bereits ein Webserver, geht das nicht** —
  das Binden scheitert, und der Daemon fällt zurück.
- **DNS-01** — das Panel legt einen TXT-Record `_acme-challenge.<domain>` an.
  Kommt **ohne Port 80** aus und kann auch Wildcards. Der Preis ist Schreibzugriff
  auf die DNS-Zone.

Ist ein DNS-Anbieter konfiguriert, wählt das Panel **automatisch DNS-01**, sonst
HTTP-01. Mit `acme.challenge` lässt sich das festnageln (`http-01` / `dns-01`).

## Dieselben Einstellungen als YAML

Wer die Werte lieber in eine Datei schreibt — etwa aus einem
Konfigurationsmanagement heraus — nutzt dieselben Felder. Sie gehören in
`/etc/asylum/config.yaml` oder in eine eigene Ergänzung unter `conf.d`.

```yaml
server:
  tls:
    mode: acme

acme:
  email: admin@example.com      # Kontakt des ACME-Kontos (Ablaufwarnungen)
  domains: [panel.example.com]   # leer = der vollqualifizierte Rechnername
  directory_url: ""              # leer = LE-Produktion; zum Testen das Staging
  challenge: ""                  # leer = automatisch | http-01 | dns-01

  http01:
    open_firewall: false         # (siehe unten)

  dns01:
    provider: hook               # hook | cloudflare
    hook:
      set:   /etc/asylum/acme-hook
      clean: /etc/asylum/acme-hook
    cloudflare:
      api_token_file: /etc/asylum/acme/cloudflare.token
```

### DNS-01 über einen Hook

Der Hook ist der universelle Weg: Das Panel ruft ein vom Betreiber gestelltes
Programm, das den TXT-Record setzt und wieder entfernt. So steckt **kein
DNS-Anbieter im Binary** — der Hook funktioniert mit jedem Anbieter, für den es
ein Skript gibt.

Aufruf:

```
<programm> set   _acme-challenge.<domain> <wert>
<programm> clean _acme-challenge.<domain> <wert>
```

Dieselben Angaben stehen zusätzlich in der Umgebung: `ASYLUM_ACME_ACTION`,
`ASYLUM_ACME_DOMAIN`, `ASYLUM_ACME_RECORD`, `ASYLUM_ACME_VALUE`. `set` und
`clean` dürfen auf dasselbe Skript zeigen — die Aktion steht im ersten Argument.

Ein Beispiel-Skript sollte nach dem Setzen warten, bis der Record ausgebreitet
ist; das Panel wartet zwar selbst best effort, kennt aber die
Ausbreitungszeiten des Anbieters nicht.

### DNS-01 über Cloudflare

Für den häufigen Fall ohne eigenes Skript: Das Panel setzt den TXT-Record direkt
über die Cloudflare-API (reines HTTP, keine Bibliothek). Der API-Token kommt aus
einer Datei mit den Rechten `0600` — **nicht** im Klartext in die Konfiguration.
Der Token braucht die Berechtigung *Zone → DNS → Bearbeiten* für die betroffene
Zone.

## Testen gegen das Staging

Die Produktion von Let's Encrypt hat harte Rate-Limits. Zum Ausprobieren das
**Staging-Directory** setzen:

```yaml
acme:
  directory_url: https://acme-staging-v02.api.letsencrypt.org/directory
```

Das Staging stellt Zertifikate aus, denen Browser nicht vertrauen — aber der
ganze Ablauf lässt sich damit gefahrlos durchspielen.

## Nachsehen

- Im Panel: **Sicherheit → Zertifikat** — Herkunft, Namen, Aussteller,
  Restlaufzeit, Fingerprint.
- Auf der Kommandozeile:

  ```
  sudo asylum cert status
  ```

## Der Verlauf eines Bezugs

Unter den Einstellungen steht **Verlauf des Bezugs**: Schritt für Schritt, was
gerade passiert, und zwar während es passiert — die Seite schreibt sich von
selbst fort (Server-Sent Events, kein Nachladen nötig).

Das ist kein Beiwerk. Ein Bezug über DNS-01 wartet bis zu zwei Minuten darauf,
dass der TXT-Record im öffentlichen DNS auftaucht, und danach unbestimmt lange
auf die CA. Ohne Meldungen dazwischen steht die Seite minutenlang still, und ein
Fehlschlag kommt als ein einziger Satz zurück, aus dem nicht hervorgeht, an
welcher Stelle es gehakt hat. Ein typischer Ablauf:

```
Bezug für: panel.example.org
Kontoschlüssel bereit
Bei Let's Encrypt (Testverzeichnis) angemeldet als admin@example.org
Prüfverfahren: dns-01
Auftrag angelegt, 1 Autorisierung(en) zu erledigen
_acme-challenge.panel.example.org: TXT-Record gesetzt
_acme-challenge.panel.example.org: warte auf Sichtbarkeit im DNS (bis zu 2m0s)
_acme-challenge.panel.example.org: sichtbar nach 12s
panel.example.org: Prüfung angestoßen, warte auf Let's Encrypt (Testverzeichnis)
panel.example.org: bestätigt
_acme-challenge.panel.example.org: TXT-Record entfernt
Schlüssel erzeugt, Zertifikatsanforderung eingereicht
Zertifikat abgeholt, Kette aus 2 Zertifikat(en)
Zertifikat eingesetzt, gültig bis 2026-10-25 09:14 UTC
Fertig.
```

Dazu drei Zusagen:

- **Der Vorgang gehört nicht dem Browser.** Er läuft weiter, wenn die Seite
  geschlossen wird — ein abgebrochener Bezug hinterließe einen halb angelegten
  ACME-Auftrag. Wer später zurückkommt, bekommt den ganzen bisherigen Ablauf.
- **Auch die selbsttätige Erneuerung schreibt mit.** Läuft sie vor Ablauf von
  allein, steht am nächsten Morgen der Verlauf da statt einer Logzeile. Wer sie
  angestoßen hat, steht darunter — bei einer Erneuerung „automatisch".
- **Es stehen keine Geheimnisse darin.** Die Zeilen gehen in den Browser und
  bleiben im Puffer stehen; der Challenge-Wert, der Kontoschlüssel und die
  Zugangsdaten des DNS-Anbieters tauchen deshalb nie auf. Der *Name* des
  TXT-Records schon — ohne ihn kann niemand nachsehen, ob der Eintrag angekommen
  ist. Ein Test wacht darüber.

Der Verlauf liegt im Arbeitsspeicher: Er bleibt bis zum nächsten Bezug stehen
und beginnt nach einem Neustart des Dienstes von vorn. Was dauerhaft
nachvollziehbar sein muss, steht im Audit-Log (`tls.obtain`, `tls.settings`).

Ein Bezug zur Zeit: Der Knopf und die Erneuerung im Hintergrund teilen sich eine
Sperre. Sonst schrieben beide in dasselbe Verzeichnis, und ihre Zeilen liefen
ineinander.

## Offen

- **`http01.open_firewall`** ist vorgesehen, aber noch ohne Wirkung: Ein
  gefahrloses „Port 80 kurz öffnen" braucht eine eigene Firewall-Primitive
  (das vorhandene `FirewallApply` setzt den ganzen Regelsatz). Bis dahin muss
  Port 80 für HTTP-01 von außen erreichbar sein; DNS-01 umgeht das. **Im Panel
  gibt es dafür deshalb kein Kästchen** — eine Bedienmöglichkeit ohne Wirkung
  sieht aus wie eine Zusage. Wer den Wert in der Konfigurationsdatei gesetzt
  hat, behält ihn; das Panel fasst ihn nicht an.
- Der Live-Weg gegen einen echten CA wird gegen das Staging auf einem echten
  Server geprüft; in der Entwicklungsumgebung ohne öffentlichen Namen ist das
  nicht möglich.
