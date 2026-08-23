<?php

declare(strict_types=1);

namespace Contempt\Composer\Recipe\Internal;

/** @internal */
final class RecipeMutation
{
    /** @var list<string> */
    public array $created = [];

    /** @var array<string, ?string> */
    public array $changed = [];
}
