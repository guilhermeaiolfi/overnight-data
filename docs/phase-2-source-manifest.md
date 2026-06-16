# Phase 2 Source Manifest

## `Collection/Collection.php`

- Namespace: `ON\ORM\Definition\Collection`
- Declarations: class `Collection`
- External dependencies: `Cycle\ORM\Mapper\StdMapper`, `ON\ORM\Select\Source`
- Overnight tests: `.cache/overnight/tests/GraphQL/GraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLSQLResolverTest.php`, `.cache/overnight/tests/GraphQL/Support/GraphQLTestFixtures.php`, `.cache/overnight/tests/Mapper/ConversionGatewayTest.php`, `.cache/overnight/tests/Mapper/FieldContextResolverTest.php`, `.cache/overnight/tests/Mapper/MapBuilderTest.php`, `.cache/overnight/tests/Mapper/MapperConfigTest.php`, `.cache/overnight/tests/Mapper/MappingBlueprintTest.php`, `.cache/overnight/tests/Mapper/NestedObjectMapperTest.php`, `.cache/overnight/tests/Mapper/StdClassMapperTest.php`, `.cache/overnight/tests/Mapper/UrlFieldTypeTest.php`, `.cache/overnight/tests/ORM/Container/RegistryFactoryBenchmarkTest.php`, `.cache/overnight/tests/ORM/PrimaryKeyDefinitionTest.php`, `.cache/overnight/tests/ORM/RelationDefinitionTest.php`, `.cache/overnight/tests/ORM/SelectTest.php`, `.cache/overnight/tests/ORM/Select/Trait/ColumnTraitTest.php`, `.cache/overnight/tests/RestApi/HandlerRegistryTest.php`, `.cache/overnight/tests/RestApi/MutationCompilerPassTest.php`, `.cache/overnight/tests/RestApi/MutationInputTest.php`, `.cache/overnight/tests/RestApi/NewsArticleMultipartUpdateTest.php`, `.cache/overnight/tests/RestApi/PayloadParserTest.php`, `.cache/overnight/tests/RestApi/QueryParserTest.php`, `.cache/overnight/tests/RestApi/RestApiConfigTest.php`, `.cache/overnight/tests/RestApi/RestApiEventTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`, `.cache/overnight/tests/RestApi/TypecastRestApiTest.php`, `.cache/overnight/tests/Validation/CollectionValidatorTest.php`

## `Collection/CollectionInterface.php`

- Namespace: `ON\ORM\Definition\Collection`
- Declarations: interface `CollectionInterface`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/RestApi/NewsArticleMultipartUpdateTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`

## `Collection/PrimaryKeyDefinition.php`

- Namespace: `ON\ORM\Definition\Collection`
- Declarations: class `PrimaryKeyDefinition`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/ORM/PrimaryKeyDefinitionTest.php`

## `Collection/PrimaryKeyValue.php`

- Namespace: `ON\ORM\Definition\Collection`
- Declarations: class `PrimaryKeyValue`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`

## `Display/BooleanDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `BooleanDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/DateTimeDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `DatetimeDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/DisplayInterface.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: interface `DisplayInterface`
- External dependencies: none
- Overnight tests: none found

## `Display/DisplayTrait.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: trait `DisplayTrait`
- External dependencies: none
- Overnight tests: none found

## `Display/FileDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `FileDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/FormattedDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `FormattedDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/FormattedJSONDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `FormattedJSONDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/IconDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `IconDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/ImageDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `ImageDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/LabelsDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `LabelsDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/RawDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `RawDisplay`
- External dependencies: none
- Overnight tests: none found

## `Display/RelatedDisplay.php`

- Namespace: `ON\ORM\Definition\Display`
- Declarations: class `RelatedDisplay`
- External dependencies: none
- Overnight tests: none found

## `Exception/FieldException.php`

