<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Checkout\Services\AttendanceService;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\SiteContent\Support\SiteSettings;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\Plans;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dados de DEMONSTRACAO, todos ficticios, para ver as telas com conteudo
 * (revisao visual e capturas do redesign). So em local/testing e so num banco
 * sem agendamentos: nunca mistura com dados de verdade.
 *
 * Tudo passa pelos servicos do dominio (agenda, atendimento, caixa,
 * comissao, avaliacoes): os numeros que aparecem nas telas saem das mesmas
 * regras de sempre. Os textos do site ficam marcados como demonstracao.
 */
class DemoData extends Command
{
    protected $signature = 'app:demo-data {--password= : Senha das contas (12+ caracteres); sem ela, uma aleatoria e mostrada}';

    protected $description = 'Preenche um banco local VAZIO com dados ficticios de demonstracao (so local/testing)';

    private User $dono;

    private User $recepcao;

    /** @var list<Professional> */
    private array $equipe = [];

    /** @var array<string, Service> */
    private array $servicos = [];

    /** @var list<Customer> */
    private array $clientes = [];

    private int $seq = 0;

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Somente em local/testing.');

            return self::FAILURE;
        }
        if (Appointment::query()->exists()) {
            $this->error('O banco ja tem agendamentos: use um banco vazio (migrate num SQLite novo).');

            return self::FAILURE;
        }
        $senha = (string) ($this->option('password') ?: Str::password(16));
        if (strlen($senha) < 12) {
            $this->error('Senha com 12+ caracteres.');

            return self::FAILURE;
        }
        mt_srand(20261007); // mesma demonstracao a cada execucao

        $this->base($senha);
        $agora = CarbonImmutable::now();

        // Regras de comissao valendo desde o inicio do historico de demonstracao.
        $this->relogio(BusinessTime::local($agora)->subDays(15)->toDateString(), '08:00');
        app(CommissionRules::class)->set(CommissionTarget::Service, null, null, CommissionRuleType::Percent, 4500, null, 'Regra de demonstração', $this->dono);
        app(CommissionRules::class)->set(CommissionTarget::Product, null, null, CommissionRuleType::Percent, 1000, null, 'Regra de demonstração', $this->dono);

        // Duas semanas de historico (atendimentos concluidos, caixa do dia).
        for ($d = 14; $d >= 1; $d--) {
            $this->diaPassado(BusinessTime::local($agora)->subDays($d)->toDateString());
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        $this->hoje();
        $this->proximosDias();
        $this->extras();

        $this->info('Dados de demonstracao prontos (ficticios).');
        $this->info('Equipe: dono | gerente | recepcao (/painel/entrar). Cliente: cliente@barbearia.test (/entrar).');
        if (! $this->option('password')) {
            $this->warn('Senha (so desta execucao): '.$senha);
        }

        return self::SUCCESS;
    }

    private function base(string $senha): void
    {
        for ($d = 0; $d <= 6; $d++) {
            // Dia longo: a grade de hoje e montada em volta da hora atual (ver hoje()).
            BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '07:00', 'ends_at' => '23:30']);
        }
        $this->dono = $this->membro('dono', 'Ricardo Almeida', StaffRole::Owner, $senha);
        $this->membro('gerente', 'Paula Nogueira', StaffRole::Manager, $senha);
        $this->recepcao = $this->membro('recepcao', 'Camila Rocha', StaffRole::Reception, $senha);
        $this->membro('financeiro', 'Sérgio Matos', StaffRole::Finance, $senha);

        $cats = [];
        foreach ([['cabelo', 'Cabelo', 10], ['barba', 'Barba', 20], ['tratamentos', 'Tratamentos', 30]] as [$slug, $nome, $ordem]) {
            $cats[$slug] = ServiceCategory::query()->create(['slug' => $slug, 'name' => $nome, 'is_active' => true, 'sort_order' => $ordem]);
        }
        $lista = [
            ['corte', 'cabelo', 'Corte', 45, 7000, 'Tesoura e máquina, lavagem e finalização.', true],
            ['corte-degrade', 'cabelo', 'Corte degradê', 45, 7500, 'Transição precisa nas laterais, acabamento na navalha.', false],
            ['corte-e-barba', 'cabelo', 'Corte + barba', 75, 11000, 'O serviço completo, com toalha quente.', true],
            ['barba', 'barba', 'Barba', 30, 5000, 'Desenho, navalha e toalha quente.', true],
            ['pigmentacao', 'barba', 'Pigmentação de barba', 30, 4500, 'Preenchimento discreto das falhas.', false],
            ['sobrancelha', 'tratamentos', 'Sobrancelha', 15, 2500, 'Limpeza na pinça ou navalha.', false],
            ['limpeza-de-pele', 'tratamentos', 'Limpeza de pele', 40, 8000, 'Esfoliação, extração e máscara.', false],
        ];
        foreach ($lista as $i => [$slug, $cat, $nome, $min, $preco, $desc, $destaque]) {
            $this->servicos[$slug] = Service::query()->create([
                'slug' => $slug, 'category_id' => $cats[$cat]->id, 'name' => $nome, 'description' => $desc, 'duration_minutes' => $min,
                'price_cents' => $preco, 'is_active' => true, 'is_public' => true, 'is_featured' => $destaque, 'sort_order' => ($i + 1) * 10,
            ]);
        }

        $pros = [
            ['rafael', 'Rafael Moura', 'Cortes clássicos e tesoura', 'Doze anos de cadeira. Prefere o corte que cresce bem.'],
            ['diego', 'Diego Santana', 'Degradê e desenho', 'Especialista em transições limpas e acabamento na navalha.'],
            ['bruno', 'Bruno Lacerda', 'Barba e toalha quente', 'Barba desenhada no rosto de cada um, sem pressa.'],
            ['thiago', 'Thiago Ventura', 'Cortes e tratamentos', 'Cuida do cabelo e da pele, do corte à limpeza.'],
        ];
        foreach ($pros as $i => [$user, $nome, $headline, $bio]) {
            $u = $this->membro($user, $nome, StaffRole::Professional, $senha);
            $p = Professional::query()->create([
                'user_id' => $u->id, 'display_name' => $nome, 'slug' => Str::slug($nome), 'headline' => $headline, 'bio' => $bio,
                'is_active' => true, 'is_bookable' => true, 'is_public' => true, 'sort_order' => ($i + 1) * 10,
            ]);
            $p->services()->sync(collect($this->servicos)->filter(fn (Service $s, string $slug) => $i !== 3 || $slug !== 'corte-degrade')->pluck('id')->all());
            $this->equipe[] = $p;
        }

        $nomes = ['Lucas Ferreira', 'Mateus Oliveira', 'Gabriel Souza', 'Pedro Henrique Lima', 'Felipe Carvalho', 'André Barros',
            'Rodrigo Pires', 'Gustavo Teixeira', 'Leonardo Martins', 'Vinícius Cardoso', 'Eduardo Freitas', 'Caio Ribeiro',
            'Henrique Azevedo', 'Marcelo Costa', 'Daniel Rezende', 'João Vitor Prado'];
        foreach ($nomes as $i => $nome) {
            $c = Customer::query()->create([
                'name' => $nome, 'email' => $i === 0 ? 'cliente@barbearia.test' : Str::slug($nome, '.').'@exemplo.test',
                'phone' => '+55119'.str_pad((string) (88000000 + $i * 7919), 8, '0', STR_PAD_LEFT), 'cpf' => CustomerFactory::fakeCpf(),
                'password' => $senha, 'birth_date' => $i === 0 ? BusinessTime::local(CarbonImmutable::now())->setDate(1991, (int) BusinessTime::local(CarbonImmutable::now())->format('m'), 12)->toDateString() : null,
            ]);
            $c->forceFill(['email_verified_at' => now()])->save();
            $this->clientes[] = $c;
        }

        foreach ([['Pomada modeladora', 3500, 1500, 5, 24], ['Óleo para barba', 4290, 1800, 3, 2], ['Shampoo de barba', 3900, 1600, 4, 10], ['Lâmina descartável', null, 80, 50, 120]] as [$nome, $preco, $custo, $minimo, $qtd]) {
            $p = Product::query()->create(['name' => $nome, 'price_cents' => $preco, 'cost_cents' => $custo, 'min_stock' => $minimo, 'unit' => 'un', 'is_active' => true]);
            app(StockLedger::class)->receive($p, $qtd, $custo, 'Estoque inicial (demonstração)', $this->dono);
        }

        PromotionPolicy::save([
            'loyalty_enabled' => true, 'loyalty_earn_mode' => 'visit', 'loyalty_points_per_visit' => 1, 'loyalty_points_required' => 10,
            'loyalty_reward_type' => 'free_service', 'birthday_enabled' => true, 'birthday_percent_bp' => 1500,
            'referral_enabled' => true, 'referral_percent_bp' => 1000, 'referral_bonus_points' => 2,
        ], $this->dono);

        SiteSettings::save([
            'name' => 'Barbearia Demonstração',
            'tagline' => 'Corte bem feito, sem pressa.',
            'hero_subtitle' => 'Dados fictícios de demonstração para a revisão visual. Nada aqui é o conteúdo real da barbearia.',
            'neighborhood' => 'Bairro Exemplo, Cidade',
            'about_title' => 'Uma casa de ofício',
            'about_text' => "Texto de demonstração: aqui entra a história real da barbearia, escrita pelo dono.\n\nO espaço mostra como o site organiza parágrafos, fotos e diferenciais.",
            'highlights' => "Hora marcada: o horário é seu, sem fila.\nToalha quente: em todo serviço de barba.\nProdutos à venda: os mesmos usados na cadeira.",
            'address' => 'Endereço de demonstração, 100',
            'address_note' => 'Referência fictícia para a revisão visual.',
            'phone' => '(11) 3000-0000',
            'whatsapp' => '11990000000',
            'instagram' => 'https://www.instagram.com/exemplo',
        ], $this->dono);
    }

    /** Um dia ja passado: caixa aberto de manha, 4 a 7 atendimentos concluidos, caixa fechado a noite. */
    private function diaPassado(string $dia): void
    {
        $this->relogio($dia, '08:30');
        $caixa = app(CashRegister::class)->open(20000, null, $this->recepcao);
        $horas = ['09:00', '10:00', '11:00', '13:30', '14:30', '16:00', '17:00', '18:00'];
        $n = 4 + mt_rand(0, 3);
        foreach (array_slice($horas, 0, $n) as $i => $hora) {
            $pro = $this->equipe[($i + mt_rand(0, 3)) % 4];
            $this->relogio($dia, '08:45');
            $ag = $this->reservar($dia, $hora, $pro);
            if ($ag === null) {
                continue;
            }
            $this->relogio($dia, $hora);
            $this->concluir($ag, mt_rand(0, 4) === 0);
        }
        $this->relogio($dia, '20:15');
        $caixa = $caixa->fresh();
        if ($caixa !== null) {
            app(CashRegister::class)->close($caixa, app(CashRegister::class)->expectedCash($caixa), null, $this->recepcao);
        }
    }

    /** Hoje: horarios antes de agora concluidos (um em andamento), os demais marcados. */
    private function hoje(): void
    {
        $agora = BusinessTime::now();
        $dia = BusinessTime::today();
        $this->relogio($dia, '07:00');
        app(CashRegister::class)->open(20000, 'Abertura (demonstração)', $this->recepcao);
        $this->relogio($dia, '07:05');
        app(CashRegister::class)->supply(app(CashRegister::class)->lockOpen(), 5000, 'Troco extra', $this->recepcao);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        // Grade em volta da hora atual (de 4 h antes a 3 h depois), para a agenda
        // de hoje sempre mostrar concluidos, um em andamento e horarios a seguir.
        $base = BusinessTime::local($agora)->setTime((int) BusinessTime::local($agora)->format('H'), (int) BusinessTime::local($agora)->format('i') >= 30 ? 30 : 0);
        $modelo = [
            [-240, 0, 'corte'], [-240, 1, 'corte-degrade'], [-210, 2, 'barba'], [-180, 3, 'limpeza-de-pele'],
            [-150, 0, 'corte-e-barba'], [-120, 1, 'corte'], [-120, 2, 'corte-e-barba'], [-90, 3, 'corte'],
            [-60, 0, 'barba'], [-30, 1, 'corte-degrade'], [-30, 2, 'pigmentacao'], [0, 0, 'corte'],
            [30, 3, 'sobrancelha'], [30, 1, 'corte-e-barba'], [60, 2, 'barba'], [90, 0, 'corte-degrade'],
            [90, 3, 'corte'], [120, 1, 'corte'], [150, 2, 'corte-e-barba'], [180, 0, 'barba'],
        ];
        $grade = [];
        foreach ($modelo as [$min, $p, $servico]) {
            $t = $base->addMinutes($min);
            if ($t->toDateString() === $dia && $t->format('H:i') >= '07:00' && $t->format('H:i') <= '22:30') {
                $grade[] = [$t->format('H:i'), $p, $servico];
            }
        }
        $emAndamento = false;
        foreach ($grade as $i => [$hora, $p, $servico]) {
            $inicio = BusinessTime::at($dia, $hora);
            $fim = $inicio->addMinutes($this->servicos[$servico]->duration_minutes);
            if ($fim->lte($agora)) {
                $this->relogio($dia, '08:00');
                $ag = $this->reservar($dia, $hora, $this->equipe[$p], $servico);
                if ($ag !== null) {
                    $this->relogio($dia, $hora);
                    $i % 6 === 5 ? $this->booking()->markNoShow($ag->fresh(), $this->recepcao) : $this->concluir($ag, $i % 4 === 0);
                }
                Carbon::setTestNow();
                CarbonImmutable::setTestNow();

                continue;
            }
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            if ($inicio->lte($agora)) {
                // Comecou e nao terminou: atendimento em andamento.
                $this->relogio($dia, BusinessTime::local($inicio)->subMinutes(30)->format('H:i'));
                $ag = $this->reservar($dia, $hora, $this->equipe[$p], $servico);
                Carbon::setTestNow();
                CarbonImmutable::setTestNow();
                if ($ag !== null && ! $emAndamento) {
                    $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);
                    $emAndamento = true;
                }

                continue;
            }
            $ag = $this->reservar($dia, $hora, $this->equipe[$p], $servico, $i % 3 === 0 ? AppointmentSource::Online : AppointmentSource::Staff);
            if ($ag !== null && $i % 2 === 0) {
                try {
                    $this->booking()->confirm($ag, $this->recepcao);
                } catch (Throwable) {
                    // ja confirmado na criacao
                }
            }
        }
    }

    private function proximosDias(): void
    {
        for ($d = 1; $d <= 6; $d++) {
            $dia = BusinessTime::local(CarbonImmutable::now())->addDays($d)->toDateString();
            foreach (['09:30', '11:00', '14:00', '15:30', '17:00'] as $i => $hora) {
                if (mt_rand(0, 2) > 0) {
                    $this->reservar($dia, $hora, $this->equipe[($d + $i) % 4]);
                }
            }
        }
        // O cliente de demonstracao tem um horario daqui a dois dias.
        $this->reservar(BusinessTime::local(CarbonImmutable::now())->addDays(2)->toDateString(), '10:00', $this->equipe[1], 'corte-e-barba', AppointmentSource::Online, $this->clientes[0]);
    }

    private function extras(): void
    {
        $cliente = $this->clientes[0];

        // Avaliacoes: as dos atendimentos dos ultimos dias, aprovadas (algumas em destaque, com resposta).
        $comentarios = ['Corte impecável, saí do jeito que pedi.', 'Atendimento sem pressa e ambiente muito bom.', 'A toalha quente na barba faz diferença.', null, 'Pontual e caprichoso. Volto no mês que vem.', 'Melhor degradê que já fiz.'];
        $reviews = app(Reviews::class);
        foreach (Attendance::query()->with('customer')->where('status', 'completed')->whereNotNull('customer_id')->latest('completed_at')->limit(14)->get() as $i => $at) {
            try {
                $r = $reviews->submit($at->customer, $at, [5, 5, 4, 5, 4, 5, 3][$i % 7], $comentarios[$i % count($comentarios)]);
                if ($i % 5 !== 4) {
                    $r = $reviews->moderate($r, ReviewStatus::Approved, null, $this->dono);
                    if ($i < 3 && $r->comment) {
                        $reviews->feature($r, true, $this->dono);
                    }
                    if ($i === 0) {
                        $reviews->reply($r, 'Obrigado pela visita! Até a próxima.', $this->dono);
                    }
                }
            } catch (Throwable $e) {
                $this->line('  (avaliacao pulada: '.$e->getMessage().')');
            }
        }

        // Planos e duas assinaturas manuais (sem Stripe).
        $clube = app(Plans::class)->create('Clube do Corte', 'Cortes ilimitados no mês.', 12900, [$this->servicos['corte']->id], $this->dono);
        app(Plans::class)->create('Clube Completo', 'Corte e barba ilimitados no mês.', 19900, [$this->servicos['corte']->id, $this->servicos['barba']->id, $this->servicos['corte-e-barba']->id], $this->dono);
        foreach ([$cliente, $this->clientes[3]] as $c) {
            Subscription::query()->create([
                'customer_id' => $c->id, 'plan_id' => $clube->id, 'plan_version_id' => $clube->currentVersion?->id,
                'status' => SubscriptionStatus::Active, 'origin' => SubscriptionOrigin::Import, 'gateway' => Gateway::Manual,
                'starts_on' => BusinessTime::local(CarbonImmutable::now())->subDays(9)->toDateString(),
                'ends_on' => BusinessTime::local(CarbonImmutable::now())->addDays(21)->toDateString(), 'activated_at' => now()->subDays(9),
            ]);
        }

        foreach ([['BEMVINDO10', 'percent', 1000, null], ['VERAO2026', 'fixed', 1500, 100]] as [$codigo, $tipo, $valor, $max]) {
            Coupon::query()->create([
                'code' => $codigo, 'discount_type' => $tipo, 'percent_bp' => $tipo === 'percent' ? $valor : null, 'amount_cents' => $tipo === 'fixed' ? $valor : null,
                'max_uses' => $max, 'expires_on' => BusinessTime::local(CarbonImmutable::now())->addMonths(2)->toDateString(), 'is_active' => true,
            ]);
        }

        app(LoyaltyLedger::class)->credit($cliente, 4, LoyaltyEntryKind::Adjustment, 'Pontos de demonstração');
        foreach (['Lembrete: seu horário é depois de amanhã às 10:00 com Diego Santana.', 'Sua assinatura Clube do Corte foi renovada.'] as $msg) {
            CustomerNotification::query()->create(['customer_id' => $cliente->id, 'kind' => str_contains($msg, 'assinatura') ? 'subscription' : 'reminder', 'message' => $msg]);
        }
        DB::table('customers')->whereIn('id', collect($this->clientes)->take(6)->pluck('id'))->update(['marketing_email_consent' => 'granted']);
    }

    private function reservar(string $dia, string $hora, Professional $pro, ?string $servico = null, AppointmentSource $origem = AppointmentSource::Staff, ?Customer $cliente = null): ?Appointment
    {
        $s = $this->servicos[$servico ?? array_keys($this->servicos)[$this->seq % 5]];
        $cliente ??= $this->clientes[$this->seq++ % count($this->clientes)];
        if (! $pro->services()->whereKey($s->id)->exists()) {
            $s = $this->servicos['corte'];
        }
        try {
            return $this->booking()->book(new BookingRequest(
                service: $s, professional: $pro, start: BusinessTime::at($dia, $hora), channel: Channel::Staff, source: $origem,
                customer: $cliente, actor: $this->recepcao,
            ));
        } catch (Throwable) {
            return null; // conflito na grade de demonstracao: pula
        }
    }

    private function concluir(Appointment $ag, bool $comProduto): void
    {
        try {
            $at = $this->attendances()->start($this->attendances()->openFromAppointment($ag->fresh(), $this->recepcao), $this->recepcao);
            if ($comProduto) {
                $at = $this->attendances()->addProduct($at, Product::query()->where('name', 'Pomada modeladora')->firstOrFail(), 1, $this->recepcao);
            }
            $total = (int) app(AttendancePricing::class)->breakdown($at->fresh())->total?->cents;
            $metodos = [PaymentMethod::Pix, PaymentMethod::CreditCard, PaymentMethod::DebitCard, PaymentMethod::Cash];
            $gorjeta = mt_rand(0, 3) === 0 ? 1000 : 0;
            $this->attendances()->complete($at->fresh(), [new PaymentLine($metodos[mt_rand(0, 3)], $total, $gorjeta)], (string) Str::uuid(), $this->recepcao);
        } catch (Throwable $e) {
            $this->line('  (pulado: '.class_basename($e).')');
        }
    }

    private function relogio(string $dia, string $hora): void
    {
        $t = BusinessTime::at($dia, $hora);
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    private function membro(string $usuario, string $nome, StaffRole $papel, string $senha): User
    {
        $u = new User;
        $u->username = $usuario;
        $u->name = $nome;
        $u->email = $usuario.'@barbearia.test';
        $u->password = $senha;
        $u->forceFill(['role' => $papel, 'is_active' => true, 'must_change_password' => false])->save();

        return $u;
    }

    private function booking(): BookingService
    {
        return app(BookingService::class);
    }

    private function attendances(): AttendanceService
    {
        return app(AttendanceService::class);
    }
}
