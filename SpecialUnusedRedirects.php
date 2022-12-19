<?php
/**
 * Implements Special:UnusedRedirects. Based on core Special:UnusedRedirects'
 * code by Rob Church <robchur@gmail.com>.
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
 * @ingroup SpecialPage
 * @author Jack Phoenix
 * @date 30 August 2016
 * @see https://phabricator.wikimedia.org/T144245
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * @ingroup SpecialPage
 */
class UnusedRedirectsPage extends QueryPage {
	function __construct( $name = 'UnusedRedirects' ) {
		parent::__construct( $name );
	}

	public function isExpensive() {
		return true;
	}

	function getPageHeader() {
		return $this->msg( 'unusedredirects-text' )->parseAsBlock();
	}

	public function getQueryInfo() {
		return [
			'tables' => [
				'p1' => 'page',
				'redirect',
				'p2' => 'page',
				'pagelinks',
			],
			'fields' => [
				'namespace' => 'p1.page_namespace',
				'title' => 'p1.page_title',
				'value' => 'p1.page_title',
				'rd_namespace',
				'rd_title',
				'rd_fragment',
				'rd_interwiki',
				'redirid' => 'p2.page_id',
			],
			'conds' => [
				'p1.page_is_redirect' => 1,
				'pl_from IS NULL'
			],
			'join_conds' => [
				'redirect' => [ 'LEFT JOIN', 'rd_from = p1.page_id' ],
				'p2' => [ 'LEFT JOIN', [
					'p2.page_namespace = rd_namespace',
					'p2.page_title = rd_title' ]
				],
				'pagelinks' => [ 'LEFT JOIN', [ 'pl_title = p1.page_title', 'pl_namespace = p1.page_namespace' ] ]
			]
		];
	}

	function getOrderFields() {
		return [ 'p1.page_namespace', 'p1.page_title' ];
	}

	/**
	 * Cache page existence for performance
	 *
	 * @param IDatabase $db
	 * @param IResultWrapper $res
	 */
	function preprocessResults( $db, $res ) {
		if ( !$res->numRows() ) {
			return;
		}

		if ( method_exists( MediaWikiServices::class, 'getLinkBatchFactory' ) ) {
			// MW 1.35+
			$batch = MediaWikiServices::getInstance()->getLinkBatchFactory()->newLinkBatch();
		} else {
			$batch = new LinkBatch;
		}
		foreach ( $res as $row ) {
			$batch->add( $row->namespace, $row->title );
			$batch->addObj( $this->getRedirectTarget( $row ) );
		}
		$batch->execute();

		// Back to start for display
		$res->seek( 0 );
	}

	protected function getRedirectTarget( $row ) {
		if ( isset( $row->rd_title ) ) {
			// ashley: added the below checks, the core code (w/o 'em) was
			// returning weird results locally (things which were *not* supposed
			// to be formatted like interwiki links were formatted like IW links)
			// Seems that this breaks the display of fragments though, I'm not
			// sure why, but it's not a big deal IMHO.
			$fragment = $interwiki = '';
			if ( isset( $row->rd_fragment ) && $row->rd_fragment !== null ) {
				$fragment = $row->rd_fragment;
			}
			if ( isset( $row->rd_interwiki ) && $row->rd_interwiki !== null ) {
				$interwiki = $row->rd_interwiki;
			}
			return Title::makeTitle(
				$row->rd_namespace,
				$row->rd_title,
				$fragment,
				$interwiki
			);
		} else {
			$title = Title::makeTitle( $row->namespace, $row->title );
			if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
				// MW 1.36+
				$article = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			} else {
				$article = WikiPage::factory( $title );
			}

			return $article->getRedirectTarget();
		}
	}

	/**
	 * @param Skin $skin
	 * @param stdClass $result Result row
	 * @return string
	 */
	function formatResult( $skin, $result ) {
		$linkRenderer = $this->getLinkRenderer();
		# Make a link to the redirect itself
		$rd_title = Title::makeTitle( $result->namespace, $result->title );
		$rd_link = $linkRenderer->makeLink(
			$rd_title,
			null,
			[],
			[ 'redirect' => 'no' ]
		);

		# Find out where the redirect leads
		$target = $this->getRedirectTarget( $result );
		if ( $target ) {
			# Make a link to the destination page
			$lang = $this->getLanguage();
			$arr = $lang->getArrow() . $lang->getDirMark();
			$targetLink = $linkRenderer->makeLink( $target );

			return "$rd_link $arr $targetLink";
		} else {
			return "<del>$rd_link</del>";
		}
	}

	/**
	 * A should come before Z (bug 30907)
	 * @return bool
	 */
	function sortDescending() {
		return false;
	}

	/**
	 * Group this special page under the correct group in Special:SpecialPages.
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'maintenance';
	}

	/**
	 * Add the UnusedRedirects special pages to the list of QueryPages. This
	 * allows direct access via the API.
	 *
	 * @param array &$queryPages
	 */
	public static function onwgQueryPages( &$queryPages ) {
		$queryPages[] = [ 'UnusedRedirectsPage' /* class */, 'UnusedRedirects' /* special page name */ ];
	}
}
