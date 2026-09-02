<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;

final class LeadController extends Controller
{
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $leads = Lead::orderBy('created_at', 'desc')->paginate(10);

        return view('admin.leads.index', ['leads' => $leads]);
    }

    public function show(Lead $lead): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        return view('admin.leads.show', ['lead' => $lead]);
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();

        return redirect()->route('admin.leads.index')->with('success', 'Lead deleted successfully.');
    }
}
