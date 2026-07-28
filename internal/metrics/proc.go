package metrics

import (
	"bufio"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/philf90/asylum/internal/netinfo"
)

type cpuTimes struct {
	total  uint64
	idle   uint64
	iowait uint64
	steal  uint64
}

type netCounters struct {
	rx uint64
	tx uint64
}

// Sample erhebt eine Momentaufnahme. Der erste Aufruf liefert für alle
// Delta-Werte (CPU, Netzdurchsatz, Prozess-CPU) 0 — es gibt noch keinen
// Vorwert, gegen den gerechnet werden könnte.
func (s *Sampler) Sample() Snapshot {
	s.mu.Lock()
	defer s.mu.Unlock()

	now := time.Now()
	elapsed := now.Sub(s.prevAt).Seconds()
	if s.prevAt.IsZero() {
		elapsed = 0
	}

	snap := Snapshot{At: now}
	snap.CPU = s.sampleCPU()
	snap.Memory = s.sampleMemory()
	snap.Load = readLoad()
	snap.Uptime = readUptime()
	snap.UptimeText = formatUptime(snap.Uptime)
	snap.Filesystems = readFilesystems()
	snap.Interfaces = s.sampleInterfaces(elapsed)
	snap.TopProcesses = s.sampleProcesses(elapsed, snap.Memory.Total)

	s.prevAt = now
	return snap
}

func (s *Sampler) sampleCPU() CPU {
	current := readCPUTimes()
	out := CPU{PerCore: make([]float64, 0, len(current))}
	if len(current) == 0 {
		return out
	}

	if len(s.prevCPU) == len(current) {
		out.Total, out.IOWait, out.Steal = cpuDelta(s.prevCPU[0], current[0])
		for i := 1; i < len(current); i++ {
			pct, _, _ := cpuDelta(s.prevCPU[i], current[i])
			out.PerCore = append(out.PerCore, pct)
		}
	} else {
		for i := 1; i < len(current); i++ {
			out.PerCore = append(out.PerCore, 0)
		}
	}
	s.prevCPU = current
	return out
}

func cpuDelta(prev, cur cpuTimes) (busyPct, iowaitPct, stealPct float64) {
	totalDiff := float64(cur.total - prev.total)
	if totalDiff <= 0 {
		return 0, 0, 0
	}
	idleDiff := float64(cur.idle - prev.idle)
	busy := (1 - idleDiff/totalDiff) * 100
	return clampPct(busy),
		clampPct(float64(cur.iowait-prev.iowait) / totalDiff * 100),
		clampPct(float64(cur.steal-prev.steal) / totalDiff * 100)
}

func readCPUTimes() []cpuTimes {
	f, err := os.Open("/proc/stat")
	if err != nil {
		return nil
	}
	defer func() { _ = f.Close() }()

	var out []cpuTimes
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 5 || !strings.HasPrefix(fields[0], "cpu") {
			if len(out) > 0 {
				break // die cpu-Zeilen stehen am Anfang und am Stück
			}
			continue
		}
		var t cpuTimes
		for i, raw := range fields[1:] {
			v, err := strconv.ParseUint(raw, 10, 64)
			if err != nil {
				continue
			}
			t.total += v
			switch i {
			case 3: // idle
				t.idle += v
			case 4: // iowait zählt als untätig
				t.idle += v
				t.iowait = v
			case 7: // steal
				t.steal = v
			}
		}
		out = append(out, t)
	}
	return out
}

func (s *Sampler) sampleMemory() Memory {
	vals := readMeminfo()
	m := Memory{
		Total:     vals["MemTotal"] * 1024,
		Available: vals["MemAvailable"] * 1024,
		SwapTotal: vals["SwapTotal"] * 1024,
	}
	if m.Total > 0 {
		m.Used = m.Total - m.Available
		m.UsedPct = clampPct(float64(m.Used) / float64(m.Total) * 100)
	}
	if m.SwapTotal > 0 {
		m.SwapUsed = m.SwapTotal - vals["SwapFree"]*1024
	}
	s.memTotalKiB = vals["MemTotal"]
	return m
}

