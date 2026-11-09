/**
 * Intake-agent op het startscherm.
 *
 * Vervangt het startformulier door een gesprek: de agent stelt één vraag per beurt, met
 * knoppen voor gangbare antwoorden en altijd een tekstveld. Bovenaan staat "Je website":
 * een voortgangsbalk en de ingevulde velden als chips (klik om te wijzigen). Als alles er
 * is, toont de agent een samenvatting; "Maak mijn website" start de bestaande generatie
 * (UI.fastGenerate) met een brief die uit het gesprek is opgebouwd, en opent daarna de
 * stap Ontwerp. Het oude formulier blijft bereikbaar via "Liever een formulier".
 */
(function () {
  "use strict";

  const CFG = window.ARCHER;
  if (!CFG) return;

  const $ = (sel, root) => (root || document).querySelector(sel);
  const esc = (s) =>
    String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  const STORE = "archer_intake_v1";
  const LABELS = {
    business_name: "Naam", existing_site: "Website", activity: "Activiteit", services: "Diensten",
    audience: "Doelgroep", region: "Regio", main_goal: "Doel", booking: "Boeken", usp: "Troef",
    tone: "Toon", languages: "Talen", page_count: "Pagina's", must_pages: "Vaste pagina's",
    style_mood: "Stijl", brand_colors: "Kleuren", examples: "Voorbeelden", photos: "Foto's", contact: "Contact",
  };
  const LANG_NL = { Dutch: "Nederlands", French: "Frans", English: "Engels", German: "Duits" };
  const CHIP_ORDER = ["business_name", "existing_site", "activity", "audience", "region", "main_goal", "tone", "languages", "style_mood", "photos"];

  const valueText = (k, v) => {
    if (v == null || v === "") return "";
    if (k === "languages") return v.map((l) => LANG_NL[l] || l).join(", ");
    if (k === "contact") return v.skipped ? "" : Object.values(v).join(" · ");
    if (k === "existing_site") return String(v).replace(/^https?:\/\/(www\.)?/, "").replace(/\/$/, "");
    return Array.isArray(v) ? v.join(", ") : String(v);
  };

  function init() {
    const start = $("[data-archer-start]");
    const shell = $(".archer-shell");
    if (!start || !shell || CFG.projectId) return;
    if (shell.hasAttribute("data-archer-has-project")) return;

    // Oude formulier verbergen; blijft beschikbaar als terugval.
    const legacy = Array.from(start.children);
    legacy.forEach((el) => el.setAttribute("data-archer-legacy", ""));
    start.classList.add("archer-intake-on");

    const root = document.createElement("section");
    root.className = "archer-intake";
    root.setAttribute("data-archer-intake", "");
    root.innerHTML = `
      <div class="archer-intake-status"><span class="archer-intake-dot" aria-hidden="true"></span> Intake-agent · online</div>
      <div class="archer-intake-setup" data-intake-setup hidden>
        <div class="archer-intake-setup-head"><span>Je website</span><span data-intake-step></span></div>
        <div class="archer-intake-bars" data-intake-bars aria-hidden="true"></div>
        <div class="archer-intake-chips" data-intake-chips></div>
      </div>
      <div class="archer-intake-hello" data-intake-hello>
        <div class="archer-intake-orb" aria-hidden="true"></div>
        <h1 class="archer-intake-title">Laten we je website opzetten.</h1>
        <p class="archer-intake-lead">Ik ben de intake-agent van Archer. Geef de naam van je zaak of het adres van je huidige website, ik doe de rest.</p>
      </div>
      <div class="archer-intake-log" data-intake-log aria-live="polite"></div>
      <form class="archer-intake-composer" data-intake-form>
        <label class="archer-sr-only" for="archer-intake-input">Bericht aan Archer</label>
        <input id="archer-intake-input" class="archer-intake-input" autocomplete="off" placeholder="Naam van je zaak of www.jouwzaak.be" />
        <button type="submit" class="archer-intake-send" aria-label="Versturen">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
        </button>
      </form>
      <button type="button" class="archer-link-btn archer-intake-legacy" data-intake-legacy>Liever een formulier invullen</button>`;
    start.prepend(root);

    const el = {
      log: $("[data-intake-log]", root),
      form: $("[data-intake-form]", root),
      input: $("#archer-intake-input", root),
      hello: $("[data-intake-hello]", root),
      setup: $("[data-intake-setup]", root),
      chips: $("[data-intake-chips]", root),
      bars: $("[data-intake-bars]", root),
      step: $("[data-intake-step]", root),
    };

    let state = { messages: [], brief: {}, progress: { have: 0, need: 8 }, done: false, summary: [] };
    let busy = false;

    const save = () => {
      try { sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* privémodus */ }
    };
    const load = () => {
      try {
        const s = JSON.parse(sessionStorage.getItem(STORE) || "null");
        if (s && Array.isArray(s.messages) && s.messages.length) state = Object.assign(state, s);
      } catch (e) { /* niets bewaard */ }
    };

    /* ── Weergave ───────────────────────────────────────── */

    const scrollDown = () => {
      const last = el.log.lastElementChild;
      if (last) last.scrollIntoView({ block: "end", behavior: "smooth" });
    };

    function bubbleUser(text) {
      const d = document.createElement("div");
      d.className = "archer-intake-msg is-user";
      d.innerHTML = `<div class="archer-intake-bubble">${esc(text)}</div><span class="archer-intake-meta">Verzonden</span>`;
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
      d.innerHTML = html;
      el.log.appendChild(d);
      if (!isLast || !m.options || !m.options.length) return;

      d.querySelectorAll("[data-opt]").forEach((b) =>
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
      const confirm = d.querySelector("[data-opt-confirm]");
      if (confirm) confirm.addEventListener("click", () => { if (m.picked && m.picked.length) send(m.picked.join(", ")); });
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
      const keys = Object.keys(state.brief).filter((k) => valueText(k, state.brief[k]));
      el.setup.hidden = keys.length === 0;
      el.hello.hidden = state.messages.length > 0;
      const need = state.progress.need || 8;
      const have = Math.min(state.progress.have || 0, need);
      el.bars.innerHTML = Array.from({ length: need }, (_, i) => `<span class="${i < have ? "is-on" : ""}${state.done && i === need - 1 ? " is-last" : ""}"></span>`).join("");
      el.step.textContent = state.done ? "Laatste stap · nakijken" : `${have} van ${need}`;
      const order = CHIP_ORDER.filter((k) => keys.includes(k)).concat(keys.filter((k) => !CHIP_ORDER.includes(k)));
      el.chips.innerHTML = order.map((k) => {
        const v = valueText(k, state.brief[k]);
        return `<button type="button" class="archer-intake-chip" data-edit="${esc(k)}" title="${esc(LABELS[k] || k)} wijzigen">` +
          `<span class="archer-intake-chip-k">${esc(LABELS[k] || k)}</span> ${esc(v.length > 28 ? v.slice(0, 27) + "…" : v)} <span aria-hidden="true">✎</span></button>`;
      }).join("");
      el.chips.querySelectorAll("[data-edit]").forEach((b) =>
        b.addEventListener("click", () => {
          const k = b.getAttribute("data-edit");
          el.input.value = `${LABELS[k] || k} is eigenlijk: `;
          el.input.focus();
          el.input.setSelectionRange(el.input.value.length, el.input.value.length);
        }),
      );
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
        `<div class="archer-intake-actions"><button type="button" class="archer-btn archer-btn-primary" data-intake-go>Maak mijn website →</button></div>` +
        `<div class="archer-status" data-intake-error aria-live="polite"></div>`;
      el.log.appendChild(d);
      $("[data-intake-go]", d).addEventListener("click", generate);
    }

    function renderAll() {
      el.log.innerHTML = "";
      const lastAgent = state.messages.map((m) => m.role).lastIndexOf("agent");
      state.messages.forEach((m, i) => (m.role === "user" ? bubbleUser(m.text) : bubbleAgent(m, i === lastAgent && !state.done)));
      renderSetup();
      renderReview();
      el.input.placeholder = state.messages.length ? "Bericht aan Archer…" : "Naam van je zaak of www.jouwzaak.be";
      scrollDown();
    }

    /* ── Gesprek ────────────────────────────────────────── */

    async function send(text) {
      text = String(text || "").trim();
      if (!text || busy) return;
      busy = true;
      // Eerste bericht dat op een webadres lijkt: meteen als bestaande website in de brief.
      if (!state.messages.length && /^(https?:\/\/)?(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i.test(text)) {
        state.brief.existing_site = text;
      }
      state.messages.push({ role: "user", text });
      state.done = false;
      renderAll();
      typing(true);

      const body = new FormData();
      body.append("action", "aisb_archer_intake_turn");
      body.append("nonce", CFG.nonce);
      body.append("messages", JSON.stringify(state.messages.map((m) => ({ role: m.role, text: m.text }))));
      body.append("brief", JSON.stringify(state.brief));
      try {
        const res = await fetch(CFG.ajaxUrl, { method: "POST", credentials: "same-origin", body });
        const out = await res.json();
        if (!out || !out.success) throw new Error((out && out.data && out.data.message) || "De agent reageerde niet.");
        const d = out.data;
        state.brief = d.brief || state.brief;
        state.progress = d.progress || state.progress;
        state.done = !!d.done;
        state.summary = d.summary || [];
        state.messages.push({ role: "agent", text: d.reply || "Genoteerd.", options: d.options || [], multi: !!d.multi });
      } catch (err) {
        state.messages.push({ role: "agent", text: (err && err.message) || "Er ging iets mis. Probeer het nog eens." });
      } finally {
        typing(false);
        busy = false;
        save();
        renderAll();
        el.input.focus();
      }
    }

    el.form.addEventListener("submit", (e) => {
      e.preventDefault();
      const t = el.input.value;
      el.input.value = "";
      send(t);
    });

    $("[data-intake-legacy]", root).addEventListener("click", () => {
      start.classList.remove("archer-intake-on");
      root.hidden = true;
    });

    /* ── Brief naar generatie ───────────────────────────── */

    function briefPrompt(b) {
      const line = (label, k) => (b[k] && valueText(k, b[k]) ? `${label}: ${valueText(k, b[k])}.` : "");
      return [
        line("Naam van de zaak", "business_name"),
        line("Activiteit", "activity"),
        line("Diensten of producten", "services"),
        line("Doelgroep", "audience"),
        line("Regio", "region"),
        line("Wat een bezoeker moet doen (hoofddoel, de knop in de header)", "main_goal"),
        b.booking ? `Online boeken via: ${b.booking} (externe link of widget, geen agendakoppeling).` : "",
        line("Wat de zaak onderscheidt", "usp"),
        line("Toon van de teksten", "tone"),
        line("Pagina's die zeker moeten", "must_pages"),
        line("Gewenste stijl", "style_mood"),
        line("Kleuren", "brand_colors"),
        line("Voorbeeldsites", "examples"),
        line("Foto's", "photos"),
      ].filter(Boolean).join("\n");
    }

    async function generate() {
      const UI = window.ARCHER_UI;
      const app = window.AISBApp;
      const errEl = $("[data-intake-error]", el.log);
      if (!UI || typeof UI.fastGenerate !== "function" || !app) {
        if (errEl) errEl.innerHTML = '<div class="aisb-error">De generator is niet geladen. Herlaad de pagina.</div>';
        return;
      }
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
      const btn = $("[data-intake-go]", el.log);
      if (btn) { btn.disabled = true; btn.textContent = "Bezig… je website wordt opgezet"; }
      try {
        await UI.fastGenerate(opts);
        const pid = app.state && app.state.projectId;
        if (pid) {
          const body = new FormData();
          body.append("action", "aisb_archer_intake_save");
          body.append("nonce", CFG.nonce);
          body.append("project_id", pid);
          body.append("brief", JSON.stringify(b));
          await fetch(CFG.ajaxUrl, { method: "POST", credentials: "same-origin", body }).catch(() => {});
          if (UI.flushAll) await UI.flushAll();
          try { sessionStorage.removeItem(STORE); } catch (e) { /* niets */ }
          // Opnieuw laden in de stap Ontwerp: stappenbalk en tabs komen van de server.
          const url = new URL(window.location.href);
          url.searchParams.set("aisb_step", "1");
          url.searchParams.set("aisb_project", pid);
          if (app.state.sitemapId) url.searchParams.set("aisb_sitemap", app.state.sitemapId);
          window.location.href = url.toString();
        } else if (btn) {
          btn.disabled = false; btn.textContent = "Maak mijn website →";
        }
      } catch (err) {
        console.error("[Archer] intake-generatie", err);
        if (btn) { btn.disabled = false; btn.textContent = "Maak mijn website →"; }
        if (errEl) errEl.innerHTML = '<div class="aisb-error">Het opzetten lukte niet. Probeer opnieuw.</div>';
      }
    }

    load();
    renderAll();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
