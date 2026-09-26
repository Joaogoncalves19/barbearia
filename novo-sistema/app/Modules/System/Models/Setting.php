<?php

namespace App\Modules\System\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuracao NAO sensivel. Segredos (senhas, chaves de API, tokens,
 * segredos de webhook) ficam apenas no .env: gravar uma chave com cara de
 * segredo e recusado aqui e o importador descarta as secoes sensiveis.
 */
class Setting extends Model
{
    /** Nomes de campo que indicam segredo, em qualquer nivel do valor. */
    public const SECRET_KEY_PATTERN = '/(pass(word)?|senha|secret|segredo|token|api[_-]?keys?|_keys?$|^keys?$|private|credential)/i';

    protected $table = 'settings';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $s): void {
            $segredos = static::secretPaths([$s->key => $s->value]);
            if ($segredos !== []) {
                throw DomainRuleViolation::rule('R-SEGREDO', 'Configuracao parece conter segredo: '.implode(', ', $segredos).'. Use o .env.');
            }
        });
    }

    /**
     * Caminhos (a.b.c) cujo nome de campo parece segredo.
     *
     * @param  array<array-key, mixed>  $dados
     * @return list<string>
     */
    public static function secretPaths(array $dados, string $prefixo = ''): array
    {
        $achados = [];
        foreach ($dados as $chave => $valor) {
            $caminho = $prefixo === '' ? (string) $chave : $prefixo.'.'.$chave;
            if (is_string($chave) && preg_match(self::SECRET_KEY_PATTERN, $chave)) {
                $achados[] = $caminho;
            }
            if (is_array($valor)) {
                array_push($achados, ...static::secretPaths($valor, $caminho));
            }
        }

        return $achados;
    }

    public static function valueOf(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }
}
