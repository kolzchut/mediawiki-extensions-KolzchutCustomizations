<?php

namespace MediaWiki\Extension\KolzchutCustomizations\Tests\Integration\Maintenance;

use MediaWiki\Extension\KolzchutCustomizations\Maintenance\RemoveNonEditorUsers;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\MaintenanceFatalError;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\DatabaseDomain;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . '/../../../../maintenance/removeNonEditorUsers.php';

/**
 * The farm is simulated inside the test database: a second "wiki" is a set
 * of temporary tables under another table prefix, reached through its own DB
 * domain the way the script reaches kz_ar and kz_ru.
 *
 * @group Database
 * @covers \MediaWiki\Extension\KolzchutCustomizations\Maintenance\RemoveNonEditorUsers
 */
class RemoveNonEditorUsersTest extends MaintenanceBaseTestCase {

	private const OTHER_PREFIX = 'kzcother_';

	/** Per-wiki tables the script reads or writes on every farm wiki. */
	private const PER_WIKI_TABLES = [
		'revision', 'archive', 'image', 'oldimage', 'filearchive', 'logging',
		'recentchanges', 'watchlist', 'watchlist_expiry', 'user_properties',
		'user_former_groups', 'user_newtalk', 'block', 'block_target', 'site_stats',
	];

	private string $currentWiki;
	private string $otherWiki;

	/** @inheritDoc */
	protected function getMaintenanceClass() {
		return RemoveNonEditorUsers::class;
	}

	protected function setUp(): void {
		parent::setUp();
		$db = $this->getDb();
		foreach ( self::PER_WIKI_TABLES as $table ) {
			$db->query( 'DROP TEMPORARY TABLE IF EXISTS '
				. $db->addIdentifierQuotes( self::OTHER_PREFIX . $table ), __METHOD__ );
			$db->duplicateTableStructure(
				$db->tableName( $table, 'raw' ), self::OTHER_PREFIX . $table, true, __METHOD__
			);
		}
		$this->currentWiki = $db->getDomainID();
		$this->otherWiki = ( new DatabaseDomain(
			$db->getDBname(), $db->dbSchema() ?: null, self::OTHER_PREFIX
		) )->getId();
		$this->overrideConfigValue(
			MainConfigNames::LocalDatabases, [ $this->currentWiki, $this->otherWiki ]
		);
	}

	private function otherDb(): IDatabase {
		return $this->getServiceContainer()->getConnectionProvider()
			->getPrimaryDatabase( $this->otherWiki );
	}

	private function newUser( array $groups = [] ): UserIdentity {
		return $this->getMutableTestUser( $groups )->getUserIdentity();
	}

	private function actorId( UserIdentity $user ): int {
		return $this->getServiceContainer()->getActorStore()
			->findActorId( $user, $this->getDb() );
	}

	private function addRevision( IDatabase $db, UserIdentity $user ): void {
		$db->newInsertQueryBuilder()->insertInto( 'revision' )
			->row( [
				'rev_page' => 1,
				'rev_comment_id' => 0,
				'rev_actor' => $this->actorId( $user ),
				'rev_timestamp' => $db->timestamp(),
				'rev_minor_edit' => 0,
				'rev_deleted' => 0,
				'rev_len' => 0,
				'rev_parent_id' => 0,
				'rev_sha1' => '',
			] )
			->caller( __METHOD__ )->execute();
	}

	private function addWatch( IDatabase $db, UserIdentity $user ): void {
		$db->newInsertQueryBuilder()->insertInto( 'watchlist' )
			->row( [
				'wl_user' => $user->getId(),
				'wl_namespace' => 0,
				'wl_title' => 'Watched_page',
				'wl_notificationtimestamp' => null,
			] )
			->caller( __METHOD__ )->execute();
	}

	private function addBlockTarget( IDatabase $db, UserIdentity $user ): void {
		$db->newInsertQueryBuilder()->insertInto( 'block_target' )
			->row( [
				'bt_user' => $user->getId(),
				'bt_user_text' => $user->getName(),
				'bt_auto' => 0,
				'bt_count' => 1,
			] )
			->caller( __METHOD__ )->execute();
	}

	private function addNewUsersLog(
		IDatabase $db, UserIdentity $performer, string $action, string $createdName
	): void {
		$db->newInsertQueryBuilder()->insertInto( 'logging' )
			->row( [
				'log_type' => 'newusers',
				'log_action' => $action,
				'log_timestamp' => $db->timestamp(),
				'log_actor' => $this->actorId( $performer ),
				'log_namespace' => NS_USER,
				'log_title' => str_replace( ' ', '_', $createdName ),
				'log_comment_id' => 0,
				'log_params' => '',
				'log_deleted' => 0,
			] )
			->caller( __METHOD__ )->execute();
	}

