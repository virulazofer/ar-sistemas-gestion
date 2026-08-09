<?php

namespace App\DTO;

final class ExternalExchangeQuote
{
    public function __construct(
        public readonly string $rate,
        public readonly string $rateAt,
        public readonly string $provider,
        public readonly array $payload = [],
        public readonly string $rateType = 'official_sell',
        public readonly ?string $buyRate = null,
    ) {}
}
