<?php

namespace App\Http\Controllers;

use App\Services\Tachograph\DashboardData;

class AppController extends Controller
{
    public function __invoke(DashboardData $dashboard)
    {
        return view('app', [
            'headline' => $dashboard->headline(),
            'attention' => $dashboard->attention(),
            'compensation' => $dashboard->compensationDue(),
            'system' => $dashboard->system(),
            'days' => DashboardData::DAYS,
        ]);
    }
}
