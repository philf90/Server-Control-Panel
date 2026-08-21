# P7 — DNS

Geschrieben am 21. August 2026, nach der Abnahme von P6 (`docs/69`), den elf
Entscheidungen des Betreibers (`docs/70 §13`) und der Messrunde (`docs/71`).

**Wo dieser Plan und `docs/20 §9` sich widersprechen, steht der Grund
daneben** — und `docs/20` wird nachgeführt, nicht stillschweigend übergangen.

---

## 1. Der Auftrag, und was die Messrunde daran geändert hat

`docs/20 §9` verlangt: PowerDNS autoritativ über die HTTP-API, eine
Zonenvorlage, neun Satztypen mit Prüfung, DNSSEC mit Schlüsselwechsel und
DS-Angaben, AXFR an Slaves, die Betriebsart „externer DNS" und eine Kundensicht.

**Drei Dinge daran sind nach `docs/70` und `docs/71` anders.**

1. **AXFR und NOTIFY fallen weg.** Es gibt keinen zweiten Nameserver
   (`docs/70 §13`, Entscheidung 5). Beides ungeprüft mitzubauen hiesse, ein
   Merkmal auszuliefern, das nie gelaufen ist.
2. **„Ein Zonenfehler wird nicht übernommen" prüft den falschen.** PowerDNS
   weist kaputte Einträge selbst ab, serverseitig und atomar (`docs/71 §4.2`).
   Das Panel kann diese Eigenschaft gar nicht verletzen — es kann sie nur
   umgehen, indem es an der API vorbeischreibt. Das Kriterium wird deshalb neu
   gefasst (§3).
3. **Die eigene Zone braucht kein Warten.** 0,8 ms zwischen API-Antwort und
   Auslieferung gegen 60 bis 900 Sekunden bei den acht externen Anbietern
   (`docs/71 §4.3`). `Patience` und `Resolver::ready()` gehören für die lokale
   Zone ausdrücklich **nicht** benutzt.

> **Ein Kriterium, das der Prüfling gar nicht verletzen kann, prüft den
> Lieferanten und nicht den Bau.**

---

## 2. Die Grenze — und die eine Ausnahme, die P7 kostet

Der Agent ist die einzige Stelle mit Systemrechten, und `agent/src/Acme/Curl.php`
ist der einzige Ort, an dem er nach draussen spricht. Seine erste Zusage lautet
**„Nur https"**, durchgesetzt von einer Zeile:

```php
if (! str_starts_with($url, 'https://')) {
    throw AgentException::denied('Nach draussen spricht der Agent nur über https.');
}
```

**Die API von PowerDNS spricht kein HTTPS, und 4.8.3 kennt dafür keine Option**
(`docs/71 §4.1`). Der Betreiber hat die benannte Ausnahme gewählt
(`docs/70 §13`, Entscheidung 1a). Sie wird so gebaut:

### 2.1 Wie die Ausnahme aussieht — und wie sie nicht aussieht

**Falsch wäre der Vergleich am Anfang der Zeichenkette.** `http://127.0.0.1`
als Präfix zu prüfen lässt `http://127.0.0.1.angreifer.invalid/` durch — der
Name beginnt mit derselben Zeichenkette und zeigt woandershin. Das ist genau die
Fehlerklasse, gegen die dieses Repo `AnchoredPatternTest` hat, und sie ist hier
schon zweimal teuer gewesen.

**Richtig ist: die Adresse zerlegen und den Wirt vergleichen.** `parse_url()`,
dann `$host` gegen genau `127.0.0.1` und `::1`. Und:

- **Kein Name, auch nicht `localhost`.** Ein Name kommt aus einer Auflösung, und
  eine Auflösung ist etwas, das jemand ändern kann — in `/etc/hosts`, im
  Systemauflöser, über eine Suchdomäne. Die Ausnahme gilt für eine **Adresse**
  und nicht für ein Versprechen darauf.
- **Nur für den Port der API.** Steht der Port in der Konfiguration, wird er
  verglichen; alles andere auf der Rückschleife bleibt draussen.
- **Die drei übrigen Zusagen bleiben unangetastet** — keine Umleitungen,
  gedeckelte Antwort, Zeitlimits. Der Riss betrifft ausschliesslich TLS.

### 2.2 Der Wächter dazu

`LoopbackExceptionTest`, und er prüft **beide** Richtungen:

