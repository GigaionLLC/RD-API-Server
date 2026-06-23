{{-- Renders a session flash 'status' message as a success toast after load. --}}
@if (session('status'))
    @push('scripts')
        <script>$(function () { RD.toast(@json(session('status')), 'success'); });</script>
    @endpush
@endif
{{-- An 'error' flash surfaces as an error toast (e.g. a failed webhook test). --}}
@if (session('error'))
    @push('scripts')
        <script>$(function () { RD.toast(@json(session('error')), 'error'); });</script>
    @endpush
@endif
