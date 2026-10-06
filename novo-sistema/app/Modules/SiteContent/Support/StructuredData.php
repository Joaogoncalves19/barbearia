<?php

namespace App\Modules\SiteContent\Support;

use App\Modules\SiteContent\Services\PublicSite;

/**
 * Dados estruturados (schema.org, JSON-LD) do inicio (seo.md §4). So o que
 * existe de verdade: nome, endereco, telefone, horario (da agenda), imagem,
 * redes. Fica de fora o que nao temos ou que o Google nao aceita do proprio
 * negocio: faixa de preco inventada e nota das proprias avaliacoes
 * (aggregateRating "auto-servido").
 */
final class StructuredData
{
    /**
     * @return array<string, mixed>
     */
    public static function barberShop(PublicSite $site): array
    {
        $cfg = $site->settings();
        $dados = [
            '@context' => 'https://schema.org',
            '@type' => 'BarberShop',
            'name' => $cfg->name(),
            'url' => route('home'),
        ];
        if ($cfg->has('hero_subtitle')) {
            $dados['description'] = $cfg->get('hero_subtitle');
        }
        if ($cfg->has('address')) {
            $dados['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $cfg->get('address'), 'addressCountry' => 'BR'];
        }
        if ($cfg->has('phone')) {
            $dados['telephone'] = $cfg->get('phone');
        }
        if ($cfg->has('public_email')) {
            $dados['email'] = $cfg->get('public_email');
        }
        $foto = $site->image('hero') ?? $site->image('about');
        if ($foto !== null && $foto->url() !== null) {
            $dados['image'] = $foto->url();
        }
        $logo = $site->image('logo');
        if ($logo !== null && $logo->url() !== null) {
            $dados['logo'] = $logo->url();
        }
        $horario = $site->hours()->specification();
        if ($horario !== []) {
            $dados['openingHoursSpecification'] = array_map(fn ($h) => [
                '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $h['days'], 'opens' => $h['opens'], 'closes' => $h['closes'],
            ], $horario);
        }
        $redes = array_values(array_filter([$cfg->get('instagram'), $cfg->get('facebook')]));
        if ($redes !== []) {
            $dados['sameAs'] = $redes;
        }
        if ($cfg->mapsUrl() !== null && $cfg->has('address')) {
            $dados['hasMap'] = $cfg->mapsUrl();
        }

        return $dados;
    }

    /**
     * JSON seguro dentro de <script>: "</script>" nunca fecha a tag.
     *
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
