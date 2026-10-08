<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\Cpf;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Busca de cliente no balcao (agendar, abrir atendimento): so para quem pode
 * ver clientes; por nome, e-mail ou telefone; so cadastros ativos.
 *
 * browse() e a lista da tela Clientes do painel (Fase 13, P13-01): a mesma
 * busca, paginada e com filtro de situacao. CPF so entra na busca para quem
 * pode ver o CPF completo (senao a busca revelaria o numero).
 */
final class CustomerLookup
{
    /** Filtros da lista do painel (valor da URL => rotulo). */
    public const SITUATIONS = ['ativos' => 'Ativos', 'inativos' => 'Inativos', 'removidos' => 'Anonimizados', 'todos' => 'Todos'];

    /**
     * @return Collection<int, Customer>
     */
    public function search(User $user, string $term): Collection
    {
        if (mb_strlen($term) < 2 || ! $user->can('customers.view')) {
            return collect();
        }

        $digitos = preg_replace('/\D+/', '', $term);

        return Customer::query()
            ->where('status', 'active')->whereNull('merged_into_customer_id')->whereNull('anonymized_at')
            ->where(function ($q) use ($term, $digitos) {
                $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.mb_strtolower($term).'%');
                if ($digitos !== null && strlen($digitos) >= 4) {
                    $q->orWhere('phone', 'like', '%'.$digitos.'%');
                }
            })
            ->orderBy('name')->limit(10)->get();
    }

    /**
     * Cadastros mesclados em outro nao aparecem (o historico esta no destino).
     *
     * @return LengthAwarePaginator<int, Customer>
     */
    public function browse(User $user, string $term, string $situation = 'ativos', int $perPage = 25): LengthAwarePaginator
    {
        $consulta = Customer::query()->select('customers.*')->whereNull('merged_into_customer_id')
            ->addSelect(['last_attendance_at' => DB::table('attendances')->selectRaw('max(completed_at)')
                ->whereColumn('attendances.customer_id', 'customers.id')->where('status', 'completed')])
            ->withCasts(['last_attendance_at' => 'datetime']);

        match ($situation) {
            'inativos' => $consulta->where('status', 'inactive')->whereNull('anonymized_at'),
            'removidos' => $consulta->whereNotNull('anonymized_at'),
            'todos' => $consulta,
            default => $consulta->where('status', 'active')->whereNull('anonymized_at'),
        };

        $termo = trim($term);
        if (mb_strlen($termo) >= 2) {
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';
            $cpf = $user->can('customers.view_cpf') ? Cpf::normalize($digitos) : null;
            $consulta->where(function (Builder $q) use ($termo, $digitos, $cpf) {
                $q->where('name', 'like', '%'.$termo.'%')->orWhere('email', 'like', '%'.mb_strtolower($termo).'%');
                if (strlen($digitos) >= 4) {
                    $q->orWhere('phone', 'like', '%'.$digitos.'%');
                }
                if ($cpf !== null) {
                    $q->orWhere('cpf', $cpf);
                }
            });
        }

        return $consulta->orderBy('name')->orderBy('id')->paginate($perPage)->withQueryString();
    }
}
