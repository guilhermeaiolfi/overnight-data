<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\History;

use DateTime;
use Fiber;
use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\NonTransactionalFlushException;
use ON\Data\ORM\History\FlushCapture;
use ON\Data\ORM\History\FlushChangeOperation;
use ON\Data\ORM\History\FlushCommit;
use ON\Data\ORM\History\FlushHistory;
use ON\Data\ORM\History\FlushReverter;
use ON\Data\ORM\History\HistoryException;
use ON\Data\ORM\History\HistoryRollbackException;
use ON\Data\ORM\History\InspectableCommandInterface;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Session;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\CallbackCommandExecutor;
use Tests\ON\Data\Support\RecordingCommandExecutor;
use Tests\ON\Data\Support\Relation\TestCommand;
use WeakReference;

final class FlushHistoryTest extends TestCase
{
	public function testRetainsOnlyLatestCommitsWithoutReusingSequences(): void
	{
		$history = new FlushHistory(2);
		$session = new Session(new RecordingCommandExecutor(), history: $history);
		$collection = (new Registry())->collection('items')->primaryKey('id')->field('id', 'int')->end();

		for ($id = 1; $id <= 3; ++$id) {
			$session->getRecords()->add(RecordState::new($collection, ['id' => $id]));
			$session->flush('Insert ' . $id);
		}
		$session->flush('No work');

		self::assertSame(2, $history->getMaxEntries());
		self::assertCount(2, $history);
		self::assertNull($history->get(1));
		self::assertSame([2, 3], array_column($history->all(), 'sequence'));
		self::assertSame([$history->latest()], $history->since(2));
		self::assertSame('Insert 3', $history->latest()?->label);

		$history->clear();
		self::assertTrue($history->isEmpty());
		self::assertNull($history->latest());
		$session->getRecords()->add(RecordState::new($collection, ['id' => 4]));
		$session->flush();

		self::assertSame(4, $history->latest()?->sequence);
	}

	public function testRejectsNonPositiveCapacity(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new FlushHistory(0);
	}

	public function testSharedJournalRejectsOverlappingWritesUntilCommit(): void
	{
		$history = new FlushHistory();
		$first = new Session(new CallbackCommandExecutor(static function (): CommandResult {
			Fiber::suspend();

			return new CommandResult(1);
		}), history: $history);
		$second = new Session(new RecordingCommandExecutor(), history: $history);
		$collection = (new Registry())->collection('items')->primaryKey('id')->field('id', 'int')->end();
		$first->getRecords()->add(RecordState::new($collection, ['id' => 1]));
		$second->getRecords()->add(RecordState::new($collection, ['id' => 2]));

		$flush = new Fiber($first->flush(...));
		$flush->start('First');
		self::assertTrue($history->isEmpty());

		try {
			$second->flush('Second');
			self::fail('Expected an overlapping flush to be rejected.');
		} catch (HistoryException $exception) {
			self::assertSame(HistoryException::class, $exception::class);
			self::assertStringContainsString('already in progress', $exception->getMessage());
		}
		$flush->resume();
		$second->flush('Second');
		self::assertSame(['First', 'Second'], array_column($history->all(), 'label'));
	}

	public function testCommitListenersCanUnsubscribeDuringNotification(): void
	{
		$history = new FlushHistory();
		$session = new Session(new RecordingCommandExecutor(), history: $history);
		$collection = (new Registry())->collection('items')->primaryKey('id')->field('id', 'int')->end();
		$calls = [];
		$first = $second = null;
		$first = $history->onCommit(static function (FlushCommit $commit) use ($history, &$calls, &$first, &$second): void {
			$calls[] = ['first', $commit->sequence];
			$first();
			$second();
			$history->onCommit(static function (FlushCommit $commit) use (&$calls): void {
				$calls[] = ['later', $commit->sequence];
			});
		});
		$second = $history->onCommit(static function (FlushCommit $commit) use (&$calls): void {
			$calls[] = ['second', $commit->sequence];
		});

		$session->getRecords()->add(RecordState::new($collection, ['id' => 1]));
		$session->flush();
		$first();
		$second();
		$session->flush();
		$session->getRecords()->add(RecordState::new($collection, ['id' => 2]));
		$session->flush();

		self::assertSame([['first', 1], ['later', 2]], $calls);
	}

	public function testCommitListenerRejectsReentrantWritesBeforeApplyingIntents(): void
	{
		$history = new FlushHistory();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor, history: $history);
		$reverter = new FlushReverter($history, $executor);
		$collection = (new Registry())->collection('items')->primaryKey('id')->field('id', 'int')->end();
		$pending = new stdClass();
		$history->onCommit(static function () use ($history, $session, $reverter, $pending): void {
			$session->create($pending);
			foreach ([$session->flush(...), $reverter->revertLast(...), fn () => $reverter->revertTo(0), $history->clear(...)] as $write) {
				try {
					$write();
					self::fail('Expected the re-entrant journal write to be rejected.');
				} catch (HistoryException $exception) {
					self::assertSame(HistoryException::class, $exception::class);
					self::assertStringContainsString('already in progress', $exception->getMessage());
				}
			}
			self::assertNotNull($session->getContext()->getIntents()->get($pending));
		});

