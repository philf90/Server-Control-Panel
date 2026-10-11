/*
 * Welche Bänder ein Konto weggeklickt hat — gemerkt im Browser.
 *
 * **Entschieden vom Betreiber am 11. Oktober 2026:** Ein weggeklicktes Band
 * bleibt fort, bis es sich ändert, und gemerkt wird es im Browser, je Konto.
 * Auf einem anderen Gerät steht das Band wieder da — dafür braucht es keine
 * Tabelle, keine Route und keine Prüfung am Server.
 *
 * **Eine Ankündigung kommt wieder, wenn sich ändert, was ihr Band zeigt.**
 * Seit dem 6. September lässt sie sich ändern, und eine Änderung an Rang oder
 * Text ist genau das, was jemand sehen soll, der die alte Fassung weggeklickt
 * hat. Ihr Schlüssel trägt deshalb einen Fingerabdruck dessen, was das Band
 * zeigt (`announcementKey()`); bleibt beides gleich, ist das Band fort, bis die
 * Ankündigung endet. **Ein Vorgang ist ganz fort**, so stand es in der Frage
 * an den Betreiber: Wer einen laufenden Vorgang wegklickt, wartet nicht mehr
 * auf ihn.
 *
 * **Gemerkt werden genau diese zwei Arten.** Die fremde Sicht und die Wartung
 * melden einen Zustand, und das Band der fremden Sicht trägt den einzigen
 * Rückweg zur Verwaltung (`docs/20 §6.3`: „Rückweg jederzeit"). Beide lassen
 * sich wegklicken und kommen beim nächsten Seitenwechsel wieder; das hält
 * `PanelLayout` selbst und nicht dieser Speicher. Ein Schlüssel einer anderen
 * Art wird hier weder gelesen noch geschrieben.
 *
 * **Und jeder Zugriff in einem `try`.** Sind die Seitendaten abgeschaltet,
 * wirft schon das Lesen von `window.localStorage` eine Ausnahme und gibt nicht
 * `null`. Dieser Speicher läuft im Layout jeder Seite — ohne die Klammer stünde
 * dort jede Seite weiss da.
 */
import { computed, ref, watch, type ComputedRef } from 'vue'

/** Die Arten, die gemerkt werden. */
type DismissKind = 'announcement' | 'operation'

/**
 * Die Form eines gemerkten Schlüssels.
 *
 * **Gelesen wird gegen diese Form und nicht gegen sich selbst.** Im Speicher
 * des Browsers kann alles stehen: ein Wert aus einer früheren Fassung, etwas
 * von Hand Eingetragenes, Unsinn.
 *
 * > **Ein Wert aus fremder Hand gehört gegen die Liste geprüft, die ihn
 * > anbietet.**
 */
const FORM = /^(announcement:[1-9][0-9]*:[0-9a-f]{8}|operation:[1-9][0-9]*)$/

/** Was ein Ankündigungsband zeigt — die Felder, die `Bands.vue` ausgibt. */
export interface AnnouncementBand {
  id: number
  badge: string
  rank: string
  body: string
}

/**
 * Ein Fingerabdruck über einen Text: FNV-1a mit 32 Bit, als acht Hexziffern.
 *
 * **Ein Abdruck und keine Abschrift.** Der Text einer Ankündigung hat bis zu
 * 500 Zeichen; im Speicher des Browsers braucht es nur die Antwort auf „ist es
 * noch dieselbe Fassung". Treffen zwei Fassungen denselben Abdruck, bliebe eine
 * geänderte weggeklickt — bei 32 Bit ist das eine von gut vier Milliarden.
 */
function fingerprint(text: string): string {
  let hash = 0x811c9dc5

  for (let i = 0; i < text.length; i++) {
    hash ^= text.charCodeAt(i)
    hash = Math.imul(hash, 0x01000193) >>> 0
  }

  return hash.toString(16).padStart(8, '0')
}

/**
 * Der Schlüssel eines Ankündigungsbands.
 *
 * **Über alles, was das Band zeigt, und nur darüber.** Ein geändertes Ende der
 * Sichtbarkeit lässt das Band, wie es ist, und holt es deshalb nicht zurück;
 * ein geänderter Rang oder Text holt es zurück. Zeigt das Band einmal ein Feld
 * mehr, gehört es hier dazu — `BandDismissTest` hält beide Listen aneinander.
 */
