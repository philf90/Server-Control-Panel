# Übergabe an P4 (TLS)

Dieses Dokument steht hier, weil eine Übergabe im Chatfenster mit dem Fenster
verschwindet. Es beantwortet die Fragen, die am Anfang von P4 ohnehin gestellt
werden — und es nennt die zwei Dinge, die man dabei falsch machen kann, ohne es
zu merken.

Alles darin ist im Quelltext nachgesehen und nicht aus der Erinnerung notiert.
Wo etwas ungeprüft ist, steht es als ungeprüft da.

---

## 1. Der Stand — und was daran nicht gemessen ist

Ausgeliefert wird `v0.3.1-rc.3`. Der Optik-Rework ist durch, die Meldungen vom
laufenden Server sind abgearbeitet.

**Der Abnahmelauf für `0.3.1-rc.x` steht aus, und das ist kein Formfehler.**
P4 fasst Zertifikate, den Vhost der Oberfläche und die Vorlage der Kundendomains
an — also genau die Stellen, an denen der jetzige Stand nie unter echten
Bedingungen gelaufen ist. Ohne diesen Nachweis ist bei jedem Fehlschlag in P4
offen, ob P4 ihn verursacht hat oder ob er schon vorher dalag. Diese
Zweideutigkeit kostet mehr als der Lauf.

Konkret ungeprüft aus dem Rework: ob die Schwellen der Verlaufskacheln unter
echter Last sinnvoll greifen (85 % CPU und RAM, Load gegen die wirkliche
Kernzahl), und wie die Übersicht mit laufenden Diensten und einem echten
Zertifikat aussieht. Für die Kacheln sind bisher nur Messwerte von Hand in den
Ringpuffer geschrieben worden.

**Und eine Sache greift erst nach `srvpanel setup`:** Die Sitzungswerte aus
`rc.2` — `SESSION_SAME_SITE=lax` und acht Stunden gleitende Dauer — schreibt
`panel.provision` in `/etc/srvpanel/panel.env`. `srvpanel update` schreibt diese
Datei **nicht** neu. Wer nur aktualisiert hat, läuft weiter mit `strict` und
120 Minuten, und die Sitzung überlebt auf dem Telefon keinen Seitenaufruf.

---

## 2. Was für TLS schon dasteht

| Ort | Was er kann |
|---|---|
| `agent/src/Ops/PanelTls.php` | stellt das selbstsignierte Zertifikat der Oberfläche aus, mit subjectAltName |
| `agent/src/Ops/PanelTlsInfo.php` | liest Aussteller, Namen und Ablauf eines vorhandenen Zertifikats |
| `app/Console/Commands/EnsureTls.php` | `srvpanel tls` — prüft und erneuert vor dem Ablauf, `--force` stellt neu aus |
| `app/Http/Controllers/TlsSettingsController.php` | die Seite „Zertifikat" mit Zustand und Neuausstellung |
| `agent/src/Names.php` | die **einzige** Stelle, die den Rechnernamen beantwortet (`HostnameSourceTest`) |
| `docs/27-zertifikat.md` | acht Abschnitte zum heutigen Zustand; §8 heisst „Was mit P4 dazukommt" |

**Zwei Vorbereitungen in der Kundenvorlage tragen HTTP-01 sofort**
(`agent/src/SiteTemplate.php`):

- Sie hört bereits auf **Port 80** (`listen 80; listen [::]:80;`).
- `.well-known` ist vom Punktdatei-Schutz ausgenommen, und der Kommentar dort
  nennt P4 namentlich: „dort legt die ACME-Prüfung ab P4 ihre Datei ab, und
  ohne diese Ausnahme bekäme die Domain nie ein Zertifikat."

Beides ist beim Bauen von P3 mit Blick auf P4 entstanden. Es ist die halbe
Miete für die Prüfung — nicht für die Auslieferung.

---

## 3. Die Falle, die sofort zubeisst: HSTS

`docs/27 §7` beschreibt sie ausführlich; hier steht, warum sie P4 betrifft.

`Strict-Transport-Security` ist eine Anweisung an den Browser, und der merkt
sie sich. Nimmt jemand das selbstsignierte Zertifikat in seinen Speicher auf —
und dazu ist es da —, ist die Verbindung vertraut, der Header wird gespeichert,
und **ab da lässt sich auf diesem Host kein Zertifikatsfehler mehr wegklicken**:
kein „trotzdem fortfahren", keine Ausnahme. Das nächste neu ausgestellte
Zertifikat sperrt den Betreiber aus seinem eigenen Panel aus. Der Ausweg war ein
Inkognitofenster.

Deshalb liest `panel.vhost.apply` heute das Zertifikat, **bevor** es den
Server-Block schreibt: Aussteller gleich Inhaber heisst selbstsigniert heisst
kein HSTS. Unlesbar zählt dabei als selbstsigniert — wer aus einem Zertifikat,
das er nicht lesen kann, auf eine Zertifizierungsstelle schliesst, verspricht
das Jahr auf Verdacht.

