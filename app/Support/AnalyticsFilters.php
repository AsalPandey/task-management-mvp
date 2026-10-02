<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AnalyticsFilters
{
    public static function fromRequest(Request $request): array
    {
        return $request->validate([
            'dateFrom' => ['nullable', 'required_with:dateTo', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'required_with:dateFrom', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'assignee' => InputContracts::id('nullable'),
            'project' => InputContracts::id('nullable'),
        ]);
    }
}
