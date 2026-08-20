<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'
import FileTree from '../../Components/FileTree.vue'
import PermissionEditor from '../../Components/PermissionEditor.vue'
import { counted } from '../../Composables/useCounted'
import { formatBytes } from '../../bytes'
import { useConfirmation } from '../../Composables/useConfirmation'
import { bringIntoView } from '../../scroll'

const { ask } = useConfirmation()

interface Entry {
  name: string
  path: string
  type: 'file' | 'directory' | 'link'
  size: number
  mode: number
  modified_at: number
  target: string | null
  readable: boolean
  writable: boolean

  /**
   * Gehört dieser Eintrag zum Gerüst des Abonnements?
   *
   * Der Server entscheidet das über `Scheme::isFixed()` und nicht diese Seite:
   * Eine Liste hier wäre die Fassung, die beim nächsten Zuwachs des Schemas
   * veraltet.
   */
  fixed: boolean
}

const props = defineProps<{
  subscription: { id: number; name: string }
  path: string
  entries: Entry[]
  truncated: boolean

  /**
   * Welche Verzeichnisse der Webserver ausliefert.
   *
   * Sie kommen vom Server, weil nur er sie kennt: `httpdocs` ist der
   * DocumentRoot der Hauptdomain, jede weitere heisst wie ihre Domain. Eine
   * Seite, die `httpdocs` hineinschriebe, gäbe für den einen Ordner die
   * richtige Auskunft und für den daneben wortgleich die falsche.
   */
  documentRoots: string[]

  can: { edit: boolean }
}>()

/*
 * Die Krümel oben. Sie entstehen aus dem Pfad und nicht aus einer mitgelieferten
 * Liste — zwei Fassungen desselben Weges wären zwei Gelegenheiten, sie
 * auseinanderlaufen zu lassen.
 */
const crumbs = computed(() => {
  const parts = props.path.split('/').filter(Boolean)

  return parts.map((name, index) => ({
    name,
    path: '/' + parts.slice(0, index + 1).join('/'),
  }))
})

const parent = computed(() => {
  const parts = props.path.split('/').filter(Boolean)
  parts.pop()

  return '/' + parts.join('/')
})

function open(path: string): void {
  router.get(`/subscriptions/${props.subscription.id}/files`, { path }, { preserveScroll: true })
}

/*
 * Die Rechte als `rwxr-x---`.
 *
 * Als Zahl wäre es kürzer und für den Kunden unlesbar; als Zahl **und** Text
 * wäre es zweimal dasselbe. Der Text steht hier, die Zahl im Formular, das sie
 * ändert — dort ist sie das, was eingegeben wird.
 */
function rights(mode: number): string {
  const set = ['r', 'w', 'x']

  return [6, 3, 0]
    .map((shift) => set.map((c, i) => ((mode >> shift) & (4 >> i) ? c : '-')).join(''))
    .join('')
}

/*
 * Die Grösse kommt aus `bytes.ts` und wird hier nicht gerechnet.
 *
 * **Diese Seite hatte ihre eigene Umrechnung, und `SizeUnitTest` hat sie
 * gefunden.** Der Wächter steht seit dem dritten Abnahmelauf: Liste und
 * Einzelansicht hatten je eine `size()`-Funktion mit demselben Inhalt, und zwei
 * Fassungen derselben Regel heissen, dass die eine nachgezogen wird und die
 * andere nicht.
 *
 * Was hier bleibt, ist die Entscheidung, die nur diese Seite treffen kann:
 * Ein Verzeichnis und ein Verweis haben keine Inhaltsgrösse. „0 B" behauptete,
 * sie seien leer.
 */
function size(entry: Entry): string {
  return entry.type === 'file' ? formatBytes(entry.size) : '—'
}

function moment(seconds: number): string {
  return new Date(seconds * 1000).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' })
}

/*
 * Ein Ziel im aktuellen Verzeichnis.
 *
 * Der Kunde gibt einen **Namen** ein, keinen Pfad — der Pfad entsteht hier aus
 * dem Verzeichnis, in dem er steht. Das ist keine Schranke (die hält das Chroot
 * im Agenten), sondern die Bedienung: Wer in `httpdocs` steht und „bilder"
 * eingibt, meint `httpdocs/bilder`.
 */
function here(name: string): string {
  return (props.path === '/' ? '' : props.path) + '/' + name
}

const newDirectory = useForm({ path: '' })

/**
 * Eine neue, leere Datei.
 *
 * **Sie geht an dieselbe Adresse wie das Speichern aus dem Editor.**
 * `files.write` legt an, was es nicht gibt, und sagt in seiner Antwort, ob es
 * das getan hat — der Weg danach hängt an dieser Auskunft und nicht an einem
 * Feld im Formular.
 *
 * Der Inhalt ist leer und bleibt es: Wer eine Datei anlegt, will etwas
 * hineinschreiben, und dafür ist der Editor da. Ein zweites Textfeld hier wäre
 * eine zweite Stelle, an der man Dateiinhalte tippt.
 */
const newFile = useForm({ path: '', content: '' })

const upload = useForm<{ path: string; files: File[] }>({ path: '', files: [] })
const rename = useForm({ path: '', name: '' })
const modeForm = useForm({ path: '', mode: 0 })

/* Welches Formular gerade offen ist — höchstens eines. */
const open_ = ref<'directory' | 'file' | 'upload' | null>(null)

function submitDirectory(): void {
  newDirectory
    .transform((data) => ({ ...data, path: here(data.path) }))
    .post(`/subscriptions/${props.subscription.id}/files/directory`, {
      preserveScroll: true,
      onSuccess: () => { newDirectory.reset(); open_.value = null },
    })
}

function submitFile(): void {
  newFile
    .transform((data) => ({ ...data, path: here(data.path) }))
    .put(`/subscriptions/${props.subscription.id}/files`, {
      preserveScroll: true,
      onSuccess: () => { newFile.reset(); open_.value = null },
    })
}

