<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $countries = array_column(json_decode(file_get_contents(resource_path('country-calling-codes.json')), true, 512, JSON_THROW_ON_ERROR)['countries'], 'calling_code', 'code');
        $callingCode = is_string($input['country_code'] ?? null) ? ($countries[$input['country_code']] ?? null) : null;
        if (isset($input['phone']) && is_string($input['phone'])) {
            $input['phone'] = preg_replace('/[ ()-]/u', '', Money::digits($input['phone']));
            if ($callingCode && str_starts_with($input['phone'], '+'.$callingCode)) {
                $input['phone'] = substr($input['phone'], strlen($callingCode) + 1);
            }
        }
        Validator::make($input, [
            'is_platform_admin' => ['prohibited'],
            'role' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', Rule::in(array_keys($countries))],
            'phone' => ['required', 'string', 'max:15', 'regex:/^[0-9]{4,15}$/D', function (string $attribute, mixed $value, \Closure $fail) use ($callingCode): void {
                if ($callingCode && is_string($value) && strlen($callingCode.$value) > 15) {
                    $fail('Phone number exceeds supported length.');
                }
            }],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'phone' => $input['phone'],
            'country_code' => $input['country_code'],
            'email' => strtolower(trim($input['email'])),
            'password' => Hash::make($input['password']),
        ]);
    }
}
