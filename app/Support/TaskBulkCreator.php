<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;
use App\Models\Goal;
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
     * $goalId/$projectId scope every task created in this batch. When
     * both are provided, the tasks land under the Goal → Project pair
     * that reveals them together on the productivity tree. Either can
     * be null; TaskBulkCreator does not validate their consistency.
     *
     * @return array{created: int, unmatched_contacts: array<int, string>}
     */
    public static function run(string $text, ?int $goalId = null, ?int $projectId = null): array
    {
        $rows = TaskLineParser::parseBlock($text);
        if ($rows === []) {
            return ['created' => 0, 'unmatched_contacts' => []];
        }

        // Goal doesn't sit on Task directly; it reaches a task through
        // the project. We look the goal up only to record it as a
        // subject link on each task so users who prefer goal-scoped
        // bulk entry get the thread regardless of project setting.
        $created = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            $task = Task::create([
                'title' => $row['title'],
                'due_at' => $row['due_at'],
                'state' => 'open',
                'priority' => $row['priority'] ?? 3,
                'project_id' => $projectId,
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
            // Goal assignment piggybacks on the subject-link machinery
            // so it shows up wherever subjects surface (chip list,
            // goal detail) without needing a separate goal_id column
            // on Task.
            if ($goalId !== null) {
                $refs[] = ['type' => Goal::class, 'id' => $goalId];
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
