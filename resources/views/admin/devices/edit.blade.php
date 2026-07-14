@extends('layouts.admin')
@section('title', 'Edit Device')
@php($canEdit = auth()->user()->hasPermission('devices.edit'))

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">Fleet / Devices</div>
            <h1 class="rd-page-header__title">{{ $device->hostname ?: $device->rustdesk_id }}</h1>
            <p class="rd-page-header__description">{{ $canEdit ? 'Update ownership, grouping, rollout strategy, and connection approval.' : 'Review ownership, grouping, rollout strategy, and connection approval.' }}</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.devices.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__body">
            @unless ($canEdit)
                <div class="rd-callout rd-callout--info"><i class="ri-eye-line" aria-hidden="true"></i><p>You have view-only access to devices.</p></div>
            @endunless
            <form class="rd-liveform rd-stack rd-stack--lg" data-url="{{ route('admin.devices.update', $device) }}" data-method="PUT">
                <fieldset class="rd-fieldset-reset rd-stack rd-stack--lg" @disabled(! $canEdit)>
                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="rustdesk_id">RustDesk ID</label>
                        <input class="rd-input rd-input--mono" id="rustdesk_id" value="{{ $device->rustdesk_id }}" disabled>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="alias">Alias</label>
                        <input class="rd-input" id="alias" name="alias" value="{{ $device->alias }}" placeholder="Friendly name">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="assigned_user">Assigned user</label>
                        <div class="rd-combo" data-url="{{ route('admin.users.search') }}">
                            <input type="hidden" name="user_id" value="{{ $device->user_id }}">
                            <input type="text" class="rd-input rd-combo__input" id="assigned_user" value="{{ $device->user?->username }}"
                                   placeholder="Search user… (blank = none)" autocomplete="off" aria-describedby="assigned-user-help">
                            <div class="rd-combo__menu"></div>
                        </div>
                        <span class="rd-help" id="assigned-user-help">Type to search; clear the box for no owner.</span>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="device_group_id">Device group</label>
                        <select class="rd-select" id="device_group_id" name="device_group_id">
                            <option value="">— None —</option>
                            @foreach ($deviceGroups as $g)
                                <option value="{{ $g->id }}" @selected($device->device_group_id == $g->id)>{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="strategy_id">Strategy</label>
                        <select class="rd-select" id="strategy_id" name="strategy_id">
                            <option value="">— None —</option>
                            @foreach ($strategies as $s)
                                <option value="{{ $s->id }}" @selected($device->strategy_id == $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="note">Note</label>
                        <input class="rd-input" id="note" name="note" value="{{ $device->note }}">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="approved">Approval</label>
                        <select class="rd-select" id="approved" name="approved" aria-describedby="approval-help">
                            <option value="1" @selected($device->approved)>Approved</option>
                            <option value="0" @selected(! $device->approved)>Not approved</option>
                        </select>
                        <span class="rd-help" id="approval-help">Unapproved devices are blocked from connecting.</span>
                    </div>
                </div>
                @if ($canEdit)
                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save</button>
                </div>
                @endif
                </fieldset>
            </form>
        </div>
    </div>
@endsection
