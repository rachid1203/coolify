<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyProjectScopedBreakout
{
    /**
     * API paths a project member may call. Team-wide infrastructure stays closed.
     *
     * @var list<string>
     */
    private const API_ALLOW = [
        'projects',
        'applications',
        'services',
        'databases',
        'deploy',
        'deployments',
        'version',
    ];

    /**
     * Team reads a project member may call. Prefix matching would also open
     * shared team variables under /team/envs.
     *
     * @var list<string>
     */
    private const API_ALLOW_EXACT = [
        'team',
        'team/members',
        'teams',
        'teams/current',
        'teams/current/members',
    ];

    /**
     * Web paths a project member may open.
     *
     * @var list<string>
     */
    private const WEB_ALLOW = [
        '',
        'projects',
        'project',
        'profile',
        'livewire',
        'force-password-reset',
        'invitations',
        'verify',
        'email',
        'realtime',
        'security/api-tokens',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->isProjectScoped()) {
            return $next($request);
        }

        if ($this->allowed($request)) {
            return $next($request);
        }

        abort(403, 'You do not have permission to access this page.');
    }

    private function allowed(Request $request): bool
    {
        $path = trim($request->path(), '/');

        if (str_starts_with($path, 'api/')) {
            $relative = (string) preg_replace('#^api/v\d+/#', '', $path);

            return $this->matches(self::API_ALLOW, $relative) || $this->allowsTeamRead($relative);
        }

        return $this->matches(self::WEB_ALLOW, $path);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matches(array $prefixes, string $path): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || ($prefix !== '' && str_starts_with($path, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }

    private function allowsTeamRead(string $path): bool
    {
        if (in_array($path, self::API_ALLOW_EXACT, true)) {
            return true;
        }

        return preg_match('#^teams/\d+(?:/members)?$#', $path) === 1;
    }
}
