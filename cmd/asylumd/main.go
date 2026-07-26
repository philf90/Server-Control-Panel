// Kommando asylumd ist der Daemon und zugleich das CLI von Project Asylum.
//
// Ein Binary, mehrere Unterkommandos:
//
//	asylumd serve      Panel starten (das macht die systemd-Unit)
//	asylumd migrate    Datenbankmigrationen einspielen
//	asylumd version    Versionsangaben ausgeben
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/httpd"
	"github.com/philf90/asylum/internal/version"
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
  asylumd serve [--config PFAD]     Panel starten
  asylumd migrate [--config PFAD]   Datenbankmigrationen einspielen
  asylumd version [--fingerprint]   Versionsangaben ausgeben
  asylumd help                      diese Hilfe

Umgebungsvariablen überschreiben die Konfigurationsdatei:
  ASYLUM_CONFIG, ASYLUM_BIND, ASYLUM_PORT, ASYLUM_LOG_LEVEL,
  ASYLUM_TLS_CERT, ASYLUM_TLS_KEY, ASYLUM_DATA_DIR
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

	srv, err := httpd.New(cfg, logger)
	if err != nil {
		return err
	}

	// SIGTERM kommt von systemd beim Stop und beim Update-Restart, SIGINT vom
	// Terminal. Beide führen zum geordneten Shutdown.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	if err := srv.Run(ctx); err != nil {
		return err
	}
	logger.Info("beendet")
	return nil
}

// cmdMigrate ist in M0 bewusst ein No-op: Es gibt noch keine Datenbank. Das
// Unterkommando existiert trotzdem, weil Installer und Update-Ablauf es
// aufrufen — der Vertrag steht damit fest, bevor M1 Inhalte liefert.
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
	if err := os.MkdirAll(cfg.Paths.Data, 0o750); err != nil {
		return fmt.Errorf("datenverzeichnis %s: %w", cfg.Paths.Data, err)
	}
	fmt.Printf("keine Migrationen offen (Schemaversion 0, Datenverzeichnis %s)\n", cfg.Paths.Data)
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