function announcementKey(hinweis: AnnouncementBand): string {
  return `announcement:${hinweis.id}:${fingerprint(`${hinweis.badge}\n${hinweis.rank}\n${hinweis.body}`)}`
}

/** Der Schlüssel eines Vorgangsbands: der Vorgang selbst, gleich in welchem Zustand. */
function operationKey(vorgang: { id: number }): string {
  return `operation:${vorgang.id}`
}

/** Wo der Browser sich die Bänder eines Eigentümers merkt. */
function storageKey(owner: string): string {
  return `srvpanel.bands.${owner}`
}

function read(owner: string | null): string[] {
  if (owner === null) return []

  try {
    const raw: unknown = JSON.parse(window.localStorage.getItem(storageKey(owner)) ?? '[]')

    if (Array.isArray(raw)) {
      return raw.filter((key): key is string => typeof key === 'string' && FORM.test(key))
    }
  } catch {
    // Kein Zugriff oder kein JSON — dann ist nichts gemerkt.
  }

  return []
}

function write(owner: string | null, keys: string[]): void {
  if (owner === null) return

  try {
    if (keys.length === 0) {
      window.localStorage.removeItem(storageKey(owner))
    } else {
      window.localStorage.setItem(storageKey(owner), JSON.stringify(keys))
    }
  } catch {
    // Ein Klick, der sich nicht merken lässt, wirkt trotzdem auf dieser Seite.
    // Eine Fehlermeldung dafür wäre eine Störung ohne Handlung.
  }
}

/** Die sichtbaren Bänder einer Liste und der Griff, eines davon wegzuklicken. */
export interface VisibleBands<T> {
  visible: ComputedRef<T[]>
  dismiss: (item: T) => void
}

/**
 * Die gemerkten Bänder eines Eigentümers.
 *
 * `owner` ist `account.<Kennung>` für ein angemeldetes Konto und `guest` auf
 * den beiden Seiten der Anmeldung. **Je Konto**, weil sich mehrere Konten einen
 * Browser teilen können — der Betreiber und die fremde Sicht, die er gerade
 * hält, zum Beispiel. `null` heisst: nichts merken. Ein Klick wirkt dann auf
 * der Seite und ist beim nächsten Laden vergessen; so steht es im Layout, wenn
 * dort einmal kein Konto ist, und der Speicher der Anmeldeseite bleibt davon
 * unberührt.
 *
 * **Art und Schlüssel gehören zusammen und werden hier gepaart**, nicht beim
 * Aufrufer: Wer `announcements()` ruft, bekommt den Schlüssel mit Abdruck, wer
 * `operations()` ruft, den ohne. Zwei Stellen, die sie selbst zusammensetzen,
 * könnten sie vertauschen.
 */
export function useDismissedBands(owner: string | null) {
  const keys = ref<string[]>(read(owner))

  function dismiss(key: string): void {
    if (!FORM.test(key) || keys.value.includes(key)) return

    keys.value = [...keys.value, key]
    write(owner, keys.value)
  }

  /**
   * Vergessen, was es nicht mehr gibt.
   *
   * **Sonst wächst der Speicher mit jedem Vorgang.** Übergeben werden die
   * Schlüssel der Bänder, die gerade gezeigt werden könnten; ein gemerkter
   * Schlüssel dieser Art, der nicht darunter ist, gehört zu einer Ankündigung,
   * die geendet hat oder geändert wurde, oder zu einem Vorgang, der aus dem
   * Streifen gefallen ist. Keiner davon kommt unter diesem Schlüssel wieder.
   * Gefragt wird je Art, weil jede Liste nur ihre eigene Art kennt.
   */
  function keep(kind: DismissKind, current: string[]): void {
    const prefix = `${kind}:`
    const kept = keys.value.filter((key) => !key.startsWith(prefix) || current.includes(key))

    if (kept.length === keys.value.length) return

    keys.value = kept
    write(owner, kept)
  }

  function bands<T>(kind: DismissKind, list: () => T[], key: (item: T) => string): VisibleBands<T> {
    watch(() => list().map(key), (current) => keep(kind, current), { immediate: true })

    return {
      visible: computed(() => list().filter((item) => !keys.value.includes(key(item)))),
      dismiss: (item: T) => dismiss(key(item)),
    }
  }

  return {
    announcements: (list: () => AnnouncementBand[]) => bands('announcement', list, announcementKey),
    operations: <T extends { id: number }>(list: () => T[]) => bands('operation', list, operationKey),
  }
}
