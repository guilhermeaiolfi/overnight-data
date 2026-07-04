<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentationMap;
use PHPUnit\Framework\TestCase;

final class PersistenceContextTest extends TestCase
{
	public function testGettersReturnExactInstancesPassedToConstructor(): void
	{
		$records = new RecordStateMap();
		$representations = new TrackedRepresentationMap();
		$relations = new RelatedCollectionMap();
		$commands = new CommandBuffer();
		$context = new PersistenceContext($records, $representations, $relations, $commands);

		self::assertSame($records, $context->getRecords());
		self::assertSame($representations, $context->getRepresentations());
		self::assertSame($relations, $context->getRelations());
		self::assertSame($commands, $context->getCommands());
	}

	public function testContextDoesNotAddRepresentationSpecificShortcuts(): void
	{
		self::assertFalse(method_exists(PersistenceContext::class, 'getRecordForRepresentation'));
		self::assertFalse(method_exists(PersistenceContext::class, 'requireRecordForRepresentation'));
	}
}
