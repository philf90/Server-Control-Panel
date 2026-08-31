/*
 * Der Zustand einer systemd-Unit — in Worten und in einer Farbe.
 *
 * **Anlass ist Befund 5 aus dem Nachlauf zu A2** (`docs/91 §13`): Die Übersicht
 * druckte `active_state` roh — also **„active"**, wo die Dienste-Seite „läuft"
 * sagt. Zweierlei war daran falsch.
 *
 * **Erstens ist „active" englisch**, und `docs/19 §4a` bindet die Texte der
 * Oberfläche auf Deutsch. `WordChoiceTest` konnte es nicht sehen: Der Wert
 * entsteht zur Laufzeit und steht als Zeichenkette nirgends in der Vorlage.
 *
 * > **Ein Wort, das erst zur Laufzeit entsteht, entgeht jedem Wächter, der
 * > Zeichenketten liest.**
 *
 * **Zweitens war `dienstRang()` eine zweite und ärmere Fassung von `rang()`**:
 * Sie kannte weder `activating` noch die Nachsicht für Dienste, die ein Timer
 * startet. Vor A2 gab es die zweite Stelle nicht — die Stufe hat die Abweichung
 * selbst erzeugt.
 *
 * > **Eine Stufe, die eine zweite Anzeige für dieselbe Sache baut, erzeugt die
 * > Abweichung, die sie danach halten muss.**
 *
 * ## Was hier nicht gelöst ist
 *
 * **Die Übersicht kann nicht wissen, ob ein Dienst von einem Timer gestartet
 * wird.** Sie fragt `service.status` je Unit einzeln, und die Zuordnung steht
 * in `Triggers` auf dem **Timer** — also auf einer anderen Unit.
 * `Units::markScheduled()` braucht dafür alle Zeilen einer Antwort.
 *
 * Heute fällt das nicht auf: `Catalog::essential()` führt den Agenten, den
 * Webserver und die Datenbank, und keiner davon ist `Type=oneshot`. Käme je
 * einer dazu, stünde er auf der Übersicht als „gestoppt" da und auf der
 * Dienste-Seite als „wartet auf seinen Timer".
 *
 * Die Behebung wäre, dass die Übersicht ihre Zeilen aus `system.units.list`
 * nimmt — ein Aufruf statt fünf. Sie trägt aber auch die PostgreSQL-Cluster,
 * und die stehen bewusst nicht im Katalog; das ist eine eigene Entscheidung.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 */

/**
 * Was eine Anzeige von einer Unit wissen muss.
 *
 * Die drei letzten Felder sind **fehlend erlaubt** und nicht `null` erlaubt: Eine
 * Anzeige, die sie nicht hat, ist keine, die „nein" sagt — sie ist eine, die
 * nicht gefragt hat. Die Unterscheidung steht im Kopf dieser Datei.
 */
export type UnitLike = {
  present: boolean
  active_state: string
  sub_state?: string
  kind?: string
  has_next?: boolean | null
  scheduled?: boolean | null
}

/** Startet ein Timer diesen Dienst, und steht er gerade still? */
function wartet(unit: UnitLike): boolean {
  return unit.scheduled === true && unit.active_state === 'inactive'
}

/** Ein Timer, der `active` meldet und keinen nächsten Termin hat. */
function ohneTermin(unit: UnitLike): boolean {
  return unit.kind === 'timer' && unit.has_next === false
}

/**
 * Der Rang einer Zeile — er entscheidet die Farbe.
 *
 * **Ein Timer ohne nächsten Termin ist kaputt, obwohl er `active` meldet.** Das
 * ist der Satz, um den es in A2 geht: `ActiveState` steht beim gesunden wie beim
 * kaputten Timer auf `active` (gemessen gegen systemd 255, `docs/89 §3`). Wer
 * die Farbe an `active_state` hängt, malt beide grün.
 *
 * **Und derselbe Satz spiegelverkehrt:** Ein Dienst, den ein Timer startet,
 * steht zwischen seinen Läufen auf `inactive` — vier der eigenen zwölf sind so
 * gebaut. Wer die Farbe allein an `active_state` hängt, malt den gesunden
 * Server viermal rot.
 *
 * `failed` bleibt davon unberührt: Ein oneshot-Dienst, dessen letzter Lauf
 * scheiterte, meldet `failed` und nicht `inactive` — gemessen, mit einem eigenen
 * Prüfkörper je Fall.
 */
export function rang(unit: UnitLike): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (!unit.present) return 'neutral'
  if (ohneTermin(unit)) return 'critical'
  if (unit.active_state === 'active') return 'ok'
  if (unit.active_state === 'activating') return 'warn'
  if (wartet(unit)) return 'ok'

  return 'critical'
}

/**
 * Was in der Zustandsspalte steht.
 *
 * Für einen kaputten Timer ein **Satz** und keine Zahl: Das Abnahmekriterium
 * von A2 verlangt, dass er erkennbar ist, ohne dass man etwas deuten muss.
 */
export function zustand(unit: UnitLike): string {
  if (!unit.present) return 'nicht installiert'
  if (ohneTermin(unit)) return 'kein nächster Termin'
  if (unit.active_state === 'active') return unit.sub_state === 'running' ? 'läuft' : 'bereit'
  if (unit.active_state === 'activating') return 'startet neu'
  if (unit.active_state === 'failed') return 'fehlgeschlagen'
  if (wartet(unit)) return 'wartet auf seinen Timer'

  return 'gestoppt'
}