- `https://…` geht durch, `http://` auf eine fremde Adresse nicht.
- `http://127.0.0.1:8081/…` geht durch.
- **`http://127.0.0.1.angreifer.invalid/…` geht nicht durch** — das ist der
  Fall, an dem die naive Fassung stirbt.
- `http://localhost:8081/…` geht **nicht** durch.
- `http://[::1]:8081/…` geht durch, `http://[::1]:9999/…` nicht.

Der Bruch dazu ersetzt die Zerlegung durch den Präfixvergleich und muss rot
werden. Ein Wächter, der nie rot war, ist kein Wächter.

> **Eine Ausnahme ohne Wächter ist keine Ausnahme, sondern der neue
> Normalfall.**

### 2.3 Wo der API-Schlüssel liegt

Wie die übrigen Geheimnisse des Agenten: in einer Datei unter `/etc/srvpanel`,
`0600`, `root`. **Nicht in der Panel-Datenbank** — sonst öffnet ein Lesezugriff
auf die Datenbank den Weg zum Nameserver, und die Datenbank ist dieselbe, in der
die Zonen liegen. Das Panel schickt eine typisierte Operation; den Schlüssel
sieht es nie.

---

## 3. Das Abnahmekriterium — neu gefasst

`docs/20 §9` sagt: „Fertig, wenn eine neu angelegte Domain ohne weiteres Zutun
auflösbar ist, ein Zonenfehler nicht übernommen wird und DNSSEC nachweislich
validiert."

Der zweite Punkt prüft PowerDNS (§1). Neu gefasst, als **zehn Punkte, jeder auf
`cloudsrv24` messbar**:

| # | Punkt | Gemessen woran |
|---|---|---|
| 1 | Eine neu angelegte Domain löst ohne weiteres Zutun auf | `A`, `AAAA` und `www` liefern die Serveradresse, gefragt am autoritativen Server |
| 2 | Der Platzhalter greift | ein nie gesetzter Name unter der Zone löst auf |
| 3 | Das CAA steht und nennt die eigene CA | `CAA` am Apex |
| 4 | Ein kaputter Eintrag wird abgewiesen, **und der Kunde liest einen Satz, der ihm gehört** | die Meldung nennt weder `pdnsutil` noch einen HTTP-Code |
| 5 | Ein abgewiesener Aufruf hinterlässt nichts | das gültige RRset desselben Aufrufs steht danach **nicht** im Bestand |
| 6 | Das Panel schreibt nie an der API vorbei | kein Zugriff auf die PowerDNS-Tabellen aus `app/`, belegt durch einen Wächter **und** durch eine Messung am laufenden Server |
| 7 | DNSSEC lässt sich einschalten, und der DS steht zum Weitergeben da | DS aus der Oberfläche, verglichen mit dem der API |
| 8 | DNSSEC validiert nachweislich | ein fremder validierender Auflöser nimmt die Zone an, nach Eintrag des DS beim Registrar |
| 9 | Ein Kunde kann seine Einträge bearbeiten, und die gesperrten nicht | SOA, NS am Apex und DNSSEC sind ihm verwehrt; ein NS unterhalb der Zone nicht |
| 10 | Eine Bestellung nach DNS-01 gegen die eigene Zone läuft durch, ohne zu warten | die Bestellung nennt keinen Wartelauf |

**Punkt 8 ist der teuerste**, und er braucht eine echte Domain, deren Registrar
einen DS annimmt. Er ist der einzige Punkt, der nicht allein auf `cloudsrv24`
liegt, und er gehört deshalb **früh** angefangen und nicht am Ende.

**Punkt 4 ist der, den `docs/20` nicht kannte.** Er ist das, was von „ein
Zonenfehler wird nicht übernommen" übrigbleibt, wenn der Lieferant die Prüfung
schon mitbringt: nicht *ob* abgewiesen wird, sondern *was der Kunde davon
liest*.

---

## 4. Was P7 ausdrücklich **nicht** wird

- **Kein AXFR, kein NOTIFY, keine Slaves** (Entscheidung 5). `docs/20 §9` wird
  entsprechend nachgeführt.
- **Kein zweiter Weg zu demselben Gegenstand.** Der RFC-2136-Klient bleibt
  unverändert einer von acht Anbietern für **fremde** Zonen und bekommt keine
  Rolle für die eigene (`docs/70 §1`).
