<?php
/**
 * GitLab Code Quality generator for PHP and JS projects.
 *
 * Code style: PER-2T / PSR-12T (PHP’s standard PER-2 / PSR-12 with SmartTabs instead of spaces).
 *
 * Written in pure PHP (8.0+) without any framework or library dependencies.
 *
 * @link https://github.com/maximal/gitlab-code-quality
 * @link https://maximals.ru/
 * @link https://sijeko.ru/
 *
 * @since 2024-10-28 Biome support
 * @since 2024-06-09 Print issue location in statistics table if the issue is the only one of its class
 * @since 2024-06-08 PHPStan issue classes support
 * @since 2024-05-10 Strict mode to return non-zero exit code if issues are found
 * @since 2024-04-25 ECS (Easy Coding Standard) support
 * @since 2024-04-18 Bun JS runtime support
 * @since 2023-03-19
 * @date 2023-03-19
 */

namespace Maximal\GitlabCodeQuality;

use Throwable;

/**
 * GitLab Code Quality generator for PHP and JS projects
 */
class App
{
	// Project version
	public const VERSION = '1.9';

	// Config variables
	private string $phpDir = '.';

	private string $jsDir = 'resources';
	private string $bunBin = 'bun';
	private string $nodeBin = 'node';
	private string $styleLintFiles = 'resources/**/*.{css,scss,sass,vue}';

	private string $psalmConfig = self::DEFAULT_PSALM_CONFIG;
	private string $phpStanConfig = self::DEFAULT_PHPSTAN_CONFIG;
	private string $phpCsStandard = self::DEFAULT_PHPCS_STANDARD;
	private string $ecsConfig = self::DEFAULT_ECS_CONFIG;
	private string $esLintConfig = self::DEFAULT_ESLINT_CONFIG;
	private string $styleLintConfig = self::DEFAULT_STYLELINT_CONFIG;
	private string $biomeConfig = self::DEFAULT_BIOME_CONFIG;

	private bool $printStats = true;
	private int $printLast = self::PRINT_LAST_SINGLE;
	private bool $silent = false;
	private bool $cache = false;
	private bool $strict = false;

	private bool $runPsalm = true;
	private bool $runPhpStan = true;
	private bool $runPhpCs = true;
	private bool $runEcs = true;

	private bool $runEsLint = true;
	private bool $runStyleLint = true;
	private bool $runBiome = true;

	// Runtime variables
	private array $argv;
	private string $selfBin;
	private string $binDir;
	private string $phpAppDir;
	private string $jsAppDir;
	private string $currentDir;

	private bool $hasPsalm = false;
	private bool $hasPhpStan = false;
	private bool $hasPhpCs = false;
	private bool $hasEcs = false;

	private bool $hasBun = false;
	private bool $hasNode = false;
	private bool $hasEsLint = false;
	private bool $hasStyleLint = false;
	private bool $hasBiome = false;

	private string $binPsalm;
	private string $binPhpStan;
	private string $binPhpCs;
	private string $binEcs;
	private string $binEsLint;
	private string $binStyleLint;
	private string $binBiome;

	private ?string $lastErrors = null;

	private int $verbosity = 1;

	// Severities
	private const SEVERITY_INFO = 'info';
	private const SEVERITY_MINOR = 'minor';
	private const SEVERITY_MAJOR = 'major';
	private const SEVERITY_CRITICAL = 'critical';

	// printLast values
	private const PRINT_LAST_NO = 0;
	private const PRINT_LAST_YES = 1;
	private const PRINT_LAST_SINGLE = 2;

	// Result codes
	private const RESULT_OK = 0;
	private const RESULT_CRITICAL_ISSUES = 1;
	private const RESULT_ISSUES_WITH_STRICT_MODE = 2;
	private const RESULT_PSALM_FAILED = 3;
	private const RESULT_PHPSTAN_FAILED = 4;
	private const RESULT_PHPCS_FAILED = 5;
	private const RESULT_ECS_FAILED = 6;
	private const RESULT_ESLINT_FAILED = 7;
	private const RESULT_STYLELINT_FAILED = 8;
	private const RESULT_BIOME_FAILED = 9;

