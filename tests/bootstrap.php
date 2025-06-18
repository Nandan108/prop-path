<?php

require __DIR__.'/../vendor/autoload.php';

/** @psalm-suppress RiskyTruthyFalsyComparison */
if (PHP_SAPI === 'cli' && ($_SERVER['COLLISION_TESTING'] ?? false)) {
    /** @psalm-suppress InternalMethod */
    (new NunoMaduro\Collision\Provider())->register();
}
