<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\History;

use DateTimeImmutable;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\UnexpectedAffectedRowsException;
use ON\Data\ORM\History\FlushChangeOperation;
use ON\Data\ORM\History\FlushChangeSource;
use ON\Data\ORM\History\FlushCommit;
use ON\Data\ORM\History\FlushHistory;
use ON\Data\ORM\History\FlushReverter;
use ON\Data\ORM\History\HistoryException;
use ON\Data\ORM\History\HistoryRollbackException;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Session;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;
use WeakReference;

#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteFlushHistoryTest extends TestCase
{
	private SqliteMemoryHarness $db;
	private FlushHistory $history;
	private Session $session;
	private FlushReverter $reverter;

	protected function setUp(): void
	{
		$this->db = SqliteMemoryHarness::create();
		$this->db->exec('PRAGMA foreign_keys = ON');
		$this->history = new FlushHistory();
		$this->session = new Session($this->db->commandExecutor, history: $this->history);
		$this->reverter = new FlushReverter($this->history, $this->db->commandExecutor);
	}

	public function testCapturesAndRevertsGeneratedInsertLoadedUpdateAndCompleteDelete(): void
	{
		$items = $this->items();
		$beforeFlush = new DateTimeImmutable();
		$this->insert($items, 'Original');
		$insert = $this->history->latest();
		self::assertSame('UTC', $insert->committedAt->getTimezone()->getName());
		self::assertGreaterThanOrEqual($beforeFlush, $insert->committedAt);
		self::assertLessThanOrEqual(new DateTimeImmutable(), $insert->committedAt);
		self::assertSame([
			'sequence' => 1,
			'label' => 'Original',
			'committedAt' => $insert->committedAt->format('Y-m-d\TH:i:s.uP'),
			'reversible' => true,
			'changes' => [[
				'operation' => 'insert',
				'source' => 'record',
				'commandType' => InsertCommand::class,
				'collection' => 'items',
				'affectedRows' => 1,
				'identity' => ['id' => 1],
				'before' => [],
				'after' => ['title' => 'Original', 'id' => 1, 'revision' => 7, 'note' => null],
				'changedFields' => ['title', 'id', 'revision', 'note'],
				'unavailableFields' => [],
				'reversible' => true,
				'irreversibleReason' => null,
			]],
		], $insert->toArray());
		self::assertSame([$insert->toArray()], json_decode(json_encode($this->history->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

		$this->session->clear();
		$row = $this->loadItem($items);
		$row->title = 'Edited';
		$row->note = 'Written from NULL';
		$this->session->flush('Edit');
		$update = $this->history->latest()->changes[0];
		self::assertSame(['title' => 'Original', 'note' => null], $update->before);
		self::assertSame(['title' => 'Edited', 'note' => 'Written from NULL'], $update->after);
		self::assertSame(['title', 'note'], $update->changedFields);

		$this->session->remove($row);
		$this->session->flush('Remove');
		$delete = $this->history->latest()->changes[0];
		self::assertSame(['id' => 1, 'title' => 'Edited', 'revision' => 7, 'note' => 'Written from NULL'], $delete->before);
		self::assertSame([], $delete->after);
		self::assertNull($this->db->fetchRow('SELECT * FROM items'));

		self::assertSame('Remove', $this->reverter->revertLast()->label);
		self::assertSame($delete->before, $this->db->fetchRow('SELECT * FROM items'));
		self::assertSame('Edit', $this->reverter->revertLast()->label);
		self::assertSame(['id' => 1, 'title' => 'Original', 'revision' => 7, 'note' => null], $this->db->fetchRow('SELECT * FROM items'));
		self::assertSame($insert, $this->reverter->revertLast());
		self::assertNull($this->db->fetchRow('SELECT * FROM items'));
		self::assertTrue($this->history->isEmpty());
		self::assertSame('Edited', $row->title);
	}

	public function testRevertsDeleteUsingNullReadBackFromFreshInsert(): void
	{
		$items = $this->items();
		$this->insert($items, 'Original');
		$record = $this->session->getRecords()->getByKey($items->getKey(1));
		self::assertInstanceOf(RecordState::class, $record);

		$this->session->remove($record);
		$this->session->flush('Remove');
		$expected = ['id' => 1, 'title' => 'Original', 'revision' => 7, 'note' => null];
		self::assertEquals($expected, $this->history->latest()->changes[0]->before);
		self::assertNull($this->db->fetchRow('SELECT * FROM items'));

		self::assertSame('Remove', $this->reverter->revertLast()->label);
		self::assertSame($expected, $this->db->fetchRow('SELECT * FROM items'));
	}

	public function testRevertsSeveralCommitsAndPreservesTheTargetAndSequenceGaps(): void
	{
		$items = $this->items();
		$this->insert($items, 'First');
		$this->insert($items, 'Second');
		$this->insert($items, 'Third');

		$events = [];
		$unsubscribe = $this->history->onRevert(function (array $commits, int $target) use (&$events): void {
			$events[] = [array_column($commits, 'sequence'), $target];
			self::assertSame([1], array_column($this->history->all(), 'sequence'));
			self::assertSame(['title' => 'First'], $this->db->fetchRow('SELECT title FROM items'));
			self::assertNull($this->db->fetchRow('SELECT id FROM items WHERE id > 1'));

			try {
				$this->history->clear();
				self::fail('Expected clear during revert notification to be rejected.');
			} catch (HistoryException $exception) {
				self::assertSame(HistoryException::class, $exception::class);
			}
		});
		self::assertSame([3, 2], array_column($this->reverter->revertTo(1), 'sequence'));
		self::assertSame([], $this->reverter->revertTo(1));
		self::assertSame([[[3, 2], 1]], $events);
		self::assertSame(['title' => 'First'], $this->db->fetchRow('SELECT title FROM items'));
		self::assertNull($this->db->fetchRow('SELECT id FROM items WHERE id > 1'));
		$this->session->clear();
		$this->insert($items, 'Fourth');
		self::assertSame([1, 4], array_column($this->history->all(), 'sequence'));
		self::assertSame([4], array_column($this->history->since(2), 'sequence'));

		try {
			$this->reverter->revertTo(2);
			self::fail('Expected a sequence gap to be rejected.');
		} catch (HistoryRollbackException $exception) {
			self::assertStringContainsString('Sequence 2', $exception->getMessage());
		}
		self::assertSame(4, $this->reverter->revertLast()->sequence);
		self::assertSame([[[3, 2], 1], [[4], 1]], $events);
		$unsubscribe();
		$unsubscribe();
		$this->reverter->revertTo(0);
		self::assertSame([[[3, 2], 1], [[4], 1]], $events);
	}

	public function testFailedCompensationRollsBackEarlierInversesAndRetainsHistory(): void
	{
		$items = $this->items();
		$this->insert($items, 'First');
		$this->insert($items, 'Second');
		$this->insert($items, 'Third');
		$this->db->exec('DELETE FROM items WHERE id = 2');
		$this->history->onRevert(static function (): void {
			self::fail('A failed compensation must leave listeners untouched.');
		});

		$this->expectException(UnexpectedAffectedRowsException::class);

		try {
			$this->reverter->revertTo(0);
		} finally {
			self::assertSame(['title' => 'Third'], $this->db->fetchRow('SELECT title FROM items WHERE id = 3'));
			self::assertSame([1, 2, 3], array_column($this->history->all(), 'sequence'));
		}
	}

	public function testFailedCommitAndEmptyFlushDoNotConsumeASequence(): void
	{
		$this->db->exec('CREATE TABLE owners (id INTEGER PRIMARY KEY)');
		$this->db->exec('CREATE TABLE children (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES owners(id) DEFERRABLE INITIALLY DEFERRED)');
		$children = (new Registry())->collection('children')->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('owner_id', 'int')->end();
		$child = RecordState::new($children, ['owner_id' => 1]);
		$this->session->getRecords()->add($child);

		try {
			$this->session->flush('Fails at commit');
			self::fail('Expected the deferred foreign key to fail at commit.');
		} catch (PDOException $exception) {
			self::assertStringContainsString('FOREIGN KEY', $exception->getMessage());
		}
		self::assertTrue($this->history->isEmpty());
		self::assertTrue($child->isNew());
		self::assertFalse($child->hasValue('id'));
		self::assertNull($this->db->fetchRow('SELECT * FROM children'));

		$this->db->exec('INSERT INTO owners VALUES (1)');
		$this->session->flush('Retry');
		self::assertSame([], $this->session->flush('No work')->getCommandResults());
		self::assertCount(1, $this->history);
		self::assertSame(1, $this->history->latest()?->sequence);
		self::assertSame('Retry', $this->history->latest()?->label);
	}

	public function testRefusesSavepointsForJournaledFlushesAndReverts(): void
	{
		$items = $this->items();
		$this->insert($items, 'Outside');
		$this->db->connection->transaction(function () use ($items): void {
			foreach ([fn () => $this->insert($items, 'Inside'), $this->reverter->revertLast(...)] as $operation) {
				try {
					$operation();
					self::fail('Expected an outermost transaction requirement.');
				} catch (HistoryException $exception) {
					self::assertSame(HistoryException::class, $exception::class);
					self::assertStringContainsString('outermost transaction', $exception->getMessage());
				}
			}
		});
		self::assertCount(1, $this->history);
		self::assertSame(['title' => 'Outside'], $this->db->fetchRow('SELECT title FROM items'));
		self::assertNull($this->db->fetchRow('SELECT id FROM items WHERE id > 1'));
	}

	public function testRefusesBlindPatchAndPreflightsTheEntireRevertRange(): void
	{
		$items = $this->items();
		$this->insert($items, 'Original');
		$this->session->clear();
		$dto = (object) ['id' => 1, 'title' => 'Patched'];
		$query = $this->db->database->query($items);
		$this->session->update($dto, $query->select($query->id, $query->title)->projection());
		$this->session->flush('Blind patch');
		self::assertFalse($this->history->latest()->isReversible());
		self::assertSame([], $this->history->latest()->changes[0]->before);
		$this->insert($items, 'Created after patch');

		$this->expectException(HistoryRollbackException::class);
		$this->expectExceptionMessage('Missing prior values for updated fields: title');

		try {
			$this->reverter->revertTo(1);
		} finally {
			self::assertCount(3, $this->history);
			self::assertSame(['title' => 'Patched'], $this->db->fetchRow('SELECT title FROM items WHERE id = 1'));
			self::assertSame(['title' => 'Created after patch'], $this->db->fetchRow('SELECT title FROM items WHERE id = 2'));
		}
	}

	public function testRefusesToRestoreAKeyOnlyDelete(): void
	{
		$items = $this->items();
		$this->insert($items, 'Original');
		$this->session->clear();
		$this->session->remove($this->session->identify($items, ['id' => 1]));
		$this->session->flush('Key-only delete');
		self::assertSame(['id' => 1], $this->history->latest()->changes[0]->before);

		$this->expectException(HistoryRollbackException::class);
		$this->expectExceptionMessage('A complete prior row snapshot');

		try {
			$this->reverter->revertLast();
		} finally {
			self::assertCount(2, $this->history);
			self::assertNull($this->db->fetchRow('SELECT id FROM items'));
		}
	}

	public function testCapturesUpdateReadbackButRefusesToRegenerateDatabaseManagedFields(): void
	{
		$this->db->exec('CREATE TABLE versions (id INTEGER PRIMARY KEY, title TEXT, revision INTEGER)');
		$this->db->exec('CREATE TRIGGER bump_revision AFTER UPDATE OF title ON versions BEGIN UPDATE versions SET revision = OLD.revision + 1 WHERE id = OLD.id; END');
		$this->db->exec("INSERT INTO versions VALUES (1, 'Original', 3)");
		$versions = (new Registry())->collection('versions')->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('revision', 'int')->generator(DatabaseGenerator::class, null, When::UPDATE)->end();
		$query = $this->db->database->query($versions);
		$version = $query->select($query->id, $query->title, $query->revision)->to(stdClass::class)->writable($this->session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $version);
		$version->title = 'Next';
		$this->session->flush();

		$update = $this->history->latest()->changes[0];
		self::assertSame(['title' => 'Original', 'revision' => 3], $update->before);
		self::assertSame(['title' => 'Next', 'revision' => 4], $update->after);
		$this->expectException(HistoryRollbackException::class);
		$this->expectExceptionMessage('may regenerate database-managed fields');
		$this->reverter->revertLast();
	}

	#[DataProvider('retentionBoundaries')]
	public function testRevertsToTheOldestReachableState(int $capacity, int $base, array $undone, ?array $retainedRow): void
	{
		$this->history = new FlushHistory($capacity);
		$this->session = new Session($this->db->commandExecutor, history: $this->history);
		$this->reverter = new FlushReverter($this->history, $this->db->commandExecutor);
		$items = $this->items();
		$this->insert($items, 'First');
		$this->insert($items, 'Second');
		$this->insert($items, 'Third');

		self::assertSame($base, $this->history->getBaseSequence());

		try {
			$this->reverter->revertTo($base - 1);
			self::fail('Expected the target below the base to be rejected.');
		} catch (HistoryRollbackException $exception) {
			self::assertStringContainsString('Sequence ' . ($base - 1), $exception->getMessage());
		}
		self::assertSame($undone, array_column($this->reverter->revertTo($base), 'sequence'));
		self::assertTrue($this->history->isEmpty());
		self::assertSame($base, $this->history->getBaseSequence());
		self::assertSame($retainedRow, $this->db->fetchRow('SELECT id FROM items ORDER BY id DESC LIMIT 1'));
		self::assertSame([], $this->reverter->revertTo($base));
	}

	public static function retentionBoundaries(): array
	{
		return [
			'initial state' => [3, 0, [3, 2, 1], null],
			'one eviction' => [2, 1, [3, 2], ['id' => 1]],
			'two evictions' => [1, 2, [3], ['id' => 2]],
		];
	}

	public function testClearMovesTheRevertBoundaryToTheLastSequenceIssued(): void
	{
		$items = $this->items();
		$this->insert($items, 'First');
		$this->insert($items, 'Second');
		$this->reverter->revertLast();
		$this->session->clear();
		$this->history->clear();
		self::assertSame(2, $this->history->getBaseSequence());
		$this->insert($items, 'Third');

		try {
			$this->reverter->revertTo(1);
			self::fail('Expected a pre-clear sequence to be rejected.');
		} catch (HistoryRollbackException $exception) {
			self::assertStringContainsString('Sequence 1', $exception->getMessage());
		}
		self::assertSame([3], array_column($this->reverter->revertTo(2), 'sequence'));
		self::assertSame(['id' => 1], $this->db->fetchRow('SELECT id FROM items ORDER BY id DESC LIMIT 1'));
	}

	public function testListenerExceptionsLeaveCommittedChangesAppliedAndReleaseTheGuard(): void
	{
		$items = $this->items();
		$commitError = new RuntimeException('Commit listener failed');
		$offCommit = $this->history->onCommit(static function () use ($commitError): never {
			throw $commitError;
		});

		try {
			$this->insert($items, 'First');
			self::fail('Expected the commit listener exception.');
		} catch (RuntimeException $exception) {
			self::assertSame($commitError, $exception);
		}
		$offCommit();
		self::assertSame('First', $this->history->latest()?->label);
		self::assertSame(['title' => 'First'], $this->db->fetchRow('SELECT title FROM items'));
		self::assertSame([], $this->session->flush()->getCommandResults());

		$revertError = new RuntimeException('Revert listener failed');
		$offRevert = $this->history->onRevert(static function () use ($revertError): never {
			throw $revertError;
		});

		try {
			$this->reverter->revertLast();
			self::fail('Expected the revert listener exception.');
		} catch (RuntimeException $exception) {
			self::assertSame($revertError, $exception);
		}
		$offRevert();
		self::assertTrue($this->history->isEmpty());
		self::assertNull($this->db->fetchRow('SELECT id FROM items'));
		$this->session->clear();
		$this->insert($items, 'Second');
		self::assertSame(2, $this->reverter->revertLast()->sequence);
	}

	public function testHistoryOutlivesTrackedObjectsAndRecords(): void
	{
		$items = $this->items();
		$this->insert($items, 'Original');
		$this->session->clear();
		$row = $this->loadItem($items);
		$record = $this->session->getRecords()->get($items->getKey(1));
		self::assertInstanceOf(RecordState::class, $record);
		$dtoReference = WeakReference::create($row);
		$recordReference = WeakReference::create($record);
		$row->title = 'Tracked';
		$this->session->flush();

		unset($row, $record);
		$this->session->clear();
		gc_collect_cycles();
		self::assertNull($dtoReference->get());
		self::assertNull($recordReference->get());
		self::assertSame(['title' => 'Tracked'], $this->history->latest()->changes[0]->after);
	}

	public function testCapturesWritableFlatProjectionAtItsSourceRecord(): void
	{
		$items = $this->items();
		$this->insert($items, 'Original');
		$this->db->exec('CREATE TABLE owners (id INTEGER PRIMARY KEY, item_id INTEGER REFERENCES items(id))');
		$this->db->exec('INSERT INTO owners VALUES (9, 1)');
		$owners = $items->getRegistry()->collection('owners')->primaryKey('id')
			->field('id', 'int')->end()
			->field('item_id', 'int')->end();
		$owners->belongsTo('item', 'items')->innerKey('item_id')->outerKey('id');
		$this->session->clear();
		$query = $this->db->database->query($owners);
		$row = $query->select($query->id, $query->item->title->as('itemTitle'))->to(stdClass::class)->writable($this->session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $row);
		$row->itemTitle = 'Through projection';
		$this->session->flush();

		$update = $this->history->latest()->changes[0];
		self::assertSame('items', $update->getCollection()->getName());
		self::assertSame(['id' => 1], $update->identity);
		self::assertSame(['title' => 'Original'], $update->before);
		self::assertSame(['title' => 'Through projection'], $update->after);
		$this->reverter->revertLast();
		self::assertSame(['title' => 'Original'], $this->db->fetchRow('SELECT title FROM items'));
	}

	public function testCapturesGeneratedForeignKeysAndUnwindsAGraphInReverseOrder(): void
	{
		[$owners] = $this->graph();
		$query = $this->db->database->query($owners);
		$query->select($query->label);
		$query->group->select('label');
		$query->children->select('label');
		$query->profile->select('label');
		$query->tags->select('label');
		$owner = (object) [
			'label' => 'Owner',
			'group' => (object) ['label' => 'Group'],
			'children' => [(object) ['label' => 'Child']],
			'profile' => (object) ['label' => 'Profile'],
			'tags' => [(object) ['label' => 'Tag']],
		];
		$this->session->create($owner, $query->projection());
		$observed = null;
		$this->history->onCommit(function (FlushCommit $commit) use (&$observed): void {
			$observed = $commit;
			self::assertSame($commit, $this->history->latest());
			self::assertSame([$commit], $this->history->all());
			self::assertCount(1, $this->history);
			self::assertSame([$commit->toArray()], $this->history->toArray());
			foreach ($this->session->getRecords()->getAll() as $record) {
				self::assertTrue($record->isClean());
			}
			foreach ($this->session->getRelations()->getAll() as $relation) {
				self::assertFalse($relation->hasChanges());
			}
			self::assertSame(['owner_id' => 1, 'tag_id' => 1], $this->db->fetchRow('SELECT * FROM owner_tag'));
		});
		$this->session->flush('Create graph');

		$commit = $this->history->latest();
		self::assertSame($commit, $observed);
		self::assertTrue($commit->isReversible());
		$byCollection = array_column($commit->toArray()['changes'], null, 'collection');
		self::assertSame(['groups', 'tags', 'owners', 'children', 'profiles', 'owner_tag'], array_keys($byCollection));
		self::assertSame(1, $byCollection['owners']['after']['group_id']);
		self::assertSame(1, $byCollection['children']['after']['owner_id']);
		self::assertSame(1, $byCollection['profiles']['after']['owner_id']);
		self::assertSame(['owner_id' => 1, 'tag_id' => 1], $byCollection['owner_tag']['identity']);
		self::assertSame('extra', $byCollection['owner_tag']['source']);
		self::assertSame(['owner_id' => 1, 'tag_id' => 1], $this->db->fetchRow('SELECT * FROM owner_tag'));

		$this->reverter->revertLast();
		foreach (array_keys($byCollection) as $table) {
			self::assertSame(['total' => 0], $this->db->fetchRow("SELECT COUNT(*) AS total FROM $table"));
		}
	}

	public function testRevertsThroughTableDeleteButDoesNotReinsertAMissingLink(): void
	{
		[$owners, $tags] = $this->graph();
		$this->db->exec("INSERT INTO groups VALUES (1, 'Group')");
		$this->db->exec("INSERT INTO owners VALUES (1, 'Owner', 1)");
		$this->db->exec("INSERT INTO tags VALUES (1, 'Tag'), (2, 'Unlinked')");
		$this->db->exec('INSERT INTO owner_tag VALUES (1, 1)');
		$owner = $this->session->identify($owners, ['id' => 1]);
		$this->session->detach($this->session->identify($tags, ['id' => 1]), $owner, 'tags');
		$this->session->detach($this->session->identify($tags, ['id' => 2]), $owner, 'tags');
		$this->session->flush('Unlink');

		[$removed, $missing] = $this->history->latest()->changes;
		self::assertSame(FlushChangeOperation::Delete, $removed->operation);
		self::assertSame(FlushChangeSource::Extra, $removed->source);
		self::assertSame(['owner_id' => 1, 'tag_id' => 1], $removed->before);
		self::assertSame(['owner_id' => 1, 'tag_id' => 2], $missing->identity);
		self::assertSame(0, $missing->affectedRows);
		self::assertSame([], $missing->before);
		self::assertNull($this->db->fetchRow('SELECT * FROM owner_tag'));

		$this->reverter->revertLast();
		self::assertSame(['owner_id' => 1, 'tag_id' => 1], $this->db->fetchRow('SELECT * FROM owner_tag'));
		self::assertNull($this->db->fetchRow('SELECT * FROM owner_tag WHERE tag_id = 2'));
	}

	private function items(): CollectionInterface
	{
		$this->db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, revision INTEGER DEFAULT 7, note TEXT DEFAULT NULL)');

		return (new Registry())->collection('items')->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('title', 'string')->end()
			->field('revision', 'int')->end()
			->field('note', 'string')->nullable(true)->end();
	}

	private function insert(CollectionInterface $items, string $title): void
	{
		$this->session->getRecords()->add(RecordState::new($items, ['title' => $title]));
		$this->session->flush($title);
	}

	private function loadItem(CollectionInterface $items): stdClass
	{
		$query = $this->db->database->query($items);
		$row = $query->select($query->id, $query->title, $query->revision, $query->note)->to(stdClass::class)->writable($this->session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $row);

		return $row;
	}

	/** @return array{CollectionInterface, CollectionInterface} */
	private function graph(): array
	{
		$this->db->exec('CREATE TABLE groups (id INTEGER PRIMARY KEY, label TEXT)');
		$this->db->exec('CREATE TABLE owners (id INTEGER PRIMARY KEY, label TEXT, group_id INTEGER NOT NULL REFERENCES groups(id))');
		$this->db->exec('CREATE TABLE children (id INTEGER PRIMARY KEY, label TEXT, owner_id INTEGER NOT NULL REFERENCES owners(id))');
		$this->db->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, label TEXT, owner_id INTEGER NOT NULL UNIQUE REFERENCES owners(id))');
		$this->db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, label TEXT)');
		$this->db->exec('CREATE TABLE owner_tag (owner_id INTEGER REFERENCES owners(id), tag_id INTEGER REFERENCES tags(id), PRIMARY KEY (owner_id, tag_id))');
		$registry = new Registry();
		foreach (['groups', 'owners', 'children', 'profiles', 'tags'] as $name) {
			$registry->collection($name)->primaryKey('id')->field('id', 'int')->autoIncrement(true)->end()->field('label', 'string')->end();
		}
		$owners = $registry->getCollection('owners');
		$owners->field('group_id', 'int');
		$registry->getCollection('children')->field('owner_id', 'int');
		$registry->getCollection('profiles')->field('owner_id', 'int');
		$registry->collection('owner_tag')->field('owner_id', 'int')->end()->field('tag_id', 'int')->end();
		$owners->belongsTo('group', 'groups')->innerKey('group_id')->outerKey('id');
		$owners->hasMany('children', 'children')->innerKey('id')->outerKey('owner_id');
		$owners->hasOne('profile', 'profiles')->innerKey('id')->outerKey('owner_id');
		$owners->relation('tags', M2MRelation::class)->collection('tags')->innerKey('id')->outerKey('id')
			->through('owner_tag')->innerKey('owner_id')->outerKey('tag_id');

		return [$owners, $registry->getCollection('tags')];
	}
}
