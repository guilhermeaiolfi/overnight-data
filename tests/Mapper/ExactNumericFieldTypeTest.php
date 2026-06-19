<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Field\BigIntFieldType;
use ON\Data\Mapper\Field\DecimalFieldType;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExactNumericFieldTypeTest extends TestCase
{
	#[DataProvider('decimalNormalizationProvider')]
	public function testDecimalFieldTypeNormalizesExactValues(mixed $input, string $expected): void
	{
		self::assertSame($expected, DecimalFieldType::toPhp($input, LeafNodeResolution::named('amount', 'decimal')));
		self::assertSame($expected, DecimalFieldType::fromPhp($input, LeafNodeResolution::named('amount', 'decimal')));
	}

	public static function decimalNormalizationProvider(): array
	{
		return [
			['0', '0'],
			['00012', '12'],
			['+12', '12'],
			['-12', '-12'],
			['0012.3400', '12.34'],
			['0.5000', '0.5'],
			['-0', '0'],
			['-0.000', '0'],
			['  123.4500  ', '123.45'],
			[12, '12'],
		];
	}

	#[DataProvider('invalidDecimalProvider')]
	public function testDecimalFieldTypeRejectsInvalidValues(mixed $input, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		DecimalFieldType::toPhp($input, LeafNodeResolution::named('amount', 'decimal'));
	}

	public static function invalidDecimalProvider(): array
	{
		return [
			[12.5, 'strings or integers'],
			['1e10', 'base-10 decimal string'],
			['', 'base-10 decimal string'],
			['   ', 'base-10 decimal string'],
			['1.', 'base-10 decimal string'],
			['.5', 'base-10 decimal string'],
			['1,25', 'base-10 decimal string'],
		];
	}

	#[DataProvider('bigIntNormalizationProvider')]
	public function testBigIntFieldTypeNormalizesExactValues(mixed $input, string $expected): void
	{
		self::assertSame($expected, BigIntFieldType::toPhp($input, LeafNodeResolution::named('id', 'bigint')));
		self::assertSame($expected, BigIntFieldType::fromPhp($input, LeafNodeResolution::named('id', 'bigint')));
	}

	public static function bigIntNormalizationProvider(): array
	{
		return [
			['0', '0'],
			['000123', '123'],
			['+123', '123'],
			['-000123', '-123'],
			['-0', '0'],
			['9223372036854775808', '9223372036854775808'],
			['-9223372036854775809', '-9223372036854775809'],
			[42, '42'],
		];
	}

	#[DataProvider('invalidBigIntProvider')]
	public function testBigIntFieldTypeRejectsInvalidValues(mixed $input): void
	{
		$this->expectException(InvalidArgumentException::class);

		BigIntFieldType::toPhp($input, LeafNodeResolution::named('id', 'bigint'));
	}

	public static function invalidBigIntProvider(): array
	{
		return [
			[12.5],
			['12.5'],
			['1e10'],
			[''],
			['   '],
		];
	}

	public function testRepresentationBoundariesPreserveExactStrings(): void
	{
		$definition = $this->numbersDefinition();

		$php = map([
			'id' => '9223372036854775808',
			'amount' => '00012.3400',
		])
			->from(StorageRepresentation::class)
			->args($definition)
			->to([]);

		$storage = map($php)
			->as(StorageRepresentation::class)
			->args($definition)
			->to([]);

		$wire = map($php)
			->as(WireRepresentation::class)
			->args($definition)
			->to([]);

		self::assertSame(['id' => '9223372036854775808', 'amount' => '12.34'], $php);
		self::assertSame(['id' => '9223372036854775808', 'amount' => '12.34'], $storage);
		self::assertSame(['id' => '9223372036854775808', 'amount' => '12.34'], $wire);
		self::assertSame('{"id":"9223372036854775808","amount":"12.34"}', map($php)->toJson());
	}

	public function testBigPrimaryAliasDoesNotModifyDefinitionPrimaryKeyMetadata(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('orders')
			->field('id', 'bigprimary')
			->end()
			->primaryKey('id');
		$before = $definition->getPrimaryKey();

		$result = map(['id' => '9223372036854775808'])
			->from(StorageRepresentation::class)
			->args($definition)
			->to([]);

		self::assertSame(['id'], $before);
		self::assertSame(['id'], $definition->getPrimaryKey());
		self::assertSame('9223372036854775808', $result['id']);
	}

	private function numbersDefinition(): object
	{
		$registry = new Registry();
		$definition = $registry->collection('numbers');
		$definition->field('id', 'bigint');
		$definition->field('amount', 'decimal');

		return $definition;
	}
}
