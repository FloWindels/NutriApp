<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ForgotPasswordRequest;
use App\Http\Requests\Account\LoginRequest;
use App\Http\Requests\Account\RegisterRequest;
use App\Http\Requests\Account\ResetPasswordRequest;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\NutritionCalculator;
use App\Support\PasswordResetUrl;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentification (brief §1 / §0.3). Les formes de /register, /login et /me sont gelées :
 * l'objet utilisateur reste `{id, name, email, email_verified_at, created_at, updated_at}`.
 */
class AuthController extends Controller
{
    public const MSG_IDENTIFIANTS = 'Identifiants invalides.';
    public const MSG_DECONNECTE = 'Déconnecté.';
    public const MSG_LIEN_ENVOYE = 'Si un compte existe, un lien de réinitialisation a été envoyé.';
    public const MSG_MOT_DE_PASSE_REINITIALISE = 'Mot de passe réinitialisé.';

    public function __construct()
    {
        // URL du lien de réinitialisation → ${FRONTEND_URL}/reset-password?token=…&email=…
        PasswordResetUrl::register();
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            UserSetting::query()->firstOrCreate(['user_id' => $user->id]);

            return $user;
        });

        event(new Registered($user));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => self::userPayload($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => [self::MSG_IDENTIFIANTS],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => self::userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => self::MSG_DECONNECTE]);
    }

    /**
     * Forme gelée au premier niveau + frères has_profile, household_id, consentement_sante, settings.
     */
    public function me(Request $request, NutritionCalculator $calculator): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['profile', 'settings']);

        $settings = $user->getRelation('settings')
            ?? tap(UserSetting::query()->firstOrCreate(['user_id' => $user->id]), fn ($s) => $user->setRelation('settings', $s));

        $profile = $user->getRelation('profile');

        return response()->json(self::userPayload($user) + [
            'has_profile' => $profile !== null && $calculator->isComplete($profile),
            'household_id' => $user->household_id !== null ? (int) $user->household_id : null,
            'consentement_sante' => $user->consentement_sante_at !== null,
            'settings' => [
                'timezone' => (string) $settings->timezone,
                'theme' => (string) $settings->theme,
            ],
        ]);
    }

    /**
     * Toujours 200 : ne révèle pas l'existence d'un compte.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink(['email' => $request->validated('email')]);

        return response()->json(['message' => self::MSG_LIEN_ENVOYE]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Toute session existante est révoquée.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => self::MSG_MOT_DE_PASSE_REINITIALISE]);
    }

    /**
     * Objet utilisateur du contrat legacy (jamais d'autres clés ici).
     *
     * @return array{id: int, name: string, email: string, email_verified_at: string|null, created_at: string|null, updated_at: string|null}
     */
    public static function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
