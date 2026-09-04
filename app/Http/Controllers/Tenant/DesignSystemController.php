<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class DesignSystemController extends Controller
{
    public function __construct()
    {
        $this->middleware(['can:access-superadmin-dashboard']);
    }

    public function index(): Response
    {
        return Inertia::render('Tenant/DesignSystem/Index');
    }
}
