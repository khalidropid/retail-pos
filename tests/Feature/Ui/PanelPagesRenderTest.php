<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\UserSeeder;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Opens every resource index/create/view page and every custom page as an
 * admin, so a runtime error anywhere in the panel fails the build.
 */
class PanelPagesRenderTest extends TestCase
{
    public function test_every_panel_page_renders_for_admin(): void
    {
        $this->seed([UserSeeder::class, CatalogSeeder::class]);
        $admin = User::query()->where('email', 'admin@pos.test')->firstOrFail();
        $this->actingAs($admin);

        $urls = ['/admin'];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $pages = $resource::getPages();
            $urls[] = $resource::getUrl('index');
            if (isset($pages['create'])) {
                $urls[] = $resource::getUrl('create');
            }
            $record = $resource::getModel()::query()->first();
            if ($record) {
                foreach (['view', 'edit'] as $page) {
                    if (isset($pages[$page]) && ($page === 'view' ? $resource::canView($record) : $resource::canEdit($record))) {
                        $urls[] = $resource::getUrl($page, ['record' => $record]);
                    }
                }
            }
        }
        foreach (Filament::getPanel('admin')->getPages() as $page) {
            $urls[] = $page::getUrl();
        }

        $failures = [];
        foreach (array_unique($urls) as $url) {
            $response = $this->get($url);
            if ($response->status() !== 200) {
                $failures[] = $url.' => '.$response->status().' '.mb_substr((string) ($response->exception?->getMessage() ?? ''), 0, 300);
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