func readMeminfo() map[string]uint64 {
	out := make(map[string]uint64, 8)
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return out
	}
	defer func() { _ = f.Close() }()

	sc := bufio.NewScanner(f)
	for sc.Scan() {
		key, rest, found := strings.Cut(sc.Text(), ":")
		if !found {
			continue
		}
		fields := strings.Fields(rest)
		if len(fields) == 0 {
			continue
		}
		if v, err := strconv.ParseUint(fields[0], 10, 64); err == nil {
			out[key] = v
		}
	}
	return out
}

func readLoad() [3]float64 {
	var out [3]float64
	raw, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return out
	}
	fields := strings.Fields(string(raw))
	for i := 0; i < 3 && i < len(fields); i++ {
		out[i], _ = strconv.ParseFloat(fields[i], 64)
	}
	return out
}

func readUptime() time.Duration {
	raw, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0
	}
	fields := strings.Fields(string(raw))
	if len(fields) == 0 {
		return 0
	}
	secs, err := strconv.ParseFloat(fields[0], 64)
	if err != nil {
		return 0
	}
	return time.Duration(secs) * time.Second
}

// virtuelle Dateisysteme, die in einer Belegungsanzeige nur stören.
var skipFSTypes = map[string]bool{
	"proc": true, "sysfs": true, "devtmpfs": true, "devpts": true,
	"cgroup": true, "cgroup2": true, "securityfs": true, "pstore": true,
	"debugfs": true, "tracefs": true, "mqueue": true, "hugetlbfs": true,
	"fusectl": true, "configfs": true, "bpf": true, "binfmt_misc": true,
	"autofs": true, "squashfs": true, "ramfs": true, "efivarfs": true,
	"nsfs": true, "overlay": true,
}

func readFilesystems() []Filesystem {
	f, err := os.Open("/proc/mounts")
	if err != nil {
		return nil
	}
	defer func() { _ = f.Close() }()

	seen := make(map[string]bool)
	sammler := &fsSammler{nachID: make(map[string]int)}

	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 3 {
			continue
		}
		device, mount, fstype := fields[0], unescapeMount(fields[1]), fields[2]
		if skipFSTypes[fstype] || seen[mount] {
			continue
		}
		// tmpfs nur dort zeigen, wo es echte Nutzdaten hält.
		if fstype == "tmpfs" && !strings.HasPrefix(mount, "/run/user") && mount != "/dev/shm" && mount != "/tmp" {
			continue
		}
		seen[mount] = true

		var st syscall.Statfs_t
		if err := syscall.Statfs(mount, &st); err != nil {
			continue
		}
		if st.Blocks == 0 {
			continue
		}

		id := fmt.Sprintf("%s|%d|%d", device, st.Fsid.X__val[0], st.Fsid.X__val[1])
		if sammler.weitererOrt(id, mount) {
			continue
		}

		bsize := uint64(st.Bsize) //nolint:gosec // Bsize ist auf Linux positiv
		total := st.Blocks * bsize
		free := st.Bavail * bsize
		used := total - st.Bfree*bsize

		fs := Filesystem{
			Mount: mount, Device: device, Type: fstype,
			Total: total, Used: used,
		}
		if total > 0 {
			fs.UsedPct = clampPct(float64(used) / float64(used+free) * 100)
		}
		if st.Files > 0 {
			fs.InodesUsed = st.Files - st.Ffree
			fs.InodesPct = clampPct(float64(fs.InodesUsed) / float64(st.Files) * 100)
		}
		sammler.neu(id, fs)
	}

	return sammler.fertig()
}

