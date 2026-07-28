package privops

import (
	"context"
	"fmt"
	"io"
	"io/fs"
	"path/filepath"
)

// ErrTooLarge meldet eine Datei über der zulässigen Größe.
var ErrTooLarge = errPolitik("die Datei ist größer als erlaubt")

// Receive nimmt einen Upload auf.
//
// Der Inhalt wird gestreamt, nicht gepuffert: Eine Datei von zwei Gigabyte darf
// bei MemoryMax=256M nichts anderes bedeuten als zwei Gigabyte auf der Platte.
// Geschrieben wird in eine Nachbardatei im Zielverzeichnis und erst danach
// umbenannt — ein abgebrochener Upload hinterlässt damit keine halbe Datei
// unter dem endgültigen Namen.
func (f *FileSystem) Receive(ctx context.Context, dir, name string, src io.Reader, opts ReceiveOptions) (FileEntry, error) {
	_ = ctx
	max := opts.MaxSize
	if max <= 0 || max > f.pol.MaxUpload {
		max = f.pol.MaxUpload
	}

	pf, err := f.wache.pruefenNeu(dir, name)
	if err != nil {
		return FileEntry{}, err
	}
	if pf.Info != nil {
		if !opts.Overwrite {
			return FileEntry{}, fmt.Errorf("%s gibt es bereits", pf.Roh)
		}
		if !pf.Info.Mode().IsRegular() {
			return FileEntry{}, fmt.Errorf("%w: %s", ErrNotRegular, pf.Roh)
		}
		// Vor dem Ersetzen eine Sicherung, wie beim Editor. Wer eine Datei
		// versehentlich mit der falschen überschreibt, hat sonst nichts mehr.
		vorher, err := f.rohLesen(pf, f.pol.MaxEditSize)
		if err == nil {
			if err := f.sichern(pf, vorher); err != nil {
				return FileEntry{}, err
			}
		}
	}

	mode := fs.FileMode(0o644)
	if pf.Info != nil {
		mode = pf.Info.Mode().Perm()
	}

	grenze := &grenzLeser{r: src, rest: max}
	if err := f.atomarSchreiben(pf, grenze, mode); err != nil {
		if grenze.ueber {
			return FileEntry{}, fmt.Errorf("%w: höchstens %s je Datei", ErrTooLarge, formatSize(max))
		}
		return FileEntry{}, err
	}

	info, err := pf.root.Lstat(pf.rel)
	if err != nil {
		return FileEntry{}, fmt.Errorf("%s: %w", pf.Roh, err)
	}
	return f.eintrag(pf.Echt, info, ladeNamensbuch()), nil
}

// grenzLeser bricht ab, sobald mehr Bytes kommen als erlaubt.
//
// Anders als io.LimitReader endet er nicht still mit EOF: Ein stillschweigend
// abgeschnittener Upload wäre die schlechtere Antwort — der Empfänger hielte
// eine unvollständige Datei für vollständig.
type grenzLeser struct {
	r     io.Reader
	rest  int64
	ueber bool
}

func (g *grenzLeser) Read(p []byte) (int, error) {
	if g.rest <= 0 {
		g.ueber = true
		return 0, ErrTooLarge
	}
	if int64(len(p)) > g.rest+1 {
		p = p[:g.rest+1]
	}
	n, err := g.r.Read(p)
	g.rest -= int64(n)
	if g.rest < 0 {
		g.ueber = true
		return n, ErrTooLarge
	}
	return n, err
}

// UploadName säubert einen aus einem Multipart-Teil gemeldeten Dateinamen.
//
// Der Wert kommt vom Browser und ist damit Eingabe wie jede andere. Manche
// Browser schicken den vollständigen Pfad der Datei auf dem Rechner des
// Benutzers; davon interessiert nur der letzte Bestandteil. Ein Name, der nach
// dem Säubern leer wäre oder aus Punkten besteht, wird abgelehnt statt geraten.
func UploadName(gemeldet string) (string, error) {
	name := filepath.Base(filepath.FromSlash(gemeldet))
	// Auch der Windows-Trenner: Ein "C:\Users\x\datei.txt" hat unter Linux
	// keinen einzigen Schrägstrich, und filepath.Base gäbe die ganze Kette.
	if i := lastIndexAny(name, `\/`); i >= 0 {
		name = name[i+1:]
	}
	if err := pruefeName(name); err != nil {
		return "", err
	}
	return name, nil
}

func lastIndexAny(s, chars string) int {
	for i := len(s) - 1; i >= 0; i-- {
		for j := 0; j < len(chars); j++ {
			if s[i] == chars[j] {
				return i
			}
		}
	}
	return -1
}
