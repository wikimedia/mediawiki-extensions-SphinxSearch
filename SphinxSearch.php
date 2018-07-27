<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'SphinxSearch' );
	/* wfWarn(
		'Deprecated PHP entry point used for SphinxSearch extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	); */
	return true;
} else {
	die( 'This version of the SphinxSearch extension requires MediaWiki 1.25+' );
}


# To completely disable the default search and replace it with SphinxSearch,
# set this BEFORE including SphinxSearch.php in LocalSettings.php
# $wgSearchType = 'SphinxMWSearch';
# All other variables should be set AFTER you include this file in LocalSettings


class SphinxSearchInit {
    function initialize() {
        global $wgHooks, $wgEnableSphinxPrefixSearch, $wgSearchType, $wgDisableSearchUpdate;

        if ( !class_exists( 'SphinxClient' ) ) {
            if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
                require_once __DIR__ . '/vendor/autoload.php';
            }
            //require_once ( dirname( __FILE__ ) . '/vendor/neutron/sphinxsearch-api/' . "sphinxapi.php" );
        }

        # Prior to version 0.8.0 there was a SphinxSearch search type
        if ( $wgSearchType == 'SphinxSearch' ) {
            $wgSearchType == 'SphinxMWSearch';
        }

        if ( $wgSearchType == 'SphinxMWSearch' ) {
            $wgDisableSearchUpdate = true;
        }

        wfDebug( 'SphinxSearchInit::initialize: running.' );
        if ( $wgEnableSphinxPrefixSearch ) {
            $wgHooks[ 'PrefixSearchBackend' ][ ] = 'SphinxMWSearch::prefixSearch';
        }
    }
}
