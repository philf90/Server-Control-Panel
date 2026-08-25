<script setup lang="ts">
/*
 * Die Zeichen der Navigation — einer je Menüpunkt.
 *
 * **Warum sie hier als Pfade stehen und nicht als Dateien geladen werden.**
 * Dasselbe wie beim Zeichen des Panels und beim Auge: Ein `<img>` wäre ein
 * Aufruf je Symbol und könnte vor allem seine Farbe nicht von der Umgebung
 * erben. Genau das braucht es hier — der aktive Eintrag trägt `--accent`, die
 * übrigen `--text`, und das Zeichen läuft über `currentColor` mit.
 *
 * **Warum keine Symbolbibliothek.** Zwölf Zeichen sind zwölf Zeilen Pfad; ein
 * Paket dafür wäre eine Abhängigkeit, ein Bündel und eine Auswahl von tausend
 * Symbolen, aus der beim nächsten Menüpunkt jemand ein anderes Stilmittel
 * greift. Der Satz hier ist geschlossen: Wer einen Menüpunkt hinzufügt, zeichnet
 * sein Symbol dazu — und `NavIconTest` besteht darauf.
 *
 * **Eine Strichstärke, ein Raster, kein Füllen.** Alle Pfade laufen im
 * 24er-Raster mit `stroke-width: 1.6` und ohne Fläche. Gemischte Zeichnungen —
 * hier ein gefülltes, dort ein umrissenes — sehen in einer Spalte untereinander
 * nach zwei Sätzen aus, und der Blick liest daraus eine Bedeutung, die es nicht
 * gibt.
 *
 * **Sie tragen keine Bedeutung allein.** Neben jedem Zeichen steht sein Wort;
 * das Zeichen ist eine Wiedererkennungshilfe und kein Ersatz (WCAG 1.1.1).
 * Deshalb `aria-hidden`: Ein Screenreader liest den Menüpunkt, nicht seine
 * Verzierung.
 */

/**
 * Die Zeichnungen, ein Pfad je Name.
 *
 * Die Namen sind die Sache, die sie zeigen, und nicht die Form: `customers`
 * und nicht `two-persons`. Beim nächsten Umzeichnen bleibt der Name dann
 * stehen.
 */
