package update

import (
	"fmt"
	"strconv"
	"strings"
)

// Versionen folgen SemVer 2.0.0. Die Vergleichslogik steht hier selbst, weil
// sie kurz ist und die Alternative — eine weitere Abhängigkeit — für vier
// Dutzend Zeilen nicht lohnt. Wichtig ist vor allem, dass Vorabversionen
// korrekt *vor* der zugehörigen Freigabe einsortiert werden: sonst gälte
// 0.2.0-rc1 als neuer als 0.2.0 und ein Beta-Tester bekäme nie die finale
// Fassung.

// Version ist eine zerlegte SemVer-Angabe.
type Version struct {
	Major, Minor, Patch int
	Pre                 string // ohne führenden Bindestrich, leer bei einer Freigabe
	Build               string // Metadaten nach '+', für den Vergleich ohne Bedeutung
}

// ParseVersion zerlegt "1.2.3", "v1.2.3", "1.2.3-rc.1" oder "1.2.3+abc".
func ParseVersion(s string) (Version, error) {
	var v Version

	s = strings.TrimSpace(s)
	s = strings.TrimPrefix(s, "v")
	if s == "" {
		return v, fmt.Errorf("leere Versionsangabe")
	}

	if i := strings.IndexByte(s, '+'); i >= 0 {
		v.Build = s[i+1:]
		s = s[:i]
	}
	if i := strings.IndexByte(s, '-'); i >= 0 {
		v.Pre = s[i+1:]
		s = s[:i]
		if v.Pre == "" {
			return Version{}, fmt.Errorf("leere Vorabkennung in %q", s)
		}
	}

	parts := strings.Split(s, ".")
	if len(parts) != 3 {
		return Version{}, fmt.Errorf("%q ist keine Version der Form MAJOR.MINOR.PATCH", s)
	}
	nums := make([]int, 3)
	for i, p := range parts {
		n, err := strconv.Atoi(p)
		if err != nil || n < 0 || (len(p) > 1 && p[0] == '0') {
			return Version{}, fmt.Errorf("%q ist keine gültige Zahl in einer Version", p)
		}
		nums[i] = n
	}
	v.Major, v.Minor, v.Patch = nums[0], nums[1], nums[2]
	return v, nil
}

// IsPrerelease meldet, ob es sich um eine Vorabversion handelt.
func (v Version) IsPrerelease() bool { return v.Pre != "" }

// String setzt die Version wieder zusammen.
func (v Version) String() string {
	s := fmt.Sprintf("%d.%d.%d", v.Major, v.Minor, v.Patch)
	if v.Pre != "" {
		s += "-" + v.Pre
	}
	if v.Build != "" {
		s += "+" + v.Build
	}
	return s
}

// Compare liefert -1, 0 oder 1. Build-Metadaten bleiben außen vor, so will es
// SemVer: 1.0.0+a und 1.0.0+b sind dieselbe Version.
func (v Version) Compare(o Version) int {
	for _, p := range [][2]int{
		{v.Major, o.Major}, {v.Minor, o.Minor}, {v.Patch, o.Patch},
	} {
		if p[0] != p[1] {
			return sign(p[0] - p[1])
		}
	}
	return comparePre(v.Pre, o.Pre)
}

// comparePre vergleicht die Vorabkennungen nach SemVer §11.4.
func comparePre(a, b string) int {
	switch {
	case a == b:
		return 0
	case a == "":
		return 1 // eine Freigabe steht über jeder Vorabversion
	case b == "":
		return -1
	}

	as, bs := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(as) && i < len(bs); i++ {
		an, aNum := toNumber(as[i])
		bn, bNum := toNumber(bs[i])
		switch {
		case aNum && bNum:
			if an != bn {
				return sign(an - bn)
			}
		case aNum != bNum:
			// Rein numerische Bezeichner sind kleiner als alphanumerische.
			if aNum {
				return -1
			}
			return 1
		case as[i] != bs[i]:
			return strings.Compare(as[i], bs[i])
		}
	}
	// Bei gleichem Anfang entscheidet die Anzahl der Bezeichner.
	return sign(len(as) - len(bs))
}

func toNumber(s string) (int, bool) {
	n, err := strconv.Atoi(s)
	if err != nil || n < 0 {
		return 0, false
	}
	return n, true
}

func sign(n int) int {
	switch {
	case n < 0:
		return -1
	case n > 0:
		return 1
	}
	return 0
}

// Newer meldet, ob candidate neuer als current ist. Nicht deutbare Angaben
// gelten als "nicht neuer": Ein Update wird nur auf sicherem Grund angeboten.
func Newer(current, candidate string) bool {
	c, err := ParseVersion(current)
	if err != nil {
		// "dev" — ein selbst gebautes Binary. Hier gibt es nichts zu vergleichen.
		return false
	}
	n, err := ParseVersion(candidate)
	if err != nil {
		return false
	}
	return n.Compare(c) > 0
}
