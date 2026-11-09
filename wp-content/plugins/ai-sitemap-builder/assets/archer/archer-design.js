/**
 * Scherm Ontwerp: sitemap, wireframes, stijl en foto's in één stap.
 *
 * - Links: de pagina's van de website (de sitemap). Klik om naar de pagina op het canvas te gaan;
 *   hernoemen, schrappen en een pagina toevoegen (AI schrijft de secties, daarna de wireframe).
 * - Midden: het canvas met alle pagina's (archer.js, initWireframes).
 * - Rechts: stijl (stijlen, hoeken, merkkleur, logo) en foto's (eigen foto's, stock, voorkeur).
 *   Een wijziging zie je meteen op het canvas: stijl via het voorbeeld van de server (iframes
 *   herladen), foto's door ze in de voorbeelden te zetten (zelfde fotovakken als bij publiceren).
 * - Na de intake (aisb_build=1) maakt dit scherm de run af: wireframes van alle pagina's,
 *   daarna de foto's, met een voortgangskaart onderaan.
 *
 * Server: AISB_Archer_Design (aisb_archer_design_*), AISB_Archer_Generate (aisb_archer_sections),
 * AISB_Archer_Intake (aisb_archer_intake_upload voor logo en foto's).
 */
(function () {
  "use strict";

  const CFG = window.ARCHER;
  if (!CFG) return;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) =>
    String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

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
    const host = $("[data-archer-design]");
    const wf = window.AISB_WF_App;
    if (!host || !wf) return;
    const UI = window.ARCHER_UI || {};
    const pid = Number(host.getAttribute("data-project-id")) || 0;
    const sid = Number(host.getAttribute("data-sitemap-id")) || 0;
    if (!pid || !sid) return;
    document.body.classList.add("archer-design-mode");

    const left = $("[data-archer-design-pages]", host);
    const right = $("[data-archer-design-side]", host);
    const links = {
      sitemap: host.getAttribute("data-sitemap-url"),
      style: host.getAttribute("data-style-url"),
      photos: host.getAttribute("data-photos-url"),
    };
    let S = { pages: [], styles: [], guide: {}, photos: { images: [], sections: {}, pages: [], own: [], total: 0, filled: 0, pref: "mix" } };
    let tab = ["style", "photos"].includes(host.getAttribute("data-panel")) ? host.getAttribute("data-panel") : "style";
    const toast = (msg, kind) => {
      let t = $(".archer-design-toast");
      if (!t) {
        t = document.createElement("div");
        t.className = "archer-design-toast";
        t.setAttribute("role", "status");
        document.body.appendChild(t);
      }
      t.className = "archer-design-toast is-on" + (kind ? " is-" + kind : "");
      t.textContent = msg;
      clearTimeout(toast._t);
      toast._t = setTimeout(() => t.classList.remove("is-on"), kind === "err" ? 7000 : 3500);
    };
    const saving = (on, text) => UI.setSave && UI.setSave(on ? "saving" : "saved", text);

    /* ── Hoogte: de zijbalken vullen het scherm onder de werkbalk ── */
    const layout = () => {
      const top = host.getBoundingClientRect().top + window.scrollY;
      document.documentElement.style.setProperty("--ar-design-top", Math.max(0, Math.round(top)) + "px");
    };
    layout();
    window.addEventListener("resize", () => requestAnimationFrame(layout));
    setTimeout(layout, 400);

    /* ── Toestand van de server ───────────────────────── */
    async function loadState() {
      const d = await post("aisb_archer_design_state", { project_id: String(pid) });
      S = d;
      S.photos = S.photos || { images: [], sections: {}, pages: [], own: [] };
      renderLeft();
      renderRight();
      injectAll();
      return d;
    }

    /* ── Pagina's (links) ─────────────────────────────── */

    const sectionsOf = (slug) => ((wf.state.pageModels[slug] || {}).sections || []).length;
    let modelsSeen = false; // de wireframes van de server zijn geladen
    function treeOrder() {
      const out = [];
      const seen = new Set();
      const kids = (parent) => S.pages.filter((p) => (p.parent || "") === parent && p.slug !== "home");
      const walk = (p, depth) => {
        if (seen.has(p.slug)) return;
        seen.add(p.slug);
        out.push(Object.assign({ depth }, p));
        kids(p.slug).forEach((k) => walk(k, depth + 1));
      };
      const home = S.pages.find((p) => p.slug === "home");
      if (home) walk(home, 0);
      S.pages.filter((p) => !p.parent || p.parent === "home").forEach((p) => walk(p, home ? 1 : 0));
      S.pages.forEach((p) => walk(p, 1));
      return out;
    }

    function renderLeft() {
      const state = UI.wfGenState ? UI.wfGenState() : { failed: [] };
      const rows = treeOrder().map((p) => {
        const n = sectionsOf(p.slug);
        const failed = (state.failed || []).includes(p.slug);
        const col = $(`.archer-cv-col[data-archer-cv-page="${CSS.escape(p.slug)}"]`);
        const busy = !!(col && col.classList.contains("is-pending")) || (!modelsSeen && !n);
        const status = failed ? "mislukt" : n ? n + " secties" : busy ? "wordt gemaakt…" : "nog leeg";
        return `<li class="archer-dp-page${p.depth > 1 ? " is-child" : ""}" data-slug="${esc(p.slug)}" style="--d:${Math.max(0, p.depth - 1)}">
          <button type="button" class="archer-dp-go" data-dp-go title="Naar ${esc(p.title)} op het canvas">
            <span class="archer-dp-title">${esc(p.title)}</span>
            <span class="archer-dp-meta${failed ? " is-err" : n ? "" : busy ? " is-busy" : ""}">/${esc(p.slug === "home" ? "" : p.slug)} · ${esc(status)}</span>
          </button>
          <span class="archer-dp-acts">
            <button type="button" class="archer-dp-act" data-dp-edit aria-label="${esc(p.title)} openen en bewerken" title="Secties en teksten">✎</button>
            <button type="button" class="archer-dp-act" data-dp-rename aria-label="${esc(p.title)} hernoemen" title="Hernoemen">Aa</button>
            ${p.slug === "home" ? "" : `<button type="button" class="archer-dp-act is-danger" data-dp-remove aria-label="${esc(p.title)} schrappen" title="Schrappen">✕</button>`}
          </span>
        </li>`;
      }).join("");
      const parents = S.pages.filter((p) => !p.parent || p.parent === "home");
      left.innerHTML = `
        <div class="archer-dp-head">
          <h2>Pagina's <span>${S.pages.length}</span></h2>
          <button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-dp-add-open>+ Pagina</button>
        </div>
        <form class="archer-dp-add" data-dp-add hidden>
          <label class="archer-sr-only" for="archer-dp-title">Naam van de pagina</label>
          <input id="archer-dp-title" class="archer-input" placeholder="Naam, bv. Vacatures" maxlength="60" required>
          <label class="archer-dp-small" for="archer-dp-parent">Onder</label>
          <select id="archer-dp-parent" class="archer-input">${parents.map((p) => `<option value="${esc(p.slug)}">${esc(p.slug === "home" ? "Hoofdmenu" : p.title)}</option>`).join("")}</select>
          <label class="archer-sr-only" for="archer-dp-purpose">Waarover gaat de pagina?</label>
          <input id="archer-dp-purpose" class="archer-input" placeholder="Waarover? (optioneel)" maxlength="160">
          <div class="archer-dp-add-row">
            <button type="submit" class="archer-btn archer-btn-primary archer-btn-sm">Maak de pagina</button>
            <button type="button" class="archer-link-btn" data-dp-add-cancel>Annuleren</button>
          </div>
          <p class="archer-dp-small" data-dp-add-status aria-live="polite"></p>
        </form>
        <ul class="archer-dp-list">${rows}</ul>
        <a class="archer-dp-more" href="${esc(links.sitemap)}" data-archer-nav>Sitemap met alle opties (boom, secties, SEO) →</a>`;
    }

    function focusPage(slug) {
      if (wf.el.expanded && wf.el.expanded.classList.contains("is-open") && wf.closeExpandedPage) wf.closeExpandedPage();
      const col = $(`.archer-cv-col[data-archer-cv-page="${CSS.escape(slug)}"]`);
      if (!col || !wf.canvas) return;
      const scale = wf.canvas.scale || 0.5;
      wf.canvas.tx = 32 - col.offsetLeft * scale;
      wf.canvas.ty = 24 - col.offsetTop * scale;
      if (wf.applyCanvasTransform) wf.applyCanvasTransform();
      col.classList.add("is-flash");
      setTimeout(() => col.classList.remove("is-flash"), 1200);
      $$(".archer-dp-page", left).forEach((li) => li.classList.toggle("is-current", li.getAttribute("data-slug") === slug));
    }

    left.addEventListener("click", async (e) => {
      const li = e.target.closest("[data-slug]");
      const slug = li && li.getAttribute("data-slug");
      if (e.target.closest("[data-dp-go]") && slug) return focusPage(slug);
      if (e.target.closest("[data-dp-edit]") && slug) {
        if (UI.flushAll) await UI.flushAll();
        if (wf.openExpandedPage) wf.openExpandedPage(slug);
        return;
      }
      if (e.target.closest("[data-dp-rename]") && slug) return renameInline(li, slug);
      if (e.target.closest("[data-dp-remove]") && slug) return removePage(slug);
      if (e.target.closest("[data-dp-add-open]")) {
        const f = $("[data-dp-add]", left);
        f.hidden = !f.hidden;
        if (!f.hidden) $("#archer-dp-title").focus();
        return;
      }
      if (e.target.closest("[data-dp-add-cancel]")) { $("[data-dp-add]", left).hidden = true; return; }
    });
    left.addEventListener("submit", (e) => {
      if (!e.target.matches("[data-dp-add]")) return;
      e.preventDefault();
      addPage();
    });

    function renameInline(li, slug) {
      const p = S.pages.find((x) => x.slug === slug);
      if (!p) return;
      const go = $("[data-dp-go]", li);
      const form = document.createElement("form");
      form.className = "archer-dp-rename";
      form.innerHTML = `<input class="archer-input" value="${esc(p.title)}" maxlength="60" aria-label="Nieuwe naam"><button type="submit" class="archer-btn archer-btn-primary archer-btn-sm">OK</button>`;
      go.replaceWith(form);
      const input = $("input", form);
      input.focus();
      input.select();
      const cancel = () => renderLeft();
      input.addEventListener("keydown", (ev) => { if (ev.key === "Escape") cancel(); });
      form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const title = input.value.trim();
        if (!title || title === p.title) return cancel();
        saving(true);
        try {
          const d = await post("aisb_archer_design_page", { project_id: String(pid), op: "rename", slug, title });
          S.pages = d.pages;
          const wp = wf.state.pages.find((x) => x.slug === slug);
          if (wp) wp.title = title;
          if (wf.renderWhiteboard) wf.renderWhiteboard();
          saving(false);
          toast(`Hernoemd naar ${title}. Het menu volgt bij het publiceren.`);
        } catch (err) {
          saving(false);
          toast(err.message, "err");
        }
        renderLeft();
      });
    }

    async function removePage(slug) {
      const p = S.pages.find((x) => x.slug === slug);
      if (!p) return;
      const kids = S.pages.filter((x) => x.parent === slug);
      const msg = `Pagina "${p.title}" schrappen${kids.length ? ` (en ${kids.map((k) => k.title).join(", ")})` : ""}? Dit kan je niet ongedaan maken.`;
      if (!window.confirm(msg)) return;
      saving(true);
      try {
        if (UI.flushAll) await UI.flushAll();
        await post("aisb_archer_design_page", { project_id: String(pid), op: "remove", slug });
        await reloadWith({ toast: `"${p.title}" is geschrapt.` });
      } catch (err) {
        saving(false);
        toast(err.message, "err");
      }
    }

    async function addPage() {
      const title = $("#archer-dp-title").value.trim();
      const parent = $("#archer-dp-parent").value || "home";
      const purpose = $("#archer-dp-purpose").value.trim();
      const status = $("[data-dp-add-status]", left);
      if (!title) return;
      const btn = $("[data-dp-add] [type=submit]", left);
      btn.disabled = true;
      status.textContent = "AI bedenkt de secties van deze pagina…";
      saving(true, "pagina maken…");
      try {
        if (UI.flushAll) await UI.flushAll();
        const slug = title.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 60) || "pagina";
        let sections = [];
        try {
          const out = await post("aisb_archer_sections", {
            project_id: String(pid),
            pages: JSON.stringify([{ slug, title, page_type: "Other", parent, purpose }]),
            outline: JSON.stringify(S.pages.map((x) => ({ slug: x.slug, title: x.title, parent: x.parent })).concat([{ slug, title, parent }])),
            site: JSON.stringify({ name: (document.querySelector(".archer-save-name") || {}).textContent || "" }),
          });
          sections = ((out.pages || {})[slug] || {}).sections || [];
        } catch (e) { /* standaardsecties op de server */ }
        status.textContent = "Pagina toevoegen…";
        const d = await post("aisb_archer_design_page", { project_id: String(pid), op: "add", slug, title, parent, purpose, sections: JSON.stringify(sections) });
        await reloadWith({ generate: [d.slug], toast: `"${title}" staat erbij. De wireframe wordt gemaakt.` });
      } catch (err) {
        btn.disabled = false;
        status.textContent = err.message;
        saving(false);
      }
    }

    /** Herladen (de wireframes volgen de sitemap pas na een nieuwe start), met een taak voor daarna. */
    async function reloadWith(task) {
      if (UI.flushAll) await UI.flushAll();
      try { sessionStorage.setItem("archer_design_task_" + pid, JSON.stringify(task || {})); } catch (e) { /* niets */ }
      window.location.reload();
    }

    /* ── Stijl en foto's (rechts) ─────────────────────── */

    const fontLoaded = {};
    const loadFont = (url) => {
      if (!url || fontLoaded[url]) return;
      fontLoaded[url] = 1;
      const l = document.createElement("link");
      l.rel = "stylesheet";
      l.href = url;
      document.head.appendChild(l);
    };

    function renderRight() {
      right.innerHTML = `
        <div class="archer-ds-tabs" role="tablist">
          <button type="button" role="tab" class="archer-ds-tab${tab === "style" ? " is-on" : ""}" data-ds-tab="style" aria-selected="${tab === "style"}">Stijl</button>
          <button type="button" role="tab" class="archer-ds-tab${tab === "photos" ? " is-on" : ""}" data-ds-tab="photos" aria-selected="${tab === "photos"}">Foto's <span>${S.photos.filled || 0}/${S.photos.total || 0}</span></button>
        </div>
        <div class="archer-ds-body">${tab === "style" ? styleHtml() : photosHtml()}</div>`;
    }

    function styleHtml() {
      const g = S.guide || {};
      const cur = g.style;
      const styles = S.styles || [];
      styles.forEach((s) => loadFont(s.fontUrl));
      const brand = /^#[0-9A-F]{6}$/i.test(g.brand || "") ? g.brand.toUpperCase() : "";
      const swatches = ["#1F3A5F", "#2152E8", "#0E6E72", "#2F5A46", "#6B8F3E", "#C2410C", "#D21F14", "#B4235A", "#6D28D9", "#191917"];
      return `
        <section class="archer-ds-sec">
          <h3>Stijl van de website</h3>
          <div class="archer-ds-styles">${styles.map((s) => {
            const c = s.colours || {};
            return `<button type="button" class="archer-ds-style${s.id === cur ? " is-on" : ""}" data-ds-style="${esc(s.id)}" aria-pressed="${s.id === cur}" title="${esc(s.pitch)}">
              <span class="archer-ds-style-demo" style="background:${esc(c.base)};color:${esc(c.ink)}">
                <span style="font-family:'${esc(s.headingFont)}';font-weight:${Number(s.headingWeight) || 600}">Aa</span>
                <i style="background:${esc(c.accent)}"></i><i style="background:${esc(c.dark)}"></i><i style="background:${esc(c.surface)}"></i>
              </span>
              <span class="archer-ds-style-name">${esc(s.name)}${s.fit ? " <em>past</em>" : ""}</span>
            </button>`;
          }).join("")}</div>
        </section>
        <section class="archer-ds-sec">
          <h3>Hoeken</h3>
          <div class="archer-seg archer-seg-sm" role="group" aria-label="Hoeken">${[["sharp", "Scherp"], ["soft", "Zacht"], ["round", "Rond"]].map(([k, l]) => `<button type="button" class="archer-seg-btn${g.radius === k ? " is-active" : ""}" data-ds-radius="${k}" aria-pressed="${g.radius === k}">${l}</button>`).join("")}</div>
        </section>
        <section class="archer-ds-sec">
          <h3>Merkkleur</h3>
          <div class="archer-ds-swatches">${swatches.map((h) => `<button type="button" class="archer-ds-swatch${brand === h ? " is-on" : ""}" data-ds-brand="${h}" style="--sw:${h}" title="${h}"><span class="archer-sr-only">${h}</span></button>`).join("")}
            <label class="archer-ds-swatch is-custom" title="Eigen kleur"><input type="color" value="${esc(brand || "#1F3A5F")}" data-ds-brand-custom><span class="archer-sr-only">Eigen kleur</span></label>
          </div>
          <p class="archer-ds-note">${brand ? `Accentkleur: <strong>${esc(brand)}</strong> · <button type="button" class="archer-link-btn" data-ds-brand="RESET">kleur van de stijl</button>` : "De kleur van de stijl. Kies een kleur om die te vervangen."}</p>
        </section>
        <section class="archer-ds-sec">
          <h3>Logo</h3>
          <div class="archer-ds-logo">
            ${g.logoUrl ? `<span class="archer-ds-logo-img"><img src="${esc(sameScheme(g.logoUrl))}" alt="Logo"></span>` : `<span class="archer-ds-logo-img is-empty">Nog geen logo: de naam staat in de header.</span>`}
            <label class="archer-btn archer-btn-outline archer-btn-sm">${g.logoUrl ? "Ander logo" : "Logo opladen"}<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif" hidden data-ds-logo></label>
            ${g.logoUrl ? `<button type="button" class="archer-link-btn" data-ds-logo-remove>Weghalen</button>` : ""}
          </div>
        </section>
        <a class="archer-dp-more" href="${esc(links.style)}" data-archer-nav>Kleuren en letters verfijnen (alle opties) →</a>`;
    }

    function photosHtml() {
      const P = S.photos || {};
      const own = P.own || [];
      const pref = P.pref || "mix";
      const empty = (P.total || 0) - (P.filled || 0);
      return `
        <section class="archer-ds-sec">
          <h3>Fotovakken</h3>
          <p class="archer-ds-note"><strong>${P.filled || 0}</strong> van ${P.total || 0} gevuld${empty > 0 ? ` · ${empty} leeg` : ""}.</p>
          <div class="archer-seg archer-seg-sm" role="group" aria-label="Welke foto's">${[["own", "Eigen"], ["mix", "Mix"], ["stock", "Stock"]].map(([k, l]) => `<button type="button" class="archer-seg-btn${pref === k ? " is-active" : ""}" data-ds-pref="${k}" aria-pressed="${pref === k}">${l}</button>`).join("")}</div>
          <div class="archer-ds-row">
            <button type="button" class="archer-btn archer-btn-primary archer-btn-sm" data-ds-fill ${P.total ? "" : "disabled"}>${empty > 0 ? "Vul de lege fotovakken" : "Alles is gevuld"}</button>
            ${pref !== "own" ? `<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-ds-shuffle ${P.filled ? "" : "disabled"}>Andere stockfoto's</button>` : ""}
          </div>
          <p class="archer-ds-note" data-ds-photo-status aria-live="polite"></p>
        </section>
        <section class="archer-ds-sec">
          <h3>Eigen foto's <span>${own.length}</span></h3>
          <label class="archer-ds-drop" data-ds-drop>
            <input type="file" accept="image/png,image/jpeg,image/webp" multiple hidden data-ds-upload>
            <strong>Foto's opladen</strong><span>Sleep ze hierheen. Ze komen eerst in de fotovakken.</span>
          </label>
          <div class="archer-ds-own">${own.map((o) => `<span class="archer-ds-own-item"><img src="${esc(sameScheme(o.thumb || o.full))}" alt="" loading="lazy"><button type="button" data-ds-own-remove="${Number(o.id)}" aria-label="Foto weghalen">✕</button></span>`).join("")}</div>
        </section>
        <a class="archer-dp-more" href="${esc(links.photos)}" data-archer-nav>Foto per foto wisselen (alle opties) →</a>`;
    }

    right.addEventListener("click", async (e) => {
      const t = e.target.closest("[data-ds-tab]");
      if (t) { tab = t.getAttribute("data-ds-tab"); renderRight(); return; }
      const st = e.target.closest("[data-ds-style]");
      if (st) return setStyle({ style: st.getAttribute("data-ds-style"), keep_brand: S.guide.brand ? "1" : "" }, "Stijl toegepast.");
      const rd = e.target.closest("[data-ds-radius]");
      if (rd) return setStyle({ radius: rd.getAttribute("data-ds-radius") }, "Hoeken aangepast.");
      const br = e.target.closest("[data-ds-brand]");
      if (br) return setStyle({ brand: br.getAttribute("data-ds-brand") }, "Merkkleur aangepast.");
      if (e.target.closest("[data-ds-logo-remove]")) return setStyle({ logo_id: "0" }, "Logo weggehaald.");
      const pf = e.target.closest("[data-ds-pref]");
      if (pf) return setStyle({ photo_pref: pf.getAttribute("data-ds-pref") }, "Voorkeur bewaard.");
      if (e.target.closest("[data-ds-fill]")) return fillPhotos(false);
      if (e.target.closest("[data-ds-shuffle]")) return fillPhotos(true);
      const rm = e.target.closest("[data-ds-own-remove]");
      if (rm) return photoOp({ op: "own_remove", id: rm.getAttribute("data-ds-own-remove") });
    });
    right.addEventListener("change", async (e) => {
      if (e.target.matches("[data-ds-brand-custom]")) return setStyle({ brand: e.target.value.toUpperCase() }, "Merkkleur aangepast.");
      if (e.target.matches("[data-ds-logo]")) return uploadLogo(e.target.files);
      if (e.target.matches("[data-ds-upload]")) return uploadPhotos(e.target.files);
    });
    ["dragenter", "dragover"].forEach((ev) => right.addEventListener(ev, (e) => { const d = e.target.closest("[data-ds-drop]"); if (d) { e.preventDefault(); d.classList.add("is-over"); } }));
    ["dragleave", "drop"].forEach((ev) => right.addEventListener(ev, (e) => { const d = e.target.closest("[data-ds-drop]"); if (d) { e.preventDefault(); d.classList.remove("is-over"); } }));
    right.addEventListener("drop", (e) => { if (e.target.closest("[data-ds-drop]")) uploadPhotos(e.dataTransfer.files); });

    let styleBusy = Promise.resolve();
    function setStyle(data, msg) {
      styleBusy = styleBusy.then(async () => {
        saving(true);
        try {
          const d = await post("aisb_archer_design_style", Object.assign({ project_id: String(pid) }, data));
          S.guide = d.guide;
          renderRight();
          saving(false);
          if (data.photo_pref === undefined) reloadPreviews();
          toast(msg);
        } catch (err) {
          saving(false);
          toast(err.message, "err");
        }
      });
      return styleBusy;
    }

    async function uploadLogo(files) {
      const f = Array.from(files || []).find((x) => /^image\//.test(x.type));
      if (!f) return;
      saving(true, "logo opladen…");
      try {
        const d = await post("aisb_archer_intake_upload", { kind: "logo" }, [f]);
        await setStyle({ logo_id: String(d.items[0].id) }, "Logo staat erop.");
      } catch (err) {
        saving(false);
        toast(err.message, "err");
      }
    }

    async function uploadPhotos(files) {
      const list = Array.from(files || []).filter((x) => /^image\//.test(x.type)).slice(0, 30);
      if (!list.length) return;
      const status = $("[data-ds-photo-status]", right);
      if (status) status.textContent = `${list.length} foto${list.length === 1 ? "" : "'s"} opladen…`;
      saving(true, "foto's opladen…");
      try {
        const d = await post("aisb_archer_intake_upload", { kind: "photos" }, list);
        await photoOp({ op: "own_add", ids: JSON.stringify(d.items.map((it) => it.id)) });
        toast(`${d.items.length} foto${d.items.length === 1 ? "" : "'s"} opgeladen. Klik op "Vul de lege fotovakken" of "Andere stockfoto's" om ze te plaatsen.`);
      } catch (err) {
        saving(false);
        toast(err.message, "err");
      }
    }

    async function photoOp(data) {
      saving(true);
      try {
        const d = await post("aisb_archer_design_photos", Object.assign({ project_id: String(pid) }, data));
        S.photos = d.photos;
        renderRight();
        injectAll();
        saving(false);
        return d;
      } catch (err) {
        saving(false);
        toast(err.message, "err");
        throw err;
      }
    }

    /** Fotovakken pagina per pagina vullen (een voor een: de stijlgids heeft één schrijver). */
    async function fillPhotos(replace, onPage) {
      if (UI.flushAll) await UI.flushAll();
      await photoOp({ op: "state" }).catch(() => {});
      const pages = (S.photos.pages || []).filter((p) => p.count > 0);
      const status = () => $("[data-ds-photo-status]", right);
      let i = 0;
      let errors = 0;
      for (const p of pages) {
        i++;
        if (status()) status().textContent = `Foto's voor ${p.title} (${i} van ${pages.length})…`;
        if (onPage) onPage(i, pages.length, p);
        try {
          await photoOp({ op: "fill", page_slug: p.slug, replace: replace ? "1" : "" });
        } catch (e) {
          errors++;
        }
      }
      if (status()) status().textContent = errors ? `${errors} pagina${errors === 1 ? "" : "'s"} zonder foto's: probeer opnieuw.` : "Klaar.";
      return errors;
    }

    /* ── Foto's en stijl op het canvas ────────────────── */

    // Eigen foto's van deze site in https als de pagina https is (WordPress geeft hier http-adressen).
    const sameScheme = (u) => (location.protocol === "https:" && u.indexOf("http://" + location.host + "/") === 0 ? "https://" + u.slice(7) : u);
    const isLogoImg = (img) => !!(img.closest(".brxe-logo") || img.classList.contains("bricks-site-logo"));
    function inject(iframe) {
      const sec = iframe.closest(".archer-cv-sec");
      if (!sec) return;
      const uuid = sec.getAttribute("data-archer-cv-uuid");
      const range = uuid && S.photos.sections ? S.photos.sections[uuid] : null;
      let doc = null;
      try { doc = iframe.contentDocument; } catch (e) { return; }
      if (!doc || !doc.body) return;
      const imgs = Array.from(doc.querySelectorAll("img")).filter((img) => !isLogoImg(img));
      imgs.forEach((img) => { if (!img.hasAttribute("data-archer-orig")) img.setAttribute("data-archer-orig", img.getAttribute("src") || ""); });
      if (!range || !range.count) return;
      const list = (S.photos.images || []).slice(range.start, range.start + range.count);
      imgs.forEach((img, i) => {
        const it = list[i];
        if (it && it.full) {
          const src = sameScheme(it.full);
          if (img.getAttribute("src") !== src) { img.src = src; img.srcset = ""; img.style.objectFit = "cover"; }
        } else {
          const o = img.getAttribute("data-archer-orig");
          if (o !== null && img.getAttribute("src") !== o) { img.src = o; img.srcset = ""; }
        }
      });
    }
    function injectAll() {
      $$(".archer-cv-sec iframe, .aisb-wf-expanded iframe").forEach((f) => { try { if (f.contentDocument && f.contentDocument.readyState === "complete") inject(f); } catch (e) { /* ander domein */ } });
    }
    // load-gebeurtenissen van iframes bubbelen niet, maar zijn wel op te vangen.
    document.addEventListener("load", (e) => {
      const f = e.target;
      if (f && f.tagName === "IFRAME" && f.closest && f.closest(".archer-cv-sec, .aisb-wf-expanded")) setTimeout(() => inject(f), 30);
    }, true);

    let reloadTimer = null;
    function reloadPreviews() {
      clearTimeout(reloadTimer);
      reloadTimer = setTimeout(() => {
        $$(".archer-cv-sec iframe, .aisb-wf-expanded iframe").forEach((f) => {
          try { f.contentWindow.location.reload(); } catch (e) { f.src = f.src; }
        });
      }, 250);
    }

    /* ── Opbouw veranderd (sectie erbij, weg of verplaatst): foto's laten volgen ── */
    let layoutSig = "";
    let layoutTimer = null;
    function watchLayout() {
      const sig = wf.state.pages.map((p) => p.slug + ":" + ((wf.state.pageModels[p.slug] || {}).sections || []).map((x) => x.uuid).join(",")).join("|");
      if (!layoutSig) { layoutSig = sig; return; }
      if (sig === layoutSig) return;
      layoutSig = sig;
      clearTimeout(layoutTimer);
      layoutTimer = setTimeout(async () => {
        if (UI.wfGenState && UI.wfGenState().pending) return; // tijdens het maken: de run vult straks
        if (UI.flushAll) await UI.flushAll();
        photoOp({ op: "state" }).catch(() => {});
      }, 1500);
    }

    /* ── Wireframes volgen: links bijwerken ───────────── */
    let leftTimer = null;
    document.addEventListener("archer:wf-progress", () => {
      clearTimeout(leftTimer);
      leftTimer = setTimeout(renderLeft, 200);
    });

    /* ── Run na de intake, en taken na herladen ───────── */

    let dock = null;
    function dockShow(step, text, pct) {
      if (!dock) {
        dock = document.createElement("div");
        dock.className = "archer-build-dock";
        dock.setAttribute("role", "status");
        document.body.appendChild(dock);
      }
      const steps = [["sitemap", "Pagina's"], ["style", "Stijl"], ["wireframes", "Opbouw en teksten"], ["photos", "Foto's"]];
      const idx = steps.findIndex((s) => s[0] === step);
      dock.innerHTML = `<div class="archer-build-dock-steps">${steps.map(([k, l], i) => `<span class="${i < idx ? "is-done" : i === idx ? "is-active" : ""}">${esc(l)}</span>`).join("")}</div>
        <div class="archer-build-dock-text">${esc(text)}</div>
        <div class="archer-build-dock-bar"><i style="width:${Math.max(3, Math.min(100, pct || 0))}%"></i></div>`;
    }
    function dockDone(text) {
      if (!dock) return;
      dock.classList.add("is-done");
      dock.innerHTML = `<div class="archer-build-dock-text"><strong>${esc(text)}</strong></div><div class="archer-build-dock-actions"><button type="button" class="archer-btn archer-btn-primary archer-btn-sm" data-dock-close>Bekijk je website</button></div>`;
      $("[data-dock-close]", dock).addEventListener("click", () => { dock.remove(); dock = null; });
      setTimeout(() => { if (dock) { dock.remove(); dock = null; } }, 12000);
    }

    /** Wachten tot het canvas de wireframes kent (geladen of klaar met maken). */
    async function waitCanvas() {
      for (let i = 0; i < 120; i++) {
        const cols = $$(".archer-cv-col").length;
        if (wf.state.pages.length && cols >= wf.state.pages.length) break;
        await sleep(250);
      }
      await sleep(900); // de automatische start van het canvas (300 ms) eerst laten beginnen
    }
    async function waitWireframes(onTick) {
      for (let i = 0; i < 1200; i++) {
        const st = UI.wfGenState ? UI.wfGenState() : { pending: 0, total: 0, done: 0 };
        if (onTick) onTick(st);
        if (!st.pending) return st;
        await sleep(1000);
      }
      return UI.wfGenState ? UI.wfGenState() : {};
    }

    async function runBuild() {
      dockShow("wireframes", "Elke pagina krijgt secties uit de bibliotheek en eigen teksten…", 35);
      await waitCanvas();
      // Pagina's zonder wireframe (bv. na herladen tijdens de run): alsnog maken.
      const missing = wf.state.pages.filter((p) => !sectionsOf(p.slug)).map((p) => p.slug);
      const st0 = UI.wfGenState ? UI.wfGenState() : { pending: 0 };
      if (missing.length && !st0.pending && UI.generateWireframes) UI.generateWireframes(missing);
      await sleep(400);
      const st = await waitWireframes((s) => {
        if (s.total) dockShow("wireframes", `Pagina's opbouwen: ${s.done} van ${s.total} klaar…`, 35 + Math.round((s.done / s.total) * 40));
      });
      renderLeft();
      dockShow("photos", "Foto's plaatsen…", 78);
      const errors = await fillPhotos(false, (i, n, p) => dockShow("photos", `Foto's voor ${p.title} (${i} van ${n})…`, 78 + Math.round((i / n) * 20)));
      const url = new URL(window.location.href);
      url.searchParams.delete("aisb_build");
      window.history.replaceState({}, "", url.toString());
      try { sessionStorage.removeItem("archer_build_" + pid); } catch (e) { /* niets */ }
      const failed = (st && st.failed && st.failed.length) || 0;
      dockDone(failed || errors
        ? `Je website staat er. ${failed ? failed + " pagina('s) lukten niet: klik er links op en kies opnieuw proberen. " : ""}${errors ? "Niet alle foto's zijn geplaatst." : ""}`
        : "Je website staat er. Pas aan wat je wil en publiceer.");
    }

    async function runTask() {
      let task = null;
      try {
        task = JSON.parse(sessionStorage.getItem("archer_design_task_" + pid) || "null");
        sessionStorage.removeItem("archer_design_task_" + pid);
      } catch (e) { /* niets */ }
      if (!task) return;
      if (task.toast) toast(task.toast);
      if (task.generate && task.generate.length && UI.generateWireframes) {
        await waitCanvas();
        const todo = task.generate.filter((slug) => wf.state.pages.some((p) => p.slug === slug) && !sectionsOf(slug));
        if (!todo.length) return;
        UI.generateWireframes(todo);
        focusPage(todo[0]);
        await sleep(400);
        await waitWireframes();
        renderLeft();
        // Foto's voor de nieuwe pagina.
        await photoOp({ op: "state" }).catch(() => {});
        for (const slug of todo) await photoOp({ op: "fill", page_slug: slug }).catch(() => {});
        toast("De nieuwe pagina is klaar.");
      }
    }

    // Start.
    renderLeft();
    right.innerHTML = '<p class="archer-ds-note">Stijl en foto\'s laden…</p>';
    loadState()
      .catch((err) => { right.innerHTML = `<p class="archer-ds-note">${esc(err.message)}</p>`; })
      .then(() => {
        if (host.getAttribute("data-build") === "1") return runBuild();
        return runTask();
      })
      .catch((err) => console.error("[Archer] ontwerp", err));
    // Elke keer het canvas tekent (modellen geladen, wireframe klaar, sectie verplaatst): de paginalijst mee.
    const origWb = wf.renderWhiteboard;
    if (typeof origWb === "function") {
      wf.renderWhiteboard = function () {
        const r = origWb.apply(this, arguments);
        if (Object.keys(wf.state.pageModels || {}).length) modelsSeen = true;
        clearTimeout(leftTimer);
        leftTimer = setTimeout(renderLeft, 150);
        watchLayout();
        return r;
      };
    }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => setTimeout(init, 0));
  else init();
})();
