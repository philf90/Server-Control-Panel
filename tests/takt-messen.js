/*
 * Die Messung zum Selbstlauf der Übersicht — wann wird nachgeladen, und
 * wie oft.
 *
 * In die Konsole des Browsers einfügen, während die Übersicht offen ist. Sie
 * meldet jede Anfrage, die Inertia als **Teilnachladung** stellt, mit ihrem
 * Zeitstempel seit dem Einsetzen.
 *
 * **Warum es dafür ein eigenes Mittel braucht.** Der Fehler, um den es geht,
 * ist nicht, dass etwas Falsches passiert — es passiert etwas **zu oft**:
 * `setInterval` kennt keine Änderung seiner Länge, und wer beim Umschalten den
 * alten Takt nicht anhält, hat danach zwei. Auf dem Bildschirm sieht das aus
 * wie ein Takt; die Kacheln aktualisieren sich, nur eben doppelt so häufig wie
 * eingestellt, und beim nächsten Umschalten dreifach.
 *
 * > **Ein zweiter Takt ist nicht daran zu erkennen, dass etwas Falsches
 * > passiert, sondern daran, dass etwas zu oft passiert.**
 *
 * Weder ein Bild noch die Überlaufmessung sagen darüber etwas. Ein Blick auf
 * die Uhr auch nicht: „es lädt nach" sieht bei 30 und bei 60 Sekunden gleich
 * aus.
 *
 * ---
 *
 * **Die Gegenprobe kommt zuerst und ist keine Formsache.** Nach dem Einsetzen
 * einmal auf „Aktualisieren" klicken: Es *muss* sofort eine Zeile erscheinen.
 * Ohne sie ist jede ausbleibende Zeile danach mehrdeutig — „der Takt ist aus"
 * und „die Probe misst nichts" sehen gleich aus.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 *
 * ---
 *
 * **Gelesen wird das Ausbleiben und nicht das Erscheinen.** Der Beleg dafür,
 * dass genau *ein* Zeitgeber läuft, sind die Zeilen, die **nicht** dastehen:
 * Nach dem Umschalten von 30 auf 60 Sekunden dürfen die Zeilen nur noch auf der
 * neuen Phase liegen. Läge dazwischen weiter alle 30 Sekunden eine, liefen
 * beide.
 *
 * Gemessen am 23. August 2026 auf `cloudsrv24` gegen `v0.7.0-rc.7` (`docs/76`):
 * dreimal 30,0 s, dann dreimal 60,0 s, keine Zeile dazwischen, nach „nicht von
 * allein" gar keine mehr.
 *
 * ---
 *
 * **Zwei Zahlen, die nach einem Fund aussehen und keiner sind.** Die erste
 * selbsttätige Zeile liegt selten genau auf dem Takt — der lief schon, als die
 * Probe eingesetzt wurde, und ihre Uhr fängt bei null an. Und unmittelbar nach
 * dem Umschalten steht eine Zeile ausserhalb jeder Phase: Das Panel lädt bei
 * einer Änderung sofort nach, damit niemand eine Minute auf frische Zahlen
 * wartet.
 *
 * **Die Probe lebt bis zum nächsten Neuladen der Seite.** Sie hängt sich in
 * `XMLHttpRequest.prototype`, und das ist nach einem Neuladen wieder das
 * ursprüngliche. Wer zwischendurch neu lädt, setzt sie neu ein — und fängt mit
 * der Gegenprobe wieder an.
 *
 * ---
 *
 * **Seit dem 11. Oktober 2026 misst sie auch den Streifen der laufenden
 * Vorgänge (B8, `docs/144`).** Dort fragt der Klient alle drei Sekunden
 * `runningOperations` nach, solange ein Vorgang nicht fertig ist. Die
 * Gegenprobe ist dort der laufende Vorgang selbst: Seine Zeilen müssen kommen,
 * bevor ihr Ausbleiben nach dem Ende etwas bedeutet.
 *
 * Dafür meldet sie drei Dinge mehr, und alle drei sind gemessen nötig:
 *
 * - **Ganze Seiten.** Ein Seitenwechsel über das Menü und das Neuladen über
 *   „Seite aktualisieren" sind Inertia-Anfragen ohne Teilangabe; bis hierher
 *   sah die Probe sie nicht. Ohne diese Zeile wäre „die Seite lädt nach dem
 *   Ende nicht von selbst nach" nicht zu unterscheiden von „die Probe sieht
 *   ganze Seiten nicht". Die Gegenprobe dafür ist der Knopf „Seite
 *   aktualisieren": genau eine Zeile `Seite`.
 * - **Ströme.** Nur die Vorgangsseite darf eine `EventSource` öffnen
 *   (`StreamPageTest`); ein Strom auf jeder Seite belegte je Reiter einen der
 *   zwölf Arbeiter des Panels. Die Probe meldet jedes Öffnen mit seiner
 *   Adresse, und die Gegenprobe ist die Vorgangsseite eines laufenden
 *   Vorgangs: genau eine Zeile `Strom`. Gemessen wird am Öffnen und nicht an
 *   `performance.getEntriesByType('resource')` — dort steht ein Strom erst,
 *   wenn er zu Ende ist, und solange er offen ist, stünde dort dieselbe Null
 *   wie auf einer Seite ohne Strom.
 * - **Ein zweites Einsetzen wird abgewiesen.** Ohne Sperre hängte es sich ein
 *   zweites Mal ein, und jede Anfrage stünde doppelt da — **genau das Bild
 *   eines doppelten Takts**, also des Befundes, den die Probe finden soll.
 *
 *   > **Eine Probe, die sich zweimal einhängt, meldet ihr eigenes Doppel als
 *   > Doppel des Prüflings.**
 */
