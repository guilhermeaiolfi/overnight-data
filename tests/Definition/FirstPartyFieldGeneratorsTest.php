<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use ON\Data\Definition\Field\Generator\FieldGeneratorFactory;
use ON\Data\Definition\Field\Generator\GenerationContext;
use ON\Data\Definition\Field\Generator\NowGenerator;
use ON\Data\Definition\Field\Generator\UuidGenerator;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\CommandPlanner;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Record\RecordState;
use PHPUnit\Framework\TestCase;

final class FirstPartyFieldGeneratorsTest extends TestCase
{
	public function testUuidGeneratorReturnsRfc4122Version4String(): void
	{
		$users = (new Registry())->collection('users');
		$field = $users->field('id', 'string');
		$generator = new UuidGenerator();

		$value = $generator->generate(new GenerationContext(
			$users,
			$field,
			RecordState::new($users, []),
			When::INSERT,
		));

		self::assertIsString($value);
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$value,
		);
	}

	public function testUuidGeneratorProducesDistinctValues(): void
	{
		$generator = new UuidGenerator();
		$users = (new Registry())->collection('users');
		$field = $users->field('id', 'string');
		$context = new GenerationContext(
			$users,
			$field,
			RecordState::new($users, []),
			When::INSERT,
		);

		self::assertNotSame($generator->generate($context), $generator->generate($context));
	}

	public function testNowGeneratorReturnsDateTimeImmutable(): void
	{
		$users = (new Registry())->collection('users');
		$field = $users->field('createdAt', 'datetime');
		$before = new DateTimeImmutable('now');

		$value = (new NowGenerator())->generate(new GenerationContext(
			$users,
			$field,
			RecordState::new($users, []),
			When::INSERT,
		));

		$after = new DateTimeImmutable('now');

		self::assertInstanceOf(DateTimeImmutable::class, $value);
		self::assertGreaterThanOrEqual($before->getTimestamp(), $value->getTimestamp());
		self::assertLessThanOrEqual($after->getTimestamp(), $value->getTimestamp());
	}

	public function testNowGeneratorHonorsTimezoneArg(): void
	{
		$users = (new Registry())->collection('users');
		$field = $users->field('createdAt', 'datetime');

		$value = (new NowGenerator('UTC'))->generate(new GenerationContext(
			$users,
			$field,
			RecordState::new($users, []),
			When::INSERT,
		));

		self::assertInstanceOf(DateTimeImmutable::class, $value);
		self::assertSame('UTC', $value->getTimezone()->getName());
	}

	public function testNowGeneratorRejectsInvalidTimezone(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('timezone');

		new NowGenerator('Not/AZone');
	}

	public function testFactoryCreatesFirstPartyGenerators(): void
	{
		$factory = new FieldGeneratorFactory();

		self::assertInstanceOf(UuidGenerator::class, $factory->create(UuidGenerator::class));
		self::assertInstanceOf(NowGenerator::class, $factory->create(NowGenerator::class, 'UTC'));

		$fromInstance = $factory->create(NowGenerator::class, (new NowGenerator(new DateTimeZone('UTC')))->getDefinitionArg());
		self::assertInstanceOf(NowGenerator::class, $fromInstance);
	}

	public function testInsertPlanningAppliesUuidAndNowGenerators(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'string')->generator(UuidGenerator::class, null, When::INSERT)->end()
			->field('createdAt', 'datetime')->generator(NowGenerator::class, 'UTC', When::INSERT)->end()
			->field('name', 'string')->end();

		$record = RecordState::new($users, ['name' => 'Ada']);
		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(InsertCommand::class, $command);
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$command->getValues()['id'],
		);
		self::assertInstanceOf(DateTimeImmutable::class, $command->getValues()['createdAt']);
		self::assertSame('UTC', $command->getValues()['createdAt']->getTimezone()->getName());
	}

	public function testUpdatePlanningAppliesNowGenerator(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('updatedAt', 'datetime')
				->generator(NowGenerator::class, null, When::UPDATE)
				->end();

		$record = RecordState::clean($users->getKey(1), [
			'id' => 1,
			'name' => 'Ada',
			'updatedAt' => new DateTimeImmutable('2020-01-01 00:00:00'),
		]);
		$record->setValues(['name' => 'Grace']);

		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(UpdateCommand::class, $command);
		self::assertInstanceOf(DateTimeImmutable::class, $command->getChanges()['updatedAt']);
		self::assertNotSame('2020-01-01 00:00:00', $command->getChanges()['updatedAt']->format('Y-m-d H:i:s'));
	}

	public function testDefinitionFlattensNowGeneratorInstance(): void
	{
		$field = (new Registry())
			->collection('users')
			->field('updatedAt', 'datetime')
			->generator(new NowGenerator('UTC'), null, When::INSERT | When::UPDATE);

		self::assertSame([
			'class' => NowGenerator::class,
			'arg' => 'UTC',
			'when' => When::INSERT | When::UPDATE,
		], $field->getGenerator());
	}
}
