<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RepresentationArchitectureTest extends TestCase
{
	public function testRepresentationSchemaDocumentationNamesTheModelBoundaries(): void
	{
		$contents = file_get_contents(dirname(__DIR__, 3) . '/docs/orm/representation-schema.md');

		self::assertIsString($contents);
		self::assertStringContainsString('Definition Tree', $contents);
		self::assertStringContainsString('Query Graph / Selection Graph', $contents);
		self::assertStringContainsString('`map($source)->to(...)`', $contents);
		self::assertStringContainsString('The mapper does not by itself know persistence provenance.', $contents);
		self::assertStringContainsString('RepresentationSchema', $contents);
		self::assertStringContainsString('RepresentationState', $contents);
		self::assertStringContainsString('ToManyRelationState / ToOneRelationState', $contents);
		self::assertStringContainsString('field schemas', $contents);
		self::assertStringContainsString('relation schemas', $contents);
		self::assertStringContainsString('It owns two path maps', $contents);
		self::assertStringContainsString('getRelatedSchema()', $contents);
		self::assertStringContainsString('Do not create one schema object per child instance.', $contents);
		self::assertStringContainsString('Scalar representation sync uses field schemas only', $contents);
	}

	public function testNoSeparateBindingOrPersistenceGraphClassesWereIntroduced(): void
	{
		$forbidden = [
			'BindingNode',
			'BindingGraph',
			'PersistenceGraph',
			'QueryGraph',
			'BindingTree',
			'RepresentationBinding',
			'RepresentationFieldBinding',
			'RepresentationRelationBinding',
			'RepresentationBindingMerger',
			'RepresentationBindingAssembler',
			'RepresentationRelationCardinality',
			'RepresentationStore',
			'ProjectionRepresentationAdopter',
			'GraphAdopter',
			'RepresentationAdopter',
		];

		foreach ($this->phpFiles(dirname(__DIR__, 3) . '/src') as $path) {
			$contents = file_get_contents($path);
			self::assertIsString($contents);

			foreach ($forbidden as $name) {
				self::assertStringNotContainsString('class ' . $name, $contents, $path);
				self::assertStringNotContainsString('interface ' . $name, $contents, $path);
				self::assertStringNotContainsString('enum ' . $name, $contents, $path);
			}
		}
	}

	public function testLegacyBindingDocumentationWasRemoved(): void
	{
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/docs/orm/representation-binding.md');
	}

	public function testRepresentationSchemaIsNotCoupledToMapperHydrationApi(): void
	{
		foreach ([
			dirname(__DIR__, 3) . '/src/ORM/Representation/Schema',
			dirname(__DIR__, 3) . '/src/ORM/Representation/State',
		] as $root) {
			foreach ($this->phpFiles($root) as $path) {
				$contents = file_get_contents($path);
				self::assertIsString($contents);
				self::assertStringNotContainsString('ON\\Data\\Mapper', $contents, $path);
				self::assertStringNotContainsString('map(', $contents, $path);
			}
		}
	}

	public function testScalarSyncStillUsesExplicitFieldSchemasOnly(): void
	{
		$sources = [
			dirname(__DIR__, 3) . '/src/ORM/Representation/Sync/RepresentationReader.php' => 'getFields()',
			dirname(__DIR__, 3) . '/src/ORM/Representation/Sync/SyncConflictDetector.php' => 'getWritableFieldItems()',
			dirname(__DIR__, 3) . '/src/ORM/Representation/Sync/ScalarRepresentationSynchronizer.php' => 'getWritableFieldItems()',
		];

		foreach ($sources as $path => $expectedCall) {
			$contents = file_get_contents($path);
			self::assertIsString($contents);
			self::assertStringContainsString($expectedCall, $contents, $path);
			self::assertStringNotContainsString('getRelations()', $contents, $path);
			self::assertStringNotContainsString('getExpressions()', $contents, $path);
		}
	}

	public function testSelectQueryMutableExportMayCompileSchemasButAvoidsPersistenceCoupling(): void
	{
		$contents = file_get_contents(dirname(__DIR__, 3) . '/src/Query/SelectQuery.php');

		self::assertIsString($contents);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $contents);
		self::assertStringNotContainsString('RecordState', $contents);
		self::assertStringContainsString('QueryRepresentationSchemaCompiler', $contents);
		self::assertStringContainsString('function projection(', $contents);
		self::assertStringContainsString('MutableResultHandler', $contents);
	}

	/**
	 * @return list<string>
	 */
	private function phpFiles(string $root): array
	{
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($iterator as $file) {
			if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$files[] = $file->getPathname();
		}

		return $files;
	}
}
