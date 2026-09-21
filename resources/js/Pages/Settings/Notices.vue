<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Badge from '../../Components/Badge.vue'
import EyeIcon from '../../Components/EyeIcon.vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { useConfirmation } from '../../Composables/useConfirmation'

interface Kanal {
  key: string
  usable: boolean
  delivered_at: string | null
}

const props = defineProps<{
  channels: Kanal[]

  /*
   * Das Meldeziel, wie der Agent es beschreibt — **ohne die Adresse**.
   *
   * Der Grund steht im Kopf von `SrvPanel\Agent\Notify\Target`: Bei Slack,
   * Discord und den meisten Eingangshaken berechtigt die Adresse *allein* zur
   * Zustellung. Sie steht deshalb in keiner Antwort, auch nicht auf dieser
   * Seite, die nur der Betreiber sieht — ein Bildschirmfoto reicht sonst aus.
   */
  target: { host: string, provider: string, stored_at: string | null, signed: boolean } | null

  /*
   * Antwortet der Agent?
   *
   * **Der dritte Zustand, und ohne ihn behauptet die Seite etwas, das sie
   * nicht weiss.** „Kein Meldeziel" und „nicht feststellbar" sähen sonst gleich
   * aus — und der Betreiber trüge ein zweites ein, während das erste meldet.
   */
  live: boolean

  secret_min: number

  /*
   * Die Empfänger kommen aus der Positivliste des Agenten und nicht aus einer
   * zweiten Aufzählung hier — `SrvPanel\Agent\Notify\Providers`.
   */
  providers: { value: string, label: string, signs: boolean, hint: string | null, fields: string[] }[]
}>()

const { ask } = useConfirmation()

/**
 * Wie ein Kanal auf dieser Seite heisst — und was er tut.
 *
 * **Die Schlüssel sind die der Umsetzungen** (`App\Support\Notify\Channels`),
 * und `ChannelReachTest` hält beide Richtungen aneinander: Jeder Kanal, den
 * diese Seite anbietet, hat eine Umsetzung, und jede Umsetzung steht hier.
 * Ohne die zweite Richtung entstünde der tote Eintrag, der wirklich vorkommt —
 * jemand baut einen Kanal, der Nachtlauf bedient ihn, und die Seite bietet ihn
 * nie an.
 *
 * **Die Beschriftung steht hier und nicht im Controller**, weil sie ein Text
 * der Oberfläche ist: Der Schlüssel ist ein Bezeichner und englisch, der Name
 * ist deutsch (`docs/19 §4a`).
 */
const KANAELE: Record<string, { name: string, satz: string }> = {
  mail: {
    name: 'Mailversand',
    satz: 'Kontingente an den Kunden, alles Übrige an den Betreiber.',
  },
  webhook: {
    name: 'Meldeziel (Webhook)',
    satz: 'Jeder Befund an eine Adresse dieses Servers, je Gegenstand einer.',
  },
}

const form = useForm({ url: '', secret: '', provider: 'generic', chat_id: '' })

/*
 * Trägt der gewählte Empfänger eine Signatur?
 *
 * **Slack und Discord lesen unsere Kopfzeile nicht** — dort ist die Adresse
 * das Zugangsmittel. Das Feld steht deshalb nicht bloss wirkungslos da,
 * sondern gar nicht: Ein Feld, das man ausfüllen kann und das nichts tut, ist
 * eine Zusage, die niemand einlöst.
 */
const signiert = computed((): boolean =>
  props.providers.find((p) => p.value === form.provider)?.signs ?? false,
)

/*
 * Was neben der Liste stehen muss, damit jemand den richtigen Eintrag findet.
 *
 * **Gezeigt wird das, bevor jemand wählt, und nicht danach.** Wer
 * „Mattermost" sucht, findet es in der Liste nicht und geht — der Hinweis
 * eines ausgewählten Eintrags erreicht ihn nie.
 *
 * **Und die Sätze kommen aus der Positivliste des Agenten**, nicht aus dieser
 * Datei: Ein Satz hier wäre die zweite Fassung von `Notify\Providers::HINTS`,
 * und sie bliebe stehen, wenn der Eintrag verschwindet.
 */
const hinweise = computed((): string =>
  props.providers.map((p) => p.hint).filter((h): h is string => h !== null).join(' '),
)

