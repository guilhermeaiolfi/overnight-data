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
	private ?MappingDataset $dataset = null;
	private ?ConversionGateway $gateway = null;

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(100)
	 */
	public function benchSingleFlatArrayToDto(): void
	{
		map($this->dataset->singleFlatArray(), null, $this->gateway)->to(FlatTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 */
	public function benchFlatArrayCollectionToArray1000(): void
	{
		map($this->dataset->flatArrayCollection1000(), null, $this->gateway)
			->collection()
			->to([]);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 */
	public function benchFlatArrayCollectionToDto1000(): void
	{
		map($this->dataset->flatArrayCollection1000(), null, $this->gateway)
			->collection()
			->to(FlatTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 */
	public function benchFlatDtoCollectionToArray1000(): void
	{
		map($this->dataset->flatDtoCollection1000(), null, $this->gateway)
			->collection()
			->to([]);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 */
	public function benchFlatArrayCollectionToDtoWithWireConversion1000(): void
	{
		map($this->dataset->flatWireArrayCollection1000(), null, $this->gateway)
			->from(WireRepresentation::class)
			->collection()
			->to(FlatTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 */
	public function benchDefinitionCollectionWithStorageConversion1000(): void
	{
		map($this->dataset->flatStorageArrayCollection1000(), null, $this->gateway)
			->from(StorageRepresentation::class)
			->args($this->dataset->flatDefinition())
			->collection()
			->to([]);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 */
	public function benchNestedArrayCollectionToDto1000(): void
	{
		map($this->dataset->nestedArrayCollection1000(), null, $this->gateway)
			->collection()
			->to(NestedTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 */
	public function benchDottedArrayCollectionToDto1000(): void
	{
		map($this->dataset->dottedNestedArrayCollection1000(), null, $this->gateway)
			->collection()
			->to(NestedTargetDto::class);
	}

	/**
	 * @BeforeMethods({"setUpBenchmark"})
	 * @Revs(1)
	 * @Iterations(3)
	 */
	public function benchFlatArrayCollectionToDto10000(): void
	{
		map($this->dataset->flatArrayCollection10000(), null, $this->gateway)
			->collection()
			->to(FlatTargetDto::class);
	}

	public function setUpBenchmark(): void
	{
		if ($this->dataset !== null && $this->gateway !== null) {
			return;
		}

		$this->dataset = new MappingDataset();
		$this->gateway = ConversionGateway::createDefault();
		$this->gateway->getMapperManager()->warmUp();
	}
}
