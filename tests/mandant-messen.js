/*
 * Der Mandantenübergriff — Punkt 11 des Abnahmekriteriums (`docs/51 §4`).
 *
 * **Was gemessen wird.** Angemeldet als Kunde von Abonnement A wird in jeder
 * der 22 Routen mit `{subscription}` die Kennung von Abonnement B eingesetzt.
 * Keine davon darf durchkommen.
 *
 * **Warum das im Browser läuft und nicht als Test.** Dieselbe Begründung wie
 * bei `srvpanel:acceptance-web`: Ein Test läuft gegen SQLite, einen erfundenen
 * Agenten und eine Sitzung, die es so nicht gibt. Hier zählt die echte Route
 * mit der echten Sitzung des echten Kunden — und die hat der Betreiber schon
 * offen. Ein Schnipsel in der Konsole braucht kein Anmelden, kein
 * Cookie-Kopieren und keine zweite Fassung der Anwendung.
 *
 * **Das Kriterium ist beim Ausschreiben berichtigt worden.** `docs/51 §4`
 * verlangt „403 in allen 22, und zwar aus der Policy und nicht aus einem 404".
 * Der Code kann das nicht liefern: {@see ApplyTenancy} klammert die Abfragen
 * auf die Abonnements des Kontos, **bevor** die Policy gefragt wird — ein
 * fremdes Abonnement ist damit schon für die Auflösung von `{subscription}`
 * unauffindbar, und das ergibt einen 404.
 *
 * Das ist die stärkere Antwort und nicht die schwächere: Ein 403 bestätigt die
 * Existenz, ein 404 nicht.
 *
 * > **Ein Kriterium, das eine Zahl vorschreibt, prüft die Zahl und nicht die
 * > Wand.**
 *
 * Gemessen wird deshalb: **kein 2xx, und der Grund ist benennbar.**
 *
 * **Und ohne die Gegenprobe sagt die Reihe nichts.** Dieselbe Route mit der
 * *eigenen* Kennung muss in den Controller kommen. Sichtbar wird das an einem
 * `422`: Der Rumpf wird absichtlich weggelassen, also scheitert jede
 * verändernde Route an ihrer eigenen Prüfung — **nachdem** die Autorisierung
 * sie durchgelassen hat.
 *
 * **Für zwei der 22 Routen stimmt das nicht, und hier stand das Gegenteil.**
 * Der Kopf behauptete, der Lauf verändere nichts. Am 18. August 2026 hat er auf
 * `cloudsrv24` zweimal einen Cronjob gelöscht, und beim ersten Mal sah es
 * aus, als sei er „nicht gespeichert worden".
 *
 * > **Ein Vorgang, der nichts entgegennimmt, hat nichts, woran er scheitern
 * > kann.**
 *
 * `CronController::destroy` und `SftpController::destroy` prüfen keinen Rumpf —
 * sie löschen und leiten weiter. Für eine löschende Route heisst
 * „durchgelassen" **wörtlich** „hat gelöscht"; anders ist ihre Erreichbarkeit
 * nicht zu belegen. Die beiden stehen deshalb am Ende der Liste (damit die
 * lesenden ihren Gegenstand noch vorfinden), tragen die Spalte
 * `Nebenwirkung`, und der Lauf warnt davor, bevor er beginnt.
 *
 * **Zwei Kopfzeilen sind nach dem ersten Lauf herausgeflogen, und beide hätten
 * das Ergebnis geschönt** (18. August 2026, `docs/62`):
 *
 * | war | ergab | warum das zu wenig ist |
 * |---|---|---|
 * | `X-Inertia: true` | `409` auf jede GET-Route | `HandleInertiaRequests` liegt in der `web`-Gruppe, also **vor** dem `can:` der Route — ein 409 belegt die Auflösung, nicht die Policy |
 * | `redirect: 'manual'` | `0` auf jede verändernde Route | eine undurchsichtige Weiterleitung; ein Netzwerkfehler sieht genauso aus |
 *
 * > **Ein Messwert, den auch ein Fehlschlag erzeugt, ist keiner.**
 *
 * **Und der zweite Lauf hat zwei weitere Fehler dieses Werkzeugs gezeigt** —
 * beide in der Gegenprobe, keiner im Prüfling:
 *
 * 1. **`fetch` folgt einer Weiterleitung von selbst**, und bei `PUT` und
 *    `DELETE` behält es dabei die Methode; nur aus `POST` macht der Standard
 *    ein `GET`. Vier Zeilen meldeten `405` — nicht weil die Route die Methode
 *    verböte (dann hätte auch die fremde Kennung 405 gegeben statt 404),
 *    sondern weil die *Zielseite* der Weiterleitung kein PUT annimmt. Seitdem
 *    steht in jeder Zeile, ob umgeleitet wurde und wohin.
 * 2. **Die Spalte `eindeutig` behauptete etwas, das sie nicht geprüft hatte.**
 *    Sie sah nur nach, ob eine fremde Zweitkennung mitgegeben war, und meldete
 *    „ja" für eine Zeile, deren eigene Seite hängenblieb. Eindeutig ist eine
 *    Zeile erst, wenn ihre Gegenprobe durchkam.
 *
 * > **Eine Spalte, die etwas behauptet, das sie nicht geprüft hat, ist
 * > schlimmer als keine.**
 *
 * Das ist der dritte Anlauf dieses Werkzeugs, und alle drei Fehler steckten in
 * ihm und nicht im Panel — dasselbe Verhältnis wie in `docs/45`, `docs/47`,
 * `docs/48` und `docs/59`.
 *
 * So wird er benutzt — in der Konsole des Browsers, angemeldet als der Kunde
 * von EIGEN, mit den beiden Kennungen aus der Abonnementliste:
 *
 *     await mandantMessen({ eigen: 2, fremd: 1 })
 *
 * Für die vier Routen mit einem zweiten Parameter (`{job}`, `{key}`) lassen
 * sich echte Kennungen mitgeben. Ohne sie kann ein 404 auch vom zweiten
 * Parameter kommen, und der Lauf sagt das dann auch:
 *
 *     await mandantMessen({ eigen: 2, fremd: 1, eigenJob: 7, fremdJob: 3 })
 *
 * **Die eigenen Zweitkennungen werden vorher geprüft** — siehe `vorflug()`
 * weiter unten. Eine Kennung, die man von einer Messung in die nächste
 * mitnimmt, trägt ihr Abonnement nicht mit, und ohne diese Prüfung sieht der
 * Fehler wie ein Befund am Panel aus.
 */

