<?php

namespace App\Modules\SiteContent\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\ServiceCatalog;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\SiteContent\Models\SiteImage;
use App\Modules\SiteContent\Support\OpeningHours;
use App\Modules\SiteContent\Support\SiteSettings;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Services\SubscriptionCheckout;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Illuminate\Database\Eloquent\Collection;

/**
 * O que o site publico mostra (site-publico.md §2). So LEITURA, sempre das
 * mesmas regras de dominio do painel e da agenda (ServiceCatalog,
 * ProfessionalDirectory, planos, avaliacoes aprovadas): nenhuma regra de
 * servico, equipe ou disponibilidade e repetida aqui. Nada e inventado: cada
 * bloco devolve vazio/nulo quando nao ha dado real.
 */
final class PublicSite
{
    /** Avaliacoes publicadas a partir das quais a nota media aparece. */
    public const MIN_REVIEWS_FOR_RATING = 3;

    /** Fotos a partir das quais a galeria aparece. */
    public const MIN_GALLERY = 3;

    public function __construct(
        private readonly ServiceCatalog $catalog,
        private readonly ProfessionalDirectory $directory,
        private readonly OpeningHours $hours,
    ) {}

    public function settings(): SiteSettings
    {
        return SiteSettings::current();
    }

    public function hours(): OpeningHours
    {
        return $this->hours;
    }

    public function image(string $kind): ?SiteImage
    {
        return SiteImage::query()->shown($kind)->first();
    }

    /**
     * @return Collection<int, SiteImage>
     */
    public function images(string $kind): Collection
    {
        return SiteImage::query()->shown($kind)->get();
    }

    /**
     * @return Collection<int, SiteImage>
     */
    public function gallery(): Collection
    {
        $fotos = $this->images('gallery');

        return $fotos->count() >= self::MIN_GALLERY ? $fotos : new Collection;
    }

    /**
     * Catalogo do site (ativos, de categoria ativa, publicos), por categoria.
     *
     * @return list<array{category: ?ServiceCategory, services: Collection<int, Service>}>
     */
    public function services(): array
    {
        return $this->catalog->publicByCategory();
    }

    /**
     * Destaques do inicio: os marcados como destaque; sem destaque, os
     * primeiros do catalogo (ate 6).
     *
     * @return Collection<int, Service>
     */
    public function featuredServices(): Collection
    {
        $destaques = Service::query()->shownPublicly()->where('services.is_featured', true)->with('category')->ordered()->limit(6)->get();

        return $destaques->isNotEmpty() ? $destaques : Service::query()->shownPublicly()->with('category')->ordered()->limit(6)->get();
    }

    /**
     * Equipe do site: ativos, que recebem agendamento e publicos.
     *
     * @return Collection<int, Professional>
     */
    public function team(): Collection
    {
        return $this->directory->publicTeam();
    }

    public function professional(string $slug): ?Professional
    {
        return $this->team()->first(fn (Professional $p) => $p->slug === $slug);
    }

    /**
     * Servicos do site que o profissional faz (para "agendar com").
     *
     * @return Collection<int, Service>
     */
    public function servicesOf(Professional $professional): Collection
    {
        return $this->directory->bookableServicesOf($professional)->filter(fn (Service $s) => $s->is_public)->values();
    }

    /**
     * Planos ativos com versao atual (D-03: assinaturas mantidas).
     *
     * @return Collection<int, Plan>
     */
    public function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->with(['currentVersion.services'])->orderBy('name')->get()
            ->filter(fn (Plan $p) => $p->currentVersion !== null)->values();
    }

    public function onlineSignup(): bool
    {
        return app(SubscriptionCheckout::class)->available();
    }

    /**
     * Nota media e quantidade de avaliacoes PUBLICADAS (reais).
     *
     * @return array{average: float, count: int}|null
     */
    public function rating(): ?array
    {
        $q = Review::query()->where('status', ReviewStatus::Approved->value);
        $n = (clone $q)->count();
        if ($n < self::MIN_REVIEWS_FOR_RATING) {
            return null;
        }

        return ['average' => round((float) $q->avg('rating'), 1), 'count' => $n];
    }

    /**
     * Avaliacoes destacadas pela equipe (aprovadas, com comentario), ate 3.
     *
     * @return Collection<int, Review>
     */
    public function featuredReviews(): Collection
    {
        return Review::query()->where('status', ReviewStatus::Approved->value)->where('is_featured', true)
            ->whereNotNull('comment')->where('comment', '<>', '')
            ->with(['customer:id,name', 'professional:id,display_name'])->orderByDesc('reviewed_at')->orderByDesc('id')->limit(3)->get();
    }

    /** Ate duas iniciais, so de palavras que comecam com letra ("Joao (Navalha)" -> "JN"). */
    public static function initials(string $name): string
    {
        preg_match_all('/(?<![\p{L}\p{N}])\p{L}/u', $name, $m);

        return mb_strtoupper(implode('', array_slice($m[0], 0, 2)));
    }

    /** "Maria S." (primeiro nome e inicial): nunca nome completo, e-mail ou outro dado. */
    public static function reviewerName(Review $review): string
    {
        $partes = preg_split('/\s+/', trim((string) ($review->customer->name ?? ''))) ?: [];
        $primeiro = $partes[0] ?? '';
        if ($primeiro === '') {
            return 'Cliente';
        }
        $ultimo = count($partes) > 1 ? mb_substr((string) end($partes), 0, 1).'.' : '';

        return trim($primeiro.' '.$ultimo);
    }
}
