// passkey-register.js — die Registrierung eines Passkeys auf der Kontoseite.
//
// Der Server liefert bei /register/begin die Optionen für
// navigator.credentials.create und einen Token, unter dem die Challenge liegt;
// das Ergebnis geht an /register/finish. WebAuthn arbeitet mit ArrayBuffers, die
// Optionen kommen aber als base64url-Text über JSON — dieses Skript rechnet an
// den Grenzen um.
(function () {
  "use strict";

  var form = document.getElementById("passkey-add");
  if (!form || !window.PublicKeyCredential || !navigator.credentials) {
    // Ohne WebAuthn-Unterstützung bleibt das Formular ein gewöhnliches (und hier
    // wirkungsloses) Formular; der Nutzer sieht die Passkey-Verwaltung, kann
    // aber keinen anlegen. Das ist ehrlicher als ein verstecktes Feature.
    return;
  }

  var status = document.getElementById("pk-status");
  var csrf = form.getAttribute("data-csrf");

  function b64urlToBuf(s) {
    s = s.replace(/-/g, "+").replace(/_/g, "/");
    while (s.length % 4) {
      s += "=";
    }
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) {
      buf[i] = bin.charCodeAt(i);
    }
    return buf.buffer;
  }

  function bufToB64url(buf) {
    var bytes = new Uint8Array(buf);
    var s = "";
    for (var i = 0; i < bytes.length; i++) {
      s += String.fromCharCode(bytes[i]);
    }
    return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }

  function say(msg, kind) {
    status.hidden = false;
    status.textContent = msg;
    status.className = kind === "error" ? "hint warn-text" : "hint";
  }

  function form_encode(obj) {
    return Object.keys(obj)
      .map(function (k) {
        return encodeURIComponent(k) + "=" + encodeURIComponent(obj[k]);
      })
      .join("&");
  }

  function post(url, fields) {
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: form_encode(fields),
    });
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var label = document.getElementById("pk-label").value;
    var password = document.getElementById("pk-pass").value;
    say("Bestätige den Passkey auf deinem Gerät …");

    post("/alt/account/passkeys/register/begin", { _csrf: csrf, label: label, password: password })
      .then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.error || "Die Registrierung ließ sich nicht beginnen.");
          }
          return body;
        });
      })
      .then(function (body) {
        var token = body.token;
        var opt = body.publicKey;
        opt.challenge = b64urlToBuf(opt.challenge);
        opt.user.id = b64urlToBuf(opt.user.id);
        if (opt.excludeCredentials) {
          opt.excludeCredentials = opt.excludeCredentials.map(function (c) {
            return { id: b64urlToBuf(c.id), type: c.type, transports: c.transports };
          });
        }
        return navigator.credentials.create({ publicKey: opt }).then(function (cred) {
          var payload = {
            id: cred.id,
            rawId: bufToB64url(cred.rawId),
            type: cred.type,
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
            response: {
              clientDataJSON: bufToB64url(cred.response.clientDataJSON),
              attestationObject: bufToB64url(cred.response.attestationObject),
              transports: cred.response.getTransports ? cred.response.getTransports() : [],
            },
          };
          return post("/alt/account/passkeys/register/finish", {
            _csrf: csrf,
            token: token,
            label: label,
            credential: JSON.stringify(payload),
          });
        });
      })
      .then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.error || "Der Passkey ließ sich nicht speichern.");
          }
          window.location.reload();
        });
      })
      .catch(function (err) {
        // Ein Abbruch am Gerät (NotAllowedError) ist kein Fehler des Panels.
        if (err && err.name === "NotAllowedError") {
          say("Abgebrochen.", "error");
        } else {
          say((err && err.message) || "Es ist ein Fehler aufgetreten.", "error");
        }
      });
  });
})();
