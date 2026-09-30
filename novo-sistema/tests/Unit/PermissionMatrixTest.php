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
            'checkout.operate' => ['owner', 'manager', 'reception'],
            'finance.view' => ['owner', 'manager', 'finance'],
            'finance.manage' => ['owner', 'finance'],
            'reports.view' => ['owner', 'manager', 'finance'],
            'marketing.manage' => ['owner', 'manager'],
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