// /proc/mounts kodiert Leerzeichen und Konsorten oktal.
func unescapeMount(s string) string {
	r := strings.NewReplacer(`\040`, " ", `\011`, "\t", `\012`, "\n", `\134`, `\`)
	return r.Replace(s)
}

func (s *Sampler) sampleInterfaces(elapsed float64) []Interface {
	current := readNetDev()
	addrs := interfaceAddrs()

	out := make([]Interface, 0, len(current))
	for name, c := range current {
		iface := Interface{
			Name: name, RXBytes: c.rx, TXBytes: c.tx, Addrs: addrs[name],
			Physical: hatGeraet(name),
		}
		if prev, ok := s.prevNet[name]; ok && elapsed > 0 {
			if c.rx >= prev.rx {
				iface.RXRate = float64(c.rx-prev.rx) / elapsed
			}
			if c.tx >= prev.tx {
				iface.TXRate = float64(c.tx-prev.tx) / elapsed
			}
		}
		out = append(out, iface)
	}
	s.prevNet = current

	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	// Die Reihenfolge bleibt alphabetisch — sie ist die Liste. Welche
	// Schnittstelle die Übersicht zeigt, entscheidet die Markierung.
	if i := hauptschnittstelle(out, standardrouten()); i >= 0 {
		out[i].Primary = true
	}
	return out
}

func readNetDev() map[string]netCounters {
	out := make(map[string]netCounters)
	f, err := os.Open("/proc/net/dev")
	if err != nil {
		return out
	}
	defer func() { _ = f.Close() }()

	sc := bufio.NewScanner(f)
	for sc.Scan() {
		name, rest, found := strings.Cut(sc.Text(), ":")
		if !found {
			continue // Kopfzeilen
		}
		name = strings.TrimSpace(name)
		if name == "lo" {
			continue
		}
		fields := strings.Fields(rest)
		if len(fields) < 9 {
			continue
		}
		rx, _ := strconv.ParseUint(fields[0], 10, 64)
		tx, _ := strconv.ParseUint(fields[8], 10, 64)
		out[name] = netCounters{rx: rx, tx: tx}
	}
	return out
}

func (s *Sampler) sampleProcesses(elapsed float64, memTotal uint64) []Process {
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return nil
	}

	current := make(map[int]uint64, len(entries))
	var procs []Process

	for _, e := range entries {
		pid, err := strconv.Atoi(e.Name())
		if err != nil {
			continue
		}
		p, ticks, ok := readProcess(pid, s.pageSize)
		if !ok {
			continue
		}
		current[pid] = ticks

		if prev, seen := s.prevProc[pid]; seen && elapsed > 0 && ticks >= prev {
			p.CPUPct = clampPct(float64(ticks-prev) / s.clockTicks / elapsed * 100)
		}
		if memTotal > 0 {
			p.RSSPct = clampPct(float64(p.RSS) / float64(memTotal) * 100)
		}
		procs = append(procs, p)
	}
	s.prevProc = current

	// Nach CPU sortieren, bei Gleichstand nach Speicher — sonst springt die
	// Liste im Leerlauf unruhig hin und her.
	sort.Slice(procs, func(i, j int) bool {
		if procs[i].CPUPct != procs[j].CPUPct {
			return procs[i].CPUPct > procs[j].CPUPct
		}
		return procs[i].RSS > procs[j].RSS
	})
	if len(procs) > s.topN {
		procs = procs[:s.topN]
	}
	return procs
}

func readProcess(pid int, pageSize uint64) (Process, uint64, bool) {
	raw, err := os.ReadFile(filepath.Join("/proc", strconv.Itoa(pid), "stat"))
	if err != nil {
		return Process{}, 0, false
	}
	line := string(raw)

	// Der Prozessname steht in Klammern und darf selbst Leerzeichen und
	// Klammern enthalten — deshalb ab der letzten schließenden Klammer parsen.
	open := strings.IndexByte(line, '(')
	close := strings.LastIndexByte(line, ')')
	if open < 0 || close < 0 || close < open {
		return Process{}, 0, false
	}
	name := line[open+1 : close]
	fields := strings.Fields(line[close+1:])
	if len(fields) < 22 {
		return Process{}, 0, false
	}

	// Nach dem Namen: state(0) ppid(1) … utime(11) stime(12) … rss(21)
	utime, _ := strconv.ParseUint(fields[11], 10, 64)
	stime, _ := strconv.ParseUint(fields[12], 10, 64)
	rssPages, _ := strconv.ParseUint(fields[21], 10, 64)

	p := Process{
		PID:     pid,
		Name:    name,
		RSS:     rssPages * pageSize,
		User:    processOwner(pid),
		Command: processCommand(pid, name),
	}
	return p, utime + stime, true
}

func processOwner(pid int) string {
	info, err := os.Stat(filepath.Join("/proc", strconv.Itoa(pid)))
	if err != nil {
		return ""
	}
	st, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return ""
	}
	uid := strconv.FormatUint(uint64(st.Uid), 10)
	if u, err := user.LookupId(uid); err == nil {
		return u.Username
	}
	return uid
}

func processCommand(pid int, fallback string) string {
	raw, err := os.ReadFile(filepath.Join("/proc", strconv.Itoa(pid), "cmdline"))
	if err != nil || len(raw) == 0 {
		return fallback
	}
	cmd := strings.TrimRight(strings.ReplaceAll(string(raw), "\x00", " "), " ")
	if len(cmd) > 120 {
		cmd = cmd[:120] + "…"
	}
	return cmd
}

func interfaceAddrs() map[string][]string {
	out := make(map[string][]string)
	ifaces, err := netInterfaces()
	if err != nil {
		return out
	}
	for name, addrs := range ifaces {
		out[name] = addrs
	}
	return out
}

func readHost() Host {
	h := Host{Cores: runtime.NumCPU(), Arch: runtime.GOARCH}
	h.Hostname, _ = os.Hostname()
	// Einmal beim Start, nicht je Messung: netinfo.FQDN() fragt im Zweifel
	// den Resolver, und der Sampler läuft alle 30 Sekunden.
	h.FQDN = netinfo.FQDN()

	if raw, err := os.ReadFile("/proc/sys/kernel/osrelease"); err == nil {
		h.Kernel = strings.TrimSpace(string(raw))
	}
	if f, err := os.Open("/etc/os-release"); err == nil {
		defer func() { _ = f.Close() }()
		sc := bufio.NewScanner(f)
		for sc.Scan() {
			if rest, ok := strings.CutPrefix(sc.Text(), "PRETTY_NAME="); ok {
				h.Distro = strings.Trim(rest, `"`)
				break
			}
		}
	}
	if h.Distro == "" {
		h.Distro = "unbekannt"
	}
	return h
}

