<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Binding;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionCompiler;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityMap;
use ON\Data\ORM\Query\MutableQueryResultTracker;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RepresentationState;
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

	public function testMutableFetchAllTracksAllRootObjectsWithEquivalentBindings(): void
	{
		$session = $this->session();
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new MutableExportRecordingExecutor(),
		);

		$rows = $query->to(stdClass::class)->mutable($session)->fetchAll();

		$states = $this->representationStates($session, $rows[0], $rows[1]);

		self::assertCount(2, $states);
		self::assertSame($states[0]->getBinding()->getPaths(), $states[1]->getBinding()->getPaths());
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
		$binding = (new ProjectionCompiler())->compile($query);
		(new MutableQueryResultTracker())->trackOne($session, $binding, new ProjectionIdentityMap(), $user, ['id' => 1, 'name' => 'Ada']);

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
	public function testTrackAllReusesCompiledTemplateForRelatedBindings(): void
	{
		$registry = $this->makeRegistryWithPosts();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users);
		$query->select($query->name);
		$query->posts->fields('title');

		$tracker = new MutableQueryResultTracker();
		$session = new Session(new RecordingCommandExecutor());
		$binding = (new ProjectionCompiler())->compile($query);

		$first = $this->userWithPosts(1, 'Ada', 10, 'Hello');
		$second = $this->userWithPosts(2, 'Grace', 11, 'World');

		$tracker->trackAll($session, $binding, new ProjectionIdentityMap(), [$first, $second], [
			['id' => 1, 'name' => 'Ada'],
			['id' => 2, 'name' => 'Grace'],
		]);

		$firstState = $session->getRepresentations()->get($first);
		$secondState = $session->getRepresentations()->get($second);
		self::assertInstanceOf(RepresentationState::class, $firstState);
		self::assertInstanceOf(RepresentationState::class, $secondState);

		self::assertSame(
			$firstState->getBinding()->getRelation('posts')->getRelatedBinding(),
			$secondState->getBinding()->getRelation('posts')->getRelatedBinding(),
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
		$binding = (new ProjectionCompiler())->compile($query);

		$first = $this->userObject(1, 'Ada');
		$second = $this->userObject(2, 'Grace');

		$tracker->trackAll($session, $binding, new ProjectionIdentityMap(), [$first, $second], [
			['id' => 1, 'name' => 'Ada'],
			['id' => 2, 'name' => 'Grace'],
		]);

		$firstState = $session->getRepresentations()->get($first);
		$secondState = $session->getRepresentations()->get($second);

		self::assertInstanceOf(RepresentationState::class, $firstState);
		self::assertInstanceOf(RepresentationState::class, $secondState);
		self::assertNotSame($firstState, $secondState);
	}

	public function testTrackOneTracksObjectWithPrecompiledBinding(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users);
		$query->select($query->name);

		$tracker = new MutableQueryResultTracker();
		$session = new Session(new RecordingCommandExecutor());
		$user = $this->userObject(1, 'Ada');
		$binding = (new ProjectionCompiler())->compile($query);

		$tracker->trackOne($session, $binding, new ProjectionIdentityMap(), $user, ['id' => 1, 'name' => 'Ada']);

		self::assertTrue($session->getRepresentations()->has($user));
		self::assertTrue($session->getRepresentations()->get($user)?->getBinding()->hasField('name'));
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
