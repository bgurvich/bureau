<?php

namespace App\Http\Controllers;

use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class BookkeeperExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        $household = CurrentHousehold::get();
        $householdName = is_object($household) && isset($household->name) ? (string) $household->name : 'Bureau';
        $filename = sprintf(
            'bureau-bookkeeper-%s-%s-to-%s.zip',
            str(strtolower($householdName))->slug(),
            $from->format('Y-m-d'),
            $from->format('Y-m-d') === $to->format('Y-m-d') ? $from->format('Y-m-d') : $to->format('Y-m-d'),
        );
        $householdId = CurrentHousehold::id();

        return response()->streamDownload(function () use ($from, $to, $householdName, $householdId) {
            $tmp = tempnam(sys_get_temp_dir(), 'bkpr');
            $zip = new ZipArchive;
            $zip->open($tmp, ZipArchive::OVERWRITE);

            $zip->addFromString('README.md', $this->readme($from, $to, $householdName));
            $zip->addFromString('accounts.csv', $this->accountsCsv($householdId));
            $zip->addFromString('categories.csv', $this->categoriesCsv($householdId));
            $zip->addFromString('contacts.csv', $this->contactsCsv($householdId));
            $zip->addFromString('transactions.csv', $this->transactionsCsv($from, $to, $householdId));
            $zip->addFromString('transfers.csv', $this->transfersCsv($from, $to, $householdId));

            $zip->close();
            readfile($tmp);
            @unlink($tmp);
        }, $filename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, must-revalidate',
        ]);
    }

    private function readme(CarbonImmutable $from, CarbonImmutable $to, string $householdName): string
    {
        return implode("\n", [
            '# Bureau bookkeeper package',
            '',
            sprintf('Household: %s', $householdName),
            sprintf('Period: %s → %s', $from->toDateString(), $to->toDateString()),
            sprintf('Generated: %s UTC', now()->toIso8601String()),
            '',
            '## Contents',
            '',
            '- `accounts.csv` — chart of accounts (with `external_code` for QBO/Xero mapping).',
            '- `categories.csv` — category tree (with `external_code`).',
            '- `contacts.csv` — vendor/customer list (only contacts flagged is_vendor or is_customer).',
            '- `transactions.csv` — signed single-entry journal for the period.',
            '- `transfers.csv` — inter-account transfers (paired amounts).',
            '',
            '## Notes for the CPA',
            '',
            '- Amounts are signed: negative = money out, positive = money in. Transfers are separate rows in `transfers.csv` to keep the P&L clean.',
            '- `tax_code` / `tax_amount` on transactions are present if captured at entry time.',
            '- `reference_number` maps to cheque/invoice numbers if provided.',
            '',
        ]);
    }

    private function accountsCsv(?int $householdId): string
    {
        $rows = [['id', 'name', 'external_code', 'type', 'institution', 'currency', 'opening_balance', 'is_active', 'include_in_net_worth']];

        $records = DB::table('accounts')
            ->when($householdId, fn ($q) => $q->where('household_id', $householdId))
            ->orderBy('name')
            ->get();

        foreach ($records as $a) {
            $rows[] = [
                (string) $a->id,
                (string) $a->name,
                (string) ($a->external_code ?? ''),
                (string) $a->type,
                (string) ($a->institution ?? ''),
                (string) $a->currency,
                (string) $a->opening_balance,
                $a->is_active ? '1' : '0',
                $a->include_in_net_worth ? '1' : '0',
            ];
        }

        return $this->toCsv($rows);
    }

    private function categoriesCsv(?int $householdId): string
    {
        $rows = [['id', 'kind', 'slug', 'external_code', 'name', 'parent_slug']];

        $cats = DB::table('categories')
            ->when($householdId, fn ($q) => $q->where('household_id', $householdId))
            ->orderBy('kind')
            ->orderBy('slug')
            ->get()
            ->keyBy('id');

        foreach ($cats as $c) {
            $parent = $c->parent_id ? ($cats[$c->parent_id] ?? null) : null;
            $rows[] = [
                (string) $c->id,
                (string) $c->kind,
                (string) $c->slug,
                (string) ($c->external_code ?? ''),
                (string) $c->name,
                (string) ($parent->slug ?? ''),
            ];
        }

        return $this->toCsv($rows);
    }

    private function contactsCsv(?int $householdId): string
    {
        $rows = [['id', 'kind', 'display_name', 'is_vendor', 'is_customer', 'tax_id']];

        $records = DB::table('contacts')
            ->when($householdId, fn ($q) => $q->where('household_id', $householdId))
            ->where(fn ($q) => $q->where('is_vendor', true)->orWhere('is_customer', true))
            ->orderBy('display_name')
            ->get();

        foreach ($records as $c) {
            $rows[] = [
                (string) $c->id,
                (string) $c->kind,
                (string) $c->display_name,
                $c->is_vendor ? '1' : '0',
                $c->is_customer ? '1' : '0',
                (string) ($c->tax_id ?? ''),
            ];
        }

        return $this->toCsv($rows);
    }

    private function transactionsCsv(CarbonImmutable $from, CarbonImmutable $to, ?int $householdId): string
    {
        $rows = [[
            'date', 'account_code', 'account_name', 'description', 'reference_number',
            'amount', 'currency', 'tax_amount', 'tax_code',
            'category_code', 'category_name', 'category_kind',
            'counterparty_name', 'counterparty_tax_id',
            'status',
        ]];

        DB::table('transactions')
            ->leftJoin('accounts', 'transactions.account_id', '=', 'accounts.id')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->leftJoin('contacts', 'transactions.counterparty_contact_id', '=', 'contacts.id')
            ->when($householdId, fn ($q) => $q->where('transactions.household_id', $householdId))
            ->whereBetween('transactions.occurred_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('transactions.occurred_on')
            ->orderBy('transactions.id')
            ->select([
                'transactions.occurred_on',
                'accounts.external_code as account_code',
                'accounts.name as account_name',
                'transactions.description',
                'transactions.reference_number',
                'transactions.amount',
                'transactions.currency as txn_currency',
                'accounts.currency as account_currency',
                'transactions.tax_amount',
                'transactions.tax_code',
                'categories.external_code as category_code',
                'categories.name as category_name',
                'categories.kind as category_kind',
                'contacts.display_name as counterparty_name',
                'contacts.tax_id as counterparty_tax_id',
                'transactions.status',
            ])
            ->chunk(500, function ($chunk) use (&$rows) {
                foreach ($chunk as $r) {
                    $rows[] = [
                        (string) substr((string) $r->occurred_on, 0, 10),
                        (string) ($r->account_code ?? ''),
                        (string) ($r->account_name ?? ''),
                        (string) ($r->description ?? ''),
                        (string) ($r->reference_number ?? ''),
                        (string) $r->amount,
                        (string) ($r->account_currency ?? $r->txn_currency ?? ''),
                        (string) ($r->tax_amount ?? ''),
                        (string) ($r->tax_code ?? ''),
                        (string) ($r->category_code ?? ''),
                        (string) ($r->category_name ?? ''),
                        (string) ($r->category_kind ?? ''),
                        (string) ($r->counterparty_name ?? ''),
                        (string) ($r->counterparty_tax_id ?? ''),
                        (string) $r->status,
                    ];
                }
            });

        return $this->toCsv($rows);
    }

    private function transfersCsv(CarbonImmutable $from, CarbonImmutable $to, ?int $householdId): string
    {
        $rows = [[
            'date', 'from_account_code', 'from_account_name', 'from_amount', 'from_currency',
            'to_account_code', 'to_account_name', 'to_amount', 'to_currency',
            'fee_amount', 'description', 'status',
        ]];

        DB::table('transfers')
            ->leftJoin('accounts as from_a', 'transfers.from_account_id', '=', 'from_a.id')
            ->leftJoin('accounts as to_a', 'transfers.to_account_id', '=', 'to_a.id')
            ->when($householdId, fn ($q) => $q->where('transfers.household_id', $householdId))
            ->whereBetween('transfers.occurred_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('transfers.occurred_on')
            ->orderBy('transfers.id')
            ->select([
                'transfers.occurred_on',
                'from_a.external_code as from_account_code',
                'from_a.name as from_account_name',
                'transfers.from_amount',
                'transfers.from_currency',
                'to_a.external_code as to_account_code',
                'to_a.name as to_account_name',
                'transfers.to_amount',
                'transfers.to_currency',
                'transfers.fee_amount',
                'transfers.description',
                'transfers.status',
            ])
            ->chunk(500, function ($chunk) use (&$rows) {
                foreach ($chunk as $r) {
                    $rows[] = [
                        (string) substr((string) $r->occurred_on, 0, 10),
                        (string) ($r->from_account_code ?? ''),
                        (string) ($r->from_account_name ?? ''),
                        (string) $r->from_amount,
                        (string) $r->from_currency,
                        (string) ($r->to_account_code ?? ''),
                        (string) ($r->to_account_name ?? ''),
                        (string) $r->to_amount,
                        (string) $r->to_currency,
                        (string) ($r->fee_amount ?? ''),
                        (string) ($r->description ?? ''),
                        (string) $r->status,
                    ];
                }
            });

        return $this->toCsv($rows);
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function toCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $out = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $out;
    }
}
