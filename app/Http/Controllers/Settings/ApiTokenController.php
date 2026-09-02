<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class ApiTokenController extends Controller
{
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $tokens = auth()->user()->tokens()
            ->latest()
            ->get();

        return view('settings.developer.index', [
            'tokens' => $tokens,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
        ]);

        $abilities = $request->input('abilities', ['*']);

        $token = $request->user()->createToken($request->name, $abilities);

        return back()->with('new_token', $token->plainTextToken);
    }

    public function destroy($id)
    {
        auth()->user()->tokens()->where('id', $id)->delete();

        return back()->with('success', 'Token revoked successfully.');
    }
}
