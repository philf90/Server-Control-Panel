// Tippt Passwörter in die Kontoseite und gibt zurück, was die Prüfliste sagt.
//
// Der Grund: Die Regeln stehen zweimal — in Go (auth.CheckPasswordPolicy, die
// verbindliche Prüfung) und in passwort.js (die Anzeige beim Tippen). Zwei
// Fassungen derselben Regel laufen auseinander, und dann zeigt die Seite grün,
// während der Server ablehnt. Der Go-Test vergleicht sein eigenes Urteil mit dem
// hier gemeldeten.
//
// Erwartete Umgebung: ASYLUM_E2E_URL, ASYLUM_E2E_COOKIE, ASYLUM_E2E_PROBEN
// (JSON-Liste von Passwörtern), ASYLUM_CHROMIUM, ASYLUM_NODE_PATH.

const { chromium } = require("playwright");

async function main() {
  const proben = JSON.parse(process.env.ASYLUM_E2E_PROBEN);
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
  seite.on("console", (m) => {
    if (/Content Security Policy|Refused to/i.test(m.text())) {
      verstoesse.push(m.text());
    }
  });
  seite.on("pageerror", (err) => verstoesse.push("Skriptfehler: " + err.message));

  await seite.goto(basis + "/alt/account", { waitUntil: "load" });

  // Vor der ersten Eingabe darf keine Regel rot sein: Ein leeres Feld mit
  // Kreuzen sieht aus wie eine Ablehnung.
  const leer = await ablesen(seite);

  const ergebnisse = [];
  for (const probe of proben) {
    await seite.fill("#new_password", probe);
    // Das Ereignis "input" läuft synchron; ein Tick genügt.
    await seite.waitForTimeout(30);
    ergebnisse.push(await ablesen(seite));
  }

  console.log(JSON.stringify({ verstoesse, leer, ergebnisse }));
  await browser.close();
}

async function ablesen(seite) {
  return await seite.evaluate(() => {
    const box = document.querySelector(".pwcheck");
    const regeln = {};
    box.querySelectorAll("[data-pw-regel]").forEach((li) => {
      regeln[li.dataset.pwRegel] = li.classList.contains("erfuellt")
        ? "erfuellt"
        : li.classList.contains("verletzt")
          ? "verletzt"
          : li.classList.contains("unentschieden")
            ? "unentschieden"
            : "neutral";
    });
    return {
      wort: box.querySelector(".pwwort").textContent.trim(),
      balken: Number(box.querySelector(".pwbalken").value),
      klasse: box.querySelector(".pwbalken").className.replace("bar pwbalken", "").trim(),
      regeln,
      // Die Zahlen im Markup stammen aus auth.Policy(); der Go-Test vergleicht sie.
      min: Number(box.dataset.pwMin),
      max: Number(box.dataset.pwMax),
      name: box.dataset.pwName,
    };
  });
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
