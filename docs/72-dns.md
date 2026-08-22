# P7 — DNS-Abgleich

Geschrieben am 21. August 2026. **Die erste Fassung dieses Dokuments plante einen
eigenen autoritativen Nameserver** — PowerDNS über die HTTP-API, Zonenvorlage,
Eintragseditor, DNSSEC. Sie ist am selben Tag verworfen worden, nachdem der
Betreiber gefragt hat, ob das überhaupt Sinn ergibt, bevor es zu erheblichen
Problemen kommen kann.

**Die Antwort war nein**, und die Begründung steht in §1. Die Messrunde
(`docs/71`) behält ihren Wert: Sie ist die Grundlage dieser Entscheidung, nicht
ihr Abfall.

---

## 1. Warum P7 keinen eigenen Nameserver bekommt

Die Entscheidung fiel am 21. August 2026 auf die Rückfrage des Betreibers hin.
Vier Gründe, drei davon gemessen.

### 1.1 Der Ausfallschaden wird nicht grösser, er wird anders

Heute gilt: `cloudsrv24` weg → die Websites sind weg. Mit eigener Zone auf einer
Maschine gilt: `cloudsrv24` weg → **die Namen selbst sind weg**. Das nimmt Dinge
mit, die gar nicht hier liegen — ein MX auf einen fremden Mailanbieter, eine
Subdomain auf ein CDN, ein TXT für irgendeine Verifikation.

Das Panel würde damit zum Einzelpunkt für Infrastruktur, die es nicht betreibt.

> **Ein Dienst, dessen Ausfall Dinge mitnimmt, die er nicht betreibt, hat eine
> andere Art von Verantwortung als einer, der nur sich selbst mitnimmt.**

### 1.2 Und das ist gemessen, nicht befürchtet

Aus `docs/71 §4.4`: Fällt MariaDB weg, bedient PowerDNS noch rund zwanzig
Sekunden aus seinem Zwischenspeicher und fällt dann auf `SERVFAIL`. Es ist
dieselbe MariaDB wie die des Panels (das war Entscheidung 3). Ein gewöhnlicher
Datenbank-Neustart beim Update nimmt die Zone also für zehn bis fünfzehn
Sekunden dunkel.

Das ist kein Katastrophenfall. Das ist ein Dienstag.

### 1.3 DNSSEC ist die schärfste Kante, und das Panel kann sie nicht abstumpfen

Gemessen (`docs/71 §4.5`): PowerDNS legt einen **CSK** an, kein KSK/ZSK-Paar.
Damit zieht **jeder** Schlüsselwechsel einen neuen DS beim Registrar nach sich —
und den kann das Panel nicht setzen, nur anzeigen. Ein falscher Wechsel heisst
harter Fehlschlag für jeden validierenden Auflöser, und die Erholung braucht den
Registrar plus die Haltbarkeit des DS.

Die zweistufige Führung aus der ursprünglichen Entscheidung 7 verkleinert dieses
Fenster. Sie schliesst es nicht.

### 1.4 Und eine Frage, die die Sache ohnehin entschieden hätte

`docs/70 §5` hatte notiert, dass viele Registries zwei Nameserver in
verschiedenen Netzen verlangen. Die Antwort darauf war „ns1 und ns2, beide auf
`cloudsrv24`, und schreib ehrlich hin, dass es kein Ausfallschutz ist" — eine
Entscheidung über die **Darstellung**. Ob die Delegierung so überhaupt
angenommen wird, hat niemand geprüft.

Bei deutschsprachiger Zielgruppe heisst das `.de` und damit DENIC, die die
Nameserver bei der Delegierung prüft. **Das ist ungemessen geblieben**, und es
steht hier als das, was es ist: kein Argument, sondern eine offene Frage, die
gross genug war, um die Abwägung zu kippen.

> **Eine Entscheidung über die Darstellung eines Risikos ist keine Entscheidung
> über das Risiko.**

### 1.5 Was dabei aufgegeben wird — und was nicht

**Nicht aufgegeben:** Wildcard-Zertifikate über DNS-01. Das ist seit P4 gebaut,
acht Anbieter plus RFC 2136 (`docs/34 §6`). Dafür braucht es die eigene Zone
nicht.

