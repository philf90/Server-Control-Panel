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

  <main class="signin">
    <form class="sheet" @submit.prevent="submit">
      <h1>Bestätigung</h1>

      <p class="hint">
        Der sechsstellige Code aus Ihrer Authenticator-App. Wenn Sie kein Gerät
        zur Hand haben, geht auch einer Ihrer Wiederherstellungscodes.
      </p>

      <CodeField
        v-model="form.code"
        autofocus
        hint="Sechs Ziffern — oder ein Wiederherstellungscode in der Form ABCDE-FGHIJ."
        :error="form.errors.code"
      />

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Einen Moment …' : 'Bestätigen' }}
        </button>
      </div>
    </form>
  </main>
</template>
