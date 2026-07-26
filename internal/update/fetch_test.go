package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"golang.org/x/crypto/blake2b"
)

// Die Prüfung gegen echtes minisign-Material steht in minisign_test.go. Hier
// wird mit einem Wegwerfschlüssel signiert, damit die vollständige Kette —
// Metadaten, Signatur, Prüfsumme, Archiv — an jeder Stelle gezielt beschädigt
// werden kann.

type signer struct {
	pub  PublicKey
	priv ed25519.PrivateKey
}

func newSigner(t *testing.T) signer {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("Schlüssel erzeugen: %v", err)
	}
	key := PublicKey{Key: pub}
	copy(key.KeyID[:], []byte{9, 8, 7, 6, 5, 4, 3, 2})
	return signer{pub: key, priv: priv}
}

// sign erzeugt eine .minisig im Verfahren "ED" (BLAKE2b-Vorabhash), so wie es
// minisign heute schreibt.
func (s signer) sign(content []byte, comment string) string {
	h := blake2b.Sum512(content)
	sum := h[:]
	sig := ed25519.Sign(s.priv, sum)

	block := append([]byte(algPrehashed), s.pub.KeyID[:]...)
	block = append(block, sig...)
	global := ed25519.Sign(s.priv, append(append([]byte(nil), sig...), []byte(comment)...))

	return strings.Join([]string{
		"untrusted comment: signature from minisign secret key",
		base64.StdEncoding.EncodeToString(block),
		trustedCommentPrefix + comment,
		base64.StdEncoding.EncodeToString(global),
	}, "\n") + "\n"
}

// fakeELF liefert Bytes, die die ELF-Prüfung passieren.
func fakeELF(marker string) []byte {
	return append([]byte{0x7f, 'E', 'L', 'F', 2, 1, 1, 0}, []byte(marker)...)
}

func tarGz(t *testing.T, files map[string][]byte) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for name, body := range files {
		hdr := &tar.Header{Name: name, Mode: 0o755, Size: int64(len(body)), Typeflag: tar.TypeReg}
		if err := tw.WriteHeader(hdr); err != nil {
			t.Fatalf("tar header: %v", err)
		}
		if _, err := tw.Write(body); err != nil {
			t.Fatalf("tar write: %v", err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatalf("tar close: %v", err)
	}
	if err := gz.Close(); err != nil {
		t.Fatalf("gzip close: %v", err)
	}
	return buf.Bytes()
}

func sha256hex(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// releaseFixture stellt einen vollständigen, signierten Release über HTTPS
// bereit und lässt sich vor dem Start gezielt verbiegen.
type releaseFixture struct {
	t        *testing.T
	signer   signer
	version  string
	archive  []byte
	sums     string
	sig      string
	metadata map[string]any
	srv      *httptest.Server
}

func newReleaseFixture(t *testing.T) *releaseFixture {
	t.Helper()
	f := &releaseFixture{t: t, signer: newSigner(t), version: "0.2.0"}

	f.archive = tarGz(t, map[string][]byte{
		"asylumd":   fakeELF("neue Fassung"),
		"LICENSE":   []byte("Apache-2.0"),
		"README.md": []byte("# Asylum"),
	})
	name := "asylumd_" + f.version + "_linux_amd64.tar.gz"
	f.sums = sha256hex(f.archive) + "  " + name + "\n"
	f.sig = f.signer.sign([]byte(f.sums), "Project Asylum "+f.version)
	f.metadata = map[string]any{
		"version":             f.version,
		"released_at":         "2026-07-26T10:00:00Z",
		"min_upgradable_from": "0.1.0",
		"notes_url":           "https://example.invalid/notes",
		"severity":            "normal",
		"artifacts": map[string]any{
			"linux_amd64": map[string]any{
				"url":    "PLACEHOLDER/" + name,
				"sha256": sha256hex(f.archive),
			},
		},
	}
	return f
}

// start hebt den Server an und setzt die Platzhalter in den Metadaten.
func (f *releaseFixture) start() *Client {
	f.t.Helper()

	mux := http.NewServeMux()
	f.srv = httptest.NewTLSServer(mux)
	f.t.Cleanup(f.srv.Close)

	// httptest liefert https auf 127.0.0.1 — genau das verlangt requireHTTPS.
	base := f.srv.URL

	arts, _ := f.metadata["artifacts"].(map[string]any)
	for _, v := range arts {
		a, _ := v.(map[string]any)
		if u, ok := a["url"].(string); ok {
			a["url"] = strings.Replace(u, "PLACEHOLDER", base+"/dl", 1)
		}
	}
	for _, k := range []string{"checksums_url", "signature_url"} {
		if u, ok := f.metadata[k].(string); ok {
			f.metadata[k] = strings.Replace(u, "PLACEHOLDER", base+"/dl", 1)
		}
	}

	mux.HandleFunc("/updates/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(f.metadata)
	})
	mux.HandleFunc("/dl/", func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, ".minisig"):
			_, _ = w.Write([]byte(f.sig))
		case strings.HasSuffix(r.URL.Path, checksumFile):
			_, _ = w.Write([]byte(f.sums))
		case strings.HasSuffix(r.URL.Path, ".tar.gz"):
			_, _ = w.Write(f.archive)
		default:
			http.NotFound(w, r)
		}
	})

	c := NewClient()
	c.BaseURL = base
	c.HTTP = f.srv.Client()
	return c
}