		$session->getRecords()->add(RecordState::new($collection, ['id' => 1]));
		$session->flush();
		self::assertCount(1, $history);
	}

	public function testSupportsAnInjectedFlusherWithItsOwnHistory(): void
	{
		$executor = new RecordingCommandExecutor();
		$first = new FlushHistory();
		$second = new FlushHistory();
		$flusher = new FlushExecutor($executor, history: $first);
		$session = new Session($executor, $flusher, history: $second);
		$collection = (new Registry())->collection('items')->primaryKey('id')->field('id', 'int')->end();
		$session->getRecords()->add(RecordState::new($collection, ['id' => 1]));
		$session->flush('Override');
		self::assertSame($first, $flusher->getHistory());
		self::assertTrue($first->isEmpty());
		self::assertSame($second, $session->getHistory());
		self::assertSame('Override', $second->latest()?->label);
	}

	public function testEmptyHistoryAndUnknownSequencesAreRejected(): void
	{
		$reverter = new FlushReverter(new FlushHistory(), new RecordingCommandExecutor());

		try {
			$reverter->revertLast();
			self::fail('Expected an empty-history error.');
		} catch (HistoryRollbackException $exception) {
			self::assertStringContainsString('empty flush history', $exception->getMessage());
		}
		$this->expectException(HistoryRollbackException::class);
		$this->expectExceptionMessage('Sequence 999 is not retained');
		$reverter->revertTo(999);
	}

	public function testRejectsAnExecutorWithoutTransactions(): void
	{
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				return new CommandResult(1);
			}
		};

		$this->expectException(NonTransactionalFlushException::class);
		new FlushReverter(new FlushHistory(), $executor);
	}

	public function testMissingResolvedKeyMakesAnInsertIrreversible(): void
	{
		$history = new FlushHistory();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor, history: $history);
		$collection = (new Registry())->collection('items')->primaryKey('id')->field('id', 'int')->end();
		$session->getRecords()->add(RecordState::new($collection));
		$session->flush();
		self::assertFalse($history->latest()->isReversible());
		$this->expectException(HistoryRollbackException::class);
		$this->expectExceptionMessage('resolved primary key is incomplete');
		(new FlushReverter($history, $executor))->revertLast();
	}

	public function testCustomCommandsCanExposeDetachedDataWithoutProvidingAnInverse(): void
	{
		$collection = (new Registry())->collection('items');
		$opaque = new stdClass();
		$opaqueReference = WeakReference::create($opaque);
		$date = new DateTime('2026-02-01T12:30:00+00:00');
		$command = new class ($collection, $opaque, $date) implements InspectableCommandInterface {
			public function __construct(private CollectionInterface $collection, private object $opaque, private DateTime $date)
			{
			}

			public function getCollection(): CollectionInterface
			{
				return $this->collection;
			}

			public function getHistoryData(): array
			{
				return ['identity' => ['id' => 1], 'values' => [
					'metadata' => ['opaque' => $this->opaque],
					'time' => $this->date,
					'binary' => "\xff",
					'binaryMap' => ["\xff" => 'value'],
				]];
			}
		};
		$capture = new FlushCapture();
		$capture->record($command, new CommandResult(1, ['id' => 1]));
		$capture->record(new TestCommand($collection), new CommandResult(1));
		unset($opaque, $command);
		$date->modify('+1 day');
		self::assertNull($opaqueReference->get());

		[$inspected, $opaqueCommand] = $capture->getChanges();
		self::assertFalse($inspected->isReversible());
		self::assertSame(FlushChangeOperation::Custom, $inspected->operation);
		self::assertSame('custom', $inspected->toArray()['operation']);
		self::assertSame(['id' => 1], $inspected->identity);
		self::assertSame(['after' => ['metadata']], $inspected->unavailableFields);
		self::assertSame([
			'time' => '2026-02-01T12:30:00.000000+00:00',
			'binary' => ['base64' => '/w=='],
			'binaryMap' => ['entries' => [[['base64' => '/w=='], 'value']]],
			'id' => 1,
		], $inspected->toArray()['after']);
		self::assertFalse($opaqueCommand->isReversible());
		self::assertSame(TestCommand::class, $opaqueCommand->commandType);
		$this->expectException(HistoryRollbackException::class);
		$this->expectExceptionMessage('does not expose a supported inverse');
		$inspected->inverse();
	}
}
