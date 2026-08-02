# Mitarbeiten

Beiträge sind willkommen. Dieses Dokument sagt, was Sie brauchen und woran ein
Pull Request gemessen wird — damit niemand Arbeit investiert, die dann an einer
ungeschriebenen Regel scheitert.

## Vorab: passt es ins Projekt?

Project Asylum ist ein **Server-Administrations-Panel**, kein Hosting-Panel. Die
Nicht-Ziele stehen in [docs/03-funktionsumfang.md](docs/03-funktionsumfang.md)
und sind bewusst gewählt: vHosts, Mail, DNS und Kundenverwaltung gehören nicht
hinein.

Bei allem, was größer ist als ein Fehlerbericht, lohnt sich vorher ein Issue.
Ein abgelehnter Pull Request nach zwei Wochenenden Arbeit ist für beide Seiten
das schlechteste Ergebnis.

## Entwicklungsumgebung

Gebraucht wird nur Go — die Fassung steht in `go.mod`, es gilt die dort genannte
als Untergrenze:

```bash
git clone https://github.com/philf90/Server-Control-Panel
cd Server-Control-Panel
make test          # go test ./...
make lint          # golangci-lint
make build         # statisches Binary nach bin/
```

Zum Ausprobieren ohne Systemzugriff genügt eine eigene Konfiguration:

```bash
mkdir -p /tmp/asylum/{etc,data,log}
cat > /tmp/asylum/config.yaml <<'YAML'
server: { bind: 127.0.0.1, port: 8443,
          tls: { cert: /tmp/asylum/etc/server.crt, key: /tmp/asylum/etc/server.key } }
paths:  { data: /tmp/asylum/data, log: /tmp/asylum/log }
YAML

./bin/asylumd serve --config /tmp/asylum/config.yaml &
./bin/asylumd setup-token --config /tmp/asylum/config.yaml
```

Der ausgegebene Link führt durch die Ersteinrichtung. Ohne systemd und ohne
root fehlen die Systemmodule — die Oberfläche zeigt dann eine Meldung statt
Daten, was ein gültiger Zustand ist und getestet wird.

### Oberfläche ändern

Für Go allein ist **kein Node nötig**. Das gebaute Bündel liegt im Repository,
damit das so bleibt:

| Was | Quelle | Ergebnis | Ziel |
|---|---|---|---|
| Oberfläche (Svelte 5, Vite) | `web/` | `internal/ui/dist/` | `make ui` |

Wer daran arbeitet, braucht Node 22 und muss **das gebaute Ergebnis mit
einchecken**: Ein CI-Job baut es aus dem festgeschriebenen Lockfile nach und
vergleicht byteweise. Schlägt er an, fehlt der Lauf von `make ui` im Commit.

(Bis 0.4.1 stand hier eine zweite Kette: `packaging/editor/` baute mit esbuild
ein eigenes CodeMirror-Bundle nach `internal/ui/static/editor/cm.js` für die
Editorseite der alten Oberfläche. Mit ihr ist sie abgebaut — CodeMirror kommt
jetzt aus `web/`, aus einem Lockfile und mit einem Reproduzierbarkeits-Job.)

Daraus folgen drei Regeln für `web/`:

- **Fassungen exakt festschreiben**, kein `^` und kein `~`. Eine Nebenfassung
  mehr, und der Nachbau weicht ab.
- **Nichts in die Ausgabe, was von der Umgebung abhängt** — keine Zeitstempel,
  kein `esnext` als Ziel, keine Sourcemap. Die Begründung steht in
  `web/vite.config.js` an jeder betroffenen Einstellung.
- **Keine externe Quelle zur Laufzeit** — kein CDN, keine Schriftdatei, kein
  Bild von woanders. Die Richtlinie des Panels (`default-src 'none'`) ließe es
  nicht zu, und das ist Absicht.

Die neue Oberfläche liegt unter `/v2/` und die bestehende unverändert unter
`/`, bis die Parität steht. Der Browsertest dazu hängt hinter einer
Umgebungsvariablen, weil er Node und Chromium braucht:

