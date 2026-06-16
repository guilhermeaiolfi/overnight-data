<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sourceRoot = $root . '/.cache/overnight/src/ORM/Definition';
$targetRoot = $root . '/src/Definition';
$testsRoot = $root . '/.cache/overnight/tests';

require $root . '/vendor/autoload.php';

/**
 * @return list<string>
 */
function phpFiles(string $root): array
{
	$files = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
	foreach ($iterator as $file) {
		if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
			continue;
		}

		$files[] = $file->getPathname();
	}

	sort($files);

	return $files;
}

/**
 * @return array{namespace:string,declarations:list<array{type:string,name:string}>,uses:list<string>}
 */
function parsePhpFile(string $file): array
{
	$tokens = token_get_all((string) file_get_contents($file));
	$namespace = '';
	$declarations = [];
	$uses = [];
	$braceDepth = 0;
	$previousSignificant = null;

	for ($i = 0, $count = count($tokens); $i < $count; $i++) {
		$token = $tokens[$i];
		if ($token === '{') {
			$braceDepth++;
			continue;
		}
		if ($token === '}') {
			$braceDepth--;
			continue;
		}
		if (! is_array($token)) {
			continue;
		}

		if ($token[0] === T_NAMESPACE) {
			$name = '';
			for ($j = $i + 1; $j < $count; $j++) {
				$current = $tokens[$j];
				if ($current === ';' || $current === '{') {
					break;
				}
				if (is_array($current) && in_array($current[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
					$name .= $current[1];
				}
			}
			$namespace = $name;
		}

		if ($token[0] === T_USE && $braceDepth === 0) {
			$name = '';
			for ($j = $i + 1; $j < $count; $j++) {
				$current = $tokens[$j];
				if ($current === ';') {
					if ($name !== '') {
						$uses[] = trim($name);
					}
					break;
				}
				if ($current === ',') {
					if ($name !== '') {
						$uses[] = trim($name);
					}
					$name = '';
					continue;
				}
				if (is_array($current) && in_array($current[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
					$name .= $current[1];
				}
			}
		}

		if (
			in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
			&& $previousSignificant !== T_DOUBLE_COLON
			&& $previousSignificant !== T_NEW
		) {
			for ($j = $i + 1; $j < $count; $j++) {
				$current = $tokens[$j];
				if (is_array($current) && $current[0] === T_STRING) {
					$declarations[] = [
						'type' => strtolower(str_replace('T_', '', token_name($token[0]))),
						'name' => $current[1],
					];
					break;
				}
			}
		}

		if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
			$previousSignificant = $token[0];
		}
	}

	return [
		'namespace' => $namespace,
		'declarations' => $declarations,
		'uses' => array_values(array_unique($uses)),
	];
}

/**
 * @return list<string>
 */
function runtimeDependencies(array $uses): array
{
	$dependencies = [];
	foreach ($uses as $use) {
		if (! str_contains($use, '\\')) {
			continue;
		}
		if (str_starts_with($use, 'ON\\ORM\\Definition') || str_starts_with($use, 'ON\\Data\\Definition')) {
			continue;
		}

		$dependencies[] = $use;
	}

	sort($dependencies);

	return array_values(array_unique($dependencies));
}

$sourceFiles = phpFiles($sourceRoot);
$sourceManifestLines = [
	'# Phase 2 Source Manifest',
	'',
];

foreach ($sourceFiles as $file) {
	$relative = str_replace('\\', '/', substr($file, strlen($sourceRoot) + 1));
	$parsed = parsePhpFile($file);
	$declarationSummary = [];
	foreach ($parsed['declarations'] as $declaration) {
		$declarationSummary[] = sprintf('%s `%s`', $declaration['type'], $declaration['name']);
	}

	$equivalentTests = [];
	foreach (phpFiles($testsRoot) as $testFile) {
		$testContents = (string) file_get_contents($testFile);
		foreach ($parsed['declarations'] as $declaration) {
			if (str_contains($testContents, $declaration['name'])) {
				$equivalentTests[] = str_replace('\\', '/', substr($testFile, strlen($root) + 1));
				break;
			}
		}
	}

	$sourceManifestLines[] = '## `' . $relative . '`';
	$sourceManifestLines[] = '';
	$sourceManifestLines[] = '- Namespace: `' . $parsed['namespace'] . '`';
	$sourceManifestLines[] = '- Declarations: ' . ($declarationSummary === [] ? 'none' : implode(', ', $declarationSummary));
	$sourceManifestLines[] = '- External dependencies: ' . (runtimeDependencies($parsed['uses']) === [] ? 'none' : '`' . implode('`, `', runtimeDependencies($parsed['uses'])) . '`');
	$sourceManifestLines[] = '- Overnight tests: ' . ($equivalentTests === [] ? 'none found' : '`' . implode('`, `', $equivalentTests) . '`');
	$sourceManifestLines[] = '';
}

file_put_contents($root . '/docs/phase-2-source-manifest.md', implode(PHP_EOL, $sourceManifestLines) . PHP_EOL);

$publicApiLines = [
	'# Phase 2 Public API',
	'',
];

foreach (phpFiles($targetRoot) as $file) {
	require_once $file;
}

$classes = array_filter(
	get_declared_classes(),
	static fn(string $class): bool => str_starts_with($class, 'ON\\Data\\Definition\\')
);
$interfaces = array_filter(
	get_declared_interfaces(),
	static fn(string $class): bool => str_starts_with($class, 'ON\\Data\\Definition\\')
);
$traits = array_filter(
	get_declared_traits(),
	static fn(string $class): bool => str_starts_with($class, 'ON\\Data\\Definition\\')
);

$symbols = array_merge($classes, $interfaces, $traits);
sort($symbols);

foreach ($symbols as $symbol) {
	$reflection = new ReflectionClass($symbol);
	$kind = $reflection->isInterface() ? 'interface' : ($reflection->isTrait() ? 'trait' : 'class');
	$parent = $reflection->getParentClass();
	$publicApiLines[] = '## `' . $symbol . '`';
	$publicApiLines[] = '';
	$publicApiLines[] = '- Type: ' . $kind;
	$publicApiLines[] = '- Parent: ' . ($parent instanceof ReflectionClass ? $parent->getName() : 'none');
	$publicApiLines[] = '- Interfaces: ' . ($reflection->getInterfaceNames() === [] ? 'none' : '`' . implode('`, `', $reflection->getInterfaceNames()) . '`');
	$publicApiLines[] = '- Traits: ' . ($reflection->getTraitNames() === [] ? 'none' : '`' . implode('`, `', $reflection->getTraitNames()) . '`');

	$constructor = $reflection->getConstructor();
	if ($constructor !== null && $constructor->isPublic()) {
		$publicApiLines[] = '- Constructor: `' . $constructor->getName() . '(' . implode(', ', array_map(
			static fn(ReflectionParameter $parameter): string => '$' . $parameter->getName(),
			$constructor->getParameters()
		)) . ')`';
	} else {
		$publicApiLines[] = '- Constructor: none';
	}

	$methods = array_filter(
		$reflection->getMethods(ReflectionMethod::IS_PUBLIC),
		static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $symbol
	);

	if ($methods === []) {
		$publicApiLines[] = '- Public methods: none';
	} else {
		$publicApiLines[] = '- Public methods:';
		foreach ($methods as $method) {
			$params = [];
			foreach ($method->getParameters() as $parameter) {
				$type = $parameter->hasType() ? $parameter->getType()->__toString() . ' ' : '';
				$default = $parameter->isDefaultValueAvailable() ? ' = ' . var_export($parameter->getDefaultValue(), true) : '';
				$params[] = $type . '$' . $parameter->getName() . $default;
			}

			$return = $method->hasReturnType() ? $method->getReturnType()->__toString() : 'mixed';
			$publicApiLines[] = '  - `' . $method->getName() . '(' . implode(', ', $params) . '): ' . $return . '`';
		}
	}

	$properties = array_filter(
		$reflection->getProperties(ReflectionProperty::IS_PUBLIC),
		static fn(ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $symbol
	);
	$publicApiLines[] = '- Public properties: ' . ($properties === [] ? 'none' : '`' . implode('`, `', array_map(
		static fn(ReflectionProperty $property): string => '$' . $property->getName(),
		$properties
	)) . '`');
	$publicApiLines[] = '';
}

file_put_contents($root . '/docs/phase-2-public-api.md', implode(PHP_EOL, $publicApiLines) . PHP_EOL);

echo "Generated phase-2 docs.\n";
