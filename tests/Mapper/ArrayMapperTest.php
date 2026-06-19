<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Writer\WriterInterface;
use PHPUnit\Framework\TestCase;

final class ArrayMapperTest extends TestCase
{
	protected function setUp(): void
	{
		ArrayMapperNodeRecordingWriter::reset();
	}

	public function testArraySourcePropertiesAreAlwaysNull(): void
	{
		$this->mapWithRecorder([
			'name' => 'Alice',
		]);

		self::assertSame(
			[
				[
					'name' => 'name',
					'sourceProperty' => null,
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
					'sourceProperty' => null,
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
	 * @var list<array{name: string|int|null, sourceProperty: string|null}>
	 */
	public static array $writes = [];

	public static function reset(): void
	{
		self::$writes = [];
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		return is_array($target);
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): array {
		return [];
	}

	public function write(
		mixed $target,
		MappingNode $node,
		mixed $value,
	): array {
		self::$writes[] = [
			'name' => $node->getName(),
			'sourceProperty' => $node->getSourceProperty()?->getName(),
		];

		$target[$node->getName()] = $value;

		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): array {
		return $target;
	}
}
