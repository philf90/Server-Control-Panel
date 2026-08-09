<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'

/*
 * Der Datenbankserver — eine Seite zum Nachsehen.
 *
 * **Ohne Schalter, und das ist der Inhalt der Seite und nicht ihre Lücke.**
 * Den Fernzugriff umzulegen heisst, den Datenbankserver neu zu starten, und
 * der trägt auch dieses Panel: Die Anfrage, die den Vorgang anstösst, verlöre
 * ihre Verbindung mitten im Lauf. Deshalb steht hier der Befehl statt eines
 * Knopfes — abgedruckt und nicht beschrieben, damit man ihn nimmt und nicht
 * nachschlägt.
 */
const props = defineProps<{
  server: {
    reachable: boolean
    error: string | null
    flavour: string | null
    version: string | null
    usable: boolean
    reason: string | null
    bind_address: string | null
    remote: boolean
  }
  remote_users: {
    total: number
    hosts: { host: string; count: number }[]
  }
  commands: { on: string; off: string }
}>()

/*
 * Der Zustand, der beide Zahlen zusammenbringt.
 *
 * Zugänge für fremde Adressen an einem Server, der nur lokal horcht, sind
 * Konten, die niemand benutzen kann — und niemand sucht sie, weil sie im Panel
 * ganz normal aussehen. Sie entstehen, wenn der Fernzugriff nachträglich
 * abgeschaltet wird; anlegen lassen sie sich in diesem Zustand nicht.
 */
const gestrandet = computed(() => !props.server.remote && props.remote_users.total > 0)

const marke = computed<'ok' | 'warn' | 'neutral'>(() => {
  if (!props.server.reachable) return 'warn'

  return props.server.remote ? 'ok' : 'neutral'
})

const zustand = computed(() => {
  if (!props.server.reachable) return 'unbekannt'

  return props.server.remote ? 'Fernzugriff möglich' : 'nur lokal'
})

const bezeichnung = computed(() => {
  if (props.server.flavour === 'mariadb') return 'MariaDB'
  if (props.server.flavour === 'mysql') return 'MySQL'

  return props.server.flavour ?? '—'
})
</script>

<template>
  <Head title="Datenbankserver" />

  <PanelLayout title="Datenbankserver" subline="Was hier läuft und von wo aus es erreichbar ist">
    <template #actions>
      <Badge :kind="marke">{{ zustand }}</Badge>
    </template>

    <p v-if="props.server.error" class="notice warn">
      <span>
        Der Agent antwortet nicht: {{ props.server.error }} Solange das so ist,
        steht auf dieser Seite nichts über den Datenbankserver — auch nicht,
        dass alles in Ordnung wäre.
      </span>
    </p>

    <p v-else-if="!props.server.reachable" class="notice critical">
      <span>
        Es ist kein Datenbankserver erreichbar.
        <template v-if="props.server.reason">{{ props.server.reason }}</template>
        Datenbanken lassen sich damit weder anlegen noch benutzen.
      </span>
    </p>

    <p v-else-if="!props.server.usable" class="notice critical">
      <span>
        Auf diesem Datenbankserver arbeitet srvpanel nicht:
        {{ props.server.reason ?? 'kein Grund genannt' }}
      </span>
    </p>

    <!--
      Der gestrandete Zustand steht oben und nicht unten bei den Zahlen: Er ist
      der einzige, aus dem etwas folgt.
    -->
    <p v-if="gestrandet" class="notice warn">
      <span>
        {{ props.remote_users.total }} Zugang/Zugänge lauten auf eine fremde
        Adresse, aber der Server horcht nur lokal — diese Konten kommen nie
        zustande. Sie bleiben bestehen und werden wieder benutzbar, sobald der
        Fernzugriff eingeschaltet ist.
      </span>
    </p>

    <div class="sections">
      <Section title="Server">
        <table class="pairs">
          <tbody>
            <tr>
              <!-- „Art" und nicht „Ausgabe": Auf der PHP-Seite heisst
                   „Ausgabe" die Release-Angabe einer Version, und dasselbe
                   Wort zweimal für zwei Dinge ist schlechter als ein
                   blasseres. -->
              <td class="quiet">Art</td>
              <td class="right">{{ bezeichnung }}</td>
            </tr>
            <tr>
              <td class="quiet">Version</td>
              <td class="right ident">{{ props.server.version ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Horchadresse</td>
              <td class="right ident">{{ props.server.bind_address ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Fernzugriff</td>
              <td class="right">
                <Badge :kind="props.server.remote ? 'ok' : 'neutral'">
                  {{ props.server.remote ? 'an' : 'aus' }}
                </Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <Section title="Zugänge von aussen" note="Aus dem Bestand dieses Panels — von Hand angelegte Konten stehen hier nicht.">
        <p v-if="props.remote_users.total === 0" class="empty">
          Alle Zugänge lauten auf <span class="ident">localhost</span>.
        </p>

        <table v-else class="pairs">
          <tbody>
            <tr v-for="eintrag in props.remote_users.hosts" :key="eintrag.host">
              <td class="ident">{{ eintrag.host }}</td>
              <td class="right">{{ eintrag.count }}</td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        **Kein `full`, und das ist gemessen.** Ein Befehl, der mitten in der
        Option umbricht, ist keine Zeile zum Abtippen mehr — in einer
        Bezeichnungstabelle bricht `.ident` ausdrücklich um, statt den Nachbarn
        zu überschreiben (app.css). Der schmalste Zustand dieses Bereichs auf
        dem Schreibtisch ist `--bereich-min`, also 400px; bei 1600px
        Fensterbreite steht er genau dort. Gemessen im Chromium dieses
        Containers: Bereich 404px, Wertzelle 295px, der Befehl 236px — eine
        Zeile, 59px Luft. Darunter stapeln die Bereiche ohnehin auf volle
        Breite.
      -->
      <Section title="Umschalten">
        <!--
          **Der Befehl steht abgedruckt und nicht beschrieben.** „Schalte es auf
          dem Server ein" ist keine Auskunft — wer hier steht, will die Zeile,
          die er einfügt.
        -->
        <p class="notice neutral">
          <span>
            Der Fernzugriff ist eine Eigenschaft des Servers und wird auf ihm
            geschaltet. Der Datenbankserver wird dabei neu gestartet, und weil
            dieses Panel auf ihm arbeitet, geschieht das nicht hinter einem
            Klick.
          </span>
        </p>

        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Einschalten</td>
              <td class="right ident">{{ props.commands.on }}</td>
            </tr>
            <tr>
              <td class="quiet">Abschalten</td>
              <td class="right ident">{{ props.commands.off }}</td>
            </tr>
          </tbody>
        </table>

        <p class="section-note">
          Nach dem Umschalten liest der Befehl selbst nach, worauf der Server
          danach horcht, und meldet einen Fehler, wenn es etwas anderes ist als
          bestellt. Was er dabei nach <span class="ident">/etc</span> schreibt,
          nimmt das Paket beim Entfernen wieder mit.
        </p>
      </Section>
    </div>
  </PanelLayout>
</template>
