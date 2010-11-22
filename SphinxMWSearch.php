<?php

/**
 * Class file for the SphinxMWSearch extension
 *
 * http://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 * @file
 * @ingroup Extensions
 * @author Svemir Brkic <svemir@deveblog.com>
 */

class SphinxMWSearch extends SearchEngine {

	var $categories = array();
	var $exc_categories = array();
	var $db;
	var $sphinx_client = null;

	function __construct( $db ) {
		parent::__construct( $db );
	}

	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return SphinxMWSearchResultSet
	 * @access public
	 */
	function searchText( $term ) {
		global $wgSphinxSearch_index_list;

		if ( !$this->sphinx_client ) {
			$this->sphinx_client = $this->prepareSphinxClient( $term );
		}

		if ( $this->sphinx_client ) {
			$this->searchTerms = $term;
			$escape = '/';
			$delims = array(
				'(' => ')',
				'[' => ']',
				'"' => '',
			);
			// temporarily replace already escaped characters
			$placeholders = array(
				'\\(' => '_PLC_O_PAR_',
				'\\)' => '_PLC_C_PAR_',
				'\\[' => '_PLC_O_BRA_',
				'\\]' => '_PLC_C_BRA_',
				'\\"' => '_PLC_QUOTE_',
			);
			$term = str_replace(array_keys($placeholders), $placeholders, $term);
			foreach ($delims as $open => $close) {
				$open_cnt = substr_count( $term, $open );
				if ($close) {
					// if counts do not match, escape them all
					$close_cnt = substr_count( $term, $close );
					if ($open_cnt != $close_cnt) {
						$escape .= $open . $close;
					}
				} elseif ($open_cnt % 2 == 1) {
					// if there is no closing symbol, count should be even
					$escape .= $open;
				}
			}
			$term = str_replace($placeholders, array_keys($placeholders), $term);
			$resultSet = $this->sphinx_client->Query(
				addcslashes( $term, $escape ),
				$wgSphinxSearch_index_list
			);
		} else {
			$resultSet = false;
		}

		if ( $resultSet === false ) {
			return null;
		} else {
			return new SphinxMWSearchResultSet( $resultSet, $term, $this->sphinx_client, $this->db );
		}
	}

	/**
	 * We do a weighted title/body search, no need to return titles separately
	 */
	function searchTitle() {
		return null;
	}

	/**
	 * @return SphinxClient: ready to run or false if term is empty
	 */
	function prepareSphinxClient( &$term ) {
		global $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby, $wgSphinxSearch_host,
			$wgSphinxSearch_port, $wgSphinxSearch_index_weights, $wgSphinxSearch_index,
			$wgSphinxSearch_mode, $wgSphinxMatchAll, $wgSphinxSearch_maxmatches,
			$wgSphinxSearch_cutoff, $wgSphinxSearch_weights;

		// don't do anything for blank searches
		if ( trim( $term ) === '' ) {
			return false;
		}

		wfRunHooks( 'SphinxSearchBeforeResults', array(
			&$term,
			&$this->offset,
			&$this->namespaces,
			&$this->categories,
			&$this->exc_categories
		) );

		$cl = new SphinxClient();

		// setup the options for searching
		if ( isset( $wgSphinxSearch_host ) && isset( $wgSphinxSearch_port ) ) {
			$cl->SetServer( $wgSphinxSearch_host, $wgSphinxSearch_port );
		}
		if ( count( $wgSphinxSearch_weights ) ) {
			$cl->SetFieldWeights( $wgSphinxSearch_weights );
		}
		if ( is_array( $wgSphinxSearch_index_weights ) ) {
			$cl->SetIndexWeights( $wgSphinxSearch_index_weights );
		}
		if ( isset( $wgSphinxSearch_mode ) ) {
			$cl->SetMatchMode( $wgSphinxSearch_mode );
		}
		if ( count( $this->namespaces ) ) {
			$cl->SetFilter( 'page_namespace', $this->namespaces );
		}
		if( !$this->showRedirects ) {
			$cl->SetFilter( 'page_is_redirect', array( 0 ) );
		}
		if ( count( $this->categories ) ) {
			$cl->SetFilter( 'category', $this->categories );
		}
		if ( count( $this->exc_categories ) ) {
			$cl->SetFilter( 'category', $this->exc_categories, true );
		}
		$cl->SetSortMode( $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby );
		$cl->SetLimits(
			$this->offset,
			$this->limit,
			$wgSphinxSearch_maxmatches,
			$wgSphinxSearch_cutoff
		);

		wfRunHooks( 'SphinxSearchBeforeQuery', array( &$term, &$cl ) );

		return $cl;
	}

	/**
	 * @return Boolean: can we list/unlist redirects
	 */
	function acceptListRedirects() {
		return true;
	}

	/**
	 * @return String: allowed query characters
	 */
	public static function legalSearchChars() {
		return "A-Za-z_'./\"!~0-9\\x80-\\xFF\\-";
	}

}

class SphinxMWSearchResultSet extends SearchResultSet {
	var $mNdx = 0;
	var $sphinx_client = null;
	var $mSuggestion = '';

	function __construct( $resultSet, $terms, $sphinx_client, $dbr ) {
		global $wgSphinxSearch_index;

		$this->sphinx_client = $sphinx_client;
		$this->mResultSet = array();

		if ( is_array( $resultSet ) && isset( $resultSet['matches'] ) ) {
			foreach ( $resultSet['matches'] as $id => $docinfo ) {
				$res = $dbr->select(
					'page',
					array( 'page_id', 'page_title', 'page_namespace' ),
					array( 'page_id' => $id ),
					__METHOD__,
					array()
				);
				if ( $dbr->numRows( $res ) > 0 ) {
					$this->mResultSet[] = $dbr->fetchObject( $res );
				}
			}
		}
		$this->mNdx = 0;
		$this->mTerms = preg_split('/\W+/', $terms);
	}

