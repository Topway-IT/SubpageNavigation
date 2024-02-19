<?php


namespace MediaWiki\Extension\SubpageNavigation;

use Category;
use Exception;
use ExtensionRegistry;
use FormatJson;
use Html;
use IContextSource;
use LinkBatch;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Parser;
use RequestContext;
use SpecialPage;
use Title;

/**
 * Core functions for the CategoryTree extension, an AJAX based gadget
 * to display the category structure of a wiki
 */
class Tree {

	const MODE_DEFAULT = 1;
	const MODE_FOLDERS = 2;
	const MODE_FILESYSTEM = 3;
	const MODE_COUNT = 4;
	
	public $mOptions = [];

	/**
	 * @var LinkRenderer
	 */
	private $linkRenderer;
	
	public static function getDataForJs() {
		global $wgCategoryTreeCategoryPageOptions;
		return [
			'defaultCtOptions' => 'abc',
		];

		// Look, this is pretty bad but CategoryTree is just whacky, it needs to be rewritten
		$ct = new Tree( $wgCategoryTreeCategoryPageOptions );

		return [
			'defaultCtOptions' => $ct->getOptionsAsJsStructure(),
		];
	}

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		global $wgCategoryTreeDefaultOptions;
		$this->linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

$this->mOptions = [
	'mode' => TreeMode::PAGES,
	'hideprefix' => false,
	'showcount' => true,
];
return;
		// ensure default values and order of options.
		// Order may become important, it may influence the cache key!
		foreach ( $wgCategoryTreeDefaultOptions as $option => $default ) {
			$this->mOptions[$option] = $options[$option] ?? $default;
		}

		$this->mOptions['mode'] = self::decodeMode( $this->mOptions['mode'] );

		if ( $this->mOptions['mode'] === TreeMode::PARENTS ) {
			// namespace filter makes no sense with TreeMode::PARENTS
			$this->mOptions['namespaces'] = false;
		}

		$this->mOptions['hideprefix'] = self::decodeHidePrefix( $this->mOptions['hideprefix'] );
		$this->mOptions['showcount'] = self::decodeBoolean( $this->mOptions['showcount'] );
		$this->mOptions['namespaces'] = self::decodeNamespaces( $this->mOptions['namespaces'] );

		if ( $this->mOptions['namespaces'] ) {
			# automatically adjust mode to match namespace filter
			if ( count( $this->mOptions['namespaces'] ) === 1
				&& $this->mOptions['namespaces'][0] === NS_CATEGORY ) {
				$this->mOptions['mode'] = TreeMode::CATEGORIES;
			} elseif ( !in_array( NS_FILE, $this->mOptions['namespaces'] ) ) {
				$this->mOptions['mode'] = TreeMode::PAGES;
			} else {
				$this->mOptions['mode'] = TreeMode::ALL;
			}
		}
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function getOption( $name ) {
		return $this->mOptions[$name];
	}

	/**
	 * @return bool
	 */
	private function isInverse() {
		return $this->getOption( 'mode' ) === TreeMode::PARENTS;
	}

	/**
	 * @param mixed $nn
	 * @return array|bool
	 */
	private static function decodeNamespaces( $nn ) {
		if ( $nn === false || $nn === null ) {
			return false;
		}

		if ( !is_array( $nn ) ) {
			$nn = preg_split( '![\s#:|]+!', $nn );
		}

		$namespaces = [];
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		foreach ( $nn as $n ) {
			if ( is_int( $n ) ) {
				$ns = $n;
			} else {
				$n = trim( $n );
				if ( $n === '' ) {
					continue;
				}

				$lower = strtolower( $n );

				if ( is_numeric( $n ) ) {
					$ns = (int)$n;
				} elseif ( $n === '-' || $n === '_' || $n === '*' || $lower === 'main' ) {
					$ns = NS_MAIN;
				} else {
					$ns = $contLang->getNsIndex( $n );
				}
			}

			if ( is_int( $ns ) ) {
				$namespaces[] = $ns;
			}
		}

		# get elements into canonical order
		sort( $namespaces );
		return $namespaces;
	}

