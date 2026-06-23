<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldConversionCoordinator;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\ON\Data\Fixture\ApiRepresentation;
use Tests\ON\Data\Fixture\TrackingApiCodec;
use Tests\ON\Data\Fixture\TrackingCustomFieldType;
use Tests\ON\Data\Fixture\TrackingWireCodec;

final class MappingRuntimeConversionGateTest extends TestCase
{
	protected function setUp(): void
	{
		TrackingCustomFieldType::reset();
	}

	public function testOrdinaryMappingsDoNotCreateConversionCoordinator(): void
	{
		$runtime = $this->runtimeFor(
			source: ['id' => 1, 'name' => 'Ada'],
			target: [],
			context: new MappingContext($this->gateway()),
		);

		self::assertSame(['id' => 1, 'name' => 'Ada'], $runtime->map());
		self::assertNull($this->converterProperty()->getValue($runtime));
	}

	public function testMappingWithoutRepresentationsNeverInvokesConversionGateway(): void
	{
		$runtime = $this->runtimeFor(
			source: ['payload' => 'value'],
			target: [],
			context: (new MappingContext($this->gateway()))
				->withAddedResolverClass(ConversionGateTrackedResolver::class),
		);

		self::assertSame(['payload' => 'value'], $runtime->map());
		self::assertSame([], TrackingCustomFieldType::calls());
		self::assertNull($this->converterProperty()->getValue($runtime));
	}

	public function testSourceRepresentationAloneStillInvokesConversion(): void
	{
		$runtime = $this->runtimeFor(
			source: ['payload' => 'value'],
			target: [],
			context: (new MappingContext($this->gateway()))
				->withSourceRepresentation(WireRepresentation::class)
				->withAddedResolverClass(ConversionGateTrackedResolver::class),
		);

		self::assertSame(['payload' => 'php-wire<value>'], $runtime->map());
		self::assertSame(['wireCodec:toPhp'], TrackingCustomFieldType::calls());
		self::assertInstanceOf(FieldConversionCoordinator::class, $this->converterProperty()->getValue($runtime));
	}

	public function testOutputRepresentationAloneStillInvokesConversion(): void
	{
		$runtime = $this->runtimeFor(
			source: ['payload' => 'value'],
			target: [],
			context: (new MappingContext($this->gateway()))
				->withOutputRepresentation(ApiRepresentation::class)
				->withAddedResolverClass(ConversionGateTrackedResolver::class),
		);

		self::assertSame(['payload' => 'api<value>'], $runtime->map());
		self::assertSame(['apiCodec:fromPhp'], TrackingCustomFieldType::calls());
		self::assertInstanceOf(FieldConversionCoordinator::class, $this->converterProperty()->getValue($runtime));
	}

	public function testSourceAndOutputRepresentationsTogetherStillInvokeConversion(): void
	{
		$runtime = $this->runtimeFor(
			source: ['payload' => 'value'],
			target: [],
			context: (new MappingContext($this->gateway()))
				->withSourceRepresentation(WireRepresentation::class)
				->withOutputRepresentation(ApiRepresentation::class)
				->withAddedResolverClass(ConversionGateTrackedResolver::class),
		);

		self::assertSame(['payload' => 'api<php-wire<value>>'], $runtime->map());
		self::assertSame(['wireCodec:toPhp', 'apiCodec:fromPhp'], TrackingCustomFieldType::calls());
		self::assertInstanceOf(FieldConversionCoordinator::class, $this->converterProperty()->getValue($runtime));
	}

	public function testCollectionsRetainSameOutputWhenConversionIsDisabled(): void
	{
		$runtime = $this->runtimeFor(
			source: [
				['payload' => 'first'],
				['payload' => 'second'],
			],
			target: [],
			context: (new MappingContext($this->gateway()))
				->withAddedResolverClass(ConversionGateTrackedResolver::class)
				->asCollection(),
		);

		self::assertSame([
			['payload' => 'first'],
			['payload' => 'second'],
		], $runtime->map());
		self::assertSame([], TrackingCustomFieldType::calls());
		self::assertNull($this->converterProperty()->getValue($runtime));
	}

	public function testRecursiveLeafConversionStillWorks(): void
	{
		$result = map(['child' => ['payload' => 'value']], null, $this->gateway())
			->from(WireRepresentation::class)
			->resolver(ConversionGateRecursiveResolver::class)
			->to([]);

		self::assertSame(['child' => ['payload' => 'php-wire<value>']], $result);
		self::assertSame(['wireCodec:toPhp'], TrackingCustomFieldType::calls());
	}

	public function testNullTypedValuesPreserveExistingBehavior(): void
	{
		$runtime = $this->runtimeFor(
			source: ['payload' => null],
			target: [],
			context: (new MappingContext($this->gateway()))
				->withSourceRepresentation(WireRepresentation::class)
				->withAddedResolverClass(ConversionGateTrackedResolver::class),
		);

		self::assertSame(['payload' => null], $runtime->map());
		self::assertSame([], TrackingCustomFieldType::calls());
		self::assertInstanceOf(FieldConversionCoordinator::class, $this->converterProperty()->getValue($runtime));
	}

	private function gateway(): ConversionGateway
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->register(TrackingCustomFieldType::class);
		$gateway->getMapperManager()->register(TrackingWireCodec::class);
		$gateway->getMapperManager()->register(TrackingApiCodec::class);

		return $gateway;
	}

	private function runtimeFor(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): MappingRuntime {
		return new MappingRuntime(
			mapperManager: $context->getGateway()->getMapperManager(),
			mappingNode: MappingNode::root(
				source: $source,
				target: $target,
				context: $context,
			),
		);
	}

	private function converterProperty(): ReflectionProperty
	{
		return new ReflectionProperty(MappingRuntime::class, 'converter');
	}
}

final class ConversionGateTrackedResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'payload') {
			return LeafNodeResolution::named('payload', 'tracked');
		}

		return null;
	}
}

final class ConversionGateRecursiveResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'child') {
			return BranchNodeResolution::named(
				name: 'child',
				target: [],
				arguments: [],
			);
		}

		if ($node->getName() === 'payload') {
			return LeafNodeResolution::named('payload', 'tracked');
		}

		return null;
	}
}
