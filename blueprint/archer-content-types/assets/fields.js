/**
 * Archer Content Types: image and gallery fields on the edit screen.
 */
( function ( $ ) {
	'use strict';

	var t = window.archerCtFields || {};

	function sync( box ) {
		var ids = $( box ).find( '.archer-ct-media__list li' ).map( function () {
			return $( this ).data( 'id' );
		} ).get();
		$( box ).find( 'input[type=hidden]' ).val( ids.join( ',' ) );
	}

	$( document ).on( 'click', '.archer-ct-media__add', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '.archer-ct-media' );
		var multiple = '1' === String( box.data( 'multiple' ) );
		var frame = wp.media( {
			title: multiple ? t.chooseImages : t.chooseImage,
			button: { text: t.use },
			library: { type: 'image' },
			multiple: multiple ? 'add' : false
		} );

		frame.on( 'select', function () {
			var list = box.find( '.archer-ct-media__list' );
			if ( ! multiple ) {
				list.empty();
			}
			frame.state().get( 'selection' ).each( function ( item ) {
				var a = item.toJSON();
				if ( list.find( 'li[data-id="' + a.id + '"]' ).length ) {
					return;
				}
				var src = ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url );
				var li = $( '<li/>' ).attr( 'data-id', a.id );
				li.append( $( '<img/>' ).attr( { src: src, alt: '' } ) );
				li.append( $( '<button type="button" class="archer-ct-media__remove">×</button>' ) );
				list.append( li );
			} );
			sync( box );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.archer-ct-media__remove', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '.archer-ct-media' );
		$( this ).closest( 'li' ).remove();
		sync( box );
	} );

	$( function () {
		if ( $.fn.sortable ) {
			$( '.archer-ct-media[data-multiple="1"] .archer-ct-media__list' ).sortable( {
				update: function () {
					sync( $( this ).closest( '.archer-ct-media' ) );
				}
			} );
		}
	} );
}( jQuery ) );
