<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import EyeIcon from './EyeIcon.vue'
import Section from './Section.vue'

/*
 * Zugangsdaten für DNS-01 — hinterlegen, ansehen, entfernen.
 *
 * **Eine Komponente für beide Orte.** Der Betreiber trägt seine unter
 * Einstellungen ein, ein Abonnement mit der Freigabe `dns_edit` an seinem
 * Abonnement (`docs/34 §5`). Es sind dieselben Felder und dieselbe Auskunft;
 * zwei Fassungen davon wären genau das Muster, an dem dieses Projekt am
 * häufigsten verloren hat — die zweite ist die, die veraltet.
 *
 * **Was hier bewusst fehlt: das Geheimnis.** Der Agent gibt es nie zurück,
 * weder ganz noch als letzte vier Zeichen — bei einem kurzen Token wäre das
 * ein spürbarer Teil davon. Angezeigt wird, was man ohne das Geheimnis
 * beurteilen kann: Anbieter, Zeitpunkt und die Zonen.
 *
 * **Die Zonen sind der Grund, warum das mehr als ein Formular ist.** Ein
 * TSIG-Schlüssel ist im Nameserver auf Zonen eingegrenzt, und die Liste in den
 * Zugangsdaten ist eine Positivliste: Was nicht daraufsteht, wird gar nicht
 * erst versucht. Fehlt eine, scheitert die Bestellung — und ein Fehlversuch
 * zählt bei Let's Encrypt für jeden Kunden dieses Servers.
 */
interface Credential {
  profile: string
  provider: string
  provider_label: string
  stored_at: number
  zones: string[]
}

const props = defineProps<{
  /** Wohin das Formular schickt — dieselbe Adresse trägt auch das Entfernen. */
  action: string

  /** Der Profilname. Abgeleitet und nicht wählbar; er steht hier nur als Auskunft. */
  profile: string

  credential: Credential | null
  providers: { value: string; label: string; usable: boolean }[]
}>()

const usable = computed(() => props.providers.filter((p) => p.usable))
const pending = computed(() => props.providers.filter((p) => !p.usable))

const form = useForm({
  provider: usable.value[0]?.value ?? '',
  server: '',
  port: '',
  zones: '',
  key_name: '',
  algorithm: '',
  secret: '',
})

const revealed = ref(false)

function submit(): void {
  form.put(props.action, {
    preserveScroll: true,
    onSuccess: () => form.reset('secret'),
  })
}

/*
 * Entfernen wird gefragt, nicht angenommen.
 *
 * Danach bestellt keine Domain dieses Profils mehr über DNS-01, und rückgängig
 * geht es nur, indem jemand den Schlüssel wieder heraussucht.
 */
function forget(): void {
  if (!confirm('Die Zugangsdaten entfernen? Danach wird für diese Zonen nichts mehr über DNS-01 bestellt, bis ein neuer Schlüssel hinterlegt ist.')) {
    return
  }

  router.delete(props.action, { preserveScroll: true })
}

