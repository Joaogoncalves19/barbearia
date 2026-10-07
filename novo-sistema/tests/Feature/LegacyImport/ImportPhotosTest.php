<?php

namespace Tests\Feature\LegacyImport;

use App\Modules\Customers\Models\Customer;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Achado do ensaio da Fase 13: foto migrada apontava para "uploads/..." do
 * sistema antigo (imagem quebrada no site). A foto generica padrao nao e
 * importada; as reais sao reprocessadas a partir da copia da pasta uploads/.
 */
class ImportPhotosTest extends TestCase
{
    use RefreshDatabase;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->uploads = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uploads-antigo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->uploads.'/barbeiros');
        $img = imagecreatetruecolor(320, 400);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 120, 90, 60));
        imagejpeg($img, $this->uploads.'/barbeiros/foto-real.jpg');
        imagedestroy($img);
        file_put_contents($this->uploads.'/barbeiros/corrompida.jpg', 'isto nao e imagem');
        file_put_contents($this->uploads.'/default-profile.jpg', 'generica');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploads);
        parent::tearDown();
    }

    public function test_foto_generica_do_sistema_antigo_nao_e_importada(): void
    {
        $this->assertNull(V::photoPath('uploads/default-profile.jpg'));
        $this->assertNull(V::photoPath(''));
        $this->assertSame('uploads/barbeiros/x.jpg', V::photoPath('uploads/barbeiros/x.jpg'));
    }

    public function test_reprocessa_as_fotos_reais_e_tira_as_quebradas(): void
    {
        $real = Professional::factory()->create(['photo_path' => 'uploads/barbeiros/foto-real.jpg']);
        $generica = Professional::factory()->create(['photo_path' => 'uploads/default-profile.jpg']);
        $sumiu = Professional::factory()->create(['photo_path' => 'uploads/barbeiros/nao-existe.jpg']);
        $corrompida = Customer::factory()->create(['photo_path' => 'uploads/barbeiros/corrompida.jpg']);
        $fora = Customer::factory()->create(['photo_path' => 'uploads/../../segredo.txt']);

        $this->artisan('legacy:import-photos', ['uploads' => $this->uploads, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('uploads/barbeiros/foto-real.jpg', $real->refresh()->photo_path, 'Simulação não altera nada');

        $this->artisan('legacy:import-photos', ['uploads' => $this->uploads])->expectsOutputToContain('Fotos importadas')->assertSuccessful();

        $novo = (string) $real->refresh()->photo_path;
        $this->assertNotNull(ImageStore::dimensions($novo), 'Formato novo (WebP com dimensões no nome)');
        $this->assertStringStartsWith('professionals/', $novo);
        Storage::disk('public')->assertExists($novo);
        foreach ([$generica, $sumiu, $corrompida, $fora] as $m) {
            $this->assertNull($m->refresh()->photo_path);
        }

        // Rodar de novo não refaz o que já está no formato novo.
        $this->artisan('legacy:import-photos', ['uploads' => $this->uploads])->assertSuccessful();
        $this->assertSame($novo, $real->refresh()->photo_path);
    }
}