/*
 * Welche Felder der gewählte Empfänger ausser Adresse und Geheimnis braucht.
 *
 * **Die Liste kommt aus dem Agenten und nicht aus dieser Datei.** Telegram ist
 * der erste mit einem zweiten Feld; eine Bedingung auf `form.provider ===
 * 'telegram'` wäre die zweite Fassung von `Notify\Providers::FIELDS`, und sie
 * bliebe stehen, wenn dort etwas dazukommt.
 */
const felder = computed((): string[] =>
  props.providers.find((p) => p.value === form.provider)?.fields ?? [],
)

const zeigen = ref(false)

/*
 * Der Zustand eines Kanals als Wort — und **drei** Zustände beim Webhook.
 *
 * Ein schweigender Agent heisst nicht „nicht eingerichtet". Dieselbe
 * Unterscheidung, die `props.live` für den Bereich darunter trifft; sie hier
 * fallenzulassen liesse die Tabelle behaupten, was die Notiz daneben gerade
 * verneint.
 */
function zustand(kanal: Kanal): { wort: string, rang: 'ok' | 'warn' | 'neutral' } {
  if (kanal.key === 'webhook' && !props.live) return { wort: 'nicht feststellbar', rang: 'neutral' }

  return kanal.usable
    ? { wort: 'eingerichtet', rang: 'ok' }
    : { wort: 'nicht eingerichtet', rang: 'warn' }
}

const hinterlegt = computed((): boolean => props.live && props.target !== null)

function submit(): void {
  // Ein Geheimnis, das der Empfänger nicht prüft, geht gar nicht erst hinaus —
  // der Agent wiese es ab, und die Meldung stünde am falschen Feld.
  if (!signiert.value) form.secret = ''

  form.put('/settings/notices', { onSuccess: () => form.reset() })
}

function probe(): void {
  router.post('/settings/notices/test', {}, { preserveScroll: true })
}

/*
 * Entfernen wird gefragt, nicht angenommen.
 *
 * Danach meldet dieser Server nichts mehr nach draussen, und rückgängig geht es
 * nur, indem jemand die Adresse wieder heraussucht — die Seite zeigt sie nicht.
 */
function forget(): void {
  ask(
    'Das Meldeziel entfernen? Danach geht keine Meldung dieses Servers mehr dorthin, '
    + 'und die Adresse steht nirgends mehr — auch nicht auf dieser Seite.',
    'Entfernen',
    () => { router.delete('/settings/notices', { preserveScroll: true }) },
  )
}
</script>

