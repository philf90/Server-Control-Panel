package httpd

// Firewall über /api/v1.
//
// Das Modul mit Grundsatz VI aus docs/16-neukonzeption.md: „Was schiefgehen
// kann, hat einen Rückweg." Es ist das einzige, bei dem ein Fehler den Zugang
// zum Panel kostet — und zwar aus der Seite heraus, auf der man ihn zurücknehmen
// könnte. Deshalb gilt jede Änderung zunächst auf Probe: Ohne Bestätigung binnen
// 60 Sekunden stellt der Server den vorherigen Stand wieder her.
//
// Drei Dinge folgen daraus für die Schnittstelle:
//
//  1. **Die Probe ist Teil des Zustands.** GET liefert, ob eine aussteht, was auf
//     Probe steht und wie viele Sekunden übrig sind. Nicht als Ereignis, sondern
//     als Feld: Wer die Seite neu lädt, während die Frist läuft, muss den
//     Countdown vorfinden — sonst bestätigt er nicht und die Änderung fällt weg,
//     ohne dass er weiß, warum.
//  2. **Die Frist ist die des Servers.** Der Browser zählt nur herunter, damit man
//     sie sieht. Verbindlich ist der Wächter (probenWaechter, probe.go), und er läuft weiter,
//     auch wenn niemand mehr zusieht. Eine Uhr, die im Browser stimmt, aber auf
//     dem Server anders läuft, wäre die schlechteste Fassung von beidem.
//  3. **Der Panel-Port wird nicht der Anfrage überlassen.** In der Oberfläche
//     steht die Regel unveränderlich da, aber ein gesperrtes Feld ist eine Bitte,
//     keine Sperre: Wer die Anfrage selbst baut, lässt sie weg. Sie wird hier
//     ergänzt — dieselbe Funktion, die auch die alte Oberfläche benutzt.
//
// Die Stufen der Rückfragen sind dieselben wie in der alten Fassung und aus
// demselben Grund gewählt (docs/14-bestaetigungen.md):
//
//   - **Regeln ändern:** Stufe 2. Die Probe nimmt einen Fehler von selbst zurück.
//   - **ufw einschalten:** Stufe 2, und die Frage nennt, was erreichbar bleibt.
//     Auch hier fängt die Probe den Fehler.
//   - **ufw ausschalten:** Stufe 3 mit dem Hostnamen. Das ist die einzige der drei
//     Aktionen, die sich NICHT von selbst zurücknimmt — sie öffnet den Server, und
//     dieser Zustand bleibt, bis jemand ihn ändert.

