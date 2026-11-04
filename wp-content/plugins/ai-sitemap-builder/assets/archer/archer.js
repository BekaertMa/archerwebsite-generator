/**
 * Archer-interface: gedeelde shell voor alle stappen.
 *
 * - "automatisch bewaard"-status in de topbar;
 * - wachtende bewaaracties afronden voor je naar een andere stap gaat;
 * - startscherm (beschrijving of bestaande website);
 * - wireframes: paginatabs, volgende/vorige pagina, labels en autosave;
 * - stijl en foto's: twee weergaven van de stijlgids, met autosave naar de server;
 * - publiceren: Nederlandse labels en melding na publicatie.
 *
 * Draait op DOMContentLoaded, dus na de scripts van de stapmodules.
 */
(function () {
  "use strict";

  if (!window.ARCHER) return;
  const CFG = window.ARCHER;

  const UI = (window.ARCHER_UI = {
    flushers: [],
    pendingCount: 0,
  });

  // Voorbeelden kennen het project: de header en footer krijgen het menu en de naam van deze website.
  (function () {
    const q = new URLSearchParams(location.search);
    const pid = q.get("aisb_project");
    const sid = q.get("aisb_sitemap");
    if (!pid) return;
    ["AISB_WF", "AISB_SG", "AISB_DESIGN"].forEach((k) => {
      const o = window[k];
      if (o && typeof o.previewUrl === "string" && o.previewUrl.indexOf("aisb_project=") < 0) {
        o.previewUrl = o.previewUrl.replace(
          "?aisb_bricks_preview=",
          "?aisb_project=" + encodeURIComponent(pid) + (sid ? "&aisb_sitemap=" + encodeURIComponent(sid) : "") + "&aisb_bricks_preview=",
        );
      }
    });
  })();

  /** Hoeken van knoppen en velden (dezelfde waarden als AISB_Archer_Taste::RADIUS). */
  UI.RADIUS = { sharp: "0px", soft: "6px", round: "999px" };
  /** Hoeken die een Archer-stijl overschrijven (zelfde waarden als AISB_Archer_Styles::RADII). */
  UI.RADII = {
    sharp: { button: 0, card: 0, image: 0, field: 0 },
    soft: { button: 8, card: 14, image: 14, field: 8 },
    round: { button: 999, card: 24, image: 24, field: 12 },
  };
  UI.radiusVars = (key) => {
    const r = UI.RADII[key];
    if (!r) return "";
    return `--a-radius-button:${r.button}px;--a-radius-card:${r.card}px;--a-radius-image:${r.image}px;--a-radius-field:${r.field}px;` +
      `--archer-radius:${r.button}px;--brxw-radius-xs:${Math.min(r.card, 6)}px;--brxw-radius-s:${Math.min(r.card, 10)}px;` +
      `--brxw-radius-m:${r.card}px;--brxw-radius-l:${r.card}px;--brxw-radius-xl:${r.card}px;`;
  };
  UI.applyRadius = (doc, key) => {
    if (!doc || !doc.head) return;
    const v = UI.RADIUS[key];
    let st = doc.getElementById("archer-radius");
    if (!v) { if (st) st.remove(); return; }
    if (!st) {
      st = doc.createElement("style");
      st.id = "archer-radius";
    }
    doc.head.appendChild(st);
    st.textContent = `:root{--archer-radius:${v};}`;
  };

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) =>
    (s ?? "")
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  UI.esc = esc;

  UI.post = async function (action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", CFG.nonce);
    Object.keys(data || {}).forEach((k) => fd.append(k, data[k]));
    const res = await fetch(CFG.ajaxUrl, { method: "POST", credentials: "same-origin", body: fd });
    let json = null;
    try {
      json = await res.json();
    } catch (e) {
      throw new Error("De server gaf geen geldig antwoord.");
    }
    // WordPress antwoordt "0" (niet aangemeld) of "-1" (verlopen beveiligingscode) zonder uitleg.
    if (json === 0 || json === -1) {
      throw new Error("Je bent niet meer aangemeld of de pagina staat te lang open. Vernieuw de pagina (en meld je opnieuw aan) en probeer opnieuw.");
    }
    if (!res.ok || !json || json.success !== true) {
      throw new Error((json && json.data && json.data.message) || "Er ging iets mis.");
    }
    return json.data;
  };

  /** Vervangt losse Engelse tekstknopen in een blok door Nederlandse tekst. */
  UI.translate = function (root, dict) {
    if (!root) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((n) => {
      const t = n.nodeValue.trim();
      if (t && Object.prototype.hasOwnProperty.call(dict, t)) {
        n.nodeValue = n.nodeValue.replace(t, dict[t]);
      }
    });
  };

  /* ── Bewaarstatus ───────────────────────────────────────── */

  UI.setSave = function (state, text) {
    const pill = $("[data-archer-save-pill]");
    if (!pill) return;
    pill.setAttribute("data-state", state);
    const t = $("[data-archer-save-text]", pill);
    if (t) {
      t.textContent =
        text ||
        (state === "saving" ? "bewaren…" : state === "error" ? "niet bewaard" : "automatisch bewaard");
    }
  };

  /**
   * Maakt een uitgestelde bewaaractie. Elke run() schuift de timer op; flush()
   * voert een wachtende actie meteen uit. Gebruikt door sitemap, wireframes en stijl.
   */
  UI.debouncedSaver = function (fn, delay) {
    let timer = null;
    let running = null;
    let dirty = false;
    const exec = async () => {
      timer = null;
      if (running) {
        dirty = true;
        return running;
      }
      dirty = false;
      UI.setSave("saving");
      running = (async () => {
        try {
          await fn();
          UI.setSave("saved");
        } catch (e) {
          console.error("[Archer] bewaren mislukt", e);
          UI.setSave("error", "niet bewaard: " + (e.message || "fout"));
        } finally {
          running = null;
        }
      })();
      await running;
      if (dirty) return exec();
    };
    const saver = {
      run() {
        UI.setSave("saving", "wijzigingen…");
        clearTimeout(timer);
        timer = setTimeout(exec, delay || 800);
      },
      async flush() {
        if (timer) {
          clearTimeout(timer);
          await exec();
        } else if (running) {
          await running;
        }
      },
    };
    UI.flushers.push(saver.flush);
    return saver;
  };

  UI.flushAll = async function () {
    await Promise.all(UI.flushers.map((f) => f().catch(() => {})));
  };

  // Navigatie tussen stappen: eerst alles bewaren.
  document.addEventListener("click", async (e) => {
    const a = e.target.closest("a[data-archer-nav], .archer-stepbar a.archer-step, .archer-progress a");
    if (!a || a.getAttribute("aria-disabled") === "true") return;
    const href = a.getAttribute("href");
    if (!href || href === "#") {
      e.preventDefault();
      return;
    }
    if (e.metaKey || e.ctrlKey || e.shiftKey || a.target === "_blank") return;
    e.preventDefault();
    await UI.flushAll();
    window.location.href = href;
  });

  /* ── Projectcontext in URL en links ─────────────────────── */

  UI.setContext = function (projectId, sitemapId) {
    if (!projectId) return;
    CFG.projectId = projectId;
    if (sitemapId) CFG.sitemapId = sitemapId;
    const url = new URL(window.location.href);
    url.searchParams.set("aisb_project", projectId);
    if (sitemapId) url.searchParams.set("aisb_sitemap", sitemapId);
    if (!url.searchParams.get("aisb_step")) url.searchParams.set("aisb_step", "1");
    window.history.replaceState({}, "", url.toString());

    // Nieuw project in deze sessie: de bewaarstatus verschijnt in de topbar.
    if (!$("[data-archer-save-pill]")) {
      const right = $(".archer-topbar-right");
      const name = (window.AISBApp && window.AISBApp.state.data && window.AISBApp.state.data.website_name) || "Nieuwe website";
      if (right) {
        const pill = document.createElement("span");
        pill.className = "archer-save-pill";
        pill.setAttribute("data-archer-save-pill", "");
        pill.setAttribute("data-state", "saved");
        pill.innerHTML =
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>' +
          '<span class="archer-save-name">' + esc(name) + '</span><span class="archer-save-sep" aria-hidden="true">·</span><span data-archer-save-text>automatisch bewaard</span>';
        right.prepend(pill);
      }
    }

    $$("[data-archer-stepbar] a.archer-step, [data-archer-step-footer] a").forEach((a) => {
      const step = a.getAttribute("data-step");
      let target;
      if (step) {
        target = new URL(window.location.href);
        target.searchParams.set("aisb_step", step);
        const view = a.getAttribute("data-view");
        if (view) target.searchParams.set("aisb_view", view);
        else target.searchParams.delete("aisb_view");
      } else {
        const href = a.getAttribute("href");
        if (!href || href === "#") return;
        target = new URL(href, window.location.href);
      }
      target.searchParams.set("aisb_project", projectId);
      if (sitemapId) target.searchParams.set("aisb_sitemap", sitemapId);
      a.setAttribute("href", target.toString());
      a.classList.remove("is-disabled");
      a.removeAttribute("aria-disabled");
      a.removeAttribute("tabindex");
    });
  };

  /* ── Startscherm ────────────────────────────────────────── */

  function initStart() {
    const app = window.AISBApp;
    const start = $("[data-archer-start]");
    if (!app || !start) return;

    let mode = "desc";
    const setMode = (m) => {
      mode = m;
      $$("[data-archer-mode]", start).forEach((b) => {
        const on = b.getAttribute("data-archer-mode") === m;
        b.classList.toggle("is-active", on);
        b.setAttribute("aria-pressed", on ? "true" : "false");
      });
      $$("[data-archer-mode-panel]", start).forEach((p) => {
        p.hidden = p.getAttribute("data-archer-mode-panel") !== m;
      });
    };
    $$("[data-archer-mode]", start).forEach((b) =>
      b.addEventListener("click", () => setMode(b.getAttribute("data-archer-mode"))),
    );

    // Contactgegevens van de zaak: komen in de footer, het contactblok en de belknoppen.
    const card = $(".archer-start-card", start);
    const genBtn = card && $("[data-aisb-generate]", card);
    if (card && genBtn && !$("[data-archer-contact]", card)) {
      const box = document.createElement("details");
      box.className = "archer-more archer-contact-box";
      box.setAttribute("data-archer-contact", "");
      box.open = true;
      box.innerHTML =
        '<summary>Contactgegevens <span class="archer-muted">(aanbevolen)</span></summary>' +
        '<p class="archer-muted archer-small">Komen in de footer, op de contactpagina en achter de knoppen "Bel ons" en "Mail ons". Leeg laten mag: dan staat er een plaatshouder die je later invult.</p>' +
        '<div class="aisb-brief-grid">' +
        '<div class="aisb-field"><label class="aisb-label" for="archer-c-address">Adres</label><input id="archer-c-address" class="archer-input" autocomplete="street-address" placeholder="Straat 1, 9000 Gent"></div>' +
        '<div class="aisb-field"><label class="aisb-label" for="archer-c-phone">Telefoon</label><input id="archer-c-phone" type="tel" class="archer-input" autocomplete="tel" placeholder="09 123 45 67"></div>' +
        '<div class="aisb-field"><label class="aisb-label" for="archer-c-email">E-mail</label><input id="archer-c-email" type="email" class="archer-input" autocomplete="email" placeholder="info@zaak.be"></div>' +
        '<div class="aisb-field"><label class="aisb-label" for="archer-c-socials">Sociale media <span class="archer-muted">(links, optioneel)</span></label><input id="archer-c-socials" class="archer-input" placeholder="instagram.com/zaak, facebook.com/zaak"></div>' +
        "</div>";
      genBtn.before(box);
    }
    UI.readContact = () => ({
      address: (($("#archer-c-address") || {}).value || "").trim(),
      phone: (($("#archer-c-phone") || {}).value || "").trim(),
      email: (($("#archer-c-email") || {}).value || "").trim(),
      socials: (($("#archer-c-socials") || {}).value || "").trim(),
    });
    /** Lege velden aanvullen met wat op de huidige website staat; geeft de gegevens terug. */
    UI.fillContact = (found) => {
      const set = (id, v) => {
        const el = $(id);
        if (el && !el.value.trim() && v) el.value = v;
      };
      set("#archer-c-phone", found.phone || "");
      set("#archer-c-email", found.email || "");
      set("#archer-c-address", found.address || "");
      set("#archer-c-socials", (found.socials || []).join(", "));
      return UI.readContact();
    };

    // Nederlandse knoptekst tijdens het genereren.
    const btnLabel = "Genereer de sitemap →";
    if (app.btnGen && !app.btnGen.disabled) app.btnGen.textContent = btnLabel;
    // wp_localize_script maakt van 0 de tekst "0", die in JS waar is: demo-melding weg als er een sleutel is.
    if (window.AISB && String(window.AISB.demoMode) === "0" && app.demoNoteEl) app.demoNoteEl.style.display = "none";
    app.setLoading = (loading) => {
      if (!app.btnGen) return;
      app.btnGen.disabled = !!loading;
      app.btnGen.textContent = loading ? "Bezig met de sitemap… dit kan enkele minuten duren" : btnLabel;
    };

    // Genereren: eerst de structuur (meteen op het canvas), dan de secties per pagina, parallel.
    if (app.btnGen) {
      app.btnGen.addEventListener(
        "click",
        async (e) => {
          if (typeof UI.fastGenerate !== "function") return; // terugval: de gewone generatie
          e.preventDefault();
          e.stopImmediatePropagation();
          const languages = [($("#aisb-languages") || {}).value || "Dutch"];
          const pageCount = ($("#aisb-pagecount") || {}).value || "5-10";
          let opts;
          if (mode === "url") {
            const urlEl = $("#archer-site-url");
            const url = (urlEl.value || "").trim();
            if (url.length < 4) {
              app.setStatus('<div class="aisb-error">Vul het adres van de huidige website in.</div>');
              urlEl.focus();
              return;
            }
            opts = { mode: "url", url, prompt: (($("#archer-site-extra") || {}).value || "").trim(), languages, pageCount, contact: UI.readContact() };
          } else {
            const prompt = (app.promptEl.value || "").trim();
            if (prompt.length < 10) {
              app.setStatus('<div class="aisb-error">Beschrijf de zaak in een paar zinnen: wat ze doet, voor wie en in welke regio.</div>');
              app.promptEl.focus();
              return;
            }
            opts = { mode: "desc", prompt, languages, pageCount, contact: UI.readContact() };
          }
          app.setStatus("");
          app.btnGen.disabled = true;
          try {
            await UI.fastGenerate(opts);
          } catch (err) {
            console.error("[Archer] generatie", err);
          } finally {
            app.btnGen.disabled = false;
          }
        },
        true,
      );
    }

    // Zodra er een sitemap is: startscherm weg, projectcontext in de URL.
    const origRenderAll = app.renderAll;
    if (typeof origRenderAll === "function") {
      app.renderAll = function (data) {
        const out = origRenderAll.apply(this, arguments);
        $(".archer-shell")?.classList.add("archer-has-output");
        UI.setContext(app.state.projectId, app.state.sitemapId);
        return out;
      };
    }
    // Ook voor laden via URL (autoLoadFromUrl gebruikt de interne renderAll).
    const outWrap = app.outWrap;
    if (outWrap) {
      let firstShow = true;
      new MutationObserver(() => {
        if (outWrap.style.display !== "none") {
          $(".archer-shell")?.classList.add("archer-has-output");
          if (app.state.projectId) UI.setContext(app.state.projectId, app.state.sitemapId);
          // De oude app scrollt naar de output; bij het openen van een project
          // blijft de stappenbalk liever in beeld.
          if (firstShow && CFG.projectId) {
            setTimeout(() => window.scrollTo({ top: 0 }), 60);
          }
          firstShow = false;
        }
      }).observe(outWrap, { attributes: true, attributeFilter: ["style"] });
    }
  }

  /* ── Wireframes ─────────────────────────────────────────── */

  function initWireframes() {
    const wf = window.AISB_WF_App;
    if (!wf) return;
    const tabsEl = $("[data-archer-wf-tabs]");
    const pagerEl = $("[data-archer-wf-pager]");
    const labels = CFG.sectionLabels || {};

    const WF_TYPES = {
      header: "Header", footer: "Footer", hero: "Hero", features: "Diensten en troeven",
      social_proof: "Getuigenissen", testimonials: "Getuigenissen", content: "Tekst en beeld",
      cta: "Oproep tot actie", process: "Werkwijze", team: "Team", pricing: "Prijzen",
      faq: "Veelgestelde vragen", contact: "Contact", logo: "Logo's", logos: "Logo's",
      gallery: "Galerij", blog: "Blog", portfolio: "Realisaties", stats: "Cijfers",
      intro: "Intro", banner: "Banner", timeline: "Tijdlijn", generic: "Sectie",
    };
    const pageIndex = () => wf.state.pages.findIndex((p) => p.slug === wf.state.pageSlug);
    const isOpen = () => wf.el.expanded && wf.el.expanded.classList.contains("is-open");

    function renderTabs() {
      if (!tabsEl) return;
      const open = isOpen();
      const html = [
        `<button type="button" role="tab" class="archer-wf-tab${open ? "" : " is-active"}" data-archer-wf-overview aria-selected="${open ? "false" : "true"}">Overzicht</button>`,
      ];
      wf.state.pages.forEach((p) => {
        const m = wf.state.pageModels[p.slug];
        const n = m && m.sections ? m.sections.length : 0;
        const active = open && p.slug === wf.state.pageSlug;
        html.push(
          `<button type="button" role="tab" class="archer-wf-tab${active ? " is-active" : ""}${n ? "" : " is-empty"}" data-archer-wf-page="${esc(p.slug)}" aria-selected="${active ? "true" : "false"}">${esc(p.title || p.slug)}<span class="archer-wf-tab-count">${n || "leeg"}</span></button>`,
        );
      });
      tabsEl.innerHTML = html.join("");
    }

    function renderPager() {
      if (!pagerEl) return;
      if (!isOpen()) {
        pagerEl.innerHTML = "";
        return;
      }
      const i = pageIndex();
      const pages = wf.state.pages;
      const prev = pages[i - 1];
      const next = pages[i + 1];
      const cur = pages[i];
      pagerEl.innerHTML =
        (prev
          ? `<button type="button" class="archer-btn archer-btn-outline" data-archer-wf-page="${esc(prev.slug)}">← ${esc(prev.title || prev.slug)}</button>`
          : "<span></span>") +
        `<span class="archer-wf-pager-pos">Pagina <strong>${esc(cur ? cur.title || cur.slug : "")}</strong> · ${i + 1} van ${pages.length}</span>` +
        (next
          ? `<button type="button" class="archer-btn archer-btn-primary" data-archer-wf-page="${esc(next.slug)}">Volgende pagina: ${esc(next.title || next.slug)} →</button>`
          : `<a class="archer-btn archer-btn-primary" data-archer-nav href="${esc($("[data-archer-step-footer] [data-archer-next]")?.getAttribute("href") || "#")}">Verder naar stijl →</a>`);
    }

    document.addEventListener("click", (e) => {
      const ov = e.target.closest("[data-archer-wf-overview]");
      if (ov) {
        wf.closeExpandedPage();
        return;
      }
      const pb = e.target.closest("[data-archer-wf-page]");
      if (pb) {
        flushWf().then(() => {
          wf.openExpandedPage(pb.getAttribute("data-archer-wf-page"));
          window.scrollTo({ top: (tabsEl?.getBoundingClientRect().top || 0) + window.scrollY - 140, behavior: "smooth" });
        });
      }
    });

    /* Volgorde: header altijd eerst, footer altijd laatst. */
    const isHead = (s) => /^(header|headers|navbar|nav|navigation|megamenu)/i.test((s && (s.type || s.section_type)) || "");
    const isFoot = (s) => /^(footer|footers)/i.test((s && (s.type || s.section_type)) || "");
    const isFixed = (s) => isHead(s) || isFoot(s);
    /** Zet de secties in de juiste volgorde (in place). Geeft true als er iets veranderde. */
    function normalizeOrder(model) {
      const list = model && Array.isArray(model.sections) ? model.sections : null;
      if (!list || list.length < 2) return false;
      const before = list.map((s) => s.uuid).join("|");
      const head = list.filter(isHead);
      const foot = list.filter(isFoot);
      const mid = list.filter((s) => !isFixed(s));
      const next = head.concat(mid, foot);
      list.splice(0, list.length, ...next);
      return next.map((s) => s.uuid).join("|") !== before;
    }
    UI.normalizeWireframeOrder = normalizeOrder;

    // Labels per sectie in de werkbalk, in gewone taal.
    const typeLabelOf = (s) => {
      const type = (s.type || s.section_type || "sectie").toString();
      const t = labels[type] || WF_TYPES[type.toLowerCase()] || type.replace(/[_-]+/g, " ");
      return t.charAt(0).toUpperCase() + t.slice(1);
    };
    const nameOf = (s) => (s.title || s.section_name || s.name || "").toString();

    function labelSections() {
      const sections = wf.state.model?.sections || [];
      const movable = sections.map((s, i) => (isFixed(s) ? -1 : i)).filter((i) => i >= 0);
      const firstM = movable[0];
      const lastM = movable[movable.length - 1];
      $$(".aisb-wf-section", wf.el.sections).forEach((card, idx) => {
        const s = sections[idx];
        const bar = $(".aisb-wf-section-toolbar", card);
        if (!s || !bar) return;
        // Header en footer staan vast; de andere secties blijven ertussen.
        const up = $('[data-act="up"]', bar);
        const down = $('[data-act="down"]', bar);
        if (up) up.disabled = isFixed(s) || idx === firstM;
        if (down) down.disabled = isFixed(s) || idx === lastM;
        card.classList.toggle("archer-is-fixed", isFixed(s));
        if ($(".archer-wf-sec-label", bar)) return;
        const typeLabel = typeLabelOf(s);
        const name = nameOf(s);
        const lbl = document.createElement("div");
        lbl.className = "archer-wf-sec-label";
        lbl.innerHTML =
          `<strong>${esc(name || typeLabel)}</strong>` +
          `<span>${esc(name ? typeLabel : "")}${isHead(s) ? " · altijd bovenaan" : isFoot(s) ? " · altijd onderaan" : ""}${s.locked ? " · vastgezet" : ""}</span>`;
        bar.prepend(lbl);
        const lock = $('[data-act="lock"]', bar);
        if (lock) lock.textContent = s.locked ? "Losmaken" : "Vastzetten";
      });
    }

    // Autosave: elke pagina die veranderde, wordt bewaard (ook vanop het canvas).
    const lastSaved = {};
    const dirty = new Set();
    const modelOf = (slug) =>
      slug === wf.state.pageSlug && wf.state.model ? wf.state.model : wf.state.pageModels[slug];
    const saver = UI.debouncedSaver(async () => {
      const slugs = Array.from(dirty);
      dirty.clear();
      for (const slug of slugs) {
        const model = modelOf(slug);
        if (!model || !Array.isArray(model.sections)) continue;
        const json = JSON.stringify(model);
        if (lastSaved[slug] === json) continue;
        let out;
        try {
          out = await wf.post("aisb_update_wireframe_page", {
            project_id: wf.state.projectId,
            sitemap_version_id: wf.state.sitemapId,
            page_slug: slug,
            model_json: json,
          });
        } catch (e) {
          dirty.add(slug);
          throw e;
        }
        if (!out || !out.success) {
          dirty.add(slug);
          throw new Error((out && out.data && out.data.message) || "Bewaren mislukt");
        }
        lastSaved[slug] = json;
      }
    }, 900);
    const markDirty = (slug) => {
      if (!slug) return;
      dirty.add(slug);
      saver.run();
    };
    const flushWf = () => saver.flush();

    const anyGenerated = () =>
      wf.state.pages.some((p) => (wf.state.pageModels[p.slug]?.sections || []).length);

    function emptyState() {
      const page = wf.state.pages.find((p) => p.slug === wf.state.pageSlug);
      const name = page ? page.title || page.slug : "deze pagina";
      wf.el.sections.innerHTML =
        '<div class="archer-empty">' +
        '<h2 class="archer-h2">Nog geen wireframe voor ' + esc(name) + "</h2>" +
        "<p>AI kiest per sectie een passend voorbeeld uit de bibliotheek en schrijft er de teksten bij. Dit duurt ongeveer een halve minuut per pagina.</p>" +
        '<div class="archer-toolbar-actions">' +
        (anyGenerated()
          ? ""
          : '<button type="button" class="archer-btn archer-btn-primary" data-archer-wf-gen-all>Maak alle pagina\'s</button>') +
        '<button type="button" class="archer-btn ' + (anyGenerated() ? "archer-btn-primary" : "archer-btn-outline") + '" data-archer-wf-gen-one>Alleen ' + esc(name) + "</button>" +
        "</div></div>";
    }
    document.addEventListener("click", (e) => {
      if (e.target.closest("[data-archer-wf-gen-all]")) wf.btn.generateAll && wf.btn.generateAll.click();
      if (e.target.closest("[data-archer-wf-gen-one]")) wf.btn.generate && wf.btn.generate.click();
    });

    const origRenderSections = wf.renderSections;
    wf.renderSections = function () {
      const slug = wf.state.pageSlug;
      if (wf.state.model) {
        normalizeOrder(wf.state.model);
        if (slug) wf.state.pageModels[slug] = wf.state.model;
      }
      const r = origRenderSections.apply(this, arguments);
      if (!(wf.state.model?.sections || []).length) emptyState();
      if (wf.btn.generateAll) wf.btn.generateAll.textContent = anyGenerated() ? "Alle pagina's opnieuw maken" : "Maak alle pagina's";
      labelSections();
      const json = wf.state.model ? JSON.stringify(wf.state.model) : "";
      if (slug && json) {
        if (lastSaved[slug] === undefined) lastSaved[slug] = json; // eerste weergave: niets te bewaren
        else if (lastSaved[slug] !== json) markDirty(slug);
      }
      renderTabs();
      renderPager();
      return r;
    };

    /* ── Canvas: alle pagina's naast elkaar, zoals Relume ──────────── */

    const wb = wf.el.whiteboard;
    const inner = wf.el.canvasInner;
    const COL_BODY = 336; // breedte van een sectievoorbeeld op het canvas
    let editorApi = null; // zijpaneel om een sectie te bewerken (verderop)
    const PREVIEW = (window.AISB_WF && AISB_WF.previewUrl) || "";
    let parentOf = null; // slug => ouderslug, uit de sitemap
    let firstCanvas = true;
    let canvasStale = false;
    const sitemapSections = {}; // slug => [{ name, type }] uit de sitemap, voor de skeletten
    // Pas als alle bestaande wireframes geladen zijn, mag het canvas beslissen of er iets gemaakt moet worden.
    let modelsLoaded = false;
    const origLoadAll = wf.loadAllPageModels;
    if (typeof origLoadAll === "function") {
      wf.loadAllPageModels = async function () {
        const r = await origLoadAll.apply(this, arguments);
        modelsLoaded = true;
        return r;
      };
    }
    const genPending = new Set(); // pagina's waarvan de wireframe gemaakt wordt
    const genFailed = new Set();

    // Ouders uit de sitemap, voor de volgorde en de kruimel boven elke kolom.
    if (typeof wf.postCore === "function" && wf.state.sitemapId) {
      wf.postCore("aisb_get_sitemap_by_id", { sitemap_id: wf.state.sitemapId })
        .then((out) => {
          const data = (out && out.data && (out.data.data || out.data)) || {};
          const list = Array.isArray(data.sitemap) ? data.sitemap : [];
          parentOf = {};
          list.forEach((p) => {
            const slug = (p.slug || "").toString().replace(/^\//, "");
            if (slug) {
              parentOf[slug] = (p.parent_slug || "").toString().replace(/^\//, "");
              sitemapSections[slug] = (Array.isArray(p.sections) ? p.sections : []).map((x) => ({
                name: x.section_name || "",
                type: x.section_type || "",
              }));
            }
          });
          // Alleen opnieuw tekenen als de wireframes al geladen zijn (anders lijkt het project leeg).
          if (modelsLoaded && wf.state.pages.length && !isOpen()) wf.renderWhiteboard();
        })
        .catch(() => {});
    }

    /** Boomvolgorde: home, dan elke hoofdpagina gevolgd door haar subpagina's. */
    function treeOrder() {
      const pages = wf.state.pages;
      if (!parentOf) return pages.slice();
      const known = new Set(pages.map((p) => p.slug));
      const kids = {};
      pages.forEach((p) => {
        let par = parentOf[p.slug] || "";
        if (p.slug === "home") par = "";
        else if (!par || !known.has(par) || par === p.slug) par = "home";
        (kids[par] = kids[par] || []).push(p);
      });
      const out = [];
      const seen = new Set();
      const walk = (slug) => {
        (kids[slug] || []).forEach((p) => {
          if (seen.has(p.slug)) return;
          seen.add(p.slug);
          out.push(p);
          walk(p.slug);
        });
      };
      walk("");
      walk("home");
      pages.forEach((p) => !seen.has(p.slug) && out.push(p));
      return out;
    }

    const depthOf = (slug) => {
      let d = 0;
      let cur = parentOf && parentOf[slug];
      while (cur && cur !== "home" && d < 5) {
        d++;
        cur = parentOf[cur];
      }
      return d;
    };

    const sectionKey = (s) => [s.uuid || "", s.ai_wireframe_id || "", s.bricks_template_id || "", s.layout_key || ""].join(":");

    function sectionEl(slug, s) {
      const el = document.createElement("div");
      el.className = "archer-cv-sec";
      el.setAttribute("data-archer-cv-key", sectionKey(s));
      el.setAttribute("data-archer-cv-uuid", s.uuid || "");
      const id = s.ai_wireframe_id || s.bricks_template_id;
      el.innerHTML =
        '<div class="archer-cv-sec-label" aria-hidden="true"><strong></strong><span></span></div>' +
        (id
          ? '<div class="aisb-wf-body archer-cv-body"><div class="aisb-wf-iframe-wrap"></div></div>'
          : '<div class="archer-cv-placeholder"></div>') +
        '<div class="archer-cv-tools" role="toolbar">' +
        '<button type="button" class="archer-cv-tool" data-archer-cv-act="up" title="Naar boven" aria-label="Naar boven">↑</button>' +
        '<button type="button" class="archer-cv-tool" data-archer-cv-act="down" title="Naar beneden" aria-label="Naar beneden">↓</button>' +
        '<button type="button" class="archer-cv-tool" data-archer-cv-act="shuffle">Ander voorbeeld</button>' +
        '<button type="button" class="archer-cv-tool" data-archer-cv-act="lock">Vastzetten</button>' +
        '<button type="button" class="archer-cv-tool is-danger" data-archer-cv-act="del" title="Verwijderen" aria-label="Verwijderen">✕</button>' +
        "</div>";
      if (id) {
        const iframe = document.createElement("iframe");
        iframe.src = PREVIEW + id;
        iframe.className = "aisb-bricks-iframe";
        // Niet lazy: in het geschaalde canvas laadt Chrome lazy iframes vaak pas na een klik.
        iframe.loading = "eager";
        iframe.title = typeLabelOf(s) + " (voorbeeld)";
        iframe.scrolling = "no";
        iframe.tabIndex = -1;
        iframe.addEventListener("load", () => {
          measureIframe(iframe);
          setTimeout(() => measureIframe(iframe), 400);
          setTimeout(() => measureIframe(iframe), 1500);
        });
        $(".aisb-wf-iframe-wrap", el).appendChild(iframe);
      } else {
        $(".archer-cv-placeholder", el).textContent = typeLabelOf(s);
      }
      return el;
    }

    /** Meet de hoogte rechtstreeks (zelfde origin), los van de postMessage van de preview. */
    function measureIframe(iframe) {
      let h = 0;
      try {
        const doc = iframe.contentDocument;
        const wrap = doc && doc.getElementById("aisb-preview");
        if (wrap) h = Math.ceil(wrap.getBoundingClientRect().height);
      } catch (e) {
        return;
      }
      if (!h) return;
      iframe.style.height = h + "px";
      const body = iframe.closest(".archer-cv-body");
      if (!body) return;
      const scale = (body.offsetWidth || COL_BODY) / 1200;
      const wrapEl = iframe.closest(".aisb-wf-iframe-wrap");
      if (wrapEl) wrapEl.style.setProperty("--exp-scale", scale.toFixed(4));
      body.style.height = Math.ceil(h * scale) + "px";
    }

    function updateSectionEl(el, s, idx, sections) {
      const movable = sections.map((x, i) => (isFixed(x) ? -1 : i)).filter((i) => i >= 0);
      el.style.order = String(idx);
      el.classList.toggle("is-fixed", isFixed(s));
      el.classList.toggle("is-locked", !!s.locked);
      const name = nameOf(s);
      const type = typeLabelOf(s);
      $(".archer-cv-sec-label strong", el).textContent = name || type;
      $(".archer-cv-sec-label span", el).textContent =
        (name ? type : "") + (isHead(s) ? " · altijd bovenaan" : isFoot(s) ? " · altijd onderaan" : "") + (s.locked ? " · vastgezet" : "");
      $('[data-archer-cv-act="up"]', el).disabled = isFixed(s) || idx === movable[0];
      $('[data-archer-cv-act="down"]', el).disabled = isFixed(s) || idx === movable[movable.length - 1];
      $('[data-archer-cv-act="shuffle"]', el).disabled = !!s.locked || !(s.ai_wireframe_id || s.bricks_template_id);
      $('[data-archer-cv-act="lock"]', el).textContent = s.locked ? "Losmaken" : "Vastzetten";
      // Header en footer kan je niet verplaatsen; verwijderen kan wel.
      const body = $(".archer-cv-body", el);
      if (body) {
        const w = body.offsetWidth || COL_BODY;
        const scale = w / 1200;
        const wrap = $(".aisb-wf-iframe-wrap", body);
        wrap.style.setProperty("--exp-scale", scale.toFixed(4));
        const iframe = $("iframe", wrap);
        const h = parseInt(iframe && iframe.style.height, 10) || 400;
        body.style.height = Math.ceil(h * scale) + "px";
      }
    }

    function columnSig(p) {
      const crumb = parentOf && parentOf[p.slug] && parentOf[p.slug] !== "home" ? parentOf[p.slug] : "";
      return [p.title, crumb].join("|");
    }

    /** Skeletsecties zolang de wireframe gemaakt wordt: dezelfde secties als in de sitemap. */
    function skeletonHtml(slug) {
      const list = (sitemapSections[slug] && sitemapSections[slug].length
        ? sitemapSections[slug]
        : [{ type: "Headers" }, { type: "Hero Sections" }, { type: "Feature Sections" }, { type: "Content Sections" }, { type: "CTA Sections" }, { type: "Footers" }]
      ).slice();
      const head = list.filter((x) => /^(headers|megamenu)/i.test(x.type));
      const foot = list.filter((x) => /^footers/i.test(x.type));
      const mid = list.filter((x) => !/^(headers|megamenu|footers)/i.test(x.type));
      return head.concat(mid, foot)
        .map((x) => {
          const t = (x.type || "").toLowerCase();
          const kind = /^(headers|megamenu)/.test(t) ? "is-header" : /^footers/.test(t) ? "is-footer" : /^hero|banner/.test(t) ? "is-hero" : /cta|logo/.test(t) ? "is-short" : "";
          const label = x.name || (labels[x.type] || x.type || "").replace(/ Sections?$/, "");
          return `<div class="archer-cv-skel ${kind}"><span>${esc(label)}</span></div>`;
        })
        .join("");
    }

    function renderColumn(col, p, order) {
      const model = wf.state.pageModels[p.slug];
      const pendingNow = genPending.has(p.slug);
      const sections = pendingNow ? [] : (model && model.sections) || [];
      col.style.order = String(order);
      col.classList.toggle("is-pending", pendingNow);
      col.classList.toggle("is-failed", genFailed.has(p.slug));
      col.classList.toggle("is-empty", !sections.length && !pendingNow);
      let skel = $(".archer-cv-skeleton", col);
      if (pendingNow && !skel) {
        skel = document.createElement("div");
        skel.className = "archer-cv-skeleton";
        skel.setAttribute("aria-hidden", "true");
        skel.innerHTML = skeletonHtml(p.slug);
        $(".archer-cv-sections", col).before(skel);
      } else if (!pendingNow && skel) {
        skel.remove();
      }
      col.classList.toggle("is-child", depthOf(p.slug) > 0);
      if (col.getAttribute("data-archer-cv-sig") !== columnSig(p)) {
        col.setAttribute("data-archer-cv-sig", columnSig(p));
        const parent = parentOf && parentOf[p.slug] && parentOf[p.slug] !== "home" ? wf.state.pages.find((x) => x.slug === parentOf[p.slug]) : null;
        $(".archer-cv-crumb", col).textContent = parent ? (parent.title || parent.slug) + " ›" : p.slug === "home" ? "Startpagina" : "Hoofdmenu";
        $(".archer-cv-title", col).textContent = p.title || p.slug;
        $("[data-archer-cv-open]", col).setAttribute("aria-label", "Pagina " + (p.title || p.slug) + " bewerken");
      }
      $(".archer-cv-meta", col).textContent =
        "/" + (p.slug === "home" ? "" : p.slug) + " · " +
        (pendingNow ? "wireframe wordt gemaakt…" : sections.length ? sections.length + " secties" : genFailed.has(p.slug) ? "mislukt" : "nog leeg");

      const list = $(".archer-cv-sections", col);
      const existing = {};
      $$(".archer-cv-sec", list).forEach((el) => (existing[el.getAttribute("data-archer-cv-key")] = el));
      const used = new Set();
      sections.forEach((s, idx) => {
        const key = sectionKey(s);
        let el = existing[key];
        if (!el || used.has(key)) {
          el = sectionEl(p.slug, s);
          list.appendChild(el);
        }
        used.add(key);
        updateSectionEl(el, s, idx, sections);
      });
      Object.keys(existing).forEach((k) => !used.has(k) && existing[k].remove());

      const empty = $(".archer-cv-empty", col);
      empty.hidden = !!sections.length || pendingNow;
      $(".archer-cv-empty p", col).textContent = genFailed.has(p.slug) ? "Deze wireframe lukte niet." : "Nog geen wireframe voor deze pagina.";
      $("[data-archer-cv-gen]", col).textContent = genFailed.has(p.slug) ? "Opnieuw proberen" : "Maak wireframe";
      if (editorApi) editorApi.afterRender();
    }

    function columnEl(p) {
      const col = document.createElement("section");
      col.className = "aisb-wf-page-card archer-cv-col";
      col.setAttribute("data-archer-cv-page", p.slug);
      col.innerHTML =
        '<header class="archer-cv-head">' +
        '<div class="archer-cv-head-text"><div class="archer-cv-crumb"></div><h3 class="archer-cv-title"></h3><div class="archer-cv-meta"></div></div>' +
        '<button type="button" class="archer-btn archer-btn-outline archer-cv-open" data-archer-cv-open>Bewerken</button>' +
        "</header>" +
        '<div class="archer-cv-sections"></div>' +
        '<div class="archer-cv-empty" hidden><p>Nog geen wireframe voor deze pagina.</p>' +
        '<button type="button" class="archer-btn archer-btn-primary" data-archer-cv-gen>Maak wireframe</button></div>';
      return col;
    }

    // Zoomknoppen rechtsonder in het canvas.
    const zoom = document.createElement("div");
    zoom.className = "archer-cv-zoom";
    zoom.innerHTML =
      '<button type="button" data-archer-zoom="out" aria-label="Uitzoomen">−</button>' +
      '<span data-archer-zoom-level>100%</span>' +
      '<button type="button" data-archer-zoom="in" aria-label="Inzoomen">+</button>' +
      '<button type="button" data-archer-zoom="fit">Passend</button>';
    wb.appendChild(zoom);

    /* ── Wireframes maken, zoals Relume: alle kolommen tegelijk, met skeletten ── */

    const progress = document.createElement("div");
    progress.className = "archer-smap-progress archer-cv-progress";
    progress.hidden = true;
    wb.appendChild(progress);
    progress.addEventListener("mousedown", (e) => e.stopPropagation());
    let genTotal = 0;
    let genDone = 0;
    const setGenProgress = () => {
      if (!genTotal) { progress.hidden = true; return; }
      progress.hidden = false;
      const left = genTotal - genDone;
      const pct = Math.round((genDone / genTotal) * 100);
      progress.innerHTML =
        (left > 0
          ? `<span class="archer-smap-spinner" aria-hidden="true"></span><span>Wireframes maken · <strong>${genDone}</strong> van ${genTotal} pagina's</span>`
          : genFailed.size
            ? `<span>${genTotal - genFailed.size} van ${genTotal} klaar, ${genFailed.size} mislukt.</span><button type="button" class="archer-link-btn" data-archer-gen-retry>Opnieuw proberen</button>`
            : `<span>Klaar: ${genTotal} wireframes.</span>`) +
        `<span class="archer-smap-bar"><span style="width:${pct}%"></span></span>`;
      if (left === 0 && !genFailed.size) setTimeout(() => { if (!genPending.size) { progress.hidden = true; genTotal = 0; genDone = 0; } }, 4000);
    };
    progress.addEventListener("click", (e) => {
      if (!e.target.closest("[data-archer-gen-retry]")) return;
      const slugs = Array.from(genFailed);
      genFailed.clear();
      generatePages(slugs);
    });

    let genRunning = Promise.resolve();
    /**
     * Maakt de wireframes van deze pagina's. Home eerst (de andere pagina's nemen de header en
     * footer daarvan over), daarna zes pagina's tegelijk. Elke kolom toont skeletsecties tot
     * zijn wireframe klaar is.
     */
    function generatePages(slugs) {
      slugs = slugs.filter((s) => !genPending.has(s));
      if (!slugs.length) return genRunning;
      slugs.forEach((s) => { genPending.add(s); genFailed.delete(s); });
      genTotal += slugs.length;
      setGenProgress();
      if (isOpen()) wf.closeExpandedPage();
      wf.renderWhiteboard();

      const one = async (slug) => {
        try {
          const out = await wf.post("aisb_generate_wireframe_page", {
            project_id: wf.state.projectId,
            sitemap_version_id: wf.state.sitemapId,
            page_slug: slug,
          });
          if (!out || !out.success || !out.data || !out.data.wireframe) throw new Error((out && out.data && out.data.message) || "");
          const w = out.data.wireframe;
          lastSaved[slug] = JSON.stringify(w);
          // Header en footer zoals Home (de server zet ze ook zo na het bewaren).
          if (slug !== "home" && wf.state.pageModels.home) {
            applyChrome(w, chromeOf(wf.state.pageModels.home));
            lastSaved[slug] = JSON.stringify(w);
          }
          if (normalizeOrder(w)) markDirty(slug);
          wf.state.pageModels[slug] = w;
          if (wf.state.pageSlug === slug) wf.state.model = w;
          if (slug === "home") spreadChrome("home", w);
        } catch (e) {
          genFailed.add(slug);
        }
        genPending.delete(slug);
        genDone++;
        setGenProgress();
        redrawPage(slug);
        if (wf.btn.generateAll) wf.btn.generateAll.textContent = anyGenerated() ? "Alle pagina's opnieuw maken" : "Maak alle pagina's";
      };

      const order = slugs.slice().sort((a, b) => (a === "home" ? -1 : b === "home" ? 1 : 0));
      genRunning = genRunning.then(async () => {
        const hasHeader = wf.state.pages.some((p) => p.slug !== "home" && !genPending.has(p.slug) && (wf.state.pageModels[p.slug]?.sections || []).length);
        let rest = order;
        if (order[0] === "home" || !hasHeader) {
          await one(order[0]);
          rest = order.slice(1);
        }
        let next = 0;
        const worker = async () => {
          while (next < rest.length) await one(rest[next++]);
        };
        // Zes tegelijk (was drie); de site praat HTTP/2, dus de browser remt niet af.
        await Promise.all(Array.from({ length: 6 }, worker));
      });
      return genRunning;
    }
    UI.generateWireframes = generatePages;

    // De knoppen van de module gebruiken nu deze generatie.
    if (wf.btn.generateAll) {
      wf.btn.generateAll.addEventListener(
        "click",
        (e) => {
          e.preventDefault();
          e.stopImmediatePropagation();
          flushWf().then(() => generatePages(wf.state.pages.map((p) => p.slug)));
        },
        true,
      );
    }
    if (wf.btn.generate) {
      wf.btn.generate.addEventListener(
        "click",
        (e) => {
          e.preventDefault();
          e.stopImmediatePropagation();
          const slug = wf.state.pageSlug;
          if (!slug) return;
          const reopen = isOpen();
          flushWf()
            .then(() => generatePages([slug]))
            .then(() => {
              if (reopen && wf.state.pageModels[slug]) wf.openExpandedPage(slug);
            });
        },
        true,
      );
    }
    zoom.addEventListener("mousedown", (e) => e.stopPropagation());
    const zoomLevel = $("[data-archer-zoom-level]", zoom);
    const showZoom = () => (zoomLevel.textContent = Math.round(wf.canvas.scale * 100) + "%");
    const origApply = wf.applyCanvasTransform;
    if (origApply) {
      wf.applyCanvasTransform = function () {
        const r = origApply.apply(this, arguments);
        showZoom();
        return r;
      };
    }
    // Ctrl + scroll zoomt via canvas.js; het percentage volgt mee.
    wb.addEventListener("wheel", () => requestAnimationFrame(showZoom), { passive: true });
    const setTransform = () => {
      inner.style.transform = `translate(${wf.canvas.tx}px, ${wf.canvas.ty}px) scale(${wf.canvas.scale})`;
      showZoom();
    };
    const zoomBy = (factor) => {
      const rect = wb.getBoundingClientRect();
      const cx = rect.width / 2;
      const cy = Math.min(rect.height / 2, 200);
      const prev = wf.canvas.scale;
      const next = Math.max(0.2, Math.min(1.5, prev * factor));
      const wx = (cx - wf.canvas.tx) / prev;
      const wy = (cy - wf.canvas.ty) / prev;
      wf.canvas.scale = next;
      wf.canvas.tx = cx - wx * next;
      wf.canvas.ty = cy - wy * next;
      setTransform();
    };
    zoom.addEventListener("click", (e) => {
      const b = e.target.closest("[data-archer-zoom]");
      if (!b) return;
      const z = b.getAttribute("data-archer-zoom");
      if (z === "in") zoomBy(1.2);
      else if (z === "out") zoomBy(1 / 1.2);
      else wf.fitToView();
    });

    // Passend: zo veel mogelijk kolommen in de breedte, bovenaan beginnen.
    let fitPending = false;
    wf.fitToView = function () {
      const rect = wb.getBoundingClientRect();
      const cols = $$(".archer-cv-col", inner);
      if (!cols.length) return;
      if (!rect.width || inner.style.display === "none") {
        fitPending = true; // canvas verborgen: passend maken zodra het weer zichtbaar is
        return;
      }
      fitPending = false;
      let minX = Infinity;
      let maxX = -Infinity;
      cols.forEach((c) => {
        minX = Math.min(minX, c.offsetLeft);
        maxX = Math.max(maxX, c.offsetLeft + c.offsetWidth);
      });
      const pad = 24;
      const w = maxX - minX;
      const scale = Math.max(0.35, Math.min(1, (rect.width - pad * 2) / w));
      wf.canvas.scale = scale;
      wf.canvas.tx = w * scale < rect.width ? (rect.width - w * scale) / 2 - minX * scale : pad - minX * scale;
      wf.canvas.ty = pad - (cols[0].offsetTop - 0) * scale + 8;
      setTransform();
    };

    // Slepen op een kolom verschuift ook het canvas (behalve op knoppen).
    let dragMoved = false;
    let downAt = null;
    wb.addEventListener("mousedown", (e) => {
      downAt = { x: e.clientX, y: e.clientY };
      dragMoved = false;
      if (!e.target.closest(".archer-cv-col") || e.target.closest("button, a, input, select")) return;
      wf.isPanning = true;
      wf.panStart = { x: e.clientX, y: e.clientY, tx: wf.canvas.tx, ty: wf.canvas.ty };
      wb.style.cursor = "grabbing";
      e.preventDefault();
    });
    window.addEventListener("mousemove", (e) => {
      if (downAt && Math.abs(e.clientX - downAt.x) + Math.abs(e.clientY - downAt.y) > 4) dragMoved = true;
    });
    window.addEventListener("mouseup", () => (downAt = null));

    let baselineDone = false;
    wf.renderWhiteboard = function () {
      if (!inner) return;
      const pages = wf.state.pages || [];
      // Alle andere aanroepen komen pas na het laden van de wireframes (sitemap.js, generatie, openen/sluiten).
      if (pages.length) modelsLoaded = true;
      if (!baselineDone && pages.length) {
        baselineDone = true;
        // Modellen die al op de server staan, gelden als bewaard; daarna de volgorde rechtzetten.
        Object.keys(wf.state.pageModels || {}).forEach((slug) => {
          lastSaved[slug] = JSON.stringify(wf.state.pageModels[slug]);
          if (normalizeOrder(wf.state.pageModels[slug])) markDirty(slug);
        });
      } else {
        Object.keys(wf.state.pageModels || {}).forEach((slug) => {
          if (normalizeOrder(wf.state.pageModels[slug])) markDirty(slug);
        });
      }
      renderTabs();
      renderPager();
      // Met een open pagina is het canvas verborgen: pas tekenen bij terugkeren.
      if (isOpen()) {
        canvasStale = true;
        return;
      }
      canvasStale = false;
      const ordered = treeOrder();
      const cols = {};
      $$(".archer-cv-col", inner).forEach((c) => (cols[c.getAttribute("data-archer-cv-page")] = c));
      // Andere inhoud (de oude paginakaarten) eruit.
      Array.from(inner.children).forEach((c) => !c.classList.contains("archer-cv-col") && c.remove());
      const keep = new Set();
      ordered.forEach((p, i) => {
        let col = cols[p.slug];
        if (!col) {
          col = columnEl(p);
          inner.appendChild(col);
        }
        keep.add(p.slug);
        renderColumn(col, p, i);
      });
      Object.keys(cols).forEach((slug) => !keep.has(slug) && cols[slug].remove());
      if (firstCanvas && pages.length) {
        firstCanvas = false;
        fitPending = true;
        // Alleen een project zonder enige wireframe start vanzelf (zoals Relume). Bestaande wireframes
        // worden nooit automatisch opnieuw gemaakt.
        const nothingYet = !Object.values(wf.state.pageModels || {}).some((m) => m && Array.isArray(m.sections) && m.sections.length);
        if (nothingYet && !wf.root.hasAttribute("data-archer-no-autogen")) {
          setTimeout(() => generatePages(pages.map((p) => p.slug)), 300);
        }
      }
      // setTimeout in plaats van requestAnimationFrame: werkt ook in een achtergrondtab.
      if (fitPending) setTimeout(() => wf.fitToView(), 60);
    };
    const origHideLoader = wf.hideCanvasLoader;
    if (origHideLoader) {
      wf.hideCanvasLoader = function () {
        const r = origHideLoader.apply(this, arguments);
        if (fitPending) setTimeout(() => wf.fitToView(), 60);
        return r;
      };
    }

    // Een kolom opnieuw tekenen na een actie op het canvas.
    const redrawPage = (slug) => {
      const p = wf.state.pages.find((x) => x.slug === slug);
      const col = $(`.archer-cv-col[data-archer-cv-page="${CSS.escape(slug)}"]`, inner);
      if (p && col) renderColumn(col, p, Number(col.style.order) || 0);
      renderTabs();
    };

    /* ── Header en footer: overal die van Home ─────────────────────── */

    const chromeOf = (model) => {
      const out = {};
      ((model && model.sections) || []).forEach((sec) => {
        const t = String((sec && sec.type) || "").toLowerCase();
        if (t === "header" && !out.header) out.header = sec;
        if (t === "footer") out.footer = sec;
      });
      return out;
    };
    const sameSection = (a, b) => {
      const strip = (x) => JSON.stringify(Object.assign({}, x, { uuid: "" }));
      return strip(a) === strip(b);
    };
    /** Zet header en footer van `model` gelijk aan die van `src`; true als er iets veranderde. */
    const applyChrome = (model, src) => {
      if (!model || !Array.isArray(model.sections) || !src) return false;
      let changed = false;
      const headerIdx = model.sections.findIndex((x) => String((x && x.type) || "").toLowerCase() === "header");
      let footerIdx = -1;
      model.sections.forEach((x, i) => { if (String((x && x.type) || "").toLowerCase() === "footer") footerIdx = i; });
      [["header", headerIdx], ["footer", footerIdx]].forEach(([t, i]) => {
        if (i < 0 || !src[t] || sameSection(model.sections[i], src[t])) return;
        model.sections[i] = Object.assign(JSON.parse(JSON.stringify(src[t])), { uuid: model.sections[i].uuid });
        changed = true;
      });
      return changed;
    };
    /** De header en footer van `sourceSlug` op alle andere pagina's (de server doet hetzelfde). */
    const spreadChrome = (sourceSlug, sourceModel, onlyType) => {
      const src = chromeOf(sourceModel || modelOf(sourceSlug));
      if (onlyType === "header") delete src.footer;
      if (onlyType === "footer") delete src.header;
      if (!src.header && !src.footer) return;
      (wf.state.pages || []).forEach((p) => {
        if (p.slug === sourceSlug) return;
        const m = modelOf(p.slug);
        if (applyChrome(m, src)) {
          lastSaved[p.slug] = JSON.stringify(m); // de server zette dezelfde wijziging al
          redrawPage(p.slug);
        }
      });
    };
    UI.spreadChrome = spreadChrome;
    // Een ander voorbeeld voor de header of footer (op het canvas of in de paginaweergave) geldt voor alle pagina's.
    const chromePost = wf.post.bind(wf);
    wf.post = async function (action, data) {
      const out = await chromePost(action, data);
      if ((action === "aisb_shuffle_section_layout" || action === "aisb_replace_section_type") && out && out.success && data && data.page_slug) {
        const w = out.data && out.data.wireframe;
        const sec = w && (w.sections || []).find((x) => x && x.uuid === data.uuid);
        if (sec && ["header", "footer"].includes(String(sec.type || "").toLowerCase())) {
          setTimeout(() => spreadChrome(data.page_slug, w, String(sec.type).toLowerCase()), 0);
        }
      }
      return out;
    };

    let undo = null;
    const status = (html) => {
      if (wf.el.status) wf.el.status.innerHTML = html;
    };

    inner.addEventListener("click", async (e) => {
      if (dragMoved) return;
      const col = e.target.closest(".archer-cv-col");
      if (!col) return;
      e.stopPropagation();
      const slug = col.getAttribute("data-archer-cv-page");

      if (e.target.closest("[data-archer-cv-open]")) {
        await flushWf();
        wf.openExpandedPage(slug);
        return;
      }
      if (e.target.closest("[data-archer-cv-gen]")) {
        await flushWf();
        generatePages([slug]);
        return;
      }
      const btn = e.target.closest("[data-archer-cv-act]");
      if (!btn) {
        // Klik op een sectie: bewerken in het zijpaneel.
        const sec = e.target.closest(".archer-cv-sec");
        if (sec && !e.target.closest("button")) editor.open(slug, sec.getAttribute("data-archer-cv-uuid"));
        return;
      }
      if (btn.disabled) return;
      const secEl = btn.closest(".archer-cv-sec");
      const uuid = secEl && secEl.getAttribute("data-archer-cv-uuid");
      const model = modelOf(slug);
      if (!model || !Array.isArray(model.sections)) return;
      const list = model.sections;
      const idx = list.findIndex((s) => s.uuid === uuid);
      if (idx < 0) return;
      const act = btn.getAttribute("data-archer-cv-act");

      if (act === "up" || act === "down") {
        const step = act === "up" ? -1 : 1;
        const to = idx + step;
        if (!list[to] || isFixed(list[to]) || isFixed(list[idx])) return;
        [list[idx], list[to]] = [list[to], list[idx]];
      } else if (act === "lock") {
        list[idx].locked = !list[idx].locked;
      } else if (act === "del") {
        undo = { slug, idx, section: list[idx] };
        list.splice(idx, 1);
        status(
          '<span class="aisb-ok">Sectie ' + esc(nameOf(undo.section) || typeLabelOf(undo.section)) + " verwijderd. " +
            '<button type="button" class="archer-link-btn" data-archer-cv-undo>Ongedaan maken</button></span>',
        );
      } else if (act === "shuffle") {
        btn.disabled = true;
        btn.textContent = "Zoeken…";
        await flushWf();
        try {
          const out = await wf.post("aisb_shuffle_section_layout", {
            project_id: wf.state.projectId,
            sitemap_version_id: wf.state.sitemapId,
            page_slug: slug,
            uuid,
          });
          if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "");
          const w = out.data.wireframe;
          normalizeOrder(w);
          wf.state.pageModels[slug] = w;
          if (wf.state.pageSlug === slug) wf.state.model = w;
          lastSaved[slug] = JSON.stringify(w);
          // Het voorbeeld opnieuw laden, ook als het sjabloon-id gelijk bleef.
          if (secEl) secEl.remove();
          wf.setStatus("Shuffled.", "ok");
        } catch (err) {
          wf.setStatus("Shuffle failed", "err");
        }
        redrawPage(slug);
        return;
      }
      if (wf.state.pageSlug === slug && wf.state.model) wf.state.pageModels[slug] = wf.state.model;
      redrawPage(slug);
      markDirty(slug);
    });

    document.addEventListener("click", (e) => {
      if (!e.target.closest("[data-archer-cv-undo]") || !undo) return;
      const model = modelOf(undo.slug);
      if (model && Array.isArray(model.sections)) {
        model.sections.splice(Math.min(undo.idx, model.sections.length), 0, undo.section);
        normalizeOrder(model);
        redrawPage(undo.slug);
        markDirty(undo.slug);
        status('<span class="aisb-ok">Sectie teruggezet.</span>');
      }
      undo = null;
    });

    // Dubbelklik op een kolom opent de pagina.
    inner.addEventListener("dblclick", (e) => {
      const col = e.target.closest(".archer-cv-col");
      if (!col || e.target.closest("button")) return;
      flushWf().then(() => wf.openExpandedPage(col.getAttribute("data-archer-cv-page")));
    });

    /* ── Sectie bewerken in een zijpaneel naast het canvas ─────────── */

    const editor = (() => {
      // Het canvas en het paneel naast elkaar (het paneel komt nooit over de pagina's).
      const shell = document.createElement("div");
      shell.className = "archer-cv-shell";
      wb.parentNode.insertBefore(shell, wb);
      shell.appendChild(wb);
      const panel = document.createElement("aside");
      panel.className = "archer-smap-panel archer-cv-panel";
      panel.hidden = true;
      panel.setAttribute("aria-label", "Sectie bewerken");
      shell.appendChild(panel);

      const TEXT_NAMES = { heading: "Titel", "text-basic": "Tekst", text: "Tekst", button: "Knop", "text-link": "Link" };
      const MEDIA = /^(image|video|image-gallery|map|svg|slider|carousel)$/;
      let sel = null; // { slug, uuid, postId, nodes, byId, a (analyse) }
      let textQueue = {}; // id => { key, value }
      let textTimer = null;
      let busy = false;

      const sectionOf = (slug, uuid) => (modelOf(slug)?.sections || []).find((s) => s.uuid === uuid) || null;
      const secElOf = (uuid) => $(`.archer-cv-sec[data-archer-cv-uuid="${CSS.escape(uuid)}"]`, inner);
      const docOf = (uuid) => {
        try {
          const f = $("iframe", secElOf(uuid) || document.createElement("i"));
          return f && f.contentDocument && f.contentDocument.getElementById("aisb-preview") ? f.contentDocument : null;
        } catch (e) {
          return null;
        }
      };
      const domOf = (doc, id) => (doc ? doc.getElementById("brxe-" + id) : null);

      /** Wat kan er aan deze sectie aangepast worden? Uit de Bricks-elementen en hoe ze getoond worden. */
      function analyse(nodes, doc) {
        const byId = {};
        nodes.forEach((n) => (byId[n.id] = n));
        const kids = (n) => (n.children || []).filter((c) => byId[c]);
        const sig = (id, d) => {
          const n = byId[id];
          return n ? n.name + (d < 4 ? "(" + kids(n).map((c) => sig(c, d + 1)).join(",") + ")" : "") : "";
        };
        const has = (id, test) => {
          const n = byId[id];
          return !!n && (test(n) || kids(n).some((c) => has(c, test)));
        };
        const root = nodes.find((n) => !byId[n.parent]);
        const order = [];
        const walk = (id) => {
          order.push(id);
          kids(byId[id]).forEach(walk);
        };
        if (root) walk(root.id);

        // Herhaalde items (kaarten, lijstjes, teamleden): zelfde opbouw naast elkaar.
        const repeats = [];
        const itemOf = {}; // node-id => { r, i }
        order.forEach((id) => {
          if (itemOf[id]) return;
          const n = byId[id];
          const k = kids(n);
          if (k.length < 2) return;
          const first = byId[k[0]];
          if (!/^(block|div|container)$/.test(first.name) && first.tag !== "li") return;
          const s0 = sig(k[0], 0);
          if (!k.every((c) => sig(c, 0) === s0)) return;
          const r = { id, items: k, label: n.label || "", cols: 0 };
          const el = domOf(doc, id);
          if (el) {
            const cs = el.ownerDocument.defaultView.getComputedStyle(el);
            if (cs.display === "grid" || cs.display === "inline-grid") {
              r.cols = cs.gridTemplateColumns.split(" ").filter(Boolean).length;
            }
          }
          repeats.push(r);
          k.forEach((c, i) => {
            const mark = (x) => {
              itemOf[x] = itemOf[x] || { r, i };
              kids(byId[x]).forEach(mark);
            };
            mark(c);
          });
        });

        // Beeld naast tekst: wisselen van kant.
        const media = [];
        if (doc) {
          order.forEach((id) => {
            if (itemOf[id] || media.length >= 2) return;
            const k = kids(byId[id]);
            if (k.length < 2) return;
            const isMedia = (c) => MEDIA.test(byId[c].name) || (has(c, (x) => MEDIA.test(x.name)) && !has(c, (x) => x.text));
            const m = k.find(isMedia);
            if (!m) return;
            const me = domOf(doc, m);
            if (!me) return;
            const mr = me.getBoundingClientRect();
            // Alleen een echt beeld, geen klein icoon of avatar.
            if (mr.width < (doc.getElementById("aisb-preview") || doc.body).getBoundingClientRect().width * 0.25) return;
            const other = k.find((c) => {
              if (c === m || !has(c, (x) => x.text)) return false;
              const oe = domOf(doc, c);
              if (!oe) return false;
              const or = oe.getBoundingClientRect();
              const overlap = Math.min(mr.bottom, or.bottom) - Math.max(mr.top, or.top);
              const sideBySide = or.left >= mr.right - 2 || or.right <= mr.left + 2;
              return sideBySide && overlap > Math.min(mr.height, or.height) * 0.4;
            });
            if (!other) return;
            const or = domOf(doc, other).getBoundingClientRect();
            media.push({ id: m, other, side: mr.left < or.left ? "left" : "right" });
          });
        }

        // Knoppen: per groep (niet binnen herhaalde items).
        const buttons = [];
        const seen = {};
        order.forEach((id) => {
          const n = byId[id];
          if (n.name !== "button" || itemOf[id]) return;
          const p = byId[n.parent];
          if (!p || seen[p.id]) return;
          seen[p.id] = true;
          const list = kids(p).filter((c) => byId[c].name === "button");
          if (list.length <= 2) buttons.push({ parent: p.id, list });
        });

        // Teksten in leesvolgorde; binnen herhaalde items per item gegroepeerd.
        const texts = order
          .filter((id) => byId[id].text !== undefined && TEXT_NAMES[byId[id].name] !== undefined)
          .map((id) => ({ n: byId[id], item: itemOf[id] || null }));

        return { byId, repeats, media, buttons, texts };
      }

      // Opmaak zoals in de server (AISB_Archer_Generate::clean_markup): geen kleuren, spans of style.
      const ALLOWED = { basic: ["STRONG", "EM", "BR"], rich: ["P", "BR", "STRONG", "EM", "UL", "OL", "LI", "A"] };
      function cleanHtml(html, mode) {
        const doc = new DOMParser().parseFromString("<body>" + (html || "") + "</body>", "text/html");
        if (mode === "inline") {
          doc.body.querySelectorAll("br").forEach((b) => b.replaceWith(" "));
          return doc.body.textContent.replace(/\s+/g, " ").trim();
        }
        const allowed = ALLOWED[mode] || ALLOWED.basic;
        const walk = (node) => {
          Array.from(node.childNodes).forEach((c) => {
            if (c.nodeType === 3) return;
            if (c.nodeType !== 1) return c.remove();
            if (/^(SCRIPT|STYLE)$/.test(c.tagName)) return c.remove();
            walk(c);
            let tag = c.tagName === "B" ? "STRONG" : c.tagName === "I" ? "EM" : c.tagName;
            if (/^(DIV|P)$/.test(tag)) {
              if (mode === "basic") {
                if (c.previousSibling) c.before(doc.createElement("br"));
                return c.replaceWith(...c.childNodes);
              }
              tag = "P";
            }
            if (!allowed.includes(tag)) return c.replaceWith(...c.childNodes);
            const el = doc.createElement(tag);
            const href = c.getAttribute("href") || "";
            if (tag === "A" && /^(https?:|mailto:|tel:|\/|#)/i.test(href)) el.setAttribute("href", href);
            el.append(...c.childNodes);
            c.replaceWith(el);
          });
        };
        walk(doc.body);
        return doc.body.innerHTML.replace(/(<br>\s*){3,}/g, "<br><br>").replace(/^(\s|<br>)+|(\s|<br>)+$/g, "").trim();
      }
      const plainLen = (html) => {
        const d = new DOMParser().parseFromString("<body>" + html + "</body>", "text/html");
        return d.body.textContent.replace(/\s+/g, " ").trim().length;
      };
      const countText = (len, max) => (max ? `${len} / ${max}` : `${len}`);

      function fieldHtml(n) {
        const mode = n.mode || (n.name === "heading" || n.name === "button" ? "inline" : n.name === "text" ? "rich" : "basic");
        const v = cleanHtml(n.text || "", mode);
        const len = plainLen(v);
        const lab = /tagline|eyebrow/i.test(n.label || "") ? "Tagline" : TEXT_NAMES[n.name] + (n.tag && /^h\d$/.test(n.tag) ? " (" + n.tag.toUpperCase() + ")" : "");
        const tools =
          mode === "inline"
            ? ""
            : `<div class="archer-ed-tb" role="toolbar" aria-label="Opmaak">` +
              `<button type="button" data-ed-cmd="bold" aria-label="Vet" title="Vet"><strong>B</strong></button>` +
              `<button type="button" data-ed-cmd="italic" aria-label="Cursief" title="Cursief"><em>I</em></button>` +
              (mode === "rich" ? `<button type="button" data-ed-cmd="insertUnorderedList" aria-label="Opsomming" title="Opsomming">•</button>` : "") +
              `</div>`;
        return (
          `<div class="archer-ed-field">` +
          `<div class="archer-ed-labelrow"><span class="aisb-label" id="archer-ed-l-${n.id}">${esc(lab)}</span>` +
          `<span class="archer-ed-len${n.max && len > n.max ? " is-over" : ""}" data-ed-len="${n.id}" title="${n.max ? "Aanbevolen maximum: " + n.max + " tekens" : ""}">${countText(len, n.max)}</span></div>` +
          tools +
          `<div class="archer-ed-rte is-${mode}" id="archer-ed-${n.id}" contenteditable="true" role="textbox" spellcheck="true"` +
          ` aria-labelledby="archer-ed-l-${n.id}"${mode === "inline" ? "" : ' aria-multiline="true"'}` +
          ` data-ed-text="${n.id}" data-ed-key="${n.key || "text"}" data-ed-mode="${mode}" data-ed-max="${n.max || 0}">` +
          (mode === "inline" ? esc(v) : v) +
          `</div>` +
          (n.max ? `<p class="archer-ed-hint" data-ed-hint="${n.id}"${len > n.max ? "" : " hidden"}>Langer dan aanbevolen voor deze plek in het ontwerp.</p>` : "") +
          `</div>`
        );
      }

      const seg = (attr, value, options, current) =>
        `<div class="archer-seg archer-ed-seg" role="group">` +
        options
          .map(([v, l]) => `<button type="button" class="archer-seg-btn${String(v) === String(current) ? " is-active" : ""}" ${attr}="${value}" data-ed-val="${v}"${String(v) === String(current) ? ' aria-pressed="true"' : ' aria-pressed="false"'}>${l}</button>`)
          .join("") +
        "</div>";

      function render() {
        if (!sel) return;
        const s = sectionOf(sel.slug, sel.uuid);
        if (!s) return close();
        const page = wf.state.pages.find((p) => p.slug === sel.slug);
        const head =
          `<div class="archer-smap-panel-head"><div><span class="archer-smap-panel-eyebrow">Sectie op ${esc(page ? page.title || page.slug : sel.slug)}</span>` +
          `<h3>${esc(nameOf(s) || typeLabelOf(s))}</h3></div>` +
          `<button type="button" class="archer-sec-tool" data-ed-close aria-label="Sluiten">✕</button></div>`;
        if (!sel.nodes) {
          panel.innerHTML = head + (sel.error ? `<p class="archer-danger-text">${esc(sel.error)}</p>` : `<p class="archer-muted">Sectie laden…</p>`);
          return;
        }
        const a = (sel.a = analyse(sel.nodes, docOf(sel.uuid)));
        let html = head;
        if (isFixed(s) && a.texts.length) html += `<p class="archer-ed-note">Wijzigingen aan de ${isHead(s) ? "header" : "footer"} gelden voor alle pagina's.</p>`;

        // Layout
        const layout = [];
        a.media.forEach((m, i) => {
          layout.push(`<div class="archer-ed-row"><span>Beeld${a.media.length > 1 ? " " + (i + 1) : ""}</span>` + seg("data-ed-media", i, [["left", "Links"], ["right", "Rechts"]], m.side) + "</div>");
        });
        a.buttons.forEach((b, i) => {
          layout.push(`<div class="archer-ed-row"><span>Knoppen${a.buttons.length > 1 ? " " + (i + 1) : ""}</span>` + seg("data-ed-buttons", i, [[1, "1"], [2, "2"]], b.list.length) + "</div>");
        });
        a.repeats.forEach((r, i) => {
          const name = a.repeats.length > 1 ? "Aantal items " + (i + 1) : "Aantal items";
          layout.push(
            `<div class="archer-ed-row"><span>${esc(name)}</span><div class="archer-ed-count">` +
              `<button type="button" class="archer-sec-tool" data-ed-items="${i}" data-ed-val="-1" aria-label="Eén minder"${r.items.length <= 1 ? " disabled" : ""}>−</button>` +
              `<strong>${r.items.length}</strong>` +
              `<button type="button" class="archer-sec-tool" data-ed-items="${i}" data-ed-val="1" aria-label="Eén meer"${r.items.length >= 12 ? " disabled" : ""}>+</button></div></div>`,
          );
          if (r.cols) {
            layout.push(`<div class="archer-ed-row archer-ed-sub"><span>Kolommen</span>` + seg("data-ed-cols", i, [1, 2, 3, 4, 5, 6].map((n) => [n, String(n)]), r.cols) + "</div>");
          }
        });
        if (layout.length) html += `<div class="archer-ed-group"><div class="archer-ed-title">Opbouw</div>${layout.join("")}</div>`;

        // Teksten
        const loose = a.texts.filter((t) => !t.item);
        if (loose.length) html += `<div class="archer-ed-group"><div class="archer-ed-title">Teksten</div>${loose.map((t) => fieldHtml(t.n)).join("")}</div>`;
        a.repeats.forEach((r) => {
          r.items.forEach((it, i) => {
            const list = a.texts.filter((t) => t.item && t.item.r === r && t.item.i === i);
            if (!list.length) return;
            html += `<details class="archer-ed-group archer-ed-item"${i === 0 ? " open" : ""}><summary class="archer-ed-title">Item ${a.repeats.length > 1 ? a.repeats.indexOf(r) + 1 + "." : ""}${i + 1}</summary>${list.map((t) => fieldHtml(t.n)).join("")}</details>`;
          });
        });
        if (!layout.length && !a.texts.length) {
          html += isHead(s) && !s.ai_wireframe_id
            ? `<p class="archer-muted">De header kan je hier niet aanpassen. Het menu volgt automatisch de pagina's van je sitemap.</p>`
            : `<p class="archer-muted">Deze sectie heeft geen teksten of opties om aan te passen.</p>`;
        }
        html += `<p class="archer-ed-status" data-ed-status aria-live="polite"></p>`;
        const focusId = document.activeElement && panel.contains(document.activeElement) ? document.activeElement.id : "";
        const scroll = panel.scrollTop;
        panel.innerHTML = html;
        panel.scrollTop = scroll;
        if (focusId && document.getElementById(focusId)) document.getElementById(focusId).focus();
      }

      const setStatus = (t, err) => {
        const el = $("[data-ed-status]", panel);
        if (el) {
          el.textContent = t || "";
          el.classList.toggle("archer-danger-text", !!err);
        }
      };

      async function open(slug, uuid) {
        if (sel && sel.uuid === uuid) return;
        await flushText();
        const s = sectionOf(slug, uuid);
        if (!s) return;
        $$(".archer-cv-sec.is-editing", inner).forEach((x) => x.classList.remove("is-editing"));
        const el = secElOf(uuid);
        if (el) el.classList.add("is-editing");
        sel = { slug, uuid, postId: s.ai_wireframe_id || s.bricks_template_id || 0, nodes: null };
        panel.hidden = false;
        shell.classList.add("has-panel");
        render();
        if (!sel.postId) {
          sel.error = "Deze sectie heeft nog geen voorbeeld.";
          return render();
        }
        const mine = sel;
        try {
          const out = await wf.post("aisb_archer_section_schema", { project_id: wf.state.projectId, post_id: sel.postId });
          if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "Laden mislukt");
          if (sel !== mine) return;
          sel.nodes = out.data.nodes;
          // Voorbeeld nog niet geladen: na het laden opnieuw bekijken (voor beeld en kolommen).
          const f = !docOf(uuid) && $("iframe", secElOf(uuid) || document.createElement("i"));
          if (f) f.addEventListener("load", () => setTimeout(() => sel === mine && render(), 50), { once: true });
        } catch (e) {
          if (sel !== mine) return;
          sel.error = e.message || "Laden mislukt";
        }
        render();
      }

      function close() {
        flushText();
        sel = null;
        panel.hidden = true;
        panel.innerHTML = "";
        shell.classList.remove("has-panel");
        $$(".archer-cv-sec.is-editing", inner).forEach((x) => x.classList.remove("is-editing"));
      }

      /** Header en footer zijn op elke pagina gelijk: dezelfde wijziging overal. */
      function syncTargets(s) {
        if (!isFixed(s)) return [];
        const out = [];
        wf.state.pages.forEach((p) => {
          if (p.slug === sel.slug) return;
          (modelOf(p.slug)?.sections || []).forEach((x) => {
            if ((isHead(s) ? isHead(x) : isFoot(x)) && (x.ai_wireframe_id || x.bricks_template_id)) {
              out.push({ page_slug: p.slug, post_id: x.ai_wireframe_id || x.bricks_template_id });
            }
          });
        });
        return out;
      }

      /** Stuurt bewerkingen naar de server; bij een sjabloon komt er een eigen kopie. */
      async function send(ops, reload) {
        if (!sel || !sel.nodes) return;
        const mine = sel;
        const s = sectionOf(sel.slug, sel.uuid);
        if (!s) return;
        busy = true;
        setStatus("Bewaren…");
        let out;
        try {
          out = await wf.post("aisb_archer_section_edit", {
            project_id: wf.state.projectId,
            page_slug: sel.slug,
            post_id: mine.postId,
            ops: JSON.stringify(ops),
            sync: JSON.stringify(syncTargets(s)),
          });
          if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "Bewaren mislukt");
        } catch (e) {
          busy = false;
          setStatus(e.message || "Bewaren mislukt", true);
          return;
        }
        busy = false;
        const d = out.data;
        mine.nodes = d.nodes;
        const touched = [];
        // Nieuwe kopie (sjabloon) of bijgewerkte header/footer op andere pagina's: modellen bijwerken.
        let idChanged = false;
        if (Number(d.post_id) !== Number(mine.postId)) {
          idChanged = true;
          s.ai_wireframe_id = Number(d.post_id);
          mine.postId = Number(d.post_id);
          markDirty(mine.slug);
          touched.push(mine.slug);
        } else if (reload) {
          touched.push(mine.slug);
        }
        Object.keys(d.synced || {}).forEach((slug) => {
          const id = Number(d.synced[slug]);
          (modelOf(slug)?.sections || []).forEach((x) => {
            if ((isHead(s) ? isHead(x) : isFoot(x)) && Number(x.ai_wireframe_id) !== id) {
              x.ai_wireframe_id = id;
              markDirty(slug);
            }
          });
          touched.push(slug);
        });
        // Voorbeelden verversen (zonder flits: nieuw voorbeeld eerst laden, dan wisselen).
        const waits = [];
        touched.forEach((slug) => {
          redrawPage(slug);
          (modelOf(slug)?.sections || []).forEach((x) => {
            if (slug === mine.slug ? x.uuid === mine.uuid : isFixed(s) && (isHead(s) ? isHead(x) : isFoot(x))) {
              waits.push(refresh(x.uuid));
            }
          });
        });
        await Promise.all(waits);
        if (sel === mine) {
          const el = secElOf(mine.uuid);
          if (el) el.classList.add("is-editing");
          if (reload || idChanged) render(); // na een tekstwijziging niet: de cursor blijft staan
          setStatus("Bewaard.");
        }
      }

      function refresh(uuid) {
        return new Promise((resolve) => {
          const el = secElOf(uuid);
          const wrap = el && $(".aisb-wf-iframe-wrap", el);
          const old = wrap && $("iframe", wrap);
          if (!old) return resolve();
          const base = old.src.split("&v=")[0];
          const f = old.cloneNode(false);
          f.src = base + "&v=" + Date.now();
          f.classList.add("is-loading");
          f.addEventListener("load", () => {
            measureIframe(f);
            f.classList.remove("is-loading");
            old.remove();
            setTimeout(() => measureIframe(f), 400);
            resolve();
          }, { once: true });
          f.addEventListener("load", () => setTimeout(() => measureIframe(f), 1500));
          wrap.appendChild(f);
          setTimeout(resolve, 8000);
        });
      }

      // Teksten: meteen zichtbaar in het voorbeeld, even later bewaard.
      function liveText(id, value, mode) {
        const doc = docOf(sel.uuid);
        const el = domOf(doc, id);
        if (!el) return;
        const n = sel.a && sel.a.byId[id];
        if (n && n.name === "button") {
          const t = Array.from(el.childNodes).find((c) => c.nodeType === 3 && c.textContent.trim());
          if (t) t.textContent = value;
          else el.insertBefore(doc.createTextNode(value), el.firstChild);
        } else if (mode === "inline") {
          el.textContent = value;
        } else {
          el.innerHTML = value; // al opgekuist door cleanHtml
        }
        const f = $("iframe", secElOf(sel.uuid));
        if (f) measureIframe(f);
      }
      async function flushText() {
        clearTimeout(textTimer);
        const ids = Object.keys(textQueue);
        if (!ids.length || !sel) return;
        const ops = ids.map((id) => ({ op: "text", id, key: textQueue[id].key, value: textQueue[id].value }));
        textQueue = {};
        const n = sel.nodes || [];
        ops.forEach((o) => {
          const node = n.find((x) => x.id === o.id);
          if (node) node.text = o.value;
        });
        await send(ops, false);
      }

      panel.addEventListener("input", (e) => {
        const f = e.target.closest("[data-ed-text]");
        if (!f || !sel) return;
        const id = f.getAttribute("data-ed-text");
        const mode = f.getAttribute("data-ed-mode") || "basic";
        const value = cleanHtml(mode === "inline" ? esc(f.textContent) : f.innerHTML, mode);
        textQueue[id] = { key: f.getAttribute("data-ed-key") || "text", value };
        // Tekenteller en waarschuwing bij een te lange tekst.
        const max = Number(f.getAttribute("data-ed-max")) || 0;
        const len = plainLen(mode === "inline" ? esc(value) : value);
        const lenEl = $(`[data-ed-len="${id}"]`, panel);
        if (lenEl) {
          lenEl.textContent = countText(len, max);
          lenEl.classList.toggle("is-over", !!max && len > max);
        }
        const hint = $(`[data-ed-hint="${id}"]`, panel);
        if (hint) hint.hidden = !(max && len > max);
        liveText(id, value, mode);
        setStatus("");
        clearTimeout(textTimer);
        textTimer = setTimeout(flushText, 800);
      });

      panel.addEventListener("click", async (e) => {
        if (e.target.closest("[data-ed-close]")) return close();
        if (!sel || !sel.a || busy) return;
        const a = sel.a;
        let b;
        if ((b = e.target.closest("[data-ed-media]"))) {
          const m = a.media[Number(b.getAttribute("data-ed-media"))];
          if (!m || b.getAttribute("data-ed-val") === m.side) return;
          await flushText();
          return send([{ op: "swap", id: m.id, with: m.other }], true);
        }
        if ((b = e.target.closest("[data-ed-buttons]"))) {
          const g = a.buttons[Number(b.getAttribute("data-ed-buttons"))];
          const want = Number(b.getAttribute("data-ed-val"));
          if (!g || want === g.list.length) return;
          await flushText();
          return send(want > g.list.length ? [{ op: "button", id: g.list[0] }] : [{ op: "remove", id: g.list[g.list.length - 1] }], true);
        }
        if ((b = e.target.closest("[data-ed-items]"))) {
          const r = a.repeats[Number(b.getAttribute("data-ed-items"))];
          if (!r || b.disabled) return;
          await flushText();
          const last = r.items[r.items.length - 1];
          return send([Number(b.getAttribute("data-ed-val")) > 0 ? { op: "clone", id: last } : { op: "remove", id: last }], true);
        }
        if ((b = e.target.closest("[data-ed-cols]"))) {
          const r = a.repeats[Number(b.getAttribute("data-ed-cols"))];
          const n = Number(b.getAttribute("data-ed-val"));
          if (!r || n === r.cols) return;
          await flushText();
          return send([{ op: "cols", id: r.id, n }], true);
        }
      });
      panel.addEventListener("mousedown", (e) => {
        e.stopPropagation();
        // Opmaakknoppen: de selectie in het tekstveld behouden.
        const b = e.target.closest("[data-ed-cmd]");
        if (!b) return;
        e.preventDefault();
        const f = b.closest(".archer-ed-field");
        const rte = f && $("[data-ed-text]", f);
        if (rte && document.activeElement !== rte) rte.focus();
        document.execCommand(b.getAttribute("data-ed-cmd"), false, null);
      });
      panel.addEventListener("focusin", (e) => {
        if (e.target.getAttribute && e.target.getAttribute("data-ed-mode") === "rich") document.execCommand("defaultParagraphSeparator", false, "p");
      });
      panel.addEventListener("keydown", (e) => {
        const f = e.target.closest && e.target.closest("[data-ed-text]");
        if (!f || e.key !== "Enter") return;
        const mode = f.getAttribute("data-ed-mode");
        if (mode === "inline") {
          e.preventDefault(); // titels en knoppen: één regel
        } else if (mode === "basic") {
          e.preventDefault();
          document.execCommand("insertLineBreak");
        }
      });
      // Plakken: alleen de tekst, zonder opmaak of kleuren van elders.
      panel.addEventListener("paste", (e) => {
        if (!e.target.closest || !e.target.closest("[data-ed-text]")) return;
        e.preventDefault();
        const t = (e.clipboardData || window.clipboardData).getData("text/plain") || "";
        const f = e.target.closest("[data-ed-text]");
        document.execCommand("insertText", false, f.getAttribute("data-ed-mode") === "inline" ? t.replace(/\s+/g, " ") : t);
      });
      panel.addEventListener("wheel", (e) => e.stopPropagation());
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && sel && !(e.target.closest && e.target.closest("input, textarea"))) close();
      });

      // Ander voorbeeld, verwijderen of een nieuwe wireframe: het paneel volgt of sluit.
      const syncAfterRender = () => {
        if (!sel) return;
        const s = sectionOf(sel.slug, sel.uuid);
        const id = s && (s.ai_wireframe_id || s.bricks_template_id);
        if (!s || isOpen()) return close();
        const el = secElOf(sel.uuid);
        if (el) el.classList.add("is-editing");
        if (Number(id) !== Number(sel.postId) && !busy) {
          const keep = { slug: sel.slug, uuid: sel.uuid };
          sel = null;
          open(keep.slug, keep.uuid);
        }
      };

      return { open, close, flush: flushText, afterRender: syncAfterRender, isOpen: () => !!sel };
    })();
    editorApi = editor;
    const origOpenExpanded = wf.openExpandedPage;
    wf.openExpandedPage = function () {
      editor.close();
      return origOpenExpanded.apply(this, arguments);
    };

    // Schaal van de voorbeelden bijwerken als het venster verandert.
    window.addEventListener("resize", () => {
      if (isOpen()) return;
      wf.state.pages.forEach((p) => redrawPage(p.slug));
    });

    const origClose = wf.closeExpandedPage;
    wf.closeExpandedPage = function () {
      const r = origClose.apply(this, arguments); // roept renderWhiteboard aan
      if (canvasStale) wf.renderWhiteboard();
      renderTabs();
      renderPager();
      return r;
    };

    // Statusberichten in het Nederlands.
    const map = [
      [/^Generating wireframe for (.+) \((\d+)\/(\d+)\)\.\.\.$/, "Wireframe maken voor $1 ($2 van $3)…"],
      [/^Generating wireframe\.\.\.$/, "Wireframe maken…"],
      [/^Generated\.$/, "Klaar."],
      [/^Finished! Generated: (\d+)\. Failed: (\d+)\.$/, "Klaar: $1 pagina's gemaakt, $2 mislukt."],
      [/^Shuffled unlocked sections\.$/, "Andere voorbeelden gekozen voor de secties die niet vastgezet zijn."],
      [/^Saving all pages\.\.\.$/, "Alle pagina's bewaren…"],
      [/^Saved (\d+) pages\.(.*)$/, "$1 pagina's bewaard.$2"],
      [/^Saving\.\.\.$/, "Bewaren…"],
      [/^Saved\.$/, "Bewaard."],
      [/^No pages found in sitemap\.$/, "De sitemap heeft nog geen pagina's."],
      [/^Shuffling section\.\.\.$/, "Ander voorbeeld zoeken…"],
      [/^Shuffled\.$/, "Ander voorbeeld gekozen."],
      [/^Edit mode:.*$/, "Klik in de sectie op een titel, tekst of knop en typ. Klik daarna op 'Klaar met aanpassen'."],
      [/^Saving changes\.\.\.$/, "Tekst bewaren…"],
      [/^Saved (\d+) text change\(s\)\. Refreshing\.\.\.$/, "$1 tekstwijziging(en) bewaard."],
      [/^No changes detected\.$/, "Geen tekstwijzigingen gevonden."],
      [/^Only Bricks sections can be edited inline\.$/, "Deze sectie kan je hier niet rechtstreeks aanpassen."],
      [/^Preview iframe not loaded yet\.$/, "Het voorbeeld laadt nog. Probeer zo opnieuw."],
      [/^Replacing section type\.\.\.$/, "Sectie vervangen…"],
      [/^Replaced\.$/, "Sectie vervangen."],
      [/^Shuffle failed$/, "Er is geen ander voorbeeld gevonden."],
    ];

    // Knoptekst bij tekst aanpassen volgt de modus.
    wf.el.sections &&
      wf.el.sections.addEventListener("click", (e) => {
        const b = e.target.closest('[data-act="edit"]');
        if (!b) return;
        setTimeout(() => {
          b.textContent = b.classList.contains("active") ? "Klaar met aanpassen" : "Tekst aanpassen";
        }, 0);
      });
    const origStatus = wf.setStatus;
    wf.setStatus = function (msg, kind) {
      let m = msg;
      if (typeof m === "string") for (const [re, to] of map) if (re.test(m)) { m = m.replace(re, to); break; }
      return origStatus.call(this, m, kind);
    };
    const origLoader = wf.showCanvasLoader;
    if (origLoader) {
      wf.showCanvasLoader = function (msg) {
        let m = msg || "";
        m = m.replace(/^Generating (.+) \((\d+)\/(\d+)\)…$/, "$1 maken ($2 van $3)…").replace(/^Generating wireframes?…$/, "Wireframes maken…");
        return origLoader.call(this, m);
      };
    }
  }

  /* ── Stijl en foto's ────────────────────────────────────── */

  function initStyleGuide() {
    const SG = window.AISB_StyleGuide;
    const panel = $("[data-archer-sg-view]");
    if (!SG || !panel) return;
    const view = panel.getAttribute("data-archer-sg-view");

    const wfReady = () => Array.isArray(SG.wireframePages) && SG.wireframePages.length > 0;
    // Stockfoto's komen pas na een klik ("Kies stockfoto's voor deze pagina" of "voor alle pagina's").
    // Tot dan staan de lege fotovakken van de sjablonen er; guide.images houdt per fotovak een lege plaats.
    const EMPTY = () => ({ full: "", thumb: "", alt: "", archerEmpty: true });
    const isEmpty = (img) => !img || img.archerEmpty === true || !(img.full || img.thumb);
    const isStock = (img) => !isEmpty(img) && (!!img.source || /unsplash|pexels/i.test(img.full || ""));
    const mediaCountOf = (s) => (s && s.media_count) || 0;
    const sectionRange = (slug, sIdx) => {
      let base = 0;
      for (const p of SG.wireframePages || []) {
        const secs = p.sections || [];
        for (let i = 0; i < secs.length; i++) {
          if (p.slug === slug && i === sIdx) return { start: base, count: mediaCountOf(secs[i]) };
          base += mediaCountOf(secs[i]);
        }
      }
      return { start: base, count: 0 };
    };
    const pageSlots = () => {
      let base = 0;
      return (SG.wireframePages || []).map((p) => {
        const secs = p.sections || [];
        const count = secs.reduce((n, x) => n + mediaCountOf(x), 0);
        const r = { slug: p.slug, title: p.title || p.slug, start: base, count, sections: secs.map((x) => x.name || x.section_name || x.type || "").filter(Boolean).slice(0, 10).join(", ") };
        base += count;
        return r;
      });
    };
    const padImages = () => {
      if (!wfReady()) return (SG.guide && SG.guide.images) || [];
      const total = (SG.wireframePages || []).reduce((n, p) => n + (p.sections || []).reduce((m, x) => m + mediaCountOf(x), 0), 0);
      if (!SG.guide.images || !Array.isArray(SG.guide.images)) SG.guide.images = [];
      const imgs = SG.guide.images;
      while (imgs.length < total) imgs.push(EMPTY());
      if (total && imgs.length > total) imgs.length = total;
      return imgs;
    };
    UI.photoHelpers = { EMPTY, isEmpty, isStock, pageSlots, padImages };
    if (typeof SG.autoAssignImages === "function" && !SG.autoAssignImages._archer) {
      const origAuto = SG.autoAssignImages;
      SG.autoAssignImages = function () {
        if (!wfReady()) return Promise.resolve();
        // Niet meer automatisch: alleen lege fotovakken klaarzetten.
        padImages();
        SG.imagesLoaded = true;
        return Promise.resolve();
      };
      SG.autoAssignImages._archer = true;
      SG.autoAssignImages._orig = origAuto;
      if (SG.onStep3Enter === origAuto) SG.onStep3Enter = SG.autoAssignImages;
    }
    // Weergave kiezen: stijl = kleuren + lettertypes, foto's = afbeeldingen.
    const show = () => {
      if (typeof SG.goToWizardStep !== "function") return;
      SG.goToWizardStep(view === "photos" ? "3" : "1");
    };
    show();
    // Stockfoto's vullen elke <img> in volgorde: het logo eerst uit de pagina halen, anders wordt dat een foto.
    if (typeof SG.injectImagesIntoIframe === "function" && !SG.injectImagesIntoIframe._archer) {
      const origInject = SG.injectImagesIntoIframe;
      SG.injectImagesIntoIframe = function (iframe) {
        const all = (SG.guide && SG.guide.images) || [];
        const manual = all.some(isEmpty);
        let doc0 = null;
        try { doc0 = iframe.contentDocument; } catch (e) {}
        // Oorspronkelijke fotovakken onthouden, om lege vakken leeg te laten.
        if (doc0) doc0.querySelectorAll("img").forEach((img) => {
          if (!img.hasAttribute("data-archer-orig")) img.setAttribute("data-archer-orig", img.getAttribute("src") || "");
        });
        const restore = (img) => {
          const o = img.getAttribute("data-archer-orig");
          if (o !== null && img.getAttribute("src") !== o) { img.setAttribute("src", o); img.removeAttribute("srcset"); }
        };
        if (manual) {
          const r = sectionRange(iframe._pageSlug, iframe._sectionIdx);
          const slots = all.slice(r.start, r.start + r.count);
          if (!r.count || slots.every(isEmpty)) {
            if (doc0) doc0.querySelectorAll("img[data-archer-orig]").forEach((img) => { if (!img.closest(".brxe-logo") && !img.classList.contains("bricks-site-logo")) restore(img); });
            return;
          }
        }
        let held = [];
        try {
          const doc = iframe.contentDocument;
          held = Array.from(doc.querySelectorAll(".brxe-logo img, img.bricks-site-logo")).map((img) => {
            const mark = doc.createComment("archer-logo");
            img.replaceWith(mark);
            return [img, mark];
          });
        } catch (e) {}
        try {
          const res = origInject.apply(this, arguments);
          if (manual && doc0) {
            const r = sectionRange(iframe._pageSlug, iframe._sectionIdx);
            doc0.querySelectorAll("img[data-aisb-img-ui]").forEach((img) => {
              const ui = parseInt(img.getAttribute("data-aisb-img-ui"), 10);
              if (isNaN(ui) || ui >= r.count || isEmpty(all[r.start + ui])) restore(img);
            });
          }
          return res;
        } finally {
          held.forEach(([img, mark]) => {
            mark.replaceWith(img);
            if (SG.guide && SG.guide.logoUrl) {
              img.src = SG.guide.logoUrl;
              img.srcset = "";
            }
          });
        }
      };
      SG.injectImagesIntoIframe._archer = true;
    }
    // Foto's: de stap opent vaak voor de wireframes (en de bewaarde stijlgids) geladen zijn. Dan telde de
    // stijlgids 0 fotovakken, toonde "No images needed" en maakte guide.images leeg. Daarom: wachten.
    const mediaTotal = () =>
      (SG.wireframePages || []).reduce((n, p) => n + (p.sections || []).reduce((m, x) => m + (x.media_count || 0), 0), 0);
    /** Bewaarde stockfoto's tonen (de stijlgids doet dat alleen na een nieuwe zoekopdracht). */
    const showStock = () => {
      const grid = SG.el && SG.el.autoImagesContainer;
      const imgs = (SG.guide && SG.guide.images) || [];
      if (!grid) return;
      if (imgs.some((i) => !isEmpty(i)) && !grid.querySelector(".aisb-sg-auto-card")) {
        grid.innerHTML = imgs
          .map((img, i) => (isEmpty(img) ? "" : `<div class="aisb-sg-auto-card" data-image-index="${i}" title="Klik om te wisselen"><img src="${esc(img.thumb || img.full || "")}" alt="${esc(img.alt || "")}" loading="lazy"></div>`))
          .join("");
      }
      const total = mediaTotal();
      if (SG.el.uploadTotalNeeded) SG.el.uploadTotalNeeded.textContent = total;
      if (SG.el.uploadHint) SG.el.uploadHint.textContent = `${(SG.uploadedImages || []).length} eigen foto's · ${total} foto's nodig`;
      if (SG.refreshImageLibraryState) SG.refreshImageLibraryState();
      // Het aantal stockfoto's: alleen gevulde vakken tellen.
      const tabCount = $("[data-unsplash-tab-count]");
      if (tabCount) tabCount.textContent = "(" + imgs.filter((i) => !isEmpty(i)).length + ")";
    };
    if (view === "photos") {
      let tries = 0;
      const waitPages = setInterval(async () => {
        tries++;
        if (!wfReady() && tries <= 80) return;
        clearInterval(waitPages);
        if (!wfReady()) return;
        padImages();
        SG.imagesLoaded = true;
        showStock();
        initStockButtons();
      }, 500);
    }

    /** Knoppen "Kies stockfoto's voor deze pagina" en "voor alle pagina's". */
    function initStockButtons() {
      const host = $("[data-wizard-panel='3']", panel);
      if (!host || $("[data-archer-stock]", host)) return;
      const box = document.createElement("section");
      box.className = "archer-stock-box";
      box.setAttribute("data-archer-stock", "");
      box.innerHTML =
        "<h4>Stockfoto's</h4>" +
        '<p class="archer-muted archer-small">De fotovakken blijven leeg tot je stockfoto\'s kiest. Per pagina zoeken we foto\'s die bij die pagina passen. Eigen foto\'s kan je altijd opladen.</p>' +
        '<div class="archer-stock-actions">' +
        '<button type="button" class="archer-btn archer-btn-primary archer-btn-sm" data-stock-page>Kies stockfoto\'s voor deze pagina</button>' +
        '<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-stock-all>Kies stockfoto\'s voor alle pagina\'s</button>' +
        "</div>" +
        '<p class="archer-small" data-stock-status aria-live="polite"></p>';
      host.prepend(box);
      const status = $("[data-stock-status]", box);
      const pageNow = () => {
        const sel = $(".archer-sg-page-select", panel);
        const slug = (sel && sel.value) || "home";
        return pageSlots().find((p) => p.slug === slug) || pageSlots()[0];
      };
      const count = () => {
        const imgs = padImages();
        const filled = imgs.filter((i) => !isEmpty(i)).length;
        const pg = pageNow();
        const pgFilled = pg ? imgs.slice(pg.start, pg.start + pg.count).filter((i) => !isEmpty(i)).length : 0;
        status.textContent = `${filled} van ${imgs.length} fotovakken gevuld` + (pg ? ` · ${pg.title}: ${pgFilled} van ${pg.count}` : "");
        const btn = $("[data-stock-page]", box);
        if (btn && pg) btn.textContent = pg.count && pgFilled >= pg.count ? `Andere stockfoto's voor ${pg.title}` : `Kies stockfoto's voor ${pg.title}`;
      };
      const fill = async (pg, replace) => {
        const imgs = padImages();
        const idx = [];
        for (let i = pg.start; i < pg.start + pg.count; i++) if (isEmpty(imgs[i]) || (replace && isStock(imgs[i]))) idx.push(i);
        if (!idx.length) return 0;
        const exclude = imgs.filter((i) => !isEmpty(i)).map((i) => i.full);
        const out = await SG.post("aisb_archer_stock_page", {
          project_id: SG.projectId, page_slug: pg.slug, page_title: pg.title, sections: pg.sections,
          need: idx.length, exclude: JSON.stringify(exclude.slice(-150)),
        });
        if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "Geen stockfoto's gevonden.");
        // Foto's die intussen al op een andere pagina staan, overslaan (de pagina's lopen tegelijk).
        const taken = new Set(imgs.filter((x) => !isEmpty(x)).map((x) => x.full));
        const found = (out.data.images || []).filter((x) => x && x.full && !taken.has(x.full));
        let n = 0;
        idx.forEach((i, k) => { if (found[k]) { imgs[i] = found[k]; n++; } });
        return n;
      };
      const done = () => {
        SG.imagesLoaded = true;
        if (SG.applyImagesToAllIframes) SG.applyImagesToAllIframes();
        const grid = SG.el && SG.el.autoImagesContainer;
        if (grid) grid.innerHTML = "";
        showStock();
        if (typeof SG.saveDraft === "function") SG.saveDraft();
        count();
      };
      const busy = (on) => $$("button", box).forEach((b) => (b.disabled = on));
      $("[data-stock-page]", box).addEventListener("click", async () => {
        const pg = pageNow();
        if (!pg || !pg.count) { status.textContent = "Deze pagina heeft geen fotovakken."; return; }
        busy(true);
        status.textContent = `Foto's zoeken voor ${pg.title}…`;
        try {
          const imgs = padImages();
          const full = imgs.slice(pg.start, pg.start + pg.count).every((i) => !isEmpty(i));
          const n = await fill(pg, full);
          done();
          if (!n) status.textContent += " · alle vakken hadden al een foto";
        } catch (err) {
          status.textContent = err.message || "Er ging iets mis.";
        } finally { busy(false); }
      });
      $("[data-stock-all]", box).addEventListener("click", async () => {
        busy(true);
        const list = pageSlots().filter((p) => p.count);
        let k = 0;
        status.textContent = `Foto's zoeken voor ${list.length} pagina's…`;
        try {
          // Drie pagina's tegelijk; elke pagina krijgt foto's die bij haar onderwerp passen.
          let next = 0;
          const worker = async () => {
            while (next < list.length) {
              const pg = list[next++];
              try { await fill(pg, false); } catch (e) { console.warn("[Archer] stockfoto's", pg.slug, e); }
              k++;
              status.textContent = `Foto's zoeken… ${k} van ${list.length} pagina's`;
              if (SG.applyImagesToAllIframes) SG.applyImagesToAllIframes();
            }
          };
          await Promise.all([worker(), worker(), worker()]);
          done();
        } finally { busy(false); }
      });
      const sel = $(".archer-sg-page-select", panel);
      if (sel) sel.addEventListener("change", count);
      count();
      // De tekst onderaan de stap volgt de nieuwe werkwijze.
      $$(".archer-step-footer .archer-muted, [data-archer-step-footer] .archer-muted").forEach((el) => {
        if (/stockfoto/i.test(el.textContent)) el.textContent = "Geen eigen foto's? Kies stockfoto's per pagina of voor alle pagina's, of publiceer met lege fotovakken.";
      });
    }

    // Teksten van de wizard in gewone taal.
    const swap = [
      [".aisb-sg-wizard-step[data-wizard-step='1'] .aisb-sg-wizard-step-label", "Kleuren"],
      [".aisb-sg-wizard-step[data-wizard-step='2'] .aisb-sg-wizard-step-label", "Lettertypes"],
      [".aisb-sg-wizard-step[data-wizard-step='3'] .aisb-sg-wizard-step-label", "Foto's"],
      ["[data-wizard-panel='1'] .aisb-sg-panel-title", "Logo en kleuren"],
      ["[data-wizard-panel='2'] .aisb-sg-panel-title", "Lettertypes"],
      ["[data-wizard-panel='3'] .aisb-sg-panel-title", "Foto's per sectie"],
      ["[data-colour-mode='logo']", "Uit een logo of foto"],
      ["[data-colour-mode='manual']", "Zelf kiezen"],
      [".aisb-sg-preview-label", "Live voorbeeld"],
      [".aisb-sg-preview-hint", "Klik op een tekst om ze aan te passen · kleurschema rechtsboven in een sectie"],
    ];
    swap.forEach(([sel, txt]) => {
      const el = $(sel, panel);
      if (el) el.textContent = txt;
    });
    UI.translate(panel, {
      "Brand Logo": "Logo",
      "Upload your website logo to place it in the wireframes.": "Upload het logo. Het komt in de header van de website.",
      "Brand Colours": "Kleuren",
      "Extract colours from an image or pick manually.": "Haal de kleuren uit het logo of een foto, of kies ze zelf.",
      "Drop your logo here or": "Sleep het logo hierheen of",
      "Drop an image here or": "Sleep een afbeelding hierheen of",
      "browse": "kies een bestand",
      "Extracted Colours": "Gevonden kleuren",
      "Shuffle unlocked": "Andere combinatie",
      "Primary colour": "Hoofdkleur",
      "AI automatically picks the best Google Fonts pairing based on your brand colours and website topic.":
        "AI kiest een passende combinatie van lettertypes bij de kleuren en het onderwerp van de website.",
      "Fonts will be generated automatically when you arrive at this step…": "De lettertypes worden gekozen zodra je deze stap opent…",
      "Typography": "Lettertypes",
      "Change fonts": "Ander lettertype kiezen",
      "Pick a different font if you prefer — or keep the AI suggestion.": "Kies zelf een ander lettertype, of houd het voorstel.",
      "Heading font": "Titels",
      "Body font": "Lopende tekst",
      "Upload your own images or let AI find matching stock photos.": "Upload eigen foto's of kies stockfoto's per pagina.",
      "Drag & drop images here or": "Sleep foto's hierheen of",
      "No uploaded images yet.": "Nog geen eigen foto's.",
      "Your uploads": "Eigen foto's",
      "Unsplash images": "Stockfoto's",
      "Unsplash images will be loaded automatically…": "Nog geen stockfoto's gekozen.",
    });

    /* Live voorbeeld: standaard ingezoomd op de startpagina, met een keuzelijst per pagina. */
    const header = $(".aisb-sg-preview-header", panel);
    let focusSlug = "home";
    const pick = document.createElement("select");
    pick.className = "archer-sg-page-select";
    pick.setAttribute("aria-label", "Pagina in het live voorbeeld");
    pick.hidden = true;
    if (header) {
      const label = $(".aisb-sg-preview-label", header);
      if (label) label.after(pick);
      else header.prepend(pick);
    }
    const fillPick = () => {
      const pages = SG.wireframePages || [];
      if (!pages.length) return;
      if (!pages.some((p) => p.slug === focusSlug)) focusSlug = pages[0].slug;
      pick.innerHTML =
        pages.map((p) => `<option value="${esc(p.slug)}">${esc(p.title || p.slug)}</option>`).join("");
      pick.value = focusSlug;
      pick.hidden = false;
    };
    // Geen canvas meer: één pagina over de volle breedte, gewoon scrollen; wisselen met de keuzelijst.
    const focusPage = (container) => {
      const canvas = $(".aisb-sg-canvas", container);
      const innerEl = canvas && $(".aisb-sg-canvas-inner", canvas);
      if (!canvas || !innerEl) return;
      canvas.classList.add("archer-sg-pageview");
      const pages = SG.wireframePages || [];
      const idx = Math.max(0, pages.findIndex((p) => p.slug === focusSlug));
      const cards = $$(".aisb-sg-page-card", innerEl);
      cards.forEach((c, i) => (c.style.display = i === idx ? "" : "none"));
      const card = cards[idx];
      if (!card) return;
      card.style.zoom = "";
      const w = canvas.clientWidth;
      const cw = card.offsetWidth;
      if (!w || !cw) return;
      card.style.zoom = String(Math.min(1, (w - 4) / cw));
    };
    // Het canvas van de stijlgids zoomt en verschuift met het wiel: hier gewoon scrollen.
    const plainScroll = (container) => {
      if (!container || container._archerScroll) return;
      container._archerScroll = true;
      ["wheel", "mousedown", "pointerdown"].forEach((type) =>
        container.addEventListener(type, (e) => {
          if (!e.target.closest || !e.target.closest(".aisb-sg-canvas")) return;
          if (type === "wheel" && (e.ctrlKey || e.metaKey)) e.preventDefault();
          e.stopPropagation();
        }, { capture: true, passive: false }),
      );
    };
    const setupCanvas = (container) => {
      if (!container || !$(".aisb-sg-canvas", container)) return;
      // In een geschaald canvas laden lazy iframes niet altijd: meteen laden.
      $$("iframe", container).forEach((f) => (f.loading = "eager"));
      if (container._sgFitToView && container._sgFitToView !== container._archerFocus) {
        container._archerFitAll = container._sgFitToView;
      }
      container._archerFocus = () => focusPage(container);
      plainScroll(container);
      container._sgFitToView = container._archerFocus; // ook bij het wisselen van kleuren naar lettertypes
      fillPick();
      requestAnimationFrame(() => focusPage(container));
      setTimeout(() => focusPage(container), 80);
    };
    const containers = () => Array.from(new Set([SG.el.coloursPreview, SG.el.typographyPreview, SG.el.imagesPreview].filter(Boolean)));
    if (typeof SG.buildCanvasForContainer === "function") {
      const origBuild = SG.buildCanvasForContainer;
      SG.buildCanvasForContainer = function (container) {
        const r = origBuild.apply(this, arguments);
        setupCanvas(container);
        return r;
      };
    }
    containers().forEach(setupCanvas); // canvas dat al gebouwd was
    pick.addEventListener("change", () => {
      focusSlug = pick.value;
      containers().forEach(focusPage);
    });
    window.addEventListener("resize", () => containers().forEach(focusPage));

    /* Stijlrichtingen (tasteskill): palet + lettertypes die samen kloppen, per sector. */
    (function tasteDirections() {
      const colourPanel = $("[data-wizard-panel='1']", panel);
      const anchor = colourPanel && ($(".aisb-sg-subtabs", colourPanel) || $("[data-colour-mode]", colourPanel));
      if (!colourPanel || !SG.projectId || typeof SG.post !== "function") return;
      const box = document.createElement("section");
      box.className = "archer-taste";
      box.setAttribute("aria-label", "Stijlrichting");
      box.innerHTML = '<div class="archer-taste-head"><h4>Stijlrichting</h4>' +
        '<button type="button" class="archer-link-btn" data-taste-more>Andere richtingen</button></div>' +
        '<p class="archer-taste-intro">Kleuren en lettertypes die samen kloppen. Je kan daarna alles nog aanpassen.</p>' +
        '<div class="archer-taste-list" data-taste-list><p class="archer-muted">Richtingen laden…</p></div>' +
        '<div class="archer-taste-tools"><span>Los schudden:</span>' +
        '<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-taste-shuffle="palette">Andere kleuren</button>' +
        '<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-taste-shuffle="type">Andere letters</button></div>' +
        '<div class="archer-taste-tools" role="group" aria-label="Hoeken van knoppen en velden"><span>Hoeken:</span>' +
        '<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-radius="sharp">Recht</button>' +
        '<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-radius="soft">Zacht</button>' +
        '<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-radius="round">Rond</button></div>' +
        '<p class="archer-taste-status" data-taste-status aria-live="polite"></p>';
      const host = anchor ? anchor.closest("div") : null;
      if (host && host.parentElement) host.parentElement.insertBefore(box, host);
      else colourPanel.prepend(box);
      let round = Number((SG.guide && SG.guide.tasteDirection && SG.guide.tasteDirection.round) || 0);

      /* ── Archer-stijlen: een volledig ontwerpsysteem kiezen (letters, kleuren, ruimte, vormen) ── */
      const sbox = document.createElement("section");
      sbox.className = "archer-styles";
      sbox.setAttribute("aria-label", "Stijl");
      sbox.innerHTML = '<div class="archer-taste-head"><h4>Stijl</h4></div>' +
        '<p class="archer-taste-intro">Kies een stijl voor de hele website: letters, kleuren, witruimte en vormen horen samen. Daaronder kan je kleuren en letters nog verfijnen.</p>' +
        '<div data-style-advice></div>' +
        '<div class="archer-style-grid" data-style-grid><p class="archer-muted">Stijlen laden…</p></div>' +
        '<p class="archer-taste-status" data-style-status aria-live="polite"></p>' +
        '<p class="archer-style-brand" data-style-brand hidden></p>' +
        '<p class="archer-style-md" data-style-md hidden></p>';
      box.before(sbox);
      $(".archer-taste-head h4", box).textContent = "Kleuren en letters verfijnen";
      $(".archer-taste-intro", box).textContent = "Andere combinaties binnen de gekozen stijl: kleuren of letters apart wisselen.";
      let styles = [];
      const styleFonts = document.createElement("link");
      styleFonts.rel = "stylesheet";
      document.head.appendChild(styleFonts);
      const styleNow = () => (SG.guide && !Array.isArray(SG.guide) && SG.guide.style) || "";
      const styleById = (id) => styles.find((x) => x.id === id);
      // Kleur- en letteraanpassingen na het kiezen gaan voor op de stijl (zelfde regel als op de server).
      const styleCssFor = (st) => {
        if (!st) return "";
        const cols = (SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : (SG.guide && SG.guide.colours) || []);
        const get = (n) => ((cols.find((c) => c && c.name === n) || {}).hex || "");
        const map = { Primary: "--a-accent", Secondary: "--a-dark", Dark: "--a-ink", Light: "--a-surface", Neutral: "--a-surface2" };
        let o = "";
        Object.keys(map).forEach((n) => { const h = get(n); if (/^#[0-9a-f]{6}$/i.test(h)) o += map[n] + ":" + h + ";"; });
        // Tekst op de knoppen: wit of de tekstkleur, wat het best leesbaar is (zoals AISB_Archer_Styles::css).
        const acc = get("Primary");
        if (/^#[0-9a-f]{6}$/i.test(acc)) {
          const L = (h) => { const n = parseInt(h.slice(1), 16); const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f((n >> 16) & 255) + 0.7152 * f((n >> 8) & 255) + 0.0722 * f(n & 255); };
          const white = 1.05 / (L(acc) + 0.05);
          const ink = get("Dark") || (st.colours && st.colours.ink) || "#111111";
          o += "--a-accent-ink:" + (white >= 4.5 || !/^#[0-9a-f]{6}$/i.test(ink) ? "#FFFFFF" : ink) + ";";
        }
        const hf = SG.guide && SG.guide.headingFont, bf = SG.guide && SG.guide.bodyFont;
        if (hf && hf !== st.headingFont) o += `--a-font-heading:'${hf}',ui-sans-serif,system-ui,sans-serif;`;
        if (bf && bf !== st.bodyFont) o += `--a-font-body:'${bf}',ui-sans-serif,system-ui,sans-serif;`;
        const rk = SG.guide && !Array.isArray(SG.guide) ? SG.guide.radius : "";
        if (rk && rk !== ((st.guide && st.guide.radius) || "")) o += UI.radiusVars(rk);
        return st.css + (o ? ":root{" + o + "}" : "");
      };
      const injectStyle = (iframe) => {
        let doc;
        try { doc = iframe.contentDocument; } catch (e) { return; }
        if (!doc || !doc.head) return;
        let el = doc.getElementById("archer-style");
        const st = styleById(styleNow());
        if (!st) { if (el) el.remove(); return; }
        const css = styleCssFor(st);
        if (!el) { el = doc.createElement("style"); el.id = "archer-style"; }
        if (el.textContent !== css) el.textContent = css;
        // Vaste plek: direct na de stijlgids, daarna het kleurschema per sectie. De overige
        // voorbeeldregels (contrast, geen overgangen, hoeken) blijven erna staan.
        const ov = doc.getElementById("aisb-sg-override") || doc.getElementById("archer-house");
        if (ov && ov.parentNode === doc.head) ov.after(el);
        else if (!el.parentNode) doc.head.appendChild(el);
        const sch = doc.getElementById("archer-scheme");
        if (sch) el.after(sch);
      };
      UI.injectStyle = injectStyle;
      // Rustige achtergronden: alle secties op de grond, hoogstens één band per pagina (oproep) en de footer.
      const bandSchemes = (st) => {
        const out = { global: {}, pages: {} };
        // Donkere stijl: de rol Dark is daar de lichte tekstkleur, dus een donkere band wordt 'field'.
        const band = (b) => ((st.dark || (st.guide && st.guide.darkMode)) && b === "ink" ? "field" : b);
        if (st.bands && st.bands.footer) out.global.footer = band(st.bands.footer);
        (SG.wireframePages || []).forEach((p) => {
          const map = {};
          let cta = false;
          (p.sections || []).forEach((sec) => {
            const t = String((sec && sec.type) || "").toLowerCase();
            if (!sec || !sec.uuid || /^(header|footer)$/.test(t)) return;
            if (!cta && st.bands && st.bands.cta && /cta|call|oproep/.test(t)) { map[sec.uuid] = band(st.bands.cta); cta = true; return; }
            if (st.bands && st.bands.quote && /testimon|review|quote|getuig/.test(t)) map[sec.uuid] = band(st.bands.quote);
          });
          if (Object.keys(map).length) out.pages[p.slug] = map;
        });
        return out;
      };
      const applyStyle = (st, quiet) => {
        if (!st) return;
        if (Array.isArray(SG.guide) || !SG.guide) SG.guide = {};
        const g = st.guide;
        setColours(g.colours);
        setFonts(g.headingFont, g.bodyFont);
        SG.guide.typography = JSON.parse(JSON.stringify(g.typography));
        if (SG.renderTypography) SG.renderTypography(SG.guide.typography);
        ["style", "radius", "sectionBg1", "sectionBg2", "darkMode", "groundsLight"].forEach((k) => (SG.guide[k] = g[k]));
        const branded = UI.brandColours ? UI.brandColours(g.colours) : g.colours;
        if (branded !== g.colours) setColours(branded);
        SG.guide.sectionSchemes = bandSchemes(st);
        SG.guide.tasteDirection = null;
        SG.applyOverridesToAllIframes();
        renderStyles();
        if (UI.markRadius) UI.markRadius();
        if (!quiet) $("[data-style-status]", sbox).textContent = "Stijl " + st.name + " toegepast op alle pagina's" + (branded !== g.colours ? ", met de kleuren van je logo." : ".");
        renderBrand();
        SG.saveDraft && SG.saveDraft();
      };
      // Bestaande projecten: de stijl verving de logokleuren. Eén klik zet ze terug, zonder de stijl te wisselen.
      function renderBrand() {
        const el = $("[data-style-brand]", sbox);
        if (!el) return;
        const b = SG.guide && !Array.isArray(SG.guide) ? SG.guide.brand : null;
        const cols = (SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : (SG.guide && SG.guide.colours) || []);
        const prim = ((cols.find((c) => c && c.name === "Primary") || {}).hex || "").toLowerCase();
        const show = !!(b && /^#[0-9a-f]{6}$/i.test(b.primary || "") && cols.length && prim !== b.primary.toLowerCase());
        el.hidden = !show;
        el.innerHTML = show
          ? `<button type="button" class="archer-style-brand-btn" data-style-brand-apply><span class="archer-style-brand-dot" style="background:${esc(b.primary)}"></span>Kleuren van je logo gebruiken</button>` +
            '<span class="archer-muted">De stijl blijft; de knoppen en accenten krijgen de kleur van het logo.</span>'
          : "";
      }
      // Nieuw logo in de header: de merkkleur komt daaruit. Staan de kleuren nog zoals de stijl ze meebracht,
      // dan meteen toepassen (dat verwacht wie een logo oplaadt); anders de knop tonen.
      let lastLogo = (SG.guide && !Array.isArray(SG.guide) && SG.guide.logoUrl) || "";
      setInterval(() => {
        const g = SG.guide && !Array.isArray(SG.guide) ? SG.guide : null;
        const now = (g && g.logoUrl) || "";
        if (!g || now === lastLogo) return;
        lastLogo = now;
        if (!now || (g.brand && g.brand.source !== "logo")) return renderBrand();
        g.brand = null;
        UI.ensureBrand().then(() => {
          if (g.brand) g.brand.source = "logo";
          const st = styleById(styleNow());
          const cols = (SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : g.colours) || [];
          const prim = ((cols.find((c) => c && c.name === "Primary") || {}).hex || "").toLowerCase();
          const def = st && st.guide ? ((st.guide.colours || []).find((c) => c.name === "Primary") || {}).hex || "" : "";
          if (g.brand && g.brand.primary && st && prim === def.toLowerCase()) {
            setColours(UI.brandColours(st.guide.colours));
            SG.applyOverridesToAllIframes();
            renderStyles();
            $("[data-style-status]", sbox).textContent = "Kleuren uit je logo toegepast op stijl " + st.name + ".";
            SG.saveDraft && SG.saveDraft();
          }
          renderBrand();
        });
      }, 1000);
      sbox.addEventListener("click", (e) => {
        if (!e.target.closest("[data-style-brand-apply]")) return;
        const cols = (SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : (SG.guide && SG.guide.colours) || []);
        setColours(UI.brandColours(cols));
        SG.applyOverridesToAllIframes();
        renderStyles();
        renderBrand();
        $("[data-style-status]", sbox).textContent = "Kleuren van je logo toegepast.";
        SG.saveDraft && SG.saveDraft();
      });
      const specimen = (st) => {
        const c = st.colours;
        return `<span class="archer-style-swatch" style="background:${esc(c.base)};color:${esc(c.ink)}">` +
          `<span class="archer-style-aa" style="font-family:'${esc(st.headingFont)}',serif;font-weight:${st.headingWeight};letter-spacing:${esc(st.headingTracking)}">Aa</span>` +
          `<span class="archer-style-lines"><i style="background:${esc(c.ink)}"></i><i style="background:${esc(c.muted)}"></i></span>` +
          `<span class="archer-style-btn" style="background:${esc(c.accent)};color:${esc(c.accentInk)};border-radius:${Math.min(999, st.radius.button)}px">Knop</span>` +
          `<span class="archer-style-chips"><b style="background:${esc(c.surface)}"></b><b style="background:${esc(c.accent)}"></b><b style="background:${esc(c.dark)}"></b></span>` +
          `</span>`;
      };
      // Sectoradvies (UI/UX Pro Max): waarom deze stijlen bovenaan staan, wat zeker op de website moet en wat niet.
      function renderAdvice(a) {
        const el = $("[data-style-advice]", sbox);
        if (!el) return;
        if (!a) { el.innerHTML = ""; return; }
        const li = (xs) => (xs || []).map((x) => `<li>${esc(x)}</li>`).join("");
        el.innerHTML =
          `<details class="archer-sector-advice"><summary><span class="archer-sector-advice-label">Advies voor ${esc(a.label.toLowerCase())}</span>` +
          `<span class="archer-sector-advice-line">${esc(a.stijl)}</span></summary>` +
          '<dl class="archer-sector-advice-grid">' +
          `<dt>Kleur</dt><dd>${esc(a.kleur)}</dd><dt>Letters</dt><dd>${esc(a.letters)}</dd><dt>Opbouw</dt><dd>${esc(a.opbouw)}</dd>` +
          "</dl>" +
          `<div class="archer-sector-advice-cols"><div><h5>Moet op de website</h5><ul>${li(a.moet)}</ul></div><div><h5>Vermijden</h5><ul>${li(a.vermijd)}</ul></div></div>` +
          '<p class="archer-sector-advice-src">Op basis van UI/UX Pro Max. De stijlen die het best passen, staan eerst.</p></details>';
      }
      function renderStyles() {
        const cur = styleNow();
        $("[data-style-grid]", sbox).innerHTML = styles
          .map((st) => `<button type="button" class="archer-style-card${cur === st.id ? " is-active" : ""}" data-style-id="${esc(st.id)}" aria-pressed="${cur === st.id ? "true" : "false"}">` +
            specimen(st) +
            `<span class="archer-style-name">${esc(st.name)}${st.fit && styles.indexOf(st) < 3 ? ' <em>past bij deze zaak</em>' : ""}</span>` +
            `<span class="archer-style-pitch">${esc(st.pitch)}</span></button>`)
          .join("");
        // Het ontwerpsysteem van de gekozen stijl als DESIGN.md (voor Claude Design of een ontwikkelaar).
        const md = $("[data-style-md]", sbox);
        const act = styleById(cur);
        if (md) {
          md.hidden = !(act && act.designMd);
          md.innerHTML = act && act.designMd ? `Ontwerpsysteem van ${esc(act.name)}: <a href="${esc(act.designMd)}" target="_blank" rel="noopener" download>DESIGN.md</a>` : "";
        }
      }
      let userPicked = false;
      sbox.addEventListener("click", (e) => {
        const b = e.target.closest("[data-style-id]");
        if (!b) return;
        userPicked = true;
        const st = styleById(b.getAttribute("data-style-id"));
        (UI.ensureBrand ? UI.ensureBrand() : Promise.resolve()).then(() => applyStyle(st));
      });
      // Een nieuw project (nog geen stijlgids): de eerste stijl die bij de zaak past, toepassen.
      // Pas beslissen als de opgeslagen stijlgids geladen is; een keuze van de gebruiker gaat altijd voor.
      const guideEmpty = () => !(SG.extractedColours && SG.extractedColours.length) && !(SG.guide && !Array.isArray(SG.guide) && SG.guide.colours && SG.guide.colours.length) && !styleNow();
      UI.styleAuto = guideEmpty();
      (async () => {
        let out;
        try { out = await SG.post("aisb_archer_styles", { project_id: SG.projectId }); } catch (e) { out = null; }
        if (!out || !out.success) {
          $("[data-style-grid]", sbox).innerHTML = '<p class="archer-danger-text">De stijlen konden niet geladen worden.</p>';
          return;
        }
        styles = out.data.styles || [];
        renderAdvice(out.data.advice);
        const fams = Array.from(new Set(styles.map((x) => x.headingFont))).map((f) => "family=" + f.replace(/ /g, "+") + ":wght@400;500;600;700");
        styleFonts.href = "https://fonts.googleapis.com/css2?" + fams.join("&") + "&display=swap";
        renderStyles();
        (SG.allIframes || []).forEach((f) => f._loaded && injectStyle(f));
        setTimeout(() => {
          if (!userPicked && UI.styleAuto && guideEmpty() && styles[0]) (UI.ensureBrand ? UI.ensureBrand() : Promise.resolve()).then(() => applyStyle(styles[0], true));
          else if (UI.ensureBrand) UI.ensureBrand().then(renderBrand);
        }, 1800);
      })();
      let dealt = [];
      const fontLink = document.createElement("link");
      fontLink.rel = "stylesheet";
      document.head.appendChild(fontLink);
      const chips = (p) =>
        [p.bg, p.surface, p.ink, p.accent, (p.fields && p.fields[0] && p.fields[0].bg) || p.muted]
          .filter(Boolean)
          .map((c) => `<span style="background:${esc(c)}"></span>`)
          .join("");
      const render = () => {
        const cur = SG.guide && SG.guide.tasteDirection;
        $("[data-taste-list]", box).innerHTML = dealt
          .map((d, i) => {
            const on = cur && cur.id === d.id && cur.palette === d.palette.id && cur.type === d.type.id;
            return `<button type="button" class="archer-taste-card${on ? " is-active" : ""}" data-taste-idx="${i}" aria-pressed="${on ? "true" : "false"}">` +
              `<span class="archer-taste-chips" aria-hidden="true">${chips(d.palette)}</span>` +
              `<strong style="font-family:'${esc(d.headingFont)}',sans-serif">${esc(d.name)}</strong>` +
              `<span class="archer-taste-meta">${esc(d.colourNote)} · ${esc(d.typeNote || "")}: ${esc(d.headingFont)}${d.bodyFont !== d.headingFont ? " + " + esc(d.bodyFont) : ""}</span>` +
              `</button>`;
          })
          .join("");
      };
      const defaultScale = (h, b) => [
        { cls: "h1", label: "Titel (H1)", fontFamily: h, fontSize: "56px", lineHeight: "1.05", sample: "Een titel in twee regels" },
        { cls: "h2", label: "Sectietitel (H2)", fontFamily: h, fontSize: "40px", lineHeight: "1.1", sample: "Wat we doen" },
        { cls: "h3", label: "Tussentitel (H3)", fontFamily: h, fontSize: "26px", lineHeight: "1.2", sample: "Onderhoud en herstellingen" },
        { cls: "body", label: "Lopende tekst", fontFamily: b, fontSize: "18px", lineHeight: "1.55", sample: "Gewone tekst leest rustig, met genoeg ruimte tussen de regels." },
        { cls: "small", label: "Kleine tekst", fontFamily: b, fontSize: "14px", lineHeight: "1.5", sample: "Kleine tekst voor details." },
      ];
      const setColours = (colours) => {
        SG.extractedColours = colours.map((c) => ({ name: c.name, hex: c.hex }));
        if (SG.lockedColours) SG.lockedColours.clear();
        [SG.el.extractedSwatches, SG.el.manualSwatches].forEach((c) => c && SG.renderSwatchRow(c, SG.extractedColours));
        if (SG.el.extractedContainer) SG.el.extractedContainer.style.display = "";
      };
      const setFonts = (h, b) => {
        SG.guide.headingFont = h;
        SG.guide.bodyFont = b;
        SG.guide._aiHeadingFont = h;
        SG.guide._aiBodyFont = b;
        if (Array.isArray(SG.guide.typography) && SG.guide.typography.length) {
          SG.guide.typography.forEach((t) => (t.fontFamily = t.cls === "body" || t.cls === "small" ? b : h));
        } else {
          SG.guide.typography = defaultScale(h, b);
        }
        SG.fontsAssigned = true;
        if (SG.loadGoogleFonts) SG.loadGoogleFonts(h, b);
        if (SG.renderTypography) SG.renderTypography(SG.guide.typography);
        if (SG.el.typographyResult) SG.el.typographyResult.style.display = "";
        if (SG.el.typographyStatus) SG.el.typographyStatus.style.display = "none";
        if (SG.initFontPickers) SG.initFontPickers();
      };
      const apply = (d) => {
        if (Array.isArray(SG.guide)) SG.guide = {};
        setColours(d.colours);
        setFonts(d.headingFont, d.bodyFont);
        SG.guide.tasteDirection = { id: d.id, palette: d.palette.id, type: d.type.id, round };
        SG.applyOverridesToAllIframes();
        render();
      };
      const status = (t) => ($("[data-taste-status]", box).textContent = t || "");
      // Zoals Relume: alleen de kleuren of alleen de letters wisselen; het andere blijft staan.
      const shuffleOne = async (kind) => {
        if (Array.isArray(SG.guide)) SG.guide = {};
        const cur = SG.guide.tasteDirection || (dealt[0] ? { id: dealt[0].id, palette: "", type: "", round } : null);
        if (!cur) return;
        const seen = (SG.guide.tasteSeen = SG.guide.tasteSeen || { palette: [], type: [] });
        const exclude = Array.from(new Set([cur[kind]].concat(seen[kind] || []))).filter(Boolean).slice(-20);
        let out;
        try {
          out = await SG.post("aisb_archer_taste_shuffle", { project_id: SG.projectId, kind, direction: cur.id, exclude: JSON.stringify(exclude) });
        } catch (e) {
          out = null;
        }
        if (!out || !out.success) return status("Geen andere keuze gevonden.");
        const d = out.data;
        if (kind === "palette") {
          setColours(d.colours);
          cur.palette = d.palette.id;
          status("Kleuren: " + d.name + ". De letters bleven staan.");
        } else {
          setFonts(d.headingFont, d.bodyFont);
          cur.type = d.type.id;
          status("Letters: " + d.headingFont + (d.bodyFont !== d.headingFont ? " + " + d.bodyFont : "") + ". De kleuren bleven staan.");
        }
        seen[kind] = (seen[kind] || []).concat(cur[kind]).slice(-20);
        SG.guide.tasteDirection = cur;
        SG.applyOverridesToAllIframes();
        render();
      };
      const load = async (applyFirst) => {
        let out;
        try {
          out = await SG.post("aisb_archer_taste_deal", { project_id: SG.projectId, round });
        } catch (e) {
          out = null;
        }
        if (!out || !out.success) {
          $("[data-taste-list]", box).innerHTML = '<p class="archer-danger-text">De stijlrichtingen konden niet geladen worden.</p>';
          return;
        }
        dealt = out.data.directions || [];
        const fams = Array.from(new Set(dealt.map((d) => d.headingFont))).map((f) => "family=" + f.replace(/ /g, "+") + ":wght@400;700");
        if (fams.length) fontLink.href = "https://fonts.googleapis.com/css2?" + fams.join("&") + "&display=swap";
        render();
        if (applyFirst && dealt[0]) apply(dealt[0]);
      };
      UI.taste = {
        setColours,
        setFonts,
        render,
        surprise: async () => {
          round++;
          await load(false);
          if (dealt[0]) apply(dealt[0]);
        },
      };
      const radiusNow = () => (SG.guide && !Array.isArray(SG.guide) && SG.guide.radius) || "soft";
      const markRadius = () =>
        box.querySelectorAll("[data-radius]").forEach((b) => {
          const on = b.getAttribute("data-radius") === radiusNow();
          b.classList.toggle("is-active", on);
          b.setAttribute("aria-pressed", on ? "true" : "false");
        });
      markRadius();
      UI.markRadius = markRadius;
      box.addEventListener("click", (e) => {
        const rb = e.target.closest("[data-radius]");
        if (rb) {
          if (!SG.guide || Array.isArray(SG.guide)) SG.guide = {};
          SG.guide.radius = rb.getAttribute("data-radius");
          markRadius();
          (SG.allIframes || []).forEach((f) => {
            try { UI.applyRadius(f.contentDocument, SG.guide.radius); } catch (err) {}
            if (UI.injectStyle && f._loaded) UI.injectStyle(f);
          });
          SG.saveDraft && SG.saveDraft();
          return;
        }
        const card = e.target.closest("[data-taste-idx]");
        if (card) return apply(dealt[Number(card.getAttribute("data-taste-idx"))]);
        const sh = e.target.closest("[data-taste-shuffle]");
        if (sh) return shuffleOne(sh.getAttribute("data-taste-shuffle"));
        if (e.target.closest("[data-taste-more]")) {
          round++;
          load(false);
        }
      });
      // Nog geen kleuren: meteen de eerste richting toepassen zodra de stijlgids geladen is.
      setTimeout(() => {
        const empty = !(SG.extractedColours && SG.extractedColours.length) && !(SG.guide && SG.guide.colours && SG.guide.colours.length);
        load(empty && !UI.styleAuto && !(SG.guide && SG.guide.style));
      }, 1200);
    })();

    /* Kleuren uit een logo of foto: rollen op lichtheid, niet op volgorde van voorkomen.
       (De stijlgids noemt ze Primary, Secondary, Accent, Dark, Light, Neutral in die volgorde.) */
    if (window.ColorThief && ColorThief.prototype.getPalette && !ColorThief.prototype._archerRoles) {
      const origPalette = ColorThief.prototype.getPalette;
      ColorThief.prototype._archerRoles = true;
      ColorThief.prototype.getPalette = function () {
        const p = origPalette.apply(this, arguments);
        // Merkkleur uit dit logo of deze foto: een stijl houdt die kleur daarna (UI.brandColours).
        const G0 = window.AISB_StyleGuide;
        if (Array.isArray(p) && p.length && UI.brandFrom && G0 && G0.guide && !Array.isArray(G0.guide)) G0.guide.brand = UI.brandFrom(p);
        if (!Array.isArray(p) || p.length < 6) return p;
        const info = p.map((c) => {
          const [r, g, b] = c.map((v) => v / 255);
          const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
          const l = (mx + mn) / 2;
          const s = mx === mn ? 0 : (mx - mn) / (1 - Math.abs(2 * l - 1));
          return { c, l: lum([c[0], c[1], c[2], 1]), s };
        });
        const take = (arr, fn) => arr.splice(arr.indexOf(arr.reduce((a, b) => (fn(b, a) ? b : a))), 1)[0];
        const rest = info.slice();
        const dark = take(rest, (a, b) => a.l < b.l);
        const light = take(rest, (a, b) => a.l > b.l);
        const primary = take(rest, (a, b) => a.s > b.s);
        const neutral = take(rest, (a, b) => a.s < b.s);
        rest.sort((a, b) => b.s - a.s);
        const out = [primary, rest[0], rest[1], dark, light, neutral].concat(rest.slice(2)).map((x) => x.c);
        // Lichte gronden: een foto levert vaak een middentint als "Light"; die wordt een lichte tint.
        const g = window.AISB_StyleGuide && window.AISB_StyleGuide.guide;
        if (UI.lightenGrounds && !(g && g.darkMode)) {
          const names = ["Primary", "Secondary", "Accent", "Dark", "Light", "Neutral"];
          const cols = out.slice(0, 6).map((c, i) => ({ name: names[i], hex: "#" + c.slice(0, 3).map((v) => v.toString(16).padStart(2, "0")).join("") }));
          UI.lightenGrounds(cols);
          cols.forEach((c, i) => { const h = c.hex.slice(1); out[i] = [0, 2, 4].map((k) => parseInt(h.slice(k, k + 2), 16)); });
        }
        return out;
      };
    }

    /* Merkkleuren. Een stijl bracht tot nu toe zijn eigen kleuren mee en verving zo de kleuren van het logo
       (Stinro: oranje logo, blauwe Fris-knoppen). Nu: de merkkleur wordt de accentkleur, de donkere kleur van het
       logo de donkere band; de gronden en tekstkleur van de stijl houden hun lichtheid maar krijgen de tint van het merk.
       Grijze stijlkleuren blijven grijs (Atelier, Raster). */
    {
      const hx = (c) => "#" + c.slice(0, 3).map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, "0")).join("");
      const rgbOf = (h) => { const n = parseInt(h.slice(1), 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };
      const toHsl = (h) => {
        const [r, g, b] = rgbOf(h).map((v) => v / 255);
        const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2;
        let s = 0, hh = 0;
        if (mx !== mn) {
          const d = mx - mn;
          s = d / (1 - Math.abs(2 * l - 1));
          hh = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
          hh *= 60;
          if (hh < 0) hh += 360;
        }
        return { h: hh, s, l };
      };
      const fromHsl = ({ h, s, l }) => {
        const c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = l - c / 2;
        const [r, g, b] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
        return hx([(r + m) * 255, (g + m) * 255, (b + m) * 255]);
      };
      /** Uit de kleuren van ColorThief: de meest verzadigde middentint (merkkleur) en de donkerste (tekst, band). */
      UI.brandFrom = (pal) => {
        const info = pal.filter((c) => Array.isArray(c) && c.length >= 3).map((c) => ({ hex: hx(c), ...toHsl(hx(c)) }));
        const vivid = info.filter((x) => x.s >= 0.25 && x.l > 0.18 && x.l < 0.82).sort((a, b) => b.s * (1 - Math.abs(2 * b.l - 1)) - a.s * (1 - Math.abs(2 * a.l - 1)));
        const darks = info.filter((x) => x.l < 0.3).sort((a, b) => a.l - b.l);
        return { primary: vivid[0] ? vivid[0].hex : "", dark: darks[0] ? darks[0].hex : "", source: "image" };
      };
      /** De kleuren van een stijl met het merk erin. Zonder merkkleur: ongewijzigd. */
      UI.brandColours = (cols) => {
        const G = window.AISB_StyleGuide;
        const b = G && G.guide && !Array.isArray(G.guide) ? G.guide.brand : null;
        if (!b || !/^#[0-9a-f]{6}$/i.test(b.primary || "") || !Array.isArray(cols)) return cols;
        const bh = toHsl(b.primary).h;
        const dark = !!(G.guide && G.guide.darkMode);
        return cols.map((c) => {
          if (!c || !/^#[0-9a-f]{6}$/i.test(c.hex || "")) return c;
          if (c.name === "Primary" || c.name === "Accent") return { name: c.name, hex: b.primary };
          if (c.name === "Secondary" && !dark && /^#[0-9a-f]{6}$/i.test(b.dark || "")) return { name: c.name, hex: b.dark };
          const t = toHsl(c.hex);
          if (t.s < 0.06) return { name: c.name, hex: c.hex };
          // Tekstkleur bijna neutraal (anders bruine of groene tekst), gronden zacht getint.
          return { name: c.name, hex: fromHsl({ h: bh, s: Math.min(t.s, c.name === "Dark" ? 0.12 : 0.22), l: t.l }) };
        });
      };
      /** Nog geen merkkleur, wel een logo in de header: de merkkleur daaruit halen. */
      UI.ensureBrand = () => new Promise((res) => {
        const G = window.AISB_StyleGuide;
        const g = G && G.guide;
        if (!g || Array.isArray(g) || g.brand || !g.logoUrl || !window.ColorThief) return res();
        const img = new Image();
        img.crossOrigin = "anonymous";
        const done = () => { clearTimeout(t); res(); };
        const t = setTimeout(done, 3000);
        img.onload = () => { try { new ColorThief().getPalette(img, 6); if (g.brand) g.brand.source = "logo"; } catch (e) {} done(); };
        img.onerror = done;
        img.src = g.logoUrl;
      });
      // Zelf een kleur kiezen: die kleur is vanaf nu het merk.
      document.addEventListener("input", (e) => {
        const G = window.AISB_StyleGuide;
        const el = G && G.el && e.target;
        if (!el || (el !== G.el.primaryPicker && el !== G.el.primaryHex)) return;
        const v = String(el.value || "");
        if (/^#[0-9a-f]{6}$/i.test(v) && G.guide && !Array.isArray(G.guide)) G.guide.brand = { primary: v, dark: "", source: "manual" };
      }, true);
    }

    /* Altijd genoeg contrast: tekst t.o.v. de achtergrond (WCAG AA) en knoppen t.o.v. de sectie. */
    const parseCol = (s) => {
      const m = /rgba?\(([^)]+)\)/.exec(s || "");
      if (!m) return null;
      const p = m[1].split(/[\s,/]+/).filter(Boolean).map(Number);
      return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
    };
    const hexCol = (h) => {
      const m = /^#?([0-9a-f]{6})$/i.exec((h || "").trim());
      if (!m) return null;
      const n = parseInt(m[1], 16);
      return [(n >> 16) & 255, (n >> 8) & 255, n & 255, 1];
    };
    const over = (top, under) => {
      const a = top[3];
      return [0, 1, 2].map((i) => top[i] * a + under[i] * (1 - a)).concat(1);
    };
    const lum = (c) => {
      const f = (v) => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      };
      return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
    };
    const ratio = (a, b) => {
      const x = lum(a), y = lum(b);
      return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
    };
    const css = (c) => `rgb(${c.slice(0, 3).map(Math.round).join(",")})`;
    /** Achtergrond achter een element; null als er een afbeelding achter zit (niet te meten). */
    // Een foto of video die de hele sectie vult (absoluut, achter de tekst): daar beslist het ontwerp (overlay), niet de kleur.
    const coverCache = new WeakMap();
    const hasCoverMedia = (x, win) => {
      if (coverCache.has(x)) return coverCache.get(x);
      let yes = false;
      const r = x.getBoundingClientRect();
      if (r.width > 0 && r.height > 0) {
        for (const ch of x.children) {
          if (!/^(IMG|FIGURE|PICTURE|VIDEO)$/.test(ch.tagName) && !ch.querySelector(":scope > img, :scope > video")) continue;
          if (win.getComputedStyle(ch).position !== "absolute") continue;
          const c = ch.getBoundingClientRect();
          if (c.width * c.height >= r.width * r.height * 0.8) { yes = true; break; }
        }
      }
      if (yes) coverCache.set(x, yes); // nee niet onthouden: de foto kan nog aan het laden zijn
      return yes;
    };
    const bgOf = (el, win) => {
      if (el.closest && el.closest(".archer-cover")) return null;
      const layers = [];
      for (let x = el; x && x.nodeType === 1; x = x.parentElement) {
        const cs = win.getComputedStyle(x);
        if (cs.backgroundImage && cs.backgroundImage !== "none" && !/gradient/.test(cs.backgroundImage)) return null;
        if (hasCoverMedia(x, win)) return null;
        const c = parseCol(cs.backgroundColor);
        if (c && c[3] > 0.02) {
          layers.push(c);
          if (c[3] >= 0.99) break;
        }
      }
      return layers.reduceRight((acc, c) => over(c, acc), [255, 255, 255, 1]);
    };
    const paletteCandidates = () => {
      const pal = (SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : (SG.guide && SG.guide.colours) || []);
      const by = (n) => (pal.find((c) => c.name === n) || {}).hex;
      return [by("Dark"), by("Primary") || (pal[0] && pal[0].hex), by("Light"), "#111111", "#ffffff", "#000000"].map(hexCol).filter(Boolean);
    };
    const pickColour = (bg, need, candidates) => {
      const ok = candidates.find((c) => ratio(c, bg) >= need);
      return ok || candidates.slice().sort((a, b) => ratio(b, bg) - ratio(a, bg))[0];
    };
    const TEXT_SEL = "h1,h2,h3,h4,h5,h6,p,span,a,li,label,blockquote,figcaption,strong,em,button,.brxe-heading,.brxe-text-basic,.brxe-text,.bricks-button";
    function fixContrast(iframe) {
      let doc, win;
      try {
        doc = iframe.contentDocument;
        win = iframe.contentWindow;
      } catch (e) {
        return;
      }
      const root = doc && doc.getElementById("aisb-preview");
      if (!root || !win) return;
      // Geen overgangen in het voorbeeld: anders meten we kleuren halverwege een animatie.
      if (!doc.getElementById("archer-notrans")) {
        const nt = doc.createElement("style");
        nt.id = "archer-notrans";
        nt.textContent = "*,*::before,*::after{transition:none !important;}";
        doc.head.appendChild(nt);
      }
      let style = doc.getElementById("archer-contrast");
      if (!style) {
        style = doc.createElement("style");
        style.id = "archer-contrast";
        doc.head.appendChild(style);
      }
      style.textContent = "";
      root.querySelectorAll("[data-archer-c]").forEach((x) => x.removeAttribute("data-archer-c"));
      const cand = paletteCandidates();
      let n = 0;
      const rules = [];
      const marked = new Map(); // element => index in rules (één regel per element)
      const mark = (el, decl) => {
        if (marked.has(el)) {
          const i = marked.get(el);
          rules[i] = rules[i].replace(/}$/, decl + "}");
          return;
        }
        el.setAttribute("data-archer-c", String(++n));
        marked.set(el, rules.length);
        rules.push(`html [data-archer-c="${n}"][data-archer-c]{${decl}}`);
      };
      // 1. Knoppen die wegvallen tegen de sectie (bv. hoofdkleur op een sectie in dezelfde kleur).
      root.querySelectorAll(".bricks-button,.brxe-button").forEach((b) => {
        if (!b.getClientRects().length) return;
        const own = parseCol(win.getComputedStyle(b).backgroundColor);
        const behind = bgOf(b.parentElement, win);
        if (!own || own[3] < 0.5 || !behind) return; // outline-knoppen: de tekstcontrole hieronder
        const fill = over(own, behind);
        if (ratio(fill, behind) >= 1.6) return;
        const nb = pickColour(behind, 3, cand);
        const txt = pickColour(nb, 4.5, cand.slice().reverse());
        mark(b, `background-color:${css(nb)} !important;border-color:${css(nb)} !important;color:${css(txt)} !important;`);
      });
      style.textContent = rules.join("");
      // 2. Elke tekst tegen haar echte achtergrond.
      const fixes = [];
      root.querySelectorAll(TEXT_SEL).forEach((el) => {
        if (!el.getClientRects().length) return;
        const hasText = Array.from(el.childNodes).some((c) => c.nodeType === 3 && c.textContent.trim());
        if (!hasText) return;
        const cs = win.getComputedStyle(el);
        if (cs.visibility === "hidden" || Number(cs.opacity) === 0) return;
        const bg = bgOf(el, win);
        if (!bg) return;
        const fgRaw = parseCol(cs.color);
        if (!fgRaw) return;
        const fg = over(fgRaw, bg);
        const size = parseFloat(cs.fontSize) || 16;
        const large = size >= 24 || (size >= 18.6 && Number(cs.fontWeight) >= 700);
        const need = large ? 3 : 4.5;
        if (ratio(fg, bg) >= need) {
          // impeccable: grijze tekst op een gekleurde achtergrond wordt getint in die kleur.
          if (!el.matches("h1,h2,h3,h4,h5,h6,.brxe-heading,.bricks-button,.brxe-button,a") && hslOf(bg).s > 0.3 && lum(bg) < 0.9 && hslOf(fg).s < 0.08) {
            const t = tintedFor(bg).find((c) => ratio(c, bg) >= need);
            if (t) fixes.push([el, t, false]);
          }
          return;
        }
        fixes.push([el, pickColour(bg, need, cand), el.matches(".bricks-button,.brxe-button")]);
      });
      fixes.forEach(([el, c, isBtn]) => mark(el, `color:${css(c)} !important;` + (isBtn ? `border-color:${css(c)} !important;` : "")));
      style.textContent = rules.join("");
    }
    /* Kleurschema per sectie (zoals Relume): klik op een sectie om de achtergrond te wisselen. */
    const SCHEMES = [
      ["ground", "Grond", "Light"],
      ["tone", "Toon", "Neutral"],
      ["field", "Vlak", "Secondary"],
      ["accent", "Accent", "Primary"],
      ["ink", "Inkt", "Dark"],
    ];
    const palHex = (role) => {
      const pal = SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : (SG.guide && SG.guide.colours) || [];
      return ((pal.find((c) => c.name === role) || {}).hex || "").toLowerCase();
    };
    const schemeList = () => {
      const seen = new Set();
      return SCHEMES.map(([id, name, role]) => ({ id, name, hex: palHex(role) })).filter((x) => /^#[0-9a-f]{6}$/.test(x.hex) && !seen.has(x.hex) && seen.add(x.hex));
    };
    const hsl2rgb = (h, s, l) => {
      const k = (n) => (n + h / 30) % 12;
      const a = s * Math.min(l, 1 - l);
      const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
      return [f(0) * 255, f(8) * 255, f(4) * 255, 1];
    };
    const hslOf = (c) => {
      const [r, g, b] = c.slice(0, 3).map((v) => v / 255);
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
      const l = (mx + mn) / 2;
      let h = 0, s = 0;
      if (mx !== mn) {
        const d = mx - mn;
        s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
        h = mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
        h *= 60;
      }
      return { h, s, l };
    };
    /** impeccable: op een gekleurde achtergrond is tekst getint in die kleur, nooit grijs. */
    const tintedFor = (bg) => {
      const { h, s } = hslOf(bg);
      const sat = Math.min(0.45, Math.max(0.18, s * 0.6));
      return lum(bg) > 0.4 ? [hsl2rgb(h, sat, 0.14), hsl2rgb(h, sat, 0.2)] : [hsl2rgb(h, sat, 0.95), hsl2rgb(h, sat, 0.9)];
    };
    const sectionInfo = (iframe) => {
      const page = (SG.wireframePages || []).find((p) => p.slug === iframe._pageSlug);
      const sec = page && page.sections ? page.sections[iframe._sectionIdx] : null;
      const type = String(iframe._sectionType || (sec && sec.type) || "").toLowerCase();
      return { slug: iframe._pageSlug, uuid: sec && sec.uuid, global: /^(header|footer)$/.test(type) ? type : null };
    };
    const schemeStore = () => {
      if (Array.isArray(SG.guide)) SG.guide = {};
      const st = SG.guide.sectionSchemes && typeof SG.guide.sectionSchemes === "object" && !Array.isArray(SG.guide.sectionSchemes) ? SG.guide.sectionSchemes : {};
      if (!st.global || Array.isArray(st.global)) st.global = {};
      if (!st.pages || Array.isArray(st.pages)) st.pages = {};
      SG.guide.sectionSchemes = st;
      return st;
    };
    const getScheme = (iframe) => {
      const i = sectionInfo(iframe);
      const st = schemeStore();
      if (i.global) return st.global[i.global] || "";
      return (i.uuid && st.pages[i.slug] && st.pages[i.slug][i.uuid]) || "";
    };
    const setScheme = (iframe, id) => {
      const i = sectionInfo(iframe);
      const st = schemeStore();
      if (i.global) {
        if (id) st.global[i.global] = id;
        else delete st.global[i.global];
        return;
      }
      if (!i.uuid) return;
      st.pages[i.slug] = st.pages[i.slug] || {};
      if (id) st.pages[i.slug][i.uuid] = id;
      else delete st.pages[i.slug][i.uuid];
    };
    function applyScheme(iframe) {
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (e) {
        return;
      }
      if (!doc || !doc.head) return;
      let st = doc.getElementById("archer-scheme");
      if (!st) {
        st = doc.createElement("style");
        st.id = "archer-scheme";
      }
      doc.head.appendChild(st); // altijd na de stijl van de stijlgids
      UI.applyRadius(doc, SG.guide && !Array.isArray(SG.guide) ? SG.guide.radius : "");
      if (UI.markRadius) UI.markRadius(); // de opgeslagen stijlgids komt pas na het opbouwen van de knoppen binnen
      renderSchemeBar(iframe);
      const id = getScheme(iframe);
      const s = id && schemeList().find((x) => x.id === id);
      if (!s) {
        st.textContent = "";
        return;
      }
      const bg = hexCol(s.hex);
      const cand = paletteCandidates();
      const head = pickColour(bg, 4.5, cand);
      const body = pickColour(bg, 4.5, hslOf(bg).s > 0.25 ? tintedFor(bg).concat(cand) : cand);
      const sel = ".brxe-section:not([style*='background-image']),.brxe-container:not([style*='background-image']),.brxe-block:not([style*='background-image'])";
      st.textContent =
        `${sel}{background-color:${s.hex} !important;}body{background-color:${s.hex} !important;}` +
        `h1,h2,h3,h4,h5,h6,.brxe-heading{color:${css(head)} !important;}` +
        `body,p,.brxe-text,.brxe-text-basic,.brxe-rich-text,li{color:${css(body)} !important;}` +
        bandButtons(bg, head, s.hex);
    }
    /** Knoppen op een band: omlijnd in de leeskleur; een knop in de kleur van de band wordt omgekeerd. */
    function bandButtons(bg, head, hex) {
      const g = SG.guide && !Array.isArray(SG.guide) ? SG.guide : {};
      const p = (g.colours || []).find((c) => c && c.name === "Primary");
      const prim = p && hexCol(p.hex);
      const btn = 'html body .brxe-button.bricks-button:not(#archer-x):not(.archer-header *):not(.archer-footer *)';
      let out = `${btn}:is(.outline,[class*="outline"],.brxe-button ~ .brxe-button){background-color:transparent !important;color:${css(head)} !important;border-color:${css(head)} !important;}`;
      if (prim && ratio(prim, bg) < 3) {
        out += `${btn}:not(.outline):not([class*="outline"]):not(.brxe-button ~ .brxe-button){background-color:${css(head)} !important;border-color:${css(head)} !important;color:${hex} !important;}`;
      }
      return out;
    }
    const schemeView = view !== "photos";
    function hookSchemeClicks(iframe) {
      if (!schemeView) return;
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (e) {
        return;
      }
      if (!doc || !doc.body || doc._archerSchemeClick) return;
      doc._archerSchemeClick = true;
      const hover = doc.createElement("style");
      hover.textContent = ".brxe-heading:hover,.brxe-text-basic:hover,.brxe-text:hover,.brxe-button:hover,.brxe-text-link:hover{outline:1px dashed #14b8a6;outline-offset:4px;cursor:text}";
      doc.head.appendChild(hover);
      // Zoals Relume: bij hover de kleurschema's als stalen bovenaan de sectie.
      const barCss = doc.createElement("style");
      barCss.textContent =
        "#archer-scheme-bar{position:fixed;top:12px;right:12px;z-index:99999;display:none;gap:8px;align-items:center;padding:8px 10px;background:#fff !important;border:1px solid #d7dbe6;border-radius:999px;font:600 18px/1 system-ui,sans-serif !important;color:#0b1033 !important}" +
        "html:hover #archer-scheme-bar{display:flex}" +
        "#archer-scheme-bar button{all:unset;cursor:pointer;width:40px;height:40px;border-radius:50%;border:2px solid #fff;outline:1px solid #c8ccd8;box-sizing:border-box}" +
        "#archer-scheme-bar button.is-on{outline:3px solid #14b8a6}" +
        "#archer-scheme-bar button[data-id='']{width:auto;height:40px;border-radius:999px;padding:0 14px;background:#f1f3f8 !important;color:#0b1033 !important;font:600 16px/40px system-ui,sans-serif !important}";
      doc.head.appendChild(barCss);
      const bar = doc.createElement("div");
      bar.id = "archer-scheme-bar";
      bar.setAttribute("role", "toolbar");
      bar.setAttribute("aria-label", "Kleurschema");
      doc.body.appendChild(bar);
      bar.addEventListener("click", (e) => {
        const b = e.target.closest("button");
        e.preventDefault();
        e.stopPropagation();
        if (!b) return;
        setScheme(iframe, b.getAttribute("data-id"));
        reinjectFor(iframe);
        saveGuide();
        schemeStatus(iframe);
      });
      // Klikken: teksten rechtstreeks aanpassen (kleurschema's via de balk rechtsboven).
      doc.addEventListener("click", (e) => {
        if (e.target.closest && e.target.closest("#archer-scheme-bar")) return;
        e.preventDefault();
        e.stopPropagation();
        startTextEdit(iframe, e.target);
      }, true);      renderSchemeBar(iframe);
    }
    /* Teksten aanpassen in het voorbeeld (Stijl): klik, typ, klik ernaast of Enter om te bewaren. */
    const EDIT_SEL = ".brxe-heading, .brxe-text-basic, .brxe-text, .brxe-button, .brxe-text-link";
    const editable = (iframe) => {
      const i = sectionInfo(iframe);
      const page = (SG.wireframePages || []).find((p) => p.slug === iframe._pageSlug);
      const sec = page && page.sections ? page.sections[iframe._sectionIdx] : null;
      const id = Number(String(iframe.src || "").split("aisb_bricks_preview=").pop().split("&")[0]) || 0;
      const ai = sec && Number(sec.ai_wireframe_id || 0);
      return id && ai && ai === id ? { postId: id, slug: i.slug, global: i.global } : null;
    };
    function startTextEdit(iframe, target) {
      const doc = iframe.contentDocument;
      const el = target && target.closest && target.closest(EDIT_SEL);
      if (!el || el.closest(".archer-credit") || !/^brxe-[a-z0-9]+$/i.test(el.id || "") || el.isContentEditable) return;
      const info = editable(iframe);
      const status = $("[data-scheme-status]", panel);
      if (!info) {
        if (status) status.textContent = "De header pas je aan in de sitemap (het menu), niet hier.";
        return;
      }
      const name = (el.className.match(/brxe-([a-z-]+)/) || [])[1] || "text";
      const single = name === "heading" || name === "button" || name === "text-link";
      // Bij knoppen en links: alleen de tekst, niet een icoon.
      const box = name === "button" || name === "text-link" ? el.querySelector("span:not(:empty)") || el : el;
      const before = box.innerHTML;
      box.setAttribute("contenteditable", single ? "plaintext-only" : "true");
      box.classList.add("archer-editing");
      if (!doc.getElementById("archer-edit-css")) {
        const st = doc.createElement("style");
        st.id = "archer-edit-css";
        st.textContent = ".archer-editing{outline:2px dashed #14b8a6 !important;outline-offset:4px;cursor:text !important}" +
          `${EDIT_SEL}{cursor:text}`;
        doc.head.appendChild(st);
      }
      box.focus();
      const done = async (save) => {
        box.removeEventListener("blur", onBlur);
        box.removeEventListener("keydown", onKey);
        box.removeAttribute("contenteditable");
        box.classList.remove("archer-editing");
        if (!save) { box.innerHTML = before; return; }
        const value = single ? box.textContent.trim() : box.innerHTML.trim();
        if (value === before.trim() || !value) { if (!value) box.innerHTML = before; return; }
        if (status) status.textContent = "Tekst bewaren…";
        // Footer: dezelfde tekst op de andere pagina's.
        const sync = [];
        if (info.global === "footer") {
          (SG.wireframePages || []).forEach((p) => (p.sections || []).forEach((x) => {
            if (String(x.type || "").toLowerCase() === "footer" && x.ai_wireframe_id && Number(x.ai_wireframe_id) !== info.postId) sync.push({ page_slug: p.slug, post_id: Number(x.ai_wireframe_id) });
          }));
        }
        try {
          const out = await SG.post("aisb_archer_section_edit", {
            project_id: SG.projectId,
            page_slug: info.slug,
            post_id: info.postId,
            ops: JSON.stringify([{ op: "text", id: el.id.slice(5), key: "text", value }]),
            sync: JSON.stringify(sync),
          });
          if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "Bewaren mislukt");
          if (info.global) {
            (SG.allIframes || []).forEach((f) => {
              if (f === iframe || sectionInfo(f).global !== info.global) return;
              try {
                const twin = f.contentDocument.getElementById(el.id);
                if (twin) (twin.querySelector("span:not(:empty)") && box !== el ? twin.querySelector("span:not(:empty)") : twin).innerHTML = box.innerHTML;
              } catch (err) {}
            });
          }
          if (status) status.textContent = "Tekst bewaard.";
        } catch (err) {
          box.innerHTML = before;
          if (status) status.textContent = err.message || "Bewaren mislukt.";
        }
      };
      const onBlur = () => done(true);
      const onKey = (ev) => {
        if (ev.key === "Escape") { ev.preventDefault(); done(false); }
        else if (ev.key === "Enter" && (single || !ev.shiftKey) && single) { ev.preventDefault(); box.blur(); }
      };
      box.addEventListener("blur", onBlur);
      box.addEventListener("keydown", onKey);
    }

    function renderSchemeBar(iframe) {
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (e) {
        return;
      }
      const bar = doc && doc.getElementById("archer-scheme-bar");
      if (!bar) return;
      const cur = getScheme(iframe);
      bar.innerHTML =
        schemeList().map((x) => `<button type="button" data-id="${x.id}" title="${x.name}" aria-label="${x.name}" class="${x.id === cur ? "is-on" : ""}" style="background:${x.hex} !important"></button>`).join("") +
        `<button type="button" data-id="" class="${cur ? "" : "is-on"}">Standaard</button>`;
    }
    function schemeStatus(iframe) {
      const id = getScheme(iframe);
      const nm = (schemeList().find((x) => x.id === id) || {}).name;
      const st = $("[data-scheme-status]", panel);
      if (st) st.textContent = id ? "Kleurschema: " + nm + "." : "Kleurschema: standaard.";
    }
    const reinjectFor = (iframe) => {
      const i = sectionInfo(iframe);
      (SG.allIframes || []).forEach((f) => {
        if (!f._loaded) return;
        const j = sectionInfo(f);
        if (f === iframe || (i.global && j.global === i.global)) SG.injectOverrideIntoIframe(f);
      });
    };
    const saveGuide = () => SG.saveDraft && SG.saveDraft();
    function cycleScheme(iframe) {
      const list = schemeList();
      if (!list.length) return;
      const cur = getScheme(iframe);
      const ids = list.map((x) => x.id).concat([""]);
      const next = ids[(ids.indexOf(cur) + 1) % ids.length];
      setScheme(iframe, next);
      reinjectFor(iframe);
      saveGuide();
      schemeStatus(iframe);
    }
    /** Alle secties van de getoonde pagina een schema geven (spatiebalk of knop), zonder twee keer hetzelfde na elkaar. */
    function shuffleSchemes() {
      const list = schemeList();
      if (!list.length) return;
      const slug = pick.value;
      const frames = (SG.allIframes || []).filter((f) => f._loaded && (slug === "__all" || f._pageSlug === slug)).sort((a, b) => (a._pageSlug === b._pageSlug ? a._sectionIdx - b._sectionIdx : 0));
      // Vooral lichte vlakken (grond en toon om beurten); af en toe één sterk vlak, nooit twee na elkaar.
      const light = list.filter((x) => lum(hexCol(x.hex)) >= 0.7).map((x) => x.id);
      const strong = list.filter((x) => lum(hexCol(x.hex)) < 0.7 && x.id !== "ink").map((x) => x.id);
      let prev = "";
      let sinceStrong = 0;
      let n = 0;
      frames.forEach((f) => {
        const i = sectionInfo(f);
        if (i.global === "header") {
          prev = getScheme(f) || "ground";
          return;
        }
        let id;
        if (i.global === "footer") {
          // De footer sluit de pagina af: het donkerste schema.
          const dark = list.slice().sort((a, b) => lum(hexCol(a.hex)) - lum(hexCol(b.hex)))[0];
          id = dark ? dark.id : "";
        } else if (n > 0 && strong.length && sinceStrong >= 2 && Math.random() < 0.35) {
          id = strong[Math.floor(Math.random() * strong.length)];
          sinceStrong = 0;
        } else {
          const opts = light.filter((x) => x !== prev);
          id = (opts.length ? opts : light)[n % Math.max(1, (opts.length ? opts : light).length)] || "";
          sinceStrong++;
        }
        n++;
        setScheme(f, id);
        prev = id;
      });
      frames.forEach((f) => SG.injectOverrideIntoIframe(f));
      saveGuide();
      const st = $("[data-scheme-status]", panel);
      if (st) st.textContent = "Nieuwe kleurschema's. Klik op een sectie om er één te wisselen.";
    }
    function resetSchemes() {
      const st = schemeStore();
      const slug = pick.value;
      if (slug === "__all") {
        st.pages = {};
        st.global = {};
      } else {
        delete st.pages[slug];
      }
      (SG.allIframes || []).forEach((f) => f._loaded && SG.injectOverrideIntoIframe(f));
      saveGuide();
      const s2 = $("[data-scheme-status]", panel);
      if (s2) s2.textContent = "Standaard kleurschema's.";
    }
    if (schemeView) {
      const colourPanel = $("[data-wizard-panel='1']", panel);
      const tasteBox = $(".archer-taste", panel);
      const sbox = document.createElement("section");
      sbox.className = "archer-scheme-box";
      sbox.setAttribute("aria-label", "Kleurschema per sectie");
      sbox.innerHTML =
        '<div class="archer-taste-head"><h4>Kleurschema per sectie</h4></div>' +
        '<p class="archer-taste-intro">Klik in het voorbeeld op een sectie om haar achtergrond te wisselen: grond, toon, vlak, accent of inkt. Met de spatiebalk boven het voorbeeld schud je de hele pagina.</p>' +
        '<div class="archer-taste-tools"><button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-scheme-shuffle>Schud deze pagina</button>' +
        '<button type="button" class="archer-link-btn" data-scheme-reset>Standaard</button></div>' +
        '<p class="archer-taste-status" data-scheme-status aria-live="polite"></p>';
      if (tasteBox) tasteBox.after(sbox);
      else if (colourPanel) colourPanel.prepend(sbox);
      sbox.addEventListener("click", (e) => {
        if (e.target.closest("[data-scheme-shuffle]")) shuffleSchemes();
        if (e.target.closest("[data-scheme-reset]")) resetSchemes();
      });
      document.addEventListener("keydown", (e) => {
        if (e.code !== "Space" || e.target.closest && e.target.closest("input, textarea, select, button, [contenteditable]")) return;
        const c = $(".aisb-sg-canvas", panel);
        if (!c || !c.matches(":hover")) return;
        e.preventDefault();
        shuffleSchemes();
      });
    }
    UI.sectionSchemes = { cycle: cycleScheme, shuffle: shuffleSchemes, reset: resetSchemes };

    /* Lichte achtergronden: "Light" en "Neutral" zijn de grond van de secties en moeten echt licht zijn. */
    const toHex = (c) => "#" + c.slice(0, 3).map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, "0")).join("");
    const groundOf = (hex, L) => {
      const c = hexCol(hex);
      if (!c) return hex;
      const { h, s } = hslOf(c);
      return toHex(hsl2rgb(h, Math.min(s, 0.3), L));
    };
    function lightenGrounds(colours) {
      if (!Array.isArray(colours)) return false;
      const get = (n) => colours.find((c) => c && c.name === n);
      const primary = get("Primary") || get("Accent");
      const base = (primary && primary.hex) || "#808080";
      let changed = false;
      const lightC = get("Light");
      if (lightC && hexCol(lightC.hex) && lum(hexCol(lightC.hex)) < 0.75) {
        lightC.hex = groundOf(lightC.hex && hslOf(hexCol(lightC.hex)).s > 0.05 ? lightC.hex : base, 0.965);
        changed = true;
      }
      const neutralC = get("Neutral");
      if (neutralC && hexCol(neutralC.hex) && lum(hexCol(neutralC.hex)) < 0.6) {
        neutralC.hex = groundOf(lightC && hexCol(lightC.hex) ? lightC.hex : base, 0.925);
        changed = true;
      }
      const darkC = get("Dark");
      if (darkC && hexCol(darkC.hex) && lum(hexCol(darkC.hex)) > 0.2) {
        const c = hexCol(darkC.hex);
        darkC.hex = toHex(hsl2rgb(hslOf(c).h, Math.min(hslOf(c).s, 0.5), 0.16));
        changed = true;
      }
      return changed;
    }
    UI.lightenGrounds = lightenGrounds;
    // Zelf kiezen: de harmonie van de stijlgids maakt "Light" vaak te donker.
    if (typeof SG.renderSwatchRow === "function") {
      const prevRender = SG.renderSwatchRow;
      SG.renderSwatchRow = function (container, colours) {
        if (container && container === SG.el.manualSwatches && Array.isArray(colours) && colours !== SG.extractedColours) lightenGrounds(colours);
        return prevRender.apply(this, arguments);
      };
    }
    // Bestaande projecten één keer omzetten naar lichte gronden.
    setTimeout(() => {
      if (Array.isArray(SG.guide) || !SG.guide || SG.guide.groundsLight || SG.guide.darkMode) return;
      const cols = SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : SG.guide.colours;
      if (!cols || !cols.length) return;
      SG.guide.groundsLight = 1;
      if (lightenGrounds(cols)) {
        SG.extractedColours = cols;
        [SG.el.extractedSwatches, SG.el.manualSwatches].forEach((c) => c && SG.renderSwatchRow(c, SG.extractedColours));
        SG.applyOverridesToAllIframes();
      } else if (SG.saveDraft) SG.saveDraft();
    }, 2500);

    /* Bovenaan het voorbeeld, zoals Relume: Verras me, licht/donker en concepten. */
    if (schemeView && header) {
      const bar = document.createElement("div");
      bar.className = "archer-sg-actions";
      header.appendChild(bar);
      const renderBar = () => {
        const g = Array.isArray(SG.guide) ? {} : SG.guide || {};
        const concepts = Array.isArray(g.concepts) ? g.concepts : [];
        bar.innerHTML =
          '<div class="archer-sg-concepts" role="group" aria-label="Concepten">' +
          concepts.map((c, i) => `<span class="archer-sg-concept${g.activeConcept === i ? " is-active" : ""}"><button type="button" data-concept="${i}">${esc(c.name)}</button><button type="button" class="archer-sg-concept-x" data-concept-del="${i}" aria-label="${esc(c.name)} verwijderen">✕</button></span>`).join("") +
          (concepts.length < 3 ? '<button type="button" class="archer-link-btn" data-concept-save>+ Bewaar als concept</button>' : "") +
          "</div>" +
          `<button type="button" class="archer-btn archer-btn-outline archer-btn-sm" data-sg-dark aria-pressed="${g.darkMode ? "true" : "false"}">${g.darkMode ? "Licht" : "Donker"}</button>` +
          '<button type="button" class="archer-btn archer-btn-primary archer-btn-sm" data-sg-surprise>Verras me</button>';
      };
      const snapshot = (name) => ({
        name,
        colours: (SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : SG.guide.colours || []).map((c) => ({ name: c.name, hex: c.hex })),
        headingFont: SG.guide.headingFont || "",
        bodyFont: SG.guide.bodyFont || "",
        tasteDirection: SG.guide.tasteDirection ? Object.assign({}, SG.guide.tasteDirection) : null,
        sectionSchemes: JSON.parse(JSON.stringify(SG.guide.sectionSchemes || {})),
        darkMode: !!SG.guide.darkMode,
      });
      const applyConcept = (c) => {
        if (!c || !UI.taste) return;
        UI.taste.setColours(c.colours);
        if (c.headingFont) UI.taste.setFonts(c.headingFont, c.bodyFont || c.headingFont);
        SG.guide.tasteDirection = c.tasteDirection ? Object.assign({}, c.tasteDirection) : SG.guide.tasteDirection;
        SG.guide.sectionSchemes = JSON.parse(JSON.stringify(c.sectionSchemes || {}));
        SG.guide.darkMode = !!c.darkMode;
        SG.applyOverridesToAllIframes();
        UI.taste.render();
      };
      bar.addEventListener("click", async (e) => {
        if (Array.isArray(SG.guide)) SG.guide = {};
        const g = SG.guide;
        let b;
        if ((b = e.target.closest("[data-concept-save]"))) {
          g.concepts = Array.isArray(g.concepts) ? g.concepts : [];
          g.concepts.push(snapshot("Concept " + (g.concepts.length + 1)));
          g.activeConcept = g.concepts.length - 1;
          saveGuide();
        } else if ((b = e.target.closest("[data-concept-del]"))) {
          const i = Number(b.getAttribute("data-concept-del"));
          g.concepts.splice(i, 1);
          g.concepts.forEach((c, j) => (c.name = "Concept " + (j + 1)));
          if (g.activeConcept === i) g.activeConcept = null;
          else if (g.activeConcept > i) g.activeConcept--;
          saveGuide();
        } else if ((b = e.target.closest("[data-concept]"))) {
          const i = Number(b.getAttribute("data-concept"));
          // Wijzigingen aan het actieve concept eerst bewaren, dan wisselen.
          if (typeof g.activeConcept === "number" && g.concepts[g.activeConcept] && g.activeConcept !== i) {
            g.concepts[g.activeConcept] = snapshot(g.concepts[g.activeConcept].name);
          }
          g.activeConcept = i;
          applyConcept(g.concepts[i]);
        } else if (e.target.closest("[data-sg-dark]")) {
          // Zoals Relume: het concept omkeren (grond en inkt wisselen), geen aparte donkere modus.
          const cols = SG.extractedColours && SG.extractedColours.length ? SG.extractedColours : g.colours || [];
          const get = (n) => cols.find((c) => c.name === n);
          const L = get("Light"), D = get("Dark"), N = get("Neutral");
          if (L && D) {
            [L.hex, D.hex] = [D.hex, L.hex];
            if (N) {
              if (!g.darkMode) {
                g._lightNeutral = N.hex;
                N.hex = toHex(hsl2rgb(hslOf(hexCol(L.hex)).h, Math.min(hslOf(hexCol(L.hex)).s, 0.4), 0.2));
              } else if (g._lightNeutral) N.hex = g._lightNeutral;
            }
            g.darkMode = !g.darkMode;
            UI.taste && UI.taste.setColours(cols);
            SG.applyOverridesToAllIframes();
          }
        } else if (e.target.closest("[data-sg-surprise]")) {
          if (UI.taste) await UI.taste.surprise();
          setTimeout(shuffleSchemes, 400);
        } else return;
        renderBar();
      });
      renderBar();
      setTimeout(renderBar, 2500);
    }

    if (typeof SG.injectOverrideIntoIframe === "function") {
      const origInject = SG.injectOverrideIntoIframe;
      SG.injectOverrideIntoIframe = function (iframe) {
        const r = origInject.apply(this, arguments);
        if (UI.injectStyle) UI.injectStyle(iframe);
        applyScheme(iframe);
        hookSchemeClicks(iframe);
        // Kort uitgesteld: bij het slepen in de kleurkiezer maar één keer per frame meten.
        clearTimeout(iframe._archerContrastTimer);
        clearTimeout(iframe._archerContrastLate);
        iframe._archerContrastTimer = setTimeout(() => fixContrast(iframe), 40);
        // Nog eens na het laden van lettertypes, afbeeldingen en late stijlen van Bricks.
        iframe._archerContrastLate = setTimeout(() => fixContrast(iframe), 1500);
        return r;
      };
    }
    UI.fixContrast = fixContrast;

    /* Kleuren meteen toepassen: ook als ze uit een logo of foto gehaald worden. */
    if (typeof SG.renderSwatchRow === "function") {
      const origSwatches = SG.renderSwatchRow;
      let applyTimer = null;
      SG.renderSwatchRow = function (container, colours) {
        const r = origSwatches.apply(this, arguments);
        if (Array.isArray(colours) && colours.length) {
          clearTimeout(applyTimer);
          applyTimer = setTimeout(() => SG.applyOverridesToAllIframes && SG.applyOverridesToAllIframes(), 0);
        }
        return r;
      };
    }

    // Autosave naar de server, bovenop de lokale kladversie van de stijlgids.
    const saver = UI.debouncedSaver(async () => {
      if (!SG.extractedColours || !SG.extractedColours.length) return;
      if (Array.isArray(SG.guide)) SG.guide = {};
      SG.guide.colours = SG.extractedColours;
      SG.guide.uploadedImages = SG.uploadedImages || [];
      const out = await SG.post("aisb_save_style_guide", {
        project_id: SG.projectId,
        style_guide_json: JSON.stringify(SG.guide),
      });
      if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "Bewaren mislukt");
      try {
        localStorage.setItem("aisb_sg_preview_" + SG.projectId, JSON.stringify(SG.guide));
        localStorage.removeItem("aisb_sg_draft_" + SG.projectId);
      } catch (e) {}
    }, 1200);

    if (typeof SG.saveDraft === "function") {
      const origDraft = SG.saveDraft;
      SG.saveDraft = function () {
        const r = origDraft.apply(this, arguments);
        saver.run();
        return r;
      };
    }
  }

  /* ── Publiceren ─────────────────────────────────────────── */

  /** Controle voor publiceren: wat alleen in de voorbeelden te zien is (alt-teksten, één H1 per pagina, afspraakknop). */
  function initChecks() {
    const box = $("[data-archer-checks]");
    if (!box) return;
    let cfg = {};
    try { cfg = JSON.parse(box.getAttribute("data-config") || "{}"); } catch (e) {}
    const list = $("[data-archer-checks-list]", box);
    const add = (id, status, label, detail) => {
      const li = document.createElement("li");
      li.className = "is-" + status;
      li.setAttribute("data-check", id);
      li.innerHTML = `<span class="archer-check-mark" aria-hidden="true"></span><span><strong>${esc(label)}</strong> <span class="archer-muted">${esc(detail)}</span></span>`;
      list.insertBefore(li, $('[data-check="preview"]', list));
    };
    const done = () => {
      const p = $('[data-check="preview"]', list);
      if (p) p.remove();
      const warn = $$("li.is-warn", list).length;
      const c = $("[data-archer-checks-count]", box);
      if (c) c.textContent = warn ? warn + " om na te kijken" : "Alles in orde";
    };
    let tries = 0;
    const run = () => {
      const cards = $$(".aisb-design-page-card");
      const frames = $$(".aisb-design-page-card iframe");
      const ready = frames.filter((f) => { try { const d = f.contentDocument; return d && d.readyState === "complete" && d.body && d.body.children.length; } catch (e) { return false; } });
      if ((!frames.length || ready.length < frames.length) && tries++ < 40) return setTimeout(run, 750);
      if (!frames.length) { done(); return; }
      let noAlt = 0, imgs = 0, booking = !cfg.booking;
      // Bewijs (awwwards-sites: geen nepgetuigenissen, geen logomuur zonder echte klanten): secties met
      // getuigenissen, reviews of logo's, en of daar nog plaatshouders in staan.
      const proofPages = [], proofTodo = [];
      const badH1 = [];
      cards.forEach((card, idx) => {
        const title = (($(".aisb-design-page-title", card) || {}).textContent || "Pagina " + (idx + 1)).trim();
        let h1 = 0, seen = 0;
        $$("iframe", card).forEach((f) => {
          if (ready.indexOf(f) < 0) return;
          seen++;
          let d; try { d = f.contentDocument; } catch (e) { return; }
          if (!d) return;
          h1 += d.querySelectorAll("h1").length;
          d.querySelectorAll("img").forEach((img) => {
            if (img.closest(".brxe-logo") || img.classList.contains("bricks-site-logo") || img.getAttribute("role") === "presentation") return;
            imgs++;
            if (!(img.getAttribute("alt") || "").trim()) noAlt++;
          });
          d.querySelectorAll(".brxe-section").forEach((sec) => {
            if (sec.closest(".archer-header,.archer-footer,header,footer")) return;
            if (!/testimonial|review|getuig|logo|client|partner|brand/i.test(sec.className || "")) return;
            if (proofPages.indexOf(title) < 0) proofPages.push(title);
            if (/\[[^\]]{2,60}\]/.test(sec.textContent || "") && proofTodo.indexOf(title) < 0) proofTodo.push(title);
          });
          if (idx === 0 && cfg.booking) d.querySelectorAll(".brxe-button, .bricks-button").forEach((b) => {
            if (/afspra|reserv|boek|book|rendez|inschrijv|proefles|bestel/i.test(b.textContent || "")) booking = true;
          });
        });
        if (seen && h1 !== 1) badH1.push(title + " (" + h1 + ")");
      });
      // Beide worden bij publiceren rechtgezet (AISB_Archer_Site::one_h1, alt-teksten in het runtime-script).
      add("h1", badH1.length ? "auto" : "ok", badH1.length ? "Hoofdtitel (H1) wordt bij publiceren rechtgezet" : "Eén hoofdtitel per pagina",
        badH1.length ? "Nu zonder of met meer dan één H1: " + badH1.slice(0, 4).join(", ") + (badH1.length > 4 ? "…" : "") + ". De eerste titel van de pagina wordt de H1." : "Goed voor Google en schermlezers.");
      if (imgs) add("alt", noAlt ? "auto" : "ok", noAlt ? noAlt + " van " + imgs + " foto's krijgen bij publiceren een beschrijving" : "Alle foto's hebben een beschrijving",
        noAlt ? "De titel van hun blok of sectie wordt de alt-tekst, in de taal van de site." : "Alt-teksten zijn ingevuld.");
      if (cfg.booking) add("booking", booking ? "ok" : "warn", booking ? "Afspraak- of reserveerknop op de startpagina" : "Geen afspraak- of reserveerknop op de startpagina",
        booking ? "Belangrijk voor " + (cfg.sector || "deze sector").toLowerCase() + "." : "Voor " + (cfg.sector || "deze sector").toLowerCase() + " is dat de belangrijkste knop.");
      if (proofPages.length) add("proof", proofTodo.length ? "warn" : "ok",
        proofTodo.length ? "Getuigenissen of logo's met plaatshouders" : "Getuigenissen en logo's: echt?",
        proofTodo.length ? "Op " + proofTodo.slice(0, 3).join(", ") + ": vul echte reviews of klantlogo's in, of verwijder die sectie. Verzonnen bewijs kost vertrouwen." : "Op " + proofPages.slice(0, 3).join(", ") + ". Alleen echte klanten, met foto en hun toestemming.");
      done();
    };
    setTimeout(run, 1500);
  }

  function initPublish() {
    initChecks();
    const wrap = $("[data-design]");
    if (!wrap) return;
    const t = $(".aisb-design-toolbar-title", wrap);
    if (t) t.textContent = "Volledige preview";
    const save = $("#aisb-design-save-btn");
    if (save) save.textContent = "Bewaren";
    const pub = $("#aisb-design-publish-btn");
    if (pub) {
      pub.textContent = "Publiceren";
      new MutationObserver(() => {
        const txt = pub.textContent || "";
        if (/Publishing/i.test(txt)) pub.textContent = "Bezig met publiceren… dit duurt enkele minuten";
        else if (/Published/i.test(txt)) {
          pub.textContent = "Gepubliceerd";
          const card = $("[data-archer-published]");
          if (card && !card.classList.contains("is-online")) {
            card.classList.add("is-online");
            card.innerHTML =
              '<span class="archer-summary-label">Webadres</span><strong>Online</strong><span class="archer-muted">Het adres staat ook bij Mijn websites.</span>';
          }
        } else if (/Publish failed/i.test(txt)) pub.textContent = "Publiceren mislukt";
      }).observe(pub, { childList: true, characterData: true, subtree: true });
    }
    if (pub) initPublishFlow(pub);
    initContactCard();
    const figma = $(".aisb-design-figma-btn");
    if (figma) figma.textContent = "Figma-export (Archer-team)";
    const figmaIn = $("#aisb-design-figma-import-btn");
    if (figmaIn) figmaIn.textContent = "Figma-import (Archer-team)";
    const hint = $(".aisb-design-hint", wrap);
    if (hint) hint.textContent = "Scroll om te verschuiven · Ctrl + scroll om te zoomen · dubbelklik om alles te tonen";
  }

  /**
   * Publiceren in de eigen pagina, met een scherm in de Archer-stijl, in plaats van een apart venster.
   * Een apart venster zette deze tab op de achtergrond; daar wachtte de export op een animatieframe dat
   * nooit kwam, en bleef "Publishing your AI website" minutenlang staan.
   */
  function initPublishFlow(pub) {
    const D = window.AISB_Design;
    if (!D || pub._archerFlow) return;
    pub._archerFlow = true;
    const STEPS = [
      { key: "save", label: "Wijzigingen bewaren" },
      { key: "export", label: "Pagina's en stijl klaarmaken" },
      { key: "send", label: "Website opbouwen op het webadres" },
      { key: "done", label: "Menu's, logo en favicon afwerken" },
    ];
    let overlay = null;
    let timer = null;
    // Het venster wordt een keer opgebouwd en daarna ter plaatse bijgewerkt: zo loopt de draaier door
    // en schuift de balk echt (transform), in plaats van alles elke 600ms opnieuw te tekenen.
    const closeOverlay = () => {
      if (!overlay) return;
      const el = overlay;
      overlay = null;
      el.classList.add("is-leaving");
      setTimeout(() => el.remove(), 160);
    };
    const render = (state, extra) => {
      if (!overlay) {
        overlay = document.createElement("div");
        overlay.className = "archer-publish-overlay";
        overlay.setAttribute("role", "dialog");
        overlay.setAttribute("aria-modal", "true");
        overlay.innerHTML =
          '<div class="archer-publish-card"><div class="archer-publish-mark" aria-hidden="true"></div>' +
          '<h2></h2><p class="archer-publish-sub"></p>' +
          '<div class="archer-publish-bar"><span></span></div>' +
          '<ol class="archer-publish-steps">' + STEPS.map((s) => `<li><span class="archer-publish-dot" aria-hidden="true"></span>${esc(s.label)}</li>`).join("") + "</ol>" +
          '<div class="archer-publish-actions" hidden></div><p class="archer-publish-url" hidden></p></div>';
        document.body.appendChild(overlay);
        overlay.addEventListener("click", (e) => {
          if (e.target.closest("[data-archer-publish-close]")) closeOverlay();
          else if (e.target.closest("[data-archer-publish-retry]")) run();
        });
      }
      const card = $(".archer-publish-card", overlay);
      const cur = STEPS.findIndex((s) => s.key === state.step);
      card.classList.toggle("is-done", !!state.done);
      card.classList.toggle("is-error", !state.done && !!state.error);
      $$(".archer-publish-steps li", overlay).forEach((li, i) => {
        li.className = state.error && i === cur ? "is-error" : i < cur || state.done ? "is-done" : i === cur ? "is-active" : "";
      });
      const title = state.done ? "Je website staat online" : state.error ? "Publiceren lukte niet" : "Je website wordt gepubliceerd";
      const sub = state.done
        ? "Alles staat klaar op het webadres. Je kan de teksten en foto's daar nog aanpassen."
        : state.error || "Even geduld: dit duurt meestal minder dan een minuut. Je kan dit venster open laten.";
      if ($("h2", card).textContent !== title) $("h2", card).textContent = title;
      if ($(".archer-publish-sub", card).textContent !== sub) $(".archer-publish-sub", card).textContent = sub;
      $(".archer-publish-bar span", card).style.transform = "scaleX(" + (state.done ? 1 : Math.max(0, Math.min(100, state.pct || 0)) / 100) + ")";
      const actions = $(".archer-publish-actions", card);
      const url = $(".archer-publish-url", card);
      const want = state.done ? "done" : state.error ? "error" : "";
      if (actions.dataset.mode !== want) {
        actions.dataset.mode = want;
        actions.hidden = !want;
        actions.innerHTML = state.done
          ? `<a class="archer-btn archer-btn-primary" href="${esc((extra && extra.url) || "#")}" target="_blank" rel="noopener">Bekijk de website</a><button type="button" class="archer-btn archer-btn-ghost" data-archer-publish-close>Sluiten</button>`
          : state.error
            ? '<button type="button" class="archer-btn archer-btn-primary" data-archer-publish-retry>Opnieuw proberen</button><button type="button" class="archer-btn archer-btn-ghost" data-archer-publish-close>Sluiten</button>'
            : "";
      }
      url.hidden = !state.done;
      if (state.done) url.textContent = String((extra && extra.base) || "").replace(/^https?:\/\//, "").replace(/\/$/, "");
    };
    const run = async () => {
      if (pub.dataset.aisbPublishing === "1") return;
      pub.dataset.aisbPublishing = "1";
      pub.disabled = true;
      // De balk loopt mee op de verwachte duur van elke stap; de stappen zelf bepalen waar we zijn.
      const state = { step: "save", pct: 2 };
      const ceil = { save: 12, export: 30, send: 88, done: 96 };
      clearInterval(timer);
      timer = setInterval(() => {
        state.pct = Math.min(ceil[state.step] || 95, state.pct + (state.step === "send" ? 1.2 : 2.5));
        render(state);
      }, 600);
      render(state);
      try {
        await UI.flushAll();
        if (D.saveAllEdits) await D.saveAllEdits();
        state.step = "export"; state.pct = Math.max(state.pct, 14); render(state);
        if (typeof D.exportLiveDesign !== "function") throw new Error("De export is nog niet klaar. Herlaad de pagina en probeer opnieuw.");
        const exportData = await D.exportLiveDesign();
        state.step = "send"; state.pct = Math.max(state.pct, 32); render(state);
        const result = await D.post("aisb_publish_instawp_site", { project_id: D.projectId, export_payload: JSON.stringify(exportData) });
        if (!result || !result.success) throw new Error((result && result.data && result.data.message) || "De server kon de website niet publiceren.");
        state.step = "done"; state.pct = 97; render(state);
        const data = result.data || {};
        const base = data.wp_url || "";
        const url = data.frontend_auto_login_url || data.magic_login_url || base;
        clearInterval(timer);
        state.done = true;
        render(state, { url, base });
        pub.textContent = "Gepubliceerd";
        const card = $("[data-archer-published]");
        if (card) {
          card.classList.add("is-online");
          card.innerHTML =
            '<span class="archer-summary-label">Webadres</span><strong>Online</strong>' +
            `<a href="${esc(base)}" target="_blank" rel="noopener">${esc(base.replace(/^https?:\/\//, "").replace(/\/$/, ""))}</a>`;
        }
      } catch (err) {
        clearInterval(timer);
        console.error("[Archer] publiceren", err);
        state.error = (err && err.message) || "Er ging iets mis.";
        render(state);
        pub.textContent = "Publiceren";
      } finally {
        delete pub.dataset.aisbPublishing;
        pub.disabled = false;
      }
    };
    // Voor de klikhandler van de stijlgids (die een apart venster opent).
    document.addEventListener(
      "click",
      (e) => {
        if (!e.target.closest || e.target.closest("#aisb-design-publish-btn") !== pub) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        run();
      },
      true,
    );
  }

  /** Contactgegevens wijzigen vanuit de samenvatting van de stap Publiceren. */
  function initContactCard() {
    const card = $("[data-archer-contact-card]");
    if (!card) return;
    const edit = $("[data-archer-contact-edit]", card);
    if (!edit) return;
    edit.addEventListener("click", (e) => {
      e.preventDefault();
      let c = {};
      try { c = JSON.parse(card.getAttribute("data-contact") || "{}"); } catch (err) {}
      const socials = Object.values(c.socials || {}).join(", ");
      const dlg = document.createElement("div");
      dlg.className = "archer-publish-overlay";
      dlg.innerHTML =
        '<form class="archer-publish-card archer-contact-form">' +
        "<h2>Contactgegevens</h2>" +
        '<p class="archer-publish-sub">Komen in de footer, op de contactpagina en achter de knoppen. Publiceer daarna opnieuw.</p>' +
        `<label class="aisb-label">Adres<input name="address" class="archer-input" value="${esc(c.address || "")}" placeholder="Straat 1, 9000 Gent"></label>` +
        `<label class="aisb-label">Telefoon<input name="phone" type="tel" class="archer-input" value="${esc(c.phone || "")}"></label>` +
        `<label class="aisb-label">E-mail<input name="email" type="email" class="archer-input" value="${esc(c.email || "")}"></label>` +
        `<label class="aisb-label">Sociale media (links)<input name="socials" class="archer-input" value="${esc(socials)}"></label>` +
        '<p class="archer-small" data-msg></p>' +
        '<div class="archer-publish-actions"><button class="archer-btn archer-btn-primary" type="submit">Bewaren</button><button type="button" class="archer-btn archer-btn-ghost" data-close>Annuleren</button></div>' +
        "</form>";
      document.body.appendChild(dlg);
      $("[data-close]", dlg).addEventListener("click", () => dlg.remove());
      $("form", dlg).addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const f = ev.target;
        const val = { address: f.address.value, phone: f.phone.value, email: f.email.value, socials: f.socials.value };
        $("[data-msg]", dlg).textContent = "Bewaren…";
        try {
          const out = await UI.post("aisb_archer_contact", { project_id: CFG.projectId, contact: JSON.stringify(val) });
          const saved = out.contact || val;
          card.setAttribute("data-contact", JSON.stringify(saved));
          const filled = [saved.phone, saved.email, saved.address].filter(Boolean);
          $("strong", card).textContent = filled.length ? filled.length + " van 3 ingevuld" : "Nog niet ingevuld";
          $("[data-archer-contact-summary]", card).textContent = filled.length ? filled.join(" · ") : "Adres, telefoon en e-mail voor de footer en de knoppen.";
          dlg.classList.add("is-leaving");
          setTimeout(() => dlg.remove(), 160);
        } catch (err) {
          $("[data-msg]", dlg).textContent = err.message || "Bewaren lukte niet.";
        }
      });
    });
  }

  /* ── Start ──────────────────────────────────────────────── */

  document.addEventListener("DOMContentLoaded", () => {
    try { initStart(); } catch (e) { console.error("[Archer] start", e); }
    try { initWireframes(); } catch (e) { console.error("[Archer] wireframes", e); }
    try { initStyleGuide(); } catch (e) { console.error("[Archer] stijl", e); }
    try { initPublish(); } catch (e) { console.error("[Archer] publiceren", e); }

    // De volledige preview (stap Publiceren) verving elk menu door een platte lijst van alle pagina's.
    // De menu's komen al juist van de server (hoofdmenu met submenu's, footergroepen, juridisch).
    if (window.AISB_Design) window.AISB_Design.injectNavMenuLinks = function () {};

    // De website op InstaWP al klonen zodra de klant aan de stijl begint: publiceren slaat
    // het klonen dan over (de server antwoordt meteen en kloont op de achtergrond verder).
    if (CFG.projectId && CFG.step >= 3 && !Number(CFG.published)) {
      UI.post("aisb_archer_prewarm", { project_id: CFG.projectId }).catch((err) => console.warn("[Archer] site klaarzetten", err));
    }

    // Waarschuwen bij sluiten met onbewaarde wijzigingen.
    window.addEventListener("beforeunload", (e) => {
      const pill = $("[data-archer-save-pill]");
      if (pill && pill.getAttribute("data-state") === "saving") {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  });
})();
