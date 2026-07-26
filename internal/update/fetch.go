package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"path"
	"strings"

	"github.com/philf90/asylum/internal/version"
)

// Namen und Grenzen der Release-Artefakte.
const (
	checksumFile = "SHA256SUMS"
	// binaryName ist der Dateiname im Archiv.
	binaryName = "asylumd"

	maxChecksumSize  = 64 << 10 // die Prüfsummenliste eines Releases
	maxSignatureSize = 4 << 10  // vier Zeilen Base64
	maxArchiveSize   = 96 << 20 // Archiv; das Binary liegt bei ~14 MB
	maxBinarySize    = 96 << 20 // entpacktes Binary
)

func userAgent() string {
	return "asylumd/" + version.Version + " (+https://repo.cloudsrv24.de)"
}

// Package ist das geprüfte Ergebnis eines Downloads.
type Package struct {
	Version string
	// Binary ist das entpackte, gegen die signierte Prüfsummenliste geprüfte
	// Programm.
	Binary []byte
	// TrustedComment stammt aus der Signatur und nennt die Fassung.
	TrustedComment string
}

// Fetch lädt das Archiv einer Fassung und prüft die vollständige Kette:
//
//	minisign-Signatur → SHA256SUMS → SHA-256 des Archivs → Inhalt des Archivs
//
// Vertrauensanker ist allein der eingebaute öffentliche Schlüssel. Die
// Metadatendatei bestimmt nur, *wonach* gesucht wird; ob das Gefundene echt
// ist, entscheidet sie nicht.
func (c *Client) Fetch(ctx context.Context, rel Release, platform string, key PublicKey) (Package, error) {
	art, err := rel.ArtifactFor(platform)
	if err != nil {
		return Package{}, err
	}
	sumsURL, sigURL, err := rel.checksumURLs(art)
	if err != nil {
		return Package{}, err
	}

	sums, err := c.get(ctx, sumsURL, maxChecksumSize)
	if err != nil {
		return Package{}, err
	}
	sigFile, err := c.get(ctx, sigURL, maxSignatureSize)
	if err != nil {
		return Package{}, err
	}

	comment, err := Verify(sums, string(sigFile), key)
	if err != nil {
		return Package{}, fmt.Errorf("prüfsummenliste des Releases: %w", err)
	}

	// Der beglaubigte Kommentar nennt die Fassung und ist mitsigniert. Ohne
	// diesen Abgleich könnte eine gefälschte Metadatendatei die Signatur einer
	// *älteren*, echten Fassung vorlegen und so ein Downgrade erzwingen.
	if got := versionFromComment(comment); got != rel.Version {
		return Package{}, fmt.Errorf(
			"%w: die Signatur beglaubigt Fassung %q, die Metadaten nennen %q",
			ErrBadSignature, got, rel.Version)
	}

	name := path.Base(art.URL)
	want, err := checksumFor(string(sums), name)
	if err != nil {
		return Package{}, err
	}
	// Die Prüfsumme in den Metadaten ist bequem, aber nicht maßgeblich. Weicht
	// sie ab, stimmt etwas an der Veröffentlichung nicht — das ist ein Fehler
	// und keine Kleinigkeit, die man stillschweigend übergeht.
	if !strings.EqualFold(want, art.SHA256) {
		return Package{}, fmt.Errorf(
			"prüfsumme in den Metadaten (%s) weicht von der signierten Liste (%s) ab",
			art.SHA256, want)
	}

	archive, err := c.get(ctx, art.URL, maxArchiveSize)
	if err != nil {
		return Package{}, err
	}
	sum := sha256.Sum256(archive)
	if got := hex.EncodeToString(sum[:]); !strings.EqualFold(got, want) {
		return Package{}, fmt.Errorf("%w: %s hat die Prüfsumme %s, erwartet %s",
			ErrBadSignature, name, got, want)
	}

	bin, err := extractBinary(archive)
	if err != nil {
		return Package{}, err
	}
	return Package{Version: rel.Version, Binary: bin, TrustedComment: comment}, nil
}

// versionFromComment zieht die Fassung aus "Project Asylum 0.2.0".
func versionFromComment(comment string) string {
	fields := strings.Fields(comment)
	if len(fields) == 0 {
		return ""
	}
	last := fields[len(fields)-1]
	v, err := ParseVersion(last)
	if err != nil {
		return ""
	}
	return v.String()
}

// checksumFor sucht die Zeile "<hex>  <dateiname>" zu einem Dateinamen.
func checksumFor(sums, name string) (string, error) {
	for line := range strings.SplitSeq(sums, "\n") {
		fields := strings.Fields(strings.TrimSpace(line))
		if len(fields) != 2 {
			continue
		}
		// GNU coreutils setzt bei Binärdateien ein '*' vor den Namen.
		if strings.TrimPrefix(fields[1], "*") != name {
			continue
		}
		raw, err := hex.DecodeString(fields[0])
		if err != nil || len(raw) != sha256.Size {
			return "", fmt.Errorf("prüfsumme für %s ist unbrauchbar", name)
		}
		return strings.ToLower(fields[0]), nil
	}
	return "", fmt.Errorf("die signierte Prüfsummenliste kennt %s nicht", name)
}

// extractBinary holt genau eine Datei aus dem Archiv: asylumd.
//
// Bewusst wird nichts ins Dateisystem geschrieben. Damit sind Pfadausbrüche
// über "../" und untergeschobene Symlinks kein Thema — es gibt keinen Pfad,
// der entstehen könnte.
func extractBinary(archive []byte) ([]byte, error) {
	gz, err := gzip.NewReader(bytes.NewReader(archive))
	if err != nil {
		return nil, fmt.Errorf("archiv ist kein gzip: %w", err)
	}
	defer func() { _ = gz.Close() }()

	tr := tar.NewReader(gz)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, fmt.Errorf("archiv ist beschädigt: %w", err)
		}
		if path.Base(path.Clean(hdr.Name)) != binaryName || hdr.Typeflag != tar.TypeReg {
			continue
		}
		if hdr.Size > maxBinarySize {
			return nil, fmt.Errorf("%s im Archiv ist %d Byte groß", binaryName, hdr.Size)
		}

		bin, err := io.ReadAll(io.LimitReader(tr, maxBinarySize+1))
		if err != nil {
			return nil, fmt.Errorf("%s aus dem Archiv lesen: %w", binaryName, err)
		}
		if int64(len(bin)) > maxBinarySize {
			return nil, fmt.Errorf("%s im Archiv ist zu groß", binaryName)
		}
		if !isELF(bin) {
			return nil, fmt.Errorf("%s im Archiv ist kein Linux-Programm", binaryName)
		}
		return bin, nil
	}
	return nil, fmt.Errorf("das Archiv enthält kein %s", binaryName)
}

// isELF prüft die Kennung am Dateianfang. Ein Textfehler oder eine
// HTML-Fehlerseite fällt damit auf, bevor irgendetwas ausgeführt wird.
func isELF(b []byte) bool {
	return len(b) > 4 && b[0] == 0x7f && b[1] == 'E' && b[2] == 'L' && b[3] == 'F'
}
