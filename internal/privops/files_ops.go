package privops

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"
)

// Grenzen der Läufe über Bäume. Sie sind da, damit ein versehentlicher Klick auf
// ein Verzeichnis mit Millionen Einträgen den Dienst nicht lahmlegt: Das Panel
// bricht ab und sagt, dass es abgebrochen hat, statt minutenlang zu schweigen.
const (
	maxWalkEintraege = 200000
	maxWalkTiefe     = 32
	// maxSuchTreffer begrenzt das Ergebnis einer Namenssuche.
	maxSuchTreffer = 2000
	// kopierPuffer ist die Blockgröße beim Kopieren. Groß genug für Durchsatz,
	// klein genug, dass MemoryMax=256M davon nichts merkt.
	kopierPuffer = 256 << 10
)

// FileSystem ist die Umsetzung von Files gegen das echte Dateisystem.
type FileSystem struct {
	pol   FilesPolicy
	wache *pfadwache
}

// Ein Compile-Time-Nachweis, dass die Umsetzung vollständig ist.
var _ Files = (*FileSystem)(nil)

// NewFileSystem baut den Dateimanager aus einer Politik.
func NewFileSystem(p FilesPolicy) (*FileSystem, error) {
	if p.MaxEditSize <= 0 {
		p.MaxEditSize = DefaultMaxEditSize
	}
	if p.MaxUpload <= 0 {
		p.MaxUpload = DefaultMaxUpload
	}
	w, err := neuePfadwache(p)
	if err != nil {
		return nil, err
	}
	return &FileSystem{pol: p, wache: w}, nil
}

// Close gibt die offenen Wurzel-Deskriptoren frei.
func (f *FileSystem) Close() { f.wache.schliessen() }

// WritableRoots liefert die Bereiche, in denen geschrieben werden darf.
func (f *FileSystem) WritableRoots() []string {
	return append([]string{}, f.wache.schreib...)
}

// ReadableRoots liefert die sichtbaren Bereiche.
func (f *FileSystem) ReadableRoots() []string {
	return append([]string{}, f.wache.lese...)
}

// Limits liefert die Obergrenzen für Editor und Upload. Beide sind in
// NewFileSystem auf die Vorgaben gesetzt, wenn die Politik nichts nennt — hier
// steht deshalb nie eine Null.
func (f *FileSystem) Limits() (maxEdit, maxUpload int64) {
	return f.pol.MaxEditSize, f.pol.MaxUpload
}

// ------------------------------------------------------------ Namen zu IDs ---

// namensbuch löst UID und GID in Namen auf. Es wird je Aufruf einmal gefüllt:
// zwei kleine Dateien zu lesen ist billiger, als für zweitausend Einträge
// zweitausend Mal nachzusehen.
type namensbuch struct {
	users  map[int]string
	groups map[int]string
}

func ladeNamensbuch() namensbuch {
	nb := namensbuch{users: map[int]string{}, groups: map[int]string{}}
	if raw, err := os.ReadFile(passwdPath); err == nil {
		for _, u := range parsePasswd(string(raw)) {
			nb.users[u.UID] = u.Name
		}
	}
	if raw, err := os.ReadFile(groupPath); err == nil {
		_, byGID := parseGroups(string(raw))
		for gid, name := range byGID {
			nb.groups[gid] = name
		}
	}
	return nb
}

func (nb namensbuch) user(uid int) string {
	if n, ok := nb.users[uid]; ok {
		return n
	}
	return fmt.Sprintf("%d", uid)
}

func (nb namensbuch) group(gid int) string {
	if n, ok := nb.groups[gid]; ok {
		return n
	}
	return fmt.Sprintf("%d", gid)
}

// ------------------------------------------------------------------ Lesen ---

// eintrag baut einen FileEntry aus einem Lstat-Ergebnis.
func (f *FileSystem) eintrag(echt string, info fs.FileInfo, nb namensbuch) FileEntry {
	e := FileEntry{
		Name:       filepath.Base(echt),
		Path:       echt,
		Kind:       kindOf(info.Mode()),
		Size:       info.Size(),
		Mode:       info.Mode(),
		ModeOctal:  FormatMode(info.Mode()),
		ModeText:   info.Mode().String(),
		ModTime:    info.ModTime(),
		Writable:   unterEinerWurzel(echt, f.wache.schreib),
		LinkTarget: "",
	}
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		e.UID = int(st.Uid)
		e.GID = int(st.Gid)
		e.Owner = nb.user(e.UID)
		e.Group = nb.group(e.GID)
	}
	e.Sensitive, e.SensitiveReason = f.wache.sensibel(echt, echt)
	if e.Kind == KindSymlink {
		if ziel, err := os.Readlink(echt); err == nil {
			e.LinkTarget = ziel
			absZiel := ziel
			if !filepath.IsAbs(absZiel) {
				absZiel = filepath.Join(filepath.Dir(echt), absZiel)
			}
			if _, err := os.Stat(absZiel); err != nil {
				e.LinkBroken = true
			}
		}
		// Ein Verweis führt möglicherweise auf etwas Gesperrtes. Der Inhalt
		// wird ohnehin nicht ausgeliefert (das Panel folgt Verweisen nicht),
		// aber die Anzeige soll den Grund nennen können.
		if !e.Sensitive && e.LinkTarget != "" {
			absZiel := e.LinkTarget
			if !filepath.IsAbs(absZiel) {
				absZiel = filepath.Join(filepath.Dir(echt), absZiel)
			}
			if sens, grund := f.wache.sensibel(absZiel, absZiel); sens {
				e.Sensitive, e.SensitiveReason = true, grund
			}
		}
	}
	return e
}

