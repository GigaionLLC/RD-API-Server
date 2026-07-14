@extends('layouts.admin')
@section('title', 'Edit Group')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">People &amp; Access / User Groups</div>
            <h1 class="rd-page-header__title">{{ $group->name }}</h1>
            <p class="rd-page-header__description">Maintain membership behavior and cross-group access.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.groups.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-md">
        <div class="rd-card__body">
            <form class="rd-liveform rd-stack rd-stack--lg" data-url="{{ route('admin.groups.update', $group) }}" data-method="PUT">
                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" value="{{ $group->name }}" required>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="type">Type</label>
                        <select class="rd-select" id="type" name="type">
                            <option value="{{ \App\Models\Group::TYPE_DEFAULT }}" @selected($group->type === \App\Models\Group::TYPE_DEFAULT)>Default</option>
                            <option value="{{ \App\Models\Group::TYPE_SHARED }}" @selected($group->type === \App\Models\Group::TYPE_SHARED)>Shared</option>
                        </select>
                    </div>
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="note">Note</label>
                    <input class="rd-input" id="note" name="note" value="{{ $group->note }}">
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="can_access_groups">Can access these user groups</label>
                    <select class="rd-select" id="can_access_groups" multiple size="6" data-access-multiselect data-target="#can_access_group_ids" aria-describedby="access-groups-help">
                        @foreach ($allGroups as $g)
                            <option value="{{ $g->id }}" @selected(in_array((int) $g->id, $accessGroupIds, true))>{{ $g->name }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" id="can_access_group_ids" name="can_access_group_ids" value="{{ implode(',', $accessGroupIds) }}">
                    <small class="rd-help" id="access-groups-help">Members of this group may access devices owned by users in the selected groups.</small>
                </div>
                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        // Mirror the multi-select selection into a hidden CSV field so the live-save form
        // (which flattens array inputs) submits the full set.
        $('select[data-access-multiselect]').each(function () {
            var $sel = $(this);
            var $target = $($sel.data('target'));
            $sel.on('change', function () {
                $target.val(($sel.val() || []).join(','));
                $sel.closest('form').trigger('change');
            });
        });
    });
</script>
@endpush