- Namespace: `ON\ORM\Definition\Exception`
- Declarations: class `FieldException`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/ORM/Compiler/CycleRegistryGeneratorTest.php`

## `Exception/RelationException.php`

- Namespace: `ON\ORM\Definition\Exception`
- Declarations: class `RelationException`
- External dependencies: none
- Overnight tests: none found

## `Field/Field.php`

- Namespace: `ON\ORM\Definition\Field`
- Declarations: class `Field`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/CRM/Parser/QueryParserTest.php`, `.cache/overnight/tests/GraphQL/GraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLSQLResolverTest.php`, `.cache/overnight/tests/Http/MultipartFormDataParserTest.php`, `.cache/overnight/tests/Mapper/ConversionGatewayTest.php`, `.cache/overnight/tests/Mapper/FieldContextResolverTest.php`, `.cache/overnight/tests/Mapper/MapperConfigTest.php`, `.cache/overnight/tests/Mapper/MappingBlueprintTest.php`, `.cache/overnight/tests/Mapper/StdClassMapperTest.php`, `.cache/overnight/tests/Mapper/UrlFieldTypeTest.php`, `.cache/overnight/tests/ORM/Compiler/CycleRegistryGeneratorTest.php`, `.cache/overnight/tests/ORM/PrimaryKeyDefinitionTest.php`, `.cache/overnight/tests/ORM/RelationDefinitionTest.php`, `.cache/overnight/tests/ORM/SelectTest.php`, `.cache/overnight/tests/ORM/Select/Trait/ColumnTraitTest.php`, `.cache/overnight/tests/RestApi/HandlerRegistryTest.php`, `.cache/overnight/tests/RestApi/QueryParserTest.php`, `.cache/overnight/tests/RestApi/RestApiEventTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareValidationTest.php`, `.cache/overnight/tests/RestApi/SchemaAddonTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`, `.cache/overnight/tests/RestApi/TypecastRestApiTest.php`, `.cache/overnight/tests/Validation/CollectionValidatorTest.php`

## `Field/FieldInterface.php`

