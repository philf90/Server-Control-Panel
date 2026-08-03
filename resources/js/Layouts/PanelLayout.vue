<script setup lang="ts">
/*
 * Das Gerüst beider Flächen. Der Unterschied zwischen Admin- und Kundenfläche
 * ist die Dichte, nicht die Gestaltung — umgeschaltet wird über ein Attribut
 * am Wurzelelement, das die Werte aus app.css umstellt (§7.2 des Plans).
 */
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

defineProps<{ title: string; subline?: string }>()

const page = usePage()
const source = computed(() => page.props.source as { repository: string; version: string; commit: string })
const account = computed(() => page.props.account as { name: string; is_admin: boolean } | null)
const impersonation = computed(() => page.props.impersonation as { active: boolean; admin: string } | null)

/*
 * Die Navigation kommt aus dem Kontotyp, nicht aus einer Rechteprüfung im
 * Menü. Das ist ausdrücklich keine Autorisierung — die sitzt an der Aktion
 * (§6.2.2). Ein Kunde, der eine Adminadresse von Hand einträgt, wird von der
 * Policy abgewiesen; hier geht es nur darum, ihm keinen Weg zu zeigen, der
 * ohnehin nicht seiner ist.
 */
const current = computed(() => page.url.split('?')[0])

const navigation = computed(() => {
  if (account.value?.is_admin === false) {
    return [
      { group: null, items: [{ name: 'Übersicht', href: '/' }] },
      { group: 'Konto', items: [{ name: 'Vorgänge', href: '/operations' }, { name: 'Protokoll', href: '/audit' }] },
    ]
  }

  return [
    { group: null, items: [{ name: 'Übersicht', href: '/' }] },
    { group: 'Verwaltung', items: [{ name: 'Kunden', href: '/customers' }] },
    { group: 'Server', items: [{ name: 'Vorgänge', href: '/operations' }, { name: 'Protokoll', href: '/audit' }] },
  ]
})

function signOut(): void {
  router.post('/logout')
}

function stopImpersonation(): void {
  router.post('/impersonation/stop')
}
</script>

<template>
  <div class="frame">
    <!--
      Das Band aus §6.3. Es steht über allem, ist nicht wegklickbar und trägt
      den Rückweg bei sich — ein Wechsel, aus dem man suchen muss, ist einer,
      den jemand vergisst.
    -->
    <div v-if="impersonation?.active" class="band">
      <span>
        Sie arbeiten in der Sicht dieses Kunden.
        Angemeldet als <b>{{ account?.name }}</b>, gewechselt von <b>{{ impersonation.admin }}</b>.
      </span>
      <button type="button" @click="stopImpersonation">Zurück zur Verwaltung</button>
    </div>

    <aside class="nav">
      <div class="badge">
        <!-- „C" stand hier bis August 2026 — von CloudSrv, dem verworfenen Namen. -->
        <span class="glyph">S</span>
        <b>SrvPanel</b>
      </div>

      <nav>
        <template v-for="block in navigation" :key="block.group ?? 'oben'">
          <p v-if="block.group" class="group">{{ block.group }}</p>
          <Link
            v-for="item in block.items"
            :key="item.name"
            :href="item.href"
            :class="['item', { active: current === item.href }]"
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
      <div v-if="account" class="account">
        <span class="name">{{ account.name }}</span>
        <button type="button" class="signout" @click="signOut">Abmelden</button>
      </div>

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
  grid-template-rows: auto 1fr;
  min-height: 100vh;
}

.band {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: .5rem var(--padding);
  font-size: .85rem;
  color: var(--warn);
  background: var(--warn-surface);
  border-bottom: 1px solid var(--warn);
}

.band button {
  padding: .25rem .6rem;
  font: inherit;
  font-size: .8rem;
  color: var(--warn);
  background: transparent;
  border: 1px solid var(--warn);
  border-radius: 5px;
  cursor: pointer;
}

.account {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
  padding: .5rem 0;
  font-size: .78rem;
  color: var(--text-muted);
  border-top: 1px solid var(--nav-border);
}

.account .signout {
  padding: 0;
  font: inherit;
  font-size: .78rem;
  color: var(--text-faint);
  background: none;
  border: 0;
  cursor: pointer;
}

.account .signout:hover { color: var(--text); }

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

.item.active {
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
