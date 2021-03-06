<?php

namespace Yoga\Auth;

use Str;
use Auth;
use Hash;
use Yoga\Yoga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait IdentityAndPassword
{
  public $identityFields = ['email'];

  public function getIdentityValidationRules($identityField) {
    $rules = ['email' => 'required|email'];
    return $rules[$identityField];
  }

  public function getCredentialsValidationRules($identityField)
  {
    return [
      'identity' => 'required', 
      'identity.field' => 'required',
      'identity.value' => $this->getIdentityValidationRules($identityField),
      'password' => 'required|min:6|max:16'
    ];
  }

  function validate($credentials)
  {
    $validIdentityField = in_array(
      optional(optional($credentials)['identity'])['field'],
      $this->identityFields
    );

    if (!$validIdentityField) {
      throw new \Error(__('Invalid identity field'));
    }

    return Validator::make(
      $credentials,
      $this->getCredentialsValidationRules($credentials['identity']['field'])
    );
  }

  function doLogin(Request $request)
  {
    try {
      $validator = $this->validate($request->input('credentials', []));
    } catch (\Throwable $e) {
      return Yoga::reject($e->getMessage());
    }

    if ($validator->fails()) {
      return Yoga::reject($validator->errors()->first());
    }

    $credentials = $request->input('credentials');
    $identity = $credentials['identity'];

    $user = $this->authenticable::where($identity['field'], '=', $identity['value'])->first();
    if (!$user) {
      return Yoga::reject(__('No user found for the specified credentials'));
    }

    if (!Hash::check($credentials['password'], $user->password)) {
      return Yoga::reject(__('Invalid password'));
    }

    $user->createToken();

    return Yoga::resolve([
      'access_token' => $user->getAccessToken(),
      'token_type' => 'Bearer',
      'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))
    ]);
  }

  function refreshToken()
  {
    $user = Auth::guard(config('yoga.auth.guard'))->user();
    $user->createToken();
    return Yoga::resolve([
      'access_token' => $user->getAccessToken(),
      'token_type' => 'Bearer',
      'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))
    ]);
  }

  function getProfile()
  {
    $user = Auth::guard(config('yoga.auth.guard'))->user();
    return Yoga::resolve($user);
  }

  function createUser(Request $request) 
  {
    if (!config('yoga.auth.enable_create_user')) {
      return Yoga::reject(__('Create users not enable'));
    }

    // Get validation fields
    $fields = array_keys(config('yoga.auth.create_user_rules', []));
    $validationData = $request->only($fields);

    // Validar requisição
    $validator = Validator::make($validationData, config('yoga.auth.create_user_rules', []));
    if ($validator->fails()) {
      return Yoga::reject($validator->errors()->first());
    }

    $validationData['password'] = Hash::make($validationData['password']);

    // Criar novo usuario
    $user = $this->authenticable::create($validationData);

    // Logar o novo usuario
    $user->createToken();
    return Yoga::resolve([
      'access_token' => $user->getAccessToken(),
      'token_type' => 'Bearer',
      'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))
    ]);
  }

  function updateProfile(Request $request) {

    // Verica se possui permissão para editar
    if (!config('yoga.auth.enable_update_user')) {
      return Yoga::reject(__('Update users not enable'));
    }
    $user = Auth::guard(config('yoga.auth.guard'))->user();

    // Valida os dados
    $validatedData = $request->validate(
      collect(config('yoga.auth.update_user_rules', []))->map(function($rule) use ($user) {
        return join(collect(explode('|', $rule))->map(function($rule) use ($user) {
          if (strpos($rule, 'unique:') !== false) {
            return $rule.','.$user->id;
          } else return $rule;
        })->toArray(), '|');
      })->toArray()
    );
    if ($validatedData['password']) {
      $validatedData['password'] = Hash::make($validatedData['password']);
    }

    // Edita os dados
    $user->update($validatedData);

    return Yoga::resolve($user);
  }

}

// End of file
