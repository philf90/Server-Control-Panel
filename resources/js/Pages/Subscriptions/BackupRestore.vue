<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { formatBytes } from '../../bytes'

const props = defineProps<{
  backup: { id: number; storage_name: string; bytes: number | null; created_at: string | null }

  /** Was in der Sicherung steht — Zahlen und keine Zusage. */
  contents: {
    subscription: string
    plan: string | null
    domains: number
    databases: number
    db_users: number
    cron: number
    ssh_keys: number
    created_at: string
    panel: string
  }

  /**
   * Der alte Name, solange er frei ist — sonst `null`.
   *
   * Ist er vergeben, gibt es keinen Vorschlag: Einen zu erfinden hiesse, dem
   * Betreiber eine Entscheidung abzunehmen, die er sehen soll.
   */
  suggested_name: string | null

  customers: { id: number; label: string; suspended: boolean }[]
  plans: { id: number; label: string; is_default: boolean }[]
}>()

const form = useForm({
  // Der Vorschlag überspringt gesperrte Kunden — dieselbe Überlegung wie beim
  // Anlegen: Stünde einer oben im Feld, bekäme man beim Absenden eine
  // Fehlermeldung über eine Auswahl, die man nie getroffen hat.
  customer_id: props.customers.find((c) => !c.suspended)?.id ?? null,
  plan_id: props.plans.find((p) => p.is_default)?.id ?? props.plans[0]?.id ?? null,
  name: props.suggested_name ?? '',
})

/** Die Einzahl am Wert und nicht am Wort — dieselbe Regel wie in PHP. */
function gezaehlt(anzahl: number, einzahl: string, mehrzahl: string): string {
  return anzahl === 1 ? `1 ${einzahl}` : `${anzahl} ${mehrzahl}`
}
</script>

<template>
  <Head title="Sicherung zurückspielen" />

  <PanelLayout
    title="Sicherung zurückspielen"
    subline="Sie entsteht als neues Abonnement — die Sicherung bleibt, wie sie ist"
  >
    <template #breadcrumb>
      <Link href="/backups" class="link">Sicherungen</Link>
    </template>

    <FormErrors />

    <div class="sections">
      <Section title="Diese Sicherung" full>
        <table class="pairs">
          <tbody>
            <tr>
              <td>Ablage</td>
              <td class="right ident">{{ props.backup.storage_name }}</td>
            </tr>
            <tr>
              <td>Abonnement</td>
              <td class="right ident">{{ props.contents.subscription }}</td>
            </tr>
            <tr>
              <td>Erstellt</td>
              <td class="right">{{ props.backup.created_at ?? '—' }}</td>
            </tr>
            <tr>
              <td>Grösse</td>
              <td class="right">{{ props.backup.bytes === null ? '—' : formatBytes(props.backup.bytes) }}</td>
            </tr>
            <tr>
              <td>Plan damals</td>
              <td class="right">{{ props.contents.plan ?? 'nicht vermerkt' }}</td>
            </tr>
            <tr>
              <td>Enthält</td>
              <td class="right">
                {{ gezaehlt(props.contents.domains, 'Domain', 'Domains') }},
                {{ gezaehlt(props.contents.databases, 'Datenbank', 'Datenbanken') }},
                {{ gezaehlt(props.contents.cron, 'Cronjob', 'Cronjobs') }}
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        **Was sich ändert, steht vor dem Knopf und nicht danach.**

        `docs/117 §3` nennt drei Preise von Form A, und der Plan hat bis zum
        16. September nur einen davon genannt.

        > **Eine Aufzählung, die einen von drei Preisen nennt, liest sich wie
        > der ganze Preis.**
      -->
      <Section title="Was sich dadurch ändert" full>
        <p class="notice warn">
          Das wiederhergestellte Abonnement bekommt einen neuen Systembenutzer —
          und das ist der SFTP-Benutzername des Kunden. Seine Datenbanken heissen
          danach anders, weil sie ein neues Präfix bekommen; wer sie in einer
          Konfigurationsdatei nennt, trägt die neuen Namen nach. Die Zuordnung
          alt zu neu steht anschliessend beim Vorgang.
        </p>

        <p class="hint">
          Datenbankpasswörter sind nicht gesichert — dieses Panel hält keine. Die
          Zugänge stehen nachher wieder da; jeder braucht einmal
          „Passwort neu setzen".
        </p>

        <p class="hint">
          Der Verzeichnispfad bleibt derselbe, solange der Name frei ist. Domains,
          Cronjobs und SFTP-Schlüssel werden aus der Beschreibung neu erzeugt und
          nicht zurückgespielt.
        </p>
      </Section>

      <form class="form" @submit.prevent="form.post(`/backups/${props.backup.id}/restore`)">
        <Section title="Wohin" full>
          <p v-if="props.suggested_name === null" class="notice warn">
            Das Abonnement {{ props.contents.subscription }} gibt es schon. Das
            wiederhergestellte braucht einen anderen Namen — und liegt dann auch
            in einem anderen Verzeichnis.
          </p>

          <label class="field">
            <span>Kunde</span>
            <select v-model="form.customer_id" required :aria-invalid="Boolean(form.errors.customer_id)">
              <option v-for="c in props.customers" :key="c.id" :value="c.id" :disabled="c.suspended">
                {{ c.label }}{{ c.suspended ? ' · gesperrt' : '' }}
              </option>
            </select>
          </label>

          <label class="field">
            <span>Plan</span>
            <select v-model="form.plan_id" required :aria-invalid="Boolean(form.errors.plan_id)">
              <option v-for="p in props.plans" :key="p.id" :value="p.id">{{ p.label }}</option>
            </select>
          </label>

          <label class="field">
            <span>Name</span>
            <input v-model="form.name" type="text" maxlength="63" required :aria-invalid="Boolean(form.errors.name)">
          </label>

          <div class="button-row">
            <button type="submit" class="button" :disabled="form.processing">Zurückspielen</button>
            <Link href="/backups" class="button">Abbrechen</Link>
          </div>
        </Section>
      </form>
    </div>
  </PanelLayout>
</template>
