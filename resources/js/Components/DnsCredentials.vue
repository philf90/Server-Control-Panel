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

/*
 * Ein Formular für alle Anbieter, aber nicht alle Felder für alle.
 *
 * Die Schlüssel stehen hier wörtlich, weil das Markup sie zum Umschalten
 * braucht. Dass es sie beim Agenten gibt, prüft `DnsProviderReachTest` — eine
 * Zeichenkette, die auf einen Anbieter zeigt, den es nicht gibt, wäre genau
 * der Fehler, gegen den dieses Projekt seine Wächter stellt.
 */
const RFC2136 = 'rfc2136'
const IPV64 = 'ipv64'
const HETZNER = 'hetzner'
const CLOUDFLARE = 'cloudflare'
const NETCUP = 'netcup'
const IONOS = 'ionos'

/*
 * Wer seine Zonen selbst führt, bekommt hier kein Feld dafür.
 *
 * Bei RFC 2136 stehen die Zonen in den Zugangsdaten, weil ein TSIG-Schlüssel im
 * Nameserver ohnehin auf Zonen eingegrenzt ist; bei netcup, weil die
 * Schnittstelle die Domains eines Kontos nicht nennt. Alle anderen kennen ihre
 * Zonen selbst — ein zweites, von Hand gepflegtes Verzeichnis daneben wäre
 * dieselbe Auskunft ein zweites Mal, und die zweite ist die, die veraltet.
 *
 * **Die zwei stehen als Liste da und nicht als Ausnahme von einem.** Vorher
 * hiess die Bedingung „alles ausser RFC 2136", und netcup wäre stillschweigend
 * auf der falschen Seite gelandet: Die Auskunft hätte „vom Anbieter" gesagt,
 * wo der Betreiber die Zonen selbst eingetragen hat.
 */
const carriesZones = [RFC2136, NETCUP]

const asked = computed(
  () => props.credential !== null && !carriesZones.includes(props.credential.provider),
)

