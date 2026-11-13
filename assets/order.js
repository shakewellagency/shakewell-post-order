( function ( $ ) {
	'use strict';

	var cfg = window.shakewellPostOrder;
	var list = $( '#the-list' );
	var rowSelector = 'tr[id^="post-"]:not(.inline-edit-row)';
	var busy = false;
	var queued = false;
	var parentOf = {}; // post id => parent id, as strings; every flat row has parent '0'.

	function rows() {
		return list.children( rowSelector );
	}

	function idOf( row ) {
		return String( $( row ).attr( 'id' ).replace( 'post-', '' ) );
	}

	rows().each( function () {
		var button = $( this ).find( '.shakewell-post-order-move' ).first();
		parentOf[ idOf( this ) ] = cfg.groups && button.length ? String( button.data( 'parent' ) ) : '0';
	} );

	// True when row sits at or below the post with id ancestor in the tree.
	function isDescendant( row, ancestor ) {
		var p = parentOf[ idOf( row ) ];
		while ( p && '0' !== p ) {
			if ( p === ancestor ) {
				return true;
			}
			p = parentOf[ p ];
		}
		return false;
	}

	// The row followed by the rows nested under it (hierarchical lists show them straight after it).
	function block( row ) {
		var id = idOf( row );
		return $( row ).add( $( row ).nextAll( rowSelector ).filter( function () {
			return isDescendant( this, id );
		} ) );
	}

	function group( parent ) {
		return rows().filter( function () {
			return parentOf[ idOf( this ) ] === parent;
		} );
	}

	// A group is complete when every sibling is on this page; only then can its order be saved safely.
	function isComplete( parent ) {
		return ! cfg.groups || group( parent ).length === Number( cfg.groups[ parent ] );
	}

	// Moves must keep every sibling's subtree together and stay between the first and last sibling.
	function isLegal( row ) {
		var parent = parentOf[ idOf( row ) ];
		var members = group( parent );
		var all = rows();
		var from = all.index( members.first() );
		var to = all.index( members.last() ) + block( members.last() ).length - 1;
		return all.slice( from, to + 1 ).filter( function () {
			var x = idOf( this );
			while ( x && '0' !== x ) {
				if ( parentOf[ x ] === parent ) {
					return false;
				}
				x = parentOf[ x ];
			}
			return true;
		} ).length === 0;
	}

	// One request at a time: a save requested while one is in flight is replayed afterwards with the latest order.
	function save( parent, position ) {
		if ( busy ) {
			queued = { parent: parent, position: position };
			return;
		}
		busy = true;
		var ids = group( parent ).map( function () {
			return idOf( this );
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
					save( next.parent, next.position );
				}
			} );
	}

	// Lock rows whose siblings are not all on this page.
	var locked = false;
	rows().each( function () {
		if ( ! isComplete( parentOf[ idOf( this ) ] ) ) {
			locked = true;
			$( this ).addClass( 'shakewell-post-order-locked' ).find( '.shakewell-post-order-move' ).prop( 'disabled', true );
		}
	} );
	if ( locked ) {
		$( '.wp-header-end' ).after( $( '<div class="notice notice-info"></div>' ).append( $( '<p></p>' ).text( cfg.partial ) ) );
	}

	var dragged = null;
	var origin = null;

	list.sortable( {
		items: rowSelector,
		axis: 'y',
		cancel: 'a, button, input, select, textarea, .shakewell-post-order-locked',
		start: function ( e, ui ) {
			origin = ui.item.prev();
			dragged = block( ui.item[ 0 ] ).not( ui.item[ 0 ] ).hide();
			list.sortable( 'refreshPositions' );
		},
		stop: function ( e, ui ) {
			ui.item.after( dragged );
			dragged.show();
			dragged = null;
			if ( isLegal( ui.item[ 0 ] ) ) {
				save( parentOf[ idOf( ui.item[ 0 ] ) ] );
				return;
			}
			var back = block( ui.item[ 0 ] );
			if ( origin.length ) {
				origin.after( back );
			} else {
				list.prepend( back );
			}
			window.wp.a11y.speak( cfg.illegal, 'assertive' );
		}
	} );

	list.on( 'click', '.shakewell-post-order-move', function () {
		var up = $( this ).data( 'dir' ) < 0;
		var row = $( this ).closest( 'tr' );
		var siblings = group( parentOf[ idOf( row ) ] );
		var target = siblings.eq( siblings.index( row ) + ( up ? -1 : 1 ) );
		if ( ! target.length ) {
			return;
		}
		if ( up ) {
			target.before( block( row[ 0 ] ) );
		} else {
			block( target[ 0 ] ).last().after( block( row[ 0 ] ) );
		}
		$( this ).trigger( 'focus' );
		save( parentOf[ idOf( row ) ], Number( cfg.offset ) + group( parentOf[ idOf( row ) ] ).index( row ) + 1 );
	} );
}( jQuery ) );
