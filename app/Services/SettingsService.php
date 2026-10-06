<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SettingsService
{
    private const CACHE_KEY = 'store_settings.all';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key] ?? $default;
        }

        return $this->defaults()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $this->loaded = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return Setting::query()->pluck('value', 'key')->all();
            });
        } catch (Throwable) {
            $this->loaded = [];
        }

        return $this->loaded;
    }

    /**
     * Persist several settings at once and return the previous values.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function setMany(array $values): array
    {
        $groups = $this->keyGroups();
        $before = [];

        DB::transaction(function () use ($values, $groups, &$before) {
            foreach ($values as $key => $value) {
                $before[$key] = $this->get($key);
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['group' => $groups[$key] ?? 'general', 'value' => $value],
                );
            }
        });

        $this->flush();

        return $before;
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    /** Insert any missing defaults without overwriting existing values. */
    public function seedDefaults(): void
    {
        foreach (config('store.defaults') as $group => $items) {
            foreach ($items as $key => $value) {
                Setting::query()->firstOrCreate(['key' => $key], ['group' => $group, 'value' => $value]);
            }
        }

        $this->flush();
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return Arr::collapse(config('store.defaults', []));
    }

    /** @return array<string, string> */
    private function keyGroups(): array
    {
        $map = [];
        foreach (config('store.defaults', []) as $group => $items) {
            foreach (array_keys($items) as $key) {
                $map[$key] = $group;
            }
        }

        return $map;
    }
}