func (f *releaseFixture) fetch(c *Client) (Package, error) {
	f.t.Helper()
	rel, err := c.Latest(context.Background(), ChannelStable)
	if err != nil {
		return Package{}, err
	}
	return c.Fetch(context.Background(), rel, "linux_amd64", f.signer.pub)
}

func TestFetchVollstaendigeKette(t *testing.T) {
	f := newReleaseFixture(t)
	c := f.start()

	pkg, err := f.fetch(c)
	if err != nil {
		t.Fatalf("Fetch: %v", err)
	}
	if pkg.Version != "0.2.0" {
		t.Errorf("Version = %q", pkg.Version)
	}
	if !bytes.Equal(pkg.Binary, fakeELF("neue Fassung")) {
		t.Error("das entpackte Binary weicht ab")
	}
	if pkg.TrustedComment != "Project Asylum 0.2.0" {
		t.Errorf("beglaubigter Kommentar = %q", pkg.TrustedComment)
	}
}

func TestFetchAbweisungen(t *testing.T) {
	// Jeder Fall nennt den erwarteten Grund. Ohne diese Erwartung würde ein
	// Test auch dann grün bleiben, wenn die Kette aus einem ganz anderen —
	// womöglich harmlosen — Grund abbricht und die eigentliche Prüfung
	// unbemerkt ausfällt.
	tests := map[string]struct {
		breakIt func(f *releaseFixture)
		want    string
	}{
		"manipuliertes Archiv": {want: "hat die Prüfsumme", breakIt: func(f *releaseFixture) {
			// Prüfsumme und Signatur bleiben stehen, nur die Datei ändert sich —
			// der Fall eines ausgetauschten Downloads.
			f.archive = tarGz(f.t, map[string][]byte{"asylumd": fakeELF("Schadcode")})
		}},
		"manipulierte Prüfsummenliste": {want: "signatur ist ungültig", breakIt: func(f *releaseFixture) {
			bad := tarGz(f.t, map[string][]byte{"asylumd": fakeELF("Schadcode")})
			f.archive = bad
			f.sums = sha256hex(bad) + "  asylumd_0.2.0_linux_amd64.tar.gz\n"
			// Signatur *nicht* neu erzeugt: sie deckt die alte Liste ab.
		}},
		"fremder Signaturschlüssel": {want: "signatur ist ungültig", breakIt: func(f *releaseFixture) {
			other := newSigner(f.t)
			other.pub.KeyID = f.signer.pub.KeyID
			f.sig = other.sign([]byte(f.sums), "Project Asylum "+f.version)
		}},
		"Signatur einer anderen Fassung": {want: "beglaubigt Fassung", breakIt: func(f *releaseFixture) {
			// Der klassische Downgrade-Versuch: eine echte, aber ältere
			// Signatur unter neuen Metadaten.
			f.sig = f.signer.sign([]byte(f.sums), "Project Asylum 0.1.0")
		}},
		"Metadaten nennen andere Prüfsumme": {want: "weicht von der signierten Liste", breakIt: func(f *releaseFixture) {
			arts, _ := f.metadata["artifacts"].(map[string]any)
			a, _ := arts["linux_amd64"].(map[string]any)
			a["sha256"] = sha256hex([]byte("etwas anderes"))
		}},
		"Archiv nicht in der Liste": {want: "kennt asylumd_0.2.0_linux_amd64.tar.gz nicht", breakIt: func(f *releaseFixture) {
			f.sums = sha256hex(f.archive) + "  ein_anderer_name.tar.gz\n"
			f.sig = f.signer.sign([]byte(f.sums), "Project Asylum "+f.version)
		}},
		"Archiv ohne asylumd": {want: "enthält kein asylumd", breakIt: func(f *releaseFixture) {
			f.archive = tarGz(f.t, map[string][]byte{"LICENSE": []byte("nur Text")})
			f.refresh()
		}},
		"asylumd ist kein Programm": {want: "kein Linux-Programm", breakIt: func(f *releaseFixture) {
			f.archive = tarGz(f.t, map[string][]byte{"asylumd": []byte("<html>404</html>")})
			f.refresh()
		}},
		"Download über http": {want: "nur https ist zulässig", breakIt: func(f *releaseFixture) {
			arts, _ := f.metadata["artifacts"].(map[string]any)
			a, _ := arts["linux_amd64"].(map[string]any)
			a["url"] = "http://example.invalid/asylumd_0.2.0_linux_amd64.tar.gz"
		}},
		"Prüfsummenliste anderswoher": {want: "nur https ist zulässig", breakIt: func(f *releaseFixture) {
			f.metadata["checksums_url"] = "http://example.invalid/SHA256SUMS"
		}},
	}

	for name, tc := range tests {
		t.Run(name, func(t *testing.T) {
			f := newReleaseFixture(t)
			tc.breakIt(f)
			c := f.start()
			pkg, err := f.fetch(c)
			if err == nil {
				t.Fatalf("Fehler erwartet, bekam Fassung %q", pkg.Version)
			}
			if !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("abgewiesen mit %q, erwartet wurde ein Grund mit %q", err, tc.want)
			}
		})
	}
}

