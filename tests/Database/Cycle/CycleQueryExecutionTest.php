<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\Query\QueryParameters;
use DateTimeImmutable;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Database;
use ON\Data\Database\Exception\QueryExecutionException;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Definition\Registry;
use ON\Data\Query\Expression\SubqueryExpression;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\ON\Data\Fixture\CustomRelation;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleQueryExecutionTest extends TestCase
{
	private string $databasePath;

	private string $dsn;

	private ?Registry $registry = null;

	private ?Database $database = null;

	protected function setUp(): void
	{
		$this->databasePath = tempnam(sys_get_temp_dir(), 'ondata-cycle-');
		$this->dsn = 'sqlite:' . str_replace('\\', '/', $this->databasePath);
		$this->registry = $this->makeRegistry();

		$this->seedDatabase();

		$this->database = Database::connect(ConnectionConfig::dsn('sqlite', $this->dsn));
	}

	protected function tearDown(): void
	{
		$this->database = null;
		$this->registry = null;
		gc_collect_cycles();

		if (is_file($this->databasePath)) {
			@unlink($this->databasePath);
		}
	}

	public function testFactoryBindsExecutableQueriesAndMapsFieldsThroughCycle(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		self::assertTrue($users->isExecutable());

		$rows = $users
			->select(
				$users->id,
				$users->name,
				$users->email,
				$users->createdAt,
				$users->profile,
			)
			->where(x()->eq($users->active, true))
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertCount(2, $rows);
		self::assertSame('ada@example.test', $rows[0]['email']);
		self::assertInstanceOf(DateTimeImmutable::class, $rows[0]['createdAt']);
		self::assertSame(['role' => 'admin'], $rows[0]['profile']);
	}

	public function testFetchOneDoesNotMutateOriginalQueryState(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users
			->select($users->id, $users->name)
			->orderBy($users->id->desc())
			->offset(1);

		$row = $users->fetchOne();

		self::assertSame(2, $row['id']);
		self::assertNull($users->getLimit());
		self::assertSame(1, $users->getOffset());
	}

	public function testFetchOnePreservesExplicitLimitZero(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$row = $users
			->select($users->id)
			->limit(0)
			->fetchOne();

		self::assertNull($row);
		self::assertSame(0, $users->getLimit());
	}

	public function testRawSqlCanBeSelectedAndAliased(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select(x()->rawSql('UPPER(name)')->as('upper_name'))
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['upper_name' => 'ADA'],
			['upper_name' => 'GRACE'],
			['upper_name' => 'LINUS'],
		], $rows);
	}

	public function testRawSqlCanBeUsedInComparisons(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->name)
			->where(x()->eq(x()->rawSql('LOWER(name)'), 'ada'))
			->fetchAll();

		self::assertSame([['name' => 'Ada']], $rows);
	}

	public function testRawSqlParametersAreBoundAsValues(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->name)
			->where(x()->eq(x()->rawSql('name || ?', [' Lovelace']), 'Ada Lovelace'))
			->fetchAll();

		self::assertSame([['name' => 'Ada']], $rows);
	}

	public function testIterateYieldsMappedRowsLazily(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = iterator_to_array(
			$users
				->select($users->id, $users->createdAt)
				->orderBy($users->id->asc())
				->iterate(),
			false,
		);

		self::assertCount(3, $rows);
		self::assertInstanceOf(DateTimeImmutable::class, $rows[2]['createdAt']);
	}

	public function testAggregatesGroupingHavingSortingAndPaginationExecute(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));

		$rows = $posts
			->select(
				$posts->userId,
				$posts->amount->sum()->as('total'),
			)
			->groupBy($posts->userId)
			->having(x()->gt($posts->amount->sum(), 10))
			->orderBy($posts->amount->sum()->desc())
			->limit(1)
			->fetchAll();

		self::assertSame([[
			'userId' => 1,
			'total' => 25.5,
		]], $rows);
	}

	public function testNullConditionsPreserveExpressionParameters(): void
	{
		$isNullUsers = $this->database->query($this->registry->getCollection('users'));
		$isNull = $isNullUsers
			->select($isNullUsers->id)
			->where(x()->isNull(x()->coalesce($isNullUsers->nickname, 'fallback')))
			->fetchAll();

		$isNotNullUsers = $this->database->query($this->registry->getCollection('users'));
		$isNotNull = $isNotNullUsers
			->select($isNotNullUsers->id)
			->where(x()->isNotNull(x()->coalesce($isNotNullUsers->nickname, 'fallback')))
			->orderBy($isNotNullUsers->id->asc())
			->fetchAll();

		self::assertSame([], $isNull);
		self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $isNotNull);
	}

	public function testExistsAndScalarSubqueriesExecute(): void
	{
		$usersDefinition = $this->registry->getCollection('users');
		$postsDefinition = $this->registry->getCollection('posts');
		$users = $this->database->query($usersDefinition);
		$posts = query($postsDefinition, function (SelectQuery $query) use ($users): void {
			$query->where(
				x()->eq($query->userId, $users->id),
				x()->eq($query->published, true),
			);
		});

		$rows = $users
			->select(
				$users->name,
				(new SubqueryExpression(query($postsDefinition, function (SelectQuery $query) use ($users): void {
					$query
						->select($query->id->count())
						->where(x()->eq($query->userId, $users->id));
				})))->as('post_count'),
			)
			->where(x()->exists($posts))
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'post_count' => 2],
			['name' => 'Grace', 'post_count' => 1],
		], $rows);
	}

	public function testInNotInNotOrAndNotExistsExecute(): void
	{
		$usersDefinition = $this->registry->getCollection('users');
		$postsDefinition = $this->registry->getCollection('posts');
		$postUserIds = query($postsDefinition, fn (SelectQuery $query) => $query->select($query->userId));

		$inUsers = $this->database->query($usersDefinition);
		$inRows = $inUsers
			->select($inUsers->id)
			->where(x()->in($inUsers->id, [1, 3]))
			->orderBy($inUsers->id->asc())
			->fetchAll();

		$subqueryUsers = $this->database->query($usersDefinition);
		$subqueryRows = $subqueryUsers
			->select($subqueryUsers->id)
			->where(x()->in($subqueryUsers->id, $postUserIds))
			->orderBy($subqueryUsers->id->asc())
			->fetchAll();

		$notInUsers = $this->database->query($usersDefinition);
		$notInRows = $notInUsers
			->select($notInUsers->id)
			->where(x()->notIn($notInUsers->id, [1, 2]))
			->fetchAll();

		$notUsers = $this->database->query($usersDefinition);
		$notRows = $notUsers
			->select($notUsers->id)
			->where(
				x()->not(
					x()->or(
						x()->eq($notUsers->id, 1),
						x()->eq($notUsers->id, 2),
					),
				),
			)
			->fetchAll();

		$notExistsUsers = $this->database->query($usersDefinition);
		$publishedPosts = query($postsDefinition, function (SelectQuery $query) use ($notExistsUsers): void {
			$query
				->where(x()->eq($query->userId, $notExistsUsers->id))
				->where(x()->eq($query->published, true));
		});
		$notExistsRows = $notExistsUsers
			->select($notExistsUsers->id)
			->where(x()->notExists($publishedPosts))
			->fetchAll();

		self::assertSame([['id' => 1], ['id' => 3]], $inRows);
		self::assertSame([['id' => 1], ['id' => 2]], $subqueryRows);
		self::assertSame([['id' => 3]], $notInRows);
		self::assertSame([['id' => 3]], $notRows);
		self::assertSame([['id' => 3]], $notExistsRows);
	}

	public function testCountStarCountDistinctAndValueOperationsExecute(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$users = $this->database->query($this->registry->getCollection('users'));

		$postCounts = $posts
			->select(
				$posts->star()->count()->as('all_count'),
				$posts->userId->countDistinct()->as('distinct_users'),
			)
			->fetchOne();

		$userRows = $users
			->select(
				$users->name->upper()->as('upper_name'),
				$users->email->lower()->as('lower_email'),
				x()->concat($users->name, ' <', $users->email, '>')->as('label'),
				x()->coalesce($users->nickname, 'n/a')->as('nickname_value'),
				x()->add($users->score, 1)->as('score_plus_one'),
			)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame(['all_count' => 3, 'distinct_users' => 2], $postCounts);
		self::assertSame('ADA', $userRows[0]['upper_name']);
		self::assertSame('ada@example.test', $userRows[0]['lower_email']);
		self::assertSame('Ada <ada@example.test>', $userRows[0]['label']);
		self::assertSame('n/a', $userRows[2]['nickname_value']);
		self::assertSame(11, $userRows[0]['score_plus_one']);
	}

	public function testWindowRowNumberCanBeFilteredThroughDerivedSource(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$position = x()->fn()
			->rowNumber()
			->over(
				partitionBy: $posts->userId,
				orderBy: [
					$posts->id->desc(),
				],
			)
			->as('__rank');

		$inner = $posts->select($posts->all(), $position);
		$ranked = $inner->as('ranked_posts');

		$rows = $this->database->query($ranked)
			->select($ranked->all())
			->where($ranked->field('__rank')->eq(1))
			->fetchAll();

		self::assertSame([
			[
				'id' => 2,
				'userId' => 1,
				'title' => 'World',
				'amount' => 15.5,
				'published' => 0,
				'__rank' => 1,
			],
			[
				'id' => 3,
				'userId' => 2,
				'title' => 'Graph',
				'amount' => 7.5,
				'published' => 1,
				'__rank' => 1,
			],
		], $rows);
	}

	public function testDerivedSourceSqlQuotesFieldsAsQualifiedIdentifiers(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$position = x()->fn()
			->rowNumber()
			->over(
				partitionBy: $posts->userId,
				orderBy: $posts->id->desc(),
			)
			->as('__rank');

		$ranked = $posts
			->select($posts->all(), $position)
			->as('ranked_posts');

		$query = $this->database->query($ranked)
			->select($ranked->all())
			->where($ranked->field('__rank')->eq(1));

		$sql = $this->compileSql($query);

		self::assertMatchesRegularExpression('/FROM\s+\(\s*SELECT/', $sql);
		self::assertStringContainsString('AS "ranked_posts"', $sql);
		self::assertStringContainsString('"ranked_posts".*', $sql);
		self::assertStringContainsString('"ranked_posts"."__rank"', $sql);
		self::assertStringNotContainsString('"ranked_posts.__rank"', $sql);
	}

	public function testAutomaticDerivedSourceAliasIsStableInGeneratedSql(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$position = x()->fn()
			->rowNumber()
			->over(
				partitionBy: $posts->userId,
				orderBy: $posts->id->desc(),
			)
			->as('__rank');

		$ranked = $posts
			->select($posts->all(), $position)
			->as();

		$query = $this->database->query($ranked)
			->select($ranked->all())
			->where($ranked->field('__rank')->eq(1));

		$sql = $this->compileSql($query);

		self::assertMatchesRegularExpression('/AS "d\d+"/', $sql);
		self::assertMatchesRegularExpression('/"d\d+"\.\*/', $sql);
		self::assertMatchesRegularExpression('/"d\d+"\."__rank" = \?/', $sql);
	}

	public function testRankDenseRankCompositePartitionAndAutomaticDerivedAliasExecute(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$rank = x()->fn()
			->rank()
			->over(
				partitionBy: [$posts->userId, $posts->published],
				orderBy: $posts->amount->desc(),
			)
			->as('rank_value');
		$denseRank = x()->fn()
			->denseRank()
			->over(orderBy: [$posts->amount->desc(), $posts->id->asc()])
			->as('dense_value');

		$inner = $posts->select($posts->id, $rank, $denseRank);
		$ranked = $inner->as();

		$rows = $this->database->query($ranked)
			->select(
				$ranked->field('id'),
				$ranked->field('rank_value'),
				$ranked->field('dense_value'),
			)
			->orderBy($ranked->field('id')->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'rank_value' => 1, 'dense_value' => 2],
			['id' => 2, 'rank_value' => 1, 'dense_value' => 1],
			['id' => 3, 'rank_value' => 1, 'dense_value' => 3],
		], $rows);
	}

	public function testRootExecutionRequiresSelections(): void
	{
		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('root execution requires at least one explicit selection');

		$this->database->query($this->registry->getCollection('users'))->fetchAll();
	}

	public function testImplicitSelectionsAreIncludedInSqlButHiddenFromPublicRows(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->name)
			->require($users->id, 'relation-key')
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada'],
			['name' => 'Grace'],
			['name' => 'Linus'],
		], $rows);
	}

	public function testImplicitAliasesAvoidCollisionsWithExplicitBackendNames(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->name->as('__ondata_implicit_0'))
			->require($users->id, 'relation-key')
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['__ondata_implicit_0' => 'Ada'],
			['__ondata_implicit_0' => 'Grace'],
			['__ondata_implicit_0' => 'Linus'],
		], $rows);
	}

	public function testExplicitSelectionsMayAlsoCarryInternalRequirementReasonsAndRemainVisible(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id, $users->name)
			->require($users->id, 'relation-key')
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'name' => 'Ada'],
			['id' => 2, 'name' => 'Grace'],
			['id' => 3, 'name' => 'Linus'],
		], $rows);
	}

	public function testRootQueriesWithOnlyImplicitSelectionsAreRejected(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('root execution requires at least one explicit selection');

		$users
			->require($users->id, 'relation-key')
			->fetchAll();
	}

	public function testUnaliasedComputedRootSelectionsAreRejected(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('unaliased computed, aggregate, and subquery root selections are not supported');

		$users->select($users->name->upper())->fetchAll();
	}

	public function testDuplicateRootResultNamesAreRejected(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage("duplicate root result name 'id'");

		$users
			->select($users->id, $users->name->as('id'))
			->fetchAll();
	}

	public function testInvalidFieldScopeIsRejected(): void
	{
		$definition = $this->registry->getCollection('users');
		$users = $this->database->query($definition);
		$other = query($definition);

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('outside the active query scope');

		$users
			->select($users->id)
			->where(x()->eq($users->id, $other->id))
			->fetchAll();
	}

	public function testRelatedFieldSelectionUsesAutomaticFlatJoin(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id, $users->posts->title)
			->orderBy($users->id->asc(), $users->posts->title->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'posts.title' => 'Hello'],
			['id' => 1, 'posts.title' => 'World'],
			['id' => 2, 'posts.title' => 'Graph'],
		], $rows);
	}

	public function testNestedRelatedExpressionTranslationUsesNestedJoins(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id)
			->where(x()->eq($users->posts->author->name, 'Ada'))
			->fetchAll();

		self::assertSame([
			['id' => 1],
			['id' => 1],
		], $rows);
	}

	public function testRelatedFieldInWhereUsesAutomaticJoin(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->posts->title)
			->where(x()->eq($users->posts->published, true))
			->orderBy($users->posts->title->asc())
			->fetchAll();

		self::assertSame([
			['posts.title' => 'Graph'],
			['posts.title' => 'Hello'],
		], $rows);
	}

	public function testCyclicQueryGraphsAreRejected(): void
	{
		$cyclic = query($this->registry->getCollection('users'));
		$cyclic->select($cyclic->id, (new SubqueryExpression($cyclic))->as('self_cycle'));

		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('Cyclic query references are not supported.');

		$users
			->select($users->id, (new SubqueryExpression($cyclic))->as('outer_cycle'))
			->fetchAll();
	}

	public function testScalarSubqueriesRequireExactlyOneSelection(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$posts = query($this->registry->getCollection('posts'), function (SelectQuery $query): void {
			$query->select($query->id, $query->title);
		});

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('scalar subqueries require exactly one selection');

		$users
			->select($users->id)
			->where(x()->eq($users->id, $posts))
			->fetchAll();
	}

	public function testInSubqueriesRequireExactlyOneSelection(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$posts = query($this->registry->getCollection('posts'), function (SelectQuery $query): void {
			$query->select($query->id, $query->title);
		});

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('IN subqueries require exactly one selection');

		$users
			->select($users->id)
			->where(x()->in($users->id, $posts))
			->fetchAll();
	}

	public function testNullFieldValuesAreNotSentThroughCodecs(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$row = $users
			->select($users->profile)
			->where(x()->eq($users->id, 3))
			->fetchOne();

		self::assertSame(['profile' => null], $row);
	}

	public function testLazyIterationWrapsBackendMappingFailures(): void
	{
		$pdo = new PDO($this->dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec("UPDATE users SET created_at = 'not-a-datetime' WHERE id = 2");

		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(QueryExecutionException::class);
		$this->expectExceptionMessage("definition 'users'");

		foreach (
			$users
				->select($users->id, $users->createdAt)
				->orderBy($users->id->asc())
				->iterate() as $row
		) {
			// Consume the generator until the invalid row is mapped.
		}
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->table('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('email', 'string')->column('mail_address');
		$users->field('active', 'bool');
		$users->field('createdAt', 'datetime')->column('created_at');
		$users->field('profile', 'json')->column('profile_json')->nullable(true);
		$users->field('nickname', 'string')->nullable(true);
		$users->field('score', 'int');
		$users->relation('posts', CustomRelation::class)
			->collection('posts')
			->innerKey('id')
			->outerKey('userId');
		$users->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->table('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int')->column('user_id');
		$posts->field('title', 'string');
		$posts->field('amount', 'float');
		$posts->field('published', 'bool');
		$posts->relation('author', CustomRelation::class)
			->collection('users')
			->innerKey('userId')
			->outerKey('id');
		$posts->primaryKey('id');

		return $registry;
	}

	private function seedDatabase(): void
	{
		$pdo = new PDO($this->dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, mail_address TEXT, active INTEGER, created_at TEXT, profile_json TEXT, nickname TEXT NULL, score INTEGER)');
		$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, amount REAL, published INTEGER)');

		$users = $pdo->prepare('INSERT INTO users (id, name, mail_address, active, created_at, profile_json, nickname, score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
		$users->execute([1, 'Ada', 'ada@example.test', 1, '2026-06-24 10:00:00', '{"role":"admin"}', 'Ada', 10]);
		$users->execute([2, 'Grace', 'grace@example.test', 1, '2026-06-24 11:00:00', '{"role":"editor"}', 'Grace', 20]);
		$users->execute([3, 'Linus', 'linus@example.test', 0, '2026-06-24 12:00:00', null, null, 30]);

		$posts = $pdo->prepare('INSERT INTO posts (id, user_id, title, amount, published) VALUES (?, ?, ?, ?, ?)');
		$posts->execute([1, 1, 'Hello', 10.0, 1]);
		$posts->execute([2, 1, 'World', 15.5, 0]);
		$posts->execute([3, 2, 'Graph', 7.5, 1]);
	}

	private function compileSql(SelectQuery $query): string
	{
		$databaseReflection = new ReflectionClass($this->database);
		$executorProperty = $databaseReflection->getProperty('executor');
		$executor = $executorProperty->getValue($this->database);

		$executorReflection = new ReflectionClass($executor);
		$translatorProperty = $executorReflection->getProperty('translator');
		$translator = $translatorProperty->getValue($executor);

		$translated = $translator->translate($query);
		$parameters = new QueryParameters();

		return $translated->query()->sqlStatement($parameters);
	}
}
