<?php

declare(strict_types=1);

try {
	$root = dirname(__DIR__);
	$resultsDir = $root . DIRECTORY_SEPARATOR . 'benchmark' . DIRECTORY_SEPARATOR . 'results';
	$latestPrefix = $resultsDir . DIRECTORY_SEPARATOR . 'mapping-latest';
	$baselinePrefix = $resultsDir . DIRECTORY_SEPARATOR . 'mapping-baseline';
	$comparePrefix = $resultsDir . DIRECTORY_SEPARATOR . 'mapping-compare';
	$historyFile = $resultsDir . DIRECTORY_SEPARATOR . 'mapping-history.json';

	ensureDirectory($resultsDir);
	ensureDirectory($resultsDir . DIRECTORY_SEPARATOR . 'storage');

	$mode = $argv[1] ?? 'compare';
	if (! in_array($mode, ['record', 'compare', 'history'], true)) {
		fwrite(STDERR, "Usage: php benchmark/mapping_bench.php [record|compare|history] [label]\n");
		exit(1);
	}

	if ($mode === 'history') {
		printHistory($historyFile);
		exit(0);
	}

	$latestXml = $latestPrefix . '.xml';
	$latestTxt = $latestPrefix . '.txt';
	$latestJson = $latestPrefix . '.json';
	$baselineXml = $baselinePrefix . '.xml';
	$baselineTxt = $baselinePrefix . '.txt';
	$baselineJson = $baselinePrefix . '.json';
	$compareTxt = $comparePrefix . '.txt';
	$compareJson = $comparePrefix . '.json';

	runBenchmarkDump($root, $latestXml);

	$latestConsole = renderReport($root, [$latestXml], 'console');
	file_put_contents($latestTxt, normalizeOutput($latestConsole));

	$latestJsonRaw = renderReport($root, [$latestXml], 'json');
	file_put_contents($latestJson, prettyJson($latestJsonRaw));

	if ($mode === 'record') {
		$label = trim($argv[2] ?? '');
		if ($label === '') {
			fwrite(STDERR, "Record mode requires a non-empty label.\nExample: composer bench:mapping:record -- before-operation-caches\n");
			exit(1);
		}

		$gitState = [
			'commit' => trimRequiredCommandOutput(
				$root,
				['git', 'rev-parse', 'HEAD'],
				'Unable to read current Git commit.',
			),
			'dirty' => trim(runCommand($root, ['git', 'status', '--short'])['stdout']) !== '',
		];

		copy($latestXml, $baselineXml);
		copy($latestTxt, $baselineTxt);
		copy($latestJson, $baselineJson);

		$history = readHistoryFile($historyFile);
		$history['runs'] = registerHistoryRun($history['runs'], $label, $latestJson, $gitState);
		writeJsonFile($historyFile, $history);

		echo "Recorded mapping baseline:\n";
		echo " - {$baselineXml}\n";
		echo " - {$baselineTxt}\n";
		echo " - {$baselineJson}\n";
		echo "Updated mapping history:\n";
		echo " - {$historyFile}\n";
		exit(0);
	}

	if (! file_exists($baselineXml)) {
		fwrite(STDERR, "Baseline file not found: {$baselineXml}\nRun `composer bench:mapping:record -- <label>` first.\n");
		exit(1);
	}

	$baselineBenchmarks = extractBenchmarksFromLatestJson($baselineJson);
	$latestBenchmarks = extractBenchmarksFromLatestJson($latestJson);
	$comparisonRows = buildComparisonRows($baselineBenchmarks, $latestBenchmarks);
	$comparisonText = renderComparisonText($comparisonRows);

	file_put_contents($compareTxt, $comparisonText);
	writeJsonFile($compareJson, $comparisonRows);

	echo $comparisonText;
	echo "\nWrote mapping comparison artifacts:\n";
	echo " - {$latestTxt}\n";
	echo " - {$latestJson}\n";
	echo " - {$compareTxt}\n";
	echo " - {$compareJson}\n";
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}

