<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationBindingTest extends TestCase
{
	use OrmFixture;

	public function testRequiresRootCollection(): void
	{
		$users = $this->users();
		$binding = new RepresentationBinding($users);

		self::assertSame($users, $binding->getCollection());
		self::assertSame('users', $binding->getCollectionName());
	}

	public function testAddFieldAndGetFieldByPath(): void
	{
		$binding = new RepresentationBinding($this->users());
		$fieldBinding = $this->fieldBinding('name');

		$binding->addField($fieldBinding);

		self::assertTrue($binding->hasField('name'));
		self::assertSame($fieldBinding, $binding->getField('name'));
		self::assertSame([$fieldBinding], $binding->getFields());
	}

	public function testAddRelationAndGetRelationByPath(): void
	{
		$binding = new RepresentationBinding($this->users());
		$relation = $this->relationBinding('posts');

		$binding->addRelation($relation);

		self::assertTrue($binding->hasRelation('posts'));
		self::assertSame($relation, $binding->getRelation('posts'));
		self::assertSame([$relation], $binding->getRelations());
	}

	public function testRelatedBindingIsRootedAtRelatedCollection(): void
	{
		$binding = new RepresentationBinding($this->users());
		$relation = $this->relationBinding('posts');
		$binding->addRelation($relation);

		self::assertSame('posts', $relation->getRelatedBinding()->getCollectionName());
	}

	public function testHasPathWorksAcrossFieldsAndRelations(): void
	{
		$binding = new RepresentationBinding($this->users());
		$binding->addField($this->fieldBinding('name'));
		$binding->addRelation($this->relationBinding('posts'));

		self::assertTrue($binding->hasPath('name'));
		self::assertTrue($binding->hasPath('posts'));
		self::assertFalse($binding->hasPath('missing'));
	}

	public function testGetPathsPreservesInsertionOrderForFieldsAndRelations(): void
	{
		$binding = new RepresentationBinding($this->users());
		$binding->addField($this->fieldBinding('name'));
		$binding->addRelation($this->relationBinding('posts'));
		$binding->addField($this->fieldBinding('email'));

		self::assertSame(['name', 'posts', 'email'], $binding->getPaths());
	}

	public function testDuplicateFieldPathThrows(): void
	{
		$binding = new RepresentationBinding($this->users());
		$binding->addField($this->fieldBinding('name'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('name');
		$binding->addField($this->fieldBinding('name'));
	}

	public function testDuplicateRelationPathThrows(): void
	{
		$binding = new RepresentationBinding($this->users());
		$binding->addRelation($this->relationBinding('posts'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$binding->addRelation($this->relationBinding('posts'));
	}

	public function testDuplicatePathAcrossFieldAndRelationThrows(): void
	{
		$binding = new RepresentationBinding($this->users());
		$binding->addField($this->fieldBinding('posts'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$binding->addRelation($this->relationBinding('posts'));
	}

	public function testWritableFilterWorks(): void
	{
		$binding = new RepresentationBinding($this->users());
		$writable = $this->fieldBinding('name');
		$binding->addField($writable);
		$binding->addField($this->fieldBinding('upperName', false));

		self::assertSame([$writable], $binding->getWritableFieldBindings());
	}

	public function testReadOnlyFilterWorks(): void
	{
		$binding = new RepresentationBinding($this->users());
		$binding->addField($this->fieldBinding('name'));
		$readOnly = $this->fieldBinding('upperName', false);
		$binding->addField($readOnly);

		self::assertSame([$readOnly], $binding->getReadOnlyFieldBindings());
	}

	public function testRelationBindingsAreNotReturnedByFieldFilters(): void
	{
		$binding = new RepresentationBinding($this->users());
		$writable = $this->fieldBinding('name');
		$readOnly = $this->fieldBinding('upperName', false);
		$binding->addField($writable);
		$binding->addField($readOnly);
		$binding->addRelation($this->relationBinding('posts'));

		self::assertSame([$writable], $binding->getWritableFieldBindings());
		self::assertSame([$readOnly], $binding->getReadOnlyFieldBindings());
	}

	public function testFieldInsertionOrderIsPreserved(): void
	{
		$binding = new RepresentationBinding($this->users());
		$name = $this->fieldBinding('name');
		$email = $this->fieldBinding('email');

		$binding->addField($name);
		$binding->addField($email);

		self::assertSame([$name, $email], $binding->getFields());
	}

	public function testFlatHeterogeneousFieldsCanTargetCollectionsOtherThanRoot(): void
	{
		$users = $this->users();
		$companies = $this->posts();
		$binding = new RepresentationBinding($users);
		$root = new RepresentationFieldBinding('name', $users, 'name');
		$foreign = new RepresentationFieldBinding('companyName', $companies, 'title', sourcePath: ['company']);
		$binding->addField($root);
		$binding->addField($foreign);

		self::assertSame('users', $binding->getCollectionName());
		self::assertSame($root, $binding->getFieldForSource([], 'name'));
		self::assertSame($foreign, $binding->getFieldForSource(['company'], 'title'));
	}

	public function testFindsStructuralFieldForSourcePathAndField(): void
	{
		$users = $this->users();
		$field = new RepresentationFieldBinding('displayName', $users, 'name');
		$binding = new RepresentationBinding($users);
		$binding->addField($field);

		self::assertSame($field, $binding->getFieldForSource([], 'name'));
		self::assertTrue($binding->hasFieldForSource([], 'name'));
		self::assertFalse($binding->hasFieldForSource([], 'email'));
	}

	public function testSameTerminalCollectionFieldsStayDistinctBySourcePath(): void
	{
		$users = $this->users();
		$binding = new RepresentationBinding($users);
		$rootName = new RepresentationFieldBinding('name', $users, 'name');
		$managerName = new RepresentationFieldBinding('managerName', $users, 'name', sourcePath: ['manager']);
		$binding->addField($rootName);
		$binding->addField($managerName);

		self::assertSame($rootName, $binding->getFieldForSource([], 'name'));
		self::assertSame($managerName, $binding->getFieldForSource(['manager'], 'name'));
		self::assertNotSame(
			$binding->getFieldForSource([], 'name'),
			$binding->getFieldForSource(['manager'], 'name'),
		);
	}

	public function testFieldBindingSourcePathKeyUsesSharedEncoder(): void
	{
		self::assertSame('', RepresentationFieldBinding::sourcePathKey([]));
		self::assertSame('manager.profile', RepresentationFieldBinding::sourcePathKey(['manager', 'profile']));
	}

	private function fieldBinding(string $path, bool $writable = true): RepresentationFieldBinding
	{
		return new RepresentationFieldBinding($path, $this->users(), $path, $writable);
	}

	private function relationBinding(string $path): RepresentationRelationBinding
	{
		return new RepresentationRelationBinding(
			$path,
			$this->users(),
			$path,
			new RepresentationBinding($this->relatedCollection($path)),
		);
	}

	private function relatedCollection(string $relationName): CollectionInterface
	{
		return match ($relationName) {
			'profile' => $this->profiles(),
			default => $this->posts(),
		};
	}
}
