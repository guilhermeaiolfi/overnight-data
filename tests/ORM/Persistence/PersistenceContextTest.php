<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use PHPUnit\Framework\TestCase;

final class PersistenceContextTest extends TestCase
{
	public function testGettersReturnExactInstancesPassedToConstructor(): void
	{
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$relations = new RelationStateStore();
		$commands = new CommandBuffer();
		$session = new SessionContext($records, $representations, $relations);
		$context = new PersistenceContext($session, $commands);

		self::assertSame($session, $context->getSession());
		self::assertSame($records, $context->getRecords());
		self::assertSame($representations, $context->getRepresentations());
		self::assertSame($relations, $context->getRelations());
		self::assertSame($relations, $context->getToManyRelations());
		self::assertSame($relations, $context->getToOneRelations());
		self::assertSame($commands, $context->getCommands());
	}

	public function testContextDoesNotAddRepresentationSpecificShortcuts(): void
	{
		self::assertFalse(method_exists(PersistenceContext::class, 'getRecordForRepresentation'));
		self::assertFalse(method_exists(PersistenceContext::class, 'requireRecordForRepresentation'));
	}
}