- **Kein Mail.** MX, SPF, DMARC stehen nicht in der Vorlage; der Kunde darf sie
  setzen, das Panel setzt sie nicht.
- **Keine Registrar-Anbindung.** Das Panel zeigt den DS an und trägt ihn
  nirgends ein.
- **Kein Spiegel der Einträge in der Panel-Datenbank** (§5).
- **Kein PTR.** `docs/20 §9` nennt einen „PTR-Hinweis" — das bleibt ein Hinweis
  auf der Seite und keine Verwaltung: Die Rückwärtszone gehört dem, dem das Netz
  gehört, und das ist nicht dieses Panel.
- **Keine Zonenübernahme von aussen.** Wer eine bestehende Zone hierher holen
  will, trägt sie ein; ein Import über AXFR wäre der Slave-Fall, den es nicht
  gibt.

---

## 5. Datenmodell — was das Panel führt und was es nicht führt

**Die Einträge stehen in PowerDNS und werden nicht gespiegelt.** Ein Spiegel
wäre ein zweiter Bestand, und der zweite ist der, der veraltet — der Fehler, an
dem dieses Projekt am häufigsten verloren hat.

Der übliche Einwand lautet: „Dann hängt die Seite an einem Dienst, der
stillstehen kann." **Durch Entscheidung 3 fällt er weitgehend weg:** PowerDNS
liegt mit `gmysql` auf derselben MariaDB wie das Panel. Ist sie weg, ist auch
das Panel weg — die Abhängigkeit ist keine neue. Bleibt der Fall, dass **pdns**
steht und MariaDB läuft; dafür bekommt die Zonenseite einen eigenen Zustand
(§8.3) statt einer weissen Seite.

Das Panel führt drei Dinge, die PowerDNS nicht halten kann:

```
domains.dns_mode          'local' | 'external'      (Entscheidung 8)
domains.dnssec_state      'off' | 'pending_ds' | 'active'   (Entscheidung 7)

dns_record_pins           domain_id, name, type, pinned_at
```

**`dns_record_pins` ist der teuerste Teil dieses Plans** und der Grund, warum
Entscheidung 9 so lautet, wie sie lautet. Ändert ein Kunde einen Eintrag, den
das Panel selbst gesetzt hat — den `A` auf den Server, weil seine Seite künftig
bei einem externen Dienst liegt —, dann darf die Automatik ihn beim nächsten
Anfassen der Zone **nicht still zurücksetzen.**

Das ist `certificate_pinned_at` aus `docs/34 §11` noch einmal, mit demselben
Grund und demselben Rückfallverhalten: Wird der gepinnte Wert später unhaltbar,
wird **laut** zurückgefallen — ein Eintrag im Prüfprotokoll und ein Hinweis auf
der Seite, nicht ein stiller Wechsel.

> **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke, sondern eine
> Voreinstellung** — und umgekehrt: Was die Automatik unbemerkt zurücknimmt, ist
> keine Entscheidung des Kunden, sondern ein Vorschlag.

**`dnssec_state` hat drei Werte und nicht zwei**, weil die zweistufige Führung
aus Entscheidung 7 einen Zustand *zwischen* aus und an braucht. Ein Hinweis, den
jemand weggeklickt hat, ist kein Zustand.

---

## 6. Die Operationen des Agenten

Typisiert, wie alles andere — nie Text, der zu einer Kommandozeile oder einer
Konfigurationsdatei wird.

| Operation | Was sie tut |
|---|---|
| `dns.server.info` | Fassung und Erreichbarkeit; die Grundlage der Überwachung (§10) |
| `dns.zone.create` | Zone anlegen und die Vorlage in **einem** Aufruf einspielen |
| `dns.zone.read` | Zone samt RRsets lesen — die Quelle jeder Anzeige |
| `dns.zone.remove` | Zone löschen |
| `dns.record.write` | Ein RRset setzen oder entfernen |
| `dns.dnssec.enable` | Signieren einschalten |
| `dns.dnssec.disable` | und wieder aus |
| `dns.dnssec.keys` | Schlüssel und DS-Angaben lesen |
| `dns.dnssec.rollover` | Schlüsselwechsel |

**`dns.zone.create` spielt die Vorlage im selben Aufruf ein und nicht in einem
zweiten** (gemessen: geht, `docs/71 §3` Nr. 7). Der Grund ist nicht Geschwindigkeit,
sondern Atomarität: Ein zweiter Aufruf kann scheitern, und dann steht eine Zone
ohne Einträge da — auflösbar, aber ins Leere.

