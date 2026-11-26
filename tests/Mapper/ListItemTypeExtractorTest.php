<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Metadata\CachedListItemTypeExtractor;
use ON\Data\Mapper\Metadata\ListItemTypeExtractorInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\ON\Data\Fixture\AuthorDto;

final class ListItemTypeExtractorTest extends TestCase
{
	public function testGatewayTriesConfiguredExtractorsInOrderAndCachesForOneMapping(): void
	{
		$first = $this->createMock(ListItemTypeExtractorInterface::class);
		$first->expects(self::exactly(2))->method('extract')->willReturn(null);
		$second = $this->createMock(ListItemTypeExtractorInterface::class);
		$second->expects(self::exactly(2))->method('extract')->willReturn(AuthorDto::class);
		$fallback = $this->createMock(ListItemTypeExtractorInterface::class);
		$fallback->expects(self::never())->method('extract');
		$gateway = ConversionGateway::createDefault(listItemTypeExtractors: [$first, $second, $fallback]);

		$rows = [['authors' => [['id' => 1]]], ['authors' => [['id' => 2]]]];
		$batch = map($rows, gateway: $gateway)->collection()->to(ExtractedCollectionDto::class);
		self::assertInstanceOf(AuthorDto::class, $batch[0]->authors[0]);
		self::assertSame(2, $batch[1]->authors[0]->id);

		$single = map($rows[0], gateway: $gateway)->to(ExtractedCollectionDto::class);
		self::assertSame(1, $single->authors[0]->id);
	}

	public function testExplicitDecoratorReusesATypeBetweenMappings(): void
	{
		$extractor = $this->createMock(ListItemTypeExtractorInterface::class);
		$extractor->expects(self::once())->method('extract')->willReturn(AuthorDto::class);
		$gateway = ConversionGateway::createDefault(listItemTypeExtractors: [new CachedListItemTypeExtractor($extractor)]);
		$first = map(['authors' => [['id' => 1]]], gateway: $gateway)->to(ExtractedCollectionDto::class);
		$second = map(['authors' => [['id' => 2]]], gateway: $gateway)->to(ExtractedCollectionDto::class);

		self::assertSame(1, $first->authors[0]->id);
		self::assertSame(2, $second->authors[0]->id);
	}

	public function testDecoratorRetriesMissesAndEvictsOldEntries(): void
	{
		$extractor = $this->createMock(ListItemTypeExtractorInterface::class);
		$extractor->expects(self::exactly(4))->method('extract')->willReturnOnConsecutiveCalls(
			null,
			AuthorDto::class,
			AuthorDto::class,
			AuthorDto::class,
		);
		$cache = new CachedListItemTypeExtractor($extractor, maxEntries: 1);
		$authors = new ReflectionProperty(ExtractedCollectionDto::class, 'authors');
		$editors = new ReflectionProperty(ExtractedCollectionDto::class, 'editors');

		self::assertNull($cache->extract($authors));
		self::assertSame(AuthorDto::class, $cache->extract($authors));
		self::assertSame(AuthorDto::class, $cache->extract($authors));
		self::assertSame(AuthorDto::class, $cache->extract($editors));
		self::assertSame(AuthorDto::class, $cache->extract($authors));
	}
}

final class ExtractedCollectionDto
{
	public ?array $authors = null;

	public array $editors = [];
}
