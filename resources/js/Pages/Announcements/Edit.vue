<script setup lang="ts">
/*
 * Eine bestehende Ankündigung ändern (`docs/105 §10.4`).
 *
 * **Eine eigene Seite und keine Zeile in der Tabelle.** Das Formular trägt
 * sieben Felder; in einer aufgeklappten Zeile stünde es bei 390 px in einer
 * Zelle, deren Breite von den fünf Spalten daneben abhängt. Die Vorschau darf
 * das, weil sie ein Band zeigt und nichts entgegennimmt.
 *
 * **Die Felder stehen in `AnnouncementForm`** — dieselbe Komponente wie beim
 * Anlegen. Ein zweites Formular wäre die Fassung, die beim nächsten Feld
 * stehenbleibt.
 */
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import AnnouncementForm from '../../Components/AnnouncementForm.vue'
import Section from '../../Components/Section.vue'

defineProps<{
  announcement: { id: number; rank: string }
  values: {
    category: string
    body: string
    visible_from_date: string
    visible_from_time: string
    visible_until_date: string
    visible_until_time: string
    audiences: string[]
  }
  zone: string
  categories: { value: string; label: string }[]
  audiences: { value: string; label: string }[]
}>()
</script>

<template>
  <Head title="Ankündigung ändern" />

  <PanelLayout
    title="Ankündigung ändern"
    subline="Was hier steht, sehen alle, die im Publikum stehen"
  >
    <div class="sections">
      <Section title="Ankündigung">
        <!--
          **Der Weg zurück steht auf der Seite** und nicht nur im Browser.
          `docs/93` hat das an den Vorgangsseiten bezahlt: Ein Weg, den man nur
          erklären kann, indem man den Zurück-Knopf des Browsers zu Hilfe
          nimmt, ist keiner, den die Anwendung anbietet.
        -->
        <p class="quiet">
          <Link class="link" href="/announcements">Zur Liste der Ankündigungen</Link>
        </p>

        <AnnouncementForm
          :announcement="announcement"
          :values="values"
          :zone="zone"
          :categories="categories"
          :audiences="audiences"
        />
      </Section>
    </div>
  </PanelLayout>
</template>
