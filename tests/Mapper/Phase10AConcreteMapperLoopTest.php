<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Writer\WriterInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class Phase10AConcreteMapperLoopTest extends TestCase
{
	protected function setUp(): void
	{
		Phase10ARecordingWriter::reset();
	}

	public function testArrayMapperOwnsImmediateArrayLoopAndNestedBranchLifecycle(): void
	{
		$child = new stdClass();
		$child->name = 'Ada';

		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(Phase10ARecordingWriter::class);

		$result = map(
			[
				'id' => 10,
				'child' => $child,
			],
			null,
			$gateway,
		)->to([]);

		self::assertSame(['id' => 10, 'child' => ['name' => 'Ada']], $result);
		self::assertSame(
			[
				['event' => 'createTarget'],
				['event' => 'write', 'path' => 'id'],
				['event' => 'createTarget'],
				['event' => 'write', 'path' => 'child.name'],
				['event' => 'write', 'path' => 'child'],
			],
			Phase10ARecordingWriter::$events,
		);
	}

	public function testObjectMapperOwnsStdClassAndPropertyLoops(): void
	{
		$source = new Phase10AObjectSource();
		$source->name = 'Ada';
		$source->child = new stdClass();
		$source->child->role = 'admin';

		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(Phase10ARecordingWriter::class);

		$result = map($source, null, $gateway)->to([]);

		self::assertSame(
			[
				'display_name' => 'Ada',
				'child' => ['role' => 'admin'],
			],
			$result,
		);
		self::assertSame(
			[
				['event' => 'createTarget'],
				['event' => 'write', 'path' => 'name'],
				['event' => 'createTarget'],
				['event' => 'write', 'path' => 'child.role'],
				['event' => 'write', 'path' => 'child'],
			],
			Phase10ARecordingWriter::$events,
		);
	}
}

final class Phase10ARecordingWriter implements WriterInterface
{
	/**
	 * @var list<array{event: string, path?: string}>
	 */
	public static array $events = [];

	public static function reset(): void
	{
		self::$events = [];
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		return is_array($target);
	}

	public function createTarget(MappingNode $node): array
	{
		self::$events[] = [
			'event' => 'createTarget',
		];

		return [];
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): array {
		self::$events[] = [
			'event' => 'write',
			'path' => $node->getPath(),
		];

		$target[$name] = $value;

		return $target;
	}
}

final class Phase10AObjectSource
{
	#[MapTo('display_name')]
	public string $name;

	public stdClass $child;

	#[Hidden]
	public string $hidden = 'secret';

	public static string $staticValue = 'static';

	public ?string $uninitialized;
}
