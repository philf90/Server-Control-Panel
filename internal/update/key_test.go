package update

import (
	"os"
	"strings"
	"testing"
)

func TestProjectKey(t *testing.T) {
	key, err := ProjectKey()
	if err != nil {
		t.Fatalf("ProjectKey: %v", err)
	}
	if len(key.Key) != 32 {
		t.Fatalf("Schlüssel hat %d Byte", len(key.Key))
	}
	// Zweiter Aufruf: derselbe Schlüssel, kein erneutes Parsen.
	again, err := ProjectKey()
	if err != nil || again.KeyID != key.KeyID {
		t.Fatalf("zweiter Aufruf liefert etwas anderes: %v, %v", again, err)
	}
}

// TestEingebauterSchluesselPasstZumRepository hält die drei Orte zusammen, an
// denen der öffentliche Schlüssel steht. Laufen sie auseinander, lehnt der
// Installer ab, was der Daemon annimmt — oder umgekehrt.
func TestEingebauterSchluesselPasstZumRepository(t *testing.T) {
	files := map[string]string{
		"../../packaging/minisign.pub": "",
		"../../packaging/install.sh":   "",
	}
	for path := range files {
		b, err := os.ReadFile(path)
		if err != nil {
			t.Fatalf("%s lesen: %v", path, err)
		}
		files[path] = string(b)
	}

	if got := lastNonEmptyLine(files["../../packaging/minisign.pub"]); got != EmbeddedPublicKey {
		t.Errorf("packaging/minisign.pub hält\n  %s\naber eingebaut ist\n  %s", got, EmbeddedPublicKey)
	}
	if !strings.Contains(files["../../packaging/install.sh"], EmbeddedPublicKey) {
		t.Error("packaging/install.sh enthält einen anderen Schlüssel als das Binary")
	}
}