**Aufgegeben wird ein Satz aus `docs/20 §9`:** „eine neu angelegte Domain ist
ohne weiteres Zutun auflösbar". Sein ehrlicher Ersatz steht im selben Absatz
schon als eigene Betriebsart — *das Panel verwaltet nichts, zeigt aber die
nötigen Einträge zum Abgleich*. Genau das wird P7.

---

## 2. Was P7 wird

**Das Panel führt keine Zone. Es weiss, was eine Domain braucht, und sieht nach,
ob es da ist.**

Drei Teile:

1. **Der Sollzustand** — welche Einträge diese Domain braucht, damit sie hier
   funktioniert. Das weiss das Panel ohnehin; bisher stand es nirgends.
2. **Der Istzustand** — gemessen an den autoritativen Nameservern der Zone, nicht
   am Systemauflöser.
3. **Der Vergleich**, mit dem gefundenen Wert daneben und dem Zeitpunkt der
   Messung.

### 2.1 Der Sollzustand

> **Berichtigt am 21. August 2026, vor dem Bauen von Schritt 3.** Hier stand
> eine Zeile „`A`/`AAAA` auf `www` — der Standard-Vhost bedient beide". **Das ist
> falsch, und ich hatte es angenommen statt nachgesehen.** `Site::serverNames()`
> gibt `array_merge([$this->domain], $this->aliases)` zurück: die Domain und
> ihre **ausdrücklichen** Aliasse. Ein automatisches `www` legt dieses Panel
> nirgends an.
>
> **Die Regel wird dadurch einfacher, nicht komplizierter** — und richtiger: Ein
> Alias ist in diesem System eine eigene Zeile in `domains` (`type = alias`,
> `parent_domain_id`), genau wie eine Subdomain. Es gibt also gar keinen
> Sonderfall; jede Zeile braucht Adressen auf ihren eigenen Namen.
>
> > **Wissen aus zweiter Hand sieht aus wie Wissen** — auch das eigene von
> > vorgestern.

| Eintrag | Wert | Warum |
|---|---|---|
| `A` auf den Namen der Domain | IPv4 des Servers | Ohne ihn ist die Website nicht erreichbar |
| `AAAA` auf den Namen der Domain | IPv6 des Servers | **Nur wenn der Server eine hat** — sonst ist sein Fehlen kein Befund |
| `CAA` | **wird geprüft, nicht gefordert** | §2.4, und deshalb kein Teil des Sollzustands |

**Die Regel in einem Satz:** Jede Zeile in `domains` — Haupt-, Zusatzdomain,
Subdomain **und Alias** — wird von nginx unter ihrem eigenen Namen bedient und
braucht deshalb Adressen auf genau diesen Namen. Ein `www`, das der Kunde will,
legt er als Alias an; dann steht es von selbst im Sollzustand.

**Kein MX, kein SPF, kein DMARC.** Mailversand ist laut `docs/20` eine spätere
Stufe, und ein Sollwert für etwas, das es nicht gibt, ist ein Fehler mit
Ansage.

**Die erwarteten Adressen werden vereinheitlicht abgelegt.** `2001:0db8::0001`
und `2001:db8::1` sind dieselbe Adresse und nicht dieselbe Zeichenkette; der
Abgleich vergleicht Zeichenketten. Beide Seiten gehen deshalb durch
`inet_ntop(inet_pton(…))` — die gemessene in {@see Packet::addresses}, die
erwartete an ihrer Quelle.

> **Zwei Werte, die dasselbe bedeuten und verschieden geschrieben sind, ergeben
> einen Befund, den es nicht gibt.**

### 2.1a Woher die Adressen kommen

Entschieden am 21. August 2026: **abgeleitet, mit Übersteuerung.**

Der Agent liefert die Adressen der Schnittstellen, gefiltert auf öffentliche —
also ohne `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`,
`fc00::/7` und `fe80::/10`. Der Betreiber sieht sie auf der Seite und kann sie
übersteuern; **leer heisst „nimm die abgeleiteten"**.

**Warum nicht nur abgeleitet.** Hinter NAT, einer Floating-IP oder einem
Lastverteiler ist die Adresse, unter der ein Server von aussen erreichbar ist,
von innen nicht zu erfahren. Ohne Übersteuerung meldete der Abgleich dort jede
Domain als „zeigt woandershin" — und zwar mit der **privaten** Adresse als
Sollwert. Das ist schlimmer als keine Anzeige: Es sieht aus wie eine Auskunft.

