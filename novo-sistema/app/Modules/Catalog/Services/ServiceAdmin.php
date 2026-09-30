<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Shared\Support\Ordering;
use App\Modules\System\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Administracao dos servicos: a unica porta de gravacao do catalogo.
 *
 * Preco: alterar price_cents muda so o preco ATUAL (agendamentos novos).
 * Agendamentos existentes guardam o proprio preco em appointment_items e
 * nunca sao recalculados. A alteracao fica na auditoria (antes/depois, quem,
 * quando), que e tambem o historico de precos (precos.md).
 */
final class ServiceAdmin
{
    /**
     * @param  array<string, mixed>  $data  campos ja validados
     */
    public function create(array $data, ?UploadedFile $image = null): Service
    {
        return DB::transaction(function () use ($data, $image): Service {
            $s = new Service($data);
            $s->sort_order = Ordering::next(Service::query()->where('category_id', $data['category_id'] ?? null));
            if ($image !== null) {
                $s->image_path = ImageStore::replace($image, 'services', null);
            }
            $s->save();

            return $s;
        });
    }

    /**
     * Grava a edicao se ninguem alterou o servico desde que o formulario foi
     * aberto (StaleRecord, nada gravado). Mudou de categoria: vai para o fim
     * da nova categoria.
     *
     * @param  array<string, mixed>  $data  so os campos que a pessoa pode alterar
     */
    public function update(Service $service, array $data, int $version, ?UploadedFile $image = null, bool $removeImage = false): Service
    {
        $antigo = null;

        $s = DB::transaction(function () use ($service, $data, $version, $image, $removeImage, &$antigo): Service {
            $s = StaleRecord::guard($service, $version);
            $categoriaAntes = $s->category_id;
            $s->fill($data);

            if ($s->category_id !== $categoriaAntes) {
                $s->sort_order = Ordering::next(Service::query()->where('category_id', $s->category_id)->whereKeyNot($s->id));
            }
            if ($image !== null) {
                $antigo = $s->image_path;
                $s->image_path = $image->store('services', ImageStore::disk()) ?: null;
            } elseif ($removeImage) {
                $antigo = $s->image_path;
                $s->image_path = null;
            }

            $s->save();

            return $s;
        });

        // So apaga o arquivo antigo depois de gravar (falha no banco nao
        // deixa o servico apontando para uma imagem apagada).
        ImageStore::delete($antigo);

        return $s;
    }

    public function setActive(Service $service, bool $active): void
    {
        $service->is_active = $active;
        $service->save();
    }

    public function move(Service $service, string $direction): void
    {
        Ordering::move(Service::query()->where('category_id', $service->category_id), $service, $direction);
    }

    /**
     * Exclusao so para servico que NUNCA foi usado (nem em agendamento, nem
     * em combo, nem em plano). Usado = desativar, nunca excluir.
     */
    public function canDelete(Service $service): bool
    {
        return ! DB::table('appointment_items')->where('service_id', $service->id)->exists()
            && ! DB::table('package_items')->where('service_id', $service->id)->exists()
            && ! DB::table('plan_services')->where('service_id', $service->id)->exists();
    }

    public function delete(Service $service): void
    {
        DB::transaction(function () use ($service): void {
            if (! $this->canDelete($service)) {
                throw DomainRuleViolation::rule('R-CAT', 'Servico ja usado nao pode ser excluido: desative-o.');
            }
            // Tira dos profissionais (o vinculo nao tem historico proprio).
            $service->professionals()->detach();
            $service->delete();
        });
    }

    /**
     * Historico de alteracoes de preco, da trilha de auditoria.
     *
     * @return Collection<int, array{at: Carbon|null, by: ?string, from: ?int, to: int}>
     */
    public function priceHistory(Service $service): Collection
    {
        return AuditLog::query()
            ->where('auditable_type', 'Service')
            ->where('auditable_id', $service->id)
            ->whereIn('action', ['created', 'updated'])
            ->latest('id')
            ->get()
            ->filter(fn (AuditLog $l) => array_key_exists('price_cents', $l->new_values ?? []))
            ->map(fn (AuditLog $l) => [
                'at' => $l->created_at,
                'by' => $l->actor_label,
                'from' => isset($l->old_values['price_cents']) ? (int) $l->old_values['price_cents'] : null,
                'to' => (int) $l->new_values['price_cents'],
            ])
            ->values();
    }
}
