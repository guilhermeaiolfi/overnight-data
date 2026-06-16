# Phase 2 Public API

## Phase 3 Delta

- The public API remains behaviorally equivalent for the characterized methods in Phase 2.
- Constructor signatures for concrete definition wrappers now accept an optional second array-reference hydration argument used internally for registry restoration.
- `ON\Data\Definition\Collection\Collection` now also exposes `bindDefinitionArray(array &$items): void` as restoration infrastructure used by `Registry::register()`.

## `ON\Data\Definition\Collection\Collection`

- Type: class
- Parent: none
- Interfaces: `ON\Data\Definition\Collection\CollectionInterface`
- Traits: `ON\Data\Definition\MetadataTrait`
- Constructor: `__construct($registry)`
- Public methods:
  - `__construct(ON\Data\Definition\Registry $registry): mixed`
  - `table(string $table): self`
  - `getTable(): string`
  - `entity(string $entity): self`
  - `getEntity(): string`
  - `database(string $database): self`
  - `getDatabase(): string`
  - `parentCollection(string $parentCollection): self`
  - `getParentCollection(): ?string`
  - `scope(string $scope): self`
  - `getScope(): ?string`
  - `repository(?string $repository): self`
  - `getRepository(): ?string`
  - `mapper(?string $mapper): self`
  - `getMapper(): ?string`
  - `name(string $name): self`
  - `getName(): string`
  - `note(string $note): self`
  - `getNote(): ?string`
  - `description(?string $description): self`
  - `getDescription(): ?string`
  - `source(?string $source): self`
  - `getSource(): ?string`
  - `hidden(bool $hidden): self`
  - `isHidden(): bool`
  - `field(string $name, ?string $type = NULL): ON\Data\Definition\Field\FieldInterface`
  - `relation(string $name, string $type = 'ON\\Data\\Definition\\Relation\\HasOneRelation'): ON\Data\Definition\Relation\RelationInterface`
  - `hasMany(string $name, string $targetCollection): ON\Data\Definition\Relation\HasManyRelation`
  - `hasOne(string $name, string $targetCollection): ON\Data\Definition\Relation\HasOneRelation`
  - `belongsTo(string $name, string $targetCollection): ON\Data\Definition\Relation\BelongsToRelation`
  - `getPrimaryKeyFields(): mixed`
  - `getPrimaryKey(): ON\Data\Definition\Collection\PrimaryKeyDefinition`
  - `getVisibleFields(): array`
  - `getVisibleColumns(): array`
  - `getFieldNameByColumn(string $columnName): string`
  - `mapRowFromColumns(array $row): array`
  - `mapVisibleRowFromColumns(array $row): array`
  - `end(): ON\Data\Definition\Registry`
  - `getRegistry(): ON\Data\Definition\Registry`
  - `setFileDefinitionLocation(?string $file = NULL): void`
  - `getFileDefinitionLocation(): ?string`
  - `metadata(string $key, mixed $value = NULL): mixed`
- Public properties: `$fields`, `$relations`

## `ON\Data\Definition\Collection\CollectionInterface`

- Type: interface
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `entity(string $entity): self`
  - `getEntity(): string`
  - `table(string $table): self`
  - `getTable(): string`
  - `scope(string $scope): self`
  - `getScope(): ?string`
  - `source(?string $source): self`
  - `getSource(): ?string`
  - `database(string $database): self`
  - `getDatabase(): string`
  - `repository(?string $repository): self`
  - `getRepository(): ?string`
  - `mapper(?string $mapper): self`
  - `getMapper(): ?string`
  - `name(string $name): self`
  - `getName(): string`
  - `hidden(bool $hidden): self`
  - `isHidden(): bool`
  - `field(string $name, ?string $type = NULL): ON\Data\Definition\Field\FieldInterface`
  - `relation(string $name, string $type = 'ON\\Data\\Definition\\Relation\\HasOneRelation'): ON\Data\Definition\Relation\RelationInterface`
  - `hasMany(string $name, string $targetCollection): ON\Data\Definition\Relation\HasManyRelation`
  - `hasOne(string $name, string $targetCollection): ON\Data\Definition\Relation\HasOneRelation`
  - `belongsTo(string $name, string $targetCollection): ON\Data\Definition\Relation\BelongsToRelation`
  - `getPrimaryKeyFields(): mixed`
  - `getPrimaryKey(): ON\Data\Definition\Collection\PrimaryKeyDefinition`
  - `getVisibleFields(): array`
  - `getVisibleColumns(): array`
  - `getFieldNameByColumn(string $columnName): string`
  - `mapRowFromColumns(array $row): array`
  - `mapVisibleRowFromColumns(array $row): array`
  - `note(string $note): self`
  - `getNote(): ?string`
  - `description(?string $description): self`
  - `getDescription(): ?string`
  - `end(): ON\Data\Definition\Registry`
  - `getRegistry(): ON\Data\Definition\Registry`
  - `parentCollection(string $parentCollection): self`
  - `getParentCollection(): ?string`
  - `setFileDefinitionLocation(?string $file = NULL): void`
  - `getFileDefinitionLocation(): ?string`
  - `metadata(string $key, mixed $value = NULL): mixed`