async function mandantMessen ({ eigen, fremd, eigenJob, fremdJob, eigenKey, fremdKey }) {
  if (!eigen || !fremd) {
    throw new Error('eigen und fremd sind beide nötig — sonst misst der Lauf sich selbst.')
  }

  const token = decodeURIComponent(
    (document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || ''
  )

  if (!token) {
    throw new Error('Kein XSRF-TOKEN im Cookie — ist diese Sitzung angemeldet?')
  }

  // Die 22 Routen aus `routes/web.php`. `zweit` heisst: die Route trägt einen
  // zweiten Parameter, und ohne echte Kennung dafür ist ein 404 nicht eindeutig.
  const routen = [
    ['GET', '/files'],
    ['POST', '/files/tree'],
    ['GET', '/files/edit'],
    ['PUT', '/files'],
    ['POST', '/files/directory'],
    ['DELETE', '/files'],
    ['POST', '/files/rename'],
    ['POST', '/files/move'],
    ['POST', '/files/copy'],
    ['GET', '/files/search'],
    ['POST', '/files/extract'],
    ['POST', '/files/compress'],
    ['POST', '/files/upload'],
    ['POST', '/files/chmod'],
    ['GET', '/sftp'],
    ['POST', '/sftp/keys'],
    ['GET', '/cron'],
    ['POST', '/cron'],
    ['PUT', '/cron/{job}', 'job'],
    ['GET', '/cron/{job}/runs', 'job'],
    ['POST', '/cron/preview'],

    // **Die beiden zerstörenden zuletzt**, damit die lesenden ihren Gegenstand
    // noch vorfinden. Beim ersten Wurf stand `DELETE /cron/{job}` davor — der
    // Job war weg, bevor `…/runs` ihn ansah, und dessen 404 sah aus wie eine
    // gehaltene Grenze.
    ['DELETE', '/sftp/keys/{key}', 'key', 'zerstoert'],
    ['DELETE', '/cron/{job}', 'job', 'zerstoert'],
  ]

  /**
   * **Der Vorflug: Liegen die eigenen Zweitkennungen überhaupt auf EIGEN?**
   *
   * Am 19. August lief dieser Lauf mit `eigenJob: 4` — einer Kennung aus der
   * Messung der Punkte 9 und 10, die auf dem **fremden** Abonnement lag. Drei
   * Zeilen meldeten daraufhin „BLIEB HÄNGEN", und das liest sich zunächst wie
   * ein Befund am Panel. Es war einer an dem, was diesem Lauf übergeben wurde.
   *
   * > **Eine Kennung, die man von einer Messung in die nächste mitnimmt, trägt
   * > ihr Abonnement nicht mit.**
   *
   * Geprüft wird nur die **eigene** Seite. Die fremde lässt sich nicht prüfen,
   * ohne die Wand zu umgehen, die dieser Lauf messen soll — und sie muss auch
   * nicht: Die Route trägt kein `scopeBindings()`, `{subscription}` wird vor
   * `{job}` und `{key}` aufgelöst, und der 404 fliegt aus der Mandantenklammer,
   * bevor die Zweitkennung angefasst wird.
   *
   * Der Vorflug liest die Seite als HTML und nicht über eine Inertia-Anfrage:
   * `X-Inertia` erzeugt hier einen 409, und das war Fehler 1 dieses Werkzeugs.
   */
  const vorflug = async (pfad, ablage, kennung, name) => {
    if (kennung == null) return

    const antwort = await fetch(`/subscriptions/${eigen}${pfad}`, { credentials: 'same-origin' })
    const treffer = (await antwort.text()).match(/data-page="([^"]+)"/)

    if (!treffer) {
      throw new Error(
        `Der Vorflug kann ${pfad} nicht lesen (Status ${antwort.status}). Ohne ihn misst `
        + 'der Lauf eine Kennung, von der niemand weiss, ob es sie gibt.'
      )
    }

    // Die Ablage steht HTML-maskiert im Attribut; ein textarea entmaskiert sie
    // vollständig, ohne dass hier eine zweite Fassung der Regeln entsteht.
    const roh = document.createElement('textarea')
    roh.innerHTML = treffer[1]

    const vorhanden = (JSON.parse(roh.value).props[ablage] || []).map((e) => e.id)

    if (!vorhanden.includes(kennung)) {
      throw new Error(
        `${name}: ${kennung} liegt nicht auf Abonnement ${eigen} — dort gibt es `
        + `${vorhanden.length ? vorhanden.join(', ') : 'keine'}. Eine Kennung, die man von einer `
        + 'Messung in die nächste mitnimmt, trägt ihr Abonnement nicht mit.'
      )
    }
  }

  await vorflug('/cron', 'jobs', eigenJob, 'eigenJob')
  await vorflug('/sftp', 'keys', eigenKey, 'eigenKey')

  const zweiter = (art, eigenesAbo) => {
    if (art === 'job') return (eigenesAbo ? eigenJob : fremdJob) ?? null
    if (art === 'key') return (eigenesAbo ? eigenKey : fremdKey) ?? null

    return undefined
  }

  /**
   * Eine Anfrage, absichtlich ohne Rumpf.
   *
   * Kommt sie bis in den Controller, scheitert sie an dessen Prüfung — `422`.
   * Genau das ist der Beleg, dass die Autorisierung sie durchgelassen hat, und
   * genau deshalb verändert dieser Lauf nichts.
   */
  const anfrage = async (methode, pfad) => {
    const antwort = await fetch(pfad, {
      method: methode,
      headers: {
        'X-XSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
    })

    // **Der Status allein sagt nicht, wessen Status es ist.** `fetch` folgt
    // einer Weiterleitung von sich aus, und bei PUT und DELETE behält es dabei
    // die Methode — nur aus POST macht der Standard ein GET. Der Code stammt
    // dann von der *Zielseite* und nicht vom Vorgang: Ein `405` heisst „das
    // Ziel nimmt PUT nicht an", nicht „die Route verbietet PUT".
    //
    // > **Ein Statuscode nach einer gefolgten Weiterleitung gehört einer
    // > anderen Anfrage.**
    return {
      status: antwort.status,
      umgeleitet: antwort.redirected,
      ziel: antwort.redirected ? new URL(antwort.url).pathname : null,
    }
  }

  // **Zwei der 22 Routen zerstören ihren Gegenstand, und das lässt sich nicht
  // vermeiden.** Ein Vorgang, der nichts entgegennimmt, hat nichts, woran er
  // scheitern kann: `CronController::destroy` prüft keinen Rumpf, es löscht und
  // leitet weiter. Für eine Route, die löscht, heisst „durchgelassen" wörtlich
  // „hat gelöscht" — anders ist ihre Erreichbarkeit nicht zu belegen.
  console.warn(
    'ACHTUNG: Dieser Lauf löscht auf dem eigenen Abonnement den Cronjob '
    + `${eigenJob ?? '(keiner angegeben)'} und den SFTP-Schlüssel `
    + `${eigenKey ?? '(keiner angegeben)'}. Beides sind Wegwerf-Gegenstände für `
    + 'genau diese Messung — die Gegenprobe einer löschenden Route ist die Löschung.'
  )

  const zeilen = []

  for (const [methode, muster, art, zerstoert] of routen) {
    const bauen = (abo, eigenesAbo) => {
      const zweit = zweiter(art, eigenesAbo)
      const pfad = muster.replace(/\{(job|key)\}/, String(zweit ?? 999999))

      return `/subscriptions/${abo}${pfad}`
    }

    const fremdAntwort = await anfrage(methode, bauen(fremd, false))
    const eigenAntwort = await anfrage(methode, bauen(eigen, true))

    const fremdCode = fremdAntwort.status
    const eigenCode = eigenAntwort.status

    // **Ein 2xx auf die fremde Kennung ist der Übergriff.** Alles andere hält
    // — aber der Grund gehört daneben, sonst ist es eine Zahl ohne Ursache.
    // Eine gefolgte Weiterleitung zählt dabei nicht als Durchkommen: Der Code
    // stammt dann von der Zielseite.
    const uebergriff = fremdCode >= 200 && fremdCode < 300 && !fremdAntwort.umgeleitet
    const durchgelassen = !(eigenCode === 403 || eigenCode === 404)

    // **Eindeutig ist eine Zeile erst, wenn ihre Gegenprobe durchkam.** Der
    // erste Wurf prüfte nur, ob eine fremde Zweitkennung mitgegeben wurde — und
    // meldete deshalb „ja" für eine Zeile, deren eigene Seite hängenblieb. Eine
    // Spalte, die etwas behauptet, das sie nicht geprüft hat, ist schlimmer als
    // keine.
    const eindeutig = durchgelassen
      ? 'ja'
      : art
        ? 'nein — die eigene Kennung kam auch nicht durch'
        : 'nein — die Gegenprobe fehlt'

    zeilen.push({
      Route: `${methode} ${muster}`,
      fremd: fremdCode,
      eigen: eigenCode,
      'eigen umgeleitet': eigenAntwort.umgeleitet ? `→ ${eigenAntwort.ziel}` : 'nein',
      Grund: fremdCode === 404 ? 'Klammer (nicht auffindbar)'
        : fremdCode === 403 ? 'Policy'
          : uebergriff ? 'ÜBERGRIFF' : `unerwartet ${fremdCode}`,
      Gegenprobe: durchgelassen ? 'durchgelassen' : 'BLIEB HÄNGEN',
      eindeutig,
      Nebenwirkung: zerstoert ? 'die eigene Kennung ist jetzt gelöscht' : 'keine',
    })
  }

  console.table(zeilen)

  const uebergriffe = zeilen.filter((z) => z.Grund === 'ÜBERGRIFF')
  const haengen = zeilen.filter((z) => z.Gegenprobe === 'BLIEB HÄNGEN')

  console.log(`fremd: ${zeilen.length - uebergriffe.length} von ${zeilen.length} gehalten`)
  console.log(`eigen: ${zeilen.length - haengen.length} von ${zeilen.length} durchgelassen`)

  // **Die Gegenprobe ist die Aussage.** Halten alle 22 fremden Aufrufe, aber
  // kommt keiner der eigenen durch, misst der Lauf eine Anmeldung, die nichts
  // darf — und nicht eine Klammer, die trennt.
  if (haengen.length === zeilen.length) {
    console.error(
      'OHNE MESSUNG: keine einzige eigene Kennung kam durch. '
      + 'Angemeldet als der Kunde von EIGEN? Dann sagt diese Reihe nichts über die Klammer.'
    )
  }

  return { zeilen, uebergriffe: uebergriffe.length, haengen: haengen.length }
}
