<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Strategy management: list, create, and an editor for the config_options
 * key/value map, enable toggle, and target assignments.
 */
class StrategyController extends Controller
{
    public function index(): View
    {
        $strategies = Strategy::withCount('assignments')->orderBy('name')->paginate(20);

        return view('admin.strategies.index', compact('strategies'));
    }

    /**
     * Toggle a strategy as the default fallback. At most one strategy is default at a time;
     * it applies to any device with no device/user/device-group assignment.
     */
    public function setDefault(Strategy $strategy): RedirectResponse
    {
        $makeDefault = ! $strategy->is_default;

        Strategy::query()->where('is_default', true)->update(['is_default' => false]);

        if ($makeDefault) {
            $strategy->forceFill(['is_default' => true])->save();
        }

        return redirect()
            ->route('admin.strategies.index')
            ->with('status', $makeDefault ? "“{$strategy->name}” is now the default strategy." : 'Default strategy cleared.');
    }

    public function create(): View
    {
        $strategy = new Strategy(['enabled' => true, 'options' => []]);

        return view('admin.strategies.create', compact('strategy'));
    }

    public function store(Request $request): RedirectResponse
    {
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

    public function edit(Strategy $strategy): View
    {
        $strategy->load('assignments');

        // Device groups are few, so the picker stays a plain select; devices and users are
        // chosen with a searchable combobox (so we never load thousands of rows here).
        $deviceGroups = DeviceGroup::orderBy('name')->get(['id', 'name']);

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
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'opt' => ['nullable', 'array'],
            'opt.*' => ['nullable', 'string'],
            'option_keys' => ['nullable', 'array'],
            'option_keys.*' => ['nullable', 'string', 'max:255'],
            'option_values' => ['nullable', 'array'],
            'option_values.*' => ['nullable', 'string'],
        ]);

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
        $data = $request->validate([
            'target_type' => ['required', Rule::in([
                StrategyAssignment::TARGET_DEVICE,
                StrategyAssignment::TARGET_USER,
                StrategyAssignment::TARGET_DEVICE_GROUP,
            ])],
            'target_id' => ['required', 'integer'],
        ]);

        $strategy->assignments()->firstOrCreate([
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
        ]);

        $strategy->forceFill(['modified_at' => time()])->save();

        return redirect()
            ->route('admin.strategies.edit', $strategy)
            ->with('status', 'Assignment added.');
    }

    public function destroyAssignment(StrategyAssignment $assignment): RedirectResponse
    {
        $strategyId = $assignment->strategy_id;
        $assignment->delete();

        Strategy::whereKey($strategyId)->update(['modified_at' => time()]);

        return redirect()
            ->route('admin.strategies.edit', $strategyId)
            ->with('status', 'Assignment removed.');
    }

    public function destroy(Strategy $strategy): RedirectResponse
    {
        $strategy->assignments()->delete();
        $strategy->delete();

        return redirect()
            ->route('admin.strategies.index')
            ->with('status', 'Strategy deleted.');
    }
}
