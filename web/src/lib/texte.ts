// Alle Texte der Oberfläche an einer Stelle. Deutsch ist die Sprache des
// Projekts; eine zweite käme als weiterer Block in dieser Datei und nicht als
// Bibliothek — für ein Panel mit einigen hundert Zeichenketten wiegt eine
// i18n-Abhängigkeit mehr als sie trägt.

export const t = {
  marke: "asylum",

  bereiche: {
    system: "System",
    apps: "Apps",
    sicherheit: "Sicherheit",
    betrieb: "Betrieb",
  },

  ziele: {
    uebersicht: "Übersicht",
    dienste: "Dienste",
    pakete: "Pakete",
    cron: "Cron & Timer",
    docker: "Docker",
    webserver: "Webserver",
    datenbanken: "Datenbanken",
    backups: "Backups",
    firewall: "Firewall",
    benutzer: "Benutzer & SSH",
    zertifikate: "Zertifikate",
    dateien: "Dateien",
    logs: "Logs",
    audit: "Audit",
    // „Panel-Zugänge" und nicht „Benutzer": Der Unterschied zu „Benutzer & SSH"
    // muss im Menü stehen, nicht erst auf der Seite. Bis 0.4.0-rc.3 hieß dieser
    // Punkt „Einstellungen" und führte auf die Kontenliste der alten Oberfläche —
    // ein Name, der etwas anderes versprach, als dahinter stand.
    zugaenge: "Panel-Zugänge",
    konto: "Eigenes Konto",
  },

  kacheln: {
    cpu: "CPU",
    memory: "Arbeitsspeicher",
    load: "Last",
    netz: "Netzwerk",
  },

  live: {
    verbunden: "Live verbunden",
    getrennt: "Verbindung unterbrochen — versucht erneut",
    warte: "verbindet …",
  },

  protokoll: {
    leer: "Noch kein Befehl ausgeführt",
    aufklappen: "Protokoll aufklappen",
    exit: "Exit",
  },

  uebersicht: {
    seit: "seit",
    kerne: "Kerne",
    von: "von",
    gesendet: "gesendet",
    keineDaten: "Noch keine Messwerte — der erste Takt kommt in wenigen Sekunden.",
    keinVerlauf: "Für einen Verlauf sind noch zu wenige Messungen da.",

    urteilLaeuft: "Der Zustand wird erhoben …",
    urteilUnbekannt: "Der Zustand konnte nicht erhoben werden.",
    urteilUnbekanntDetail:
      "Die Messwerte unten stimmen — nur die Prüfung auf Dienste, Platte und Neustart ist ausgefallen.",
    handlungsbedarf: "Handlungsbedarf",

    dateisysteme: "Dateisysteme",
    einhaengepunkt: "Einhängepunkt",
    geraet: "Gerät",
    auslastung: "Auslastung",
    belegt: "Belegt",
    inodes: "Inodes",
    dieselbePlatte: "dieselbe Platte",
    keineDateisysteme: "Keine Dateisysteme gefunden.",
    // Eine Zahl im Text braucht beide Formen — „auch an 1 weiteren Stellen"
    // liest sich falsch, und genau solche Stellen fallen im Betrieb auf.
    weitereStellen: (n: number) =>
      n === 1 ? "auch an einer weiteren Stelle" : `auch an ${n} weiteren Stellen`,

    prozesse: "Prozesse · Spitzenreiter",
    prozess: "Prozess",
    benutzer: "Benutzer",
    speicher: "Speicher",
    keineProzesse: "Keine Prozessdaten verfügbar.",
  },

  dienste: {
    laedt: "wird geladen …",
    suchen: "Dienst suchen",
    filtern: "Nach Zustand filtern",
    alle: "alle",
    unit: "Unit",
    zustandSpalte: "Zustand",
    autostart: "Autostart",
    beschreibung: "Beschreibung",
    nichts: "Kein Dienst passt zu dieser Suche.",
    // Die drei Wörter für den einen Zustand, den der Server bildet. Die
    // Schlüssel sind die Werte aus der Schnittstelle, damit die Zuordnung ohne
    // eine zweite Tabelle auskommt.
    zustand: {
      laeuft: "läuft",
      gescheitert: "fehlgeschlagen",
      aus: "aus",
    },
    seit: "Aktiv seit",
    pid: "PID",
    speicher: "Speicher",
    unitDatei: "Unit-Datei",
    journal: "Letzte Journalzeilen",
    keineZeilen: "Keine Journalzeilen zu dieser Unit.",
    laeuft: "läuft …",
    nurLesen: "Dieses Konto darf lesen, aber nicht schalten.",
    // Beschriftungen der Aktionen. Die Schlüssel sind die Namen aus der
    // privops-Allowlist — mehr Aktionen gibt es nicht.
    aktion: {
      start: "starten",
      stop: "stoppen",
      restart: "neu starten",
      reload: "neu laden",
      enable: "Autostart ein",
      disable: "Autostart aus",
    },
    units: (n: number) => (n === 1 ? "1 Unit" : `${n} Units`),
    gescheiterte: (n: number) =>
      n === 1 ? "1 fehlgeschlagen" : `${n} fehlgeschlagen`,
  },

  pakete: {
    laedt: "wird geladen …",
    suchen: "Paket suchen",
    keine: "Alles auf dem neuesten Stand.",
    keineDetail:
      "Es sind keine Updates verfügbar. Wann die Listen zuletzt geholt wurden, sagt der Auszug unten.",
    nichts: "Kein Paket passt zu dieser Suche.",
    alle: "alle",
    paket: "Paket",
    version: "Version",
    quelle: "Quelle",
    art: "Art",
    sicherheit: "Sicherheit",
    normal: "Update",
    listenHolen: "Listen holen",
    alleEinspielen: "Alle einspielen",
    nurSicherheit: "Nur Sicherheitsupdates",
    einzelnEinspielen: "einspielen",
    nurLesen: "Dieses Konto darf lesen, aber nicht einspielen.",
    // Der Neustart wird nur angeboten, wenn er aussteht — ein Knopf, der immer
    // da ist, wird irgendwann versehentlich gedrückt.
    neustartTitel: "Ein Neustart steht aus",
    neustartWegen: "Verlangt von:",
    neustartKnopf: "Server neu starten",
    neustartNurOwner: "Den Neustart löst nur die Owner-Rolle aus.",
    neustartAngestossen:
      "Der Neustart wurde angestoßen. Die Verbindung bricht gleich ab und kommt nach dem Hochfahren zurück.",
    updates: (n: number) => (n === 1 ? "1 Update" : `${n} Updates`),
    sicherheitsupdates: (n: number) =>
      n === 1 ? "1 Sicherheitsupdate" : `${n} Sicherheitsupdates`,
  },

  logs: {
    laedt: "wird geladen …",
    zeit: "Zeit",
    stufe: "Stufe",
    unit: "Unit",
    nachricht: "Nachricht",
    suche: "Suche",
    suchen: "suchen",
    suchePlatzhalter: "im Text oder in der Unit",
    alleUnits: "alle Units",
    alleStufen: "alle Stufen",
    abFehler: "ab Fehler",
    abWarnung: "ab Warnung",
    abInfo: "ab Info",
    zeitraum: "Zeitraum",
    ohneGrenze: "ohne Grenze",
    letzteStunde: "letzte Stunde",
    letzte6h: "letzte 6 Stunden",
    letzte24h: "letzte 24 Stunden",
    heute: "heute",
    letzte7t: "letzte 7 Tage",
    verfolgen: "verfolgen",
    anhalten: "anhalten",
    keine: "Keine Einträge.",
    keineDetail:
      "Zu diesen Filtern hat das Journal nichts. Ein weiterer Zeitraum oder eine niedrigere Stufe hilft.",
    zuVieleZuschauer:
      "Es sehen schon zu viele Verbindungen dem Journal zu — ein anderer Tab hält einen Strom offen.",
    zeilenZahl: (n: number) => (n === 1 ? "1 Zeile" : `${n} Zeilen`),
    holt: (n: number) => `holt ${n}`,
    // Verworfene Zeilen ehrlich benennen: Das Journal schrieb schneller, als die
    // Leitung übertragen konnte.
    luecke: (n: number) =>
      n === 1
        ? "Eine Zeile ist unterwegs verloren gegangen — das Journal schrieb schneller als die Leitung."
        : `${n} Zeilen sind unterwegs verloren gegangen — das Journal schrieb schneller als die Leitung.`,
  },

  firewall: {
    laedt: "wird geladen …",
    ein: "eingeschaltet",
    aus: "ausgeschaltet",
    port: "Port",
    protokoll: "Protokoll",
    quelle: "Quelle",
    notiz: "Notiz",
    ueberall: "überall",
    fest: "fest",
    annehmen: "annehmen",
    entfernen: "entfernen",
    zeileHinzu: "Regel hinzufügen",
    uebernehmen: "Regeln übernehmen",
    einschalten: "ufw einschalten",
    ausschalten: "ufw ausschalten",
    einspielen: "ufw einspielen",
    bestaetigen: "Änderung bestätigen",
    nurLesen: "Dieses Konto darf lesen, aber die Firewall nicht ändern.",
    nichtInstalliert: "ufw ist nicht installiert.",
    nichtInstalliertDetail:
      "Ohne ufw gibt es keinen Regelsatz, den das Panel verwalten kann. Einspielen läuft als Vorgang; die Ausgabe steht danach oben.",
    entwurfOffen:
      "Die Liste ist bearbeitet und gilt noch nicht — erst „Regeln übernehmen“ schreibt sie.",
    // Der Satz, um den es auf dieser Seite geht. Er nennt beides: dass etwas
    // gilt, und dass es von selbst wieder weggeht.
    probeTitel: (gegenstand: string) =>
      gegenstand === "Aktivierung"
        ? "ufw ist eingeschaltet — auf Probe"
        : "Die Regeln gelten — auf Probe",
    probeDetail:
      "Ohne Bestätigung wird der vorherige Stand wiederhergestellt. Bestätigen Sie, solange diese Verbindung noch steht.",
    panelPortFehlt: (port: number) =>
      `Für Port ${port} gibt es keine Regel von überall her. Ohne sie verweigert der Server das Einschalten — das Panel wäre danach nicht mehr erreichbar, auch nicht zum Bestätigen.`,
  },

  dateien: {
    laedt: "wird geladen …",
    suchen: "Namen suchen",
    suchenHier: "unterhalb dieses Ordners suchen",
    suchergebnis: (begriff: string, n: number) =>
      `${n} Treffer für „${begriff}“`,
    suchenBeenden: "Suche beenden",
    versteckte: "versteckte zeigen",
    name: "Name",
    groesse: "Größe",
    geaendert: "Geändert",
    rechte: "Rechte",
    eigentuemer: "Eigentümer",
    art: "Art",
    hoch: "eine Ebene höher",
    leer: "Dieser Ordner ist leer.",
    nichtsGefunden: "Kein Eintrag passt.",
    wurzeln: "Bereiche",
    frei: "frei",
    freiKnapp: "wenig Platz",
    // Die Zählung unter der Liste. Sie nennt, was ausgeliefert wurde — bei einer
    // gekürzten Liste steht die wahre Zahl daneben in gekuerzt_grund.
    inhalt: (ordner: number, dateien: number, bytes: string) =>
      `${ordner} Ordner, ${dateien} ${dateien === 1 ? "Datei" : "Dateien"} · ${bytes}`,
    gesperrt: "gesperrt",
    gesperrtErklaerung:
      "Der Pfad steht auf der Sperrliste. Der Eintrag ist sichtbar, sein Inhalt wird nie gelesen, geschrieben oder ausgeliefert.",
    nurLesen: "Dieses Konto darf lesen, aber nichts verändern.",
    nichtBeschreibbar:
      "Dieser Bereich ist nicht beschreibbar — verändernde Handgriffe fehlen deshalb.",
    verweisAuf: "zeigt auf",
    verweisGebrochen: "Das Ziel des Verweises existiert nicht.",
    inhaltZaehlung: "Inhalt",
    grenzeEditor: (text: string) => `Der Editor öffnet Dateien bis ${text}.`,
    // Der Weg zurück bleibt sichtbar, solange die alte Fläche mehr kann.
    alteAnsicht: "dieser Ordner in der alten Ansicht",

    // ---------------------------------------------------------- Verändern ---
    neuerOrdner: "Neuer Ordner",
    neueDatei: "Neue Datei",
    anlegen: "anlegen",
    namePlatzhalter: "Name",
    hochladenTitel: "Dateien hochladen",
    hochladenWaehlen: "Dateien auswählen",
    hochladenLaeuft: "wird übertragen …",
    ueberschreiben: "vorhandene ersetzen (mit Sicherung)",
    hochladenGrenze: (text: string) => `Höchstens ${text} je Datei.`,
    umbenennenTitel: "Umbenennen",
    zielwahlKopieren: "Wohin kopieren?",
    zielwahlVerschieben: "Wohin verschieben?",
    zielIst: "Ziel",
    zielNichtBeschreibbar:
      "In diesen Ordner darf nicht geschrieben werden — wählen Sie einen anderen.",
    zielGekuerzt: "Die Liste ist gekürzt; tieferliegende Ordner erreicht man über die Krumen.",
    keineUnterordner: "Hier gibt es keine Unterordner.",
    nurDurchsehen: "nur durchsehen",
    rechteTitel: "Rechte und Eigentümer",
    rechteOktal: "Rechte (oktal)",
    rechteAnwenden: "anwenden",
    rekursiv: "auf alle Einträge darunter anwenden",
    ohneAenderung: "unverändert",
    abbrechen: "abbrechen",
    // Das Ergebnis einer Handlung. Es steht im Inspektor und nicht als Toast:
    // Wer eine Datei umbenennt, sieht danach dorthin, wo er den Knopf gedrückt
    // hat.
    erledigt: "erledigt",
    nichtsGeaendert: "Es war nichts zu ändern — die Werte stehen schon so.",

    // ------------------------------------------------------------- Editor ---
    editorSchliessen: "Editor schließen",
    editorNichtGeladen:
      "Der Editor ließ sich nicht laden. Er kommt als eigener Brocken nach; bitte die Seite neu laden.",
    speichern: "speichern",
    speichertGerade: "speichert …",
    ungespeichert: "ungespeichert",
    // Die Zusage VOR dem Speichern. Wer weiß, dass geprüft und im Fehlerfall
    // zurückgerollt wird, editiert anders.
    wirdGeprueft: (werkzeug: string) =>
      `Nach dem Speichern prüft ${werkzeug} die Datei. Lehnt das Programm sie ab, wird der vorherige Stand zurückgeschrieben.`,
    fremdenStandLaden: "fremden Stand übernehmen (eigene Fassung verwerfen)",
    fremdUebernommen: "Der Stand von der Platte ist übernommen. Ihre Fassung ist verworfen.",
    ueberschreiben2: "fremde Änderung überschreiben",
    hochgeladen: (n: number) =>
      n === 1 ? "Eine Datei hochgeladen." : `${n} Dateien hochgeladen.`,
    handgriff: {
      oeffnen: "öffnen",
      herunterladen: "herunterladen",
      archiv: "als tar.gz laden",
      bearbeiten: "bearbeiten",
      umbenennen: "umbenennen",
      kopieren: "kopieren",
      verschieben: "verschieben",
      rechte: "Rechte",
      loeschen: "löschen",
      anlegen: "anlegen",
      hochladen: "hochladen",
    },
  },

  vorgang: {
    laeuft: "läuft",
    fertig: "abgeschlossen",
    gescheitert: "gescheitert",
    teils: "teils geglückt",
    von: "von",
    auszug: "Ausgabe des Vorgangs",
    zumEnde: "zum Ende springen",
    wartetAufAusgabe: "wartet auf die erste Ausgabe …",
    stromWeg: "Der Live-Auszug ist abgerissen — der Vorgang läuft weiter.",
  },

  inspektor: {
    titel: "Einzelheiten",
    schliessen: "Einzelheiten schließen",
  },

  rueckfrage: {
    abbrechen: "abbrechen",
    laeuft: "läuft …",
  },

  palette: {
    titel: "Suchen und springen",
    platzhalter: "Suchen oder springen …",
    nichts: "Nichts gefunden.",
    waehlen: "wählen",
    oeffnen: "öffnen",
    neu: "neu",
    // Ehrlich benannt: Die Palette sucht heute Ziele, keine Einträge darin. Wer
    // hier nach einer Unit oder einem Pfad sucht und nichts findet, hält die
    // Suche für kaputt — sie hat nur einen anderen Gegenstand.
    spaeter: "Units, Pfade und Pakete sucht man auf ihrer Seite",
  },

  konten: {
    laedt: "wird geladen …",
    // Der Satz, der die beiden Kontenarten auseinanderhält. Wer das verwechselt,
    // legt ein Konto an, das nichts kann — oder eines, das mehr kann als gedacht.
    wesen:
      "Konten des Servers, nicht des Panels: Damit kommt man über SSH auf die Maschine. Die Zugänge zum Panel selbst stehen unter „Panel-Zugänge“; sie verwaltet die Owner-Rolle.",
    name: "Name",
    uid: "UID",
    gruppen: "Gruppen",
    schale: "Anmeldeschale",
    schluesselSpalte: "Schlüssel",
    zustand: "Zustand",
    notiz: "Notiz",
    home: "Home",
    suchen: "Konto suchen",
    // Die Zähler sind die Filter — Grundsatz II: jede Zahl ist ein Griff.
    alle: "alle",
    menschen: "mit Anmeldung",
    dienste: "Dienstkonten",
    gesperrt: "gesperrt",
    ohneSchluessel: "ohne Schlüssel",
    nichts: "Kein Konto passt.",
    entsperrt: "aktiv",
    // Die Auffälligkeit, die eine Handlung nach sich zieht.
    ohneSchluesselWarnung:
      "Dieses Konto hat keinen Schlüssel und kein Passwort — es kommt nicht auf den Server.",
    dienstkonto:
      "Dienstkonto ohne Anmeldeschale. Es dient einem Programm, nicht einem Menschen — eine Anmeldung ist nicht vorgesehen.",
    geschuetzt: "Dieses Konto lässt sich über das Panel nicht sperren und nicht löschen.",
    nurLesen: "Dieses Konto darf lesen, aber keine Systemkonten ändern.",

    anlegen: "Konto anlegen",
    anlegenTitel: "Neues Systemkonto",
    anlegenHinweis:
      "Das Konto bekommt kein Passwort. Die Anmeldung läuft über den SSH-Schlüssel — ohne Schlüssel kommt niemand herein.",
    namePlatzhalter: "kontoname",
    notizPlatzhalter: "wofür ist das Konto",
    schluesselPlatzhalter: "ssh-ed25519 AAAA… kommentar",
    schluesselFeld: "Öffentlicher SSH-Schlüssel",
    schluesselOptional: "kann auch später hinterlegt werden",
    abbrechen: "abbrechen",

    handgriff: {
      sperren: "sperren",
      entsperren: "entsperren",
      loeschen: "löschen",
      schluessel: "Schlüssel",
    },
    homeEntfernen: "Home-Verzeichnis mit löschen",
    schluesselTitel: "SSH-Schlüssel",
    schluesselDatei: (datei: string) => `Hinterlegt in ${datei}`,
    keineSchluessel: "Kein Schlüssel hinterlegt.",
    schluesselHinzu: "Schlüssel hinterlegen",
    schluesselEntfernen: "entfernen",
    schwach: "schwach",
    letzterSchluessel:
      "Das ist der einzige Schlüssel. Ohne ihn hat das Konto keinen Zugang mehr.",
    fingerprint: "Fingerprint",
    art: "Art",
  },

  konto: {
    laedt: "wird geladen …",
    wesen:
      "Ihr eigenes Konto: Anmeldung, zweiter Faktor, Passkeys und die offenen Sitzungen. Konten anderer stehen unter Panel-Zugänge.",
    rolle: "Rolle",
    angelegt: "Angelegt",
    codesOffen: "Wiederherstellungscodes",
    codesZahl: (n: number) => (n === 1 ? "1 unbenutzt" : `${n} unbenutzt`),
    // Bei 0 ist der Weg zurück ins Konto verstellt, wenn das Telefon
    // abhandenkommt. Das ist der Grund, warum die Zahl überhaupt dasteht.
    codesKeine: "keiner mehr übrig",
    codesWarnung:
      "Es ist kein Wiederherstellungscode mehr übrig. Geht das Telefon verloren, führt der Weg zurück nur noch über die Kommandozeile des Servers.",
    wechselzwang:
      "Das aktuelle Passwort ist ein Einmalpasswort aus einer Zurücksetzung. Bitte jetzt ein eigenes wählen.",

    // ---------------------------------------------------------- Passwort ---
    passwortTitel: "Passwort",
    aktuell: "Aktuelles Passwort",
    // Der Satz sagt, WARUM danach gefragt wird. Ohne ihn wirkt es wie eine
    // Formalität.
    aktuellWarum:
      "Jede Änderung an einem Anmeldeweg verlangt Ihr aktuelles Passwort — eine übernommene Sitzung soll Sie nicht aus Ihrem eigenen Konto aussperren können.",
    neu: "Neues Passwort",
    neuWiederholt: "Neues Passwort wiederholen",
    passwortAendern: "Passwort ändern",
    passwortFolge:
      "Alle anderen Sitzungen werden dabei beendet. Diese bleibt offen.",

    // --------------------------------------------------- Zweiter Faktor ---
    faktorTitel: "Zweiter Faktor",
    faktorGut: "Eingerichtet und bestätigt.",
    faktorWechseln: "Zweiten Faktor wechseln",
    faktorWarum:
      "Für ein neues Telefon: Der bisherige Faktor gilt weiter, bis der neue bestätigt ist.",
    wechselOffen: "Wechsel begonnen",
    wechselBis: (zeit: string) => `gültig bis ${zeit}`,
    wechselScannen:
      "Den Code in der Authenticator-App einlesen oder das Geheimnis von Hand eintragen, dann den angezeigten Sechsstelligen bestätigen.",
    geheimnis: "Geheimnis",
    qrAlt: "QR-Code für die Authenticator-App",
    code: "Code aus der App",
    faktorBestaetigen: "Wechsel abschließen",
    faktorAbbrechen: "Wechsel abbrechen",
    faktorFolge:
      "Mit dem Abschluss werden neue Wiederherstellungscodes erzeugt und alle anderen Sitzungen beendet.",

    // ------------------------------------------------------------ Codes ---
    codesTitel: "Wiederherstellungscodes",
    codesWarum:
      "Der Weg zurück, wenn das Telefon mit der Authenticator-App verloren ist. Jeder Code gilt einmal.",
    codesNeu: "Neue Codes erzeugen",
    codesEinmal:
      "Diese Liste erscheint nur jetzt und wird nicht noch einmal angezeigt. Bitte ausdrucken oder in einen Passwortspeicher legen.",
    kopieren: "kopieren",
    kopiert: "kopiert",
    verstanden: "notiert, schließen",

    // --------------------------------------------------------- Passkeys ---
    passkeysTitel: "Passkeys",
    passkeysWarum:
      "Ein Passkey ersetzt das Passwort bei der Anmeldung: Bestätigt wird am Gerät — mit Fingerabdruck, Gesicht oder Geräte-PIN. Der zweite Faktor bleibt davon unberührt.",
    passkeysAus:
      "Passkeys sind in dieser Installation abgeschaltet. Eingeschaltet werden sie in der Konfigurationsdatei.",
    passkeysKeine: "Es ist kein Passkey hinterlegt.",
    passkeyAnlegen: "Passkey hinterlegen",
    passkeyName: "Name des Geräts",
    passkeyNamePlatzhalter: "Telefon, Notebook, Sicherheitsschlüssel",
    // Der Unterschied gehört in die Anzeige: Ein gerätegebundener Schlüssel ist
    // mit dem Gerät verloren.
    synced: "geräteübergreifend",
    gebunden: "an dieses Gerät gebunden",
    nieBenutzt: "noch nie benutzt",
    umbenennen: "umbenennen",
    entfernen: "entfernen",
    speichern: "speichern",
    abbrechen: "abbrechen",
    // Der Satz während der Zeremonie. Ohne ihn steht der Bediener vor einem
    // Systemdialog, den er nicht angefordert zu haben glaubt.
    amGeraet: "Bitte am Gerät bestätigen …",
    passkeyAbgebrochen: "Am Gerät abgebrochen. Es wurde nichts hinterlegt.",
    keinWebAuthn:
      "Dieser Browser kennt keine Passkeys. Die Anmeldung mit Passwort und zweitem Faktor bleibt unverändert.",

    // -------------------------------------------------------- Sitzungen ---
    sitzungenTitel: "Offene Sitzungen",
    // Der eigentliche Zweck dieser Liste — sie ist keine Statistik.
    sitzungenWarum:
      "Ein entwendetes Sitzungscookie hinterlässt sonst keine Spur. Adresse und letzte Aktivität machen eine fremde Sitzung sichtbar; der Knopf daneben beendet sie.",
    von: "Von",
    programm: "Programm",
    seit: "Angemeldet",
    zuletzt: "Zuletzt gesehen",
    laeuftAb: "Läuft ab",
    diese: "diese Sitzung",
    beenden: "beenden",
    abmelden: "abmelden",
    andereBeenden: (n: number) =>
      n === 1 ? "eine weitere Sitzung beenden" : `alle ${n} anderen Sitzungen beenden`,
    keineAnderen: "Keine weitere Sitzung offen.",
    abgemeldet: "Diese Sitzung ist beendet. Bitte neu anmelden.",
    zurAnmeldung: "Zur Anmeldung",
  },

  zugaenge: {
    laedt: "wird geladen …",
    // Der Gegensatz zum wesen-Satz bei „Benutzer & SSH". Beide Sätze zusammen
    // halten die zwei Kontenarten auseinander, und sie stehen absichtlich
    // nebeneinander im Menü.
    wesen:
      "Konten dieser Oberfläche, nicht des Servers: Damit kommt man in das Panel. Systemkonten für SSH stehen unter Benutzer & SSH.",
    nurOwner:
      "Diese Fläche ist der Owner-Rolle vorbehalten. Wer Konten verwalten kann, kann jedem Zugang zu allem anderen geben.",
    name: "Anmeldename",
    rolle: "Rolle",
    zustand: "Zustand",
    passkeysSpalte: "Passkeys",
    letzteAnmeldung: "Letzte Anmeldung",
    angelegt: "Angelegt",
    nie: "noch nie",
    suchen: "Zugang suchen",
    alle: "alle",
    owner: "Owner",
    gesperrt: "gesperrt",
    aktiv: "aktiv",
    offen: "Einrichtung offen",
    nichts: "Kein Zugang passt.",
    ich: "das ist Ihr Konto",
    ichHinweis:
      "Am eigenen Konto ändert man hier nichts: Passwort, zweiter Faktor und Passkeys stehen auf der Kontoseite, und sperren oder löschen wäre ein Selbstausschluss.",
    letzterOwner:
      "Das letzte Owner-Konto. Es lässt sich nicht löschen — danach könnte niemand mehr Konten verwalten.",
    keinZweiterFaktor:
      "Der zweite Faktor ist noch nicht eingerichtet. Das geschieht bei der nächsten Anmeldung.",
    einmalpasswortOffen:
      "Das Konto hat ein Einmalpasswort aus einer Zurücksetzung. Es wird bei der nächsten Anmeldung ersetzt.",
    nurLesen: "Dieses Konto darf keine Panel-Zugänge ändern.",

    anlegen: "Zugang anlegen",
    anlegenTitel: "Neuer Panel-Zugang",
    // Warum es kein Passwortfeld gibt. Der Satz ist die Antwort auf die Frage,
    // die jeder stellt, der hier ein solches Feld erwartet.
    anlegenHinweis:
      "Das Startpasswort erzeugt das Panel. Es steht genau einmal in der Antwort, gilt als Einmalpasswort und wird bei der ersten Anmeldung ersetzt — zusammen mit der Einrichtung des zweiten Faktors.",
    namePlatzhalter: "anmeldename",
    abbrechen: "abbrechen",

    handgriff: {
      sperren: "sperren",
      freigeben: "freigeben",
      loeschen: "löschen",
      passwort: "Passwort zurücksetzen",
      "zweiter-faktor": "zweiten Faktor zurücksetzen",
      passkeys: "Passkeys entfernen",
    } as Record<string, string>,

    // Die zweite Schranke. Der Satz sagt, WESSEN Passwort gemeint ist — die
    // häufigste Verwechslung an dieser Stelle.
    eigenesPasswort: "Ihr eigenes Passwort",
    eigenesPasswortWarum:
      "Zum Zurücksetzen eines fremden Kontos ist Ihr eigenes Passwort nötig. Ein übernommenes Fenster allein soll kein anderes Konto übernehmen können.",
    weiter: "zurücksetzen",

    // Das Einmalpasswort. Es steht genau einmal da, und das muss dabeistehen.
    einmalpasswortTitel: "Einmalpasswort",
    einmalpasswortEinmal:
      "Dieses Passwort steht nur hier und wird nicht noch einmal angezeigt. Wer es verliert, setzt erneut zurück.",
    kopieren: "kopieren",
    kopiert: "kopiert",
    verstanden: "notiert, schließen",
    fuer: (name: string) => `für ${name}`,

    passkeysAus: "Passkeys sind in dieser Installation abgeschaltet.",
    passkeysAnzahl: (n: number) => (n === 1 ? "1 Passkey" : `${n} Passkeys`),
    keinePasskeys: "kein Passkey",
  },

  audit: {
    laedt: "wird geladen …",
    zeit: "Zeit",
    akteur: "Wer",
    aktion: "Was",
    ziel: "Woran",
    ergebnis: "Ergebnis",
    ip: "Von",
    detail: "Einzelheiten",
    alleAkteure: "alle Konten",
    alleFamilien: "alle Bereiche",
    alleErgebnisse: "alle Ergebnisse",
    suchen: "in Ziel und Einzelheiten suchen",
    suchenKurz: "suchen",
    zuruecksetzen: "Filter zurücksetzen",
    mehr: "weitere 100 laden",
    laedtMehr: "lädt …",
    ende: "Das ist der Anfang des Protokolls.",
    nichts: "Kein Eintrag passt zu diesem Filter.",
    leer: "Das Protokoll ist leer.",
    // Die Wörter für die drei Ergebnisse. „denied" ist keine Panne: Es heißt,
    // dass die Politik gegriffen hat.
    ergebnisse: {
      ok: "getan",
      denied: "abgelehnt",
      error: "gescheitert",
    } as Record<string, string>,
    // Der Satz über der Liste. Er sagt, was das Protokoll ist und was es nicht
    // ist — es gibt keinen Knopf, der etwas darin ändert, und das ist Absicht.
    wesen:
      "Nur additiv: Einträge lassen sich nicht ändern und nicht löschen — auch nicht von der Owner-Rolle.",
    gefiltert: (n: number) => (n === 1 ? "1 Eintrag" : `${n} Einträge`),
  },

  bald: {
    ab: (fassung: string) => `geplant für ${fassung}`,
    satz: (modul: string, fassung: string) =>
      fassung
        ? `${modul} gibt es noch nicht. Das Modul ist für Fassung ${fassung} vorgesehen.`
        : `${modul} gibt es noch nicht.`,
    warum:
      "Der Menüpunkt steht trotzdem hier, weil er zum Leitbild gehört und die Reihenfolge der Module absehbar sein soll. Er führt auf diese Auskunft und nicht auf die Startseite — ein Klick, der stillschweigend woanders landet, sieht wie ein Fehler aus.",
    heute: "Was heute an seiner Stelle geht",
    zu: (label: string) => `zu ${label}`,
  },

  stufe: {
    kritisch: "kritisch",
    warnung: "Warnung",
  },

  fehler: {
    laden: "Die Daten konnten nicht geladen werden.",
    abgemeldet: "Die Sitzung ist abgelaufen. Bitte neu anmelden.",
    erneut: "Erneut versuchen",
  },

  vorschau:
    "Neue Oberfläche im Aufbau — die alte bleibt unter / erreichbar und unverändert.",
} as const;
