# Read and write pipeline

This is the birds-eye map of **what must happen** on reads and writes. Class names and file layout are the current pointers, not the design: they can be rethought. These steps cannot.

Living how-it-works for each layer: [`definition-api.md`](./definition-api.md), [`mapper-runtime-guide.md`](./mapper-runtime-guide.md), [`query/query-model.md`](./query/query-model.md), [`query/bound-execution.md`](./query/bound-execution.md), [`orm/foundation.md`](./orm/foundation.md), [`orm/persistence.md`](./orm/persistence.md), [`orm/representation-schema.md`](./orm/representation-schema.md), [`orm/session-save-api.md`](./orm/session-save-api.md).

## Invariant

Persistence truth is the **record**, not the PHP object.

```text
database / query  →  record meaning  →  representation
representation    →  sync            →  record state  →  flush  →  database
```

Reads produce a representation over record meaning. Writes push that representation back into record state, then project record state to the database. If those two collapse into “the object is the row,” identity, partial updates, nested graphs, and conflict detection all break.

## Shared — both directions

These exist before any fetch or flush. Every later step consults them.

| Id | What must happen | Why |
|---|---|---|
| S1 | Know what can exist | Collections, fields, relations, keys, and storage names are the vocabulary. Without them, neither a query nor a row has meaning. |
| S2 | Own one canonical meaning per value | Storage, wire, and PHP disagree about types. Something must round-trip through a single in-memory meaning, or reads and writes silently diverge. |

## Reads

Database → record meaning → user-facing object. Tracking is optional until the result must be writable. A read-only fetch stops at R9. Writable fetch continues to R10 on the same instance returned to the caller.

| Id | What must happen | Why |
|---|---|---|
| R1 | Declare which rows and members to fetch | The store has everything. You must name the source, the subset of fields and relations, and the filters, order, and page. |
| R2 | Keep result paths separate from fetch destinations | A flat `authorName` sits on the parent object; a nested `posts` is both a path and a load. Mixing shape with SQL/JOIN strategy makes identity and later writes wrong. |
| R3 | Carry provenance and identity if the result may be written | Later you must know which collection.field and which row a property came from. Expressions cannot reverse. A result without identity cannot be tracked. |
| R4 | Translate intent into the storage language | The database does not understand field refs or relation refs. |
| R5 | Convert bound literals to storage types | A PHP `true` is not necessarily how `active` is stored. Predicates must use the same conversion as written columns, or filters miss rows. |
| R6 | Execute and retrieve storage rows | Existing data only comes from the backend. This is the only step that actually reads the database. |
| R7 | Convert storage scalars to canonical PHP | Driver types are not the application meaning. The rest of the pipeline should see PHP values, not PDO leftovers. |
| R8 | Fold rows into the intended shape; hide identity-only columns | Joins multiply rows; separate queries arrive later. Hidden primary keys must exist for tracking but must not leak onto the user's object unless selected. |
| R9 | Materialize the user-facing representation | Callers want arrays, `stdClass`, or DTOs — not a raw bag of columns. |
| R10 | If writable: snapshot identity, values, and relation completeness on that same object | Without a baseline and load-knowledge, later sync cannot tell user-changed from never-loaded, or this object from a newer write of the same record. The object is not the source of truth — the snapshot is. |

## Writes

Intent → tracked records → relation effects → commands → storage. Flush does not invent lifecycle; it writes what sync already admitted.

