<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Mail\Users\ResendAccountPassword;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

final class ResendAccountPasswordController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return Response
     */
    public function __invoke(Request $request, User $user)
    {
        $newPassword = Helper::generateRandomPassword();

        $user->update(['password' => bcrypt($newPassword)]);

        Mail::to($user)
            ->queue(new ResendAccountPassword([
                'user' => $user->displayName(),
                'email' => $user->email,
                'password' => $newPassword,
            ]));

        return response()->json([
            'status' => 'success',
            'message' => 'A new password was generated and resent to the user successfully.',
        ]);
    }
}
