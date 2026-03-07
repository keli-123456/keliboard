<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        $data = [
            'username' => $response->result->username
        ];
        return $this->success($data);
    }

    public function unbind(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            throw new ApiException('User not found', 404);
        }

        if ($user->telegram_id === null) {
            return $this->success(true);
        }

        $user->telegram_id = null;
        if (!$user->save()) {
            return $this->fail([500, '解绑失败']);
        }

        return $this->success(true);
    }
}
