# Extension:SphinxSearch

MediaWiki full instructions: [Extension:SphinxSearch](https://www.mediawiki.org/wiki/Extension:SphinxSearch)

## Compatibility

This extension is compatible with both [SphinxSearch](https://sphinxsearch.com/) and the successor/fork [ManticoreSearch](https://manticoresearch.com/).

Versions confirmed working:

* [SphinxSearch](https://sphinxsearch.com/downloads/archive/) up to v2.2.11
  * Untested with v2.3+
  * Untested with v3+
* [ManticoreSearch](https://manticoresearch.com/install/) up to v9.2.14

There are some configurations incompatible with Sphinx/Manticore:

* This extension will **not** work if you [compress all page revisions via `$wgCompressRevisions`](https://www.mediawiki.org/wiki/Manual:$wgCompressRevisions).
* This extension is **not** compatible with [Extension:TitleKey](https://www.mediawiki.org/wiki/Extension:TitleKey)
  * Enable `$wgEnableSphinxPrefixSearch` and/or `$wgEnableSphinxInfixSearch` to enable title completion

## Installation

See [Extension:SphinxSearch](https://www.mediawiki.org/wiki/Extension:SphinxSearch) for full details.

Add this configuration to your `LocalSettings.php`:

```php
//
// Extension:SphinxSearch
//
$wgSearchType = 'SphinxMWSearch';
wfLoadExtension( 'SphinxSearch' );

// config options (and defaults)
$wgSphinxSearch_host = "127.0.0.1";
$wgSphinxSearch_port = 9312;
$wgSphinxSearch_index = "wiki_main";
$wgSphinxSearch_index_list = "*";
$wgSphinxSearch_index_weights = "null";
$wgSphinxSearch_mode = 4;
$wgSphinxSearch_sortmode = 0;
$wgSphinxSearch_sortby = "";
$wgSphinxSearch_maxmatches = 1000;
$wgSphinxSearch_cutoff = 0;
$wgSphinxSearch_weights = null;
$wgSphinxSearchMWHighlighter = false;
$wgSphinxSuggestMode = "soundex";
$wgSphinxSearchAspellPath = "aspell";
$wgSphinxSearchPersonalDictionary = "";
$wgEnableSphinxPrefixSearch = true;
$wgEnableSphinxInfixSearch = true;
$wgSphinxSearchContextLines = 2;
$wgSphinxSearchContextChars = 75;
```

## Credits

* Initial development by Paul Grinberg, based on the idea [from Hank](http://www.ralree.info/2007/9/15/fulltext-indexing-wikipedia-with-sphinx/)
* v1.0 by [Svemir Brkic](https://deveblog.com)
* [Other contributors](https://github.com/xingrz/node-contributors), ordered by date of first contribution.
  * Alexandre Emsenhuber
  * Raimond Spekking
  * Siebrand Mazeland
  * Meno25
  * Chad Horohoe
  * Aryeh Gregor
  * Platonides
  * Brion Vibber
  * Chad Horohoe
  * Reedy
  * Timo Tijhof
  * [Svemir Brkic](https://github.com/svemir)
  * Siebrand Mazeland
  * Daniel De Marco
  * addshore
  * [Nic Jansma](https://github.com/nicjansma)
  * umherirrender
  * Ryan Glasnapp
  * Greg Sabino Mullane
  * Justin Du
  * Antoine Musso
  * Amir Sarabadani
  * Paladox
  * Kunal Mehta
  * Artom Lifshitz
  * [Zoran Dori](https://github.com/kizule)
  * DannyS712
  * Aaron Schulz
  * Thijs Kinkhorst
  * Svemir Brkic
  * [Edward Chernenko](https://github.com/edwardspec)

## Version History

* v1.0 - 2010-04-02 through 2025-04-22 - Svemir Brkic and others
* v1.1 - 2025-04-23 - Nic Jansma
  * Changed `$wgEnableSphinxPrefixSearch` and `$wgEnableSphinxInfixSearch` to use `completionSearchBackend()`
  * Removed `prefixSearch()` and `infixSearch()` as they're handled by `completionSearchBackend()`
  * Cleaned up unused / deprecated variables like `$wgHooks`
  * Removed deprecated `PrefixSearchBackend` hook
  * `SphinxMWSearchResult`: Keep a local copy of `$terms`
  * `SphinxMWSearch`: Ensure title search only highlights the terms and not the search clause (e.g. `title` in `@page_title`)
  * `$wgSphinxSearchContextLines` option added
  * `$wgSphinxSearchContextChars` option added
  * Fixed `preg_split()` deprecation on `null` for 3rd parameter
  * `SphinxMWSearchResultSet`: Added required `extractResults()` override
  * Minor formatting and variable naming changes for readability and consistency
  * `extension.json` to v2 manifest