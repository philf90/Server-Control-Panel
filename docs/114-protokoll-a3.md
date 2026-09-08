# A3, erster Wurf — das Protokoll

**Gefahren am 8. September 2026 auf `cloudsrv24` gegen `0.7.3-rc.28`.**
Der Plan ist `docs/109`, der Lauf `docs/110`, die Messrunde `docs/81 §2.3s`.

**Sieben der acht Punkte sind erfüllt, beide Ausschlusskriterien (2 und 3)
darunter — und Punkt 6 ist es nicht.** A3s erster Wurf ist damit **nicht
abgenommen**: `docs/110 §10` verlangt alle acht, und Punkt 6 ist auch nicht als
„nicht herstellbar" ausgefallen. Der Zustand war herstellbar, und der Prüfling
hat ihn falsch beantwortet.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten, ist
> keines mehr — es ist eine Zusammenfassung.**

---

## 0 · Der Vorflug

    2026-09-08T17:48:32Z
    srvpanel version        0.7.3-rc.28
    ss -H -ltnp | wc -l     13
    nft list ruleset        rc=0, 2307 Bytes (drei Warnungen auf stderr)
    iptables-legacy         /usr/sbin/iptables-legacy, genau drei -P … ACCEPT
    ufw                     /usr/sbin/ufw
    Dienste                 active · active · active · active

**Und der Vorflug trug einen Befund an der eigenen Vorschrift** — siehe §9.3.

---

## 1 · Die Lauscher stehen da — erfüllt

**13 gegen 13, Zeile für Zeile in derselben Reihenfolge.** Ohne einen einzigen
Prüfkörper: Der Server hat einen echten Bestand, und ihn zu messen ist besser,
als einen zu bauen.

| `ss -H -ltnp` | Seite |
|---|---|
| `127.0.0.1:3306` mariadbd | 3306 · lauscht nur lokal · `mariadbd` |
| `127.0.0.53%lo:53` | 53 · nur lokal · `systemd-resolve` |
| `127.0.0.1:5432` | 5432 · nur lokal · `postgres` |
| `0.0.0.0:443` · `:8443` · `:22` · `:80` | auf allen Adressen · `nginx`, `sshd` |
| `127.0.0.54:53` | 53 · nur lokal · `systemd-resolve` |
| `[::1]:5432` | 5432 · **nur lokal** · `postgres` |
| `[::]:443` · `:8443` · `:22` · `:80` | auf allen Adressen |

Beide Sorten stehen da — „auf allen Adressen" für `0.0.0.0` **und** `[::]`,
„nur lokal" für `127.0.0.1` und `[::1]`.

---

## 2 · Legacy wird gesehen — erfüllt *(Ausschluss)*

**Der Punkt, für den es diesen Wurf gibt**, und er ist hier stärker belegt als
im Container.

| | |
|---|---|
| `nft list ruleset \| wc -c` **vorher** | **2307** |
| Regel gesetzt | `iptables-legacy -A INPUT -p tcp --dport 12346 -j DROP` |
| `iptables-legacy -S \| grep 12346` | `-A INPUT -p tcp -m tcp --dport 12346 -j DROP` |
| `nft list ruleset \| wc -c` **nachher** | **2307** — byteweise identisch |
| Seite: Verwaltet von | `nftables` → **`iptables`** |
| Seite: iptables (alte Bauart) | `führt nichts` → **`führt Regeln`** |
| Abbau | `legacy wie vorher`, nft wieder 2307 |

**Im Container war der Beleg eine Null** — und eine Null sieht aus wie „keine
Regeln". Hier ist es eine **unveränderte Zahl neben einer Regel, die
nachweislich dasteht**.

> **Ein leeres Regelwerk und ein Regelwerk, das man mit dem falschen Werkzeug
> abfragt, sehen gleich aus — und beide sagen `rc=0`.** M10 ist damit auf einer
> echten Maschine gemessen und nicht nur beschrieben.

**Die Meldung „alte Bauart" ist ausgeblieben, und das ist richtig.** Sie hängt
an `legacy_only = $l['configured'] && $n['readable'] && ! $n['configured']`, und
`nft` führt hier Regeln. Die Erwartung dazu stand **vor** dem Lauf da, aus dem
Quelltext abgeleitet — siehe §10.

