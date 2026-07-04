<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationExpressionBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use PHPUnit\Framework\TestCase;

final class RepresentationBindingTest extends TestCase
{
	public function testAddFieldAndGetFieldByPath(): void
	{
		$binding = new RepresentationBinding();
		$fieldBinding = $this->fieldBinding('name');

		$binding->addField($fieldBinding);

		self::assertTrue($binding->hasField('name'));
		self::assertSame($fieldBinding, $binding->getField('name'));
		self::assertSame([$fieldBinding], $binding->getFields());
	}

	public function testAddExpressionAndGetExpressionByPath(): void
	{
		$binding = new RepresentationBinding();
		$expression = new RepresentationExpressionBinding('postCount', 'post_count');

		$binding->addExpression($expression);

		self::assertTrue($binding->hasExpression('postCount'));
		self::assertSame($expression, $binding->getExpression('postCount'));
		self::assertSame([$expression], $binding->getExpressions());
	}

	public function testAddRelationAndGetRelationByPath(): void
	{
		$binding = new RepresentationBinding();
		$relation = $this->relationBinding('posts');

		$binding->addRelation($relation);

		self::assertTrue($binding->hasRelation('posts'));
		self::assertSame($relation, $binding->getRelation('posts'));
		self::assertSame([$relation], $binding->getRelations());
	}

	public function testHasPathWorksAcrossAllBindingKinds(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField($this->fieldBinding('name'));
		$binding->addExpression(new RepresentationExpressionBinding('postCount'));
		$binding->addRelation($this->relationBinding('posts'));

		self::assertTrue($binding->hasPath('name'));
		self::assertTrue($binding->hasPath('postCount'));
		self::assertTrue($binding->hasPath('posts'));
		self::assertFalse($binding->hasPath('missing'));
	}

	public function testGetPathsIncludesAllBindingKindsInInsertionOrder(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField($this->fieldBinding('name'));
		$binding->addExpression(new RepresentationExpressionBinding('postCount'));
		$binding->addRelation($this->relationBinding('posts'));

		self::assertSame(['name', 'postCount', 'posts'], $binding->getPaths());
	}