function runBenchmarkDump(string $root, string $dumpFile): void
{
	$result = runCommand($root, [
		PHP_BINARY,
		$root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpbench',
		'run',
		'--config=phpbench.json',
		'--filter=MappingBench',
		'--progress=none',
		'--dump-file=' . $dumpFile,
		'--quiet',
		'--no-ansi',
	]);

	if ($result['exitCode'] !== 0) {
		fwrite(STDERR, $result['stderr'] . PHP_EOL);
		exit($result['exitCode']);
	}
}

function renderReport(string $root, array $xmlFiles, string $output): string
{
	$command = [
		PHP_BINARY,
		$root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpbench',
		'report',
		'--config=phpbench.json',
		'--report=baseline-summary',
		'--output=' . $output,
		'--no-ansi',
	];

	foreach ($xmlFiles as $file) {
		$command[] = '--file=' . $file;
	}

	$result = runCommand($root, $command);
	if ($result['exitCode'] !== 0) {
		fwrite(STDERR, $result['stderr'] . PHP_EOL);
		exit($result['exitCode']);
	}

	return $result['stdout'];
}

/**
 * @param list<array<string, mixed>> $runs
 * @param array{commit:string, dirty:bool} $gitState
 *
 * @return list<array<string, mixed>>
 */
function registerHistoryRun(array $runs, string $label, string $latestJsonFile, array $gitState): array
{
	$latestBenchmarks = extractBenchmarksFromLatestJson($latestJsonFile);
	$entry = [
		'label' => $label,
		'recorded_at' => date(DATE_ATOM),
		'commit' => $gitState['commit'],
		'dirty' => $gitState['dirty'],
		'environment' => [
			'php' => PHP_VERSION,
			'os' => PHP_OS_FAMILY,
			'architecture' => php_uname('m'),
		],
		'benchmarks' => $latestBenchmarks,
	];

	foreach ($runs as $index => $run) {
		if (($run['label'] ?? null) === $label) {
			$runs[$index] = $entry;

			return $runs;
		}
	}

	$runs[] = $entry;

	return $runs;
}

/**
 * @return array<string, array{mode_us:float|int, rstdev_percent:float|int, memory_peak_bytes:int}>
 */
function extractBenchmarksFromLatestJson(string $latestJsonFile): array
{
	if (! file_exists($latestJsonFile)) {
		throw new RuntimeException(sprintf('Latest benchmark JSON not found: %s', $latestJsonFile));
	}

	$payload = json_decode((string) file_get_contents($latestJsonFile), true);
	if (! is_array($payload)) {
		throw new RuntimeException(sprintf('Latest benchmark JSON is malformed: %s', $latestJsonFile));
	}

	$benchmarks = [];

	foreach ($payload as $row) {
		if (! is_array($row) || ! isset($row['name'], $row['mode'], $row['rstdev'], $row['mem_peak'])) {
			throw new RuntimeException(sprintf('Latest benchmark JSON is missing required fields: %s', $latestJsonFile));
		}

		$name = (string) $row['name'];
		$benchmarks[$name] = [
			'mode_us' => is_int($row['mode']) ? $row['mode'] : (float) $row['mode'],
			'rstdev_percent' => is_int($row['rstdev']) ? $row['rstdev'] : (float) $row['rstdev'],
			'memory_peak_bytes' => (int) $row['mem_peak'],
		];
	}

	ksort($benchmarks);

	return $benchmarks;
}

/**
 * @param array<string, array{mode_us:float|int, rstdev_percent:float|int, memory_peak_bytes:int}> $baselineBenchmarks
 * @param array<string, array{mode_us:float|int, rstdev_percent:float|int, memory_peak_bytes:int}> $latestBenchmarks
 *
 * @return list<array<string, float|int|string|null>>
 */
