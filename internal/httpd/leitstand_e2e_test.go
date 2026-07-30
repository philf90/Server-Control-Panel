package httpd

import (
	"encoding/json"
	"math"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// ergebnisLeitstand ist die Ausgabe des Browsertreibers.
type ergebnisLeitstand struct {
	Verstoesse []string `json:"verstoesse"`
	Fehler     []string `json:"fehler"`
	Fehlend    []string `json:"fehlend"`
	Montiert   struct {
		Kinder       int    `json:"kinder"`
		Kacheln      int    `json:"kacheln"`
		Schale       int    `json:"schale"`
		Statusband   int    `json:"statusband"`
		Seitenleiste int    `json:"seitenleiste"`
		Protokoll    int    `json:"protokoll"`
		KartenFarbe  string `json:"kartenFarbe"`
	} `json:"montiert"`
	Uebersicht struct {
		UrteilText        string `json:"urteilText"`
		UrteilUnbekannt   bool   `json:"urteilUnbekannt"`
		Punkte            int    `json:"punkte"`
		PunkteMitGriff    int    `json:"punkteMitGriff"`
		Tabellen          int    `json:"tabellen"`
		DateisystemZeilen int    `json:"dateisystemZeilen"`
	} `json:"uebersicht"`
	TitelSitz []struct {
		Name          string `json:"name"`
		Gefunden      bool   `json:"gefunden"`
		GleicheKante  bool   `json:"gleicheKante"`
		TitelDarueber bool   `json:"titelDarueber"`
	} `json:"titelSitz"`
	RahmenSitz []struct {
		InhaltBreite float64 `json:"inhaltBreite"`
		RahmenBreite float64 `json:"rahmenBreite"`
		Scrollbar    string  `json:"scrollbar"`
	} `json:"rahmenSitz"`
	Palette struct {
		Schritte          []string `json:"schritte"`
		FokusImFeld       bool     `json:"fokusImFeld"`
		ZieleGesamt       int      `json:"zieleGesamt"`
		ZieleInLeiste     int      `json:"zieleInLeiste"`
		TrefferNginx      []string `json:"trefferNginx"`
		TrefferOhneUmlaut []string `json:"trefferOhneUmlaut"`
		LeerZustand       string   `json:"leerZustand"`
		NachEscape        bool     `json:"nachEscape"`
		ZweiteGewaehlt    bool     `json:"zweiteGewaehlt"`
		KlickInnenHaelt   bool     `json:"klickInnenHaelt"`
		KlickDaneben      bool     `json:"klickDanebenSchliesst"`
	} `json:"palette"`
	Dienste struct {
		OhneNeuladen bool   `json:"ohneNeuladen"`
		Pfad         string `json:"pfad"`
		NavAktiv     string `json:"navAktiv"`
		Reihen       []struct {
			Name    string `json:"name"`
			Zustand string `json:"zustand"`
		} `json:"reihen"`
		NachKlick struct {
			Suche string `json:"suche"`
			Titel string `json:"titel"`
			Paare int    `json:"paare"`
		} `json:"nachKlick"`
		NachZurueck struct {
			Inspektor bool   `json:"inspektor"`
			Pfad      string `json:"pfad"`
			Suche     string `json:"suche"`
		} `json:"nachZurueck"`
		NachNeuladen   string   `json:"nachNeuladen"`
		Gefiltert      []string `json:"gefiltert"`
		NurGescheitert []string `json:"nurGescheitert"`
		Rueckfrage     struct {
			Frage          string `json:"frage"`
			Punkte         int    `json:"punkte"`
			FokusAufGefahr bool   `json:"fokusAufGefahr"`
			Tippfeld       bool   `json:"tippfeld"`
		} `json:"rueckfrage"`
		NachAbbruch      bool `json:"nachAbbruch"`
		NachBestaetigung struct {
			DialogZu bool   `json:"dialogZu"`
			Meldung  string `json:"meldung"`
		} `json:"nachBestaetigung"`
		EscapeSchliesst bool `json:"escapeSchliesst"`
		Schmal          struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
			InspektorOben bool    `json:"inspektorOben"`
		} `json:"schmal"`
	} `json:"dienste"`
	Pakete struct {
		Reihen []struct {
			Name string `json:"name"`
			Art  string `json:"art"`
		} `json:"reihen"`
		Neustart struct {
			Da    bool   `json:"da"`
			Text  string `json:"text"`
			Knopf bool   `json:"knopf"`
		} `json:"neustart"`
		Vorgang struct {
			Titel   string   `json:"titel"`
			Zustand string   `json:"zustand"`
			Zeilen  []string `json:"zeilen"`
			Kopf    string   `json:"kopf"`
		} `json:"vorgang"`
		StromGeoeffnet bool `json:"stromGeoeffnet"`
		NachNeuladen   struct {
			Zeilen  int    `json:"zeilen"`
			Zustand string `json:"zustand"`
		} `json:"nachNeuladen"`
		Rueckfrage struct {
			Frage    string `json:"frage"`
			Punkte   int    `json:"punkte"`
			Tippfeld bool   `json:"tippfeld"`
		} `json:"rueckfrage"`
		NachAbbruch bool `json:"nachAbbruch"`
		StufeDrei   struct {
			Tippfeld      bool   `json:"tippfeld"`
			Hinweis       string `json:"hinweis"`
			Wort          string `json:"wort"`
			Gesperrt      bool   `json:"gesperrt"`
			FokusImFeld   bool   `json:"fokusImFeld"`
			NachFalschem  bool   `json:"nachFalschem"`
			NachRichtigem bool   `json:"nachRichtigem"`
		} `json:"stufeDrei"`
		Schmal struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
			Beschriftung  string  `json:"beschriftung"`
		} `json:"schmal"`
	} `json:"pakete"`
	Logs struct {
		ZeilenAnfangs    int      `json:"zeilenAnfangs"`
		Spalten          []string `json:"spalten"`
		StromVorherOffen bool     `json:"stromVorherOffen"`
		NachStufenfilter string   `json:"nachStufenfilter"`
		NachSuche        string   `json:"nachSuche"`
		NachZurueck      struct {
			Suche string `json:"suche"`
			Feld  string `json:"feld"`
		} `json:"nachZurueck"`
		StromGeoeffnet    bool   `json:"stromGeoeffnet"`
		ZeileNachgekommen bool   `json:"zeileNachgekommen"`
		Doppelt           int    `json:"doppelt"`
		KnopfText         string `json:"knopfText"`
		NachAnhalten      bool   `json:"nachAnhalten"`
		FolgerNachWechsel bool   `json:"folgerNachWechsel"`
		Schmal            struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
			Beschriftung  string  `json:"beschriftung"`
		} `json:"schmal"`
	} `json:"logs"`
	Firewall struct {
		Zeilen []struct {
			Text      string `json:"text"`
			Vorschlag bool   `json:"vorschlag"`
		} `json:"zeilen"`
		ProbeVorher         bool   `json:"probeVorher"`
		UebernehmenGesperrt bool   `json:"uebernehmenGesperrt"`
		EntwurfHinweis      string `json:"entwurfHinweis"`
		Rueckfrage          struct {
			Frage    string `json:"frage"`
			Punkte   int    `json:"punkte"`
			Tippfeld bool   `json:"tippfeld"`
		} `json:"rueckfrage"`
		Probe struct {
			ErsteZahl     int    `json:"ersteZahl"`
			VorDerTabelle bool   `json:"vorDerTabelle"`
			Text          string `json:"text"`
			LaeuftRunter  bool   `json:"laeuftRunter"`
		} `json:"probe"`
		ProbeNachNeuladen int `json:"probeNachNeuladen"`
		NachBestaetigen   struct {
			Probe   bool   `json:"probe"`
			Meldung string `json:"meldung"`
		} `json:"nachBestaetigen"`
		Schmal struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
		} `json:"schmal"`
	} `json:"firewall"`
	Dateien struct {
		Wurzeln []string `json:"wurzeln"`
		Krumen  []string `json:"krumen"`
		Reihen  []struct {
			Name     string `json:"name"`
			Groesse  string `json:"groesse"`
			Rechte   string `json:"rechte"`
			Gesperrt bool   `json:"gesperrt"`
		} `json:"reihen"`
		NachOrdnerklick struct {
			Pfad   string   `json:"pfad"`
			Krumen []string `json:"krumen"`
			Reihen []string `json:"reihen"`
		} `json:"nachOrdnerklick"`
		NachZurueck struct {
			Pfad   string   `json:"pfad"`
			Reihen []string `json:"reihen"`
		} `json:"nachZurueck"`
		Inspektor struct {
			Titel      string   `json:"titel"`
			Paare      int      `json:"paare"`
			Rechtetext []string `json:"rechtetext"`
			Aktionen   []string `json:"aktionen"`
			DownloadZu string   `json:"downloadZu"`
		} `json:"inspektor"`
		GesperrtInspektor struct {
			Warnung  string   `json:"warnung"`
			Aktionen []string `json:"aktionen"`
		} `json:"gesperrtInspektor"`
		Suche struct {
			Band   string   `json:"band"`
			Reihen []string `json:"reihen"`
			Orte   []string `json:"orte"`
		} `json:"suche"`
		NachSuchende int    `json:"nachSuchende"`
		SortiertNach string `json:"sortiertNach"`
		NachNeuladen string `json:"nachNeuladen"`
		AlteAnsicht  string `json:"alteAnsicht"`
		Schmal       struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
		} `json:"schmal"`
	} `json:"dateien"`
	Schreiben struct {
		WerkstattHier bool `json:"werkstattHier"`
		NachAnlegen   struct {
			Meldung string `json:"meldung"`
			Auswahl string `json:"auswahl"`
			InListe bool   `json:"inListe"`
		} `json:"nachAnlegen"`
		NachUmbenennen struct {
			Meldung  string `json:"meldung"`
			BandOben bool   `json:"bandOben"`
			Titel    string `json:"titel"`
			InListe  bool   `json:"inListe"`
		} `json:"nachUmbenennen"`
		Rechtemaske struct {
			Oktal         string `json:"oktal"`
			Auswahlfelder int    `json:"auswahlfelder"`
			Rekursiv      bool   `json:"rekursiv"`
		} `json:"rechtemaske"`
		RekursivFrage struct {
			Frage    string `json:"frage"`
			Punkte   int    `json:"punkte"`
			Tippfeld bool   `json:"tippfeld"`
		} `json:"rekursivFrage"`
		NachAbbruchRekursiv bool `json:"nachAbbruchRekursiv"`
		LoeschFrage         struct {
			Frage    string `json:"frage"`
			Punkte   int    `json:"punkte"`
			Tippfeld bool   `json:"tippfeld"`
			Hinweis  string `json:"hinweis"`
			Gesperrt bool   `json:"gesperrt"`
		} `json:"loeschFrage"`
		DialogSitz struct {
			Links   float64 `json:"links"`
			Breite  float64 `json:"breite"`
			Fenster float64 `json:"fenster"`
			Mittig  bool    `json:"mittig"`
		} `json:"dialogSitz"`
		NachLoeschAbbruch struct {
			DialogZu bool `json:"dialogZu"`
			NochDa   bool `json:"nochDa"`
		} `json:"nachLoeschAbbruch"`
		NachLoeschen struct {
			Meldung   string `json:"meldung"`
			Inspektor bool   `json:"inspektor"`
			NochDa    bool   `json:"nochDa"`
		} `json:"nachLoeschen"`
		Zielwahl struct {
			Textfelder int      `json:"textfelder"`
			Ordner     []string `json:"ordner"`
			Ziel       string   `json:"ziel"`
			KnopfOffen bool     `json:"knopfOffen"`
		} `json:"zielwahl"`
		NachKopieren struct {
			Meldung          string  `json:"meldung"`
			DialogZu         bool    `json:"dialogZu"`
			OriginalDa       bool    `json:"originalDa"`
			KoerperBreite    float64 `json:"koerperBreite"`
			FensterBreite    float64 `json:"fensterBreite"`
			InspektorRechts  float64 `json:"inspektorRechts"`
			LetzterKnopfDrin bool    `json:"letzterKnopfDrin"`
		} `json:"nachKopieren"`
		WerkstattDraussen  bool     `json:"werkstattDraussen"`
		HandgriffeDraussen []string `json:"handgriffeDraussen"`
	} `json:"schreiben"`
	Editor struct {
		KernVorher     int `json:"kernVorher"`
		KernVorOeffnen int `json:"kernVorOeffnen"`
		KernNachher    int `json:"kernNachher"`
		Aufbau         struct {
			Adresse       string   `json:"adresse"`
			Zeilennummern int      `json:"zeilennummern"`
			Inhalt        string   `json:"inhalt"`
			Sprache       []string `json:"sprache"`
			Rahmen        string   `json:"rahmen"`
			Schrift       string   `json:"schrift"`
			ListeDa       bool     `json:"listeDa"`
			KrumenDa      bool     `json:"krumenDa"`
		} `json:"aufbau"`
		NachTippen    bool `json:"nachTippen"`
		NachSpeichern struct {
			Meldung       string `json:"meldung"`
			Ungespeichert bool   `json:"ungespeichert"`
		} `json:"nachSpeichern"`
		GroesseDanach  string `json:"groesseDanach"`
		FremdSchreiben int    `json:"fremdSchreiben"`
		Konflikt       struct {
			Meldung       string `json:"meldung"`
			FremdKnopf    bool   `json:"fremdKnopf"`
			EigenerTextDa bool   `json:"eigenerTextDa"`
			Knopf         string `json:"knopf"`
		} `json:"konflikt"`
		NachUebernahme struct {
			Meldung     string `json:"meldung"`
			Inhalt      string `json:"inhalt"`
			KonfliktWeg bool   `json:"konfliktWeg"`
		} `json:"nachUebernahme"`
		NachZurueck struct {
			EditorDa   bool   `json:"editorDa"`
			PfadDa     bool   `json:"pfadDa"`
			Bearbeiten string `json:"bearbeiten"`
		} `json:"nachZurueck"`
	} `json:"editor"`
	Audit struct {
		ZeilenAnfangs int      `json:"zeilenAnfangs"`
		Wesen         string   `json:"wesen"`
		Knoepfe       []string `json:"knoepfe"`
		NachFilter    struct {
			Adresse    string   `json:"adresse"`
			Ergebnisse []string `json:"ergebnisse"`
		} `json:"nachFilter"`
		NachNeuladen      string `json:"nachNeuladen"`
		NachZuruecksetzen int    `json:"nachZuruecksetzen"`
		Einzelheiten      struct {
			Paare       int  `json:"paare"`
			Aufgeklappt bool `json:"aufgeklappt"`
		} `json:"einzelheiten"`
		Schmal struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
		} `json:"schmal"`
	} `json:"audit"`
	Konten struct {
		Wesen  string `json:"wesen"`
		Reihen []struct {
			Name string `json:"name"`
			Warn bool   `json:"warn"`
		} `json:"reihen"`
		Filter         []string `json:"filter"`
		RootHandgriffe []string `json:"rootHandgriffe"`
		RootHinweis    bool     `json:"rootHinweis"`
		Philipp        struct {
			Handgriffe     []string `json:"handgriffe"`
			Schluessel     int      `json:"schluessel"`
			Datei          bool     `json:"datei"`
			LetzterHinweis string   `json:"letzterHinweis"`
		} `json:"philipp"`
		LetzterSchluessel struct {
			Frage    string `json:"frage"`
			Tippfeld bool   `json:"tippfeld"`
			Gesperrt bool   `json:"gesperrt"`
		} `json:"letzterSchluessel"`
		NachAbbruch struct {
			DialogZu   bool `json:"dialogZu"`
			Schluessel int  `json:"schluessel"`
		} `json:"nachAbbruch"`
		Anlegen struct {
			Auswahlfelder  int    `json:"auswahlfelder"`
			Hinweis        string `json:"hinweis"`
			Schluesselfeld bool   `json:"schluesselfeld"`
		} `json:"anlegen"`
		Schmal struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
		} `json:"schmal"`
	} `json:"konten"`
	Zugaenge struct {
		Wesen  string `json:"wesen"`
		Reihen []struct {
			Name    string `json:"name"`
			Ich     bool   `json:"ich"`
			Zustand string `json:"zustand"`
		} `json:"reihen"`
		ImMenue bool `json:"imMenue"`
		Eigenes struct {
			Handgriffe int    `json:"handgriffe"`
			Schranke   bool   `json:"schranke"`
			Hinweis    string `json:"hinweis"`
		} `json:"eigenes"`
		Fremdes struct {
			Handgriffe []string `json:"handgriffe"`
			Warum      string   `json:"warum"`
			FeldTyp    string   `json:"feldTyp"`
			Gesperrt   []bool   `json:"gesperrt"`
			Knoepfe    []string `json:"knoepfe"`
		} `json:"fremdes"`
		Sperren struct {
			Frage    string  `json:"frage"`
			Tippfeld bool    `json:"tippfeld"`
			Links    float64 `json:"links"`
			Rechts   float64 `json:"rechts"`
			Oben     float64 `json:"oben"`
		} `json:"sperren"`
		Loeschen struct {
			GesperrtFalsch  *bool `json:"gesperrtFalsch"`
			GesperrtRichtig *bool `json:"gesperrtRichtig"`
		} `json:"loeschen"`
		NachAbbruch struct {
			DialogZu bool `json:"dialogZu"`
			Reihen   int  `json:"reihen"`
		} `json:"nachAbbruch"`
		Einmal struct {
			Wort    string   `json:"wort"`
			Warnung string   `json:"warnung"`
			Knoepfe []string `json:"knoepfe"`
			Links   float64  `json:"links"`
			Rechts  float64  `json:"rechts"`
		} `json:"einmal"`
		NachEscape bool `json:"nachEscape"`
		FeldLeer   bool `json:"feldLeer"`
		Zu         bool `json:"zu"`
		Schmal     struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
		} `json:"schmal"`
	} `json:"zugaenge"`
	Zert struct {
		Wesen                string   `json:"wesen"`
		Kopfzustand          string   `json:"kopfzustand"`
		Angaben              []string `json:"angaben"`
		SelbstsigniertSatz   string   `json:"selbstsigniertSatz"`
		VerwalteteDatei      bool     `json:"verwalteteDatei"`
		SelbstsigniertFelder struct {
			Email   bool `json:"email"`
			Methode bool `json:"methode"`
		} `json:"selbstsigniertFelder"`
		AcmeFelder struct {
			Email    bool `json:"email"`
			Namen    bool `json:"namen"`
			Methode  bool `json:"methode"`
			Anbieter bool `json:"anbieter"`
			Hook     bool `json:"hook"`
			Token    bool `json:"token"`
			Geltend  bool `json:"geltend"`
		} `json:"acmeFelder"`
		HTTP01 bool `json:"http01"`
		Hook   struct {
			Setzen     bool `json:"setzen"`
			Aufraeumen bool `json:"aufraeumen"`
			Token      bool `json:"token"`
		} `json:"hook"`
		Cloudflare struct {
			Token string `json:"token"`
			Hook  bool   `json:"hook"`
			Warum bool   `json:"warum"`
		} `json:"cloudflare"`
		NachSpeichern struct {
			Meldung       string `json:"meldung"`
			Hinweis       string `json:"hinweis"`
			Zwischen      bool   `json:"zwischen"`
			BeziehenOffen bool   `json:"beziehenOffen"`
		} `json:"nachSpeichern"`
		Rueckschritt struct {
			Frage    string   `json:"frage"`
			Punkte   []string `json:"punkte"`
			Tippfeld bool     `json:"tippfeld"`
		} `json:"rueckschritt"`
		NachAbbruch string `json:"nachAbbruch"`
		Schmal      struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
		} `json:"schmal"`
	} `json:"zert"`
	Konto struct {
		Wesen   string   `json:"wesen"`
		Bloecke []string `json:"bloecke"`
		Warum   []struct {
			Titel string `json:"titel"`
			Satz  string `json:"satz"`
		} `json:"warum"`
		Sitzungen []struct {
			Diese bool   `json:"diese"`
			Knopf string `json:"knopf"`
		} `json:"sitzungen"`
		PasskeysAus bool `json:"passkeysAus"`
		Wechsel     struct {
			Hervorgehoben bool   `json:"hervorgehoben"`
			Frist         string `json:"frist"`
			Geheimnis     string `json:"geheimnis"`
			QRPfad        string `json:"qrPfad"`
			QRGeladen     bool   `json:"qrGeladen"`
		} `json:"wechsel"`
		NachNeuladen bool `json:"nachNeuladen"`
		FalscherCode struct {
			Meldung   string `json:"meldung"`
			NochOffen bool   `json:"nochOffen"`
		} `json:"falscherCode"`
		NachAbbruch struct {
			WechselWeg bool   `json:"wechselWeg"`
			Meldung    string `json:"meldung"`
		} `json:"nachAbbruch"`
		CodesFrage string `json:"codesFrage"`
		Codes      struct {
			Anzahl  int     `json:"anzahl"`
			Warnung string  `json:"warnung"`
			Links   float64 `json:"links"`
			Rechts  float64 `json:"rechts"`
		} `json:"codes"`
		CodesNachEscape bool   `json:"codesNachEscape"`
		CodesOffen      string `json:"codesOffen"`
		Schmal          struct {
			KoerperBreite float64 `json:"koerperBreite"`
			FensterBreite float64 `json:"fensterBreite"`
		} `json:"schmal"`
	} `json:"konto"`
	FremdeRolle struct {
		ImMenue     bool   `json:"imMenue"`
		InPalette   int    `json:"inPalette"`
		Satz        string `json:"satz"`
		ErneutKnopf bool   `json:"erneutKnopf"`
	} `json:"fremdeRolle"`
	Bald struct {
		Pfad     string `json:"pfad"`
		Titel    string `json:"titel"`
		Marke    string `json:"marke"`
		Satz     string `json:"satz"`
		Ersatz   string `json:"ersatz"`
		NavAktiv string `json:"navAktiv"`
	} `json:"bald"`
	Zweige struct {
		Vorher  int `json:"vorher"`
		Nachher int `json:"nachher"`
	} `json:"zweige"`
	Schmal struct {
		KoerperBreite float64 `json:"koerperBreite"`
		FensterBreite float64 `json:"fensterBreite"`
		Beschriftung  string  `json:"beschriftung"`
	} `json:"schmal"`
	Strich *struct {
		SVGBreite    float64 `json:"svgBreite"`
		Effekt       string  `json:"effekt"`
		PunktEffekt  string  `json:"punktEffekt"`
		Strichbreite string  `json:"strichbreite"`
	} `json:"strich"`
	Live     bool   `json:"live"`
	Ablesung string `json:"ablesung"`
}

