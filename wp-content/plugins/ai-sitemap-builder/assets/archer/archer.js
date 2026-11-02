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

    // Modus "bestaande website": eerst de site lezen, dan de gewone generatie.
    if (app.btnGen) {
      app.btnGen.addEventListener(
        "click",
        async (e) => {
          if (mode !== "url") return;
          e.preventDefault();
          e.stopImmediatePropagation();
          const urlEl = $("#archer-site-url");
          const extraEl = $("#archer-site-extra");
          const url = (urlEl.value || "").trim();
          if (url.length < 4) {
            app.setStatus('<div class="aisb-error">Vul het adres van de huidige website in.</div>');
            urlEl.focus();
            return;
          }
          app.setLoading(true);
          app.setStatus("We lezen de huidige website…");
          try {
            const data = await UI.post("aisb_archer_site_brief", { url });
            const extra = (extraEl.value || "").trim();
            const pages = Array.isArray(data.pages) ? data.pages : [];
            const titleOf = {};
            pages.forEach((p) => (titleOf[p.slug] = p.title));
            let tree = "";
            if (pages.length) {
              tree =
                "\nBESTAANDE SITEMAP (neem alle " + (pages.length + 1) + " pagina's over, met dezelfde structuur):\n" +
                "- Home (slug: home)\n" +
                pages
                  .map((p) => "- " + p.title + " (slug: " + p.slug + (p.parent_slug && p.parent_slug !== "home" ? ", onder: " + (titleOf[p.parent_slug] || p.parent_slug) : "") + ")")
                  .join("\n") +
                "\n";
              (data.collapsed || []).forEach((c) => {
                tree += "Onder " + (titleOf[c.slug] || c.path) + " staan " + c.items + " losse items (bv. producten of berichten): maak daarvoor één detailpagina als sjabloon.\n";
              });
              // Paginakeuze volgt de bestaande site.
              const total = pages.length + 1;
              const pc = $("#aisb-pagecount");
              if (pc) pc.value = total <= 5 ? "2-5" : total <= 10 ? "5-10" : total <= 15 ? "10-15" : "15+";
            }
            const head =
              "Maak een nieuwe, betere versie van de bestaande website " + data.url + ". " +
              "Gebruik de eigen diensten, teksten en contactgegevens hieronder als bron en verzin geen feiten bij.\n" +
              (extra ? "Wensen van de klant: " + extra + "\n" : "") +
              tree +
              "\n";
            // De server aanvaardt maximaal 10.000 bytes: de lopende tekst wordt ingekort, de sitemap niet.
            const bytes = (t) => new TextEncoder().encode(t).length;
            let text = data.text || "";
            while (text && bytes(head + text) > 9800) text = text.slice(0, Math.max(0, text.length - 400));
            app.promptEl.value = head + text;
            app.promptEl.dispatchEvent(new Event("input"));
            app.setLoading(false);
            await app.doGenerate();
          } catch (err) {
            app.setLoading(false);
            app.setStatus('<div class="aisb-error">' + esc(err.message) + "</div>");
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
    const PREVIEW = (window.AISB_WF && AISB_WF.previewUrl) || "";
    let parentOf = null; // slug => ouderslug, uit de sitemap
    let firstCanvas = true;
    let canvasStale = false;

    // Ouders uit de sitemap, voor de volgorde en de kruimel boven elke kolom.
    if (typeof wf.postCore === "function" && wf.state.sitemapId) {
      wf.postCore("aisb_get_sitemap_by_id", { sitemap_id: wf.state.sitemapId })
        .then((out) => {
          const data = (out && out.data && (out.data.data || out.data)) || {};
          const list = Array.isArray(data.sitemap) ? data.sitemap : [];
          parentOf = {};
          list.forEach((p) => {
            const slug = (p.slug || "").toString().replace(/^\//, "");
            if (slug) parentOf[slug] = (p.parent_slug || "").toString().replace(/^\//, "");
          });
          if (wf.state.pages.length && !isOpen()) wf.renderWhiteboard();
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
        '<div class="archer-cv-sec-label"><strong></strong><span></span></div>' +
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
        iframe.loading = "lazy";
        iframe.title = typeLabelOf(s) + " (voorbeeld)";
        iframe.scrolling = "no";
        iframe.tabIndex = -1;
        $(".aisb-wf-iframe-wrap", el).appendChild(iframe);
      } else {
        $(".archer-cv-placeholder", el).textContent = typeLabelOf(s);
      }
      return el;
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

    function renderColumn(col, p, order) {
      const model = wf.state.pageModels[p.slug];
      const sections = (model && model.sections) || [];
      col.style.order = String(order);
      col.classList.toggle("is-empty", !sections.length);
      col.classList.toggle("is-child", depthOf(p.slug) > 0);
      if (col.getAttribute("data-archer-cv-sig") !== columnSig(p)) {
        col.setAttribute("data-archer-cv-sig", columnSig(p));
        const parent = parentOf && parentOf[p.slug] && parentOf[p.slug] !== "home" ? wf.state.pages.find((x) => x.slug === parentOf[p.slug]) : null;
        $(".archer-cv-crumb", col).textContent = parent ? (parent.title || parent.slug) + " ›" : p.slug === "home" ? "Startpagina" : "Hoofdmenu";
        $(".archer-cv-title", col).textContent = p.title || p.slug;
        $("[data-archer-cv-open]", col).setAttribute("aria-label", "Pagina " + (p.title || p.slug) + " bewerken");
      }
      $(".archer-cv-meta", col).textContent = "/" + (p.slug === "home" ? "" : p.slug) + " · " + (sections.length ? sections.length + " secties" : "nog leeg");

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
      empty.hidden = !!sections.length;
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

    wf.renderWhiteboard = function () {
      if (!inner) return;
      const pages = wf.state.pages || [];
      if (firstCanvas && pages.length) {
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
        wf.state.pageSlug = slug;
        wf.state.model = wf.state.pageModels[slug] || { sections: [] };
        wf.btn.generate && wf.btn.generate.click();
        return;
      }
      const btn = e.target.closest("[data-archer-cv-act]");
      if (!btn || btn.disabled) return;
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

    // Weergave kiezen: stijl = kleuren + lettertypes, foto's = afbeeldingen.
    const show = () => {
      if (typeof SG.goToWizardStep !== "function") return;
      SG.goToWizardStep(view === "photos" ? "3" : "1");
    };
    show();

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
      [".aisb-sg-preview-hint", "Scroll · Ctrl + scroll om te zoomen"],
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
      "Upload your own images or let AI find matching stock photos.": "Upload eigen foto's of houd de passende stockfoto's die AI koos.",
      "Drag & drop images here or": "Sleep foto's hierheen of",
      "No uploaded images yet.": "Nog geen eigen foto's.",
      "Your uploads": "Eigen foto's",
      "Unsplash images": "Stockfoto's",
      "Unsplash images will be loaded automatically…": "De stockfoto's worden geladen…",
    });

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

  function initPublish() {
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
    const figma = $(".aisb-design-figma-btn");
    if (figma) figma.textContent = "Figma-export (Archer-team)";
    const figmaIn = $("#aisb-design-figma-import-btn");
    if (figmaIn) figmaIn.textContent = "Figma-import (Archer-team)";
    const hint = $(".aisb-design-hint", wrap);
    if (hint) hint.textContent = "Scroll om te verschuiven · Ctrl + scroll om te zoomen · dubbelklik om alles te tonen";
  }

  /* ── Start ──────────────────────────────────────────────── */

  document.addEventListener("DOMContentLoaded", () => {
    try { initStart(); } catch (e) { console.error("[Archer] start", e); }
    try { initWireframes(); } catch (e) { console.error("[Archer] wireframes", e); }
    try { initStyleGuide(); } catch (e) { console.error("[Archer] stijl", e); }
    try { initPublish(); } catch (e) { console.error("[Archer] publiceren", e); }

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
