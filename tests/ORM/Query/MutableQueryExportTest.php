<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSchemaCompiler;
use ON\Data\ORM\Representation\State\Query\MutableQueryResultTracker;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use ON\Data\Query\Exception\ObjectExportException;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class MutableQueryExportTest extends TestCase
{
	public function testToStdClassFetchAllRemainsUntracked(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$rows = $query->to(stdClass::class)->fetchAll();

		self::assertInstanceOf(stdClass::class, $rows[0]);
		self::assertFalse($query->isMutable());
		self::assertNull($query->getSession());
	}

	public function testMutableWithoutToThrows(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Mutable query export requires object export; call to(stdClass::class) before mutable().');

		$query->mutable($this->session());
	}

	public function testMutableFetchAllReturnsStdClassObjects(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$rows = $query->to(stdClass::class)->mutable($this->session())->fetchAll();

		self::assertCount(2, $rows);
		self::assertInstanceOf(stdClass::class, $rows[0]);
		self::assertSame(1, $rows[0]->id);
		self::assertSame('Ada', $rows[0]->name);
		self::assertInstanceOf(stdClass::class, $rows[1]);
		self::assertSame(2, $rows[1]->id);
	}

	public function testComputedProjectionExpressionHydratesWithoutSchemaOrStateTracking(): void
	{
		$session = $this->session();
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery(
			$users,
			new MutableExportRecordingExecutor(
				fetchAllRows: [
					['id' => 1, 'name' => 'Ada', 'postCount' => 2],
				],
				fetchOneRow: ['id' => 1, 'name' => 'Ada', 'postCount' => 2],
			),
		);
		$query->select($query->id, $query->name, $query->id->count()->as('postCount'));

		$row = $query->to(stdClass::class)->mutable($session)->fetchOne();

		self::assertInstanceOf(stdClass::class, $row);
		self::assertSame(1, $row->id);
		self::assertSame('Ada', $row->name);
		self::assertSame(2, $row->postCount);

		$state = $session->getRepresentations()->get($row);
		self::assertInstanceOf(RepresentationState::class, $state);

		$schema = $state->getSchema();
		self::assertTrue($schema->hasFieldForSource([], 'id'));
		self::assertTrue($schema->hasFieldForSource([], 'name'));
		self::assertFalse($schema->hasField('postCount'));

		self::assertTrue($state->hasFieldItem('id'));
		self::assertTrue($state->hasFieldItem('name'));
		self::assertFalse($state->hasFieldItem('postCount'));
	}

	public function testMutableFetchAllTracksAllRootObjectsWithEquivalentSchemas(): void
	{
		$session = $this->session();
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$rows = $query->to(stdClass::class)->mutable($session)->fetchAll();

		$states = $this->representationStates($session, $rows[0], $rows[1]);

		self::assertCount(2, $states);
		self::assertSame($states[0]->getSchema()->getPaths(), $states[1]->getSchema()->getPaths());
	}

	public function testEachRootObjectGetsItsOwnRepresentationState(): void
	{
		$session = $this->session();
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$rows = $query->to(stdClass::class)->mutable($session)->fetchAll();

		$first = $session->getRepresentations()->get($rows[0]);
		$second = $session->getRepresentations()->get($rows[1]);

		self::assertInstanceOf(RepresentationState::class, $first);
		self::assertInstanceOf(RepresentationState::class, $second);
		self::assertNotSame($first, $second);
	}

	public function testNestedRelationObjectsAreTracked(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users);
		$query->select($query->name);
		$query->posts->fields('title');

		$user = new stdClass();
		$user->id = 1;
		$user->name = 'Ada';
		$post = new stdClass();
		$post->id = 10;
		$post->title = 'Hello';
		$user->posts = [$post];

		$session = new Session(new RecordingCommandExecutor());
		$compilation = (new QueryRepresentationSchemaCompiler())->compileResult($query);
		(new MutableQueryResultTracker())->trackOne($session, $compilation, $user, ['id' => 1, 'name' => 'Ada']);

		self::assertTrue($session->getRepresentations()->has($user));
		self::assertTrue($session->getRepresentations()->has($post));
	}

	public function testProvidedSessionIsUsedWhenPassed(): void
	{
		$session = $this->session();
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$rows = $query->to(stdClass::class)->mutable($session)->fetchAll();

		self::assertSame($session, $query->getSession());
		self::assertTrue($session->getRepresentations()->has($rows[0]));
		self::assertTrue($session->getRepresentations()->has($rows[1]));
	}

	public function testMutableFetchOneTracksSingleObject(): void
	{
		$session = $this->session();
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$row = $query->to(stdClass::class)->mutable($session)->fetchOne();

		self::assertInstanceOf(stdClass::class, $row);
		self::assertSame($session, $query->getSession());
		self::assertTrue($session->getRepresentations()->has($row));
	}

	public function testMutableFetchOneReturningNullDoesNotTrack(): void
	{
		$session = $this->session();
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(fetchOneRow: null),
		);

		self::assertNull($query->to(stdClass::class)->mutable($session)->fetchOne());
		self::assertSame($session, $query->getSession());
		self::assertSame([], iterator_to_array($session->getRepresentations()->getAll(), false));
	}

	public function testCopyPreservesMutableAndSessionState(): void
	{
		$session = $this->session();
		$query = new SelectQuery($this->makeRegistry()->getCollection('users'));
		$query->to(stdClass::class)->mutable($session);

		$copy = $query->copy();

		self::assertTrue($copy->isMutable());
		self::assertSame(stdClass::class, $copy->getResultClass());
		self::assertSame($session, $copy->getSession());
	}

	public function testUnsupportedClassCannotReachMutable(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Object export class "App\\User" does not exist.');

		$query->to('App\\User')->mutable($this->session());
	}

	private function session(): Session
	{
		return new Session(new RecordingCommandExecutor());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end();

		$registry->getCollection('users')
			->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('user_id');

		return $registry;
	}

	/**
	 * @return list<RepresentationState>
	 */
	private function representationStates(Session $session, object ...$representations): array
	{
		$states = [];

		foreach ($representations as $representation) {
			$state = $session->getRepresentations()->get($representation);
			self::assertInstanceOf(RepresentationState::class, $state);
			$states[] = $state;
		}

		return $states;
	}
}

