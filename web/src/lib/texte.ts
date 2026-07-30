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
    einstellungen: "Einstellungen",
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
    // Ehrlich benannt: Die Palette sucht heute Ziele, keine Dienste. Wer das
    // erwartet und nichts findet, hält die Suche für kaputt.
    spaeter: "Dienste und Dateien folgen mit ihren Modulen",
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
