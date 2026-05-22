<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Passport\AuthForget;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Tests\TestCase;

final class AuthForgetRequestTest extends TestCase
{
    public function test_forget_password_rejects_boolean_email_code(): void
    {
        $request = AuthForget::create('/api/v1/passport/auth/forget', 'POST', [
            'email' => 'user@example.com',
            'password' => 'new-password',
            'email_code' => false,
        ]);

        $validator = $this->validatorFactory()->make(
            $request->all(),
            $request->rules(),
            $request->messages()
        );

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('email_code'));
    }

    public function test_forget_password_accepts_six_digit_email_code(): void
    {
        $request = AuthForget::create('/api/v1/passport/auth/forget', 'POST', [
            'email' => 'user@example.com',
            'password' => 'new-password',
            'email_code' => '123456',
        ]);

        $validator = $this->validatorFactory()->make(
            $request->all(),
            $request->rules(),
            $request->messages()
        );

        $this->assertTrue($validator->passes());
    }

    private function validatorFactory(): ValidatorFactory
    {
        return new ValidatorFactory(new Translator(new ArrayLoader(), 'en'));
    }
}
