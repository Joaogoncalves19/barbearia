<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Enums\NoteVisibility;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Finance\Services\Payouts;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Services\AppointmentPricing;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\Plans;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contas FICTICIAS para os testes de navegador (Playwright).
 *
 * - So roda em local/testing (recusa qualquer outro ambiente).
 * - A senha vem da variavel E2E_PASSWORD, gerada aleatoriamente pelo
 *   playwright.config.js a cada execucao: nao existe senha fixa no codigo.
 * - Idempotente: cada execucao recoloca as contas no estado inicial.
 * - Um conjunto de contas por "--suffix" (um por projeto do Playwright, que
 *   rodam em paralelo e trocam senhas).
 */
class E2eAccounts extends Command
{
    protected $signature = 'app:e2e-accounts {--suffix=* : Um conjunto de contas por sufixo}';

    protected $description = 'Cria/reinicia contas ficticias para os testes de navegador (so local/testing)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Somente em local/testing.');

            return self::FAILURE;
        }

        // Lida direto do processo (nao do .env): so existe durante o teste.
        $senha = (string) getenv('E2E_PASSWORD');
        if (strlen($senha) < 12) {
            $this->error('Defina E2E_PASSWORD (12+ caracteres, gerada pelo playwright.config.js).');

            return self::FAILURE;
        }

        DB::transaction(fn () => $this->agenda());

        foreach ($this->option('suffix') ?: ['local'] as $sufixo) {
            DB::transaction(fn () => $this->conjunto((string) $sufixo, $senha));
        }

        $this->info('Contas de teste prontas.');

        return self::SUCCESS;
    }

    private function conjunto(string $s, string $senha): void
    {
        $this->membro("e2e-dono-{$s}", 'Dono E2E', StaffRole::Owner, $senha);
        $this->membro("e2e-provisorio-{$s}", 'Recepção E2E', StaffRole::Reception, $senha, provisoria: true);
        // Catalogo e equipe (Fase 4): conta propria, para nao dividir o limite
        // de tentativas de login com os testes de acesso.
        $this->membro("e2e-gerente-{$s}", 'Gerente E2E', StaffRole::Manager, $senha);
        $a = $this->barbeiro("e2e-barbeiro-a-{$s}", "Barbeiro A {$s}", $senha);
        $b = $this->barbeiro("e2e-barbeiro-b-{$s}", "Barbeiro B {$s}", $senha);

        $ana = $this->cliente("e2e-cliente-{$s}@exemplo.test", 'Cliente E2E', $senha);
        $outra = $this->cliente("e2e-outra-{$s}@exemplo.test", 'Outra Cliente E2E', $senha);

        $this->agendamento("AG-E2E-A-{$s}", $ana, $a);
        $this->agendamento("AG-E2E-B-{$s}", $outra, $b);

        // Agenda (Fase 5): os dois barbeiros fazem o servico de teste.
        $corte = Service::query()->where('slug', 'corte-e2e')->firstOrFail();
        $a->services()->syncWithoutDetaching([$corte->id]);
        $b->services()->syncWithoutDetaching([$corte->id]);

        // Atendimento e caixa (Fase 6): recepcao sem senha provisoria, um
        // produto com estoque e um cliente agendado para HOJE (novo a cada
        // execucao: o de ontem ja foi atendido).
        $gerente = User::query()->where('username', "e2e-gerente-{$s}")->firstOrFail();
        $this->membro("e2e-recepcao-{$s}", 'Recepção Caixa E2E', StaffRole::Reception, $senha);
        // Conta propria (limite de tentativas de login separado do catalogo).
        $this->membro("e2e-operacao-{$s}", 'Gerente Operação E2E', StaffRole::Manager, $senha);
        $this->produto("Pomada E2E {$s}", $gerente);
        $this->chegadaDeHoje($s, $ana, $s === 'celular' ? $a : $b, $corte);

        // Correcao da Fase 6 (encaixe ocupa a agenda): um profissional com a
        // agenda ocupada AGORA, para o encaixe ser recusado pelo servidor a
        // qualquer hora em que o teste rode.
        $ocupado = $this->barbeiro("e2e-ocupado-{$s}", "Ocupado E2E {$s}", $senha);
        $ocupado->services()->syncWithoutDetaching([$corte->id]);
        $this->ocupadoAgora($s, $outra, $ocupado);

        // Comissao e repasse (Fase 7): um dono so para estes testes (limite de
        // login proprio) e um profissional com conta, sem regra e sem saldo.
        $donoComissao = $this->membro("e2e-comissao-{$s}", 'Dono Comissão E2E', StaffRole::Owner, $senha);
        $this->zerarComissao($this->barbeiro("e2e-comissao-pro-{$s}", "Comissão E2E {$s}", $senha), $donoComissao);

        // Promocoes e vale-presente (Fase 8): dono e cliente proprios (limite
        // de login separado). Os cupons e vales de cada execucao tem codigo
        // novo; nada precisa ser reiniciado.
        $this->membro("e2e-promo-{$s}", 'Dono Promoções E2E', StaffRole::Owner, $senha);
        $this->cliente("e2e-promo-cliente-{$s}@exemplo.test", 'Cliente Promoções E2E', $senha);

        // Assinaturas (Fase 9): dono proprio e um cliente com assinatura
        // MANUAL ativa (ficticia, sem Stripe) no plano que inclui o corte de teste.
        $donoAssina = $this->membro("e2e-assina-{$s}", 'Dono Assinaturas E2E', StaffRole::Owner, $senha);
        $assinante = $this->cliente("e2e-assina-cliente-{$s}@exemplo.test", 'Cliente Assinante E2E', $senha);
        $this->assinaturaAtiva($assinante, $corte, $donoAssina);

        // Comunicacao e avaliacoes (Fase 10): dono proprio (limite de login
        // separado) e um cliente com um atendimento concluido ontem, sem
        // avaliacao (novo a cada execucao), e preferencias de e-mail do zero
        // ("desconhecido" so volta para o estado inicial nesta conta ficticia).
        $this->membro("e2e-comunica-{$s}", 'Dono Comunicação E2E', StaffRole::Owner, $senha);
        $avaliador = $this->cliente("e2e-comunica-cliente-{$s}@exemplo.test", 'Cliente Avaliador E2E', $senha);
        $avaliador->forceFill(['marketing_email_consent' => MarketingConsent::Unknown, 'email_reminders_enabled' => true])->save();
        EmailSuppression::query()->where('email', $avaliador->email)->where('reason', 'marketing_opt_out')->delete();
        $this->atendimentoConcluidoOntem($s, $avaliador, $s === 'celular' ? $a : $b, $corte);

        // Area do cliente (Fase 12): cliente com horario daqui a 3 dias (com o
        // servico, para remarcar e cancelar) e um atendimento concluido ontem
        // (comprovante); outra cliente para tentar abrir o horario alheio; e uma
        // conta para exportar e excluir (a da execucao anterior foi anonimizada,
        // sem e-mail: o mesmo endereco vira uma conta nova).
        $area = $this->cliente("e2e-area-{$s}@exemplo.test", 'Cliente Área E2E', $senha);
        $this->cliente("e2e-area-outra-{$s}@exemplo.test", 'Outra Área E2E', $senha);
        // Profissional proprio: remarcar e cancelar aqui nao mexe na agenda dos testes da Fase 5.
        $proArea = $this->barbeiro("e2e-area-pro-{$s}", "Área E2E {$s}", $senha);
        $proArea->services()->syncWithoutDetaching([$corte->id]);
        $this->horarioFuturo($s, $area, $proArea, $corte);
        $this->atendimentoConcluidoOntem($s, $area, $proArea, $corte);
        $this->cliente("e2e-excluir-{$s}@exemplo.test", 'Cliente Exclusão E2E', $senha);

        // Redesign: dono proprio para a varredura visual de todas as telas
        // (limite de tentativas de login separado dos outros testes).
        $this->membro("e2e-visual-{$s}", 'Dono Visual E2E', StaffRole::Owner, $senha);

        // Area do profissional (Fase 12.5): dois profissionais com conta (para
        // o isolamento), um dono proprio so para abrir o caixa, um cliente de
        // cada um marcado HOJE e uma anotacao do cliente do A. O horario do B
        // tem codigo fixo: o A tenta abri-lo pela URL.
        $this->membro("e2e-pro-dono-{$s}", 'Dono Profissional E2E', StaffRole::Owner, $senha);
        $proA = $this->barbeiro("e2e-pro-a-{$s}", "Profissional A {$s}", $senha);
        $proB = $this->barbeiro("e2e-pro-b-{$s}", "Profissional B {$s}", $senha);
        $proA->services()->syncWithoutDetaching([$corte->id]);
        $proB->services()->syncWithoutDetaching([$corte->id]);
        $clienteA = $this->cliente("e2e-pro-cliente-a-{$s}@exemplo.test", "Cliente do A {$s}", $senha);
        $clienteB = $this->cliente("e2e-pro-cliente-b-{$s}@exemplo.test", "Cliente do B {$s}", $senha);
        $this->horarioDeHoje($s, 'PA', $clienteA, $proA, $corte, '13:00');
        $this->horarioDeHoje($s, 'PB', $clienteB, $proB, $corte, '13:00', "AG-E2E-PROB-{$s}");
        $codigoB = 'AT-E2E-PB-'.strtoupper(mb_substr($s, 0, 3));
        if (! Attendance::query()->where('code', $codigoB)->exists()) {
            $this->atendimentoConcluidoOntem($s, $clienteB, $proB, $corte, $codigoB);
        }
        if (! CustomerNote::query()->where('customer_id', $clienteA->id)->where('visibility', NoteVisibility::Professionals->value)->exists()) {
            CustomerNote::query()->create(['customer_id' => $clienteA->id, 'author_label' => 'Barbeiros (teste E2E)', 'visibility' => NoteVisibility::Professionals, 'body' => 'Prefere máquina 2 nas laterais']);
        }

        // Tela Clientes (Fase 13, P13-01): dono (anonimiza), recepcao (CPF
        // mascarado), financeiro (sem acesso), um cliente com historico e um
        // para anonimizar (recriado a cada execucao: o anonimizado perde o e-mail).
        $this->membro("e2e-clientes-dono-{$s}", 'Dono Clientes E2E', StaffRole::Owner, $senha);
        $this->membro("e2e-clientes-rec-{$s}", 'Recepção Clientes E2E', StaffRole::Reception, $senha);
        $this->membro("e2e-clientes-fin-{$s}", 'Financeiro Clientes E2E', StaffRole::Finance, $senha);
        $ficha = $this->cliente("e2e-ficha-{$s}@exemplo.test", "Cliente Ficha {$s}", $senha);
        $ficha->forceFill(['phone' => '+55119'.str_pad((string) (crc32($s) % 100000000), 8, '0', STR_PAD_LEFT)])->save();
        $codigoFicha = 'AT-E2E-FI-'.strtoupper(mb_substr($s, 0, 3));
        if (! Attendance::query()->where('code', $codigoFicha)->exists()) {
            $this->atendimentoConcluidoOntem($s, $ficha, $proA, $corte, $codigoFicha);
        }
        if (! CustomerNote::query()->where('customer_id', $ficha->id)->exists()) {
            CustomerNote::query()->create(['customer_id' => $ficha->id, 'author_label' => 'Barbeiros (teste E2E)', 'visibility' => NoteVisibility::Professionals, 'body' => 'Pele sensível: usar navalha nova']);
        }
        $this->cliente("e2e-anonimizar-{$s}@exemplo.test", "Cliente Anonimizar {$s}", $senha);
    }

    /**
     * Horario confirmado de HOJE as $hora com o servico de teste. As sobras
     * abertas do mesmo cliente com este profissional (e os atendimentos ainda
     * abertos delas) sao canceladas antes, pela transicao permitida. Com
     * $codigo fixo, o mesmo registro e reaproveitado (so volta ao estado
     * inicial): serve para o teste que tenta abri-lo pela URL.
     */
    private function horarioDeHoje(string $s, string $tag, Customer $cliente, Professional $pro, Service $servico, string $hora, ?string $codigo = null): void
    {
        $inicio = BusinessTime::at(BusinessTime::today(), $hora);
        $sobras = Appointment::query()->where('customer_id', $cliente->id)->where('professional_id', $pro->id)
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::Pending->value])->get();
        foreach ($sobras as $velho) {
            foreach (Attendance::query()->where('appointment_id', $velho->id)->whereIn('status', ['open', 'in_progress'])->get() as $at) {
                $at->forceFill(['status' => AttendanceStatus::Cancelled, 'active_appointment_id' => null, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save();
            }
            if ($velho->code !== $codigo) {
                $velho->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save();
            }
        }

        $dados = [
            'customer_id' => $cliente->id,
            'customer_name' => $cliente->name,
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'starts_at' => $inicio,
            'ends_at' => $inicio->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
        $ag = $codigo !== null
            ? Appointment::query()->updateOrCreate(['code' => $codigo], $dados)
            : Appointment::query()->create(['code' => 'AG-E2E-'.$tag.'-'.mb_substr($s, 0, 3).'-'.now()->format('ymdHis')] + $dados);
        if ($ag->items()->doesntExist()) {
            $ag->items()->create([
                'item_type' => ItemType::Service, 'service_id' => $servico->id, 'name' => $servico->name, 'quantity' => 1,
                'unit_price_cents' => $servico->price_cents, 'duration_minutes' => $servico->duration_minutes, 'price_source' => PriceSource::CatalogAtBooking,
            ]);
        }
        app(AppointmentPricing::class)->refresh($ag);
    }

    /**
     * Horario confirmado daqui a 3 dias as 16:00 (fuso da barbearia), com o
     * servico de teste. O da execucao anterior (remarcado ou nao) e cancelado
     * antes, pela transicao permitida.
     */
    private function horarioFuturo(string $s, Customer $cliente, Professional $pro, Service $servico): void
    {
        Appointment::query()->where('customer_id', $cliente->id)
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::Pending->value])->get()
            ->each(fn (Appointment $velho) => $velho->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save());

        $inicio = BusinessTime::at(CarbonImmutable::parse(BusinessTime::today())->addDays(3)->toDateString(), '16:00');
        $ag = Appointment::query()->create([
            'code' => 'AG-E2E-AR-'.mb_substr($s, 0, 3).'-'.now()->format('ymdHis'),
            'customer_id' => $cliente->id,
            'customer_name' => $cliente->name,
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'starts_at' => $inicio,
            'ends_at' => $inicio->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ]);
        $ag->items()->create([
            'item_type' => ItemType::Service, 'service_id' => $servico->id, 'name' => $servico->name, 'quantity' => 1,
            'unit_price_cents' => $servico->price_cents, 'duration_minutes' => $servico->duration_minutes, 'price_source' => PriceSource::CatalogAtBooking,
        ]);
        app(AppointmentPricing::class)->refresh($ag);
    }

    /**
     * Atendimento ficticio concluido ontem (historico de teste, sem caixa):
     * base valida para avaliar. Um novo a cada execucao.
     */
    private function atendimentoConcluidoOntem(string $s, Customer $cliente, Professional $pro, Service $servico, ?string $codigo = null): void
    {
        $ontem = now()->subDay();
        $at = Attendance::query()->create(($codigo !== null ? ['code' => $codigo] : []) + [
            'source' => 'walk_in', 'customer_id' => $cliente->id, 'customer_name' => $cliente->name,
            'professional_id' => $pro->id, 'professional_name' => $pro->display_name,
            'status' => AttendanceStatus::InProgress, 'opened_at' => $ontem, 'started_at' => $ontem,
            'subtotal_cents' => $servico->price_cents, 'discount_cents' => 0, 'total_cents' => $servico->price_cents, 'tip_cents' => 0,
            'completion_key' => 'e2e-'.$s.'-'.Str::uuid(),
        ]);
        $at->items()->create([
            'item_type' => 'service', 'service_id' => $servico->id, 'name' => $servico->name, 'quantity' => 1,
            'unit_price_cents' => $servico->price_cents, 'total_cents' => $servico->price_cents,
            'duration_minutes' => $servico->duration_minutes, 'price_source' => 'catalog_at_booking',
        ]);
        // Itens primeiro: depois de concluido, o atendimento nao muda mais.
        $at->forceFill(['status' => AttendanceStatus::Completed, 'completed_at' => $ontem->copy()->addMinutes(30)])->save();
    }

    /**
     * Plano "Clube E2E" (inclui o corte de teste) e assinatura manual ativa
     * com direito por mais 30 dias. Assinatura vencendo e encerrada pela
     * transicao permitida (expirada) e uma nova e criada; nada e apagado.
     */
    private function assinaturaAtiva(Customer $cliente, Service $corte, User $dono): void
    {
        $plano = Plan::query()->where('name', 'Clube E2E')->first()
            ?? app(Plans::class)->create('Clube E2E', 'Plano dos testes de navegador', 9900, [$corte->id], $dono);
        $hoje = BusinessTime::today();
        $atual = Subscription::query()->where('customer_id', $cliente->id)->whereIn('status', SubscriptionStatus::currentValues())->first();
        if ($atual !== null && $atual->ends_on !== null && $atual->ends_on->toDateString() >= CarbonImmutable::parse($hoje)->addDays(20)->toDateString()) {
            return;
        }
        $atual?->forceFill(['status' => SubscriptionStatus::Expired])->save();
        Subscription::query()->create([
            'customer_id' => $cliente->id, 'plan_id' => $plano->id, 'plan_version_id' => $plano->currentVersion?->id,
            'status' => SubscriptionStatus::Active, 'origin' => SubscriptionOrigin::Import, 'gateway' => Gateway::Manual,
            'starts_on' => $hoje, 'ends_on' => CarbonImmutable::parse($hoje)->addDays(30)->toDateString(), 'activated_at' => now(),
        ]);
    }

    /**
     * Comeca do zero a cada execucao, SEM apagar historico: encerra as regras
     * do profissional e quita o saldo em aberto (ajuste + repasse por Pix,
     * pelos servicos de verdade), como faria a equipe.
     */
    private function zerarComissao(Professional $pro, User $quem): void
    {
        $regras = app(CommissionRules::class);
        foreach (CommissionRule::query()->where('professional_id', $pro->id)->whereNotNull('current_scope')->get() as $r) {
            $regras->clear($r->target, $pro, $r->service, $quem);
        }

        $ledger = app(ProfessionalLedger::class);
        $aberto = $ledger->open($pro);
        if ($aberto['commission'] === 0 && $aberto['tips'] === 0 && $aberto['advances'] === 0) {
            return;
        }
        if ($aberto['net'] < 0) {
            $ledger->adjust($pro, 'commission', -$aberto['net'], 'Sobra de teste E2E', null, $quem, (string) Str::uuid());
        }
        app(Payouts::class)->pay($pro, PaymentMethod::Pix, 'Sobra de teste E2E', $quem, (string) Str::uuid());
    }

    /**
     * Agendamento confirmado cobrindo o instante atual (de 10 min atras a
     * 2 h a frente). Gravado direto, como dado de teste: o objetivo e a
     * agenda estar ocupada, nao testar a reserva.
     */
    private function ocupadoAgora(string $s, Customer $cliente, Professional $pro): void
    {
        Appointment::query()->where('professional_id', $pro->id)->where('customer_name', "Ocupando E2E {$s}")
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::Pending->value])->get()
            ->each(fn (Appointment $velho) => $velho->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save());

        $inicio = BusinessTime::now()->setSecond(0)->subMinutes(10);
        Appointment::query()->create([
            'code' => 'AG-E2E-OC-'.mb_substr($s, 0, 3).'-'.now()->format('ymdHis'),
            'customer_id' => $cliente->id,
            'customer_name' => "Ocupando E2E {$s}",
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'starts_at' => $inicio,
            'ends_at' => $inicio->addMinutes(130),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ]);
    }

    /** Produto de teste com ao menos 20 no estoque (entrada pelo razao, nunca editando saldo). */
    private function produto(string $nome, User $quem): void
    {
        $p = Product::query()->firstOrNew(['name' => $nome]);
        $p->fill(['price_cents' => 3500, 'cost_cents' => 1500, 'unit' => 'un', 'is_active' => true])->save();
        $ledger = app(StockLedger::class);
        $saldo = $ledger->balance($p);
        if ($saldo < 20) {
            $ledger->receive($p->refresh(), 20 - $saldo, 1500, 'Reposição para os testes E2E', $quem);
        }
    }

    /**
     * Agendamento confirmado para hoje (no fuso da barbearia), com o servico
     * de teste. Sobras de execucoes interrompidas (chegada ainda confirmada,
     * atendimento aberto) sao canceladas antes, pelas transicoes permitidas.
     */
    private function chegadaDeHoje(string $s, Customer $cliente, Professional $pro, Service $servico): void
    {
        $sobras = Appointment::query()->where('customer_name', "Chegada E2E {$s}")
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::Pending->value])->get();
        foreach ($sobras as $velho) {
            foreach (Attendance::query()->where('appointment_id', $velho->id)->whereIn('status', ['open', 'in_progress'])->get() as $at) {
                $at->forceFill(['status' => AttendanceStatus::Cancelled, 'active_appointment_id' => null, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save();
            }
            $velho->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save();
        }

        $inicio = BusinessTime::at(BusinessTime::today(), '12:00');
        $ag = Appointment::query()->create([
            'code' => 'AG-E2E-HJ-'.mb_substr($s, 0, 3).'-'.now()->format('ymdHis'),
            'customer_id' => $cliente->id,
            'customer_name' => "Chegada E2E {$s}",
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'starts_at' => $inicio,
            'ends_at' => $inicio->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ]);
        $ag->items()->create([
            'item_type' => ItemType::Service, 'service_id' => $servico->id, 'name' => $servico->name, 'quantity' => 1,
            'unit_price_cents' => $servico->price_cents, 'duration_minutes' => $servico->duration_minutes, 'price_source' => PriceSource::CatalogAtBooking,
        ]);
        app(AppointmentPricing::class)->refresh($ag);
    }

    /**
     * Agenda minima para os testes: funcionamento todos os dias das 09:00 as
     * 20:00 (so se ainda nao houver nenhum, para nao mexer na configuracao de
     * quem desenvolve) e o servico "Corte E2E".
     */
    private function agenda(): void
    {
        if (! BusinessHour::query()->exists()) {
            for ($d = 0; $d <= 6; $d++) {
                BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '09:00', 'ends_at' => '20:00']);
            }
        }

        $categoria = ServiceCategory::query()->firstOrCreate(['slug' => 'e2e'], ['name' => 'E2E', 'is_active' => true, 'sort_order' => 999]);
        $servico = Service::query()->firstOrNew(['slug' => 'corte-e2e']);
        $servico->fill(['name' => 'Corte E2E', 'category_id' => $categoria->id, 'duration_minutes' => 30, 'price_cents' => 5000, 'is_active' => true, 'is_public' => true]);
        $servico->save();
    }

    private function membro(string $username, string $nome, StaffRole $papel, string $senha, bool $provisoria = false): User
    {
        $user = User::query()->firstOrNew(['username' => $username]);
        $user->name = $nome;
        $user->email = $username.'@barbearia.test';
        $user->password = $senha;
        $user->forceFill(['role' => $papel, 'is_active' => true, 'must_change_password' => $provisoria])->save();

        return $user;
    }

    private function barbeiro(string $username, string $nome, string $senha): Professional
    {
        $user = $this->membro($username, $nome, StaffRole::Professional, $senha);

        return Professional::query()->updateOrCreate(['user_id' => $user->id], ['display_name' => $nome, 'is_active' => true, 'is_bookable' => true]);
    }

    private function cliente(string $email, string $nome, string $senha): Customer
    {
        $c = Customer::query()->firstOrNew(['email' => $email]);
        $c->fill(['name' => $nome, 'password' => $senha]);
        $c->cpf ??= CustomerFactory::fakeCpf();
        $c->email_verified_at = now();
        $c->save();

        return $c;
    }

    private function agendamento(string $codigo, Customer $cliente, Professional $pro): void
    {
        $inicio = now()->addDays(3)->setTime(14, 0);

        Appointment::query()->updateOrCreate(['code' => $codigo], [
            'customer_id' => $cliente->id,
            'customer_name' => $cliente->name,
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'starts_at' => $inicio,
            'ends_at' => $inicio->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ]);
    }
}
