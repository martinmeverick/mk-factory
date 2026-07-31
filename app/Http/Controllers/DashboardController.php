<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IssuedInvoiceStatus;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\IssuedInvoice;
use App\Models\ReceivedInvoice;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd = Carbon::now()->endOfMonth()->toDateString();

        $overdueIssued = IssuedInvoice::query()->overdue()
            ->with('contact')->orderBy('due_date')->get();

        $overdueReceived = ReceivedInvoice::query()->overdue()
            ->with('contact')->orderBy('due_date')->get();

        $unpaidIssued = IssuedInvoice::query()
            ->whereIn('status', [IssuedInvoiceStatus::Issued, IssuedInvoiceStatus::PartiallyPaid])
            ->get();

        // Fakturováno tento měsíc: vystavené faktury (vč. uhrazených), bez konceptů a storen.
        $issuedThisMonthSum = (int) IssuedInvoice::query()
            ->whereNotIn('status', [IssuedInvoiceStatus::Draft, IssuedInvoiceStatus::Cancelled])
            ->whereBetween('issue_date', [$monthStart, $monthEnd])
            ->sum('total_minor');

        $receivedThisMonthSum = (int) ReceivedInvoice::query()
            ->where('status', '!=', ReceivedInvoiceStatus::Rejected)
            ->whereBetween('received_date', [$monthStart, $monthEnd])
            ->sum('total_minor');

        return view('dashboard.index', [
            'overdueIssued' => $overdueIssued,
            'overdueIssuedSum' => $overdueIssued->sum(fn (IssuedInvoice $i) => $i->total_minor - $i->paid_amount_minor),
            'overdueReceived' => $overdueReceived,
            'overdueReceivedSum' => (int) $overdueReceived->sum('total_minor'),
            'unpaidIssuedCount' => $unpaidIssued->count(),
            'unpaidIssuedSum' => $unpaidIssued->sum(fn (IssuedInvoice $i) => $i->total_minor - $i->paid_amount_minor),
            'issuedThisMonthSum' => $issuedThisMonthSum,
            'receivedThisMonthSum' => $receivedThisMonthSum,
        ]);
    }
}