**`dns.record.write` schreibt ein RRset und nicht einen Eintrag.** Die API
ersetzt satzweise; wer „einen Eintrag hinzufügen" anbietet, muss den Satz vorher
lesen, ergänzen und ganz zurückschreiben. Diese Lesestelle ist die eine, an der
zwei gleichzeitige Änderungen einander überschreiben können, und sie gehört
benannt statt übersehen.

> **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
> anderen Vorgänge derselben Reihe nicht.**

---

## 7. Die Zonenvorlage

Nach Entscheidung 6 und 9a:

```
@      SOA    ns1.<panel>. hostmaster.<panel>. <serial> …
@      NS     ns1.<panel>.
@      NS     ns2.<panel>.
@      A      <IPv4 des Servers>
@      AAAA   <IPv6 des Servers>
www    A      <IPv4 des Servers>
www    AAAA   <IPv6 des Servers>
*      A      <IPv4 des Servers>
*      AAAA   <IPv6 des Servers>
@      CAA    0 issue "letsencrypt.org"
```

Drei Dinge, die daran hängen:

1. **Der Name des Nameservers kommt aus `SrvPanel\Agent\Names::fqdn()`** und
   nirgendwoher sonst. Diese Quelle ist viermal neu erfunden worden; seither
   hält `HostnameSourceTest` sie fest.
2. **`ns1` und `ns2` zeigen beide auf `cloudsrv24`** (Entscheidung 9a). Das
   erfüllt die Formalie und ist **kein Ausfallschutz** — und genau das gehört
   auf die Seite geschrieben, sonst verkauft die Oberfläche eine Redundanz, die
   es nicht gibt.
3. **Das CAA muss mitziehen, wenn die Zertifizierungsstelle wechselt.** Ein CAA,
   das die eigene CA nicht mehr nennt, nimmt dem Kunden die Erneuerung — und
   zwar erst in sechzig Tagen, wenn niemand mehr an den Wechsel denkt. Das ist
   ein eigener Wächter: *Die CA in der Zonenvorlage ist dieselbe, die
   `AcmeSettings` führt.*

> **Ein Eintrag, den das Panel setzt und nicht mitzieht, ist eine Zusage mit
> Ablaufdatum.**

**Und die Vorlage ist eine Vorlage.** Ändert sie sich, ändern sich die schon
stehenden Zonen nicht. Das ist Absicht — ein Nachziehen würde Kundenänderungen
überschreiben —, und es gehört sichtbar: Die Zonenseite sagt, wenn eine Zone
nach einer älteren Vorlage entstanden ist.

---

## 8. Die Oberfläche

### 8.1 Wo sucht jemand das?

Dreimal in P6 ist ein Merkmal drei Klicks tief gelandet und musste verlegt
werden — der Dateimanager (`docs/55` Befund 8), der SFTP-Zugang (`docs/59`
Befund 19) und der Bereich „Job anlegen" (`docs/64` Befund 13). Jedes Mal hat
es der Betreiber gemeldet und kein Test.

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?**

**Die Antwort für P7: an der Domain.** Ein Kunde, der einen DNS-Eintrag ändern
will, geht zu seiner Domain und nicht in die Einstellungen — „DNS" ist ein
Reiter der Domainseite, nicht ein eigener Menüpunkt und schon gar nicht eine
Unterseite davon. Der Betreiber findet dieselbe Ansicht zusätzlich über die
Domainliste.

### 8.2 Die Eintragsliste

- **Kein freies Textfeld für eine Zonendatei.** Ein Formular je Satztyp, mit den
  Feldern, die dieser Typ hat — Priorität nur beim MX, Gewicht und Port nur beim
  SRV. Das ist dieselbe Entscheidung wie „kein freies SQL" aus P5c: Der Agent
  bekommt typisierte Fragen und keine Anweisung.
- **Die Meldung der API wird übersetzt, nicht durchgereicht** (§3 Punkt 4).
  „try 'pdnsutil check-zone'" nennt ein Programm, das der Kunde nicht hat.
- **Gesperrte Sätze werden gezeigt und nicht versteckt.** SOA und die NS am
  Apex stehen da, ohne Knopf — wer sie nicht sieht, sucht sie. Und der Knopf
  fehlt, weil die Policy es sagt, nicht weil ein `v-if` den Kontotyp abfragt
  (`AbilityReachTest`).
