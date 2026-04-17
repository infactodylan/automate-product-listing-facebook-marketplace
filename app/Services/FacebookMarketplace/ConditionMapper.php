<?php

namespace App\Services\FacebookMarketplace;

/**
 * Maps arbitrary scraped condition strings to Meta's allowed dropdown values.
 *
 * Rule: prefer keyword matches (New / Like New / Fair / Good). When unknown or
 * empty, default to "Used - Good" so uploads stay valid.
 */
class ConditionMapper
{
    public function toAllowedCondition(?string $raw): string
    {
        $value = strtolower(trim((string) $raw));

        if ($value === '') {
            return 'Used - Good';
        }

        if (str_contains($value, 'like new')) {
            return 'Used - Like New';
        }

        if (preg_match('/\bnew\b/', $value) === 1 && ! str_contains($value, 'used')) {
            return 'New';
        }

        if (str_contains($value, 'fair')) {
            return 'Used - Fair';
        }

        if (str_contains($value, 'good')) {
            return 'Used - Good';
        }

        return 'Used - Good';
    }
}
