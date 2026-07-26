# 07 — Name, Lizenz, Domain

## Entschieden

| Punkt | Entscheidung |
|---|---|
| **Scope** | A — Server-Administrations-Panel. Hosting-Funktionen (vHosts, PHP, Mail, DNS) sind Nicht-Ziele bzw. später optionale Module. |
| **Lizenz** | Apache-2.0 |
| **Name** | **Project Asylum** — Binary `asylum`, Daemon `asylumd`, Paket `asylum` |
| **Domain** | `repo.cloudsrv24.de` — ein Host für Installer, Update-Metadaten und APT-Repository |

---

## Name: Project Asylum

*Asylum* im Sinne von **Zufluchtsort, sicherer Ort** — nicht im Sinne der Anstalt.
Das Panel ist der Ort, an dem ein Server sicher, überschaubar und beherrschbar
bleibt. Diese Lesart muss die Bildsprache konsequent tragen (Schild, Zuflucht, Hafen),
sonst setzt sich die andere durch.

### Namensraum im Produkt

| Ebene | Wert |
|---|---|
| Projektname | Project Asylum |
| CLI / Symlink | `asylum` |
| Daemon / Binary | `asylumd` |
| Debian-Paket | `asylum` |
| Systembenutzer | `asylum` |
| Pfade | `/etc/asylum/`, `/var/lib/asylum/`, `/var/log/asylum/` |
| Env-Präfix | `ASYLUM_` |

### Das Akronym

Die sechs Buchstaben decken exakt den MVP-Funktionsumfang aus
[03-funktionsumfang.md](03-funktionsumfang.md) ab — das Akronym ist damit kein
nachträglicher Wortwitz, sondern die Modulliste:

| | Begriff | Deckt ab |
|---|---|---|
| **A** | **Administration** | systemd-Dienste, apt-Pakete, Cron & Timer, Dateien |
| **S** | **Security** | nftables-Firewall, SSH-Härtung, Systembenutzer & SSH-Keys, 2FA, RBAC, Audit-Log |
| **Y** | **YAML** | deklarative, versionierbare Konfiguration in `/etc/asylum/config.yaml` statt versteckter Datenbank-Einstellungen |
| **L** | **Logs** | journald: filtern, live folgen, exportieren |
| **U** | **Updates** | System-Updates (apt, unattended-upgrades) und Panel-Updates mit Rollback |
| **M** | **Monitoring** | Live-Metriken, Dashboard, Schwellwerte und Benachrichtigungen |

> **Administration · Security · YAML · Logs · Updates · Monitoring**

### Alternative Belegungen je Buchstabe

Falls die Schwerpunkte sich verschieben, funktionieren auch diese — die Tabelle oben
ist die empfohlene Kombination, weil jeder Begriff genau einem Modul entspricht und
keiner doppelt belegt ist:

| | Alternativen |
|---|---|
| **A** | Automation · Access · Audit · Agent · Alerting · Apt |
| **S** | Services · Systemd · Storage · Supervision · SSH · Sicherheit |
| **Y** | Yardstick (Schwellwerte, Benchmarks) · Yield (Auslastung, Durchsatz) |
| **L** | Lifecycle · Least-Privilege · Load · Linux |
| **U** | Users · Uptime · Unattended · Upgrades |
| **M** | Metrics · Management · Maintenance · Modules |

Zum **Y**: Das ist der einzige harte Buchstabe. `YAML` ist die stärkste Wahl, weil es
etwas Konkretes über das Produkt aussagt (Konfiguration ist eine lesbare Datei, kein
Datenbankfeld) statt ein Füllwort zu sein. Ein rein deutsches Akronym ist nicht
möglich — im Deutschen existiert praktisch kein passendes Y-Wort.

### Bekannte Nachteile des Namens

Dokumentiert, damit die Entscheidung bewusst getroffen ist:

1. **Konnotation im Englischen:** Umgangssprachlich ist *asylum* zuerst die
   psychiatrische Anstalt (*lunatic asylum*) — ein Begriff mit stigmatisierender
   Geschichte. Die Lesart „Zuflucht" ist korrekt, aber nicht die erste, die ein
   englischsprachiger Nutzer abruft.