// List liest ein Verzeichnis.
func (f *FileSystem) List(ctx context.Context, dir string, opts ListOptions) (Listing, error) {
	p, err := f.wache.pruefen(dir, zInhalt)
	if err != nil {
		return Listing{}, err
	}
	if !p.Info.IsDir() {
		return Listing{}, fmt.Errorf("%s ist kein Verzeichnis", p.Roh)
	}

	namen, err := f.dirNamen(p)
	if err != nil {
		return Listing{}, err
	}

	limit := opts.Limit
	if limit <= 0 {
		limit = maxListEntries
	}

	nb := ladeNamensbuch()
	liste := Listing{Total: 0}
	eintraege := make([]FileEntry, 0, min(len(namen), limit))

	for i, name := range namen {
		if i%256 == 0 {
			if err := ctx.Err(); err != nil {
				return Listing{}, err
			}
		}
		if !opts.ShowHidden && strings.HasPrefix(name, ".") {
			continue
		}
		liste.Total++
		if len(eintraege) >= limit {
			liste.Truncated = true
			continue
		}
		info, err := p.root.Lstat(path.Join(p.rel, name))
		if err != nil {
			// Ein Eintrag, der zwischen readdir und lstat verschwindet, ist
			// normal — etwa eine Sitzungsdatei unter /run. Er fehlt dann eben.
			continue
		}
		eintraege = append(eintraege, f.eintrag(filepath.Join(p.Echt, name), info, nb))
	}

	sortiere(eintraege, opts)
	liste.Entries = eintraege
	liste.Dir = f.eintrag(p.Echt, p.Info, nb)
	if p.Echt != "/" {
		liste.Parent = filepath.Dir(p.Echt)
	}
	return liste, nil
}

// dirNamen liest die Namen eines Verzeichnisses.
func (f *FileSystem) dirNamen(p *pfad) ([]string, error) {
	d, err := p.root.Open(p.rel)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", p.Roh, err)
	}
	defer func() { _ = d.Close() }()

	namen, err := d.Readdirnames(-1)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", p.Roh, err)
	}
	return namen, nil
}

// sortiere ordnet die Liste. Verzeichnisse stehen immer vorn — sie sind der
// Weg weiter, und den sucht das Auge zuerst.
func sortiere(e []FileEntry, opts ListOptions) {
	kleiner := func(a, b FileEntry) bool {
		switch opts.Sort {
		case SortSize:
			if a.Size != b.Size {
				return a.Size < b.Size
			}
		case SortTime:
			if !a.ModTime.Equal(b.ModTime) {
				return a.ModTime.Before(b.ModTime)
			}
		}
		return strings.ToLower(a.Name) < strings.ToLower(b.Name)
	}
	sort.SliceStable(e, func(i, j int) bool {
		if e[i].IsDir() != e[j].IsDir() {
			return e[i].IsDir()
		}
		if opts.Desc {
			return kleiner(e[j], e[i])
		}
		return kleiner(e[i], e[j])
	})
}

// Stat beschreibt einen Eintrag. Gesperrte Pfade sind hier ausdrücklich
// erlaubt: Die Oberfläche soll erklären können, was sie nicht zeigt.
func (f *FileSystem) Stat(ctx context.Context, p string) (FileEntry, error) {
	_ = ctx
	pf, err := f.wache.pruefen(p, zMeta)
	if err != nil {
		return FileEntry{}, err
	}
	return f.eintrag(pf.Echt, pf.Info, ladeNamensbuch()), nil
}

// Open öffnet eine reguläre Datei zum Lesen.
func (f *FileSystem) Open(ctx context.Context, p string) (io.ReadSeekCloser, FileEntry, error) {
	_ = ctx
	pf, err := f.wache.pruefen(p, zInhalt)
	if err != nil {
		return nil, FileEntry{}, err
	}
	if !pf.Info.Mode().IsRegular() {
		return nil, FileEntry{}, fmt.Errorf("%w: %s", ErrNotRegular, pf.Roh)
	}
	datei, err := f.oeffnenNurLesen(pf)
	if err != nil {
		return nil, FileEntry{}, err
	}
	return datei, f.eintrag(pf.Echt, pf.Info, ladeNamensbuch()), nil
}

// oeffnenNurLesen öffnet ohne Verweisen zu folgen und prüft danach am
// Deskriptor nach, dass wirklich eine reguläre Datei in der Hand liegt.
//
// O_NOFOLLOW ist der Riegel gegen den Austausch der letzten Komponente
// zwischen Prüfung und Öffnen. O_NONBLOCK schützt zusätzlich davor, an einer
// FIFO hängen zu bleiben, falls doch eine durchkommt.
func (f *FileSystem) oeffnenNurLesen(pf *pfad) (*os.File, error) {
	datei, err := pf.root.OpenFile(pf.rel, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", pf.Roh, err)
	}
	if err := istRegulaer(datei); err != nil {
		_ = datei.Close()
		return nil, fmt.Errorf("%s: %w", pf.Roh, err)
	}
	// Zusätzlich zur Sperrliste die Identität: Ein Hardlink auf /etc/shadow
	// trägt einen anderen Namen und käme an jedem Mustervergleich vorbei.
	info, err := datei.Stat()
	if err != nil {
		_ = datei.Close()
		return nil, fmt.Errorf("%s: %w", pf.Roh, err)
	}
	if err := f.wache.pruefeKennung(info, pf.Roh); err != nil {
		_ = datei.Close()
		return nil, err
	}
	return datei, nil
}

