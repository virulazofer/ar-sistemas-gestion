<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientCodeService
{
    public function format(?int $code): string
    {
        if ($code === null || $code <= 0) {
            return '—';
        }

        return sprintf('%04d', $code);
    }

    public function label(Client $client): string
    {
        return $this->format($client->code).' — '.$client->name;
    }

    public function allocateNext(): int
    {
        return (int) DB::transaction(function () {
            $next = (int) Setting::getValue('clients.next_code', 0);
            if ($next <= 0) {
                $max = (int) Client::query()->max('code');
                $next = $max > 0 ? $max + 1 : 1;
            }

            Setting::setValue('clients.next_code', $next + 1, 'int');

            return $next;
        });
    }

    public function assertEditable(?int $currentCode, mixed $incoming, bool $canEditCode): ?int
    {
        if ($incoming === null || $incoming === '') {
            return $currentCode;
        }

        $code = (int) $incoming;
        if ($code <= 0) {
            throw new InvalidArgumentException('El código de cliente debe ser un entero positivo.');
        }

        if ($currentCode !== null && $code !== $currentCode && ! $canEditCode) {
            throw new InvalidArgumentException('El código de cliente es inmutable salvo permiso administrativo.');
        }

        return $code;
    }
}
