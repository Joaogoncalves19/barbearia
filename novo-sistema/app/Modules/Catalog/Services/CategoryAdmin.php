<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Support\Ordering;
use Illuminate\Support\Facades\DB;

/**
 * Administracao das categorias. Toda gravacao passa por aqui (os
 * controllers so validam e chamam). A trilha de auditoria sai da trait
 * Auditable do model (quem, quando, antes/depois).
 */
final class CategoryAdmin
{
    /**
     * @param  array{name: string, description: ?string}  $data
     */
    public function create(array $data): ServiceCategory
    {
        return DB::transaction(function () use ($data): ServiceCategory {
            $c = new ServiceCategory($data);
            $c->is_active = true;
            $c->sort_order = Ordering::next(ServiceCategory::query());
            $c->save();

            return $c;
        });
    }

    /**
     * @param  array{name: string, description: ?string}  $data
     */
    public function update(ServiceCategory $category, array $data, int $version): ServiceCategory
    {
        return DB::transaction(function () use ($category, $data, $version): ServiceCategory {
            $c = StaleRecord::guard($category, $version);
            $c->fill($data)->save();

            return $c;
        });
    }

    public function setActive(ServiceCategory $category, bool $active): void
    {
        $category->is_active = $active;
        $category->save();
    }

    public function move(ServiceCategory $category, string $direction): void
    {
        Ordering::move(ServiceCategory::query(), $category, $direction);
    }

    /** Pode ser excluida? So se nunca teve nada dentro (nem servico excluido). */
    public function canDelete(ServiceCategory $category): bool
    {
        return ! $category->services()->withTrashed()->exists()
            && ! Package::withTrashed()->where('category_id', $category->id)->exists()
            && ! Product::withTrashed()->where('category_id', $category->id)->exists();
    }

    public function delete(ServiceCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            if (! $this->canDelete($category)) {
                throw DomainRuleViolation::rule('R-CAT', 'Categoria com servicos (mesmo inativos) nao pode ser excluida: desative-a.');
            }
            $category->delete();
        });
    }
}