// ReadText liest eine Datei für den Editor.
func (f *FileSystem) ReadText(ctx context.Context, p string, max int64) (TextFile, error) {
	if max <= 0 {
		max = f.pol.MaxEditSize
	}
	leser, eintrag, err := f.Open(ctx, p)
	if err != nil {
		return TextFile{}, err
	}
	defer func() { _ = leser.Close() }()

	if eintrag.Size > max {
		return TextFile{}, fmt.Errorf("die Datei ist %s groß; der Editor nimmt bis %s. Herunterladen geht weiterhin",
			formatSize(eintrag.Size), formatSize(max))
	}

	roh, err := io.ReadAll(io.LimitReader(leser, max+1))
	if err != nil {
		return TextFile{}, fmt.Errorf("%s: %w", p, err)
	}
	if int64(len(roh)) > max {
		return TextFile{}, fmt.Errorf("die Datei ist während des Lesens gewachsen und übersteigt %s", formatSize(max))
	}

	// Binäres wird nicht in eine Textarea geschoben: Der Browser würde die
	// Bytes durch seine Kodierung drehen, und beim Speichern käme eine andere
	// Datei heraus als vorher da war.
	if i := indexNul(roh); i >= 0 {
		return TextFile{}, fmt.Errorf("die Datei enthält an Stelle %d ein Null-Byte und ist damit keine Textdatei", i+1)
	}
	if !utf8.Valid(roh) {
		return TextFile{}, errors.New("die Datei ist nicht in UTF-8 kodiert; der Editor würde sie beim Speichern verändern")
	}

	summe := sha256.Sum256(roh)
	inhalt := string(roh)
	tf := TextFile{
		Entry:          eintrag,
		Hash:           hex.EncodeToString(summe[:]),
		CRLF:           strings.Contains(inhalt, "\r\n"),
		NoFinalNewline: len(roh) > 0 && !strings.HasSuffix(inhalt, "\n"),
	}
	if tf.CRLF {
		inhalt = strings.ReplaceAll(inhalt, "\r\n", "\n")
	}
	tf.Content = inhalt
	return tf, nil
}

// WriteText schreibt eine Textdatei zurück.
//
// Drei Zusagen: Zeilenenden und ein fehlender Schlussumbruch bleiben, wie sie
// waren; vor dem Überschreiben entsteht eine Sicherung; und wurde die Datei
// zwischenzeitlich von außen verändert, bricht der Vorgang ab, statt die
// fremde Änderung zu verschlucken.
func (f *FileSystem) WriteText(ctx context.Context, p string, content []byte, opts WriteOptions) (TextFile, error) {
	pf, err := f.zielPfad(p)
	if err != nil {
		return TextFile{}, err
	}

	if pf.Info != nil {
		if !pf.Info.Mode().IsRegular() {
			return TextFile{}, fmt.Errorf("%w: %s", ErrNotRegular, pf.Roh)
		}
		vorher, err := f.rohLesen(pf, f.pol.MaxEditSize)
		if err != nil {
			return TextFile{}, err
		}
		summe := sha256.Sum256(vorher)
		if opts.ExpectHash != "" && !strings.EqualFold(opts.ExpectHash, hex.EncodeToString(summe[:])) {
			return TextFile{}, ErrConflict
		}
		if err := f.sichern(pf, vorher); err != nil {
			return TextFile{}, err
		}
	}

	text := strings.ReplaceAll(string(content), "\r\n", "\n")
	if !opts.NoFinalNewline && text != "" && !strings.HasSuffix(text, "\n") {
		text += "\n"
	}
	if opts.NoFinalNewline {
		text = strings.TrimSuffix(text, "\n")
	}
	if opts.CRLF {
		text = strings.ReplaceAll(text, "\n", "\r\n")
	}

	mode := opts.Mode
	if mode == 0 {
		mode = 0o644
	}
	if pf.Info != nil {
		mode = pf.Info.Mode().Perm()
	}
	if err := f.atomarSchreiben(pf, strings.NewReader(text), mode); err != nil {
		return TextFile{}, err
	}
	return f.ReadText(ctx, pf.Echt, f.pol.MaxEditSize)
}

// zielPfad liefert einen geprüften Pfad, der existieren darf, aber nicht muss.
func (f *FileSystem) zielPfad(p string) (*pfad, error) {
	pf, err := f.wache.pruefen(p, zAendern)
	if err == nil {
		return pf, nil
	}
	// Nur ein fehlender Eintrag rechtfertigt den zweiten Versuch. Ein
	// abgelehnter Pfad bleibt abgelehnt.
	if errors.Is(err, ErrDenied) {
		return nil, err
	}
	sauber := filepath.Clean(p)
	return f.wache.pruefenNeu(filepath.Dir(sauber), filepath.Base(sauber))
}

// rohLesen liest den Inhalt einer geprüften Datei mit Obergrenze.
func (f *FileSystem) rohLesen(pf *pfad, max int64) ([]byte, error) {
	datei, err := f.oeffnenNurLesen(pf)
	if err != nil {
		return nil, err
	}
	defer func() { _ = datei.Close() }()
	return io.ReadAll(io.LimitReader(datei, max))
}

// sichern legt eine Kopie unter /var/lib/asylum/backups/<zeit>/<pfad> ab.
//
// Dieselbe Zusage wie für die verwalteten Konfigurationsdateien
// (docs/02-architektur.md, Regel 4): Vor jedem Überschreiben existiert der
// Vorzustand noch irgendwo.
func (f *FileSystem) sichern(pf *pfad, inhalt []byte) error {
	if f.pol.BackupDir == "" {
		return nil
	}
	ziel := filepath.Join(f.pol.BackupDir, time.Now().UTC().Format("20060102-150405"), pf.Echt)
	if err := os.MkdirAll(filepath.Dir(ziel), 0o700); err != nil {
		return fmt.Errorf("Sicherung anlegen: %w", err)
	}
	// 0600: Die Sicherung kann Geheimnisse enthalten, die in der Originaldatei
	// durch strengere Rechte geschützt waren.
	if err := os.WriteFile(ziel, inhalt, 0o600); err != nil {
		return fmt.Errorf("Sicherung schreiben: %w", err)
	}
	return nil
}

