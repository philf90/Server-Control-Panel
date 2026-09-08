<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { counted } from '../../Composables/useCounted'

/**
 * Die Zeitpläne dieses Servers — A6 (`docs/111`).
 *
 * Drei Gegenstände und deshalb drei Bereiche: Tabellen mit Benutzerfeld
 * (`/etc/crontab`, `/etc/cron.d`), Verzeichnisse mit Skripten und **ohne
 * eigenen Zeitplan**, und das, was in einem Verzeichnis liegt und nicht läuft.
 * In einer gemeinsamen Tabelle bliebe bei den einen die Zeitspalte leer.
 */

/** Eine Zeile mit Benutzerfeld, so wie `system.cron` sie liefert. */
interface Entry {
  schedule: string
  user: string
  command: string
}

/** Eine Datei mit Benutzerfeld — `/etc/crontab` oder eine aus `/etc/cron.d`. */
interface Table {
  path: string

  /** Ob das Panel sie selbst schreibt. Dann steht ihr Inhalt auf `/cron`. */
  owned: boolean

  readable: boolean
  entries: Entry[]

  /**
   * `SHELL`, `PATH`, `MAILTO` — was cron als Umgebung liest.
   *
   * Ein leerer Wert kommt aus PHP als `[]` und nicht als `{}`; `Object.entries`
   * verträgt beides, und deshalb steht hier kein Sonderfall.
   */
  env: Record<string, string>
}

/** Ein `cron.*`-Verzeichnis samt seinem Zeitplan aus `/etc/crontab`. */
interface Directory {
  name: string
  path: string

  /**
   * Ob `/etc/crontab` gelesen werden konnte.
   *
   * **Drei Zustände und nicht zwei.** `known: false` heisst „nicht
   * nachgesehen", `schedule: null` bei `known: true` heisst „es gibt keine
   * Zeile" — und das ist der Zustand von `cron.yearly` auf jedem gemessenen
   * Debian. Ohne dieses Feld sähen beide gleich aus.
   */
  known: boolean

  schedule: string | null

  /** `anacron`, wenn die Zeile den Vorbehalt trägt — sonst `null`. */
  conditional: string | null

  readable: boolean
  scripts: string[]
  ignored: { name: string; reason: string }[]
}

const props = defineProps<{
  cron: {
    readable: boolean
    reason: string | null

    /** Ob `/usr/sbin/anacron` da ist — die Frage, die `/etc/crontab` selbst stellt. */
    anacron: boolean

    tables: Table[]
    directories: Directory[]
  }
}>()

/**
 * Eine Zeile der oberen Tabelle.
 *
 * **Vier Arten und nicht eine.** Eine Datei, die es gibt und die nichts
 * enthält, verschwände sonst aus der Ansicht — und eine, die nicht lesbar ist,
 * sähe genauso aus. Beides sind Auskünfte über den Server.
 */
type Row =
  | { kind: 'entry'; path: string; schedule: string; user: string; command: string }
  | { kind: 'owned'; path: string }
  | { kind: 'unreadable'; path: string }
  | { kind: 'empty'; path: string }

const zeilen = computed<Row[]>(() => {
  const alle: Row[] = []

  for (const tabelle of props.cron.tables) {
    if (!tabelle.readable) {
      alle.push({ kind: 'unreadable', path: tabelle.path })
      continue
    }

    /*
     * **Die eigenen Dateien stehen als eine Zeile da und nicht mit Inhalt**
     * (`docs/111 §2`, Frage 2). Sie haben ihre Seite; sie hier ein zweites Mal
     * auszuschreiben wäre eine zweite Anzeige derselben Sache — und die zweite
     * ist die, die veraltet.
     */
    if (tabelle.owned) {
      alle.push({ kind: 'owned', path: tabelle.path })
      continue
    }

    if (tabelle.entries.length === 0) {
      alle.push({ kind: 'empty', path: tabelle.path })
      continue
    }

    for (const eintrag of tabelle.entries) {
      alle.push({ kind: 'entry', path: tabelle.path, ...eintrag })
    }
  }

  return alle
})

/** Die Dateien, die eine Umgebung setzen — als Satz und nicht als vierte Spalte. */
const umgebungen = computed(() =>
  props.cron.tables
    .filter((t) => t.readable && !t.owned && Object.entries(t.env ?? {}).length > 0)
    .map((t) => ({
      path: t.path,
      text: Object.entries(t.env)
        .map(([name, wert]) => `${name}=${wert}`)
        .join('  '),
    })),
)

