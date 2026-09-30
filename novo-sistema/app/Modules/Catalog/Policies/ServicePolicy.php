<?php

namespace App\Modules\Catalog\Policies;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceAdmin;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;

/**
 * Servicos. As capacidades vem da matriz (services.*); aqui ficam as regras
 * que dependem do registro. Cliente (outro guard): sempre negado.
 */
class ServicePolicy
{
    public function __construct(private readonly ServiceAdmin $admin) {}

    /** Excluir: quem pode desativar, e so se o servico nunca foi usado. */
    public function delete(User|Customer $actor, Service $service): bool
    {
        return $actor instanceof User
            && $actor->hasPermission('services.toggle')
            && $this->admin->canDelete($service);
    }
}
