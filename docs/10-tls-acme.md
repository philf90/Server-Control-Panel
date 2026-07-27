# 10 — TLS: selbstsigniert und Let's Encrypt (ACME)

Beim ersten Start erzeugt das Panel ein **selbstsigniertes** Zertifikat. Es
verschlüsselt die Verbindung, aber der Browser warnt — nur der Abgleich des
Fingerprints belegt, dass die Verbindung echt ist. Wer einen auflösenden Namen
hat, holt sich stattdessen ein von Browsern anerkanntes Zertifikat über
**ACME (Let's Encrypt)**.

Beides steuert `server.tls.mode`:

| Modus | Bedeutung |
|---|---|
| `selfsigned` | Vorgabe. Selbstsigniertes Zertifikat, im Panel unter **Zertifikat** und über `asylum cert status` einsehbar. |
| `acme` | Zertifikat von Let's Encrypt. Solange keins bezogen ist — oder wenn der Bezug scheitert — bleibt das selbstsignierte im Einsatz. |

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

## Konfiguration

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

## Offen

- **`http01.open_firewall`** ist vorgesehen, aber noch ohne Wirkung: Ein
  gefahrloses „Port 80 kurz öffnen" braucht eine eigene Firewall-Primitive
  (das vorhandene `FirewallApply` setzt den ganzen Regelsatz). Bis dahin muss
  Port 80 für HTTP-01 von außen erreichbar sein; DNS-01 umgeht das.
- Der Live-Weg gegen einen echten CA wird gegen das Staging auf einem echten
  Server geprüft; in der Entwicklungsumgebung ohne öffentlichen Namen ist das
  nicht möglich.
