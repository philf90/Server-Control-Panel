<script setup lang="ts">
/*
 * Das Gerüst beider Flächen. Der Unterschied zwischen Admin- und Kundenfläche
 * ist die Dichte, nicht die Gestaltung — umgeschaltet wird über ein Attribut
 * am Wurzelelement, das die Werte aus app.css umstellt (§7.2 des Plans).
 */
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

defineProps<{ title: string; subline?: string }>()

const page = usePage()
const source = computed(() => page.props.source as { repository: string; version: string; commit: string })

const navigation = [
  { group: null, items: [{ name: 'Übersicht', href: '/', active: true }] },
  {
    group: 'Server',
    items: [
      { name: 'Dienste', href: '#', active: false },
      { name: 'Pakete', href: '#', active: false },
      { name: 'Protokoll', href: '#', active: false },
    ],
  },
]
</script>

<template>
  <div class="frame">
    <aside class="nav">
      <div class="badge">
        <span class="glyph">C</span>
        <b>CloudSrv</b>
      </div>

      <nav>
        <template v-for="block in navigation" :key="block.group ?? 'oben'">
          <p v-if="block.group" class="group">{{ block.group }}</p>
          <Link
            v-for="item in block.items"
            :key="item.name"
            :href="item.href"
            :class="['item', { active: item.active }]"
          >
            {{ item.name }}
          </Link>
        </template>
      </nav>

      <!--
        Abschnitt 13 der AGPL: Wer die Software über das Netz benutzt, muss an
        den Quelltext der laufenden Fassung kommen. Deshalb Version und Commit
        im Link und nicht bloß die Adresse des Repositorys.
      -->
      <footer class="source">
        <a :href="source.commit ? `${source.repository}/tree/${source.commit}` : source.repository">
          Quelltext · {{ source.version }}
        </a>
      </footer>
    </aside>

    <main class="content">
      <header class="header">
        <h1>{{ title }}</h1>
        <span v-if="subline" class="meta">{{ subline }}</span>
      </header>

      <slot />
    </main>
  </div>
</template>

<style scoped>
.frame {
  display: grid;
  grid-template-columns: 186px 1fr;
  min-height: 100vh;
}

.nav {
  background: var(--nav-bg);
  border-right: 1px solid var(--nav-border);
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
}

.badge {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
}

.badge b {
  font-size: 13px;
  letter-spacing: -0.01em;
  color: var(--text-strong);
}

.glyph {
  width: 22px;
  height: 22px;
  border-radius: 3px;
  display: grid;
  place-items: center;
  background: var(--accent);
  color: var(--accent-on);
  font-size: 11px;
  font-weight: 700;
}

nav {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.group {
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--text-faint);
  margin: 16px 0 6px 9px;
}

.item {
  padding: 6px 9px;
  border-radius: 3px;
  text-decoration: none;
  color: var(--text-muted);
  font-size: 12.5px;
}

.item:hover {
  color: var(--text);
}

.item.an {
  background: var(--surface);
  color: var(--accent);
  box-shadow: inset 2px 0 0 var(--accent);
  border-radius: 0 3px 3px 0;
}

.source {
  margin-top: auto;
  padding-top: 20px;
  font-size: 11px;
}

.source a {
  color: var(--text-faint);
  text-decoration: none;
}

.source a:hover {
  color: var(--text-muted);
  text-decoration: underline;
}

.content {
  padding: 18px 22px 28px;
  min-width: 0;
}

.header {
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
  color: var(--text-strong);
}

.meta {
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--text-muted);
}
</style>
