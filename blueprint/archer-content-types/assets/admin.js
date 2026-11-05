/**
 * Archer Content Types: settings screen (rows add/remove, keys from labels).
 */
( function () {
	'use strict';

	var form = document.querySelector( '.archer-ct-form' );

	function keyFrom( text, max ) {
		return String( text || '' )
			.replace( /['’`]/g, '' )
			.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' )
			.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' )
			.slice( 0, max );
	}

	function slugFrom( text ) {
		return keyFrom( text, 60 ).replace( /_/g, '-' );
	}

	// A key follows its label until someone types in the key field itself.
	function follow( source, target, fn ) {
		if ( ! source || ! target || target.readOnly ) {
			return;
		}
		target.dataset.auto = target.value === '' || target.value === fn( source.value ) ? '1' : '';
		source.addEventListener( 'input', function () {
			if ( target.dataset.auto ) {
				target.value = fn( source.value );
			}
		} );
		target.addEventListener( 'input', function () {
			target.dataset.auto = target.value === '' ? '1' : '';
		} );
	}

	function wireRow( row, max ) {
		// Category keys are left empty: the server prefixes them with the type
		// ("wagen_merk"), so two types can both have a "Merk" list.
		if ( 40 === max ) {
			follow( row.querySelector( '.archer-ct-label' ), row.querySelector( '.archer-ct-key' ), function ( v ) {
				return keyFrom( v, max );
			} );
		}
		var type = row.querySelector( '.archer-ct-type' );
		var options = row.querySelector( '.archer-ct-options' );
		if ( type && options ) {
			type.addEventListener( 'change', function () {
				options.hidden = 'select' !== type.value;
			} );
		}
	}

	if ( form ) {
		follow( form.querySelector( '#act-singular' ), form.querySelector( '#act-key' ), function ( v ) {
			return keyFrom( v, 20 );
		} );
		follow( form.querySelector( '#act-plural' ), form.querySelector( '#act-slug' ), slugFrom );

		form.querySelectorAll( '.archer-ct-repeater' ).forEach( function ( table ) {
			var max = 'fields' === table.dataset.repeater ? 40 : 32;
			table.querySelectorAll( 'tbody .archer-ct-row' ).forEach( function ( row ) {
				wireRow( row, max );
			} );
		} );

		form.addEventListener( 'click', function ( e ) {
			var add = e.target.closest( '.archer-ct-add' );
			if ( add ) {
				var name = add.dataset.target;
				var tpl = document.getElementById( 'archer-ct-tpl-' + name );
				var body = form.querySelector( '[data-repeater="' + name + '"] tbody' );
				var index = Date.now() + '' + body.children.length;
				var wrap = document.createElement( 'tbody' );
				wrap.innerHTML = tpl.innerHTML.replace( /__i__/g, index );
				var row = wrap.firstElementChild;
				body.appendChild( row );
				wireRow( row, 'fields' === name ? 40 : 32 );
				var first = row.querySelector( 'input' );
				if ( first ) {
					first.focus();
				}
				return;
			}
			var remove = e.target.closest( '.archer-ct-remove' );
			if ( remove ) {
				remove.closest( 'tr' ).remove();
			}
		} );
	}

	document.querySelectorAll( '.archer-ct-delete' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( ( window.archerCtAdmin && window.archerCtAdmin.confirmDelete ) || 'OK?' ) ) {
				e.preventDefault();
			}
		} );
	} );
}() );
