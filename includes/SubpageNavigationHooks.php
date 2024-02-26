<?php
/**
 * This file is part of the MediaWiki extension SubpageNavigation.
 *
 * SubpageNavigation is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * SubpageNavigation is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with SubpageNavigation.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2023-2024, https://wikisphere.org
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\SubpageNavigation\Tree as SubpageNavigationTree;

class SubpageNavigationHooks {

	/**
	 * @param MediaWikiServices $services
	 * @return void
	 */
	public static function onMediaWikiServices( $services ) { }

	public static function onRegistration() {
		// $GLOBALS['wgwgNamespacesWithSubpages'][NS_MAIN] = false;
	}

	/**
	 * @param Title &$title
	 * @param null $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki|MediaWiki\Actions\ActionEntryPoint $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize( \Title &$title, $unused, \OutputPage $output, \User $user, \WebRequest $request, $mediaWiki ) {
		\SubpageNavigation::initialize( $user );
	}

	/**
	 * @see https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/Translate/+/394f20034b62f5b1ddfb7ac7d31c5d7ff3e3b253/src/PageTranslation/Hooks.php
	 * @param Article &$article
	 * @param bool|ParserOutput|null &$outputDone
	 * @param bool &$pcache
	 * @return void
	 */
	public static function onArticleViewHeader( Article &$article, &$outputDone, bool &$pcache ) {
		// *** this is used by the Translate extension
		// *** to display the "translate" link,
		// *** we use onBeforePageDisplay OutputPage -> prependHTML instead
	}

	/**
	 * @param string &$subpages
	 * @param Skin $skin
	 * @param OutputPage $out
	 * @return void|bool
	 */
	public static function onSkinSubPageSubtitle( &$subpages, $skin, $out ) {
		if ( \SubpageNavigation::breadcrumbIsEnabled( $skin ) ) {
			return false;
		}
	}

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( OutputPage $outputPage, Skin $skin ) {
		global $wgResourceBasePath;

		$title = $outputPage->getTitle();

		// with vector-2022 skin unfortunately
		// there is no way to place indicators on top
		// @see SkinVector -> isLanguagesInContentAt and ContentHeader.mustache
		if ( \SubpageNavigation::breadcrumbIsEnabled( $skin ) ) {
			$breadCrumb = \SubpageNavigation::breadCrumbNavigation( $title );
			if ( $breadCrumb !== false ) {
				$outputPage->setIndicators( [
					// *** id = mw-indicator-subpage-navigation
					'subpage-navigation' => $breadCrumb
				] );
			}
		}

		// used by WikidataPageBanner to place the banner
		// $outputPage->addSubtitle( 'addSubtitle' );

		\SubpageNavigation::addHeaditem( $outputPage, [
			[ 'stylesheet', $wgResourceBasePath . '/extensions/SubpageNavigation/resources/style.css' ],
		] );

		if ( $title->isSpecialPage() ) {
			return;
		}

		if ( !empty( $GLOBALS['wgSubpageNavigationShowTree'] ) ) {
			SubpageNavigationTree::setHeaders( $outputPage );
		}

		if ( !empty( $_REQUEST['action'] ) && $_REQUEST['action'] !== 'view' ) {
			return;
		}

		$outputPage->addModules( [ 'ext.SubpageNavigationSubpages' ] );

		// *** this is rendered after than onArticleViewHeader
		$outputPage->prependHTML( \SubpageNavigation::getSubpageHeader( $title ) );

		if ( \SubpageNavigation::breadcrumbIsEnabled( $skin ) ) {
			$titleText = $outputPage->getPageTitle();

			if ( \SubpageNavigation::parseSubpage( $titleText, $current ) ) {
				$outputPage->setPageTitle( $current );
			}
		}
	}

	/**
	 * @param OutputPage $out
	 *
	 * @return true
	 */
	public static function onAfterFinalPageOutput( OutputPage $output ) {
		if ( empty( $GLOBALS['wgSubpageNavigationShowTree'] ) ) {
			return;
		}

		$title = $output->getTitle();

		if ( $title->isSpecialPage() ) {
			return;
		}
	
		$html = ob_get_clean();
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$parentDiv = $dom->getElementById( 'mw-panel' );

		if ( !$parentDiv ) {
			$out = $dom->saveHTML();
			ob_start();
			echo $out;
			return true;
		}

		$context = RequestContext::getMain();

		$options = [];
		$tree = new SubpageNavigationTree( $options );
		$treeHtml = $tree->getTree( $output );

		// this creates a MW's TOC like toggle
		$treeHtml = SubpageNavigationTree::tocList( $treeHtml );

		// *** the following is an hack for the Vector skin to
		// add the tree above the menu items, without using javascript

		$children = $parentDiv->childNodes;

		if ( $children->length > 1 ) {
        	$wrapperDiv = $dom->createElement( 'div' );
        	$wrapperDiv->setAttribute( 'id', 'subpagenavigation-mw-portlets' );
      
			for ( $i = 2; $i < $children->length; $i++ ) {
            	$wrapperDiv->appendChild( $children->item( $i )->cloneNode( true ) );
			}

			while ( $parentDiv->childNodes->length > 2 ) {
				$parentDiv->removeChild( $parentDiv->childNodes->item( 2 ) );
			}

			$fragment = $dom->createDocumentFragment();
			$fragment->appendXML( $treeHtml );
        	$treeContainer = $dom->createElement( 'div' );
			$treeContainer->setAttribute( 'id', 'subpagenavigation-tree' );
			$treeContainer->appendChild( $fragment );

			$container = $dom->createElement( 'div' );
			$container->setAttribute( 'id', 'subpagenavigation-tree-container' );

			$container->appendChild( $treeContainer );
			$container->appendChild( $wrapperDiv );

			$parentDiv->appendChild( $container );
		}

		$out = $dom->saveHTML();
		ob_start();
		echo $out;
		return true;
	}

	/**
	 * @param Skin $skin
	 * @param array &$sidebar
	 * @return void
	 */
	public static function onSidebarBeforeOutput( $skin, &$sidebar ) {
		if ( !empty( $GLOBALS['wgSubpageNavigationDisableSidebarLink'] ) ) {
			return;
		}

		$specialpage_title = SpecialPage::getTitleFor( 'SubpageNavigationBrowse' );
		$sidebar['TOOLBOX'][] = [
			'text'   => wfMessage( 'subpagenavigation-sidebar' )->text(),
			'href'   => $specialpage_title->getLocalURL()
		];

		$sidebar['subpagenavigation-tree'][] = [
			'text'   => wfMessage( 'subpagenavigation-sidebar' )->text(),
			'href'   => $specialpage_title->getLocalURL()
		];

	}

}
