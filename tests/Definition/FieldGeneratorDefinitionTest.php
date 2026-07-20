<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Field\Generator\FieldGeneratorFactory;
use ON\Data\Definition\Field\Generator\GenerationContext;
use ON\Data\Definition\Field\Generator\GeneratorDefinitionArgInterface;
use ON\Data\Definition\Field\Generator\PhpFieldGeneratorInterface;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Record\RecordState;
use PHPUnit\Framework\TestCase;
use stdClass;

final class FieldGeneratorDefinitionTest extends TestCase
{
	public function testAutoIncrementIsSugarForDatabaseGenerator(): void
	{
		$field = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')
			->autoIncrement(true);

		self::assertTrue($field->isAutoIncrement());
		self::assertTrue($field->isDatabaseGenerated());
		self::assertTrue($field->isGeneratedWhen(When::INSERT));
		self::assertSame([
			'class' => DatabaseGenerator::class,
			'arg' => null,
			'when' => When::INSERT,
		], $field->getGenerator());
	}

	public function testGeneratorAcceptsDatabaseSequenceArgAndWhenBitmask(): void
	{
		$field = (new Registry())
			->collection('users')
			->field('balance', 'int')
			->generator(DatabaseGenerator::class, 'user_balance_seq', When::INSERT);

		self::assertTrue($field->isDatabaseGenerated());
		self::assertSame('user_balance_seq', $field->getGeneratorSequence());
		self::assertSame(DatabaseGenerator::class, $field->getGenerator()['class']);
	}

	public function testGeneratorAcceptsDatabaseInstanceAndArrayArg(): void
	{
		$fromInstance = (new Registry())
			->collection('users')
			->field('id', 'int')
			->generator(new DatabaseGenerator('user_id_seq'));

		self::assertSame(DatabaseGenerator::class, $fromInstance->getGenerator()['class']);
		self::assertSame('user_id_seq', $fromInstance->getGeneratorSequence());

		$fromArray = (new Registry())
			->collection('accounts')
			->field('balance', 'int')
			->generator(DatabaseGenerator::class, ['sequence' => 'balance_seq']);

		self::assertSame('balance_seq', $fromArray->getGeneratorSequence());
	}

	public function testGeneratorAcceptsListConstructorArgsForPhpGenerators(): void
	{
		$users = (new Registry())->collection('users');
		$field = $users
			->field('token', 'string')
			->generator(DefinitionTokenGenerator::class, ['pre', 'fix'], When::INSERT);

		$generator = (new FieldGeneratorFactory())->create(
			$field->getGenerator()['class'],
			$field->getGenerator()['arg'],
		);

		self::assertInstanceOf(DefinitionTokenGenerator::class, $generator);
		self::assertSame(
			'pre-fix',
			$generator->generate(new GenerationContext(
				$users,
				$field,
				RecordState::new($users, []),
				When::INSERT,
			)),
		);
	}

	public function testGeneratorFlattensPhpInstanceViaDefinitionArg(): void
	{
		$field = (new Registry())
			->collection('users')
			->field('token', 'string')
			->generator(new DefinitionTokenGenerator('flat', 'ok'));

		self::assertSame(DefinitionTokenGenerator::class, $field->getGenerator()['class']);
		self::assertSame(['flat', 'ok'], $field->getGenerator()['arg']);
	}

	public function testGeneratorAcceptsPhpGeneratorClass(): void
	{
		$field = (new Registry())
			->collection('users')
			->field('token', 'string')
			->generator(DefinitionTokenGenerator::class, 'prefix', When::INSERT | When::UPDATE);

		self::assertFalse($field->isDatabaseGenerated());
		self::assertTrue($field->isGeneratedWhen(When::INSERT));
		self::assertTrue($field->isGeneratedWhen(When::UPDATE));
		self::assertSame(DefinitionTokenGenerator::class, $field->getGenerator()['class']);
		self::assertSame('prefix', $field->getGenerator()['arg']);
	}

	public function testGeneratorRejectsInvalidClass(): void
	{
		$field = (new Registry())->collection('users')->field('id', 'int');

		$this->expectException(InvalidArgumentException::class);
		$field->generator(stdClass::class);
	}

	public function testDisablingAutoIncrementClearsDatabaseGenerator(): void
	{
		$field = (new Registry())
			->collection('users')
			->field('id', 'int')
			->autoIncrement(true)
			->autoIncrement(false);

		self::assertFalse($field->isAutoIncrement());
		self::assertNull($field->getGenerator());
	}
}

final class DefinitionTokenGenerator implements PhpFieldGeneratorInterface, GeneratorDefinitionArgInterface
{
	public function __construct(
		private readonly string $prefix = 'tok',
		private readonly string $suffix = 'generated',
	) {
	}

	public function getDefinitionArg(): mixed
	{
		return [$this->prefix, $this->suffix];
	}

	public function generate(GenerationContext $context): mixed
	{
		return $this->prefix . '-' . $this->suffix;
	}
}