// TestLeitstandBrowser fährt die neue Oberfläche in einem echten Browser.
//
// Vier Fragen, die kein Go-Test beantwortet:
//
//  1. Montiert die Anwendung? Ein Go-Test sieht die Hülle mit einem leeren
//     <div id="app">. Ob Svelte darin etwas erzeugt — oder ob ein
//     Laufzeitfehler im Bundle die Seite leer lässt —, sagt nur der Browser.
//  2. Verwirft die Richtlinie etwas? Das Bundle ist eine externe Datei und ein
//     Modulskript, das Stylesheet ebenso. An genau dieser Stelle ist das
//     Projekt zweimal gescheitert: die Auslastungsbalken in rc.5 und CodeMirror
//     im Dateimanager. Beides sah im Go-Test richtig aus.
//  3. Ist der Strich der Verläufe gleichmäßig? Die Kachel ist neu gebaut, die
//     Falle dieselbe wie in 0.2.0: 100 viewBox-Einheiten werden waagerecht
//     stärker gestreckt als senkrecht.
//  4. Trägt der Live-Kanal? Die Zahl kommt beim Aufbau aus der Schnittstelle
//     und wird danach aus dem SSE-Strom fortgeschrieben.
//  5. Werden Tabellen unter 600 Pixeln zu Karten? Der Seitenkörper darf nicht
//     waagerecht scrollen — die Lektion aus rc.4, gemessen und nicht vermutet.
//  6. Klappen die weiteren Einhängepunkte einer Platte auf?
//
// Bewusst hinter einer Umgebungsvariablen: Der Test braucht Node und Chromium
// und läuft nicht in jeder CI. Aufruf:
//
//	ASYLUM_LEITSTAND_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestLeitstandBrowser -v
func TestLeitstandBrowser(t *testing.T) {
	if os.Getenv("ASYLUM_LEITSTAND_E2E") == "" {
		t.Skip("ohne ASYLUM_LEITSTAND_E2E nichts zu tun (braucht Node und Chromium)")
	}
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}
	node := envOr("ASYLUM_NODE", "node")

	// Der Dateimanager zeigt auf ein Wegwerfverzeichnis, nicht auf "/": Der Test
	// soll das System des Entwicklers nicht anfassen und nicht von seinem Inhalt
	// abhängen. Die Struktur darin ist absichtlich so gewählt, dass sie die drei
	// Fälle trägt, die die Seite unterscheiden muss — ein beschreibbarer Ordner,
	// ein nur lesbarer, und ein gesperrter Eintrag.
	s, dateiWurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)
	fuelleUebersicht(s)

	// Ein ZWEITER Panel-Zugang, und ein zweiter mit einer anderen Rolle. Beide
	// sind für das Modul Panel-Zugänge nötig, und aus zwei verschiedenen Gründen:
	//
	//   * „vertretung" ist das FREMDE Konto. Am eigenen gibt es hier keine
	//     Handgriffe, und ohne ein zweites liefe der Browsertest nur durch eine
	//     Liste mit einer Zeile, an der nichts zu prüfen ist.
	//   * „gehilfe" trägt die Gegenprobe: Der Menüpunkt gehört der Owner-Rolle,
	//     und dass er für andere fehlt, ist nur mit einer zweiten Sitzung zu
	//     sehen.
	addUser(t, s, "vertretung", store.RoleAdmin)
	gehilfe := addUser(t, s, "gehilfe", store.RoleAdmin)
	gehilfeCookie, _ := login(t, s, gehilfe)

	// Das selbstsignierte TLS-Paar. Im Test entsteht es nicht von selbst —
	// EnsurePair läuft nur in Run —, und ohne es zeigte die Zertifikatsseite den
	// Lesefehler statt des Zustands. Ein laufendes Panel hat immer ein Zertifikat;
	// ein Bildschirmfoto ohne eines wäre eine Lüge über die Fläche.
	if _, err := certs.EnsurePair(s.cfg.Server.TLS.Cert, s.cfg.Server.TLS.Key,
		[]string{"panel.example.test"}); err != nil {
		t.Fatal(err)
	}

	// Wiederherstellungscodes für das eigene Konto. Ohne sie stünde auf der
	// Kontoseite „keiner mehr übrig" samt roter Warnung — ein Zustand, den es nach
	// der Erstinstallation nicht gibt, weil dort immer Codes vergeben werden. Ein
	// Bildschirmfoto davon wäre eine Lüge über die Fläche, dieselbe wie bei den
	// SSH-Schlüsseln der Attrappe.
	if _, hashes, err := auth.NewRecoveryCodes(); err != nil {
		t.Fatal(err)
	} else if err := s.db.ReplaceRecoveryCodes(t.Context(), user.ID, hashes); err != nil {
		t.Fatal(err)
	}

	lege(t, filepath.Join(dateiWurzel, "schreibbar", "notizen.txt"), "hallo welt")
	lege(t, filepath.Join(dateiWurzel, "schreibbar", "server.conf"), "port: 8443\n")
	lege(t, filepath.Join(dateiWurzel, "schreibbar", "tief", "gesucht.conf"), "a: 1")
	lege(t, filepath.Join(dateiWurzel, "schluessel.geheim"), "privat")
	if err := os.MkdirAll(filepath.Join(dateiWurzel, "nurlesbar"), 0o755); err != nil {
		t.Fatal(err)
	}

	// Ein ausstehender Neustart, damit die Paketseite ihren Handlungsbedarf zeigt
	// — und damit die dritte Bestätigungsstufe im Browser geprüft werden kann. Es
	// ist die einzige Aktion der neuen Oberfläche, die sie verlangt.
	ops := s.ops.(*fakeOps)
	ops.reboot = privops.RebootState{
		Required: true, Packages: []string{"linux-image-generic"},
	}

	// GENAU EIN SSH-Schlüssel. Das ist der Fall, um den es im Kontenmodul geht:
	// Ihn zu entfernen legt den Zugang still, und die Rückfrage ist dann eine
	// andere als bei „einen von dreien". Mit der Vorgabe der Attrappe (keine
	// Schlüssel) wäre dieser Weg im Browser nie gelaufen.
	ops.keys = []privops.SSHKey{
		{Type: "ssh-ed25519", Comment: "philipp@arbeitsplatz", Fingerprint: "SHA256:MtQrPfe1"},
	}
	// Und die Kontenliste passend dazu. Die Attrappe liefert dieselben Schlüssel
	// für jedes Konto — auf einem echten System zählt SSHKeys aus derselben Datei,
	// die AuthorizedKeys liest. Die Zahlen hier von Hand gleichzuziehen ist kein
	// Schönheitsdienst: Ohne das zeigte die Liste „0 Schlüssel" und der Inspektor
	// daneben einen, und ein Bildschirmfoto davon wäre eine Lüge über die Fläche.
	ops.sysUsers = []privops.SystemUser{
		{Name: "root", UID: 0, Home: "/root", Shell: "/bin/bash", HasShell: true,
			Protected: true, SSHKeys: 1, Groups: []string{"root"}},
		{Name: "philipp", UID: 1000, GID: 1000, Home: "/home/philipp", Shell: "/bin/bash",
			HasShell: true, SSHKeys: 1, Comment: "Betreiber", Groups: []string{"philipp", "sudo"}},
		// Ein Menschenkonto OHNE Schlüssel: Es kommt nicht auf den Server, und
		// genau diese Auffälligkeit soll im Bild stehen.
		{Name: "monteur", UID: 1001, GID: 1001, Home: "/home/monteur", Shell: "/bin/bash",
			HasShell: true, SSHKeys: 0, Comment: "Wartung"},
		{Name: "www-data", UID: 33, System: true, Shell: "/usr/sbin/nologin"},
	}

	// Ein Eintrag, der erst WÄHREND des Verfolgens hereinkommt. Ohne ihn prüfte
	// der Browsertest nur, dass der Strom den Rückblick noch einmal liefert — und
	// das ist keine Verfolgung, sondern eine zweite Momentaufnahme.
	ops.folgeLogs = []privops.LogEntry{
		{At: time.Now(), Unit: "cron.service", Priority: 4, Message: "waehrend-des-verfolgens"},
	}

	// ufw als vorhanden und mit einer Regel für den Panel-Port. Die Vorgabe der
	// Attrappe ist „aktiv, aber nicht installiert" — ein Zustand, den es auf einem
	// echten System nicht gibt, und die Firewallseite zeigt dann zu Recht nur den
	// Knopf zum Einspielen. Ohne den Panel-Port stünde außerdem die Sperre gegen
	// das Aussperren im Weg, und geprüft werden soll hier die Probe.
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: true, Managed: true, Installed: true,
		Rules: []privops.FirewallRule{
			{Port: 8443, Protocol: "tcp", Comment: "Asylum-Panel"},
			{Port: 22, Protocol: "tcp", Comment: "SSH"},
		},
	}

	ts := httptest.NewServer(s.Handler())
	defer ts.Close()

	cmd := exec.Command(node, "testdata/leitstand_e2e.js")
	cmd.Env = append(os.Environ(),
		"ASYLUM_E2E_DATEIWURZEL="+dateiWurzel,
		"ASYLUM_E2E_URL="+ts.URL,
		"ASYLUM_E2E_COOKIE="+cookie.Name+"="+cookie.Value,
		// Die zweite Sitzung für die Gegenprobe mit einer anderen Rolle.
		"ASYLUM_E2E_COOKIE2="+gehilfeCookie.Name+"="+gehilfeCookie.Value,
		"ASYLUM_CHROMIUM="+chromium,
	)
	if p := os.Getenv("ASYLUM_NODE_PATH"); p != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+p)
	}
	if p := os.Getenv("ASYLUM_E2E_SHOTS"); p != "" {
		cmd.Env = append(cmd.Env, "ASYLUM_E2E_SHOTS="+p)
	}

	ausgabe, err := cmd.CombinedOutput()
	t.Logf("Treiber:\n%s", ausgabe)
	if err != nil {
		t.Fatalf("Browsertreiber: %v", err)
	}

	var e ergebnisLeitstand
	letzte := letzteZeile(string(ausgabe))
	if err := json.Unmarshal([]byte(letzte), &e); err != nil {
		t.Fatalf("Ausgabe des Treibers unlesbar: %v — %q", err, letzte)
	}

	// 1. Kein Laufzeitfehler und nichts verworfen.
	if len(e.Fehler) > 0 {
		t.Errorf("die Anwendung hat einen Laufzeitfehler geworfen:\n  %s",
			strings.Join(e.Fehler, "\n  "))
	}
	if len(e.Verstoesse) > 0 {
		t.Errorf("die Content-Security-Policy hat etwas verworfen:\n  %s",
			strings.Join(e.Verstoesse, "\n  "))
	}

	// 2. Die Schale steht vollständig.
	if e.Montiert.Kinder == 0 {
		t.Fatal("#app ist leer — die Anwendung hat nicht montiert")
	}
	if e.Montiert.Kacheln != 4 {
		t.Errorf("%d Telemetrie-Kacheln, erwartet 4", e.Montiert.Kacheln)
	}
	for name, anzahl := range map[string]int{
		"Schale":       e.Montiert.Schale,
		"Statusband":   e.Montiert.Statusband,
		"Seitenleiste": e.Montiert.Seitenleiste,
		"Protokoll":    e.Montiert.Protokoll,
	} {
		if anzahl != 1 {
			t.Errorf("%s ist %d Mal da, erwartet einmal", name, anzahl)
		}
	}
	// Kommt das Stylesheet nicht durch die Richtlinie, ist die Kachel weiß.
	// Ein Go-Test sähe davon nichts, weil das Markup stimmt.
	if farbe := e.Montiert.KartenFarbe; !strings.HasPrefix(farbe, "rgb(19, 22, 27") {
		t.Errorf("die Kachel hat die Farbe %q — das Stylesheet ist nicht angekommen", farbe)
	}

	// 3. Der Strich ist gleichmäßig.
	if e.Strich == nil {
		t.Fatal("kein Verlauf gezeichnet, obwohl der Ring gefüllt ist")
	}
	if e.Strich.SVGBreite < 150 {
		t.Fatalf("die Kachel ist nur %.0f Pixel breit — dann greift die Messung nicht",
			e.Strich.SVGBreite)
	}
	if streckung := e.Strich.SVGBreite / 100; streckung < 1.5 {
		t.Errorf("die Kachel streckt nur um %.1f — die Messung prüft dann nichts", streckung)
	}
	if e.Strich.Effekt != "non-scaling-stroke" {
		t.Errorf("vector-effect der Linie = %q — die Strichstärke wird mit der Breite gestreckt",
			e.Strich.Effekt)
	}
	if e.Strich.PunktEffekt != "non-scaling-stroke" {
		t.Errorf("vector-effect des Endpunkts = %q — er käme als liegende Ellipse heraus",
			e.Strich.PunktEffekt)
	}

	// 4. Der Live-Kanal trägt, und die Ablesung antwortet.
	if !e.Live {
		t.Error("der Live-Kanal wurde nicht offen gemeldet — SSE kommt nicht an")
	}
	if e.Ablesung == "" {
		t.Error("der Zeiger über dem Verlauf zeigt keinen Messwert")
	}

	// 5. Urteil und Handlungsbedarf. Das Test-Doppel führt einen ausgefallenen
	//    Dienst, es muss also ein Punkt erscheinen — und ein gescheiterter
	//    Abruf darf nicht als „alles in Ordnung" durchgehen.
	if e.Uebersicht.UrteilUnbekannt {
		t.Errorf("die Erhebung ist gescheitert: %q", e.Uebersicht.UrteilText)
	}
	if !strings.Contains(e.Uebersicht.UrteilText, "Aufmerksamkeit") {
		t.Errorf("Urteil %q nennt keinen Handlungsbedarf, obwohl ein Dienst ausgefallen ist",
			e.Uebersicht.UrteilText)
	}
	if e.Uebersicht.Punkte == 0 {
		t.Error("die Liste des Handlungsbedarfs ist leer")
	}
	// Grundsatz II: Jede Zahl ist ein Griff. Ein Punkt ohne Weg dorthin ist eine
	// Meldung, die man nur zur Kenntnis nehmen kann.
	if e.Uebersicht.PunkteMitGriff != e.Uebersicht.Punkte {
		t.Errorf("%d von %d Punkten tragen einen Weg zur Behebung",
			e.Uebersicht.PunkteMitGriff, e.Uebersicht.Punkte)
	}
	if e.Uebersicht.Tabellen != 2 {
		t.Errorf("%d Tabellen, erwartet 2 (Dateisysteme und Prozesse)", e.Uebersicht.Tabellen)
	}

	// Jeder Tabellentitel sitzt über seiner Tabelle. Der Fehler, gegen den das
	// geschrieben ist: Zwei Wurzelelemente je Komponente sind im Gitter zwei
	// Zellen — der Titel stand links, die Tabelle rechts. Der DOM-Test war grün,
	// weil beide Elemente da waren.
	if len(e.TitelSitz) == 0 {
		t.Error("kein Tabellentitel gefunden")
	}
	for _, sitz := range e.TitelSitz {
		if !sitz.Gefunden {
			t.Errorf("Titel %q gehört zu keiner Tabelle in derselben Wurzel", sitz.Name)
			continue
		}
		if !sitz.GleicheKante {
			t.Errorf("Titel %q hat nicht die linke Kante seiner Tabelle — er steht daneben, nicht darüber", sitz.Name)
		}
		if !sitz.TitelDarueber {
			t.Errorf("Titel %q sitzt nicht über seiner Tabelle", sitz.Name)
		}
	}

	// Keine Tabelle wird stillschweigend beschnitten. Wenn der Inhalt breiter ist
	// als der Rahmen, muss der Rahmen scrollen — sonst fehlt eine Spalte, ohne
	// dass es jemand merkt. Genau das war der Fall: overflow: hidden am Rahmen.
	for i, r := range e.RahmenSitz {
		if r.InhaltBreite > r.RahmenBreite+1 && r.Scrollbar == "hidden" {
			t.Errorf("Tabelle %d: Inhalt %.0f px in einem Rahmen von %.0f px mit overflow-x: hidden — "+
				"die letzte Spalte ist abgeschnitten, und nichts sagt es",
				i, r.InhaltBreite, r.RahmenBreite)
		}
	}

	// 6. Die weiteren Einhängepunkte klappen auf.
	if e.Zweige.Vorher != 0 {
		t.Errorf("%d Zweigzeilen sind offen, bevor jemand geklickt hat", e.Zweige.Vorher)
	}
	if e.Zweige.Nachher == 0 {
		t.Error("nach dem Klick erscheinen keine weiteren Einhängepunkte")
	}

	// Die Befehlspalette — der offene Punkt aus docs/15-neuordnung.md.
	if len(e.Palette.Schritte) != 2 {
		t.Errorf("die Palette ließ sich nicht auf beiden Wegen öffnen: %v", e.Palette.Schritte)
	}
	if !e.Palette.FokusImFeld {
		t.Error("nach dem Öffnen liegt der Fokus nicht im Suchfeld — man müsste erst hinklicken")
	}
	// Gegen die Seitenleiste und nicht gegen eine feste Zahl: Ein neues Modul
	// erschien sonst in der Leiste, aber nicht in der Suche, und niemandem fiele
	// auf, warum es sich nicht finden lässt. Eine Zahl im Test nachzuziehen wäre
	// kein Nachweis dafür.
	if e.Palette.ZieleInLeiste == 0 {
		t.Error("die Seitenleiste hat keine Ziele — dann sagt der Vergleich mit der Palette nichts")
	} else if e.Palette.ZieleGesamt != e.Palette.ZieleInLeiste {
		t.Errorf("%d Ziele in der Palette, %d in der Seitenleiste — zwei Listen desselben "+
			"Menüs laufen auseinander", e.Palette.ZieleGesamt, e.Palette.ZieleInLeiste)
	}
	// Der Unterschied zwischen einer Suche und einer Liste: ein Wort, das im
	// Namen nicht vorkommt.
	if len(e.Palette.TrefferNginx) == 0 || !strings.Contains(e.Palette.TrefferNginx[0], "Webserver") {
		t.Errorf("die Suche nach \"nginx\" findet den Webserver nicht: %v", e.Palette.TrefferNginx)
	}
	// Wer den Umlaut weglässt, soll finden, was er meint — sonst ist die Suche
	// eine Rechtschreibprüfung.
	if len(e.Palette.TrefferOhneUmlaut) == 0 ||
		!strings.Contains(e.Palette.TrefferOhneUmlaut[0], "bersicht") {
		t.Errorf("die Suche nach \"ubersicht\" ohne Umlaut findet nichts: %v",
			e.Palette.TrefferOhneUmlaut)
	}
	if e.Palette.LeerZustand == "" {
		t.Error("ohne Treffer sagt die Palette nichts — ein leerer Kasten sieht wie ein Fehler aus")
	}
	if !e.Palette.NachEscape {
		t.Error("Escape schließt die Palette nicht")
	}
	if !e.Palette.ZweiteGewaehlt {
		t.Error("der Pfeil nach unten wandert nicht — die Palette ist nur mit der Maus bedienbar")
	}
	// Der Schleier horcht auf Klicks, die Palette liegt darin. Wird das Ziel des
	// Klicks nicht geprüft, schließt jeder Klick ins Suchfeld die Palette wieder.
	if !e.Palette.KlickInnenHaelt {
		t.Error("ein Klick in die Palette schließt sie — man kommt nicht ins Suchfeld")
	}
	if !e.Palette.KlickDaneben {
		t.Error("ein Klick neben die Palette schließt sie nicht — der einzige Ausweg wäre Escape")
	}

	// 6b. Das Modul Dienste — die Form, die die weiteren Module übernehmen.
	d := e.Dienste
	if !d.OhneNeuladen {
		t.Error("der Wechsel zu den Diensten hat die Seite neu geladen — dann fällt " +
			"das Statusband mitsamt Live-Kanal bei jedem Klick weg, und der " +
			"eigentliche Gewinn gegenüber der alten Oberfläche ist verspielt")
	}
	if d.Pfad != "/v2/dienste" {
		t.Errorf("nach dem Klick steht die Adresse auf %q, erwartet /v2/dienste", d.Pfad)
	}
	if !strings.Contains(d.NavAktiv, "Dienste") {
		t.Errorf("die Seitenleiste hebt %q hervor, erwartet Dienste", d.NavAktiv)
	}
	if len(d.Reihen) != 2 {
		t.Errorf("%d Zeilen in der Liste, erwartet 2 (die Attrappe hat zwei Units)", len(d.Reihen))
	} else {
		// Gescheitertes oben — der Grund, warum jemand die Seite öffnet.
		if d.Reihen[0].Name != "nginx" {
			t.Errorf("erste Zeile ist %q, erwartet nginx — gescheitert gehört nach oben",
				d.Reihen[0].Name)
		}
		// Farbe trägt Zustand, aber nie allein: Neben dem Punkt steht ein Wort.
		if d.Reihen[0].Zustand == "" {
			t.Error("die Zustandsspalte trägt kein Wort — dann trägt die Farbe die Aussage allein")
		}
	}
	if !strings.Contains(d.NachKlick.Suche, "unit=nginx.service") {
		t.Errorf("nach dem Klick steht die Auswahl nicht in der Adresse: %q", d.NachKlick.Suche)
	}
	if !strings.Contains(d.NachKlick.Titel, "nginx.service") {
		t.Errorf("der Inspektor trägt den Titel %q, erwartet nginx.service", d.NachKlick.Titel)
	}
	if d.NachKlick.Paare == 0 {
		t.Error("im Inspektor stehen keine Wertepaare — das Detail wurde nicht geladen")
	}
	// Der Zurück-Knopf schließt den Inspektor. Das ist der Grund, warum die
	// erste Auswahl ein Schritt im Verlauf ist und der Wechsel zur nächsten
	// keiner: Sonst müsste man nach zehn angesehenen Diensten zehnmal zurück.
	if d.NachZurueck.Inspektor {
		t.Error("der Zurück-Knopf schließt den Inspektor nicht")
	}
	if d.NachZurueck.Pfad != "/v2/dienste" || d.NachZurueck.Suche != "" {
		t.Errorf("der Zurück-Knopf führt nach %q%q, erwartet /v2/dienste ohne Auswahl — "+
			"er soll den Inspektor schließen und nicht die Seite verlassen",
			d.NachZurueck.Pfad, d.NachZurueck.Suche)
	}
	if !strings.Contains(d.NachNeuladen, "nginx.service") {
		t.Errorf("nach dem Neuladen der tiefen Adresse steht %q im Inspektor — "+
			"dann ist der Verweis nicht teilbar", d.NachNeuladen)
	}
	// Gesucht wird auch in der Beschreibung: "Webserver" steht nicht im
	// Unitnamen nginx.service.
	if len(d.Gefiltert) != 1 || d.Gefiltert[0] != "nginx" {
		t.Errorf("die Suche nach \"Webserver\" ergibt %v, erwartet nur nginx — "+
			"gesucht wird auch in der Beschreibung", d.Gefiltert)
	}
	if len(d.NurGescheitert) != 1 {
		t.Errorf("der Filter auf Gescheiterte zeigt %d Zeilen, erwartet 1", len(d.NurGescheitert))
	}

	// Die Rückfrage vor dem Stoppen. Bis rc.5 waren dreizehn Rückfragen im
	// Projekt so gebaut, dass keine einzige gefragt hat — dieser Block ist der
	// Nachweis, dass die neue Fassung das nicht wiederholt.
	if d.Rueckfrage.Frage == "" {
		t.Error("der Stopp-Knopf hat keine Rückfrage gestellt")
	}
	if d.Rueckfrage.Punkte == 0 {
		t.Error("die Rückfrage nennt keine Folgen — dann befähigt sie zu keiner Entscheidung")
	}
	if d.Rueckfrage.FokusAufGefahr {
		t.Error("der gefährliche Knopf hat den Fokus — Enter zerstört dann sofort, " +
			"und der Dialog ist keine Rückfrage mehr")
	}
	if d.Rueckfrage.Tippfeld {
		t.Error("die Rückfrage verlangt ein getipptes Wort — Stoppen ist laut docs/14 Stufe 2")
	}
	if !d.NachAbbruch {
		t.Error("Escape bricht die Rückfrage nicht ab")
	}
	if !d.NachBestaetigung.DialogZu {
		t.Error("nach der Bestätigung bleibt der Dialog stehen")
	}
	if d.NachBestaetigung.Meldung == "" {
		t.Error("nach der Aktion sagt nichts, dass sie gelaufen ist")
	}
	if !d.EscapeSchliesst {
		t.Error("Escape schließt den Inspektor nicht")
	}

	if d.Schmal.FensterBreite == 0 {
		t.Error("die Dienstseite wurde nicht im Schmalmodus gemessen")
	} else if d.Schmal.KoerperBreite > d.Schmal.FensterBreite+1 {
		t.Errorf("die Dienstseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			d.Schmal.KoerperBreite, d.Schmal.FensterBreite)
	}
	if !d.Schmal.InspektorOben {
		t.Error("schmal steht der Inspektor unter der Liste — wer eine Zeile angeklickt " +
			"hat, müsste erst scrollen, um zu sehen, was er angeklickt hat")
	}

	// 6c. Das Modul Pakete und die Vorgangsplatte — Grundsatz III.
	p := e.Pakete
	if len(p.Reihen) != 2 {
		t.Errorf("%d Zeilen in der Paketliste, erwartet 2", len(p.Reihen))
	} else {
		// Sicherheitsupdates oben, und erkennbar an einem Wort und nicht nur an
		// der Farbe.
		if p.Reihen[0].Name != "libssl3" {
			t.Errorf("erstes Paket ist %q, erwartet libssl3 — Sicherheit gehört nach oben",
				p.Reihen[0].Name)
		}
		if !strings.Contains(p.Reihen[0].Art, "Sicherheit") {
			t.Errorf("die Sicherheitszeile trägt kein Wort dafür: %q", p.Reihen[0].Art)
		}
	}
	if !p.Neustart.Da {
		t.Error("der ausstehende Neustart wird nicht angezeigt — es ist die wichtigste " +
			"Aussage der Seite, weil eingespielte Updates dann noch nicht wirken")
	}
	if !strings.Contains(p.Neustart.Text, "linux-image-generic") {
		t.Errorf("der Hinweis nennt das verlangende Paket nicht: %q", p.Neustart.Text)
	}
	if !p.Neustart.Knopf {
		t.Error("dem Owner wird kein Neustart-Knopf angeboten")
	}

	// Der Vorgang: Die Zeilen kommen über den Ereignisstrom. Das ist der Kern von
	// Grundsatz III — bis 0.3.0 sammelte das Panel die zwanzig Zeilen von
	// apt-get update und verwarf sie.
	if len(p.Vorgang.Zeilen) == 0 {
		t.Error("der Auszug des Vorgangs ist leer — die Zeilen kommen nicht über den Strom an")
	}
	if p.Vorgang.Titel == "" || p.Vorgang.Zustand == "" {
		t.Errorf("die Platte nennt Titel %q und Zustand %q", p.Vorgang.Titel, p.Vorgang.Zustand)
	}
	// Wer den Vorgang angestoßen hat, gehört dazu: Auf einem Server mit mehreren
	// Konten ist das die erste Frage.
	if !strings.Contains(p.Vorgang.Kopf, "philipp") {
		t.Errorf("die Platte nennt nicht, wer den Vorgang angestoßen hat: %q", p.Vorgang.Kopf)
	}
	// Und die Seite hat den Ereignisstrom geöffnet. Das ist nicht am Bild zu
	// erkennen: Die Attrappe ist in Millisekunden fertig, die Zeilen stünden
	// deshalb auch da, wenn die Seite nur die Ressource abgefragt hätte. Bei einem
	// echten apt-Lauf über eine Viertelstunde wäre das der Unterschied zwischen
	// einer Quittung und einem Standbild. Was der Strom überträgt, prüft
	// TestAPIVorgangStromLiefertZeilenUndEnde am Endpunkt selbst.
	if !p.StromGeoeffnet {
		t.Error("die Seite hat den Ereignisstrom des Vorgangs nicht geöffnet — " +
			"die Ausgabe käme dann erst, wenn der Vorgang schon vorbei ist")
	}
	// Nach dem Neuladen ist er wieder da: Der Vorgang liegt auf dem Server, nicht
	// in der Seite.
	if p.NachNeuladen.Zeilen == 0 {
		t.Error("nach dem Neuladen ist der Vorgang verschwunden — er läuft auf dem " +
			"Server weiter, und wer zurückkommt, soll ihn vorfinden")
	}

	if !strings.Contains(p.Rueckfrage.Frage, "2") {
		t.Errorf("die Rückfrage zum Einspielen nennt die Zahl nicht: %q", p.Rueckfrage.Frage)
	}
	if p.Rueckfrage.Punkte == 0 {
		t.Error("die Rückfrage nennt keine Folgen")
	}
	if p.Rueckfrage.Tippfeld {
		t.Error("Updates einspielen verlangt ein getipptes Wort — es ist Stufe 2")
	}
	if !p.NachAbbruch {
		t.Error("Escape bricht die Rückfrage nicht ab")
	}

	// Stufe 3: die Sperre ist der ganze Unterschied zu Stufe 2.
	d3 := p.StufeDrei
	if !d3.Tippfeld {
		t.Fatal("der Neustart verlangt kein getipptes Wort — er ist Stufe 3")
	}
	if d3.Wort == "" || !strings.Contains(d3.Hinweis, d3.Wort) {
		t.Errorf("der Hinweis sagt nicht, was zu tippen ist: %q", d3.Hinweis)
	}
	if !d3.Gesperrt {
		t.Error("der Knopf ist offen, bevor etwas getippt wurde — dann ist die dritte " +
			"Stufe eine Anzeige und keine Sicherung")
	}
	if !d3.FokusImFeld {
		t.Error("der Fokus liegt nicht im Eingabefeld — bei Stufe 3 gehört er dorthin " +
			"und nicht auf den gefährlichen Knopf")
	}
	if !d3.NachFalschem {
		t.Error("ein falsches Wort gibt den Knopf frei")
	}
	if d3.NachRichtigem {
		t.Error("das richtige Wort gibt den Knopf nicht frei — in Großschreibung " +
			"getippt muss es genügen, wie auf dem Server (EqualFold)")
	}

	if p.Schmal.FensterBreite == 0 {
		t.Error("die Paketseite wurde nicht im Schmalmodus gemessen")
	} else if p.Schmal.KoerperBreite > p.Schmal.FensterBreite+1 {
		t.Errorf("die Paketseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			p.Schmal.KoerperBreite, p.Schmal.FensterBreite)
	}
	if p.Schmal.Beschriftung == "" {
		t.Error("die Zellen der Paketliste tragen im Schmalmodus keine Spaltenbeschriftung")
	}

	// 6d. Das Modul Logs — der zweite Strom, und ein anderer.
	l := e.Logs
	if l.ZeilenAnfangs == 0 {
		t.Error("die Logseite zeigt keine Zeilen")
	}
	for _, spalte := range []string{"Zeit", "Stufe", "Unit", "Nachricht"} {
		if !slices.Contains(l.Spalten, spalte) {
			t.Errorf("die Spalte %q fehlt: %v", spalte, l.Spalten)
		}
	}
	// Ohne Umschalten kein Strom: Wer die Seite öffnet, will meist lesen, was war.
	// Ein Journal, das ungefragt einen journalctl-Prozess aufmacht, ist eine
	// Zumutung an den Betrieb — und bei vier Bedienenden mit je zwei Tabs sofort
	// an der Obergrenze.
	if l.StromVorherOffen {
		t.Error("die Seite öffnet den Journalstrom ungefragt — Verfolgen ist ein Schalter")
	}

	// Die Filter stehen in der Adresse, damit ein Verweis auf „nur Fehler"
	// teilbar ist.
	if !strings.Contains(l.NachStufenfilter, "priority=3") {
		t.Errorf("der Stufenfilter steht nicht in der Adresse: %q", l.NachStufenfilter)
	}
	if !strings.Contains(l.NachSuche, "q=publickey") {
		t.Errorf("die Suche steht nicht in der Adresse: %q", l.NachSuche)
	}
	if strings.Contains(l.NachZurueck.Suche, "q=publickey") {
		t.Errorf("der Zurück-Knopf nimmt die Suche nicht zurück: %q", l.NachZurueck.Suche)
	}
	// Das Feld folgt der Adresse. Ohne das stünde nach dem Zurück-Knopf ein
	// Suchbegriff im Feld, nach dem nicht gesucht wird.
	if l.NachZurueck.Feld != "" {
		t.Errorf("das Suchfeld hält %q, obwohl die Adresse die Suche nicht mehr trägt",
			l.NachZurueck.Feld)
	}

	// Der Strom: erst auf Knopfdruck, dann kommen Zeilen nach.
	if !l.StromGeoeffnet {
		t.Error("der Knopf öffnet den Journalstrom nicht")
	}
	if !l.ZeileNachgekommen {
		t.Error("während des Verfolgens kam keine Zeile nach — dann ist es keine " +
			"Verfolgung, sondern eine Momentaufnahme mit Puls")
	}
	// Keine Zeile steht doppelt: Der Strom bringt seinen eigenen Rückblick mit —
	// dieselben letzten N Einträge, die die Abfrage schon geliefert hat. Ohne das
	// Leeren der Liste stand jede Zeile zweimal da, und bei 200 geholten Zeilen sah
	// die Seite nach einem Klick auf „verfolgen" wie 400 Ereignisse aus.
	if l.Doppelt > 0 {
		t.Errorf("%d Zeilen stehen doppelt da — der Rückblick des Stroms wurde an die "+
			"Liste angehängt statt sie zu ersetzen", l.Doppelt)
	}
	if !strings.Contains(l.KnopfText, "anhalten") {
		t.Errorf("der Knopf heißt %q, erwartet \"anhalten\" — ein Schalter, der beim "+
			"Einschalten weiter \"verfolgen\" heißt, sagt nicht, was er tut", l.KnopfText)
	}
	if !l.NachAnhalten {
		t.Error("der Knopf hält den Strom nicht an")
	}
	// Und der Seitenwechsel hält ihn ebenfalls an. Sonst läuft auf dem Server ein
	// journalctl weiter, dem niemand mehr zusieht — und nach vier Wechseln ist die
	// Obergrenze für immer erreicht.
	if !l.FolgerNachWechsel {
		t.Error("nach dem Seitenwechsel zählt der Server noch einen Zuschauer — " +
			"der Strom wurde nicht angehalten")
	}

	if l.Schmal.FensterBreite == 0 {
		t.Error("die Logseite wurde nicht im Schmalmodus gemessen")
	} else if l.Schmal.KoerperBreite > l.Schmal.FensterBreite+1 {
		t.Errorf("die Logseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			l.Schmal.KoerperBreite, l.Schmal.FensterBreite)
	}
	if l.Schmal.Beschriftung == "" {
		t.Error("die Zellen der Logliste tragen im Schmalmodus keine Spaltenbeschriftung")
	}

	// 6e. Das Modul Firewall — Grundsatz VI. Der Kern ist die Probe: Sie muss
	// oben stehen, herunterlaufen und sich beenden lassen.
	f := e.Firewall
	if len(f.Zeilen) == 0 {
		t.Error("die Firewall zeigt keine Regeln")
	}
	// Die Attrappe kennt sshd auf Port 22 und hat dafür eine Regel — der
	// Vorschlag entsteht also nicht. Geprüft wird, dass Vorschläge überhaupt
	// unterscheidbar sind: Ein Vorschlag gilt nicht, er wird angeboten.
	if f.ProbeVorher {
		t.Error("vor jeder Änderung läuft schon eine Probe")
	}
	if !f.UebernehmenGesperrt {
		t.Error("der Knopf zum Übernehmen ist offen, obwohl nichts bearbeitet wurde — " +
			"ein Klick stellte dann den unveränderten Stand ohne Grund auf Probe")
	}
	if f.EntwurfHinweis == "" {
		t.Error("nach dem Bearbeiten fehlt der Hinweis, dass der Entwurf noch nicht gilt")
	}
	if f.Rueckfrage.Punkte == 0 || !strings.Contains(f.Rueckfrage.Frage, "8080") {
		t.Errorf("die Rückfrage nennt nicht, was gilt: %q (%d Punkte)",
			f.Rueckfrage.Frage, f.Rueckfrage.Punkte)
	}
	if f.Rueckfrage.Tippfeld {
		t.Error("Regeln übernehmen verlangt ein getipptes Wort — es ist Stufe 2, " +
			"weil die Probe den Fehler von selbst zurücknimmt")
	}

	// Die Probe selbst.
	if f.Probe.ErsteZahl <= 0 || f.Probe.ErsteZahl > 60 {
		t.Errorf("die Uhr startet bei %d, erwartet zwischen 1 und 60", f.Probe.ErsteZahl)
	}
	if !f.Probe.VorDerTabelle {
		t.Error("die Probe steht nicht über der Tabelle — wer hereinkommt, während " +
			"eine Frist läuft, muss zuerst den Knopf sehen, der sie beendet")
	}
	if f.Probe.Text == "" {
		t.Error("die Probe sagt nicht, was auf Probe steht")
	}
	if !f.Probe.LaeuftRunter {
		t.Error("die Uhr läuft nicht herunter — dann ist sie eine Zahl und keine Frist")
	}
	// Der wichtigste Punkt: Nach einem Neuladen ist die Probe wieder da. Sie ist
	// Zustand des Servers, nicht der Seite.
	if f.ProbeNachNeuladen <= 0 {
		t.Error("nach dem Neuladen ist die Probe verschwunden — dann fände jemand, " +
			"der die Seite neu lädt, den Bestätigungsknopf nicht mehr und die " +
			"Änderung fiele weg, ohne dass er weiß, warum")
	}
	if f.NachBestaetigen.Probe {
		t.Error("nach dem Bestätigen läuft die Probe weiter")
	}
	if f.NachBestaetigen.Meldung == "" {
		t.Error("nach dem Bestätigen sagt nichts, dass die Änderung bleibt")
	}

	if f.Schmal.FensterBreite == 0 {
		t.Error("die Firewallseite wurde nicht im Schmalmodus gemessen")
	} else if f.Schmal.KoerperBreite > f.Schmal.FensterBreite+1 {
		t.Errorf("die Firewallseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			f.Schmal.KoerperBreite, f.Schmal.FensterBreite)
	}

	// 6f. Das Modul Dateien. Der Kern ist die Bewegung: Der Ort steht in der
	// Adresse, jeder Schritt hinein ist ein Schritt im Verlauf, und der
	// Zurück-Knopf führt eine Ebene höher. Das ist eine Aussage über pushState
	// und nicht über die Antwort des Servers — deshalb steht sie hier und nicht
	// in api_dateien_test.go.
	dat := e.Dateien
	if len(dat.Wurzeln) == 0 {
		t.Error("die Dateiseite zeigt keine Bereiche — dann gibt es keinen Einstiegspunkt")
	}
	if len(dat.Krumen) < 2 {
		t.Errorf("der Krumenpfad hat %d Glieder, erwartet mindestens zwei", len(dat.Krumen))
	}
	if len(dat.Reihen) == 0 {
		t.Fatal("die Dateiliste ist leer")
	}
	// Der gesperrte Eintrag steht in der Liste und ist als solcher gekennzeichnet.
	// Ihn zu verstecken hieße, jemanden über den Inhalt seines Servers zu belügen.
	var gesperrtGefunden bool
	for _, r := range dat.Reihen {
		if strings.Contains(r.Name, "schluessel.geheim") {
			gesperrtGefunden = true
			if !r.Gesperrt {
				t.Error("schluessel.geheim ist nicht als gesperrt gekennzeichnet")
			}
		}
	}
	if !gesperrtGefunden {
		t.Error("der gesperrte Eintrag fehlt in der Liste — er soll sichtbar sein")
	}
	if dat.AlteAnsicht == "" || !strings.HasPrefix(dat.AlteAnsicht, "/files?") {
		t.Errorf("der Weg in die alte Ansicht fehlt oder zeigt woandershin: %q", dat.AlteAnsicht)
	}

	// Ein Klick auf einen Ordner geht hinein.
	if !strings.HasSuffix(dat.NachOrdnerklick.Pfad, "/schreibbar") {
		t.Errorf("nach dem Klick auf den Ordner steht %q in der Adresse", dat.NachOrdnerklick.Pfad)
	}
	if !slices.ContainsFunc(dat.NachOrdnerklick.Reihen, func(s string) bool {
		return strings.Contains(s, "notizen.txt")
	}) {
		t.Errorf("der Inhalt des Ordners fehlt: %v", dat.NachOrdnerklick.Reihen)
	}
	if len(dat.NachOrdnerklick.Krumen) <= len(dat.Krumen) {
		t.Errorf("der Krumenpfad ist nicht gewachsen: %v → %v", dat.Krumen, dat.NachOrdnerklick.Krumen)
	}
	// Und der Zurück-Knopf führt eine Ebene höher — nicht aus der Seite heraus.
	if strings.HasSuffix(dat.NachZurueck.Pfad, "/schreibbar") || dat.NachZurueck.Pfad == "" {
		t.Errorf("der Zurück-Knopf führt nicht eine Ebene höher, sondern nach %q — "+
			"dann ist das Hineinwechseln kein Schritt im Verlauf", dat.NachZurueck.Pfad)
	}
	if !slices.ContainsFunc(dat.NachZurueck.Reihen, func(s string) bool {
		return strings.Contains(s, "schreibbar")
	}) {
		t.Errorf("nach dem Zurückgehen steht der Ordner nicht in der Liste: %v", dat.NachZurueck.Reihen)
	}

	// Der Inspektor: Rechte in Worten, und der Download als echter Verweis.
	if dat.Inspektor.Paare < 5 {
		t.Errorf("der Inspektor zeigt %d Angaben, erwartet mindestens fünf", dat.Inspektor.Paare)
	}
	if len(dat.Inspektor.Rechtetext) != 3 {
		t.Errorf("die Rechte in Worten haben %d Zeilen, erwartet drei (Eigentümer, "+
			"Gruppe, alle anderen): %v", len(dat.Inspektor.Rechtetext), dat.Inspektor.Rechtetext)
	}
	// „0644" sagt nur denen etwas, die es ohnehin wissen. Der Satz daneben ist
	// die Auskunft, die eine Entscheidung trägt.
	if !slices.ContainsFunc(dat.Inspektor.Rechtetext, func(s string) bool {
		return strings.Contains(s, "darf")
	}) {
		t.Errorf("die Rechte stehen nicht in Worten: %v", dat.Inspektor.Rechtetext)
	}
	if dat.Inspektor.DownloadZu != "A" {
		t.Errorf("der Download ist ein %q und kein <a> — ein fetch zöge die Datei "+
			"in den Speicher des Tabs statt in den Download-Manager", dat.Inspektor.DownloadZu)
	}

	// Der gesperrte Eintrag: benannt, und ohne Handgriff auf seinen Inhalt.
	if dat.GesperrtInspektor.Warnung == "" {
		t.Error("der Inspektor eines gesperrten Eintrags sagt nicht, warum er gesperrt ist")
	}
	for _, verboten := range []string{"herunterladen", "bearbeiten", "kopieren"} {
		if slices.ContainsFunc(dat.GesperrtInspektor.Aktionen, func(s string) bool {
			return strings.Contains(s, verboten)
		}) {
			t.Errorf("der gesperrte Eintrag bietet %q an: %v — der Knopf ist bereits "+
				"der Fehler, auch wenn der Endpunkt danach 403 antwortet",
				verboten, dat.GesperrtInspektor.Aktionen)
		}
	}

	// Die Suche findet unterhalb — das kann ein Browserfilter nicht.
	if len(dat.Suche.Reihen) != 1 || !strings.Contains(dat.Suche.Reihen[0], "gesucht.conf") {
		t.Errorf("die Suche findet %v, erwartet genau gesucht.conf", dat.Suche.Reihen)
	}
	if len(dat.Suche.Orte) == 0 || !strings.Contains(dat.Suche.Orte[0], "/tief/") {
		t.Errorf("am Treffer steht kein Ort: %v — ein Suchergebnis quer über "+
			"Unterordner wäre ohne ihn eine Sammlung von Namen", dat.Suche.Orte)
	}
	if dat.Suche.Band == "" {
		t.Error("über der Trefferliste steht nicht, dass es eine Suche ist")
	}
	if dat.NachSuchende < 2 {
		t.Errorf("nach dem Beenden der Suche stehen %d Zeilen da, erwartet die Liste zurück",
			dat.NachSuchende)
	}

	// Sortierung in der Adresse: teilbar, und ein Neuladen zeigt dasselbe.
	if dat.SortiertNach != "size" {
		t.Errorf("die Sortierung steht als %q in der Adresse, erwartet size", dat.SortiertNach)
	}
	if !strings.Contains(dat.NachNeuladen, "↑") && !strings.Contains(dat.NachNeuladen, "↓") {
		t.Errorf("nach dem Neuladen fehlt der Pfeil an der Spalte: %q — dann ist die "+
			"Sortierung nicht ablesbar", dat.NachNeuladen)
	}

	if dat.Schmal.FensterBreite == 0 {
		t.Error("die Dateiseite wurde nicht im Schmalmodus gemessen")
	} else if dat.Schmal.KoerperBreite > dat.Schmal.FensterBreite+1 {
		t.Errorf("die Dateiseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			dat.Schmal.KoerperBreite, dat.Schmal.FensterBreite)
	}

	// 6f2. Die Schreibvorgänge im Dateimodul. Der Kern ist die Rückfrage, und
	// geprüft wird nicht, dass ein Dialog erscheint, sondern dass nach dem Abbruch
	// NICHTS geschehen ist. Bis 0.3.0-rc.5 waren dreizehn Rückfragen im Projekt so
	// gebaut, dass keine einzige gefragt hat — und alle sahen richtig aus.
	sch := e.Schreiben

	// Anlegen.
	if sch.NachAnlegen.Meldung == "" {
		t.Error("nach dem Anlegen sagt nichts, dass es geklappt hat")
	}
	if !sch.NachAnlegen.InListe {
		t.Error("der neu angelegte Ordner steht nicht in der Liste — die Liste wurde nicht neu geholt")
	}
	if !strings.HasSuffix(sch.NachAnlegen.Auswahl, "/vom-browser") {
		t.Errorf("der neue Eintrag ist nicht ausgewählt (Auswahl = %q) — wer einen "+
			"Ordner anlegt, will meist gleich hinein", sch.NachAnlegen.Auswahl)
	}

	// Umbenennen: Die Meldung gehört an die Stelle, an der der Knopf war.
	if sch.NachUmbenennen.Meldung == "" {
		t.Error("nach dem Umbenennen steht im Inspektor keine Meldung")
	}
	if sch.NachUmbenennen.BandOben {
		t.Error("die Meldung des Umbenennens steht ÜBER der Liste — sie gehört dorthin, " +
			"wo der Knopf war, sonst wird sie nicht gelesen")
	}
	if !strings.Contains(sch.NachUmbenennen.Titel, "umbenannt") {
		t.Errorf("der Inspektor trägt weiter %q — er folgt dem Eintrag nicht", sch.NachUmbenennen.Titel)
	}
	if !sch.NachUmbenennen.InListe {
		t.Error("der neue Name steht nicht in der Liste")
	}

	// Die Rechtemaske ist vorbelegt und bietet Auswahlfelder statt Freitext.
	if sch.Rechtemaske.Oktal == "" {
		t.Error("die Rechtemaske ist nicht vorbelegt — wer die Rechte ansehen will, " +
			"müsste sie abschreiben")
	}
	if sch.Rechtemaske.Auswahlfelder < 2 {
		t.Errorf("die Maske hat %d Auswahlfelder, erwartet zwei (Eigentümer, Gruppe) — "+
			"Freitext führt zu Tippfehlern, die als „Benutzer gibt es nicht\" zurückkommen",
			sch.Rechtemaske.Auswahlfelder)
	}
	if !sch.Rechtemaske.Rekursiv {
		t.Error("bei einem Ordner fehlt der Schalter für den rekursiven Lauf")
	}

	// Der rekursive Lauf fragt zurück — die Verschärfung gegenüber der alten
	// Oberfläche.
	if sch.RekursivFrage.Frage == "" || sch.RekursivFrage.Punkte < 2 {
		t.Errorf("der rekursive Lauf fragt nicht ausreichend zurück: %q (%d Punkte)",
			sch.RekursivFrage.Frage, sch.RekursivFrage.Punkte)
	}
	if sch.RekursivFrage.Tippfeld {
		t.Error("der rekursive Lauf verlangt ein getipptes Wort — er ist Stufe 2")
	}
	if !sch.NachAbbruchRekursiv {
		t.Error("nach dem Abbruch steht der Dialog noch")
	}

	// Löschen eines Ordners mit Inhalt: Stufe 3, und der Knopf bleibt gesperrt.
	if !sch.LoeschFrage.Tippfeld {
		t.Error("das Löschen eines Ordners mit Inhalt verlangt kein getipptes Wort — " +
			"hinter dem Klick steht dort ein Baum und nicht ein Eintrag")
	}
	if !sch.LoeschFrage.Gesperrt {
		t.Error("der Löschknopf ist offen, bevor das Wort getippt ist")
	}
	// „1 Datei" im Singular ist derselbe Nachweis wie „4132 Dateien": Die Frage
	// nennt, was verschwindet, und nicht nur dass etwas verschwindet.
	if !strings.Contains(sch.LoeschFrage.Frage, "Datei") {
		t.Errorf("die Frage nennt die Zählung nicht: %q", sch.LoeschFrage.Frage)
	}
	// Und sie nennt nicht, was es nicht gibt: „1 Datei, 0 Ordner" ist eine
	// Aufzählung mit einem Posten, der von dem ablenkt, auf den es ankommt.
	if strings.Contains(sch.LoeschFrage.Frage, "0 Ordner") {
		t.Errorf("die Frage zählt einen leeren Posten mit: %q", sch.LoeschFrage.Frage)
	}
	// Der Dialog sitzt in der Mitte. Der Rücksetzer in app.css (`* { margin: 0 }`)
	// nimmt einem modalen <dialog> die Zentrierung, die der Browser über
	// `margin: auto` herstellt — ein Dialog in der linken oberen Ecke funktioniert,
	// sieht aber aus wie ein Fehler.
	if !sch.DialogSitz.Mittig {
		t.Errorf("der Rückfragedialog sitzt bei %.0f Pixeln (Breite %.0f, Fenster %.0f) — "+
			"nicht mittig", sch.DialogSitz.Links, sch.DialogSitz.Breite, sch.DialogSitz.Fenster)
	}

	// Und der Punkt, der zählt: Nach dem Abbruch steht der Ordner noch da.
	if !sch.NachLoeschAbbruch.DialogZu {
		t.Error("Escape schließt den Löschdialog nicht")
	}
	if !sch.NachLoeschAbbruch.NochDa {
		t.Error("nach dem ABBRUCH ist der Ordner weg — die Rückfrage hat nicht gefragt, " +
			"sondern nur gefragt ausgesehen")
	}

	// Nach dem echten Löschen ist er weg und der Inspektor zu.
	if sch.NachLoeschen.NochDa {
		t.Error("nach dem Löschen steht der Ordner noch in der Liste")
	}
	if sch.NachLoeschen.Inspektor {
		t.Error("nach dem Löschen steht der Inspektor noch — er zeigte einen Eintrag, den es nicht gibt")
	}
	if sch.NachLoeschen.Meldung == "" {
		t.Error("nach dem Löschen sagt nichts, dass es geschehen ist")
	}

	// Die Zielauswahl: ein Ordnerbrowser und kein Textfeld.
	if sch.Zielwahl.Textfelder != 0 {
		t.Errorf("die Zielauswahl hat %d Textfelder — ein Tippfehler wurde damit erst "+
			"beim Absenden zu einer Meldung", sch.Zielwahl.Textfelder)
	}
	if len(sch.Zielwahl.Ordner) == 0 {
		t.Error("die Zielauswahl nennt keine Ordner")
	}
	if sch.Zielwahl.Ziel == "" {
		t.Error("die Zielauswahl sagt nicht, welches Ziel gerade gewählt ist")
	}
	if !sch.Zielwahl.KnopfOffen {
		t.Error("der Knopf ist gesperrt, obwohl das Ziel beschreibbar ist")
	}
	if sch.NachKopieren.Meldung == "" {
		t.Error("nach dem Kopieren fehlt die Meldung")
	}
	if !sch.NachKopieren.DialogZu {
		t.Error("nach dem Kopieren steht die Zielauswahl noch")
	}
	if !sch.NachKopieren.OriginalDa {
		t.Error("nach dem Kopieren ist das Original weg — dann war es ein Verschieben")
	}
	// Die Meldung enthält einen Pfad, und ein Pfad ohne Trennstelle hat eine große
	// Mindestbreite. Ohne overflow-wrap wuchs die Spalte der Werkbank über das
	// Fenster hinaus: Der Inspektor wurde rechts abgeschnitten, und die
	// Schaltfläche „löschen" lag außerhalb des Bildes.
	if sch.NachKopieren.FensterBreite == 0 {
		t.Error("die Breite nach dem Kopieren wurde nicht gemessen")
	} else if sch.NachKopieren.KoerperBreite > sch.NachKopieren.FensterBreite+1 {
		t.Errorf("nach dem Kopieren ist die Seite %.0f Pixel breit bei %.0f Pixeln Fenster — "+
			"die Meldung mit dem Pfad sprengt das Gitter",
			sch.NachKopieren.KoerperBreite, sch.NachKopieren.FensterBreite)
	}
	if sch.NachKopieren.InspektorRechts > sch.NachKopieren.FensterBreite+1 {
		t.Errorf("der Inspektor endet bei %.0f Pixeln, das Fenster bei %.0f — er ist rechts abgeschnitten",
			sch.NachKopieren.InspektorRechts, sch.NachKopieren.FensterBreite)
	}
	if !sch.NachKopieren.LetzterKnopfDrin {
		t.Error("die letzte Schaltfläche des Inspektors liegt außerhalb von ihm — " +
			"sie ist da, aber nicht zu sehen, und das ist schlimmer als keine")
	}

	// Die Gegenprobe: Wo nicht geschrieben werden darf, gibt es die Knöpfe nicht.
	if sch.WerkstattDraussen {
		t.Error("in einem nur lesbaren Bereich steht die Werkstatt — jeder Knopf darin " +
			"liefe zuverlässig in ein 403")
	}
	for _, verboten := range []string{"löschen", "umbenennen", "Rechte", "verschieben"} {
		if slices.ContainsFunc(sch.HandgriffeDraussen, func(s string) bool {
			return strings.Contains(s, verboten)
		}) {
			t.Errorf("am gesperrten Eintrag steht %q: %v", verboten, sch.HandgriffeDraussen)
		}
	}

	// Fehlende Antworten. Sie werden im Treiber mitgeschrieben, und bis hierher
	// hat sie niemand ausgewertet — eine Datei, die es nicht gibt, wäre also
	// unbemerkt geblieben. Bewusst ausgenommen sind 409 und 412: Beide sind eine
	// Auskunft, um die ausdrücklich gebeten wurde, und keine fehlende Antwort.
	if len(e.Fehlend) > 0 {
		t.Errorf("Antworten mit Fehlerstatus, die keine Auskunft sind: %v", e.Fehlend)
	}

	// 6f3. Der Editor. Er ist der Prüfstein des Moduls, und zwar an der Stelle, an
	// der dieses Projekt schon zweimal gescheitert ist: Die
	// Content-Security-Policy erlaubt kein Inline-Skript und kein
	// Inline-Stylesheet, und CodeMirror trägt seine Stilregeln zur Laufzeit ein.
	// Ob das durchgeht, sagt kein Go-Test und kein Build — nur der Browser gegen
	// die UNVERÄNDERTE Richtlinie. Verstöße stünden oben in e.Verstoesse; hier
	// wird geprüft, dass das Ergebnis auch ankommt.
	ed := e.Editor

	// Der Brocken kommt NACHGELADEN. Das ist der ganze Zweck der Aufteilung: Ein
	// Panel, das für die Übersicht 350 KiB Editor mitlädt, ist auf einer
	// schlechten Leitung eine Zumutung — für alle, nicht nur für die, die
	// editieren.
	if ed.KernVorOeffnen != 0 {
		t.Errorf("der Editor-Brocken wurde %d mal geholt, bevor jemand ihn geöffnet hat — "+
			"dann ist die Aufteilung wirkungslos", ed.KernVorOeffnen)
	}
	if ed.KernNachher == 0 {
		t.Error("der Editor-Brocken wurde nie geholt — dann läuft CodeMirror aus dem " +
			"Hauptbündel, und die Aufteilung ist nur eine Datei mehr")
	}

	// Läuft CodeMirror? Zeilennummern entstehen erst, wenn es läuft.
	if ed.Aufbau.Zeilennummern == 0 {
		t.Error("der Editor zeigt keine Zeilennummern — CodeMirror läuft nicht")
	}
	if !strings.Contains(ed.Aufbau.Inhalt, "8443") {
		t.Errorf("der Inhalt der Datei steht nicht im Editor: %q", ed.Aufbau.Inhalt)
	}
	// Und der Nachweis, um den es geht: Der Stil ist angekommen. Verwirft die
	// Richtlinie die Regeln, die CodeMirror zur Laufzeit einträgt, fehlt der
	// Rahmen und die Schrift ist nicht Mono.
	if ed.Aufbau.Rahmen == "" || ed.Aufbau.Rahmen == "0px" {
		t.Errorf("der Editor hat keinen Rahmen (%q) — die zur Laufzeit eingetragenen "+
			"Stilregeln sind nicht angekommen. Genau daran ist der Editor der alten "+
			"Oberfläche schon einmal gescheitert.", ed.Aufbau.Rahmen)
	}
	if !strings.Contains(strings.ToLower(ed.Aufbau.Schrift), "mono") {
		t.Errorf("die Schrift des Editors ist %q, erwartet eine Monoschrift — "+
			"das Thema ist nicht angekommen", ed.Aufbau.Schrift)
	}
	// Die Sprache steht als Marke da. server.conf in einem Ordner ohne „nginx" im
	// Namen ist ini — bestimmt vom Server, weil dort der ganze Pfad bekannt ist.
	if !slices.ContainsFunc(ed.Aufbau.Sprache, func(s string) bool { return s == "ini" }) {
		t.Errorf("die Sprache steht nicht am Editor: %v", ed.Aufbau.Sprache)
	}
	// Der Ort bleibt: Der Editor ersetzt die Liste nicht.
	if !ed.Aufbau.ListeDa || !ed.Aufbau.KrumenDa {
		t.Error("mit offenem Editor fehlt die Liste oder der Krumenpfad — dann ist der " +
			"Ort verloren, an dem man ist")
	}
	if !strings.HasSuffix(ed.Aufbau.Adresse, "server.conf") {
		t.Errorf("die bearbeitete Datei steht nicht in der Adresse (%q) — ein Verweis "+
			"darauf wäre nicht teilbar", ed.Aufbau.Adresse)
	}

	// Tippen kennzeichnet, Speichern hebt das Kennzeichen auf.
	if !ed.NachTippen {
		t.Error("nach dem Tippen fehlt das Kennzeichen „ungespeichert\"")
	}
	if ed.NachSpeichern.Meldung == "" {
		t.Error("nach dem Speichern sagt nichts, dass es geklappt hat")
	}
	if ed.NachSpeichern.Ungespeichert {
		t.Error("nach dem Speichern steht weiter „ungespeichert\" — dann weiß niemand, " +
			"ob die Datei auf der Platte der im Editor entspricht")
	}
	if ed.GroesseDanach == "" {
		t.Error("die Liste unter dem Editor zeigt keine Größe — sie wurde nicht neu geholt")
	}

	// Und der Fall, um den es beim Editor eines Panels wirklich geht: zwei
	// Menschen an derselben Datei.
	if ed.FremdSchreiben != http.StatusOK {
		t.Fatalf("das Schreiben von außerhalb ergab %d — der Konflikt konnte nicht "+
			"nachgestellt werden", ed.FremdSchreiben)
	}
	if ed.Konflikt.Meldung == "" {
		t.Error("der Konflikt wird nicht gemeldet — dann wurde die fremde Änderung " +
			"überschrieben, und niemand hat es erfahren")
	}
	if !ed.Konflikt.EigenerTextDa {
		t.Error("nach dem Konflikt ist der eigene Text weg — das ist der Kern der Sache: " +
			"Die eigene Arbeit darf nicht verloren gehen, weil jemand anders gespeichert hat")
	}
	if !ed.Konflikt.FremdKnopf {
		t.Error("der Konflikt bietet nur einen Ausweg an — den fremden Stand zu übernehmen " +
			"ist der zweite, und ohne ihn ist es keine Wahl")
	}
	if !strings.Contains(ed.Konflikt.Knopf, "überschreiben") {
		t.Errorf("der Knopf heißt weiter %q — Überschreiben ist eine andere Handlung als "+
			"Speichern, und er soll es sagen", ed.Konflikt.Knopf)
	}
	if !strings.Contains(ed.NachUebernahme.Inhalt, "von auswaerts") {
		t.Errorf("nach der Übernahme steht nicht der fremde Stand im Editor: %q",
			ed.NachUebernahme.Inhalt)
	}
	if !ed.NachUebernahme.KonfliktWeg {
		t.Error("nach der Übernahme steht die Konfliktmeldung noch")
	}

	// Der Zurück-Knopf schließt den Editor und lässt die Seite stehen.
	if ed.NachZurueck.EditorDa || ed.NachZurueck.Bearbeiten != "" {
		t.Errorf("der Zurück-Knopf schließt den Editor nicht (bearbeiten=%q)",
			ed.NachZurueck.Bearbeiten)
	}
	if !ed.NachZurueck.PfadDa {
		t.Error("der Zurück-Knopf hat auch den Ort verworfen — er soll nur den Editor schließen")
	}

	// 6h. Das Modul Audit. Zwei Dinge sind nur im Browser zu sehen: dass der
	// Filter in der Adresse steht und ein Neuladen ihn vorfindet, und dass es
	// keinen Knopf gibt, der etwas am Protokoll ändert.
	au := e.Audit
	if au.ZeilenAnfangs == 0 {
		t.Error("das Protokoll ist leer — der Browsertest hat sich vorher angemeldet " +
			"und Dutzende Aktionen ausgeführt, es müsste voll sein")
	}
	if au.Wesen == "" {
		t.Error("über der Liste steht nicht, dass das Protokoll nur additiv ist")
	}
	// Kein Knopf verändert etwas. Das ist die Aussage des Moduls.
	for _, verboten := range []string{"löschen", "bearbeiten", "leeren", "entfernen"} {
		if slices.ContainsFunc(au.Knoepfe, func(s string) bool {
			return strings.Contains(strings.ToLower(s), verboten)
		}) {
			t.Errorf("das Auditmodul bietet %q an: %v — das Protokoll ist nur additiv",
				verboten, au.Knoepfe)
		}
	}

	// Der Filter steht in der Adresse und wirkt.
	if au.NachFilter.Adresse != "denied" {
		t.Errorf("der Filter steht als %q in der Adresse, erwartet denied — ein "+
			"Verweis darauf wäre sonst nicht teilbar", au.NachFilter.Adresse)
	}
	if len(au.NachFilter.Ergebnisse) == 0 {
		t.Error("der Filter auf „abgelehnt\" liefert keine Zeile — der Browsertest hat " +
			"vorher Aktionen abgelehnt bekommen (gesperrte Pfade), es müsste welche geben")
	}
	for _, erg := range au.NachFilter.Ergebnisse {
		if !strings.Contains(erg, "abgelehnt") {
			t.Errorf("nach dem Filter steht %q in der Liste, erwartet nur abgelehnte", erg)
		}
	}
	if !strings.Contains(au.NachNeuladen, "abgelehnt") {
		t.Errorf("nach dem Neuladen ist der Filter nicht mehr hervorgehoben (%q) — "+
			"dann ist er Zustand der Seite und nicht der Adresse", au.NachNeuladen)
	}
	if au.NachZuruecksetzen <= len(au.NachFilter.Ergebnisse) {
		t.Errorf("nach dem Zurücksetzen stehen %d Zeilen da, gefiltert waren es %d — "+
			"der Filter wurde nicht gelöst", au.NachZuruecksetzen, len(au.NachFilter.Ergebnisse))
	}

	// Die Einzelheiten klappen auf.
	if !au.Einzelheiten.Aufgeklappt || au.Einzelheiten.Paare < 3 {
		t.Errorf("die Einzelheiten einer Zeile klappen nicht auf (%d Angaben, "+
			"aria-expanded=%v)", au.Einzelheiten.Paare, au.Einzelheiten.Aufgeklappt)
	}

	if au.Schmal.FensterBreite == 0 {
		t.Error("die Auditseite wurde nicht im Schmalmodus gemessen")
	} else if au.Schmal.KoerperBreite > au.Schmal.FensterBreite+1 {
		t.Errorf("die Auditseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			au.Schmal.KoerperBreite, au.Schmal.FensterBreite)
	}

	// 6i. Benutzer & SSH. Der Kern ist die Frage beim LETZTEN Schlüssel und die
	// Gegenprobe an root: Ein geschütztes Konto darf keinen Knopf zeigen, der
	// dann verweigert.
	ko := e.Konten
	if ko.Wesen == "" {
		t.Error("es fehlt der Satz, der Systemkonten von Panel-Zugängen unterscheidet — " +
			"wer das verwechselt, legt ein Konto an, das nichts kann")
	}
	if len(ko.Reihen) == 0 {
		t.Fatal("die Kontenliste ist leer")
	}
	if len(ko.Filter) < 3 {
		t.Errorf("die Zähler sind keine Filter: %v", ko.Filter)
	}

	// root ist geschützt.
	for _, verboten := range []string{"sperren", "löschen", "entsperren"} {
		if slices.ContainsFunc(ko.RootHandgriffe, func(s string) bool {
			return strings.Contains(s, verboten)
		}) {
			t.Errorf("root bietet %q an: %v — die Prüfung in privops greift ohnehin, "+
				"aber ein Knopf, der dann verweigert, ist die schlechteste Antwort",
				verboten, ko.RootHandgriffe)
		}
	}
	if !ko.RootHinweis {
		t.Error("bei root steht nicht, WARUM die Handgriffe fehlen — sie einfach " +
			"weglassen sieht wie ein halb gebautes Modul aus")
	}

	// Ein gewöhnliches Konto: alles da, Schlüssel im Inspektor.
	for _, erwartet := range []string{"sperren", "löschen"} {
		if !slices.ContainsFunc(ko.Philipp.Handgriffe, func(s string) bool {
			return strings.Contains(s, erwartet)
		}) {
			t.Errorf("philipp bietet %q nicht an: %v", erwartet, ko.Philipp.Handgriffe)
		}
	}
	if ko.Philipp.Schluessel == 0 {
		t.Error("die Schlüssel stehen nicht im Inspektor")
	}
	if !ko.Philipp.Datei {
		t.Error("der Ort der Schlüsseldatei fehlt — wer den Zugang verliert, muss " +
			"wissen, wo er von Hand nachsehen kann")
	}
	if ko.Philipp.LetzterHinweis == "" {
		t.Error("bei genau einem Schlüssel fehlt die Anmerkung, BEVOR jemand klickt")
	}

	// Und der Punkt: Der letzte Schlüssel verlangt den Kontonamen.
	if !ko.LetzterSchluessel.Tippfeld {
		t.Error("der letzte Schlüssel lässt sich ohne getipptes Wort entfernen — " +
			"danach hat das Konto keinen Zugang mehr, das ist Stufe 3")
	}
	if !strings.Contains(ko.LetzterSchluessel.Frage, "EINZIGE") {
		t.Errorf("die Frage sagt nicht, dass es der einzige Schlüssel ist: %q",
			ko.LetzterSchluessel.Frage)
	}
	if !ko.LetzterSchluessel.Gesperrt {
		t.Error("der Knopf ist offen, bevor das Wort getippt ist")
	}
	if !ko.NachAbbruch.DialogZu {
		t.Error("Escape schließt den Dialog nicht")
	}
	if ko.NachAbbruch.Schluessel == 0 {
		t.Error("nach dem ABBRUCH ist der Schlüssel weg — die Rückfrage hat nicht " +
			"gefragt, sondern nur gefragt ausgesehen")
	}

	// Die Maske zum Anlegen: Auswahlfelder statt Freitext.
	if ko.Anlegen.Auswahlfelder < 2 {
		t.Errorf("die Maske hat %d Auswahlfelder, erwartet zwei (Schale, Gruppen) — "+
			"Freitext schlägt Werte vor, die der Server ablehnt", ko.Anlegen.Auswahlfelder)
	}
	if !strings.Contains(ko.Anlegen.Hinweis, "Passwort") {
		t.Errorf("die Maske sagt nicht, dass das Konto kein Passwort bekommt: %q",
			ko.Anlegen.Hinweis)
	}
	if !ko.Anlegen.Schluesselfeld {
		t.Error("der Schlüssel lässt sich beim Anlegen nicht mitgeben — dann entsteht " +
			"erst ein Konto, das niemand benutzen kann")
	}

	if ko.Schmal.FensterBreite == 0 {
		t.Error("die Kontenseite wurde nicht im Schmalmodus gemessen")
	} else if ko.Schmal.KoerperBreite > ko.Schmal.FensterBreite+1 {
		t.Errorf("die Kontenseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			ko.Schmal.KoerperBreite, ko.Schmal.FensterBreite)
	}

	// 6j. Panel-Zugänge. Vier Dinge, die nur der Browser zeigt: die eigene Zeile
	// ohne Handgriffe, die offen stehende Schranke vor den Zurücksetzungen, das
	// Einmalpasswort in einem Dialog, den Escape nicht schließt, und dass beide
	// Dialoge in der Mitte sitzen.
	pz := e.Zugaenge
	if pz.Wesen == "" {
		t.Error("es fehlt der Satz, der Panel-Zugänge von Systemkonten unterscheidet")
	}
	if !pz.ImMenue {
		t.Error("der Menüpunkt für die Panel-Zugänge fehlt der Owner-Rolle")
	}
	if len(pz.Reihen) < 2 {
		t.Fatalf("die Zugangsliste hat %d Zeilen, erwartet mindestens zwei — mit einer "+
			"einzigen ist am fremden Konto nichts zu prüfen", len(pz.Reihen))
	}
	eigeneMarkiert := false
	for _, r := range pz.Reihen {
		if r.Name == "philipp" {
			eigeneMarkiert = r.Ich
		}
		if r.Zustand == "" {
			t.Errorf("die Zeile %q hat keinen Zustand", r.Name)
		}
	}
	if !eigeneMarkiert {
		t.Error("die eigene Zeile ist nicht markiert — dann sieht das Fehlen der " +
			"Handgriffe wie eine Panne aus")
	}

	// Das eigene Konto: keine Handgriffe, keine Schranke, aber der Satz, der es
	// erklärt.
	if pz.Eigenes.Handgriffe != 0 {
		t.Errorf("das eigene Konto bietet %d Handgriffe — sperren oder löschen wäre "+
			"ein Selbstausschluss, Passwort und zweiter Faktor stehen auf der Kontoseite",
			pz.Eigenes.Handgriffe)
	}
	if pz.Eigenes.Schranke {
		t.Error("am eigenen Konto steht die Zurücksetzungsschranke — sie führt hier zu nichts")
	}
	if !strings.Contains(pz.Eigenes.Hinweis, "Kontoseite") {
		t.Errorf("am eigenen Konto fehlt der Verweis auf die Kontoseite: %q", pz.Eigenes.Hinweis)
	}

	// Das fremde Konto: alles da, und die Schranke davor sichtbar gesperrt.
	fr := pz.Fremdes
	for _, erwartet := range []string{"sperren", "löschen"} {
		if !slices.Contains(fr.Handgriffe, erwartet) {
			t.Errorf("das fremde Konto bietet %q nicht an: %v", erwartet, fr.Handgriffe)
		}
	}
	if fr.FeldTyp != "password" {
		t.Errorf("das Feld für das eigene Passwort ist vom Typ %q, erwartet password", fr.FeldTyp)
	}
	if !strings.Contains(fr.Warum, "eigenes Passwort") {
		t.Errorf("es steht nicht, WESSEN Passwort gemeint ist: %q", fr.Warum)
	}
	if len(fr.Gesperrt) < 2 {
		t.Errorf("die Schranke hat %d Knöpfe, erwartet mindestens zwei "+
			"(Passwort, zweiter Faktor): %v", len(fr.Gesperrt), fr.Knoepfe)
	}
	for i, gesperrt := range fr.Gesperrt {
		if !gesperrt {
			t.Errorf("der Knopf %q ist offen, obwohl das Passwortfeld leer ist — "+
				"dann kommt die Bedingung als 403 nach dem Klick statt vor ihm",
				fr.Knoepfe[i])
		}
	}

	// Sperren ist Stufe 2, Löschen Stufe 3.
	if pz.Sperren.Tippfeld {
		t.Error("das Sperren verlangt ein getipptes Wort — es ist umkehrbar, Stufe 2 genügt")
	}
	if !strings.Contains(pz.Sperren.Frage, "vertretung") {
		t.Errorf("die Frage nennt das Konto nicht: %q", pz.Sperren.Frage)
	}
	// Die Mitte, gemessen: `* { margin: 0 }` hat margin:auto schon einmal
	// geschlagen, und alle Dialoge klebten oben links. Gesehen hat das erst ein
	// Bildschirmfoto — jetzt fällt es hier auf.
	if math.Abs(pz.Sperren.Links-pz.Sperren.Rechts) > 2 {
		t.Errorf("die Rückfrage sitzt nicht waagerecht in der Mitte: %.0f links, %.0f rechts",
			pz.Sperren.Links, pz.Sperren.Rechts)
	}
	if pz.Sperren.Oben < 10 {
		t.Errorf("die Rückfrage klebt am oberen Rand (%.0f Pixel)", pz.Sperren.Oben)
	}
	if pz.Loeschen.GesperrtFalsch == nil || !*pz.Loeschen.GesperrtFalsch {
		t.Error("beim Löschen ist der Knopf offen, obwohl der Name falsch getippt ist")
	}
	if pz.Loeschen.GesperrtRichtig == nil || *pz.Loeschen.GesperrtRichtig {
		t.Error("beim Löschen bleibt der Knopf gesperrt, obwohl der Name stimmt — " +
			"dann ist die Stufe keine Rückfrage, sondern eine Sperre")
	}
	if !pz.NachAbbruch.DialogZu {
		t.Error("Escape schließt die Rückfrage nicht")
	}
	if pz.NachAbbruch.Reihen < 2 {
		t.Errorf("nach dem ABBRUCH sind es %d Zeilen — die Rückfrage hat nicht gefragt, "+
			"sondern nur gefragt ausgesehen", pz.NachAbbruch.Reihen)
	}

	// Das Einmalpasswort: sichtbar, mit dem Satz, dass es nur einmal da ist, und
	// gegen Escape gesichert.
	if pz.Einmal.Wort == "" {
		t.Error("nach der Zurücksetzung steht kein Einmalpasswort da")
	}
	if !strings.Contains(pz.Einmal.Warnung, "nur hier") {
		t.Errorf("es steht nicht dabei, dass das Passwort nur einmal kommt: %q", pz.Einmal.Warnung)
	}
	if math.Abs(pz.Einmal.Links-pz.Einmal.Rechts) > 2 {
		t.Errorf("der Passwortdialog sitzt nicht in der Mitte: %.0f links, %.0f rechts",
			pz.Einmal.Links, pz.Einmal.Rechts)
	}
	if !pz.NachEscape {
		t.Error("Escape schließt den Passwortdialog — dann ist das Einmalpasswort weg, " +
			"bevor es jemand gelesen hat, und es kommt kein zweites Mal")
	}
	if !pz.FeldLeer {
		t.Error("das Passwortfeld ist nach dem Aufruf noch gefüllt — der nächste Klick " +
			"träfe ein anderes Ziel als der Tippende im Kopf hatte")
	}
	if !pz.Zu {
		t.Error("der Passwortdialog lässt sich über seinen Knopf nicht schließen")
	}
	if pz.Schmal.FensterBreite == 0 {
		t.Error("die Zugangsseite wurde nicht im Schmalmodus gemessen")
	} else if pz.Schmal.KoerperBreite > pz.Schmal.FensterBreite+1 {
		t.Errorf("die Zugangsseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			pz.Schmal.KoerperBreite, pz.Schmal.FensterBreite)
	}

	// 6m. Zertifikat und ACME. Der Kern ist das gestaffelte Formular: Es zeigt nur,
	// was zur getroffenen Wahl passt. Ein Feld, das nichts bewirkt, ist eine
	// Aufforderung, etwas Wirkungsloses einzutragen.
	ze := e.Zert
	if ze.Wesen == "" {
		t.Error("es fehlt der Satz darüber, was das Zertifikat ist")
	}
	if ze.Kopfzustand == "" {
		t.Error("der Zustand des Zertifikats steht nicht in der Kopfzeile")
	}
	for _, feld := range []string{"Herkunft", "Gültig", "Aussteller", "Fingerprint", "Datei"} {
		if !slices.Contains(ze.Angaben, feld) {
			t.Errorf("die Angabe %q fehlt: %v", feld, ze.Angaben)
		}
	}
	if !strings.Contains(ze.SelbstsigniertSatz, "warnt") {
		t.Errorf("bei einem selbstsignierten Zertifikat steht nicht, dass jeder Browser "+
			"warnt: %q", ze.SelbstsigniertSatz)
	}
	if !ze.VerwalteteDatei {
		t.Error("die Datei, in der die Einstellungen landen, wird nicht genannt — " +
			"das Panel versteckt nichts")
	}

	// Gestaffelt: Bei „selbstsigniert" keine ACME-Felder.
	if ze.SelbstsigniertFelder.Email || ze.SelbstsigniertFelder.Methode {
		t.Errorf("bei selbstsigniert stehen ACME-Felder da: %+v", ze.SelbstsigniertFelder)
	}
	// Bei ACME und „automatisch": Adresse, Namen, Methode — aber weder Hook-Pfade
	// noch Token, denn welcher Anbieter gemeint ist, steht noch nicht fest.
	af := ze.AcmeFelder
	if !af.Email || !af.Namen || !af.Methode {
		t.Errorf("bei ACME fehlen Grundfelder: %+v", af)
	}
	if af.Hook || af.Token {
		t.Errorf("bei ACME ohne gewählten Anbieter stehen schon Anbieterfelder da: %+v", af)
	}
	if !af.Geltend {
		t.Error("die aufgelösten Namen fehlen — dann muss man raten, was „leer\" bedeutet")
	}
	if !ze.HTTP01 {
		t.Error("bei HTTP-01 steht ein Anbieterfeld da — es bewirkt dort nichts")
	}
	if !ze.Hook.Setzen || !ze.Hook.Aufraeumen {
		t.Errorf("beim Hook fehlen die zwei Pfadfelder: %+v", ze.Hook)
	}
	if ze.Hook.Token {
		t.Error("beim Hook steht ein Tokenfeld da")
	}
	// Das Token ist ein Passwortfeld: Es soll nicht offen auf dem Schirm stehen.
	if ze.Cloudflare.Token != "password" {
		t.Errorf("das Tokenfeld ist vom Typ %q, erwartet password", ze.Cloudflare.Token)
	}
	if ze.Cloudflare.Hook {
		t.Error("bei Cloudflare stehen die Hook-Pfade da")
	}
	if !ze.Cloudflare.Warum {
		t.Error("es steht nicht dabei, dass das Token in einer eigenen Datei mit 0600 landet")
	}

	// Nach dem Speichern: der Zwischenzustand ist benannt, und beziehen ist offen.
	if !strings.Contains(ze.NachSpeichern.Meldung, "gespeichert") {
		t.Errorf("nach dem Speichern fehlt die Quittung: %q", ze.NachSpeichern.Meldung)
	}
	if !ze.NachSpeichern.Zwischen {
		t.Error("der Zwischenzustand „eingestellt, aber noch nichts bezogen\" ist nicht " +
			"benannt — dann sucht jemand den Fehler an der falschen Stelle")
	}
	if !ze.NachSpeichern.BeziehenOffen {
		t.Error("nach dem Einschalten ist „jetzt beziehen\" noch gesperrt")
	}

	// Der Rückschritt fragt zurück, und nach dem ABBRUCH steht die Einstellung noch.
	if !strings.Contains(ze.Rueckschritt.Frage, "selbstsigniert") {
		t.Errorf("die Frage benennt den Rückschritt nicht: %q", ze.Rueckschritt.Frage)
	}
	warnt := false
	for _, p := range ze.Rueckschritt.Punkte {
		if strings.Contains(p, "warnt") {
			warnt = true
		}
	}
	if !warnt {
		t.Errorf("die Frage sagt nicht, dass danach jeder Browser warnt: %v", ze.Rueckschritt.Punkte)
	}
	if ze.Rueckschritt.Tippfeld {
		t.Error("der Rückschritt verlangt ein getipptes Wort — er ist umkehrbar")
	}
	if !strings.Contains(ze.NachAbbruch, "Let's Encrypt") {
		t.Errorf("nach dem ABBRUCH steht %q eingestellt — die Rückfrage hat nicht "+
			"gefragt, sondern nur gefragt ausgesehen", ze.NachAbbruch)
	}
	if ze.Schmal.FensterBreite == 0 {
		t.Error("die Zertifikatsseite wurde nicht im Schmalmodus gemessen")
	} else if ze.Schmal.KoerperBreite > ze.Schmal.FensterBreite+1 {
		t.Errorf("die Zertifikatsseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — "+
			"sie scrollt waagerecht", ze.Schmal.KoerperBreite, ze.Schmal.FensterBreite)
	}

	// 6l. Das eigene Konto. Die Passkeys haben ihren eigenen Durchlauf mit
	// virtuellem Authenticator (TestPasskeyBrowserV2); hier steht der Rest.
	ek := e.Konto
	if ek.Wesen == "" {
		t.Error("es fehlt der Satz, der das eigene Konto von den Panel-Zugängen unterscheidet")
	}
	// Fünf Blöcke: Überblick (ohne Titel), Passwort, zweiter Faktor, Codes,
	// Passkeys, Sitzungen.
	if len(ek.Bloecke) < 5 {
		t.Errorf("%d benannte Blöcke, erwartet mindestens fünf: %v", len(ek.Bloecke), ek.Bloecke)
	}
	if len(ek.Warum) < 5 {
		t.Errorf("%d benannte Blöcke mit Begründung, erwartet mindestens fünf", len(ek.Warum))
	}
	for _, w := range ek.Warum {
		if w.Satz == "" {
			t.Errorf("der Block %q hat keinen Satz darüber, warum es ihn gibt — Grundsatz V",
				w.Titel)
		}
	}
	if !ek.PasskeysAus {
		t.Error("ohne eingeschaltete Passkeys steht das nicht dabei — dann fehlt der " +
			"Grund, warum der Block leer ist")
	}
	if len(ek.Sitzungen) == 0 {
		t.Fatal("die Sitzungsliste ist leer")
	}
	eigene := 0
	for _, sz := range ek.Sitzungen {
		if sz.Diese {
			eigene++
			// Die eigene Sitzung zu beenden IST ein Abmelden. „beenden" wäre eine
			// Untertreibung darüber, was gleich passiert.
			if sz.Knopf != "abmelden" {
				t.Errorf("der Knopf der eigenen Sitzung heißt %q, erwartet „abmelden\"", sz.Knopf)
			}
		} else if sz.Knopf != "beenden" {
			t.Errorf("der Knopf einer fremden Sitzung heißt %q", sz.Knopf)
		}
	}
	if eigene != 1 {
		t.Errorf("%d Sitzungen sind als die eigene markiert, erwartet genau eine — ohne "+
			"die Markierung beendet man aus Versehen die, in der man sitzt", eigene)
	}

	// Der Wechsel des zweiten Faktors.
	if !ek.Wechsel.Hervorgehoben {
		t.Error("ein offener Wechsel ist nicht hervorgehoben — dann bleibt der halbe " +
			"Wechsel liegen, ohne dass es auffällt")
	}
	if ek.Wechsel.Frist == "" {
		t.Error("am offenen Wechsel steht nicht, wie lange er gilt")
	}
	if ek.Wechsel.Geheimnis == "" {
		t.Error("das Geheimnis steht nicht als Text da — nicht jeder kann einen " +
			"QR-Code abfotografieren")
	}
	if !strings.HasPrefix(ek.Wechsel.QRPfad, "/api/v1/") {
		t.Errorf("der QR-Code kommt von %q, erwartet einen Pfad unter /api/v1/ — ein "+
			"data:-URI hätte das Geheimnis ein zweites Mal in der Antwort", ek.Wechsel.QRPfad)
	}
	// Die Lektion aus rc.5 und dem Editor: Ein von der Richtlinie verworfenes Bild
	// steht als <img> im DOM und ist doch nicht da.
	if !ek.Wechsel.QRGeladen {
		t.Error("der QR-Code ist nicht geladen — img-src der Inhaltsrichtlinie verwirft ihn")
	}
	if !ek.NachNeuladen {
		t.Error("nach dem Neuladen ist der begonnene Wechsel verschwunden — der Zustand " +
			"liegt auf dem Server und soll das überstehen")
	}
	if !strings.Contains(ek.FalscherCode.Meldung, "Code") {
		t.Errorf("ein falscher Code wird nicht als solcher benannt: %q", ek.FalscherCode.Meldung)
	}
	if !ek.FalscherCode.NochOffen {
		t.Error("nach einem falschen Code ist der Wechsel abgebrochen — er müsste " +
			"offen bleiben, damit man es erneut versuchen kann")
	}
	if !ek.NachAbbruch.WechselWeg {
		t.Error("der Abbruch hat den Wechsel nicht verworfen")
	}
	if !strings.Contains(ek.NachAbbruch.Meldung, "gilt weiter") {
		t.Errorf("nach dem Abbruch steht nicht, dass der bisherige Faktor weiter gilt: %q",
			ek.NachAbbruch.Meldung)
	}

	// Die Wiederherstellungscodes.
	if !strings.Contains(ek.CodesFrage, "nicht mehr") {
		t.Errorf("die Frage sagt nicht, dass die alten Codes verfallen: %q", ek.CodesFrage)
	}
	if ek.Codes.Anzahl == 0 {
		t.Error("es werden keine Codes angezeigt")
	}
	if !strings.Contains(ek.Codes.Warnung, "nur jetzt") {
		t.Errorf("es steht nicht dabei, dass die Liste nur einmal kommt: %q", ek.Codes.Warnung)
	}
	if math.Abs(ek.Codes.Links-ek.Codes.Rechts) > 2 {
		t.Errorf("der Codes-Dialog sitzt nicht in der Mitte: %.0f links, %.0f rechts",
			ek.Codes.Links, ek.Codes.Rechts)
	}
	if !ek.CodesNachEscape {
		t.Error("Escape schließt den Codes-Dialog — dann ist die Liste weg, bevor sie " +
			"jemand abgeschrieben hat, und sie kommt kein zweites Mal")
	}
	if ek.CodesOffen == "" {
		t.Error("nach dem Erzeugen steht die Zahl der offenen Codes nicht da")
	}
	if ek.Schmal.FensterBreite == 0 {
		t.Error("die Kontoseite wurde nicht im Schmalmodus gemessen")
	} else if ek.Schmal.KoerperBreite > ek.Schmal.FensterBreite+1 {
		t.Errorf("die Kontoseite ist %.0f Pixel breit bei %.0f Pixeln Fenster — sie scrollt waagerecht",
			ek.Schmal.KoerperBreite, ek.Schmal.FensterBreite)
	}

	// 6k. Die Gegenprobe mit einer anderen Rolle. Ein Menüpunkt, der zuverlässig
	// „der Owner-Rolle vorbehalten" antwortet, ist kein Menüpunkt.
	fro := e.FremdeRolle
	if fro.ImMenue {
		t.Error("die Admin-Rolle sieht den Menüpunkt für die Panel-Zugänge — er führt " +
			"für sie nur auf 403")
	}
	if fro.InPalette != 0 {
		t.Errorf("die Befehlspalette findet für die Admin-Rolle %d Treffer zu „zugänge\" — "+
			"in der Leiste gefiltert und in der Suche nicht ist genau der Fehler, den "+
			"zwei Filter derselben Regel machen", fro.InPalette)
	}
	if !strings.Contains(fro.Satz, "Owner") {
		t.Errorf("der Pfad von Hand aufgerufen sagt nicht, warum er nichts zeigt: %q", fro.Satz)
	}
	if fro.ErneutKnopf {
		t.Error("bei der Rechtefrage steht ein Knopf „Erneut versuchen" + `" — er brächte ` +
			"nie ein anderes Ergebnis, und die Meldung wäre damit zweimal falsch: " +
			"im Grund und im Ausweg")
	}

	// 6g. Ein angekündigtes Modul. Der Menüpunkt landete bis 0.4.0-rc.2
	// stillschweigend auf der Übersicht; jetzt sagt eine Seite, worum es geht.
	b := e.Bald
	if b.Pfad != "/v2/docker" {
		t.Errorf("der Pfad ist %q, erwartet /v2/docker", b.Pfad)
	}
	if b.Titel != "Docker" {
		t.Errorf("die Überschrift ist %q, erwartet Docker — die Seite nennt nicht, "+
			"worum es geht", b.Titel)
	}
	if !strings.Contains(b.Marke, "0.6") {
		t.Errorf("die Marke ist %q, erwartet die geplante Fassung 0.6", b.Marke)
	}
	if b.Satz == "" {
		t.Error("die Seite sagt nicht, dass es das Modul noch nicht gibt")
	}
	if b.Ersatz == "" {
		t.Error("die Seite nennt keinen Weg, der heute schon geht")
	}
	if b.NavAktiv != "/v2/docker" {
		t.Errorf("der Menüpunkt ist nicht hervorgehoben (aria-current auf %q) — "+
			"dann sieht die Seite aus wie eine, auf die man versehentlich geraten ist",
			b.NavAktiv)
	}

	// 7. Schmal: keine waagerechte Scrollerei, Beschriftung sichtbar.
	if e.Schmal.FensterBreite == 0 {
		t.Fatal("die Fensterbreite wurde nicht gemessen")
	}
	// Ein Pixel Toleranz für Rundung; mehr wäre echtes Überlaufen.
	if e.Schmal.KoerperBreite > e.Schmal.FensterBreite+1 {
		t.Errorf("der Seitenkörper ist %.0f Pixel breit bei %.0f Pixeln Fenster — "+
			"er scrollt waagerecht, und genau das war der Befund aus rc.3",
			e.Schmal.KoerperBreite, e.Schmal.FensterBreite)
	}
	if e.Schmal.Beschriftung == "" || e.Schmal.Beschriftung == "none" {
		t.Errorf("die Zellen zeigen im Schmalmodus keine Spaltenbeschriftung (%q) — "+
			"in der Kartenansicht stünde dort ein Wert ohne Namen", e.Schmal.Beschriftung)
	}
}
