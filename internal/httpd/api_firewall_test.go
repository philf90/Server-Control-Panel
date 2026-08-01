package httpd

// Tests für /api/v1/firewall.
//
// Der Schwerpunkt liegt auf den Sicherungen, nicht auf der Darstellung. Dieses
// Modul ist das einzige, bei dem ein Fehler den Zugang zum Panel kostet — und
// zwar aus der Seite heraus, auf der man ihn zurücknehmen könnte. Geprüft wird
// deshalb vor allem, was NICHT passieren darf:
//
//   - Einschalten ohne Regel für den Panel-Port.
//   - Eine Änderung, die ohne Bestätigung dauerhaft gilt.
//   - Ausschalten ohne getippten Hostnamen.
//   - Ein Regelsatz, der die Panel-Regel nicht enthält, weil der Aufrufer sie
//     weggelassen hat.

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Der Zustand: Regelwerk, Aktivität, Zeilen, und die Probe als Feld.
func TestAPIFirewallZustand(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	var antwort apiFirewall
	mussJSON(t, get(t, s, "/api/v1/firewall", cookie), &antwort)

	if antwort.Regelwerk != string(privops.BackendUFW) || !antwort.Aktiv {
		t.Errorf("Regelwerk %q, aktiv %v — erwartet ufw und aktiv", antwort.Regelwerk, antwort.Aktiv)
	}
	if antwort.PanelPort == 0 {
		t.Error("der Panel-Port fehlt — ohne ihn kann die Oberfläche die Sicherung nicht zeigen")
	}
	if antwort.Rechnername == "" {
		t.Error("der Rechnername fehlt — die Rückfrage zum Ausschalten verlangt ihn")
	}
	// Die Panel-Regel steht zuerst und ist fest. Sie darf nicht weg, und die
	// Oberfläche muss das wissen, ohne es selbst herzuleiten.
	if len(antwort.Zeilen) == 0 {
		t.Fatal("keine Zeilen")
	}
	if !antwort.Zeilen[0].Fest {
		t.Errorf("die erste Zeile ist nicht fest: %+v", antwort.Zeilen[0])
	}
	if antwort.Zeilen[0].Port != antwort.PanelPort {
		t.Errorf("die feste Zeile hat Port %d, erwartet den Panel-Port %d",
			antwort.Zeilen[0].Port, antwort.PanelPort)
	}
	// Ohne laufende Frist ist die Probe zu — und ein Feld, kein fehlender Wert.
	if antwort.Probe.Offen {
		t.Error("es steht eine Probe aus, obwohl nichts geändert wurde")
	}
}

// Die Probe steht im Zustand, samt Restfrist. Wer die Seite neu lädt, während
// die Frist läuft, muss den Countdown vorfinden — sonst bestätigt er nicht, und
// die Änderung fällt weg, ohne dass er weiß, warum.
func TestAPIFirewallProbeStehtImZustand(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)

	rec := postJSON(t, s, "/api/v1/firewall/rules",
		`{"regeln":[{"port":22,"protokoll":"tcp","quelle":"","notiz":"SSH"}],"bestaetigt":true}`,
		cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	var antwort apiFirewallAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if !antwort.Zustand.Probe.Offen {
		t.Fatal("nach einer Änderung steht keine Probe aus — dann gilt sie dauerhaft, " +
			"und Grundsatz VI ist wirkungslos")
	}
	if antwort.Zustand.Probe.Gegenstand != "Regelsatz" {
		t.Errorf("Gegenstand = %q, erwartet Regelsatz", antwort.Zustand.Probe.Gegenstand)
	}
	if antwort.Zustand.Probe.RestSekunden <= 0 || antwort.Zustand.Probe.RestSekunden > 60 {
		t.Errorf("Restfrist = %d s, erwartet zwischen 1 und 60",
			antwort.Zustand.Probe.RestSekunden)
	}

	// Und ein frischer Aufruf sieht sie auch — sie hängt nicht an der Antwort.
	var frisch apiFirewall
	mussJSON(t, get(t, s, "/api/v1/firewall", cookie), &frisch)
	if !frisch.Probe.Offen || frisch.Probe.RestSekunden <= 0 {
		t.Errorf("ein neuer Aufruf sieht die Probe nicht: %+v", frisch.Probe)
	}
}

