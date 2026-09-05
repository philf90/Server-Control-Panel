<script setup lang="ts">
/*
 * Eine Ankündigung im vollen Wortlaut (A14, `docs/103 §4.3`).
 *
 * **Ohne `PanelLayout`, wie die Anmeldeseite.** Diese Seite ist auch für einen
 * Unangemeldeten erreichbar — sie darf also keine Navigation, keine
 * Fähigkeitsablage und keinen Kontonamen zeigen. `.signin-frame` ist die Form,
 * die dieses Panel dafür schon hat; eine eigene wäre eine zweite Fassung.
 *
 * **Der Text steht hier ungeklammert.** Die Zeilenklammer ist eine Eigenschaft
 * des Streifens und nicht des Textes — hierher kommt genau der, dem zwei Zeilen
 * nicht gereicht haben.
 */
import { Head, Link } from '@inertiajs/vue3'

defineProps<{
  announcement: { rank: string; badge: string; body: string }
  back: { url: string; label: string }
}>()
</script>

<template>
  <Head title="Ankündigung" />

  <div class="signin-frame">
    <main class="signin">
      <article class="sheet">
        <h1>{{ announcement.rank }}</h1>

        <!--
          Derselbe Rang wie im Streifen, in derselben Marke — der Leser kommt
          von dort und soll die Meldung wiedererkennen.
        -->
        <p class="notice" :class="announcement.badge">
          <span>{{ announcement.body }}</span>
        </p>

        <p><Link class="link" :href="back.url">{{ back.label }}</Link></p>
      </article>
    </main>
  </div>
</template>
