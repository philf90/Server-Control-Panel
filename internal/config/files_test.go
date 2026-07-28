package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestParseSize(t *testing.T) {
	gut := map[string]int64{
		"":         0,
		"1024":     1024,
		"512B":     512,
		"4KiB":     4 << 10,
		"2MiB":     2 << 20,
		"2GiB":     2 << 30,
		" 8 MiB  ": 8 << 20,
		"0":        0,
	}
	for ein, aus := range gut {
		got, err := ParseSize(ein)
		if err != nil {
			t.Errorf("ParseSize(%q): %v", ein, err)
			continue
		}
		if got != aus {
			t.Errorf("ParseSize(%q) = %d, erwartet %d", ein, got, aus)
		}
	}

	// "2GB" wird bewusst nicht angenommen: Eine Grenze, die als GB dasteht und
	// GiB bedeutet, wäre eine kleine Unwahrheit an einer Stelle, an der es auf
	// Zahlen ankommt.
	for _, ein := range []string{"2GB", "zwei", "-1", "1,5MiB", "MiB", "1e9"} {
		if _, err := ParseSize(ein); err == nil {
			t.Errorf("ParseSize(%q) wurde angenommen", ein)
		}
	}
}

func TestFilesVorgabeIstAn(t *testing.T) {
	var f Files
	if !f.On() {
		t.Error("ohne Eintrag ist der Dateimanager aus, erwartet an")
	}
	aus := false
	f.Enabled = &aus
	if f.On() {
		t.Error("enabled: false schaltet nicht ab")
	}
	an := true
	f.Enabled = &an
	if !f.On() {
		t.Error("enabled: true schaltet nicht ein")
	}
}

func TestFilesValidate(t *testing.T) {
	faelle := map[string]struct {
		f    Files
		teil string
	}{
		"relative Lesewurzel": {
			Files{ReadableRoots: []string{"etc"}}, "absoluter Pfad",
		},
		"Lesewurzel nicht normalisiert": {
			Files{ReadableRoots: []string{"/etc/"}}, "Normalform",
		},
		"relative Schreibwurzel": {
			Files{WritableRoots: []string{"../etc"}}, "absoluter Pfad",
		},
		"relative Sperre": {
			Files{DeniedPaths: []string{"*.key"}}, "absoluter Pfad",
		},
		"unlesbare Obergrenze": {
			Files{MaxUpload: "viel"}, "files.max_upload",
		},
		"unlesbare Editorgrenze": {
			Files{MaxEditSize: "2 Gigabyte"}, "files.max_edit_size",
		},
	}
	for name, f := range faelle {
		t.Run(name, func(t *testing.T) {
			err := f.f.validate()
			if err == nil {
				t.Fatal("wurde angenommen")
			}
			if !strings.Contains(err.Error(), f.teil) {
				t.Errorf("Meldung %q nennt %q nicht", err, f.teil)
			}
		})
	}

	gut := Files{
		ReadableRoots: []string{"/"},
		WritableRoots: []string{"/etc", "/home"},
		DeniedPaths:   []string{"/etc/ssl/private/*"},
		MaxUpload:     "1GiB",
		MaxEditSize:   "1MiB",
	}
	if err := gut.validate(); err != nil {
		t.Fatalf("eine gültige Angabe wurde abgelehnt: %v", err)
	}
	upload, edit, err := gut.Limits()
	if err != nil {
		t.Fatal(err)
	}
	if upload != 1<<30 || edit != 1<<20 {
		t.Errorf("Limits: %d, %d", upload, edit)
	}
}

// TestFilesAusDerDatei prüft den Weg, den ein Betreiber wirklich geht: Block in
// die YAML-Datei, Load, fertig.
func TestFilesAusDerDatei(t *testing.T) {
	dir := t.TempDir()
	pfad := filepath.Join(dir, "config.yaml")
	inhalt := `
files:
  enabled: true
  readable_roots: ["/etc", "/home"]
  writable_roots: ["/home"]
  denied_paths: ["/home/*/.gnupg"]
  follow_symlinks: true
  max_upload: 512MiB
  max_edit_size: 128KiB
`
	if err := os.WriteFile(pfad, []byte(inhalt), 0o600); err != nil {
		t.Fatal(err)
	}
	cfg, err := Load(pfad)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if !cfg.Files.On() {
		t.Error("der Block wurde nicht übernommen")
	}
	if len(cfg.Files.ReadableRoots) != 2 || cfg.Files.ReadableRoots[1] != "/home" {
		t.Errorf("ReadableRoots %v", cfg.Files.ReadableRoots)
	}
	if !cfg.Files.FollowSymlinks {
		t.Error("follow_symlinks wurde nicht übernommen")
	}
	upload, edit, err := cfg.Files.Limits()
	if err != nil {
		t.Fatal(err)
	}
	if upload != 512<<20 || edit != 128<<10 {
		t.Errorf("Grenzen: %d, %d", upload, edit)
	}

	// Eine fehlerhafte Angabe verhindert den Start, statt später zu überraschen.
	if err := os.WriteFile(pfad, []byte("files:\n  max_upload: zwei\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(pfad); err == nil {
		t.Error("eine unlesbare Größenangabe wurde beim Laden angenommen")
	}
}
