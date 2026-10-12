<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `feature:<flag>` — blocks endpoints of disabled features (Chapter 03 §3.3).
 */
final class RequireFeature
{
    public function __construct(private readonly PlatformConfig $config) {}

    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if (! $this->config->feature($flag)) {
            throw DomainException::featureDisabled($flag);
        }

        return $next($request);
    }
}