final class MutableQueryResultTrackerTest extends TestCase
{
	public function testTrackAllReusesCompiledTemplateForRelatedSchemas(): void
	{
		$registry = $this->makeRegistryWithPosts();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users);
		$query->select($query->name);
		$query->posts->fields('title');

		$tracker = new MutableQueryResultTracker();
		$session = new Session(new RecordingCommandExecutor());
		$compilation = (new QueryRepresentationSchemaCompiler())->compileResult($query);

		$first = $this->userWithPosts(1, 'Ada', 10, 'Hello');
		$second = $this->userWithPosts(2, 'Grace', 11, 'World');

		$tracker->trackAll($session, $compilation, [$first, $second], [
			['id' => 1, 'name' => 'Ada'],
			['id' => 2, 'name' => 'Grace'],
		]);

		$firstState = $session->getRepresentations()->get($first);
		$secondState = $session->getRepresentations()->get($second);
		self::assertInstanceOf(RepresentationState::class, $firstState);
		self::assertInstanceOf(RepresentationState::class, $secondState);

		self::assertSame(
			$firstState->getSchema()->getRelation('posts')->getRelatedSchema(),
			$secondState->getSchema()->getRelation('posts')->getRelatedSchema(),
		);
	}

	public function testTrackAllCreatesDistinctRepresentationStates(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users);
		$query->select($query->name);

		$tracker = new MutableQueryResultTracker();
		$session = new Session(new RecordingCommandExecutor());
		$compilation = (new QueryRepresentationSchemaCompiler())->compileResult($query);

		$first = $this->userObject(1, 'Ada');
		$second = $this->userObject(2, 'Grace');

		$tracker->trackAll($session, $compilation, [$first, $second], [
			['id' => 1, 'name' => 'Ada'],
			['id' => 2, 'name' => 'Grace'],
		]);

		$firstState = $session->getRepresentations()->get($first);
		$secondState = $session->getRepresentations()->get($second);

		self::assertInstanceOf(RepresentationState::class, $firstState);
		self::assertInstanceOf(RepresentationState::class, $secondState);
		self::assertNotSame($firstState, $secondState);
	}

	public function testTrackOneTracksObjectWithPrecompiledSchema(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users);
		$query->select($query->name);

		$tracker = new MutableQueryResultTracker();
		$session = new Session(new RecordingCommandExecutor());
		$user = $this->userObject(1, 'Ada');
		$compilation = (new QueryRepresentationSchemaCompiler())->compileResult($query);

		$tracker->trackOne($session, $compilation, $user, ['id' => 1, 'name' => 'Ada']);

		self::assertTrue($session->getRepresentations()->has($user));
		self::assertTrue($session->getRepresentations()->get($user)?->getSchema()->hasField('name'));
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end();

		$registry->getCollection('users')
			->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('user_id');

		return $registry;
	}

	private function makeRegistryWithPosts(): Registry
	{
		return $this->makeRegistry();
	}

	private function userWithPosts(int $userId, string $name, int $postId, string $title): stdClass
	{
		$user = $this->userObject($userId, $name);
		$post = new stdClass();
		$post->id = $postId;
		$post->title = $title;
		$user->posts = [$post];

		return $user;
	}

	private function userObject(int $id, string $name): stdClass
	{
		$user = new stdClass();
		$user->id = $id;
		$user->name = $name;

		return $user;
	}
}

final class MutableExportRecordingExecutor implements QueryExecutorInterface
{
	/**
	 * @param list<array<string, mixed>> $fetchAllRows
	 */
	public function __construct(
		private readonly array $fetchAllRows = [
			['id' => 1, 'name' => 'Ada'],
			['id' => 2, 'name' => 'Grace'],
		],
		private readonly ?array $fetchOneRow = ['id' => 1, 'name' => 'Ada'],
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		return $this->fetchAllRows;
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return $this->fetchOneRow;
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAllRows;
	}
}
