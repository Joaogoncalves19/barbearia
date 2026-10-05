<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Customers\Models\Customer;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Receipts\Services\Receipts;
use Illuminate\Support\Facades\URL;

/**
 * Campanha (campanhas.md): MARKETING. Texto escrito pela equipe como texto
 * simples (paragrafos separados por linha em branco), com marcadores
 * {primeiro_nome}, {nome_cliente}, {nome_barbearia}, {link_agendamento}.
 * Nunca vira HTML. Sempre com o link de descadastro (assinado) e o
 * cabecalho List-Unsubscribe. Campanha cancelada nao envia o que restou.
 */
final class CampaignTemplate extends BaseTemplate
{
    /** $test: envio de teste para a equipe (transacional, sem descadastro real). */
    public function __construct(private readonly bool $test = false) {}

    public function key(): string
    {
        return $this->test ? 'campaign_test' : 'campaign';
    }

    public function category(): MessageCategory
    {
        return $this->test ? MessageCategory::Transactional : MessageCategory::Marketing;
    }

    public function label(): string
    {
        return $this->test ? 'Campanha (teste para a equipe)' : 'Campanha';
    }

    public function render(EmailMessage $m): RenderedEmail|string
    {
        $c = Campaign::query()->find((int) $m->param('campaign_id'));
        if ($c === null || $c->cancelled_at !== null) {
            return 'Campanha cancelada.';
        }
        if ($this->test) {
            return $this->compose('[TESTE] '.$c->subject, (string) $c->body, $m->to_name, url('/descadastro/teste'));
        }
        $cliente = $m->customer_id !== null ? Customer::query()->find($m->customer_id) : null;
        if ($cliente === null) {
            return 'Cliente não existe mais.';
        }

        return $this->compose((string) $c->subject, (string) $c->body, $cliente->name, self::unsubscribeUrl($cliente));
    }

    public function compose(string $subject, string $body, ?string $customerName, string $unsubscribeUrl): RenderedEmail
    {
        $marcadores = [
            '{primeiro_nome}' => $this->firstName($customerName),
            '{nome_cliente}' => trim((string) $customerName) !== '' ? trim((string) $customerName) : 'cliente',
            '{nome_barbearia}' => app(Receipts::class)->business()['name'],
            '{link_agendamento}' => route('booking.services'),
        ];
        $texto = strtr(str_replace("\r\n", "\n", $body), $marcadores);
        $paragrafos = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $texto) ?: []), fn ($p) => $p !== ''));

        return $this->message(strtr($subject, $marcadores), strtr($subject, $marcadores), $paragrafos, [],
            ['label' => 'Agendar meu horário', 'url' => route('booking.services')], [], $unsubscribeUrl);
    }

    public static function unsubscribeUrl(Customer $customer): string
    {
        return URL::signedRoute('unsubscribe.show', ['customer' => $customer->public_id]);
    }

    public function preview(): RenderedEmail
    {
        return $this->compose('Novidade na {nome_barbearia}', "Oi, {primeiro_nome}!\n\nTexto da campanha (exemplo). Escreva parágrafos separados por uma linha em branco.", 'Maria Exemplo', url('/descadastro/exemplo'));
    }
}
