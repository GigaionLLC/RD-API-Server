@extends('layouts.admin')
@section('title', 'New Group')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">People &amp; Access / User Groups</div>
            <h1 class="rd-page-header__title">Create user group</h1>
            <p class="rd-page-header__description">Group people for delegated access and device ownership rules.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.groups.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-md">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>User group not created.</strong> {{ $errors->first() }}</div>
                </div>
            @endif
            <form method="POST" action="{{ route('admin.groups.store') }}" class="rd-stack rd-stack--lg">
                @csrf
                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" value="{{ old('name') }}" required
                               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')<span class="rd-help rd-help--error" id="name-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="type">Type</label>
                        <select class="rd-select" id="type" name="type" @error('type') aria-invalid="true" aria-describedby="type-error" @enderror>
                            <option value="{{ \App\Models\Group::TYPE_DEFAULT }}" @selected(old('type', \App\Models\Group::TYPE_DEFAULT) == \App\Models\Group::TYPE_DEFAULT)>Default</option>
                            <option value="{{ \App\Models\Group::TYPE_SHARED }}" @selected(old('type') == \App\Models\Group::TYPE_SHARED)>Shared</option>
                        </select>
                        @error('type')<span class="rd-help rd-help--error" id="type-error">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="note">Note</label>
                    <input class="rd-input" id="note" name="note" value="{{ old('note') }}"
                           @error('note') aria-invalid="true" aria-describedby="note-error" @enderror>
                    @error('note')<span class="rd-help rd-help--error" id="note-error">{{ $message }}</span>@enderror
                </div>
                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line" aria-hidden="true"></i> Create group</button>
                </div>
            </form>
        </div>
    </div>
@endsection
