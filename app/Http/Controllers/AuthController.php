<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Show the staff login form.
     */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /**
     * Authenticate a staff member by username and password.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'اسم المستخدم أو كلمة المرور غير صحيحة.']);
        }

        // New session id after login: protects against session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('reception'));
    }

    /**
     * End the session safely and send the staff member back to the login page.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