- **Bei 390 px** ist ein Eintrag ein Kärtchen und keine Tabellenzeile. Ein
  Zonenname darf 253 Zeichen lang sein, ein einzelnes Label 63 — die
  Umbruchregel aus `docs/67` Befund 6 gilt hier an jeder Stelle, an der ein Name
  steht.

### 8.3 Wenn PowerDNS nicht antwortet

Ein eigener Zustand mit einem Satz, der sagt, was los ist und was der Kunde tun
kann (nämlich nichts, und auch das gehört gesagt). **Keine leere Liste** — eine
leere Liste behauptet, es gebe keine Einträge.

> **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt, behauptet
> etwas, das sie nicht weiss.**

### 8.4 DNSSEC in zwei Schritten

1. **Einschalten.** Die Zone wird signiert, `dnssec_state` geht auf
   `pending_ds`.
2. **Der DS steht zum Abholen da**, mit einem Satz dazu, was der Kunde damit
   tut — bei seinem Registrar eintragen, nicht hier.
3. **Der Kunde bestätigt**, dass er ihn eingetragen hat; `dnssec_state` geht auf
   `active`.

Der Zustand `pending_ds` ist nicht kosmetisch: Solange er gilt, ist die Zone
signiert, aber die Kette nicht geschlossen — und ein Schlüsselwechsel in diesem
Zustand ist harmlos, im Zustand `active` dagegen nimmt er die Domain vom Netz,
bis der neue DS steht. **Der Schlüsselwechsel führt deshalb wieder über
`pending_ds`**, und das gilt bei einem CSK für *jeden* Wechsel (`docs/71 §4.5`).

---

## 9. Die Rechte

**`Feature::DnsEdit` behält seine heutige Bedeutung** — ein eigenes
DNS-01-Profil für ACME (Entscheidung 9). Für das Bearbeiten der eigenen Zone
kommt **`Feature::DnsRecords`** dazu.

**Und dabei fällt eine Ungereimtheit auf, die es seit P4 gibt:**
`Feature::DnsEdit` trägt schon heute die Beschriftung **„DNS-Einträge
bearbeiten"** — also genau das, was es *nicht* tut. Wer den Plan liest, kauft
etwas anderes, als er bekommt.

> **Eine Beschriftung, die etwas anderes verspricht als der Code tut, ist eine
> Zusage, die niemand eingelöst hat.**

Die Beschriftung wird deshalb im selben Schritt berichtigt: `DnsEdit` heisst
künftig „Eigene DNS-Zugangsdaten für Zertifikate", `DnsRecords` heisst
„DNS-Einträge bearbeiten". Das ist eine Änderung an einer sichtbaren
Beschriftung und gehört in den `CHANGELOG` mit ihrem Grund.

**Gesperrt für den Kunden** (Entscheidung 9):

- der SOA-Satz,
- **die NS-Sätze am Apex** — und nur dort. Ein NS *unterhalb* der Zone ist eine
  Unterzonen-Delegierung, alltäglich und erlaubt. Die Regel lautet also **NS am
  Apex gesperrt, NS darunter erlaubt**, und der Unterschied ist der ganze Fall.
- DNSSEC und alles, was an Schlüsseln hängt.

**Erlaubt**, mit Warnung und Rückholweg: die A/AAAA, die das Panel gesetzt hat
(§5).

Jede Route trägt `can:` oder steht mit Begründung in
`app/Support/Authorization/RouteGuard.php`. Und wer eine Aktion **zeigt**, fragt
vorher dieselbe Policy, die sie später abweist — die Antwort kommt als
`can`-Ablage im Inertia-Payload, nie als `v-if` auf den Kontotyp.

---

## 10. Die Überwachung fragt die API

Aus `docs/71 §4.4`: Fällt MariaDB weg, meldet die API sofort `500`, während der
Nameserver noch rund zwanzig Sekunden aus seinem Zwischenspeicher weiterbedient
und danach auf `SERVFAIL` fällt. **Die API sieht den Ausfall also, bevor Kunden
ihn sehen** — das ist die Zeitspanne, in der eine Überwachung noch etwas
ausrichten kann.