// Der Panel-Port wird nicht der Anfrage überlassen. In der Oberfläche steht die
// Regel unveränderlich da, aber ein gesperrtes Feld ist eine Bitte, keine
// Sperre: Wer die Anfrage selbst baut, lässt sie weg.
func TestAPIFirewallPanelRegelWirdErgaenzt(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	// Ein Regelsatz ohne den Panel-Port — genau der Fall, den ein selbstgebautes
	// POST erzeugt.
	rec := postJSON(t, s, "/api/v1/firewall/rules",
		`{"regeln":[{"port":80,"protokoll":"tcp","quelle":"","notiz":"HTTP"}],"bestaetigt":true}`,
		cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	angewandt := ops.letzteRegeln()
	if len(angewandt) != 2 {
		t.Fatalf("%d Regeln angewandt, erwartet 2 (HTTP plus die ergänzte Panel-Regel): %+v",
			len(angewandt), angewandt)
	}
	if !ruleCoversPort(angewandt, s.cfg.Server.Port) {
		t.Errorf("die Panel-Regel wurde nicht ergänzt: %+v", angewandt)
	}
}

// Ohne Bestätigung wird nichts geschrieben. Stufe 2 — die Probe nimmt einen
// Fehler von selbst zurück, deshalb genügt der zweite Klick.
func TestAPIFirewallRegelnFragenZurueck(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/firewall/rules",
		`{"regeln":[{"port":80,"protokoll":"tcp","quelle":"","notiz":""}]}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	if len(ops.regelSaetze()) != 0 {
		t.Fatalf("es wurde ohne Bestätigung geschrieben: %+v", ops.regelSaetze())
	}

	var frage apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	// Die Frage nennt, was erreichbar bleibt. „Regeln übernehmen?" befähigt zu
	// keiner Entscheidung; eine Liste der Ports schon.
	if !strings.Contains(frage.Bestaetigung.Frage, "80/tcp") {
		t.Errorf("die Frage nennt nicht, was erreichbar bleibt: %q", frage.Bestaetigung.Frage)
	}
	if frage.Bestaetigung.Tippen != "" {
		t.Error("Regeln übernehmen verlangt ein getipptes Wort — es ist Stufe 2, " +
			"weil die Probe den Fehler zurücknimmt")
	}
	if len(frage.Bestaetigung.Punkte) == 0 {
		t.Error("die Rückfrage nennt keine Folgen")
	}
}

// Eine unsinnige Regel wird abgewiesen — mit derselben Prüfung wie beim
// Formular. Eine zweite Prüfung für den JSON-Weg wäre die Stelle, an der ein
// Zeichen durchrutscht, das die eine Fassung kennt und die andere nicht.
func TestAPIFirewallRegelnWerdenGeprueft(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	faelle := []string{
		`{"regeln":[{"port":0,"protokoll":"tcp"}],"bestaetigt":true}`,
		`{"regeln":[{"port":99999,"protokoll":"tcp"}],"bestaetigt":true}`,
		`{"regeln":[{"port":80,"protokoll":"icmp"}],"bestaetigt":true}`,
		`{"regeln":[{"port":80,"protokoll":"tcp","quelle":"nicht; wirklich"}],"bestaetigt":true}`,
	}
	for _, koerper := range faelle {
		rec := postJSON(t, s, "/api/v1/firewall/rules", koerper, cookie, csrf)
		if rec.Code != http.StatusBadRequest {
			t.Errorf("%s: Status = %d, erwartet 400: %s", koerper, rec.Code, rec.Body.String())
		}
	}
	if len(ops.regelSaetze()) != 0 {
		t.Errorf("es wurde etwas geschrieben: %+v", ops.regelSaetze())
	}
}

// Die wichtigste Sperre des Panels: Ohne Regel für den eigenen Port wird das
// Einschalten verweigert — vor der Rückfrage, nicht danach. Danach wäre die
// Rückfrage eine Einladung in eine Minute Ausfall.
func TestAPIFirewallEinschaltenOhnePanelPortAbgewiesen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: false, Managed: true, Installed: true,
		// Nur SSH, nicht der Panel-Port.
		Rules: []privops.FirewallRule{{Port: 22, Protocol: "tcp"}},
	}

	rec := postJSON(t, s, "/api/v1/firewall/active",
		`{"aktiv":true,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "nicht mehr erreichbar") {
		t.Errorf("die Meldung sagt nicht, was passieren würde: %s", rec.Body.String())
	}
	for _, a := range ops.recorded() {
		if strings.HasPrefix(a, "firewall:active") {
			t.Fatal("ufw wurde eingeschaltet, obwohl der Panel-Port fehlt — " +
				"das Panel wäre danach nicht mehr erreichbar, auch nicht zum Bestätigen")
		}
	}

	// Und der Zustand sagt es der Oberfläche, damit sie den Knopf gar nicht
	// anbietet.
	var zustand apiFirewall
	mussJSON(t, get(t, s, "/api/v1/firewall", cookie), &zustand)
	if zustand.PanelPortOffen {
		t.Error("der Zustand meldet den Panel-Port als offen, obwohl keine Regel dafür da ist")
	}
}

// Einschalten ist Stufe 2 und stellt danach auf Probe. Ausschalten ist Stufe 3
// mit dem Hostnamen und hat KEINE Probe: Es öffnet den Server, und dieser
// Zustand nimmt sich nicht von selbst zurück.
func TestAPIFirewallSchaltenStufenUndProbe(t *testing.T) {
	t.Run("einschalten fragt und stellt auf Probe", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleAdmin)
		ops := s.ops.(*fakeOps)
		ops.firewall = privops.FirewallState{
			Backend: privops.BackendUFW, Active: false, Managed: true, Installed: true,
			Rules: []privops.FirewallRule{{Port: s.cfg.Server.Port, Protocol: "tcp"}},
		}

		rec := postJSON(t, s, "/api/v1/firewall/active", `{"aktiv":true}`, cookie, csrf)
		if rec.Code != http.StatusConflict {
			t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
		}
		var frage apiBestaetigungAntwort
		if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
			t.Fatalf("Antwort ist kein JSON: %v", err)
		}
		if frage.Bestaetigung.Tippen != "" {
			t.Error("Einschalten verlangt ein getipptes Wort — die Probe genügt hier")
		}
		// Die Frage nennt, was erreichbar bleibt.
		if !strings.Contains(frage.Bestaetigung.Frage, "Erreichbar") {
			t.Errorf("die Frage sagt nicht, was erreichbar bleibt: %q", frage.Bestaetigung.Frage)
		}

		rec = postJSON(t, s, "/api/v1/firewall/active",
			`{"aktiv":true,"bestaetigt":true}`, cookie, csrf)
		if rec.Code != http.StatusOK {
			t.Fatalf("bestätigt: Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
		}
		var antwort apiFirewallAntwort
		if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
			t.Fatalf("Antwort ist kein JSON: %v", err)
		}
		if !antwort.Zustand.Probe.Offen || antwort.Zustand.Probe.Gegenstand != "Aktivierung" {
			t.Errorf("nach dem Einschalten steht keine Probe \"Aktivierung\" aus: %+v",
				antwort.Zustand.Probe)
		}
	})

	t.Run("ausschalten verlangt den Hostnamen und hat keine Probe", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleAdmin)
		ops := s.ops.(*fakeOps)
		// Installed ausdrücklich setzen: Die Vorgabe der Attrappe ist "aktiv, aber
		// nicht installiert" — ein Zustand, den es auf einem echten System nicht
		// gibt. Ohne ufw weist der Handler zu Recht ab, und der Test prüfte dann
		// die falsche Sperre.
		ops.firewall = privops.FirewallState{
			Backend: privops.BackendUFW, Active: true, Managed: true, Installed: true,
			Rules: []privops.FirewallRule{{Port: 22, Protocol: "tcp"}},
		}

		rec := postJSON(t, s, "/api/v1/firewall/active", `{"aktiv":false}`, cookie, csrf)
		if rec.Code != http.StatusConflict {
			t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
		}
		var frage apiBestaetigungAntwort
		if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
			t.Fatalf("Antwort ist kein JSON: %v", err)
		}
		host := frage.Bestaetigung.Tippen
		if host == "" {
			t.Fatal("Ausschalten verlangt kein getipptes Wort — es ist Stufe 3, " +
				"weil es sich nicht von selbst zurücknimmt")
		}

		// Falsches Wort: nichts passiert.
		rec = postJSON(t, s, "/api/v1/firewall/active",
			`{"aktiv":false,"bestaetigt":true,"getippt":"falsch"}`, cookie, csrf)
		if rec.Code != http.StatusConflict {
			t.Errorf("falsches Wort: Status = %d, erwartet 409", rec.Code)
		}
		for _, a := range ops.recorded() {
			if strings.HasPrefix(a, "firewall:active") {
				t.Fatal("mit falschem Wort wurde ausgeschaltet")
			}
		}

		rec = postJSON(t, s, "/api/v1/firewall/active",
			`{"aktiv":false,"bestaetigt":true,"getippt":"`+host+`"}`, cookie, csrf)
		if rec.Code != http.StatusOK {
			t.Fatalf("richtiges Wort: Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
		}
		var antwort apiFirewallAntwort
		if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
			t.Fatalf("Antwort ist kein JSON: %v", err)
		}
		// Keine Probe: Ausschalten öffnet, es sperrt nicht aus.
		if antwort.Zustand.Probe.Offen {
			t.Error("nach dem Ausschalten läuft eine Probe — es gibt nichts zurückzunehmen, " +
				"und eine Frist würde die Firewall nach 60 Sekunden von selbst wieder einschalten")
		}
	})
}