function moment(seconds: number): string {
  return new Date(seconds * 1000).toLocaleString('de-DE', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}
</script>

<template>
  <Section v-if="props.credential" title="Hinterlegt">
    <template #actions>
      <button type="button" class="button danger" @click="forget">Entfernen</button>
    </template>

    <!--
      `table.pairs` und keine Definitionsliste: Die Regel in app.css hängt am
      Element, und ein `<dl class="pairs">` bekäme die Klasse ohne die
      Gestaltung dazu — genau die Sorte Zeichenkette, die auf nichts zeigt.
      Auf schmaler Fläche bricht diese Tabelle in Zeilen um.
    -->
    <table class="pairs">
      <tbody>
        <tr>
          <td class="quiet">Profil</td>
          <td class="right ident">{{ props.credential.profile }}</td>
        </tr>
        <tr>
          <td class="quiet">Anbieter</td>
          <td class="right">{{ props.credential.provider_label }}</td>
        </tr>
        <tr>
          <td class="quiet">Hinterlegt am</td>
          <td class="right">{{ moment(props.credential.stored_at) }}</td>
        </tr>
        <!--
          **Eine Kennung je Zone und `ident` an der Zelle.**

          Bei 390px sind das 305px Überlauf, wenn man es anders macht — hier
          gemessen und nicht geraten. Zwei Regeln greifen ineinander: `td .ident`
          steht auf `nowrap` (in einer Tabelle richtig, man kann sie schieben),
          und `table.pairs td.right` bekommt auf schmaler Fläche `flex: none`,
          behält also seine Inhaltsbreite. Die Ausnahme dagegen — `white-space:
          normal` samt `flex: 1 1 auto` — hängt an `table.pairs td.right.ident`,
          also an der **Zelle**. Eine Liste in einem Span *innerhalb* einer
          gewöhnlichen Zelle fällt durch beide Maschen.

          Deshalb trägt die **Zelle** die Kennung und kein Span darin: Dann
          greift die Ausnahme, die es längst gibt, und die Liste bricht an ihren
          Leerzeichen um — also zwischen den Zonen.

          `ident` steht als Objektschlüssel und nicht in einer Zeichenkette aus
          einer Variablen: So sieht `ClassReachTest`, welche Klasse hier
          entstehen kann. Ohne Zonen bleibt sie weg — eine Meldung in
          Monospace wäre eine Kennung, die keine ist.
        -->
        <tr>
          <td class="quiet">Zonen</td>
          <td class="right" :class="{ ident: props.credential.zones.length > 0 }">
            <template v-if="props.credential.zones.length > 0">{{ props.credential.zones.join(' ') }}</template>
            <span v-else class="quiet">keine — so ändert dieses Profil nichts</span>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="hint">
      Das Geheimnis wird nicht angezeigt, auch nicht in Teilen. Wer prüfen will,
      ob das richtige liegt, hinterlegt es neu.
    </p>
  </Section>

  <Section :title="props.credential ? 'Neu hinterlegen' : 'Zugangsdaten hinterlegen'">
    <p class="section-note">
      Die Angaben gelten für das Profil <span class="ident">{{ props.profile }}</span>.
    </p>

    <form @submit.prevent="submit">
      <label class="field">
        <span>Anbieter</span>
        <select v-model="form.provider">
          <option v-for="p in usable" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
      </label>
      <p v-if="form.errors.provider" class="error">{{ form.errors.provider }}</p>
      <p v-if="pending.length > 0" class="hint">
        Noch nicht verfügbar: {{ pending.map((p) => p.label).join(', ') }}.
      </p>

      <label class="field">
        <span>Nameserver</span>
        <input v-model="form.server" type="text" autocomplete="off" placeholder="ns1.example.de" required>
      </label>
      <p v-if="form.errors.server" class="error">{{ form.errors.server }}</p>
      <p class="hint">Der Server, der die Aktualisierung annimmt — nicht der, der die Zone ausliefert.</p>

      <label class="field">
        <span>Port</span>
        <input v-model="form.port" type="number" inputmode="numeric" placeholder="53">
      </label>
      <p v-if="form.errors.port" class="error">{{ form.errors.port }}</p>

      <label class="field">
        <span>Zonen</span>
        <textarea v-model="form.zones" rows="3" placeholder="example.de&#10;example.net" required />
      </label>
      <p v-if="form.errors.zones" class="error">{{ form.errors.zones }}</p>
      <p class="hint">
        Eine je Zeile. Nur diese Zonen darf das Profil ändern; für einen Namen
        ausserhalb wird gar nicht erst ein Versuch verbraucht.
      </p>

      <label class="field">
        <span>Schlüsselname</span>
        <input v-model="form.key_name" type="text" autocomplete="off" placeholder="srvpanel-acme" required>
      </label>
      <p v-if="form.errors.key_name" class="error">{{ form.errors.key_name }}</p>

      <label class="field">
        <span>Verfahren</span>
        <input v-model="form.algorithm" type="text" autocomplete="off" placeholder="hmac-sha256">
      </label>
      <p v-if="form.errors.algorithm" class="error">{{ form.errors.algorithm }}</p>
      <p class="hint">Leer lassen für hmac-sha256. Zugelassen sind ausserdem hmac-sha384 und hmac-sha512.</p>

      <label class="field">
        <span>Geheimnis</span>
        <span class="with-reveal">
          <input
            v-model="form.secret"
            :type="revealed ? 'text' : 'password'"
            autocomplete="new-password"
            required
          >
          <button
            type="button"
            class="reveal"
            :aria-label="revealed ? 'Geheimnis verbergen' : 'Geheimnis anzeigen'"
            :aria-pressed="revealed"
            @click.prevent="revealed = !revealed"
          >
            <EyeIcon :off="revealed" />
          </button>
        </span>
      </label>
      <p v-if="form.errors.secret" class="error">{{ form.errors.secret }}</p>
      <p class="hint">
        Das Base64 aus der Schlüsseldatei des Nameservers. Es überquert die
        Grenze zum Agenten genau einmal — beim Speichern — und wird danach nie
        wieder herausgegeben.
      </p>

      <div class="button-row">
        <button type="submit" class="button" :disabled="form.processing || usable.length === 0">
          Hinterlegen
        </button>
      </div>
    </form>
  </Section>
</template>
