<?php
/**
 * Bliki feed for the Bliki extension
 * 
 * See http://www.organicdesign.co.nz/bliki for more detail
 *
 * @package MediaWiki
 * @subpackage Extensions
 * @ingroup API
 * @author [http://www.organicdesign.co.nz/aran Aran Dunkley]
 * @copyright © 2015 [http://www.organicdesign.co.nz/aran Aran Dunkley]
 * @licence GNU General Public Licence 2.0 or later
 */

class ApiBlikiFeed extends ApiBase {

	/**
	 * This module uses a custom feed wrapper printer.
	 *
	 * @return ApiFormatFeedWrapper
	 */
	public function getCustomPrinter() {
		return new ApiFormatFeedWrapper( $this->getMain() );
	}

	/**
	 * Format the rows (generated by SpecialRecentchanges or SpecialRecentchangeslinked)
	 * as an RSS/Atom feed.
	 */
	public function execute() {
		global $wgBlikiDefaultCat;
		$this->params = $this->extractRequestParams();
		$config = $this->getConfig();
		if ( !$config->get( 'Feed' ) ) {
			$this->dieUsage( 'Syndication feeds are not available', 'feed-unavailable' );
		}

		if( !array_key_exists( 'q', $this->params ) ) {
			$this->getRequest()->setVal( 'q' , $this->params['q'] = array( $wgBlikiDefaultCat ) );
		}
		if( !array_key_exists( 'feed', $this->params ) ) {
			$this->getRequest()->setVal( 'feed' , $this->params['feed'] = 'rss' );
		}
		if( !array_key_exists( 'days', $this->params ) ) {
			$this->getRequest()->setVal( 'days' , $this->params['days'] = '1000' );
		}

		$feedClasses = $config->get( 'FeedClasses' );
		if ( !isset( $feedClasses[$this->params['feedformat']] ) ) {
			$this->dieUsage( 'Invalid subscription feed type', 'feed-invalid' );
		}

		$this->getMain()->setCacheMode( 'public' );
		if ( !$this->getMain()->getParameter( 'smaxage' ) ) {
			// bug 63249: This page gets hit a lot, cache at least 15 seconds.
			$this->getMain()->setCacheMaxAge( 15 );
		}

		$feedFormat = $this->params['feedformat'];
		$formatter = $this->getFeedObject( $feedFormat );
		$rc = new SpecialBlikiFeed();
		$rows = $rc->getRows();
		$feedItems = $rows ? ChangesFeed::buildItems( $rows ) : array();

		ApiFormatFeedWrapper::setResult( $this->getResult(), $formatter, $feedItems );
	}

	/**
	 * Return a ChannelFeed object.
	 *
	 * @param string $feedFormat Feed's format (either 'rss' or 'atom')
	 * @return ChannelFeed
	 */
	public function getFeedObject( $feedFormat ) {
			global $wgRequest, $wgSitename;

			// Blog title & description
			$q = $wgRequest->getVal( 'q', false );
			$cat = $q ? Title::newFromText( $q )->getText() : false;
			$tag = $cat ? self::inCat( 'Tags', $cat ) : false;
			$title = preg_replace( '% *wiki$%i', '', $wgSitename ) . ' blog';
			$desc = $cat ? ( $tag ? "\"$cat\" posts" : lcfirst( $cat ) ) : 'posts';
			$desc = wfMessage( 'bliki-desc', $desc, $wgSitename )->text();

			// Blog URL
			$blog = Title::newFromText( 'Blog' );
			$url = $blog->getFullURL( $cat ? "q=$cat" : '' );

			// Instantiate our custom ChangesFeed class
			$feed = new BlikiChangesFeed( $feedFormat, 'rcfeed' );
			$feedObj = $feed->getFeedObject( $title, $desc, $url );

			return $feedObj;
		}

	/**
	 * Return whether or not the passed title is a member of the passed cat
	 */
	public static function inCat( $cat, $title = false ) {
		global $wgTitle;
		if( $title === false ) $title = $wgTitle;
		if( !is_object( $title ) ) $title = Title::newFromText( $title );
		$id  = $title->getArticleID();
		$dbr = wfGetDB( DB_SLAVE );
		$cat = $dbr->addQuotes( Title::newFromText( $cat, NS_CATEGORY )->getDBkey() );
		return $dbr->selectRow( 'categorylinks', '1', "cl_from = $id AND cl_to = $cat" );
	}

	public function getAllowedParams() {
		$config = $this->getConfig();
		$feedFormatNames = array_keys( $config->get( 'FeedClasses' ) );
		return array(
			'feedformat' => array(
				ApiBase::PARAM_DFLT => 'rss',
				ApiBase::PARAM_TYPE => $feedFormatNames,
			),
			'days' => array(
				ApiBase::PARAM_DFLT => 7,
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_TYPE => 'integer',
			),
			'limit' => array(
				ApiBase::PARAM_DFLT => 50,
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => $config->get( 'FeedLimit' ),
				ApiBase::PARAM_TYPE => 'integer',
			),
			'from' => array(
				ApiBase::PARAM_TYPE => 'timestamp',
			),
			'q' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_ISMULTI => true,
			),
		);
	}

	public function getParamDescription() {
		return array(
			'feedformat' => 'The format of the feed',
			'days' => 'Days to limit the results to',
			'limit' => 'Maximum number of results to return',
			'from' => 'Show changes since then',
			'q' => 'Show only changes on pages in this category',
		);
	}

	public function getDescription() {
		return 'Returns a blog feed, see http://www.organicdesign.co.nz/bliki for more information';
	}

	public function getExamples() {
		return array(
			'api.php?action=blikifeed',
			'api.php?action=blikifeed&q=CategoryName'
		);
	}
}
