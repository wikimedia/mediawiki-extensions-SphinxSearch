<?php

/**
 * Class file for the SphinxSearch extension
 *
 * http://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 * @addtogroup Extensions
 * @author Svemir Brkic <svemir@deveblog.com> and Paul Grinberg
 */

class SphinxSearch extends SpecialPage {

	var $search_term = '';         // what are we looking for
	var $namespaces = array();     // namespaces to search
	var $categories = array();     // categories to include
	var $exc_categories = array(); // categories to exclude
	var $page = 1;                 // results page we are on

	/**
	 * Build a set of next/previous links for a given title
	 *
	 * @param Title $title
	 * @return string
	 */
	function SphinxSearch() {
		global $wgDisableInternalSearch, $wgAutoloadClasses;

		if ( $wgDisableInternalSearch ) {
			SpecialPage::SpecialPage( 'Search' );
		} else {
			SpecialPage::SpecialPage( 'SphinxSearch' );
		}

		if ( function_exists( 'wfLoadExtensionMessages' ) ) {
			wfLoadExtensionMessages( 'SphinxSearch' );
		} else {
			static $messagesLoaded = false;
			global $wgMessageCache;
			if ( !$messagesLoaded ) {
				$messagesLoaded = true;
				include dirname( __FILE__ ) . '/SphinxSearch.i18n.php';
				foreach ( $messages as $lang => $langMessages ) {
					$wgMessageCache->addMessages( $langMessages, $lang );
				}
			}
		}

		return true;
	}

	/**
	 * Determine which namespaces may be included in a search
	 *
	 * @return array
	 */
	function searchableNamespaces() {
		$namespaces = SearchEngine::searchableNamespaces();

		wfRunHooks( 'SphinxSearchFilterSearchableNamespaces', array( &$namespaces ) );

		return $namespaces;
	}

	/**
	 * Determine which categories may be included in a search
	 *
	 * @return array
	 */
	function searchableCategories() {
		global $wgSphinxTopSearchableCategory;

		if ( $wgSphinxTopSearchableCategory ) {
			$categories = self::getChildrenCategories( $wgSphinxTopSearchableCategory );
		} else {
			$categories = array();
		}

		wfRunHooks( 'SphinxSearchGetSearchableCategories', array( &$categories ) );

		return $categories;
	}

	/**
	 * Determine sub-categories of a given category
	 *
	 * @param string $parent
	 * @return array
	 */
	function getChildrenCategories( $parent ) {
		global $wgMemc, $wgDBname;

		$categories = null;
		if ( is_object( $wgMemc ) ) {
			$cache_key = $wgDBname . ':sphinx_cats:' . md5( $parent );
			$categories = $wgMemc->get( $cache_key );
		}

		if ( !is_array( $categories ) ) {
			$categories = array();
			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select(
				array( 'categorylinks', 'page' ),
				array( 'cl_from', 'cl_sortkey', 'page_title' ),
				array( '1',
						'cl_from         =  page_id',
						'cl_to'   => $parent,
						'page_namespace' => NS_CATEGORY ),
				__METHOD__,
				array( 'ORDER BY' => 'cl_sortkey' )
			);
			while ( $x = $dbr->fetchObject ( $res ) ) {
				$categories[$x->cl_from] = $x->cl_sortkey;
			}
			if ( $cache_key ) {
				# cache query results for a day
				$wgMemc->set( $cache_key, $categories, 86400 );
			}
			$dbr->freeResult( $res );
		}
		return $categories;
	}

	function ajaxGetCategoryChildren( $parent_id ) {

		$title = Title::newFromID( $parent_id );

		if ( !$title ) {
			return false;
		}

		# Retrieve page_touched for the category
		$dbkey = $title->getDBkey();
		$dbr = wfGetDB( DB_SLAVE );
		$touched = $dbr->selectField(
			'page', 'page_touched',
			array(
				'page_namespace' => NS_CATEGORY,
				'page_title' => $dbkey,
			),
			__METHOD__
		);

		$response = new AjaxResponse();

		if ( $response->checkLastModified( $touched ) ) {
			return $response;
		}

		$categories = self::getChildrenCategories( $dbkey );

		$html = self::getCategoryCheckboxes( $categories, $parent_id );

		$response->addText( $html );

		return $response;
	}

