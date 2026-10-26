<?php

declare(strict_types=1);

use Quire\Content\Type\Capability;
use Quire\Extend;
use Quire\Fixtures\Recipes\DurationField;

/*
 * A test extension with no admin code at all: a field type, a content type and a setting. The admin's
 * acceptance test (tests/Acceptance/AdminTest.php) checks that they get complete admin screens.
 */
return [
    new Extend\Services()
        ->autoload('Quire\\Fixtures\\Recipes\\', __DIR__.'/src'),

    new Extend\FieldTypes()
        ->type('quire-fixture-recipes.duration', DurationField::class),

    new Extend\ContentType('recipe')
        ->label('Recipe', 'Recipes')
        ->capabilities(Capability::REVISIONS, Capability::LISTABLE, Capability::SEARCHABLE, Capability::AUTHOR, Capability::EXCERPT)
        ->permalink('/recipes/{slug}')
        ->field('servings', 'int', required: true, min: 1, max: 50, sortable: true, label: 'Servings')
        ->field('cook_time', 'quire-fixture-recipes.duration', sortable: true, label: 'Cooking time', help: 'E.g. "1h 30m", or minutes.')
        ->field('difficulty', 'enum', choices: ['easy', 'medium', 'hard'], default: 'easy', filterable: true, label: 'Difficulty')
        ->field('ingredients', 'list', label: 'Ingredients')
        ->field('vegetarian', 'bool', default: false, filterable: true, label: 'Vegetarian'),

    new Extend\Settings()
        ->define('quire-fixture-recipes.units', 'enum', 'metric', label: 'Units', help: 'How quantities are shown.', choices: ['metric', 'imperial'])
        ->define('quire-fixture-recipes.api_key', 'string', null, label: 'Nutrition API key', nullable: true, sensitive: true),
];
