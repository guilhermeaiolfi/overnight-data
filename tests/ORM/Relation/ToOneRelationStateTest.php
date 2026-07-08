<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class ToOneRelationStateTest extends TestCase
{
	use OrmFixture;

	public function testConstructorStoresOwnerRelationNameRelatedBindingBaselineTargetAndCurrentTarget(): void
	{
		$owner = RecordState::new($this->users());
		$binding = $this->postBinding();
		$target = new stdClass();

		$reference = new ToOneRelationState($owner, 'author', $binding, $target);

		self::assertSame($owner, $reference->getOwner());
		self::assertSame('author', $reference->getRelationName());
		self::assertSame($binding, $reference->getRelatedSchema());
		self::assertSame($target, $reference->getBaselineTarget());
		self::assertSame($target, $reference->getTarget());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(StateException::class);

		new ToOneRelationState(RecordState::new($this->users()), '', $this->postBinding());
	}

	public function testSetChangesCurrentTarget(): void
	{
		$target = new stdClass();
		$reference = $this->reference();

		$reference->set($target);

		self::assertSame($target, $reference->getTarget());
	}

	public function testClearSetsCurrentTargetToNull(): void
	{
		$reference = $this->reference(new stdClass());

		$reference->clear();

		self::assertNull($reference->getTarget());
	}

	public function testHasTargetReflectsCurrentTarget(): void
	{
		$reference = $this->reference();

		self::assertFalse($reference->hasTarget());

		$reference->set(new stdClass());

		self::assertTrue($reference->hasTarget());
	}

	public function testHasChangesIsFalseForNullToNull(): void
	{
		self::assertFalse($this->reference()->hasChanges());
	}

	public function testHasChangesIsFalseForSameObject(): void
	{
		$target = new stdClass();

		self::assertFalse($this->reference($target)->hasChanges());
	}

	public function testHasChangesIsTrueForNullToObject(): void
	{
		$reference = $this->reference();

		$reference->set(new stdClass());

		self::assertTrue($reference->hasChanges());
	}

	public function testHasChangesIsTrueForObjectToNull(): void
	{
		$reference = $this->reference(new stdClass());

		$reference->clear();

		self::assertTrue($reference->hasChanges());
	}

	public function testHasChangesIsTrueForObjectToDifferentObject(): void
	{
		$reference = $this->reference(new stdClass());

		$reference->set(new stdClass());

		self::assertTrue($reference->hasChanges());
	}

	public function testClearChangesRefreshesBaselineToCurrent(): void
	{
		$target = new stdClass();
		$reference = $this->reference();
		$reference->set($target);

		$reference->clearChanges();

		self::assertSame($target, $reference->getBaselineTarget());
		self::assertFalse($reference->hasChanges());
	}

	public function testGetRelatedBindingReturnsExactBindingInstance(): void
	{
		$binding = $this->postBinding();

		self::assertSame($binding, (new ToOneRelationState(RecordState::new($this->users()), 'author', $binding))->getRelatedSchema());
	}

	private function reference(?object $target = null): ToOneRelationState
	{
		return new ToOneRelationState(RecordState::new($this->users()), 'author', $this->postBinding(), $target);
	}
}
