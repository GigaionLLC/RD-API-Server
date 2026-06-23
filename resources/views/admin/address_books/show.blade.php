@extends('layouts.admin')
@section('title', 'Address Book')

@php
    // Stored tag colour is an opaque ARGB int (as text); show it as #rrggbb.
    $tagHex = static fn ($c) => ((int) $c) ? '#'.substr(sprintf('%08X', (int) $c), 2) : '#1e88e5';
    $platIcon = static function (?string $p): string {
        $p = strtolower((string) $p);
        return match (true) {
            str_contains($p, 'win') => 'ri-windows-fill',
            str_contains($p, 'mac') || str_contains($p, 'ios') => 'ri-apple-fill',
            str_contains($p, 'android') => 'ri-android-fill',
            str_contains($p, 'linux') => 'ri-ubuntu-fill',
            default => 'ri-computer-line',
        };
    };
    $owner = $addressBook->user?->username ?? 'shared';
@endphp

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">Management / Address Books / {{ $addressBook->name ?: 'Default' }}</div>

    <style>
        .rd-ab { display:grid; grid-template-columns:230px 1fr; gap:18px; align-items:start; }
        .rd-ab__rail .rd-card__body { padding:12px; }
        .rd-tag { display:flex; align-items:center; gap:8px; padding:6px 8px; border-radius:7px; cursor:pointer; }
        .rd-tag:hover { background:var(--rd-surface-2); }
        .rd-tag.active { background:var(--rd-surface-3); }
        .rd-tag__dot { width:11px; height:11px; border-radius:50%; flex:none; }
        .rd-tag__name { flex:1; font-size:13px; color:var(--rd-text-bright); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .rd-tag__act { opacity:0; color:var(--rd-text-muted); }
        .rd-tag:hover .rd-tag__act { opacity:1; }
        .rd-peers { display:grid; grid-template-columns:repeat(auto-fill,minmax(176px,1fr)); gap:14px; }
        .rd-peer { border:1px solid var(--rd-border); border-radius:10px; overflow:hidden; background:var(--rd-surface-2); }
        .rd-peer__banner { height:74px; display:flex; align-items:center; justify-content:center; font-size:30px; color:rgba(255,255,255,.92); position:relative; }
        .rd-peer__menu { position:absolute; top:6px; right:6px; }
        .rd-peer__menu .rd-btn { padding:2px 7px; background:rgba(0,0,0,.28); color:#fff; }
        .rd-peer__body { padding:9px 11px; }
        .rd-peer__name { display:flex; align-items:center; gap:7px; font-weight:600; color:var(--rd-text-bright); font-size:14px; }
        .rd-peer__name .dot { width:8px; height:8px; border-radius:50%; background:var(--rd-text-muted); flex:none; }
        .rd-peer__name .dot.on { background:#22c55e; }
        .rd-peer__id { font-family:monospace; font-size:12px; color:var(--rd-text-muted); margin-top:2px; }
        .rd-peer__tags { margin-top:8px; display:flex; flex-wrap:wrap; gap:4px; }
        .rd-peer__tags .rd-badge { font-size:10px; }
        .rd-hide { display:none !important; }
    </style>

    <div class="rd-card" style="margin-bottom:16px;">
        <div class="rd-card__body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <div class="dropdown">
                <button class="rd-btn rd-btn--ghost dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="ri-book-2-line"></i> {{ $addressBook->name ?: 'Default' }}
                </button>
                <ul class="dropdown-menu">
                    @foreach ($ownerBooks as $b)
                        <li><a class="dropdown-item @if($b->id === $addressBook->id) active @endif"
                               href="{{ route('admin.address-books.show', $b) }}">{{ $b->name ?: 'Default' }}</a></li>
                    @endforeach
                </ul>
            </div>
            <span class="rd-muted">Owner: <strong style="color:var(--rd-text-bright);">{{ $owner }}</strong></span>
            <span class="rd-muted">·</span>
            <span class="rd-muted">{{ $peers->total() }} {{ Str::plural('device', $peers->total()) }}</span>
            <div style="margin-left:auto;display:flex;gap:8px;">
                <button class="rd-btn rd-btn--primary" data-bs-toggle="modal" data-bs-target="#peerModal" data-mode="add">
                    <i class="ri-add-line"></i> Add ID
                </button>
                <a href="{{ route('admin.address-books.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
            </div>
        </div>
    </div>

    <div class="rd-ab">
        {{-- Tags rail --}}
        <div class="rd-card rd-ab__rail">
            <div class="rd-card__header">
                <h3 class="rd-card__title" style="font-size:14px;">Tags</h3>
                <button class="rd-btn rd-btn--ghost" data-bs-toggle="modal" data-bs-target="#tagModal" data-mode="add" title="Add tag"><i class="ri-add-line"></i></button>
            </div>
            <div class="rd-card__body">
                <div class="rd-tag active" data-filter="" title="Show all"><span class="rd-tag__dot" style="background:var(--rd-text-muted);"></span><span class="rd-tag__name">All devices</span></div>
                @foreach ($addressBook->tags as $tag)
                    <div class="rd-tag" data-filter="{{ $tag->name }}">
                        <span class="rd-tag__dot" style="background:{{ $tagHex($tag->color) }};"></span>
                        <span class="rd-tag__name">{{ $tag->name }}</span>
                        <button class="rd-tag__act rd-btn rd-btn--ghost" style="padding:0 5px;"
                                data-bs-toggle="modal" data-bs-target="#tagModal" data-mode="edit"
                                data-url="{{ route('admin.address-books.tags.update', $tag) }}"
                                data-name="{{ $tag->name }}" data-color="{{ $tagHex($tag->color) }}" title="Edit"><i class="ri-pencil-line"></i></button>
                        <form method="POST" action="{{ route('admin.address-books.tags.destroy', $tag) }}" class="m-0">
                            @csrf @method('DELETE')
                            <button type="submit" class="rd-tag__act rd-btn rd-btn--ghost" style="padding:0 5px;" data-confirm="Remove tag '{{ $tag->name }}'?" title="Delete"><i class="ri-close-line"></i></button>
                        </form>
                    </div>
                @endforeach
                @if ($addressBook->tags->isEmpty())
                    <span class="rd-muted" style="font-size:12px;">No tags yet.</span>
                @endif
            </div>
        </div>

        {{-- Peer cards --}}
        <div>
            <div class="rd-peers" id="peerGrid">
                @forelse ($peers as $peer)
                    @php
                        $hue = crc32((string) $peer->rustdesk_id) % 360;
                        $name = $peer->alias ?: $peer->hostname ?: $peer->rustdesk_id;
                        $ptags = array_values((array) ($peer->tags ?? []));
                    @endphp
                    <div class="rd-peer" data-tags='@json($ptags)'>
                        <div class="rd-peer__banner" style="background:hsl({{ $hue }},42%,34%);">
                            <i class="{{ $platIcon($peer->platform) }}"></i>
                            <div class="rd-peer__menu dropdown">
                                <button class="rd-btn rd-btn--ghost" data-bs-toggle="dropdown"><i class="ri-more-2-fill"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#peerModal" data-mode="edit"
                                            data-url="{{ route('admin.address-books.peers.update', $peer) }}"
                                            data-id="{{ $peer->rustdesk_id }}"
                                            data-alias="{{ $peer->alias }}"
                                            data-note="{{ $peer->note }}"
                                            data-tags='@json($ptags)'><i class="ri-pencil-line"></i> Edit</button></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.address-books.peers.destroy', $peer) }}" class="m-0">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="dropdown-item text-danger" data-confirm="Remove '{{ $peer->rustdesk_id }}' from this book?"><i class="ri-delete-bin-line"></i> Delete</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="rd-peer__body">
                            <div class="rd-peer__name"><span class="dot {{ ($peer->online ?? false) ? 'on' : '' }}"></span>{{ $name }}</div>
                            <div class="rd-peer__id">{{ $peer->rustdesk_id }}</div>
                            @if ($ptags)
                                <div class="rd-peer__tags">
                                    @foreach ($ptags as $t)
                                        <span class="rd-badge rd-badge--muted">{{ $t }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rd-muted" style="grid-column:1/-1;text-align:center;padding:40px;">
                        No devices in this address book yet. Use <strong>Add ID</strong> to add one.
                    </div>
                @endforelse
            </div>
            <div style="margin-top:14px;">@include('admin.partials.pagination', ['paginator' => $peers])</div>
        </div>
    </div>

    {{-- Add / Edit peer modal --}}
    <div class="modal fade" id="peerModal" tabindex="-1">
      <div class="modal-dialog">
        <form method="POST" id="peerForm" class="modal-content" data-add-url="{{ route('admin.address-books.peers.store', $addressBook) }}">
            @csrf
            <input type="hidden" name="_method" value="POST" id="peerMethod">
            <div class="modal-header"><h5 class="modal-title" id="peerModalTitle">Add ID</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="rd-field">
                    <label class="rd-label">ID <span style="color:#ef4444;">*</span></label>
                    <input class="rd-input" name="rustdesk_id" id="peerId" required>
                    @error('rustdesk_id')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                </div>
                <div class="rd-field"><label class="rd-label">Alias</label><input class="rd-input" name="alias" id="peerAlias"></div>
                <div class="rd-field"><label class="rd-label">Note</label><input class="rd-input" name="note" id="peerNote" maxlength="300"></div>
                <div class="rd-field"><label class="rd-label">Password (leave blank to keep)</label><input class="rd-input" type="password" name="password" autocomplete="new-password"></div>
                <div class="rd-field">
                    <label class="rd-label">Tags</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        @forelse ($addressBook->tags as $tag)
                            <label class="rd-badge rd-badge--muted" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                                <input type="checkbox" class="peer-tag" name="tags[]" value="{{ $tag->name }}"> {{ $tag->name }}
                            </label>
                        @empty
                            <span class="rd-muted" style="font-size:12px;">No tags — add some from the Tags panel.</span>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="rd-btn rd-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="rd-btn rd-btn--primary">Save</button>
            </div>
        </form>
      </div>
    </div>

    {{-- Add / Edit tag modal --}}
    <div class="modal fade" id="tagModal" tabindex="-1">
      <div class="modal-dialog modal-sm">
        <form method="POST" id="tagForm" class="modal-content" data-add-url="{{ route('admin.address-books.tags.store', $addressBook) }}">
            @csrf
            <input type="hidden" name="_method" value="POST" id="tagMethod">
            <div class="modal-header"><h5 class="modal-title" id="tagModalTitle">Add tag</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="rd-field"><label class="rd-label">Name</label><input class="rd-input" name="name" id="tagName" required></div>
                <div class="rd-field"><label class="rd-label">Colour</label><input type="color" name="color" id="tagColor" value="#1e88e5" style="width:54px;height:34px;padding:2px;border:1px solid var(--rd-border);border-radius:6px;background:var(--rd-surface-2);"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="rd-btn rd-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="rd-btn rd-btn--primary">Save</button>
            </div>
        </form>
      </div>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        // --- Tag filter ---
        $('.rd-tag').on('click', function (e) {
            if ($(e.target).closest('button,form').length) return; // ignore edit/delete clicks
            $('.rd-tag').removeClass('active');
            $(this).addClass('active');
            var f = $(this).data('filter') || '';
            $('#peerGrid .rd-peer').each(function () {
                var tags = $(this).data('tags') || [];
                $(this).toggleClass('rd-hide', f !== '' && tags.indexOf(f) === -1);
            });
        });

        // --- Peer modal (add vs edit) ---
        $('#peerModal').on('show.bs.modal', function (ev) {
            var b = $(ev.relatedTarget), edit = b.data('mode') === 'edit', $f = $('#peerForm');
            $('#peerModalTitle').text(edit ? 'Edit ID' : 'Add ID');
            $('#peerMethod').val(edit ? 'PUT' : 'POST');
            $f.attr('action', edit ? b.data('url') : $f.data('add-url'));
            $('#peerId').val(edit ? b.data('id') : '').prop('readonly', edit);
            $('#peerAlias').val(edit ? (b.data('alias') || '') : '');
            $('#peerNote').val(edit ? (b.data('note') || '') : '');
            var tags = edit ? (b.data('tags') || []) : [];
            $('.peer-tag').each(function () { $(this).prop('checked', tags.indexOf($(this).val()) !== -1); });
        });

        // --- Tag modal (add vs edit) ---
        $('#tagModal').on('show.bs.modal', function (ev) {
            var b = $(ev.relatedTarget), edit = b.data('mode') === 'edit', $f = $('#tagForm');
            $('#tagModalTitle').text(edit ? 'Edit tag' : 'Add tag');
            $('#tagMethod').val(edit ? 'PUT' : 'POST');
            $f.attr('action', edit ? b.data('url') : $f.data('add-url'));
            $('#tagName').val(edit ? b.data('name') : '');
            $('#tagColor').val(edit ? b.data('color') : '#1e88e5');
        });
    });
</script>
@endpush
