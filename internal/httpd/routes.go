package httpd

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/ui"
	"github.com/philf90/asylum/internal/version"
)

// Handler baut den Router auf. Öffentlich, damit Tests ihn ohne TLS-Listener
// verwenden können.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	// Offen zugänglich.
	mux.HandleFunc("GET /healthz", s.handleHealth)
	mux.HandleFunc("GET /login", s.handleLoginForm)
	mux.Handle("POST /login", s.rateLimited(http.HandlerFunc(s.handleLogin)))
	// Anmeldung mit Passkey: zwei Schritte, beide ratenbegrenzt wie /login und
	// ohne CSRF (es gibt noch keine Sitzung). Die Assertion selbst ist an den
	// Ursprung gebunden und damit der Schutz gegen fremde Seiten.
	mux.Handle("POST /login/passkey/begin", s.rateLimited(http.HandlerFunc(s.handlePasskeyLoginBegin)))
	mux.Handle("POST /login/passkey/finish", s.rateLimited(http.HandlerFunc(s.handlePasskeyLoginFinish)))
	// Vergessenes Passwort: Nachweis über einen auffindbaren Passkey mit Prüfung
	// am Gerät. Kein Konto in der Anfrage, deshalb auch keine Auskunft darüber,
	// welche es gibt. Alle Schritte ratenbegrenzt; CSRF entfällt, weil es noch
	// keine Sitzung gibt — der Riegel ist SameSite=Strict am Ticket-Cookie.
	mux.HandleFunc("GET /login/forgot", s.handleForgotForm)
	mux.Handle("POST /login/forgot/begin", s.rateLimited(http.HandlerFunc(s.handleForgotBegin)))
	mux.Handle("POST /login/forgot/finish", s.rateLimited(http.HandlerFunc(s.handleForgotFinish)))
	mux.Handle("GET /login/forgot/new", s.rateLimited(http.HandlerFunc(s.handleForgotNewForm)))
	mux.Handle("POST /login/forgot/new", s.rateLimited(http.HandlerFunc(s.handleForgotNew)))
	mux.HandleFunc("GET /setup", s.handleSetupForm)
	mux.Handle("POST /setup", s.rateLimited(http.HandlerFunc(s.handleSetup)))

	// Angemeldet, aber noch ohne bestätigtes TOTP erreichbar.
	mux.Handle("GET /setup/2fa", s.loggedIn(http.HandlerFunc(s.handleTOTPForm)))
	mux.Handle("POST /setup/2fa", s.loggedIn(s.verifyCSRF(http.HandlerFunc(s.handleTOTPConfirm))))
	mux.Handle("GET /setup/2fa/qr.png", s.loggedIn(http.HandlerFunc(s.handleTOTPQR)))
	mux.Handle("POST /logout", s.loggedIn(s.verifyCSRF(http.HandlerFunc(s.handleLogout))))

	// Erzwungener Wechsel eines Einmalpassworts. Bewusst requireSetupDone und
	// nicht protected: requireAuth leitet ein Konto mit Wechselzwang genau
	// hierher: durch protected wäre das eine Weiterleitung auf sich selbst.
	mux.Handle("GET /account/password-change", s.requireSetupDone(http.HandlerFunc(s.handlePasswordChangeForcedForm)))
	mux.Handle("POST /account/password-change", s.requireSetupDone(s.verifyCSRF(http.HandlerFunc(s.handlePasswordChangeForced))))

	// Vollständig angemeldet.
	// Die Wurzel und alles darunter liefern die HÜLLE der neuen Oberfläche.
	//
	// Ein Muster ohne {$} ist in Go der allgemeine Rückfall: Auch /dienste und
	// /gibtsnicht landen hier. Für eine Einzelseiten-Anwendung ist das richtig —
	// die Wegewahl passiert im Browser. Damit nicht JEDE erdachte Adresse mit 200
	// beantwortet wird, prüft spaOderNichtGefunden den ersten Pfadteil gegen die
	// Liste der Seiten und antwortet sonst 404 — ein abgeschaltetes Modul wäre
	// sonst von einem vorhandenen nicht zu unterscheiden. Spezifischere Muster
	// (/api/, /static/, /login) gewinnen nach den Vorrangregeln des Multiplexers
	// und werden davon nicht berührt.
	mux.Handle("GET /", s.spaOderNichtGefunden(s.protected(http.HandlerFunc(s.handleV2))))
	// Der Live-Kanal der Übersicht (lib/live.svelte.ts).
	mux.Handle("GET /events", s.protected(http.HandlerFunc(s.handleEvents)))

	// Die Schnittstelle der Oberfläche. Sie ist seit dem Abbau der alten Fläche
	// (0.4.1) der EINZIGE Weg an die Systemfunktionen: Was hier nicht steht, gibt
	// es im Panel nicht. Vorher lag daneben ein zweiter, server-gerenderter Satz
	// Routen mit eigenen Handlern für dieselben Aufgaben — das war der Preis für
	// den Rückweg während der Umstellung und ist mit ihm entfallen.
	mux.Handle("GET /api/v1/session", s.protected(http.HandlerFunc(s.handleAPISession)))
	mux.Handle("GET /api/v1/overview", s.protected(http.HandlerFunc(s.handleAPIOverview)))
	mux.Handle("GET /api/v1/signals", s.protected(http.HandlerFunc(s.handleAPISignals)))
	mux.Handle("GET /api/v1/metrics/history", s.protected(http.HandlerFunc(s.handleAPIMetricsHistory)))
	mux.Handle("GET /api/v1/services", s.protected(http.HandlerFunc(s.handleAPIServices)))
	mux.Handle("GET /api/v1/services/{unit}", s.protected(http.HandlerFunc(s.handleAPIServiceDetail)))
	mux.Handle("POST /api/v1/services/{unit}",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIServiceAction))))
	mux.Handle("GET /api/v1/packages", s.protected(http.HandlerFunc(s.handleAPIPackages)))
	mux.Handle("POST /api/v1/packages/refresh",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIPackageRefresh))))
	mux.Handle("POST /api/v1/packages/upgrade",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIPackageUpgrade))))
	// Der Neustart bleibt der Owner-Rolle vorbehalten — dieselbe Grenze wie bei
	// POST /reboot in der alten Oberfläche. Eine Aktion, die die Verbindung
	// zerreißt, gehört nicht in die Hand jedes Admin-Kontos.
	mux.Handle("POST /api/v1/system/reboot",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIReboot)))))
	// Vorgänge: eine Ressource für alle Module. Der Strom ist ein GET und braucht
	// deshalb kein Token — er verändert nichts.
	mux.Handle("GET /api/v1/jobs/{art}", s.protected(http.HandlerFunc(s.handleAPIJob)))
	mux.Handle("GET /api/v1/jobs/{art}/events", s.protected(http.HandlerFunc(s.handleAPIJobEvents)))
	// Logs: die Abfrage und daneben der Strom, der weiterläuft. Beides lesend,
	// also ohne Schreibrecht und ohne Token — dieselbe Grenze wie bei GET /logs.
	mux.Handle("GET /api/v1/logs", s.protected(http.HandlerFunc(s.handleAPILogs)))
	mux.Handle("GET /api/v1/logs/follow", s.protected(http.HandlerFunc(s.handleAPILogsFollow)))
	// Firewall. Bestätigen ist ausdrücklich schreibend und braucht das Token:
	// Ein GET, der eine Probe endgültig macht, wäre über einen Bildverweis von
	// einer fremden Seite auslösbar.
	mux.Handle("GET /api/v1/firewall", s.protected(http.HandlerFunc(s.handleAPIFirewall)))
	mux.Handle("POST /api/v1/firewall/rules",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFirewallRules))))
	mux.Handle("POST /api/v1/firewall/active",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFirewallActive))))
	mux.Handle("POST /api/v1/firewall/confirm",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFirewallConfirm))))
	mux.Handle("POST /api/v1/firewall/install",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFirewallInstall))))
	// Dateien. Wie bei den alten Routen entstehen sie nur, wenn das Modul läuft
	// — abschalten entfernt Rechte, nicht nur den Menüpunkt.
	if s.files != nil {
		mux.Handle("GET /api/v1/files", s.protected(http.HandlerFunc(s.handleAPIFiles)))
		mux.Handle("GET /api/v1/files/entry", s.protected(http.HandlerFunc(s.handleAPIFileEntry)))
		mux.Handle("GET /api/v1/files/dirs", s.protected(http.HandlerFunc(s.handleAPIFileDirs)))
		// Download und Archiv sind die beiden einzigen Endpunkte unter /api/v1,
		// die kein JSON liefern: Sie streamen Bytes, und der Browser navigiert
		// dorthin — es ist kein fetch. Scheitert der Aufruf, kommt deshalb die
		// server-gerenderte Fehlerseite und keine JSON-Meldung im Adressfeld.
		// Dass die Fehlerseite mit dem Abbau der alten Oberfläche geblieben ist,
		// hat genau hier seinen Grund.
		mux.Handle("GET /api/v1/files/download", s.protected(http.HandlerFunc(s.handleFileDownload)))
		mux.Handle("GET /api/v1/files/archive", s.protected(http.HandlerFunc(s.handleFileArchive)))
		// Verändern: Schreibrolle und Token, wie in jedem anderen Modul. Die
		// Prüfung des Pfads selbst liegt in der Pfadwache, nicht hier.
		mux.Handle("POST /api/v1/files/mkdir",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileMkdir))))
		mux.Handle("POST /api/v1/files/touch",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileTouch))))
		mux.Handle("POST /api/v1/files/rename",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileRename))))
		mux.Handle("POST /api/v1/files/copy",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileCopy))))
		mux.Handle("POST /api/v1/files/move",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileMove))))
		mux.Handle("POST /api/v1/files/delete",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileDelete))))
		mux.Handle("POST /api/v1/files/mode",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileMode))))
		// Der Upload liegt NICHT hinter apiSchreibend: Die Middleware liest das
		// Token aus einer Kopfzeile, und das täte sie auch hier; aber der Handler
		// streamt den Körper Teil für Teil, prüft das Token selbst vor dem ersten
		// Byte Dateiinhalt und darf sich dabei nicht darauf verlassen, dass jemand
		// vorher hineingesehen hat. Die Begründung im Einzelnen steht in
		// handlers_files_upload.go.
		mux.Handle("POST /api/v1/files/upload",
			s.protected(s.requireWrite(http.HandlerFunc(s.handleFileUpload))))
		// Der Editor. Lesen ist ein GET ohne Token; Schreiben geht durch dieselbe
		// Kette wie jede andere Veränderung.
		mux.Handle("GET /api/v1/files/text", s.protected(http.HandlerFunc(s.handleAPIFileText)))
		mux.Handle("POST /api/v1/files/text",
			s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIFileTextSave))))
	}
	// Audit. Nur lesend, und das ist keine Auslassung: Das Protokoll ist nur
	// additiv, es gibt im Store bewusst keine Lösch- oder Änderungsfunktion.
	mux.Handle("GET /api/v1/audit", s.protected(http.HandlerFunc(s.handleAPIAudit)))
	// Systembenutzer und ihre SSH-Schlüssel. Konten des WIRTSYSTEMS, nicht die des
	// Panels — die stehen unter /api/v1/panel-users.
	mux.Handle("GET /api/v1/system-users", s.protected(http.HandlerFunc(s.handleAPISystemUsers)))
	mux.Handle("GET /api/v1/system-users/{name}/keys", s.protected(http.HandlerFunc(s.handleAPISSHKeys)))
	mux.Handle("POST /api/v1/system-users",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPISystemUserCreate))))
	mux.Handle("POST /api/v1/system-users/{name}/locked",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPISystemUserLocked))))
	mux.Handle("POST /api/v1/system-users/{name}/delete",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPISystemUserDelete))))
	mux.Handle("POST /api/v1/system-users/{name}/keys",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPISSHKeyAdd))))
	mux.Handle("POST /api/v1/system-users/{name}/keys/remove",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPISSHKeyRemove))))
	// Panel-Zugänge. Die Konten DIESER Oberfläche, nicht die des Wirtsystems.
	// Sämtliche Routen — auch die lesende — liegen hinter apiOwner: Wer keine Konten
	// verwalten darf, soll die Kontenliste auch nicht einsehen. apiOwner steht vor
	// apiSchreibend, damit der Grund der richtige ist.
	mux.Handle("GET /api/v1/panel-users",
		s.protected(s.apiOwner(http.HandlerFunc(s.handleAPIPanelUsers))))
	mux.Handle("POST /api/v1/panel-users",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIPanelUserCreate)))))
	mux.Handle("POST /api/v1/panel-users/{id}/disabled",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIPanelUserDisabled)))))
	mux.Handle("POST /api/v1/panel-users/{id}/delete",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIPanelUserDelete)))))
	mux.Handle("POST /api/v1/panel-users/{id}/reset-password",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIPanelUserResetPassword)))))
	mux.Handle("POST /api/v1/panel-users/{id}/reset-2fa",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIPanelUserReset2FA)))))
	mux.Handle("POST /api/v1/panel-users/{id}/reset-passkeys",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIPanelUserResetPasskeys)))))
	// Selbstupdate und Rückweg. Der Stand wird im Sekundentakt gefragt — auch
	// während der Dienst neu startet —, deshalb ein eigener, kleiner Endpunkt und
	// kein Ereignisstrom: Ein offener Kanal übersteht den Neustart nicht.
	// Auslösen darf nur die Owner-Rolle; die Prüfung ändert nichts und steht allen
	// schreibberechtigten Rollen offen.
	mux.Handle("GET /api/v1/update", s.protected(http.HandlerFunc(s.handleAPIUpdate)))
	mux.Handle("GET /api/v1/update/status", s.protected(http.HandlerFunc(s.handleAPIUpdateStand)))
	mux.Handle("POST /api/v1/update/check",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIUpdatePruefen))))
	mux.Handle("POST /api/v1/update/apply",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIUpdateEinspielen)))))
	mux.Handle("POST /api/v1/update/rollback",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIUpdateRueckweg)))))
	// Zertifikat und ACME. Kein eigener Ereignisstrom: Der Bezug ist ein Vorgang
	// und läuft über /api/v1/jobs/certificate/events wie die anderen.
	mux.Handle("GET /api/v1/certificate", s.protected(http.HandlerFunc(s.handleAPIZertifikat)))
	mux.Handle("POST /api/v1/certificate",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIZertifikatSpeichern))))
	mux.Handle("POST /api/v1/certificate/obtain",
		s.protected(s.apiSchreibend(http.HandlerFunc(s.handleAPIZertifikatBezug))))
	// API-Tokens. Owner-Rolle, und zwar auch lesend: Die Liste sagt, welche
	// Zugänge zu diesem Server bestehen, und wer Tokens vergeben kann, vergibt
	// Zugänge. Diese Fläche ist außerdem für Tokens selbst gesperrt — ein
	// entwendeter Token darf keinen frischen minten und so seinen eigenen
	// Widerruf überleben (tokenGesperrt in tokenauth.go).
	mux.Handle("GET /api/v1/tokens",
		s.protected(s.apiOwner(http.HandlerFunc(s.handleAPITokens))))
	mux.Handle("POST /api/v1/tokens",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPITokenAnlegen)))))
	mux.Handle("POST /api/v1/tokens/{id}/revoke",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPITokenWiderrufen)))))

	// Zeitpläne: Cron-Einträge und systemd-Timer. Lesen genügt das Leserecht — wer
	// wissen darf, welche Dienste laufen, darf wissen, was nachts läuft. Schreiben
	// verlangt die Owner-Rolle: Ein Cron-Eintrag IST eine Shell-Zeile, und wer
	// einen anlegen darf, führt Code als den eingetragenen Benutzer aus. Die
	// Begründung steht im Kopf von api_v1_zeitplaene.go.
	mux.Handle("GET /api/v1/schedules", s.protected(http.HandlerFunc(s.handleAPIZeitplaene)))
	mux.Handle("GET /api/v1/schedules/timers/{unit}/run",
		s.protected(http.HandlerFunc(s.handleAPITimerLauf)))
	mux.Handle("POST /api/v1/schedules/cron",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPICronSpeichern)))))
	mux.Handle("POST /api/v1/schedules/cron/{name}/delete",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPICronLoeschen)))))

	// Docker. Lesen genügt das Leserecht — wer sehen darf, welche Dienste laufen,
	// darf sehen, welche Container laufen. Schreiben verlangt die Owner-Rolle,
	// und zwar schärfer begründet als bei den Zeitplänen: Wer den Docker-Socket
	// hat, hat die Maschine. Ein Container mit „-v /:/host" ist root auf dem
	// Wirt, ohne Umweg über eine Lücke. Die Begründung steht im Kopf von
	// api_v1_docker.go und ausführlich in docs/17-docker.md.
	mux.Handle("GET /api/v1/docker", s.protected(http.HandlerFunc(s.handleAPIDocker)))
	mux.Handle("POST /api/v1/docker/install",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerInstall)))))
	mux.Handle("GET /api/v1/docker/containers",
		s.protected(http.HandlerFunc(s.handleAPIDockerContainers)))
	mux.Handle("GET /api/v1/docker/containers/{id}",
		s.protected(http.HandlerFunc(s.handleAPIDockerContainer)))
	// Der Strom ist lesend und liegt deshalb nicht hinter apiSchreibend. Seine
	// Schranke ist eine andere: höchstens vier gleichzeitig, weil jeder einen
	// eigenen docker-Prozess hält (maxDockerLogFolger).
	mux.Handle("GET /api/v1/docker/containers/{id}/logs",
		s.protected(http.HandlerFunc(s.handleAPIDockerContainerLogs)))
	mux.Handle("POST /api/v1/docker/containers/{id}",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerContainerAktion)))))
	mux.Handle("GET /api/v1/docker/bestand",
		s.protected(http.HandlerFunc(s.handleAPIDockerBestand)))
	mux.Handle("POST /api/v1/docker/images/{id}/remove",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerImageEntfernen)))))
	mux.Handle("POST /api/v1/docker/volumes/{name}/remove",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerVolumeEntfernen)))))
	mux.Handle("POST /api/v1/docker/networks/{id}/remove",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerNetzEntfernen)))))
	mux.Handle("POST /api/v1/docker/prune",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerPrune)))))
	// Stacks, lesend. Der Name im Pfad wird nie zu einem Pfad auf der Platte —
	// wo die Compose-Datei liegt, sagt Docker oder das verwaltete Verzeichnis.
	// Die Begründung steht im Kopf von internal/privops/compose.go.
	mux.Handle("GET /api/v1/docker/stacks",
		s.protected(http.HandlerFunc(s.handleAPIDockerStacks)))
	mux.Handle("GET /api/v1/docker/stacks/{name}",
		s.protected(http.HandlerFunc(s.handleAPIDockerStack)))
	// Schreibend. Anlegen und Speichern stehen getrennt, weil sie verschiedene
	// Fehler haben: Anlegen kann an einem schon vergebenen Namen scheitern,
	// Speichern an einer Datei, die dem Panel nicht gehört. Ein Endpunkt für
	// beides beantwortete beide Fälle mit derselben Meldung.
	mux.Handle("POST /api/v1/docker/stacks",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerStackAnlegen)))))
	mux.Handle("PUT /api/v1/docker/stacks/{name}",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerStackSpeichern)))))
	mux.Handle("POST /api/v1/docker/stacks/{name}",
		s.protected(s.apiOwner(s.apiSchreibend(http.HandlerFunc(s.handleAPIDockerStackAktion)))))
	// Ports und Ereignisse. Beides lesend — der Ereignisstrom hat dieselbe
	// Schranke wie das Containerprotokoll: höchstens vier gleichzeitig, weil
	// jeder einen eigenen docker-Prozess hält.
	mux.Handle("GET /api/v1/docker/ports",
		s.protected(http.HandlerFunc(s.handleAPIDockerPorts)))
	mux.Handle("GET /api/v1/docker/events",
		s.protected(http.HandlerFunc(s.handleAPIDockerEvents)))

	// Das eigene Konto. KEIN apiSchreibend: Die Rolle „readonly" darf keine
	// Systemzustände ändern, aber jeder darf sein eigenes Passwort wechseln — sonst
	// bliebe ein Konto mit Leserecht auf dem Einmalpasswort sitzen, mit dem es
	// angelegt wurde. Das CSRF-Token prüft apiEigenerZugriff, die zweite Schranke
	// ist das aktuelle Passwort.
	mux.Handle("GET /api/v1/account", s.protected(http.HandlerFunc(s.handleAPIKonto)))
	mux.Handle("GET /api/v1/account/2fa/qr.png",
		s.protected(http.HandlerFunc(s.handleAPIKontoZweiterFaktorQR)))
	mux.Handle("POST /api/v1/account/password",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIKontoPasswort))))
	mux.Handle("POST /api/v1/account/recovery-codes",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIKontoCodes))))
	mux.Handle("POST /api/v1/account/2fa",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIKontoZweiterFaktorStart))))
	mux.Handle("POST /api/v1/account/2fa/confirm",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIKontoZweiterFaktorConfirm))))
	mux.Handle("POST /api/v1/account/2fa/cancel",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIKontoZweiterFaktorAbbruch))))
	mux.Handle("POST /api/v1/account/sessions/revoke",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIKontoSitzungBeenden))))
	mux.Handle("POST /api/v1/account/sessions/revoke-others",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIKontoSitzungenBeenden))))
	mux.Handle("POST /api/v1/account/passkeys/register/begin",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIPasskeyBegin))))
	mux.Handle("POST /api/v1/account/passkeys/register/finish",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIPasskeyFinish))))
	mux.Handle("POST /api/v1/account/passkeys/{id}/rename",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIPasskeyRename))))
	mux.Handle("POST /api/v1/account/passkeys/{id}/delete",
		s.protected(s.apiEigenerZugriff(http.HandlerFunc(s.handleAPIPasskeyDelete))))
	if static, err := ui.Static(); err == nil {
		fileServer := http.FileServer(http.FS(static))
		mux.Handle("GET /static/", cacheStatic(http.StripPrefix("/static/", fileServer)))
	} else {
		s.log.Error("statische Dateien nicht verfügbar", "err", err)
	}

	// loadToken steht VOR loadSession: Trägt die Anfrage einen Bearer-Token, wird
	// das Cookie gar nicht erst gelesen — eine Anfrage hat einen Anmeldeweg und
	// nicht zwei. Ein unbrauchbarer Authorization-Kopf endet dort mit 401 und
	// fällt nicht auf das Cookie zurück; die Begründung steht im Kopf von
	// tokenauth.go und ist die tragende Stelle des ganzen Token-Wegs.
	return s.recoverer(securityHeaders(s.requestLog(s.loadToken(s.loadSession(mux)))))
}

