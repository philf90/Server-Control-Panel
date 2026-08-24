<script setup lang="ts">
/*
 * Die Adminkonten — wer an dieses Panel darf und mit welcher Rolle.
 *
 * **Nur Adminkonten.** Kundenkonten stehen am Kunden; zwei Listen derselben
 * Zeilen wären zwei Wege zum selben Ort, und der zweite veraltet.
 *
 * **Warum der zweite Faktor eine Spalte bekommt.** `docs/20 §6.4` macht ihn für
 * Adminkonten verpflichtend, und `RequireTwoFactor` setzt das durch — ein
 * frisch angelegtes Konto hat ihn also noch nicht, und der Betreiber sieht
 * daran, ob der Mensch dahinter angekommen ist. Die Spalte ist eine Auskunft
 * und kein Schalter: Ein Schalter für eine Pflicht wäre ihre Abschaffung.
 */
import { Head, Link } from '@inertiajs/vue3'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Pager from '../../Components/Pager.vue'

interface Row {
  id: number
  name: string
  email: string | null
  role: string | null
  role_label: string | null
  status: string
  status_label: string
  two_factor: boolean
  last_login_at: string | null
  is_last_operator: boolean
}

const props = defineProps<{
  accounts: { data: Row[]; current_page: number; last_page: number; total: number }
  operators: number
}>()
</script>

<template>
  <Head title="Konten" />

  <PanelLayout title="Konten" :subline="`${props.accounts.total} für die Verwaltung dieses Servers`">
    <template #actions>
      <Link href="/accounts/create" class="button primary">Konto anlegen</Link>
    </template>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Name</th>
            <th>Rolle</th>
            <th>Zustand</th>
            <th>Zweiter Faktor</th>
            <th>Letzte Anmeldung</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.accounts.data" :key="row.id">
            <td data-column="Konto" class="multiline">
              <span class="title-row">
                <Link :href="`/accounts/${row.id}/edit`" class="link">{{ row.name }}</Link>
              </span>
              <!--
                Die Adresse als `.ident`: Sie ist eine Kennung und keine Prosa,
                und sie darf lang sein. Ohne die Ausnahme aus app.css schiebt
                sie bei 390 px die Seite aus dem Bild — dieselbe Stelle, an der
                das dieses Projekt schon dreimal gekostet hat.
              -->
              <p class="ident address">{{ row.email }}</p>
            </td>

            <td data-column="Rolle">
              <!--
                Der Betreiber ist die weitergehende Rolle und trägt deshalb die
                auffälligere Marke. `warn` und nicht `critical`: Ein Betreiber
                ist kein Fehlerzustand, sondern der Normalfall für den, der den
                Server betreibt — die Marke sagt „hier steht mehr auf dem
                Spiel", nicht „hier stimmt etwas nicht".
              -->
              <span class="title-row">
                <Badge :kind="row.role === 'operator' ? 'warn' : 'neutral'">
                  {{ row.role_label ?? 'keine' }}
                </Badge>

                <!--
                  **Welche Zeile es betrifft, steht an der Zeile.** Der Hinweis
                  unter der Liste sagt *warum* ein letzter Betreiber sich nicht
                  herabstufen lässt; er sagt nicht, *welcher* es ist. Zwei
                  Fragen, zwei Orte — und keine der beiden Angaben ersetzt die
                  andere.
                -->
                <Badge v-if="row.is_last_operator" kind="neutral">letzter</Badge>
              </span>
            </td>

            <td data-column="Zustand">
              <Badge :kind="row.status === 'active' ? 'ok' : 'critical'">{{ row.status_label }}</Badge>
            </td>

            <td data-column="Zweiter Faktor" class="quiet">
              {{ row.two_factor ? 'eingerichtet' : 'noch nicht' }}
            </td>

            <td data-column="Letzte Anmeldung" class="quiet">
              {{ row.last_login_at ?? 'noch nie' }}
            </td>

            <td>
              <Link :href="`/accounts/${row.id}/edit`" class="button small">Bearbeiten</Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!--
      **Der Grund steht unter der Liste und nicht erst hinter der Ablehnung.**
      Wer den einzigen Betreiber herabstufen will, soll das vorher lesen und
      nicht als Fehlermeldung, nachdem er das Formular ausgefüllt hat.

      Die Zahl kommt aus derselben Stelle, die es später abweist
      (App\Support\Authorization\LastOperator) — eine eigene Bedingung hier wäre
      deren zweite Fassung.
    -->
    <p v-if="props.operators <= 1" class="hint">
      Es gibt genau einen aktiven Betreiber. Er lässt sich weder herabstufen
      noch sperren, solange er der letzte ist — sonst käme niemand mehr an die
      Einstellungen dieses Servers.
    </p>

    <Pager :page="props.accounts.current_page" :pages="props.accounts.last_page" />
  </PanelLayout>
</template>

<style scoped>
.title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

/*
 * **`.address` und nicht `.ident` — der Name gehört app.css.**
 *
 * Der erste Wurf gab dieser Regel den Namen `.ident`, also den der globalen
 * Kennungsklasse. Zwei Dinge sind dabei passiert, und das zweite ist das
 * teurere: Die Regel hat die Schriftgrösse der globalen überschrieben — eine
 * Komponente, die eine globale Form nachbessert, ist derselbe Fehler wie ein
 * Hexwert. Und sie hat `MobileLayoutTest` **blind gemacht**: Der Wächter liest
 * jede Regel, deren Selektor `.ident` enthält, und merkt sich an der letzten,
 * ob `overflow-wrap: anywhere` dabei ist. Im gebauten Stylesheet stand die
 * Regel von hier zuletzt — und damit meldete er, die globale Klasse dürfe
 * nicht mehr brechen.
 *
 * > **Eine Komponentenregel mit dem Namen einer globalen überschreibt nicht nur
 * > deren Form, sondern auch die Antwort, die ein Wächter über sie bekommt.**
 *
 * Das Element trägt beide Klassen: `.ident` bringt Schrift und Umbruch,
 * `.address` nur den Abstand zur Zeile darüber.
 */
.address {
  margin: 3px 0 0;
  color: var(--text-muted);
}
</style>
