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
  },

  fehler: {
    laden: "Die Daten konnten nicht geladen werden.",
    abgemeldet: "Die Sitzung ist abgelaufen. Bitte neu anmelden.",
    erneut: "Erneut versuchen",
  },

  vorschau:
    "Neue Oberfläche im Aufbau — die alte bleibt unter / erreichbar und unverändert.",
} as const;
