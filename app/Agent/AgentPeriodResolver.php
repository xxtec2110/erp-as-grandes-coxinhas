<?php

namespace App\Agent;

use Carbon\CarbonImmutable;
use DomainException;

class AgentPeriodResolver
{
    /** @return array{from: CarbonImmutable, to: CarbonImmutable, label: string} */
    public function resolve(array $input, string $default = 'today'): array
    {
        $timezone = (string) config('app.timezone', 'America/Sao_Paulo');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if (isset($input['from']) || isset($input['to'])) {
            if (! isset($input['from'], $input['to'])) {
                throw new DomainException('Informe as datas inicial e final no formato AAAA-MM-DD.');
            }

            $from = $this->date((string) $input['from'], $timezone);
            $to = $this->date((string) $input['to'], $timezone);
            if ($from->isAfter($to)) {
                throw new DomainException('A data inicial não pode ser posterior à data final.');
            }
            if ($from->diffInDays($to) > 366) {
                throw new DomainException('O período máximo de consulta é de 366 dias.');
            }

            return ['from' => $from, 'to' => $to, 'label' => $from->toDateString().' a '.$to->toDateString()];
        }

        return match ($input['period'] ?? $default) {
            'today' => ['from' => $today, 'to' => $today, 'label' => 'hoje'],
            'yesterday' => ['from' => $today->subDay(), 'to' => $today->subDay(), 'label' => 'ontem'],
            'week' => ['from' => $today->startOfWeek(), 'to' => $today->endOfWeek(), 'label' => 'esta semana'],
            'month' => ['from' => $today->startOfMonth(), 'to' => $today->endOfMonth(), 'label' => 'este mês'],
            default => throw new DomainException('Período inválido. Use today, yesterday, week, month ou informe from e to.'),
        };
    }

    private function date(string $value, string $timezone): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::parse($value, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw new DomainException('Data inválida. Use o formato AAAA-MM-DD.');
        }

        if ($date->toDateString() !== $value) {
            throw new DomainException('Data inválida. Use o formato AAAA-MM-DD.');
        }

        return $date;
    }
}
