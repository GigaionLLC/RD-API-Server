{{--
    Shared fields for the admin-role create/edit forms. Expects $role (an AdminRole, possibly
    unsaved), $groups (collection for the group-scope multi-select), and $selectedPerms /
    $selectedScope arrays of the currently chosen permission strings and group ids.
--}}
<div class="rd-form-grid rd-form-grid--2">
    <div class="rd-field">
        <label class="rd-label" for="name">Name</label>
        <input class="rd-input" id="name" name="name" value="{{ old('name', $role->name) }}" required
               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        @error('name')<span class="rd-help rd-help--error" id="name-error">{{ $message }}</span>@enderror
    </div>

    <div class="rd-field">
        <label class="rd-label" for="type">Type</label>
        <select class="rd-select" id="type" name="type" data-role-type aria-describedby="type-help"
                @error('type') aria-invalid="true" aria-errormessage="type-error" @enderror>
            <option value="{{ \App\Models\AdminRole::TYPE_GLOBAL }}" @selected(old('type', $role->type) === \App\Models\AdminRole::TYPE_GLOBAL)>Global (full access)</option>
            <option value="{{ \App\Models\AdminRole::TYPE_INDIVIDUAL }}" @selected(old('type', $role->type) === \App\Models\AdminRole::TYPE_INDIVIDUAL)>Individual (own devices &amp; logs)</option>
            <option value="{{ \App\Models\AdminRole::TYPE_GROUP }}" @selected(old('type', $role->type) === \App\Models\AdminRole::TYPE_GROUP)>Group-scoped</option>
        </select>
        <span class="rd-help" id="type-help">A global role implies every permission regardless of the selections below.</span>
        @error('type')<span class="rd-help rd-help--error" id="type-error">{{ $message }}</span>@enderror
    </div>
</div>

<div class="rd-field" data-role-scope @unless(old('type', $role->type) === \App\Models\AdminRole::TYPE_GROUP) hidden @endunless>
    <label class="rd-label" for="scope">Scoped user groups</label>
    <select class="rd-select" id="scope" name="scope[]" multiple size="6" aria-describedby="scope-help"
            @error('scope') aria-invalid="true" aria-errormessage="scope-error" @enderror>
        @foreach ($groups as $g)
            <option value="{{ $g->id }}" @selected(in_array((int) $g->id, $selectedScope, true))>{{ $g->name }}</option>
        @endforeach
    </select>
    <span class="rd-help" id="scope-help">For group-scoped roles, choose the user groups whose users and devices this role may manage.</span>
    @error('scope')<span class="rd-help rd-help--error" id="scope-error">{{ $message }}</span>@enderror
</div>

<div class="rd-field">
    <div class="rd-label" id="permissions-label">Permissions</div>
    <span class="rd-help">Select the console areas and actions this role may use.</span>
    <div class="rd-form-grid rd-form-grid--2" data-role-perms role="group" aria-labelledby="permissions-label">
        @foreach (\App\Models\AdminRole::PERMISSION_CATALOG as $area => $perms)
            <section class="rd-card rd-card--quiet" aria-labelledby="permission-area-{{ $loop->index }}">
                <div class="rd-card__body rd-stack rd-stack--sm">
                    <h3 class="rd-card__title" id="permission-area-{{ $loop->index }}">{{ $area }}</h3>
                    @foreach ($perms as $perm)
                        <label class="rd-check">
                            <input type="checkbox" name="perms[]" value="{{ $perm }}" @checked(in_array($perm, $selectedPerms, true))>
                            <span>{{ \Illuminate\Support\Str::headline(\Illuminate\Support\Str::afterLast($perm, '.')) }}</span>
                        </label>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
    @error('perms')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
</div>

@push('scripts')
<script>
    $(function () {
        // Show the group-scope picker only for the group type; the permission grid is ignored
        // for global roles (which imply everything) but stays editable for clarity.
        var $type = $('select[data-role-type]');
        function syncScope() {
            $('[data-role-scope]').prop('hidden', $type.val() !== '{{ \App\Models\AdminRole::TYPE_GROUP }}');
        }
        $type.on('change', syncScope);
        syncScope();
    });
</script>
@endpush