// refresh erzeugt Prüfsumme und Signatur zum aktuellen Archiv neu. Für Fälle,
// in denen nicht die Kette, sondern der Inhalt geprüft werden soll.
func (f *releaseFixture) refresh() {
	name := "asylumd_" + f.version + "_linux_amd64.tar.gz"
	f.sums = sha256hex(f.archive) + "  " + name + "\n"
	f.sig = f.signer.sign([]byte(f.sums), "Project Asylum "+f.version)
	arts, _ := f.metadata["artifacts"].(map[string]any)
	a, _ := arts["linux_amd64"].(map[string]any)
	a["sha256"] = sha256hex(f.archive)
}

func TestFetchAbgeleiteteAdressen(t *testing.T) {
	// Ohne checksums_url müssen SHA256SUMS und Signatur aus der Archivadresse
	// abgeleitet werden — so sehen ältere Metadaten aus.
	f := newReleaseFixture(t)
	delete(f.metadata, "checksums_url")
	c := f.start()

	if _, err := f.fetch(c); err != nil {
		t.Fatalf("Fetch: %v", err)
	}
}

func TestFetchExplizteAdressen(t *testing.T) {
	f := newReleaseFixture(t)
	f.metadata["checksums_url"] = "PLACEHOLDER/" + checksumFile
	f.metadata["signature_url"] = "PLACEHOLDER/" + checksumFile + ".minisig"
	c := f.start()

	if _, err := f.fetch(c); err != nil {
		t.Fatalf("Fetch: %v", err)
	}
}

func TestFetchUnbekanntePlattform(t *testing.T) {
	f := newReleaseFixture(t)
	c := f.start()
	rel, err := c.Latest(context.Background(), ChannelStable)
	if err != nil {
		t.Fatalf("Latest: %v", err)
	}
	if _, err := c.Fetch(context.Background(), rel, "linux_riscv64", f.signer.pub); err == nil {
		t.Fatal("Fehler erwartet")
	}
}

func TestChecksumFor(t *testing.T) {
	list := strings.Join([]string{
		"aa" + strings.Repeat("00", 31) + "  asylumd_0.2.0_linux_amd64.tar.gz",
		"bb" + strings.Repeat("00", 31) + " *asylumd_0.2.0_linux_arm64.tar.gz",
		"# ein Kommentar",
		"",
	}, "\n")

	got, err := checksumFor(list, "asylumd_0.2.0_linux_arm64.tar.gz")
	if err != nil {
		t.Fatalf("checksumFor: %v", err)
	}
	if want := "bb" + strings.Repeat("00", 31); got != want {
		t.Errorf("= %s, erwartet %s", got, want)
	}
	if _, err := checksumFor(list, "gibt_es_nicht.tar.gz"); err == nil {
		t.Error("Fehler erwartet")
	}
	if _, err := checksumFor("zzz  datei.tar.gz", "datei.tar.gz"); err == nil {
		t.Error("Fehler bei unbrauchbarer Prüfsumme erwartet")
	}
}

