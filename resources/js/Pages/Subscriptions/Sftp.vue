<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
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

const form = useForm({ label: '', key: '' })

function eintragen(): void {
  form.post(`/subscriptions/${props.subscription.id}/sftp/keys`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function entfernen(key: Key): void {
  if (!confirm(`Den Schlüssel „${key.label}“ entfernen? Wer ihn benutzt, kommt danach nicht mehr herein.`)) {
    return
  }

  router.delete(`/subscriptions/${props.subscription.id}/sftp/keys/${key.id}`, { preserveScroll: true })
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

        <p v-if="props.check.unavailable" class="notice warn">
          Der Zustand des Zugangs lässt sich gerade nicht feststellen: {{ props.check.unavailable }}
        </p>

        <template v-else>
          <p v-if="problem()" class="notice critical">
            <b>Der Zugang kommt so nicht zustande.</b>
            <span class="ident">{{ problem()?.path }}</span> {{ problem()?.reason }}
            (Eigentümer <span class="ident">{{ problem()?.owner }}</span>,
            Rechte <span class="ident">{{ problem()?.mode }}</span>).
            OpenSSH weist die Anmeldung dann ab, ohne dem Programm des Kunden einen Grund zu nennen.
          </p>

          <p v-else-if="props.keys.length === 0" class="notice neutral">
            Es ist kein Schlüssel eingetragen — damit ist der Zugang aus. Tragen Sie unten einen ein.
          </p>

          <p v-else class="notice ok">
            Der Zugang steht: {{ props.keys.length }} Schlüssel, Verzeichnis und Rechte in Ordnung.
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
            In <span class="ident">sshd_config</span> gilt für diesen Benutzer ein anderes
            Verzeichnis als das des Abonnements:
            <span class="ident">{{ props.check.effective.chrootdirectory }}</span>.
            Eine Regel des Betreibers steht über der des Panels.
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
                <th v-if="props.can.manage">Entfernen</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="key in props.keys" :key="key.id">
                <td data-column="Bezeichnung" class="cell-name">{{ key.label }}</td>
                <td data-column="Art"><span class="ident">{{ key.type }}</span> {{ key.bits }} Bit</td>
                <td data-column="Fingerabdruck" class="cell-name"><span class="ident">{{ key.fingerprint }}</span></td>
                <td v-if="props.can.manage" data-column="Entfernen">
                  <button type="button" class="button danger" @click="entfernen(key)">Entfernen</button>
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