	function execute( $par ) {
		global $wgRequest, $wgOut, $wgUser, $wgSphinxMatchAll, $wgSphinxSearch_index_list;

		# extract the options from the GET query
		$term = $wgRequest->getText( 'search', $par );
		if ( $term === '' ) {
			$term = $wgRequest->getText( 'sphinxsearch', $par );
		}
		# see if we want to go the title directly
		# this logic is actually reversed (if we are not doing a search,
		# thn try to go to title directly). This is needed because IE has a
		# different behavior when the <ENTER> button is pressed in a form -
		# it does not send the name of the default button!
		if ( !$wgRequest->getVal( 'fulltext' ) ) {
			$this->goResult( $term );
		}

		$this->setHeaders();
		$wgOut->setPagetitle( wfMsg( 'sphinxsearch' ) );

		$this->namespaces = array();
		$all_namespaces = self::searchableNamespaces();
		foreach ( $all_namespaces as $ns => $name ) {
			if ( $wgRequest->getCheck( "ns{$ns}" ) ) {
				$this->namespaces[] = $ns;
			}
		}
		if ( !count( $this->namespaces ) ) {
			foreach ( $all_namespaces as $ns => $name ) {
				if ( $wgUser->getOption( 'searchNs' . $ns ) ) {
					$this->namespaces[] = $ns;
				}
			}
		}

		$this->categories = $wgRequest->getIntArray( "cat", array() );
		$this->exc_categories = $wgRequest->getIntArray( "exc", array() );

		$this->page = $wgRequest->getInt( 'page', 1 );
		$wgSphinxMatchAll = $wgRequest->getInt( 'match_all', intval( $wgSphinxMatchAll ) );
		$match_titles_only = ( $wgRequest->getInt( 'match_titles' ) == 1 );

		# do the actual search
		$found = 0;
		$cl = $this->prepareSphinxClient( $term, $match_titles_only );
		if ( $cl ) {
			$res = $cl->Query(
				addcslashes( $this->search_term, '/()[]"!' ),
				$wgSphinxSearch_index_list
			);
			if ( $res === false ) {
				$wgOut->addWikiText( wfMsg( 'sphinxSearchFailed', $cl->GetLastError() ) . "\n" );
			} else {
				$found = $this->wfSphinxDisplayResults( $term, $res, $cl );
			}
		} else {
			$wgOut->addWikiText( wfMsg( 'sphinxClientFailed' ) . "\n" );
		}

		# prepare for the next search
		if ( $found ) {
			$this->createNextPageBar( $found, $term );
		}

		$this->createNewSearchForm( $term );
	}

	function goResult( $term ) {
		global $wgOut, $wgGoToEdit;

		# Try to go to page as entered.
		$t = Title::newFromText( $term );

		# If the string cannot be used to create a title
		if ( is_null( $t ) ) {
			return;
		}

		# If there's an exact or very near match, jump right there.
		$t = SearchEngine::getNearMatch( $term );
		wfRunHooks( 'SphinxSearchGetNearMatch', array( &$term, &$t ) );
		if ( !is_null( $t ) ) {
			$wgOut->redirect( $t->getFullURL() );
			return;
		}

		# No match, generate an edit URL
		$t = Title::newFromText( $term );
		if ( !is_null( $t ) ) {
			# If the feature is enabled, go straight to the edit page
			if ( $wgGoToEdit ) {
				$wgOut->redirect( $t->getFullURL( 'action=edit' ) );
				return;
			}
		}

		$wgOut->addWikiText( wfMsg( 'noexactmatch', wfEscapeWikiText( $term ) ) );
	}

