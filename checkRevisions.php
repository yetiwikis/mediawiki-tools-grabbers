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

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionAccessException;
use MediaWiki\Revision\SlotRecord;

require_once 'includes/TextGrabber.php';

class CheckRevisions extends TextGrabber {

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

	/**
	 * The current replaced revisions count
	 *
	 * @var int
	 */
	protected $replacedCount = 0;

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
					$this->handleRevision( $rev );
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

		$this->output( "Done.\n\n$this->revCount revisions checked.\n$this->missingCount revisions missing.\n$this->mismatchCount hash mismatches.\n$this->replacedCount revisions replaced.\n" );
	}

	public function handleRevision( $remoteRev ) {
		$rev = $this->revisionStore->getRevisionById( $remoteRev['revid'] );

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

					// Check whether our revision is an empty revision, because sites like Fandom often have empty
					// revision content on a random number of pages inside their XML dumps.
					if ( $rev->getSize() === 0 ) {
						// If it is empty, then replace it with the content from the remote wiki.
						// We fetch the revision text again separately here, because getting "content" is expensive
						// to do all the time in the original API call.
						$params = [
							'prop' => 'revisions',
							'rvprop' => 'ids|timestamp|sha1|content|contentmodel',
							'revids' => $remoteRev['revid']
						];
						$result = $this->bot->query( $params );

						if ( empty( $result['query']['pages'] ) ) {
							$this->error( "Could not replace revision {$remoteRev['revid']} with remote wiki: bad API call." );
							return;
						}

						$remoteRev = $result['query']['pages'][array_key_first( $result['query']['pages'] )]['revisions'][0];
						$content = ContentHandler::makeContent( $remoteRev['*'], null, $remoteRev['contentmodel'], $remoteRev['contentformat'] );

						// TODO: do it in a better way than the next lines of code, which feel jank...
						$updatedRev = MutableRevisionRecord::newUpdatedRevisionRecord( $rev, [] );
						$updatedRev->setContent( SlotRecord::MAIN, $content );
						$updatedRev->setTimestamp( $rev->getTimestamp() );
						$updatedRev->setMinorEdit( $rev->isMinor() );
						$updatedRev->setComment( $rev->getComment() );
						$updatedRev->setVisibility( $rev->getVisibility() );

						// Delete our version of the revision and save the new version.
						$this->dbw->delete(
							'revision',
							[ 'rev_id' => $remoteRev['revid'] ],
							__METHOD__
						);
						$this->dbw->delete(
							'slots',
							[ 'slot_revision_id' => $remoteRev['revid'] ],
							__METHOD__
						);

						$this->revisionStore->insertRevisionOn( $updatedRev, $this->dbw );

						$this->output("Replaced revision {$remoteRev['revid']} with content from remote wiki\n");
						$this->replacedCount++;
					}
				}
			} catch ( RevisionAccessException $e ) {
				// SHA doesn't exist, or something went wrong, so let's just play safe and do nothing.
				$this->output( "Problem processing revision {$remoteRev['revid']}: {$e->getMessage()}\n" );
			}
		}
	}
}

$maintClass = 'CheckRevisions';
require_once RUN_MAINTENANCE_IF_MAIN;