	/**
	 * @param mixed $mode
	 * @return int|string
	 */
	public static function decodeMode( $mode ) {
		global $wgCategoryTreeDefaultOptions;

		if ( $mode === null ) {
			return $wgCategoryTreeDefaultOptions['mode'];
		}
		if ( is_int( $mode ) ) {
			return $mode;
		}

		$mode = trim( strtolower( $mode ) );

		if ( is_numeric( $mode ) ) {
			return (int)$mode;
		}

		if ( $mode === 'all' ) {
			$mode = TreeMode::ALL;
		} elseif ( $mode === 'pages' ) {
			$mode = TreeMode::PAGES;
		} elseif ( $mode === 'categories' || $mode === 'sub' ) {
			$mode = TreeMode::CATEGORIES;
		} elseif ( $mode === 'parents' || $mode === 'super' || $mode === 'inverse' ) {
			$mode = TreeMode::PARENTS;
		} elseif ( $mode === 'default' ) {
			$mode = $wgCategoryTreeDefaultOptions['mode'];
		}

		return (int)$mode;
	}

	/**
	 * Helper function to convert a string to a boolean value.
	 * Perhaps make this a global function in MediaWiki proper
	 * @param mixed $value
	 * @return bool|null|string
	 */
	public static function decodeBoolean( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return ( $value > 0 );
		}

		$value = trim( strtolower( $value ) );
		if ( is_numeric( $value ) ) {
			return ( (int)$value > 0 );
		}