	/**
	 * Set the maximum number of results to return
	 * and how many to skip before returning the first.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @access public
	 */
	function setLimitOffset( $limit, $offset = 0 ) {
		global $wgSphinxSearch_matches;

		$wgSphinxSearch_matches = intval( $limit );

		if ( $offset > 0 && $limit > 0 ) {
			$this->page = 1 + intval( $offset / $limit );
		}
	}

	/**
	 * Set which namespaces the search should include.
	 * Give an array of namespace index numbers.
	 *
	 * @param array $namespaces
	 * @access public
	 */
	function setNamespaces( $namespaces ) {
		$this->namespaces = $namespaces;
	}

	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return SphinxSearchResultSet
	 * @access public
	 */
	function searchText( $term, $titles_only = false ) {
		global $wgSphinxSearch_index_list;

		$cl = $this->prepareSphinxClient( $term, $titles_only );
		if ( $cl ) {
			$res = $cl->Query(
				addcslashes( $this->search_term, '/()[]"!' ),
				$wgSphinxSearch_index_list
			);
		} else {
			$res = false;
		}

		if ( $res === false ) {
			return null;
		} else {
			return new SphinxSearchResultSet( $term, $res, $cl );
		}
	}

	/**
	 * Perform a title-only search query and return a result set.
	 *
	 * @param string $term - Raw search term
	 * @return SphinxSearchResultSet
	 * @access public
	 */
	function searchTitle( $term ) {
		return $this->searchText( $term, true );
	}

