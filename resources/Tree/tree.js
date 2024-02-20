/**
 * JavaScript for the CategoryTree extension.
 *
 * © 2006 Daniel Kinzler
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Daniel Kinzler, brightbyte.de
 * @author thomas-topway-it for K-MA (search-input)
 */

console.log('a')
( function () {
	var loadChildren,
		config = require( './data.json' );
		
		console.log('config',config)

config = {defaultCtOptions: {} };

	/**
	 * Expands a given node (loading it's children if not loaded)
	 *
	 * @param {jQuery} $link
	 */
	function expandNode( $link ) {
		// Show the children node
		var $children = $link.parents( '.CategoryTreeItem' )
			.siblings( '.CategoryTreeChildren' )
			.css( 'display', '' );

		$link.attr( {
			title: mw.msg( 'categorytree-collapse' ),
			'data-ct-state': 'expanded'
		} );

		if ( !$link.data( 'ct-loaded' ) ) {
			loadChildren( $link, $children );
		}
	}

	/**
	 * Collapses a node
	 *
	 * @param {jQuery} $link
	 */
	function collapseNode( $link ) {
		// Hide the children node
		$link.parents( '.CategoryTreeItem' )
			.siblings( '.CategoryTreeChildren' )
			.css( 'display', 'none' );

		$link.attr( {
			title: mw.msg( 'categorytree-expand' ),
			'data-ct-state': 'collapsed'
		} );
	}

	/**
	 * Handles clicks on the expand buttons, and calls the appropriate function
	 *
	 * @this {Element} CategoryTreeToggle
	 */
	function handleNode() {
		var $link = $( this );
		if ( $link.attr( 'data-ct-state' ) === 'collapsed' ) {
			expandNode( $link );
		} else {
			collapseNode( $link );
		}
	}

	/**
	 * Attach click handler to buttons
	 *
	 * @param {jQuery} $content
	 */
	function attachHandler( $content ) {
		$content.find( '.CategoryTreeToggle' )
			.on( 'click', handleNode )
			.attr( 'title', function () {
				return mw.msg(
					$( this ).attr( 'data-ct-state' ) === 'collapsed' ?
						'categorytree-expand' :
						'categorytree-collapse'
				);
			} )
			.addClass( 'CategoryTreeToggleHandlerAttached' );
	}

	/**
	 * Loads children for a node via an HTTP call
	 *
	 * @param {jQuery} $link
	 * @param {jQuery} $children
	 */
	loadChildren = function ( $link, $children ) {
	
	
		var $linkParentCTTag, ctTitle, ctMode, ctOptions;

		/**
		 * Error callback
		 */
		function error() {
			var $retryLink;

			$retryLink = $( '<a>' )
				.text( mw.msg( 'categorytree-retry' ) )
				.attr( {
					role: 'button',
					tabindex: 0
				} )
				.on( 'click keypress', function ( e ) {
					if (
						e.type === 'click' ||
						e.type === 'keypress' && e.which === 13
					) {
						loadChildren( $link, $children );
					}
				} );

			$children
				.text( mw.msg( 'categorytree-error' ) + ' ' )
				.append( $retryLink );
		}

		$link.data( 'ct-loaded', true );

		$children.empty().append(
			$( '<i>' )
				.addClass( 'CategoryTreeNotice' )
				.text( mw.msg( 'subpagenavigation-tree-loading' ) )
		);

		$linkParentCTTag = $link.parents( '.CategoryTreeTag' );

		// Element may not have a .CategoryTreeTag parent, fallback to defauls
		// Probably a CategoryPage (@todo: based on what?)
		ctTitle = $link.attr( 'data-ct-title' );
		ctMode = $linkParentCTTag.data( 'ct-mode' );
		ctMode = typeof ctMode === 'number' ? ctMode : undefined;
		
		console.log('ctOptions',$linkParentCTTag.attr( 'data-ct-options' ))
		
		ctOptions = $linkParentCTTag.attr( 'data-ct-options' ) || config.defaultCtOptions;


		console.log('ctOptions',ctOptions)


		// Mode and options have defaults or fallbacks, title does not.
		// Don't make a request if there is no title.
		if ( !ctTitle ) {
			error();
			return;
		}
		
		console.log('ctTitle', ctTitle)
		console.log('options', ctOptions)

		new mw.Api().get( {
			action: 'subpagenavigation-tree',
			category: ctTitle,
			options: ctOptions,
			uselang: mw.config.get( 'wgUserLanguage' ),
			formatversion: 2
		} ).done( function ( data ) {
			var $data;
		console.log('data', data)

			data = data['subpagenavigation-tree'].html;

			if ( data === '' ) {
				$data = $( '<i>' ).addClass( 'CategoryTreeNotice' )
					// eslint-disable-next-line mediawiki/msg-doc
					.text( mw.msg( {
						0: 'subpagenavigation-tree-no-subcategories',
						10: 'subpagenavigation-tree-no-pages',
						100: 'subpagenavigation-tree-no-parent-categories'
					}[ ctMode ] || 'subpagenavigation-tree-nothing-found' ) );
			} else {
				$data = $( $.parseHTML( data ) );
				attachHandler( $data );
			}

			$children.empty().append( $data );
		} ).fail( error );
	};

	// Register click events
	// mw.hook( 'wikipage.content' ).add( attachHandler );

	// Attach click handler for categories.
	// This is needed when wgCategoryTreeHijackPageCategories is enabled.
	mw.hook( 'wikipage.categories' ).add( attachHandler );

	$( function () {
		// Attach click handler for sidebar
		// eslint-disable-next-line no-jquery/no-global-selector
		// attachHandler( $( '#p-categorytree-portlet' ) );
		
		attachHandler( $( '#subpagenavigation-tree' ) );
	} );
	
	
	function initToc( tocNode ) {
		var hidden = false,
			toggleNode = tocNode.querySelector( '.toctogglecheckbox' );
			
			console.log('toggleNode',toggleNode)

		if ( !toggleNode ) {
			return;
		}

		toggleNode.addEventListener( 'change', function () {
			hidden = !hidden;
			mw.cookie.set( 'hidetoc', hidden ? '1' : null );
		} );

		// Initial state
		if ( mw.cookie.get( 'hidetoc' ) === '1' ) {
			toggleNode.checked = true;
			hidden = true;
		}
	}


initToc( $( '#subpagenavigation-tree' ).get(0) );

/*
	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		var tocs = $content[ 0 ] ? $content[ 0 ].querySelectorAll( '.toc' ) : [],
			i = tocs.length;
		while ( i-- ) {
			initToc( tocs[ i ] );
		}
	} );
*/
	

}() );