	private function countNewUsersLog( IDatabase $db, UserIdentity $performer, string $action ): int {
		return (int)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'logging' )
			->where( [
				'log_type' => 'newusers',
				'log_action' => $action,
				'log_actor' => $this->actorId( $performer ),
			] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Swap in a script that runs $hook once the scan is over, as the delete
	 * phase starts: what a block or an edit made during a --delete run does.
	 *
	 * @param callable $hook
	 */
	private function actBetweenScanAndDelete( callable $hook ): void {
		$this->maintenance = TestingAccessWrapper::newFromObject(
			new class( $hook ) extends RemoveNonEditorUsers {
				/** @var callable|null */
				private $hook;

				public function __construct( callable $hook ) {
					parent::__construct();
					$this->hook = $hook;
				}

				/** @inheritDoc */
				protected function output( $out, $channel = null ) {
					if ( $this->hook && str_starts_with( $out, 'Deleting ' ) ) {
						$hook = $this->hook;
						$this->hook = null;
						$hook();
					}
					parent::output( $out, $channel );
				}
			}
		);
	}

	/**
	 * Run the script and return the user names it selected for deletion.
	 *
	 * @param array $options
	 * @return string[]
	 */
	private function runScript( array $options = [] ): array {
		$file = $this->getNewTempFile();
		foreach ( $options as $name => $value ) {
			$this->maintenance->setOption( $name, $value );
		}
		$this->maintenance->setOption( 'output-file', $file );
		$this->maintenance->execute();

		$lines = array_slice( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), 1 );
		return array_map( static fn ( $line ) => explode( "\t", $line )[2], $lines );
	}

