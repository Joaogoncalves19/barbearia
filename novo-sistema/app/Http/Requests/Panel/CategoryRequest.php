<?php

namespace App\Http\Requests\Panel;

use App\Http\Requests\Panel\Concerns\CatalogRules;
use App\Modules\Catalog\Models\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;

/** Criar/editar categoria. Autorizacao na rota (can:services.create/update). */
class CategoryRequest extends FormRequest
{
    use CatalogRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $atual = $this->route('category');
        $id = $atual instanceof ServiceCategory ? $atual->id : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:80', $this->uniqueName('service_categories', 'name', $id, 'Já existe uma categoria com este nome.')],
            'description' => ['nullable', 'string', 'max:500'],
            'version' => $id === null ? ['prohibited'] : ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'description' => 'descrição'];
    }

    /**
     * @return array{name: string, description: ?string}
     */
    public function categoryData(): array
    {
        return [
            'name' => $this->string('name')->squish()->value(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->value() : null,
        ];
    }
}