import (
	"context"
	"fmt"
	"net/http"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// firewallConfirmWindow ist die Frist zur Bestätigung einer Firewalländerung.
//
// Sie steht hier und nicht beim Wächter: Wie lange eine Probe läuft, ist eine
// Entscheidung des Bereichs, der sie stellt. Sechzig Sekunden sind lang genug,
// um zu merken, dass die Verbindung noch steht, und kurz genug, dass niemand
// eine Minute lang glaubt, die Änderung sei schon fest.
const firewallConfirmWindow = 60 * time.Second

// apiRegel ist eine Regel für eingehenden Verkehr.
type apiRegel struct {
	Port      int    `json:"port"`
	Protokoll string `json:"protokoll"`
	Quelle    string `json:"quelle"`
	Notiz     string `json:"notiz"`
}

func (r apiRegel) nachPrivops() privops.FirewallRule {
	return privops.FirewallRule{
		Port: r.Port, Protocol: r.Protokoll, Source: r.Quelle, Comment: r.Notiz,
	}
}

func regelAus(r privops.FirewallRule) apiRegel {
	return apiRegel{Port: r.Port, Protokoll: r.Protocol, Quelle: r.Source, Notiz: r.Comment}
}

// apiRegelZeile ist eine Regel mit dem, was die Oberfläche über sie wissen muss.
type apiRegelZeile struct {
	apiRegel
	// Fest heißt: Diese Regel darf nicht weg. Es ist die des Panels.
	Fest bool `json:"fest"`
	// Vorschlag heißt: Die Regel gibt es noch nicht, sie wäre aber sinnvoll —
	// etwa der Port, auf dem sshd laut Konfiguration lauscht. Wer ufw ohne
	// SSH-Regel einschaltet, verliert den zweiten Weg auf den Server und merkt es
	// erst, wenn er ihn braucht.
	Vorschlag bool   `json:"vorschlag"`
	Hinweis   string `json:"hinweis"`
}

// apiProbe ist die laufende Probezeit.
type apiProbe struct {
	Offen bool `json:"offen"`
	// Gegenstand benennt, was auf Probe steht: „Regelsatz" oder „Aktivierung".
	Gegenstand string `json:"gegenstand"`
	// RestSekunden ist die Restfrist, wie der Server sie sieht. Der Browser zählt
	// davon herunter; verbindlich bleibt der Wächter.
	RestSekunden int `json:"rest_sekunden"`
}

// apiFirewall ist die Antwort von GET /api/v1/firewall.
type apiFirewall struct {
	Regelwerk   string `json:"regelwerk"` // ufw | nftables | keins
	Aktiv       bool   `json:"aktiv"`
	Verwaltet   bool   `json:"verwaltet"`
	Installiert bool   `json:"installiert"`
	// Anmerkung erklärt bei nicht verwalteten Regelwerken, warum.
	Anmerkung string          `json:"anmerkung"`
	Zeilen    []apiRegelZeile `json:"zeilen"`
	Probe     apiProbe        `json:"probe"`
	// PanelPort und PanelPortOffen sind die Sicherung gegen das Aussperren: Ohne
	// eine Regel von überall her für diesen Port wird das Einschalten verweigert.
	PanelPort      int    `json:"panel_port"`
	PanelPortOffen bool   `json:"panel_port_offen"`
	OffeneZugaenge string `json:"offene_zugaenge"`
	Rechnername    string `json:"rechnername"`
	// Job ist die laufende oder letzte ufw-Installation, null wenn keine.
	Job    *apiJob `json:"job"`
	Fehler string  `json:"fehler"`
}

func (s *Server) firewallAntwort(ctx context.Context) apiFirewall {
	antwort := apiFirewall{
		Zeilen:      []apiRegelZeile{},
		PanelPort:   s.cfg.Server.Port,
		Rechnername: s.rechnername(),
		Job:         s.jobAus(jobFirewallInstall),
	}

	zustand, err := s.ops.FirewallState(ctx)
	if err != nil {
		s.log.Error("firewall lesen", "err", err)
		antwort.Fehler = "Der Firewall-Zustand ist nicht verfügbar: " + err.Error()
	}

	antwort.Regelwerk = string(zustand.Backend)
	antwort.Aktiv = zustand.Active
	antwort.Verwaltet = zustand.Managed
	antwort.Installiert = zustand.Installed
	antwort.Anmerkung = zustand.Notice
	antwort.PanelPortOffen = ruleCoversPort(zustand.Rules, s.cfg.Server.Port)
	antwort.OffeneZugaenge = openPortSummary(zustand.Rules)

	// Dieselben Zeilen wie die alte Oberfläche: Panel-Regel zuerst und fest,
	// dann die bestehenden, zuletzt die Vorschläge. Aus derselben Funktion, damit
	// nicht eine der beiden Oberflächen einen Vorschlag verschweigt.
	for _, zeile := range s.firewallRows(ctx, zustand.Rules) {
		antwort.Zeilen = append(antwort.Zeilen, apiRegelZeile{
			apiRegel:  regelAus(zeile.Rule),
			Fest:      zeile.Locked,
			Vorschlag: zeile.Proposed,
			Hinweis:   zeile.Note,
		})
	}

	offen, rest := s.fwGuard.state()
	antwort.Probe = apiProbe{
		Offen:        offen,
		Gegenstand:   s.fwGuard.subjectOf(),
		RestSekunden: int(rest.Seconds()),
	}
	return antwort
}

func (s *Server) handleAPIFirewall(w http.ResponseWriter, r *http.Request) {
	s.apiJSON(w, http.StatusOK, s.firewallAntwort(r.Context()))
}

// apiRegelAnfrage ist der Körper von POST /api/v1/firewall/rules.
//
// Übergeben wird immer die vollständige gewünschte Liste, nicht eine einzelne
// Änderung. Damit ist der Zustand nach dem Absenden eindeutig, auch wenn zwei
// Personen gleichzeitig arbeiten — dieselbe Entscheidung wie beim Formular der
// alten Oberfläche.
type apiRegelAnfrage struct {
	Regeln     []apiRegel `json:"regeln"`
	Bestaetigt bool       `json:"bestaetigt"`
	Getippt    string     `json:"getippt"`
}

func (s *Server) handleAPIFirewallRules(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	var anfrage apiRegelAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}

	vorher, err := s.ops.FirewallState(ctx)
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}

	regeln := make([]privops.FirewallRule, 0, len(anfrage.Regeln))
	for _, roh := range anfrage.Regeln {
		regel := roh.nachPrivops()
		if regel.Protocol == "" {
			regel.Protocol = "tcp"
		}
		// Geprüft wird mit derselben Funktion wie beim Formular. Eine zweite
		// Prüfung für den JSON-Weg wäre die Stelle, an der ein Zeichen
		// durchrutscht, das die eine Fassung kennt und die andere nicht.
		if err := privops.ValidateRule(regel); err != nil {
			s.apiFehler(w, http.StatusBadRequest, err.Error())
			return
		}
		regeln = append(regeln, regel)
	}
	regeln = ensurePanelRule(regeln, s.cfg.Server.Port)

	// Stufe 2. Eine dritte braucht es nicht: Die Probe nimmt einen Fehler von
	// selbst zurück — das ist mehr Sicherung als ein getipptes Wort.
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, apiBestaetigung{
		Titel: "Regeln übernehmen",
		Frage: fmt.Sprintf("%d Regeln übernehmen? Erreichbar bleibt danach: %s.",
			len(regeln), openPortSummary(regeln)),
		Punkte: []string{
			"Alles andere wird abgewiesen, sobald ufw eingeschaltet ist.",
			"Die Regeln gelten zunächst auf Probe: Ohne Bestätigung binnen 60 Sekunden " +
				"wird der vorherige Stand wiederhergestellt.",
			"Bestätigen Sie, solange diese Verbindung noch steht.",
		},
		Knopf: "übernehmen",
	}) {
		return
	}

	if err := s.ops.FirewallApply(ctx, regeln); err != nil {
		s.audit(r, "firewall.apply", "", store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	s.audit(r, "firewall.apply", "", store.ResultOK,
		fmt.Sprintf("%d Regeln, Bestätigung ausstehend", len(regeln)))

	// Auf Probe. Der Rückbau läuft im Wächter und nicht hier: Er muss auch dann
	// stattfinden, wenn diese Anfrage längst beendet ist — im schlimmsten Fall,
	// weil das Panel gerade nicht mehr erreichbar ist.
	vorherige := vorher.Rules
	s.fwGuard.arm("Regelsatz", func(ctx context.Context) error {
		s.log.Warn("Firewall-Änderung nicht bestätigt — Rückbau läuft")
		return s.revertFirewall(ctx, "firewall.revert", s.ops.FirewallApply(ctx, vorherige))
	})

	s.apiJSON(w, http.StatusOK, apiFirewallAntwort{
		Meldung: "Die Regeln gelten auf Probe. Ohne Bestätigung innerhalb von " +
			"60 Sekunden wird der vorherige Stand wiederhergestellt.",
		Zustand: s.firewallAntwort(ctx),
	})
}

// apiFirewallAntwort ist die Antwort auf eine Änderung: die Meldung und der neu
// gelesene Zustand — dasselbe Muster wie bei den Diensten. Ohne den Zustand
// müsste die Oberfläche eine zweite Anfrage stellen und zeigte in der Lücke die
// alte Frist.
type apiFirewallAntwort struct {
	Meldung string      `json:"meldung"`
	Zustand apiFirewall `json:"zustand"`
}

// apiAktivAnfrage ist der Körper von POST /api/v1/firewall/active.
type apiAktivAnfrage struct {
	Aktiv      bool   `json:"aktiv"`
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// handleAPIFirewallActive schaltet ufw ein oder aus.
//
// Das Einschalten ist die gefährlichste Aktion, die dieses Panel kennt: ufw
// weist danach alles ab, was nicht ausdrücklich erlaubt ist — auch die
// Verbindung, über die gerade geklickt wurde. Bestehende Verbindungen überleben
// dank Conntrack meist den Moment, der nächste Seitenaufruf aber nicht mehr.
// Deshalb zwei Sicherungen: Ohne freigegebenen Panel-Port wird die Aktivierung
// verweigert, und danach gilt sie auf Probe.
func (s *Server) handleAPIFirewallActive(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	var anfrage apiAktivAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}

	zustand, err := s.ops.FirewallState(ctx)
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	if !zustand.Installed {
		s.apiFehler(w, http.StatusBadRequest, "ufw ist nicht installiert.")
		return
	}

	if anfrage.Aktiv {
		// Die Sperre vor der Rückfrage: Ohne Regel für den Panel-Port führt kein
		// Weg zum Bestätigen zurück, und dann hilft auch die Probe nur noch
		// insofern, dass sie nach 60 Sekunden aufräumt. Das ist eine Minute
		// Ausfall, die niemand braucht.
		if !ruleCoversPort(zustand.Rules, s.cfg.Server.Port) {
			s.audit(r, "firewall.activate", "", store.ResultError, "Panel-Port nicht freigegeben")
			s.apiFehler(w, http.StatusBadRequest, fmt.Sprintf(
				"Für Port %d gibt es keine Regel — das Panel wäre nach dem Einschalten "+
					"nicht mehr erreichbar, auch nicht zum Bestätigen. Legen Sie die Regel "+
					"zuerst an.", s.cfg.Server.Port))
			return
		}
		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
		}, apiBestaetigung{
			Titel: "ufw einschalten",
			Frage: "ufw einschalten? Erreichbar bleibt danach nur: " +
				openPortSummary(zustand.Rules) + ".",
			Punkte: []string{
				"Alles andere wird abgewiesen — auch Zugänge, die gerade offen sind.",
				"Die Regeln gelten zunächst auf Probe: Ohne Bestätigung binnen 60 Sekunden " +
					"schaltet sich ufw wieder aus.",
				"Bestätigen Sie, solange diese Verbindung noch steht.",
			},
			Knopf: "ufw einschalten",
		}) {
			return
		}
	} else {
		// Stufe 3 mit dem Hostnamen: Ausschalten öffnet den Server für jede
		// eingehende Verbindung, und dieser Zustand nimmt sich nicht von selbst
		// zurück. Es ist die einzige der drei Aktionen ohne Probe — und deshalb
		// die einzige mit getipptem Wort.
		host := s.rechnername()
		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
		}, apiBestaetigung{
			Titel: "ufw ausschalten",
			Frage: "ufw auf " + host + " ausschalten?",
			Punkte: []string{
				"Der Server nimmt danach jede eingehende Verbindung an — auf allen Ports, von überall.",
				"Der Regelsatz bleibt gespeichert und gilt wieder, sobald ufw eingeschaltet wird.",
				"Anders als beim Einschalten gibt es hier keine Probezeit: Der Zustand bleibt, " +
					"bis jemand ihn ändert.",
			},
			Knopf:         "ufw ausschalten",
			Tippen:        host,
			TippenHinweis: "Zum Bestätigen den Hostnamen eingeben: " + host,
		}) {
			return
		}
	}

	if err := s.ops.FirewallSetActive(ctx, anfrage.Aktiv); err != nil {
		s.audit(r, "firewall.activate", "", store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}

	if !anfrage.Aktiv {
		s.audit(r, "firewall.activate", "", store.ResultOK, "ufw ausgeschaltet")
		s.apiJSON(w, http.StatusOK, apiFirewallAntwort{
			Meldung: "ufw ist ausgeschaltet. Der Server nimmt wieder jede eingehende " +
				"Verbindung an.",
			Zustand: s.firewallAntwort(ctx),
		})
		return
	}

	s.audit(r, "firewall.activate", "", store.ResultOK, "ufw eingeschaltet, Bestätigung ausstehend")
	s.fwGuard.arm("Aktivierung", func(ctx context.Context) error {
		s.log.Warn("Firewall-Aktivierung nicht bestätigt — ufw wird wieder ausgeschaltet")
		return s.revertFirewall(ctx, "firewall.revert", s.ops.FirewallSetActive(ctx, false))
	})

	s.apiJSON(w, http.StatusOK, apiFirewallAntwort{
		Meldung: fmt.Sprintf("ufw ist eingeschaltet und gilt auf Probe. Erreichbar: %s. "+
			"Ohne Bestätigung binnen 60 Sekunden schaltet sich ufw wieder aus.",
			openPortSummary(zustand.Rules)),
		Zustand: s.firewallAntwort(ctx),
	})
}

