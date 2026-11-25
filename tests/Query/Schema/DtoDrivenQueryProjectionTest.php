<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Schema;

use ON\Data\Database\DatabasePlatformInterface;
use ON\Data\Database\Sql\Dialect\SqliteDialect;
use ON\Data\Database\Sql\SqlQueryCompiler;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Session;
use ON\Data\Query\Exception\ObjectExportException;
use function ON\Data\Query\query;
use ON\Data\Query\Schema\ClassRepresentationSchemaCompiler;
use ON\Data\Schema\Exception\InvalidSchemaException;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationFieldRole;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

final class DtoDrivenQueryProjectionTest extends TestCase
{
	public function testCompilerReturnsNullForStdClassAndPrivateOnlyClasses(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$compiler = new ClassRepresentationSchemaCompiler();

		self::assertNull($compiler->compile($users, stdClass::class));
		self::assertNull($compiler->compile($users, DtoPrivateOnlyUser::class));
	}

	public function testCompilerDerivesFieldsFlatPathsNestedRelationsAndImplicitKeys(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$schema = (new ClassRepresentationSchemaCompiler())->compile($users, DtoUserCard::class);

		self::assertNotNull($schema);
		self::assertSame(['id', 'name', 'companyName', 'countryCode'], $schema->getPublicScalarPaths());
		self::assertSame(['company.id', 'company.country.id'], $schema->getImplicitScalarPaths());

		$companyName = $schema->getField('companyName');
		self::assertSame('companies', $companyName->getCollectionName());
		self::assertSame('name', $companyName->getFieldName());
		self::assertSame(['company'], $companyName->getSourcePath());

		$countryCode = $schema->getField('countryCode');
		self::assertSame('countries', $countryCode->getCollectionName());
		self::assertSame('code', $countryCode->getFieldName());
		self::assertSame(['company', 'country'], $countryCode->getSourcePath());

		self::assertTrue($schema->hasRelation('posts'));
		$postsRelation = $schema->getRelation('posts');
		self::assertSame(RelationLoadKnowledge::Full, $postsRelation->getLoadKnowledge());
		self::assertSame(['id', 'title', 'authorName'], $postsRelation->getRelatedSchema()->getPublicScalarPaths());
		self::assertSame(['author.id'], $postsRelation->getRelatedSchema()->getImplicitScalarPaths());

		self::assertFalse($schema->hasPath('internalTag'));
		self::assertFalse($schema->hasPath('selected'));
	}

	public function testCompilerSupportsUntypedRelationDefaultVisibleFields(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$schema = (new ClassRepresentationSchemaCompiler())->compile($users, DtoUserWithRawPosts::class);

		self::assertNotNull($schema);
		self::assertSame(['id'], $schema->getPublicScalarPaths());
		self::assertSame(
			['id', 'userId', 'title', 'published'],
			$schema->getRelation('posts')->getRelatedSchema()->getPublicScalarPaths(),
		);
	}

	public function testCompilerRejectsRequiredUnmappedProperty(): void
	{
		$users = $this->makeRegistry()->getCollection('users');

		$this->expectException(InvalidSchemaException::class);
		$this->expectExceptionMessage("does not match any field or relation on collection 'users'");

		(new ClassRepresentationSchemaCompiler())->compile($users, DtoTypoUser::class);
	}

	public function testCompilerRejectsInvalidExplicitMapFromEvenWithDefault(): void
	{
		$users = $this->makeRegistry()->getCollection('users');

		$this->expectException(InvalidSchemaException::class);
		$this->expectExceptionMessage("references unknown field 'missingField' on collection 'companies'");

		(new ClassRepresentationSchemaCompiler())->compile($users, DtoInvalidMapFromUser::class);
	}

	public function testCompilerRejectsAliasedRelationProperty(): void
	{
		$users = $this->makeRegistry()->getCollection('users');

		$this->expectException(InvalidSchemaException::class);
		$this->expectExceptionMessage("cannot alias relation 'posts' as 'articles'");

		(new ClassRepresentationSchemaCompiler())->compile($users, DtoAliasedRelationUser::class);
	}

	public function testCompilerRejectsCyclicDtoRelations(): void
	{
		$users = $this->makeRegistry()->getCollection('users');

		$this->expectException(InvalidSchemaException::class);
		$this->expectExceptionMessage('Cyclic DTO relation projection detected');

		(new ClassRepresentationSchemaCompiler())->compile($users, DtoCyclicUser::class);
	}

