<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface PhpOption {
  version: string
  selectable: boolean
  reason: string | null
}

const props = defineProps<{
  domain: {
    id: number
    name: string
    type: string
    type_label: string
    status: string
    status_label: string
    pending: boolean
    document_root: string | null
    document_root_path: string | null
    php_version: string | null
    php_settings: Record<string, string>
    nginx_directives: string[]
    redirect_target: string | null
    redirect_kind: string | null
    parent: string | null
    subscription: string | null
    subscription_id: number
    removable: boolean
    log_dir: string | null
    is_redirect: boolean
  }
  php: PhpOption[]
  caps: Record<string, number | null>
  settings: string[]
  directives: string[]
  certificate: {
    id: number
    names: string[]
    issuer: string | null
    source: string
    source_label: string
    trusted: boolean
    not_after: number | null
    renew_after: number | null
    covers_all: boolean
  } | null
  acme: { configured: boolean; staging: boolean }
  choice: {
    pinned: number | null
    overridden: boolean
    options: { id: number; label: string; not_after: number | null }[]
  }
  wildcard: {
    possible: boolean
    obstacle: string | null
    names: string[]
    uncovered: string[]
  }
  can: {
    update: boolean
    update_php: boolean
    delete: boolean
    view_logs: boolean
    upload_certificate: boolean
    order_wildcard: boolean
  }
  operations: { id: number; task: string | null; status_label: string; created_at: string | null }[]
}>()

/*
 * Die Restlaufzeit in Tagen, abgerundet — dieselbe Rechnung wie auf der
 * Zertifikatsseite der Oberfläche. Abgerundet, weil aufgerundet in genau dem
 * Fall schmeichelt, in dem es darauf ankommt.
 */
const tage = computed(() => {
  const bis = props.certificate?.not_after

  return bis ? Math.floor((bis * 1000 - Date.now()) / 86400000) : null
})

function datum(zeit: number | null): string {
  return zeit ? new Date(zeit * 1000).toLocaleDateString('de-DE') : '—'
}

/*
 * Bestellt wird von selbst, sobald der Server-Block steht. Dieser Knopf ist
 * für den Fall danach: Wer den DNS-Eintrag gerade berichtigt hat, will es
 * jetzt versuchen und nicht beim nächsten Anlass, den es womöglich nicht gibt.
 */
function zertifikatBestellen(): void {
  router.post(`/domains/${props.domain.id}/certificate`, { wildcard: alsPlatzhalter.value })
}

/*
 * Der Platzhalter.
 *
 * **Ein Kästchen und keine Automatik.** Ein Platzhalter deckt jede Unterdomain
 * der Zone — auch eine, die einem anderen Abonnement gehört (`docs/34 §3`).
 * Etwas mit dieser Folge passiert nicht als Nebenwirkung eines Knopfdrucks.
 */
// **Nicht `platzhalter`.** Den Namen trägt in dieser Datei schon die
// Hilfsfunktion für die Platzhaltertexte der Felder — dasselbe Wort, zwei
// Bedeutungen. Aufgefallen ist es beim Bauen; im Gegensatz zu PHP meldet der
// Übersetzer hier sofort.
const alsPlatzhalter = ref(false)

/*
 * Die Auswahl.
 *
 * **Sie steht auf dem, was gewählt ist — nicht auf dem, was ausgeliefert
 * wird.** Die beiden fallen auseinander, wenn eine Wahl abgelaufen ist: Dann
 * springt ein anderes ein, und das Feld zeigt trotzdem weiter die Wahl, weil
 * sie es ja ist. Was gerade gilt, steht darüber in den Angaben, und die
 * Meldung dazwischen sagt, dass beides nicht dasselbe ist.
 */
const wahl = useForm({ certificate: props.choice.pinned === null ? '' : String(props.choice.pinned) })

function wahlSpeichern(): void {
  wahl.put(`/domains/${props.domain.id}/certificate`, { preserveScroll: true })
}

