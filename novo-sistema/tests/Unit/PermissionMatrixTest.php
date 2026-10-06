<?php

namespace Tests\Unit;

use App\Modules\Identity\Authorization\PermissionMatrix;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use Tests\TestCase;

/**
 * Regras da matriz de permissoes sem tocar no banco (usuario em memoria).
 */
class PermissionMatrixTest extends TestCase
{
    private function usuario(StaffRole $role, bool $ativo = true): User
    {
        $u = new User;
        $u->role = $role;
        $u->is_active = $ativo;

        return $u;
    }

    public function test_todo_papel_tem_entrada_na_matriz(): void
    {
        foreach (StaffRole::cases() as $role) {
            $this->assertArrayHasKey($role->value, config('permissions.roles'), "Papel {$role->value} sem entrada na matriz");
        }
    }

    public function test_toda_habilidade_dos_papeis_esta_declarada(): void
    {
        $declaradas = array_keys(config('permissions.abilities'));
        foreach (config('permissions.roles') as $papel => $habilidades) {
            foreach ($habilidades as $h) {
                $this->assertContains($h, $declaradas, "Papel {$papel} usa habilidade nao declarada: {$h}");
            }
        }
    }

    public function test_nenhum_papel_tem_curinga_nem_o_proprietario(): void
    {
        foreach (config('permissions.roles') as $papel => $habilidades) {
            $this->assertNotContains('*', $habilidades, "Papel {$papel} com curinga");
        }

        // Curinga colocado a forca nao concede nada.
        config(['permissions.roles.owner' => ['*']]);
        $this->assertFalse($this->usuario(StaffRole::Owner)->hasPermission('users.manage'));
    }

    public function test_habilidades_da_equipe_e_do_cliente_nao_se_misturam(): void
    {
        $this->assertSame([], array_intersect_key(config('permissions.abilities'), config('permissions.customer_abilities')));

        foreach (array_keys(config('permissions.customer_abilities')) as $h) {
            $this->assertFalse($this->usuario(StaffRole::Owner)->hasPermission($h), "equipe com habilidade de cliente {$h}");
        }
    }

    public function test_usuario_inativo_nao_tem_permissao_alguma(): void
    {
        $this->assertFalse($this->usuario(StaffRole::Owner, ativo: false)->hasPermission('panel.access'));
    }

    public function test_papel_sem_mapeamento_nao_tem_permissao(): void
    {
        config(['permissions.roles.reception' => null]);
        $this->assertFalse($this->usuario(StaffRole::Reception)->hasPermission('panel.access'));
    }

    public function test_ator_desconhecido_nao_tem_permissao(): void
    {
        $this->assertFalse(PermissionMatrix::allows(null, 'panel.access'));
        $this->assertFalse(PermissionMatrix::allows(new \stdClass, 'panel.access'));
    }

