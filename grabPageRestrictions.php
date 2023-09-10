<?php
/**
 * Maintenance script to grab the page restrictions from a wiki (to which we have only read-only access instead of
 * full database access). It's worth noting that grabText.php and grabNewText.php already import page restrictions,
 * so this script is only really useful if you're using an XML dump that doesn't include page restrictions.
 *
 * @file
 * @ingroup Maintenance
 * @author Jayden Bailey <jayden@weirdgloop.org>
 * @version 1.0
 * @date 10 September 2023
 */

use MediaWiki\MediaWikiServices;

require_once 'includes/ExternalWikiGrabber.php';

class GrabPageRestrictions extends ExternalWikiGrabber {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Grabs page restrictions from a pre-existing wiki into a new wiki.' );
	}

	public function execute() {
		parent::execute();

		$params = [
			'generator' => 'allpages',
			'gaplimit' => 'max',
			'prop' => 'info',
			'inprop' => 'protection',
			'gapprtype' => 'edit|move|upload'
		];

		$more = true;
		$i = 0;

		$this->output( "Grabbing pages with restrictions...\n" );
		do {
			$result = $this->bot->query( $params );

			if ( empty( $result['query']['pages'] ) ) {
				$this->fatalError( 'No pages with restrictions, hence nothing to do.' );
			}

			foreach ( $result['query']['pages'] as $page ) {
				$this->processPage( $page );
				$i++;

				if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['pages'] ) ) {
					$params = array_merge( $params, $result['query-continue']['pages'] );
					$this->output( "{$i} entries processed.\n" );
				} elseif ( isset( $result['continue'] ) ) {
					$params = array_merge( $params, $result['continue'] );
					$this->output( "{$i} entries processed.\n" );
				} else {
					$more = false;
				}
			}

		} while ( $more );

		$this->output( "Done: $i entries processed\n" );
	}

	public function processPage( $page ) {
		$pageStore = MediaWikiServices::getInstance()->getPageStore();
		$ourPage = $pageStore->getPageById( $page['pageid'] );

		if ( is_null( $ourPage ) ) {
			// This page doesn't exist in our database, so ignore it.
			return;
		}

		// Delete first any existing protection
		$this->dbw->delete(
			'page_restrictions',
			[ 'pr_page' => $page['pageid'] ],
			__METHOD__
		);

		$this->output( "Setting page_restrictions on page_id {$page['pageid']}.\n" );

		// insert current restrictions
		foreach ( $page['protection'] as $prot ) {
			// Skip protections inherited from cascade protections
			if ( !isset( $prot['source'] ) ) {
				$expiry = $prot['expiry'] == 'infinity' ? 'infinity' : wfTimestamp( TS_MW, $prot['expiry'] );
				$this->dbw->insert(
					'page_restrictions',
					[
						'pr_page' => $page['pageid'],
						'pr_type' => $prot['type'],
						'pr_level' => $prot['level'],
						'pr_cascade' => (int)isset( $prot['cascade'] ),
						'pr_expiry' => $expiry
					],
					__METHOD__
				);
			}
		}
	}
}

$maintClass = 'GrabPageRestrictions';
require_once RUN_MAINTENANCE_IF_MAIN;
