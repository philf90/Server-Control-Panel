import { PanelRequestError, ask as askPanel } from './usePanelRequest'

/*
 * Der Weg von der Konsole zum Panel.
 *
 * **Hier stand der Mechanismus selbst**, mit dem Satz darüber, dass es die
 * einzige Stelle sei, die `fetch` ruft. Der Satz war richtig und von nichts
 * geprüft — und als P6 den Baum bekam, brauchte der denselben Weg.
 *
 * > **Ein Mechanismus, den zwei Stellen selbst bauen, hat zwei Fassungen — und
 * > die zweite ist die, die den Kopf vergisst.**
 *
 * Er steht jetzt in {@link ./usePanelRequest}, und `PanelRequestTest` hält
 * fest, dass es dabei bleibt. Was hier bleibt, ist das, was die Konsole
 * ausmacht: dass ein Griff ein **kurzer Name** ist und keine Adresse.
 */

/**
 * Was ein gescheiterter Griff mitbringt.
 *
 * **Der Name bleibt**, obwohl die Klasse umgezogen ist: Fünf Stellen in
 * `Console.vue` fangen `ConsoleError`, und ein Umbenennen wäre eine Änderung an
 * fünf Dateien für nichts.
 */
export { PanelRequestError as ConsoleError }

/**
 * Einen Griff der Konsole aufrufen.
 *
 * **Der Griff ist ein kurzer Name und keine Adresse.** `tables`, `columns`,
 * `indexes` — die Adresse setzt diese Funktion zusammen, damit es genau eine
 * Stelle gibt, die weiss, wie sie aussieht. Dieselbe Überlegung wie bei
 * `EngineDriver::consoleOperation()` auf der anderen Seite.
 *
 * @throws ConsoleError mit dem Satz, den der Server oder der Agent geschickt hat
 */
export function ask<T>(
  databaseId: number,
  handle: string,
  args: Record<string, unknown> = {},
): Promise<T> {
  return askPanel<T>(`/databases/${databaseId}/console/${handle}`, args)
}
