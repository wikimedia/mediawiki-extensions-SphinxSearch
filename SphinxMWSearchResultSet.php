<?php

/**
 * Class file for the SphinxMWSearchResultSet
 *
 * https://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 * @file
 * @ingroup Extensions
 * @author Svemir Brkic <svemir@deveblog.com>
 */

use Wikimedia\Rdbms\IDatabase;

class SphinxMWSearchResultSet extends SearchResultSet {

	/** @var int */
	public $mNdx = 0;
	/** @var SphinxClient */
	public $sphinx_client;
	/** @var string */
	public $mSuggestion = '';
	/** @var IDatabase */
	public $db;
	/** @var int */
	public $total_hits = 0;

	/**
	 * @param array $resultSet
	 * @param array $terms
	 * @param SphinxClient $sphinx_client
	 * @param IDatabase|null $dbr
	 */
	public function __construct( $resultSet, $terms, $sphinx_client, $dbr ) {
		global $wgSearchHighlightBoundaries;

		$this->sphinx_client = $sphinx_client;
		$this->mResultSet = [];
		$this->db = $dbr ? $dbr : wfGetDB( DB_REPLICA );
		if ( is_array( $resultSet ) && isset( $resultSet['matches'] ) ) {
			$this->total_hits = $resultSet[ 'total_found' ];
			foreach ( $resultSet['matches'] as $id => $docinfo ) {
				$row = $this->db->selectRow(
					'page',
					[ 'page_id', 'page_title', 'page_namespace' ],
					[ 'page_id' => $id ],
					__METHOD__,
					[]
				);
				if ( $row ) {
					$this->mResultSet[] = $row;
				}
			}
		}
		$this->mNdx = 0;
		$this->mTerms = preg_split(
			"/$wgSearchHighlightBoundaries+/ui",
			$terms,
			null,
			PREG_SPLIT_NO_EMPTY
		);
	}

	/**
	 * Some search modes return a suggested alternate term if there are
	 * no exact hits. Returns true if there is one on this set.
	 *
	 * @return bool
	 */
	public function hasSuggestion() {
		global $wgSphinxSuggestMode;

		if ( $wgSphinxSuggestMode ) {
			$this->mSuggestion = '';
			if ( $wgSphinxSuggestMode === 'enchant' ) {
				$this->suggestWithEnchant();
			} elseif ( $wgSphinxSuggestMode === 'soundex' ) {
				$this->suggestWithSoundex();
			} elseif ( $wgSphinxSuggestMode === 'aspell' ) {
				$this->suggestWithAspell();
			}
			if ( $this->mSuggestion ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wiki-specific search suggestions using enchant library.
	 * Use SphinxSearch_setup.php to create the dictionary
	 */
	private function suggestWithEnchant() {
		if ( !function_exists( 'enchant_broker_init' ) ) {
			return;
		}
		$broker = enchant_broker_init();
		enchant_broker_set_dict_path( $broker, ENCHANT_MYSPELL, __DIR__ );
		if ( enchant_broker_dict_exists( $broker, 'sphinx' ) ) {
			$dict = enchant_broker_request_dict( $broker, 'sphinx' );
			$suggestion_found = false;
			$full_suggestion = '';
			foreach ( $this->mTerms as $word ) {
				if ( !enchant_dict_check( $dict, $word ) ) {
					$suggestions = enchant_dict_suggest( $dict, $word );
					while ( count( $suggestions ) ) {
						$candidate = array_shift( $suggestions );
						if ( strtolower( $candidate ) != strtolower( $word ) ) {
							$word = $candidate;
							$suggestion_found = true;
							break;
						}
					}
				}
				$full_suggestion .= $word . ' ';
			}
			enchant_broker_free_dict( $dict );
			if ( $suggestion_found ) {
				$this->mSuggestion = trim( $full_suggestion );
			}
		}
		enchant_broker_free( $broker );
	}

	/**
	 * Default (weak) suggestions implementation relies on MySQL soundex
	 */
	private function suggestWithSoundex() {
		$joined_terms = $this->db->addQuotes( implode( ' ', $this->mTerms ) );
		$suggestionTitle = $this->db->selectField(
			[ 'page' ],
			'page_title',
			[
				"page_title SOUNDS LIKE " . $joined_terms,
				// avoid (re)recommending the search string
				"page_title NOT LIKE " . $joined_terms
			],
			__METHOD__,
			[
				'ORDER BY' => 'page_len desc',
				'LIMIT' => 1
			]
		);
		if ( $suggestionTitle !== false ) {
			$title = Title::newFromDBkey( $suggestionTitle );
			$this->mSuggestion = $title->getText();
		}
	}

	private function suggestWithAspell() {
		global $wgLanguageCode, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchAspellPath;

		// aspell will only return mis-spelled words, so remember all here
		$words = $this->mTerms;
		$word_suggestions = [];
		foreach ( $words as $word ) {
			$word_suggestions[ $word ] = $word;
		}

		// prepare the system call with optional dictionary
		$aspellcommand = 'echo ' . escapeshellarg( implode( ' ', $words ) ) .
			' | ' . escapeshellarg( $wgSphinxSearchAspellPath ) .
			' -a --ignore-accents --ignore-case --lang=' . $wgLanguageCode;
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
			if ( $value[0] === '&' ) {
				$correction = explode( ' ', $value );
				$word = $correction[ 1 ];
				$suggestions = substr( $value, strpos( $value, ':' ) + 2 );
				$suggestions = explode( ', ', $suggestions );
				if ( count( $suggestions ) ) {
					$guess = array_shift( $suggestions );
					if ( strtolower( $word ) != strtolower( $guess ) ) {
						$word_suggestions[ $word ] = $guess;
						$suggestion_needed = true;
					}
				}
			}
		}

		if ( $suggestion_needed ) {
			$this->mSuggestion = implode( ' ', $word_suggestions );
		}
	}

	/**
	 * @return string suggested query, null if none
	 */
	public function getSuggestionQuery() {
		return $this->mSuggestion;
	}

	/**
	 * @return string HTML highlighted suggested query, '' if none
	 */
	public function getSuggestionSnippet() {
		return $this->mSuggestion;
	}

	/**
	 * @return array search terms
	 */
	public function termMatches() {
		return $this->mTerms;
	}

	/**
	 * @return int number of results
	 */
	public function numRows() {
		return count( $this->mResultSet );
	}

	/**
	 * Some search modes return a total hit count for the query
	 * in the entire article database. This may include pages
	 * in namespaces that would not be matched on the given
	 * settings.
	 *
	 * Return null if no total hits number is supported.
	 *
	 * @return int
	 */
	public function getTotalHits() {
		return $this->total_hits;
	}

	/**
	 * @return SphinxMWSearchResult next result, false if none
	 */
	public function next() {
		if ( isset( $this->mResultSet[$this->mNdx] ) ) {
			$row = $this->mResultSet[$this->mNdx];
			++$this->mNdx;
			return new SphinxMWSearchResult( $row, $this->sphinx_client );
		} else {
			return false;
		}
	}

	/**
	 * Clear the result set from memory
	 */
	public function free() {
		unset( $this->mResultSet );
	}

}
