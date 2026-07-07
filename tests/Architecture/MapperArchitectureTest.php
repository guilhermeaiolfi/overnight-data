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

final class MapperArchitectureTest extends TestCase
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
			$normalizedPath = str_replace('\\', '/', $file->getPathname());

			foreach ($forbiddenPatterns as $pattern) {
				if (
					$pattern === 'Cycle\\'
					&& (
						str_contains($normalizedPath, '/src/Database/Cycle/')
						|| str_ends_with($normalizedPath, '/src/Database/DataRuntime.php')
					)
				) {
					continue;
				}

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

	public function testCanonicalMapperApisExist(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Mapper';
		$requiredFiles = [
			'/MapperManager.php',
			'/MapBuilder.php',
			'/MappingBranch.php',
			'/Writer/WriterStateInterface.php',
			'/functions.php',
			'/FieldConversionCoordinator.php',
			'/Mapper/MapperInterface.php',
			'/Mapper/ArrayMapper.php',
			'/Mapper/ObjectMapper.php',
			'/Writer/WriterInterface.php',
			'/Writer/ArrayWriter.php',
			'/Writer/ObjectWriter.php',
			'/Resolver/NodeResolverInterface.php',
			'/Resolver/ReflectionPropertyNodeResolver.php',
			'/Support/ObjectPropertyMatcher.php',
		];

		foreach ($requiredFiles as $suffix) {
			self::assertFileExists($root . $suffix);
		}
	}

	public function testPairSpecificMapperFilesWereRemoved(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Mapper';

		foreach (
			[
				'/MapperInterface.php',
				'/Mapper.php',
				'/ArrayToStdClassMapper.php',
				'/StdClassToArrayMapper.php',
				'/ArrayToObjectMapper.php',
				'/ObjectToArrayMapper.php',
				'/LeafNodeResolutionResolverInterface.php',
				'/ReflectionPropertyLeafNodeResolutionResolver.php',
			] as $suffix
		) {
			self::assertFileDoesNotExist($root . $suffix);
		}
	}

	public function testMappingBranchKeepsMapperAndWriterBoundaries(): void
	{
		$contents = $this->requireFileContents(dirname(__DIR__, 2) . '/src/Mapper/MappingBranch.php');

		foreach (
			[
				'ObjectWriter',
				'ArrayMapper',
				'ObjectMapper',
				'ArrayMapperOptions',
				'ArrayPathExpander',
				'Hidden',
				'ReflectionObject',
				'ReflectionProperty',
				'stdClass',
			] as $forbidden
		) {
			self::assertStringNotContainsString($forbidden, $contents);
		}
	}

	public function testMappingRuntimeDoesNotUseMapperTypeChecksForConstructorHydration(): void
	{
		$contents = $this->requireFileContents(dirname(__DIR__, 2) . '/src/Mapper/MappingRuntime.php');

		self::assertStringNotContainsString('ArrayMapper', $contents);
		self::assertStringNotContainsString('ObjectMapper', $contents);
	}

	private function requireFileContents(string $path): string
	{
		$contents = file_get_contents($path);
		self::assertIsString($contents);

		return $contents;
	}
}
