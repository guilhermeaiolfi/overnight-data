<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Relation\LoadBranch;
use ON\Data\Query\Relation\Loader\AbstractLoader;
use ON\Data\Query\Relation\Loader\BelongsToLoader;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\Relation\Loader\HasOneLoader;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationOutputProcessor;
use ON\Data\Query\Relation\RootLoadBranch;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
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

	public function testLoaderRegisterReturnsAnAbstractParserNode(): void
	{
		$reflection = new ReflectionMethod(LoaderInterface::class, 'register');
		$parameters = $reflection->getParameters();

		self::assertCount(2, $parameters);
		self::assertSame(RelationLoadBranch::class, $parameters[0]->getType()?->getName());
		self::assertSame(LoadRuntime::class, $parameters[1]->getType()?->getName());
		self::assertInstanceOf(ReflectionNamedType::class, $reflection->getReturnType());
		self::assertSame(AbstractNode::class, $reflection->getReturnType()->getName());
	}

	public function testLoaderLoadReceivesRelationLoadBranch(): void
	{
		$reflection = new ReflectionMethod(LoaderInterface::class, 'load');
		$parameters = $reflection->getParameters();

		self::assertCount(2, $parameters);
		self::assertSame(RelationLoadBranch::class, $parameters[0]->getType()?->getName());
		self::assertSame(LoadRuntime::class, $parameters[1]->getType()?->getName());
	}

	public function testAbstractLoaderProvidesFinalRegisterReturningAbstractNode(): void
	{
		$reflection = new ReflectionMethod(AbstractLoader::class, 'register');

		self::assertTrue($reflection->isFinal());
		self::assertInstanceOf(ReflectionNamedType::class, $reflection->getReturnType());
		self::assertSame(AbstractNode::class, $reflection->getReturnType()->getName());
	}

	public function testBuiltInLoadersImplementInitNodeInsteadOfRegister(): void
	{
		foreach ([BelongsToLoader::class, HasOneLoader::class, HasManyLoader::class] as $class) {
			self::assertSame(AbstractLoader::class, (new ReflectionMethod($class, 'register'))->getDeclaringClass()->getName());
			self::assertSame($class, (new ReflectionMethod($class, 'initNode'))->getDeclaringClass()->getName());
		}
	}

	public function testLoaderInterfaceDoesNotExposeCollectFieldsHook(): void
	{
		self::assertFalse(method_exists(LoaderInterface::class, 'collectFields'));
	}

	public function testOwnedBranchPlanAndRelatedRuntimeApisDoNotExist(): void
	{
		self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/Query/Relation/OwnedBranchPlan.php');
		self::assertFalse(method_exists(LoadBranch::class, 'ownedPlan'));
		self::assertFalse(method_exists(LoadBranch::class, 'getOwnedPlans'));
		self::assertFalse(method_exists(LoadRuntime::class, 'requireBranchSourceFields'));
		self::assertFalse(method_exists(LoadRuntime::class, 'createInternalBranch'));
	}

	public function testLoadBranchDoesNotExposePublicChildAttachment(): void
	{
		$reflection = new ReflectionMethod(LoadBranch::class, 'addChild');

		self::assertFalse($reflection->isPublic());
	}

	public function testRelationLoadBranchRequiresNonNullableParentSelectionAndLoader(): void
	{
		self::assertSame(LoadBranch::class, $this->methodReturnType(RelationLoadBranch::class, 'getParent'));
		self::assertSame('ON\Data\Query\Relation\RelationSelection', $this->methodReturnType(RelationLoadBranch::class, 'getSelection'));
		self::assertSame(LoaderInterface::class, $this->methodReturnType(RelationLoadBranch::class, 'getLoader'));
		self::assertSame('ON\Data\Query\Relation\RelationRef', $this->methodReturnType(RelationLoadBranch::class, 'getRelationRef'));
		self::assertSame('ON\Data\Query\Relation\RelationRef', $this->methodReturnType('ON\Data\Query\Relation\RelationSelection', 'getRelationRef'));
		self::assertSame('bool', $this->methodReturnType(RelationLoadBranch::class, 'returnsMany'));
		self::assertTrue(method_exists(RelationLoadBranch::class, 'setContinuation'));
		self::assertTrue(method_exists(RelationLoadBranch::class, 'clearContinuation'));
		self::assertTrue(method_exists(RelationLoadBranch::class, 'getSelections'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'schedule'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'clearSchedule'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'getRelation'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'nodeIsCollectionLike'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'getParserFields'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'getPublicFields'));
		self::assertFalse(method_exists('ON\Data\Query\Relation\RelationSelection', 'getRelation'));
	}

	public function testRootAndRelationBranchesKeepSeparateResponsibilities(): void
	{
		self::assertFalse(method_exists(RootLoadBranch::class, 'getSelection'));
		self::assertFalse(method_exists(RootLoadBranch::class, 'getLoader'));
		self::assertFalse(method_exists(RootLoadBranch::class, 'returnsMany'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'addPublicColumn'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'getRootNode'));
		self::assertFalse(method_exists(LoadBranch::class, 'setRootFieldResolver'));
	}

	public function testRuntimeDoesNotExposeLoaderBranchIdentityApis(): void
	{
		self::assertFalse(method_exists(LoadRuntime::class, 'rootIdentityFields'));
		self::assertFalse(method_exists(LoadRuntime::class, 'rootPrimaryKeyFields'));
		self::assertFalse(method_exists(LoadRuntime::class, 'buildPlan'));
		self::assertFalse(method_exists(LoadRuntime::class, 'nextPass'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getCurrentBranch'));
		self::assertFalse(method_exists(LoadRuntime::class, 'requireParentBranch'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getParserFields'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getNode'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getParentNode'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getQuery'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getSource'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getReferenceValues'));
		self::assertFalse(method_exists(LoadRuntime::class, 'setJoinedAttachment'));
		self::assertFalse(method_exists(LoadRuntime::class, 'getChildBranches'));
		self::assertFalse(method_exists(LoadRuntime::class, 'registerChildBranches'));
		self::assertTrue(method_exists(LoadRuntime::class, 'continueWith'));
	}

	public function testContinuationVocabularyDoesNotUseLegacySchedulingNames(): void
	{
		$runtimeContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/LoadRuntime.php');
		$branchContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/RelationLoadBranch.php');
		$exceptionContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Exception/LoadRuntimeException.php');

		self::assertStringNotContainsString('nextPass', $runtimeContents);
		self::assertStringNotContainsString('schedule(', $runtimeContents);
		self::assertStringNotContainsString('clearSchedule(', $runtimeContents);
		self::assertStringNotContainsString('boundaryQuery', $runtimeContents);
		self::assertStringNotContainsString('assertSchedulableMethod', $runtimeContents);
		self::assertStringNotContainsString('scheduled', $runtimeContents);

		self::assertStringNotContainsString('schedule(', $branchContents);
		self::assertStringNotContainsString('clearSchedule(', $branchContents);
		self::assertStringNotContainsString('boundaryQuery', $branchContents);

		self::assertStringNotContainsString('nextPass', $exceptionContents);
		self::assertStringNotContainsString('scheduleBoundary', $exceptionContents);
		self::assertStringNotContainsString('invalidScheduledMethod', $exceptionContents);
		self::assertStringNotContainsString('multipleNextPasses', $exceptionContents);
	}

	public function testRuntimeBranchScopedServicesReceiveRelationLoadBranchExplicitly(): void
	{
		self::assertSame(RelationLoadBranch::class, (new ReflectionMethod(LoadRuntime::class, 'setQueryContext'))->getParameters()[0]->getType()?->getName());
		self::assertSame(RelationLoadBranch::class, (new ReflectionMethod(LoadRuntime::class, 'getQueryRelation'))->getParameters()[0]->getType()?->getName());
		self::assertSame(RelationLoadBranch::class, (new ReflectionMethod(LoadRuntime::class, 'execute'))->getParameters()[0]->getType()?->getName());
		self::assertSame(RelationLoadBranch::class, (new ReflectionMethod(LoadRuntime::class, 'continueWith'))->getParameters()[0]->getType()?->getName());
	}

	public function testRuntimeDoesNotStoreActiveBranchIdentityInternally(): void
	{
		self::assertFalse(property_exists(LoadRuntime::class, 'activeBranch'));
		self::assertFalse(method_exists(LoadRuntime::class, 'requireActiveBranch'));
	}

	public function testRelationRefExposesDefinitionNaming(): void
	{
		self::assertSame('ON\Data\Definition\Relation\RelationInterface', $this->methodReturnType('ON\Data\Query\Relation\RelationRef', 'getDefinition'));
		self::assertFalse(method_exists('ON\Data\Query\Relation\RelationRef', 'getRelation'));
	}

	public function testRootBranchOwnsPrimaryKeyRequirementHelpers(): void
	{
		self::assertTrue(method_exists(LoadBranch::class, 'requirePrimaryKey'));
		self::assertTrue(method_exists(RootLoadBranch::class, 'createNode'));
		self::assertTrue(method_exists(RootLoadBranch::class, 'getSelections'));
	}

	public function testRelationOutputProcessorOwnsOutputShaping(): void
	{
		self::assertTrue(class_exists(RelationOutputProcessor::class));
		self::assertTrue(method_exists(RelationOutputProcessor::class, 'processRoot'));

		foreach ([
			'buildVisibleOutput',
			'collectHiddenOutput',
			'defaultHiddenPromotions',
			'projectPromotionItems',
			'recordIdentity',
			'promotionPath',
		] as $method) {
			self::assertFalse(method_exists(RelationLoadBranch::class, $method), $method);
		}

		self::assertFalse(method_exists(RootLoadBranch::class, 'buildOutputRecords'));
	}

	public function testNoPromotionModelClassesAreIntroduced(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Query/Relation';

		self::assertFileDoesNotExist($root . '/PromotionMap.php');
		self::assertFileDoesNotExist($root . '/PromotionEntry.php');
		self::assertFileDoesNotExist($root . '/PromotionItem.php');
	}

	public function testRootLoadBranchUsesSelectionListInsteadOfLegacyParallelArrays(): void
	{
		$reflection = new ReflectionClass(RootLoadBranch::class);
		$contents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/RootLoadBranch.php');

		self::assertTrue($reflection->hasProperty('selections'));

		foreach (['columns', 'valueAliases', 'publicColumns', 'fieldParserNames', 'identityAliases'] as $property) {
			self::assertFalse($reflection->hasProperty($property), $property);
		}

		self::assertStringContainsString('SelectionList', $contents);
	}

	public function testRootLoadBranchDoesNotExposeLegacyRootSelectionApis(): void
	{
		foreach (['addPublicColumn', 'getColumns', 'getValueAliases', 'getPublicColumns', 'asParserKey', 'asRowAlias'] as $method) {
			self::assertFalse(method_exists(RootLoadBranch::class, $method), $method);
		}
	}

	public function testProductionQueryCodeDoesNotUseRemovedSelectionConvenienceApis(): void
	{
		$this->assertForbiddenStringsAbsent(
			[dirname(__DIR__, 2) . '/src'],
			[],
			[
				'getParserItems(',
				'getPublicItems(',
				'getIdentityItems(',
				'filterForParser(',
				'isParserVisible(',
				'SelectionReason',
				'addParserProjectedFrom(',
				'addProjectedFrom(',
			],
			'Removed selection vocabulary "%s" found in %s',
		);
	}

	public function testRuntimeAndBuiltInLoadersDoNotContainCollectFieldsLifecycle(): void
	{
		foreach ([
			dirname(__DIR__, 2) . '/src/Query/Relation/LoadRuntime.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/AbstractLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/BelongsToLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasOneLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasManyLoader.php',
		] as $path) {
			self::assertStringNotContainsString('collectFields', (string) file_get_contents($path), $path);
			self::assertStringNotContainsString('collectBranchFields', (string) file_get_contents($path), $path);
			self::assertStringNotContainsString('OwnedBranchPlan', (string) file_get_contents($path), $path);
			self::assertStringNotContainsString('ownedPlan(', (string) file_get_contents($path), $path);
			self::assertStringNotContainsString('getOwnedPlans(', (string) file_get_contents($path), $path);
		}
	}

	public function testProductionLoaderLoadMethodsDoNotPerformParserAttachment(): void
	{
		foreach ([
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/BelongsToLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasOneLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasManyLoader.php',
		] as $path) {
			$contents = (string) file_get_contents($path);
			self::assertStringNotContainsString('->joinNode(', $contents, $path);
			self::assertStringNotContainsString('->linkNode(', $contents, $path);
		}
	}

	public function testBuiltInLoadersDoNotUseRuntimeBranchLookupOrOldRelationNaming(): void
	{
		foreach ([
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/BelongsToLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasOneLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasManyLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/M2MLoader.php',
		] as $path) {
			$contents = (string) file_get_contents($path);
			self::assertStringNotContainsString('->getCurrentBranch(', $contents, $path);
			self::assertStringNotContainsString('->requireParentBranch(', $contents, $path);
			self::assertStringNotContainsString('->getRelation()', $contents, $path);
			self::assertStringNotContainsString('$runtime->getParserFields(', $contents, $path);
			self::assertStringNotContainsString('$runtime->getNode(', $contents, $path);
			self::assertStringNotContainsString('$runtime->getParentNode(', $contents, $path);
			self::assertStringNotContainsString('$runtime->setJoinedAttachment(', $contents, $path);
			self::assertStringNotContainsString('$runtime->getQuery()', $contents, $path);
			self::assertStringNotContainsString('$runtime->getSource()', $contents, $path);
			self::assertStringNotContainsString('$runtime->getReferenceValues(', $contents, $path);
			self::assertStringNotContainsString('$runtime->registerChildBranches(', $contents, $path);
			self::assertStringNotContainsString('$runtime->getChildBranches(', $contents, $path);
			self::assertStringNotContainsString('getExpression()->getField()->getName()', $contents, $path);
		}
	}

	public function testRelationLoadingUsesSelectionKeysInsteadOfInspectingFieldBackedExpressions(): void
	{
		foreach ([
			dirname(__DIR__, 2) . '/src/Query/Relation/LoadRuntime.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/RelationOutputProcessor.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/BelongsToLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasOneLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasManyLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/M2MLoader.php',
		] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringContainsString('getSelectionKey()', $contents, $path);
			self::assertStringNotContainsString('getExpression()->getField()->getName()', $contents, $path);
		}
	}

	public function testExpressionSelectionKeyCodeDoesNotUseDynamicPathGuessing(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Expression/AbstractValueExpression.php');

		self::assertStringNotContainsString("method_exists(\$this, 'getPath')", $contents);
		self::assertStringContainsString('cannot provide a selection key without an alias', $contents);
	}

	public function testQueryBindingApiDoesNotExposeLegacyCompatibilityMethods(): void
	{
		$legacyFieldBindingMethod = implode('', array_map('chr', [114, 101, 98, 97, 115, 101, 70, 105, 101, 108, 100, 115]));

		foreach ([ConditionInterface::class, ValueExpressionInterface::class, FieldRef::class, Sort::class] as $class) {
			self::assertFalse(method_exists($class, $legacyFieldBindingMethod), $class);
		}

		self::assertFalse(method_exists(SelectQuery::class, 'adopt' . 'Conditions'));
		self::assertFalse(method_exists(SelectQuery::class, 'adopt' . 'Sorts'));
		self::assertTrue(method_exists(SelectQuery::class, 'bindConditions'));
		self::assertTrue(method_exists(SelectQuery::class, 'bindSorts'));
	}

	public function testQuerySourceCodeUsesOnlyBindingTerminology(): void
	{
		$legacyFieldBindingMethod = implode('', array_map('chr', [114, 101, 98, 97, 115, 101, 70, 105, 101, 108, 100, 115]));

		$this->assertForbiddenStringsAbsent(
			[dirname(__DIR__, 2) . '/src/Query'],
			[],
			[
				$legacyFieldBindingMethod,
				'adopt' . 'Conditions',
				'adopt' . 'Sorts',
				implode('', array_map('chr', [114, 101, 98, 97, 115, 101])),
				implode('', array_map('chr', [114, 101, 98, 97, 115, 105, 110, 103])),
				implode('', array_map('chr', [114, 101, 98, 97, 115, 101, 100])),
			],
			'Legacy binding vocabulary "%s" found in %s',
		);
	}

	public function testBuiltInLoadersUseRelationKeyPairingsForPairedOperations(): void
	{
		$simpleLoaderPaths = [
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/BelongsToLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasOneLoader.php',
			dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasManyLoader.php',
		];

		foreach ($simpleLoaderPaths as $path) {
			$contents = (string) file_get_contents($path);
			self::assertStringContainsString('getKeyPairing()', $contents, $path);
			self::assertStringContainsString('getRightFields()', $contents, $path);
			self::assertStringContainsString('getLeftFields()', $contents, $path);
		}

		$abstractLoaderContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/Loader/AbstractLoader.php');
		self::assertStringContainsString('RelationKeyQuery::addJoinConditions(', $abstractLoaderContents);

		$m2mContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/Loader/M2MLoader.php');
		self::assertStringContainsString('$definition->getKeyPairing()', $m2mContents);
		self::assertStringContainsString('$through->getKeyPairing()', $m2mContents);
		self::assertStringNotContainsString('addM2MConditions(', $m2mContents);
		self::assertStringContainsString('RelationKeyQuery::addJoinConditions(', $m2mContents);
		self::assertStringContainsString('RelationKeyQuery::filterRightByLeftReferences(', $m2mContents);
		self::assertStringNotContainsString('count($throughInnerKeys) === 1', $m2mContents);
		self::assertStringNotContainsString('count($childFields) === 1', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/Loader/HasManyLoader.php'));
	}

	public function testRelationKeyPairingStaysPureDefinitionMetadata(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Definition/Relation/RelationKeyPairing.php');

		foreach ([
			'LoadBranch',
			'Join',
			'SelectQuery',
			'QuerySourceInterface',
			'namespace ON\Data\Query',
			'ON\Data\Query\\',
			'requireLeft(',
			'requireRight(',
			'addJoinConditions(',
			'filterRightByLeftReferences(',
		] as $forbidden) {
			self::assertStringNotContainsString($forbidden, $contents, $forbidden);
		}

		$queryHelperContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/RelationKeyQuery.php');
		self::assertStringContainsString('RelationKeyPairing', $queryHelperContents);
		self::assertStringContainsString('addJoinConditions', $queryHelperContents);
		self::assertStringContainsString('filterRightByLeftReferences', $queryHelperContents);
	}

	public function testBranchOutputShapingUsesRelationCardinalityInsteadOfParserCollectionChecks(): void
	{
		$relationBranchContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/RelationLoadBranch.php');
		$rootBranchContents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/RootLoadBranch.php');

		self::assertStringContainsString('returnsMany(', $relationBranchContents);
		self::assertStringNotContainsString('nodeIsCollectionLike(', $relationBranchContents);
		self::assertStringNotContainsString('isCollectionLike(', $relationBranchContents);
		self::assertStringNotContainsString('nodeIsCollectionLike(', $rootBranchContents);
	}

	public function testRuntimeOnlyAttachesRootParserNodes(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Query/Relation/LoadRuntime.php');

		self::assertStringContainsString('$rootNode->joinNode(', $contents);
		self::assertStringContainsString('$rootNode->linkNode(', $contents);
		self::assertStringNotContainsString('$node->joinNode(', $contents);
		self::assertStringNotContainsString('$node->linkNode(', $contents);
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
				'/src/Query/Relation/RelationLoadBranch.php',
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
				'->getInnerKeys(',
				'->getOuterKeys(',
				'->getWhere(',
				'->getOrderBy(',
				'->getThrough(',
				'->getInnerKeys(',
				'->getOuterKeys(',
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
				'->getInnerKeys(',
				'->getOuterKeys(',
				'->getWhere(',
				'->getOrderBy(',
				'->getThrough(',
				'->getInnerKeys(',
				'->getOuterKeys(',
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
