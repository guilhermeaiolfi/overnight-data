<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Phase34BindingModelTest extends TestCase
{
	public function testRepresentationBindingDocumentationNamesTheModelBoundaries(): void
	{
		$contents = file_get_contents(dirname(__DIR__, 3) . '/docs/orm/representation-binding.md');

		self::assertIsString($contents);
		self::assertStringContainsString('Definition Tree', $contents);
		self::assertStringContainsString('Query Graph / Selection Graph', $contents);
		self::assertStringContainsString('`map($source)->to(...)`', $contents);
		self::assertStringContainsString('The mapper does not by itself know persistence provenance.', $contents);
		self::assertStringContainsString('RepresentationBinding', $contents);
		self::assertStringContainsString('RepresentationState', $contents);
		self::assertStringContainsString('ToManyRelationState / ToOneRelationState', $contents);
		self::assertStringContainsString('field bindings', $contents);
		self::assertStringContainsString('expression bindings', $contents);
		self::assertStringContainsString('relation bindings', $contents);
		self::assertStringContainsString('getRelatedBinding()', $contents);
		self::assertStringContainsString('Do not create one binding object per child instance.', $contents);
		self::assertStringContainsString('Scalar representation sync uses field bindings only', $contents);
	}

	public function testNoSeparateBindingOrPersistenceGraphClassesWereIntroduced(): void
	{
		$forbidden = [
			'BindingNode',
			'BindingGraph',
			'PersistenceGraph',
			'QueryGraph',
			'BindingTree',
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

	public function testRepresentationBindingIsNotCoupledToMapperHydrationApi(): void
	{
		foreach ($this->phpFiles(dirname(__DIR__, 3) . '/src/ORM/State') as $path) {
			$contents = file_get_contents($path);
			self::assertIsString($contents);
			self::assertStringNotContainsString('ON\\Data\\Mapper', $contents, $path);
			self::assertStringNotContainsString('map(', $contents, $path);
		}
	}

	public function testScalarSyncStillUsesExplicitFieldBindingsOnly(): void
	{
		$sources = [
			dirname(__DIR__, 3) . '/src/ORM/Sync/RepresentationReader.php' => 'getFields()',
			dirname(__DIR__, 3) . '/src/ORM/Sync/SyncConflictDetector.php' => 'getWritableFieldBindings()',
			dirname(__DIR__, 3) . '/src/ORM/Sync/ScalarRepresentationSynchronizer.php' => 'getWritableFieldBindings()',
		];

		foreach ($sources as $path => $expectedCall) {
			$contents = file_get_contents($path);
			self::assertIsString($contents);
			self::assertStringContainsString($expectedCall, $contents, $path);
			self::assertStringNotContainsString('getRelations()', $contents, $path);
			self::assertStringNotContainsString('getExpressions()', $contents, $path);
		}
	}

	public function testSelectQueryWasNotCoupledToOrmPersistence(): void
	{
		$contents = file_get_contents(dirname(__DIR__, 3) . '/src/Query/SelectQuery.php');

		self::assertIsString($contents);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $contents);
		self::assertStringNotContainsString('RepresentationBinding', $contents);
		self::assertStringNotContainsString('RecordState', $contents);
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
