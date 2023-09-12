<?php
/**
 * Maintenance script to check the integrity of the revisions in the database. This doesn't modify the database -
 * it simply logs each bad revision.
 *
 * Realistically, you should probably pipe this script's output to a file for some more in-depth analysis.
 *
 * @file
 * @ingroup Maintenance
 * @author Jayden Bailey <jayden@weirdgloop.org>
 * @version 1.0
 * @date 10 September 2023
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionAccessException;

require_once 'includes/ExternalWikiGrabber.php';

class CheckRevisions extends ExternalWikiGrabber {

	/**
	 * The report interval
	 *
	 * @var int
	 */
	protected $reportInterval = 5000;

	/**
	 * The current revision count
	 *
	 * @var int
	 */
	protected $revCount = 0;

	/**
	 * The current missing revisions count
	 *
	 * @var int
	 */
	protected $missingCount = 0;

	/**
	 * The current hash mismatch count
	 *
	 * @var int
	 */
	protected $mismatchCount = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Checks that our database contains all of the remote wiki\'s revisions');
		$this->addOption( 'report', 'Report position after every n revisions processed (default is 5000)', false, true );
	}

	public function execute() {
		parent::execute();
		$this->reportInterval = intval( $this->getOption( 'report', 5000 ) );

		$params = [
			'list' => 'allrevisions',
			'arvprop' => 'ids|timestamp|sha1',
			'arvlimit' => 'max',
			'arvdir' => 'older',
		];


		$more = true;
		$checkpoint = $this->reportInterval;

		$this->output( "Checking revisions...\n" );
		do {
			$result = $this->bot->query( $params );

			if ( empty( $result['query']['allrevisions'] ) ) {
				$this->fatalError( 'No revisions found on remote wiki.' );
			}

			foreach ( $result['query']['allrevisions'] as $page ) {
				foreach ( $page['revisions'] as $rev ) {
					$this->processRevision( $rev );
					$this->revCount++;
					if ( $this->revCount >= $checkpoint ) {
						$this->output( "{$this->revCount} revisions processed.\n" );
						$checkpoint = $checkpoint + $this->reportInterval;
					}
				}

				if ( isset( $result['continue'] ) ) {
					$params = array_merge( $params, $result['continue'] );
				} else {
					$more = false;
				}
			}
		} while ( $more );

		$this->output( "Done.\n\n$this->revCount revisions checked.\n$this->missingCount revisions missing.\n$this->mismatchCount hash mismatches.\n" );
	}

	public function processRevision( $remoteRev ) {
		$revStore = MediaWikiServices::getInstance()->getRevisionStore();
		$rev = $revStore->getRevisionById( $remoteRev['revid'] );

		if ( is_null( $rev ) ) {
			// The revision is missing from our database.
			$this->output( "Bad revision (missing): {$remoteRev['revid']}\n" );
			$this->missingCount++;
		} else {
			try {
				$sha = Wikimedia\base_convert( $rev->getSha1(), 36, 16, 40 );
				if ( !is_null( $sha ) && array_key_exists( 'sha1', $remoteRev ) && $sha != $remoteRev['sha1'] ) {
					// The checksum of our revision and the remote revision doesn't match.
					$this->output( "Bad revision (hash): {$remoteRev['revid']} (ours: ${sha} | theirs: ${remoteRev['sha1']})\n" );
					$this->mismatchCount++;
				}
			} catch ( RevisionAccessException $e ) {
				// SHA doesn't exist, or something went wrong, so let's just play safe and do nothing.
			}
		}
	}
}

$maintClass = 'CheckRevisions';
require_once RUN_MAINTENANCE_IF_MAIN;
