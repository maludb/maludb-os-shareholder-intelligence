<?php
declare(strict_types=1);

/** The ownership screens' chips (slice 5): a transaction code with its meaning on hover, a 13F change state. */

function code_chip(?string $code): string
{
    if ($code === null) {
        return '<span class="badge text-bg-light" title="A holding, not a transaction">holding</span>';
    }
    $c = in_array($code, ['S', 'F', 'D'], true) ? 'text-bg-danger' : (in_array($code, ['P'], true) ? 'text-bg-success' : 'text-bg-secondary');
    return '<span class="badge ' . $c . '" title="' . e(INSIDER_CODES[$code] ?? 'Code ' . $code) . '">' . e($code) . '</span>';
}

function change_state_chip(string $s): string
{
    $c = match ($s) { 'new' => 'text-bg-success', 'out' => 'text-bg-danger', 'up' => 'bg-soft-success text-success', 'down' => 'bg-soft-danger text-danger', default => 'text-bg-light' };
    return '<span class="badge ' . $c . '">' . e($s) . '</span>';
}
