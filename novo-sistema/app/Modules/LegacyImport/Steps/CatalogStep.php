<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;

/**
 * categorias, servicos, combos, produtos.
 *
 * - valor TEXT -> centavos; slots x 30 -> minutos (minimo 1 slot, regra atual).
 * - combos.servicos_ids (CSV) -> package_items; servico repetido soma quantidade.
 * - produtos.quantidade NAO vira coluna: o saldo e reconstituido no razao
 *   de estoque (StockStep).
 */
final class CatalogStep extends Step
{
    public function name(): string
    {
        return 'Catalogo';
    }

    public function tables(): array
    {
        return ['categorias', 'servicos', 'combos', 'produtos'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('categorias') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('categorias', $sid, $row) !== 'new') {
                continue;
            }
            $id = $this->ctx->insert('service_categories', [
                'name' => $this->text('categorias', $row['nome']) ?? '(sem nome)',
                'sort_order' => V::int($row['ordem'] ?? null) ?? 0,
                ...$this->stamps(),
            ]);
            $this->ctx->remember('categorias', $sid, 'service_category', $id, $row);
        }

        foreach ($this->src->rows('servicos') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('servicos', $sid, $row) !== 'new') {
                continue;
            }
            $preco = $this->money('servicos', $sid, 'valor', $row['valor'] ?? null);
            if ($preco === null || $preco < 0) {
                $this->ctx->skip('servicos', $sid, C::Inconsistent, 'service_without_price',
                    'Servico sem preco valido: nao importado. Itens de agendamento que o usam ficam com valor desconhecido.', ['nome' => $row['nome'] ?? null, 'valor' => $row['valor'] ?? null]);

                continue;
            }
            $slots = V::int($row['slots'] ?? null);
            if ($slots === null || $slots < 1) {
                $this->ctx->issue('servicos', $sid, C::PotentiallyValid, S::Info, 'service_slots_defaulted',
                    'Duracao (slots) ausente ou invalida: aplicada a regra atual de 1 slot (30 min).', ['slots' => $row['slots'] ?? null]);
                $slots = 1;
            }
            $id = $this->ctx->insert('services', [
                'category_id' => $this->ctx->ref('categorias', $row['categoria_id'] ?? null),
                'name' => $this->text('servicos', $row['nome']) ?? '(sem nome)',
                'description' => $this->text('servicos', $row['descricao'] ?? null),
                'duration_minutes' => $slots * 30,
                'price_cents' => $preco,
                'is_active' => true,
                ...$this->stamps(),
            ]);
            $this->ctx->remember('servicos', $sid, 'service', $id, $row);
        }

        foreach ($this->src->rows('combos') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('combos', $sid, $row) !== 'new') {
                continue;
            }
            $preco = $this->money('combos', $sid, 'valor', $row['valor'] ?? null);
            if ($preco === null || $preco < 0) {
                $this->ctx->skip('combos', $sid, C::Inconsistent, 'package_without_price', 'Combo sem preco valido: nao importado.', ['nome' => $row['nome'] ?? null]);

                continue;
            }
            $id = $this->ctx->insert('packages', [
                'category_id' => $this->ctx->ref('categorias', $row['categoria_id'] ?? null),
                'name' => $this->text('combos', $row['nome']) ?? '(sem nome)',
                'price_cents' => $preco,
                'is_active' => true,
                ...$this->stamps(),
            ]);

            $quantidades = array_count_values(V::csv($row['servicos_ids'] ?? null));
            foreach ($quantidades as $servicoAntigo => $qtd) {
                $servico = $this->ctx->ref('servicos', (string) $servicoAntigo);
                if ($servico === null) {
                    $this->ctx->issue('combos', $sid, C::Orphan, S::Warning, 'package_unknown_service',
                        "Combo referencia servico inexistente {$servicoAntigo}: item nao incluido.", ['servico_id' => $servicoAntigo], true);

                    continue;
                }
                $this->ctx->insert('package_items', ['package_id' => $id, 'service_id' => $servico, 'quantity' => $qtd, ...$this->stamps()]);
            }
            $this->ctx->remember('combos', $sid, 'package', $id, $row);
        }

        foreach ($this->src->rows('produtos') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('produtos', $sid, $row) !== 'new') {
                continue;
            }
            $preco = $this->money('produtos', $sid, 'valor', $row['valor'] ?? null);
            if ($preco === null || $preco < 0) {
                $this->ctx->skip('produtos', $sid, C::Inconsistent, 'product_without_price', 'Produto sem preco valido: nao importado.', ['nome' => $row['nome'] ?? null]);

                continue;
            }
            $id = $this->ctx->insert('products', [
                'category_id' => $this->ctx->ref('categorias', $row['categoria_id'] ?? null),
                'name' => $this->text('produtos', $row['nome']) ?? '(sem nome)',
                'price_cents' => $preco,
                'cost_cents' => $this->money('produtos', $sid, 'custo', $row['custo'] ?? null),
                'min_stock' => V::int($row['estoque_minimo'] ?? null),
                'is_active' => true,
                ...$this->stamps(),
            ]);
            $this->ctx->remember('produtos', $sid, 'product', $id, $row);
        }
    }
}
