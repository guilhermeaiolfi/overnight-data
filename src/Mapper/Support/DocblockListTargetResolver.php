<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\MapCollection;
use ParseError;
use ReflectionProperty;

/** Resolves explicit collection item types with PHPDoc as a fallback. */
final class DocblockListTargetResolver
{
	/**
	 * @var array<class-string, array<string, class-string|null>>
	 */
	private array $listTargets = [];

	/**
	 * @var array<class-string, array<string, class-string>>
	 */
	private array $importMaps = [];

	/**
	 * @return class-string|null
	 */
	public function resolve(ReflectionProperty $property): ?string
	{
		$class = $property->getDeclaringClass()->getName();
		$name = $property->getName();

		if (array_key_exists($name, $this->listTargets[$class] ?? [])) {
			return $this->listTargets[$class][$name];
		}

		// PHP exposes attributes on promoted parameters through their properties too.
		$attribute = $property->getAttributes(MapCollection::class)[0] ?? null;

		return $this->listTargets[$class][$name] = $attribute?->newInstance()->getItemClass()
			?? $this->parseListTarget($property);
	}

	/**
	 * @return class-string|null
	 */
	private function parseListTarget(ReflectionProperty $property): ?string
	{
		$doc = $property->getDocComment();
		if (is_string($doc)) {
			$type = $this->matchVarListType($doc);
			if ($type !== null) {
				return $this->qualifyClassName($property, $type);
			}
		}

		if ($property->isPromoted()) {
			$constructorDoc = $property->getDeclaringClass()->getConstructor()?->getDocComment();
			if (is_string($constructorDoc)) {
				$type = $this->matchConstructorParamListType($constructorDoc, $property->getName());
				if ($type !== null) {
					return $this->qualifyClassName($property, $type);
				}
			}
		}

		return null;
	}

	private function matchVarListType(string $doc): ?string
	{
		if (preg_match('/@var\s+\??([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\[\]/u', $doc, $match)) {
			return $match[1];
		}

		if (preg_match('/@var\s+\??(?:list|array|iterable)<\s*(?:[^,<>]+,\s*)?([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*>/u', $doc, $match)) {
			return $match[1];
		}

		return null;
	}

	private function matchConstructorParamListType(string $doc, string $propertyName): ?string
	{
		$escaped = preg_quote($propertyName, '/');

		if (preg_match('/@param\s+\??([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\[\](?:\|null)?\s+\$' . $escaped . '\b/u', $doc, $match)) {
			return $match[1];
		}

		if (preg_match('/@param\s+\??(?:list|array|iterable)<\s*(?:[^,<>]+,\s*)?([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*>(?:\|null)?\s+\$' . $escaped . '\b/u', $doc, $match)) {
			return $match[1];
		}

		return null;
	}

	/**
	 * @return class-string|null
	 */
	private function qualifyClassName(ReflectionProperty $property, string $type): ?string
	{
		$type = ltrim(trim($type), '\\');
		if ($type === '') {
			return null;
		}

		if (class_exists($type)) {
			return $type;
		}

		$import = $this->resolveImportedClassName($property, $type);
		if ($import !== null && class_exists($import)) {
			return $import;
		}

		$namespace = $property->getDeclaringClass()->getNamespaceName();
		$qualified = $namespace === '' ? $type : $namespace . '\\' . $type;

		return class_exists($qualified) ? $qualified : null;
	}

	/**
	 * @return class-string|null
	 */
	private function resolveImportedClassName(ReflectionProperty $property, string $type): ?string
	{
		if (str_contains($type, '\\')) {
			return null;
		}

		$class = $property->getDeclaringClass()->getName();
		$imports = $this->importMaps[$class] ??= $this->parseImports($property);

		return $imports[$type] ?? null;
	}

	/**
	 * @return array<string, class-string>
	 */
	private function parseImports(ReflectionProperty $property): array
	{
		$file = $property->getDeclaringClass()->getFileName();
		if (! is_string($file) || $file === '') {
			return [];
		}

		$contents = file_get_contents($file);
		if (! is_string($contents)) {
			return [];
		}

		try {
			$tokens = token_get_all($contents, TOKEN_PARSE);
		} catch (ParseError) {
			return [];
		}
		$imports = [];
		$depth = 0;
		$namespaceDepth = 0;
		$classLine = $property->getDeclaringClass()->getStartLine();
		$count = count($tokens);

		for ($index = 0; $index < $count; $index++) {
			$token = $tokens[$index];

			if (is_array($token) && $classLine !== false && $token[2] > $classLine) {
				break;
			}

			if ($depth === 0 && is_array($token) && $token[0] === T_NAMESPACE) {
				$imports = [];
				while (++$index < $count && $tokens[$index] !== ';' && $tokens[$index] !== '{') {
					// Skip the namespace name.
				}
				$namespaceDepth = ($tokens[$index] ?? null) === '{' ? ++$depth : $depth;

				continue;
			}

			if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
				$depth++;

				continue;
			}

			if ($token === '}') {
				$depth = max(0, $depth - 1);

				continue;
			}

			if (! is_array($token) || $depth !== $namespaceDepth || $token[0] !== T_USE) {
				continue;
			}

			$import = '';
			$alias = null;
			$parsingAlias = false;

			for ($index++; $index < $count; $index++) {
				$current = $tokens[$index];

				if ($current === ';') {
					break;
				}

				if (! is_array($current)) {
					continue;
				}

				if (in_array($current[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
					if ($parsingAlias) {
						$alias ??= '';
						$alias .= $current[1];
					} else {
						$import .= $current[1];
					}

					continue;
				}

				if ($current[0] === T_AS) {
					$parsingAlias = true;
				}
			}

			$import = ltrim($import, '\\');
			if ($import === '') {
				continue;
			}

			$shortName = $alias;
			if ($shortName === null || $shortName === '') {
				$parts = explode('\\', $import);
				$shortName = end($parts);
			}

			if (is_string($shortName) && $shortName !== '') {
				/** @var class-string $import */
				$imports[$shortName] = $import;
			}
		}

		return $imports;
	}
}
