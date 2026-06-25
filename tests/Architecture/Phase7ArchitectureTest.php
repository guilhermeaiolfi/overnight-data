<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Definition\Registry;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class Phase7ArchitectureTest extends TestCase
{
	public function testRegistryNoLongerContainsRecursiveNormalizationMethods(): void
	{
		$reflection = new ReflectionClass(Registry::class);

		foreach (
			[
				'normalizeDefinitions',
				'normalizeCollectionDefinitions',
				'normalizeViewDefinitions',
				'normalizeFields',
				'normalizeRelations',
				'normalizeNestedDisplay',
				'normalizeNestedInterface',
				'normalizePlainArray',
				'normalizePrimaryKey',
				'exportCollection',
			] as $method
		) {
			self::assertFalse($reflection->hasMethod($method), sprintf('Registry still exposes %s().', $method));
		}
	}

	public function testDefinitionFactoryNoLongerContainsNormalizationHelpersOrThroughMethod(): void
	{
		$reflection = new ReflectionClass(DefinitionFactory::class);

		foreach (
			[
				'materializeDefinitionArray',
				'normalizeStoredClass',
				'node',
				'export',
				'through',
			] as $method
		) {
			self::assertFalse($reflection->hasMethod($method), sprintf('DefinitionFactory still exposes %s().', $method));
		}

		self::assertTrue($reflection->hasMethod('requireStoredClass'));
		self::assertTrue($reflection->hasMethod('create'));
		self::assertTrue($reflection->hasMethod('restore'));
	}

	public function testGenericInfrastructureNoLongerHardCodesConcreteFieldRelationOrThroughTypes(): void
	{
		$checks = [
			'src/Definition/Registry.php' => [
				'Field',
				'ViewField',
				'RawDisplay',
				'InterfaceInterface',
				'M2MThrough',
			],
			'src/Definition/Field/FieldMap.php' => [
				'instanceof Field',
			],
			'src/Definition/Relation/RelationMap.php' => [
				'instanceof AbstractRelation',
			],
		];

		$root = dirname(__DIR__, 2);

		foreach ($checks as $relativePath => $forbiddenStrings) {
			$contents = file_get_contents($root . '/' . $relativePath);
			self::assertNotFalse($contents);

			foreach ($forbiddenStrings as $forbidden) {
				self::assertStringNotContainsString(
					$forbidden,
					$contents,
					sprintf('Forbidden phase-7 coupling "%s" found in %s', $forbidden, $relativePath),
				);
			}
		}
	}

	public function testSelectQueryExposesCanonicalCollectionApiWithoutLegacySourceGetter(): void
	{
		$reflection = new ReflectionClass(SelectQuery::class);

		self::assertTrue($reflection->hasMethod('getCollection'));
		self::assertTrue($reflection->hasMethod('getQuery'));
		self::assertTrue($reflection->implementsInterface(QuerySourceInterface::class));
		self::assertFalse($reflection->hasMethod('getSource'));
	}

	public function testFieldRefUsesCanonicalSourceApiWithoutLegacyRelationGetter(): void
	{
		$reflection = new ReflectionClass(FieldRef::class);

		self::assertTrue($reflection->hasMethod('getSource'));
		self::assertFalse($reflection->hasMethod('getRelation'));
	}

	public function testRelationInterfaceRequiresConcreteLoaderClassStrings(): void
	{
		$reflection = new ReflectionClass(LoaderInterface::class);

		self::assertTrue($reflection->hasMethod('join'));
		self::assertTrue($reflection->hasMethod('load'));
	}
}