	public function testCompilerRejectsDtoWithNoMatchingProperties(): void
	{
		$users = $this->makeRegistry()->getCollection('users');

		$this->expectException(InvalidSchemaException::class);
		$this->expectExceptionMessage("has no properties matching any field or relation on collection 'users'");

		(new ClassRepresentationSchemaCompiler())->compile($users, DtoOnlyDefaultedExtra::class);
	}

	public function testToDtoSelectsOnlyDtoColumnsAndStaysOnFastPathForOwnScalars(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$query = query($users)->to(DtoUserSummary::class);

		self::assertFalse($query->needsRowAssemble());

		$compiled = (new SqlQueryCompiler(
			new SqliteDialect(),
			$this->createStub(DatabasePlatformInterface::class),
			ConversionGateway::createDefault(),
		))->compile($query);
		$sql = $compiled->sql;

		self::assertStringContainsString('"id"', $sql);
		self::assertStringContainsString('"name"', $sql);
		self::assertStringNotContainsString('"bio"', $sql);
		self::assertStringNotContainsString('"email"', $sql);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testToDtoHydratesFlatsAndNestedRelationsEndToEnd(): void
	{
		$harness = $this->makeSqliteHarness();
		$users = $this->makeRegistry()->getCollection('users');

		$query = $harness->database->query($users);
		$cards = $query
			->to(DtoUserCard::class)
			->orderBy($query->id->asc())
			->fetchAll();

		self::assertCount(2, $cards);
		self::assertInstanceOf(DtoUserCard::class, $cards[0]);
		self::assertSame(1, $cards[0]->id);
		self::assertSame('Ada', $cards[0]->name);
		self::assertSame('Analytical Inc', $cards[0]->companyName);
		self::assertSame('UK', $cards[0]->countryCode);
		self::assertSame('local', $cards[0]->internalTag);
		self::assertFalse($cards[0]->selected);

		self::assertCount(2, $cards[0]->posts);
		self::assertInstanceOf(DtoPostRow::class, $cards[0]->posts[0]);
		self::assertSame(10, $cards[0]->posts[0]->id);
		self::assertSame('Engine', $cards[0]->posts[0]->title);
		self::assertSame('Ada', $cards[0]->posts[0]->authorName);

		self::assertInstanceOf(DtoUserCard::class, $cards[1]);
		self::assertSame(2, $cards[1]->id);
		self::assertNull($cards[1]->companyName);
		self::assertNull($cards[1]->countryCode);
		self::assertCount(1, $cards[1]->posts);
		self::assertSame('Compiler', $cards[1]->posts[0]->title);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testToDtoSupportsWritableRootFlatAndNestedUpdates(): void
	{
		$harness = $this->makeSqliteHarness();
		$users = $this->makeRegistry()->getCollection('users');
		$session = new Session($harness->commandExecutor);

		$card = $harness->database->query($users)
			->to(DtoUserCard::class)
			->writable($session)
			->fetchOne(1);

		self::assertInstanceOf(DtoUserCard::class, $card);

		$card->name = 'Ada Lovelace';
		$card->companyName = 'Babbage Labs';
		$card->countryCode = 'GB';
		$card->posts[0]->title = 'Analytical Notes';
		$card->internalTag = 'changed-locally';
		$card->selected = true;

		$session->sync($card);
		$session->flush();

		self::assertSame(
			['name' => 'Ada Lovelace', 'bio' => 'Original bio'],
			$harness->fetchRow('SELECT name, bio FROM users WHERE id = 1'),
		);
		self::assertSame(
			['name' => 'Babbage Labs'],
			$harness->fetchRow('SELECT name FROM companies WHERE id = 100'),
		);
		self::assertSame(
			['code' => 'GB'],
			$harness->fetchRow('SELECT code FROM countries WHERE id = 1000'),
		);
		self::assertSame(
			['title' => 'Analytical Notes'],
			$harness->fetchRow('SELECT title FROM posts WHERE id = 10'),
		);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testToDtoSupportsNullableToOneNestedDtoAndReadonlyConstructorPromotion(): void
	{
		$harness = $this->makeSqliteHarness();
		$users = $this->makeRegistry()->getCollection('users');
		$session = new Session($harness->commandExecutor);

		$query = $harness->database->query($users);
		$cards = $query
			->to(DtoReadonlyPromotedUser::class)
			->orderBy($query->id->asc())
			->fetchAll();

		self::assertCount(2, $cards);
		self::assertInstanceOf(DtoReadonlyPromotedUser::class, $cards[0]);
		self::assertSame(1, $cards[0]->id);
		self::assertSame('Analytical Inc', $cards[0]->companyName);
		self::assertInstanceOf(DtoCompanyCard::class, $cards[0]->company);
		self::assertSame('Analytical Inc', $cards[0]->company->name);
		self::assertCount(2, $cards[0]->posts);
		self::assertInstanceOf(DtoPostRow::class, $cards[0]->posts[0]);
		self::assertSame('default-note', $cards[0]->note);

		self::assertNull($cards[1]->company);
		self::assertNull($cards[1]->companyName);

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Writable query export does not support readonly classes or readonly public properties');

		$harness->database->query($users)
			->to(DtoReadonlyPromotedUser::class)
			->writable($session);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testRelationConstraintsAfterToDtoRefineDerivedRelation(): void
	{
		$harness = $this->makeSqliteHarness();
		$users = $this->makeRegistry()->getCollection('users');

		$query = $harness->database->query($users)->to(DtoUserCard::class);
		$query->posts->where($query->posts->published->eq(true));

		$card = $query->fetchOne(1);

		self::assertInstanceOf(DtoUserCard::class, $card);
		self::assertCount(1, $card->posts);
		self::assertSame('Engine', $card->posts[0]->title);
		self::assertSame(
			RelationLoadKnowledge::Partial,
			$query->getFetchSchema()?->getRelation('posts')->getLoadKnowledge(),
		);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testExplicitSelectTakesPrecedenceOverToDtoProjection(): void
	{
		$harness = $this->makeSqliteHarness();
		$users = $this->makeRegistry()->getCollection('users');

		$query = $harness->database->query($users);
		$user = $query
			->select($query->id, $query->name)
			->to(DtoUserWithOptionalCompany::class)
			->fetchOne(1);

		self::assertInstanceOf(DtoUserWithOptionalCompany::class, $user);
		self::assertSame(1, $user->id);
		self::assertSame('Ada', $user->name);
		self::assertNull($user->company);
		self::assertTrue($query->getRelationSelections()->isEmpty());
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testSymmetricMapFromAndMapToWorksWithMapperBackedRepresentationReader(): void
	{
		$harness = $this->makeSqliteHarness();
		$users = $this->makeRegistry()->getCollection('users');
		$session = new Session($harness->commandExecutor);

		$user = $harness->database->query($users)
			->to(DtoSymmetricRenamedUser::class)
			->writable($session)
			->fetchOne(1);

		self::assertInstanceOf(DtoSymmetricRenamedUser::class, $user);
		self::assertSame(1, $user->id);
		self::assertSame('Ada', $user->displayName);

		$user->displayName = 'Augusta Ada';
		$session->sync($user);
		$session->flush();

		self::assertSame(
			['name' => 'Augusta Ada'],
			$harness->fetchRow('SELECT name FROM users WHERE id = 1'),
		);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testScalarDtoSupportsIterateCountAndProjectionForManualSessionUpdate(): void
	{
		$harness = $this->makeSqliteHarness();
		$users = $this->makeRegistry()->getCollection('users');
		$session = new Session($harness->commandExecutor);

		$query = $harness->database->query($users)->to(DtoUserSummary::class);
		self::assertSame(2, $query->count());

		$iterated = iterator_to_array($query->orderBy($query->id->asc())->iterate(), false);
		self::assertCount(2, $iterated);
		self::assertInstanceOf(DtoUserSummary::class, $iterated[0]);
		self::assertSame('Ada', $iterated[0]->name);

		$schema = $query->projection();
		self::assertSame(['id', 'name'], $schema->getPublicScalarPaths());
		self::assertSame(RepresentationFieldRole::Public, $schema->getField('id')->getRole());

		$patch = new DtoUserSummary();
		$patch->id = 2;
		$patch->name = 'Grace Hopper';
		$session->update($patch, $schema);
		$session->flush();

		self::assertSame(
			['name' => 'Grace Hopper'],
			$harness->fetchRow('SELECT name FROM users WHERE id = 2'),
		);
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$countries = $registry->collection('countries');
		$countries->primaryKey('id');
		$countries->field('id', 'int')->end();
		$countries->field('code', 'string')->end();

		$companies = $registry->collection('companies');
		$companies->primaryKey('id');
		$companies->field('id', 'int')->end();
		$companies->field('name', 'string')->end();
		$companies->field('countryId', 'int')->column('country_id')->nullable(true)->end();
		$companies->belongsTo('country', 'countries')->innerKey('countryId')->outerKey('id');

		$users = $registry->collection('users');
		$users->primaryKey('id');
		$users->field('id', 'int')->end();
		$users->field('name', 'string')->end();
		$users->field('email', 'string')->end();
		$users->field('bio', 'string')->nullable(true)->end();
		$users->field('companyId', 'int')->column('company_id')->nullable(true)->end();
		$users->belongsTo('company', 'companies')->innerKey('companyId')->outerKey('id');

		$posts = $registry->collection('posts');
		$posts->primaryKey('id');
		$posts->field('id', 'int')->end();
		$posts->field('userId', 'int')->column('user_id')->end();
		$posts->field('title', 'string')->end();
		$posts->field('published', 'bool')->end();

		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId');
		$posts->belongsTo('author', 'users')->innerKey('userId')->outerKey('id');

		return $registry;
	}

	private function makeSqliteHarness(): SqliteMemoryHarness
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
		$harness->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT NOT NULL, country_id INTEGER NULL)');
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, bio TEXT NULL, company_id INTEGER NULL)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL, published INTEGER NOT NULL)');

		$harness->exec("INSERT INTO countries (id, code) VALUES (1000, 'UK')");
		$harness->exec("INSERT INTO companies (id, name, country_id) VALUES (100, 'Analytical Inc', 1000)");
		$harness->exec("INSERT INTO users (id, name, email, bio, company_id) VALUES (1, 'Ada', 'ada@example.test', 'Original bio', 100), (2, 'Grace', 'grace@example.test', 'Navy bio', NULL)");
		$harness->exec("INSERT INTO posts (id, user_id, title, published) VALUES (10, 1, 'Engine', 1), (11, 1, 'Draft', 0), (12, 2, 'Compiler', 1)");

		return $harness;
	}
}

final class DtoUserSummary
{
	public int $id;

	public string $name;
}

final class DtoCompanyCard
{
	public int $id;

	public string $name;
}

final class DtoPostRow
{
	public int $id;

	public string $title;

	#[MapFrom('author.name')]
	public string $authorName;
}

final class DtoUserCard
{
	public int $id;

	public string $name;

	#[MapFrom('company.name')]
	public ?string $companyName = null;

	#[MapFrom('company.country.code')]
	public ?string $countryCode = null;

	/** @var list<DtoPostRow> */
	public array $posts = [];

	#[Hidden]
	public string $internalTag = 'local';

	public bool $selected = false;
}

final readonly class DtoReadonlyPromotedUser
{
	/**
	 * @param list<DtoPostRow> $posts
	 */
	public function __construct(
		public int $id,
		public string $name,
		#[MapFrom('company.name')]
		public ?string $companyName,
		public ?DtoCompanyCard $company,
		public array $posts,
		public string $note = 'default-note',
	) {
	}
}

final class DtoUserWithOptionalCompany
{
	public int $id;

	public string $name;

	public ?DtoCompanyCard $company = null;
}

final class DtoUserWithRawPosts
{
	public int $id;

	/** @var list<stdClass> */
	public array $posts = [];
}

final class DtoSymmetricRenamedUser
{
	public int $id;

	#[MapFrom('name')]
	#[MapTo('name')]
	public string $displayName;
}

final class DtoPrivateOnlyUser
{
	public function __construct(
		private int $id,
		private string $name,
	) {
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}
}

final class DtoTypoUser
{
	public int $id;

	public string $nmae;
}

final class DtoInvalidMapFromUser
{
	public int $id;

	#[MapFrom('company.missingField')]
	public ?string $companyName = null;
}

final class DtoAliasedRelationUser
{
	public int $id;

	/** @var list<DtoPostRow> */
	#[MapFrom('posts')]
	public array $articles = [];
}

final class DtoCyclicUser
{
	public int $id;

	/** @var list<DtoCyclicPost> */
	public array $posts = [];
}

final class DtoCyclicPost
{
	public int $id;

	public DtoCyclicUser $author;
}

final class DtoOnlyDefaultedExtra
{
	public string $unrelated = 'x';
}
