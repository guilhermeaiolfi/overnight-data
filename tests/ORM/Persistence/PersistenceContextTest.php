<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;
use PHPUnit\Framework\TestCase;

final class PersistenceContextTest extends TestCase
{
	public function testGettersReturnExactInstancesPassedToConstructor(): void
	{
		$records = new RecordStateStore();
		$representations = new RepresentationStore();
		$relations = new RelationStateStore();
		$references = new RelationStateStore();
		$commands = new CommandBuffer();
		$context = new PersistenceContext($records, $representations, $relations, $references, $commands);

		self::assertSame($records, $context->getRecords());
		self::assertSame($representations, $context->getRepresentations());
		self::assertSame($relations, $context->getRelations());
		self::assertSame($references, $context->getReferences());
		self::assertSame($commands, $context->getCommands());
	}

	public function testContextDoesNotAddRepresentationSpecificShortcuts(): void
	{
		self::assertFalse(method_exists(PersistenceContext::class, 'getRecordForRepresentation'));
		self::assertFalse(method_exists(PersistenceContext::class, 'requireRecordForRepresentation'));
	}
}
