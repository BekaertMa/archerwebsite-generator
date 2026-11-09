/**
 * Intake-agent op het startscherm.
 *
 * Links het gesprek: de agent werkt een agenda af in vijf blokken (de zaak, klanten en doel,
 * vertrouwen, pagina's, stijl en beeld) en stelt één vraag per beurt. Bij sommige vragen staat
 * een kaart in het gesprek: stijlen, een kleurpalet, een paginalijst om aan te vinken, of een vak
 * om het logo en eigen foto's op te laden. Rechts bouwt een live voorbeeld de website op uit wat
 * de klant vertelt (naam, diensten, stijl, kleur, logo, foto's, pagina's).
 *
 * Zodra de verplichte velden er zijn, kan de klant starten; "Maak mijn website" doet dan in één
 * run de sitemap (UI.fastGenerate), zet stijl, logo en eigen foto's klaar (aisb_archer_intake_save)
 * en opent het scherm Ontwerp, dat de wireframes en de foto's afmaakt (archer-design.js).
 * Het oude formulier blijft bereikbaar via "Liever een formulier".
 */
(function () {
  "use strict";

  const CFG = window.ARCHER;
  if (!CFG) return;

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) =>
    String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  const STORE = "archer_intake_v2";
  const LABELS = {
    business_name: "Naam", existing_site: "Website", activity: "Activiteit", services: "Diensten",
    region: "Regio", service_area: "Werkgebied", founded: "Sinds", team: "Team", opening_hours: "Open",
    audience: "Doelgroep", main_goal: "Doel", booking: "Boeken", price_info: "Prijzen",
    usp: "Troef", reviews: "Reviews", references: "Referenties", certifications: "Erkenningen", guarantees: "Garantie",
    must_pages: "Pagina's", page_count: "Aantal", page_notes: "Per pagina", faq_topics: "FAQ",
    tone: "Toon", languages: "Talen", style_id: "Stijl", style_mood: "Sfeer", brand_colors: "Kleur",
    logo: "Logo", photos: "Foto's", own_photos: "Eigen foto's", examples: "Voorbeelden", dislikes: "Liever niet", contact: "Contact",
  };
  const LANG_NL = { Dutch: "Nederlands", French: "Frans", English: "Engels", German: "Duits" };
  const CHIP_ORDER = ["business_name", "existing_site", "activity", "region", "audience", "main_goal", "style_id", "brand_colors", "logo", "own_photos", "tone", "languages"];
  const HIDDEN_CHIPS = ["style_mood", "page_count"];

  let STYLES = []; // Archer-stijlen in de volgorde van de brief (aisb_archer_intake_styles)

  const styleName = (id) => (STYLES.find((s) => s.id === id) || {}).name || id;
  const valueText = (k, v) => {
    if (v == null || v === "" || v === "-") return "";
    if (k === "languages") return v.map((l) => LANG_NL[l] || l).join(", ");
    if (k === "contact") return v.skipped ? "" : Object.values(v).join(" · ");
    if (k === "existing_site") return String(v).replace(/^https?:\/\/(www\.)?/, "").replace(/\/$/, "");
    if (k === "style_id") return styleName(v);
    if (k === "logo") return typeof v === "object" ? "opgeladen" : String(v);
    if (k === "own_photos") return Array.isArray(v) ? v.length + " opgeladen" : "";
    return Array.isArray(v) ? v.join(", ") : String(v);
  };

  const post = async (action, data, files) => {
    const body = new FormData();
    body.append("action", action);
    body.append("nonce", CFG.nonce);
    Object.keys(data || {}).forEach((k) => body.append(k, typeof data[k] === "string" ? data[k] : JSON.stringify(data[k])));
    (files || []).forEach((f) => body.append("files[]", f));
    const res = await fetch(CFG.ajaxUrl, { method: "POST", credentials: "same-origin", body });
    let out = null;
    try { out = await res.json(); } catch (e) { throw new Error("De server gaf geen geldig antwoord."); }
    if (out === 0 || out === -1) throw new Error("Je bent niet meer aangemeld. Vernieuw de pagina.");
    if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "Er ging iets mis.");
    return out.data;
  };

  function init() {
    const start = $("[data-archer-start]");
    const shell = $(".archer-shell");
    // wp_localize_script maakt van 0 de tekst "0" (die in JS waar is): altijd als getal vergelijken.
    if (!start || !shell || Number(CFG.projectId)) return;
    if (shell.hasAttribute("data-archer-has-project")) return;

    // Oude formulier verbergen; blijft beschikbaar als terugval.
    Array.from(start.children).forEach((el) => el.setAttribute("data-archer-legacy", ""));
    start.classList.add("archer-intake-on");
    document.body.classList.add("archer-intake-mode");

    const root = document.createElement("section");
    root.className = "archer-intake";
    root.setAttribute("data-archer-intake", "");
    root.innerHTML = `
      <div class="archer-intake-chat">
        <div class="archer-intake-status"><span class="archer-intake-dot" aria-hidden="true"></span> Intake-agent · online</div>
        <div class="archer-intake-setup" data-intake-setup hidden>
          <div class="archer-intake-setup-head"><span>Je website</span><span data-intake-step></span></div>
          <ol class="archer-intake-blocks" data-intake-blocks aria-label="Onderwerpen"></ol>
          <div class="archer-intake-chips" data-intake-chips></div>
        </div>
        <div class="archer-intake-hello" data-intake-hello>
          <div class="archer-intake-orb" aria-hidden="true"></div>
          <h1 class="archer-intake-title">Laten we je website opzetten.</h1>
          <p class="archer-intake-lead">Ik ben de intake-agent van Archer. Ik stel je een reeks korte vragen over je zaak, je klanten, je pagina's en je stijl. Intussen zie je je website groeien.</p>
          <p class="archer-intake-lead archer-small">Begin met de naam van je zaak of het adres van je huidige website.</p>
        </div>
        <div class="archer-intake-log" data-intake-log aria-live="polite"></div>
        <div class="archer-intake-ready" data-intake-ready hidden>
          <span>Genoeg voor een eerste versie. Hoe meer je vertelt, hoe beter ze wordt.</span>
          <button type="button" class="archer-btn archer-btn-primary archer-btn-sm" data-intake-go-early>Maak mijn website →</button>
        </div>
        <form class="archer-intake-composer" data-intake-form>
          <label class="archer-intake-attach" title="Logo of foto's opladen">
            <input type="file" accept="image/*" multiple data-intake-attach hidden>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
            <span class="archer-sr-only">Foto's opladen</span>
          </label>
          <label class="archer-sr-only" for="archer-intake-input">Bericht aan Archer</label>
          <input id="archer-intake-input" class="archer-intake-input" autocomplete="off" placeholder="Naam van je zaak of www.jouwzaak.be" />
          <button type="submit" class="archer-intake-send" aria-label="Versturen">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
          </button>
        </form>
        <button type="button" class="archer-link-btn archer-intake-legacy" data-intake-legacy>Liever een formulier invullen</button>
      </div>
      <aside class="archer-intake-side" aria-label="Voorbeeld van je website">
        <button type="button" class="archer-intake-side-toggle" data-intake-side-toggle aria-expanded="false">Bekijk je website</button>
        <div class="archer-intake-side-inner" data-intake-preview></div>
      </aside>`;
    start.prepend(root);

    const el = {
      log: $("[data-intake-log]", root),
      form: $("[data-intake-form]", root),
      input: $("#archer-intake-input", root),
      hello: $("[data-intake-hello]", root),
      setup: $("[data-intake-setup]", root),
      chips: $("[data-intake-chips]", root),
      blocks: $("[data-intake-blocks]", root),
      step: $("[data-intake-step]", root),
      ready: $("[data-intake-ready]", root),
      preview: $("[data-intake-preview]", root),
      attach: $("[data-intake-attach]", root),
    };

    let state = { messages: [], brief: {}, progress: { have: 0, need: 8, blocks: [] }, done: false, ready: false, summary: [] };
    let busy = false;
    let peek = null; // stijl of kleur waar de muis op staat (voorbeeld, nog niet gekozen)

    const save = () => {
      try { sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* privémodus */ }
    };
    const load = () => {
      try {
        const s = JSON.parse(sessionStorage.getItem(STORE) || "null");
        if (s && Array.isArray(s.messages) && s.messages.length) state = Object.assign(state, s);
      } catch (e) { /* niets bewaard */ }
    };

    /* ── Stijlen (voor kaarten en voorbeeld) ─────────────── */

    let stylesKey = "";
    async function refreshStyles() {
      const b = state.brief;
      const key = [b.activity, (b.services || []).join(","), b.style_mood, b.business_name].join("|");
      if (key === stylesKey && STYLES.length) return;
      stylesKey = key;
      try {
        const d = await post("aisb_archer_intake_styles", { brief: JSON.stringify(b) });
        STYLES = d.styles || [];
        renderPreview();
        renderSetup();
      } catch (e) { /* voorbeeld zonder stijl */ }
    }
    const fontLoaded = {};
    const loadFont = (s) => {
      if (!s || !s.fontUrl || fontLoaded[s.fontUrl]) return;
      fontLoaded[s.fontUrl] = 1;
      const l = document.createElement("link");
      l.rel = "stylesheet";
      l.href = s.fontUrl;
      document.head.appendChild(l);
    };

    /* ── Weergave: gesprek ───────────────────────────────── */

    const scrollDown = () => {
      const last = el.log.lastElementChild;
      if (last) last.scrollIntoView({ block: "nearest", behavior: "smooth" });
    };

    function bubbleUser(m) {
      const d = document.createElement("div");
      d.className = "archer-intake-msg is-user";
      const thumbs = (m.thumbs || []).slice(0, 8).map((t) => `<img src="${esc(t)}" alt="" loading="lazy">`).join("");
      d.innerHTML = `<div class="archer-intake-bubble">${esc(m.text)}${thumbs ? `<div class="archer-intake-bubble-thumbs">${thumbs}</div>` : ""}</div><span class="archer-intake-meta">Verzonden</span>`;
      el.log.appendChild(d);
    }

    function bubbleAgent(m, isLast) {
      const d = document.createElement("div");
      d.className = "archer-intake-msg is-agent";
      let html = `<span class="archer-intake-who">Archer</span><div class="archer-intake-text">${esc(m.text)}</div>`;
      if (m.options && m.options.length) {
        const picked = m.picked || [];
        html += `<div class="archer-intake-options${m.multi ? " is-multi" : ""}" role="group">` +
          m.options.map((o) => {
            const on = picked.includes(o);
            return `<button type="button" class="archer-intake-opt${on ? " is-on" : ""}" data-opt="${esc(o)}" ${isLast ? "" : "disabled"} aria-pressed="${on}">${esc(o)}</button>`;
          }).join("") +
          (m.multi && isLast ? `<button type="button" class="archer-intake-opt is-confirm" data-opt-confirm>Bevestig</button>` : "") +
          `</div>`;
      }
      if (m.card) html += `<div class="archer-intake-card" data-intake-card></div>`;
      d.innerHTML = html;
      el.log.appendChild(d);
      if (m.card) renderCard($("[data-intake-card]", d), m, isLast);
      if (!isLast || !m.options || !m.options.length) return;

      $$("[data-opt]", d).forEach((b) =>
        b.addEventListener("click", () => {
          const o = b.getAttribute("data-opt");
          if (m.multi) {
            m.picked = m.picked || [];
            const i = m.picked.indexOf(o);
            if (i >= 0) m.picked.splice(i, 1); else m.picked.push(o);
            b.classList.toggle("is-on");
            b.setAttribute("aria-pressed", String(b.classList.contains("is-on")));
            return;
          }
          if (/^iets anders$/i.test(o)) { el.input.focus(); el.input.placeholder = "Typ je antwoord"; return; }
          m.picked = [o];
          send(o);
        }),
      );
      const confirm = $("[data-opt-confirm]", d);
      if (confirm) confirm.addEventListener("click", () => { if (m.picked && m.picked.length) send(m.picked.join(", ")); });
    }

    /* ── Kaarten in het gesprek ──────────────────────────── */

    function renderCard(host, m, live) {
      const c = m.card;
      host.className = "archer-intake-card is-" + c.type + (live ? "" : " is-closed");
      if (c.type === "styles") return cardStyles(host, m, live);
      if (c.type === "palette") return cardPalette(host, m, live);
      if (c.type === "upload") return cardUpload(host, m, live);
      if (c.type === "pages") return cardPages(host, m, live);
    }

    function styleTile(s, chosen) {
      loadFont(s);
      const c = s.colours || {};
      return `<button type="button" class="archer-ic-style${chosen ? " is-on" : ""}" data-style="${esc(s.id)}" aria-pressed="${chosen}">
        <span class="archer-ic-style-demo" style="background:${esc(c.base)};color:${esc(c.ink)}">
          <span class="archer-ic-style-aa" style="font-family:'${esc(s.headingFont)}';font-weight:${Number(s.headingWeight) || 600}">Aa</span>
          <span class="archer-ic-style-btn" style="background:${esc(c.accent)};color:${esc(c.accentInk)};border-radius:${Math.min(Number((s.radius || {}).button) || 0, 999)}px">Knop</span>
          <span class="archer-ic-style-sw">${["accent", "dark", "surface", "surface2"].map((k) => `<i style="background:${esc(c[k])}"></i>`).join("")}</span>
        </span>
        <span class="archer-ic-style-name">${esc(s.name)}${s.fit ? ' <em>past bij je zaak</em>' : ""}</span>
        <span class="archer-ic-style-pitch">${esc(s.pitch)}</span>
      </button>`;
    }

    function cardStyles(host, m, live) {
      const c = m.card;
      const chosen = state.brief.style_id || "";
      const all = (c.items || []).concat(c.more || []);
      // De volledige stijlgegevens kennen we ook uit STYLES (fonts voor het voorbeeld).
      all.forEach((s) => { if (!STYLES.find((x) => x.id === s.id)) STYLES.push(s); });
      if (!live) {
        host.innerHTML = chosen ? `<span class="archer-ic-closed">Gekozen stijl: <strong>${esc(styleName(chosen))}</strong></span>` : "";
        return;
      }
      host.innerHTML =
        `<div class="archer-ic-style-grid">${(c.items || []).map((s) => styleTile(s, s.id === chosen)).join("")}</div>` +
        ((c.more || []).length ? `<details class="archer-ic-more"><summary>Toon alle ${all.length} stijlen</summary><div class="archer-ic-style-grid">${c.more.map((s) => styleTile(s, s.id === chosen)).join("")}</div></details>` : "");
      $$("[data-style]", host).forEach((b) => {
        const id = b.getAttribute("data-style");
        b.addEventListener("mouseenter", () => { peek = { style_id: id }; renderPreview(); });
        b.addEventListener("focus", () => { peek = { style_id: id }; renderPreview(); });
        b.addEventListener("mouseleave", () => { peek = null; renderPreview(); });
        b.addEventListener("click", () => {
          peek = null;
          send("Stijl gekozen: " + styleName(id), { style_id: id });
        });
      });
    }

    function cardPalette(host, m, live) {
      const items = m.card.items || [];
      const chosen = String(state.brief.brand_colors || "");
      if (!live) {
        host.innerHTML = chosen ? `<span class="archer-ic-closed">Merkkleur: <strong>${esc(chosen)}</strong></span>` : "";
        return;
      }
      host.innerHTML =
        `<div class="archer-ic-swatches">${items.map((it) => `<button type="button" class="archer-ic-swatch" data-hex="${esc(it.hex)}" title="${esc(it.label)} ${esc(it.hex)}" style="--sw:${esc(it.hex)}"><span class="archer-sr-only">${esc(it.label)}</span></button>`).join("")}</div>` +
        `<div class="archer-ic-row">` +
        `<label class="archer-ic-custom"><input type="color" value="#1F3A5F" data-custom-color><span>Eigen kleur</span></label>` +
        `<input type="text" class="archer-input archer-ic-hex" placeholder="#1F3A5F" maxlength="7" data-custom-hex aria-label="Hexcode">` +
        `<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-hex-ok>Gebruik</button>` +
        `<button type="button" class="archer-link-btn" data-hex-none>Geen vaste kleur</button>` +
        `</div>`;
      $$("[data-hex]", host).forEach((b) => {
        const hex = b.getAttribute("data-hex");
        b.addEventListener("mouseenter", () => { peek = { brand_colors: hex }; renderPreview(); });
        b.addEventListener("mouseleave", () => { peek = null; renderPreview(); });
        b.addEventListener("click", () => { peek = null; send("Merkkleur: " + hex, { brand_colors: hex }); });
      });
      const hexIn = $("[data-custom-hex]", host);
      const colIn = $("[data-custom-color]", host);
      colIn.addEventListener("input", () => { hexIn.value = colIn.value.toUpperCase(); peek = { brand_colors: hexIn.value }; renderPreview(); });
      $("[data-hex-ok]", host).addEventListener("click", () => {
        const v = (hexIn.value || "").trim().toUpperCase();
        if (!/^#[0-9A-F]{6}$/.test(v)) { hexIn.focus(); hexIn.classList.add("is-bad"); return; }
        peek = null;
        send("Merkkleur: " + v, { brand_colors: v });
      });
      $("[data-hex-none]", host).addEventListener("click", () => { peek = null; send("Geen vaste merkkleur, de kleur van de stijl is goed.", { brand_colors: "geen" }); });
    }

    function cardUpload(host, m, live) {
      const c = m.card;
      const isLogo = c.kind === "logo";
      const current = isLogo
        ? (state.brief.logo && typeof state.brief.logo === "object" ? [state.brief.logo] : [])
        : (state.brief.own_photos || []);
      if (!live) {
        host.innerHTML = current.length
          ? `<div class="archer-ic-thumbs">${current.slice(0, 12).map((it) => `<img src="${esc(it.thumb || it.url)}" alt="">`).join("")}</div>`
          : "";
        return;
      }
      host.innerHTML =
        `<label class="archer-ic-drop" data-drop>
          <input type="file" accept="${isLogo ? "image/png,image/jpeg,image/webp,image/svg+xml,image/gif" : "image/png,image/jpeg,image/webp"}" ${c.multi ? "multiple" : ""} hidden data-file>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>
          <strong>${esc(c.label)}</strong><span>${esc(c.hint || "")}</span>
        </label>
        <div class="archer-ic-thumbs" data-thumbs>${current.map((it) => `<img src="${esc(it.thumb || it.url)}" alt="">`).join("")}</div>
        <div class="archer-ic-status archer-small" data-upstatus aria-live="polite"></div>
        <div class="archer-ic-row">
          ${isLogo
            ? `<button type="button" class="archer-link-btn" data-none>Ik heb (nog) geen logo</button>`
            : `<button type="button" class="archer-btn archer-btn-primary archer-btn-sm" data-done ${current.length ? "" : "hidden"}>Klaar met opladen</button>`}
        </div>`;
      const drop = $("[data-drop]", host);
      const file = $("[data-file]", host);
      const status = $("[data-upstatus]", host);
      const doneBtn = $("[data-done]", host);
      const upload = async (files) => {
        files = Array.from(files || []).filter((f) => /^image\//.test(f.type));
        if (!files.length) return;
        status.textContent = isLogo ? "Logo opladen…" : `${files.length} foto${files.length === 1 ? "" : "'s"} opladen…`;
        drop.classList.add("is-busy");
        try {
          const d = await post("aisb_archer_intake_upload", { kind: isLogo ? "logo" : "photos" }, isLogo ? files.slice(0, 1) : files.slice(0, 30));
          if (isLogo) {
            const it = d.items[0];
            send("Logo opgeladen", { logo: it }, [it.thumb || it.url]);
            return;
          }
          const list = (state.brief.own_photos || []).concat(d.items);
          state.brief.own_photos = list;
          save();
          renderPreview();
          renderSetup();
          $("[data-thumbs]", host).innerHTML = list.map((it) => `<img src="${esc(it.thumb || it.url)}" alt="">`).join("");
          status.textContent = `${list.length} foto${list.length === 1 ? "" : "'s"} klaar.` + (d.errors && d.errors.length ? " Niet gelukt: " + d.errors.join(", ") : "");
          if (doneBtn) doneBtn.hidden = false;
        } catch (err) {
          status.textContent = err.message || "Opladen lukte niet.";
        } finally {
          drop.classList.remove("is-busy");
        }
      };
      file.addEventListener("change", () => upload(file.files));
      ["dragenter", "dragover"].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add("is-over"); }));
      ["dragleave", "drop"].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove("is-over"); }));
      drop.addEventListener("drop", (e) => upload(e.dataTransfer.files));
      const none = $("[data-none]", host);
      if (none) none.addEventListener("click", () => send("Ik heb nog geen logo.", { logo: "geen" }));
      if (doneBtn) doneBtn.addEventListener("click", () => {
        const list = state.brief.own_photos || [];
        send(`${list.length} eigen foto${list.length === 1 ? "" : "'s"} opgeladen`, { own_photos: list, photos: "Mix (eigen foto's eerst)" }, list.slice(0, 8).map((it) => it.thumb || it.url));
      });
    }

    function cardPages(host, m, live) {
      const items = (m.card.items || []).map((it) => Object.assign({}, it));
      if (!live) {
        const chosen = state.brief.must_pages || [];
        host.innerHTML = chosen.length ? `<span class="archer-ic-closed">Pagina's: <strong>${esc(chosen.join(", "))}</strong></span>` : "";
        return;
      }
      const draw = () => {
        host.innerHTML =
          `<ul class="archer-ic-pages">${items.map((it, i) => `<li class="${it.child ? "is-child" : ""}">
            <label><input type="checkbox" data-page="${i}" ${it.on ? "checked" : ""} ${it.fixed ? "disabled" : ""}> <span>${esc(it.title)}</span>${it.fixed ? ' <em>altijd</em>' : ""}</label></li>`).join("")}</ul>` +
          `<div class="archer-ic-row"><input type="text" class="archer-input archer-ic-add" placeholder="Andere pagina, bv. Vacatures" data-page-add-in>` +
          `<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-page-add>Toevoegen</button>` +
          `<button type="button" class="archer-btn archer-btn-primary archer-btn-sm" data-pages-ok>Deze pagina's</button></div>`;
        $$("[data-page]", host).forEach((cb) => cb.addEventListener("change", () => {
          items[Number(cb.getAttribute("data-page"))].on = cb.checked;
          peek = { must_pages: items.filter((x) => x.on).map((x) => x.title) };
          renderPreview();
        }));
        const addIn = $("[data-page-add-in]", host);
        const add = () => {
          const t = (addIn.value || "").trim();
          if (!t) return;
          items.splice(items.length - 1, 0, { title: t.slice(0, 40), on: true });
          draw();
          $("[data-page-add-in]", host).focus();
        };
        $("[data-page-add]", host).addEventListener("click", add);
        addIn.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); add(); } });
        $("[data-pages-ok]", host).addEventListener("click", () => {
          const list = items.filter((x) => x.on).map((x) => x.title);
          peek = null;
          send("Pagina's: " + list.join(", "), { must_pages: list });
        });
      };
      draw();
    }

    function typing(on) {
      let t = $("[data-intake-typing]", el.log);
      if (on && !t) {
        t = document.createElement("div");
        t.className = "archer-intake-msg is-agent";
        t.setAttribute("data-intake-typing", "");
        t.innerHTML = `<span class="archer-intake-who">Archer</span><div class="archer-intake-typing"><i></i><i></i><i></i></div>`;
        el.log.appendChild(t);
        scrollDown();
      } else if (!on && t) t.remove();
    }

    function renderSetup() {
      const keys = Object.keys(state.brief).filter((k) => !HIDDEN_CHIPS.includes(k) && valueText(k, state.brief[k]));
      el.setup.hidden = keys.length === 0;
      el.hello.hidden = state.messages.length > 0;
      const blocks = (state.progress && state.progress.blocks) || [];
      el.blocks.innerHTML = blocks.map((b) => {
        const pct = b.need ? Math.round((b.have / b.need) * 100) : 0;
        return `<li class="${b.done ? "is-done" : b.have ? "is-busy" : ""}"><span class="archer-intake-block-bar"><i style="width:${pct}%"></i></span><span class="archer-intake-block-label">${esc(b.label)}</span></li>`;
      }).join("");
      const need = state.progress.need || 8;
      const have = Math.min(state.progress.have || 0, need);
      el.step.textContent = state.done ? "Klaar · nakijken" : state.ready ? "Klaar om te starten" : `${have} van ${need} basisvragen`;
      const order = CHIP_ORDER.filter((k) => keys.includes(k)).concat(keys.filter((k) => !CHIP_ORDER.includes(k)));
      el.chips.innerHTML = order.map((k) => {
        const v = valueText(k, state.brief[k]);
        return `<button type="button" class="archer-intake-chip" data-edit="${esc(k)}" title="${esc(LABELS[k] || k)} wijzigen">` +
          `<span class="archer-intake-chip-k">${esc(LABELS[k] || k)}</span> ${esc(v.length > 28 ? v.slice(0, 27) + "…" : v)} <span aria-hidden="true">✎</span></button>`;
      }).join("");
      $$("[data-edit]", el.chips).forEach((b) =>
        b.addEventListener("click", () => {
          const k = b.getAttribute("data-edit");
          el.input.value = `${LABELS[k] || k} is eigenlijk: `;
          el.input.focus();
          el.input.setSelectionRange(el.input.value.length, el.input.value.length);
        }),
      );
      el.ready.hidden = !(state.ready && !state.done);
    }

    function renderReview() {
      const old = $("[data-intake-review]", el.log);
      if (old) old.remove();
      if (!state.done || !state.summary.length) return;
      const d = document.createElement("div");
      d.className = "archer-intake-msg is-agent";
      d.setAttribute("data-intake-review", "");
      d.innerHTML =
        `<div class="archer-intake-text">Dit heb je me verteld:</div>` +
        `<table class="archer-intake-review"><tbody>` +
        state.summary.map((r) => `<tr><th scope="row">${esc(r.label)}</th><td>${esc(r.value)}</td></tr>`).join("") +
        `</tbody></table>` +
        `<p class="archer-muted archer-small">Iets niet juist? Typ het gewoon, bijvoorbeeld "de regio is Lokeren en omstreken".</p>` +
        `<p class="archer-small">In één keer maak ik nu je pagina's, de opbouw van elke pagina, de teksten, de stijl en de foto's. Daarna kan je alles in één scherm aanpassen.</p>` +
        `<div class="archer-intake-actions"><button type="button" class="archer-btn archer-btn-primary" data-intake-go>Maak mijn website →</button></div>` +
        `<div class="archer-status" data-intake-error aria-live="polite"></div>`;
      el.log.appendChild(d);
      $("[data-intake-go]", d).addEventListener("click", generate);
    }

    function renderAll() {
      el.log.innerHTML = "";
      const lastAgent = state.messages.map((m) => m.role).lastIndexOf("agent");
      state.messages.forEach((m, i) => (m.role === "user" ? bubbleUser(m) : bubbleAgent(m, i === lastAgent && !state.done)));
      renderSetup();
      renderReview();
      renderPreview();
      el.input.placeholder = state.messages.length ? "Bericht aan Archer…" : "Naam van je zaak of www.jouwzaak.be";
      scrollDown();
    }

    /* ── Live voorbeeld ──────────────────────────────────── */

    const GOAL_CTA = {
      bellen: "Bel ons", offerte: "Vraag een offerte", afspraak: "Maak een afspraak", reserv: "Reserveer",
      langs: "Kom langs", bestel: "Bestel nu", contact: "Neem contact op",
    };
    const ctaFor = (goal) => {
      const g = String(goal || "").toLowerCase();
      for (const k of Object.keys(GOAL_CTA)) if (g.indexOf(k) >= 0) return GOAL_CTA[k];
      return "Neem contact op";
    };
    const hexOk = (v) => /^#[0-9a-f]{6}$/i.test(String(v || "").trim());
    const slugify = (s) => String(s || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "").slice(0, 24) || "jouwzaak";

    function renderPreview() {
      const b = Object.assign({}, state.brief, peek || {});
      const s = STYLES.find((x) => x.id === b.style_id) || STYLES[0] || null;
      if (s) loadFont(s);
      const c = Object.assign({ base: "#FFFFFF", surface: "#F4F7FC", surface2: "#E2E9F4", ink: "#090930", muted: "#4A4D63", accent: "#1B5A5B", accentInk: "#FFFFFF", line: "#E2E9F4", dark: "#090930", darkInk: "#FFFFFF" }, (s && s.colours) || {});
      if (hexOk(b.brand_colors)) { c.accent = b.brand_colors; c.accentInk = "#FFFFFF"; }
      const r = (s && s.radius) || { button: 8, card: 14, image: 14 };
      const vars = `--p-base:${c.base};--p-surface:${c.surface};--p-surface2:${c.surface2};--p-ink:${c.ink};--p-muted:${c.muted};--p-accent:${c.accent};--p-accent-ink:${c.accentInk};--p-line:${c.line};--p-dark:${c.dark};--p-dark-ink:${c.darkInk};` +
        `--p-hf:'${s ? s.headingFont : "Urbanist"}',system-ui,sans-serif;--p-bf:'${s ? s.bodyFont : "Poppins"}',system-ui,sans-serif;--p-hw:${(s && s.headingWeight) || 700};` +
        `--p-rb:${Math.min(Number(r.button) || 0, 999)}px;--p-rc:${Number(r.card) || 0}px;--p-ri:${Number(r.image) || 0}px;`;

      const name = b.business_name || "Je zaak";
      const pages = (b.must_pages && b.must_pages.length ? b.must_pages : ["Home", "Diensten", "Over ons", "Contact"]).filter((p) => !/^home$/i.test(p)).slice(0, 5);
      const services = (b.services || []).slice(0, 3);
      const photos = b.own_photos || [];
      const logo = b.logo && typeof b.logo === "object" ? b.logo.thumb || b.logo.url : "";
      const head = b.usp && b.usp !== "-" ? b.usp : b.activity ? b.activity : "Hier komt je website";
      const sub = [b.activity && b.usp ? b.activity : "", b.region ? "in " + b.region + (b.service_area && b.service_area !== "-" ? " (" + b.service_area + ")" : "") : ""].filter(Boolean).join(" ");
      const trust = [
        b.reviews && b.reviews !== "-" ? "★ " + b.reviews : "",
        b.founded && b.founded !== "-" ? "Sinds " + b.founded : "",
        (b.certifications || []).filter((x) => x !== "-")[0] || "",
      ].filter((t) => t && t !== "-");
      const hero = photos[0] ? `<img src="${esc(photos[0].thumb || photos[0].url)}" alt="">` : `<span class="ip-ph" aria-hidden="true"></span>`;
      const contact = b.contact && !b.contact.skipped ? b.contact : {};
      const filledPct = Math.round(((state.progress.agenda && state.progress.agenda.have) || 0) / (((state.progress.agenda && state.progress.agenda.need) || 1)) * 100);

      el.preview.innerHTML = `
        <div class="archer-ip-head">
          <span>Je website${s ? ` · stijl <strong>${esc(s.name)}</strong>` : ""}</span>
          <span class="archer-ip-meter" title="Hoeveel ik al weet"><i style="width:${filledPct}%"></i></span>
        </div>
        <div class="archer-ip" style="${vars}">
          <div class="archer-ip-chrome"><i></i><i></i><i></i><span>${esc(slugify(name))}.archerwebsites.app</span></div>
          <div class="archer-ip-page">
            <header class="ip-nav">
              <span class="ip-logo">${logo ? `<img src="${esc(logo)}" alt="">` : esc(name)}</span>
              <nav>${pages.slice(0, 4).map((p) => `<span>${esc(p)}</span>`).join("")}</nav>
              <span class="ip-btn">${esc(ctaFor(b.main_goal))}</span>
            </header>
            <section class="ip-hero">
              <div class="ip-hero-text">
                <h2>${esc(head)}</h2>
                ${sub ? `<p>${esc(sub)}</p>` : ""}
                <span class="ip-btn">${esc(ctaFor(b.main_goal))}</span>
                ${trust.length ? `<div class="ip-trust">${trust.map((t) => `<span>${esc(t)}</span>`).join("")}</div>` : ""}
              </div>
              <div class="ip-hero-img">${hero}</div>
            </section>
            <section class="ip-cards">
              ${(services.length ? services : ["Dienst", "Dienst", "Dienst"]).map((t, i) => `<div class="ip-card${services.length ? "" : " is-ph"}">${photos[i + 1] ? `<img src="${esc(photos[i + 1].thumb || photos[i + 1].url)}" alt="">` : `<span class="ip-ico" aria-hidden="true"></span>`}<strong>${esc(t)}</strong></div>`).join("")}
            </section>
            <footer class="ip-foot">
              <strong>${esc(name)}</strong>
              <span>${esc([contact.address, contact.phone, contact.email].filter(Boolean).join(" · ") || (b.region || ""))}</span>
              ${b.opening_hours && b.opening_hours !== "-" ? `<span>${esc(b.opening_hours)}</span>` : ""}
            </footer>
          </div>
        </div>
        <div class="archer-ip-map">
          <span class="archer-ip-map-k">Pagina's</span>
          <ul>${["Home"].concat(pages).map((p) => `<li>${esc(p)}</li>`).join("")}${b.must_pages && b.must_pages.length > 6 ? `<li>+${b.must_pages.length - 6}</li>` : ""}</ul>
        </div>
        ${photos.length ? `<div class="archer-ip-photos"><span class="archer-ip-map-k">Je foto's (${photos.length})</span><div>${photos.slice(0, 10).map((p) => `<img src="${esc(p.thumb || p.url)}" alt="">`).join("")}</div></div>` : ""}`;
    }

    /* ── Gesprek ─────────────────────────────────────────── */

    async function send(text, set, thumbs) {
      text = String(text || "").trim();
      if (!text || busy) return;
      busy = true;
      // Eerste bericht dat op een webadres lijkt: meteen als bestaande website in de brief.
      if (!state.messages.length && /^(https?:\/\/)?(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i.test(text)) {
        state.brief.existing_site = text;
      }
      if (set) Object.assign(state.brief, set);
      // Het veld waarop dit antwoord slaat (vangnet op de server als de AI het vergeet).
      const lastAgent = state.messages.slice().reverse().find((m) => m.role === "agent");
      const answering = (lastAgent && lastAgent.ask) || "";
      state.messages.push({ role: "user", text, thumbs: thumbs || [] });
      state.done = false;
      renderAll();
      typing(true);

      try {
        const d = await post("aisb_archer_intake_turn", {
          messages: JSON.stringify(state.messages.map((m) => ({ role: m.role, text: m.text }))),
          brief: JSON.stringify(state.brief),
          set: JSON.stringify(set || {}),
          answering,
        });
        state.brief = d.brief || state.brief;
        state.progress = d.progress || state.progress;
        state.done = !!d.done;
        state.ready = !!d.ready;
        state.summary = d.summary || [];
        state.messages.push({ role: "agent", text: d.reply || "Genoteerd.", options: d.options || [], multi: !!d.multi, card: d.card || null, ask: d.ask || "" });
      } catch (err) {
        state.messages.push({ role: "agent", text: (err && err.message) || "Er ging iets mis. Probeer het nog eens." });
      } finally {
        typing(false);
        busy = false;
        save();
        renderAll();
        refreshStyles();
        el.input.focus();
      }
    }

    el.form.addEventListener("submit", (e) => {
      e.preventDefault();
      const t = el.input.value;
      el.input.value = "";
      // Een hexcode getypt bij de kleurvraag: meteen als merkkleur.
      const last = state.messages[state.messages.length - 1];
      const hex = (t.match(/#[0-9a-f]{6}\b/i) || [])[0];
      if (hex && last && last.ask === "brand_colors") return send(t, { brand_colors: hex.toUpperCase() });
      send(t);
    });

    // Paperclip: foto's (of een logo bij de logovraag) op elk moment opladen.
    el.attach.addEventListener("change", async () => {
      const files = Array.from(el.attach.files || []).filter((f) => /^image\//.test(f.type));
      el.attach.value = "";
      if (!files.length || busy) return;
      const last = state.messages[state.messages.length - 1];
      const asLogo = last && last.ask === "logo";
      try {
        busy = true;
        typing(true);
        const d = await post("aisb_archer_intake_upload", { kind: asLogo ? "logo" : "photos" }, asLogo ? files.slice(0, 1) : files.slice(0, 30));
        busy = false;
        typing(false);
        if (asLogo) return send("Logo opgeladen", { logo: d.items[0] }, [d.items[0].thumb]);
        const list = (state.brief.own_photos || []).concat(d.items);
        send(`${d.items.length} foto${d.items.length === 1 ? "" : "'s"} opgeladen`, { own_photos: list }, d.items.map((it) => it.thumb));
      } catch (err) {
        busy = false;
        typing(false);
        state.messages.push({ role: "agent", text: err.message || "Opladen lukte niet." });
        renderAll();
      }
    });

    $("[data-intake-legacy]", root).addEventListener("click", () => {
      start.classList.remove("archer-intake-on");
      document.body.classList.remove("archer-intake-mode");
      root.hidden = true;
    });
    $("[data-intake-go-early]", root).addEventListener("click", generate);
    $("[data-intake-side-toggle]", root).addEventListener("click", (e) => {
      const open = root.classList.toggle("is-side-open");
      e.currentTarget.setAttribute("aria-expanded", String(open));
      e.currentTarget.textContent = open ? "Terug naar het gesprek" : "Bekijk je website";
    });

    /* ── Eén run: sitemap, stijl, dan Ontwerp ────────────── */

    function briefPrompt(b) {
      const v = (k) => (b[k] && b[k] !== "-" ? valueText(k, b[k]) : "");
      const line = (label, k) => (v(k) ? `${label}: ${v(k)}.` : "");
      return [
        line("Naam van de zaak", "business_name"),
        line("Activiteit", "activity"),
        line("Diensten of producten", "services"),
        line("Regio", "region"),
        line("Werkgebied", "service_area"),
        line("Actief sinds", "founded"),
        line("Team", "team"),
        line("Openingsuren", "opening_hours"),
        line("Doelgroep", "audience"),
        line("Wat een bezoeker moet doen (hoofddoel, de knop in de header)", "main_goal"),
        v("booking") ? `Online boeken via: ${v("booking")} (externe link of widget, geen agendakoppeling).` : "",
        line("Prijzen", "price_info"),
        line("Wat de zaak onderscheidt", "usp"),
        line("Reviews (gebruik exact dit, verzin geen andere)", "reviews"),
        line("Referenties", "references"),
        line("Erkenningen en labels", "certifications"),
        line("Garanties", "guarantees"),
        line("Toon van de teksten", "tone"),
        line("Pagina's die zeker moeten (maak precies deze pagina's, plus Home en Contact)", "must_pages"),
        line("Afspraken per pagina", "page_notes"),
        line("Veelgestelde vragen", "faq_topics"),
        line("Gewenste sfeer", "style_mood"),
        line("Voorbeeldsites", "examples"),
        line("Liever niet", "dislikes"),
      ].filter(Boolean).join("\n");
    }

    /** Overlay met de stappen van de run; gedeeld met archer-design.js via sessionStorage. */
    const BUILD_STEPS = [
      { key: "sitemap", label: "Pagina's en secties bedenken" },
      { key: "style", label: "Stijl, logo en foto's klaarzetten" },
      { key: "wireframes", label: "Elke pagina opbouwen en teksten schrijven" },
      { key: "photos", label: "Foto's plaatsen" },
    ];
    function overlay(stepKey, note) {
      let o = $("[data-archer-build]");
      if (!o) {
        o = document.createElement("div");
        o.className = "archer-build";
        o.setAttribute("data-archer-build", "");
        o.setAttribute("role", "status");
        document.body.appendChild(o);
      }
      const idx = BUILD_STEPS.findIndex((s) => s.key === stepKey);
      o.innerHTML = `<div class="archer-build-card">
        <div class="archer-intake-orb" aria-hidden="true"></div>
        <h2>Je website wordt gebouwd</h2>
        <p class="archer-muted">${esc(state.brief.business_name || "")} · dit duurt een paar minuten. Je mag dit venster open laten staan.</p>
        <ol>${BUILD_STEPS.map((s, i) => `<li class="${i < idx ? "is-done" : i === idx ? "is-active" : ""}"><span aria-hidden="true"></span>${esc(s.label)}</li>`).join("")}</ol>
        <p class="archer-small archer-muted" data-build-note>${esc(note || "")}</p>
      </div>`;
    }

    let building = false;
    async function generate() {
      if (building) return;
      const UI = window.ARCHER_UI;
      const app = window.AISBApp;
      const errEl = $("[data-intake-error]", el.log);
      if (!UI || typeof UI.fastGenerate !== "function" || !app) {
        if (errEl) errEl.innerHTML = '<div class="aisb-error">De generator is niet geladen. Herlaad de pagina.</div>';
        return;
      }
      building = true;
      const b = state.brief;
      const c = b.contact && !b.contact.skipped ? b.contact : {};
      const opts = {
        mode: b.existing_site ? "url" : "desc",
        url: b.existing_site || "",
        prompt: briefPrompt(b),
        languages: (b.languages && b.languages.length ? b.languages : ["Dutch"]).slice(0, 1),
        pageCount: b.page_count || "5-10",
        contact: { address: c.address || "", phone: c.phone || "", email: c.email || "", socials: c.socials || "" },
      };
      $$("[data-intake-go], [data-intake-go-early]").forEach((btn) => { btn.disabled = true; });
      overlay("sitemap", "AI bedenkt de pagina's en de secties op elke pagina.");
      try {
        await UI.fastGenerate(opts);
        const pid = app.state && app.state.projectId;
        if (!pid) throw new Error("Er kwam geen project terug.");
        overlay("style", "Stijl " + (styleName(b.style_id) || "") + (b.logo && typeof b.logo === "object" ? ", je logo" : "") + ((b.own_photos || []).length ? ` en ${(b.own_photos || []).length} eigen foto's` : "") + ".");
        await post("aisb_archer_intake_save", { project_id: String(pid), brief: JSON.stringify(b) });
        if (UI.flushAll) await UI.flushAll();
        try {
          sessionStorage.removeItem(STORE);
          sessionStorage.setItem("archer_build_" + pid, JSON.stringify({ at: Date.now(), name: b.business_name || "" }));
        } catch (e) { /* niets */ }
        overlay("wireframes", "Het scherm Ontwerp opent. Daar zie je elke pagina verschijnen.");
        // Ontwerp: wireframes en foto's worden daar verder gemaakt (aisb_build=1).
        const url = new URL(window.location.href);
        url.searchParams.set("aisb_step", "2");
        url.searchParams.set("aisb_project", pid);
        if (app.state.sitemapId) url.searchParams.set("aisb_sitemap", app.state.sitemapId);
        url.searchParams.set("aisb_build", "1");
        window.location.href = url.toString();
      } catch (err) {
        console.error("[Archer] intake-generatie", err);
        building = false;
        const o = $("[data-archer-build]");
        if (o) o.remove();
        $$("[data-intake-go], [data-intake-go-early]").forEach((btn) => { btn.disabled = false; });
        const target = errEl || el.ready;
        if (target) {
          target.hidden = false;
          target.insertAdjacentHTML("beforeend", '<div class="aisb-error">Het opzetten lukte niet: ' + esc(err.message || "") + " Probeer opnieuw.</div>");
        }
      }
    }

    load();
    renderAll();
    refreshStyles();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
