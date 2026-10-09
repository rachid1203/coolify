<?php

namespace App\Support;

use Livewire\ComponentHook;

class ProjectScopedComponentGuard extends ComponentHook
{
    public function mount($params): void
    {
        $this->deny();
    }

    public function call($method, $params, $returnEarly): void
    {
        $this->deny();
    }

    private function deny(): void
    {
        if (! auth()->user()?->isProjectScoped()) {
            return;
        }

        $class = $this->component::class;
        $blocked = [
            'App\\Livewire\\Server\\',
            'App\\Livewire\\Security\\PrivateKey\\',
            'App\\Livewire\\Security\\Cloud',
            'App\\Livewire\\Security\\Integration',
            'App\\Livewire\\Source\\',
            'App\\Livewire\\Storage\\',
            'App\\Livewire\\Destination\\',
            'App\\Livewire\\Team\\',
            'App\\Livewire\\Settings\\',
            'App\\Livewire\\Notifications\\',
            'App\\Livewire\\Terminal\\',
        ];

        foreach ($blocked as $prefix) {
            if (str_starts_with($class, $prefix)) {
                abort(403, 'You do not have permission to access this page.');
            }
        }
    }
}
