<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;

/** Ajudas comuns dos modelos: corpo generico e resumo do agendamento. */
abstract class BaseTemplate implements EmailTemplate
{
    public function category(): MessageCategory
    {
        return MessageCategory::Transactional;
    }

    /**
     * @param  list<string>  $paragraphs
     * @param  array<string, string>  $details
     * @param  array{label: string, url: string}|null  $button
     * @param  list<string>  $notes
     * @param  array{text: string, label: string, url: string}|null  $secondary
     */
    protected function message(string $subject, string $heading, array $paragraphs, array $details = [], ?array $button = null, array $notes = [], ?string $unsubscribeUrl = null, ?array $secondary = null): RenderedEmail
    {
        return new RenderedEmail($subject, 'mail.communication.message', [
            'heading' => $heading, 'paragraphs' => $paragraphs, 'details' => $details, 'button' => $button, 'notes' => $notes, 'secondary' => $secondary,
        ], $unsubscribeUrl);
    }

    /**
     * @return array<string, string>
     */
    protected function appointmentDetails(Appointment $a): array
    {
        $quando = $a->starts_at !== null
            ? ucfirst(BusinessTime::local($a->starts_at)->locale('pt_BR')->translatedFormat('l, d/m/Y')).' às '.BusinessTime::formatLocal($a->starts_at, 'H:i')
            : '—';
        $itens = $a->items()->pluck('name')->join(', ');
        $d = ['Quando' => $quando, 'Profissional' => $a->professional_name ?? 'A definir', 'Serviços' => $itens !== '' ? $itens : '—'];
        if ($a->total_cents !== null) {
            $d['Valor'] = Money::fromCents((int) $a->total_cents)->format().((int) $a->discount_cents > 0 ? ' (com desconto)' : '');
        }
        $d['Código'] = (string) $a->code;

        return $d;
    }

    protected function firstName(?string $name): string
    {
        $primeiro = trim(explode(' ', trim((string) $name))[0]);

        return $primeiro !== '' ? $primeiro : 'cliente';
    }

    /**
     * Agendamento ficticio para pre-visualizacao (nao e gravado).
     *
     * @return array<string, string>
     */
    protected function fakeDetails(): array
    {
        return ['Quando' => 'Segunda-feira, 05/10/2026 às 10:00', 'Profissional' => 'João (exemplo)', 'Serviços' => 'Corte', 'Valor' => 'R$ 50,00', 'Código' => 'AG-EXEMPLO'];
    }
}
