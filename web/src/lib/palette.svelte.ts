// Der offene Zustand der Befehlspalette.
//
// Er liegt außerhalb der Komponente, weil zwei Stellen ihn brauchen: die Palette
// selbst und der Hinweis im Statusband, der sie öffnet. Ihn durch die Schale
// weiterzugeben wäre eine Kette aus drei Komponenten, von denen zwei nichts
// damit zu tun haben.

class PaletteStand {
  offen = $state(false);

  /** vorher hält das Element, das den Fokus hatte, damit er beim Schließen
   *  dorthin zurückgeht. Ohne das landet er am Seitenanfang, und wer mit der
   *  Tastatur arbeitet, fängt von vorn an. */
  #vorher: HTMLElement | null = null;

  oeffnen(): void {
    if (this.offen) return;
    this.#vorher = document.activeElement as HTMLElement | null;
    this.offen = true;
  }

  schliessen(): void {
    if (!this.offen) return;
    this.offen = false;
    this.#vorher?.focus();
    this.#vorher = null;
  }

  umschalten(): void {
    if (this.offen) {
      this.schliessen();
    } else {
      this.oeffnen();
    }
  }
}

export const palette = new PaletteStand();
