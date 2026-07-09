<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationSchemaTest extends TestCase
{
	use OrmFixture;

	public function testRequiresRootCollection(): void
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);

		self::assertSame($users, $schema->getCollection());
		self::assertSame('users', $schema->getCollectionName());
	}

	public function testAddFieldAndGetFieldByPath(): void
	{
		$schema = new RepresentationSchema($this->users());
		$fieldSchema = $this->fieldSchema('name');

		$schema->addField($fieldSchema);

		self::assertTrue($schema->hasField('name'));
		self::assertSame($fieldSchema, $schema->getField('name'));
		self::assertSame([$fieldSchema], $schema->getFields());
	}

	public function testAddRelationAndGetRelationByPath(): void
	{
		$schema = new RepresentationSchema($this->users());
		$relation = $this->relationSchema('posts');

		$schema->addRelation($relation);

		self::assertTrue($schema->hasRelation('posts'));
		self::assertSame($relation, $schema->getRelation('posts'));
		self::assertSame([$relation], $schema->getRelations());
	}

	public function testRelatedSchemaIsRootedAtRelatedCollection(): void
	{
		$schema = new RepresentationSchema($this->users());
		$relation = $this->relationSchema('posts');
		$schema->addRelation($relation);

		self::assertSame('posts', $relation->getRelatedSchema()->getCollectionName());
	}

	public function testHasPathWorksAcrossFieldsAndRelations(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField($this->fieldSchema('name'));
		$schema->addRelation($this->relationSchema('posts'));

		self::assertTrue($schema->hasPath('name'));
		self::assertTrue($schema->hasPath('posts'));
		self::assertFalse($schema->hasPath('missing'));
	}

	public function testGetPathsPreservesInsertionOrderForFieldsAndRelations(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField($this->fieldSchema('name'));
		$schema->addRelation($this->relationSchema('posts'));
		$schema->addField($this->fieldSchema('email'));

		self::assertSame(['name', 'posts', 'email'], $schema->getPaths());
	}

	public function testDuplicateFieldPathThrows(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField($this->fieldSchema('name'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('name');
		$schema->addField($this->fieldSchema('name'));
	}

	public function testDuplicateRelationPathThrows(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addRelation($this->relationSchema('posts'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$schema->addRelation($this->relationSchema('posts'));
	}

	public function testDuplicatePathAcrossFieldAndRelationThrows(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField($this->fieldSchema('posts'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$schema->addRelation($this->relationSchema('posts'));
	}

	public function testWritableFilterWorks(): void
	{
		$schema = new RepresentationSchema($this->users());
		$writable = $this->fieldSchema('name');
		$schema->addField($writable);
		$schema->addField($this->fieldSchema('upperName', false));

		self::assertSame([$writable], $schema->getWritableFieldSchemas());
	}

	public function testReadOnlyFilterWorks(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField($this->fieldSchema('name'));
		$readOnly = $this->fieldSchema('upperName', false);
		$schema->addField($readOnly);

		self::assertSame([$readOnly], $schema->getReadOnlyFieldSchemas());
	}

	public function testRelationSchemasAreNotReturnedByFieldFilters(): void
	{
		$schema = new RepresentationSchema($this->users());
		$writable = $this->fieldSchema('name');
		$readOnly = $this->fieldSchema('upperName', false);
		$schema->addField($writable);
		$schema->addField($readOnly);
		$schema->addRelation($this->relationSchema('posts'));

		self::assertSame([$writable], $schema->getWritableFieldSchemas());
		self::assertSame([$readOnly], $schema->getReadOnlyFieldSchemas());
	}

	public function testFieldInsertionOrderIsPreserved(): void
	{
		$schema = new RepresentationSchema($this->users());
		$name = $this->fieldSchema('name');
		$email = $this->fieldSchema('email');

		$schema->addField($name);
		$schema->addField($email);

		self::assertSame([$name, $email], $schema->getFields());
	}

	public function testFlatHeterogeneousFieldsCanTargetCollectionsOtherThanRoot(): void
	{
		$users = $this->users();
		$companies = $this->posts();
		$schema = new RepresentationSchema($users);
		$root = new RepresentationFieldSchema('name', $users, 'name');
		$foreign = new RepresentationFieldSchema('companyName', $companies, 'title', sourcePath: ['company']);
		$schema->addField($root);
		$schema->addField($foreign);

		self::assertSame('users', $schema->getCollectionName());
		self::assertSame($root, $schema->getFieldForSource([], 'name'));
		self::assertSame($foreign, $schema->getFieldForSource(['company'], 'title'));
	}

	public function testFindsStructuralFieldForSourcePathAndField(): void
	{
		$users = $this->users();
		$field = new RepresentationFieldSchema('displayName', $users, 'name');
		$schema = new RepresentationSchema($users);
		$schema->addField($field);

		self::assertSame($field, $schema->getFieldForSource([], 'name'));
		self::assertTrue($schema->hasFieldForSource([], 'name'));
		self::assertFalse($schema->hasFieldForSource([], 'email'));
	}

	public function testSameTerminalCollectionFieldsStayDistinctBySourcePath(): void
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);
		$rootName = new RepresentationFieldSchema('name', $users, 'name');
		$managerName = new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']);
		$schema->addField($rootName);
		$schema->addField($managerName);

		self::assertSame($rootName, $schema->getFieldForSource([], 'name'));
		self::assertSame($managerName, $schema->getFieldForSource(['manager'], 'name'));
		self::assertNotSame(
			$schema->getFieldForSource([], 'name'),
			$schema->getFieldForSource(['manager'], 'name'),
		);
	}

	public function testFieldSchemaSourcePathKeyUsesSharedEncoder(): void
	{
		self::assertSame('', RepresentationFieldSchema::sourcePathKey([]));
		self::assertSame('manager.profile', RepresentationFieldSchema::sourcePathKey(['manager', 'profile']));
	}

	private function fieldSchema(string $path, bool $writable = true): RepresentationFieldSchema
	{
		return new RepresentationFieldSchema($path, $this->users(), $path, $writable);
	}

	private function relationSchema(string $path): RepresentationRelationSchema
	{
		return new RepresentationRelationSchema(
			$path,
			$this->users(),
			$path,
			new RepresentationSchema($this->relatedCollection($path)),
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