// atomarSchreiben schreibt über eine Nachbardatei und benennt sie um.
//
// Erst schreiben, dann umbenennen: Ein Abbruch — Stromausfall, vollgelaufene
// Platte, beendeter Dienst — hinterlässt entweder die alte oder die neue
// Fassung, niemals eine halbe. Bei einer Konfigurationsdatei ist das der
// Unterschied zwischen "unverändert" und "der Dienst startet nicht mehr".
func (f *FileSystem) atomarSchreiben(pf *pfad, src io.Reader, mode fs.FileMode) error {
	tmpName := "." + pf.Name + ".asylum.tmp"
	if len(tmpName) > 255 {
		tmpName = ".asylum.tmp." + kurzHash(pf.Name)
	}
	tmpRel := path.Join(path.Dir(pf.rel), tmpName)
	tmpEcht := filepath.Join(filepath.Dir(pf.Echt), tmpName)

	// O_EXCL: Eine bereits liegende Temp-Datei ist ein Zeichen dafür, dass ein
	// anderer Vorgang läuft, und wird nicht überfahren.
	tmp, err := pf.root.OpenFile(tmpRel, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		if errors.Is(err, fs.ErrExist) {
			_ = pf.root.Remove(tmpRel)
			tmp, err = pf.root.OpenFile(tmpRel, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o600)
		}
		if err != nil {
			return fmt.Errorf("%s: %w", tmpEcht, err)
		}
	}
	aufraeumen := func() { _ = pf.root.Remove(tmpRel) }

	if _, err := io.CopyBuffer(tmp, src, make([]byte, kopierPuffer)); err != nil {
		_ = tmp.Close()
		aufraeumen()
		return fmt.Errorf("%s: %w", pf.Roh, err)
	}
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()
		aufraeumen()
		return fmt.Errorf("%s: %w", pf.Roh, err)
	}
	if err := tmp.Close(); err != nil {
		aufraeumen()
		return fmt.Errorf("%s: %w", pf.Roh, err)
	}

	// Rechte und Eigentümer der Vorgängerdatei übernehmen. Eine
	// Konfigurationsdatei, die nach dem Speichern plötzlich root:root 0644
	// gehört, wäre für den Dienst, der sie liest, möglicherweise unbrauchbar.
	if err := pf.root.Chmod(tmpRel, mode); err != nil {
		aufraeumen()
		return fmt.Errorf("%s: %w", tmpEcht, err)
	}
	if pf.Info != nil {
		if st, ok := pf.Info.Sys().(*syscall.Stat_t); ok {
			if err := pf.root.Lchown(tmpRel, int(st.Uid), int(st.Gid)); err != nil && !errors.Is(err, os.ErrPermission) {
				aufraeumen()
				return fmt.Errorf("%s: %w", tmpEcht, err)
			}
		}
	}

	if err := pf.root.Rename(tmpRel, pf.rel); err != nil {
		aufraeumen()
		return fmt.Errorf("%s: %w", pf.Roh, err)
	}
	return nil
}

func kurzHash(s string) string {
	summe := sha256.Sum256([]byte(s))
	return hex.EncodeToString(summe[:6])
}

func indexNul(b []byte) int {
	for i, c := range b {
		if c == 0 {
			return i
		}
	}
	return -1
}

// ------------------------------------------------------------- Verändern ---

// Mkdir legt ein Verzeichnis an.
func (f *FileSystem) Mkdir(ctx context.Context, p string) error {
	_ = ctx
	sauber := filepath.Clean(p)
	pf, err := f.wache.pruefenNeu(filepath.Dir(sauber), filepath.Base(sauber))
	if err != nil {
		return err
	}
	if pf.Info != nil {
		return fmt.Errorf("%s gibt es bereits", pf.Roh)
	}
	if err := pf.root.Mkdir(pf.rel, 0o755); err != nil {
		return fmt.Errorf("%s: %w", pf.Roh, uebersetze(err))
	}
	return nil
}

// Touch legt eine leere Datei an.
func (f *FileSystem) Touch(ctx context.Context, p string) error {
	_ = ctx
	sauber := filepath.Clean(p)
	pf, err := f.wache.pruefenNeu(filepath.Dir(sauber), filepath.Base(sauber))
	if err != nil {
		return err
	}
	if pf.Info != nil {
		return fmt.Errorf("%s gibt es bereits", pf.Roh)
	}
	datei, err := pf.root.OpenFile(pf.rel, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o644)
	if err != nil {
		return fmt.Errorf("%s: %w", pf.Roh, uebersetze(err))
	}
	return datei.Close()
}

// Rename benennt innerhalb desselben Verzeichnisses um.
func (f *FileSystem) Rename(ctx context.Context, p, neuerName string) error {
	_ = ctx
	quelle, err := f.wache.pruefen(p, zAendern)
	if err != nil {
		return err
	}
	ziel, err := f.wache.pruefenNeu(filepath.Dir(quelle.Echt), neuerName)
	if err != nil {
		return err
	}
	if ziel.Info != nil {
		return fmt.Errorf("%s gibt es bereits", ziel.Roh)
	}
	return f.verschiebenRoh(quelle, ziel)
}

// verschiebenRoh führt das rename(2) aus, notfalls über Wurzelgrenzen hinweg.
func (f *FileSystem) verschiebenRoh(quelle, ziel *pfad) error {
	if quelle.root == ziel.root {
		if err := quelle.root.Rename(quelle.rel, ziel.rel); err != nil {
			return fmt.Errorf("%s → %s: %w", quelle.Roh, ziel.Roh, uebersetze(err))
		}
		return nil
	}
	// Verschiedene Lesewurzeln: Beide Pfade sind geprüft, deshalb ist das
	// os.Rename darauf zulässig.
	if err := os.Rename(quelle.Echt, ziel.Echt); err != nil {
		return fmt.Errorf("%s → %s: %w", quelle.Roh, ziel.Roh, uebersetze(err))
	}
	return nil
}

// Move verschiebt einen Eintrag in ein anderes Verzeichnis.
func (f *FileSystem) Move(ctx context.Context, p, zielDir string, fortschritt Progress) error {
	quelle, err := f.wache.pruefen(p, zAendern)
	if err != nil {
		return err
	}
	ziel, err := f.wache.pruefenNeu(zielDir, quelle.Name)
	if err != nil {
		return err
	}
	if ziel.Info != nil {
		return fmt.Errorf("%s gibt es bereits", ziel.Roh)
	}
	if unterWurzel(ziel.Echt, quelle.Echt) {
		return fmt.Errorf("%s liegt innerhalb von %s — das wäre eine Endlosschleife", ziel.Roh, quelle.Roh)
	}

	// Der schnelle Weg: rename(2) innerhalb eines Dateisystems.
	if err := f.verschiebenRoh(quelle, ziel); err == nil {
		return nil
	} else if !errors.Is(err, syscall.EXDEV) {
		return err
	}
	// Über Dateisystemgrenzen hinweg gibt es kein rename: kopieren, prüfen,
	// dann löschen. Erst nach dem geglückten Kopieren, nie davor.
	if err := f.Copy(ctx, quelle.Echt, zielDir, fortschritt); err != nil {
		return err
	}
	return f.Remove(ctx, quelle.Echt, nil)
}