/*
 * Das eigene Zertifikat.
 *
 * **Zwei Textfelder und keine Dateiauswahl.** Wer ein Zertifikat gekauft hat,
 * hat es meistens als Text in einer Mail — und wer es als Datei hat, kann sie
 * öffnen und den Inhalt einfügen. Umgekehrt gilt das nicht: Eine Dateiauswahl
 * auf dem Telefon findet den Anhang einer Mail nicht.
 *
 * Der Schlüssel wird nach dem Absenden geleert, auch bei Erfolg. Er hat in
 * einem Formularfeld nichts zu suchen, das jemand offen liegen lässt.
 */
const hochladen = useForm({ certificate: '', private_key: '' })

function zertifikatHochladen(): void {
  hochladen.post(`/domains/${props.domain.id}/certificate/upload`, {
    preserveScroll: true,
    onFinish: () => hochladen.reset('private_key'),
  })
}

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'failed') return 'critical'

  return 'neutral'
}

/*
 * Ein Fehler, der zu keinem Feld gehört.
 *
 * Der Dienst meldet unter `domain`, was die Domain als Ganzes betrifft — ein
 * gesperrtes Abonnement, eine Hauptdomain, die nicht einzeln geht. Er steht
 * nicht in `form.errors`, weil es kein Feld dieses Namens gibt; er kommt aus
 * der Seite.
 */
const allgemein = computed(() => {
  const errors = usePage().props.errors as Record<string, string> | undefined

  return errors?.domain ?? null
})

const form = useForm({
  document_root: props.domain.document_root ?? '',
  php_version: props.domain.php_version ?? '',
  php_settings: { ...props.domain.php_settings },
  nginx_directives: props.domain.nginx_directives.join('\n'),
  redirect_target: props.domain.redirect_target ?? '',
  redirect_kind: props.domain.redirect_kind ?? 'temporary',
})

/*
 * Der Platzhalter nennt die Einheit.
 *
 * „höchstens 64" stand hier zuerst und sagt nicht, wovon — im Browser
 * nachgesehen, und genau daran gestolpert. Sekunden bei der Laufzeit, MB bei
 * allem, was eine Größe ist.
 */
function platzhalter(key: string): string {
  const deckel = props.caps[key]

  if (deckel === null || deckel === undefined) return 'Vorgabe des Servers'

  return `höchstens ${deckel} ${key === 'max_execution_time' ? 'Sekunden' : 'MB'}`
}

function speichern(): void {
  // Die Direktiven kommen als Textfeld und gehen als Liste: Eine leere Zeile
  // wäre für den Agenten eine Direktive ohne Inhalt.
  form
    .transform((data) => ({
      ...data,
      nginx_directives: String(data.nginx_directives)
        .split('\n')
        .map((zeile) => zeile.trim())
        .filter((zeile) => zeile !== ''),
    }))
    .patch(`/domains/${props.domain.id}`)
}

/*
 * Zwei Sätze und der Pfad in der Rückfrage.
 *
 * Das Verzeichnis geht mit — so ist es festgelegt, und es gibt noch keine
 * Sicherungen. Wer den Pfad vor dem Bestätigen liest, entfernt nicht die
 * falsche Domain.
 */
function entfernen(): void {
  const pfad = props.domain.document_root_path
  const frage = pfad === null
    ? `${props.domain.name} entfernen? Server-Block und Protokolle werden gelöscht.`
    : `${props.domain.name} entfernen? Server-Block, Protokolle und das Verzeichnis ${pfad} werden gelöscht. Es gibt keine Sicherung.`

  if (!window.confirm(frage)) return

  router.delete(`/domains/${props.domain.id}`)
}
</script>