const form = useForm({
  provider: usable.value[0]?.value ?? '',
  token: '',
  api_key: '',
  customer_number: '',
  api_password: '',
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
    onSuccess: () => form.reset('secret', 'token', 'api_key', 'api_password'),
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

          **Und in dieser Zelle steht nur der Wert, nie ein Satz.** Beim ersten
          Anlauf stand hier „aus dem Konto bei IPv64.net — dieses Profil ändert,
          was dort geführt wird", und das waren bei 390px 128px Überlauf:
          `td.right` steht auf `nowrap` und `flex: none`, ein Satz darin wird
          also weder umbrochen noch zusammengedrückt. Die Ausnahme dagegen hängt
          an `ident`, und einen Satz in Monospace zu setzen, damit er umbricht,
          wäre die falsche Antwort auf die richtige Beobachtung. Sätze stehen
          unter der Tabelle.
        -->
        <tr>
          <td class="quiet">Zonen</td>
          <td class="right" :class="{ ident: props.credential.zones.length > 0 }">
            <template v-if="props.credential.zones.length > 0">{{ props.credential.zones.join(' ') }}</template>
            <template v-else-if="asked">vom Anbieter</template>
            <template v-else>keine</template>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="asked && props.credential.zones.length === 0" class="hint">
      Die Zonen führt der Anbieter selbst; hier werden sie nicht eingetragen.
      Dieses Profil ändert, was dort steht.
    </p>
    <p v-else-if="props.credential.zones.length === 0" class="hint">
      Ohne eine Zone ändert dieses Profil nichts.
    </p>

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

      <!--
        Ab hier hängen die Felder am Anbieter. Ein Anbieter mit Token braucht
        keinen Nameserver, und ein Formular, das ihn trotzdem verlangt, weist
        eine richtige Eingabe ab. Geprüft wird derselbe Satz Felder auf der
        Serverseite — `DnsCredentialInput` verzweigt an derselben Stelle.
      -->
      <template v-if="form.provider === IPV64 || form.provider === HETZNER || form.provider === CLOUDFLARE">
        <label class="field">
          <span>Token</span>
          <span class="with-reveal">
            <input
              v-model="form.token"
              :type="revealed ? 'text' : 'password'"
              autocomplete="new-password"
              required
            >
            <button
              type="button"
              class="reveal"
              :aria-label="revealed ? 'Token verbergen' : 'Token anzeigen'"
              :aria-pressed="revealed"
              @click.prevent="revealed = !revealed"
            >
              <EyeIcon :off="revealed" />
            </button>
          </span>
        </label>
        <p v-if="form.errors.token" class="error">{{ form.errors.token }}</p>
        <p v-if="form.provider === IPV64" class="hint">
          Aus dem Konto bei IPv64.net. Die Zonen kommen von dort und werden
          nicht hier eingetragen — der Anbieter führt sie selbst, und bei ihm
          ist eine Zone häufig schon eine Unterdomain.
        </p>
        <!--
          **Hetzner führt zwei Schnittstellen für dasselbe**, und ein Token der
          einen gilt bei der anderen nicht. Auseinanderhalten lassen sie sich an
          ihrer Form nicht, also steht der Satz hier — vor der Eingabe und nicht
          erst in der Abweisung, die eine Erneuerung nachts scheitern lässt.
        -->
        <p v-if="form.provider === HETZNER" class="hint">
          Ein Token aus der Cloud-Konsole von Hetzner. Ein Token der älteren
          DNS-Konsole gilt hier nicht. Die Zonen kommen aus dem Projekt und
          werden nicht hier eingetragen.
        </p>
        <!--
          **Der globale API-Schlüssel wird hier nicht angeboten.** Er öffnet das
          ganze Cloudflare-Konto; ein Token lässt sich auf zwei Rechte und auf
          einzelne Zonen eingrenzen. Ein Feld, das es nicht gibt, wird nicht
          ausgefüllt — ein Rat im Kleingedruckten schon.
        -->
        <p v-if="form.provider === CLOUDFLARE" class="hint">
          Ein API-Token mit den Rechten Zone:Read und DNS:Edit. Der globale
          API-Schlüssel wird nicht angenommen: Er öffnet das ganze Konto, ein
          Token nur die Zonen, für die es ausgestellt ist.
        </p>
      </template>

      <!--
        IONOS: ein Feld, und das ist der Punkt. Der Schlüssel besteht aus zwei
        Teilen, die IONOS getrennt anzeigt — wer nur den Präfix einträgt,
        bekommt sonst nachts eine Abweisung, die von einem ungültigen Schlüssel
        spricht.
      -->
      <template v-if="form.provider === IONOS">
        <label class="field">
          <span>API-Schlüssel</span>
          <span class="with-reveal">
            <input
              v-model="form.api_key"
              :type="revealed ? 'text' : 'password'"
              autocomplete="new-password"
              required
            >
            <button
              type="button"
              class="reveal"
              :aria-label="revealed ? 'Schlüssel verbergen' : 'Schlüssel anzeigen'"
              :aria-pressed="revealed"
              @click.prevent="revealed = !revealed"
            >
              <EyeIcon :off="revealed" />
            </button>
          </span>
        </label>
        <p v-if="form.errors.api_key" class="error">{{ form.errors.api_key }}</p>
        <p class="hint">
          Beide Teile zusammen, verbunden mit einem Punkt: erst der Präfix, dann
          das Geheimnis. IONOS zeigt sie getrennt an; der Präfix allein wird
          nicht angenommen.
        </p>
      </template>

      <!--
        netcup ist der einzige ohne Token: Kundennummer, zwei Geheimnisse und
        die Zonen. Die Zonen stehen hier, weil die Schnittstelle die Domains
        eines Kontos nicht selbst nennt — dieselbe Antwort wie bei RFC 2136 und
        aus demselben Grund.
      -->
      <template v-if="form.provider === NETCUP">
        <label class="field">
          <span>Kundennummer</span>
          <input v-model="form.customer_number" type="text" inputmode="numeric" autocomplete="off" required>
        </label>
        <p v-if="form.errors.customer_number" class="error">{{ form.errors.customer_number }}</p>

        <label class="field">
          <span>API-Schlüssel</span>
          <span class="with-reveal">
            <input
              v-model="form.api_key"
              :type="revealed ? 'text' : 'password'"
              autocomplete="new-password"
              required
            >
            <button
              type="button"
              class="reveal"
              :aria-label="revealed ? 'Angaben verbergen' : 'Angaben anzeigen'"
              :aria-pressed="revealed"
              @click.prevent="revealed = !revealed"
            >
              <EyeIcon :off="revealed" />
            </button>
          </span>
        </label>
        <p v-if="form.errors.api_key" class="error">{{ form.errors.api_key }}</p>

        <label class="field">
          <span>API-Passwort</span>
          <span class="with-reveal">
            <input
              v-model="form.api_password"
              :type="revealed ? 'text' : 'password'"
              autocomplete="new-password"
              required
            >
            <button
              type="button"
              class="reveal"
              :aria-label="revealed ? 'Angaben verbergen' : 'Angaben anzeigen'"
              :aria-pressed="revealed"
              @click.prevent="revealed = !revealed"
            >
              <EyeIcon :off="revealed" />
            </button>
          </span>
        </label>
        <p v-if="form.errors.api_password" class="error">{{ form.errors.api_password }}</p>
        <p class="hint">
          Beide stehen im Kundenkonto von netcup unter den API-Zugängen. Sie
          überqueren die Grenze zum Agenten genau einmal — beim Speichern — und
          werden danach nie wieder herausgegeben.
        </p>

        <label class="field">
          <span>Zonen</span>
          <textarea v-model="form.zones" rows="3" placeholder="example.de&#10;example.net" required />
        </label>
        <p v-if="form.errors.zones" class="error">{{ form.errors.zones }}</p>
        <p class="hint">
          Eine je Zeile. Die Schnittstelle von netcup nennt die Domains eines
          Kontos nicht selbst, deshalb stehen sie hier — und nur diese Zonen
          darf das Profil ändern.
        </p>
      </template>

      <template v-if="form.provider === RFC2136">
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
      </template>

      <div class="button-row">
        <button type="submit" class="button" :disabled="form.processing || usable.length === 0">
          Hinterlegen
        </button>
      </div>
    </form>
  </Section>
</template>
