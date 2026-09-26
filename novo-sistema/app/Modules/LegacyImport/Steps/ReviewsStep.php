<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;

/**
 * avaliacoes, respostas_avaliacoes, avaliacoes_destacadas.
 * Uma avaliacao por agendamento: a segunda do mesmo agendamento e mantida,
 * mas sem o vinculo (pendencia). Avaliacao de agendamento que sumiu fica
 * ligada ao profissional.
 */
final class ReviewsStep extends Step
{
    public function name(): string
    {
        return 'Avaliacoes';
    }

    public function tables(): array
    {
        return ['avaliacoes', 'respostas_avaliacoes', 'avaliacoes_destacadas'];
    }

    protected function handle(): void
    {
        $destaques = [];
        foreach ($this->src->rows('avaliacoes_destacadas') as $d) {
            $destaques[(string) $d['id_avaliacao']] = true;
            $this->ctx->count('avaliacoes_destacadas', 'read');
        }

        foreach ($this->src->rows('avaliacoes') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('avaliacoes', $sid, $row) !== 'new') {
                continue;
            }
            $nota = V::int($row['rating'] ?? null);
            if ($nota === null || $nota < 1 || $nota > 5) {
                $this->ctx->skip('avaliacoes', $sid, C::Inconsistent, 'invalid_rating', 'Nota fora de 1 a 5.', ['rating' => $row['rating'] ?? null]);

                continue;
            }
            $agAntigo = V::text($row['agendamento_id'] ?? null);
            $ag = $this->ctx->ref('agendamentos', $agAntigo);
            if ($agAntigo !== null && $ag === null) {
                $this->ctx->issue('avaliacoes', $sid, C::Orphan, S::Info, 'review_orphan_appointment', 'Avaliacao de agendamento que nao existe mais: mantida sem o vinculo.', ['agendamento_id' => $agAntigo]);
            }
            if ($ag !== null && DB::table('reviews')->where('appointment_id', $ag)->exists()) {
                $this->ctx->issue('avaliacoes', $sid, C::Duplicate, S::Warning, 'duplicate_review', 'Segunda avaliacao do mesmo agendamento: mantida sem o vinculo ao agendamento.', ['agendamento_id' => $agAntigo], true);
                $ag = null;
            }
            $prof = V::text($row['barbeiro_id'] ?? null) !== null ? $this->professionalFor($row['barbeiro_id'], 'avaliacoes', $sid) : null;
            $id = $this->ctx->insert('reviews', [
                'appointment_id' => $ag,
                'customer_id' => $this->ctx->ref('clientes', $row['cliente_id'] ?? null),
                'professional_id' => $prof,
                'rating' => $nota,
                'comment' => $this->text('avaliacoes', $row['comment'] ?? null),
                'is_featured' => isset($destaques[$sid]),
                'reviewed_at' => $this->local($row['timestamp'] ?? null),
                ...$this->stamps(),
            ]);
            $this->ctx->remember('avaliacoes', $sid, 'review', $id, $row);
            if (isset($destaques[$sid])) {
                $this->ctx->count('avaliacoes_destacadas', 'imported');
            }
        }

        foreach ($this->src->rows('respostas_avaliacoes') as $row) {
            $sid = (string) $row['id_resposta'];
            if ($this->ctx->status('respostas_avaliacoes', $sid, $row) !== 'new') {
                continue;
            }
            $review = $this->ctx->ref('avaliacoes', $row['id_avaliacao'] ?? null);
            $texto = $this->text('respostas_avaliacoes', $row['texto_resposta'] ?? null);
            if ($review === null || $texto === null) {
                $this->ctx->skip('respostas_avaliacoes', $sid, $review === null ? C::Orphan : C::Inconsistent, 'invalid_reply', 'Resposta vazia ou de avaliacao inexistente.', ['id_avaliacao' => $row['id_avaliacao'] ?? null], false);

                continue;
            }
            $id = $this->ctx->insert('review_replies', ['review_id' => $review, 'body' => $texto, 'replied_at' => $this->local($row['timestamp'] ?? null), ...$this->stamps()]);
            $this->ctx->remember('respostas_avaliacoes', $sid, 'review_reply', $id, $row);
        }
    }
}
