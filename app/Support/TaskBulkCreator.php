<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * Parses a bulk-entry text block and persists one Task per line, with
 * tags + @contact subject links. Callers (desktop tasks-index, mobile
 * /m/tasks/bulk) feed raw textarea content in and render the returned
 * summary. Pure persistence — no UI concerns here.
 */
final class TaskBulkCreator
{
    /**
     * @return array{created: int, unmatched_contacts: array<int, string>}
     */
    public static function run(string $text): array
    {
        $rows = TaskLineParser::parseBlock($text);
        if ($rows === []) {
            return ['created' => 0, 'unmatched_contacts' => []];
        }

        $created = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            $task = Task::create([
                'title' => $row['title'],
                'due_at' => $row['due_at'],
                'state' => 'open',
                'priority' => $row['priority'] ?? 3,
            ]);

            if ($row['tags'] !== []) {
                $ids = [];
                foreach ($row['tags'] as $name) {
                    $slug = Str::slug($name);
                    if ($slug === '') {
                        continue;
                    }
                    $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => $name]);
                    $ids[] = $tag->id;
                }
                if ($ids !== []) {
                    $task->tags()->syncWithoutDetaching($ids);
                }
            }

            $refs = [];
            foreach ($row['contact_patterns'] as $pattern) {
                $hit = Contact::query()
                    ->where('display_name', 'like', '%'.$pattern.'%')
                    ->orderByRaw('LENGTH(display_name)')
                    ->first();
                if ($hit === null) {
                    $unmatched[] = '@'.$pattern;

                    continue;
                }
                $refs[] = ['type' => Contact::class, 'id' => $hit->id];
            }
            if ($refs !== []) {
                $task->syncSubjects($refs);
            }

            $created++;
        }

        return [
            'created' => $created,
            'unmatched_contacts' => array_values(array_unique($unmatched)),
        ];
    }
}
