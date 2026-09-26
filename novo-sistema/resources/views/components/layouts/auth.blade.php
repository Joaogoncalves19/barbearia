@props(['title' => null])
<x-layouts.document :title="$title" surface="clara" area="panel" :noindex="true">
    <main id="conteudo" class="auth-page">
        <div class="auth-card stack stack-lg">
            <span class="brand">
                <span class="brand__mark" aria-hidden="true">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </span>
            {{ $slot }}
        </div>
    </main>
</x-layouts.document>
