<script setup lang="ts">
/*
 * Die Zeichen der Handlungen — vier, und der Satz ist geschlossen.
 *
 * **Warum das nicht `NavIcon` ist.** Dort steht der Satz der *Navigation*, und
 * `NavIconTest` hält ihn eins zu eins gegen die Menüpunkte in `PanelLayout`:
 * jedes Zeichen einem Eintrag, jeder Eintrag einem Zeichen. Eine Zeichnung für
 * einen Knopf wäre dort eine Waise und machte den Wächter rot — zu Recht, denn
 * er beantwortet eine andere Frage.
 *
 * **Dieselben Regeln wie dort, weil sie nebeneinander stehen.** 24er-Raster,
 * `stroke-width: 1.6`, keine Fläche, Farbe über `currentColor`. Gemischte
 * Zeichnungen sehen nach zwei Sätzen aus, und der Blick liest daraus eine
 * Bedeutung, die es nicht gibt.
 *
 * **Sie tragen keine Bedeutung allein.** Neben jedem Zeichen steht sein Wort —
 * das ist der Grund, aus dem diese Knöpfe *nicht* zu reinen Symbolen geworden
 * sind, obwohl das gemessen zwölf Pixel gespart hätte (`docs/64 §12`). Dass die
 * beiden Anlegen-Knöpfe auf der schmalen Fläche nur ihr Objekt nennen
 * („Verzeichnis", „Datei"), trägt das **Plus** in der Zeichnung mit; das ganze
 * Wort steht dort als `.verb` für die Vorlesesoftware und ab 480 px auch
 * sichtbar.
 */

/**
 * Die Zeichnungen, ein Pfad je Name.
 *
 * Die Namen sind die Sache, die sie zeigen, und nicht die Form — `upload` und
 * nicht `arrow-up`. Beim nächsten Umzeichnen bleibt der Name dann stehen.
 */
const PATHS: Record<string, string> = {
  // Verzeichnis anlegen: die Mappe mit einem Plus darin. Das Plus ist hier der
  // Unterschied zwischen „ein Verzeichnis" und „ein Verzeichnis anlegen".
  directory: 'M3 6.5A1.5 1.5 0 014.5 5h4l2 2.5h9A1.5 1.5 0 0121 9v9a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 18zM12 11.5v6M9 14.5h6',

  // Datei anlegen: das Blatt mit der umgeknickten Ecke — dieselbe Zeichnung wie
  // im Menü, damit „Dateien" und „Datei anlegen" sichtbar zusammengehören — und
  // demselben Plus.
  file: 'M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5M12 12v6M9 15h6',

  // Hochladen: der Pfeil aus der Schale heraus. Nach oben, weil die Datei den
  // Rechner verlässt.
  upload: 'M12 16V4M8 8l4-4 4 4M4 17v2a1 1 0 001 1h14a1 1 0 001-1v-2',

  // Suchen: die Lupe. Das eine Zeichen, bei dem die Verkehrsform stärker ist
  // als jede eigene Idee.
  search: 'M11 4a7 7 0 100 14 7 7 0 000-14zM20 20l-4-4',
}

const props = defineProps<{ name: string }>()

const path = (): string => PATHS[props.name] ?? ''
</script>

<template>
  <!--
    `aria-hidden`: Die Vorlesesoftware liest den Knopf, nicht seine Verzierung.
  -->
  <svg
    class="action-icon"
    viewBox="0 0 24 24"
    width="20"
    height="20"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path :d="path()" />
  </svg>
</template>
