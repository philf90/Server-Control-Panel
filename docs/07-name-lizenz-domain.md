# 07 — Name, Lizenz, Domain

## Entschieden

| Punkt | Entscheidung |
|---|---|
| **Scope** | A — Server-Administrations-Panel. Hosting-Funktionen (vHosts, PHP, Mail, DNS) sind Nicht-Ziele bzw. später optionale Module. |
| **Lizenz** | Apache-2.0 |
| **Name** | offen — Kandidaten und Prüfverfahren siehe unten |
| **Domain** | offen — Bedeutung und Optionen siehe unten |

---

## Name

### Kriterien

1. **CLI-tauglich:** 4–7 Zeichen, tippbar, keine Sonderzeichen, keine Verwechslung mit
   einem bestehenden Kommando. `scp` scheidet deshalb aus — es ist OpenSSH.
2. **Suchbar:** Ein Name, der 200 andere Treffer hat, kostet dauerhaft Sichtbarkeit.
3. **Frei:** Kein prominentes Projekt gleichen Namens, keine eingetragene Marke in
   Klasse 9/42, Domain und Paketnamen verfügbar.
4. **Aussprechbar in DE und EN**, keine Umlaute, kein ß.

### Befund der Recherche

Generische englische Wörter sind im Infrastruktur-Umfeld praktisch vollständig
belegt. Stichproben:

| Kandidat | Treffer |
|---|---|
| `vane` | Arista Network-Validation-Tool, AI-Suchmaschine (ex-Perplexica), Rust-Proxy-CLI, Bot-Detection-SDK |
| `steer` | Coding-Agent (Rust), AI-Runtime-Enforcement, Git-Deploy-Tool, Lightning-Node-Manager, CMS |
| `osprey` | mind. 8 Projekte (MRS-Toolbox, Discord Rules Engine, Voice-Typing, Rocketry, …) |
| `grip` | in Debian als Paket vorhanden (GitHub-Readme-Preview) — direkte Kommandokollision |
| `helm`, `forge`, `bolt`, `rudder`, `harbor` | jeweils prominent belegt, teils im selben Themenfeld |

Konsequenz: Erfolgreiche Projekte dieser Nische nehmen **Kunstwörter oder
ungewöhnliche Schreibweisen** — Traefik, Coolify, Dokploy, Authentik, Portainer,
Umami, Pocketbase. Das ist kein Zufall, sondern die einzige Strategie, die 2026 noch
freie Namen liefert.

### Kandidaten

| Name | Herkunft / Bedeutung | Bewertung |
|---|---|---|
| **Pult** ⭐ | *Schaltpult / Bedienpult* — die exakte deutsche Entsprechung von "control panel" | 4 Zeichen, kein Softwareprojekt dieses Namens auffindbar, in EN aussprechbar, `pult services restart nginx` liest sich gut. **Empfehlung.** |
| **Griff** | „den Server im Griff haben" | 5 Zeichen, starke Metapher; Nähe zu *Griffe* (Python-Tool) und zum Namen Griffin |
| **Hebel** | Hebelwirkung — kleines Werkzeug, große Wirkung | 5 Zeichen, softwareseitig unbelegt, in EN etwas erklärungsbedürftig |
| **Havn** | Hafen/harbour in brandbarer Schreibweise | 4 Zeichen, sehr gut suchbar; semantische Nähe zu *Harbor* (Container-Registry) |
| **Hosta** | Pflanzengattung, enthält sichtbar *host* | 5 Zeichen, im Softwarebereich unverbraucht, neutral international |
| **Servus** | süddeutsch/österreichischer Gruß, enthält *serv* | sympathisch und einprägsam, wirkt aber je nach Publikum unseriös — Wildcard |

`Pult` bringt drei Dinge zusammen, die selten zusammenkommen: es bedeutet exakt das,
was die Software ist, es ist kurz genug für die Kommandozeile, und es scheint frei zu
sein. Der leichte deutsche Einschlag ist unproblematisch — Umami, Authentik und
Traefik zeigen, dass nicht-englische Namen international funktionieren, solange sie
aussprechbar sind.

### Verbleibende Prüfschritte vor der Festlegung

Diese Prüfungen ließen sich aus der Entwicklungsumgebung heraus nicht durchführen
(die Netzwerk-Policy blockiert RDAP und `packages.debian.org`) und sollten vor der
endgültigen Wahl manuell erfolgen:

```bash
# 1. Kommandokollision in Debian/Ubuntu
apt-file search --regexp '/(usr/)?s?bin/pult$'

# 2. Paket-Namensräume
#    packages.debian.org/pult · crates.io/crates/pult · npmjs.com/package/pult
#    pkg.go.dev/search?q=pult

# 3. Domain-Verfügbarkeit
whois pult.dev   # bzw. RDAP: https://rdap.org/domain/pult.dev

# 4. Marken (Klassen 9 und 42)
#    DPMA: register.dpma.de · EUIPO: euipo.europa.eu/eSearch
#    Wichtig, sobald das Projekt kommerziell werden könnte

# 5. GitHub-Organisation
#    github.com/pult ist bereits ein Nutzerkonto → Organisation ggf. als
#    "pult-panel" oder "getpult" registrieren; der Projektname bleibt davon
#    unberührt
```

---

## Lizenz: Apache-2.0

Entschieden wie empfohlen. `LICENSE` liegt im Repository-Root.