/**
 * Hochladen — eine Datei oder viele.
 *
 * **`path` ist das Verzeichnis und nicht mehr der vollständige Pfad.** Vorher
 * setzte diese Seite den Zielpfad aus dem Dateinamen zusammen; bei mehreren
 * Dateien wäre das ein Pfad für alle gewesen. Wie die einzelne Datei heisst,
 * entscheidet jetzt der Server aus ihrem Namen.
 */
function submitUpload(): void {
  if (upload.files.length === 0) return

  upload
    .transform((data) => ({ ...data, path: props.path }))
    .post(`/subscriptions/${props.subscription.id}/files/upload`, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => { upload.reset(); open_.value = null },
    })
}

/**
 * Welcher Eintrag gerade umbenannt wird — `null`, wenn keiner.
 *
 * **Hier stand ein `window.prompt`.** Genau derselbe Ersatz wie beim
 * Rechte-Editor (`docs/53`, Befund 8) — nur ist er dort im August gebaut worden
 * und hier nicht, weil gemeldet war, was gemeldet war. Siehe die Notiz über
 * {@see startPack}.
 */
const renameFor = ref<Entry | null>(null)

/**
 * Umbenennen — der Name geht als Name und nicht als Pfad.
 *
 * **Hier stand `here(wanted)`.** Diese Seite setzte den Zielpfad selbst
 * zusammen und schickte ihn an `files/move`; seit die Mehrfachauswahl dort ein
 * **Verzeichnis** erwartet, wäre das dasselbe Feld mit zwei Bedeutungen. Wie der
 * Eintrag danach heisst, entscheidet jetzt der Server aus dem Namen — dieselbe
 * Verschiebung wie beim Hochladen mehrerer Dateien.
 */
function startRename(entry: Entry): void {
  renameFor.value = renameFor.value?.path === entry.path ? null : entry

  if (renameFor.value !== null) {
    rename.path = entry.path
    rename.name = entry.name
  }
}

function submitRename(): void {
  const wanted = rename.name.trim()

  if (wanted === '' || wanted === renameFor.value?.name) {
    renameFor.value = null

    return
  }

  rename.post(`/subscriptions/${props.subscription.id}/files/rename`, {
    preserveScroll: true,
    onSuccess: () => { renameFor.value = null },
  })
}

/**
 * Welcher Eintrag gerade seine Rechte zeigt — `null`, wenn keiner.
 *
 * **Hier stand ein `window.prompt`.** Er verlangte eine Oktalzahl, erklärte
 * nichts und brachte einen Systemdialog mit, der keine Farbe aus `app.css`
 * nimmt (`docs/53`, Befund 8). Der Ersatz ist ein Bereich auf der Seite:
 * Dieses Panel hat keine Modalen, und es bekommt auch für diesen einen Fall
 * keine.
 */
const chmodFor = ref<Entry | null>(null)

/**
 * Liegt dieser Eintrag in einem Verzeichnis, das der Webserver ausliefert?
 *
 * Entscheidet, ob {@see PermissionEditor} den Satz über den Webserver zeigt.
 * Ein Ordner **ist** sein DocumentRoot oder liegt darin; beides zählt.
 */
function served(entry: Entry): boolean {
  return props.documentRoots.some(
    (root) => entry.path === root || entry.path.startsWith(root + '/'),
  )
}

function startChmod(entry: Entry): void {
  chmodFor.value = chmodFor.value?.path === entry.path ? null : entry

  if (chmodFor.value !== null) {
    modeForm.path = entry.path
    modeForm.mode = entry.mode & 0o777
  }
}

const chmodBlock = ref<HTMLElement | null>(null)
const renameBlock = ref<HTMLElement | null>(null)

/*
 * **Ein Bereich, der aufgeht, holt sich ins Bild.**
 *
 * Beide Bereiche stehen am Kopf der Seite; gedrückt wird „Rechte" oder
 * „Umbenennen" an einer Zeile weit unten. Bei 390px ist die Wirkung damit
 * ausserhalb des Bildes, und der Betreiber hat genau das gemeldet (`docs/64`,
 * Befund 10): *Man hat das Gefühl, es passiert nichts.*
 *
 * > **Ein Bedienelement, dessen Wirkung ausserhalb des Bildes erscheint, sieht
 * > aus wie eines ohne Wirkung.**
 *
 * Die Behebung dafür liegt seit dem 15. August in diesem Repo — `scroll.ts`
 * gibt es wegen desselben Vorgangs am Knopf „Entfernen". Sie war nur an eine
 * einzige Stelle angeschlossen.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * `bringIntoView` rollt nur, wenn der Block nicht ohnehin ganz zu sehen ist —
 * auf einer breiten Fläche geschieht deshalb nichts.
 */
watch(chmodFor, (offen) => {
  if (offen !== null) {
    void nextTick(() => bringIntoView(chmodBlock.value))
  }
})

watch(renameFor, (offen) => {
  if (offen !== null) {
    void nextTick(() => bringIntoView(renameBlock.value))
  }


/*
 * **Der Zielbaum ist der dritte Griff dieser Art — und der erste, der nach
 * oben zeigt.**
 *
 * „Verschieben" und „Kopieren" stehen in der Auswahlleiste, und die gehört zur
 * Liste. Der Baum, den man danach benutzen soll, ist die **erste** Hälfte von
 * `.split`, bei 390 px also der obere Stapel. Wer den Knopf drückt, soll etwas
 * benutzen, das über ihm liegt.
 *
 * Gemessen am 20. August (`docs/64`, Befund 18): `oben: -98` — abgeschnitten
 * waren die Wurzel, `.ssh`, `conf` und `httpdocs`, also gerade die Ziele, die
 * man von hier aus meint.
 *
 * **Warum das nicht schon vorher auffiel:** `RevealTest` fand Griffe der Form
 * `@click="funktion(argument)"`. Dieser hier ist eine Zuweisung —
 * `@click="picking = 'move'"` — und fiel aus dem Ausdruck heraus.
 *
 * > **Ein Wächter, der eine Sorte Griff prüft, sagt über die andere Sorte
 * > nichts.**
 */
const asideBlock = ref<HTMLElement | null>(null)

watch(picking, (an) => {
  if (an !== null) {
    void nextTick(() => bringIntoView(asideBlock.value))
  }
})})