	// Default config files
	private const DEFAULT_PSALM_CONFIG = 'psalm.xml';
	private const DEFAULT_PHPSTAN_CONFIG = 'phpstan.neon';
	private const DEFAULT_PHPCS_STANDARD = 'PSR12';
	private const DEFAULT_ECS_CONFIG = 'ecs.php';
	private const DEFAULT_ESLINT_CONFIG = '.eslintrc.yml';
	private const DEFAULT_STYLELINT_CONFIG = '.stylelintrc.yml';
	private const DEFAULT_BIOME_CONFIG = 'biome.jsonc';


	public function __construct(array $argv, string $binDir)
	{
		$this->argv = $argv;
		$this->binDir = $binDir;
		$this->phpAppDir = dirname($binDir, 2);
		$this->currentDir = getcwd();
		$this->jsAppDir = $this->currentDir;
	}

	/**
	 * Run the generator
	 */
	public function run(): int
	{
		$code = $this->processArgumentsAndConfigs();
		if ($code !== null) {
			return $code;
		}
		$this->detectTools();
		return $this->process();
	}

	private function processArgumentsAndConfigs(): ?int
	{
		$rewriteProperties = [];
		foreach ($this->argv as $index => $argument) {
			if ($index === 0) {
				$this->selfBin = $argument;
				continue;
			}
			switch (strtolower(trim($argument))) {
				case '--help':
				case '-h':
					return $this->printHelp();

				case '--version':
				case '-v':
					return $this->printVersion();

				case '--verbose':
				case '-vv':
					$this->verbosity = 2;
					$rewriteProperties['verbosity'] = 2;
					break;

				case '-vvv':
					$this->verbosity = 3;
					$rewriteProperties['verbosity'] = 3;
					break;

				case '-vvvv':
					$this->verbosity = 4;
					$rewriteProperties['verbosity'] = 4;
					break;

				case '-s':
				case '--strict':
					$this->strict = true;
					$rewriteProperties['strict'] = true;
					break;
			}
		}
		$this->processComposerConfig();
		$this->processJsConfig();
		// Rewrite properties, so that arguments take precedence over configs
		foreach ($rewriteProperties as $property => $value) {
			$this->$property = $value;
		}
		return null;
	}

	private function printHelp(): int
	{
		echo '    GitLab Code Quality Tool for PHP and JS    ', PHP_EOL;
		echo '===============================================', PHP_EOL;
		echo 'v', str_pad(self::VERSION, 17), '© MaximAL of Sijeko 2023—2024', PHP_EOL;
		echo PHP_EOL;
		echo 'Runs: Psalm, PHPStan, PHP CodeSniffer, ECS, ESLint, StyleLint.', PHP_EOL;
		echo PHP_EOL;
		echo 'Installation:', PHP_EOL;
		echo '    composer require maximal/gitlab-code-quality', PHP_EOL;
		echo PHP_EOL;
		echo 'Usage:', PHP_EOL;
		echo '    ' . $this->selfBin . ' > gl-code-quality-report.json', PHP_EOL;
		echo PHP_EOL;
		echo 'Options:', PHP_EOL;
		echo '    -h   --help       Print help.', PHP_EOL;
		echo '    -v   --version    Print version.', PHP_EOL;
		echo '    -s   --strict     Return non-zero exit code if issues are found.', PHP_EOL;
		echo '    -vv  --verbose    Increase output verbosity.', PHP_EOL;
		echo PHP_EOL;
		echo 'More information:', PHP_EOL;
		echo '    https://github.com/maximal/gitlab-code-quality', PHP_EOL;
		return self::RESULT_OK;
	}

	private function printVersion(): int
	{
		echo 'v' . self::VERSION, PHP_EOL;
		return self::RESULT_OK;
	}

	private function detectTools(): void
	{
		$binDir = $this->binDir . '/';

		// Detect tools
		$this->binPsalm = realpath($binDir . 'psalm');
		$this->binPhpStan = realpath($binDir . 'phpstan');
		$this->binPhpCs = realpath($binDir . 'phpcs');
		$this->binEcs = realpath($binDir . 'ecs');
		$this->binEsLint = realpath($this->currentDir . '/node_modules/eslint/bin/eslint.js');
		$this->binStyleLint = realpath($this->currentDir . '/node_modules/stylelint/bin/stylelint.mjs');
		$this->binBiome = realpath($this->currentDir . '/node_modules/@biomejs/biome/bin/biome');

		$this->hasPsalm = is_file($this->binPsalm);
		$this->hasPhpStan = is_file($this->binPhpStan);
		$this->hasPhpCs = is_file($this->binPhpCs);
		$this->hasEcs = is_file($this->binEcs);
		$this->hasEsLint = is_file($this->binEsLint);
		$this->hasStyleLint = is_file($this->binStyleLint);
		$this->hasBiome = is_file($this->binBiome);

		// Detect JS runtime
		exec($this->bunBin . ' --version 2>&1', $output, $code);
		$this->hasBun = $code === 0;
		if (!$this->hasBun) {
			exec($this->nodeBin . ' --version 2>&1', $output, $code);
			$this->hasNode = $code === 0;
		}
		$this->stdErrPrintLine('JS runtime: ' . $this->getJsRuntime(), 2);
	}