2. **Konnotation im Deutschen:** *Asyl* ist migrationspolitisch aufgeladen. Bei einem
   Projekt mit deutschem Absender wird der Name mitgelesen.
3. **Suchbarkeit:** Der Namensraum ist gut gefüllt — Asylum Records (Warner), The
   Asylum (Filmstudio), [Asylum-os](https://github.com/Asylum-os) (Buildroot-basiertes
   OS), [SDL Asylum](https://github.com/M-HT/asylum) (Spiele-Port). Keines davon ist
   ein Server-Tool, es gibt also keine Verwechslungsgefahr im Anwendungsfeld — aber
   organische Suchtreffer sind schwerer zu erobern.

Gegenmaßnahmen: durchgängig **„Project Asylum"** als Wortmarke verwenden (die Phrase
ist deutlich unterscheidbarer als das Einzelwort), Bildsprache und Claim eindeutig auf
Zuflucht/Schutz ausrichten, und in `README`/Landingpage den Namen in einem Halbsatz
erklären.

### Erledigt: Debian- und Ubuntu-Namensraum

Die Prüfung ist nachgeholt und hat einen Treffer ergeben:

```
$ apt-cache policy asylum
asylum:
  Candidate: 0.3.2-3build1
     0.3.2-3build1 500 … noble/universe amd64 Packages

$ apt-cache show asylum | grep -E '^(Section|Description)'
Section: universe/games
Description: surreal platform shooting game
```

Das Paket liefert `/usr/games/asylum`. Zwei getrennte Fragen:

- **Paketname — kollidiert.** `asylum` ist vergeben, und mit 0.3.2 liegt es
  *über* unserer Fassung; apt hätte also selbst mit eingebundenem Repository das
  Spiel bevorzugt. **Entscheidung: Das Debian-Paket heißt `asylum-panel`.**
  Geprüft gegen ein echtes, signiertes apt-Repository: `apt install asylum-panel`
  löst eindeutig auf unser Paket auf. Frei sind außerdem `asylumd`,
  `project-asylum` und `asylum-server`.
- **Befehlsname — kollidiert nicht.** Unser `asylum` liegt in `/usr/bin`
  (Paket) bzw. `/usr/local/bin` (Installer), beides steht im `PATH` vor
  `/usr/games`. Da sich die Pfade unterscheiden, gibt es auch keinen
  dpkg-Konflikt: Beide Pakete könnten nebeneinander installiert sein.

Projektname, Daemon `asylumd` und Befehl `asylum` bleiben damit unverändert.

### Verbleibende Prüfschritte

Vor dem ersten öffentlichen Release:

```bash
# 1. Paket-Namensräume reservieren
#    crates.io · npmjs.com · hub.docker.com · pkg.go.dev

# 2. Marken in Klasse 9 (Software) und 42 (IT-Dienstleistungen)
#    DPMA: register.dpma.de · EUIPO: euipo.europa.eu/eSearch
#    Asylum Records und The Asylum liegen in Klasse 41 (Unterhaltung),
#    kollidieren also voraussichtlich nicht — trotzdem prüfen

# 3. GitHub-Organisation (github.com/Asylum-os und github.com/AsylumCorp existieren
#    bereits; für dieses Projekt z. B. "project-asylum" oder "asylum-panel")
```

---

## Lizenz: Apache-2.0

`LICENSE` liegt im Repository-Root.

### Was daraus konkret folgt

- **Copyright-Zeile:** Der Platzhalter im Anhang lautet aktuell
  „Server Control Panel Contributors" und sollte auf den endgültigen Projekt- bzw.
  Rechtsträgernamen angepasst werden.
- **Datei-Header:** Apache-2.0 empfiehlt einen kurzen Header je Quelldatei. Für ein
  Go-Projekt genügt in der Praxis die `LICENSE` im Root plus ein Hinweis im README;
  einheitliche Header sind optional, aber bei Nutzung durch Firmen hilfreich.
