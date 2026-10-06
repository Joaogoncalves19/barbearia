<?php

namespace App\Modules\Team\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Shared\Support\Ordering;
use App\Modules\System\Services\AuditTrail;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Administracao da equipe profissional: a unica porta de gravacao.
 *
 * - Profissional != usuario: a conta de acesso e OPCIONAL e so vincula uma
 *   conta ja existente (criada em Usuarios). Nunca cria login sozinho.
 * - Desligar = desativar. Nada e apagado; agendamentos antigos guardam o
 *   proprio snapshot do nome e continuam apontando para o registro.
 * - Servicos executados: professional_service. So servico ATIVO pode ser
 *   vinculado; vinculos com servicos inativos sao preservados (voltam a
 *   valer se o servico for reativado).
 */
final class ProfessionalAdmin
{
    /**
     * @param  array<string, mixed>  $data  campos ja validados
     */
    public function create(array $data, ?UploadedFile $photo = null): Professional
    {
        return DB::transaction(function () use ($data, $photo): Professional {
            $p = new Professional($data);
            $this->assertUserAvailable($p->user_id, null);
            $p->sort_order = Ordering::next(Professional::query());
            if ($photo !== null) {
                $p->photo_path = ImageStore::store($photo, 'professionals', 'portrait')->path;
            }
            $p->save();

            return $p;
        });
    }

    /**
     * @param  array<string, mixed>  $data  so os campos que a pessoa pode alterar
     */
    public function update(Professional $professional, array $data, int $version, ?UploadedFile $photo = null, bool $removePhoto = false): Professional
    {
        $antiga = null;

        $p = DB::transaction(function () use ($professional, $data, $version, $photo, $removePhoto, &$antiga): Professional {
            $p = StaleRecord::guard($professional, $version);
            $p->fill($data);
            $this->assertUserAvailable($p->user_id, $p->id);

            if ($photo !== null) {
                $antiga = $p->photo_path;
                $p->photo_path = ImageStore::store($photo, 'professionals', 'portrait')->path;
            } elseif ($removePhoto) {
                $antiga = $p->photo_path;
                $p->photo_path = null;
            }

            $p->save();

            return $p;
        });

        ImageStore::delete($antiga);

        return $p;
    }

    public function setActive(Professional $professional, bool $active): void
    {
        $professional->is_active = $active;
        $professional->save();
    }

    public function move(Professional $professional, string $direction): void
    {
        Ordering::move(Professional::query(), $professional, $direction);
    }

    /**
     * Define os servicos que o profissional executa.
     *
     * Numa transacao, com o profissional bloqueado: dois administradores
     * salvando ao mesmo tempo nao deixam o vinculo pela metade, e um servico
     * desativado no meio do caminho e recusado (nada e gravado).
     *
     * @param  list<int>  $serviceIds  servicos ATIVOS escolhidos
     * @return array{added: list<int>, removed: list<int>}
     */
    public function syncServices(Professional $professional, array $serviceIds, User $actor): array
    {
        return DB::transaction(function () use ($professional, $serviceIds, $actor): array {
            $p = Professional::query()->whereKey($professional->id)->lockForUpdate()->firstOrFail();
            $escolhidos = array_values(array_unique(array_map('intval', $serviceIds)));

            $ativos = Service::query()->whereIn('id', $escolhidos)->where('is_active', true)->lockForUpdate()->pluck('id')->all();
            if (count($ativos) !== count($escolhidos)) {
                throw DomainRuleViolation::rule('R-VINCULO', 'So servicos ativos podem ser vinculados a um profissional.');
            }

            $atuais = $p->services()->withTrashed()->pluck('services.id')->map(fn ($id) => (int) $id)->all();
            $inativosPreservados = Service::withTrashed()->whereIn('id', $atuais)
                ->where(fn ($q) => $q->where('is_active', false)->orWhereNotNull('deleted_at'))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            $final = array_values(array_unique(array_merge($ativos, $inativosPreservados)));
            $mudou = $p->services()->sync($final);

            $adicionados = array_map('intval', $mudou['attached']);
            $removidos = array_map('intval', $mudou['detached']);

            if ($adicionados !== [] || $removidos !== []) {
                AuditTrail::record('professional.services_changed', $p, $actor, 'Serviços executados alterados.', [
                    'adicionados' => implode(',', $adicionados) ?: null,
                    'removidos' => implode(',', $removidos) ?: null,
                ]);
            }

            return ['added' => $adicionados, 'removed' => $removidos];
        });
    }

    /** A conta de acesso so pode estar ligada a um profissional. */
    private function assertUserAvailable(?int $userId, ?int $professionalId): void
    {
        if ($userId === null) {
            return;
        }

        $ocupada = Professional::withTrashed()->where('user_id', $userId)
            ->when($professionalId, fn ($q) => $q->whereKeyNot($professionalId))
            ->exists();

        if ($ocupada || ! User::query()->whereKey($userId)->exists()) {
            throw DomainRuleViolation::rule('R-VINCULO', 'Esta conta de acesso ja esta ligada a outro profissional.');
        }
    }
}
