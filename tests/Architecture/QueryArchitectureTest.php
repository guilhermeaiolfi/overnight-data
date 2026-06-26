<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Result\Parser\AbstractNode;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;

final class QueryArchitectureTest extends TestCase
{
	public function testQueryNamespaceRemainsDatabaseIndependent(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Query';
		$forbiddenPatterns = [
			'Cycle\\',
			'Doctrine\\',
			'PDO',
		];

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = strtolower((string) file_get_contents($file->getPathname()));

			foreach ($forbiddenPatterns as $pattern) {
				self::assertStringNotContainsString(
					strtolower($pattern),
					$contents,
					sprintf('Forbidden query-layer pattern "%s" found in %s', $pattern, $file->getPathname()),
				);
			}
		}
	}

	public function testNeutralDatabaseSurfaceDoesNotExposeCycleNamespacesOutsideBackendFolder(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Database';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$normalizedPath = str_replace('\\', '/', $file->getPathname());

			if (
				str_contains($normalizedPath, '/src/Database/Cycle/')
				|| str_ends_with($normalizedPath, '/src/Database/Database.php')
			) {
				continue;
			}

			$contents = (string) file_get_contents($file->getPathname());

			self::assertStringNotContainsString(
				'Cycle\\',
				$contents,
				sprintf('Neutral database surface leaked Cycle namespace in %s', $file->getPathname()),
			);
		}
	}

	public function testCollectionInterfaceKeepsTypedBuiltInRelationConvenienceApi(): void
	{
		self::assertSame(HasManyRelation::class, $this->methodReturnType(CollectionInterface::class, 'hasMany'));
		self::assertSame(HasOneRelation::class, $this->methodReturnType(CollectionInterface::class, 'hasOne'));
		self::assertSame(BelongsToRelation::class, $this->methodReturnType(CollectionInterface::class, 'belongsTo'));
	}

	public function testBuiltInRelationTypesHelperDoesNotExist(): void
	{
		self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/Definition/Relation/BuiltInRelationTypes.php');
	}

	public function testLoadRuntimeRegisterAcceptsOnlyAbstractParserNodes(): void
	{
		$reflection = new ReflectionMethod(LoadRuntime::class, 'register');
		$parameters = $reflection->getParameters();

		self::assertCount(1, $parameters);
		self::assertInstanceOf(ReflectionNamedType::class, $parameters[0]->getType());
		self::assertSame(AbstractNode::class, $parameters[0]->getType()->getName());
	}

	public function testQueryAndDatabaseInfrastructureDoesNotInterpretConcreteRelationExecutionSemantics(): void
	{
		$root = dirname(__DIR__, 2);

		$this->assertForbiddenStringsAbsent(
			[
				$root . '/src/Query',
				$root . '/src/Database',
			],
			[
				'/src/Query/Relation/Loader/',
			],
			[
				'HasOneRelation',
				'HasManyRelation',
				'BelongsToRelation',
				'M2MRelation',
				'FirstOfManyRelation',
				'M2MThrough',
				'HasOneLoader',
				'HasManyLoader',
				'BelongsToLoader',
				'M2MLoader',
				'FirstOfManyLoader',
				'->getCardinality(',
				'->isJunction(',
				'->innerKeys(',
				'->outerKeys(',
				'->getWhere(',
				'->getOrderBy(',
				'->getThrough(',
				'->throughInnerKeys(',
				'->throughOuterKeys(',
			],
			'Query/backend infrastructure leaked relation-specific coupling "%s" into %s',
		);
	}

	public function testNeutralDefinitionInfrastructureDoesNotInterpretRelationExecutionSemantics(): void
	{
		$root = dirname(__DIR__, 2);

		$this->assertForbiddenStringsAbsent(
			[
				$root . '/src/Definition',
			],
			[
				'/src/Definition/Relation/',
			],
			[
				'->getCardinality(',
				'->isJunction(',
				'->innerKeys(',
				'->outerKeys(',
				'->getWhere(',
				'->getOrderBy(',
				'->getThrough(',
				'->throughInnerKeys(',
				'->throughOuterKeys(',
			],
			'Neutral definition infrastructure leaked relation execution semantics "%s" into %s',
		);
	}

	/**
	 * @param list<string> $targets
	 * @return list<string>
	 */
	private function phpFiles(array $targets): array
	{
		$files = [];

		foreach ($targets as $target) {
			if (is_file($target)) {
				$files[] = $target;

				continue;
			}

			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target));

			foreach ($iterator as $file) {
				/** @var SplFileInfo $file */
				if (! $file->isFile() || $file->getExtension() !== 'php') {
					continue;
				}

				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * @param list<string> $targets
	 * @param list<string> $allowedPaths
	 */
	private function assertForbiddenStringsAbsent(
		array $targets,
		array $allowedPaths,
		array $forbiddenStrings,
		string $message,
	): void {
		foreach ($this->phpFiles($targets) as $path) {
			$normalizedPath = str_replace('\\', '/', $path);

			if ($this->isAllowedRelationOwnershipPath($normalizedPath, $allowedPaths)) {
				continue;
			}

			$contents = (string) file_get_contents($path);

			foreach ($forbiddenStrings as $forbidden) {
				self::assertStringNotContainsString(
					$forbidden,
					$contents,
					sprintf($message, $forbidden, $normalizedPath),
				);
			}
		}
	}

	/**
	 * @param list<string> $allowedPaths
	 */
	private function isAllowedRelationOwnershipPath(string $path, array $allowedPaths): bool
	{
		foreach ($allowedPaths as $allowedPath) {
			if (str_contains($path, $allowedPath)) {
				return true;
			}
		}

		return false;
	}

	private function methodReturnType(string $class, string $method): string
	{
		$reflection = new ReflectionMethod($class, $method);
		$type = $reflection->getReturnType();

		self::assertNotNull($type, sprintf('%s::%s() should declare a return type.', $class, $method));

		return $type->getName();
	}
}
