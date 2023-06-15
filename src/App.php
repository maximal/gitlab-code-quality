<?php

/**
 * GitLab Code Quality generator for PHP and JS projects.
 *
 * Code style: PSR-12T (PSR12 with SmartTabs).
 *
 * @link https://github.com/maximal/gitlab-code-quality
 * @link https://maximals.ru/
 * @link https://sijeko.ru/
 *
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
	// Config variables
	private string $phpDir = '.';
	private string $jsDir = 'resources';
	private string $nodeBin = 'node';

	private string $psalmConfig = self::DEFAULT_PSALM_CONFIG;
	private string $phpStanConfig = self::DEFAULT_PHPSTAN_CONFIG;
	private string $phpCsStandard = self::DEFAULT_PHPCS_STANDARD;
	private string $esLintConfig = self::DEFAULT_ESLINT_CONFIG;

	private bool $printStats = true;
	private bool $silent = false;
	private bool $cache = false;

	private bool $runPsalm = true;
	private bool $runPhpStan = true;
	private bool $runPhpCs = true;
	private bool $runEsLint = true;

	// Runtime variables
	private array $argv;
	private string $binDir;
	private string $phpAppDir;
	private string $jsAppDir;
	private string $currentDir;

	private bool $hasPsalm = false;
	private bool $hasPhpStan = false;
	private bool $hasPhpCs = false;
	private bool $hasEsLint = false;

	private string $binPsalm;
	private string $binPhpStan;
	private string $binPhpCs;
	private string $binEsLint;

	private ?string $lastErrors = null;

	// Severities
	private const SEVERITY_MINOR = 'minor';
	private const SEVERITY_MAJOR = 'major';
	private const SEVERITY_CRITICAL = 'critical';

	// Result codes
	private const RESULT_PSALM_FAILED = 1;
	private const RESULT_PHPSTAN_FAILED = 2;
	private const RESULT_PHPCS_FAILED = 3;
	private const RESULT_ESLINT_FAILED = 4;
	private const RESULT_CRITICAL_ISSUES = 5;

	// Default config files
	private const DEFAULT_PSALM_CONFIG = 'psalm.xml';
	private const DEFAULT_PHPSTAN_CONFIG = 'phpstan.neon';
	private const DEFAULT_PHPCS_STANDARD = 'PSR12';
	private const DEFAULT_ESLINT_CONFIG = '.eslintrc.yml';

	// Project version
	public const VERSION = '1.1';


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
		foreach ($this->argv as $index => $argument) {
			if ($index === 0) {
				continue;
			}
			switch (strtolower(trim($argument))) {
				case '--help':
				case '-h':
					return $this->printHelp();
				case '--version':
				case '-v':
					echo 'v' . self::VERSION, PHP_EOL;
					return 0;
			}
		}
		$this->processComposerConfig();
		$this->processNodeConfig();
		return null;
	}

	private function printHelp(): int
	{
		echo '    GitLab Code Quality Tool for PHP and JS    ', PHP_EOL;
		echo '===============================================', PHP_EOL;
		echo 'v', str_pad(self::VERSION, 22), '© MaximAL of Sijeko 2023', PHP_EOL;
		echo PHP_EOL;
		echo 'Runs: Psalm, PHPStan, PHP CodeSniffer, ESLint.', PHP_EOL;
		echo PHP_EOL;
		echo 'Installation:', PHP_EOL;
		echo '    composer require maximal/gitlab-code-quality', PHP_EOL;
		echo PHP_EOL;
		echo 'Usage:', PHP_EOL;
		echo '    ' . $this->argv[0] . ' > gl-code-quality-report.json', PHP_EOL;
		echo PHP_EOL;
		echo 'Options:', PHP_EOL;
		echo '    -h  --help       Print help.', PHP_EOL;
		echo '    -v  --version    Print version.', PHP_EOL;
		echo PHP_EOL;
		echo 'https://github.com/maximal/gitlab-code-quality', PHP_EOL;
		return 0;
	}

	private function detectTools(): void
	{
		$binDir = $this->binDir . '/';
		$this->binPsalm = realpath($binDir . 'psalm');
		$this->binPhpStan = realpath($binDir . 'phpstan');
		$this->binPhpCs = realpath($binDir . 'phpcs');
		$this->binEsLint = realpath($this->currentDir . '/node_modules/eslint/bin/eslint.js');

		$this->hasPsalm = is_file($this->binPsalm);
		$this->hasPhpStan = is_file($this->binPhpStan);
		$this->hasPhpCs = is_file($this->binPhpCs);
		$this->hasEsLint = is_file($this->binEsLint);
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
			self::stdErrPrintLine($this->lastErrors);
			self::stdErrPrintLine('Error running Psalm. See errors above.');
			return self::RESULT_PSALM_FAILED;
		}
		array_push($issues, ...$psalm);

		$phpStan = $this->runPhpStan();
		if ($this->lastErrors !== null) {
			self::stdErrPrintLine($this->lastErrors);
			self::stdErrPrintLine('Error running PHPStan. See errors above.');
			return self::RESULT_PHPSTAN_FAILED;
		}
		array_push($issues, ...$phpStan);

		$phpCs = $this->runPhpCs();
		if ($this->lastErrors !== null) {
			self::stdErrPrintLine($this->lastErrors);
			self::stdErrPrintLine('Error running PHP CodeSniffer. See errors above.');
			return self::RESULT_PHPCS_FAILED;
		}
		array_push($issues, ...$phpCs);

		if ($this->jsAppDir !== $this->phpAppDir) {
			// Change current dir to JS app dir
			chdir($this->jsAppDir);
		}

		$esLint = $this->runEsLint();
		if ($this->lastErrors !== null) {
			self::stdErrPrintLine($this->lastErrors);
			self::stdErrPrintLine('Error running ESLint. See errors above.');
			return self::RESULT_ESLINT_FAILED;
		}
		array_push($issues, ...$esLint);

		if (getcwd() !== $this->currentDir) {
			// Change current dir to the old current dir
			chdir($this->currentDir);
		}

		return $this->getStats($issues);
	}

	private function runPsalm(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runPsalm || !$this->hasPsalm) {
			return [];
		}
		self::stdErrPrintLine('Running Psalm...');
		$config = $this->psalmConfig !== self::DEFAULT_PSALM_CONFIG ?
			('--config=' . escapeshellarg($this->psalmConfig)) : '';
		$dir = !in_array($this->phpDir, ['', '.']) ? escapeshellarg($this->phpDir) : '';
		exec(
			$this->binPsalm . ($this->cache ? '' : ' --no-cache') .
			' --memory-limit=-1 --output-format=json ' . $config .
			' ' . $dir,
			$output
		);
		$text = implode(PHP_EOL, $output);
		$data = self::getJson($text);
		if (!is_array($data)) {
			$this->lastErrors = $text;
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
		self::stdErrPrintLine('Running PhpStan...');
		$config = $this->phpStanConfig !== self::DEFAULT_PHPSTAN_CONFIG ?
			('--configuration=' . escapeshellarg($this->phpStanConfig)) : '';
		$dir = !in_array($this->phpDir, ['', '.']) ? escapeshellarg($this->phpDir) : '';
		exec(
			$this->binPhpStan .
			' analyse --memory-limit=-1 --no-interaction --error-format=json ' .
			$config . ' ' . $dir,
			$output
		);
		$text = implode(PHP_EOL, $output);
		$data = self::getJson($text);
		if (!is_object($data) || !isset($data->files)) {
			$this->lastErrors = $text;
			return null;
		}
		$result = [];
		foreach ($data->files as $fileName => $file) {
			foreach ($file->messages as $issue) {
				$result[] = $this->makeIssue(
					$fileName,
					$issue->line,
					'PHPStan: ' . $issue->message
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
		self::stdErrPrintLine(
			'Running PHP CodeSniffer with standard ' .
			$this->phpCsStandard . '...'
		);
		exec(
			$this->binPhpCs . ($this->cache ? '' : ' --no-cache') .
			' --report=json --standard=' . escapeshellarg($this->phpCsStandard) .
			' ' . escapeshellarg($this->phpDir),
			$output
		);
		$text = implode(PHP_EOL, $output);
		$data = self::getJson($text);
		if (!is_object($data) || !is_object($data->files)) {
			$this->lastErrors = $text;
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

	private function runEsLint(): ?array
	{
		$this->lastErrors = null;
		if (!$this->runEsLint || !$this->hasEsLint) {
			return [];
		}
		self::stdErrPrintLine('Running ES Lint...');
		$config = $this->esLintConfig !== self::DEFAULT_ESLINT_CONFIG ?
			('--config ' . escapeshellarg($this->esLintConfig)) : '';
		exec(
			$this->nodeBin . ' ' . escapeshellarg($this->binEsLint) .
			' --format=json ' . $config . ' ' . escapeshellarg($this->jsDir),
			$output
		);
		$text = implode(PHP_EOL, $output);
		$data = self::getJson($text);
		if (!is_array($data)) {
			$this->lastErrors = $text;
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

	private function getStats(array $issues): int
	{
		if (!$this->silent) {
			// GitLab CodeQuality JSON
			echo self::prettyJson($issues), PHP_EOL;
		}

		// Группировка ошибок
		$types = [];
		$critical = 0;
		foreach ($issues as $issue) {
			if (($issue['severity'] ?? '') === self::SEVERITY_CRITICAL) {
				$critical++;
			}
			$types[$issue['check_name']] = ($types[$issue['check_name']] ?? 0) + 1;
		}

		if ($this->printStats) {
			if (count($types) > 0) {
				arsort($types);
				self::stdErrPrintLine('Issue types by count:');
				self::stdErrPrintLine("\tRNK\tCNT\tTYPE\t");
				$rank = 1;
				foreach ($types as $type => $count) {
					self::stdErrPrintLine("\t#" . ($rank++) . "\t" . $count . "\t" . $type);
				}
			}
			self::stdErrPrintLine('Total issues: ' . count($issues) . ' (' . $critical . ' critical)');
		}

		return $critical > 0 ? self::RESULT_CRITICAL_ISSUES : 0;
	}

	private function processComposerConfig(): void
	{
		$composerFile = $this->phpAppDir . '/composer.json';
		if (is_file($composerFile)) {
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

						// EsLint
						case 'eslint':
							if ($value === false) {
								$this->runEsLint = false;
							}
							break;
						case 'eslint-config':
							$this->esLintConfig = trim($value);
							break;
						case 'node':
							$this->nodeBin = trim($value);
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
						case 'silent':
							$this->silent = (bool)$value;
							break;
						case 'cache':
							$this->cache = (bool)$value;
							break;
					}
				}
			}
		}
	}

	private function processNodeConfig(): void
	{
		// ... ... ...
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
		$path = $this->relativePath($path);
		if ($severity === 1 || strtolower($severity) === 'warning') {
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
				'path' => $path,
				'positions' => $positions,
			],
			'fingerprint' => sha1($path . '[' . $positionString . ']' . $description),
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
	 * Напечатать строку в STDERR
	 */
	private static function stdErrPrintLine(string $line): void
	{
		fwrite(STDERR, $line . PHP_EOL);
	}
}
