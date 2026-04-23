<?php

namespace App\Http\Controllers;

use App\Models\PortalGrant;
use App\Models\Transaction;
use App\Support\PortalActivityLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of transactions for the bookkeeper portal. Scoped by
 * EnsurePortalSession → CurrentHousehold; no household fan-out.
 *
 * Columns match the shape most bookkeepers expect: posting date,
 * account, counterparty, category, memo, amount, currency, reference.
 * Pipe-through to Xero / QuickBooks etc. happens in a follow-up via
 * their specific mapping sheets — this is the raw dump.
 */
final class PortalExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $grant = PortalGrant::query()
            ->withoutGlobalScope('household')
            ->find($request->session()->get('portal_grant_id'));
        $slug = $grant?->label ? preg_replace('/[^a-z0-9-]+/i', '-', strtolower($grant->label)) : 'transactions';
        $filename = 'secretaire-portal-'.$slug.'-'.now()->format('Y-m-d').'.csv';

        PortalActivityLog::record('export_csv', $grant, $request, [
            'filename' => $filename,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ]);

        return new StreamedResponse(function () use ($data) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel

            fputcsv($out, [
                'occurred_on', 'account', 'counterparty', 'category',
                'description', 'memo', 'reference_number',
                'amount', 'currency', 'status',
            ]);

            Transaction::query()
                ->with(['account:id,name,currency', 'counterparty:id,display_name', 'category:id,name'])
                ->when(isset($data['from']), fn ($q) => $q->whereDate('occurred_on', '>=', $data['from']))
                ->when(isset($data['to']), fn ($q) => $q->whereDate('occurred_on', '<=', $data['to']))
                ->orderBy('occurred_on')
                ->orderBy('id')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $t) {
                        $account = $t->account;
                        $counterparty = $t->counterparty;
                        $category = $t->category;
                        fputcsv($out, [
                            $t->occurred_on->toDateString(),
                            $account ? (string) $account->name : '',
                            $counterparty ? (string) $counterparty->display_name : '',
                            $category ? (string) $category->name : '',
                            (string) ($t->description ?? ''),
                            (string) ($t->memo ?? ''),
                            (string) ($t->reference_number ?? ''),
                            (string) $t->amount,
                            (string) ($t->currency ?? ($account ? $account->currency : '')),
                            (string) ($t->status ?? ''),
                        ]);
                    }
                });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
