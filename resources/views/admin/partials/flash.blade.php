@if (session('status'))
    @push('scripts')
        <script>
            $(function () {
                RD.toast(@json(session('status')), 'success');
            });
        </script>
    @endpush
@endif

@if (session('warning'))
    @push('scripts')
        <script>
            $(function () {
                RD.toast(@json(session('warning')), 'warning');
            });
        </script>
    @endpush
@endif

@if (session('error'))
    @push('scripts')
        <script>
            $(function () {
                RD.toast(@json(session('error')), 'error');
            });
        </script>
    @endpush
@endif
