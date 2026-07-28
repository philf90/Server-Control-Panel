package privops

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"path/filepath"
	"strings"
)

// Archive schreibt einen Pfad als tar.gz-Strom.
//
// Gestreamt und ohne Zwischendatei: Ein Verzeichnis von mehreren Gigabyte darf
// weder den Speicher (MemoryMax=256M) noch die Platte des Servers belegen, nur
// weil jemand es herunterladen möchte.
//
// Ausgelassen wird, was nicht in ein Archiv gehört: gesperrte Pfade,
// Gerätedateien, Sockets, FIFOs und alles auf einem anderen Dateisystem — ein
// Archiv von /mnt würde sonst die eingehängte Platte mitnehmen. Wie viel
// ausgelassen wurde, steht im Ergebnis; die Oberfläche nennt die Zahl, statt
// Vollständigkeit zu suggerieren.
func (f *FileSystem) Archive(ctx context.Context, p string, w io.Writer) (ArchiveResult, error) {
	pf, err := f.wache.pruefen(p, zInhalt)
	if err != nil {
		return ArchiveResult{}, err
	}

	gz, err := gzip.NewWriterLevel(w, gzip.DefaultCompression)
	if err != nil {
		return ArchiveResult{}, err
	}
	tw := tar.NewWriter(gz)

	var res ArchiveResult
	// Der Name des Wurzelverzeichnisses steht im Archiv mit drin: Ein
	// Entpacken legt dann einen Ordner an, statt das Zielverzeichnis mit
	// Dateien zu übersäen.
	basis := filepath.Dir(pf.Echt)
	gerät := deviceOf(pf.Info)

	lauf := func(echt, rel string, info fs.FileInfo, tiefe int) error {
		if f.wache.istSensibel(echt) {
			res.Skipped++
			if info.IsDir() {
				return fs.SkipDir
			}
			return nil
		}
		if d := deviceOf(info); d != 0 && gerät != 0 && d != gerät {
			res.Skipped++
			if info.IsDir() {
				return fs.SkipDir
			}
			return nil
		}
		switch kindOf(info.Mode()) {
		case KindDir, KindRegular, KindSymlink:
		default:
			res.Skipped++
			return nil
		}

		name := strings.TrimPrefix(strings.TrimPrefix(echt, basis), "/")
		if name == "" {
			return nil
		}

		kopf, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return fmt.Errorf("%s: %w", echt, err)
		}
		kopf.Name = name
		if info.IsDir() {
			kopf.Name += "/"
		}
		if info.Mode()&fs.ModeSymlink != 0 {
			ziel, err := f.readlinkAbs(echt)
			if err != nil {
				res.Skipped++
				return nil
			}
			kopf.Linkname = ziel
			kopf.Size = 0
		}
		// Namen aus dem Namensbuch, nicht nur Nummern: Ein Archiv, das auf
		// einem anderen Rechner entpackt wird, ordnet die Dateien damit dem
		// gleichnamigen Konto zu.
		if err := tw.WriteHeader(kopf); err != nil {
			return fmt.Errorf("%s: %w", echt, err)
		}

		if !info.Mode().IsRegular() {
			return nil
		}
		qp, err := f.wache.pruefen(echt, zInhalt)
		if err != nil {
			// Zwischen Lauf und Öffnen abgelehnt oder verschwunden: auslassen,
			// aber der Kopf steht schon im Archiv — deshalb eine leere Datei.
			res.Skipped++
			return nil
		}
		datei, err := f.oeffnenNurLesen(qp)
		if err != nil {
			res.Skipped++
			return nil
		}
		defer func() { _ = datei.Close() }()

		n, err := io.CopyBuffer(tw, io.LimitReader(datei, info.Size()), make([]byte, kopierPuffer))
		if err != nil {
			return fmt.Errorf("%s: %w", echt, err)
		}
		// Ist die Datei zwischenzeitlich kürzer geworden, verlangt tar den Rest
		// als Nullbytes — sonst passt der Kopf nicht zum Inhalt und das Archiv
		// ist kaputt.
		if n < info.Size() {
			if _, err := io.CopyN(tw, nullLeser{}, info.Size()-n); err != nil {
				return fmt.Errorf("%s: %w", echt, err)
			}
		}
		res.Files++
		res.Bytes += info.Size()
		return nil
	}

	if pf.Info.IsDir() {
		err = f.gehen(ctx, pf, lauf)
	} else {
		err = lauf(pf.Echt, pf.rel, pf.Info, 0)
	}
	if err != nil && !errors.Is(err, errZuViel) {
		// Der Strom ist an dieser Stelle schon teilweise beim Empfänger. Mehr
		// als abbrechen und den Grund melden geht nicht — der HTTP-Status ist
		// längst gesendet. Der Aufrufer schreibt es ins Protokoll.
		_ = tw.Close()
		_ = gz.Close()
		return res, err
	}
	if err := tw.Close(); err != nil {
		_ = gz.Close()
		return res, err
	}
	if err := gz.Close(); err != nil {
		return res, err
	}
	if errors.Is(err, errZuViel) {
		res.Skipped++
	}
	return res, nil
}

// readlinkAbs liest das Ziel eines Verweises über die geprüfte Wurzel.
func (f *FileSystem) readlinkAbs(echt string) (string, error) {
	pf, err := f.wache.aufloesen(echt)
	if err != nil {
		return "", err
	}
	return pf.root.Readlink(pf.rel)
}

// nullLeser liefert beliebig viele Nullbytes.
type nullLeser struct{}

func (nullLeser) Read(p []byte) (int, error) {
	for i := range p {
		p[i] = 0
	}
	return len(p), nil
}