(() => {
  const STAND = '2026-10-11'

  if (window.__taktprobe !== undefined) {
    console.log('Die Taktprobe läuft schon (Stand ' + window.__taktprobe + '). Ein zweites Einsetzen hinge sich ein zweites Mal ein, und jede Anfrage stünde doppelt da.')

    return
  }

  window.__taktprobe = STAND

  const start = Date.now()
  const oeffnen = XMLHttpRequest.prototype.open
  const setzen = XMLHttpRequest.prototype.setRequestHeader
  const senden = XMLHttpRequest.prototype.send
  const uhr = () => ((Date.now() - start) / 1000).toFixed(1) + 's'

  XMLHttpRequest.prototype.open = function (methode, adresse) {
    this.__adresse = adresse

    return oeffnen.apply(this, arguments)
  }

  /*
   * Der Kopfzeile nach und nicht der Adresse nach: Eine Teilnachladung geht an
   * dieselbe Adresse wie die Seite selbst. Was sie unterscheidet, ist
   * `X-Inertia-Partial-Data` — und darin steht, welche Ablage geholt wird.
   * Eine ganze Seite trägt nur `X-Inertia`.
   */
  XMLHttpRequest.prototype.setRequestHeader = function (name, wert) {
    const kopf = String(name).toLowerCase()

    if (kopf === 'x-inertia-partial-data') {
      this.__teil = wert
    }

    if (kopf === 'x-inertia') {
      this.__inertia = true
    }

    return setzen.apply(this, arguments)
  }

  XMLHttpRequest.prototype.send = function () {
    if (this.__teil) {
      console.log('Nachladen bei ' + uhr() + ' — ' + this.__teil)
    } else if (this.__inertia) {
      console.log('Seite bei ' + uhr() + ' — ' + new URL(String(this.__adresse), location.href).pathname)
    }

    return senden.apply(this, arguments)
  }

  /*
   * Eine abgeleitete Klasse und keine Funktion: So behält sie die Konstanten
   * `CONNECTING`, `OPEN` und `CLOSED` und das `instanceof` des Originals. Die
   * Zeile steht vor `super()`, weil sie `this` nicht braucht.
   */
  const Strom = window.EventSource

  window.EventSource = class extends Strom {
    constructor (adresse, optionen) {
      console.log('Strom bei ' + uhr() + ' — ' + new URL(String(adresse), location.href).pathname)
      super(adresse, optionen)
    }
  }

  console.log('Taktprobe läuft (Stand ' + STAND + '). Uhr auf 0. Zuerst die Gegenprobe: eine Handlung, die sicher nachlädt — auf der Übersicht „Aktualisieren", beim Streifen der laufende Vorgang selbst.')
})()
