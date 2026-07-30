package privops

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
)

// Die Pfadwache ist die einzige Stelle, an der ein Pfad aus einer HTTP-Anfrage
// zu einem Pfad auf der Platte wird. Kein Handler und keine Operation baut
// jemals selbst einen Pfad zusammen.
//
// Sie beantwortet vier Fragen in dieser Reihenfolge:
//
//  1. Ist die Zeichenkette überhaupt ein Pfad? (absolut, ohne Steuerzeichen,
//     in vernünftiger Länge)
//  2. Liegt er in einem freigegebenen Baum — auch nach Auflösung aller
//     Verzeichnisverweise? Ein Symlink /tmp/x → /etc ist sonst ein Umweg um
//     jede Prüfung, die nur die Zeichenkette ansieht.
//  3. Steht er auf der Sperrliste?
//  4. Ist die verlangte Art des Zugriffs dort erlaubt?
//
// Was sie ausdrücklich nicht leistet: Schutz gegen einen Angreifer, der schon
// lokal schreiben darf und ein Verzeichnis mitten im Pfad im richtigen
// Augenblick durch einen Verweis ersetzt. Gegen die letzte Komponente hilft
// O_NOFOLLOW, gegen die mittleren wäre ein Öffnen Komponente für Komponente
// nötig. Wer lokal schreiben kann, braucht das Panel dafür allerdings nicht.

// zugriff benennt, was mit einem Pfad geschehen soll.
type zugriff int

const (
	// zMeta fragt nur Metadaten ab. Auch für gesperrte Pfade erlaubt: Die
	// Oberfläche soll den Eintrag zeigen und erklären können, statt ihn
	// zu verschweigen.
	zMeta zugriff = iota
	// zInhalt liest den Inhalt — eine Datei oder die Einträge eines
	// Verzeichnisses.
	zInhalt
	// zAendern verändert den Eintrag.
	zAendern
)

// maxPfadLaenge entspricht PATH_MAX unter Linux.
const maxPfadLaenge = 4096

// maxVerweisTiefe begrenzt die Auflösung von Verweisketten, wenn
// FollowSymlinks eingeschaltet ist.
const maxVerweisTiefe = 8

// pfadwache prüft Pfade gegen die Politik.
type pfadwache struct {
	lese     []string
	schreib  []string
	verboten []deniedPath
	folgen   bool

	mu    sync.Mutex
	roots map[string]*os.Root

	// Kennungen (Gerät und Inode) der gesperrten Dateien, einmal je Prozess
	// ermittelt. Siehe geheimeKennungen.
	idOnce sync.Once
	ids    map[[2]uint64]string
}

// pfad ist ein geprüfter Pfad. Nur hierüber laufen die Operationen.
type pfad struct {
	// Roh ist der gesäuberte Pfad, wie er angefragt wurde.
	Roh string
	// Echt ist derselbe Pfad nach Auflösung der Verzeichnisverweise. Alle
	// Prüfungen laufen gegen diesen Wert, alle Operationen ebenso.
	Echt string
	// Name ist der letzte Bestandteil.
	Name string

	// Info ist das Ergebnis eines Lstat auf Echt — nil, wenn der Eintrag noch
	// nicht existiert (Ziel eines Anlegens).
	Info fs.FileInfo

	Sensibel   bool
	Grund      string
	Schreibbar bool

	root *os.Root
	rel  string
}

// istVerweis sagt, ob der Eintrag selbst ein Verweis ist.
func (p *pfad) istVerweis() bool {
	return p.Info != nil && p.Info.Mode()&fs.ModeSymlink != 0
}