```bash
ASYLUM_LEITSTAND_E2E=1 \
  ASYLUM_CHROMIUM=/pfad/zu/chrome \
  ASYLUM_NODE_PATH=/pfad/zu/node_modules \
  go test ./internal/httpd -run TestLeitstandBrowser -v
```

### Einen Eigenbau auf einem echten Server ausprobieren

Die Befunde, die zählen, kommen aus dem Betrieb: Fast alle Fehler der
Freigabekandidaten waren in der Entwicklungsumgebung unsichtbar. Ein Stand ohne
Release lässt sich deshalb direkt einsetzen — der reguläre Weg trägt ihn nicht,
und das ist Absicht: `install.sh` lädt immer aus dem Release und prüft die
Signatur, `asylum update` braucht signierte Metadaten. Beides wird nicht
umgangen, sondern beiseitegelassen.

```bash
# lokal bauen — statisch, ohne Laufzeitabhängigkeiten
make build

# hochladen und tauschen (auf dem Server als root)
scp bin/asylumd packaging/dev-deploy.sh root@server:/tmp/
ssh root@server 'chmod +x /tmp/dev-deploy.sh && /tmp/dev-deploy.sh /tmp/asylumd'
```

`packaging/dev-deploy.sh` liest den Zielpfad **aus der laufenden Unit** statt zu
raten — die curl-Installation legt das Binary unter `/usr/local/lib/asylum`, das
`.deb` unter `/usr/lib/asylum`, und wer den falschen Pfad überschreibt, hat
danach zwei Fassungen und keine Ahnung, welche läuft. Es sichert das alte
Binary, tauscht, startet und prüft die Bereitschaft; antwortet der neue Stand
nicht, rollt es von allein zurück. Der Rückweg von Hand:

```bash
/tmp/dev-deploy.sh --rollback
```

**Die Datenbank vorher sichern, sobald ein Stand eine Migration mitbringt.**
Migrationen laufen nur vorwärts; nach einem Rückweg träfe die vorherige Fassung
ein neueres Schema. Das Skript weist darauf hin, kann es aber nicht für dich
entscheiden:

```bash
systemctl stop asylumd
cp -a /var/lib/asylum/asylum.db{,-wal,-shm} /wohin/auch/immer/
```

Bis zur Übersicht des Leitstands bringt kein Stand eine Migration mit; die
ersten kommen mit dem Job-Modell und den API-Tokens.

## Was ein Pull Request erfüllen muss

Die CI prüft das alles; lokal geht es schneller.

1. **`gofmt`, `go vet`, `golangci-lint`** ohne Befund.
2. **Tests grün**, auch mit `-race`.
3. **Testabdeckung** hält die Schwellen in `.github/workflows/ci.yml`. Sie sind
   eine Sperrklinke gegen Rückschritt, keine Zielwerte — sie liegen knapp unter
   dem jeweils erreichten Stand.
4. **Binärgröße < 30 MB.** Das ist eine Zusage an die Nutzer, kein Richtwert.
5. **Neue direkte Abhängigkeiten sind begründungspflichtig.** Derzeit sind es
   sechs. Für dreißig Zeilen Code eine Bibliothek zu ziehen, die man dann
   jahrelang pflegen muss, ist selten ein guter Tausch.

Testen Sie außerdem **als unprivilegiertes Konto**. Als root laufen Tests durch,
die sonst an Dateirechten scheitern — das ist schon einmal erst in der CI
aufgefallen.

## Stil

**Kommentare und Fehlermeldungen sind deutsch**, Bezeichner englisch. Das ist
ungewöhnlich und Absicht: Die Oberfläche ist deutsch, und Fehlermeldungen, die
Nutzer sehen, sollen nicht übersetzt werden müssen. `golangci-lint` hat `ST1005`
deshalb abgeschaltet.

Kommentare erklären **warum**, nicht was. Ein Kommentar, der den Code
nacherzählt, veraltet beim ersten Umbau und lügt danach. Wertvoll ist die
Überlegung, die man dem Code nicht ansieht: welche Alternative verworfen wurde,
welcher Fehler dahintersteckt, welche Annahme gilt.

Fehlermeldungen richten sich an jemanden, der gerade ein Problem hat: Was ist
passiert, womit, und was hilft weiter. `fehler beim verarbeiten` hilft niemandem.

