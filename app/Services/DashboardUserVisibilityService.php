<?php

namespace App\Services;

use App\Models\AuthorizationAudit;
use App\Models\User;
use App\Models\UserDashboardWidget;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardUserVisibilityService
{
    public function __construct(
        private DashboardWidgetRegistry $registry,
        private DashboardVisibilityService $visibility,
        private AuthorizationService $authorization,
    ) {}

    public function authorizeManager(User $actor): void
    {
        $this->authorization->authorize($actor, 'dashboard.permissions.manage');
    }

    /** @return array<string, mixed> */
    public function inspect(User $target, User $actor): array
    {
        $this->authorizeManager($actor);

        return [
            'target_user_id' => $target->id,
            'target_user_name' => $target->name,
            'widgets' => $this->visibility->configuration($target)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function prepareAgentInput(string $tool, array $input, User $actor): array
    {
        $this->authorizeManager($actor);
        $target = isset($input['target_user_id'])
            ? User::query()->findOrFail((int) $input['target_user_id'])
            : $this->resolveUser((string) ($input['target_user_name'] ?? ''));

        $show = array_values(array_unique($input['show'] ?? []));
        $hide = array_values(array_unique($input['hide'] ?? []));

        if ($tool === 'dashboard.user_widgets.update') {
            if (($input['mode'] ?? null) === 'only') {
                $hide = array_values(array_diff($this->registry->keys(), $show));
            }
            if ($show === [] && $hide === []) {
                throw new DomainException('Informe ao menos um widget para adicionar ou remover.');
            }
            $this->validateKeys([...$show, ...$hide]);
            $configuration = $this->visibility->configuration($target)->keyBy('key');
            foreach ($show as $key) {
                if (! $configuration->get($key)['available']) {
                    $missing = implode(', ', $configuration->get($key)['missing_permissions']);
                    throw new DomainException("{$target->name} não possui permissão funcional para exibir {$configuration->get($key)['name']} ({$missing}). Nenhuma permissão geral foi alterada.");
                }
            }
        }

        return [
            ...$input,
            'target_user_id' => $target->id,
            'target_user_name' => $target->name,
            'show' => $show,
            'hide' => $hide,
        ];
    }

    /** @param array<string, string> $states */
    public function update(User $target, array $states, User $actor, string $source = 'web', array $context = [], ?string $idempotencyKey = null): AuthorizationAudit
    {
        $this->authorizeManager($actor);
        $this->ensureConfigurable($target);
        $this->validateKeys(array_keys($states));

        foreach ($states as $key => $state) {
            if (! in_array($state, ['inherit', UserDashboardWidget::SHOW, UserDashboardWidget::HIDE], true)) {
                throw new DomainException("Estado inválido para o widget {$key}.");
            }
        }

        return DB::transaction(function () use ($target, $states, $actor, $source, $context, $idempotencyKey): AuthorizationAudit {
            if ($idempotencyKey !== null && ($existing = AuthorizationAudit::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }

            $before = $this->snapshot($target);
            $configuration = $this->visibility->configuration($target)->keyBy('key');

            foreach ($states as $key => $state) {
                if ($state === UserDashboardWidget::SHOW && ! $configuration->get($key)['available']) {
                    throw new DomainException("{$target->name} não possui as permissões funcionais necessárias para {$configuration->get($key)['name']}.");
                }
                if ($state === 'inherit') {
                    UserDashboardWidget::query()->where('user_id', $target->id)->where('widget_key', $key)->delete();
                } else {
                    UserDashboardWidget::query()->updateOrCreate(
                        ['user_id' => $target->id, 'widget_key' => $key],
                        ['visibility' => $state, 'created_by' => $actor->id],
                    );
                }
            }

            $target->unsetRelation('dashboardWidgets');
            $after = $this->snapshot($target);
            $explicitlyShown = collect($after['preferences'])->filter(fn (string $state, string $key) => $state === UserDashboardWidget::SHOW && ($before['preferences'][$key] ?? 'inherit') !== UserDashboardWidget::SHOW)->keys()->all();
            $explicitlyHidden = collect($after['preferences'])->filter(fn (string $state, string $key) => $state === UserDashboardWidget::HIDE && ($before['preferences'][$key] ?? 'inherit') !== UserDashboardWidget::HIDE)->keys()->all();
            $added = array_values(array_unique([...array_diff($after['visible'], $before['visible']), ...$explicitlyShown]));
            $removed = array_values(array_unique([...array_diff($before['visible'], $after['visible']), ...$explicitlyHidden]));

            return AuthorizationAudit::query()->create([
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'change_type' => 'dashboard_visibility_updated',
                'subject' => 'dashboard_widgets',
                'previous_value' => $before,
                'new_value' => [...$after, 'added' => $added, 'removed' => $removed],
                'source' => $source,
                'context' => $context,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function updateFromAgent(array $input, User $actor, array $context = []): AuthorizationAudit
    {
        $prepared = $this->prepareAgentInput('dashboard.user_widgets.update', $input, $actor);
        $states = array_fill_keys($prepared['show'], UserDashboardWidget::SHOW)
            + array_fill_keys($prepared['hide'], UserDashboardWidget::HIDE);

        return $this->update(
            User::query()->findOrFail($prepared['target_user_id']),
            $states,
            $actor,
            'agent',
            $context,
            $prepared['idempotency_key'] ?? null,
        );
    }

    public function reset(User $target, User $actor, string $source = 'web', array $context = [], ?string $idempotencyKey = null): AuthorizationAudit
    {
        $this->authorizeManager($actor);
        $this->ensureConfigurable($target);

        return DB::transaction(function () use ($target, $actor, $source, $context, $idempotencyKey): AuthorizationAudit {
            if ($idempotencyKey !== null && ($existing = AuthorizationAudit::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $before = $this->snapshot($target);
            UserDashboardWidget::query()->where('user_id', $target->id)->delete();
            $target->unsetRelation('dashboardWidgets');
            $after = $this->snapshot($target);

            return AuthorizationAudit::query()->create([
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'change_type' => 'dashboard_visibility_reset',
                'subject' => 'dashboard_widgets',
                'previous_value' => $before,
                'new_value' => [...$after, 'added' => array_values(array_diff($after['visible'], $before['visible'])), 'removed' => array_values(array_diff($before['visible'], $after['visible']))],
                'source' => $source,
                'context' => $context,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function preview(string $tool, array $payload): string
    {
        $target = User::query()->findOrFail($payload['target_user_id']);
        if ($tool === 'dashboard.user_widgets.reset') {
            return "ALTERAR DASHBOARD DE:\n{$target->name}\n\nRESTAURAR:\n✓ Padrão herdado\n\nNenhuma outra permissão será alterada.\n\nConfirmar?";
        }

        $show = collect($payload['show'] ?? [])->map(fn (string $key) => '✓ '.$this->registry->get($key)['name']);
        $hide = collect($payload['hide'] ?? [])->map(fn (string $key) => '✕ '.$this->registry->get($key)['name']);
        $lines = ["ALTERAR DASHBOARD DE:\n{$target->name}"];
        if ($show->isNotEmpty()) {
            $lines[] = "ADICIONAR:\n".$show->implode("\n");
        }
        if ($hide->isNotEmpty()) {
            $lines[] = "REMOVER:\n".$hide->implode("\n");
        }
        $lines[] = 'Nenhuma outra permissão será alterada.';
        $lines[] = 'Confirmar?';

        return implode("\n\n", $lines);
    }

    public function describe(array $result): string
    {
        $visible = collect($result['widgets'])->where('effective_visible', true)->pluck('name');
        $names = $visible->isEmpty() ? 'nenhum widget' : $visible->implode(', ');

        return "Widgets visíveis para {$result['target_user_name']}: {$names}.";
    }

    /** @return array<string, mixed> */
    private function snapshot(User $target): array
    {
        $configuration = $this->visibility->configuration($target);

        return [
            'preferences' => $configuration->mapWithKeys(fn (array $widget) => [$widget['key'] => $widget['preference']])->all(),
            'visible' => $configuration->where('effective_visible', true)->pluck('key')->values()->all(),
        ];
    }

    private function ensureConfigurable(User $target): void
    {
        if ($target->is_super_admin) {
            throw new DomainException('O dashboard do administrador master sempre exibe todos os widgets disponíveis e não admite restrições individuais.');
        }
    }

    /** @param array<int, string> $keys */
    private function validateKeys(array $keys): void
    {
        $unknown = array_values(array_diff(array_unique($keys), $this->registry->keys()));
        if ($unknown !== []) {
            throw new DomainException('Widget desconhecido: '.implode(', ', $unknown).'.');
        }
    }

    private function resolveUser(string $name): User
    {
        $needle = Str::lower(Str::ascii(trim($name)));
        if ($needle === '') {
            throw new DomainException('Informe o usuário cujo dashboard será consultado ou alterado.');
        }
        $matches = User::query()->where('active', true)->orderBy('name')->get()->filter(function (User $user) use ($needle): bool {
            $candidate = Str::lower(Str::ascii($user->name));

            return $candidate === $needle || str_starts_with($candidate, $needle.' ') || collect(explode(' ', $candidate))->contains($needle);
        })->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            throw new DomainException("Não encontrei usuário ativo compatível com {$name}.");
        }
        $options = $matches->map(fn (User $user, int $index) => ($index + 1).'. '.$user->name)->implode('; ');
        throw new DomainException("Encontrei mais de um usuário compatível: {$options}. Informe o nome completo.");
    }
}
