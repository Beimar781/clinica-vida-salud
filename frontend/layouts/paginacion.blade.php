{{-- Paginación común del sistema (Paginator::defaultView), con textos en español --}}
@if ($paginator->hasPages())
    <nav class="paginacion" role="navigation" aria-label="Paginación">
        @if ($paginator->onFirstPage())
            <span aria-disabled="true">« Anterior</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">« Anterior</a>
        @endif

        @foreach ($elements as $elemento)
            @if (is_string($elemento))
                <span>{{ $elemento }}</span>
            @else
                @foreach ($elemento as $pagina => $url)
                    @if ($pagina == $paginator->currentPage())
                        <span class="actual" aria-current="page">{{ $pagina }}</span>
                    @else
                        <a href="{{ $url }}" aria-label="Ir a la página {{ $pagina }}">{{ $pagina }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente »</a>
        @else
            <span aria-disabled="true">Siguiente »</span>
        @endif
    </nav>
@endif
