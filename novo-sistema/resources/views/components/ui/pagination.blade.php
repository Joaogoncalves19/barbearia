{{--
    Paginacao padrao (Paginator::defaultView). Recebe $paginator do Laravel.
--}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Paginação">
        <p class="text-muted">
            @if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
            @else
                Página {{ $paginator->currentPage() }}
            @endif
        </p>
        <div class="pagination__pages">
            @if ($paginator->onFirstPage())
                <span class="pagination__link" aria-disabled="true"><x-icon name="chevron-left" label="Anterior" /></span>
            @else
                <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="chevron-left" label="Anterior" /></a>
            @endif

            @isset($elements)
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="pagination__link" aria-disabled="true">{{ $element }}</span>
                    @endif
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="pagination__link" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="pagination__link" href="{{ $url }}" aria-label="Página {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            @endisset

            @if ($paginator->hasMorePages())
                <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next"><x-icon name="chevron-right" label="Próxima" /></a>
            @else
                <span class="pagination__link" aria-disabled="true"><x-icon name="chevron-right" label="Próxima" /></span>
            @endif
        </div>
    </nav>
@endif
