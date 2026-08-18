<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationalLocationService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Location
    {
        if ($this->equivalent([$data['name']]) !== null) {
            throw ValidationException::withMessages([
                'name' => 'Já existe uma unidade com nome equivalente.',
            ]);
        }

        return Location::query()->create($data);
    }

    /** @return array{ibira: Location, factory: Location} */
    public function ensureRequiredLocations(): array
    {
        return [
            'ibira' => $this->ensure(
                ['Unidade Ibirá', 'Ibirá', 'Ibira', 'Termas de Ibirá', 'Unidade Termas de Ibirá'],
                ['name' => 'Unidade Ibirá', 'type' => Location::TYPE_STORE, 'active' => true],
            ),
            'factory' => $this->ensure(
                ['Fábrica Central', 'Fábrica', 'Fabrica', 'Fábrica Ibirá', 'Fabrica Ibira', 'Produção', 'Producao', 'Produção Central', 'Producao Central', 'Cozinha Central'],
                ['name' => 'Fábrica Central', 'type' => Location::TYPE_PRODUCTION, 'active' => true],
            ),
        ];
    }

    /** @param array<int, string> $aliases
     * @param  array<string, mixed>  $data
     */
    private function ensure(array $aliases, array $data): Location
    {
        return $this->equivalent($aliases) ?? Location::query()->create($data);
    }

    /** @param array<int, string> $aliases */
    private function equivalent(array $aliases): ?Location
    {
        $normalized = collect($aliases)->map($this->normalize(...));

        return Location::query()->orderBy('id')->get()->first(
            fn (Location $location): bool => $normalized->contains($this->normalize($location->name)),
        );
    }

    private function normalize(string $name): string
    {
        return Str::squish(Str::lower(Str::ascii($name)));
    }
}
