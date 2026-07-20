<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\Definition\Field\Generator\GenerationContext;
use ON\Data\Definition\Field\Generator\PhpFieldGeneratorInterface;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\CommandPlanner;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Record\RecordState;
use PHPUnit\Framework\TestCase;

final class FieldGeneratorApplierTest extends TestCase
{
	public function testInsertPlanningAppliesPhpGeneratorWhenValueMissing(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('token', 'string')
				->generator(FixedTokenGenerator::class, 'x', When::INSERT)
				->end();

		$record = RecordState::new($users, ['id' => 1]);
		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(InsertCommand::class, $command);
		self::assertSame('x-ok', $command->getValues()['token']);
		self::assertSame('x-ok', $record->getValue('token'));
	}

	public function testInsertPlanningSkipsPhpGeneratorWhenValuePresent(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('token', 'string')
				->generator(FixedTokenGenerator::class, null, When::INSERT)
				->end();

		$record = RecordState::new($users, ['id' => 1, 'token' => 'keep']);
		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(InsertCommand::class, $command);
		self::assertSame('keep', $command->getValues()['token']);
	}

	public function testUpdatePlanningAppliesPhpGeneratorForWhenUpdate(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('token', 'string')
				->generator(FixedTokenGenerator::class, 'u', When::UPDATE)
				->end();

		$record = RecordState::clean($users->getKey(1), [
			'id' => 1,
			'name' => 'Ada',
			'token' => 'old',
		]);
		$record->setValues(['name' => 'Grace']);

		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(UpdateCommand::class, $command);
		self::assertSame('u-ok', $command->getChanges()['token']);
	}

	public function testInsertOmitsNullDatabaseGeneratedPrimaryKey(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('name', 'string')->end();

		$record = RecordState::new($users, ['id' => null, 'name' => 'Ada']);
		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(InsertCommand::class, $command);
		self::assertArrayNotHasKey('id', $command->getValues());
		self::assertSame('Ada', $command->getValues()['name']);
	}
}

final class FixedTokenGenerator implements PhpFieldGeneratorInterface
{
	public function __construct(
		private readonly ?string $prefix = null,
	) {
	}

	public function generate(GenerationContext $context): mixed
	{
		return ($this->prefix ?? 'tok') . '-ok';
	}
}
