<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { useConfirmation } from '../../Composables/useConfirmation'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { bringIntoView } from '../../scroll'
import { canGenerate, generate } from '../../ssh/openssh'

interface Key {
  id: number
  label: string
  type: string
  bits: number
  fingerprint: string
  created_by: string | null
}

interface Link {
  path: string
  owner: string
  group: string
  mode: string
  ok: boolean
  reason: string
}

interface Check {
  unavailable?: string
  root?: string
  checked_root?: string
  key_file?: string
  has_keys?: boolean
  chroot_problem?: Link | null
  key_problem?: Link | null
  effective?: Record<string, string>
}

const props = defineProps<{
  subscription: { id: number; name: string; system_user: string | null }
  keys: Key[]
  check: Check
  can: { manage: boolean }
}>()

const { ask } = useConfirmation()
const form = useForm({ label: '', key: '' })

function eintragen(): void {
  form.post(`/subscriptions/${props.subscription.id}/sftp/keys`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * Einen Schlüssel erzeugen — Wunsch 2 des Betreibers (`docs/64 §5`)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * **Wo sucht jemand diese Handlung?** Dort, wo er nach einem Schlüssel gefragt
 * wird — also in diesem Bereich, neben dem Feld, in das er ihn sonst einfügt.
 * Nicht in einem eigenen Bereich darunter: Wer keinen Schlüssel hat, merkt es
 * genau hier, und die Antwort gehört an dieselbe Stelle wie die Frage.
 *
 * Das ist die Frage aus `CLAUDE.md`, gestellt vor dem Merkmal und nicht danach
 * — dreimal hat ihr Fehlen einen Abnahmelauf gekostet (`docs/55` Befund 8,
 * `docs/59` Befund 19, `docs/64` Befund 13).
 *
 * **Der private Teil entsteht im Browser und geht nie an den Server.** Warum
 * das keine Vorsichtsmassnahme, sondern die Bauart ist, steht in
 * `resources/js/ssh/openssh.ts`.
 */

/** Ob dieser Browser es kann — `null`, solange die Frage noch läuft. */
const kannErzeugen = ref<boolean | null>(null)

onMounted(async () => { kannErzeugen.value = await canGenerate() })

/**
 * Der private Teil, einmal und nur hier.
 *
 * **Er steht in einem `ref` und in keinem Formular.** Eine Flash-Meldung läge
 * in der Tabelle `sessions`, eine Vorgangsantwort in `operations.result`
 * (`docs/64 §5.2`) — beide schreiben auf die Platte, und beide überleben mehr,
 * als einem lieb ist.
 */
const privaterTeil = ref<string | null>(null)
const dateiname = ref('id_ed25519')
const erzeugt = ref(false)
const erzeugtBlock = ref<HTMLElement | null>(null)

/** Eine Meldung über den Browser — kein Feldfehler, also auch kein roter Rand. */
const fehler = ref<string | null>(null)

/**
 * Erzeugen und im selben Zug eintragen.
 *
 * **Warum nicht erst erzeugen und dann eintragen lassen.** Der private Teil
 * wird genau einmal gezeigt; wer ihn sieht, ohne dass der öffentliche beim
 * Server angekommen ist, hält einen Schlüssel für ein Schloss, das es nicht
 * gibt. Gezeigt wird er deshalb erst nach `onSuccess`.
 *
 * `preserveState` hält die Komponente am Leben, sonst wäre `privaterTeil` nach
 * der Antwort fort.
 */
async function erzeugen(): Promise<void> {
  if (erzeugt.value || kannErzeugen.value !== true) {
    return
  }

  erzeugt.value = true

  try {
    const paar = await generate(bemerkung())

    form.key = paar.publicKey

    form.post(`/subscriptions/${props.subscription.id}/sftp/keys`, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        privaterTeil.value = paar.privateKey
        form.reset()
      },
      onError: () => { erzeugt.value = false },
      onFinish: () => { if (privaterTeil.value === null) { erzeugt.value = false } },
    })
  } catch {
    erzeugt.value = false
    fehler.value = 'Der Schlüssel liess sich in diesem Browser nicht erzeugen.'
  }
}