- Public properties: none

## `ON\Data\Definition\Collection\PrimaryKeyDefinition`

- Type: class
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: `__construct($collection, $fields)`
- Public methods:
  - `__construct(ON\Data\Definition\Collection\CollectionInterface $collection, array $fields): mixed`
  - `getFields(): array`
  - `getFieldNames(): array`
  - `getColumns(): array`
  - `isComposite(): bool`
  - `extract(array $input, bool $allowColumnNames = true): ?ON\Data\Definition\Collection\PrimaryKeyValue`
  - `requireFromInput(array $input, string $context): ON\Data\Definition\Collection\PrimaryKeyValue`
  - `getMissingFieldNames(array $input): array`
  - `getValueFromUrlId(string $id): ON\Data\Definition\Collection\PrimaryKeyValue`
  - `getValue(ON\Data\Definition\Collection\PrimaryKeyValue|array|string|int|float $value): ON\Data\Definition\Collection\PrimaryKeyValue`
- Public properties: none

## `ON\Data\Definition\Collection\PrimaryKeyValue`

- Type: class
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: `__construct($collection, $values)`
- Public methods:
  - `__construct(ON\Data\Definition\Collection\CollectionInterface $collection, array $values): mixed`
  - `getCollection(): ON\Data\Definition\Collection\CollectionInterface`
  - `getValues(): array`
  - `getValue(string $fieldName): mixed`
  - `isComplete(): bool`
  - `toUrlId(): string`
  - `fromUrlId(ON\Data\Definition\Collection\CollectionInterface $collection, string $id): self`
- Public properties: none

## `ON\Data\Definition\Display\BooleanDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `labelOn(string $label): self`
  - `getLabelOn(): ?string`
  - `labelOff(string $label): self`
  - `getLabelOff(): ?string`
  - `iconOn(string $icon): self`
  - `getIconOn(): ?string`
  - `iconOff(string $icon): self`
  - `getIconOff(): ?string`
  - `colorOn(string $color): self`
  - `getColorOn(): ?string`
  - `colorOff(string $color): self`
  - `getColorOff(): ?string`
- Public properties: none

## `ON\Data\Definition\Display\DatetimeDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `format(string $format): self`
  - `getFormat(): string`
- Public properties: none

## `ON\Data\Definition\Display\DisplayInterface`

- Type: interface
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `type(string $type): self`
  - `getType(): string`
  - `setOptions(array $options): self`
  - `getOptions(): array`
  - `end(): mixed`
- Public properties: none

## `ON\Data\Definition\Display\DisplayTrait`

- Type: trait
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `display(string $type = 'ON\\Data\\Definition\\Display\\RawDisplay'): ON\Data\Definition\Display\DisplayInterface`
  - `getDisplay(): ON\Data\Definition\Display\DisplayInterface`
- Public properties: none

## `ON\Data\Definition\Display\FileDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods: none
- Public properties: none

## `ON\Data\Definition\Display\FormattedDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `color(string $color): self`
  - `getColor(): ?string`
  - `font(string $font): self`
  - `getFont(): ?string`
  - `italic(bool $italic): self`
  - `getItalic(): ?bool`
  - `bold(bool $bold): self`
  - `getBold(): ?bool`
  - `prefix(string $prefix): self`
  - `getPrefix(): ?string`
  - `suffix(string $suffix): self`
  - `getSuffix(): ?string`
  - `background(string $background): self`
  - `getBackground(): ?string`
  - `icon(string $icon): self`
  - `getIcon(): ?string`
