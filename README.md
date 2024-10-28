# GitLab Code Quality generator for PHP and JS projects

This program generates [Code Climate](https://github.com/codeclimate/platform/blob/master/spec/analyzers/SPEC.md#data-types) report for [GitLab Code Quality widget](https://docs.gitlab.com/ee/ci/testing/code_quality.html#view-code-quality-results) in merge requests and pipelines using various tools and linters:
* [Psalm](https://psalm.dev/)
* [PHPStan](https://phpstan.org/)
* [PHP CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
* [ECS](https://github.com/easy-coding-standard/easy-coding-standard)
* [ESLint](https://eslint.org/)
* [StyleLint](https://stylelint.io/)
* [Biome](https://biomejs.dev/)

They are automatically detected in the following paths:
* `vendor/bin/psalm`
* `vendor/bin/phpstan`
* `vendor/bin/phpcs`
* `vendor/bin/ecs`
* `node_modules/eslint/bin/eslint.js`
* `node_modules/stylelint/bin/stylelint.mjs`
* `node_modules/@biomejs/biome/bin/biome`

Two JS runtimes supported (to run Biome, ESLint, and StyleLint checks):
* [Bun](https://bun.sh/) (preferred over Node);
* [Node](https://nodejs.org/en) (used if no Bun executable found).

The application is written in pure PHP (8.0+) without any framework or library dependencies.


## Installation
Install the tool via [Composer](https://getcomposer.org/):
```shell
composer require --dev maximal/gitlab-code-quality
```

Make the test run:
```shell
./vendor/bin/gitlab-code-quality > gl-code-quality-report.json
```
Now, you should have `gl-code-quality-report.json` file with all code quality issues found in your project.


## Usage
Edit your GitLab CI/CD config to have `code_quality` step, which runs this tool.

Example `.gitlab-ci.yml` file:
```yaml
# ... ... ...

# GitLab Code Quality Widget
# https://docs.gitlab.com/ee/ci/testing/code_quality.html#view-code-quality-results
code_quality:
  stage: test
  script:
    - ./vendor/bin/gitlab-code-quality > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
    expire_in: 1 month
    paths: [gl-code-quality-report.json]

# ... ... ...
```


### Strict Mode
By default, the tool returns non-zero exit code (1) only if critical errors are found. For example, on PHP syntax parsing errors (the code cannot be executed at all). This is considered a non-strict behavior.

Although, you can harden issue handling and have non-zero exit code (2) if any issues are found:
```shell
./vendor/bin/gitlab-code-quality --strict > gl-code-quality-report.json
```

In strict mode, code quality stage will fail CI/CD pipeline even on minor issues.


## Configuration
Code quality tools are detected automatically and run with their default config files.
You can override this behavior in `extra` section of your project’s `composer.json` file:
```json5
{
	// composer.json
	// ... ... ...
	"extra": {
		// GitLab Code Quality report settings with their default values
		// All the paths and filenames are relative to the root of the project
		"gitlab-code-quality": {
			// Directory for PHP checks/linters
			"php-dir": ".",
			// Directory for JS checks/linters
			"js-dir": "resources",
			// Paths above are typical for Laravel projects
			// Print issue statistics table to STDERR (`false` to only print issues JSON to STDOUT)
			"stats": true,
			// Print last issue location for every issue type in statistics table:
			// `false` or `"no"` — do not print
			// `true` or `"yes"` — print
			// `"single"` (default) — print only if the issue is the only one of its class
			"last": "single",
			// Run Psalm and PHP CodeSniffer with `--no-cache` and ECX with `--clear-cache`
			"cache": false,
			// Strict mode:
			// `false` to return non-zero exit code only if critical errors are found (for example, PHP parsing errors)
			// `true` to return non-zero exit code if any issues are found
			"strict": false,

			// Run Psalm if it exists in `vendor/bin`
			"psalm": true,
			// Psalm config file path
			"psalm-config": "psalm.xml",

			// Run PHPStan if it exists in `vendor/bin`
			"phpstan": true,
			// PHPStan config file path
			"phpstan-config": "phpstan.neon",

			// Run PHP CodeSniffer if it exists in `vendor/bin`
			"phpcs": true,
			// PHP CodeSniffer standard (name or path to rules file)
			"phpcs-standard": "PSR12",

			// Run ECS (Easy Coding Standard) if it exists in `vendor/bin`
			"ecs": true,
			// ECS config file path
			"ecs-config": "ecs.php",

			// Bun executable for EsLint and StyleLint (preferred over Node)
			"bun": "bun",
			// Node executable for EsLint and StyleLint (used if no Bun found)
			"node": "node",

			// Run ESLint if it exists in `node_modules/eslint/bin/eslint.js`
			"eslint": true,
			// ESLint config file
			"eslint-config": ".eslintrc.yml",

			// Run StyleLint if it exists in `node_modules/stylelint/bin/stylelint.mjs`
			"stylelint": true,
			// StyleLint config file
			"stylelint-config": ".stylelintrc.yml",
			// Files to check glob pattern for StyleLint
			"stylelint-files": "resources/**/*.{css,scss,sass,vue}",

			// Run Biome if it exists in `node_modules/@biomejs/biome/bin/biome`
			"biome": true,
			// Biome config file
			"biome-config": "biome.jsonc"
		}
	},
	// ... ... ...
}
```

In most cases you only need to specify `php-dir` and `js-dir` paths. For example:
```json5
{
	// composer.json
	// ... ... ...
	"require-dev": {
		// ...
		"maximal/gitlab-code-quality": "^1.0",
		// ...
	},
	// ... ... ...
	"extra": {
		"gitlab-code-quality": {
			// Run all quality tools if they exist,
			// considering that PHP files are located in `app` directory
			// and JS files are located in `frontend` directory
			"php-dir": "app",
			"js-dir": "frontend",
		}
	},
	// ... ... ...
}
```

In an ordinary [Laravel](https://laravel.com/) project this tool runs in zero-config way with Laravel’s default paths (`php-dir` is `.` and `js-dir` is `resourses`):
```json5
{
	// composer.json
	// ... ... ...
	"require-dev": {
		// ...
		"maximal/gitlab-code-quality": "^1.0",
		// ...
	},
	// ... ... ...
	"extra": {
		"laravel": {
			// ... Laravel’s `dont-discover` and other configs ...
		},
		// No `gitlab-code-quality` section
	},
	// ... ... ...
}
```


## Coding Style
PER-2T / PSR-12T (PHP’s standard [PER-2](https://www.php-fig.org/per/coding-style/) / [PSR-12](https://www.php-fig.org/psr/psr-12/) with [SmartTabs](https://www.emacswiki.org/emacs/SmartTabs) instead of spaces).


## Author
* https://github.com/maximal
* https://maximals.ru/
* https://sijeko.ru/
