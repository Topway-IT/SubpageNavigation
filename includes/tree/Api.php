<?php

namespace MediaWiki\Extension\SubpageNavigation;

use ApiBase;
use ApiMain;
use Config;
use ConfigFactory;
use FormatJson;
use MediaWiki\Languages\LanguageConverterFactory;
use ObjectCache;
use Title;
use WANObjectCache;
use Wikimedia\ParamValidator\ParamValidator;

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
 * @copyright Copyright ©2023, https://wikisphere.org
 */

// @credits: https://www.mediawiki.org/wiki/Extension:CategoryTree

class Api extends ApiBase {
	/** @var ConfigFactory */
	private $configFactory;

	/** @var LanguageConverterFactory */
	private $languageConverterFactory;

	/** @var WANObjectCache */
	private $wanCache;

	/** @var srvCache */
	private $srvCache;

	/**
	 * @param ApiMain $main
	 * @param string $action
	 * @param ConfigFactory $configFactory
	 * @param LanguageConverterFactory $languageConverterFactory
	 * @param WANObjectCache $wanCache
	 */
	public function __construct(
		ApiMain $main,
		$action,
		ConfigFactory $configFactory,
		LanguageConverterFactory $languageConverterFactory,
		WANObjectCache $wanCache
	) {
		parent::__construct( $main, $action );
		$this->configFactory = $configFactory;
		$this->languageConverterFactory = $languageConverterFactory;
		$this->wanCache = $wanCache;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$options = $this->extractOptions( $params );
		$title = Tree::makeTitle( $params['title'], (int)$options['namespace'] );

		if ( !$title || $title->isExternal() ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}

		$this->srvCache = ObjectCache::getLocalServerInstance( 'hash' );

		$tree = new Tree( $options );
		$config = $this->configFactory->makeConfig( 'subpagenavigation' );

		$html = $this->getHTML( $tree, $title, $config );
		// $html = trim( $ct->renderChildren( $title, true ) );

		$this->getMain()->setCacheMode( 'public' );
		$this->getResult()->addContentValue( $this->getModuleName(), 'html', $html );
	}
	
	private function extractOptions( $params ) {
		$options = [];
		if ( isset( $params['options'] ) ) {
			$options = FormatJson::decode( $params['options'] );
			if ( !is_object( $options ) ) {
				$this->dieWithError( 'apierror-subpagenavigation-invalidjson', 'invalidjson' );
			}
			$options = get_object_vars( $options );
		}
		return $options;
	}

	/**
	 * @param string $condition
	 * @return bool|null|string
	 */
	public function getConditionalRequestData( $condition ) {
		if ( $condition === 'last-modified' ) {
			$params = $this->extractRequestParams();
			$options = $this->extractOptions( $params );
			$title = Tree::makeTitle( $params['title'], (int)$options['namespace'] );
			return wfGetDB( DB_REPLICA )->selectField( 'page', 'page_touched',
				[
					'page_namespace' => $title->getNamespace(),
					'page_title' => $title->getDBkey(),
				],
				__METHOD__
			);
		}
	}

	/**
	 * @param Tree $tree
	 * @param Title $title
	 * @param Config $config
	 * @return string HTML
	 */
	private function getHTML( Tree $tree, Title $title, Config $config ) {
		$langConv = $this->languageConverterFactory->getLanguageConverter();
		
		

		return $this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey(
				'subpagenavigation-tree-html-ajax',
				md5( $title->getDBkey() ),
				$this->getLanguage()->getCode(),
				$langConv->getExtraHashOptions(),
				$config->get( 'RenderHashAppend' )
			),
			$this->wanCache::TTL_DAY,
			static function () use ( $tree, $title ) {
				return trim( $tree->renderChildren( $title, true ) );
			},
			[
				'touchedCallback' => function () {
					$timestamp = $this->getConditionalRequestData( 'last-modified' );
					return $timestamp ? wfTimestamp( TS_UNIX, $timestamp ) : null;
				}
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'options' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function isInternal() {
		return true;
	}
}