/** Alles, was in einem Verzeichnis liegt und nicht läuft — über alle Verzeichnisse. */
const uebergangen = computed(() =>
  props.cron.directories.flatMap((v) =>
    // **Der Pfad und nicht der Name.** Der Bereich darüber nennt dasselbe
    // Verzeichnis mit seinem Pfad; zwei Schreibweisen auf einer Seite lesen
    // sich als zwei Sachen.
    v.ignored.map((datei) => ({ path: v.path, ...datei })),
  ),
)

/**
 * Wann ein Verzeichnis läuft — in Worten, weil eine Zeitangabe hier lügen kann.
 *
 * Gemessen (`docs/81 §2.3t` M1): Drei der vier Zeilen tragen
 * `test -x /usr/sbin/anacron ||`. Ist anacron da, tut cron für sie **gar
 * nichts**, und die Zeitpunkte stehen in `/etc/anacrontab`. Wer den Zeitpunkt
 * ohne den Vorbehalt zeigt, zeigt auf jedem Server mit anacron eine falsche
 * Uhrzeit.
 */
function laeuft(v: Directory): { text: string; kennung: boolean } {
  if (!v.known) {
    return { text: 'nicht feststellbar', kennung: false }
  }

  if (v.schedule === null) {
    return { text: 'kein Zeitplan — nichts hier läuft', kennung: false }
  }

  if (v.conditional === 'anacron' && props.cron.anacron) {
    return { text: 'anacron bestimmt den Zeitpunkt', kennung: false }
  }

  /*
   * **`kennung` reist mit, statt in der Vorlage entschieden zu werden.**
   * Diese Zelle zeigt einmal einen Zeitplan und einmal einen Satz; ein festes
   * `.ident` setzte den Satz in Monospace, gar keines den Zeitplan in die
   * Fliesstextschrift. Gefunden hat das die Bilderrunde: Derselbe Ausdruck
   * stand im Bereich darüber in Monospace und hier daneben in der Textschrift.
   *
   * > **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte
   * > Auskunft, sondern eine widersprüchliche.**
   */
  return { text: v.schedule, kennung: true }
}

/**
 * Warum eine Datei nicht läuft.
 *
 * **„läuft nicht" und nicht „ungültig"** (`docs/111 §3.2`): Die Datei ist in
 * Ordnung, sie hat nur einen Namen, den `run-parts` übergeht.
 */
const GRUENDE: Record<string, string> = {
  dot: 'Punkt im Namen',
  character: 'Zeichen, das run-parts nicht zulässt',
  'not-executable': 'kein Ausführbit',
  directory: 'ein Verzeichnis und kein Skript',
  unknown: 'übergangen — der Grund steht nicht fest',
}

function grund(schluessel: string): string {
  return GRUENDE[schluessel] ?? GRUENDE.unknown
}
</script>

