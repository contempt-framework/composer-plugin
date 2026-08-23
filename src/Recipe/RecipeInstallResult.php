<?php

declare(strict_types=1);

namespace Contempt\Composer\Recipe;

final readonly class RecipeInstallResult
{
    /** @param list<string> $created */
    public function __construct(public array $created) {}
}
