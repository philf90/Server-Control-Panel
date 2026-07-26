// Kommando asylumd ist der Daemon und zugleich das CLI von Project Asylum.
//
// Ein Binary, mehrere Unterkommandos:
//
//	asylumd serve           Panel starten (das macht die systemd-Unit)
//	asylumd migrate         Datenbankmigrationen einspielen
//	asylumd setup-token     einmaligen Token für die Ersteinrichtung ausgeben
//	asylumd reset-password  Passwort eines Kontos lokal zurücksetzen
//	asylumd update          auf die neueste Fassung des Kanals aktualisieren
//	asylumd rollback        zur zuvor installierten Fassung zurückkehren
//	asylumd version         Versionsangaben ausgeben
package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/httpd"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/version"
	"golang.org/x/term"
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		if errors.Is(err, flag.ErrHelp) {
			os.Exit(2)
		}
		fmt.Fprintf(os.Stderr, "asylumd: %v\n", err)
		os.Exit(1)
	}
}

func run(args []string) error {
	if len(args) == 0 {
		usage()
		return errors.New("kein Unterkommando angegeben")
	}

	cmd, rest := args[0], args[1:]
	switch cmd {
	case "serve":
		return cmdServe(rest)
	case "migrate":
		return cmdMigrate(rest)
	case "setup-token":
		return cmdSetupToken(rest)
	case "reset-password":
		return cmdResetPassword(rest)
	case "update":
		return cmdUpdate(rest)
	case "rollback":
		return cmdRollback(rest)
	case "version", "--version", "-v":
		return cmdVersion(rest)
	case "help", "--help", "-h":
		usage()
		return nil
	default:
		usage()
		return fmt.Errorf("unbekanntes Unterkommando %q", cmd)
	}
}

func usage() {
	fmt.Fprint(os.Stderr, `Project Asylum — Control Panel für Linux-Server

Aufruf:
  asylum serve [--config PFAD]         Panel starten
  asylum migrate [--config PFAD]       Datenbankmigrationen einspielen
  asylum setup-token [--config PFAD]   Token für die Ersteinrichtung ausgeben
  asylum reset-password BENUTZER       Passwort zurücksetzen (Rettungsweg)
  asylum update [--check]              auf die neueste Fassung aktualisieren
  asylum rollback                      zur vorherigen Fassung zurückkehren
  asylum version [--fingerprint]       Versionsangaben ausgeben
  asylum help                          diese Hilfe

Umgebungsvariablen überschreiben die Konfigurationsdatei:
  ASYLUM_CONFIG, ASYLUM_BIND, ASYLUM_PORT, ASYLUM_LOG_LEVEL,
  ASYLUM_TLS_CERT, ASYLUM_TLS_KEY, ASYLUM_DATA_DIR,
  ASYLUM_UPDATE_CHANNEL, ASYLUM_UPDATE_BASE_URL
`)
}

func cmdServe(args []string) error {
	fs := flag.NewFlagSet("serve", flag.ContinueOnError)
	cfgPath := fs.String("config", defaultConfigPath(), "Pfad zur Konfigurationsdatei")
	if err := fs.Parse(args); err != nil {
		return err
	}

	cfg, err := config.Load(*cfgPath)
	if err != nil {
		return err
	}
	logger := newLogger(cfg)
	logger.Info("Project Asylum startet",
		"version", version.String(),
		"config", *cfgPath,
		"addr", cfg.Addr(),
	)

	db, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer func() { _ = db.Close() }()

	// Migrationen laufen auch beim regulären Start: Nach einem Paket-Update
	// über apt gibt es keinen separaten Migrationsschritt.
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	applied, err := db.Migrate(ctx)
	cancel()
	if err != nil {
		return err
	}
	if applied > 0 {
		logger.Info("Migrationen eingespielt", "anzahl", applied)
	}

	srv, err := httpd.New(cfg, logger, db, privops.NewSystem())
	if err != nil {
		return err
	}

	// SIGTERM kommt von systemd beim Stop und beim Update-Restart, SIGINT vom
	// Terminal. Beide führen zum geordneten Shutdown.
	runCtx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	if err := srv.Run(runCtx); err != nil {
		return err
	}
	logger.Info("beendet")
	return nil
}

