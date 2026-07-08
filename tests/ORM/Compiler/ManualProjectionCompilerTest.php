<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Compiler\ManualProjection\ProjectionCompiler;
use ON\Data\ORM\Compiler\ManualProjection\PropertySource;
use ON\Data\ORM\Compiler\ManualProjection\RootTarget;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\TestCase;

final class ManualProjectionCompilerTest extends TestCase
{
	public function testResolvesRootCollectionFromFirstPropertySource(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$root = new RootTarget(RecordState::new($users));

		$schema = (new ProjectionCompiler())->compile([
			new ProjectionFieldShape('name', $root, 'name'),
		]);

		self::assertSame('users', $schema->getCollectionName());
	}

	public function testManualFieldsAreSkipWhenMissing(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$root = new RootTarget(RecordState::new($users));

		$schema = (new ProjectionCompiler())->compile([
			new ProjectionFieldShape('name', $root, 'name'),
		]);

		self::assertTrue($schema->getField('name')->shouldSkipWhenMissing());
	}

	public function testPreservesSourcePathForRelationSourcedManualFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$root = new RootTarget(RecordState::new($users));
		$manager = $this->relationSource(RecordState::new($users), ['manager']);

		$schema = (new ProjectionCompiler())->compile([
			new ProjectionFieldShape('name', $root, 'name'),
			new ProjectionFieldShape('managerName', $manager, 'name'),
		]);

		self::assertSame([], $schema->getField('name')->getSourcePath());
		self::assertSame('users', $schema->getField('name')->getCollectionName());
		self::assertSame(['manager'], $schema->getField('managerName')->getSourcePath());
		self::assertSame('users', $schema->getField('managerName')->getCollectionName());
		self::assertSame('name', $schema->getField('managerName')->getFieldName());
		self::assertTrue($schema->getField('managerName')->shouldSkipWhenMissing());
	}

	public function testEmptyPropertyShapesUsesFallbackCollection(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');

		$schema = (new ProjectionCompiler())->compile([], $users);

		self::assertSame('users', $schema->getCollectionName());
		self::assertSame([], $schema->getPaths());
	}

	public function testEmptyPropertyShapesWithoutFallbackThrows(): void
	{
		$this->expectException(StateException::class);

		(new ProjectionCompiler())->compile([]);
	}

	private function relationSource(RecordState $record, array $relationPath): PropertySource
	{
		return new class ($record, $relationPath) implements PropertySource {
			/**
			 * @param list<string> $relationPath
			 */
			public function __construct(
				private RecordState $record,
				private array $relationPath,
			) {
			}

			public function getTargetRecord(): RecordState
			{
				return $this->record;
			}

			/**
			 * @return list<string>
			 */
			public function getRelationPath(): array
			{
				return $this->relationPath;
			}
		};
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		return $registry;
	}
}
