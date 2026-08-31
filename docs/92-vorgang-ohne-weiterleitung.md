# 92 — Ein Vorgang ohne Weiterleitung

**Vorgemerkt am 31. August 2026.** Dies ist keine Stufe, die jetzt gebaut wird,
sondern der Ort, an dem eine Entscheidung wartet, bis sie an der Reihe ist. Sie
gehört nach **P9** — siehe §5.

---

## 1 · Der Befund

Gefunden vom Betreiber am 31. August, beim Fahren von Punkt 11 des Nachlaufs zu
A2 (`docs/91 §17`). Er ist nicht beim Bauen aufgefallen und auch nicht beim
Prüfen, sondern beim **Erklären**: Die Frage lautete, wie man denselben Knopf
ein zweites Mal drückt, und die Antwort war „mit dem Zurück-Knopf des Browsers".

> **Ein Weg, den man nur erklären kann, indem man den Browser zu Hilfe nimmt,
> ist keiner, den die Anwendung anbietet.**

**Gemessen:** **21** Weiterleitungen auf `operations.show`, aus **sieben**
Controllern — `DatabaseController` (5), `SubscriptionController` (6),
`UpdatesController` (4), `OperationController` (3), `DomainController`,
`ServerController`, `TlsSettingsController`.

Jede davon endet auf einer Seite, deren Brotkrümel **eine** Verknüpfung trägt:
`Vorgänge`, also die Liste aller Vorgänge. Nicht die Seite, von der man kam.

Wer eine Domain anlegt, landet beim Vorgang und findet von dort nicht zur
Domain. Wer Pakete einspielt, findet nicht zurück zu den Updates. Der Weg
zurück ist der Zurück-Knopf des Browsers — und der ist kein Bedienelement
dieses Panels.

---

## 2 · Warum die kleine Behebung nicht genügt

Am selben Tag sind zwei kleinere Antworten gebaut worden, und sie decken den
Fall zum Teil ab:

- **Die Herkunft steht im Brotkrümel.** Eine Spalte `origin`, gefüllt beim
  Absetzen, ergibt `Updates ← Vorgänge · … · Nummer 726`.
- **Der Gegenstand steht auf der Seite.** `subject_type`/`subject_id` gab es
  seit dem 4. August und wurde von keiner Oberfläche gelesen.

Damit gibt es einen Weg zurück. **Was bleibt, ist der Umweg selbst:** Man hat
einen Knopf gedrückt und steht auf einer anderen Seite, obwohl man nichts
anderes sehen wollte.

> **Ein Verweis zurück behebt, dass man gestrandet ist — nicht, dass man
> weggetragen wurde.**

---

## 3 · Was gebaut werden soll

**Nach dem Absetzen bleibt man, wo man ist.** Oben auf der Seite erscheint ein
Streifen:

    Vorgang 726 läuft — ansehen

Er zeigt Fortschritt und Zustand. Ist der Vorgang fertig, wird daraus die
Meldung, die heute die Vorgangsseite trägt — grün, bernstein oder rot, mit
demselben Satz. Der Verweis „ansehen" führt weiter auf die Detailseite, für den,
der die Ausgabe lesen will.

Die Vorgangsseite bleibt, was sie ist. Sie wird nur nicht mehr der Ort, an dem
man **unfreiwillig** landet.

---

## 4 · Was dabei zu entscheiden ist

Diese vier Fragen sind der Grund, warum das eine eigene Stufe ist und kein
Nachmittag.

**1. Der Log-Strom.** Die Vorgangsseite hält eine SSE-Verbindung
(`/operations/{id}/stream`). Auf **jeder** Seite mitzulaufen ist teuer — unter
`artisan serve` blockiert ein offener Strom die einzige Anfrage (`CLAUDE.md`,
„Diese Umgebung"), und auf dem Server kostet er einen FPM-Prozess je offenem
Streifen. Der Streifen braucht also entweder eine billigere Quelle (Abfrage im
Takt) oder eine Begründung, warum der Strom hier tragbar ist.

**2. Was passiert, wenn man weg navigiert?** Der Vorgang läuft weiter — das
steht heute schon in der Rückfrage und stimmt. Aber der Streifen ist dann fort,
und der Betreiber hat kein Zeichen mehr. Braucht es eine Ablage („diese
Vorgänge hast du losgeschickt"), die über Seitenwechsel hinweg hält?

**3. Welche Seite lädt sich nach?** Wenn `system.packages.upgrade` fertig ist,
ist die Updates-Seite veraltet — sie zeigt sieben Pakete, die es nicht mehr
gibt. Ein Nachladen je Seite ist eine Entscheidung je Seite und keine
allgemeine.

**4. Was ist mit den Vorgängen, die niemand ausgelöst hat?** Die
Zertifikatsautomatik und der Cron-Einsammler erzeugen Vorgänge ohne Herkunft.
Für sie ändert sich nichts — aber die Regel muss sagen, dass sie gemeint sind.

---

## 5 · Wo es hingehört

**P9** (`docs/20 §9`, „Kundenfähigkeit und Betrieb"). Der Grund steht im
Abnahmekriterium dieser Stufe:

> Fertig, wenn ein fremder Kunde das Panel benutzen kann, ohne zu fragen —
> gemessen an einem Durchlauf mit einer Person, die das Projekt nicht kennt.

Genau dort fällt dieser Fehler auf, und genau dort ist er zu messen. Er gehört
nicht nach P9b (das ist Absicherung) und nicht nach P10 (das ist Härtung).

**Nicht früher**, und das ist eine Aussage über den Preis: Die kleine Behebung
vom 31. August nimmt dem Fehler seine Schärfe. Wer keinen Weg zurück hat, ist
gestrandet; wer einen hat, geht einen Schritt zu viel. Der zweite Zustand hält
bis P9 aus.

> **Ein Fehler, dessen schlimmster Teil behoben ist, wartet — und was von ihm
> bleibt, gehört aufgeschrieben und nicht erinnert.**

---

## 6 · Was schon gemessen ist

| | |
|---|---|
| Weiterleitungen auf `operations.show` | 21, aus sieben Controllern |
| Brotkrümel der Vorgangsseite | trug nur `Vorgänge` (bis zum 31. August) |
| `subject_type`/`subject_id` | seit dem 4. August da, von fünf Stellen gesetzt |
| Laufzeiten, an denen der Umweg sich lohnt | `refresh` 12 s, `upgrade` (7 Pakete) 18 s — gemessen auf `cloudsrv24` |

Die letzte Zeile ist die, die den Entwurf entscheiden könnte: **Ein Vorgang von
zwölf Sekunden braucht keine eigene Seite.** Ob es eine Grenze gibt, ab der der
Umweg richtig ist, ist nicht gemessen — und wer diese Stufe baut, misst sie an
den Vorgängen, die es dann gibt, und nicht an denen von heute.
