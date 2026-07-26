// Package systemd implementiert das sd_notify-Protokoll ohne cgo.
//
// Die Unit läuft als Type=notify: systemd betrachtet den Dienst erst dann als
// gestartet, wenn READY=1 gemeldet wurde. Das ist die Voraussetzung dafür, dass
// der Installer und spätere Updates verlässlich auf "läuft" warten können,
// statt zu pollen.
package systemd

import (
	"fmt"
	"net"
	"os"
	"strconv"
	"time"
)

// Notify sendet einen Statuszustand an systemd. Ohne NOTIFY_SOCKET (also
// außerhalb von systemd) ist der Aufruf ein No-op.
func Notify(state string) error {
	socket := os.Getenv("NOTIFY_SOCKET")
	if socket == "" {
		return nil
	}
	// Abstrakte Sockets beginnen mit '@'; Go erwartet dort ein NUL-Byte.
	if socket[0] == '@' {
		socket = "\x00" + socket[1:]
	}

	conn, err := net.DialUnix("unixgram", nil, &net.UnixAddr{Name: socket, Net: "unixgram"})
	if err != nil {
		return fmt.Errorf("notify-socket: %w", err)
	}
	defer func() { _ = conn.Close() }()

	if _, err := conn.Write([]byte(state)); err != nil {
		return fmt.Errorf("notify %q: %w", state, err)
	}
	return nil
}

// Ready meldet den erfolgreichen Start.
func Ready() error { return Notify("READY=1") }

// Stopping meldet den beginnenden Shutdown, damit systemd nicht auf einen
// Absturz schließt.
func Stopping() error { return Notify("STOPPING=1") }

// Status setzt die einzeilige Statusanzeige in `systemctl status`.
func Status(text string) error { return Notify("STATUS=" + text) }

// WatchdogPing bestätigt dem Watchdog, dass der Prozess arbeitet.
func WatchdogPing() error { return Notify("WATCHDOG=1") }

// WatchdogInterval liefert das Intervall, in dem gepingt werden muss, oder 0,
// wenn kein Watchdog konfiguriert ist. systemd erwartet den Ping deutlich vor
// Ablauf von WatchdogSec, deshalb die Halbierung.
func WatchdogInterval() time.Duration {
	raw := os.Getenv("WATCHDOG_USEC")
	if raw == "" {
		return 0
	}
	usec, err := strconv.ParseInt(raw, 10, 64)
	if err != nil || usec <= 0 {
		return 0
	}
	// WATCHDOG_PID schützt davor, dass ein Kindprozess das Intervall erbt.
	if pid := os.Getenv("WATCHDOG_PID"); pid != "" {
		if want, err := strconv.Atoi(pid); err == nil && want != os.Getpid() {
			return 0
		}
	}
	return time.Duration(usec) * time.Microsecond / 2
}
