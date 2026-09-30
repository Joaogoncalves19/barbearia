<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consultas de LEITURA do catalogo para a agenda (Fase 5), o site (Fase 11)
 * e as telas do painel. As regras de "agendavel" e "publico" vivem nos
 * escopos do model Service; aqui so se agrupa e ordena, num lugar so.
 */
final class ServiceCatalog
{
    /**
     * Servicos oferecidos para agendamentos NOVOS, agrupados por categoria.
     *
     * @return list<array{category: ?ServiceCategory, services: Collection<int, Service>}>
     */
    public function bookableByCategory(): array
    {
        return $this->groupByCategory(Service::query()->bookable()->ordered()->get());
    }

    /**
     * Catalogo do site: agendavel e publico.
     *
     * @return list<array{category: ?ServiceCategory, services: Collection<int, Service>}>
     */
    public function publicByCategory(): array
    {
        return $this->groupByCategory(Service::query()->shownPublicly()->ordered()->get());
    }

    /**
     * Agrupa servicos pela ordem das categorias (e, dentro de cada uma, pela
     * ordem dos servicos). Servicos sem categoria vao no fim. Categorias sem
     * nenhum servico na lista nao aparecem.
     *
     * @param  Collection<int, Service>  $services
     * @return list<array{category: ?ServiceCategory, services: Collection<int, Service>}>
     */
    public function groupByCategory(Collection $services): array
    {
        $porCategoria = $services->groupBy(fn (Service $s) => $s->category_id ?? 0);
        $grupos = [];

        foreach (ServiceCategory::withTrashed()->ordered()->get() as $categoria) {
            $lista = $porCategoria->get($categoria->id);
            if ($lista !== null && $lista->isNotEmpty()) {
                $grupos[] = ['category' => $categoria, 'services' => new Collection($lista->all())];
            }
        }

        $semCategoria = $porCategoria->get(0);
        if ($semCategoria !== null && $semCategoria->isNotEmpty()) {
            $grupos[] = ['category' => null, 'services' => new Collection($semCategoria->all())];
        }

        return $grupos;
    }
}
