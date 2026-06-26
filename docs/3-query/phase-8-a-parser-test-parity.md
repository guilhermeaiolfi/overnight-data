# Phase 8A Parser Test Parity

This document records the upstream parser-test sources reviewed during the Cycle ORM adoption for Phase 8A.

## Upstream structural parser tests

| Upstream test file | Upstream test method | Local test file | Local test method | Status | Notes |
| --- | --- | --- | --- | --- | --- |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testRoot` | `tests/Query/Result/Parser/NodeTest.php` | `testRoot` | ported with API adaptation | `ArrayNode` became `CollectionNode`. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testRootDuplicate` | `tests/Query/Result/Parser/NodeTest.php` | `testRootDuplicate` | ported unchanged in behavior | Duplicate root folding preserved. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testSingular` | `tests/Query/Result/Parser/NodeTest.php` | `testJoinedSingular` | ported with API adaptation | Method renamed for clarity. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testGetReferences` | `tests/Query/Result/Parser/NodeTest.php` | `testGetReferences` | ported with API adaptation | References are raw values from `ReferenceIndex`. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testGetReferencesWithoutParent` | `tests/Query/Result/Parser/NodeTest.php` | `testGetReferencesWithoutParent` | ported unchanged in behavior | Same failure case. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testSingularOverExternal` | `tests/Query/Result/Parser/NodeTest.php` | `testLinkedSingular` | ported with API adaptation | Linked parsing terminology aligned to ON\Data. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testSingularInvalidReference` | `tests/Query/Result/Parser/NodeTest.php` | `testSingularInvalidReference` | ported unchanged in behavior | Invalid linked singular reference still throws. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testInvalidColumnCount` | `tests/Query/Result/Parser/NodeTest.php` | `testInvalidColumnCount` | ported unchanged in behavior | Invalid row width still throws. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testGetNode` | `tests/Query/Result/Parser/NodeTest.php` | `testGetNode` | ported unchanged in behavior | Child lookup preserved. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testGetUndefinedNode` | `tests/Query/Result/Parser/NodeTest.php` | `testGetUndefinedNode` | ported unchanged in behavior | Undefined child lookup still throws. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testSingularParseWithoutParent` | `tests/Query/Result/Parser/NodeTest.php` | `testSingularParseWithoutParent` | ported unchanged in behavior | Orphan singular parse still throws. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testArray` | `tests/Query/Result/Parser/NodeTest.php` | `testJoinedCollection` | ported with API adaptation | `ArrayNode` became `CollectionNode`. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testArrayInvalidReference` | `tests/Query/Result/Parser/NodeTest.php` | `testCollectionInvalidReference` | ported with API adaptation | `ArrayNode` became `CollectionNode`. |
| `tests/ORM/Unit/Parser/NodeTest.php` | `testArrayWithoutParent` | `tests/Query/Result/Parser/NodeTest.php` | `testCollectionParseWithoutParent` | ported with API adaptation | `ArrayNode` became `CollectionNode`. |
| `tests/ORM/Unit/Parser/TypecastTest.php` | all methods | n/a | n/a | excluded: typecasting | Conversion remains in the existing ON\Data mapper. |
| `tests/ORM/Unit/Parser/CompositeTypecastTest.php` | all methods | n/a | n/a | excluded: typecasting | Composite parser typecasting was intentionally not imported. |

## Repository-wide upstream search follow-up

The pinned Cycle ORM repository was searched for structural parser classes beyond the direct parser unit tests. The following loader-driven behaviors informed direct parser-level ON\Data coverage:

| Upstream test file | Upstream test method | Local test file | Local test method | Status | Notes |
| --- | --- | --- | --- | --- | --- |
| `tests/ORM/Functional/Driver/Common/Relation/Embedded/EmbeddedLoaderTest.php` | `testDeduplicate` | `tests/Query/Result/Parser/AdvancedNodeTest.php` | `testEmbeddedNodeMountsIntoTheMostRecentlyParsedParent` | covered by another local test | Reproduced as a parser-only embedded mounting assertion. |
| `tests/ORM/Functional/Driver/Common/Relation/Morphed/BelongsToMorphedRelationTest.php` | `testGetParentLoaded` | `tests/Query/Result/Parser/AdvancedNodeTest.php` | `testProxyNodeMountsRoleSpecificSingularChildren` | covered by another local test | Morphed proxy behavior reduced to parser-only role mounting. |
| `tests/ORM/Functional/Driver/Common/Inheritance/JTI/SimpleCasesTest.php` | `testSelectEmployeeAllDataWithInheritance` | `tests/Query/Result/Parser/AdvancedNodeTest.php` | `testParentMergeNodeMergesIntoParentRecords` | covered by another local test | Parent merge behavior reproduced directly at parser level. |
| `tests/ORM/Functional/Driver/Common/Inheritance/JTI/SimpleCasesTest.php` | `testSelectEngineerAllDataWithInheritance` | `tests/Query/Result/Parser/AdvancedNodeTest.php` | `testSubclassMergeNodeCanIncludeTheDiscriminatorField` | covered by another local test | Subclass merge behavior reproduced directly at parser level. |
| `tests/ORM/Functional/Driver/Common/Relation/Embedded/EmbeddedLoaderTest.php` | `testLoadDataLoadTypecast` | n/a | n/a | excluded: typecasting | The structural port intentionally omits parser-local type conversion. |
| `tests/ORM/Functional/Driver/Common/Relation/Morphed/BelongsToMorphedRelationTest.php` | write and persistence methods | n/a | n/a | not applicable: documented reason | These methods assert ORM persistence semantics rather than parser structure. |
| `tests/ORM/Functional/Driver/Common/Inheritance/JTI/SimpleCasesTest.php` | persistence and query-ordering methods | n/a | n/a | deferred: requires loader integration | These rely on query execution and loader orchestration scheduled for later phases. |

## Imported production classes and local coverage

| Imported class | Local coverage |
| --- | --- |
| `AbstractNode` | `NodeTest`, `NestedNodeTest`, `OwnershipTest` |
| `AbstractMergeNode` | `AdvancedNodeTest` |
| `CollectionNode` | `NodeTest`, `NestedNodeTest`, `AdvancedNodeTest`, `OwnershipTest` |
| `EmbeddedNode` | `AdvancedNodeTest` |
| `OutputNode` | `NodeTest`, `AdvancedNodeTest` |
| `ParentMergeNode` | `AdvancedNodeTest` |
| `ProxyNode` | `AdvancedNodeTest` |
| `ReferenceIndex` | `ReferenceIndexTest` |
| `RootNode` | `NodeTest`, `IdentityEncodingTest`, `NestedNodeTest`, `AdvancedNodeTest`, `OwnershipTest` |
| `SingularNode` | `NodeTest`, `NestedNodeTest`, `AdvancedNodeTest` |
| `StaticNode` | `AdvancedNodeTest` |
| `SubclassMergeNode` | `AdvancedNodeTest` |
| `Traits/DuplicateTrait` | `NodeTest`, `IdentityEncodingTest`, `NestedNodeTest` |