function buildComparisonRows(array $baselineBenchmarks, array $latestBenchmarks): array
{
	$names = array_unique([
		...array_keys($baselineBenchmarks),
		...array_keys($latestBenchmarks),
	]);
	sort($names);

	$rows = [];

	foreach ($names as $name) {
		$baseline = $baselineBenchmarks[$name] ?? null;
		$latest = $latestBenchmarks[$name] ?? null;

		$rows[] = [
			'name' => $name,
			'baseline_mode_us' => $baseline['mode_us'] ?? null,
			'current_mode_us' => $latest['mode_us'] ?? null,
			'delta_mode_percent' => percentDelta($baseline['mode_us'] ?? null, $latest['mode_us'] ?? null),
			'baseline_rstdev_percent' => $baseline['rstdev_percent'] ?? null,
			'current_rstdev_percent' => $latest['rstdev_percent'] ?? null,
			'delta_rstdev_percent' => percentDelta($baseline['rstdev_percent'] ?? null, $latest['rstdev_percent'] ?? null),
			'baseline_memory_peak_bytes' => $baseline['memory_peak_bytes'] ?? null,
			'current_memory_peak_bytes' => $latest['memory_peak_bytes'] ?? null,
			'delta_memory_peak_percent' => percentDelta($baseline['memory_peak_bytes'] ?? null, $latest['memory_peak_bytes'] ?? null),
		];
	}

	return $rows;
}

function percentDelta(float|int|null $baseline, float|int|null $current): ?float
{
	if ($baseline === null || $current === null || $baseline == 0.0) {
		return null;
	}

	return (($current - $baseline) / $baseline) * 100;
}

/**
 * @param list<array<string, float|int|string|null>> $rows
 */
function renderComparisonText(array $rows): string
{
	$headers = [
		'name',
		'baseline_us',
		'current_us',
		'delta_pct',
	];

	$tableRows = array_map(static function (array $row): array {
		return [
			'name' => (string) $row['name'],
			'baseline_us' => formatNumber($row['baseline_mode_us']),
			'current_us' => formatNumber($row['current_mode_us']),
			'delta_pct' => formatPercent($row['delta_mode_percent']),
		];
	}, $rows);

	$widths = [];
	foreach ($headers as $header) {
		$widths[$header] = strlen($header);
	}

	foreach ($tableRows as $row) {
		foreach ($headers as $header) {
			$widths[$header] = max($widths[$header], strlen($row[$header]));
		}
	}

	$lines = [];
	$lines[] = 'Mapping benchmark comparison';
	$lines[] = '';
	$lines[] = sprintf(
		'%-' . $widths['name'] . 's  %' . $widths['baseline_us'] . 's  %' . $widths['current_us'] . 's  %' . $widths['delta_pct'] . 's',
		'name',
		'baseline_us',
		'current_us',
		'delta_pct',
	);

	foreach ($tableRows as $row) {
		$lines[] = sprintf(
			'%-' . $widths['name'] . 's  %' . $widths['baseline_us'] . 's  %' . $widths['current_us'] . 's  %' . $widths['delta_pct'] . 's',
			$row['name'],
			$row['baseline_us'],
			$row['current_us'],
			$row['delta_pct'],
		);
	}

	return implode(PHP_EOL, $lines) . PHP_EOL;
}

function formatNumber(float|int|null $value): string
{
	if ($value === null) {
		return 'n/a';
	}

	return number_format((float) $value, 3, '.', '');
}

function formatPercent(float|int|null $value): string
{
	if ($value === null) {
		return 'n/a';
	}

	return sprintf('%+.2f%%', (float) $value);
}

function printHistory(string $historyFile): void
{
	$history = readHistoryFile($historyFile);
	$compatibleRuns = compatibleRuns($history['runs']);

	if ($compatibleRuns === []) {
		echo "No compatible mapping history runs found for this environment.\n";
		return;
	}

	$previous = null;
	foreach ($compatibleRuns as $run) {
		$dirtySuffix = ($run['dirty'] ?? false) ? ' dirty' : '';
		echo sprintf(
			"[%s] %s (%s%s)\n",
			$run['label'],
			$run['recorded_at'],
			substr((string) $run['commit'], 0, 12),
			$dirtySuffix
		);

		$benchmarks = $run['benchmarks'] ?? [];
		ksort($benchmarks);
		foreach ($benchmarks as $name => $metrics) {
			$change = 'n/a';
			if (is_array($previous) && isset($previous['benchmarks'][$name]['mode_us'])) {
				$priorMode = (float) $previous['benchmarks'][$name]['mode_us'];
				$currentMode = (float) $metrics['mode_us'];
				if ($priorMode !== 0.0) {
					$delta = (($currentMode - $priorMode) / $priorMode) * 100;
					$change = sprintf('%+.2f%%', $delta);
				}
			}

			echo sprintf(
				"  - %s: %.3f us (%s)\n",
				$name,
				(float) $metrics['mode_us'],
				$change
			);
		}

		$previous = $run;
	}
}