func TestVersionFromComment(t *testing.T) {
	tests := map[string]string{
		"Project Asylum 0.2.0":   "0.2.0",
		"Project Asylum v0.2.0":  "0.2.0",
		"Asylum 1.0.0-rc.1":      "1.0.0-rc.1",
		"ohne Nummer":            "",
		"":                       "",
		"Project Asylum 0.2":     "",
		"Project Asylum 0.2.0 x": "",
	}
	for in, want := range tests {
		if got := versionFromComment(in); got != want {
			t.Errorf("versionFromComment(%q) = %q, erwartet %q", in, got, want)
		}
	}
}

func TestLatestFehler(t *testing.T) {
	t.Run("unbekannter Kanal", func(t *testing.T) {
		c := NewClient()
		if _, err := c.Latest(context.Background(), "nightly"); err == nil {
			t.Fatal("Fehler erwartet")
		}
	})

	tests := map[string]string{
		"kein JSON":       "das ist kein json",
		"ohne Version":    `{"artifacts":{"linux_amd64":{"url":"https://x.invalid/a","sha256":"` + strings.Repeat("0", 64) + `"}}}`,
		"ohne Artefakte":  `{"version":"0.2.0","artifacts":{}}`,
		"kaputte Version": `{"version":"null","artifacts":{"linux_amd64":{"url":"https://x.invalid/a","sha256":"` + strings.Repeat("0", 64) + `"}}}`,
		"min_upgradable":  `{"version":"0.2.0","min_upgradable_from":"x","artifacts":{"linux_amd64":{"url":"https://x.invalid/a","sha256":"` + strings.Repeat("0", 64) + `"}}}`,
	}
	for name, body := range tests {
		t.Run(name, func(t *testing.T) {
			srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				_, _ = w.Write([]byte(body))
			}))
			defer srv.Close()

			c := NewClient()
			c.BaseURL = srv.URL
			c.HTTP = srv.Client()
			if _, err := c.Latest(context.Background(), ChannelStable); err == nil {
				t.Fatal("Fehler erwartet")
			}
		})
	}

	t.Run("HTTP-Fehler", func(t *testing.T) {
		srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			http.NotFound(w, r)
		}))
		defer srv.Close()
		c := NewClient()
		c.BaseURL = srv.URL
		c.HTTP = srv.Client()
		if _, err := c.Latest(context.Background(), ChannelStable); err == nil {
			t.Fatal("Fehler erwartet")
		}
	})

	t.Run("zu große Antwort", func(t *testing.T) {
		srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			_, _ = w.Write(bytes.Repeat([]byte("x"), maxMetadataSize+1))
		}))
		defer srv.Close()
		c := NewClient()
		c.BaseURL = srv.URL
		c.HTTP = srv.Client()
		if _, err := c.Latest(context.Background(), ChannelStable); err == nil {
			t.Fatal("Fehler erwartet")
		}
	})
}

func TestRequireHTTPS(t *testing.T) {
	for _, ok := range []string{"https://a.invalid/x", "https://a.invalid:8443/x"} {
		if err := requireHTTPS(ok); err != nil {
			t.Errorf("requireHTTPS(%q): %v", ok, err)
		}
	}
	for _, bad := range []string{"http://a.invalid/x", "file:///etc/passwd", "https:///x", "://"} {
		if err := requireHTTPS(bad); err == nil {
			t.Errorf("requireHTTPS(%q): Fehler erwartet", bad)
		}
	}
}

func TestPlatformUndKanal(t *testing.T) {
	if !strings.HasPrefix(Platform(), "linux_") && !strings.Contains(Platform(), "_") {
		t.Errorf("Platform() = %q", Platform())
	}
	if !ValidChannel(ChannelStable) || !ValidChannel(ChannelBeta) || ValidChannel("nightly") {
		t.Error("ValidChannel arbeitet nicht wie erwartet")
	}
}

func TestExtractBinaryFehler(t *testing.T) {
	if _, err := extractBinary([]byte("kein gzip")); err == nil {
		t.Error("Fehler erwartet")
	}
	if _, err := extractBinary([]byte{0x1f, 0x8b, 0x08, 0, 0, 0, 0, 0, 0, 0}); err == nil {
		t.Error("Fehler bei abgeschnittenem Archiv erwartet")
	}
}

func TestUserAgent(t *testing.T) {
	if !strings.HasPrefix(userAgent(), "asylumd/") {
		t.Errorf("userAgent() = %q", userAgent())
	}
}
