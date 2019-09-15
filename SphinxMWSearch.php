<?php

/**
 * Class file for the SphinxMWSearch extension
 *
 * https://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 * @file
 * @ingroup Extensions
 * @author Svemir Brkic <svemir@deveblog.com>
 */

class SphinxMWSearch extends SearchDatabase {

	public $categories = array();
	public $exc_categories = array();
	public $db;
	public $sphinx_client = null;
	public $prefix_handlers = array(
		'intitle' => 'filterByTitle',
		'incategory' => 'filterByCategory',
		'prefix' => 'filterByPrefix',
	);

	public static function initialize() {
		global $wgHooks, $wgEnableSphinxPrefixSearch, $wgSearchType, $wgDisableSearchUpdate;

		if ( !class_exists( 'SphinxClient' ) ) {
			if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
				require_once __DIR__ . '/vendor/autoload.php';
			}
			if ( !class_exists( 'SphinxClient' ) ) {
				require_once __DIR__ . '/sphinxapi.php';
			}
		}

		if ( $wgSearchType == 'SphinxMWSearch' ) {
			$wgDisableSearchUpdate = true;
		}

		wfDebug( 'SphinxSearchInit::initialize: running.' );
		if ( $wgEnableSphinxPrefixSearch ) {
			$wgHooks[ 'PrefixSearchBackend' ][ ] = 'SphinxMWSearch::prefixSearch';
		}
	}

	protected function doSearchTextInDB( $term ) {
		return $this->searchText( $term );
	}

	protected function doSearchTitleInDB( $term ) {
		return $this->searchText( '@page_title: ^' . $term . '*' );
	}

	/**
	 * PrefixSearchBackend override for OpenSearch results
	 */
	static function prefixSearch( $namespaces, $term, $limit, &$results, $offset = 0 ) {
		$search_engine = new SphinxMWSearch( wfGetDB( DB_REPLICA ) );
		$search_engine->namespaces = $namespaces;
		$search_engine->setLimitOffset( $limit, $offset );
		$result_set = $search_engine->searchText( '@page_title: ^' . $term . '*' );
		$results = array();
		if ( $result_set ) {
			while ( $res = $result_set->next() ) {
				$results[ ] = $res->getTitle()->getPrefixedText();
			}
		}
		return false;
	}

	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return SphinxMWSearchResultSet
	 * @access public
	 */
	function searchText( $term ) {
		global $wgSphinxSearch_index_list, $wgSphinxSuggestMode;

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
			$term = addcslashes( $term, $escape );
			wfDebug( "SphinxSearch query: $term\n" );
			$resultSet = $this->sphinx_client->Query(
				$term,
				$wgSphinxSearch_index_list
			);
		} else {
			$resultSet = false;
		}

		if ( $resultSet === false && !$wgSphinxSuggestMode ) {
			return null;
		} else {
			return new SphinxMWSearchResultSet( $resultSet, $term, $this->sphinx_client, $this->db );
		}
	}

	/**
	 * @return SphinxClient: ready to run or false if term is empty
	 */
	function prepareSphinxClient( &$term ) {
		global $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby, $wgSphinxSearch_host,
			$wgSphinxSearch_port, $wgSphinxSearch_index_weights,
			$wgSphinxSearch_mode, $wgSphinxSearch_maxmatches,
			$wgSphinxSearch_cutoff, $wgSphinxSearch_weights;

		// don't do anything for blank searches
		if ( trim( $term ) === '' ) {
			return false;
		}

		Hooks::run( 'SphinxSearchBeforeResults', array(
			&$term,
			&$this->offset,
			&$this->namespaces,
			&$this->categories,
			&$this->exc_categories
		) );

		$cl = new SphinxClient();

		$cl->SetServer( $wgSphinxSearch_host, $wgSphinxSearch_port );
		if ( $wgSphinxSearch_weights && count( $wgSphinxSearch_weights ) ) {
			$cl->SetFieldWeights( $wgSphinxSearch_weights );
		}
		if ( is_array( $wgSphinxSearch_index_weights ) ) {
			$cl->SetIndexWeights( $wgSphinxSearch_index_weights );
		}
		if ( $wgSphinxSearch_mode ) {
			$cl->SetMatchMode( $wgSphinxSearch_mode );
		}
		if ( $this->namespaces && count( $this->namespaces ) ) {
			$cl->SetFilter( 'page_namespace', $this->namespaces );
		}
		if ( $this->categories && count( $this->categories ) ) {
			$cl->SetFilter( 'category', $this->categories );
			wfDebug( "SphinxSearch included categories: " . join( ', ', $this->categories ) . "\n" );
		}
		if ( $this->exc_categories && count( $this->exc_categories ) ) {
			$cl->SetFilter( 'category', $this->exc_categories, true );
			wfDebug( "SphinxSearch excluded categories: " . join( ', ', $this->exc_categories ) . "\n" );
		}
		$cl->SetSortMode( $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby );
		$cl->SetLimits(
			$this->offset,
			$this->limit,
			$wgSphinxSearch_maxmatches,
			$wgSphinxSearch_cutoff
		);

		Hooks::run( 'SphinxSearchBeforeQuery', array( &$term, &$cl ) );

		return $cl;
	}

	/**
	 * Prepare query for sphinx search daemon
	 *
	 * @param string $query
	 * @return string rewritten query
	 */
	function replacePrefixes( $query ) {
		if ( trim( $query ) === '' ) {
			return $query;
		}

		// ~ prefix is used to avoid near-term search, remove it now
		if ( $query[ 0 ] === '~' ) {
			$query = substr( $query, 1 );
		}

		$parts = preg_split( '/(")/', $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		$inquotes = false;
		$rewritten = '';
		foreach ( $parts as $key => $part ) {
			if ( $part == '"' ) { // stuff in quotes doesn't get rewritten
				$rewritten .= $part;
				$inquotes = !$inquotes;
			} elseif ( $inquotes ) {
				$rewritten .= $part;
			} else {
				if ( strpos( $query, ':' ) !== false ) {
					$regexp = $this->preparePrefixRegexp();
					$part = preg_replace_callback(
						'/(^|[| :]|-)(' . $regexp . '):([^ ]+)/i',
						array( $this, 'replaceQueryPrefix' ),
						$part
					);
				}
				$rewritten .= str_replace(
					array( ' OR ', ' AND ' ),
					array( ' | ', ' & ' ),
					$part
				);
			}
		}
		return $rewritten;
	}

	/**
	 * @return string Regexp to match namespaces and other prefixes
	 */
	function preparePrefixRegexp() {
		global $wgContLang, $wgCanonicalNamespaceNames, $wgNamespaceAliases;

		// "search everything" keyword
		$allkeyword = wfMessage( 'searchall' )->inContentLanguage()->text();
		$this->prefix_handlers[ $allkeyword ] = 'searchAllNamespaces';

		$all_prefixes = array_merge(
			$wgContLang->getNamespaces(),
			$wgCanonicalNamespaceNames,
			array_keys( array_merge( $wgNamespaceAliases, $wgContLang->getNamespaceAliases() ) ),
			array_keys( $this->prefix_handlers )
		);

		$regexp_prefixes = array();
		foreach ( $all_prefixes as $prefix ) {
			if ( $prefix != '' ) {
				$regexp_prefixes[] = preg_quote( str_replace( ' ', '_', $prefix ), '/' );
			}
		}

		return implode( '|', array_unique( $regexp_prefixes ) );
	}

	/**
	 * preg callback to process foo: prefixes in the query
	 *
	 * @param array $matches
	 * @return string
	 */
	function replaceQueryPrefix( $matches ) {
		if ( isset( $this->prefix_handlers[ $matches[ 2 ] ] ) ) {
			$callback = $this->prefix_handlers[ $matches[ 2 ] ];
			return $this->$callback( $matches );
		} else {
			return $this->filterByNamespace( $matches );
		}
	}

	function filterByNamespace( $matches ) {
		global $wgContLang;
		$inx = $wgContLang->getNsIndex( str_replace( ' ', '_', $matches[ 2 ] ) );
		if ( $inx === false ) {
			return $matches[ 0 ];
		} else {
			$this->namespaces[] = $inx;
			return $matches[ 3 ];
		}
	}

	function searchAllNamespaces( $matches ) {
		$this->namespaces = null;
		return $matches[ 3 ];
	}

	function filterByTitle( $matches ) {
		return '@page_title ' . $matches[ 3 ];
	}

	function filterByPrefix( $matches ) {
		$prefix = $matches[ 3 ];
		if ( strpos( $matches[ 3 ], ':' ) !== false ) {
			global $wgContLang;
			list( $ns, $prefix ) = explode( ':', $matches[ 3 ] );
			$inx = $wgContLang->getNsIndex( str_replace( ' ', '_', $ns ) );
			if ( $inx !== false ) {
				$this->namespaces = array( $inx );
			}
		}
		return '@page_title ^' . $prefix . '*';
	}

	function filterByCategory( $matches ) {
		$page_id = $this->db->selectField( 'page', 'page_id',
			array(
				'page_title' => $matches[ 3 ],
				'page_namespace' => NS_CATEGORY
			),
			__METHOD__
		);
		$category = intval( $page_id );
		if ( $matches[ 1 ] === '-' ) {
			$this->exc_categories[ ] = $category;
		} else {
			$this->categories[ ] = $category;
		}
		return '';
	}

}