func formatUptime(d time.Duration) string {
	if d <= 0 {
		return "unbekannt"
	}
	days := int(d.Hours()) / 24
	hours := int(d.Hours()) % 24
	minutes := int(d.Minutes()) % 60
	switch {
	case days > 0:
		return fmt.Sprintf("%d T %d Std %d Min", days, hours, minutes)
	case hours > 0:
		return fmt.Sprintf("%d Std %d Min", hours, minutes)
	default:
		return fmt.Sprintf("%d Min", minutes)
	}
}

func clampPct(v float64) float64 {
	switch {
	case v < 0:
		return 0
	case v > 100:
		return 100
	default:
		return v
	}
}

// fsSammler fasst mehrere Einhängepunkte desselben Dateisystems zu einem
// Eintrag zusammen.
//
// Die systemd-Härtung der eigenen Unit hängt Teile von / erneut ein: /etc,
// /home, /root, /tmp, /usr und /var/… erscheinen in /proc/mounts als eigene
// Zeilen, alle mit den Zahlen von /. In der Übersicht standen dadurch sieben
// Einträge für eine Platte — auf einem Telefon knapp fünfzig Zeilen, die
// dasselbe sagen.
//
// Verschwiegen wird nichts: Die weiteren Stellen stehen in AlsoAt.
type fsSammler struct {
	nachID map[string]int
	out    []Filesystem
}

// weitererOrt meldet, ob dieses Dateisystem schon bekannt ist, und merkt sich
// den zusätzlichen Einhängepunkt. Der kürzere Pfad wird zum Hauptnamen — "/"
// sagt mehr über die Platte aus als "/var/lib/asylum".
func (c *fsSammler) weitererOrt(id, mount string) bool {
	i, ok := c.nachID[id]
	if !ok {
		return false
	}
	if len(mount) < len(c.out[i].Mount) {
		c.out[i].AlsoAt = append(c.out[i].AlsoAt, c.out[i].Mount)
		c.out[i].Mount = mount
	} else {
		c.out[i].AlsoAt = append(c.out[i].AlsoAt, mount)
	}
	return true
}

func (c *fsSammler) neu(id string, fs Filesystem) {
	c.nachID[id] = len(c.out)
	c.out = append(c.out, fs)
}

func (c *fsSammler) fertig() []Filesystem {
	for i := range c.out {
		sort.Strings(c.out[i].AlsoAt)
	}
	sort.Slice(c.out, func(i, j int) bool { return c.out[i].Mount < c.out[j].Mount })
	return c.out
}
