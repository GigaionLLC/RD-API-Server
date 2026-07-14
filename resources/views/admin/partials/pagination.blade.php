@if ($paginator->hasPages())
    <nav class="rd-pagination" aria-label="Pagination">
        <div class="rd-pagination__meta">
            Showing {{ $paginator->firstItem() ?? 0 }}&ndash;{{ $paginator->lastItem() ?? 0 }}
            of {{ $paginator->total() }}
        </div>

        <div class="rd-pagination__controls">
            @if ($paginator->onFirstPage())
                <button class="rd-btn rd-btn--ghost" type="button" disabled>
                    <i class="ri-arrow-left-s-line" aria-hidden="true"></i> Previous
                </button>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="rd-btn rd-btn--ghost" rel="prev">
                    <i class="ri-arrow-left-s-line" aria-hidden="true"></i> Previous
                </a>
            @endif

            <span class="rd-pagination__meta" aria-current="page">
                Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="rd-btn rd-btn--ghost" rel="next">
                    Next <i class="ri-arrow-right-s-line" aria-hidden="true"></i>
                </a>
            @else
                <button class="rd-btn rd-btn--ghost" type="button" disabled>
                    Next <i class="ri-arrow-right-s-line" aria-hidden="true"></i>
                </button>
            @endif
        </div>
    </nav>
@endif
