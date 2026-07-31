// Fährt den Editor der Dateiseite in einem echten Browser.
//
// Zwei Fragen, die kein Go-Test beantworten kann:
//
//  1. Läuft CodeMirror unter der Content-Security-Policy des Panels? Die
//     Richtlinie erlaubt keine inline-Stile, und CodeMirror trägt seine Regeln
//     zur Laufzeit ein. Ob das über CSSOM geschieht (erlaubt) oder über ein
//     style-Attribut (verworfen), sagt nur der Browser.
//  2. Kommt der bearbeitete Inhalt wirklich beim Server an? Der Editor ersetzt
//     eine Textarea; das Zurückschreiben beim Absenden ist die Stelle, an der
//     ein Fehler unbemerkt bliebe.
//
// Aufruf über TestFilesEditorBrowser. Erwartete Umgebung:
//   ASYLUM_E2E_URL, ASYLUM_E2E_COOKIE, ASYLUM_E2E_PATH, ASYLUM_CHROMIUM
//   ASYLUM_NODE_PATH (Verzeichnis mit playwright)

const { chromium } = require("playwright");

// Von main gefüllt, damit der Fehlerpfad sie ausgeben kann.
const gesammelt = [];
let letztesHTML = "";

async function main() {
  const basis = process.env.ASYLUM_E2E_URL;
  const cookie = process.env.ASYLUM_E2E_COOKIE;
  const pfad = process.env.ASYLUM_E2E_PATH;

  const browser = await chromium.launch({
    executablePath: process.env.ASYLUM_CHROMIUM,
    args: ["--no-sandbox"],
  });
  const kontext = await browser.newContext({ ignoreHTTPSErrors: true });

  const [name, wert] = cookie.split("=");
  const url = new URL(basis);
  // sameSite ausdrücklich: Ohne die Angabe setzt Chromium "None", und ein
  // None-Cookie ohne Secure-Kennzeichen wird beim Absenden eines Formulars
  // verworfen — die Anmeldung galt dann nur für GET-Anfragen.
  await kontext.addCookies([
    {
      name,
      value: wert,
      domain: url.hostname,
      path: "/",
      httpOnly: true,
      secure: false,
      sameSite: "Strict",
    },
  ]);

  const seite = await kontext.newPage();
  const meldungen = [];
  const verstoesse = [];

  seite.on("console", (m) => {
    const text = m.text();
    meldungen.push(`${m.type()}: ${text}`);
    gesammelt.push(`${m.type()}: ${text}`);
    // Chromium meldet einen CSP-Verstoß als Konsolenfehler. Genau darum geht es
    // hier: Ein Editor, dessen Stile verworfen werden, ist unbenutzbar, und der
    // Fehler wäre auf einem Bildschirmfoto nicht zu sehen.
    if (/Content Security Policy|Refused to (apply|load|execute)/i.test(text)) {
      verstoesse.push(text);
    }
  });
  seite.on("pageerror", (e) => {
    meldungen.push(`pageerror: ${e.message}`);
    gesammelt.push(`pageerror: ${e.message}`);
    verstoesse.push(`pageerror: ${e.message}`);
  });

  const ziel = `${basis}/alt/files/edit?path=${encodeURIComponent(pfad)}`;
  const antwort = await seite.goto(ziel, { waitUntil: "networkidle" });
  if (!antwort || antwort.status() !== 200) {
    throw new Error(`Status ${antwort ? antwort.status() : "keine Antwort"} für ${ziel}`);
  }

  // CodeMirror muss die Textarea ersetzt haben.
  letztesHTML = (await seite.content()).slice(0, 4000);
  await seite.waitForSelector(".cm-editor", { timeout: 10000 });
  letztesHTML = (await seite.locator("#editor-halter").innerHTML().catch(() => "kein Halter")).slice(0, 2000);
  // Über evaluate statt über einen Locator: Der Inhalt von .cm-content ist ein
  // contenteditable, und locator.innerText wartet dort auf eine Bedingung, die
  // nie eintritt. Gebraucht wird nur der Text.
  const zeilennummern = await seite.locator(".cm-gutterElement").count();
  const inhaltVorher = await seite.evaluate(
    () => document.querySelector(".cm-content").innerText
  );

  // Prüfen, dass die Stile wirklich angekommen sind: Ohne die eingetragenen
  // Regeln hat der Editor keine Monospace-Schrift und keinen eigenen Rahmen.
  const stil = await seite.evaluate(() => {
    const el = document.querySelector(".cm-scroller");
    const s = getComputedStyle(el);
    return { fontFamily: s.fontFamily, gutter: getComputedStyle(document.querySelector(".cm-gutters")).borderRightWidth };
  });

  // Eine Zeile am Anfang einfügen und speichern.
  await seite.click(".cm-content");
  await seite.keyboard.press("Control+Home");
  await seite.keyboard.type("# vom Browser eingefügt\n");

  const [nachAntwort] = await Promise.all([
    seite.waitForNavigation({ waitUntil: "networkidle" }),
    // Ausdrücklich der Knopf im Speicherformular: Der erste submit-Knopf der
    // Seite steht in der Navigation und heißt "Abmelden".
    seite.click('form[action="/alt/files/save"] button[type="submit"]'),
  ]);
  const statusNachher = nachAntwort ? nachAntwort.status() : 0;
  letztesHTML = (await seite.content()).slice(0, 1500);

  const meldungNachher = await seite.evaluate(() => {
    const el = document.querySelector(".alert");
    return el ? el.innerText : "";
  });
  const inhaltNachher = await seite.evaluate(() => {
    const el = document.querySelector(".cm-content");
    return el ? el.innerText : "";
  });

  console.log(
    JSON.stringify({
      zeilennummern,
      inhaltVorher: inhaltVorher.slice(0, 120),
      inhaltNachher: inhaltNachher.slice(0, 160),
      stil,
      statusNachher,
      urlNachher: seite.url(),
      meldung: meldungNachher.slice(0, 200),
      verstoesse,
      meldungen,
    })
  );

  await browser.close();
}

// Bei einem Fehler zählen die Konsolenmeldungen mehr als die Ausnahme selbst:
// Ein verworfener Stil oder ein Skriptfehler steht dort, nicht im Stack.
main().catch((e) => {
  console.error("FEHLER: " + e.message);
  console.error("Konsole:\n  " + gesammelt.join("\n  "));
  console.error("HTML:\n" + letztesHTML);
  process.exit(1);
});