---

## 3 · Keine Zusage über aussen — erfüllt *(Ausschluss)*

    offen       → 0
    erreichbar  → 0
    geschlossen → 0
    Satz        → 1
    lauscht     → 13

Gesucht wurde **ohne Wortgrenzen** über den ganzen gerenderten Text; die Suche
hätte also auch `unerreichbar` und `abgeschlossen` gefangen. Dreimal 0 heisst
damit: Das Wort kommt nirgends vor. Und die 13 daneben belegen, dass der Zähler
den richtigen Text liest.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 4 · Der Verwalter wird benannt — erfüllt

    ufw status                                        Status: inactive
    command -v firewall-cmd                           rc=1
    nft list tables                                   ip nat, ip filter, ip6 nat,
                                                      ip6 filter, inet f2b-table
    iptables-legacy -S | grep -v '^-P .* ACCEPT$' | wc -l    0

Daraus folgt `nftables`, und genau das steht auf der Seite. Die Reihenfolge ist
die der **Zuständigkeit** und nicht der Sichtbarkeit: firewalld und ufw sagen
selbst, dass sie zuständig sind; `nft` sagt nur, dass etwas dasteht.

---

## 5 · IPv6 — erfüllt

**Der Fall, den der Container grundsätzlich nicht messen kann** (kein IPv6 im
Kernel). Aus der Nutzlast:

| | |
|---|---|
| `family: inet6` | **5** |
| `[`-Zeilen in `ss` | **5** |
| `::` (4×) | `scope: any` |
| `::1` (1×) | `scope: loopback` |
| Klammern in einer Adresse | **keine** |

Die Klammerform stand bis heute im Leser **nach der Dokumentation von iproute2
und nicht nach einer Messung**. Sie ist jetzt gemessen.

**Und ein Fall ist zum ersten Mal überhaupt durchgegangen, den keine Messrunde
kannte:** `127.0.0.53%lo` — eine Adresse mit **Schnittstellensuffix**. Sie steht
so in der Nutzlast und wird richtig als `loopback` eingeordnet. In
`docs/81 §2.3s` kam diese Form nicht vor (M3 mass `0.0.0.0:19001` und
`127.0.0.1:19002`).

---

## 6 · Nicht feststellbar — **nicht erfüllt**

    systemctl stop srvpanel-agentd && sleep 2
    inactive · inactive · inactive · active

Gemessen auf der neu geladenen Seite:

    Ports-Satz      : true
    Tabellen        : 2
    unter Regelwerk : "Regelwerk"

Der **Ports**-Bereich sagt *„Die lauschenden Ports sind nicht feststellbar — der
Agent hat nicht geantwortet."* — richtig. Der **Regelwerk**-Bereich sagt
**nichts**: Unter der Überschrift steht weder „nicht feststellbar" noch „keine
Regeln", sondern der Seitenfuss.

`docs/110 §6` verlangt, dass der Bereich `nicht feststellbar` **sagt**. Er tut
es nicht. **Das ist Befund 1** (§9.1).

**Zurück, über das Ziel und nicht über den Agenten:** viermal `active`.

---

## 7 · Die Tür — erfüllt

| Konto | `/services` | Spalte „Eigentümer" | Nutzlast |
|---|---|---|---|
| Betreiber | 200, vollständig | Namen | 13 Lauscher, 13 Namen |
| **Administrator** | **200**, vollständig | **„nicht feststellbar"** ×13 | **13 Lauscher, 0 Namen** |
| Kundenkonto | **403** „Kein Zutritt" | — | — |

**Die Null ist der Punkt.** Ein `v-if` hätte die Spalte geleert und den Namen
trotzdem über die Leitung geschickt; `ServerPorts::withoutProcesses()` schneidet
ihn heraus, bevor die Seite ihn sieht.

> **Eine Grenze, die erst im Browser gezogen wird, ist keine.**

