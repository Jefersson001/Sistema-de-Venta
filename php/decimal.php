<?php
declare(strict_types=1);

/** Exact half-up rounding. Extra precision is used only while calculating. */
function decimalRound(string $value): string
{
    if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) {
        throw new InvalidArgumentException('Indica un número decimal válido.');
    }
    $negative = str_starts_with($value, '-');
    $absolute = ltrim($value, '-');
    $rounded = bcadd($absolute, '0.005', 2);
    return $negative && bccomp($rounded, '0', 2) !== 0 ? '-'.$rounded : $rounded;
}

/** Format without converting decimal strings to floating point. */
function decimalDisplay(string $value): string
{
    $rounded = decimalRound($value);
    [$whole, $fraction] = explode('.', $rounded);
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);
    return $whole.','.$fraction;
}

/** Normalize input presentation without concealing an invalid extra fraction. */
function decimalInput(string $value): string
{
    $normalized = str_replace(',', '.', trim($value));
    if (!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2}0*)?$/D', $normalized)) return $value;
    return bcadd($normalized, '0', 2);
}
