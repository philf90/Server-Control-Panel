package privops

import (
	"context"
	"fmt"
	"io"
	"io/fs"
	"os"
	"strings"
	"time"
)

// Der Dateimanager ist der einzige Teil des Panels, der mit beliebigen Pfaden
// des Wirtsystems arbeitet. Alles andere kennt feste Ziele — eine Unit, ein
// Paket, eine Konfigurationsdatei. Deshalb liegt die gesamte Prüfung an einer
// Stelle (pfadwache.go), und keine Operation hier unten nimmt je einen Pfad
// entgegen, der nicht durch sie hindurchgegangen ist.
//
// Umgesetzt ist alles über die Standardbibliothek: os.Root für die Auflösung,
// archive/tar und compress/gzip für den Ordner-Download. Kein neues Kommando in
// der Allowlist von exec.go — cp, mv, chmod und tar würden Argumente aus
// Benutzereingaben tragen, und genau das soll dieses Projekt nicht tun.

// Files ist die Schnittstelle des Dateimanagers.
//
// Bewusst getrennt von Executor: Das Interface ist umfangreich, und Executor
// wird von mehreren Attrappen in den Tests erfüllt. Für die geplante
// Prozesstrennung (docs/02-architektur.md) ändert die Trennung nichts — es ist
// ein Interface mehr, das ein root-Agent per RPC bedient.
type Files interface {
	// List liest ein Verzeichnis.
	List(ctx context.Context, dir string, opts ListOptions) (Listing, error)
	// Stat beschreibt einen einzelnen Eintrag.
	Stat(ctx context.Context, path string) (FileEntry, error)

	// Open öffnet eine reguläre Datei zum Lesen. Der Aufrufer schließt.
	Open(ctx context.Context, path string) (io.ReadSeekCloser, FileEntry, error)
	// ReadText liest eine Textdatei für den Editor.
	ReadText(ctx context.Context, path string, max int64) (TextFile, error)
	// WriteText schreibt eine Textdatei atomar zurück.
	WriteText(ctx context.Context, path string, content []byte, opts WriteOptions) (TextFile, error)

	// Mkdir legt ein Verzeichnis an, Touch eine leere Datei.
	Mkdir(ctx context.Context, path string) error
	Touch(ctx context.Context, path string) error
	// Rename benennt innerhalb desselben Verzeichnisses um.
	Rename(ctx context.Context, path, newName string) error
	// Copy und Move arbeiten auf ein Zielverzeichnis.
	Copy(ctx context.Context, path, targetDir string, fortschritt Progress) error
	Move(ctx context.Context, path, targetDir string, fortschritt Progress) error
	// Remove löscht, bei Verzeichnissen einschließlich Inhalt.
	Remove(ctx context.Context, path string, fortschritt Progress) error

	// Chmod setzt die Rechte, Chown Eigentümer und Gruppe.
	Chmod(ctx context.Context, path string, mode fs.FileMode, recursive bool) error
	Chown(ctx context.Context, path, owner, group string, recursive bool) error

	// Measure zählt Einträge und Bytes unterhalb eines Pfads — die Grundlage der
	// Rückfrage vor einem rekursiven Eingriff.
	Measure(ctx context.Context, path string) (Measurement, error)
	// Search sucht nach Namensbestandteilen unterhalb eines Verzeichnisses.
	Search(ctx context.Context, dir, query string, limit int) (SearchResult, error)

	// Archive schreibt ein Verzeichnis als tar.gz-Strom.
	Archive(ctx context.Context, path string, w io.Writer) (ArchiveResult, error)
	// Receive nimmt einen Upload auf und legt ihn im Zielverzeichnis ab.
	Receive(ctx context.Context, dir, name string, src io.Reader, opts ReceiveOptions) (FileEntry, error)

	// FreeSpace liefert den freien Platz des Dateisystems, auf dem der Pfad liegt.
	FreeSpace(ctx context.Context, path string) (uint64, error)
	// OwnerCandidates liefert die Benutzer- und Gruppennamen des Systems für die
	// Auswahlfelder von chown. Freitext gibt es dort bewusst nicht.
	OwnerCandidates(ctx context.Context) (users, groups []string, err error)
	// ReadableRoots sind die Bäume, die überhaupt sichtbar sind. Die Oberfläche
	// zeigt sie als Einstiegspunkte.
	ReadableRoots() []string
	// WritableRoots und Verify sagen, wo geschrieben werden darf und ob das auch
	// tatsächlich möglich ist — eine per Selbstupdate aktualisierte Installation
	// trägt noch die alte systemd-Härtung.
	WritableRoots() []string
	Verify(ctx context.Context) []RootStatus
}