**Warum nicht nur eingestellt.** Ohne Ableitung funktioniert der Abgleich vor
dem ersten Eintrag gar nicht, und das trifft ausgerechnet die Ersteinrichtung —
den Zeitpunkt, an dem jemand am ehesten wissen will, ob seine Domain schon
hierher zeigt.

**Der Preis steht daneben und gehört in die Oberfläche:** Eine übersteuerte
Adresse ist eine gemerkte Fassung eines Serverzustands, und die kann veralten.
Bekommt der Server eine neue Adresse, zeigt der Abgleich weiter auf die alte —
und meldet jede Domain als falsch, die in Wahrheit richtig steht. Die Seite
zeigt deshalb **beide**: was eingetragen ist und was abgeleitet würde.

> **Eine im Panel gemerkte Fassung eines Serverzustands ist die, die veraltet**
> — derselbe Satz, der in `Settings` schon über `bind-address` und den
> PostgreSQL-Schalter steht. Er verbietet die Übersteuerung nicht; er verlangt,
> dass man sieht, wenn sie nicht mehr stimmt.

### 2.2 Der Istzustand — und warum nicht der Systemauflöser

`agent/src/Acme/Dns/Resolver.php` gibt es seit P4, und sein Kopfkommentar sagt
den Grund schon: Der Systemauflöser antwortet aus seinem Zwischenspeicher, und
der kann den Namen von vorhin noch als „gibt es nicht" führen.

**Für diese Frage ist das besonders schädlich.** Der Kunde stellt seinen Eintrag
beim Anbieter um, sieht im Panel nach — und das Panel sagt weiter „zeigt
woandershin". Er stellt ihn zurück, und dann ist es wirklich falsch.

> **Ein Zwischenspeicher, der eine Anleitung beantwortet, macht aus einer Hilfe
> eine Irreführung.**

Gefragt werden deshalb die autoritativen Server der Zone, wie bei `ready()`.

