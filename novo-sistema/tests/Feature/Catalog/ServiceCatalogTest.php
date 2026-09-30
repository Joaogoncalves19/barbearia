<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\ServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leitura do catalogo para a agenda (Fase 5) e o site (Fase 11): uma regra
 * so para "agendavel" e "publico".
 */
class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_agendavel_e_publico_por_categoria_na_ordem(): void
    {
        $barba = ServiceCategory::factory()->create(['name' => 'Barba', 'sort_order' => 20]);
        $cabelo = ServiceCategory::factory()->create(['name' => 'Cabelo', 'sort_order' => 10]);
        $fechada = ServiceCategory::factory()->create(['name' => 'Fechada', 'sort_order' => 5, 'is_active' => false]);

        Service::factory()->create(['name' => 'Barba simples', 'category_id' => $barba->id, 'sort_order' => 10]);
        Service::factory()->create(['name' => 'Degradê', 'category_id' => $cabelo->id, 'sort_order' => 20]);
        Service::factory()->create(['name' => 'Social', 'category_id' => $cabelo->id, 'sort_order' => 10]);
        Service::factory()->create(['name' => 'Interno', 'category_id' => $cabelo->id, 'sort_order' => 30, 'is_public' => false]);
        Service::factory()->create(['name' => 'Parado', 'category_id' => $cabelo->id, 'is_active' => false]);
        Service::factory()->create(['name' => 'Da categoria fechada', 'category_id' => $fechada->id]);
        Service::factory()->create(['name' => 'Avulso', 'category_id' => null]);

        $catalogo = app(ServiceCatalog::class);
        $agendavel = collect($catalogo->bookableByCategory())->map(fn ($g) => [$g['category']?->name, $g['services']->pluck('name')->all()])->all();
        $publico = collect($catalogo->publicByCategory())->map(fn ($g) => [$g['category']?->name, $g['services']->pluck('name')->all()])->all();

        $this->assertSame([
            ['Cabelo', ['Social', 'Degradê', 'Interno']],
            ['Barba', ['Barba simples']],
            [null, ['Avulso']],
        ], $agendavel);
        $this->assertSame([
            ['Cabelo', ['Social', 'Degradê']],
            ['Barba', ['Barba simples']],
            [null, ['Avulso']],
        ], $publico);
    }
}