// Progress nimmt den Zwischenstand eines langen Vorgangs auf. Nil ist erlaubt.
type Progress func(Step)

// Step ist ein Zwischenstand: was gerade geschieht und wie weit es ist.
type Step struct {
	Current string
	Done    int
	Total   int
	Bytes   int64
}

// ------------------------------------------------------------------ Typen ---

// FileKind unterscheidet, womit man es zu tun hat. Die Unterscheidung ist mehr
// als Kosmetik: Nur eine reguläre Datei wird gelesen, editiert oder
// heruntergeladen. Ein open() auf eine FIFO blockiert unbegrenzt, /dev/zero
// liefert unendlich viel, und /proc/kcore behauptet 128 TiB.
type FileKind string

// Die möglichen Arten eines Eintrags.
const (
	KindRegular FileKind = "datei"
	KindDir     FileKind = "ordner"
	KindSymlink FileKind = "verweis"
	KindOther   FileKind = "sonstiges"
)

// FileEntry ist ein Eintrag in einer Liste oder das Ergebnis eines Stat.
type FileEntry struct {
	Name string   `json:"name"`
	Path string   `json:"path"`
	Kind FileKind `json:"kind"`
	Size int64    `json:"size"`

	Mode      fs.FileMode `json:"-"`
	ModeOctal string      `json:"mode_octal"`
	ModeText  string      `json:"mode_text"`

	UID   int    `json:"uid"`
	GID   int    `json:"gid"`
	Owner string `json:"owner"`
	Group string `json:"group"`

	ModTime time.Time `json:"mod_time"`

	// LinkTarget ist bei Verweisen das Ziel, wie es in der Datei steht.
	LinkTarget string `json:"link_target,omitempty"`
	// LinkBroken sagt, dass das Ziel nicht existiert.
	LinkBroken bool `json:"link_broken,omitempty"`

	// Sensitive: Der Pfad steht auf der Sperrliste. Der Eintrag wird angezeigt,
	// sein Inhalt aber nie gelesen, geschrieben oder ausgeliefert. Warum steht
	// in SensitiveReason.
	Sensitive       bool   `json:"sensitive,omitempty"`
	SensitiveReason string `json:"sensitive_reason,omitempty"`

	// Writable sagt, ob der Eintrag unter einer Schreibwurzel liegt. Nur dann
	// zeigt die Oberfläche verändernde Aktionen an — eine Schaltfläche, die
	// zuverlässig in einen Fehler läuft, ist die schlechteste aller Antworten.
	Writable bool `json:"writable"`
}

// IsDir sagt, ob der Eintrag ein Verzeichnis ist.
func (e FileEntry) IsDir() bool { return e.Kind == KindDir }

// Readable sagt, ob der Inhalt ausgeliefert werden darf.
func (e FileEntry) Readable() bool { return e.Kind == KindRegular && !e.Sensitive }

// Listing ist der Inhalt eines Verzeichnisses.
type Listing struct {
	Dir     FileEntry   `json:"dir"`
	Entries []FileEntry `json:"entries"`
	// Total ist die Zahl der Einträge vor der Begrenzung.
	Total int `json:"total"`
	// Truncated sagt, dass die Liste gekürzt wurde. Ein
	// /usr/lib/x86_64-linux-gnu mit viertausend Dateien darf die Seite nicht
	// sprengen.
	Truncated bool `json:"truncated"`
	// Parent ist das übergeordnete Verzeichnis, leer an der Wurzel.
	Parent string `json:"parent"`
}

// ListSort benennt die Sortierung einer Liste.
type ListSort string

// Die möglichen Sortierungen. Verzeichnisse stehen immer vorn.
const (
	SortName ListSort = "name"
	SortSize ListSort = "size"
	SortTime ListSort = "time"
)

// ListOptions steuert das Auslesen eines Verzeichnisses.
type ListOptions struct {
	Sort ListSort
	Desc bool
	// ShowHidden nimmt Einträge mit führendem Punkt auf.
	ShowHidden bool
	// Limit begrenzt die Zahl der zurückgegebenen Einträge; 0 nimmt die Vorgabe.
	Limit int
}

// maxListEntries ist die Vorgabe für Limit.
const maxListEntries = 2000

