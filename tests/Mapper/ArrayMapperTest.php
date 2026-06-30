<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ArrayWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;
use PHPUnit\Framework\TestCase;

final class ArrayMapperTest extends TestCase
{
	protected function setUp(): void
	{
		ArrayMapperNodeRecordingWriter::reset();
	}

	public function testArrayKeysBecomeRawNodeNames(): void
	{
		$this->mapWithRecorder([
			'name' => 'Alice',
		]);

		self::assertSame(
			[
				[
					'name' => 'name',
				],
			],
			ArrayMapperNodeRecordingWriter::$writes,
		);
	}

	public function testNumericArrayKeyZeroRemainsNodeName(): void
	{
		$this->mapWithRecorder([
			0 => 'Alice',
		]);

		self::assertSame(
			[
				[
					'name' => 0,
				],
			],
			ArrayMapperNodeRecordingWriter::$writes,
		);
	}

	/**
	 * @param array<string|int, mixed> $source
	 */
	private function mapWithRecorder(array $source): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(ArrayMapperNodeRecordingWriter::class);

		map($source, null, $gateway)->to([]);
	}
}

final class ArrayMapperNodeRecordingWriter implements WriterInterface
{
	/**
	 * @var list<array{name: string|int|null}>
	 */
	public static array $writes = [];

	public static function reset(): void
	{
		self::$writes = [];
	}

	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		return is_array($target);
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
		return new ArrayWriterState();
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		self::$writes[] = [
			'name' => $node->getName(),
		];
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): array {
		return [];
	}
}
