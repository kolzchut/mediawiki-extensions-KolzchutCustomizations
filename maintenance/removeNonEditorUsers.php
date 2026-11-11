<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 * @ingroup Maintenance
 */

namespace MediaWiki\Extension\KolzchutCustomizations\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\User;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Remove user accounts that never edited any wiki of a shared-user-table farm
 * (kolzchut/kz#485).
 *
 * Core's removeUnusedAccounts.php only looks at the CURRENT wiki's tables. In
 * this farm `user`, `user_groups`, `actor` and `bot_passwords` are shared
 * ($wgSharedDB / $wgSharedTables), while edits, uploads, logs, watchlists and
 * preferences live in each wiki's own database. Run against one wiki, the core
 * script would flag a user who only ever edited another language wiki.
 *
 * This script unions the footprint of every global actor ID across ALL farm
 * wikis (revision, archive, image, oldimage, filearchive, and logging other
 * than the automatic "newusers" entries) and treats a user whose actor has no
 * footprint anywhere as never-edited.
 *
 * Never-edited users are then split into mutually exclusive layers, and the
 * cumulative keep filters choose the deletion tier:
 *
 *   --keep-grouped          keep anyone holding a user group           (T2)
 *   --keep-watchers         + keep anyone watching a page on any wiki   (T3)
 *   --keep-email-confirmed  + keep anyone with a confirmed email        (T4)
 *   --ignore-touched N      also keep anyone touched within N days
 *
 * Some accounts are never deleted whatever the filters: system users (made by
 * User::newSystemUser(), such as "MediaWiki default"), reserved user names,
 * and holders of a bot password, which can use the API without ever editing.
 *
 * The default is a DRY RUN. Nothing is deleted unless --delete is passed, and
 * --delete is refused unless the scan covered every wiki in
 * $wgLocalDatabases. Any farm wiki or table that cannot be read stops the
 * script in either mode, because the result would be wrong.
 */
class RemoveNonEditorUsers extends Maintenance {

	/** Per-wiki tables whose actor column records an edit or upload. */
	private const FOOTPRINT_TABLES = [
		'revision' => 'rev_actor',
		'archive' => 'ar_actor',
		'image' => 'img_actor',
		'oldimage' => 'oi_actor',
		'filearchive' => 'fa_actor',
	];

	private const LAYER_GROUPED = 'grouped';
	private const LAYER_WATCHER = 'watcher';
	private const LAYER_EMAIL = 'email';
	private const LAYER_CORE = 'core';

	/** @var string[] DB domains of the wikis scanned, the current wiki first. */
	private array $wikis = [];

	/** @var string[] Farm wikis ($wgLocalDatabases) the scan does not cover. */
	private array $missingWikis = [];

	/** @var bool Whether the scan is known to cover the whole farm. */
	private bool $farmComplete = false;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Farm-aware removal of users who never edited any wiki of the farm. '
			. 'Dry-run by default; pass --delete to actually remove them.'
		);
		$this->addOption( 'delete', 'Actually delete the selected accounts (otherwise dry-run)' );
		$this->addOption( 'wikis',
			'Comma-separated DB domains to scan. Defaults to $wgLocalDatabases. The current wiki is '
			. 'always scanned. --delete is refused unless every $wgLocalDatabases entry is included.',
			false, true );
		$this->addOption( 'keep-grouped', 'Do not delete users who hold any user group' );
		$this->addOption( 'keep-watchers', 'Do not delete users who watch any page on any farm wiki' );
		$this->addOption( 'keep-email-confirmed', 'Do not delete users with a confirmed email address' );
		$this->addOption( 'ignore-touched',
			'Do not delete accounts touched within the last N days', false, true );
		$this->addOption( 'output-file',
			'Write the selected accounts as TSV to this path', false, true );
		$this->setBatchSize( 500 );
		$this->requireExtension( 'KolzchutCustomizations' );
	}

	/** @inheritDoc */
	public function execute() {
		$delete = $this->hasOption( 'delete' );
		$touchedDays = $this->getTouchedDays();
		if ( $this->getBatchSize() < 1 ) {
			$this->fatalError( 'Refusing to run: --batch-size must be a positive integer.' );
		}

		$this->resolveWikis();
		$this->output( 'Farm wikis scanned: ' . implode( ', ', $this->wikis ) . "\n" );
		if ( !$this->farmComplete ) {
			$this->output( 'WARNING: the scan does not cover the whole farm'
				. ( $this->missingWikis ? ' (missing: ' . implode( ', ', $this->missingWikis ) . ')' : '' )
				. ". Users who edited only there are counted as never-edited.\n" );
			if ( $delete ) {
				$this->fatalError( 'Refusing to delete: the farm wiki list is incomplete.' );
			}
		}

		// Read from the primary when deleting, so the decision is not made on
		// lagged data.
		$activeActors = $this->collectActiveActors( $delete );
		$this->output( 'Actors with an edit, upload or log entry farm-wide: '
			. count( $activeActors ) . "\n" );

		$sharedDb = $this->getWikiDB( $this->wikis[0], $delete );
		$grouped = $this->fetchIdSet( $sharedDb, $this->wikis[0], 'user_groups', 'ug_user' );
		$botPasswordUsers = $this->fetchIdSet( $sharedDb, $this->wikis[0], 'bot_passwords', 'bp_user' );
		$watchers = [];
		foreach ( $this->wikis as $wiki ) {
			$watchers += $this->fetchIdSet( $this->getWikiDB( $wiki, $delete ), $wiki, 'watchlist', 'wl_user' );
		}

		try {
			$rows = $sharedDb->newSelectQueryBuilder()
				->select( [
					'user_id', 'user_name', 'user_touched', 'user_registration',
					'user_email_authenticated', 'user_token', 'actor_id',
				] )
				->from( 'user' )
				->leftJoin( 'actor', null, 'user_id = actor_user' )
				->caller( __METHOD__ )->fetchResultSet();
		} catch ( DBError $e ) {
			$this->fatalError( 'Cannot read the user table: ' . $e->getMessage() );
		}

		$userNameUtils = $this->getServiceContainer()->getUserNameUtils();
		$touchedCutoff = $touchedDays > 0 ? ConvertibleTimestamp::time() - $touchedDays * 86400 : null;
		$layers = [
			self::LAYER_GROUPED => 0,
			self::LAYER_WATCHER => 0,
			self::LAYER_EMAIL => 0,
			self::LAYER_CORE => 0,
		];
		$protected = 0;
		$keptTouched = 0;
		$candidates = [];

		foreach ( $rows as $row ) {
			$actorId = $row->actor_id !== null ? (int)$row->actor_id : null;
			if ( $actorId !== null && isset( $activeActors[$actorId] ) ) {
				continue;
			}
			$userId = (int)$row->user_id;
			if ( isset( $botPasswordUsers[$userId] )
				// user_token is binary(32), so MySQL returns it NUL-padded.
				|| rtrim( $row->user_token, " \0" ) === User::INVALID_TOKEN
				|| ( $userNameUtils->isValid( $row->user_name ) && !$userNameUtils->isUsable( $row->user_name ) )
			) {
				$protected++;
				continue;
			}

			$hasGroup = isset( $grouped[$userId] );
			$isWatcher = isset( $watchers[$userId] );
			$emailConfirmed = $row->user_email_authenticated !== null;
			if ( $hasGroup ) {
				$layer = self::LAYER_GROUPED;
			} elseif ( $isWatcher ) {
				$layer = self::LAYER_WATCHER;
			} elseif ( $emailConfirmed ) {
				$layer = self::LAYER_EMAIL;
			} else {
				$layer = self::LAYER_CORE;
			}
			$layers[$layer]++;

			if ( ( $hasGroup && $this->hasOption( 'keep-grouped' ) )
				|| ( $isWatcher && $this->hasOption( 'keep-watchers' ) )
				|| ( $emailConfirmed && $this->hasOption( 'keep-email-confirmed' ) )
			) {
				continue;
			}
			if ( $touchedCutoff !== null
				&& (int)ConvertibleTimestamp::convert( TS_UNIX, $row->user_touched ) >= $touchedCutoff
			) {
				$keptTouched++;
				continue;
			}

			$candidates[] = [
				'user_id' => $userId,
				'actor_id' => $actorId,
				'user_name' => $row->user_name,
				'registration' => $row->user_registration,
				'touched' => $row->user_touched,
				'layer' => $layer,
				'has_group' => (int)$hasGroup,
				'is_watcher' => (int)$isWatcher,
				'email_confirmed' => (int)$emailConfirmed,
			];
		}

		$this->printReport( $layers, $protected );
		if ( $touchedCutoff !== null ) {
			$this->output( "Kept because touched within $touchedDays days: $keptTouched\n" );
		}
		$this->output( 'Accounts selected for deletion under the current filters: '
			. count( $candidates ) . "\n" );

		if ( $this->hasOption( 'output-file' ) ) {
			$this->writeCandidateFile( $this->getOption( 'output-file' ), $candidates );
		}

		if ( !$delete ) {
			$this->output( "\nDRY RUN: no accounts deleted. Re-run with --delete to remove them.\n" );
			return;
		}
		if ( !$candidates ) {
			$this->output( "Nothing to delete.\n" );
			return;
		}
		$this->deleteCandidates( $candidates );
	}

	/**
	 * @return int Days from --ignore-touched, 0 when not given
	 */
	private function getTouchedDays(): int {
		if ( !$this->hasOption( 'ignore-touched' ) ) {
			return 0;
		}
		$days = (string)$this->getOption( 'ignore-touched' );
		if ( !ctype_digit( $days ) ) {
			$this->fatalError( "--ignore-touched takes a non-negative whole number of days, got '$days'." );
		}
		return (int)$days;
	}

	/**
	 * Build the list of wikis to scan and decide whether it covers the farm.
	 */
	private function resolveWikis(): void {
		$config = $this->getConfig();
		$current = $this->getReplicaDB()->getDomainID();
		$farm = array_values( (array)$config->get( MainConfigNames::LocalDatabases ) );

		if ( $this->hasOption( 'wikis' ) ) {
			$requested = array_filter(
				array_map( 'trim', explode( ',', (string)$this->getOption( 'wikis' ) ) ),
				'strlen'
			);
		} else {
			$requested = $farm;
		}
		$this->wikis = array_values( array_unique( array_merge( [ $current ], $requested ) ) );
		$this->missingWikis = array_values( array_diff( $farm, $this->wikis ) );

		if ( $farm ) {
			$this->farmComplete = !$this->missingWikis;
		} else {
			// No declared farm. That is only safe when users are not shared
			// with another database we would then not know about.
			$sharedTables = (array)$config->get( MainConfigNames::SharedTables );
			$sharesUsers = $config->get( MainConfigNames::SharedDB )
				&& array_intersect( [ 'user', 'actor' ], $sharedTables );
			$this->farmComplete = !$sharesUsers;
		}
	}

	/**
	 * @param string $wiki DB domain
	 * @param bool $primary
	 * @return IReadableDatabase
	 */
	private function getWikiDB( string $wiki, bool $primary ): IReadableDatabase {
		$provider = $this->getServiceContainer()->getConnectionProvider();
		return $primary ? $provider->getPrimaryDatabase( $wiki ) : $provider->getReplicaDatabase( $wiki );
	}

	/**
	 * Distinct non-null integer values of one column, as a set.
	 *
	 * Any database error is fatal: a farm wiki that cannot be fully read would
	 * make its editors look like never-edited users.
	 *
	 * @param IReadableDatabase $db
	 * @param string $wiki DB domain, for the error message
	 * @param string $table
	 * @param string $field
	 * @param array $conds
	 * @return array<int,true>
	 */
	private function fetchIdSet(
		IReadableDatabase $db, string $wiki, string $table, string $field, array $conds = []
	): array {
		try {
			$ids = $db->newSelectQueryBuilder()
				->select( $field )->distinct()
				->from( $table )
				->where( $conds )
				->caller( __METHOD__ )->fetchFieldValues();
		} catch ( DBError $e ) {
			$this->fatalError( "Cannot read $table on $wiki: " . $e->getMessage()
				. "\nRefusing to continue: every farm wiki must be fully readable." );
		}
		$set = [];
		foreach ( $ids as $id ) {
			if ( $id !== null ) {
				$set[(int)$id] = true;
			}
		}
		return $set;
	}

	/**
	 * Union of actor IDs with any footprint, across every scanned wiki.
	 *
	 * @param bool $primary
	 * @return array<int,true> Set keyed by actor ID
	 */
	private function collectActiveActors( bool $primary ): array {
		$active = [];
		foreach ( $this->wikis as $wiki ) {
			$db = $this->getWikiDB( $wiki, $primary );
			foreach ( self::FOOTPRINT_TABLES as $table => $field ) {
				$active += $this->fetchIdSet( $db, $wiki, $table, $field );
			}
			// Account creation writes a "newusers" entry for every account,
			// so only other log types count as activity.
			$active += $this->fetchIdSet( $db, $wiki, 'logging', 'log_actor',
				[ $db->expr( 'log_type', '!=', 'newusers' ) ] );
		}
		return $active;
	}

	/**
	 * @param array<string,int> $layers
	 * @param int $protected
	 */
	private function printReport( array $layers, int $protected ): void {
		$l1 = $layers[self::LAYER_GROUPED];
		$l2 = $layers[self::LAYER_WATCHER];
		$l3 = $layers[self::LAYER_EMAIL];
		$l4 = $layers[self::LAYER_CORE];
		$lines = [
			"\n=== Never-edited users, farm-wide (layers are mutually exclusive) ===",
			sprintf( '  protected, never deleted        : %6d  (system user, reserved, bot password)', $protected ),
			sprintf( '  L1 holds a user group           : %6d', $l1 ),
			sprintf( '  L2 no group, watches a page     : %6d', $l2 ),
			sprintf( '  L3 no group or watch, email conf: %6d', $l3 ),
			sprintf( '  L4 none of the above            : %6d', $l4 ),
			'--- Accounts each tier would delete (before --ignore-touched) ---',
			sprintf( '  T1 no keep filter               : %6d', $l1 + $l2 + $l3 + $l4 ),
			sprintf( '  T2 --keep-grouped               : %6d', $l2 + $l3 + $l4 ),
			sprintf( '  T3 T2 + --keep-watchers         : %6d', $l3 + $l4 ),
			sprintf( '  T4 T3 + --keep-email-confirmed  : %6d', $l4 ),
			"=====================================================================\n",
		];
		$this->output( implode( "\n", $lines ) . "\n" );
	}

	/**
	 * @param string $path
	 * @param array[] $candidates
	 */
	private function writeCandidateFile( string $path, array $candidates ): void {
		$fh = fopen( $path, 'w' );
		if ( !$fh ) {
			$this->fatalError( "Cannot open output file: $path" );
		}
		$columns = [ 'user_id', 'actor_id', 'user_name', 'registration', 'touched',
			'layer', 'has_group', 'is_watcher', 'email_confirmed' ];
		fwrite( $fh, implode( "\t", $columns ) . "\n" );
		foreach ( $candidates as $candidate ) {
			$fields = [];
			foreach ( $columns as $column ) {
				$fields[] = (string)( $candidate[$column] ?? '' );
			}
			fwrite( $fh, implode( "\t", $fields ) . "\n" );
		}
		fclose( $fh );
		$this->output( 'Wrote ' . count( $candidates ) . " account(s) to $path\n" );
	}

	/**
	 * Delete the selected accounts, one batch at a time.
	 *
	 * Per-wiki rows go first and the shared user rows last, so an interrupted
	 * run leaves users that a re-run selects and finishes, never per-wiki rows
	 * that point at a deleted user.
	 *
	 * @param array[] $candidates
	 */
	private function deleteCandidates( array $candidates ): void {
		$this->output( 'Deleting ' . count( $candidates ) . " account(s)...\n" );
		$done = 0;
		foreach ( array_chunk( $candidates, $this->getBatchSize() ) as $batch ) {
			$userIds = array_column( $batch, 'user_id' );
			$actorIds = array_values( array_filter( array_column( $batch, 'actor_id' ) ) );

			$blockingActors = [];
			foreach ( $this->wikis as $wiki ) {
				$dbw = $this->getWikiPrimaryDB( $wiki );
				if ( $actorIds ) {
					$blockingActors += $this->fetchIdSet( $dbw, $wiki, 'block', 'bl_by_actor',
						[ 'bl_by_actor' => $actorIds ] );
				}
				$this->deletePerWikiRows( $dbw, $userIds, $actorIds );
			}

			// An actor that placed a block must survive, without its user, so
			// the block keeps a valid performer (as core does).
			$keepActors = array_values( array_intersect( $actorIds, array_keys( $blockingActors ) ) );
			$dropActors = array_values( array_diff( $actorIds, $keepActors ) );

			$dbw = $this->getWikiPrimaryDB( $this->wikis[0] );
			$this->beginTransaction( $dbw, __METHOD__ );
			$dbw->newDeleteQueryBuilder()->deleteFrom( 'user_groups' )
				->where( [ 'ug_user' => $userIds ] )->caller( __METHOD__ )->execute();
			if ( $dropActors ) {
				$dbw->newDeleteQueryBuilder()->deleteFrom( 'actor' )
					->where( [ 'actor_id' => $dropActors ] )->caller( __METHOD__ )->execute();
			}
			if ( $keepActors ) {
				$dbw->newUpdateQueryBuilder()->update( 'actor' )
					->set( [ 'actor_user' => null ] )
					->where( [ 'actor_id' => $keepActors ] )
					->caller( __METHOD__ )->execute();
			}
			$dbw->newDeleteQueryBuilder()->deleteFrom( 'user' )
				->where( [ 'user_id' => $userIds ] )->caller( __METHOD__ )->execute();
			$this->commitTransaction( $dbw, __METHOD__ );

			$done += count( $batch );
			$this->output( "  ... $done\n" );
		}

		$users = (int)$this->getWikiPrimaryDB( $this->wikis[0] )->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'user' )->caller( __METHOD__ )->fetchField();
		foreach ( $this->wikis as $wiki ) {
			$this->getWikiPrimaryDB( $wiki )->newUpdateQueryBuilder()->update( 'site_stats' )
				->set( [ 'ss_users' => $users ] )
				->where( [ 'ss_row_id' => 1 ] )
				->caller( __METHOD__ )->execute();
		}
		$this->output( "done.\n" );
	}

	/**
	 * Delete one batch's rows from the tables a wiki keeps for itself.
	 *
	 * Only the automatic "newusers" log entries are removed: any other entry
	 * would have made the user active, so finding one here means the user
	 * acted after the scan, and its log must stay.
	 *
	 * @param IDatabase $dbw
	 * @param int[] $userIds
	 * @param int[] $actorIds
	 */
	private function deletePerWikiRows( IDatabase $dbw, array $userIds, array $actorIds ): void {
		$this->beginTransaction( $dbw, __METHOD__ );
		if ( $actorIds ) {
			$dbw->newDeleteQueryBuilder()->deleteFrom( 'logging' )
				->where( [ 'log_actor' => $actorIds, 'log_type' => 'newusers' ] )
				->caller( __METHOD__ )->execute();
			$dbw->newDeleteQueryBuilder()->deleteFrom( 'recentchanges' )
				->where( [ 'rc_actor' => $actorIds, 'rc_log_type' => 'newusers' ] )
				->caller( __METHOD__ )->execute();
		}
		$watchIds = $dbw->newSelectQueryBuilder()
			->select( 'wl_id' )->from( 'watchlist' )
			->where( [ 'wl_user' => $userIds ] )
			->caller( __METHOD__ )->fetchFieldValues();
		if ( $watchIds ) {
			$dbw->newDeleteQueryBuilder()->deleteFrom( 'watchlist_expiry' )
				->where( [ 'we_item' => $watchIds ] )->caller( __METHOD__ )->execute();
			$dbw->newDeleteQueryBuilder()->deleteFrom( 'watchlist' )
				->where( [ 'wl_id' => $watchIds ] )->caller( __METHOD__ )->execute();
		}
		$dbw->newDeleteQueryBuilder()->deleteFrom( 'user_properties' )
			->where( [ 'up_user' => $userIds ] )->caller( __METHOD__ )->execute();
		$dbw->newDeleteQueryBuilder()->deleteFrom( 'user_former_groups' )
			->where( [ 'ufg_user' => $userIds ] )->caller( __METHOD__ )->execute();
		$dbw->newDeleteQueryBuilder()->deleteFrom( 'user_newtalk' )
			->where( [ 'user_id' => $userIds ] )->caller( __METHOD__ )->execute();
		$this->commitTransaction( $dbw, __METHOD__ );
	}

	/**
	 * @param string $wiki DB domain
	 * @return IDatabase
	 */
	private function getWikiPrimaryDB( string $wiki ): IDatabase {
		return $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase( $wiki );
	}
}

// @codeCoverageIgnoreStart
$maintClass = RemoveNonEditorUsers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
