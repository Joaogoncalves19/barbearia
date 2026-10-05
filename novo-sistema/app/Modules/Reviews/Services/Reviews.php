<?php

namespace App\Modules\Reviews\Services;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewRejected;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Models\ReviewReply;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Avaliacoes (avaliacoes.md). Base valida: atendimento CONCLUIDO, do proprio
 * cliente (cadastrado), com profissional, dentro do prazo (30 dias, D-49),
 * uma por atendimento (unico no banco). Entra "aguardando revisao" (D-48) e
 * so e publicada quando a equipe aprova. Moderacao (aprovar, recusar com
 * motivo, destacar, responder) sempre auditada.
 *
 * Comentario e resposta sao TEXTO: guardados como vieram (sem HTML
 * "limpo"), com caracteres de controle removidos e tamanho limitado, e
 * escapados em toda tela (Blade {{ }}; regressao S-02 do sistema antigo).
 */
final class Reviews
{
    public const WINDOW_DAYS = 30;

    public const MAX_COMMENT = 1000;

    /** Motivo pelo qual o atendimento nao pode ser avaliado agora, ou nulo. */
    public function ineligibilityReason(Attendance $at): ?string
    {
        if ($at->status !== AttendanceStatus::Completed || $at->completed_at === null) {
            return 'Só atendimento concluído pode ser avaliado.';
        }
        if ($at->customer_id === null) {
            return 'Atendimento sem cliente cadastrado.';
        }
        if ($at->professional_id === null) {
            return 'Atendimento sem profissional.';
        }
        if ($at->completed_at->lt(BusinessTime::now()->subDays(self::WINDOW_DAYS))) {
            return 'O prazo para avaliar ('.self::WINDOW_DAYS.' dias) terminou.';
        }
        if (Review::query()->where('attendance_id', $at->id)->exists()) {
            return 'Este atendimento já foi avaliado.';
        }

        return null;
    }

    /**
     * @throws ReviewRejected
     */
    public function submit(Customer $customer, Attendance $at, int $rating, ?string $comment): Review
    {
        if ($at->customer_id !== $customer->id) {
            throw new ReviewRejected('Este atendimento não é seu.');
        }
        $motivo = $this->ineligibilityReason($at);
        if ($motivo !== null) {
            throw new ReviewRejected($motivo);
        }
        if ($rating < 1 || $rating > 5) {
            throw new ReviewRejected('Escolha uma nota de 1 a 5.');
        }

        try {
            $r = Review::query()->create([
                'attendance_id' => $at->id,
                'appointment_id' => $at->appointment_id,
                'customer_id' => $customer->id,
                'professional_id' => $at->professional_id,
                'rating' => $rating,
                'comment' => self::cleanText($comment, self::MAX_COMMENT),
                'status' => ReviewStatus::Pending,
                'reviewed_at' => BusinessTime::now(),
            ]);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new ReviewRejected('Este atendimento já foi avaliado.');
            }
            throw $e;
        }
        AuditTrail::record('review.submitted', $r, null, 'Avaliação recebida (nota '.$rating.') do atendimento '.$at->code.'.', ['nota' => $rating, 'cliente_id' => $customer->id]);

        return $r;
    }

    /**
     * @throws ReviewRejected
     */
    public function moderate(Review $review, ReviewStatus $to, ?string $reason, User $actor): Review
    {
        $motivo = self::cleanText($reason, 255);
        if ($to === ReviewStatus::Pending) {
            throw new ReviewRejected('Escolha aprovar ou recusar.');
        }
        if ($to === ReviewStatus::Rejected && mb_strlen((string) $motivo) < 3) {
            throw new ReviewRejected('Informe o motivo da recusa.');
        }

        return DB::transaction(function () use ($review, $to, $motivo, $actor): Review {
            $r = Review::query()->lockForUpdate()->findOrFail($review->id);
            $antes = $r->status;
            if ($antes === $to) {
                throw new ReviewRejected('A avaliação já está '.mb_strtolower($to->label()).'.');
            }
            $r->forceFill([
                'status' => $to, 'moderated_by_user_id' => $actor->id, 'moderated_at' => BusinessTime::now(), 'moderation_reason' => $motivo,
                'is_featured' => $to === ReviewStatus::Approved && $r->is_featured,
            ])->save();
            AuditTrail::record($to === ReviewStatus::Approved ? 'review.approved' : 'review.rejected', $r, $actor,
                'Avaliação '.($to === ReviewStatus::Approved ? 'aprovada' : 'recusada').'.', ['antes' => $antes->value, 'motivo' => $motivo]);

            return $r;
        });
    }

    /**
     * @throws ReviewRejected
     */
    public function feature(Review $review, bool $featured, User $actor): Review
    {
        $review->refresh();
        if ($featured && $review->status !== ReviewStatus::Approved) {
            throw new ReviewRejected('Só avaliação publicada pode ser destacada.');
        }
        $review->forceFill(['is_featured' => $featured, 'featured_by_user_id' => $featured ? $actor->id : null])->save();
        AuditTrail::record($featured ? 'review.featured' : 'review.unfeatured', $review, $actor, $featured ? 'Avaliação destacada.' : 'Destaque retirado.');

        return $review;
    }

    /**
     * Resposta da barbearia (uma por avaliacao; responder de novo substitui,
     * com o texto anterior na auditoria).
     *
     * @throws ReviewRejected
     */
    public function reply(Review $review, string $body, User $actor): ReviewReply
    {
        $texto = self::cleanText($body, self::MAX_COMMENT);
        if ($texto === null) {
            throw new ReviewRejected('Escreva a resposta.');
        }

        return DB::transaction(function () use ($review, $texto, $actor): ReviewReply {
            // Situacao ATUAL (outra pessoa pode ter recusado agora ha pouco).
            if (Review::query()->whereKey($review->id)->first()?->status !== ReviewStatus::Approved) {
                throw new ReviewRejected('Só avaliação publicada pode ser respondida.');
            }
            $atual = ReviewReply::query()->where('review_id', $review->id)->lockForUpdate()->first();
            $antes = $atual?->body;
            $resp = $atual ?? new ReviewReply(['review_id' => $review->id]);
            $resp->forceFill(['body' => $texto, 'author_user_id' => $actor->id, 'replied_at' => BusinessTime::now()])->save();
            AuditTrail::record('review.replied', $review, $actor, 'Resposta à avaliação '.($antes === null ? 'publicada' : 'alterada').'.', ['antes' => $antes, 'depois' => $texto]);

            return $resp;
        });
    }

    /** Texto livre: sem caracteres de controle (mantem quebras de linha), limitado, nulo se vazio. */
    public static function cleanText(?string $text, int $max): ?string
    {
        $t = preg_replace('/[^\P{C}\n]/u', '', str_replace("\r\n", "\n", (string) $text)) ?? '';
        $t = trim($t);

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