// neuePfadwache baut die Wache aus einer Politik. Ein Fehler hier ist ein
// Konfigurationsfehler und soll den Start verhindern, nicht den ersten
// Seitenaufruf.
func neuePfadwache(p FilesPolicy) (*pfadwache, error) {
	w := &pfadwache{
		folgen:   p.FollowSymlinks,
		verboten: append([]deniedPath{}, builtinDeniedPaths...),
		roots:    make(map[string]*os.Root),
	}

	lese := p.ReadableRoots
	if len(lese) == 0 {
		lese = []string{"/"}
	}
	for _, r := range lese {
		norm, err := normWurzel(r)
		if err != nil {
			return nil, fmt.Errorf("files.readable_roots: %w", err)
		}
		w.lese = append(w.lese, norm)
	}
	for _, r := range p.WritableRoots {
		norm, err := normWurzel(r)
		if err != nil {
			return nil, fmt.Errorf("files.writable_roots: %w", err)
		}
		// Eine Schreibwurzel außerhalb der lesbaren Bäume wäre eine Zusage, die
		// niemand einlöst: Der Pfad ist nicht einmal sichtbar.
		if !unterEinerWurzel(norm, w.lese) {
			return nil, fmt.Errorf("files.writable_roots: %q liegt außerhalb von files.readable_roots", norm)
		}
		w.schreib = append(w.schreib, norm)
	}
	for _, d := range p.DeniedPaths {
		if !filepath.IsAbs(d) {
			return nil, fmt.Errorf("files.denied_paths: %q ist kein absoluter Pfad", d)
		}
		if _, err := filepath.Match(d, "/"); err != nil {
			return nil, fmt.Errorf("files.denied_paths: %q ist kein gültiges Muster: %w", d, err)
		}
		w.verboten = append(w.verboten, deniedPath{Pattern: filepath.Clean(d), Reason: "in der Konfiguration gesperrt"})
	}
	return w, nil
}

// normWurzel prüft und säubert eine Wurzelangabe.
func normWurzel(r string) (string, error) {
	if !filepath.IsAbs(r) {
		return "", fmt.Errorf("%q ist kein absoluter Pfad", r)
	}
	c := filepath.Clean(r)
	for _, ps := range pseudoRoots {
		if unterWurzel(c, ps) {
			return "", fmt.Errorf("%q liegt in einem Pseudo-Dateisystem", c)
		}
	}
	return c, nil
}

// schliessen gibt die offenen Wurzeln frei.
func (w *pfadwache) schliessen() {
	w.mu.Lock()
	defer w.mu.Unlock()
	for k, r := range w.roots {
		_ = r.Close()
		delete(w.roots, k)
	}
}

// wurzelFor liefert die offene Wurzel für einen bereits geprüften Pfad.
//
// Die Wurzel bleibt für die Laufzeit offen: Ein Deskriptor je Lesewurzel, und
// os.Root prüft bei jeder Operation erneut, dass keine Komponente aus dem Baum
// herausführt.
func (w *pfadwache) wurzelFor(echt string) (*os.Root, string, error) {
	beste := ""
	for _, l := range w.lese {
		if unterWurzel(echt, l) && len(l) > len(beste) {
			beste = l
		}
	}
	if beste == "" {
		return nil, "", fmt.Errorf("%w: %s liegt außerhalb der freigegebenen Bereiche", ErrDenied, echt)
	}

	w.mu.Lock()
	defer w.mu.Unlock()
	root, ok := w.roots[beste]
	if !ok {
		r, err := os.OpenRoot(beste)
		if err != nil {
			return nil, "", fmt.Errorf("%s: %w", beste, err)
		}
		w.roots[beste] = r
		root = r
	}

	rel := strings.TrimPrefix(strings.TrimPrefix(echt, beste), "/")
	if rel == "" {
		rel = "."
	}
	return root, rel, nil
}

// pruefen prüft einen Pfad, der existieren muss.
func (w *pfadwache) pruefen(roh string, z zugriff) (*pfad, error) {
	return w.pruefenTief(roh, z, 0)
}