**`privileged: null` ist dabei die entworfene Antwort und keine Lücke.** Das
Feld sagt, ob der *Agent* nachsehen durfte; für einen Betrachter, der die Spalte
ohnehin nicht bekommt, würde daraus „niemand sichtbar" statt „darfst du nicht
sehen". Die Schlüssel bleiben stehen und werden auf `null` gesetzt, statt
entfernt zu werden — drei Zustände und nicht vier.

> **Ein Feld, das erklärt, warum eine Spalte leer ist, ist falsch, wenn die
> Spalte aus einem anderen Grund leer ist.**

Der 403 zeigt die deutsche, gestaltete Seite mit einem Weg zurück.

---

## 8 · 390 px — erfüllt

Vier Lagen, je frisch geladene Seite, als Betreiber:

| Breite | Thema | dokument | gegenprobe | schiebt | rollt | versteckt |
|---|---|---|---|---|---|---|
| 1440 | hell | 0 | 200 (soll 200) | 0 | 0 | 0 |
| 1440 | dunkel | 0 | 200 (soll 200) | 0 | 0 | 0 |
| 390 | dunkel | 0 | 200 (soll 200) | 0 | 0 | 6 |
| 390 | hell | 0 | 200 (soll 200) | 0 | 0 | 6 |

Gegen den echten Bestand: 13 Lauscher, drei gestapelte Tabellen, zwei Bereiche —
wo der Container drei Zeilen hatte. `versteckt = 6` sind zwei Elemente je
gestapelter Tabelle.

---

## 9 · Die Befunde

### 9.1 Der Bereich „Regelwerk" schweigt, wo er reden müsste — behoben

Bei angehaltenem Agenten gibt `ServicesController` `['readable' => false,
'reason' => 'unreachable']` zurück — **ohne** den Schlüssel `filter`. In
`Services/Index.vue` hängt die ganze Regelwerkstabelle an
`v-if="props.ports.filter"`, und die „alte Bauart"-Meldung ebenso. Beide fallen
weg, und übrig bleibt eine Überschrift über nichts.

> **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
> behauptet etwas, das sie nicht weiss.**

**Es ist derselbe Befund wie Befund 3 des A6-Laufs**, behoben am 8. September
auf `/schedules` — und hier, an einer zweiten Seite, **am selben Tag** wieder
da. Er steht seit dem 7. September im Repo.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.** Zum vierten Mal in
> diesem Repo — und diesmal mit einem Tag Abstand statt Wochen.

Der Unterschied zu `/schedules` sagt, wo die Behebung hingehört: Dort war die
Antwort, den Bereich **wegzulassen**; hier gibt es einen zweiten Bereich, der
etwas zu sagen hätte — nämlich `nicht feststellbar`. Beide Male ist die Regel
dieselbe: **Wo nichts feststeht, steht der Satz und keine Form ohne Inhalt.**

### 9.2 Der doppelte Punkt im Agenten-Streifen — behoben, gehört zu A2

Auf `/services` steht bei angehaltenem Agenten:

    Der Agent antwortet nicht: Der Agent läuft nicht: Socket ist nicht vorhanden..

`Services/Index.vue:175` schreibt `Der Agent antwortet nicht{{ error ? ': …' : '' }}.`
und `Client.php:228` gibt die Meldung **mit** Schlusspunkt zurück.

> **Ein Satz, der einen fremden Satz einbettet und selbst schliesst, schliesst
> ihn zweimal.**

Klein, sichtbar und real. Er gehört zu A2 und nicht zu A3 — ein Lauf, der ihn
sieht und verschweigt, hätte ihn übersehen.

### 9.3 Ein Wächter trug eine zweite Fassung einer Regel — behoben

**Gefunden beim Beheben von Befund 1 und nicht vom Lauf.**
`tests/Support/WithoutMarkupComments.php` gibt es seit dem **25. August 2026**,
und neun Wächter benutzen es. `ReachabilityWordTest` ist am **7. September**
entstanden — dreizehn Tage später — mit einem **eigenen** privaten
`ohneKommentare()`, und in seinem Kopf stand wörtlich *„Für eine `.vue` gab es
nichts — bis hier."*

> **Zwei Fassungen derselben Regel sind zwei, und die zweite ist die, die
> veraltet.**

