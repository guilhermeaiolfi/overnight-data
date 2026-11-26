<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Support\ReflectionMetadataCache;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\AuthorDto;
use WeakReference;

final class ReflectionMetadataCacheTest extends TestCase
{
	public function testSeparateMappingsReuseMetadataThroughTheSameGateway(): void
	{
		$gateway = ConversionGateway::createDefault();
		$manager = $gateway->getMapperManager();
		$cache = $manager->getReflectionMetadata();
		$target = ReflectionMetadataTarget::class;

		$first = map(['authors' => [['id' => 1]]], gateway: $gateway)->to($target);
		self::assertInstanceOf($target, $first);
		self::assertSame(1, $first->authors[0]->id);
		$entry = $cache->forClass($target);
		$matcher = $entry->targetMatcher;
		self::assertNotNull($matcher);
		self::assertSame(AuthorDto::class, $entry->imports['AuthorDto'] ?? null);

		$second = map(['authors' => [['id' => 2]]], gateway: $gateway)->to($target);
		self::assertInstanceOf($target, $second);
		self::assertSame(2, $second->authors[0]->id);
		self::assertSame($matcher, $cache->forClass($target)->targetMatcher);

		$other = ConversionGateway::createDefault();
		self::assertNull($other->getMapperManager()->getReflectionMetadata()->forClass($target)->targetMatcher);
		$clone = clone $manager;
		self::assertNull($clone->getReflectionMetadata()->forClass($target)->targetMatcher);

		$manager->clear();
		self::assertNull($cache->forClass($target)->targetMatcher);
		self::assertNull($cache->forClass($target)->imports);
	}

	public function testReleasingTheGatewayReleasesItsMetadataCache(): void
	{
		$gateway = ConversionGateway::createDefault();
		map(['id' => 1], gateway: $gateway)->to(AuthorDto::class);
		$cache = WeakReference::create($gateway->getMapperManager()->getReflectionMetadata());

		unset($gateway);
		gc_collect_cycles();

		self::assertNull($cache->get());
	}

	public function testCacheEvictsTheOldestEntryWhenFull(): void
	{
		$cache = new ReflectionMetadataCache();
		$oldest = WeakReference::create($cache->forClass(ReflectionMetadataTarget::class));
		$next = WeakReference::create($cache->forClass(AuthorDto::class));
		for ($i = 0; $i < 255; $i++) {
			$alias = __NAMESPACE__ . '\\ReflectionMetadataAlias' . $i;
			class_alias(ReflectionMetadataTarget::class, $alias);
			assert(class_exists($alias, false));
			$cache->forClass($alias);
		}

		self::assertNull($oldest->get());
		self::assertNotNull($next->get());
		self::assertSame($next->get(), $cache->forClass(AuthorDto::class));
	}
}

final class ReflectionMetadataTarget
{
	/** @var list<AuthorDto> */
	public array $authors = [];
}
