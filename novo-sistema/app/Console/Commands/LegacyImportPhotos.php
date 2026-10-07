<?php

namespace App\Console\Commands;

use App\Modules\Customers\Models\Customer;
use App\Modules\LegacyImport\Support\LegacyValue;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Team\Models\Professional;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Fotos do sistema antigo (Fase 13, achado do ensaio): o importador guarda o
 * caminho antigo ("uploads/..."); este comando le o arquivo de uma COPIA da
 * pasta uploads/ do sistema antigo e o reprocessa como qualquer foto nova
 * (WebP, tamanhos limitados, sem metadados; imagens.md). Foto ausente,
 * ilegivel ou a generica padrao: fica sem foto (o sistema mostra as
 * iniciais). So mexe em caminho antigo: rodar de novo nao refaz nada.
 *
 *     php artisan legacy:import-photos /caminho/copia/uploads
 */
#[Signature('legacy:import-photos {uploads : Pasta uploads/ copiada do sistema antigo} {--dry-run : Só mostra o que faria}')]
#[Description('Reprocessa as fotos de profissionais e clientes importados do sistema antigo')]
class LegacyImportPhotos extends Command
{
    public function handle(): int
    {
        $raiz = rtrim((string) $this->argument('uploads'), '/\\');
        if (! is_dir($raiz)) {
            $this->error('Pasta não encontrada: '.$raiz);

            return self::FAILURE;
        }
        $simular = (bool) $this->option('dry-run');
        $contagem = [];

        foreach ([[Professional::class, 'professionals'], [Customer::class, 'customers']] as [$classe, $pasta]) {
            $c = ['reprocessadas' => 0, 'sem_arquivo' => 0, 'genericas' => 0, 'ilegiveis' => 0, 'ja_no_formato_novo' => 0];
            $classe::query()->whereNotNull('photo_path')->orderBy('id')->each(function (Model $m) use ($raiz, $pasta, $simular, &$c): void {
                $atual = (string) $m->getAttribute('photo_path');
                if (ImageStore::dimensions($atual) !== null || str_starts_with($atual, $pasta.'/')) {
                    $c['ja_no_formato_novo']++;

                    return;
                }
                $novo = null;
                $arquivo = $this->legacyFile($raiz, $atual);
                if (LegacyValue::photoPath($atual) === null) {
                    $c['genericas']++;
                } elseif ($arquivo === null) {
                    $c['sem_arquivo']++;
                } elseif ($simular) {
                    $c['reprocessadas']++;

                    return;
                } else {
                    try {
                        $novo = ImageStore::store(new UploadedFile($arquivo, basename($arquivo), null, null, true), $pasta, 'portrait')->path;
                        $c['reprocessadas']++;
                    } catch (Throwable) {
                        $c['ilegiveis']++;
                    }
                }
                if (! $simular) {
                    $m->forceFill(['photo_path' => $novo])->saveQuietly();
                }
            });
            $contagem[$pasta] = $c;
        }

        $this->table(['', 'Reprocessadas', 'Arquivo ausente', 'Genérica (sem foto)', 'Ilegível (sem foto)', 'Já no formato novo'],
            array_map(fn ($c, $k) => [$k, ...array_values($c)], $contagem, array_keys($contagem)));
        $this->info($simular ? 'Simulação: nada foi alterado.' : 'Fotos importadas.');

        return self::SUCCESS;
    }

    /** Arquivo dentro da pasta de uploads (nunca fora dela). */
    private function legacyFile(string $raiz, string $caminho): ?string
    {
        $relativo = preg_replace('#^/?uploads/#', '', str_replace('\\', '/', $caminho)) ?? '';
        if ($relativo === '' || str_contains($relativo, '..')) {
            return null;
        }
        $arquivo = realpath($raiz.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativo));
        $base = realpath($raiz);

        return $arquivo !== false && $base !== false && str_starts_with($arquivo, $base.DIRECTORY_SEPARATOR) && is_file($arquivo) ? $arquivo : null;
    }
}