		if ( $value === 'yes' || $value === 'y'
			|| $value === 'true' || $value === 't' || $value === 'on'
		) {
			return true;
		} elseif ( $value === 'no' || $value === 'n'
			|| $value === 'false' || $value === 'f' || $value === 'off'
		) {
			return false;
		} elseif ( $value === 'null' || $value === 'default' || $value === 'none' || $value === 'x' ) {
			return null;
		} else {
			return false;
		}
	}

	/**
	 * @param mixed $value
	 * @return int|string
	 */
	public static function decodeHidePrefix( $value ) {
		global $wgCategoryTreeDefaultOptions;

		if ( $value === null ) {
			return $wgCategoryTreeDefaultOptions['hideprefix'];
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( $value === true ) {
			return HidePrefix::ALWAYS;
		}
		if ( $value === false ) {
			return HidePrefix::NEVER;
		}

		$value = trim( strtolower( $value ) );

		if ( $value === 'yes' || $value === 'y'
			|| $value === 'true' || $value === 't' || $value === 'on'
		) {
			return HidePrefix::ALWAYS;
		} elseif ( $value === 'no' || $value === 'n'
			|| $value === 'false' || $value === 'f' || $value === 'off'
		) {
			return HidePrefix::NEVER;
		} elseif ( $value === 'always' ) {
			return HidePrefix::ALWAYS;
		} elseif ( $value === 'never' ) {
			return HidePrefix::NEVER;
		} elseif ( $value === 'auto' ) {
			return HidePrefix::AUTO;
		} elseif ( $value === 'categories' || $value === 'category' || $value === 'smart' ) {
			return HidePrefix::CATEGORIES;
		} else {
			return $wgCategoryTreeDefaultOptions['hideprefix'];
		}
	}

	/**
	 * Add ResourceLoader modules to the OutputPage object
	 * @param OutputPage $outputPage
	 */
	public static function setHeaders( OutputPage $outputPage ) {
		# Add the modules
		// $outputPage->addModuleStyles( 'ext.categoryTree.styles' );
		$outputPage->addModules( 'ext.SubpageNavigation.tree' );
	}

	/**
	 * @param array $options
	 * @param string $enc
	 * @return mixed
	 * @throws Exception
	 */
	protected static function encodeOptions( array $options, $enc ) {
		if ( $enc === 'mode' || $enc === '' ) {
			$opt = $options['mode'];
		} elseif ( $enc === 'json' ) {
			$opt = FormatJson::encode( $options );
		} else {
			throw new Exception( 'Unknown encoding for CategoryTree options: ' . $enc );
		}

		return $opt;
	}

	/**
	 * @param int|null $depth
	 * @return string
	 */
	public function getOptionsAsCacheKey( $depth = null ) {
		$key = '';

		foreach ( $this->mOptions as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = implode( '|', $v );
			}
			$key .= $k . ':' . $v . ';';
		}

		if ( $depth !== null ) {
			$key .= ';depth=' . $depth;
		}
		return $key;
	}

	/**
	 * @param int|null $depth
	 * @return mixed
	 */
	public function getOptionsAsJsStructure( $depth = null ) {
		if ( $depth !== null ) {
			$opt = $this->mOptions;
			$opt['depth'] = $depth;
			$s = self::encodeOptions( $opt, 'json' );
		} else {
			$s = self::encodeOptions( $this->mOptions, 'json' );
		}

		return $s;
	}

	/**
	 * Custom tag implementation. This is called by Hooks::parserHook, which is used to
	 * load CategoryTreeFunctions.php on demand.
	 * @param ?Parser $parser
	 * @param string $category
	 * @param bool $hideroot
	 * @param array $attr
	 * @param int $depth
	 * @param bool $allowMissing
	 * @param bool $searchInput
	 * @return bool|string
	 */
	// public function getTag( ?Parser $parser, $category, $hideroot = false, array $attr = [],
	// 	$depth = 1, $allowMissing = false, $searchInput = false
	//  ) {
	
	public function getTag( ?Parser $parser, $category, $hideroot = false, array $attr = [],
		$api = false, $allowMissing = false, $searchInput = false
	  ) {
		global $wgCategoryTreeDisableCache;

		$category = trim( $category );
		if ( $category === '' ) {
			return false;
		}

		if ( $parser ) {
			if ( $wgCategoryTreeDisableCache === true ) {
				$parser->getOutput()->updateCacheExpiry( 0 );
			} elseif ( is_int( $wgCategoryTreeDisableCache ) ) {
				$parser->getOutput()->updateCacheExpiry( $wgCategoryTreeDisableCache );
			}
		}
		$title = self::makeTitle( $category );

		if ( $title === false || $title === null ) {
			return false;
		}

		if ( isset( $attr['class'] ) ) {
			$attr['class'] .= ' CategoryTreeTag';
		} else {
			$attr['class'] = ' CategoryTreeTag';
		}

		$attr['data-ct-mode'] = $this->mOptions['mode'];
		$attr['data-ct-options'] = $this->getOptionsAsJsStructure();

		if ( !$allowMissing && !$title->getArticleID() ) {
			$html = Html::rawElement( 'span', [ 'class' => 'CategoryTreeNotice' ],
				wfMessage( 'subpagenavigation-tree-not-found' )
					->plaintextParams( $category )
					->parse()
			);
		} else {
		$hideroot = true;
			if ( !$hideroot ) {
				$html = $this->renderNode( $title, $depth );
			} else {
				// $html = $this->renderChildren( $title, $depth );
				 $html = $this->renderChildren( $title, $api );
			}
		}
		
		
				$outText = Html::openElement( 'div', [ 'class' => '' ] );
		// $outText .= 'Subpages';
		$outText .= Html::closeElement( 'div' );
		$outText .= $html;
		
		return Html::rawElement(
			'div',
			[
				'class' => 'subpageNavigation mw-pt-translate-navigation noprint'
			],
			$outText
		);

		return Html::rawElement( 'div', $attr, $outText );
	}

	public static function getSubpages( $prefix, $namespace, $limit = null ) {
		$dbr = wfGetDB( DB_REPLICA );
		$sql = \SubpageNavigation::subpagesSQL( $dbr, $prefix, $namespace, self::MODE_FILESYSTEM );
		if ( $limit ) {
			$offset = 0;
			$sql = $dbr->limitResult( $sql, $limit, $offset );
		}
		$res = $dbr->query( $sql, __METHOD__ );
		$ret = [];
		foreach ( $res as $row ) {
			$title = Title::newFromRow( $row );
			if ( $title->isKnown() ) {
				$ret[] = $title;
			}
		}
		return $ret;
	}
	/**
	 * Returns a string with an HTML representation of the children of the given category.
	 * @param Title $title
	 * @param int $depth
	 * @suppress PhanUndeclaredClassMethod,PhanUndeclaredClassInstanceof
	 * @return string
	 */
	// public function renderChildren( Title $title, $depth = 1 ) {
	public function renderChildren( Title $title, $api = false ) {
		global $wgCategoryTreeMaxChildren, $wgCategoryTreeUseCategoryTable;

		if ( $api === false ) {
		$prefix = '';
		} else {
		 $prefix = str_replace( ' ', '_', $title->getText() );
		}
		
		$namespace = $title->getNamespace();
		$limit = null;
	$subpages = $this->getSubpages( "$prefix/", $namespace, $limit );
	
	
	$titlesText = [];
		foreach ( $subpages as $t ) {
			$titlesText[] = $t->getText();
			
		}
		
		// print_r($titlesText);
		
		
	$dbr = wfGetDB( DB_REPLICA );
		$childrenCount = \SubpageNavigation::getChildrenCount( $dbr, $titlesText, $title->getNamespace() );
		
	
	$categories = '';
	$cat = null;
		foreach ( $subpages as $t ) {
			$titlesText[] = $t->getText();
			// $s = $this->renderNodeInfo( $t, $cat, $depth - 1, array_shift( $childrenCount ), $title );
			$s = $this->renderNodeInfo( $t, $cat, $api, array_shift( $childrenCount ), $title );


			$categories .= $s;
		}
		

		return $categories;
	
	}


	/**
	 * Returns a string with a HTML represenation of the given page.
	 * @param Title $title
	 * @param int $children
	 * @return string
	 */
	public function renderNode( Title $title, $children = 0 ) {
		global $wgCategoryTreeUseCategoryTable;

		
		return $this->renderNodeInfo( $title, null, $children );
		

		if ( $wgCategoryTreeUseCategoryTable && $title->getNamespace() === NS_CATEGORY
			&& !$this->isInverse()
		) {
			$cat = Category::newFromTitle( $title );
		} else {
			$cat = null;
		}

		return $this->renderNodeInfo( $title, $cat, $children );
	}

	/**
	 * Returns a string with a HTML represenation of the given page.
	 * $info must be an associative array, containing at least a Title object under the 'title' key.
	 * @param Title $title
	 * @param Category|null $cat
	 * @param int $children
	 * @return string
	 */
	// public function renderNodeInfo( Title $title, Category $cat = null, $children = 0, $count = 0, $parentTitle = null ) {
	public function renderNodeInfo( Title $title, Category $cat = null, $api = false, $count = 0, $parentTitle = null ) {
	
		$mode = $this->getOption( 'mode' );


	$count = (int)$count;

		$ns = $title->getNamespace();
		$key = $title->getDBkey();

		$hideprefix = $this->getOption( 'hideprefix' );

		if ( $hideprefix === HidePrefix::ALWAYS ) {
			$hideprefix = true;
		} elseif ( $hideprefix === HidePrefix::AUTO ) {
			$hideprefix = ( $mode === TreeMode::CATEGORIES );
		} elseif ( $hideprefix === HidePrefix::CATEGORIES ) {
			$hideprefix = ( $ns === NS_CATEGORY );
		} else {
			$hideprefix = true;
		}
$hideprefix = true;
		// when showing only categories, omit namespace in label unless we explicitely defined the
		// configuration setting
		// patch contributed by Manuel Schneider <manuel.schneider@wikimedia.ch>, Bug 8011
		if ( $hideprefix ) {
			$label = $title->getText();
		} else {
			$label = $title->getPrefixedText();
		}

if ( $api ) {
$label = substr( $label, strlen( $parentTitle->getText() ) + 1);
}

		$link = $this->linkRenderer->makeLink( $title, $label );

		// $count = false;
		$s = '';

		# NOTE: things in CategoryTree.js rely on the exact order of tags!
		#      Specifically, the CategoryTreeChildren div must be the first
		#      sibling with nodeName = DIV of the grandparent of the expland link.

		$s .= Html::openElement( 'div', [ 'class' => 'CategoryTreeSection' ] );
		
		$s .= Html::openElement( 'div', [ 'class' => 'CategoryTreeItem' ] );

		$attr = [ 'class' => 'CategoryTreeBullet' ];
		
// ***edited
		// if ( $ns === NS_CATEGORY ) {
		if ( true ) {
		
		/*
			if ( $cat ) { 
				if ( $mode === TreeMode::CATEGORIES ) {
					$count = $cat->getSubcatCount();
				} elseif ( $mode === TreeMode::PAGES ) {
					$count = $cat->getMemberCount() - $cat->getFileCount();
				} else {
					$count = $cat->getMemberCount();
				}
			}
		*/
				$dbr = wfGetDB( DB_REPLICA );
		// $childrenCount = SubpageNavigation::getChildrenCount( $dbr, $titlesText, $title->getNamespace() );
		
$title_ = RequestContext::getMain()->getTitle();
	 $children = 1;
	 
	 $expanded = strpos( $title_->getText(), $title->getText() ) === 0;
				// ***edited
			//$count = 5;
			if ( $count === 0 ) {
				$bullet = '';
				$attr['class'] = 'CategoryTreeEmptyBullet';
			} else {
				$linkattr = [
					'class' => 'CategoryTreeToggle',
					'data-ct-title' => $key,
				];
				// ***edited

				if ( !$expanded  ) {
					$linkattr['data-ct-state'] = 'collapsed';
				} else {
					$linkattr['data-ct-loaded'] = true;
					$linkattr['data-ct-state'] = 'expanded';
				}

				$bullet = Html::element( 'span', $linkattr );
			}
		} else {
			$bullet = '';
			$attr['class'] = 'CategoryTreePageBullet';
		}
		$s .= Html::rawElement( 'span', $attr, $bullet ) . ' ';

		$s .= $link;
		

		if ( $count !== 0 && $this->getOption( 'showcount' ) ) {
			$s .= self::createCountString( RequestContext::getMain(), $cat, $count, $count );
		}

		$s .= Html::closeElement( 'div' );
		$s .= Html::openElement(
			'div',
			[
				'class' => 'CategoryTreeChildren',
				'style' => $children === 0 ? 'display:none' : null
			]
		);


		// if ( $ns === NS_CATEGORY && $children > 0 ) {
	//	if ( !$api ) {
	
	if ( strpos( $title_->getText(), $title->getText() ) === 0 ) {
			$children = $this->renderChildren( $title, true );
			if ( $children === '' ) {
				switch ( $mode ) {
					case TreeMode::CATEGORIES:
						$msg = 'subpagenavigation-tree-no-subcategories';
						break;
					case TreeMode::PAGES:
						$msg = 'subpagenavigation-tree-no-pages';
						break;
					case TreeMode::PARENTS:
						$msg = 'subpagenavigation-tree-no-parent-categories';
						break;
					default:
						$msg = 'subpagenavigation-tree-nothing-found';
						break;
				}
				$children = Html::element( 'i', [ 'class' => 'CategoryTreeNotice' ],
					wfMessage( $msg )->text()
				);
			}
			$s .= $children;
		}

		$s .= Html::closeElement( 'div' ) . Html::closeElement( 'div' );

		return $s;
	}

	/**
	 * Create a string which format the page, subcat and file counts of a category
	 * @param IContextSource $context
	 * @param ?Category $cat
	 * @param int $countMode
	 * @return string
	 */
	public static function createCountString( IContextSource $context, ?Category $cat,
		$countMode, $memberNumsShort
	) {
			$allCount = $cat ? $cat->getMemberCount() : 0;
		$subcatCount = $cat ? $cat->getSubcatCount() : 0;
		$fileCount = $cat ? $cat->getFileCount() : 0;
		$pages = $cat ? $cat->getPageCount( Category::COUNT_CONTENT_PAGES ) : 0;

		$attr = [
			'title' => $context->msg( 'subpagenavigation-tree-member-counts' )
				->numParams( $subcatCount, $pages, $fileCount, $allCount, $countMode )->text(),
			# numbers and commas get messed up in a mixed dir env
			'dir' => $context->getLanguage()->getDir()
		];

			$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$s = $contLang->getDirMark() . ' ';
				# Only $5 is actually used in the default message.
		# Other arguments can be used in a customized message.
		$s .= Html::rawElement(
			'span',
			$attr,
			$context->msg( 'subpagenavigation-tree-member-num' )
				// Do not use numParams on params 1-4, as they are only used for customisation.
				->params( $subcatCount, $pages, $fileCount, $allCount, $memberNumsShort )
				->escaped()
		);

		return $s;
	
	
		$allCount = $cat ? $cat->getMemberCount() : 0;
		$subcatCount = $cat ? $cat->getSubcatCount() : 0;
		$fileCount = $cat ? $cat->getFileCount() : 0;
		$pages = $cat ? $cat->getPageCount( Category::COUNT_CONTENT_PAGES ) : 0;

		$attr = [
			'title' => $context->msg( 'subpagenavigation-tree-member-counts' )
				->numParams( $subcatCount, $pages, $fileCount, $allCount, $countMode )->text(),
			# numbers and commas get messed up in a mixed dir env
			'dir' => $context->getLanguage()->getDir()
		];
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$s = $contLang->getDirMark() . ' ';

		# Create a list of category members with only non-zero member counts
		$memberNums = [];
		if ( $subcatCount ) {
			$memberNums[] = $context->msg( 'subpagenavigation-tree-num-categories' )
				->numParams( $subcatCount )->text();
		}
		if ( $pages ) {
			$memberNums[] = $context->msg( 'subpagenavigation-tree-num-pages' )->numParams( $pages )->text();
		}
		if ( $fileCount ) {
			$memberNums[] = $context->msg( 'subpagenavigation-tree-num-files' )
				->numParams( $fileCount )->text();
		}
		$memberNumsShort = $memberNums
			? $context->getLanguage()->commaList( $memberNums )
			: $context->msg( 'subpagenavigation-tree-num-empty' )->text();

		# Only $5 is actually used in the default message.
		# Other arguments can be used in a customized message.
		$s .= Html::rawElement(
			'span',
			$attr,
			$context->msg( 'subpagenavigation-tree-member-num' )
				// Do not use numParams on params 1-4, as they are only used for customisation.
				->params( $subcatCount, $pages, $fileCount, $allCount, $memberNumsShort )
				->escaped()
		);

		return $s;
	}

	/**
	 * Creates a Title object from a user provided (and thus unsafe) string
	 * @param string $title
	 * @return null|Title
	 */
	public static function makeTitle( $title ) {
	
		$title = trim( strval( $title ) );

		if ( $title === '' ) {
			return null;
		}
		
		// ***edited
		$t = Title::newFromText( $title );
		
		return $t;

		# The title must be in the category namespace
		# Ignore a leading Category: if there is one
		$t = Title::newFromText( $title, NS_CATEGORY );
		if ( !$t || $t->getNamespace() !== NS_CATEGORY || $t->getInterwiki() !== '' ) {
			// If we were given something like "Wikipedia:Foo" or "Template:",
			// try it again but forced.
			$title = "Category:$title";
			$t = Title::newFromText( $title );
		}
		return $t;
	}

	/**
	 * Internal function to cap depth
	 * @param string $mode
	 * @param int $depth
	 * @return int|mixed
	 */
	public static function capDepth( $mode, $depth ) {
		global $wgCategoryTreeMaxDepth;

		if ( !is_numeric( $depth ) ) {
			return 1;
		}

		$depth = intval( $depth );

		if ( is_array( $wgCategoryTreeMaxDepth ) ) {
			$max = $wgCategoryTreeMaxDepth[$mode] ?? 1;
		} elseif ( is_numeric( $wgCategoryTreeMaxDepth ) ) {
			$max = $wgCategoryTreeMaxDepth;
		} else {
			wfDebug( 'CategoryTree::capDepth: $wgCategoryTreeMaxDepth is invalid.' );
			$max = 1;
		}

		return min( $depth, $max );
	}
}
