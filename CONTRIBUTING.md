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
