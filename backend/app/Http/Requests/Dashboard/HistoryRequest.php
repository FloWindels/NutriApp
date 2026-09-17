<?php

namespace App\Http\Requests\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class HistoryRequest extends FormRequest
{
    public const MAX_JOURS = 92;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'date de début',
            'to' => 'date de fin',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $from = $this->input('from');
            $to = $this->input('to');
            if (! is_string($from) || ! is_string($to) || $v->errors()->isNotEmpty()) {
                return;
            }
            try {
                $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
            } catch (\Throwable) {
                return;
            }
            if ($days > self::MAX_JOURS) {
                $v->errors()->add('to', sprintf('La période ne peut pas dépasser %d jours.', self::MAX_JOURS));
            }
        });
    }
}