**Sichtbare Texte sind technisch, nicht literarisch.** Beschriftungen,
Erklärsätze, Rückfragen, Fehlermeldungen und Vorgangszeilen benennen die Sache
so, wie eine Fachperson sie benennt. Wo im deutschen Fachgebrauch das englische
Wort gilt — Container, Volume, Stack, Image, Rollback, Backup, Build-Cache,
Upstream, Login-Shell, Stream, Logs, Host —, **benutzt das Panel das englische
Wort**. Eine gesuchte deutsche Entsprechung ist keine Verbesserung, sondern eine
Übersetzungsleistung, die der Lesende erbringen muss, bevor er handeln kann. Wo
umgekehrt ein gebräuchliches deutsches Wort dasselbe leistet, gewinnt es:
*Datei*, *Verzeichnis*, *Neustart*, *Vorgang*.

Für Wörter, die im Panel schon einmal falsch standen — *einspielen*, *Fassung*,
*Rückweg*, *Fläche*, *Handgriff*, *Anmeldeschale*, *Gegenstelle* und ein Dutzend
weitere — gibt es eine verbindliche Liste samt Ersatz in
[docs/19-sprache-der-oberflaeche.md](docs/19-sprache-der-oberflaeche.md). Sie
wird von `internal/ui/wortwahl_test.go` geprüft, das die Texte der Oberfläche
und die Zeichenkettenliterale des Servers durchsieht. **Kommentare sind
ausgenommen** — sie dürfen erzählen und die alten Wörter nennen.

## Tests

Ein Test soll den Fehler finden, gegen den er geschrieben ist — nicht bloß
grün sein. Konkret hat sich bewährt:

- **Fehlerfälle prüfen den Grund**, nicht nur „irgendein Fehler". Sonst bleibt
  ein Test grün, wenn die eigentliche Prüfung ausfällt und etwas anderes
  abbricht.
- **Durch die vollständige Kette testen**, wo die Kette das Problem ist. Der
  Live-Kanal war einmal tot, weil eine Middleware den `ResponseWriter`
  umhüllte; ein Test direkt gegen den Handler hätte das nie gesehen.
- **Gegen echtes Material prüfen**, wo es welches gibt. Die Signaturprüfung
  wird gegen echte minisign-Ausgabe getestet, nicht gegen die eigene
  Vorstellung vom Format.
- **Keine Systempfade.** Alles, was ein Test schreibt, gehört unter `t.TempDir()`.

## Sicherheitsrelevante Änderungen

Alles unter `internal/auth`, `internal/privops` und `internal/update` bekommt
eine genauere Durchsicht und braucht Tests für die Abweisungsfälle. Bei
`privops` gilt zusätzlich: keine Shell, Pfade aus der Allowlist, jedes Argument
im Code gebaut und nicht aus einer Zeichenkette zusammengesetzt.

**Eine Schwachstelle gehört nicht in einen Pull Request**, sondern in einen
privaten Kanal — siehe [SECURITY.md](SECURITY.md). Ein öffentlicher Patch ist
eine Anleitung für alle, die noch nicht aktualisiert haben.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/), also
`feat:`, `fix:`, `docs:`, `test:`, `chore:`, `ci:`, `refactor:`. Der Changelog
entsteht daraus.

Die Beschreibung darf ausführlich sein und soll die Überlegung festhalten, nicht
den Diff wiederholen — der steht schon daneben.

## DCO statt CLA

Jeder Commit braucht ein `Signed-off-by`:

```bash
git commit -s -m "fix: …"
```

Damit bestätigen Sie das [Developer Certificate of Origin](https://developercertificate.org/):
Sie dürfen den Beitrag unter der Projektlizenz einbringen. Ein CLA, der
Rechte an einen Einzelnen abtritt, ist nicht vorgesehen — bei Apache-2.0 und
einem Projekt dieser Größe wäre das eine Hürde ohne Gegenwert.

## Lizenz

[Apache-2.0](LICENSE). Beiträge stehen unter derselben Lizenz. Abhängigkeiten
mit copyleft-Wirkung auf das statisch gelinkte Binary sind ausgeschlossen; die
CI prüft das mit `go-licenses`.
