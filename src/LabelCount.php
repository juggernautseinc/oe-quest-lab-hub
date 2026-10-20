<?php

namespace Juggernaut\Quest\Module;

final class LabelCount
{
    // At most ten 30-label sheets per request; normal encounter printing uses 3.
    public const MAX = 300;

    public static function parse(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,2}\z/D', $value)
            || (int) $value > self::MAX) {
            throw new \InvalidArgumentException('Label count must be an integer from 1 to 300.');
        }
        return (int) $value;
    }
}
