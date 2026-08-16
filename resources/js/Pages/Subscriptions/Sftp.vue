<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { useConfirmation } from '../../Composables/useConfirmation'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

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
          <p v-if="problem()" class="notice critical">
            <span>
              <b>Der Zugang kommt so nicht zustande.</b>
              <span class="ident">{{ problem()?.path }}</span> {{ problem()?.reason }}
              (Eigentümer <span class="ident">{{ problem()?.owner }}</span>,
              Rechte <span class="ident">{{ problem()?.mode }}</span>).
              OpenSSH weist die Anmeldung dann ab, ohne dem Programm des Kunden einen Grund zu nennen.
            </span>
          </p>

          <p v-else-if="props.keys.length === 0" class="notice neutral">
            <span>Es ist kein Schlüssel eingetragen — damit ist der Zugang aus. Tragen Sie unten einen ein.</span>
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
          <p v-else class="notice neutral">
            <span>Der Zugang steht: {{ props.keys.length }} Schlüssel, Verzeichnis und Rechte in Ordnung.</span>
          </p>

          <!--
            Was gilt, sagt nicht unser Block, sondern `sshd -T -C` (docs/57 §7):
            Der erste passende Match-Block gewinnt, und ein Eintrag des
            Betreibers weiter oben schlägt unseren — zu Recht, aber sichtbar.
          -->
          <p
            v-if="props.check.effective?.chrootdirectory && props.check.effective.chrootdirectory !== props.check.root"
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

          <div class="button-row">
            <button type="submit" class="button primary" :disabled="form.processing">Eintragen</button>
          </div>
        </form>
      </section>
    </div>
  </PanelLayout>
</template>
