/**
 * Het uitgewerkte ontwerp van een stijl, getekend met de gegevens uit de brief.
 *
 * De chatbot toonde bij het kiezen een schets: een kleurvlak met "Aa", een knop en vier staaltjes.
 * Daar zie je een stijl niet aan. Hier staat in de plaats een hele pagina - hoofding, hero, kaarten,
 * tekst naast beeld, cijfers, een woord van een klant, een oproep en een voet - met de letters, de
 * maten, de ruimte, de hoeken en het vakmanschap van die ene stijl.
 *
 * De variabelen komen van de server (AISB_Archer_Styles::preview_vars), dezelfde formules als die
 * van de gepubliceerde site. De opmaak staat in archer-preview.css. Dit bestand doet alleen twee
 * dingen: van de brief een inhoud maken, en van een stijl plus die inhoud een pagina.
 *
 * De teksten die de brief nog niet heeft, blijven zichtbaar algemeen ("Een paar zinnen over wat je
 * hier doet"). Een voorbeeld mag een ontwerp tonen, geen beloftes verzinnen die de zaak niet deed.
 */
(function () {
  "use strict";

  const esc = (s) =>
    String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  /**
   * De woorden komen uit de taal van de plugin (AISB_Archer_Lang::words, via wp_localize_script).
   * Ze staan niet in dit bestand: wie Nederlands op Engels zet, mag niet in de code moeten.
   */
  const W = window.ARCHER_WORDS || {};
  const t = (k) => (typeof W[k] === "string" ? W[k] : "");
  const list = (k) => (Array.isArray(W[k]) ? W[k] : []);
  const fmt = (k, v) => t(k).replace("%s", v);
  const fill = (i) => {
    const f = list("fill");
    return f.length ? f[i % f.length] : "";
  };

  const ctaFor = (goal) => {
    const g = String(goal || "").toLowerCase();
    for (const row of list("goals")) {
      if (row && row[0] && g.indexOf(String(row[0]).toLowerCase()) >= 0) return row[1];
    }
    return t("cta_default");
  };

  /* ── De inhoud uit de brief ──────────────────────────────── */

  function content(brief) {
    const b = brief || {};
    const clean = (v) => (typeof v === "string" && v.trim() !== "" && v.trim() !== "-" ? v.trim() : "");
    // `uit` leest een lijst uit de brief; `list` leest er een uit de woordenlijst van de taal.
    const uit = (k) => (Array.isArray(b[k]) ? b[k].filter((x) => clean(x)) : []);
    const name = clean(b.business_name) || t("business");
    const region = clean(b.region);
    const activity = clean(b.activity);
    const usp = clean(b.usp);
    const pages = (uit("must_pages").length ? uit("must_pages") : list("nav_pages"))
      .filter((p) => !/^home$/i.test(p));
    const services = uit("services");
    const photos = Array.isArray(b.own_photos) ? b.own_photos : [];
    const contact = b.contact && !b.contact.skipped ? b.contact : {};
    const cta = ctaFor(b.main_goal);

    const trust = [
      clean(b.reviews) ? "★ " + clean(b.reviews) : "",
      clean(b.founded) ? fmt("since", clean(b.founded)) : "",
      uit("certifications")[0] || "",
      uit("guarantees")[0] || "",
    ].filter(Boolean).slice(0, 3);

    // Cijfers: alleen wat de brief echt weet. Weet ze te weinig, dan staat de band er als werkwijze.
    const figures = [];
    if (clean(b.founded)) figures.push({ n: clean(b.founded), t: t("fig_since") });
    if (clean(b.reviews)) figures.push({ n: clean(b.reviews).split(/\s/)[0], t: t("fig_reviews") });
    if (clean(b.team)) figures.push({ n: clean(b.team), t: t("fig_team") });
    if (services.length) figures.push({ n: String(services.length), t: t("fig_services") });
    const steps = list("steps").map((r) => ({ n: r[0], t: r[1] }));

    return {
      name, region, activity, cta,
      logo: b.logo && typeof b.logo === "object" ? b.logo.thumb || b.logo.url : "",
      pages: pages.slice(0, 5),
      title: usp || activity || t("title"),
      lead: [activity && usp ? activity : "", region ? fmt("in_region", region) : ""].filter(Boolean).join(" ") || t("lead"),
      services: (services.length ? services : list("services_fallback")).slice(0, 3),
      servicesKnown: services.length > 0,
      audience: clean(b.audience),
      hours: clean(b.opening_hours),
      contact, trust, photos,
      figures: figures.length >= 3 ? figures.slice(0, 3) : null,
      steps,
      quote: t("quote"),
      quoteBy: clean(b.references) ? clean(b.references).split(/[,;]/)[0] : t("quote_by"),
    };
  }

  /* ── De pagina ───────────────────────────────────────────── */

  const img = (photo, cls) =>
    `<div class="apv-img${cls ? " " + cls : ""}">${photo ? `<img src="${esc(photo.thumb || photo.url)}" alt="">` : '<span class="apv-ph" aria-hidden="true"></span>'}</div>`;

  const SECTIONS = {
    nav: (c) => `<header class="apv-nav"><div class="apv-wrap">
      <span class="apv-logo">${c.logo ? `<img src="${esc(c.logo)}" alt="">` : esc(c.name)}</span>
      <nav>${c.pages.slice(0, 4).map((p) => `<span>${esc(p)}</span>`).join("")}</nav>
      <span class="apv-btn">${esc(c.cta)}</span>
    </div></header>`,

    hero: (c) => `<section class="apv-hero"><div class="apv-wrap">
      <div class="apv-hero-text">
        <h1>${esc(c.title)}</h1>
        <p>${esc(c.lead)}</p>
        <div class="apv-actions"><span class="apv-btn">${esc(c.cta)}</span><span class="apv-btn is-ghost">${esc(t("secondary"))}</span></div>
        ${c.trust.length ? `<ul class="apv-trust">${c.trust.map((x) => `<li>${esc(x)}</li>`).join("")}</ul>` : ""}
      </div>
      ${img(c.photos[0], "apv-hero-img")}
    </div></section>`,

    services: (c) => `<section class="apv-sec apv-services"><div class="apv-wrap">
      <div class="apv-head"><h2>${esc(t("services_title"))}</h2><p>${esc(c.audience ? fmt("services_for", c.audience) : fill(1))}</p></div>
      <div class="apv-grid">${c.services.map((s, i) => `<article class="apv-card">
        ${c.photos[i + 1] ? img(c.photos[i + 1]) : ""}
        <h3>${esc(s)}</h3><p>${esc(fill(i))}</p><span class="apv-link">${esc(t("more"))}</span>
      </article>`).join("")}</div>
    </div></section>`,

    split: (c) => `<section class="apv-sec apv-split"><div class="apv-wrap">
      ${img(c.photos[4])}
      <div class="apv-split-text">
        <h2>${esc(fmt("about", c.name))}</h2>
        <p>${esc(fill(2))}</p>
        <ul class="apv-checks">${(c.trust.length ? c.trust : list("checks")).map((x) => `<li>${esc(x)}</li>`).join("")}</ul>
      </div>
    </div></section>`,

    figures: (c) => `<section class="apv-sec apv-figures"><div class="apv-wrap">
      ${(c.figures || c.steps).map((f) => `<div class="apv-fig"><b>${esc(f.n)}</b><span>${esc(f.t)}</span></div>`).join("")}
    </div></section>`,

    quote: (c) => `<section class="apv-sec apv-quote"><div class="apv-wrap">
      <blockquote>${esc(c.quote)}</blockquote><cite>${esc(c.quoteBy)}</cite>
    </div></section>`,

    cta: (c) => `<section class="apv-sec apv-cta"><div class="apv-wrap">
      <div class="apv-cta-text"><h2>${esc(t("cta_title"))}</h2><p>${esc(c.region ? fmt("cta_region", c.region) : fill(0))}</p></div>
      <span class="apv-btn">${esc(c.cta)}</span>
    </div></section>`,

    foot: (c) => `<footer class="apv-foot"><div class="apv-wrap">
      <div class="apv-foot-col"><strong>${esc(c.name)}</strong>${c.contact.address ? `<span>${esc(c.contact.address)}</span>` : c.region ? `<span>${esc(c.region)}</span>` : ""}</div>
      <div class="apv-foot-col"><b>${esc(t("pages"))}</b><ul>${[t("home")].concat(c.pages).slice(0, 5).map((p) => `<li>${esc(p)}</li>`).join("")}</ul></div>
      <div class="apv-foot-col"><b>${esc(t("contact"))}</b><ul>${[c.contact.phone, c.contact.email].filter(Boolean).map((x) => `<li>${esc(x)}</li>`).join("") || `<li>${esc(t("your_details"))}</li>`}</ul></div>
      <div class="apv-foot-col"><b>${esc(t("open"))}</b><ul><li>${esc(c.hours || t("your_hours"))}</li></ul></div>
      <div class="apv-foot-rule" style="grid-column:1/-1">&copy; ${new Date().getFullYear()} ${esc(c.name)}</div>
    </div></footer>`,
  };

  const FULL = ["nav", "hero", "services", "split", "figures", "quote", "cta", "foot"];
  const TOP = ["nav", "hero", "services"];

  function page(style, c, sections) {
    return (sections || FULL).map((k) => (SECTIONS[k] ? SECTIONS[k](c) : "")).join("");
  }

  /* ── Tekenen ─────────────────────────────────────────────── */

  /**
   * Het ontwerp staat in een iframe, niet in de pagina zelf.
   *
   * De bouwer heeft zijn eigen stylesheets (archer.css, base.css) en die zetten h1, p en strong met
   * selectors die zwaarder wegen dan .apv. Het eerste voorbeeld kreeg daardoor Urbanist in plaats van
   * Ultra: de letters van de bouwer in plaats van die van de stijl. Een iframe is een schone lei -
   * geen regel van de bouwer of van het thema raakt er binnen - en dat is precies wat een voorbeeld
   * moet tonen. Het scrollt ook vanzelf, dus er valt geen hoogte uit te meten.
   */
  const previewCss = () => {
    const l = document.querySelector('link[href*="archer-preview.css"]');
    return l ? l.href : "";
  };

  function frameDoc(style, c, opts) {
    const urls = style && style.fontUrls && style.fontUrls.length ? style.fontUrls : style && style.fontUrl ? [style.fontUrl] : [];
    const links = urls.map((u) => `<link rel="stylesheet" href="${esc(u)}">`).join("");
    const css = previewCss();
    const vars = (style && style.vars ? style.vars : "") + (opts.vars || "");
    return (
      '<!doctype html><html class="apv-doc" lang="nl"><head><meta charset="utf-8">' +
      links + (css ? `<link rel="stylesheet" href="${esc(css)}">` : "") +
      `</head><body><div class="apv" style="${esc(vars)}">${page(style, c, opts.sections)}</div></body></html>`
    );
  }

  /**
   * Zet het ontwerp in `host`.
   * opts: { width } breedte van het kader in px (standaard die van host), { clip } zichtbare hoogte,
   *       { scroll } zelf scrollen, { sections } welke secties, { vars } extra variabelen
   *       (bijvoorbeeld de merkkleur van de klant).
   */
  function mount(host, style, c, opts) {
    if (!host) return null;
    opts = opts || {};
    // Een kader dat nog nul breed is (een dichtgeklapt paneel) zou de pagina op ware grootte
    // tekenen en dus afgeknipt tonen; dan liever de breedte van het scherm als maat.
    const w = opts.width || host.clientWidth || Math.min(1280, Math.max(320, document.documentElement.clientWidth - 40));
    const k = w / 1280;
    const visible = opts.clip || host.clientHeight || Math.round(w * 0.78);
    host.innerHTML =
      `<div class="apv-stage" style="height:${visible}px">` +
      `<iframe class="apv-frame" title="Voorbeeld van het ontwerp" scrolling="${opts.scroll ? "auto" : "no"}" ` +
      `style="height:${Math.round(visible / k)}px;transform:scale(${k})"></iframe></div>`;
    const frame = host.querySelector(".apv-frame");
    if (!opts.scroll) frame.style.pointerEvents = "none";
    frame.srcdoc = frameDoc(style, c, opts);
    return host.firstElementChild;
  }

  window.ArcherPreview = { content, page, mount, esc, ctaFor, FULL, TOP };
})();
