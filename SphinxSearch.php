<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'SphinxSearch' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['SphinxSearch'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['SphinxSearchAlias'] = __DIR__ . '/FooBar.alias.php';
	wfWarn(
		'Deprecated PHP entry point used for the SphinxSearch extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of SphinxSearch extension requires MediaWiki 1.25+' );
}
