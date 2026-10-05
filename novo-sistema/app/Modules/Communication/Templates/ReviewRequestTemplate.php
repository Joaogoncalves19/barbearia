<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Reviews\Services\Reviews;

/** Pedido de avaliacao (D-49): so enquanto o atendimento puder ser avaliado. */
final class ReviewRequestTemplate extends BaseTemplate
{
    public function key(): string
    {
        return 'review_request';
    }

    public function label(): string
    {
        return 'Pedido de avaliação';
    }

    public function render(EmailMessage $m): RenderedEmail|string
    {
        $at = Attendance::query()->find((int) $m->param('attendance_id'));
        if ($at === null) {
            return 'Atendimento não existe mais.';
        }
        $motivo = app(Reviews::class)->ineligibilityReason($at);
        if ($motivo !== null) {
            return $motivo;
        }

        return $this->message('Como foi o seu atendimento?', 'Como foi, '.$this->firstName($at->customer_name).'?', [
            'Conte como foi o seu atendimento com '.($at->professional_name ?? 'a gente').'. Leva menos de um minuto e ajuda muito.',
        ], ['Atendimento' => (string) $at->code, 'Profissional' => (string) ($at->professional_name ?? '—')],
            ['label' => 'Avaliar o atendimento', 'url' => route('account.reviews.create', $at)],
            ['Você pode avaliar até '.Reviews::WINDOW_DAYS.' dias depois do atendimento. A avaliação é publicada depois de revisada pela barbearia.']);
    }

    public function preview(): RenderedEmail
    {
        return $this->message('Como foi o seu atendimento?', 'Como foi, Maria?', ['Conte como foi o seu atendimento com João (exemplo). Leva menos de um minuto e ajuda muito.'],
            ['Atendimento' => 'AT-EXEMPLO', 'Profissional' => 'João (exemplo)'], ['label' => 'Avaliar o atendimento', 'url' => url('/minha-conta')],
            ['Você pode avaliar até '.Reviews::WINDOW_DAYS.' dias depois do atendimento.']);
    }
}
