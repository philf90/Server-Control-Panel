<script setup lang="ts">
/*
 * Der Streifen der laufenden Vorgänge (B8, `docs/132 §2`).
 *
 * ## Warum er hier steht und nicht auf der Vorgangsseite
 *
 * Bis B8 trug jeder Knopf, der einen Vorgang absetzt, seinen Betrachter fort:
 * 22 Weiterleitungen aus acht Controllern endeten auf `operations.show`. Der
 * Weg zurück war der Zurück-Knopf des Browsers.
 *
 * > **Ein Weg, den man nur erklären kann, indem man den Browser zu Hilfe
 * > nimmt, ist keiner, den die Anwendung anbietet.** (`docs/92 §1`)
 *
 * ## Nachgefragt und nicht gestreamt
 *
 * Die Vorgangsseite hält eine `EventSource`. Auf **jeder** Seite mitzulaufen
 * ist gemessen zu teuer: Der Panel-Pool hat zwölf Arbeiter, und zwei belegte
 * lassen die nächste Anfrage 16 s warten (`docs/128` M9).
 *
 * > **Zwölf offene Ströme machen das Panel für alle unerreichbar.**
 *
 * Gefragt wird deshalb im Takt — und **nur solange etwas läuft**. Dieselbe
 * Form wie in `Subscriptions/Backups.vue` seit P8: der Takt an den Zustand
 * gehängt, der alte vorher angehalten, und in `onUnmounted` abgeräumt, weil
 * Inertia die Seite im selben Dokument austauscht.
 *
 * ## Und die Seite lädt sich nicht von selbst nach
 *
 * Aus „läuft" wird „fertig — Seite aktualisieren", und wer will, drückt.
 * `docs/92 §4` nennt das Nachladen eine Entscheidung je Seite; so wird aus der
 * Entscheidung ein Knopf. Ein Nachladen unter den Händen nähme dem, der gerade
 * tippt, seinen Stand — Inertia stellt ihn nicht wieder her.
 */
import { Link, router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, watch } from 'vue'

interface RunningOperation {
  id: number
  label: string
  status: string
  progress: number
  running: boolean
}

const props = defineProps<{ items: RunningOperation[] }>()

/**
 * Wie oft nachgefragt wird, solange ein Vorgang läuft.
 *
 * Drei Sekunden — dieselbe Zahl und derselbe Grund wie bei den Sicherungen:
 * Wer gerade einen Knopf gedrückt hat, steht davor und wartet.
 */
const NACHFRAGE_MS = 3000

let takt: ReturnType<typeof setInterval> | undefined

/**
 * Läuft noch etwas?
 *
 * **An der Antwort des Servers und nicht an einem eigenen Merker.** Ein
 * Merker wüsste nichts von einem Vorgang, den ein zweiter Reiter abgesetzt
 * hat — und stünde nach einem Neuladen auf falsch.
 */
const laeuft = computed((): boolean => props.items.some((zeile) => zeile.running))

/** Nur die eine Eigenschaft, und nur solange sich etwas ändern kann. */
function nachsehen(): void {
  router.reload({ only: ['runningOperations'] })
}

/**
 * Den Takt an den Zustand hängen — und den alten vorher anhalten.
 *
 * `setInterval` kennt keine Änderung seiner Länge; ohne das Anhalten liefen
 * nach zwei Wechseln drei. Dieselbe Stelle und derselbe Grund wie in
 * `Overview.vue` und `Backups.vue`.
 */
function stellen(): void {
  clearInterval(takt)
  takt = undefined

  if (laeuft.value) takt = setInterval(nachsehen, NACHFRAGE_MS)
}

watch(laeuft, stellen)
onMounted(stellen)

onUnmounted((): void => {
  clearInterval(takt)
})

/**
 * Der Rang je Zustand — **derselbe Ausdruck wie auf der Vorgangsseite**.
 *
 * Eine zweite Zuordnung wäre die zweite Fassung, und die veraltet: Bekommt
 * `OperationStatus` einen Zustand dazu, stünde er hier in einer anderen Farbe
 * als dort.
 */
function rang(status: string): string {
  if (status === 'succeeded') return 'ok'
  if (status === 'failed' || status === 'cancelled') return 'critical'

  return 'info'
}

function wort(status: string): string {
  if (status === 'succeeded') return 'fertig'
  if (status === 'failed') return 'fehlgeschlagen'
  if (status === 'cancelled') return 'abgebrochen'
  if (status === 'queued') return 'wartet'

  return 'läuft'
}

function aktualisieren(): void {
  router.reload()
}
</script>

<template>
  <div v-for="vorgang in props.items" :key="vorgang.id" class="band" :class="rang(vorgang.status)">
    <span>
      <b class="rank">Vorgang {{ vorgang.id }}</b>
      {{ vorgang.label }} — {{ wort(vorgang.status) }}<template
        v-if="vorgang.running && vorgang.progress > 0"
      > · {{ vorgang.progress }} %</template>
    </span>

    <!--
      **Der Knopf steht nur da, wenn er etwas tut.** Solange der Vorgang läuft,
      ist die Seite nicht veraltet — ein „Aktualisieren", das nichts ändert,
      erzieht dazu, es zu übersehen, wenn es etwas ändert.
    -->
    <button v-if="!vorgang.running" type="button" class="button small" @click="aktualisieren">
      Seite aktualisieren
    </button>

    <Link :href="`/operations/${vorgang.id}`" class="link">ansehen</Link>
  </div>
</template>
