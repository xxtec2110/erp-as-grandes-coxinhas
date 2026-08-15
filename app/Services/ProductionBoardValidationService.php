<?php

namespace App\Services;

use App\Models\AgentAttachment;
use App\Models\ProductionUserPolicy;
use App\Models\UserExternalIdentity;
use App\Production\ProductionBoardInterpretation;
use App\Production\ProductionBoardValidationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class ProductionBoardValidationService
{
    public const INVALID_PRODUCT_MESSAGE = "⚠️ NÃO CONSEGUI VALIDAR A PRODUÇÃO\n\nNão encontrei corretamente um dos sabores do quadro.\n\nNenhuma informação foi registrada.\n\nConfira o quadro e envie uma nova foto completa.";

    public const INVALID_DATE_MESSAGE = "⚠️ NÃO CONSEGUI VALIDAR A PRODUÇÃO\n\nA data do quadro não corresponde ao dia operacional atual.\n\nNenhuma informação foi registrada.\n\nConfira o quadro e envie uma nova foto completa.";

    public const INVALID_QUANTITY_MESSAGE = "⚠️ NÃO CONSEGUI VALIDAR A PRODUÇÃO\n\nUma das quantidades está ausente, repetida ou não está legível.\n\nNenhuma informação foi registrada.\n\nConfira o quadro e envie uma nova foto completa.";

    public function __construct(
        private ProductMatchService $products,
        private AuthorizationService $authorization,
    ) {}

    public function validate(
        ProductionBoardInterpretation $board,
        ProductionUserPolicy $policy,
        AgentAttachment $attachment,
        UserExternalIdentity $identity,
        ?CarbonImmutable $now = null,
    ): ProductionBoardInterpretation {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $user = $identity->user;

        if ($user === null || ! $user->active || ! $identity->active || $identity->status !== 'approved'
            || $identity->user_id !== $policy->user_id || ! $policy->active || ! $policy->restricted) {
            throw new ProductionBoardValidationException('identity_not_authorized', self::INVALID_PRODUCT_MESSAGE);
        }
        if ($attachment->created_by !== $user->id || $attachment->location_id !== $policy->location_id
            || ! str_starts_with((string) $attachment->mime_type, 'image/')
            || blank($attachment->disk) || blank($attachment->path)
            || ! Storage::disk($attachment->disk)->exists($attachment->path)) {
            throw new ProductionBoardValidationException('attachment_not_authorized', self::INVALID_PRODUCT_MESSAGE);
        }
        if ($policy->location === null || ! $policy->location->active || $policy->location->type !== 'production') {
            throw new ProductionBoardValidationException('location_not_authorized', self::INVALID_PRODUCT_MESSAGE);
        }
        foreach (['agent.image.use', 'agent.write.use', 'production.orders.create', 'production.orders.complete'] as $permission) {
            if (! $this->authorization->allows($user, $permission, $policy->location_id)) {
                throw new ProductionBoardValidationException('permission_denied', self::INVALID_PRODUCT_MESSAGE);
            }
        }

        if ($board->operationDate === null || ! $board->operationDate->isSameDay($now)) {
            throw new ProductionBoardValidationException('invalid_operation_date', self::INVALID_DATE_MESSAGE);
        }
        if ($now->format('H:i:s') > $policy->cutoff_time) {
            throw new ProductionBoardValidationException(
                'cutoff_reached',
                'O prazo da produção encerrou. Somente um administrador pode registrar retroativamente.',
            );
        }

        if ($this->hasDateError($board->errors)) {
            throw new ProductionBoardValidationException('invalid_visual_date', self::INVALID_DATE_MESSAGE);
        }
        if ($board->errors !== [] || ! $board->complete || $board->items === []) {
            throw new ProductionBoardValidationException('invalid_quantity_or_completeness', self::INVALID_QUANTITY_MESSAGE);
        }

        $resolved = $this->products->resolveExactItems($board->items);
        $statuses = collect($resolved)->pluck('_product_match.status');
        if ($statuses->contains(fn (?string $status) => $status !== 'resolved')) {
            $reason = match (true) {
                $statuses->contains('inactive') => 'inactive_product',
                $statuses->contains('ambiguous') => 'ambiguous_product',
                default => 'unknown_product',
            };

            throw new ProductionBoardValidationException($reason, self::INVALID_PRODUCT_MESSAGE);
        }

        $productIds = collect($resolved)->pluck('product_id');
        if ($productIds->duplicates()->isNotEmpty()) {
            throw new ProductionBoardValidationException('duplicate_product', self::INVALID_QUANTITY_MESSAGE);
        }

        return $board->withItems(array_map(function (array $item): array {
            unset($item['_product_match']);

            return $item;
        }, $resolved));
    }

    private function hasDateError(array $errors): bool
    {
        return collect($errors)->contains(
            fn (string $error) => str_starts_with($error, 'date_') || $error === 'board_incomplete'
        );
    }
}
