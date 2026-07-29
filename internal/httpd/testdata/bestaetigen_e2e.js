// Fährt die Rückfrage vor dem Löschen in einem echten Browser.
//
// Der Anlass ist ein Befund, den nur ein Browser liefern konnte: Dreizehn
// Formulare trugen ein onsubmit="return confirm(…)", und keines hat je gefragt —
// die Content-Security-Policy verwirft Inline-Handler. Ein Go-Test sieht das
// Attribut im Markup und ist zufrieden.
//
// Geprüft wird deshalb hier, was ein Mensch sieht und tut:
//
//  1. Erscheint der Dialog überhaupt, und beschwert sich die CSP dabei nicht?
//  2. Ist "abbrechen" wirklich ein Abbruch — kein POST, keine Navigation?
//  3. Sperrt die getippte Bestätigung den Knopf, bis das Wort stimmt?
//  4. Kommt die Aktion nach dem Bestätigen tatsächlich an?
//
// Erwartete Umgebung: ASYLUM_E2E_URL, ASYLUM_E2E_COOKIE, ASYLUM_E2E_DATEI,
// ASYLUM_E2E_ORDNER, ASYLUM_CHROMIUM, ASYLUM_NODE_PATH.

const { chromium } = require("playwright");

async function main() {
  const basis = process.env.ASYLUM_E2E_URL;
  const [name, wert] = process.env.ASYLUM_E2E_COOKIE.split("=");
  const url = new URL(basis);

  const browser = await chromium.launch({
    executablePath: process.env.ASYLUM_CHROMIUM,
    args: ["--no-sandbox"],
  });
  const kontext = await browser.newContext({ ignoreHTTPSErrors: true });
  await kontext.addCookies([
    { name, value: wert, domain: url.hostname, path: "/", httpOnly: true, secure: false, sameSite: "Strict" },
  ]);

  const seite = await kontext.newPage();
  const verstoesse = [];
  // window.confirm und Co. wären der alte Weg. Sie dürfen nicht auftauchen: Ein
  // <dialog> aus dem Skript ist gestaltbar, trägt das Eingabefeld und wird von
  // keiner Richtlinie verworfen.
  let nativeDialoge = 0;
  seite.on("console", (m) => {
    if (/Content Security Policy|Refused to/i.test(m.text())) verstoesse.push(m.text());
  });
  seite.on("pageerror", (err) => verstoesse.push("Skriptfehler: " + err.message));
  seite.on("dialog", async (d) => {
    nativeDialoge++;
    await d.dismiss();
  });

  const eintrag = (p) => basis + "/files/entry?path=" + encodeURIComponent(p);

  // --- 1. Eine Datei: zweite Stufe, ein Knopf genügt ------------------------
  await seite.goto(eintrag(process.env.ASYLUM_E2E_DATEI), { waitUntil: "load" });
  const urlVorher = seite.url();
  await seite.click('form[action="/files/delete"] button');
  await seite.waitForTimeout(120);

  const datei = await seite.evaluate(() => {
    const d = document.querySelector("dialog.frage-dialog");
    return {
      offen: !!(d && d.open),
      frage: d ? d.querySelector(".frage-text").textContent : "",
      tippfeldSichtbar: d ? !d.querySelector(".frage-tippen").hidden : false,
      knopfGesperrt: d ? d.querySelector("button.danger").disabled : null,
      // Ein Modal muss den Rest der Seite abdecken, sonst klickt man daneben
      // weiter. ::backdrop lässt sich nicht abfragen — die Modalität schon.
      modal: d ? d.matches(":modal") : false,
    };
  });
  datei.urlUnveraendert = seite.url() === urlVorher;

  // Abbrechen: kein POST, kein Seitenwechsel, die Datei bleibt.
  await seite.click("dialog.frage-dialog button.secondary");
  await seite.waitForTimeout(120);
  const nachAbbruch = await seite.evaluate(() => {
    const d = document.querySelector("dialog.frage-dialog");
    return { offen: !!(d && d.open) };
  });
  nachAbbruch.urlUnveraendert = seite.url() === urlVorher;

  // Escape ist derselbe Abbruch.
  await seite.click('form[action="/files/delete"] button');
  await seite.waitForTimeout(80);
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(80);
  const nachEscape = await seite.evaluate(() => {
    const d = document.querySelector("dialog.frage-dialog");
    return { offen: !!(d && d.open) };
  });
  nachEscape.urlUnveraendert = seite.url() === urlVorher;
  // Und die Datei liegt noch da. Das muss hier gefragt werden und nicht später
  // im Go-Test: Der Treiber löscht sie gleich darauf mit Bestätigung, und ein
  // Blick aufs Dateisystem danach sähe beide Fälle gleich.
  nachEscape.eintragStatus = await seite.evaluate(
    async (u) => (await fetch(u, { headers: { Accept: "text/html" } })).status,
    eintrag(process.env.ASYLUM_E2E_DATEI),
  );

  // Und jetzt bestätigen: Das POST geht raus, die Seite wechselt in die Liste.
  await seite.click('form[action="/files/delete"] button');
  await seite.waitForTimeout(80);
  await Promise.all([
    seite.waitForNavigation({ waitUntil: "load" }),
    seite.click("dialog.frage-dialog button.danger"),
  ]);
  const nachBestaetigung = { gelandetAuf: new URL(seite.url()).pathname };

  // --- 2. Ein Ordner mit Inhalt: dritte Stufe ------------------------------
  await seite.goto(eintrag(process.env.ASYLUM_E2E_ORDNER), { waitUntil: "load" });
  await seite.click('form[action="/files/delete"] button');
  await seite.waitForTimeout(120);

  const lesen = () =>
    seite.evaluate(() => {
      const d = document.querySelector("dialog.frage-dialog");
      return {
        tippfeldSichtbar: !d.querySelector(".frage-tippen").hidden,
        knopfGesperrt: d.querySelector("button.danger").disabled,
        hinweis: d.querySelector(".frage-label").textContent,
      };
    });

  const ordner = { leer: await lesen() };
  await seite.fill("#frage-eingabe", "falsch");
  ordner.falsch = await lesen();
  // Die richtige Schreibweise, nur groß: Auf einem Telefon macht die Tastatur
  // genau das. Der Server vergleicht ohne Rücksicht darauf, der Dialog auch.
  await seite.fill("#frage-eingabe", process.env.ASYLUM_E2E_ORDNER.split("/").pop().toUpperCase());
  ordner.grossKlein = await lesen();

  await Promise.all([
    seite.waitForNavigation({ waitUntil: "load" }),
    seite.click("dialog.frage-dialog button.danger"),
  ]);
  ordner.gelandetAuf = new URL(seite.url()).pathname;

  // --- 3. Die Angabe am Knopf, nicht am Formular ---------------------------
  //
  // Auf Panel-Zugänge teilen drei Knöpfe ein Formular; welcher gedrückt wurde,
  // entscheidet über formaction, welche Zurücksetzung gemeint ist. Der Dialog
  // muss deshalb den Knopf lesen — und nach dem Bestätigen darf nicht das
  // Formularziel gewinnen. Ein form.submit() täte genau das: Statt der Passkeys
  // wäre das Passwort zurückgesetzt.
  await seite.goto(basis + "/users", { waitUntil: "load" });
  await seite.fill("#owner_password", process.env.ASYLUM_E2E_PW);
  await seite.click('button[formaction="/users/reset-passkeys"]');
  await seite.waitForTimeout(150);
  const knopf = await seite.evaluate(() => {
    const d = document.querySelector("dialog.frage-dialog");
    return {
      offen: !!(d && d.open),
      titel: d.querySelector(".frage-titel").textContent,
      frage: d.querySelector(".frage-text").textContent,
    };
  });
  await Promise.all([
    seite.waitForNavigation({ waitUntil: "load" }),
    seite.click("dialog.frage-dialog button.danger"),
  ]);
  knopf.gelandetAuf = new URL(seite.url()).pathname;
  knopf.meldung = await seite.evaluate(() => {
    const a = document.querySelector(".alert");
    return a ? a.textContent.trim() : "";
  });

  console.log(
    JSON.stringify({ verstoesse, nativeDialoge, datei, nachAbbruch, nachEscape, nachBestaetigung, ordner, knopf }),
  );
  await browser.close();
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