// Bestätigen beendet die Frist. Ein zweites Mal ist ein 409 mit dem Zustand —
// meist heißt das: Die Frist ist abgelaufen und der Rückbau hat stattgefunden.
func TestAPIFirewallBestaetigen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)

	// Ohne laufende Probe: 409, und die Antwort trägt den Zustand.
	rec := postJSON(t, s, "/api/v1/firewall/confirm", "", cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("ohne Probe: Status = %d, erwartet 409", rec.Code)
	}
	var leer apiFirewallAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &leer); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if leer.Meldung == "" {
		t.Error("die Antwort sagt nicht, warum nichts zu bestätigen war")
	}

	// Mit laufender Probe: bestätigt, danach keine mehr.
	postJSON(t, s, "/api/v1/firewall/rules",
		`{"regeln":[{"port":22,"protokoll":"tcp"}],"bestaetigt":true}`, cookie, csrf)

	rec = postJSON(t, s, "/api/v1/firewall/confirm", "", cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiFirewallAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if antwort.Zustand.Probe.Offen {
		t.Error("nach dem Bestätigen läuft die Probe weiter")
	}
}

// Ohne Schreibrecht läuft keine Änderung — und die Antwort ist JSON.
func TestAPIFirewallBrauchtSchreibrecht(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleReadOnly)
	ops := s.ops.(*fakeOps)

	faelle := []struct{ pfad, koerper string }{
		{"/api/v1/firewall/rules", `{"regeln":[],"bestaetigt":true}`},
		{"/api/v1/firewall/active", `{"aktiv":false,"bestaetigt":true,"getippt":"vm"}`},
		{"/api/v1/firewall/confirm", ""},
		{"/api/v1/firewall/install", ""},
	}
	for _, f := range faelle {
		rec := postJSON(t, s, f.pfad, f.koerper, cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Status = %d, erwartet 403", f.pfad, rec.Code)
		}
	}
	// Lesen darf sie: Wer nicht ändern darf, soll sehen, was gilt.
	if rec := get(t, s, "/api/v1/firewall", cookie); rec.Code != http.StatusOK {
		t.Errorf("Lesen: Status = %d, erwartet 200", rec.Code)
	}
	if len(ops.recorded()) != 0 || len(ops.appliedRules) != 0 {
		t.Errorf("es wurde etwas ausgeführt: %v %+v", ops.recorded(), ops.regelSaetze())
	}
}

