<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Representation\Schema;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSchemaAssembler;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSourceResolverInterface;
use ON\Data\ORM\Representation\Schema\Shape\ResolvedRepresentationSource;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RepresentationSchemaAssemblerTest extends TestCase
{
	public function testAssemblesFieldShapesWithResolvedSourceProperties(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');

		$rootSource = new stdClass();
		$companySource = new stdClass();
		$resolver = $this->resolver($rootSource, $users, $companySource, $companies, ['company']);

		$assembler = new RepresentationSchemaAssembler();
		$schema = $assembler->assemble(
			[
				new RepresentationFieldShape('name', $rootSource, 'name'),
				new RepresentationFieldShape('companyName', $companySource, 'name'),
			],
			$resolver,
			$users,
			skipWhenMissing: true,
		);

		self::assertSame(['name', 'companyName'], $schema->getPaths());

		$name = $schema->getField('name');
		self::assertSame('name', $name->getPath());
		self::assertSame([], $name->getSourcePath());
		self::assertSame('users', $name->getCollectionName());
		self::assertSame('name', $name->getFieldName());
		self::assertTrue($name->isWritable());
		self::assertTrue($name->shouldSkipWhenMissing());

		$companyName = $schema->getField('companyName');
		self::assertSame('companyName', $companyName->getPath());
		self::assertSame(['company'], $companyName->getSourcePath());
		self::assertSame('companies', $companyName->getCollectionName());
		self::assertSame('name', $companyName->getFieldName());
		self::assertTrue($companyName->isWritable());
		self::assertTrue($companyName->shouldSkipWhenMissing());
	}

	public function testAssembleIntoDefaultsToNonSkippingSchemas(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$rootSource = new stdClass();
		$resolver = $this->resolver($rootSource, $users, new stdClass(), $users, []);

		$schema = (new RepresentationSchemaAssembler())->assemble(
			[new RepresentationFieldShape('name', $rootSource, 'name')],
			$resolver,
			$users,
		);

		self::assertFalse($schema->getField('name')->shouldSkipWhenMissing());
	}

	public function testDefaultFieldShapesCoverEveryCollectionFieldAsWritableRootSource(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$source = new stdClass();
		$assembler = new RepresentationSchemaAssembler();

		$shapes = $assembler->defaultFieldShapes($users, $source);

		$publicPaths = array_map(
			static fn (RepresentationFieldShape $shape): string => $shape->getPublicPath(),
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
		$assembler = new RepresentationSchemaAssembler();

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
		$assembler = new RepresentationSchemaAssembler();

		$schema = $assembler->assemble(
			[new RepresentationFieldShape('name', $source, 'name', writable: true)],
			$resolver,
			$users,
		);
		$assembler->assembleInto(
			$schema,
			[new RepresentationFieldShape('name', $source, 'name', writable: false)],
			$resolver,
		);

		self::assertTrue($schema->getField('name')->isWritable());
	}

	public function testResolvedRepresentationSourceIsStructuralOnly(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$source = new ResolvedRepresentationSource($users, ['manager']);

		self::assertSame($users, $source->getCollection());
		self::assertSame(['manager'], $source->getSourcePath());
		self::assertFalse(method_exists($source, 'getRecordState'));
	}

	public function testRepresentationSourcesGroupFieldsBySourcePath(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');

		$rootSource = new stdClass();
		$companySource = new stdClass();
		$schema = (new RepresentationSchemaAssembler())->assemble(
			[
				new RepresentationFieldShape('name', $rootSource, 'name'),
				new RepresentationFieldShape('companyName', $companySource, 'name'),
				new RepresentationFieldShape('companyId', $companySource, 'id'),
			],
			$this->resolver($rootSource, $users, $companySource, $companies, ['company']),
			$users,
		);

		$sources = RepresentationSource::fromRepresentationSchema($schema);

		self::assertCount(2, $sources);
		self::assertSame([], $sources[0]->getPath());
		self::assertSame(['company'], $sources[1]->getPath());
		self::assertSame('companies', $sources[1]->getCollection()->getName());
		self::assertTrue($sources[1]->hasField('name'));
		self::assertFalse($sources[1]->hasField('companyName'));
		self::assertSame('companyName', $sources[1]->getFieldPath('name'));
		self::assertSame('companyId', $sources[1]->getFieldPath('id'));
	}

	private function resolver(
		object $rootSource,
		CollectionInterface $rootCollection,
		object $relationSource,
		CollectionInterface $relationCollection,
		array $relationSourcePath,
	): RepresentationSourceResolverInterface {
		return new class ($rootSource, $rootCollection, $relationSource, $relationCollection, $relationSourcePath) implements RepresentationSourceResolverInterface {
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

			public function resolve(object $source): ResolvedRepresentationSource
			{
				if ($source === $this->relationSource) {
					return new ResolvedRepresentationSource($this->relationCollection, sourcePath: $this->relationSourcePath);
				}

				return new ResolvedRepresentationSource($this->rootCollection, sourcePath: []);
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