| Id | What must happen | Why |
|---|---|---|
| W1 | Separate lifecycle intent from primary-key presence | A DTO with an id is not automatically an existing row. Create, update, identify, delete, and unlink are different jobs. Guessing from the PK silently turns inserts into updates. |
| W2 | Bind the object to concrete records using provenance | One object can span many records (root + flats + nested). New records have no database key yet and still need a stable handle. Adoption is how a PHP value becomes tracked state. |
| W3 | Read current values off the representation | The object is allowed to drift. Nothing is persistable until you look at what the user actually has now. |
| W4 | Convert inbound values to canonical PHP | Wire/DTO types may not be persistable as-is. The record must store the same meaning that reads produced. |
| W5 | Reject stale overwrites | Two representations of the same record can disagree. Last-write-wins would silently lose the newer record value. |
| W6 | Apply scalar deltas to record state, not to SQL | Several objects may patch different fields of the same row. The persistable unit is the record. SQL is a later projection of that state. |
| W7 | Capture relation membership changes and how complete the collection is | Unloaded is not empty. Omitting a child from an array is only “remove the rest” if you loaded everyone. Unlink is not delete. |
| W8 | Turn relation intent into foreign-key or through-row effects | A relation is not a column. Someone must write join keys onto owner or child, or insert/delete pivot rows. |
| W9 | Fill generated values — PHP now, database after write | Identity and defaults often do not come from the user. PHP-owned values must exist before insert; database-owned values must be read back. |
| W10 | Order writes so dependents wait for generated keys | A new child cannot store `parent_id` until the parent insert returns. Temporary references must collapse before they hit the adapter. |
| W11 | Convert command values PHP → storage | Same conversion authority as reads, opposite direction. Adapters should not invent a second type system. |
| W12 | Execute as one transaction; validate affected rows | A half-written graph is worse than failing. Zero-row updates usually mean the identity was wrong. |
| W13 | Merge generated and defaulted values back into records | Later commands and later reads in this session must see the real identity. |
| W14 | On failure, restore in-memory snapshots | A rolled-back insert must not leave a generated id in PHP as if it committed. |

## Where it currently happens

Grouped by current package. If you redesign, keep the step and move or merge the group.