	/**
	 * Search for "$term"
	 * Display the results of the search one page at a time.
	 * Returns the number of matches.
	 */
	function prepareSphinxClient( $term, $match_titles_only = false ) {
		global $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby, $wgSphinxSearch_host,
			$wgSphinxSearch_port, $wgSphinxSearch_index_weights, $wgSphinxSearch_index,
			$wgSphinxSearch_matches, $wgSphinxSearch_mode, $wgSphinxSearch_weights,
			$wgSphinxMatchAll, $wgSphinxSearch_maxmatches, $wgSphinxSearch_cutoff;

		# don't do anything for blank searches
		if ( trim( $term ) === '' ) {
			return false;
		}

		wfRunHooks( 'SphinxSearchBeforeResults', array(
			&$term,
			&$this->page,
			&$this->namespaces,
			&$this->categories,
			&$this->exc_categories
		) );

		if ( $wgSphinxSearch_mode == SPH_MATCH_EXTENDED && $wgSphinxMatchAll != '1' ) {
			# make OR the default in extended mode
			$this->search_term = preg_replace( '/[\s_\-&]+/', '|', trim( $term ) );
		} else {
			$this->search_term = $term;
		}

		$cl = new SphinxClient();

		# setup the options for searching
		if ( isset( $wgSphinxSearch_host ) && isset( $wgSphinxSearch_port ) ) {
			$cl->SetServer( $wgSphinxSearch_host, $wgSphinxSearch_port );
		}
		if ( count( $wgSphinxSearch_weights ) ) {
			if ( is_string( key( $wgSphinxSearch_weights ) ) ) {
				$cl->SetFieldWeights( $wgSphinxSearch_weights );
			} else {
				$cl->SetWeights( $wgSphinxSearch_weights );
			}
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

		if ( count( $this->categories ) ) {
			$cl->SetFilter( 'category', $this->categories );
		}

		if ( count( $this->exc_categories ) ) {
			$cl->SetFilter( 'category', $this->exc_categories, true );
		}

		if ( isset( $wgSphinxSearch_groupby ) && isset( $wgSphinxSearch_groupsort ) ) {
			$cl->SetGroupBy( $wgSphinxSearch_groupby, SPH_GROUPBY_ATTR, $wgSphinxSearch_groupsort );
		}
		$cl->SetSortMode( $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby );
		$cl->SetLimits(
			( $this->page - 1 ) * $wgSphinxSearch_matches,
			$wgSphinxSearch_matches,
			$wgSphinxSearch_maxmatches,
			$wgSphinxSearch_cutoff
		);

		if ( $match_titles_only ) {
			$this->search_term = '@page_title ' . $this->search_term;
		}

		wfRunHooks( 'SphinxSearchBeforeQuery', array( &$this->search_term, &$cl ) );

		return $cl;
	}

	function wfSphinxDisplayResults( $term, $res, $cl ) {

		global $wgOut, $wgSphinxSuggestMode, $wgSphinxSearch_matches,
			$wgSphinxSearch_index, $wgSphinxSearch_maxmatches;

		if ($cl->GetLastWarning()) {
			$wgOut->addWikiText( wfMsg( 'sphinxSearchWarning', $cl->GetLastWarning() ) . "\n\n");
		}
		$found = $res['total_found'];

		if ( $wgSphinxSuggestMode ) {
			$didyoumean = $this->spell();
			if ( $didyoumean ) {
				$wgOut->addhtml( wfMsg( 'sphinxSearchDidYouMean' ) .
					" <b><a href='" .
					$this->getActionURL( $didyoumean, $this->namespaces ) .
					"1'>" . $didyoumean . '</a></b>'
				);
			}
		}

		$from = min(
			( ( $this->page - 1 ) * $wgSphinxSearch_matches ) + 1,
			$res['total']
		);
		$to = min(
			$this->page * $wgSphinxSearch_matches,
			$res['total']
		);
		$wgOut->addWikiText( wfMsgExt( 'sphinxSearchPreamble', 'parsemag',
			$from, $to, $res['total'], $term, $res['time'] )
		);

		if ( is_array( $res["words"] ) ) {
			$warn = false;
			foreach ( $res["words"] as $word => $info ) {
				$wgOut->addWikiText(
					wfMsgExt( 'sphinxSearchStats', 'parsemag', $word, $info['hits'], $info['docs'] )
				);
				if ( ( $info['docs'] < $wgSphinxSearch_maxmatches ) && ( $info['docs'] > $res['total'] ) ) {
					$warn = true;
				}
			}
			if ( $warn ) {
				$wgOut->addWikiText( wfMsg( 'sphinxSearchStatsInfo' ) );
			} else {
				$wgOut->addWikiText( "\n" );
			}
		}
		$start_time = microtime( true );

		if ( isset( $res["matches"] ) && is_array( $res["matches"] ) ) {
			$wgOut->addWikiText( "----" );
			$dbr = wfGetDB( DB_SLAVE );
			$excerpts_opt = array(
				"before_match"    => "<span style='color:red'>",
				"after_match"     => "</span>",
				"chunk_separator" => " ... ",
				"limit"           => 400,
				"around"          => 15
			);
			foreach ( $res["matches"] as $doc => $docinfo ) {
				$page_content = $dbr->selectField(
					'text', 'old_text',
					array(
						'old_id' => $docinfo['attrs']['old_id']
					),
					__METHOD__
				);
				if ( $page_content ) {
					$title_obj = Title::newFromID( $doc );
					if ( is_object( $title_obj ) ) {
						$wiki_title = $title_obj->getPrefixedText();
						$wiki_path = $title_obj->getPrefixedDBkey();
						$wgOut->addWikiText( "* <span style='font-size:110%;'>[[:$wiki_path|$wiki_title]]</span>" );

						# uncomment this line to see the weights etc. as HTML comments in the source of the page
						# $wgOut->addHTML("<!-- page_id: ".$doc."\ninfo: ".print_r($docinfo, true)." -->");

						$excerpts = $cl->BuildExcerpts(
							array( $page_content ),
							$wgSphinxSearch_index,
							$term,
							$excerpts_opt
						);
						if ( !is_array( $excerpts ) ) {
							$excerpts = array( wfMsg( 'sphinxSearchWarning', $cl->GetLastError() ) );
						}
						foreach ( $excerpts as $entry ) {
							# add excerpt to output, removing some wiki markup
							$entry = preg_replace( '/([\[\]\{\}\*\#\|\!]+|==+)/',
								' ',
								strip_tags( $entry, '<span><br>' )
							);
							$wgOut->addHTML( "<div style='margin: 0.2em 1em 1em 1em;'>$entry</div>\n" );
						}
					}
				}
			}
			$time = number_format( microtime( true ) - $start_time, 3);
			$wgOut->addWikiText( wfMsg( 'sphinxSearchEpilogue', $time ) );
		}

		wfRunHooks( 'SphinxSearchAfterResults', array( $term, $this->page ) );

		return $found;
	}

	function getActionURL( $term ) {
		global $wgDisableInternalSearch, $wgSphinxMatchAll, $wgRequest;

		$search_title = ( $wgDisableInternalSearch ? 'Search' : 'SphinxSearch' );
		$titleObj = SpecialPage::getTitleFor( $search_title );
		$qry = $titleObj->getLocalUrl();
		$searchField = strtolower( $search_title );
		$term = urlencode( $term );
		$qry .= ( strpos( $qry, '?' ) === false ? '?' : '&amp;' ) .
			$searchField . "={$term}&amp;fulltext=" .
			wfMsg( 'sphinxSearchButton' ) .	"&amp;";
		if ( $wgSphinxMatchAll == '1' ) {
			$qry .= "match_all=1&amp;";
		}
		if ( $wgRequest->getInt( 'match_titles' ) ) {
			$qry .= "match_titles=1&amp;";
		}
		foreach ( $this->namespaces as $ns ) {
			$qry .= "ns{$ns}=1&amp;";
		}
		foreach ( $this->categories as $c ) {
			$qry .= "cat[]={$c}&amp;";
		}
		foreach ( $this->exc_categories as $c ) {
			$qry .= "exc[]={$c}&amp;";
		}
		$qry .= "page=";

		return $qry;
	}

	function createNextPageBar( $found, $term ) {
		global $wgOut, $wgSphinxSearch_matches;

		$qry = $this->getActionURL( $term );

		$display_pages = 10;
		$max_page = ceil( $found / $wgSphinxSearch_matches );
		$center_page = floor( ( $this->page + $display_pages ) / 2 );
		$first_page = $center_page - $display_pages / 2;
		if ( $first_page < 1 ) {
			$first_page = 1;
		}
		$last_page = $first_page + $display_pages - 1;
		if ( $last_page > $max_page ) {
			$last_page = $max_page;
		}
		if ( $first_page != $last_page ) {
			$wgOut->addWikiText( "----" );
			$wgOut->addHTML( "<center>
	<table border='0' cellpadding='0' width='1%' cellspacing='0'>
		<tr align='center' valign='top'>
			<td valign='bottom' nowrap='1'>" . wfMsg( 'sphinxResultPage' ) . "</td>" );

			if ( $first_page > 1 ) {
				$prev_page  = "<td>&#160;<a href='{$qry}";
				$prev_page .= ( $this->page - 1 ) . "'>" . wfMsg( 'sphinxPreviousPage' ) . "</a>&#160;</td>";
				$wgOut->addHTML( $prev_page );
			}
			for ( $i = $first_page; $i < $this->page; $i++ ) {
				$wgOut->addHTML( "<td>&#160;<a href='{$qry}{$i}'>{$i}</a>&#160;</td>" );
			}
			$wgOut->addHTML( "<td>&#160;<b>{$this->page}</b>&#160;</td>" );
			for ( $i = $this->page + 1; $i <= $last_page; $i++ ) {
				$wgOut->addHTML( "<td>&#160;<a href='{$qry}{$i}'>{$i}</a>&#160;</td>" );
			}
			if ( $last_page < $max_page ) {
				$next_page  = "<td>&#160;<a href='{$qry}";
				$next_page .= ( $this->page + 1 ) . "'>" . wfMsg( 'sphinxNextPage' ) . "</a>&#160;</td>";
				$wgOut->addHTML( $next_page );
			}

			$wgOut->addHTML( "</tr></table></center>" );
		}
	}

	function createNewSearchForm( $term ) {
		global $wgOut, $wgDisableInternalSearch, $wgSphinxSearch_mode, $wgSphinxMatchAll,
			$wgUseExcludes, $wgUseAjax, $wgJsMimeType, $wgScriptPath,
			$wgSphinxSearchExtPath, $wgSphinxSearchJSPath, $wgRequest;

		$search_title = ( $wgDisableInternalSearch ? 'Search' : 'SphinxSearch' );
		$titleObj = SpecialPage::getTitleFor( $search_title );
		$kiAction = $titleObj->getLocalUrl();
		$searchField = strtolower( $search_title );
		$wgOut->addHTML( "<form action='$kiAction' method='GET'>
				<input type='hidden' name='title' value='" . $titleObj->getPrefixedText() . "'>
				<input type='text' name='$searchField' maxlength='100' value='$term'>
				<input type='submit' name='fulltext' value='" . wfMsg( 'sphinxSearchButton' ) . "'>" );

		$wgOut->addHTML( "<div style='margin:0.5em 0 0.5em 0;'>" );
		if ( $wgSphinxSearch_mode == SPH_MATCH_EXTENDED ) {
			$wgOut->addHTML( "<input type='radio' name='match_all' value='0' " .
				( $wgSphinxMatchAll ? "" : "checked='checked'" ) . " />" .
				wfMsg( 'sphinxMatchAny' ) .
				" <input type='radio' name='match_all' value='1' " .
				( $wgSphinxMatchAll ? "checked='checked'" : "" ) . " />" .
				wfMsg( 'sphinxMatchAll' )
			);
		}
		$wgOut->addHTML( " &#160; <input type='checkbox' name='match_titles' value='1' " .
			( $wgRequest->getInt( 'match_titles' ) ? "checked='checked'" : "" ) . ">" .
			wfMsg( 'sphinxMatchTitles' ) . "</div>"
		);
		# get user settings for which namespaces to search
		$wgOut->addHTML( "<div style='width:30%; border:1px #eee solid; padding:4px; margin-right:1px; float:left;'>" );
		$wgOut->addHTML( wfMsg( 'sphinxSearchInNamespaces' ) . '<br />' );
		$all_namespaces = self::searchableNamespaces();
		foreach ( $all_namespaces as $ns => $name ) {
			$checked = in_array( $ns, $this->namespaces ) ? ' checked="checked"' : '';
			$name = str_replace( '_', ' ', $name );
			if ( '' == $name ) {
				$name = wfMsg( 'blanknamespace' );
			}
			$wgOut->addHTML( "<label><input type='checkbox' value='1' name='ns$ns'$checked />$name</label><br />" );
		}

		$all_categories = self::searchableCategories();
		if ( is_array( $all_categories ) && count( $all_categories ) ) {
			$cat_parents = $wgRequest->getIntArray( "catp", array() );
			$wgOut->addScript( Skin::makeVariablesScript( array(
				'sphinxLoadingMsg'      => wfMsg( 'sphinxLoading' ),
				'wgSphinxSearchExtPath' => ( $wgSphinxSearchJSPath ? $wgSphinxSearchJSPath : $wgSphinxSearchExtPath )
			) ) );
			$wgOut->addScript(
				"<script type='{$wgJsMimeType}' src='" .
				( $wgSphinxSearchJSPath ? $wgSphinxSearchJSPath : $wgSphinxSearchExtPath ) .
				"/SphinxSearch.js?2'></script>\n"
			);
			$wgOut->addHTML( "</div>
				<div style='width:30%; border:1px #eee solid; padding:4px; margin-right:1px; float:left;'>"
			);
			$wgOut->addHTML( wfMsg('sphinxSearchInCategories') );
			if ( $wgUseExcludes ) {
				$wgOut->addHTML("<div style='float:right; font-size:80%;'>exclude</div>");
			}
			$wgOut->addHTML('<br />');
			$wgOut->addHTML( $this->getCategoryCheckboxes( $all_categories, '', $cat_parents ) );
		}
		$wgOut->addHTML( "</div></form><br clear='both' />" );

		# Put a Sphinx label for this search
		$wgOut->addHTML( "<div style='text-align:center'>" .
			wfMsg( 'sphinxPowered', "<a href='http://www.sphinxsearch.com/'>Sphinx</a>" ) .
			"</div>"
		);
	}

	function getCategoryCheckboxes( $all_categories, $parent_id, $cat_parents = array() ) {
		global $wgUseAjax, $wgRequest, $wgUseExcludes;

		$html = '';

		foreach ( $all_categories as $cat => $name ) {
			$input_attrs = '';
			if ( $this && in_array( $cat, $this->categories ) ) {
				$input_attrs .= ' checked="checked"';
			}
			$name = str_replace( '_', ' ', $name );
			if ( '' == $name ) {
				$name = wfMsg( 'blanknamespace' );
			}
			$children = '';
			if ( isset( $cat_parents['_' . $cat] ) && ( $input_attrs || $cat_parents['_' . $cat] > 0 ) ) {
				$title = Title::newFromID( $cat );
				$children_cats = self::getChildrenCategories( $title->getDBkey() );
				if ( count( $children_cats ) ) {
					if ( $this ) {
						$children = $this->getCategoryCheckboxes( $children_cats, $cat, $cat_parents );
					} else {
						$children = self::getCategoryCheckboxes( $children_cats, $cat, $cat_parents );
					}
				}
			}
			if ( $wgUseAjax ) {
				$input_attrs .= " onmouseup='sphinxShowCats(this)'";
			}
			$html .= "<label><input type='checkbox' id='{$parent_id}_$cat' value='$cat' name='cat[]'$input_attrs />$name</label>";
			if ( $wgUseExcludes ) {
				$input_attrs = '';
				if ( $this && in_array( $cat, $this->exc_categories ) ) {
					$input_attrs .= ' checked="checked"';
				}
				if ( $wgUseAjax ) {
					$input_attrs .= " onmouseup='sphinxShowCats(this)'";
				}
				$html .= "<input type='checkbox' id='exc_{$parent_id}_$cat' value='$cat' name='exc[]'$input_attrs style='float:right' />";
			}
			$html .= "<div id='cat{$cat}_children'>$children</div>\n";
		}
		if ( $parent_id && $html ) {
			$html = "<input type='hidden' name='catp[_$parent_id]' value='" .
				intval( $cat_parents['_' . $parent_id] ) .
				"' /><div style='margin-left:10px; margin-bottom:4px; padding-left:8px; border-left:1px dashed #ccc; border-bottom:1px solid #ccc;'>" .
				$html . "</div>";
		}
		return $html;
	}

	function spell() {
		$string = str_replace( '"', '', $this->search_term );
		$words = preg_split( '/(\s+|\|)/', $string, -1, PREG_SPLIT_NO_EMPTY );
		if ( function_exists( 'pspell_check' ) ) {
			$suggestion = $this->builtin_spell($words);
		} else {
			$suggestion = $this->nonnative_spell($words);
		}
		return $suggestion;
	}

	function builtin_spell($words) {
		global $wgUser, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchPspellDictionaryDir;

		$ret = '';
		$suggestion_needed = false;
		foreach ( $words as $word ) {
			$pspell_config = pspell_config_create(
				$wgUser->getDefaultOption( 'language' ),
				$wgUser->getDefaultOption( 'variant' )
			);
			if ( $wgSphinxSearchPspellDictionaryDir ) {
				pspell_config_data_dir( $pspell_config, $wgSphinxSearchPspellDictionaryDir );
				pspell_config_dict_dir( $pspell_config, $wgSphinxSearchPspellDictionaryDir );
			}
			pspell_config_mode( $pspell_config, PSPELL_FAST | PSPELL_RUN_TOGETHER );
			if ( $wgSphinxSearchPersonalDictionary ) {
				pspell_config_personal( $pspell_config, $wgSphinxSearchPersonalDictionary );
			}
			$pspell_link = pspell_new_config( $pspell_config );

			if ( !$pspell_link ) {
				return wfMsg( 'sphinxPspellError' );
			}
			if ( !pspell_check( $pspell_link, $word ) ) {
				$suggestions = pspell_suggest( $pspell_link, $word );
				if ( count( $suggestions ) ) {
					$guess = array_shift($suggestions);
				} else {
					$guess = '';
				}
				if ( !$guess || (strtolower( $word ) == strtolower( $guess )) ) {
					$ret .= "$word ";
				} else {
					$ret .=  "$guess ";
					$suggestion_needed = true;
				}
			} else {
				$ret .= "$word ";
			}
		}

		return ( $suggestion_needed ? trim( $ret ) : '' );
	}

	function nonnative_spell($words) {
		global $wgUser, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchAspellPath;

		// aspell will only return mis-spelled words, so remember all here
		$word_suggestions = array();
		foreach ( $words as $word ) {
			$word_suggestions[$word] = $word;
		}

		// prepare the system call with optional dictionary
		$aspellcommand = 'echo ' . escapeshellarg( join( ' ', $words ) ) .
			' | ' . escapeshellarg( $wgSphinxSearchAspellPath ) .
			' -a --ignore-accents --ignore-case';
		if ( $wgUser ) {
			$aspellcommand .= ' --lang=' . $wgUser->getDefaultOption( 'language' );
		}
		if ( $wgSphinxSearchPersonalDictionary ) {
			$aspellcommand .= ' --home-dir=' . dirname( $wgSphinxSearchPersonalDictionary );
			$aspellcommand .= ' -p ' . basename( $wgSphinxSearchPersonalDictionary );
		}

		// run aspell
		$shell_return = shell_exec( $aspellcommand );

		// parse return line by line
		$returnarray = explode( "\n", $shell_return );
		$suggestion_needed = false;
		foreach ( $returnarray as $key => $value ) {
			// lines with suggestions start with &
			if ( substr( $value, 0, 1 ) == "&" ) {
				$correction = explode( " ", $value );
				$word = $correction[1];
				$suggestions = substr( $value, strpos( $value, ":" ) + 2 );
				$suggestions = explode( ", ", $suggestions );
				if (count($suggestions)) {
					$guess = array_shift($suggestions);
					if ( strtolower( $word ) != strtolower( $guess ) ) {
						$word_suggestions[$word] = $guess;
						$suggestion_needed = true;
					}
				}
			}
		}

		return ( $suggestion_needed ? join( ' ', $word_suggestions ) : '' );
	}

}

/**
 * @ingroup Search
 */
class SphinxSearchResultSet extends SearchResultSet {
	var $mNdx = 0;

	function SphinxSearchResultSet( $term, $rs, $cl ) {
		global $wgSphinxSearch_index;

		$this->mResultSet = array();
		if ( is_array( $rs ) && is_array( $rs['matches'] ) ) {
			$dbr = wfGetDB( DB_SLAVE );
			foreach ( $rs['matches'] as $id => $docinfo ) {
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
		$this->mTerms = $term;
	}

	function termMatches() {
		return $this->mTerms;
	}

	function numRows() {
		return count( $this->mResultSet );
	}

	function next() {
		if ( isset( $this->mResultSet[$this->mNdx] ) ) {
			$row = $this->mResultSet[$this->mNdx];
			++$this->mNdx;
			return new SearchResult( $row );
		} else {
			return false;
		}
	}

	function free() {
		unset( $this->mResultSet );
	}
}
