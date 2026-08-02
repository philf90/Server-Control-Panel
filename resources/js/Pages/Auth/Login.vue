<script setup lang="ts">
import { useForm, Head, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/*
 * Die Anmeldemaske.
 *
 * Bewusst ohne Navigation und ohne Kacheln: Wer hier steht, hat noch kein
 * Konto vorgewiesen und soll nichts über den Server erfahren — nicht die
 * Fassung, nicht den Rechnernamen, nicht die Zahl der Kunden.
 */

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const page = usePage()
const hinweis = computed(() => (page.props.flash as Record<string, string> | undefined)?.hinweis)

function submit(): void {
  form.post('/anmeldung', {
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <Head title="Anmeldung" />

  <main class="anmeldung">
    <form class="maske" @submit.prevent="submit">
      <h1>SrvPanel</h1>

      <p v-if="hinweis" class="hinweis">{{ hinweis }}</p>

      <label for="email">Adresse</label>
      <input
        id="email"
        v-model="form.email"
        type="email"
        name="email"
        autocomplete="username"
        required
        autofocus
      >

      <label for="password">Passwort</label>
      <input
        id="password"
        v-model="form.password"
        type="password"
        name="password"
        autocomplete="current-password"
        required
      >

      <!--
        Eine Meldung für alles: unbekannte Adresse, falsches Passwort,
        deaktiviertes Konto. Wer unterscheidet, verrät, welche Adressen es
        gibt.
      -->
      <p v-if="form.errors.email" class="fehler" role="alert">{{ form.errors.email }}</p>

      <label class="merken">
        <input v-model="form.remember" type="checkbox" name="remember">
        <span>Angemeldet bleiben</span>
      </label>

      <button type="submit" :disabled="form.processing">
        {{ form.processing ? 'Einen Moment …' : 'Anmelden' }}
      </button>
    </form>
  </main>
</template>

<style scoped>
.anmeldung {
  min-height: 100dvh;
  display: grid;
  place-items: center;
  padding: var(--padding);
  background: var(--bg);
}

.maske {
  width: min(24rem, 100%);
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: calc(var(--padding) * 1.5);
  background: var(--surface);
  border: 1px solid var(--surface-border);
  border-radius: 10px;
}

h1 {
  margin: 0 0 0.75rem;
  font-size: 1.15rem;
  color: var(--text-strong);
}

label {
  font-size: 0.8rem;
  color: var(--text-muted);
}

input[type='email'],
input[type='password'] {
  padding: 0.5rem 0.6rem;
  margin-bottom: 0.5rem;
  font: inherit;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
}

input:focus-visible,
button:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 1px;
}

.merken {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0.35rem 0 0.9rem;
  color: var(--text);
}

button {
  padding: 0.55rem;
  font: inherit;
  font-weight: 600;
  color: var(--accent-on);
  background: var(--accent);
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: default;
}

.fehler {
  margin: 0 0 0.5rem;
  padding: 0.5rem 0.6rem;
  font-size: 0.85rem;
  color: var(--critical);
  background: var(--critical-surface);
  border-radius: 6px;
}

.hinweis {
  margin: 0 0 0.75rem;
  padding: 0.5rem 0.6rem;
  font-size: 0.85rem;
  color: var(--warn);
  background: var(--warn-surface);
  border-radius: 6px;
}
</style>
