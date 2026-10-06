<?php

namespace App\Modules\SiteContent\Support;

use App\Modules\Identity\Models\User;
use App\Modules\System\Models\Setting;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Textos e contatos do site publico (site-publico.md §3), editados no painel
 * (site.manage). Tudo opcional: o que estiver vazio NAO aparece no site
 * (nada e inventado: sem endereco, horario, numero ou depoimento ficticio).
 *
 * Primeira leitura: aproveita o que veio do sistema antigo
 * (legacy.config_geral e legacy.landing_page), DESCARTANDO os textos padrao
 * dele ("Sua Barbearia", "Rua Exemplo, 123", "(00) 00000-0000", estatisticas
 * e o texto padrao de "Sobre"), e nunca os termos/politica antigos em HTML.
 */
final class SiteSettings
{
    public const KEY = 'site.content';

    /** @var array<string, array{label: string, max: int, type: string, hint?: string}> */
    public const FIELDS = [
        'name' => ['label' => 'Nome da barbearia', 'max' => 80, 'type' => 'text'],
        'tagline' => ['label' => 'Frase de marca (título do início)', 'max' => 90, 'type' => 'text', 'hint' => 'Uma linha. Ex.: o jeito da casa. Vazio: aparece o nome.'],
        'hero_subtitle' => ['label' => 'Subtítulo do início', 'max' => 220, 'type' => 'text'],
        'neighborhood' => ['label' => 'Bairro e cidade', 'max' => 60, 'type' => 'text', 'hint' => 'Aparece no topo: "Barbearia · Centro, Cidade".'],
        'about_title' => ['label' => 'Título da seção "A barbearia"', 'max' => 90, 'type' => 'text'],
        'about_text' => ['label' => 'Texto da seção "A barbearia"', 'max' => 1200, 'type' => 'textarea', 'hint' => 'Parágrafos separados por uma linha em branco.'],
        'highlights' => ['label' => 'Diferenciais (um por linha, até 4)', 'max' => 600, 'type' => 'textarea', 'hint' => 'Formato "Título: explicação". Só o que for verdade na casa.'],
        'address' => ['label' => 'Endereço', 'max' => 200, 'type' => 'text'],
        'address_note' => ['label' => 'Como chegar (referência, estacionamento)', 'max' => 200, 'type' => 'text'],
        'maps_url' => ['label' => 'Link do mapa (Google Maps)', 'max' => 300, 'type' => 'url', 'hint' => 'Opcional: sem ele, o botão abre a busca pelo endereço.'],
        'phone' => ['label' => 'Telefone', 'max' => 30, 'type' => 'phone'],
        'whatsapp' => ['label' => 'WhatsApp (DDD + número)', 'max' => 20, 'type' => 'whatsapp'],
        'public_email' => ['label' => 'E-mail de contato da barbearia', 'max' => 120, 'type' => 'email'],
        'instagram' => ['label' => 'Instagram (link do perfil)', 'max' => 200, 'type' => 'url'],
        'facebook' => ['label' => 'Facebook (link da página)', 'max' => 200, 'type' => 'url'],
        'cnpj' => ['label' => 'CNPJ (opcional, no rodapé)', 'max' => 18, 'type' => 'text'],
        'privacy_policy' => ['label' => 'Política de privacidade', 'max' => 20000, 'type' => 'textarea', 'hint' => 'Texto simples. Linhas começando com "## " viram títulos. Vazio: a página não é publicada.'],
        'terms' => ['label' => 'Termos de uso', 'max' => 20000, 'type' => 'textarea', 'hint' => 'Mesmo formato. Vazio: a página não é publicada.'],
    ];

    /** Hosts aceitos por campo de link (nada de link para qualquer lugar). */
    private const URL_HOSTS = [
        'maps_url' => ['google.com', 'www.google.com', 'maps.google.com', 'goo.gl', 'maps.app.goo.gl', 'maps.apple.com'],
        'instagram' => ['instagram.com', 'www.instagram.com'],
        'facebook' => ['facebook.com', 'www.facebook.com', 'm.facebook.com'],
    ];

    /** Textos padrao do sistema antigo: nunca viram conteudo do site. */
    private const LEGACY_PLACEHOLDERS = [
        'Sua Barbearia', 'Barbearia Fictícia', '(00) 00000-0000', 'Rua Exemplo, 123 - Centro, Sua Cidade', 'Nossa História',
        'Somos especialistas em cortes clássicos e modernos, além de cuidados completos com a barba. Nossa missão é elevar sua autoestima em um ambiente descontraído e confortável.',
        'Agende seu horário com os melhores profissionais da região e viva uma experiência premium.',
    ];

    /**
     * @param  array<string, string>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function current(): self
    {
        $salvo = Setting::valueOf(self::KEY, null);

        return new self(self::clean(is_array($salvo) ? $salvo : self::fromLegacy()));
    }

    public function get(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    public function has(string $field): bool
    {
        return $this->get($field) !== '';
    }

    /** Nome exibido: o configurado, ou o nome do sistema. */
    public function name(): string
    {
        return $this->has('name') ? $this->get('name') : (string) config('app.name');
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function whatsappUrl(): ?string
    {
        $n = preg_replace('/\D+/', '', $this->get('whatsapp')) ?? '';
        if ($n === '') {
            return null;
        }

        return 'https://wa.me/'.(strlen($n) <= 11 ? '55'.$n : $n);
    }

    public function phoneHref(): ?string
    {
        $n = preg_replace('/[^\d+]+/', '', $this->get('phone')) ?? '';

        return $n !== '' ? 'tel:'.$n : null;
    }

    public function mapsUrl(): ?string
    {
        if ($this->has('maps_url')) {
            return $this->get('maps_url');
        }

        return $this->has('address') ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->get('address')) : null;
    }

