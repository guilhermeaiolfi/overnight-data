<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Database;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Query\JoinType;
use function ON\Data\Query\x;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleJoinExecutionTest extends TestCase
{
	private string $databasePath;

	private string $dsn;

	private ?Registry $registry = null;

	private ?Database $database = null;

	protected function setUp(): void
	{
		$this->databasePath = tempnam(sys_get_temp_dir(), 'ondata-joins-');
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

	public function testExplicitJoinSupportsSelectionsConditionsAndOrdering(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$company = $users->join(
			$this->registry->getCollection('companies'),
			JoinType::LEFT,
			'company',
		);
		$company->on(x()->eq($users->companyId, $company->id));

		$rows = $users
			->select($users->name, $company->name)
			->where(x()->eq($company->active, true))
			->orderBy($company->name->asc(), $users->name->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'company.name' => 'Acme'],
			['name' => 'Grace', 'company.name' => 'Acme'],
		], $rows);
	}

	public function testNullableBelongsToUsesAutomaticLeftJoin(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id, $users->company->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'company.name' => 'Acme'],
			['id' => 2, 'company.name' => 'Acme'],
			['id' => 3, 'company.name' => null],
		], $rows);
	}

	public function testManyToManyCreatesFlatMultipliedRows(): void
	{
		$articles = $this->database->query($this->registry->getCollection('articles'));

		$rows = $articles
			->select($articles->id, $articles->tags->name)
			->orderBy($articles->id->asc(), $articles->tags->name->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'tags.name' => 'orm'],
			['id' => 1, 'tags.name' => 'php'],
			['id' => 2, 'tags.name' => 'sqlite'],
		], $rows);
	}

	public function testFirstOfManyJoinFailsExplicitly(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('FirstOfMany join semantics');

		$users
			->select($users->latestPost->title)
			->fetchAll();
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$companies = $registry->collection('companies');
		$companies->table('companies');
		$companies->field('id', 'int');
		$companies->field('name', 'string');
		$companies->field('active', 'bool');
		$companies->primaryKey('id');

		$users = $registry->collection('users');
		$users->table('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('companyId', 'int')->column('company_id')->nullable(true);
		$users->belongsTo('company', 'companies')->innerKey('companyId')->outerKey('id')->end();
		$users->relation('latestPost', FirstOfManyRelation::class)
			->collection('posts')
			->innerKey('id')
			->outerKey('userId');
		$users->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->table('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int')->column('user_id');
		$posts->field('title', 'string');
		$posts->primaryKey('id');

		$articles = $registry->collection('articles');
		$articles->table('articles');
		$articles->field('id', 'int');
		$articles->field('title', 'string');
		$articles->primaryKey('id');
		$articles->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id')
			->through('article_tag')
				->innerKey('article_id')
				->outerKey('tag_id')
				->end();

		$tags = $registry->collection('tags');
		$tags->table('tags');
		$tags->field('id', 'int');
		$tags->field('name', 'string');
		$tags->primaryKey('id');

		$articleTag = $registry->collection('article_tag');
		$articleTag->table('article_tag');
		$articleTag->field('article_id', 'int');
		$articleTag->field('tag_id', 'int');

		return $registry;
	}

	private function seedDatabase(): void
	{
		$pdo = new PDO($this->dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$pdo->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)');
		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, company_id INTEGER NULL)');
		$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
		$pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT)');
		$pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)');
		$pdo->exec('CREATE TABLE article_tag (article_id INTEGER, tag_id INTEGER)');

		$pdo->exec("INSERT INTO companies (id, name, active) VALUES (1, 'Acme', 1), (2, 'Dormant', 0)");
		$pdo->exec("INSERT INTO users (id, name, company_id) VALUES (1, 'Ada', 1), (2, 'Grace', 1), (3, 'Linus', NULL)");
		$pdo->exec("INSERT INTO posts (id, user_id, title) VALUES (1, 1, 'Hello'), (2, 1, 'World'), (3, 2, 'Graph')");
		$pdo->exec("INSERT INTO articles (id, title) VALUES (1, 'Joins'), (2, 'SQLite')");
		$pdo->exec("INSERT INTO tags (id, name) VALUES (1, 'php'), (2, 'orm'), (3, 'sqlite')");
		$pdo->exec('INSERT INTO article_tag (article_id, tag_id) VALUES (1, 1), (1, 2), (2, 3)');
	}
}
