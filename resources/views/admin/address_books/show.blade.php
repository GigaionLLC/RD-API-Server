@extends('layouts.admin')
@section('title', 'Address Book')

@php
    // Stored tag colour is an opaque ARGB int (as text); expose it as a CSS-compatible value.
    $tagHex = static fn ($color) => ((int) $color) ? '#'.substr(sprintf('%08X', (int) $color), 2) : '#1e88e5';
    $platformIcon = static function (?string $platform): string {
        $platform = strtolower((string) $platform);

        return match (true) {
            str_contains($platform, 'win') => 'ri-windows-fill',
            str_contains($platform, 'mac') || str_contains($platform, 'ios') => 'ri-apple-fill',
            str_contains($platform, 'android') => 'ri-android-fill',
            str_contains($platform, 'linux') => 'ri-ubuntu-fill',
            default => 'ri-computer-line',
        };
    };
    $owner = $addressBook->user?->username ?? 'shared';
    $canEdit = auth()->user()?->hasPermission('address_books.edit') ?? false;
    $modalState = session('address_book_modal', []);
    $modalState = is_array($modalState) ? $modalState : [];
    $requestedModal = (string) ($modalState['id'] ?? '');
    $activeModal = in_array($requestedModal, ['peerModal', 'tagModal', 'shareModal', 'importModal'], true)
        ? $requestedModal
        : '';

    $peerErrors = $errors->getBag('peer');
    $tagErrors = $errors->getBag('tag');
    $sharingErrors = $errors->getBag('sharing');
    $collaboratorErrors = $errors->getBag('collaborator');
    $importErrors = $errors->getBag('import');

    $peerHasErrors = $activeModal === 'peerModal' && $peerErrors->any();
    $tagHasErrors = $activeModal === 'tagModal' && $tagErrors->any();
    $sharingHasErrors = $activeModal === 'shareModal' && $sharingErrors->any();
    $collaboratorHasErrors = $activeModal === 'shareModal' && $collaboratorErrors->any();
    $importHasErrors = $activeModal === 'importModal' && $importErrors->any();
    $oldScalar = static function (string $key, string $default = ''): string {
        $value = old($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    };
    $oldTags = old('tags', []);
    $oldPeerTags = $peerHasErrors && is_array($oldTags)
        ? array_values(array_filter($oldTags, static fn ($tag): bool => is_scalar($tag)))
        : [];

    $peerRecordId = filter_var($modalState['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $peerIsEditing = $peerHasErrors && ($modalState['mode'] ?? null) === 'edit' && $peerRecordId !== false;
    $peerFormAction = $peerIsEditing
        ? route('admin.address-books.peers.update', ['peer' => $peerRecordId])
        : route('admin.address-books.peers.store', $addressBook);

    $tagRecordId = filter_var($modalState['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tagIsEditing = $tagHasErrors && ($modalState['mode'] ?? null) === 'edit' && $tagRecordId !== false;
    $tagFormAction = $tagIsEditing
        ? route('admin.address-books.tags.update', ['tag' => $tagRecordId])
        : route('admin.address-books.tags.store', $addressBook);
    $tagColorValue = $tagHasErrors ? $oldScalar('color', '#1e88e5') : '#1e88e5';
    $tagColorValue = preg_match('/^#[0-9a-f]{6}$/i', $tagColorValue) === 1 ? $tagColorValue : '#1e88e5';
    $effectivePeerLimit = $addressBook->effectiveMaxPeers();
    $peerLimitLabel = $effectivePeerLimit === 0 ? 'Unlimited' : number_format($effectivePeerLimit);
    if ($addressBook->max_peers === null) {
        $peerLimitLabel .= ' (server default)';
    }
@endphp

@section('content')
    @include('admin.partials.flash')
    <div class="rd-stack rd-stack--lg">
        <header class="rd-page-header">
            <div class="rd-page-header__copy">
                <div class="rd-breadcrumb" aria-label="Breadcrumb">People &amp; Access / Address Books / {{ $addressBook->name ?: 'Default' }}</div>
                <p class="rd-page-header__eyebrow">Collaborative directory</p>
                <h1 class="rd-page-header__title">{{ $addressBook->name ?: 'Default' }}</h1>
                <p class="rd-page-header__description">Organize remote IDs, tags, and team access for <strong>{{ $owner }}</strong>.</p>
            </div>
            <div class="rd-page-header__actions">
                @if ($canEdit)
                    <button class="rd-btn rd-btn--primary" data-bs-toggle="modal" data-bs-target="#peerModal" data-mode="add">
                        <i class="ri-add-line" aria-hidden="true"></i> Add ID
                    </button>
                @endif
                <a href="{{ route('admin.address-books.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> All address books</a>
            </div>
        </header>

        <section class="rd-card rd-card--quiet" aria-label="Address book controls">
            <div class="rd-toolbar">
                <div class="rd-toolbar__group rd-grow">
                    <div class="dropdown">
                        <button class="rd-btn rd-btn--ghost dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="ri-book-2-line" aria-hidden="true"></i> Switch book
                        </button>
                        <ul class="dropdown-menu">
                            @foreach ($ownerBooks as $book)
                                <li><a class="dropdown-item @if($book->id === $addressBook->id) active @endif"
                                       @if($book->id === $addressBook->id) aria-current="page" @endif
                                       href="{{ route('admin.address-books.show', $book) }}">{{ $book->name ?: 'Default' }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                    <span class="rd-badge rd-badge--muted"><i class="ri-computer-line" aria-hidden="true"></i> {{ $peers->total() }} {{ Str::plural('device', $peers->total()) }}</span>
                    @if ($addressBook->is_shared)
                        <span class="rd-badge rd-badge--online"><i class="ri-team-line" aria-hidden="true"></i> Shared with {{ $addressBook->collaborators->count() }}</span>
                    @else
                        <span class="rd-badge rd-badge--muted"><i class="ri-lock-line" aria-hidden="true"></i> Private</span>
                    @endif
                </div>
                <div class="rd-toolbar__group">
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.address-books.export', $addressBook) }}"><i class="ri-download-2-line" aria-hidden="true"></i> Export</a>
                    @if ($canEdit)
                        <button class="rd-btn rd-btn--ghost" data-bs-toggle="modal" data-bs-target="#importModal"><i class="ri-upload-2-line" aria-hidden="true"></i> Import</button>
                        <button class="rd-btn rd-btn--ghost" data-bs-toggle="modal" data-bs-target="#shareModal"><i class="ri-team-line" aria-hidden="true"></i> Share</button>
                    @endif
                </div>
            </div>
        </section>

        @unless ($canEdit)
            <section class="rd-card rd-card--quiet" aria-labelledby="address-book-sharing-title">
                <div class="rd-card__header">
                    <h2 class="rd-card__title" id="address-book-sharing-title">Sharing details</h2>
                </div>
                <div class="rd-card__body rd-stack rd-stack--md">
                    <div class="rd-form-grid rd-form-grid--3">
                        <div class="rd-field">
                            <span class="rd-label">Status</span>
                            <span>{{ $addressBook->is_shared ? 'Shared' : 'Private' }}</span>
                        </div>
                        <div class="rd-field">
                            <span class="rd-label">Peer limit</span>
                            <span>{{ $peerLimitLabel }}</span>
                        </div>
                        <div class="rd-field">
                            <span class="rd-label">Note</span>
                            <span>{{ $addressBook->note ?: '—' }}</span>
                        </div>
                    </div>

                    <div class="rd-stack rd-stack--sm">
                        <span class="rd-label">Collaborators</span>
                        @if ($addressBook->collaborators->isEmpty())
                            <span class="rd-muted">No collaborators.</span>
                        @else
                            <div class="rd-table-wrap" role="region" tabindex="0" aria-label="Address book collaborators">
                                <table class="rd-table rd-table--compact">
                                    <thead><tr><th>User</th><th>Permission</th></tr></thead>
                                    <tbody>
                                    @foreach ($addressBook->collaborators as $collaborator)
                                        <tr>
                                            <td class="rd-table__primary">{{ $collaborator->user?->username ?? 'Unknown user' }}</td>
                                            <td>{{ $ruleList[$collaborator->rule] ?? 'Unknown' }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        @endunless

        <div class="rd-address-book">
            <aside class="rd-card rd-card--quiet rd-address-book__rail" aria-labelledby="address-book-tags-title">
                <div class="rd-card__header">
                    <h2 class="rd-card__title" id="address-book-tags-title">Tags</h2>
                    @if ($canEdit)
                        <button class="rd-icon-btn" data-bs-toggle="modal" data-bs-target="#tagModal" data-mode="add" aria-label="Add tag" title="Add tag"><i class="ri-add-line" aria-hidden="true"></i></button>
                    @endif
                </div>
                <div class="rd-card__body">
                    <div class="rd-tag-list">
                        <div class="rd-tag-row">
                            <button type="button" class="rd-tag-filter is-active" data-filter="" aria-pressed="true">
                                <span class="rd-tag__dot rd-tag__dot--muted" aria-hidden="true"></span><span class="rd-tag__name">All devices</span>
                            </button>
                        </div>
                        @foreach ($addressBook->tags as $tag)
                            <div class="rd-tag-row">
                                <button type="button" class="rd-tag-filter" data-filter="{{ $tag->name }}" aria-pressed="false">
                                    <span class="rd-tag__dot" style="--rd-tag-color: {{ $tagHex($tag->color) }}" aria-hidden="true"></span>
                                    <span class="rd-tag__name">{{ $tag->name }}</span>
                                </button>
                                @if ($canEdit)
                                    <div class="rd-tag__actions">
                                        <button class="rd-icon-btn" data-bs-toggle="modal" data-bs-target="#tagModal" data-mode="edit"
                                                data-url="{{ route('admin.address-books.tags.update', $tag) }}"
                                                data-name="{{ $tag->name }}" data-color="{{ $tagHex($tag->color) }}"
                                                aria-label="Edit {{ $tag->name }} tag" title="Edit tag"><i class="ri-pencil-line" aria-hidden="true"></i></button>
                                        <form method="POST" action="{{ route('admin.address-books.tags.destroy', $tag) }}" class="m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rd-icon-btn rd-icon-btn--danger" aria-label="Delete {{ $tag->name }} tag" title="Delete tag" data-confirm="Remove tag '{{ $tag->name }}'?"><i class="ri-close-line" aria-hidden="true"></i></button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($addressBook->tags->isEmpty())
                        <p class="rd-help">{{ $canEdit ? 'No tags yet. Add one to organize larger address books.' : 'No tags have been added to this address book.' }}</p>
                    @endif
                </div>
            </aside>

            <section class="rd-address-book__main" aria-label="Address book devices">
                <div class="rd-peer-grid" id="peerGrid">
                    @forelse ($peers as $peer)
                        @php
                            $tone = abs(crc32((string) $peer->rustdesk_id)) % 5;
                            $name = $peer->alias ?: $peer->hostname ?: $peer->rustdesk_id;
                            $peerTags = array_values((array) ($peer->tags ?? []));
                        @endphp
                        <article class="rd-peer" data-tags='@json($peerTags)'>
                            <div class="rd-peer__banner rd-peer__banner--{{ $tone }}" aria-hidden="true"></div>
                            @if ($canEdit)
                                <div class="rd-peer__menu dropdown">
                                    <button class="rd-icon-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $name }}" title="Device actions"><i class="ri-more-2-fill" aria-hidden="true"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#peerModal" data-mode="edit"
                                                data-url="{{ route('admin.address-books.peers.update', $peer) }}"
                                                data-id="{{ $peer->rustdesk_id }}"
                                                data-alias="{{ $peer->alias }}"
                                                data-note="{{ $peer->note }}"
                                                data-tags='@json($peerTags)'><i class="ri-pencil-line" aria-hidden="true"></i> Edit</button></li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.address-books.peers.destroy', $peer) }}" class="m-0">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="dropdown-item text-danger" data-confirm="Remove '{{ $peer->rustdesk_id }}' from this book?"><i class="ri-delete-bin-line" aria-hidden="true"></i> Delete</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            @endif
                            <div class="rd-peer__body">
                                <div class="rd-peer__platform"><i class="{{ $platformIcon($peer->platform) }}" aria-hidden="true"></i> {{ $peer->platform ?: 'Device' }}</div>
                                <h2 class="rd-peer__name">
                                    <span class="rd-peer__status @if($peer->online ?? false) is-online @endif" aria-hidden="true"></span>
                                    <span>{{ $name }}</span>
                                    <span class="visually-hidden">{{ ($peer->online ?? false) ? 'Online' : 'Offline' }}</span>
                                </h2>
                                <div class="rd-peer__id rd-mono">{{ $peer->rustdesk_id }}</div>
                                @if ($peer->note)
                                    <p class="rd-peer__note">{{ $peer->note }}</p>
                                @endif
                                @if ($peerTags)
                                    <div class="rd-peer__tags" aria-label="Tags">
                                        @foreach ($peerTags as $peerTag)
                                            <span class="rd-badge rd-badge--muted">{{ $peerTag }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="rd-empty rd-form-grid__full">
                            <i class="ri-contacts-book-2-line rd-empty__icon" aria-hidden="true"></i>
                            <p class="rd-empty__title">This address book is empty</p>
                            <p class="rd-empty__body">{{ $canEdit ? "Add a remote ID to make it available to this book's owner and collaborators." : 'No remote IDs are available in this book.' }}</p>
                            @if ($canEdit)
                                <div class="rd-empty__actions"><button class="rd-btn rd-btn--primary" data-bs-toggle="modal" data-bs-target="#peerModal" data-mode="add">Add ID</button></div>
                            @endif
                        </div>
                    @endforelse
                </div>
                <div class="rd-empty" id="peerFilterEmpty" hidden>
                    <i class="ri-filter-off-line rd-empty__icon" aria-hidden="true"></i>
                    <p class="rd-empty__title">No devices use this tag</p>
                    <p class="rd-empty__body">Choose another tag or return to all devices.</p>
                </div>
                <div class="rd-address-book__pagination">@include('admin.partials.pagination', ['paginator' => $peers])</div>
            </section>
        </div>
    </div>

    @if ($canEdit)
    {{-- Add or edit a peer. --}}
    <div class="modal fade" id="peerModal" tabindex="-1" aria-labelledby="peerModalTitle" aria-hidden="true" @if ($activeModal === 'peerModal') data-reopen="true" @endif>
        <div class="modal-dialog">
            <form method="POST" action="{{ $peerFormAction }}" id="peerForm" class="modal-content" data-add-url="{{ route('admin.address-books.peers.store', $addressBook) }}" @if ($peerHasErrors) data-validation-error="true" @endif>
                @csrf
                <input type="hidden" name="_method" value="{{ $peerIsEditing ? 'PUT' : 'POST' }}" id="peerMethod">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="peerModalTitle">{{ $peerIsEditing ? 'Edit ID' : 'Add ID' }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body rd-stack rd-stack--md">
                    @if ($peerHasErrors)
                        <div class="rd-callout rd-callout--danger" id="peer-error-summary" role="alert" tabindex="-1" data-modal-error-summary>
                            <i class="ri-error-warning-line" aria-hidden="true"></i>
                            <div>
                                <p><strong>Could not save this ID.</strong> Review the following fields:</p>
                                <ul class="mb-0">
                                    @foreach ($peerErrors->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                    <div class="rd-field">
                        <label class="rd-label" for="peerId">ID <span class="rd-required" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
                        <input class="rd-input" name="rustdesk_id" id="peerId" value="{{ $peerHasErrors ? $oldScalar('rustdesk_id') : '' }}" maxlength="255" required @readonly($peerIsEditing) @if ($peerErrors->has('rustdesk_id')) aria-invalid="true" aria-describedby="peer-id-error" @endif>
                        @error('rustdesk_id', 'peer')<span class="rd-help rd-help--error" id="peer-id-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="peerAlias">Alias</label>
                        <input class="rd-input" name="alias" id="peerAlias" value="{{ $peerHasErrors ? $oldScalar('alias') : '' }}" maxlength="255" @if ($peerErrors->has('alias')) aria-invalid="true" aria-describedby="peer-alias-error" @endif>
                        @error('alias', 'peer')<span class="rd-help rd-help--error" id="peer-alias-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="peerNote">Note</label>
                        <input class="rd-input" name="note" id="peerNote" value="{{ $peerHasErrors ? $oldScalar('note') : '' }}" maxlength="300" @if ($peerErrors->has('note')) aria-invalid="true" aria-describedby="peer-note-error" @endif>
                        @error('note', 'peer')<span class="rd-help rd-help--error" id="peer-note-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="peerPassword">Password <span class="rd-muted">(leave blank to keep)</span></label>
                        <input class="rd-input" id="peerPassword" type="password" name="password" autocomplete="new-password" maxlength="255" @if ($peerErrors->has('password')) aria-invalid="true" aria-describedby="peer-password-error" @endif>
                        @error('password', 'peer')<span class="rd-help rd-help--error" id="peer-password-error">{{ $message }} Re-enter the password before saving.</span>@enderror
                    </div>
                    <fieldset class="rd-field">
                        <legend class="rd-label">Tags</legend>
                        <div class="rd-choice-list">
                            @forelse ($addressBook->tags as $tag)
                                <label class="rd-check-card"><input type="checkbox" class="peer-tag" name="tags[]" value="{{ $tag->name }}" @checked(in_array($tag->name, $oldPeerTags, true))> <span>{{ $tag->name }}</span></label>
                            @empty
                                <span class="rd-help">No tags — add some from the Tags panel.</span>
                            @endforelse
                        </div>
                        @error('tags', 'peer')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                        @error('tags.*', 'peer')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="rd-btn rd-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="rd-btn rd-btn--primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Add or edit a tag. --}}
    <div class="modal fade" id="tagModal" tabindex="-1" aria-labelledby="tagModalTitle" aria-hidden="true" @if ($activeModal === 'tagModal') data-reopen="true" @endif>
        <div class="modal-dialog modal-sm">
            <form method="POST" action="{{ $tagFormAction }}" id="tagForm" class="modal-content" data-add-url="{{ route('admin.address-books.tags.store', $addressBook) }}" @if ($tagHasErrors) data-validation-error="true" @endif>
                @csrf
                <input type="hidden" name="_method" value="{{ $tagIsEditing ? 'PUT' : 'POST' }}" id="tagMethod">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="tagModalTitle">{{ $tagIsEditing ? 'Edit tag' : 'Add tag' }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body rd-form-grid rd-form-grid--2">
                    @if ($tagHasErrors)
                        <div class="rd-callout rd-callout--danger rd-form-grid__full" id="tag-error-summary" role="alert" tabindex="-1" data-modal-error-summary>
                            <i class="ri-error-warning-line" aria-hidden="true"></i>
                            <div>
                                <p><strong>Could not save this tag.</strong> Review the following fields:</p>
                                <ul class="mb-0">
                                    @foreach ($tagErrors->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                    <div class="rd-field">
                        <label class="rd-label" for="tagName">Name</label>
                        <input class="rd-input" name="name" id="tagName" value="{{ $tagHasErrors ? $oldScalar('name') : '' }}" maxlength="255" required @if ($tagErrors->has('name')) aria-invalid="true" aria-describedby="tag-name-error" @endif>
                        @error('name', 'tag')<span class="rd-help rd-help--error" id="tag-name-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="tagColor">Colour</label>
                        <input class="rd-color-input" type="color" name="color" id="tagColor" value="{{ $tagColorValue }}" data-default-color="#1e88e5" @if ($tagErrors->has('color')) aria-invalid="true" aria-describedby="tag-color-error" @endif>
                        @error('color', 'tag')<span class="rd-help rd-help--error" id="tag-color-error">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="rd-btn rd-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="rd-btn rd-btn--primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Team sharing. --}}
    <div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalTitle" aria-hidden="true" @if ($activeModal === 'shareModal') data-reopen="true" @endif>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="shareModalTitle"><i class="ri-team-line" aria-hidden="true"></i> Share “{{ $addressBook->name ?: 'Default' }}”</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body rd-stack rd-stack--lg">
                    <form method="POST" action="{{ route('admin.address-books.sharing', $addressBook) }}" class="rd-stack rd-stack--md">
                        @csrf
                        @method('PUT')
                        @if ($sharingHasErrors)
                            <div class="rd-callout rd-callout--danger" id="sharing-error-summary" role="alert" tabindex="-1" data-modal-error-summary>
                                <i class="ri-error-warning-line" aria-hidden="true"></i>
                                <div>
                                    <p><strong>Could not save sharing settings.</strong> Review the following fields:</p>
                                    <ul class="mb-0">
                                        @foreach ($sharingErrors->all() as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif
                        <div class="rd-field">
                            <label class="rd-switch">
                                <input type="checkbox" name="is_shared" value="1" @checked($sharingHasErrors ? $oldScalar('is_shared') === '1' : $addressBook->is_shared)>
                                <span class="rd-switch__track" aria-hidden="true"></span>
                                <span>Mark as a shared team book</span>
                            </label>
                            <span class="rd-help">Collaborators below can see a shared book in their RustDesk client.</span>
                        </div>
                        <div class="rd-form-grid rd-form-grid--2">
                            <div class="rd-field">
                                <label class="rd-label" for="shareNote">Description note</label>
                                <input class="rd-input" id="shareNote" name="note" value="{{ $sharingHasErrors ? $oldScalar('note') : $addressBook->note }}" maxlength="255" placeholder="e.g. Front-desk machines" @if ($sharingErrors->has('note')) aria-invalid="true" aria-describedby="sharing-note-error" @endif>
                                @error('note', 'sharing')<span class="rd-help rd-help--error" id="sharing-note-error">{{ $message }}</span>@enderror
                            </div>
                            <div class="rd-field">
                                <label class="rd-label" for="shareMaxPeers">Max peers</label>
                                <input class="rd-input" id="shareMaxPeers" type="number" name="max_peers" min="0" max="1000000" value="{{ $sharingHasErrors ? $oldScalar('max_peers') : $addressBook->max_peers }}" placeholder="Blank = server default" @if ($sharingErrors->has('max_peers')) aria-invalid="true" aria-describedby="sharing-max-peers-error" @endif>
                                <span class="rd-help">Blank uses the server default; 0 means unlimited.</span>
                                @error('max_peers', 'sharing')<span class="rd-help rd-help--error" id="sharing-max-peers-error">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div class="rd-actions rd-actions--end"><button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line" aria-hidden="true"></i> Save sharing</button></div>
                    </form>

                    <hr class="rd-divider">

                    <section class="rd-stack rd-stack--md" aria-labelledby="collaborators-title">
                        <h3 class="rd-section-heading" id="collaborators-title">Collaborators</h3>
                        <div class="rd-table-wrap" role="region" tabindex="0" aria-label="Address book collaborators">
                            <table class="rd-table rd-table--compact">
                                <thead><tr><th>User</th><th>Permission</th><th><span class="visually-hidden">Action</span></th></tr></thead>
                                <tbody>
                                @forelse ($addressBook->collaborators as $collaborator)
                                    <tr>
                                        <td class="rd-table__primary">{{ $collaborator->user->username ?? '—' }}</td>
                                        <td class="rd-muted">{{ $ruleList[$collaborator->rule] ?? $collaborator->rule }}</td>
                                        <td>
                                            <div class="rd-table__actions">
                                                <form method="POST" action="{{ route('admin.address-books.collaborators.destroy', $collaborator) }}" class="m-0" data-collaborator-remove-form>
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="button" class="rd-icon-btn rd-icon-btn--danger" aria-label="Remove {{ $collaborator->user->username ?? 'collaborator' }}" aria-expanded="false" aria-controls="collaborator-confirm-{{ $collaborator->id }}" title="Remove collaborator" data-collaborator-remove-trigger><i class="ri-close-line" aria-hidden="true"></i></button>
                                                    <div class="rd-actions rd-actions--end rd-actions--wrap" id="collaborator-confirm-{{ $collaborator->id }}" role="group" aria-labelledby="collaborator-confirm-label-{{ $collaborator->id }}" hidden data-collaborator-remove-confirmation>
                                                        <span class="rd-help" id="collaborator-confirm-label-{{ $collaborator->id }}">Remove <strong>{{ $collaborator->user->username ?? 'this collaborator' }}</strong>?</span>
                                                        <button type="button" class="rd-btn rd-btn--ghost" data-collaborator-remove-cancel>Cancel</button>
                                                        <button type="submit" class="rd-btn rd-btn--danger">Remove</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3"><div class="rd-empty"><p class="rd-empty__title">No collaborators yet</p><p class="rd-empty__body">Add a user below when this book is ready to share.</p></div></td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if ($collaboratorHasErrors)
                            <div class="rd-callout rd-callout--danger" id="collaborator-error-summary" role="alert" tabindex="-1" data-modal-error-summary>
                                <i class="ri-error-warning-line" aria-hidden="true"></i>
                                <div>
                                    <p><strong>Could not add this collaborator.</strong> Review the following fields:</p>
                                    <ul class="mb-0">
                                        @foreach ($collaboratorErrors->all() as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif
                        <form method="POST" action="{{ route('admin.address-books.collaborators.store', $addressBook) }}" class="rd-inline-form">
                            @csrf
                            <div class="rd-combo rd-grow" data-url="{{ route('admin.users.search') }}">
                                <input type="hidden" name="user_id" value="{{ $collaboratorHasErrors ? $oldScalar('user_id') : '' }}">
                                <input type="text" class="rd-input rd-combo__input" name="user_search" value="{{ $collaboratorHasErrors ? $oldScalar('user_search') : '' }}" maxlength="255" aria-label="Collaborator" placeholder="Search user…" autocomplete="off" @if ($collaboratorErrors->has('user_id') || $collaboratorErrors->has('user_search')) aria-invalid="true" aria-describedby="collaborator-user-error" @endif>
                                <div class="rd-combo__menu"></div>
                            </div>
                            <label class="visually-hidden" for="collaboratorRule">Permission</label>
                            <select class="rd-select rd-max-w-sm" id="collaboratorRule" name="rule" @if ($collaboratorErrors->has('rule')) aria-invalid="true" aria-describedby="collaborator-rule-error" @endif>
                                @foreach ($ruleList as $value => $label)
                                    <option value="{{ $value }}" @selected((string) $value === ($collaboratorHasErrors ? $oldScalar('rule') : (string) \App\Models\AddressBookCollaborator::RULE_READ_WRITE))>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-user-add-line" aria-hidden="true"></i> Add</button>
                            @if ($collaboratorErrors->has('user_id'))
                                <span class="rd-help rd-help--error rd-form-grid__full" id="collaborator-user-error">{{ $collaboratorErrors->first('user_id') }}</span>
                            @elseif ($collaboratorErrors->has('user_search'))
                                <span class="rd-help rd-help--error rd-form-grid__full" id="collaborator-user-error">{{ $collaboratorErrors->first('user_search') }}</span>
                            @endif
                            @error('rule', 'collaborator')<span class="rd-help rd-help--error rd-form-grid__full" id="collaborator-rule-error">{{ $message }}</span>@enderror
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </div>

    {{-- Import peers from CSV. --}}
    <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalTitle" aria-hidden="true" @if ($activeModal === 'importModal') data-reopen="true" @endif>
        <div class="modal-dialog">
            <form method="POST" action="{{ route('admin.address-books.import', $addressBook) }}" enctype="multipart/form-data" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h5" id="importModalTitle"><i class="ri-upload-2-line" aria-hidden="true"></i> Import peers</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body rd-stack rd-stack--md">
                    @if ($importHasErrors)
                        <div class="rd-callout rd-callout--danger" id="import-error-summary" role="alert" tabindex="-1" data-modal-error-summary>
                            <i class="ri-error-warning-line" aria-hidden="true"></i>
                            <div>
                                <p><strong>Could not import this file.</strong> Review the following fields:</p>
                                <ul class="mb-0">
                                    @foreach ($importErrors->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                    <div class="rd-callout rd-callout--info">
                        <i class="ri-file-list-3-line" aria-hidden="true"></i>
                        <p>Upload a CSV with <code>id, alias, note, tags</code> columns. Separate tags with <code>;</code>. A header row is optional, and existing IDs are skipped.</p>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="peerImportFile">CSV file</label>
                        <input class="rd-input" id="peerImportFile" type="file" name="file" accept=".csv,text/csv,text/plain" required @if ($importErrors->has('file')) aria-invalid="true" aria-describedby="peer-import-file-error" @endif>
                        @error('file', 'import')<span class="rd-help rd-help--error" id="peer-import-file-error">{{ $message }} Select the file again before importing.</span>@enderror
                    </div>
                    <p class="rd-help">Tip: export the current book first to get a ready-to-edit template.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="rd-btn rd-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="rd-btn rd-btn--primary">Import</button>
                </div>
            </form>
        </div>
    </div>
    @endif
@endsection

@push('scripts')
<script>
    $(function () {
        $('.rd-tag-filter').on('click', function () {
            $('.rd-tag-filter').removeClass('is-active').attr('aria-pressed', 'false');
            $(this).addClass('is-active').attr('aria-pressed', 'true');
            var filter = $(this).data('filter') || '';
            var visible = 0;
            $('#peerGrid .rd-peer').each(function () {
                var tags = $(this).data('tags') || [];
                var show = filter === '' || tags.indexOf(filter) !== -1;
                $(this).prop('hidden', !show);
                if (show) { visible += 1; }
            });
            $('#peerFilterEmpty').prop('hidden', visible !== 0 || $('#peerGrid .rd-peer').length === 0);
        });

        $('#peerModal').on('show.bs.modal', function (event) {
            var $button = $(event.relatedTarget), edit = $button.data('mode') === 'edit', $form = $('#peerForm');
            if (!event.relatedTarget && $form.is('[data-validation-error]')) {
                return;
            }
            $form.removeAttr('data-validation-error');
            $form.find('[data-modal-error-summary], .rd-help--error').prop('hidden', true);
            $form.find('[aria-invalid="true"]').removeAttr('aria-invalid aria-describedby');
            $('#peerModalTitle').text(edit ? 'Edit ID' : 'Add ID');
            $('#peerMethod').val(edit ? 'PUT' : 'POST');
            $form.attr('action', edit ? $button.data('url') : $form.data('add-url'));
            $('#peerId').val(edit ? $button.data('id') : '').prop('readonly', edit);
            $('#peerAlias').val(edit ? ($button.data('alias') || '') : '');
            $('#peerNote').val(edit ? ($button.data('note') || '') : '');
            $('#peerPassword').val('');
            var tags = edit ? ($button.data('tags') || []) : [];
            $('.peer-tag').each(function () { $(this).prop('checked', tags.indexOf($(this).val()) !== -1); });
        });

        $('#tagModal').on('show.bs.modal', function (event) {
            var $button = $(event.relatedTarget), edit = $button.data('mode') === 'edit', $form = $('#tagForm');
            if (!event.relatedTarget && $form.is('[data-validation-error]')) {
                return;
            }
            $form.removeAttr('data-validation-error');
            $form.find('[data-modal-error-summary], .rd-help--error').prop('hidden', true);
            $form.find('[aria-invalid="true"]').removeAttr('aria-invalid aria-describedby');
            $('#tagModalTitle').text(edit ? 'Edit tag' : 'Add tag');
            $('#tagMethod').val(edit ? 'PUT' : 'POST');
            $form.attr('action', edit ? $button.data('url') : $form.data('add-url'));
            $('#tagName').val(edit ? $button.data('name') : '');
            $('#tagColor').val(edit ? $button.data('color') : $('#tagColor').data('default-color'));
        });

        $('[data-collaborator-remove-trigger]').on('click', function () {
            var $trigger = $(this);
            var confirmation = document.getElementById($trigger.attr('aria-controls'));
            if (!confirmation) {
                return;
            }

            $trigger.prop('hidden', true).attr('aria-expanded', 'true');
            $(confirmation).prop('hidden', false)
                .find('[data-collaborator-remove-cancel]').trigger('focus');
        });

        $('[data-collaborator-remove-cancel]').on('click', function () {
            var $form = $(this).closest('[data-collaborator-remove-form]');
            $form.find('[data-collaborator-remove-confirmation]').prop('hidden', true);
            $form.find('[data-collaborator-remove-trigger]')
                .prop('hidden', false)
                .attr('aria-expanded', 'false')
                .trigger('focus');
        });

        $('#shareModal').on('hidden.bs.modal', function () {
            $(this).find('[data-collaborator-remove-confirmation]').prop('hidden', true);
            $(this).find('[data-collaborator-remove-trigger]').prop('hidden', false).attr('aria-expanded', 'false');
        });

        var modalToReopen = document.querySelector('.modal[data-reopen="true"]');
        if (modalToReopen && window.bootstrap) {
            $(modalToReopen).one('shown.bs.modal', function () {
                $(this).find('[data-modal-error-summary]').first().trigger('focus');
            });
            window.bootstrap.Modal.getOrCreateInstance(modalToReopen).show();
        }
    });
</script>
@endpush
