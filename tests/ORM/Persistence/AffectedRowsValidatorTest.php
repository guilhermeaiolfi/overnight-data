<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\UnexpectedAffectedRowsException;
use ON\Data\ORM\Persistence\AffectedRowsValidator;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class AffectedRowsValidatorTest extends TestCase
{
	use OrmFixture;

	private AffectedRowsValidator $validator;

	protected function setUp(): void
	{
		$this->validator = new AffectedRowsValidator();
	}

	public function testInsertWithOneAffectedRowPasses(): void
	{
		$command = new InsertCommand($this->users(), ['name' => 'Ada']);

		$this->validator->validate($command, new CommandResult(1));

		$this->addToAssertionCount(1);
	}

	public function testInsertWithZeroAffectedRowsThrows(): void
	{
		$command = new InsertCommand($this->users(), ['name' => 'Ada']);

		$this->expectException(UnexpectedAffectedRowsException::class);
		$this->expectExceptionMessage("Insert command for collection 'users' expected to affect 1 row, affected 0.");

		$this->validator->validate($command, new CommandResult(0));
	}

	public function testUpdateWithOneAffectedRowPasses(): void
	{
		$command = new UpdateCommand($this->users(), ['id' => 1], ['name' => 'Ada']);

		$this->validator->validate($command, new CommandResult(1));

		$this->addToAssertionCount(1);
	}

	public function testUpdateWithZeroAffectedRowsThrows(): void
	{
		$command = new UpdateCommand($this->users(), ['id' => 1], ['name' => 'Ada']);

		$this->expectException(UnexpectedAffectedRowsException::class);
		$this->expectExceptionMessage("Update command for collection 'users' with identity {\"id\":1} expected to affect 1 row, affected 0.");

		$this->validator->validate($command, new CommandResult(0));
	}

	public function testDeleteWithOneAffectedRowPasses(): void
	{
		$command = new DeleteCommand($this->users(), ['id' => 1]);

		$this->validator->validate($command, new CommandResult(1));

		$this->addToAssertionCount(1);
	}

	public function testDeleteWithZeroAffectedRowsThrows(): void
	{
		$command = new DeleteCommand($this->users(), ['id' => 1]);

		$this->expectException(UnexpectedAffectedRowsException::class);
		$this->expectExceptionMessage("Delete command for collection 'users' with identity {\"id\":1} expected to affect 1 row, affected 0.");

		$this->validator->validate($command, new CommandResult(0));
	}

	public function testMessageIncludesCollectionName(): void
	{
		$command = new InsertCommand($this->posts(), ['title' => 'Draft']);

		try {
			$this->validator->validate($command, new CommandResult(0));
			self::fail('Expected UnexpectedAffectedRowsException.');
		} catch (UnexpectedAffectedRowsException $exception) {
			self::assertStringContainsString("collection 'posts'", $exception->getMessage());
		}
	}

	public function testUpdateMessageIncludesIdentity(): void
	{
		$command = new UpdateCommand($this->users(), ['tenant_id' => 5, 'id' => 10], ['name' => 'Ada']);

		try {
			$this->validator->validate($command, new CommandResult(0));
			self::fail('Expected UnexpectedAffectedRowsException.');
		} catch (UnexpectedAffectedRowsException $exception) {
			self::assertStringContainsString('{"tenant_id":5,"id":10}', $exception->getMessage());
		}
	}

	public function testDeleteMessageIncludesIdentity(): void
	{
		$command = new DeleteCommand($this->users(), ['id' => 1]);

		try {
			$this->validator->validate($command, new CommandResult(0));
			self::fail('Expected UnexpectedAffectedRowsException.');
		} catch (UnexpectedAffectedRowsException $exception) {
			self::assertStringContainsString('{"id":1}', $exception->getMessage());
		}
	}
}