func (w *pfadwache) pruefenTief(roh string, z zugriff, tiefe int) (*pfad, error) {
	p, err := w.aufloesen(roh)
	if err != nil {
		return nil, err
	}

	info, err := p.root.Lstat(p.rel)
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return nil, fmt.Errorf("%s gibt es nicht", p.Roh)
		}
		return nil, fmt.Errorf("%s: %w", p.Roh, err)
	}
	p.Info = info

	if err := w.erlauben(p, z); err != nil {
		return nil, err
	}

	// Verweise: Metadaten und Umbenennen/Löschen gelten dem Verweis selbst.
	// Für den Inhalt entscheidet die Politik. Ist Folgen erlaubt, wird das Ziel
	// vollständig neu geprüft — ein Verweis darf keine Abkürzung an der
	// Sperrliste vorbei sein.
	if p.istVerweis() && z == zInhalt {
		if !w.folgen {
			return nil, fmt.Errorf("%w: %s ist ein Verweis; das Panel folgt Verweisen nicht", ErrDenied, p.Roh)
		}
		if tiefe >= maxVerweisTiefe {
			return nil, fmt.Errorf("%w: %s führt über zu viele Verweise", ErrDenied, p.Roh)
		}
		ziel, err := p.root.Readlink(p.rel)
		if err != nil {
			return nil, fmt.Errorf("%s: %w", p.Roh, err)
		}
		if !filepath.IsAbs(ziel) {
			ziel = filepath.Join(filepath.Dir(p.Echt), ziel)
		}
		return w.pruefenTief(ziel, z, tiefe+1)
	}

	// Inhalt gibt es nur bei regulären Dateien und Verzeichnissen. Eine FIFO
	// würde beim Öffnen blockieren, eine Gerätedatei endlos liefern.
	if z == zInhalt && !p.Info.Mode().IsRegular() && !p.Info.IsDir() {
		return nil, fmt.Errorf("%w: %s", ErrNotRegular, p.Roh)
	}
	return p, nil
}

// pruefenNeu prüft das Ziel eines Eintrags, den es noch nicht geben muss.
//
// Geprüft wird vor allem das Elternverzeichnis: Es muss existieren, ein
// Verzeichnis sein und beschreibbar. Der Name selbst darf keinen Trenner
// enthalten — ein "Dateiname" mit Schrägstrich ist ein Pfad und käme sonst an
// der Prüfung des Elternteils vorbei.
func (w *pfadwache) pruefenNeu(dir, name string) (*pfad, error) {
	if err := pruefeName(name); err != nil {
		return nil, err
	}
	elter, err := w.pruefen(dir, zAendern)
	if err != nil {
		return nil, err
	}
	if !elter.Info.IsDir() {
		return nil, fmt.Errorf("%s ist kein Verzeichnis", elter.Roh)
	}

	p, err := w.aufloesen(filepath.Join(elter.Echt, name))
	if err != nil {
		return nil, err
	}
	if err := w.erlauben(p, zAendern); err != nil {
		return nil, err
	}
	// Lstat darf fehlschlagen: Der Eintrag soll gerade erst entstehen.
	if info, err := p.root.Lstat(p.rel); err == nil {
		p.Info = info
	}
	return p, nil
}

// aufloesen macht aus einer Zeichenkette einen geprüften Pfad, ohne den Eintrag
// selbst anzufassen.
func (w *pfadwache) aufloesen(roh string) (*pfad, error) {
	if roh == "" {
		return nil, fmt.Errorf("kein Pfad angegeben")
	}
	if !filepath.IsAbs(roh) {
		return nil, fmt.Errorf("%q ist kein absoluter Pfad", roh)
	}
	if len(roh) > maxPfadLaenge {
		return nil, fmt.Errorf("der Pfad ist zu lang (höchstens %d Zeichen)", maxPfadLaenge)
	}
	if i := strings.IndexFunc(roh, istSteuerzeichen); i >= 0 {
		// Ein NUL-Byte beendet die Zeichenkette in jedem syscall; alles dahinter
		// wäre unsichtbar. Zeilenumbrüche zerlegen Protokolle und Anzeigen.
		return nil, fmt.Errorf("der Pfad enthält ein Steuerzeichen an Stelle %d", i+1)
	}
	sauber := filepath.Clean(roh)

	if !unterEinerWurzel(sauber, w.lese) {
		return nil, fmt.Errorf("%w: %s liegt außerhalb der freigegebenen Bereiche", ErrDenied, sauber)
	}

	// Verzeichnisverweise auflösen — nur das Elternteil. Die letzte Komponente
	// bleibt stehen, damit ein Verweis als Verweis erkennbar ist und nicht
	// stillschweigend zu seinem Ziel wird.
	echt := sauber
	if sauber != "/" {
		elter, err := filepath.EvalSymlinks(filepath.Dir(sauber))
		if err != nil {
			if errors.Is(err, fs.ErrNotExist) {
				return nil, fmt.Errorf("%s gibt es nicht", filepath.Dir(sauber))
			}
			return nil, fmt.Errorf("%s: %w", filepath.Dir(sauber), err)
		}
		echt = filepath.Join(elter, filepath.Base(sauber))
	}

	// Zweite Prüfung, jetzt gegen den aufgelösten Pfad: Der Umweg über einen
	// Verweis führt sonst aus dem freigegebenen Bereich heraus.
	if !unterEinerWurzel(echt, w.lese) {
		return nil, fmt.Errorf("%w: %s führt über einen Verweis aus dem freigegebenen Bereich heraus", ErrDenied, sauber)
	}
	for _, ps := range pseudoRoots {
		if unterWurzel(echt, ps) || unterWurzel(sauber, ps) {
			return nil, fmt.Errorf("%w: %s liegt in %s — das ist keine Ablage, sondern eine Schnittstelle zum Kernel",
				ErrDenied, sauber, ps)
		}
	}

	root, rel, err := w.wurzelFor(echt)
	if err != nil {
		return nil, err
	}

	p := &pfad{
		Roh:        sauber,
		Echt:       echt,
		Name:       filepath.Base(echt),
		root:       root,
		rel:        rel,
		Schreibbar: unterEinerWurzel(echt, w.schreib),
	}
	p.Sensibel, p.Grund = w.sensibel(echt, sauber)
	return p, nil
}

