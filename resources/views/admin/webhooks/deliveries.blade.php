@extends('layouts.admin')
@section('title', 'Webhook deliveries')
@php
    $canEdit = auth()->user()->hasPermission('webhooks.edit');
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Integrations / Webhooks</p>
            <h1 class="rd-page-header__title">{{ $webhook->name }} Deliveries</h1>
            <p class="rd-page-header__description rd-mono">{{ $webhook->redactedUrl() }}</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.webhooks.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back to webhooks</a>
        </div>
    </header>

    <div class="rd-stack rd-stack--lg">
        @unless ($canEdit)
            <div class="rd-callout rd-callout--info" role="status">
                You have view-only access. Resending failed deliveries requires edit permission.
            </div>
        @endunless

        <div class="rd-card rd-card--flush">
        <div class="rd-card__header"><h3 class="rd-card__title">Recent deliveries</h3></div>
        <div class="rd-table-wrap" role="region" aria-label="Recent webhook deliveries" tabindex="0">
            <table class="rd-table">
                <thead><tr><th>Event</th><th>Status</th><th>Code</th><th>Attempts</th><th>When</th><th>Next retry</th><th>Error</th><th class="rd-table__actions">Action</th></tr></thead>
                <tbody>
                @forelse ($deliveries as $d)
                    @php
                        $displayError = $d->error === null
                            ? null
                            : $webhook->redactSensitiveText((string) $d->error);
                    @endphp
                    <tr>
                        <td><code class="rd-code">{{ $d->event }}</code></td>
                        <td>
                            @php
                                $cls = $d->status === \App\Models\WebhookDelivery::STATUS_SUCCESS
                                    ? 'rd-badge--online'
                                    : ($d->status === \App\Models\WebhookDelivery::STATUS_FAILED ? 'rd-badge--offline' : 'rd-badge--muted');
                            @endphp
                            <span class="rd-badge {{ $cls }}">{{ $d->status }}</span>
                        </td>
                        <td class="rd-muted rd-mono">{{ $d->status_code ?? '—' }}</td>
                        <td class="rd-muted">{{ $d->attempts }}</td>
                        <td class="rd-muted">{{ $d->created_at?->diffForHumans() }}</td>
                        <td class="rd-muted">{{ $d->next_attempt_at?->diffForHumans() ?? '—' }}</td>
                        <td><span class="rd-table__meta" title="{{ $displayError }}">{{ $displayError ?? '—' }}</span></td>
                        <td class="rd-table__actions">
                            @if ($canEdit && $d->status !== \App\Models\WebhookDelivery::STATUS_SUCCESS)
                                <form method="POST" action="{{ route('admin.webhooks.deliveries.resend', $d) }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="rd-btn rd-btn--ghost" title="Resend now"><i class="ri-refresh-line" aria-hidden="true"></i> Resend</button>
                                </form>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="rd-empty"><i class="rd-empty__icon ri-send-plane-line" aria-hidden="true"></i><p class="rd-empty__title">No deliveries recorded yet</p><p class="rd-empty__body">Delivery attempts will appear after this webhook receives an event.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $deliveries])
        </div>
    </div>
@endsection
