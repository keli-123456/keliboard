<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Tests\TestCase;

final class ServerSaveRequestTest extends TestCase
{
    public function test_v2node_rejects_anytls_custom_transport_until_native_core_supports_it(): void
    {
        $request = ServerSave::create('/api/v2/admin/server/manage/save', 'POST', [
            'type' => Server::TYPE_ANYTLS,
            'runtime' => Server::RUNTIME_V2NODE,
            'name' => 'AnyTLS WS',
            'host' => 'anytls.example.com',
            'port' => 443,
            'server_port' => 10443,
            'rate' => 1,
            'protocol_settings' => [
                'tls_mode' => 1,
                'network' => 'ws',
                'tls' => [
                    'server_name' => 'anytls.example.com',
                    'allow_insecure' => false,
                ],
            ],
        ]);

        $validatorFactory = new ValidatorFactory(new Translator(new ArrayLoader(), 'en'));
        $validator = $validatorFactory->make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('protocol_settings.network'));
    }
}
