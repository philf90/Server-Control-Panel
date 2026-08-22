<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import Bar from '../../Components/Bar.vue'
import DnsCredentials from '../../Components/DnsCredentials.vue'
import FormErrors from '../../Components/FormErrors.vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { useConfirmation } from '../../Composables/useConfirmation'

const { ask } = useConfirmation()

const props = defineProps<{
  subscription: {
    id: number
    name: string
    customer: string | null
    customer_id: number
    plan: string | null
    system_user: string | null
    root: string
    status: string
    status_label: string
    suspended_at: string | null
  }
  usage: {
    used_mb: number | null
    limit_mb: number | null
    percent: number | null
    measured_at: string | null

    /* Drei Werte: `null` heisst „nicht nachgesehen" und ist weder ja noch
       nein — dieselbe Form wie `handed_over` und der Kernel. */
    enforced: boolean | null
    note: string | null
  }
  database_usage: {
    count: number
    used_mb: number | null
    limit_mb: number | null
    percent: number | null

    /* Immer `false` in P5 — siehe den Bereich unten und docs/36 §9. Als Feld
       und nicht als fester Text, damit die Oberfläche nicht umgeschrieben
       werden muss, falls P9 daraus eine Grenze macht. */
    enforced: boolean
  }
  quotas: { key: string; label: string; value: string; differs: boolean }[]
  features: { label: string; granted: boolean }[]
  domains: {
    id: number
    name: string
    type_label: string
    status: string
    status_label: string
    php_version: string | null
    is_redirect: boolean

    /**
     * Der DNS-Abgleich, auf eine Marke zusammengezogen — Rang und Wortlaut
     * kommen vom Server (`DnsHealth`) und werden hier nicht abgeleitet.
     */
    dns: string
    dns_label: string
    dns_badge: 'ok' | 'warn' | 'critical' | 'neutral'
  }[]
  /**
   * Was der Betrachter an diesem Abonnement tun darf — vom Server entschieden.
   *
   * **Hier stand `mayAddDomain` allein.** Richtig gedacht und nur für eine der
   * sechs Aktionen dieser Seite gemacht: Bearbeiten, Sperren, Entsperren und
   * Zurückbauen standen ungefragt da, und ein Kunde bekam auf jeden Klick
   * einen 403. Eine Form für dieselbe Sache, damit `AbilityReachTest` sie
   * gegenprüfen kann.
   */
  can: {
    update: boolean
    suspend: boolean
    delete: boolean
    addDomain: boolean
    viewCustomer: boolean
    manageDns: boolean
    browseFiles: boolean
    manageSftp: boolean
  }

  /**
   * Die eigenen DNS-Zugangsdaten — `null`, wenn es keine geben kann.
   *
   * Der Server entscheidet das und nicht die Seite: Ohne die Freigabe
   * `dns_edit` im Plan gilt das Profil des Betreibers, und dann gibt es hier
   * nichts zu hinterlegen. Ein `v-if` auf den Kontotyp wäre eine zweite
   * Fassung der Policy — und die zweite ist die, die veraltet.
   */
  dns: {
    profile: string
    credential: {
      profile: string
      provider: string
      provider_label: string
      stored_at: number
      zones: string[]
    } | null
    providers: { value: string; label: string; usable: boolean; reason: string | null }[]
  } | null
  operations: { id: number; task: string | null; status_label: string; created_at: string | null }[]
}>()

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended' || status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'cancelled' || status === 'failed') return 'critical'

  return 'neutral'
}

function suspend(): void {
  ask(
    `${props.subscription.name} sperren? Webseiten und Zugänge sind danach aus, die Daten bleiben.`,
    'Sperren',
    () => { router.post(`/subscriptions/${props.subscription.id}/suspend`) },
    // Umkehrbar — der Satz der Frage sagt es selbst: „die Daten bleiben".
    false,
  )
}

function resume(): void {
  router.post(`/subscriptions/${props.subscription.id}/resume`)
}

/*
 * **Ohne Rückfrage.** Sie setzt dieselbe Grenze noch einmal, die schon
 * dasteht — es gibt nichts zu verlieren und nichts zu bestätigen. Die
 * Rückfragen daneben hängen an Verlust (Sperren, Zurückbauen).
 */
