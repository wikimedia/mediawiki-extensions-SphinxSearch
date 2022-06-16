<?php
/**
 * Generate dictionary for search suggestions
 *
 * Run without any arguments to see instructions.
 *
 * @author Svemir Brkic
 * @file
 * @ingroup extensions
 */
$maintenancePath = getenv( 'MW_INSTALL_PATH' ) !== false
		? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
		: __DIR__ . '/../../../../maintenance/Maintenance.php';

require_once $maintenancePath;

class GenerateEnchantDictionary extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( "Sets up myspell dictionary (sphinx.dic and sphinx.aff) " .
			"for search suggestions (suggestWithEnchant method.)\n" .
			"Uses Sphinx indexer to create a list of all indexed words, sorted by frequency." );

		$this->addOption( 'sphinxconf', 'Location of Sphinx configuration file', true, true );
		$this->addOption( 'indexer', 'Full path to Sphinx indexer if not in the path', false, true );
		$this->addOption( 'useindex', 'Sphinx index to use (defaults to wiki_main)', false, true );
		$this->addOption( 'maxwords', 'Max. number of words (defaults to 10000)', false, true );
		$this->addOption( 'help', "Display this help message" );
		$this->addOption( 'quiet', "Whether to suppress non-error output" );
	}

	public function execute() {
		$max_words = intval( $this->getOption( 'maxwords', 10000 ) );
		$indexer = wfEscapeShellArg( $this->getOption( 'indexer', 'indexer' ) );
		$index = wfEscapeShellArg( $this->getOption( 'useindex', 'wiki_main' ) );
		$conf = wfEscapeShellArg( $this->getOption( 'sphinxconf' ) );

		$cmd = "$indexer  --config $conf $index --buildstops sphinx.dic $max_words";
		$this->output( wfShellExec( $cmd, $retval ) );
		if ( file_exists( 'sphinx.dic' ) ) {
			$words = file( 'sphinx.dic' );
			$cnt = count( $words );
			if ( $cnt ) {
				file_put_contents( 'sphinx.dic',  $cnt . "\n" . implode( '', $words ) );
				file_put_contents( 'sphinx.aff', "SET UTF-8\n" );
			}
		}
	}

}

$maintClass = GenerateEnchantDictionary::class;

// Avoid E_ALL notice caused by ob_end_flush() in Maintenance::setup()
ob_start();

require_once RUN_MAINTENANCE_IF_MAIN;