`dns.server.info` ist deshalb die Frage, die der Metrikenlauf stellt, und nicht
eine DNS-Abfrage an Port 53. Eine Abfrage, die aus dem Zwischenspeicher
beantwortet wird, meldet „alles in Ordnung" für einen Dienst, der seinen Bestand
verloren hat.

> **Eine Antwort aus dem Zwischenspeicher ist eine Aussage über vorhin.**

---

## 11. ACME gegen die eigene Zone

Der Faden aus P4, den `docs/20` als „DNS-01 gegen die eigene Zone (nach P7
automatisch)" führt.

**Vorrang: die lokale Zone** (Entscheidung 10). Liegt die Zone hier und sind
zugleich Zugangsdaten für einen fremden Anbieter hinterlegt, gewinnt die lokale
— und die hinterlegten Zugangsdaten werden **als für diese Domain wirkungslos
angezeigt**. Nicht verschwiegen:

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

**Und die lokale Zone wartet nicht.** `Patience` und `Resolver::ready()`
existieren, weil die API eines Anbieters „ok" sagt, bevor der Eintrag
ausgeliefert wird — hier vergehen 0,8 ms (`docs/71 §4.3`). Ein Wartelauf über
60 Sekunden gegen den eigenen Server wäre eine Minute, die jede Bestellung
zusätzlich kostet, ohne dass sie etwas prüft.

Der Wächter dazu ist einer, der etwas **verhindert**: *Der lokale Anbieter
benutzt die Wartelogik der externen nicht.* Er ist nötig, weil der bequemste Bau
darin bestünde, `DnsProvider` einfach ein neuntes Mal umzusetzen und `patience()`
mitzuerben.

---

## 12. Die Wächter

Für jede Regel einer, und jeder wird gegengeprüft.

| Wächter | Regel |
|---|---|
| `LoopbackExceptionTest` | Die Ausnahme in `Curl` gilt für zwei Adressen und keinen Namen (§2.2) |
| `DnsApiOnlyTest` | Nichts unter `app/` fasst die PowerDNS-Tabellen an — nicht einmal lesend |
| `ZoneTemplateSourceTest` | Die Vorlage steht an einer Stelle, und die CA darin ist die aus `AcmeSettings` (§7) |
| `ApexRecordTest` | NS am Apex gesperrt, NS darunter erlaubt (§9) |
| `LocalZoneNoPatienceTest` | Der lokale Weg erbt die Wartelogik der Anbieter nicht (§11) |
| `DnssecStateTest` | Jeder Schlüsselwechsel führt über `pending_ds` (§8.4) |
| `RecordPinTest` | Ein gepinnter Eintrag wird nicht still überschrieben (§5) |
| `DnsMessageTest` | Keine Meldung der API erreicht den Kunden unübersetzt (§3 Punkt 4) |
| `DnsOperationReachTest` | Jeder Operationsname zeigt auf eine Operation, die es gibt |

**Zwei davon lassen sich nicht auf die übliche Art brechen**, und das gehört
dazugeschrieben: `DnsApiOnlyTest` und `DnsOperationReachTest` prüfen die
Abwesenheit von etwas. Ihr Bruch besteht darin, das Verbotene **einzufügen** —
eine Abfrage auf `records` in einem Controller, einen Operationsnamen ohne
Operation — und zu sehen, dass es rot wird.

---

## 13. Die Schritte, in dieser Reihenfolge