	/**
	 * Some search modes return a suggested alternate term if there are
	 * no exact hits. Returns true if there is one on this set.
	 *
	 * @return Boolean
	 */
	function hasSuggestion() {
		global $wgSphinxSuggestMode;

		if ( $wgSphinxSuggestMode ) {
			$this->mSuggestion = '';
			if ( $wgSphinxSuggestMode == 'enchant' ) {
				$this->suggestWithEnchant();
			} else {
				$this->suggestWithSoundex();
			}
			if ($this->mSuggestion) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wiki-specific search suggestions using enchant library.
	 * Use SphinxSearch_setup.php to create the dictionary
	 */
	function suggestWithEnchant() {
		$broker = enchant_broker_init();
		enchant_broker_set_dict_path($broker, ENCHANT_MYSPELL, dirname( __FILE__ ));
		if ( enchant_broker_dict_exists( $broker, 'sphinx' ) ) {
			$dict = enchant_broker_request_dict( $broker, 'sphinx' );
			$suggestion_found = false;
			$full_suggestion = '';
			foreach ( $this->mTerms as $word ) {
				$suggestions = array();
				if ( !enchant_dict_check($dict, $word) ) {
					$suggestions = enchant_dict_suggest($dict, $word);
					while ( count( $suggestions ) ) {
						$candidate = array_shift( $suggestions );
						if ( strtolower($candidate) != strtolower($word) ) {
							$word = $candidate;
							$suggestion_found = true;
							break;
						}
					}
				}
				$full_suggestion .= $word . ' ';
			}
			enchant_broker_free_dict( $dict );
			if ($suggestion_found) {
				$this->mSuggestion = trim( $full_suggestion );
			}
		}
		enchant_broker_free( $broker );
	}

	/**
	 * Default (weak) suggestions implementation relies on MySQL soundex
	 */
	function suggestWithSoundex() {
		$dbr = wfGetDB( DB_SLAVE );
		$joined_terms = $dbr->addQuotes( join( ' ', $this->mTerms ) );
		$res = $dbr->select(
			array( 'page' ),
			array( 'page_title' ),
			array(
				"page_title SOUNDS LIKE " . $joined_terms,
				// avoid (re)recommending the search string
				"page_title NOT LIKE " . $joined_terms
			),
			__METHOD__,
			array(
				'ORDER BY' => 'page_counter desc',
				'LIMIT' => 1
			)
		);
		$suggestion = $dbr->fetchObject( $res );
		if ( is_object( $suggestion ) ) {
			$this->mSuggestion = trim( $suggestion->page_title );
		}
	}

	/**
	 * @return String: suggested query, null if none
	 */
	function getSuggestionQuery(){
		return $this->mSuggestion;
	}

	/**
	 * @return String: HTML highlighted suggested query, '' if none
	 */
	function getSuggestionSnippet(){
		return $this->mSuggestion;
	}

	/**
	 * @return Array: search terms
	 */
	function termMatches() {
		return $this->mTerms;
	}

	/**
	 * @return Integer: number of results
	 */
	function numRows() {
		return count( $this->mResultSet );
	}

	/**
	 * @return SphinxMWSearchResult: next result, false if none
	 */
	function next() {
		if ( isset( $this->mResultSet[$this->mNdx] ) ) {
			$row = $this->mResultSet[$this->mNdx];
			++$this->mNdx;
			return new SphinxMWSearchResult( $row, $this->sphinx_client );
		} else {
			return false;
		}
	}

	function free() {
		unset( $this->mResultSet );
	}

}

class SphinxMWSearchResult extends SearchResult {

	var $sphinx_client = null;

	function __construct( $row, $sphinx_client ) {
		$this->sphinx_client = $sphinx_client;
		parent::__construct( $row );
	}

	/**
	 * @param $terms Array: terms to highlight
	 * @return String: highlighted text snippet, null (and not '') if not supported
	 */
	function getTextSnippet( $terms ){
		global $wgUser, $wgSphinxSearchMWHighlighter, $wgSphinxSearch_index;

		if ( $wgSphinxSearchMWHighlighter ) {
			return parent::getTextSnippet( $terms );
		}

		$this->initText();

		list( $contextlines, $contextchars ) = SearchEngine::userHighlightPrefs( $wgUser );

		$excerpts_opt = array(
			"before_match"    => "<span class='searchmatch'>",
			"after_match"     => "</span>",
			"chunk_separator" => " ... ",
			"limit"           => $contextlines * $contextchars,
			"around"          => $contextchars
		);

		$excerpts = $this->sphinx_client->BuildExcerpts(
			array( $this->mText ),
			$wgSphinxSearch_index,
			join(' ', $terms),
			$excerpts_opt
		);

		if ( is_array( $excerpts ) ) {
			$ret = '';
			foreach ( $excerpts as $entry ) {
				// remove some wiki markup
				$entry = preg_replace( '/([\[\]\{\}\*\#\|\!]+|==+)/',
					' ',
					strip_tags( $entry, '<span><br>' )
				);
				$ret .= "<div style='margin: 0.2em 1em 0.2em 1em;'>$entry</div>\n";
			}
		} else {
			$ret = wfMsg( 'sphinxSearchWarning', $this->sphinx_client->GetLastError() );
		}
		return $ret;
	}

}