// erlauben setzt die Politik auf einen aufgelösten Pfad an.
func (w *pfadwache) erlauben(p *pfad, z zugriff) error {
	if z == zMeta {
		return nil
	}
	if p.Sensibel {
		return fmt.Errorf("%w: %s ist gesperrt (%s). Über SSH ist die Datei erreichbar, über das Panel nicht",
			ErrDenied, p.Roh, p.Grund)
	}
	if z == zAendern && !p.Schreibbar {
		return fmt.Errorf("%w: %s liegt in keinem der Bereiche, in denen das Panel schreiben darf (%s)",
			ErrDenied, p.Roh, strings.Join(w.schreib, ", "))
	}
	return nil
}

// sensibel prüft beide Fassungen des Pfads gegen die Sperrliste und liefert die
// Begründung mit. Geprüft wird auch jeder Vorfahre: Ein Muster sperrt damit
// auch alles, was darunter liegt.
func (w *pfadwache) sensibel(echt, roh string) (bool, string) {
	if istOeffentlicherSchluessel(filepath.Base(echt)) {
		return false, ""
	}
	for _, kandidat := range []string{echt, roh} {
		for _, vorfahr := range vorfahren(kandidat) {
			for _, d := range w.verboten {
				if ok, _ := filepath.Match(d.Pattern, vorfahr); ok {
					return true, d.Reason
				}
			}
		}
	}
	return false, ""
}

// istSensibel ist die Kurzfassung für Läufe über Bäume, in denen kein
// vollständiger pfad gebraucht wird.
func (w *pfadwache) istSensibel(p string) bool {
	ok, _ := w.sensibel(p, p)
	return ok
}

// geheimeKennungen sind Gerät und Inode der gesperrten Dateien.
//
// Die Sperrliste vergleicht Pfade, und ein Pfad ist nicht die Datei: Ein
// Hardlink auf /etc/shadow trägt einen anderen Namen und käme an jedem
// Mustervergleich vorbei. Dasselbe gilt für einen Bind-Mount. Deshalb wird
// zusätzlich die Identität geprüft — Gerät und Inode der geöffneten Datei gegen
// die der gesperrten.
//
// Einmal je Prozess: Die Liste umfasst ein Dutzend Muster, und ein Glob je
// Lesezugriff wäre Aufwand ohne Gegenwert. Neu entstandene Dateien deckt
// weiterhin der Mustervergleich ab; die Kennungen sind das Netz für Aliase auf
// die zum Startzeitpunkt vorhandenen Geheimnisse.
func (w *pfadwache) geheimeKennungen() map[[2]uint64]string {
	w.idOnce.Do(func() {
		w.ids = make(map[[2]uint64]string)
		for _, d := range w.verboten {
			treffer, err := filepath.Glob(d.Pattern)
			if err != nil {
				continue
			}
			for _, t := range treffer {
				if istOeffentlicherSchluessel(filepath.Base(t)) {
					continue
				}
				info, err := os.Lstat(t)
				if err != nil || !info.Mode().IsRegular() {
					continue
				}
				st, ok := info.Sys().(*syscall.Stat_t)
				if !ok {
					continue
				}
				grund := d.Reason
				if grund == "" {
					grund = "gesperrt"
				}
				w.ids[[2]uint64{uint64(st.Dev), st.Ino}] = grund //nolint:unconvert // Feldtyp je Plattform verschieden
			}
		}
	})
	return w.ids
}

