<?php

declare(strict_types=1);

namespace Contempt\Composer;

use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Contempt\Composer\Manifest\PackageManifest;
use Contempt\Composer\Recipe\RecipeInstaller;

final class ContemptPlugin implements PluginInterface, EventSubscriberInterface
{
    private ?Composer $composer = null;
    private ?IOInterface $io = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
        $this->io = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
        $this->io = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'synchronizeRecipes',
            ScriptEvents::POST_UPDATE_CMD => 'synchronizeRecipes',
        ];
    }

    public function synchronizeRecipes(Event $event): void
    {
        $composer = $this->composer ?? throw new \LogicException('Contempt Composer plugin is not active.');
        $io = $this->io ?? throw new \LogicException('Contempt Composer plugin has no IO channel.');
        $vendorDirectory = $composer->getConfig()->get('vendor-dir');

        if (!\is_string($vendorDirectory) || $vendorDirectory === '') {
            throw new \RuntimeException('Composer vendor-dir configuration is invalid.');
        }

        $installer = new RecipeInstaller(\dirname($vendorDirectory));
        $installed = [];

        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $name = $package->getName();
            $installPath = $composer->getInstallationManager()->getInstallPath($package);

            if ($installPath === null || !is_file($installPath . '/contempt.json')) {
                continue;
            }

            $contents = file_get_contents($installPath . '/contempt.json');

            if ($contents === false || \strlen($contents) > 1_048_576) {
                throw new \RuntimeException('Package contempt.json cannot be read safely.');
            }

            $manifest = PackageManifest::fromJson($contents);
            $installed[$name] = true;

            if ($manifest->recipe !== null) {
                $result = $installer->install($name, $installPath . '/' . $manifest->recipe);

                foreach ($result->created as $file) {
                    $io->writeError(\sprintf('<info>Contempt recipe created %s</info>', $file));
                }
            }
        }

        foreach ($installer->installedPackages() as $package) {
            if (isset($installed[$package])) {
                continue;
            }

            $result = $installer->uninstall($package);

            foreach ($result->retainedModified as $file) {
                $io->writeError(\sprintf('<warning>Contempt retained modified recipe file %s</warning>', $file));
            }
        }
    }
}
