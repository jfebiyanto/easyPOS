@if ($paginator->hasPages())
    <nav class="pagination">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="disabled"><span>&laquo;</span></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo;</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="disabled"><span>{{ $element }}</span></span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="active"><span>{{ $page }}</span></span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">&raquo;</a>
        @else
            <span class="disabled"><span>&raquo;</span></span>
        @endif
    </nav>
@endif