// Bestätigen ist schreibend und braucht das Token. Ein Weg, eine Probe ohne
// Token endgültig zu machen, wäre von einer fremden Seite auslösbar.
func TestAPIFirewallBestaetigenBrauchtToken(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)

	postJSON(t, s, "/api/v1/firewall/rules",
		`{"regeln":[{"port":22,"protokoll":"tcp"}],"bestaetigt":true}`, cookie, csrf)

	rec := postJSON(t, s, "/api/v1/firewall/confirm", "", cookie, "")
	if rec.Code != http.StatusForbidden {
		t.Errorf("ohne Token: Status = %d, erwartet 403", rec.Code)
	}
	// Die Probe läuft weiter — sie wurde nicht bestätigt.
	if offen, _ := s.fwGuard.state(); !offen {
		t.Error("die Probe wurde ohne Token bestätigt")
	}
}

// Die ufw-Installation ist ein Vorgang wie ein Paket-Update: 202, und zusehen
// läuft über /api/v1/jobs.
func TestAPIFirewallInstallIstEinVorgang(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)

	rec := postJSON(t, s, "/api/v1/firewall/install", "", cookie, csrf)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	var antwort apiVorgangGestartet
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if antwort.Job.Art != jobFirewallInstall || antwort.Job.Titel == "" {
		t.Errorf("Vorgang = %+v, erwartet Art %q mit Beschriftung", antwort.Job, jobFirewallInstall)
	}
	// Und derselbe Vorgang ist über die gemeinsame Ressource abfragbar.
	if rec := get(t, s, "/api/v1/jobs/firewall-install", cookie); rec.Code != http.StatusOK {
		t.Errorf("der Vorgang ist nicht abfragbar: Status = %d", rec.Code)
	}
}

