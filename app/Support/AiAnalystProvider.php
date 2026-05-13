<?php

namespace App\Support;

interface AiAnalystProvider
{
    public function providerName(): string;

    public function generate(string $suggestionType, array $context): array;
}
