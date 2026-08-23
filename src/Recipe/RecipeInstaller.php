<?php

declare(strict_types=1);

namespace Contempt\Composer\Recipe;

use Contempt\Composer\Recipe\Internal\RecipeMutation;

/**
 * @phpstan-type RecipeOperation array{type: string, source?: string, target?: string, mode?: string, file?: string, name?: string, description?: string, entry?: string, value?: array<string, mixed>}
 * @phpstan-type RecipeRecord array{recipeVersion: string, files: array<string, string>, originals: array<string, ?string>, directories: list<string>}
 * @phpstan-type RecipeLock array{schemaVersion: '1.0', recipes: array<string, RecipeRecord>}
 */
final readonly class RecipeInstaller
{
    private const int MAX_RECIPE_BYTES = 1_048_576;
    private const int MAX_LOCK_BYTES = 4_194_304;

    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $resolved = realpath($projectRoot);

        if ($resolved === false || !is_dir($resolved) || is_link($projectRoot) || !is_writable($resolved)) {
            throw new \InvalidArgumentException('Recipe project root must be a real writable directory.');
        }

        $this->projectRoot = $resolved;
    }

    public function install(string $package, string $recipeFile): RecipeInstallResult
    {
        self::package($package);

        return $this->withExclusiveLock(fn(): RecipeInstallResult => $this->installLocked($package, $recipeFile));
    }

    public function uninstall(string $package): RecipeUninstallResult
    {
        self::package($package);

        return $this->withExclusiveLock(fn(): RecipeUninstallResult => $this->uninstallLocked($package));
    }

    /** @return list<string> */
    public function installedPackages(): array
    {
        return $this->withExclusiveLock(function (): array {
            $packages = array_keys($this->readLock()['recipes']);
            sort($packages, SORT_STRING);

            return $packages;
        });
    }

    private function installLocked(string $package, string $recipeFile): RecipeInstallResult
    {
        $recipePath = realpath($recipeFile);
        $size = $recipePath === false ? false : filesize($recipePath);

        if ($recipePath === false || !is_file($recipePath) || is_link($recipeFile) || $size === false || $size > self::MAX_RECIPE_BYTES) {
            throw new \InvalidArgumentException('Recipe must be a regular JSON file no larger than 1 MiB.');
        }

        $recipe = self::recipe($recipePath);
        $lock = $this->readLock();
        $record = $lock['recipes'][$package] ?? self::emptyRecord($recipe['recipeVersion']);
        $ownedDirectories = $record['directories'];
        $mutation = new RecipeMutation();

        try {
            foreach ($recipe['operations'] as $operation) {
                $type = $operation['type'];

                if ($type === 'copy') {
                    $source = self::source(\dirname($recipePath), self::stringField($operation, 'source'));
                    $relative = self::stringField($operation, 'target');
                    $target = $this->target($relative, false);

                    if (file_exists($target) && !\array_key_exists($relative, $record['files'])) {
                        throw new \RuntimeException('Recipe refuses to overwrite an existing application file.');
                    }

                    $contents = file_get_contents($source);

                    if ($contents === false) {
                        throw new \RuntimeException('Could not read recipe source file.');
                    }

                    $this->applyContent($relative, $contents, $record, $mutation);
                } elseif ($type === 'mkdir') {
                    $relative = self::stringField($operation, 'target');
                    $target = $this->target($relative, false);

                    if (is_link($target) || (file_exists($target) && !is_dir($target))) {
                        throw new \RuntimeException('Recipe directory target is occupied by a non-directory.');
                    }

                    if (!is_dir($target)) {
                        $this->createDirectory($relative);
                        $record['directories'][] = $relative;
                        $mutation->created[] = $relative;
                    }
                } elseif ($type === 'config-import') {
                    $this->applyConfigImport(self::stringField($operation, 'file'), $record, $mutation);
                } elseif ($type === 'env-document') {
                    $this->applyEnvDocument(
                        self::stringField($operation, 'name'),
                        self::stringField($operation, 'description'),
                        $record,
                        $mutation,
                    );
                } elseif ($type === 'gitignore-entry') {
                    $this->applyLineEntry('.gitignore', self::stringField($operation, 'entry'), $record, $mutation);
                } elseif ($type === 'json-merge') {
                    $this->applyJsonMerge(self::stringField($operation, 'target'), self::objectField($operation, 'value'), $record, $mutation);
                } else {
                    throw new \LogicException('Validated recipe operation has no executor.');
                }
            }
        } catch (\Throwable $failure) {
            $this->rollbackChanges($mutation->changed);
            $this->rollbackDirectories(array_values(array_diff($record['directories'], $ownedDirectories)));
            throw $failure;
        }

        $record['recipeVersion'] = $recipe['recipeVersion'];
        $record['directories'] = array_values(array_unique($record['directories']));
        sort($record['directories'], SORT_STRING);
        ksort($record['files'], SORT_STRING);
        ksort($record['originals'], SORT_STRING);
        $lock['recipes'][$package] = $record;
        ksort($lock['recipes'], SORT_STRING);
        $this->writeLock($lock);

        return new RecipeInstallResult(array_values(array_unique($mutation->created)));
    }

    private function uninstallLocked(string $package): RecipeUninstallResult
    {
        $lock = $this->readLock();
        $recipe = $lock['recipes'][$package] ?? null;

        if ($recipe === null) {
            return new RecipeUninstallResult([], []);
        }

        $removed = [];
        $retained = [];

        foreach ($recipe['files'] as $relative => $expectedHash) {
            $target = $this->target($relative, false);

            if (!file_exists($target)) {
                continue;
            }

            if (is_link($target) || !is_file($target) || self::fileHash($target) !== $expectedHash) {
                $retained[] = $relative;
                continue;
            }

            $original = $recipe['originals'][$relative] ?? null;

            if ($original === null) {
                if (!unlink($target)) {
                    throw new \RuntimeException('Could not remove an unchanged recipe-owned file.');
                }
            } else {
                $decoded = base64_decode($original, true);

                if ($decoded === false) {
                    throw new \RuntimeException('contempt.lock contains invalid original file data.');
                }

                self::atomicWrite($target, $decoded);
            }

            $removed[] = $relative;
        }

        $directories = $recipe['directories'];
        usort($directories, static fn(string $left, string $right): int => substr_count($right, '/') <=> substr_count($left, '/'));

        foreach ($directories as $relative) {
            $target = $this->target($relative, false);

            if (is_dir($target) && !is_link($target) && (scandir($target) ?: []) === ['.', '..']) {
                if (!rmdir($target)) {
                    throw new \RuntimeException('Could not remove an empty recipe-owned directory.');
                }

                $removed[] = $relative;
            }
        }

        unset($lock['recipes'][$package]);
        $this->writeLock($lock);
        sort($removed, SORT_STRING);
        sort($retained, SORT_STRING);

        return new RecipeUninstallResult($removed, $retained);
    }

    /**
     * @param RecipeRecord $record
     */
    private function applyContent(string $relative, string $contents, array &$record, RecipeMutation $mutation): void
    {
        $target = $this->target($relative, true);

        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new \RuntimeException('Recipe target exists and is not a regular file.');
        }

        $current = is_file($target) ? file_get_contents($target) : null;

        if ($current === false) {
            throw new \RuntimeException('Could not read existing recipe target.');
        }

        $expected = $record['files'][$relative] ?? null;

        if ($expected !== null && ($current === null || self::contentHash($current) !== $expected)) {
            throw new \RuntimeException('Recipe refuses to overwrite a modified application file.');
        }

        if ($current === $contents) {
            return;
        }

        if (!\array_key_exists($relative, $mutation->changed)) {
            $mutation->changed[$relative] = $current;
        }
        if (!\array_key_exists($relative, $record['originals'])) {
            $record['originals'][$relative] = $current === null ? null : base64_encode($current);
        }
        self::atomicWrite($target, $contents);
        $record['files'][$relative] = self::contentHash($contents);
        $mutation->created[] = $relative;
    }

    /** @param RecipeRecord $record */
    private function applyConfigImport(string $file, array &$record, RecipeMutation $mutation): void
    {
        if (!self::safePath($file) || !str_ends_with($file, '.php')) {
            throw new \InvalidArgumentException('Configuration import must reference a safe PHP configuration path.');
        }

        $relative = 'config/contempt.imports.json';
        $current = $this->readStringList($relative);

        if (!\in_array($file, $current, true)) {
            $current[] = $file;
            sort($current, SORT_STRING);
            $this->applyContent($relative, self::json($current), $record, $mutation);
        }
    }

    /** @param RecipeRecord $record */
    private function applyEnvDocument(string $name, string $description, array &$record, RecipeMutation $mutation): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) !== 1 || trim($description) !== $description || $description === '' || str_contains($description, "\n")) {
            throw new \InvalidArgumentException('Environment documentation entry is malformed.');
        }

        $relative = '.env.example';
        $target = $this->target($relative, false);
        $current = is_file($target) ? file_get_contents($target) : '';

        if ($current === false) {
            throw new \RuntimeException('Could not read environment documentation file.');
        }

        if (preg_match('/^' . preg_quote($name, '/') . '=/m', $current) === 1) {
            return;
        }

        $prefix = $current === '' || str_ends_with($current, "\n") ? '' : "\n";
        $this->applyContent($relative, $current . $prefix . '# ' . $description . "\n" . $name . "=\n", $record, $mutation);
    }

    /** @param RecipeRecord $record */
    private function applyLineEntry(string $relative, string $entry, array &$record, RecipeMutation $mutation): void
    {
        if ($entry === '' || trim($entry) !== $entry || str_contains($entry, "\n") || str_contains($entry, "\0")) {
            throw new \InvalidArgumentException('Line entry is malformed.');
        }

        $target = $this->target($relative, false);
        $current = is_file($target) ? file_get_contents($target) : '';

        if ($current === false) {
            throw new \RuntimeException('Could not read line-oriented target file.');
        }

        if (\in_array($entry, preg_split('/\R/', rtrim($current, "\r\n")) ?: [], true)) {
            return;
        }

        $prefix = $current === '' || str_ends_with($current, "\n") ? '' : "\n";
        $this->applyContent($relative, $current . $prefix . $entry . "\n", $record, $mutation);
    }

    /**
     * @param array<string, mixed> $value
     * @param RecipeRecord $record
     */
    private function applyJsonMerge(string $relative, array $value, array &$record, RecipeMutation $mutation): void
    {
        if (preg_match('#^(?:config|\.github|\.vscode)/[A-Za-z0-9._/-]+\.json$#D', $relative) !== 1) {
            throw new \InvalidArgumentException('JSON merge is restricted to object values in approved configuration directories.');
        }

        $current = $this->readJsonObject($relative);
        $merged = self::mergeWithoutOverwrite($current, $value);
        $this->applyContent($relative, self::json($merged), $record, $mutation);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $addition
     * @return array<string, mixed>
     */
    private static function mergeWithoutOverwrite(array $current, array $addition): array
    {
        foreach ($addition as $key => $value) {
            if ($key === '' || str_contains($key, "\0")) {
                throw new \InvalidArgumentException('JSON merge keys must be non-empty safe strings.');
            }

            if (!\array_key_exists($key, $current)) {
                $current[$key] = $value;
            } elseif (\is_array($current[$key]) && !array_is_list($current[$key]) && \is_array($value) && !array_is_list($value)) {
                $current[$key] = self::mergeWithoutOverwrite(self::objectValue($current[$key]), self::objectValue($value));
            } elseif ($current[$key] !== $value) {
                throw new \RuntimeException('JSON merge refuses to overwrite an existing application value.');
            }
        }

        ksort($current, SORT_STRING);

        return $current;
    }

    /** @return list<string> */
    private function readStringList(string $relative): array
    {
        $value = $this->readJson($relative, []);

        if (!array_is_list($value)) {
            throw new \RuntimeException('Configuration import registry must contain a JSON list.');
        }

        $result = [];

        foreach ($value as $entry) {
            if (!\is_string($entry) || !self::safePath($entry)) {
                throw new \RuntimeException('Configuration import registry contains an unsafe entry.');
            }

            $result[] = $entry;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function readJsonObject(string $relative): array
    {
        return self::objectValue($this->readJson($relative, []));
    }

    /**
     * @param array<array-key, mixed> $default
     * @return array<array-key, mixed>
     */
    private function readJson(string $relative, array $default): array
    {
        $target = $this->target($relative, false);

        if (!file_exists($target)) {
            return $default;
        }

        if (is_link($target) || !is_file($target)) {
            throw new \RuntimeException('JSON recipe target is not a regular file.');
        }

        $contents = file_get_contents($target);

        try {
            $value = $contents === false ? null : json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \RuntimeException('JSON recipe target contains invalid JSON.', 0, $failure);
        }

        if (!\is_array($value)) {
            throw new \RuntimeException('JSON recipe target must contain an array or object.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function objectValue(mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('JSON merge value must be an object.');
        }

        $result = [];

        foreach ($value as $key => $entry) {
            if (!\is_string($key) || $key === '' || str_contains($key, "\0")) {
                throw new \InvalidArgumentException('JSON merge keys must be non-empty safe strings.');
            }

            $result[$key] = \is_array($entry) && !array_is_list($entry) ? self::objectValue($entry) : $entry;
        }

        return $result;
    }

    /** @param array<string, ?string> $changed */
    private function rollbackChanges(array $changed): void
    {
        foreach (array_reverse($changed, true) as $relative => $original) {
            $target = $this->target($relative, false);

            if ($original === null) {
                if (is_file($target) && !is_link($target)) {
                    unlink($target);
                }
            } else {
                self::atomicWrite($target, $original);
            }
        }
    }

    /** @param list<string> $directories */
    private function rollbackDirectories(array $directories): void
    {
        usort($directories, static fn(string $left, string $right): int => substr_count($right, '/') <=> substr_count($left, '/'));

        foreach ($directories as $relative) {
            $target = $this->target($relative, false);

            if (is_dir($target) && !is_link($target) && (scandir($target) ?: []) === ['.', '..']) {
                rmdir($target);
            }
        }
    }

    /** @return array{schemaVersion: '1.0', recipeVersion: string, operations: list<RecipeOperation>} */
    private static function recipe(string $path): array
    {
        $contents = file_get_contents($path);

        try {
            $data = $contents === false ? null : json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \InvalidArgumentException('Recipe is not valid JSON.', 0, $failure);
        }

        if (!\is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['$schema', 'schemaVersion', 'recipeVersion', 'operations']) !== []
            || ($data['schemaVersion'] ?? null) !== '1.0' || !\is_string($data['recipeVersion'] ?? null)
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $data['recipeVersion']) !== 1
            || !\is_array($data['operations'] ?? null) || !array_is_list($data['operations'])) {
            throw new \InvalidArgumentException('Recipe schema is invalid or unsupported.');
        }

        $operations = [];

        foreach ($data['operations'] as $operation) {
            $operations[] = self::operation($operation);
        }

        return ['schemaVersion' => '1.0', 'recipeVersion' => $data['recipeVersion'], 'operations' => $operations];
    }

    /** @return RecipeOperation */
    private static function operation(mixed $operation): array
    {
        if (!\is_array($operation) || array_is_list($operation) || !\is_string($operation['type'] ?? null)) {
            throw new \InvalidArgumentException('Recipe operation is malformed.');
        }

        $type = $operation['type'];
        $fields = match ($type) {
            'copy' => ['type', 'source', 'target', 'mode'],
            'mkdir' => ['type', 'target'],
            'config-import' => ['type', 'file'],
            'env-document' => ['type', 'name', 'description'],
            'gitignore-entry' => ['type', 'entry'],
            'json-merge' => ['type', 'target', 'value'],
            default => throw new \InvalidArgumentException('Recipe operation type is unsupported.'),
        };

        $actual = array_keys($operation);
        sort($actual, SORT_STRING);
        sort($fields, SORT_STRING);

        if ($actual !== $fields) {
            throw new \InvalidArgumentException('Recipe operation contains missing or unknown fields.');
        }

        if ($type === 'copy') {
            $source = self::rawString($operation, 'source');
            $target = self::rawString($operation, 'target');

            if (($operation['mode'] ?? null) !== 'create' || !self::safePath($source) || !self::safePath($target)) {
                throw new \InvalidArgumentException('Copy recipe operation is malformed or unsafe.');
            }
        }

        if ($type === 'mkdir' && !self::safePath(self::rawString($operation, 'target'))) {
            throw new \InvalidArgumentException('Directory recipe operation is malformed or unsafe.');
        }

        foreach (['file', 'name', 'description', 'entry'] as $field) {
            if (\array_key_exists($field, $operation) && (!\is_string($operation[$field]) || $operation[$field] === '')) {
                throw new \InvalidArgumentException('Recipe operation string field is invalid.');
            }
        }

        if ($type === 'json-merge') {
            self::rawString($operation, 'target');
            self::objectValue($operation['value'] ?? null);
        }

        $normalized = ['type' => $type];

        foreach (['source', 'target', 'mode', 'file', 'name', 'description', 'entry'] as $field) {
            if (isset($operation[$field]) && \is_string($operation[$field])) {
                $normalized[$field] = $operation[$field];
            }
        }

        if (\array_key_exists('value', $operation)) {
            $normalized['value'] = self::objectValue($operation['value']);
        }

        return $normalized;
    }

    /** @param array<array-key, mixed> $operation */
    private static function rawString(array $operation, string $field): string
    {
        $value = $operation[$field] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Recipe operation string field is invalid.');
        }

        return $value;
    }

    /** @param RecipeOperation $operation */
    private static function stringField(array $operation, string $field): string
    {
        $value = $operation[$field] ?? null;

        if (!\is_string($value)) {
            throw new \LogicException('Validated recipe string field is unavailable.');
        }

        return $value;
    }

    /**
     * @param RecipeOperation $operation
     * @return array<string, mixed>
     */
    private static function objectField(array $operation, string $field): array
    {
        return self::objectValue($operation[$field] ?? null);
    }

    /** @return RecipeRecord */
    private static function emptyRecord(string $version): array
    {
        return ['recipeVersion' => $version, 'files' => [], 'originals' => [], 'directories' => []];
    }

    private static function source(string $root, string $relative): string
    {
        $resolvedRoot = realpath($root);
        $resolved = realpath($root . '/' . $relative);
        $prefix = $resolvedRoot === false ? '' : rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($resolved === false || $prefix === '' || !str_starts_with($resolved, $prefix) || !is_file($resolved) || is_link($root . '/' . $relative)) {
            throw new \RuntimeException('Recipe source escapes its package or is not a regular file.');
        }

        return $resolved;
    }

    private function createDirectory(string $relative): void
    {
        $this->target($relative . '/sentinel', true);
    }

    private function target(string $relative, bool $createParents): string
    {
        if (!self::safePath($relative)) {
            throw new \InvalidArgumentException('Recipe target path is unsafe.');
        }

        $parts = explode('/', $relative);
        array_pop($parts);
        $directory = $this->projectRoot;

        foreach ($parts as $part) {
            $directory .= '/' . $part;

            if (is_link($directory)) {
                throw new \RuntimeException('Recipe target path contains a symbolic link.');
            }

            if (!file_exists($directory) && $createParents && !mkdir($directory, 0o755)) {
                throw new \RuntimeException('Could not create recipe target directory.');
            }

            if (file_exists($directory) && !is_dir($directory)) {
                throw new \RuntimeException('Recipe target parent is not a directory.');
            }
        }

        return $this->projectRoot . '/' . $relative;
    }

    /** @return RecipeLock */
    private function readLock(): array
    {
        $path = $this->projectRoot . '/contempt.lock';

        if (!file_exists($path)) {
            return ['schemaVersion' => '1.0', 'recipes' => []];
        }

        $size = filesize($path);

        if (is_link($path) || !is_file($path) || $size === false || $size > self::MAX_LOCK_BYTES) {
            throw new \RuntimeException('contempt.lock must be a small regular file.');
        }

        $contents = file_get_contents($path);

        try {
            $data = $contents === false ? null : json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \RuntimeException('contempt.lock is invalid JSON.', 0, $failure);
        }

        if (!\is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['schemaVersion', 'recipes']) !== []
            || ($data['schemaVersion'] ?? null) !== '1.0' || !\is_array($data['recipes'] ?? null) || array_is_list($data['recipes'])) {
            throw new \RuntimeException('contempt.lock has an unsupported schema.');
        }

        $recipes = [];

        foreach ($data['recipes'] as $package => $recipe) {
            if (!\is_string($package) || !\is_array($recipe) || array_is_list($recipe)
                || array_diff(array_keys($recipe), ['recipeVersion', 'files', 'originals', 'directories']) !== []
                || !\is_string($recipe['recipeVersion'] ?? null) || !\is_array($recipe['files'] ?? null) || array_is_list($recipe['files'])) {
                throw new \RuntimeException('contempt.lock contains an invalid recipe record.');
            }

            self::package($package);
            $files = [];

            foreach ($recipe['files'] as $relative => $hash) {
                if (!\is_string($relative) || !self::safePath($relative) || !\is_string($hash) || preg_match('/^sha256:[0-9a-f]{64}$/D', $hash) !== 1) {
                    throw new \RuntimeException('contempt.lock contains an invalid owned-file record.');
                }

                $files[$relative] = $hash;
            }

            $recipes[$package] = [
                'recipeVersion' => $recipe['recipeVersion'],
                'files' => $files,
                'originals' => self::originals($recipe['originals'] ?? [], $files),
                'directories' => self::directories($recipe['directories'] ?? []),
            ];
        }

        return ['schemaVersion' => '1.0', 'recipes' => $recipes];
    }

    /**
     * @param array<string, string> $files
     * @return array<string, ?string>
     */
    private static function originals(mixed $value, array $files): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('contempt.lock contains invalid originals.');
        }

        $result = [];

        foreach ($value as $relative => $original) {
            if (!\is_string($relative) || !\array_key_exists($relative, $files) || (!\is_string($original) && $original !== null)
                || (\is_string($original) && base64_decode($original, true) === false)) {
                throw new \RuntimeException('contempt.lock contains invalid original file data.');
            }

            $result[$relative] = $original;
        }

        return $result;
    }

    /** @return list<string> */
    private static function directories(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('contempt.lock contains invalid directories.');
        }

        foreach ($value as $directory) {
            if (!\is_string($directory) || !self::safePath($directory)) {
                throw new \RuntimeException('contempt.lock contains an unsafe directory.');
            }
        }

        /** @var list<string> $value */
        return array_values(array_unique($value));
    }

    /** @param RecipeLock $lock */
    private function writeLock(array $lock): void
    {
        self::atomicWrite($this->projectRoot . '/contempt.lock', self::json($lock), 0o600);
    }

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @return TResult
     */
    private function withExclusiveLock(callable $operation): mixed
    {
        $path = $this->projectRoot . '/.contempt.recipe.lock';

        if (is_link($path)) {
            throw new \RuntimeException('Recipe coordination lock must not be a symbolic link.');
        }

        $handle = fopen($path, 'c+b');

        if ($handle === false || !chmod($path, 0o600) || !flock($handle, LOCK_EX)) {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            throw new \RuntimeException('Could not acquire the recipe coordination lock.');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function atomicWrite(string $target, string $contents, int $mode = 0o644): void
    {
        $temporary = \dirname($target) . '/.' . basename($target) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');

        if ($handle === false) {
            throw new \RuntimeException('Could not create a temporary recipe file.');
        }

        try {
            $written = fwrite($handle, $contents) === \strlen($contents) && fflush($handle);

            if (\function_exists('fsync')) {
                $written = $written && fsync($handle);
            }
        } finally {
            fclose($handle);
        }

        if (!$written || !chmod($temporary, $mode) || !rename($temporary, $target)) {
            if (file_exists($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException('Could not atomically write a recipe target.');
        }
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function fileHash(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new \RuntimeException('Could not hash recipe-owned file.');
        }

        return 'sha256:' . $hash;
    }

    private static function contentHash(string $contents): string
    {
        return 'sha256:' . hash('sha256', $contents);
    }

    private static function package(string $package): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/D', $package) !== 1) {
            throw new \InvalidArgumentException('Recipe package name is invalid.');
        }
    }

    private static function safePath(string $path): bool
    {
        return $path !== '' && preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))(?!.*//)[A-Za-z0-9._/-]+$#D', $path) === 1;
    }
}
