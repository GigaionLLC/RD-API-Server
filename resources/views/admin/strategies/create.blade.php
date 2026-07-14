@extends('layouts.admin')
@section('title', 'New Strategy')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">Policies &amp; Rollout / Strategies</div>
            <h1 class="rd-page-header__title">Create strategy</h1>
            <p class="rd-page-header__description">Start a policy container, then configure and assign client options from its editor.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.strategies.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-md">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Strategy not created.</strong> {{ $errors->first() }}</div>
                </div>
            @endif
            <form method="POST" action="{{ route('admin.strategies.store') }}" class="rd-stack rd-stack--lg">
                @csrf
                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" value="{{ old('name') }}" required
                               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')<span class="rd-help rd-help--error" id="name-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="note">Note</label>
                        <input class="rd-input" id="note" name="note" value="{{ old('note') }}"
                               @error('note') aria-invalid="true" aria-describedby="note-error" @enderror>
                        @error('note')<span class="rd-help rd-help--error" id="note-error">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="rd-callout rd-callout--info">
                    <i class="ri-information-line" aria-hidden="true"></i>
                    <span>Configuration options are added on the edit page after creation.</span>
                </div>
                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line"></i> Create strategy</button>
                </div>
            </form>
        </div>
    </div>
@endsection