- Namespace: `ON\ORM\Definition\Field`
- Declarations: interface `FieldInterface`, interface `string`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/CRM/Parser/QueryParserTest.php`, `.cache/overnight/tests/Cache/CacheExtensionTest.php`, `.cache/overnight/tests/Console/ClearCacheCommandTest.php`, `.cache/overnight/tests/Container/PhpDiFactoryCachingTest.php`, `.cache/overnight/tests/Extension/AutoWiringExtensionTest.php`, `.cache/overnight/tests/Extension/ExtensionInitTest.php`, `.cache/overnight/tests/Extension/ExtensionPriorityTest.php`, `.cache/overnight/tests/FileRouting/FileRoutingExtensionTest.php`, `.cache/overnight/tests/Fixtures/DummyLoader.php`, `.cache/overnight/tests/Fixtures/TestPage.php`, `.cache/overnight/tests/GraphQL/CachedGraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLSQLResolverTest.php`, `.cache/overnight/tests/GraphQL/MetadataMapTest.php`, `.cache/overnight/tests/GraphQL/Support/GraphQLTestFixtures.php`, `.cache/overnight/tests/GraphQL/Support/SqliteTestDatabase.php`, `.cache/overnight/tests/Handler/NotFoundHandlerTest.php`, `.cache/overnight/tests/Image/ImageManagerTest.php`, `.cache/overnight/tests/Maintenance/MaintenanceMiddlewareTest.php`, `.cache/overnight/tests/Mapper/ConversionGatewayTest.php`, `.cache/overnight/tests/Mapper/FieldContextResolverTest.php`, `.cache/overnight/tests/Mapper/MapBuilderTest.php`, `.cache/overnight/tests/Mapper/MapperConfigTest.php`, `.cache/overnight/tests/Mapper/MapperRegistryTest.php`, `.cache/overnight/tests/Mapper/MappingBlueprintTest.php`, `.cache/overnight/tests/Mapper/NestedObjectMapperTest.php`, `.cache/overnight/tests/Mapper/StdClassMapperTest.php`, `.cache/overnight/tests/Middleware/AuthMiddlewareTest.php`, `.cache/overnight/tests/Middleware/ExecutionMiddlewareTest.php`, `.cache/overnight/tests/Middleware/PipelineExtensionTest.php`, `.cache/overnight/tests/Middleware/PlainPageIntegrationTest.php`, `.cache/overnight/tests/Middleware/ValidationMiddlewareTest.php`, `.cache/overnight/tests/ORM/Compiler/CycleRegistryGeneratorTest.php`, `.cache/overnight/tests/ORM/Container/RegistryFactoryBenchmarkTest.php`, `.cache/overnight/tests/ORM/PrimaryKeyDefinitionTest.php`, `.cache/overnight/tests/ORM/RelationDefinitionTest.php`, `.cache/overnight/tests/RestApi/HandlerRegistryTest.php`, `.cache/overnight/tests/RestApi/MutationCompilerPassTest.php`, `.cache/overnight/tests/RestApi/NewsArticleMultipartUpdateTest.php`, `.cache/overnight/tests/RestApi/QueryParserTest.php`, `.cache/overnight/tests/RestApi/RestActionRouterTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareValidationTest.php`, `.cache/overnight/tests/RestApi/SchemaAddonTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/CycleSqliteTestDatabase.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`, `.cache/overnight/tests/Router/RouterTest.php`, `.cache/overnight/tests/Session/NativeSessionTest.php`, `.cache/overnight/tests/Validation/CollectionValidatorTest.php`, `.cache/overnight/tests/View/LatteExtensionTest.php`, `.cache/overnight/tests/View/ViewBuilderTraitTest.php`, `.cache/overnight/tests/View/ViewTest.php`

## `Field/FieldMap.php`

- Namespace: `ON\ORM\Definition\Field`
- Declarations: class `FieldMap`
- External dependencies: none
- Overnight tests: none found

## `Field/SchemaTrait.php`

- Namespace: `ON\ORM\Definition\Field`
- Declarations: trait `SchemaTrait`
- External dependencies: none
- Overnight tests: none found

## `Interface/AbstractInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `AbstractInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/AutocompleteInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `AutocompleteInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/CodeInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `CodeInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/ColorInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `ColorInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/DatetimeInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `DatetimeInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/DropdownInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `DropdownInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/DropdownMultipleInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `DropdownMultipleInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/FileInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `FileInterface`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/Http/MultipartFormDataParserTest.php`, `.cache/overnight/tests/RestApi/MutationCompilerPassTest.php`, `.cache/overnight/tests/RestApi/NewsArticleMultipartUpdateTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`

