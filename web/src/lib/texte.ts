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
    tokens: "API-Tokens",
    docker: "Docker",
    // Die Flächen unter Docker. Kurze Namen, weil sie eingerückt unter dem
    // Modul stehen — „Aktualität der Images" wäre dort eine Zeile, die umbricht.
    // „Image-Updates" und nicht „Updates": So heißt schon die Seite, auf der
    // sich das Panel selbst aktualisiert, und zwei gleich benannte Ziele in der
    // Befehlspalette sind ein Rätsel.
    dockerStacks: "Stacks",
    dockerContainer: "Container",
    dockerPorts: "Ports",
    dockerUpdates: "Image-Updates",
    dockerBestand: "Bestand",
    webserver: "Webserver",
    // Die Flächen unter Webserver. „Sites" ist die Vorgabe und steht deshalb
    // ausdrücklich da — „Webserver ohne Zusatz" wäre kein Name.
    webserverSites: "Sites",
    webserverPorts: "Portbelegung",
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
    update: "Updates",
  },

  leiste: {
    // Der Text zum Warnpunkt, den nur Vorleseprogramme hören. Zwei Fassungen,
    // weil der Punkt zwei Stufen hat und „offen" beide Male zu wenig wäre.
    offen: (kritisch: boolean) =>
      kritisch ? "Hier ist etwas kritisch offen" : "Hier ist etwas offen",
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

  update: {
    laedt: "wird geladen …",
    wesen:
      "Das Panel aktualisiert sich selbst: Es lädt die Fassung aus dem eingestellten Kanal, prüft sie gegen den eingebauten Signaturschlüssel und tauscht das Programm aus. Die vorherige Fassung bleibt als Sicherung liegen.",
    // Der Block heißt „Stand" und die Zeile darin „Laufende Fassung": Dasselbe
    // Wort zweimal übereinander liest man als Versehen.
    standTitel: "Stand",
    fassung: "Laufende Fassung",
    kanal: "Kanal",
    quelle: "Metadatenquelle",
    geprueftAm: "Zuletzt geprüft",
    nieGeprueft: "in dieser Laufzeit noch nicht",
    verfuegbar: "Im Kanal steht",
    erschienen: "Erschienen",
    notizen: "Änderungsnotizen",
    notizenLink: "Notizen zu dieser Fassung",
    sicherheit: "Sicherheitsupdate",
    aktuell: "Diese Fassung ist aktuell.",
    pruefen: "nach Updates suchen",
    prueffehler: "Die Metadaten sind nicht erreichbar:",
    // „noch nicht gefragt" ist ein anderer Zustand als „kein Update".
    nichtGeprueft:
      "In dieser Laufzeit wurde noch nicht geprüft. Der letzte Befund steht im Speicher und ist nach einem Neustart des Dienstes weg.",

    einspielenTitel: "Aktualisieren",
    einspielen: (v: string) => `auf ${v} aktualisieren`,
    // Solange keine Fassung bekannt ist, heißt der Knopf schlicht so — und ist
    // gesperrt. Ihn stattdessen „nach Updates suchen" zu nennen, wie es eine
    // frühere Fassung tat, ergab zwei Knöpfe mit demselben Wort, von denen einer
    // etwas anderes tut.
    einspielenUnbekannt: "aktualisieren",
    // Die Sätze, die den Verbindungsabbruch vorher ankündigen. Ohne sie sieht er
    // wie ein Fehlschlag aus.
    einspielenWarum:
      "Der Dienst startet dabei neu. Die Oberfläche verliert für einige Sekunden die Verbindung — das gehört dazu und ist kein Fehler.",
    nurOwner:
      "Update und Rückweg löst nur die Owner-Rolle aus: Sie tauschen das Programm aus, das alle anderen Rechte durchsetzt.",
    erstPruefen: "Zum Aktualisieren zuerst nach Updates suchen.",

    rueckwegTitel: "Rückweg",
    rueckweg: (v: string) => `zurück auf ${v}`,
    rueckwegWarum:
      "Zurückgesetzt wird das Programm, nicht die Datenbank: Was neuere Fassungen an ihr geändert haben, bleibt.",
    keineSicherung:
      "Es liegt keine Sicherung einer vorherigen Fassung bereit — ein Rückweg ist erst nach dem ersten Update möglich.",
    vorher: "Gesicherte Fassung",

    verlaufTitel: "Verlauf",
    keinVerlauf: "Noch kein Update-Protokoll — vor dem ersten Lauf gibt es nichts zu zeigen.",
    laeuft: (v: string) => `Der Vorgang läuft — Ziel ist Fassung ${v}.`,
    // Der Kern der Anzeige während des Laufs: Die Verbindung reißt ab, und das
    // ist der Normalfall.
    wartetAufNeustart:
      "Die Verbindung ist weg. Das Panel startet neu; diese Seite versucht es weiter.",
    wiederDa: (v: string) => `Fassung ${v} antwortet. Der Vorgang ist durch.`,
    unveraendert: (v: string) =>
      `Das Panel antwortet wieder, weiter mit Fassung ${v}. Der Verlauf unten sagt, woran es lag.`,
  },

  zert: {
    laedt: "wird geladen …",
    wesen:
      "Das Zertifikat, mit dem das Panel selbst ausgeliefert wird. Ein selbstsigniertes funktioniert, aber jeder Browser widerspricht; Let's Encrypt beglaubigt es und erneuert es von selbst.",
    // Der Zustand des ausgelieferten Zertifikats.
    zustand: "Zustand",
    quelle: "Herkunft",
    datei: "Datei",
    inhaber: "Inhaber",
    aussteller: "Aussteller",
    namen: "Namen im Zertifikat",
    fingerprint: "Fingerprint",
    gueltig: "Gültig",
    bis: (ab: string, bis: string) => `${ab} bis ${bis}`,
    tage: (n: number) =>
      n < 0
        ? `seit ${-n} Tagen abgelaufen`
        : n === 1
          ? "noch 1 Tag"
          : `noch ${n} Tage`,
    lesefehler: "Das Zertifikat ist nicht lesbar:",
    // Der Zwischenzustand, den man erklärt bekommen möchte.
    nochNichtBezogen:
      "Der automatische Bezug ist eingestellt, aber noch kein Zertifikat bezogen. Ausgeliefert wird bis dahin das selbstsignierte.",
    selbstsigniertWarnung:
      "Selbstsigniert: Jeder Browser warnt beim Aufruf. Für ein beglaubigtes Zertifikat unten den automatischen Bezug einschalten.",

    // ---------------------------------------------------------- Bezugsart ---
    // „Bezugsart" und nicht „Bezug": Der Block darunter heißt so, und zwei
    // Überschriften mit demselben Wort auf einem Schirm liest man als Versehen.
    modusTitel: "Bezugsart",
    selbstsigniert: "selbstsigniert",
    selbstsigniertWas:
      "Das Panel erzeugt das Paar selbst. Kein Netzzugang nötig, aber jeder Browser warnt.",
    acme: "Let's Encrypt (ACME)",
    acmeWas:
      "Beglaubigt und wird vor Ablauf selbst erneuert. Braucht einen von außen erreichbaren Namen.",

    // ----------------------------------------------------- Einstellungen ---
    einstellungenTitel: "Einstellungen für den Bezug",
    email: "Kontaktadresse",
    emailWarum:
      "Dorthin schickt Let's Encrypt die Warnung, wenn eine Erneuerung ausbleibt.",
    namenFeld: "Namen",
    namenWarum:
      "Ein Name je Zeile. Leer heißt: der vollqualifizierte Rechnername des Servers.",
    geltend: "Verwendet würde:",
    keineNamen:
      "Kein Name ermittelbar — ohne vollqualifizierten Rechnernamen gibt es nichts zu beantragen.",
    pruefmethode: "Prüfmethode",
    anbieter: "DNS-Anbieter",
    hookSetzen: "Programm zum Setzen",
    hookAufraeumen: "Programm zum Aufräumen",
    hookWarum:
      "Absolute Pfade auf ausführbare Programme. Sie laufen als root und setzen beziehungsweise entfernen den TXT-Eintrag.",
    // „Token" hieß dieses Feld, solange es Cloudflare war. Mit sieben Anbietern
    // trägt es je nach Anbieter einen Schlüssel, drei Zeilen oder vier — der
    // Name muss deshalb allgemein sein.
    token: "Zugangsdaten",
    tokenHinterlegt:
      "Es sind Zugangsdaten hinterlegt. Das Feld leer lassen behält sie; ein neuer Wert ersetzt sie.",
    tokenWarum:
      "Die Zugangsdaten landen in einer eigenen Datei mit Rechten 0600 — nicht in der Konfiguration, die für die Gruppe des Dienstes lesbar ist. Zurückgezeigt werden sie nie.",
    /** zugangFelder nennt die Zeilen, die der gewählte Anbieter braucht. Der
     *  Server sagt, welche das sind (Wahl.felder); die Oberfläche führt keine
     *  zweite Liste. */
    zugangFelder: (felder: string[]) =>
      `Pflicht sind ${felder.length} Zeilen: ${felder.map((f) => `${f} = …`).join(", ")}`,
    /** zugangKuer nennt die Zeilen, die in der Vorlage stehen, aber leer
     *  bleiben dürfen — OVHs `endpoint` etwa. Ohne diesen Satz stünde
     *  „Pflicht sind 3 Zeilen" über einem Feld mit vier. */
    zugangKuer: (felder: string[]) =>
      felder.length === 1
        ? `${felder[0]} darf leer bleiben.`
        : `Leer bleiben dürfen: ${felder.join(", ")}.`,
    zugangEinzeilig:
      "Dieser Anbieter braucht genau ein Geheimnis. Es genügt, den Schlüssel einzutragen.",
    testverzeichnis: "Testverzeichnis von Let's Encrypt verwenden",
    testverzeichnisWarum:
      "Stellt Zertifikate aus, denen kein Browser traut — dafür sind die Grenzen weit. Der richtige Ort, um einen DNS-Hook einzurichten, ohne die Produktionsgrenzen zu verbrauchen.",
    testverzeichnisAktiv:
      "Es ist das Testverzeichnis eingestellt. Ein damit bezogenes Zertifikat wird von keinem Browser akzeptiert.",
    verwalteteDatei: (datei: string) => `Gespeichert wird in ${datei}`,
    speichern: "Einstellungen speichern",
    nurLesen: "Dieses Konto darf den Zertifikatsbezug nicht ändern.",

    // ----------------------------------------------------------- Bezug ---
    bezugTitel: "Bezug",
    beziehen: "jetzt beziehen",
    bezugLaeuft: "Es läuft ein Bezug.",
    bezugZuletzt: (zeit: string) => `Letzter Versuch: ${zeit}`,
    bezugFehler: "Der letzte Versuch ist gescheitert:",
    bezugWarum:
      "Ein Bezug nimmt nichts weg: Das bisherige Zertifikat bleibt aktiv, bis ein neues da ist. Über DNS kann er einige Minuten dauern.",
    bezugNurACME:
      "Beziehen geht erst, wenn der automatische Bezug eingeschaltet und gespeichert ist.",
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

  tokens: {
    wesen:
      "Zugänge für Skripte und Automatisierung: Ein Token ist ein zweiter Anmeldeweg neben Passwort und zweitem Faktor. Er erbt die Rolle des Kontos, das ihn anlegt, und kann nie mehr als dieses Konto.",
    laedt: "Tokens werden gelesen …",
    suchen: "Name, Konto oder Anfang",
    nichts: "Es gibt noch keinen Token.",
    nichtsGefiltert: "Kein Token passt zur Suche.",
    // Spalten.
    name: "Name",
    anfang: "Anfang",
    konto: "Konto",
    umfang: "Umfang",
    frist: "Frist",
    zuletzt: "zuletzt benutzt",
    zustandSpalte: "Zustand",
    // Die Spalte der Handgriffe braucht eine Beschriftung, weil unter 600 Pixeln
    // jede Zelle zu einer Karte mit Namen wird — ein Knopf ohne Namen daneben
    // sähe nach einem Fehler aus.
    handgriff: "Handgriff",
    // Werte.
    alleFlaechen: "alle offenen Flächen",
    nurLesen: "nur lesen",
    lesenUndSchreiben: "lesen und schreiben",
    ohneAblauf: "ohne Ablauf",
    nieBenutzt: "noch nie",
    von: "von",
    angelegtAm: "angelegt",
    // Anlegen.
    anlegen: "Token anlegen",
    anlegenTitel: "Neuer Token",
    abbrechen: "abbrechen",
    widerrufen: "widerrufen",
    namePlatzhalter: "Sicherungsskript",
    nameHinweis:
      "In sechs Monaten ist der Name die einzige Auskunft darüber, wozu dieser Token da war.",
    rechte: "Rechte",
    rechteHinweis:
      "Nur lesen beschneidet den Token auf Abfragen. Die Rolle des Kontos bleibt die Obergrenze — mehr als Sie selbst kann er nie.",
    flaechen: "Flächen",
    flaechenHinweis:
      "Keine Auswahl heißt: alle für Tokens offenen Flächen. Eine Auswahl ist eine Einschränkung — der Token gilt dann nur dort.",
    fristFeld: "Laufzeit",
    fristHinweis:
      "Ohne Ablauf gilt der Token, bis ihn jemand widerruft. Das ist erlaubt und bleibt eine offene Rechnung; die Liste markiert solche Tokens dauerhaft.",
    gesperrtTitel: "Für Tokens gesperrt",
    gesperrtHinweis:
      "Diese Flächen erreicht kein Token, unabhängig von Rolle und Auswahl: Er soll weder Tokens noch Panel-Zugänge anlegen und nicht den eigenen Anmeldeweg ändern können — sonst überlebt ein entwendeter Token seinen eigenen Widerruf.",
    nurOwner: "Tokens verwaltet die Owner-Rolle. Wer Tokens vergeben kann, vergibt Zugänge.",
    // Die Einmal-Anzeige.
    einmalTitel: "Der Token",
    einmalWarnung:
      "Dieser Token steht nur hier und wird nicht noch einmal angezeigt. Wer ihn verliert, widerruft ihn und legt einen neuen an.",
    kopieren: "kopieren",
    kopiert: "kopiert",
    einmalSchliessen: "notiert, schließen",
    // Wie man ihn benutzt — Grundsatz V: die Oberfläche erklärt sich dort, wo
    // etwas geschieht. Ohne dieses Beispiel muss man die Dokumentation suchen.
    benutzung: "So wird er benutzt",
    benutzungBefehl: (host: string, token: string) =>
      `curl -H "Authorization: Bearer ${token}" \\
  https://${host}/api/v1/overview`,
  },

  zeitplaene: {
    wesen:
      "Was auf diesem Server von allein läuft: Cron-Einträge und systemd-Timer. Gelesen wird alles, geschrieben nur, was das Panel selbst angelegt hat — fremde Crontabs bleiben unangetastet.",
    laedt: "Zeitpläne werden gelesen …",
    suchen: "Befehl, Benutzer oder Zeitplan",
    nichts: "Kein Zeitplan passt zur Suche.",
    // Die Zähler oben sind die Filter — Grundsatz II: jede Zahl ist ein Griff.
    alle: "alle",
    eigene: "vom Panel",
    fremde: "vom System",
    aus: "abgeschaltet",
    // Spalten der Cron-Tabelle.
    wann: "wann",
    wer: "als",
    was: "Befehl",
    herkunft: "Quelle",
    // Der rohe Zeitplan steht neben dem Satz. Beides, weil der Satz eine
    // Lesehilfe ist und kein Ersatz: Wer die fünf Felder kennt, liest sie
    // schneller, und wer sie nicht kennt, braucht den Satz.
    rohHinweis: "Zeitplan in Cron-Schreibweise",
    keinSatz: "Dieser Zeitplan lässt sich nicht in einen Satz fassen — es gilt das Feld links.",
    nurAuskunft: "Dieser Eintrag gehört nicht dem Panel. Er wird gelesen und nicht angefasst; ändern lässt er sich in",
    skript:
      "Eine Datei in einem run-parts-Verzeichnis. Was dort liegt und ausführbar ist, läuft — es gibt keine Crontab-Zeile dazu.",
    abgeschaltet: "abgeschaltet",
    zeileIn: (zeile: number) => `Zeile ${zeile}`,
    // Anlegen und Ändern.
    anlegen: "Zeitplan anlegen",
    anlegenTitel: "Neuer Zeitplan",
    aendern: "ändern",
    speichern: "speichern",
    abbrechen: "abbrechen",
    loeschen: "löschen",
    einschalten: "einschalten",
    ausschalten: "abschalten",
    name: "Name",
    namePlatzhalter: "sicherung",
    nameHinweis:
      "Wird zum Dateinamen unter /etc/cron.d. Kleinbuchstaben, Ziffern, Bindestrich, Unterstrich — kein Punkt: cron überspringt Dateien mit Punkt im Namen stillschweigend.",
    plan: "Zeitplan",
    planHinweis: "Fünf Felder (Minute Stunde Tag Monat Wochentag) oder ein Sonderwort wie @daily.",
    vorlagen: "Vorlagen",
    benutzer: "läuft als",
    benutzerHinweis:
      "Der Befehl läuft mit den Rechten dieses Kontos. Als root heißt das: vollen Zugriff auf den Server.",
    befehl: "Befehl",
    befehlHinweis:
      "Die Zeile geht an /bin/sh — Pipes, Umleitungen und Semikolon sind erlaubt. Ein Prozentzeichen muss maskiert werden (\\%), sonst beendet es in einer Crontab den Befehl.",
    beschreibung: "Beschreibung",
    beschreibungHinweis:
      "Steht als Kommentarzeile über dem Eintrag. In sechs Monaten die wichtigste Angabe der Datei.",
    aktiv: "läuft",
    aktivHinweis:
      "Abgeschaltet wird die Zeile auskommentiert geschrieben: Sie bleibt lesbar, statt zu verschwinden.",
    nurLesen: "Zeitpläne ändern darf nur die Owner-Rolle.",
    nurOwner:
      "Ein Cron-Eintrag ist eine Shell-Zeile: Wer einen anlegen darf, führt Code als den eingetragenen Benutzer aus. Deshalb liegt das Schreiben bei derselben Schranke wie die Systemkonten.",
    schreibtNach: (dir: string) => `Verwaltete Einträge liegen in ${dir}.`,
    luecken: "Nicht alle Quellen ließen sich lesen",
    // Timer.
    timerTitel: "systemd-Timer",
    timerWesen:
      "Timer werden gelesen. Starten, stoppen und beim Hochfahren aktivieren geht über die Dienste — ein Timer ist eine Unit. Neue Timer legt das Panel nicht an; wer die Härtung von systemd braucht, schreibt die Units von Hand.",
    timerNichts: "Es gibt keine Timer auf diesem System.",
    timerFehler: "systemd antwortet nicht",
    timerUnit: "Timer",
    timerLoest: "startet",
    timerNaechster: "nächster Lauf",
    timerLetzter: "letzter Lauf",
    timerNie: "nie",
    timerUnbekannt: "unbekannt",
    timerPersistent: "holt versäumte Läufe nach",
    timerZuDenDiensten: "zu den Diensten",
    laufTitel: "Letzter Lauf",
    laufGeglueckt: "ohne Fehler",
    laufGescheitert: (code: number) => `gescheitert (Rückgabewert ${code})`,
    laufUnbekannt: "noch nie gelaufen",
    laufFragen: "Ergebnis des letzten Laufs holen",
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

  webserver: {
    wesen:
      "Domains, Ziele und TLS. Verwaltet wird nginx; jeder andere Webserver wird erkannt und nicht angefasst — das Panel schreibt nur in eigene Dateien.",
    laedt: "Zustand wird gelesen …",
    server: "Webserver",
    dienst: "Dienst",
    ports: "Ports 80 und 443",
    paket: "Paket",
    laeuft: "läuft",
    tot: "läuft nicht",
    fehlt: "fehlt",
    frei: "frei",
    // „Unbekannt" ist ein eigener Zustand und nicht „frei". Er ist der Grund,
    // warum an dieser Stelle kein Knopf steht, und muss deshalb lesbar sein.
    unbekannt: "unbekannt",
    offen: "—",
    ausApt: "an der Paketverwaltung vorbei installiert",
    einspielen: "nginx einspielen",
    zuDiensten: "zu den Diensten",
    zuDateien: "zu den Dateien",
    belegung: "Wer hört auf den Webports",
    spaltePort: "Port",
    spalteAdresse: "Adresse",
    spalteProzess: "Programm",
    unbenannt: "nicht ermittelt",
    eigen: "nginx",
    fremd: "fremd",
    // Die Marke in der Portspalte. „verwaltet" und nicht „nginx": In der Zeile
    // steht der Programmname schon davor, und „nginx nginx" ist keine Auskunft.
    // Dasselbe Wort wie in der Sitesliste — dieselbe Frage, dieselbe Antwort.
    markeVerwaltet: "verwaltet",
    nurOwner:
      "Dieses Konto darf den Zustand sehen, den Webserver aber nicht einspielen. Eine Site ist eine Konfiguration, die als root gelesen wird und einen Dienst aus dem Netz erreichbar macht — deshalb liegt dieses Modul bei der Owner-Rolle.",
    imBau: "Was hier noch fehlt",
    // Kein Termin, den niemand zugesagt hat — dieselbe Berichtigung wie bei
    // Docker. Was steht, ist die Reihenfolge, nicht das Datum.
    imBauDetail:
      "Sites sind bis hierher LESBAR: Was nginx ausliefert, steht in der Liste — auch das, was das Panel nie geschrieben hat. Was fehlt, ist der Schreibpfad: Sites anlegen und ändern als Domain → Ziel → TLS, der Prüfer davor, `nginx -t` und die Probe danach. Ein Zertifikat je Site folgt danach. Die Begründung steht im Repository unter docs/18-webserver.md.",

    // Die Fläche „Sites".
    sites: "Sites",
    sitesLaedt: "Konfiguration wird gelesen …",
    // Der leere Fall braucht keinen eigenen Satz: Ob die Liste leer ist, weil
    // der Server leer ist, oder weil sie sich nicht lesen ließ, entscheidet der
    // Server — und schickt den passenden Satz als anmerkung mit. Zwei Sätze
    // hier wären eine zweite Auslegung derselben Frage.
    spalteSite: "Site",
    spalteDomains: "Domains",
    spalteZiel: "Ziel",
    spaltePorts: "Ports",
    spalteHerkunft: "Herkunft",
    ohneDomain: "ohne server_name",
    tls: "TLS",
    zaehlerVerwaltet: "verwaltet",
    zaehlerFremd: "fremd",
    // Der Satz über der Liste. Er sagt, was diese Fläche IST und was sie noch
    // nicht ist — sonst sucht man den Knopf zum Anlegen, den es noch nicht gibt.
    sitesNurLesend:
      "Diese Liste zeigt, was nginx gerade ausliefert. Ändern lässt sich hier noch nichts — der Schreibpfad kommt im nächsten Schritt.",
    // Ohne nginx wird `nginx -T` gar nicht erst aufgerufen. Ein Fehler „command
    // not found" wäre die Antwort auf eine Frage, die niemand gestellt hat —
    // die Karten oben sagen bereits, was fehlt.
    sitesOhneNginx:
      "Ohne nginx gibt es keine Serverblöcke zu lesen. Was zu tun ist, steht oben.",
    portsUngeprueft:
      "Die Belegung der Webports ließ sich nicht ermitteln — `ss` fehlt oder antwortete nicht. Solange das so ist, bietet das Panel die Installation nicht an: Unbekannt ist kein Frei.",
    portsFrei: "Auf Port 80 und 443 hört derzeit niemand.",
    flaecheUnbekannt: "Diese Fläche gibt es in diesem Modul nicht.",
  },

  docker: {
    wesen:
      "Container, Images und Compose-Stacks. Das Panel spricht mit Docker über die Kommandozeile und nie über den Socket — jede Aktion steht damit als Befehl in der Protokollzeile.",
    laedt: "Zustand wird gelesen …",
    laufzeit: "Laufzeit",
    daemon: "Daemon",
    compose: "Compose",
    paket: "Paket",
    laeuft: "antwortet",
    tot: "antwortet nicht",
    fehlt: "fehlt",
    da: "vorhanden",
    // Ohne Docker gibt es zu Daemon und Compose keine Frage. Dort „antwortet
    // nicht" und „fehlt" zu melden, machte aus einem Befund drei — und zwei
    // davon wären erfunden.
    offen: "—",
    ausApt: "an der Paketverwaltung vorbei installiert",
    einspielen: "Docker einspielen",
    composeEinspielen: "docker compose einspielen",
    zuDiensten: "zu den Diensten",
    nurOwner:
      "Dieses Konto darf den Zustand sehen, aber Docker nicht bedienen. Ein Container mit Zugriff auf das Wirtsdateisystem ist root auf dem Server — deshalb liegt dieses Modul bei der Owner-Rolle.",
    // Was in dieser Fassung noch nicht da ist, steht als Satz da statt als
    // leerer Bereich. Eine Fläche, die nichts sagt, sieht aus wie ein Fehler.
    imBau: "Was hier noch fehlt",
    // Kein Termin, den niemand zugesagt hat: Bis 0.5.0 stand hier „kommt mit
    // dem letzten Schritt der Fassung 0.5" — der Schritt wurde zurückgestellt,
    // der Satz blieb stehen. Dieselbe Sorte Versprechen wie auf der leeren
    // Stackliste, und dieselbe Berichtigung: sagen, was ist.
    imBauDetail:
      "Die Container-Shell ist zurückgestellt. Sie bringt die schwierigere Hälfte eines Web-Terminals mit — PTY, bidirektionaler Transport, Terminal im Browser — und wird deshalb zusammen mit dieser Frage entschieden, nicht nebenbei. Die Begründung steht im Repository unter docs/17-docker.md.",

    // Container
    container: "Container",
    suchen: "Name, Image oder Stack",
    nichts: "Kein Container passt zur Suche.",
    keine: "Auf diesem Server läuft kein Container.",
    alle: "alle",
    laufend: "laufend",
    gestoppt: "gestoppt",
    auffaellig: "auffällig",
    spalteName: "Name",
    spalteImage: "Image",
    spalteStack: "Stack",
    spalteZustand: "Zustand",
    spaltePorts: "Ports",
    ohneGesundheit: "keine Prüfung",
    // Handgriffe. Die Beschriftung steht hier und der Bezeichner kommt vom
    // Server — so heißt derselbe Handgriff überall gleich.
    aktion: {
      start: "starten",
      stop: "stoppen",
      restart: "neu starten",
      pause: "anhalten",
      unpause: "fortsetzen",
      remove: "entfernen",
    } as Record<string, string>,
    // Inspektor
    befehl: "Befehl",
    neustartregel: "Neustart",
    exitCode: "Exit-Code",
    benutzer: "Benutzer",
    erstellt: "erstellt",
    umgebung: "Umgebungsvariablen",
    umgebungWarum:
      "Nur die Anzahl: Umgebungsvariablen tragen häufig Passwörter und Schlüssel. Wer sie braucht, kommt über SSH an sie heran.",
    privilegiert: "privilegiert",
    privilegiertWarum:
      "Dieser Container läuft privilegiert. Er hat damit auf dem Wirt praktisch die Rechte von root.",
    mounts: "Eingehängt",
    netze: "Netze",
    bind: "Wirtspfad",
    volume: "Volume",
    nurLesen: "nur lesen",
    stats: "Auslastung",
    cpu: "CPU",
    speicher: "Speicher",
    netz: "Netz",
    platte: "Platte",
    prozesse: "Prozesse",
    protokoll: "Protokoll",
    keinProtokoll: "Der Container hat nichts ausgegeben.",
    verfolgen: "verfolgen",
    anhalten: "anhalten",
    folgerVoll:
      "Es sehen schon zu viele Verbindungen Containerprotokollen zu. Bitte einen anderen Tab schließen.",
    verworfen: (n: number) => `${n} Zeilen verworfen — der Container schreibt schneller als die Leitung überträgt.`,

    // Bestand
    bestand: "Bestand",
    platte: "Platzbedarf",
    posten: "Art",
    anzahl: "Anzahl",
    inGebrauchSpalte: "in Gebrauch",
    groesse: "Größe",
    freigebbar: "freigebbar",
    images: "Images",
    volumesTitel: "Volumes",
    netzeTitel: "Netze",
    spalteImage: "Image",
    spalteAlter: "Alter",
    spalteOrt: "Ort",
    spalteTreiber: "Treiber",
    ohneNamen: "ohne Namen",
    inGebrauch: "in Gebrauch",
    eingebaut: "eingebaut",
    entfernen: "entfernen",
    keineImages: "Keine Images in der lokalen Ablage.",
    keineVolumes: "Keine Volumes.",
    keineNetze: "Keine Netze.",
    // Aufräumen. Der Text sagt je Knopf, was er trifft — "aufräumen" allein
    // befähigt zu keiner Entscheidung.
    aufraeumen: "aufräumen",
    verwaisteWeg: "namenlose Images wegräumen",
    alleUnbenutztenWeg: "alle unbenutzten Images wegräumen",
    gestoppteWeg: "gestoppte Container wegräumen",
    volumesWeg: "ungenutzte Volumes wegräumen",
    netzeWeg: "ungenutzte Netze wegräumen",
    cacheWeg: "Baucache leeren",

    // Stacks
    stacks: "Stacks",
    // Der Satz sagt, was jetzt geht, und wo das Ergebnis liegt. Bis 0.5.0 stand
    // hier „Anlegen kommt mit dem nächsten Schritt" — ein Satz aus der Zeit, in
    // der die Stackliste nur lesen konnte. Er blieb stehen, nachdem der Schritt
    // da war, und stand ausgerechnet auf dem frischen Server, auf dem er falsch
    // war.
    stacksLeer:
      "Auf diesem Server gibt es kein Compose-Projekt. „Stack anlegen“ schreibt das erste nach /opt/asylum/stacks.",
    stacksLeerNurLesen:
      "Einen Stack anlegen darf nur die Owner-Rolle — ein Compose-Stack ist Codeausführung als root.",
    stacksSuchen: "Stack- oder Dienstname",
    stacksNichts: "Kein Stack passt zur Suche.",
    verwaltet: "verwaltet",
    fremd: "fremd",
    // „Fremd" ist kein Vorwurf, sondern eine Zusage: An dieser Datei rührt das
    // Panel nicht. Wer den Unterschied nicht kennt, sucht sonst einen Knopf,
    // den es mit Absicht nicht gibt.
    fremdWarum:
      "Dieses Projekt hat jemand außerhalb des Panels angelegt. Es ist lesbar; geschrieben wird nur, was unter /opt/asylum/stacks liegt und den Marker des Panels trägt.",
    spalteDienste: "Dienste",
    spalteStatus: "Zustand",
    spalteHerkunft: "Herkunft",
    nieGestartet: "nie gestartet",
    vonWieviel: (laufend: number, gesamt: number) => `${laufend} von ${gesamt} laufen`,
    stackDatei: "Compose-Datei",
    stackGekuerzt:
      "Die Datei ist größer als die Anzeigegrenze und steht hier nur zum Teil.",
    keineDatei: "Zu diesem Projekt ist keine lesbare Compose-Datei da.",
    stackContainer: "Container dieses Stacks",
    keineContainer: "Zu diesem Stack läuft kein Container.",

    // Stacks bedienen
    stackUp: "starten",
    stackDown: "herunterfahren",
    stackDownVolumes: "herunterfahren + Volumes löschen",
    stackPull: "Images holen",
    stackRestart: "neu starten",
    stackLoeschen: "löschen",
    stackAnlegen: "Stack anlegen",
    stackBearbeiten: "bearbeiten",
    stackSpeichern: "speichern",
    stackAbbrechen: "abbrechen",
    stackNameFeld: "Name",
    stackNameHinweis:
      "Kleinbuchstaben, Ziffern, Bindestrich und Unterstrich. So verlangt es Compose selbst — ein anderer Name ließe sich anlegen, aber nie starten.",
    stackVorlage: "Vorlage",
    stackNameFehlt: "Ohne Namen lässt sich kein Stack anlegen.",
    vorlageGesperrt:
      "Die Vorlage lässt sich nicht mehr wechseln — sie würde den geschriebenen Text ersetzen.",
    stackNeuTitel: "Neuen Stack anlegen",
    stackFremdNichtAenderbar:
      "Dieses Projekt hat jemand außerhalb des Panels angelegt. Das Panel liest seine Datei, schreibt sie aber nicht — auch dann nicht, wenn sie an unserem Platz liegt.",

    // Der Compose-Prüfer
    prueferTitel: "Compose-Prüfer",
    prueferAbgelehnt: "Der Compose-Prüfer hat die Datei abgelehnt. Gespeichert wurde nichts.",
    // „Geprüft" und „in Ordnung" sind zwei verschiedene Fragen. Ein Satz, der
    // sie zusammenwirft, behauptet mehr, als der Prüfer weiß.
    prueferNichtGeprueft:
      "Die Datei ließ sich nicht als Compose lesen. Sie wurde damit nicht geprüft — das heißt nicht, dass sie in Ordnung ist.",
    prueferNurRoh:
      "Geprüft wurde nur die Rohdatei, nicht die von Compose aufgelöste Fassung. YAML-Anker, extends und env_file können daran vorbei.",
    prueferOK: "Keine Beanstandung.",
    prueferHinweise: "Nicht geprüft und aufgefallen",
    prueferAussen: "Zugriff auf Serververzeichnisse",
    prueferAblehnung: "Abgelehnt",
    prueferDienste: "Dienste",

    // Das Compose-Formular (Stufe C)
    //
    // Die Texte tragen die Last dieser Fläche: Ein Formular über einer
    // Konfigurationsdatei ist nur so gut wie das, was es über sich selbst sagt.
    // Deshalb steht hier für jede Sperre ein eigener Satz und nicht ein
    // allgemeines „nicht unterstützt".
    formTitel: "Felder",
    formText: "Datei",
    formBeides: "Felder und Datei",
    formAnsicht: "Ansicht",
    formDienstAnlegen: "Dienst hinzufügen",
    formDienstEntfernen: "Dienst entfernen",
    formDienstName: "Name des Dienstes",
    formDienstNameFalsch:
      "Ein Dienstname beginnt mit einem Buchstaben oder einer Ziffer; danach sind Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich erlaubt.",
    formDienstDa: "Einen Dienst dieses Namens gibt es schon.",
    formKeineDienste:
      "In dieser Datei steht noch kein Dienst. „Dienst hinzufügen“ legt den ersten an.",
    formImage: "Image",
    formRestart: "Neustartregel",
    formCommand: "Befehl",
    formPorts: "Ports",
    formVolumes: "Volumes",
    formUmgebung: "Umgebung",
    formAbhaengig: "Abhängig von",
    formNetze: "Netze",
    formZeileHinzu: "Zeile hinzufügen",
    formZeileWeg: "entfernen",
    // Beschriftung und Platzhalter getrennt: In der Spalte neben dem Texteditor
    // ist ein Feld rund hundert Pixel breit, und „Port auf dem Server" wäre
    // dort abgeschnitten. Die lange Fassung bleibt als aria-label stehen —
    // sichtbar kurz, vorgelesen vollständig.
    formAdresse: "Adresse",
    formWirtPort: "Port auf dem Server",
    formContainerPort: "Port im Container",
    formProtokoll: "Protokoll",
    formAdresseKurz: "Adresse",
    formWirtPortKurz: "Server",
    formContainerPortKurz: "Container",
    formProtokollKurz: "Proto",
    formQuelle: "Quelle",
    formZiel: "Ziel im Container",
    formModus: "Modus",
    formSchluessel: "Name",
    formWert: "Wert",
    formDienstname: "Dienst",
    formNetzname: "Netz",
    // Die drei Zustände, in denen das Formular NICHT schreibt. Jeder bekommt
    // seinen eigenen Satz, weil sie verschiedene Ursachen haben und
    // verschiedene Auswege.
    formNichtLesbar:
      "Die Datei ist gerade kein gültiges YAML. Die Felder sind bis dahin gesperrt — sie würden sonst aus einem halben Dokument schreiben.",
    formNurAnzeige: "Nur Anzeige",
    formRohzeile: "Diese Zeile steht in der langen Form und wird nicht angefasst.",
    formWeitere: (felder: string[]) =>
      `Dieses Formular zeigt nicht: ${felder.join(", ")}. Diese Felder bleiben unangetastet — sie stehen weiter in der Datei.`,
    formUnbedienbar: (felder: string[]) =>
      `Nicht bearbeitbar, weil sie hier in einer anderen Gestalt stehen: ${felder.join(", ")}. Im Texteditor lassen sie sich ändern.`,
    // Der Satz, der die ganze Fläche einordnet. Er steht einmal oben und nicht
    // an jedem Feld.
    formWesen:
      "Die Felder und die Datei sind dasselbe: Was hier steht, steht dort, und geändert wird immer die Datei. Kommentare, Einrückung und alles, was das Formular nicht zeigt, bleiben, wo sie sind.",
    formZweiterLeser:
      "Ob der Stack zulässig ist, entscheidet weiter der Prüfer auf dem Server — nicht dieses Formular.",

    flaecheUnbekannt:
      "Diese Fläche gibt es unter Docker nicht. Vielleicht ist der Verweis alt oder verschrieben.",

    // Ports
    ports: "Ports",
    portsLeer: "Kein Container veröffentlicht einen Port.",
    spaltePort: "Port",
    spalteAdresse: "gebunden an",
    spalteContainer: "Container",
    spalteUrteil: "erreichbar",
    portsUnbemerkt: "offen ohne Firewall-Regel",
    portsOffen: "offen und erlaubt",
    portsLokal: "nur lokal",
    portsPanel: "Panel",
    portsPanelWarum:
      "Über diesen Port läuft die Oberfläche. Ihn zu schließen wäre der Selbstausschluss.",
    portsOhneFirewall:
      "Auf diesem Server läuft keine Firewall. Zu den offenen Ports gibt es deshalb nichts abzugleichen.",

    // Ereignisse
    ereignisse: "Ereignisse",
    ereignisseWesen:
      "Was Docker gerade tut: Container, die starten und sterben, geholte Images, angelegte Volumes. Beantwortet die Frage, warum ein Container um 3 Uhr neu gestartet ist.",
    ereignisseZeigen: "Ereignisse ansehen",
    ereignisseWarte: "Warte auf Ereignisse …",
    spalteZeit: "Zeit",
    spalteAktion: "Aktion",
    spalteObjekt: "Objekt",
    ereignisFolgerVoll:
      "Es sehen schon zu viele Verbindungen dem Ereignisstrom zu. Bitte einen anderen Tab schließen.",
    ereignisVerworfen: (n: number) =>
      `${n} Ereignisse verworfen — Docker schreibt schneller als die Leitung überträgt.`,

    // Update-Prüfung
    // „Aktualität der Images" und nicht „Images": Unter Bestand steht schon
    // eine Liste dieses Namens, und zwei gleich benannte Überschriften auf einer
    // Seite lassen sich nicht auseinanderhalten.
    updates: "Aktualität der Images",
    updatesWesen:
      "Gibt es zu den Tags, die hier laufen, in den Registries etwas Neueres? Das Panel vergleicht Kennungen und tauscht nichts aus — den Knopf drückt ein Mensch.",
    updatesNieGeprueft:
      "Noch nicht geprüft. Der Vergleich läuft höchstens einmal am Tag, weil Docker Hub anonyme Abfragen zählt.",
    updatesPruefen: "jetzt prüfen",
    updatesGeprueftAm: "zuletzt geprüft am",
    updatesWiederAb: "wieder möglich ab",
    updatesNeu: "neuere Fassung",
    updatesAktuell: "aktuell",
    updatesUngeprueft: "nicht geprüft",
    // „Nicht geprüft" ist die ehrlichste der drei Zahlen — und der Satz dazu
    // sagt, warum sie keine Beruhigung ist.
    updatesUngeprueftWarum:
      "Zu diesen Images kam kein belastbarer Vergleich zustande. Das heißt nicht, dass sie aktuell sind.",
    spalteImageRef: "Image",
    spalteStand: "Stand",
    spalteGebrauch: "benutzt von",
    updatesAktualisieren: "Stack aktualisieren",
    updatesKeinGriff:
      "Dieser Container gehört zu keinem Compose-Projekt. Das Panel kann ihn hier nicht aktualisieren.",
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
} as const;
