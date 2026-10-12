<?php

declare(strict_types=1);

namespace App\Support\Testing;

use RuntimeException;

/** Thrown by FaultInjector at an armed point. */
final class InjectedFault extends RuntimeException {}
