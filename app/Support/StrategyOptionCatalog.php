<?php

namespace App\Support;

/**
 * Read access to the client-option catalog (config/strategy_options.php) and the rule that
 * decides whether a stored value is one the editor's control can express.
 *
 * A strategy keeps every pushed value in one flat `options` map; "catalog" versus "custom" is
 * only a display split in the editor. A value an administrator stored always wins — the server
 * never adds, rewrites or drops an option value by itself — so the editor and the save path
 * both need to know when a stored value falls outside the vocabulary of its control instead of
 * quietly rendering it as "Default" and losing it on the next save.
 */
final class StrategyOptionCatalog
{
    /**
     * Every catalog option keyed by its wire key, in tab order, with the tab and section it is
     * rendered under.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $definitions = [];

        foreach ((array) config('strategy_options.tabs', []) as $tab) {
            foreach ((array) ($tab['sections'] ?? []) as $section) {
                foreach ((array) ($section['options'] ?? []) as $option) {
                    $key = (string) ($option['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $definitions[$key] = $option + [
                        'tab' => (string) ($tab['key'] ?? ''),
                        'section' => (string) ($section['label'] ?? ''),
                    ];
                }
            }
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Whether the option's control can express this value. A value it cannot express must be
     * preserved verbatim and rendered as-is: it belongs to an administrator, and the server does
     * not drop it by itself. An empty string is always expressible — that is the control's
     * "Default" choice, where the key is simply left out of the pushed config.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function acceptsValue(array $definition, string $value): bool
    {
        if ($value === '') {
            return true;
        }

        return match ((string) ($definition['type'] ?? 'text')) {
            'toggle' => in_array($value, ['Y', 'N'], true),
            'select' => array_key_exists($value, (array) ($definition['choices'] ?? [])),
            // A leading zero is left out on purpose: a `type="number"` input normalises `007` to
            // `7` and would rewrite the stored value on the next save.
            'number' => preg_match('/\A(0|[1-9][0-9]*)\z/', $value) === 1,
            // A text field renders any single-line value unchanged, and the save path already
            // rejects control characters, so it can always express what is stored.
            default => true,
        };
    }

    /**
     * Whether the client reads this key through the "on only for exactly `Y`" branch of
     * `option2bool()` — `allow-` keys plus `stop-service`, `direct-server` and
     * `force-always-relay` (rustdesk a0c0963c -> libs/hbb_common @ 450cc1d7,
     * `src/config.rs:2924-2936`; the Flutter client mirrors it in
     * `flutter/lib/common.dart:1616-1628`). For those keys any other stored value leaves the
     * option off, which is worth saying when the value is one the control cannot express.
     */
    public static function onlyYMeansOn(string $key): bool
    {
        return str_starts_with($key, 'allow-')
            || in_array($key, ['stop-service', 'direct-server', 'force-always-relay'], true);
    }
}
