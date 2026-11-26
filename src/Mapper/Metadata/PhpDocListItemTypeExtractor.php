<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Metadata;

use Closure;
use ParseError;
use ReflectionProperty;

final class PhpDocListItemTypeExtractor implements ListItemTypeExtractorInterface
{
	/** @var (Closure(ReflectionProperty): array<string, class-string>)|null */
	private ?Closure $readImports = null;

	/** @internal */
	public static function forOperation(): self
	{
		$extractor = new self();
		$imports = [];
		$extractor->readImports = static function (ReflectionProperty $property) use (&$imports): array {
			return $imports[$property->getDeclaringClass()->getName()] ??= self::parseImports($property);
		};

		return $extractor;
	}

	/**
	 * @return class-string|null
	 */
	public function extract(ReflectionProperty $property): ?string
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

		$imports = $this->readImports === null ? self::parseImports($property) : ($this->readImports)($property);

		return $imports[$type] ?? null;
	}

	/**
	 * @return array<string, class-string>
	 */
	private static function parseImports(ReflectionProperty $property): array
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
