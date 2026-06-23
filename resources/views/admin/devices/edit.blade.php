@extends('layouts.admin')
@section('title', 'Edit Device')

@section('content')
    <div class="rd-breadcrumb">Management / Devices / Edit</div>

    <div class="rd-card" style="max-width:640px;">
        <div class="rd-card__header">
            <h3 class="rd-card__title">{{ $device->hostname ?: $device->rustdesk_id }}</h3>
            <a href="{{ route('admin.devices.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
        <div class="rd-card__body">
            <form class="rd-liveform" data-url="{{ route('admin.devices.update', $device) }}" data-method="PUT">
                <div class="rd-field">
                    <label class="rd-label">RustDesk ID</label>
                    <input class="rd-input" value="{{ $device->rustdesk_id }}" disabled>
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="alias">Alias</label>
                    <input class="rd-input" id="alias" name="alias" value="{{ $device->alias }}" placeholder="Friendly name">
                </div>
                <div class="rd-field">
                    <label class="rd-label">Assigned user</label>
                    <div class="rd-combo" data-url="{{ route('admin.users.search') }}">
                        <input type="hidden" name="user_id" value="{{ $device->user_id }}">
                        <input type="text" class="rd-input rd-combo__input" value="{{ $device->user?->username }}"
                               placeholder="Search user… (blank = none)" autocomplete="off">
                        <div class="rd-combo__menu"></div>
                    </div>
                    <span class="rd-help">Type to search; clear the box for no owner.</span>
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
                    <select class="rd-select" id="approved" name="approved">
                        <option value="1" @selected($device->approved)>Approved</option>
                        <option value="0" @selected(! $device->approved)>Not approved</option>
                    </select>
                    <span class="rd-help">Unapproved devices are blocked from connecting.</span>
                </div>
                <div class="rd-row" style="margin-top:8px;">
                    <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save</button>
                </div>
            </form>
        </div>
    </div>
@endsection
