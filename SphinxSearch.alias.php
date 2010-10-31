<?php

/**
 * Aliases for special pages
 *
 * @file
 * @ingroup Extensions
 */

$specialPageAliases = array();

/** English (English) */
$specialPageAliases['en'] = array(
	'SphinxSearch' => array( 'SphinxSearch' ),
);

/** Arabic (العربية) */
$specialPageAliases['ar'] = array(
	'SphinxSearch' => array( 'بحث_سفنكس' ),
);

/** Interlingua (Interlingua) */
$specialPageAliases['ia'] = array(
	'SphinxSearch' => array( 'Recerca_Sphinx' ),
);

/** Japanese (日本語) */
$specialPageAliases['ja'] = array(
	'SphinxSearch' => array( 'Sphinx検索' ),
);

/** Luxembourgish (Lëtzebuergesch) */
$specialPageAliases['lb'] = array(
	'SphinxSearch' => array( 'Sphinx_Sich' ),
);

/** Dutch (Nederlands) */
$specialPageAliases['nl'] = array(
	'SphinxSearch' => array( 'SphinxZoeken' ),
);

/**
 * For backwards compatibility with MediaWiki 1.15 and earlier.
 */
$aliases =& $specialPageAliases;