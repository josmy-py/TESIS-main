<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Dui implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $dui = str_replace('-', '', $value);

        if(!preg_match('/^[0-9]{9}$/', $dui)) {
            $fail('Campo Dui no cumple con el formato requerido.');
            return;
        }

        $digits = substr($dui, 0, 8);
        $checkDigit = (int)substr($dui, 8, 1);

        $sum = 0;
        for($i = 0; $i < 8; $i++) {
            $sum += (int)substr($digits, $i, 1) * (9 - $i);
        }

        $verificadorCalculated = (10 - ($sum % 10)) % 10;

        if($verificadorCalculated !== $checkDigit) {
            $fail('Campo Dui no cumple o es inválido.');
        }
    }
}
