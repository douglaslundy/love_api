<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $min = config('love.min_age');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'birth_date' => ['required', 'date', 'before_or_equal:'.now()->subYears($min)->toDateString()],
            'accept_terms' => ['accepted'],
        ], [
            'birth_date.before_or_equal' => "É preciso ter {$min} anos ou mais.",
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'birth_date' => $data['birth_date'],
            'terms_accepted_at' => now(),
        ]);

        $user->sendEmailVerificationNotification();

        Auth::login($user);

        return response()->json($this->payload($user->refresh()), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        $user = $request->user();
        if ($user->suspended_at || $user->deactivated_at) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            throw ValidationException::withMessages(['email' => $user->suspended_at ? 'Conta suspensa.' : 'Conta desativada.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_seen_at' => now(), 'login_count' => $user->login_count + 1])->save();

        return response()->json($this->payload($user));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json($this->payload($request->user()));
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'intense_enabled' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'notify_email' => ['sometimes', 'boolean'],
            'reminder_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);
        $request->user()->update($data);

        return response()->json($this->payload($request->user()->fresh()));
    }

    private function payload(User $user): array
    {
        $couple = $user->currentCouple();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'intense_enabled' => $user->intense_enabled,
                'pin_set' => $user->pin_hash !== null,
                'timezone' => $user->timezone,
                'email_verified' => $user->hasVerifiedEmail(),
                'notify_email' => $user->notify_email,
                'reminder_time' => $user->reminder_time,
                'allowed_levels' => Entitlements::forUser($user),
                'is_admin' => (bool) $user->is_admin,
            ],
            'couple' => $couple ? [
                'id' => $couple->id,
                'status' => $couple->status,
                'session_ended_at' => $couple->safety_at,
                'reunion_date' => $couple->reunion_date?->toDateString(),
                'partner' => $couple->users()->where('users.id', '!=', $user->id)->first(['users.name', 'users.timezone']),
            ] : null,
            'billing_enabled' => config('love.billing_enabled'),
            'email_verification_required' => config('love.require_email_verification'),
            // Só sobre a própria pessoa: mostra ou esconde a aba. Nunca aparece para o par.
            'public_tab' => \App\Models\PublicProfile::where('user_id', $user->id)->where('enabled', true)->where('visible', true)->exists(),
            // O par vê a aba só quando o representante ligou o perfil de casal e pediu o consentimento dele (RF-90).
            'couple_offer' => $couple?->status === 'active' && \App\Models\PublicProfile::where('as_couple', true)
                ->whereIn('user_id', $couple->users()->where('users.id', '!=', $user->id)->pluck('users.id'))->exists(),
        ];
    }
}
