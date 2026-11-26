<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use App\Services\AdminScopeService;
use App\Services\ClientConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin REST API (v1) — strategies. Read needs `strategies.read`; create/update need
 * `strategies.write`. Writes bump `modified_at` so the heartbeat re-pushes the options.
 */
class StrategyController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $strategies = $this->scope->scopeStrategies(Strategy::query(), $request->user(), 'strategies.view')
            ->withCount('assignments')
            ->orderBy('name')
            ->paginate($perPage, ['id', 'name', 'note', 'enabled', 'options', 'modified_at']);

        return response()->json($strategies);
    }

    /**
     * POST /api/v1/strategies — create a strategy with an optional config_options map.
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope->authorizeUnrestricted($request->user(), 'strategies.edit');
        $data = $this->validateStrategy($request, creating: true);

        $strategy = Strategy::create([
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
            'enabled' => $data['enabled'] ?? true,
            'options' => $this->storedOptions($request, (array) ($data['options'] ?? [])),
            'modified_at' => time(),
        ]);

        return response()->json(['data' => $this->shape($strategy)], 201);
    }

    /**
     * PUT /api/v1/strategies/{strategy} — update name/note/enabled/options. Any change bumps
     * `modified_at`, so connected clients pick up the new options on their next heartbeat.
     *
     * The `options` map is a plain config_options map, not the editor's catalog-plus-custom
     * split, so there is no duplicate-key case here: it replaces the stored map exactly as sent.
     * The server adds nothing, rewrites nothing and drops nothing from it — a value an
     * administrator stored always wins, and only this endpoint's own caller decides what the map
     * contains. (`Strategy::pushableOptions()` filters keys the client no longer defines out of
     * the heartbeat push only.) The one exception is a key sent as JSON `null`, which means the
     * editor's *Default* and leaves the stored map — see `storedOptions()`.
     */
    public function update(Request $request, Strategy $strategy): JsonResponse
    {
        $this->scope->authorizeStrategy($request->user(), (int) $strategy->id, 'strategies.edit');
        $data = $this->validateStrategy($request, creating: false);

        $strategy->fill(array_filter([
            'name' => $data['name'] ?? null,
            'note' => $data['note'] ?? null,
        ], static fn ($v) => $v !== null));

        if (array_key_exists('enabled', $data)) {
            $strategy->enabled = $data['enabled'];
        }
        if (array_key_exists('options', $data)) {
            $options = $this->storedOptions($request, (array) ($data['options'] ?? []));
            if (! $this->scope->isUnrestricted($request->user(), 'strategies.edit')) {
                $changed = ClientConfigService::changedFullAdminOnlyKeys((array) ($strategy->options ?? []), $options);
                if ($changed !== []) {
                    throw ValidationException::withMessages([
                        'options' => 'Only a full administrator can set or change: '.implode(', ', $changed).'.',
                    ]);
                }
            }
            // Written through fill(), as the admin editor's save does: the analyzer reads the
            // model's `casts()` docblock (@return array<string, string>) as the property's type,
            // so a direct write of this array does not analyse.
            $strategy->fill(['options' => $options]);
        }

        $strategy->modified_at = time();
        $strategy->save();

        $response = ['data' => $this->shape($strategy)];

        // A key the client strips from a server push is still accepted (older clients honour it),
        // but the caller is told instead of believing the policy now applies.
        $warning = ClientConfigService::strategyIgnoredWarning(array_keys((array) ($strategy->options ?? [])));
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return response()->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStrategy(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            // config_options are string-valued (tri-state "" / "Y" / "N" or free text).
            'options.*' => ['nullable', 'string', static function (string $attribute, mixed $value, $fail): void {
                if (ClientConfigService::containsControlCharacters((string) $value)) {
                    $fail('Strategy option values must be a single line without control characters.');
                }
            }],
        ]);

        foreach (array_keys((array) ($data['options'] ?? [])) as $key) {
            if (! ClientConfigService::isValidOptionKey((string) $key)) {
                throw ValidationException::withMessages([
                    'options' => 'Strategy option keys may contain only letters, numbers, periods, underscores, and hyphens.',
                ]);
            }
        }

        return $data;
    }

    /**
     * The options map to store: a key the caller sent as JSON `null` is the editor's *Default*
     * and leaves the map entirely, so nothing is pushed for it and the client keeps its own
     * value. Stored as-is it would be cast to `""` by `Strategy::pushableOptions()`, and a
     * RustDesk client reads an empty value as "delete this key" — the opposite of a default.
     * An empty string is a value of its own: it is stored and pushed as `""` exactly as before.
     *
     * The full-admin-only guard is handed the returned map, so a delegated caller who sends
     * `null` for a protected key that is stored is still removing it, and still rejected.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, string>
     */
    private function storedOptions(Request $request, array $options): array
    {
        $posted = $this->postedOptions($request);

        $stored = [];
        foreach ($options as $key => $value) {
            // `ConvertEmptyStringsToNull` (a framework default) rewrites every `""` in the
            // request to null before the controller sees it, so the raw body is the only place
            // the difference between the two survives.
            if ($value === null && ($posted[$key] ?? null) === '') {
                $value = '';
            }

            if ($value !== null) {
                $stored[(string) $key] = (string) $value;
            }
        }

        return $stored;
    }

    /**
     * The `options` map as the caller wrote it, before the framework's empty-string conversion.
     *
     * @return array<array-key, mixed>
     */
    private function postedOptions(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) && is_array($body['options'] ?? null) ? $body['options'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Strategy $strategy): array
    {
        return $strategy->only(['id', 'name', 'note', 'enabled', 'options', 'modified_at']);
    }
}
