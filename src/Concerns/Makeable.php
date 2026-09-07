<?php

namespace OneMediaLabs\MixpostMcp\Concerns;

trait Makeable
{
    public static function make(...$arguments): static
    {
        return new static(...$arguments);
    }
}
