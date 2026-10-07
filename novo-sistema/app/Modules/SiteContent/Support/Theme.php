<?php

namespace App\Modules\SiteContent\Support;

/**
 * Tema visual predefinido (temas-visuais.md). O tema e so APARENCIA: troca
 * tokens de cor, tipografia, cantos e o elemento grafico; nunca a estrutura
 * das telas, os textos ou qualquer regra. As cores e fontes de verdade ficam
 * em resources/css/themes/{chave}.css; aqui ficam o nome, a descricao, a
 * amostra da paleta (para a tela Aparencia) e qual superficie (clara ou
 * escura) cada parte da interface usa neste tema.
 *
 * Adicionar um tema: uma entrada em THEMES + um arquivo CSS em
 * resources/css/themes/ importado em themes/index.css (ver a documentacao).
 */
final class Theme
{
    public const DEFAULT = 'oficio';

    /** Partes da interface que escolhem a superficie pelo tema. */
    public const ROLES = ['site', 'band', 'panel', 'sidebar', 'auth'];

    /**
     * @var array<string, array{
     *     name: string, palette: string, description: string, type: string, motif: string,
     *     swatches: list<array{0: string, 1: string}>,
     *     surfaces: array<string, 'clara'|'escura'>,
     *     colors: array{clara: string, escura: string}
     * }>
     */
    public const THEMES = [
        'oficio' => [
            'name' => 'Ofício',
            'palette' => 'Grafite · Osso · Latão',
            'description' => 'Editorial e contemporâneo. Contraste alto, pouca cor, títulos condensados e a régua como assinatura.',
            'type' => 'Archivo condensada',
            'motif' => 'Régua técnica',
            'swatches' => [['Grafite', '#121211'], ['Osso', '#f5f2ec'], ['Latão', '#b5814a'], ['Pedra', '#625c55']],
            'surfaces' => ['site' => 'escura', 'band' => 'clara', 'panel' => 'clara', 'sidebar' => 'escura', 'auth' => 'escura'],
            'colors' => ['clara' => '#f5f2ec', 'escura' => '#121211'],
        ],
        'black-label' => [
            'name' => 'Black Label',
            'palette' => 'Preto · Marfim · Ouro velho',
            'description' => 'Luxo discreto, de noite. Preto profundo do site ao painel, serifa alta e o ouro só em detalhes.',
            'type' => 'Cormorant',
            'motif' => 'Fio de ouro',
            'swatches' => [['Preto', '#0a0a0a'], ['Carvão', '#1c1b19'], ['Ouro velho', '#b39a63'], ['Marfim', '#f6f2e9']],
            'surfaces' => ['site' => 'escura', 'band' => 'escura', 'panel' => 'escura', 'sidebar' => 'escura', 'auth' => 'escura'],
            'colors' => ['clara' => '#f6f2e9', 'escura' => '#0a0a0a'],
        ],
        'gentlemans-club' => [
            'name' => "Gentleman's Club",
            'palette' => 'Verde garrafa · Creme · Castanho',
            'description' => 'Clássico e elegante, de clube. Verde garrafa, creme, serifa tradicional e filetes duplos.',
            'type' => 'Newsreader',
            'motif' => 'Filete duplo',
            'swatches' => [['Verde garrafa', '#15271f'], ['Creme', '#f5efe2'], ['Castanho', '#6b4429'], ['Dourado', '#b39150']],
            'surfaces' => ['site' => 'escura', 'band' => 'clara', 'panel' => 'clara', 'sidebar' => 'escura', 'auth' => 'escura'],
            'colors' => ['clara' => '#f5efe2', 'escura' => '#0f1c17'],
        ],
        'urban-barber' => [
            'name' => 'Urban Barber',
            'palette' => 'Petróleo · Areia · Cobre',
            'description' => 'Urbano e jovem. Azul petróleo, areia, títulos largos e geometria reta, sem cantos arredondados.',
            'type' => 'Archivo expandida',
            'motif' => 'Blocos geométricos',
            'swatches' => [['Petróleo', '#11252c'], ['Areia', '#f3efe7'], ['Cobre', '#c97f4b'], ['Grafite', '#30545f']],
            'surfaces' => ['site' => 'escura', 'band' => 'clara', 'panel' => 'clara', 'sidebar' => 'escura', 'auth' => 'escura'],
            'colors' => ['clara' => '#f3efe7', 'escura' => '#0c1a1f'],
        ],
        'old-school' => [
            'name' => 'Old School',
            'palette' => 'Terracota · Papel envelhecido · Marrom',
            'description' => 'Retrô e artesanal, como impressão tradicional. Papel claro, letra serifada de cartaz e filetes de gráfica.',
            'type' => 'Roboto Slab',
            'motif' => 'Filete de impressão',
            'swatches' => [['Terracota', '#b5532f'], ['Papel', '#f2e9d7'], ['Marrom', '#2d1f16'], ['Creme', '#f9f4e8']],
            'surfaces' => ['site' => 'clara', 'band' => 'escura', 'panel' => 'clara', 'sidebar' => 'escura', 'auth' => 'escura'],
            'colors' => ['clara' => '#f2e9d7', 'escura' => '#21160f'],
        ],
        'red-barber' => [
            'name' => 'Red Barber',
            'palette' => 'Vinho · Preto · Creme · Cobre',
            'description' => 'Forte e marcante. Preto com vinho nas ações, títulos pesados e estreitos; o vermelho não toma conta.',
            'type' => 'Archivo extra-condensada',
            'motif' => 'Traço forte',
            'swatches' => [['Vinho', '#9c2731'], ['Preto', '#120c0d'], ['Creme', '#f6f1e9'], ['Cobre', '#dca06f']],
            'surfaces' => ['site' => 'escura', 'band' => 'clara', 'panel' => 'clara', 'sidebar' => 'escura', 'auth' => 'escura'],
            'colors' => ['clara' => '#f6f1e9', 'escura' => '#120c0d'],
        ],
        'minimal' => [
            'name' => 'Minimal',
            'palette' => 'Branco quente · Cinza · Grafite',
            'description' => 'Limpo e discreto. Tudo claro, quase nenhum elemento gráfico: a tipografia, o espaço e as fotos fazem o trabalho.',
            'type' => 'Inter',
            'motif' => 'Nenhum',
            'swatches' => [['Branco quente', '#f8f7f4'], ['Cinza', '#8c8a85'], ['Grafite', '#1e1e1e'], ['Champanhe', '#a8926a']],
            'surfaces' => ['site' => 'clara', 'band' => 'clara', 'panel' => 'clara', 'sidebar' => 'clara', 'auth' => 'clara'],
            'colors' => ['clara' => '#f8f7f4', 'escura' => '#151515'],
        ],
        'copper-club' => [
            'name' => 'Copper Club',
            'palette' => 'Café · Creme · Cobre · Carvão',
            'description' => 'Artesanal e quente, de couro e metal trabalhado. Marrom café, cobre e a costura como detalhe.',
            'type' => 'Roboto Slab',
            'motif' => 'Costura',
            'swatches' => [['Café', '#221914'], ['Creme', '#f6f0e6'], ['Cobre', '#c67a43'], ['Carvão', '#1f1f1e']],
            'surfaces' => ['site' => 'escura', 'band' => 'clara', 'panel' => 'clara', 'sidebar' => 'escura', 'auth' => 'escura'],
            'colors' => ['clara' => '#f6f0e6', 'escura' => '#18120e'],
        ],
    ];