    /**
     * Diferenciais: "Titulo: explicacao", ate 4.
     *
     * @return list<array{title: string, text: string}>
     */
    public function highlights(): array
    {
        $itens = [];
        foreach (preg_split('/\R/', $this->get('highlights')) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            [$titulo, $texto] = array_pad(explode(':', $linha, 2), 2, '');
            $itens[] = ['title' => trim($titulo), 'text' => trim($texto)];
            if (count($itens) === 4) {
                break;
            }
        }

        return $itens;
    }

    /**
     * Texto longo em blocos (titulo "## " ou paragrafo). Sempre texto, nunca HTML.
     *
     * @return list<array{type: 'h'|'p', text: string}>
     */
    public function blocks(string $field): array
    {
        $blocos = [];
        $paragrafo = [];
        // Linha vazia ou titulo fecha o paragrafo em andamento; "" no fim fecha o ultimo.
        foreach ([...(preg_split('/\R/', $this->get($field)) ?: []), ''] as $linha) {
            $linha = trim($linha);
            $titulo = str_starts_with($linha, '## ');
            if (($linha === '' || $titulo) && $paragrafo !== []) {
                $blocos[] = ['type' => 'p', 'text' => implode("\n", $paragrafo)];
                $paragrafo = [];
            }
            if ($titulo) {
                $blocos[] = ['type' => 'h', 'text' => trim(substr($linha, 3))];
            } elseif ($linha !== '') {
                $paragrafo[] = $linha;
            }
        }

        return $blocos;
    }

    /**
     * Valida e grava (auditado: quais campos mudaram).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function save(array $input, ?User $actor): self
    {
        $regras = [];
        $nomes = [];
        foreach (self::FIELDS as $campo => $def) {
            $r = ['nullable', 'string', 'max:'.$def['max']];
            $r[] = match ($def['type']) {
                'url' => function (string $attr, mixed $v, \Closure $fail) use ($campo): void {
                    $host = strtolower((string) parse_url((string) $v, PHP_URL_HOST));
                    if (! str_starts_with((string) $v, 'https://') || ! in_array($host, self::URL_HOSTS[$campo] ?? [], true)) {
                        $fail('Use um link https de '.implode(', ', array_slice(self::URL_HOSTS[$campo] ?? [], 0, 2)).'.');
                    }
                },
                'email' => 'email:rfc',
                'whatsapp' => 'regex:/^\+?[\d\s().-]{10,20}$/',
                'phone' => 'regex:/^[\d\s()+.-]{8,30}$/',
                default => 'string',
            };
            $regras[$campo] = $r;
            $nomes[$campo] = mb_strtolower($def['label']);
        }
        $dados = Validator::make($input, $regras, [], $nomes)->validate();

        $antes = self::current()->toArray();
        $novo = self::clean($dados);
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $novo]);
        $mudou = array_keys(array_filter(self::FIELDS, fn ($d, $c) => ($antes[$c] ?? '') !== ($novo[$c] ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($mudou !== []) {
            AuditTrail::record('site.settings_changed', null, $actor, 'Conteúdo do site alterado: '.implode(', ', $mudou).'.', ['campos' => implode(', ', $mudou)]);
        }

        return new self($novo);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function clean(array $values): array
    {
        $saida = [];
        foreach (self::FIELDS as $campo => $def) {
            $v = $values[$campo] ?? '';
            $v = is_scalar($v) ? (string) $v : '';
            // Texto, nunca HTML: sem caracteres de controle (mantem quebras nos textos longos).
            $v = $def['type'] === 'textarea'
                ? (string) preg_replace('/[^\P{C}\n]/u', '', str_replace("\r\n", "\n", $v))
                : (string) preg_replace('/\p{C}+/u', ' ', $v);
            $saida[$campo] = mb_substr(trim($v), 0, $def['max']);
        }

        return $saida;
    }

    /**
     * @return array<string, string>
     */
    private static function fromLegacy(): array
    {
        $geral = Setting::valueOf('legacy.config_geral', []);
        $landing = Setting::valueOf('legacy.landing_page', []);
        $geral = is_array($geral) ? $geral : [];
        $landing = is_array($landing) ? $landing : [];
        $pega = function (array $origem, string $chave): string {
            $v = $origem[$chave] ?? '';
            $v = is_string($v) ? trim(strip_tags($v)) : '';

            return in_array($v, self::LEGACY_PLACEHOLDERS, true) ? '' : $v;
        };
        $insta = $pega($geral, 'link_instagram');
        $face = $pega($geral, 'link_facebook');

        return [
            'name' => $pega($geral, 'nome_barbearia'),
            'tagline' => $pega($geral, 'header_slogan'),
            'hero_subtitle' => $pega($landing, 'hero_subtitle'),
            'about_title' => $pega($landing, 'about_title'),
            'about_text' => $pega($landing, 'about_text'),
            'address' => $pega($geral, 'endereco'),
            'phone' => $pega($geral, 'telefone_contato'),
            'whatsapp' => $pega($geral, 'whatsapp_numero'),
            'public_email' => $pega($geral, 'email_contato'),
            'instagram' => str_starts_with($insta, 'https://') ? $insta : '',
            'facebook' => str_starts_with($face, 'https://') ? $face : '',
        ];
    }
}