- **NOTICE-Datei:** Nur nötig, wenn Drittcode mit eigener NOTICE eingebunden wird.
- **DCO statt CLA:** Beiträge über das Developer Certificate of Origin
  (`git commit -s`). Geringere Hürde als ein CLA und für Apache-2.0 ausreichend.
- **Abhängigkeiten-Hygiene — der praktisch wichtigste Punkt:** In ein statisch
  gelinktes Binary darf kein GPL-/AGPL-Code, sonst wird das gesamte Binary von der
  GPL erfasst. Erlaubt sind MIT, BSD, Apache-2.0, ISC, MPL-2.0. In der CI erzwingen:

  ```yaml
  - name: License-Check
    run: |
      go install github.com/google/go-licenses@latest
      go-licenses check ./... \
        --disallowed_types=forbidden,restricted,reciprocal
  ```

  Das Aufrufen GPL-lizenzierter Programme als externe Prozesse (`apt`, `nft`,
  `systemctl`) ist davon nicht betroffen — das ist Nutzung, kein Linking.

---

## Domain: `repo.cloudsrv24.de`

Ein einziger Host trägt die gesamte Auslieferungskette. Statt drei Subdomains werden
Pfade verwendet:

| URL | Inhalt |
|---|---|
| `https://repo.cloudsrv24.de/` | schlichte Landingpage mit Installationsbefehl |
| `https://repo.cloudsrv24.de/install.sh` | Installer des aktuellen Stable-Releases |
| `https://repo.cloudsrv24.de/updates/stable.json` | Update-Metadaten Stable-Kanal |
| `https://repo.cloudsrv24.de/updates/beta.json` | Update-Metadaten Beta-Kanal |
| `https://repo.cloudsrv24.de/apt/` | APT-Repository (`dists/`, `pool/`, `gpg.key`) |

Alles davon sind statische Dateien → **GitHub Pages** genügt, es entstehen keine
Serverkosten.

### DNS-Konfiguration

**Empfohlen — CNAME.** Für eine Subdomain ist das der von GitHub vorgesehene Weg. Er
überlebt IP-Wechsel bei GitHub und aktiviert deren Load-Balancing:

```dns
repo.cloudsrv24.de.   3600   IN   CNAME   philf90.github.io.
```

Wichtig: `philf90.github.io` ist der **Benutzer-/Organisationsname**, nicht der
Repository-Name — es lautet also nicht `philf90.github.io/Server-Control-Panel`. Ein
CNAME-Record darf am selben Namen nicht mit anderen Records koexistieren.

**Falls nur A-Records möglich sind**, sind dies die vier Adressen von GitHub Pages
(soeben per DNS verifiziert):

```dns
repo.cloudsrv24.de.   3600   IN   A   185.199.108.153
repo.cloudsrv24.de.   3600   IN   A   185.199.109.153
repo.cloudsrv24.de.   3600   IN   A   185.199.110.153
repo.cloudsrv24.de.   3600   IN   A   185.199.111.153
```

Optional zusätzlich IPv6 (Werte laut GitHub-Dokumentation, vor dem Setzen
gegenprüfen):

```dns
repo.cloudsrv24.de.   3600   IN   AAAA   2606:50c0:8000::153
repo.cloudsrv24.de.   3600   IN   AAAA   2606:50c0:8001::153
repo.cloudsrv24.de.   3600   IN   AAAA   2606:50c0:8002::153
repo.cloudsrv24.de.   3600   IN   AAAA   2606:50c0:8003::153
```

Beide Varianten funktionieren; die Zuordnung Anfrage → Repository macht GitHub über
den `Host`-Header und die im Repository hinterlegte Custom Domain, nicht über die IP.

**Empfohlen zusätzlich — Domain-Verifizierung gegen Subdomain-Takeover.** GitHub
erzeugt dafür in den Account-Einstellungen (*Pages* → *Verified domains*) einen
TXT-Record der Form:

```dns
_github-pages-challenge-philf90.cloudsrv24.de.   IN   TXT   "<token>"
```

Ohne Verifizierung könnte ein anderer GitHub-Account dieselbe Custom Domain für sich
beanspruchen, falls das Repository je gelöscht wird — bei einer Domain, die
root-Installer ausliefert, ist das ein reales Risiko.