// Copy kopiert einen Eintrag in ein Zielverzeichnis.
func (f *FileSystem) Copy(ctx context.Context, p, zielDir string, fortschritt Progress) error {
	quelle, err := f.wache.pruefen(p, zInhalt)
	if err != nil {
		return err
	}
	ziel, err := f.wache.pruefenNeu(zielDir, quelle.Name)
	if err != nil {
		return err
	}
	if ziel.Info != nil {
		return fmt.Errorf("%s gibt es bereits", ziel.Roh)
	}
	if quelle.Info.IsDir() && unterWurzel(ziel.Echt, quelle.Echt) {
		return fmt.Errorf("%s liegt innerhalb von %s — das wäre eine Endlosschleife", ziel.Roh, quelle.Roh)
	}

	mass, err := f.Measure(ctx, quelle.Echt)
	if err != nil {
		return err
	}
	if err := f.pruefeRekursiv(mass, quelle.Roh, "kopieren"); err != nil {
		return err
	}
	if frei, err := f.FreeSpace(ctx, ziel.Echt); err == nil && mass.Bytes > 0 && uint64(mass.Bytes) > frei {
		return fmt.Errorf("dafür fehlt der Platz: %s werden gebraucht, %s sind frei",
			formatSize(mass.Bytes), formatBytes(frei))
	}

	if !quelle.Info.IsDir() {
		return f.dateiKopieren(quelle, ziel, fortschritt, 1, 1)
	}
	return f.baumKopieren(ctx, quelle, ziel, mass, fortschritt)
}

// dateiKopieren kopiert eine reguläre Datei samt Rechten, Eigentümer und Zeit.
func (f *FileSystem) dateiKopieren(quelle, ziel *pfad, fortschritt Progress, nr, gesamt int) error {
	if !quelle.Info.Mode().IsRegular() {
		// Verweise werden als Verweise kopiert; alles andere (FIFO, Gerät,
		// Socket) wird ausgelassen, statt es nachzubauen.
		if quelle.Info.Mode()&fs.ModeSymlink != 0 {
			ziel2, err := quelle.root.Readlink(quelle.rel)
			if err != nil {
				return fmt.Errorf("%s: %w", quelle.Roh, err)
			}
			if err := ziel.root.Symlink(ziel2, ziel.rel); err != nil {
				return fmt.Errorf("%s: %w", ziel.Roh, uebersetze(err))
			}
			return nil
		}
		return nil
	}

	src, err := f.oeffnenNurLesen(quelle)
	if err != nil {
		return err
	}
	defer func() { _ = src.Close() }()

	melde(fortschritt, Step{Current: quelle.Echt, Done: nr, Total: gesamt, Bytes: quelle.Info.Size()})

	if err := f.atomarSchreiben(ziel, src, quelle.Info.Mode().Perm()); err != nil {
		return err
	}
	if st, ok := quelle.Info.Sys().(*syscall.Stat_t); ok {
		if err := ziel.root.Lchown(ziel.rel, int(st.Uid), int(st.Gid)); err != nil && !errors.Is(err, os.ErrPermission) {
			return fmt.Errorf("%s: %w", ziel.Roh, err)
		}
	}
	_ = ziel.root.Chtimes(ziel.rel, time.Now(), quelle.Info.ModTime())
	return nil
}

// baumKopieren kopiert ein Verzeichnis mit allem darunter.
func (f *FileSystem) baumKopieren(ctx context.Context, quelle, ziel *pfad, mass Measurement, fortschritt Progress) error {
	if err := ziel.root.Mkdir(ziel.rel, quelle.Info.Mode().Perm()); err != nil {
		return fmt.Errorf("%s: %w", ziel.Roh, uebersetze(err))
	}
	gesamt := mass.Files + mass.Dirs + mass.Symlinks
	nr := 0

	return f.gehen(ctx, quelle, func(echt, rel string, info fs.FileInfo, tiefe int) error {
		if echt == quelle.Echt {
			return nil
		}
		nr++
		unter := strings.TrimPrefix(strings.TrimPrefix(echt, quelle.Echt), "/")
		zielEcht := filepath.Join(ziel.Echt, unter)

		zp, err := f.wache.pruefenNeu(filepath.Dir(zielEcht), filepath.Base(zielEcht))
		if err != nil {
			return err
		}
		if info.IsDir() {
			if err := zp.root.Mkdir(zp.rel, info.Mode().Perm()); err != nil && !errors.Is(err, fs.ErrExist) {
				return fmt.Errorf("%s: %w", zp.Roh, uebersetze(err))
			}
			melde(fortschritt, Step{Current: echt, Done: nr, Total: gesamt})
			return nil
		}
		qp, err := f.wache.pruefen(echt, zMeta)
		if err != nil {
			return err
		}
		return f.dateiKopieren(qp, zp, fortschritt, nr, gesamt)
	})
}