## `Interface/IconInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `IconInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/ImageInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `ImageInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/InterfaceInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: interface `InterfaceInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/InterfaceTrait.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: trait `InterfaceTrait`, interface `string`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/CRM/Parser/QueryParserTest.php`, `.cache/overnight/tests/Cache/CacheExtensionTest.php`, `.cache/overnight/tests/Console/ClearCacheCommandTest.php`, `.cache/overnight/tests/Container/PhpDiFactoryCachingTest.php`, `.cache/overnight/tests/Extension/AutoWiringExtensionTest.php`, `.cache/overnight/tests/Extension/ExtensionInitTest.php`, `.cache/overnight/tests/Extension/ExtensionPriorityTest.php`, `.cache/overnight/tests/FileRouting/FileRoutingExtensionTest.php`, `.cache/overnight/tests/Fixtures/DummyLoader.php`, `.cache/overnight/tests/Fixtures/TestPage.php`, `.cache/overnight/tests/GraphQL/CachedGraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLSQLResolverTest.php`, `.cache/overnight/tests/GraphQL/MetadataMapTest.php`, `.cache/overnight/tests/GraphQL/Support/GraphQLTestFixtures.php`, `.cache/overnight/tests/GraphQL/Support/SqliteTestDatabase.php`, `.cache/overnight/tests/Handler/NotFoundHandlerTest.php`, `.cache/overnight/tests/Image/ImageManagerTest.php`, `.cache/overnight/tests/Maintenance/MaintenanceMiddlewareTest.php`, `.cache/overnight/tests/Mapper/ConversionGatewayTest.php`, `.cache/overnight/tests/Mapper/FieldContextResolverTest.php`, `.cache/overnight/tests/Mapper/MapBuilderTest.php`, `.cache/overnight/tests/Mapper/MapperConfigTest.php`, `.cache/overnight/tests/Mapper/MapperRegistryTest.php`, `.cache/overnight/tests/Mapper/MappingBlueprintTest.php`, `.cache/overnight/tests/Mapper/NestedObjectMapperTest.php`, `.cache/overnight/tests/Mapper/StdClassMapperTest.php`, `.cache/overnight/tests/Middleware/AuthMiddlewareTest.php`, `.cache/overnight/tests/Middleware/ExecutionMiddlewareTest.php`, `.cache/overnight/tests/Middleware/PipelineExtensionTest.php`, `.cache/overnight/tests/Middleware/PlainPageIntegrationTest.php`, `.cache/overnight/tests/Middleware/ValidationMiddlewareTest.php`, `.cache/overnight/tests/ORM/Compiler/CycleRegistryGeneratorTest.php`, `.cache/overnight/tests/ORM/Container/RegistryFactoryBenchmarkTest.php`, `.cache/overnight/tests/ORM/PrimaryKeyDefinitionTest.php`, `.cache/overnight/tests/ORM/RelationDefinitionTest.php`, `.cache/overnight/tests/RestApi/HandlerRegistryTest.php`, `.cache/overnight/tests/RestApi/MutationCompilerPassTest.php`, `.cache/overnight/tests/RestApi/NewsArticleMultipartUpdateTest.php`, `.cache/overnight/tests/RestApi/QueryParserTest.php`, `.cache/overnight/tests/RestApi/RestActionRouterTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareValidationTest.php`, `.cache/overnight/tests/RestApi/SchemaAddonTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/CycleSqliteTestDatabase.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`, `.cache/overnight/tests/Router/RouterTest.php`, `.cache/overnight/tests/Session/NativeSessionTest.php`, `.cache/overnight/tests/Validation/CollectionValidatorTest.php`, `.cache/overnight/tests/View/LatteExtensionTest.php`, `.cache/overnight/tests/View/ViewBuilderTraitTest.php`, `.cache/overnight/tests/View/ViewTest.php`

## `Interface/ManyToManyInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `ManyToManyInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/ManyToOneInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `ManyToOneInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/MapInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `MapInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/MarkdownInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `MarkdownInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/OneToManyInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `ManyToManyInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/RepeaterInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `RepeaterInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/TagsInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `TagsInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/TextareaInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `TextareaInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/ToggleInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `ToggleInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/TreeInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `TreeInterface`
- External dependencies: none
- Overnight tests: none found

## `Interface/WYSIWYGInterface.php`

- Namespace: `ON\ORM\Definition\Interface`
- Declarations: class `WYSIWYGInterface`
- External dependencies: none
- Overnight tests: none found

## `MetadataTrait.php`

- Namespace: `ON\ORM\Definition`
- Declarations: trait `MetadataTrait`
- External dependencies: none
- Overnight tests: none found

## `Metadata/MetadataMap.php`