**CAA-Records prüfen:** Falls für `cloudsrv24.de` CAA-Records gesetzt sind, muss
`letsencrypt.org` erlaubt sein, sonst kann GitHub kein Zertifikat ausstellen:

```bash
dig +short CAA cloudsrv24.de
```

### Einrichtung auf GitHub-Seite

1. Im Repository einen Branch `gh-pages` anlegen (oder `docs/` auf `main` verwenden).
2. *Settings → Pages*: Quelle auf diesen Branch stellen.
3. *Custom domain* auf `repo.cloudsrv24.de` setzen → GitHub legt automatisch die
   Datei `CNAME` mit diesem Inhalt im Pages-Branch an. **Diese Datei darf der
   Release-Job nicht überschreiben.**
4. Nach DNS-Propagierung stellt GitHub automatisch ein Let's-Encrypt-Zertifikat aus
   (dauert bis zu einer Stunde). Danach **„Enforce HTTPS" aktivieren.**
5. `.nojekyll` im Pages-Root ablegen, sonst ignoriert Jekyll Verzeichnisse mit
   führendem Unterstrich — bei APT-Metadaten relevant.

### Sicherheitshinweis zur TLD

`.de` steht — anders als `.dev` — **nicht** in der HSTS-Preload-Liste. Ein Aufruf von
`http://repo.cloudsrv24.de/install.sh` kann also theoretisch abgefangen werden, bevor
die Weiterleitung auf HTTPS greift. Drei Maßnahmen:

1. Der dokumentierte Befehl erzwingt HTTPS explizit:
   ```bash
   curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
   ```
2. „Enforce HTTPS" in den Pages-Einstellungen (setzt einen HSTS-Header für die
   Domain — wirkt allerdings erst ab dem zweiten Aufruf).
3. Der eigentliche Schutz bleibt die **Signaturprüfung**: Der Installer verifiziert
   jedes heruntergeladene Artefakt gegen den im Skript eingebetteten minisign-Public-Key.
   Selbst ein manipulierter Transport liefert damit kein installierbares Binary.

Sollte das Projekt später eine eigene Domain bekommen, ist `.dev` wegen des
Preloadings die bessere Wahl — `repo.cloudsrv24.de` bleibt dann als Weiterleitung
bestehen.

### Grenzen von GitHub Pages

| Grenze | Wert | Bedeutung für uns |
|---|---|---|
| Repository-Größe | 1 GB empfohlen | Der APT-`pool/` mit `.deb`-Dateien wächst je Release um ca. 25 MB (amd64 + arm64) → **alte Versionen im Release-Job aussortieren**, z. B. die letzten 10 behalten |
| Bandbreite | 100 GB/Monat (weich) | Für Metadaten und `install.sh` unkritisch. Die Tarballs liegen ohnehin auf GitHub Releases, das eigene Kontingente hat |
| Builds | 10/Stunde | Bei Release-Frequenz irrelevant |
| Kein Custom Header | — | HSTS und `Cache-Control` sind nicht frei setzbar; falls das später stört, ist Cloudflare Pages oder ein Object-Storage-Bucket der Umstieg |

Wenn der APT-`pool/` zu groß wird, ist die saubere Lösung, `dists/` (Metadaten) auf
Pages zu belassen und die `.deb`-Dateien per `pool/`-Redirect von GitHub Releases
ausliefern zu lassen.

### Was der Release-Job schreibt

Bei jedem `v*`-Tag aktualisiert GitHub Actions den Pages-Branch:

```
gh-pages/
├── CNAME                      repo.cloudsrv24.de   (nicht überschreiben)
├── .nojekyll
├── index.html                 Landingpage mit Installationsbefehl
├── install.sh                 Kopie des Release-Artefakts
├── minisign.pub               öffentlicher Signaturschlüssel zum Nachprüfen
├── updates/
│   ├── stable.json
│   └── beta.json
└── apt/
    ├── gpg.key
    ├── dists/stable/…
    └── pool/main/a/asylum/asylum_<ver>_<arch>.deb
```
