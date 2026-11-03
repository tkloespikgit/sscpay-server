<?php

namespace App\Filament\Resources\SystemConfigResource\Pages;

use App\Filament\Resources\SystemConfigResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSystemConfigs extends ListRecords
{
    protected static string $resource = SystemConfigResource::class;

    public function getTitle(): string
    {
        return __('admin.system_config.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('admin.system_config.list_description');
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $groups = SystemConfigResource::getEloquentQuery()
            ->select('group')
            ->selectRaw('COUNT(*) as config_count')
            ->groupBy('group')
            ->orderBy('group')
            ->get();

        $tabs = [
            'all' => Tab::make(__('admin.system_config.all_groups'))
                ->badge($groups->sum('config_count')),
        ];

        foreach ($groups as $configGroup) {
            $group = $configGroup->group;
            $tabs[$group === null ? 'ungrouped' : 'group:'.$group] = Tab::make(SystemConfigResource::groupLabel($group))
                ->badge($configGroup->config_count)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('group', $group));
        }

        return $tabs;
    }
}
