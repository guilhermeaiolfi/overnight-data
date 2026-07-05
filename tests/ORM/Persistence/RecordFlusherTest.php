<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeferredFlushResult;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\RecordFlusher;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class RecordFlusherTest extends TestCase
{
	use OrmFixture;

	public function testFlushDelegatesToSchedulerAndReturnsCommandResults(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$states = $this->states($record);
		$result = new CommandResult(1, ['id' => 10]);
		$executor = new RecordingCommandExecutor($result);

		$results = (new RecordFlusher($executor))->flush($states);

		self::assertSame([$result], $results);
		self::assertContainsOnlyInstancesOf(InsertCommand::class, $executor->getCommands());
		self::assertTrue($record->isClean());
	}

	public function testFlushDeferredDelegatesToSchedulerWithDeferredFinalizers(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, ['name' => 'Ada']);
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor(new CommandResult(1, ['id' => 10]));

		$flush = (new RecordFlusher($executor))->flushDeferred($states);

		self::assertInstanceOf(DeferredFlushResult::class, $flush);
		self::assertTrue($record->isNew());
		$flush->finalize();
		self::assertTrue($record->isClean());
		self::assertTrue($record->hasKey());
	}

	private function states(RecordState ...$records): RecordStateStore
	{
		$states = new RecordStateStore();
		foreach ($records as $record) {
			$states->add($record);
		}

		return $states;
	}
}
