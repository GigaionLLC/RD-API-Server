<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin REST API (v1) — strategies. Read needs `strategies.read`; create/update need
 * `strategies.write`. Writes bump `modified_at` so the heartbeat re-pushes the options.
 */
class StrategyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $strategies = Strategy::query()
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
        $data = $this->validateStrategy($request, creating: true);

        $strategy = Strategy::create([
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
            'enabled' => $data['enabled'] ?? true,
            'options' => $data['options'] ?? [],
            'modified_at' => time(),
        ]);

        return response()->json(['data' => $this->shape($strategy)], 201);
    }

    /**
     * PUT /api/v1/strategies/{strategy} — update name/note/enabled/options. Any change bumps
     * `modified_at`, so connected clients pick up the new options on their next heartbeat.
     */
    public function update(Request $request, Strategy $strategy): JsonResponse
    {
        $data = $this->validateStrategy($request, creating: false);

        $strategy->fill(array_filter([
            'name' => $data['name'] ?? null,
            'note' => $data['note'] ?? null,
        ], static fn ($v) => $v !== null));

        if (array_key_exists('enabled', $data)) {
            $strategy->enabled = $data['enabled'];
        }
        if (array_key_exists('options', $data)) {
            $strategy->options = $data['options'];
        }

        $strategy->modified_at = time();
        $strategy->save();

        return response()->json(['data' => $this->shape($strategy)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStrategy(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            // config_options are string-valued (tri-state "" / "Y" / "N" or free text).
            'options.*' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Strategy $strategy): array
    {
        return $strategy->only(['id', 'name', 'note', 'enabled', 'options', 'modified_at']);
    }
}
