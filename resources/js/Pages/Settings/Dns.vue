<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import Badge from '../../Components/Badge.vue'
import DnsCredentials from '../../Components/DnsCredentials.vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

/*
 * Die DNS-Zugangsdaten des Betreibers (docs/34 §5).
 *
 * **Warum es diese Seite gibt, obwohl es `srvpanel dns` gibt.** Für den
 * Betreiber ist die Kommandozeile oft der bequemere Weg — wer einen Server
 * einrichtet, hat den Schlüssel gerade im Terminal. Es fehlte der Ort, an dem
 * man *nachsieht*, was hinterlegt ist: „Es müsste eigentlich liegen" ist keine
 * Auskunft, und die Frage stellt sich genau dann, wenn eine Bestellung
 * gescheitert ist.
 *
 * Das Formular selbst steht in {@link DnsCredentials} — dieselben Felder
 * gelten am Abonnement.
 */
const props = defineProps<{
  profile: string
  credential: {
    profile: string
    provider: string
    provider_label: string
    stored_at: number
    zones: string[]
  } | null
  providers: { value: string; label: string; usable: boolean; reason: string | null }[]
}>()
</script>

<template>
  <Head title="DNS-Zugang" />

  <PanelLayout title="DNS-Zugang" subline="Zugangsdaten für Bestellungen über DNS-01">
    <template #actions>
      <Badge :kind="props.credential ? 'ok' : 'warn'">
        {{ props.credential ? 'hinterlegt' : 'nicht hinterlegt' }}
      </Badge>
    </template>

    <FormErrors />

    <!--
      Der Satz, der die Seite erklärt, steht oben und nicht am Feld: Wer
      hierherkommt, will meistens zuerst wissen, wofür das gebraucht wird — und
      was ohne die Angaben *nicht* passiert.
    -->
    <p v-if="!props.credential" class="notice warn">
      <span>
        Ohne Zugangsdaten geht <b>DNS-01</b> nicht, und damit kein Platzhalter
        (<span class="ident">*.example.de</span>). Gewöhnliche Zertifikate über
        HTTP-01 sind davon nicht betroffen — sie brauchen keinen Zugriff auf die
        Zone.
      </span>
    </p>

    <p class="notice">
      <span>
        Diese Angaben gelten für alles, was der Betreiber selbst führt. Ein
        Abonnement, dessen Plan <b>DNS-Einträge bearbeiten</b> freigibt,
        hinterlegt seine eigenen an seinem Abonnement — und bekommt diese hier
        ausdrücklich <b>nicht</b> ersatzweise.
      </span>
    </p>

    <DnsCredentials
      action="/settings/dns"
      :profile="props.profile"
      :credential="props.credential"
      :providers="props.providers"
    />
  </PanelLayout>
</template>
