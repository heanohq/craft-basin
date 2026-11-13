<?php

namespace heano\basin\models;

use craft\base\Model;

/**
 * A Basin project.
 */
class Project extends Model
{
    public int $id;
    public string $name = '';

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        $project = new self();
        $project->id = (int)($data['id'] ?? 0);
        $project->name = (string)($data['name'] ?? '');
        return $project;
    }
}
