<?php

namespace OneMediaLabs\MixpostMcp\Contracts;

use OneMediaLabs\MixpostMcp\Support\MediaConversionData;

interface MediaConversion
{
    public function getEngineName(): string;

    public function getPath(): string;

    public function canPerform(): bool;

    public function handle(): ?MediaConversionData;
}
