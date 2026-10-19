<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\TicketAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserAuthController extends Controller
{
    public function loginForm(Request $request)
    {
        if (TicketAccess::admin($request)) return redirect()->route('data-sources');

        return $request->user() ? redirect()->route('tickets') : view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            return back()->withErrors(['email' => 'Date de autentificare incorecte.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        if (Auth::user()->is_admin) {
            $adminId = Auth::id();
            Auth::logout();
            $request->session()->put('admin_user_id', $adminId);

            return redirect()->route('data-sources');
        }
        $request->session()->forget('admin_user_id');

        return redirect()->intended(route('tickets'));
    }

    public function registerForm(Request $request)
    {
        if (TicketAccess::admin($request)) return redirect()->route('data-sources');

        return $request->user() ? redirect()->route('tickets') : view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => false,
        ]);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('admin_user_id');

        return redirect()->route('tickets');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    }
}
