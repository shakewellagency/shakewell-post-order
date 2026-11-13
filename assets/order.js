( function ( $ ) {
	'use strict';

	var cfg = window.shakewellPostOrder;
	var list = $( '#the-list' );
	var rowSelector = 'tr[id^="post-"]:not(.inline-edit-row)';
	var busy = false;
	var queued = false;

	function rows() {
		return list.children( rowSelector );
	}

	// One request at a time: a save that arrives while another runs is replayed afterwards with the latest order.
	function save( position ) {
		if ( busy ) {
			queued = { position: position };
			return;
		}
		busy = true;
		var ids = rows().map( function () {
			return this.id.replace( 'post-', '' );
		} ).get();
		$.post( cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, offset: cfg.offset, ids: ids } )
			.done( function () {
				window.wp.a11y.speak( position ? cfg.moved.replace( '%d', position ) : cfg.saved );
			} )
			.fail( function () {
				window.wp.a11y.speak( cfg.failed, 'assertive' );
			} )
			.always( function () {
				busy = false;
				if ( queued ) {
					var next = queued;
					queued = false;
					save( next.position );
				}
			} );
	}

	list.sortable( {
		items: rowSelector,
		axis: 'y',
		cancel: 'a, button, input, select, textarea',
		update: function () {
			save();
		}
	} );

	list.on( 'click', '.shakewell-post-order-move', function () {
		var up = $( this ).data( 'dir' ) < 0;
		var row = $( this ).closest( 'tr' );
		var target = up ? row.prevAll( rowSelector ).first() : row.nextAll( rowSelector ).first();
		if ( ! target.length ) {
			return;
		}
		if ( up ) {
			row.insertBefore( target );
		} else {
			row.insertAfter( target );
		}
		$( this ).trigger( 'focus' );
		save( Number( cfg.offset ) + rows().index( row ) + 1 );
	} );
}( jQuery ) );