// TextFile ist eine Datei, wie der Editor sie sieht.
type TextFile struct {
	Entry FileEntry `json:"entry"`
	// Content ist der Inhalt mit Zeilenenden in LF — der Browser schickt es so
	// zurück, und CRLF wird beim Schreiben wiederhergestellt.
	Content string `json:"content"`
	// Hash ist der SHA-256 des Inhalts auf der Platte, hexadezimal. Er wandert
	// in ein verstecktes Feld und wird beim Speichern verglichen: Wurde die
	// Datei zwischenzeitlich von außen geändert, zeigt die Seite den Konflikt,
	// statt die fremde Änderung zu überschreiben.
	Hash string `json:"hash"`
	// CRLF sagt, dass die Datei Windows-Zeilenenden hatte.
	CRLF bool `json:"crlf"`
	// NoFinalNewline sagt, dass die letzte Zeile ohne Umbruch endete.
	NoFinalNewline bool `json:"no_final_newline"`
}

// WriteOptions steuert das Zurückschreiben einer Textdatei.
type WriteOptions struct {
	// ExpectHash ist der Hash, den der Schreibende gelesen hat. Leer heißt: Es
	// gab nichts zu überschreiben (neue Datei).
	ExpectHash string
	// CRLF stellt Windows-Zeilenenden wieder her.
	CRLF bool
	// NoFinalNewline lässt den abschließenden Umbruch weg.
	NoFinalNewline bool
	// Mode gilt nur, wenn die Datei neu angelegt wird.
	Mode fs.FileMode
}

// ReceiveOptions steuert die Aufnahme eines Uploads.
type ReceiveOptions struct {
	// Overwrite ersetzt eine bestehende Datei (mit Sicherung).
	Overwrite bool
	// MaxSize begrenzt die Größe; 0 nimmt die Grenze aus der Politik.
	MaxSize int64
}

// Measurement ist das Ergebnis von Measure.
type Measurement struct {
	Files    int   `json:"files"`
	Dirs     int   `json:"dirs"`
	Symlinks int   `json:"symlinks"`
	Bytes    int64 `json:"bytes"`
	// Sensitive zählt Einträge auf der Sperrliste. Ist der Wert größer als
	// null, wird der rekursive Eingriff abgelehnt: Ein rm -rf /etc darf nicht
	// /etc/shadow mitnehmen.
	Sensitive int `json:"sensitive"`
	// Mounts zählt überschrittene Dateisystemgrenzen. Auch das lehnt einen
	// rekursiven Eingriff ab — ein Löschen von /mnt würde sonst die
	// eingehängte Platte leeren.
	Mounts int `json:"mounts"`
	// Truncated sagt, dass die Zählung an einer Obergrenze abgebrochen wurde.
	Truncated bool `json:"truncated"`
}

// SearchResult ist das Ergebnis einer Namenssuche.
type SearchResult struct {
	Entries []FileEntry `json:"entries"`
	// Truncated sagt, dass die Suche an einer Grenze endete (Treffer, Tiefe
	// oder Zeit) und es weitere Treffer geben kann.
	Truncated bool `json:"truncated"`
	// Reason benennt die Grenze, damit die Oberfläche nicht "keine weiteren
	// Treffer" behaupten muss, wo sie nur nicht weitergesucht hat.
	Reason string `json:"reason,omitempty"`
}

// ArchiveResult beschreibt, was in ein tar.gz gewandert ist.
type ArchiveResult struct {
	Files int   `json:"files"`
	Bytes int64 `json:"bytes"`
	// Skipped zählt, was ausgelassen wurde: Sperrliste, Gerätedateien,
	// fremde Dateisysteme.
	Skipped int `json:"skipped"`
}

// RootStatus ist das Ergebnis der Selbstprüfung einer Schreibwurzel.
type RootStatus struct {
	Path string `json:"path"`
	// Exists sagt, ob das Verzeichnis vorhanden ist. Ein System ohne /srv ist
	// kein Fehler.
	Exists bool `json:"exists"`
	// Writable ist das Ergebnis eines echten Schreibversuchs, nicht die
	// Auswertung von Rechtebits: ProtectHome und ProtectSystem der systemd-Unit
	// sind an den Bits nicht zu erkennen.
	Writable bool `json:"writable"`
	// Reason erklärt ein Nein in einem Satz.
	Reason string `json:"reason,omitempty"`
}

// ------------------------------------------------------------- Politik ---

