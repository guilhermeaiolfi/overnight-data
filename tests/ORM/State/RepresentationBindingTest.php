<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationExpressionBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationBindingTest extends TestCase
{
	use OrmFixture;

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

	public function testFindsStructuralFieldForCollectionAndField(): void
	{
		$users = $this->users();
		$field = new RepresentationFieldBinding('displayName', $users, 'name');
		$binding = new RepresentationBinding();
		$binding->addField($field);

		self::assertSame($field, $binding->getFieldFor($users, 'name'));
		self::assertSame($field, $binding->getFieldFor('users', 'name'));
		self::assertTrue($binding->hasFieldFor($users, 'name'));
		self::assertFalse($binding->hasFieldFor($users, 'email'));
	}

	private function fieldBinding(string $path, bool $writable = true): RepresentationFieldBinding
	{
		return new RepresentationFieldBinding($path, $this->users(), $path, $writable);
	}

	private function relationBinding(string $path): RepresentationRelationBinding
	{
		return new RepresentationRelationBinding(
			$path,
			$this->users(), $path,
			new RepresentationBinding(),
		);
	}
}