// loggedIn verlangt eine Sitzung, aber noch kein abgeschlossenes 2FA-Setup.
func (s *Server) loggedIn(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if _, ok := userFrom(r.Context()); !ok {
			s.redirectToLogin(w, r)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// protected verlangt eine vollständig eingerichtete Anmeldung.
func (s *Server) protected(next http.Handler) http.Handler {
	return s.requireAuth(next)
}

type healthResponse struct {
	Status        string `json:"status"`
	Version       string `json:"version"`
	UptimeSeconds int64  `json:"uptime_seconds"`
}

// handleHealth ist der Endpunkt, auf den Installer und Update-Vorgang warten.
// Er darf niemals von optionalen Komponenten abhängen — er beantwortet
// ausschließlich die Frage, ob der Prozess Anfragen bedient, und verlangt
// deshalb bewusst keine Anmeldung.
func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	resp := healthResponse{
		Status:        "ok",
		Version:       version.Version,
		UptimeSeconds: int64(time.Since(s.started).Seconds()),
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(resp)
	_ = r
}

// handleEvents liefert die Live-Metriken als Server-Sent Events.
//
// SSE statt WebSocket: Der Datenfluss geht nur in eine Richtung, das Protokoll
// ist einfaches HTTP, und Browser bauen die Verbindung von selbst wieder auf.
func (s *Server) handleEvents(w http.ResponseWriter, r *http.Request) {
	// ResponseController statt einer Typzusicherung auf http.Flusher: Der
	// Writer ist von der Middleware umhüllt, und der Controller folgt der
	// Unwrap-Kette bis zum echten Writer. Eine Zusicherung auf die Hülle
	// schlägt fehl — genau daran ist dieser Endpunkt schon einmal gescheitert.
	rc := http.NewResponseController(w)

	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Connection", "keep-alive")
	// Falls doch einmal ein Proxy davor steht: nicht puffern.
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)

	if err := rc.Flush(); err != nil {
		s.log.Error("streaming nicht verfügbar", "err", err)
		return
	}

	// Sofort den letzten bekannten Stand senden, damit die Seite nicht bis zum
	// nächsten Takt leer bleibt.
	if snap, ok := s.ring.Last(); ok {
		writeEvent(w, rc, snap)
	}

	ch := s.hub.subscribe()
	defer s.hub.unsubscribe(ch)

	ctx := r.Context()
	for {
		select {
		case <-ctx.Done():
			return
		case snap, ok := <-ch:
			if !ok {
				return
			}
			if !writeEvent(w, rc, snap) {
				return
			}
		}
	}
}

// writeEvent schickt einen Metrik-Schnappschuss. Die Nutzlast ist bereits
// JSON und wird unverändert geschrieben — kompaktes JSON enthält keine
// Zeilenumbrüche, die das Ereignis vorzeitig beenden könnten.
func writeEvent(w http.ResponseWriter, rc *http.ResponseController, snap metrics.Snapshot) bool {
	payload, err := json.Marshal(snap)
	if err != nil {
		return false
	}
	if _, err := fmt.Fprintf(w, "event: metrics\ndata: %s\n\n", payload); err != nil {
		return false
	}
	return rc.Flush() == nil
}

// writeSSE schickt eine Textzeile, etwa aus der Ausgabe eines laufenden
// Kommandos. Der Text wird als JSON-Zeichenkette kodiert: Ein Zeilenumbruch
// oder ein Steuerzeichen würde das Ereignis sonst mitten im Datenfeld beenden.
// Der Empfänger parst die Zeile entsprechend mit JSON.parse.
func writeSSE(w http.ResponseWriter, rc *http.ResponseController, event, text string) bool {
	encoded, err := json.Marshal(text)
	if err != nil {
		return false
	}
	if _, err := fmt.Fprintf(w, "event: %s\ndata: %s\n\n", event, encoded); err != nil {
		return false
	}
	return rc.Flush() == nil
}

// rateLimited bremst Anmelde- und Setup-Versuche je Quell-IP.
func (s *Server) rateLimited(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		key := "ip:" + clientIP(r)
		if allowed, retryAfter := s.limiter.Allowed(key); !allowed {
			w.Header().Set("Retry-After", formatSeconds(retryAfter))
			s.renderError(w, r, http.StatusTooManyRequests,
				"Zu viele Fehlversuche. Bitte in "+retryAfter.String()+" erneut versuchen.")
			return
		}
		next.ServeHTTP(w, r)
	})
}

