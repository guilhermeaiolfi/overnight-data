<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TrackedRepresentationTest extends TestCase
{
	public function testSupportsStdClassAndExposesBindingAndRepresentation(): void
	{
		$object = new stdClass();
		$binding = new RepresentationBinding();
		$tracked = new TrackedRepresentation($object, $binding, []);

		self::assertSame($object, $tracked->getRepresentation());
		self::assertSame($binding, $tracked->getBinding());
	}

	public function testStoresBaselineRevisionsByRecordHash(): void
	{
		$hash = $this->users()->getKey(10)->getHash();
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), [$hash => 4]);

		self::assertTrue($tracked->hasBaselineRevision($hash));
		self::assertSame(4, $tracked->getBaselineRevision($hash));
		self::assertSame([$hash => 4], $tracked->getBaselineRevisions());
	}

	public function testGetsBaselineRevisionForRecordFieldRef(): void
	{
		$users = $this->users();
		$field = new RecordFieldRef($users, 'name', $users->getKey(10));
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), [$field->getRecordHash() => 2]);

		self::assertSame(2, $tracked->getBaselineRevisionFor($field));
	}

	public function testStoresBaselineRevisionForStateTargetedNewRecordHash(): void
	{
		$field = RecordFieldRef::forState(RecordState::new($this->users(), ['name' => 'A1']), 'name');
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), [$field->getRecordHash() => 1]);

		self::assertTrue($tracked->hasBaselineRevision($field->getRecordHash()));
		self::assertSame(1, $tracked->getBaselineRevisionFor($field));
	}

	public function testMissingBaselineRevisionThrows(): void
	{
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), []);

		$this->expectException(StateException::class);
		$tracked->getBaselineRevision('missing');
	}

	public function testFieldWithoutKeyThrowsWhenAskingBaselineRevision(): void
	{
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), []);

		$this->expectException(StateException::class);
		$tracked->getBaselineRevisionFor(new RecordFieldRef($this->users(), 'name'));
	}

	public function testMissingBaselineForStateTargetedRefThrows(): void
	{
		$field = RecordFieldRef::forState(RecordState::new($this->users(), ['name' => 'A1']), 'name');
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), []);

		$this->expectException(StateException::class);
		$tracked->getBaselineRevisionFor($field);
	}

	public function testReplacingBaselineRevisionsWorks(): void
	{
		$users = $this->users();
		$first = $users->getKey(10)->getHash();
		$second = $users->getKey(11)->getHash();
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), [$first => 1]);

		$tracked->replaceBaselineRevisions([$second => 3]);

		self::assertFalse($tracked->hasBaselineRevision($first));
		self::assertSame(3, $tracked->getBaselineRevision($second));
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}
}