	private function process(): int
	{
		$issues = [];

		if ($this->phpAppDir !== $this->currentDir) {
			// Change current dir to PHP app dir
			chdir($this->phpAppDir);
		}

		$psalm = $this->runPsalm();
		if ($this->lastErrors !== null) {
			$this->stdErrPrintLine($this->lastErrors);
			$this->stdErrPrintLine('Error running Psalm. See errors above.');
			return self::RESULT_PSALM_FAILED;
		}
		array_push($issues, ...$psalm);

		$phpStan = $this->runPhpStan();
		if ($this->lastErrors !== null) {
			$this->stdErrPrintLine($this->lastErrors);
			$this->stdErrPrintLine('Error running PHPStan. See errors above.');
			return self::RESULT_PHPSTAN_FAILED;
		}
		array_push($issues, ...$phpStan);

		$phpCs = $this->runPhpCs();
		if ($this->lastErrors !== null) {
			$this->stdErrPrintLine($this->lastErrors);
			$this->stdErrPrintLine('Error running PHP CodeSniffer. See errors above.');
			return self::RESULT_PHPCS_FAILED;
		}
		array_push($issues, ...$phpCs);

		$ecs = $this->runEcs();
		if ($this->lastErrors !== null) {
			$this->stdErrPrintLine($this->lastErrors);
			$this->stdErrPrintLine('Error running ESC. See errors above.');
			return self::RESULT_ECS_FAILED;
		}
		array_push($issues, ...$ecs);

		if ($this->jsAppDir !== $this->phpAppDir) {
			// Change current dir to JS app dir
			chdir($this->jsAppDir);
		}

		$esLint = $this->runEsLint();
		if ($this->lastErrors !== null) {
			$this->stdErrPrintLine($this->lastErrors);
			$this->stdErrPrintLine('Error running ESLint. See errors above.');
			return self::RESULT_ESLINT_FAILED;
		}
		array_push($issues, ...$esLint);

		$styleLint = $this->runStyleLint();
		if ($this->lastErrors !== null) {
			$this->stdErrPrintLine($this->lastErrors);
			$this->stdErrPrintLine('Error running StyleLint. See errors above.');
			return self::RESULT_STYLELINT_FAILED;
		}
		array_push($issues, ...$styleLint);

		if (getcwd() !== $this->currentDir) {
			// Change current dir to the old current dir
			chdir($this->currentDir);
		}

		$biome = $this->runBiome();
		if ($this->lastErrors !== null) {
			$this->stdErrPrintLine($this->lastErrors);
			$this->stdErrPrintLine('Error running Biome. See errors above.');
			return self::RESULT_BIOME_FAILED;
		}
		array_push($issues, ...$biome);

		return $this->getStats($issues);
	}

