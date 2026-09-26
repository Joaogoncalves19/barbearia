<?php

namespace App\View\Components;

use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * Icone SVG inline a partir de resources/icons (copiados do Lucide, ISC).
 * Decorativo por padrao (aria-hidden). Para icone com significado proprio,
 * passe `label` e ele vira role="img" com nome acessivel.
 *
 *   <x-icon name="scissors" />
 *   <x-icon name="bell" label="Notificações" />
 */
class Icon extends Component
{
    /** @var array<string, string> */
    private static array $cache = [];

    public function __construct(
        public string $name,
        public ?string $label = null,
    ) {}

    public function svg(): string
    {
        if (! isset(self::$cache[$this->name])) {
            $arquivo = resource_path('icons/'.basename($this->name).'.svg');
            if (! is_file($arquivo)) {
                throw new InvalidArgumentException("Icone inexistente: {$this->name}. Rode scripts/copiar-icones.mjs.");
            }
            self::$cache[$this->name] = trim((string) file_get_contents($arquivo));
        }

        return self::$cache[$this->name];
    }

    public function render(): string
    {
        return <<<'BLADE'
<svg {{ $attributes->class(['icon']) }} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" @if($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>{!! $svg() !!}</svg>
BLADE;
    }
}