	private function userExists( UserIdentity $user ): bool {
		return (bool)$this->getDb()->newSelectQueryBuilder()
			->select( 'user_id' )->from( 'user' )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )->fetchField();
	}

	public function testEditOnAnotherFarmWikiKeepsTheUser() {
		$neverEdited = $this->newUser();
		$editedHere = $this->newUser();
		$editedElsewhere = $this->newUser();
		$this->addRevision( $this->getDb(), $editedHere );
		$this->addRevision( $this->otherDb(), $editedElsewhere );

		$selected = $this->runScript();

		$this->assertContains( $neverEdited->getName(), $selected );
		$this->assertNotContains( $editedHere->getName(), $selected );
		$this->assertNotContains( $editedElsewhere->getName(), $selected,
			'an edit on another farm wiki must keep the user' );
	}

	public function testScanThatOmitsAFarmWikiWarnsAndMissesItsEditors() {
		$editedElsewhere = $this->newUser();
		$this->addRevision( $this->otherDb(), $editedElsewhere );

		$selected = $this->runScript( [ 'wikis' => $this->currentWiki ] );

		// Proves the union is what protects the user in the test above.
		$this->assertContains( $editedElsewhere->getName(), $selected );
		$this->expectOutputRegex( '/WARNING: the scan does not cover the whole farm/' );
	}

	public function testDeleteIsRefusedWhenAFarmWikiIsNotScanned() {
		$neverEdited = $this->newUser();

		try {
			$this->runScript( [ 'wikis' => $this->currentWiki, 'delete' => true ] );
			$this->fail( 'expected the script to refuse' );
		} catch ( MaintenanceFatalError $e ) {
			$this->assertTrue( $this->userExists( $neverEdited ) );
		}
		$this->expectOutputRegex( '/Refusing to delete: the farm wiki list is incomplete/' );
	}

	public function testDeleteIsRefusedWhenAFarmWikiCannotBeRead() {
		$neverEdited = $this->newUser();
		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [
			$this->currentWiki,
			( new DatabaseDomain( $this->getDb()->getDBname(), null, 'kzcmissing_' ) )->getId(),
		] );

		try {
			$this->runScript( [ 'delete' => true ] );
			$this->fail( 'expected the script to refuse' );
		} catch ( MaintenanceFatalError $e ) {
			$this->assertTrue( $this->userExists( $neverEdited ) );
		}
		$this->expectOutputRegex( '/Cannot read revision on .*kzcmissing_/' );
	}

	public function testKeepGrouped() {
		$grouped = $this->newUser( [ 'sysop' ] );

		$this->assertContains( $grouped->getName(), $this->runScript() );
		$this->assertNotContains( $grouped->getName(),
			$this->runScript( [ 'keep-grouped' => true ] ) );
	}

	public function testKeepWatchersCountsWatchesOnEveryFarmWiki() {
		$watcherHere = $this->newUser();
		$watcherElsewhere = $this->newUser();
		$this->addWatch( $this->getDb(), $watcherHere );
		$this->addWatch( $this->otherDb(), $watcherElsewhere );

		$selected = $this->runScript();
		$this->assertContains( $watcherHere->getName(), $selected );
		$this->assertContains( $watcherElsewhere->getName(), $selected );

		$selected = $this->runScript( [ 'keep-watchers' => true ] );
		$this->assertNotContains( $watcherHere->getName(), $selected );
		$this->assertNotContains( $watcherElsewhere->getName(), $selected );
	}

	public function testBotPasswordHolderIsNeverSelected() {
		$botOwner = $this->newUser();
		$this->getDb()->newInsertQueryBuilder()->insertInto( 'bot_passwords' )
			->row( [
				'bp_user' => $botOwner->getId(),
				'bp_app_id' => 'sync',
				'bp_password' => '',
				'bp_token' => '',
				'bp_restrictions' => '{}',
				'bp_grants' => '[]',
			] )
			->caller( __METHOD__ )->execute();

		$this->assertNotContains( $botOwner->getName(), $this->runScript() );
	}

	public function testSystemUserIsNeverSelected() {
		$systemUser = User::newSystemUser( 'Kzc system user', [ 'steal' => true ] );

		$this->assertNotContains( $systemUser->getName(), $this->runScript() );
	}

	public function testBlockedUserOnAnyFarmWikiIsNeverSelected() {
		$blockedHere = $this->newUser();
		$blockedElsewhere = $this->newUser();
		$this->addBlockTarget( $this->getDb(), $blockedHere );
		$this->addBlockTarget( $this->otherDb(), $blockedElsewhere );

		$selected = $this->runScript();

		$this->assertNotContains( $blockedHere->getName(), $selected );
		$this->assertNotContains( $blockedElsewhere->getName(), $selected,
			'a block on another farm wiki must keep the user' );
		$this->expectOutputRegex( '/protected, blocked account\s*:\s*2\s/' );
	}

	public function testBlockedUserSurvivesDelete() {
		$blocked = $this->newUser();
		$this->addBlockTarget( $this->otherDb(), $blocked );
		$neverEdited = $this->newUser();

		$this->runScript( [ 'keep-grouped' => true, 'delete' => true ] );

		$this->assertFalse( $this->userExists( $neverEdited ) );

		$this->assertTrue( $this->userExists( $blocked ) );
	}

	public function testUserBlockedAfterTheScanIsNotDeleted() {
		$blockedLater = $this->newUser();
		$neverEdited = $this->newUser();
		$this->actBetweenScanAndDelete( function () use ( $blockedLater ) {
			$this->addBlockTarget( $this->otherDb(), $blockedLater );
		} );

		$selected = $this->runScript( [ 'keep-grouped' => true, 'delete' => true ] );

		$this->assertContains( $blockedLater->getName(), $selected, 'the scan selected the user' );
		$this->assertTrue( $this->userExists( $blockedLater ) );
		$this->assertFalse( $this->userExists( $neverEdited ) );
		$this->expectOutputRegex( '/Kept because they were blocked or acted after the scan: 1\n/' );
	}

	public function testUserWhoEditsAfterTheScanIsNotDeleted() {
		$editsLater = $this->newUser();
		$createsLater = $this->newUser();
		$neverEdited = $this->newUser();
		$this->actBetweenScanAndDelete( function () use ( $editsLater, $createsLater ) {
			$this->addRevision( $this->otherDb(), $editsLater );
			$this->addNewUsersLog( $this->getDb(), $createsLater, 'create2', 'Someone else' );
		} );

		$selected = $this->runScript( [ 'keep-grouped' => true, 'delete' => true ] );

		$this->assertContains( $editsLater->getName(), $selected, 'the scan selected the user' );
		$this->assertContains( $createsLater->getName(), $selected, 'the scan selected the user' );
		$this->assertTrue( $this->userExists( $editsLater ) );
		$this->assertTrue( $this->userExists( $createsLater ) );
		$this->assertSame( 1, $this->countNewUsersLog( $this->getDb(), $createsLater, 'create2' ) );
		$this->assertFalse( $this->userExists( $neverEdited ) );
		$this->expectOutputRegex( '/Kept because they were blocked or acted after the scan: 2\n/' );
	}

	public function testUnreadableBlockTargetIsFatal() {
		$neverEdited = $this->newUser();
		$this->otherDb()->query( 'DROP TEMPORARY TABLE '
			. $this->otherDb()->addIdentifierQuotes( self::OTHER_PREFIX . 'block_target' ), __METHOD__ );

		try {
			$this->runScript( [ 'delete' => true ] );
			$this->fail( 'expected the script to refuse' );
		} catch ( MaintenanceFatalError $e ) {
			$this->assertTrue( $this->userExists( $neverEdited ) );
		}
		$this->expectOutputRegex( '/Cannot read block_target on .*kzcother_/' );
	}

	public function testCreatingAnotherAccountKeepsTheUser() {
		$creator = $this->newUser();
		$emailCreator = $this->newUser();
		$selfCreated = $this->newUser();
		$autoCreated = $this->newUser();
		$this->addNewUsersLog( $this->getDb(), $creator, 'create', $creator->getName() );
		$this->addNewUsersLog( $this->otherDb(), $creator, 'create2', 'Someone else' );
		$this->addNewUsersLog( $this->getDb(), $emailCreator, 'byemail', 'Someone by email' );
		$this->addNewUsersLog( $this->getDb(), $selfCreated, 'create', $selfCreated->getName() );
		$this->addNewUsersLog( $this->otherDb(), $autoCreated, 'autocreate', $autoCreated->getName() );

		$selected = $this->runScript();

		$this->assertNotContains( $creator->getName(), $selected,
			'a create2 entry on another farm wiki means a person acted' );
		$this->assertNotContains( $emailCreator->getName(), $selected,
			'a byemail entry means a person acted' );
		$this->assertContains( $selfCreated->getName(), $selected,
			'the user\'s own create entry is not a footprint' );
		$this->assertContains( $autoCreated->getName(), $selected,
			'the user\'s own autocreate entry is not a footprint' );
	}

	public function testDeleteRemovesOnlyOwnCreationLogRows() {
		$creator = $this->newUser();
		$selfCreated = $this->newUser();
		$autoCreated = $this->newUser();
		$this->addNewUsersLog( $this->getDb(), $creator, 'create', $creator->getName() );
		$this->addNewUsersLog( $this->getDb(), $creator, 'create2', 'Someone else' );
		$this->addNewUsersLog( $this->getDb(), $selfCreated, 'create', $selfCreated->getName() );
		$this->addNewUsersLog( $this->otherDb(), $autoCreated, 'autocreate', $autoCreated->getName() );
		$selfActor = $this->actorId( $selfCreated );

		$this->runScript( [ 'keep-grouped' => true, 'delete' => true ] );

		$this->assertTrue( $this->userExists( $creator ) );
		$this->assertSame( 1, $this->countNewUsersLog( $this->getDb(), $creator, 'create2' ),
			'the record of creating another account must survive' );
		$this->assertSame( 1, $this->countNewUsersLog( $this->getDb(), $creator, 'create' ),
			'a kept user keeps their own creation entry' );
		$this->assertFalse( $this->userExists( $selfCreated ) );
		$this->assertFalse( $this->userExists( $autoCreated ) );
		$this->assertSame( 0, (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'logging' )->where( [ 'log_actor' => $selfActor ] )
			->caller( __METHOD__ )->fetchField(), 'a deleted user\'s own create entry is removed' );
		$this->assertSame( 0, (int)$this->otherDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'logging' )
			->where( [ 'log_type' => 'newusers', 'log_action' => 'autocreate' ] )
			->caller( __METHOD__ )->fetchField(), 'a deleted user\'s own autocreate entry is removed' );
	}

	public function testDryRunDeletesNothing() {
		$neverEdited = $this->newUser();
		$this->addWatch( $this->otherDb(), $neverEdited );
		$usersBefore = $this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'user' )->caller( __METHOD__ )->fetchField();

		$this->assertContains( $neverEdited->getName(), $this->runScript() );

		$this->assertTrue( $this->userExists( $neverEdited ) );
		$this->assertSame( $usersBefore, $this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'user' )->caller( __METHOD__ )->fetchField() );
		$this->assertSame( 1, (int)$this->otherDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'watchlist' )
			->where( [ 'wl_user' => $neverEdited->getId() ] )
			->caller( __METHOD__ )->fetchField() );
		$this->expectOutputRegex( '/DRY RUN: no accounts deleted/' );
	}

	public function testDeleteRemovesOnlySelectedAccountsOnEveryWiki() {
		$neverEdited = $this->newUser();
		$grouped = $this->newUser( [ 'sysop' ] );
		$editedElsewhere = $this->newUser();
		$this->addRevision( $this->otherDb(), $editedElsewhere );
		$this->addWatch( $this->otherDb(), $neverEdited );
		$actorId = $this->actorId( $neverEdited );

		$this->runScript( [ 'keep-grouped' => true, 'delete' => true ] );

		$this->assertFalse( $this->userExists( $neverEdited ) );
		$this->assertTrue( $this->userExists( $grouped ) );
		$this->assertTrue( $this->userExists( $editedElsewhere ) );
		$this->assertFalse( (bool)$this->getDb()->newSelectQueryBuilder()
			->select( 'actor_id' )->from( 'actor' )->where( [ 'actor_id' => $actorId ] )
			->caller( __METHOD__ )->fetchField() );
		$this->assertSame( 0, (int)$this->otherDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'watchlist' )
			->where( [ 'wl_user' => $neverEdited->getId() ] )
			->caller( __METHOD__ )->fetchField() );
	}
}
