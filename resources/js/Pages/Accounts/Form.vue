<script setup lang="ts">
/*
 * Ein Adminkonto anlegen oder ändern.
 *
 * **Ein Formular für beides**, wie bei den Plänen: Die Felder sind dieselben,
 * und zwei Dateien wären zwei Fassungen derselben Prüfliste.
 *
 * **Was sich unterscheidet, sind zwei Felder, und beide haben einen Grund.**
 * Die Anmeldeadresse steht nur beim Anlegen — sie ist die Anmeldung und steht
 * im Protokoll, ihr Wechsel ist ein eigener Vorgang mit Bestätigung
 * (`docs/82 §2.4`). Der Zustand steht nur beim Ändern: Ein Konto, das man
 * gesperrt anlegt, ist ein Konto, das man nicht anlegen wollte.
 *
 * **Das Passwort wird im Browser erzeugt** — `PasswordFields` mit seinem Knopf,
 * dieselbe Komponente wie beim Anlegen eines Kunden. Der erste Wurf von
 * `docs/82 §2.4` plante, dass der Server es erzeugt und einmalig anzeigt; die
 * Begründung dagegen ist älter als der Plan und steht im Kopf von
 * `App\Support\Passwords\Policy::generate()`.
 */
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import PasswordFields from '../../Components/PasswordFields.vue'
import Section from '../../Components/Section.vue'

interface PasswordPolicy {
  minimum: number
  requirements: { key: string; label: string }[]
}

interface Existing {
  id: number
  name: string
  email: string | null
  two_factor: boolean
  last_login_at: string | null
}

const props = defineProps<{
  account: Existing | null
  values: { name: string; email?: string; role: string | null; status?: string }
  roles: { value: string; label: string }[]
  isLastOperator: boolean
}>()

const page = usePage<{ passwordPolicy: PasswordPolicy }>()
const policy = computed(() => page.props.passwordPolicy)

const form = useForm({
  name: props.values.name,
  email: props.values.email ?? '',
  role: props.values.role ?? '',
  status: props.values.status ?? 'active',
  password: '',
  password_confirmation: '',
})

/* Das Zurücksetzen ist ein eigener Vorgang und deshalb ein eigenes Formular. */
const reset = useForm({ password: '', password_confirmation: '' })

function submit(): void {
  if (props.account === null) {
    form.post('/accounts', { onFinish: () => form.reset('password', 'password_confirmation') })

    return
  }

  form.patch(`/accounts/${props.account.id}`)
}