<template>
  <Head title="Benachrichtigungen" />

  <PanelLayout title="Benachrichtigungen" subline="Wie dieser Server meldet, was er gefunden hat">
    <!--
      **„Zuletzt erfolgreich zugestellt" ist der Grund für diese Tabelle.**

      > **Ein Kanal, der schweigt, ist von einem, der nichts zu melden hat,
      > nicht zu unterscheiden.** (`docs/80`)

      Die Probezustellung steht bewusst nicht dahinter — sie belegt die Leitung
      und nicht die Zustellung einer Meldung.
    -->
    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Kanal</th>
            <th>Zustand</th>
            <th>Zuletzt erfolgreich zugestellt</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="kanal in props.channels" :key="kanal.key">
            <td data-column="Kanal" class="multiline">
              <span class="name">{{ KANAELE[kanal.key]?.name ?? kanal.key }}</span>
              <span class="quiet">{{ KANAELE[kanal.key]?.satz }}</span>
            </td>
            <td data-column="Zustand">
              <Badge :kind="zustand(kanal).rang">{{ zustand(kanal).wort }}</Badge>
            </td>
            <td data-column="Zuletzt erfolgreich zugestellt" class="quiet">
              {{ kanal.delivered_at ?? 'noch nie' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <FormErrors />

    <!--
      **Wo nichts feststeht, steht keine Tabelle.** Dieselbe Regel wie auf
      `/services` und `/schedules`: Bei schweigendem Agenten sagt die Notiz es,
      und darunter steht kein Bereich mit leeren Zeilen, der wie „da ist nichts"
      aussieht.

      `v-else` und nicht eine zweite Bedingung — ein `v-else` kann nicht
      auseinanderlaufen.
    -->
    <p v-if="!props.live" class="notice warn">
      <span>
        Der Agent antwortet nicht. Ob ein Meldeziel hinterlegt ist, lässt sich
        deshalb nicht feststellen — und hinterlegen lässt sich gerade keines.
      </span>
    </p>

    <div v-else-if="hinterlegt && props.target" class="sections">
      <Section title="Hinterlegt">
        <template #actions>
          <button type="button" class="button" @click="probe">Probezustellung</button>
          <button type="button" class="button danger" @click="forget">Entfernen</button>
        </template>

        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Rechner</td>
              <td class="right ident">{{ props.target.host }}</td>
            </tr>
            <tr>
              <td class="quiet">Empfänger</td>
              <td class="right">{{ props.target.provider }}</td>
            </tr>
            <tr>
              <td class="quiet">Hinterlegt am</td>
              <td class="right">{{ props.target.stored_at ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Signiert</td>
              <td class="right">{{ props.target.signed ? 'ja' : 'nein' }}</td>
            </tr>
          </tbody>
        </table>

        <!--
          **Die Adresse steht hier nicht, und das ist kein Versehen.** Bei den
          meisten Eingangshaken berechtigt sie allein zur Zustellung; der
          Rechnername beantwortet die einzige Frage, die man an ein hinterlegtes
          Ziel hat — welches ist es.
        -->
        <p class="quiet">
          Die vollständige Adresse bleibt im Agenten. Wer sie ändern will, trägt
          sie unten neu ein — das überschreibt die hinterlegte.
        </p>
      </Section>
    </div>

    <p v-else class="notice warn">
      <span>
        Noch kein Meldeziel hinterlegt. Bis dahin geht über diesen Kanal nichts
        hinaus; was der Nachtlauf findet, steht nur auf der Diagnoseseite.
      </span>
    </p>

    <form v-if="props.live" class="form" @submit.prevent="submit">
      <Section title="Meldeziel">
        <label class="field">
          <span>Empfänger</span>
          <select v-model="form.provider" :aria-invalid="Boolean(form.errors.provider)">
            <option v-for="anbieter in props.providers" :key="anbieter.value" :value="anbieter.value">
              {{ anbieter.label }}
            </option>
          </select>
          <small class="quiet">
            Er entscheidet die Form des Rumpfes; jeder Empfänger nimmt nur
            seine eigene an. {{ hinweise }}
          </small>
        </label>

        <label class="field">
          <span>Adresse</span>
          <input
            v-model="form.url"
            type="url"
            autocomplete="off"
            placeholder="https://hooks.example.net/dienste/…"
            required
            :aria-invalid="Boolean(form.errors.url)"
          >
          <small class="quiet">
            Nur https. Eine Adresse ohne TLS weist der Agent ab, bevor er sie
            wählt — und eine ins eigene Netz gehört gar nicht erst hierher.
          </small>
        </label>

        <label v-if="felder.includes('chat_id')" class="field">
          <span>Chat</span>
          <input
            v-model="form.chat_id"
            type="text"
            autocomplete="off"
            placeholder="-1001234567890"
            required
            :aria-invalid="Boolean(form.errors.chat_id)"
          >
          <small class="quiet">
            Die Kennung des Chats, in den der Bot schreiben soll — eine Zahl
            oder ein Name mit vorangestelltem Klammeraffen. Sie steht nicht in
            der Adresse: Die trägt die Marke des Bots, und derselbe Bot
            schreibt in viele Chats.
          </small>
        </label>

        <label v-if="signiert" class="field">
          <span>Geheimnis zum Signieren</span>
          <span class="with-reveal">
            <input
              v-model="form.secret"
              :type="zeigen ? 'text' : 'password'"
              autocomplete="new-password"
              :aria-invalid="Boolean(form.errors.secret)"
            >
            <button
              type="button"
              class="reveal"
              :aria-label="zeigen ? 'Geheimnis verbergen' : 'Geheimnis anzeigen'"
              :aria-pressed="zeigen"
              @click.prevent="zeigen = !zeigen"
            >
              <EyeIcon :off="zeigen" />
            </button>
          </span>
          <small class="quiet">
            Freiwillig; die Mindestlänge beträgt {{ props.secret_min }}.
            Jede Meldung trägt damit eine Kopfzeile, aus der der Empfänger
            nachrechnen kann, dass sie von hier stammt und von heute ist. Viele
            Eingangshaken brauchen keines — dort berechtigt die Adresse allein
            zur Zustellung, und genau deshalb zeigt diese Seite sie nicht.
          </small>
        </label>

        <div class="button-row">
          <button type="submit" class="button primary" :disabled="form.processing">Hinterlegen</button>
        </div>
      </Section>
    </form>
  </PanelLayout>
</template>
