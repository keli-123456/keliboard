<?php

namespace Plugin\Cloudmanger\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class MeController extends Controller
{
    public function me()
    {
        $user = Auth::guard()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $this->success([
            'id' => $user->id,
            'email' => $user->email ?? null,
            'is_admin' => (bool) ($user->is_admin ?? false),
        ]);
    }
}