- Namespace: `ON\ORM\Definition\Metadata`
- Declarations: class `MetadataMap`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/GraphQL/MetadataMapTest.php`

## `Registry.php`

- Namespace: `ON\ORM\Definition`
- Declarations: class `Registry`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/CRM/Parser/QueryParserTest.php`, `.cache/overnight/tests/Cache/CacheClearerRegistryTest.php`, `.cache/overnight/tests/Cache/CacheExtensionTest.php`, `.cache/overnight/tests/Console/ClearCacheCommandTest.php`, `.cache/overnight/tests/Fixtures/UserPartsRegistry.php`, `.cache/overnight/tests/GraphQL/CachedGraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLSQLResolverTest.php`, `.cache/overnight/tests/GraphQL/Support/GraphQLTestFixtures.php`, `.cache/overnight/tests/Image/ImageManagerTest.php`, `.cache/overnight/tests/Mapper/ConversionGatewayTest.php`, `.cache/overnight/tests/Mapper/FieldContextResolverTest.php`, `.cache/overnight/tests/Mapper/MapBuilderTest.php`, `.cache/overnight/tests/Mapper/MapperConfigTest.php`, `.cache/overnight/tests/Mapper/MapperRegistryTest.php`, `.cache/overnight/tests/Mapper/MappingBlueprintTest.php`, `.cache/overnight/tests/Mapper/StdClassMapperTest.php`, `.cache/overnight/tests/Mapper/UrlFieldTypeTest.php`, `.cache/overnight/tests/ORM/Compiler/CycleRegistryGeneratorTest.php`, `.cache/overnight/tests/ORM/Container/RegistryFactoryBenchmarkTest.php`, `.cache/overnight/tests/ORM/PrimaryKeyDefinitionTest.php`, `.cache/overnight/tests/ORM/RelationDefinitionTest.php`, `.cache/overnight/tests/ORM/SelectTest.php`, `.cache/overnight/tests/ORM/Select/Trait/ColumnTraitTest.php`, `.cache/overnight/tests/PathRegistryTest.php`, `.cache/overnight/tests/RestApi/HandlerRegistryTest.php`, `.cache/overnight/tests/RestApi/MutationCompilerPassTest.php`, `.cache/overnight/tests/RestApi/MutationInputTest.php`, `.cache/overnight/tests/RestApi/NewsArticleMultipartUpdateTest.php`, `.cache/overnight/tests/RestApi/PayloadParserTest.php`, `.cache/overnight/tests/RestApi/QueryParserTest.php`, `.cache/overnight/tests/RestApi/RestApiEventTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareValidationTest.php`, `.cache/overnight/tests/RestApi/SchemaAddonTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`, `.cache/overnight/tests/RestApi/TypecastRestApiTest.php`, `.cache/overnight/tests/Validation/CollectionValidatorTest.php`, `.cache/overnight/tests/View/LatteExtensionTest.php`

## `Relation/AbstractRelation.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `AbstractRelation`
- External dependencies: none
- Overnight tests: none found

## `Relation/BelongsToRelation.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `BelongsToRelation`
- External dependencies: `ON\ORM\Select\Loader\BelongsToLoader`
- Overnight tests: `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`

## `Relation/FirstOfManyRelation.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `FirstOfManyRelation`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`

## `Relation/HasManyRelation.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `HasManyRelation`
- External dependencies: `ON\ORM\Select\Loader\HasManyLoader`
- Overnight tests: `.cache/overnight/tests/GraphQL/Support/GraphQLTestFixtures.php`

## `Relation/HasOneRelation.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `HasOneRelation`
- External dependencies: `ON\ORM\Select\Loader\BelongsToLoader`
- Overnight tests: none found

## `Relation/M2MRelation.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `M2MRelation`
- External dependencies: `ON\ORM\Select\Loader\ManyToManyLoader`
- Overnight tests: `.cache/overnight/tests/ORM/RelationDefinitionTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`

## `Relation/M2MThrough.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `M2MThrough`
- External dependencies: none
- Overnight tests: none found

