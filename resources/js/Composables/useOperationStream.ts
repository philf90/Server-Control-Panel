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
  label: string
  progress: number
  message: string | null
  open: boolean
}

export interface OperationStream {
  state: Ref<OperationState | null>
  output: Ref<string>
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
  const finished = ref(false)
  const failed = ref(false)

  const source = new EventSource(`/operations/${operationId}/stream`)

  source.addEventListener('state', (event) => {
    const payload = JSON.parse((event as MessageEvent).data) as StatePayload

    state.value = {
      status: payload.status,
      label: payload.label,
      progress: payload.progress,
      message: payload.message,
      open: payload.open,
    }

    // Angehängt, nicht ersetzt: Der Server schickt nur, was seit der letzten
    // Kennung dazugekommen ist.
    if (payload.output) {
      output.value += payload.output
    }
  })

  source.addEventListener('done', (event) => {
    const payload = JSON.parse((event as MessageEvent).data) as { status: string }

    finished.value = true
    failed.value = payload.status === 'failed'
    source.close()
  })

  const close = (): void => {
    source.close()
  }

  onUnmounted(close)

  return { state, output, finished, failed, close }
}
