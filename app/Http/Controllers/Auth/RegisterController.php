<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DataTransferObjects\Auth\RegisterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect('/dashboard');
        }

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $data = RegisterData::fromArray($request->validated());

        $this->authService->register($data);

        return redirect('/login')
            ->with('status', 'Registration successful! Please check your email to verify your account.');
    }
}
