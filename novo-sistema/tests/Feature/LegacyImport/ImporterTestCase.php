<?php

namespace Tests\Feature\LegacyImport;

use App\Modules\LegacyImport\LegacyImporter;
use App\Modules\LegacyImport\Testing\FictitiousLegacyDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class ImporterTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $source;

    protected CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->now = CarbonImmutable::parse('2026-09-26 15:00:00', 'UTC');
        $this->source = sys_get_temp_dir().'/legado-teste-'.getmypid().'-'.uniqid().'.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->source)) {
            unlink($this->source);
        }
        parent::tearDown();
    }

    protected function buildSource(int $customers = 30, int $appointments = 120, bool $minimal = false): string
    {
        return (new FictitiousLegacyDatabase($this->source, $customers, $appointments, $minimal, $this->now))->build();
    }

    /**
     * @return array<string, mixed>
     */
    protected function import(bool $dryRun = false): array
    {
        return app(LegacyImporter::class)->run($this->source, $dryRun, $this->now);
    }

    protected function ref(string $table, string $id): ?int
    {
        $v = DB::table('legacy_references')->where(['source_table' => $table, 'source_id' => $id])->value('entity_id');

        return $v === null ? null : (int) $v;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array<string, mixed>>
     */
    protected function issues(array $r, string $code, ?string $sourceId = null): array
    {
        return array_values(array_filter($r['issues'], fn ($i) => $i['code'] === $code && ($sourceId === null || $i['source_id'] === $sourceId)));
    }

    /** Tabelas de dominio do sistema novo (tudo o que o importador escreve). */
    protected function domainTables(): array
    {
        return ['users', 'customers', 'customer_notes', 'customer_favorite_professionals', 'customer_merge_candidates', 'consent_records', 'email_suppressions',
            'customer_notifications', 'professionals', 'professional_service', 'professional_package', 'working_hours', 'schedule_breaks', 'time_off', 'blocked_slots',
            'service_categories', 'services', 'packages', 'package_items', 'products', 'stock_movements', 'appointments', 'appointment_items',
            'appointment_adjustments', 'appointment_events', 'appointment_reminders', 'payments', 'commission_payouts', 'advances', 'expenses',
            'financial_goals', 'loyalty_entries', 'coupons', 'coupon_redemptions', 'gift_cards', 'plans', 'plan_versions', 'plan_version_services', 'subscriptions',
            'subscription_payments', 'gateway_events', 'reviews', 'review_replies', 'campaigns', 'settings', 'audit_logs', 'import_runs', 'import_issues', 'legacy_references'];
    }
}
