/**
 * Archer-interface, stap 1: de sitemap als lijst van paginakaarten.
 *
 * Werkt op dezelfde state als de bestaande canvas (window.AISBApp.state):
 * - per pagina de secties, met verplaatsen, verwijderen en toevoegen;
 * - subpagina's en pagina's toevoegen via de bestaande AI-route (addChildPage);
 * - pagina's verwijderen;
 * - elke wijziging wordt automatisch bewaard in de huidige sitemapversie.
 * De boomstructuur (oude canvas) blijft beschikbaar als tweede weergave.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const app = window.AISBApp;
    const UI = window.ARCHER_UI;
    const CFG = window.ARCHER;
    if (!app || !UI || !CFG) return;

    const card = document.querySelector(".archer-sitemap-card");
    const listEl = document.querySelector("[data-archer-sitemap-list]");
    const treeEl = document.querySelector(".archer-tree-view");
    if (!card || !listEl) return;

    const esc = UI.esc;
    const state = app.state;
    const LABELS = CFG.sectionLabels || {};
    const TYPES = Array.isArray(app.SECTION_TYPES) ? app.SECTION_TYPES : [];
    const GLOBAL_TYPES = ["Headers", "Footers", "Megamenu Sections - part of header section"];
    const isGlobal = (s) => GLOBAL_TYPES.includes(s.section_type);
    const typeLabel = (t) => LABELS[t] || (t || "Sectie").replace(/ Sections?$/, "");

    // Open formulieren: { kind: 'section'|'child'|'page', slug }
    let openForm = null;
    let confirmDelete = null;

    /* ── Weergave kiezen ─────────────────────────────────── */

    // Weergaven: canvas (standaard, zoals Relume), lijst, en de oude boomstructuur (Archer-team).
    const seg = card.querySelector(".archer-seg");
    const listBtn = card.querySelector('[data-archer-sitemap-view="list"]');
    const treeBtn = card.querySelector('[data-archer-sitemap-view="tree"]');
    if (seg && listBtn && !card.querySelector('[data-archer-sitemap-view="canvas"]')) {
      const cBtn = document.createElement("button");
      cBtn.type = "button";
      cBtn.className = "archer-seg-btn";
      cBtn.setAttribute("data-archer-sitemap-view", "canvas");
      cBtn.textContent = "Canvas";
      seg.insertBefore(cBtn, listBtn);
      listBtn.textContent = "Lijst";
      if (treeBtn) treeBtn.classList.add("archer-staff-only");
    }
    let currentView = "canvas";
    try {
      const saved = localStorage.getItem("archer_sitemap_view");
      if (saved === "list" || saved === "canvas") currentView = saved;
    } catch (e) {}

    const setView = (v) => {
      currentView = v;
      if (v !== "tree") {
        try { localStorage.setItem("archer_sitemap_view", v); } catch (e) {}
      }
      card.setAttribute("data-archer-view", v === "canvas" ? "list" : v);
      card.setAttribute("data-archer-layout", v);
      document.querySelectorAll("[data-archer-sitemap-view]").forEach((b) => {
        const on = b.getAttribute("data-archer-sitemap-view") === v;
        b.classList.toggle("is-active", on);
        b.setAttribute("aria-pressed", on ? "true" : "false");
      });
      if (treeEl) treeEl.hidden = v !== "tree";
      if (v === "canvas") view.fitted = false;
      if (v === "tree") {
        app.renderCanvas && app.renderCanvas();
        requestAnimationFrame(() => app.fitToView && app.fitToView());
      } else {
        render();
      }
    };
    document.querySelectorAll("[data-archer-sitemap-view]").forEach((b) =>
      b.addEventListener("click", () => setView(b.getAttribute("data-archer-sitemap-view"))),
    );
    card.setAttribute("data-archer-view", "list");
    card.setAttribute("data-archer-layout", currentView);

    document.querySelectorAll("[data-archer-sitemap-view]").forEach((b) => {
      const on = b.getAttribute("data-archer-sitemap-view") === currentView;
      b.classList.toggle("is-active", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });

    /* ── Canvas ──────────────────────────────────────────── */

    const view = { tx: 0, ty: 0, scale: 1, fitted: false };
    const vp = document.createElement("div");
    vp.className = "archer-smap-viewport";
    vp.innerHTML =
      '<div class="archer-smap-inner"><div class="archer-smap-tree" data-archer-smap-tree></div></div>' +
      '<div class="archer-cv-zoom archer-smap-zoom">' +
      '<button type="button" data-archer-smap-zoom="out" aria-label="Uitzoomen">−</button>' +
      '<span data-archer-smap-level>100%</span>' +
      '<button type="button" data-archer-smap-zoom="in" aria-label="Inzoomen">+</button>' +
      '<button type="button" data-archer-smap-zoom="fit">Passend</button></div>' +
      '<div class="archer-smap-hint">Sleep om te verschuiven · Ctrl + scroll om te zoomen</div>';
    const gridEl = document.createElement("div");
    gridEl.className = "archer-sitemap-grid";
    listEl.append(vp, gridEl);
    const innerEl = vp.querySelector(".archer-smap-inner");
    const treeBox = vp.querySelector("[data-archer-smap-tree]");
    const levelEl = vp.querySelector("[data-archer-smap-level]");

    const applyView = () => {
      innerEl.style.transform = `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})`;
      levelEl.textContent = Math.round(view.scale * 100) + "%";
    };
    const zoomAt = (next, cx, cy) => {
      next = Math.max(0.25, Math.min(1.5, next));
      const wx = (cx - view.tx) / view.scale;
      const wy = (cy - view.ty) / view.scale;
      view.scale = next;
      view.tx = cx - wx * next;
      view.ty = cy - wy * next;
      applyView();
    };
    const fit = () => {
      const w = vp.clientWidth;
      const tw = treeBox.offsetWidth;
      if (!w || !tw) return false;
      const scale = Math.max(0.35, Math.min(1, (w - 48) / tw));
      view.scale = scale;
      // De startpagina staat in het midden van de boom: die komt in het midden van het beeld.
      view.tx = (w - tw * scale) / 2;
      view.ty = 24;
      applyView();
      return true;
    };
    vp.querySelector(".archer-smap-zoom").addEventListener("click", (e) => {
      const b = e.target.closest("[data-archer-smap-zoom]");
      if (!b) return;
      const z = b.getAttribute("data-archer-smap-zoom");
      const cx = vp.clientWidth / 2;
      const cy = Math.min(vp.clientHeight / 2, 240);
      if (z === "in") zoomAt(view.scale * 1.2, cx, cy);
      else if (z === "out") zoomAt(view.scale / 1.2, cx, cy);
      else fit();
    });
    // Verschuiven: slepen op de achtergrond (niet op kaarten of knoppen).
    let pan = null;
    vp.addEventListener("pointerdown", (e) => {
      if (e.button !== 0) return;
      if (e.target.closest(".archer-page-card, .archer-add-page-card, .archer-smap-zoom, button, a, input, textarea, select, label, form")) return;
      pan = { x: e.clientX, y: e.clientY, tx: view.tx, ty: view.ty, id: e.pointerId };
      vp.setPointerCapture(e.pointerId);
      vp.classList.add("is-panning");
      e.preventDefault();
    });
    vp.addEventListener("pointermove", (e) => {
      if (!pan || e.pointerId !== pan.id) return;
      view.tx = pan.tx + (e.clientX - pan.x);
      view.ty = pan.ty + (e.clientY - pan.y);
      applyView();
    });
    const endPan = (e) => {
      if (!pan) return;
      try { vp.releasePointerCapture(pan.id); } catch (err) {}
      pan = null;
      vp.classList.remove("is-panning");
    };
    vp.addEventListener("pointerup", endPan);
    vp.addEventListener("pointercancel", endPan);
    // Scrollen verschuift het canvas; Ctrl/Cmd + scroll (of knijpen) zoomt.
    vp.addEventListener(
      "wheel",
      (e) => {
        e.preventDefault();
        const r = vp.getBoundingClientRect();
        if (e.ctrlKey || e.metaKey) {
          zoomAt(view.scale * Math.exp(-e.deltaY * 0.0025), e.clientX - r.left, e.clientY - r.top);
        } else {
          view.tx -= e.shiftKey && !e.deltaX ? e.deltaY : e.deltaX;
          view.ty -= e.shiftKey && !e.deltaX ? 0 : e.deltaY;
          applyView();
        }
      },
      { passive: false },
    );
    // Focus in een formulier mag de viewport niet laten verspringen.
    vp.addEventListener("scroll", () => {
      vp.scrollTop = 0;
      vp.scrollLeft = 0;
    });

    /** Boom: home bovenaan, hoofdpagina's in een rij, subpagina's eronder gestapeld. */
    const treeHtml = () => {
      const pages = state.pages || [];
      const bySlug = {};
      pages.forEach((p) => (bySlug[p.slug] = p));
      const kids = {};
      pages.forEach((p) => {
        if (p.slug === "home") return;
        let par = p.parent_slug && bySlug[p.parent_slug] && p.parent_slug !== p.slug ? p.parent_slug : "home";
        (kids[par] = kids[par] || []).push(p);
      });
      const seen = new Set();
      const branch = (p) => {
        if (seen.has(p.slug)) return "";
        seen.add(p.slug);
        const ch = (kids[p.slug] || []).filter((c) => !seen.has(c.slug));
        return (
          `<div class="archer-smap-node">${pageCard(p)}</div>` +
          (ch.length ? `<ul class="archer-smap-stack">${ch.map((c) => `<li>${branch(c)}</li>`).join("")}</ul>` : "")
        );
      };
      const home = bySlug.home;
      if (home) seen.add("home");
      const top = (kids.home || []).filter((p) => !seen.has(p.slug));
      const cols = top.map((p) => `<li class="archer-smap-col">${branch(p)}</li>`).join("");
      // Pagina's die nergens onder hangen (lussen in de data) achteraan.
      const rest = pages.filter((p) => !seen.has(p.slug)).map((p) => `<li class="archer-smap-col">${branch(p)}</li>`).join("");
      return (
        `<div class="archer-smap-root">` +
        (home ? `<div class="archer-smap-node archer-smap-home">${pageCard(home)}</div>` : "") +
        `<ul class="archer-smap-row">${cols}${rest}<li class="archer-smap-col archer-smap-col-add"><div class="archer-smap-node">${addPageCard()}</div></li></ul>` +
        `</div>`
      );
    };

    /* ── State-hulp ──────────────────────────────────────── */

    // Na een wijziging aan state.bySlug[slug]: pages, data.sitemap en de JSON bijwerken.
    const syncPage = (slug) => {
      const page = state.bySlug[slug];
      if (!page) return;
      const i = state.pages.findIndex((p) => app.normalizeSlug(p.slug) === slug);
      if (i >= 0) state.pages[i] = page;
      if (state.data && Array.isArray(state.data.sitemap)) {
        const j = state.data.sitemap.findIndex((p) => app.normalizeSlug(p.slug) === slug);
        if (j >= 0) state.data.sitemap[j] = page;
      }
    };

    const afterChange = () => {
      if (app.rawEl && state.data) app.rawEl.textContent = JSON.stringify(state.data, null, 2);
      if (card.getAttribute("data-archer-view") === "tree") {
        app.renderCanvas && app.renderCanvas({ skipLayout: true });
        if (state.activeSlug && app.renderDetail) app.renderDetail(state.activeSlug);
      }
      render();
      saver.run();
    };

    /* ── Autosave ────────────────────────────────────────── */

    const saver = UI.debouncedSaver(async () => {
      if (!state.data || !state.projectId || !state.sitemapId) return;
      await UI.post("aisb_archer_autosave_sitemap", {
        project_id: state.projectId,
        sitemap_id: state.sitemapId,
        sitemap_json: JSON.stringify(stripTransient(state.data)),
      });
      if (app.deepClone) state.baselineData = app.deepClone(state.data);
    }, 900);

    const stripTransient = (data) => {
      if (app.stripTransient) {
        try {
          return app.stripTransient(data);
        } catch (e) {}
      }
      return JSON.parse(
        JSON.stringify(data, (k, v) => (k === "_x" || k === "_y" || k === "_userMoved" ? undefined : v)),
      );
    };

    // De bestaande editor (boomweergave) wijzigt ook de state: na elke invoer bewaren.
    app.detailBodyEl &&
      app.detailBodyEl.addEventListener("input", () => {
        saver.run();
      });
    app.nodesEl &&
      app.nodesEl.addEventListener("drop", () => {
        setTimeout(() => saver.run(), 0);
      });

    /* ── Rendering ───────────────────────────────────────── */

    const orderPages = () => {
      const pages = (state.pages || []).slice();
      const bySlug = {};
      pages.forEach((p) => (bySlug[p.slug] = p));
      const out = [];
      const seen = new Set();
      const walk = (slug) => {
        if (seen.has(slug) || !bySlug[slug]) return;
        seen.add(slug);
        out.push(bySlug[slug]);
        pages.filter((p) => p.parent_slug === slug).forEach((c) => walk(c.slug));
      };
      walk("home");
      pages.filter((p) => !p.parent_slug || p.parent_slug === "home").forEach((p) => walk(p.slug));
      pages.forEach((p) => walk(p.slug));
      return out;
    };

    const sectionOptions = () =>
      TYPES.filter((t) => !GLOBAL_TYPES.includes(t))
        .map((t) => `<option value="${esc(t)}">${esc(typeLabel(t))}</option>`)
        .join("");

    const icon = {
      up: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6"></path></svg>',
      down: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>',
      x: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>',
    };

    const pageCard = (page) => {
      const slug = page.slug;
      const sections = Array.isArray(page.sections) ? page.sections : [];
      const isHome = slug === "home";
      // De contactpagina blijft altijd staan (de laatste die er is).
      const isContact = /^contact$/i.test(page.page_type || "") || /(^|-)(contact|kontakt)/i.test(slug);
      const contactCount = state.pages.filter((p) => /^contact$/i.test(p.page_type || "") || /(^|-)(contact|kontakt)/i.test(p.slug || "")).length;
      const keep = isHome || (isContact && contactCount <= 1);
      const parent = page.parent_slug && page.parent_slug !== "home" ? state.bySlug[page.parent_slug] : null;
      const movable = sections
        .map((s, i) => ({ s, i }))
        .filter((x) => !isGlobal(x.s));
      const firstMovable = movable.length ? movable[0].i : -1;
      const lastMovable = movable.length ? movable[movable.length - 1].i : -1;

      const secHtml = sections
        .map((s, i) => {
          const g = isGlobal(s);
          const tools = g
            ? ""
            : `<div class="archer-sec-tools">
                 <button type="button" class="archer-sec-tool" data-act="sec-up" data-slug="${esc(slug)}" data-idx="${i}" aria-label="${esc(s.section_name)} naar boven" ${i === firstMovable ? "disabled" : ""}>${icon.up}</button>
                 <button type="button" class="archer-sec-tool" data-act="sec-down" data-slug="${esc(slug)}" data-idx="${i}" aria-label="${esc(s.section_name)} naar beneden" ${i === lastMovable ? "disabled" : ""}>${icon.down}</button>
                 <button type="button" class="archer-sec-tool is-danger" data-act="sec-del" data-slug="${esc(slug)}" data-idx="${i}" aria-label="${esc(s.section_name)} verwijderen">${icon.x}</button>
               </div>`;
          return `<li class="archer-sec${g ? " is-global" : ""}">
              <div class="archer-sec-body">
                <div class="archer-sec-type">${esc(typeLabel(s.section_type))}${g ? " · op elke pagina" : ""}</div>
                <div class="archer-sec-name">${esc(s.section_name || "Sectie")}</div>
                ${!g && s.purpose ? `<div class="archer-sec-desc">${esc(s.purpose)}</div>` : ""}
              </div>
              ${tools}
            </li>`;
        })
        .join("");

      const sectionForm =
        openForm && openForm.kind === "section" && openForm.slug === slug
          ? `<form class="archer-inline-form" data-form="section" data-slug="${esc(slug)}">
               <label class="aisb-label" for="archer-sec-type-${esc(slug)}">Soort sectie</label>
               <select id="archer-sec-type-${esc(slug)}" name="type">${sectionOptions()}</select>
               <label class="aisb-label" for="archer-sec-name-${esc(slug)}">Naam <span class="archer-muted">(optioneel)</span></label>
               <input type="text" id="archer-sec-name-${esc(slug)}" name="name" placeholder="Bijvoorbeeld: Onze diensten">
               <div class="archer-form-row">
                 <button type="submit" class="archer-btn archer-btn-primary">Sectie toevoegen</button>
                 <button type="button" class="archer-btn archer-btn-ghost" data-act="cancel">Annuleren</button>
               </div>
               <span class="archer-muted archer-small">De teksten komen in de stap Wireframes.</span>
             </form>`
          : "";

      const childForm =
        openForm && openForm.kind === "child" && openForm.slug === slug
          ? `<form class="archer-inline-form" data-form="child" data-slug="${esc(slug)}">
               <label class="aisb-label" for="archer-child-title-${esc(slug)}">Subpagina onder ${esc(page.page_title || slug)}</label>
               <input type="text" id="archer-child-title-${esc(slug)}" name="title" placeholder="Naam van de pagina" required>
               <label class="aisb-label" for="archer-child-desc-${esc(slug)}">Waarvoor dient deze pagina?</label>
               <textarea id="archer-child-desc-${esc(slug)}" name="desc" rows="2" placeholder="Bijvoorbeeld: uitleg over onderhoud van warmtepompen" required></textarea>
               <div class="archer-form-row">
                 <button type="submit" class="archer-btn archer-btn-primary">Toevoegen</button>
                 <button type="button" class="archer-btn archer-btn-ghost" data-act="cancel">Annuleren</button>
               </div>
               <span class="archer-muted archer-small">AI stelt meteen de secties voor deze pagina op.</span>
             </form>`
          : "";

      const delConfirm =
        confirmDelete === slug
          ? `<div class="archer-inline-form" role="alert">
               <strong>Pagina ${esc(page.page_title || slug)} verwijderen?</strong>
               <span class="archer-small archer-muted">Subpagina's schuiven een niveau op.</span>
               <div class="archer-form-row">
                 <button type="button" class="archer-btn archer-btn-primary" data-act="page-del-yes" data-slug="${esc(slug)}">Ja, verwijderen</button>
                 <button type="button" class="archer-btn archer-btn-ghost" data-act="cancel">Annuleren</button>
               </div>
             </div>`
          : "";

      const meta = isHome ? "Startpagina" : parent ? `Subpagina van ${esc(parent.page_title || parent.slug)}` : "Pagina in het menu";
      const badge =
        page.archer_added === "source"
          ? '<span class="archer-page-badge">van je huidige website</span>'
          : page.archer_added === "detail"
            ? '<span class="archer-page-badge">sjabloon per item</span>'
            : isContact && keep
              ? '<span class="archer-page-badge">altijd aanwezig</span>'
              : "";

      return `<article class="archer-page-card${parent ? " is-child" : ""}" data-page="${esc(slug)}">
          <div class="archer-page-card-head">
            <div>
              <h3 class="archer-page-card-title">${esc(page.page_title || page.nav_label || slug)}${badge}</h3>
              <div class="archer-page-card-meta">${meta} · /${esc(isHome ? "" : slug)} · ${sections.length} secties</div>
            </div>
            ${keep ? "" : `<button type="button" class="archer-sec-tool is-danger" data-act="page-del" data-slug="${esc(slug)}" aria-label="Pagina ${esc(page.page_title || slug)} verwijderen">${icon.x}</button>`}
          </div>
          ${page.page_purpose ? `<p class="archer-page-card-purpose">${esc(page.page_purpose)}</p>` : ""}
          ${delConfirm}
          <ul class="archer-sec-list">${secHtml}</ul>
          ${sectionForm}${childForm}
          ${
            sectionForm || childForm
              ? ""
              : `<div class="archer-add-row">
                   <button type="button" class="archer-link-btn" data-act="open-section" data-slug="${esc(slug)}">+ Sectie toevoegen</button>
                   <button type="button" class="archer-link-btn" data-act="open-child" data-slug="${esc(slug)}">+ Subpagina</button>
                 </div>`
          }
        </article>`;
    };

    const addPageCard = () => {
      if (openForm && openForm.kind === "page") {
        return `<form class="archer-add-page-card archer-inline-form" data-form="page">
            <label class="aisb-label" for="archer-page-title">Naam van de pagina</label>
            <input type="text" id="archer-page-title" name="title" placeholder="Bijvoorbeeld: Realisaties" required>
            <label class="aisb-label" for="archer-page-desc">Waarvoor dient deze pagina?</label>
            <textarea id="archer-page-desc" name="desc" rows="2" required placeholder="Bijvoorbeeld: foto's en korte uitleg bij afgewerkte projecten"></textarea>
            <div class="archer-form-row">
              <button type="submit" class="archer-btn archer-btn-primary">Pagina toevoegen</button>
              <button type="button" class="archer-btn archer-btn-ghost" data-act="cancel">Annuleren</button>
            </div>
            <span class="archer-muted archer-small">Krijgt automatisch de header en footer, en komt in het menu.</span>
          </form>`;
      }
      return `<div class="archer-add-page-card">
          <button type="button" class="archer-btn archer-btn-outline" data-act="open-page">+ Pagina toevoegen</button>
          <span class="archer-muted archer-small">AI stelt meteen de secties voor die pagina op, op basis van de beschrijving.</span>
        </div>`;
    };

    const render = () => {
      if (!state.data || !Array.isArray(state.pages)) {
        treeBox.innerHTML = "";
        gridEl.innerHTML = "";
        return;
      }
      const pages = orderPages();
      const asCanvas = currentView === "canvas";
      vp.hidden = !asCanvas;
      gridEl.hidden = asCanvas;
      if (asCanvas) {
        treeBox.innerHTML = treeHtml();
        gridEl.innerHTML = "";
        if (!view.fitted) {
          view.fitted = fit();
          if (!view.fitted) setTimeout(() => { if (!view.fitted) view.fitted = fit(); }, 120);
        }
      } else {
        treeBox.innerHTML = "";
        gridEl.innerHTML = pages.map(pageCard).join("") + addPageCard();
      }

      const total = pages.reduce((n, p) => n + (Array.isArray(p.sections) ? p.sections.length : 0), 0);
      const aside = card.querySelector("[data-archer-head-aside]");
      if (aside) {
        aside.innerHTML = `<span class="archer-stat"><strong>${pages.length}</strong> pagina's</span><span class="archer-stat"><strong>${total}</strong> secties</span>`;
      }
      if (openForm) {
        const f = listEl.querySelector("form input, form select");
        f && f.focus({ preventScroll: true });
      }
    };

    /* ── Acties ──────────────────────────────────────────── */

    listEl.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-act]");
      if (!btn) return;
      const act = btn.getAttribute("data-act");
      const slug = btn.getAttribute("data-slug");
      const idx = parseInt(btn.getAttribute("data-idx") || "", 10);
      const page = slug ? state.bySlug[slug] : null;

      if (act === "cancel") {
        openForm = null;
        confirmDelete = null;
        render();
        return;
      }
      if (act === "open-section") { openForm = { kind: "section", slug }; confirmDelete = null; render(); return; }
      if (act === "open-child") { openForm = { kind: "child", slug }; confirmDelete = null; render(); return; }
      if (act === "open-page") { openForm = { kind: "page" }; confirmDelete = null; render(); return; }

      if (act === "sec-up" || act === "sec-down") {
        if (!page || !Array.isArray(page.sections)) return;
        const step = act === "sec-up" ? -1 : 1;
        let to = idx + step;
        while (page.sections[to] && isGlobal(page.sections[to])) to += step;
        if (!page.sections[to]) return;
        const arr = page.sections.slice();
        const [m] = arr.splice(idx, 1);
        arr.splice(to, 0, m);
        page.sections = arr;
        syncPage(slug);
        afterChange();
        listEl.querySelector(`[data-act="${act}"][data-slug="${CSS.escape(slug)}"][data-idx="${to}"]`)?.focus({ preventScroll: true });
        return;
      }
      if (act === "sec-del") {
        if (!page || !Array.isArray(page.sections)) return;
        page.sections = page.sections.filter((_, i) => i !== idx);
        syncPage(slug);
        afterChange();
        return;
      }
      if (act === "page-del") {
        confirmDelete = slug;
        openForm = null;
        render();
        return;
      }
      if (act === "page-del-yes") {
        deletePage(slug);
        return;
      }
    });

    listEl.addEventListener("submit", async (e) => {
      const form = e.target.closest("form[data-form]");
      if (!form) return;
      e.preventDefault();
      const kind = form.getAttribute("data-form");
      const slug = form.getAttribute("data-slug");

      if (kind === "section") {
        const page = state.bySlug[slug];
        if (!page) return;
        const type = form.elements.type.value;
        const name = (form.elements.name.value || "").trim() || typeLabel(type);
        const sections = Array.isArray(page.sections) ? page.sections.slice() : [];
        // Voor de footer invoegen, zodat die onderaan blijft.
        let at = sections.length;
        for (let i = sections.length - 1; i >= 0; i--) {
          if (sections[i].section_type === "Footers") { at = i; break; }
        }
        sections.splice(at, 0, { section_name: name, section_type: type, purpose: "", key_content: [] });
        page.sections = sections;
        syncPage(slug);
        openForm = null;
        afterChange();
        return;
      }

      if (kind === "child" || kind === "page") {
        const title = (form.elements.title.value || "").trim();
        const desc = (form.elements.desc.value || "").trim();
        if (title.length < 2 || desc.length < 3) return;
        const submit = form.querySelector('[type="submit"]');
        if (submit) {
          submit.disabled = true;
          submit.textContent = "Secties maken…";
        }
        // addChildPage klikt na afloop zelf op "Nieuwe versie"; dat willen we niet,
        // want autosave bewaart in de huidige versie.
        const realSave = app.btnSave;
        app.btnSave = null;
        try {
          await app.addChildPage(kind === "page" ? "home" : slug, title, desc);
        } finally {
          app.btnSave = realSave;
        }
        openForm = null;
        render();
        saver.run();
      }
    });

    const deletePage = (slug) => {
      if (slug === "home") return;
      const page = state.bySlug[slug];
      if (!page) return;
      const parent = page.parent_slug || "home";
      // Subpagina's komen onder de ouder van de verwijderde pagina.
      (state.pages || []).forEach((p) => {
        if (p.parent_slug === slug) p.parent_slug = parent;
      });
      state.pages = (state.pages || []).filter((p) => p.slug !== slug);
      if (state.data && Array.isArray(state.data.sitemap)) {
        state.data.sitemap = state.data.sitemap.filter((p) => app.normalizeSlug(p.slug) !== slug);
        state.data.sitemap.forEach((p) => {
          if (app.normalizeSlug(p.parent_slug) === slug) p.parent_slug = parent;
        });
      }
      state.bySlug = app.buildIndex(state.pages);
      state.tree = app.buildTree(state.bySlug);
      if (state.activeSlug === slug) state.activeSlug = "home";
      confirmDelete = null;
      afterChange();
    };

    /* ── Haken in de bestaande app ───────────────────────── */

    // Elke keer dat de bestaande app de canvas tekent, ook de lijst bijwerken.
    const origRenderCanvas = app.renderCanvas;
    if (typeof origRenderCanvas === "function") {
      app.renderCanvas = function () {
        const r = origRenderCanvas.apply(this, arguments);
        render();
        return r;
      };
    }
    const origRenderAll = app.renderAll;
    if (typeof origRenderAll === "function") {
      app.renderAll = function () {
        const r = origRenderAll.apply(this, arguments);
        render();
        return r;
      };
    }
    // Statusberichten van stap 1 in het Nederlands.
    const origSetStatus = app.setStatus;
    if (typeof origSetStatus === "function") {
      const map = [
        [/^Loading selected sitemap…$/, "De sitemap wordt geladen…"],
        [/^<div class="aisb-ok">Loaded\.<\/div>$/, ""],
        [/^<div class="aisb-ok">Done\.<\/div>$/, '<div class="aisb-ok">Klaar: de sitemap staat. Pas pagina\'s en secties aan; layouts en teksten maken we in de volgende stap.</div>'],
        [/^Generating sitemap and canvas layout….*$/, "We maken de sitemap. Dit kan enkele minuten duren."],
        [/^Creating child page…$/, "Pagina toevoegen…"],
        [/Child page created\. Saving state\.\.\./, "Pagina toegevoegd."],
      ];
      app.setStatus = function (html) {
        let h = html;
        if (typeof h === "string") for (const [re, to] of map) if (re.test(h)) { h = h.replace(re, to); break; }
        // Project laden mislukt: niet een lege pagina tonen, maar het startscherm met de melding.
        if (typeof h === "string" && /Could not load|Failed to load|aisb-error/i.test(h) && (!app.outWrap || app.outWrap.style.display === "none")) {
          const shell = document.querySelector(".archer-shell");
          if (shell && shell.hasAttribute("data-archer-has-project")) {
            shell.removeAttribute("data-archer-has-project");
            h = '<div class="aisb-error">Deze website kon niet geladen worden. Controleer of je aangemeld bent met het account dat de website maakte, of open ze opnieuw via Mijn websites.</div>';
          }
        }
        return origSetStatus.call(this, h);
      };
    }

    // Als de sitemap al geladen was voor dit script draaide.
    if (state.data) render();
  });
})();
