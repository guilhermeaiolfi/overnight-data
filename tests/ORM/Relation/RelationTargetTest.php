<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\RelationTarget;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RelationTargetTest extends TestCase
{
	public function testFromRecordStateUsesRecordIdentity(): void
	{
		$posts = (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
		$record = RecordState::new($posts, ['title' => 'A']);

		$target = RelationTarget::from($record);

		self::assertTrue($target->isRecord());
		self::assertSame($record, $target->getRecord());
		self::assertSame($record, $target->toObject());
		self::assertSame(RelationTarget::record($record)->identityKey(), $target->identityKey());
	}

	public function testFromRepresentationUsesObjectIdentity(): void
	{
		$object = new stdClass();
		$target = RelationTarget::from($object);

		self::assertTrue($target->isRepresentation());
		self::assertSame($object, $target->getRepresentation());
		self::assertSame('o:' . spl_object_id($object), $target->identityKey());
	}

	public function testRepresentationFactoryRejectsRecordState(): void
	{
		$posts = (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end();

		$this->expectException(StateException::class);
		RelationTarget::representation(RecordState::new($posts, []));
	}
}