// Remove löscht einen Eintrag, bei Verzeichnissen samt Inhalt.
func (f *FileSystem) Remove(ctx context.Context, p string, fortschritt Progress) error {
	pf, err := f.wache.pruefen(p, zAendern)
	if err != nil {
		return err
	}
	// Eine Wurzel des Panels selbst zu löschen ist kein sinnvoller Wunsch.
	for _, w := range append(append([]string{}, f.wache.lese...), f.wache.schreib...) {
		if pf.Echt == w {
			return fmt.Errorf("%s ist ein freigegebener Bereich und lässt sich nicht als Ganzes löschen", pf.Roh)
		}
	}

	if !pf.Info.IsDir() {
		if err := pf.root.Remove(pf.rel); err != nil {
			return fmt.Errorf("%s: %w", pf.Roh, uebersetze(err))
		}
		return nil
	}

	mass, err := f.Measure(ctx, pf.Echt)
	if err != nil {
		return err
	}
	if err := f.pruefeRekursiv(mass, pf.Roh, "löschen"); err != nil {
		return err
	}

	// Von innen nach außen: RemoveAll von os.Root nimmt keine Rücksicht auf
	// Sperrliste und Dateisystemgrenzen, deshalb der eigene Lauf.
	var pfade []string
	if err := f.gehen(ctx, pf, func(echt, rel string, info fs.FileInfo, tiefe int) error {
		pfade = append(pfade, echt)
		return nil
	}); err != nil {
		return err
	}

	gesamt := len(pfade)
	for i := len(pfade) - 1; i >= 0; i-- {
		if err := ctx.Err(); err != nil {
			return err
		}
		zp, err := f.wache.pruefen(pfade[i], zAendern)
		if err != nil {
			return err
		}
		if err := zp.root.Remove(zp.rel); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return fmt.Errorf("%s: %w", zp.Roh, uebersetze(err))
		}
		melde(fortschritt, Step{Current: pfade[i], Done: gesamt - i, Total: gesamt})
	}
	return nil
}

// pruefeRekursiv lehnt einen rekursiven Eingriff ab, wenn darunter etwas liegt,
// das nicht angefasst werden darf.
func (f *FileSystem) pruefeRekursiv(m Measurement, pfad, was string) error {
	if m.Sensitive > 0 {
		return fmt.Errorf("%w: unter %s liegen %d gesperrte Einträge — %s würde sie mitnehmen",
			ErrDenied, pfad, m.Sensitive, was)
	}
	if m.Mounts > 0 {
		return fmt.Errorf("%w: unter %s ist ein weiteres Dateisystem eingehängt — %s würde dessen Inhalt mit erfassen",
			ErrDenied, pfad, was)
	}
	if m.Truncated {
		return fmt.Errorf("%s enthält mehr als %d Einträge; das lässt sich über das Panel nicht %s",
			pfad, maxWalkEintraege, was)
	}
	return nil
}

// Chmod setzt die Rechte.
func (f *FileSystem) Chmod(ctx context.Context, p string, mode fs.FileMode, rekursiv bool) error {
	pf, err := f.wache.pruefen(p, zAendern)
	if err != nil {
		return err
	}
	if pf.istVerweis() {
		return fmt.Errorf("%s ist ein Verweis; Rechte gelten immer dem Ziel, und das liegt woanders", pf.Roh)
	}
	if !rekursiv {
		if err := pf.root.Chmod(pf.rel, mode); err != nil {
			return fmt.Errorf("%s: %w", pf.Roh, uebersetze(err))
		}
		return nil
	}
	return f.rekursiv(ctx, pf, "die Rechte zu ändern", func(zp *pfad, info fs.FileInfo) error {
		if info.Mode()&fs.ModeSymlink != 0 {
			return nil // chmod würde dem Verweis folgen
		}
		return zp.root.Chmod(zp.rel, mode)
	})
}

// Chown setzt Eigentümer und Gruppe. Leere Angaben bleiben unverändert.
func (f *FileSystem) Chown(ctx context.Context, p, owner, group string, rekursiv bool) error {
	pf, err := f.wache.pruefen(p, zAendern)
	if err != nil {
		return err
	}
	uid, gid := -1, -1
	if owner != "" {
		if err := ValidateSystemUser(owner); err != nil {
			return err
		}
		id, _, err := userIDs(owner)
		if err != nil {
			return err
		}
		uid = id
	}
	if group != "" {
		if err := ValidateGroup(group); err != nil {
			return err
		}
		id, err := groupID(group)
		if err != nil {
			return err
		}
		gid = id
	}
	if uid == -1 && gid == -1 {
		return errors.New("weder Eigentümer noch Gruppe angegeben")
	}

	if !rekursiv {
		// Lchown, nicht Chown: Bei einem Verweis soll der Verweis den Besitzer
		// wechseln, nicht dessen Ziel — das liegt möglicherweise in einem
		// Bereich, in dem das Panel nichts zu ändern hat.
		if err := pf.root.Lchown(pf.rel, uid, gid); err != nil {
			return fmt.Errorf("%s: %w", pf.Roh, uebersetze(err))
		}
		return nil
	}
	return f.rekursiv(ctx, pf, "den Eigentümer zu ändern", func(zp *pfad, info fs.FileInfo) error {
		return zp.root.Lchown(zp.rel, uid, gid)
	})
}

// rekursiv setzt eine Operation auf einen ganzen Baum an, nach derselben
// Vorprüfung wie Kopieren und Löschen.
func (f *FileSystem) rekursiv(ctx context.Context, pf *pfad, was string, tun func(*pfad, fs.FileInfo) error) error {
	mass, err := f.Measure(ctx, pf.Echt)
	if err != nil {
		return err
	}
	if err := f.pruefeRekursiv(mass, pf.Roh, was); err != nil {
		return err
	}
	return f.gehen(ctx, pf, func(echt, rel string, info fs.FileInfo, tiefe int) error {
		zp, err := f.wache.pruefen(echt, zAendern)
		if err != nil {
			return err
		}
		if err := tun(zp, info); err != nil {
			return fmt.Errorf("%s: %w", echt, uebersetze(err))
		}
		return nil
	})
}

// ------------------------------------------------------- Zählen und Suchen ---

