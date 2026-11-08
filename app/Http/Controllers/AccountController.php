<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        $request->user()->update(['password' => $data['password']]);

        return response()->noContent();
    }

    public function email(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email,'.$request->user()->id],
        ]);
        $user = $request->user();
        $user->forceFill(['email' => $data['email'], 'email_verified_at' => null])->save();
        $user->sendEmailVerificationNotification();

        return response()->noContent();
    }

    public function setPin(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
        ]);
        $request->user()->forceFill(['pin_hash' => Hash::make($data['pin'])])->save();

        return response()->noContent();
    }

    public function removePin(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $request->user()->forceFill(['pin_hash' => null])->save();

        return response()->noContent();
    }

    public function verifyPin(Request $request)
    {
        $data = $request->validate(['pin' => ['required', 'string', 'max:6']]);
        $hash = $request->user()->pin_hash;

        if (! $hash || ! Hash::check($data['pin'], $hash)) {
            throw ValidationException::withMessages(['pin' => 'PIN incorreto.']);
        }

        return response()->noContent();
    }

    /** Exclusão real: apaga o usuário e, por cascata, respostas, progresso e vínculos. */
    public function destroy(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $couple = $user->currentCouple();
            $user->delete();

            if ($couple) {
                // Sem ninguém, apaga o casal. Com o par ainda lá, volta a "pendente".
                $couple->users()->count() === 0 ? $couple->delete() : $couple->update(['status' => 'pending']);
            }
        });

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