func cacheStatic(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Kurz genug, dass ein Update nicht tagelang alte Assets ausliefert.
		w.Header().Set("Cache-Control", "public, max-age=300")
		next.ServeHTTP(w, r)
	})
}

// audit schreibt einen Audit-Eintrag und protokolliert Fehler dabei, statt sie
// zu verschlucken — ein stilles Audit-Log ist schlimmer als keines.
func (s *Server) audit(r *http.Request, action, target, result, detail string) {
	actor := "anonym"
	if u, ok := userFrom(r.Context()); ok {
		actor = u.Username
	}
	// Kam die Anfrage über einen API-Token, steht das im Eintrag. Sonst sagt das
	// Protokoll „philipp hat den Dienst gestoppt", während es ein Skript war —
	// und wer nach dem Grund sucht, fragt den falschen Menschen.
	//
	// Der Token wandert in die Einzelheiten und NICHT in das Akteursfeld: Daran
	// hängen die Filter der Protokollseite, und „philipp" und
	// „philipp (Token: Sicherung)" wären dort zwei Akteure für ein Konto.
	if tok, ok := tokenFrom(r.Context()); ok {
		if detail != "" {
			detail = "token=" + tok.Name + " " + detail
		} else {
			detail = "token=" + tok.Name
		}
	}
	entry := store.AuditEntry{
		At: time.Now(), Actor: actor, Action: action,
		Target: target, Result: result, IP: clientIP(r), Detail: detail,
	}
	// Eigener Kontext: Der Eintrag soll auch dann noch geschrieben werden,
	// wenn der Client die Verbindung bereits getrennt hat.
	if err := s.db.AppendAudit(context.Background(), entry); err != nil {
		s.log.Error("audit-eintrag", "action", action, "err", err)
	}
}

// renderPage rendert ein Template in einen Puffer und schreibt es erst danach.
func (s *Server) renderPage(w http.ResponseWriter, r *http.Request, status int, name string, data any) {
	var buf bytes.Buffer
	if err := s.tmpl.ExecuteTemplate(&buf, name, data); err != nil {
		s.log.Error("template", "name", name, "err", err)
		http.Error(w, "interner Fehler", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_, _ = buf.WriteTo(w)
	_ = r
}

func (s *Server) renderError(w http.ResponseWriter, r *http.Request, status int, message string) {
	s.renderPage(w, r, status, "error", s.base(r, "Fehler").with(errorPage{Message: message}))
}

func formatSeconds(d time.Duration) string {
	secs := int(d.Seconds())
	if secs < 1 {
		secs = 1
	}
	return strconv.Itoa(secs)
}