	public function testDuplicateFieldPathThrows(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField($this->fieldBinding('name'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('name');
		$binding->addField($this->fieldBinding('name'));
	}

	public function testDuplicateExpressionPathThrows(): void
	{
		$binding = new RepresentationBinding();
		$binding->addExpression(new RepresentationExpressionBinding('postCount'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('postCount');
		$binding->addExpression(new RepresentationExpressionBinding('postCount'));
	}

	public function testDuplicateRelationPathThrows(): void
	{
		$binding = new RepresentationBinding();
		$binding->addRelation($this->relationBinding('posts'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$binding->addRelation($this->relationBinding('posts'));
	}

	public function testDuplicatePathAcrossDifferentBindingKindsThrows(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField($this->fieldBinding('name'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('name');
		$binding->addExpression(new RepresentationExpressionBinding('name'));
	}

	public function testWritableFilterWorks(): void
	{
		$binding = new RepresentationBinding();
		$writable = $this->fieldBinding('name');
		$binding->addField($writable);
		$binding->addField($this->fieldBinding('upperName', false));

		self::assertSame([$writable], $binding->getWritableFieldBindings());
	}

	public function testReadOnlyFilterWorks(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField($this->fieldBinding('name'));
		$readOnly = $this->fieldBinding('upperName', false);
		$binding->addField($readOnly);

		self::assertSame([$readOnly], $binding->getReadOnlyFieldBindings());
	}

	public function testExpressionAndRelationBindingsAreNotReturnedByFieldFilters(): void
	{
		$binding = new RepresentationBinding();
		$writable = $this->fieldBinding('name');
		$readOnly = $this->fieldBinding('upperName', false);
		$binding->addField($writable);
		$binding->addField($readOnly);
		$binding->addExpression(new RepresentationExpressionBinding('postCount'));
		$binding->addRelation($this->relationBinding('posts'));

		self::assertSame([$writable], $binding->getWritableFieldBindings());
		self::assertSame([$readOnly], $binding->getReadOnlyFieldBindings());
	}

	public function testFieldInsertionOrderIsPreserved(): void
	{
		$binding = new RepresentationBinding();
		$name = $this->fieldBinding('name');
		$email = $this->fieldBinding('email');

		$binding->addField($name);
		$binding->addField($email);

		self::assertSame([$name, $email], $binding->getFields());
	}

	public function testApplyToRecordStateReturnsNewBindingWithoutMutatingTemplate(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$templateName = new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name'));
		$templateUpper = new RepresentationFieldBinding('upperName', RecordFieldRef::template($users, 'name'), false);
		$template->addField($templateName);
		$template->addField($templateUpper);
		$state = RecordState::new($users, ['name' => 'A1']);

		$applied = $template->applyToRecordState($state);

		self::assertNotSame($template, $applied);
		self::assertSame($templateName, $template->getField('name'));
		self::assertTrue($template->getField('name')->getField()->isTemplate());
		self::assertSame($state, $applied->getField('name')->getField()->getState());
		self::assertSame($state, $applied->getField('upperName')->getField()->getState());
		self::assertSame('name', $applied->getField('name')->getPath());
		self::assertSame('upperName', $applied->getField('upperName')->getPath());
		self::assertTrue($applied->getField('name')->isWritable());
		self::assertTrue($applied->getField('upperName')->isReadOnly());
	}

	public function testApplyToRecordStatePreservesExpressionAndRelationBindingsUnchanged(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$field = new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name'));
		$expression = new RepresentationExpressionBinding('postCount', 'post_count');
		$relation = $this->relationBinding('posts');
		$template->addField($field);
		$template->addExpression($expression);
		$template->addRelation($relation);

		$applied = $template->applyToRecordState(RecordState::new($users, ['name' => 'A1']));

		self::assertNotSame($template, $applied);
		self::assertNotSame($field, $applied->getField('name'));
		self::assertSame($expression, $applied->getExpression('postCount'));
		self::assertSame($relation, $applied->getRelation('posts'));
		self::assertSame(['name', 'postCount', 'posts'], $applied->getPaths());
	}

	public function testApplyToRecordStateWithFieldFromAnotherCollectionThrows(): void
	{
		$template = new RepresentationBinding();
		$template->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		$this->expectException(StateException::class);
		$template->applyToRecordState(RecordState::new($this->users()));
	}

	public function testApplyToRecordStateWithAlreadyKeyedFieldThrows(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$template->addField(new RepresentationFieldBinding('name', RecordFieldRef::forKey($users->getKey(10), 'name')));

		$this->expectException(StateException::class);
		$template->applyToRecordState(RecordState::new($users));
	}

	public function testApplyToRecordStateWithAlreadyStateTargetedFieldThrows(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$template->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState(RecordState::new($users), 'name')));

		$this->expectException(StateException::class);
		$template->applyToRecordState(RecordState::new($users));
	}

	public function testTwoApplicationsToTwoNewStatesProduceDifferentRecordHashes(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$template->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name')));

		$first = $template->applyToRecordState(RecordState::new($users, ['name' => 'A1']));
		$second = $template->applyToRecordState(RecordState::new($users, ['name' => 'A2']));

		self::assertNotSame(
			$first->getField('name')->getField()->getRecordHash(),
			$second->getField('name')->getField()->getRecordHash()
		);
	}

	private function fieldBinding(string $path, bool $writable = true): RepresentationFieldBinding
	{
		return new RepresentationFieldBinding($path, new RecordFieldRef($this->users(), $path), $writable);
	}

	private function relationBinding(string $path): RepresentationRelationBinding
	{
		return new RepresentationRelationBinding(
			$path,
			$path,
			RepresentationRelationCardinality::MANY,
			new RepresentationBinding(),
			RelationCollectionState::PARTIALLY_LOADED
		);
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
	}
}