// Measure zählt, was unter einem Pfad liegt.
func (f *FileSystem) Measure(ctx context.Context, p string) (Measurement, error) {
	pf, err := f.wache.pruefen(p, zMeta)
	if err != nil {
		return Measurement{}, err
	}
	var m Measurement
	if !pf.Info.IsDir() {
		switch kindOf(pf.Info.Mode()) {
		case KindSymlink:
			m.Symlinks = 1
		default:
			m.Files = 1
			m.Bytes = pf.Info.Size()
		}
		return m, nil
	}

	gerät := deviceOf(pf.Info)
	err = f.gehen(ctx, pf, func(echt, rel string, info fs.FileInfo, tiefe int) error {
		if f.wache.istSensibel(echt) {
			m.Sensitive++
		}
		if d := deviceOf(info); d != 0 && gerät != 0 && d != gerät {
			m.Mounts++
		}
		switch {
		case info.Mode()&fs.ModeSymlink != 0:
			m.Symlinks++
		case info.IsDir():
			m.Dirs++
		default:
			m.Files++
			m.Bytes += info.Size()
		}
		return nil
	})
	if errors.Is(err, errZuViel) {
		m.Truncated = true
		return m, nil
	}
	return m, err
}

// Search sucht nach einem Namensbestandteil.
func (f *FileSystem) Search(ctx context.Context, dir, query string, limit int) (SearchResult, error) {
	query = strings.TrimSpace(query)
	if len(query) < 2 {
		return SearchResult{}, errors.New("der Suchbegriff braucht mindestens zwei Zeichen")
	}
	if limit <= 0 || limit > maxSuchTreffer {
		limit = maxSuchTreffer
	}
	pf, err := f.wache.pruefen(dir, zInhalt)
	if err != nil {
		return SearchResult{}, err
	}
	if !pf.Info.IsDir() {
		return SearchResult{}, fmt.Errorf("%s ist kein Verzeichnis", pf.Roh)
	}

	nadel := strings.ToLower(query)
	nb := ladeNamensbuch()
	var res SearchResult

	err = f.gehen(ctx, pf, func(echt, rel string, info fs.FileInfo, tiefe int) error {
		if echt == pf.Echt {
			return nil
		}
		if !strings.Contains(strings.ToLower(filepath.Base(echt)), nadel) {
			return nil
		}
		res.Entries = append(res.Entries, f.eintrag(echt, info, nb))
		if len(res.Entries) >= limit {
			return errGenug
		}
		return nil
	})
	switch {
	case errors.Is(err, errGenug):
		res.Truncated = true
		res.Reason = fmt.Sprintf("bei %d Treffern abgebrochen", limit)
	case errors.Is(err, errZuViel):
		res.Truncated = true
		res.Reason = fmt.Sprintf("nach %d durchsuchten Einträgen abgebrochen", maxWalkEintraege)
	case errors.Is(err, context.DeadlineExceeded):
		res.Truncated = true
		res.Reason = "die Suche hat zu lange gedauert"
	case err != nil:
		return SearchResult{}, err
	}
	return res, nil
}

// Abbruchgründe eines Baumlaufs. Sie sind Steuerfluss, keine Fehler — der
// Aufrufer entscheidet, ob ein Abbruch ein Ergebnis ist.
var (
	errZuViel = errors.New("zu viele Einträge")
	errGenug  = errors.New("genug gefunden")
)

// gehen läuft über einen Baum, ohne Verweisen zu folgen.
//
// Der Lauf geht über os.Root.FS(): Damit bleibt jede Auflösung innerhalb der
// freigegebenen Wurzel, und ein Verzeichnisverweis wird als Verweis gemeldet
// statt betreten. Ein WalkDir über absolute Pfade täte beides nicht.
func (f *FileSystem) gehen(ctx context.Context, pf *pfad, besuch func(echt, rel string, info fs.FileInfo, tiefe int) error) error {
	wurzelPfad := pf.root.Name()
	basisTiefe := strings.Count(pf.rel, "/")
	gezaehlt := 0

	return fs.WalkDir(pf.root.FS(), pf.rel, func(rel string, d fs.DirEntry, err error) error {
		if err != nil {
			// Ein nicht lesbares Unterverzeichnis bricht den ganzen Lauf nicht
			// ab; es fehlt dann eben in der Zählung.
			if errors.Is(err, fs.ErrPermission) || errors.Is(err, fs.ErrNotExist) {
				return nil
			}
			return err
		}
		gezaehlt++
		if gezaehlt%512 == 0 {
			if cerr := ctx.Err(); cerr != nil {
				return cerr
			}
		}
		if gezaehlt > maxWalkEintraege {
			return errZuViel
		}

		tiefe := strings.Count(rel, "/") - basisTiefe
		if tiefe > maxWalkTiefe {
			if d.IsDir() {
				return fs.SkipDir
			}
			return nil
		}

		echt := filepath.Join(wurzelPfad, rel)
		if rel == "." {
			echt = wurzelPfad
		}
		// Pseudo-Dateisysteme und gesperrte Verzeichnisse werden nicht betreten.
		for _, ps := range pseudoRoots {
			if unterWurzel(echt, ps) {
				if d.IsDir() {
					return fs.SkipDir
				}
				return nil
			}
		}

		info, err := d.Info()
		if err != nil {
			// Der Eintrag ist zwischen readdir und lstat verschwunden. Das ist
			// im Betrieb normal — etwa eine Sitzungsdatei, die abgelaufen ist —
			// und kein Grund, den ganzen Lauf abzubrechen.
			return nil //nolint:nilerr // absichtlich übersprungen, siehe oben
		}
		return besuch(echt, rel, info, tiefe)
	})
}

// ------------------------------------------------------------- Auskünfte ---

// FreeSpace liefert den freien Platz des Dateisystems, auf dem der Pfad liegt.
func (f *FileSystem) FreeSpace(ctx context.Context, p string) (uint64, error) {
	_ = ctx
	pf, err := f.wache.aufloesen(filepath.Clean(p))
	if err != nil {
		return 0, err
	}
	// Für ein noch nicht existierendes Ziel zählt das Elternverzeichnis.
	ziel := pf.Echt
	if _, err := os.Lstat(ziel); err != nil {
		ziel = filepath.Dir(ziel)
	}
	var st syscall.Statfs_t
	if err := syscall.Statfs(ziel, &st); err != nil {
		return 0, fmt.Errorf("%s: %w", p, err)
	}
	return uint64(st.Bavail) * uint64(st.Bsize), nil //nolint:gosec,unconvert // Feldtypen je Plattform verschieden
}

