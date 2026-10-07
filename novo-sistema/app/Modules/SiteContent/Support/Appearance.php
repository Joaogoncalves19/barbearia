<?php

namespace App\Modules\SiteContent\Support;

use App\Modules\Identity\Models\User;
use App\Modules\System\Models\Setting;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Validation\ValidationException;

/**
 * Aparencia da barbearia (temas-visuais.md): hoje, so o tema predefinido.
 * Guardada na tabela de configuracoes (sem tabela nova) como um objeto, para
 * que a personalizacao futura (cores proprias, fontes, favicon) entre como
 * uma camada POR CIMA do tema, sem migrar o que ja foi salvo.
 *
 * So o proprietario troca (settings.manage); a troca e auditada. Tema
 * desconhecido no banco (removido do catalogo, valor estranho) volta para o
 * padrao em vez de quebrar a pagina.
 */
final class Appearance
{
    public const KEY = 'appearance';

    /** Atributo da requisicao que liga a previa de um tema (so na tela de previa). */
    public const PREVIEW_ATTRIBUTE = 'appearance.preview';

    private static ?self $cache = null;

    private function __construct(private readonly Theme $theme) {}

    public static function current(): self
    {
        if (self::$cache !== null && ! app()->runningUnitTests()) {
            return self::$cache;
        }
        try {
            $salvo = Setting::valueOf(self::KEY, []);
        } catch (\Throwable) {
            $salvo = []; // pagina de erro sem banco: tema padrao, nunca uma segunda falha
        }
        $chave = is_array($salvo) && is_string($salvo['theme'] ?? null) ? $salvo['theme'] : Theme::DEFAULT;

        return self::$cache = new self(Theme::find($chave) ?? Theme::default());
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    /**
     * @throws ValidationException
     */
    public static function chooseTheme(string $key, User $actor): Theme
    {
        $tema = Theme::find($key);
        if ($tema === null) {
            throw ValidationException::withMessages(['theme' => 'Escolha um dos temas da lista.']);
        }
        $antes = self::current()->theme();
        $salvo = Setting::valueOf(self::KEY, []);
        $valor = array_merge(is_array($salvo) ? $salvo : [], ['theme' => $tema->key]);
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $valor]);
        self::$cache = null;

        if (! $antes->is($tema)) {
            AuditTrail::record('appearance.theme_changed', null, $actor, 'Tema visual alterado de '.$antes->name().' para '.$tema->name().'.', [
                'tema_anterior' => $antes->key, 'tema_novo' => $tema->key,
            ]);
        }

        return $tema;
    }
}
