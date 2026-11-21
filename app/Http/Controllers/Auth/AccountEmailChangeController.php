<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\StaffAccountSecurity;
use App\Http\Requests\Auth\ConfirmAccountEmailChangeRequest;
use App\Http\Requests\Auth\RequestAccountEmailChangeRequest;
use App\Models\User;
use App\Services\Auth\AccountEmailChanges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AccountEmailChangeController extends Controller
{
    public function __construct(private readonly AccountEmailChanges $changes) {}

    /**
     * @param  string  $method
     * @param  array<int|string, mixed>  $parameters
     */
    public function callAction($method, $parameters): mixed
    {
        try {
            $response = parent::callAction($method, $parameters);
        } catch (ValidationException $exception) {
            $response = request()->expectsJson()
                ? response()->json(['message' => 'Review the highlighted fields.', 'errors' => $exception->errors()], 422)
                : redirect()->back()->withErrors($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            $response = request()->expectsJson()
                ? response()->json(['message' => 'This email change cannot be completed. Open a current link while signed in to the same account.'], $exception->getStatusCode())
                : response()->view('auth.account-email-change', ['change' => null, 'message' => 'This email change cannot be completed. Open a current link while signed in to the same account.'], $exception->getStatusCode());
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function store(RequestAccountEmailChangeRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->changes->request($request->user(), $request->validated('email'), $request->validated('password'), $request->validated('code'));

        return response()->json(['message' => 'Check your new mailbox for a verification link. Your email has not changed yet.'], 202);
    }

    public function show(Request $request, string $change): \Illuminate\Http\Response
    {
        $record = $this->changes->pending($request->user(), $change);

        return response()->view('auth.account-email-change', [
            'change' => ['id' => $record->id, 'email' => $record->new_email],
            'mfaRequired' => (bool) ($request->user()->two_factor_secret && $request->user()->two_factor_confirmed_at),
        ]);
    }

    public function confirm(ConfirmAccountEmailChangeRequest $request, string $change): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        $user = $this->changes->confirm($request->user(), $change, $request->validated('token'), $request->validated('password'), $request->validated('code'));
        Auth::guard('web')->setUser($user);
        $request->setUserResolver(fn (): User => $user);
        $request->session()->regenerate(true);
        $request->session()->put(StaffAccountSecurity::SESSION_KEY, ['user_id' => (string) $user->id, 'version' => (int) $user->security_version]);
        $request->session()->put('auth.password_confirmed_at', now()->timestamp);

        return $request->expectsJson()
            ? response()->json(['message' => 'Your email address has been changed and verified.'])
            : response()->view('auth.account-email-change', ['change' => null, 'message' => 'Your email address has been changed and verified. Other staff sessions have been signed out.']);
    }
}
