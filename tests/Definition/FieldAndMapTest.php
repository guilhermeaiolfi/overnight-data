<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Display\BooleanDisplay;
use ON\Data\Definition\Exception\FieldException;
use ON\Data\Definition\Field\FieldMap;
use ON\Data\Definition\Interface\TextareaInterface;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class FieldAndMapTest extends TestCase
{
	public function testFieldSupportsCurrentSettersGettersAndTraits(): void
	{
		$field = (new Registry())
			->collection('users')
			->field('email')
			->alias('mail')
			->type('string')
			->column('user_email')
			->required(true)
			->searchable()
			->sensible(true)
			->default('guest@example.com', false)
			->setGeneratedFromRelation('profile')
			->validation('required|email', ['required' => 'Email is required'])
			->description('Primary email')
			->typecast('trim')
			->primaryKey(true)
			->filterable(false)
			->autoIncrement(true)
			->nullable(true)
			->unique(true)
			->indexed(true)
			->comment('column-comment')
			->numericPrecision(4)
			->dataType('varchar')
			->defaultValue('fallback')
			->maxLength(320);

		$field->display(BooleanDisplay::class)->type('boolean')->labelOn('Yes')->end();
		$field->interface(TextareaInterface::class)->limit(500)->end();
		$field->metadata('group', 'identity');

		self::assertSame('email', $field->getName());
		self::assertSame('mail', $field->getAlias());
		self::assertSame('string', $field->getType());
		self::assertSame('user_email', $field->getColumn());
		self::assertTrue($field->isRequired());
		self::assertTrue($field->isSearchable());
		self::assertTrue($field->getSensible());
		self::assertTrue($field->isHidden());
		self::assertSame('guest@example.com', $field->getDefault());
		self::assertFalse($field->castDefault());
		self::assertSame('profile', $field->getGeneratedFromRelation());
		self::assertSame('required|email', $field->getValidation());
		self::assertSame(['required' => 'Email is required'], $field->getValidationMessages());
		self::assertSame('Primary email', $field->getDescription());
		self::assertTrue($field->hasTypecast());
		self::assertSame('trim', $field->getTypecast());
		self::assertTrue($field->isPrimaryKey());
		self::assertFalse($field->isFilterable());
		self::assertTrue($field->isAutoIncrement());
		self::assertTrue($field->isNullable());
		self::assertTrue($field->isUnique());
		self::assertTrue($field->isIndexed());
		self::assertSame('column-comment', $field->getComment());
		self::assertSame(4, $field->getNumericPrecision());
		self::assertSame('varchar', $field->getDataType());
		self::assertSame('fallback', $field->getDefaultValue());
		self::assertSame(320, $field->getMaxLength());
		self::assertSame('identity', $field->metadata('group'));
		self::assertInstanceOf(BooleanDisplay::class, $field->getDisplay());
		self::assertInstanceOf(TextareaInterface::class, $field->getInterface());
	}

	public function testMissingFieldTypeThrowsCurrentException(): void
	{
		$field = (new Registry())->collection('users')->field('email');

		$this->expectException(FieldException::class);
		$this->expectExceptionMessage('Field(email) type must be set in collection: users');
		$field->getType();
	}

	public function testFieldMapPreservesInsertionOrderAndCloneBehavior(): void
	{
		$collection = (new Registry())->collection('users');
		$id = $collection->field('id', 'int')->column('user_id');
		$name = $collection->field('name', 'string');
		$map = new FieldMap();
		$map->set('id', $id);
		$map->set('name', $name);

		self::assertCount(2, $map);
		self::assertTrue($map->has('id'));
		self::assertSame($id, $map->get('id'));
		self::assertSame(['user_id', 'name'], $map->getColumnNames());
		self::assertSame(['id' => 'user_id', 'name' => 'name'], $map->getFieldNameColumnNameMap());
		self::assertSame(['id', 'name'], $map->getNames());
		self::assertTrue($map->hasColumn('user_id'));
		self::assertSame('id', $map->getKeyByColumnName('user_id'));
		self::assertSame($id, $map->getByColumnName('user_id'));
		self::assertSame(['id', 'name'], array_keys(iterator_to_array($map)));

		$cloned = clone $map;
		self::assertNotSame($map->get('id'), $cloned->get('id'));

		$this->expectException(FieldException::class);
		$map->set('id', $collection->field('other_id', 'int'));
	}
}