| # | Schritt | Warum hier |
|---|---|---|
| 0 | Die zwei offenen Servermessungen (`docs/70 §14`) | Sie entscheiden die Mindestfassung und die Bindung an Port 53 |
| 1 | **Punkt 8 des Kriteriums anfangen** — eine Domain besorgen, deren Registrar einen DS annimmt | Der einzige Punkt, der nicht allein auf `cloudsrv24` liegt; am Ende ist er ein Blocker |
| 2 | PowerDNS aufs Paket: Abhängigkeit, `gmysql`, Schema, Bindung an die öffentlichen Adressen | Ohne den Dienst misst nichts etwas |
| 3 | Die Ausnahme in `Curl` samt `LoopbackExceptionTest` und Bruch | Sie ist die Grenze; alles Weitere geht durch sie |
| 4 | `dns.server.info`, und die Überwachung daran (§10) | Der erste Weg, der beweist, dass der Agent die API erreicht |
| 5 | `dns.zone.create` mit der Vorlage, `dns.zone.read`, `dns.zone.remove` | Kriterienpunkte 1–3 |
| 6 | Das Datenmodell: `dns_mode`, `dnssec_state`, `dns_record_pins` | Vor der Oberfläche, weil sie darauf steht |
| 7 | `dns.record.write` und die Eintragsliste, mit den übersetzten Meldungen | Kriterienpunkte 4 und 5 |
| 8 | Die zweite Freigabe, die Sperren, die berichtigten Beschriftungen (§9) | Kriterienpunkt 9 |
| 9 | Das Pinnen und sein lautes Zurückfallen (§5) | Der teuerste Teil; er steht hinter dem Editor, weil er ihn braucht |
| 10 | DNSSEC in zwei Schritten (§8.4) | Kriterienpunkte 7 und 8 |
| 11 | „Externer DNS" je Domain (Entscheidung 8) | Klein, und er hängt am Datenmodell aus Schritt 6 |
| 12 | ACME gegen die eigene Zone (§11) | Kriterienpunkt 10 |
| 13 | **Zwischenabnahme auf `cloudsrv24`** | Spätestens hier, weil ab Schritt 2 alles auf einem Dienst steht, den der Container nur als Wegwerf-Fassung kennt |
| 14 | Bilderrunde, beide Themes, 390 und 1440 px | `tests/bilder-messen.js` |
| 15 | Abnahmelauf, Protokoll **während** des Laufs | `docs/69` als Vorbild |

**Schritt 1 steht so weit vorn, weil er von jemand anderem abhängt.** Ein
Registrar, der einen DS annimmt, ist keine Sache von Minuten, und Punkt 8 ist
der einzige, der ohne ihn nicht messbar ist. Wer ihn ans Ende schiebt, hat am
Ende eine fertige Stufe und kein Kriterium.

---

## 14. Die Risiken, ehrlich benannt

1. **Der Nameserver hängt an der Panel-Datenbank** (Entscheidung 3). Gemessen
   ist, dass er den Ausfall überlebt, nach ~20 s auf `SERVFAIL` fällt und sich
   in 5 s selbst erholt (`docs/71 §4.4`). Ungemessen ist, wie er sich verhält,
   wenn die Datenbank *langsam* ist statt weg — der Fall, der im Betrieb
   häufiger ist.
2. **Die Ausnahme in `Curl`.** Sie ist eng gefasst und geprüft, aber sie ist der
   erste Riss in einer Zusage, die ohne Ausnahme galt. Wer die nächste
   hinzufügt, findet einen Präzedenzfall vor.
3. **`dns_record_pins` kann veralten.** Ein Pin auf einen Eintrag, den es nicht
   mehr gibt, ist derselbe Grabstein wie in `docs/35`: Solange etwas darauf
   zeigt, sieht der Rest nicht aus wie einer. Der Rückbau gehört **mitgebaut**
   und nicht nachgereicht.
4. **Die Fassungsspanne 4.5 bis 4.8** über die vier Zielplattformen ist erst zur
   Hälfte gemessen (`docs/70 §14.1`).
5. **Die höchste Aussenwirkung.** `docs/20 §10` nennt genau das als Grund, warum
   DNS spät kommt — im Wortlaut: „eine falsche Zone nimmt **Kunden** vom Netz,
   ein falscher Vhost nur eine Seite". Der Unterschied ist die Mehrzahl: Eine
   Zone trägt alles, was unter einem Namen liegt, und ein Fehler daran ist von
   aussen sichtbar, bevor er hier auffällt. Jeder Schritt, der eine bestehende
   Zone anfasst, gehört deshalb an einer Wegwerf-Domain gemessen, bevor er eine
   echte trifft.

---

## 15. Was offen bleibt und nicht zu P7 gehört

- Die zwei Servermessungen aus `docs/70 §14`.
- Das Verhalten bei einer **langsamen** statt einer fehlenden Datenbank
  (Risiko 1).
- Das Verhalten bei vielen Zonen — Last ist nicht gemessen (`docs/71 §1`).
- Und aus P6, weiterhin benannt (`docs/69 §3`): Wand 2 aus Punkt 11, Befund 23,
  die neunzehn ungeprüften Griffe in `RevealTest::UNEXAMINED`, die vollständige
  Umkehrung der Abstandsregel — und die Entscheidung, ob die CI künftig
  `packaging/testbed.sh` aufruft (`docs/67 §3`).
