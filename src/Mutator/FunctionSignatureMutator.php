<?php
/**
 * Copyright © 2017 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */
declare(strict_types=1);

namespace Infection\Mutator;

abstract class FunctionSignatureMutator extends Mutator
{
    public function isFunctionBodyMutator(): bool
    {
        return false;
    }

    public function isFunctionSignatureMutator(): bool
    {
        return true;
    }
}
