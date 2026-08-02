# CloudSrv

Ein Hosting-Panel für einen einzelnen Linux-Server, vergleichbar mit Plesk: Der
Betreiber verwaltet Kunden, Kunden verwalten ihre Abonnements — Domains,
Webseiten, PHP, Datenbanken, DNS, Dateien, Zugänge, Cronjobs und Sicherungen —
ohne SSH und ohne Zugriff auf fremde Daten.

> **Status: P0 abgeschlossen.** Installierbar, einrichtbar, aktualisierbar —
> aber noch ohne Konten und ohne Fachfunktionen. Gebaut sind der Agent mit
> seiner Trennlinie, die Adminübersicht mit den Verlaufskacheln, Paket,
> Ersteinrichtung, Update mit Rückweg und die CI mitsamt Integrationslauf auf
> allen vier Zielplattformen. Anmeldung, Mandanten und Rechte kommen mit P1.

Der vollständige Plan mit allen elf Ausbaustufen:
**[docs/20-hostingpanel-neuplan.md](docs/20-hostingpanel-neuplan.md)**.

## Installation

```bash
curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
sudo sh install.sh
```

Danach einmal:

```bash
sudo cloudsrv setup
```

Das legt Datenbank, Anwendungsschlüssel und ein selbstsigniertes Zertifikat an,
schreibt den nginx-Server-Block und startet die Dienste. Der Lauf ist
wiederholbar und wechselt dabei keinen Schlüssel. Danach antwortet das Panel
unter `https://<host>:8443/`.

Zielplattformen sind Debian 12 und 13 sowie Ubuntu 22.04 und 24.04. Andere
Systeme werden nicht geprüft und nicht zugesagt.

**Aktualisieren** geht über `apt` oder über `cloudsrv update`. Beide Wege
führen durch dieselbe Bereitschaftsprüfung: Antwortet das Panel nach dem
Umschalten nicht, zeigt der Symlink wieder auf die vorige Fassung.

## Wie es gebaut ist

Eine PHP-Anwendung mit großer Angriffsfläche darf nicht als root laufen, muss
aber Systembenutzer anlegen und nginx neu laden. Daraus folgt die Aufteilung:

```
Browser ─TLS─▶ nginx ─▶ php-fpm „cloudsrv"   (Benutzer cloudsrv, kein root)
                     └▶ cloudsrv-worker       (Warteschlange)
                              │
                              └─unix socket─▶ cloudsrv-agentd (root, minimal)
```

`cloudsrv-agentd` ist der einzige Prozess mit Systemrechten. Er kommt **ohne
Framework und ohne Composer-Abhängigkeiten** aus — die Menge Code, die als root
läuft, soll klein genug bleiben, um sie ganz zu lesen. Die CI prüft das.

Die Anwendung schickt keine Befehle und keine Pfade, sondern typisierte
Absichten: `service.status` mit einem Unit-Namen, nicht `systemctl show …`. Die
Vorlagen für nginx, FPM-Pools und Zonen liegen im Agenten.

**Was diese Trennung nicht leistet:** Sie schützt gegen Fehler in der
Anwendung, nicht gegen eine vollständig übernommene Anwendung. Wer die
Anwendung kontrolliert, kann gültige Aufträge stellen.

## Entwicklung

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate

# Agent unprivilegiert, für die Entwicklung
mkdir -p /tmp/cs
agent/bin/cloudsrv-agentd serve --socket=/tmp/cs/a.sock --log=/tmp/cs/a.log \
    --unprivileged --user="$(id -un)" --group="$(id -gn)" &

php artisan serve & npm run dev
```

```bash
vendor/bin/pint --test        # Stil
vendor/bin/phpstan analyse    # Typen
php artisan test              # Tests, inklusive Agent gegen echten Socket
npm run types && npm run build
```

**Sprache im Quelltext:** Bezeichner sind englisch — Dateien, Klassen,
Methoden, Variablen, Konfigurations- und Protokollschlüssel, CSS-Marken,
Job-Namen in der CI. Kommentare, Dokumentation und die Texte der Oberfläche
sind deutsch; für letztere gilt
[docs/19-sprache-der-oberflaeche.md](docs/19-sprache-der-oberflaeche.md).
Die Begründung steht in §2 des Plans.

## Lizenz

[AGPL-3.0-only](LICENSE). Wer das Panel über das Netz benutzt, kommt über den
Link in der Fußzeile an den Quelltext der laufenden Fassung — das ist die
Auflage aus Abschnitt 13 und der Grund, warum dort Version und Commit stehen.
