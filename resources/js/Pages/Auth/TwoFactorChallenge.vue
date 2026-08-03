<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

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

      <label for="code">Code</label>
      <input
        id="code"
        v-model="form.code"
        type="text"
        name="code"
        inputmode="text"
        autocomplete="one-time-code"
        autofocus
        required
      >

      <p v-if="form.errors.code" class="fehler" role="alert">{{ form.errors.code }}</p>

      <button type="submit" :disabled="form.processing">
        {{ form.processing ? 'Einen Moment …' : 'Bestätigen' }}
      </button>
    </form>
  </main>
</template>

<style scoped>
.anmeldung { min-height: 100dvh; display: grid; place-items: center; padding: var(--padding); background: var(--bg); }
.maske { width: min(24rem, 100%); display: flex; flex-direction: column; gap: .4rem; padding: calc(var(--padding) * 1.5); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 10px; }
h1 { margin: 0 0 .3rem; font-size: 1.15rem; color: var(--text-strong); }
.hinweis { margin: 0 0 .8rem; font-size: .82rem; color: var(--text-muted); line-height: 1.5; }
label { font-size: .8rem; color: var(--text-muted); }
input { padding: .5rem .6rem; margin-bottom: .6rem; font: inherit; letter-spacing: .1em; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 6px; }
button { padding: .55rem; font: inherit; font-weight: 600; color: var(--accent-on); background: var(--accent); border: 0; border-radius: 6px; cursor: pointer; }
button:disabled { opacity: .6; cursor: default; }
.fehler { margin: 0 0 .5rem; padding: .5rem .6rem; font-size: .85rem; color: var(--critical); background: var(--critical-surface); border-radius: 6px; }
</style>
