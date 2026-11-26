<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\ClientConfigService;
use App\Support\StrategyOptionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Strategy management: list, create, and an editor for the config_options
 * key/value map, enable toggle, and target assignments.
 */
class StrategyController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $strategies = $this->scope->scopeStrategies(
            Strategy::query()->withCount('assignments'),
            $request->user(),
            'strategies.view',
        )->orderBy('name')->paginate(20);

        return view('admin.strategies.index', compact('strategies'));
    }

    /**
     * Toggle a strategy as the default fallback. At most one strategy is default at a time;
     * it applies to any device with no device/user/device-group assignment.
     */
    public function setDefault(Request $request, Strategy $strategy): RedirectResponse
    {
        $this->scope->authorizeUnrestricted($request->user(), 'strategies.edit');
        $makeDefault = ! $strategy->is_default;

        Strategy::query()->where('is_default', true)->update(['is_default' => false]);

        if ($makeDefault) {
            $strategy->forceFill(['is_default' => true])->save();
        }

        return redirect()
            ->route('admin.strategies.index')
            ->with('status', $makeDefault ? "“{$strategy->name}” is now the default strategy." : 'Default strategy cleared.');
    }

    public function create(Request $request): View
    {
        $this->scope->authorizeUnrestricted($request->user(), 'strategies.edit');
        $strategy = new Strategy(['enabled' => true, 'options' => []]);

        return view('admin.strategies.create', compact('strategy'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->authorizeUnrestricted($request->user(), 'strategies.edit');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        Strategy::create([
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
            'enabled' => true,
            'options' => [],
            'modified_at' => time(),
        ]);

        return redirect()
            ->route('admin.strategies.index')
            ->with('status', 'Strategy created.');
    }

    public function edit(Request $request, Strategy $strategy): View
    {
        $this->scope->authorizeStrategy($request->user(), (int) $strategy->id, 'strategies.view');
        $strategy->load('assignments');

        // Device groups are few, so the picker stays a plain select; devices and users are
        // chosen with a searchable combobox (so we never load thousands of rows here).
        $scopePermission = $request->user()->hasPermission('strategies.edit')
            ? 'strategies.edit'
            : 'strategies.view';
        $deviceGroups = $this->scope->scopeDeviceGroups(
            DeviceGroup::query(),
            $request->user(),
            $scopePermission,
        )->orderBy('name')->get(['id', 'name']);

        // Readable labels for the EXISTING assignments only — look up just the referenced ids.
        $byType = $strategy->assignments->groupBy('target_type');
        $deviceMap = Device::whereIn('id', ($byType['device'] ?? collect())->pluck('target_id'))
            ->get(['id', 'rustdesk_id'])->keyBy('id');
        $userMap = User::whereIn('id', ($byType['user'] ?? collect())->pluck('target_id'))
            ->get(['id', 'username'])->keyBy('id');
        $deviceGroupMap = $deviceGroups->keyBy('id');

        // The known-option catalog (client-Settings-style tabs → sections → options) plus any
        // options the strategy carries that are NOT in the catalog — those keep the free-form
        // key/value editor. Keys the client no longer defines get their own read-only block so a
        // legacy strategy stays loadable without looking like a supported control.
        $tabs = config('strategy_options.tabs', []);
        $definitions = StrategyOptionCatalog::definitions();
        $storedOptions = (array) ($strategy->options ?? []);
        $customOptions = array_diff_key($storedOptions, $definitions);

        // A stored value the control cannot express (say `true` for a toggle that only offers
        // Y/N) is rendered as-is under "Custom: …" instead of falling back to "Default": the
        // next save of an unrelated field must not delete a value an administrator stored.
        $optionStates = $this->optionStates($definitions, $storedOptions, (int) $strategy->modified_at);

        $retiredDefinitions = (array) config('strategy_options.removed', []);
        $retiredOptions = [];
        foreach ($retiredDefinitions as $key => $definition) {
            if (! array_key_exists($key, (array) ($strategy->options ?? []))) {
                continue;
            }
            $retiredOptions[$key] = $definition + [
                'value' => (string) $strategy->options[$key],
            ];
        }
        $customOptions = array_diff_key($customOptions, $retiredDefinitions);

        return view('admin.strategies.edit', compact(
            'strategy',
            'deviceGroups',
            'deviceMap',
            'userMap',
            'deviceGroupMap',
            'tabs',
            'optionStates',
            'customOptions',
            'retiredOptions'
        ));
    }

    public function update(Request $request, Strategy $strategy): JsonResponse
    {
        $this->scope->authorizeStrategy($request->user(), (int) $strategy->id, 'strategies.edit');
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'opt' => ['nullable', 'array'],
            'opt.*' => ['nullable', 'string', static function (string $attribute, mixed $value, $fail): void {
                if (ClientConfigService::containsControlCharacters((string) $value)) {
                    $fail('Strategy option values must be a single line without control characters.');
                }
            }],
            'option_keys' => ['nullable', 'array'],
            'option_keys.*' => ['nullable', 'string', 'max:255', static function (string $attribute, mixed $value, $fail): void {
                if ((string) $value !== '' && ! ClientConfigService::isValidOptionKey(trim((string) $value))) {
                    $fail('Option keys may contain only letters, numbers, periods, underscores, and hyphens.');
                }
            }],
            'option_values' => ['nullable', 'array'],
            'option_values.*' => ['nullable', 'string', static function (string $attribute, mixed $value, $fail): void {
                if (ClientConfigService::containsControlCharacters((string) $value)) {
                    $fail('Strategy option values must be a single line without control characters.');
                }
            }],
            'drop_retired' => ['nullable', 'array'],
            'drop_retired.*' => ['nullable', 'string', 'max:255'],
        ]);

        foreach (array_keys((array) $request->input('opt', [])) as $key) {
            if (! ClientConfigService::isValidOptionKey((string) $key)) {
                throw ValidationException::withMessages([
                    'opt' => 'Strategy option keys may contain only letters, numbers, periods, underscores, and hyphens.',
                ]);
            }
        }

        $options = [];
        $definitions = StrategyOptionCatalog::definitions();

        /** @var array<array-key, mixed> $storedOptions */
        $storedOptions = (array) ($strategy->options ?? []);

        // Known catalog options post as opt[<key>]=<value>; an empty value means "leave the
        // client's own default" (option2bool treats "" as the per-key default), so it's omitted.
        foreach ((array) $request->input('opt', []) as $key => $val) {
            $key = trim((string) $key);
            $val = (string) $val;
            if ($key === '' || $val === '') {
                continue;
            }
            $options[$key] = $val;
        }

        // Custom (non-catalog) options: parallel key/value rows; skip empty keys. A row that
        // names a built-in control (or repeats a posted opt[] key) still wins — a value an
        // administrator stored always wins — but the response says so instead of leaving the
        // admin to guess which of the two applied.
        $keys = $request->input('option_keys', []);
        $values = $request->input('option_values', []);
        $builtInOverrides = [];
        foreach ($keys as $i => $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if (isset($definitions[$key]) || array_key_exists($key, $options)) {
                $builtInOverrides[] = $key;
            }
            $options[$key] = (string) ($values[$i] ?? '');
        }

        // A catalog value the control cannot express is kept when the request never mentions its
        // key: the form could not have offered it, so silence is not an administrator clearing it.
        // (An explicit empty opt[] value is the editor's "Default" choice and still clears the
        // key, and a delegated editor's attempt to change a protected one is still rejected.)
        $postedOpt = (array) $request->input('opt', []);
        foreach ($storedOptions as $storedKey => $storedValue) {
            $storedKey = (string) $storedKey;
            $storedValue = (string) $storedValue;
            if ($storedValue === '' || ! isset($definitions[$storedKey])) {
                continue;
            }
            if (array_key_exists($storedKey, $options) || array_key_exists($storedKey, $postedOpt)) {
                continue;
            }
            if (! StrategyOptionCatalog::acceptsValue($definitions[$storedKey], $storedValue)) {
                $options[$storedKey] = $storedValue;
            }
        }

        // Keys the client no longer defines are preserved exactly as stored (the editor shows
        // them read-only) until an admin ticks them for removal. Only retired keys qualify, so
        // this path cannot be used to inject an arbitrary option.
        $retiredKeys = array_keys((array) config('strategy_options.removed', []));
        $droppedKeys = array_map(strval(...), (array) $request->input('drop_retired', []));
        foreach ($storedOptions as $storedKey => $storedValue) {
            $storedKey = (string) $storedKey;
            if (in_array($storedKey, $retiredKeys, true) && ! in_array($storedKey, $droppedKeys, true)) {
                $options[$storedKey] = (string) $storedValue;
            }
        }

        $this->guardFullAdminOnlyOptions($request, (array) ($strategy->options ?? []), $options);

        $strategy->fill([
            'name' => $request->input('name'),
            'note' => $request->input('note'),
            'enabled' => $request->boolean('enabled'),
            'options' => $options,
            // Bump so clients pull the new config within one heartbeat.
            'modified_at' => time(),
        ])->save();

        $warnings = [];

        // A custom row that duplicates a built-in control kept its own value; say so, because
        // otherwise the admin sees two places claiming to set the same key.
        $builtInOverrideWarning = ClientConfigService::customOverridesBuiltInWarning($builtInOverrides);
        if ($builtInOverrideWarning !== null) {
            $warnings[] = $builtInOverrideWarning;
        }

        // Catalog entries carry the client-ignored warning inline; only free-form keys need it
        // here, so an admin who pastes a deny-listed key is told instead of silently pushing
        // nothing.
        $customIgnored = array_diff(array_keys($options), array_keys($definitions));
        $ignoredWarning = ClientConfigService::strategyIgnoredWarning($customIgnored);
        if ($ignoredWarning !== null) {
            $warnings[] = $ignoredWarning;
        }

        return response()->json($warnings === [] ? (object) [] : ['warning' => implode(' ', $warnings)]);
    }

    public function storeAssignment(Request $request, Strategy $strategy): RedirectResponse
    {
        $this->scope->authorizeStrategy($request->user(), (int) $strategy->id, 'strategies.edit');
        $data = $request->validate([
            'target_type' => ['required', Rule::in([
                StrategyAssignment::TARGET_DEVICE,
                StrategyAssignment::TARGET_USER,
                StrategyAssignment::TARGET_DEVICE_GROUP,
            ])],
            'target_id' => ['required', 'integer'],
        ]);

        if ($data['target_type'] === StrategyAssignment::TARGET_DEVICE) {
            $this->scope->authorizeDevice(
                $request->user(),
                Device::findOrFail($data['target_id']),
                'strategies.edit',
            );
        } elseif ($data['target_type'] === StrategyAssignment::TARGET_USER) {
            $this->scope->authorizeUser(
                $request->user(),
                User::findOrFail($data['target_id']),
                'strategies.edit',
            );
        } else {
            DeviceGroup::findOrFail($data['target_id']);
            $this->scope->authorizeDeviceGroup(
                $request->user(),
                (int) $data['target_id'],
                'strategies.edit',
            );
        }

        $strategy->assignments()->firstOrCreate([
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
        ]);

        $strategy->forceFill(['modified_at' => time()])->save();

        return redirect()
            ->route('admin.strategies.edit', $strategy)
            ->with('status', 'Assignment added.');
    }

    public function destroyAssignment(Request $request, StrategyAssignment $assignment): RedirectResponse
    {
        $strategyId = $assignment->strategy_id;
        $this->scope->authorizeStrategy($request->user(), (int) $strategyId, 'strategies.edit');
        $assignment->delete();

        Strategy::whereKey($strategyId)->update(['modified_at' => time()]);

        return redirect()
            ->route('admin.strategies.edit', $strategyId)
            ->with('status', 'Assignment removed.');
    }

    public function destroy(Request $request, Strategy $strategy): RedirectResponse
    {
        $this->scope->authorizeStrategy($request->user(), (int) $strategy->id, 'strategies.edit');
        $strategy->assignments()->delete();
        $strategy->delete();

        return redirect()
            ->route('admin.strategies.index')
            ->with('status', 'Strategy deleted.');
    }

    /**
     * Per-option editor state: which stored values the control cannot express (they render as
     * "Custom: …" and are preserved verbatim), whether the client reads only an exact `Y` as on,
     * and the one-time notice for a key that became a built-in control after this strategy was
     * last saved.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<array-key, mixed>  $stored
     * @return array<string, array{custom: bool, only_y_means_on: bool, kept_notice: ?string}>
     */
    private function optionStates(array $definitions, array $stored, int $modifiedAt): array
    {
        $states = [];

        foreach ($definitions as $key => $definition) {
            $value = (string) ($stored[$key] ?? '');
            $states[$key] = [
                'custom' => $value !== '' && ! StrategyOptionCatalog::acceptsValue($definition, $value),
                'only_y_means_on' => StrategyOptionCatalog::onlyYMeansOn($key),
                'kept_notice' => $value === '' ? null : $this->keptValueNotice($definition, $value, $modifiedAt),
            ];
        }

        return $states;
    }

    /**
     * The notice for a key this strategy already carried before the catalog offered a control
     * for it (`added_in` / `added_on` in config/strategy_options.php). It disappears on its own
     * once the strategy is saved again, because that bumps `modified_at`.
     *
     * @param  array<string, mixed>  $definition
     */
    private function keptValueNotice(array $definition, string $value, int $modifiedAt): ?string
    {
        $addedIn = $definition['added_in'] ?? null;
        $addedOn = $definition['added_on'] ?? null;
        if (! is_string($addedIn) || ! is_string($addedOn)) {
            return null;
        }

        $addedAt = strtotime($addedOn.' 00:00:00 UTC');
        if ($addedAt === false || $modifiedAt >= $addedAt) {
            return null;
        }

        return "Added as a built-in control in RD-API {$addedIn}. This strategy already had "
            ."`{$value}` set by hand; it was kept and is still pushed.";
    }

    /**
     * Server-redirect and proxy-credential options are full-administrator territory: a delegated
     * editor may keep them exactly as stored but not add, change or remove them.
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     */
    private function guardFullAdminOnlyOptions(Request $request, array $before, array $after): void
    {
        if ($this->scope->isUnrestricted($request->user(), 'strategies.edit')) {
            return;
        }

        $changed = ClientConfigService::changedFullAdminOnlyKeys($before, $after);
        if ($changed !== []) {
            throw ValidationException::withMessages([
                'opt' => 'Only a full administrator can set or change: '.implode(', ', $changed).'.',
            ]);
        }
    }
}