function reapplyQuota(): void {
  router.post(`/subscriptions/${props.subscription.id}/quota`)
}

/*
 * Die Grenze gilt nachweislich nicht — der Agent hat es gemeldet.
 */
const quotaBroken = computed(() => props.usage.enforced === false)

/**
 * Über die Grenze ist nichts bekannt, und es gibt eine.
 *
 * **Der Fall, den die erste Fassung übersehen hat.** `disk_quota_enforced` kam
 * am 10. August 2026 ohne Backfill dazu; jedes Abonnement von davor steht auf
 * `null`. Der Knopf hing an `=== false` — und fehlte damit ausgerechnet den
 * beiden Abonnements auf `cloudsrv24`, für die er gebaut worden war. Ihre
 * Grenze liess sich nur anwenden, indem man sie *änderte*.
 *
 * > **Ein Knopf, der an einer Messung hängt, fehlt dort, wo nie gemessen
 * > wurde.**
 *
 * `limit_mb` gehört in die Bedingung: Ohne Grenze ist die Frage, ob sie gilt,
 * gegenstandslos, und ein Knopf dafür wäre eine Handlung ohne Gegenstand.
 */
const quotaUnknown = computed(() => props.usage.enforced === null && props.usage.limit_mb !== null)

/*
 * **Beide Zustände führen zum selben Knopf, aber nicht zum selben Satz.**
 * „Gilt nicht" ist ein Befund und wird gewarnt; „nicht nachgesehen" ist eine
 * Auskunft und bleibt nüchtern. Ein Abonnement aus der Zeit vor der Spalte
 * bekäme sonst eine Warnung über einen Zustand, den niemand gemessen hat —
 * dieselbe Sorte Meldung wie die, die im August bei jeder Freigabe erschien.
 */
const quotaActionable = computed(() => quotaBroken.value || quotaUnknown.value)

/*
 * Zwei Rückfragen und nicht eine.
 *
 * Der Rückbau löscht als root einen Verzeichnisbaum, und es gibt noch keine
 * Sicherungen. Ein einzelnes „Wirklich?" beantwortet man im Vorbeigehen; den
 * Namen abzutippen ist die kleinste Hürde, die eine bewusste Handlung
 * verlangt.
 *
 * **Hier stand ein `window.prompt`, und das war bei genau dieser Aktion am
 * schlimmsten** (`docs/55`, Befund 15): Safari darf die Dialoge einer Seite
 * abschalten, `prompt()` gibt danach `null` zurück — und der Knopf „Zurückbauen"
 * hätte wortlos nichts getan. Die Richtung ist die sichere, die Auskunft ist
 * keine.
 */
const tearingDown = ref(false)
const typedName = ref('')

function remove(): void {
  if (typedName.value !== props.subscription.name) return

  router.delete(`/subscriptions/${props.subscription.id}`)
}
</script>

