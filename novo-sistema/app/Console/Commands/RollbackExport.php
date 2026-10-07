<?php

namespace App\Console\Commands;

use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plano de retorno (Fase 13; plano-retorno.md): exporta o que o sistema NOVO
 * gravou desde a virada, para a recepcao relancar no sistema antigo se a
 * virada for desfeita. So le o banco. Arquivos CSV (separador ";", UTF-8 com
 * BOM, abrem direto no Excel) numa pasta PRIVADA (nunca servida por URL).
 *
 *     php artisan app:rollback-export --since="2026-10-12 20:00"
 *
 * A hora e a da barbearia. CPF nao sai: quem precisar consulta o sistema
 * novo, que continua acessivel para a equipe.
 */
#[Signature('app:rollback-export {--since= : Início da virada (AAAA-MM-DD HH:MM, hora da barbearia)} {--dir= : Pasta de saída (padrão: storage/app/private/retorno/<data>)}')]
#[Description('Exporta para CSV o que foi gravado no sistema novo desde a virada (plano de retorno)')]
class RollbackExport extends Command
{
    public function handle(): int
    {
        try {
            $desde = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $this->option('since'), BusinessTime::zone());
        } catch (Throwable) {
            $desde = null;
        }
        if (! $desde instanceof CarbonImmutable) {
            $this->error('Informe --since="AAAA-MM-DD HH:MM" (hora da barbearia em que a virada começou).');

            return self::FAILURE;
        }
        $utc = $desde->utc()->format('Y-m-d H:i:s');
        $pasta = (string) ($this->option('dir') ?: storage_path('app/private/retorno/'.BusinessTime::now()->format('Ymd-His')));
        File::ensureDirectoryExists($pasta, 0700);

        $totais = [
            'agendamentos' => $this->appointments($pasta, $utc),
            'atendimentos' => $this->attendances($pasta, $utc),
            'pagamentos' => $this->payments($pasta, $utc),
            'caixa' => $this->cash($pasta, $utc),
            'clientes' => $this->customers($pasta, $utc),
            'assinaturas' => $this->subscriptions($pasta, $utc),
        ];

        $resumo = 'Exportado em '.BusinessTime::now()->format('d/m/Y H:i').' — gravações desde '.$desde->format('d/m/Y H:i').PHP_EOL;
        foreach ($totais as $nome => $n) {
            $resumo .= sprintf('%-14s %d%s', $nome, $n, PHP_EOL);
        }
        file_put_contents($pasta.DIRECTORY_SEPARATOR.'resumo.txt', $resumo);
        Log::warning('rollback.exported', ['desde' => $desde->toIso8601String()] + $totais);

        $this->info('Exportado em '.$pasta);
        $this->table(['Arquivo', 'Linhas'], array_map(fn ($n, $k) => [$k.'.csv', $n], $totais, array_keys($totais)));

