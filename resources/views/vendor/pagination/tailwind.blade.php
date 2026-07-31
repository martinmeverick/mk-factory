@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Stránkování">
        @if ($paginator->onFirstPage())
            <span class="page-link disabled" aria-disabled="true">‹ Předchozí</span>
        @else
            <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Předchozí</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page-link disabled">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page-link current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Další ›</a>
        @else
            <span class="page-link disabled" aria-disabled="true">Další ›</span>
        @endif
    </nav>
@endif