/**
 * Die Bemerkung im Schlüssel — sie steht später in `authorized_keys`.
 *
 * Sie ist keine Kennung und wird von niemandem geprüft; sie hilft dem Kunden,
 * seine eigenen Dateien auseinanderzuhalten. Alles, was die Zeile zerreissen
 * könnte, fällt heraus.
 */
function bemerkung(): string {
  const roh = `${form.label} ${props.subscription.system_user ?? ''}`.trim()

  return roh.replace(/\s+/g, ' ').slice(0, 100)
}

/*
 * **Der Bereich holt sich ins Bild, sobald es ihn gibt.**
 *
 * Er hängt am Zustand und nicht am Klick: Zwischen dem Druck auf „Schlüssel
 * erzeugen" und dem Erscheinen liegt eine Antwort des Servers, und ohne sie
 * gibt es nichts zu zeigen. Bei 390 px steht der Bereich sonst unterhalb des
 * Formulars — also ausserhalb des Bildes, und der Kunde sieht den einzigen
 * Moment nicht, in dem sein privater Schlüssel dasteht.
 */
watch(privaterTeil, (wert) => {
  if (wert !== null) {
    void nextTick(() => bringIntoView(erzeugtBlock.value))
  }
})

/** Die Datei anbieten, ohne dass sie über den Server geht. */
function herunterladen(): void {
  if (privaterTeil.value === null) {
    return
  }

  const url = URL.createObjectURL(new Blob([privaterTeil.value], { type: 'application/octet-stream' }))
  const a = document.createElement('a')

  a.href = url
  a.download = dateiname.value
  a.click()

  URL.revokeObjectURL(url)
}

// Kein `confirm()`: Safari darf die Dialoge einer Seite abschalten, und danach
// tut der Knopf wortlos nichts. `BrowserDialogTest` besteht darauf.
function entfernen(key: Key): void {
  ask(
    `Den Schlüssel „${key.label}“ entfernen?\n\nWer ihn benutzt, kommt danach nicht mehr herein.`,
    'Entfernen',
    () => router.delete(`/subscriptions/${props.subscription.id}/sftp/keys/${key.id}`, { preserveScroll: true }),
  )
}

// Der Befund, den das Panel zeigt, weil der Klient ihn nicht bekommt: Er sieht
// „Broken pipe“, und der Grund steht nur im Serverprotokoll (docs/50 §6).
// Zwei Ketten, nicht eine — die der Schlüsseldatei scheitert früher und mit
// anderem Wortlaut (docs/57 §9).
function problem(): Link | null {
  return props.check.chroot_problem ?? props.check.key_problem ?? null
}
</script>

