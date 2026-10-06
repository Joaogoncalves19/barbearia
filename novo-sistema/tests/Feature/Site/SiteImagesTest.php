<?php

namespace Tests\Feature\Site;

use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Media\ImageProcessor;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\SiteContent\Models\SiteImage;
use App\Modules\SiteContent\Support\SiteSettings;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Imagens e conteudo do site (Fase 11; imagens.md): todo envio e
 * decodificado e reprocessado (WebP, tamanhos limitados, sem metadados,
 * orientacao aplicada); tipo conferido pelo conteudo; nada executavel;
 * texto alternativo obrigatorio; permissoes e auditoria.
 */
class SiteImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->gerente = User::factory()->manager()->create();
    }

    /** JPEG real (GD) com segmento EXIF: orientacao e um "GPS" ficticio. */
    private function jpegComExif(int $w, int $h, int $orientation): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 180, 120, 60));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        $tiff = 'II*'."\0".pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0".pack('V', 0).'GPS-FICTICIO-LAT-23.55';
        $app1 = "Exif\0\0".$tiff;
        $arquivo = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
        $caminho = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($caminho, $arquivo);

        return new UploadedFile($caminho, 'foto-do-celular.jpg', 'image/jpeg', null, true);
    }

    private function enviar(UploadedFile $file, string $kind = 'hero', string $alt = 'Cadeiras de barbeiro com luz quente'): TestResponse
    {
        return $this->actingAs($this->gerente)->post(route('panel.site.images.store'), ['kind' => $kind, 'image' => $file, 'alt' => $alt]);
    }

    public function test_envio_vira_webp_em_tamanhos_sem_metadados_e_com_orientacao(): void
    {
        $this->assertSame(6, ImageProcessor::exifOrientation($this->jpegComExif(400, 300, 6)->getRealPath()));

        $this->enviar($this->jpegComExif(3000, 1000, 6))->assertSessionHas('status');

        $img = SiteImage::query()->sole();
        // Girada (orientacao 6): 3000x1000 vira 1000x3000; limite do topo e 2400 de largura (nao aumenta).
        $this->assertSame([1000, 3000], [$img->width, $img->height]);
        $this->assertMatchesRegularExpression('#^site/hero/[0-9a-z]{26}\.1000x3000\.webp$#', $img->path);
        $disco = Storage::disk('public');
        $this->assertCount(3, $disco->files('site/hero'), 'principal + 480w + 960w');
        $bytes = (string) $disco->get($img->path);
        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertSame('WEBP', substr($bytes, 8, 4));
        $this->assertStringNotContainsString('GPS-FICTICIO', $bytes, 'metadados descartados');
        $this->assertStringNotContainsString('foto-do-celular', $img->path, 'nome nunca vem do envio');
        $this->assertStringContainsString('480w', ImageStore::srcset($img->path));
        $this->assertSame(1, AuditLog::query()->where('action', 'site.image_uploaded')->count());

        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('fetchpriority="high"', $html);
        $this->assertStringContainsString('width="1000" height="3000"', $html);
        $this->assertStringContainsString('alt="Cadeiras de barbeiro com luz quente"', $html);
    }

    public function test_arquivo_disfarcado_svg_e_texto_sao_recusados(): void
    {
        $poliglota = tempnam(sys_get_temp_dir(), 'pg');
        file_put_contents($poliglota, "\xFF\xD8\xFF\xE0".'<?php echo "invadido"; ?>');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $php = UploadedFile::fake()->createWithContent('foto.jpg', '<?php phpinfo();');

        foreach ([new UploadedFile($poliglota, 'foto.jpg', 'image/jpeg', null, true), $svg, $php, UploadedFile::fake()->image('mini.png', 50, 50)] as $f) {
            $this->enviar($f)->assertSessionHasErrors('image');
        }
        $this->assertSame(0, SiteImage::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_descricao_obrigatoria_limite_por_tipo_ordem_e_remocao(): void
    {
        $this->enviar(UploadedFile::fake()->image('a.png', 800, 600), 'logo', '')->assertSessionHasErrors('alt');
        $this->enviar(UploadedFile::fake()->image('a.png', 800, 600), 'logo', 'Logo da barbearia')->assertSessionHas('status');
        $this->enviar(UploadedFile::fake()->image('b.png', 800, 600), 'logo', 'Outro logo')->assertSessionHasErrors('image');

        foreach (['g1', 'g2', 'g3'] as $i) {
            $this->enviar(UploadedFile::fake()->image($i.'.jpg', 900, 900), 'gallery', 'Trabalho '.$i)->assertSessionHas('status');
        }
        $galeria = SiteImage::query()->where('kind', 'gallery')->orderBy('sort_order')->get();
        $this->actingAs($this->gerente)->post(route('panel.site.images.move', $galeria[2]), ['direction' => 'up']);
        $this->assertSame(['Trabalho g1', 'Trabalho g3', 'Trabalho g2'], SiteImage::query()->where('kind', 'gallery')->orderBy('sort_order')->pluck('alt')->all());
        $this->get('/')->assertSee('Trabalhos da casa')->assertSee('class="brand__logo"', false); // o alt do logo e o nome da barbearia

        $this->actingAs($this->gerente)->put(route('panel.site.images.update', $galeria[0]), ['alt' => 'Trabalho g1', 'is_active' => 0]);
        $this->get('/')->assertDontSee('Trabalhos da casa', false); // galeria so com 3 ou mais ativas

        $caminho = $galeria[1]->path;
        $this->actingAs($this->gerente)->delete(route('panel.site.images.destroy', $galeria[1]))->assertSessionHas('status');
        $this->assertFalse(Storage::disk('public')->exists($caminho));
        $this->assertSame([], array_filter(Storage::disk('public')->allFiles(), fn ($f) => str_starts_with($f, substr($caminho, 0, -15))));
        $this->assertSame(1, AuditLog::query()->where('action', 'site.image_deleted')->count());
    }

    public function test_so_quem_pode_editar_o_site(): void
    {
        foreach ([User::factory()->create(), User::factory()->role(StaffRole::Finance)->create(), User::factory()->role(StaffRole::Professional)->create()] as $u) {
            $this->actingAs($u)->get(route('panel.site.content'))->assertForbidden();
            $this->actingAs($u)->post(route('panel.site.images.store'), ['kind' => 'hero', 'image' => UploadedFile::fake()->image('x.png', 800, 600), 'alt' => 'x'])->assertForbidden();
        }
        $this->actingAs($this->gerente)->get(route('panel.site.content'))->assertOk();
        $this->actingAs($this->gerente)->get(route('panel.site.images'))->assertOk();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_conteudo_validado_texto_nunca_html_e_auditado(): void
    {
        $this->actingAs($this->gerente)->put(route('panel.site.content.update'), ['instagram' => 'https://evil.example/instagram'])->assertSessionHasErrors('instagram');
        $this->actingAs($this->gerente)->put(route('panel.site.content.update'), ['instagram' => 'http://instagram.com/x'])->assertSessionHasErrors('instagram');
        $this->actingAs($this->gerente)->put(route('panel.site.content.update'), ['whatsapp' => 'abc'])->assertSessionHasErrors('whatsapp');

        $this->actingAs($this->gerente)->put(route('panel.site.content.update'), [
            'name' => 'Barbearia Teste', 'about_text' => '<img src=x onerror=alert(1)> Nossa casa', 'about_title' => 'Sobre', 'whatsapp' => '(11) 90000-0000',
            'instagram' => 'https://www.instagram.com/barbearia.teste',
        ])->assertRedirect(route('panel.site.content'));

        $this->assertSame('11900000000', preg_replace('/\D/', '', SiteSettings::current()->get('whatsapp')));
        $html = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; Nossa casa', $html);
        $this->assertStringContainsString('https://wa.me/5511900000000', $html);
        $this->assertSame(1, AuditLog::query()->where('action', 'site.settings_changed')->count());
    }

    public function test_imagem_de_servico_tambem_e_reprocessada_e_aparece_com_srcset(): void
    {
        $s = Service::factory()->create(['name' => 'Corte Foto', 'is_public' => true]);
        $s->forceFill(['image_path' => ImageStore::store(UploadedFile::fake()->image('corte.jpg', 2000, 1500), 'services', 'card')->path])->save();

        $this->assertMatchesRegularExpression('#^services/[0-9a-z]{26}\.1200x900\.webp$#', (string) $s->image_path);
        $this->get(route('site.services'))->assertSee('Corte Foto')->assertSee('480w', false);
    }
}