**Was dafür zu bauen ist:** `Packet` kann heute genau einen Satztyp lesen — TXT
(`Packet::TYPE_TXT`, „der einzige Satztyp, den diese Prüfung braucht").
Dazukommen **A, AAAA und CAA, ausschliesslich lesend**. Das sind drei
Rdata-Formate: vier Bytes, sechzehn Bytes, und ein Flag mit Marke und Wert. Kein
Schreiben, kein Aktualisieren, keine Signatur.

Das ist der ganze technische Kern dieser Stufe.

### 2.3 Der Vergleich und seine drei Zustände

Je Eintrag genau drei, und die Unterscheidung ist die halbe Miete:

| Zustand | Was er heisst |
|---|---|
| **zeigt hierher** | Der Wert ist der erwartete |
| **zeigt woandershin** | Es gibt einen Wert, und er ist ein anderer — **mit dem gefundenen daneben** |
| **fehlt** | Es gibt keinen Wert |

**„Zeigt woandershin" ist kein Fehler.** Ein Kunde, der seine Domain absichtlich
über Cloudflare oder ein CDN führt, hat genau diesen Zustand und will keine rote
Meldung. Die Anzeige sagt, was ist, und nicht, was falsch ist — die Wertung
gehört dorthin, wo sie eine Folge hat (die Website antwortet nicht, das
Zertifikat lässt sich nicht bestellen).

> **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt, behauptet
> etwas, das sie nicht weiss** — und eine, die einen davon als Fehler malt,
> auch.

### 2.4 CAA — der Fall, der eine Bestellung rettet

Ein CAA-Satz, der die eigene Zertifizierungsstelle nicht nennt, lässt die
Bestellung scheitern, und zwar mit einer Meldung, die von der Zone spricht und
nicht vom Panel. Das Panel setzt hier nichts — es **liest** und meldet den einen
Fall, der etwas kostet:

- kein CAA → alles erlaubt, in Ordnung, keine Meldung;
- CAA, das die eigene CA nennt → in Ordnung;
- CAA, das sie **nicht** nennt → Hinweis, **bevor** eine Bestellung daran
  scheitert.

Jeder Fehlversuch zählt bei Let's Encrypt fünf pro Konto und Stunde — und die
gelten für jeden Kunden dieses Servers (`docs/34 §11`). Ein Hinweis vorher ist
deshalb nicht Komfort, sondern Schadensbegrenzung.

### 2.5 Wann gemessen wird

**Nicht bei jedem Seitenaufruf.** Das hinge die Seite an fremden Nameservern und
machte aus einem Klick eine Reihe von UDP-Anfragen mit fremden Zeitlimits.

Stattdessen: ein Vorgang, der auf Wunsch läuft und regelmässig — und das
Ergebnis trägt **den Zeitpunkt seiner Messung sichtbar**.

> **Eine Antwort aus dem Zwischenspeicher ist eine Aussage über vorhin** — und
> wenn sie das ist, sagt sie es auch.

### 2.5a Die Grenze des regelmässigen Laufs

**Gebaut am 22. August 2026 als Schritt 7.** `srvpanel dns-check` fährt einen
Durchgang, gestartet von `srvpanel-dns.timer` alle fünfzehn Minuten — dieselbe
Bauart wie die drei Timer davor: `Type=oneshot`, kein Dauerlauf, kein Eintrag in
cron (das Panel verwaltet cron und hängt seine eigene Verwaltung nicht hinein).

**Die Grenze besteht aus zwei Zahlen, und die naheliegende allein wäre falsch.**
„So viele Domains je Lauf" deckelt eine Zahl, die die Dauer nicht bestimmt: Eine
Domain hat einen Namen oder zwölf — jeder Alias ist ein eigener Aufruf —, und
ihre Nameserver antworten in Millisekunden oder gar nicht.

> **Eine Grenze über die Zahl der Vorgänge ist keine über ihre Dauer, solange
> der einzelne Vorgang unterschiedlich lange braucht.**

Also beides, und die Frist ist die, die hält:

| Zahl | Wert | Wo |
|---|---|---|
| Domains je Lauf | 25 | `Budget::DOMAINS` |
| Frist je Lauf | 240 s | `Budget::SECONDS` |
| Reserve je Name | Server × Zeitlimit = 20 s | `Budget::reserve()`, gefragt bei `DnsCheck::MAX_SERVERS` und `Resolver::TIMEOUT_SECONDS` |
| Frist der Unit | 600 s | `TimeoutStartSec` in `srvpanel-dns.service` |
| Takt | 15 min | `OnCalendar` in `srvpanel-dns.timer` |
| Frühestens wieder | 60 min | `Sweep::FRESH_MINUTES` |

**Die Reserve ist der Teil, der leicht fehlt.** „Noch Zeit übrig" vor einem
Vorgang unbekannter Dauer sagt nichts über sein Ende — gerechnet wird deshalb
mit dem schlimmsten Fall *dieser* Domain, und der hängt an der Zahl ihrer Namen.

> **Eine Frist, die vor einem Vorgang unbekannter Dauer geprüft wird, ist
> eingehalten, solange niemand misst, wann er endet.**

**Und die Reserve gilt nicht für die erste Domain.** Eine mit zwölf Aliassen
hat eine Reserve von 240 Sekunden — genau die Frist. Ohne die Ausnahme käme sie
in keinem Lauf an die Reihe, für immer, und im Bericht stünde nur „wartet noch".

> **Eine Reserve, die den ersten Vorgang verhindert, macht aus einer Grenze eine
> Sperre.**

**Die Reihenfolge ist die andere Hälfte der Grenze:** erst, wer noch nie
gemessen wurde, dann der älteste Befund. Ein Deckel ohne Reihenfolge bevorzugt
immer dieselben Domains, und der Bericht meldete trotzdem jeden Lauf
„25 geprüft".

> **Eine Obergrenze ohne Reihenfolge ist keine Begrenzung, sondern eine
> Bevorzugung.**

**Drei der sechs Zahlen stehen in drei Dateien, die nichts voneinander wissen.**
`DnsBudgetTest` hält sie aneinander: Die Frist der Unit muss über der des
Quelltextes liegen, und der Takt über beiden — sonst räumt systemd den Lauf
mitten in einer Messung ab, oder der nächste Termin fällt in einen noch
laufenden Dienst und fällt lautlos aus.

> **Zwei Fristen über denselben Lauf, die nichts voneinander wissen, entscheidet
> die kleinere — und die steht woanders.**

**Was der Lauf nicht filtert, ist die Sorte.** Ein Alias wird mitgemessen,
obwohl seine Namen schon unter seiner Elterndomain gefragt wurden — er hat eine
eigene Seite mit einem eigenen Abgleich, und wer ihn überspränge, liesse genau
diese Seite für immer auf „noch nie geprüft" stehen. Übergangen wird allein, was
gerade zurückgebaut wird.

### 2.6 Die eine Operation

`dns.check` — der Agent fragt die autoritativen Server und gibt zurück, was er
gefunden hat. Ausgehende Verbindungen gehören nach Grenze 1 in den Agenten, auch
UDP auf Port 53.

**Eine Operation und keine neun.** Das ist der Umfangsunterschied zwischen dieser
Fassung und der verworfenen.

### 2.7 Wo das steht

**An der Domain**, als Teil ihrer Seite — nicht als eigener Menüpunkt und nicht
als Unterseite davon. Dreimal in P6 ist ein Merkmal drei Klicks tief gelandet und
musste verlegt werden (`docs/55` Befund 8, `docs/59` Befund 19, `docs/64`
Befund 13); jedes Mal hat es der Betreiber gemeldet und kein Test.

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?**

Der Ort ist derselbe, an dem der Kunde ohnehin steht, wenn er sich fragt, warum
seine Seite nicht erscheint.

---

## 3. Das Abnahmekriterium

Acht Punkte, **alle auf `cloudsrv24` messbar** — ohne Registrar, ohne DS, ohne
fremde Mitwirkung. Das ist der zweite grosse Unterschied zur verworfenen
Fassung, deren Punkt 8 von einem Dritten abhing.

| # | Punkt | Gemessen woran |
|---|---|---|
| 1 | Eine Domain, deren `A` hierher zeigt, wird als „zeigt hierher" angezeigt | eine echte Domain auf `cloudsrv24` |
| 2 | Eine Domain, deren `A` woandershin zeigt, wird als solche angezeigt — **mit dem gefundenen Wert** | eine Domain, deren `A` absichtlich anders steht |
| 3 | Eine Domain ohne `A` wird von einer mit falschem `A` unterschieden | die beiden Zustände nebeneinander |
| 4 | Die Prüfung fragt die autoritativen Server und nicht den Systemauflöser | Wächter **und** eine Messung: eine Änderung ist sichtbar, bevor ein Auflöserzwischenspeicher sie hätte |
| 5 | Ein fehlendes `AAAA` wird nicht als Fehler gemeldet, wenn der Server keine IPv6 führt | beide Fälle |
| 6 | Ein `CAA`, das die eigene CA nicht nennt, wird gemeldet, bevor eine Bestellung daran scheitert | ein gesetztes fremdes CAA |
| 7 | Der Zeitpunkt der Messung steht dabei | die Anzeige |
| 8 | Bei 390 px läuft nichts über, in beiden Themes | `tests/bilder-messen.js` |

**Punkt 4 ist der, der etwas beweist.** Alles andere liesse sich auch mit
`dns_get_record()` bauen — und wäre dann genau die Anzeige, die dem Kunden
zwanzig Minuten lang das Gegenteil dessen sagt, was er gerade eingestellt hat.

---

## 4. Was P7 ausdrücklich **nicht** wird

- **Kein autoritativer Nameserver**, kein PowerDNS, keine Zone, kein
  Eintragseditor, kein DNSSEC, kein AXFR, kein NOTIFY. §1 ist die Begründung.
- **Keine Änderung an fremden Zonen** — auch nicht dort, wo das Panel es
  könnte. Für acht Anbieter liegen seit P4 Zugangsdaten vor, und ein `A`-Eintrag
  wäre technisch derselbe Aufruf wie ein `TXT`. **Das ist der naheliegende
  nächste Schritt und ausdrücklich nicht dieser** — er macht aus einer Anzeige
  wieder einen Schreiber, und die Frage „wer hat meinen Eintrag geändert" ist
  eine, die man einmal falsch beantwortet und nie wieder los wird. Wenn der
  Abgleich sich bewährt, ist das eine eigene Stufe mit eigener Entscheidung.
- **Kein Mail** (MX, SPF, DMARC) — spätere Stufe.
- **Kein PTR.** Die Rückwärtszone gehört dem, dem das Netz gehört.
- **Keine Wertung fremder Betriebsarten.** Wer über ein CDN fährt, bekommt
  „zeigt woandershin" und keine rote Meldung (§2.3).

---

## 5. Datenmodell

Klein, und das ist der Punkt:

```
domain_dns_checks     domain_id, checked_at, findings (json)
```

**Kein Spiegel einer Zone**, weil es keine Zone gibt. Was hier steht, ist das
Ergebnis einer Messung mit ihrem Zeitpunkt — und ein Ergebnis ohne Zeitpunkt
wäre eine Behauptung über jetzt.

`domains` bekommt **keine neue Spalte**. Die verworfene Fassung brauchte
`dns_mode`, `dnssec_state` und `dns_record_pins`; davon bleibt nichts, weil das
Panel nichts mehr führt, was es zurücknehmen könnte.

---

## 6. Die Rechte

**Keine neue Freigabe.** Der Kunde sieht nur, und sehen darf er seine eigene
Domain ohnehin. `Feature::DnsEdit` behält seine heutige Bedeutung — ein eigenes
DNS-01-Profil für ACME.

**Die falsche Beschriftung gehört trotzdem berichtigt, und jetzt mehr als
vorher.** `Feature::DnsEdit` heisst seit P4 **„DNS-Einträge bearbeiten"** und tut
etwas anderes: Es gibt einem Abonnement die Ablage fremder Registrar-Token. In
der verworfenen Fassung wäre daneben eine zweite Freigabe entstanden, die den
Namen zu Recht getragen hätte. Jetzt wird es **nie** einen Eintragseditor geben —
und eine Beschriftung, die einen verspricht, ist dann keine Ungenauigkeit mehr,
sondern schlicht falsch.

Sie heisst künftig **„Eigene DNS-Zugangsdaten für Zertifikate"**.

> **Eine Beschriftung, die etwas anderes verspricht als der Code tut, ist eine
> Zusage, die niemand eingelöst hat.**

---

## 7. Die Wächter

| Wächter | Regel |
|---|---|
| `AuthoritativeLookupTest` | Der Abgleich fragt `Resolver` und nirgendwo `dns_get_record()` für die Werte |
| `RecordRdataTest` | A, AAAA und CAA werden aus gebauten Paketen richtig gelesen — und ein abgeschnittenes ergibt kein Ergebnis statt eines falschen |
| `DesiredRecordSourceTest` | Der Sollzustand steht an einer Stelle und wird nicht je Ansicht neu gerechnet |
| `CheckAgeTest` | Kein Ergebnis wird ohne seinen Zeitpunkt angezeigt |
| `NoZoneWriteTest` | Nichts unter `app/` oder `agent/` schreibt in eine fremde Zone — die acht Anbieter bleiben dem ACME-Weg vorbehalten |
| `DnsBudgetTest` | Die Grenze des regelmässigen Laufs hält — und die drei Fristen in drei Dateien passen zueinander (§2.5a) |
| `DnsSweepTest` | Der Lauf misst ohne angemeldetes Konto, in der Reihenfolge des Alters, und ein Fehlschlag beendet ihn nicht |

`NoZoneWriteTest` ist der, der §4 zweiten Punkt festhält: Der bequemste Weg
später ist, `Cloudflare::add()` einfach auch für einen `A`-Eintrag zu benutzen.
Der Wächter macht daraus eine Entscheidung statt einer Handbewegung.

---

## 8. Die Schritte

| # | Schritt |
|---|---|
| 1 | `Packet` um A, AAAA und CAA erweitern, lesend — mit `RecordRdataTest` und gebauten Paketen |
| 2 | `dns.check` im Agenten, gegen den vorhandenen `Resolver` |
| 3 | Der Sollzustand als eine Quelle, mit `DesiredRecordSourceTest` |
| 4 | Die Adressquelle (§2.1a) und der Vergleich mit seinen drei Zuständen |
| 5 | Die Anzeige an der Domain, mit dem Zeitpunkt |
| 6 | Der CAA-Fall |
| 7 | Die regelmässige Messung und ihre Grenze — Kommando, Timer, Reihenfolge (§2.5a) |
| 8 | Zwischenabnahme auf `cloudsrv24` — der Lauf steht in **`docs/73`** |
| 9 | Bilderrunde, beide Themes, 390 und 1440 px |
| 10 | Abnahmelauf auf `cloudsrv24`, Protokoll **während** des Laufs |

**Schritt 8 stand hier zuerst nicht da**, und die Begründung dafür war falsch.
Sie lautete: Nichts an dieser Stufe stehe auf einem Dienst, den der Container nur
als Wegwerf-Fassung kennt — der `Resolver` laufe hier gegen echte Nameserver.
Das stimmt für den `Resolver` und für sonst nichts. Migration, Modell,
Controller, Route, Bereich, Kommando und Timer sind auf keinem Server je
gelaufen; `vendor/` fehlt in diesem Container, also hat auch kein Feature-Test
sie angefasst.

> **Eine Begründung, die für einen Teil stimmt, ist keine für das Ganze.**

Vor die Bilderrunde gehört deshalb ein kurzer Lauf auf `cloudsrv24`: Migration
einspielen, `systemctl list-timers srvpanel-dns.timer` lesen,
`srvpanel dns-check` von Hand fahren, den Bereich an einer echten Domain
ansehen. Und dabei die eine Zahl messen, die dieser Container nicht kennt:
`systemctl show srvpanel-dns.service -p TimeoutStartUSec` — die Vorgabe für
`Type=oneshot` steht hier nur in der Dokumentation und ist ungemessen.

---

## 9. Die Risiken

Deutlich kleiner als in der verworfenen Fassung, aber nicht null:

1. **Fremde Nameserver antworten langsam oder gar nicht.** Der Abgleich hängt an
   Servern, die niemand hier betreibt. Zeitlimit und Obergrenze je Lauf gehören
   in Schritt 7 — und ein Ergebnis „nicht erreichbar" ist ein eigener Zustand,
   nicht „fehlt".
2. **Ein falsch gelesenes Rdata zeigt einen falschen Zustand.** Harmlos für die
   Zone, aber es schickt den Kunden dorthin, wo nichts zu ändern ist. Deshalb
   `RecordRdataTest` gegen gebaute Pakete und nicht gegen echte Antworten.
3. **Die Anzeige kann in beide Richtungen irreführen** — „zeigt woandershin" bei
   einem Kunden, der das absichtlich tut, und „zeigt hierher" bei einem, dessen
   Anbieter mehrere Adressen führt und nur eine davon hierher zeigt. Der zweite
   Fall gehört benannt: Ein Satz mit mehreren Werten wird als Menge verglichen
   und nicht am ersten.
4. **Der Zeitpunkt kann alt sein**, und dann ist die Anzeige eine Aussage über
   vorhin. Deshalb steht er dabei.

---

## 10. Was aus der verworfenen Fassung bleibt

- **`docs/70`** — die Rückfragen und die elf Entscheidungen. Sie sind die
  Vorgeschichte dieser Entscheidung und bleiben stehen; §13 trägt die Revision
  nach.
- **`docs/71`** — die Messrunde. Ihre Zahlen sind der Grund für §1.2 und §1.3.
  Ein Messmittel, das eine Entscheidung *gegen* etwas trägt, ist genauso viel
  wert wie eines dafür.
- **`tests/dns-messen.sh`** — bleibt im Repo. Wer die Frage in einem Jahr noch
  einmal aufmacht, fängt nicht bei null an.
- **Der Wächter über `Curl`** — die Ausnahme ist zurückgebaut, aber die Regel
  „nach draussen nur https" hat dabei zum ersten Mal einen Test bekommen
  (`OutboundHttpsOnlyTest`). Sie stand von P4 bis P7 ungeprüft da.

> **Ein Plan, den man verwirft, ist bezahlt — was man behält, entscheidet, ob er
> auch teuer war.**

---

## 11. Was offen bleibt

- **Die DENIC-Frage aus §1.4** ist ungemessen und bleibt es. Sie gehört
  beantwortet, bevor jemand die Entscheidung noch einmal aufmacht — nicht
  danach.
- **Die zwei Servermessungen aus `docs/70 §14`** werden für diese Fassung nicht
  mehr gebraucht: keine PowerDNS-Fassung, kein Port 53. Sie bleiben dort als
  Vorarbeit für den Fall stehen, dass die Frage wiederkommt.
- **Das Schreiben fremder Zonen über die vorhandenen Anbieter-Zugangsdaten**
  (§4) — der naheliegende nächste Schritt, ausdrücklich nicht dieser.
- Und aus P6, weiterhin benannt (`docs/69 §3`): Wand 2 aus Punkt 11, Befund 23,
  die neunzehn ungeprüften Griffe in `RevealTest::UNEXAMINED`, die vollständige
  Umkehrung der Abstandsregel — und die Entscheidung zu `packaging/testbed.sh`
  (`docs/67 §3`).