    /**
     * A matriz esperada, escrita a mao: mudar config/permissions.php sem
     * mudar aqui (e na documentacao) quebra o teste de proposito.
     */
    public function test_matriz_papel_por_habilidade(): void
    {
        $esperado = [
            'panel.access' => ['owner', 'manager', 'reception', 'finance', 'professional'],
            'system.health.view' => ['owner', 'manager'],
            'users.manage' => ['owner'],
            'audit.view' => ['owner'],
            'customers.view' => ['owner', 'manager', 'reception'],
            'customers.view_own' => ['professional'],
            'customers.create' => ['owner', 'manager', 'reception'],
            'customers.update' => ['owner', 'manager', 'reception'],
            'customers.view_cpf' => ['owner', 'manager'],
            'customers.anonymize' => ['owner'],
            'appointments.view_all' => ['owner', 'manager', 'reception'],
            'appointments.view_own' => ['professional'],
            'appointments.manage' => ['owner', 'manager', 'reception'],
            'appointments.manage_own' => ['professional'],
            'appointments.cancel' => ['owner', 'manager', 'reception'],
            'agenda.view' => ['owner', 'manager', 'reception', 'professional'],
            'schedule.settings' => ['owner', 'manager'],
            'schedule.working_hours' => ['owner', 'manager'],
            'schedule.time_off' => ['owner', 'manager', 'reception'],
            'schedule.blocks' => ['owner', 'manager', 'reception'],
            'services.view' => ['owner', 'manager', 'reception'],
            'services.create' => ['owner', 'manager'],
            'services.update' => ['owner', 'manager'],
            'services.toggle' => ['owner', 'manager'],
            'services.price' => ['owner', 'manager'],
            'services.display' => ['owner', 'manager'],
            'professionals.view' => ['owner', 'manager', 'reception'],
            'professionals.create' => ['owner', 'manager'],
            'professionals.update' => ['owner', 'manager'],
            'professionals.toggle' => ['owner', 'manager'],
            'professionals.services' => ['owner', 'manager'],
            'professionals.display' => ['owner', 'manager'],
            'attendances.view' => ['owner', 'manager', 'reception', 'finance'],
            'attendances.view_own' => ['professional'],
            'attendances.manage' => ['owner', 'manager', 'reception'],
            'attendances.manage_own' => ['professional'],
            'attendances.discount' => ['owner', 'manager'],
            'attendances.cancel' => ['owner', 'manager', 'reception'],
            'payments.receive' => ['owner', 'manager', 'reception', 'professional'],
            'payments.refund' => ['owner', 'finance'],
            'cash.view' => ['owner', 'manager', 'reception', 'finance'],
            'cash.open' => ['owner', 'manager', 'reception'],
            'cash.move' => ['owner', 'manager', 'reception'],
            'cash.close' => ['owner', 'manager', 'reception'],
            'products.view' => ['owner', 'manager', 'reception', 'finance'],
            'products.create' => ['owner', 'manager'],
            'products.update' => ['owner', 'manager'],
            'products.toggle' => ['owner', 'manager'],
            'stock.view' => ['owner', 'manager', 'reception', 'finance'],
            'stock.receive' => ['owner', 'manager', 'reception'],
            'stock.issue' => ['owner', 'manager', 'reception'],
            'stock.adjust' => ['owner', 'manager'],
            // Fase 7: comissao, gorjeta, vales e repasse. Regra de comissao so
            // o proprietario; pagar, corrigir e estornar: proprietario e
            // financeiro; gerente consulta; profissional ve o proprio extrato.
            'commissions.view' => ['owner', 'manager', 'finance'],
            'commissions.view_own' => ['professional'],
            'commissions.configure' => ['owner'],
            'commissions.correct' => ['owner', 'finance'],
            'commissions.history' => ['owner', 'manager', 'finance'],
            'payouts.view' => ['owner', 'manager', 'finance'],
            'payouts.create' => ['owner', 'finance'],
            'payouts.reverse' => ['owner', 'finance'],
            'advances.create' => ['owner', 'finance'],
            'advances.reverse' => ['owner', 'finance'],
            'reports.view' => ['owner', 'manager', 'finance'],
            // Fase 8: promocoes, fidelidade e vale-presente. Configurar regras:
            // so o proprietario. Cancelar vale (devolve dinheiro): proprietario
            // e financeiro, como o estorno (D-30). Aplicar cupom/pontos no
            // balcao: quem conclui atendimento.
            'coupons.view' => ['owner', 'manager', 'reception', 'finance'],
            'coupons.manage' => ['owner', 'manager'],
            'promotions.apply' => ['owner', 'manager', 'reception', 'professional'],
            'promotions.configure' => ['owner'],
            'loyalty.view' => ['owner', 'manager', 'reception', 'finance'],
            'loyalty.adjust' => ['owner', 'manager'],
            'gift_cards.view' => ['owner', 'manager', 'reception', 'finance'],
            'gift_cards.sell' => ['owner', 'manager', 'reception'],
            'gift_cards.cancel' => ['owner', 'finance'],
            // Fase 9: assinaturas
            'subscriptions.view' => ['owner', 'manager', 'reception', 'finance'],
            'subscriptions.payments' => ['owner', 'manager', 'finance'],
            'subscriptions.create' => ['owner', 'manager', 'reception'],
            'subscriptions.cancel' => ['owner', 'manager'],
            'subscriptions.reactivate' => ['owner', 'manager'],
            'subscriptions.refund' => ['owner', 'finance'],
            'subscriptions.history' => ['owner', 'manager', 'finance'],
            'plans.manage' => ['owner'],
            // Fase 10: comunicacao e avaliacoes. Configurar lembretes/ritmo: so
            // o proprietario. Disparar campanha separado de escrever rascunho.
            // Recepcao consulta avaliacoes; profissional ve as publicadas dele.
            'communications.view' => ['owner', 'manager'],
            'communications.retry' => ['owner', 'manager'],
            'communications.settings' => ['owner'],
            'campaigns.view' => ['owner', 'manager'],
            'campaigns.manage' => ['owner', 'manager'],
            'campaigns.send' => ['owner', 'manager'],
            'reviews.view' => ['owner', 'manager', 'reception'],
            'reviews.view_own' => ['professional'],
            'reviews.moderate' => ['owner', 'manager'],
            'reviews.reply' => ['owner', 'manager'],
            // Fase 11: conteudo e imagens do site (servicos e equipe ficam nos proprios cadastros).
            'site.manage' => ['owner', 'manager'],
            'settings.manage' => ['owner'],
        ];

        $this->assertEqualsCanonicalizing(array_keys($esperado), array_keys(config('permissions.abilities')), 'habilidades declaradas');

        foreach ($esperado as $habilidade => $papeis) {
            foreach (StaffRole::cases() as $role) {
                $this->assertSame(
                    in_array($role->value, $papeis, true),
                    $this->usuario($role)->hasPermission($habilidade),
                    "{$role->value} x {$habilidade}",
                );
            }
        }
    }
}