## `Relation/RelationInterface.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: interface `RelationInterface`, interface `string`
- External dependencies: none
- Overnight tests: `.cache/overnight/tests/CRM/Parser/QueryParserTest.php`, `.cache/overnight/tests/Cache/CacheExtensionTest.php`, `.cache/overnight/tests/Console/ClearCacheCommandTest.php`, `.cache/overnight/tests/Container/PhpDiFactoryCachingTest.php`, `.cache/overnight/tests/Extension/AutoWiringExtensionTest.php`, `.cache/overnight/tests/Extension/ExtensionInitTest.php`, `.cache/overnight/tests/Extension/ExtensionPriorityTest.php`, `.cache/overnight/tests/FileRouting/FileRoutingExtensionTest.php`, `.cache/overnight/tests/Fixtures/DummyLoader.php`, `.cache/overnight/tests/Fixtures/TestPage.php`, `.cache/overnight/tests/GraphQL/CachedGraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLRegistryGeneratorTest.php`, `.cache/overnight/tests/GraphQL/GraphQLSQLResolverTest.php`, `.cache/overnight/tests/GraphQL/MetadataMapTest.php`, `.cache/overnight/tests/GraphQL/Support/GraphQLTestFixtures.php`, `.cache/overnight/tests/GraphQL/Support/SqliteTestDatabase.php`, `.cache/overnight/tests/Handler/NotFoundHandlerTest.php`, `.cache/overnight/tests/Image/ImageManagerTest.php`, `.cache/overnight/tests/Maintenance/MaintenanceMiddlewareTest.php`, `.cache/overnight/tests/Mapper/ConversionGatewayTest.php`, `.cache/overnight/tests/Mapper/FieldContextResolverTest.php`, `.cache/overnight/tests/Mapper/MapBuilderTest.php`, `.cache/overnight/tests/Mapper/MapperConfigTest.php`, `.cache/overnight/tests/Mapper/MapperRegistryTest.php`, `.cache/overnight/tests/Mapper/MappingBlueprintTest.php`, `.cache/overnight/tests/Mapper/NestedObjectMapperTest.php`, `.cache/overnight/tests/Mapper/StdClassMapperTest.php`, `.cache/overnight/tests/Middleware/AuthMiddlewareTest.php`, `.cache/overnight/tests/Middleware/ExecutionMiddlewareTest.php`, `.cache/overnight/tests/Middleware/PipelineExtensionTest.php`, `.cache/overnight/tests/Middleware/PlainPageIntegrationTest.php`, `.cache/overnight/tests/Middleware/ValidationMiddlewareTest.php`, `.cache/overnight/tests/ORM/Compiler/CycleRegistryGeneratorTest.php`, `.cache/overnight/tests/ORM/Container/RegistryFactoryBenchmarkTest.php`, `.cache/overnight/tests/ORM/PrimaryKeyDefinitionTest.php`, `.cache/overnight/tests/ORM/RelationDefinitionTest.php`, `.cache/overnight/tests/RestApi/HandlerRegistryTest.php`, `.cache/overnight/tests/RestApi/MutationCompilerPassTest.php`, `.cache/overnight/tests/RestApi/NewsArticleMultipartUpdateTest.php`, `.cache/overnight/tests/RestApi/QueryParserTest.php`, `.cache/overnight/tests/RestApi/RestActionRouterTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareTest.php`, `.cache/overnight/tests/RestApi/RestMiddlewareValidationTest.php`, `.cache/overnight/tests/RestApi/SchemaAddonTest.php`, `.cache/overnight/tests/RestApi/SqlRestResolverTest.php`, `.cache/overnight/tests/RestApi/Support/CycleSqliteTestDatabase.php`, `.cache/overnight/tests/RestApi/Support/RestApiTestFixtures.php`, `.cache/overnight/tests/Router/RouterTest.php`, `.cache/overnight/tests/Session/NativeSessionTest.php`, `.cache/overnight/tests/Validation/CollectionValidatorTest.php`, `.cache/overnight/tests/View/LatteExtensionTest.php`, `.cache/overnight/tests/View/ViewBuilderTraitTest.php`, `.cache/overnight/tests/View/ViewTest.php`

## `Relation/RelationMap.php`

- Namespace: `ON\ORM\Definition\Relation`
- Declarations: class `RelationMap`
- External dependencies: none
- Overnight tests: none found

## `Schema/SchemaInterface.php`

- Namespace: `ON\ORM\Definition\Schema`
- Declarations: interface `SchemaInterface`
- External dependencies: none
- Overnight tests: none found

