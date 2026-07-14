@extends('layouts.admin')
@section('title', 'Address Books')

@php($canEdit = auth()->user()?->hasPermission('address_books.edit') ?? false)

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">People &amp; Access</p>
            <h1 class="rd-page-header__title">Address Books</h1>
            <p class="rd-page-header__description">Review shared contact collections, their owners, and the peers they contain.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="Address books" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Owner</th>
                        <th>Peers</th>
                        <th>Tags</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($addressBooks as $book)
                    <tr>
                        <td><span class="rd-table__primary">{{ $book->name ?: 'Default' }}</span></td>
                        <td class="rd-muted">{{ $book->user->username ?? '—' }}</td>
                        <td class="rd-muted">{{ $book->peers_count }}</td>
                        <td class="rd-muted">{{ $book->tags_count }}</td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                <a href="{{ route('admin.address-books.show', $book) }}" class="rd-btn rd-btn--ghost"><i class="ri-eye-line" aria-hidden="true"></i> View</a>
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('admin.address-books.destroy', $book) }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete this address book and all its peers/tags?" aria-label="Delete {{ $book->name ?: 'Default' }} address book" title="Delete address book"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="rd-empty">
                                <i class="rd-empty__icon ri-contacts-book-2-line" aria-hidden="true"></i>
                                <p class="rd-empty__title">No address books yet</p>
                                <p class="rd-empty__body">Shared address books will appear here.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $addressBooks])
    </div>
@endsection
