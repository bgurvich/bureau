<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ContactsExportController extends Controller
{
    /**
     * Stream the current household's contacts as a CSV. Multi-value
     * fields (emails/phones/addresses are JSON arrays in the column;
     * tags live on a separate pivot; match_patterns is already a newline
     * list) get flattened to a single cell each:
     *   - email = first email only (multi-email contacts are rare;
     *     importers round-trip without loss for the typical case)
     *   - phone = first phone only (same reasoning)
     *   - tags  = comma-separated slug list
     *   - match_patterns = pipe-separated so CSV's newline-sensitive
     *     parsers don't split the cell across rows.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $filename = 'contacts-'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // BOM makes Excel pick up UTF-8 correctly. Livewire / other
            // readers ignore it.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'display_name', 'kind', 'organization', 'first_name', 'last_name',
                'email', 'phone', 'favorite', 'is_vendor', 'is_customer',
                'tax_id', 'category', 'match_patterns', 'tags', 'roles', 'birthday', 'notes',
            ]);

            Contact::query()
                ->with(['category:id,name', 'tags:id,slug'])
                ->orderBy('display_name')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $c) {
                        $firstEmail = is_array($c->emails) && $c->emails !== [] ? (string) $c->emails[0] : '';
                        $firstPhone = is_array($c->phones) && $c->phones !== [] ? (string) $c->phones[0] : '';
                        // Store match_patterns pipe-separated in the CSV cell so
                        // CSV newline-handling quirks don't corrupt the field.
                        // Import converts pipes back to newlines.
                        $patterns = (string) ($c->match_patterns ?? '');
                        $patternsCell = str_replace(["\r\n", "\r", "\n"], '|', $patterns);

                        $roles = is_array($c->contact_roles) ? $c->contact_roles : [];
                        // Birthday in ISO, 1900-MM-DD sentinel round-trips fine —
                        // the import side re-detects it and preserves the
                        // year-unknown marker.
                        $birthday = $c->birthday ? $c->birthday->toDateString() : '';

                        fputcsv($out, [
                            (string) $c->display_name,
                            (string) $c->kind,
                            (string) ($c->organization ?? ''),
                            (string) ($c->first_name ?? ''),
                            (string) ($c->last_name ?? ''),
                            $firstEmail,
                            $firstPhone,
                            $c->favorite ? '1' : '0',
                            $c->is_vendor ? '1' : '0',
                            $c->is_customer ? '1' : '0',
                            (string) ($c->tax_id ?? ''),
                            $c->category ? (string) $c->category->name : '',
                            $patternsCell,
                            $c->tags->pluck('slug')->join(','),
                            implode(',', $roles),
                            $birthday,
                            (string) ($c->notes ?? ''),
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
