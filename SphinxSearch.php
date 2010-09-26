<?php

# Alert the user that this is not a valid entry point to MediaWiki if they try to access the file directly.
if ( !defined( 'MEDIAWIKI' ) ) {
	echo <<<EOT
To install SphinxSearch extension, put the following line in LocalSettings.php:
require_once( "\$IP/extensions/SphinxSearch/SphinxSearch.php" );
EOT;
	exit( 1 );
}

$wgExtensionCredits['specialpage'][] = array(
	'path'           => __FILE__,
	'version'        => '0.7.2',
	'name'           => 'SphinxSearch',
	'author'         => array( 'Svemir Brkic', 'Paul Grinberg' ),
	'email'          => 'svemir at deveblog dot com, gri6507 at yahoo dot com',
	'url'            => 'http://www.mediawiki.org/wiki/Extension:SphinxSearch',
	'descriptionmsg' => 'sphinxsearch-desc'
);

$dir = dirname( __FILE__ ) . '/';

$wgExtensionMessagesFiles['SphinxSearch'] = $dir . 'SphinxSearch.i18n.php';

# To completely disable the default search and replace it with SphinxSearch,
# set this BEFORE including SphinxSearch.php in LocalSettings.php
# $wgSearchType = 'SphinxSearch';
# To use the new approach (added in 0.7.2) set it to SphinxMWSearch
if ( $wgSearchType == 'SphinxMWSearch' ) {
	$wgAutoloadClasses['SphinxMWSearch'] = $dir . 'SphinxMWSearch.php';
} else {
	$wgAutoloadClasses['SphinxSearch'] = $dir . 'SphinxSearch_body.php';
	if ( $wgSearchType == 'SphinxSearch' ) {
		$wgDisableInternalSearch = true;
		$wgDisableSearchUpdate = true;
		$wgSpecialPages['Search'] = 'SphinxSearch';
		$wgDisableSearchUpdate = true;
	} else {
		$wgExtensionAliasesFiles['SphinxSearch'] = $dir . 'SphinxSearch.alias.php';
		$wgSpecialPages['SphinxSearch'] = 'SphinxSearch';
	}
}

# this assumes you have copied sphinxapi.php from your Sphinx
# installation folder to your SphinxSearch extension folder
# not needed if you install http://pecl.php.net/package/sphinx
if ( !class_exists( 'SphinxClient' ) ) {
	require_once ( $dir . "sphinxapi.php" );
}

# Host and port on which searchd deamon is running
$wgSphinxSearch_host = 'localhost';
$wgSphinxSearch_port = 9312;

# Main sphinx.conf index to search
$wgSphinxSearch_index = "wiki_main";

# By default, we search all available indexes
# You can also specify them explicitly, e.g
# $wgSphinxSearch_index_list = "wiki_main,wiki_incremental";
$wgSphinxSearch_index_list = "*";

# If you have multiple index files, you can specify their weights like this
# See http://www.sphinxsearch.com/docs/current.html#api-func-setindexweights
# $wgSphinxSearch_index_weights = array(
#	"wiki_main" => 100,
#	"wiki_incremental" => 10
# );
$wgSphinxSearch_index_weights = null;

# Default Sphinx search mode
$wgSphinxSearch_mode = SPH_MATCH_EXTENDED;

# Default sort mode
$wgSphinxSearch_sortmode = SPH_SORT_RELEVANCE;
$wgSphinxSearch_sortby = '';

if ( $wgSearchType == 'SphinxMWSearch' ) {
	# Following settings apply only in the new search model

	# Set to true to use MW's default search snippets and highlighting
	$wgSphinxSearchMWHighlighter = false;
} else {
	# Following settings apply only in the old search model

	# By default, search will return articles that match any of the words in the search
	# To change that to require all words to match by default, set the following to true
	$wgSphinxMatchAll = false;

	# Number of matches to display at once
	$wgSphinxSearch_matches = 10;

	# To enable hierarchical category search, specify the top category of your hierarchy
	$wgSphinxTopSearchableCategory = '';

	# This will fetch sub-categories as parent categories are checked
	# Requires $wgUseAjax to be true
	$wgAjaxExportList[] = 'SphinxSearch::ajaxGetCategoryChildren';

	# Allow excluding selected categories when filtering
	$wgUseExcludes = false;

	# Web-accessible path to the extension's folder
	$wgSphinxSearchExtPath = $wgScriptPath . '/extensions/SphinxSearch';

	# Web-accessible path to the folder with SphinxSearch.js file (if different from $wgSphinxSearchExtPath)
	$wgSphinxSearchJSPath = '';
}

# #########################################################
# Use Aspell to suggest possible misspellings. This can be provided via
# PHP pspell module (http://www.php.net/manual/en/ref.pspell.php)
# or command line insterface to ASpell

# Should the suggestion mode be enabled?
$wgSphinxSuggestMode = false;

# Path to personal dictionary (for example personal.en.pws.) Needed only if using a personal dictionary
$wgSphinxSearchPersonalDictionary = '';

# Path to Aspell. Used only if your PHP does not have the pspell extension.
$wgSphinxSearchAspellPath = "/usr/bin/aspell";

# Path to aspell location and language data files. Do not set if not sure.
$wgSphinxSearchPspellDictionaryDir = '';

# How many matches searchd will keep in RAM while searching
$wgSphinxSearch_maxmatches = 1000;

# When to stop searching all together (if not zero)
$wgSphinxSearch_cutoff = 0;

# Weights of individual indexed columns. This gives page titles extra weight
$wgSphinxSearch_weights = array(
	'old_text' => 1,
	'page_title' => 100
);
