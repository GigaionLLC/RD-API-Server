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
        // key/value editor.
        $tabs = config('strategy_options.tabs', []);
        $catalogKeys = [];
        foreach ($tabs as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['options'] as $opt) {
                    $catalogKeys[] = $opt['key'];
                }
            }
        }
        $customOptions = array_diff_key((array) ($strategy->options ?? []), array_flip($catalogKeys));

        return view('admin.strategies.edit', compact(
            'strategy',
            'deviceGroups',
            'deviceMap',
            'userMap',
            'deviceGroupMap',
            'tabs',
            'customOptions'
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
        ]);

        foreach (array_keys((array) $request->input('opt', [])) as $key) {
            if (! ClientConfigService::isValidOptionKey((string) $key)) {
                throw ValidationException::withMessages([
                    'opt' => 'Strategy option keys may contain only letters, numbers, periods, underscores, and hyphens.',
                ]);
            }
        }

        $options = [];

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

        // Custom (non-catalog) options: parallel key/value rows; skip empty keys.
        $keys = $request->input('option_keys', []);
        $values = $request->input('option_values', []);
        foreach ($keys as $i => $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $options[$key] = (string) ($values[$i] ?? '');
        }

        $strategy->fill([
            'name' => $request->input('name'),
            'note' => $request->input('note'),
            'enabled' => $request->boolean('enabled'),
            'options' => $options,
            // Bump so clients pull the new config within one heartbeat.
            'modified_at' => time(),
        ])->save();

        return response()->json((object) []);
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
}
