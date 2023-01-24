<?php
/**
 * Maintenance script to grab revisions from a wiki and import it to another wiki.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;

require_once 'includes/TextGrabber.php';

class GrabRevisions extends TextGrabber {

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab revisions from an external wiki and import it into one of ours.\n" .
			"Don't use this on a large wiki unless you absolutely must; it will be incredibly slow.";
		$this->addOption( 'arvstart', 'Timestamp at which to continue, useful to grab new revisions', false, true );
		$this->addOption( 'arvend', 'Timestamp at which to end', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
	}

	public function execute() {
		parent::execute();

		$this->output( "\n" );

		# Get all pages as a list, start by getting namespace numbers...
		$this->output( "Retrieving namespaces list...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|statistics|namespacealiases'
		];
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->fatalError( 'No siteinfo data found' );
		}

		$textNamespaces = [];
		if ( $this->hasOption( 'namespaces' ) ) {
			$grabFromAllNamespaces = false;
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
			foreach ( $textNamespaces as $idx => $ns ) {
				# Ignore special
				if ( $ns < 0 || !isset( $siteinfo['namespaces'][$ns] ) ) {
					unset( $textNamespaces[$idx] );
				}
			}
			$textNamespaces = array_values( $textNamespaces );
		} else {
			$grabFromAllNamespaces = true;
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special
				if ( $ns >= 0 ) {
					$textNamespaces[] = $ns;
				}
			}
		}
		if ( !$textNamespaces ) {
			$this->fatalError( 'Got no namespaces' );
		}

		if ( $grabFromAllNamespaces ) {
			# Get list of live pages from namespaces and continue from there
			$pageCount = $siteinfo['statistics']['edits'];
			$this->output( "Generating revision list from all namespaces - $pageCount expected...\n" );
		} else {
			$this->output( sprintf( "Generating revision list from %s namespaces...\n", count( $textNamespaces ) ) );
		}

		$arvstart = $this->getOption( 'arvstart' );
		$arvend = $this->getOption( 'arvend' );
		$pageCount = 0;
		$pageCount += $this->processRevisionsFromNamespaces( implode( '|', $textNamespaces ), $arvstart, $arvend );
		$this->output( "\nDone - found $pageCount total pages.\n" );
		# Done.
	}

	/**
	 * Grabs all revisions from a given namespace
	 *
	 * @param string $ns Namespaces to process, separate by '|'.
	 * @param string $arvstart Timestamp to start from (optional).
	 * @param string $arvend Timestamp to end with (optional).
	 * @return int Number of pages processed.
	 */
	function processRevisionsFromNamespaces( $ns, $arvstart = null, $arvend = null ) {
		$this->output( "Processing pages from namespace $ns...\n" );

		$params = [
			'list' => 'allrevisions',
			'arvlimit' => 'max',
			'arvdir' => 'newer', // Grab old revisions first
			'arvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel|size',
			'arvnamespace' => $ns,
			'arvslots' => 'main',
		];
		if ( $arvstart ) {
			$params['arvstart'] = $arvstart;
		}
		if ( $arvend ) {
			$params['arvend'] = $arvend;
		}

		$nsRevisionCount = 0;
		$misserModeCount = 0;
		$lastTimestamp = '';
		while ( true ) {
			$result = $this->bot->query( $params );

			$pages = $result['query']['allrevisions'];
			// Deal with misser mode
			if ( $pages ) {
				$misserModeCount = $resultsCount = 0;
				foreach ( $pages as $page ) {
					$title = new PageIdentityValue( $page['pageid'], $page['ns'], $page['title'], PageIdentityValue::LOCAL );
					foreach ( $page['revisions'] as $revision ) {
						$this->processRevision( $revision, $page['pageid'], Title::castFromPageIdentity( $title ) );
						$resultsCount++;
						$lastTimestamp = $revision['timestamp'];
					}
				}
				$nsRevisionCount += $resultsCount;
				$this->output( "$resultsCount/$nsRevisionCount, arvstart: $lastTimestamp\n" );
			} else {
				$misserModeCount++;
				$this->output( "No result in this query due to misser mode.\n" );
				// Just in case if too far to scroll
				if ( $lastTimestamp && $misserModeCount % 10 === 0 ) {
					$this->output( "Last arvstart: $lastTimestamp\n" );
				}
			}
			if ( !isset( $result['continue'] ) ) {
				break;
			}

			// Add continuation parameters
			$params = array_merge( $params, $result['continue'] );
		}

		$this->output( "$nsRevisionCount revisions found in namespace $ns.\n" );

		return $nsRevisionCount;
	}
}

$maintClass = 'GrabRevisions';
require_once RUN_MAINTENANCE_IF_MAIN;