        return self::SUCCESS;
    }

    private function appointments(string $pasta, string $desde): int
    {
        $linhas = DB::table('appointments')
            ->where(fn ($q) => $q->where('created_at', '>=', $desde)->orWhere('updated_at', '>=', $desde))
            ->orderBy('starts_at')->get();
        $itens = DB::table('appointment_items')->whereIn('appointment_id', $linhas->pluck('id'))->orderBy('id')->get()->groupBy('appointment_id');

        return $this->csv($pasta, 'agendamentos', ['Código', 'Situação', 'Data', 'Hora', 'Fim', 'Profissional', 'Cliente', 'Telefone', 'E-mail', 'Serviços', 'Total', 'Origem', 'Criado em', 'Novo desde a virada?', 'Cancelado em', 'Motivo do cancelamento', 'Observações'],
            $linhas->map(fn ($a) => [
                $a->code, AppointmentStatus::tryFrom((string) $a->status)?->label() ?? $a->status,
                $this->local($a->starts_at, 'd/m/Y'), $this->local($a->starts_at, 'H:i'), $this->local($a->ends_at, 'H:i'),
                $a->professional_name, $a->customer_name, $a->customer_phone, $a->customer_email,
                ($itens[$a->id] ?? collect())->map(fn ($i) => $i->name.($i->quantity > 1 ? ' x'.$i->quantity : ''))->implode(' + '),
                $this->money($a->total_cents), $a->source, $this->local($a->created_at, 'd/m/Y H:i'),
                $a->created_at >= $desde ? 'sim' : 'não (alterado)', $this->local($a->cancelled_at, 'd/m/Y H:i'), $a->cancellation_reason, $a->notes,
            ])->all());
    }

    private function attendances(string $pasta, string $desde): int
    {
        $linhas = DB::table('attendances')
            ->where(fn ($q) => $q->where('created_at', '>=', $desde)->orWhere('updated_at', '>=', $desde))
            ->orderBy('opened_at')->get();
        $itens = DB::table('attendance_items')->whereIn('attendance_id', $linhas->pluck('id'))->orderBy('id')->get()->groupBy('attendance_id');
        $pagos = DB::table('payments')->whereIn('attendance_id', $linhas->pluck('id'))->orderBy('id')->get()->groupBy('attendance_id');

        return $this->csv($pasta, 'atendimentos', ['Código', 'Situação', 'Aberto em', 'Concluído em', 'Profissional', 'Cliente', 'Telefone', 'Itens', 'Subtotal', 'Desconto', 'Total', 'Gorjeta', 'Pagamentos'],
            $linhas->map(fn ($a) => [
                $a->code, $a->status, $this->local($a->opened_at, 'd/m/Y H:i'), $this->local($a->completed_at, 'd/m/Y H:i'),
                $a->professional_name, $a->customer_name, $a->customer_phone,
                ($itens[$a->id] ?? collect())->map(fn ($i) => $i->name.' x'.$i->quantity.' '.$this->money($i->total_cents))->implode(' + '),
                $this->money($a->subtotal_cents), $this->money($a->discount_cents), $this->money($a->total_cents), $this->money($a->tip_cents),
                ($pagos[$a->id] ?? collect())->map(fn ($p) => $p->kind.' '.$p->method.' '.$this->money($p->amount_cents))->implode(' + '),
            ])->all());
    }

    private function payments(string $pasta, string $desde): int
    {
        $linhas = DB::table('payments')->leftJoin('attendances', 'attendances.id', '=', 'payments.attendance_id')
            ->where('payments.created_at', '>=', $desde)->orderBy('payments.paid_at')
            ->get(['payments.*', 'attendances.code as attendance_code', 'attendances.customer_name']);

        return $this->csv($pasta, 'pagamentos', ['Pago em', 'Tipo', 'Forma', 'Valor', 'Gorjeta', 'Atendimento', 'Cliente', 'Motivo'],
            $linhas->map(fn ($p) => [
                $this->local($p->paid_at, 'd/m/Y H:i'), $p->kind, $p->method, $this->money($p->amount_cents), $this->money($p->tip_cents),
                $p->attendance_code, $p->customer_name, $p->reason,
            ])->all());
    }

    private function cash(string $pasta, string $desde): int
    {
        $linhas = DB::table('cash_movements')->where('created_at', '>=', $desde)->orderBy('occurred_at')->get();

        return $this->csv($pasta, 'caixa', ['Quando', 'Tipo', 'Forma', 'Valor', 'Descrição'],
            $linhas->map(fn ($m) => [$this->local($m->occurred_at, 'd/m/Y H:i'), $m->type, $m->method, $this->money($m->amount_cents), $m->description])->all());
    }

    private function customers(string $pasta, string $desde): int
    {
        $linhas = DB::table('customers')
            ->where(fn ($q) => $q->where('created_at', '>=', $desde)->orWhere('updated_at', '>=', $desde))
            ->whereNull('anonymized_at')->orderBy('created_at')->get();

        return $this->csv($pasta, 'clientes', ['Nome', 'Telefone', 'E-mail', 'Nascimento', 'Situação', 'Novo desde a virada?', 'Criado em'],
            $linhas->map(fn ($c) => [
                $c->name, $c->phone, $c->email, $c->birth_date, $c->status,
                $c->created_at >= $desde ? 'sim' : 'não (alterado)', $this->local($c->created_at, 'd/m/Y H:i'),
            ])->all());
    }

    private function subscriptions(string $pasta, string $desde): int
    {
        $linhas = DB::table('subscriptions')->leftJoin('customers', 'customers.id', '=', 'subscriptions.customer_id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where(fn ($q) => $q->where('subscriptions.created_at', '>=', $desde)->orWhere('subscriptions.updated_at', '>=', $desde))
            ->orderBy('subscriptions.id')
            ->get(['subscriptions.*', 'customers.name as customer_name', 'plans.name as plan_name']);

        return $this->csv($pasta, 'assinaturas', ['Cliente', 'Plano', 'Situação', 'Início', 'Fim', 'Cobrança', 'Assinatura no Stripe', 'Cliente no Stripe', 'Cancelamento pedido em'],
            $linhas->map(fn ($s) => [
                $s->customer_name, $s->plan_name, $s->status, $s->starts_on, $s->ends_on, $s->gateway,
                $s->gateway_subscription_id, $s->gateway_customer_id, $this->local($s->cancel_requested_at, 'd/m/Y H:i'),
            ])->all());
    }

    /**
     * @param  list<string>  $cabecalho
     * @param  array<int, array<int, mixed>>  $linhas
     */
    private function csv(string $pasta, string $nome, array $cabecalho, array $linhas): int
    {
        $arquivo = $pasta.DIRECTORY_SEPARATOR.$nome.'.csv';
        $h = fopen($arquivo, 'wb');
        if ($h === false) {
            throw new \RuntimeException('Não foi possível gravar '.$arquivo);
        }
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $cabecalho, ';', '"', '');
        foreach ($linhas as $l) {
            // Celula que comeca com = + - @ vira texto (nao formula) no Excel.
            fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $l), ';', '"', '');
        }
        fclose($h);
        @chmod($arquivo, 0600);

        return count($linhas);
    }

    private function local(?string $utc, string $formato): string
    {
        return $utc === null || $utc === '' ? '' : CarbonImmutable::parse($utc, 'UTC')->setTimezone(BusinessTime::zone())->format($formato);
    }

    private function money(int|string|null $centavos): string
    {
        return $centavos === null ? '' : number_format(((int) $centavos) / 100, 2, ',', '.');
    }
}
