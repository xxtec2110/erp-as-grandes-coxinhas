<?php

namespace App\Services;

use App\Models\PdvConnection;
use Carbon\CarbonImmutable;
use DomainException;

class PdvOrderPreparationService
{
    public function __construct(
        private GrandChefSalesReportService $reports,
        private PdvOrderStagingService $staging,
    ) {}

    /** @return array{staged:int,reported_total:?int,pages:int} */
    public function prepare(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $report = $this->reports->report($connection, $from, $to);
        if (! $report['pagination']['complete']) {
            throw new DomainException('A consulta foi interrompida antes de receber todos os pedidos; nenhum staging foi gravado.');
        }

        foreach ($report['orders'] as $sale) {
            $this->staging->stage($connection, $sale);
        }

        return [
            'staged' => count($report['orders']),
            'reported_total' => $report['pagination']['reported_total'],
            'pages' => $report['pagination']['pages'],
        ];
    }
}