function submitChmod(): void {
  modeForm.post(`/subscriptions/${props.subscription.id}/files/chmod`, {
    preserveScroll: true,
    onSuccess: () => { chmodFor.value = null },
  })
}

/*
 * Entpacken bietet sich nur an, wo es etwas zu entpacken gibt.
 *
 * Am Inhalt zu erkennen wäre genauer als an der Endung — das tut der Agent
 * auch. Für die Frage, ob ein Knopf erscheint, reicht die Endung: Ein Archiv
 * ohne passenden Namen wird über den Griff nicht angeboten und lässt sich
 * trotzdem entpacken, sobald es richtig heisst.
 */
function isArchive(entry: Entry): boolean {
  return entry.type === 'file' && /\.(zip|tar|tar\.gz|tgz)$/i.test(entry.name)
}

function extract(entry: Entry): void {
  router.post(`/subscriptions/${props.subscription.id}/files/extract`, {
    path: entry.path,
    target: props.path,
  })
}

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * Die Suche — Wunsch 3 des Betreibers (`docs/64 §6`)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * **Bis hierher war der Suchbegriff zugleich der Schalter.** `searching` war
 * `string | null`: `null` hiess „zu", alles andere war der Begriff. Das trug,
 * solange das Feld nur auf Knopfdruck erschien. Auf der breiten Fläche steht es
 * jetzt immer — und dort gibt es kein „zu", das ein `null` bedeuten könnte.
 *
 * > **Ein Wert, der zugleich ein Zustand ist, verliert den Zustand in dem
 * > Moment, in dem es ihn nicht mehr gibt.**
 *
 * Ab 720 px steht die Leiste als eigene Zeile unter dem Seitenkopf, darunter
 * bleibt der Knopf. Der Unterschied steht in `app.css` und nicht hier: Eine
 * Abfrage der Fensterbreite im Skript wäre eine zweite Fassung der Schwelle,
 * und die zweite ist die, die beim nächsten Umbau abweicht.
 *
 * **Warum nicht überall die Leiste.** Gemessen am 20. August: Bei 390 px kostet
 * sie dauerhaft 141 px, und der Seitenkopf nähme damit mehr als die Hälfte des
 * Fensters ein, bevor eine einzige Datei zu sehen ist. Bei 390 px ist die
 * Leiste ausserdem keine — dort stapelt alles in voller Breite.
 */
const query = ref('')
const searchOpen = ref(false)

/*
 * **Und die Suche kann jetzt auch im Inhalt suchen.** Die Trefferseite konnte
 * das immer; die Kopfleiste schickte nur `query` und `path`. Wer von hier aus
 * suchte, erfuhr erst auf der Trefferseite, dass es die Möglichkeit gibt.
 *
 * > **Zwei Eingaben für dieselbe Sache sind eine Sicht und eine Kopie — und die
 * > Kopie ist die, die weniger kann.**
 */
const inContent = ref(false)

/*
 * Nur für die schmale Fläche: dort öffnet ein Knopf die Leiste.
 *
 * **Ein „Abbrechen" gibt es nicht mehr.** Der Knopf schaltet in beide
 * Richtungen und sagt, in welcher — dieselbe Form wie „Aktionen zuklappen" in
 * der Tabelle darunter. Ein zweiter Knopf im Formular wäre ein zweiter Weg
 * zurück für einen Zustand, den es auf der breiten Fläche gar nicht gibt.
 */
function startSearch(): void {
  searchOpen.value = !searchOpen.value
}

function search(): void {
  const wanted = query.value.trim()

  if (wanted === '') return

  router.get(`/subscriptions/${props.subscription.id}/files/search`, {
    query: wanted,
    path: props.path,
    content: inContent.value,
  })
}

function remove(entry: Entry): void {
  const question = entry.type === 'directory'
    ? `„${entry.name}" mitsamt Inhalt entfernen?`
    : `„${entry.name}" entfernen?`

  ask(question, 'Entfernen', () => {
    router.delete(`/subscriptions/${props.subscription.id}/files`, {
      data: { paths: [entry.path], recursive: entry.type === 'directory' },
      preserveScroll: true,
    })
  })
}

/*
 * ────────────────────────────────────────────────────────────────────────────
 * Die Mehrfachauswahl (P6 Schritt 5h)
 * ────────────────────────────────────────────────────────────────────────────
 */

/**
 * Was angehakt ist — als Pfade und nicht als Einträge.
 *
 * Ein Pfad überlebt eine neue Liste, ein Eintragsobjekt nicht: Inertia liefert
 * bei jeder Navigation frische Objekte, und eine Auswahl aus den alten zeigte
 * danach auf nichts.
 */
const selected = ref<string[]>([])

/**
 * Welche Aktion gerade auf ein Ziel wartet — `null`, wenn keine.
 *
 * **Der Baum ist der Zielwähler** (Entscheidung des Betreibers vom 15. August
 * 2026). Ein Textfeld für den Zielpfad wäre die kürzere Fassung und die
 * schlechtere: Wer ein Ziel tippt, tippt sich vertippt, und die Absage kommt
 * erst nach dem Abschicken.
 */
const picking = ref<'copy' | 'move' | null>(null)

