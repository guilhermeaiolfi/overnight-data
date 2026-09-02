# Definition Extension Guide

`ON\Data` supports a class-based, array-backed extension model. Custom definition nodes participate in registry export and restoration only when they follow the same storage and ownership rules as the built-in nodes.

## Plugins

Prefer a `DefinitionPlugin` subclass when you need typed extras without growing `Collection` / `Field` / `Relation` methods.

```php
$users->extend(SoftDeletePlugin::class, 'deleted_at');

$plugin = $users->getPlugin(SoftDeletePlugin::class);
$plugin?->getValue();
```

One-value plugins can be an empty `DefinitionPlugin` subclass. `extend($class, $value)` stores that payload on `value` and returns the host, so there is no nested setter or `end()`.

Omit the second argument to enter the plugin when it has more to configure:

```php
$users->extend(SoftDeletePlugin::class)
    ->value('deleted_at')
    ->end();
```

- `extend(PluginClass::class)` creates the plugin in place on the host array slot, the same way `display(RawDisplay::class)` and `interface(DropdownInterface::class)` create widgets.
- Nested fluent `end()` returns the plugin so you can keep configuring it; plugin `end()` returns the host.
- `extend(PluginClass::class, $value)` sets `value` and stays on the host.
- A second `extend()` for the same class resumes the bound plugin instead of replacing it.
- `getPlugin(PluginClass::class)` restores a bound instance over that slot.

Built-in plugins: `UiDefinitionPlugin` (interface + display widgets) and `CycleCollectionPlugin` (entity / repository / mapper / scope).

Custom plugins must extend `DefinitionPlugin`, use `definitionDefaults()`, keep plain array data, and avoid a custom constructor.

## Supported subclass points

You can still extend the definition layer with custom subclasses of:

- `Collection`
- `Field`
- `ViewDefinition`
- `ViewField`
- relation definitions
- display definitions
- interface-definition nodes
- nested child nodes owned by those custom definitions or by plugins

## Required contract

Stored wrappers that must round-trip through `Registry` need to:

- extend `ON\Data\Support\DefinitionNode`
- implement the expected public interface
- define canonical defaults through `definitionDefaults()`
- inherit the built-in owner/name/array constructor contract
- avoid declaring a custom constructor
- keep exported state as plain array data only
- initialize runtime-only caches from `initializeRuntimeState()` when they own nested children

`DefinitionFactory` validates classes and constructs wrappers directly over final array slots. It does not normalize, rebind, or cache wrappers globally.

## Important boundary

Implementing only `FieldInterface`, `RelationInterface`, or another public interface is not enough for registry restoration. If a stored wrapper does not extend `DefinitionNode`, the registry cannot persist and restore it as canonical definition data.

## Plain-data rule

Definition exports may contain only arrays, strings, integers, floats, booleans, and `null`. Objects, closures, and resources are rejected by `Registry::all()`.

## Ownership rules

- Do not create orphan stored nodes and attach them later.
- Create custom fields, relations, displays, interfaces, and plugins through the owning API and class-string extension points.
- Names come from owner map keys and are not mutable.
- Stored definition nodes cannot be cloned.