// pruefeKennung lehnt eine geöffnete Datei ab, die dieselbe Datei ist wie ein
// gesperrter Pfad — unter welchem Namen auch immer.
func (w *pfadwache) pruefeKennung(info fs.FileInfo, name string) error {
	st, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return nil
	}
	if grund, gesperrt := w.geheimeKennungen()[[2]uint64{uint64(st.Dev), st.Ino}]; gesperrt { //nolint:unconvert // Feldtyp je Plattform verschieden
		return fmt.Errorf("%w: %s ist dieselbe Datei wie ein gesperrter Pfad (%s)", ErrDenied, name, grund)
	}
	return nil
}

// vorfahren liefert den Pfad und alle darüberliegenden Verzeichnisse.
func vorfahren(p string) []string {
	out := []string{p}
	for {
		elter := filepath.Dir(p)
		if elter == p {
			return out
		}
		out = append(out, elter)
		p = elter
	}
}

// unterWurzel sagt, ob p auf oder unter wurzel liegt. Verglichen wird
// komponentenweise: /etc darf nicht auf /etcetera passen.
func unterWurzel(p, wurzel string) bool {
	if wurzel == "/" {
		return strings.HasPrefix(p, "/")
	}
	return p == wurzel || strings.HasPrefix(p, wurzel+"/")
}

func unterEinerWurzel(p string, wurzeln []string) bool {
	for _, w := range wurzeln {
		if unterWurzel(p, w) {
			return true
		}
	}
	return false
}

// PruefeName prüft den Namen eines neuen Eintrags — für Aufrufer außerhalb des
// Pakets.
//
// Sie steht hier und nicht als zweite Regel in der Schnittstellenschicht, weil
// zwei Fassungen derselben Prüfung auseinanderlaufen: Die eine verbietet dann den
// Schrägstrich, die andere nicht, und welche gilt, hängt vom Endpunkt ab.
// Verbindlich bleibt der Aufruf innerhalb der Wache — dies ist die Auskunft
// darüber, damit eine Eingabemaske den Grund nennen kann, statt einen
// zusammengesetzten Pfad in eine unverständliche Ablehnung laufen zu lassen.
func PruefeName(name string) error { return pruefeName(name) }

// pruefeName prüft den Namen eines neuen Eintrags.
func pruefeName(name string) error {
	switch name {
	case "":
		return errors.New("kein Name angegeben")
	case ".", "..":
		return fmt.Errorf("%q ist als Name nicht zulässig", name)
	}
	if len(name) > 255 {
		return errors.New("der Name ist zu lang (höchstens 255 Zeichen)")
	}
	if strings.ContainsRune(name, os.PathSeparator) {
		return fmt.Errorf("der Name %q darf keinen Schrägstrich enthalten — ein Pfad ist kein Name", name)
	}
	if i := strings.IndexFunc(name, istSteuerzeichen); i >= 0 {
		return fmt.Errorf("der Name enthält ein Steuerzeichen an Stelle %d", i+1)
	}
	return nil
}

// istSteuerzeichen erkennt, was in einem Pfad nichts zu suchen hat: NUL,
// Zeilenumbrüche, Tabulatoren und die übrigen C0-Zeichen, dazu die
// Schreibrichtungs-Umschalter. Letztere sind der klassische Weg, einen
// Dateinamen in einer Liste anders aussehen zu lassen, als er ist.
func istSteuerzeichen(r rune) bool {
	switch {
	case r < 0x20, r == 0x7f:
		return true
	case r >= 0x202a && r <= 0x202e: // LRE, RLE, PDF, LRO, RLO
		return true
	case r >= 0x2066 && r <= 0x2069: // LRI, RLI, FSI, PDI
		return true
	}
	return false
}