// FilesPolicy beschreibt, wo der Dateimanager lesen und schreiben darf.
//
// Die Werte kommen aus der Konfiguration; die Vorgaben stehen in
// DefaultFilesPolicy und sind so gewählt, dass eine bestehende Installation
// nichts eintragen muss.
type FilesPolicy struct {
	// ReadableRoots sind die Bäume, die überhaupt sichtbar sind.
	ReadableRoots []string
	// WritableRoots sind die Bäume, in denen verändert werden darf. Immer eine
	// Teilmenge der lesbaren.
	WritableRoots []string
	// DeniedPaths ergänzt die eingebaute Sperrliste. Sie kann nur wachsen:
	// builtinDeniedPaths lässt sich über die Konfiguration nicht verkleinern.
	DeniedPaths []string
	// FollowSymlinks erlaubt es, Inhalte durch einen Verweis hindurch zu lesen
	// und zu schreiben. Vorgabe: aus.
	FollowSymlinks bool
	// MaxEditSize ist die Obergrenze für den Editor.
	MaxEditSize int64
	// MaxUpload ist die Obergrenze je hochgeladener Datei.
	MaxUpload int64
	// BackupDir nimmt die Sicherungen vor jedem Überschreiben auf.
	BackupDir string
}

// Vorgaben des Dateimanagers.
const (
	// DefaultMaxEditSize: darüber ist es keine Konfigurationsdatei mehr, und
	// eine Textarea mit 20 MB bringt jeden Browser ins Straucheln.
	DefaultMaxEditSize = 2 << 20 // 2 MiB
	// DefaultMaxUpload ist absichtlich großzügig; die echte Grenze ist der
	// freie Platz, und der wird vorher geprüft.
	DefaultMaxUpload = 2 << 30 // 2 GiB
)

// DefaultFilesPolicy liefert die Vorgaben.
//
// Die Schreibwurzeln entsprechen dem, was die systemd-Unit nach der Anpassung
// in packaging/ tatsächlich zulässt: /usr, /boot und /efi bleiben über
// ProtectSystem=true schreibgeschützt und stehen deshalb nicht in der Liste —
// dort hat ein Panel auch nichts von Hand zu ändern.
func DefaultFilesPolicy(backupDir string) FilesPolicy {
	return FilesPolicy{
		ReadableRoots: []string{"/"},
		WritableRoots: []string{
			"/etc", "/home", "/root", "/srv", "/opt", "/var", "/mnt", "/media", "/tmp",
		},
		MaxEditSize: DefaultMaxEditSize,
		MaxUpload:   DefaultMaxUpload,
		BackupDir:   backupDir,
	}
}

// builtinDeniedPaths ist die eingebaute Sperrliste. Die Einträge werden
// angezeigt, ihr Inhalt aber nie gelesen, geschrieben oder ausgeliefert — und
// zwar für jede Rolle, auch für Owner.
//
// Der Grund ist nicht Prüderie gegenüber root, sondern der Zweck des Panels:
// Es ist über das Netz erreichbar. Eine übernommene Sitzung könnte sonst mit
// zwei Klicks die Passwort-Hashes aller Panel-Zugänge, den privaten
// TLS-Schlüssel und die SSH-Host-Schlüssel des Servers herunterladen — also
// genau das Material, mit dem sich jede weitere Schutzschicht umgehen lässt.
// Wer diese Dateien braucht, hat SSH.
//
// Die Muster folgen filepath.Match: * überschreitet keinen Pfadtrenner. Jeder
// Vorfahre eines Pfads wird mitgeprüft, ein Muster sperrt also auch alles
// darunter.
var builtinDeniedPaths = []deniedPath{
	{"/etc/shadow", "Passwort-Hashes der Systembenutzer"},
	{"/etc/shadow-", "Passwort-Hashes der Systembenutzer"},
	{"/etc/gshadow", "Passwort-Hashes der Gruppen"},
	{"/etc/gshadow-", "Passwort-Hashes der Gruppen"},
	{"/etc/ssh/ssh_host_*_key", "privater SSH-Host-Schlüssel"},
	{"/etc/asylum/tls/*.key", "privater TLS-Schlüssel des Panels"},
	{"/var/lib/asylum/asylum.db*", "Datenbank des Panels: Passwort-Hashes, Sitzungen, Passkeys"},
	{"/var/lib/asylum/acme", "ACME-Kontoschlüssel und Zertifikatsmaterial"},
	{"/var/lib/asylum/releases", "Programmfassungen für den Rückweg eines Updates"},
	{"/root/.ssh/id_*", "privater SSH-Schlüssel"},
	{"/home/*/.ssh/id_*", "privater SSH-Schlüssel"},
}

// Öffentliche Schlüssel sind öffentlich. Ohne diese Ausnahme fiele
// id_ed25519.pub unter das Muster id_* und wäre gesperrt — eine Sperre, die
// nichts schützt und nur verwirrt.
func istOeffentlicherSchluessel(name string) bool {
	return strings.HasSuffix(name, ".pub")
}

