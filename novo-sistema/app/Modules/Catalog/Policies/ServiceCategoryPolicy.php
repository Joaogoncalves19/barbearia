<?php

namespace App\Modules\Catalog\Policies;

use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\CategoryAdmin;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;

/** Categorias: excluir so vazia (nunca teve servico, combo ou produto). */
class ServiceCategoryPolicy
{
    public function __construct(private readonly CategoryAdmin $admin) {}

    public function delete(User|Customer $actor, ServiceCategory $category): bool
    {
        return $actor instanceof User
            && $actor->hasPermission('services.toggle')
            && $this->admin->canDelete($category);
    }
}
