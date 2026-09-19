<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Scores standardised self-report instruments. Bands follow each instrument's published cut-offs.
 * A score is a screening result, not a diagnosis; the clinician interprets it.
 */
class ScreeningScorer
{
    public const INSTRUMENTS = [
        'phq9' => ['items' => 9, 'max' => 3, 'flag_item' => 8],   // item 9 (index 8): thoughts of self-harm
        'gad7' => ['items' => 7, 'max' => 3, 'flag_item' => null],
        'pss10' => ['items' => 10, 'max' => 4, 'flag_item' => null, 'reverse' => [3, 4, 6, 7]],
        'isi' => ['items' => 7, 'max' => 4, 'flag_item' => null],
        'pcl5' => ['items' => 20, 'max' => 4, 'flag_item' => null],
        'audit_c' => ['items' => 3, 'max' => 4, 'flag_item' => null],
    ];

    public function score(string $instrument, array $answers): array
    {
        $spec = self::INSTRUMENTS[$instrument] ?? throw new InvalidArgumentException("unknown instrument {$instrument}");
        if (count($answers) !== $spec['items']) {
            throw new InvalidArgumentException("{$instrument} expects {$spec['items']} answers");
        }
        $total = 0;
        foreach ($answers as $i => $a) {
            $a = (int) $a;
            if ($a < 0 || $a > $spec['max']) {
                throw new InvalidArgumentException('answer out of range');
            }
            if (in_array($i, $spec['reverse'] ?? [], true)) {
                $a = $spec['max'] - $a;
            }
            $total += $a;
        }
        $flag = $spec['flag_item'] !== null && (int) $answers[$spec['flag_item']] > 0;

        return ['total' => $total, 'band' => $this->band($instrument, $total), 'item_flag' => $flag];
    }

    public function band(string $instrument, int $t): string
    {
        return match ($instrument) {
            'phq9' => $t <= 4 ? 'minimal' : ($t <= 9 ? 'mild' : ($t <= 14 ? 'moderate' : ($t <= 19 ? 'moderately_severe' : 'severe'))),
            'gad7' => $t <= 4 ? 'minimal' : ($t <= 9 ? 'mild' : ($t <= 14 ? 'moderate' : 'severe')),
            'pss10' => $t <= 13 ? 'low' : ($t <= 26 ? 'moderate' : 'high'),
            'isi' => $t <= 7 ? 'none' : ($t <= 14 ? 'subthreshold' : ($t <= 21 ? 'moderate' : 'severe')),
            'pcl5' => $t >= 33 ? 'above_cutoff' : 'below_cutoff',
            'audit_c' => $t >= 4 ? 'positive_screen' : 'negative_screen',
            default => 'n/a',
        };
    }
}
