<?php

namespace App\Http\Requests\Sport;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * GET /sport/calendar?from=&to= — fenêtre de 62 jours maximum (addendum §C.2),
 * mois en cours par défaut.
 */
class IndexCalendarRequest extends SportFormRequest
{
    public const MAX_DAYS = 62;

    public const MSG_RANGE = 'La période demandée ne peut pas dépasser 62 jours.';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->input('from');
            $to = $this->input('to');

            if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
                return;
            }

            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('to', self::MSG_RANGE);
            }
        });
    }
}