<template>
  <Head :title="props.subscription.name" />

  <PanelLayout :title="props.subscription.name">
    <template #breadcrumb>
      <Link href="/subscriptions" class="link">Abonnements</Link> ·
      <!-- Ohne das Recht auf die Kundenseite bleibt der Name stehen und wird
           kein Verweis: Der Weg dorthin ist ihm verwehrt, der Name ist es
           nicht. -->
      <Link
        v-if="props.can.viewCustomer"
        :href="`/customers/${props.subscription.customer_id}`"
        class="link"
      >
        {{ props.subscription.customer ?? '—' }}
      </Link>
      <template v-else>{{ props.subscription.customer ?? '—' }}</template>
    </template>

    <template #actions>
      <Badge :kind="rang(props.subscription.status)">{{ props.subscription.status_label }}</Badge>
      <!--
        Jede Aktion fragt zuerst, ob sie dem Betrachter überhaupt offensteht,
        und dann, ob sie zum Zustand passt. Die Reihenfolge ist keine
        Geschmacksfrage: „Sperren" für einen Kunden auszublenden, weil das Abo
        gerade gesperrt ist, wäre die richtige Antwort aus dem falschen Grund —
        und beim nächsten Zustand stünde der Knopf wieder da.
      -->
      <Link
        v-if="props.can.update"
        class="button primary"
        :href="`/subscriptions/${props.subscription.id}/edit`"
      >Bearbeiten</Link>

      <!--
        **Der einzige Weg zum Dateimanager, und er hat gefehlt.**

        Bis zur Zwischenabnahme am 14. August 2026 zeigte kein Template auf
        `/files` — weder diese Seite noch die Navigation. Elf Routen, drei
        Seiten und eine Policy waren gebaut und über die Adresszeile
        erreichbar (`docs/53`, Befund 6).

        Er steht hier und nicht in der Navigation: Der Dateimanager gehört zu
        *einem* Abonnement, und ein Menüpunkt bräuchte davor eine Auswahl, die
        es an dieser Stelle schon gibt. Derselbe Grund, aus dem die Domains
        eines Abonnements hier stehen und nicht doppelt im Menü.
      -->
      <Link
        v-if="props.can.browseFiles && props.subscription.status !== 'provisioning'"
        class="button"
        :href="`/subscriptions/${props.subscription.id}/files`"
      >Dateien</Link>

      <!--
        Und der SFTP-Zugang daneben, aus demselben Grund: Er gehört zu *einem*
        Abonnement. Ein Knopf, den der Betrachter nicht drücken darf, wird nicht
        gezeigt — die Antwort kommt aus derselben Policy, die ihn später abweist
        (`AbilityReachTest`), und nicht aus einem `v-if` auf den Kontotyp.
      -->
      <Link
        v-if="props.can.manageSftp && props.subscription.status !== 'provisioning'"
        class="button"
        :href="`/subscriptions/${props.subscription.id}/sftp`"
      >SFTP-Zugang</Link>
      <button
        v-if="props.can.suspend && props.subscription.status === 'active'"
        type="button"
        class="button"
        @click="suspend"
      >Sperren</button>
      <button
        v-if="props.can.suspend && props.subscription.status === 'suspended'"
        type="button"
        class="button"
        @click="resume"
      >Entsperren</button>
      <button
        v-if="props.can.delete && props.subscription.status !== 'provisioning' && !tearingDown"
        type="button"
        class="button danger"
        @click="tearingDown = true; typedName = ''"
      >
        Zurückbauen
      </button>
    </template>

    <!--
      Die zweite Rückfrage steht auf der Seite und nicht in einem Systemdialog.
      Der Satz nennt, was verlorengeht, **bevor** das Feld danach fragt — er ist
      die Begründung für die Hürde und nicht ihre Beschriftung.
    -->
    <form v-if="tearingDown" class="block" @submit.prevent="remove">
      <p class="notice warn">
        Rückbau von {{ props.subscription.name }}: Verzeichnis, Systembenutzer und Quota werden
        entfernt. Es gibt keine Sicherung.
      </p>

      <label class="field inline">
        <span>Zum Bestätigen den Namen des Abonnements eintippen</span>
        <input v-model="typedName" type="text" autocomplete="off" required />
      </label>

      <div class="button-row">
        <button
          type="submit"
          class="button danger"
          :disabled="typedName !== props.subscription.name"
        >
          Zurückbauen
        </button>
        <button type="button" class="button" @click="tearingDown = false">Abbrechen</button>
      </div>
    </form>

    <!--
      Die Zusammenfassung steht über allem und nicht am Feld.

      Diese Seite hatte lange kein Formular; mit den DNS-Zugangsdaten hat sie
      eines. Ohne die Zusammenfassung stünde eine Abweisung als kleine rote
      Zeile unten in einem langen Aufbau, und nach der Antwort springt die
      Seite nach oben — genau der Fall, der beim Anlegen eines Kunden einen
      halben Tag gekostet hat.
    -->
    <FormErrors />

    <div class="sections">
      <Section title="Stammdaten">
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Kunde</td>
              <td class="right">
                <Link :href="`/customers/${props.subscription.customer_id}`" class="link">
                  {{ props.subscription.customer ?? '—' }}
                </Link>
              </td>
            </tr>
            <tr><td class="quiet">Plan</td><td class="right name">{{ props.subscription.plan ?? '—' }}</td></tr>
            <tr><td class="quiet">Systembenutzer</td><td class="right ident">{{ props.subscription.system_user ?? '—' }}</td></tr>
            <tr><td class="quiet">Verzeichnis</td><td class="right ident">{{ props.subscription.root }}</td></tr>
            <tr v-if="props.subscription.suspended_at">
              <td class="quiet">Gesperrt seit</td>
              <td class="right">{{ props.subscription.suspended_at }}</td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        Der Speicher steht neben den Kontingenten und nicht darin: Er ist das
        einzige Kontingent, zu dem es einen gemessenen Stand gibt, und die
        Tabelle daneben zeigt Vereinbartes. Beides in einer Zeile hiesse, zwei
        verschiedene Dinge gleich aussehen zu lassen.
      -->
      <Section title="Speicher">
        <!--
          **Die Grenze steht ganz oben, wenn sie nicht gilt.** Sie gehört vor
          die Zahlen und nicht darunter: Wer eine Grenze liest, hat sie
          geglaubt, bevor er weiterliest.

          Auf `cloudsrv24` stand hier am 10. August 2026 „15360 MB", und
          `setquota` war beim Anlegen gescheitert — gemeldet vom Agenten, von
          niemandem gelesen. Der Grund kommt wörtlich vom System; ein
          „konnte nicht gesetzt werden" hülfe beim Beheben nicht.
        -->
        <!--
          **Der ganze Text in einem `span`, und das ist keine Kosmetik.**
          `.notice` ist eine Flexbox; jedes direkte Kind wird ein Flex-Item und
          steht neben den anderen, statt mit ihnen umzubrechen. Mit vier Kindern
          — `strong` und drei Kennungen — schob diese Meldung die Seite bei
          390px um **65px** aus dem Bild. Gemessen am 10. August 2026 im
          Chromium, nachdem sie mit `v0.5.1-rc.7` schon ausgeliefert war.

          Es ist derselbe Fehler wie der aus P4, der 83px gekostet hat, und er
          ist auf demselben Weg gefunden worden: nicht von einem Test, sondern
          von einer Messung bei 390px. `Overview.vue` macht es seit demselben
          Tag richtig — wieder eine Seite, die es kann, und die nächste nicht.
        -->
        <p v-if="quotaBroken" class="notice warn">
          <!--
            **Die Systemmeldung steht am Schluss**, und der Grund stand auf dem
            Bildschirm: Davor las sich der Absatz als „…for device Der Weg
            dorthin steht in…". Eine wörtlich übernommene Meldung endet nicht
            verlässlich mit einem Punkt, und was danach kommt, klebt an ihr.
            Am Satzende braucht sie keinen.
          -->
          <span>
            Diese Grenze ist <strong>nicht in Kraft</strong>. Das Dateisystem
            unter <span class="ident">/var/www/vhosts</span> führt keine Quota
            für Benutzer; der Weg dorthin steht in
            <span class="ident">docs/41-dateisystem-quota.md</span>.
            <template v-if="props.usage.note">
              Das System meldet: <span class="ident">{{ props.usage.note }}</span>
            </template>
          </span>
        </p>

        <!--
          **Keine Warnung, sondern eine Auskunft.** Hier ist nichts gemessen
          worden — weder dass die Grenze gilt noch dass sie fehlgeht. Ein
          `notice warn` behauptete einen Befund, den es nicht gibt.
        -->
        <p v-else-if="quotaUnknown" class="hint">
          Ob diese Grenze im Dateisystem gilt, ist nicht nachgesehen worden. Sie
          wird angewandt, sobald sie sich ändert — oder gleich hier.
        </p>

        <!--
          **Der Knopf steht beim Befund und nicht bei den anderen.** Er ist die
          Antwort auf genau diese Meldung: Ist die Quota des Dateisystems
          eingeschaltet worden, greift die Grenze erst, wenn sie noch einmal
          gesetzt wird — und `Bearbeiten` reicht dafür nicht, weil sich der Wert
          nicht ändert (siehe `SubscriptionController::reapplyQuota()`).
        -->
        <div v-if="quotaActionable && props.can.update" class="button-row">
          <button type="button" class="button" @click="reapplyQuota">
            {{ quotaBroken ? 'Grenze erneut anwenden' : 'Grenze anwenden' }}
          </button>
        </div>

        <p v-if="props.usage.used_mb === null" class="empty">
          Noch nicht gemessen. Die Messung läuft im Viertelstundentakt
          (<span class="ident">srvpanel-usage.timer</span>) und braucht eine
          Dateisystem-Quota auf dem Mount von /var/www/vhosts.
        </p>

        <template v-else>
          <p class="usage">
            <strong>{{ props.usage.used_mb.toLocaleString('de-DE') }} MB</strong>
            <span v-if="props.usage.limit_mb !== null">
              von {{ props.usage.limit_mb.toLocaleString('de-DE') }} MB
            </span>
            <span v-else>ohne Grenze</span>
          </p>

          <Bar
            v-if="props.usage.percent !== null"
            :percent="props.usage.percent"
            :tight="props.usage.percent >= 90 && props.usage.percent <= 100"
            :over="props.usage.percent > 100"
            breit
          />

          <p class="section-note">Gemessen am {{ props.usage.measured_at ?? '—' }}</p>
        </template>
      </Section>

      <!--
        Die Datenbanken bekommen einen eigenen Bereich und nicht eine zweite
        Farbe im Balken darüber: Die beiden Zahlen messen verschiedene Stellen
        des Datenträgers — /var/www/vhosts hier, /var/lib/mysql dort — und ihre
        Summe stünde gegen keine Grenze.
      -->
      <Section title="Datenbanken">
        <!-- Ein Abonnement ohne Datenbanken hat nichts zu messen. Der Satz
             darunter würde sonst einen ausstehenden Lauf melden, wo alles
             erledigt ist. -->
        <p v-if="props.database_usage.count === 0" class="empty">
          Keine Datenbanken angelegt.
        </p>

        <p v-else-if="props.database_usage.used_mb === null" class="empty">
          Noch nicht gemessen. Die Messung läuft im selben Viertelstundentakt
          (<span class="ident">srvpanel-usage.timer</span>) und braucht einen
          erreichbaren Datenbankserver.
        </p>

        <template v-else>
          <p class="usage">
            <strong>{{ props.database_usage.used_mb.toLocaleString('de-DE') }} MB</strong>
            <span v-if="props.database_usage.limit_mb !== null">
              von {{ props.database_usage.limit_mb.toLocaleString('de-DE') }} MB
            </span>
            <span v-else>ohne Grenze</span>
          </p>

          <Bar
            v-if="props.database_usage.percent !== null"
            :percent="props.database_usage.percent"
            :tight="props.database_usage.percent >= 90 && props.database_usage.percent <= 100"
            :over="props.database_usage.percent > 100"
            breit
          />
        </template>

        <!--
          **Der Satz steht immer da, auch vor der ersten Messung.** Er ist keine
          Erläuterung der Zahl, sondern die Einschränkung des Kontingents: Wer
          hier eine Grenze sieht, soll nicht annehmen, dass sie zuschlägt.
        -->
        <p v-if="!props.database_usage.enforced && props.database_usage.count > 0" class="hint">
          Diese Grenze wird <b>gemessen und nicht erzwungen</b>. Keiner der
          beiden Datenbankserver kennt eine Obergrenze je Datenbank, und ihre
          Daten liegen ausserhalb der Dateisystem-Quota des Abonnements — ein
          überschrittener Wert füllt den Datenträger weiter.
        </p>
      </Section>

      <Section title="Kontingente">
        <table class="pairs">
          <tbody>
            <tr v-for="q in props.quotas" :key="q.key">
              <td class="quiet">{{ q.label }}</td>
              <td class="right name">{{ q.value }}</td>
              <td class="right">
                <Badge v-if="q.differs" kind="warn">abweichend vom Plan</Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <Section title="Freigaben">
        <table class="pairs">
          <tbody>
            <tr v-for="f in props.features" :key="f.label">
              <td class="quiet">{{ f.label }}</td>
              <td class="right">
                <Badge :kind="f.granted ? 'ok' : 'neutral'">{{ f.granted ? 'frei' : 'gesperrt' }}</Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        Die eigenen DNS-Zugangsdaten (docs/34 §5).

        Sie stehen unmittelbar hinter den Freigaben, weil sie an genau einer
        davon hängen: Ohne `DNS-Einträge bearbeiten` verwaltet der Betreiber
        die Zone, und dieser Bereich erscheint gar nicht. Wer die Freigabe hat,
        führt seine Zone selbst und hält den Schlüssel dazu ohnehin in den
        Händen.

        Gefragt wird `can.manageDns` — dieselbe Policy, die den Aufruf später
        abweist. `props.dns` daneben trägt die Angaben und ist aus demselben
        Grund `null`; die Bedingung steht trotzdem an der Fähigkeit, denn ein
        Knopf, der an einer vorhandenen Ablage hängt statt an einem Recht, ist
        beim nächsten Umbau der Ablage ungeschützt.
      -->
      <template v-if="props.can.manageDns && props.dns">
        <DnsCredentials
          :action="`/subscriptions/${props.subscription.id}/dns`"
          :profile="props.dns.profile"
          :credential="props.dns.credential"
          :providers="props.dns.providers"
        />
      </template>

      <!--
        Die Domains stehen vor den Vorgängen: Sie sind das, wofür ein
        Abonnement da ist.
      -->
      <Section title="Domains" full>
        <template #actions>
          <Link
            v-if="props.can.addDomain"
            class="button small"
            :href="`/subscriptions/${props.subscription.id}/domains/create`"
          >
            Domain anlegen
          </Link>
        </template>

        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Domain</th><th>Sorte</th><th>PHP</th><th>DNS</th><th>Zustand</th></tr>
            </thead>
            <tbody>
              <tr v-for="d in props.domains" :key="d.id">
                <td data-column="Domain" class="ident name">
                  <Link :href="`/domains/${d.id}`" class="link">{{ d.name }}</Link>
                </td>
                <td data-column="Sorte" class="quiet">{{ d.type_label }}</td>
                <td data-column="PHP">
                  <template v-if="d.is_redirect"><span class="quiet">leitet weiter</span></template>
                  <template v-else>{{ d.php_version ?? '—' }}</template>
                </td>
                <!--
                  **Der DNS-Abgleich steht vor dem Zustand der Domain.** Eine
                  Liste beantwortet nicht dieselbe Frage wie eine Seite — sie
                  beantwortet, welche Seite man aufschlagen muss.
                -->
                <td data-column="DNS">
                  <Badge :kind="d.dns_badge">{{ d.dns_label }}</Badge>
                </td>

                <td data-column="Zustand">
                  <Badge :kind="rang(d.status)">{{ d.status_label }}</Badge>
                </td>
              </tr>
              <tr v-if="props.domains.length === 0">
                <td colspan="5" class="quiet">Noch keine Domain.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Vorgänge" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Nummer</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
            </thead>
            <tbody>
              <tr v-for="o in props.operations" :key="o.id">
                <td data-column="Nummer" class="ident">
                  <Link :href="`/operations/${o.id}`" class="link">{{ o.id }}</Link>
                </td>
                <td data-column="Aufgabe" class="ident name">{{ o.task ?? '—' }}</td>
                <td data-column="Zustand" class="quiet">{{ o.status_label }}</td>
                <td data-column="Angelegt" class="quiet">{{ o.created_at ?? '—' }}</td>
              </tr>
              <tr v-if="props.operations.length === 0">
                <td colspan="4" class="quiet">Noch kein Vorgang für dieses Abonnement.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Der gemessene Stand als grosse Zahl — dieselbe Rolle wie auf einer Kachel,
 * deshalb dieselbe Marke. Die Einheit daneben ist kleiner, weil sie mitläuft
 * und nicht gelesen wird.
 */
.usage {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
  margin: 16px 0 14px;
  font-size: var(--text-table);
  color: var(--text-muted);
}

.usage strong {
  font-size: var(--text-metric);
  font-weight: 640;
  letter-spacing: -0.03em;
  line-height: 1.05;
  color: var(--text-strong);
}
</style>