<template>
  <Head title="Zeitpläne" />

  <PanelLayout
    title="Zeitpläne"
    subline="Was auf diesem Server zeitgesteuert läuft — und was danebenliegt und nie läuft"
  >
    <p v-if="!props.cron.readable" class="notice critical">
      Die Zeitpläne sind nicht feststellbar — der Agent hat nicht geantwortet.
      Das heisst nicht, dass keine laufen.
    </p>

    <!--
      **Kein Hinweis auf ein Verzeichnis ohne Zeitplan.** `cron.yearly` steht
      auf jedem gemessenen Debian ohne Zeile in `/etc/crontab`; ein Satz darüber
      stünde auf jedem heilen Server und wäre in einem Monat überlesen. Er steht
      in der Tabelle, wo er hingehört.
    -->
    <!--
      **Beide Verben werden übergeben und keines abgeleitet.** Der erste Wurf
      zählte nur das erste Wort und schrieb „3 Dateien liegen in einem
      Verzeichnis und **läuft** nicht" — dieselbe Familie wie „geschätzt 1
      Zeilen" (`docs/48 §3.3`), nur eine Konjunktion weiter. Gefunden hat es
      das Bild und keine Zahl.
    -->
    <p v-else-if="uebergangen.length > 0" class="notice warn">
      {{ counted(
        uebergangen.length,
        'Datei liegt in einem Verzeichnis und läuft nicht',
        'Dateien liegen in einem Verzeichnis und laufen nicht',
      ) }}.
    </p>

    <div class="sections">
      <Section
        title="Zeitpläne"
        full
        note="Die Zeilen aus /etc/crontab und /etc/cron.d. Was das Panel selbst schreibt, steht als eine Zeile — sein Inhalt gehört auf die Cronseite."
      >
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Datei</th>
                <th>Zeitplan</th>
                <th>Benutzer</th>
                <th>Kommando</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(zeile, i) in zeilen" :key="`${zeile.path}-${i}`">
                <td data-column="Datei"><span class="ident">{{ zeile.path }}</span></td>

                <td data-column="Zeitplan">
                  <span v-if="zeile.kind === 'entry'" class="ident">{{ zeile.schedule }}</span>
                  <span v-else class="quiet">—</span>
                </td>

                <td data-column="Benutzer">
                  <span v-if="zeile.kind === 'entry'" class="ident">{{ zeile.user }}</span>
                  <span v-else class="quiet">—</span>
                </td>

                <!--
                  **Das Kommando steht vollständig da** (`docs/111 §2`, Frage 3).
                  Ein gekürztes Kommando ist die Auskunft, die man gerade nicht
                  brauchen kann — und `.cell-command` bricht, statt zu rollen:
                  `docs/46 §20.13` hat gemessen, was eine nicht brechende
                  Textzelle bei 390 px anrichtet.
                -->
                <td v-if="zeile.kind === 'entry'" data-column="Kommando">
                  <div class="cell-command">{{ zeile.command }}</div>
                </td>

                <td v-else-if="zeile.kind === 'owned'" data-column="Kommando" class="quiet">
                  Vom Panel verwaltet —
                  <Link href="/cron" class="link">auf der Cronseite</Link>.
                </td>

                <td v-else-if="zeile.kind === 'unreadable'" data-column="Kommando" class="quiet">
                  Nicht lesbar.
                </td>

                <td v-else data-column="Kommando" class="quiet">Keine Zeile.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!--
          **Die Umgebung steht als Satz und nicht als Spalte.** Sie gilt für die
          ganze Datei und nicht für eine Zeile; in einer Spalte stünde sie bei
          jeder Zeile derselben Datei noch einmal.
        -->
        <p v-for="u in umgebungen" :key="u.path" class="hint">
          <span class="ident">{{ u.path }}</span> setzt
          <span class="ident">{{ u.text }}</span>.
        </p>
      </Section>

      <Section
        title="Verzeichnisse"
        full
        note="Ein Skript hier hat keinen eigenen Zeitplan — der steht als Zeile in /etc/crontab. Trägt die Zeile den anacron-Vorbehalt, bestimmt anacron den Zeitpunkt und nicht cron."
      >
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Verzeichnis</th>
                <th>Läuft</th>
                <th>Skripte</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in props.cron.directories" :key="v.path">
                <td data-column="Verzeichnis"><span class="ident">{{ v.path }}</span></td>
                <td data-column="Läuft">
                  <span :class="{ ident: laeuft(v).kennung }">{{ laeuft(v).text }}</span>
                </td>
                <td data-column="Skripte">
                  <div class="cell-command">
                    <span v-if="!v.readable" class="quiet">nicht feststellbar</span>
                    <span v-else-if="v.scripts.length === 0" class="quiet">keine</span>
                    <template v-else>{{ v.scripts.join(', ') }}</template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <!--
        **Der Bereich steht nur da, wenn es etwas zu zeigen gibt**
        (`docs/111 §3.2`). Leer wäre er eine Beruhigung, die niemand bestellt
        hat — und ein Bereich, der immer dasteht, wird überlesen.
      -->
      <Section
        v-if="uebergangen.length > 0"
        title="Übergangen"
        full
        note="Diese Dateien liegen in einem cron.*-Verzeichnis und werden von run-parts nicht ausgeführt. Sie sind nicht kaputt — sie haben einen Namen oder Rechte, die run-parts übergeht."
      >
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Verzeichnis</th>
                <th>Datei</th>
                <th>Warum</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in uebergangen" :key="`${d.path}/${d.name}`">
                <td data-column="Verzeichnis"><span class="ident">{{ d.path }}</span></td>
                <td data-column="Datei"><div class="cell-command">{{ d.name }}</div></td>
                <td data-column="Warum">{{ grund(d.reason) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>