> **Eine Zeile, die eine Abwesenheit behauptet, lässt den Nächsten dasselbe noch
> einmal bauen** — teurer als eine, die eine Grenze benennt.

Der eigene Abtaster ist fort; beide Wächter fragen jetzt denselben Trait.

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

### 9.4 Der Vorflug fragte die Vereinigung — behoben in der Vorschrift

`docs/110 §0b` schrieb:

    command -v ufw firewall-cmd || echo "ufw/firewalld: fehlen"

Im Container nachgemessen: `command -v bash firewall-cmd` druckt `/usr/bin/bash`
und gibt **`rc=0`** — der Rückgabewert ist 0, sobald **einer** gefunden wird. Das
`||` feuert also nur, wenn keiner von beiden da ist. Über `firewall-cmd` sagte
die Zeile auf `cloudsrv24` **gar nichts**; entschieden hat es erst die einzelne
Frage in §4 (`rc=1`).

> **Eine Frage an die Vereinigung hält auch dann, wenn eine der Quellen blind
> ist — die andere zahlt für sie mit.** Derselbe Satz steht seit `docs/78` in
> `CLAUDE.md`, dort über einen Wächter. Hier stand er in einer Zeile, die ich
> selbst geschrieben habe.

### 9.5 Der Plan brach seine eigene Regel im Beispielsatz — beim Bauen berichtigt

`docs/109 §3.2` gibt den Satz als *„Ob diese Ports von aussen **erreichbar**
sind…"* und verbietet drei Absätze weiter genau dieses Wort. Auf der Seite steht
*„von aussen **zu benutzen** sind"*.

> **Eine Regel, die in ihrem eigenen Beispielsatz gebrochen wird, ist beim Bauen
> zu berichtigen und nicht abzuschreiben.**

### 9.6 Zwei Erwartungen des Laufs konnte der Prüfling nicht erfüllen

**Punkt 5** verlangte die Adresse „**ohne** Klammern" auf der **Seite**. Die
Seite zeigt die Adresse nur im Fall `specific` (`docs/109 §3.2`); bei `any` und
`loopback` steht der Satz statt der Adresse. Gemessen wurde deshalb die
**Nutzlast**, und dort stimmt es.

**Punkt 2** erwartete die Meldung „alte iptables-Bauart". Sie hängt an einem
leeren `nft`, und `nft` führt auf diesem Server Regeln. Ihr Ausbleiben ist
richtig.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

### 9.7 Eine Konsolenmeldung, die ungeklärt bleibt

Während §5 stand in der Konsole:

    Uncaught (in promise) Error: A listener indicated an asynchronous response
    by returning true, but the message channel closed before a response was received

Das ist der Wortlaut, den Chrome für eine **Erweiterung** ausgibt, die einen
Nachrichtenkanal schliesst, bevor sie antwortet. Zugeordnet ist sie **nicht** —
der Handgriff dafür ist ein Inkognito-Fenster. Kein Kriterium hängt daran; ins
Protokoll gehört sie als das, was sie ist: **ungeklärt**.

---

## 10 · Was der Lauf über das Vorhersagen gelernt hat

**Zwei Vorhersagen aus dem Quelltext haben getroffen, zwei Zahlen aus dem
Gedächtnis nicht.**

Getroffen: dass die Meldung „alte Bauart" ausbleibt (§2), und dass der
Regelwerk-Bereich bei angehaltenem Agenten **leer** ist (§6) — beide vor dem
Fahren ausgeschrieben, beide am Code abgeleitet.

Danebengelegen sind an diesem Tag zwei Zahlen aus dem Nachlauf zu A6: eine
Lücke von „dreistellig" (gemessen 14) und `versteckt = 6` (gemessen 4). Beide
kamen aus einer Messung unter **anderen** Bedingungen.

> **Eine Vorhersage aus dem Quelltext ist eine Frage an den Prüfling. Eine Zahl
> aus einer früheren Messung ist eine Vermutung mit Anspruch.**

