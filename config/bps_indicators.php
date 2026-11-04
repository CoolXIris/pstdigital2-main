<?php

$mapPath = __DIR__.'/bps_indicators.json';
$contents = file_get_contents($mapPath);
if ($contents === false) {
    throw new RuntimeException('Shared BPS indicator map is unavailable.');
}

$configuration = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
if (! is_array($configuration) || ! is_array($configuration['groups'] ?? null)) {
    throw new RuntimeException('Shared BPS indicator map has an invalid format.');
}

return $configuration;