/**
 * @return array{schema:int, runs:list<array<string, mixed>>}
 */
function readHistoryFile(string $historyFile): array
{
	if (! file_exists($historyFile)) {
		throw new RuntimeException(sprintf(
			'History file not found: %s. Restore it or create the seeded benchmark/results/mapping-history.json first.',
			$historyFile
		));
	}

	$decoded = json_decode((string) file_get_contents($historyFile), true);
	if (! is_array($decoded)) {
		throw new RuntimeException(sprintf('History file is malformed JSON: %s', $historyFile));
	}

	if (($decoded['schema'] ?? null) !== 1 || ! isset($decoded['runs']) || ! is_array($decoded['runs'])) {
		throw new RuntimeException(sprintf('History file has an unsupported structure: %s', $historyFile));
	}

	return [
		'schema' => 1,
		'runs' => array_values($decoded['runs']),
	];
}

/**
 * @param list<array<string, mixed>> $runs
 *
 * @return list<array<string, mixed>>
 */
function compatibleRuns(array $runs): array
{
	$currentPhpMinor = phpMinorVersion(PHP_VERSION);
	$currentOs = PHP_OS_FAMILY;
	$currentArchitecture = php_uname('m');

	return array_values(array_filter($runs, static function (array $run) use ($currentPhpMinor, $currentOs, $currentArchitecture): bool {
		$environment = $run['environment'] ?? null;
		if (! is_array($environment)) {
			return false;
		}

		return phpMinorVersion((string) ($environment['php'] ?? '')) === $currentPhpMinor
			&& (string) ($environment['os'] ?? '') === $currentOs
			&& (string) ($environment['architecture'] ?? '') === $currentArchitecture;
	}));
}

function phpMinorVersion(string $version): string
{
	$parts = explode('.', $version);
	if (count($parts) < 2) {
		return $version;
	}

	return $parts[0] . '.' . $parts[1];
}

function trimRequiredCommandOutput(string $cwd, array $command, string $error): string
{
	$result = runCommand($cwd, $command);
	if ($result['exitCode'] !== 0) {
		throw new RuntimeException($error . ' ' . trim($result['stderr']));
	}

	return trim($result['stdout']);
}

/**
 * @return array{exitCode:int, stdout:string, stderr:string}
 */
function runCommand(string $cwd, array $command): array
{
	$descriptors = [
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];

	$process = proc_open(
		$command,
		$descriptors,
		$pipes,
		$cwd,
		null,
		['bypass_shell' => true]
	);

	if (! is_resource($process)) {
		throw new RuntimeException('Unable to start process.');
	}

	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);

	return [
		'exitCode' => $exitCode,
		'stdout' => $stdout === false ? '' : $stdout,
		'stderr' => $stderr === false ? '' : $stderr,
	];
}

function ensureDirectory(string $path): void
{
	if (! is_dir($path)) {
		mkdir($path, 0777, true);
	}
}

function writeJsonFile(string $path, array $payload): void
{
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if (! is_string($json)) {
		throw new RuntimeException(sprintf('Unable to encode JSON for %s.', $path));
	}

	file_put_contents($path, $json . PHP_EOL);
}

function prettyJson(string $json): string
{
	$json = stripDeprecationLines($json);
	$decoded = json_decode(trim($json), true);
	if ($decoded === null && trim($json) !== 'null') {
		return trim($json) . PHP_EOL;
	}

	return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function normalizeOutput(string $output): string
{
	return rtrim(str_replace("\r\n", "\n", stripDeprecationLines($output))) . PHP_EOL;
}

function stripDeprecationLines(string $output): string
{
	$lines = preg_split("/\r\n|\n|\r/", $output);
	if ($lines === false) {
		return $output;
	}

	$filtered = array_values(array_filter(
		$lines,
		static fn (string $line): bool => ! str_contains($line, 'Deprecated:')
	));

	return implode(PHP_EOL, $filtered);
}
