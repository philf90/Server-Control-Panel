import { onUnmounted, ref, type Ref } from 'vue'

/*
 * Die Browserseite der Live-Ausgabe.
 *
 * EventSource baut eine abgerissene Verbindung von selbst wieder auf und
 * schickt dabei `Last-Event-ID` mit — genau darauf ist der Strom auf der
 * Serverseite ausgelegt. Zwei Dinge muss diese Datei trotzdem selbst tun:
 *
 * 1. **Beim Ereignis `done` schließen.** Sonst baut EventSource nach dem
 *    regulären Ende der Verbindung wieder auf, bekommt wieder `done`, schließt
 *    wieder — und fragt den Server in einer Endlosschleife nach einem Vorgang,
 *    der längst fertig ist.
 *
 * 2. **Bei `reconnect` gerade nichts tun.** Der Server beendet den Strom nach
 *    fünf Minuten absichtlich, damit der PHP-FPM-Arbeiter frei wird. Der
 *    Wiederaufbau ist erwünscht und passiert von allein; ein `close()` hier
 *    wäre genau falsch.
 */

export interface OperationState {
  status: string

  /* Der Zustand als Text — `label` heisst in der Seitennutzlast die Aufgabe. */
  status_label: string
  progress: number
  message: string | null
  open: boolean

  /* Ohne sie zeigte ein fertiger Vorgang „Begonnen —" (docs/36 §22.3m). */
  started_at: string | null
  finished_at: string | null
}

export interface OperationStream {
  state: Ref<OperationState | null>
  output: Ref<string>

  /*
   * **Das Ergebnis des abschliessenden Ereignisses — bis zum 30. August
   * weggeworfen.** Der Server schickt es seit jeher (`done` trägt `status` und
   * `result`); hier stand `as { status: string }`, und alles andere fiel unter
   * den Tisch. Wer einem Vorgang beim Enden zusah, bekam den Ausgang deshalb
   * erst beim Neuladen (`docs/88`, Befund 6 und 8).
   *
   * > **Ein Feld, das gesendet und nicht gelesen wird, ist von einem, das
   * > niemand sendet, nicht zu unterscheiden.**
   */
  result: Ref<Record<string, unknown> | null>
  finished: Ref<boolean>
  failed: Ref<boolean>
  close: () => void
}

interface StatePayload extends OperationState {
  output: string
}

export function useOperationStream(operationId: number): OperationStream {
  const state = ref<OperationState | null>(null)
  const output = ref('')
  const result = ref<Record<string, unknown> | null>(null)
  const finished = ref(false)
  const failed = ref(false)

  const source = new EventSource(`/operations/${operationId}/stream`)

  source.addEventListener('state', (event) => {
    const payload = JSON.parse((event as MessageEvent).data) as StatePayload

    state.value = {
      status: payload.status,
      status_label: payload.status_label,
      progress: payload.progress,
      message: payload.message,
      open: payload.open,
      started_at: payload.started_at,
      finished_at: payload.finished_at,
    }

    // Angehängt, nicht ersetzt: Der Server schickt nur, was seit der letzten
    // Kennung dazugekommen ist.
    if (payload.output) {
      output.value += payload.output
    }
  })

  source.addEventListener('done', (event) => {
    const payload = JSON.parse((event as MessageEvent).data) as {
      status: string
      result: Record<string, unknown> | null
    }

    result.value = payload.result ?? null
    finished.value = true
    failed.value = payload.status === 'failed'
    source.close()
  })

  const close = (): void => {
    source.close()
  }

  onUnmounted(close)

  return { state, output, result, finished, failed, close }
}
