package update

import "testing"

func TestParseVersion(t *testing.T) {
	tests := []struct {
		in   string
		want Version
	}{
		{"1.2.3", Version{Major: 1, Minor: 2, Patch: 3}},
		{"v1.2.3", Version{Major: 1, Minor: 2, Patch: 3}},
		{" 0.1.0 ", Version{Minor: 1}},
		{"1.2.3-rc.1", Version{Major: 1, Minor: 2, Patch: 3, Pre: "rc.1"}},
		{"1.2.3+abc", Version{Major: 1, Minor: 2, Patch: 3, Build: "abc"}},
		{"1.2.3-beta+abc", Version{Major: 1, Minor: 2, Patch: 3, Pre: "beta", Build: "abc"}},
		{"10.20.30", Version{Major: 10, Minor: 20, Patch: 30}},
	}
	for _, tc := range tests {
		got, err := ParseVersion(tc.in)
		if err != nil {
			t.Errorf("ParseVersion(%q): %v", tc.in, err)
			continue
		}
		if got != tc.want {
			t.Errorf("ParseVersion(%q) = %+v, erwartet %+v", tc.in, got, tc.want)
		}
	}
}

func TestParseVersionFehler(t *testing.T) {
	// "dev" steht in jedem selbst gebauten Binary und muss sauber scheitern,
	// nicht etwa als 0.0.0 durchgehen.
	for _, in := range []string{
		"", "dev", "1.2", "1.2.3.4", "1.2.x", "-1.2.3", "01.2.3", "1.2.3-",
	} {
		if v, err := ParseVersion(in); err == nil {
			t.Errorf("ParseVersion(%q) = %+v, Fehler erwartet", in, v)
		}
	}
}

func TestCompare(t *testing.T) {
	tests := []struct {
		a, b string
		want int
	}{
		{"1.0.0", "1.0.0", 0},
		{"1.0.1", "1.0.0", 1},
		{"1.1.0", "1.0.9", 1},
		{"2.0.0", "1.9.9", 1},
		{"0.1.0", "0.2.0", -1},
		// Eine Freigabe steht über der zugehörigen Vorabversion.
		{"1.0.0", "1.0.0-rc.1", 1},
		{"1.0.0-rc.1", "1.0.0", -1},
		// SemVer §11.4: numerisch vor alphanumerisch, Zahlen numerisch.
		{"1.0.0-alpha", "1.0.0-alpha.1", -1},
		{"1.0.0-alpha.1", "1.0.0-alpha.beta", -1},
		{"1.0.0-alpha.beta", "1.0.0-beta", -1},
		{"1.0.0-beta", "1.0.0-beta.2", -1},
		{"1.0.0-beta.2", "1.0.0-beta.11", -1},
		{"1.0.0-beta.11", "1.0.0-rc.1", -1},
		{"1.0.0-rc.1", "1.0.0", -1},
		// Build-Metadaten zählen nicht.
		{"1.0.0+a", "1.0.0+b", 0},
	}
	for _, tc := range tests {
		a, err := ParseVersion(tc.a)
		if err != nil {
			t.Fatalf("%q: %v", tc.a, err)
		}
		b, err := ParseVersion(tc.b)
		if err != nil {
			t.Fatalf("%q: %v", tc.b, err)
		}
		if got := a.Compare(b); got != tc.want {
			t.Errorf("Compare(%q, %q) = %d, erwartet %d", tc.a, tc.b, got, tc.want)
		}
		if got := b.Compare(a); got != -tc.want {
			t.Errorf("Compare(%q, %q) = %d, erwartet %d", tc.b, tc.a, got, -tc.want)
		}
	}
}

func TestVersionString(t *testing.T) {
	for _, in := range []string{"1.2.3", "1.2.3-rc.1", "1.2.3+abc", "1.2.3-rc.1+abc"} {
		v, err := ParseVersion(in)
		if err != nil {
			t.Fatalf("%q: %v", in, err)
		}
		if v.String() != in {
			t.Errorf("String() = %q, erwartet %q", v.String(), in)
		}
	}
}

func TestNewer(t *testing.T) {
	tests := []struct {
		current, candidate string
		want               bool
	}{
		{"0.1.0", "0.2.0", true},
		{"0.2.0", "0.1.0", false},
		{"0.2.0", "0.2.0", false},
		{"0.2.0-rc.1", "0.2.0", true},
		{"0.2.0", "0.2.0-rc.1", false},
		// Ein selbst gebautes Binary meldet "dev". Ein Update darauf wäre ein
		// Rückschritt in Unbekanntes und wird nicht angeboten.
		{"dev", "0.2.0", false},
		{"0.1.0", "kaputt", false},
	}
	for _, tc := range tests {
		if got := Newer(tc.current, tc.candidate); got != tc.want {
			t.Errorf("Newer(%q, %q) = %v, erwartet %v", tc.current, tc.candidate, got, tc.want)
		}
	}
}