<template>
  <Head :title="`SFTP — ${props.subscription.name}`" />

  <PanelLayout title="SFTP-Zugang" :subline="props.subscription.name">
    <FormErrors />

    <div class="sections">
      <section class="section">
        <div class="section-head"><h2>Verbinden</h2></div>

        <p>
          Angemeldet wird mit einem Schlüssel — <b>ein Passwort gibt es nicht</b>. Der
          Zugang führt ausschliesslich in das Abonnement; eine Shell hat er nicht.
        </p>

        <!--
          `table.pairs` mit `td.quiet` links und `td.right.ident` rechts — die
          Form, die zehn andere Paar-Tabellen dieses Panels benutzen. Eine
          zweite Form für dieselbe Sache heisst: Eine wird gepflegt und die
          andere nicht.
        -->
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Benutzer</td>
              <td class="right ident">{{ props.subscription.system_user ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Verzeichnis</td>
              <td class="right ident">{{ props.check.root ?? '—' }}</td>
            </tr>
          </tbody>
        </table>

        <!--
          Ohne diesen Satz legt jemand seinen Schlüssel nach .ssh/authorized_keys
          und sucht einen Nachmittag lang. Gemessen (docs/57 §4): Die Datei dort
          wird nicht gelesen, sobald der verwaltete Block eine eigene nennt.
        -->
        <p class="section-note">
          Ein Schlüssel, den Sie selbst nach <span class="ident">.ssh/authorized_keys</span>
          legen, wird nicht gelesen. Es gilt ausschliesslich, was hier steht.
        </p>
      </section>

      <section class="section">
        <div class="section-head"><h2>Lage</h2></div>

        <!--
          **Jede Meldung mit mehr als einem Kind bekommt einen Wrapper.**
          `.notice` ist ein Flex-Behälter; ohne das umschliessende `span` wird
          jedes `ident` darin ein eigenes Flex-Kind, und bei 390 px steht die
          Meldung als Spalten aus fünf Zeichen da — bei einem Dokumentüberlauf
          von 0 px. `NoticeShapeTest` besteht darauf; gefunden hat es hier die
          Aufnahme und nicht die Zahl.
        -->
        <p v-if="props.check.unavailable" class="notice warn">
          <span>Der Zustand des Zugangs lässt sich gerade nicht feststellen: {{ props.check.unavailable }}</span>
        </p>

        <template v-else>
          <!--
            **Die Reihenfolge ist der Befund aus dem Lauf.** Hier stand der
            Defekt zuerst, und ohne Schlüssel gibt es die Schlüsseldatei nicht
            — also meldete `Chain` „gibt es nicht", und die Seite schrieb rot
            „Der Zugang kommt so nicht zustande" für ein Abonnement, an dem
            nur noch niemand etwas eingerichtet hatte.

            > **Ein Zustand, in dem noch nichts eingerichtet ist, sieht für
            > eine Prüfung genauso aus wie einer, in dem etwas kaputt ist — und
            > nur der Code kennt den Unterschied.**

            Ohne Schlüssel gibt es keinen Zugang, den etwas klemmen könnte. Die
            Ketten werden ab dem ersten Schlüssel beurteilt und nicht davor.
          -->
          <p v-if="props.keys.length === 0" class="notice neutral">
            <span>Es ist kein Schlüssel eingetragen — damit ist der Zugang aus. Tragen Sie unten einen ein.</span>
          </p>

          <p v-else-if="problem()" class="notice critical">
            <span>
              <!--
                `{{ ' ' }}` und nicht ein Zeilenumbruch: Vues Vorgabe
                `whitespace: 'condense'` entfernt einen Textknoten zwischen zwei
                Elementen, wenn er einen Umbruch enthält. Im Lauf stand deshalb
                „zustande./etc/srvpanel/ssh" ohne Leerzeichen.
              -->
              <b>Der Zugang kommt so nicht zustande.</b>{{ ' ' }}
              <span class="ident">{{ problem()?.path }}</span> {{ problem()?.reason }}
              (Eigentümer <span class="ident">{{ problem()?.owner }}</span>,
              Rechte <span class="ident">{{ problem()?.mode }}</span>).
              OpenSSH weist die Anmeldung dann ab, ohne dem Programm des Kunden einen Grund zu nennen.
            </span>
          </p>

          <!--
            **Hier ist die Marke bewusst nicht die grüne.** Grün meldet den
            Erfolg eines *Vorgangs* (docs/19 §6.3); hier steht ein Zustand, den
            niemand gerade ausgelöst hat. Wer den Zustand grün malt, hat für den
            nächsten Vorgang keine Farbe mehr übrig.

            Und der Klassenname dazu steht hier **nicht ausgeschrieben**:
            `FieldErrorTest` liest den Quelltext als Text, und eine Begründung,
            die den verbotenen Namen nennt, ist für ihn ein Verstoss.

            > Ein Wächter, der Text liest, liest auch die Begründung dafür,
            > warum er recht hat.
          -->
          <!--
            **Und der Satz nennt das Verzeichnis, wenn es nicht das des
            Abonnements ist** (`docs/59`, Befund 10). Im Lauf stand hier
            „Verzeichnis und Rechte in Ordnung", während `sshd -T` `/var/www`
            nannte — wahr über das eine Verzeichnis, gelesen über das andere.

            > **Ein Satz ohne Gegenstand bekommt den, den der Leser erwartet.**
          -->
          <!--
            **Ein Wickel und die Verzweigung darin, nicht zwei Wickel.**
            `NoticeShapeTest` verlangt ein *attributfreies* `span` als einziges
            Kind, und das zu Recht: Zwei `span` mit `v-if`/`v-else` sind für
            einen Leser des Quelltexts zwei Flexkinder, und die Regel wäre
            danach eine, die man von Fall zu Fall auslegt.
          -->
          <p v-else class="notice neutral">
            <span>
              <template v-if="props.check.checked_root && props.check.checked_root !== props.check.root">
                Der Zugang steht: {{ props.keys.length }} Schlüssel. Geprüft ist{{ ' ' }}
                <span class="ident">{{ props.check.checked_root }}</span> — das Verzeichnis, das gilt.
              </template>
              <template v-else>
                Der Zugang steht: {{ props.keys.length }} Schlüssel, Verzeichnis und Rechte in Ordnung.
              </template>
            </span>
          </p>

          <!--
            Was gilt, sagt nicht unser Block, sondern `sshd -T -C` (docs/57 §7):
            Der erste passende Match-Block gewinnt, und ein Eintrag des
            Betreibers weiter oben schlägt unseren — zu Recht, aber sichtbar.
          -->
          <!--
            **`none` ist die Abwesenheit einer Angabe und keine andere Angabe.**
            `sshd -T` schreibt `chrootdirectory none`, wenn nichts gesetzt ist;
            im Lauf stand daraufhin „gilt ein anderes Verzeichnis: none. Eine
            Regel des Betreibers steht über der des Panels" — für einen Server,
            auf dem schlicht noch kein Block existierte.

            > **Ein Platzhalter für „nichts" ist ein Wert, und eine Prüfung, die
            > nur auf „ungleich" sieht, hält ihn für eine Aussage.**

            Und ohne Schlüssel gibt es keinen Block, der überschrieben sein
            könnte — deshalb zählt diese Meldung erst ab dem ersten.
          -->
          <p
            v-if="
              props.keys.length > 0 &&
              props.check.effective?.chrootdirectory &&
              props.check.effective.chrootdirectory !== 'none' &&
              props.check.effective.chrootdirectory !== props.check.root
            "
            class="notice warn"
          >
            <span>
              In <span class="ident">sshd_config</span> gilt für diesen Benutzer ein anderes
              Verzeichnis als das des Abonnements:
              <span class="ident">{{ props.check.effective.chrootdirectory }}</span>.
              Eine Regel des Betreibers steht über der des Panels.
            </span>
          </p>
        </template>
      </section>

      <section class="section">
        <div class="section-head"><h2>Schlüssel</h2></div>

        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Bezeichnung</th><th>Art</th><th>Fingerabdruck</th>
                <th v-if="props.can.manage">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="key in props.keys" :key="key.id">
                <!--
                  **`cell-name` bleibt, und das ist gemessen.** Sie einmal
                  wegzunehmen war der Versuch, eine Naht loszuwerden, die
                  `BlockSpacingTest` meldet — und hundert Zeichen ohne
                  Leerzeichen machten den Tabelleninhalt daraufhin **1129 px**
                  breit statt 390, bei einem Dokumentüberlauf von 0 px.

                  > **Eine Zelle, die rollen darf, hat keine Obergrenze — sie
                  > hat nur keine Zahl, die sich beschwert.** (docs/46 §20.13)

                  Die Naht selbst steht als offene in `BlockSpacingTest`, neben
                  `check + cell-name`: Es sind zwei Tabellenzellen, und ihren
                  Abstand hat `.stacks td` längst.
                -->
                <td data-column="Bezeichnung" class="cell-name">{{ key.label }}</td>
                <td data-column="Art"><span class="ident">{{ key.type }}</span> {{ key.bits }} Bit</td>
                <!--
                  `td .ident` und **nicht** `.cell-name` darum herum: Die
                  beiden sind Alternativen (`docs/46 §20.13`), und
                  `BlockSpacingTest` liest die Schachtelung als Naht, die es
                  in app.css nicht gibt. Ein Fingerabdruck ist eine Kennung,
                  also `ident` — und `.stacks td .ident` trägt seit
                  `docs/46 §20.11` das `overflow-wrap: anywhere`, das ihn
                  brechen lässt.
                -->
                <td data-column="Fingerabdruck"><span class="ident">{{ key.fingerprint }}</span></td>
                <!--
                  **Die Knöpfe einer Aktionsspalte stehen in einer
                  `.button-row`**, so wie in jeder anderen Tabelle dieses
                  Panels. Ohne sie steht `.button` unmittelbar unter dem
                  `.ident` der Nachbarzelle — eine Naht, die app.css nicht
                  kennt, während `ident + button-row` längst darin steht.

                  Die Form war also schon da; ich hatte sie nur nicht benutzt.
                -->
                <td v-if="props.can.manage" data-column="Aktion">
                  <div class="button-row">
                    <button type="button" class="button danger" @click="entfernen(key)">Entfernen</button>
                  </div>
                </td>
              </tr>
              <tr v-if="props.keys.length === 0">
                <td :colspan="props.can.manage ? 4 : 3" class="quiet">Kein Schlüssel eingetragen.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="props.can.manage" class="section">
        <div class="section-head"><h2>Schlüssel eintragen</h2></div>

        <form @submit.prevent="eintragen">
          <label class="field">
            <span>Bezeichnung</span>
            <input v-model="form.label" type="text" required maxlength="100" :aria-invalid="Boolean(form.errors.label)" />
          </label>

          <label class="field">
            <span>Öffentlicher Schlüssel</span>
            <textarea
              v-model="form.key"
              rows="4"
              required
              spellcheck="false"
              :aria-invalid="Boolean(form.errors.key)"
              placeholder="ssh-ed25519 AAAA…"
            />
          </label>

          <p class="section-note">
            Der Inhalt Ihrer Datei <span class="ident">.pub</span> — nicht der private Schlüssel.
            Angenommen werden ed25519, ECDSA und RSA ab 2048 Bit.
          </p>

          <!--
            **Der Satz steht vor dem Knopf und nicht nach dem Erzeugen.**
            Danach gelesen ist er eine Feststellung; davor gelesen ist er die
            Auskunft, die man braucht, um sich vorzubereiten. Derselbe
            Unterschied wie bei Befund 12 der Cronseite.
          -->
          <p v-if="kannErzeugen === true" class="notice neutral">
            <span>
              Haben Sie keinen Schlüssel, erzeugt ihn dieser Browser — der private Teil
              entsteht auf Ihrem Gerät und wird <b>einmal</b> zum Herunterladen angeboten.
              Danach kennt ihn niemand mehr, auch dieses Panel nicht. Wer ihn verliert,
              erzeugt einen neuen und entfernt den alten.
            </span>
          </p>

          <!--
            Ein Browser ohne Ed25519 in WebCrypto. Kein roter Rand an einem
            Feld: Das Feld ist nicht falsch, der Browser kann es nicht.

            > **Ein roter Rand am Feld behauptet, das Feld sei falsch.**
          -->
          <p v-else-if="kannErzeugen === false" class="notice neutral">
            <span>
              Dieser Browser kann keine Schlüssel erzeugen. Fügen Sie oben einen ein —
              erzeugen lässt er sich auf Ihrem Rechner mit
              <span class="ident">ssh-keygen -t ed25519</span>.
            </span>
          </p>

          <p v-if="fehler !== null" class="notice critical"><span>{{ fehler }}</span></p>

          <div class="button-row">
            <button type="submit" class="button primary" :disabled="form.processing">Eintragen</button>
            <button
              v-if="kannErzeugen === true"
              type="button"
              class="button"
              :disabled="form.processing || erzeugt"
              @click="erzeugen"
            >
              Schlüssel erzeugen
            </button>
          </div>
        </form>

        <!--
          **Der private Teil, einmal.** Er steht erst hier, wenn der öffentliche
          beim Server angekommen ist — sonst hielte der Kunde einen Schlüssel
          für ein Schloss, das es nicht gibt.
        -->
        <div v-if="privaterTeil !== null" ref="erzeugtBlock" class="block" tabindex="-1">
          <p class="notice warn">
            <span>
              Das ist Ihr <b>privater</b> Schlüssel. Er wird hier zum einzigen Mal gezeigt:
              Laden Sie ihn herunter oder kopieren Sie ihn jetzt. Geben Sie ihn niemandem —
              wer ihn hat, kommt an Ihre Dateien.
            </span>
          </p>

          <label class="field wide">
            <span>Privater Schlüssel</span>
            <textarea :value="privaterTeil" rows="9" readonly spellcheck="false" class="code short" />
          </label>

          <p class="section-note">
            Auf Ihrem Rechner gehört er nach <span class="ident">~/.ssh/{{ dateiname }}</span>
            und braucht dort die Rechte <span class="ident">600</span>. Danach meldet
            <span class="ident">sftp</span> sich damit an.
          </p>

          <div class="button-row">
            <button type="button" class="button primary" @click="herunterladen">Herunterladen</button>
          </div>
        </div>
      </section>
    </div>
  </PanelLayout>
</template>
