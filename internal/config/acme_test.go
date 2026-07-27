package config

import "testing"

func baseACMEConfig() Config {
	c := Default()
	c.Server.TLS.Mode = TLSModeACME
	c.ACME.Email = "admin@example.com"
	return c
}

func TestACMEValidation(t *testing.T) {
	t.Run("Vorgabe selfsigned ist gültig", func(t *testing.T) {
		if err := Default().Validate(); err != nil {
			t.Fatalf("die Vorgabe sollte gültig sein: %v", err)
		}
	})

	t.Run("leerer Modus gilt als selfsigned", func(t *testing.T) {
		c := Default()
		c.Server.TLS.Mode = ""
		if err := c.Validate(); err != nil {
			t.Errorf("leerer Modus sollte als selfsigned durchgehen: %v", err)
		}
	})

	t.Run("unbekannter Modus", func(t *testing.T) {
		c := Default()
		c.Server.TLS.Mode = "vault"
		if err := c.Validate(); err == nil {
			t.Error("ein unbekannter TLS-Modus wurde angenommen")
		}
	})

	t.Run("acme braucht eine E-Mail", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.Email = ""
		if err := c.Validate(); err == nil {
			t.Error("eine leere E-Mail wurde angenommen")
		}
	})

	t.Run("acme mit automatischer Wahl ohne Anbieter ist gültig", func(t *testing.T) {
		if err := baseACMEConfig().Validate(); err != nil {
			t.Errorf("die automatische Wahl ohne DNS-Anbieter sollte gültig sein: %v", err)
		}
	})

	t.Run("dns-01 ohne Anbieter ist ein Widerspruch", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.Challenge = "dns-01"
		if err := c.Validate(); err == nil {
			t.Error("dns-01 ohne Anbieter wurde angenommen")
		}
	})

	t.Run("gültige Hook-Konfiguration", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.Challenge = "dns-01"
		c.ACME.DNS01.Provider = DNS01ProviderHook
		c.ACME.DNS01.Hook.Set = "/etc/asylum/acme-hook"
		c.ACME.DNS01.Hook.Clean = "/etc/asylum/acme-hook"
		if err := c.Validate(); err != nil {
			t.Errorf("eine gültige Hook-Konfiguration wurde abgelehnt: %v", err)
		}
	})

	t.Run("Hook ohne clean", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.DNS01.Provider = DNS01ProviderHook
		c.ACME.DNS01.Hook.Set = "/etc/asylum/acme-hook"
		if err := c.Validate(); err == nil {
			t.Error("ein Hook ohne clean wurde angenommen")
		}
	})

	t.Run("Cloudflare braucht die Token-Datei", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.DNS01.Provider = DNS01ProviderCloudflare
		if err := c.Validate(); err == nil {
			t.Error("Cloudflare ohne api_token_file wurde angenommen")
		}
	})

	t.Run("unbekannter Anbieter", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.DNS01.Provider = "route53"
		if err := c.Validate(); err == nil {
			t.Error("ein unbekannter DNS-Anbieter wurde angenommen")
		}
	})

	t.Run("unbekannte Challenge", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.Challenge = "tls-alpn-01"
		if err := c.Validate(); err == nil {
			t.Error("eine unbekannte Challenge wurde angenommen")
		}
	})

	t.Run("directory_url muss https sein", func(t *testing.T) {
		c := baseACMEConfig()
		c.ACME.DirectoryURL = "http://acme.example/dir"
		if err := c.Validate(); err == nil {
			t.Error("ein http-Directory wurde angenommen")
		}
	})
}