// handleAPIFirewallConfirm beendet die Probe.
//
// Ohne Rückfrage: Bestätigen ist die Zustimmung zu etwas, das gerade schon gilt.
// Eine Rückfrage vor der Bestätigung wäre eine Rückfrage vor der Rückfrage.
func (s *Server) handleAPIFirewallConfirm(w http.ResponseWriter, r *http.Request) {
	if !s.fwGuard.confirm() {
		// 409 und nicht 400: Die Anfrage ist in Ordnung, sie kommt nur zu spät
		// (oder zu früh). Der beigelegte Zustand sagt der Oberfläche, was gilt —
		// meist: die Frist ist abgelaufen und der Rückbau hat stattgefunden.
		s.apiJSON(w, http.StatusConflict, apiFirewallAntwort{
			Meldung: "Es steht keine Bestätigung aus. Wenn eine Frist lief, ist sie " +
				"abgelaufen und der vorherige Stand wiederhergestellt.",
			Zustand: s.firewallAntwort(r.Context()),
		})
		return
	}
	s.audit(r, "firewall.confirm", "", store.ResultOK, "")

	s.apiJSON(w, http.StatusOK, apiFirewallAntwort{
		Meldung: "Die Änderung ist bestätigt und bleibt bestehen.",
		Zustand: s.firewallAntwort(r.Context()),
	})
}

// handleAPIFirewallInstall spielt ufw ein — als Vorgang, wie ein Paket-Update.
func (s *Server) handleAPIFirewallInstall(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	j, neu := s.jobs.start(jobFirewallInstall, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Die Installation läuft bereits.")
		return
	}
	s.audit(r, "firewall.install", "ufw", store.ResultOK, "gestartet")

	// Wie beim Paket-Update: eigener Kontext, damit ein abgebrochener
	// Seitenaufruf kein halb konfiguriertes dpkg hinterlässt.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
		defer cancel()

		err := s.ops.FirewallInstall(ctx, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(user.Username, "firewall.install", "ufw", result, detail)
	}()

	s.gestartet(w, jobFirewallInstall, "ufw wird installiert.")
}
