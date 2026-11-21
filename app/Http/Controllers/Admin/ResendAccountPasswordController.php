<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

final class ResendAccountPasswordController extends Controller
{
    public function __invoke(Request $request, User $user): JsonResponse
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('update user');

        $status = Password::sendResetLink(['email' => $user->email]);

        return response()->json([
            'status' => $status === Password::RESET_LINK_SENT ? 'success' : 'error',
            'message' => __($status),
        ], $status === Password::RESET_LINK_SENT ? 200 : ($status === Password::RESET_THROTTLED ? 429 : 422));
    }
}