/**
 * Welche Zeile ihre Aktionen gerade aufgeklappt hat — auf dem Telefon.
 *
 * **Ein Pfad und keine Liste.** Zwanzig offene Zeilen wären zwanzigmal das
 * Problem, das dieses Zuklappen löst (`docs/55`, Befund 25): Die Knopfreihe
 * misst bei 390px 162px von 344px Kärtchenhöhe. Wer die nächste öffnet, hat die
 * vorige gelesen.
 *
 * **Der Wert steuert nur eine Klasse.** Über 720px ist die Reihe ohne
 * Bedingung sichtbar und der Umschalter fort — beides entscheidet `app.css`.
 * Eine `matchMedia`-Abfrage hier wäre eine zweite Fassung des Haltepunkts, und
 * die zweite ist die, die beim nächsten Umbau stehenbleibt.
 */
const unfolded = ref<string | null>(null)

function toggleActions(path: string): void {
  unfolded.value = unfolded.value === path ? null : path
}

/**
 * Beim Wechsel des Verzeichnisses fällt die Auswahl weg.
 *
 * **Sonst entfernt sie Einträge, die niemand mehr sieht.** Eine Auswahl, die
 * eine Navigation überlebt, ist eine Liste von Pfaden aus einem anderen
 * Verzeichnis — und die Knopfreihe darüber sagt „3 Einträge ausgewählt", während
 * die Tabelle darunter keinen einzigen Haken zeigt.
 *
 * > **Eine Auswahl, die man nicht mehr sieht, ist keine Auswahl mehr — nur eine
 * > Zahl, die noch da ist.**
 */
watch(() => props.path, () => {
  selected.value = []
  picking.value = null
  // Aus demselben Grund: Ein offenes Namensfeld über einer leeren Auswahl
  // fragt nach dem Namen für nichts.
  archiveName.value = null
  renameFor.value = null
  // Und aus demselben Grund: Ein aufgeklappter Pfad zeigt nach der Navigation
  // auf eine Zeile, die es in dieser Liste nicht gibt.
  unfolded.value = null
})

function toggleSelected(path: string, on: boolean): void {
  selected.value = on
    ? [...selected.value, path]
    : selected.value.filter((einzeln) => einzeln !== path)
}

/**
 * Der Haken in der Kopfzeile.
 *
 * Er hat nur zwei Zustände und keinen dritten für „einige": Ein Ankreuzfeld, das
 * `indeterminate` zeigt, braucht einen Griff auf das DOM-Element, und die
 * Auskunft steht in der Zeile daneben ohnehin als Zahl.
 */
/**
 * Was sich überhaupt anhaken lässt.
 *
 * **Ohne diese Zeile wäre der fehlende Haken am Gerüst eine Zierde:** „Alle
 * auswählen" nähme die sechs Verzeichnisse trotzdem mit, und der Zählsatz stünde
 * wieder auf „0 von 6" (`docs/55`, Befund 21).
 *
 * > **Ein Knopf, der alles auswählt, muss dasselbe „alles" meinen wie die
 * > Haken daneben.**
 */
const selectable = computed(() => props.entries.filter((entry) => ! entry.fixed))

const allSelected = computed<boolean>({
  get: () => selectable.value.length > 0 && selected.value.length === selectable.value.length,
  set: (on: boolean) => {
    selected.value = on ? selectable.value.map((entry) => entry.path) : []
  },
})

const chosenDirectories = computed(
  () => props.entries.filter((entry) => entry.type === 'directory' && selected.value.includes(entry.path)),
)

/**
 * Welche der ausgewählten Einträge eine Domain ausliefern.
 *
 * **Eine Warnung und keine zweite Durchsetzung.** Das Gerüst des Abonnements —
 * `httpdocs`, `logs`, `conf` und die anderen — schützt der Agent
 * (`Files\Scheme`). Der DocumentRoot einer **weiteren** Domain heisst wie die
 * Domain, steht in keiner festen Liste, und der Agent müsste dafür die
 * vhost-Dateien lesen. Das Panel kennt die Namen und sagt deshalb, was
 * passiert; entfernen darf der Kunde sein Verzeichnis.
 */
const chosenRoots = computed(
  () => selected.value.filter((path) => props.documentRoots.includes(path)),
)

/**
 * Die Rückfrage vor einem Griff, der mehrere Einträge trifft.
 *
 * **Sie nennt, was der Betrachter nicht sieht.** In der Tabelle steht neben
 * jedem Haken ein Name; was dort *nicht* steht, ist die Zahl der Verzeichnisse
 * (deren Inhalt mitgeht) und die Frage, ob eines davon eine Domain ausliefert.
 * Beides entscheidet, ob der Klick harmlos ist.
 *
 * **Sie gibt die Frage zurück und stellt sie nicht selbst.** Bis zum 15. August
 * rief sie `window.confirm` und lieferte ein `true`/`false`; die Antwort kommt
 * jetzt erst später, über einen Rückruf. Der Name ist deshalb `bulkQuestion`
 * und nicht mehr `confirmed` — er sagt, was die Funktion liefert.
 */
function bulkQuestion(verb: string): string {
  const zeilen = [`${counted(selected.value.length, 'Eintrag', 'Einträge')} ${verb}?`]

  const ordner = chosenDirectories.value.length

  if (ordner === 1) {
    zeilen.push('Darunter ist ein Verzeichnis — sein Inhalt geht mit.')
  } else if (ordner > 1) {
    zeilen.push(`Darunter sind ${ordner} Verzeichnisse — ihr Inhalt geht mit.`)
  }

  if (chosenRoots.value.length === 1) {
    zeilen.push(`${chosenRoots.value[0]} liefert eine Domain aus. Die Seite ist danach nicht mehr erreichbar.`)
  } else if (chosenRoots.value.length > 1) {
    zeilen.push(
      `${chosenRoots.value.length} der ausgewählten Verzeichnisse liefern Domains aus. `
      + 'Diese Seiten sind danach nicht mehr erreichbar.',
    )
  }

  return zeilen.join('\n\n')
}

function removeSelected(): void {
  if (selected.value.length === 0) return

  ask(bulkQuestion('entfernen'), 'Entfernen', () => {
    router.delete(`/subscriptions/${props.subscription.id}/files`, {
      data: { paths: selected.value, recursive: chosenDirectories.value.length > 0 },
      preserveScroll: true,
      onSuccess: () => { selected.value = [] },
    })
  })
}