**Und der teuerste Handgriff des Tages war ein Bruch, der nicht gebissen hat.**
`AgentMessageTest` stand grün, als der doppelte Punkt zurückgesetzt wurde. Sein
Ausdruck `\{\{[^}]*\berrors?\b[^}]*\}\}` verträgt kein `}` in der Mitte der
Klammer — und die Zeile lautet `{{ error ? \`: ${error}\` : '.' }}`. Gezählt
hatte er neun Einbettungen: die **anderen** neun Dateien. Genau die Stelle, an
der der Befund entstand, hat er nie angesehen.

> **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
> gemessen — er hat an dieser Stelle gar nicht gemessen.**

Überführt hat ihn die Regel, die seit dem 20. August in `CLAUDE.md` steht: *Wer
einen Wächter über eine Aufzählung baut, prüft ihn an dem Fall, der ihn
ausgelöst hat.* Mit einer Klammer, die `${…}` kennt, sind es **zehn**, und der
Bruch beisst.

**Dieselbe Zahl ist dabei zweimal berichtigt worden** — sieben (der Plural
`errors` fehlte), neun (die Klammer), zehn. Keine der drei stand vor dem Lauf
fest; gemessen hat sie jedes Mal der Wächter selbst.

> **Eine Untergrenze ist kein Formalismus — sie ist die einzige Stelle, an der
> ein Wächter merkt, dass sein Ausdruck ins Leere greift.**

Für `versteckt` in §8 ist deshalb **keine** Zahl vorhergesagt worden — nur der
Mechanismus. Gemessen sind 6.

---

## 11 · Die Bilanz

| | Punkt | Ergebnis |
|---|---|---|
| 1 | Die Lauscher stehen da | erfüllt — 13 gegen 13 |
| 2 | Legacy wird gesehen *(Ausschluss)* | erfüllt — 2307 vorher wie nachher |
| 3 | Keine Zusage über aussen *(Ausschluss)* | erfüllt — 0/0/0, Satz 1, Gegenprobe 13 |
| 4 | Der Verwalter wird benannt | erfüllt — `nftables` |
| 5 | IPv6 | erfüllt — 5 `inet6`, ohne Klammern |
| 6 | Nicht feststellbar | **nicht erfüllt** — Befund 1 |
| 7 | Die Tür | erfüllt — 13 Lauscher, 0 Namen |
| 8 | 390 px | erfüllt — vier Lagen, `dokument = 0` |

**A3s erster Wurf ist nicht abgenommen.** Der Weg dahin ist kurz und benannt:
Befund 1 beheben, ausliefern, Punkt 6 noch einmal messen.

**Der Prüfstand ist abgeräumt und der Abbau belegt:** `iptables-legacy -S`
gleich dem Vorflug (`legacy wie vorher`), `nft list ruleset` wieder 2307 Bytes,
alle vier Dienste `active`. Die `table ip filter`, vor der `docs/109 §6` warnt,
ist nicht entstanden — die Legacy-Regel legt keine an.

---

## 12 · Was offen bleibt

- **Befund 1** (§9.1) — der schweigende Bereich. **Behoben am 8. September**
  mit `UnknownStateTest` und zwei Brüchen; er hält die Abnahme auf, bis Punkt 6
  gegen die nächste Fassung noch einmal gemessen ist.
- **Befund 2** (§9.2) — der doppelte Punkt. **Behoben** mit `AgentMessageTest`
  und zwei Brüchen, in beide Richtungen.
- **Befund 3** (§9.3) — die zweite Fassung im Wächter. **Behoben.**
- **Die Konsolenmeldung** (§9.7) — ungeklärt, ein Inkognito-Fenster entscheidet.
- **Zwei Beobachtungen über den Wortlaut `nftables`:** `nft list ruleset` nennt
  in seinen eigenen Warnungen dreimal `iptables-nft` als Verwalter, und
  `inet f2b-table` gehört **fail2ban**. Die Seite nennt die Maschine und nicht
  den Schreiber. Das ist nach `docs/109 §1.1` entworfen — feiner wird es erst im
  zweiten Wurf.
- **Was A3s erster Wurf nicht wird** (`docs/109 §8`): kein Schreibweg, keine
  Antwort auf „von aussen erreichbar", kein Regelwerk im Wortlaut, kein `check`
  in der Bestandsdiagnose, kein UDP.
