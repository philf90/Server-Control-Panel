package systemd

import (
	"net"
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"
)

func TestNotifyWithoutSocketIsNoop(t *testing.T) {
	t.Setenv("NOTIFY_SOCKET", "")
	if err := Ready(); err != nil {
		t.Fatalf("außerhalb von systemd darf Notify nicht fehlschlagen: %v", err)
	}
}

func TestNotifySendsState(t *testing.T) {
	// Kurzer Pfad: Unix-Socket-Adressen sind auf ~100 Zeichen begrenzt, und
	// t.TempDir() wird schnell länger.
	dir, err := os.MkdirTemp("", "sd")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = os.RemoveAll(dir) }()

	socket := filepath.Join(dir, "notify")
	conn, err := net.ListenUnixgram("unixgram", &net.UnixAddr{Name: socket, Net: "unixgram"})
	if err != nil {
		t.Skipf("unixgram nicht verfügbar: %v", err)
	}
	defer func() { _ = conn.Close() }()

	t.Setenv("NOTIFY_SOCKET", socket)
	if err := Ready(); err != nil {
		t.Fatalf("Ready: %v", err)
	}

	if err := conn.SetReadDeadline(time.Now().Add(2 * time.Second)); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 64)
	n, _, err := conn.ReadFromUnix(buf)
	if err != nil {
		t.Fatalf("nichts empfangen: %v", err)
	}
	if got := string(buf[:n]); got != "READY=1" {
		t.Errorf("empfangen %q, erwartet READY=1", got)
	}
}

func TestWatchdogInterval(t *testing.T) {
	t.Run("ohne Variable", func(t *testing.T) {
		t.Setenv("WATCHDOG_USEC", "")
		if got := WatchdogInterval(); got != 0 {
			t.Errorf("= %v, erwartet 0", got)
		}
	})

	t.Run("halbiert den Wert", func(t *testing.T) {
		t.Setenv("WATCHDOG_USEC", "30000000") // 30 s
		t.Setenv("WATCHDOG_PID", strconv.Itoa(os.Getpid()))
		if got, want := WatchdogInterval(), 15*time.Second; got != want {
			t.Errorf("= %v, erwartet %v", got, want)
		}
	})

	t.Run("fremde PID", func(t *testing.T) {
		t.Setenv("WATCHDOG_USEC", "30000000")
		t.Setenv("WATCHDOG_PID", strconv.Itoa(os.Getpid()+1))
		if got := WatchdogInterval(); got != 0 {
			t.Errorf("= %v, erwartet 0 (Variable galt einem anderen Prozess)", got)
		}
	})

	t.Run("unbrauchbarer Wert", func(t *testing.T) {
		t.Setenv("WATCHDOG_USEC", "bald")
		if got := WatchdogInterval(); got != 0 {
			t.Errorf("= %v, erwartet 0", got)
		}
	})
}
