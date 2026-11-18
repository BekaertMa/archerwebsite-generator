// font-stack.js - Terugvalletters achter een webfont, in de browser.
//
// Dezelfde beslissing als AISB_Archer_Styles::stack() op de server: schreefloos, schreef of mono.
// De namen van de schreef- en monoletters komen van daar mee via wp_localize_script (AISB_FONTS),
// zodat de lijst op een plek staat. Wie er geen meekrijgt, valt terug op de naam van de letter.
//
// Nodig omdat drie lagen de letter zetten: het thema van de stijl (--a-font-heading), de
// stijlgidsoverride (core.js, met !important) en de eigen keuze in het scherm Ontwerp (archer.js).
// Kozen die alle drie hun eigen terugval, dan kreeg Vollkorn een schreefloze reserve.
(function () {
  "use strict";
  var D = window.AISB_FONTS || {};
  var serif = D.serif || [];
  var mono = D.mono || [];
  window.AISB_fontStack = function (family) {
    family = String(family || "").trim();
    if (!family) return "";
    var q = "'" + family + "'";
    if (mono.indexOf(family) !== -1 || /\bmono\b/i.test(family)) {
      return q + ",ui-monospace,SFMono-Regular,Menlo,monospace";
    }
    if (serif.indexOf(family) !== -1
      || (/serif|garamond|cormorant|fraunces|caslon|bodoni|didone|slab/i.test(family) && !/sans/i.test(family))) {
      return q + ",ui-serif,Georgia,serif";
    }
    return q + ",ui-sans-serif,system-ui,sans-serif";
  };
})();