const PATHS: Record<string, string> = {
  // Übersicht: vier Felder — das Raster der Kacheln.
  overview: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',

  // Kunden: zwei Personen, eine davor.
  customers: 'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM2.5 20a6.5 6.5 0 0113 0M16 4.6a3.5 3.5 0 010 6.8M18 20a6.5 6.5 0 00-2-4.7',

  // Pläne: ein Blatt mit Zeilen — die Ausstattung, die man vergleicht.
  plans: 'M6 3h12v18H6zM9.5 8h5M9.5 12h5M9.5 16h3',

  // Abonnements: ein Paket. Das Ding, das der Kunde gebucht hat.
  subscriptions: 'M12 3l8 4.2v9.6L12 21l-8-4.2V7.2zM4 7.2l8 4.2 8-4.2M12 11.4V21',

  // Domains: ein Globus mit Meridian und Äquator.
  domains: 'M12 3a9 9 0 100 18 9 9 0 000-18zM3 12h18M12 3c2.5 2.4 3.8 5.4 3.8 9s-1.3 6.6-3.8 9c-2.5-2.4-3.8-5.4-3.8-9S9.5 5.4 12 3z',

  // Dateien: das Blatt mit der umgeknickten Ecke. Ein Ordner wäre die
  // naheliegende Zeichnung und die falsche — der Griff führt in einen Baum aus
  // Dateien **und** Verzeichnissen, und das Blatt ist die kleinere Einheit
  // davon. Der Knick ist der Teil, den man auf 18px noch erkennt.
  files: 'M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5',

  // Datenbanken: der Stapel Scheiben. Das ist das eine Symbol, bei dem die
  // Verkehrsform stärker ist als jede eigene Idee — wer drei gestapelte
  // Ellipsen sieht, denkt an eine Datenbank und an nichts sonst.
  databases: 'M12 3c4.4 0 8 1.3 8 3s-3.6 3-8 3-8-1.3-8-3 3.6-3 8-3zM4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',

  // Vorgänge: ein Kreislauf mit Pfeilspitze — etwas, das läuft.
  operations: 'M20 12a8 8 0 11-2.4-5.7M20 3v4h-4',

  // Protokoll: Zeilen mit Punkten davor — Einträge untereinander.
  log: 'M5 6h.01M5 12h.01M5 18h.01M9.5 6H19M9.5 12H19M9.5 18H19',

  /*
   * Logs des Servers: ein Blatt mit umgeknickter Ecke und zwei Zeilen.
   *
   * **Bewusst nicht dasselbe wie `log`.** Die beiden Punkte stehen in
   * derselben Gruppe — „Protokoll" ist, was das Panel getan hat, „Logs" ist,
   * was auf dem Server steht. Bei „Datenbanken" und „Datenbankserver" durfte
   * ein Zeichen zweimal stehen, weil die **Gruppen** den Unterschied machen;
   * hier täte es das nicht, und zwei gleiche Zeichen untereinander lesen sich
   * als derselbe Ort.
   */
  logfile: 'M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6',

  // PHP-Versionen: spitze Klammern, das Zeichen für Quelltext.
  php: 'M8.5 8L5 12l3.5 4M15.5 8L19 12l-3.5 4',

  // Mailversand: ein Umschlag.
  mail: 'M3 6h18v12H3zM3 6.5l9 6.5 9-6.5',

  // Zertifikat: ein Schild.
  tls: 'M12 3l7 2.6v5.6c0 4.2-2.8 7.6-7 9.8-4.2-2.2-7-5.6-7-9.8V5.6z',

  // DNS-Zugangsdaten: ein Schlüssel — der Bart rechts, der Griff als Ring.
  // Nicht noch ein Globus: `domains` ist schon einer, und zwei Kreise
  // nebeneinander im selben Menü unterscheidet im Vorbeigehen niemand.
  dns: 'M7.5 14.5a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM10.9 10.2H20M17 10.2v3M20 10.2v2.4',

  // Allgemein: eine Uhr — was diese Seite bisher trägt, ist die Anzeigezone.
  // Kein Zahnrad: Das steht auf jeder zweiten Oberfläche für „Einstellungen"
  // überhaupt, und hier ist schon die ganze Gruppe eine.
  general: 'M12 20a8 8 0 100-16 8 8 0 000 16zM12 8v4l2.5 2.5',

  // SFTP-Zugang: zwei Pfeile gegeneinander — die Übertragung.
  //
  // **Kein Schlüssel**, obwohl der Zugang an einem hängt: `dns` ist schon einer
  // („der Bart rechts, der Griff als Ring"), und zwei Schlüssel in derselben
  // Spalte unterscheidet im Vorbeigehen niemand — dasselbe Argument, das dort
  // gegen einen zweiten Globus stand.
  //
  // Und kein zweites Blatt: `files` ist eines, und der Unterschied zwischen den
  // beiden Punkten ist nicht *was* man sieht, sondern *wie* man herankommt. Die
  // Richtung ist die Sache, und zwei Pfeile sind das Einzige, was sie auf 17px
  // noch zeigt.
  sftp: 'M9 20V5M5.5 8.5L9 5l3.5 3.5M15 4v15M18.5 15.5L15 19l-3.5-3.5',
  cron: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 7v5l3.5 2',

  // Mein Konto: eine Person.
  account: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4.5 20a7.5 7.5 0 0115 0',

  /*
   * Konten: eine Person mit einem Schlüssel — Adminkonten, nicht Kunden.
   *
   * Es ist bewusst weder `account` (eine Person, „Mein Konto") noch `customers`
   * (zwei Personen). Beide gibt es schon, und ein Zeichen, das an zwei Stellen
   * steht, sagt an der zweiten nichts mehr. Der Schlüssel ist das, was diese
   * Liste von den anderen unterscheidet: Wer hier steht, darf an den Server.
   */
  accounts: 'M8.5 11.5a4 4 0 100-8 4 4 0 000 8zM2 20a6.5 6.5 0 0113 0M18 16.5a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5zM19.9 14.25H22.5M21.4 14.25v1.9',

  /*
   * Zugang: ein Schloss. Nicht `tls` (der Schild — das ist die Echtheit einer
   * Verbindung) und nicht `sftp` (der Schlüssel am Konto). Hier geht es um die
   * Tür selbst: wer überhaupt herein darf.
   */
  access: 'M6 10.5V7.5a6 6 0 0112 0v3M5 10.5h14v9H5zM12 14v2',
}

const props = withDefaults(defineProps<{ name: string; size?: number }>(), { size: 17 })

/*
 * Ein unbekannter Name zeichnet nichts, statt zu raten.
 *
 * Ein Ersatzzeichen wäre die schlechtere Antwort: Es sähe aus wie eine
 * Entscheidung, und der Tippfehler bliebe. `NavIconTest` fängt den Fall vorher
 * ab — hier steht nur, was geschieht, wenn er es doch nicht tut.
 */
const path = () => PATHS[props.name] ?? ''
</script>

<template>
  <svg
    v-if="path()"
    class="nav-icon"
    :width="props.size"
    :height="props.size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path :d="path()" />
  </svg>
</template>

<style scoped>
.nav-icon {
  flex: none;

  /* Etwas ruhiger als das Wort daneben: Das Zeichen führt das Auge, es soll
     nicht mit der Beschriftung um sie streiten. Der aktive Eintrag hebt beides
     zusammen an, weil dort `currentColor` der Akzent ist. */
  opacity: 0.75;
}
</style>
