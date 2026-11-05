# Blueprint-plugins

Plugins die op de InstaWP-blueprint (archer-blueprint.instawp.site) staan en via de snapshot "AI Snapshot" op elke gepubliceerde site komen.
Ze staan hier buiten wp-content, zodat ze niet meedraaien op staging.

- archer-content-types: eigen inhoudstypes (realisaties, projecten, wagens ...) met velden, categorieën, Bricks-tags {act_veld} en een REST API voor de builder.
- archer-cookie-consent: cookiemelding (CookieConsent v3) met Consent Mode v2 en bewijs van toestemming; volgt de taal van de pagina.

Na een wijziging: plugin bijwerken op de blueprint en daarna de snapshot "AI Snapshot" opnieuw vastleggen in InstaWP.
