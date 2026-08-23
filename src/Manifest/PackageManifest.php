<?php

declare(strict_types=1);

namespace Contempt\Composer\Manifest;

final readonly class PackageManifest
{
    /** @var list<string> */
    public array $capabilities;

    /** @var list<string> */
    public array $configuration;

    /**
     * @param list<string> $capabilities
     * @param list<string> $configuration
     */
    private function __construct(
        public ?string $extension,
        array $capabilities,
        array $configuration,
        public ?string $recipe,
    ) {
        $this->capabilities = $capabilities;
        $this->configuration = $configuration;
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \InvalidArgumentException('Package manifest is not valid JSON.', 0, $failure);
        }

        $data = self::object($decoded);

        $allowed = ['$schema', 'schemaVersion', 'extension', 'capabilities', 'configuration', 'recipe'];

        if (array_diff(array_keys($data), $allowed) !== [] || ($data['schemaVersion'] ?? null) !== '1.0') {
            throw new \InvalidArgumentException('Package manifest schema version or fields are unsupported.');
        }

        $extension = self::optionalClass($data, 'extension');
        $capabilities = self::stringList($data, 'capabilities', '/^[a-z][a-z0-9_.-]{0,127}$/D');
        $configuration = self::stringList($data, 'configuration', '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)*[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D');
        $recipe = $data['recipe'] ?? null;

        if ($recipe !== null && (!\is_string($recipe) || !self::safePath($recipe))) {
            throw new \InvalidArgumentException('Package recipe path must be safe and relative.');
        }

        return new self($extension, $capabilities, $configuration, $recipe);
    }

    /** @param array<string, mixed> $data */
    private static function optionalClass(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && (!\is_string($value) || preg_match('/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)*[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $value) !== 1)) {
            throw new \InvalidArgumentException('Package extension must be a class name.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key, string $pattern): array
    {
        $value = $data[$key] ?? [];

        if (!\is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Package manifest list field is invalid.');
        }

        foreach ($value as $item) {
            if (!\is_string($item) || preg_match($pattern, $item) !== 1) {
                throw new \InvalidArgumentException('Package manifest list item is invalid.');
            }
        }

        if (\count($value) !== \count(array_unique($value))) {
            throw new \InvalidArgumentException('Package manifest lists must not contain duplicates.');
        }

        sort($value, SORT_STRING);

        return $value;
    }

    private static function safePath(string $path): bool
    {
        return preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$#D', $path) === 1;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Package manifest root must be an object.');
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException('Package manifest object keys must be strings.');
            }

            $object[$key] = $item;
        }

        return $object;
    }
}