function submitReset(): void {
  if (props.account === null) return

  reset.post(`/accounts/${props.account.id}/password`, {
    onFinish: () => reset.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head :title="props.account ? `Konto ${props.account.name}` : 'Konto anlegen'" />

  <PanelLayout
    :title="props.account ? props.account.name : 'Konto anlegen'"
    :subline="props.account ? 'Adminkonto ändern' : 'Ein weiterer Mensch für die Verwaltung dieses Servers'"
  >
    <template #breadcrumb>
      <Link href="/accounts" class="link">Konten</Link>
    </template>

    <FormErrors />

    <!--
      **Beide Formulare in einer `.sections`-Hülle.** Als Geschwister standen
      sie auf 0 px Abstand — `main.page` verteilt nicht, und den Abstand
      zwischen Bereichen gibt der jeweilige Behälter an *seine* Kinder. Der
      Knopf „Abbrechen" klebte an der Überschrift des nächsten Bereichs. Die
      Überlaufmessung sah davon nichts (`dokument: 0` in allen vier Lagen);
      gefunden hat es das Bild.
    -->
    <div class="sections">
      <form class="form" @submit.prevent="submit">
        <Section title="Konto">
          <label class="field">
            <span>Name</span>
            <input v-model="form.name" type="text" required :aria-invalid="Boolean(form.errors.name)">
          </label>

          <template v-if="props.account === null">
            <label class="field">
              <span>Anmeldeadresse</span>
              <input v-model="form.email" type="email" autocomplete="off" required :aria-invalid="Boolean(form.errors.email)">
            </label>
          </template>
          <!--
            **Kein `readonly`-Feld für die Adresse, und das ist gemessen.**

            Der erste Wurf zeigte sie wie die Kundennummer in einem
            `<input readonly>`. Bei 390 px lief der Inhalt um 215 px über den
            Rand des Feldes — das Dokument schob dabei `0`, weil ein Eingabefeld
            seinen Inhalt selbst rollt. Der Betreiber konnte die Adresse des
            Kontos, das er gerade ändert, nicht zu Ende lesen.

            Ein Format, das für eine Kundennummer reicht, reicht nicht für eine
            Adresse: `K-1001` hat sechs Zeichen, eine Anmeldeadresse darf 255
            haben. Als Fliesstext mit `.ident` bricht sie.

            > Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur
            > keine Zahl, die sich beschwert.
          -->
          <template v-else>
            <p class="hint">
              Angemeldet wird mit <span class="ident">{{ props.account.email }}</span>.
              Die Adresse lässt sich hier nicht ändern: Sie ist die Anmeldung und
              steht im Protokoll; ihr Wechsel bekommt einen eigenen Weg mit
              Bestätigung.
            </p>
          </template>
        </Section>

        <Section title="Rolle">
          <label class="field">
            <span>Rolle</span>
            <select v-model="form.role" :aria-invalid="Boolean(form.errors.role)">
              <!--
                **Die Auswahl zeigt, was die Prüfung dahinter zulässt.** Ist dies
                der letzte aktive Betreiber, ist „Administrator" hier gesperrt —
                nicht, weil die Oberfläche das entscheidet, sondern weil der
                Server es abweist und ein Knopf, der an einer anderen Frage hängt
                als die Prüfung dahinter, in diesem Projekt schon mehrfach teuer
                war.
              -->
              <option
                v-for="role in props.roles"
                :key="role.value"
                :value="role.value"
                :disabled="props.isLastOperator && role.value !== 'operator'"
              >
                {{ role.label }}
              </option>
            </select>
          </label>

          <p class="hint">
            Ein <b>Betreiber</b> darf alles auf diesem Server — auch die Seiten
            mit Zugangsdaten und die, die Pakete installieren. Ein
            <b>Administrator</b> verwaltet Kunden, Abonnements, Domains und
            Datenbanken und kommt an diese Seiten nicht heran.
          </p>

          <template v-if="props.account !== null">
            <label class="field">
              <span>Zustand</span>
              <select v-model="form.status" :aria-invalid="Boolean(form.errors.status)">
                <option value="active">aktiv</option>
                <option value="disabled" :disabled="props.isLastOperator">deaktiviert</option>
              </select>
            </label>

            <p class="hint">
              Ein deaktiviertes Konto kann sich nicht mehr anmelden. Seine
              Einträge im Protokoll tragen weiterhin seinen Namen — deshalb wird
              gesperrt und nicht gelöscht.
            </p>
          </template>

          <p v-if="props.isLastOperator" class="hint">
            Dies ist der letzte aktive Betreiber. Er lässt sich weder herabstufen
            noch sperren — sonst käme niemand mehr an die Einstellungen dieses
            Servers. Legen Sie zuerst einen zweiten Betreiber an.
          </p>
        </Section>

        <Section v-if="props.account === null" title="Passwort">
          <PasswordFields
            v-model="form.password"
            v-model:confirmation="form.password_confirmation"
            :requirements="policy.requirements"
            :minimum="policy.minimum"
            :error="form.errors.password"
          />
          <p class="hint">
            Das Passwort entsteht im Browser und wird nur beim Anlegen übertragen.
            Es lässt sich danach nicht wieder anzeigen — geben Sie es weiter,
            bevor Sie die Seite verlassen.
          </p>
        </Section>

        <div class="button-row">
          <button type="submit" class="button primary" :disabled="form.processing">
            {{ form.processing ? 'Wird gespeichert …' : props.account ? 'Speichern' : 'Anlegen' }}
          </button>
          <Link href="/accounts" class="button">Abbrechen</Link>
        </div>
      </form>

      <!--
        **Das Zurücksetzen steht in einem eigenen Formular und nicht im Feld
        darüber.** Ein Passwort, das versehentlich mitgeschickt wird, weil es
        im selben Absenden steckt, ist ein Passwortwechsel, den niemand wollte.
      -->
      <form v-if="props.account !== null" class="form" @submit.prevent="submitReset">
        <Section title="Passwort zurücksetzen">
          <p class="hint">
            Setzt ein neues Passwort für dieses Konto. Der zweite Faktor bleibt
            dabei unberührt — wer sich damit ausgesperrt hat, kommt auch mit einem
            neuen Passwort nicht herein; dafür gibt es
            <span class="ident">srvpanel:admin</span> auf dem Server.
          </p>

          <PasswordFields
            v-model="reset.password"
            v-model:confirmation="reset.password_confirmation"
            :requirements="policy.requirements"
            :minimum="policy.minimum"
            :error="reset.errors.password"
            label="Neues Passwort"
          />

          <div class="button-row">
            <button type="submit" class="button" :disabled="reset.processing">
              {{ reset.processing ? 'Wird gesetzt …' : 'Passwort setzen' }}
            </button>
          </div>
        </Section>
      </form>
    </div>
  </PanelLayout>
</template>