### Was daraus konkret folgt

- **Copyright-Zeile:** Der Platzhalter im Anhang lautet aktuell
  „Server Control Panel Contributors". Sobald Projektname und ggf. Rechtsträger
  feststehen, anpassen.
- **Datei-Header:** Apache-2.0 empfiehlt einen kurzen Header je Quelldatei. Für ein
  Go-Projekt genügt in der Praxis der `LICENSE`-Datei im Root plus ein Hinweis in
  `README.md`; einheitliche Header sind optional, aber bei späterer Verwendung durch
  Firmen hilfreich.
- **NOTICE-Datei:** Nur nötig, wenn Drittcode mit eigener NOTICE eingebunden wird.
  Dann muss deren Inhalt übernommen werden.
- **DCO statt CLA:** Für Beiträge das Developer Certificate of Origin verwenden
  (`git commit -s`) statt eines Contributor License Agreements. Geringere Hürde,
  in der Community akzeptiert, für Apache-2.0-Projekte ausreichend.
- **Abhängigkeiten-Hygiene — der wichtigste Punkt:** In ein statisch gelinktes Binary
  darf kein GPL-/AGPL-lizenzierter Code eingebunden werden, sonst wird das gesamte
  Binary von der GPL erfasst. Erlaubt sind MIT, BSD, Apache-2.0, ISC, MPL-2.0
  (dateibasiertes Copyleft, unkritisch bei unveränderter Nutzung). Das ist im
  Go-Ökosystem normalerweise kein Problem, muss aber in der CI erzwungen werden:

  ```yaml
  # .github/workflows/ci.yml
  - name: License-Check
    run: |
      go install github.com/google/go-licenses@latest
      go-licenses check ./... \
        --disallowed_types=forbidden,restricted,reciprocal
  ```

  Aufrufen von GPL-Programmen als externe Prozesse (`apt`, `nft`, `systemctl`) ist
  davon nicht betroffen — das ist normale Nutzung, kein Linking.

---

## Domain — was damit gemeint ist

Gemeint ist die **Domain des Projekts**, nicht die Domain, unter der das Panel später
auf einem Kundenserver läuft. Sie wird an drei Stellen der Auslieferungskette
gebraucht:

| Hostname | Wofür | Was dort liegt |
|---|---|---|
| `get.<domain>` | Installer-URL im README und in jeder Anleitung | Weiterleitung auf `install.sh` des aktuellen Stable-Releases |
| `updates.<domain>` | Update-Prüfung der laufenden Installationen | `stable.json`, `beta.json` mit Version, Prüfsummen, Signatur |
| `apt.<domain>` | APT-Repository | `dists/`, `pool/`, signierte `Release`-Dateien |

Alle drei sind statische Dateien und können auf **GitHub Pages** liegen — es entstehen
also keine Serverkosten, nur die Domaingebühr (ca. 10–20 €/Jahr).

### Braucht es die Domain zwingend?

Nein. Es geht auch ohne, mit Einschränkungen:

| Zweck | Ohne eigene Domain | Nachteil |
|---|---|---|
| Installer | `https://github.com/<user>/<repo>/releases/latest/download/install.sh` | Lang, schwer diktierbar, GitHub-gebunden |
| Update-Metadaten | GitHub Releases API | Rate-Limit von 60 Anfragen/Stunde je IP; bei vielen Installationen hinter Provider-NAT wird das zum Problem. Eine statische JSON-Datei auf GitHub Pages umgeht das. |
| APT-Repo | `https://<user>.github.io/<repo>/apt` | Funktioniert, wirkt aber provisorisch und zementiert den GitHub-Namespace in jeder `sources.list` der Nutzer |

### Empfehlung

Eine eigene, kurze Domain passend zum Namen — z. B. `pult.dev` mit den drei
Subdomains. Zwei Argumente sprechen für **`.dev`**:

1. Die TLD steht vollständig in der **HSTS-Preload-Liste** der Browser und
   HTTP-Clients. Ein Installer-Download kann damit nicht auf HTTP heruntergestuft
   werden — bei einem Skript, das mit root-Rechten ausgeführt wird, ist das ein
   echtes Sicherheitsargument, kein Kosmetikum.
2. Kein Registrierungsvorbehalt, sofort verfügbar, günstig.

`.org` ist die klassische Alternative für Open-Source-Projekte und signalisiert
Gemeinnützigkeit. `.io` würde ich meiden (Unsicherheit um die TLD-Zukunft, hoher
Preis).

Eine Subdomain einer bestehenden Firmendomain (z. B. `pult.netzhost24.de`) ist
technisch gleichwertig, lässt das Projekt aber als Firmenprodukt erscheinen. Für ein
Community-Projekt, das Beiträge anziehen soll, ist eine neutrale Projektdomain die
bessere Wahl. Umgekehrt gilt: Wenn das Panel bewusst als Produkt von netzhost24
positioniert werden soll, ist die Subdomain die konsequentere Variante — das ist eine
Positionierungs-, keine technische Frage.

### Was zu registrieren ist, sobald der Name steht

- Domain + die drei Subdomains, DNS auf GitHub Pages
- GitHub-Organisation (ggf. mit Suffix, siehe oben)
- Namensreservierung, wo relevant: crates.io, npm, Docker Hub — auch wenn zunächst
  ungenutzt, verhindert das Namenskaperung