func cmdMigrate(args []string) error {
	fs := flag.NewFlagSet("migrate", flag.ContinueOnError)
	cfgPath := fs.String("config", defaultConfigPath(), "Pfad zur Konfigurationsdatei")
	if err := fs.Parse(args); err != nil {
		return err
	}
	cfg, err := config.Load(*cfgPath)
	if err != nil {
		return err
	}

	db, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer func() { _ = db.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	applied, err := db.Migrate(ctx)
	if err != nil {
		return err
	}
	current, err := db.SchemaVersion(ctx)
	if err != nil {
		return err
	}

	if applied == 0 {
		fmt.Printf("keine Migrationen offen (Schemaversion %d)\n", current)
		return nil
	}
	fmt.Printf("%d Migration(en) eingespielt, Schemaversion %d\n", applied, current)
	return nil
}

// cmdSetupToken erzeugt den einmaligen Token für die Ersteinrichtung.
//
// Der Installer ruft das nach dem Start auf. Ausgegeben wird der Klartext
// genau hier und nur einmal; in der Datenbank landet ausschließlich der Hash.
func cmdSetupToken(args []string) error {
	fs := flag.NewFlagSet("setup-token", flag.ContinueOnError)
	cfgPath := fs.String("config", defaultConfigPath(), "Pfad zur Konfigurationsdatei")
	urlOnly := fs.Bool("url-only", false, "nur die Setup-URL ausgeben")
	if err := fs.Parse(args); err != nil {
		return err
	}
	cfg, err := config.Load(*cfgPath)
	if err != nil {
		return err
	}

	db, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer func() { _ = db.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	if _, err := db.Migrate(ctx); err != nil {
		return err
	}
	n, err := db.CountUsers(ctx)
	if err != nil {
		return err
	}
	if n > 0 {
		return errors.New("die Ersteinrichtung ist bereits abgeschlossen — bei verlorenem Zugang: asylum reset-password BENUTZER")
	}

	token, err := auth.NewToken()
	if err != nil {
		return err
	}
	expires := time.Now().Add(60 * time.Minute)
	if err := db.SetSetting(ctx, store.SettingSetupTokenHash, auth.HashToken(token)); err != nil {
		return err
	}
	if err := db.SetSetting(ctx, store.SettingSetupTokenExpires, expires.Format(time.RFC3339)); err != nil {
		return err
	}

	host := hostForURL(cfg)
	url := fmt.Sprintf("https://%s:%d/setup?token=%s", host, cfg.Server.Port, token)

	if *urlOnly {
		fmt.Println(url)
		return nil
	}

	fmt.Printf(`
  Ersteinrichtung — dieser Link gilt %d Minuten und nur einmal:

  %s

`, int(time.Until(expires).Minutes()), url)

	if fp, err := certs.Fingerprint(cfg.Server.TLS.Cert); err == nil {
		fmt.Printf("  Der Browser wird vor dem selbstsignierten Zertifikat warnen.\n"+
			"  Erwarteter SHA-256-Fingerprint:\n  %s\n\n", fp)
	}
	return nil
}

// cmdResetPassword ist der lokale Rettungsanker: Wer sich aus dem Panel
// ausgesperrt hat, kommt über SSH wieder hinein. Der Aufruf setzt außerdem den
// zweiten Faktor zurück, weil ein verlorenes Telefon der häufigste Grund ist.
func cmdResetPassword(args []string) error {
	fs := flag.NewFlagSet("reset-password", flag.ContinueOnError)
	cfgPath := fs.String("config", defaultConfigPath(), "Pfad zur Konfigurationsdatei")
	keepTOTP := fs.Bool("keep-2fa", false, "den zweiten Faktor unangetastet lassen")
	if err := fs.Parse(args); err != nil {
		return err
	}
	if fs.NArg() != 1 {
		return errors.New("Aufruf: asylum reset-password BENUTZER")
	}
	username := fs.Arg(0)

	cfg, err := config.Load(*cfgPath)
	if err != nil {
		return err
	}
	db, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer func() { _ = db.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	user, err := db.UserByName(ctx, username)
	if err != nil {
		if errors.Is(err, store.ErrNotFound) {
			return fmt.Errorf("kein Konto mit dem Namen %q", username)
		}
		return err
	}

	password, err := readPassword("Neues Passwort: ")
	if err != nil {
		return err
	}
	confirm, err := readPassword("Wiederholen:    ")
	if err != nil {
		return err
	}
	if password != confirm {
		return errors.New("die beiden Passwörter stimmen nicht überein")
	}
	if err := auth.CheckPasswordPolicy(password); err != nil {
		return err
	}

	hash, err := auth.HashPassword(password)
	if err != nil {
		return err
	}
	if err := db.SetPassword(ctx, user.ID, hash); err != nil {
		return err
	}
	// Sitzungen beenden: Wer immer noch angemeldet war, ist es jetzt nicht mehr.
	if err := db.DeleteUserSessions(ctx, user.ID); err != nil {
		return err
	}
	if err := db.SetDisabled(ctx, user.ID, false); err != nil {
		return err
	}

	detail := "Passwort zurückgesetzt"
	if !*keepTOTP {
		secret, err := auth.GenerateTOTPSecret()
		if err != nil {
			return err
		}
		if err := db.SetTOTP(ctx, user.ID, secret, false); err != nil {
			return err
		}
		if err := db.ReplaceRecoveryCodes(ctx, user.ID, nil); err != nil {
			return err
		}
		detail += ", zweiter Faktor zurückgesetzt"
	}

	if err := db.AppendAudit(ctx, store.AuditEntry{
		Actor: "cli(root)", Action: "password.reset", Target: username,
		Result: store.ResultOK, IP: "lokal", Detail: detail,
	}); err != nil {
		return err
	}

	fmt.Printf("\nKonto %q: %s.\n", username, detail)
	if !*keepTOTP {
		fmt.Println("Beim nächsten Anmelden wird der zweite Faktor neu eingerichtet.")
	}
	return nil
}

func cmdVersion(args []string) error {
	fs := flag.NewFlagSet("version", flag.ContinueOnError)
	fingerprint := fs.Bool("fingerprint", false, "zusätzlich den TLS-Fingerprint ausgeben")
	cfgPath := fs.String("config", defaultConfigPath(), "Pfad zur Konfigurationsdatei")
	if err := fs.Parse(args); err != nil {
		return err
	}

	fmt.Println(version.Full())

	if *fingerprint {
		cfg, err := config.Load(*cfgPath)
		if err != nil {
			return err
		}
		fp, err := certs.Fingerprint(cfg.Server.TLS.Cert)
		if err != nil {
			return fmt.Errorf("fingerprint: %w", err)
		}
		fmt.Printf("\nTLS-Zertifikat: %s\nSHA-256:        %s\n", cfg.Server.TLS.Cert, fp)
	}
	return nil
}

func openDB(cfg config.Config) (*store.DB, error) {
	return store.Open(filepath.Join(cfg.Paths.Data, "asylum.db"))
}

// hostForURL wählt die Adresse für die ausgegebene Setup-URL. Bei einer
// Bindung auf alle Schnittstellen ist der Hostname die brauchbarere Angabe als
// 0.0.0.0.
func hostForURL(cfg config.Config) string {
	bind := cfg.Server.Bind
	if bind == "" || bind == "0.0.0.0" || bind == "::" {
		if h, err := os.Hostname(); err == nil && h != "" {
			return h
		}
		return "localhost"
	}
	return bind
}

// readPassword liest ohne Echo, fällt aber auf zeilenweises Lesen zurück, wenn
// die Eingabe kein Terminal ist (etwa in einem Skript).
func readPassword(prompt string) (string, error) {
	fmt.Print(prompt)
	fd := int(os.Stdin.Fd())
	if term.IsTerminal(fd) {
		raw, err := term.ReadPassword(fd)
		fmt.Println()
		if err != nil {
			return "", err
		}
		return string(raw), nil
	}

	line, err := bufio.NewReader(os.Stdin).ReadString('\n')
	if err != nil && line == "" {
		return "", err
	}
	return strings.TrimRight(line, "\r\n"), nil
}

func defaultConfigPath() string {
	if v := os.Getenv("ASYLUM_CONFIG"); v != "" {
		return v
	}
	return config.DefaultPath
}

func newLogger(cfg config.Config) *slog.Logger {
	var level slog.Level
	switch cfg.Log.Level {
	case "debug":
		level = slog.LevelDebug
	case "warn":
		level = slog.LevelWarn
	case "error":
		level = slog.LevelError
	default:
		level = slog.LevelInfo
	}

	opts := &slog.HandlerOptions{Level: level}
	// Ausgabe nach stderr; journald übernimmt Zeitstempel und Rotation.
	var handler slog.Handler
	if cfg.Log.Format == "json" {
		handler = slog.NewJSONHandler(os.Stderr, opts)
	} else {
		handler = slog.NewTextHandler(os.Stderr, opts)
	}
	return slog.New(handler)
}