- Public properties: none

## `ON\Data\Definition\Display\FormattedJSONDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `template(string $template): self`
  - `getTemplate(): ?string`
- Public properties: none

## `ON\Data\Definition\Display\IconDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `filled(bool $filled): self`
  - `isFilled(): ?bool`
  - `color(string $color): self`
  - `getColor(): ?string`
- Public properties: none

## `ON\Data\Definition\Display\ImageDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `displayAsCircle(bool $displayAsCircle): self`
  - `shouldDisplayAsCircle(): bool`
- Public properties: none

## `ON\Data\Definition\Display\LabelsDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `formatEachLabel(bool $formatEachLabel): self`
  - `isFormatEachLabel(): ?bool`
- Public properties: none

## `ON\Data\Definition\Display\RawDisplay`

- Type: class
- Parent: none
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `__construct(mixed $parent): mixed`
  - `type(string $type): self`
  - `getType(): string`
  - `end(): mixed`
  - `setOptions(array $options): self`
  - `getOptions(): array`
- Public properties: none

## `ON\Data\Definition\Display\RelatedDisplay`

- Type: class
- Parent: ON\Data\Definition\Display\RawDisplay
- Interfaces: `ON\Data\Definition\Display\DisplayInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `template(string $template): self`
  - `getTemplate(): ?string`
- Public properties: none

## `ON\Data\Definition\Exception\FieldException`

- Type: class
- Parent: Exception
- Interfaces: `Throwable`, `Stringable`
- Traits: none
- Constructor: `__construct($message, $code, $previous)`
- Public methods: none
- Public properties: none

## `ON\Data\Definition\Exception\RelationException`

- Type: class
- Parent: Exception
- Interfaces: `Throwable`, `Stringable`
- Traits: none
- Constructor: `__construct($message, $code, $previous)`
- Public methods: none
- Public properties: none

## `ON\Data\Definition\Field\Field`

- Type: class
- Parent: none
- Interfaces: `ON\Data\Definition\Field\FieldInterface`
- Traits: `ON\Data\Definition\Display\DisplayTrait`, `ON\Data\Definition\Interface\InterfaceTrait`, `ON\Data\Definition\Field\SchemaTrait`, `ON\Data\Definition\MetadataTrait`
- Constructor: `__construct($collection)`
- Public methods:
  - `__construct(ON\Data\Definition\Collection\CollectionInterface $collection): mixed`
  - `setGeneratedFromRelation(?string $relation_name): self`
  - `getGeneratedFromRelation(): ?string`
  - `default(mixed $default, bool $castDefault = true): self`
  - `getDefault(): mixed`
  - `hasDefault(): bool`
  - `castDefault(): bool`
  - `name(string $name): self`
  - `getName(): string`
  - `alias(string $alias): self`
  - `getAlias(): string`
  - `type(string $type): self`
  - `getType(): string`
  - `sensible(bool $sensible): self`
  - `getSensible(): bool`
  - `column(string $column): self`
  - `getColumn(): string`
  - `required(bool $required): self`
  - `isRequired(): bool`
  - `searchable(bool $searchable = true): self`
  - `isSearchable(): ?bool`
  - `hasTypecast(): bool`
  - `typecast(array|string|null $typecast): self`
  - `getTypecast(): array|string|null`
  - `validation(?string $rules, array $messages = array (
)): self`
  - `getValidation(): ?string`
  - `getValidationMessages(): array`
  - `description(?string $description): self`
  - `getDescription(): ?string`
  - `end(): ON\Data\Definition\Collection\CollectionInterface`
  - `display(string $type = 'ON\\Data\\Definition\\Display\\RawDisplay'): ON\Data\Definition\Display\DisplayInterface`
  - `getDisplay(): ON\Data\Definition\Display\DisplayInterface`
  - `interface(string $className): ON\Data\Definition\Interface\InterfaceInterface`
  - `getInterface(): ON\Data\Definition\Interface\InterfaceInterface`
  - `numericPrecision(int $numeric_precision): self`
  - `getNumericPrecision(): int`
  - `autoIncrement(bool $auto_increment): self`
  - `isAutoIncrement(): bool`
  - `primaryKey(bool $pk): self`
  - `isPrimaryKey(): bool`
  - `filterable(bool $filterable = true): self`
  - `isFilterable(): bool`
  - `dataType(mixed $data_type): self`
  - `getDataType(): mixed`
  - `defaultValue(mixed $default_value): self`
  - `getDefaultValue(): mixed`
  - `maxLength(int $max_length): self`
  - `getMaxLength(): int`
  - `nullable(bool $nullable): self`
  - `isNullable(): bool`
  - `hidden(bool $hidden): self`
  - `isHidden(): bool`
  - `unique(bool $unique): self`
  - `isUnique(): bool`
  - `indexed(bool $indexed): self`
  - `isIndexed(): bool`
  - `comment(string $comment): self`
  - `getComment(): ?string`
  - `metadata(string $key, mixed $value = NULL): mixed`
- Public properties: none

## `ON\Data\Definition\Field\FieldInterface`

- Type: interface
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `display(string $type = 'ON\\Data\\Definition\\Display\\RawDisplay'): ON\Data\Definition\Display\DisplayInterface`
  - `getDisplay(): ON\Data\Definition\Display\DisplayInterface`
  - `interface(string $className): ON\Data\Definition\Interface\InterfaceInterface`
  - `getInterface(): ON\Data\Definition\Interface\InterfaceInterface`
  - `name(string $name): self`
  - `getName(): string`
  - `alias(string $alias): self`
  - `getAlias(): string`
  - `setGeneratedFromRelation(?string $name): self`
  - `getGeneratedFromRelation(): ?string`
  - `type(string $type): self`
  - `getType(): string`
  - `column(string $column): self`
  - `getColumn(): string`
  - `hidden(bool $hidden): self`
  - `isHidden(): bool`
  - `primaryKey(bool $pk): self`
  - `isPrimaryKey(): bool`
  - `autoIncrement(bool $autoIncrement): self`
  - `isAutoIncrement(): bool`
  - `nullable(bool $nullable): self`
  - `isNullable(): bool`
  - `unique(bool $unique): self`
  - `isUnique(): bool`
  - `indexed(bool $indexed): self`
  - `isIndexed(): bool`
  - `comment(string $comment): self`
  - `getComment(): ?string`
  - `numericPrecision(int $numericPrecision): self`
  - `getNumericPrecision(): int`
  - `filterable(bool $filterable = true): self`
  - `isFilterable(): bool`
  - `searchable(bool $searchable = true): self`
  - `isSearchable(): ?bool`
  - `sensible(bool $sensible): self`
  - `getSensible(): bool`
  - `required(bool $required): self`
  - `isRequired(): bool`
  - `hasTypecast(): bool`
  - `typecast(array|string|null $typecast): self`
  - `getTypecast(): array|string|null`
  - `validation(?string $rules, array $messages = array (
)): self`
  - `getValidation(): ?string`
  - `getValidationMessages(): array`
  - `description(?string $description): self`
  - `getDescription(): ?string`
  - `end(): ON\Data\Definition\Collection\CollectionInterface`
- Public properties: none

## `ON\Data\Definition\Field\FieldMap`

- Type: class
- Parent: none
- Interfaces: `IteratorAggregate`, `Countable`, `Traversable`
- Traits: none
- Constructor: none
- Public methods:
  - `__clone(): mixed`
  - `count(): int`
  - `getColumnNames(): array`
  - `getFieldNameColumnNameMap(): array`
  - `getNames(): array`
  - `has(string $name): bool`
  - `hasColumn(string $name): bool`
  - `get(string $name): ON\Data\Definition\Field\Field`
  - `getKeyByColumnName(string $name): string`
  - `getByColumnName(string $name): ON\Data\Definition\Field\FieldInterface`
  - `set(string $name, ON\Data\Definition\Field\FieldInterface $field): self`
  - `remove(string $name): self`
  - `getIterator(): Traversable`
- Public properties: none

## `ON\Data\Definition\Field\SchemaTrait`

- Type: trait
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `numericPrecision(int $numeric_precision): self`
  - `getNumericPrecision(): int`
  - `autoIncrement(bool $auto_increment): self`
  - `isAutoIncrement(): bool`
  - `primaryKey(bool $pk): self`
  - `isPrimaryKey(): bool`
  - `filterable(bool $filterable = true): self`
  - `isFilterable(): bool`
  - `dataType(mixed $data_type): self`
  - `getDataType(): mixed`
  - `defaultValue(mixed $default_value): self`
  - `getDefaultValue(): mixed`
  - `maxLength(int $max_length): self`
  - `getMaxLength(): int`
  - `nullable(bool $nullable): self`
  - `isNullable(): bool`
  - `hidden(bool $hidden): self`
  - `isHidden(): bool`
  - `unique(bool $unique): self`
  - `isUnique(): bool`
  - `indexed(bool $indexed): self`
  - `isIndexed(): bool`
  - `comment(string $comment): self`
  - `getComment(): ?string`
  - `end(): ON\Data\Definition\Field\FieldInterface|ON\Data\Definition\Relation\RelationInterface`
- Public properties: none

## `ON\Data\Definition\Interface\AbstractInterface`

- Type: class
- Parent: none
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `__construct(mixed $parent): mixed`
  - `setOptions(array $options): self`
  - `getOptions(): array`
  - `end(): mixed`
- Public properties: none

## `ON\Data\Definition\Interface\AutocompleteInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `placeholder(array $placeholder): self`
  - `getPlaceholder(): ?string`
  - `trigger(bool $trigger): self`
  - `getTrigger(): string`
  - `rate(int $rate): self`
  - `getRate(): int`
  - `url(int $url): self`
  - `getUrl(): ?string`
  - `textPath(int $text_path): self`
  - `getTextPath(): ?string`
  - `valuePath(int $value_path): self`
  - `getValuePath(): ?string`
  - `resultPath(int $result_path): self`
  - `getResultPath(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\CodeInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `language(array $language): self`
  - `getLanguage(): ?string`
  - `wrapping(bool $wrapping): self`
  - `isWrapping(): bool`
  - `showLineNumbers(bool $line_numbers): self`
  - `shouldShowLineNumbers(): bool`
  - `limit(int $limit): self`
  - `getLimit(): int`
  - `template(string $template): self`
  - `getTemplate(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\ColorInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `presets(array $presets): self`
  - `getPresets(): array`
  - `opacity(bool $opacity): self`
  - `getOpacity(): bool`
- Public properties: none

## `ON\Data\Definition\Interface\DatetimeInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `use24hFormat(bool $use24hformat): self`
  - `is24hFormat(): bool`
  - `includeSeconds(bool $include_seconds): self`
  - `hasSeconds(): bool`
- Public properties: none

## `ON\Data\Definition\Interface\DropdownInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `choices(array $choices): self`
  - `getChoices(): array`
  - `allowOther(bool $allow_other): self`
  - `isAllowOther(): bool`
  - `allowNone(bool $allow_none): self`
  - `isAllowNone(): bool`
  - `placeholder(array $placeholder): self`
  - `getPlaceholder(): ?string`
  - `icon(array $icon): self`
  - `getIcon(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\DropdownMultipleInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `choices(array $choices): self`
  - `getChoices(): array`
  - `allowOther(bool $allow_other): self`
  - `isAllowOther(): bool`
  - `allowNone(bool $allow_none): self`
  - `isAllowNone(): bool`
  - `placeholder(array $placeholder): self`
  - `getPlaceholder(): ?string`
  - `icon(array $icon): self`
  - `getIcon(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\FileInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `rootFolder(string $rootFolder): self`
  - `getRootFolder(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\IconInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods: none
- Public properties: none

## `ON\Data\Definition\Interface\ImageInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `rootFolder(string $rootFolder): self`
  - `getRootFolder(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\InterfaceInterface`

- Type: interface
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `setOptions(array $options): self`
  - `getOptions(): array`
  - `end(): mixed`
- Public properties: none

## `ON\Data\Definition\Interface\InterfaceTrait`

- Type: trait
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `interface(string $className): ON\Data\Definition\Interface\InterfaceInterface`
  - `getInterface(): ON\Data\Definition\Interface\InterfaceInterface`
- Public properties: none

## `ON\Data\Definition\Interface\ManyToManyInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `allowDuplication(bool $allow_duplication): self`
  - `isAllowDuplication(): bool`
  - `showLink(bool $show_link): self`
  - `shouldShowLink(): bool`
  - `allowCreation(bool $allow_creation): self`
  - `isAllowCreation(): bool`
  - `allowSelection(bool $allow_selection): self`
  - `isAllowSelection(): bool`
  - `allowSearch(bool $allow_search): self`
  - `isAllowSearch(): bool`
  - `itemsPerPage(int $items_per_page): self`
  - `getItemsPerPage(): int`
  - `type(string $type): self`
  - `getType(): string`
  - `template(string $template): self`
  - `getTemplate(): ?string`
  - `columns(array $columns): self`
  - `getColumns(): ?array`
- Public properties: none

## `ON\Data\Definition\Interface\ManyToOneInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `template(string $template): self`
  - `getTemplate(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\MapInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `defaultView(string $default_view): self`
  - `getDefaultView(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\MarkdownInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `toolbar(array $toolbar): self`
  - `getToolbar(): ?array`
  - `folder(array $folder): self`
  - `getFolder(): string`
  - `limit(int $limit): self`
  - `getLimit(): int`
  - `view(int $view): self`
  - `getView(): string`
- Public properties: none

## `ON\Data\Definition\Interface\OneToManyInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `allowDuplication(bool $allow_duplication): self`
  - `isAllowDuplication(): bool`
  - `showLink(bool $show_link): self`
  - `shouldShowLink(): bool`
  - `allowCreation(bool $allow_creation): self`
  - `isAllowCreation(): bool`
  - `allowSelection(bool $allow_selection): self`
  - `isAllowSelection(): bool`
  - `allowSearch(bool $allow_search): self`
  - `isAllowSearch(): bool`
  - `itemsPerPage(int $items_per_page): self`
  - `getItemsPerPage(): int`
  - `type(string $type): self`
  - `getType(): string`
  - `template(string $template): self`
  - `getTemplate(): ?string`
  - `columns(array $columns): self`
  - `getColumns(): ?array`
- Public properties: none

## `ON\Data\Definition\Interface\RepeaterInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `template(string $template): self`
  - `getTemplate(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\TagsInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `whitespace(int $whitespace): self`
  - `getWhitespace(): ?int`
  - `capitalization(int $capitalization): self`
  - `getCapitalization(): ?int`
  - `allowOther(bool $allow_other): self`
  - `isAllowOther(): bool`
  - `az(bool $az): self`
  - `isAZ(): bool`
  - `presetTags(array $tags): self`
  - `getPresetTags(): ?array`
  - `placeholder(string $placeholder): self`
  - `getplaceholder(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\TextareaInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `placeholder(array $placeholder): self`
  - `getPlaceholder(): ?string`
  - `trim(bool $trim): self`
  - `getTrim(): bool`
  - `limit(int $limit): self`
  - `getLimit(): int`
- Public properties: none

## `ON\Data\Definition\Interface\ToggleInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `label(string $label): self`
  - `getLabel(): ?string`
  - `iconOn(bool $icon): self`
  - `getIconOn(): ?string`
  - `iconOff(bool $icon): self`
  - `getIconOff(): ?string`
  - `colorOn(bool $color): self`
  - `getColorOn(): ?string`
  - `colorOff(bool $color): self`
  - `getColorOff(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\TreeInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `template(string $template): self`
  - `getTemplate(): ?string`
- Public properties: none

## `ON\Data\Definition\Interface\WYSIWYGInterface`

- Type: class
- Parent: ON\Data\Definition\Interface\AbstractInterface
- Interfaces: `ON\Data\Definition\Interface\InterfaceInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `toolbar(array $toolbar): self`
  - `getToolbar(): ?array`
  - `folder(array $folder): self`
  - `getFolder(): string`
  - `limit(int $limit): self`
  - `getLimit(): int`
- Public properties: none

## `ON\Data\Definition\MetadataTrait`

- Type: trait
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `metadata(string $key, mixed $value = NULL): mixed`
- Public properties: none

## `ON\Data\Definition\Metadata\MetadataMap`

- Type: class
- Parent: none
- Interfaces: `IteratorAggregate`, `Traversable`
- Traits: none
- Constructor: none
- Public methods:
  - `has(string $key): bool`
  - `get(string $key, mixed $default = NULL): mixed`
  - `set(string $key, mixed $value): self`
  - `remove(string $key): self`
  - `all(): array`
  - `getIterator(): Traversable`
- Public properties: none

## `ON\Data\Definition\Registry`

- Type: class
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `register(ON\Data\Definition\Collection\CollectionInterface $collection): void`
  - `getDefinitionFiles(): array`
  - `collection(string $name): ON\Data\Definition\Collection\CollectionInterface`
  - `getCollection(ON\Data\Definition\Collection\CollectionInterface|string $name): ?ON\Data\Definition\Collection\CollectionInterface`
  - `getCollections(): array`
  - `getInheritedCollections(): array`
- Public properties: `$collections`

## `ON\Data\Definition\Relation\AbstractRelation`

- Type: class
- Parent: none
- Interfaces: `ON\Data\Definition\Relation\RelationInterface`
- Traits: `ON\Data\Definition\Display\DisplayTrait`, `ON\Data\Definition\Interface\InterfaceTrait`, `ON\Data\Definition\MetadataTrait`
- Constructor: `__construct($parent)`
- Public methods:
  - `__construct(ON\Data\Definition\Collection\CollectionInterface $parent): mixed`
  - `getParent(): ON\Data\Definition\Collection\CollectionInterface`
  - `name(string $name): self`
  - `getName(): string`
  - `collection(string $collectionName): self`
  - `getCollectionName(): string`
  - `getCollection(): ON\Data\Definition\Collection\CollectionInterface`
  - `nullable(bool $nullable): self`
  - `isNullable(): bool`
  - `where(array $where): self`
  - `getWhere(): array`
  - `orderBy(array $orderBy): self`
  - `getOrderBy(): array`
  - `cascade(bool $cascade): self`
  - `isCascade(): bool`
  - `load(string $load): self`
  - `getLoadStrategy(): string`
  - `innerKey(array|string $fieldName): self`
  - `getInnerKey(): array|string`
  - `innerKeys(): array`
  - `getInnerField(): ON\Data\Definition\Field\FieldInterface`
  - `outerKey(array|string $fieldName): self`
  - `getOuterKey(): array|string`
  - `outerKeys(): array`
  - `getOuterField(): ON\Data\Definition\Field\FieldInterface`
  - `loader(string $loader): self`
  - `getLoader(): ?string`
  - `getCardinality(): string`
  - `isJunction(): bool`
  - `end(): ON\Data\Definition\Collection\CollectionInterface`
  - `display(string $type = 'ON\\Data\\Definition\\Display\\RawDisplay'): ON\Data\Definition\Display\DisplayInterface`
  - `getDisplay(): ON\Data\Definition\Display\DisplayInterface`
  - `interface(string $className): ON\Data\Definition\Interface\InterfaceInterface`
  - `getInterface(): ON\Data\Definition\Interface\InterfaceInterface`
  - `metadata(string $key, mixed $value = NULL): mixed`
- Public properties: `$parent`

## `ON\Data\Definition\Relation\BelongsToRelation`

- Type: class
- Parent: ON\Data\Definition\Relation\HasOneRelation
- Interfaces: `ON\Data\Definition\Relation\RelationInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods: none
- Public properties: none

## `ON\Data\Definition\Relation\FirstOfManyRelation`

- Type: class
- Parent: ON\Data\Definition\Relation\HasManyRelation
- Interfaces: `ON\Data\Definition\Relation\RelationInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `getCardinality(): string`
- Public properties: none

## `ON\Data\Definition\Relation\HasManyRelation`

- Type: class
- Parent: ON\Data\Definition\Relation\AbstractRelation
- Interfaces: `ON\Data\Definition\Relation\RelationInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `getCardinality(): string`
- Public properties: none

## `ON\Data\Definition\Relation\HasOneRelation`

- Type: class
- Parent: ON\Data\Definition\Relation\AbstractRelation
- Interfaces: `ON\Data\Definition\Relation\RelationInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `exclusive(bool $exclusive): self`
  - `isExclusive(): bool`
  - `end(): ON\Data\Definition\Collection\CollectionInterface`
  - `generateField(): ?ON\Data\Definition\Field\FieldInterface`
  - `getLoader(): ?string`
- Public properties: none

## `ON\Data\Definition\Relation\M2MRelation`

- Type: class
- Parent: ON\Data\Definition\Relation\AbstractRelation
- Interfaces: `ON\Data\Definition\Relation\RelationInterface`
- Traits: none
- Constructor: `__construct($parent)`
- Public methods:
  - `getCardinality(): string`
  - `isJunction(): bool`
  - `through(string $collection): ON\Data\Definition\Relation\M2MThrough`
  - `collectionFactory(string $factory): self`
  - `getCollectionFactory(): string`
- Public properties: `$through`

## `ON\Data\Definition\Relation\M2MThrough`

- Type: class
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: `__construct($m2m)`
- Public methods:
  - `__construct(ON\Data\Definition\Relation\M2MRelation $m2m): mixed`
  - `collection(string $collectionName): self`
  - `getCollectionName(): string`
  - `getCollection(): ON\Data\Definition\Collection\CollectionInterface`
  - `innerKey(array|string $fieldName): self`
  - `getInnerKey(): array|string`
  - `getInnerField(): ON\Data\Definition\Field\FieldInterface`
  - `outerKey(array|string $fieldName): self`
  - `getOuterKey(): array|string`
  - `getOuterField(): ON\Data\Definition\Field\FieldInterface`
  - `throughInnerKeys(): array`
  - `throughOuterKeys(): array`
  - `where(array $where): self`
  - `getWhere(): array`
  - `end(): ON\Data\Definition\Relation\M2MRelation`
- Public properties: none

## `ON\Data\Definition\Relation\RelationInterface`

- Type: interface
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `display(string $type = 'ON\\Data\\Definition\\Display\\RawDisplay'): ON\Data\Definition\Display\DisplayInterface`
  - `getDisplay(): ON\Data\Definition\Display\DisplayInterface`
  - `interface(string $className): ON\Data\Definition\Interface\InterfaceInterface`
  - `getInterface(): ON\Data\Definition\Interface\InterfaceInterface`
  - `name(string $name): self`
  - `getName(): string`
  - `collection(string $collectionName): self`
  - `getCollectionName(): string`
  - `getCollection(): ON\Data\Definition\Collection\CollectionInterface`
  - `nullable(bool $nullable): self`
  - `isNullable(): bool`
  - `cascade(bool $cascade): self`
  - `isCascade(): bool`
  - `load(string $load): self`
  - `getLoadStrategy(): string`
  - `innerKey(array|string $fieldName): self`
  - `getInnerKey(): array|string`
  - `innerKeys(): array`
  - `getInnerField(): ON\Data\Definition\Field\FieldInterface`
  - `outerKey(array|string $fieldName): self`
  - `getOuterKey(): array|string`
  - `outerKeys(): array`
  - `getOuterField(): ON\Data\Definition\Field\FieldInterface`
  - `loader(string $loader): self`
  - `getLoader(): ?string`
  - `where(array $where): self`
  - `getWhere(): array`
  - `orderBy(array $orderBy): self`
  - `getOrderBy(): array`
  - `getCardinality(): string`
  - `isJunction(): bool`
  - `end(): ON\Data\Definition\Collection\CollectionInterface`
- Public properties: none

## `ON\Data\Definition\Relation\RelationMap`

- Type: class
- Parent: none
- Interfaces: `IteratorAggregate`, `Traversable`
- Traits: none
- Constructor: none
- Public methods:
  - `__clone(): mixed`
  - `has(string $name): bool`
  - `get(string $name): ON\Data\Definition\Relation\RelationInterface`
  - `set(string $name, ON\Data\Definition\Relation\RelationInterface $relation): self`
  - `remove(string $name): self`
  - `getIterator(): Traversable`
- Public properties: none

## `ON\Data\Definition\Schema\SchemaInterface`

- Type: interface
- Parent: none
- Interfaces: none
- Traits: none
- Constructor: none
- Public methods:
  - `end(): ON\Data\Definition\Field\FieldInterface|ON\Data\Definition\Relation\RelationInterface`
- Public properties: none

