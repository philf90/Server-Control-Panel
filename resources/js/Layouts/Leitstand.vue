<script setup lang="ts">
/*
 * Das Gerüst beider Flächen. Der Unterschied zwischen Admin- und Kundenfläche
 * ist die Dichte, nicht die Gestaltung — umgeschaltet wird über ein Attribut
 * am Wurzelelement, das die Werte aus app.css umstellt (§7.2 des Plans).
 */
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

defineProps<{ titel: string; unterzeile?: string }>()

const seite = usePage()
const quelle = computed(() => seite.props.quelle as { repository: string; version: string; commit: string })

const navigation = [
  { gruppe: null, punkte: [{ name: 'Übersicht', ziel: '/', aktiv: true }] },
  {
    gruppe: 'Server',
    punkte: [
      { name: 'Dienste', ziel: '#', aktiv: false },
      { name: 'Pakete', ziel: '#', aktiv: false },
      { name: 'Protokoll', ziel: '#', aktiv: false },
    ],
  },
]
</script>

<template>
  <div class="rahmen">
    <aside class="navigation">
      <div class="marke">
        <span class="zeichen">C</span>
        <b>CloudSrv</b>
      </div>

      <nav>
        <template v-for="block in navigation" :key="block.gruppe ?? 'oben'">
          <p v-if="block.gruppe" class="gruppe">{{ block.gruppe }}</p>
          <Link
            v-for="punkt in block.punkte"
            :key="punkt.name"
            :href="punkt.ziel"
            :class="['punkt', { an: punkt.aktiv }]"
          >
            {{ punkt.name }}
          </Link>
        </template>
      </nav>

      <!--
        Abschnitt 13 der AGPL: Wer die Software über das Netz benutzt, muss an
        den Quelltext der laufenden Fassung kommen. Deshalb Version und Commit
        im Link und nicht bloß die Adresse des Repositorys.
      -->
      <footer class="quelle">
        <a :href="quelle.commit ? `${quelle.repository}/tree/${quelle.commit}` : quelle.repository">
          Quelltext · {{ quelle.version }}
        </a>
      </footer>
    </aside>

    <main class="inhalt">
      <header class="kopf">
        <h1>{{ titel }}</h1>
        <span v-if="unterzeile" class="meta">{{ unterzeile }}</span>
      </header>

      <slot />
    </main>
  </div>
</template>

<style scoped>
.rahmen {
  display: grid;
  grid-template-columns: 186px 1fr;
  min-height: 100vh;
}

.navigation {
  background: var(--navigation);
  border-right: 1px solid var(--navigation-rand);
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
}

.marke {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
}

.marke b {
  font-size: 13px;
  letter-spacing: -0.01em;
  color: var(--text-stark);
}

.zeichen {
  width: 22px;
  height: 22px;
  border-radius: 3px;
  display: grid;
  place-items: center;
  background: var(--akzent);
  color: var(--akzent-auf);
  font-size: 11px;
  font-weight: 700;
}

nav {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.gruppe {
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--text-schwach);
  margin: 16px 0 6px 9px;
}

.punkt {
  padding: 6px 9px;
  border-radius: 3px;
  text-decoration: none;
  color: var(--text-ruhig);
  font-size: 12.5px;
}

.punkt:hover {
  color: var(--text);
}

.punkt.an {
  background: var(--bereich);
  color: var(--akzent);
  box-shadow: inset 2px 0 0 var(--akzent);
  border-radius: 0 3px 3px 0;
}

.quelle {
  margin-top: auto;
  padding-top: 20px;
  font-size: 11px;
}

.quelle a {
  color: var(--text-schwach);
  text-decoration: none;
}

.quelle a:hover {
  color: var(--text-ruhig);
  text-decoration: underline;
}

.inhalt {
  padding: 18px 22px 28px;
  min-width: 0;
}

.kopf {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

h1 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--text-stark);
}

.meta {
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--text-ruhig);
}
</style>