// Ein Vorschlag entsteht für den Port, auf dem sshd laut Konfiguration lauscht,
// wenn dafür keine Regel da ist.
//
// Er ist kein Selbstzweck: Wer ufw ohne SSH-Regel einschaltet, verliert den
// zweiten Weg auf den Server — und merkt es erst, wenn er ihn braucht. Der Port
// kommt aus sshd_config und nicht aus der Annahme „22".
func TestAPIFirewallSchlaegtDenSSHPortVor(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.sshPorts = []int{2222}
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: false, Managed: true, Installed: true,
		// Nur der Panel-Port, kein SSH.
		Rules: []privops.FirewallRule{{Port: s.cfg.Server.Port, Protocol: "tcp"}},
	}

	var antwort apiFirewall
	mussJSON(t, get(t, s, "/api/v1/firewall", cookie), &antwort)

	var vorschlag *apiRegelZeile
	for i := range antwort.Zeilen {
		if antwort.Zeilen[i].Vorschlag {
			vorschlag = &antwort.Zeilen[i]
		}
	}
	if vorschlag == nil {
		t.Fatalf("kein Vorschlag für den SSH-Port: %+v", antwort.Zeilen)
	}
	if vorschlag.Port != 2222 {
		t.Errorf("Vorschlag für Port %d, erwartet 2222 aus sshd_config — nicht die Annahme 22",
			vorschlag.Port)
	}
	if vorschlag.Hinweis == "" {
		t.Error("der Vorschlag sagt nicht, warum er da ist")
	}

	// Und er ist NICHT Teil des Regelsatzes: Ein Vorschlag, der beim nächsten
	// Übernehmen stillschweigend mitgeschrieben würde, wäre keiner.
	if ruleCoversPort(regelnAusZeilen(antwort.Zeilen), 2222) {
		t.Error("der Vorschlag steht im Regelsatz — er soll angeboten und nicht angewandt werden")
	}
}

// regelnAusZeilen sammelt die Regeln, die tatsächlich gelten — also alles außer
// den Vorschlägen. Dieselbe Auswahl, die die Oberfläche beim Übernehmen trifft.
func regelnAusZeilen(zeilen []apiRegelZeile) []privops.FirewallRule {
	out := make([]privops.FirewallRule, 0, len(zeilen))
	for _, z := range zeilen {
		if z.Vorschlag {
			continue
		}
		out = append(out, z.nachPrivops())
	}
	return out
}
