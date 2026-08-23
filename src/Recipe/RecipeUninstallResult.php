<?php

declare(strict_types=1);

namespace Contempt\Composer\Recipe;

final readonly class RecipeUninstallResult
{
    /**
     * @param list<string> $removed
     * @param list<string> $retainedModified
     */
    public function __construct(public array $removed, public array $retainedModified) {}
}
