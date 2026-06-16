<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapping;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class MapperPhase2ArchitectureTest extends TestCase
{
	public function testProductionCodeDoesNotReferenceExpandedForbiddenNamespaces(): void
	{
		$root = dirname(__DIR__, 2) . '/src';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		$forbiddenPatterns = [
			'Cycle\\',
			'Doctrine\\',
			'ON\\Application',
			'ON\\Container',
			'ON\\ORM\\',
			'ON\\RestApi\\',
			'Psr\\Container',
			'Psr\\Http',
		];

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = file_get_contents($file->getPathname());
			self::assertNotFalse($contents);

			foreach ($forbiddenPatterns as $pattern) {
				self::assertStringNotContainsString(
					$pattern,
					$contents,
					sprintf('Forbidden namespace "%s" found in %s', $pattern, $file->getPathname()),
				);
			}
		}
	}

	public function testConversionGatewayDoesNotExposeStaticSingletonLifecycle(): void
	{
		$reflection = new ReflectionClass(ConversionGateway::class);

		foreach (['get', 'setInstance', 'configure', 'tryContainer'] as $method) {
			self::assertFalse($reflection->hasMethod($method), sprintf('ConversionGateway still exposes %s().', $method));
		}
	}

	public function testMappingIsTheOnlyAmbientDefaultHolder(): void
	{
		$gatewayReflection = new ReflectionClass(ConversionGateway::class);
		self::assertFalse($gatewayReflection->hasProperty('defaultGateway'));

		$mappingReflection = new ReflectionClass(Mapping::class);
		self::assertTrue($mappingReflection->hasProperty('defaultGateway'));
	}

	public function testPhaseTwoApisNowExist(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Mapper';
		$requiredFiles = [
			'/MapperInterface.php',
			'/Mapper.php',
			'/MapperManager.php',
			'/MapBuilder.php',
			'/MappingContext.php',
			'/functions.php',
		];

		foreach ($requiredFiles as $suffix) {
			self::assertFileExists($root . $suffix);
		}
	}
}
