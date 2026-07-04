<?php

declare(strict_types=1);

namespace Duat\Tests\Support;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;

final class InvalidCacheKeyException extends InvalidArgumentException implements CacheInvalidArgumentException
{
}
