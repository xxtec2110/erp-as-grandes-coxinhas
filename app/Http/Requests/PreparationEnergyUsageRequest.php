<?php

namespace App\Http\Requests;

use App\Models\EquipmentBurner;
use App\Models\ProductionEquipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreparationEnergyUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'production_equipment_id' => ['required', 'integer', 'exists:production_equipment,id'],
            'equipment_burner_id' => ['nullable', 'integer', 'exists:equipment_burners,id'],
            'glp_product_id' => ['required', 'integer', 'exists:glp_products,id'],
            'usage_time_minutes' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'utilization_factor' => ['required', 'numeric', 'gt:0', 'lte:1', 'decimal:0,3'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('production_equipment_id')) {
                return;
            }

            $equipment = ProductionEquipment::query()->find($this->integer('production_equipment_id'));

            if ($equipment?->energy_source !== 'glp') {
                $validator->errors()->add('production_equipment_id', 'Selecione um equipamento que utilize GLP.');

                return;
            }

            if ($this->filled('equipment_burner_id')) {
                $burner = EquipmentBurner::query()->find($this->integer('equipment_burner_id'));

                if ($burner !== null && $burner->production_equipment_id !== $equipment->id) {
                    $validator->errors()->add('equipment_burner_id', 'O queimador não pertence ao equipamento selecionado.');
                }

                return;
            }

            if ($equipment->nominal_glp_consumption_kg_hour === null) {
                $validator->errors()->add(
                    'equipment_burner_id',
                    'Selecione um queimador ou cadastre o consumo nominal do equipamento.',
                );
            }
        }];
    }
}