<template>
  <Head :title="props.domain.name" />

  <PanelLayout :title="props.domain.name" :subline="props.domain.type_label">
    <template #breadcrumb>
      <Link href="/domains" class="link">Domains</Link> ·
      <Link :href="`/subscriptions/${props.domain.subscription_id}`" class="link">
        {{ props.domain.subscription ?? '—' }}
      </Link>
    </template>

    <template #actions>
      <Badge :kind="rang(props.domain.status)" :running="props.domain.pending">
        {{ props.domain.status_label }}
      </Badge>
      <Link v-if="props.can.view_logs" class="button" :href="`/domains/${props.domain.id}/logs`">
        Protokolle
      </Link>
      <button
        v-if="props.can.delete && props.domain.removable"
        type="button"
        class="button danger"
        :disabled="props.domain.pending"
        @click="entfernen"
      >
        Entfernen
      </button>
    </template>

    <p v-if="props.domain.pending" class="notice warn">
      An dieser Domain läuft gerade ein Vorgang. Bis er durch ist, lässt sich
      nichts ändern — der Zustand folgt dem Server und nicht dem Formular.
    </p>

    <div class="sections">
      <Section title="Stammdaten">
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Abonnement</td>
              <td class="right">
                <Link :href="`/subscriptions/${props.domain.subscription_id}`" class="link">
                  {{ props.domain.subscription ?? '—' }}
                </Link>
              </td>
            </tr>
            <tr v-if="props.domain.parent">
              <td class="quiet">Gehört zu</td>
              <td class="right ident name">{{ props.domain.parent }}</td>
            </tr>
            <tr>
              <td class="quiet">Verzeichnis</td>
              <td class="right ident">{{ props.domain.document_root_path ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">PHP</td>
              <td class="right">
                <template v-if="props.domain.is_redirect">leitet weiter</template>
                <template v-else>{{ props.domain.php_version ?? 'ohne Handler' }}</template>
              </td>
            </tr>
            <tr v-if="props.domain.log_dir">
              <td class="quiet">Protokolle</td>
              <td class="right ident">{{ props.domain.log_dir }}</td>
            </tr>
          </tbody>
        </table>

        <p v-if="!props.domain.removable" class="section-note">
          Die Hauptdomain gehört zum Abonnement und wird mit ihm entfernt.
        </p>
      </Section>

      <!--
        Das Zertifikat steht neben den Stammdaten und nicht in einem eigenen
        Reiter: Es ist die zweite Frage, die jemand an eine Domain hat — läuft
        sie, und läuft sie gesichert?
      -->
      <Section v-if="props.domain.type !== 'alias'" title="Zertifikat">
        <table v-if="props.certificate" class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Art</td>
              <td class="right">
                <Badge :kind="props.certificate.trusted ? 'ok' : 'warn'">
                  {{ props.certificate.source_label }}
                </Badge>
              </td>
            </tr>
            <tr>
              <td class="quiet">Aussteller</td>
              <td class="right ident">{{ props.certificate.issuer ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Gültig bis</td>
              <td class="right">
                {{ datum(props.certificate.not_after) }}
                <template v-if="tage !== null">({{ tage }} Tage)</template>
              </td>
            </tr>
            <tr>
              <td class="quiet">Gilt für</td>
              <td class="right ident">{{ props.certificate.names.join(', ') || '—' }}</td>
            </tr>
          </tbody>
        </table>

        <!--
          Ein Alias, der nach der Ausstellung dazukam, ist genau der Fall, in
          dem der Browser warnt und im Panel alles grün aussieht. Deshalb steht
          hier nicht „hat ein Zertifikat", sondern ob es *alle* Namen deckt,
          unter denen dieser Block antwortet.
        -->
        <p v-if="props.certificate && !props.certificate.covers_all" class="section-note">
          Das Zertifikat deckt nicht alle Namen ab, unter denen diese Domain
          antwortet. Beim nächsten Erneuern kommen sie mit; bis dahin warnt der
          Browser bei den übrigen.
        </p>

        <p v-if="!props.certificate" class="section-note">
          <template v-if="!props.acme.configured">
            Es ist keine Kontaktadresse für Let’s Encrypt eingetragen — ohne sie
            bestellt das Panel nichts, für keine Domain.
          </template>
          <template v-else>
            Noch keines. Bestellt wird von selbst, sobald der Server-Block
            steht; scheitert die Prüfung — falscher DNS-Eintrag, Port 80 zu —,
            hilft der Knopf, nachdem das behoben ist.
          </template>
        </p>

        <!--
          Der laute Rückfall (`docs/34 §8`): Die Wahl gilt gerade nicht, und
          das gehört dahin, wo jemand das Zertifikat ansieht — nicht nur ins
          Protokoll. Ein hochgeladenes erneuert niemand, und stur daran
          festzuhalten nähme die Website vom Netz.
        -->
        <p v-if="props.choice.overridden" class="notice warn">
          <span>
            Das ausgewählte Zertifikat gilt nicht mehr. Ausgeliefert wird
            solange das oben genannte; die Wahl bleibt eingetragen und greift
            wieder, sobald sie gültig ist.
          </span>
        </p>

        <!--
          Der Platzhalter (`docs/34 §3`). Er löst die Wochengrenze — ein
          Abonnement mit vierzig Unterdomains verbraucht sonst vierzig Einträge
          je Woche statt zwei — und kostet die Trennschärfe. Deshalb steht
          neben dem Kästchen, was er deckt, und nicht nur sein Name.
        -->
        <label v-if="props.can.order_wildcard && !props.certificate" class="toggle">
          <input v-model="alsPlatzhalter" type="checkbox" :disabled="!props.wildcard.possible">
          <span>
            Als Platzhalter bestellen
            <small class="hint">
              Ein Zertifikat für <span class="ident">{{ props.wildcard.names.join(' ') }}</span> —
              es gilt für jede Unterdomain dieser Zone, auch für die eines
              anderen Abonnements. Dafür zählt es bei der Wochengrenze als eine
              Bestellung statt als eine je Name.
            </small>
            <small v-if="props.wildcard.obstacle" class="hint">{{ props.wildcard.obstacle }}</small>
          </span>
        </label>

        <!--
          Eine Grenze, die ACME selbst zieht: `*.example.de` deckt
          `a.b.example.de` nicht. Das gehört auf die Seite, statt es als
          Browserwarnung entstehen zu lassen.
        -->
        <p
          v-if="alsPlatzhalter && props.wildcard.uncovered.length > 0"
          class="section-note"
        >
          Eine Ebene tiefer deckt ein Platzhalter nicht. Ohne eigenes Zertifikat
          bleiben:
          <span class="ident">{{ props.wildcard.uncovered.join(' ') }}</span>
        </p>

        <div v-if="props.can.update && !props.certificate" class="button-row">
          <button
            type="button"
            class="button"
            :disabled="!props.acme.configured"
            @click="zertifikatBestellen"
          >
            {{ alsPlatzhalter ? 'Platzhalter bestellen' : 'Zertifikat bestellen' }}
          </button>
        </div>

        <!--
          Die Auswahl erscheint erst, wenn es etwas zu wählen gibt — bei einem
          einzigen Zertifikat wäre ein Feld mit einem Eintrag eine Frage ohne
          Antwortmöglichkeit.
        -->
        <form v-if="props.can.update && props.choice.options.length > 1" @submit.prevent="wahlSpeichern">
          <label class="field">
            <span>Ausgeliefert wird</span>
            <select v-model="wahl.certificate">
              <option value="">Automatisch — das jeweils gültige</option>
              <!--
                Kurz genug für ein Auswahlfeld auf dem Telefon (`docs/24 §8`):
                ein `<select>` bricht nicht um, es schneidet ab. Die gedeckten
                Namen stehen bewusst nicht dabei — jeder Eintrag deckt alle,
                sonst stünde er nicht zur Wahl. Was unterscheidet, ist die
                Herkunft und die Laufzeit.
              -->
              <option v-for="o in props.choice.options" :key="o.id" :value="String(o.id)">
                {{ o.label }} — bis {{ datum(o.not_after) }}
              </option>
            </select>
          </label>
          <p class="hint">
            Ohne Wahl entscheidet die Automatik: Sie nimmt das gültige, das alle
            Namen deckt, und tauscht es nach einer Erneuerung selbst aus. Eine
            Wahl bleibt stehen, bis sie hier zurückgenommen wird.
          </p>

          <div class="button-row">
            <button type="submit" class="button" :disabled="wahl.processing">
              {{ wahl.processing ? 'Wird übernommen …' : 'Übernehmen' }}
            </button>
          </div>
        </form>
      </Section>

      <!--
        Das eigene Zertifikat steht in einem eigenen Bereich und nicht neben
        den Angaben darüber: Dort steht, was gerade gilt — hier wird etwas
        ersetzt. Der Bereich erscheint nur, wenn der Plan die Freigabe gibt;
        gefragt wird dieselbe Policy, die die Route später abweist.
      -->
      <Section
        v-if="props.can.upload_certificate"
        title="Eigenes Zertifikat"
        note="Für ein gekauftes Zertifikat. Ohne Eintrag bleibt es beim automatisch ausgestellten."
      >
        <form @submit.prevent="zertifikatHochladen">
          <label class="field">
            <span>Zertifikat samt Kette (PEM)</span>
            <textarea
              v-model="hochladen.certificate"
              rows="6"
              spellcheck="false"
              placeholder="-----BEGIN CERTIFICATE-----"
              required
            ></textarea>
          </label>
          <p v-if="hochladen.errors.certificate" class="error">{{ hochladen.errors.certificate }}</p>
          <p v-else class="hint">
            Zuerst das eigene, danach die ausstellenden. Die Reihenfolge zählt:
            Eine verkehrte Kette verzeihen manche Browser und Mobilgeräte nicht.
          </p>

          <label class="field">
            <span>Privater Schlüssel (PEM)</span>
            <textarea
              v-model="hochladen.private_key"
              rows="6"
              spellcheck="false"
              placeholder="-----BEGIN PRIVATE KEY-----"
              required
            ></textarea>
          </label>
          <p v-if="hochladen.errors.private_key" class="error">{{ hochladen.errors.private_key }}</p>
          <p v-else class="hint">
            Ohne Passwort — nginx fragt beim Start danach, und niemand ist da,
            um es einzutippen. Er wird nach dem Absenden geleert.
          </p>

          <div class="button-row">
            <button type="submit" class="button primary" :disabled="hochladen.processing">
              {{ hochladen.processing ? 'Wird geprüft …' : 'Hinterlegen' }}
            </button>
          </div>
        </form>
      </Section>

      <Section v-if="props.operations.length > 0" title="Vorgänge">
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Nummer</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
            </thead>
            <tbody>
              <tr v-for="op in props.operations" :key="op.id">
                <td data-column="Nummer" class="ident">
                  <Link :href="`/operations/${op.id}`" class="link">{{ op.id }}</Link>
                </td>
                <td data-column="Aufgabe" class="ident name">{{ op.task }}</td>
                <td data-column="Zustand" class="quiet">{{ op.status_label }}</td>
                <td data-column="Angelegt" class="quiet">{{ op.created_at ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>

    <form v-if="props.can.update" @submit.prevent="speichern">
      <div class="sections form-top">
        <Section title="Verzeichnis und Handler">
          <!--
            Das `<fieldset>` bleibt, obwohl es keinen Rahmen mehr trägt: Es
            schaltet mit einem Attribut jedes Feld darin ab, solange ein Vorgang
            läuft. Die Gliederung macht der Bereich, die Sperre das Fieldset —
            zwei Aufgaben, die vorher ein Element hatte.
          -->
          <fieldset :disabled="props.domain.pending">
            <label v-if="props.domain.type !== 'alias'" class="field">
              <span>Verzeichnis</span>
              <input v-model="form.document_root" type="text" autocomplete="off">
            </label>
            <p v-if="form.errors.document_root" class="error">{{ form.errors.document_root }}</p>
            <p v-if="props.domain.type !== 'alias'" class="hint">
              Relativ zum Abonnement, ohne führenden Schrägstrich.
            </p>

            <label v-if="props.domain.type !== 'alias'" class="field">
              <span>PHP-Version</span>
              <select v-model="form.php_version">
                <option value="">ohne Handler — nur statische Dateien</option>
                <option
                  v-for="option in props.php"
                  :key="option.version"
                  :value="option.version"
                  :disabled="!option.selectable"
                >
                  {{ option.version }}<template v-if="option.reason"> · {{ option.reason }}</template>
                </option>
              </select>
            </label>
            <p v-if="form.errors.php_version" class="error">{{ form.errors.php_version }}</p>

            <label class="field">
              <span>Weiterleitung</span>
              <input v-model="form.redirect_target" type="url" placeholder="leer = keine" autocomplete="off">
            </label>
            <p v-if="form.errors.redirect_target" class="error">{{ form.errors.redirect_target }}</p>

            <label v-if="form.redirect_target !== ''" class="field">
              <span>Art der Weiterleitung</span>
              <select v-model="form.redirect_kind">
                <option value="temporary">vorübergehend (302)</option>
                <option value="permanent">dauerhaft (301)</option>
              </select>
            </label>
          </fieldset>
        </Section>

        <!--
          Die PHP-Einstellungen stehen nur da, wenn der Plan sie freigibt und
          das Konto sie ändern darf. Ein abgeblendetes Feld wäre die Auskunft
          „das gibt es, du darfst nur nicht" — richtig, aber hier ohne Nutzen:
          Wer sie braucht, wendet sich an den Betreiber.
        -->
        <Section
          v-if="props.can.update_php && props.domain.type !== 'alias'"
          title="PHP-Einstellungen dieser Domain"
        >
          <fieldset :disabled="props.domain.pending">
            <label v-for="key in props.settings" :key="key" class="field">
              <span class="ident">{{ key }}</span>
              <input
                v-model="form.php_settings[key]"
                type="text"
                autocomplete="off"
                :placeholder="platzhalter(key)"
              >
            </label>
          </fieldset>

          <p v-if="form.errors.php_settings" class="error">{{ form.errors.php_settings }}</p>
          <p class="hint">
            Leer lassen heißt: Vorgabe des Servers. Die Grenzen kommen aus dem
            Plan; <span class="ident">open_basedir</span> und die Abschottung
            stehen im Pool und lassen sich hier nicht ändern.
          </p>
        </Section>

        <Section title="Eigene nginx-Direktiven" full>
          <fieldset :disabled="props.domain.pending">
            <label class="field">
              <span>Eine je Zeile</span>
              <textarea v-model="form.nginx_directives" rows="4" spellcheck="false" class="ident-field" />
            </label>
          </fieldset>

          <p v-if="form.errors.nginx_directives" class="error">{{ form.errors.nginx_directives }}</p>
          <p class="hint">
            Erlaubt sind: {{ props.directives.join(', ') }}. Keine Blöcke, ein
            Semikolon am Ende. Was einen Pfad oder einen Empfänger bestimmt,
            steht nicht darauf.
          </p>
        </Section>
      </div>

      <p v-if="allgemein" class="error">{{ allgemein }}</p>

      <div class="button-row footer-row">
        <button type="submit" class="button primary" :disabled="form.processing || props.domain.pending">
          {{ form.processing ? 'wird übernommen …' : 'Übernehmen' }}
        </button>
      </div>
    </form>
  </PanelLayout>
</template>

<style scoped>
/*
 * Das Fieldset trägt nur noch seine Aufgabe und kein Aussehen: Es schaltet die
 * Felder darin ab. Ohne diese Zeilen brächte der Browser seinen eigenen Rahmen
 * mit — den einzigen im ganzen Panel.
 */
fieldset {
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}

.form-top {
  margin-top: var(--block-gap);
}

.ident-field {
  font-family: var(--font-mono);
}

.footer-row {
  margin-top: var(--block-gap);
}
</style>
