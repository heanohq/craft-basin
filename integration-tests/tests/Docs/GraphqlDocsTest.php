<?php

use craft\models\GqlSchema;
use GraphQL\Language\Parser;
use GraphQL\Validator\DocumentValidator;
use heano\basin\gql\BasinGql;
use heano\basin\tests\craft\Docs;

/*
 * The GraphQL API as the docs describe it (reference/graphql.md): the types'
 * fields and their types, the scope's name, and the example queries.
 */

/** The full schema a site would have with the Basin scope and a section with a Basin Form field. */
function docsSchema(array $extraScope = []): \GraphQL\Type\Schema
{
    $gql = Craft::$app->getGql();
    $gql->flushCaches();
    return $gql->getSchemaDef(new GqlSchema(['name' => 'Docs', 'scope' => ['basinForms:read', ...$extraScope]]), true);
}

it('lists each type’s fields and their types as they are', function(string $type) {
    $rows = Docs::table(Docs::section(Docs::page('reference/graphql.md'), "`$type`"));
    $documented = [];
    foreach ($rows as $row) {
        $documented[Docs::plain($row['Field'])] = Docs::plain($row['Type']);
    }

    $actual = [];
    foreach (docsSchema()->getType($type)->getFields() as $name => $field) {
        $actual[$name] = (string)$field->getType();
    }

    expect($documented)->toEqualCanonicalizing($actual);
})->with(['BasinForm', 'BasinSpamProtection']);

it('names the scope as Craft shows it', function() {
    $label = BasinGql::schemaComponents()['basinForms:read']['label'] ?? null;
    expect(Docs::bold(Docs::page('reference/graphql.md')))->toContain($label);
});

it('has example queries that are valid against the schema', function() {
    [$section] = sectionWithFormField();
    $entryType = $section->getEntryTypes()[0];
    $schema = docsSchema(["sections.{$section->uid}:read", "entrytypes.{$entryType->uid}:read"]);

    $blocks = Docs::codeBlocks('graphql');
    expect($blocks)->not->toBeEmpty();
    foreach ($blocks as $where => $query) {
        // The docs' section and entry type handles are examples.
        $query = str_replace(['"pages"', 'page_Entry'], ["\"{$section->handle}\"", "{$entryType->handle}_Entry"], $query);
        $errors = array_map(fn($error) => $error->getMessage(), DocumentValidator::validate($schema, Parser::parse($query)));
        expect($errors)->toBe([], $where);
    }
});
