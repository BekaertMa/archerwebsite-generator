/**
 * Archer Cookie Consent — front-end runtime.
 *
 * Wires the bundled CookieConsent v3 instance to Google Consent Mode v2 and to
 * the proof-of-consent endpoint. No third-party requests are made from here.
 *
 * @package Archer_Cookie_Consent
 */
( function () {
	'use strict';

	var data = window.archerCcData;

	if ( ! data || ! data.config || 'undefined' === typeof window.CookieConsent ) {
		return;
	}

	var signalMap = ( data.consentMode && data.consentMode.signalMap ) || {};
	var cmEnabled = !! ( data.consentMode && data.consentMode.enabled );

	/**
	 * Ensure a gtag() function exists even when no Google tag is present, so the
	 * update call never throws.
	 *
	 * @return {Function} gtag
	 */
	function gtag() {
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( arguments );
	}

	if ( cmEnabled && 'function' !== typeof window.gtag ) {
		window.gtag = gtag;
	}

	/**
	 * Push the current consent state to Google Consent Mode.
	 */
	function pushConsentMode() {
		if ( ! cmEnabled ) {
			return;
		}

		var update = {};
		var category;
		var signals;
		var i;

		for ( category in signalMap ) {
			if ( ! Object.prototype.hasOwnProperty.call( signalMap, category ) ) {
				continue;
			}

			signals = signalMap[ category ];

			var granted = window.CookieConsent.acceptedCategory( category ) ? 'granted' : 'denied';

			for ( i = 0; i < signals.length; i++ ) {
				update[ signals[ i ] ] = granted;
			}
		}

		update.security_storage = 'granted';

		( window.gtag || gtag )( 'consent', 'update', update );

		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( {
			event: 'archer_cc_consent_update',
			archer_cc_accepted: ( window.CookieConsent.getUserPreferences().acceptedCategories || [] ).join( ',' )
		} );
	}

	/**
	 * Send one consent record to the REST endpoint.
	 *
	 * @param {string} action Either "first_consent" or "change".
	 */
	function logConsent( action ) {
		if ( ! data.log || ! data.log.enabled || ! window.fetch ) {
			return;
		}

		var cookie = window.CookieConsent.getCookie() || {};
		var prefs = window.CookieConsent.getUserPreferences() || {};

		var body = {
			token: data.log.token,
			consent_id: cookie.consentId || '',
			action: action,
			accepted: prefs.acceptedCategories || [],
			rejected: prefs.rejectedCategories || [],
			revision: cookie.revision || 0,
			language: resolvedLanguage(),
			url: window.location.href
		};

		if ( ! body.consent_id ) {
			return;
		}

		// No X-WP-Nonce: the day-token in the payload is the authorisation, and a
		// stale nonce would make WordPress reject the request outright.
		window.fetch( data.log.url, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body )
		} ).catch( function () {
			// Logging is evidence, not functionality: a failure must never block
			// the visitor's choice from taking effect.
		} );
	}

	var config = data.config;

	/**
	 * Turn the serialised autoClear entries into the shape the library expects.
	 *
	 * A pattern that starts with "^" becomes a RegExp; everything else stays an
	 * exact cookie name. Regexes cannot survive JSON, hence the conversion here.
	 */
	( function convertAutoClear() {
		var categories = config.categories || {};
		var name;
		var entry;

		for ( name in categories ) {
			if ( ! Object.prototype.hasOwnProperty.call( categories, name ) ) {
				continue;
			}

			entry = categories[ name ];

			if ( ! entry.autoClear || ! entry.autoClear.cookies ) {
				continue;
			}

			entry.autoClear.cookies = entry.autoClear.cookies.map( function ( pattern ) {
				if ( 0 === String( pattern ).indexOf( '^' ) ) {
					try {
						return { name: new RegExp( pattern ) };
					} catch ( e ) {
						return { name: String( pattern ) };
					}
				}

				return { name: String( pattern ) };
			} );
		}
	}() );

	/**
	 * Work out which translation the visitor is actually being shown, so the
	 * consent record reflects the banner they saw rather than the site default.
	 *
	 * @return {string} Language code.
	 */
	function resolvedLanguage() {
		var lang = config.language || {};
		var available = Object.keys( lang.translations || {} );
		var candidate = '';

		if ( 'browser' === lang.autoDetect ) {
			candidate = ( window.navigator.language || '' ).toLowerCase();
		} else if ( 'document' === lang.autoDetect ) {
			candidate = ( document.documentElement.lang || '' ).toLowerCase();
		}

		if ( candidate ) {
			// Exact match first, then the base subtag ("nl-BE" -> "nl").
			if ( -1 !== available.indexOf( candidate ) ) {
				return candidate;
			}

			var base = candidate.split( '-' )[ 0 ];

			if ( -1 !== available.indexOf( base ) ) {
				return base;
			}
		}

		return lang.default || '';
	}

	config.onFirstConsent = function () {
		pushConsentMode();
		logConsent( 'first_consent' );
	};

	config.onConsent = function () {
		// Fires on every page load once consent exists — keeps Consent Mode in
		// sync without writing a new log row.
		pushConsentMode();
	};

	config.onChange = function () {
		pushConsentMode();
		logConsent( 'change' );
	};

	window.CookieConsent.run( config );

	/**
	 * Public helper so themes can open the preferences modal from custom markup.
	 */
	window.archerCcShowPreferences = function () {
		window.CookieConsent.showPreferences();
	};
}() );
