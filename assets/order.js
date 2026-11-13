( function ( $ ) {
	'use strict';

	var cfg = window.shakewellPostOrder;
	var list = $( '#the-list' );

	function save() {
		var ids = list.children( 'tr' ).map( function () {
			return this.id.replace( 'post-', '' );
		} ).get();
		return $.post( cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, offset: cfg.offset, ids: ids } )
			.done( function () {
				window.wp.a11y.speak( cfg.saved );
			} )
			.fail( function () {
				window.wp.a11y.speak( cfg.failed, 'assertive' );
			} );
	}

	list.sortable( {
		items: '> tr',
		axis: 'y',
		cancel: 'a, button, input, select, textarea',
		update: save
	} );

	list.on( 'click', '.shakewell-post-order-move', function () {
		var row = $( this ).closest( 'tr' );
		var target = $( this ).data( 'dir' ) < 0 ? row.prev( 'tr' ) : row.next( 'tr' );
		if ( ! target.length ) {
			return;
		}
		if ( $( this ).data( 'dir' ) < 0 ) {
			row.insertBefore( target );
		} else {
			row.insertAfter( target );
		}
		$( this ).trigger( 'focus' );
		save().done( function () {
			window.wp.a11y.speak( cfg.moved.replace( '%d', cfg.offset + row.index() + 1 ) );
		} );
	} );
}( jQuery ) );
