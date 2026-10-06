<?php

namespace App\Modules\Team\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consultas de LEITURA sobre catalogo x equipe, prontas para a agenda (Fase 5)
 * e o site (Fase 11). E a unica resposta para "quem pode fazer este servico
 * num agendamento novo?": nenhuma tela ou JavaScript repete essa regra.
 */
final class ProfessionalDirectory
{
    /**
     * Profissionais que podem receber um agendamento NOVO deste servico:
     * profissional ativo e agendavel, vinculado ao servico, e servico
     * agendavel (ativo, categoria ativa).
     *
     * @return Collection<int, Professional>
     */
    public function bookableFor(Service $service): Collection
    {
        if (! Service::query()->whereKey($service->id)->bookable()->exists()) {
            return new Collection;
        }

        return Professional::query()
            ->bookable()
            ->whereHas('services', fn ($q) => $q->whereKey($service->id))
            ->ordered()
            ->get();
    }

    /**
     * O mesmo, pelo canal do CLIENTE (site e conta): so servico e
     * profissional publicados no site (Fase 11; P11-03 vale tambem para a
     * remarcacao pela conta). A Availability recusa o resto de qualquer jeito;
     * esta lista so evita oferecer o que seria recusado.
     *
     * @return Collection<int, Professional>
     */
    public function customerBookableFor(Service $service): Collection
    {
        if (! $service->is_public) {
            return new Collection;
        }

        return $this->bookableFor($service)->filter(fn (Professional $p) => $p->is_public)->values();
    }

    /**
     * Servicos que o profissional pode executar num agendamento NOVO.
     *
     * @return Collection<int, Service>
     */
    public function bookableServicesOf(Professional $professional): Collection
    {
        if (! $professional->isBookable()) {
            return new Collection;
        }

        return $professional->services()->bookable()->ordered()->get();
    }

    /**
     * Equipe do site (Fase 11, decisao do dono): ativos, que recebem
     * agendamentos e marcados para aparecer no site, na ordem definida.
     *
     * @return Collection<int, Professional>
     */
    public function publicTeam(): Collection
    {
        return Professional::query()->shownPublicly()->bookable()->ordered()->get();
    }
}