/**
 * Wie das Archiv heissen soll — `null`, solange nicht gepackt wird.
 *
 * ## Warum hier kein `window.prompt` mehr steht
 *
 * Er stand hier, und auf dem iPhone liess sich „Als Zip packen" damit **gar
 * nicht** bedienen (`docs/55`, Befund 15): Safari darf die Dialoge einer Seite
 * abschalten, nachdem sie mehrere gezeigt hat, und `prompt()` gibt danach ohne
 * jedes Zeichen `null` zurück. Der Knopf tut dann nichts — keine Meldung, kein
 * Hinweis, kein Unterschied zu einem kaputten Knopf.
 *
 * > **Ein Knopf, dessen Wirkung in einem Dialog steckt, den der Browser
 * > abschalten darf, ist ein Knopf, der nichts tut.**
 *
 * Entschieden war das ohnehin schon: `docs/53` Befund 8 hat den `prompt` des
 * Rechte-Editors durch einen Bereich auf der Seite ersetzt, mit dem Satz
 * „dieses Panel hat keine Modalen". **Drei weitere `prompt` in derselben Datei
 * haben das überlebt**, weil gemeldet war, was gemeldet war.
 *
 * > **Eine Regel, die nur auf den gemeldeten Fall angewandt wird, lässt ihre
 * > Geschwister stehen.**
 */
const archiveName = ref<string | null>(null)

function startPack(): void {
  if (selected.value.length === 0) return

  // Der Name wird gefragt und nicht geraten: `auswahl.zip` neben `auswahl.zip`
  // wäre beim zweiten Mal eine Absage, und ein Zeitstempel im Namen ist eine
  // Erfindung des Panels über etwas, das der Kunde benennt.
  archiveName.value = 'auswahl.zip'
}

function packSelected(): void {
  const name = (archiveName.value ?? '').trim()

  if (selected.value.length === 0 || name === '') return

  router.post(
    `/subscriptions/${props.subscription.id}/files/compress`,
    { paths: selected.value, target: here(name) },
    {
      preserveScroll: true,
      onSuccess: () => { selected.value = []; archiveName.value = null },
    },
  )
}

/**
 * Das Ziel steht fest — kopieren oder verschieben.
 *
 * **Das Ziel ist ein Verzeichnis, kein Pfad.** Wie der einzelne Eintrag darin
 * heisst, entscheidet der Server aus seinem Namen; bei mehreren Quellen wäre ein
 * vollständiger Zielpfad **ein** Pfad für alle — der letzte gewönne, die anderen
 * wären fort, und der Vorgang meldete Erfolg.
 */
function pick(target: string): void {
  const aktion = picking.value

  if (aktion === null || selected.value.length === 0) return

  router.post(
    `/subscriptions/${props.subscription.id}/files/${aktion}`,
    { paths: selected.value, to: target },
    {
      preserveScroll: true,
      onSuccess: () => { selected.value = [] },
      onFinish: () => { picking.value = null },
    },
  )
}
</script>

