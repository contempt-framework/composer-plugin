<?php

declare(strict_types=1);

namespace Contempt\Composer\Tests;

use Contempt\Composer\Manifest\PackageManifest;
use Contempt\Composer\Recipe\RecipeInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageManifest::class)]
#[CoversClass(RecipeInstaller::class)]
final class ManifestAndRecipeTest extends TestCase
{
    private string $project;
    private string $package;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/contempt-recipe-' . bin2hex(random_bytes(8));
        $this->project = $root . '/project';
        $this->package = $root . '/package';
        self::assertTrue(mkdir($this->project, 0o700, true));
        self::assertTrue(mkdir($this->package . '/recipe/config', 0o700, true));
    }

    protected function tearDown(): void
    {
        $this->remove(\dirname($this->project));
    }

    public function testManifestIsStrictVersionedAndRejectsTraversalAndUnknownFields(): void
    {
        $manifest = PackageManifest::fromJson(json_encode([
            'schemaVersion' => '1.0',
            'extension' => 'Vendor\\Package\\Extension',
            'frameworkApi' => '^1.0',
            'capabilities' => ['cache.backend', 'messaging.transport'],
            'configuration' => ['Vendor\\Package\\Configuration'],
            'recipe' => 'recipe/recipe.json',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('Vendor\\Package\\Extension', $manifest->extension);
        self::assertSame('^1.0', $manifest->frameworkApi);
        self::assertSame(['cache.backend', 'messaging.transport'], $manifest->capabilities);

        foreach ([
            ['schemaVersion' => '2.0'],
            ['schemaVersion' => '1.0', 'unknown' => true],
            ['schemaVersion' => '1.0', 'frameworkApi' => 'not-a-constraint'],
            ['schemaVersion' => '1.0', 'capabilities' => ['same', 'same']],
            ['schemaVersion' => '1.0', 'recipe' => '../outside.json'],
        ] as $invalid) {
            try {
                PackageManifest::fromJson(json_encode($invalid, JSON_THROW_ON_ERROR));
                self::fail('Invalid package manifest must fail closed.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testOfficialPackageManifestsParseIncludingFrameworkApi(): void
    {
        $manifests = glob(\dirname(__DIR__, 2) . '/*/contempt.json');
        self::assertIsArray($manifests);
        self::assertNotSame([], $manifests);

        foreach ($manifests as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents, $path);
            $manifest = PackageManifest::fromJson($contents);
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            if (isset($decoded['extension'])) {
                self::assertSame('^1.0', $manifest->frameworkApi, $path);
            }
        }
    }

    public function testCopyRecipeIsIdempotentAndUninstallKeepsModifiedFiles(): void
    {
        self::assertSame(17, file_put_contents($this->package . '/recipe/config/cache.php', "<?php\nreturn [];\n", LOCK_EX));
        $recipe = json_encode([
            'schemaVersion' => '1.0',
            'recipeVersion' => '1.0.0',
            'operations' => [[
                'type' => 'copy',
                'source' => 'config/cache.php',
                'target' => 'config/packages/cache.php',
                'mode' => 'create',
            ]],
        ], JSON_THROW_ON_ERROR);
        self::assertIsInt(file_put_contents($this->package . '/recipe/recipe.json', $recipe, LOCK_EX));
        $installer = new RecipeInstaller($this->project);

        $first = $installer->install('vendor/cache', $this->package . '/recipe/recipe.json');
        $second = $installer->install('vendor/cache', $this->package . '/recipe/recipe.json');

        self::assertSame(['config/packages/cache.php'], $first->created);
        self::assertSame([], $second->created);
        self::assertSame("<?php\nreturn [];\n", file_get_contents($this->project . '/config/packages/cache.php'));

        self::assertIsInt(file_put_contents($this->project . '/config/packages/cache.php', "<?php\nreturn ['changed'];\n", LOCK_EX));
        $result = $installer->uninstall('vendor/cache');
        self::assertSame(['config/packages/cache.php'], $result->retainedModified);
        self::assertFileExists($this->project . '/config/packages/cache.php');
    }

    public function testRecipeCannotEscapeRootsOrOverwriteAnExistingFile(): void
    {
        self::assertSame(4, file_put_contents($this->package . '/recipe/safe.txt', 'safe', LOCK_EX));
        self::assertTrue(mkdir($this->project . '/config', 0o700));
        self::assertSame(4, file_put_contents($this->project . '/config/existing.txt', 'user', LOCK_EX));

        foreach ([
            ['source' => '../safe.txt', 'target' => 'config/new.txt'],
            ['source' => 'safe.txt', 'target' => '../outside.txt'],
            ['source' => 'safe.txt', 'target' => 'config/existing.txt'],
        ] as $copy) {
            $recipe = json_encode([
                'schemaVersion' => '1.0',
                'recipeVersion' => '1.0.0',
                'operations' => [['type' => 'copy', ...$copy, 'mode' => 'create']],
            ], JSON_THROW_ON_ERROR);
            self::assertIsInt(file_put_contents($this->package . '/recipe/recipe.json', $recipe, LOCK_EX));

            try {
                new RecipeInstaller($this->project)->install('vendor/unsafe', $this->package . '/recipe/recipe.json');
                self::fail('Unsafe recipe operation must fail.');
            } catch (\RuntimeException|\InvalidArgumentException) {
            }
        }

        self::assertFileDoesNotExist(\dirname($this->project) . '/outside.txt');
        self::assertSame('user', file_get_contents($this->project . '/config/existing.txt'));
    }

    public function testEveryDeclarativeOperationIsInstalledAndReversiblyUninstalled(): void
    {
        self::assertTrue(mkdir($this->project . '/config', 0o700));
        self::assertIsInt(file_put_contents($this->project . '/.env.example', "APP_ENV=production\n", LOCK_EX));
        self::assertIsInt(file_put_contents($this->project . '/.gitignore', "/vendor/\n.DS_Store\n", LOCK_EX));
        self::assertIsInt(file_put_contents($this->project . '/config/tool.json', "{\n    \"existing\": {\"keep\": true}\n}\n", LOCK_EX));

        $recipe = json_encode([
            'schemaVersion' => '1.0',
            'recipeVersion' => '2.1.0',
            'operations' => [
                ['type' => 'mkdir', 'target' => 'var/messages'],
                ['type' => 'config-import', 'file' => 'config/packages/rabbitmq.php'],
                ['type' => 'env-document', 'name' => 'RABBITMQ_DSN', 'description' => 'RabbitMQ connection DSN'],
                ['type' => 'gitignore-entry', 'entry' => '/var/messages/'],
                ['type' => 'json-merge', 'target' => 'config/tool.json', 'value' => ['rabbitmq' => ['enabled' => true]]],
            ],
        ], JSON_THROW_ON_ERROR);
        self::assertIsInt(file_put_contents($this->package . '/recipe/recipe.json', $recipe, LOCK_EX));
        $installer = new RecipeInstaller($this->project);

        $result = $installer->install('vendor/rabbitmq', $this->package . '/recipe/recipe.json');

        self::assertDirectoryExists($this->project . '/var/messages');
        self::assertStringContainsString('config/packages/rabbitmq.php', (string) file_get_contents($this->project . '/config/contempt.imports.json'));
        self::assertStringContainsString("# RabbitMQ connection DSN\nRABBITMQ_DSN=", (string) file_get_contents($this->project . '/.env.example'));
        self::assertStringContainsString('/var/messages/', (string) file_get_contents($this->project . '/.gitignore'));
        self::assertSame(
            ['existing' => ['keep' => true], 'rabbitmq' => ['enabled' => true]],
            json_decode((string) file_get_contents($this->project . '/config/tool.json'), true, 32, JSON_THROW_ON_ERROR),
        );
        self::assertNotEmpty($result->created);
        self::assertSame([], $installer->install('vendor/rabbitmq', $this->package . '/recipe/recipe.json')->created);

        $uninstalled = $installer->uninstall('vendor/rabbitmq');

        self::assertSame([], $uninstalled->retainedModified);
        self::assertDirectoryDoesNotExist($this->project . '/var/messages');
        self::assertFileDoesNotExist($this->project . '/config/contempt.imports.json');
        self::assertSame("APP_ENV=production\n", file_get_contents($this->project . '/.env.example'));
        self::assertSame("/vendor/\n.DS_Store\n", file_get_contents($this->project . '/.gitignore'));
        self::assertSame("{\n    \"existing\": {\"keep\": true}\n}\n", file_get_contents($this->project . '/config/tool.json'));
    }

    public function testSharedFileChangesAreRetainedWhenApplicationModifiedThem(): void
    {
        $recipe = json_encode([
            'schemaVersion' => '1.0',
            'recipeVersion' => '1.0.0',
            'operations' => [['type' => 'gitignore-entry', 'entry' => '/generated/']],
        ], JSON_THROW_ON_ERROR);
        self::assertIsInt(file_put_contents($this->package . '/recipe/recipe.json', $recipe, LOCK_EX));
        $installer = new RecipeInstaller($this->project);
        $installer->install('vendor/tool', $this->package . '/recipe/recipe.json');
        self::assertIsInt(file_put_contents($this->project . '/.gitignore', "/generated/\n/user-change\n", LOCK_EX));

        $result = $installer->uninstall('vendor/tool');

        self::assertSame(['.gitignore'], $result->retainedModified);
        self::assertSame("/generated/\n/user-change\n", file_get_contents($this->project . '/.gitignore'));
    }

    public function testJsonMergeCannotOverwriteUserValuesOrTargetSensitiveFiles(): void
    {
        self::assertTrue(mkdir($this->project . '/config', 0o700));
        self::assertIsInt(file_put_contents($this->project . '/config/app.json', "{\"feature\":{\"on\":false}}", LOCK_EX));

        foreach ([
            ['type' => 'json-merge', 'target' => 'config/app.json', 'value' => ['feature' => ['on' => true]]],
            ['type' => 'json-merge', 'target' => 'composer.json', 'value' => ['scripts' => ['unsafe']]],
        ] as $operation) {
            $recipe = json_encode([
                'schemaVersion' => '1.0',
                'recipeVersion' => '1.0.0',
                'operations' => [$operation],
            ], JSON_THROW_ON_ERROR);
            self::assertIsInt(file_put_contents($this->package . '/recipe/recipe.json', $recipe, LOCK_EX));

            try {
                new RecipeInstaller($this->project)->install('vendor/conflict', $this->package . '/recipe/recipe.json');
                self::fail('Unsafe or conflicting JSON merge must fail closed.');
            } catch (\InvalidArgumentException|\RuntimeException) {
            }
        }

        self::assertSame("{\"feature\":{\"on\":false}}", file_get_contents($this->project . '/config/app.json'));
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
