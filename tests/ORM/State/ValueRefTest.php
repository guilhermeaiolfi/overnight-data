<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\ValueRef;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class ValueRefTest extends TestCase
{
	use OrmFixture;

	public function testFieldStoresRecordAndField(): void
	{
		$record = RecordState::new($this->users(), ['id' => 10]);
		$ref = ValueRef::field($record, 'id');

		self::assertSame($record, $ref->getRecord());
		self::assertSame('id', $ref->getField());
	}

	public function testEmptyFieldThrows(): void
	{
		$this->expectException(StateException::class);

		ValueRef::field(RecordState::new($this->users()), '');
	}

	public function testIsResolvedFalseWhenReferencedFieldIsMissing(): void
	{
		$ref = ValueRef::field(RecordState::new($this->users()), 'id');

		self::assertFalse($ref->isResolved());
	}

	public function testIsResolvedFalseWhenReferencedFieldIsNull(): void
	{
		$ref = ValueRef::field(RecordState::new($this->users(), ['id' => null]), 'id');

		self::assertFalse($ref->isResolved());
	}

	public function testIsResolvedFalseWhenReferencedFieldIsAnotherUnresolvedValueRef(): void
	{
		$source = RecordState::new($this->users());
		$target = RecordState::new($this->users(), ['user_id' => $source->getValueRef('id')]);
		$ref = ValueRef::field($target, 'user_id');

		self::assertFalse($ref->isResolved());
	}

	public function testIsResolvedTrueWhenReferencedFieldHasConcreteNonNullValue(): void
	{
		$ref = ValueRef::field(RecordState::new($this->users(), ['id' => 10]), 'id');

		self::assertTrue($ref->isResolved());
	}

	public function testResolveReturnsConcreteValueWhenResolved(): void
	{
		$ref = ValueRef::field(RecordState::new($this->users(), ['id' => 10]), 'id');

		self::assertSame(10, $ref->resolve());
	}

	public function testResolveThrowsWhenUnresolved(): void
	{
		$this->expectException(StateException::class);

		ValueRef::field(RecordState::new($this->users()), 'id')->resolve();
	}

	public function testEqualsTrueForSameRecordAndSameField(): void
	{
		$record = RecordState::new($this->users());

		self::assertTrue(ValueRef::field($record, 'id')->equals(ValueRef::field($record, 'id')));
	}

	public function testEqualsFalseForSameRecordDifferentField(): void
	{
		$record = RecordState::new($this->users());

		self::assertFalse(ValueRef::field($record, 'id')->equals(ValueRef::field($record, 'name')));
	}

	public function testEqualsFalseForDifferentRecordSameField(): void
	{
		$users = $this->users();

		self::assertFalse(ValueRef::field(RecordState::new($users), 'id')->equals(ValueRef::field(RecordState::new($users), 'id')));
	}

	public function testStringConversionIsNotAdded(): void
	{
		self::assertFalse((new ReflectionClass(ValueRef::class))->hasMethod('__toString'));
	}
}
