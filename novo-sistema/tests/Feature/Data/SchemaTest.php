<?php

namespace Tests\Feature\Data;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABELAS = [
        'users', 'customers', 'customer_notes', 'customer_favorite_professionals', 'customer_merge_candidates', 'consent_records',
        'email_suppressions', 'customer_notifications', 'professionals', 'professional_service', 'professional_package', 'working_hours',
        'schedule_breaks', 'time_off', 'blocked_slots', 'service_categories', 'services', 'packages', 'package_items', 'products',
        'stock_movements', 'appointments', 'appointment_items', 'appointment_adjustments', 'appointment_events', 'appointment_reminders',
        'payments', 'commission_entries', 'commission_payouts', 'advances', 'expenses', 'financial_goals', 'loyalty_entries', 'coupons',
        'coupon_redemptions', 'gift_cards', 'plans', 'plan_services', 'subscriptions', 'subscription_payments', 'gateway_events',
        'reviews', 'review_replies', 'campaigns', 'settings', 'audit_logs', 'import_runs', 'import_issues', 'legacy_references',
    ];

    public function test_todas_as_tabelas_do_modelo_existem(): void
    {
        foreach (self::TABELAS as $t) {
            $this->assertTrue(Schema::hasTable($t), "Tabela {$t} ausente");
        }
    }

    public function test_dinheiro_e_sempre_inteiro_nunca_float_ou_decimal(): void
    {
        $colunasDinheiro = 0;
        foreach (self::TABELAS as $t) {
            foreach (Schema::getColumns($t) as $c) {
                $tipo = strtolower($c['type_name']);
                $this->assertNotContains($tipo, ['real', 'float', 'double', 'decimal', 'numeric'], "{$t}.{$c['name']} usa {$tipo}");
                if (str_ends_with($c['name'], '_cents') || str_ends_with($c['name'], '_bp')) {
                    $colunasDinheiro++;
                    $this->assertContains($tipo, ['integer', 'int', 'bigint', 'smallint', 'tinyint'], "{$t}.{$c['name']} deveria ser inteiro");
                }
            }
        }
        $this->assertGreaterThanOrEqual(30, $colunasDinheiro);
    }

    public function test_chaves_estrangeiras_e_acoes(): void
    {
        $fk = fn (string $t, string $col) => collect(Schema::getForeignKeys($t))->first(fn ($f) => $f['columns'] === [$col]);

        $esperado = [
            ['appointments', 'professional_id', 'professionals', 'restrict'],
            ['appointments', 'customer_id', 'customers', 'set null'],
            ['appointment_items', 'appointment_id', 'appointments', 'cascade'],
            ['appointment_items', 'service_id', 'services', 'set null'],
            ['payments', 'attendance_id', 'attendances', 'restrict'],
            ['payments', 'cash_session_id', 'cash_sessions', 'restrict'],
            ['attendances', 'appointment_id', 'appointments', 'restrict'],
            ['attendances', 'professional_id', 'professionals', 'restrict'],
            ['attendance_items', 'attendance_id', 'attendances', 'cascade'],
            ['attendance_consumptions', 'product_id', 'products', 'restrict'],
            ['cash_movements', 'cash_session_id', 'cash_sessions', 'restrict'],
            ['cash_movements', 'payment_id', 'payments', 'restrict'],
            ['stock_movements', 'attendance_id', 'attendances', 'restrict'],
            ['stock_movements', 'reverses_movement_id', 'stock_movements', 'restrict'],
            ['loyalty_entries', 'customer_id', 'customers', 'restrict'],
            ['subscriptions', 'customer_id', 'customers', 'restrict'],
            ['subscription_payments', 'customer_id', 'customers', 'restrict'],
            ['commission_payouts', 'professional_id', 'professionals', 'restrict'],
            ['stock_movements', 'product_id', 'products', 'restrict'],
            ['customer_notes', 'customer_id', 'customers', 'cascade'],
            ['reviews', 'appointment_id', 'appointments', 'set null'],
            ['professionals', 'user_id', 'users', 'set null'],
            ['legacy_references', 'import_run_id', 'import_runs', 'set null'],
        ];
        foreach ($esperado as [$t, $col, $alvo, $acao]) {
            $f = $fk($t, $col);
            $this->assertNotNull($f, "FK {$t}.{$col} ausente");
            $this->assertSame($alvo, $f['foreign_table']);
            $this->assertSame($acao, strtolower($f['on_delete']), "{$t}.{$col} on delete");
        }
    }

    public function test_indices_unicos(): void
    {
        $unicos = fn (string $t) => collect(Schema::getIndexes($t))->where('unique', true)->pluck('columns')->map(fn ($c) => implode(',', $c))->all();

        foreach ([
            'customers' => ['email', 'phone', 'cpf', 'referral_code', 'public_id'],
            'users' => ['email', 'username'],
            'appointments' => ['code'],
            'coupons' => ['code'],
            'gift_cards' => ['code'],
            'subscriptions' => ['gateway_subscription_id', 'active_customer_id'],
            'subscription_payments' => ['gateway_payment_id'],
            'gateway_events' => ['gateway,event_id'],
            'reviews' => ['appointment_id'],
            // Fase 8: 1 uso por cliente via sentinela (reservado ou usado; liberado nao conta).
            'coupon_redemptions' => ['active_key'],
            'loyalty_redemptions' => ['active_key'],
            'legacy_references' => ['source_table,source_id'],
            'appointment_reminders' => ['appointment_id,kind'],
            'email_suppressions' => ['email'],
            'settings' => ['key'],
        ] as $t => $cols) {
            foreach ($cols as $c) {
                $this->assertContains($c, $unicos($t), "Unico {$t}({$c}) ausente");
            }
        }
    }

    public function test_historico_financeiro_nao_tem_soft_delete_e_cadastro_tem(): void
    {
        foreach (['services', 'packages', 'products', 'professionals', 'customers', 'plans', 'coupons', 'expenses'] as $t) {
            $this->assertTrue(Schema::hasColumn($t, 'deleted_at'), "{$t} deveria ter soft delete");
        }
        foreach (['payments', 'loyalty_entries', 'stock_movements', 'appointment_items', 'subscription_payments', 'commission_entries'] as $t) {
            $this->assertFalse(Schema::hasColumn($t, 'deleted_at'), "{$t} e historico: nao se apaga");
        }
        $this->assertFalse(Schema::hasColumn('products', 'stock'), 'estoque nao e coluna: e soma do razao');
        $this->assertFalse(Schema::hasColumn('customers', 'loyalty_points'), 'pontos nao sao coluna: sao soma do razao');
    }

    public function test_migrations_desfazem_e_refazem(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));
        $this->assertFalse(Schema::hasTable('appointments'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('appointments'));
    }
}
