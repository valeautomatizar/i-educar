<?php

use App\Extensions\LegacyUserProvider;
use App\Models\LegacyEmployee;
use App\User;
use Database\Factories\LegacyUserFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    Cache::flush();
    $this->provider = new LegacyUserProvider(app('hash'));
    $this->user = User::query()->findOrFail(LegacyUserFactory::new()->create()->getKey());
});

test('acha o usuário pelo id e pelo token de lembrar', function () {
    $this->provider->updateRememberToken($this->user, 'token-secreto');

    expect($this->provider->retrieveById($this->user->getKey())?->getKey())->toBe($this->user->getKey())
        ->and(LegacyEmployee::query()->find($this->user->getKey())->remember_token)->toBe('token-secreto');

    Cache::flush();

    expect($this->provider->retrieveByToken($this->user->getKey(), 'token-secreto')?->getKey())->toBe($this->user->getKey())
        ->and($this->provider->retrieveByToken($this->user->getKey(), 'outro'))->toBeNull()
        ->and($this->provider->retrieveByToken(0, 'token-secreto'))->toBeNull();
});

test('aceita a senha antiga em md5 e já troca pelo hash atual', function () {
    // O md5 é o formato das senhas antigas do legado, que o provider precisa reconhecer.
    LegacyEmployee::query()->whereKey($this->user->getKey())->update(['senha' => md5('antiga')]); // NOSONAR
    $user = User::query()->findOrFail($this->user->getKey());

    expect($this->provider->validateLegacyCredentials($user, ['password' => 'antiga']))->toBeTrue()
        ->and(Hash::check('antiga', LegacyEmployee::query()->find($user->getKey())->senha))->toBeTrue();
});

test('refaz o hash da senha só quando é forçado', function () {
    $before = LegacyEmployee::query()->find($this->user->getKey())->senha;

    $this->provider->rehashPasswordIfRequired($this->user, ['password' => 'nova']);

    expect(LegacyEmployee::query()->find($this->user->getKey())->senha)->toBe($before);

    $this->provider->rehashPasswordIfRequired($this->user, ['password' => 'nova'], true);

    expect(Hash::check('nova', LegacyEmployee::query()->find($this->user->getKey())->senha))->toBeTrue();
});

test('obriga a troca da senha quando o login usa uma senha fraca', function () {
    LegacyEmployee::query()->whereKey($this->user->getKey())->update(['senha' => Hash::make('123456789'), 'force_reset_password' => false]);
    $user = User::query()->findOrFail($this->user->getKey());

    expect($this->provider->validateCredentials($user, ['password' => '123456789']))->toBeTrue()
        ->and(LegacyEmployee::query()->find($user->getKey())->force_reset_password)->toBeTrue();
});

test('obriga a troca da senha antiga em md5 quando ela é fraca', function () {
    LegacyEmployee::query()->whereKey($this->user->getKey())->update(['senha' => md5('antiga'), 'force_reset_password' => false]); // NOSONAR
    $user = User::query()->findOrFail($this->user->getKey());

    expect($this->provider->validateCredentials($user, ['password' => 'antiga']))->toBeTrue()
        ->and(LegacyEmployee::query()->find($user->getKey())->force_reset_password)->toBeTrue();
});

test('não obriga a troca quando a senha atende à política', function () {
    LegacyEmployee::query()->whereKey($this->user->getKey())->update(['senha' => Hash::make('Senha@Forte1'), 'force_reset_password' => false]);
    $user = User::query()->findOrFail($this->user->getKey());

    expect($this->provider->validateCredentials($user, ['password' => 'Senha@Forte1']))->toBeTrue()
        ->and(LegacyEmployee::query()->find($user->getKey())->force_reset_password)->toBeFalse();
});

test('senha errada não altera nada', function () {
    LegacyEmployee::query()->whereKey($this->user->getKey())->update(['senha' => Hash::make('123456789'), 'force_reset_password' => false]);
    $user = User::query()->findOrFail($this->user->getKey());

    expect($this->provider->validateCredentials($user, ['password' => 'outra']))->toBeFalse()
        ->and(LegacyEmployee::query()->find($user->getKey())->force_reset_password)->toBeFalse();
});
