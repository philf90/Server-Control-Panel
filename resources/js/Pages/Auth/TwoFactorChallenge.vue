<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import CodeField from '../../Components/CodeField.vue'

/*
 * Der zweite Schritt. Bewusst ohne Navigation: Wer hier steht, ist noch nicht
 * angemeldet.
 */
const form = useForm({ code: '' })

function submit(): void {
  form.post('/two-factor', { onFinish: () => form.reset('code') })
}
</script>

<template>
  <Head title="Bestätigung" />

  <main class="anmeldung">
    <form class="maske" @submit.prevent="submit">
      <h1>Bestätigung</h1>
      <p class="hinweis">
        Der sechsstellige Code aus Ihrer Authenticator-App. Wenn Sie kein Gerät
        zur Hand haben, geht auch einer Ihrer Wiederherstellungscodes.
      </p>

      <CodeField
        v-model="form.code"
        autofocus
        hint="Sechs Ziffern — oder ein Wiederherstellungscode in der Form ABCDE-FGHIJ."
        :error="form.errors.code"
      />

      <button type="submit" class="knopf wichtig" :disabled="form.processing">
        {{ form.processing ? 'Einen Moment …' : 'Bestätigen' }}
      </button>
    </form>
  </main>
</template>

<style scoped>
.anmeldung { min-height: 100dvh; display: grid; place-items: center; padding: 16px; background: var(--bg); }

/*
 * Hier stand `padding: calc(var(--padding) * 1.5)`, und diese Karte hatte
 * deshalb überhaupt keinen Innenabstand. `--padding` ist eine Kurzform mit
 * drei Werten (`12px 13px 10px`); `calc()` rechnet nur mit einem einzelnen.
 * Was daraus wird, ist kein Fehler, den der Übersetzer meldet, sondern eine
 * ungültige Deklaration — und eine ungültige Deklaration fällt still auf den
 * Ausgangswert zurück, hier auf null. Überschrift und Knopf klebten an der
 * Kante. Feste Werte wie in der Anmeldemaske: Diese Seite steht ausserhalb
 * der Dichteumschaltung, sie hat kein Panel um sich herum.
 */
.maske { width: min(384px, 100%); display: flex; flex-direction: column; gap: 14px; padding: 20px 22px 22px; background: var(--surface); border: 1px solid var(--surface-border); border-radius: 10px; }
h1 { margin: 0; font-size: var(--text-heading); color: var(--text-strong); }
.hinweis { margin: -8px 0 0; font-size: var(--text-small); color: var(--text-muted); line-height: 1.5; }
</style>
