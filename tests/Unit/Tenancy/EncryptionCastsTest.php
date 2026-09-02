<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Unit\Tenancy\EncryptedModel;

beforeEach(function () {
    Schema::create('encrypted_models', function (Blueprint $table) {
        $table->id();
        $table->text('secret_string')->nullable();
        $table->text('secret_json')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('encrypted_models');
});

test('it encrypts and decrypts strings', function () {
    $plaintext = 'this is a secret';
    $model = new EncryptedModel();
    $model->secret_string = $plaintext;
    $model->save();

    // Verify it's encrypted in the database
    $raw = DB::table('encrypted_models')->where('id', $model->id)->first();
    expect($raw->secret_string)->not->toBe($plaintext);
    expect(Crypt::decryptString($raw->secret_string))->toBe($plaintext);

    // Verify it's decrypted when accessed via model
    $found = EncryptedModel::find($model->id);
    expect($found->secret_string)->toBe($plaintext);
});

test('it encrypts and decrypts json', function () {
    $data = ['key' => 'value', 'nested' => ['a' => 1]];
    $model = new EncryptedModel();
    $model->secret_json = $data;
    $model->save();

    // Verify it's encrypted in the database
    $raw = DB::table('encrypted_models')->where('id', $model->id)->first();
    expect($raw->secret_json)->toBeString();
    expect($raw->secret_json)->not->toBe(json_encode($data));
    expect(Crypt::decrypt($raw->secret_json))->toBe($data);

    // Verify it's decrypted when accessed via model
    $found = EncryptedModel::find($model->id);
    expect($found->secret_json)->toBe($data);
});
