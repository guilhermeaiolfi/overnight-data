# ADR 0001: Database Adapter Boundary and Compilation Target

Status: Accepted

## Context

ON\Data needs to support database-backed querying and persistence without tying the core runtime to one database abstraction library.

The current implementation has a Cycle Database adapter. Future support may include Doctrine DBAL or other adapters.

We have repeatedly reconsidered whether ON\Data should:

1. compile queries and commands to generic SQL strings through an ON\Data-owned SQL compiler; or
2. translate ON\Data query/command objects into the native builder/execution API of each supported database adapter.

Doctrine DBAL changes the tradeoff. A large part of Doctrine's value is already in its Connection, QueryBuilder, platform, parameter binding, result APIs, transaction handling, and type/platform services.

## Decision

ON\Data core will not introduce a generic public SqlStatement, SqlCompiler, or database-agnostic SQL AST as the main database abstraction.

The stable core abstraction is:

```php
QueryExecutorInterface
CommandExecutorInterface
DataRuntime
```

DataRuntime is backend-neutral. It receives already-created executors:

```php
new DataRuntime(
    QueryExecutorInterface $queryExecutor,
    CommandExecutorInterface $commandExecutor,
)
```

Adapter-specific code is responsible for turning an adapter-specific connection into those executors.

Current Cycle shape:

```php
ON\Data\Database\Cycle\CycleRuntimeFactory
    connect(ConnectionConfig $config): DataRuntime
    create(Cycle\Database\DatabaseInterface $database): DataRuntime
```

Future Doctrine shape:

```php
ON\Data\Database\Doctrine\DoctrineRuntimeFactory
    create(Doctrine\DBAL\Connection $connection): DataRuntime
```

Each adapter may have its own translator/compiler internally.

The word "compile" is acceptable internally, but the compilation target is adapter-native, not generic ON\Data SQL.

Cycle adapter target:

```text
ON\Data SelectQuery / CommandInterface
    -> Cycle Database query/command primitives
    -> Cycle execution
```

Future Doctrine adapter target:

```text
ON\Data SelectQuery / CommandInterface
    -> Doctrine DBAL QueryBuilder / Connection / Platform
    -> Doctrine execution
```

## Doctrine Adapter Guidance

A future Doctrine adapter should use Doctrine\DBAL\Connection as its adapter boundary.

It should create:

```php
DoctrineQueryExecutor implements QueryExecutorInterface
DoctrineCommandExecutor implements CommandExecutorInterface
DoctrineRuntimeFactory
```

DoctrineQueryExecutor should translate ON\Data SelectQuery into a Doctrine DBAL QueryBuilder-backed plan.

DoctrineCommandExecutor should translate InsertCommand, UpdateCommand, and DeleteCommand into Doctrine DBAL QueryBuilder or Connection operations, then execute through DBAL.

Doctrine execution should use:

```php
executeQuery()
```

for SELECT/result statements, and:

```php
executeStatement()
```

for INSERT/UPDATE/DELETE statements.

Doctrine transaction support should use:

```php
Connection::transactional()
```

where practical.

Doctrine platform APIs may be used inside the Doctrine adapter for dialect-sensitive SQL fragments, identifier quoting, generated identifiers, and platform differences.

Doctrine DBAL types may be used as parameter binding/type hints when useful, but they must not replace ON\Data FieldType and Representations as the conversion authority.

## Security Rule

Doctrine QueryBuilder does not make arbitrary SQL fragments safe.

The Doctrine adapter must only put trusted ON\Data-generated SQL fragments into QueryBuilder methods.

Values must be bound as parameters.

Identifiers must come from ON\Data definitions, not user input.

Operators, sort directions, join kinds, and other SQL keywords must be whitelisted by ON\Data query objects/translators.

User input must never be concatenated into QueryBuilder clauses.

## Consequences

ON\Data avoids duplicating Doctrine DBAL or Cycle Database SQL-building abstractions.

Adapter implementations remain free to use the strongest primitives of their backend.

Adding Doctrine later does not require changing DataRuntime.

Adding another adapter later follows the same pattern:

```text
AdapterRuntimeFactory
    -> AdapterQueryExecutor
    -> AdapterCommandExecutor
```

The core does not need:

```php
DatabaseInterface
ConnectionAdapterInterface
getConnection(): mixed
getResource(): mixed
public SqlStatement
public SqlCompiler
```

Raw SQL strings may still exist inside an adapter as private implementation details, but they must not become the core public abstraction.

## Non-Goals

This decision does not implement Doctrine support.

This decision does not add doctrine/dbal to composer.

This decision does not change Overnight integration.

This decision does not introduce an ORM dependency.
