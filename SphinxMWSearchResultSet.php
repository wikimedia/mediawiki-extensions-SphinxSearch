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
	public $resultSetIndex = 0;

	/** @var SphinxClient */
	public $sphinxClient;

	/** @var string */
	public $suggestion = '';

	/** @var IDatabase */
	public $db;

	/** @var int */
	public $totalHits = 0;

	/** @var array */
	public $resultSet = null;

	/** @var array */
	public $terms = null;

	/**
	 * @param array $resultSet
	 * @param array $terms
	 * @param SphinxClient $sphinxClient
	 * @param IDatabase $dbr
	 */
	public function __construct( $resultSet, $terms, $sphinxClient, $dbr ) {
		global $wgSearchHighlightBoundaries;

		$this->sphinxClient = $sphinxClient;
		$this->resultSet = [];
		$this->db = $dbr;

		if ( is_array( $resultSet ) && isset( $resultSet['matches'] ) ) {
			$this->totalHits = $resultSet[ 'total_found' ];

			foreach ( $resultSet['matches'] as $id => $docinfo ) {
				$row = $this->db->selectRow(
					'page',
					[ 'page_id', 'page_title', 'page_namespace' ],
					[ 'page_id' => $id ],
					__METHOD__,
					[]
				);

				if ( $row ) {
					$this->resultSet[] = $row;
				}
			}
		}

		$this->resultSetIndex = 0;

		$this->terms = preg_split(
			"/$wgSearchHighlightBoundaries+/ui",
			$terms,
			-1,
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

			$this->suggestion = '';

			if ( $wgSphinxSuggestMode === 'enchant' ) {
				$this->suggestWithEnchant();
			} elseif ( $wgSphinxSuggestMode === 'soundex' ) {
				$this->suggestWithSoundex();
			} elseif ( $wgSphinxSuggestMode === 'aspell' ) {
				$this->suggestWithAspell();
			}

			if ( $this->suggestion ) {
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

			foreach ( $this->terms as $word ) {
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
				$this->suggestion = trim( $full_suggestion );
			}
		}

		enchant_broker_free( $broker );
	}

	/**
	 * Default (weak) suggestions implementation relies on MySQL soundex
	 */
	private function suggestWithSoundex() {
		$joined_terms = $this->db->addQuotes( implode( ' ', $this->terms ) );

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
			$this->suggestion = $title->getText();
		}
	}

	private function suggestWithAspell() {
		global $wgLanguageCode, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchAspellPath;

		// aspell will only return mis-spelled words, so remember all here
		$words = $this->terms;
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
			$this->suggestion = implode( ' ', $word_suggestions );
		}
	}

	/**
	 * @return string suggested query, null if none
	 */
	public function getSuggestionQuery() {
		return $this->suggestion;
	}

	/**
	 * @return string HTML highlighted suggested query, '' if none
	 */
	public function getSuggestionSnippet() {
		return $this->suggestion;
	}

	/**
	 * @return array search terms
	 */
	public function termMatches() {
		return $this->terms;
	}

	/**
	 * @return int number of results
	 */
	public function numRows() {
		return count( $this->resultSet );
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
		return $this->totalHits;
	}

	/**
	 * @return SphinxMWSearchResult next result, false if none
	 */
	public function next() {
		if ( isset( $this->resultSet[$this->resultSetIndex] ) ) {
			$row = $this->resultSet[$this->resultSetIndex];
			++$this->resultSetIndex;

			return new SphinxMWSearchResult( $row, $this->sphinxClient, $this->terms );
		} else {
			return false;
		}
	}

	/**
	 * Clear the result set from memory
	 */
	public function free() {
		unset( $this->resultSet );
	}

	public function extractResults() {
		if ( $this->results === null ) {
			$this->results = [];

			if ( $this->numRows() == 0 ) {
				// Don't bother if we've got empty res
				return $this->results;
			}

			// Add existing results first
			foreach ( $this as $result ) {
				$this->results[] = $result;
			}

			// Add from Sphinx result set
			$run = $this->next();
			while ( $run ) {
				$this->results[] = $run;
				$run = $this->next();
			}
		}

		return $this->results;
	}
}
