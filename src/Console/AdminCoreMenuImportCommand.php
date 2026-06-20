<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\MenuItem;

/**
 * Copies the config sidebar menu (config('admin-core.menu') or a named portal menu)
 * into the menu_items table, so switching to `menu_source = 'database'` starts from
 * the menu you already have instead of a blank slate. Headers, nesting, icons,
 * routes/URLs, `can` permissions and `match` patterns all round-trip.
 */
class AdminCoreMenuImportCommand extends Command
{
    protected $signature = 'admin-core:menu:import
                            {--menu= : Import a named portal menu (config admin-core.menus.NAME) instead of the default}
                            {--force : Replace existing menu_items rows}';

    protected $description = 'Import the config sidebar menu into the menu_items table (for menu_source=database)';

    public function handle(): int
    {
        if (! Schema::hasTable('menu_items')) {
            $this->error('The menu_items table is missing — run `php artisan admin-core:install --access` and migrate first.');

            return self::FAILURE;
        }

        $name = $this->option('menu');
        $items = $name ? config('admin-core.menus.' . $name, []) : config('admin-core.menu', []);

        if ($items === []) {
            $this->warn('Nothing to import — the ' . ($name ? "'{$name}'" : 'default') . ' config menu is empty.');

            return self::SUCCESS;
        }

        if (MenuItem::query()->exists()) {
            if (! $this->option('force')) {
                $this->error('menu_items already has rows. Re-run with --force to replace them.');

                return self::FAILURE;
            }
            MenuItem::query()->update(['parent_id' => null]); // drop self-FK refs before bulk delete
            MenuItem::query()->delete();
        }

        $count = $this->insert($items, null);
        MenuItem::forgetCache();

        $this->info("Imported {$count} menu item(s) into menu_items.");
        $this->line("Set 'menu_source' => 'database' in config/admin-core.php (or ADMIN_CORE_MENU_SOURCE=database) to use it.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function insert(array $items, ?int $parentId): int
    {
        $count = 0;
        $sort = 0;

        foreach ($items as $item) {
            $row = MenuItem::create([
                'parent_id' => $parentId,
                'label' => $item['header'] ?? $item['label'] ?? 'Item',
                'icon' => $item['icon'] ?? null,
                'route' => $item['route'] ?? null,
                'url' => $item['url'] ?? null,
                'match' => $item['match'] ?? null,
                'permission' => $item['can'] ?? null,
                'target' => $item['target'] ?? null,
                'sort' => ++$sort,
                'is_active' => true,
            ]);
            $count++;

            if (! empty($item['children'])) {
                $count += $this->insert($item['children'], $row->id);
            }
        }

        return $count;
    }
}
