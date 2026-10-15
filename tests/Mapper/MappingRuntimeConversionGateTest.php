<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use InvalidArgumentException;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\ConversionException;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\ApiRepresentation;
use Tests\ON\Data\Fixture\TrackingApiCodec;
use Tests\ON\Data\Fixture\TrackingCustomFieldType;
use Tests\ON\Data\Fixture\TrackingWireCodec;

final class MappingRuntimeConversionGateTest extends TestCase
{
	public function testRecursiveConversionFailureKeepsPathAndPreviousException(): void
	{
		try {
			map(['child' => ['id' => 'invalid']], null, $this->gateway())
				->from(WireRepresentation::class)
				->fieldMap(FieldMap::fromArray(['child.id' => 'int']))
				->to([]);
			self::fail('Expected the invalid integer to fail conversion.');
		} catch (MappingException $exception) {
			self::assertSame("Failed converting value at path 'child.id'.", $exception->getMessage());
			self::assertInstanceOf(ConversionException::class, $exception->getPrevious());
			self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious()->getPrevious());
		}
	}

	public function testRootConversionFailurePreservesOriginalException(): void
	{
		$gateway = $this->gateway();
		$node = MappingNode::root('invalid', [], (new MappingOptions($gateway))->withSourceRepresentation(WireRepresentation::class));

		try {
			$this->runtimeFor($gateway)->convert('invalid', LeafNodeResolution::named('id', 'int'), $node);
			self::fail('Expected the invalid integer to fail conversion.');
		} catch (MappingException $exception) {
			self::assertSame(ConversionException::class, $exception::class);
			self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
		}
	}

	protected function setUp(): void
	{
		TrackingCustomFieldType::reset();
	}

	public function testMappingWithoutRepresentationsNeverInvokesConversionGateway(): void
	{
		$gateway = $this->gateway();
		$runtime = $this->runtimeFor($gateway);

		self::assertSame(
			['payload' => 'value'],
			$runtime->mapNode(
				MappingNode::root(
					source: ['payload' => 'value'],
					target: [],
					options: (new MappingOptions($gateway))
						->withAddedResolverClass(ConversionGateTrackedResolver::class),
				),
			),
		);
		self::assertSame([], TrackingCustomFieldType::calls());
	}

	public function testSourceRepresentationAloneStillInvokesConversion(): void
	{
		$gateway = $this->gateway();
		$runtime = $this->runtimeFor($gateway);

		self::assertSame(
			['payload' => 'php-wire<value>'],
			$runtime->mapNode(
				MappingNode::root(
					source: ['payload' => 'value'],
					target: [],
					options: (new MappingOptions($gateway))
						->withSourceRepresentation(WireRepresentation::class)
						->withAddedResolverClass(ConversionGateTrackedResolver::class),
				),
			),
		);
		self::assertSame(['wireCodec:toPhp'], TrackingCustomFieldType::calls());
	}

	public function testOutputRepresentationAloneStillInvokesConversion(): void
	{
		$gateway = $this->gateway();
		$runtime = $this->runtimeFor($gateway);

		self::assertSame(
			['payload' => 'api<value>'],
			$runtime->mapNode(
				MappingNode::root(
					source: ['payload' => 'value'],
					target: [],
					options: (new MappingOptions($gateway))
						->withOutputRepresentation(ApiRepresentation::class)
						->withAddedResolverClass(ConversionGateTrackedResolver::class),
				),
			),
		);
		self::assertSame(['apiCodec:fromPhp'], TrackingCustomFieldType::calls());
	}

	public function testSourceAndOutputRepresentationsTogetherStillInvokeConversion(): void
	{
		$gateway = $this->gateway();
		$runtime = $this->runtimeFor($gateway);

		self::assertSame(
			['payload' => 'api<php-wire<value>>'],
			$runtime->mapNode(
				MappingNode::root(
					source: ['payload' => 'value'],
					target: [],
					options: (new MappingOptions($gateway))
						->withSourceRepresentation(WireRepresentation::class)
						->withOutputRepresentation(ApiRepresentation::class)
						->withAddedResolverClass(ConversionGateTrackedResolver::class),
				),
			),
		);
		self::assertSame(['wireCodec:toPhp', 'apiCodec:fromPhp'], TrackingCustomFieldType::calls());
	}

	public function testCollectionsRetainSameOutputWhenConversionIsDisabled(): void
	{
		$gateway = $this->gateway();
		$runtime = $this->runtimeFor($gateway);

		self::assertSame(
			[
				['payload' => 'first'],
				['payload' => 'second'],
			],
			$runtime->mapNode(
				MappingNode::root(
					source: [
						['payload' => 'first'],
						['payload' => 'second'],
					],
					target: [],
					options: (new MappingOptions($gateway))
						->withAddedResolverClass(ConversionGateTrackedResolver::class)
						->asCollection(),
				),
			),
		);
		self::assertSame([], TrackingCustomFieldType::calls());
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
		$gateway = $this->gateway();
		$runtime = $this->runtimeFor($gateway);

		self::assertSame(
			['payload' => null],
			$runtime->mapNode(
				MappingNode::root(
					source: ['payload' => null],
					target: [],
					options: (new MappingOptions($gateway))
						->withSourceRepresentation(WireRepresentation::class)
						->withAddedResolverClass(ConversionGateTrackedResolver::class),
				),
			),
		);
		self::assertSame([], TrackingCustomFieldType::calls());
	}

	private function gateway(): ConversionGateway
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->register(TrackingCustomFieldType::class);
		$gateway->getMapperManager()->register(TrackingWireCodec::class);
		$gateway->getMapperManager()->register(TrackingApiCodec::class);

		return $gateway;
	}

	private function runtimeFor(ConversionGateway $gateway): MappingRuntime
	{
		return new MappingRuntime(
			mapperManager: $gateway->getMapperManager(),
		);
	}
}

final class ConversionGateTrackedResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
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
		MappingRuntime $runtime,
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