// deniedPath ist ein Muster samt Begründung. Die Begründung erscheint in der
// Oberfläche: Ein Schloss ohne Erklärung wirkt wie eine Fehlfunktion.
type deniedPath struct {
	Pattern string
	Reason  string
}

// pseudoRoots sind Bäume, die keine Ablage sind. Sie werden nicht durchlaufen
// und nicht ausgeliefert:
//
//   - /proc/kcore behauptet 128 TiB, /dev/zero liefert unendlich viel.
//   - /proc/self/root ist ein Verweis auf / und damit ein Sprungbrett aus jeder
//     Pfadprüfung, die sich auf Zeichenketten verlässt.
//   - Ein Schreibzugriff auf /sys ändert Kernel-Zustand, nicht eine Datei.
var pseudoRoots = []string{"/proc", "/sys", "/dev"}

// ErrDenied meldet einen Pfad, den die Politik nicht zulässt. Die Oberfläche
// antwortet darauf mit 403.
var ErrDenied = errPolitik("nicht erlaubt")

// ErrNotRegular meldet einen Inhalt, der keine reguläre Datei ist.
var ErrNotRegular = errPolitik("keine reguläre Datei")

// ErrConflict meldet eine Datei, die sich seit dem Laden geändert hat.
var ErrConflict = errPolitik("die Datei wurde zwischenzeitlich geändert")

// errPolitik ist ein Sentinel-Fehlertyp, an dem die HTTP-Schicht den
// Statuscode ablesen kann, ohne Fehlertexte zu vergleichen.
type errPolitik string

func (e errPolitik) Error() string { return string(e) }

// FormatMode liefert die oktale Schreibweise, wie chmod sie erwartet —
// vierstellig, damit setuid, setgid und das Sticky-Bit sichtbar sind.
func FormatMode(m fs.FileMode) string {
	return fmt.Sprintf("%04o", bitsFromMode(m))
}

// ParseMode liest eine oktale Rechteangabe. Erlaubt sind drei oder vier
// Stellen; alles andere wäre eher Tippfehler als Absicht.
func ParseMode(s string) (fs.FileMode, error) {
	s = strings.TrimSpace(s)
	if len(s) < 3 || len(s) > 4 {
		return 0, fmt.Errorf("Rechte %q sind keine drei- oder vierstellige Oktalzahl", s)
	}
	var v uint32
	for _, c := range s {
		if c < '0' || c > '7' {
			return 0, fmt.Errorf("Rechte %q sind keine Oktalzahl", s)
		}
		v = v*8 + uint32(c-'0')
	}
	if v > 0o7777 {
		return 0, fmt.Errorf("Rechte %q liegen außerhalb von 0000–7777", s)
	}
	return modeFromBits(v), nil
}

// modeFromBits setzt die Sonderbits von der Unix-Darstellung auf die von Go.
func modeFromBits(v uint32) fs.FileMode {
	m := fs.FileMode(v & 0o777)
	if v&0o4000 != 0 {
		m |= fs.ModeSetuid
	}
	if v&0o2000 != 0 {
		m |= fs.ModeSetgid
	}
	if v&0o1000 != 0 {
		m |= fs.ModeSticky
	}
	return m
}

// bitsFromMode ist die Umkehrung — die Form, die chmod(2) erwartet.
func bitsFromMode(m fs.FileMode) uint32 {
	v := uint32(m.Perm())
	if m&fs.ModeSetuid != 0 {
		v |= 0o4000
	}
	if m&fs.ModeSetgid != 0 {
		v |= 0o2000
	}
	if m&fs.ModeSticky != 0 {
		v |= 0o1000
	}
	return v
}

// kindOf bestimmt die Art eines Eintrags aus seinem Modus.
func kindOf(m fs.FileMode) FileKind {
	switch {
	case m&fs.ModeSymlink != 0:
		return KindSymlink
	case m.IsDir():
		return KindDir
	case m.IsRegular():
		return KindRegular
	default:
		return KindOther
	}
}

// istRegulaer prüft eine geöffnete Datei nach dem Öffnen ein zweites Mal.
//
// Zwischen der Prüfung des Namens und dem Öffnen kann sich der Eintrag
// geändert haben. Ein Lstat vorher genügt deshalb nicht: Verlässlich ist nur
// die Auskunft über den Deskriptor, den wir tatsächlich in der Hand haben.
func istRegulaer(f *os.File) error {
	info, err := f.Stat()
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() {
		return ErrNotRegular
	}
	return nil
}
