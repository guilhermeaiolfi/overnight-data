<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper;

use Benchmarks\ON\Data\Mapper\Support\FlatTargetDto;
use Benchmarks\ON\Data\Mapper\Support\MappingDataset;
use Benchmarks\ON\Data\Mapper\Support\NestedTargetDto;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use function ON\Data\Mapper\map;

/**
 * @Iterations(5)
 * @Warmup(1)
 */
final class MappingBench
{
	private ?ConversionGateway $gateway = null;
	private ?MappingDataset $dataset = null;
	private mixed $source = null;

	public function setUpGateway(): void
	{
		if ($this->dataset === null) {
			$this->dataset = new MappingDataset();
		}

		if ($this->gateway !== null) {
			return;
		}

		$this->gateway = ConversionGateway::createDefault();
		$this->gateway->getMapperManager()->warmUp();
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpSingleFlatArray"})
	 * @Revs(100)
	 */
	public function benchSingleFlatArrayToDto(): void
	{
		map($this->source, null, $this->gateway)->to(FlatTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpFlatArrayCollection1000"})
	 * @Revs(1)
	 */
	public function benchFlatArrayCollectionToArray1000(): void
	{
		map($this->source, null, $this->gateway)
			->collection()
			->to([]);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpFlatArrayCollection1000"})
	 * @Revs(1)
	 */
	public function benchFlatArrayCollectionToDto1000(): void
	{
		map($this->source, null, $this->gateway)
			->collection()
			->to(FlatTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpFlatDtoCollection1000"})
	 * @Revs(1)
	 */
	public function benchFlatDtoCollectionToArray1000(): void
	{
		map($this->source, null, $this->gateway)
			->collection()
			->to([]);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpFlatWireArrayCollection1000"})
	 * @Revs(1)
	 */
	public function benchFlatArrayCollectionToDtoWithWireConversion1000(): void
	{
		map($this->source, null, $this->gateway)
			->from(WireRepresentation::class)
			->collection()
			->to(FlatTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpFlatStorageArrayCollection1000"})
	 * @Revs(1)
	 */
	public function benchDefinitionCollectionWithStorageConversion1000(): void
	{
		map($this->source, null, $this->gateway)
			->from(StorageRepresentation::class)
			->args($this->dataset->flatDefinition())
			->collection()
			->to([]);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpNestedArrayCollection1000"})
	 * @Revs(1)
	 */
	public function benchNestedArrayCollectionToDto1000(): void
	{
		map($this->source, null, $this->gateway)
			->collection()
			->to(NestedTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpDottedNestedArrayCollection1000"})
	 * @Revs(1)
	 */
	public function benchDottedArrayCollectionToDto1000(): void
	{
		map($this->source, null, $this->gateway)
			->collection()
			->to(NestedTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpGateway", "setUpFlatArrayCollection10000"})
	 * @Revs(1)
	 * @Iterations(3)
	 */
	public function benchFlatArrayCollectionToDto10000(): void
	{
		map($this->source, null, $this->gateway)
			->collection()
			->to(FlatTargetDto::class);
	}

	public function setUpSingleFlatArray(): void
	{
		$this->source = $this->dataset?->createFlatArray(1);
	}

	public function setUpFlatArrayCollection1000(): void
	{
		$this->source = $this->dataset?->createFlatArrayCollection(1000);
	}

	public function setUpFlatDtoCollection1000(): void
	{
		$this->source = $this->dataset?->createFlatDtoCollection(1000);
	}

	public function setUpFlatWireArrayCollection1000(): void
	{
		$this->source = $this->dataset?->createFlatWireArrayCollection(1000);
	}

	public function setUpFlatStorageArrayCollection1000(): void
	{
		$this->source = $this->dataset?->createFlatStorageArrayCollection(1000);
	}

	public function setUpNestedArrayCollection1000(): void
	{
		$this->source = $this->dataset?->createNestedArrayCollection(1000);
	}

	public function setUpDottedNestedArrayCollection1000(): void
	{
		$this->source = $this->dataset?->createDottedNestedArrayCollection(1000);
	}

	public function setUpFlatArrayCollection10000(): void
	{
		$this->source = $this->dataset?->createFlatArrayCollection(10000);
	}
}