| Group | Serves | Where | Classes today |
|---|---|---|---|
| Definition | S1 — and every later step consults it | [`src/Definition`](../src/Definition), [`src/Key.php`](../src/Key.php) | [`Registry`](../src/Definition/Registry.php), [`Collection`](../src/Definition/Collection/Collection.php), [`Field`](../src/Definition/Field/Field.php), [`HasManyRelation`](../src/Definition/Relation/HasManyRelation.php), [`BelongsToRelation`](../src/Definition/Relation/BelongsToRelation.php), [`HasOneRelation`](../src/Definition/Relation/HasOneRelation.php), [`M2MRelation`](../src/Definition/Relation/M2MRelation.php), [`Key`](../src/Key.php) |
| Conversion | S2, R5, R7, W4, W11, W13 | [`src/Mapper`](../src/Mapper) | [`ConversionGateway`](../src/Mapper/ConversionGateway.php), [`FieldTypeInterface`](../src/Mapper/FieldTypeInterface.php), field types under [`src/Mapper/Field`](../src/Mapper/Field), [`PhpRepresentation`](../src/Mapper/Representation/PhpRepresentation.php), [`StorageRepresentation`](../src/Mapper/Representation/StorageRepresentation.php), [`WireRepresentation`](../src/Mapper/Representation/WireRepresentation.php), [`map()`](../src/Mapper/functions.php) |
| Query authoring | R1 | [`src/Query`](../src/Query) | [`SelectQuery`](../src/Query/SelectQuery.php), [`FieldRef`](../src/Query/Expression/FieldRef.php), [`RelationRef`](../src/Query/Relation/RelationRef.php), [`SelectionList`](../src/Query/Selection/SelectionList.php), conditions under [`src/Query/Condition`](../src/Query/Condition), [`Sort`](../src/Query/Sort/Sort.php), [`StarExpression`](../src/Query/Expression/StarExpression.php), [`query()`](../src/Query/functions.php) |
| Path and provenance | R2, R3, W2 | [`src/Schema`](../src/Schema), [`src/Query/Schema`](../src/Query/Schema) | [`RepresentationSchema`](../src/Schema/RepresentationSchema.php), [`RepresentationFieldSchema`](../src/Schema/RepresentationFieldSchema.php), [`RepresentationRelationSchema`](../src/Schema/RepresentationRelationSchema.php), [`RepresentationExpressionSchema`](../src/Schema/RepresentationExpressionSchema.php), [`QueryRepresentationSchemaCompiler`](../src/Query/Schema/QueryRepresentationSchemaCompiler.php), [`SelectQuery::projection()`](../src/Query/SelectQuery.php) |
| Fetch destinations and assemble | R2, R8 | [`src/Query/Relation`](../src/Query/Relation), [`src/Query/Result/Parser`](../src/Query/Result/Parser) | [`LoadRuntime`](../src/Query/Relation/LoadRuntime.php), [`LoadBranch`](../src/Query/Relation/LoadBranch.php), [`RootLoadBranch`](../src/Query/Relation/RootLoadBranch.php), [`RelationSelectionTree`](../src/Query/Relation/RelationSelectionTree.php), [`HasManyLoader`](../src/Query/Relation/Loader/HasManyLoader.php), [`BelongsToLoader`](../src/Query/Relation/Loader/BelongsToLoader.php), [`HasOneLoader`](../src/Query/Relation/Loader/HasOneLoader.php), [`M2MLoader`](../src/Query/Relation/Loader/M2MLoader.php), [`RelationOutputProcessor`](../src/Query/Relation/RelationOutputProcessor.php), [`LoadFieldPlanner`](../src/Query/Relation/LoadFieldPlanner.php) |
| Read adapter | R4, R5, R6, R7 | [`src/Database`](../src/Database), [`src/Database/Cycle`](../src/Database/Cycle) | [`QueryExecutorInterface`](../src/Database/QueryExecutorInterface.php), [`CycleQueryTranslator`](../src/Database/Cycle/CycleQueryTranslator.php), [`CycleQueryExecutor`](../src/Database/Cycle/CycleQueryExecutor.php), [`CycleResultMapper`](../src/Database/Cycle/CycleResultMapper.php) |
| User materialization | R9 | [`SelectQuery`](../src/Query/SelectQuery.php) + Mapper | [`SelectQuery::to()`](../src/Query/SelectQuery.php), [`map()->to()`](../src/Mapper/functions.php), [`ObjectMapper`](../src/Mapper/Mapper/ObjectMapper.php), [`ArrayMapper`](../src/Mapper/Mapper/ArrayMapper.php) |
| Session stores | R10, W2–W7, W14 | [`src/ORM`](../src/ORM) | [`Session`](../src/ORM/Session.php), [`SessionContext`](../src/ORM/SessionContext.php), [`RecordState`](../src/ORM/Record/RecordState.php), [`RecordStateStore`](../src/ORM/Record/RecordStateStore.php), [`RepresentationState`](../src/ORM/Representation/State/RepresentationState.php), [`RepresentationStateStore`](../src/ORM/Representation/State/RepresentationStateStore.php), [`RelationStateStore`](../src/ORM/Relation/RelationStateStore.php) |
| Adoption / hydrate / intent | R10, W1, W2 | [`src/ORM/Representation/Sync`](../src/ORM/Representation/Sync), [`src/ORM/Representation/State/Query`](../src/ORM/Representation/State/Query) | [`RepresentationAdoptionEngine`](../src/ORM/Representation/Sync/RepresentationAdoptionEngine.php), [`AdoptionPolicy`](../src/ORM/Representation/Sync/AdoptionPolicy.php) (`Hydrate` / `Identify` / `Patch` / `Create`), [`WritableQueryResultTracker`](../src/ORM/Representation/State/Query/WritableQueryResultTracker.php), [`RepresentationIntentStore`](../src/ORM/Representation/Sync/RepresentationIntentStore.php), [`IntentBuilder`](../src/ORM/IntentBuilder.php), [`Session::update`](../src/ORM/Session.php) / `create` / `identify` / `remove` / `detach` |
| Scalar sync | W3–W6 | [`src/ORM/Representation/Sync`](../src/ORM/Representation/Sync) | [`RepresentationSyncer`](../src/ORM/Representation/Sync/RepresentationSyncer.php), [`RepresentationReader`](../src/ORM/Representation/Sync/RepresentationReader.php), [`ScalarRepresentationSynchronizer`](../src/ORM/Representation/Sync/ScalarRepresentationSynchronizer.php), [`SyncConflictDetector`](../src/ORM/Representation/Sync/SyncConflictDetector.php), [`SyncPlan`](../src/ORM/Representation/Sync/SyncPlan.php), [`SyncFieldUpdate`](../src/ORM/Representation/Sync/SyncFieldUpdate.php) |
| Relation sync | W7 | [`src/ORM/Relation`](../src/ORM/Relation), [`src/ORM/Representation/Sync`](../src/ORM/Representation/Sync) | [`RelationRepresentationSynchronizer`](../src/ORM/Representation/Sync/RelationRepresentationSynchronizer.php), [`ToManyRelationState`](../src/ORM/Relation/ToManyRelationState.php), [`ToOneRelationState`](../src/ORM/Relation/ToOneRelationState.php), [`RelationLoadKnowledge`](../src/Schema/RelationLoadKnowledge.php) |
| Relation persistence | W8, W10 | [`src/ORM/Relation/Persistence`](../src/ORM/Relation/Persistence) | [`RelationPersistencePlanner`](../src/ORM/Relation/Persistence/RelationPersistencePlanner.php), [`HasManyPersistencePlanner`](../src/ORM/Relation/Persistence/HasManyPersistencePlanner.php), [`BelongsToPersistencePlanner`](../src/ORM/Relation/Persistence/BelongsToPersistencePlanner.php), [`HasOnePersistencePlanner`](../src/ORM/Relation/Persistence/HasOnePersistencePlanner.php), [`ManyToManyPersistencePlanner`](../src/ORM/Relation/Persistence/ManyToManyPersistencePlanner.php), [`ForeignKeyWriter`](../src/ORM/Relation/Persistence/ForeignKeyWriter.php), [`ValueRef`](../src/ORM/Record/ValueRef.php) |
| Flush and commands | W6, W9–W12, W14 | [`src/ORM/Persistence`](../src/ORM/Persistence) | [`FlushExecutor`](../src/ORM/Persistence/FlushExecutor.php), [`FlushScheduler`](../src/ORM/Persistence/FlushScheduler.php), [`CommandPlanner`](../src/ORM/Persistence/CommandPlanner.php), [`FieldGeneratorApplier`](../src/ORM/Persistence/FieldGeneratorApplier.php), [`InsertCommand`](../src/ORM/Persistence/InsertCommand.php), [`UpdateCommand`](../src/ORM/Persistence/UpdateCommand.php), [`DeleteCommand`](../src/ORM/Persistence/DeleteCommand.php), [`CommandValueResolver`](../src/ORM/Persistence/CommandValueResolver.php), [`AffectedRowsValidator`](../src/ORM/Persistence/AffectedRowsValidator.php) |
| Write adapter | W11–W13 | [`src/ORM/Persistence`](../src/ORM/Persistence), [`src/Database/Cycle`](../src/Database/Cycle) | [`CommandExecutorInterface`](../src/ORM/Persistence/CommandExecutorInterface.php), [`ConvertingCommandExecutor`](../src/ORM/Persistence/ConvertingCommandExecutor.php), [`CycleCommandExecutor`](../src/Database/Cycle/CycleCommandExecutor.php), [`CommandResult`](../src/ORM/Persistence/CommandResult.php) |

## Public entry points today

| Job | Call | Then |
|---|---|---|
| Read only | `query(…)->select(…)->fetchAll()` | Stops at R9. No session, no baseline. |
| Read writable | `to(…)->writable($session)->fetchOne()` | R1–R10. Same object is tracked as clean. |
| Write inbound graph | `update` / `create`, then `sync`, then `flush` | W1–W14. Flush also re-syncs already-tracked objects. |
| Write loaded object | `sync($obj)`, then `flush()` | Skip W1 if hydrate already set lifecycle. Do not skip W3–W6. |
| Unlink vs delete | `detach` vs `remove` | W7/W8 vs W6 delete. Relation planners own FK vs through-row. |

## Rethinkable

These are current implementation choices, not the work itself:

- `Session` as the container
- two public calls (`sync` then `flush`)
- Cycle as the adapter
- `RepresentationSchema` as the path type
- command objects as the write boundary
- wave-based flushing
- mapper as a separate runtime
- `LoadBranch` as the fetch tree
- collection wrappers for relations
