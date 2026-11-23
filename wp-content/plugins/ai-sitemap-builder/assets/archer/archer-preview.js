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

  const GOAL_CTA = {
    bellen: "Bel ons", offerte: "Vraag een offerte", afspraak: "Maak een afspraak", reserv: "Reserveer",
    langs: "Kom langs", bestel: "Bestel nu", contact: "Neem contact op",
  };
  const ctaFor = (goal) => {
    const g = String(goal || "").toLowerCase();
    for (const k of Object.keys(GOAL_CTA)) if (g.indexOf(k) >= 0) return GOAL_CTA[k];
    return "Neem contact op";
  };

  const FILL = [
    "Een paar zinnen over wat je hier doet en voor wie.",
    "Wat de klant krijgt, in gewone woorden.",
    "Hoe het werkt, van eerste vraag tot afgewerkt.",
  ];

  /* ── De inhoud uit de brief ──────────────────────────────── */

  function content(brief) {
    const b = brief || {};
    const clean = (v) => (typeof v === "string" && v.trim() !== "" && v.trim() !== "-" ? v.trim() : "");
    const list = (k) => (Array.isArray(b[k]) ? b[k].filter((x) => clean(x)) : []);
    const name = clean(b.business_name) || "Je zaak";
    const region = clean(b.region);
    const activity = clean(b.activity);
    const usp = clean(b.usp);
    const pages = (list("must_pages").length ? list("must_pages") : ["Diensten", "Over ons", "Werkwijze", "Contact"])
      .filter((p) => !/^home$/i.test(p));
    const services = list("services");
    const photos = Array.isArray(b.own_photos) ? b.own_photos : [];
    const contact = b.contact && !b.contact.skipped ? b.contact : {};
    const cta = ctaFor(b.main_goal);

    const trust = [
      clean(b.reviews) ? "★ " + clean(b.reviews) : "",
      clean(b.founded) ? "Sinds " + clean(b.founded) : "",
      list("certifications")[0] || "",
      list("guarantees")[0] || "",
    ].filter(Boolean).slice(0, 3);

    // Cijfers: alleen wat de brief echt weet. Weet ze te weinig, dan staat de band er als werkwijze.
    const figures = [];
    if (clean(b.founded)) figures.push({ n: clean(b.founded), t: "In zaak sinds" });
    if (clean(b.reviews)) figures.push({ n: clean(b.reviews).split(/\s/)[0], t: "Wat klanten geven" });
    if (clean(b.team)) figures.push({ n: clean(b.team), t: "Ons team" });
    if (services.length) figures.push({ n: String(services.length), t: "Diensten" });
    const steps = [
      { n: "Contact", t: "Je vraag komt binnen en we bekijken ze samen." },
      { n: "Voorstel", t: "Je krijgt een duidelijk voorstel met een prijs." },
      { n: "Uitvoering", t: "We voeren uit en je blijft op de hoogte." },
    ];

    return {
      name, region, activity, cta,
      logo: b.logo && typeof b.logo === "object" ? b.logo.thumb || b.logo.url : "",
      pages: pages.slice(0, 5),
      title: usp || activity || "Hier komt je website",
      lead: [activity && usp ? activity : "", region ? "in " + region : ""].filter(Boolean).join(" ")
        || "De eerste zin van je website vertelt wat je doet en voor wie.",
      services: (services.length ? services : ["Je eerste dienst", "Je tweede dienst", "Je derde dienst"]).slice(0, 3),
      servicesKnown: services.length > 0,
      audience: clean(b.audience),
      hours: clean(b.opening_hours),
      contact, trust, photos,
      figures: figures.length >= 3 ? figures.slice(0, 3) : null,
      steps,
      quote: "Hier komt straks een woord van een klant.",
      quoteBy: clean(b.references) ? clean(b.references).split(/[,;]/)[0] : "Een klant",
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
        <div class="apv-actions"><span class="apv-btn">${esc(c.cta)}</span><span class="apv-btn is-ghost">Bekijk ons werk</span></div>
        ${c.trust.length ? `<ul class="apv-trust">${c.trust.map((t) => `<li>${esc(t)}</li>`).join("")}</ul>` : ""}
      </div>
      ${img(c.photos[0], "apv-hero-img")}
    </div></section>`,

    services: (c) => `<section class="apv-sec apv-services"><div class="apv-wrap">
      <div class="apv-head"><h2>Wat we doen</h2><p>${esc(c.audience ? "Voor " + c.audience + "." : FILL[1])}</p></div>
      <div class="apv-grid">${c.services.map((s, i) => `<article class="apv-card">
        ${c.photos[i + 1] ? img(c.photos[i + 1]) : ""}
        <h3>${esc(s)}</h3><p>${esc(FILL[i % FILL.length])}</p><span class="apv-link">Meer hierover</span>
      </article>`).join("")}</div>
    </div></section>`,

    split: (c) => `<section class="apv-sec apv-split"><div class="apv-wrap">
      ${img(c.photos[4])}
      <div class="apv-split-text">
        <h2>Over ${esc(c.name)}</h2>
        <p>${esc(FILL[2])}</p>
        <ul class="apv-checks">${(c.trust.length ? c.trust : ["Vast aanspreekpunt", "Afspraak is afspraak", "Werk dat blijft"]).map((t) => `<li>${esc(t)}</li>`).join("")}</ul>
      </div>
    </div></section>`,

    figures: (c) => `<section class="apv-sec apv-figures"><div class="apv-wrap">
      ${(c.figures || c.steps).map((f) => `<div class="apv-fig"><b>${esc(f.n)}</b><span>${esc(f.t)}</span></div>`).join("")}
    </div></section>`,

    quote: (c) => `<section class="apv-sec apv-quote"><div class="apv-wrap">
      <blockquote>${esc(c.quote)}</blockquote><cite>${esc(c.quoteBy)}</cite>
    </div></section>`,

    cta: (c) => `<section class="apv-sec apv-cta"><div class="apv-wrap">
      <div class="apv-cta-text"><h2>Zin om te starten?</h2><p>${esc(c.region ? "We werken in " + c.region + " en omstreken." : FILL[0])}</p></div>
      <span class="apv-btn">${esc(c.cta)}</span>
    </div></section>`,

    foot: (c) => `<footer class="apv-foot"><div class="apv-wrap">
      <div class="apv-foot-col"><strong>${esc(c.name)}</strong>${c.contact.address ? `<span>${esc(c.contact.address)}</span>` : c.region ? `<span>${esc(c.region)}</span>` : ""}</div>
      <div class="apv-foot-col"><b>Pagina's</b><ul>${["Home"].concat(c.pages).slice(0, 5).map((p) => `<li>${esc(p)}</li>`).join("")}</ul></div>
      <div class="apv-foot-col"><b>Contact</b><ul>${[c.contact.phone, c.contact.email].filter(Boolean).map((x) => `<li>${esc(x)}</li>`).join("") || "<li>Je gegevens</li>"}</ul></div>
      <div class="apv-foot-col"><b>Open</b><ul><li>${esc(c.hours || "Je openingsuren")}</li></ul></div>
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
    const w = opts.width || host.clientWidth || 1280;
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
