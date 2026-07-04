<?php

declare(strict_types=1);

namespace Duat\Tests\Support\Proxy;

use Attribute;

/**
 * Attribute foreign to Duat, proving the factory leaves it alone.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Audited
{
}
