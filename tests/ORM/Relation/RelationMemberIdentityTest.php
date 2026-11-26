<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RelationMemberIdentityTest extends TestCase
{
	public function testGeneratedKeyRemainsAddressableAsTheSamePendingMember(): void
	{
		$posts = (new Registry())->collection('posts')->primaryKey('id')->field('id', 'int')->end();
		$owner = RecordState::new($posts);
		$member = RecordState::new($posts);
		$relation = new ToManyRelationState($owner, 'children', RepresentationSchema::forPrimaryKey($posts));
		$relation->add($member);

		$key = $posts->getKey(5);
		$member->markClean($key);
		$equivalent = RecordState::clean($key, []);
		self::assertTrue($relation->contains($equivalent));
		$relation->remove($equivalent);
		self::assertFalse($relation->contains($member));
		self::assertFalse($relation->hasChanges());
	}

	public function testDistinctDtoInstancesRemainSeparateMembers(): void
	{
		$posts = (new Registry())->collection('posts')->primaryKey('id')->field('id', 'int')->end();
		$first = new stdClass();
		$second = new stdClass();
		$relation = new ToManyRelationState(RecordState::new($posts), 'children', RepresentationSchema::forPrimaryKey($posts));
		$relation->add($first);
		$relation->add($second);
		$relation->add($first);
		self::assertSame([$first, $second], $relation->getItems());
	}
}
