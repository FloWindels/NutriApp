<?php

namespace App\Http\Requests\Meals;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Photo d'assiette envoyée pour analyse.
 *
 * Le navigateur compresse déjà l'image en JPEG (web/src/lib/image-resize.ts) ; la borne
 * serveur est volontairement plus haute que la borne navigateur, pour ne pas rejeter une
 * image que le client considérait comme valide. Elle se mesure en octets décodés.
 */
class AnalyzePlateRequest extends FormRequest
{
    /** 500 Ko décodés, contre 350 Ko côté navigateur. */
    public const MAX_BYTES = 500 * 1024;

    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! preg_match('#^data:([a-z/+-]+);base64,#i', $value, $matches)) {
                    $fail('La photo doit être envoyée en data URI base64.');

                    return;
                }

                if (! in_array(strtolower($matches[1]), self::MEDIA_TYPES, true)) {
                    $fail('Format d’image non pris en charge : utilise JPEG, PNG ou WebP.');

                    return;
                }

                $binaire = base64_decode(substr($value, strlen($matches[0])), true);

                if ($binaire === false || $binaire === '') {
                    $fail('La photo est illisible.');

                    return;
                }

                if (strlen($binaire) > self::MAX_BYTES) {
                    $fail('La photo est trop lourde : réduis-la avant de l’envoyer.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['image' => 'photo'];
    }

    /** Type MIME déclaré par le data URI, déjà validé. */
    public function mediaType(): string
    {
        preg_match('#^data:([a-z/+-]+);base64,#i', (string) $this->input('image'), $matches);

        return strtolower($matches[1] ?? 'image/jpeg');
    }

    /** Image en base64 nu, sans le préfixe `data:`. */
    public function base64(): string
    {
        $value = (string) $this->input('image');
        $position = strpos($value, ',');

        return $position === false ? $value : substr($value, $position + 1);
    }
}