	private function runPsalm(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runPsalm || !$this->hasPsalm) {
			return [];
		}
		$this->stdErrPrintLine('Running Psalm...');
		$config = $this->psalmConfig !== self::DEFAULT_PSALM_CONFIG ?
			('--config=' . escapeshellarg($this->psalmConfig)) : '';
		$dir = !in_array($this->phpDir, ['', '.']) ? escapeshellarg($this->phpDir) : '';
		$this->execCommand(
			$this->binPsalm . ($this->cache ? '' : ' --no-cache') .
			' --memory-limit=-1 --output-format=json ' . $config . ' ' . $dir,
			$output
		);
		// Fixes for invalid Psalm’s JSON output
		$fixedOutput = [];
		$header = true;
		foreach (explode(PHP_EOL, $output) as $line) {
			if (!$header) {
				$fixedOutput[] = $line;
				continue;
			}
			if (str_starts_with($line, '[')) {
				$fixedOutput[] = $line;
				$header = false;
			}
		}
		$data = self::getJson(implode(PHP_EOL, $fixedOutput));
		if (!is_array($data)) {
			$this->lastErrors = $output;
			return null;
		}
		$result = [];
		foreach ($data as $issue) {
			if ($issue->type === 'ParseError') {
				$severity = self::SEVERITY_CRITICAL;
			} else {
				$severity = $issue->severity;
			}
			$result[] = $this->makeIssue(
				$issue->file_path,
				$issue->line_from,
				'Psalm: ' . $issue->message,
				$severity,
				'Psalm.' . $issue->type,
				$issue->column_from,
				$issue->line_to,
				$issue->column_to
			);
		}
		return $result;
	}

	private function runPhpStan(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runPhpStan || !$this->hasPhpStan) {
			return [];
		}
		$this->stdErrPrintLine('Running PhpStan...');
		$config = $this->phpStanConfig !== self::DEFAULT_PHPSTAN_CONFIG ?
			('--configuration=' . escapeshellarg($this->phpStanConfig)) : '';
		$dir = !in_array($this->phpDir, ['', '.']) ? escapeshellarg($this->phpDir) : '';
		$this->execCommand(
			$this->binPhpStan .
			' analyse --memory-limit=-1 --no-interaction --error-format=json ' .
			$config . ' ' . $dir,
			$output
		);
		$data = self::getJson($output);
		if (!is_object($data) || !isset($data->files)) {
			$this->lastErrors = $output;
			return null;
		}
		$result = [];
		foreach ($data->files as $fileName => $file) {
			foreach ($file->messages as $issue) {
				$result[] = $this->makeIssue(
					$fileName,
					$issue->line,
					'PHPStan: ' . $issue->message,
					($issue->ignorable ?? false) ? self::SEVERITY_MINOR : self::SEVERITY_MAJOR,
					isset($issue->identifier) ? ('PhpStan.' . $issue->identifier) : ''
				);
			}
		}
		return $result;
	}

	private function runPhpCs(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runPhpCs || !$this->hasPhpCs) {
			return [];
		}
		$this->stdErrPrintLine(
			'Running PHP CodeSniffer with standard ' .
			$this->phpCsStandard . '...'
		);
		$this->execCommand(
			$this->binPhpCs . ($this->cache ? '' : ' --no-cache') .
			' --report=json --standard=' . escapeshellarg($this->phpCsStandard) .
			' ' . escapeshellarg($this->phpDir),
			$output
		);
		$data = self::getJson($output);
		if (!is_object($data) || !is_object($data->files)) {
			$this->lastErrors = $output;
			return null;
		}
		$result = [];
		foreach ($data->files as $file => $item) {
			foreach ($item->messages as $issue) {
				$result[] = $this->makeIssue(
					$file,
					$issue->line,
					'PHP CS: ' . $issue->message,
					$issue->type,
					'PhpCs.' . $issue->source,
					$issue->column
				);
			}
		}
		return $result;
	}

	private function runEcs(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runEcs || !$this->hasEcs) {
			return [];
		}
		$this->stdErrPrintLine('Running ECS (Easy Coding Standard)...');
		$config = $this->ecsConfig !== self::DEFAULT_ECS_CONFIG ?
			('--config=' . escapeshellarg($this->ecsConfig)) : '';
		$dir = !in_array($this->phpDir, ['', '.']) ? escapeshellarg($this->phpDir) : '';
		$this->execCommand(
			$this->binEcs . ($this->cache ? '' : ' --clear-cache') .
			' --memory-limit=-1 --output-format=json ' . $config . ' ' . $dir,
			$output
		);
		$data = self::getJson($output);
		if (!is_object($data) || !is_object($data->files)) {
			$this->lastErrors = $output;
			return null;
		}
		$result = [];
		foreach ($data->files as $file => $item) {
			foreach ($item->diffs as $diff) {
				$line = 1;
				$diffContext = 3;
				if (
					preg_match(
						'/---\s*Original\n\+\+\+\s*New\n@@\s*-(\d+),\d+\s*\+\d+,\d+\s*@@/ui',
						$diff->diff,
						$match
					)
				) {
					// Trying to guess the line number from the diff
					$line = (int)$match[1] + $diffContext;
				}
				foreach ($diff->applied_checkers ?? [] as $check) {
					$rule = str_replace('\\', '.', $check);
					$result[] = $this->makeIssue(
						$file,
						$line,
						'ECS: ' . $rule,
						self::SEVERITY_MINOR,
						'Ecs.' . $rule,
					);
				}
			}
		}
		return $result;
	}

	private function runEsLint(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runEsLint || !$this->hasEsLint) {
			return [];
		}
		$this->stdErrPrintLine('Running ES Lint...');
		$jsRuntime = $this->getJsRuntime();
		if ($jsRuntime === null) {
			$this->lastErrors = 'No JS runtime found: no Bun, no Node';
			return null;
		}
		$config = $this->esLintConfig !== self::DEFAULT_ESLINT_CONFIG ?
			(' --config ' . escapeshellarg($this->esLintConfig)) : '';
		$this->execCommand(
			$jsRuntime . ' ' . escapeshellarg($this->binEsLint) .
			' --format=json' . $config . ' ' . escapeshellarg($this->jsDir),
			$output
		);
		// Sometimes ESLint outputs malformed UTF-8 JSON, due to source code printing
		// Cut `"source"`s off before decoding JSON
		// See: https://github.com/maximal/gitlab-code-quality/issues/7
		$output = preg_replace(
			'/"source":".+?","usedDeprecatedRules":/',
			'"usedDeprecatedRules":',
			$output
		);
		$data = self::getJson($output);
		if (!is_array($data)) {
			$this->lastErrors = $output;
			return null;
		}
		$result = [];
		foreach ($data as $file) {
			foreach ($file->messages as $issue) {
				$result[] = $this->makeIssue(
					$file->filePath,
					$issue->line,
					'ESLint: ' . $issue->message,
					$issue->severity,
					'EsLint.' . $issue->ruleId,
					$issue->column ?? -1,
					$issue->endLine ?? -1,
					$issue->endColumn ?? -1
				);
			}
		}
		return $result;
	}

	private function runStyleLint(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runStyleLint || !$this->hasStyleLint) {
			return [];
		}
		$this->stdErrPrintLine('Running StyleLint...');
		$jsRuntime = $this->getJsRuntime();
		if ($jsRuntime === null) {
			$this->lastErrors = 'No JS runtime found: no Bun, no Node';
			return null;
		}
		$config = $this->styleLintConfig !== self::DEFAULT_STYLELINT_CONFIG ?
			('--config ' . escapeshellarg($this->styleLintConfig)) : '';
		$this->execCommand(
			$jsRuntime . ' ' . escapeshellarg($this->binStyleLint) .
			' --formatter=json ' . $config . ' --quiet-deprecation-warnings ' .
			escapeshellarg($this->styleLintFiles) .
			' 2>&1',
			$output
		);
		$data = self::getJson($output);
		if (!is_array($data)) {
			$this->lastErrors = $output;
			return null;
		}
		$result = [];
		foreach ($data as $file) {
			foreach ($file->warnings as $issue) {
				$result[] = $this->makeIssue(
					$file->source,
					$issue->line,
					'StyleLint: ' . $issue->text,
					$issue->severity,
					'StyleLint.' . $issue->rule,
					$issue->column,
					$issue->endLine,
					$issue->endColumn
				);
			}
		}
		return $result;
	}

	private function runBiome(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runBiome || !$this->hasBiome) {
			return [];
		}
		$this->stdErrPrintLine('Running Biome...');
		$jsRuntime = $this->getJsRuntime();
		if ($jsRuntime === null) {
			$this->lastErrors = 'No JS runtime found: no Bun, no Node';
			return null;
		}
		$config = $this->biomeConfig !== self::DEFAULT_BIOME_CONFIG ?
			('--config-path=' . escapeshellarg($this->biomeConfig)) : '';

		// region GitHub reporter: precise issue location, but returns not all the issues
		//*
		// https://biomejs.dev/reference/reporters/#github
		$this->execCommand(
			$jsRuntime . ' ' .
			escapeshellarg($this->binBiome) .
			' check --max-diagnostics=65535 --files-max-size=' . (16 * 1024 * 1024) .
			' --reporter=github ' . $config,
			$output
		);
		$result = [];
		$lines = preg_split('/(\r\n|\r|\n)/', $output, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($lines as $line) {
			$line = trim($line);
			if (
				!preg_match(
					'/^::(\S+)\s+title=([^,]+),file=([^,]+),line=(\d+),endLine=(\d+),col=(\d+),endColumn=(\d+)::(.+)$/',
					$line,
					$match
				)
			) {
				continue;
			}
			$result[] = $this->makeIssue(
				self::deEscapeGithubReportProperty($match[3]),
				(int)$match[4],
				'Biome: ' . self::deEscapeGithubReportData($match[8]),
				$match[1],
				'Biome.' . self::deEscapeGithubReportProperty($match[2]),
				(int)$match[6],
				(int)$match[5],
				(int)$match[7]
			);
		}
		return $result;
		/**/
		// endregion GitHub reporter: precise issue location, but returns not all the issues


		// region JSON reporter: need to calculate issue location, but returns all the issues
		/*
		// https://biomejs.dev/reference/reporters/#json
		$this->execCommand(
			$jsRuntime . ' ' .
			escapeshellarg($this->binBiome) .
			' check --max-diagnostics=65535 --files-max-size=' . (16 * 1024 * 1024) .
			' --reporter=json ' . $config,
			$output
		);
		$data = self::getJson($output);
		if (!is_object($data) || !is_object($data->summary) || !is_array($data->diagnostics)) {
			$this->lastErrors = $output;
			return null;
		}
		$result = [];
		foreach ($data->diagnostics as $issue) {
			$where = $issue->location;
			$location = $this->calcLocationFromSpan($where->sourceCode, $where->span);
			$result[] = $this->makeIssue(
				$where->path->file,
				$location['startLine'],
				'Biome: ' . $issue->description,
				$issue->severity,
				'Biome.' . $issue->category,
				$location['startColumn'],
				$location['endLine'],
				$location['endColumn']
			);
		}
		return $result;
		/**/
		// endregion JSON reporter: need to calculate issue location, but returns all the issues
	}

	private function getStats(array $issues): int
	{
		if (!$this->silent) {
			// GitLab CodeQuality JSON
			echo self::prettyJson($issues), PHP_EOL;
		}

		// Группировка ошибок
		$types = [];
		$lastIssues = [];
		$critical = 0;
		foreach ($issues as $issue) {
			if (($issue['severity'] ?? '') === self::SEVERITY_CRITICAL) {
				$critical++;
			}
			$type = $issue['check_name'];
			$types[$type] = ($types[$type] ?? 0) + 1;
			$lastIssues[$type] = $issue;
		}

		if ($this->printStats) {
			if (count($types) > 0) {
				arsort($types);
				$this->stdErrPrintLine('Issue types by count:');
				$this->stdErrPrintLine("\tRNK\tCNT\tTYPE\t");
				$rank = 1;
				foreach ($types as $type => $count) {
					$line = "\t#" . ($rank++) . "\t" . $count . "\t" . $type;
					if (
						$this->printLast === self::PRINT_LAST_YES ||
						($this->printLast === self::PRINT_LAST_SINGLE && $count === 1)
					) {
						$lastIssue = $lastIssues[$type]['location'];
						$position = [$lastIssue['full_path']];
						if (isset($lastIssue['positions']['begin']['line'])) {
							$position[] = $lastIssue['positions']['begin']['line'];
						}
						if (isset($lastIssue['positions']['begin']['column'])) {
							$position[] = $lastIssue['positions']['begin']['column'];
						}
						$line .= "\t" . 'Last: ' . implode(':', $position);
					}
					$this->stdErrPrintLine($line);
				}
			}
			$this->stdErrPrintLine('Total issues: ' . count($issues) . ' (' . $critical . ' critical)');
		}

		if ($this->strict) {
			return count($issues) > 0 ? self::RESULT_ISSUES_WITH_STRICT_MODE : self::RESULT_OK;
		}
		return $critical > 0 ? self::RESULT_CRITICAL_ISSUES : self::RESULT_OK;
	}

	private function processComposerConfig(): void
	{
		$composerFile = $this->phpAppDir . '/composer.json';
		if (is_file($composerFile)) {
			$this->stdErrPrintLine('Loading Composer config file: ' . $composerFile, 2);
			$composer = self::getJson(file_get_contents($composerFile));
			$config = $composer->extra->{'gitlab-code-quality'} ?? null;
			if ($config) {
				foreach ($config as $key => $value) {
					switch (strtolower(trim($key))) {
						// Psalm
						case 'psalm':
							if ($value === false) {
								$this->runPsalm = false;
							}
							break;
						case 'psalm-config':
							$this->psalmConfig = trim($value);
							break;

						// PhpStan
						case 'phpstan':
							if ($value === false) {
								$this->runPhpStan = false;
							}
							break;
						case 'phpstan-config':
							$this->phpStanConfig = trim($value);
							break;

						// PHP CodeSniffer
						case 'phpcs':
							if ($value === false) {
								$this->runPhpCs = false;
							}
							break;
						case 'phpcs-standard':
							$this->phpCsStandard = trim($value);
							break;

						// ECS (Easy Coding Standard)
						case 'ecs':
							if ($value === false) {
								$this->runEcs = false;
							}
							break;
						case 'ecs-config':
							$this->ecsConfig = trim($value);
							break;

						// Bun
						case 'bun':
							$this->bunBin = trim($value);
							break;

						// NodeJS
						case 'node':
							$this->nodeBin = trim($value);
							break;

						// EsLint
						case 'eslint':
							if ($value === false) {
								$this->runEsLint = false;
							}
							break;
						case 'eslint-config':
							$this->esLintConfig = trim($value);
							break;

						// StyleLint
						case 'stylelint':
							if ($value === false) {
								$this->runStyleLint = false;
							}
							break;
						case 'stylelint-config':
							$this->styleLintConfig = trim($value);
							break;
						case 'stylelint-files':
							$this->styleLintFiles = trim($value);
							break;

						// Biome
						case 'biome':
							if ($value === false) {
								$this->runBiome = false;
							}
							break;
						case 'biome-config':
							$this->biomeConfig = trim($value);
							break;

						// Other
						case 'php-dir':
							$this->phpDir = trim($value);
							break;
						case 'js-dir':
							$this->jsDir = trim($value);
							break;
						case 'stats':
							$this->printStats = (bool)$value;
							break;
						case 'last':
							$this->printLast = $this->parsePrintLast($value);
							break;
						case 'silent':
							$this->silent = (bool)$value;
							break;
						case 'cache':
							$this->cache = (bool)$value;
							break;
						case 'strict':
							$this->strict = (bool)$value;
							break;
					}
				}
			}
		}
	}

	private function processJsConfig(): void
	{
		//$packageFile = $this->phpDir . '/package.json';
		//if (is_file($packageFile)) {
		//	$this->stdErrPrintLine('Loading JS config file: ' . $packageFile, 2);
		//	$package = self::getJson(file_get_contents($packageFile));
		//	// ... ... ...
		//}
	}

	private function getJsRuntime(): ?string
	{
		if ($this->hasBun) {
			return $this->bunBin;
		}
		if ($this->hasNode) {
			return $this->nodeBin;
		}
		return null;
	}

	/**
	 * Create an issue
	 *
	 * @link https://docs.gitlab.com/ee/ci/testing/code_quality.html#implement-a-custom-tool
	 * @link https://github.com/codeclimate/platform/blob/master/spec/analyzers/SPEC.md#data-types
	 */
	private function makeIssue(
		string     $path,
		int        $startLine,
		string     $description,
		string|int $severity = self::SEVERITY_MAJOR,
		string     $class = '',
		int        $startColumn = -1,
		int        $endLine = -1,
		int        $endColumn = -1
	): array
	{
		$relativePath = $this->relativePath($path);
		if (
			$severity === 0 ||
			strtolower($severity) === 'info' ||
			strtolower($severity) === 'notice'
		) {
			$severity = self::SEVERITY_INFO;
		} elseif ($severity === 1 || strtolower($severity) === 'warning') {
			$severity = self::SEVERITY_MINOR;
		} elseif ($severity === 2 || strtolower($severity) === 'error') {
			$severity = self::SEVERITY_MAJOR;
		}
		$positions = ['begin' => ['line' => $startLine]];
		$positionString = $startLine . ':';
		if ($startColumn >= 0) {
			$positions['begin']['column'] = $startColumn;
			$positionString .= $startColumn;
		}
		$positionString .= ':';
		if ($endLine >= 0) {
			$positions['end']['line'] = $endLine;
			$positionString .= $endLine;
		}
		$positionString .= ':';
		if ($endColumn >= 0) {
			$positions['end']['column'] = $endColumn;
			$positionString .= $endColumn;
		}
		return [
			'type' => 'issue',
			'check_name' => $class ?: $description,
			'description' => $description,
			'categories' => ['Clarity', 'Style'],
			'severity' => $severity,
			'location' => [
				'path' => $relativePath,
				'full_path' => $path,
				'positions' => $positions,
			],
			'fingerprint' => sha1($relativePath . '[' . $positionString . ']' . $description),
		];
	}

	private static function getJson(string $json): null|array|object
	{
		try {
			$data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			$data = null;
		}
		return is_array($data) || is_object($data) ? $data : null;
	}

	private static function prettyJson(mixed $value): string
	{
		try {
			return preg_replace_callback(
				'/^( {4,})/m',
				static function ($match) {
					return str_replace('    ', "\t", $match[1]);
				},
				json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
			);
		} catch (Throwable) {
		}
		return '[]';
	}

	/**
	 * Сделать относительный путь (к корню проекта)
	 */
	private function relativePath(string $path): string
	{
		$dir = $this->currentDir . DIRECTORY_SEPARATOR;
		if (str_starts_with($path, $dir)) {
			return mb_substr($path, mb_strlen($dir));
		}
		return $path;
	}

	/**
	 * https://github.com/actions/toolkit/blob/fe3e7ce9a7f995d29d1fcfd226a32bca407f9dc8/packages/core/src/command.ts#L80-L85
	 */
	private static function deEscapeGithubReportData(string $data): string
	{
		return str_replace(['%0D', '%0A', '%25'], ["\r", "\n", '%'], $data);
	}

	/**
	 * https://github.com/actions/toolkit/blob/fe3e7ce9a7f995d29d1fcfd226a32bca407f9dc8/packages/core/src/command.ts#L87-L94
	 */
	private static function deEscapeGithubReportProperty(string $property): string
	{
		return str_replace(['%0D', '%0A', '%3A', '%2C', '%25'], ["\r", "\n", ':', ',', '%'], $property);
	}

	/**
	 * Calculate location from byte offsets inside source code. The complexity is O(n).
	 *
	 * @param ?string $source Source code
	 * @param ?array{0: int<0,max>, 1: int<0,max>} $span Array with from and to byte offsets
	 * @return array{startLine: int<1,max>, startColumn: int<-1,max>, endLine: int<-1,max>, endColumn: int<-1,max>}
	 */
	private function calcLocationFromSpan(?string $source, ?array $span): array
	{
		if ($source === null || $span === null) {
			return [
				'startLine' => 1,
				'startColumn' => -1,
				'endLine' => -1,
				'endColumn' => -1,
			];
		}
		$from = min($span[0], $span[1]);
		$to = max($span[0], $span[1]);
		$currentLine = 1;
		$startLine = $currentLine;
		for ($i = 0; $i < $to; $i++) {
			$byte = $source[$i];
			if ($byte === "\r") {
				$nextByte = $source[$i + 1] ?? null;
				if ($nextByte === "\n") {
					// CRLF, skip to the next byte
					continue;
				}
				// CR only
				$currentLine++;
				if ($i <= $from) {
					$startLine++;
				}
			} elseif ($byte === "\n") {
				if ($from === 80 && $to === 83) {
					echo 'GOT LF', PHP_EOL;
				}
				// LF or CRLF
				$currentLine++;
				if ($i <= $from) {
					$startLine++;
				}
			}
		}
		return [
			'startLine' => $startLine,
			'startColumn' => -1,
			'endLine' => $currentLine,
			'endColumn' => -1,
		];
	}

	/**
	 * Разобрать значение `printLast` из конфигурации
	 */
	private function parsePrintLast(mixed $value): int
	{
		return match (is_string($value) ? strtolower($value) : $value) {
			0, false, 'no', 'false' => self::PRINT_LAST_NO,
			1, true, 'yes', 'true' => self::PRINT_LAST_YES,
			default => self::PRINT_LAST_SINGLE,
		};
	}

	/**
	 * Выполнить команду, вернуть код выхода и установить выходную строку `$output`
	 */
	private function execCommand(string $command, ?string &$output = null): int
	{
		$this->stdErrPrintLine('Executing command: ' . $command, 2);
		exec($command, $result, $code);
		$output = implode(PHP_EOL, $result);
		return $code;
	}

	/**
	 * Напечатать строку в STDERR
	 */
	private function stdErrPrintLine(string $line, int $verbosity = 1): void
	{
		if ($this->verbosity < $verbosity) {
			return;
		}
		fwrite(STDERR, $line . PHP_EOL);
	}
}
