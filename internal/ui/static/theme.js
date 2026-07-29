// theme.js — der manuelle Umschalter für Hell/Dunkel.
//
// Der Server rendert das Attribut data-theme am <html> bereits aus einem Cookie
// (asylum_theme), damit die Seite ohne Aufblitzen im richtigen Modus ankommt.
// Ohne Cookie steht kein Attribut da und es gilt die Systemeinstellung
// (@media prefers-color-scheme). Dieses Skript tut nur zweierlei: den Knopf
// einblenden (ohne JavaScript bliebe er wirkungslos, deshalb steht er auf
// hidden) und beim Klick den Modus umschalten — sofort am Attribut, dauerhaft
// im Cookie.
(function () {
  "use strict";

  var btn = document.getElementById("theme-toggle");
  if (!btn) {
    return;
  }

  var root = document.documentElement;

  // Der aktuell wirksame Modus: das gesetzte Attribut, sonst die
  // Systemeinstellung. Nur so trägt die Beschriftung von Anfang an das
  // Richtige, auch wenn noch kein Cookie existiert.
  function current() {
    var set = root.getAttribute("data-theme");
    if (set === "dark" || set === "light") {
      return set;
    }
    return window.matchMedia &&
      window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light";
  }

  // Die Beschriftung nennt, wohin der Klick führt — nicht den Ist-Zustand.
  function label() {
    // Kurz, weil der Knopf im Fuß der Symbolschiene steht und die nur gut
    // vier Zeichen breit ist. Was er tut, sagt zusätzlich das title-Attribut.
    var dunkel = current() === "dark";
    btn.textContent = dunkel ? "◐ Hell" : "◐ Dunkel";
    btn.title = dunkel ? "Auf hellen Modus umschalten" : "Auf dunklen Modus umschalten";
  }

  function setCookie(value) {
    // Ein Jahr haltbar, auf das ganze Panel bezogen, SameSite=Lax reicht: Der
    // Wert ist keine Berechtigung, nur eine Vorliebe.
    document.cookie =
      "asylum_theme=" + value + ";path=/;max-age=31536000;SameSite=Lax";
  }

  btn.hidden = false;
  label();

  btn.addEventListener("click", function () {
    var next = current() === "dark" ? "light" : "dark";
    root.setAttribute("data-theme", next);
    setCookie(next);
    label();
  });
})();
