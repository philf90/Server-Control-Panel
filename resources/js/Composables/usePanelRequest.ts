/*
 * Der eine HTTP-Weg dieses Panels neben Inertia.
 *
 * ## Warum es diese Datei gibt
 *
 * Bis P5c Schritt 4 kam jede Antwort über Inertia, also über eine
 * Seitennavigation. Die Konsole konnte das nicht: Ihre Griffe geben JSON zurück
 * (`docs/46 §20.9`), weil ein Filterwert und ein Zeilenschlüssel nicht in eine
 * Adresse gehören — dort stünden sie im Zugriffsprotokoll des Webservers, in
 * der Verlaufsliste des Browsers und in jedem `Referer`.
 *
 * `useConsole.ts` trug den Mechanismus deshalb selbst, mit dem Satz darüber:
 *
 * > **Ein Mechanismus, den zwei Stellen selbst bauen, hat zwei Fassungen — und
 * > die zweite ist die, die den Kopf vergisst.**
 *
 * **Der Satz stimmte und war von nichts geprüft.** Als P6 den Baum bekam,
 * brauchte er denselben Weg — und damit war der zweite Aufrufer genau der Fall,
 * vor dem der Kommentar warnte. Der Mechanismus steht seitdem hier, die Konsole
 * benutzt ihn, und `PanelRequestTest` hält fest, dass es genau **eine** Stelle
 * mit einem `fetch` gibt.
 *
 * ## Die drei Kopfzeilen, und keine ist Zierde
 *
 * **`X-XSRF-TOKEN`** aus dem gleichnamigen Keks. Laravel legt ihn bei jeder
 * Antwort der Web-Gruppe ab; ohne ihn weist `ValidateCsrfToken` jeden
 * `POST`-Griff mit 419 ab — und zwar **nach** der Anmeldung und ohne dass die
 * Seite etwas Falsches gemacht hätte.
 *
 * **`Accept: application/json`** sorgt dafür, dass ein Validierungsfehler als
 * JSON zurückkommt und nicht als Umleitung. Ohne ihn wäre die Antwort auf eine
 * fehlerhafte Anfrage eine 302 auf die vorige Seite, und `fetch` folgte ihr
 * stillschweigend: Der Aufrufer bekäme HTML und meldete „unerwartete Antwort",
 * wo eine brauchbare Begründung stand.
 *
 * **`X-Requested-With: XMLHttpRequest`** aus demselben Grund, eine Schicht
 * tiefer — daran erkennt Laravel eine Anfrage, die keine Umleitung verträgt.
 */

/** Was ein gescheiterter Griff mitbringt: den Satz, den der Kunde liest. */
export class PanelRequestError extends Error {}

/**
 * Der Wert des `XSRF-TOKEN`-Kekses.
 *
 * Er ist URL-kodiert abgelegt und muss dekodiert übergeben werden — ein
 * Base64-Wert endet häufig auf `=`, und das steht im Keks als `%3D`.
 */
function csrfToken(): string {
  const treffer = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

  return treffer ? decodeURIComponent(treffer[1]) : ''
}

/**
 * Einen Griff des Panels aufrufen, der JSON zurückgibt.
 *
 * **Immer `POST`, auch wenn er nur liest.** Damit bleiben alle Griffe in einer
 * Bauform, und ein Wert des Kunden landet nie in einer Adresse. Die Adresse
 * setzt der Aufrufer zusammen; wie sie aussieht, weiss er besser als diese
 * Datei.
 *
 * @throws PanelRequestError mit dem Satz, den der Server oder der Agent geschickt hat
 */
export async function ask<T>(url: string, args: Record<string, unknown> = {}): Promise<T> {
  const antwort = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': csrfToken(),
    },
    body: JSON.stringify(args),
  })

  /*
   * **Erst den Rumpf lesen, dann über den Status entscheiden.** Ein 422 trägt
   * die Begründung, an der zwei Abnahmekriterien hängen (`docs/46 §4`, Punkte 4
   * und 6) — wer beim Status abbricht, wirft genau sie weg und zeigt
   * „fehlgeschlagen".
   */
  const text = await antwort.text()
  let inhalt: unknown = null

  try {
    inhalt = text === '' ? null : JSON.parse(text)
  } catch {
    inhalt = null
  }

  if (antwort.ok) {
    return inhalt as T
  }

  const gemeldet =
    inhalt !== null &&
    typeof inhalt === 'object' &&
    typeof (inhalt as { message?: unknown }).message === 'string'
      ? (inhalt as { message: string }).message
      : ''

  if (gemeldet !== '') {
    throw new PanelRequestError(gemeldet)
  }

  /*
   * **Der Rückfall nennt den Status und nicht „ein Fehler ist aufgetreten".**
   * 419 heisst abgelaufene Sitzung, 403 fehlende Berechtigung, 500 ein Fehler
   * bei uns — drei verschiedene Handlungen für den Lesenden. Ein Satz, der alle
   * drei abdeckt, deckt keinen davon ab.
   */
  throw new PanelRequestError(`Die Anfrage ist mit Status ${antwort.status} gescheitert.`)
}
