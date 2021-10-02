<?php
/**
 * Class file for SphinxMWSearchResult
 *
 * https://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 * @file
 * @ingroup Extensions
 * @author Svemir Brkic <svemir@deveblog.com>
 */

class SphinxMWSearchResult extends RevisionSearchResult {

	/** @var SphinxClient|null */
	public $sphinx_client = null;

	/**
	 * @param stdClass $row
	 * @param SphinxClient|null $sphinx_client
	 */
	public function __construct( $row, $sphinx_client ) {
		$this->sphinx_client = $sphinx_client;
		$this->initFromTitle( Title::makeTitle( $row->page_namespace, $row->page_title ) );
	}

	/**
	 * Emulates SearchEngine getTextSnippet so that we can use our own userHighlightPrefs
	 *
	 * @param array $terms
	 * @return string highlighted text snippet
	 */
	public function getTextSnippet( $terms = [] ) {
		global $wgAdvancedSearchHighlighting, $wgSphinxSearchMWHighlighter, $wgSphinxSearch_index;

		$this->initText();
		$contextlines = 2;
		$contextchars = 75;
		if ( $wgSphinxSearchMWHighlighter ) {
			$h = new SearchHighlighter();
			if ( $wgAdvancedSearchHighlighting ) {
				return $h->highlightText( $this->mText, $terms, $contextlines, $contextchars );
			} else {
				return $h->highlightSimple( $this->mText, $terms, $contextlines, $contextchars );
			}
		}

		$excerpts_opt = [
			"before_match" => "(searchmatch)",
			"after_match" => "(/searchmatch)",
			"chunk_separator" => " ... ",
			"limit" => $contextlines * $contextchars,
			"around" => $contextchars,
		];

		$excerpts = $this->sphinx_client->BuildExcerpts(
			[ $this->mText ],
			$wgSphinxSearch_index,
			implode( ' ', $terms ),
			$excerpts_opt
		);

		if ( is_array( $excerpts ) ) {
			$ret = '';
			foreach ( $excerpts as $entry ) {
				// remove some wiki markup
				$entry = preg_replace(
					'/([\[\]\{\}\*\#\|\!]+|==+|<br ?\/?>)/',
					' ',
					$entry
				);
				$entry = str_replace(
					[ "<", ">" ],
					[ "&lt;", "&gt;" ],
					$entry
				);
				$entry = str_replace(
					[ "(searchmatch)", "(/searchmatch)" ],
					[ "<span class='searchmatch'>", "</span>" ],
					$entry
				);
				$ret .= "<div style='margin: 0.2em 1em 0.2em 1em;'>$entry</div>\n";
			}
		} else {
			$ret = wfMessage( 'internalerror_info', $this->sphinx_client->GetLastError() );
		}
		return $ret;
	}

}