<template>
  <Head :title="`Dateien — ${props.subscription.name}`" />

  <PanelLayout title="Dateien" :subline="props.subscription.name">
    <FormErrors />

    <!--
      Die Krümel sind der Baum. `docs/46 §20.11` hat gemessen, was ein langer
      Name in einer Überschrift anrichtet — deshalb steht der Pfad hier als
      umbrechende Zeile und nicht im Seitentitel.
    -->
    <template #actions>
      <div v-if="props.can.edit" class="button-row">
        <button type="button" class="button" @click="open_ = open_ === 'directory' ? null : 'directory'">
          Verzeichnis anlegen
        </button>
        <button type="button" class="button" @click="open_ = open_ === 'file' ? null : 'file'">
          Datei anlegen
        </button>
        <button type="button" class="button primary" @click="open_ = open_ === 'upload' ? null : 'upload'">
          Datei hochladen
        </button>
      </div>
      <!--
        Der Knopf gibt es nur auf der schmalen Fläche — darüber steht die
        Leiste ohnehin. `app.css` blendet ihn aus; ein `v-if` an der
        Fensterbreite wäre die Schwelle ein zweites Mal.
      -->
      <button
        type="button"
        class="button search-toggle"
        :aria-expanded="searchOpen"
        aria-controls="dateisuche"
        @click="startSearch"
      >
        {{ searchOpen ? 'Suche zuklappen' : 'Suchen' }}
      </button>
    </template>

    <!--
      Auch hier stand ein `window.prompt` (`docs/55`, Befund 15). Dieselbe Form
      wie beim Anlegen darunter: ein Feld auf der Seite, mit sichtbarer
      Beschriftung.
    -->
    <!--
      **Der Ort steht als Kennung daneben und nicht in der Beschriftung.**
      `.field.inline > span` trägt `white-space: nowrap` — mit Absicht, und der
      Kommentar dort verlangt eine Messung, wenn jemand eine längere
      Beschriftung einsetzt. Ein Pfad ist beliebig lang; als `.ident` bricht er,
      bevor er die Seite schiebt.

      **Und er steht überhaupt da, weil eine Leiste, die immer da ist, aussieht,
      als suchte sie überall** (`docs/64 §6.1`). Wer in einem tiefen Verzeichnis
      steht und das nicht sieht, sucht am Bestand vorbei und schliesst daraus,
      die Datei gebe es nicht. Ein Platzhalter täte es nicht:

      > **Eine Auskunft im Platzhalter ist genau so lange da, wie man sie nicht
      > braucht.**
    -->
    <form id="dateisuche" class="button-row search" :class="{ open: searchOpen }" @submit.prevent="search">
      <label class="field inline">
        <span>Suchen in <span class="ident">{{ props.path }}</span></span>
        <input v-model="query" type="search" autocomplete="off" required />
      </label>
      <label class="toggle">
        <input v-model="inContent" type="checkbox" />
        <span>auch im Inhalt</span>
      </label>
      <button type="submit" class="button primary">Suchen</button>
    </form>

    <!--
      Die Beschriftung steht sichtbar dabei und nicht nur als `aria-label` —
      am 7. August 2026 hat der Betreiber genau das an der Domainauswahl
      gemeldet, und es gilt hier genauso.
    -->
    <form v-if="open_ === 'directory'" class="button-row" @submit.prevent="submitDirectory">
      <label class="field inline">
        <span>Name des Verzeichnisses</span>
        <input v-model="newDirectory.path" type="text" autocomplete="off" required />
      </label>
      <button type="submit" class="button primary" :disabled="newDirectory.processing">Anlegen</button>
    </form>

    <!--
      Die neue Datei entsteht leer und der Editor öffnet sich danach. Ein
      Textfeld hier wäre eine zweite Stelle, an der man Dateiinhalte tippt —
      und die zweite ist die, die den Editor nicht hat.
    -->
    <form v-if="open_ === 'file'" class="button-row" @submit.prevent="submitFile">
      <label class="field inline">
        <span>Name der Datei</span>
        <input v-model="newFile.path" type="text" autocomplete="off" required />
      </label>
      <button type="submit" class="button primary" :disabled="newFile.processing">Anlegen</button>
    </form>

    <form v-if="open_ === 'upload'" class="button-row" @submit.prevent="submitUpload">
      <!--
        `multiple`, und die Rückmeldung dazu steht im Controller: Was zählt, ist
        nicht die Schleife, sondern der Fall, in dem Datei 7 von 20 die Quota
        reisst und die anderen neunzehn schon dort liegen.
      -->
      <label class="field inline">
        <span>Dateien</span>
        <input
          type="file"
          multiple
          required
          @change="upload.files = Array.from(($event.target as HTMLInputElement).files ?? [])"
        />
      </label>
      <button type="submit" class="button primary" :disabled="upload.processing || upload.files.length === 0">
        Hochladen
      </button>
      <!--
        Der Fortschritt kommt von Inertia und nicht von einer eigenen Zählung:
        Eine zweite Fassung derselben Zahl wäre die, die stehenbleibt.
      -->
      <span v-if="upload.progress" class="quiet">{{ upload.progress.percentage }} %</span>
    </form>

    <!--
      **Die Rechte stehen auf der Seite und nicht in einem Systemdialog.**

      Sie stehen hier oben und nicht in der Tabellenzeile: Bei 390px bricht die
      Tabelle in Kärtchen um, und ein Formular in einer Zelle bräuchte dort
      eine zweite Form. Der Name des Eintrags steht dabei, weil sonst nicht
      abzulesen wäre, wessen Rechte gerade offen sind.
    -->
    <form v-if="chmodFor !== null" ref="chmodBlock" class="block" tabindex="-1" @submit.prevent="submitChmod">
      <p class="path-line">Rechte für {{ chmodFor.name }}</p>

      <PermissionEditor
        v-model="modeForm.mode"
        :is-directory="chmodFor.type === 'directory'"
        :served="served(chmodFor)"
      />

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="modeForm.processing">Speichern</button>
        <button type="button" class="button" @click="chmodFor = null">Abbrechen</button>
      </div>
    </form>

    <!--
      Und aus demselben Grund an derselben Stelle: ein Formular in einer Zelle
      bräuchte bei 390px eine zweite Form. Der alte Name steht daneben, weil
      sonst nicht abzulesen wäre, was gerade umbenannt wird.
    -->
    <form v-if="renameFor !== null" ref="renameBlock" class="block" tabindex="-1" @submit.prevent="submitRename">
      <!--
        **Der alte Name steht in der Zeile darüber und nicht in der
        Beschriftung.** Dieselbe Form wie beim Rechte-Editor — und aus dem
        Grund, den `.field.inline > span` in `app.css` nennt: Die Beschriftung
        eines nebenstehenden Feldes bricht nicht. Ein Dateiname darf 255 Zeichen
        haben; als Beschriftung schob er die Seite bei 390px um 132px aus dem
        Bild (gemessen, `docs/55` Befund 15).
      -->
      <p class="path-line">Umbenennen: {{ renameFor.name }}</p>

      <label class="field inline">
        <span>Neuer Name</span>
        <input v-model="rename.name" type="text" autocomplete="off" required />
      </label>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="rename.processing">Umbenennen</button>
        <button type="button" class="button" @click="renameFor = null">Abbrechen</button>
      </div>
    </form>

    <!--
      **Baum links, Liste rechts** (`docs/51 §8`). Bis Schritt 5g stand hier nur
      der Satz „Die Krümel sind der Baum" — als Entscheidung hingeschrieben,
      ohne dass sie je eine war.

      `.split` bringt den Haltepunkt und die beiden `min-width: 0` mit, die dort
      teuer erkauft sind: Ohne sie schiebt die Seite bei 800px um 242px, und
      zwar **nur** dort — bei 720 und 1440 ist sie sauber.
    -->
    <div class="split">
      <div ref="asideBlock" class="aside" tabindex="-1">
        <!--
          **Derselbe Baum in zwei Rollen.** Beim Navigieren führt ein Klick zur
          Liste, beim Auswählen setzt er das Ziel — der Unterschied ist eine
          einzige Angabe. Zwei Bäume wären zwei Bausteine, die dasselbe zeigen,
          und beim nächsten Umbau zeigten sie es verschieden.
        -->
        <FileTree
          :subscription-id="props.subscription.id"
          :current="props.path"
          :picking="picking !== null"
          @open="open"
          @pick="pick"
        />
      </div>

      <div>
    <nav class="crumbs" aria-label="Pfad">
      <button type="button" class="link" @click="open('/')">Abo-Wurzel</button>
      <template v-for="crumb in crumbs" :key="crumb.path">
        <span class="quiet" aria-hidden="true">/</span>
        <button type="button" class="link" @click="open(crumb.path)">{{ crumb.name }}</button>
      </template>
    </nav>

    <!--
      **Was mit der Auswahl geschehen kann, steht über der Auswahl.**

      Nicht in der Zeile: Ein Griff je Zeile beantwortet die Frage „was tue ich
      mit dieser Datei", und die Mehrfachauswahl stellt eine andere. Und nicht
      unten: Bei zwanzig Zeilen stünde der Knopf ausserhalb des Bildes, während
      der letzte Haken gesetzt wird.
    -->
    <div v-if="props.can.edit && selected.length > 0" class="selection">
      <span v-if="archiveName !== null">
        {{ counted(selected.length, 'Eintrag', 'Einträge') }} packen — wie soll das Archiv heissen?
      </span>
      <span v-else-if="picking === null">
        {{ counted(selected.length, 'Eintrag', 'Einträge') }} ausgewählt
      </span>
      <span v-else>
        Ziel im Baum wählen — {{ counted(selected.length, 'Eintrag', 'Einträge') }}
        {{ picking === 'copy' ? 'kopieren' : 'verschieben' }}
      </span>

      <!--
        **Das Namensfeld steht in derselben Leiste und nicht in einem Dialog.**
        Es ist ein dritter Zustand neben „nichts vorgemerkt" und „Ziel im Baum
        wählen"; alle drei stehen an derselben Stelle, damit die Antwort dort
        erscheint, wo die Frage gestellt wurde.
      -->
      <form v-if="archiveName !== null" class="button-row" @submit.prevent="packSelected">
        <label class="field inline">
          <span>Name des Archivs</span>
          <input v-model="archiveName" type="text" autocomplete="off" required />
        </label>
        <button type="submit" class="button small">Packen</button>
        <button type="button" class="button small" @click="archiveName = null">Abbrechen</button>
      </form>

      <div v-else class="button-row">
        <template v-if="picking === null">
          <button type="button" class="button small" @click="picking = 'copy'">Kopieren</button>
          <button type="button" class="button small" @click="picking = 'move'">Verschieben</button>
          <button type="button" class="button small" @click="startPack">Als Zip packen</button>
          <button type="button" class="button small danger" @click="removeSelected">Entfernen</button>
          <!--
            **„Alle auswählen" steht hier und nicht nur im Spaltenkopf.**
            `.stacks thead` ist unter 720px aus dem Bild geschoben — der Haken
            oben links gibt es auf dem Telefon also gar nicht, und ohne diesen
            Knopf müsste man dort zwanzig Zeilen einzeln anhaken.
          -->
          <button
            v-if="selected.length < selectable.length"
            type="button"
            class="button small"
            @click="allSelected = true"
          >
            Alle auswählen
          </button>
          <button type="button" class="button small" @click="selected = []">Auswahl aufheben</button>
        </template>
        <button v-else type="button" class="button small" @click="picking = null">Abbrechen</button>
      </div>
    </div>

    <p v-if="props.truncated" class="notice warn">
      Dieses Verzeichnis hat mehr Einträge, als die Liste zeigt. Angezeigt wird der Anfang.
    </p>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <!--
              Der Haken in der Kopfzeile wählt alles, was die Liste **zeigt** —
              nicht alles, was im Verzeichnis liegt. Bei einer gekürzten Liste
              stünde sonst „alle ausgewählt" über einer Auswahl, die den Rest
              nicht kennt.
            -->
            <!--
              **Und er steht nur da, wo es etwas auszuwählen gibt** (`docs/56`,
              Befund 27). In der Abo-Wurzel liegen ausschliesslich die sechs
              Verzeichnisse des Schemas; `selectable` ist dort leer, und der
              Haken konnte nichts tun.

              Er tat aber etwas Sichtbares: Er blieb **angehakt** stehen. Der
              Setzer schreibt `selected = []`, der Leser rechnet daraus wieder
              `false` — und weil der Wert sich damit nicht **ändert**, schreibt
              Vue das DOM nicht zurück. Der Klick des Betrachters bleibt stehen,
              und über einer leeren Auswahl steht „alles ausgewählt".

              > **Ein Kästchen, das der Betrachter setzt und das Modell nicht,
              > zeigt danach den Klick und nicht den Zustand.**

              Die Zelle bleibt: `<td data-column="Auswahl">` steht in jeder
              Zeile, auch der leeren, und eine Kopfzeile mit fünf Spalten über
              einem Rumpf mit sechs verschiebt die ganze Tabelle.
            -->
            <th v-if="props.can.edit">
              <input
                v-if="selectable.length > 0"
                v-model="allSelected"
                type="checkbox"
                class="check"
                :aria-label="allSelected ? 'Auswahl aufheben' : 'Alle auswählen'"
              />
            </th>
            <th>Name</th><th>Grösse</th><th>Rechte</th><th>Geändert</th><th>Aktion</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="props.path !== '/'">
            <td data-column="Name" :colspan="props.can.edit ? 6 : 5">
              <button type="button" class="link" @click="open(parent)">… eine Ebene höher</button>
            </td>
          </tr>

          <tr v-for="entry in props.entries" :key="entry.path">
            <!--
              **Der Haken steht an jeder Zeile — ausser am Gerüst.**

              Ob ein Eintrag sich entfernen lässt, entscheidet sonst der Kernel,
              und was dabei herauskommt, steht danach je Eintrag in der
              Rückmeldung. Ein Haken, der bei `conf/` fehlt, sähe aus wie ein
              Anzeigefehler; eine Absage mit Grund ist eine Auskunft.

              **Bei den sechs Verzeichnissen des Schemas gilt das nicht** (`docs/55`,
              Befund 21). Ein Haken dort führt in eine Mehrfachauswahl, deren
              gefährliche Griffe — Entfernen, Verschieben — nie durchgehen; die
              Auskunft „0 von 6" kommt dann **nach** dem Klick auf einen roten
              Knopf. Wo die Zeile schon keine eigene Aktion mehr anbietet, bietet
              sie auch keine über den Umweg der Auswahl an.

              > **Eine Auswahl ist ein Versprechen, dass die Knöpfe darüber
              > gelten.**
            -->
            <td v-if="props.can.edit" data-column="Auswahl">
              <input
                v-if="! entry.fixed"
                type="checkbox"
                class="check"
                :checked="selected.includes(entry.path)"
                :aria-label="`${entry.name} auswählen`"
                @change="toggleSelected(entry.path, ($event.target as HTMLInputElement).checked)"
              />
            </td>

            <!--
              `cell-name` und nicht `ident`: Ein Dateiname darf 255 Zeichen lang
              sein, und `td .ident { white-space: nowrap }` machte die Tabelle in
              `docs/46 §20.13` 5710px breit — zehn Bildschirme Rollen durch eine
              einzige Zelle, ohne dass die Überlaufmessung eine Zahl liefert.
            -->
            <td data-column="Name" class="cell-name">
              <button
                v-if="entry.type === 'directory'"
                type="button"
                class="link"
                @click="open(entry.path)"
              >
                {{ entry.name }}/
              </button>

              <Link
                v-else-if="entry.type === 'file' && entry.readable"
                :href="`/subscriptions/${props.subscription.id}/files/edit?path=${encodeURIComponent(entry.path)}`"
                class="link"
              >
                {{ entry.name }}
              </Link>

              <span v-else>{{ entry.name }}</span>

              <!--
                Ein Verweis wird als Verweis gezeigt und sein Ziel dazu. Im
                Chroot zeigt er meistens ins Leere, wenn der Kunde ihn von
                aussen angelegt hat — und genau das soll ablesbar sein.
              -->
              <span v-if="entry.type === 'link'" class="quiet"> → {{ entry.target ?? '?' }}</span>
            </td>

            <td data-column="Grösse">{{ size(entry) }}</td>
            <td data-column="Rechte" class="ident quiet">{{ rights(entry.mode) }}</td>
            <td data-column="Geändert" class="quiet">{{ moment(entry.modified_at) }}</td>

            <td data-column="Aktion">
              <!--
                Der Knopf erscheint nur, wenn der Betrachter ihn drücken darf —
                und die Antwort darauf kommt aus derselben Policy, die ihn
                später abweist (`AbilityReachTest`). `writable` kommt aus dem
                Dateisystem und ist die zweite Bedingung: `conf/` gehört root,
                und daran ändert keine Berechtigung im Panel etwas.
              -->
              <!--
                **Auf dem Telefon steht hier erst ein Umschalter.** Die vier
                Knöpfe stapeln unter 480px auf volle Breite — richtig für eine
                Reihe, die einmal auf der Seite steht, und teuer für eine, die
                je Zeile dasteht: 162px von 344px Kärtchenhöhe, gemessen bei
                390px (`docs/55`, Befund 25).

                Ob er zu sehen ist, entscheidet `app.css` und nicht diese
                Vorlage: `.fold` steht im Grundzustand auf `display: none` und
                erscheint erst unter 720px. Eine Abfrage der Breite hier wäre
                eine zweite Fassung des Haltepunkts.
              -->
              <!--
                **Der Umschalter und seine Reihe stehen in einer eigenen
                Hülle.** Eine gestapelte Zelle ist eine Flexzeile
                (`justify-content: space-between`); ohne sie stünde die
                aufgeklappte Knopfreihe **neben** dem Umschalter statt darunter
                und wurde am rechten Rand abgeschnitten — „Umbenen…",
                „Entferne…". Gemessen bei 390px, und die Zahl hat davon nichts
                gesehen: Der Dokumentüberlauf stand auf 0, weil die Zelle
                schneidet und nicht schiebt.
              -->
              <div v-if="props.can.edit && entry.writable && !entry.fixed" class="folds">
                <button
                  type="button"
                  class="button small fold"
                  :aria-expanded="unfolded === entry.path"
                  @click="toggleActions(entry.path)"
                >
                  {{ unfolded === entry.path ? 'Aktionen zuklappen' : 'Aktionen' }}
                </button>

                <div class="button-row" :class="{ folded: unfolded !== entry.path }">
                  <button type="button" class="button small" @click="startRename(entry)">Umbenennen</button>
                  <button
                    v-if="entry.type !== 'link'"
                    type="button"
                    class="button small"
                    @click="startChmod(entry)"
                  >
                    Rechte
                  </button>
                  <button
                    v-if="isArchive(entry)"
                    type="button"
                    class="button small"
                    @click="extract(entry)"
                  >
                    Entpacken
                  </button>
                  <!--
                    Rot wie das „Entfernen" der Auswahlleiste darüber. Bis zum
                    16. August 2026 war dasselbe Wort in derselben Liste einmal
                    rot und einmal grau, je nachdem, über welchen Weg man es
                    auslöste.
                  -->
                  <button type="button" class="button small danger" @click="remove(entry)">Entfernen</button>
                </div>
              </div>
              <!--
                **Und wo nichts geht, steht warum.** Ein blosser Strich sagt
                „hier ist nichts", und für die sechs Verzeichnisse des Schemas
                wäre das die falsche Auskunft: Ihr **Inhalt** lässt sich sehr
                wohl ändern, sie selbst nicht. Der Satz ist derselbe, den der
                Agent bei einem Versuch antworten würde — nur kommt er jetzt
                vorher.
              -->
              <span v-else-if="entry.fixed" class="quiet">gehört zum Aufbau</span>
              <span v-else class="quiet">—</span>
            </td>
          </tr>

          <tr v-if="props.entries.length === 0">
            <td :colspan="props.can.edit ? 6 : 5" class="quiet">Dieses Verzeichnis ist leer.</td>
          </tr>
        </tbody>
      </table>
        </div>
      </div>
    </div>
  </PanelLayout>
</template>
