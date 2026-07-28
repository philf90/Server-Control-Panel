package privops

import (
	"io/fs"
	"strings"
	"testing"
)

// TestDescribeMode: Aus einer Zahl werden Worte. Der Test hält die Zuordnung
// fest, weil sie das ist, was in der Oberfläche steht — eine falsche Zeile hier
// wäre eine falsche Auskunft über Rechte.
func TestDescribeMode(t *testing.T) {
	faelle := []struct {
		octal          string
		istVerzeichnis bool
		wollen         map[string]string
	}{
		{
			octal: "0644",
			wollen: map[string]string{
				"user":  "darf lesen und ändern",
				"group": "darf lesen",
				"other": "darf lesen",
			},
		},
		{
			octal: "0600",
			wollen: map[string]string{
				"user":  "darf lesen und ändern",
				"group": "darf nichts",
				"other": "darf nichts",
			},
		},
		{
			octal:          "0755",
			istVerzeichnis: true,
			wollen: map[string]string{
				"user":  "darf Inhalt auflisten, Einträge anlegen und löschen und hineinwechseln",
				"group": "darf Inhalt auflisten und hineinwechseln",
				"other": "darf Inhalt auflisten und hineinwechseln",
			},
		},
		{
			octal: "0755",
			wollen: map[string]string{
				"user": "darf lesen, ändern und ausführen",
			},
		},
	}

	for _, f := range faelle {
		t.Run(f.octal, func(t *testing.T) {
			mode, err := ParseMode(f.octal)
			if err != nil {
				t.Fatal(err)
			}
			b := DescribeMode(mode, f.istVerzeichnis)
			if b.Octal != f.octal {
				t.Errorf("Octal = %q, erwartet %q", b.Octal, f.octal)
			}
			if len(b.Roles) != 3 {
				t.Fatalf("%d Rollen, erwartet 3", len(b.Roles))
			}
			for _, rolle := range b.Roles {
				want, ok := f.wollen[rolle.Key]
				if !ok {
					continue
				}
				if rolle.Text != want {
					t.Errorf("%s: %q, erwartet %q", rolle.Key, rolle.Text, want)
				}
			}
		})
	}
}

// Die Bits müssen zur Aufschlüsselung passen — und zwar in beide Richtungen:
// Was DescribeMode als gesetzt meldet, muss ParseMode aus derselben Ziffer lesen.
func TestDescribeModeBitsStimmen(t *testing.T) {
	for v := 0; v <= 0o777; v++ {
		mode := modeFromBits(uint32(v)) //nolint:gosec // Schleife bis 0777
		b := DescribeMode(mode, false)

		var wieder uint32
		for _, rolle := range b.Roles {
			var drei uint32
			for _, recht := range rolle.Rights {
				if !recht.Set {
					continue
				}
				switch recht.Key {
				case "r":
					drei |= 4
				case "w":
					drei |= 2
				case "x":
					drei |= 1
				}
			}
			wieder = wieder<<3 | drei
		}
		if wieder != uint32(v) { //nolint:gosec // Schleife bis 0777
			t.Fatalf("%04o wurde als %04o aufgeschlüsselt", v, wieder)
		}
	}
}

// Die Sonderbits erklären die erste Ziffer und stehen deshalb immer in der
// Liste, gesetzt oder nicht.
func TestDescribeModeSonderbits(t *testing.T) {
	mode, err := ParseMode("4755")
	if err != nil {
		t.Fatal(err)
	}
	b := DescribeMode(mode, false)
	if len(b.Specials) != 3 {
		t.Fatalf("%d Sonderbits, erwartet 3", len(b.Specials))
	}
	nach := map[string]ModeSpecial{}
	for _, s := range b.Specials {
		nach[s.Key] = s
		if strings.TrimSpace(s.Text) == "" {
			t.Errorf("Sonderbit %q ohne Erklärung", s.Key)
		}
	}
	if !nach["setuid"].Set {
		t.Error("setuid wurde nicht erkannt")
	}
	if nach["setgid"].Set || nach["sticky"].Set {
		t.Error("ungesetzte Sonderbits gelten als gesetzt")
	}

	// Und das Sticky-Bit von /tmp.
	tmp, _ := ParseMode("1777")
	for _, s := range DescribeMode(tmp, true).Specials {
		if s.Key == "sticky" && !s.Set {
			t.Error("das Sticky-Bit von 1777 wurde nicht erkannt")
		}
	}
}

func TestAufzaehlung(t *testing.T) {
	faelle := map[string][]string{
		"nichts":           {},
		"lesen":            {"lesen"},
		"lesen und ändern": {"lesen", "ändern"},
		"a, b und c":       {"a", "b", "c"},
	}
	for want, teile := range faelle {
		if got := aufzaehlung(teile); got != want {
			t.Errorf("aufzaehlung(%v) = %q, erwartet %q", teile, got, want)
		}
	}
}

var _ fs.FileMode // die Aufschlüsselung arbeitet auf fs.FileMode
