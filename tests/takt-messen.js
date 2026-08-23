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
 */
(() => {
  const start = Date.now()
  const setzen = XMLHttpRequest.prototype.setRequestHeader
  const senden = XMLHttpRequest.prototype.send

  /*
   * Der Kopfzeile nach und nicht der Adresse nach: Eine Teilnachladung geht an
   * dieselbe Adresse wie die Seite selbst. Was sie unterscheidet, ist
   * `X-Inertia-Partial-Data` — und darin steht, welche Ablage geholt wird.
   */
  XMLHttpRequest.prototype.setRequestHeader = function (name, wert) {
    if (String(name).toLowerCase() === 'x-inertia-partial-data') {
      this.__teil = wert
    }

    return setzen.apply(this, arguments)
  }

  XMLHttpRequest.prototype.send = function () {
    if (this.__teil) {
      console.log('Nachladen bei ' + ((Date.now() - start) / 1000).toFixed(1) + 's — ' + this.__teil)
    }

    return senden.apply(this, arguments)
  }

  console.log('Taktprobe läuft. Uhr auf 0. Jetzt einmal auf „Aktualisieren" klicken — es muss sofort eine Zeile kommen.')
})()
