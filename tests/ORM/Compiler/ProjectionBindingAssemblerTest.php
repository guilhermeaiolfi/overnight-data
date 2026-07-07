<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Compiler\ProjectionBindingAssembler;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Compiler\ProjectionSourceResolverInterface;
use ON\Data\ORM\Compiler\ResolvedProjectionSource;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ProjectionBindingAssemblerTest extends TestCase
{
	public function testAssemblesFieldShapesWithResolvedSourceProperties(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');

		$rootSource = new stdClass();
		$companySource = new stdClass();
		$resolver = $this->resolver($rootSource, $users, $companySource, $companies, ['company']);

		$assembler = new ProjectionBindingAssembler();
		$binding = $assembler->assemble(
			[
				new ProjectionFieldShape('name', $rootSource, 'name'),
				new ProjectionFieldShape('companyName', $companySource, 'name'),
			],
			$resolver,
			$users,
			skipWhenMissing: true,
		);

		self::assertSame(['name', 'companyName'], $binding->getPaths());

		$name = $binding->getField('name');
		self::assertSame('name', $name->getPath());
		self::assertSame([], $name->getSourcePath());
		self::assertSame('users', $name->getCollectionName());
		self::assertSame('name', $name->getFieldName());
		self::assertTrue($name->isWritable());
		self::assertTrue($name->shouldSkipWhenMissing());

		$companyName = $binding->getField('companyName');
		self::assertSame('companyName', $companyName->getPath());
		self::assertSame(['company'], $companyName->getSourcePath());
		self::assertSame('companies', $companyName->getCollectionName());
		self::assertSame('name', $companyName->getFieldName());
		self::assertTrue($companyName->isWritable());
		self::assertTrue($companyName->shouldSkipWhenMissing());
	}

	public function testAssembleIntoDefaultsToNonSkippingBindings(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$rootSource = new stdClass();
		$resolver = $this->resolver($rootSource, $users, new stdClass(), $users, []);

		$binding = (new ProjectionBindingAssembler())->assemble(
			[new ProjectionFieldShape('name', $rootSource, 'name')],
			$resolver,
			$users,
		);

		self::assertFalse($binding->getField('name')->shouldSkipWhenMissing());
	}

	public function testDefaultFieldShapesCoverEveryCollectionFieldAsWritableRootSource(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$source = new stdClass();
		$assembler = new ProjectionBindingAssembler();

		$shapes = $assembler->defaultFieldShapes($users, $source);

		$publicPaths = array_map(
			static fn (ProjectionFieldShape $shape): string => $shape->getPublicPath(),
			$shapes,
		);
		sort($publicPaths);
		self::assertSame(['company_id', 'id', 'name'], $publicPaths);

		foreach ($shapes as $shape) {
			self::assertSame($source, $shape->getSource());
			self::assertSame($shape->getPublicPath(), $shape->getFieldName());
			self::assertTrue($shape->isWritable());
		}
	}

	public function testPrimaryKeyFieldShapesAreReadOnlyByDefault(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$source = new stdClass();
		$assembler = new ProjectionBindingAssembler();

		$shapes = $assembler->primaryKeyFieldShapes($users, $source);

		self::assertCount(1, $shapes);
		self::assertSame('id', $shapes[0]->getPublicPath());
		self::assertSame('id', $shapes[0]->getFieldName());
		self::assertFalse($shapes[0]->isWritable());
		self::assertTrue($assembler->primaryKeyFieldShapes($users, $source, writable: true)[0]->isWritable());
	}

	public function testAssembleIntoSkipsPathsAlreadyBound(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$source = new stdClass();
		$resolver = $this->resolver($source, $users, new stdClass(), $users, []);
		$assembler = new ProjectionBindingAssembler();

		$binding = $assembler->assemble(
			[new ProjectionFieldShape('name', $source, 'name', writable: true)],
			$resolver,
			$users,
		);
		$assembler->assembleInto(
			$binding,
			[new ProjectionFieldShape('name', $source, 'name', writable: false)],
			$resolver,
		);

		self::assertTrue($binding->getField('name')->isWritable());
	}

	private function resolver(
		object $rootSource,
		CollectionInterface $rootCollection,
		object $relationSource,
		CollectionInterface $relationCollection,
		array $relationSourcePath,
	): ProjectionSourceResolverInterface {
		return new class($rootSource, $rootCollection, $relationSource, $relationCollection, $relationSourcePath) implements ProjectionSourceResolverInterface {
			/**
			 * @param list<string> $relationSourcePath
			 */
			public function __construct(
				private object $rootSource,
				private CollectionInterface $rootCollection,
				private object $relationSource,
				private CollectionInterface $relationCollection,
				private array $relationSourcePath,
			) {
			}

			public function resolve(object $source): ResolvedProjectionSource
			{
				if ($source === $this->relationSource) {
					return new ResolvedProjectionSource($this->relationCollection, sourcePath: $this->relationSourcePath);
				}

				return new ResolvedProjectionSource($this->rootCollection, sourcePath: []);
			}
		};
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('company_id', 'int')->end();

		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		return $registry;
	}
}
