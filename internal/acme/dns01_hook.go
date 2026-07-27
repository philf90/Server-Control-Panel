package acme

import (
	"context"
	"fmt"
	"os"
	"os/exec"
)

// hookSetter ruft ein vom Betreiber gestelltes Programm, das den TXT-Record
// setzt und wieder entfernt. So bleibt kein DNS-Anbieter im Binary — der Hook
// funktioniert mit jedem Anbieter, für den es ein Skript gibt.
//
// Aufruf: <programm> <action> <record> <value>, wobei action "set" oder "clean"
// ist. Dieselben Angaben stehen zusätzlich in der Umgebung (ASYLUM_ACME_*),
// damit ein gemeinsames Skript set und clean unterscheiden kann.
type hookSetter struct {
	set   string
	clean string
}

func (h *hookSetter) setTXT(ctx context.Context, domain, record, value string) error {
	return runHook(ctx, h.set, "set", domain, record, value)
}

func (h *hookSetter) removeTXT(ctx context.Context, domain, record, value string) error {
	return runHook(ctx, h.clean, "clean", domain, record, value)
}

func runHook(ctx context.Context, path, action, domain, record, value string) error {
	// G204: Der Pfad stammt aus der Konfiguration des Betreibers (acme.dns01.hook),
	// nicht aus einer Anfrage. Ein Hook, der ein Programm ruft, ist der Sinn der
	// Sache — genau dafür ist das Feld da.
	cmd := exec.CommandContext(ctx, path, action, record, value) //nolint:gosec // G204: Pfad aus vertrauenswürdiger Konfiguration
	cmd.Env = append(os.Environ(),
		"ASYLUM_ACME_ACTION="+action,
		"ASYLUM_ACME_DOMAIN="+domain,
		"ASYLUM_ACME_RECORD="+record,
		"ASYLUM_ACME_VALUE="+value,
	)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("hook %q (%s) fehlgeschlagen: %w: %s", path, action, err, string(out))
	}
	return nil
}
