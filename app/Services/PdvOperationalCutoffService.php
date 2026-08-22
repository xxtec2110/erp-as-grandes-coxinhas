<?php

namespace App\Services;

use App\Models\PdvConnection;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class PdvOperationalCutoffService
{
    /** @return array{operational_start_at:?string,order_completed_at:?string,is_after_operational_start:?bool,importable_by_cutoff:bool,classification:string,blocker:?array{code:string,message:string}} */
    public function assess(PdvConnection $connection, ?DateTimeInterface $completedAt): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $operationalStart = $connection->operational_start_at === null
            ? null
            : CarbonImmutable::instance($connection->operational_start_at)->setTimezone($timezone);
        $completed = $completedAt === null
            ? null
            : CarbonImmutable::instance($completedAt)->setTimezone($timezone);

        if ($operationalStart === null) {
            return $this->result(
                null,
                $completed,
                null,
                false,
                'operational_start_pending',
                'operational_start_not_set',
                'O marco oficial de início desta conexão ainda não foi definido.',
            );
        }

        if ($completed === null) {
            return $this->result(
                $operationalStart,
                null,
                null,
                false,
                'operation_date_missing',
                'operation_date_missing',
                'O pedido não possui data de conclusão para comparar com o início oficial.',
            );
        }

        if ($completed->lessThan($operationalStart)) {
            return $this->result(
                $operationalStart,
                $completed,
                false,
                false,
                'pre_operational',
                'before_operational_start',
                'Pedido histórico concluído antes do início oficial; permanece visível, mas nunca será importável.',
            );
        }

        return $this->result($operationalStart, $completed, true, true, 'operational');
    }

    /** @return array{operational_start_at:?string,order_completed_at:?string,is_after_operational_start:?bool,importable_by_cutoff:bool,classification:string,blocker:?array{code:string,message:string}} */
    private function result(
        ?CarbonImmutable $operationalStart,
        ?CarbonImmutable $completed,
        ?bool $isAfter,
        bool $importable,
        string $classification,
        ?string $blockerCode = null,
        ?string $blockerMessage = null,
    ): array {
        return [
            'operational_start_at' => $operationalStart?->toIso8601String(),
            'order_completed_at' => $completed?->toIso8601String(),
            'is_after_operational_start' => $isAfter,
            'importable_by_cutoff' => $importable,
            'classification' => $classification,
            'blocker' => $blockerCode === null ? null : ['code' => $blockerCode, 'message' => (string) $blockerMessage],
        ];
    }
}
