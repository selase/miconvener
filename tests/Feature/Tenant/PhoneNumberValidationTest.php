<?php

declare(strict_types=1);

use App\Http\Requests\Tenant\StoreTeamMemberRequest;
use App\Http\Requests\Tenant\UpdateTeamMemberRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Every account form accepts a Ghanaian number in its local or international form
 * and a valid number from any other country, however it is typed.
 */
dataset('accepted phone numbers', [
    'Ghana local' => '0201234567',
    'Ghana international' => '+233201234567',
    'Ghana international with spaces' => '+233 20 123 4567',
    'UK with plus' => '+447911123456',
    'UK with 00 prefix' => '00447911123456',
    'US with 00 prefix' => '0013018201664',
    'US with plus' => '+13018201664',
    'US formatted' => '+1 (301) 820-1664',
]);

dataset('rejected phone numbers', [
    'empty' => '',
    'letters' => 'not a number',
    'too short' => '12345',
    'unassigned country code' => '+999123456789',
    'Ghana local too short' => '020123',
]);

dataset('account requests', [
    'add team member' => [StoreTeamMemberRequest::class],
    'edit team member' => [UpdateTeamMemberRequest::class],
    'add user' => [StoreUserRequest::class],
    'edit user' => [UpdateUserRequest::class],
]);

it('accepts a local, international or foreign number in every account form', function (string $request, string $number): void {
    $request = $request::create('/team', 'POST', ['phone_no' => $number, 'user' => App\Models\User::factory()->make(['id' => 1])]);
    $request->setContainer(app());
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
    $rule = collect($request->rules()['phone_no']);

    expect(Validator::make(['phone_no' => $request->input('phone_no')], ['phone_no' => $rule->all()])->passes())->toBeTrue();
})->with('account requests')->with('accepted phone numbers');

it('still refuses what is not a phone number in every account form', function (string $request, string $number): void {
    $request = $request::create('/team', 'POST', ['phone_no' => $number, 'user' => App\Models\User::factory()->make(['id' => 1])]);
    $request->setContainer(app());
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
    $rule = collect($request->rules()['phone_no']);

    expect(Validator::make(['phone_no' => $request->input('phone_no')], ['phone_no' => $rule->all()])->passes())->toBeFalse();
})->with('account requests')->with('rejected phone numbers');