// OwnerCandidates liefert Benutzer- und Gruppennamen für die Auswahlfelder.
func (f *FileSystem) OwnerCandidates(ctx context.Context) (users, groups []string, err error) {
	_ = ctx
	raw, err := os.ReadFile(passwdPath)
	if err != nil {
		return nil, nil, fmt.Errorf("%s: %w", passwdPath, err)
	}
	for _, u := range parsePasswd(string(raw)) {
		users = append(users, u.Name)
	}
	if raw, err := os.ReadFile(groupPath); err == nil {
		_, byGID := parseGroups(string(raw))
		for _, name := range byGID {
			groups = append(groups, name)
		}
	}
	sort.Strings(users)
	sort.Strings(groups)
	return users, groups, nil
}

// Verify prüft, ob in den Schreibwurzeln tatsächlich geschrieben werden kann.
//
// Der Grund ist eine Eigenart des Selbstupdates: Es tauscht das Programm, nie
// die systemd-Unit. Eine Installation, die von einer älteren Fassung kommt,
// trägt deshalb noch ProtectHome=read-only — und jeder Schreibversuch unter
// /home scheitert mit EROFS, ohne dass die Rechtebits der Verzeichnisse etwas
// davon verraten. Deshalb ein echter Schreibversuch statt einer Auswertung von
// Bits.
func (f *FileSystem) Verify(ctx context.Context) []RootStatus {
	_ = ctx
	out := make([]RootStatus, 0, len(f.wache.schreib))
	for _, w := range f.wache.schreib {
		st := RootStatus{Path: w}
		info, err := os.Stat(w)
		switch {
		case errors.Is(err, fs.ErrNotExist):
			st.Reason = "gibt es auf diesem System nicht"
		case err != nil:
			st.Reason = err.Error()
		case !info.IsDir():
			st.Exists = true
			st.Reason = "ist kein Verzeichnis"
		default:
			st.Exists = true
			st.Writable, st.Reason = probeSchreiben(w)
		}
		out = append(out, st)
	}
	return out
}

// probeSchreiben legt eine leere Datei an und entfernt sie wieder.
func probeSchreiben(dir string) (bool, string) {
	datei, err := os.CreateTemp(dir, ".asylum-probe-*")
	if err != nil {
		if errors.Is(err, syscall.EROFS) {
			return false, "nur lesbar eingehängt — vermutlich ProtectSystem oder ProtectHome der systemd-Unit"
		}
		return false, uebersetze(err).Error()
	}
	name := datei.Name()
	_ = datei.Close()
	_ = os.Remove(name)
	return true, ""
}

// groupID liest die GID einer Gruppe aus /etc/group.
func groupID(name string) (int, error) {
	raw, err := os.ReadFile(groupPath)
	if err != nil {
		return 0, fmt.Errorf("%s: %w", groupPath, err)
	}
	_, byGID := parseGroups(string(raw))
	for gid, n := range byGID {
		if n == name {
			return gid, nil
		}
	}
	return 0, fmt.Errorf("die Gruppe %q gibt es nicht", name)
}

// deviceOf liefert die Gerätenummer eines Eintrags, 0 wenn unbekannt.
func deviceOf(info fs.FileInfo) uint64 {
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		return uint64(st.Dev) //nolint:unconvert // Feldtyp je Plattform verschieden
	}
	return 0
}

func melde(p Progress, s Step) {
	if p != nil {
		p(s)
	}
}

// uebersetze macht aus einem errno eine Meldung, die dem Bedienenden sagt, was
// zu tun ist. Ein durchgereichtes "operation not permitted" tut das nicht.
func uebersetze(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, syscall.EROFS):
		return errors.New("das Dateisystem ist an dieser Stelle nur lesbar eingehängt — bei einer selbst aktualisierten Installation trägt die systemd-Unit noch die alte Härtung (siehe UPGRADING.md)")
	case errors.Is(err, syscall.ENOSPC):
		return errors.New("auf dem Ziel-Dateisystem ist kein Platz mehr")
	case errors.Is(err, syscall.EDQUOT):
		return errors.New("die Plattenquote ist erschöpft")
	case errors.Is(err, syscall.ENOTEMPTY):
		return errors.New("das Verzeichnis ist nicht leer")
	case errors.Is(err, syscall.EXDEV):
		return syscall.EXDEV // vom Aufrufer behandelt
	case errors.Is(err, syscall.ELOOP):
		return errors.New("der Pfad führt über eine Verweisschleife")
	case errors.Is(err, syscall.ENAMETOOLONG):
		return errors.New("der Name ist für dieses Dateisystem zu lang")
	case errors.Is(err, syscall.EPERM), errors.Is(err, fs.ErrPermission):
		return errors.New("das Betriebssystem verweigert die Änderung (möglicherweise ein unveränderliches Attribut, siehe chattr)")
	case errors.Is(err, syscall.EBUSY):
		return errors.New("der Eintrag ist in Benutzung (vermutlich ein Einhängepunkt)")
	}
	return err
}

// formatSize ist die Fassung für Dateigrößen, wie das Dateisystem sie liefert.
// Ein negativer Wert wäre ein Programmfehler; er wird als 0 gezeigt, statt sich
// beim Wandeln in eine gewaltige Zahl zu verkehren.
func formatSize(n int64) string {
	if n < 0 {
		return "0 B"
	}
	return formatBytes(uint64(n))
}

// formatBytes gibt eine Größe lesbar aus. Dieselbe Darstellung wie in der
// Oberfläche, damit Meldung und Anzeige nicht auseinanderlaufen.
func formatBytes(b uint64) string {
	const unit = 1024
	if b < unit {
		return fmt.Sprintf("%d B", b)
	}
	div, exp := uint64(unit), 0
	for n := b / unit; n >= unit && exp < 4; n /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f %ciB", float64(b)/float64(div), "KMGTP"[exp])
}
