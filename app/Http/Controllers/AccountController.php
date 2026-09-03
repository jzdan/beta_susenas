<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function edit(Request $request)
    {
        return view('akun.edit', ['u' => $request->user()]);
    }

    public function updatePassword(Request $request)
    {
        $u = $request->user();
        $d = $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers(),
                'different:current_password'],
        ], [], ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru']);

        if (str_contains(strtolower($d['password']), strtolower($u->username))) {
            return back()->withErrors(['password' => 'Kata sandi tidak boleh memuat username.']);
        }

        $u->update(['password' => Hash::make($d['password'])]);
        AuditLog::record('user', $u->id, 'ganti-sandi-sendiri', '', $u->id);

        return back()->with('ok', 'Kata sandi diperbarui.');
    }
}
