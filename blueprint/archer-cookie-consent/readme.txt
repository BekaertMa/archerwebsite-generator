=== Archer Cookie Consent ===
Contributors: archerwebsites
Tags: cookies, gdpr, consent, cookie banner, consent mode
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

EU/GDPR cookie consent banner built on CookieConsent v3, with prior blocking, Google Consent Mode v2, proof-of-consent logging and a full colour theme editor.

== Description ==

A cookie banner that starts from what EU law actually requires rather than from what looks tidy.

* **Nothing runs before consent.** Non-necessary scripts are rewritten to inert `type="text/plain"` tags and only activated once the matching category is granted. The CookieConsent library and its stylesheet are bundled locally, so no third party sees a visitor IP before a choice is made.
* **Reject sits on the first layer**, next to accept, with equal weight. This is not configurable away.
* **Nothing is pre-ticked.** Only the strictly-necessary category is on by default, and it is read-only because it relies on the ePrivacy Art. 5(3) exemption rather than on consent.
* **Withdrawal is one click**, via a permanently reachable control or the `[archer_cc_preferences]` shortcode.
* **Google Consent Mode v2** is emitted before any Google tag, with every signal denied except `security_storage`, then updated the moment the visitor decides. Choose advanced (cookieless pings, conversion modelling) or basic (nothing from Google loads at all before consent). An `AW-`/`DC-` id is gated behind marketing rather than statistics, because an Ads or Floodlight tag is not a measurement tag.
* **Cookies are actually erased** when a category is refused or withdrawn, driven by an editable `category|cookie` list that ships with sensible patterns for Google, Meta, Microsoft, TikTok, LinkedIn, Hotjar and Clarity.
* **Proof of consent.** Every action is recorded with a timestamp, the categories chosen, the policy revision, the language and a salted hash of the IP — never the address itself. Records are purged automatically after the retention period and can be exported as CSV.
* **Full colour control.** Separate light and dark palettes, 20 colour pickers each, plus radii, font stack and overlay opacity. Saving runs a WCAG contrast audit and warns you when a palette makes the reject button less legible than accept — the most-cited dark pattern in EU enforcement.
* **English, Dutch and French** out of the box, detected from the visitor's browser, with per-language overrides for the banner title, text and policy URL.

= Shortcodes =

* `[archer_cc_preferences label="Cookie settings" class="" tag="button"]` — reopens the preferences modal.
* `[archer_cc_cookie_policy lang="nl"]` — a purpose and legal-basis table for your cookie policy page.

= Blocking your own scripts =

Enqueued scripts: add `handle|category` lines under **Settings → Cookie Consent → Google & scripts**.

In PHP:

`add_filter( 'archer_cc_block_script', function ( $category, $handle ) {
	return 'my-pixel' === $handle ? 'marketing' : $category;
}, 10, 2 );`

In a template:

`<script <?php echo archer_cc_blocked_script_atts( 'marketing' ); ?>>
	/* only runs after marketing consent */
</script>`

Blocked scripts are tagged with `data-category` only, never `data-service`. That is deliberate: in CookieConsent v3 a script carrying a service name can only be activated by that specific service being accepted, and a visitor who consented before the service existed has no record of it — so a service name would strand the script for your existing audience until you bump the policy revision.

= Filters =

* `archer_cc_default_settings` — change the shipped defaults.
* `archer_cc_sanitized_settings` — inspect or adjust values on save.
* `archer_cc_config` — modify the CookieConsent configuration object.
* `archer_cc_banner_strings` — replace or extend the banner copy.
* `archer_cc_consent_mode_signal_map` — remap categories to Consent Mode signals.
* `archer_cc_block_script` — block a script handle.
* `archer_cc_should_render` — suppress the banner on specific requests.

== Frequently Asked Questions ==

= Does this make my site compliant on its own? =

No, and no plugin can. It gives you a technically correct consent layer: prior blocking, granular opt-in, an equally prominent reject, easy withdrawal and a consent record. You still have to classify your own cookies honestly, write an accurate cookie policy, and have a lawful basis for every processing activity behind the tags. This plugin is not legal advice.

= Does it work with page caching? =

Yes. The banner is rendered client-side and the consent state lives in a first-party cookie, so cached HTML is fine. The consent-log endpoint is authorised by a token that rotates daily rather than a per-session nonce, precisely so it survives full-page caching.

= Which CookieConsent version is bundled? =

v3.1.0 by Orest Bida, MIT licensed, unmodified. See `vendor/cookieconsent/LICENSE`.

= Where is the consent log stored? =

In a custom table, `{prefix}archer_cc_consent_log`. It is kept on deactivation and only dropped on uninstall if you explicitly enabled "Delete all data on uninstall".

== Changelog ==

= 1.1.0 =
* The banner and the "Cookie settings" control follow the language of the page (WordPress locale, also per page with WPML/Polylang) instead of the visitor's browser.
* The floating control and the [archer_cc_preferences] shortcode are labelled in the page language: "Cookie-instellingen", "Paramètres cookies", "Cookie settings".
* New defaults: language detection "document", default language = site language. Existing 1.0.0 settings are migrated once.
* New filters: archer_cc_page_language, archer_cc_trigger_label.

= 1.0.0 =
* Initial release.