    private function __construct(public readonly string $key) {}

    public static function find(string $key): ?self
    {
        return isset(self::THEMES[$key]) ? new self($key) : null;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(fn (string $k) => new self($k), array_keys(self::THEMES));
    }

    /**
     * Tema desta requisicao: a previa (so na tela de previa do painel) ou o
     * tema escolhido pela barbearia.
     */
    public static function active(): self
    {
        $previa = request()->attributes->get(Appearance::PREVIEW_ATTRIBUTE);
        if (is_string($previa) && ($tema = self::find($previa)) !== null) {
            return $tema;
        }

        return Appearance::current()->theme();
    }

    public function name(): string
    {
        return self::THEMES[$this->key]['name'];
    }

    public function palette(): string
    {
        return self::THEMES[$this->key]['palette'];
    }

    public function description(): string
    {
        return self::THEMES[$this->key]['description'];
    }

    public function typeface(): string
    {
        return self::THEMES[$this->key]['type'];
    }

    public function motif(): string
    {
        return self::THEMES[$this->key]['motif'];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function swatches(): array
    {
        return self::THEMES[$this->key]['swatches'];
    }

    /**
     * Superficie de uma parte da interface (site, faixa do site, painel,
     * barra lateral, painel de acesso). Aceita tambem 'clara'/'escura' direto.
     *
     * @return 'clara'|'escura'
     */
    public function surface(string $role): string
    {
        if ($role === 'clara' || $role === 'escura') {
            return $role;
        }

        return self::THEMES[$this->key]['surfaces'][$role] ?? 'clara';
    }

    /** Cor da barra do navegador no celular (meta theme-color). */
    public function browserColor(string $surface): string
    {
        return self::THEMES[$this->key]['colors'][$surface === 'escura' ? 'escura' : 'clara'];
    }

    public function is(self $other): bool
    {
        return $this->key === $other->key;
    }
}
