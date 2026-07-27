package metrics

import (
	"testing"
	"time"
)

func TestRingKeepsNewestAndOrders(t *testing.T) {
	r := NewRing(3)

	if _, ok := r.Last(); ok {
		t.Error("leerer Ring darf keinen Snapshot liefern")
	}
	if r.Len() != 0 {
		t.Errorf("Len = %d, erwartet 0", r.Len())
	}

	base := time.Now()
	for i := 0; i < 5; i++ {
		r.Add(Snapshot{At: base.Add(time.Duration(i) * time.Second)})
	}

	if r.Len() != 3 {
		t.Fatalf("Len = %d, erwartet 3", r.Len())
	}

	all := r.All()
	if len(all) != 3 {
		t.Fatalf("All() = %d Einträge, erwartet 3", len(all))
	}
	// Die ältesten beiden sind überschrieben, die Reihenfolge bleibt chronologisch.
	for i := 1; i < len(all); i++ {
		if !all[i].At.After(all[i-1].At) {
			t.Errorf("Reihenfolge stimmt nicht: %v vor %v", all[i-1].At, all[i].At)
		}
	}
	if !all[0].At.Equal(base.Add(2 * time.Second)) {
		t.Errorf("ältester Eintrag = %v, erwartet %v", all[0].At, base.Add(2*time.Second))
	}

	last, ok := r.Last()
	if !ok || !last.At.Equal(base.Add(4*time.Second)) {
		t.Errorf("Last() = %v, erwartet %v", last.At, base.Add(4*time.Second))
	}
}

func TestRingPartiallyFilled(t *testing.T) {
	r := NewRing(10)
	base := time.Now()
	for i := 0; i < 3; i++ {
		r.Add(Snapshot{At: base.Add(time.Duration(i) * time.Second)})
	}

	if got := len(r.All()); got != 3 {
		t.Errorf("All() = %d, erwartet 3 — der ungefüllte Rest darf nicht mitkommen", got)
	}
}

func TestSamplerReadsSystem(t *testing.T) {
	s := NewSampler()

	host := s.Host()
	if host.Hostname == "" {
		t.Error("Hostname fehlt")
	}
	if host.Cores < 1 {
		t.Errorf("Cores = %d", host.Cores)
	}
	if host.Kernel == "" {
		t.Error("Kernel-Version fehlt")
	}

	first := s.Sample()
	if first.Memory.Total == 0 {
		t.Error("Gesamtspeicher = 0 — /proc/meminfo wurde nicht gelesen")
	}
	if first.Uptime <= 0 {
		t.Error("Laufzeit = 0 — /proc/uptime wurde nicht gelesen")
	}
	if len(first.Filesystems) == 0 {
		t.Error("kein Dateisystem gefunden")
	}
	if len(first.TopProcesses) == 0 {
		t.Error("kein Prozess gefunden")
	}

	// Der zweite Aufruf liefert die Delta-Werte; sie müssen in [0,100] liegen.
	second := s.Sample()
	if second.CPU.Total < 0 || second.CPU.Total > 100 {
		t.Errorf("CPU-Auslastung = %.2f", second.CPU.Total)
	}
	if second.Memory.UsedPct < 0 || second.Memory.UsedPct > 100 {
		t.Errorf("Speicherauslastung = %.2f", second.Memory.UsedPct)
	}
	for _, fs := range second.Filesystems {
		if fs.UsedPct < 0 || fs.UsedPct > 100 {
			t.Errorf("%s: Belegung = %.2f", fs.Mount, fs.UsedPct)
		}
	}
	for _, p := range second.TopProcesses {
		if p.CPUPct < 0 || p.CPUPct > 100 {
			t.Errorf("PID %d: CPU = %.2f", p.PID, p.CPUPct)
		}
	}
}

func TestSamplerTopListIsSortedAndBounded(t *testing.T) {
	s := NewSampler()
	s.Sample()
	snap := s.Sample()

	if len(snap.TopProcesses) > 8 {
		t.Errorf("%d Prozesse in der Top-Liste, erwartet höchstens 8", len(snap.TopProcesses))
	}
	for i := 1; i < len(snap.TopProcesses); i++ {
		prev, cur := snap.TopProcesses[i-1], snap.TopProcesses[i]
		if cur.CPUPct > prev.CPUPct {
			t.Errorf("Liste ist nicht nach CPU sortiert: %.2f vor %.2f", prev.CPUPct, cur.CPUPct)
		}
	}
}

func TestFormatUptime(t *testing.T) {
	tests := map[time.Duration]string{
		0:                "unbekannt",
		90 * time.Second: "1 Min",
		2 * time.Hour:    "2 Std 0 Min",
		50 * time.Hour:   "2 T 2 Std 0 Min",
	}
	for d, want := range tests {
		if got := formatUptime(d); got != want {
			t.Errorf("formatUptime(%v) = %q, erwartet %q", d, got, want)
		}
	}
}

func TestClampPct(t *testing.T) {
	if clampPct(-5) != 0 {
		t.Error("negative Werte müssen auf 0 begrenzt werden")
	}
	if clampPct(120) != 100 {
		t.Error("Werte über 100 müssen begrenzt werden")
	}
	if clampPct(42.5) != 42.5 {
		t.Error("Werte im Bereich dürfen sich nicht ändern")
	}
}

// TestHostName: Die Kopfzeile des Panels soll den Namen zeigen, unter dem der
// Rechner erreichbar ist — sonst steht dort "cloudsrv24", während im Browser
// "cloudsrv24.de" steht, und der Abgleich, ob man auf der richtigen Maschine
// ist, wird zum Ratespiel.
func TestHostName(t *testing.T) {
	faelle := []struct {
		host Host
		want string
	}{
		{Host{Hostname: "cloudsrv24", FQDN: "cloudsrv24.de"}, "cloudsrv24.de"},
		// Ohne auflösenden Namen bleibt nur der kurze — besser als nichts.
		{Host{Hostname: "cloudsrv24"}, "cloudsrv24"},
		{Host{}, ""},
	}
	for _, f := range faelle {
		if got := f.host.Name(); got != f.want {
			t.Errorf("Host%+v.Name() = %q, erwartet %q", f.host, got, f.want)
		}
	}
}