**Was das für P4 heisst**, und es ist leicht zu übersehen: Mit dem ersten
vertrauten Zertifikat wird HSTS richtig und kommt von selbst — aber nur, wenn
der Server-Block danach neu geschrieben wird. **Wer ein ACME-Zertifikat
einspielt, ohne `panel.vhost.apply` danach zu rufen, bekommt ein vertrautes
Zertifikat ohne den Header.** Das ist der harmlosere der beiden Ausgänge, und
genau deshalb bemerkt ihn niemand.

---

## 4. Was fehlt

Aus dem Plan (§9, P4):

- ACME: HTTP-01 für alle Domains, DNS-01 für Wildcards
- DNS-01 gegen die eigene Zone (nach P7 automatisch) und gegen externe Anbieter
  über API
- Erneuerung als Zeitplan, mit Warnung und Protokoll
- Eigenes Zertifikat hochladen, Kette prüfen, Ablauf anzeigen
- Zertifikat für die Panel-Fläche selbst
- HSTS, Weiterleitung auf HTTPS, moderne Chiffren, OCSP-Stapling

Dazu, aus dem Quelltext gelesen und im Plan nicht ausgeschrieben: In
`SiteTemplate` gibt es heute **kein** `ssl_certificate` und keine Weiterleitung
auf HTTPS. Eine Kundendomain spricht Klartext. Das ist der Teil, der die Vorlage
selbst anfasst — und damit `SiteTemplateTest` und `PhpIsolationTest` berührt,
die die erzeugte Zeichenkette als Text prüfen.

---

## 5. Das Abnahmekriterium, und wie man es misst

> **Fertig, wenn** ein Kunde ohne Zutun des Admins für seine Domain ein
> Zertifikat erhält, die Erneuerung ohne Ausfall läuft und ein Fehlschlag den
> laufenden Betrieb nicht unterbricht.

Der dritte Teil ist der, den man beim Bauen vergisst. Eine gescheiterte
Erneuerung — abgelaufenes ACME-Konto, Ratenbegrenzung, DNS kurz weg — darf die
Domain nicht offline nehmen. Der Prüfweg dafür gehört in den Abnahmelauf und
nicht in eine Notiz: Erneuerung absichtlich scheitern lassen und nachsehen, dass
die Seite weiter ausgeliefert wird.

Der Plan verlangt den Nachweis auf einem echten Server, nicht die Schätzung
(§8 und §9).

---

## 6. Entscheidungen, die der Betreiber trifft

Sie gehören in den ersten Prompt, sonst rät die Session:

1. **ACME-Verzeichnis:** Staging zuerst oder gleich produktiv? Let's Encrypt
   begrenzt produktiv hart — unter anderem fünf Fehlversuche je Konto und
   Stunde. Wer beim Bauen produktiv testet, steht schnell vor einer Sperre, die
   Stunden hält.
2. **Konto und Kontaktadresse** für ACME — und ob ein Konto je Server oder eines
   für alle. Das ist eine Betriebsentscheidung und keine technische.
3. **DNS-01:** welcher Anbieter zuerst. Die eigene Zone kommt erst mit P7; bis
   dahin braucht ein Wildcard einen externen Anbieter mit API.
4. **Eigenes Zertifikat hochladen:** in P4 oder später. Es steht in der
   Stufenliste, hängt aber an nichts, was ACME braucht.

---

## 7. Was diese Umgebung nicht kann

Steht ausführlich in `CLAUDE.md`; für P4 zählt vor allem:

- **Kein nginx, kein PHP-FPM, kein Agent, kein systemd.** Vorlagen werden
  deshalb als Text geprüft — der Schutz ist eine Eigenschaft der erzeugten
  Zeichenkette, nicht des laufenden Servers. Für P4 heisst das: Der
  ACME-Ablauf lässt sich hier nicht durchspielen, die erzeugte Konfiguration
  schon.
- **PHPStan ist nicht installiert** und lässt sich hinter dem Proxy nicht
  nachinstallieren (`composer install` scheitert an „Could not authenticate
  against github.com"). Jede Änderung an `app/`, `agent/` oder `tests/` kostet
  damit eine Runde CI. An einem einzigen Tag ist das viermal passiert.
- **Privates Schlüsselmaterial wird in diesem Container nie erzeugt.** Für P4
  gilt das doppelt: ACME-Kontoschlüssel entstehen auf dem Zielserver, im
  Agenten, und überqueren den Socket nie — dieselbe Regel wie beim
  Datenbankpasswort und beim `APP_KEY` (`PanelProvision`).

---

## 8. Die Gewohnheit, die auch P4 tragen muss

Für jede Regel ein Wächter, und der Wächter wird gegengeprüft. P4 bringt
mindestens drei Regeln mit, die ohne Werkzeug verfallen:

- Ein ACME-Zertifikat wird eingespielt **und** der Server-Block danach neu
  geschrieben (sonst fehlt HSTS, siehe §3).
- Eine gescheiterte Erneuerung nimmt keine Domain offline.
- Der Kontoschlüssel verlässt den Agenten nicht.

Alle drei sind Zusagen, denen im laufenden Betrieb nichts entspricht, solange
niemand danach fragt — genau das Muster, das dieses Projekt sechsmal getroffen
hat. `tests/waechter-brechen.sh` ist der Ort, an dem der Bruch dazukommt.
